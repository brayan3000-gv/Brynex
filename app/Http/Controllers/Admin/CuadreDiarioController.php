<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\{Cuadre, Gasto, CajaMenor, CajaMovimiento, Consignacion, BancoCuenta, Factura, User, Anticipo};
use App\Services\Caja\FlujoEfectivo;
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

        // Entregas, retiros y saldos iniciales del día que tocan a la persona.
        $movimientosCaja = CajaMovimiento::where('aliado_id', $aliadoId)
            ->whereDate('fecha', $fecha)
            ->when($usuarioId, fn ($q) => $q->where(fn ($w) => $w
                ->where('usuario_origen_id', $usuarioId)->orWhere('usuario_destino_id', $usuarioId)))
            ->with(['origen:id,nombre', 'destino:id,nombre', 'registradoPor:id,nombre'])
            ->orderBy('id')
            ->get();
        $usuariosCaja = $this->usuariosDeCaja($aliadoId);

        return view('admin.cuadre-diario.index', compact(
            'fecha', 'esAdmin', 'esPropio', 'verTodos', 'usuarios', 'usuarioId', 'usuarioVista',
            'bancosFacturacion', 'resumen', 'canales', 'gastos',
            'consignaciones', 'cuadreDia', 'movimientosCaja', 'usuariosCaja'
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
     * Consolidado admin: la caja de TODOS los usuarios en un día, o en un mes
     * completo con `vista=mes` (`mes=AAAA-MM`).
     *
     * Ya no depende de que exista una fila en `cuadres` — se calcula por
     * fecha igual que el índice, y la fila solo aporta el estado (cuadrado
     * o pendiente). Todo en 6 queries agregadas por usuario y día, sin N+1:
     * con eso salen tanto los totales por usuario como los del mes día a día.
     */
    public function consolidado(Request $request)
    {
        if (!Auth::user()->hasRole(['admin', 'superadmin'])) {
            abort(403, 'Solo administradores pueden ver el consolidado.');
        }

        $aliadoId      = session('aliado_id_activo');
        $fecha         = $this->fechaValida($request->input('fecha'));
        $usuarioFiltro = $request->input('usuario_id');
        $vistaMes      = $request->input('vista') === 'mes';
        $mes           = $this->mesValido($request->input('mes'), $fecha);

        [$desde, $hasta] = $vistaMes
            ? [\Carbon\Carbon::parse($mes . '-01')->toDateString(), \Carbon\Carbon::parse($mes . '-01')->endOfMonth()->toDateString()]
            : [$fecha, $fecha];

        // Las mismas personas que manejan caja en el índice, incluidas las de
        // BryNex asignadas al aliado: antes solo salían las del aliado.
        $usuarios = $this->usuariosDeCaja((int) $aliadoId);

        $visibles = $usuarioFiltro
            ? $usuarios->where('id', (int) $usuarioFiltro)
            : $usuarios;
        $usuarioIds = $visibles->pluck('id')->all();

        $enRango = fn($q, string $col) => $q->whereDate($col, '>=', $desde)->whereDate($col, '<=', $hasta);
        $porDia  = fn(string $col) => DB::raw("CAST($col AS date)");

        // Filas (uid, dia, …): sqlsrv devuelve la fecha como texto y a veces con hora.
        $filas = fn($q) => $usuarioIds
            ? $q->get()->each(fn($r) => $r->dia = substr((string) $r->dia, 0, 10))
            : collect();

        // El conteo de facturas del día: no préstamos, como siempre.
        $ingresos = $filas($enRango(DB::table('facturas')
            ->whereNull('deleted_at')
            ->where('aliado_id', $aliadoId)->whereIn('usuario_id', $usuarioIds)
            ->where('es_prestamo', false), 'fecha_pago')
            ->groupBy('usuario_id', $porDia('fecha_pago'))
            ->selectRaw('usuario_id AS uid, CAST(fecha_pago AS date) AS dia, COUNT(*) AS facturas'));

        // El efectivo, con la regla única (FlujoEfectivo): lo que entra incluye
        // lo que otra persona le entregó y lo que sale incluye lo que entregó
        // y los retiros del dueño, para que el neto sea el mismo del índice.
        $flujo = $usuarioIds ? FlujoEfectivo::porUsuarioDia((int) $aliadoId, $usuarioIds, $desde, $hasta) : [];
        $efectivoFlujo = [];
        $gastosFlujo   = [];
        foreach ($flujo as $llave => $c) {
            [$uid, $dia] = explode('|', $llave);
            $t = FlujoEfectivo::resumir([$c]);
            $efectivoFlujo[] = (object) ['uid' => $uid, 'dia' => $dia, 't' => $t['recibido'] + $t['recibidas']];
            $gastosFlujo[]   = (object) ['uid' => $uid, 'dia' => $dia, 't' => $t['salidas'] + $t['dadas'] + $t['retiros']];
        }

        $consignado = $filas($enRango(DB::table('consignaciones')
            ->whereNull('deleted_at')
            ->where('aliado_id', $aliadoId)->whereIn('usuario_id', $usuarioIds), 'fecha')
            ->groupBy('usuario_id', $porDia('fecha'))
            ->selectRaw('usuario_id AS uid, CAST(fecha AS date) AS dia, SUM(ISNULL(valor,0)) AS t'));

        // Días ya cuadrados del rango (modelo por día), por "usuario|día".
        $cuadrados = $enRango(Cuadre::where('aliado_id', $aliadoId)
            ->whereIn('usuario_id', $usuarioIds)
            ->whereColumn('fecha_inicio', 'fecha_fin'), 'fecha_inicio')
            ->with('cerradoPor:id,nombre')
            ->get()
            ->keyBy(fn($c) => $c->usuario_id . '|' . $c->fecha_inicio->toDateString());

        // Una celda por usuario y día con lo que se movió ese día.
        $celdas = [];
        $sumar = function ($rows, array $campos) use (&$celdas) {
            foreach ($rows as $r) {
                $celda = $celdas[(int) $r->uid][$r->dia] ?? [];
                foreach ($campos as $destino => $origen) {
                    $celda[$destino] = ($celda[$destino] ?? 0) + (int) $r->$origen;
                }
                $celdas[(int) $r->uid][$r->dia] = $celda;
            }
        };
        $sumar($ingresos,      ['facturas' => 'facturas']);
        $sumar($efectivoFlujo, ['efectivo' => 't']);
        $sumar($gastosFlujo,   ['gastos' => 't']);
        $sumar($consignado,    ['consignado' => 't']);
        foreach ($cuadrados as $c) {
            $celdas[(int) $c->usuario_id][$c->fecha_inicio->toDateString()] ??= [];
        }

        // Solo los días en que el usuario movió plata (o que quedaron cuadrados).
        $dias = collect();
        foreach ($visibles as $u) {
            foreach ($celdas[$u->id] ?? [] as $dia => $c) {
                $d = (object) [
                    'uid'        => $u->id,
                    'dia'        => $dia,
                    'facturas'   => $c['facturas']   ?? 0,
                    'efectivo'   => $c['efectivo']   ?? 0,
                    'consignado' => $c['consignado'] ?? 0,
                    'gastos'     => $c['gastos']     ?? 0,
                    'cuadre'     => $cuadrados[$u->id . '|' . $dia] ?? null,
                ];
                if ($d->efectivo || $d->consignado || $d->gastos || $d->cuadre) {
                    $dias->push($d);
                }
            }
        }

        // La base de caja menor es la de hoy: solo tiene sentido al mirar un día.
        // Lo pendiente por pasar de cada persona al terminar el rango: es lo
        // que responde «¿quién tiene la plata?».
        $corte = min($hasta, today()->toDateString());

        $resumen = $visibles->map(function ($u) use ($aliadoId, $dias, $vistaMes, $corte) {
            $suyos = $dias->where('uid', $u->id);
            $ing   = $suyos->sum('efectivo');
            $gas   = $suyos->sum('gastos');
            $base  = (! $vistaMes && $suyos->isNotEmpty()) ? CajaMenor::montoActivo($aliadoId, $u->id) : 0;

            return (object) [
                'usuario'         => $u,
                'base_caja'       => $base,
                'facturas'        => $suyos->sum('facturas'),
                'efectivo_total'  => $ing,
                'consignado'      => $suyos->sum('consignado'),
                'gastos_efectivo' => $gas,
                'saldo_esperado'  => $base + $ing - $gas,
                'cuadre'          => $suyos->first()?->cuadre,
                'dias_movimiento' => $suyos->count(),
                'dias_cuadrados'  => $suyos->filter(fn($d) => $d->cuadre)->count(),
                'pendiente'       => $this->saldoAlCierre((int) $aliadoId, (int) $u->id, $corte),
            ];
        })
        // Solo quien movió plata o tiene algo pendiente por pasar; sin esto la
        // tabla es una lista de ceros.
        ->filter(fn($r) => $r->dias_movimiento > 0 || ($r->pendiente ?? 0) != 0)
        ->sortByDesc('efectivo_total')
        ->values();

        // Vista mes: el mismo consolidado, día por día. El card tiene su propio
        // filtro de usuario (`usuario_dia`) para no reducir la tabla de arriba.
        $usuarioDia  = $resumen->contains(fn($r) => $r->usuario->id === (int) $request->input('usuario_dia'))
            ? (int) $request->input('usuario_dia') : null;
        $resumenDias = $dias->when($usuarioDia, fn($ds) => $ds->where('uid', $usuarioDia))
            ->groupBy('dia')
            ->map(fn($ds, $dia) => (object) [
                'dia'             => $dia,
                'facturas'        => $ds->sum('facturas'),
                'efectivo_total'  => $ds->sum('efectivo'),
                'consignado'      => $ds->sum('consignado'),
                'gastos_efectivo' => $ds->sum('gastos'),
                'usuarios'        => $ds->count(),
                'cuadrados'       => $ds->filter(fn($d) => $d->cuadre)->count(),
            ])
            ->sortBy('dia')
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
            'resumen', 'resumenDias', 'usuarioDia', 'usuarios', 'fecha', 'mes', 'vistaMes', 'saldosBanco'
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
     * Personas que pueden tener efectivo del aliado: las suyas y las de BryNex
     * asignadas a él por `aliado_user`, más quien está logueado.
     */
    private function usuariosDeCaja(int $aliadoId)
    {
        $asignados = DB::table('aliado_user')->where('aliado_id', $aliadoId)->where('activo', true)->pluck('user_id');

        return User::where('activo', true)
            ->where(fn ($q) => $q->where('aliado_id', $aliadoId)->orWhereIn('id', $asignados)->orWhere('id', Auth::id()))
            ->orderBy('nombre')
            ->get(['id', 'nombre']);
    }

    // ── Entregas, retiros del dueño y saldo inicial ──────────────────
    /**
     * Registra efectivo que cambia de manos sin ser cobro ni gasto.
     *
     * - entrega: la registra quien entrega o quien recibe (o un admin).
     * - retiro_dueno: solo admin/superadmin; sale de la caja de una persona.
     * - saldo_inicial: solo superadmin; el efectivo contado con que arranca una
     *   persona ese día. Desde ahí se arrastra su saldo.
     */
    public function registrarMovimientoCaja(Request $request)
    {
        $aliadoId = (int) session('aliado_id_activo');
        $yo       = (int) Auth::id();
        $esAdmin  = Auth::user()->hasRole(['admin', 'superadmin']);
        $esSuper  = Auth::user()->hasRole('superadmin');

        $validated = $request->validate([
            'tipo'               => 'required|in:' . implode(',', array_keys(CajaMovimiento::TIPOS)),
            'fecha'              => 'required|date|before_or_equal:today',
            'valor'              => 'required|integer|min:1',
            'usuario_origen_id'  => 'nullable|integer',
            'usuario_destino_id' => 'nullable|integer',
            'observacion'        => 'nullable|string|max:500',
        ]);

        $tipo    = $validated['tipo'];
        $origen  = isset($validated['usuario_origen_id']) ? (int) $validated['usuario_origen_id'] : null;
        $destino = isset($validated['usuario_destino_id']) ? (int) $validated['usuario_destino_id'] : null;
        $validos = $this->usuariosDeCaja($aliadoId)->pluck('id')->map(fn ($id) => (int) $id);

        if ($tipo === CajaMovimiento::TIPO_ENTREGA) {
            $origen ??= $yo;
            if (! $destino || $destino === $origen) {
                return back()->with('error', 'Elige a quién se le entregó la plata.');
            }
            if (! $esAdmin && $origen !== $yo && $destino !== $yo) {
                return back()->with('error', 'Solo puedes registrar entregas que hiciste o que recibiste.');
            }
        } elseif ($tipo === CajaMovimiento::TIPO_RETIRO_DUENO) {
            if (! $esAdmin) {
                return back()->with('error', 'Solo un administrador registra retiros del dueño.');
            }
            $origen ??= $yo;
            $destino = null;
        } else {
            if (! $esSuper) {
                return back()->with('error', 'Solo el superadmin pone el saldo inicial de una caja.');
            }
            if (! $destino) {
                return back()->with('error', 'Elige de quién es el saldo inicial.');
            }
            $origen = null;
        }

        foreach (array_filter([$origen, $destino]) as $uid) {
            if (! $validos->contains($uid)) {
                return back()->with('error', 'Esa persona no maneja caja en este aliado.');
            }
            if ($this->cuadreDelDia($aliadoId, $uid, $validated['fecha'])) {
                return back()->with('error', 'Ese día ya fue cuadrado para una de las personas; reábrelo primero.');
            }
        }

        CajaMovimiento::create([
            'aliado_id'          => $aliadoId,
            'fecha'              => $validated['fecha'],
            'tipo'               => $tipo,
            'usuario_origen_id'  => $origen,
            'usuario_destino_id' => $destino,
            'valor'              => (int) $validated['valor'],
            'observacion'        => $validated['observacion'] ?? null,
            'registrado_por'     => $yo,
        ]);

        return back()->with('success', CajaMovimiento::TIPOS[$tipo] . ' registrado.');
    }

    public function eliminarMovimientoCaja(int $movId)
    {
        $aliadoId = (int) session('aliado_id_activo');
        $mov = CajaMovimiento::where('aliado_id', $aliadoId)->findOrFail($movId);

        $esAdmin = Auth::user()->hasRole(['admin', 'superadmin']);
        if ($mov->tipo === CajaMovimiento::TIPO_SALDO_INICIAL && ! Auth::user()->hasRole('superadmin')) {
            return back()->with('error', 'Solo el superadmin quita un saldo inicial.');
        }
        if (! $esAdmin && (int) $mov->registrado_por !== (int) Auth::id()) {
            return back()->with('error', 'Solo quien lo registró o un administrador lo puede quitar.');
        }
        foreach (array_filter([$mov->usuario_origen_id, $mov->usuario_destino_id]) as $uid) {
            if ($this->cuadreDelDia($aliadoId, (int) $uid, $mov->fecha->toDateString())) {
                return back()->with('error', 'Ese día ya fue cuadrado; reábrelo primero.');
            }
        }

        $mov->delete();

        return back()->with('success', $mov->tipoLabel() . ' quitado.');
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

    /** Mes 'AAAA-MM' pedido; si no viene o no sirve, el de la fecha. */
    private function mesValido(?string $mes, string $fecha): string
    {
        return ($mes && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes)) ? $mes : substr($fecha, 0, 7);
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
     * `saldo_esperado` = base de caja menor + lo que la persona traía de días
     * anteriores + lo recibido en efectivo del día + lo que otros le entregaron
     * − lo que salió del cajón (gastos, traslados al banco, incapacidades)
     * − lo que entregó a otros − los retiros del dueño.
     *
     * Lo que traía solo se arrastra desde un saldo inicial contado (ver
     * saldoAntesDe): sin él la persona arranca cada día de cero, como antes.
     */
    private function resumenDia(int $aliadoId, ?int $usuarioId, string $fecha, $facturasDia): array
    {
        // Lo que entró y salió del efectivo sale de la regla única
        // (FlujoEfectivo), la misma que usan el consolidado y el informe.
        $flujo = FlujoEfectivo::totales($aliadoId, $usuarioId, $fecha, $fecha);
        $ingresosEfectivo  = $flujo['facturas'];
        $cobrosCartera     = $flujo['abonos'];
        $anticiposEfectivo = $flujo['anticipos'];

        // Informativo: lo que se prestó hoy no es ingreso, es cartera. Junto al
        // préstamo formal va la factura que se marcó pagada sin recibir un peso
        // (ver facturasSinPago): también es plata por cobrar, y así el card
        // cuadra con la columna 'prestado' de los canales.
        $sinPago = $this->facturasSinPago($facturasDia);
        $facturasSinPago = $facturasDia->filter(fn ($f) => isset($sinPago[$f->id]));
        $totalPrestado = (int) $facturasDia->where('es_prestamo', true)->sum('total')
                       + (int) $facturasSinPago->sum('total');

        // Todo lo que salió del cajón ese día. Para arquear la caja da igual en
        // qué se fue, pero solo una parte es gasto de la empresa: el traslado
        // al banco sigue siendo plata de la empresa, y el pago de una
        // incapacidad es plata del afiliado que entró por la razón social.
        $salidasTraslado      = $flujo['traslados'];
        $salidasIncapacidades = $flujo['incapacidades'];
        $gastosEfectivo       = $flujo['salidas'];

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
        $recibido  = $flujo['recibido'];
        $traia     = $this->saldoAntesDe($aliadoId, $usuarioId, $fecha);
        $pendiente = (int) $traia + $flujo['neto'];

        return [
            'total_facturado'    => (int) $conValor->sum('total'),
            'num_facturas'       => $numFacturas,
            'base_caja'          => $baseCaja,
            'ingresos_efectivo'  => $ingresosEfectivo,
            'cobros_cartera'     => $cobrosCartera,
            'anticipos_efectivo' => $anticiposEfectivo,
            'opi_efectivo'       => $flujo['opi'],
            'total_prestado'     => $totalPrestado,
            'periodos'           => $periodos,
            'num_prestamos'      => $facturasDia->where('es_prestamo', true)->count(),
            'num_sin_pago'       => $facturasSinPago->count(),
            'total_sin_pago'     => (int) $facturasSinPago->sum('total'),
            'gastos_efectivo'    => $gastosEfectivo,
            'salidas_gastos'        => $flujo['gastos'],
            'salidas_traslado'      => $salidasTraslado,
            'salidas_incapacidades' => $salidasIncapacidades,
            'consignado'         => $consignado,
            'recibido_efectivo'  => $recibido,
            'arrastra'           => $traia !== null,
            'traia'              => (int) $traia,
            'entregas_recibidas' => $flujo['recibidas'],
            'entregas_dadas'     => $flujo['dadas'],
            'retiros_dueno'      => $flujo['retiros'],
            'pendiente_por_pasar' => $pendiente,
            'saldo_esperado'     => $baseCaja + $pendiente,
        ];
    }

    /**
     * Lo que una persona traía al empezar el día, sin la base de caja menor.
     *
     * Parte del último saldo inicial contado con fecha <= $fecha (que es el
     * saldo al empezar ese día) y le suma lo que se movió desde entonces hasta
     * el día anterior. null si la persona nunca tuvo saldo inicial: entonces
     * no arrastra nada. Con la caja de todos suma el de cada persona.
     */
    private function saldoAntesDe(int $aliadoId, ?int $usuarioId, string $fecha): ?int
    {
        if ($usuarioId === null) {
            $conSaldo = CajaMovimiento::where('aliado_id', $aliadoId)
                ->where('tipo', CajaMovimiento::TIPO_SALDO_INICIAL)
                ->whereDate('fecha', '<=', $fecha)
                ->distinct()
                ->pluck('usuario_destino_id');
            if ($conSaldo->isEmpty()) {
                return null;
            }

            return (int) $conSaldo->sum(fn ($uid) => (int) $this->saldoAntesDe($aliadoId, (int) $uid, $fecha));
        }

        $inicial = CajaMovimiento::saldoInicialVigente($aliadoId, $usuarioId, $fecha);
        if (! $inicial) {
            return null;
        }

        $desde = $inicial->fecha->toDateString();
        if ($desde === $fecha) {
            return (int) $inicial->valor;
        }

        $ayer = \Carbon\Carbon::parse($fecha)->subDay()->toDateString();

        return (int) $inicial->valor + FlujoEfectivo::totales($aliadoId, $usuarioId, $desde, $ayer)['neto'];
    }

    /** Lo pendiente por pasar al terminar el día $hasta (null si no arrastra). */
    private function saldoAlCierre(int $aliadoId, int $usuarioId, string $hasta): ?int
    {
        $traia = $this->saldoAntesDe($aliadoId, $usuarioId, $hasta);

        return $traia === null ? null : $traia + FlujoEfectivo::totales($aliadoId, $usuarioId, $hasta, $hasta)['neto'];
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
        // Los mismos estados que el informe: una pre-factura tiene fecha de
        // pago pero no movió plata.
        return Factura::where('aliado_id', $aliadoId)
            ->when($usuarioId, fn ($q) => $q->where('usuario_id', $usuarioId))
            ->whereDate('fecha_pago', $fecha)
            ->whereIn('estado', [Factura::ESTADO_PAGADA, Factura::ESTADO_ABONO, Factura::ESTADO_PRESTAMO])
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
                if (! empty($f['aparte'])) continue;   // se muestra, pero no es del canal
                foreach ($t as $k => $_) $t[$k] += $f[$k];
            }
            return $t;
        };

        // ── Canal 1: Administración ──────────────────────────────────────
        // La comisión del asesor se cobra con la factura pero es del asesor
        // (se le paga cuando la pide): se muestra aparte y no suma al canal,
        // igual que en el informe financiero, para que los dos den lo mismo.
        $c1 = [
            $fila('Administración',   'admon',        '#3b82f6'),
            ['aparte' => true] + $fila('Comisión asesor (del asesor)', 'admin_asesor', '#f59e0b'),
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
                    // Lo que queda a la empresa: el bruto menos asesor, retiro y
                    // encargado. Es la cifra que el informe pone en el canal 2.
                    'empresa' => array_map(
                        fn ($col) => $bruto[$col] - $c2[1][$col] - $c2[2][$col] - $c2[4][$col],
                        ['efectivo' => 'efectivo', 'consignado' => 'consignado', 'prestado' => 'prestado']
                    ),
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

        if ($datos['anticipos']->isNotEmpty()) {
            $hoja = $spreadsheet->createSheet();
            $hoja->setTitle('Anticipos');
            $hoja->fromArray(['No.', 'Cédula', 'Nombres', 'Forma pago', 'Valor', 'Estado',
                              'Factura', 'Empresa', 'Banco', 'Recibió'], null, 'A1');
            $hoja->getStyle('A1:J1')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '7c3aed']],
            ]);
            foreach ($datos['anticipos']->values() as $i => $a) {
                $fila = $i + 2;
                $hoja->fromArray([
                    $i + 1,
                    $a->cedula,
                    $a->nombre_cliente,
                    Anticipo::FORMAS_PAGO[$a->forma_pago] ?? $a->forma_pago,
                    (int) $a->valor,
                    $a->etiqueta_estado,
                    $a->factura?->numero_factura ?? '—',
                    $a->empresa?->empresa ?? 'INDIVIDUALES',
                    $a->bancoCuenta ? ($a->bancoCuenta->nombre ?: $a->bancoCuenta->banco) : '—',
                    $a->usuario?->nombre ?? '—',
                ], null, "A{$fila}", true);
                $hoja->getStyle("E{$fila}")->getNumberFormat()->setFormatCode('$#,##0');
            }
            foreach (range('A', 'J') as $col) {
                $hoja->getColumnDimension($col)->setAutoSize(true);
            }
            $spreadsheet->setActiveSheetIndex(0);
        }

        if ($datos['abonos']->isNotEmpty()) {
            $hoja = $spreadsheet->createSheet();
            $hoja->setTitle('Abonos a préstamos');
            $hoja->fromArray(['No.', 'Cédula', 'Nombres', 'Factura préstamo', 'Fecha préstamo',
                              'Forma pago', 'Efectivo', 'Consignado', 'Banco', 'Recibió', 'Observación'], null, 'A1');
            $hoja->getStyle('A1:K1')->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'b45309']],
            ]);
            foreach ($datos['abonos']->values() as $i => $ab) {
                $fila = $i + 2;
                $hoja->fromArray([
                    $i + 1,
                    $ab->factura?->cedula,
                    $ab->nombre_cliente,
                    $ab->factura?->numero_factura,
                    $ab->factura?->fecha_pago?->format('Y-m-d'),
                    self::FORMAS_PAGO_DIA[$ab->forma_pago] ?? $ab->forma_pago,
                    (int) $ab->valor_efectivo,
                    (int) $ab->valor_consignado,
                    $ab->bancoCuenta ? ($ab->bancoCuenta->nombre ?: $ab->bancoCuenta->banco) : '—',
                    $ab->usuario?->nombre ?? '—',
                    $ab->observacion,
                ], null, "A{$fila}", true);
                $hoja->getStyle("G{$fila}:H{$fila}")->getNumberFormat()->setFormatCode('$#,##0');
            }
            foreach (range('A', 'K') as $col) {
                $hoja->getColumnDimension($col)->setAutoSize(true);
            }
            $spreadsheet->setActiveSheetIndex(0);
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
            // Los mismos estados que el cuadre y el informe (FlujoEfectivo).
            ->whereIn('estado', [Factura::ESTADO_PAGADA, Factura::ESTADO_ABONO, Factura::ESTADO_PRESTAMO])
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

        $anticipos = $this->anticiposDelDiaListado($aliadoId, $fecha, $fUsuario, $fForma, $fBanco, $fEmpresa,
            // Tipo, razón social y modalidad son de la factura: con esos filtros no aplican.
            (bool) ($fTipo || $fRazon || $fModal !== null));

        // Abonos a préstamos recibidos ese día: el préstamo se facturó otro día.
        $abonos = $this->abonosPrestamoDelDia($aliadoId, $fecha, $fUsuario, $fForma, $fBanco, $fEmpresa,
            (bool) (($fTipo && $fTipo !== 'prestamo') || $fRazon || $fModal !== null));

        // ── Nombres de cliente en un solo query (evita N+1 por cédula) ──
        $cedulas = $facturas->pluck('cedula')
            ->merge($anticipos->pluck('cedula'))
            ->merge($abonos->pluck('factura.cedula'))
            ->filter()->unique()->values()->all();
        $nombres = ! $cedulas
            ? collect()
            : \App\Models\Cliente::where('aliado_id', $aliadoId)
                ->whereIn('cedula', $cedulas)
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

        foreach ($anticipos as $a) {
            $a->nombre_cliente = $nombres->get($a->cedula)?->nombre_corto ?? '—';
        }
        foreach ($abonos as $ab) {
            $ab->nombre_cliente = $nombres->get($ab->factura?->cedula)?->nombre_corto ?? '—';
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
            'anticipos'            => (int) $anticipos->sum('valor'),
            'anticipos_efectivo'   => (int) $anticipos->filter(fn($a) => $a->es_efectivo)->sum('valor'),
            'anticipos_consignado' => (int) $anticipos->reject(fn($a) => $a->es_efectivo)->sum('valor'),
            'abonos'               => (int) $abonos->sum('valor'),
            'abonos_efectivo'      => (int) $abonos->sum('valor_efectivo'),
            'abonos_consignado'    => (int) $abonos->sum('valor_consignado'),
        ];

        // Cuánto recibió cada quien: desglose por el usuario que facturó.
        // Solo quien movió plata — sin esto aparecen los usuarios cuyas
        // facturas del día son todas de valor 0 (retiros, por ejemplo).
        // Los anticipos van aparte: una factura pagada con anticipo llega en $0
        // y la plata se recibió como anticipo.
        $nombreUsuario = fn($x) => $x->usuario?->nombre ?? 'Sin usuario';
        $facturasPorU  = $facturas->groupBy($nombreUsuario);
        $anticiposPorU = $anticipos->groupBy($nombreUsuario);
        $abonosPorU    = $abonos->groupBy($nombreUsuario);
        $porUsuario = $facturasPorU->keys()->merge($anticiposPorU->keys())->merge($abonosPorU->keys())->unique()
            ->mapWithKeys(function ($nombre) use ($facturasPorU, $anticiposPorU, $abonosPorU) {
                $g  = $facturasPorU->get($nombre, collect());
                $a  = $anticiposPorU->get($nombre, collect());
                $ab = $abonosPorU->get($nombre, collect());
                return [$nombre => [
                    'cantidad'             => $g->count(),
                    'total'                => (int) $g->sum('total'),
                    'efectivo'             => (int) $g->sum('valor_efectivo'),
                    'consignado'           => (int) $g->sum('valor_consignado'),
                    'anticipos_efectivo'   => (int) $a->filter(fn($x) => $x->es_efectivo)->sum('valor'),
                    'anticipos_consignado' => (int) $a->reject(fn($x) => $x->es_efectivo)->sum('valor'),
                    'abonos_efectivo'      => (int) $ab->sum('valor_efectivo'),
                    'abonos_consignado'    => (int) $ab->sum('valor_consignado'),
                ]];
            })
            ->filter(fn($t) => $t['efectivo'] > 0 || $t['consignado'] > 0
                || $t['anticipos_efectivo'] > 0 || $t['anticipos_consignado'] > 0
                || $t['abonos_efectivo'] > 0 || $t['abonos_consignado'] > 0)
            ->sortByDesc(fn($t) => $t['efectivo'] + $t['consignado'] + $t['anticipos_efectivo']
                + $t['anticipos_consignado'] + $t['abonos_efectivo'] + $t['abonos_consignado']);

        return [
            'fecha'      => $fecha,
            'facturas'   => $facturas,
            'anticipos'  => $anticipos,
            'abonos'     => $abonos,
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
     * Anticipos recibidos ese día, con el mismo criterio del consolidado: sin los
     * devueltos ni los maestros ya distribuidos (sus hijos son los que cuentan).
     * Efectivo y Nequi suman como efectivo, igual que en la caja del día.
     */
    private function anticiposDelDiaListado(int $aliadoId, string $fecha, $fUsuario, $fForma, $fBanco,
                                            $fEmpresa, bool $noAplica)
    {
        // Las formas de pago de la factura no son las del anticipo: se traducen.
        $formas = match ($fForma) {
            null, ''       => null,
            'efectivo'     => ['efectivo', 'nequi'],
            'consignacion' => ['transferencia', 'consignacion'],
            default        => [],   // mixta / préstamo: no existen en anticipos
        };

        if ($noAplica || $formas === []) {
            return collect();
        }

        return Anticipo::where('aliado_id', $aliadoId)
            ->whereDate('fecha_pago', $fecha)
            ->whereNotIn('estado', [Anticipo::ESTADO_DEVUELTO, Anticipo::ESTADO_DISTRIBUIDO])
            ->when($fUsuario, fn($q) => $q->where('usuario_id', $fUsuario))
            ->when($formas, fn($q) => $q->whereIn('forma_pago', $formas))
            ->when($fBanco, fn($q) => $q->where('banco_cuenta_id', $fBanco))
            ->when($fEmpresa === 'individuales', fn($q) => $q->whereNull('empresa_id'))
            ->when($fEmpresa && $fEmpresa !== 'individuales', fn($q) => $q->where('empresa_id', $fEmpresa))
            ->with(['empresa:id,empresa', 'usuario:id,nombre', 'bancoCuenta', 'factura:id,numero_factura'])
            ->orderBy('id')
            ->get()
            // Closure con llaves: si devolviera false, each() cortaría el recorrido.
            ->each(function ($a) {
                $a->es_efectivo = in_array($a->forma_pago, ['efectivo', 'nequi'], true);
            });
    }

    /**
     * Abonos a préstamos recibidos ese día (la "cartera" del consolidado). Se
     * filtran por la fecha y el usuario del abono, no los de la factura del
     * préstamo, que casi siempre es de otro día.
     */
    private function abonosPrestamoDelDia(int $aliadoId, string $fecha, $fUsuario, $fForma, $fBanco,
                                          $fEmpresa, bool $noAplica)
    {
        $formas = match ($fForma) {
            null, '', 'prestamo' => null,
            'efectivo'           => ['efectivo'],
            'consignacion'       => ['consignacion'],
            default              => [],   // mixta: no existe en abonos
        };

        if ($noAplica || $formas === []) {
            return collect();
        }

        // Todos los abonos del día, no solo los de préstamos (un abono a una
        // factura en estado `abono` también es plata), menos los que nacen de
        // cobrar cartera junto con una factura nueva: esa plata ya está en el
        // pago de la factura. La misma regla de FlujoEfectivo.
        return \App\Models\Abono::whereDate('fecha', $fecha)
            ->where(fn($q) => $q->whereNull('observacion')
                ->orWhere('observacion', 'not like', \App\Services\Caja\FlujoEfectivo::OBS_CARTERA_CRUZADA))
            ->whereHas('factura', fn($q) => $q->where('aliado_id', $aliadoId)
                ->when($fEmpresa === 'individuales', fn($q2) => $q2->whereNull('empresa_id'))
                ->when($fEmpresa && $fEmpresa !== 'individuales', fn($q2) => $q2->where('empresa_id', $fEmpresa)))
            ->when($fUsuario, fn($q) => $q->where('usuario_id', $fUsuario))
            ->when($formas, fn($q) => $q->whereIn('forma_pago', $formas))
            ->when($fBanco, fn($q) => $q->where('banco_cuenta_id', $fBanco))
            ->with(['factura:id,numero_factura,cedula,fecha_pago,empresa_id', 'factura.empresa:id,empresa',
                    'usuario:id,nombre', 'bancoCuenta'])
            ->orderBy('id')
            ->get();
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
            'usuariosDisp' => $base->pluck('usuario')->filter()
                                ->merge(User::whereIn('id', Anticipo::where('aliado_id', $aliadoId)
                                    ->whereDate('fecha_pago', $fecha)->whereNotNull('usuario_id')
                                    ->distinct()->pluck('usuario_id'))->get(['id', 'nombre']))
                                ->merge(User::whereIn('id', \App\Models\Abono::whereDate('fecha', $fecha)
                                    ->whereHas('factura', fn($q) => $q->where('aliado_id', $aliadoId))
                                    ->whereNotNull('usuario_id')
                                    ->distinct()->pluck('usuario_id'))->get(['id', 'nombre']))
                                ->unique('id')->sortBy('nombre')->values(),
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
