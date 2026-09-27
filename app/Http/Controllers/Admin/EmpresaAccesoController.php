<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bitacora;
use App\Models\Empresa;
use App\Models\EmpresaAcceso;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * El acceso de una empresa a su portal, administrado desde «Editar empresa».
 *
 * Todas las rutas piden `facturacion.portal_empresas`, que no trae ningún rol:
 * lo tiene el superadmin del aliado y quien él lo reciba a mano.
 *
 * La clave nunca la escoge el aliado ni se guarda en claro: se genera, se
 * muestra una sola vez para entregársela a la empresa, y la empresa pone la
 * suya al primer ingreso.
 */
class EmpresaAccesoController extends Controller
{
    public function store(Request $request, int $empresaId)
    {
        $empresa = $this->empresa($empresaId);

        if (EmpresaAcceso::where('empresa_id', $empresa->id)->exists()) {
            return back()->with('error', 'Esta empresa ya tiene acceso al portal.');
        }

        $usuario = EmpresaAcceso::normalizarUsuario((string) $empresa->nit);
        if ($error = $this->usuarioNoDisponible($usuario)) {
            return back()->with('error', $error);
        }

        $clave = EmpresaAcceso::claveTemporal();

        $acceso = EmpresaAcceso::create([
            'aliado_id' => $empresa->aliado_id,
            'empresa_id' => $empresa->id,
            'usuario' => $usuario,
            'password' => $clave,
            'activo' => true,
            'ver_discriminado' => $request->boolean('ver_discriminado'),
            'debe_cambiar_clave' => true,
            'creado_por' => Auth::id(),
        ]);

        Bitacora::registrar('created', 'EmpresaAcceso', $acceso->id,
            "Acceso al portal creado para {$empresa->empresa} (usuario {$usuario})",
            ['empresa_id' => $empresa->id, 'ver_discriminado' => $acceso->ver_discriminado]);

        return back()->with('clave_portal', ['usuario' => $usuario, 'clave' => $clave]);
    }

    /**
     * Clave nueva para una empresa que olvidó la suya. De paso vuelve a tomar
     * el NIT de la ficha, por si lo corrigieron después de crear el acceso.
     */
    public function restablecer(int $empresaId)
    {
        $empresa = $this->empresa($empresaId);
        $acceso = EmpresaAcceso::where('empresa_id', $empresa->id)->firstOrFail();

        $usuario = EmpresaAcceso::normalizarUsuario((string) $empresa->nit);
        if ($usuario !== $acceso->usuario && ($error = $this->usuarioNoDisponible($usuario))) {
            return back()->with('error', $error);
        }

        $clave = EmpresaAcceso::claveTemporal();
        $acceso->forceFill([
            'usuario' => $usuario,
            'password' => $clave,
            'debe_cambiar_clave' => true,
            // Cierra la sesión «recordarme» que tuviera abierta con la clave vieja.
            'remember_token' => null,
        ])->save();

        Bitacora::registrar('updated', 'EmpresaAcceso', $acceso->id,
            "Clave del portal restablecida para {$empresa->empresa}");

        return back()->with('clave_portal', ['usuario' => $usuario, 'clave' => $clave]);
    }

    /** Activar o desactivar el acceso, y si ve los valores discriminados. */
    public function update(Request $request, int $empresaId)
    {
        $empresa = $this->empresa($empresaId);
        $acceso = EmpresaAcceso::where('empresa_id', $empresa->id)->firstOrFail();

        $datos = $request->validate([
            'activo' => 'required|boolean',
            'ver_discriminado' => 'required|boolean',
        ]);

        $acceso->fill($datos);
        if (! $acceso->activo) {
            $acceso->remember_token = null;
        }
        $cambios = $acceso->getDirty();
        $acceso->save();

        if ($cambios) {
            Bitacora::registrar('updated', 'EmpresaAcceso', $acceso->id,
                "Acceso al portal de {$empresa->empresa}: ".
                    ($acceso->activo ? 'activo' : 'desactivado').', '.
                    ($acceso->ver_discriminado ? 'valores discriminados' : 'solo total'),
                array_diff_key($cambios, ['remember_token' => 1]));
        }

        return back()->with('success', 'Acceso al portal actualizado.');
    }

    private function empresa(int $empresaId): Empresa
    {
        return Empresa::where('aliado_id', session('aliado_id_activo'))->findOrFail($empresaId);
    }

    /** Por qué ese NIT no sirve de usuario, o null si sirve. */
    private function usuarioNoDisponible(string $usuario): ?string
    {
        if (strlen($usuario) < 5) {
            return 'La empresa necesita un NIT en su ficha para crearle el acceso: es el usuario con que entra.';
        }

        if (EmpresaAcceso::where('usuario', $usuario)->exists()) {
            return "El NIT {$usuario} ya es el usuario del portal de otra empresa. Corrige el NIT de una de las dos.";
        }

        return null;
    }
}
