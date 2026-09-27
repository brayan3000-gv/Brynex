<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Aliado;

class SetAlidoContext
{
    /**
     * Inyecta el aliado_id activo en la sesión.
     * - Usuarios normales: siempre usan su aliado_id propio.
     * - Usuarios BryNex (es_brynex=true): pueden cambiar de aliado
     *   pasando ?aliado=ID en la URL, y se persiste en sesión.
     */
    public function handle(Request $request, Closure $next)
    {
        // Siempre el guard `web`: en /portal, `auth:empresa` corre antes que este
        // middleware y cambia el guard por defecto, y la empresa no tiene aliado
        // activo que poner en sesión.
        if (!Auth::guard('web')->check()) {
            return $next($request);
        }

        $user = Auth::guard('web')->user();

        // Cambio de aliado solo permitido a usuarios BryNex
        if ($user->es_brynex && $request->has('aliado')) {
            $alidoId = (int) $request->get('aliado');
            if ($user->puedeAccederAliado($alidoId)) {
                session(['aliado_id_activo' => $alidoId]);
            }
        }

        // Si no hay aliado en sesión, usar el aliado principal del usuario
        if (!session()->has('aliado_id_activo')) {
            session(['aliado_id_activo' => $user->aliado_id]);
        }

        // Compartir con todas las vistas el aliado activo
        $alidoActivo = Aliado::find(session('aliado_id_activo'));
        view()->share('alidoActivo', $alidoActivo);

        return $next($request);
    }
}
