<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CotizacionProspecto;
use App\Models\CotizacionGestion;
use App\Models\Cliente;
use App\Models\Contrato;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CotizacionController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth']);
    }

    public function index(Request $request)
    {
        $aliadoId = session('aliado_id_activo');
        $buscar   = $request->get('buscar');
        $estado   = $request->get('estado');
        $canal    = $request->get('canal');
        $asesorId = $request->get('asesor_id');
        $fechaIni = $request->get('fecha_ini');
        $fechaFin = $request->get('fecha_fin');
        $porLlamar = $request->boolean('llamar');

        $query = CotizacionProspecto::with(['asesor', 'creador', 'plan'])
            ->where('aliado_id', $aliadoId);

        if ($buscar) {
            $query->where(function ($q) use ($buscar) {
                $q->where('cedula', 'LIKE', "%{$buscar}%")
                  ->orWhere('celular', 'LIKE', "%{$buscar}%")
                  ->orWherePalabrasSinTildes(['primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido'], $buscar);
            });
        }

        if ($estado) {
            $query->where('estado', $estado);
        }

        if ($canal) {
            $query->where('canal_origen', $canal);
        }

        if ($asesorId) {
            $query->where('asesor_id', $asesorId);
        }

        if ($fechaIni && $fechaFin) {
            $query->whereBetween('fecha_cotizacion', [$fechaIni, $fechaFin]);
        }

        // "Por llamar": seguimientos abiertos cuya llamada es hoy o ya se venció,
        // la más atrasada primero.
        if ($porLlamar) {
            $query->whereNotIn('estado', CotizacionProspecto::ESTADOS_CERRADOS)
                ->whereDate('proxima_llamada', '<=', today())
                ->orderBy('proxima_llamada');
        }

        $prospectos = $query->orderByDesc('id')->paginate(30)->withQueryString();

        // Totales del aliado para las pestañas (no dependen de los filtros).
        $conteos = CotizacionProspecto::where('aliado_id', $aliadoId)
            ->selectRaw('estado, COUNT(*) as n')
            ->groupBy('estado')
            ->pluck('n', 'estado')
            ->map(fn ($n) => (int) $n);

        $conteoPorLlamar = CotizacionProspecto::where('aliado_id', $aliadoId)
            ->whereNotIn('estado', CotizacionProspecto::ESTADOS_CERRADOS)
            ->whereDate('proxima_llamada', '<=', today())
            ->count();

        $asesores = $this->asesoresDelAliado();
        $estados = CotizacionProspecto::ESTADOS;
        $canales = CotizacionProspecto::CANALES;

        return view('admin.cotizaciones.index', compact(
            'prospectos', 'buscar', 'estado', 'canal', 'asesorId', 'fechaIni', 'fechaFin',
            'asesores', 'estados', 'canales', 'porLlamar', 'conteos', 'conteoPorLlamar'
        ));
    }

    public function create()
    {
        $prospecto = new CotizacionProspecto();
        $prospecto->fecha_cotizacion = now();
        $lookups = $this->getLookups();

        return view('admin.cotizaciones.create', compact('prospecto', 'lookups'));
    }

    public function store(Request $request)
    {
        $data = $this->validarProspecto($request);
        $data['aliado_id'] = session('aliado_id_activo');
        $data['creado_por'] = auth()->id();
        $data['fecha_cotizacion'] = $request->input('fecha_cotizacion', now());
        
        // Separar nombre completo
        if (!empty($data['nombre_completo'])) {
            $partes = explode(' ', trim($data['nombre_completo']));
            $data['primer_nombre'] = $partes[0] ?? null;
            $data['segundo_nombre'] = isset($partes[1]) && count($partes) == 2 ? null : ($partes[1] ?? null);
            $data['primer_apellido'] = count($partes) == 2 ? $partes[1] : (count($partes) >= 3 ? $partes[2] : null);
            $data['segundo_apellido'] = count($partes) >= 4 ? $partes[3] : null;
        }

        // Si es_independiente no viene, asume 0
        $data['es_independiente'] = $request->input('es_independiente', 0);
        $data['resultado_cotizacion'] = $request->input('resultado_cotizacion') ? json_decode($request->input('resultado_cotizacion'), true) : null;

        if (empty($data['estado'])) {
            $data['estado'] = 'sin_respuesta';
        }

        $prospecto = CotizacionProspecto::create($data);

        // Crear automáticamente la gestión inicial de cotización
        $fechaCotizacion = $prospecto->fecha_cotizacion 
            ? ($prospecto->fecha_cotizacion instanceof \Carbon\Carbon ? $prospecto->fecha_cotizacion : \Carbon\Carbon::parse($prospecto->fecha_cotizacion))
            : now();

        CotizacionGestion::create([
            'cotizacion_id'   => $prospecto->id,
            'user_id'         => auth()->id(),
            'tipo_gestion'    => 'Cotización',
            'descripcion'     => 'Cotización registrada automáticamente en el sistema. Fecha en que se solicitó la cotización: ' . $fechaCotizacion->format('d/m/Y') . '.',
            'resultado'       => $prospecto->estado ?? 'sin_respuesta',
            'proxima_llamada' => $prospecto->proxima_llamada,
        ]);

        return redirect()->route('admin.cotizaciones.show', $prospecto->id)
            ->with('success', 'Prospecto creado exitosamente. Verifique la cotización calculada.');
    }

    public function show(int $id)
    {
        $aliadoId = session('aliado_id_activo');
        $prospecto = CotizacionProspecto::with(['gestiones.usuario', 'asesor', 'creador', 'modalidad', 'plan', 'municipio'])
            ->where('aliado_id', $aliadoId)
            ->findOrFail($id);
            
        $cotizacionCalc = $this->calcularCotizacion($prospecto);
        $lookups = $this->getLookups();

        return view('admin.cotizaciones.show', compact('prospecto', 'cotizacionCalc', 'lookups'));
    }

    public function update(Request $request, int $id)
    {
        $aliadoId = session('aliado_id_activo');
        $prospecto = CotizacionProspecto::where('aliado_id', $aliadoId)->findOrFail($id);
        
        $data = $this->validarProspecto($request, $id);

        // Separar nombre completo
        if (!empty($data['nombre_completo'])) {
            $partes = explode(' ', trim($data['nombre_completo']));
            $data['primer_nombre'] = $partes[0] ?? null;
            $data['segundo_nombre'] = isset($partes[1]) && count($partes) == 2 ? null : ($partes[1] ?? null);
            $data['primer_apellido'] = count($partes) == 2 ? $partes[1] : (count($partes) >= 3 ? $partes[2] : null);
            $data['segundo_apellido'] = count($partes) >= 4 ? $partes[3] : null;
        }

        $data['es_independiente'] = $request->input('es_independiente', 0);
        
        if ($request->has('resultado_cotizacion')) {
            $data['resultado_cotizacion'] = $request->input('resultado_cotizacion') ? json_decode($request->input('resultado_cotizacion'), true) : null;
        }

        $prospecto->update($data);

        return redirect()->route('admin.cotizaciones.show', $id)
            ->with('success', 'Prospecto actualizado correctamente.');
    }

    public function registrarGestion(Request $request, int $id)
    {
        $request->validate([
            'tipo_gestion' => 'required|string',
            'descripcion' => 'required|string',
            'resultado' => 'required|string',
            'proxima_llamada' => 'nullable|date',
        ]);

        $aliadoId = session('aliado_id_activo');
        $prospecto = CotizacionProspecto::where('aliado_id', $aliadoId)->findOrFail($id);

        CotizacionGestion::create([
            'cotizacion_id' => $prospecto->id,
            'user_id' => auth()->id(),
            'tipo_gestion' => $request->tipo_gestion,
            'descripcion' => $request->descripcion,
            'resultado' => $request->resultado,
            'proxima_llamada' => $request->proxima_llamada,
        ]);

        $updateData = ['estado' => $request->resultado];
        if ($request->proxima_llamada) {
            $updateData['proxima_llamada'] = $request->proxima_llamada;
        }

        $prospecto->update($updateData);

        return redirect()->route('admin.cotizaciones.show', $id)
            ->with('success', 'Gestión registrada correctamente.');
    }

    public function cotizar(Request $request, int $id)
    {
        // AJAX endpoint para recalcular si cambian datos en vivo
        $aliadoId = session('aliado_id_activo');
        $prospecto = CotizacionProspecto::where('aliado_id', $aliadoId)->findOrFail($id);
        
        // Si vienen datos nuevos, temporalmente asignarlos
        if ($request->has('modalidad_id')) $prospecto->modalidad_id = $request->modalidad_id;
        if ($request->has('plan_id')) $prospecto->plan_id = $request->plan_id;
        if ($request->has('salario_base')) $prospecto->salario_base = $request->salario_base;

        $cotizacionCalc = $this->calcularCotizacion($prospecto);

        return response()->json($cotizacionCalc);
    }

    public function convertirACliente(Request $request, int $id)
    {
        $aliadoId = session('aliado_id_activo');
        $prospecto = CotizacionProspecto::where('aliado_id', $aliadoId)->findOrFail($id);

        if ($prospecto->estado === 'convertido' && $prospecto->cliente_id) {
            return redirect()->route('admin.clientes.edit', $prospecto->cliente_id)
                ->with('info', 'Este prospecto ya fue convertido a cliente.');
        }

        if (!$prospecto->cedula) {
            return redirect()->back()->with('error', 'El prospecto debe tener una cédula para convertirse a cliente.');
        }

        // Buscar si ya existe el cliente
        $clienteExistente = Cliente::where('cedula', $prospecto->cedula)
            ->where('aliado_id', $aliadoId)
            ->first();

        if ($clienteExistente) {
            $prospecto->update(['estado' => 'convertido', 'cliente_id' => $clienteExistente->id]);
            return redirect()->route('admin.clientes.edit', $clienteExistente->id)
                ->with('success', 'El prospecto se vinculó a un cliente existente.');
        }

        // Crear nuevo cliente
        $maxId = DB::table('clientes')->max('id') ?? 0;
        
        $cliente = Cliente::create([
            'id' => $maxId + 1,
            'aliado_id' => $aliadoId,
            'tipo_doc' => $prospecto->tipo_doc,
            'cedula' => $prospecto->cedula,
            'primer_nombre' => $prospecto->primer_nombre,
            'segundo_nombre' => $prospecto->segundo_nombre,
            'primer_apellido' => $prospecto->primer_apellido,
            'segundo_apellido' => $prospecto->segundo_apellido,
            'celular' => $prospecto->celular,
            'correo' => $prospecto->correo,
            'ocupacion' => $prospecto->ocupacion,
            'municipio_id' => $prospecto->municipio_id,
            'referido' => $prospecto->referido,
        ]);

        $prospecto->update(['estado' => 'convertido', 'cliente_id' => $cliente->id]);

        return redirect()->route('admin.clientes.edit', $cliente->id)
            ->with('success', 'Prospecto convertido a cliente exitosamente. Puede completar su contrato ahora.');
    }

    public function descargarPdf(int $id)
    {
        $aliadoId = session('aliado_id_activo');
        $prospecto = CotizacionProspecto::where('aliado_id', $aliadoId)->findOrFail($id);
        $cotizacionCalc = $this->calcularCotizacion($prospecto);
        $aliado = \App\Models\Aliado::find($aliadoId);

        $pdf = \PDF::loadView('pdf.cotizacion_prospecto', compact('prospecto', 'cotizacionCalc', 'aliado'));
        app(\App\Services\TrazaArchivoService::class)->marcarPdf($pdf);
        
        return $pdf->download("Cotizacion_Prospecto_{$prospecto->cedula}.pdf");
    }

    // --- Helpers ---

    private function validarProspecto(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'tipo_doc'            => 'nullable|string|max:10',
            'cedula'              => 'nullable|string|max:20',
            'nombre_completo'     => 'required|string|max:200',
            'primer_nombre'       => 'nullable|string|max:55',
            'segundo_nombre'      => 'nullable|string|max:55',
            'primer_apellido'     => 'nullable|string|max:55',
            'segundo_apellido'    => 'nullable|string|max:55',
            'celular'             => 'required|string|max:20',
            'correo'              => 'nullable|string|max:100',
            'ocupacion'           => 'nullable|string|max:80',
            'es_independiente'    => 'nullable|boolean',
            'municipio_id'        => 'nullable|integer',
            'municipio'           => 'nullable|string|max:100',
            'referido'            => 'nullable|string|max:80',
            'canal_origen'        => 'nullable|string|max:20',
            'modalidad_id'        => 'nullable|integer',
            'plan_id'             => 'nullable|integer',
            'salario_base'        => 'nullable|numeric',
            'fecha_ingreso'       => 'nullable|date',
            'n_arl'               => 'nullable|integer',
            'costo_afiliacion'    => 'nullable|numeric',
            'administracion'      => 'nullable|numeric',
            'estado'              => 'nullable|string|max:20',
            'asesor_id'           => 'nullable|integer',
            'proxima_llamada'     => 'nullable|date',
        ]);
    }

    private function getLookups(): array
    {
        $ciudades = DB::table('ciudades')
            ->orderBy('nombre')
            ->select('id', 'departamento_id', 'nombre')
            ->get();

        $planes = DB::table('planes_contrato')
            ->where('activo', true)
            ->orderBy('nombre')
            ->get();

        $modalidades = DB::table('tipo_modalidad')
            ->where('activo', true)
            ->orderBy('tipo_modalidad')
            ->get();

        $planesPermitidos = DB::table('modalidad_planes')
            ->get()
            ->groupBy('tipo_modalidad_id')
            ->map(fn($rows) => $rows->pluck('plan_id')->values())
            ->toArray();

        $aliadoId = session('aliado_id_activo');
        $cfg = \App\Models\ConfiguracionAliado::paraAliado($aliadoId);

        return [
            'asesores'      => $this->asesoresDelAliado(),
            'ciudades'      => $ciudades,
            'planes'        => $planes,
            'modalidades'   => $modalidades,
            'planesPermitidos' => $planesPermitidos,
            'salarioMinimo' => \App\Models\ConfiguracionBrynex::salarioMinimo(),
            'pctIbcSugerido' => \App\Models\ConfiguracionBrynex::pctIbcIndependienteSugerido(),
            'costo_afiliacion_default' => $cfg ? $cfg->costo_afiliacion : 0,
            'administracion_default' => $cfg ? $cfg->administracion : 0,
            'modalidadesIndependientes' => [10, 13, 14],
            'tipos_doc'     => \App\Models\Cliente::TIPOS_DOC,
            'canales'       => CotizacionProspecto::CANALES,
            'estados'       => CotizacionProspecto::ESTADOS,
        ];
    }

    /** Asesores del aliado activo (id => nombre) para los selectores del módulo. */
    private function asesoresDelAliado(): array
    {
        return DB::table('asesores')
            ->where('aliado_id', session('aliado_id_activo'))
            ->whereNull('deleted_at')
            ->orderBy('nombre')
            ->pluck('nombre', 'id')
            ->toArray();
    }

    private function calcularCotizacion(CotizacionProspecto $prospecto): array
    {
        if (!$prospecto->modalidad_id || !$prospecto->plan_id) {
            return [];
        }

        // Crear un Contrato en memoria para usar su lógica de cotización
        $contratoMock = new Contrato([
            'aliado_id' => $prospecto->aliado_id,
            'tipo_modalidad_id' => $prospecto->modalidad_id,
            'plan_id' => $prospecto->plan_id,
            'salario' => $prospecto->salario_base,
            'n_arl' => 1, // Por defecto riesgo 1
        ]);

        return $contratoMock->calcularCotizacion();
    }
}
