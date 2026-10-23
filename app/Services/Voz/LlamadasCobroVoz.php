<?php

namespace App\Services\Voz;

use App\Models\Aliado;
use App\Models\BancoCuenta;
use App\Models\BitacoraCobro;
use App\Models\LlamadaVoz;
use App\Models\WhatsappConfig;
use App\Services\MoraClienteService;
use App\Services\NumeroALetras;
use App\Services\WhatsappApiService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Llamadas de Avappi Voz para recordarle al cliente el pago de su seguridad
 * social y saber cuándo paga (agente «cobro-seguridad-social» en Avappi).
 *
 * - desdeCobros() + armar(): del listado de Cobros sale una llamada por
 *   celular (como el WhatsApp de cobro), solo individuales sin pagar.
 * - llamar(): las manda en lotes a POST /api/voz/lotes y guarda cada una.
 * - recibirAviso(): Avappi avisa cada intento (POST /avisos/voz/{token},
 *   firmado). Se guarda el intento y lo que dijo el cliente queda en la
 *   bitácora de cobros de cada contrato.
 *
 * Nació el 22-oct-2026, solo para Brygar (config services.avappi_voz.aliados).
 */
class LlamadasCobroVoz
{
    /** Avappi rechaza la firma si `t` tiene más de 5 minutos (verificar() en src/voz/avisos.js). */
    public const TOLERANCIA_SEG = 300;

    public const MAX_LOTE = 500;

    /** A un número que ya se llamó no se le vuelve a llamar antes de esto. */
    public const DIAS_ENTRE_LLAMADAS = 3;

    /** A quien se atribuye lo que no hizo una persona (como los comandos). */
    public const USUARIO_SISTEMA = 2;

    /** Duración típica de una llamada, para el estimado del botón. */
    public const MINUTOS_POR_LLAMADA = 1.5;

    /** Por qué una fila del listado no se llama. */
    public const MOTIVOS = [
        'pagado' => 'ya pagó',
        'empresa' => 'es de una empresa',
        'afiliacion' => 'es una afiliación',
        'retiro' => 'es un retiro',
        'sin_valor' => 'no tiene valor por cobrar',
        'sin_celular' => 'no tiene celular válido',
        'whatsapp_hoy' => 'recibió el WhatsApp de cobro hoy',
        'llamado_hace_poco' => 'se le llamó hace menos de '.self::DIAS_ENTRE_LLAMADAS.' días',
        'promesa' => 'prometió pagar y la fecha no ha pasado',
        'numero_equivocado' => 'el número es equivocado',
        'no_contactar' => 'pidió que no lo llamen',
    ];

    public static function configurado(): bool
    {
        return (bool) config('services.avappi_voz.key') && (bool) config('services.avappi_voz.secreto');
    }

    public static function habilitado(?int $aliadoId): bool
    {
        return $aliadoId !== null && in_array($aliadoId, (array) config('services.avappi_voz.aliados', []), true);
    }

    /**
     * Quién ve el botón y puede llamar: por ahora solo el superadmin de BryNex
     * (lo pidió Brayan al desplegar, 22-oct-2026), y solo en los aliados
     * habilitados.
     */
    public static function puedeLlamar($usuario, ?int $aliadoId): bool
    {
        return self::habilitado($aliadoId)
            && $usuario !== null
            && $usuario->es_brynex
            && $usuario->hasRole('superadmin');
    }

    /** Token de la ruta del aviso: el del .env o, si no hay, uno fijo derivado del APP_KEY. */
    public static function tokenAviso(): string
    {
        $token = (string) config('services.avappi_voz.token_aviso');

        return $token !== '' ? $token : substr(hash_hmac('sha256', 'avappi-voz-avisos', (string) config('app.key')), 0, 40);
    }

    public static function urlAviso(): string
    {
        return rtrim((string) config('app.url'), '/').'/avisos/voz/'.self::tokenAviso();
    }

    // ── Firma del aviso ─────────────────────────────────────────────────────

    /**
     * X-Avappi-Firma: t=<unix>,v1=hex(HMAC-SHA256(secreto, "<t>.<cuerpo crudo>")).
     * Se compara en tiempo constante y se rechaza si t está a más de 5 minutos
     * de ahora, hacia atrás o hacia adelante.
     */
    public static function firmaValida(string $secreto, string $cuerpo, ?string $cabecera, ?int $ahora = null): bool
    {
        if ($secreto === '') {
            return false;
        }
        $partes = [];
        foreach (explode(',', (string) $cabecera) as $p) {
            $kv = explode('=', trim($p));
            if (count($kv) === 2) {
                $partes[$kv[0]] = $kv[1];
            }
        }
        $t = isset($partes['t']) && preg_match('/^\d+$/', $partes['t']) ? (int) $partes['t'] : null;
        $ahora ??= time();
        if ($t === null || abs($ahora - $t) > self::TOLERANCIA_SEG || empty($partes['v1'])) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', "{$t}.{$cuerpo}", $secreto), strtolower($partes['v1']));
    }

    // ── Armar las llamadas ──────────────────────────────────────────────────

    /**
     * Las filas del listado de Cobros que se pueden llamar, ya planas.
     *
     * @param  iterable  $contratos  Filas de CobrosController::obtenerDatosCobros().
     * @return array{filas: array<int, array>, omitidos: array<string, int>}
     */
    public static function desdeCobros(int $aliadoId, iterable $contratos, int $mes, int $anio): array
    {
        $filas = [];
        $omitidos = [];

        foreach ($contratos as $c) {
            $valor = (float) ($c->total_estimado ?? 0) + (float) ($c->mora_estimada ?? 0);
            $celular = $c->cliente?->celular ?? null;
            $motivo = match (true) {
                (bool) ($c->fact_pagada ?? false) => 'pagado',
                (bool) ($c->es_empresa ?? false) => 'empresa',
                (bool) ($c->es_retiro ?? false) => 'retiro',
                ($c->es_afil ?? false) || ($c->afil_atrasada ?? null) => 'afiliacion',
                $valor <= 0 => 'sin_valor',
                ! WhatsappApiService::esCelularColombiano($celular) => 'sin_celular',
                default => null,
            };
            if ($motivo) {
                $omitidos[$motivo] = ($omitidos[$motivo] ?? 0) + 1;

                continue;
            }

            $filas[] = [
                'contrato_id' => (int) $c->id,
                'factura_id' => $c->fact_id ?? null,
                'numero' => self::diezDigitos(WhatsappApiService::normalizarNumero($celular)),
                'nombre' => trim((string) ($c->cliente?->nombre_corto ?? '')) ?: 'Cliente',
                'valor' => $valor,
                'vence' => $c->vence_cobro ?? self::vence($aliadoId, $c, $mes, $anio),
            ];
        }

        return ['filas' => $filas, 'omitidos' => $omitidos];
    }

    /** El día hábil en que empieza a correr la mora de ese contrato, como en Cobros. */
    private static function vence(int $aliadoId, $c, int $mes, int $anio): ?Carbon
    {
        $dia = MoraClienteService::diaHabilVencimiento($aliadoId, (int) $c->nitParaMora(), $c->diaHabilParaMora());

        return MoraClienteService::getNthDiaHabil($anio, $mes, $dia);
    }

    /**
     * Junta las filas por celular, quita las que no se deben llamar y arma el
     * lote para Avappi, sin mandar nada.
     *
     * @param  array  $filas  Las de desdeCobros().
     * @return array{comunes: array, llamadas: array, series: array, omitidos: array, costo_estimado: int}
     */
    public static function armar(int $aliadoId, array $filas, int $mes, int $anio, array $omitidos = [], ?Carbon $ahora = null): array
    {
        $ahora ??= now();
        $hoy = $ahora->copy()->startOfDay();

        $grupos = [];
        foreach ($filas as $f) {
            $grupos[$f['numero']][] = $f;
        }

        $bloqueos = self::bloqueos($aliadoId, array_map('strval', array_keys($grupos)), array_column($filas, 'contrato_id'), $ahora);

        $mesNombre = Carbon::create($anio, $mes, 1)->locale('es')->monthName;
        $llamadas = [];
        $series = [];
        foreach ($grupos as $numero => $g) {
            $ids = array_column($g, 'contrato_id');
            $motivo = $bloqueos['numeros'][$numero]
                ?? (array_intersect($ids, $bloqueos['whatsapp_hoy']) ? 'whatsapp_hoy' : null);
            if ($motivo) {
                $omitidos[$motivo] = ($omitidos[$motivo] ?? 0) + count($g);

                continue;
            }

            $valor = array_sum(array_column($g, 'valor'));
            // Si un celular paga varios contratos, manda el que vence primero.
            $vences = array_map(fn ($v) => Carbon::parse($v)->startOfDay(), array_filter(array_column($g, 'vence')));
            usort($vences, fn ($x, $y) => $x <=> $y);
            $vence = $vences[0] ?? null;
            $referencia = "brynex:cobro:{$aliadoId}:{$hoy->toDateString()}:{$numero}";
            $personas = count($g);

            $llamadas[] = [
                'numero' => (string) $numero,
                'referencia' => $referencia,
                'idempotency_key' => $referencia,
                'responder_antes' => self::responderAntes($vence, $ahora)->toIso8601String(),
                'variables' => [
                    'nombre' => $g[0]['nombre'],
                    'monto' => self::pesosEnLetras($valor),
                    'valor' => (int) round($valor),
                    'concepto' => $personas === 1
                        ? "su seguridad social de {$mesNombre}"
                        : "la seguridad social de {$mesNombre} de las {$personas} personas que tiene afiliadas",
                    'personas' => $personas,
                    'vence' => $vence ? 'el '.$vence->copy()->locale('es')->isoFormat('dddd D [de] MMMM') : 'en estos días',
                    'ya_vencio' => $vence !== null && $vence->lt($hoy),
                ],
            ];
            $series[$referencia] = [
                'numero' => (string) $numero,
                'nombre' => $g[0]['nombre'],
                'contrato_ids' => $ids,
                // contrato => factura, para que la bitácora quede con su factura.
                'factura_ids' => array_filter(array_combine($ids, array_column($g, 'factura_id'))),
                'valor' => $valor,
                'vence' => $vence?->toDateString(),
            ];
        }

        return [
            'comunes' => [
                'modo' => 'ia',
                'agente' => (string) config('services.avappi_voz.agente', 'cobro-seguridad-social'),
                'aviso_url' => self::urlAviso(),
                'variables' => [
                    'organizacion' => self::nombreAliado($aliadoId),
                    'formas_pago' => self::formasPago($aliadoId),
                ],
            ],
            'llamadas' => $llamadas,
            'series' => $series,
            'omitidos' => $omitidos,
            'costo_estimado' => (int) ceil(count($llamadas) * self::MINUTOS_POR_LLAMADA * (int) config('services.avappi_voz.costo_minuto', 99)),
        ];
    }

    /**
     * Números y contratos que no se llaman por lo que ya pasó: «no me llamen»,
     * número equivocado, una llamada reciente, una promesa vigente o el WhatsApp
     * de cobro de hoy (Ley 2300: un contacto al día, sumando los canales).
     *
     * @return array{numeros: array<string, string>, whatsapp_hoy: int[]}
     */
    private static function bloqueos(int $aliadoId, array $numeros, array $contratoIds, Carbon $ahora): array
    {
        $porNumero = [];
        foreach (array_chunk($numeros, 1000) as $parte) {
            $filas = LlamadaVoz::where('aliado_id', $aliadoId)
                ->whereIn('numero', $parte)
                ->where(function ($q) use ($ahora) {
                    $q->whereIn('resultado', ['NO_CONTACTAR', 'NUMERO_EQUIVOCADO'])
                        ->orWhere('fecha_promesa', '>=', $ahora->toDateString())
                        ->orWhere(fn ($q2) => $q2->where('created_at', '>=', $ahora->copy()->subDays(self::DIAS_ENTRE_LLAMADAS))
                            ->where(fn ($q3) => $q3->whereNull('tipo')->orWhere('tipo', '!=', 'llamada.rechazada')));
                })
                ->get(['numero', 'resultado', 'fecha_promesa', 'created_at']);

            foreach ($filas as $f) {
                $motivo = match (true) {
                    $f->resultado === 'NO_CONTACTAR' => 'no_contactar',
                    $f->resultado === 'NUMERO_EQUIVOCADO' => 'numero_equivocado',
                    $f->fecha_promesa !== null && $f->fecha_promesa->gte($ahora->copy()->startOfDay()) => 'promesa',
                    default => 'llamado_hace_poco',
                };
                // El más fuerte manda: «no me llamen» sobre una llamada reciente.
                $actual = $porNumero[$f->numero] ?? null;
                if ($actual === null || array_search($motivo, array_keys(self::MOTIVOS)) > array_search($actual, array_keys(self::MOTIVOS))) {
                    $porNumero[$f->numero] = $motivo;
                }
            }
        }

        $whatsappHoy = [];
        foreach (array_chunk($contratoIds, 1000) as $parte) {
            $whatsappHoy = array_merge($whatsappHoy, BitacoraCobro::where('aliado_id', $aliadoId)
                ->whereIn('contrato_id', $parte)
                ->where('resultado', 'whatsapp')
                ->where('fecha_llamada', '>=', $ahora->copy()->startOfDay())
                ->pluck('contrato_id')->map(fn ($id) => (int) $id)->all());
        }

        return ['numeros' => $porNumero, 'whatsapp_hoy' => array_values(array_unique($whatsappHoy))];
    }

    /**
     * Hasta cuándo sirve llamar: antes del vencimiento, hasta la hora de corte
     * de ese día (después ya no se abona sin mora). Si ya venció o vence hoy,
     * hasta el final del siguiente día con horario. Nunca más de 7 días (Avappi).
     */
    public static function responderAntes(?Carbon $vence, Carbon $ahora): Carbon
    {
        $tz = $ahora->getTimezone();
        $tope = $ahora->copy()->addDays(7)->subMinute();

        if ($vence && $vence->copy()->startOfDay()->gt($ahora->copy()->startOfDay())) {
            [$h, $m] = explode(':', MoraClienteService::HORA_CORTE);
            $hasta = Carbon::parse($vence->toDateString(), $tz)->setTime((int) $h, (int) $m);
        } else {
            $dia = $ahora->copy()->addDay()->startOfDay();
            while ($dia->isSunday() || in_array($dia->toDateString(), MoraClienteService::festivosColombia($dia->year), true)) {
                $dia->addDay();
            }
            $hasta = $dia->setTime(19, 0);
        }

        return $hasta->lt($tope) ? $hasta : $tope;
    }

    /** 120000 → «ciento veinte mil pesos»; 1000000 → «un millón de pesos». */
    public static function pesosEnLetras(float $valor): string
    {
        $letras = mb_strtolower(NumeroALetras::convertir($valor));
        $de = str_ends_with($letras, 'millón') || str_ends_with($letras, 'millones') ? ' de' : '';

        return "{$letras}{$de} pesos";
    }

    private static function nombreAliado(int $aliadoId): string
    {
        $config = WhatsappConfig::where('aliado_id', $aliadoId)->first();

        return $config?->nombre_cuenta ?: (Aliado::find($aliadoId)?->nombre ?? 'BryNex');
    }

    /** «transferencia o consignación (Bancolombia o Nequi), o en efectivo». */
    private static function formasPago(int $aliadoId): string
    {
        $bancos = BancoCuenta::paraCobro($aliadoId)->pluck('banco')->filter()->unique()->values()->all();
        if (! $bancos) {
            return 'transferencia, consignación o en efectivo';
        }
        $ultimo = array_pop($bancos);
        $lista = $bancos ? implode(', ', $bancos)." o {$ultimo}" : $ultimo;

        return "transferencia o consignación ({$lista}), o en efectivo";
    }

    private static function diezDigitos(string $numero): string
    {
        $d = preg_replace('/\D/', '', $numero);

        return strlen($d) > 10 ? substr($d, -10) : $d;
    }

    // ── Mandar las llamadas ─────────────────────────────────────────────────

    /**
     * Manda el lote armado a Avappi y guarda cada llamada que quedó en cola (o
     * que Avappi guardó como rechazada). Repetir el botón el mismo día no crea
     * llamadas nuevas: la referencia es la llave de idempotencia.
     *
     * @return array{en_cola: int, repetidas: int, no_salieron: array, error?: string}
     */
    public static function llamar(int $aliadoId, array $lote, int $mes, int $anio, ?int $usuarioId): array
    {
        $r = ['en_cola' => 0, 'repetidas' => 0, 'no_salieron' => []];
        if (! self::configurado()) {
            return $r + ['error' => 'Falta configurar Avappi Voz en el servidor (clave y secreto de los avisos).'];
        }
        if (! $lote['llamadas']) {
            return $r + ['error' => 'No hay a quién llamar con este filtro.'];
        }

        foreach (array_chunk($lote['llamadas'], self::MAX_LOTE) as $parte) {
            try {
                $resp = self::http()->timeout(120)->post('/api/voz/lotes', ['comunes' => $lote['comunes'], 'llamadas' => $parte]);
            } catch (\Throwable $e) {
                Log::warning('Avappi Voz: no se pudo mandar el lote de cobro: '.$e->getMessage());

                return $r + ['error' => 'Avappi no contestó. Vuelve a intentarlo: las que ya quedaron en cola no se repiten.'];
            }
            if (! $resp->successful() || ! is_array($resp->json('resultados'))) {
                return $r + ['error' => 'Avappi no recibió las llamadas: '.($resp->json('error') ?: 'respondió '.$resp->status())];
            }

            foreach ($resp->json('resultados') as $pos => $res) {
                $item = $parte[$res['indice'] ?? $pos] ?? null;
                if (! $item) {
                    continue;
                }
                $serie = $lote['series'][$item['referencia']];
                $llamada = is_array($res['llamada'] ?? null) ? $res['llamada'] : null;
                if (! empty($res['ok']) && $llamada) {
                    $nueva = self::guardar($aliadoId, $llamada, $item, $serie, $mes, $anio, $usuarioId);
                    $r[! empty($res['repetida']) || ! $nueva ? 'repetidas' : 'en_cola']++;

                    continue;
                }
                $r['no_salieron'][] = [
                    'numero' => $item['numero'],
                    'nombre' => $serie['nombre'],
                    'codigo' => $res['codigo'] ?? null,
                    'error' => $res['error'] ?? 'sin causa',
                ];
                // Avappi la guardó como rechazada («no me llamen», horario, saldo…).
                if ($llamada && ! empty($llamada['id'])) {
                    self::guardar($aliadoId, $llamada + ['causa' => $res['codigo'] ?? null], $item, $serie, $mes, $anio, $usuarioId, true);
                }
            }
        }

        return $r;
    }

    /** true si la guardó; false si ya estaba (el mismo botón dos veces). */
    private static function guardar(int $aliadoId, array $llamada, array $item, array $serie, int $mes, int $anio, ?int $usuarioId, bool $rechazada = false): bool
    {
        $id = strtolower((string) $llamada['id']);
        if (LlamadaVoz::where('avappi_id', $id)->exists()) {
            return false;
        }
        LlamadaVoz::create([
            'aliado_id' => $aliadoId,
            'avappi_id' => $id,
            'referencia' => $item['referencia'],
            'numero' => $serie['numero'],
            'nombre' => mb_substr($serie['nombre'], 0, 150),
            'contrato_ids' => $serie['contrato_ids'],
            'factura_ids' => $serie['factura_ids'] ?: null,
            'mes' => $mes,
            'anio' => $anio,
            'valor' => $serie['valor'],
            'vence' => $serie['vence'],
            'usuario_id' => $usuarioId,
            'intento' => max(1, (int) ($llamada['intento'] ?? 1)),
            'final' => $rechazada,
            'tipo' => $rechazada ? 'llamada.rechazada' : null,
            'estado' => self::corto($llamada['estado'] ?? ($rechazada ? 'rechazada' : 'pendiente'), 20),
            'causa' => self::corto($llamada['causa'] ?? null, 40),
            'aviso_en' => $rechazada ? now() : null,
        ]);

        return true;
    }

    private static function http()
    {
        return Http::baseUrl(rtrim((string) config('services.avappi_voz.url'), '/'))
            ->withHeaders(['X-Api-Key' => (string) config('services.avappi_voz.key')])
            ->acceptJson()
            ->connectTimeout(5);
    }

    // ── El aviso de Avappi ──────────────────────────────────────────────────

    /**
     * Guarda un intento y, si el cliente contestó o ya no habrá más intentos,
     * lo deja en la bitácora de sus contratos. 'repetido' si ese aviso (el id
     * de la llamada) ya se había procesado; 'ignorado' si no es de una llamada
     * de Brynex.
     */
    public static function recibirAviso(string $avisoId, array $a): string
    {
        try {
            return DB::transaction(fn () => self::procesar(strtolower($avisoId), $a));
        } catch (QueryException $e) {
            // Dos copias del mismo aviso a la vez: la otra ya insertó el intento.
            if (LlamadaVoz::where('avappi_id', strtolower($avisoId))->whereNotNull('aviso_en')->exists()) {
                return 'repetido';
            }
            throw $e;
        }
    }

    private static function procesar(string $avisoId, array $a): string
    {
        $fila = LlamadaVoz::where('avappi_id', $avisoId)->lockForUpdate()->first();
        if ($fila && $fila->aviso_en) {
            return 'repetido';
        }

        $referencia = (string) ($a['referencia'] ?? $fila?->referencia ?? '');
        $serie = $fila ?: ($referencia !== '' ? LlamadaVoz::where('referencia', $referencia)->orderBy('id')->first() : null);
        if (! $serie) {
            return 'ignorado';
        }

        $tipo = (string) ($a['tipo'] ?? 'llamada.terminada');
        $final = in_array($tipo, ['llamada.cancelada', 'llamada.rechazada'], true) || (bool) ($a['final'] ?? false);
        $datos = is_array($a['datos'] ?? null) ? $a['datos'] : [];
        $resultado = self::codigo($a['resultado'] ?? ($datos['resultado'] ?? null));

        $campos = [
            'intento' => max(1, (int) ($a['intento'] ?? 1)),
            'final' => $final,
            'tipo' => mb_substr($tipo, 0, 30),
            'estado' => self::corto($a['estado'] ?? null, 20),
            'causa' => self::corto($a['causa'] ?? null, 40),
            'resultado' => $resultado,
            'datos' => $datos ?: null,
            'resumen' => self::corto($a['resumen'] ?? null, 1000),
            'fecha_promesa' => $resultado === 'PROMESA_DE_PAGO' ? self::fechaPromesa($datos['fecha_pago'] ?? null) : null,
            'duracion_seg' => isset($a['duracion_seg']) ? (int) $a['duracion_seg'] : null,
            'costo_cop' => isset($a['costo_cop']) ? (float) $a['costo_cop'] : null,
            'proximo_intento_en' => self::fecha($a['proximo_intento_en'] ?? null),
            'terminada_en' => self::fecha($a['terminada_en'] ?? null),
            'aviso_en' => now(),
        ];

        if ($fila) {
            $fila->update($campos);
        } else {
            // Un reintento: Avappi le da un id nuevo a cada intento de la serie.
            $fila = LlamadaVoz::create($campos + $serie->only([
                'aliado_id', 'referencia', 'numero', 'nombre', 'contrato_ids', 'factura_ids',
                'mes', 'anio', 'valor', 'vence', 'usuario_id',
            ]) + ['avappi_id' => $avisoId]);
        }

        self::aplicar($fila, $tipo);

        return 'procesado';
    }

    /**
     * Lo que dijo el cliente, en la bitácora de cada contrato. Un intento sin
     * contestar que todavía tiene reintento no se anota: solo el último.
     */
    private static function aplicar(LlamadaVoz $f, string $tipo): void
    {
        if (in_array($tipo, ['llamada.cancelada', 'llamada.rechazada'], true)) {
            return;
        }
        if (! $f->resultado && ! $f->final) {
            return;
        }

        $resultado = match ($f->resultado) {
            'PROMESA_DE_PAGO' => 'promesa_pago',
            'NUMERO_EQUIVOCADO' => 'numero_errado',
            null => 'no_contesta',
            default => 'otro',
        };
        $observacion = self::observacion($f);
        $facturas = (array) ($f->factura_ids ?? []);

        foreach ((array) $f->contrato_ids as $contratoId) {
            BitacoraCobro::create([
                'aliado_id' => $f->aliado_id,
                'contrato_id' => (int) $contratoId,
                'factura_id' => $facturas[$contratoId] ?? null,
                // La bitácora exige usuario: quien pulsó el botón o, si no
                // quedó, el usuario de los procesos automáticos.
                'usuario_id' => $f->usuario_id ?? self::USUARIO_SISTEMA,
                'fecha_llamada' => now(),
                'resultado' => $resultado,
                'observacion' => $observacion,
                'tipo' => BitacoraCobro::TIPO_COBRO,
            ]);
        }
    }

    public static function observacion(LlamadaVoz $f): string
    {
        $d = (array) ($f->datos ?? []);
        $partes = [$f->resultado ? (LlamadaVoz::RESULTADOS[$f->resultado] ?? $f->resultado) : '📵 No contestó'];
        if ($f->resultado === 'PROMESA_DE_PAGO') {
            $partes[] = 'paga '.($f->fecha_promesa ? $f->fecha_promesa->format('d/m/Y') : ($d['fecha_pago'] ?? 'sin fecha'))
                .(isset($d['monto']) && is_numeric($d['monto']) ? ' $'.number_format((float) $d['monto'], 0, ',', '.') : '');
        }
        if (! empty($d['medio_pago'])) {
            $partes[] = 'por '.str_replace('_', ' ', (string) $d['medio_pago']);
        }
        if (! empty($d['motivo'])) {
            $partes[] = 'motivo: '.$d['motivo'];
        }
        if (! empty($d['pide_cuentas'])) {
            $partes[] = 'pide las cuentas por WhatsApp';
        }
        if ($f->intento > 1) {
            $partes[] = "intento {$f->intento}";
        }

        $texto = '🤖 Llamada IA: '.implode(' · ', $partes);
        if ($f->paraElEquipo()) {
            $texto = '⚠️ Para el equipo. '.$texto;
        }
        if ($f->resumen) {
            $texto .= ' — '.$f->resumen;
        }

        return mb_substr($texto, 0, 1000);
    }

    /** «2026-10-30», «30/10/2026» o «30-10-2026»; lo demás queda solo en el texto. */
    private static function fechaPromesa($valor): ?string
    {
        $v = trim((string) $valor);
        try {
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
                return Carbon::parse(substr($v, 0, 10))->toDateString();
            }
            if (preg_match('/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})$/', $v, $m)) {
                return Carbon::create((int) $m[3], (int) $m[2], (int) $m[1])->toDateString();
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private static function codigo($valor): ?string
    {
        $v = strtoupper(trim((string) $valor));

        return preg_match('/^[A-Z][A-Z0-9_]{1,39}$/', $v) ? $v : null;
    }

    private static function corto($valor, int $largo): ?string
    {
        $v = trim((string) $valor);

        return $v === '' ? null : mb_substr($v, 0, $largo);
    }

    private static function fecha($valor): ?Carbon
    {
        if (! $valor) {
            return null;
        }
        try {
            return Carbon::parse((string) $valor)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    // ── Para la pantalla ────────────────────────────────────────────────────

    /** Las últimas llamadas del aliado, una por serie (el intento más reciente). */
    public static function historial(int $aliadoId, int $dias = 7): array
    {
        $filas = LlamadaVoz::where('aliado_id', $aliadoId)
            ->where('created_at', '>=', now()->subDays($dias)->startOfDay())
            ->orderByDesc('id')
            ->limit(2000)
            ->get();

        return $filas->unique('referencia')->values()->map(fn (LlamadaVoz $f) => [
            'nombre' => $f->nombre,
            'numero' => $f->numero,
            'fecha' => $f->created_at?->format('d/m/Y H:i'),
            'valor' => (int) $f->valor,
            'intento' => $f->intento,
            'etiqueta' => $f->etiqueta,
            'para_el_equipo' => $f->paraElEquipo(),
            'promesa' => $f->fecha_promesa?->format('d/m/Y'),
            'resumen' => $f->resumen,
            'causa' => $f->causa,
            'proximo' => $f->final ? null : $f->proximo_intento_en?->format('d/m H:i'),
        ])->all();
    }
}
