<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\{Cuadre, Gasto, CajaMenor, Consignacion, BancoCuenta, Factura, User, Anticipo};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CuadreDiarioController extends Controller
{
    /** Valor del selector de usuario que pide la caja de todos juntos. */
    private const FILTRO_TODOS = 'todos';

    /**
     * Index: la caja de UN día concreto, del usuario logueado.
     *
     * El cuadre dejó de ser un período que se abre y se cierra: cada día es
     * independiente y se calcula al vuelo por (aliado, usuario, fecha). La
     * fila en `cuadres` solo se crea cuando el superadmin marca el día como
     * cuadrado — ver cerrarDia().
     */
    public function index(Request $request)
    {
        $aliadoId = session('aliado_id_activo');
        $esAdmin  = Auth::user()->hasRole(['admin', 'superadmin']);
        $fecha    = $this->fechaValida($request->input('fecha'));

        $usuarios = User::where('aliado_id', $aliadoId)->where('activo', true)
            ->orderBy('nombre')->get(['id', 'nombre']);

        // Un usuario de BryNex operando dentro de otro aliado no figura en esa lista,
        // pero sus cobros de ese dia si quedan a su nombre: sin esto el selector no
        // marcaba ninguna opcion y parecia que se estaba viendo la caja de todos.
        if (! $usuarios->contains('id', (int) Auth::id())) {
            $usuarios = $usuarios
                ->push(User::select('id', 'nombre')->find(Auth::id()))
                ->filter()
                ->sortBy('nombre')
                ->values();
        }

        // Solo admin/superadmin puede mirar el día de otro usuario, y solo ellos
        // pueden pedir la caja de TODOS juntos ($usuarioId = null → sin filtro por
        // usuario en las consultas del día).
        $usuarioId = (int) Auth::id();
        $verTodos  = false;
        if ($esAdmin && $request->input('usuario_id') === self::FILTRO_TODOS) {
            $usuarioId = null;
            $verTodos  = true;
        } elseif ($esAdmin && $request->filled('usuario_id')
            && $usuarios->contains('id', (int) $request->input('usuario_id'))) {
            $usuarioId = (int) $request->input('usuario_id');
        }
        $usuarioVista = $verTodos ? null : ($usuarios->firstWhere('id', $usuarioId) ?? Auth::user());
        // Con la caja de todos no hay un dueño del día: no se registra gasto ni se
        // cuadra desde esa vista, solo se mira el total.
        $esPropio     = ! $verTodos && $usuarioId === (int) Auth::id();

        // El selector de gastos solo ofrece las cuentas de facturación.
        $bancosFacturacion = BancoCuenta::paraFacturacion($aliadoId);

        // Las facturas del día se cargan una sola vez: alimentan el resumen y
        // los canales. Cada query a SQL Server cuesta ~200 ms de red.
        $facturasDia    = $this->facturasDelDia($aliadoId, $usuarioId, $fecha);

        $resumen        = $this->resumenDia($aliadoId, $usuarioId, $fecha, $facturasDia);
        $canales        = $this->canalesDelDia($facturasDia);
        $gastos         = $this->gastosDelDia($aliadoId, $usuarioId, $fecha);
        $consignaciones = $this->consignacionesDelDia($aliadoId, $usuarioId, $fecha);
        $cuadreDia      = $this->cuadreDelDia($aliadoId, $usuarioId, $fecha);

        return view('admin.cuadre-diario.index', compact(
            'fecha', 'esAdmin', 'esPropio', 'verTodos', 'usuarios', 'usuarioId', 'usuarioVista',
            'bancosFacturacion', 'resumen', 'canales', 'gastos',
            'consignaciones', 'cuadreDia'
        ));
    }

    /**
     * Ver un cuadre histórico. Los registros viejos abarcan varios días
     * (modelo anterior), así que conservan su propia vista de solo lectura.
     * Los nuevos son de un solo día → se redirige al índice de esa fecha.
     */
    public function ver(int $id)
    {
        $aliadoId  = session('aliado_id_activo');
        $usuarioId = Auth::id();
        $esAdmin   = Auth::user()->hasRole(['admin', 'superadmin']);

        $cuadre = Cuadre::where('aliado_id', $aliadoId)
            ->when(!$esAdmin, fn($q) => $q->where('usuario_id', $usuarioId))
            ->with(['usuario', 'cerradoPor'])
            ->findOrFail($id);

        $esDeUnDia = $cuadre->fecha_fin
            && $cuadre->fecha_inicio->toDateString() === $cuadre->fecha_fin->toDateString();

        if ($esDeUnDia) {
            return redirect()->route('admin.cuadre-diario.index', [
                'fecha'      => $cuadre->fecha_inicio->toDateString(),
                'usuario_id' => $cuadre->usuario_id,
            ]);
        }

        $gastos = Gasto::where('cuadre_id', $cuadre->id)
            ->with(['bancoOrigen', 'bancoDestino', 'usuario'])
            ->orderBy('fecha')->get();

        $facturasPeriodo = $this->facturasPeriodo($cuadre, $aliadoId, $cuadre->usuario_id);
        $datosPeriodo    = $this->calcularPeriodo($cuadre, $aliadoId, $cuadre->usuario_id);
        $cajaMenor       = $cuadre->saldo_apertura;

        return view('admin.cuadre-diario.historico', compact(
            'cuadre', 'cajaMenor', 'datosPeriodo', 'gastos', 'facturasPeriodo'
        ));
    }

    /**
     * Consolidado admin: la caja de TODOS los usuarios en un día.
     *
     * Ya no depende de que exista una fila en `cuadres` — se calcula por
     * fecha igual que el índice, y la fila solo aporta el estado (cuadrado
     * o pendiente). Todo en 6 queries agregadas, sin N+1 por usuario.
     */
    public function consolidado(Request $request)
    {
        if (!Auth::user()->hasRole(['admin', 'superadmin'])) {
            abort(403, 'Solo administradores pueden ver el consolidado.');
        }

        $aliadoId      = session('aliado_id_activo');
        $fecha         = $this->fechaValida($request->input('fecha'));
        $usuarioFiltro = $request->input('usuario_id');

        $usuarios = User::where('aliado_id', $aliadoId)->orderBy('nombre')->get();

        $visibles = $usuarioFiltro
            ? $usuarios->where('id', (int) $usuarioFiltro)
            : $usuarios;
        $usuarioIds = $visibles->pluck('id')->all();

        $porUsuario = fn($q) => $usuarioIds ? $q->get()->keyBy('uid') : collect();

        $ingresos = $porUsuario(DB::table('facturas')
            ->whereNull('deleted_at')
            ->where('aliado_id', $aliadoId)->whereIn('usuario_id', $usuarioIds)
            ->whereDate('fecha_pago', $fecha)->where('es_prestamo', false)
            ->groupBy('usuario_id')
            ->selectRaw('usuario_id AS uid, SUM(ISNULL(valor_efectivo,0)) AS efectivo,
                         SUM(ISNULL(valor_consignado,0)) AS consignado, COUNT(*) AS facturas'));

        $cartera = $porUsuario(DB::table('abonos')
            ->join('facturas', 'abonos.factura_id', '=', 'facturas.id')
            ->whereNull('facturas.deleted_at')
            ->where('facturas.aliado_id', $aliadoId)->where('facturas.es_prestamo', true)
            ->whereIn('abonos.usuario_id', $usuarioIds)
            ->whereDate('abonos.fecha', $fecha)
            ->groupBy('abonos.usuario_id')
            ->selectRaw('abonos.usuario_id AS uid, SUM(ISNULL(abonos.valor_efectivo,0)) AS t'));

        $anticipos = $porUsuario(DB::table('anticipos')
            ->whereNull('deleted_at')
            ->where('aliado_id', $aliadoId)->whereIn('usuario_id', $usuarioIds)
            ->whereIn('forma_pago', ['efectivo', 'nequi'])
            ->whereNotIn('estado', [Anticipo::ESTADO_DEVUELTO, Anticipo::ESTADO_DISTRIBUIDO])
            ->whereDate('fecha_pago', $fecha)
            ->groupBy('usuario_id')
            ->selectRaw('usuario_id AS uid, SUM(ISNULL(valor,0)) AS t'));

        $gastos = $porUsuario(DB::table('gastos')
            ->where('aliado_id', $aliadoId)->whereIn('usuario_id', $usuarioIds)
            ->whereDate('fecha', $fecha)
            ->where(fn($q) => $q->where('forma_pago', 'efectivo')->orWhere('tipo', 'efectivo_banco'))
            ->groupBy('usuario_id')
            ->selectRaw('usuario_id AS uid, SUM(ISNULL(valor,0)) AS t'));

        $consignado = $porUsuario(DB::table('consignaciones')
            ->whereNull('deleted_at')
            ->where('aliado_id', $aliadoId)->whereIn('usuario_id', $usuarioIds)
            ->whereDate('fecha', $fecha)
            ->groupBy('usuario_id')
            ->selectRaw('usuario_id AS uid, SUM(ISNULL(valor,0)) AS t'));

        // Días ya cuadrados de esa fecha (modelo por día).
        $cuadrados = Cuadre::where('aliado_id', $aliadoId)
            ->whereIn('usuario_id', $usuarioIds)
            ->whereDate('fecha_inicio', $fecha)
            ->whereColumn('fecha_inicio', 'fecha_fin')
            ->with('cerradoPor:id,nombre')
            ->get()->keyBy('usuario_id');

        $resumen = $visibles->map(function ($u) use (
            $aliadoId, $ingresos, $cartera, $anticipos, $gastos, $consignado, $cuadrados
        ) {
            $ing  = (int) ($ingresos[$u->id]->efectivo ?? 0);
            $car  = (int) ($cartera[$u->id]->t         ?? 0);
            $ant  = (int) ($anticipos[$u->id]->t       ?? 0);
            $gas  = (int) ($gastos[$u->id]->t          ?? 0);
            $base = CajaMenor::montoActivo($aliadoId, $u->id);

            return (object) [
                'usuario'         => $u,
                'base_caja'       => $base,
                'facturas'        => (int) ($ingresos[$u->id]->facturas ?? 0),
                'efectivo_total'  => $ing + $car + $ant,
                'consignado'      => (int) ($consignado[$u->id]->t ?? 0),
                'gastos_efectivo' => $gas,
                'saldo_esperado'  => $base + $ing + $car + $ant - $gas,
                'cuadre'          => $cuadrados[$u->id] ?? null,
            ];
        })
        // Solo quien movió plata ese día; sin esto la tabla es una lista de ceros.
        ->filter(fn($r) => $r->efectivo_total || $r->consignado || $r->gastos_efectivo || $r->cuadre)
        ->sortByDesc('efectivo_total')
        ->values();

        // Saldos bancarios actuales (calculados desde consignaciones + gastos)
        $saldosBanco = BancoCuenta::where('aliado_id', $aliadoId)
            ->where('activo', true)
            ->get()
            ->map(fn($bc) => [
                'banco' => $bc,
                'saldo' => Consignacion::saldoBanco($aliadoId, $bc->id),
            ]);

        return view('admin.cuadre-diario.consolidado', compact(
            'resumen', 'usuarios', 'fecha', 'saldosBanco'
        ));
    }

    // ── Registrar gasto ──────────────────────────────────────────────
    public function registrarGasto(Request $request)
    {
        $aliadoId  = session('aliado_id_activo');
        $usuarioId = Auth::id();
        $esAdmin   = Auth::user()->hasRole(['admin', 'superadmin']);

        $validated = $request->validate([
            'fecha'             => 'required|date',
            'tipo'              => 'required|string',
            'descripcion'       => 'required|string|max:500',
            'pagado_a'          => 'nullable|string|max:255',
            'forma_pago'        => 'required|in:efectivo,transferencia_bancaria,banco_banco',
            'banco_origen_id'   => 'nullable|integer',
            'banco_destino_id'  => 'nullable|integer',
            'valor'             => 'required|integer|min:1',
            'recibo_caja'       => 'nullable|string|max:100',
            'observacion'       => 'nullable|string',
        ]);

        // Validar tipos de admin
        if (in_array($validated['tipo'], Gasto::TIPOS_ADMIN) && !$esAdmin) {
            return back()->with('error', 'No tienes permiso para este tipo de gasto.');
        }

        // El día ya cuadrado no admite movimientos nuevos.
        $cuadreDia = $this->cuadreDelDia($aliadoId, $usuarioId, $validated['fecha']);
        if ($cuadreDia) {
            return back()->with('error', 'Ese día ya fue cuadrado; no se pueden registrar más gastos.');
        }

        DB::beginTransaction();
        try {
            $gasto = Gasto::create(array_merge($validated, [
                'aliado_id'  => $aliadoId,
                'usuario_id' => $usuarioId,
                'cuadre_id'  => null,   // se asocia al cuadrar el día — ver cerrarDia()
            ]));

            // ── Traslado efectivo → banco ────────────────────────────────
            // Registra como consignación interna (tipo traslado_efectivo)
            // para que el saldo bancario lo cuente como entrada.
            if ($validated['tipo'] === 'efectivo_banco' && !empty($validated['banco_origen_id'])) {
                Consignacion::create([
                    'aliado_id'       => $aliadoId,
                    'banco_cuenta_id' => $validated['banco_origen_id'],
                    'factura_id'      => null,
                    'fecha'           => $validated['fecha'],
                    'valor'           => $validated['valor'],
                    'tipo'            => Consignacion::TIPO_TRASLADO_EFECTIVO,
                    'referencia'      => 'Cuadre ' . $validated['fecha'],
                    'confirmado'      => true,
                    'observacion'     => $validated['descripcion'],
                    'usuario_id'      => $usuarioId,
                ]);
            }

            // ── Banco → Banco ────────────────────────────────────────────
            // El gasto ya registra la salida del origen (banco_origen_id).
            // Aquí creamos la consignación de entrada en el banco destino.
            if ($validated['forma_pago'] === 'banco_banco' && !empty($validated['banco_destino_id'])) {
                Consignacion::create([
                    'aliado_id'       => $aliadoId,
                    'banco_cuenta_id' => $validated['banco_destino_id'],
                    'factura_id'      => null,
                    'fecha'           => $validated['fecha'],
                    'valor'           => $validated['valor'],
                    'tipo'            => Consignacion::TIPO_BANCO_RECIBIDO,
                    'referencia'      => 'Transferencia desde banco origen',
                    'confirmado'      => true,
                    'observacion'     => $validated['descripcion'],
                    'usuario_id'      => $usuarioId,
                ]);
            }
            // Nota: gastos con forma_pago='transferencia_bancaria' (pago de gasto)
            // y banco_banco (débito del origen) quedan como salidas en la fórmula
            // Consignacion::saldoBanco() que descuenta gastos.banco_origen_id.

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'Error al registrar el gasto: ' . $e->getMessage());
        }

        return back()->with('success', 'Gasto registrado correctamente.');
    }

    // ── Eliminar gasto ───────────────────────────────────────────────
    public function eliminarGasto(int $gastoId)
    {
        $aliadoId = session('aliado_id_activo');
        $gasto = Gasto::where('aliado_id', $aliadoId)->findOrFail($gastoId);

        // Solo puede eliminar el propio o admin
        if ($gasto->usuario_id !== Auth::id() && !Auth::user()->hasRole(['admin', 'superadmin'])) {
            return back()->with('error', 'Sin permiso.');
        }

        if ($this->cuadreDelDia($aliadoId, $gasto->usuario_id, $gasto->fecha->toDateString())) {
            return back()->with('error', 'Ese día ya fue cuadrado; el gasto no se puede eliminar.');
        }

        $gasto->delete();
        return back()->with('success', 'Gasto eliminado.');
    }

    /**
     * Marca un día como cuadrado (solo superadmin). Crea la fila en `cuadres`
     * con fecha_inicio = fecha_fin = el día, y engancha los gastos sueltos de
     * ese día para dejar la trazabilidad completa.
     */
    public function cerrarDia(Request $request)
    {
        if (!Auth::user()->hasRole('superadmin')) {
            abort(403, 'Solo el Superadmin puede cuadrar un día.');
        }

        $aliadoId = session('aliado_id_activo');

        $datos = $request->validate([
            'fecha'       => 'required|date',
            'usuario_id'  => 'required|integer',
            'observacion' => 'nullable|string',
        ]);

        $fecha     = \Carbon\Carbon::parse($datos['fecha'])->toDateString();
        $usuarioId = (int) $datos['usuario_id'];

        // El usuario debe pertenecer al aliado activo (evita cuadrar ajeno por id).
        $usuario = User::where('aliado_id', $aliadoId)->find($usuarioId);
        if (!$usuario) {
            return back()->with('error', 'El usuario no pertenece a este aliado.');
        }

        if ($this->cuadreDelDia($aliadoId, $usuarioId, $fecha)) {
            return back()->with('error', 'Ese día ya está cuadrado.');
        }

        $resumen = $this->resumenDia(
            $aliadoId, $usuarioId, $fecha,
            $this->facturasDelDia($aliadoId, $usuarioId, $fecha)
        );

        DB::beginTransaction();
        try {
            $cuadre = Cuadre::create([
                'aliado_id'      => $aliadoId,
                'usuario_id'     => $usuarioId,
                'fecha_inicio'   => $fecha,
                'fecha_fin'      => $fecha,
                'estado'         => 'cerrado',
                'saldo_apertura' => $resumen['base_caja'],
                'saldo_cierre'   => $resumen['saldo_esperado'],
                'cerrado_por'    => Auth::id(),
                'observacion'    => $datos['observacion'] ?? null,
            ]);

            Gasto::where('aliado_id', $aliadoId)
                ->where('usuario_id', $usuarioId)
                ->whereDate('fecha', $fecha)
                ->whereNull('cuadre_id')
                ->update(['cuadre_id' => $cuadre->id]);

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'No se pudo cuadrar el día: ' . $e->getMessage());
        }

        return back()->with('success',
            'Día cuadrado. Saldo: $' . number_format($resumen['saldo_esperado'], 0, ',', '.'));
    }

    /** Reabre un día ya cuadrado (solo superadmin). */
    public function reabrirDia(Request $request, int $cuadreId)
    {
        if (!Auth::user()->hasRole('superadmin')) {
            abort(403, 'Solo el Superadmin puede reabrir un día.');
        }

        $aliadoId = session('aliado_id_activo');
        $cuadre   = Cuadre::where('aliado_id', $aliadoId)->findOrFail($cuadreId);

        // Solo los cuadres del modelo por día. Los históricos multi-día son
        // registros del modelo anterior y no se tocan.
        $esDeUnDia = $cuadre->fecha_fin
            && $cuadre->fecha_inicio->toDateString() === $cuadre->fecha_fin->toDateString();
        if (!$esDeUnDia) {
            return back()->with('error', 'Este cuadre es de un período histórico y no se puede reabrir.');
        }

        DB::beginTransaction();
        try {
            Gasto::where('cuadre_id', $cuadre->id)->update(['cuadre_id' => null]);
            $cuadre->delete();
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', 'No se pudo reabrir el día: ' . $e->getMessage());
        }

        return back()->with('success', 'Día reabierto.');
    }

    // ── Confirmar consignación ────────────────────────────────────────
    public function confirmarConsignacion(Request $request, int $csId)
    {
        if (!Auth::user()->hasRole(['admin', 'superadmin'])) {
            abort(403);
        }
        $aliadoId = session('aliado_id_activo');
        $cs = Consignacion::where('aliado_id', $aliadoId)->findOrFail($csId);

        $observacion = $cs->observacion ?? '';
        $observacion = preg_replace('/\s*[-·]?\s*(?:Validado|Marcado no aparece) por:.*$/u', '', $observacion);

        // Nota libre del usuario (ej: "llevo al 8-julio")
        $notaExtra = trim($request->input('observacion_extra', ''));
        if ($notaExtra) {
            $observacion = trim($observacion . ($observacion ? ' | ' : '') . $notaExtra);
        }

        $firma = 'Validado por: ' . Auth::user()->nombre;
        $nuevaObservacion = trim($observacion . ($observacion ? ' - ' : '') . $firma);

        $cs->update([
            'confirmado' => true,
            'no_aparece' => false,
            'usuario_validador_id' => Auth::id(),
            'fecha_validacion' => now(),
            'observacion' => $nuevaObservacion,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Consignación verificada ✅',
                'banco_id' => $cs->banco_cuenta_id,
                'consignacion' => [
                    'id' => $cs->id,
                    'confirmado' => true,
                    'no_aparece' => false,
                    'observacion' => $cs->observacion,
                    'descripcion' => $cs->observacion,
                    'imagen_url' => $cs->imagen_path ? Storage::url($cs->imagen_path) : null,
                ],
                'nuevo_saldo' => Consignacion::saldoBanco($aliadoId, $cs->banco_cuenta_id)
            ]);
        }

        return back()->with('success', 'Consignación verificada ✅');
    }

    // ── Marcar consignación como no aparece en banco ──────────────────
    public function noApareceConsignacion(Request $request, int $csId)
    {
        if (!Auth::user()->hasRole(['admin', 'superadmin'])) {
            abort(403);
        }
        $aliadoId = session('aliado_id_activo');
        $cs = Consignacion::where('aliado_id', $aliadoId)->findOrFail($csId);

        $observacion = $cs->observacion ?? '';
        $observacion = preg_replace('/\s*[-·]?\s*(?:Validado|Marcado no aparece) por:.*$/u', '', $observacion);

        // Nota libre del usuario (ej: "llevo al 8-julio")
        $notaExtra = trim($request->input('observacion_extra', ''));
        if ($notaExtra) {
            $observacion = trim($observacion . ($observacion ? ' | ' : '') . $notaExtra);
        }

        $firma = 'Marcado no aparece por: ' . Auth::user()->nombre;
        $nuevaObservacion = trim($observacion . ($observacion ? ' - ' : '') . $firma);

        $cs->update([
            'confirmado' => false,
            'no_aparece' => true,
            'usuario_validador_id' => Auth::id(),
            'fecha_validacion' => now(),
            'observacion' => $nuevaObservacion,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Consignación marcada como no aparece en banco ❌',
                'banco_id' => $cs->banco_cuenta_id,
                'consignacion' => [
                    'id' => $cs->id,
                    'confirmado' => false,
                    'no_aparece' => true,
                    'observacion' => $cs->observacion,
                    'descripcion' => $cs->observacion,
                    'imagen_url' => $cs->imagen_path ? Storage::url($cs->imagen_path) : null,
                ],
                'nuevo_saldo' => Consignacion::saldoBanco($aliadoId, $cs->banco_cuenta_id)
            ]);
        }

        return back()->with('success', 'Consignación marcada como no aparece en banco ❌');
    }

    // ── Subir imagen de gasto ─────────────────────────────────────────
    public function subirImagenGasto(Request $request, int $gastoId)
    {
        $aliadoId = session('aliado_id_activo');
        $gasto = Gasto::where('aliado_id', $aliadoId)->findOrFail($gastoId);

        if (!Auth::user()->hasRole(['admin', 'superadmin']) && $gasto->usuario_id !== Auth::id()) {
            abort(403);
        }

        $request->validate(['imagen' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120']);
        $path = $request->file('imagen')->store('gastos', 'public');
        $gasto->update(['imagen_path' => $path]);

        return back()->with('success', 'Imagen del gasto guardada.');
    }

    // ── Subir imagen de consignación (todos los roles pueden adjuntar comprobantes) ──
    public function subirImagenConsignacion(Request $request, int $csId)
    {
        $aliadoId = session('aliado_id_activo');
        $cs = Consignacion::where('aliado_id', $aliadoId)->findOrFail($csId);

        $request->validate(['imagen' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120']);
        $path = $request->file('imagen')->store('consignaciones', 'public');
        $cs->update(['imagen_path' => $path]);

        return back()->with('success', 'Comprobante de consignación guardado.');
    }

    // ── Reversar consignación a pendiente ──────────────────────────
    public function reversarConsignacion(Request $request, int $csId)
    {
        if (!Auth::user()->hasRole(['admin', 'superadmin'])) {
            abort(403);
        }
        $aliadoId = session('aliado_id_activo');
        $cs = Consignacion::where('aliado_id', $aliadoId)->findOrFail($csId);

        $observacion = $cs->observacion ?? '';
        $nuevaObservacion = trim(preg_replace('/\s*[-·]?\s*(?:Validado|Marcado no aparece) por:.*$/u', '', $observacion));

        // Nota libre del usuario (si la proporcionó al reversar)
        $notaExtra = trim($request->input('observacion_extra', ''));
        if ($notaExtra) {
            $nuevaObservacion = trim($nuevaObservacion . ($nuevaObservacion ? ' | ' : '') . $notaExtra);
        }

        $cs->update([
            'confirmado' => false,
            'no_aparece' => false,
            'usuario_validador_id' => null,
            'fecha_validacion' => null,
            'observacion' => $nuevaObservacion ?: null,
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Consignación marcada como pendiente 🕐',
                'banco_id' => $cs->banco_cuenta_id,
                'consignacion' => [
                    'id' => $cs->id,
                    'confirmado' => false,
                    'no_aparece' => false,
                    'observacion' => $cs->observacion,
                    'descripcion' => $cs->observacion ?: '—',
                    'imagen_url' => $cs->imagen_path ? Storage::url($cs->imagen_path) : null,
                ],
                'nuevo_saldo' => Consignacion::saldoBanco($aliadoId, $cs->banco_cuenta_id)
            ]);
        }

        return back()->with('success', 'Consignación marcada como pendiente.');
    }

    // ── Anular consignación duplicada + convertir factura a préstamo ──
    /**
     * Acción exclusiva de SUPERADMIN.
     * Úsala cuando una consignación fue registrada por error en una factura
     * cuyo plano ya fue pagado al operador (no se puede anular la factura).
     *
     * En una sola transacción:
     *  1. Convierte la factura → estado='prestamo', valor_consignado=0,
     *     valor_efectivo=0, valor_prestamo=total, saldo_proximo=-total
     *  2. Soft-delete de la consignación (queda en BD para auditoría)
     */
    public function anularConsignacionPrestamo(Request $request, int $csId)
    {
        if (!Auth::user()->hasRole('superadmin')) {
            abort(403, 'Solo superadmin puede realizar esta operación.');
        }

        $aliadoId = session('aliado_id_activo');
        $cs = Consignacion::where('aliado_id', $aliadoId)->findOrFail($csId);

        if (!$cs->factura_id) {
            return response()->json([
                'success' => false,
                'message' => 'Esta consignación no está asociada a una factura.',
            ], 422);
        }

        $factura = Factura::where('aliado_id', $aliadoId)->findOrFail($cs->factura_id);

        DB::transaction(function () use ($cs, $factura, $aliadoId) {
            $totalFactura = (int)($factura->total ?? 0);
            $firma        = 'Convertida a préstamo por: ' . Auth::user()->nombre . ' el ' . now()->format('d/m/Y H:i');

            // 1. Convertir factura a préstamo
            $factura->update([
                'estado'           => 'prestamo',
                'es_prestamo'      => true,
                'forma_pago'       => 'prestamo',
                'valor_consignado' => 0,
                'valor_efectivo'   => 0,
                'valor_prestamo'   => $totalFactura,
                // saldo_proximo negativo = el cliente debe el total
                'saldo_proximo'    => -$totalFactura,
            ]);

            // 2. Soft-delete de la consignación (deleted_at = now())
            $cs->update([
                'observacion' => trim(($cs->observacion ? $cs->observacion . ' | ' : '') . $firma),
            ]);
            $cs->delete(); // SoftDeletes → pone deleted_at
        });

        return response()->json([
            'success'    => true,
            'message'    => '✅ Consignación anulada y factura convertida a préstamo.',
            'banco_id'   => $cs->banco_cuenta_id,
            'nuevo_saldo' => Consignacion::saldoBanco($aliadoId, $cs->banco_cuenta_id),
        ]);
    }

    // ── Calcular datos del período ────────────────────────────────────
    // ═════════════════════════════════════════════════════════════════
    //  Modelo por día
    // ═════════════════════════════════════════════════════════════════

    /** Normaliza la fecha del filtro; cualquier basura cae en hoy. */
    private function fechaValida(?string $fecha): string
    {
        if (!$fecha) return today()->toDateString();

        try {
            return \Carbon\Carbon::parse($fecha)->toDateString();
        } catch (\Exception $e) {
            return today()->toDateString();
        }
    }

    /** Cuadre (día ya cuadrado) de ese usuario en esa fecha, si existe. */
    private function cuadreDelDia(int $aliadoId, ?int $usuarioId, string $fecha): ?Cuadre
    {
        // El cierre es por persona: la vista de todos juntos no se cuadra ni se bloquea.
        if ($usuarioId === null) {
            return null;
        }

        return Cuadre::where('aliado_id', $aliadoId)
            ->where('usuario_id', $usuarioId)
            ->whereDate('fecha_inicio', $fecha)
            ->whereDate('fecha_fin', $fecha)
            ->with('cerradoPor')
            ->first();
    }

    /**
     * Resumen de caja de un día para un usuario.
     *
     * `saldo_esperado` = base de caja menor + lo recibido en efectivo del día
     * - los gastos en efectivo del día. No arrastra saldo de días anteriores:
     * el cuadre se hace y se entrega por día.
     */
    private function resumenDia(int $aliadoId, ?int $usuarioId, string $fecha, $facturasDia): array
    {
        // Facturas cobradas en efectivo (los préstamos no entran: no hay plata).
        $ingresosEfectivo = (int) $facturasDia
            ->where('es_prestamo', false)
            ->sum('valor_efectivo');

        // Abonos en efectivo a préstamos: plata de cartera recuperada hoy.
        $cobrosCartera = (int) DB::table('abonos')
            ->join('facturas', 'abonos.factura_id', '=', 'facturas.id')
            ->where('facturas.aliado_id', $aliadoId)
            ->where('facturas.es_prestamo', true)
            ->when($usuarioId, fn ($q) => $q->where('abonos.usuario_id', $usuarioId))
            ->whereDate('abonos.fecha', $fecha)
            ->sum('abonos.valor_efectivo');

        // Anticipos en efectivo/Nequi. Los de transferencia ya viven en el
        // saldo del banco, sumarlos aquí sería doble conteo. Igual con el maestro
        // 'distribuido' de un anticipo de empresa: su plata ya está en los hijos.
        $anticiposEfectivo = (int) Anticipo::where('aliado_id', $aliadoId)
            ->when($usuarioId, fn ($q) => $q->where('usuario_id', $usuarioId))
            ->whereIn('forma_pago', ['efectivo', 'nequi'])
            ->whereDate('fecha_pago', $fecha)
            ->whereNotIn('estado', [Anticipo::ESTADO_DEVUELTO, Anticipo::ESTADO_DISTRIBUIDO])
            ->sum('valor');

        // Informativo: lo que se prestó hoy no es ingreso, es cartera. Junto al
        // préstamo formal va la factura que se marcó pagada sin recibir un peso
        // (ver facturasSinPago): también es plata por cobrar, y así el card
        // cuadra con la columna 'prestado' de los canales.
        $sinPago = $this->facturasSinPago($facturasDia);
        $facturasSinPago = $facturasDia->filter(fn ($f) => isset($sinPago[$f->id]));
        $totalPrestado = (int) $facturasDia->where('es_prestamo', true)->sum('total')
                       + (int) $facturasSinPago->sum('total');

        $gastosEfectivo = (int) Gasto::where('aliado_id', $aliadoId)
            ->when($usuarioId, fn ($q) => $q->where('usuario_id', $usuarioId))
            ->whereDate('fecha', $fecha)
            ->where(fn($q) => $q->where('forma_pago', 'efectivo')->orWhere('tipo', 'efectivo_banco'))
            ->sum('valor');

        // Consignado: solo lo que ESTE usuario registró en cuentas bancarias.
        $consignado = (int) Consignacion::where('aliado_id', $aliadoId)
            ->when($usuarioId, fn ($q) => $q->where('usuario_id', $usuarioId))
            ->whereDate('fecha', $fecha)
            ->sum('valor');

        // Total facturado del día. Fuera los retiros (numero_factura = 0) y todo
        // lo que quedó en cero: son papeles sin plata. Afiliaciones y préstamos
        // sí entran — son factura con valor.
        $conValor = $facturasDia->filter(
            fn($f) => (int) $f->numero_factura !== 0 && (float) $f->total > 0
        );

        // Una factura de empresa son varias filas (una por empleado) con el mismo
        // numero_factura: el valor se suma todo, pero se cuenta una sola vez.
        $numFacturas = $conValor->pluck('numero_factura')->unique()->count();

        // Qué mes está cobrando la plata que entró hoy (ver recaudoPorPeriodo).
        $periodos = $this->recaudoPorPeriodo($conValor, $fecha);

        $baseCaja  = $usuarioId === null
            ? CajaMenor::montoActivoTotal($aliadoId)
            : CajaMenor::montoActivo($aliadoId, $usuarioId);
        $recibido  = $ingresosEfectivo + $cobrosCartera + $anticiposEfectivo;

        return [
            'total_facturado'    => (int) $conValor->sum('total'),
            'num_facturas'       => $numFacturas,
            'base_caja'          => $baseCaja,
            'ingresos_efectivo'  => $ingresosEfectivo,
            'cobros_cartera'     => $cobrosCartera,
            'anticipos_efectivo' => $anticiposEfectivo,
            'total_prestado'     => $totalPrestado,
            'periodos'           => $periodos,
            'num_prestamos'      => $facturasDia->where('es_prestamo', true)->count(),
            'num_sin_pago'       => $facturasSinPago->count(),
            'total_sin_pago'     => (int) $facturasSinPago->sum('total'),
            'gastos_efectivo'    => $gastosEfectivo,
            'consignado'         => $consignado,
            'recibido_efectivo'  => $recibido,
            'saldo_esperado'     => $baseCaja + $recibido - $gastosEfectivo,
        ];
    }

    /**
     * Reparte el recaudo del día según el mes que cobra cada factura.
     *
     * `facturas.mes/anio` es el mes que se está cobrando, no el de servicio
     * (ver la convención en Plano::periodoPlano). Comparado con el mes del
     * cuadre, la plata del día cae en tres canastas:
     *
     *   atrasado → cartera vieja que se recupera hoy
     *   del mes  → el ciclo corriente
     *   próximo  → cobro adelantado: entra hoy pero se gasta el mes entrante
     *
     * Sin esta separación, un 31 de mes parece un día de caja enorme cuando en
     * realidad casi todo es plata del ciclo siguiente que hay que guardar para
     * pagar sus planillas.
     */
    private function recaudoPorPeriodo($facturas, string $fecha): array
    {
        $refCuadre = ((int) substr($fecha, 0, 4)) * 12 + (int) substr($fecha, 5, 2);

        $vacio = ['total' => 0, 'efectivo' => 0, 'consignado' => 0, 'num' => 0];
        $g = ['atrasado' => $vacio, 'actual' => $vacio, 'proximo' => $vacio];
        $mesesProximo = [];

        foreach ($facturas as $f) {
            $ref = ((int) $f->anio) * 12 + (int) $f->mes;
            if ($ref <= 0) continue;              // factura vieja sin período

            $k = $ref < $refCuadre ? 'atrasado' : ($ref > $refCuadre ? 'proximo' : 'actual');

            $g[$k]['total']      += (int) $f->total;
            $g[$k]['efectivo']   += (int) $f->valor_efectivo;
            $g[$k]['consignado'] += (int) $f->valor_consignado;
            $g[$k]['num']++;

            if ($k === 'proximo') {
                $etq = sprintf('%04d-%02d', (int) $f->anio, (int) $f->mes);
                $mesesProximo[$etq] = ($mesesProximo[$etq] ?? 0) + 1;
            }
        }

        // Nombre del período que más pesa entre los adelantados, para el card.
        arsort($mesesProximo);
        $g['etiqueta_proximo'] = null;
        if ($mesesProximo) {
            $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
                      'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
            [$anio, $mes] = explode('-', array_key_first($mesesProximo));
            $g['etiqueta_proximo'] = $meses[(int) $mes - 1] . ' ' . $anio
                . (count($mesesProximo) > 1 ? ' y otros' : '');
        }

        return $g;
    }

    /** Facturas que el usuario cobró ese día, con las columnas que usan el resumen y los canales. */
    private function facturasDelDia(int $aliadoId, ?int $usuarioId, string $fecha)
    {
        return Factura::where('aliado_id', $aliadoId)
            ->when($usuarioId, fn ($q) => $q->where('usuario_id', $usuarioId))
            ->whereDate('fecha_pago', $fecha)
            ->get([
                'id', 'tipo', 'estado', 'es_prestamo', 'forma_pago', 'total',
                'valor_efectivo', 'valor_consignado', 'numero_factura', 'factura_retiro_origen_id',
                'saldo_proximo', 'anticipo_aplicado', 'mes', 'anio',
                'total_ss', 'v_eps', 'v_arl', 'v_afp', 'v_caja', 'v_parafiscales',
                'admon', 'admin_asesor', 'seguro', 'afiliacion', 'mensajeria',
                'otros', 'iva', 'retiro', 'mora', 'otros_admon',
                'dist_admon', 'dist_asesor', 'dist_retiro', 'dist_utilidad', 'dist_encargado',
            ]);
    }

    /**
     * Facturas del día que se marcaron pagadas pero no movieron un peso: ni
     * efectivo, ni consignación, ni anticipo previo que las cubriera. El
     * cliente quedó debiendo.
     *
     * Existen porque al facturar se declara una `forma_pago` aunque el valor
     * no se registre. Para la caja no son recaudo — el card de efectivo ya las
     * deja fuera al sumar `valor_efectivo` real — así que los canales tampoco
     * deben contarlas como recaudo: van a la columna de cartera por cobrar.
     *
     * Se mira el LOTE, no la fila: en una factura de empresa el pago suele
     * quedar cargado a una sola fila del mismo `numero_factura` y las demás
     * aparecen en cero sin estar impagas. Solo cuenta como sin pago cuando el
     * lote entero quedó en cero.
     *
     * @return array<int,true> ids de factura, para consultar con isset()
     */
    private function facturasSinPago($facturas): array
    {
        // Plata que recibió cada lote (numero_factura). El 0 es el retiro sin
        // cobro: no agrupa nada, cada fila se mira sola.
        $plataLote = [];
        foreach ($facturas as $f) {
            if ($f->es_prestamo) continue;
            $llave = (int) $f->numero_factura !== 0 ? 'n' . $f->numero_factura : 'f' . $f->id;
            $plataLote[$llave] = ($plataLote[$llave] ?? 0)
                + (float) $f->valor_efectivo
                + (float) $f->valor_consignado
                + (float) $f->anticipo_aplicado;
        }

        $sinPago = [];
        foreach ($facturas as $f) {
            if ($f->es_prestamo || (float) $f->total <= 0) continue;

            $llave = (int) $f->numero_factura !== 0 ? 'n' . $f->numero_factura : 'f' . $f->id;
            if (($plataLote[$llave] ?? 0) >= 1) continue;   // el lote sí recibió plata

            // saldo_proximo = pagado - total al facturar. Si no es negativo, la
            // factura quedó cubierta y no hay deuda que llevar a cartera.
            if ($f->saldo_proximo !== null && (float) $f->saldo_proximo >= 0) continue;

            $sinPago[$f->id] = true;
        }

        return $sinPago;
    }

    /**
     * Cómo entró la plata de una factura: pesos para repartir sus componentes
     * entre efectivo / consignado / prestado.
     *
     * Se reparte por lo realmente pagado (así una factura mixta divide cada
     * componente en la misma proporción). Cuando no se movió plata — la pagó
     * un anticipo recibido antes, o quedó saldo pendiente — se clasifica por
     * la forma de pago declarada.
     *
     * La planilla sin cobro (omisos, correcciones: total en cero pero con
     * seguridad social liquidada) no reparte nada: esa plata nunca entró y no
     * es recaudo del día. Es el mismo criterio con el que el resumen deja los
     * papeles sin plata fuera del total facturado.
     *
     * `$sinPago` marca la factura que quedó debiendo (ver facturasSinPago):
     * va entera a cartera, igual que un préstamo. Sin eso caía en el último
     * caso — clasificar por la forma de pago declarada — y los canales
     * contaban como efectivo una plata que nunca llegó a la caja.
     */
    private function pesosPago(Factura $f, bool $sinPago = false): array
    {
        if ($f->es_prestamo || $f->forma_pago === 'prestamo' || $f->estado === Factura::ESTADO_PRESTAMO || $sinPago) {
            return ['efectivo' => 0.0, 'consignado' => 0.0, 'prestado' => 1.0];
        }

        $ef   = (float) $f->valor_efectivo;
        $co   = (float) $f->valor_consignado;
        $base = $ef + $co;

        if ($base > 0) {
            return ['efectivo' => $ef / $base, 'consignado' => $co / $base, 'prestado' => 0.0];
        }

        if ((float) $f->total <= 0) {
            return ['efectivo' => 0.0, 'consignado' => 0.0, 'prestado' => 0.0];
        }

        return $f->forma_pago === 'consignacion'
            ? ['efectivo' => 0.0, 'consignado' => 1.0, 'prestado' => 0.0]
            : ['efectivo' => 1.0, 'consignado' => 0.0, 'prestado' => 0.0];
    }

    /**
     * Los 3 canales del informe financiero, pero solo del día y del usuario, y
     * con cada renglón partido en efectivo / consignado / prestado.
     *
     * Cada canal totaliza lo suyo; los tres NO suman el total facturado del día
     * (igual que en el informe financiero: hay componentes fuera de canal).
     */
    private function canalesDelDia($facturas): array
    {
        $sum = [];
        $conteo = ['planilla' => 0, 'afiliacion' => 0, 'otro' => 0, 'prestamo' => 0, 'retiro' => 0];
        $sinPago = $this->facturasSinPago($facturas);

        foreach ($facturas as $f) {
            $w = $this->pesosPago($f, isset($sinPago[$f->id]));

            // Papel sin plata (planilla de omiso, corrección): no reparte nada
            // y tampoco cuenta como factura del día.
            if ($w['efectivo'] + $w['consignado'] + $w['prestado'] <= 0) {
                continue;
            }

            $add = function (string $slug, $valor) use (&$sum, $w) {
                $v = (float) $valor;
                if (abs($v) < 0.005) return;
                foreach (['efectivo', 'consignado', 'prestado'] as $col) {
                    $sum[$slug][$col] = ($sum[$slug][$col] ?? 0.0) + $v * $w[$col];
                }
            };

            $etiqueta = $this->etiquetaTipoFactura($f);
            $conteo[match ($etiqueta) {
                'afiliacion'   => 'afiliacion',
                'prestamo'     => 'prestamo',
                'retiro'       => 'retiro',
                'otro_ingreso' => 'otro',
                default        => 'planilla',
            }]++;

            if ($f->tipo === 'afiliacion') {
                $add('afiliacion',     $f->afiliacion);
                $add('dist_admon',     $f->dist_admon);
                $add('dist_asesor',    $f->dist_asesor);
                $add('dist_retiro',    $f->dist_retiro);
                $add('dist_utilidad',  $f->dist_utilidad);
                $add('dist_encargado', $f->dist_encargado);
            } elseif ($f->tipo === 'otro_ingreso') {
                // Trámites: en este tipo 'otros' es ingreso propio, no SS.
                $add('tramites', (float) $f->admon + (float) $f->otros);
            } else {
                $add('admon',        $f->admon);
                $add('seguro',       $f->seguro);
                $add('mensajeria',   $f->mensajeria);
                $add('iva',          $f->iva);
                $add('otros_admon',  $f->otros_admon);
                $add('retiro_campo', $f->retiro);
                // La comisión del asesor se le cobra al cliente aparte de la
                // administración: son dos columnas distintas del contrato y las
                // dos entran en el total de la factura. Iba como renglón
                // informativo fuera del total del canal, y por eso el canal 1
                // quedaba corto justo por este valor cada vez que un contrato
                // traía asesor.
                $add('admin_asesor', $f->admin_asesor);
                $add('otros_ss',     $f->otros);          // 'otros' pertenece al canal SS
            }

            $add('eps',  $f->v_eps);
            $add('arl',  $f->v_arl);
            $add('afp',  $f->v_afp);
            $add('caja', $f->v_caja);
            // SENA e ICBF del aportante no exonerado: es plata que se va a la
            // planilla como los demás aportes, no ingreso del aliado.
            $add('parafiscales', $f->v_parafiscales);
            $add('mora', $f->mora);
        }

        $fila = function (string $etiqueta, string $slug, string $color) use ($sum) {
            $v = $sum[$slug] ?? [];
            return [
                'etiqueta'   => $etiqueta,
                'color'      => $color,
                'efectivo'   => (float) ($v['efectivo']   ?? 0),
                'consignado' => (float) ($v['consignado'] ?? 0),
                'prestado'   => (float) ($v['prestado']   ?? 0),
            ];
        };
        $totalizar = function (array $filas) {
            $t = ['efectivo' => 0.0, 'consignado' => 0.0, 'prestado' => 0.0];
            foreach ($filas as $f) {
                foreach ($t as $k => $_) $t[$k] += $f[$k];
            }
            return $t;
        };

        // ── Canal 1: Administración ──────────────────────────────────────
        $c1 = [
            $fila('Administración',   'admon',        '#3b82f6'),
            $fila('Comisión asesor',  'admin_asesor', '#f59e0b'),
            $fila('Seguro',           'seguro',       '#0ea5e9'),
            $fila('Mensajería',       'mensajeria',   '#06b6d4'),
            $fila('IVA',              'iva',          '#8b5cf6'),
            $fila('Otros admon',      'otros_admon',  '#a78bfa'),
            $fila('Comisión retiros', 'retiro_campo', '#c2410c'),
            $fila('Trámites',         'tramites',     '#10b981'),
        ];

        // ── Canal 2: Afiliaciones (distribución del ingreso) ─────────────
        $bruto  = $fila('Total distribución (bruto)', 'afiliacion', '#5b21b6');
        $c2 = [
            $fila('→ Admon',              'dist_admon',     '#3b82f6'),
            $fila('→ Comisión asesor',    'dist_asesor',    '#f59e0b'),
            $fila('→ Retiro',             'dist_retiro',    '#c2410c'),
            $fila('→ Utilidad',           'dist_utilidad',  '#16a34a'),
            $fila('→ Comisión encargado', 'dist_encargado', '#8b5cf6'),
        ];
        // Lo que quedó sin repartir en dist_*: cuadra el bruto con la distribución.
        $repartido = $totalizar($c2);
        $c2[] = [
            'etiqueta'   => '→ Sin distribuir',
            'color'      => '#94a3b8',
            'efectivo'   => $bruto['efectivo']   - $repartido['efectivo'],
            'consignado' => $bruto['consignado'] - $repartido['consignado'],
            'prestado'   => $bruto['prestado']   - $repartido['prestado'],
        ];

        // ── Canal 3: Seguridad Social ────────────────────────────────────
        $c3 = [
            $fila('EPS',      'eps',      '#0d9488'),
            $fila('ARL',      'arl',      '#14b8a6'),
            $fila('Pensión',  'afp',      '#2dd4bf'),
            $fila('Caja',     'caja',     '#5eead4'),
            $fila('Parafiscales', 'parafiscales', '#f97316'),
            // 'Otros SS' es el campo `otros` del modal ("Otros planilla"): lo que
            // se le suma a la planilla y no es EPS, ARL, pensión ni caja. Va en
            // este canal y no en administración porque no lo gana el aliado: se
            // paga con el resto de los aportes.
            $fila('Otros SS', 'otros_ss', '#99f6e4'),
            // La mora al cliente no es ingreso del aliado: entra para cubrir lo que
            // la planilla cobra de mas por pagarse tarde. Va en este canal, que es
            // donde ya la cuenta el informe financiero (moraRecogida en el canal SS).
            $fila('Mora',     'mora',     '#f43f5e'),
        ];

        $limpiar = fn(array $filas) => array_values(array_filter(
            $filas,
            fn($f) => abs($f['efectivo']) >= 1 || abs($f['consignado']) >= 1 || abs($f['prestado']) >= 1
        ));

        // Sin préstamos en el día, la columna sobra y se oculta en la vista.
        $hayPrestado = collect(array_merge($c1, $c2, $c3))
            ->contains(fn($f) => abs($f['prestado']) >= 1);

        return [
            'conteo'       => $conteo,
            'hay_prestado' => $hayPrestado,
            'canales'      => [
                [
                    'n' => 1, 'titulo' => '💼 Administración', 'gradiente' => '#1e3a8a,#2563eb',
                    'subtitulo' => 'Ingresos cobrados',
                    'filas' => $limpiar($c1), 'total' => $totalizar($limpiar($c1)),
                ],
                [
                    'n' => 2, 'titulo' => '🔖 Afiliaciones', 'gradiente' => '#5b21b6,#7c3aed',
                    'subtitulo' => 'Distribución del ingreso',
                    'filas' => $limpiar($c2), 'total' => $bruto,
                ],
                [
                    'n' => 3, 'titulo' => '🏥 Seguridad Social', 'gradiente' => '#0f766e,#0d9488',
                    'subtitulo' => 'Recaudo del día',
                    'filas' => $limpiar($c3), 'total' => $totalizar($limpiar($c3)),
                ],
            ],
        ];
    }

    /**
     * Gastos que el usuario registró ese día.
     *
     * Los pagos de planilla quedan fuera: son plata de la seguridad social
     * saliendo del banco, no un gasto de la caja del usuario. Se ven en la
     * conciliación de bancos.
     */
    private function gastosDelDia(int $aliadoId, ?int $usuarioId, string $fecha)
    {
        return Gasto::where('aliado_id', $aliadoId)
            ->when($usuarioId, fn ($q) => $q->where('usuario_id', $usuarioId))
            ->whereDate('fecha', $fecha)
            ->where('tipo', '!=', 'pago_planilla')
            ->with(['bancoOrigen', 'bancoDestino'])
            ->orderBy('id')
            ->get();
    }

    /** Consignaciones que el usuario registró ese día, agrupadas por cuenta. */
    private function consignacionesDelDia(int $aliadoId, ?int $usuarioId, string $fecha)
    {
        return Consignacion::where('aliado_id', $aliadoId)
            ->when($usuarioId, fn ($q) => $q->where('usuario_id', $usuarioId))
            ->whereDate('fecha', $fecha)
            ->with(['bancoCuenta', 'factura:id,numero_factura,cedula'])
            ->orderByDesc('valor')
            ->get()
            ->groupBy('banco_cuenta_id');
    }

    // ═════════════════════════════════════════════════════════════════
    //  Cuadres históricos (modelo anterior, por período)
    // ═════════════════════════════════════════════════════════════════

    private function calcularPeriodo(Cuadre $cuadre, int $aliadoId, int $usuarioId): array
    {
        $inicio = $cuadre->fecha_inicio->toDateString();
        $fin    = ($cuadre->fecha_fin ?? today())->toDateString();

        // Ingresos en efectivo del período (facturas normales — excluye préstamos)
        // Los préstamos tienen valor_efectivo=0 → ya quedan excluidos naturalmente,
        // pero filtramos explícitamente para mayor claridad.
        $ingresosEfectivo = (int) Factura::where('aliado_id', $aliadoId)
            ->where('usuario_id', $usuarioId)
            ->whereBetween('fecha_pago', [$inicio, $fin])
            ->where('es_prestamo', false)
            ->whereNotNull('valor_efectivo')
            ->sum('valor_efectivo');

        // ── Cobros de cartera: efectivo de abonos a préstamos del período ──
        // La fecha del abono (cuando entró la plata) puede ser diferente al mes del servicio.
        $cobrosCartera = (int) DB::table('abonos')
            ->join('facturas', 'abonos.factura_id', '=', 'facturas.id')
            ->where('facturas.aliado_id', $aliadoId)
            ->where('facturas.es_prestamo', true)
            ->where('abonos.usuario_id', $usuarioId)
            ->whereBetween('abonos.fecha', [$inicio, $fin])
            ->sum('abonos.valor_efectivo');

        // ── Total prestado en el período (informativo, no es ingreso real) ──
        $totalPrestado = (int) Factura::where('aliado_id', $aliadoId)
            ->where('usuario_id', $usuarioId)
            ->where('es_prestamo', true)
            ->whereBetween('fecha_pago', [$inicio, $fin])
            ->sum('total');

        // Gastos en efectivo del período
        $gastosEfectivo = (int) Gasto::where('cuadre_id', $cuadre->id)
            ->where(fn($q) => $q->where('forma_pago', 'efectivo')
                ->orWhere('tipo', 'efectivo_banco'))
            ->sum('valor');

        // ── Anticipos en efectivo/Nequi del período (ingreso real del día) ───────────────────────
        // REGLA CLAVE:
        //   • Anticipo recibido en ABRIL  → aparece como ingreso en el cuadre de ABRIL.
        //   • Cuando se factura en MAYO   → anticipo_aplicado en la factura de mayo.
        //     El cuadre de MAYO NO suma ese dinero (ya fue contado en abril).
        //
        // Solo se suman formas de pago que no pasan por banco:
        //   efectivo          → entra al flujo de efectivo del cuadre.
        //   nequi             → idem (solo registros históricos; nueva UI solo usa efectivo/transferencia).
        //   transferencia     → ya se refleja en Consignacion (saldo banco),
        //                        NO se suma aquí para evitar doble conteo.
        $anticiposEfectivo = (int) Anticipo::where('aliado_id', $aliadoId)
            ->where('usuario_id', $usuarioId)
            ->whereIn('forma_pago', ['efectivo', 'nequi'])
            ->whereBetween('fecha_pago', [$inicio, $fin])
            ->whereNotIn('estado', [Anticipo::ESTADO_DEVUELTO, Anticipo::ESTADO_DISTRIBUIDO])
            ->sum('valor');

        $saldoInicial = $cuadre->saldo_apertura;
        // Saldo = apertura + ingresos facturas + cartera + anticipos efectivo/nequi - gastos
        $saldoFinal   = $saldoInicial + $ingresosEfectivo + $cobrosCartera + $anticiposEfectivo - $gastosEfectivo;

        // Por día
        $dias = $cuadre->diasDelPeriodo();
        $saldoAcum = $saldoInicial;
        $porDia = $dias->map(function($dia) use ($cuadre, $aliadoId, $usuarioId, &$saldoAcum) {
            $fechaDia = $dia->toDateString();

            $ingDia = (int) Factura::where('aliado_id', $aliadoId)
                ->where('usuario_id', $usuarioId)
                ->whereDate('fecha_pago', $fechaDia)
                ->where('es_prestamo', false)
                ->sum('valor_efectivo');

            // Cobros de cartera del día
            $carteraDia = (int) DB::table('abonos')
                ->join('facturas', 'abonos.factura_id', '=', 'facturas.id')
                ->where('facturas.aliado_id', $aliadoId)
                ->where('facturas.es_prestamo', true)
                ->where('abonos.usuario_id', $usuarioId)
                ->whereDate('abonos.fecha', $fechaDia)
                ->sum('abonos.valor_efectivo');

            // Anticipos efectivo/Nequi del día
            $anticipoDia = (int) Anticipo::where('aliado_id', $aliadoId)
                ->where('usuario_id', $usuarioId)
                ->whereIn('forma_pago', ['efectivo', 'nequi'])
                ->whereDate('fecha_pago', $fechaDia)
                ->whereNotIn('estado', [Anticipo::ESTADO_DEVUELTO, Anticipo::ESTADO_DISTRIBUIDO])
                ->sum('valor');

            $gastoDia = (int) Gasto::where('cuadre_id', $cuadre->id)
                ->whereDate('fecha', $fechaDia)
                ->where(fn($q) => $q->where('forma_pago', 'efectivo')
                    ->orWhere('tipo', 'efectivo_banco'))
                ->sum('valor');

            $saldoAcum += $ingDia + $carteraDia + $anticipoDia - $gastoDia;

            return [
                'fecha'        => $dia,
                'ingresos'     => $ingDia,
                'cartera'      => $carteraDia,
                'anticipos'    => $anticipoDia,
                'gastos'       => $gastoDia,
                'saldo'        => $saldoAcum,
            ];
        });

        return [
            'efectivo_total'    => $ingresosEfectivo,
            'cobros_cartera'    => $cobrosCartera,
            'total_prestado'    => $totalPrestado,
            'anticipos_efectivo'=> $anticiposEfectivo,  // ⇐ anticipos efectivo/nequi del período
            'gastos_efectivo'   => $gastosEfectivo,
            'saldo_inicial'     => $saldoInicial,
            'saldo_final'       => $saldoFinal,
            'por_dia'           => $porDia,
        ];
    }


    /** Facturas del período del cuadre */
    private function facturasPeriodo(Cuadre $cuadre, int $aliadoId, int $usuarioId): \Illuminate\Support\Collection
    {
        $inicio = $cuadre->fecha_inicio->toDateString();
        $fin    = ($cuadre->fecha_fin ?? today())->toDateString();

        return Factura::where('aliado_id', $aliadoId)
            ->where('usuario_id', $usuarioId)
            ->whereBetween('fecha_pago', [$inicio, $fin])
            ->with(['empresa', 'contrato', 'consignaciones.bancoCuenta'])
            ->orderBy('fecha_pago')
            ->get();
    }

    // ═════════════════════════════════════════════════════════════════
    //  Facturas del día
    // ═════════════════════════════════════════════════════════════════

    /**
     * Tipos "de negocio" del listado. No son los valores de `facturas.tipo`:
     * en BD solo existen planilla / afiliacion / otro_ingreso. Préstamo y
     * retiro se derivan de otras columnas (ver etiquetaTipoFactura).
     */
    private const TIPOS_FACTURA_DIA = [
        'planilla'     => 'Planilla',
        'afiliacion'   => 'Afiliación',
        'retiro'       => 'Retiro',
        'prestamo'     => 'Préstamo',
        'otro_ingreso' => 'Otro ingreso',
    ];

    private const FORMAS_PAGO_DIA = [
        'efectivo'     => 'Efectivo',
        'consignacion' => 'Consignación',
        'mixto'        => 'Mixta',
        'prestamo'     => 'Préstamo',
    ];

    /** Vista: todas las facturas de un día, con filtros. Solo admin. */
    public function facturasDia(Request $request)
    {
        if (!Auth::user()->hasRole(['admin', 'superadmin'])) {
            abort(403, 'Solo administradores pueden ver las facturas del día.');
        }

        return view('admin.cuadre-diario.facturas-dia', $this->datosFacturasDia($request));
    }

    /** Exporta a Excel el listado del día respetando los filtros activos. */
    public function exportarFacturasDia(Request $request)
    {
        if (!Auth::user()->hasRole(['admin', 'superadmin'])) {
            abort(403, 'Solo administradores pueden exportar las facturas del día.');
        }

        $datos    = $this->datosFacturasDia($request);
        $facturas = $datos['facturas'];

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Facturas del día');

        // La columna de parafiscales solo existe los días que la necesitan, igual
        // que en la pantalla: si se dejara fija, todos los exports arrastrarían
        // una columna en ceros.
        $conParaf = $datos['hayParafiscales'] ?? false;
        $headers = array_merge(
            ['No.', 'Factura', 'Tipo', 'Cédula', 'Nombres', 'Forma pago',
             'Pago total', 'Efectivo', 'Consignado', 'Admón empresa', 'Admón asesor',
             'Seguro'],
            $conParaf ? ['Parafiscales'] : [],
            ['Seg. social', 'IVA',
             'Empresa', 'Razón social', 'Modalidad', 'Banco', 'Facturó'],
        );
        $ultimaCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle("A1:{$ultimaCol}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1e40af']],
        ]);

        $row = 2;
        foreach ($facturas as $i => $f) {
            $sheet->fromArray(array_merge([
                $i + 1,
                $f->numero_factura,
                self::TIPOS_FACTURA_DIA[$f->tipo_dia] ?? $f->tipo_dia,
                $f->cedula,
                $f->nombre_cliente,
                self::FORMAS_PAGO_DIA[$f->forma_pago] ?? $f->forma_pago,
                (int) $f->total,
                (int) $f->valor_efectivo,
                (int) $f->valor_consignado,
                (int) $f->admon,
                (int) $f->admin_asesor,
                (int) $f->seguro,
            ], $conParaf ? [(int) $f->v_parafiscales] : [], [
                (int) $f->total_ss,
                (int) $f->iva,
                $f->empresa?->empresa ?? 'INDIVIDUALES',
                $f->razon_social_texto,
                $f->modalidad_nombre,
                $f->banco_texto ?: '—',
                $f->usuario?->nombre ?? '—',
                // strictNullComparison: sin esto fromArray compara con != y
                // descarta los 0, dejando las celdas de valores en blanco.
            ]), null, "A{$row}", true);

            // Las columnas de plata van de "Pago total" (G) hasta IVA, que se
            // corre un puesto cuando el día lleva parafiscales.
            $colDinero = $conParaf ? 'O' : 'N';
            $sheet->getStyle("G{$row}:{$colDinero}{$row}")->getNumberFormat()->setFormatCode('$#,##0');
            $row++;
        }

        foreach (range('A', $ultimaCol) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer   = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $filename = 'facturas_del_dia_' . $datos['fecha'] . '.xlsx';
        $tmpPath  = tempnam(sys_get_temp_dir(), 'facdia');
        $writer->save($tmpPath);

        return response()->download($tmpPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Datos compartidos por la vista y la exportación.
     * Las facturas anuladas quedan fuera (SoftDeletes del modelo).
     */
    private function datosFacturasDia(Request $request): array
    {
        $aliadoId = session('aliado_id_activo');
        // El default de input() no aplica a `?fecha=` (llega como null por
        // ConvertEmptyStringsToNull): pasa al borrar el día en el selector.
        $fecha    = $this->fechaValida($request->input('fecha'));

        $fTipo     = $request->input('tipo');
        $fForma    = $request->input('forma_pago');
        $fBanco    = $request->input('banco_cuenta_id');
        $fEmpresa  = $request->input('empresa_id');   // 'individuales' = sin empresa
        $fUsuario  = $request->input('usuario_id');
        $fRazon    = $request->input('razon_social_id');
        // 'sin' = facturas sin contrato. Ojo: la modalidad más común
        // (Dependiente E) tiene id 0, así que no sirve un chequeo por truthy.
        $fModal    = $request->input('tipo_modalidad_id');
        $fModal    = ($fModal === null || $fModal === '') ? null : $fModal;

        $sort = in_array($request->input('sort'), ['factura', 'cedula'], true)
            ? $request->input('sort') : null;
        $dir  = $request->input('dir') === 'desc' ? 'desc' : 'asc';

        // `facturas.razon_social_id` casi nunca viene poblado (7.5k de 282k filas);
        // la razón social real vive en el contrato (99.8% de cobertura), así que
        // se carga también para usarla como respaldo.
        $query = Factura::where('aliado_id', $aliadoId)
            ->whereDate('fecha_pago', $fecha)
            ->with(['empresa', 'razonSocial', 'usuario', 'contrato.razonSocial',
                   'contrato.tipoModalidad', 'consignaciones.bancoCuenta']);

        // ── Tipo derivado (misma precedencia que etiquetaTipoFactura) ──
        if ($fTipo && isset(self::TIPOS_FACTURA_DIA[$fTipo])) {
            if ($fTipo === 'retiro') {
                $query->where(fn($q) => $this->filtroEsRetiro($q));
            } elseif ($fTipo === 'prestamo') {
                $query->where(fn($q) => $this->filtroNoEsRetiro($q))
                      ->where(fn($q) => $this->filtroEsPrestamo($q));
            } else {
                $query->where('tipo', $fTipo)
                      ->where(fn($q) => $this->filtroNoEsRetiro($q))
                      ->where(fn($q) => $this->filtroNoEsPrestamo($q));
            }
        }

        if ($fForma)   { $query->where('forma_pago', $fForma); }
        if ($fUsuario) { $query->where('usuario_id', $fUsuario); }

        if ($fBanco) {
            $query->whereHas('consignaciones',
                fn($q) => $q->where('banco_cuenta_id', $fBanco));
        }

        if ($fEmpresa === 'individuales') {
            $query->whereNull('empresa_id');
        } elseif ($fEmpresa) {
            $query->where('empresa_id', $fEmpresa);
        }

        // Razón social: la de la factura si existe, si no la del contrato.
        // Debe replicar el mismo respaldo que usa razon_social_texto.
        if ($fRazon) {
            $query->where(function ($q) use ($fRazon) {
                $q->where('razon_social_id', $fRazon)
                  ->orWhere(fn($q2) => $q2->whereNull('razon_social_id')
                      ->whereHas('contrato', fn($c) => $c->where('razon_social_id', $fRazon)));
            });
        }

        // Modalidad: vive en el contrato, no en la factura.
        if ($fModal !== null) {
            if ($fModal === 'sin') {
                $query->whereDoesntHave('contrato',
                    fn($c) => $c->whereNotNull('tipo_modalidad_id'));
            } else {
                $query->whereHas('contrato',
                    fn($c) => $c->where('tipo_modalidad_id', (int) $fModal));
            }
        }

        if ($sort === 'factura') {
            $query->orderBy('numero_factura', $dir)->orderBy('id', $dir);
        } elseif ($sort === 'cedula') {
            $query->orderBy('cedula', $dir)->orderBy('id', $dir);
        } else {
            // Orden por defecto: hay ~24k facturas históricas con
            // numero_factura = 0 (retiros sin numerar); van al final para no
            // encabezar el listado. Al ordenar a mano se respeta el orden literal.
            $query->orderByRaw('CASE WHEN numero_factura > 0 THEN 0 ELSE 1 END')
                  ->orderBy('numero_factura')
                  ->orderBy('id');
        }

        $facturas = $query->get();

        // ── Nombres de cliente en un solo query (evita N+1 por cédula) ──
        $nombres = $facturas->isEmpty()
            ? collect()
            : \App\Models\Cliente::where('aliado_id', $aliadoId)
                ->whereIn('cedula', $facturas->pluck('cedula')->unique()->all())
                ->get(['cedula', 'primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido'])
                ->keyBy('cedula');

        foreach ($facturas as $f) {
            $f->tipo_dia       = $this->etiquetaTipoFactura($f);
            // nombre_corto = primer nombre + primer apellido
            $f->nombre_cliente = $nombres->get($f->cedula)?->nombre_corto ?? '—';

            $f->razon_social_texto = $f->razonSocial?->razon_social
                ?? $f->contrato?->razonSocial?->razon_social
                ?? '—';

            // Modalidad: solo vive en el contrato. Las facturas sin contrato
            // (otros ingresos, préstamos sueltos) quedan sin modalidad.
            $f->modalidad_texto  = $f->contrato?->tipoModalidad?->tipo_modalidad ?: '—';
            $f->modalidad_nombre = $f->contrato?->tipoModalidad?->nombre ?: '—';

            // Cuenta destino: se muestra el titular, que es lo que identifica la
            // cuenta en la operación. Las facturas sincronizadas desde el legacy
            // no traen consignaciones, así que aquí puede quedar vacío.
            $f->banco_texto = $f->consignaciones
                ->pluck('bancoCuenta')->filter()
                ->map(fn($bc) => $bc->nombre ?: $bc->banco)
                ->unique()->implode(', ');
        }

        $totales = [
            'cantidad'   => $facturas->count(),
            'total'      => (int) $facturas->sum('total'),
            'efectivo'   => (int) $facturas->sum('valor_efectivo'),
            'consignado' => (int) $facturas->sum('valor_consignado'),
            'admon'      => (int) $facturas->sum('admon'),
            'asesor'     => (int) $facturas->sum('admin_asesor'),
            'seg_social' => (int) $facturas->sum('total_ss'),
            // Va dentro de seg_social; se totaliza aparte solo para poder
            // mostrarlo desglosado cuando el día trae algún aportante no exonerado.
            'parafiscales' => (int) $facturas->sum('v_parafiscales'),
            'iva'        => (int) $facturas->sum('iva'),
            'pendiente'  => (int) $facturas->sum(fn($f) => min(0, (int)($f->saldo_proximo ?? 0))) * -1,
        ];

        // Cuánto recibió cada quien: desglose por el usuario que facturó.
        // Solo quien movió plata — sin esto aparecen los usuarios cuyas
        // facturas del día son todas de valor 0 (retiros, por ejemplo).
        $porUsuario = $facturas
            ->groupBy(fn($f) => $f->usuario?->nombre ?? 'Sin usuario')
            ->map(fn($g) => [
                'cantidad'   => $g->count(),
                'total'      => (int) $g->sum('total'),
                'efectivo'   => (int) $g->sum('valor_efectivo'),
                'consignado' => (int) $g->sum('valor_consignado'),
            ])
            ->filter(fn($t) => $t['efectivo'] > 0 || $t['consignado'] > 0)
            ->sortByDesc('total');

        return [
            'fecha'      => $fecha,
            'facturas'   => $facturas,
            'totales'    => $totales,
            'porUsuario' => $porUsuario,
            'tipos'      => self::TIPOS_FACTURA_DIA,
            'formas'     => self::FORMAS_PAGO_DIA,
            'sort'       => $sort,
            'dir'        => $dir,
            // Casi ningún día tiene parafiscales: la columna solo se dibuja
            // cuando alguna factura del día trae valor, para no cargar la tabla
            // con un renglón en ceros.
            'hayParafiscales' => $totales['parafiscales'] > 0,
        ] + $this->opcionesFacturasDia($aliadoId, $fecha);
    }

    /**
     * Opciones de los desplegables del encabezado: solo lo que realmente
     * existe ese día, sin aplicar los filtros activos (así nunca queda un
     * desplegable vacío del que no se pueda salir).
     */
    private function opcionesFacturasDia(int $aliadoId, string $fecha): array
    {
        $base = Factura::where('aliado_id', $aliadoId)
            ->whereDate('fecha_pago', $fecha)
            ->with(['empresa:id,empresa', 'usuario:id,nombre',
                    'razonSocial:id,razon_social',
                    'contrato:id,razon_social_id,tipo_modalidad_id',
                    'contrato.razonSocial:id,razon_social',
                    'contrato.tipoModalidad'])
            // numero_factura es obligatorio aquí: etiquetaTipoFactura lo usa
            // para detectar retiros y sin él todo se clasificaría como retiro.
            ->get(['id', 'tipo', 'numero_factura', 'es_prestamo', 'estado',
                   'factura_retiro_origen_id', 'forma_pago', 'empresa_id',
                   'usuario_id', 'razon_social_id', 'contrato_id']);

        $bancoIds = $base->isEmpty() ? collect() : DB::table('consignaciones')
            ->whereIn('factura_id', $base->pluck('id')->all())
            ->whereNull('deleted_at')
            ->whereNotNull('banco_cuenta_id')
            ->distinct()->pluck('banco_cuenta_id');

        $tiposDisp = $base->map(fn($f) => $this->etiquetaTipoFactura($f))->unique();
        $formasDisp = $base->pluck('forma_pago')->filter()->unique();

        return [
            'tiposDisp'  => collect(self::TIPOS_FACTURA_DIA)
                                ->filter(fn($l, $k) => $tiposDisp->contains($k)),
            'formasDisp' => collect(self::FORMAS_PAGO_DIA)
                                ->filter(fn($l, $k) => $formasDisp->contains($k)),
            'bancosDisp' => BancoCuenta::whereIn('id', $bancoIds)->orderBy('nombre')->get(),
            'hayIndiv'   => $base->contains(fn($f) => $f->empresa_id === null),
            'empresasDisp' => $base->pluck('empresa')->filter()->unique('id')
                                ->sortBy('empresa')->values(),
            'usuariosDisp' => $base->pluck('usuario')->filter()->unique('id')
                                ->sortBy('nombre')->values(),
            // Mismo respaldo al contrato que usa razon_social_texto
            'razonesDisp'  => $base->map(fn($f) => $f->razonSocial ?? $f->contrato?->razonSocial)
                                ->filter()->unique('id')->sortBy('razon_social')->values(),
            'modalidadesDisp' => $base->map(fn($f) => $f->contrato?->tipoModalidad)
                                ->filter()->unique('id')->sortBy('orden')->values(),
            'haySinModal'  => $base->contains(fn($f) => !$f->contrato?->tipoModalidad),
        ];
    }

    /**
     * Etiqueta de tipo mostrada en el listado.
     * Precedencia: retiro → préstamo → valor de `facturas.tipo`.
     *
     * Un retiro es la "factura 0": numero_factura = 0, sin cobro asociado.
     * `factura_retiro_origen_id` marca el caso inverso (el retiro que sí se
     * facturó desde empresa y por eso tiene número propio); también es retiro.
     */
    private function etiquetaTipoFactura(Factura $f): string
    {
        if ((int) $f->numero_factura === 0 || $f->factura_retiro_origen_id)  return 'retiro';
        if ($f->es_prestamo || $f->estado === Factura::ESTADO_PRESTAMO)      return 'prestamo';

        return $f->tipo ?: 'otro_ingreso';
    }

    private function filtroEsRetiro($q)
    {
        return $q->where('numero_factura', 0)->orWhereNotNull('factura_retiro_origen_id');
    }

    private function filtroNoEsRetiro($q)
    {
        return $q->where('numero_factura', '!=', 0)->whereNull('factura_retiro_origen_id');
    }

    private function filtroEsPrestamo($q)
    {
        return $q->where('es_prestamo', true)->orWhere('estado', Factura::ESTADO_PRESTAMO);
    }

    private function filtroNoEsPrestamo($q)
    {
        return $q->where(fn($s) => $s->where('es_prestamo', false)->orWhereNull('es_prestamo'))
                 ->where('estado', '!=', Factura::ESTADO_PRESTAMO);
    }
}
