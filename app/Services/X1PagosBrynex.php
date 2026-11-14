<?php

namespace App\Services;

use App\Models\ConfiguracionBrynex;
use App\Models\Plano;
use App\Models\TipoModalidad;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Pagos de Tipo E - X1 por BryNex (fase 1: lo que hace el aliado).
 *
 * La X1 solo liquida por Simple y depende de que el subtipo 04 pase —o de la
 * ventana que encuentra el vigía, o del plan B (doble planilla, Blindem)—. Eso
 * lo maneja BryNex, no cada aliado: el aliado no liquida la X1, sino que
 * solicita el pago. Manda la SS de esas personas (con el día de pensión que
 * cobra la factura) más la administración X1 por persona, pega la foto de la
 * consignación, y BryNex liquida con el usuario de Simple del dueño.
 *
 * ## No toca los planos normales
 *
 * Al solicitar, las personas X1 de cada razón social pasan a su propio plano
 * (el siguiente libre del período) si estaban compartiendo con alguien más. El
 * resto del plano del aliado queda igual. Todo lo demás vive en tablas propias
 * (`x1_solicitudes`, `x1_solicitud_planos`): si la X1 se retira, se apaga
 * `x1_pagos_brynex_activo` y los planos normales no dependieron nunca de esto.
 *
 * ## Brygar (aliado 2)
 *
 * No consigna a nadie: no se le pide foto ni se le cobra administración.
 */
class X1PagosBrynex
{
    /** Interruptor del flujo. Apagado, el aliado vuelve a liquidar su X1. */
    public const CONFIG_ACTIVO = 'x1_pagos_brynex_activo';

    /** Administración X1 por persona por defecto. */
    public const CONFIG_ADMON = 'x1_admon_por_persona';

    public const ADMON_POR_DEFECTO = 10000;

    /** JSON {"aliado_id": valor} con la admon de cada aliado que no usa la de defecto. */
    public const CONFIG_ADMON_ALIADOS = 'x1_admon_por_aliado';

    /** Brygar: es BryNex mismo, no consigna ni paga admon. */
    public const ALIADO_BRYGAR = 2;

    public const ESTADO_SOLICITADA = 'solicitada';

    public const ESTADO_PLANO_EN_SOLICITUD = 'en_solicitud';

    public const ETIQUETAS_ESTADO_PLANO = [
        'en_solicitud' => 'En solicitud',
        'pagada' => 'Pagada',
        'esperando_ventana' => 'Esperando ventana',
        'blindem' => 'Pagada por Blindem',
        'doble_planilla' => 'Pagada (doble planilla)',
    ];

    public static function activo(): bool
    {
        return (bool) (int) ConfiguracionBrynex::obtener(self::CONFIG_ACTIVO, 1);
    }

    /**
     * ¿Puede liquidar una tanda X1 en el operador? Con el flujo apagado, todos
     * como antes. Encendido, solo el superadmin de BryNex y los procesos de
     * consola (el vigía liquida solo cuando se abre la ventana).
     */
    public static function puedeLiquidar(?User $user): bool
    {
        if (! self::activo() || app()->runningInConsole()) {
            return true;
        }

        return self::esSuperadminBrynex($user);
    }

    /**
     * ¿La tanda que se va a liquidar lleva gente X1? Con filtro de modalidades
     * lo dice el filtro; con «Todos» se mira el plano, porque un plano que es
     * solo de X1 se liquida sin filtro y el filtro vacío no lo delata.
     */
    public static function tandaTieneX1(int $aliadoId, int $rsId, int $mes, int $anio, int $nPlano, array $filtro): bool
    {
        if ($filtro !== []) {
            return PilaCotizanteEX1::esTanda($filtro);
        }

        return DB::table('planos AS p')
            ->where('p.aliado_id', $aliadoId)
            ->where('p.razon_social_id', $rsId)
            ->where('p.n_plano', $nPlano)
            ->whereIn('p.tipo_modalidad_id', TipoModalidad::IDS_EX1)
            ->whereNull('p.deleted_at')
            ->tap(fn ($w) => Plano::filtrarPeriodoDePago($w, $mes, $anio))
            ->exists();
    }

    public static function esSuperadminBrynex(?User $user): bool
    {
        return (bool) ($user?->es_brynex && $user->hasRole('superadmin'));
    }

    public static function esBrygar(int $aliadoId): bool
    {
        return $aliadoId === self::ALIADO_BRYGAR;
    }

    /** Administración X1 por persona que paga este aliado. */
    public static function admonPorPersona(int $aliadoId): int
    {
        if (self::esBrygar($aliadoId)) {
            return 0;
        }

        $porAliado = json_decode((string) ConfiguracionBrynex::obtener(self::CONFIG_ADMON_ALIADOS, ''), true);
        if (is_array($porAliado) && isset($porAliado[(string) $aliadoId]) && is_numeric($porAliado[(string) $aliadoId])) {
            return (int) $porAliado[(string) $aliadoId];
        }

        return (int) ConfiguracionBrynex::obtener(self::CONFIG_ADMON, self::ADMON_POR_DEFECTO);
    }

    /**
     * Personas X1 del período que el aliado todavía no ha mandado: facturadas,
     * sin planilla y fuera de toda solicitud. Las que facturó después de una
     * solicitud aparecen aquí solas.
     */
    public static function pendientes(int $aliadoId, int $mes, int $anio): Collection
    {
        $q = DB::table('planos AS p')
            ->join('razones_sociales AS rs', function ($j) {
                $j->on('rs.id', '=', 'p.razon_social_id')->on('rs.aliado_id', '=', 'p.aliado_id');
            })
            ->leftJoin('facturas AS f', function ($j) use ($aliadoId) {
                $j->on('f.id', '=', 'p.factura_id')->where('f.aliado_id', $aliadoId);
            })
            ->leftJoin('x1_solicitud_planos AS xp', 'xp.plano_id', '=', 'p.id')
            ->where('p.aliado_id', $aliadoId)
            ->whereIn('p.tipo_modalidad_id', TipoModalidad::IDS_EX1)
            ->whereIn('p.tipo_reg', ['planilla', 'retiro'])
            ->where('p.num_dias', '>', 0)
            ->whereNull('p.deleted_at')
            ->whereNull('f.deleted_at')
            ->where(fn ($w) => $w->whereNull('p.numero_planilla')->orWhere('p.numero_planilla', ''))
            ->whereNull('xp.id')
            ->tap(fn ($w) => Plano::filtrarPeriodoDePago($w, $mes, $anio))
            ->orderBy('rs.razon_social')->orderBy('p.primer_ape')
            ->select([
                'p.id', 'p.razon_social_id', 'p.n_plano', 'p.tipo_modalidad_id', 'p.tipo_doc',
                'p.no_identifi', 'p.primer_nombre', 'p.primer_ape', 'p.num_dias',
                'rs.razon_social', 'rs.nit AS rs_nit', 'rs.dia_habil AS rs_dia_habil',
                'f.v_afp', 'f.total_ss',
            ]);

        return $q->get()->map(fn ($r) => self::conDiaPension($r));
    }

    /**
     * La SS que se cobra por la persona, con el día de pensión. Las facturas de
     * antes de nov-2026 lo traían en `otros` con v_afp en cero: se completa
     * igual que en la vista de planos (PlanoPagoController::index).
     */
    private static function conDiaPension(object $r): object
    {
        $r->valor_afp = (int) ($r->v_afp ?? 0);
        $r->valor_ss = (int) ($r->total_ss ?? 0);
        if ($r->valor_afp === 0) {
            $dia = PilaCotizanteEX1::diaPension();
            $r->valor_afp = $dia;
            $r->valor_ss += $dia;
        }

        return $r;
    }

    /**
     * Pendientes agrupados por razón social, con lo que cuesta cada una:
     * SS + admon X1 por persona + mora estimada (si ya venció, la paga el aliado).
     *
     * @return Collection<int, array>
     */
    public static function resumenPorRazonSocial(int $aliadoId, int $mes, int $anio, ?Collection $pendientes = null): Collection
    {
        $pendientes ??= self::pendientes($aliadoId, $mes, $anio);
        $admon = self::admonPorPersona($aliadoId);

        return $pendientes->groupBy('razon_social_id')->map(function (Collection $filas, $rsId) use ($aliadoId, $mes, $anio, $admon) {
            $primera = $filas->first();
            $ss = (int) $filas->sum('valor_ss');
            $mora = self::moraEstimada($aliadoId, $primera, $ss, $mes, $anio);
            $valorAdmon = $admon * $filas->count();

            return [
                'razon_social_id' => (int) $rsId,
                'razon_social' => trim((string) $primera->razon_social),
                'planos' => $filas->pluck('n_plano')->map(fn ($n) => (int) $n)->unique()->sort()->values()->all(),
                'personas' => $filas->count(),
                'valor_ss' => $ss,
                'valor_admon' => $valorAdmon,
                'valor_mora' => $mora,
                'total' => $ss + $valorAdmon + $mora,
                'filas' => $filas->values(),
            ];
        })->values();
    }

    private static function moraEstimada(int $aliadoId, object $fila, int $ss, int $mes, int $anio): int
    {
        $nit = (int) preg_replace('/\D/', '', (string) ($fila->rs_nit ?? ''));
        if ($nit <= 0 || $ss <= 0) {
            return 0;
        }

        try {
            $info = MoraClienteService::calcular(
                $aliadoId, $nit,
                $fila->rs_dia_habil !== null ? (int) $fila->rs_dia_habil : null,
                $ss, $mes, $anio
            );

            return (int) ($info['mora'] ?? 0);
        } catch (\Throwable $e) {
            // Es una estimación: si falla, la solicitud sale sin mora y BryNex la ajusta.
            Log::warning('x1-pagos: no se pudo estimar la mora', ['aliado' => $aliadoId, 'error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Crea la solicitud de las razones sociales marcadas. Los valores se
     * recalculan aquí —no se confía en lo que mandó el navegador— y cada
     * persona X1 queda en el plano X1 de su razón social.
     *
     * @param  int[]  $razonesSociales
     * @return array{ok: bool, mensaje: string, solicitud_id?: int}
     */
    public static function solicitar(
        int $aliadoId,
        int $mes,
        int $anio,
        array $razonesSociales,
        ?UploadedFile $soporte,
        ?int $usuarioId
    ): array {
        $razonesSociales = array_values(array_unique(array_map('intval', $razonesSociales)));
        if (! $razonesSociales) {
            return ['ok' => false, 'mensaje' => 'Marque al menos una razón social.'];
        }

        if (! $soporte && ! self::esBrygar($aliadoId)) {
            return ['ok' => false, 'mensaje' => 'Falta la foto de la consignación.'];
        }

        $soportePath = null;
        if ($soporte) {
            // Disco local (storage/app): es un comprobante bancario, nunca public.
            $soportePath = $soporte->store("x1-solicitudes/{$aliadoId}/{$anio}-".str_pad((string) $mes, 2, '0', STR_PAD_LEFT), 'local');
        }

        try {
            $solicitud = DB::transaction(function () use ($aliadoId, $mes, $anio, $razonesSociales, $soporte, $soportePath, $usuarioId) {
                $pendientes = self::pendientes($aliadoId, $mes, $anio)
                    ->whereIn('razon_social_id', $razonesSociales);

                if ($pendientes->isEmpty()) {
                    throw new \RuntimeException('Esas razones sociales ya no tienen personas X1 por solicitar.');
                }

                // Bloquea los registros mientras se mueven: dos clics seguidos no
                // pueden meter a la misma persona en dos solicitudes (además del
                // índice único de x1_solicitud_planos.plano_id).
                DB::table('planos')->whereIn('id', $pendientes->pluck('id')->all())
                    ->lockForUpdate()->get(['id']);

                $resumen = self::resumenPorRazonSocial($aliadoId, $mes, $anio, $pendientes);
                $admon = self::admonPorPersona($aliadoId);

                $solicitudId = DB::table('x1_solicitudes')->insertGetId([
                    'aliado_id' => $aliadoId,
                    'mes' => $mes,
                    'anio' => $anio,
                    'estado' => self::ESTADO_SOLICITADA,
                    'personas' => (int) $resumen->sum('personas'),
                    'valor_ss' => (int) $resumen->sum('valor_ss'),
                    'valor_admon' => (int) $resumen->sum('valor_admon'),
                    'valor_mora' => (int) $resumen->sum('valor_mora'),
                    'total' => (int) $resumen->sum('total'),
                    'admon_por_persona' => $admon,
                    'soporte_path' => $soportePath,
                    'soporte_nombre' => $soporte ? mb_substr($soporte->getClientOriginalName(), 0, 200) : null,
                    'usuario_id' => $usuarioId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($resumen as $rs) {
                    $destino = self::planoX1Destino($aliadoId, $rs['razon_social_id'], $mes, $anio, $rs['filas']);

                    foreach ($rs['filas'] as $fila) {
                        DB::table('x1_solicitud_planos')->insert([
                            'x1_solicitud_id' => $solicitudId,
                            'aliado_id' => $aliadoId,
                            'plano_id' => $fila->id,
                            'razon_social_id' => $rs['razon_social_id'],
                            'tipo_modalidad_id' => (int) $fila->tipo_modalidad_id,
                            'n_plano_origen' => (int) $fila->n_plano,
                            'n_plano' => $destino,
                            'valor_ss' => (int) $fila->valor_ss,
                            'valor_afp' => (int) $fila->valor_afp,
                            'estado' => self::ESTADO_PLANO_EN_SOLICITUD,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    self::moverAPlano($aliadoId, $rs['filas']->pluck('id')->all(), $destino);
                }

                return DB::table('x1_solicitudes')->find($solicitudId);
            });
        } catch (\RuntimeException $e) {
            if ($soportePath) {
                Storage::disk('local')->delete($soportePath);
            }

            return ['ok' => false, 'mensaje' => $e->getMessage()];
        } catch (\Illuminate\Database\QueryException $e) {
            if ($soportePath) {
                Storage::disk('local')->delete($soportePath);
            }
            Log::warning('x1-pagos: no se pudo crear la solicitud', ['aliado' => $aliadoId, 'error' => $e->getMessage()]);

            return ['ok' => false, 'mensaje' => 'No se pudo crear la solicitud: alguna persona ya estaba en otra. Recargue la página.'];
        }

        self::avisar($solicitud);

        return [
            'ok' => true,
            'mensaje' => 'Solicitud enviada a BryNex: '.$solicitud->personas.' '.($solicitud->personas == 1 ? 'persona' : 'personas')
                .' por $'.number_format((float) $solicitud->total, 0, ',', '.').'.',
            'solicitud_id' => (int) $solicitud->id,
        ];
    }

    /**
     * El plano donde quedan las X1 de la razón social.
     *
     * Se quedan donde están solo si ese plano es solo de ellas: un único
     * n_plano sin nadie más (ni gente normal ni X1 de otra solicitud). Si no,
     * van al siguiente número libre del período, para que el plano normal del
     * aliado se pueda liquidar y cerrar sin esperar a BryNex.
     */
    private static function planoX1Destino(int $aliadoId, int $rsId, int $mes, int $anio, Collection $filas): int
    {
        $origenes = $filas->pluck('n_plano')->map(fn ($n) => (int) $n)->unique();
        $ids = $filas->pluck('id')->all();

        $base = fn () => DB::table('planos AS p')
            ->where('p.aliado_id', $aliadoId)
            ->where('p.razon_social_id', $rsId)
            ->whereNull('p.deleted_at')
            ->tap(fn ($w) => Plano::filtrarPeriodoDePago($w, $mes, $anio));

        if ($origenes->count() === 1) {
            $otros = $base()->where('p.n_plano', $origenes->first())->whereNotIn('p.id', $ids)->exists();
            if (! $otros) {
                return (int) $origenes->first();
            }
        }

        return (int) $base()->max('p.n_plano') + 1;
    }

    /** Mueve los registros al plano X1 y sincroniza facturas.n_plano (como moverPlanoMasivo). */
    private static function moverAPlano(int $aliadoId, array $planoIds, int $nPlano): void
    {
        $facturaIds = DB::table('planos')
            ->whereIn('id', $planoIds)->where('aliado_id', $aliadoId)
            ->whereNotNull('factura_id')->pluck('factura_id')->all();

        DB::table('planos')->whereIn('id', $planoIds)->where('aliado_id', $aliadoId)
            ->update(['n_plano' => $nPlano, 'updated_at' => now()]);

        if ($facturaIds) {
            DB::table('facturas')->whereIn('id', $facturaIds)->where('aliado_id', $aliadoId)
                ->whereNull('deleted_at')->update(['n_plano' => $nPlano]);
        }
    }

    /** Aviso interno al número de BryNex. Si no sale, la solicitud igual queda. */
    private static function avisar(object $solicitud): void
    {
        try {
            $aliado = DB::table('aliados')->where('id', $solicitud->aliado_id)->value('nombre');
            $empresas = DB::table('x1_solicitud_planos AS xp')
                ->join('razones_sociales AS rs', 'rs.id', '=', 'xp.razon_social_id')
                ->where('xp.x1_solicitud_id', $solicitud->id)
                ->groupBy('rs.razon_social')
                ->selectRaw('rs.razon_social, COUNT(*) AS personas')
                ->get()
                ->map(fn ($e) => '• '.trim((string) $e->razon_social).' ('.$e->personas.')')
                ->implode("\n");

            $texto = "Solicitud de pago X1 #{$solicitud->id}\n"
                .'Aliado: '.($aliado ?: $solicitud->aliado_id)."\n"
                .'Período de pago: '.str_pad((string) $solicitud->mes, 2, '0', STR_PAD_LEFT).'/'.$solicitud->anio."\n"
                .$empresas."\n"
                .'Total: $'.number_format((float) $solicitud->total, 0, ',', '.')
                .($solicitud->soporte_path ? ' · con foto de la consignación' : ' · sin foto (Brygar)');

            app(AlertaOperativaService::class)->enviar('x1-solicitud', $texto);
        } catch (\Throwable $e) {
            Log::warning('x1-pagos: no salió el aviso de la solicitud', ['solicitud' => $solicitud->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Solicitudes del aliado con el estado de cada persona.
     *
     * @return Collection<int, object>
     */
    public static function solicitudes(int $aliadoId, int $limite = 30): Collection
    {
        $solicitudes = DB::table('x1_solicitudes')
            ->where('aliado_id', $aliadoId)
            ->orderByDesc('id')
            ->limit($limite)
            ->get();

        if ($solicitudes->isEmpty()) {
            return $solicitudes;
        }

        $filas = DB::table('x1_solicitud_planos AS xp')
            ->join('planos AS p', 'p.id', '=', 'xp.plano_id')
            ->join('razones_sociales AS rs', 'rs.id', '=', 'xp.razon_social_id')
            ->whereIn('xp.x1_solicitud_id', $solicitudes->pluck('id')->all())
            ->where('xp.aliado_id', $aliadoId)
            ->orderBy('rs.razon_social')->orderBy('p.primer_ape')
            ->select([
                'xp.*', 'rs.razon_social', 'p.tipo_doc', 'p.no_identifi', 'p.primer_nombre', 'p.primer_ape',
                'p.numero_planilla AS planilla_plano',
            ])
            ->get()
            ->groupBy('x1_solicitud_id');

        return $solicitudes->map(function ($s) use ($filas) {
            $s->filas = $filas->get($s->id, collect());

            return $s;
        });
    }
}
