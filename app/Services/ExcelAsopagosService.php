<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use Illuminate\Support\Facades\DB;
use App\Services\PilaCotizanteCalculator;
use App\Models\Plano;

class ExcelAsopagosService
{
    private const TITULOS_ASOPAGOS = [
        'Tipo de identificación',
        'Número de identificación',
        'Primer apellido',
        'Segundo apellido',
        'Primer nombre',
        'Segundo nombre',
        'Tipo de cotizante',
        'Subtipo de cotizante',
        'Extranjero no obligado a cotizar pensiones',
        'Colombiano temporal en el exterior',
        'Tipo de Salario',
        'Salario básico',
        'Base de Cotización',
        'Otros ingresos para el IBC de parafiscales',
        'Código de administradora de riesgos laborales',
        'Código Centro de trabajo',
        'Código Departamento de la ubicación laboral',
        'Código de municipio de la ubicación laboral',
        'Acogido a exoneración en parafiscales',
        'Indicador de tarifa especial de pensión',
        'EPS',
        'AFP',
        'Caja de compensación',
        'UPC adicional',
        'Tipo de identificación del cotizante titular UPC',
        'Número de identificación del cotizante titular UPC',
        'Tarifa pensión',
        'Tarifa salud',
        'Tarifa Sena',
        'Tarifa ICBF',
        'Tarifa de Caja de compensación',
        'Fecha de ingreso a laborar (aaaa-mm-dd)',
        'Correo electrónico',
        'Correo electrónico CC',
        'Días cotizados (Exclusivo para cotizante 51)',
        'Pagos no constitutivos de salario (Ley 1393 de 2010)',
        'Indique código el soporte de pago de planilla',
        'Fecha de radicación en el exterior (aaaa-mm-dd)',
        'Valor mesada pensional (solo si en el campo subtipo de cotizante relaciona los tipos 1, 2 y 9)',
        'Actividad económica',
        'Código Categoría'
    ];

    private const ACTECO_ARL = [
        1 => '1141001', 2 => '2141003', 3 => '3139202',
        4 => '4131301', 5 => '5131201',
    ];

    public function generar(array $params): Spreadsheet
    {
        $aliadoId      = $params['aliado_id'];
        $razonSocialId = $params['razon_social_id'];
        $mesPago       = (int) $params['mes'];
        $anioPago      = (int) $params['anio'];
        $nPlano        = (int) $params['n_plano'];
        $tiposModal    = $params['tipos_modalidad'] ?? [];

        $mesVencido  = $mesPago > 1 ? $mesPago - 1 : 12;
        $anioVencido = $mesPago > 1 ? $anioPago    : $anioPago - 1;

        $rs = DB::table('razones_sociales')
            ->where('id', $razonSocialId)
            ->where('aliado_id', $aliadoId)
            ->first();

        if (!$rs) {
            throw new \RuntimeException("Razón social {$razonSocialId} no encontrada.");
        }

        // Nombre ARL de la razón social como fallback para Asopagos
        $nombreArlRs = null;
        if (!empty($rs->arl_nit)) {
            $nombreArlRs = DB::table('arls')
                ->where(DB::raw('CAST(nit AS VARCHAR(20))'), (string)$rs->arl_nit)
                ->value('nombre_asopagos');
        }

        $query = DB::table('planos AS p')
            ->leftJoin('facturas AS f',      'f.id',  '=', 'p.factura_id')
            // filtrar por aliado_id: evita duplicar filas si el cliente existe en múltiples aliados
            ->leftJoin('clientes AS cl', function ($join) use ($aliadoId) {
                $join->on('cl.cedula', '=', 'p.no_identifi')
                     ->where('cl.aliado_id', '=', $aliadoId);
            })
            // La exoneración de SENA e ICBF es del aportante, y su NIT lo tiene
            // la empresa del cliente — ver PilaCotizanteCalculator.
            ->leftJoin('empresas AS emp', function ($join) use ($aliadoId) {
                $join->on('emp.id', '=', 'cl.cod_empresa')
                     ->where('emp.aliado_id', '=', $aliadoId);
            })
            ->leftJoin('ciudades AS c',      'c.id_ciudad_t', '=', 'cl.municipio_id')
            ->leftJoin('departamentos AS d', 'd.id',          '=', 'cl.departamento_id')
            ->leftJoin('pensiones AS afp_t', DB::raw('CAST(afp_t.nit AS VARCHAR(20))'), '=', DB::raw('p.cod_afp'))
            ->leftJoin('eps AS eps_t',       DB::raw('CAST(eps_t.nit AS VARCHAR(20))'), '=', DB::raw('p.cod_eps'))
            ->leftJoin('cajas AS caj_t',     DB::raw('CAST(caj_t.nit AS VARCHAR(20))'), '=', DB::raw('p.cod_caja'))
            ->leftJoin('arls AS arl_m',      DB::raw('CAST(arl_m.nit AS VARCHAR(20))'), '=', DB::raw('p.cod_arl'))
            ->leftJoin('tipo_modalidad AS tm', 'tm.id', '=', 'p.tipo_modalidad_id')
            // Solo por la tarifa de caja del cotizante 76 (0,6% o 2%), que es
            // una elección del contrato y no vive en el plano.
            ->leftJoin('contratos AS ctr', 'ctr.id', '=', 'p.contrato_id')
            ->where('p.aliado_id',       $aliadoId)
            ->where('p.razon_social_id', $razonSocialId)
            ->where('p.n_plano',         $nPlano)
            ->whereIn('p.tipo_reg',      ['planilla', 'retiro'])
            ->whereRaw('ISNULL(p.num_dias, 0) > 0')   // excluir num_dias=0 y NULL
            ->whereNull('p.deleted_at')
            ->tap(fn ($q) => Plano::filtrarPeriodoDePago($q, $mesPago, $anioPago))
            ->select([
                'p.tipo_doc', 'p.no_identifi', 'p.tipo_modalidad_id', 'p.paga_mes_actual',
                'p.primer_nombre', 'p.segundo_nombre', 'p.primer_ape', 'p.segundo_ape',
                'p.cod_eps', 'p.cod_afp', 'p.cod_arl', 'p.cod_caja',
                'p.salario_basico', 'p.num_dias', 'p.nivel_riesgo',
                'p.fecha_ing', 'p.fecha_ret', 'p.tipo_p',
                DB::raw('COALESCE(eps_t.nombre_asopagos, p.cod_eps) AS nombre_asopagos_eps'),
                DB::raw('COALESCE(afp_t.nombre_asopagos, p.cod_afp) AS nombre_asopagos_afp'),
                DB::raw('COALESCE(arl_m.nombre_asopagos, p.cod_arl) AS nombre_asopagos_arl'),
                DB::raw('COALESCE(caj_t.nombre_asopagos, p.cod_caja) AS nombre_asopagos_caj'),
                // Códigos tipo EPS010 de las tablas maestras
                DB::raw('eps_t.codigo  AS codigo_eps'),
                DB::raw('afp_t.codigo  AS codigo_afp'),
                DB::raw('arl_m.codigo  AS codigo_arl'),
                DB::raw('caj_t.codigo  AS codigo_caj'),
                'f.v_eps', 'f.v_afp', 'f.v_arl', 'f.v_caja', 'f.total_ss', 'f.dias_cotizados',
                'cl.genero', 'cl.fecha_nacimiento', 'cl.correo AS cliente_correo',
                DB::raw("DATEDIFF(YEAR, cl.fecha_nacimiento, GETDATE()) AS edad_calculada"),
                DB::raw('d.id                    AS cod_departamento'),
                DB::raw('CAST(c.Municipio AS INT) AS cod_municipio'),
                DB::raw('tm.es_tiempo_parcial    AS es_tiempo_parcial'),
                // Manda el snapshot del plano y, si no lo tiene (todo lo anterior
                // al cotizante 76), los días fijos de la modalidad.
                DB::raw('ISNULL(p.dias_tp_afp, ISNULL(tm.dias_afp, 30)) AS dias_afp'),
                DB::raw('ISNULL(p.dias_tp_caja, ISNULL(p.dias_tp_afp, ISNULL(tm.dias_caja, 30))) AS dias_caja'),
                'ctr.porcentaje_caja',
                // Fondo de Solidaridad: el grupo decide la tarifa de pensión del cotizante 33.
                DB::raw('ISNULL(p.grupo_fondo_solidaridad, ctr.grupo_fondo_solidaridad) AS grupo_fondo_solidaridad'),
                DB::raw('emp.exonerado_parafiscales AS exonerado_parafiscales'),
            ]);

        if (!empty($tiposModal)) {
            $query->whereIn('p.tipo_modalidad_id', $tiposModal);
        }

        $planos = $query->orderBy('p.primer_ape')->orderBy('p.primer_nombre')->get();

        Plano::validarPeriodoUnico($planos);

        // ── Spreadsheet ─────────────────────────────────────────────────────
        $spreadsheet = new Spreadsheet();
        // Traza invisible de quién exportó (propiedades del documento).
        app(TrazaArchivoService::class)->marcarExcel($spreadsheet);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Asopagos');

        // Fila 1: Título principal
        $sheet->setCellValue('A1', 'FORMATO CARGUE MASIVO DE EMPLEADOS – ASOPAGOS');
        $sheet->mergeCells('A1:AO1');
        $sheet->getStyle('A1:AO1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A5F']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(28);

        // Fila 2: Encabezados de columnas
        foreach (self::TITULOS_ASOPAGOS as $idx => $titulo) {
            $col = $idx + 1;
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
            $sheet->setCellValue("{$colLetter}2", $titulo);
            $sheet->getStyle("{$colLetter}2")->applyFromArray([
                'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2563EB']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ]);
            $sheet->getColumnDimensionByColumn($col)->setWidth(18);
        }
        $sheet->getRowDimension(2)->setRowHeight(28);

        // Datos de empleados a partir de la fila 3
        $fila = 3;
        foreach ($planos as $p) {
            $this->escribirFila($sheet, $fila, $p, $nombreArlRs);
            $fila++;
        }

        $sheet->freezePane('A3');
        return $spreadsheet;
    }

    private function escribirFila($sheet, int $fila, object $p, ?string $nombreArlRs = null): void
    {
        $c = PilaCotizanteCalculator::calcular($p);

        // Tipo documento
        $tipoDoc = strtoupper(trim($p->tipo_doc ?? 'CC'));
        // PT (Permiso por Protección Temporal) es código PILA válido: no se traduce a CE
        $mapaDoc = ['C'=>'CC','NIT'=>'CC','CE'=>'CE','TI'=>'TI','PA'=>'PA','CD'=>'CD','SC'=>'SC','RC'=>'RC','PE'=>'PE','NUIP'=>'CC','PT'=>'PT'];
        $tipoDoc = $mapaDoc[$tipoDoc] ?? $tipoDoc;

        // Actividad Económica
        $actEco = self::ACTECO_ARL[$c['nivelRiesgo']] ?? null;

        // Fechas ingreso
        $fechaIng = null;
        if (!empty($p->fecha_ing)) {
            try {
                $fechaIng = \Carbon\Carbon::parse($p->fecha_ing)->toDateString();
            } catch (\Exception $e) {
                $fechaIng = substr((string)$p->fecha_ing, 0, 10);
            }
        }

        $esPlanillaY = ((int)$p->tipo_modalidad_id === 8);

        // Mapeo de valores exacto para las 41 columnas
        $valores = [
            1  => substr($tipoDoc, 0, 2),                                                     // Tipo de identificación
            2  => substr((string)$p->no_identifi, 0, 16),                                     // Número de identificación
            3  => substr((string)($p->primer_ape   ?? ''), 0, 20),                            // Primer apellido
            4  => substr((string)($p->segundo_ape  ?? ''), 0, 30),                            // Segundo apellido
            5  => substr((string)($p->primer_nombre?? ''), 0, 20),                            // Primer nombre
            6  => substr((string)($p->segundo_nombre??''), 0, 30),                            // Segundo nombre
            7  => $c['tipoCotizante'],                                                        // Tipo de cotizante
            8  => $esPlanillaY ? 0 : $c['subtipoCotizante'],                                  // Subtipo de cotizante
            9  => $c['esExtranjero'] ? 'X' : null,                                            // Extranjero no obligado a cotizar pensiones
            10 => null,                                                                       // Colombiano temporal en el exterior
            11 => $c['tipoSalarioAplica']                                                     // Tipo de Salario (blank en 23, 51 y 59: PILA lo prohíbe)
                    ? (strtoupper(trim($p->tipo_p ?? '')) === 'I' ? 'I' : 'F')
                    : null,
            12 => $c['ibcFull'],                                                              // Salario básico
            13 => $c['ibcProp'],                                                              // Base de Cotización
            14 => 0,                                                                          // Otros ingresos para el IBC de parafiscales
            15 => $p->nombre_asopagos_arl ?: $nombreArlRs ?: $p->cod_arl,                     // Código de administradora de riesgos laborales (nombre)
            16 => $c['nivelRiesgo'],                                                          // Código Centro de trabajo
            17 => $c['depCod'],                                                               // Código Departamento de la ubicación laboral
            18 => $c['munCod'],                                                               // Código de municipio de la ubicación laboral
            19 => $c['tipoCotizante'] === 3 ? 'N' : 'S',                                      // Acogido a exoneración en parafiscales
            20 => null,                                                                       // Indicador de tarifa especial de pensión
            21 => $esPlanillaY ? null : ($c['tipoCotizante'] === 23 ? null : ($p->nombre_asopagos_eps ?: $c['codEpsPila'])), // EPS (nombre)
            22 => $esPlanillaY ? null : ($c['tienePension'] ? ($p->nombre_asopagos_afp ?: $c['codAfpPila']) : 'SINAFP_SINAFP'), // AFP (nombre)
            23 => $esPlanillaY ? null : ($c['tipoCotizante'] === 23 ? null : ( ($p->nombre_asopagos_caj && $p->nombre_asopagos_caj !== 'CCF68') ? $p->nombre_asopagos_caj : 'CCF68_COMCAJA' )), // Caja de compensación (nombre)
            24 => 0,                                                                          // UPC adicional
            25 => null,                                                                       // Tipo de identificación del cotizante titular UPC
            26 => null,                                                                       // Número de identificación del cotizante titular UPC
            27 => $c['tienePension'] ? rtrim(rtrim($c['tarifaAfpStr'] ?? '0.16', '0'), '.') : '0.00', // Tarifa pensión (el cotizante 33 trae la suya)
            28 => $c['tipoCotizante'] === 23 ? '0.00' : ($c['tipoCotizante'] === 3 ? '0.125' : '0.04'), // Tarifa salud
            29 => '0.00',                                                                     // Tarifa Sena
            30 => '0.00',                                                                     // Tarifa ICBF
            31 => $c['tipoCotizante'] === 23 ? '0.00' : '0.04',                               // Tarifa de Caja de compensación
            32 => $fechaIng,                                                                  // Fecha de ingreso a laborar (aaaa-mm-dd)
            33 => $p->cliente_correo,                                                         // Correo electrónico
            34 => null,                                                                       // Correo electrónico CC
            35 => $c['tipoCotizante'] === 51 ? $c['diasCcf'] : null,                          // Días cotizados (Exclusivo para cotizante 51)
            36 => 0,                                                                          // Pagos no constitutivos de salario (Ley 1393 de 2010)
            37 => null,                                                                       // Indique código el soporte de pago de planilla
            38 => null,                                                                       // Fecha de radicación en el exterior (aaaa-mm-dd)
            39 => null,                                                                       // Valor mesada pensional
            40 => $actEco,                                                                    // Actividad económica
            41 => null,                                                                       // Código Categoría
        ];

        foreach ($valores as $colIdx => $val) {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIdx);
            $cell = $sheet->getCell("{$colLetter}{$fila}");
            $cell->setValue($val ?? '');

            // Documento y tarifas como texto para evitar notación científica y garantizar punto decimal regional
            if (in_array($colIdx, [2, 27, 28, 29, 30, 31])) {
                $cell->getStyle()->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            }
        }
    }

    public function respuesta(Spreadsheet $spreadsheet, string $filename): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $writer = new Xlsx($spreadsheet);
        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control'       => 'max-age=0',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
