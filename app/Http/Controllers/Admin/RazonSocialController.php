<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RazonSocial;
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
        $cajas = DB::table('cajas')->orderBy('nombre')->get(['id', 'nit', 'nombre']);
        $rs    = null;

        return view('admin.razones_sociales.form', compact('arls', 'cajas', 'rs'));
    }

    // ─── Guardar ──────────────────────────────────────────────────
    public function store(Request $request)
    {
        $aliadoId = session('aliado_id_activo');

        // Generar automáticamente el siguiente ID interno (PK)
        $nextId = (int) DB::table('razones_sociales')->max('id') + 1;
        $request->merge(['id' => $nextId]);

        $data = $this->validar($request);

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
        $cajas = DB::table('cajas')->orderBy('nombre')->get(['id', 'nit', 'nombre']);

        // Estado de las credenciales de API por operador (sin exponer secretos)
        $operadoresCred = OperadorCredencialController::estadoPorOperador($aliadoId, $id);

        return view('admin.razones_sociales.form', compact('arls', 'cajas', 'rs', 'operadoresCred'));
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
        unset($data['id']); // no cambiar PK en update

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

        $data['es_independiente'] = $request->boolean('es_independiente');

        DB::table('razones_sociales')
            ->where('id', $id)
            ->where('aliado_id', $aliadoId)
            ->update($data);

        return redirect()->route('admin.configuracion.razones.index')
            ->with('success', '✅ Razón Social actualizada correctamente.');
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
