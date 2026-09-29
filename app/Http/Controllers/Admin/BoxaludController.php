<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contrato;
use App\Services\Boxalud\BoxaludService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Afiliación en el portal Boxalud (Emssanar) desde el radicado de EPS. La
 * extensión BryNex Portales opera el portal; aquí se arman los datos, se entregan
 * los PDF para adjuntar y se registra lo que salió.
 */
class BoxaludController extends Controller
{
    public function __construct(private BoxaludService $servicio)
    {
        $this->middleware('auth');
    }

    public function precheck(int $contratoId, string $eps)
    {
        try {
            $prep = $this->servicio->preparar($this->contrato($contratoId), $eps);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'problemas' => [$e->getMessage()]], 422);
        }

        return response()->json(['ok' => ! $prep['problemas']] + $prep);
    }

    /** Usuario del portal (y clave, solo con permiso de ver contraseñas) para llenar el login. */
    public function credencial(int $contratoId, string $eps)
    {
        try {
            $cred = $this->servicio->credencial($this->contrato($contratoId), $eps);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
        if (isset($cred['error'])) {
            return response()->json(['ok' => false, 'error' => $cred['error']], 422);
        }
        if (! Auth::user()->can('claves_acceso.ver_contrasena')) {
            unset($cred['contrasena']);
        }

        return response()->json(['ok' => true] + $cred)->header('Cache-Control', 'no-store');
    }

    public function archivo(int $contratoId, string $eps, string $tipo)
    {
        try {
            $pdf = $this->servicio->archivo($this->contrato($contratoId), $eps, $tipo);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'no-store']);
    }

    public function aplicar(Request $request, int $contratoId, string $eps)
    {
        $datos = $request->validate([
            'numero' => 'nullable|string|max:40',
            'texto'  => 'nullable|string|max:20000',
            'error'  => 'nullable|string|max:1000',
        ]);

        try {
            return response()->json($this->servicio->aplicar($this->contrato($contratoId), $eps, $datos, Auth::id()));
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
