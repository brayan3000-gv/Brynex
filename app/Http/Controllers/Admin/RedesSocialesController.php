<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Aliado;
use App\Models\RedSocialConfig;
use App\Services\RedesSociales\RedesFactory;
use Illuminate\Http\Request;

/**
 * Configuración de credenciales de redes sociales por aliado (Facebook, Instagram — extensible).
 * Self-service: cada aliado gestiona sus propias cuentas, igual que ConfiguracionAliadoController
 * (aliado activo resuelto por sesión, no una lista central de BryNex como WhatsApp).
 */
class RedesSocialesController extends Controller
{
    public function __construct()
    {
        // Quién entra lo decide el `permiso:` de cada ruta (routes/web.php). El
        // `role:` que había aquí bloqueaba antes, aunque el permiso estuviera otorgado.
        $this->middleware('auth');
    }

    public function edit()
    {
        $aliado = $this->aliadoActivo();

        $configs = collect(RedSocialConfig::REDES_DISPONIBLES)
            ->mapWithKeys(fn ($red) => [$red => RedSocialConfig::paraAliado($aliado->id, $red)]);

        return view('admin.redes-sociales.index', compact('aliado', 'configs'));
    }

    public function update(Request $request, string $red)
    {
        $this->validarRed($red);
        $aliado = $this->aliadoActivo();
        $config = RedSocialConfig::paraAliado($aliado->id, $red);

        $validated = $request->validate([
            'identificador' => 'nullable|string|max:100',
            'access_token'  => 'nullable|string|max:2000',
            'nombre_cuenta' => 'nullable|string|max:150',
            'activo'        => 'nullable|boolean',
        ]);

        // No sobreescribir el token si se deja vacío (evita perderlo por error al solo tocar otro campo).
        $cambioCredenciales = $validated['identificador'] !== $config->identificador
            || !empty($validated['access_token']);

        if (empty($validated['access_token'])) {
            unset($validated['access_token']);
        }

        $config->fill($validated);
        $config->activo = $request->boolean('activo');
        if ($cambioCredenciales) {
            $config->verificado_en = null; // hay que volver a probar la conexión
        }
        $config->save();

        return redirect()->route('admin.redes-sociales.index')->with('success', ucfirst($red) . ' actualizado.');
    }

    public function probarConexion(Request $request, string $red)
    {
        $this->validarRed($red);
        $aliado = $this->aliadoActivo();
        $config = RedSocialConfig::paraAliado($aliado->id, $red);

        $resultado = RedesFactory::make($config)->probarConexion();

        if ($resultado['ok']) {
            $config->update(['verificado_en' => now()]);
        }

        return response()->json($resultado);
    }

    private function validarRed(string $red): void
    {
        abort_unless(in_array($red, RedSocialConfig::REDES_DISPONIBLES, true), 404);
    }

    private function aliadoActivo(): Aliado
    {
        $aliado = Aliado::find(session('aliado_id_activo'));
        abort_if(!$aliado, 400, 'No hay un aliado activo seleccionado.');
        return $aliado;
    }
}
