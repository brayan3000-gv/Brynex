<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CotizacionProspecto;
use App\Models\CotizacionGestion;
use App\Models\Cliente;
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
            ->withCount('trabajadores')
            ->where('aliado_id', $aliadoId);

        if ($buscar) {
            $query->where(function ($q) use ($buscar) {
                $q->where('cedula', 'LIKE', "%{$buscar}%")
                  ->orWhere('celular', 'LIKE', "%{$buscar}%")
                  ->orWhere('empresa_nombre', 'LIKE', "%{$buscar}%")
                  ->orWhere('empresa_nit', 'LIKE', "%{$buscar}%")
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
        $trabajadores = $this->trabajadoresDesde($request, $data);

        if (empty($data['estado'])) {
            $data['estado'] = 'sin_respuesta';
        }

        $prospecto = CotizacionProspecto::create($data);
        $this->guardarTrabajadores($prospecto, $trabajadores);

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
        $prospecto = CotizacionProspecto::with(['gestiones.usuario', 'asesor', 'creador', 'modalidad', 'plan', 'municipio', 'trabajadores'])
            ->where('aliado_id', $aliadoId)
            ->findOrFail($id);
            
        $lookups = $this->getLookups();
        $servicio = app(\App\Services\CotizacionProspectoService::class);
        // Dos versiones del mensaje: con el primer mes proporcional y solo con el mes
        // completo; el modal cambia entre las dos según lo que se marque.
        $mensajeWhatsapp = [
            'con' => $servicio->mensajeWhatsapp($prospecto, true),
            'sin' => $servicio->mensajeWhatsapp($prospecto, false),
        ];
        $tieneProporcional = $prospecto->valor_mensual ? $servicio->tieneProporcional($prospecto) : false;
        $whatsappApi = $this->whatsappApiDisponible($prospecto);

        return view('admin.cotizaciones.show', compact('prospecto', 'lookups', 'mensajeWhatsapp', 'tieneProporcional', 'whatsappApi'));
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
        $trabajadores = $this->trabajadoresDesde($request, $data);

        $prospecto->update($data);
        $this->guardarTrabajadores($prospecto, $trabajadores);

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

        return response()->json(app(\App\Services\CotizacionProspectoService::class)->resultado($prospecto));
    }

    /** Manda la cotización (PDF + texto) por la API de WhatsApp del aliado. */
    public function enviarWhatsapp(Request $request, int $id)
    {
        $request->validate(['texto' => 'required|string|max:3000', 'proporcional' => 'nullable|boolean']);

        $aliadoId = session('aliado_id_activo');
        $prospecto = CotizacionProspecto::with('trabajadores')->where('aliado_id', $aliadoId)->findOrFail($id);

        $resultado = app(\App\Services\CotizacionProspectoService::class)
            ->enviarWhatsapp($prospecto, $request->input('texto'), auth()->id(), $request->boolean('proporcional', true));

        return redirect()->route('admin.cotizaciones.show', $id)
            ->with($resultado['ok'] ? 'success' : 'error', $resultado['mensaje']);
    }

    /**
     * Si el PDF puede salir por la API: el aliado la tiene configurada y el número
     * del prospecto tiene una conversación con la ventana de 24 h abierta.
     */
    private function whatsappApiDisponible(CotizacionProspecto $prospecto): array
    {
        $config = \App\Models\WhatsappConfig::paraAliado((int) $prospecto->aliado_id);
        if (! $config->activo || ! $config->credencialesCompletas()) {
            return ['configurado' => false, 'ventana' => false];
        }
        if (! \App\Services\WhatsappApiService::esCelularColombiano((string) $prospecto->celular)) {
            return ['configurado' => true, 'ventana' => false];
        }
        $conversacion = \App\Models\WhatsappConversacion::where('aliado_id', $prospecto->aliado_id)
            ->where('wa_contact_id', \App\Services\WhatsappApiService::normalizarNumero((string) $prospecto->celular))
            ->first();

        return ['configurado' => true, 'ventana' => (bool) $conversacion?->ventanaActiva()];
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
            $this->gestionAutomatica($prospecto, 'Prospecto convertido: se vinculó al cliente existente '.$clienteExistente->cedula.'.');

            return redirect()->route('admin.contratos.create', ['cedula' => $clienteExistente->cedula, 'cotizacion' => $prospecto->id])
                ->with('success', 'El prospecto se vinculó a un cliente existente. El contrato arranca con lo cotizado.');
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
        $this->gestionAutomatica($prospecto, 'Prospecto convertido a cliente.');

        return redirect()->route('admin.contratos.create', ['cedula' => $cliente->cedula, 'cotizacion' => $prospecto->id])
            ->with('success', 'Cliente creado. El contrato arranca con el plan y los valores cotizados: revise y guarde.');
    }

    /** Cambio de estado rápido desde el listado o el detalle. */
    public function cambiarEstado(Request $request, int $id)
    {
        $request->validate([
            'estado' => 'required|in:'.implode(',', array_keys(CotizacionProspecto::ESTADOS)),
            'proxima_llamada' => 'nullable|date',
        ]);

        $aliadoId = session('aliado_id_activo');
        $prospecto = CotizacionProspecto::where('aliado_id', $aliadoId)->findOrFail($id);
        $nuevo = $request->input('estado');

        if ($nuevo === 'convertido') {
            return redirect()->back()->with('error', 'Para pasar a convertido use «Convertir a cliente» en el detalle.');
        }

        $anterior = CotizacionProspecto::ESTADOS[$prospecto->estado] ?? $prospecto->estado;
        $cambios = ['estado' => $nuevo];
        if ($request->filled('proxima_llamada')) {
            $cambios['proxima_llamada'] = $request->input('proxima_llamada');
        } elseif (in_array($nuevo, CotizacionProspecto::ESTADOS_CERRADOS)) {
            $cambios['proxima_llamada'] = null;
        }
        $prospecto->update($cambios);

        CotizacionGestion::create([
            'cotizacion_id' => $prospecto->id,
            'user_id' => auth()->id(),
            'tipo_gestion' => 'Nota',
            'descripcion' => 'Estado cambiado de «'.$anterior.'» a «'.CotizacionProspecto::ESTADOS[$nuevo].'» desde el listado.',
            'resultado' => $nuevo,
            'proxima_llamada' => $cambios['proxima_llamada'] ?? null,
        ]);

        return redirect()->back()->with('success', 'Estado actualizado: '.CotizacionProspecto::ESTADOS[$nuevo].'.');
    }

    private function gestionAutomatica(CotizacionProspecto $prospecto, string $texto): void
    {
        CotizacionGestion::create([
            'cotizacion_id' => $prospecto->id,
            'user_id' => auth()->id(),
            'tipo_gestion' => 'Nota',
            'descripcion' => $texto,
            'resultado' => $prospecto->estado,
            'proxima_llamada' => null,
        ]);
    }

    public function descargarPdf(Request $request, int $id)
    {
        $aliadoId = session('aliado_id_activo');
        $prospecto = CotizacionProspecto::with('trabajadores')->where('aliado_id', $aliadoId)->findOrFail($id);
        $servicio = app(\App\Services\CotizacionProspectoService::class);

        // ?proporcional=0 saca la cotización solo con el mes completo.
        return $servicio->pdf($prospecto, $request->boolean('proporcional', true))
            ->download($servicio->nombreArchivoPdf($prospecto));
    }

    // --- Helpers ---

    private function validarProspecto(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'tipo'                => 'nullable|in:persona,empresa',
            'empresa_nombre'      => 'required_if:tipo,empresa|nullable|string|max:200',
            'empresa_nit'         => 'nullable|string|max:20',
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
        ], [
            'empresa_nombre.required_if' => 'Escriba el nombre de la empresa que se cotiza.',
        ]);
    }

    /**
     * Trabajadores de una cotización de empresa, tal como los manda el formulario
     * (JSON en `trabajadores`). Para una persona devuelve una lista vacía y deja
     * los campos de empresa en blanco.
     */
    private function trabajadoresDesde(Request $request, array &$data): array
    {
        $data['tipo'] = ($data['tipo'] ?? 'persona') === 'empresa' ? 'empresa' : 'persona';

        if ($data['tipo'] !== 'empresa') {
            $data['empresa_nombre'] = null;
            $data['empresa_nit'] = null;

            return [];
        }

        // Una empresa no tiene un plan único: lo que se cotiza está en cada trabajador.
        $data['modalidad_id'] = null;
        $data['plan_id'] = null;
        $data['salario_base'] = null;
        $data['es_independiente'] = 0;

        $lista = json_decode((string) $request->input('trabajadores'), true);
        if (! is_array($lista) || $lista === []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'trabajadores' => 'Agregue al menos un trabajador con su cargo y su plan.',
            ]);
        }

        $trabajadores = [];
        foreach (array_values($lista) as $i => $t) {
            $cargo = trim((string) ($t['cargo'] ?? ''));
            // La modalidad «Dependiente E» tiene id 0: no sirve empty() aquí.
            $sinDato = fn ($v) => $v === null || $v === '';
            if ($cargo === '' || $sinDato($t['modalidad_id'] ?? null) || $sinDato($t['plan_id'] ?? null)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'trabajadores' => 'El trabajador '.($i + 1).' necesita cargo, modalidad y plan.',
                ]);
            }
            $trabajadores[] = [
                'orden' => $i + 1,
                'cargo' => mb_substr($cargo, 0, 100),
                'nombre' => mb_substr(trim((string) ($t['nombre'] ?? '')), 0, 150) ?: null,
                'modalidad_id' => (int) $t['modalidad_id'],
                'plan_id' => (int) $t['plan_id'],
                'salario' => (int) ($t['salario'] ?? 0),
                'n_arl' => max(1, min(5, (int) ($t['n_arl'] ?? 1))),
                'resultado' => [
                    'completo' => is_array($t['completo'] ?? null) ? $t['completo'] : null,
                    'proporcional' => is_array($t['proporcional'] ?? null) ? $t['proporcional'] : null,
                ],
            ];
        }

        return $trabajadores;
    }

    /** Reemplaza los trabajadores del prospecto por los que llegaron del formulario. */
    private function guardarTrabajadores(CotizacionProspecto $prospecto, array $trabajadores): void
    {
        DB::transaction(function () use ($prospecto, $trabajadores) {
            $prospecto->trabajadores()->delete();
            foreach ($trabajadores as $t) {
                $prospecto->trabajadores()->create($t + ['aliado_id' => $prospecto->aliado_id]);
            }
        });
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
        return Cliente::listaAsesores();
    }
}
