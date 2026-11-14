<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\X1PagosBrynex;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Pagos de Tipo E - X1 por BryNex — el lado del aliado.
 *
 * Una sola pantalla con dos partes: las personas X1 que todavía no ha mandado
 * (por razón social, para marcar y solicitar con la foto de la consignación) y
 * las solicitudes que ya mandó, con el estado de cada persona. La lógica está
 * en X1PagosBrynex.
 */
class X1SolicitudController extends Controller
{
    public function index(Request $request)
    {
        $aliadoId = (int) session('aliado_id_activo');
        [$mes, $anio] = $this->periodo($request);

        $pendientes = X1PagosBrynex::pendientes($aliadoId, $mes, $anio);

        return view('admin.planos.x1.index', [
            'mes' => $mes,
            'anio' => $anio,
            'activo' => X1PagosBrynex::activo(),
            'esBrygar' => X1PagosBrynex::esBrygar($aliadoId),
            'admonPorPersona' => X1PagosBrynex::admonPorPersona($aliadoId),
            'resumen' => X1PagosBrynex::resumenPorRazonSocial($aliadoId, $mes, $anio, $pendientes),
            'solicitudes' => X1PagosBrynex::solicitudes($aliadoId),
            'etiquetasEstado' => X1PagosBrynex::ETIQUETAS_ESTADO_PLANO,
        ]);
    }

    public function solicitar(Request $request)
    {
        $aliadoId = (int) session('aliado_id_activo');

        if (! X1PagosBrynex::activo()) {
            return back()->with('warning', 'La solicitud de pagos X1 a BryNex está apagada.');
        }

        $validated = $request->validate([
            'mes' => 'required|integer|between:1,12',
            'anio' => 'required|integer|between:2020,2100',
            'razones_sociales' => 'required|array|min:1',
            'razones_sociales.*' => 'integer',
            'soporte' => 'nullable|file|mimes:jpg,jpeg,png,webp,heic,pdf|max:10240',
        ], [
            'razones_sociales.required' => 'Marque al menos una razón social.',
            'soporte.mimes' => 'La consignación tiene que ser una foto (JPG, PNG, WEBP) o un PDF.',
            'soporte.max' => 'La foto de la consignación no puede pasar de 10 MB.',
        ]);

        $resultado = X1PagosBrynex::solicitar(
            $aliadoId,
            (int) $validated['mes'],
            (int) $validated['anio'],
            $validated['razones_sociales'],
            $request->file('soporte'),
            Auth::id()
        );

        return redirect()
            ->route('admin.planos.x1.index', ['mes' => $validated['mes'], 'anio' => $validated['anio']])
            ->with($resultado['ok'] ? 'success' : 'warning', $resultado['mensaje']);
    }

    /** La foto de la consignación: la ve el aliado dueño o el superadmin de BryNex. */
    public function soporte(int $id)
    {
        $aliadoId = (int) session('aliado_id_activo');

        $solicitud = DB::table('x1_solicitudes')->where('id', $id)->first();
        if (! $solicitud || ! $solicitud->soporte_path) {
            abort(404);
        }

        if ((int) $solicitud->aliado_id !== $aliadoId && ! X1PagosBrynex::esSuperadminBrynex(Auth::user())) {
            abort(404);
        }

        if (! Storage::disk('local')->exists($solicitud->soporte_path)) {
            abort(404);
        }

        return Storage::disk('local')->response($solicitud->soporte_path, $solicitud->soporte_nombre ?: null);
    }

    /** Período de pago, como en la vista de planos (mes/anio). */
    private function periodo(Request $request): array
    {
        $mes = (int) $request->input('mes', now()->month);
        $anio = (int) $request->input('anio', now()->year);

        return [max(1, min(12, $mes)), max(2020, min(2100, $anio))];
    }
}
