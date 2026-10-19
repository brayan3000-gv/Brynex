<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BancoCuenta;
use App\Models\BancoMovimiento;
use App\Models\Bitacora;
use App\Models\Consignacion;
use App\Models\Gasto;
use App\Services\Banco\ConciliadorConsignacionesService;
use App\Services\Banco\ConciliadorGastosService;
use App\Services\Banco\LectorExtractoBancolombia;
use App\Services\Banco\SincronizadorMovimientosService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Bandeja del extracto: qué dice el banco contra qué dice el libro.
 *
 * Tres listas, y cada una es una pregunta distinta:
 *
 *   - **Entradas sin identificar**: plata que llegó al banco y nadie registró.
 *     Hoy ese dinero simplemente no existe en BryNex hasta que alguien lo
 *     digita, y por eso se pierde.
 *   - **Sin respaldo en el banco**: consignaciones registradas que el extracto
 *     no reporta. Es el candidato a `no aparece`, pero lo decide una persona:
 *     también puede ser que el rango consultado no las alcance.
 *   - **Cruzadas**: lo que ya cuadró, con la regla que lo cruzó, para poder
 *     revisar los cruces flojos y deshacer los que estén mal.
 *
 * Todo lo que escribe pasa por el mismo criterio que el cruce automático: si
 * no hay certeza, no se marca. Marcar la factura de otro cliente como pagada
 * es peor que dejarla pendiente.
 */
class ExtractoBancoController extends Controller
{
    private function aliadoId(): int
    {
        return (int) session('aliado_id_activo');
    }

    public function index(Request $request)
    {
        $aliadoId = $this->aliadoId();

        $cuentas = BancoCuenta::where('aliado_id', $aliadoId)
            ->where('activo', true)
            ->orderBy('banco')
            ->get();

        [$desde, $hasta] = $this->rango($request);

        $cuentaId = (int) $request->input('cuenta', 0);
        if ($cuentaId && ! $cuentas->contains('id', $cuentaId)) {
            abort(403, 'Esa cuenta no es de este aliado.');
        }
        $cuentaIds = $cuentaId ? [$cuentaId] : $cuentas->pluck('id')->all();

        $sinIdentificar = $this->sinIdentificar($aliadoId, $cuentaIds, $desde, $hasta);
        $sinRespaldo = $this->sinRespaldo($aliadoId, $cuentaIds, $desde, $hasta);
        $cruzados = $this->cruzados($aliadoId, $cuentaIds, $desde, $hasta);
        $salidasSueltas = $this->salidasSueltas($aliadoId, $cuentaIds, $desde, $hasta);
        $cobrosBanco = $this->cobrosBanco($aliadoId, $cuentaIds, $desde, $hasta);
        $gastosSinRespaldo = $this->gastosSinRespaldo($aliadoId, $cuentaIds, $desde, $hasta);

        $resumen = [
            'sin_identificar' => $sinIdentificar->count(),
            'valor_sin_identificar' => (int) $sinIdentificar->sum('valor'),
            'sin_respaldo' => $sinRespaldo->count(),
            'valor_sin_respaldo' => (int) $sinRespaldo->sum('valor'),
            'cruzados' => $cruzados->count(),
            'salidas_sueltas' => $salidasSueltas->count(),
            'valor_salidas_sueltas' => (int) $salidasSueltas->sum('valor'),
            'cobros_banco' => $cobrosBanco->count(),
            'valor_cobros_banco' => (int) $cobrosBanco->sum('valor'),
            'gastos_sin_respaldo' => $gastosSinRespaldo->count(),
            'valor_gastos_sin_respaldo' => (int) $gastosSinRespaldo->sum('valor'),
        ];

        $tiposGasto = Gasto::TIPOS;

        return view('admin.informes.extracto_banco', compact(
            'cuentas', 'cuentaId', 'desde', 'hasta',
            'sinIdentificar', 'sinRespaldo', 'cruzados', 'salidasSueltas', 'cobrosBanco', 'gastosSinRespaldo', 'tiposGasto', 'resumen'
        ));
    }

    // ── Listas ───────────────────────────────────────────────────────

    /** Entradas del banco que no cruzaron con nada del libro. */
    private function sinIdentificar(int $aliadoId, array $cuentaIds, string $desde, string $hasta)
    {
        if ($cuentaIds === []) {
            return collect();
        }

        return DB::table('banco_movimientos as bm')
            ->leftJoin('banco_cuentas as bc', 'bc.id', '=', 'bm.banco_cuenta_id')
            ->where('bm.aliado_id', $aliadoId)
            ->whereIn('bm.banco_cuenta_id', $cuentaIds)
            ->where('bm.tipo', BancoMovimiento::TIPO_CREDITO)
            ->where('bm.estado_conciliacion', BancoMovimiento::CONCILIACION_PENDIENTE)
            ->whereBetween('bm.fecha', [$desde, $hasta])
            ->orderByDesc('bm.fecha')
            ->orderByDesc('bm.id')
            ->limit(300)
            ->select(
                'bm.id', 'bm.fecha', 'bm.valor', 'bm.descripcion', 'bm.referencia',
                'bm.canal', 'bm.contraparte_nombre', 'bm.contraparte_documento',
                'bm.banco_cuenta_id', 'bc.banco', 'bc.numero_cuenta'
            )
            ->get();
    }

    /** Consignaciones del libro que el banco no reporta. */
    private function sinRespaldo(int $aliadoId, array $cuentaIds, string $desde, string $hasta)
    {
        if ($cuentaIds === []) {
            return collect();
        }

        return DB::table('consignaciones as cs')
            ->leftJoin('banco_movimiento_consignacion as p', 'p.consignacion_id', '=', 'cs.id')
            ->leftJoin('facturas as f', 'f.id', '=', 'cs.factura_id')
            ->leftJoin('clientes as cl', function ($j) use ($aliadoId) {
                $j->on('cl.cedula', '=', 'f.cedula')->where('cl.aliado_id', $aliadoId);
            })
            ->leftJoin('empresas as em', 'em.id', '=', 'f.empresa_id')
            ->leftJoin('banco_cuentas as bc', 'bc.id', '=', 'cs.banco_cuenta_id')
            ->where('cs.aliado_id', $aliadoId)
            ->whereIn('cs.banco_cuenta_id', $cuentaIds)
            ->whereNull('cs.deleted_at')
            ->whereNull('p.id')
            ->where('cs.no_aparece', 0)
            ->whereBetween('cs.fecha', [$desde, $hasta])
            ->orderByDesc('cs.fecha')
            ->orderByDesc('cs.id')
            ->limit(300)
            ->selectRaw("
                cs.id, cs.fecha, cs.valor, cs.referencia, cs.tipo, cs.confirmado,
                cs.banco_cuenta_id, bc.banco, f.numero_factura,
                CASE
                    WHEN f.empresa_id IS NOT NULL AND f.empresa_id > 0
                        THEN UPPER(ISNULL(em.empresa, '—'))
                    ELSE LTRIM(RTRIM(
                        ISNULL(cl.primer_nombre,'') + ' ' + ISNULL(cl.primer_apellido,'')
                    ))
                END AS titular
            ")
            ->get();
    }

    /**
     * Salidas del banco que ningún gasto explica.
     *
     * El otro lado del cuadre: plata que salió de la cuenta y no está
     * registrada como gasto. Suelen ser pagos al operador de planilla o
     * traslados a otra cuenta propia que nadie anotó.
     */
    private function salidasSueltas(int $aliadoId, array $cuentaIds, string $desde, string $hasta)
    {
        if ($cuentaIds === []) {
            return collect();
        }

        return DB::table('banco_movimientos as bm')
            ->leftJoin('banco_movimiento_gasto as p', 'p.banco_movimiento_id', '=', 'bm.id')
            ->leftJoin('banco_cuentas as bc', 'bc.id', '=', 'bm.banco_cuenta_id')
            ->where('bm.aliado_id', $aliadoId)
            ->whereIn('bm.banco_cuenta_id', $cuentaIds)
            ->where('bm.tipo', BancoMovimiento::TIPO_DEBITO)
            ->where('bm.estado_conciliacion', BancoMovimiento::CONCILIACION_PENDIENTE)
            ->whereNull('p.id')
            ->whereBetween('bm.fecha', [$desde, $hasta])
            ->orderByDesc('bm.valor')
            ->limit(300)
            ->select('bm.id', 'bm.fecha', 'bm.valor', 'bm.descripcion', 'bm.referencia', 'bm.canal', 'bc.banco')
            ->get();
    }

    /**
     * Gastos registrados que el extracto no reporta.
     *
     * El espejo de las consignaciones sin respaldo, del lado de las salidas: un
     * pago de planilla anotado en BryNex que el banco no muestra. O salió por
     * otra cuenta, o se registró con otro valor, o no salió.
     */
    private function gastosSinRespaldo(int $aliadoId, array $cuentaIds, string $desde, string $hasta)
    {
        if ($cuentaIds === []) {
            return collect();
        }

        return DB::table('gastos as g')
            ->leftJoin('banco_movimiento_gasto as p', 'p.gasto_id', '=', 'g.id')
            ->leftJoin('banco_cuentas as bc', 'bc.id', '=', 'g.banco_origen_id')
            ->where('g.aliado_id', $aliadoId)
            ->whereIn('g.banco_origen_id', $cuentaIds)
            ->whereNull('p.id')
            ->whereBetween('g.fecha', [$desde, $hasta])
            ->orderByDesc('g.valor')
            ->limit(300)
            ->select('g.id', 'g.fecha', 'g.valor', 'g.tipo', 'g.descripcion', 'g.pagado_a', 'g.numero_planilla', 'bc.banco')
            ->get();
    }

    /**
     * Lo que cobró o abonó el banco: 4x1000, cuota de manejo, intereses.
     *
     * El cruce los aparta para que no ensucien las diferencias, pero siguen
     * siendo plata que se movió de verdad. Mientras no queden registrados, el
     * saldo del libro nunca va a coincidir con el del banco, y por eso se
     * muestran con la opción de registrarlos.
     */
    private function cobrosBanco(int $aliadoId, array $cuentaIds, string $desde, string $hasta)
    {
        if ($cuentaIds === []) {
            return collect();
        }

        return DB::table('banco_movimientos as bm')
            ->leftJoin('banco_cuentas as bc', 'bc.id', '=', 'bm.banco_cuenta_id')
            ->where('bm.aliado_id', $aliadoId)
            ->whereIn('bm.banco_cuenta_id', $cuentaIds)
            ->where('bm.estado_conciliacion', BancoMovimiento::CONCILIACION_IGNORADO)
            ->whereBetween('bm.fecha', [$desde, $hasta])
            ->orderByDesc('bm.valor')
            ->limit(300)
            ->select('bm.id', 'bm.fecha', 'bm.valor', 'bm.tipo', 'bm.descripcion', 'bm.clasificacion', 'bc.banco')
            ->get();
    }

    /** Cruces ya hechos, con la regla que los emparejó. */
    private function cruzados(int $aliadoId, array $cuentaIds, string $desde, string $hasta)
    {
        if ($cuentaIds === []) {
            return collect();
        }

        return DB::table('banco_movimiento_consignacion as p')
            ->join('banco_movimientos as bm', 'bm.id', '=', 'p.banco_movimiento_id')
            ->join('consignaciones as cs', 'cs.id', '=', 'p.consignacion_id')
            ->leftJoin('facturas as f', 'f.id', '=', 'cs.factura_id')
            ->where('p.aliado_id', $aliadoId)
            ->whereIn('bm.banco_cuenta_id', $cuentaIds)
            ->whereBetween('bm.fecha', [$desde, $hasta])
            ->orderByDesc('bm.fecha')
            ->orderByDesc('p.id')
            ->limit(300)
            ->select(
                'p.id as pivote_id', 'p.regla', 'p.dias_diferencia', 'p.valor_aplicado',
                'p.usuario_id',
                'bm.id as movimiento_id', 'bm.fecha', 'bm.descripcion', 'bm.valor',
                'cs.id as consignacion_id', 'cs.referencia', 'f.numero_factura'
            )
            ->get();
    }

    // ── Acciones ─────────────────────────────────────────────────────

    /** Baja el extracto del banco para el rango en pantalla. */
    public function sincronizar(Request $request)
    {
        [$desde, $hasta] = $this->rango($request);
        $cuentas = $this->cuentasDelRequest($request);

        $nuevos = 0;

        try {
            $servicio = new SincronizadorMovimientosService;
            foreach ($cuentas as $cuenta) {
                $r = $servicio->sincronizar($cuenta, Carbon::parse($desde), Carbon::parse($hasta));
                $nuevos += $r['nuevos'];
            }
        } catch (Throwable $e) {
            return back()->with('error', 'No se pudo traer el extracto: '.$e->getMessage());
        }

        return back()->with('success', "Extracto actualizado: $nuevos movimientos nuevos.");
    }

    /**
     * Carga el extracto que se descarga de la Sucursal Virtual en Excel.
     *
     * Es la forma real de traer los movimientos: el banco respondió que sus
     * APIs no sirven para consultar la cuenta propia. El archivo entra, se
     * deduplica igual que si viniera de un API, y el cruce corre de una para
     * que la pantalla quede útil sin un segundo clic.
     */
    public function cargarExtracto(Request $request)
    {
        $request->validate([
            'cuenta' => 'required|integer',
            'archivo' => 'required|file|mimes:xlsx,xls|max:10240',
        ], [
            'archivo.mimes' => 'El extracto debe ser el Excel que descarga de la Sucursal Virtual (.xlsx).',
            'cuenta.required' => 'Elija a qué cuenta corresponde el extracto.',
        ]);

        $aliadoId = $this->aliadoId();

        $cuenta = BancoCuenta::where('id', $request->integer('cuenta'))
            ->where('aliado_id', $aliadoId)
            ->first();

        if (! $cuenta) {
            abort(403, 'Esa cuenta no es de este aliado.');
        }

        try {
            $lector = new LectorExtractoBancolombia;
            $extracto = $lector->leer($request->file('archivo')->getRealPath());
            $lector->verificarCuenta($extracto, $cuenta);
        } catch (Throwable $e) {
            return back()->with('error', 'No se pudo leer el extracto: '.$e->getMessage());
        }

        if ($extracto['movimientos'] === []) {
            return back()->with('error', 'El archivo no tiene movimientos. ¿Es el extracto de la Sucursal Virtual?');
        }

        $r = (new SincronizadorMovimientosService)
            ->guardar($cuenta, $extracto['movimientos'], 'extracto_xlsx');

        // Cruzar de una: sin esto el usuario carga el archivo y no ve nada.
        $cruce = (new ConciliadorConsignacionesService)->conciliar(
            $cuenta,
            Carbon::parse($r['desde']),
            Carbon::parse($r['hasta']),
            true
        );

        // Y las salidas contra los gastos: es la otra mitad del extracto.
        $cruceGastos = (new ConciliadorGastosService)->conciliar(
            $cuenta,
            Carbon::parse($r['desde']),
            Carbon::parse($r['hasta']),
            true
        );

        // Los cobros del banco quedan como un gasto por mes: si no, cada cierre
        // los mostraba como diferencia (ver CargosBancariosService).
        $cargos = (new \App\Services\Banco\CargosBancariosService)->registrar(
            $cuenta,
            Carbon::parse($r['desde']),
            Carbon::parse($r['hasta']),
            (int) auth()->id()
        );

        Bitacora::registrar(
            'cargar_extracto', 'BancoMovimiento', (int) $cuenta->id,
            "Extracto cargado ({$r['desde']} a {$r['hasta']}): {$r['nuevos']} movimientos nuevos, {$cruce['confirmadas']} consignaciones confirmadas",
            [
                'archivo' => $request->file('archivo')->getClientOriginalName(),
                'traidos' => $r['traidos'],
                'repetidos' => $r['repetidos'],
                'por_regla' => $cruce['por_regla'],
            ],
            $aliadoId
        );

        $aviso = "Extracto de {$r['desde']} a {$r['hasta']}: {$r['nuevos']} movimientos nuevos"
            .($r['repetidos'] ? " ({$r['repetidos']} ya estaban)" : '')
            .'. Entradas: '.count($cruce['cruces'])." cruzadas, {$cruce['confirmadas']} consignaciones confirmadas."
            .' Salidas: '.count($cruceGastos['cruces']).' cruzadas contra gastos.'
            .($cargos ? ' Cargos bancarios registrados: '.collect($cargos)->map(fn ($v, $m) => $m.' $'.number_format($v, 0, ',', '.'))->implode(', ').'.' : '');

        // El propio archivo dice cuánto debía sumar; si no cuadra llegó
        // recortado y más vale decirlo que dejar medio mes sin conciliar.
        if ($extracto['descuadre'] !== null) {
            return back()->with('error', $aviso
                .' OJO: los abonos leídos no cuadran con el resumen del archivo (diferencia de $'
                .number_format(abs($extracto['descuadre']), 0, ',', '.').'). Revise que el Excel esté completo.');
        }

        return back()->with('success', $aviso);
    }

    /** Corre el cruce automático sobre el rango en pantalla. */
    public function conciliar(Request $request)
    {
        [$desde, $hasta] = $this->rango($request);
        $cuentas = $this->cuentasDelRequest($request);

        $cruces = 0;
        $confirmadas = 0;
        $crucesSalidas = 0;

        try {
            $entradas = new ConciliadorConsignacionesService;
            $salidas = new ConciliadorGastosService;

            foreach ($cuentas as $cuenta) {
                $r = $entradas->conciliar($cuenta, Carbon::parse($desde), Carbon::parse($hasta), true);
                $cruces += count($r['cruces']);
                $confirmadas += $r['confirmadas'];

                $g = $salidas->conciliar($cuenta, Carbon::parse($desde), Carbon::parse($hasta), true);
                $crucesSalidas += count($g['cruces']);
            }
        } catch (Throwable $e) {
            return back()->with('error', 'No se pudo conciliar: '.$e->getMessage());
        }

        Bitacora::registrar(
            'conciliar', 'BancoMovimiento', null,
            "Cruce del extracto desde la bandeja: $cruces cruces, $confirmadas confirmadas",
            ['rango' => [$desde, $hasta]],
            $this->aliadoId()
        );

        return back()->with('success', "Entradas: $cruces cruces nuevos y $confirmadas consignaciones confirmadas. "
            ."Salidas: $crucesSalidas cruzadas contra gastos.");
    }

    /**
     * Vincula a mano un movimiento con una consignación.
     *
     * Se aplica lo que quepa: si el movimiento ya tenía parte imputada, o si la
     * consignación ya estaba parcialmente cubierta, solo entra el saldo. Así se
     * puede armar un pago partido a mano sin pasarse del valor.
     */
    public function vincular(Request $request, int $movimientoId)
    {
        $datos = $request->validate([
            'consignacion_id' => 'required|integer',
        ]);

        $aliadoId = $this->aliadoId();

        $mov = BancoMovimiento::where('id', $movimientoId)->where('aliado_id', $aliadoId)->first();
        $con = Consignacion::where('id', $datos['consignacion_id'])->where('aliado_id', $aliadoId)->first();

        if (! $mov || ! $con) {
            abort(404, 'El movimiento o la consignación no son de este aliado.');
        }

        if ((int) $mov->banco_cuenta_id !== (int) $con->banco_cuenta_id) {
            return back()->with('error', 'El movimiento y la consignación son de cuentas distintas.');
        }

        $libreMov = (float) $mov->valor - $this->aplicadoMovimiento($mov->id);
        $libreCon = (float) $con->valor - $this->aplicadoConsignacion($con->id);
        $aplicar = min($libreMov, $libreCon);

        if ($aplicar <= 0) {
            return back()->with('error', 'Ese movimiento o esa consignación ya están cubiertos.');
        }

        $dias = abs(Carbon::parse($mov->fecha)->diffInDays(Carbon::parse($con->fecha), false));

        DB::transaction(function () use ($mov, $con, $aplicar, $dias, $aliadoId) {
            DB::table('banco_movimiento_consignacion')->insert([
                'aliado_id' => $aliadoId,
                'banco_movimiento_id' => $mov->id,
                'consignacion_id' => $con->id,
                'valor_aplicado' => $aplicar,
                'regla' => 'manual',
                'dias_diferencia' => $dias,
                'usuario_id' => Auth::id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->refrescarEstados($mov->id, $con->id);
        });

        Bitacora::registrar(
            'vincular', 'BancoMovimiento', (int) $mov->id,
            "Movimiento {$mov->id} vinculado a mano con la consignación {$con->id}",
            ['valor_aplicado' => $aplicar, 'dias' => $dias],
            $aliadoId
        );

        return back()->with('success', "Movimiento vinculado con la consignación {$con->id}.");
    }

    /**
     * Crea en BryNex el gasto que explica una salida del extracto.
     *
     * Es el caso del 4x1000, la cuota de manejo o un pago que nadie registró:
     * el dinero salió de verdad, así que el libro tiene que tenerlo. Se crea el
     * gasto —no se modifica ninguno existente— y queda amarrado al movimiento,
     * con lo cual el saldo del libro se acerca al del banco en ese mismo valor.
     */
    public function registrarGasto(Request $request, int $movimientoId)
    {
        $datos = $request->validate([
            'tipo' => ['required', 'string', Rule::in(array_keys(Gasto::TIPOS))],
            'descripcion' => 'required|string|max:255',
            'pagado_a' => 'nullable|string|max:255',
        ]);

        $aliadoId = $this->aliadoId();

        $mov = BancoMovimiento::where('id', $movimientoId)->where('aliado_id', $aliadoId)->first();
        if (! $mov) {
            abort(404, 'Ese movimiento no es de este aliado.');
        }
        if ($mov->tipo !== BancoMovimiento::TIPO_DEBITO) {
            return back()->with('error', 'Ese movimiento es una entrada: se registra como consignación, no como gasto.');
        }
        if ($mov->gastos()->count() > 0) {
            return back()->with('error', 'Ese movimiento ya tiene un gasto que lo explica.');
        }

        DB::transaction(function () use ($mov, $datos, $aliadoId) {
            $gasto = Gasto::create([
                'aliado_id' => $aliadoId,
                'usuario_id' => Auth::id(),
                'cuadre_id' => null,
                'fecha' => $mov->fecha,
                'tipo' => $datos['tipo'],
                'descripcion' => $datos['descripcion'],
                'pagado_a' => $datos['pagado_a'] ?? null,
                'forma_pago' => 'transferencia_bancaria',
                'banco_origen_id' => $mov->banco_cuenta_id,
                'valor' => (int) round((float) $mov->valor),
                'observacion' => 'Registrado desde el extracto del banco (movimiento '.$mov->id.')',
            ]);

            DB::table('banco_movimiento_gasto')->insert([
                'aliado_id' => $aliadoId,
                'banco_movimiento_id' => $mov->id,
                'gasto_id' => $gasto->id,
                'valor_aplicado' => (float) $mov->valor,
                'regla' => 'creado',
                'dias_diferencia' => 0,
                'usuario_id' => Auth::id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $mov->update([
                'estado_conciliacion' => BancoMovimiento::CONCILIACION_CONCILIADO,
                'conciliado_por' => Auth::id(),
                'conciliado_at' => now(),
            ]);

            Bitacora::registrar(
                'crear', 'Gasto', (int) $gasto->id,
                'Gasto creado desde el extracto: '.$datos['descripcion'],
                ['movimiento' => (int) $mov->id, 'valor' => (float) $mov->valor],
                $aliadoId
            );
        });

        return back()->with('success', 'Gasto registrado y movimiento cuadrado.');
    }

    /**
     * Crea en BryNex la consignación que explica una entrada del extracto.
     *
     * Sirve para la plata que llegó y nadie registró: un traslado desde otra
     * cuenta propia, los intereses que abona el banco, un pago que no se
     * digitó. Queda confirmada de entrada porque viene del extracto: el banco
     * ya la reportó.
     */
    public function registrarEntrada(Request $request, int $movimientoId)
    {
        $datos = $request->validate([
            'tipo' => ['required', Rule::in([
                Consignacion::TIPO_BANCO_RECIBIDO,
                Consignacion::TIPO_TRASLADO_EFECTIVO,
                Consignacion::TIPO_CLIENTE,
            ])],
            'observacion' => 'required|string|max:500',
        ]);

        $aliadoId = $this->aliadoId();

        $mov = BancoMovimiento::where('id', $movimientoId)->where('aliado_id', $aliadoId)->first();
        if (! $mov) {
            abort(404, 'Ese movimiento no es de este aliado.');
        }
        if ($mov->tipo !== BancoMovimiento::TIPO_CREDITO) {
            return back()->with('error', 'Ese movimiento es una salida: se registra como gasto, no como consignación.');
        }
        if ($mov->consignaciones()->count() > 0) {
            return back()->with('error', 'Ese movimiento ya tiene una consignación que lo explica.');
        }

        DB::transaction(function () use ($mov, $datos, $aliadoId) {
            $consig = Consignacion::create([
                'aliado_id' => $aliadoId,
                'banco_cuenta_id' => $mov->banco_cuenta_id,
                'factura_id' => null,
                'fecha' => $mov->fecha,
                'valor' => (int) round((float) $mov->valor),
                'tipo' => $datos['tipo'],
                'referencia' => $mov->referencia,
                'confirmado' => true,
                'observacion' => $datos['observacion'],
                'usuario_id' => Auth::id(),
            ]);

            DB::table('banco_movimiento_consignacion')->insert([
                'aliado_id' => $aliadoId,
                'banco_movimiento_id' => $mov->id,
                'consignacion_id' => $consig->id,
                'valor_aplicado' => (float) $mov->valor,
                'regla' => 'creado',
                'dias_diferencia' => 0,
                'usuario_id' => Auth::id(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $mov->update([
                'estado_conciliacion' => BancoMovimiento::CONCILIACION_CONCILIADO,
                'consignacion_id' => $consig->id,
                'conciliado_por' => Auth::id(),
                'conciliado_at' => now(),
            ]);

            Bitacora::registrar(
                'crear', 'Consignacion', (int) $consig->id,
                'Entrada registrada desde el extracto: '.$datos['observacion'],
                ['movimiento' => (int) $mov->id, 'valor' => (float) $mov->valor],
                $aliadoId
            );
        });

        return back()->with('success', 'Entrada registrada y movimiento cuadrado.');
    }

    /** Deshace un cruce. Si la consignación queda sin respaldo, vuelve a pendiente. */
    public function desvincular(int $pivoteId)
    {
        $aliadoId = $this->aliadoId();

        $fila = DB::table('banco_movimiento_consignacion')
            ->where('id', $pivoteId)->where('aliado_id', $aliadoId)->first();

        if (! $fila) {
            abort(404, 'Ese vínculo no es de este aliado.');
        }

        DB::transaction(function () use ($fila) {
            DB::table('banco_movimiento_consignacion')->where('id', $fila->id)->delete();
            $this->refrescarEstados((int) $fila->banco_movimiento_id, (int) $fila->consignacion_id);
        });

        Bitacora::registrar(
            'desvincular', 'BancoMovimiento', (int) $fila->banco_movimiento_id,
            "Se deshizo el cruce con la consignación {$fila->consignacion_id}",
            null, $aliadoId
        );

        return back()->with('success', 'Cruce deshecho.');
    }

    /** Marca un movimiento como que no le corresponde al libro (costos del banco). */
    public function ignorar(int $movimientoId)
    {
        $aliadoId = $this->aliadoId();

        $mov = BancoMovimiento::where('id', $movimientoId)->where('aliado_id', $aliadoId)->first();
        if (! $mov) {
            abort(404, 'Ese movimiento no es de este aliado.');
        }

        if ($mov->consignaciones()->count() > 0) {
            return back()->with('error', 'Ese movimiento ya está cruzado. Deshaga el cruce primero.');
        }

        $mov->update([
            'estado_conciliacion' => BancoMovimiento::CONCILIACION_IGNORADO,
            'clasificacion' => BancoMovimiento::CLASIFICACION_COSTO_BANCO,
            'conciliado_por' => Auth::id(),
            'conciliado_at' => now(),
        ]);

        return back()->with('success', "Movimiento {$mov->id} marcado como ajeno al libro.");
    }

    /**
     * Marca un movimiento como plata personal del titular.
     *
     * Varias cuentas del aliado son personales aunque por ellas pase la
     * operación: en su extracto conviven la planilla de un cliente y el mercado
     * del sábado. Lo personal sale del cuadre del negocio, pero se guarda con
     * su motivo —no revuelto con los cobros del banco— para poder decir cuánto
     * de la diferencia de una cuenta es simplemente vida privada.
     */
    public function marcarPersonal(int $movimientoId)
    {
        $aliadoId = $this->aliadoId();

        $mov = BancoMovimiento::where('id', $movimientoId)->where('aliado_id', $aliadoId)->first();
        if (! $mov) {
            abort(404, 'Ese movimiento no es de este aliado.');
        }

        if ($mov->consignaciones()->count() > 0 || $mov->gastos()->count() > 0) {
            return back()->with('error', 'Ese movimiento ya está cruzado con el libro. Deshaga el cruce primero.');
        }

        $mov->update([
            'estado_conciliacion' => BancoMovimiento::CONCILIACION_IGNORADO,
            'clasificacion' => BancoMovimiento::CLASIFICACION_PERSONAL,
            'conciliado_por' => Auth::id(),
            'conciliado_at' => now(),
        ]);

        Bitacora::registrar(
            'marcar_personal', 'BancoMovimiento', (int) $mov->id,
            'Movimiento marcado como personal: '.mb_substr((string) $mov->descripcion, 0, 60),
            ['valor' => (float) $mov->valor],
            $aliadoId
        );

        return back()->with('success', 'Movimiento marcado como personal: queda fuera del cuadre del negocio.');
    }

    /**
     * Marca una consignación como `no aparece` en el extracto.
     *
     * Esta es la decisión que el cruce automático nunca toma sola, porque que
     * algo no esté en el extracto también puede ser que el rango consultado no
     * lo alcanza. Aquí la toma una persona y queda su nombre.
     */
    public function noAparece(int $consignacionId)
    {
        $aliadoId = $this->aliadoId();

        $con = Consignacion::where('id', $consignacionId)->where('aliado_id', $aliadoId)->first();
        if (! $con) {
            abort(404, 'Esa consignación no es de este aliado.');
        }

        if ($con->movimientosBanco()->count() > 0) {
            return back()->with('error', 'Esa consignación sí tiene respaldo en el banco.');
        }

        $con->update([
            'confirmado' => 0,
            'no_aparece' => 1,
            'usuario_validador_id' => null,
            'fecha_validacion' => null,
            'observacion' => $this->sinFirma($con->observacion),
        ]);

        Bitacora::registrar(
            'no_aparece', 'Consignacion', (int) $con->id,
            'Marcada como no encontrada en el extracto del banco',
            null, $aliadoId
        );

        return back()->with('success', "Consignación {$con->id} marcada como no encontrada.");
    }

    /** Busca consignaciones libres para vincular a mano, desde el modal. */
    public function buscarConsignaciones(Request $request)
    {
        $aliadoId = $this->aliadoId();
        $movimientoId = (int) $request->input('movimiento_id');

        $mov = BancoMovimiento::where('id', $movimientoId)->where('aliado_id', $aliadoId)->first();
        if (! $mov) {
            return response()->json(['error' => 'Movimiento no encontrado'], 404);
        }

        $texto = trim((string) $request->input('q', ''));

        $query = DB::table('consignaciones as cs')
            ->leftJoin('banco_movimiento_consignacion as p', 'p.consignacion_id', '=', 'cs.id')
            ->leftJoin('facturas as f', 'f.id', '=', 'cs.factura_id')
            ->leftJoin('clientes as cl', function ($j) use ($aliadoId) {
                $j->on('cl.cedula', '=', 'f.cedula')->where('cl.aliado_id', $aliadoId);
            })
            ->leftJoin('empresas as em', 'em.id', '=', 'f.empresa_id')
            ->where('cs.aliado_id', $aliadoId)
            ->where('cs.banco_cuenta_id', $mov->banco_cuenta_id)
            ->whereNull('cs.deleted_at')
            ->whereNull('p.id');

        if ($texto !== '') {
            $query->where(function ($q) use ($texto) {
                $q->where('cs.referencia', 'like', "%$texto%")
                    ->orWhere('f.numero_factura', 'like', "%$texto%")
                    ->orWhere('f.cedula', 'like', "%$texto%")
                    ->orWhereSinTildes('em.empresa', $texto)
                    ->orWhereSinTildes('cl.primer_apellido', $texto);
            });
        } else {
            // Sin búsqueda, lo más probable: mismo valor y fechas cercanas.
            $query->where('cs.valor', (int) round((float) $mov->valor))
                ->whereBetween('cs.fecha', [
                    Carbon::parse($mov->fecha)->subDays(10)->toDateString(),
                    Carbon::parse($mov->fecha)->addDays(10)->toDateString(),
                ]);
        }

        $filas = $query->orderByDesc('cs.fecha')
            ->limit(25)
            ->selectRaw("
                cs.id, cs.valor, cs.referencia,
                CONVERT(VARCHAR(10), cs.fecha, 120) AS fecha,
                f.numero_factura,
                CASE
                    WHEN f.empresa_id IS NOT NULL AND f.empresa_id > 0
                        THEN UPPER(ISNULL(em.empresa, '—'))
                    ELSE LTRIM(RTRIM(ISNULL(cl.primer_nombre,'') + ' ' + ISNULL(cl.primer_apellido,'')))
                END AS titular
            ")
            ->get();

        return response()->json(['consignaciones' => $filas]);
    }

    // ── Apoyo ────────────────────────────────────────────────────────

    private function rango(Request $request): array
    {
        $hasta = $request->input('hasta') ?: now()->toDateString();
        $desde = $request->input('desde') ?: now()->startOfMonth()->toDateString();

        try {
            $d = Carbon::parse($desde)->toDateString();
            $h = Carbon::parse($hasta)->toDateString();
        } catch (Throwable $e) {
            return [now()->startOfMonth()->toDateString(), now()->toDateString()];
        }

        return $d <= $h ? [$d, $h] : [$h, $d];
    }

    /** @return \Illuminate\Support\Collection<BancoCuenta> */
    private function cuentasDelRequest(Request $request)
    {
        $aliadoId = $this->aliadoId();
        $cuentaId = (int) $request->input('cuenta', 0);

        $query = BancoCuenta::where('aliado_id', $aliadoId)->where('activo', true);
        if ($cuentaId) {
            $query->where('id', $cuentaId);
        }

        return $query->get();
    }

    private function aplicadoMovimiento(int $movimientoId): float
    {
        return (float) DB::table('banco_movimiento_consignacion')
            ->where('banco_movimiento_id', $movimientoId)->sum('valor_aplicado');
    }

    private function aplicadoConsignacion(int $consignacionId): float
    {
        return (float) DB::table('banco_movimiento_consignacion')
            ->where('consignacion_id', $consignacionId)->sum('valor_aplicado');
    }

    /**
     * Deja el movimiento y la consignación en el estado que corresponde según
     * lo que quedó imputado. Se llama después de vincular y de desvincular:
     * ambos caminos tienen que dejar lo mismo, y si se calcula en cada uno por
     * separado terminan divergiendo.
     */
    private function refrescarEstados(int $movimientoId, int $consignacionId): void
    {
        $mov = BancoMovimiento::find($movimientoId);
        if ($mov) {
            $aplicado = $this->aplicadoMovimiento($movimientoId);
            $vinculos = DB::table('banco_movimiento_consignacion')
                ->where('banco_movimiento_id', $movimientoId)->pluck('consignacion_id');

            $mov->update([
                'estado_conciliacion' => $aplicado >= (float) $mov->valor
                    ? BancoMovimiento::CONCILIACION_CONCILIADO
                    : BancoMovimiento::CONCILIACION_PENDIENTE,
                // El atajo solo aplica cuando hay una sola consignación detrás.
                'consignacion_id' => $vinculos->count() === 1 ? (int) $vinculos->first() : null,
                'conciliado_por' => $vinculos->isEmpty() ? null : Auth::id(),
                'conciliado_at' => $vinculos->isEmpty() ? null : now(),
            ]);
        }

        $con = Consignacion::find($consignacionId);
        if ($con) {
            $cubierta = $this->aplicadoConsignacion($consignacionId) >= (float) $con->valor;

            $con->update([
                'confirmado' => $cubierta ? 1 : 0,
                'no_aparece' => 0,
                'observacion' => $cubierta
                    ? $this->conFirma($con->observacion)
                    : $this->sinFirma($con->observacion),
            ]);
        }
    }

    /** Misma firma que estampa el informe de validación, para que la reconozca. */
    private function conFirma(?string $observacion): string
    {
        $firma = '[Soporte - Validado por: Conciliación con el extracto]';
        $base = $this->sinFirma($observacion);

        return trim($base.' '.$firma);
    }

    private function sinFirma(?string $observacion): ?string
    {
        $limpia = trim((string) preg_replace(
            '/\s*\[Soporte\s*-\s*Validado\s*por:\s*[^\]]+\]/i',
            '',
            (string) $observacion
        ));

        return $limpia !== '' ? $limpia : null;
    }
}
