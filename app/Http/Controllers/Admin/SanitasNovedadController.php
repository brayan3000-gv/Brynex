<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contrato;
use App\Services\Sanitas\SanitasNovedadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Novedad de cambio de empleador en Sanitas desde el radicado de EPS. El
 * formulario web lo llena la extensión BryNex Portales; aquí `precheck` arma los
 * datos, `formulario` entrega el PDF para adjuntar y `aplicar` registra el
 * número de radicado que dio Sanitas.
 */
class SanitasNovedadController extends Controller
{
    public function __construct(private SanitasNovedadService $servicio)
    {
        $this->middleware('auth');
    }

    public function precheck(int $contratoId)
    {
        $prep = $this->servicio->preparar($this->contrato($contratoId));

        return response()->json(['ok' => ! $prep['problemas']] + $prep);
    }

    public function formulario(int $contratoId)
    {
        try {
            $pdf = $this->servicio->formulario($this->contrato($contratoId));
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'no-store']);
    }

    public function aplicar(Request $request, int $contratoId)
    {
        $datos = $request->validate([
            'radicado' => 'nullable|string|max:40',
            'texto'    => 'nullable|string|max:20000',
            'captura'  => 'nullable|string|max:8000000',
            'error'    => 'nullable|string|max:1000',
        ]);

        try {
            return response()->json($this->servicio->aplicar($this->contrato($contratoId), $datos, Auth::id()));
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Siempre del aliado activo: el id llega por la URL. */
    private function contrato(int $id): Contrato
    {
        return Contrato::paraTramite($id);
    }
}
