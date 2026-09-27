<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\EmpresaAcceso;
use App\Models\User;
use App\Services\RegistroAccesoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        if (Auth::guard('empresa')->check()) {
            return redirect()->route('portal.inicio');
        }

        return view('auth.login');
    }

    public function login(Request $request, RegistroAccesoService $registroAcceso)
    {
        $request->validate([
            'cedula' => 'required|string',
            'password' => 'required|string',
        ], [
            'cedula.required' => 'La cédula es obligatoria.',
            'password.required' => 'La contraseña es obligatoria.',
        ]);

        // Buscar usuario por cédula
        $user = User::where('cedula', $request->cedula)
            ->where('activo', true)
            ->first();

        if (! $user || ! password_verify($request->password, $user->password)) {
            // No es alguien del equipo: puede ser una empresa entrando a su
            // portal con el NIT. El mismo formulario sirve para los dos y el
            // error no dice cuál de los dos falló.
            if ($acceso = $this->accesoEmpresa($request->cedula, $request->password)) {
                return $this->entrarPortal($request, $acceso);
            }

            return back()->withErrors([
                'cedula' => 'Cédula/NIT o contraseña incorrectos.',
            ])->withInput(['cedula' => $request->cedula]);
        }

        // Una sesión de portal abierta en el mismo navegador no debe seguir
        // viva debajo de la del equipo.
        Auth::guard('empresa')->logout();
        Auth::login($user, $request->boolean('remember'));

        // Renovar el ID de sesión tras autenticar (previene session fixation:
        // un ID fijado antes del login dejaría de servir). Conserva los datos.
        $request->session()->regenerate();

        // Quién entró, desde dónde y con qué equipo. Va después de regenerate()
        // para que la cookie del dispositivo se emita sobre la sesión definitiva.
        $registroAcceso->registrar($user, $request);

        // Limpiar sesión de aliado anterior
        session()->forget('aliado_id_activo');

        // Usuario BryNex con acceso a múltiples aliados → selector
        if ($user->es_brynex && $user->aliados()->where('aliados.activo', true)->wherePivot('activo', true)->exists()) {
            return redirect()->route('aliado.selector');
        }

        // Usuario normal → dashboard directo (el middleware SetAlidoContext pondrá su aliado)
        return redirect()->intended(route('dashboard'));
    }

    /**
     * El acceso de empresa que corresponde a ese NIT y contraseña, si lo hay y
     * está activo. La empresa tampoco entra si su aliado está inactivo.
     */
    private function accesoEmpresa(string $usuario, string $password): ?EmpresaAcceso
    {
        $usuario = EmpresaAcceso::normalizarUsuario($usuario);
        if ($usuario === '') {
            return null;
        }

        $acceso = EmpresaAcceso::where('usuario', $usuario)
            ->where('activo', true)
            ->whereHas('aliado', fn ($q) => $q->where('activo', true))
            ->first();

        if (! $acceso || ! password_verify($password, $acceso->password)) {
            return null;
        }

        return $acceso;
    }

    private function entrarPortal(Request $request, EmpresaAcceso $acceso)
    {
        Auth::guard('web')->logout();
        Auth::guard('empresa')->login($acceso, $request->boolean('remember'));
        $request->session()->regenerate();
        session()->forget('aliado_id_activo');

        // Sin eventos ni updated_at: es una marca de visita, no un cambio del acceso.
        EmpresaAcceso::whereKey($acceso->id)->update([
            'ultimo_acceso_at' => now(),
            'ultimo_acceso_ip' => $request->ip(),
        ]);

        return redirect()->route('portal.inicio');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
