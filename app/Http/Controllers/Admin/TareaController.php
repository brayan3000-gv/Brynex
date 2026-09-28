<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tarea;
use App\Models\TareaDocumento;
use App\Models\TareaGestion;
use App\Models\TareaSemaforoConfig;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TareaController extends Controller
{
    /**
     * Columnas ordenables desde los encabezados de la tabla.
     * Whitelist: la clave llega por URL y no puede ir directo al orderBy.
     */
    const ORDENES = [
        'creada' => 'tareas.created_at',
        'tipo' => 'tareas.tipo',
        'cliente' => 'tareas.cedula',
        'encargado' => 'tareas.encargado_id',
        'estado' => 'tareas.estado',
        'limite' => 'tareas.fecha_limite',
    ];

    // ── INDEX ───────────────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $alidoId = session('aliado_id_activo') ?? Auth::user()->aliado_id;
        $user = Auth::user();

        $query = Tarea::with(['encargado', 'creadoPor', 'razonSocial', 'cliente', 'ultimaGestion.user', 'empresa:id,empresa'])
            ->withCount(['documentos', 'gestiones'])
            ->where('aliado_id', $alidoId);

        // Filtros
        $this->filtrosComunes($query, $request, 'tareas');

        // Empresa del cliente (clientes.cod_empresa). La tarea guarda la cédula,
        // así que se resuelve con una subconsulta scopeada por aliado: la
        // relación cliente() cruza solo por cédula y no filtra por aliado.
        // También las que abrió la empresa desde su portal (tareas.empresa_id),
        // que pueden no tener cédula.
        if ($request->filled('empresa_id')) {
            $query->where(fn ($q) => $q->where('tareas.empresa_id', (int) $request->empresa_id)
                ->orWhereIn('tareas.cedula', function ($sub) use ($request, $alidoId) {
                    $sub->from('clientes')
                        ->select('cedula')
                        ->where('aliado_id', $alidoId)
                        ->where('cod_empresa', $request->empresa_id);
                }));
        }

        // Orden: si el usuario hizo clic en un encabezado manda su criterio;
        // si no, el orden por urgencia de siempre (rojo > naranja > amarillo >
        // verde > cerradas).
        $orden = $request->get('orden');
        $dir = strtolower((string) $request->get('dir')) === 'desc' ? 'desc' : 'asc';

        if (isset(self::ORDENES[$orden])) {
            $columna = self::ORDENES[$orden];

            if ($orden === 'encargado') {
                // El nombre vive en users; se ordena con subquery correlacionada
                // para no meter un join que duplique filas.
                $query->orderBy(
                    User::select('nombre')->whereColumn('users.id', 'tareas.encargado_id'),
                    $dir
                );
            } elseif ($orden === 'cliente') {
                $query->orderBy(
                    // limit(1): la misma cédula puede tener más de una ficha y
                    // SQL Server revienta si la subconsulta devuelve varias filas.
                    DB::table('clientes')
                        ->select('primer_apellido')
                        ->whereColumn('clientes.cedula', 'tareas.cedula')
                        ->where('clientes.aliado_id', $alidoId)
                        ->limit(1),
                    $dir
                );
            } else {
                $query->orderBy($columna, $dir);
            }

            // Desempate estable: sin esto SQL Server puede repetir/saltar filas
            // entre páginas cuando hay empates en la columna elegida.
            $query->orderBy('tareas.id', 'desc');
        } else {
            $query->orderByRaw("
                CASE
                    WHEN estado = 'cerrada' THEN 5
                    WHEN estado = 'en_espera' AND fecha_alerta <= CAST(GETDATE() AS DATE) THEN 1
                    WHEN fecha_limite < CAST(GETDATE() AS DATE) THEN 1
                    WHEN fecha_limite <= DATEADD(day, 5, CAST(GETDATE() AS DATE)) THEN 2
                    ELSE 3
                END ASC
            ")->orderBy('fecha_limite', 'asc');
        }

        // Paginación: ocultar cerradas por defecto, salvo que
        // se pida explícitamente (cerradas=true) o se filtre por estado=cerrada
        $mostrarCerradas = $request->boolean('cerradas', false)
            || $request->get('estado') === 'cerrada';
        if (! $mostrarCerradas) {
            $query->where('estado', '!=', 'cerrada');
        }

        $tareas = $query->paginate(50)->withQueryString();

        // Resúmenes
        $resumenEstados = DB::table('tareas')
            ->where('aliado_id', $alidoId)
            ->whereNull('deleted_at')
            ->select('estado', DB::raw('COUNT(*) as total'))
            ->groupBy('estado')
            ->pluck('total', 'estado');

        $resumenTipos = DB::table('tareas')
            ->where('aliado_id', $alidoId)
            ->whereNull('deleted_at')
            ->whereIn('estado', Tarea::ESTADOS_ACTIVOS)
            ->select('tipo', DB::raw('COUNT(*) as total'))
            ->groupBy('tipo')
            ->pluck('total', 'tipo');

        $vencidas = DB::table('tareas')
            ->where('aliado_id', $alidoId)
            ->whereNull('deleted_at')
            ->whereIn('estado', Tarea::ESTADOS_ACTIVOS)
            ->where('fecha_limite', '<', now()->toDateString())
            ->count();

        // Datos para selects
        $trabajadores = User::where('aliado_id', $alidoId)->where('activo', true)->orderBy('nombre')->get();
        $razonesSociales = DB::table('razones_sociales')->where('aliado_id', $alidoId)->where('estado', 'Activa')->orderBy('razon_social')->get(['id', 'razon_social']);
        $epsList = DB::table('eps')->orderBy('nombre')->get(['id', 'nombre']);

        // Empresas para el filtro: solo las que tienen algún cliente con tarea
        // visible en la tabla. Corren los mismos filtros de la consulta
        // principal —encargado, tipo, estado, semáforo, cédula y la regla de
        // cerradas—: si no, la lista ofrece empresas que solo tienen tareas de
        // otro encargado y elegirlas deja la tabla vacía sin explicación.
        // Con joins y no con IN anidados: el IN sobre las cédulas de tareas
        // tardaba ~1.8 s contra los ~0.3 s de esta versión.
        $empresasQuery = DB::table('tareas as t')
            ->join('clientes as c', function ($j) use ($alidoId) {
                $j->on('c.cedula', '=', 't.cedula')->where('c.aliado_id', $alidoId);
            })
            ->join('empresas as e', 'e.id', '=', 'c.cod_empresa')
            ->where('t.aliado_id', $alidoId)
            ->whereNull('t.deleted_at')
            ->where('e.aliado_id', $alidoId)
            ->when(! $mostrarCerradas, fn ($q) => $q->where('t.estado', '!=', 'cerrada'));

        $this->filtrosComunes($empresasQuery, $request, 't');

        $empresasDisponibles = $empresasQuery
            ->distinct()
            ->orderBy('e.empresa')
            ->get(['e.id', 'e.empresa']);

        // La empresa que está filtrada se queda en la lista aunque los otros
        // filtros la dejen fuera: el buscador muestra el nombre de la que
        // encontró en esta lista, y sin ella se vería vacío —como si no
        // hubiera filtro— con la tabla igual sin filas.
        if ($request->filled('empresa_id') && ! $empresasDisponibles->contains('id', $request->empresa_id)) {
            $empresaFiltrada = DB::table('empresas')
                ->where('aliado_id', $alidoId)
                ->where('id', $request->empresa_id)
                ->first(['id', 'empresa']);

            if ($empresaFiltrada) {
                $empresasDisponibles = $empresasDisponibles
                    ->push($empresaFiltrada)
                    ->sortBy('empresa')
                    ->values();
            }
        }

        return view('admin.tareas.index', compact(
            'tareas', 'resumenEstados', 'resumenTipos', 'vencidas',
            'trabajadores', 'razonesSociales', 'epsList', 'empresasDisponibles'
        ));
    }

    /**
     * Filtros que comparten la tabla y la lista de empresas del buscador.
     *
     * `$t` es el prefijo de la tabla porque la consulta principal la nombra
     * `tareas` y la de empresas la aliasa `t`; sin él, con los joins de por
     * medio, SQL Server no sabe de qué columna se habla.
     *
     * El filtro de empresa queda fuera a propósito: es el que arma esa misma
     * lista, y aplicarlo la dejaría con una sola opción para escoger.
     *
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     */
    private function filtrosComunes($query, Request $request, string $t): void
    {
        if ($request->filled('encargado_id')) {
            $query->where($t.'.encargado_id', $request->encargado_id);
        }
        if ($request->filled('tipo')) {
            $query->where($t.'.tipo', $request->tipo);
        }
        if ($request->filled('estado')) {
            $query->where($t.'.estado', $request->estado);
        }
        if ($request->filled('cedula')) {
            $query->where($t.'.cedula', 'like', '%'.$request->cedula.'%');
        }
        if ($request->filled('semaforo')) {
            $hoy = now()->toDateString();
            if ($request->semaforo === 'urgente') {
                $query->where($t.'.estado', '!=', 'cerrada')
                    ->where(function ($q) use ($hoy, $t) {
                        $q->where($t.'.fecha_limite', '<=', $hoy)
                            ->orWhereNull($t.'.fecha_limite');
                    });
            } elseif ($request->semaforo === 'en_espera') {
                $query->where($t.'.estado', 'en_espera')
                    ->where($t.'.fecha_alerta', '<=', $hoy);
            }
        }
    }

    // ── STORE ───────────────────────────────────────────────────────────────
    public function store(Request $request)
    {
        $request->validate([
            'tipo' => 'required|string',
            'cedula' => 'required|string|max:20',
            'tarea' => 'required|string',
            'encargado_id' => 'required|exists:users,id',
        ]);

        $alidoId = session('aliado_id_activo') ?? Auth::user()->aliado_id;

        // Calcular fecha límite según semáforo config
        $fechaLimite = TareaSemaforoConfig::fechaLimiteParaTipo($request->tipo, $alidoId);

        $tarea = Tarea::create([
            'aliado_id' => $alidoId,
            'tipo' => $request->tipo,
            'estado' => Tarea::ESTADO_PENDIENTE,
            'cedula' => $request->cedula,
            'contrato_id' => $request->contrato_id ?: null,
            'razon_social_id' => $request->razon_social_id ?: null,
            'entidad' => $request->entidad,
            'tarea' => $request->tarea,
            'observacion' => $request->observacion,
            'encargado_id' => $request->encargado_id,
            'creado_por' => Auth::id(),
            'fecha_limite' => $fechaLimite,
            'fecha_radicado' => $request->fecha_radicado ?: null,
            'numero_radicado' => $request->numero_radicado,
            'correo' => $request->correo,
        ]);

        // Registro inicial en bitácora
        TareaGestion::create([
            'tarea_id' => $tarea->id,
            'user_id' => Auth::id(),
            'tipo_accion' => 'tramite_realizado',
            'observacion' => '✅ Tarea creada: '.$tarea->tarea,
            'estado_tarea' => Tarea::ESTADO_PENDIENTE,
            'created_at' => now(),
        ]);

        return redirect()->route('admin.tareas.index')->with('success', 'Tarea creada correctamente.');
    }

    // ── UPDATE ──────────────────────────────────────────────────────────────
    public function update(Request $request, int $id)
    {
        $request->validate([
            'tipo' => 'required|string',
            'cedula' => 'required|string|max:20',
            'tarea' => 'required|string',
            'encargado_id' => 'required|exists:users,id',
        ]);

        $alidoId = session('aliado_id_activo') ?? Auth::user()->aliado_id;
        $tarea = Tarea::where('aliado_id', $alidoId)->findOrFail($id);
        $tarea->update([
            'tipo' => $request->tipo,
            'cedula' => $request->cedula,
            'contrato_id' => $request->contrato_id ?: null,
            'razon_social_id' => $request->razon_social_id ?: null,
            'entidad' => $request->entidad,
            'tarea' => $request->tarea,
            'observacion' => $request->observacion,
            'encargado_id' => $request->encargado_id,
            'fecha_radicado' => $request->fecha_radicado ?: null,
            'numero_radicado' => $request->numero_radicado,
            'correo' => $request->correo,
        ]);

        return response()->json(['ok' => true, 'message' => 'Tarea actualizada.']);
    }

    // ── DESTROY ─────────────────────────────────────────────────────────────
    public function destroy(int $id)
    {
        $alidoId = session('aliado_id_activo') ?? Auth::user()->aliado_id;
        $tarea = Tarea::where('aliado_id', $alidoId)->findOrFail($id);
        $tarea->delete();

        return redirect()->route('admin.tareas.index')->with('success', 'Tarea eliminada.');
    }

    // ── SHOW (JSON para modal) ───────────────────────────────────────────────
    public function show(int $id)
    {
        $alidoId = session('aliado_id_activo') ?? Auth::user()->aliado_id;
        $tarea = Tarea::with([
            'encargado', 'creadoPor', 'razonSocial',
            'gestiones.user', 'gestiones.encargadoAnterior', 'gestiones.encargadoNuevo',
            'documentos.user',
        ])->where('aliado_id', $alidoId)->findOrFail($id);

        // Enriquecer con datos del cliente
        $cliente = DB::table('clientes')
            ->where('aliado_id', $alidoId)
            ->where('cedula', $tarea->cedula)
            ->select('id', 'cedula', 'primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido', 'celular', 'correo')
            ->first();

        $portal = app(\App\Services\EmpresaSolicitudPanel::class)->paraTarea($tarea, $alidoId);

        return response()->json([
            'tarea' => $tarea,
            'cliente' => $cliente,
            // Solicitud de la empresa (si la abrió desde su portal) y si hay una
            // empresa con portal que vería los avances marcados como visibles.
            'solicitud' => $portal['solicitud'],
            // «🔄 Traslado EPS» en vez de «traslado_eps».
            'tipo_label' => $tarea->tipoLabel(),
            'empresa_portal' => $portal['empresa'],
            // Fecha de creación ya formateada: evita ambigüedad de zona horaria en el front
            'creada' => $tarea->created_at?->format('d/m/Y h:i a'),
            'semaforo' => $tarea->colorSemaforo(),
            'icono' => $tarea->iconoSemaforo(),
            'dias' => $tarea->diasRestantes(),
        ]);
    }

    // ── GESTIÓN (bitácora) ───────────────────────────────────────────────────
    public function gestion(Request $request, int $id)
    {
        $request->validate([
            'tipo_accion' => 'required|string',
            'observacion' => 'required|string',
        ]);

        $alidoId = session('aliado_id_activo') ?? Auth::user()->aliado_id;
        $tarea = Tarea::findOrFail($id);
        abort_if((int) $tarea->aliado_id !== (int) $alidoId, 403);

        // Calcular fecha_alerta si pide recordatorio
        $fechaAlerta = null;
        $recordarDias = null;
        if ($request->filled('recordar_dias') && (int) $request->recordar_dias > 0) {
            $recordarDias = (int) $request->recordar_dias;
            $fechaAlerta = now()->addDays($recordarDias)->toDateString();
        }

        // Cambiar estado según tipo_accion
        $nuevoEstado = $tarea->estado;
        if ($request->tipo_accion === 'tramite_realizado') {
            $nuevoEstado = Tarea::ESTADO_EN_GESTION;
            // Si pone recordatorio → en_espera
            if ($fechaAlerta) {
                $nuevoEstado = Tarea::ESTADO_EN_ESPERA;
            }
        } elseif ($request->tipo_accion === 'cambio_estado' && $request->filled('nuevo_estado')) {
            $nuevoEstado = $request->nuevo_estado;
        }

        // Grabar gestión en bitácora
        TareaGestion::create([
            'tarea_id' => $tarea->id,
            'user_id' => Auth::id(),
            'tipo_accion' => $request->tipo_accion,
            'observacion' => $request->observacion,
            'visible_empresa' => $request->boolean('visible_empresa'),
            'recordar_dias' => $recordarDias,
            'fecha_alerta' => $fechaAlerta,
            'estado_tarea' => $nuevoEstado,
            'created_at' => now(),
        ]);

        // Actualizar tarea
        $tarea->update([
            'estado' => $nuevoEstado,
            'fecha_alerta' => $fechaAlerta ?? $tarea->fecha_alerta,
        ]);

        // Quien gestiona una solicitud del portal ya la vio: deja de ser nueva.
        \App\Models\EmpresaSolicitud::where('tarea_id', $tarea->id)->whereNull('vista_at')
            ->update(['vista_at' => now()]);

        return response()->json([
            'ok' => true,
            'message' => 'Gestión registrada.',
            'estado' => $nuevoEstado,
            'alerta' => $fechaAlerta,
        ]);
    }

    // ── TRASLADAR ────────────────────────────────────────────────────────────
    public function trasladar(Request $request, int $id)
    {
        $request->validate([
            'encargado_id' => 'required|exists:users,id',
            'observacion' => 'required|string',
        ]);

        $alidoId = session('aliado_id_activo') ?? Auth::user()->aliado_id;
        $tarea = Tarea::where('aliado_id', $alidoId)->findOrFail($id);
        $anterior = $tarea->encargado_id;

        // Bitácora de traslado
        TareaGestion::create([
            'tarea_id' => $tarea->id,
            'user_id' => Auth::id(),
            'tipo_accion' => 'traslado',
            'observacion' => $request->observacion,
            'encargado_anterior' => $anterior,
            'encargado_nuevo' => $request->encargado_id,
            'estado_tarea' => $tarea->estado,
            'created_at' => now(),
        ]);

        $tarea->update(['encargado_id' => $request->encargado_id]);

        return response()->json(['ok' => true, 'message' => 'Tarea trasladada correctamente.']);
    }

    // ── CERRAR ───────────────────────────────────────────────────────────────
    public function cerrar(Request $request, int $id)
    {
        $request->validate([
            'resultado' => 'required|in:positivo,negativo',
            'observacion' => 'required|string',
        ]);

        $alidoId = session('aliado_id_activo') ?? Auth::user()->aliado_id;
        $tarea = Tarea::where('aliado_id', $alidoId)->findOrFail($id);

        TareaGestion::create([
            'tarea_id' => $tarea->id,
            'user_id' => Auth::id(),
            'tipo_accion' => 'cambio_estado',
            'observacion' => '🏁 Tarea cerrada ('.($request->resultado === 'positivo' ? '✅ Positiva' : '❌ Negativa').'): '.$request->observacion,
            'visible_empresa' => $request->boolean('visible_empresa'),
            'estado_tarea' => Tarea::ESTADO_CERRADA,
            'created_at' => now(),
        ]);

        $tarea->update([
            'estado' => Tarea::ESTADO_CERRADA,
            'resultado' => $request->resultado,
        ]);

        // Si la abrió una empresa desde su portal, su solicitud queda
        // aprobada o rechazada con el mismo resultado.
        app(\App\Services\EmpresaSolicitudService::class)->alCerrarTarea($tarea, $request->resultado);

        return response()->json(['ok' => true, 'message' => 'Tarea cerrada.']);
    }

    // ── SUBIR DOCUMENTO ──────────────────────────────────────────────────────
    public function subirDocumento(Request $request, int $id)
    {
        $request->validate([
            'archivo' => 'required|file|max:10240',
            'nombre' => 'required|string|max:200',
        ]);

        $alidoId = session('aliado_id_activo') ?? Auth::user()->aliado_id;
        $tarea = Tarea::where('aliado_id', $alidoId)->findOrFail($id);
        $file = $request->file('archivo');
        $ext = strtolower($file->getClientOriginalExtension());
        $ruta = $file->store("tareas/{$tarea->id}", 'public');

        TareaDocumento::create([
            'tarea_id' => $tarea->id,
            'user_id' => Auth::id(),
            'nombre' => $request->nombre,
            'ruta' => $ruta,
            'tipo_archivo' => $ext,
            'created_at' => now(),
        ]);

        // Registrar en bitácora
        TareaGestion::create([
            'tarea_id' => $tarea->id,
            'user_id' => Auth::id(),
            'tipo_accion' => 'nota',
            'observacion' => '📎 Documento adjuntado: '.$request->nombre,
            'estado_tarea' => $tarea->estado,
            'created_at' => now(),
        ]);

        return response()->json(['ok' => true, 'message' => 'Documento subido correctamente.']);
    }

    // ── DESCARGAR DOCUMENTO ──────────────────────────────────────────────────
    public function descargarDocumento(int $docId)
    {
        $alidoId = session('aliado_id_activo') ?? Auth::user()->aliado_id;
        $doc = TareaDocumento::whereHas('tarea', fn ($q) => $q->where('aliado_id', $alidoId))
            ->findOrFail($docId);

        return Storage::disk('public')->download($doc->ruta, $doc->nombre.'.'.$doc->tipo_archivo);
    }

    // ── REPORTE ──────────────────────────────────────────────────────────────
    public function reporte(Request $request)
    {
        $alidoId = session('aliado_id_activo') ?? Auth::user()->aliado_id;

        $mes = $request->get('mes', now()->month);
        $anio = $request->get('anio', now()->year);

        $trabajadores = User::where('aliado_id', $alidoId)->where('activo', true)->orderBy('nombre')->get();

        $datos = [];
        foreach ($trabajadores as $worker) {
            // Tareas asignadas en el periodo donde fue encargado
            $tareasTotales = DB::table('tareas')
                ->where('aliado_id', $alidoId)
                ->whereNull('deleted_at')
                ->where('encargado_id', $worker->id)
                ->count();

            if ($tareasTotales === 0) {
                continue;
            }

            $cerradasPositivo = DB::table('tareas')
                ->where('aliado_id', $alidoId)
                ->whereNull('deleted_at')
                ->where('encargado_id', $worker->id)
                ->where('estado', 'cerrada')
                ->where('resultado', 'positivo')
                ->count();

            $cerradasNegativo = DB::table('tareas')
                ->where('aliado_id', $alidoId)
                ->whereNull('deleted_at')
                ->where('encargado_id', $worker->id)
                ->where('estado', 'cerrada')
                ->where('resultado', 'negativo')
                ->count();

            $vencidas = DB::table('tareas')
                ->where('aliado_id', $alidoId)
                ->whereNull('deleted_at')
                ->where('encargado_id', $worker->id)
                ->whereIn('estado', Tarea::ESTADOS_ACTIVOS)
                ->where('fecha_limite', '<', now()->toDateString())
                ->count();

            // Promedio gestiones por tarea
            $promedioGestiones = DB::table('tarea_gestiones as g')
                ->join('tareas as t', 't.id', '=', 'g.tarea_id')
                ->where('t.aliado_id', $alidoId)
                ->whereNull('t.deleted_at')
                ->where('t.encargado_id', $worker->id)
                ->select(DB::raw('COUNT(g.id) as total'), DB::raw('COUNT(DISTINCT g.tarea_id) as tareas'))
                ->first();

            $avgGestiones = ($promedioGestiones && $promedioGestiones->tareas > 0)
                ? round($promedioGestiones->total / $promedioGestiones->tareas, 1)
                : 0;

            // Gestiones hechas a tiempo (antes de que se venciera la tarea)
            $totalGestiones = DB::table('tarea_gestiones as g')
                ->join('tareas as t', 't.id', '=', 'g.tarea_id')
                ->where('t.aliado_id', $alidoId)
                ->whereNull('t.deleted_at')
                ->where('t.encargado_id', $worker->id)
                ->count('g.id');

            $gestionesATiempo = DB::table('tarea_gestiones as g')
                ->join('tareas as t', 't.id', '=', 'g.tarea_id')
                ->where('t.aliado_id', $alidoId)
                ->whereNull('t.deleted_at')
                ->where('t.encargado_id', $worker->id)
                ->whereNotNull('t.fecha_limite')
                ->whereRaw('g.created_at <= t.fecha_limite')
                ->count('g.id');

            $puntualidad = $totalGestiones > 0
                ? round(($gestionesATiempo / $totalGestiones) * 100, 1)
                : 100;

            $datos[] = [
                'trabajador' => $worker,
                'total' => $tareasTotales,
                'cerradas_positivo' => $cerradasPositivo,
                'cerradas_negativo' => $cerradasNegativo,
                'vencidas' => $vencidas,
                'avg_gestiones' => $avgGestiones,
                'puntualidad' => $puntualidad,
            ];
        }

        // Ordenar por más tareas totales
        usort($datos, fn ($a, $b) => $b['total'] <=> $a['total']);

        return view('admin.tareas.reporte', compact('datos', 'trabajadores', 'mes', 'anio'));
    }

    // ── API: buscar cliente por cédula ───────────────────────────────────────
    public function buscarCliente(Request $request)
    {
        $alidoId = session('aliado_id_activo') ?? Auth::user()->aliado_id;
        $cedula = $request->get('cedula');
        $cliente = DB::table('clientes')
            ->where('aliado_id', $alidoId)
            ->where('cedula', 'like', '%'.$cedula.'%')
            ->limit(10)
            ->get(['cedula', 'primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido', 'celular']);

        return response()->json($cliente);
    }

    // ── API: contratos por cédula ────────────────────────────────────────────
    public function contratosPorCedula(Request $request)
    {
        $cedula = $request->get('cedula');
        $alidoId = session('aliado_id_activo') ?? Auth::user()->aliado_id;

        $contratos = DB::table('contratos as c')
            ->leftJoin('razones_sociales as rs', 'rs.id', '=', 'c.razon_social_id')
            ->where('c.cedula', $cedula)
            ->where('c.aliado_id', $alidoId)
            ->orderByDesc('c.fecha_ingreso')
            ->get([
                'c.id',
                'c.cedula',
                'c.fecha_ingreso',
                'c.estado',
                'rs.razon_social as razon_social',
            ]);

        return response()->json($contratos);
    }
}
