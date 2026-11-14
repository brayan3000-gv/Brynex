<?php

namespace App\Http\Controllers;

use App\Services\X1LiquidacionBrynex;
use App\Services\X1PagosBrynex;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * «Pagos X1» de BryNex: las solicitudes de pago de Tipo E - X1 de todos los
 * aliados, para liquidarlas en Simple con la cuenta de BryNex, pagarlas y
 * confirmarlas. Solo el superadmin de BryNex. La lógica está en
 * X1LiquidacionBrynex (operar) y X1PagosBrynex (solicitudes y admon).
 */
class BrynexX1PagosController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            abort_unless(X1LiquidacionBrynex::puedeOperar(Auth::user()), 403, 'Solo el superadmin de BryNex.');

            return $next($request);
        });
    }

    public function index(Request $request)
    {
        $mes = max(1, min(12, (int) $request->input('mes', now()->month)));
        $anio = max(2020, min(2100, (int) $request->input('anio', now()->year)));

        return view('brynex.x1-pagos.index', [
            'mes' => $mes,
            'anio' => $anio,
            'solicitudes' => X1LiquidacionBrynex::solicitudes($mes, $anio),
            'sinSolicitar' => X1PagosBrynex::pendientesPorAliado($mes, $anio),
            'admonAliados' => X1PagosBrynex::admonPorAliados(),
            'admonDefecto' => (int) \App\Models\ConfiguracionBrynex::obtener(X1PagosBrynex::CONFIG_ADMON, X1PagosBrynex::ADMON_POR_DEFECTO),
            'etiquetasEstado' => X1PagosBrynex::ETIQUETAS_ESTADO_PLANO,
            'activo' => X1PagosBrynex::activo(),
        ]);
    }

    public function liquidar(Request $request)
    {
        $v = $this->tanda($request);

        return response()->json(X1LiquidacionBrynex::liquidar(
            $v['aliado_id'], $v['razon_social_id'], $v['n_plano'], $v['tipo_modalidad_id'], $v['mes'], $v['anio']
        ));
    }

    /** Abre el pago PSE con un enlace recién pedido a Simple. */
    public function pagar(Request $request)
    {
        $v = $request->validate([
            'aliado_id' => 'required|integer',
            'numero' => 'required|string|max:30',
        ]);

        $pago = X1LiquidacionBrynex::urlPago((int) $v['aliado_id'], (string) $v['numero']);

        if (($pago['success'] ?? false) && ! empty($pago['url_pago'])) {
            return redirect()->away($pago['url_pago']);
        }

        return back()->with('warning', 'Simple no dio el enlace de pago de la planilla '.$v['numero'].': '
            .($pago['message'] ?? 'sin respuesta').'. Si es por el subtipo 4, solo lo entrega mientras la ventana esté abierta.');
    }

    public function confirmar(Request $request)
    {
        $v = $this->tanda($request, [
            'numero_planilla' => 'required|string|max:30',
            'estado' => 'required|in:pagada,doble_planilla,blindem',
        ]);

        return response()->json(X1LiquidacionBrynex::confirmar(
            $v['aliado_id'], $v['razon_social_id'], $v['n_plano'], $v['tipo_modalidad_id'],
            (string) $v['numero_planilla'], (string) $v['estado']
        ));
    }

    /** Brygar no consigna: sus X1 del período se pasan a la cola sin foto. */
    public function colaBrygar(Request $request)
    {
        $v = $request->validate([
            'mes' => 'required|integer|between:1,12',
            'anio' => 'required|integer|between:2020,2100',
        ]);

        $aliado = X1PagosBrynex::ALIADO_BRYGAR;
        $rs = X1PagosBrynex::pendientes($aliado, (int) $v['mes'], (int) $v['anio'])
            ->pluck('razon_social_id')->unique()->values()->all();

        $resultado = $rs
            ? X1PagosBrynex::solicitar($aliado, (int) $v['mes'], (int) $v['anio'], $rs, null, Auth::id())
            : ['ok' => false, 'mensaje' => 'Brygar no tiene X1 por pasar a la cola en ese período.'];

        return redirect()->route('brynex.x1.index', ['mes' => $v['mes'], 'anio' => $v['anio']])
            ->with($resultado['ok'] ? 'success' : 'warning', $resultado['mensaje']);
    }

    public function admon(Request $request)
    {
        $v = $request->validate([
            'por_defecto' => 'required|integer|min:0|max:1000000',
            'aliados' => 'nullable|array',
            'aliados.*' => 'nullable|integer|min:0|max:1000000',
        ]);

        X1PagosBrynex::guardarAdmon((int) $v['por_defecto'], $v['aliados'] ?? []);

        return back()->with('success', 'Administración X1 guardada.');
    }

    private function tanda(Request $request, array $extra = []): array
    {
        $v = $request->validate(array_merge([
            'aliado_id' => 'required|integer',
            'razon_social_id' => 'required|integer',
            'n_plano' => 'required|integer|min:1',
            'tipo_modalidad_id' => 'required|integer',
            'mes' => 'required|integer|between:1,12',
            'anio' => 'required|integer|between:2020,2100',
        ], $extra));

        foreach (['aliado_id', 'razon_social_id', 'n_plano', 'tipo_modalidad_id', 'mes', 'anio'] as $k) {
            $v[$k] = (int) $v[$k];
        }

        return $v;
    }
}
