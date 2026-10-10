<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Aliado;
use App\Models\User;
use App\Services\CompresorLogoService;
use App\Services\RazonSocialCompartida;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AlidoController extends Controller
{
    /**
     * Las razones sociales que se prestan desde la ficha del aliado son solo
     * de Brygar: GiMave, Formalizate y SS Faga son aliados independientes con
     * empresas de sus propios clientes (decisión del 5-oct-2026).
     */
    private const DUENO_PRESTADAS = 2;

    public function __construct()
    {
        $this->middleware(['auth', 'role:superadmin']);
    }

    /** Estados del listado: activo, pausado (activo = 0) e inactivo (borrado suave). */
    private const ESTADOS = ['activos', 'pausados', 'inactivos', 'todos'];

    public function index(Request $request)
    {
        $estado = in_array($request->query('estado'), self::ESTADOS, true) ? $request->query('estado') : 'activos';

        $todos = Aliado::withCount('usuarios')
            ->withTrashed()
            ->orderBy('nombre')
            ->get();

        $porEstado = fn ($a) => $a->trashed() ? 'inactivos' : ($a->activo ? 'activos' : 'pausados');
        $conteos = $todos->countBy($porEstado);
        $aliados = $estado === 'todos' ? $todos : $todos->filter(fn ($a) => $porEstado($a) === $estado);

        return view('admin.aliados.index', compact('aliados', 'estado', 'conteos') + ['total' => $todos->count()]);
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
        ] + $this->ubicaciones());
    }

    /** Departamentos y municipios para las listas de la ficha, como en la del cliente. */
    private function ubicaciones(): array
    {
        return [
            'departamentos' => DB::table('departamentos')->orderBy('nombre')->pluck('nombre', 'id'),
            'ciudades'      => DB::table('ciudades')->orderBy('nombre')->get(['id', 'departamento_id', 'nombre']),
        ];
    }

    /**
     * El nombre ES la razón social: antes eran dos campos y en el encabezado
     * salía el mismo texto dos veces. La frase de debajo es el eslogan.
     *
     * `ciudad` se sigue llenando, con el nombre del municipio, porque la usan
     * las cuentas de cobro y la página pública. Si no se escoge municipio se
     * deja la que había escrita a mano.
     */
    private function nombreYUbicacion(array $data): array
    {
        $data['razon_social'] = $data['nombre'];

        $municipio = ! empty($data['municipio_id'])
            ? DB::table('ciudades')->where('id', $data['municipio_id'])->first(['nombre', 'departamento_id'])
            : null;

        if ($municipio) {
            $data['departamento_id'] = (int) $municipio->departamento_id;
            $data['ciudad'] = mb_convert_case(mb_strtolower(trim($municipio->nombre)), MB_CASE_TITLE);
        } else {
            $data['municipio_id'] = null;
        }

        return $data;
    }

    /** Sucursal ante el operador: vacía queda en null (los planos usan la de la razón social). */
    private function sucursal(array $data): array
    {
        $codigo = trim((string) ($data['codigo_sucursal'] ?? ''));
        $data['codigo_sucursal'] = $codigo !== '' ? $codigo : null;
        $data['nombre_sucursal'] = $codigo !== '' ? mb_strtoupper(trim((string) $data['nombre_sucursal'])) : null;

        return $data;
    }

    public function store(Request $request, CompresorLogoService $compresor)
    {
        $data = $request->validate([
            'nombre'               => 'required|string|max:150',
            'nit'                  => 'nullable|string|max:20|unique:aliados,nit',
            'contacto'             => 'nullable|string|max:100',
            'telefono'             => 'nullable|string|max:30',
            'celular'              => 'nullable|string|max:30',
            'whatsapp'             => 'nullable|string|max:30',
            'correo'               => 'nullable|email|max:150',
            'direccion'            => 'nullable|string|max:255',
            'departamento_id'      => 'nullable|integer|exists:departamentos,id',
            'municipio_id'         => 'nullable|integer|exists:ciudades,id',
            'eslogan'              => 'nullable|string|max:120',
            'codigo_sucursal'      => 'nullable|string|max:10|regex:/^[A-Za-z0-9]+$/',
            'nombre_sucursal'      => 'nullable|string|max:40|required_with:codigo_sucursal',
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
        $data = $this->nombreYUbicacion($data);
        $data = $this->sucursal($data);

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
        return view('admin.aliados.form', compact('aliado', 'usuariosBrynex', 'todosModulos', 'modulosContratados') + $this->ubicaciones());
    }

    public function update(Request $request, Aliado $aliado, CompresorLogoService $compresor)
    {
        $data = $request->validate([
            'nombre'               => 'required|string|max:150',
            'nit'                  => "nullable|string|max:20|unique:aliados,nit,{$aliado->id}",
            'contacto'             => 'nullable|string|max:100',
            'telefono'             => 'nullable|string|max:30',
            'celular'              => 'nullable|string|max:30',
            'whatsapp'             => 'nullable|string|max:30',
            'correo'               => 'nullable|email|max:150',
            'direccion'            => 'nullable|string|max:255',
            'departamento_id'      => 'nullable|integer|exists:departamentos,id',
            'municipio_id'         => 'nullable|integer|exists:ciudades,id',
            'eslogan'              => 'nullable|string|max:120',
            'codigo_sucursal'      => 'nullable|string|max:10|regex:/^[A-Za-z0-9]+$/',
            'nombre_sucursal'      => 'nullable|string|max:40|required_with:codigo_sucursal',
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
        $data = $this->nombreYUbicacion($data);
        $data = $this->sucursal($data);

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

    // ─── Razones sociales prestadas ──────────────────────────────

    /**
     * Las razones sociales que el aliado tiene prestadas y las que se le
     * pueden prestar. Es la misma operación de «Habilitar en aliado» de la
     * ficha de la razón social, vista desde el aliado (ver RazonSocialCompartida).
     */
    public function razonesPrestadas(Aliado $aliado)
    {
        abort_unless(RazonSocialCompartida::puedeHabilitar(auth()->user()), 403);

        $vigentes = DB::table('contratos')->where('estado', 'vigente')
            ->groupBy('razon_social_id')->selectRaw('razon_social_id, COUNT(*) AS n')
            ->pluck('n', 'razon_social_id');

        $prestadas = DB::table('razones_sociales as c')
            ->join('razones_sociales as o', 'o.id', '=', 'c.origen_id')
            ->join('aliados as d', 'd.id', '=', 'o.aliado_id')
            ->where('c.aliado_id', $aliado->id)
            ->orderBy('c.razon_social')
            ->get(['c.id', 'c.razon_social', 'c.nit', 'c.estado', 'c.codigo_sucursal', 'c.ve_claves', 'd.nombre as dueno'])
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'razon_social' => $r->razon_social,
                'nit' => $r->nit,
                'estado' => $r->estado,
                // La de la copia manda en los planos; si está vacía, la del aliado.
                'sucursal' => $r->codigo_sucursal ?: $aliado->codigo_sucursal,
                've_claves' => (bool) $r->ve_claves,
                'dueno' => $r->dueno,
                'vigentes' => (int) ($vigentes[$r->id] ?? 0),
            ]);

        // NIT de lo que el aliado ya tiene: si ya está la empresa sin ligar,
        // prestarla la liga en vez de crear otra fila.
        $nit = fn ($v) => preg_replace('/\D/', '', (string) $v);
        $propias = DB::table('razones_sociales')->where('aliado_id', $aliado->id)
            ->get(['nit', 'origen_id'])
            ->mapWithKeys(fn ($r) => [$nit($r->nit) => $r->origen_id ? 'copia' : 'sin_vincular']);

        $candidatas = DB::table('razones_sociales as o')
            ->join('aliados as d', 'd.id', '=', 'o.aliado_id')
            ->whereNull('o.origen_id')
            ->where('o.estado', 'Activa')
            ->where('o.aliado_id', self::DUENO_PRESTADAS)
            ->where('o.aliado_id', '<>', $aliado->id)
            ->orderBy('o.razon_social')
            ->get(['o.id', 'o.razon_social', 'o.nit', 'o.aliado_id', 'd.nombre as dueno'])
            ->filter(fn ($r) => strlen($nit($r->nit)) >= 6 && ($propias[$nit($r->nit)] ?? null) !== 'copia')
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'razon_social' => $r->razon_social,
                'nit' => $r->nit,
                'dueno_id' => (int) $r->aliado_id,
                'dueno' => $r->dueno,
                'vigentes' => (int) ($vigentes[$r->id] ?? 0),
                'ya_la_tiene' => isset($propias[$nit($r->nit)]),
            ])
            ->values();

        return response()->json(['prestadas' => $prestadas, 'candidatas' => $candidatas]);
    }

    /** Presta al aliado las razones sociales elegidas (crea su copia o liga la que ya tenía). */
    public function prestarRazones(Request $request, Aliado $aliado)
    {
        abort_unless(RazonSocialCompartida::puedeHabilitar(auth()->user()), 403);

        $data = $request->validate([
            'razones' => 'required|array|min:1',
            'razones.*' => 'integer',
            've_claves' => 'boolean',
        ]);

        $quien = auth()->user()->nombre;
        $hechos = [];

        foreach (array_unique(array_map('intval', $data['razones'])) as $id) {
            $rs = DB::table('razones_sociales')->where('id', $id)->whereNull('origen_id')
                ->where('aliado_id', self::DUENO_PRESTADAS)->first();
            abort_if(! $rs, 404, 'Una de las razones sociales ya no existe, es una copia o no es de Brygar.');

            $r = RazonSocialCompartida::habilitar($rs, $aliado->id,
                "Habilitada desde la ficha del aliado por {$quien} el ".now()->format('d/m/Y').'.');
            RazonSocialCompartida::permitirClaves($rs, $aliado->id, $request->boolean('ve_claves'));
            $hechos[] = $rs->razon_social.($r['accion'] === 'creada' ? '' : ' (ya la tenía: vinculada)');
        }

        return response()->json([
            'success' => true,
            'message' => 'Prestadas: '.implode(', ', $hechos).'. Falta crearle la sucursal en el operador a cada una.',
        ]);
    }

    /** Cambia si el aliado ve las claves de portales de una razón social prestada. */
    public function clavesRazonPrestada(Request $request, Aliado $aliado, int $rs)
    {
        abort_unless(RazonSocialCompartida::puedeHabilitar(auth()->user()), 403);

        $copia = DB::table('razones_sociales')->where('id', $rs)
            ->where('aliado_id', $aliado->id)->whereNotNull('origen_id')->first();
        abort_if(! $copia, 404);

        $ve = $request->boolean('ve_claves');
        RazonSocialCompartida::permitirClaves($copia, $aliado->id, $ve);

        return response()->json(['success' => true, 've_claves' => $ve]);
    }
}
