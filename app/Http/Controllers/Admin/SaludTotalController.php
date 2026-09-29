<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contrato;
use App\Services\SaludTotal\SaludTotalNovedadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Novedad de inicio laboral en Salud Total desde el radicado de EPS de Afiliaciones.
 *
 * Tres pasos, igual que Nueva EPS: `precheck` no toca el portal; `consultar`
 * entra y trae el nombre, si ya está activo con la empresa y si ya hay una
 * novedad; `registrar` la radica. Va por HTTP, sin navegador: segundos, no minutos.
 */
class SaludTotalController extends Controller
{
    public function __construct(private SaludTotalNovedadService $servicio)
    {
        $this->middleware('auth');
    }

    public function precheck(Request $request, int $contratoId)
    {
        $prep = $this->servicio->preparar($this->contrato($contratoId));

        return response()->json(['ok' => ! $prep['problemas']] + $prep);
    }

    public function consultar(Request $request, int $contratoId)
    {
        @set_time_limit(300);

        try {
            return response()->json($this->servicio->consultar($this->contrato($contratoId)));
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function registrar(Request $request, int $contratoId)
    {
        @set_time_limit(300);

        try {
            return response()->json($this->servicio->registrar($this->contrato($contratoId), Auth::id()));
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
