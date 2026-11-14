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
            ->leftJoin('facturas AS f', function ($j) {
                $j->on('f.id', '=', 'p.factura_id')->on('f.aliado_id', '=', 'p.aliado_id');
            })
            ->whereIn('xp.x1_solicitud_id', $solicitudes->pluck('id')->all())
            ->orderBy('rs.razon_social')->orderBy('xp.n_plano')->orderBy('p.primer_ape')
            ->get([
                'xp.*', 'rs.razon_social', 'p.tipo_doc', 'p.no_identifi', 'p.primer_nombre', 'p.primer_ape',
                'p.num_dias', 'p.numero_planilla AS planilla_plano',
                'f.v_eps', 'f.v_arl', 'f.v_caja',
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

        // Una planilla de este mismo plano liquidada con otro filtro (por el
        // aliado, antes de que la X1 pasara a BryNex): se avisa, porque volver
        // a liquidar podría duplicar gente en Simple. Ver liquidar().
        $otra = $simple ? OperadorPlanillaApi::where('aliado_id', $s->aliado_id)
            ->where('razon_social_id', $f->razon_social_id)
            ->where('operador_planilla_id', $simple)
            ->where('anio', $anio)->where('mes', $mes)
            ->where('n_plano', $f->n_plano)
            ->where('paso', 1)
            ->where('estado', 'validada')
            ->whereNotNull('numero_planilla')
            ->whereRaw("ISNULL(tipos_modalidad, '') <> ?", [(string) $f->tipo_modalidad_id])
            ->latest('id')
            ->first(['numero_planilla', 'valor_total', 'created_at']) : null;

        $pagoIds = $g->pluck('x1_pago_id')->filter()->unique()->values()->all();
        $pagos = $pagoIds
            ? DB::table('x1_pagos')->whereIn('id', $pagoIds)->orderBy('id')->get()
            : collect();

        return (object) [
            'otra_planilla' => $otra,
            'pagos' => $pagos,
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
     * `$moverRebotados` en falso es el modo del vigía: dentro de una ventana
     * no se mueve a nadie —si alguien rebota es que la ventana ya se cerró, y
     * moverlo solo lo pasearía de plano en plano—.
     *
     * `estado`: liquidada | ya (409) | esperando (rebotó por el subtipo 4) |
     * rechazada (otra regla) | transitorio (Simple no contestó bien) | error.
     *
     * @return array{ok: bool, estado: string, mensaje: string, numero_planilla?: string,
     *               valor_total?: float|null, movidas?: int, errores?: array}
     */
    public static function liquidar(int $aliadoId, int $rsId, int $nPlano, int $tipoModalidadId, int $mes, int $anio, bool $moverRebotados = true, bool $ignorarOtra = false, bool $revisarPagada = true): array
    {
        if (! self::puedeOperar(Auth::user())) {
            return ['ok' => false, 'estado' => 'error', 'mensaje' => 'Solo el superadmin de BryNex liquida las X1.'];
        }

        if (! in_array($tipoModalidadId, TipoModalidad::IDS_EX1, true)) {
            return ['ok' => false, 'estado' => 'error', 'mensaje' => 'Esa tanda no es de Tipo E - X1.'];
        }

        $abiertas = self::filasAbiertas($aliadoId, $rsId, $nPlano, $tipoModalidadId);
        if ($abiertas->isEmpty()) {
            return ['ok' => false, 'estado' => 'error', 'mensaje' => 'Esa tanda no tiene personas pendientes.'];
        }

        $simple = self::operadorSimpleId();
        if (! $simple) {
            return ['ok' => false, 'estado' => 'error', 'mensaje' => 'No está el operador Simple en el catálogo.'];
        }

        // Freno: el plano ya tiene una planilla liquidada en Simple con OTRO
        // filtro (p. ej. «Todos», liquidada por el aliado antes de que la X1
        // pasara a BryNex). El 409 del controlador solo mira el mismo filtro,
        // así que liquidar aquí podría duplicar a esas personas en Simple.
        $otra = OperadorPlanillaApi::where('aliado_id', $aliadoId)
            ->where('razon_social_id', $rsId)
            ->where('operador_planilla_id', $simple)
            ->where('anio', $anio)->where('mes', $mes)
            ->where('n_plano', $nPlano)
            ->where('paso', 1)
            ->where('estado', 'validada')
            ->whereNotNull('numero_planilla')
            ->whereRaw("ISNULL(tipos_modalidad, '') <> ?", [(string) $tipoModalidadId])
            ->latest('id')
            ->first();

        // Quien la revisó en Simple y vio que NO se pagó puede seguir
        // (`$ignorarOtra`, solo desde la vista; el vigía nunca lo hace).
        if ($otra && ! $ignorarOtra) {
            return [
                'ok' => false,
                'estado' => 'rechazada',
                'otra_planilla' => (string) $otra->numero_planilla,
                'mensaje' => "Este plano ya tiene la planilla {$otra->numero_planilla} liquidada en Simple"
                    .($otra->valor_total ? ' por $'.number_format((float) $otra->valor_total, 0, ',', '.') : '')
                    .' el '.optional($otra->created_at)->format('d/m H:i')
                    .' (no por BryNex). Revise en Simple si ya se pagó antes de volver a liquidar: podría duplicar a esas personas.',
            ];
        }

        if ($otra) {
            Log::warning('x1-pagos: se liquida pese a otra planilla en el plano (revisada: sin pagar)', [
                'aliado' => $aliadoId, 'rs' => $rsId, 'n_plano' => $nPlano,
                'otra' => $otra->numero_planilla, 'usuario' => Auth::id(),
            ]);
        }

        $intento = self::llamarLiquidar($aliadoId, $rsId, $nPlano, $tipoModalidadId, $mes, $anio, $simple);
        $movidas = 0;
        $reliquidando = false;

        // ── Ya estaba liquidada con este mismo filtro: ¿se pagó? ──────────
        // Se le pregunta a Simple. Pagada → no se toca, se confirma. Vigente
        // → su enlace de pago. Sin pagar y sin enlace (la liquidaron en una
        // ventana que ya se cerró: Simple revalida el subtipo 4 al dar el
        // enlace y dice «contiene errores») → se liquida una nueva, que es la
        // única forma de sacar un enlace. Si no se sabe (pago en tránsito, red
        // caída) no se toca: liquidar otra podría pagarla dos veces.
        if ($revisarPagada && $intento['http'] === 409 && ($intento['data']['reemplaza'] ?? false)
            && ! empty($intento['data']['numero_planilla'])) {
            $previa = (string) $intento['data']['numero_planilla'];
            $pago = self::estadoPago($aliadoId, $previa);
            $mensajeOperador = (string) ($pago['mensaje'] ?? $pago['message'] ?? '');

            if (($pago['success'] ?? false) && ($pago['pagada'] ?? null) === true) {
                self::marcarLiquidada($aliadoId, $rsId, $nPlano, $tipoModalidadId, $previa);

                return [
                    'ok' => false,
                    'estado' => 'ya',
                    'numero_planilla' => $previa,
                    'mensaje' => "Simple dice que la planilla {$previa} ya está pagada. No se volvió a liquidar: confírmala con su comprobante.",
                ];
            }

            if (! empty($pago['url_pago'])) {
                self::marcarLiquidada($aliadoId, $rsId, $nPlano, $tipoModalidadId, $previa);

                return [
                    'ok' => true,
                    'estado' => 'liquidada',
                    'numero_planilla' => $previa,
                    'valor_total' => isset($pago['total_pagar']) ? (float) $pago['total_pagar'] : null,
                    'url_pago' => $pago['url_pago'],
                    'mensaje' => "La planilla {$previa} sigue vigente y sin pagar: Simple dio el enlace de pago. Págala y confirma.",
                ];
            }

            $sinPagar = ($pago['success'] ?? false) && ($pago['pagada'] ?? null) === false;
            $conErrores = str_contains(mb_strtolower($mensajeOperador), 'errores');

            if (! $sinPagar && ! $conErrores) {
                return [
                    'ok' => false,
                    'estado' => 'ya',
                    'numero_planilla' => $previa,
                    'mensaje' => "No se pudo saber si la planilla {$previa} está pagada"
                        .($mensajeOperador !== '' ? " (Simple: {$mensajeOperador})" : '')
                        .'. No se volvió a liquidar para no pagarla dos veces. Revísela en Simple.',
                ];
            }

            Log::warning('x1-pagos: se reliquida una tanda X1 cuya planilla quedó sin pagar', [
                'aliado' => $aliadoId, 'rs' => $rsId, 'n_plano' => $nPlano,
                'anterior' => $previa, 'simple' => $mensajeOperador, 'usuario' => Auth::id(),
            ]);

            $reliquidando = true;
            $intento = self::llamarLiquidar($aliadoId, $rsId, $nPlano, $tipoModalidadId, $mes, $anio, $simple, true);
        }

        // Rebotó por el subtipo 4 a una parte: esas esperan la ventana en otro
        // plano X1 y se liquida una vez más con el resto.
        if (! $intento['liquidada'] && $intento['http'] === 200) {
            $rebote = self::rebotadasPorSubtipo($intento['data']);

            if ($rebote['solo_subtipo'] && $rebote['documentos']) {
                $rebotadas = $abiertas->filter(fn ($f) => in_array(self::doc($f->no_identifi), $rebote['documentos'], true));

                if ($rebotadas->count() === $abiertas->count() || ! $moverRebotados) {
                    // Rebotaron todas: se quedan en su plano, esperando la ventana.
                    self::marcar($rebotadas->pluck('id')->all(), 'esperando_ventana');

                    return [
                        'ok' => false,
                        'estado' => 'esperando',
                        'mensaje' => 'Simple rechazó el subtipo 4 ('.self::REGLA_SUBTIPO.') a '
                            .($rebotadas->count() === $abiertas->count() ? 'toda la tanda' : $rebotadas->count().' de '.$abiertas->count())
                            .'. Quedan «Esperando ventana» en el P'.$nPlano.'.',
                    ];
                }

                if ($rebotadas->isNotEmpty()) {
                    $destino = X1PagosBrynex::planoX1Libre($aliadoId, $rsId, $mes, $anio);
                    self::moverFilas($aliadoId, $rebotadas, $destino);
                    $movidas = $rebotadas->count();

                    $intento = self::llamarLiquidar($aliadoId, $rsId, $nPlano, $tipoModalidadId, $mes, $anio, $simple, $reliquidando);
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
                'estado' => 'liquidada',
                'url_pago' => $intento['data']['url_pago'] ?? null,
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
            // Esa planilla cubre a la tanda (mismo filtro): sus personas quedan
            // «Liquidada, por pagar» con ese número, para pagarla y confirmarla.
            if (! empty($data['numero_planilla']) && ($data['reemplaza'] ?? false)) {
                $abiertas = self::filasAbiertas($aliadoId, $rsId, $nPlano, $tipoModalidadId);
                DB::table('x1_solicitud_planos')->whereIn('id', $abiertas->pluck('id')->all())
                    ->update(['estado' => 'liquidada', 'numero_planilla' => (string) $data['numero_planilla'], 'updated_at' => now()]);
                self::recalcularSolicitudes($abiertas->pluck('x1_solicitud_id')->unique()->all());
            }

            return [
                'ok' => false,
                'estado' => 'ya',
                'numero_planilla' => isset($data['numero_planilla']) ? (string) $data['numero_planilla'] : null,
                'mensaje' => 'Esta tanda ya tiene la planilla '.($data['numero_planilla'] ?? '?').' liquidada en Simple. Págala y confirma el pago; no se volvió a liquidar.',
            ];
        }

        $errores = collect(array_merge($data['errores_empresa'] ?? [], $data['errores_cotizante'] ?? []))
            ->map(fn ($e) => trim(($e['identificacion'] ?? '').' '.($e['idRegla'] ?? '').' '.($e['descripcion'] ?? '')))
            ->filter()->take(15)->values()->all();

        // Con reglas, el validador respondió y rechazó; sin ellas, Simple
        // tropezó (cURL 52, 5xx…) y vale la pena reintentar.
        $conReglas = collect(array_merge($data['errores_empresa'] ?? [], $data['errores_cotizante'] ?? []))
            ->pluck('idRegla')->filter()->isNotEmpty();

        return [
            'ok' => false,
            'estado' => $conReglas ? 'rechazada' : 'transitorio',
            'mensaje' => ($movidas ? "{$movidas} rebotaron por el subtipo 4 y quedaron esperando la ventana. " : '')
                .(string) ($data['message'] ?? 'Simple no liquidó la planilla.'),
            'errores' => $errores,
            'movidas' => $movidas,
        ];
    }

    /**
     * El vigía (consola, sin usuario) liquida una tanda X1 que esperaba la
     * ventana. Entra como el usuario de BryNex de los comandos (el 2, ver
     * CONFIG_USUARIO) para que la cuenta de Simple sea la de BryNex.
     *
     * Devuelve lo que entiende PlanillaVigilarSubtipo4::intentarLiquidar:
     * [estado, texto, planilla, valor] con estado liquidada | ya | rechazada |
     * transitorio.
     */
    public static function paraVigia(int $aliadoId, int $rsId, int $nPlano, int $tipoModalidadId, int $mes, int $anio): array
    {
        return self::comoUsuarioBrynex(function () use ($aliadoId, $rsId, $nPlano, $tipoModalidadId, $mes, $anio) {
            $r = self::liquidar($aliadoId, $rsId, $nPlano, $tipoModalidadId, $mes, $anio, false, false, false);

            return match ($r['estado']) {
                'liquidada' => ['liquidada', 'planilla '.$r['numero_planilla'], (string) $r['numero_planilla'], $r['valor_total'] ?? null],
                'ya' => ['ya', 'ya tenía planilla '.($r['numero_planilla'] ?? '').' (409), no se tocó'],
                'transitorio' => ['transitorio', $r['mensaje']],
                default => ['rechazada', $r['mensaje']],
            };
        });
    }

    /** Enlace de pago nuevo desde el vigía, con la cuenta de BryNex. */
    public static function urlPagoParaVigia(int $aliadoId, string $numeroPlanilla): array
    {
        return self::comoUsuarioBrynex(fn () => self::urlPago($aliadoId, $numeroPlanilla));
    }

    /** Usuario con el que el vigía opera los pagos X1 (superadmin de BryNex). */
    public const CONFIG_USUARIO = 'x1_usuario_vigia';

    private static function comoUsuarioBrynex(callable $fn)
    {
        $antes = Auth::user();
        if (! self::puedeOperar($antes)) {
            $usuario = User::find((int) \App\Models\ConfiguracionBrynex::obtener(self::CONFIG_USUARIO, 2));
            if (! self::puedeOperar($usuario)) {
                throw new \RuntimeException('El usuario de los pagos X1 del vigía no es superadmin de BryNex.');
            }
            Auth::setUser($usuario);
        }

        try {
            return $fn();
        } finally {
            if ($antes) {
                Auth::setUser($antes);
            } else {
                Auth::forgetUser();
            }
        }
    }

    /**
     * Tandas X1 que esperan la ventana o que se liquidaron y no se han pagado,
     * de todos los aliados, para el vigía. Mismo formato que sus tandas.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function tandasParaVigia(int $mes, int $anio): array
    {
        $nombres = [TipoModalidad::ID_EX1 => 'X1', TipoModalidad::ID_EX1_TP => 'X1 TP14'];

        return DB::table('x1_solicitud_planos AS xp')
            ->join('x1_solicitudes AS s', 's.id', '=', 'xp.x1_solicitud_id')
            ->join('razones_sociales AS rs', 'rs.id', '=', 'xp.razon_social_id')
            ->join('aliados AS a', 'a.id', '=', 'xp.aliado_id')
            ->where('s.mes', $mes)->where('s.anio', $anio)
            ->whereIn('xp.estado', ['esperando_ventana', 'liquidada'])
            ->groupBy('xp.aliado_id', 'a.nombre', 'xp.razon_social_id', 'rs.razon_social', 'xp.n_plano', 'xp.tipo_modalidad_id')
            ->orderBy('xp.aliado_id')->orderBy('xp.razon_social_id')->orderBy('xp.n_plano')
            ->selectRaw('xp.aliado_id, a.nombre AS aliado, xp.razon_social_id, rs.razon_social, xp.n_plano, xp.tipo_modalidad_id, COUNT(*) AS personas')
            ->get()
            ->map(fn ($g) => [
                'etiqueta' => sprintf('%s · %s · plano %d · %s (%d %s)',
                    mb_strimwidth(trim((string) $g->aliado), 0, 20, '…'),
                    mb_strimwidth(trim((string) $g->razon_social), 0, 34, '…'),
                    (int) $g->n_plano,
                    $nombres[(int) $g->tipo_modalidad_id] ?? $g->tipo_modalidad_id,
                    (int) $g->personas,
                    (int) $g->personas === 1 ? 'persona' : 'personas'),
                'aliado' => (int) $g->aliado_id,
                'rs' => (int) $g->razon_social_id,
                'mes' => $mes,
                'anio' => $anio,
                'n_plano' => (int) $g->n_plano,
                'mods' => [(int) $g->tipo_modalidad_id],
                'brynex' => true,
            ])
            ->all();
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
    public static function confirmar(
        int $aliadoId,
        int $rsId,
        int $nPlano,
        int $tipoModalidadId,
        string $numero,
        string $estado,
        int $valor = 0,
        ?string $comprobante = null,
        ?string $fechaPago = null,
        ?\Illuminate\Http\UploadedFile $soporte = null,
        ?string $observacion = null
    ): array {
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

        if ($valor <= 0) {
            return ['ok' => false, 'mensaje' => 'Escriba el valor pagado.'];
        }

        $filas = self::filasAbiertas($aliadoId, $rsId, $nPlano, $tipoModalidadId);
        if ($filas->isEmpty()) {
            return ['ok' => false, 'mensaje' => 'Esa tanda no tiene personas pendientes.'];
        }

        // El comprobante va al disco local (storage/app): es un soporte bancario.
        $soportePath = $soporte
            ? $soporte->store("x1-pagos/{$aliadoId}/".now()->format('Y-m'), 'local')
            : null;

        try {
            $pagoId = DB::transaction(function () use ($filas, $aliadoId, $rsId, $nPlano, $tipoModalidadId, $numero, $estado, $valor, $comprobante, $fechaPago, $soporte, $soportePath, $observacion) {
                $pagoId = DB::table('x1_pagos')->insertGetId([
                    'aliado_id' => $aliadoId,
                    'razon_social_id' => $rsId,
                    'n_plano' => $nPlano,
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

                DB::table('planos')->whereIn('id', $filas->pluck('plano_id')->all())
                    ->where('aliado_id', $aliadoId)
                    ->where(fn ($q) => $q->whereNull('numero_planilla')->orWhere('numero_planilla', ''))
                    ->update(['numero_planilla' => $numero, 'updated_at' => now()]);

                DB::table('x1_solicitud_planos')->whereIn('id', $filas->pluck('id')->all())
                    ->update(['estado' => $estado, 'numero_planilla' => $numero, 'x1_pago_id' => $pagoId, 'updated_at' => now()]);

                return $pagoId;
            });
        } catch (\Throwable $e) {
            if ($soportePath) {
                \Illuminate\Support\Facades\Storage::disk('local')->delete($soportePath);
            }
            throw $e;
        }

        self::recalcularSolicitudes($filas->pluck('x1_solicitud_id')->unique()->all());

        Log::warning('x1-pagos: pago confirmado por BryNex', [
            'pago' => $pagoId, 'aliado' => $aliadoId, 'rs' => $rsId, 'n_plano' => $nPlano, 'planilla' => $numero,
            'estado' => $estado, 'valor' => $valor, 'personas' => $filas->count(), 'usuario' => Auth::id(),
        ]);

        return ['ok' => true, 'mensaje' => 'Planilla '.$numero.' confirmada para '.$filas->count().' '.($filas->count() === 1 ? 'persona' : 'personas')
            .' por $'.number_format($valor, 0, ',', '.').'.'];
    }

    /** Estado de pago de una planilla del aliado, preguntado con la cuenta de BryNex. */
    private static function estadoPago(int $aliadoId, string $numero): array
    {
        $registro = OperadorPlanillaApi::where('aliado_id', $aliadoId)
            ->where('numero_planilla', $numero)
            ->orderByDesc('updated_at')
            ->first();

        if (! $registro) {
            return ['success' => false, 'message' => 'No se encontró la planilla '.$numero.'.'];
        }

        return self::comoAliado($aliadoId, fn () => app(PlanillaApiController::class)
            ->conCuentaBrynex()
            ->estadoPagoRegistro($registro, $aliadoId));
    }

    /** Las personas abiertas de la tanda quedan «Liquidada, por pagar» con ese número. */
    private static function marcarLiquidada(int $aliadoId, int $rsId, int $nPlano, int $tipoModalidadId, string $numero): void
    {
        $abiertas = self::filasAbiertas($aliadoId, $rsId, $nPlano, $tipoModalidadId);
        DB::table('x1_solicitud_planos')->whereIn('id', $abiertas->pluck('id')->all())
            ->update(['estado' => 'liquidada', 'numero_planilla' => $numero, 'updated_at' => now()]);
        self::recalcularSolicitudes($abiertas->pluck('x1_solicitud_id')->unique()->all());
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

    private static function llamarLiquidar(int $aliadoId, int $rsId, int $nPlano, int $tipoModalidadId, int $mes, int $anio, int $simple, bool $reliquidar = false): array
    {
        try {
            $resp = self::comoAliado($aliadoId, function () use ($rsId, $nPlano, $tipoModalidadId, $mes, $anio, $simple, $reliquidar) {
                $req = Request::create('/admin/planos/api-operador/liquidar', 'POST', array_filter([
                    'razon_social_id' => $rsId,
                    'operador_planilla_id' => $simple,
                    'mes' => $mes,
                    'anio' => $anio,
                    'n_plano' => $nPlano,
                    'tipos_modalidad' => [$tipoModalidadId],
                    'reliquidar' => $reliquidar ?: null,
                ], fn ($v) => $v !== null));
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
