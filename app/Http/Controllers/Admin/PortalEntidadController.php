<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RazonSocial;
use App\Services\Afiliaciones\PortalesEntidades;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Portales de entidades de una razón social: la pestaña de la ficha y el
 * panel 🔑 de Afiliaciones leen y guardan por aquí. La lógica está en
 * PortalesEntidades.
 *
 * Los permisos son los del módulo de claves: `claves_acceso.ver` para ver,
 * `claves_acceso.gestionar` para guardar y `claves_acceso.ver_contrasena`
 * para ver la contraseña en claro.
 */
class PortalEntidadController extends Controller
{
    public function index(Request $request, int $id)
    {
        $rs = $this->razonSocial($id);

        $delContrato = [
            'eps' => $request->integer('eps') ?: null,
            'arl' => $request->integer('arl') ?: null,
            'caja' => $request->integer('caja') ?: null,
        ];

        return response()->json(PortalesEntidades::tabla(
            $rs,
            (bool) auth()->user()->can('claves_acceso.ver_contrasena'),
            $this->aliadoActivo(),
            $delContrato,
        ) + [
            'puede_gestionar' => (bool) auth()->user()->can('claves_acceso.gestionar'),
            'puede_eliminar' => (bool) auth()->user()->can('claves_acceso.eliminar'),
            'catalogo' => PortalesEntidades::catalogo(),
        ]);
    }

    public function store(Request $request, int $id)
    {
        $rs = $this->razonSocial($id);

        $data = $request->validate([
            'tipo' => ['required', Rule::in([...PortalesEntidades::TIPOS, 'OTRO'])],
            'entidad_id' => 'required|integer',
            'usuario' => 'nullable|string|max:150',
            'contrasena' => 'nullable|string|max:200',
            'link_acceso' => 'nullable|string|max:350',
            'observacion' => 'nullable|string|max:300',
            'sin_portal' => 'nullable|boolean',
            'no_aplica' => 'nullable|boolean',
            'asesor_nombre' => 'nullable|string|max:150',
            'asesor_correo' => 'nullable|email|max:150',
            'asesor_telefono' => 'nullable|string|max:50',
            'asesor2_nombre' => 'nullable|string|max:150',
            'asesor2_correo' => 'nullable|email|max:150',
            'asesor2_telefono' => 'nullable|string|max:50',
        ], [
            'asesor_correo.email' => 'El correo del asesor no es válido.',
            'asesor2_correo.email' => 'El correo del asesor de reemplazo no es válido.',
        ]);

        $datos = collect($data)->except(['tipo', 'entidad_id'])
            ->map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v)
            ->all();
        $datos['sin_portal'] = $request->boolean('sin_portal');
        $datos['no_aplica'] = $request->boolean('no_aplica');

        $r = PortalesEntidades::guardar($rs, $data['tipo'], (int) $data['entidad_id'], $datos, $this->aliadoActivo());

        return response()->json([
            'success' => true,
            'message' => 'Guardado.'.$r['aviso'],
        ]);
    }

    /** Crea o edita una clave que no es de EPS, ARL, caja ni SAT (correo, operador…). */
    public function otra(Request $request, int $id)
    {
        $rs = $this->razonSocial($id);

        $data = $request->validate([
            'clave_id' => 'nullable|integer',
            'tipo' => 'required|string|max:80',
            'entidad' => 'required|string|max:150',
            'usuario' => 'nullable|string|max:150',
            'contrasena' => 'nullable|string|max:200',
            'link_acceso' => 'nullable|string|max:350',
            'observacion' => 'nullable|string|max:300',
        ], ['entidad.required' => 'Escribe el nombre del portal o del correo.']);

        $datos = collect($data)->except('clave_id')
            ->map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v)
            ->all();

        PortalesEntidades::guardarOtra($rs, $data['clave_id'] ?? null, $datos, $this->aliadoActivo());

        return response()->json(['success' => true, 'message' => 'Guardado.']);
    }

    /** Liga una clave «sin clasificar» a su entidad. */
    public function asignar(Request $request, int $id)
    {
        $rs = $this->razonSocial($id);

        $data = $request->validate([
            'clave_id' => 'required|integer',
            'tipo' => ['required', Rule::in([...PortalesEntidades::TIPOS, 'OTRO'])],
            'entidad_id' => 'nullable|required_unless:tipo,OTRO|integer',
        ]);

        PortalesEntidades::asignar($rs, (int) $data['clave_id'], $data['tipo'], $data['entidad_id'] ?? null);

        return response()->json(['success' => true, 'message' => 'Clave asignada.']);
    }

    private function razonSocial(int $id): RazonSocial
    {
        $rs = PortalesEntidades::razonSocialVisible($id, $this->aliadoActivo(), auth()->user());
        abort_if(! $rs, 404);
        abort_if(PortalesEntidades::clavesVedadas($rs, $this->aliadoActivo(), auth()->user()), 403,
            'Las claves de esta empresa las maneja BryNex.');

        return $rs;
    }

    private function aliadoActivo(): int
    {
        return (int) (session('aliado_id_activo') ?: auth()->user()->aliado_id);
    }
}
