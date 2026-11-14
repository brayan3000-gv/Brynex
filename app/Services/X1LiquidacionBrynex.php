<?php

namespace App\Services;

use App\Http\Controllers\Admin\PlanillaApiController;
use App\Models\OperadorPlanilla;
use App\Models\OperadorPlanillaApi;
use App\Models\TipoModalidad;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pagos de Tipo E - X1 por BryNex (fase 2: lo que hace BryNex).
 *
 * El superadmin de BryNex ve las solicitudes de todos los aliados y, por cada
 * tanda —razón social + plano X1 + modalidad—, liquida en Simple con la cuenta
 * de BryNex, paga y confirma con el número real de planilla.
 *
 * ## Los que rebotan por el subtipo 4
 *
 * Simple valida persona por persona. Si a unas les responde `eo.val.2.655`
 * (la UGPP no las deja ir sin pensión) y a otras no, las rebotadas pasan al
 * siguiente plano X1 en estado «esperando_ventana» y se vuelve a liquidar una
 * vez con las demás. Las que esperan se hacen cuando el vigía encuentre la
 * ventana, o por el plan B (doble planilla, Blindem), que decide una persona.
 * Si el rechazo trae cualquier otra regla, no se mueve a nadie: se muestra.
 *
 * ## Cómo llega al operador
 *
 * Por PlanillaApiController::liquidar, el mismo camino del botón de Planos y
 * del vigía —ahí viven el 409 que evita duplicar, el armado del TXT y el
 * registro—, con el aliado de la tanda puesto en la sesión mientras dura la
 * llamada (igual que el vigía) y `conCuentaBrynex()`. La sesión del usuario
 * vuelve a su aliado al terminar, pase lo que pase.
 */
class X1LiquidacionBrynex
{
    /** La regla con la que Simple rechaza el subtipo 4 cuando la UGPP lo valida. */
    public const REGLA_SUBTIPO = 'eo.val.2.655';

    private const OPERADOR_CODIGO = 'SIMPLE';

    public static function puedeOperar(?User $user): bool
    {
        return X1PagosBrynex::esSuperadminBrynex($user);
    }

    public static function operadorSimpleId(): ?int
    {
        $id = OperadorPlanilla::whereNull('aliado_id')->where('codigo', self::OPERADOR_CODIGO)->value('id');

        return $id ? (int) $id : null;
    }

    /**
     * Solicitudes del período (de pago) de todos los aliados, con sus tandas.
     *
     * @return Collection<int, object>
     */
    public static function solicitudes(int $mes, int $anio): Collection
    {
        $solicitudes = DB::table('x1_solicitudes AS s')
            ->join('aliados AS a', 'a.id', '=', 's.aliado_id')
            ->where('s.mes', $mes)->where('s.anio', $anio)
            ->orderByDesc('s.id')
            ->get(['s.*', 'a.nombre AS aliado_nombre']);

        if ($solicitudes->isEmpty()) {
            return $solicitudes;
        }

        $filas = DB::table('x1_solicitud_planos AS xp')
            ->join('planos AS p', 'p.id', '=', 'xp.plano_id')
            ->join('razones_sociales AS rs', 'rs.id', '=', 'xp.razon_social_id')
            ->whereIn('xp.x1_solicitud_id', $solicitudes->pluck('id')->all())
            ->orderBy('rs.razon_social')->orderBy('xp.n_plano')->orderBy('p.primer_ape')
            ->get([
                'xp.*', 'rs.razon_social', 'p.tipo_doc', 'p.no_identifi', 'p.primer_nombre', 'p.primer_ape',
                'p.numero_planilla AS planilla_plano',
            ]);

        $simple = self::operadorSimpleId();

        return $solicitudes->map(function ($s) use ($filas, $simple, $mes, $anio) {
            $s->tandas = $filas->where('x1_solicitud_id', $s->id)
                ->groupBy(fn ($f) => $f->razon_social_id.'|'.$f->n_plano.'|'.$f->tipo_modalidad_id)
                ->map(fn (Collection $g) => self::tanda($s, $g, $simple, $mes, $anio))
                ->values();

            return $s;
        });
    }

    private static function tanda(object $s, Collection $g, ?int $simple, int $mes, int $anio): object
    {
        $f = $g->first();
        $registro = $simple ? OperadorPlanillaApi::where('aliado_id', $s->aliado_id)
            ->where('razon_social_id', $f->razon_social_id)
            ->where('operador_planilla_id', $simple)
            ->where('anio', $anio)->where('mes', $mes)
            ->where('n_plano', $f->n_plano)
            ->where('paso', 1)
            ->where('estado', 'validada')
            ->where('tipos_modalidad', (string) $f->tipo_modalidad_id)
            ->latest('id')
            ->first(['numero_planilla', 'valor_total', 'updated_at']) : null;

        return (object) [
            'aliado_id' => (int) $s->aliado_id,
            'razon_social_id' => (int) $f->razon_social_id,
            'razon_social' => trim((string) $f->razon_social),
            'n_plano' => (int) $f->n_plano,
            'tipo_modalidad_id' => (int) $f->tipo_modalidad_id,
            'modalidad' => (int) $f->tipo_modalidad_id === TipoModalidad::ID_EX1_TP ? 'X1 TP14' : 'X1',
            'personas' => $g->count(),
            'valor_ss' => (int) $g->sum('valor_ss'),
            'estados' => $g->countBy('estado')->all(),
            'abierta' => $g->contains(fn ($x) => ! in_array($x->estado, X1PagosBrynex::ESTADOS_CERRADOS, true)),
            'registro' => $registro,
            'filas' => $g->values(),
        ];
    }

    /**
     * Liquida una tanda en Simple con la cuenta de BryNex.
     *
     * @return array{ok: bool, mensaje: string, numero_planilla?: string, valor_total?: float|null,
     *               movidas?: int, errores?: array}
     */
    public static function liquidar(int $aliadoId, int $rsId, int $nPlano, int $tipoModalidadId, int $mes, int $anio): array
    {
        if (! self::puedeOperar(Auth::user())) {
            return ['ok' => false, 'mensaje' => 'Solo el superadmin de BryNex liquida las X1.'];
        }

        if (! in_array($tipoModalidadId, TipoModalidad::IDS_EX1, true)) {
            return ['ok' => false, 'mensaje' => 'Esa tanda no es de Tipo E - X1.'];
        }

        $abiertas = self::filasAbiertas($aliadoId, $rsId, $nPlano, $tipoModalidadId);
        if ($abiertas->isEmpty()) {
            return ['ok' => false, 'mensaje' => 'Esa tanda no tiene personas pendientes.'];
        }

        $simple = self::operadorSimpleId();
        if (! $simple) {
            return ['ok' => false, 'mensaje' => 'No está el operador Simple en el catálogo.'];
        }

        $intento = self::llamarLiquidar($aliadoId, $rsId, $nPlano, $tipoModalidadId, $mes, $anio, $simple);
        $movidas = 0;

        // Rebotó por el subtipo 4 a una parte: esas esperan la ventana en otro
        // plano X1 y se liquida una vez más con el resto.
        if (! $intento['liquidada'] && $intento['http'] === 200) {
            $rebote = self::rebotadasPorSubtipo($intento['data']);

            if ($rebote['solo_subtipo'] && $rebote['documentos']) {
                $rebotadas = $abiertas->filter(fn ($f) => in_array(self::doc($f->no_identifi), $rebote['documentos'], true));

                if ($rebotadas->count() === $abiertas->count()) {
                    // Rebotaron todas: se quedan en su plano, esperando la ventana.
                    self::marcar($rebotadas->pluck('id')->all(), 'esperando_ventana');

                    return [
                        'ok' => false,
                        'mensaje' => 'Simple rechazó el subtipo 4 de toda la tanda ('.self::REGLA_SUBTIPO.'). Quedan «Esperando ventana» en el P'.$nPlano.'.',
                    ];
                }

                if ($rebotadas->isNotEmpty()) {
                    $destino = X1PagosBrynex::planoX1Libre($aliadoId, $rsId, $mes, $anio);
                    self::moverFilas($aliadoId, $rebotadas, $destino);
                    $movidas = $rebotadas->count();

                    $intento = self::llamarLiquidar($aliadoId, $rsId, $nPlano, $tipoModalidadId, $mes, $anio, $simple);
                }
            }
        }

        if ($intento['liquidada']) {
            $abiertas = self::filasAbiertas($aliadoId, $rsId, $nPlano, $tipoModalidadId);
            DB::table('x1_solicitud_planos')->whereIn('id', $abiertas->pluck('id')->all())
                ->update(['estado' => 'liquidada', 'numero_planilla' => $intento['numero'], 'updated_at' => now()]);
            self::recalcularSolicitudes($abiertas->pluck('x1_solicitud_id')->unique()->all());

            return [
                'ok' => true,
                'mensaje' => 'Planilla '.$intento['numero'].' liquidada'
                    .($intento['valor'] !== null ? ' por $'.number_format($intento['valor'], 0, ',', '.') : '')
                    .($movidas ? ". {$movidas} ".($movidas === 1 ? 'persona rebotó' : 'personas rebotaron').' por el subtipo 4 y quedaron esperando la ventana' : '')
                    .'. Falta pagarla y confirmar el pago.',
                'numero_planilla' => $intento['numero'],
                'valor_total' => $intento['valor'],
                'movidas' => $movidas,
            ];
        }

        $data = $intento['data'];

        // Ya estaba liquidada (el 409 del controlador): no se duplica en el
        // operador. Se dice el número para pagarla y confirmarla.
        if ($intento['http'] === 409) {
            return [
                'ok' => false,
                'mensaje' => 'Esta tanda ya tiene la planilla '.($data['numero_planilla'] ?? '?').' liquidada en Simple. Págala y confirma el pago; no se volvió a liquidar.',
            ];
        }

        $errores = collect(array_merge($data['errores_empresa'] ?? [], $data['errores_cotizante'] ?? []))
            ->map(fn ($e) => trim(($e['identificacion'] ?? '').' '.($e['idRegla'] ?? '').' '.($e['descripcion'] ?? '')))
            ->filter()->take(15)->values()->all();

        return [
            'ok' => false,
            'mensaje' => ($movidas ? "{$movidas} rebotaron por el subtipo 4 y quedaron esperando la ventana. " : '')
                .(string) ($data['message'] ?? 'Simple no liquidó la planilla.'),
            'errores' => $errores,
            'movidas' => $movidas,
        ];
    }

    /** Pide a Simple un enlace de pago nuevo para la planilla de la tanda. */
    public static function urlPago(int $aliadoId, string $numeroPlanilla): array
    {
        if (! self::puedeOperar(Auth::user())) {
            return ['success' => false, 'message' => 'Solo el superadmin de BryNex.'];
        }

        $registro = OperadorPlanillaApi::where('aliado_id', $aliadoId)
            ->where('numero_planilla', $numeroPlanilla)
            ->orderByDesc('updated_at')
            ->first();

        if (! $registro) {
            return ['success' => false, 'message' => 'No se encontró la planilla '.$numeroPlanilla.'.'];
        }

        return self::comoAliado($aliadoId, fn () => app(PlanillaApiController::class)
            ->conCuentaBrynex()
            ->renovarUrlPago($registro, $aliadoId));
    }

    /**
     * Confirma con el número real de planilla: lo graba en los planos de la
     * tanda y deja a cada persona en el estado de cierre.
     *
     * `$estado` es 'pagada' (Simple), 'doble_planilla' o 'blindem' (plan B,
     * que decide una persona). No crea gasto en el aliado: el aliado consignó
     * a Brygar y BryNex pagó; la contabilidad de eso va aparte.
     */
    public static function confirmar(int $aliadoId, int $rsId, int $nPlano, int $tipoModalidadId, string $numero, string $estado): array
    {
        if (! self::puedeOperar(Auth::user())) {
            return ['ok' => false, 'mensaje' => 'Solo el superadmin de BryNex confirma pagos X1.'];
        }

        if (! in_array($estado, X1PagosBrynex::ESTADOS_CERRADOS, true)) {
            return ['ok' => false, 'mensaje' => 'Estado de cierre no válido.'];
        }

        $numero = trim($numero);
        if ($numero === '' || ! preg_match('/^[0-9A-Za-z-]{4,30}$/', $numero)) {
            return ['ok' => false, 'mensaje' => 'Escriba el número de planilla real.'];
        }

        $filas = self::filasAbiertas($aliadoId, $rsId, $nPlano, $tipoModalidadId);
        if ($filas->isEmpty()) {
            return ['ok' => false, 'mensaje' => 'Esa tanda no tiene personas pendientes.'];
        }

        DB::transaction(function () use ($filas, $aliadoId, $numero, $estado) {
            DB::table('planos')->whereIn('id', $filas->pluck('plano_id')->all())
                ->where('aliado_id', $aliadoId)
                ->where(fn ($q) => $q->whereNull('numero_planilla')->orWhere('numero_planilla', ''))
                ->update(['numero_planilla' => $numero, 'updated_at' => now()]);

            DB::table('x1_solicitud_planos')->whereIn('id', $filas->pluck('id')->all())
                ->update(['estado' => $estado, 'numero_planilla' => $numero, 'updated_at' => now()]);
        });

        self::recalcularSolicitudes($filas->pluck('x1_solicitud_id')->unique()->all());

        Log::warning('x1-pagos: pago confirmado por BryNex', [
            'aliado' => $aliadoId, 'rs' => $rsId, 'n_plano' => $nPlano, 'planilla' => $numero,
            'estado' => $estado, 'personas' => $filas->count(), 'usuario' => Auth::id(),
        ]);

        return ['ok' => true, 'mensaje' => 'Planilla '.$numero.' confirmada para '.$filas->count().' '.($filas->count() === 1 ? 'persona' : 'personas').'.'];
    }

    /** Personas de la tanda que todavía no están cerradas. */
    private static function filasAbiertas(int $aliadoId, int $rsId, int $nPlano, int $tipoModalidadId): Collection
    {
        return DB::table('x1_solicitud_planos AS xp')
            ->join('planos AS p', 'p.id', '=', 'xp.plano_id')
            ->where('xp.aliado_id', $aliadoId)
            ->where('xp.razon_social_id', $rsId)
            ->where('xp.n_plano', $nPlano)
            ->where('xp.tipo_modalidad_id', $tipoModalidadId)
            ->whereNotIn('xp.estado', X1PagosBrynex::ESTADOS_CERRADOS)
            ->whereNull('p.deleted_at')
            ->get(['xp.*', 'p.no_identifi']);
    }

    private static function llamarLiquidar(int $aliadoId, int $rsId, int $nPlano, int $tipoModalidadId, int $mes, int $anio, int $simple): array
    {
        try {
            $resp = self::comoAliado($aliadoId, function () use ($rsId, $nPlano, $tipoModalidadId, $mes, $anio, $simple) {
                $req = Request::create('/admin/planos/api-operador/liquidar', 'POST', [
                    'razon_social_id' => $rsId,
                    'operador_planilla_id' => $simple,
                    'mes' => $mes,
                    'anio' => $anio,
                    'n_plano' => $nPlano,
                    'tipos_modalidad' => [$tipoModalidadId],
                ]);
                $req->setLaravelSession(app('session.store'));
                $req->setUserResolver(fn () => Auth::user());

                return app(PlanillaApiController::class)->conCuentaBrynex()->liquidar($req);
            });
        } catch (\Throwable $e) {
            Log::error('x1-pagos: excepción liquidando', ['aliado' => $aliadoId, 'rs' => $rsId, 'error' => $e->getMessage()]);

            return ['liquidada' => false, 'http' => 500, 'numero' => null, 'valor' => null,
                'data' => ['message' => 'Error hablando con Simple: '.$e->getMessage()]];
        }

        $data = json_decode($resp->getContent(), true) ?: [];
        $numero = $data['numero_planilla'] ?? null;
        $liquidada = ($data['success'] ?? false) && ($data['liquidada'] ?? false) && $numero;

        return [
            'liquidada' => (bool) $liquidada,
            'http' => $resp->getStatusCode(),
            'numero' => $numero ? (string) $numero : null,
            'valor' => isset($data['valor_total']) ? (float) $data['valor_total'] : null,
            'data' => $data,
        ];
    }

    /**
     * Quiénes rebotaron por el subtipo 4. `solo_subtipo` es falso si hay
     * cualquier otro error (de empresa o de cotizante): ahí no se mueve a
     * nadie, porque el problema es otro.
     */
    private static function rebotadasPorSubtipo(array $data): array
    {
        $empresa = $data['errores_empresa'] ?? [];
        $cotizante = collect($data['errores_cotizante'] ?? []);

        $otras = $cotizante->filter(fn ($e) => ($e['idRegla'] ?? '') !== self::REGLA_SUBTIPO);
        $documentos = $cotizante->filter(fn ($e) => ($e['idRegla'] ?? '') === self::REGLA_SUBTIPO)
            ->map(fn ($e) => self::doc((string) ($e['identificacion'] ?? '')))
            ->filter()->unique()->values()->all();

        return [
            'solo_subtipo' => empty($empresa) && $otras->isEmpty(),
            'documentos' => $documentos,
        ];
    }

    /** Solo los dígitos del documento ("CC1062304870" → "1062304870"). */
    private static function doc(string $identificacion): string
    {
        return ltrim(preg_replace('/\D/', '', $identificacion), '0');
    }

    private static function marcar(array $ids, string $estado): void
    {
        DB::table('x1_solicitud_planos')->whereIn('id', $ids)->update(['estado' => $estado, 'updated_at' => now()]);
    }

    /** Mueve personas a otro plano X1 (planos, facturas y x1_solicitud_planos) y las deja esperando. */
    private static function moverFilas(int $aliadoId, Collection $filas, int $destino): void
    {
        DB::transaction(function () use ($aliadoId, $filas, $destino) {
            $planoIds = $filas->pluck('plano_id')->all();
            $facturaIds = DB::table('planos')->whereIn('id', $planoIds)->where('aliado_id', $aliadoId)
                ->whereNotNull('factura_id')->pluck('factura_id')->all();

            DB::table('planos')->whereIn('id', $planoIds)->where('aliado_id', $aliadoId)
                ->update(['n_plano' => $destino, 'updated_at' => now()]);
            if ($facturaIds) {
                DB::table('facturas')->whereIn('id', $facturaIds)->where('aliado_id', $aliadoId)
                    ->whereNull('deleted_at')->update(['n_plano' => $destino]);
            }

            DB::table('x1_solicitud_planos')->whereIn('id', $filas->pluck('id')->all())
                ->update(['n_plano' => $destino, 'estado' => 'esperando_ventana', 'updated_at' => now()]);
        });
    }

    /** solicitada → en_proceso → parcial / pagada, según cómo van sus personas. */
    public static function recalcularSolicitudes(array $ids): void
    {
        foreach ($ids as $id) {
            $estados = DB::table('x1_solicitud_planos')->where('x1_solicitud_id', $id)->pluck('estado');
            if ($estados->isEmpty()) {
                continue;
            }

            $cerradas = $estados->filter(fn ($e) => in_array($e, X1PagosBrynex::ESTADOS_CERRADOS, true))->count();
            $nuevo = match (true) {
                $cerradas === $estados->count() => 'pagada',
                $cerradas > 0 => 'parcial',
                $estados->contains(fn ($e) => $e !== 'en_solicitud') => 'en_proceso',
                default => X1PagosBrynex::ESTADO_SOLICITADA,
            };

            DB::table('x1_solicitudes')->where('id', $id)->update(['estado' => $nuevo, 'updated_at' => now()]);
        }
    }

    /**
     * Corre `$fn` con el aliado de la tanda en la sesión y devuelve la sesión
     * a como estaba, aunque `$fn` falle: el superadmin sigue parado en su aliado.
     */
    private static function comoAliado(int $aliadoId, callable $fn)
    {
        $antes = session('aliado_id_activo');
        session(['aliado_id_activo' => $aliadoId]);

        try {
            return $fn();
        } finally {
            session(['aliado_id_activo' => $antes]);
        }
    }
}
