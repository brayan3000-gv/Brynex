<?php

namespace App\Http\Middleware;

use App\Http\Controllers\AvisoTratamientoController;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Nadie usa el panel sin haber autorizado el tratamiento de sus datos.
 *
 * Va después de Authenticate en el grupo `web`. Deja pasar tres cosas para no
 * encerrar al usuario: las rutas del propio aviso, el logout (siempre se puede
 * salir) y las peticiones no autenticadas.
 */
class ExigirAvisoTratamiento
{
    public function handle(Request $request, Closure $next)
    {
        // El guard `web` explícito: en /portal el guard por defecto es el de la
        // empresa (auth:empresa corre antes), y el aviso es para el equipo.
        $user = Auth::guard('web')->user();

        if (! $user) {
            return $next($request);
        }

        // Las públicas quedan fuera: el aviso es para operar el panel, no para
        // mirar la página de un aliado. Sin esta excepción, un usuario con
        // sesión abierta que entra a brynex.co/aliado/x acabaría en el aviso.
        if ($request->routeIs('tratamiento.*', 'logout', 'login*', 'publico.*', 'incapacidades.subir*')) {
            return $next($request);
        }

        if ($user->acepto_tratamiento_version === AvisoTratamientoController::VERSION) {
            return $next($request);
        }

        // Las peticiones AJAX no se pueden redirigir: el JS recibiría el HTML
        // del aviso donde espera JSON y fallaría de forma incomprensible. Se
        // responde 403 con un mensaje que el front puede mostrar tal cual.
        if ($request->expectsJson()) {
            return response()->json([
                'error' => 'Debes aceptar el aviso de tratamiento de datos para continuar. Recarga la página.',
            ], 403);
        }

        return redirect()->route('tratamiento.mostrar');
    }
}
