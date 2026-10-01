<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Sanitas\SanitasConciliacionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Conciliación de Sanitas desde el modal 🩺 Conciliar. El Estado de
 * Afiliación lo baja la extensión BryNex Portales con la sesión de la persona
 * (Radware + captcha + código al correo) y lo manda aquí.
 */
class SanitasController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function conciliar(Request $request, SanitasConciliacionService $servicio)
    {
        $datos = $request->validate([
            'nit'     => 'required|string|max:20',
            'txt'     => 'required|string|max:5000000',
            'simular' => 'boolean',
        ]);
        // BryNex concilia la empresa en todos los aliados donde está; el aliado, solo lo suyo.
        $aliados = Auth::user()->es_brynex ? $servicio->aliadosDelNit($datos['nit']) : [(int) session('aliado_id_activo')];

        try {
            $r = $servicio->conciliar($aliados, $datos['nit'], $datos['txt'], (bool) ($datos['simular'] ?? false), Auth::id());
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'mensaje' => $e->getMessage()], 422);
        }

        // El modal muestra la última corrida como las demás entidades.
        $estado = $r + ['corriendo' => false, 'fin' => now()->toIso8601String(), 'mensaje' => 'Terminado.'];
        Cache::put($this->claveEstado(), $estado, now()->addDay());

        return response()->json(['ok' => true] + $estado);
    }

    public function estado()
    {
        return response()->json(Cache::get($this->claveEstado()) ?? ['corriendo' => false, 'vacio' => true]);
    }

    /** La corrida consolidada de BryNex no se mezcla con la de cada aliado. */
    private function claveEstado(): string
    {
        return 'sanitas_conciliacion:estado:'.(Auth::user()->es_brynex ? 'brynex' : (int) session('aliado_id_activo'));
    }
}
