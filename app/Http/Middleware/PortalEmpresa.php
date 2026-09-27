<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * La puerta del portal de empresas. Va después de `auth:empresa`.
 *
 * - Si el aliado desactivó el acceso mientras la empresa tenía la sesión
 *   abierta, la saca en la siguiente petición.
 * - Con clave temporal no deja ver nada hasta que ponga la suya.
 * - Las páginas llevan datos de nómina y salud: no se guardan en caché del
 *   navegador, para que el botón «atrás» en un equipo compartido no las
 *   vuelva a mostrar después de salir.
 */
class PortalEmpresa
{
    public function handle(Request $request, Closure $next)
    {
        $acceso = Auth::guard('empresa')->user();

        if (! $acceso || ! $acceso->activo || ! $acceso->empresa) {
            Auth::guard('empresa')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'cedula' => 'El acceso de esta empresa no está activo. Comunícate con tu asesor.',
            ]);
        }

        if ($acceso->debe_cambiar_clave && ! $request->routeIs('portal.clave', 'portal.clave.guardar', 'portal.salir')) {
            return redirect()->route('portal.clave');
        }

        view()->share('acceso', $acceso);

        $respuesta = $next($request);
        $respuesta->headers->set('Cache-Control', 'no-store, private');

        return $respuesta;
    }
}
