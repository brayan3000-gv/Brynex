<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contrato;
use App\Models\Factura;
use App\Models\Plano;
use App\Models\RazonSocial;
use App\Models\User;
use App\Services\CorreccionNovedadesService;
use App\Services\PlanoPilaTxtService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * TrasladoRazonSocialController
 *
 * Flujo:
 *  1. index()         → Vista principal (selección RS origen + pegar cédulas)
 *  2. validar()       → API: busca contratos vigentes en la RS origen
 *  3. ejecutar()      → Crea nuevos contratos + planos de afiliación (costo 0)
 *  4. retirarOpcionA() → Duplica el último plano con fecha_ret + marca retiro
 *  5. retirarOpcionB() → Crea plano de retiro futuro + marca retiro
 *  6. descargarPlano() → Genera TXT MiPlanilla con novedades ING+RET (opción A)
 */
class TrasladoRazonSocialController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'role:superadmin|admin']);
    }

    // ─── 1. Vista principal ───────────────────────────────────────────────────
    public function index(): \Illuminate\View\View
    {
        $aliadoId = session('aliado_id_activo');

        $razonesSociales = RazonSocial::where('aliado_id', $aliadoId)
            ->orderByRaw("CASE WHEN estado = 'Activa' THEN 0 ELSE 1 END")
            ->orderBy('razon_social')
            ->get(['id', 'razon_social', 'estado', 'n_plano']);

        $usuarios = User::where('aliado_id', $aliadoId)
            ->where('activo', true)
            ->orderBy('nombre')
            ->get(['id', 'nombre']);

        $tienePivotOp = DB::table('aliado_operadores_planilla')->where('aliado_id', $aliadoId)->exists();
        if ($tienePivotOp) {
            $operadores = DB::table('operadores_planilla AS op')
                ->join('aliado_operadores_planilla AS piv',
                    fn($j) => $j->on('piv.operador_id', '=', 'op.id')
                                 ->where('piv.aliado_id', $aliadoId)
                                 ->where('piv.activo', true))
                ->whereNull('op.aliado_id')
                ->where('op.activo', true)
                ->orderBy('op.orden')
                ->select('op.*')
                ->get();
        } else {
            $operadores = DB::table('operadores_planilla')
                ->whereNull('aliado_id')
                ->where('activo', true)
                ->orderBy('orden')
                ->get(['id', 'nombre', 'codigo_ni']);
        }

        return view('admin.traslados.index', compact(
            'razonesSociales', 'usuarios', 'operadores'
        ));
    }

    // ─── 2. API: Validar cédulas en la RS origen ──────────────────────────────
    public function validar(Request $request): JsonResponse
    {
        $aliadoId = session('aliado_id_activo');

        $request->validate([
            'razon_social_origen_id' => 'required',
            'cedulas'                => 'required|string',
        ]);

        $rsOrigenId = (int) $request->input('razon_social_origen_id');

        // Verificar que la RS pertenece al aliado
        $rsOrigen = RazonSocial::where('aliado_id', $aliadoId)->find($rsOrigenId);
        if (!$rsOrigen) {
            return response()->json(['ok' => false, 'mensaje' => 'Razón Social no encontrada.'], 422);
        }

        // Parsear cédulas: separadas por salto de línea, coma, punto y coma o espacio
        $raw    = $request->input('cedulas');
        $partes = array_filter(
            array_map('trim', preg_split('/[\n\r,;]+/', $raw)),
            fn($c) => $c !== ''
        );
        $cedulas = array_values(array_unique(array_map(
            fn($c) => preg_replace('/\D/', '', $c),
            $partes
        )));
        $cedulas = array_filter($cedulas, fn($c) => $c !== '');

        if (empty($cedulas)) {
            return response()->json(['ok' => false, 'mensaje' => 'No se encontraron cédulas válidas.'], 422);
        }

        // Buscar contratos VIGENTES de esas cédulas en la RS origen
        $contratos = DB::table('contratos AS c')
            ->leftJoin('clientes AS cl', function ($j) use ($aliadoId) {
                $j->on(DB::raw('CAST(cl.cedula AS VARCHAR(20))'), '=', DB::raw('CAST(c.cedula AS VARCHAR(20))'))
                  ->where('cl.aliado_id', $aliadoId);
            })
            ->leftJoin('razones_sociales AS rs', 'rs.id', '=', 'c.razon_social_id')
            ->leftJoin('eps AS e',         'e.id',  '=', 'c.eps_id')
            ->leftJoin('pensiones AS p',   'p.id',  '=', 'c.pension_id')
            ->leftJoin('arls AS a',        'a.id',  '=', 'c.arl_id')
            ->leftJoin('cajas AS cj',      'cj.id', '=', 'c.caja_id')
            ->leftJoin('tipo_modalidad AS tm', 'tm.id', '=', 'c.tipo_modalidad_id')
            ->leftJoin('planes_contrato AS pc', 'pc.id', '=', 'c.plan_id')
            ->leftJoin('users AS uc',      'uc.id', '=', 'c.encargado_id')
            ->where('c.aliado_id', $aliadoId)
            ->where('c.razon_social_id', $rsOrigenId)
            ->where('c.estado', 'vigente')
            ->whereIn(DB::raw('CAST(c.cedula AS VARCHAR(20))'), $cedulas)
            ->select([
                'c.id AS contrato_id',
                DB::raw('CAST(c.cedula AS VARCHAR(20)) AS cedula'),
                'c.plan_id',
                'c.tipo_modalidad_id',
                'c.eps_id',
                'c.pension_id',
                'c.arl_id',
                'c.n_arl',
                'c.arl_modo',
                'c.arl_nit_cotizante',
                'c.caja_id',
                'c.salario',
                'c.ibc',
                'c.porcentaje_caja',
                'c.dias_tp_afp',
                'c.dias_tp_caja',
                'c.grupo_fondo_solidaridad',
                'c.administracion',
                'c.admon_asesor',
                'c.costo_afiliacion',
                'c.seguro',
                'c.asesor_id',
                'c.encargado_id',
                'c.motivo_afiliacion_id',
                'c.cargo',
                'c.actividad_economica_id',
                'c.envio_planilla',
                'c.fecha_probable_pago',
                'c.modo_probable_pago',
                'c.cobra_planilla_primer_mes',
                DB::raw("CONVERT(VARCHAR(10), c.fecha_ingreso, 23) AS fecha_ingreso"),
                'c.observacion',
                'c.np',
                'c.razon_social_id',
                // Nombre completo del cliente (snapshot)
                DB::raw("ISNULL(LTRIM(RTRIM(
                    ISNULL(cl.primer_nombre,'') + ' ' +
                    ISNULL(cl.segundo_nombre,'') + ' ' +
                    ISNULL(cl.primer_apellido,'') + ' ' +
                    ISNULL(cl.segundo_apellido,'')
                )), '') AS nombre_completo"),
                'cl.tipo_doc',
                // Entidades
                'rs.razon_social AS rs_nombre',
                'e.nombre AS eps_nombre',
                'p.razon_social AS pension_nombre',
                'a.nombre_arl AS arl_nombre',
                'cj.nombre AS caja_nombre',
                'tm.tipo_modalidad AS modalidad_nombre',
                'pc.nombre AS plan_nombre',
                'uc.nombre AS encargado_nombre',
            ])
            ->get();

        // Cédulas no encontradas
        $cedulasEncontradas = $contratos->pluck('cedula')->map(fn($c) => (string)$c)->toArray();
        $noEncontradas = array_values(array_diff(
            array_map('strval', $cedulas),
            $cedulasEncontradas
        ));

        return response()->json([
            'ok'             => true,
            'contratos'      => $contratos,
            'no_encontradas' => $noEncontradas,
            'rs_origen'      => $rsOrigen->razon_social,
            'total'          => $contratos->count(),
        ]);
    }

    // ─── 3. Ejecutar traslado: crear nuevos contratos + planos de afiliación ──
    public function ejecutar(Request $request): JsonResponse
    {
        $aliadoId  = session('aliado_id_activo');
        $usuarioId = Auth::id();

        $validated = $request->validate([
            'contrato_ids'           => 'required|array|min:1',
            'contrato_ids.*'         => 'integer',
            'razon_social_destino_id'=> 'required|integer',
            'encargado_id'           => 'required|integer',
        ]);

        $rsDestino = RazonSocial::where('aliado_id', $aliadoId)
            ->find($validated['razon_social_destino_id']);
        if (!$rsDestino) {
            return response()->json(['ok' => false, 'mensaje' => 'Razón Social destino no encontrada.'], 404);
        }

        $encargado = User::where('aliado_id', $aliadoId)->find($validated['encargado_id']);
        if (!$encargado) {
            return response()->json(['ok' => false, 'mensaje' => 'Encargado no encontrado.'], 404);
        }

        // Fecha de ingreso = 1 del mes actual
        $fechaIngreso = Carbon::now()->startOfMonth()->toDateString();

        $nuevosContratos = [];
        $errores         = [];

        DB::transaction(function () use (
            $validated, $aliadoId, $usuarioId, $rsDestino, $encargado,
            $fechaIngreso, &$nuevosContratos, &$errores
        ) {
            $contratos = Contrato::where('aliado_id', $aliadoId)
                ->whereIn('id', $validated['contrato_ids'])
                ->where('estado', 'vigente')
                ->with(['plan', 'eps', 'pension', 'arl', 'caja', 'cliente'])
                ->get();

            foreach ($contratos as $contratoOrigen) {
                try {
                    // ── Crear nuevo contrato copiando todos los campos relevantes ──
                    $nuevoContrato = Contrato::create([
                        'aliado_id'               => $aliadoId,
                        'cedula'                  => $contratoOrigen->cedula,
                        'estado'                  => 'vigente',
                        // Nueva RS, encargado y fecha de ingreso
                        'razon_social_id'         => $rsDestino->id,
                        'razon_social_bloqueada'  => false,
                        'encargado_id'            => $encargado->id,
                        'fecha_ingreso'           => $fechaIngreso,
                        // Datos copiados del contrato original
                        'plan_id'                 => $contratoOrigen->plan_id,
                        'tipo_modalidad_id'       => $contratoOrigen->tipo_modalidad_id,
                        'eps_id'                  => $contratoOrigen->eps_id,
                        'pension_id'              => $contratoOrigen->pension_id,
                        'arl_id'                  => $contratoOrigen->arl_id,
                        'n_arl'                   => $contratoOrigen->n_arl,
                        'arl_modo'                => $contratoOrigen->arl_modo,
                        // ARL NIT cotizante: si era por RS, usar la nueva RS
                        'arl_nit_cotizante'       => ($contratoOrigen->arl_modo === 'razon_social')
                            ? (int) $rsDestino->id
                            : $contratoOrigen->arl_nit_cotizante,
                        'caja_id'                 => $contratoOrigen->caja_id,
                        'cargo'                   => $contratoOrigen->cargo,
                        'salario'                 => $contratoOrigen->salario,
                        'ibc'                     => $contratoOrigen->ibc,
                        'porcentaje_caja'         => $contratoOrigen->porcentaje_caja,
                        'dias_tp_afp'             => $contratoOrigen->dias_tp_afp,
                        'dias_tp_caja'            => $contratoOrigen->dias_tp_caja,
                        'grupo_fondo_solidaridad' => $contratoOrigen->grupo_fondo_solidaridad,
                        'administracion'          => $contratoOrigen->administracion,
                        'admon_asesor'            => $contratoOrigen->admon_asesor,
                        'costo_afiliacion'        => $contratoOrigen->costo_afiliacion,
                        'seguro'                  => $contratoOrigen->seguro,
                        'asesor_id'               => $contratoOrigen->asesor_id,
                        'motivo_afiliacion_id'    => $contratoOrigen->motivo_afiliacion_id,
                        'actividad_economica_id'  => $contratoOrigen->actividad_economica_id,
                        'envio_planilla'          => $contratoOrigen->envio_planilla,
                        'fecha_probable_pago'     => $contratoOrigen->fecha_probable_pago,
                        'modo_probable_pago'      => $contratoOrigen->modo_probable_pago,
                        'cobra_planilla_primer_mes' => $contratoOrigen->cobra_planilla_primer_mes,
                        'np'                      => $contratoOrigen->np,
                        'observacion'             => $contratoOrigen->observacion,
                        'observacion_afiliacion'  => "Traslado desde RS #{$contratoOrigen->razon_social_id}. Contrato origen #{$contratoOrigen->id}.",
                        'fecha_created'           => now(),
                    ]);

                    // ── Crear factura de afiliación con costo 0 ──
                    $cliente  = $contratoOrigen->cliente;
                    $arl      = $contratoOrigen->arl;
                    $rs       = $rsDestino;

                    $arlSnapshot = \App\Models\Plano::resolverArlSnapshot($contratoOrigen, $rs);
                    $codArl = $arlSnapshot['cod_arl'];
                    $nombreArl = $arlSnapshot['nombre_arl'];

                    $eps  = $contratoOrigen->eps;
                    $afp  = $contratoOrigen->pension;
                    $caja = $contratoOrigen->caja;

                    $facturaAfil = Factura::create([
                        'aliado_id'        => $aliadoId,
                        'numero_factura'   => 0,
                        'tipo'             => 'afiliacion',
                        'cedula'           => $nuevoContrato->cedula,
                        'contrato_id'      => $nuevoContrato->id,
                        'razon_social_id'  => $rsDestino->id,
                        'empresa_id'       => null,
                        'mes'              => now()->month,
                        'anio'             => now()->year,
                        'fecha_pago'       => now()->toDateString(),
                        'estado'           => 'pagada',
                        'forma_pago'       => 'efectivo',
                        'valor_efectivo'   => 0,
                        'valor_consignado' => 0,
                        'valor_prestamo'   => 0,
                        'dias_cotizados'   => 0,
                        'v_eps'   => 0, 'v_arl'  => 0, 'v_afp'  => 0, 'v_caja' => 0,
                        'total_ss'=> 0, 'admon'  => 0, 'admin_asesor' => 0,
                        'otros_admon' => 0, 'seguro' => 0, 'afiliacion' => 0,
                        'mensajeria' => 0, 'otros' => 0, 'mora' => 0, 'iva' => 0,
                        'total'       => 0,
                        'saldo_proximo'=> 0,
                        'n_plano'     => $rsDestino->n_plano ?? 1,
                        'razon_social_id' => $rsDestino->id,
                        'usuario_id'  => $usuarioId,
                        'observacion' => "Afiliación por traslado de RS. Contrato #{$nuevoContrato->id}.",
                    ]);

                    // ── Crear plano de afiliación ──
                    $apellidos = $cliente?->apellidos ?? trim(($cliente?->primer_apellido ?? '') . ' ' . ($cliente?->segundo_apellido ?? ''));
                    $nombres   = $cliente?->nombres   ?? trim(($cliente?->primer_nombre   ?? '') . ' ' . ($cliente?->segundo_nombre   ?? ''));
                    $partsApe  = preg_split('/\s+/', trim($apellidos), 2);
                    $partsNom  = preg_split('/\s+/', trim($nombres),   2);

                    Plano::create([
                        'factura_id'        => $facturaAfil->id,
                        'contrato_id'       => $nuevoContrato->id,
                        'aliado_id'         => $aliadoId,
                        'numero_factura'    => 0,
                        'tipo_reg'          => 'afiliacion',
                        'tipo_doc'          => strtoupper(trim($cliente?->tipo_doc ?? 'CC')) ?: 'CC',
                        'no_identifi'       => $nuevoContrato->cedula,
                        'primer_ape'        => strtoupper($partsApe[0] ?? ''),
                        'segundo_ape'       => strtoupper($partsApe[1] ?? ''),
                        'primer_nombre'     => strtoupper($partsNom[0] ?? ''),
                        'segundo_nombre'    => strtoupper($partsNom[1] ?? ''),
                        'fecha_ing'         => $fechaIngreso,
                        'fecha_ret'         => null,
                        'num_dias'          => 0,
                        'cod_eps'           => $eps?->nit  ?? $eps?->cod_eps  ?? null,
                        'nombre_eps'        => $eps?->nombre ?? null,
                        'cod_afp'           => $afp?->nit  ?? $afp?->cod_afp  ?? null,
                        'nombre_afp'        => $afp?->razon_social ?? null,
                        'cod_arl'           => $codArl,
                        'nombre_arl'        => $nombreArl,
                        'cod_caja'          => $caja?->nit ?? $caja?->cod_caja ?? null,
                        'nombre_caja'       => $caja?->nombre ?? null,
                        'nivel_riesgo'      => $nuevoContrato->n_arl ?? 1,
                        'salario_basico'    => (int)($nuevoContrato->salario ?? 0),
                        'n_plano'           => $rsDestino->n_plano ?? 1,
                        'mes_plano'         => now()->month,
                        'anio_plano'        => now()->year,
                        'razon_social'      => $rsDestino->razon_social,
                        'razon_social_id'   => $rsDestino->id,
                        'tipo_p'            => $nuevoContrato->tipo_modalidad_id,
                        'tipo_modalidad_id' => $nuevoContrato->tipo_modalidad_id,
                        'usuario_id'        => $usuarioId,
                    ]);

                    // ── Crear radicados pendientes del nuevo contrato ──
                    $nuevoContrato->load('plan');
                    $nuevoContrato->crearRadicadosPendientes();

                    $nuevosContratos[] = [
                        'contrato_id_nuevo'   => $nuevoContrato->id,
                        'contrato_id_origen'  => $contratoOrigen->id,
                        'cedula'              => $nuevoContrato->cedula,
                        'factura_afil_id'     => $facturaAfil->id,
                    ];

                } catch (\Throwable $e) {
                    $errores[] = [
                        'cedula'  => $contratoOrigen->cedula,
                        'mensaje' => $e->getMessage(),
                    ];
                }
            }
        });

        if (!empty($errores) && empty($nuevosContratos)) {
            return response()->json([
                'ok'      => false,
                'mensaje' => 'No se pudo crear ningún contrato.',
                'errores' => $errores,
            ], 500);
        }

        return response()->json([
            'ok'               => true,
            'nuevos_contratos' => $nuevosContratos,
            'errores'          => $errores,
            'mensaje'          => count($nuevosContratos) . ' contrato(s) creado(s) correctamente en ' . $rsDestino->razon_social . '.',
        ]);
    }

    // ─── 4a. Retiro Opción A: corrección de la última planilla pagada ────────
    //
    // Cada persona se retira en SU última planilla, no en un período que se
    // elige a mano para todos: quien pagó agosto en el plano 3 y quien pagó
    // julio en el 5 no caben en la misma corrección. El plano de retiro es una
    // copia del pagado —con su razón social, que sin ella no aparece en el
    // módulo de planos ni sale en el TXT (así quedaron 280 retiros de Fecop en
    // mayo-2026)— y la corrección N se descarga por planilla corregida.

    /**
     * Última planilla de cada contrato y si se le puede agregar el retiro.
     *
     * @return array<int, array> por contrato_id: estado ('ok' | 'sin_planilla' |
     *   'pendiente' | 'ya_retirado'), mensaje, el plano base y los datos de la
     *   planilla asociada (número y fecha de pago).
     */
    private function planillasACorregir(int $aliadoId, array $contratoIds): array
    {
        $planos = DB::table('planos AS p')
            ->leftJoin('facturas AS f', 'f.id', '=', 'p.factura_id')
            ->where('p.aliado_id', $aliadoId)
            ->whereIn('p.contrato_id', $contratoIds)
            ->whereIn('p.tipo_reg', ['planilla', 'retiro'])
            ->whereRaw('ISNULL(p.num_dias, 0) > 0')
            ->whereNull('p.deleted_at')
            ->whereNull('f.deleted_at')
            ->orderByRaw('p.anio_plano * 12 + p.mes_plano DESC')
            ->orderByDesc('p.id')
            ->get(['p.*', 'f.mes AS factura_mes', 'f.anio AS factura_anio'])
            ->groupBy('contrato_id');

        $numeros = $planos->flatten(1)
            ->map(fn ($p) => trim((string) $p->numero_planilla))
            ->filter()->unique()->values()->all();

        // La fecha de pago de la planilla vive en el gasto que la pagó.
        $fechasPago = $numeros
            ? DB::table('gastos')
                ->where('aliado_id', $aliadoId)
                ->where('tipo', 'pago_planilla')
                ->whereIn('numero_planilla', $numeros)
                ->selectRaw('numero_planilla, CONVERT(VARCHAR(10), MIN(fecha), 23) AS fecha')
                ->groupBy('numero_planilla')
                ->pluck('fecha', 'numero_planilla')
            : collect();

        $resultado = [];
        foreach ($contratoIds as $contratoId) {
            $ultimo = $planos->get($contratoId)?->first();

            if (! $ultimo) {
                $resultado[$contratoId] = [
                    'estado'  => 'sin_planilla',
                    'mensaje' => 'No tiene planillas con días cotizados: no hay qué corregir.',
                    'plano'   => null,
                ];
                continue;
            }

            $periodo = sprintf('%02d/%d', $ultimo->mes_plano, $ultimo->anio_plano);
            $numero  = trim((string) $ultimo->numero_planilla);

            if ($ultimo->fecha_ret) {
                $estado  = 'ya_retirado';
                $mensaje = "Su última planilla ({$periodo}) ya lleva retiro con fecha "
                    . Carbon::parse($ultimo->fecha_ret)->format('d/m/Y') . '.';
            } elseif ($numero === '') {
                $estado  = 'pendiente';
                $mensaje = "Su último plano ({$periodo}, P{$ultimo->n_plano}) aún no se ha pagado: "
                    . 'el retiro va en esa planilla, no en una corrección.';
            } else {
                $estado  = 'ok';
                $mensaje = null;
            }

            $resultado[$contratoId] = [
                'estado'     => $estado,
                'mensaje'    => $mensaje,
                'plano'      => $ultimo,
                'periodo'    => $periodo,
                // El retiro se reporta dentro del período que se corrige.
                'fecha_ret'  => Carbon::create((int) $ultimo->anio_plano, (int) $ultimo->mes_plano, 1)
                    ->endOfMonth()->toDateString(),
                'planilla'   => $numero ?: null,
                'fecha_pago' => $numero ? ($fechasPago[$numero] ?? null) : null,
            ];
        }

        return $resultado;
    }

    public function previsualizarOpcionA(Request $request): JsonResponse
    {
        $aliadoId = (int) session('aliado_id_activo');

        $validated = $request->validate([
            'contrato_ids'   => 'required|array|min:1',
            'contrato_ids.*' => 'integer',
        ]);

        $contratos = Contrato::where('aliado_id', $aliadoId)
            ->whereIn('id', $validated['contrato_ids'])
            ->with('razonSocial:id,razon_social')
            ->get(['id', 'cedula', 'estado', 'razon_social_id']);

        $planillas = $this->planillasACorregir($aliadoId, $contratos->pluck('id')->map(fn ($id) => (int) $id)->all());

        $filas = $contratos->map(function ($c) use ($planillas) {
            $info  = $planillas[(int) $c->id];
            $plano = $info['plano'];

            // Un contrato que ya no está vigente no se retira otra vez.
            if ($c->estado !== 'vigente') {
                $info['estado']  = 'no_vigente';
                $info['mensaje'] = "El contrato está {$c->estado}.";
            }

            return [
                'contrato_id'  => (int) $c->id,
                'cedula'       => (string) $c->cedula,
                'nombre'       => $plano ? trim("{$plano->primer_nombre} {$plano->segundo_nombre} {$plano->primer_ape} {$plano->segundo_ape}") : '',
                'razon_social' => $plano->razon_social ?? $c->razonSocial?->razon_social,
                'estado'       => $info['estado'],
                'mensaje'      => $info['mensaje'],
                'periodo'      => $info['periodo'] ?? null,
                'n_plano'      => $plano ? (int) $plano->n_plano : null,
                'num_dias'     => $plano ? (int) $plano->num_dias : null,
                'planilla'     => $info['planilla'] ?? null,
                'fecha_pago'   => $info['fecha_pago'] ?? null,
                'fecha_ret'    => $info['fecha_ret'] ?? null,
            ];
        })->values();

        return response()->json(['ok' => true, 'filas' => $filas]);
    }

    public function retirarOpcionA(Request $request): JsonResponse
    {
        $aliadoId  = (int) session('aliado_id_activo');
        $usuarioId = Auth::id();

        $validated = $request->validate([
            'contrato_ids'   => 'required|array|min:1',
            'contrato_ids.*' => 'integer',
        ]);

        $procesados = [];
        $omitidos   = [];
        $errores    = [];

        $contratos = Contrato::where('aliado_id', $aliadoId)
            ->whereIn('id', $validated['contrato_ids'])
            ->where('estado', 'vigente')
            ->get();

        $planillas = $this->planillasACorregir($aliadoId, $contratos->pluck('id')->map(fn ($id) => (int) $id)->all());

        // Cada planilla corregida va en su propio número de plano: una N
        // corrige una sola planilla, y así Planos SS la liquida y la confirma
        // sin tocar a quienes ya la pagaron.
        $nPlanoPorPlanilla = [];
        foreach ($planillas as $info) {
            if ($info['estado'] !== 'ok' || isset($nPlanoPorPlanilla[$info['planilla']])) {
                continue;
            }
            $base = $info['plano'];
            $nPlanoPorPlanilla[$info['planilla']] = CorreccionNovedadesService::siguienteNPlano(
                $aliadoId,
                (int) ($base->razon_social_id ?: 0),
                (int) $base->mes_plano,
                (int) $base->anio_plano,
                (bool) $base->paga_mes_actual,
                array_values($nPlanoPorPlanilla)
            );
        }

        foreach ($contratos as $contrato) {
            $info = $planillas[(int) $contrato->id];

            if ($info['estado'] !== 'ok') {
                $omitidos[] = ['cedula' => (string) $contrato->cedula, 'mensaje' => $info['mensaje']];
                continue;
            }

            $base     = $info['plano'];
            $fechaRet = $info['fecha_ret'];
            $nPlano   = $nPlanoPorPlanilla[$info['planilla']];

            // Una transacción por persona: si algo falla no queda la factura sin
            // su plano ni el contrato retirado sin la corrección.
            try {
                $plano = DB::transaction(function () use ($contrato, $base, $fechaRet, $nPlano, $info, $aliadoId, $usuarioId) {
                    $rsId = $base->razon_social_id ?: $contrato->razon_social_id;

                    // Factura en $0 con el mismo mes de cobro de la que se corrige:
                    // la factura es el mes en que se cobra y el plano el de servicio.
                    $facturaRet = Factura::create([
                        'aliado_id'        => $aliadoId,
                        'numero_factura'   => 0,
                        'tipo'             => 'planilla',
                        'cedula'           => $contrato->cedula,
                        'contrato_id'      => $contrato->id,
                        'razon_social_id'  => $rsId,
                        'empresa_id'       => null,
                        'mes'              => $base->factura_mes ?? $base->mes_plano,
                        'anio'             => $base->factura_anio ?? $base->anio_plano,
                        'fecha_pago'       => now()->toDateString(),
                        'estado'           => 'pagada',
                        'forma_pago'       => 'efectivo',
                        'valor_efectivo'   => 0,
                        'valor_consignado' => 0,
                        'valor_prestamo'   => 0,
                        'dias_cotizados'   => $base->num_dias,
                        'v_eps'   => 0, 'v_arl'  => 0, 'v_afp'  => 0, 'v_caja' => 0,
                        'total_ss'=> 0, 'admon'  => 0, 'admin_asesor' => 0,
                        'otros_admon' => 0, 'seguro' => 0, 'afiliacion' => 0,
                        'mensajeria' => 0, 'otros' => 0, 'mora' => 0, 'iva' => 0,
                        'total'        => 0,
                        'saldo_proximo'=> 0,
                        'n_plano'      => $nPlano,
                        'usuario_id'   => $usuarioId,
                        'observacion'  => "Corrección de la planilla {$info['planilla']} ({$info['periodo']}): "
                            . "retiro por traslado de razón social. Fecha retiro: {$fechaRet}.",
                    ]);

                    // Copia de la línea pagada con la novedad de retiro. tipo_p 16
                    // es lo que hace que el TXT salga como planilla N.
                    $plano = Plano::create([
                        'factura_id'        => $facturaRet->id,
                        'contrato_id'       => $contrato->id,
                        'aliado_id'         => $aliadoId,
                        'numero_factura'    => 0,
                        'tipo_reg'          => 'retiro',
                        'tipo_doc'          => $base->tipo_doc,
                        'no_identifi'       => $base->no_identifi,
                        'primer_ape'        => $base->primer_ape,
                        'segundo_ape'       => $base->segundo_ape,
                        'primer_nombre'     => $base->primer_nombre,
                        'segundo_nombre'    => $base->segundo_nombre,
                        // La novedad de ingreso de la línea pagada se repite:
                        // el operador exige que la C traiga las mismas de la A
                        // (eo.val.2.090.14) más el retiro.
                        'fecha_ing'         => $base->fecha_ing,
                        'fecha_ret'         => $fechaRet,
                        'num_dias'          => $base->num_dias,
                        'cod_eps'           => $base->cod_eps,
                        'nombre_eps'        => $base->nombre_eps,
                        'cod_afp'           => $base->cod_afp,
                        'nombre_afp'        => $base->nombre_afp,
                        'cod_arl'           => $base->cod_arl,
                        'nombre_arl'        => $base->nombre_arl,
                        'cod_caja'          => $base->cod_caja,
                        'nombre_caja'       => $base->nombre_caja,
                        'nivel_riesgo'      => $base->nivel_riesgo,
                        'salario_basico'    => $base->salario_basico,
                        'n_plano'           => $nPlano,
                        'mes_plano'         => $base->mes_plano,
                        'anio_plano'        => $base->anio_plano,
                        'razon_social'      => $base->razon_social,
                        'razon_social_id'   => $rsId,
                        'tipo_p'            => 16,
                        'tipo_modalidad_id' => $base->tipo_modalidad_id ?? $contrato->tipo_modalidad_id,
                        'paga_mes_actual'   => $base->paga_mes_actual,
                        'dias_tp_afp'       => $base->dias_tp_afp,
                        'dias_tp_caja'      => $base->dias_tp_caja,
                        'grupo_fondo_solidaridad' => $base->grupo_fondo_solidaridad,
                        'usuario_id'        => $usuarioId,
                    ]);

                    $contrato->update([
                        'estado'       => 'retirado',
                        'fecha_retiro' => $fechaRet,
                    ]);

                    return $plano;
                });

                $procesados[] = [
                    'cedula'      => (string) $contrato->cedula,
                    'contrato_id' => (int) $contrato->id,
                    'plano_id'    => (int) $plano->id,
                    'planilla'    => $info['planilla'],
                    'fecha_pago'  => $info['fecha_pago'],
                    'periodo'     => $info['periodo'],
                    'fecha_ret'   => $fechaRet,
                    'n_plano'     => $nPlano,
                    'rs_id'       => (int) ($base->razon_social_id ?: $contrato->razon_social_id),
                    'paga_mes_actual' => (bool) $base->paga_mes_actual,
                    'mes_plano'   => (int) $base->mes_plano,
                    'anio_plano'  => (int) $base->anio_plano,
                ];
            } catch (\Throwable $e) {
                $errores[] = ['cedula' => (string) $contrato->cedula, 'mensaje' => $e->getMessage()];
            }
        }

        // Una corrección N por planilla corregida.
        $correcciones = collect($procesados)
            ->groupBy('planilla')
            ->map(fn ($g, $numero) => [
                'planilla'   => (string) $numero,
                'fecha_pago' => $g->first()['fecha_pago'],
                'periodo'    => $g->first()['periodo'],
                'plano_ids'  => $g->pluck('plano_id')->all(),
                'cantidad'   => $g->count(),
                'n_plano'    => $g->first()['n_plano'],
                // Filtro de Planos SS (mes de PAGO) donde queda esta tanda.
                'url_planos' => route('admin.planos.index', [
                    'razon_social_id' => $g->first()['rs_id'],
                    'n_plano'         => $g->first()['n_plano'],
                    'mes'             => $g->first()['paga_mes_actual'] ? $g->first()['mes_plano'] : ($g->first()['mes_plano'] % 12) + 1,
                    'anio'            => ! $g->first()['paga_mes_actual'] && $g->first()['mes_plano'] === 12 ? $g->first()['anio_plano'] + 1 : $g->first()['anio_plano'],
                ]),
            ])->values();

        return response()->json([
            'ok'           => count($procesados) > 0,
            'procesados'   => $procesados,
            'omitidos'     => $omitidos,
            'errores'      => $errores,
            'correcciones' => $correcciones,
            'mensaje'      => count($procesados) . ' retiro(s) aplicado(s) como corrección de su última planilla.',
        ]);
    }

    // ─── 4b. Retiro Opción B: crear plano de retiro en mes futuro ────────────
    public function retirarOpcionB(Request $request): JsonResponse
    {
        $aliadoId  = session('aliado_id_activo');
        $usuarioId = Auth::id();

        $validated = $request->validate([
            'contrato_ids'           => 'required|array|min:1',
            'contrato_ids.*'         => 'integer',
            'mes_retiro'             => 'required|integer|between:1,12',
            'anio_retiro'            => 'required|integer|min:2020|max:2099',
            'n_plano'                => 'required|integer|min:1',
            'fecha_ingreso_nuevo'    => 'required|date',  // 1 del mes actual (para calcular fecha_ret)
        ]);

        $mesRetiro         = (int) $validated['mes_retiro'];
        $anioRetiro        = (int) $validated['anio_retiro'];
        $nPlano            = (int) $validated['n_plano'];
        $fechaIngresoNuevo = Carbon::parse($validated['fecha_ingreso_nuevo']); // 1 del mes actual

        $procesados = [];
        $errores    = [];

        DB::transaction(function () use (
            $validated, $aliadoId, $usuarioId, $mesRetiro, $anioRetiro, $nPlano,
            $fechaIngresoNuevo, &$procesados, &$errores
        ) {
            $contratos = Contrato::where('aliado_id', $aliadoId)
                ->whereIn('id', $validated['contrato_ids'])
                ->where('estado', 'vigente')
                ->with(['eps', 'pension', 'arl', 'caja', 'razonSocial', 'cliente'])
                ->get();

            foreach ($contratos as $contrato) {
                try {
                    $cliente  = $contrato->cliente;
                    $eps      = $contrato->eps;
                    $afp      = $contrato->pension;
                    $arl      = $contrato->arl;
                    $caja     = $contrato->caja;
                    $rs       = $contrato->razonSocial;

                    $arlSnapshot = \App\Models\Plano::resolverArlSnapshot($contrato, $rs);
                    $codArl = $arlSnapshot['cod_arl'];
                    $nombreArl = $arlSnapshot['nombre_arl'];

                    $apellidos = $cliente?->apellidos ?? trim(($cliente?->primer_apellido ?? '') . ' ' . ($cliente?->segundo_apellido ?? ''));
                    $nombres   = $cliente?->nombres   ?? trim(($cliente?->primer_nombre   ?? '') . ' ' . ($cliente?->segundo_nombre   ?? ''));
                    $partsApe  = preg_split('/\s+/', trim($apellidos), 2);
                    $partsNom  = preg_split('/\s+/', trim($nombres),   2);

                    // ── Regla fecha_ret (Opción B) ─────────────────────────────
                    // Si fecha_ingreso_nuevo (1-mes_actual) está en el MISMO mes de retiro
                    // → fecha_ret = fecha_ingreso_nuevo
                    // Si no → fecha_ret = 1 del mes de retiro
                    // Mes de retiro = mes anterior al mes del plano de retiro
                    // (el plano de retiro se genera en el mes de PAGO que es mesRetiro)
                    // El mes_plano del retiro = mesRetiro - 1 (mes vencido)
                    $mesPlanoRetiro  = $mesRetiro > 1 ? $mesRetiro - 1 : 12;
                    $anioPlanoRetiro = $mesRetiro > 1 ? $anioRetiro    : $anioRetiro - 1;

                    $mismoMes = ($fechaIngresoNuevo->month === $mesPlanoRetiro &&
                                 $fechaIngresoNuevo->year  === $anioPlanoRetiro);

                    if ($mismoMes) {
                        $fechaRet = $fechaIngresoNuevo->toDateString();
                    } else {
                        $fechaRet = Carbon::createFromDate($anioPlanoRetiro, $mesPlanoRetiro, 1)->toDateString();
                    }

                    // Nueva factura de retiro (numero_factura=0, costo=0)
                    $facturaRet = Factura::create([
                        'aliado_id'        => $aliadoId,
                        'numero_factura'   => 0,
                        'tipo'             => 'planilla',
                        'cedula'           => $contrato->cedula,
                        'contrato_id'      => $contrato->id,
                        'razon_social_id'  => $contrato->razon_social_id,
                        'empresa_id'       => null,
                        'mes'              => $mesRetiro,
                        'anio'             => $anioRetiro,
                        'fecha_pago'       => now()->toDateString(),
                        'estado'           => 'pagada',
                        'forma_pago'       => 'efectivo',
                        'valor_efectivo'   => 0,
                        'valor_consignado' => 0,
                        'valor_prestamo'   => 0,
                        'dias_cotizados'   => 1,
                        'v_eps'   => 0, 'v_arl'  => 0, 'v_afp'  => 0, 'v_caja' => 0,
                        'total_ss'=> 0, 'admon'  => 0, 'admin_asesor' => 0,
                        'otros_admon' => 0, 'seguro' => 0, 'afiliacion' => 0,
                        'mensajeria' => 0, 'otros' => 0, 'mora' => 0, 'iva' => 0,
                        'total'        => 0,
                        'saldo_proximo'=> 0,
                        'n_plano'      => $nPlano,
                        'usuario_id'   => $usuarioId,
                        'observacion'  => "Plano de retiro por traslado RS. Fecha retiro: {$fechaRet}.",
                    ]);

                    // Plano de retiro futuro
                    Plano::create([
                        'factura_id'        => $facturaRet->id,
                        'contrato_id'       => $contrato->id,
                        'aliado_id'         => $aliadoId,
                        'numero_factura'    => 0,
                        'tipo_reg'          => 'retiro',
                        'tipo_doc'          => strtoupper(trim($cliente?->tipo_doc ?? 'CC')) ?: 'CC',
                        'no_identifi'       => $contrato->cedula,
                        'primer_ape'        => strtoupper($partsApe[0] ?? ''),
                        'segundo_ape'       => strtoupper($partsApe[1] ?? ''),
                        'primer_nombre'     => strtoupper($partsNom[0] ?? ''),
                        'segundo_nombre'    => strtoupper($partsNom[1] ?? ''),
                        'fecha_ing'         => null,
                        'fecha_ret'         => $fechaRet,
                        'num_dias'          => 1,
                        'cod_eps'           => $eps?->nit  ?? $eps?->cod_eps  ?? null,
                        'nombre_eps'        => $eps?->nombre ?? null,
                        'cod_afp'           => $afp?->nit  ?? $afp?->cod_afp  ?? null,
                        'nombre_afp'        => $afp?->razon_social ?? null,
                        'cod_arl'           => $codArl,
                        'nombre_arl'        => $nombreArl,
                        'cod_caja'          => $caja?->nit ?? $caja?->cod_caja ?? null,
                        'nombre_caja'       => $caja?->nombre ?? null,
                        'nivel_riesgo'      => $contrato->n_arl ?? 1,
                        'salario_basico'    => (int)($contrato->salario ?? 0),
                        'n_plano'           => $nPlano,
                        'mes_plano'         => $mesPlanoRetiro,
                        'anio_plano'        => $anioPlanoRetiro,
                        'razon_social'      => $rs?->razon_social ?? null,
                        'tipo_p'            => 16,
                        'tipo_modalidad_id' => $contrato->tipo_modalidad_id,
                        'usuario_id'        => $usuarioId,
                    ]);

                    // Marcar contrato anterior como retirado
                    $contrato->update([
                        'estado'       => 'retirado',
                        'fecha_retiro' => $fechaRet,
                    ]);

                    $procesados[] = [
                        'cedula'      => $contrato->cedula,
                        'contrato_id' => $contrato->id,
                        'fecha_ret'   => $fechaRet,
                        'factura_id'  => $facturaRet->id,
                    ];

                } catch (\Throwable $e) {
                    $errores[] = ['cedula' => $contrato->cedula, 'mensaje' => $e->getMessage()];
                }
            }
        });

        return response()->json([
            'ok'         => count($procesados) > 0,
            'procesados' => $procesados,
            'errores'    => $errores,
            'mensaje'    => count($procesados) . ' plano(s) de retiro creado(s) para el mes ' . $mesRetiro . '/' . $anioRetiro . '.',
        ]);
    }

    // ─── 5. Descarga TXT MiPlanilla con novedades ING+RET (solo Opción A) ────
    public function descargarPlano(Request $request)
    {
        $aliadoId = session('aliado_id_activo');

        $razonSocialId  = $request->input('razon_social_id');
        $mes            = (int) $request->input('mes',  now()->month);
        $anio           = (int) $request->input('anio', now()->year);
        $nPlano         = (int) $request->input('n_plano', 1);
        $tiposModalidad = array_map('intval', (array) $request->input('tipos_modalidad', []));
        $operadorId     = $request->input('operador_id');

        $codigoOperador = '88';
        if ($operadorId) {
            $codigoOperador = DB::table('operadores_planilla')
                ->where('id', $operadorId)
                ->value('codigo_ni') ?: '88';
        }

        $planoIds = array_values(array_filter(array_map('intval', (array) $request->input('plano_ids', []))));
        if ($planoIds) {
            return $this->descargarCorreccion($aliadoId, $planoIds, $codigoOperador);
        }

        if (!$razonSocialId) {
            abort(400, 'Debe especificar la Razón Social.');
        }

        try {
            $service = new PlanoPilaTxtService();
            return $service->generar([
                'aliado_id'       => $aliadoId,
                'razon_social_id' => $razonSocialId,
                'mes'             => $mes,
                'anio'            => $anio,
                'n_plano'         => $nPlano,
                'tipos_modalidad' => $tiposModalidad,
                'codigo_operador' => $codigoOperador,
                'ignorar_mes_vencido' => true,
                'tipo_planilla' => 'N',
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            abort(500, 'Error de base de datos al generar el TXT.');
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        } catch (\Exception $e) {
            abort(500, 'Error al generar el TXT: ' . $e->getMessage());
        }
    }

    /**
     * TXT de la corrección N de una planilla: solo los retiros del traslado,
     * con el número y la fecha de pago de la planilla que corrigen (campos 9 y
     * 10 del registro tipo 1). Todo sale de los planos: la pantalla solo dice
     * cuáles.
     */
    private function descargarCorreccion(int $aliadoId, array $planoIds, string $codigoOperador)
    {
        $retiros = DB::table('planos')
            ->where('aliado_id', $aliadoId)
            ->whereIn('id', $planoIds)
            ->where('tipo_reg', 'retiro')
            ->where('tipo_p', 16)
            ->whereNull('deleted_at')
            ->get(['id', 'contrato_id', 'razon_social_id', 'n_plano', 'mes_plano', 'anio_plano', 'paga_mes_actual']);

        if ($retiros->count() !== count($planoIds)) {
            abort(422, 'Algunos de los retiros ya no existen o no son correcciones de este aliado.');
        }

        $llaves = $retiros->map(fn ($p) => "{$p->razon_social_id}|{$p->n_plano}|{$p->mes_plano}|{$p->anio_plano}|" . (int) (bool) $p->paga_mes_actual)->unique();
        if ($llaves->count() > 1) {
            abort(422, 'Los retiros son de planillas distintas: se descarga una corrección por planilla.');
        }

        $primero = $retiros->first();

        try {
            $asociada = CorreccionNovedadesService::planillaAsociada($aliadoId, $retiros);
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }
        $numero    = $asociada['numero'];
        $fechaPago = $asociada['fecha_pago'];

        // El TXT pide el mes de PAGO y de ahí saca el período: quien cotiza el
        // mes en curso paga el mismo mes del plano, el resto el siguiente.
        $mesActual = (bool) $primero->paga_mes_actual;
        $mesPago   = Carbon::create((int) $primero->anio_plano, (int) $primero->mes_plano, 1);
        if (! $mesActual) {
            $mesPago->addMonth();
        }

        try {
            return (new PlanoPilaTxtService())->generar([
                'aliado_id'          => $aliadoId,
                'razon_social_id'    => (int) $primero->razon_social_id,
                'mes'                => $mesPago->month,
                'anio'               => $mesPago->year,
                'n_plano'            => (int) $primero->n_plano,
                'plano_ids'          => $planoIds,
                'codigo_operador'    => $codigoOperador,
                'tipo_planilla'      => 'N',
                'periodo_mes_actual' => $mesActual,
                'planilla_asociada'  => [
                    'numero'     => $numero,
                    'fecha_pago' => Carbon::parse($fechaPago)->toDateString(),
                ],
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            abort(500, 'Error de base de datos al generar el TXT.');
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }
    }

    // ─── 6. Descarga Excel / CSV MiPlanilla con novedades ING+RET ─────────────────
    public function descargarExcel(Request $request)
    {
        $aliadoId = session('aliado_id_activo');

        $razonSocialId = $request->input('razon_social_id');
        $mes           = (int) $request->input('mes',  now()->month);
        $anio          = (int) $request->input('anio', now()->year);
        $nPlano        = (int) $request->input('n_plano', 1);
        $formato       = $request->input('formato', 'xlsx'); // 'xlsx' | 'csv'

        if (!$razonSocialId) {
            abort(400, 'Debe especificar la Razón Social.');
        }

        $rsNombre = 'SIN_RS';
        $rs = RazonSocial::find($razonSocialId);
        if ($rs) {
            $rsNombre = preg_replace('/[^A-Za-z0-9_\-]/', '_', $rs->razon_social);
        }

        $ext = $formato === 'csv' ? 'csv' : 'xlsx';
        $filename = "MiPlanilla_Traslado_{$rsNombre}_{$mes}_{$anio}_P{$nPlano}.{$ext}";

        try {
            $service     = new \App\Services\ExcelMiPlanillaService();
            $spreadsheet = $service->generar([
                'aliado_id'       => $aliadoId,
                'razon_social_id' => $razonSocialId,
                'mes'             => $mes,
                'anio'            => $anio,
                'n_plano'         => $nPlano,
            ]);

            if ($formato === 'csv') {
                return $service->respuestaCsv($spreadsheet, $filename);
            }
            return $service->respuesta($spreadsheet, $filename);
        } catch (\Illuminate\Database\QueryException $e) {
            abort(500, 'Error de base de datos al generar la planilla.');
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        } catch (\Exception $e) {
            abort(500, 'Error al generar la planilla: ' . $e->getMessage());
        }
    }

    // ─── API: Lista de n_plano disponibles de una RS ──────────────────────────
    public function apiNPlanosRs(int $id): JsonResponse
    {
        $aliadoId = session('aliado_id_activo');
        $rs = RazonSocial::where('aliado_id', $aliadoId)->find($id);
        if (!$rs) {
            return response()->json(['ok' => false, 'mensaje' => 'No encontrada.'], 404);
        }

        // Obtener los n_plano distintos que existen en planos activos de la RS
        $nPlanos = DB::table('planos')
            ->where('razon_social_id', $id)
            ->where('aliado_id', $aliadoId)
            ->whereNull('deleted_at')
            ->where('n_plano', '>', 0)
            ->distinct()
            ->orderBy('n_plano')
            ->pluck('n_plano');

        return response()->json([
            'ok'              => true,
            'n_plano_actual'  => $rs->n_plano,
            'n_planos'        => $nPlanos,
            'razon_social'    => $rs->razon_social,
        ]);
    }
}
