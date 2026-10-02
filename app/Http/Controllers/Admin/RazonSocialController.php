<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RazonSocial;
use App\Models\RazonSocialCaja;
use App\Services\RazonSocialCompartida;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CRUD de Razones Sociales por aliado.
 * Las razones sociales son las empresas a través de las cuales
 * el aliado afilia trabajadores al sistema de seguridad social.
 */
class RazonSocialController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth']);
    }

    // ─── Listado ──────────────────────────────────────────────────
    public function index(Request $request)
    {
        $aliadoId = session('aliado_id_activo');
        $buscar   = $request->get('buscar');
        $estado   = $request->get('estado', 'Activa'); // por defecto solo activas

        $query = DB::table('razones_sociales as rs')
            ->leftJoin('arls',  'arls.nit',  '=', 'rs.arl_nit')
            ->leftJoin('cajas', 'cajas.nit', '=', 'rs.caja_nit')
            ->where('rs.aliado_id', $aliadoId)
            ->select(
                'rs.id', 'rs.nit', 'rs.dv', 'rs.razon_social', 'rs.estado',
                'rs.es_independiente', 'rs.observacion',
                'rs.fecha_constitucion',
                'arls.nombre_arl  as arl_nombre',
                'cajas.nombre     as caja_nombre',
                DB::raw('(SELECT COUNT(*) FROM contratos WHERE contratos.razon_social_id = rs.id AND contratos.estado = \'vigente\') as personas_activas')
            );

        if ($buscar) {
            $query->where(function($q) use ($buscar) {
                $q->whereSinTildes('rs.razon_social', $buscar)
                  ->orWhere(DB::raw('CAST(rs.nit AS CHAR)'), 'LIKE', "%{$buscar}%");
            });
        }

        if ($estado && $estado !== 'Todas') {
            $query->where('rs.estado', $estado);
        }

        $razones = $query->orderBy('rs.razon_social')->paginate(25)->withQueryString();

        return view('admin.razones_sociales.index', compact('razones', 'buscar', 'estado'));
    }

    // ─── Crear ────────────────────────────────────────────────────
    public function create()
    {
        $arls  = DB::table('arls')->orderBy('nombre_arl')->get(['id', 'nit', 'nombre_arl']);
        $cajas = $this->cajasCatalogo();
        $rs    = null;
        $departamentos = $this->departamentos();
        $cajasDpto = collect();

        return view('admin.razones_sociales.form', compact('arls', 'cajas', 'rs', 'departamentos', 'cajasDpto'));
    }

    // ─── Guardar ──────────────────────────────────────────────────
    public function store(Request $request)
    {
        $aliadoId = session('aliado_id_activo');

        // Generar automáticamente el siguiente ID interno (PK)
        $nextId = (int) DB::table('razones_sociales')->max('id') + 1;
        $request->merge(['id' => $nextId]);

        $data = $this->validar($request);
        $cajasDpto = $this->validarCajasDpto($request);

        // Verificar que el NIT no exista para este aliado (si se proporciona)
        if (!empty($data['nit'])) {
            $existeNit = DB::table('razones_sociales')
                ->where('nit', $data['nit'])
                ->where('aliado_id', $aliadoId)
                ->exists();

            if ($existeNit) {
                return back()->withInput()
                    ->withErrors(['nit' => 'Ya existe una Razón Social con ese NIT para este aliado.']);
            }
        }

        $data['id']              = $nextId;
        $data['aliado_id']       = $aliadoId;
        $data['es_independiente'] = $request->boolean('es_independiente');
        // Arranca en el plano 1: sin número, Planos SS no muestra el plano
        // actual aunque facturación y afiliaciones ya usen el 1.
        $data['n_plano']         = 1;

        DB::table('razones_sociales')->insert($data);
        RazonSocialCaja::guardar($nextId, $cajasDpto);

        return redirect()->route('admin.configuracion.razones.index')
            ->with('success', '✅ Razón Social creada correctamente.');
    }

    // ─── Editar ───────────────────────────────────────────────────
    public function edit(int $id)
    {
        $aliadoId = session('aliado_id_activo');
        $rs = DB::table('razones_sociales')
            ->where('id', $id)
            ->where('aliado_id', $aliadoId)
            ->first();

        abort_if(!$rs, 404);

        $arls  = DB::table('arls')->orderBy('nombre_arl')->get(['id', 'nit', 'nombre_arl']);
        $cajas = $this->cajasCatalogo();
        $departamentos = $this->departamentos();
        // Una copia muestra las de su original (no se editan aquí).
        $cajasDpto = RazonSocialCaja::deFicha($rs);

        // Estado de las credenciales de API por operador (sin exponer secretos)
        $operadoresCred = OperadorCredencialController::estadoPorOperador($aliadoId, $id);

        // Empresa prestada: de quién es copia esta fila, o con quién la comparte.
        $original = $rs->origen_id ? RazonSocialCompartida::original($rs) : null;
        $origenAliado = $original ? DB::table('aliados')->where('id', $original->aliado_id)->value('nombre') : null;
        $copias = $rs->origen_id ? collect() : RazonSocialCompartida::copias((int) $rs->id);
        $puedeHabilitar = RazonSocialCompartida::puedeHabilitar(auth()->user());
        $camposEmpresa = RazonSocialCompartida::EMPRESA;
        // Prestada sin permiso de claves: la pestaña de portales no se muestra.
        $clavesVedadas = \App\Services\Afiliaciones\PortalesEntidades::clavesVedadas(
            RazonSocial::find($id), (int) $aliadoId, auth()->user());

        return view('admin.razones_sociales.form', compact('arls', 'cajas', 'rs', 'operadoresCred',
            'original', 'origenAliado', 'copias', 'puedeHabilitar', 'camposEmpresa', 'clavesVedadas',
            'departamentos', 'cajasDpto'));
    }

    // ─── Actualizar ───────────────────────────────────────────────
    public function update(Request $request, int $id)
    {
        $aliadoId = session('aliado_id_activo');
        $rs = DB::table('razones_sociales')
            ->where('id', $id)
            ->where('aliado_id', $aliadoId)
            ->first();

        abort_if(!$rs, 404);

        $data = $this->validar($request, $id);
        $cajasDpto = $this->validarCajasDpto($request);
        unset($data['id']); // no cambiar PK en update

        // En una copia los datos de la empresa vienen de la original: se
        // cambian allá. Aquí solo lo del aliado (sucursal, estado…).
        if ($rs->origen_id) {
            $data = array_diff_key($data, array_flip(RazonSocialCompartida::EMPRESA));
            $request->request->remove('es_independiente');
        }

        // Verificar que el NIT no exista para este aliado en otra razón social
        if (!empty($data['nit'])) {
            $existeNit = DB::table('razones_sociales')
                ->where('nit', $data['nit'])
                ->where('aliado_id', $aliadoId)
                ->where('id', '<>', $id)
                ->exists();

            if ($existeNit) {
                return back()->withInput()
                    ->withErrors(['nit' => 'Ya existe otra Razón Social con ese NIT para este aliado.']);
            }
        }

        if (! $rs->origen_id) {
            $data['es_independiente'] = $request->boolean('es_independiente');
        }

        DB::table('razones_sociales')
            ->where('id', $id)
            ->where('aliado_id', $aliadoId)
            ->update($data);

        // Las cajas por departamento son de la empresa: solo las cambia la
        // original, y las copias las leen de ella.
        if (! $rs->origen_id) {
            RazonSocialCaja::guardar($id, $cajasDpto);
        }

        // La original pasa sus datos de empresa a los aliados que la usan.
        $copias = $rs->origen_id ? 0 : RazonSocialCompartida::sincronizar($id);

        return redirect()->route('admin.configuracion.razones.index')
            ->with('success', '✅ Razón Social actualizada correctamente.'
                .($copias ? " También en {$copias} aliado".($copias === 1 ? '' : 's').' que la usa'.($copias === 1 ? '' : 'n').'.' : ''));
    }

    // ─── Compartir con otros aliados ──────────────────────────────

    /** En qué aliados está la empresa y cómo (original, copia, sin vincular, no la tiene). */
    public function aliadosCompartida(int $id)
    {
        abort_unless(RazonSocialCompartida::puedeHabilitar(auth()->user()), 403);

        $rs = DB::table('razones_sociales')->where('id', $id)->where('aliado_id', session('aliado_id_activo'))->first();
        abort_if(! $rs, 404);

        return response()->json(RazonSocialCompartida::estadoPorAliado($rs));
    }

    /**
     * Habilita la razón social en los aliados elegidos: crea su copia, o liga
     * la que ya tenían con ese NIT. Solo superadmin de BryNex.
     */
    public function habilitarEnAliados(Request $request, int $id)
    {
        abort_unless(RazonSocialCompartida::puedeHabilitar(auth()->user()), 403);

        $rs = DB::table('razones_sociales')->where('id', $id)->where('aliado_id', session('aliado_id_activo'))->first();
        abort_if(! $rs, 404);

        $data = $request->validate([
            'aliados' => 'nullable|array',
            'aliados.*' => 'integer|exists:aliados,id',
            // aliado_id => true/false: si ve las claves de portales de la empresa
            've_claves' => 'nullable|array',
        ]);

        $aliados = array_map('intval', $data['aliados'] ?? []);
        $veClaves = collect($data['ve_claves'] ?? [])->mapWithKeys(fn ($v, $k) => [(int) $k => filter_var($v, FILTER_VALIDATE_BOOLEAN)]);
        abort_if(! $aliados && $veClaves->isEmpty(), 422, 'Elige al menos un aliado.');

        $nombres = DB::table('aliados')->pluck('nombre', 'id');
        $quien = auth()->user()->nombre;
        $hechos = [];

        foreach ($aliados as $aliadoId) {
            $r = RazonSocialCompartida::habilitar($rs, $aliadoId,
                "Habilitada desde {$rs->razon_social} por {$quien} el ".now()->format('d/m/Y').'.');
            $hechos[] = ($nombres[$aliadoId] ?? "aliado {$aliadoId}").' ('.($r['accion'] === 'creada' ? 'creada' : 'ya la tenía: vinculada').')';
        }

        // Después de habilitar: el permiso de claves es de la copia.
        $claves = [];
        foreach ($veClaves as $aliadoId => $ve) {
            if (RazonSocialCompartida::permitirClaves($rs, $aliadoId, $ve)) {
                $claves[] = ($nombres[$aliadoId] ?? "aliado {$aliadoId}").($ve ? ' ve las claves' : ' no ve las claves');
            }
        }

        return response()->json([
            'success' => true,
            'message' => trim(($hechos ? 'Habilitada en: '.implode(', ', $hechos).'. Falta crearle la sucursal en el operador a cada uno. ' : '')
                .($claves ? 'Claves: '.implode(', ', $claves).'.' : '')),
        ]);
    }

    // ─── Eliminar ─────────────────────────────────────────────────
    public function destroy(int $id)
    {
        $aliadoId = session('aliado_id_activo');

        // Verificar que exista y pertenezca al aliado
        $rs = DB::table('razones_sociales')
            ->where('id', $id)->where('aliado_id', $aliadoId)->first();
        abort_if(!$rs, 404);

        // Verificar que no tenga contratos vigentes
        $tieneVigentes = DB::table('contratos')
            ->where('razon_social_id', $id)
            ->where('aliado_id', $aliadoId)
            ->where('estado', 'vigente')
            ->exists();

        if ($tieneVigentes) {
            return back()->with('error', '⚠️ No se puede eliminar: tiene afiliados con contratos vigentes.');
        }

        // Verificar que nunca haya tenido contratos (si tuvo, solo puede inactivar)
        $tuvoContratos = DB::table('contratos')
            ->where('razon_social_id', $id)
            ->where('aliado_id', $aliadoId)
            ->exists();

        if ($tuvoContratos) {
            return back()->with('error', '⚠️ Esta razón social tuvo contratos asociados. Solo puede inactivarse, no eliminarse.');
        }

        DB::table('razones_sociales')
            ->where('id', $id)
            ->where('aliado_id', $aliadoId)
            ->delete();
        RazonSocialCaja::where('razon_social_id', $id)->delete();

        return redirect()->route('admin.configuracion.razones.index')
            ->with('success', '🗑️ Razón Social eliminada.');
    }

    // ─── Inactivar ────────────────────────────────────────────────
    public function inactivar(int $id)
    {
        $aliadoId = session('aliado_id_activo');

        $rs = DB::table('razones_sociales')
            ->where('id', $id)->where('aliado_id', $aliadoId)->first();
        abort_if(!$rs, 404);

        // Verificar que no tenga contratos vigentes
        $tieneVigentes = DB::table('contratos')
            ->where('razon_social_id', $id)
            ->where('aliado_id', $aliadoId)
            ->where('estado', 'vigente')
            ->exists();

        if ($tieneVigentes) {
            return back()->with('error', '⚠️ No se puede inactivar: tiene afiliados con contratos vigentes.');
        }

        DB::table('razones_sociales')
            ->where('id', $id)
            ->where('aliado_id', $aliadoId)
            ->update(['estado' => 'Inactiva']);

        return back()->with('success', '✅ Razón Social marcada como Inactiva.');
    }

    // ─── API: estado de contratos (para modal JS) ─────────────────
    public function estadoContratos(int $id)
    {
        $aliadoId = session('aliado_id_activo');

        $rs = DB::table('razones_sociales')
            ->where('id', $id)->where('aliado_id', $aliadoId)->first();

        if (!$rs) {
            return response()->json(['error' => 'No encontrada'], 404);
        }

        $vigentes = DB::table('contratos')
            ->where('razon_social_id', $id)
            ->where('aliado_id', $aliadoId)
            ->where('estado', 'vigente')
            ->count();

        $totalHistorico = DB::table('contratos')
            ->where('razon_social_id', $id)
            ->where('aliado_id', $aliadoId)
            ->count();

        return response()->json([
            'razon_social'    => $rs->razon_social,
            'estado'          => $rs->estado,
            'vigentes'        => $vigentes,
            'total_historico' => $totalHistorico,
            'puede_eliminar'  => ($totalHistorico === 0),
            'puede_inactivar' => ($vigentes === 0 && $rs->estado !== 'Inactiva'),
        ]);
    }

    // ─── Subir sello ──────────────────────────────────────────────
    public function subirSello(Request $request, int $id)
    {
        $aliadoId = session('aliado_id_activo');
        $rs = DB::table('razones_sociales')
            ->where('id', $id)
            ->where('aliado_id', $aliadoId)
            ->first();

        abort_if(!$rs, 404);

        $request->validate([
            'sello' => 'required|file|mimes:png,jpg,jpeg,webp|max:5120',
        ], [
            'sello.required' => 'Selecciona una imagen para el sello.',
            'sello.mimes'    => 'El sello debe ser PNG, JPG o WEBP.',
            'sello.max'      => 'El sello no puede pesar más de 5 MB.',
        ]);

        // Nombre de archivo: usar nit si existe, si no el id
        $nit      = $rs->nit ?? $rs->id;
        $destDir  = storage_path('app/sellos');
        $destFile = $destDir . '/' . $nit . '.png';

        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        // Convertir a PNG usando GD (independiente del formato de entrada)
        $archivo = $request->file('sello');
        $mime    = $archivo->getMimeType();

        $img = match (true) {
            str_contains($mime, 'jpeg') => imagecreatefromjpeg($archivo->getRealPath()),
            str_contains($mime, 'webp') => imagecreatefromwebp($archivo->getRealPath()),
            default                     => imagecreatefrompng($archivo->getRealPath()),
        };

        if (!$img) {
            return back()->withErrors(['sello' => 'No se pudo procesar la imagen.']);
        }

        // Preservar transparencia PNG
        imagesavealpha($img, true);
        imagepng($img, $destFile, 6); // compresión 6
        imagedestroy($img);

        return back()->with('success', "✅ Sello guardado como {$nit}.png");
    }

    // ─── Toggle estado rápido (AJAX) ─────────────────────────────
    public function toggleEstado(int $id)
    {
        $aliadoId = session('aliado_id_activo');
        $rs = DB::table('razones_sociales')
            ->where('id', $id)
            ->where('aliado_id', $aliadoId)
            ->first();

        abort_if(!$rs, 404);

        $nuevoEstado = ($rs->estado === 'Activa') ? 'Inactiva' : 'Activa';
        DB::table('razones_sociales')
            ->where('id', $id)
            ->update(['estado' => $nuevoEstado]);

        return back()->with('success', "Estado cambiado a: {$nuevoEstado}");
    }

    // ─── Cajas por departamento ───────────────────────────────────

    /** Las cajas con su departamento, para agruparlas en los selectores. */
    private function cajasCatalogo()
    {
        return DB::table('cajas')->orderBy('nombre')->get(['id', 'nit', 'nombre', 'id_dept']);
    }

    private function departamentos()
    {
        return DB::table('departamentos')->orderBy('nombre')->get(['id', 'nombre']);
    }

    /** Las filas departamento → caja del formulario (ninguna es obligatoria). */
    private function validarCajasDpto(Request $request): array
    {
        return $request->validate([
            'cajas_dpto'                   => 'nullable|array|max:40',
            'cajas_dpto.*.departamento_id' => 'nullable|integer|exists:departamentos,id',
            'cajas_dpto.*.caja_id'         => 'nullable|integer|exists:cajas,id',
        ])['cajas_dpto'] ?? [];
    }

    // ─── Validación ───────────────────────────────────────────────
    private function validar(Request $request, ?int $editId = null): array
    {
        return $request->validate([
            'id'                   => 'nullable|integer|min:1',
            'nit'                  => 'nullable|integer|min:1',
            'dv'                   => 'nullable|integer|min:0|max:9',
            'razon_social'         => 'required|string|max:255',
            'estado'               => 'nullable|in:Activa,Inactiva',
            'plan'                 => 'nullable|string|max:100',
            'direccion'            => 'nullable|string|max:255',
            'telefonos'            => 'nullable|string|max:255',
            'correos'              => 'nullable|string|max:255',
            'actividad_economica'  => 'nullable|string|max:255',
            'objeto_social'        => 'nullable|string|max:500',
            'observacion'          => 'nullable|string|max:500',
            'salario_minimo'       => 'nullable|numeric|min:0',
            'arl_nit'              => 'nullable|integer',
            'caja_nit'             => 'nullable|integer',
            'fecha_constitucion'   => 'nullable|date',
            'fecha_limite_pago'    => 'nullable|integer|min:1|max:31',
            'dia_habil'            => 'nullable|boolean',
            'forma_presentacion'   => 'nullable|string|max:100',
            'codigo_sucursal'      => 'nullable|string|max:50',
            'nombre_sucursal'      => 'nullable|string|max:150',
            'tel_formulario'       => 'nullable|string|max:100',
            'correo_formulario'    => 'nullable|string|max:255',
            'cedula_rep'           => 'nullable|string|max:30',
            'nombre_rep'           => 'nullable|string|max:255',
        ], [
            'id.required'          => 'El ID es obligatorio.',
            'razon_social.required' => 'El nombre es obligatorio.',
        ]);
    }
}
