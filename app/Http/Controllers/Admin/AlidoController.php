<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Aliado;
use App\Models\User;
use App\Services\CompresorLogoService;
use Illuminate\Http\Request;

class AlidoController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'role:superadmin']);
    }

    public function index()
    {
        $aliados = Aliado::withCount('usuarios')
            ->withTrashed()
            ->orderBy('nombre')
            ->get();
        return view('admin.aliados.index', compact('aliados'));
    }

    public function create()
    {
        $usuariosBrynex = User::where('es_brynex', true)->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']);
        $todosModulos = \App\Models\BrynexModulo::orderBy('orden')->get();
        $modulosContratados = [];
        return view('admin.aliados.form', [
            'aliado' => new Aliado(),
            'usuariosBrynex' => $usuariosBrynex,
            'todosModulos' => $todosModulos,
            'modulosContratados' => $modulosContratados,
        ]);
    }

    public function store(Request $request, CompresorLogoService $compresor)
    {
        $data = $request->validate([
            'nombre'               => 'required|string|max:150',
            'nit'                  => 'nullable|string|max:20|unique:aliados,nit',
            'razon_social'         => 'nullable|string|max:200',
            'contacto'             => 'nullable|string|max:100',
            'telefono'             => 'nullable|string|max:30',
            'celular'              => 'nullable|string|max:30',
            'whatsapp'             => 'nullable|string|max:30',
            'correo'               => 'nullable|email|max:150',
            'direccion'            => 'nullable|string|max:255',
            'ciudad'               => 'nullable|string|max:80',
            'eslogan'              => 'nullable|string|max:120',
            'color_primario'       => 'nullable|string|max:10',
            'activo'               => 'boolean',
            'logo'                 => 'nullable|image|mimes:png,jpg,jpeg,webp,svg|max:10240',
            'logo_oscuro'          => 'nullable|image|mimes:png,jpg,jpeg,webp,svg|max:10240',
            'logo_marca_claro'     => 'nullable|image|mimes:png,jpg,jpeg,webp,svg|max:10240',
            'imagen_planes'        => 'nullable|image|mimes:png,jpg,jpeg|max:5120',
            'logo_marca_recorte.alto_util_pct'         => 'nullable|numeric|min:0|max:100',
            'logo_marca_recorte.icono_ancho_pct'       => 'nullable|numeric|min:0|max:100',
            'logo_marca_recorte.wordmark_y_inicio_pct' => 'nullable|numeric|min:0|max:100',
            'logo_marca_recorte.wordmark_y_fin_pct'    => 'nullable|numeric|min:0|max:100',
            'afiliaciones_brynex'  => 'boolean',
            'encargado_afil_id'    => 'nullable|exists:users,id',
        ]);

        if ($request->hasFile('logo')) {
            $data['logo'] = $compresor->guardar($request->file('logo'), 'logos', '_', CompresorLogoService::LADO_ICONO);
        }
        if ($request->hasFile('logo_oscuro')) {
            $data['logo_oscuro'] = $compresor->guardar($request->file('logo_oscuro'), 'logos', '_oscuro_', CompresorLogoService::LADO_MARCA);
        }
        if ($request->hasFile('logo_marca_claro')) {
            $data['logo_marca_claro'] = $compresor->guardar($request->file('logo_marca_claro'), 'logos', '_marca_claro_', CompresorLogoService::LADO_MARCA);
        }
        if ($request->hasFile('imagen_planes')) {
            $file      = $request->file('imagen_planes');
            $filename  = time() . '_planes_' . $file->getClientOriginalName();
            $file->move(public_path('storage/planes-precios'), $filename);
            $data['imagen_planes'] = 'planes-precios/' . $filename;
        }
        $data['logo_marca_recorte'] = array_filter($data['logo_marca_recorte'] ?? []) ?: null;

        $data['activo'] = $request->boolean('activo', true);
        $data['afiliaciones_brynex'] = $request->boolean('afiliaciones_brynex', false);
        $data['encargado_afil_id']   = $request->input('encargado_afil_id') ?: null;
        $data['whatsapp']            = $request->input('whatsapp') ?: null;

        $aliado = Aliado::create($data);

        // Guardar relación de módulos
        $modulosInput = $request->input('modulos', []);
        foreach (\App\Models\BrynexModulo::all() as $mod) {
            $activo = isset($modulosInput[$mod->id]) ? 1 : 0;
            $moduloAliado = \App\Models\BrynexModuloAliado::firstOrNew([
                'aliado_id' => $aliado->id,
                'modulo_id' => $mod->id
            ]);
            if (!$moduloAliado->exists) {
                $moduloAliado->fecha_inicio = now();
            }
            $moduloAliado->activo = $activo;
            $moduloAliado->save();
        }

        return redirect()->route('admin.aliados.index')
            ->with('success', "Aliado '{$data['nombre']}' creado correctamente.");
    }

    public function edit(Aliado $aliado)
    {
        $usuariosBrynex = User::where('es_brynex', true)->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']);
        $todosModulos = \App\Models\BrynexModulo::orderBy('orden')->get();
        $modulosContratados = \App\Models\BrynexModuloAliado::where('aliado_id', $aliado->id)->pluck('activo', 'modulo_id')->toArray();
        return view('admin.aliados.form', compact('aliado', 'usuariosBrynex', 'todosModulos', 'modulosContratados'));
    }

    public function update(Request $request, Aliado $aliado, CompresorLogoService $compresor)
    {
        $data = $request->validate([
            'nombre'               => 'required|string|max:150',
            'nit'                  => "nullable|string|max:20|unique:aliados,nit,{$aliado->id}",
            'razon_social'         => 'nullable|string|max:200',
            'contacto'             => 'nullable|string|max:100',
            'telefono'             => 'nullable|string|max:30',
            'celular'              => 'nullable|string|max:30',
            'whatsapp'             => 'nullable|string|max:30',
            'correo'               => 'nullable|email|max:150',
            'direccion'            => 'nullable|string|max:255',
            'ciudad'               => 'nullable|string|max:80',
            'eslogan'              => 'nullable|string|max:120',
            'color_primario'       => 'nullable|string|max:10',
            'activo'               => 'boolean',
            'logo'                 => 'nullable|image|mimes:png,jpg,jpeg,webp,svg|max:10240',
            'logo_oscuro'          => 'nullable|image|mimes:png,jpg,jpeg,webp,svg|max:10240',
            'logo_marca_claro'     => 'nullable|image|mimes:png,jpg,jpeg,webp,svg|max:10240',
            'imagen_planes'        => 'nullable|image|mimes:png,jpg,jpeg|max:5120',
            'logo_marca_recorte.alto_util_pct'         => 'nullable|numeric|min:0|max:100',
            'logo_marca_recorte.icono_ancho_pct'       => 'nullable|numeric|min:0|max:100',
            'logo_marca_recorte.wordmark_y_inicio_pct' => 'nullable|numeric|min:0|max:100',
            'logo_marca_recorte.wordmark_y_fin_pct'    => 'nullable|numeric|min:0|max:100',
            'afiliaciones_brynex'  => 'boolean',
            'encargado_afil_id'    => 'nullable|exists:users,id',
        ]);

        if ($request->hasFile('logo')) {
            // Eliminar logo anterior si existe
            if ($aliado->logo) {
                $oldPath = public_path('storage/' . $aliado->logo);
                if (file_exists($oldPath)) @unlink($oldPath);
            }
            $data['logo'] = $compresor->guardar($request->file('logo'), 'logos', '_', CompresorLogoService::LADO_ICONO);
        } else {
            // Preservar el logo existente si no se sube uno nuevo
            $data['logo'] = $aliado->logo;
        }
        if ($request->hasFile('logo_oscuro')) {
            if ($aliado->logo_oscuro) {
                $oldPath = public_path('storage/' . $aliado->logo_oscuro);
                if (file_exists($oldPath)) @unlink($oldPath);
            }
            $data['logo_oscuro'] = $compresor->guardar($request->file('logo_oscuro'), 'logos', '_oscuro_', CompresorLogoService::LADO_MARCA);
        } else {
            $data['logo_oscuro'] = $aliado->logo_oscuro;
        }
        if ($request->hasFile('logo_marca_claro')) {
            if ($aliado->logo_marca_claro) {
                $oldPath = public_path('storage/' . $aliado->logo_marca_claro);
                if (file_exists($oldPath)) @unlink($oldPath);
            }
            $data['logo_marca_claro'] = $compresor->guardar($request->file('logo_marca_claro'), 'logos', '_marca_claro_', CompresorLogoService::LADO_MARCA);
        } else {
            $data['logo_marca_claro'] = $aliado->logo_marca_claro;
        }
        if ($request->hasFile('imagen_planes')) {
            if ($aliado->imagen_planes) {
                $oldPath = public_path('storage/' . $aliado->imagen_planes);
                if (file_exists($oldPath)) @unlink($oldPath);
            }
            $file      = $request->file('imagen_planes');
            $filename  = time() . '_planes_' . $file->getClientOriginalName();
            $file->move(public_path('storage/planes-precios'), $filename);
            $data['imagen_planes'] = 'planes-precios/' . $filename;
        } else {
            $data['imagen_planes'] = $aliado->imagen_planes;
        }
        $data['logo_marca_recorte'] = array_filter($data['logo_marca_recorte'] ?? []) ?: null;

        $data['activo'] = $request->boolean('activo');
        $data['afiliaciones_brynex'] = $request->boolean('afiliaciones_brynex', false);
        $data['encargado_afil_id']   = $request->input('encargado_afil_id') ?: null;
        $data['whatsapp']            = $request->input('whatsapp') ?: null;

        $aliado->update($data);

        // Guardar relación de módulos
        $modulosInput = $request->input('modulos', []);
        foreach (\App\Models\BrynexModulo::all() as $mod) {
            $activo = isset($modulosInput[$mod->id]) ? 1 : 0;
            $moduloAliado = \App\Models\BrynexModuloAliado::firstOrNew([
                'aliado_id' => $aliado->id,
                'modulo_id' => $mod->id
            ]);
            if (!$moduloAliado->exists) {
                $moduloAliado->fecha_inicio = now();
            }
            $moduloAliado->activo = $activo;
            $moduloAliado->save();
        }

        return redirect()->route('admin.aliados.edit', $aliado)
            ->with('success', "Aliado '{$aliado->nombre}' actualizado correctamente.");
    }

    public function destroy(Aliado $aliado)
    {
        $aliado->delete(); // SoftDelete
        return redirect()->route('admin.aliados.index')
            ->with('success', "Aliado '{$aliado->nombre}' desactivado.");
    }

    public function restore($id)
    {
        $aliado = Aliado::withTrashed()->findOrFail($id);
        $aliado->restore();
        return redirect()->route('admin.aliados.index')
            ->with('success', "Aliado '{$aliado->nombre}' restaurado.");
    }
}
