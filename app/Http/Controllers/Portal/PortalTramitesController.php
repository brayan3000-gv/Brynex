<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\EmpresaAcceso;
use App\Models\EmpresaSolicitud;
use App\Models\Incapacidad;
use App\Models\PlanContrato;
use App\Models\Tarea;
use App\Services\EmpresaSolicitudService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Trámites del portal: lo que la empresa pide (ingresos, retiros,
 * incapacidades, otras solicitudes) y en qué va cada cosa.
 *
 * La empresa nunca cambia datos: cada envío abre una tarea que el equipo
 * revisa y ejecuta (ver EmpresaSolicitudService).
 */
class PortalTramitesController extends Controller
{
    /** Asuntos de «otra solicitud»: ayudan a que el equipo sepa por dónde empezar. */
    public const ASUNTOS = [
        'traslado_eps' => 'Traslado de EPS',
        'beneficiarios' => 'Inclusión de beneficiarios',
        'certificado' => 'Certificado de afiliación o de aportes',
        'correccion' => 'Corrección de datos de un trabajador',
        'cambio_salario' => 'Cambio de salario o de plan',
        'otro' => 'Otro',
    ];

    private const ARCHIVO = 'file|max:10240|mimes:pdf,jpg,jpeg,png,webp';

    public function __construct(private EmpresaSolicitudService $solicitudes) {}

    // ─── Lista ───────────────────────────────────────────────────────────

    /**
     * Las tareas de la empresa: las que abrió ella y las que el equipo tiene
     * sobre sus trabajadores. De las segundas no se muestra la descripción,
     * que la escribe el equipo para sí; solo el tipo y los avances visibles.
     */
    public function index()
    {
        $acceso = $this->acceso();

        $tareas = Tarea::where('aliado_id', $acceso->aliado_id)
            ->where(fn ($q) => $q->where('empresa_id', $acceso->empresa_id)
                ->orWhere(fn ($w) => $w->whereNull('empresa_id')
                    ->whereIn('cedula', $this->subconsultaCedulas($acceso))))
            ->with([
                'gestiones' => fn ($q) => $q->where('visible_empresa', true),
                'solicitudEmpresa',
            ])
            ->orderByDesc('id')
            ->limit(300)
            ->get();

        $nombres = $this->nombres($acceso, $tareas->pluck('cedula')->filter());

        $filas = $tareas->map(function (Tarea $t) use ($acceso, $nombres) {
            $propia = (int) $t->empresa_id === (int) $acceso->empresa_id;
            $s = $t->solicitudEmpresa;

            return [
                'id' => $t->id,
                'propia' => $propia,
                'tipo' => $s ? $s->tipoLabel() : preg_replace('/^\S+\s/u', '', $t->tipoLabel()),
                'asunto' => $s && $s->tipo === 'otra' ? (self::ASUNTOS[$s->datos['asunto'] ?? ''] ?? null) : null,
                'trabajador' => $t->cedula ? ($nombres[$t->cedula] ?? $t->cedula) : null,
                // La escribió la misma empresa; la de una tarea interna no se muestra.
                'descripcion' => $propia ? $t->tarea : null,
                'abierta' => $t->estado !== Tarea::ESTADO_CERRADA,
                'estado' => $this->estadoParaEmpresa($t, $s),
                'creada' => $t->created_at,
                'avances' => $t->gestiones->sortBy('id')->map(fn ($g) => [
                    'fecha' => $g->created_at,
                    'texto' => $g->observacion,
                ])->values(),
                'archivos' => $s ? collect($s->datos['archivos'] ?? [])->map(fn ($a, $i) => [
                    'nombre' => $a['nombre'],
                    'url' => route('portal.tramites.archivo', [$s->id, $i]),
                ])->values() : [],
            ];
        });

        return view('portal.tramites', ['filas' => $filas]);
    }

    // ─── Formularios ─────────────────────────────────────────────────────

    public function nuevo(Request $request)
    {
        $acceso = $this->acceso();
        $tipo = $request->query('tipo');

        if (! array_key_exists($tipo, EmpresaSolicitud::TIPOS)) {
            return view('portal.tramite_elegir');
        }

        return view('portal.tramite_nuevo', [
            'tipo' => $tipo,
            'trabajadores' => in_array($tipo, ['retiro', 'incapacidad', 'otra'], true) ? $this->trabajadores($acceso) : collect(),
            'contratoElegido' => (int) $request->query('contrato'),
            'planes' => $tipo === 'ingreso' ? PlanContrato::where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'descripcion']) : collect(),
            'departamentos' => $tipo === 'ingreso' ? DB::table('departamentos')->orderBy('nombre')->pluck('nombre', 'id') : collect(),
            'ciudades' => $tipo === 'ingreso' ? DB::table('ciudades')->orderBy('nombre')->get(['id', 'departamento_id', 'nombre']) : collect(),
            'tiposDoc' => Cliente::TIPOS_DOC,
            'tiposIncapacidad' => Incapacidad::TIPOS_INCAPACIDAD,
            'asuntos' => self::ASUNTOS,
        ]);
    }

    public function guardar(Request $request, string $tipo)
    {
        abort_unless(array_key_exists($tipo, EmpresaSolicitud::TIPOS), 404);
        $acceso = $this->acceso();

        $solicitud = match ($tipo) {
            'ingreso' => $this->guardarIngreso($request, $acceso),
            'retiro' => $this->guardarRetiro($request, $acceso),
            'incapacidad' => $this->guardarIncapacidad($request, $acceso),
            'otra' => $this->guardarOtra($request, $acceso),
        };

        return redirect()->route('portal.tramites')
            ->with('ok', 'Enviamos tu solicitud de «'.mb_strtolower($solicitud->tipoLabel()).'». Aquí ves en qué va.');
    }

    private function guardarIngreso(Request $request, EmpresaAcceso $acceso): EmpresaSolicitud
    {
        $d = $request->validate([
            'tipo_doc' => ['required', Rule::in(array_keys(Cliente::TIPOS_DOC))],
            'cedula' => 'required|digits_between:4,15',
            'primer_nombre' => 'required|string|max:60',
            'segundo_nombre' => 'nullable|string|max:60',
            'primer_apellido' => 'required|string|max:60',
            'segundo_apellido' => 'nullable|string|max:60',
            'fecha_nacimiento' => 'required|date|before:-14 years',
            'genero' => 'required|in:M,F',
            'celular' => ['required', 'regex:/^3\d{9}$/'],
            'correo' => 'nullable|email|max:120',
            'departamento_id' => 'nullable|integer',
            'municipio_id' => 'nullable|integer',
            'direccion' => 'nullable|string|max:150',
            'plan_id' => ['required', Rule::exists('planes_contrato', 'id')->where('activo', true)],
            'fecha_ingreso' => 'required|date|after:-60 days|before:+90 days',
            'cargo' => 'required|string|max:100',
            'observacion' => 'nullable|string|max:1000',
            'doc_cedula' => 'required|'.self::ARCHIVO,
        ], $this->mensajes(), [
            'fecha_nacimiento' => 'fecha de nacimiento',
            'doc_cedula' => 'documento de identidad',
            'plan_id' => 'plan',
        ]);

        $d['departamento_id'] = ((int) ($d['departamento_id'] ?? 0)) ?: null;
        $d['municipio_id'] = ((int) ($d['municipio_id'] ?? 0)) ?: null;
        foreach (['primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido'] as $k) {
            $d[$k] = isset($d[$k]) ? mb_strtoupper(trim($d[$k])) : null;
        }
        $nombre = trim(preg_replace('/\s+/', ' ', "{$d['primer_nombre']} {$d['segundo_nombre']} {$d['primer_apellido']} {$d['segundo_apellido']}"));
        $plan = PlanContrato::find($d['plan_id']);
        $fecha = Carbon::parse($d['fecha_ingreso'])->format('d/m/Y');

        return $this->solicitudes->crear(
            $acceso, 'ingreso',
            collect($d)->except('doc_cedula')->put('plan_nombre', $plan?->nombre)->put('nombre', $nombre)->all(),
            ['cedula' => $request->file('doc_cedula')],
            "Ingreso solicitado por {$acceso->empresa->empresa}: {$nombre} ({$d['tipo_doc']} {$d['cedula']}). "
                ."Plan: {$plan?->nombre}. Ingreso: {$fecha}. Cargo: {$d['cargo']}."
                .($d['observacion'] ? "\nNota de la empresa: {$d['observacion']}" : ''),
            null, $d['cedula'], $d['tipo_doc'],
        );
    }

    private function guardarRetiro(Request $request, EmpresaAcceso $acceso): EmpresaSolicitud
    {
        $d = $request->validate([
            'contrato_id' => 'required|integer',
            'fecha_retiro' => 'required|date|after:-90 days|before:+60 days',
            'motivo' => 'required|string|max:300',
            'soporte' => 'nullable|'.self::ARCHIVO,
        ], $this->mensajes(), ['fecha_retiro' => 'fecha de retiro']);

        $contrato = $this->contratoActivo($acceso, (int) $d['contrato_id']);
        $nombre = $this->nombreDe($acceso, $contrato->cedula);
        $fecha = Carbon::parse($d['fecha_retiro'])->format('d/m/Y');

        return $this->solicitudes->crear(
            $acceso, 'retiro',
            ['fecha_retiro' => $d['fecha_retiro'], 'motivo' => $d['motivo'], 'nombre' => $nombre],
            ['soporte' => $request->file('soporte')],
            "Retiro solicitado por {$acceso->empresa->empresa}: {$nombre} (C.C. {$contrato->cedula}). "
                ."Fecha de retiro: {$fecha}. Motivo: {$d['motivo']}",
            $contrato,
        );
    }

    private function guardarIncapacidad(Request $request, EmpresaAcceso $acceso): EmpresaSolicitud
    {
        $d = $request->validate([
            'contrato_id' => 'required|integer',
            'tipo_incapacidad' => ['required', Rule::in(array_keys(Incapacidad::TIPOS_INCAPACIDAD))],
            'fecha_inicio' => 'required|date|after:-1 year|before:+30 days',
            'dias' => 'required|integer|min:1|max:540',
            'descripcion' => 'nullable|string|max:1000',
            'doc_incapacidad' => 'required|'.self::ARCHIVO,
            'doc_otros' => 'nullable|array|max:5',
            'doc_otros.*' => self::ARCHIVO,
        ], $this->mensajes(), [
            'doc_incapacidad' => 'certificado de incapacidad',
            'dias' => 'días',
        ]);

        $contrato = $this->contratoActivo($acceso, (int) $d['contrato_id'], permitirRetirado: true);
        $nombre = $this->nombreDe($acceso, $contrato->cedula);
        $tipo = Incapacidad::TIPOS_INCAPACIDAD[$d['tipo_incapacidad']];
        $fecha = Carbon::parse($d['fecha_inicio'])->format('d/m/Y');

        return $this->solicitudes->crear(
            $acceso, 'incapacidad',
            collect($d)->except(['doc_incapacidad', 'doc_otros'])->put('nombre', $nombre)->all(),
            ['incapacidad' => $request->file('doc_incapacidad'), 'otro' => $request->file('doc_otros', [])],
            "Incapacidad reportada por {$acceso->empresa->empresa}: {$nombre} (C.C. {$contrato->cedula}). "
                ."{$tipo}, {$d['dias']} días desde {$fecha}."
                .(! empty($d['descripcion']) ? "\nLo que cuenta la empresa: {$d['descripcion']}" : ''),
            $contrato,
        );
    }

    private function guardarOtra(Request $request, EmpresaAcceso $acceso): EmpresaSolicitud
    {
        $d = $request->validate([
            'asunto' => ['required', Rule::in(array_keys(self::ASUNTOS))],
            'contrato_id' => 'nullable|integer',
            'descripcion' => 'required|string|max:2000',
            'adjunto' => 'nullable|'.self::ARCHIVO,
        ], $this->mensajes());

        $contrato = ! empty($d['contrato_id']) ? $this->contratoActivo($acceso, (int) $d['contrato_id'], permitirRetirado: true) : null;
        $nombre = $contrato ? $this->nombreDe($acceso, $contrato->cedula) : null;

        return $this->solicitudes->crear(
            $acceso, 'otra',
            ['asunto' => $d['asunto'], 'descripcion' => $d['descripcion'], 'nombre' => $nombre],
            ['adjunto' => $request->file('adjunto')],
            self::ASUNTOS[$d['asunto']].' — pedido por '.$acceso->empresa->empresa
                .($nombre ? " para {$nombre} (C.C. {$contrato->cedula})" : '').":\n{$d['descripcion']}",
            $contrato,
        );
    }

    // ─── Adjuntos ────────────────────────────────────────────────────────

    /** Un adjunto que la misma empresa subió. */
    public function archivo(int $solicitudId, int $i)
    {
        $acceso = $this->acceso();
        $solicitud = EmpresaSolicitud::where('aliado_id', $acceso->aliado_id)
            ->where('empresa_id', $acceso->empresa_id)
            ->findOrFail($solicitudId);

        $archivo = $solicitud->archivo($i);
        abort_unless($archivo && Storage::disk(EmpresaSolicitudService::DISCO)->exists($archivo['ruta']), 404);

        return Storage::disk(EmpresaSolicitudService::DISCO)->response($archivo['ruta'], $archivo['nombre']);
    }

    // ─── Apoyo ───────────────────────────────────────────────────────────

    private function acceso(): EmpresaAcceso
    {
        return Auth::guard('empresa')->user();
    }

    private function subconsultaCedulas(EmpresaAcceso $acceso): \Closure
    {
        return fn ($q) => $q->select('cedula')->from('clientes')
            ->where('aliado_id', $acceso->aliado_id)
            ->where('cod_empresa', $acceso->empresa_id);
    }

    /**
     * Un contrato de un trabajador de la empresa. El id llega del formulario:
     * se busca siempre dentro de sus cédulas y del aliado.
     */
    private function contratoActivo(EmpresaAcceso $acceso, int $id, bool $permitirRetirado = false): Contrato
    {
        $contrato = Contrato::where('aliado_id', $acceso->aliado_id)
            ->whereIn('cedula', $this->subconsultaCedulas($acceso))
            ->when(! $permitirRetirado, fn ($q) => $q->whereIn('estado', ['vigente', 'activo']))
            ->find($id);

        if (! $contrato) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'contrato_id' => 'Escoge un trabajador de la lista.',
            ]);
        }

        return $contrato;
    }

    /** Los trabajadores con contrato vigente, para escoger en los formularios. */
    private function trabajadores(EmpresaAcceso $acceso)
    {
        $contratos = Contrato::where('aliado_id', $acceso->aliado_id)
            ->whereIn('cedula', $this->subconsultaCedulas($acceso))
            ->whereIn('estado', ['vigente', 'activo'])
            ->with('plan:id,nombre')
            ->get(['id', 'cedula', 'plan_id', 'fecha_ingreso']);

        $nombres = $this->nombres($acceso, $contratos->pluck('cedula'));

        return $contratos
            ->map(fn ($c) => [
                'id' => $c->id,
                'nombre' => $nombres[$c->cedula] ?? $c->cedula,
                'detalle' => trim($c->cedula.' · '.($c->plan?->nombre ?? '')),
            ])
            ->sortBy('nombre', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    private function nombreDe(EmpresaAcceso $acceso, string $cedula): string
    {
        return $this->nombres($acceso, collect([$cedula]))[$cedula] ?? $cedula;
    }

    private function nombres(EmpresaAcceso $acceso, $cedulas): array
    {
        $cedulas = collect($cedulas)->unique()->values();
        if ($cedulas->isEmpty()) {
            return [];
        }

        return DB::table('clientes')
            ->where('aliado_id', $acceso->aliado_id)
            ->where('cod_empresa', $acceso->empresa_id)
            ->whereIn('cedula', $cedulas->all())
            ->get(['cedula', 'primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido'])
            ->mapWithKeys(fn ($c) => [$c->cedula => trim(preg_replace('/\s+/', ' ',
                "{$c->primer_nombre} {$c->segundo_nombre} {$c->primer_apellido} {$c->segundo_apellido}"))])
            ->all();
    }

    /** El estado en palabras de la empresa. */
    private function estadoParaEmpresa(Tarea $t, ?EmpresaSolicitud $s): array
    {
        if ($t->estado === Tarea::ESTADO_CERRADA) {
            return $t->resultado === 'negativo' ? ['No procedió', 'chip-err'] : ['Resuelta', 'chip-ok'];
        }

        if ($s && ! $s->vista_at) {
            return ['Recibida', 'chip-info'];
        }

        return ['En trámite', 'chip-warn'];
    }

    private function mensajes(): array
    {
        return [
            'required' => 'Falta :attribute.',
            'celular.regex' => 'El celular debe tener 10 dígitos y empezar por 3.',
            'fecha_nacimiento.before' => 'Revisa la fecha de nacimiento.',
            'fecha_ingreso.after' => 'La fecha de ingreso no puede ser de hace más de 60 días.',
            'fecha_ingreso.before' => 'La fecha de ingreso no puede ser en más de 90 días.',
            'mimes' => 'El archivo debe ser PDF, JPG o PNG.',
            'max' => ':attribute es muy grande.',
            'doc_cedula.max' => 'El documento no puede pasar de 10 MB.',
            'doc_incapacidad.max' => 'El certificado no puede pasar de 10 MB.',
        ];
    }
}
