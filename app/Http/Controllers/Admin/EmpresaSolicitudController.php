<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contrato;
use App\Models\EmpresaSolicitud;
use App\Models\Radicado;
use App\Services\EmpresaSolicitudService;
use App\Services\RegistroOficialService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Lo que el equipo hace con las solicitudes del portal de empresas desde el
 * detalle de la tarea: ver adjuntos, consultar el RUAF, ejecutar el retiro o
 * la incapacidad, o rechazarla. El ingreso se aprueba al crear el contrato
 * desde el formulario de siempre (ContratoController::store).
 *
 * Todo se busca dentro del aliado activo: el id llega en la URL.
 */
class EmpresaSolicitudController extends Controller
{
    public function __construct(private EmpresaSolicitudService $servicio) {}

    /**
     * El tablero del portal: qué empresas tienen acceso, cuándo entraron por
     * última vez y los trámites que tienen abiertos. Se llega con el botón
     * «Portal empresas» del listado de Empresas; atender cada trámite sigue
     * siendo en Tareas.
     */
    public function index()
    {
        $aliadoId = (int) session('aliado_id_activo');

        $abiertasPorEmpresa = DB::table('tareas')
            ->where('aliado_id', $aliadoId)
            ->whereNotNull('empresa_id')
            ->where('estado', '!=', \App\Models\Tarea::ESTADO_CERRADA)
            ->whereNull('deleted_at')
            ->groupBy('empresa_id')
            ->selectRaw('empresa_id, COUNT(*) as n')
            ->pluck('n', 'empresa_id');

        $accesos = \App\Models\EmpresaAcceso::where('aliado_id', $aliadoId)
            ->with('empresa:id,empresa,nit')
            ->get()
            ->sortBy(fn ($a) => mb_strtolower($a->empresa?->empresa ?? ''))
            ->values();

        $abiertas = \App\Models\Tarea::where('aliado_id', $aliadoId)
            ->whereNotNull('empresa_id')
            ->where('estado', '!=', \App\Models\Tarea::ESTADO_CERRADA)
            ->with(['solicitudEmpresa', 'encargado:id,nombre'])
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $empresas = \App\Models\Empresa::whereIn('id', $abiertas->pluck('empresa_id')->unique())->pluck('empresa', 'id');

        return view('admin.portal_empresas.index', [
            'accesos' => $accesos,
            'abiertasPorEmpresa' => $abiertasPorEmpresa,
            'abiertas' => $abiertas,
            'empresas' => $empresas,
            'nuevas' => $this->servicio->nuevasPara(Auth::user(), $aliadoId)['total'],
        ]);
    }

    /** Para el globo y el aviso emergente del panel. */
    public function nuevas()
    {
        return response()->json(
            $this->servicio->nuevasPara(Auth::user(), (int) session('aliado_id_activo'))
        );
    }

    public function archivo(int $id, int $i)
    {
        $s = $this->solicitud($id);
        $archivo = $s->archivo($i);
        abort_unless($archivo && Storage::disk(EmpresaSolicitudService::DISCO)->exists($archivo['ruta']), 404);

        return Storage::disk(EmpresaSolicitudService::DISCO)->response($archivo['ruta'], $archivo['nombre']);
    }

    /**
     * EPS y fondo de pensión de la persona según el RUAF, para crear la ficha
     * con ellos. Solo lo ve el equipo: la empresa no consulta el RUAF.
     */
    public function ruaf(int $id)
    {
        $s = $this->solicitud($id);
        abort_unless($s->tipo === 'ingreso', 404);

        try {
            $r = app(RegistroOficialService::class)->consultar($s->aliado_id, (string) $s->cedula, $s->tipo_doc ?: 'CC');
        } catch (\Throwable $e) {
            Log::warning('Portal: RUAF de la solicitud falló', ['solicitud' => $s->id, 'error' => $e->getMessage()]);
            $r = null;
        }

        if (! $r) {
            return response()->json(['ok' => false, 'mensaje' => 'El RUAF no respondió. Puedes crear la ficha y poner la EPS a mano.']);
        }

        $resumen = collect($r)->only(['encontrado', 'eps_id', 'eps_nombre', 'pension_id', 'pension_nombre', 'estado', 'regimen'])->all();
        $s->forceFill(['ruaf' => $resumen])->save();

        return response()->json(['ok' => true] + $resumen);
    }

    /**
     * Deja el retiro como retiro pendiente del contrato, el mismo que se marca
     * desde la facturación de la empresa: sale en la factura de ese mes.
     */
    public function retiro(Request $request, int $id)
    {
        $s = $this->pendiente($id, 'retiro');
        $datos = $request->validate([
            'fecha_retiro' => 'required|date',
            'cobrar_admon' => 'required|boolean',
        ]);

        $sub = Request::create('/', 'POST', $datos);
        $sub->setLaravelSession($request->session());
        $respuesta = app(FacturacionController::class)->guardarRetiroPendiente($sub, (int) $s->contrato_id);
        $json = $respuesta->getData(true);

        if (! ($json['ok'] ?? false)) {
            return response()->json(['ok' => false, 'mensaje' => $json['mensaje'] ?? 'No se pudo registrar el retiro.'], 422);
        }

        $fecha = Carbon::parse($datos['fecha_retiro'])->format('d/m/Y');
        $this->servicio->resolver($s, 'aprobada', "Registramos el retiro con fecha {$fecha}. Se liquida en la facturación de ese mes.", (int) $s->contrato_id);

        return response()->json(['ok' => true, 'mensaje' => 'Retiro registrado y solicitud aprobada.']);
    }

    /**
     * Registra la incapacidad con el alta de siempre (IncapacidadController::store)
     * y le pasa los documentos que subió la empresa.
     */
    public function incapacidad(Request $request, int $id)
    {
        $s = $this->pendiente($id, 'incapacidad');
        $d = $s->datos ?? [];
        $contrato = Contrato::where('aliado_id', $s->aliado_id)->with('razonSocial')->findOrFail($s->contrato_id);

        $tipoEntidad = $request->validate(['tipo_entidad' => 'required|in:eps,arl'])['tipo_entidad'];
        $entidadId = $tipoEntidad === 'eps'
            ? $contrato->eps_id
            : ($contrato->arl_id ?: DB::table('arls')
                ->where(DB::raw('CAST(nit AS VARCHAR(20))'), (string) $contrato->razonSocial?->arl_nit)
                ->value('id'));

        $sub = Request::create('/', 'POST', [
            'cedula_usuario' => $contrato->cedula,
            'contrato_id' => $contrato->id,
            'tipo_incapacidad' => $d['tipo_incapacidad'] ?? 'enfermedad_general',
            'tipo_entidad' => $tipoEntidad,
            'entidad_responsable_id' => $entidadId,
            'razon_social_id' => $contrato->razon_social_id,
            'dias_incapacidad' => (int) ($d['dias'] ?? 1),
            'fecha_inicio' => $d['fecha_inicio'] ?? null,
            'fecha_recibido' => $s->created_at->toDateString(),
            'quien_recibe_id' => Auth::id(),
            'descripcion_cliente' => $d['descripcion'] ?? null,
            'observacion' => 'Reportada por '.$s->empresa?->empresa.' desde su portal.',
        ]);
        $sub->headers->set('Accept', 'application/json');
        $sub->setLaravelSession($request->session());

        $json = app(IncapacidadController::class)->store($sub)->getData(true);
        $incapacidadId = (int) ($json['incapacidad_id'] ?? 0);
        abort_unless($incapacidadId, 500, 'No se pudo registrar la incapacidad.');

        // Los adjuntos pasan a la incapacidad como documentos del cliente, en
        // la misma carpeta y tabla que usa la subida por enlace.
        foreach ($d['archivos'] ?? [] as $a) {
            $destino = "incapacidades/{$s->aliado_id}/{$contrato->cedula}/{$incapacidadId}/cliente/".basename($a['ruta']);
            $disco = Storage::disk(EmpresaSolicitudService::DISCO);
            if (! $disco->exists($a['ruta'])) {
                continue;
            }
            $disco->copy($a['ruta'], $destino);
            Radicado::create([
                'incapacidad_id' => $incapacidadId,
                'aliado_id' => $s->aliado_id,
                'contrato_id' => $contrato->id,
                'tipo' => 'incapacidad',
                'tipo_documento' => $a['etiqueta'] === 'incapacidad' ? 'incapacidad' : 'otro',
                'estado' => 'ok',
                'observacion' => 'Subido por la empresa desde el portal: '.$a['nombre'],
                'ruta_pdf' => $destino,
                'user_id' => null,
                'enviado_al_cliente' => false,
            ]);
        }

        $this->servicio->resolver($s, 'aprobada',
            'Registramos la incapacidad y la vamos a radicar ante la '.strtoupper($tipoEntidad).'. En Incapacidades ves en qué va el cobro.',
            $incapacidadId);

        return response()->json([
            'ok' => true,
            'mensaje' => 'Incapacidad registrada.',
            'url' => route('admin.incapacidades.index', ['cedula' => $contrato->cedula, 'abrir_incId' => $incapacidadId]),
        ]);
    }

    public function rechazar(Request $request, int $id)
    {
        $s = $this->pendiente($id);
        $motivo = $request->validate(['motivo' => 'required|string|max:500'])['motivo'];

        $this->servicio->resolver($s, 'rechazada', 'No pudimos tramitarla: '.$motivo);

        return response()->json(['ok' => true, 'mensaje' => 'Solicitud rechazada. La empresa verá el motivo.']);
    }

    private function solicitud(int $id): EmpresaSolicitud
    {
        return EmpresaSolicitud::where('aliado_id', session('aliado_id_activo'))->findOrFail($id);
    }

    private function pendiente(int $id, ?string $tipo = null): EmpresaSolicitud
    {
        $s = $this->solicitud($id);
        abort_if($tipo && $s->tipo !== $tipo, 404);
        abort_unless($s->estaPendiente(), 422, 'Esta solicitud ya fue atendida.');

        return $s;
    }
}
