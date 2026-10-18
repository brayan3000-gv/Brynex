<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BancoCuenta;
use App\Models\DataicoConfiguracion;
use App\Services\Dataico\Adquiriente;
use App\Services\TrazaArchivoService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Módulo de Facturación Electrónica (Dataico)
 *
 * Permite al admin/superadmin:
 *   - Ver todas las facturas pagadas agrupadas por numero_factura
 *   - Filtrar por rango de fecha_pago, banco_cuenta, mes/año, tipo y estado FE
 *   - Marcar/desmarcar grupos como "ya facturado electrónicamente"
 *   - Exportar el Excel en formato Dataico (importacion-facturas-multiples)
 */
class FacturacionElectronicaController extends Controller
{
    /** Cabeceras del Excel Dataico — orden exacto requerido por la plataforma */
    private const DATAICO_HEADERS = [
        'FECHA_EXPEDICION', 'FECHA_VENCIMIENTO', 'NUMERO', 'MEDIO_DE_PAGO',
        'TIPO_MEDIO_DE_PAGO', 'ORDEN_DE_COMPRA', 'MONEDA', 'CLIENTE_PAIS',
        'CLIENTE_NOMBRE', 'OBSERVACIONES', 'CLIENTE_PRIMER_NOMBRE', 'CLIENTE_APELLIDO',
        'CLIENTE_NOMBRE_COMERCIAL', 'CLIENTE_IDENTIFICATION', 'CLIENTE_TIPO_IDENTIFICATION',
        'CLIENTE_DIRECCION', 'CLIENTE_TELEFONO', 'CLIENTE_CIUDAD', 'CLIENTE_DEPARTAMENTO',
        'CLIENTE_TIPO', 'CLIENTE_CORREO', 'RESPONSABILIDAD_IVA', 'CLIENTE_REGIMEN',
        'ITEM_DESCRIPCION', 'ITEM_REFERENCIA', 'ITEM_CANTIDAD', 'ITEM_PRECIO',
        'ITEM_DESCUENTO%', 'IVA%', 'IMP_CONSUMO_LICOR', 'IMP_CONSUMO%',
        'RET_IVA%', 'RET_ICA%', 'RET_FUENTE%', 'DESCUENTO_GLOBAL',
        'DESCUENTO_GLOBAL_DESCRIPCION', 'DESCUENTO_GLOBAL', 'CARGO_GLOBAL_DESCRIPCION',
        'CARGO_GLOBAL',
    ];

    // ─── Vista principal ─────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $aliadoId = session('aliado_id_activo');
        $bancos = BancoCuenta::paraFacturacion($aliadoId);

        // Filtros recibidos
        $mesPago = $request->has('mes_pago') ? $request->input('mes_pago') : now()->month;
        $anioPago = $request->has('anio_pago') ? $request->input('anio_pago') : now()->year;
        $bancoCuentaId = $request->input('banco_cuenta_id');
        $estadoFe = $request->input('estado_fe', 'todas');   // todas | pendiente | parcial | marcada
        $tipoFact = $request->input('tipo');                  // planilla | afiliacion | otro_ingreso | null=todas
        $mesFiltro = $request->input('mes');
        $anioFiltro = $request->input('anio');

        // Obtener todos los resultados agrupados
        $todos = $this->queryAgrupada(
            $aliadoId, $mesPago, $anioPago, $bancoCuentaId,
            $estadoFe, $tipoFact, $mesFiltro, $anioFiltro
        )->get();

        // Totales en PHP (evita subquery anidada con SQL Server)
        $totales = (object) [
            'count_grupos' => $todos->count(),
            'sum_admon' => $todos->sum('total_admon'),
            'sum_ss' => $todos->sum('total_ss'),
            'sum_iva' => $todos->sum('total_iva'),
            'sum_consignado' => $todos->sum('total_consignado'),
            'sum_efectivo' => $todos->sum('total_efectivo'),
            'sum_total' => $todos->sum('gran_total'),
        ];

        // Paginación manual (evita conflictos SQL Server + GROUP BY + HAVING + paginate)
        $perPage = 200;
        $page = (int) $request->input('page', 1);
        $facturas = new LengthAwarePaginator(
            $todos->forPage($page, $perPage)->values(),
            $todos->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        // Cargar nombres para la página actual (evitar N+1)
        $itemsPag = collect($facturas->items());
        $cedulas = $itemsPag->pluck('cedula_muestra')->filter()->unique()->values()->all();
        $empresaIds = $itemsPag->pluck('empresa_id')->filter()->unique()->values()->all();

        $nombresClientes = [];
        if (! empty($cedulas)) {
            $nombresClientes = DB::table('clientes')
                ->where('aliado_id', $aliadoId)
                ->whereIn('cedula', $cedulas)
                ->select('cedula', 'primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido')
                ->get()
                ->keyBy('cedula')
                ->map(fn ($c) => trim("{$c->primer_nombre} {$c->segundo_nombre} {$c->primer_apellido} {$c->segundo_apellido}"))
                ->all();
        }

        $nombresEmpresas = [];
        if (! empty($empresaIds)) {
            $nombresEmpresas = DB::table('empresas')
                ->whereIn('id', $empresaIds)
                ->select('id', 'empresa')
                ->pluck('empresa', 'id')
                ->all();
        }

        // Estado real en Dataico de la página que se está viendo: `fe_marcada`
        // solo dice «sí o no», no con qué número quedó ni si la DIAN la
        // rechazó. Se carga de una consulta para las filas visibles.
        $feEstados = \App\Models\DataicoEnvio::where('aliado_id', $aliadoId)
            ->whereIn('numero_factura', $itemsPag->pluck('numero_factura')->filter()->unique()->all() ?: [0])
            ->get(['numero_factura', 'estado', 'dataico_numero', 'cufe', 'error_mensaje'])
            ->keyBy('numero_factura')
            ->all();

        return view('admin.facturacion.electronica', compact(
            'facturas', 'bancos', 'totales',
            'mesPago', 'anioPago', 'bancoCuentaId', 'estadoFe',
            'tipoFact', 'mesFiltro', 'anioFiltro',
            'nombresClientes', 'nombresEmpresas', 'feEstados'
        ));
    }

    // ─── Marcar / Desmarcar como FE ─────────────────────────────────────────

    public function marcar(Request $request)
    {
        $aliadoId = session('aliado_id_activo');

        $validated = $request->validate([
            'numeros_factura' => 'required|array|min:1',
            'numeros_factura.*' => 'integer|min:1',
            'accion' => 'required|in:marcar,desmarcar',
        ]);

        $update = $validated['accion'] === 'marcar'
            ? ['fe_marcada' => 1, 'fe_marcada_at' => now(), 'fe_marcada_por' => Auth::id()]
            : ['fe_marcada' => 0, 'fe_marcada_at' => null,  'fe_marcada_por' => null];

        $afectadas = DB::table('facturas')
            ->where('aliado_id', $aliadoId)
            ->whereIn('numero_factura', $validated['numeros_factura'])
            ->whereNull('deleted_at')
            ->update($update);

        return response()->json([
            'ok' => true,
            'accion' => $validated['accion'],
            'grupos' => count($validated['numeros_factura']),
            'facturas' => $afectadas,
        ]);
    }

    // ─── Exportar Excel Dataico ──────────────────────────────────────────────

    public function exportar(Request $request)
    {
        $aliadoId = session('aliado_id_activo');

        $mesPago = $request->has('mes_pago') ? $request->input('mes_pago') : now()->month;
        $anioPago = $request->has('anio_pago') ? $request->input('anio_pago') : now()->year;
        $bancoCuentaId = $request->input('banco_cuenta_id');
        $estadoFe = $request->input('estado_fe', 'todas');
        $tipoFact = $request->input('tipo');
        $mesFiltro = $request->input('mes');
        $anioFiltro = $request->input('anio');
        $numerosSelec = array_filter(
            array_map('intval', (array) $request->input('numeros_factura', [])),
            fn ($v) => $v > 0
        );

        $query = $this->queryAgrupada(
            $aliadoId, $mesPago, $anioPago, $bancoCuentaId,
            $estadoFe, $tipoFact, $mesFiltro, $anioFiltro
        );

        if (! empty($numerosSelec)) {
            $query->whereIn('f.numero_factura', $numerosSelec);
        }

        $grupos = $query->get();

        if ($grupos->isEmpty()) {
            return back()->with('error', 'No hay facturas para exportar con los filtros seleccionados.');
        }

        // ── Cargar info de clientes y empresas en batch (evitar N+1) ──────────
        $cedulas = $grupos->pluck('cedula_muestra')->filter()->unique()->values()->all();
        $empresaIds = $grupos->pluck('empresa_id')->filter()->unique()->values()->all();

        $clientesMap = [];
        if (! empty($cedulas)) {
            $clientesMap = DB::table('clientes as cl')
                ->where('cl.aliado_id', $aliadoId)
                ->whereIn('cl.cedula', $cedulas)
                ->leftJoin('ciudades as ci', 'ci.id', '=', 'cl.municipio_id')
                ->leftJoin('departamentos as de', 'de.id', '=', 'cl.departamento_id')
                ->select([
                    'cl.cedula', 'cl.tipo_doc',
                    'cl.primer_nombre', 'cl.segundo_nombre',
                    'cl.primer_apellido', 'cl.segundo_apellido',
                    'cl.direccion_vivienda', 'cl.celular', 'cl.telefono', 'cl.correo',
                    DB::raw('ci.nombre as ciudad_nombre'),
                    DB::raw('de.nombre as departamento_nombre'),
                ])
                ->get()
                ->keyBy('cedula')
                ->all();
        }

        $empresasMap = [];
        if (! empty($empresaIds)) {
            $empresasMap = DB::table('empresas')
                ->whereIn('id', $empresaIds)
                ->select(['id', 'empresa', 'nombre_legal', 'factura_electronica', 'nit', 'tipo_documento', 'contacto', 'telefono', 'celular', 'correo', 'direccion'])
                ->get()
                ->keyBy('id')
                ->all();
        }

        // ── Construir el Excel ────────────────────────────────────────────────
        $spreadsheet = new Spreadsheet;
        // Traza invisible de quién exportó (propiedades del documento).
        app(TrazaArchivoService::class)->marcarExcel($spreadsheet);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('importacion-facturas-multiples-');

        // Fila 1: encabezados
        foreach (self::DATAICO_HEADERS as $idx => $header) {
            $col = Coordinate::stringFromColumnIndex($idx + 1);
            $sheet->setCellValue($col.'1', $header);
        }

        // Estilo de la fila de encabezados
        $lastColLetter = Coordinate::stringFromColumnIndex(count(self::DATAICO_HEADERS));
        $sheet->getStyle("A1:{$lastColLetter}1")->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 10,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1E3A5F'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
            ],
        ]);

        // Datos
        $row = 2;
        foreach ($grupos as $grupo) {
            $clienteInfo = $this->resolverClienteInfo($grupo, $clientesMap, $empresasMap);
            $items = $this->buildItems($grupo);
            $fechaStr = $grupo->fecha_pago
                ? Carbon::parse($grupo->fecha_pago)->format('d/m/Y')
                : '';
            $medioPago = ((int) ($grupo->total_consignado ?? 0)) > 0
                ? 'BANK_TRANSFER'
                : 'CASH';
            // Préstamo que sigue debiendo: a crédito, con vencimiento a
            // `dataico.dias_credito` días (igual que por el API).
            $aCredito = (int) ($grupo->a_credito ?? 0) === 1;
            $vencStr  = $aCredito && $grupo->fecha_pago
                ? Carbon::parse($grupo->fecha_pago)->addDays((int) config('dataico.dias_credito', 30))->format('d/m/Y')
                : $fechaStr;

            foreach ($items as $item) {
                $rowData = [
                    $fechaStr,                              // FECHA_EXPEDICION
                    $vencStr,                               // FECHA_VENCIMIENTO
                    '',                                     // NUMERO (vacío — el contador lo asigna)
                    $medioPago,                             // MEDIO_DE_PAGO
                    $aCredito ? 'CREDITO' : 'DEBITO',       // TIPO_MEDIO_DE_PAGO
                    '',                                     // ORDEN_DE_COMPRA
                    'COP',                                  // MONEDA
                    'CO',                                   // CLIENTE_PAIS
                    $clienteInfo['nombre_completo'],        // CLIENTE_NOMBRE
                    '',                                     // OBSERVACIONES
                    $clienteInfo['primer_nombre'],          // CLIENTE_PRIMER_NOMBRE
                    $clienteInfo['apellido'],               // CLIENTE_APELLIDO
                    $clienteInfo['nombre_comercial'],       // CLIENTE_NOMBRE_COMERCIAL
                    $clienteInfo['identification'],         // CLIENTE_IDENTIFICATION
                    $clienteInfo['tipo_identification'],    // CLIENTE_TIPO_IDENTIFICATION
                    $clienteInfo['direccion'],              // CLIENTE_DIRECCION
                    $clienteInfo['telefono'],               // CLIENTE_TELEFONO
                    $clienteInfo['ciudad'],                 // CLIENTE_CIUDAD
                    $clienteInfo['departamento'],           // CLIENTE_DEPARTAMENTO
                    $clienteInfo['tipo_cliente'],           // CLIENTE_TIPO
                    $clienteInfo['correo'],                 // CLIENTE_CORREO
                    'NO_RESPONSABLE_DE_IVA',                // RESPONSABILIDAD_IVA
                    'ORDINARIO',                            // CLIENTE_REGIMEN
                    $item['descripcion'],                   // ITEM_DESCRIPCION
                    $item['referencia'],                    // ITEM_REFERENCIA
                    1,                                      // ITEM_CANTIDAD
                    $item['precio'],                        // ITEM_PRECIO
                    '',                                     // ITEM_DESCUENTO%
                    '',                                     // IVA%
                    '',                                     // IMP_CONSUMO_LICOR
                    '',                                     // IMP_CONSUMO%
                    '',                                     // RET_IVA%
                    '',                                     // RET_ICA%
                    '',                                     // RET_FUENTE%
                    '',                                     // DESCUENTO_GLOBAL
                    '',                                     // DESCUENTO_GLOBAL_DESCRIPCION
                    '',                                     // DESCUENTO_GLOBAL (2do)
                    '',                                     // CARGO_GLOBAL_DESCRIPCION
                    '',                                     // CARGO_GLOBAL
                ];

                foreach ($rowData as $colIdx => $value) {
                    $colLetter = Coordinate::stringFromColumnIndex($colIdx + 1);
                    $sheet->setCellValue($colLetter.$row, $value);
                }

                $row++;
            }
        }

        // Auto-width en todas las columnas
        foreach (range(1, count(self::DATAICO_HEADERS)) as $colIdx) {
            $sheet->getColumnDimension(
                Coordinate::stringFromColumnIndex($colIdx)
            )->setAutoSize(true);
        }

        $filename = 'dataico_facturas_'.now()->format('Ymd_His').'.xlsx';
        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(
            function () use ($writer) {
                $writer->save('php://output');
            },
            $filename,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'max-age=0',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ]
        );
    }

    // ─── Query agrupada (base compartida por index y exportar) ───────────────

    private function queryAgrupada(
        int $aliadoId,
        ?string $mesPago,
        ?string $anioPago,
        ?string $bancoCuentaId,
        string $estadoFe,
        ?string $tipoFact,
        ?string $mes,
        ?string $anio
    ) {
        $q = DB::table('facturas as f')
            ->where('f.aliado_id', $aliadoId)
            ->whereNull('f.deleted_at')
            ->whereIn('f.estado', ['pagada', 'abono', 'prestamo'])
            ->groupBy('f.numero_factura')
            ->orderByRaw('MIN(f.fecha_pago) DESC, f.numero_factura DESC')
            ->select([
                'f.numero_factura',
                DB::raw('MIN(f.id)   AS id'),
                DB::raw('MIN(f.mes)  AS mes'),
                DB::raw('MIN(f.anio) AS anio'),
                // fecha_pago como cadena para evitar problemas de serialización
                DB::raw('CONVERT(varchar(10), MIN(f.fecha_pago), 23) AS fecha_pago'),
                DB::raw('COUNT(*) AS num_clientes'),
                DB::raw('MIN(f.empresa_id) AS empresa_id'),
                DB::raw('MIN(f.tipo)       AS tipo'),
                DB::raw('MIN(f.cedula)     AS cedula_muestra'),
                // Admon = campo admon + campo afiliacion
                DB::raw('SUM(ISNULL(CAST(f.admon       AS BIGINT), 0)
                           + ISNULL(CAST(f.afiliacion   AS BIGINT), 0)) AS total_admon'),
                // SS = v_eps + v_arl + v_afp + v_caja + mora
                DB::raw('SUM(ISNULL(CAST(f.v_eps  AS BIGINT), 0)
                           + ISNULL(CAST(f.v_arl  AS BIGINT), 0)
                           + ISNULL(CAST(f.v_afp  AS BIGINT), 0)
                           + ISNULL(CAST(f.v_caja AS BIGINT), 0)
                           + ISNULL(CAST(f.mora   AS BIGINT), 0)) AS total_ss'),
                DB::raw('SUM(ISNULL(CAST(f.iva            AS BIGINT), 0)) AS total_iva'),
                DB::raw('SUM(ISNULL(CAST(f.valor_consignado AS BIGINT), 0)) AS total_consignado'),
                DB::raw('SUM(ISNULL(CAST(f.valor_efectivo   AS BIGINT), 0)) AS total_efectivo'),
                DB::raw('SUM(ISNULL(CAST(f.total            AS BIGINT), 0)) AS gran_total'),
                // FE status del grupo
                DB::raw('MIN(CAST(f.fe_marcada AS INT)) AS fe_min'),
                DB::raw('MAX(CAST(f.fe_marcada AS INT)) AS fe_max'),
                DB::raw('MAX(f.fe_marcada_at)            AS fe_marcada_at'),
                DB::raw("MAX(CASE WHEN f.estado = 'prestamo' THEN 1 ELSE 0 END) AS a_credito"),
            ]);

        // ── Filtros ─────────────────────────────────────────────────────────
        if ($mesPago) {
            $q->whereMonth('f.fecha_pago', (int) $mesPago);
        }
        if ($anioPago) {
            $q->whereYear('f.fecha_pago', (int) $anioPago);
        }
        if ($mes) {
            $q->where('f.mes', (int) $mes);
        }
        if ($anio) {
            $q->where('f.anio', (int) $anio);
        }
        if ($tipoFact) {
            $q->where('f.tipo', $tipoFact);
        }

        // Filtro por cuenta bancaria (al menos una consignación en el mismo grupo de factura)
        if ($bancoCuentaId) {
            $q->whereExists(function ($sub) use ($bancoCuentaId) {
                $sub->select(DB::raw(1))
                    ->from('consignaciones as cs')
                    ->join('facturas as sub_f', 'sub_f.id', '=', 'cs.factura_id')
                    ->whereColumn('sub_f.numero_factura', 'f.numero_factura')
                    ->where('cs.banco_cuenta_id', (int) $bancoCuentaId);
            });
        }

        // ── Guardas de seguridad ────────────────────────────────────────────
        // Las mismas que aplica la emisión por API, para que los dos caminos
        // exporten exactamente el mismo universo.
        //
        // 1. Una factura de $0 de administración no tiene qué facturar.
        // 2. Un grupo tiene que tener UN solo adquiriente: la factura de un
        //    lote empresarial sale a nombre de la empresa por la suma de todos
        //    sus afiliados, así que si el `numero_factura` mezcla filas de
        //    empresas distintas se le cargaría a una plata de otra.
        $q->havingRaw('SUM(ISNULL(CAST(f.admon AS BIGINT), 0)
                         + ISNULL(CAST(f.afiliacion AS BIGINT), 0)) > 0')
            ->havingRaw("COUNT(DISTINCT ISNULL(CAST(f.empresa_id AS VARCHAR(20)), 'X')) = 1");

        // Filtro por estado de FE (HAVING sobre columnas calculadas)
        if ($estadoFe === 'pendiente') {
            // Ninguna marcada en el grupo
            $q->havingRaw('MAX(CAST(f.fe_marcada AS INT)) = 0');
        } elseif ($estadoFe === 'parcial') {
            // Al menos una marcada pero no todas
            $q->havingRaw('MAX(CAST(f.fe_marcada AS INT)) = 1')
                ->havingRaw('MIN(CAST(f.fe_marcada AS INT)) = 0');
        } elseif ($estadoFe === 'marcada') {
            // Todas marcadas
            $q->havingRaw('MIN(CAST(f.fe_marcada AS INT)) = 1');
        }

        return $q;
    }

    // ─── Helpers privados ────────────────────────────────────────────────────

    /**
     * Información del adquiriente para las columnas del Excel.
     *
     * La clasificación no vive aquí: la hace [[Adquiriente]], compartida con la
     * emisión por API. Este método solo traduce esa forma canónica a los
     * nombres de columna que espera la plantilla de Dataico.
     *
     * Ojo con `empresas`: no son todas personas jurídicas. Antes este método
     * mandaba PERSONA_JURIDICA + NIT para cualquier fila con `empresa_id`,
     * incluso cuando el campo `nit` traía una cédula o venía vacío — y con el
     * documento en blanco la DIAN rechaza la factura. Por eso nunca subieron.
     */
    private function resolverClienteInfo(object $grupo, array $clientesMap, array $empresasMap): array
    {
        $aliadoId = (int) session('aliado_id_activo');
        $cfg = DataicoConfiguracion::where('aliado_id', $aliadoId)->first();
        $consumidorFinal = (bool) ($cfg->consumidor_final ?? false);

        $cl = $clientesMap[$grupo->cedula_muestra] ?? null;
        $empresa = $grupo->empresa_id ? ($empresasMap[$grupo->empresa_id] ?? null) : null;

        // Empresa sin documento y un solo afiliado: la empresa no aporta nada
        // (a veces es un comodín como «Individual») y el cliente sí tiene
        // cédula. Se le factura a él antes que a consumidor final.
        if ($empresa && ! Adquiriente::empresaTieneDocumento($empresa)
            && (int) ($grupo->num_clientes ?? 0) === 1 && $cl) {
            $empresa = null;
        }

        if ($empresa) {
            $a = Adquiriente::deEmpresa($empresa, $consumidorFinal);
        } elseif ($cl) {
            $a = Adquiriente::deCliente($cl);
        } else {
            $a = Adquiriente::sinDocumento('', '', $consumidorFinal);
            $a['identificacion'] = $a['identificacion'] ?: (string) ($grupo->cedula_muestra ?? '');
        }

        return [
            'nombre_completo' => $a['nombre_completo'],
            'primer_nombre' => $a['primer_nombre'],
            'apellido' => $a['apellido'],
            'nombre_comercial' => $a['nombre_completo'],
            'identification' => $a['identificacion'],
            'tipo_identification' => $a['tipo_documento'],
            'direccion' => $a['direccion'],
            'telefono' => $a['telefono'],
            'ciudad' => $a['ciudad'],
            'departamento' => $a['departamento'],
            'tipo_cliente' => $a['tipo_persona'],
            // Relleno de correo: es lo que llevan las 1.184 facturas que BRYGAR
            // ya tiene ante la DIAN, y la plantilla no acepta la columna vacía.
            'correo' => $a['correo'] !== '' ? $a['correo'] : 'notiene@gmail.com',
        ];
    }

    /**
     * Construye los ítems de la factura para el Excel.
     *
     * Un solo ítem: administración + afiliación. La seguridad social, la mora,
     * el seguro y la mensajería NO se facturan — son plata que el aliado
     * recauda y traslada, no ingreso propio.
     *
     * El IVA tampoco: BRYGAR es no responsable, y en las 270 facturas
     * pendientes de julio y agosto no hay una sola fila con `iva` distinto de
     * cero. La línea existía y nunca se usó.
     *
     * La descripción lleva el **mes cobrado** (`mes`/`anio` de la factura), no
     * el mes en que entró el pago. Son distintos en 14 de los grupos
     * pendientes: hay facturas pagadas en julio que cobran agosto. El período
     * es lo que describe el servicio, y es lo que BRYGAR venía poniendo en las
     * notas de las facturas ya emitidas.
     */
    private function buildItems(object $grupo): array
    {
        $tipo = strtolower($grupo->tipo ?? '');

        if ($tipo === 'afiliacion') {
            $descripcion = '0006-Afiliación al sistema general de seguridad social';
            $referencia = 'SKU 0006';
        } else {
            // Planilla, otro ingreso o por defecto.
            $descripcion = '0001-Servicio de administración y gestión de afiliaciones a seguridad social';
            $referencia = 'SKU 0001';
        }

        if ($periodo = $this->periodo($grupo)) {
            $descripcion .= " - {$periodo}";
        }

        return [[
            'descripcion' => $descripcion,
            'referencia' => $referencia,
            'precio' => (int) ($grupo->total_admon ?? 0),
        ]];
    }

    /** "JULIO 2026" a partir del mes cobrado del grupo. */
    private function periodo(object $grupo): ?string
    {
        $mes = (int) ($grupo->mes ?? 0);
        $anio = (int) ($grupo->anio ?? 0);

        if ($mes < 1 || $mes > 12 || $anio < 2000) {
            return null;
        }

        return mb_strtoupper(
            Carbon::create($anio, $mes, 1)->locale('es')->isoFormat('MMMM')
        )." {$anio}";
    }
}
