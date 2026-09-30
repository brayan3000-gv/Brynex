<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contrato;
use App\Services\EpsSura\EpsSuraReingresoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Reingreso en EPS SURA desde el radicado de EPS de Afiliaciones.
 *
 * Los mismos tres pasos que Nueva EPS, para que nada se radique a ciegas:
 * `precheck` no toca el portal; `consultar` entra y dice si la persona existe
 * para reingreso (si no aparece, es traslado y por esa pantalla no se hace);
 * `registrar` radica. Los dos últimos abren un navegador en el servidor y
 * tardan cerca de un minuto.
 *
 * `explorar` devuelve el inventario de campos de la pantalla del portal: es
 * para ajustar los selectores sin radicar, y no escribe nada.
 */
class EpsSuraReingresoController extends Controller
{
    public function __construct(private EpsSuraReingresoService $servicio)
    {
        $this->middleware('auth');
    }

    public function precheck(Request $request, int $contratoId)
    {
        $prep = $this->servicio->preparar($this->contrato($contratoId));

        return response()->json(['ok' => ! $prep['problemas']] + $prep);
    }

    public function explorar(Request $request, int $contratoId)
    {
        @set_time_limit(300);

        try {
            return response()->json($this->servicio->explorar($this->contrato($contratoId)));
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
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

    private function contrato(int $id): Contrato
    {
        return Contrato::paraTramite($id);
    }
}
