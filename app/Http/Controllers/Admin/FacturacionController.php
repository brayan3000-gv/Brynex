<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Abono;
use App\Models\BancoCuenta;
use App\Models\Bitacora;
use App\Models\Contrato;
use App\Models\Empresa;
use App\Models\Factura;
use App\Models\Plano;
use App\Services\Dataico\NotaCreditoService;
use App\Services\MoraClienteService;
use App\Services\TrazaArchivoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class FacturacionController extends Controller
{
    /**
     * Cuánto puede faltar en una factura marcada PAGADA antes de rechazarla.
     * Cubre al cliente que redondea hacia abajo ($563.000 sobre $563.047); por
     * encima de esto es plata que alguien tiene que cobrar, y la factura debe
     * guardarse como PRÉSTAMO o con el pago completo.
     *
     * En cero a propósito (10-sep-2026): antes toleraba $2.000, y ese hueco se
     * tragaba justo lo que más se cuela — una mora, un redondeo — dejándolo como
     * cartera silenciosa (saldo_proximo negativo) que nadie cobra y reaparece el
     * mes siguiente. Lo que falte, así sea $100, se registra como préstamo (y el
     * recibo igual sale con sello PAGO si es menor de lo que dice
     * Factura::PRESTAMO_SELLO_PAGO) o se ajusta el cobro.
     */
    private const TOLERANCIA_PAGO = 0;

    // ─── Listado de empresas ─────────────────────────────────────────
    public function index(Request $request)
    {
        $aliadoId = session('aliado_id_activo');

        // Subquery corroborada como objeto Eloquent/Builder válido para selectSub
        $subContratos = DB::table('contratos as c')
            ->join('clientes as cl', 'cl.cedula', '=', 'c.cedula')
            ->where('c.aliado_id', $aliadoId)
            ->whereIn('c.estado', ['vigente', 'activo'])
            ->whereColumn('cl.cod_empresa', 'empresas.id')
            ->selectRaw('COUNT(DISTINCT c.id)');

        // Trámites que las empresas abrieron desde su portal: los que siguen
        // abiertos y, de esos, los que nadie ha mirado todavía.
        $subPortalAbiertas = DB::table('tareas')
            ->whereColumn('tareas.empresa_id', 'empresas.id')
            ->where('tareas.aliado_id', $aliadoId)
            ->where('tareas.estado', '!=', \App\Models\Tarea::ESTADO_CERRADA)
            ->whereNull('tareas.deleted_at')
            ->selectRaw('COUNT(*)');
        $subPortalNuevas = DB::table('empresa_solicitudes')
            ->whereColumn('empresa_solicitudes.empresa_id', 'empresas.id')
            ->where('empresa_solicitudes.estado', 'pendiente')
            ->whereNull('empresa_solicitudes.vista_at')
            ->selectRaw('COUNT(*)');

        $empresas = Empresa::where('aliado_id', $aliadoId)
            ->where('id', '>', 1)
            ->select(['id', 'empresa', 'nit', 'contacto', 'telefono', 'celular', 'iva'])
            ->selectSub($subContratos, 'contratos_activos_count')
            ->selectSub($subPortalAbiertas, 'portal_abiertas')
            ->selectSub($subPortalNuevas, 'portal_nuevas')
            ->get()
            ->sortBy([
                // Las que tienen contratos activos van primero
                fn ($a, $b) => ($b->contratos_activos_count > 0) <=> ($a->contratos_activos_count > 0),
                // Dentro de cada grupo, A-Z
                fn ($a, $b) => strcmp($a->empresa, $b->empresa),
            ])
            ->values();

        return view('admin.facturacion.index', compact('empresas'));
    }

    // ─── Vista empresa (tabla de trabajadores) ───────────────────────
    public function empresa(Request $request, int $empresaId)
    {
        $aliadoId = session('aliado_id_activo');
        $mes = (int) $request->get('mes', now()->month);
        $anio = (int) $request->get('anio', now()->year);

        $datos = $this->getDatosEmpresaPeriodo($empresaId, $mes, $anio, $aliadoId);

        return view('admin.facturacion.empresa', array_merge($datos, [
            'mes' => $mes,
            'anio' => $anio,
            // Solo el número: la lista se pide al abrir el modal. En una empresa
            // con años de historia son cientos de filas que casi nadie mira.
            'retiradosPreviosTotal' => $this->contarRetiradosPrevios(
                $empresaId, $aliadoId, $datos['contratos']->pluck('cedula')
            ),
        ]));
    }

    /**
     * La lista de retirados para el modal, pedida al abrirlo.
     *
     * Recalcula acá quiénes están a la vista en el período —solo las cédulas,
     * que es una consulta— en vez de recibirlas del navegador: lo que llega
     * del cliente no manda sobre qué se le muestra.
     */
    public function retiradosEmpresa(Request $request, int $empresaId)
    {
        $aliadoId = session('aliado_id_activo');
        $mes = (int) $request->get('mes', now()->month);
        $anio = (int) $request->get('anio', now()->year);

        // findOrFail scopeado: sin el where por aliado, el id de la URL abriría
        // la empresa de cualquier otro.
        Empresa::where('aliado_id', $aliadoId)->findOrFail($empresaId);

        $cedulasEmpresa = DB::table('clientes')
            ->where('aliado_id', $aliadoId)
            ->where('cod_empresa', $empresaId)
            ->pluck('cedula');

        $cedulasVisibles = $this->contratosDelPeriodo($aliadoId, $cedulasEmpresa, $mes, $anio)
            ->pluck('cedula');

        $retirados = $this->retiradosPreviosEmpresa($empresaId, $aliadoId, $cedulasVisibles)
            ->map(fn ($r) => [
                'tipo_doc' => $r->tipo_doc,
                'cedula' => $r->cedula,
                'nombre' => trim(($r->primer_nombre ?? '').' '.($r->primer_apellido ?? '')) ?: '—',
                'cliente_url' => $r->cliente_id ? route('admin.clientes.edit', $r->cliente_id) : null,
                'razon_social' => $r->razon_social ?: '—',
                'ingreso' => $r->fecha_ingreso ? \Illuminate\Support\Carbon::parse($r->fecha_ingreso)->format('d/m/Y') : '—',
                'retiro' => $r->fecha_retiro ? \Illuminate\Support\Carbon::parse($r->fecha_retiro)->format('d/m/Y') : '—',
            ])
            ->values();

        return response()->json(['ok' => true, 'retirados' => $retirados]);
    }

    /**
     * Cuántas personas trae esa lista, sin traérsela.
     */
    private function contarRetiradosPrevios(int $empresaId, int $aliadoId, $cedulasVisibles): int
    {
        return (int) $this->queryRetiradosPrevios($empresaId, $aliadoId, $cedulasVisibles)
            ->distinct()
            ->count('ct.cedula');
    }

    /**
     * Ver EmpresaPeriodoService::retiradosPrevios(): la comparte con el portal.
     */
    private function retiradosPreviosEmpresa(int $empresaId, int $aliadoId, $cedulasVisibles)
    {
        return app(\App\Services\EmpresaPeriodoService::class)
            ->retiradosPrevios($empresaId, $aliadoId, $cedulasVisibles);
    }

    private function queryRetiradosPrevios(int $empresaId, int $aliadoId, $cedulasVisibles)
    {
        return app(\App\Services\EmpresaPeriodoService::class)
            ->queryRetiradosPrevios($empresaId, $aliadoId, $cedulasVisibles);
    }

    /**
     * Ver EmpresaPeriodoService::contratosDelPeriodo(): vive allá para que el
     * portal de empresas use la misma regla.
     */
    private function contratosDelPeriodo(int $aliadoId, $cedulasEmpresa, int $mes, int $anio)
    {
        return app(\App\Services\EmpresaPeriodoService::class)
            ->contratosDelPeriodo($aliadoId, $cedulasEmpresa, $mes, $anio);
    }

    /**
     * Ver EmpresaPeriodoService::datosPeriodo(): lo comparten esta pantalla,
     * el Excel y el portal de empresas.
     */
    private function getDatosEmpresaPeriodo(int $empresaId, int $mes, int $anio, int $aliadoId): array
    {
        return app(\App\Services\EmpresaPeriodoService::class)
            ->datosPeriodo($empresaId, $mes, $anio, $aliadoId);
    }

    /**
     * Exporta la planilla de facturación de una empresa a formato XLSX.
     */
    public function exportarEmpresaExcel(Request $request, int $empresaId)
    {
        $aliadoId = session('aliado_id_activo');
        $mes = (int) $request->get('mes', now()->month);
        $anio = (int) $request->get('anio', now()->year);

        $datos = $this->getDatosEmpresaPeriodo($empresaId, $mes, $anio, $aliadoId);

        $empresa = $datos['empresa'];
        $contratos = $datos['contratos'];
        $facturasExistentes = $datos['facturasExistentes'];
        $moraPorContrato = $datos['moraPorContrato'];
        $saldoAnticipoPorContrato = $datos['saldoAnticipoPorContrato'];
        $meses = $datos['meses'];

        $spreadsheet = new Spreadsheet;
        // Traza invisible de quién exportó (propiedades del documento).
        app(TrazaArchivoService::class)->marcarExcel($spreadsheet);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Facturación');

        // Título principal
        $sheet->setCellValue('A1', 'PLANILLA DE COBRO - '.$empresa->empresa);
        $sheet->mergeCells('A1:S1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $sheet->setCellValue('A2', 'Periodo: '.$meses[$mes].' de '.$anio);
        $sheet->mergeCells('A2:S2');
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(11);

        // Cabeceras
        $headers = [
            'TIPO', 'CÉDULA', 'NOMBRE', 'RAZÓN SOCIAL', 'F. INGRESO', 'F. RETIRO', 'DÍAS',
            'EPS', 'ARL', 'CAJA', 'PENSIÓN', 'ADMON', 'ADMON ASESOR', 'AFILIACIÓN',
            'TOTAL', 'MORA', 'ANTICIPO', 'ESTADO', 'NP',
        ];

        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col.'4', $header);
            $col++;
        }

        // Estilo cabecera
        $headerStyle = [
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 10,
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1E3A8A'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'CCCCCC'],
                ],
            ],
        ];
        $sheet->getStyle('A4:S4')->applyFromArray($headerStyle);
        $sheet->getRowDimension(4)->setRowHeight(25);

        $r100 = fn ($val) => (int) round($val ?? 0);
        $row = 5;

        foreach ($contratos as $c) {
            $fact = $c->factura_exist;
            $factRetiroPreview = (! $fact && ($c->tiene_retiro_facturable ?? false)) ? ($c->factura_retiro_0 ?? null) : null;
            $esRetirado = $c->estado === 'retirado';

            $nombre = $c->cliente?->nombre_completo ?: '—';

            $tipoMod = $c->tipoModalidad?->tipo_modalidad ?? '—';
            $rs = $c->razonSocial?->razon_social ?? '—';

            $dias = $fact
                ? (int) $fact->dias_cotizados
                : ($factRetiroPreview
                    ? (int) $factRetiroPreview->dias_cotizados
                    : ($c->dias_cotizar ?? 30));

            $esIndActPrimerMes = $c->es_ind_act_primer_mes ?? false;
            $esArlModalidad = (int) ($c->tipo_modalidad_id) === 15;
            // Solo seguro: no cotiza, no paga administración y no genera planilla.
            $esSoloSeguro = (int) ($c->tipo_modalidad_id) === \App\Models\Contrato::MODALIDAD_SEGUROS;

            $esAfil = false;
            if ($esSoloSeguro) {
                $esAfil = false;   // el seguro se cobra igual todos los meses, no hay afiliación
            } elseif ($esArlModalidad) {
                $esAfil = true;
            } elseif ($c->fecha_ingreso) {
                $fIngC = $c->fecha_ingreso;
                if ((int) $fIngC->month === $mes && (int) $fIngC->year === $anio) {
                    if (! $esIndActPrimerMes) {
                        $esAfil = true;
                    }
                }
            }
            if ($fact) {
                $esAfil = $fact->tipo === 'afiliacion' && ! ($fact->afiliacion > 0 && $fact->total_ss > 0);
            }

            $cotiz = $c->cotizacion_calc ?? $c->calcularCotizacion($dias);

            $vEps = $fact ? $r100($fact->v_eps) : 0;
            $vArl = $fact ? $r100($fact->v_arl) : 0;
            $vCaja = $fact ? $r100($fact->v_caja) : 0;
            $vPen = $fact ? $r100($fact->v_afp) : 0;
            $vIva = $fact ? $r100($fact->iva) : 0;

            $vAdmonBase = 0;
            $vAdmonAsesor = 0;
            if ($fact) {
                $vAdmonBase = (int) $fact->admon;
                $vAdmonAsesor = (int) $fact->admin_asesor;
            } else {
                if ($esRetirado) {
                    if ($factRetiroPreview) {
                        $vAdmonBase = (int) ($c->administracion ?? 0);
                        $vAdmonAsesor = (int) ($c->admon_asesor ?? 0);
                        $vAdmonBase = (int) round(($vAdmonBase / 30) * $dias);
                        $vAdmonAsesor = (int) round(($vAdmonAsesor / 30) * $dias);
                    } else {
                        $vAdmonBase = 0;
                        $vAdmonAsesor = 0;
                    }
                } elseif ($esSoloSeguro || ($esArlModalidad && ! $esAfil)) {
                    $vAdmonBase = 0;
                    $vAdmonAsesor = 0;
                } elseif ($esAfil) {
                    $vAdmonBase = 0;
                    $vAdmonAsesor = 0;
                } else {
                    $vAdmonBase = (int) ($c->administracion ?? 0);
                    $vAdmonAsesor = (int) ($c->admon_asesor ?? 0);
                }
            }

            $vAfiliacion = ($esAfil || $esIndActPrimerMes) ? (int) ($c->costo_afiliacion ?? 0) : 0;
            if ($fact) {
                $vAfiliacion = (int) $fact->afiliacion;
            }

            if (! $fact) {
                if ($esRetirado && $factRetiroPreview) {
                    $vEps = $r100($factRetiroPreview->v_eps);
                    $vArl = $r100($factRetiroPreview->v_arl);
                    $vPen = $r100($factRetiroPreview->v_afp);
                    $vCaja = $r100($factRetiroPreview->v_caja);
                    // El IVA grava la admon del retiro; la factura temporal la guarda en 0
                    $vIva = \App\Services\IvaService::calcular($vAdmonBase + $vAdmonAsesor, (bool) ($c->tiene_iva ?? false));
                    $vSS = $r100($factRetiroPreview->total_ss);
                    $vTot = $vSS + ($vAdmonBase + $vAdmonAsesor) + $vIva;
                } elseif ($esRetirado && ! $factRetiroPreview) {
                    $vEps = $vArl = $vPen = $vCaja = $vIva = $vSS = 0;
                    $vTot = 0;
                } elseif ($esSoloSeguro) {
                    // El mes vale el seguro y nada más: sin seguridad social, sin
                    // administración, sin IVA y sin costo de afiliación.
                    $vEps = $vArl = $vPen = $vCaja = $vIva = $vSS = 0;
                    $vTot = (int) ($c->seguro ?? 0);
                } elseif ($esArlModalidad && ! $esAfil) {
                    $vEps = $vArl = $vPen = $vCaja = $vIva = $vSS = 0;
                    $vTot = 0;
                } elseif ($esIndActPrimerMes) {
                    $vEps = $r100($cotiz['eps'] ?? 0);
                    $vArl = $r100($cotiz['arl'] ?? 0);
                    $vPen = $r100($cotiz['pen'] ?? 0);
                    $vCaja = $r100($cotiz['caja'] ?? 0);
                    // IVA: admon (ya viene en $cotiz) + costo de afiliación
                    $vIva = $r100($cotiz['iva'] ?? 0)
                           + \App\Services\IvaService::calcular((int) ($c->costo_afiliacion ?? 0), (bool) ($c->tiene_iva ?? false));
                    $vSS = $r100($cotiz['ss']);
                    $vTot = $vSS + ($vAdmonBase + $vAdmonAsesor) + $vIva + (int) (($c->costo_afiliacion ?? 0) + ($c->seguro ?? 0));
                } elseif ($esAfil) {
                    $vEps = 0;
                    $vArl = 0;
                    $vPen = 0;
                    $vCaja = 0;
                    $vSS = 0;
                    // El IVA de una afiliación grava el costo de afiliación
                    $vIva = \App\Services\IvaService::calcular((int) ($c->costo_afiliacion ?? 0), (bool) ($c->tiene_iva ?? false));
                    $vTot = (int) (($c->costo_afiliacion ?? 0) + ($c->seguro ?? 0)) + $vIva;
                } else {
                    $vEps = $r100($cotiz['eps'] ?? 0);
                    $vArl = $r100($cotiz['arl'] ?? 0);
                    $vPen = $r100($cotiz['pen'] ?? 0);
                    $vCaja = $r100($cotiz['caja'] ?? 0);
                    $vIva = $r100($cotiz['iva'] ?? 0);
                    $vSS = $r100($cotiz['ss']);
                    $vTot = $vSS + ($vAdmonBase + $vAdmonAsesor) + $vIva;
                }
            } else {
                $vSS = $r100($fact->total_ss);
                $vTot = (int) $fact->total;
            }

            $yaP = $fact && in_array($fact->estado, ['pagada', 'prestamo']);
            $vMora = 0;
            if (! $yaP) {
                if ($fact && ($fact->mora ?? 0) > 0) {
                    $vMora = (int) $fact->mora;
                } elseif (! $fact) {
                    $vMora = (int) ($moraPorContrato[$c->id] ?? 0);
                }
            }

            $vAnticipo = $saldoAnticipoPorContrato->get($c->id, 0);

            $sheet->setCellValue('A'.$row, $tipoMod);
            $sheet->setCellValue('B'.$row, $c->cedula);
            $sheet->setCellValue('C'.$row, $nombre);
            $sheet->setCellValue('D'.$row, $rs);

            // E: fecha de ingreso — F: fecha de retiro (cada una en su columna)
            if ($c->fecha_ingreso instanceof \DateTimeInterface) {
                $sheet->setCellValue('E'.$row, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($c->fecha_ingreso));
                $sheet->getStyle('E'.$row)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            } else {
                $sheet->setCellValue('E'.$row, '—');
            }

            $fechaRetiroObj = $c->fecha_retiro ?: ($c->fecha_retiro_pendiente ?? null);
            if ($fechaRetiroObj instanceof \DateTimeInterface) {
                $sheet->setCellValue('F'.$row, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($fechaRetiroObj));
                $sheet->getStyle('F'.$row)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            } else {
                $sheet->setCellValue('F'.$row, '—');
            }

            $sheet->setCellValue('G'.$row, $dias);

            $sheet->setCellValue('H'.$row, $vEps);
            $sheet->setCellValue('I'.$row, $vArl);
            $sheet->setCellValue('J'.$row, $vCaja);
            $sheet->setCellValue('K'.$row, $vPen);
            $sheet->setCellValue('L'.$row, $vAdmonBase);
            $sheet->setCellValue('M'.$row, $vAdmonAsesor);
            $sheet->setCellValue('N'.$row, $vAfiliacion);
            $sheet->setCellValue('O'.$row, $vTot);
            $sheet->setCellValue('P'.$row, $vMora);
            $sheet->setCellValue('Q'.$row, $vAnticipo);

            $estadoTxt = $fact ? strtoupper($fact->estado) : ($esRetirado ? 'RETIRO' : 'PENDIENTE');
            $sheet->setCellValue('R'.$row, $estadoTxt);
            $sheet->setCellValue('S'.$row, $fact?->np ?? '');

            // Alineación de texto corto
            $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('B'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('E'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('F'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('G'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('R'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('S'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            // Formato de moneda para columnas de dinero (H a Q)
            $sheet->getStyle('H'.$row.':Q'.$row)
                ->getNumberFormat()
                ->setFormatCode('$#,##0');

            // Bordes para los datos
            $sheet->getStyle('A'.$row.':S'.$row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

            $row++;
        }

        $ultimaFilaDatos = $row - 1;

        // Filtro de Excel sobre la cabecera + filas de clientes (sin la fila de totales).
        // Ninguna celda dentro de este rango puede estar combinada, o Excel se queja
        // con "todas las celdas combinadas deben tener el mismo tamaño" al filtrar.
        $sheet->setAutoFilter('A4:S'.max($ultimaFilaDatos, 4));
        // Cabecera siempre visible al desplazarse
        $sheet->freezePane('A5');

        // Fila de totales (sin combinar, para no romper el filtro)
        $sheet->setCellValue('A'.$row, 'TOTALES');
        $sheet->getStyle('A'.$row.':F'.$row)->getFont()->setBold(true);
        $sheet->getStyle('A'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        $hayDatos = $ultimaFilaDatos >= 5;

        // Suma de Días (G) — SUBTOTAL para que respete el filtro activo
        $sheet->setCellValue('G'.$row, $hayDatos ? '=SUBTOTAL(109,G5:G'.$ultimaFilaDatos.')' : 0);
        $sheet->getStyle('G'.$row)->getFont()->setBold(true);
        $sheet->getStyle('G'.$row)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Sumas por columnas con fórmulas de Excel (H a Q)
        $columnasMoneda = ['H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q'];
        foreach ($columnasMoneda as $colChar) {
            $sheet->setCellValue(
                $colChar.$row,
                $hayDatos ? '=SUBTOTAL(109,'.$colChar.'5:'.$colChar.$ultimaFilaDatos.')' : 0
            );
            $sheet->getStyle($colChar.$row)->getFont()->setBold(true);
            $sheet->getStyle($colChar.$row)->getNumberFormat()->setFormatCode('$#,##0');
        }

        // Estilo de fila de totales
        $totalStyle = [
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'E2E8F0'],
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '999999'],
                ],
            ],
        ];
        $sheet->getStyle('A'.$row.':S'.$row)->applyFromArray($totalStyle);
        $sheet->getStyle('A'.$row.':S'.$row)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        // Auto-ajustar ancho de columnas
        foreach (range('A', 'S') as $colChar) {
            $sheet->getColumnDimension($colChar)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $nomEmpresaClean = preg_replace('/[^a-zA-Z0-9]/', '_', $empresa->empresa);
        $mesNom = $meses[$mes];
        $filename = "Planilla_{$nomEmpresaClean}_{$mesNom}_{$anio}.xlsx";

        return response()->stream(
            function () use ($writer) {
                $writer->save('php://output');
            },
            200,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                'Cache-Control' => 'max-age=0',
            ]
        );
    }

    /**
     * Fecha de pago del recibo (facturas.fecha_pago).
     *
     * Cuando el pago es 100% consignación el dinero entró el día en que el
     * cliente consignó, no el día en que se factura → se usa la fecha MAYOR
     * de las consignaciones del pago (varias consignaciones = la más reciente).
     * Nunca se acepta una fecha futura: en ese caso se cae a hoy.
     *
     * Con efectivo de por medio (efectivo, mixto, préstamo) se mantiene HOY,
     * porque ese efectivo entra a la caja del día y el cuadre diario agrupa
     * el efectivo por fecha_pago.
     *
     * created_at siempre queda con la fecha real de digitación.
     */
    private function _fechaPagoRecibo(?string $formaPago, array $consignacionesData): string
    {
        $hoy = now()->toDateString();

        if ($formaPago !== 'consignacion') {
            return $hoy;
        }

        $fechas = [];
        foreach ($consignacionesData as $cs) {
            if ((int) ($cs['valor'] ?? 0) <= 0 || empty($cs['fecha'])) {
                continue;
            }
            try {
                $fechas[] = \Carbon\Carbon::parse($cs['fecha'])->toDateString();
            } catch (\Throwable $e) {
                // fecha inválida → se ignora, no debe tumbar la facturación
            }
        }

        if (empty($fechas)) {
            return $hoy;
        }

        $mayor = max($fechas);

        return $mayor > $hoy ? $hoy : $mayor;
    }

    /**
     * Detecta qué contratos de un lote YA tienen factura para el período pedido.
     *
     * Replica exactamente los tres criterios de omisión que aplica facturar():
     *   1. Modo "ambos" (I Venc / I Act en su mes de ingreso): bloquea si ya hay
     *      afiliación del período, o —para I Venc— planilla del mes siguiente.
     *   2. Retiro facturable (contrato retirado con factura numero_factura=0):
     *      nunca bloquea, tiene su propio flujo.
     *   3. Flujo normal: bloquea si ya hay una factura del MISMO tipo solicitado.
     *
     * Lo usan facturar() (para abortar el lote) y verificarPeriodoLote() (para
     * avisar en el modal antes de que el usuario dé "Facturar ahora").
     *
     * @param  \Illuminate\Support\Collection  $contratosCargados  contratos con tipoModalidad y cliente
     * @return array<int, array{contrato_id:mixed, cedula:mixed, nombre:string, motivo:string}>
     */
    private function _detectarDuplicadosLote(
        int $aliadoId,
        $contratosCargados,
        int $mes,
        int $anio,
        string $tipoSolicitado,
        string $indepModo = 'normal'
    ): array {
        $contratoIds = $contratosCargados->pluck('id')->all();
        if (empty($contratoIds)) {
            return [];
        }

        $facturasPeriodo = Factura::where('aliado_id', $aliadoId)
            ->whereIn('contrato_id', $contratoIds)
            ->where('mes', $mes)
            ->where('anio', $anio)
            ->whereNotIn('estado', ['anulada'])
            ->get(['id', 'contrato_id', 'tipo'])
            ->groupBy('contrato_id');

        $facturasRetiro0 = Factura::where('aliado_id', $aliadoId)
            ->whereIn('contrato_id', $contratoIds)
            ->where('numero_factura', 0)
            ->get(['id', 'contrato_id'])
            ->keyBy('contrato_id');

        // Planillas del mes siguiente: solo pesa en modo "ambos" con I Venc
        $mesSig = $mes === 12 ? 1 : $mes + 1;
        $anioSig = $mes === 12 ? $anio + 1 : $anio;
        $planillasMesSig = [];
        if ($indepModo === 'ambos') {
            $planillasMesSig = Factura::where('aliado_id', $aliadoId)
                ->whereIn('contrato_id', $contratoIds)
                ->where('mes', $mesSig)
                ->where('anio', $anioSig)
                ->where('tipo', 'planilla')
                ->pluck('contrato_id')
                // sqlsrv devuelve los ids como string — normalizar para comparar
                ->map(fn ($v) => (string) $v)
                ->all();
        }

        $duplicados = [];
        foreach ($contratosCargados as $contrato) {
            $facturasDup = $facturasPeriodo->get($contrato->id) ?: collect();
            $nombre = trim(($contrato->cliente?->primer_nombre ?? '').' '.($contrato->cliente?->primer_apellido ?? ''));

            // Independiente que cotiza VENCIDO: su planilla va en el mes siguiente
            // al de la factura. Un independiente de mes actual también es
            // modalidad 10, así que sin mirar el flag se le adelantaba el plano
            // un mes (facturando septiembre, el plano salía en octubre).
            $esIndVenc = (int) $contrato->tipo_modalidad_id === 10
                && ! (bool) ($contrato->paga_mes_actual ?? false);
            $esIndAct = (bool) ($contrato->paga_mes_actual ?? false);
            $esMesIngreso = $contrato->fecha_ingreso
                && (int) $contrato->fecha_ingreso->month === $mes
                && (int) $contrato->fecha_ingreso->year === $anio;

            $esAmbos = ($indepModo === 'ambos') && ($esIndVenc || $esIndAct) && $esMesIngreso;

            if ($esAmbos) {
                if ($facturasDup->contains(fn ($f) => $f->tipo === 'afiliacion')) {
                    $duplicados[] = [
                        'contrato_id' => $contrato->id,
                        'cedula' => $contrato->cedula,
                        'nombre' => $nombre,
                        'motivo' => "Ya existe una afiliación para {$mes}/{$anio} en este contrato",
                    ];
                } elseif ($esIndVenc && in_array((string) $contrato->id, $planillasMesSig, true)) {
                    $duplicados[] = [
                        'contrato_id' => $contrato->id,
                        'cedula' => $contrato->cedula,
                        'nombre' => $nombre,
                        'motivo' => "Ya existe una planilla para {$mesSig}/{$anioSig} en este contrato",
                    ];
                }

                continue;
            }

            // Retiro facturable: no bloquea, se procesa en su propio flujo.
            // Salvo en el mes de ingreso, donde el cobro es la afiliación y sí
            // aplica el anti-duplicado normal del período.
            if ($contrato->estado === 'retirado'
                && $facturasRetiro0->has($contrato->id)
                && ! $this->_esAfiliacionDelMesDeIngreso($contrato, $mes, $anio)) {
                continue;
            }

            if ($facturasDup->contains(fn ($f) => $f->tipo === $tipoSolicitado)) {
                $duplicados[] = [
                    'contrato_id' => $contrato->id,
                    'cedula' => $contrato->cedula,
                    'nombre' => $nombre,
                    'motivo' => "Ya existe una {$tipoSolicitado} para {$mes}/{$anio} en este contrato",
                ];
            }
        }

        return $duplicados;
    }

    // ─── API: verificar qué contratos del lote ya están facturados ───
    /**
     * Lo consulta el modal de facturación masiva al abrirse y al cambiar el
     * período, para avisar ANTES de que el usuario meta el dinero y dé
     * "Facturar ahora" (facturar() rechaza el lote completo si hay duplicados).
     */
    public function verificarPeriodoLote(Request $request)
    {
        $aliadoId = session('aliado_id_activo');

        $validated = $request->validate([
            'contratos' => 'required|array|min:1',
            'contratos.*' => 'integer',
            'mes' => 'required|integer|min:1|max:12',
            'anio' => 'required|integer|min:2000|max:2100',
            'tipo' => 'nullable|in:afiliacion,planilla',
            'indep_modo' => 'nullable|in:normal,ambos',
        ]);

        $contratos = Contrato::where('aliado_id', $aliadoId)
            ->whereIn('id', $validated['contratos'])
            ->with(['tipoModalidad', 'cliente'])
            ->get();

        $duplicados = $this->_detectarDuplicadosLote(
            $aliadoId,
            $contratos,
            (int) $validated['mes'],
            (int) $validated['anio'],
            $validated['tipo'] ?? 'planilla',
            $validated['indep_modo'] ?? 'normal'
        );

        return response()->json([
            'ok' => true,
            'duplicados' => $duplicados,
        ]);
    }

    // ─── Facturar (crear factura) ────────────────────────────────────
    public function facturar(Request $request)
    {
        $aliadoId = session('aliado_id_activo');

        // Una nota demasiado larga no puede costar la factura: la columna solo
        // guarda 500 caracteres, así que se recorta acá en vez de rechazar todo
        // el guardado con un error de validación.
        if ($request->filled('observacion')) {
            $request->merge([
                'observacion' => mb_substr((string) $request->input('observacion'), 0, 500),
            ]);
        }

        $validated = $request->validate([
            'contratos' => 'required|array|min:1',
            'contratos.*' => 'exists:contratos,id',
            'tipo' => 'required|in:afiliacion,planilla',
            'mes' => 'required|integer|min:1|max:12',
            'anio' => 'required|integer|min:2000|max:2100',
            'forma_pago' => 'required|in:efectivo,consignacion,mixto,prestamo',
            'estado' => 'required|in:pre_factura,pagada,prestamo',
            'es_prestamo' => 'boolean',
            // Array dinámico de consignaciones bancarias
            'consignaciones' => 'nullable|array',
            'consignaciones.*.banco_cuenta_id' => 'required_with:consignaciones|integer',
            'consignaciones.*.valor' => 'required_with:consignaciones|numeric|min:0',
            'consignaciones.*.fecha' => 'nullable|date',
            'consignaciones.*.referencia' => 'nullable|string|max:100',
            'valor_efectivo' => 'nullable|numeric|min:0',
            'valor_prestamo' => 'nullable|numeric|min:0',
            'otros' => 'nullable|numeric|min:0',
            'otros_admon' => 'nullable|numeric|min:0',
            'mensajeria' => 'nullable|numeric|min:0',
            'np' => 'nullable|integer',
            'n_plano' => 'nullable|integer',
            'empresa_id' => 'nullable|integer',
            'aplicar_saldo' => 'boolean',
            'observacion' => 'nullable|string|max:500',
            // Anticipos (pagos previos sin factura)
            'anticipo_ids' => 'nullable|array',
            'anticipo_ids.*' => 'integer',
            // SS editables desde la UI (override manual — solo 1 contrato)
            'v_eps_manual' => 'nullable|integer|min:0',
            'v_arl_manual' => 'nullable|integer|min:0',
            'v_afp_manual' => 'nullable|integer|min:0',
            'v_caja_manual' => 'nullable|integer|min:0',
            // SS manuales por contrato (multi-contrato desde form individual)
            // JSON: { "<contrato_id>": { "eps": X, "arl": X, "afp": X, "caja": X } }
            'manual_ss_por_contrato' => 'nullable|string',
            // Distribución de afiliación (override manual desde la UI)
            'dist_asesor' => 'nullable|integer|min:0',
            'dist_retiro' => 'nullable|integer|min:0',
            'dist_encargado' => 'nullable|integer|min:0',
            'dist_admon' => 'nullable|integer|min:0',
            // Retiro en el período
            'es_retiro' => 'boolean',
            'fecha_retiro' => 'nullable|date',
            'dias_retiro' => 'nullable|integer|min:1|max:30',
            // Mora al cliente (cobrada en la factura, no es ingreso)
            'mora' => 'nullable|integer|min:0',
            // El usuario edito la mora a mano en el modal (ver modal_facturar_v2.js).
            'mora_manual' => 'boolean',
            // Cartera pendiente: marcar si el usuario incluyó deuda de préstamo anterior
            'incluir_cartera' => 'boolean',
            'valor_cartera' => 'nullable|integer|min:0',
            // Modo independiente: 'normal' | 'ambos' (afiliación + planilla en mismo recibo)
            'indep_modo' => 'nullable|in:normal,ambos',
            // Retiro desde empresa: incluir administración aunque sean ≤3 días
            'incluir_admon_retiro_corto' => 'boolean',
        ]);

        // Decodificar manual_ss_por_contrato si viene como JSON string
        $manualSsPorContrato = [];
        if (! empty($validated['manual_ss_por_contrato'])) {
            $decoded = json_decode($validated['manual_ss_por_contrato'], true);
            if (is_array($decoded)) {
                $manualSsPorContrato = $decoded;
            }
        }

        $np = $validated['np'] ?? null;
        // Si es pago masivo y no tiene NP, generar uno nuevo.
        // El NP es por empresa + mes + año: cada empresa tiene su propio contador
        // que se reinicia cada mes. Primer pago mayo=1, segundo=2, junio vuelve a 1.
        if (! $np && count($validated['contratos']) > 1) {
            $mes = (int) ($validated['mes'] ?? now()->month);
            $anio = (int) ($validated['anio'] ?? now()->year);
            $empresaIdNp = $validated['empresa_id'] ?? null;
            $qNp = DB::table('facturas')
                ->where('aliado_id', $aliadoId)
                ->where('mes', $mes)
                ->where('anio', $anio)
                ->whereNull('deleted_at');
            // Si viene empresa_id, restringir el contador a esa empresa
            if ($empresaIdNp) {
                $qNp->where('empresa_id', $empresaIdNp);
            }
            $np = ($qNp->max('np') ?? 0) + 1;
        }

        $mes = (int) $validated['mes'];
        $anio = (int) $validated['anio'];

        // ─── Validar orden secuencial de facturación ────────────────────────
        // Solo para facturas individuales (single contrato); en masivo se omite
        // la validación por desempeño, ya que empresa gestiona su propio orden.
        // Se cargan todos los contratos de una vez (antes: 1 query por cada id
        // seleccionado) — ver docs/auditoria-calidad.md, hallazgo E-3.
        $contratosChk = Contrato::where('aliado_id', $aliadoId)
            ->whereIn('id', $validated['contratos'])
            ->get()
            ->keyBy('id');
        foreach ($validated['contratos'] as $cId) {
            $cChk = $contratosChk->get($cId);
            if ($cChk) {
                $gap = $this->verificarOrdenFacturacion($aliadoId, $cChk, $mes, $anio);
                if ($gap && $gap['bloquea']) {
                    return response()->json([
                        'error' => true,
                        'mensaje' => $gap['mensaje'],
                        'mes_gap' => $gap['mes'],
                        'anio_gap' => $gap['anio'],
                    ], 422);
                }
            }
        }

        // ─── Pre-calcular n_plano compartido por razón social ──────────────
        // Todos los contratos de la misma RS en este lote deben tener el MISMO n_plano.
        // Se calcula UNA VEZ por RS antes de entrar al foreach.
        $nPlanosPorRS = [];

        // ─── Pre-calcular totales del pago ─────────────────────────────────
        // valor_consignado = suma de TODAS las consignaciones del array
        $consignacionesData = $validated['consignaciones'] ?? [];
        $totalPagoConsig = array_sum(array_column($consignacionesData, 'valor'));
        $totalPagoEfectivo = (int) ($validated['valor_efectivo'] ?? 0);
        $totalPagoPrestamo = (int) ($validated['valor_prestamo'] ?? 0);

        // ── Un préstamo sin monto no es un préstamo ────────────────────
        // El módulo de cobro calcula el saldo como valor_prestamo - abonos, así
        // que una factura marcada préstamo con el campo en 0 sale con el sello
        // morado en el recibo pero NUNCA aparece en /admin/prestamos: nadie la
        // cobra. Así se colaron 20 lotes ($8,1M) entre may-ago 2026.
        if ($validated['estado'] === 'prestamo' && $totalPagoPrestamo <= 0) {
            return response()->json([
                'error' => true,
                'mensaje' => '🚫 Marcaste la factura como PRÉSTAMO pero el campo Préstamo quedó en $0. '
                    .'Escribe cuánto queda debiendo el cliente, o cambia el estado a Pagada.',
            ], 422);
        }

        // ─── Mora escrita a mano en el lote de empresa ─────────────────────
        // Sin tocar nada, la mora la calcula MoraClienteService contrato por
        // contrato (cada RS tiene su propio vencimiento). Pero si el usuario la
        // escribio en el modal, esa decision manda —valga 0 o valga mas— y se
        // reparte entre los contratos que cotizan planilla, en proporcion a su
        // seguridad social. Antes solo se respetaba el 0: escribir $1.300 se
        // ignoraba en silencio, el backend cobraba lo calculado y la propia
        // validacion de pago rechazaba el lote por la diferencia, sin decir que
        // la culpa era la mora (caso AGROMACZO, sep-2026: $1.300 escritos,
        // $6.265 cobrados, "faltan $4.965").
        // Manda la mora que viene del modal, la haya escrito el usuario o no: es
        // la que vio en pantalla y la que el cliente va a pagar. Cuando el
        // backend la recalculaba por su cuenta, cobraba una mora que el recibo
        // no mostraba y la validacion de pago rebotaba el lote por la
        // diferencia (ALIADO-ESTEBAN ROMERO, 22-sep-2026: $300 en pantalla,
        // $3.990 cobrados, "faltan $3.690"). Lo que el aliado no le cobra al
        // cliente lo asume el aliado, no se le arrastra al cliente.
        // Sin la clave —otro llamador que no sea el modal— se calcula como antes.
        $moraManualLote = array_key_exists('mora', $validated)
            || ! empty($validated['mora_manual']);
        $moraLoteManual = max(0, (int) ($validated['mora'] ?? 0));

        // Fecha del recibo: si el pago es solo consignación, la fecha en que
        // entró el dinero al banco (la mayor si son varias), no la de hoy.
        $fechaPagoRecibo = $this->_fechaPagoRecibo($validated['forma_pago'], $consignacionesData);

        // ─── Pre-cargar anticipos seleccionados ────────────────────────────
        // Los anticipos son pagos previos sin factura. Se separan de valor_efectivo
        // y valor_consignado para evitar doble conteo en el cuadre diario.
        $anticiposSeleccionados = collect();
        $totalAnticipo = 0;
        if (! empty($validated['anticipo_ids'])) {
            $anticiposSeleccionados = \App\Models\Anticipo::whereIn('id', $validated['anticipo_ids'])
                ->where('aliado_id', $aliadoId)
                ->whereIn('estado', [\App\Models\Anticipo::ESTADO_DISPONIBLE, \App\Models\Anticipo::ESTADO_PARCIAL])
                ->lockForUpdate()  // previene race condition
                ->get();
            $totalAnticipo = $anticiposSeleccionados->sum('valor_disponible');
        }

        // IVA configurado (se aplica a clientes con IVA=SI, igual que en la factura)
        $cfgIvaPct = \App\Models\ConfiguracionBrynex::porcentajeIva(); // ej: 19

        // Precargar todos los contratos involucrados en una sola query (con eager loading de relaciones)
        $contratosCargados = Contrato::where('aliado_id', $aliadoId)
            ->whereIn('id', $validated['contratos'])
            // 'plan' lo pide calcularCotizacion() en cada contrato (planilla, y ahora también
            // el retiro calculado de la afiliación): sin precargarlo es una query por contrato.
            ->with(['eps', 'arl', 'pension', 'caja', 'tipoModalidad', 'razonSocial', 'asesor', 'cliente', 'plan'])
            ->get()
            ->keyBy('id');

        // ── Retiros facturables del lote (contrato retirado con factura 0) ──
        // Se precargan ANTES del costeo: lo que cuesta un contrato retirado es
        // su retiro, no un mes completo. Costearlo como mes completo infla su
        // peso en el reparto del pago y de los "otros" del lote, y descuadra a
        // los demas (lote 106127, sep-2026: 5 retiros pesados como mes entero
        // se llevaron $382k y $240k de mas, dejando -$30.062 en 20 facturas).
        $facturasRetiro0Lote = Factura::withTrashed()
            ->where('aliado_id', $aliadoId)
            ->whereIn('contrato_id', $contratosCargados->pluck('id')->all())
            ->where('numero_factura', 0)
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('contrato_id');

        $incluirAdmonRetiroCorto = ! empty($validated['incluir_admon_retiro_corto']);

        // Calcular COSTO BRUTO REAL de cada contrato para proporcionar el pago proporcional.
        $totalesRealesPorContrato = [];
        // Seguridad social de los contratos que pueden llevar mora (planilla, no
        // afiliacion): es la base para repartir la mora que el usuario escribio.
        $ssParaMora = [];
        foreach ($validated['contratos'] as $cId) {
            $c = $contratosCargados->get($cId);
            if (! $c) {
                $totalesRealesPorContrato[$cId] = 0;

                continue;
            }

            // Retiro facturable: el costo ya esta calculado en la factura 0.
            if ($this->_tieneRetiroFacturable($c, $facturasRetiro0Lote, $mes, $anio)) {
                $totalesRealesPorContrato[$cId] = self::_totalRetiroFacturable(
                    $c,
                    $facturasRetiro0Lote->get($c->id),
                    $incluirAdmonRetiroCorto
                );

                continue;
            }

            $esIndAct = (bool) ($c->paga_mes_actual ?? false);
            $esArl = (int) ($c->tipo_modalidad_id) === 15;
            $tipoForzado = $validated['tipo'];
            $esMesIng = false;

            if ($esArl) {
                // Gestión ARL siempre es cobro de afiliación, no planilla
                $esMesIng = true;
            } elseif ($c->fecha_ingreso) {
                $mesIng = (int) $c->fecha_ingreso->month;
                $anioIng = (int) $c->fecha_ingreso->year;
                $esMesIng = ($mes === $mesIng && $anio === $anioIng);
            }

            // Afiliación pura salvo que cotice el mes en curso — lo decide el
            // flag, no la modalidad (ver CobroContratoService::calcular).
            if ($esMesIng && ($esArl || ! $esIndAct)) {
                $tipoForzado = 'afiliacion';
            }

            if ($esArl) {
                $tipoForzado = 'afiliacion';
            }

            // Solo seguro: siempre la mensualidad del seguro, nunca afiliación.
            // Misma decisión que en la segunda pasada, donde se crea la factura.
            $esSoloSeguro = (int) $c->tipo_modalidad_id === \App\Models\Contrato::MODALIDAD_SEGUROS;
            if ($esSoloSeguro) {
                $tipoForzado = 'planilla';
            }

            $esIndActPrimerMes = $esIndAct && isset($esMesIng) && $esMesIng;
            $esAfiliacion = $tipoForzado === 'afiliacion';

            if ($esArl && ! $esMesIng) {
                $diasCotizar = 0;
                $calcSS = ['eps' => 0, 'arl' => 0, 'afp' => 0, 'caja' => 0, 'parafiscales' => 0];
                $afiliacion = 0;
                $seguro = 0;
                $admon = 0;
                $adminAsesor = 0;
                $otrosAdmon = 0;
                $totalSS = 0;
                $ivaBase = 0;
                $iva = 0;
                $total = 0;
                $moraCliente = 0;
            } else {
                if ($esIndActPrimerMes) {
                    $diasCotizar = max(1, 30 - (int) $c->fecha_ingreso->day + 1);
                } elseif ($esAfiliacion) {
                    $diasCotizar = 0;
                } else {
                    $diasCotizar = $this->calcularDias($c, $mes, $anio);
                }

                $esRetiro = ! empty($validated['es_retiro']);
                $diasRetiro = $esRetiro ? (int) ($validated['dias_retiro'] ?? $diasCotizar) : null;
                if ($esRetiro && $diasRetiro !== null && ! $esAfiliacion) {
                    $diasCotizar = $diasRetiro;
                }

                $tieneIva = \App\Services\IvaService::aplicaContrato($c);

                if ($esAfiliacion && ! $esIndActPrimerMes) {
                    $calcSS = ['eps' => 0, 'arl' => 0, 'afp' => 0, 'caja' => 0, 'parafiscales' => 0];
                } else {
                    $cotiz = $c->calcularCotizacion($diasCotizar, $tieneIva);
                    $calcSS = [
                        'eps' => (int) ($cotiz['eps'] ?? 0),
                        'arl' => (int) ($cotiz['arl'] ?? 0),
                        'afp' => (int) ($cotiz['pen'] ?? 0),
                        'caja' => (int) ($cotiz['caja'] ?? 0),
                        'parafiscales' => (int) ($cotiz['parafiscales'] ?? 0),
                    ];
                }

                // Override manual
                $esModoIndividual = count($validated['contratos']) === 1;
                if (! $esAfiliacion) {
                    if ($esModoIndividual) {
                        if (isset($validated['v_eps_manual'])) {
                            $calcSS['eps'] = intval($validated['v_eps_manual']);
                        }
                        if (isset($validated['v_arl_manual'])) {
                            $calcSS['arl'] = intval($validated['v_arl_manual']);
                        }
                        if (isset($validated['v_afp_manual'])) {
                            $calcSS['afp'] = intval($validated['v_afp_manual']);
                        }
                        if (isset($validated['v_caja_manual'])) {
                            $calcSS['caja'] = intval($validated['v_caja_manual']);
                        }
                    } elseif (! empty($manualSsPorContrato[(string) $cId])) {
                        $ssMap = $manualSsPorContrato[(string) $cId];
                        if (isset($ssMap['eps'])) {
                            $calcSS['eps'] = intval($ssMap['eps']);
                        }
                        if (isset($ssMap['arl'])) {
                            $calcSS['arl'] = intval($ssMap['arl']);
                        }
                        if (isset($ssMap['afp'])) {
                            $calcSS['afp'] = intval($ssMap['afp']);
                        }
                        if (isset($ssMap['caja'])) {
                            $calcSS['caja'] = intval($ssMap['caja']);
                        }
                    }
                }

                $afiliacion = ($esAfiliacion || $esIndActPrimerMes) ? (int) ($c->costo_afiliacion ?? 0) : 0;
                $seguro = (int) ($c->seguro ?? 0);
                $admon = ($esAfiliacion && ! $esIndActPrimerMes) ? 0 : intval($c->administracion ?? 0);
                $adminAsesor = ($esAfiliacion && ! $esIndActPrimerMes) ? 0 : intval($c->admon_asesor ?? 0);
                // Los "otros" son del lote y se reparten después de conocer lo que
                // cuesta cada contrato (ver _repartirProporcional): aquí la base va
                // limpia, o la proporción se calcularía sobre sí misma.
                $otrosAdmon = 0;

                // Solo seguro: el mes vale el seguro y nada más.
                if ($esSoloSeguro) {
                    $calcSS = ['eps' => 0, 'arl' => 0, 'afp' => 0, 'caja' => 0, 'parafiscales' => 0];
                    $admon = 0;
                    $adminAsesor = 0;
                    $afiliacion = 0;
                }

                $totalSS = $calcSS['eps'] + $calcSS['arl'] + $calcSS['afp'] + $calcSS['caja'] + ($calcSS['parafiscales'] ?? 0);
                // IVA sobre administración + costo de afiliación (ver IvaService)
                $iva = \App\Services\IvaService::deFactura($tieneIva, $admon, $adminAsesor, $afiliacion);

                $total = $totalSS + $admon + $adminAsesor + $otrosAdmon + $seguro + $afiliacion + $iva;

                $moraCliente = 0;
                if ((int) $c->tipo_modalidad_id === \App\Models\Contrato::MODALIDAD_SEGUROS) {
                    $moraCliente = 0;   // sin planilla que pagar tarde no hay mora
                } elseif ($esModoIndividual) {
                    $moraCliente = $esAfiliacion ? 0 : (int) ($validated['mora'] ?? 0);
                } elseif ($moraManualLote) {
                    // La reparte el bloque de abajo entre quienes cotizan planilla.
                    $moraCliente = 0;
                    if (! $esAfiliacion && $totalSS > 0) {
                        $ssParaMora[$cId] = $totalSS;
                    }
                } else {
                    if (! $esAfiliacion) {
                        $rsNit = $c->nitParaMora();
                        $rsDiaH = $c->diaHabilParaMora();
                        if ($rsNit && $totalSS > 0) {
                            $moraInfo = MoraClienteService::calcular($aliadoId, $rsNit, $rsDiaH, $totalSS, $mes, $anio);
                            $moraCliente = $moraInfo['mora'];
                        }
                    }
                }
                $total += $moraCliente;
            }
            $totalesRealesPorContrato[$cId] = max(0, $total);
        }

        // ─── Mora del lote escrita a mano: se reparte, no se repite ────────
        // Solo entre los que cotizan planilla y en proporcion a su seguridad
        // social, que es sobre lo que se causa la mora. Si el usuario la dejo en
        // 0, no se le cobra a nadie.
        $moraPorContrato = [];
        if ($moraManualLote && count($validated['contratos']) > 1) {
            $moraPorContrato = self::_repartirProporcional(
                $moraLoteManual,
                $ssParaMora,
                array_sum($ssParaMora)
            );
            foreach ($moraPorContrato as $cId => $v) {
                $totalesRealesPorContrato[$cId] = ($totalesRealesPorContrato[$cId] ?? 0) + $v;
            }
        }

        // ─── "Otros" del lote: se reparten, no se repiten ──────────────────
        // Los dos campos del modal ("Otros planilla" y "Otros admon") son un
        // valor del lote entero — así los suma la pantalla al total bruto —,
        // pero se guardaban completos en CADA factura. `otros` además nunca
        // entraba en el total, así que el cobro se perdía y el pago sobrante
        // caía a saldo a favor; `otros_admon` sí entraba, y se cobraba una vez
        // por trabajador (un lote de 72 facturas cobró $2.260.800 de más).
        // Se reparten en proporción al costo de cada contrato, igual que la
        // consignación, el efectivo y el saldo a favor.
        $otrosLote = (int) ($validated['otros'] ?? 0);
        $otrosAdmonLote = (int) ($validated['otros_admon'] ?? 0);
        $baseReparto = array_sum($totalesRealesPorContrato);
        $otrosPorContrato = self::_repartirProporcional($otrosLote, $totalesRealesPorContrato, $baseReparto);
        $otrosAdmonPorContrato = self::_repartirProporcional($otrosAdmonLote, $totalesRealesPorContrato, $baseReparto);
        foreach ($totalesRealesPorContrato as $cId => $v) {
            $totalesRealesPorContrato[$cId] = $v
                + ($otrosPorContrato[$cId] ?? 0)
                + ($otrosAdmonPorContrato[$cId] ?? 0);
        }

        $granTotalReal = array_sum($totalesRealesPorContrato) ?: 1; // evitar división por 0

        // ─── numero_factura compartido para el lote masivo ─────────────────
        // En modo masivo todos los contratos comparten el MISMO número de recibo.
        // En modo individual se genera uno por llamada, pero aquí pre-calculamos uno solo.
        $esMasivo = count($validated['contratos']) > 1;
        $batchNumeroFactura = Factura::siguienteNumero($aliadoId); // único para el lote

        $facturasCreadas = [];
        $omitidos = [];  // contratos ya facturados para ese período
        // Acumuladores para ajuste de redondeo post-loop en la última factura del batch.
        // Garantiza sum(ef_i) = ef_total exactamente, eliminando residuos de redondeo.
        $efAcum = $csAcum = $prAcum = $sfAcum = $antAcum = 0;
        // Saldo empresa a aplicar como credito en este batch (distribuido igualmente)
        $empresaId = $validated['empresa_id'] ?? null;
        $saldoEmpresaAplicar = 0;
        $contratosPendientes = count(array_filter($validated['contratos']));

        // El modal puede decidir NO tomar el saldo a favor en esta factura, igual
        // que se hace con la cartera pendiente: sirve para poder facturar mientras
        // se corrige un saldo a favor que no corresponde (los "Otros" que no se
        // cobraban dejaron varios falsos). Sin la casilla, se aplica como siempre.
        $aplicarSaldo = ! array_key_exists('aplicar_saldo', $validated)
            || (bool) $validated['aplicar_saldo'];

        if ($empresaId && $esMasivo && $aplicarSaldo) {
            // ── Saldo neto REAL de la empresa (sin filtro de fecha) ──────────
            // Se debe sumar TODOS los saldo_proximo de empresa_id, incluyendo
            // los del mes actual ya facturados. Razón: al facturar un lote
            // parcial (ej. 9 de 14 contratos ya facturados en Mayo con sp=-87500),
            // esos negativos deben compensar el saldo positivo de Abril antes de
            // determinar si queda algún crédito real. Sin este criterio, el sistema
            // ve el saldo bruto de Abril (+700k) sin ver los -700k de Mayo ya
            // registrados, y aplica un descuento fantasma.
            $histSaldo = Factura::where('aliado_id', $aliadoId)
                ->where('empresa_id', $empresaId)
                ->whereIn('estado', ['pagada', 'prestamo', 'abono'])
                ->whereNull('deleted_at')
                ->sum('saldo_proximo');
            // Solo aplicar como crédito si el neto es estrictamente positivo, y
            // descontando lo que el aliado ya dio por consumido (SaldoAjuste).
            $saldoEmpresaAplicar = max(0, (int) $histSaldo
                - \App\Models\SaldoAjuste::totalDeEmpresa($aliadoId, (int) $empresaId));
        }

        // ── Anti-duplicado: verificar por contrato_id (no por cédula+RS)
        // Permite contratos distintos aunque compartan la misma Razón Social.
        // Bloquea solo si el MISMO contrato ya tiene una factura del MISMO tipo en el período.
        // EXCEPCIÓN: contratos retirados con factura numero_factura=0 → se procesan con lógica de retiro facturable.
        $contratoIdsLote = $contratosCargados->pluck('id')->toArray();
        $facturasDuplicadasLote = Factura::where('aliado_id', $aliadoId)
            ->whereIn('contrato_id', $contratoIdsLote)
            ->where('mes', $mes)
            ->where('anio', $anio)
            ->whereNotIn('estado', ['anulada'])
            ->get(['id', 'contrato_id', 'tipo', 'estado', 'numero_factura'])
            ->groupBy('contrato_id');

        // ── Bloqueo del lote completo si alguno ya está facturado ─────────────
        // Antes se omitía el duplicado en silencio y se seguía facturando el resto.
        // El problema: el pago recibido se reparte proporcionalmente entre los
        // contratos del lote y el ÚLTIMO no omitido se lleva el residuo completo
        // ($totalPagoEfectivo - $efAcum). Como el omitido nunca acumula, su parte
        // del dinero caía sobre la factura creada y generaba un saldo a favor
        // falso (caso empresa 1030, ago-2026: 2 personas seleccionadas, 1 omitida,
        // $11.593.100 cobrados sobre una factura de $11.172.905).
        // Ahora no se crea nada: el usuario anula la factura previa o quita a esa
        // persona de la selección y vuelve a facturar.
        $duplicadosLote = $this->_detectarDuplicadosLote(
            $aliadoId,
            $contratosCargados,
            $mes,
            $anio,
            $validated['tipo'],
            $validated['indep_modo'] ?? 'normal'
        );
        if (! empty($duplicadosLote)) {
            $nombres = collect($duplicadosLote)
                ->map(fn ($d) => trim($d['nombre']) !== '' ? trim($d['nombre']) : $d['cedula'])
                ->join(', ');

            return response()->json([
                'ok' => false,
                'duplicados' => true,
                'mensaje' => 'No se generó ninguna factura. Ya existe factura para '
                    .$mes.'/'.$anio.' de: '.$nombres
                    .'. Anúlela o quite a esa(s) persona(s) de la selección antes de facturar.',
                'omitidos' => $duplicadosLote,
            ], 422);
        }

        // ── Saldo a favor que el lote trae de meses anteriores ─────────────
        // Es lo único que justifica que una factura PAGADA quede con saldo
        // negativo: el cliente pagó de más antes y ahora abona menos. Se usa
        // como tolerancia en la guarda de pago incompleto del final de la
        // transacción. En empresa el crédito ya viene calculado arriba.
        if (! $aplicarSaldo) {
            // Sin crédito aplicado, el cliente paga el total: la guarda de pago
            // incompleto no puede tolerar una diferencia que nadie va a cubrir.
            $favorPrevioLote = 0;
        } elseif ($empresaId) {
            $favorPrevioLote = $saldoEmpresaAplicar;
        } else {
            $favorContratos = (int) Factura::where('aliado_id', $aliadoId)
                ->whereIn('contrato_id', $validated['contratos'])
                ->whereNull('empresa_id')
                ->whereIn('estado', ['pagada', 'prestamo', 'abono'])
                ->whereNotNull('saldo_proximo')
                ->where(fn ($q) => $q->where('anio', '<', $anio)
                    ->orWhere(fn ($q2) => $q2->where('anio', $anio)->where('mes', '<', $mes)))
                ->sum('saldo_proximo');

            // Lo que el aliado ya dio por consumido (SaldoAjuste) no vuelve a
            // ofrecerse como crédito; un ajuste nunca convierte saldo en deuda.
            $favorPrevioLote = $favorContratos > 0
                ? max(0, $favorContratos - $this->ajustesDeContratos($aliadoId, $validated['contratos']))
                : 0;
        }

        DB::transaction(function () use (
            $validated, $aliadoId, $np, $mes, $anio,
            $esMasivo, $favorPrevioLote,
            &$facturasCreadas, &$omitidos, &$nPlanosPorRS,
            $totalPagoConsig, $totalPagoEfectivo, $totalPagoPrestamo,
            $totalAnticipo, $anticiposSeleccionados,
            $consignacionesData, $totalesRealesPorContrato, $granTotalReal, $batchNumeroFactura,
            $otrosPorContrato, $otrosAdmonPorContrato,
            &$efAcum, &$csAcum, &$prAcum, &$sfAcum, &$antAcum,
            $saldoEmpresaAplicar, &$contratosPendientes, $contratosCargados, $facturasDuplicadasLote,
            $manualSsPorContrato, $facturasRetiro0Lote, $incluirAdmonRetiroCorto,
            $fechaPagoRecibo, $moraManualLote, $moraPorContrato
        ) {
            foreach ($validated['contratos'] as $contratoId) {
                $contrato = $contratosCargados->get($contratoId);
                if (! $contrato) {
                    continue;
                }

                // ─── Validación anti-duplicado por CONTRATO ───────────────
                // Bloquea si el MISMO contrato_id ya tiene una factura del MISMO tipo
                // en el mismo período (ej: 2 planillas del mismo contrato en junio).
                // Permite contratos distintos aunque compartan la misma Razón Social.
                $facturasDup = $facturasDuplicadasLote->get($contrato->id) ?: collect();
                $tipoSolicitado = $validated['tipo'];

                // ─── Detectar modo "ambos" (afiliación + planilla) ────────
                $indepModo = $validated['indep_modo'] ?? 'normal';
                $esIndVenc = (int) ($contrato->tipo_modalidad_id) === 10
                    && ! (bool) ($contrato->paga_mes_actual ?? false);
                $esIndActCheck = (bool) ($contrato->paga_mes_actual ?? false);
                $esMesIngresoCheck = false;
                if ($contrato->fecha_ingreso) {
                    $esMesIngresoCheck = ((int) $contrato->fecha_ingreso->month === $mes
                                      && (int) $contrato->fecha_ingreso->year === $anio);
                }

                $esAmbos = ($indepModo === 'ambos')
                    && ($esIndVenc || $esIndActCheck)
                    && $esMesIngresoCheck;

                if ($esAmbos) {
                    // ── Verificar que no existan ya afiliación NI planilla para este contrato ──
                    $yaAfil = $facturasDup->contains(fn ($f) => $f->tipo === 'afiliacion');
                    if ($yaAfil) {
                        $omitidos[] = [
                            'cedula' => $contrato->cedula,
                            'nombre' => $contrato->cliente?->primer_nombre.' '.$contrato->cliente?->primer_apellido,
                            'motivo' => "Ya existe una afiliación para {$mes}/{$anio} en este contrato",
                        ];
                        $contratosPendientes--;

                        continue;
                    }

                    // Para I Venc: verificar que no exista planilla en el mes siguiente
                    if ($esIndVenc) {
                        $mesPlan = $mes === 12 ? 1 : $mes + 1;
                        $anioPlan = $mes === 12 ? $anio + 1 : $anio;
                        $yaPlana = Factura::where('aliado_id', $aliadoId)
                            ->where('contrato_id', $contrato->id)
                            ->where('mes', $mesPlan)
                            ->where('anio', $anioPlan)
                            ->where('tipo', 'planilla')
                            ->whereNull('deleted_at')
                            ->exists();
                        if ($yaPlana) {
                            $omitidos[] = [
                                'cedula' => $contrato->cedula,
                                'nombre' => $contrato->cliente?->primer_nombre.' '.$contrato->cliente?->primer_apellido,
                                'motivo' => "Ya existe una planilla para {$mesPlan}/{$anioPlan} en este contrato",
                            ];
                            $contratosPendientes--;

                            continue;
                        }
                    }

                    // ── Calcular proporción de pago para distribución ──────
                    $costoContrato = $totalesRealesPorContrato[$contratoId] ?? 0;
                    $proporcion = $granTotalReal > 0 ? ($costoContrato / $granTotalReal) : 0;
                    $contratosPendientes--;
                    $esUltimoNoOmitido = ($contratosPendientes === 0);

                    if ($esUltimoNoOmitido) {
                        $vConsig = $totalPagoConsig - $csAcum;
                        $vEfectivo = $totalPagoEfectivo - $efAcum;
                        $vPrestamo = $totalPagoPrestamo - $prAcum;
                        $vSaldoFavor = $saldoEmpresaAplicar - $sfAcum;
                        $vAnticipo = $totalAnticipo - $antAcum;
                    } else {
                        $vConsig = (int) round($totalPagoConsig * $proporcion);
                        $vEfectivo = (int) round($totalPagoEfectivo * $proporcion);
                        $vPrestamo = (int) round($totalPagoPrestamo * $proporcion);
                        $vSaldoFavor = (int) round($saldoEmpresaAplicar * $proporcion);
                        $vAnticipo = (int) round($totalAnticipo * $proporcion);
                        $csAcum += $vConsig;
                        $efAcum += $vEfectivo;
                        $prAcum += $vPrestamo;
                        $sfAcum += $vSaldoFavor;
                        $antAcum += $vAnticipo;
                    }

                    // ── Crear par afiliación + planilla ───────────────────
                    $idsCreados = $this->_crearParAfilPlanilla(
                        contrato: $contrato,
                        validated: $validated,
                        mes: $mes,
                        anio: $anio,
                        aliadoId: $aliadoId,
                        batchNumeroFactura: $batchNumeroFactura,
                        np: $np,
                        nPlanosPorRS: $nPlanosPorRS,
                        vConsig: $vConsig,
                        vEfectivo: $vEfectivo,
                        vPrestamo: $vPrestamo,
                        vAnticipo: $vAnticipo,
                        esFirstOfBatch: empty($facturasCreadas),
                        consignacionesData: $consignacionesData,
                        esIndVenc: $esIndVenc,
                        manualSsPorContrato: $manualSsPorContrato,
                    );

                    // Si es un retiro y el par se creó con éxito, retirar contrato
                    $esRetiro = ! empty($validated['es_retiro']);
                    $fechaRetiro = $esRetiro ? ($validated['fecha_retiro'] ?? null) : null;
                    if ($esRetiro && $fechaRetiro && ! empty($idsCreados)) {
                        $contrato->update([
                            'estado' => 'retirado',
                            'fecha_retiro' => $fechaRetiro,
                        ]);

                        Bitacora::registrar(
                            accion: 'updated',
                            modelo: 'Contrato',
                            registroId: $contrato->id,
                            descripcion: "Contrato marcado como retirado con fecha {$fechaRetiro} por facturación de retiro en par Afil+Planilla (Facturas: ".implode(', ', $idsCreados).').',
                            detalle: [
                                'fecha_retiro' => $fechaRetiro,
                                'factura_ids' => $idsCreados,
                            ],
                            alidoId: $aliadoId
                        );
                    }

                    array_push($facturasCreadas, ...$idsCreados);

                    continue; // saltar la lógica normal del foreach
                }

                // ─── Validación anti-duplicado (flujo normal) ─────────────
                // EXCEPCIÓN: contratos retirados con factura 0 pendiente no se bloquean aquí
                // — se procesan en el bloque de retiro facturable de más abajo.
                $tieneRetiroFacturable = $this->_tieneRetiroFacturable($contrato, $facturasRetiro0Lote, $mes, $anio);

                // ── FLUJO ESPECIAL: Retiro facturable (contrato retirado con factura 0) ──────────
                // El usuario seleccionó un contrato retirado desde la vista empresa.
                // La factura 0 (creada al marcar el retiro individual) se anula y se crea una real.
                if ($tieneRetiroFacturable) {
                    $facturaRetiroOrigen = $facturasRetiro0Lote->get($contrato->id);

                    // Calcular distribución de pago proporcional
                    $costoContrato = $totalesRealesPorContrato[$contratoId] ?? 0;
                    $proporcion = $granTotalReal > 0 ? ($costoContrato / $granTotalReal) : 0;
                    $contratosPendientes--;
                    $esUltimoNoOmitido = ($contratosPendientes === 0);

                    if ($esUltimoNoOmitido) {
                        $vConsig = $totalPagoConsig - $csAcum;
                        $vEfectivo = $totalPagoEfectivo - $efAcum;
                        $vPrestamo = $totalPagoPrestamo - $prAcum;
                        $vSaldoFavor = $saldoEmpresaAplicar - $sfAcum;
                        $vAnticipo = $totalAnticipo - $antAcum;
                    } else {
                        $vConsig = (int) round($totalPagoConsig * $proporcion);
                        $vEfectivo = (int) round($totalPagoEfectivo * $proporcion);
                        $vPrestamo = (int) round($totalPagoPrestamo * $proporcion);
                        $vSaldoFavor = (int) round($saldoEmpresaAplicar * $proporcion);
                        $vAnticipo = (int) round($totalAnticipo * $proporcion);
                        $csAcum += $vConsig;
                        $efAcum += $vEfectivo;
                        $prAcum += $vPrestamo;
                        $sfAcum += $vSaldoFavor;
                        $antAcum += $vAnticipo;
                    }

                    // Tomar SS de la factura 0 (ya calculados al marcar el retiro)
                    $diasRetiroReal = (int) ($facturaRetiroOrigen->dias_cotizados ?? 0);
                    $vEpsRet = (int) ($facturaRetiroOrigen->v_eps ?? 0);
                    $vArlRet = (int) ($facturaRetiroOrigen->v_arl ?? 0);
                    $vAfpRet = (int) ($facturaRetiroOrigen->v_afp ?? 0);
                    $vCajaRet = (int) ($facturaRetiroOrigen->v_caja ?? 0);
                    $totalSSRet = $vEpsRet + $vArlRet + $vAfpRet + $vCajaRet;

                    // Admon: solo se cobra si días > 3, o si el usuario marcó "incluir admon retiro corto"
                    $admonRetiro = 0;
                    $adminAsesorRetiro = 0;
                    if ($diasRetiroReal > 3 || $incluirAdmonRetiroCorto) {
                        $admonRetiro = intval($contrato->administracion ?? 0);
                        $adminAsesorRetiro = intval($contrato->admon_asesor ?? 0);
                    }

                    // IVA sobre la admon que efectivamente se cobra en el retiro
                    $ivaRetiro = \App\Services\IvaService::deFactura(
                        \App\Services\IvaService::aplicaContrato($contrato),
                        $admonRetiro,
                        $adminAsesorRetiro,
                        0
                    );

                    // Parte de los "otros" del lote que le toca a este contrato. Se
                    // reservo en el reparto de arriba: si no se cobra aqui, el cliente
                    // paga un total que la factura no tiene y sobra como saldo a favor.
                    $otrosPlanillaRet = (int) ($otrosPorContrato[$contratoId] ?? 0);
                    $otrosAdmonRet = (int) ($otrosAdmonPorContrato[$contratoId] ?? 0);

                    $totalRetiro = $totalSSRet + $admonRetiro + $adminAsesorRetiro + $ivaRetiro
                        + $otrosPlanillaRet + $otrosAdmonRet;

                    // n_plano: reutilizar el del plano de retiro existente
                    $rsIdRet = $contrato->razon_social_id;
                    if ($rsIdRet && ! isset($nPlanosPorRS[$rsIdRet])) {
                        $nPlanosPorRS[$rsIdRet] = static::_nPlanoParaRS($aliadoId, $rsIdRet, $mes, $anio);
                    }
                    $nPlanoRetiro = $rsIdRet ? ($nPlanosPorRS[$rsIdRet] ?? null) : null;

                    // Crear factura real del retiro
                    $facturaReal = Factura::create([
                        'aliado_id' => $aliadoId,
                        'numero_factura' => $batchNumeroFactura,
                        'tipo' => 'planilla',
                        'cedula' => $contrato->cedula,
                        'contrato_id' => $contrato->id,
                        'empresa_id' => $validated['empresa_id'] ?? null,
                        'mes' => $mes,
                        'anio' => $anio,
                        'fecha_pago' => $fechaPagoRecibo,
                        'estado' => $validated['estado'],
                        'es_prestamo' => $validated['estado'] === 'prestamo',
                        'forma_pago' => $validated['forma_pago'],
                        'valor_consignado' => $vConsig,
                        'valor_efectivo' => $vEfectivo,
                        'valor_prestamo' => $vPrestamo,
                        'dias_cotizados' => $diasRetiroReal,
                        'v_eps' => $vEpsRet,
                        'v_arl' => $vArlRet,
                        'v_afp' => $vAfpRet,
                        'v_caja' => $vCajaRet,
                        'total_ss' => $totalSSRet,
                        'admon' => $admonRetiro,
                        'admin_asesor' => $adminAsesorRetiro,
                        'seguro' => 0,
                        'afiliacion' => 0,
                        'iva' => $ivaRetiro,
                        'mora' => 0,
                        'otros' => $otrosPlanillaRet,
                        'otros_admon' => $otrosAdmonRet,
                        'mensajeria' => 0,
                        'total' => $totalRetiro,
                        'anticipo_aplicado' => $vAnticipo,
                        'np' => $np,
                        'n_plano' => $nPlanoRetiro,
                        'razon_social_id' => $contrato->razon_social_id,
                        'usuario_id' => Auth::id(),
                        'observacion' => $validated['observacion'] ?? null,
                        // Enlace con la factura 0 original para poder reactivarla si se anula esta
                        'factura_retiro_origen_id' => $facturaRetiroOrigen->id,
                    ]);

                    // saldo_proximo de la factura real del retiro
                    $pagadoRealRet = (int) $facturaReal->valor_consignado
                                  + (int) $facturaReal->valor_efectivo
                                  + (int) $facturaReal->anticipo_aplicado;
                    $facturaReal->update(['saldo_proximo' => $pagadoRealRet - $totalRetiro]);

                    // Soft-delete de la factura 0 (motivo: cobrada en la factura real)
                    $facturaRetiroOrigen->motivo_anulacion = "Cobrado en factura #{$batchNumeroFactura}";
                    $facturaRetiroOrigen->anulado_por = Auth::id();
                    $facturaRetiroOrigen->save();
                    $facturaRetiroOrigen->delete(); // SoftDeletes → deleted_at

                    // Actualizar el plano de retiro: ahora apunta a la factura real
                    Plano::where('factura_id', $facturaRetiroOrigen->id)
                        ->whereNull('deleted_at')
                        ->update([
                            'factura_id' => $facturaReal->id,
                            'numero_factura' => $batchNumeroFactura,
                            'n_plano' => $nPlanoRetiro,
                        ]);

                    // Bitácora
                    Bitacora::registrar(
                        accion: 'updated',
                        modelo: 'Factura',
                        registroId: $facturaReal->id,
                        descripcion: "Retiro de {$contrato->cedula} cobrado en factura #{$batchNumeroFactura}. Factura origen (retiro) #{$facturaRetiroOrigen->id} anulada.",
                        detalle: [
                            'factura_retiro_origen_id' => $facturaRetiroOrigen->id,
                            'dias_retiro' => $diasRetiroReal,
                            'admon_cobrada' => $admonRetiro,
                        ],
                        alidoId: $aliadoId
                    );

                    // Guardar consignaciones (solo primera factura del lote)
                    if (empty($facturasCreadas)) {
                        foreach ($consignacionesData as $cs) {
                            $valorCs = (int) $cs['valor'];
                            if ($valorCs <= 0) {
                                continue;
                            }
                            \App\Models\Consignacion::create([
                                'aliado_id' => $aliadoId,
                                'factura_id' => $facturaReal->id,
                                'banco_cuenta_id' => (int) $cs['banco_cuenta_id'],
                                'fecha' => $cs['fecha'] ?? now()->toDateString(),
                                'valor' => $valorCs,
                                'referencia' => $cs['referencia'] ?? null,
                                'confirmado' => false,
                                'usuario_id' => Auth::id(),
                            ]);
                        }
                    }

                    $facturasCreadas[] = $facturaReal->id;

                    continue; // saltar la lógica normal del foreach
                }

                $yaExiste = $facturasDup->contains(fn ($f) => $f->tipo === $tipoSolicitado);

                if ($yaExiste) {
                    $omitidos[] = [
                        'cedula' => $contrato->cedula,
                        'nombre' => $contrato->cliente?->primer_nombre.' '.$contrato->cliente?->primer_apellido,
                        'motivo' => "Ya existe una {$tipoSolicitado} para {$mes}/{$anio} en este contrato",
                    ];
                    $contratosPendientes--; // descontar aunque se omita

                    continue; // saltar este contrato
                }

                // Mes actual: cobra afiliación + planilla el mismo mes de ingreso.
                // Sin el flag: solo afiliación el primer mes.
                $esIndAct = (bool) ($contrato->paga_mes_actual ?? false);
                $tipoForzado = $validated['tipo'];

                if ($contrato->fecha_ingreso) {
                    $mesIngreso = (int) $contrato->fecha_ingreso->month;
                    $anioIngreso = (int) $contrato->fecha_ingreso->year;
                    $esMesIngreso = ($mes === $mesIngreso && $anio === $anioIngreso);

                    if ($esMesIngreso && ! $esIndAct) {
                        // Sin mes actual, el primer mes es solo afiliación.
                        // Con mes actual: tipo=planilla y la afiliación se suma al
                        // total más abajo.
                        $tipoForzado = 'afiliacion';
                    }
                }

                // ── Gestión ARL (id=15): SIEMPRE afiliación, nunca planilla ──
                if ((int) $contrato->tipo_modalidad_id === 15) {
                    $tipoForzado = 'afiliacion';
                }

                // ── Solo seguro: SIEMPRE la mensualidad, nunca afiliación ──
                // Sin esto, el mes de ingreso lo tomaría por afiliación de empresa y
                // cobraría costo de afiliación en vez del seguro.
                if ($contrato->esSoloSeguro()) {
                    $tipoForzado = 'planilla';
                }

                // ─── Detectar primer mes de quien paga el mes actual ───────
                // En el mes de ingreso paga afiliación + planilla juntas
                $esIndActPrimerMes = $esIndAct && isset($esMesIngreso) && $esMesIngreso;

                // ─── Tipo, días y SS ───────────────────────────────────────
                $esAfiliacion = $tipoForzado === 'afiliacion';
                // Para I ACT primer mes: días = activos del mes de ingreso (no 0)
                if ($esIndActPrimerMes) {
                    $diasCotizar = max(1, 30 - (int) $contrato->fecha_ingreso->day + 1);
                } elseif ($esAfiliacion) {
                    $diasCotizar = 0;
                } else {
                    $diasCotizar = $this->calcularDias($contrato, $mes, $anio);
                }

                // SS = 0 en afiliación pura (I VENC, empresa);
                // Para I ACT primer mes se calcula con días reales del mes de ingreso.
                // Si hay retiro, sobreescribir los días con los del retiro.
                $esRetiro = ! empty($validated['es_retiro']);
                $fechaRetiro = $esRetiro ? ($validated['fecha_retiro'] ?? null) : null;
                $diasRetiro = $esRetiro ? (int) ($validated['dias_retiro'] ?? $diasCotizar) : null;

                if ($esRetiro && $diasRetiro !== null && ! $esAfiliacion) {
                    // Retiro: usar los días proporcionales indicados por el usuario
                    $diasCotizar = $diasRetiro;
                }

                // ── Retiro Pendiente desde vista empresa ─────────────────────────────────
                // Si el contrato vigente tiene fecha_retiro_pendiente, se procesa como retiro:
                // los días cotizados son los del día del mes de esa fecha, se marca el contrato
                // como retirado al crear la factura, y se limpia el campo fecha_retiro_pendiente.
                $tieneRetiroPendiente = ($contrato->estado === 'vigente' && $contrato->fecha_retiro_pendiente !== null);
                if ($tieneRetiroPendiente && ! $esRetiro && ! $esAfiliacion) {
                    $esRetiro = true;
                    $fechaRetiro = $contrato->fecha_retiro_pendiente->toDateString();
                    $diasRetiro = (int) $contrato->fecha_retiro_pendiente->day;
                    $diasCotizar = $diasRetiro;
                    // La decisión de cobrar admon ya fue tomada por el aliado al registrar el retiro
                    $cobrarAdmonRetiroPendiente = (bool) ($contrato->retiro_pendiente_cobrar_admon ?? false);
                }

                // ── Fuente de verdad: calcularCotizacion() del modelo ──────────────────────
                // Usar el mismo método que la UI para que total facturado = estimación exacta.
                $tieneIva = \App\Services\IvaService::aplicaContrato($contrato);

                if ($esAfiliacion && ! $esIndActPrimerMes) {
                    $calcSS = ['eps' => 0, 'arl' => 0, 'afp' => 0, 'caja' => 0, 'parafiscales' => 0];
                } else {
                    $cotizacion = $contrato->calcularCotizacion($diasCotizar, $tieneIva);
                    $calcSS = [
                        'eps' => (int) ($cotizacion['eps'] ?? 0),
                        'arl' => (int) ($cotizacion['arl'] ?? 0),
                        'afp' => (int) ($cotizacion['pen'] ?? 0),
                        'caja' => (int) ($cotizacion['caja'] ?? 0),
                        'parafiscales' => (int) ($cotizacion['parafiscales'] ?? 0),
                    ];
                }

                // Override manual de SS desde la UI:
                // 1) Modo individual (1 contrato): usa v_*_manual directamente.
                // 2) Multi-contrato desde form individual: usa manual_ss_por_contrato[id].
                //    Solo se aplica al contrato que tiene la entrada; el otro auto-calcula.
                // 3) Modo masivo empresa: sin override (totales del lote).
                $esModoIndividual = count($validated['contratos']) === 1;
                if (! $esAfiliacion) {
                    if ($esModoIndividual) {
                        // Modo individual clásico
                        if (isset($validated['v_eps_manual'])) {
                            $calcSS['eps'] = intval($validated['v_eps_manual']);
                        }
                        if (isset($validated['v_arl_manual'])) {
                            $calcSS['arl'] = intval($validated['v_arl_manual']);
                        }
                        if (isset($validated['v_afp_manual'])) {
                            $calcSS['afp'] = intval($validated['v_afp_manual']);
                        }
                        if (isset($validated['v_caja_manual'])) {
                            $calcSS['caja'] = intval($validated['v_caja_manual']);
                        }
                    } elseif (! empty($manualSsPorContrato[(string) $contratoId])) {
                        // Multi-contrato desde form individual: override específico por contrato
                        $ssMap = $manualSsPorContrato[(string) $contratoId];
                        if (isset($ssMap['eps'])) {
                            $calcSS['eps'] = intval($ssMap['eps']);
                        }
                        if (isset($ssMap['arl'])) {
                            $calcSS['arl'] = intval($ssMap['arl']);
                        }
                        if (isset($ssMap['afp'])) {
                            $calcSS['afp'] = intval($ssMap['afp']);
                        }
                        if (isset($ssMap['caja'])) {
                            $calcSS['caja'] = intval($ssMap['caja']);
                        }
                    }
                }

                // Afiliación:
                // • I ACT primer mes: se incluye SIEMPRE junto con SS (pago conjunto)
                // • Afiliación pura (I VENC, empresa): total = costo_afiliacion + seguro
                // • Planilla normal: no se incluye afiliación
                $afiliacion = ($esAfiliacion || $esIndActPrimerMes)
                    ? (int) ($contrato->costo_afiliacion ?? 0)
                    : 0;
                $seguro = (int) ($contrato->seguro ?? 0);

                // Admon:
                // • Afiliación pura (I VENC, empresa): sin admon mensual
                // • I ACT primer mes y planilla normal: con admon completa
                // • Retiro Pendiente: usa la decisión guardada por el aliado (retiro_pendiente_cobrar_admon)
                if ($esAfiliacion && ! $esIndActPrimerMes) {
                    $admon = 0;
                    $adminAsesor = 0;
                } elseif (isset($tieneRetiroPendiente) && $tieneRetiroPendiente) {
                    // Usar la decisión del aliado al registrar el retiro pendiente
                    $admon = (isset($cobrarAdmonRetiroPendiente) && $cobrarAdmonRetiroPendiente)
                        ? intval($contrato->administracion ?? 0) : 0;
                    $adminAsesor = (isset($cobrarAdmonRetiroPendiente) && $cobrarAdmonRetiroPendiente)
                        ? intval($contrato->admon_asesor ?? 0) : 0;
                } else {
                    $admon = intval($contrato->administracion ?? 0);
                    $adminAsesor = intval($contrato->admon_asesor ?? 0);
                }
                // Parte del lote que le toca a este contrato (ver el reparto arriba).
                $otrosAdmon = (int) ($otrosAdmonPorContrato[$contratoId] ?? 0);
                $otrosPlanilla = (int) ($otrosPorContrato[$contratoId] ?? 0);

                // Solo seguro: el mes vale el seguro y nada más. Se fuerza aquí, después de
                // los overrides manuales, para que ninguna casilla de la UI meta seguridad
                // social en una factura que no cotiza nada.
                if ($contrato->esSoloSeguro()) {
                    $calcSS = ['eps' => 0, 'arl' => 0, 'afp' => 0, 'caja' => 0, 'parafiscales' => 0];
                    $admon = 0;
                    $adminAsesor = 0;
                    $afiliacion = 0;
                    $diasCotizar = 0;
                }

                $totalSS = $calcSS['eps'] + $calcSS['arl'] + $calcSS['afp'] + $calcSS['caja'] + ($calcSS['parafiscales'] ?? 0);

                // IVA sobre administración + costo de afiliación (ver IvaService).
                // En afiliación pura la admon es 0, así que grava solo la afiliación;
                // en I ACT primer mes grava ambas. Usa round() igual que
                // calcularCotizacion() del modelo (no ceil).
                $iva = \App\Services\IvaService::deFactura($tieneIva, $admon, $adminAsesor, $afiliacion);

                // total = BRUTO (SS + admon + seguro + IVA + afiliacion + otros).
                // El anticipo (saldo_a_favor) y la deuda previa (saldo_pendiente) se guardan
                // en columnas separadas y el sistema acumulativo de saldo_proximo los maneja.
                $total = $totalSS + $admon + $adminAsesor + $otrosAdmon + $otrosPlanilla + $seguro + $afiliacion + $iva;

                // ─── Mora al cliente ────────────────────────────────────────
                // La mora viene del modal (pre-calculada + editable por el usuario).
                // Individual: manda el valor del modal tal cual.
                // Masivo: si el usuario la escribió, manda esa y llega ya repartida
                // en $moraPorContrato; si no la tocó, se calcula por RS con
                // MoraClienteService, que es lo que el modal muestra sumado.
                $moraCliente = 0;
                if ($contrato->esSoloSeguro()) {
                    $moraCliente = 0;   // sin planilla que pagar tarde no hay mora
                } elseif ($esModoIndividual) {
                    // Modo individual: usar el valor del modal (puede ser 0 o el calculado)
                    // Si es afiliación pura, forzar mora=0 sin importar lo que envíe el frontend
                    $moraCliente = $esAfiliacion ? 0 : (int) ($validated['mora'] ?? 0);
                } elseif ($moraManualLote) {
                    // Modo masivo con mora escrita en el modal: la parte que le tocó
                    // en el reparto de arriba. La decisión del usuario manda.
                    $moraCliente = (int) ($moraPorContrato[$contratoId] ?? 0);
                } else {
                    // Modo masivo (empresa): calcular mora por contrato si aplica
                    // Afiliaciones nunca generan mora (no hay pago de planilla)
                    if (! $esAfiliacion) {
                        $rsNit = $contrato->nitParaMora();
                        $rsDiaH = $contrato->diaHabilParaMora();
                        if ($rsNit && $totalSS > 0) {
                            $moraInfo = MoraClienteService::calcular($aliadoId, $rsNit, $rsDiaH, $totalSS, $mes, $anio);
                            $moraCliente = $moraInfo['mora']; // aplicar tramos automáticamente
                        }
                    }
                }

                $total += $moraCliente;

                // ─── Calcular distribución de afiliación ───────────────────
                $distAdmon = $distAsesor = $distRetiro = $distUtilidad = $distEncargado = 0;
                if ($esAfiliacion && $afiliacion > 0) {
                    // Si el frontend envió valores manuales, usarlos.
                    $hasManual = isset($validated['dist_asesor']) || isset($validated['dist_retiro'])
                              || isset($validated['dist_encargado']) || isset($validated['dist_admon']);

                    // El modal (public/js/modal_facturar_v2.js) manda SIEMPRE las cuatro claves,
                    // en cero cuando nadie tocó nada, así que isset() las daba por manuales y
                    // todo terminaba en utilidad. Un reparto en ceros no es una decisión del
                    // usuario: si el contrato tiene tarifario, manda el tarifario.
                    // Los contratos SIN tarifario conservan el comportamiento anterior.
                    $manualEnCeros = ((int) ($validated['dist_asesor'] ?? 0)
                                    + (int) ($validated['dist_retiro'] ?? 0)
                                    + (int) ($validated['dist_encargado'] ?? 0)
                                    + (int) ($validated['dist_admon'] ?? 0)) === 0;

                    if ($hasManual && $manualEnCeros && $contrato->afiliacion_asesor !== null) {
                        $hasManual = false;
                    }

                    if ($hasManual) {
                        $distAsesor = (int) ($validated['dist_asesor'] ?? 0);
                        $distRetiro = (int) ($validated['dist_retiro'] ?? 0);
                        $distEncargado = (int) ($validated['dist_encargado'] ?? 0);
                        $distAdmonRaw = (int) ($validated['dist_admon'] ?? 0);
                        // dist_admon en la tabla = empresa admon puro
                        $distAdmon = $distAdmonRaw;

                        if ((int) $contrato->tipo_modalidad_id === 15) {
                            $distRetiro = 0;
                        }

                        // Recalcular utilidad = total - todos los demás
                        $distUtilidad = max(0, $afiliacion - $distAsesor - $distRetiro - $distEncargado - $distAdmon);
                    } else {
                        // Contrato con tarifario (afiliacion_asesor no nulo): el reparto sale
                        // de lo que quedó congelado en el contrato. Ver TarifaAsesorService.
                        $dist = \App\Services\TarifaAsesorService::distribucionFactura(
                            $contrato, $afiliacion, $mes, $anio
                        );

                        if ($dist) {
                            $distAdmon = $dist['admon'];
                            $distAsesor = $dist['asesor'];
                            $distRetiro = $dist['retiro'];
                            $distUtilidad = $dist['utilidad'];
                            $distEncargado = $dist['encargado'];
                        } else {
                            // Contrato anterior al tarifario: camino de siempre.
                            $cfg = \App\Models\ConfiguracionAliado::paraAliado($aliadoId, $contrato->plan_id);
                            if ($cfg) {
                                $dist = $cfg->calcularDistribucion($afiliacion, $contrato->asesor ?? null);
                                $distAdmon = $dist['admon'];
                                $distAsesor = $dist['asesor'];
                                $distRetiro = $dist['retiro'];
                                $distUtilidad = $dist['utilidad'];

                                if ((int) $contrato->tipo_modalidad_id === 15) {
                                    $distUtilidad += $distRetiro;
                                    $distRetiro = 0;
                                }
                            }
                        }
                    }
                }

                // ─── n_plano compartido por RS en este lote ────────────────
                // Todos los contratos de la misma RS deben tener el mismo n_plano.
                // EXCEPCIÓN: Ingreso-Retiro (tipo_modalidad_id=12) con retiro usa n_plano=100
                // para separarlo de los planos normales y facilitar el control de pagos.
                // Si el IR es solo ingreso (sin retiro), usa el plano normal por RS.
                $rsId = $contrato->razon_social_id;
                if ((int) $contrato->tipo_modalidad_id === 12 && $esRetiro) {
                    $nPlanoFactura = 100; // IR retiro → plano 100
                } else {
                    if ($rsId && ! isset($nPlanosPorRS[$rsId])) {
                        $nPlanosPorRS[$rsId] = static::_nPlanoParaRS($aliadoId, $rsId, $mes, $anio);
                    }
                    $nPlanoFactura = $rsId ? ($nPlanosPorRS[$rsId] ?? null) : null;
                }

                // --- Distribucion PROPORCIONAL entre contratos del batch ---
                // Ef, consignacion, saldo a favor y anticipo en proporción al costo real de la factura.
                // El último contrato recibe el residuo acumulado para que sumen exacto.
                $nContratos = max(1, count($validated['contratos']));
                $contratosPendientes--;
                $esUltimoNoOmitido = ($contratosPendientes === 0);
                $vSaldoFavor = 0; // Inicializar siempre (evita Undefined variable)

                $costoContrato = $totalesRealesPorContrato[$contratoId] ?? 0;
                $proporcion = $granTotalReal > 0 ? ($costoContrato / $granTotalReal) : 0;

                if ($esUltimoNoOmitido) {
                    // Ultimo: residuo exacto
                    $vConsig = $totalPagoConsig - $csAcum;
                    $vEfectivo = $totalPagoEfectivo - $efAcum;
                    $vPrestamo = $totalPagoPrestamo - $prAcum;
                    $vSaldoFavor = $saldoEmpresaAplicar - $sfAcum;
                    $vAnticipo = $totalAnticipo - $antAcum;
                } else {
                    $vConsig = (int) round($totalPagoConsig * $proporcion);
                    $vEfectivo = (int) round($totalPagoEfectivo * $proporcion);
                    $vPrestamo = (int) round($totalPagoPrestamo * $proporcion);
                    $vSaldoFavor = (int) round($saldoEmpresaAplicar * $proporcion);
                    $vAnticipo = (int) round($totalAnticipo * $proporcion);

                    $csAcum += $vConsig;
                    $efAcum += $vEfectivo;
                    $prAcum += $vPrestamo;
                    $sfAcum += $vSaldoFavor;
                    $antAcum += $vAnticipo;
                }

                $factura = Factura::create([
                    'aliado_id' => $aliadoId,
                    'numero_factura' => $batchNumeroFactura,
                    'tipo' => $tipoForzado,
                    'cedula' => $contrato->cedula,
                    'contrato_id' => $contrato->id,
                    'mes' => $mes,
                    'anio' => $anio,
                    'fecha_pago' => $fechaPagoRecibo,
                    'estado' => $validated['estado'],
                    'es_prestamo' => $validated['estado'] === 'prestamo',
                    'forma_pago' => $validated['forma_pago'],
                    'valor_consignado' => $vConsig,
                    'valor_efectivo' => $vEfectivo,
                    'valor_prestamo' => $vPrestamo,
                    'otros' => $otrosPlanilla,
                    'otros_admon' => $otrosAdmon,
                    'mensajeria' => (int) ($validated['mensajeria'] ?? 0),
                    'dias_cotizados' => $diasCotizar,
                    'v_eps' => $calcSS['eps'],
                    'v_arl' => $calcSS['arl'],
                    'v_afp' => $calcSS['afp'],
                    'v_caja' => $calcSS['caja'],
                    'v_parafiscales' => $calcSS['parafiscales'] ?? 0,
                    'total_ss' => $totalSS,
                    'admon' => $admon,
                    'admin_asesor' => $adminAsesor,
                    'seguro' => $seguro,
                    'afiliacion' => $afiliacion,
                    'iva' => $iva,
                    'total' => max(0, $total),
                    'dist_admon' => $distAdmon,
                    'dist_asesor' => $distAsesor,
                    'dist_retiro' => $distRetiro,
                    'dist_utilidad' => $distUtilidad,
                    'dist_encargado' => $distEncargado,
                    'np' => $np,
                    'n_plano' => $nPlanoFactura,
                    'empresa_id' => $validated['empresa_id'] ?? null,
                    'razon_social_id' => $contrato->razon_social_id,
                    'usuario_id' => Auth::id(),
                    'observacion' => $validated['observacion'] ?? null,
                    // Mora al cliente (no es ingreso — se reporta separado en SS)
                    'mora' => $moraCliente,
                    // Anticipo: pagos previos registrados antes de la factura.
                    // Se guarda separado de valor_efectivo/consignado para evitar
                    // doble conteo en el cuadre diario (ese dinero ya se contabilizó
                    // en el mes en que se recibió el anticipo).
                    'anticipo_aplicado' => $vAnticipo,
                ]);

                // ─── Guardar consignaciones bancarias ──────────────────────
                // Las consignaciones representan comprobantes bancarios reales.
                // Se guardan UNA SOLA VEZ (en la primera factura del lote) con
                // el valor real. Las demás facturas del NP solo registran
                // valor_consignado proporcional en su campo, sin fila en consignaciones.
                if (empty($facturasCreadas)) {
                    // Esta es la PRIMERA factura del lote → guardar consignaciones reales
                    foreach ($consignacionesData as $cs) {
                        $valorCs = (int) $cs['valor'];
                        if ($valorCs <= 0) {
                            continue;
                        }
                        \App\Models\Consignacion::create([
                            'aliado_id' => $aliadoId,
                            'factura_id' => $factura->id,
                            'banco_cuenta_id' => (int) $cs['banco_cuenta_id'],
                            'fecha' => $cs['fecha'] ?? now()->toDateString(),
                            'valor' => $valorCs,
                            'referencia' => $cs['referencia'] ?? null,
                            'confirmado' => false,
                            'usuario_id' => Auth::id(),
                        ]);
                    }
                }
                // Las facturas 2..N del lote NO crean filas en consignaciones;
                // su valor_consignado proporcional ya quedó en el campo de la factura.

                // ─── Calcular saldo_proximo ────────────────────────────────
                // saldo_proximo = valor_efectivo + valor_consignado - total_bruto
                //
                // El anticipo (saldo_a_favor) ya está acumulado en el historial de
                // facturas anteriores como saldo_proximo positivo.  Sumarlo aquí
                // generaría doble conteo → se usa SIEMPRE la misma fórmula base.
                //
                // Con SUM acumulativo (SUM saldo_proximo de empresa):
                //   Mes anterior pagó de más → sp = +X
                //   Este mes aplica anticipo, paga solo diferencia → sp = ef+cs-total
                //   Acumulado = +X + (ef+cs-total) → refleja correctamente el neto.
                //
                // PRÉSTAMO CON PAGO PARCIAL: si la persona consignó $587k sobre $587.8k,
                // el saldo real pendiente es solo $800, no el total bruto completo.
                // Se usa la misma fórmula (pagadoReal - total) en todos los casos.
                // pagadoReal = efectivo nuevo + consig nuevo + anticipo aplicado
                $pagadoReal = (int) $factura->valor_consignado
                            + (int) $factura->valor_efectivo
                            + (int) $factura->anticipo_aplicado;
                // Con crédito de empresa se grababa `-$vSaldoFavor` sin mirar el
                // pago: el crédito se daba por consumido aunque la empresa
                // consignara el total completo. HECTOR JOSE CASTAÑEDA (Formalizate,
                // recibo #92216, 15-sep-2026) pagó sus $15.250.000 enteros y perdió
                // $1.122.144 a favor. Lo pagado menos el total ya consume
                // exactamente el crédito que el pago necesitó: si la empresa pagó
                // el total menos su crédito, el saldo queda en menos el crédito; si
                // pagó completo, queda en cero y el crédito sigue vivo.
                $saldoProximo = $pagadoReal - (int) $factura->total;
                $factura->update(['saldo_proximo' => $saldoProximo]);

                // Si es un retiro y la factura se generó en estado pagada/prestamo, retirar contrato
                if ($esRetiro && $fechaRetiro && in_array($factura->estado, [Factura::ESTADO_PAGADA, Factura::ESTADO_PRESTAMO])) {
                    $updateData = [
                        'estado' => 'retirado',
                        'fecha_retiro' => $fechaRetiro,
                    ];
                    // Si era un retiro pendiente, limpiar los campos de pendiente
                    if (isset($tieneRetiroPendiente) && $tieneRetiroPendiente) {
                        $updateData['fecha_retiro_pendiente'] = null;
                        $updateData['retiro_pendiente_cobrar_admon'] = null;
                    }
                    $contrato->update($updateData);

                    Bitacora::registrar(
                        accion: 'updated',
                        modelo: 'Contrato',
                        registroId: $contrato->id,
                        descripcion: "Contrato marcado como retirado con fecha {$fechaRetiro} por facturación de retiro en factura #{$factura->id}.",
                        detalle: [
                            'fecha_retiro' => $fechaRetiro,
                            'factura_id' => $factura->id,
                        ],
                        alidoId: $aliadoId
                    );
                }

                // Si está pagada o en préstamo, generar plano
                if (in_array($factura->estado, [Factura::ESTADO_PAGADA, Factura::ESTADO_PRESTAMO])) {
                    Plano::generarDesdeContrato($contrato, $factura, $fechaRetiro ?? null);
                }

                $facturasCreadas[] = $factura->id;
            }

            // ─── Marcar anticipos como aplicados (post-loop, fuera del foreach) ──
            // Se hace al final de la transacción para que todos los factura->id existan.
            // La primera factura del lote "absorbe" los anticipos.
            if ($anticiposSeleccionados->isNotEmpty() && ! empty($facturasCreadas)) {
                $facturaAnticipo = $facturasCreadas[0]; // primera del lote
                $pendienteAplicar = $totalAnticipo;
                foreach ($anticiposSeleccionados as $ant) {
                    if ($pendienteAplicar <= 0) {
                        break;
                    }
                    $aplicado = $ant->aplicarAFactura($facturaAnticipo, $pendienteAplicar);
                    $pendienteAplicar -= $aplicado;
                }
            }

            // ── Una factura PAGADA tiene que estar pagada ──────────────────
            // saldo_proximo ya dice cuánto falta (pagado - total) en todos los
            // caminos: normal, par afiliación+planilla y retiro facturable. Si
            // el lote queda debiendo más de lo que el cliente traía a favor, la
            // factura saldría verde con una deuda que no es préstamo: no entra a
            // /admin/prestamos, nadie la cobra, y solo reaparece el mes siguiente
            // como "cartera pendiente" (caso Daniel Arroyave, jul-2026: $563.047
            // marcados pagados con $0 en efectivo y $0 en consignación).
            // Se valida aquí, al final de la transacción, para que el rollback
            // deshaga facturas, planos, anticipos y número de recibo.
            if ($validated['estado'] === Factura::ESTADO_PAGADA && ! empty($facturasCreadas)) {
                $creadas = Factura::whereIn('id', $facturasCreadas)->get();

                // Se mide contra la plata, no contra el saldo. `saldo_proximo` no
                // sirve de juez: en un lote de empresa con credito disponible se
                // graba como "credito consumido" y puede quedar en 0 aunque no
                // haya entrado un peso — por ese hueco pasaron 74 facturas de
                // 2026 ($29,1M) marcadas pagadas sin pago, con la deuda anotada
                // solo en la observacion ("AUTORIZA GERENCIA", "Jefe autoriza
                // prestamo"). Lo que el cliente no pague va a PRESTAMO, que es
                // donde alguien lo cobra; el recibo igual sale con sello PAGO si
                // el faltante es menor que Factura::PRESTAMO_SELLO_PAGO.
                $recibidoLote = (int) $creadas->sum(fn ($f) => (int) $f->valor_consignado
                    + (int) $f->valor_efectivo
                    + (int) $f->anticipo_aplicado);
                $faltante = max(0, (int) $creadas->sum('total') - $recibidoLote - $favorPrevioLote);
                if ($faltante > self::TOLERANCIA_PAGO) {
                    throw new \App\Exceptions\PagoIncompletoException(
                        totalLote: (int) $creadas->sum('total'),
                        pagadoLote: (int) $creadas->sum(fn ($f) => (int) $f->valor_efectivo
                            + (int) $f->valor_consignado
                            + (int) $f->anticipo_aplicado),
                        saldoFavor: $favorPrevioLote,
                        faltante: $faltante,
                    );
                }
            }
        });

        $primera = ! empty($facturasCreadas) ? $facturasCreadas[0] : null;

        // Si no se creó ninguna (todos eran duplicados) → error
        if (empty($facturasCreadas) && ! empty($omitidos)) {
            $nombres = collect($omitidos)->pluck('nombre')->join(', ');

            return response()->json([
                'ok' => false,
                'mensaje' => 'Ya existe factura para este período: '.$nombres.'. Anule la factura existente antes de refacturar.',
                'omitidos' => $omitidos,
            ], 422);
        }

        // ─── Liquidar cartera pendiente de préstamos anteriores ────────────────────
        // Se ejecuta FUERA de la transacción principal para que un error aqui
        // no revierta las facturas ya creadas. Tiene su propia transacción interna.
        $carteraLiquidada = true;
        if (! empty($validated['incluir_cartera']) && ! empty($validated['valor_cartera']) && ! empty($facturasCreadas)) {
            $carteraLiquidada = $this->_liquidarCartera($aliadoId, $validated, $facturasCreadas);
        }

        // Éxito con posibles omitidos parciales
        $msgOmit = ! empty($omitidos)
            ? ' | '.count($omitidos).' omitido(s) por duplicado.'
            : '';
        // La factura sí se creó (por diseño, ver _liquidarCartera); esto solo avisa
        // que la cartera pendiente asociada quedó sin liquidar y hay que revisarla
        // a mano — antes ese aviso solo quedaba en el log.
        $msgCartera = ! $carteraLiquidada
            ? ' | ⚠ No se pudo liquidar la cartera pendiente de préstamos; revísela manualmente.'
            : '';

        // ─── Limpiar NP provisional de contratos que sí fueron facturados ──
        // El np en contratos es un marcador provisional (asignado antes de facturar
        // para agrupar y filtrar un listado). Al generar la factura se limpia para
        // que no persista indefinidamente. Solo se borran los que SÍ se facturaron.
        if (! empty($facturasCreadas)) {
            \App\Models\Contrato::whereIn('id', $validated['contratos'])
                ->whereNotNull('np')
                ->update(['np' => null]);
        }

        return response()->json([
            'ok' => true,
            'mensaje' => count($facturasCreadas).' factura(s) generada(s) correctamente.'.$msgOmit.$msgCartera,
            'cartera_liquidada' => $carteraLiquidada,
            'facturas' => $facturasCreadas,
            'omitidos' => $omitidos,
            'recibo_url' => $primera ? route('admin.facturacion.recibo', $primera) : null,
            // Indica al frontend que se usó modo "ambos" (afil + planilla ya creadas)
            // para que NO reabra el modal automáticamente al siguiente mes.
            'indep_ambos' => (($validated['indep_modo'] ?? 'normal') === 'ambos') && count($facturasCreadas) >= 2,
            // IDs de consignaciones creadas (solo las de la primera factura del batch)
            // El JS los usa para subir las imágenes de soporte después de crear la factura.
            'consignacion_ids' => \App\Models\Consignacion::whereIn('factura_id', $facturasCreadas)
                ->orderBy('id')
                ->pluck('id')
                ->values()
                ->all(),
        ]);
    }

    // ─── Liquidar cartera pendiente al facturar ────────────────────────────────
    /**
     * Cuando el usuario marca "Cartera pendiente" en el modal de facturación y confirma,
     * este método busca las facturas en estado=prestamo del cliente/empresa,
     * registra un Abono (con referencia a la nueva factura creada) y actualiza el estado.
     *
     * NO afecta el informe financiero: los ingresos se calculan de admon+seguro+mensajeria,
     * nunca de la tabla abonos. Sin riesgo de duplicación de ingresos.
     */
    /**
     * @return bool true si no había nada que liquidar o si liquidó correctamente;
     *              false solo si un intento real de liquidar falló (el llamador
     *              usa esto para avisarle al usuario, no solo dejarlo en el log).
     */
    private function _liquidarCartera(int $aliadoId, array $validated, array $facturasCreadas): bool
    {
        $empresaId = $validated['empresa_id'] ?? null;
        $valorCartera = (int) ($validated['valor_cartera'] ?? 0);
        if ($valorCartera <= 0) {
            return true;
        }

        // Buscar cédulas de los contratos facturados
        $cedulas = Contrato::whereIn('id', $validated['contratos'])->pluck('cedula');

        // Query base: facturas en estado=prestamo de este aliado
        $query = Factura::where('aliado_id', $aliadoId)
            ->where('estado', Factura::ESTADO_PRESTAMO)
            ->whereNull('deleted_at')
            ->with('abonos')
            ->orderBy('anio')->orderBy('mes'); // pagar primero los más antiguos

        if ($empresaId) {
            $query->where('empresa_id', $empresaId);
        } else {
            $query->whereIn('cedula', $cedulas)->whereNull('empresa_id');
        }

        $facturasPrestamo = $query->get();
        if ($facturasPrestamo->isEmpty()) {
            return true;
        }

        // Texto de referencia para el Abono
        $nuevaFactura = Factura::find($facturasCreadas[0] ?? null);
        $refNro = $nuevaFactura
            ? str_pad($nuevaFactura->numero_factura, 6, '0', STR_PAD_LEFT)
            : '—';
        $meses = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
            'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
        $mesNom = $meses[((int) ($validated['mes'])) - 1] ?? '';
        $obsText = "Cobrado con factura #{$refNro} — {$mesNom} {$validated['anio']}";

        $pendiente = $valorCartera;

        try {
            DB::transaction(function () use (
                $facturasPrestamo, $empresaId, &$pendiente,
                $validated, $obsText
            ) {
                if ($empresaId) {
                    // Lote empresa: agrupar por numero_factura, pagar lote por lote
                    $lotes = $facturasPrestamo->groupBy('numero_factura');
                    foreach ($lotes as $lote) {
                        if ($pendiente <= 0) {
                            break;
                        }

                        // Saldo a nivel de lote: misma lógica que PrestamosController e InformeController.
                        // valor_prestamo = monto explícito del préstamo al facturar (fuente de verdad).
                        $abonosLote = $lote->sum(fn ($f) => (int) $f->abonos->sum('valor'));
                        $valorPrestamo = (int) $lote->sum('valor_prestamo');
                        $saldoLote = $valorPrestamo > 0
                            ? max(0, $valorPrestamo - $abonosLote)
                            : max(0, abs((int) $lote->sum('saldo_proximo')) - $abonosLote);

                        if ($saldoLote <= 0) {
                            continue;
                        }

                        $abono = min($pendiente, $saldoLote);
                        $pendiente -= $abono;

                        // Registrar abono en la fila de referencia (primera del lote)
                        Abono::create([
                            'factura_id' => $lote->first()->id,
                            'valor' => $abono,
                            'forma_pago' => $validated['forma_pago'] ?? 'efectivo',
                            'valor_efectivo' => $abono,
                            'valor_consignado' => 0,
                            'observacion' => $obsText,
                            'fecha' => today()->toDateString(),
                            'usuario_id' => Auth::id(),
                        ]);

                        // Actualizar estado de TODAS las filas del lote
                        $nuevoSaldo = $saldoLote - $abono;
                        $estadoNuevo = $nuevoSaldo <= 0
                            ? Factura::ESTADO_PAGADA
                            : Factura::ESTADO_PRESTAMO;

                        foreach ($lote as $f) {
                            $f->update([
                                'estado' => $estadoNuevo,
                                'saldo_proximo' => $estadoNuevo === Factura::ESTADO_PAGADA ? 0 : $f->saldo_proximo,
                            ]);
                        }
                    }
                } else {
                    // Individual: una factura por cédula, pagar de más antigua a más nueva
                    foreach ($facturasPrestamo as $fp) {
                        if ($pendiente <= 0) {
                            break;
                        }
                        $saldo = $fp->saldo_pendiente_prestamo;
                        if ($saldo <= 0) {
                            continue;
                        }

                        $abono = min($pendiente, $saldo);
                        $pendiente -= $abono;

                        Abono::create([
                            'factura_id' => $fp->id,
                            'valor' => $abono,
                            'forma_pago' => $validated['forma_pago'] ?? 'efectivo',
                            'valor_efectivo' => $abono,
                            'valor_consignado' => 0,
                            'observacion' => $obsText,
                            'fecha' => today()->toDateString(),
                            'usuario_id' => Auth::id(),
                        ]);

                        $fp->refresh();
                        if ($fp->estaCompletamentePagada()) {
                            $fp->update([
                                'estado' => Factura::ESTADO_PAGADA,
                                'saldo_proximo' => 0,
                            ]);
                        }
                    }
                }
            });

            return true;
        } catch (\Throwable $e) {
            // Si falla el cierre del préstamo, loguear pero NO revertir la factura ya creada.
            // El llamador usa el false para avisarle al usuario en la respuesta —
            // antes solo quedaba en storage/logs y nadie se enteraba (ver
            // docs/auditoria-calidad.md, hallazgo E-6).
            \Log::warning('[_liquidarCartera] No se pudo liquidar la cartera: '.$e->getMessage(), [
                'aliado_id' => $aliadoId,
                'empresa_id' => $empresaId,
                'valor_cartera' => $valorCartera,
                'facturas' => $facturasCreadas,
            ]);

            return false;
        }
    }

    // ─── Crear par Afiliación + Planilla (independientes mes ingreso) ───────────
    /**
     * Crea 2 registros Factura con el mismo numero_factura:
     *   1) Afiliación  → mes=X, tipo=afiliacion, dias=0, SS=0
     *   2) Planilla    → mes=X (mes actual) | mes=X+1 (vencido), tipo=planilla, SS calculado
     *
     * Ambos registros comparten: numero_factura, np, estado, forma_pago, fecha_pago.
     * El pago (efectivo + consig) se divide proporcionalmente según el costo de cada uno.
     * Las consignaciones se guardan en la primera factura (afiliación), igual que el batch.
     *
     * @return array [$idFactAfiliacion, $idFactPlanilla]
     */
    private function _crearParAfilPlanilla(
        \App\Models\Contrato $contrato,
        array $validated,
        int $mes,
        int $anio,
        int $aliadoId,
        int $batchNumeroFactura,
        ?int $np,
        array &$nPlanosPorRS,
        int $vConsig,
        int $vEfectivo,
        int $vPrestamo,
        int $vAnticipo,
        bool $esFirstOfBatch,
        array $consignacionesData,
        bool $esIndVenc,
        array $manualSsPorContrato = [],
    ): array {
        // ── Datos comunes ────────────────────────────────────────────────────────
        $tieneIva = \App\Services\IvaService::aplicaContrato($contrato);
        $costoAfiliacion = (int) ($contrato->costo_afiliacion ?? 0);
        $seguro = (int) ($contrato->seguro ?? 0);
        $admon = (int) ($contrato->administracion ?? 0);
        $adminAsesor = (int) ($contrato->admon_asesor ?? 0);

        // ── n_plano compartido por RS ────────────────────────────────────────────
        $rsId = $contrato->razon_social_id;
        if ($rsId && ! isset($nPlanosPorRS[$rsId])) {
            $nPlanosPorRS[$rsId] = static::_nPlanoParaRS($aliadoId, $rsId, $mes, $anio);
        }
        $nPlanoFactura = $rsId ? ($nPlanosPorRS[$rsId] ?? null) : null;

        // ── Mes/Año de la planilla ───────────────────────────────────────────────
        // I Venc → mes siguiente (paga adelantado al ingresar)
        // Mes actual → mismo mes
        if ($esIndVenc) {
            $mesPlan = $mes === 12 ? 1 : $mes + 1;
            $anioPlan = $mes === 12 ? $anio + 1 : $anio;
        } else {
            $mesPlan = $mes;
            $anioPlan = $anio;
        }

        // ── Días proporcionales para la planilla ─────────────────────────────────
        // Del ingreso al fin de mes, salvo que en el mismo período se marque el
        // retiro: ahí manda lo que va del ingreso al retiro (ingresa el 22 y se
        // retira el 26 → 5 días, no los 9 que quedan hasta fin de mes).
        $diaIngreso = $contrato->fecha_ingreso ? (int) $contrato->fecha_ingreso->day : 1;
        $esRetiroPar = ! empty($validated['es_retiro']);
        $diasRetiro = $esRetiroPar ? (int) ($validated['dias_retiro'] ?? 0) : 0;
        $diasPlan = $diasRetiro > 0 ? $diasRetiro : max(1, 30 - $diaIngreso + 1);

        // ── Calcular SS de la planilla ───────────────────────────────────────────
        $cotiz = $contrato->calcularCotizacion($diasPlan, $tieneIva);
        $calcSS = [
            'eps' => (int) ($cotiz['eps'] ?? 0),
            'arl' => (int) ($cotiz['arl'] ?? 0),
            'afp' => (int) ($cotiz['pen'] ?? 0),
            'caja' => (int) ($cotiz['caja'] ?? 0),
            'parafiscales' => (int) ($cotiz['parafiscales'] ?? 0),
        ];

        // Override manual de SS (modo individual, 1 contrato)
        $esModoIndividual = count($validated['contratos']) === 1;
        if ($esModoIndividual) {
            if (isset($validated['v_eps_manual'])) {
                $calcSS['eps'] = intval($validated['v_eps_manual']);
            }
            if (isset($validated['v_arl_manual'])) {
                $calcSS['arl'] = intval($validated['v_arl_manual']);
            }
            if (isset($validated['v_afp_manual'])) {
                $calcSS['afp'] = intval($validated['v_afp_manual']);
            }
            if (isset($validated['v_caja_manual'])) {
                $calcSS['caja'] = intval($validated['v_caja_manual']);
            }
        } elseif (! empty($manualSsPorContrato[(string) $contrato->id])) {
            $ssMap = $manualSsPorContrato[(string) $contrato->id];
            if (isset($ssMap['eps'])) {
                $calcSS['eps'] = intval($ssMap['eps']);
            }
            if (isset($ssMap['arl'])) {
                $calcSS['arl'] = intval($ssMap['arl']);
            }
            if (isset($ssMap['afp'])) {
                $calcSS['afp'] = intval($ssMap['afp']);
            }
            if (isset($ssMap['caja'])) {
                $calcSS['caja'] = intval($ssMap['caja']);
            }
        }

        $totalSS = $calcSS['eps'] + $calcSS['arl'] + $calcSS['afp'] + $calcSS['caja'] + ($calcSS['parafiscales'] ?? 0);

        // IVA: cada registro del par lleva el suyo — la planilla sobre la admon,
        // la afiliación sobre el costo de afiliación (ver IvaService).
        $iva = \App\Services\IvaService::calcular($admon + $adminAsesor, $tieneIva);
        $ivaAfil = \App\Services\IvaService::calcular($costoAfiliacion, $tieneIva);

        // ── Totales de cada registro ─────────────────────────────────────────────
        $totalAfil = $costoAfiliacion + $ivaAfil; // afiliación: costo + IVA (sin admon, sin SS)
        // Los dos "otros" van a la planilla, no a la afiliación. Aquí no hay
        // reparto que hacer: el par es de un solo contrato.
        $totalPlan = $totalSS + $admon + $adminAsesor + $seguro + $iva
                   + (int) ($validated['otros_admon'] ?? 0)
                   + (int) ($validated['otros'] ?? 0);

        $totalAmbos = max(1, $totalAfil + $totalPlan);

        // ── Distribución proporcional del pago entre los 2 registros ────────────
        $propAfil = $totalAfil / $totalAmbos;
        $propPlan = 1 - $propAfil;

        $vConsigAfil = (int) round($vConsig * $propAfil);
        $vEfectAfil = (int) round($vEfectivo * $propAfil);
        $vPrestAfil = (int) round($vPrestamo * $propAfil);
        $vAntAfil = (int) round($vAnticipo * $propAfil);

        $vConsigPlan = $vConsig - $vConsigAfil;
        $vEfectPlan = $vEfectivo - $vEfectAfil;
        $vPrestPlan = $vPrestamo - $vPrestAfil;
        $vAntPlan = $vAnticipo - $vAntAfil;

        // ── Distribución de afiliación ────────────────────────────────────────────
        $distAsesor = $distRetiro = $distEncargado = $distAdmon = $distUtilidad = 0;
        if ($costoAfiliacion > 0) {
            $hasManual = isset($validated['dist_asesor']) || isset($validated['dist_retiro'])
                      || isset($validated['dist_encargado']) || isset($validated['dist_admon']);

            // Mismo caso que en facturar(): el modal manda las claves siempre, en cero. Ver el
            // comentario largo allá. Un reparto en ceros no pisa el tarifario del contrato.
            $manualEnCeros = ((int) ($validated['dist_asesor'] ?? 0)
                            + (int) ($validated['dist_retiro'] ?? 0)
                            + (int) ($validated['dist_encargado'] ?? 0)
                            + (int) ($validated['dist_admon'] ?? 0)) === 0;

            if ($hasManual && $manualEnCeros && $contrato->afiliacion_asesor !== null) {
                $hasManual = false;
            }

            if ($hasManual) {
                $distAsesor = (int) ($validated['dist_asesor'] ?? 0);
                $distRetiro = (int) ($validated['dist_retiro'] ?? 0);
                $distEncargado = (int) ($validated['dist_encargado'] ?? 0);
                $distAdmon = (int) ($validated['dist_admon'] ?? 0);

                if ((int) $contrato->tipo_modalidad_id === 15) {
                    $distRetiro = 0;
                }

                $distUtilidad = max(0, $costoAfiliacion - $distAsesor - $distRetiro - $distEncargado - $distAdmon);
            } else {
                // Contrato con tarifario (afiliacion_asesor no nulo): el reparto sale de lo
                // que quedó congelado en el contrato. Ver TarifaAsesorService.
                $dist = \App\Services\TarifaAsesorService::distribucionFactura(
                    $contrato, $costoAfiliacion, $mes, $anio
                );

                if ($dist) {
                    $distAdmon = $dist['admon'];
                    $distAsesor = $dist['asesor'];
                    $distRetiro = $dist['retiro'];
                    $distUtilidad = $dist['utilidad'];
                    $distEncargado = $dist['encargado'];
                } else {
                    // Contrato anterior al tarifario: camino de siempre.
                    $cfg = \App\Models\ConfiguracionAliado::paraAliado($aliadoId, $contrato->plan_id);
                    if ($cfg) {
                        $dist = $cfg->calcularDistribucion($costoAfiliacion, $contrato->asesor ?? null);
                        $distAdmon = $dist['admon'];
                        $distAsesor = $dist['asesor'];
                        $distRetiro = $dist['retiro'];
                        $distUtilidad = $dist['utilidad'];

                        if ((int) $contrato->tipo_modalidad_id === 15) {
                            $distUtilidad += $distRetiro;
                            $distRetiro = 0;
                        }
                    }
                }
            }
        }

        $estado = $validated['estado'];
        $formaPago = $validated['forma_pago'];
        // Ambos registros del par comparten la fecha del recibo (ver _fechaPagoRecibo)
        $fechaPagoRecibo = $this->_fechaPagoRecibo($formaPago, $consignacionesData);

        // ────────────────────────────────────────────────────────────────────────
        // REGISTRO 1: AFILIACIÓN
        // ────────────────────────────────────────────────────────────────────────
        $factAfil = Factura::create([
            'aliado_id' => $aliadoId,
            'numero_factura' => $batchNumeroFactura,
            'tipo' => 'afiliacion',
            'cedula' => $contrato->cedula,
            'contrato_id' => $contrato->id,
            'mes' => $mes,
            'anio' => $anio,
            'fecha_pago' => $fechaPagoRecibo,
            'estado' => $estado,
            'es_prestamo' => $estado === 'prestamo',
            'forma_pago' => $formaPago,
            'valor_consignado' => $vConsigAfil,
            'valor_efectivo' => $vEfectAfil,
            'valor_prestamo' => $vPrestAfil,
            'anticipo_aplicado' => $vAntAfil,
            'otros' => 0,
            'otros_admon' => 0,
            'mensajeria' => 0,
            'dias_cotizados' => 0,
            'v_eps' => 0,
            'v_arl' => 0,
            'v_afp' => 0,
            'v_caja' => 0,
            'total_ss' => 0,
            'admon' => 0,
            'admin_asesor' => 0,
            'seguro' => 0,
            'afiliacion' => $costoAfiliacion,
            'iva' => $ivaAfil,
            'mora' => 0,
            'total' => max(0, $totalAfil),
            'dist_admon' => $distAdmon,
            'dist_asesor' => $distAsesor,
            'dist_retiro' => $distRetiro,
            'dist_utilidad' => $distUtilidad,
            'dist_encargado' => $distEncargado,
            'np' => $np,
            'n_plano' => $nPlanoFactura,
            'empresa_id' => $validated['empresa_id'] ?? null,
            'razon_social_id' => $contrato->razon_social_id,
            'usuario_id' => \Illuminate\Support\Facades\Auth::id(),
            'observacion' => $validated['observacion'] ?? null,
        ]);

        // Saldo próximo de la afiliación
        $pagadoAfil = $vConsigAfil + $vEfectAfil + $vAntAfil;
        $factAfil->update(['saldo_proximo' => $pagadoAfil - $totalAfil]);

        // Consignaciones: solo en la PRIMERA factura de todo el batch
        if ($esFirstOfBatch) {
            foreach ($consignacionesData as $cs) {
                $valorCs = (int) $cs['valor'];
                if ($valorCs <= 0) {
                    continue;
                }
                \App\Models\Consignacion::create([
                    'aliado_id' => $aliadoId,
                    'factura_id' => $factAfil->id,
                    'banco_cuenta_id' => (int) $cs['banco_cuenta_id'],
                    'fecha' => $cs['fecha'] ?? now()->toDateString(),
                    'valor' => $valorCs,
                    'referencia' => $cs['referencia'] ?? null,
                    'confirmado' => false,
                    'usuario_id' => \Illuminate\Support\Facades\Auth::id(),
                ]);
            }
        }

        // Plano de afiliación
        if (in_array($factAfil->estado, [Factura::ESTADO_PAGADA, Factura::ESTADO_PRESTAMO])) {
            Plano::generarDesdeContrato($contrato, $factAfil);
        }

        // ────────────────────────────────────────────────────────────────────────
        // REGISTRO 2: PLANILLA
        // ────────────────────────────────────────────────────────────────────────
        $factPlan = Factura::create([
            'aliado_id' => $aliadoId,
            'numero_factura' => $batchNumeroFactura,
            'tipo' => 'planilla',
            'cedula' => $contrato->cedula,
            'contrato_id' => $contrato->id,
            'mes' => $mesPlan,
            'anio' => $anioPlan,
            'fecha_pago' => $fechaPagoRecibo,
            'estado' => $estado,
            'es_prestamo' => $estado === 'prestamo',
            'forma_pago' => $formaPago,
            'valor_consignado' => $vConsigPlan,
            'valor_efectivo' => $vEfectPlan,
            'valor_prestamo' => $vPrestPlan,
            'anticipo_aplicado' => $vAntPlan,
            'otros' => (int) ($validated['otros'] ?? 0),
            'otros_admon' => (int) ($validated['otros_admon'] ?? 0),
            'mensajeria' => 0,
            'dias_cotizados' => $diasPlan,
            'v_eps' => $calcSS['eps'],
            'v_arl' => $calcSS['arl'],
            'v_afp' => $calcSS['afp'],
            'v_caja' => $calcSS['caja'],
            'v_parafiscales' => $calcSS['parafiscales'] ?? 0,
            'total_ss' => $totalSS,
            'admon' => $admon,
            'admin_asesor' => $adminAsesor,
            'seguro' => $seguro,
            'afiliacion' => 0, // ya se cobró en el registro de afiliación
            'iva' => $iva,
            'mora' => 0,
            'total' => max(0, $totalPlan),
            'dist_admon' => 0,
            'dist_asesor' => 0,
            'dist_retiro' => 0,
            'dist_utilidad' => 0,
            'dist_encargado' => 0,
            'np' => $np,
            'n_plano' => $nPlanoFactura,
            'empresa_id' => $validated['empresa_id'] ?? null,
            'razon_social_id' => $contrato->razon_social_id,
            'usuario_id' => \Illuminate\Support\Facades\Auth::id(),
            'observacion' => $validated['observacion'] ?? null,
        ]);

        // Saldo próximo de la planilla
        $pagadoPlan = $vConsigPlan + $vEfectPlan + $vAntPlan;
        $factPlan->update(['saldo_proximo' => $pagadoPlan - $totalPlan]);

        // Plano de planilla. Si el par se factura con retiro marcado, la novedad
        // va en este registro: sin la fecha, la planilla reporta el ingreso pero
        // nunca el retiro y la persona sigue cotizando el mes siguiente.
        if (in_array($factPlan->estado, [Factura::ESTADO_PAGADA, Factura::ESTADO_PRESTAMO])) {
            Plano::generarDesdeContrato(
                $contrato,
                $factPlan,
                $esRetiroPar ? ($validated['fecha_retiro'] ?? null) : null
            );
        }

        return [$factAfil->id, $factPlan->id];
    }

    public function abonar(Request $request, int $facturaId)
    {
        $aliadoId = session('aliado_id_activo');
        $factura = Factura::where('aliado_id', $aliadoId)->findOrFail($facturaId);

        // Cuando entra plata por el banco hay que decir a qué cuenta: sin eso
        // no se sabe qué recaudo le toca a cada razón social, y la facturación
        // electrónica —que emite lo que entra a la cuenta de la emisora— no ve
        // el pago. Los 43 abonos de BRYGAR anteriores a esto quedaron sin
        // cuenta y hoy no hay forma de saber por dónde entraron esos $31M.
        $porBanco = in_array($request->input('forma_pago'), ['consignacion', 'mixto'], true);

        $validated = $request->validate([
            'valor' => 'required|numeric|min:1',
            'forma_pago' => 'required|in:efectivo,consignacion,mixto',
            'valor_efectivo' => 'nullable|numeric|min:0',
            'valor_consignado' => 'nullable|numeric|min:0',
            'banco_cuenta_id' => ($porBanco ? 'required' : 'nullable').'|integer|exists:banco_cuentas,id',
            'observacion' => 'nullable|string|max:300',
        ], [
            'banco_cuenta_id.required' => 'Dinos a qué cuenta entró la consignación.',
        ]);

        // El id viaja desde el formulario y `exists` solo mira que exista.
        if (! empty($validated['banco_cuenta_id'])
            && ! BancoCuenta::where('id', $validated['banco_cuenta_id'])->where('aliado_id', $aliadoId)->exists()) {
            return response()->json(['ok' => false, 'mensaje' => 'Esa cuenta no es de este aliado.'], 422);
        }

        $abono = DB::transaction(function () use ($factura, $validated) {
            $ab = Abono::create([
                ...$validated,
                'factura_id' => $factura->id,
                'fecha' => now()->toDateString(),
                'usuario_id' => Auth::id(),
            ]);

            // ¿El total abonado cubre el total?
            $factura->refresh();
            if ($factura->estaCompletamentePagada()) {
                $factura->update(['estado' => Factura::ESTADO_PAGADA]);
                // Generar plano si no existe
                if (! $factura->plano) {
                    $c = $factura->contrato()->with('eps', 'arl', 'pension', 'caja', 'tipoModalidad')->first();
                    if ($c) {
                        Plano::generarDesdeContrato($c, $factura);
                    }
                }
            } else {
                $factura->update(['estado' => Factura::ESTADO_ABONO]);
            }

            return $ab;
        });

        return response()->json([
            'ok' => true,
            'abono_id' => $abono->id,
            'total_abonado' => $factura->total_abonado,
            'saldo_restante' => $factura->saldo_restante,
            'estado' => $factura->estado,
            'recibo_url' => route('admin.facturacion.recibo-abono', $abono->id),
        ]);
    }

    // ─── API: Cotización de un contrato individual (para modal multi-contrato) ──
    /**
     * GET /admin/facturacion/api/cotizacion-contrato/{id}?mes=X&anio=Y
     * Devuelve los valores calculados de un contrato para el período dado.
     * Usado por el modal de facturación cuando el usuario selecciona un 2do contrato.
     */
    public function cotizacionContrato(Request $request, int $contratoId)
    {
        $aliadoId = session('aliado_id_activo');
        $mes = (int) $request->get('mes', now()->month);
        $anio = (int) $request->get('anio', now()->year);

        $contrato = Contrato::where('aliado_id', $aliadoId)
            ->with(['eps', 'arl', 'pension', 'caja', 'tipoModalidad', 'razonSocial', 'cliente'])
            ->find($contratoId);

        if (! $contrato) {
            return response()->json(['ok' => false, 'mensaje' => 'Contrato no encontrado.'], 404);
        }

        // Verificar si ya fue facturado para ese período
        $yaFacturado = Factura::where('aliado_id', $aliadoId)
            ->where('cedula', $contrato->cedula)
            ->where('razon_social_id', $contrato->razon_social_id)
            ->where('mes', $mes)
            ->where('anio', $anio)
            ->whereNotIn('estado', ['anulada'])
            ->exists();

        if ($yaFacturado) {
            return response()->json([
                'ok' => false,
                'ya_facturado' => true,
                'mensaje' => 'Este contrato ya fue facturado para '.$mes.'/'.$anio.'.',
            ]);
        }

        $calc = \App\Services\CobroContratoService::calcular($contrato, $mes, $anio);

        return response()->json(array_merge($calc, [
            'ok' => true,
            'ya_facturado' => false,
            'contrato_id' => $contrato->id,
            'razon_social' => $contrato->razonSocial?->razon_social ?? '—',
        ]));
    }

    // ─── Recibo de factura ───────────────────────────────────────────
    public function recibo(int $facturaId)
    {
        $aliadoId = session('aliado_id_activo');

        // Solo seguro: recibo propio, y se resuelve antes de armar nada más. El recibo
        // de siempre imprime EPS, ARL, pensión y caja en guiones y notas legales sobre
        // incapacidades que aquí no aplican, y para llegar hasta allá agrupa por
        // numero_factura y suma saldos — trabajo que este recibo no necesita.
        $facturaSeguro = Factura::where('aliado_id', $aliadoId)
            ->with(['contrato.cliente', 'contrato.seguroPlan', 'usuario', 'consignaciones.bancoCuenta'])
            ->findOrFail($facturaId);

        if ($facturaSeguro->contrato?->esSoloSeguro()) {
            return view('admin.facturacion.recibo_seguro', ['factura' => $facturaSeguro]);
        }

        $factura = Factura::where('aliado_id', $aliadoId)
            ->with(['contrato.cliente', 'contrato.eps', 'contrato.arl',
                'contrato.pension', 'contrato.caja', 'contrato.razonSocial',
                'razonSocial', 'usuario', 'abonos',
                'consignaciones.bancoCuenta'])   // ← todas las cuentas consignadas
            ->findOrFail($facturaId);

        // Grupo del recibo: todas las facturas del mismo numero_factura dentro del aliado.
        // Se usa numero_factura (identificador único del lote) en lugar de np+mes+año+empresa_id
        // para evitar mezclar dos recibos distintos que casualmente comparten el mismo NP
        // (p.ej. dos lotes de "NP 2" facturados en fechas distintas del mismo mes).
        $grupoNp = null;
        if ($factura->numero_factura) {
            $grupoNp = Factura::where('aliado_id', $aliadoId)
                ->where('numero_factura', $factura->numero_factura)
                ->with(['contrato.cliente', 'contrato.eps', 'contrato.arl',
                    'contrato.pension', 'contrato.caja', 'contrato.razonSocial',
                    'abonos', 'consignaciones.bancoCuenta', 'usuario'])
                ->orderBy('id')
                ->get();
        }

        // Anticipos aplicados a esta factura (para el recibo/PDF)
        $anticiposAplicados = \App\Models\Anticipo::where('factura_id', $facturaId)
            ->with(['bancoCuenta', 'usuario'])
            ->orderBy('fecha_pago')
            ->get();

        // ── Doble copia por hoja (copia CLIENTE + copia EMPRESA) ─────────
        // Interruptor por aliado en Configuración → Parámetros Especiales.
        $reciboDoble = (bool) \App\Models\Aliado::where('id', $aliadoId)->value('recibo_doble_copia');

        // Saldo anterior acumulado. No existe una columna con este dato: es la
        // SUMA de los saldo_proximo de las facturas ANTERIORES a esta (ver
        // Factura::saldoClienteMesPrevio).
        //   negativo → el cliente venía debiendo   positivo → traía saldo a favor
        // Antes solo se calculaba con la doble copia activa, así que en los
        // aliados que no la usan (todos menos GiMave) el cliente no veía si
        // quedaba debiendo: la deuda solo aparecía un mes después, al facturar.
        $qSaldo = Factura::where('aliado_id', $aliadoId)
            ->whereIn('estado', ['pagada', 'prestamo', 'abono'])
            ->whereNotNull('saldo_proximo')
            ->where('id', '!=', $factura->id)
            ->where(fn ($q) => $q->where('anio', '<', $factura->anio)
                ->orWhere(fn ($q2) => $q2->where('anio', $factura->anio)
                    ->where('mes', '<', $factura->mes)));

        if ($factura->empresa_id) {
            // Facturación de empresa: el saldo se lleva por empresa_id
            $qSaldo->where('empresa_id', $factura->empresa_id);
        } else {
            // Individual: por cédula y sin mezclar con facturas de empresa
            $qSaldo->where('cedula', $factura->cedula)->whereNull('empresa_id');
        }

        $saldoAnterior = (int) $qSaldo->sum('saldo_proximo');

        // Planillas ya pagadas al operador dentro del recibo (el lote completo).
        // El modal de anulación las muestra: anular aquí deja esos planos sin
        // número de planilla aunque el pago al operador ya esté hecho.
        $planillasGrupo = Plano::whereIn('factura_id', ($grupoNp ?? collect([$factura]))->pluck('id'))
            ->whereNotNull('numero_planilla')
            ->where('numero_planilla', '<>', '')
            ->get(['no_identifi', 'primer_nombre', 'primer_ape', 'numero_planilla'])
            ->map(fn ($p) => [
                'nombre' => trim("{$p->primer_nombre} {$p->primer_ape}") ?: "CC {$p->no_identifi}",
                'cedula' => $p->no_identifi,
                'planilla' => $p->numero_planilla,
            ]);

        // Retiros marcados en este recibo. El retiro suele aplicarse al facturar
        // (casilla de retiro), y hasta ahora no quedaba a la vista en ningún lado:
        // había que ir a la bitácora para saber quién retiró a quién. El plano de
        // la factura guarda la fecha de retiro y el usuario que lo generó.
        $retirosGrupo = Plano::whereIn('factura_id', ($grupoNp ?? collect([$factura]))->pluck('id'))
            ->whereNotNull('fecha_ret')
            ->orderBy('id')
            ->get(['factura_id', 'fecha_ret', 'usuario_id']);

        $nombresRetiro = \App\Models\User::whereIn('id', $retirosGrupo->pluck('usuario_id')->filter()->unique())
            ->pluck('nombre', 'id');

        $retirosGrupo = $retirosGrupo->keyBy('factura_id')->map(fn ($p) => [
            'fecha' => $p->fecha_ret,
            'usuario' => $nombresRetiro[$p->usuario_id] ?? null,
        ]);

        return view('admin.facturacion.recibo',
            compact('factura', 'grupoNp', 'anticiposAplicados', 'reciboDoble', 'saldoAnterior', 'planillasGrupo', 'retirosGrupo'));
    }

    // ─── Anular factura (solo admin) ─────────────────────────────────
    public function anular(Request $request, int $facturaId)
    {
        $user = Auth::user();
        if (! $user || (! $user->hasRole('admin') && ! $user->hasRole('superadmin'))) {
            return response()->json(['ok' => false, 'message' => 'Sin permisos.'], 403);
        }

        $aliadoId = session('aliado_id_activo');
        $factura = Factura::where('aliado_id', $aliadoId)
            ->with(['contrato.cliente', 'abonos', 'plano'])
            ->findOrFail($facturaId);

        $motivo = trim($request->input('motivo', ''));
        if (! $motivo) {
            return response()->json(['ok' => false, 'message' => 'Debe indicar el motivo de anulación.'], 422);
        }

        // Anula solo las facturas con el mismo numero_factura dentro del aliado.
        // NO filtra por mes/año ni por NP para no afectar lotes de otros períodos.
        $facturasAnular = collect([$factura]);
        if ($factura->numero_factura && $request->boolean('todo_np', false)) {
            $facturasAnular = Factura::where('aliado_id', $aliadoId)
                ->where('numero_factura', $factura->numero_factura)
                ->with(['abonos', 'plano'])
                ->get();
        }

        // ── Protección: planilla ya pagada al operador ────────────────────
        // Se revisan TODAS las facturas que se van a anular (el lote completo
        // cuando viene todo_np) y TODOS los planos de cada una: con el hasOne
        // se veía un solo plano de la factura clickeada, así que anular el NP
        // desde el recibo arrastraba planos pagados de los demás contratos.
        $planosPagados = Plano::whereIn('factura_id', $facturasAnular->pluck('id'))
            ->whereNotNull('numero_planilla')
            ->where('numero_planilla', '<>', '')
            ->get(['id', 'factura_id', 'no_identifi', 'primer_nombre', 'primer_ape', 'numero_planilla']);

        if ($planosPagados->isNotEmpty()) {
            $planillas = $planosPagados->pluck('numero_planilla')->unique()->values();
            $afectados = $planosPagados->map(function ($p) {
                $nombre = trim("{$p->primer_nombre} {$p->primer_ape}") ?: "CC {$p->no_identifi}";

                return "{$nombre} (CC {$p->no_identifi}) — planilla Nº {$p->numero_planilla}";
            })->values();

            $esSuperBrynex = $user->es_brynex && $user->hasRole('superadmin');
            if (! $esSuperBrynex) {
                return response()->json([
                    'ok' => false,
                    'message' => 'No se puede anular: '.$planosPagados->count().' plano(s) de este recibo ya '
                                 .'tienen planilla pagada al operador (Nº '.$planillas->implode(', ').'). '
                                 .'Solo un superadmin de BryNex puede anularlo.',
                    'planillas' => $planillas,
                    'afectados' => $afectados,
                ], 403);
            }

            // Superadmin BryNex sí puede, pero debe confirmar viendo los números de planilla.
            if (! $request->boolean('confirmar_planilla')) {
                return response()->json([
                    'ok' => false,
                    'requiere_confirmacion' => true,
                    'message' => 'Este recibo ya tiene pago confirmado al operador con la(s) planilla(s) Nº '
                                             .$planillas->implode(', ').'. Si lo anula, esos planos quedarán sin '
                                             .'número de planilla y habrá que re-vincularlos a mano. ¿Está seguro?',
                    'planillas' => $planillas,
                    'afectados' => $afectados,
                ], 409);
            }
        }

        // ── Factura electrónica ya emitida ante la DIAN ──────────────────
        // Una FE aceptada no se borra: se anula con nota crédito. Sin esto, el
        // recibo desaparecía de Brynex, la FE seguía viva como ingreso y al
        // re-facturar salía otra FE por la misma plata (FE2314/FE2326 y
        // FE2321/FE2327, sep-2026). La nota se emite ANTES de anular: si Dataico
        // o la DIAN la rechazan, el recibo no se toca.
        $feVigentes = $facturasAnular->pluck('numero_factura')->filter()->unique()
            ->map(fn ($n) => NotaCreditoService::feVigente((int) $aliadoId, (int) $n))
            ->filter()
            ->values();

        $notasCredito = [];
        if ($feVigentes->isNotEmpty()) {
            // La FE cubre el recibo completo: anular una sola fila del lote
            // dejaría a las demás cobradas sin factura.
            foreach ($feVigentes as $envio) {
                $quedan = Factura::where('aliado_id', $aliadoId)
                    ->where('numero_factura', $envio->numero_factura)
                    ->whereNotIn('id', $facturasAnular->pluck('id'))
                    ->count();

                if ($quedan > 0) {
                    return response()->json([
                        'ok' => false,
                        'message' => "El recibo #{$envio->numero_factura} tiene la factura electrónica {$envio->dataico_numero} "
                                    .'por el lote completo. Para anularla hay que anular todo el recibo (marque «anular todo el NP»).',
                    ], 422);
                }
            }

            if (! $request->boolean('confirmar_fe')) {
                return response()->json([
                    'ok' => false,
                    'requiere_confirmacion' => true,
                    'campo_confirmacion' => 'confirmar_fe',
                    'message' => 'Este recibo ya tiene factura electrónica aceptada por la DIAN. Al anularlo se emitirá '
                                .'una NOTA CRÉDITO que la anula ante la DIAN. Eso no se puede deshacer: si luego hay que '
                                .'cobrar de nuevo, se hace un recibo nuevo con su propia factura. ¿Continuar?',
                    'afectados' => $feVigentes->map(fn ($e) => "{$e->dataico_numero} · {$e->cliente_nombre} · $"
                        .number_format((float) $e->base_admon, 0, ',', '.'))->all(),
                    'aviso' => 'Se enviará a la DIAN en cuanto acepte.',
                ], 409);
            }

            $servicioNc = app(NotaCreditoService::class);
            foreach ($feVigentes as $envio) {
                $r = $servicioNc->anular($envio, $motivo, $user->id);
                if (! $r['ok']) {
                    return response()->json([
                        'ok' => false,
                        'message' => 'No se anuló el recibo. '.$r['mensaje']
                                    .(count($notasCredito) ? ' (Ya se emitió: '.implode(', ', $notasCredito).'.)' : ''),
                    ], 422);
                }
                $notasCredito[] = $r['nota']->numero.' anula '.$envio->dataico_numero;
            }
        }

        DB::transaction(function () use ($facturasAnular, $motivo, $aliadoId, $user, $planosPagados, $notasCredito) {
            foreach ($facturasAnular as $f) {
                // Registrar en bitácora ANTES de anular
                Bitacora::registrar(
                    accion: 'deleted',
                    modelo: 'Factura',
                    registroId: $f->id,
                    descripcion: "Factura #{$f->numero_factura} anulada. Motivo: {$motivo}",
                    detalle: [
                        'snapshot' => $f->toArray(),
                        'abonos' => $f->abonos->toArray(),
                        'plano_id' => $f->plano?->id,
                        'motivo' => $motivo,
                        'notas_credito' => $notasCredito,
                        // Planillas que quedan huérfanas con esta anulación: es el
                        // rastro para re-vincularlas si hay que re-facturar.
                        'planillas' => $planosPagados->where('factura_id', $f->id)
                            ->map(fn ($p) => ['plano_id' => $p->id, 'numero_planilla' => $p->numero_planilla])
                            ->values()->all(),
                    ],
                    alidoId: $aliadoId
                );

                // Soft-delete de la factura (guarda motivo y quién anuló)
                $f->motivo_anulacion = $motivo;
                $f->anulado_por = $user->id;
                $f->saldo_proximo = 0; // limpiar para no influir en futuros cálculos
                $f->save();
                $f->delete(); // SoftDeletes → establece deleted_at

                // Soft-delete de TODOS los planos de esta factura.
                // IMPORTANTE: en lotes masivos hay N planos por factura_id (uno por contrato).
                // El hasOne solo eliminaría el primero, dejando los demás activos y causando
                // duplicados cuando se re-factura el mismo período tras una anulación.
                Plano::where('factura_id', $f->id)->each(fn ($p) => $p->delete());

                // \u2500\u2500 Reversar retiro: dos casos seg\u00fan el tipo de factura de retiro \u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500\u2500
                // CASO 1: Factura de retiro facturado desde empresa (factura_retiro_origen_id != null)
                //   \u2192 Reactivar la factura 0 original, restaurar el plano, NO revertir el contrato
                // CASO 2: Factura de retiro original (numero_factura = 0 o plano con fecha_ret)
                //   \u2192 Revertir el contrato a vigente (comportamiento original)

                if ($f->factura_retiro_origen_id && $f->contrato_id) {
                    // CASO 1: Anular retiro facturado desde empresa
                    // Restaurar la factura 0 original (reactivar soft-delete)
                    $facturaOrigenRestore = Factura::withTrashed()
                        ->find($f->factura_retiro_origen_id);

                    if ($facturaOrigenRestore) {
                        $facturaOrigenRestore->restore(); // quitar deleted_at
                        $facturaOrigenRestore->update([
                            'motivo_anulacion' => null,
                            'anulado_por' => null,
                        ]);

                        // Restaurar el plano al estado original (apuntar a factura 0)
                        Plano::where('factura_id', $f->id)
                            ->whereNull('deleted_at')
                            ->update([
                                'factura_id' => $facturaOrigenRestore->id,
                                'numero_factura' => 0,
                            ]);

                        Bitacora::registrar(
                            accion: 'updated',
                            modelo: 'Factura',
                            registroId: $facturaOrigenRestore->id,
                            descripcion: "Factura de retiro (origen) #{$facturaOrigenRestore->id} reactivada por anulaci\u00f3n de factura real #{$f->id}. Contrato {$f->contrato_id} permanece retirado.",
                            detalle: [
                                'factura_anulada_id' => $f->id,
                                'motivo' => $motivo,
                            ],
                            alidoId: $aliadoId
                        );
                    }
                } elseif (((int) $f->numero_factura === 0 || ($f->plano && $f->plano->fecha_ret)) && $f->contrato_id) {
                    // CASO 2: Factura de retiro original (numero_factura=0) \u2192 revertir contrato a vigente
                    $contratoRetiro = \App\Models\Contrato::find($f->contrato_id);
                    if ($contratoRetiro && $contratoRetiro->estado === 'retirado') {
                        $contratoRetiro->update([
                            'estado' => 'vigente',
                            'fecha_retiro' => null,
                            'motivo_retiro_id' => null,
                        ]);

                        // Registrar reversi\u00f3n en bit\u00e1cora
                        Bitacora::registrar(
                            accion: 'updated',
                            modelo: 'Contrato',
                            registroId: $contratoRetiro->id,
                            descripcion: "Contrato revertido a vigente por anulaci\u00f3n de factura de retiro #{$f->id} (N\u00ba Factura: {$f->numero_factura}). Motivo: {$motivo}",
                            detalle: ['revertido_por_anulacion_factura_id' => $f->id],
                            alidoId: $aliadoId
                        );
                    }
                }

                // ── Restaurar anticipos aplicados a esta factura ──────────────────
                // Al anular, los anticipos que se aplicaron en el momento de facturar
                // deben quedar nuevamente disponibles para ser usados en una re-facturación.
                \App\Models\Anticipo::where('factura_id', $f->id)->each(function ($ant) use ($aliadoId, $f) {
                    Bitacora::registrar(
                        accion: 'updated',
                        modelo: 'Anticipo',
                        registroId: $ant->id,
                        descripcion: "Anticipo #{$ant->id} revertido a disponible por anulación de factura #{$f->numero_factura}.",
                        detalle: [
                            'valor_aplicado_revertido' => $ant->valor_aplicado,
                            'estado_anterior' => $ant->estado,
                            'factura_id_anulada' => $f->id,
                        ],
                        alidoId: $aliadoId
                    );

                    $ant->update([
                        'valor_aplicado' => 0,
                        'estado' => \App\Models\Anticipo::ESTADO_DISPONIBLE,
                        'factura_id' => null,
                    ]);
                });

                // Las consignaciones se eliminan físicamente (quedan en el snapshot de la bitácora)
                DB::table('consignaciones')->where('factura_id', $f->id)->delete();
                DB::table('abonos')->where('factura_id', $f->id)->delete();
            }
        });

        return response()->json([
            'ok' => true,
            'mensaje' => $facturasAnular->count() > 1
                ? "{$facturasAnular->count()} facturas del recibo #{$factura->numero_factura} anuladas."
                : "Recibo #{$factura->numero_factura} anulado.",
        ]);
    }

    // ─── Listado de facturas anuladas ────────────────────────────────
    public function anuladas(Request $request)
    {
        $aliadoId = session('aliado_id_activo');
        $q = Factura::onlyTrashed()
            ->where('aliado_id', $aliadoId)
            ->with(['contrato.cliente', 'razonSocial']);

        // Filtros opcionales
        if ($request->filled('cedula')) {
            $q->where('cedula', $request->cedula);
        }
        if ($request->filled('mes')) {
            $q->where('mes', $request->mes);
        }
        if ($request->filled('anio')) {
            $q->where('anio', $request->anio);
        }
        if ($request->filled('buscar')) {
            $b = $request->buscar;
            $q->where(fn ($sq) => $sq->where('cedula', 'like', "%$b%")
                ->orWhere('numero_factura', 'like', "%$b%")
                ->orWhere('motivo_anulacion', 'like', "%$b%"));
        }

        $facturas = $q->orderByDesc('deleted_at')->paginate(25)->withQueryString();

        return view('admin.facturacion.anuladas', compact('facturas'));
    }

    // ─── Restaurar una factura anulada ───────────────────────────────
    public function restaurar(Request $request, int $facturaId)
    {
        $user = Auth::user();
        if (! $user || (! $user->hasRole('admin') && ! $user->hasRole('superadmin'))) {
            return response()->json(['ok' => false, 'message' => 'Sin permisos.'], 403);
        }

        $aliadoId = session('aliado_id_activo');
        $factura = Factura::onlyTrashed()
            ->where('aliado_id', $aliadoId)
            ->findOrFail($facturaId);

        // Su FE ya se anuló ante la DIAN: restaurado, el recibo quedaría cobrado
        // sin factura válida y el envío automático no lo volvería a emitir.
        $nota = \App\Models\DataicoNotaCredito::where('aliado_id', $aliadoId)
            ->where('numero_factura', $factura->numero_factura)
            ->where('estado', \App\Models\DataicoNotaCredito::ESTADO_ENVIADO)
            ->first();
        if ($nota) {
            return response()->json([
                'ok' => false,
                'message' => "No se puede restaurar: su factura electrónica {$nota->factura_dataico_numero} ya se anuló ante "
                            ."la DIAN con la nota crédito {$nota->numero}. Si hay que cobrar, haga un recibo nuevo.",
            ], 422);
        }

        DB::transaction(function () use ($factura, $aliadoId, $user) {
            $factura->restore();
            $factura->motivo_anulacion = null;
            $factura->anulado_por = null;
            $factura->save();

            // Restaurar el plano asociado si existe
            Plano::onlyTrashed()->where('factura_id', $factura->id)->restore();

            Bitacora::registrar(
                accion: 'updated',
                modelo: 'Factura',
                registroId: $factura->id,
                descripcion: 'Recibo #'.$factura->numero_factura.' restaurado por '.($user->nombre ?? $user->name).'.',
                detalle: ['restaurado_por' => $user->id],
                alidoId: $aliadoId
            );
        });

        return response()->json(['ok' => true, 'mensaje' => "Recibo #{$factura->numero_factura} restaurado correctamente."]);
    }

    // ─── Recibo de abono ─────────────────────────────────────────────
    public function reciboAbono(int $abonoId)
    {
        $aliadoId = session('aliado_id_activo');
        $abono = Abono::whereHas('factura', fn ($q) => $q->where('aliado_id', $aliadoId))
            ->with(['factura.contrato.cliente', 'usuario'])
            ->findOrFail($abonoId);

        return view('admin.facturacion.recibo-abono', compact('abono'));
    }

    // ─── API: Saldo previo de cliente ────────────────────────────────
    public function saldoCliente(Request $request, int $cedula)
    {
        $aliadoId = session('aliado_id_activo');
        $mes = (int) $request->mes;
        $anio = (int) $request->anio;

        return response()->json(
            Factura::saldoClienteMesPrevio($aliadoId, $cedula, $mes, $anio)
        );
    }

    // ─── API: Verificar si mes ya está facturado para un contrato ────
    public function mesPagado(Request $request, int $contratoId)
    {
        $aliadoId = session('aliado_id_activo');
        $contrato = Contrato::where('aliado_id', $aliadoId)->find($contratoId);
        if (! $contrato) {
            return response()->json(['pagado' => false, 'mes' => null, 'anio' => null]);
        }

        $mes = (int) ($request->mes ?? now()->month);
        $anio = (int) ($request->anio ?? now()->year);

        // Verificar si ya existe factura pagada o pre-factura para este periodo
        $existe = Factura::where('aliado_id', $aliadoId)
            ->where('contrato_id', $contratoId)
            ->where('mes', $mes)
            ->where('anio', $anio)
            ->whereIn('estado', ['pagada', 'pre_factura', 'abono', 'prestamo'])
            ->exists();

        // Si ya está pagado, calcular el siguiente mes disponible
        $mesSiguiente = $mes;
        $anioSiguiente = $anio;
        if ($existe) {
            $mesSiguiente++;
            if ($mesSiguiente > 12) {
                $mesSiguiente = 1;
                $anioSiguiente++;
            }
        }

        // Calcular saldo del cliente para el nuevo mes — SOLO del contrato actual
        $saldo = Factura::saldoClienteMesPrevio(
            $aliadoId,
            $contrato->cedula,
            $existe ? $mesSiguiente : $mes,
            $existe ? $anioSiguiente : $anio,
            $contrato->id  // ← aislar por contrato, no mezclar con otros contratos de la misma cédula
        );

        // Verificar si hay meses anteriores sin facturar (orden secuencial)
        $gap = $this->verificarOrdenFacturacion(
            $aliadoId,
            $contrato,
            $existe ? $mesSiguiente : $mes,
            $existe ? $anioSiguiente : $anio
        );

        // ── Préstamos pendientes del cliente ──────────────────────────
        // Retorna facturas en estado=prestamo con saldo restante > 0.
        // El JS del modal usa esto para ofrecer cobrar el préstamo junto a la nueva factura.
        $prestamosRaw = Factura::where('aliado_id', $aliadoId)
            ->where('cedula', $contrato->cedula)
            ->prestamoPendiente()
            ->with('abonos')
            ->get();

        $prestamosPendientes = $prestamosRaw
            ->filter(fn ($f) => $f->saldo_pendiente_prestamo > 0)
            ->map(fn ($f) => [
                'id' => $f->id,
                'mes' => $f->mes,
                'anio' => $f->anio,
                'total' => (int) $f->total,
                'saldo' => $f->saldo_pendiente_prestamo,
            ])->values();

        $targetMes = $existe ? $mesSiguiente : $mes;
        $targetAnio = $existe ? $anioSiguiente : $anio;
        $diasSugeridos = $this->calcularDias($contrato, $targetMes, $targetAnio);

        return response()->json([
            'pagado' => $existe,
            'mes' => $targetMes,
            'anio' => $targetAnio,
            'dias_sugeridos' => $diasSugeridos,
            'saldo_a_favor' => $saldo['a_favor'] ?? 0,
            'saldo_pendiente' => $saldo['pendiente'] ?? 0,
            // Información de gap para advertencia en UI
            'tiene_gap' => ! is_null($gap),
            'gap_bloquea' => (bool) ($gap['bloquea'] ?? false),
            'gap_mes' => $gap['mes'] ?? null,
            'gap_anio' => $gap['anio'] ?? null,
            'gap_mensaje' => $gap['mensaje'] ?? null,
            // Primer mes sin facturar, para que el modal abra ahí y no en el mes en curso
            'pendiente' => $this->primerMesPendiente($aliadoId, $contrato),
            // Préstamos pendientes del cliente
            'tiene_prestamo_pendiente' => $prestamosPendientes->isNotEmpty(),
            'prestamos_pendientes' => $prestamosPendientes,
            // ── Mora pre-calculada para el modal ─────────────────────────
            // El JS la inyecta en el campo editable con MF.setMora()
            ...$this->_calcularMoraParaModal($aliadoId, $contrato, $targetMes, $targetAnio),
        ]);
    }

    // ─── API: N_PLANO actual de una razón social ─────────────────────
    public function planoActual(Request $request, int $razonSocialId)
    {
        $aliadoId = session('aliado_id_activo');
        $rs = DB::table('razones_sociales')->find($razonSocialId);
        $actual = DB::table('planos')
            ->where('aliado_id', $aliadoId)
            ->max('n_plano') ?? 0;

        return response()->json([
            'n_plano_actual' => $actual,
            'n_plano_siguiente' => $actual + 1,
            'razon_social' => $rs?->razon_social ?? '',
        ]);
    }

    // ─── Helpers privados ────────────────────────────────────────────

    /**
     * Verifica que no exista un "gap" (mes sin facturar) antes del período solicitado.
     *
     * Regla: el primer mes facturable es el mes de fecha_ingreso.
     * Cada mes siguiente debe tener al menos una factura registrada antes de permitir
     * facturar el mes actual.
     *
     * @return array|null null si todo OK; ['mes','anio','mensaje'] si hay gap.
     */
    /**
     * ¿En este período el contrato cobra afiliación pura (su mes de ingreso)?
     *
     * Sirve para no confundir dos cobros distintos de la misma persona: quien
     * ingresó y se retiró dentro del mismo mes debe pagar la AFILIACIÓN en el
     * mes de ingreso, y su seguridad social proporcional va aparte en la
     * factura de retiro (numero_factura=0), normalmente del mes siguiente.
     * Sin esta distinción el flujo de retiro facturable se comía la selección
     * y la afiliación quedaba sin cobrar.
     */
    /**
     * Retiro facturable: contrato ya retirado que dejo una factura en $0 al
     * marcarse el retiro, y que ahora se cobra de verdad dentro del lote.
     *
     * Lo consultan las dos pasadas de facturar(): la que cuesta cada contrato
     * para repartir el pago, y la que crea la factura. Tienen que decidir lo
     * mismo o el reparto no cuadra con lo que se cobra.
     */
    private function _tieneRetiroFacturable(Contrato $contrato, $facturasRetiro0Lote, int $mes, int $anio): bool
    {
        return $contrato->estado === 'retirado'
            && $facturasRetiro0Lote->has($contrato->id)
            // En su mes de ingreso lo que se cobra es la afiliación: la factura 0
            // del retiro se conserva para el período que le corresponde.
            && ! $this->_esAfiliacionDelMesDeIngreso($contrato, $mes, $anio);
    }

    /**
     * Lo que cuesta un retiro facturable, sin los "otros" del lote (que se
     * reparten despues, sobre esta base). Misma cuenta que hace el bloque de
     * retiro al crear la factura real.
     */
    private static function _totalRetiroFacturable(Contrato $contrato, Factura $facturaRetiro0, bool $incluirAdmonRetiroCorto): int
    {
        $totalSS = (int) ($facturaRetiro0->v_eps ?? 0)
            + (int) ($facturaRetiro0->v_arl ?? 0)
            + (int) ($facturaRetiro0->v_afp ?? 0)
            + (int) ($facturaRetiro0->v_caja ?? 0);

        // Admon: solo se cobra si días > 3, o si el usuario marcó "incluir admon retiro corto"
        $admon = 0;
        $adminAsesor = 0;
        if ((int) ($facturaRetiro0->dias_cotizados ?? 0) > 3 || $incluirAdmonRetiroCorto) {
            $admon = intval($contrato->administracion ?? 0);
            $adminAsesor = intval($contrato->admon_asesor ?? 0);
        }

        $iva = \App\Services\IvaService::deFactura(
            \App\Services\IvaService::aplicaContrato($contrato),
            $admon,
            $adminAsesor,
            0
        );

        return max(0, $totalSS + $admon + $adminAsesor + $iva);
    }

    /** Lo que el aliado ya dio por consumido del crédito de estos contratos. */
    private function ajustesDeContratos(int $aliadoId, array $contratoIds): int
    {
        $cedulas = Contrato::where('aliado_id', $aliadoId)
            ->whereIn('id', $contratoIds)
            ->pluck('cedula');

        return (int) \App\Models\SaldoAjuste::where('aliado_id', $aliadoId)
            ->whereIn('cedula', $cedulas->map(fn ($c) => (string) $c))
            ->sum('valor');
    }

    private function _esAfiliacionDelMesDeIngreso(Contrato $contrato, int $mes, int $anio): bool
    {
        if (! $contrato->fecha_ingreso) {
            return false;
        }
        // Quien cotiza el mes en curso cobra afiliación y planilla juntas: ese caso
        // sí es el flujo normal, no una afiliación pura.
        if ((bool) ($contrato->paga_mes_actual ?? false)) {
            return false;
        }
        // Solo seguro: se cobra la mensualidad todos los meses, nunca afiliación.
        if ($contrato->esSoloSeguro()) {
            return false;
        }

        return (int) $contrato->fecha_ingreso->month === $mes
            && (int) $contrato->fecha_ingreso->year === $anio;
    }

    private function verificarOrdenFacturacion(int $aliadoId, Contrato $contrato, int $mes, int $anio): ?array
    {
        // Convertir a entero YYYYMM para comparación simple
        $periodoTarget = $anio * 100 + $mes;

        // Obtener todos los períodos (YYYYMM) que ya tienen factura para este contrato
        $periodosBilled = Factura::where('aliado_id', $aliadoId)
            ->where('contrato_id', $contrato->id)
            ->whereIn('estado', ['pagada', 'pre_factura', 'abono', 'prestamo'])
            ->get(['mes', 'anio'])
            ->map(fn ($f) => (int) $f->anio * 100 + (int) $f->mes)
            ->unique()
            ->sort()
            ->values();

        // Si no hay ninguna factura previa, permitir facturar libremente
        if ($periodosBilled->isEmpty()) {
            return null;
        }

        // El período máximo ya facturado
        $ultimoPeriodo = $periodosBilled->max();

        // Si el período solicitado ya existe o es el siguiente natural, OK
        if ($periodosBilled->contains($periodoTarget)) {
            return null; // ya facturado (anti-duplicado lo manejará después)
        }

        // Calcular el "siguiente esperado" al último facturado
        $ultimoMes = $ultimoPeriodo % 100;
        $ultimoAnio = (int) ($ultimoPeriodo / 100);
        $sigMes = $ultimoMes === 12 ? 1 : $ultimoMes + 1;
        $sigAnio = $ultimoMes === 12 ? $ultimoAnio + 1 : $ultimoAnio;
        $siguientePeriodo = $sigAnio * 100 + $sigMes;

        // Si el target ES el siguiente esperado, está perfecto
        if ($periodoTarget === $siguientePeriodo) {
            return null;
        }

        // Si el target es MENOR al último, puede ser retro-facturación de un hueco puntual — permitir
        if ($periodoTarget < $ultimoPeriodo) {
            return null;
        }

        // Hay un salto: el período solicitado está más de 1 mes adelante del último facturado
        // Exigir que se facture el mes inmediatamente siguiente al último
        $nombreFaltante = \Carbon\Carbon::create($sigAnio, $sigMes, 1)->translatedFormat('F Y');
        $nombreTarget = \Carbon\Carbon::create($anio, $mes, 1)->translatedFormat('F Y');

        // El independiente paga su propia planilla: si dejó meses sin pagar, esos
        // meses ya no se pueden cotizar, pero el mes en curso sí — y bloquearlo
        // lo deja sin cobertura también este mes. Se avisa del hueco y se deja
        // facturar, solo el mes en curso: saltar a un mes futuro sigue sin poder.
        $hoy = now();
        $esMesEnCurso = $mes === (int) $hoy->month && $anio === (int) $hoy->year;
        $esIndependiente = $contrato->esIndependiente()
            || (bool) ($contrato->razonSocial?->es_independiente);

        if ($esIndependiente && $esMesEnCurso) {
            return [
                'mes' => $sigMes,
                'anio' => $sigAnio,
                'bloquea' => false,
                'mensaje' => "Tiene meses sin facturar desde {$nombreFaltante}. Como es independiente, se permite facturar {$nombreTarget}.",
            ];
        }

        return [
            'mes' => $sigMes,
            'anio' => $sigAnio,
            'bloquea' => true,
            'mensaje' => "Debe facturar {$nombreFaltante} antes de continuar con {$nombreTarget}.",
        ];
    }

    /**
     * El primer mes que le falta facturar al contrato, o null si no hay hueco.
     *
     * Con facturas es el siguiente al último facturado. Sin ninguna es el mes de
     * ingreso: un contrato creado tarde —el 57008 de Yesenia Vidal entró el
     * 01/07 y se creó en septiembre— abría el modal en el mes en curso y dejaba
     * julio y agosto sin cobrar, porque verificarOrdenFacturacion() no ve hueco
     * cuando no hay nada facturado. Ese caso se limita a contratos sin planos y
     * con ingreso en los últimos doce meses: los migrados del legacy traen su
     * historia en planos y facturarlos desde el ingreso sería reabrir años.
     *
     * @return array{mes:int, anio:int}|null
     */
    private function primerMesPendiente(int $aliadoId, Contrato $contrato): ?array
    {
        $ultimo = Factura::where('aliado_id', $aliadoId)
            ->where('contrato_id', $contrato->id)
            ->whereIn('estado', ['pagada', 'pre_factura', 'abono', 'prestamo'])
            ->selectRaw('MAX(anio * 100 + mes) as periodo')
            ->value('periodo');

        if ($ultimo) {
            $fecha = \Carbon\Carbon::create(intdiv((int) $ultimo, 100), (int) $ultimo % 100, 1)->addMonth();
        } else {
            $ingreso = $contrato->fecha_ingreso;
            $conPlanos = Plano::where('aliado_id', $aliadoId)->where('contrato_id', $contrato->id)->exists();
            if (! $ingreso || $conPlanos || $ingreso->lt(now()->startOfMonth()->subYear())) {
                return null;
            }
            $fecha = $ingreso->copy()->startOfMonth();
        }

        return ['mes' => (int) $fecha->month, 'anio' => (int) $fecha->year];
    }

    /**
     * Calcula el n_plano para una razón social en un período dado.
     *
     * Regla de negocio:
     * - Si el período facturado (mes/anio) coincide con el mes activo de la RS
     *   (rs.mes_pagos / rs.anio_pagos), se usa rs.n_plano (ej: P3).
     * - Para cualquier otro mes —futuro (junio, julio…) o pasado (abril)—
     *   siempre retorna 1: las facturas se acumulan en el primer lote del período
     *   hasta que ese mes se convierta en el mes activo y el aliado avance el contador.
     */
    private static function _nPlanoParaRS(int $aliadoId, ?int $razonSocialId, int $mes, int $anio): int
    {
        if (! $razonSocialId) {
            return 1;
        }

        $rs = \App\Models\RazonSocial::find($razonSocialId);
        if (! $rs) {
            return 1;
        }

        // Solo el mes activo de la RS usa su n_plano actual.
        // Cualquier otro mes (pasado o futuro) siempre empieza en 1.
        if ((int) $rs->mes_pagos === $mes && (int) $rs->anio_pagos === $anio) {
            return (int) ($rs->n_plano ?? 1);
        }

        return 1;
    }

    /**
     * Pre-calcula la mora al cliente para inyectarla en el modal individual.
     * Se llama desde mesPagado() y los datos se incluyen en la respuesta JSON.
     *
     * El JS usa MF.setMora(mora, infoTexto) para rellenar el campo editable.
     *
     * @return array{mora_cliente: int, mora_dias: int, mora_fecha_vence: string|null, mora_dia_habil: int, mora_info: string}
     */
    private function _calcularMoraParaModal(int $aliadoId, Contrato $contrato, int $mes, int $anio): array
    {
        try {
            $rsNit = $contrato->nitParaMora();
            $rsDiaH = $contrato->diaHabilParaMora();

            if (! $rsNit) {
                return ['mora_cliente' => 0, 'mora_dias' => 0, 'mora_fecha_vence' => null, 'mora_dia_habil' => 0, 'mora_info' => ''];
            }

            // Detectar si el contrato es afiliación para este período
            $esIndAct = (bool) ($contrato->paga_mes_actual ?? false);
            $esArlModalidad = (int) ($contrato->tipo_modalidad_id) === 15;
            $esMesIngreso = $contrato->fecha_ingreso
                && (int) $contrato->fecha_ingreso->month === $mes
                && (int) $contrato->fecha_ingreso->year === $anio;
            $esAfiliacion = $esArlModalidad || ($esMesIngreso && ! $esIndAct);

            // Quien cotiza el mes en curso paga por adelantado: no hay plazo
            // vencido que penalizar. Misma regla que CobroContratoService.
            if ($esIndAct) {
                return ['mora_cliente' => 0, 'mora_real' => 0, 'mora_dias' => 0, 'mora_fecha_vence' => null,
                    'mora_dia_habil' => 0, 'mora_info' => '📅 Mes actual — sin mora', 'mora_aplica' => false];
            }

            // Contrato con ingreso en mes futuro: aún no inicia → sin mora
            if ($contrato->fecha_ingreso) {
                $periodoIngreso = (int) $contrato->fecha_ingreso->year * 100 + (int) $contrato->fecha_ingreso->month;
                $periodoActual = $anio * 100 + $mes;
                if ($periodoIngreso > $periodoActual) {
                    return ['mora_cliente' => 0, 'mora_real' => 0, 'mora_dias' => 0, 'mora_fecha_vence' => null, 'mora_dia_habil' => 0, 'mora_info' => '✅ Ingreso futuro — sin mora', 'mora_aplica' => false];
                }
            }
            // Solo seguro: no hay planilla que pagar tarde.
            if ($contrato->esSoloSeguro()) {
                return ['mora_cliente' => 0, 'mora_real' => 0, 'mora_dias' => 0, 'mora_fecha_vence' => null, 'mora_dia_habil' => 0, 'mora_info' => '💼 Seguro — sin mora', 'mora_aplica' => false];
            }

            // Las afiliaciones nunca tienen mora (no hay pago de planilla)
            if ($esAfiliacion) {
                return ['mora_cliente' => 0, 'mora_real' => 0, 'mora_dias' => 0, 'mora_fecha_vence' => null, 'mora_dia_habil' => 0, 'mora_info' => '✅ Afiliación — sin mora', 'mora_aplica' => false];
            }

            // Usar la última factura de este contrato para obtener el total_ss real
            // Si aún no hay factura (estamos creando la primera), calcular desde cotización
            $ultimaFact = Factura::where('aliado_id', $aliadoId)
                ->where('contrato_id', $contrato->id)
                ->where('mes', $mes)->where('anio', $anio)
                ->whereNotIn('estado', ['anulada'])
                ->first();

            if ($ultimaFact) {
                $totalSS = (float) $ultimaFact->total_ss;
            } else {
                // Estimar total SS desde cotización (30 días)
                $cotiz = $contrato->calcularCotizacion(30);
                $totalSS = (float) ($cotiz['ss'] ?? 0);
            }

            if ($totalSS <= 0) {
                return ['mora_cliente' => 0, 'mora_dias' => 0, 'mora_fecha_vence' => null, 'mora_dia_habil' => 0, 'mora_info' => ''];
            }

            $info = MoraClienteService::calcular($aliadoId, $rsNit, $rsDiaH, $totalSS, $mes, $anio);

            $meses = ['', 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
            $vence = $info['fecha_vence'] ? $info['fecha_vence']->format('d').' '.($meses[$info['fecha_vence']->month] ?? '').' '.$info['fecha_vence']->year : null;
            // El "sin mora" lleva la hora: pasado el corte bancario el pago ya
            // no se abona ese día y la mora empieza a correr aunque la fecha de
            // vencimiento sea hoy — ver MoraClienteService::fechaAbono().
            $horaCorte = \Carbon\Carbon::createFromFormat('H:i', MoraClienteService::HORA_CORTE)
                ->format('g:i a');
            $infoTexto = $info['aplica']
                ? "⚠️ {$info['dias_mora']} días mora · día hábil {$info['dia_habil']} · vence {$vence}"
                : "✅ Sin mora si paga hasta día hábil {$info['dia_habil']}"
                  .($vence ? " ($vence)" : '')." antes de las {$horaCorte}";

            return [
                'mora_cliente' => $info['mora'],
                'mora_real' => (int) round($info['mora_real'] ?? 0),  // sin tramos: solo para Retiro
                'mora_dias' => $info['dias_mora'],
                'mora_fecha_vence' => $info['fecha_vence'] ? $info['fecha_vence']->toDateString() : null,
                'mora_dia_habil' => $info['dia_habil'],
                'mora_info' => $infoTexto,
                'mora_aplica' => $info['aplica'],
            ];
        } catch (\Throwable $e) {
            // No interrumpir la carga del modal por error en mora
            return ['mora_cliente' => 0, 'mora_real' => 0, 'mora_dias' => 0, 'mora_fecha_vence' => null, 'mora_dia_habil' => 0, 'mora_info' => '', 'mora_aplica' => false];
        }
    }

    /** Delega a CobroContratoService (fuente única, también usada por la tool de IA). */
    private function calcularDias(Contrato $contrato, int $mes, int $anio): int
    {
        return \App\Services\CobroContratoService::calcularDias($contrato, $mes, $anio);
    }

    /**
     * SS de un contrato para N días.
     *
     * Delega en Contrato::calcularCotizacion() — fuente única de verdad, la misma
     * que usan la UI, los retiros y el cotizador. Antes tenía su propia aritmética
     * y prorrateaba ARL/AFP/CAJA con round() al centena más cercano en vez de ceil,
     * lo que dejaba la factura $100 por debajo de lo que liquida el operador PILA.
     */
    private function calcularSS(Contrato $contrato, int $dias): array
    {
        $c = $contrato->calcularCotizacion($dias);

        return [
            'eps' => (int) ($c['eps'] ?? 0),
            'arl' => (int) ($c['arl'] ?? 0),
            'afp' => (int) ($c['pen'] ?? 0),
            'caja' => (int) ($c['caja'] ?? 0),
        ];
    }

    // ─── API: Saldos para N contratos (modo masivo empresa) ─────────
    // GET /admin/facturacion/api/saldos-contratos?contratos[]=1&contratos[]=2&mes=5&anio=2026
    public function saldosContratos(Request $request)
    {
        $aliadoId = session('aliado_id_activo');
        $contratoIds = array_filter((array) $request->contratos);
        $mes = (int) ($request->mes ?? now()->month);
        $anio = (int) ($request->anio ?? now()->year);

        // ── Obtener empresa_id desde los contratos seleccionados ───────
        // Todos los contratos del modal masivo pertenecen a la misma empresa.
        // Calculamos el saldo neto de la empresa (SUM saldo_proximo de TODOS los
        // contratos de la empresa, no solo los seleccionados) para que el
        // anticipo de un trabajador compense la cartera de otro.
        $empresaId = null;
        if (! empty($contratoIds)) {
            $primerContrato = Contrato::where('aliado_id', $aliadoId)
                ->whereIn('id', $contratoIds)
                ->with('cliente')
                ->first();
            if ($primerContrato) {
                // Buscar empresa_id desde clientes.cod_empresa
                $empresaId = DB::table('clientes')
                    ->where('cedula', $primerContrato->cedula)
                    ->value('cod_empresa');
            }
        }

        // ── Saldo neto REAL de la empresa ────────────────────────────────
        // Se suma TODOS los saldo_proximo de empresa_id sin restricción de fecha.
        // Razón: al facturar un lote parcial (ej. 5 de 14 contratos en Mayo),
        // los 9 ya facturados de Mayo tienen saldo_proximo negativo que DEBEN
        // compensar el saldo positivo de Abril. Si filtramos por mes < actual,
        // excluimos esos negativos de Mayo y el saldo queda inflado.
        //
        // Lógica:
        //   saldoNeto > 0 → empresa tiene anticipo a favor
        //   saldoNeto < 0 → empresa tiene cartera pendiente
        //   saldoNeto = 0 → empresa está al día (caso Fabio Arroyave)
        $saldoNeto = 0;
        if ($empresaId) {
            $saldoNeto = (int) Factura::where('aliado_id', $aliadoId)
                ->where('empresa_id', $empresaId)
                ->whereIn('estado', ['pagada', 'prestamo', 'abono'])
                ->whereNotNull('saldo_proximo')
                ->whereNull('deleted_at')
                ->sum('saldo_proximo');
        } else {
            // Fallback: sumar por contratos individuales si no hay empresa_id
            foreach ($contratoIds as $cId) {
                $contrato = Contrato::where('aliado_id', $aliadoId)->find($cId);
                if (! $contrato) {
                    continue;
                }
                $saldo = Factura::saldoClienteMesPrevio($aliadoId, $contrato->cedula, $mes, $anio, $cId);
                $saldoNeto += ($saldo['a_favor'] ?? 0) - ($saldo['pendiente'] ?? 0);
            }
        }

        // Convertir saldo neto a a_favor / pendiente para compatibilidad con el JS
        $totalAFavor = $saldoNeto > 0 ? $saldoNeto : 0;
        $totalPendiente = $saldoNeto < 0 ? abs($saldoNeto) : 0;

        return response()->json([
            'total_a_favor' => $totalAFavor,
            'total_pendiente' => $totalPendiente,
            'saldo_neto' => $saldoNeto,   // neto para debugging
        ]);
    }

    // ─── Historial de pagos del cliente ─────────────────────────────
    public function historial(Request $request, int $cedula)
    {
        $aliadoId = session('aliado_id_activo');

        // Buscar el cliente directamente por cédula (no depende de contratos)
        $cliente = \App\Models\Cliente::where('aliado_id', $aliadoId)
            ->where('cedula', $cedula)
            ->first();

        // Si no existe el cliente en este aliado, abortar
        abort_if(! $cliente, 404, 'Cliente no encontrado.');

        // Traemos el contrato de referencia (solo para contexto visual del header)
        $contrato = Contrato::where('aliado_id', $aliadoId)
            ->where('cedula', $cedula)
            ->whereIn('estado', ['vigente', 'activo'])
            ->with(['cliente', 'razonSocial'])
            ->orderByDesc('created_at')
            ->first();

        // Si no hay contrato vigente, buscar cualquiera
        if (! $contrato) {
            $contrato = Contrato::where('aliado_id', $aliadoId)
                ->where('cedula', $cedula)
                ->with(['cliente', 'razonSocial'])
                ->orderByDesc('created_at')
                ->first();
        }

        // Filtros opcionales
        $filtroAnio = $request->integer('anio', 0);
        $filtroRs = $request->get('razon_social_id', '');

        // Query principal con eager loading optimizado para contrato y razón social
        $query = Factura::where('aliado_id', $aliadoId)
            ->where('cedula', $cedula)
            ->with(['contrato.razonSocial', 'razonSocial', 'empresa', 'plano.razonSocial', 'usuario'])
            ->orderByDesc('anio')
            ->orderByDesc('mes');

        if ($filtroAnio > 0) {
            $query->where('anio', $filtroAnio);
        }
        if ($filtroRs !== '') {
            $query->where('razon_social_id', $filtroRs);
        }

        // Sin filtros activos: últimas 20
        $sinFiltros = ! $filtroAnio && $filtroRs === '';
        if ($sinFiltros) {
            $facturas = $query->limit(20)->get();
        } else {
            $facturas = $query->get();
        }

        // Agrupar: [contrato_label → [anio → [facturas]]]
        $agrupado = [];
        foreach ($facturas as $f) {
            if ($f->contrato_id) {
                $rsName = $f->contrato?->razonSocial?->razon_social
                       ?? $f->razonSocial?->razon_social
                       ?? $f->plano?->razonSocial?->razon_social
                       ?? 'Sin razón social';
                $grupoKey = "Contrato #{$f->contrato_id} — {$rsName}";
            } else {
                $grupoKey = 'Sin contrato';
            }
            $anio = $f->anio;
            $agrupado[$grupoKey][$anio][] = $f;
        }

        // Mapa razon_social_name → contrato_ids (todos los contratos del cliente, agrupados por RS)
        $todosContratos = Contrato::where('aliado_id', $aliadoId)
            ->where('cedula', $cedula)
            ->with('razonSocial')
            ->orderBy('id')
            ->get();

        $contratosporRS = []; // ["Nombre RS" => [id1, id2, ...]]
        foreach ($todosContratos as $c) {
            $rsNombre = $c->razonSocial?->razon_social ?? 'Sin razón social';
            $contratosporRS[$rsNombre][] = $c->id;
        }

        // Para filtros: años y razones sociales disponibles del cliente
        $aniosDisp = Factura::where('aliado_id', $aliadoId)
            ->where('cedula', $cedula)
            ->select('anio')->distinct()->orderByDesc('anio')
            ->pluck('anio');

        $rsSocDisp = Factura::where('aliado_id', $aliadoId)
            ->where('cedula', $cedula)
            ->with('razonSocial')
            ->select('razon_social_id')->distinct()
            ->get()
            ->map(fn ($f) => [
                'id' => $f->razon_social_id,
                'label' => $f->razonSocial?->razon_social ?? 'Sin razón social',
            ])->unique('id');

        $meses = ['', 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun',
            'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];

        // ─── Comprobantes de pago planilla (batch, una sola query) ───────────
        // Para cada factura con plano.numero_planilla, buscamos el gasto pago_planilla
        // correspondiente y obtenemos su imagen (soporte del pago al operador).
        $numeroPlanillas = $facturas
            ->map(fn ($f) => $f->plano?->numero_planilla)
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        $soportesPlanilla = collect();
        $operadoresPlanillaInfo = collect();
        $gastosPlanilla = collect();
        if (! empty($numeroPlanillas)) {
            $gastosPlanilla = \App\Models\Gasto::where('aliado_id', $aliadoId)
                ->where('tipo', 'pago_planilla')
                ->whereIn('numero_planilla', $numeroPlanillas)
                ->get(['numero_planilla', 'imagen_path', 'descripcion', 'pagado_a'])
                ->keyBy('numero_planilla');

            // Filtrar los que tienen soporte con imagen
            $soportesPlanilla = $gastosPlanilla->filter(fn ($g) => ! empty($g->imagen_path));

            // Cargar operadores (ID y Nombre) desde la API de planillas
            $operadoresPlanillaInfo = \DB::table('operador_planillas_api')
                ->where('operador_planillas_api.aliado_id', $aliadoId)
                ->whereIn('operador_planillas_api.numero_planilla', $numeroPlanillas)
                ->join('operadores_planilla', 'operadores_planilla.id', '=', 'operador_planillas_api.operador_planilla_id')
                ->select('operadores_planilla.id', 'operadores_planilla.nombre', 'operador_planillas_api.numero_planilla')
                ->get()
                ->keyBy('numero_planilla');
        }

        $operadoresTodosMap = \DB::table('operadores_planilla')->pluck('id', 'nombre');

        // Lo que dijo el operador de cada número de planilla (si cruza, a qué hora
        // se pagó) y si este aliado puede bajar los soportes reales de ARUS o Simple.
        $verificacionPlanillas = empty($numeroPlanillas) ? collect() : \DB::table('planillas_verificacion_operador')
            ->where('aliado_id', $aliadoId)
            ->whereIn('numero_planilla', $numeroPlanillas)
            ->get(['numero_planilla', 'estado', 'mensaje', 'verificada_at'])
            ->keyBy('numero_planilla');
        $pagosOperador = empty($numeroPlanillas) ? collect() : \DB::table('planillas_pago_operador')
            ->where('aliado_id', $aliadoId)
            ->whereIn('numero_planilla', $numeroPlanillas)
            ->pluck('fecha_pago', 'numero_planilla');
        $aliadoConSoporteOperador = \App\Models\OperadorCredencial::where('aliado_id', $aliadoId)
            ->whereIn('operador_planilla_id', \DB::table('operadores_planilla')
                ->whereIn('codigo', array_keys(\App\Services\SuaporteApiService::HOSTS))->pluck('id'))
            ->exists();

        $feEstados = $this->estadosFacturaElectronica($facturas, $aliadoId);

        return view('admin.facturacion.historial', compact(
            'cliente', 'contrato', 'cedula', 'agrupado',
            'filtroAnio', 'filtroRs', 'sinFiltros',
            'aniosDisp', 'rsSocDisp', 'meses', 'contratosporRS',
            'soportesPlanilla', 'operadoresPlanillaInfo', 'gastosPlanilla',
            'operadoresTodosMap', 'feEstados',
            'verificacionPlanillas', 'pagosOperador', 'aliadoConSoporteOperador'
        ));
    }

    // ─── Imagen de consignación ────────────────────────────────────────
    /**
     * POST /admin/facturacion/consignacion/{id}/imagen
     * Sube la imagen/PDF de soporte de una consignación y guarda la ruta.
     */
    public function subirImagenConsignacion(Request $request, int $id)
    {
        $aliadoId = session('aliado_id_activo');

        $consig = \App\Models\Consignacion::where('id', $id)
            ->where('aliado_id', $aliadoId)
            ->firstOrFail();

        $request->validate([
            'imagen' => 'required|file|mimes:jpg,jpeg,png,pdf,webp|max:8192', // 8 MB
        ]);

        // Eliminar imagen anterior si existe
        if ($consig->imagen_path && \Storage::disk('public')->exists($consig->imagen_path)) {
            \Storage::disk('public')->delete($consig->imagen_path);
        }

        $file = $request->file('imagen');
        $ext = $file->getClientOriginalExtension();
        $path = $file->storeAs(
            "consignaciones/{$aliadoId}/{$consig->factura_id}",
            "{$id}.{$ext}",
            'public'
        );

        $consig->update(['imagen_path' => $path]);

        return response()->json([
            'ok' => true,
            'url' => \Storage::url($path),
        ]);
    }

    /**
     * GET /admin/facturacion/consignacion/{id}/imagen
     * Redirige a la URL pública de la imagen de soporte.
     */
    public function verImagenConsignacion(int $id)
    {
        $aliadoId = session('aliado_id_activo');

        $consig = \App\Models\Consignacion::where('id', $id)
            ->where('aliado_id', $aliadoId)
            ->firstOrFail();

        if (! $consig->imagen_path || ! \Storage::disk('public')->exists($consig->imagen_path)) {
            abort(404, 'Imagen no encontrada.');
        }

        return redirect(\Storage::url($consig->imagen_path));
    }

    // ─── Facturar Otro Ingreso (Trámite) ─────────────────────────────
    /**
     * POST /admin/facturacion/otro-ingreso
     * Crea una factura de tipo 'otro_ingreso' (trámites, servicios adicionales).
     * NO genera plano PILA. IVA aplica si cliente OR empresa tiene iva='SI'.
     * Asesor se toma del campo asesor del cliente o de la empresa.
     */
    public function facturarOtroIngreso(Request $request)
    {
        $aliadoId = session('aliado_id_activo');

        $validated = $request->validate([
            'cedula' => 'required|integer',
            'descripcion_tramite' => 'required|string|max:300',
            'mes' => 'required|integer|min:1|max:12',
            'anio' => 'required|integer|min:2000|max:2100',
            'valor_admon' => 'required|numeric|min:0',
            'valor_asesor' => 'nullable|numeric|min:0',
            'forma_pago' => 'required|in:efectivo,consignacion,mixto,prestamo',
            'estado' => 'required|in:pre_factura,pagada,prestamo',
            'valor_efectivo' => 'nullable|numeric|min:0',
            'valor_prestamo' => 'nullable|numeric|min:0',
            'consignaciones' => 'nullable|array',
            'consignaciones.*.banco_cuenta_id' => 'required_with:consignaciones|integer',
            'consignaciones.*.valor' => 'required_with:consignaciones|numeric|min:0',
            'consignaciones.*.fecha' => 'nullable|date',
            'consignaciones.*.referencia' => 'nullable|string|max:100',
            'empresa_id' => 'nullable|integer',
            'observacion' => 'nullable|string|max:500',
        ]);

        $cedula = (int) $validated['cedula'];
        $mes = (int) $validated['mes'];
        $anio = (int) $validated['anio'];

        // ── IVA: aplica si cliente OR empresa tienen iva='SI' ─────────
        $clienteIva = DB::table('clientes')->where('cedula', $cedula)->value('iva');
        $empresaId = $validated['empresa_id'] ?? null;
        $empresaIva = $empresaId
            ? DB::table('empresas')->where('id', $empresaId)->value('iva')
            : null;

        $aplicaIva = strtoupper(trim($clienteIva ?? '')) === 'SI'
                  || strtoupper(trim($empresaIva ?? '')) === 'SI';

        $valorAdmon = (int) ($validated['valor_admon'] ?? 0);
        $valorAsesor = (int) ($validated['valor_asesor'] ?? 0);
        $ivaBase = $valorAdmon + $valorAsesor;

        $iva = 0;
        if ($aplicaIva && $ivaBase > 0) {
            $cfgIva = \App\Models\ConfiguracionBrynex::porcentajeIva();
            $iva = (int) ceil($ivaBase * $cfgIva / 100 / 100) * 100;
        }

        $total = $valorAdmon + $valorAsesor + $iva;

        // ── Pagos ──────────────────────────────────────────────────────
        $consignacionesData = $validated['consignaciones'] ?? [];
        $totalConsig = array_sum(array_column($consignacionesData, 'valor'));
        $totalEfectivo = (int) ($validated['valor_efectivo'] ?? 0);
        $totalPrestamo = (int) ($validated['valor_prestamo'] ?? 0);

        // Mismo candado que en facturar(): un trámite marcado préstamo sin monto
        // queda invisible en el módulo de cobro.
        if ($validated['estado'] === 'prestamo' && $totalPrestamo <= 0) {
            return response()->json([
                'error' => true,
                'mensaje' => '🚫 Marcaste el trámite como PRÉSTAMO pero el campo Préstamo quedó en $0. '
                    .'Escribe cuánto queda debiendo el cliente, o cambia el estado a Pagada.',
            ], 422);
        }

        // ── Saldo previo del cliente ────────────────────────────────────
        $saldo = Factura::saldoClienteMesPrevio($aliadoId, $cedula, $mes, $anio);

        // Y el candado en el otro sentido: marcado PAGADA sin el dinero
        // registrado, el faltante queda escondido en saldo_proximo — no es
        // préstamo, no lo cobra nadie. Solo el saldo a favor previo justifica
        // pagar de menos.
        if ($validated['estado'] === Factura::ESTADO_PAGADA) {
            $favor = (int) ($saldo['a_favor'] ?? 0);
            $faltante = $total - (int) $totalConsig - $totalEfectivo - $favor;
            if ($faltante > self::TOLERANCIA_PAGO) {
                throw new \App\Exceptions\PagoIncompletoException(
                    totalLote: (int) $total,
                    pagadoLote: (int) $totalConsig + $totalEfectivo,
                    saldoFavor: $favor,
                    faltante: $faltante,
                );
            }
        }

        // Fecha del recibo: la de la consignación si el pago es solo banco
        $fechaPagoRecibo = $this->_fechaPagoRecibo($validated['forma_pago'], $consignacionesData);

        $factura = DB::transaction(function () use (
            $aliadoId, $cedula, $mes, $anio, $validated,
            $valorAdmon, $valorAsesor, $iva, $total,
            $totalConsig, $totalEfectivo, $totalPrestamo,
            $consignacionesData, $empresaId, $fechaPagoRecibo
        ) {
            $numeroFactura = Factura::siguienteNumero($aliadoId);

            $factura = Factura::create([
                'aliado_id' => $aliadoId,
                'numero_factura' => $numeroFactura,
                'tipo' => Factura::TIPO_OTRO_INGRESO,
                'cedula' => $cedula,
                'contrato_id' => null,
                'empresa_id' => $empresaId,
                'mes' => $mes,
                'anio' => $anio,
                'fecha_pago' => $fechaPagoRecibo,
                'estado' => $validated['estado'],
                'es_prestamo' => $validated['estado'] === 'prestamo',
                'forma_pago' => $validated['forma_pago'],
                'valor_consignado' => (int) $totalConsig,
                'valor_efectivo' => $totalEfectivo,
                'valor_prestamo' => $totalPrestamo,
                // SS = 0 (no es planilla)
                'dias_cotizados' => 0,
                'v_eps' => 0,
                'v_arl' => 0,
                'v_afp' => 0,
                'v_caja' => 0,
                'total_ss' => 0,
                // Valores administrativos
                'admon' => $valorAdmon,
                'admin_asesor' => 0,     // planilla: no aplica
                'admon_asesor_oi' => $valorAsesor,
                'iva' => $iva,
                'total' => max(0, $total),
                'seguro' => 0,
                'afiliacion' => 0,
                'mensajeria' => 0,
                'otros' => 0,
                // Descripción del trámite
                'descripcion_tramite' => $validated['descripcion_tramite'],
                'observacion' => $validated['observacion'] ?? null,
                'usuario_id' => Auth::id(),
            ]);

            // ── saldo_proximo ──────────────────────────────────────────
            // Siempre: pagadoReal - total. Negativo = saldo pendiente.
            // Para préstamos con pago parcial esto refleja el saldo real
            // adeudado (no el total bruto completo).
            $pagadoReal = (int) $factura->valor_consignado + (int) $factura->valor_efectivo;
            $saldoProximo = $pagadoReal - (int) $factura->total;
            $factura->update(['saldo_proximo' => $saldoProximo]);

            // ── Consignaciones ─────────────────────────────────────────
            foreach ($consignacionesData as $cs) {
                $valorCs = (int) $cs['valor'];
                if ($valorCs <= 0) {
                    continue;
                }
                \App\Models\Consignacion::create([
                    'aliado_id' => $aliadoId,
                    'factura_id' => $factura->id,
                    'banco_cuenta_id' => (int) $cs['banco_cuenta_id'],
                    'fecha' => $cs['fecha'] ?? now()->toDateString(),
                    'valor' => $valorCs,
                    'referencia' => $cs['referencia'] ?? null,
                    'confirmado' => false,
                    'usuario_id' => Auth::id(),
                ]);
            }

            // ── NO se genera plano PILA ────────────────────────────────
            return $factura;
        });

        return response()->json([
            'ok' => true,
            'mensaje' => 'Otro ingreso registrado correctamente. Recibo #'.$factura->numero_factura,
            'factura_id' => $factura->id,
            'recibo_url' => route('admin.facturacion.recibo', $factura->id),
            'consignacion_ids' => \App\Models\Consignacion::where('factura_id', $factura->id)
                ->orderBy('id')->pluck('id')->values()->all(),
        ]);
    }

    // ─── Historial de facturación de una empresa ────────────────────
    public function historialEmpresa(Request $request, int $empresaId)
    {
        $aliadoId = session('aliado_id_activo');
        $empresa = Empresa::where('aliado_id', $aliadoId)->findOrFail($empresaId);

        $facturas = Factura::where('aliado_id', $aliadoId)
            ->where('empresa_id', $empresaId)
            ->with(['usuario', 'consignaciones.bancoCuenta'])
            ->orderByDesc('fecha_pago')
            ->orderByDesc('id')
            ->get();

        // Agrupar por numero_factura (cada NP puede tener varios trabajadores con el mismo número)
        $grupos = $facturas->groupBy('numero_factura')->map(function ($grupo) {
            $primera = $grupo->first();

            // Saldo generado por este NP → positivo = sobró (va al siguiente mes),
            // negativo = consumió saldo previo, cero = equilibrado.
            $saldoProximoTotal = $grupo->sum(fn ($f) => (int) ($f->saldo_proximo ?? 0));

            return (object) [
                'id' => $primera->id,
                'np' => $primera->np,
                'tipo' => $primera->tipo,
                'numero_factura' => $primera->numero_factura,
                'fecha_pago' => $primera->fecha_pago,
                'mes' => $primera->mes,
                'anio' => $primera->anio,
                'estado' => $primera->estado,
                'descripcion_tramite' => $primera->descripcion_tramite,
                // Basta con que una fila del grupo esté marcada: la factura
                // electrónica de un lote es una sola para todos sus afiliados.
                'fe_marcada' => (bool) $grupo->max('fe_marcada'),
                'total' => $grupo->sum(fn ($f) => (int) $f->total),
                'cantidad' => $grupo->count(),
                'usuario' => $primera->usuario,
                // ── Saldo ──────────────────────────────────────────────────
                // saldo_proximo > 0 → generó anticipo para el siguiente mes
                // saldo_proximo < 0 → consumió saldo que venía de meses anteriores
                // saldo_proximo = 0 → equilibrado
                'saldo_proximo' => $saldoProximoTotal,
                'saldo_a_favor_aplicado' => 0, // columna eliminada — ya no disponible
            ];
        })->values();

        $feEstados = $this->estadosFacturaElectronica($facturas, $aliadoId);

        return view('admin.facturacion.historial_empresa', compact('empresa', 'grupos', 'facturas', 'feEstados'));
    }

    // ─── Crear empresa ──────────────────────────────────────────────
    /**
     * Estado de facturación electrónica de un grupo de facturas.
     *
     * Se carga de una sola consulta y se pasa a la vista: pedirlo por fila
     * serían decenas de viajes al SQL Server, que a ~250 ms cada uno se notan
     * en un historial de veinte filas.
     *
     * @param  \Illuminate\Support\Collection  $facturas
     */
    private function estadosFacturaElectronica($facturas, int $aliadoId): array
    {
        $numeros = $facturas->pluck('numero_factura')->filter()->unique()->values();

        if ($numeros->isEmpty()) {
            return [];
        }

        return \App\Models\DataicoEnvio::where('aliado_id', $aliadoId)
            ->whereIn('numero_factura', $numeros->all())
            ->get(['numero_factura', 'estado', 'dataico_numero', 'cufe', 'error_mensaje'])
            ->keyBy('numero_factura')
            ->all();
    }

    /**
     * Reglas de la ficha de empresa. Las comparten crear y editar: si viven en
     * cada método, se agrega un campo a uno y el otro lo descarta en silencio.
     */
    /**
     * Reparte un monto del lote entre sus contratos, en proporción a lo que
     * cuesta cada uno.
     *
     * El residuo del redondeo va completo al último para que la suma de las
     * partes sea EXACTAMENTE el monto: repartiendo peso a peso, el lote queda
     * unos pesos por encima o por debajo de lo que mostró la pantalla y el
     * pago del cliente deja de cuadrar. Es la misma regla que ya usan la
     * consignación, el efectivo y el saldo a favor.
     *
     * @param  array<int|string,int>  $pesos  costo de cada contrato, por id
     * @return array<int|string,int>  la parte de cada contrato, por id
     */
    private static function _repartirProporcional(int $monto, array $pesos, int $granTotal): array
    {
        $claves = array_keys($pesos);
        $partes = array_fill_keys($claves, 0);

        if ($monto === 0 || $claves === []) {
            return $partes;
        }

        $n = count($claves);
        $acum = 0;
        foreach ($claves as $i => $k) {
            if ($i === $n - 1) {
                $partes[$k] = $monto - $acum;   // el último se lleva el residuo
                break;
            }
            // Sin costos (todo en cero) no hay proporción que valga: se parte en partes iguales.
            $parte = $granTotal > 0
                ? (int) round($monto * $pesos[$k] / $granTotal)
                : intdiv($monto, $n);
            $partes[$k] = $parte;
            $acum += $parte;
        }

        return $partes;
    }

    private function reglasEmpresa(): array
    {
        return [
            'empresa' => 'required|string|max:255',
            'nit' => 'nullable|numeric',
            // Los cuatro documentos que caben en `nit`, que es un bigint.
            'tipo_documento' => 'nullable|in:'.implode(',', array_keys(Empresa::TIPOS_DOC)),
            // Nombre según el documento: es el que viaja a la factura
            // electrónica. `empresa` sigue siendo el nombre del negocio.
            'nombre_legal' => 'nullable|string|max:255',
            // Apagado, la factura sale a consumidor final.
            'factura_electronica' => 'nullable|boolean',
            // Apagado, el aportante paga SENA e ICBF y la salud le va al 12,5%.
            'exonerado_parafiscales' => 'nullable|boolean',
            'contacto' => 'nullable|string|max:255',
            'contacto_celular' => 'nullable|string|max:50',
            'telefono' => 'nullable|string|max:50',
            'celular' => 'nullable|string|max:50',
            'correo' => 'nullable|email|max:150',
            'direccion' => 'nullable|string|max:255',
            'departamento_id' => 'nullable|exists:departamentos,id',
            'municipio_id' => 'nullable|exists:ciudades,id',
            'iva' => 'nullable|string|max:20',
            'asesor_id' => 'nullable|exists:asesores,id',
            'observacion' => 'nullable|string|max:500',
        ];
    }

    /**
     * @return array{0: array, 1: ?array} datos listos y, si algo no cuadra, el error
     */
    private function normalizarEmpresa(array $datos): array
    {
        // Una casilla sin marcar no llega en la petición: hay que traducirla a
        // un false explícito o el interruptor nunca se podría apagar.
        $datos['factura_electronica'] = ! empty($datos['factura_electronica']);
        $datos['exonerado_parafiscales'] = ! empty($datos['exonerado_parafiscales']);

        // Una sociedad no tiene «nombre según el documento»: su razón social ya
        // es el nombre legal. Guardarlo dejaría un dato colgando si mañana
        // alguien cambia el tipo.
        if (($datos['tipo_documento'] ?? null) === 'NIT') {
            $datos['nombre_legal'] = null;
        }

        // Los celulares se guardan como los diez dígitos y nada más. A este
        // campo salen las cuentas de cobro y las planillas por WhatsApp, y
        // hasta ahora entraba cualquier cosa que cupiera en 50 caracteres: dos
        // números separados por una barra, un fijo, un «311 - casa». Meta los
        // rechaza sin avisar —el mensaje simplemente no llega— así que se
        // valida aquí, contra la misma regla que usa el envío, y no cuando ya
        // es tarde. `telefono` no entra: ese sí es el fijo.
        foreach (['celular' => 'El celular', 'contacto_celular' => 'El celular del contacto'] as $campo => $etiqueta) {
            if (! array_key_exists($campo, $datos)) {
                continue;
            }

            $valor = trim((string) $datos[$campo]);

            if ($valor === '') {
                $datos[$campo] = null;
                continue;
            }

            if (! \App\Services\WhatsappApiService::esCelularColombiano($valor)) {
                return [$datos, [$campo => "{$etiqueta} («{$valor}») no es un celular colombiano. Debe ser un solo número de diez dígitos que empiece por 3; si hay dos, deja el que recibe WhatsApp."]];
            }

            $datos[$campo] = \App\Services\WhatsappApiService::formatoNacional($valor);
        }

        if (array_key_exists('telefono', $datos)) {
            $datos['telefono'] = trim((string) $datos['telefono']) ?: null;
        }

        // La ciudad tiene que ser del departamento elegido, o el par queda
        // incoherente y la factura electrónica rebota: la DIAN valida la
        // combinación.
        if (! empty($datos['municipio_id'])) {
            $pertenece = \App\Models\Ciudad::where('id', $datos['municipio_id'])
                ->where('departamento_id', $datos['departamento_id'] ?? 0)
                ->exists();

            if (! $pertenece) {
                return [$datos, ['municipio_id' => 'La ciudad elegida no pertenece a ese departamento.']];
            }
        }

        return [$datos, null];
    }

    public function createEmpresa()
    {
        $aliadoId = session('aliado_id_activo');
        $asesores = \App\Models\Asesor::where('aliado_id', $aliadoId)
            ->orderBy('nombre')
            ->get(['id', 'nombre']);

        $departamentos = \App\Models\Departamento::orderBy('nombre')->get(['id', 'nombre']);

        return view('admin.facturacion.empresa_create', compact('asesores', 'departamentos'));
    }

    // ─── Guardar empresa nueva ──────────────────────────────────────────────
    public function storeEmpresa(Request $request)
    {
        $aliadoId = session('aliado_id_activo');

        $validated = $request->validate($this->reglasEmpresa());

        [$validated, $error] = $this->normalizarEmpresa($validated);

        if ($error) {
            return back()->withInput()->withErrors($error);
        }

        $validated['aliado_id'] = $aliadoId;

        // Workaround para tabla legacy de SQL Server sin IDENTITY
        $maxId = \App\Models\Empresa::max('id');
        $validated['id'] = $maxId ? $maxId + 1 : 1;

        $empresa = \App\Models\Empresa::create($validated);

        return redirect()->route('admin.facturacion.index')
            ->with('success', 'Empresa creada exitosamente.');
    }

    // ─── Editar empresa ──────────────────────────────────────────────
    public function editEmpresa(int $empresaId)
    {
        $aliadoId = session('aliado_id_activo');
        $empresa = Empresa::where('aliado_id', $aliadoId)->findOrFail($empresaId);
        $asesores = \App\Models\Asesor::where('aliado_id', $aliadoId)
            ->orderBy('nombre')
            ->get(['id', 'nombre']);

        $departamentos = \App\Models\Departamento::orderBy('nombre')->get(['id', 'nombre']);

        $accesoPortal = \App\Models\EmpresaAcceso::where('empresa_id', $empresa->id)->first();

        return view('admin.facturacion.empresa_edit', compact('empresa', 'asesores', 'departamentos', 'accesoPortal'));
    }

    // ─── Actualizar empresa ──────────────────────────────────────────
    public function updateEmpresa(Request $request, int $empresaId)
    {
        $aliadoId = session('aliado_id_activo');
        $empresa = Empresa::where('aliado_id', $aliadoId)->findOrFail($empresaId);

        $validated = $request->validate($this->reglasEmpresa());

        [$validated, $error] = $this->normalizarEmpresa($validated);

        if ($error) {
            return back()->withInput()->withErrors($error);
        }

        $empresa->update($validated);

        return redirect()
            ->route('admin.facturacion.empresa', [
                'id' => $empresaId,
                'mes' => now()->month,
                'anio' => now()->year,
            ])
            ->with('success', 'Empresa actualizada correctamente.');
    }

    // ─── Cuenta de Cobro ─────────────────────────────────────────────
    public function cuentaCobroPreview(Request $request)
    {
        $aliadoId = session('aliado_id_activo');
        $aliado = \App\Models\Aliado::find($aliadoId);

        $contratoIds = $request->input('contratos', []);
        $mes = (int) $request->input('mes', now()->month);
        $anio = (int) $request->input('anio', now()->year);
        $empresaId = (int) $request->input('empresa_id');
        $tipo = $request->input('tipo', 'simple'); // simple | detallada

        $empresa = Empresa::where('aliado_id', $aliadoId)->find($empresaId);
        $admonRetiroCompleta = $request->input('admon_retiro_completa', '1') === '1'; // checkbox de admon en retiros
        // Botón de la barra: permite emitir la cuenta de cobro sin cobrar la mora.
        // Se le quita a todo el que aún no ha pagado — sin factura o con factura
        // pendiente/abono/préstamo. La factura ya PAGADA conserva su mora: ese
        // dinero ya entró y quitarlo descuadraría lo cobrado.
        $incluirMora = $request->input('incluir_mora', '1') === '1';

        // Cuentas bancarias marcadas para cobro
        $cuentasCobro = BancoCuenta::paraCobro($aliadoId);

        // Contratos seleccionados con sus relaciones
        $contratos = Contrato::where('aliado_id', $aliadoId)
            ->whereIn('id', $contratoIds)
            ->with(['cliente', 'tipoModalidad', 'razonSocial', 'eps', 'arl', 'pension', 'caja'])
            ->get();

        // Facturas existentes para el período — indexadas por contrato_id
        $facturasExistentes = Factura::where('aliado_id', $aliadoId)
            ->periodo($mes, $anio)
            ->whereIn('tipo', ['planilla', 'afiliacion'])
            ->whereIn('cedula', $contratos->pluck('cedula'))
            ->whereNotNull('contrato_id')
            ->where('numero_factura', '>', 0)  // excluir factura temporal de retiro (número 0)
            ->get()
            ->keyBy('contrato_id');

        // Facturas de retiro 0 pendientes (para retiros aún no facturados formalmente)
        $facturasRetiro0 = Factura::where('aliado_id', $aliadoId)
            ->whereIn('contrato_id', $contratos->pluck('id'))
            ->where('numero_factura', 0)
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('contrato_id');

        $r100 = fn ($v) => (int) (ceil(($v ?? 0) / 100) * 100);
        // El IVA NO se redondea a centena: es impuesto, va exacto como se factura.
        $rIva = fn ($v) => (int) round($v ?? 0);

        // ── Pre-calcular mora estimada por contrato en lote ──
        $moraPorContrato = [];
        $filasMora = [];
        // IVA por cédula: manda la empresa del cliente; si no tiene, su marca (ver IvaService)
        $ivaClientes = \App\Services\IvaService::mapaPorCedulas($aliadoId, $contratos->pluck('cedula'));

        // Con la mora apagada no hay nada que estimar: se ahorra el cálculo en lote
        foreach ($incluirMora ? $contratos : collect() as $c) {
            $fact = $facturasExistentes->get($c->id);
            if ($fact) {
                continue;
            }
            if ($c->estado === 'retirado') {
                continue;
            }

            $diasCotizar = 30;
            $esIndActPrimerMes = false;
            $esArlModalidad = (int) ($c->tipo_modalidad_id) === 15;
            // Solo seguro: no cotiza, no paga administración y no genera planilla.
            $esSoloSeguro = (int) ($c->tipo_modalidad_id) === \App\Models\Contrato::MODALIDAD_SEGUROS;

            if ($esSoloSeguro || $esArlModalidad) {
                $diasCotizar = 0;
            } elseif ($c->fecha_ingreso) {
                $fIng = $c->fecha_ingreso;
                $mesIngreso = (int) $fIng->month;
                $anioIngreso = (int) $fIng->year;
                $esIndAct = (bool) ($c->paga_mes_actual ?? false);

                if ($mesIngreso === $mes && $anioIngreso === $anio) {
                    if ($esIndAct) {
                        $esIndActPrimerMes = true;
                        $diasCotizar = max(1, 30 - $fIng->day + 1);
                    } else {
                        $diasCotizar = 0;
                    }
                } else {
                    $mesAnterior = $mes === 1 ? 12 : $mes - 1;
                    $anioAnterior = $mes === 1 ? $anio - 1 : $anio;
                    if ($mesIngreso === $mesAnterior && $anioIngreso === $anioAnterior) {
                        $diasCotizar = max(1, 30 - $fIng->day + 1);
                    }
                }
            }

            // ¿Es afiliación pura?
            $esAfil = false;
            if ($esSoloSeguro) {
                $esAfil = false;   // el seguro se cobra igual todos los meses
            } elseif ($esArlModalidad) {
                $esAfil = true;
            } elseif ($c->fecha_ingreso) {
                $fIngC = $c->fecha_ingreso;
                if ((int) $fIngC->month === $mes && (int) $fIngC->year === $anio) {
                    if (! $esIndActPrimerMes) {
                        $esAfil = true;
                    }
                }
            }

            if ($esAfil) {
                continue;
            }

            if ($c->estado === 'vigente' && $c->fecha_retiro_pendiente) {
                $diasCotizar = (int) $c->fecha_retiro_pendiente->day;
            }

            $ivaFlag = (bool) ($ivaClientes[$c->cedula] ?? false);
            $cotizCalc = $c->calcularCotizacion($diasCotizar, $ivaFlag);
            $vSS = (int) ($cotizCalc['ss'] ?? 0);
            if ($vSS <= 0) {
                continue;
            }

            $rsNit = $c->nitParaMora();
            if (! $rsNit) {
                continue;
            }

            $filasMora[$c->id] = [
                'contrato_id' => $c->id,
                'rs_nit' => $rsNit,
                'rs_dia_habil' => $c->diaHabilParaMora(),
                'total_ss' => $vSS,
                'eps' => (int) ($cotizCalc['eps'] ?? 0),
                'arl' => (int) ($cotizCalc['arl'] ?? 0),
                'pen' => (int) ($cotizCalc['pen'] ?? 0),
                'caja' => (int) ($cotizCalc['caja'] ?? 0),
                'mes' => $mes,
                'anio' => $anio,
            ];
        }

        if (! empty($filasMora)) {
            $resultadosMora = \App\Services\MoraClienteService::calcularLote($aliadoId, array_values($filasMora));
            foreach ($resultadosMora as $fila) {
                $moraPorContrato[$fila['contrato_id']] = (int) ($fila['mora'] ?? 0);
            }
        }

        $items = $contratos->map(function ($c) use ($mes, $anio, $facturasExistentes, $facturasRetiro0, $admonRetiroCompleta, $r100, $rIva, $aliadoId, $moraPorContrato, $ivaClientes, $incluirMora) {
            $fact = $facturasExistentes->get($c->id);
            $factRetiro0 = $facturasRetiro0->get($c->id);
            $nombre = $c->cliente?->nombre_completo
                      ?? trim(($c->cliente?->primer_nombre ?? '').' '.
                              ($c->cliente?->segundo_nombre ?? '').' '.
                              ($c->cliente?->primer_apellido ?? '').' '.
                              ($c->cliente?->segundo_apellido ?? ''))
                      ?: '—';

            $diasCotizar = 30;
            $esIndActPrimerMes = false;

            $esArlModalidad = (int) ($c->tipo_modalidad_id) === 15;
            // Solo seguro: no cotiza, no paga administración y no genera planilla.
            $esSoloSeguro = (int) ($c->tipo_modalidad_id) === \App\Models\Contrato::MODALIDAD_SEGUROS;
            if ($esSoloSeguro) {
                $diasCotizar = 0;
            } elseif ($esArlModalidad) {
                $fArl = $c->fecha_arl ?? $c->fecha_ingreso;
                if ($fArl) {
                    $mesArl = (int) $fArl->month;
                    $anioArl = (int) $fArl->year;
                    if ($mesArl === $mes && $anioArl === $anio) {
                        $diasCotizar = 0;
                    } else {
                        $diasCotizar = 0;
                    }
                } else {
                    $diasCotizar = 0;
                }
            } elseif ($c->fecha_ingreso) {
                $fIng = $c->fecha_ingreso;
                $mesIngreso = (int) $fIng->month;
                $anioIngreso = (int) $fIng->year;
                $esIndAct = (bool) ($c->paga_mes_actual ?? false);

                $periodoIngreso = $anioIngreso * 100 + $mesIngreso;
                $periodoActual = $anio * 100 + $mes;

                if ($periodoIngreso > $periodoActual) {
                    // Ingreso en mes futuro: el contrato aún no inicia en este período
                    $diasCotizar = 0;
                } elseif ($mesIngreso === $mes && $anioIngreso === $anio) {
                    if ($esIndAct) {
                        $esIndActPrimerMes = true;
                        $diasCotizar = max(1, 30 - $fIng->day + 1);
                    } else {
                        $diasCotizar = 0;
                    }
                } else {
                    $mesAnterior = $mes === 1 ? 12 : $mes - 1;
                    $anioAnterior = $mes === 1 ? $anio - 1 : $anio;

                    if ($mesIngreso === $mesAnterior && $anioIngreso === $anioAnterior) {
                        $diasCotizar = max(1, 30 - $fIng->day + 1);
                    }
                }
            }

            // ¿Es afiliación pura?
            $esAfil = false;
            if ($esArlModalidad) {
                $esAfil = true;
            } elseif ($c->fecha_ingreso) {
                $fIngC = $c->fecha_ingreso;
                if ((int) $fIngC->month === $mes && (int) $fIngC->year === $anio) {
                    if (! $esIndActPrimerMes) {
                        $esAfil = true;
                    }
                }
            }
            if ($fact) {
                $esAfil = $fact->tipo === 'afiliacion' && ! ($fact->afiliacion > 0 && $fact->total_ss > 0);
            }

            // ── Retiro Pendiente: sobreescribir días si el contrato vigente tiene fecha_retiro_pendiente ──
            if ($c->estado === 'vigente' && $c->fecha_retiro_pendiente) {
                $diasCotizar = (int) $c->fecha_retiro_pendiente->day;
            }

            $esRetirado = $c->estado === 'retirado';

            $vMora = 0;
            // Con la mora apagada solo la conserva quien ya pagó su factura
            $moraYaPagada = $fact && $fact->estado === \App\Models\Factura::ESTADO_PAGADA;
            if ($fact && ($fact->mora ?? 0) > 0) {
                if ($incluirMora || $moraYaPagada) {
                    $vMora = (int) $fact->mora;
                }
            } elseif (! $fact && $incluirMora) {
                $vMora = (int) ($moraPorContrato[$c->id] ?? 0);
            }

            if ($fact) {
                $vEps = $r100($fact->v_eps);
                $vArl = $r100($fact->v_arl);
                $vAFP = $r100($fact->v_afp);
                $vCaja = $r100($fact->v_caja);
                $vAdm = (int) ($fact->admon + $fact->admin_asesor);
                $vIva = $rIva($fact->iva);
                // El total facturado ya trae la mora dentro: si se dejó de cobrar,
                // se descuenta aquí para que el documento cuadre con lo que se pide.
                $vTot = (int) $fact->total - (int) (($fact->mora ?? 0) - $vMora);
                $estado = $fact->estado;
                $diasCotizar = (int) $fact->dias_cotizados;
            } elseif ($esRetirado && $factRetiro0 && ! $esAfil) {
                // Retiro pendiente de facturar: usar valores de la factura temporal.
                // En el mes de ingreso manda la afiliación (más abajo): el retiro
                // de quien entró y salió el mismo mes se cobra en su propio período.
                $vEps = $r100($factRetiro0->v_eps);
                $vArl = $r100($factRetiro0->v_arl);
                $vAFP = $r100($factRetiro0->v_afp);
                $vCaja = $r100($factRetiro0->v_caja);
                $diasCotizar = (int) $factRetiro0->dias_cotizados;
                $vAdmonBase = (int) (($c->administracion ?? 0) + ($c->admon_asesor ?? 0));
                $vAdm = $admonRetiroCompleta
                    ? $vAdmonBase
                    : ($diasCotizar <= 3 ? 0 : $vAdmonBase);
                // El IVA grava la admon que se cobre; la factura temporal la guarda en 0
                $vIva = \App\Services\IvaService::calcular($vAdm, (bool) ($ivaClientes[$c->cedula] ?? false));
                $vTot = $r100($factRetiro0->total_ss) + $vAdm + $vIva;
                $estado = 'sin_factura';
            } elseif ($esRetirado && ! $esAfil) {
                $vEps = $vArl = $vAFP = $vCaja = $vIva = $vAdm = 0;
                $vTot = 0;
                $estado = 'sin_factura';
            } elseif ($esSoloSeguro) {
                // El mes vale el seguro y nada más.
                $vEps = $vArl = $vAFP = $vCaja = $vIva = $vAdm = $vSS = 0;
                $vTot = (int) ($c->seguro ?? 0);
                $estado = 'sin_factura';
            } elseif ($esArlModalidad && ! $esAfil) {
                $vEps = $vArl = $vAFP = $vCaja = $vIva = $vAdm = 0;
                $vTot = 0;
                $estado = 'sin_factura';
            } elseif ($esIndActPrimerMes) {
                $tieneIva = (bool) ($ivaClientes[$c->cedula] ?? false);
                $cotiz = $c->calcularCotizacion($diasCotizar, $tieneIva);
                $vEps = $r100($cotiz['eps'] ?? 0);
                $vArl = $r100($cotiz['arl'] ?? 0);
                $vAFP = $r100($cotiz['pen'] ?? 0);
                $vCaja = $r100($cotiz['caja'] ?? 0);
                // IVA: admon (ya viene en $cotiz) + costo de afiliación
                $vIva = $rIva($cotiz['iva'] ?? 0)
                       + \App\Services\IvaService::calcular((int) ($c->costo_afiliacion ?? 0), $tieneIva);
                $vSS = $r100($cotiz['ss']);
                $vAdm = (int) (($c->administracion ?? 0) + ($c->admon_asesor ?? 0));
                $vTot = $vSS + $vAdm + $vIva + (int) (($c->costo_afiliacion ?? 0) + ($c->seguro ?? 0)) + $vMora;
                $estado = 'sin_factura';
            } elseif ($esAfil) {
                $vEps = $vArl = $vAFP = $vCaja = $vAdm = 0;
                // El IVA de una afiliación grava el costo de afiliación
                $vIva = \App\Services\IvaService::calcular(
                    (int) ($c->costo_afiliacion ?? 0),
                    (bool) ($ivaClientes[$c->cedula] ?? false)
                );
                $vTot = (int) (($c->costo_afiliacion ?? 0) + ($c->seguro ?? 0)) + $vIva;
                $estado = 'sin_factura';
            } else {
                $tieneIva = (bool) ($ivaClientes[$c->cedula] ?? false);
                $cotiz = $c->calcularCotizacion($diasCotizar, $tieneIva);
                $vEps = $r100($cotiz['eps'] ?? 0);
                $vArl = $r100($cotiz['arl'] ?? 0);
                $vAFP = $r100($cotiz['pen'] ?? 0);
                $vCaja = $r100($cotiz['caja'] ?? 0);
                $vAdm = (int) (($c->administracion ?? 0) + ($c->admon_asesor ?? 0));
                $vIva = $rIva($cotiz['iva'] ?? 0);
                $vSS = $r100($cotiz['ss'] ?? 0);
                $vTot = $vSS + $vAdm + $vIva + $vMora;
                $estado = 'sin_factura';
            }

            return (object) [
                'cedula' => $c->cedula,
                'nombre' => $nombre,
                'fecha_ingreso' => $c->fecha_ingreso,
                'razon_social' => $this->limpiarRazonSocialCuentaCobro($c->razonSocial?->razon_social),
                'modalidad' => $c->tipoModalidad?->tipo_modalidad ?? '—',
                'eps_nombre' => $c->eps?->nombre ?? '—',

                'arl_nombre' => $c->arl?->nombre ?? '—',
                'n_arl' => $c->n_arl ?? 1,
                'afp_nombre' => $c->pension?->nombre ?? '—',
                'caja_nombre' => $c->caja?->nombre ?? '—',
                'es_afil' => $esAfil,
                'dias' => $diasCotizar,
                'v_eps' => $vEps,
                'v_arl' => $vArl,
                'v_afp' => $vAFP,
                'v_caja' => $vCaja,
                'v_admon' => $vAdm,
                'v_iva' => $vIva,
                'v_mora' => $vMora,
                'v_total' => $vTot,
                'estado' => $estado,
                // Estado del CONTRATO, no del pago: en una cuenta de cobro todo está
                // pendiente por definición, así que lo que informa es si la persona
                // sigue vigente o ya se retiró.
                'estado_contrato' => $esRetirado
                    ? 'retirado'
                    : ($c->fecha_retiro_pendiente ? 'retiro_pendiente' : 'vigente'),
                'fecha_retiro' => $esRetirado
                    ? $c->fecha_retiro
                    : $c->fecha_retiro_pendiente,
                'saldo_proximo' => (int) Factura::saldoClienteMesPrevio(
                    $aliadoId, $c->cedula, $mes, $anio, $c->id
                )['a_favor'] - (int) Factura::saldoClienteMesPrevio(
                    $aliadoId, $c->cedula, $mes, $anio, $c->id
                )['pendiente'],
            ];
        });

        $totalGeneral = $items->sum('v_total');

        // Saldo de meses anteriores de los trabajadores listados: SÍ entra al total.
        // Solo el de quienes aparecen en el documento — no se traen deudas de otros
        // contratos de la empresa, que el cliente no podría ver aquí.
        // saldo_proximo: positivo = a favor del cliente, negativo = pendiente.
        $saldoNetoCC = -1 * (int) $items->sum('saldo_proximo'); // positivo = a cobrar
        $totalFavor = $saldoNetoCC < 0 ? abs($saldoNetoCC) : 0;
        $totalPendiente = $saldoNetoCC > 0 ? $saldoNetoCC : 0;

        $meses = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
            'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

        $vista = $tipo === 'detallada'
            ? 'admin.facturacion.cuenta_cobro_detallada'
            : 'admin.facturacion.cuenta_cobro_simple';

        // ─── Cobros adicionales a incluir en la cuenta de cobro ────────────────
        $cobrosAdicionalesIds = $request->input('cobros_adicionales_ids', []);
        $cobrosAdicionalesCC = collect();
        if ($empresa) {
            $qCobros = \App\Models\CobrosAdicionalEmpresa::where('aliado_id', $aliadoId)
                ->where('empresa_id', $empresa->id)
                ->where('activo', true);
            if (! empty($cobrosAdicionalesIds)) {
                $qCobros->whereIn('id', $cobrosAdicionalesIds);
            }
            $cobrosAdicionalesCC = $qCobros->get();
        }
        $totalCobrosAdicionales = (int) $cobrosAdicionalesCC->sum('valor');
        $totalGeneral += $totalCobrosAdicionales;

        // El saldo entra al total: pendiente suma, a favor descuenta. Nunca negativo.
        $totalGeneral = max(0, $totalGeneral + $saldoNetoCC);

        // IVA: se muestra como un solo renglón en la cuenta de cobro, no por trabajador
        $totalIva = (int) $items->sum('v_iva');
        $ivaPct = \App\Models\ConfiguracionBrynex::porcentajeIva();

        return view($vista, compact(
            'aliado', 'empresa', 'items', 'cuentasCobro',
            'mes', 'anio', 'meses', 'totalGeneral', 'totalFavor', 'totalPendiente', 'saldoNetoCC',
            'cobrosAdicionalesCC', 'totalCobrosAdicionales', 'totalIva', 'ivaPct',
            'tipo', 'incluirMora', 'admonRetiroCompleta'
        ));
    }

    // ─── CRUD: Cobros Adicionales por Empresa ────────────────────────────

    /** Listar cobros adicionales de una empresa (JSON). */
    public function cobrosAdicionalesIndex(Request $request, int $empresaId)
    {
        $aliadoId = session('aliado_id_activo');
        $empresa = Empresa::where('aliado_id', $aliadoId)->findOrFail($empresaId);
        $cobros = \App\Models\CobrosAdicionalEmpresa::where('aliado_id', $aliadoId)
            ->where('empresa_id', $empresa->id)
            ->where('activo', true)
            ->orderBy('tipo')->orderBy('descripcion')->get();

        return response()->json(['ok' => true, 'cobros' => $cobros]);
    }

    /** Crear un cobro adicional para una empresa. */
    public function cobrosAdicionalesStore(Request $request, int $empresaId)
    {
        $aliadoId = session('aliado_id_activo');
        $empresa = Empresa::where('aliado_id', $aliadoId)->findOrFail($empresaId);
        $validated = $request->validate([
            'descripcion' => 'required|string|max:300',
            'valor' => 'required|numeric|min:0',
            'tipo' => 'required|in:unica_vez,recurrente',
        ]);
        $cobro = \App\Models\CobrosAdicionalEmpresa::create([
            'aliado_id' => $aliadoId,
            'empresa_id' => $empresa->id,
            'descripcion' => trim($validated['descripcion']),
            'valor' => (float) $validated['valor'],
            'tipo' => $validated['tipo'],
            'activo' => true,
        ]);
        Bitacora::registrar(
            accion: 'created',
            modelo: 'CobrosAdicionalEmpresa',
            registroId: $cobro->id,
            descripcion: "Cobro adicional '{$cobro->descripcion}' ({$cobro->tipo}) creado para empresa #{$empresa->id}.",
            detalle: $cobro->toArray(),
            alidoId: $aliadoId
        );

        return response()->json(['ok' => true, 'cobro' => $cobro]);
    }

    /**
     * Eliminar un cobro adicional.
     * Recurrentes → desactivar (mantener historial). Únicos → eliminar.
     */
    public function cobrosAdicionalesDestroy(int $cobroId)
    {
        $aliadoId = session('aliado_id_activo');
        $cobro = \App\Models\CobrosAdicionalEmpresa::where('aliado_id', $aliadoId)
            ->findOrFail($cobroId);
        Bitacora::registrar(
            accion: 'deleted',
            modelo: 'CobrosAdicionalEmpresa',
            registroId: $cobro->id,
            descripcion: "Cobro adicional '{$cobro->descripcion}' eliminado de empresa #{$cobro->empresa_id}.",
            detalle: $cobro->toArray(),
            alidoId: $aliadoId
        );
        if ($cobro->tipo === \App\Models\CobrosAdicionalEmpresa::TIPO_RECURRENTE) {
            $cobro->update(['activo' => false]);
        } else {
            $cobro->delete();
        }

        return response()->json(['ok' => true]);
    }

    // ─── Retiro Pendiente desde Vista Empresa ──────────────────────────────────
    // Guarda o limpia la fecha de retiro pendiente de un contrato vigente.
    // El estado del contrato NO cambia aquí; lo hace al momento de facturar.
    public function guardarRetiroPendiente(Request $request, int $contrato): \Illuminate\Http\JsonResponse
    {
        $aliadoId = session('aliado_id_activo');

        $c = \App\Models\Contrato::where('aliado_id', $aliadoId)
            ->where('id', $contrato)
            ->firstOrFail();

        // Seguridad: solo se puede registrar retiro pendiente en contratos vigentes
        if ($c->estado !== 'vigente') {
            return response()->json(['ok' => false, 'mensaje' => 'Solo se puede registrar retiro pendiente en contratos vigentes.'], 422);
        }

        $fechaStr = $request->input('fecha_retiro'); // null para limpiar

        if ($fechaStr === null || $fechaStr === '') {
            // Limpiar retiro pendiente
            $c->update([
                'fecha_retiro_pendiente' => null,
                'retiro_pendiente_cobrar_admon' => null,
            ]);

            \App\Models\Bitacora::registrar(
                accion: 'updated',
                modelo: 'Contrato',
                registroId: $c->id,
                descripcion: "Retiro pendiente cancelado para contrato #{$c->id} (cédula {$c->cedula}).",
                detalle: [],
                alidoId: $aliadoId
            );

            return response()->json(['ok' => true, 'mensaje' => 'Retiro pendiente cancelado.', 'dias' => 30]);
        }

        $request->validate([
            'fecha_retiro' => 'required|date',
            'cobrar_admon' => 'required|boolean',
        ]);

        $fecha = \Carbon\Carbon::parse($fechaStr);
        $dias = (int) $fecha->day; // día del mes = días trabajados (incluye el último)
        $cobrarAdmon = (bool) $request->input('cobrar_admon');

        $c->update([
            'fecha_retiro_pendiente' => $fecha->toDateString(),
            'retiro_pendiente_cobrar_admon' => $cobrarAdmon ? 1 : 0,
        ]);

        \App\Models\Bitacora::registrar(
            accion: 'updated',
            modelo: 'Contrato',
            registroId: $c->id,
            descripcion: "Retiro pendiente registrado para contrato #{$c->id} (cédula {$c->cedula}): fecha {$fecha->toDateString()}, días {$dias}, admon ".($cobrarAdmon ? 'SÍ' : 'NO').'.',
            detalle: [
                'fecha_retiro_pendiente' => $fecha->toDateString(),
                'dias' => $dias,
                'retiro_pendiente_cobrar_admon' => $cobrarAdmon,
            ],
            alidoId: $aliadoId
        );

        return response()->json([
            'ok' => true,
            'dias' => $dias,
            'fecha' => $fecha->format('d/m/Y'),
            'cobrar_admon' => $cobrarAdmon,
        ]);
    }

    // Limpia y recorta sufijos o términos de tipos de sociedad en las razones sociales para simplificar cuentas de cobro
    private function limpiarRazonSocialCuentaCobro(?string $razon): string
    {
        if (! $razon) {
            return '—';
        }
        $patrones = [
            '/\bSOCIEDAD POR ACCIONES SIMPLIFICADAS\b/i',
            '/\bSOCIADAD POR ACCIONES SIMPLIFICADAS\b/i', // typo común
            '/\bSOCIEDAD ANONIMA\b/i',
            '/\bSOCIADAD ANONIMA\b/i', // typo común
            '/\bS\.A\.S\.\b/i',
            '/\bS\.A\.S\b/i',
            '/\bSAS\b/i',
            '/\bS\.A\.\b/i',
            '/\bS\.A\b/i',
            '/\bSA\b/i',
            '/\bLTDA\b/i',
            '/\bLIMITADA\b/i',
        ];
        $limpio = preg_replace($patrones, '', $razon);
        $limpio = trim(preg_replace('/\s+/', ' ', $limpio));

        return rtrim($limpio, ', -') ?: '—';
    }

    // ─── Verificar cédulas para carga masiva ─────────────────────────────────────
    /**
     * POST /admin/facturacion/empresa/{empresa}/verificar-cedulas
     * Recibe un listado de cédulas y devuelve cuáles son válidas para facturar,
     * cuáles ya tienen factura en el período y cuáles no se encuentran en la empresa.
     */
    public function verificarCedulas(Request $request, int $empresaId): \Illuminate\Http\JsonResponse
    {
        $aliadoId = session('aliado_id_activo');

        $request->validate([
            'cedulas' => 'required|array|min:1|max:500',
            'mes' => 'required|integer|min:1|max:12',
            'anio' => 'required|integer|min:2000|max:2100',
        ]);

        $mes = (int) $request->input('mes');
        $anio = (int) $request->input('anio');

        // Normalizar cédulas: quitar espacios, guiones, y filtrar vacías
        $cedulasInput = collect($request->input('cedulas'))
            ->map(fn ($c) => trim(preg_replace('/[^0-9]/', '', (string) $c)))
            ->filter(fn ($c) => strlen($c) >= 5)
            ->unique()
            ->values();

        // Traer las cédulas de la empresa que corresponden a la entrada
        $cedulasEmpresa = \Illuminate\Support\Facades\DB::table('clientes')
            ->where('aliado_id', $aliadoId)
            ->where('cod_empresa', $empresaId)
            ->whereIn('cedula', $cedulasInput->all())
            ->pluck('cedula');

        $mesAnterior = $mes === 1 ? 12 : $mes - 1;
        $anioAnterior = $mes === 1 ? $anio - 1 : $anio;

        // Cargar contratos vigentes de la empresa para este período y aliado
        $contratos = \App\Models\Contrato::where('aliado_id', $aliadoId)
            ->whereIn('cedula', $cedulasEmpresa)
            ->where(function ($q) use ($mes, $anio, $mesAnterior, $anioAnterior) {
                $q->whereIn('estado', ['vigente', 'activo'])
                    ->orWhere(function ($q2) use ($mes, $anio, $mesAnterior, $anioAnterior) {
                        $q2->where('estado', 'retirado')
                            ->where(function ($q3) use ($mes, $anio, $mesAnterior, $anioAnterior) {
                                $q3->where(function ($qa) use ($mes, $anio) {
                                    $qa->where('paga_mes_actual', 1)
                                        ->whereMonth('fecha_retiro', $mes)
                                        ->whereYear('fecha_retiro', $anio);
                                })
                                    ->orWhere(function ($qb) use ($mesAnterior, $anioAnterior) {
                                        $qb->where('paga_mes_actual', 0)
                                            ->whereMonth('fecha_retiro', $mesAnterior)
                                            ->whereYear('fecha_retiro', $anioAnterior);
                                    });
                            });
                    });
            })
            ->with('cliente:id,cedula,primer_nombre,segundo_nombre,primer_apellido,segundo_apellido')
            ->get()
            ->keyBy('cedula');

        // Cédulas que ya tienen factura en el período
        $cedulasFacturadas = \App\Models\Factura::where('aliado_id', $aliadoId)
            ->whereIn('cedula', $contratos->keys()->all())
            ->where('mes', $mes)
            ->where('anio', $anio)
            ->whereNull('deleted_at')
            ->pluck('cedula')
            ->flip(); // para lookup O(1)

        $exitosas = [];
        $yaFacturadas = [];
        $noEncontradas = [];

        foreach ($cedulasInput as $cedula) {
            if (! isset($contratos[$cedula])) {
                $noEncontradas[] = $cedula;

                continue;
            }
            $c = $contratos[$cedula];
            $nombre = trim(
                ($c->cliente?->primer_nombre ?? '').' '.
                ($c->cliente?->primer_apellido ?? '')
            ) ?: ($c->cliente?->nombre_completo ?? '—');

            if (isset($cedulasFacturadas[$cedula])) {
                $yaFacturadas[] = ['cedula' => $cedula, 'nombre' => $nombre];
            } else {
                $exitosas[] = [
                    'cedula' => $cedula,
                    'nombre' => $nombre,
                    'contrato_id' => $c->id,
                ];
            }
        }

        return response()->json([
            'ok' => true,
            'exitosas' => $exitosas,
            'ya_facturadas' => $yaFacturadas,
            'no_encontradas' => $noEncontradas,
            'total_input' => $cedulasInput->count(),
        ]);
    }

    // ─── Asignar NP provisional a contratos ──────────────────────────────────────
    /**
     * POST /admin/facturacion/empresa/{empresa}/asignar-np
     * Guarda un número de NP provisional en contratos.np para los contratos indicados.
     * Solo afecta contratos activos de la empresa y aliado correcto.
     */
    public function asignarNpProvisional(Request $request, int $empresaId): \Illuminate\Http\JsonResponse
    {
        $aliadoId = session('aliado_id_activo');

        $request->validate([
            'contrato_ids' => 'required_without:limpiar_todos|array',
            'contrato_ids.*' => 'integer',
            'np' => 'required|integer|min:0|max:5', // 0 = limpiar (null)
            'limpiar_todos' => 'nullable|boolean',
        ]);

        $np = (int) $request->input('np');

        $cedulasEmpresa = \Illuminate\Support\Facades\DB::table('clientes')
            ->where('aliado_id', $aliadoId)
            ->where('cod_empresa', $empresaId)
            ->pluck('cedula');

        if ($request->input('limpiar_todos')) {
            // Resetear todos los contratos activos de esta empresa
            $actualizados = \App\Models\Contrato::where('aliado_id', $aliadoId)
                ->whereIn('cedula', $cedulasEmpresa)
                ->whereIn('estado', ['activo', 'vigente'])
                ->whereNotNull('np')
                ->update(['np' => null]);

            return response()->json([
                'ok' => true,
                'actualizados' => $actualizados,
                'np' => null,
            ]);
        }

        $contratoIds = $request->input('contrato_ids');
        $npValor = $np === 0 ? null : (string) $np; // 0 → limpiar

        // Seguridad: solo actualizar contratos de la empresa y aliado correcto
        $actualizados = \App\Models\Contrato::where('aliado_id', $aliadoId)
            ->whereIn('cedula', $cedulasEmpresa)
            ->whereIn('id', $contratoIds)
            ->update(['np' => $npValor]);

        return response()->json([
            'ok' => true,
            'actualizados' => $actualizados,
            'np' => $npValor,
        ]);
    }
}
