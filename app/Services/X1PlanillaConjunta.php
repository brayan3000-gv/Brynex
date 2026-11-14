<?php

namespace App\Services;

use App\Http\Controllers\Admin\PlanillaApiController;
use App\Models\OperadorPlanillaApi;
use App\Models\TipoModalidad;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Pagos X1 por BryNex — una sola planilla por razón social, con la gente de
 * todos los aliados.
 *
 * Varios aliados manejan la misma empresa (mismo NIT, cada uno con su copia de
 * la razón social y su sucursal). En vez de una planilla y un PSE por aliado,
 * BryNex liquida una sola con la **sucursal de Brygar** (la copia de la razón
 * social en el aliado 2): el encabezado es de Brygar y las personas salen de
 * los planos de cada aliado (PlanoPilaTxtService `grupos`). El registro de la
 * liquidación va a nombre de Brygar en el plano X1PagosBrynex::PLANO_CONJUNTO,
 * así que el 409 que evita duplicar sigue funcionando.
 *
 * La consignación de cada solicitud se puede marcar como verificada, pero eso
 * no frena la liquidación: se paga primero y se verifica después. Lo demás —rebotes por el subtipo 4, enlace de
 * pago, confirmación con comprobante— sigue las mismas reglas que la tanda de
 * un aliado (X1LiquidacionBrynex).
 */
class X1PlanillaConjunta
{
    /** Estados de una persona que entran a la planilla conjunta. */
    private const ESTADOS_PARA_LIQUIDAR = ['en_solicitud', 'esperando_ventana'];

    /**
     * Personas X1 del período agrupadas por razón social (NIT) y modalidad,
     * con el detalle por aliado.
     *
     * @return Collection<int, object>
     */
    public static function grupos(int $mes, int $anio): Collection
    {
        $filas = DB::table('x1_solicitud_planos AS xp')
            ->join('x1_solicitudes AS s', 's.id', '=', 'xp.x1_solicitud_id')
            ->join('razones_sociales AS rs', 'rs.id', '=', 'xp.razon_social_id')
            ->join('aliados AS a', 'a.id', '=', 'xp.aliado_id')
            ->join('planos AS p', 'p.id', '=', 'xp.plano_id')
            ->leftJoin('facturas AS f', function ($j) {
                $j->on('f.id', '=', 'p.factura_id')->on('f.aliado_id', '=', 'p.aliado_id');
            })
            ->where('s.mes', $mes)->where('s.anio', $anio)
            ->whereNull('p.deleted_at')
            ->orderBy('rs.razon_social')->orderBy('a.nombre')->orderBy('p.primer_ape')
            ->get([
                'xp.*', 'rs.nit', 'rs.razon_social', 'a.nombre AS aliado_nombre',
                's.consignacion_verificada_at', 's.soporte_path', 's.id AS solicitud_id',
                'p.tipo_doc', 'p.no_identifi', 'p.primer_nombre', 'p.primer_ape', 'p.num_dias',
                'f.v_eps', 'f.v_arl', 'f.v_caja',
            ])
            ->map(function ($f) {
                $f->nit_limpio = preg_replace('/\D/', '', (string) $f->nit);
                $f->verificada = self::esVerificada($f);

                return $f;
            });

        $simple = X1LiquidacionBrynex::operadorSimpleId();

        return $filas->groupBy(fn ($f) => $f->nit_limpio.'|'.$f->tipo_modalidad_id)
            ->map(function (Collection $g) use ($simple, $mes, $anio) {
                $primera = $g->first();
                $mod = (int) $primera->tipo_modalidad_id;
                $rsBrygar = self::razonSocialBrygar($primera->nit_limpio);
                $registro = ($rsBrygar && $simple) ? self::registroConjunto($rsBrygar, $simple, $mod, $mes, $anio) : null;

                $aliados = $g->groupBy('aliado_id')->map(fn (Collection $a) => (object) [
                    'aliado_id' => (int) $a->first()->aliado_id,
                    'nombre' => $a->first()->aliado_nombre,
                    'personas' => $a->count(),
                    'valor_ss' => (int) $a->sum('valor_ss'),
                    'verificada' => $a->every(fn ($f) => $f->verificada),
                    'solicitudes' => $a->pluck('solicitud_id')->unique()->values()->all(),
                    'estados' => $a->countBy('estado')->all(),
                ])->values();

                $paraLiquidar = $g->filter(fn ($f) => self::entraALaConjunta($f, $registro));

                return (object) [
                    'nit' => $primera->nit_limpio,
                    'razon_social' => trim((string) $primera->razon_social),
                    'tipo_modalidad_id' => $mod,
                    'modalidad' => $mod === TipoModalidad::ID_EX1_TP ? 'X1 TP14' : 'X1',
                    'rs_brygar' => $rsBrygar,
                    'registro' => $registro,
                    'personas' => $g->count(),
                    'valor_ss' => (int) $g->sum('valor_ss'),
                    'aliados' => $aliados,
                    'estados' => $g->countBy('estado')->all(),
                    'para_liquidar' => $paraLiquidar->count(),
                    'sin_verificar' => $g->filter(fn ($f) => in_array($f->estado, self::ESTADOS_PARA_LIQUIDAR, true) && ! $f->verificada)->count(),
                    'por_pagar' => $registro && $g->contains(fn ($f) => $f->estado === 'liquidada' && $f->numero_planilla === $registro->numero_planilla),
                    'abierta' => $g->contains(fn ($f) => ! in_array($f->estado, X1PagosBrynex::ESTADOS_CERRADOS, true)),
                    'filas' => $g->values(),
                ];
            })
            ->sortBy('razon_social')
            ->values();
    }

    /**
     * Liquida en Simple una sola planilla de la razón social con la gente
     * pendiente de todos los aliados.
     *
     * @return array{ok: bool, estado: string, mensaje: string, numero_planilla?: string,
     *               valor_total?: float|null, url_pago?: string|null, errores?: array}
     */
    public static function liquidar(string $nit, int $tipoModalidadId, int $mes, int $anio): array
    {
        if (! X1LiquidacionBrynex::puedeOperar(Auth::user())) {
            return ['ok' => false, 'estado' => 'error', 'mensaje' => 'Solo el superadmin de BryNex liquida las X1.'];
        }

        $nit = preg_replace('/\D/', '', $nit);
        $rsBrygar = self::razonSocialBrygar($nit);
        if (! $rsBrygar) {
            return ['ok' => false, 'estado' => 'error', 'mensaje' => 'Esta razón social no está en Brygar: no se puede juntar. Liquide la tanda de cada aliado.'];
        }

        $simple = X1LiquidacionBrynex::operadorSimpleId();
        if (! $simple) {
            return ['ok' => false, 'estado' => 'error', 'mensaje' => 'No está el operador Simple en el catálogo.'];
        }

        // Brygar no consigna: sus X1 de la empresa entran solas a la cola.
        self::colaBrygar($rsBrygar, $mes, $anio);

        $registro = self::registroConjunto($rsBrygar, $simple, $tipoModalidadId, $mes, $anio);
        $reliquidar = false;

        // ── Ya hay planilla conjunta: ¿se pagó? (mismas reglas que una tanda) ─
        if ($registro) {
            $pago = X1LiquidacionBrynex::estadoPago(X1PagosBrynex::ALIADO_BRYGAR, (string) $registro->numero_planilla);
            $mensaje = (string) ($pago['mensaje'] ?? $pago['message'] ?? '');
            $sinPagar = ($pago['success'] ?? false) && ($pago['pagada'] ?? null) === false;

            if (($pago['success'] ?? false) && ($pago['pagada'] ?? null) === true) {
                return ['ok' => false, 'estado' => 'ya', 'numero_planilla' => (string) $registro->numero_planilla,
                    'mensaje' => "Simple dice que la planilla {$registro->numero_planilla} ya está pagada. Confírmala con su comprobante."];
            }

            $nuevas = self::filasParaLiquidar($nit, $tipoModalidadId, $mes, $anio, null)->isNotEmpty();

            if (! empty($pago['url_pago']) && ! $nuevas) {
                return ['ok' => true, 'estado' => 'liquidada', 'numero_planilla' => (string) $registro->numero_planilla,
                    'valor_total' => isset($pago['total_pagar']) ? (float) $pago['total_pagar'] : null,
                    'url_pago' => $pago['url_pago'],
                    'mensaje' => "La planilla {$registro->numero_planilla} sigue vigente: Simple dio el enlace de pago."];
            }

            if (! $sinPagar && ! str_contains(mb_strtolower($mensaje), 'errores') && empty($pago['url_pago'])) {
                return ['ok' => false, 'estado' => 'ya', 'numero_planilla' => (string) $registro->numero_planilla,
                    'mensaje' => "No se pudo saber si la planilla {$registro->numero_planilla} está pagada"
                        .($mensaje !== '' ? " (Simple: {$mensaje})" : '').'. No se volvió a liquidar para no pagar dos veces.'];
            }

            // Sin pagar: se rehace con todos (los de la anterior y los nuevos).
            $reliquidar = true;
        }

        $filas = self::filasParaLiquidar($nit, $tipoModalidadId, $mes, $anio, $registro);
        if ($filas->isEmpty()) {
            return ['ok' => false, 'estado' => 'error', 'mensaje' => 'No hay personas pendientes para liquidar en esta razón social.'];
        }

        $intento = self::llamar($rsBrygar, $simple, $tipoModalidadId, $mes, $anio, $filas, $reliquidar);
        $movidas = 0;

        // Rebotes por el subtipo 4: a esperar la ventana en el plano X1 de su
        // aliado, y una vez más con el resto.
        if (! $intento['liquidada'] && $intento['http'] === 200) {
            $rebote = X1LiquidacionBrynex::rebotadasPorSubtipo($intento['data']);
            if ($rebote['solo_subtipo'] && $rebote['documentos']) {
                $rebotadas = $filas->filter(fn ($f) => in_array(X1LiquidacionBrynex::doc($f->no_identifi), $rebote['documentos'], true));

                foreach ($rebotadas->groupBy('aliado_id') as $aliadoId => $deAliado) {
                    foreach ($deAliado->groupBy('razon_social_id') as $rsId => $deRs) {
                        $destino = X1PagosBrynex::planoX1Libre((int) $aliadoId, (int) $rsId, $mes, $anio);
                        X1LiquidacionBrynex::moverFilas((int) $aliadoId, $deRs, $destino);
                    }
                }
                $movidas = $rebotadas->count();
                $filas = $filas->reject(fn ($f) => $rebotadas->contains('id', $f->id))->values();

                if ($filas->isEmpty()) {
                    return ['ok' => false, 'estado' => 'esperando',
                        'mensaje' => "Simple rechazó el subtipo 4 (eo.val.2.655) a las {$movidas}: quedan «Esperando ventana» en el plano X1 de su aliado."];
                }

                $intento = self::llamar($rsBrygar, $simple, $tipoModalidadId, $mes, $anio, $filas, $reliquidar);
            }
        }

        if ($intento['liquidada']) {
            DB::table('x1_solicitud_planos')->whereIn('id', $filas->pluck('id')->all())
                ->update(['estado' => 'liquidada', 'numero_planilla' => $intento['numero'], 'updated_at' => now()]);
            X1LiquidacionBrynex::recalcularSolicitudes($filas->pluck('x1_solicitud_id')->unique()->all());

            Log::warning('x1-pagos: planilla conjunta liquidada', [
                'nit' => $nit, 'mod' => $tipoModalidadId, 'planilla' => $intento['numero'],
                'personas' => $filas->count(), 'aliados' => $filas->pluck('aliado_id')->unique()->values()->all(),
                'usuario' => Auth::id(),
            ]);

            return [
                'ok' => true,
                'estado' => 'liquidada',
                'numero_planilla' => $intento['numero'],
                'valor_total' => $intento['valor'],
                'url_pago' => $intento['data']['url_pago'] ?? null,
                'mensaje' => 'Planilla '.$intento['numero'].' liquidada para '.$filas->count().' '.($filas->count() === 1 ? 'persona' : 'personas')
                    .' de '.$filas->pluck('aliado_id')->unique()->count().' '.($filas->pluck('aliado_id')->unique()->count() === 1 ? 'aliado' : 'aliados')
                    .($intento['valor'] !== null ? ' por $'.number_format($intento['valor'], 0, ',', '.') : '')
                    .($movidas ? ". {$movidas} rebotaron por el subtipo 4 y quedaron esperando la ventana" : '').'.',
            ];
        }

        $data = $intento['data'];
        $errores = collect(array_merge($data['errores_empresa'] ?? [], $data['errores_cotizante'] ?? []))
            ->map(fn ($e) => trim(($e['identificacion'] ?? '').' '.($e['idRegla'] ?? '').' '.($e['descripcion'] ?? '')))
            ->filter()->take(15)->values()->all();

        return [
            'ok' => false,
            'estado' => $errores ? 'rechazada' : 'transitorio',
            'mensaje' => ($movidas ? "{$movidas} rebotaron por el subtipo 4 y quedaron esperando la ventana. " : '')
                .(string) ($data['message'] ?? 'Simple no liquidó la planilla.'),
            'errores' => $errores,
        ];
    }

    /** Enlace de pago fresco de la planilla conjunta (cuenta de BryNex). */
    public static function urlPago(string $numero): array
    {
        return X1LiquidacionBrynex::urlPago(X1PagosBrynex::ALIADO_BRYGAR, $numero);
    }

    /**
     * Confirma el pago de la planilla conjunta con un solo comprobante: el
     * número va a los planos de cada aliado y cada persona queda cerrada. El
     * pago queda a nombre de Brygar; la parte de cada aliado se ve sumando sus
     * personas (x1_solicitud_planos.x1_pago_id).
     */
    public static function confirmar(
        string $nit,
        int $tipoModalidadId,
        int $mes,
        int $anio,
        string $numero,
        string $estado,
        int $valor,
        ?string $comprobante = null,
        ?string $fechaPago = null,
        ?UploadedFile $soporte = null,
        ?string $observacion = null
    ): array {
        if (! X1LiquidacionBrynex::puedeOperar(Auth::user())) {
            return ['ok' => false, 'mensaje' => 'Solo el superadmin de BryNex confirma pagos X1.'];
        }
        if (! in_array($estado, X1PagosBrynex::ESTADOS_CERRADOS, true)) {
            return ['ok' => false, 'mensaje' => 'Estado de cierre no válido.'];
        }
        $numero = trim($numero);
        if ($numero === '' || ! preg_match('/^[0-9A-Za-z-]{4,30}$/', $numero)) {
            return ['ok' => false, 'mensaje' => 'Escriba el número de planilla real.'];
        }
        if ($valor <= 0) {
            return ['ok' => false, 'mensaje' => 'Escriba el valor pagado.'];
        }

        $nit = preg_replace('/\D/', '', $nit);
        $rsBrygar = self::razonSocialBrygar($nit);

        // Las personas de esa planilla (liquidadas con ese número) en todos los aliados.
        $filas = DB::table('x1_solicitud_planos AS xp')
            ->join('x1_solicitudes AS s', 's.id', '=', 'xp.x1_solicitud_id')
            ->join('razones_sociales AS rs', 'rs.id', '=', 'xp.razon_social_id')
            ->where('s.mes', $mes)->where('s.anio', $anio)
            ->where('xp.tipo_modalidad_id', $tipoModalidadId)
            ->where('xp.estado', 'liquidada')
            ->where('xp.numero_planilla', $numero)
            ->get(['xp.*', 'rs.nit'])
            ->filter(fn ($f) => preg_replace('/\D/', '', (string) $f->nit) === $nit)
            ->values();

        if ($filas->isEmpty()) {
            return ['ok' => false, 'mensaje' => "No hay personas liquidadas con la planilla {$numero} en esta razón social."];
        }

        $soportePath = $soporte ? $soporte->store('x1-pagos/conjunta/'.now()->format('Y-m'), 'local') : null;

        try {
            $pagoId = DB::transaction(function () use ($filas, $rsBrygar, $tipoModalidadId, $numero, $estado, $valor, $comprobante, $fechaPago, $soporte, $soportePath, $observacion) {
                $pagoId = DB::table('x1_pagos')->insertGetId([
                    'aliado_id' => X1PagosBrynex::ALIADO_BRYGAR,
                    'razon_social_id' => (int) ($rsBrygar ?? $filas->first()->razon_social_id),
                    'n_plano' => X1PagosBrynex::PLANO_CONJUNTO,
                    'tipo_modalidad_id' => $tipoModalidadId,
                    'numero_planilla' => $numero,
                    'forma' => $estado,
                    'valor' => $valor,
                    'comprobante' => $comprobante !== null && trim($comprobante) !== '' ? mb_substr(trim($comprobante), 0, 60) : null,
                    'fecha_pago' => $fechaPago ?: today()->toDateString(),
                    'soporte_path' => $soportePath,
                    'soporte_nombre' => $soporte ? mb_substr($soporte->getClientOriginalName(), 0, 200) : null,
                    'observacion' => $observacion !== null && trim($observacion) !== '' ? mb_substr(trim($observacion), 0, 500) : null,
                    'personas' => $filas->count(),
                    'usuario_id' => Auth::id(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($filas->groupBy('aliado_id') as $aliadoId => $deAliado) {
                    DB::table('planos')->whereIn('id', $deAliado->pluck('plano_id')->all())
                        ->where('aliado_id', (int) $aliadoId)
                        ->where(fn ($q) => $q->whereNull('numero_planilla')->orWhere('numero_planilla', ''))
                        ->update(['numero_planilla' => $numero, 'updated_at' => now()]);
                }

                DB::table('x1_solicitud_planos')->whereIn('id', $filas->pluck('id')->all())
                    ->update(['estado' => $estado, 'x1_pago_id' => $pagoId, 'updated_at' => now()]);

                return $pagoId;
            });
        } catch (\Throwable $e) {
            if ($soportePath) {
                Storage::disk('local')->delete($soportePath);
            }
            throw $e;
        }

        X1LiquidacionBrynex::recalcularSolicitudes($filas->pluck('x1_solicitud_id')->unique()->all());

        Log::warning('x1-pagos: planilla conjunta confirmada', [
            'pago' => $pagoId, 'nit' => $nit, 'planilla' => $numero, 'valor' => $valor,
            'personas' => $filas->count(), 'usuario' => Auth::id(),
        ]);

        return ['ok' => true, 'mensaje' => 'Planilla '.$numero.' confirmada para '.$filas->count().' '.($filas->count() === 1 ? 'persona' : 'personas')
            .' de '.$filas->pluck('aliado_id')->unique()->count().' '.($filas->pluck('aliado_id')->unique()->count() === 1 ? 'aliado' : 'aliados')
            .' por $'.number_format($valor, 0, ',', '.').'.'];
    }

    /** Marca (o desmarca) que la consignación de la solicitud es real. */
    public static function verificarConsignacion(int $solicitudId, bool $verificada): array
    {
        if (! X1LiquidacionBrynex::puedeOperar(Auth::user())) {
            return ['ok' => false, 'mensaje' => 'Solo el superadmin de BryNex.'];
        }

        $n = DB::table('x1_solicitudes')->where('id', $solicitudId)->update([
            'consignacion_verificada_at' => $verificada ? now() : null,
            'consignacion_verificada_por' => $verificada ? Auth::id() : null,
            'updated_at' => now(),
        ]);

        return $n
            ? ['ok' => true, 'mensaje' => $verificada ? 'Consignación verificada.' : 'Verificación quitada.']
            : ['ok' => false, 'mensaje' => 'No se encontró la solicitud.'];
    }

    // ── Internos ───────────────────────────────────────────────────────────

    private static function esVerificada(object $f): bool
    {
        return (int) $f->aliado_id === X1PagosBrynex::ALIADO_BRYGAR || ! empty($f->consignacion_verificada_at);
    }

    /** La copia de la razón social en Brygar (mismo NIT): su sucursal encabeza la planilla. */
    public static function razonSocialBrygar(string $nit): ?int
    {
        $nit = preg_replace('/\D/', '', $nit);
        if ($nit === '') {
            return null;
        }

        $id = DB::table('razones_sociales')
            ->where('aliado_id', X1PagosBrynex::ALIADO_BRYGAR)
            ->whereRaw("REPLACE(REPLACE(REPLACE(CAST(nit AS VARCHAR(30)), '.', ''), '-', ''), ' ', '') LIKE ?", [$nit.'%'])
            ->orderBy('id')
            ->value('id');

        return $id ? (int) $id : null;
    }

    private static function registroConjunto(int $rsBrygar, int $simple, int $mod, int $mes, int $anio): ?OperadorPlanillaApi
    {
        return OperadorPlanillaApi::where('aliado_id', X1PagosBrynex::ALIADO_BRYGAR)
            ->where('razon_social_id', $rsBrygar)
            ->where('operador_planilla_id', $simple)
            ->where('anio', $anio)->where('mes', $mes)
            ->where('n_plano', X1PagosBrynex::PLANO_CONJUNTO)
            ->where('paso', 1)
            ->where('estado', 'validada')
            ->where('tipos_modalidad', (string) $mod)
            ->whereNotNull('numero_planilla')
            ->latest('id')
            ->first();
    }

    /**
     * Entra a la conjunta: pendiente, o ya en la conjunta anterior
     * sin pagar (al rehacerla vuelve a ir). Las liquidadas en la tanda de su
     * aliado no: tienen su propia planilla.
     */
    private static function entraALaConjunta(object $f, ?OperadorPlanillaApi $registro): bool
    {
        // La verificación de la consignación NO frena: el dueño liquida y paga
        // primero y confirma las consignaciones después (15-nov-2026).
        return in_array($f->estado, self::ESTADOS_PARA_LIQUIDAR, true)
            || ($registro && $f->estado === 'liquidada' && $f->numero_planilla === $registro->numero_planilla);
    }

    private static function filasParaLiquidar(string $nit, int $mod, int $mes, int $anio, ?OperadorPlanillaApi $registro): Collection
    {
        return DB::table('x1_solicitud_planos AS xp')
            ->join('x1_solicitudes AS s', 's.id', '=', 'xp.x1_solicitud_id')
            ->join('razones_sociales AS rs', 'rs.id', '=', 'xp.razon_social_id')
            ->join('planos AS p', 'p.id', '=', 'xp.plano_id')
            ->where('s.mes', $mes)->where('s.anio', $anio)
            ->where('xp.tipo_modalidad_id', $mod)
            ->whereNull('p.deleted_at')
            ->get(['xp.*', 'rs.nit', 's.consignacion_verificada_at', 'p.no_identifi'])
            ->filter(fn ($f) => preg_replace('/\D/', '', (string) $f->nit) === $nit)
            ->filter(fn ($f) => self::entraALaConjunta($f, $registro))
            ->values();
    }

    /** Pasa a la cola las X1 de Brygar de esa empresa que todavía no estaban. */
    private static function colaBrygar(int $rsBrygar, int $mes, int $anio): void
    {
        $pendientes = X1PagosBrynex::pendientes(X1PagosBrynex::ALIADO_BRYGAR, $mes, $anio)
            ->where('razon_social_id', $rsBrygar);

        if ($pendientes->isNotEmpty()) {
            X1PagosBrynex::solicitar(X1PagosBrynex::ALIADO_BRYGAR, $mes, $anio, [$rsBrygar], null, Auth::id());
        }
    }

    /** Liquida por el camino de siempre, con el encabezado de Brygar y las personas de cada aliado. */
    private static function llamar(int $rsBrygar, int $simple, int $mod, int $mes, int $anio, Collection $filas, bool $reliquidar): array
    {
        $grupos = $filas->groupBy(fn ($f) => $f->aliado_id.'|'.$f->razon_social_id)
            ->map(fn (Collection $g) => [
                'aliado_id' => (int) $g->first()->aliado_id,
                'razon_social_id' => (int) $g->first()->razon_social_id,
                'plano_ids' => $g->pluck('plano_id')->map(fn ($id) => (int) $id)->all(),
            ])->values()->all();

        try {
            $resp = X1LiquidacionBrynex::comoAliado(X1PagosBrynex::ALIADO_BRYGAR, function () use ($rsBrygar, $simple, $mod, $mes, $anio, $grupos, $reliquidar) {
                $req = Request::create('/admin/planos/api-operador/liquidar', 'POST', array_filter([
                    'razon_social_id' => $rsBrygar,
                    'operador_planilla_id' => $simple,
                    'mes' => $mes,
                    'anio' => $anio,
                    'n_plano' => X1PagosBrynex::PLANO_CONJUNTO,
                    'tipos_modalidad' => [$mod],
                    'reliquidar' => $reliquidar ?: null,
                ], fn ($v) => $v !== null));
                $req->setLaravelSession(app('session.store'));
                $req->setUserResolver(fn () => Auth::user());

                return app(PlanillaApiController::class)->conCuentaBrynex()->conGruposX1($grupos)->liquidar($req);
            });
        } catch (\Throwable $e) {
            Log::error('x1-pagos: excepción en la planilla conjunta', ['rs' => $rsBrygar, 'error' => $e->getMessage()]);

            return ['liquidada' => false, 'http' => 500, 'numero' => null, 'valor' => null,
                'data' => ['message' => 'Error hablando con Simple: '.$e->getMessage()]];
        }

        $data = json_decode($resp->getContent(), true) ?: [];
        $numero = $data['numero_planilla'] ?? null;

        return [
            'liquidada' => (bool) (($data['success'] ?? false) && ($data['liquidada'] ?? false) && $numero),
            'http' => $resp->getStatusCode(),
            'numero' => $numero ? (string) $numero : null,
            'valor' => isset($data['valor_total']) ? (float) $data['valor_total'] : null,
            'data' => $data,
        ];
    }
}
