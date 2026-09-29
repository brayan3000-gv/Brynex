<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contrato;
use App\Services\NuevaEps\NuevaEpsReingresoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Reingreso en Nueva EPS desde el radicado de EPS de Afiliaciones.
 *
 * Tres pasos, para que la primera vez se vea antes de escribir: `precheck` no
 * toca el portal; `consultar` entra y trae el nombre, los reingresos que ya
 * existen y el asesor; `registrar` radica. Los dos últimos abren un navegador
 * en el servidor y tardan cerca de un minuto.
 */
class NuevaEpsController extends Controller
{
    public function __construct(private NuevaEpsReingresoService $servicio)
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
            return response()->json($this->servicio->consultar($this->contrato($contratoId), Auth::id()));
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
