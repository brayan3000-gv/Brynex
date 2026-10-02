<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bitacora;
use App\Models\Contrato;
use App\Services\ArlColmena\ColmenaAfiliacionService;
use App\Services\ArlColmena\ColmenaProgreso;
use App\Services\ArlColmena\ColmenaSesionService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Afiliación a ARL Colmena desde BryNex, por el API de su Oficina Digital.
 *
 * Mismo guion que ARL Sura: `precheck` dice qué falta y qué se va a enviar sin
 * escribir nada, y `afiliar` ejecuta. La diferencia es cuándo se puede deshacer:
 * Colmena solo anula el ingreso hasta un día calendario después del inicio de la
 * vigencia; pasado eso, la salida es el retiro.
 *
 * El precheck sí toca el portal —los catálogos de EPS, AFP y centros de trabajo
 * viven allá, no en BryNex—, así que puede tardar lo que tarde abrir sesión.
 */
class ArlColmenaController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /** Qué se va a enviar, qué falta y qué hay hoy en Colmena. */
    public function precheck(Request $request, int $contratoId)
    {
        $this->sinLimiteDeTiempo();

        $contrato = $this->contrato($request, $contratoId);
        $cliente = $contrato->cliente;
        $rs = $contrato->razonSocial;

        // Sin clave en el módulo de claves no hay nada que consultar: se dice
        // eso y no se intenta abrir sesión, que tardaría dos minutos para nada.
        if (! ColmenaSesionService::credencialPara((string) $rs?->nit)) {
            return response()->json([
                'ok' => false,
                'problemas' => [],
                'requiere_credencial' => [
                    'nit' => $rs?->nit,
                    'razon_social' => $rs?->razon_social,
                ],
                'resumen' => $this->resumenBase($contrato, $cliente),
            ]);
        }

        $progreso = $request->query('progreso');

        try {
            $servicio = ColmenaAfiliacionService::paraContrato($contrato);
            ColmenaProgreso::paso($progreso, 'Iniciando sesión en el portal de Colmena');
            $servicio->api()->contrato();
            ColmenaProgreso::paso($progreso, 'Revisando los datos del trabajador');
            $problemas = $servicio->builder()->problemas($contrato);
            ColmenaProgreso::paso($progreso, 'Consultando el centro de trabajo');
            $centro = $problemas ? null : $servicio->builder()->centro($contrato);
            ColmenaProgreso::paso($progreso, 'Consultando si ya está afiliado en Colmena');
            $cobertura = $servicio->coberturaEnColmena($contrato);
            ColmenaProgreso::terminar($progreso);
        } catch (Throwable $e) {
            ColmenaProgreso::terminar($progreso);
            Log::warning('ARL Colmena: precheck falló', ['contrato' => $contrato->id, 'error' => $e->getMessage()]);

            return response()->json([
                'ok' => false,
                'problemas' => [$e->getMessage()],
                'resumen' => $this->resumenBase($contrato, $cliente),
            ]);
        }

        return response()->json([
            'ok' => empty($problemas),
            'problemas' => $problemas,
            'resumen' => $this->resumenBase($contrato, $cliente) + [
                'contrato_colmena' => $servicio->api()->contrato(),
                'centro' => $centro ? trim($centro['name']).' · riesgo '.$centro['riskClass'].' · tasa '.$centro['riskRate'] : null,
            ],
            // Lo primero que Colmena acepta: su calendario no habilita hoy.
            'fecha_sugerida' => now()->addDay()->toDateString(),
            'cobertura' => $cobertura ? [
                'desde' => substr((string) ($cobertura['initEffectiveDate'] ?? ''), 0, 10),
                'hasta' => substr((string) ($cobertura['endEffectiveDate'] ?? ''), 0, 10),
                'vigente' => $servicio->estaVigente($cobertura),
                'centro' => $cobertura['headquarterId'] ?? null,
                'se_puede_anular' => $this->sePuedeAnular($cobertura),
            ] : null,
        ]);
    }

    /** Lo que lleva hecho el robot, para el contador de la pantalla. */
    public function progreso(string $id)
    {
        return response()->json(ColmenaProgreso::leer($id));
    }

    /** Radica el ingreso. */
    public function afiliar(Request $request, int $contratoId)
    {
        $this->sinLimiteDeTiempo();

        $contrato = $this->contrato($request, $contratoId);
        $datos = $request->validate(['fecha_inicio_cobertura' => 'required|date']);

        $progreso = $request->input('progreso');

        try {
            ColmenaProgreso::paso($progreso, 'Iniciando sesión en el portal de Colmena');
            $servicio = ColmenaAfiliacionService::paraContrato($contrato);
            $servicio->api()->contrato();
            $afiliacion = $servicio->conAvance(fn (string $p) => ColmenaProgreso::paso($progreso, $p))->afiliar(
                $contrato,
                Carbon::parse($datos['fecha_inicio_cobertura']),
                Auth::id(),
                $request->input('arl_anterior'),
            );
        } catch (Throwable $e) {
            ColmenaProgreso::terminar($progreso);

            return response()->json(['ok' => false, 'mensaje' => $e->getMessage()], 422);
        }

        ColmenaProgreso::terminar($progreso);

        Bitacora::registrar(
            'created',
            'Contrato',
            $contrato->id,
            "Afiliación ARL Colmena: radicación {$afiliacion->codigo_transaccion}, vigencia desde ".
                $afiliacion->fecha_inicio_cobertura->format('d/m/Y'),
            ['arl_afiliacion_id' => $afiliacion->id],
            (int) $contrato->aliado_id
        );

        return response()->json([
            'ok' => true,
            'mensaje' => 'Trabajador afiliado en ARL Colmena.',
            'codigo_transaccion' => $afiliacion->codigo_transaccion,
            'fecha_display' => $afiliacion->fecha_inicio_cobertura->format('d/m/Y'),
            'aviso' => $afiliacion->mensaje_error,
        ]);
    }

    /** Anula el ingreso: la vigencia desaparece y no se paga ese día. */
    public function anular(Request $request, int $contratoId)
    {
        $this->sinLimiteDeTiempo();

        $contrato = $this->contrato($request, $contratoId);

        try {
            $anulacion = ColmenaAfiliacionService::paraContrato($contrato)->anular($contrato, Auth::id());
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'mensaje' => $e->getMessage()], 422);
        }

        Bitacora::registrar(
            'updated',
            'Contrato',
            $contrato->id,
            'Anulación del ingreso en ARL Colmena'.
                ($anulacion->codigo_transaccion ? " (radicación {$anulacion->codigo_transaccion})" : ''),
            ['arl_afiliacion_id' => $anulacion->id],
            (int) $contrato->aliado_id
        );

        return response()->json([
            'ok' => true,
            'mensaje' => 'Ingreso anulado en ARL Colmena. El radicado vuelve a pendiente.',
        ]);
    }

    /** Radica el retiro con la fecha indicada. */
    public function retirar(Request $request, int $contratoId)
    {
        $this->sinLimiteDeTiempo();

        $contrato = $this->contrato($request, $contratoId);
        $datos = $request->validate(['fecha_retiro' => 'required|date']);

        try {
            $retiro = ColmenaAfiliacionService::paraContrato($contrato)->retirar(
                $contrato,
                Carbon::parse($datos['fecha_retiro']),
                Auth::id()
            );
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'mensaje' => $e->getMessage()], 422);
        }

        Bitacora::registrar(
            'updated',
            'Contrato',
            $contrato->id,
            'Retiro en ARL Colmena el '.$retiro->fecha_fin_cobertura->format('d/m/Y').
                ($retiro->codigo_transaccion ? " (radicación {$retiro->codigo_transaccion})" : ''),
            ['arl_afiliacion_id' => $retiro->id],
            (int) $contrato->aliado_id
        );

        return response()->json([
            'ok' => true,
            'mensaje' => 'Retiro radicado en ARL Colmena.',
        ]);
    }

    // ─── Apoyo ───────────────────────────────────────────────────────

    /**
     * Colmena no dice el plazo en la respuesta: lo impone al radicar ("un día
     * calendario después de la fecha de inicio de vigencia"). Se calcula igual
     * aquí para no ofrecer un botón que va a rebotar.
     */
    private function sePuedeAnular(?array $cobertura): bool
    {
        $desde = $cobertura['initEffectiveDate'] ?? null;

        if (! $desde) {
            return false;
        }

        return Carbon::parse($desde)->addDay()->startOfDay()->gte(now()->startOfDay());
    }

    private function resumenBase(Contrato $contrato, $cliente): array
    {
        return [
            'trabajador' => trim(collect([
                $cliente?->primer_nombre, $cliente?->segundo_nombre,
                $cliente?->primer_apellido, $cliente?->segundo_apellido,
            ])->filter()->implode(' ')),
            'documento' => $contrato->cedula,
            'razon_social' => $contrato->razonSocial?->razon_social,
            'eps' => ($contrato->eps ?: $cliente?->eps)?->razon_social,
            'afp' => ($contrato->pension ?: $cliente?->pension)?->razon_social,
            'ibc' => (int) ($contrato->ibc ?: $contrato->salario),
            'cargo' => $contrato->cargo
                ?: optional(\App\Models\RazonSocialCargo::porDefecto(
                    (int) $contrato->razon_social_id,
                    (int) $contrato->n_arl
                ))->cargo.($contrato->cargo ? '' : ' (por defecto)'),
            'nivel_riesgo' => $contrato->n_arl,
        ];
    }

    /**
     * El cliente se carga perezoso a propósito: con `with()` la relación no
     * filtra por aliado y trae la ficha de otra empresa. Ver el mismo comentario
     * en ArlAfiliacionController.
     */
    private function contrato(Request $request, int $id): Contrato
    {
        // `paraTramite` valida el permiso por contrato: BryNex puede abrir el de
        // cualquier aliado que gestione aunque no sea el activo en la sesión
        // (la vista de «gestionados» los lista todos). Filtrar por el aliado
        // activo daba un 404 y el modal quedaba lleno de «undefined».
        // `cliente` se deja perezoso a propósito: con `with()` se pierde el
        // filtro por aliado y trae la ficha de otro.
        return Contrato::paraTramite($id)
            ->loadMissing(['razonSocial', 'eps', 'pension', 'tipoModalidad', 'aliado']);
    }

    /** Abrir sesión en Colmena levanta un navegador: no cabe en los 30 s de PHP. */
    private function sinLimiteDeTiempo(): void
    {
        @set_time_limit(300);
        @ini_set('max_execution_time', '300');
    }
}
