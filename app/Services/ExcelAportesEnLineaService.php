<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use Illuminate\Support\Facades\DB;
use App\Models\Plano;

/**
 * ExcelAportesEnLineaService
 *
 * Genera el Excel de Aportes en Línea (formato ASOPAGOS / operador AEL).
 *
 * Hoja 1: Liquidaciones
 *   Filas  1-6  : vacías
 *   Fila   7    : título bloque datos generales
 *   Filas  8-10 : encabezado general (periodo, tipo planilla, sucursal, ARL…)
 *   Fila  12    : título bloque datos de pago
 *   Filas 13-14 : encabezados de pago
 *   Fila  17    : títulos de grupos de columnas
 *   Fila  18    : encabezados de columnas (A→CT = 98 columnas)
 *   Fila  19+   : datos de empleados
 *
 * Hoja 2: DatosPruebaEmp — listas auxiliares de validación
 */
class ExcelAportesEnLineaService
{    // ── Catálogo Tipo de Cotizante PILA ─────────────────────────────────────
    private const TIPO_COTIZANTE = [
         1 => 'DEPENDIENTE',
         2 => 'PENSIONADO',
         3 => 'INDEPENDIENTE',
        12 => 'APRENDICES EN ETAPA LECTIVA',
        15 => 'DESEMPLEADO CON SUBSIDIO DE CAJA DE COMPENSACIÓN FAMILIAR',
        16 => 'INDEPENDIENTE AGREMIADO O ASOCIADO',
        18 => 'FUNCIONARIOS PÚBLICOS SIN TOPE MÁXIMO EN EL IBC',
        19 => 'APRENDICES EN ETAPA PRODUCTIVA',
        20 => 'ESTUDIANTES',
        21 => 'ESTUDIANTE Y RESIDENTE',
        22 => 'PROFESOR DE ESTABLECIMIENTO PARTICULAR',
        23 => 'ESTUDIANTES DECRETO 055',
        30 => 'DEPENDIENTE ENTIDADES O UNIVERSIDADES PÚBLICAS CON RÉGIMEN ESPECIAL EN SALUD',
        31 => 'COOPERADOS O PRECOOPERATIVAS DE TRABAJO ASOCIADO',
        32 => 'BENEFICIARIO UPC ADICIONAL',
        33 => 'BENEFICIARIO DEL FONDO DE SOLIDARIDAD PENSIONAL',
        40 => 'FUNCIONARIO DE ENTIDADES DEL ESTADO',
        41 => 'PARTICIPES DEL ESTADO EN CONTRATO DE RIESGO COMPARTIDO',
        42 => 'INDEPENDIENTE QUE LABORA EN ACTIVIDADES DE ALTO RIESGO',
        43 => 'SERVIDORES PÚBLICOS DE LA RAMA EJECUTIVA DEL ESTADO',
        44 => 'SERVIDORES PÚBLICOS DE ELECCIÓN POPULAR',
        45 => 'SERVIDORES PÚBLICOS',
        47 => 'COTIZANTE ESPECIAL EXTERIOR',
        48 => 'AFILIADO BOLSA NACIONAL AGROPECUARIA',
        49 => 'AFILIADO VOLUNTARIO AL SUBSISTEMA GENERAL DE RIESGOS LABORALES',
        50 => 'AFILIADO VOLUNTARIO AL FONDO DE SOLIDARIDAD PENSIONAL',
        51 => 'TRABAJADOR DE TIEMPO PARCIAL',
        52 => 'MADRE COMUNITARIA',
        55 => 'COTIZANTE FONPRECON',
        56 => 'BENEFICIARIO RÉGIMEN ESPECIAL PENSIONAL FFMM Y POLICÍA',
        57 => 'BENEFICIARIO RÉGIMEN ESPECIAL ECOPETROL',
        58 => 'MAGISTRADO SALA JURISDICCIONAL DISCIPLINARIA CSJ',
        59 => 'DOCENTE OFICIAL',
        60 => 'TRABAJADOR INDEPENDIENTE DE LAS ARTES ESCÉNICAS',
        61 => 'INDEPENDIENTE - RENTISTA DE CAPITAL',
        62 => 'RENTISTA DE CAPITAL PERSONA NATURAL',
        63 => 'COLOMBIANO RESIDENTE EN EL EXTERIOR',
    ];

    // ── Catálogo Subtipo de Cotizante PILA ───────────────────────────────
    private const SUBTIPO_COTIZANTE = [
         0 => 'NINGUNO',
         1 => 'DEPENDIENTE PENSIONADO ACTIVO',
         2 => 'INDEPENDIENTE PENSIONADO ACTIVO',
         3 => 'COTIZANTE NO OBLIGADO A COTIZACIÓN A PENSIONES POR EDAD',
         4 => 'COTIZANTE CON REQUISITOS CUMPLIDOS PARA PENSIÓN',
         5 => 'COTIZANTE CON INDEMNIZACIÓN SUSTITUTIVA O DEVOLUCIÓN DE SALDOS',
         6 => 'COTIZANTE PERTENECIENTE A UN RÉGIMEN EXCEPTUADO DE PENSIONES',
         9 => 'PENSIONADO CON MESADA IGUAL A 25 SMLMV',
        11 => 'TAXISTA',
        12 => 'TAXISTA NO OBLIGADOS A COTIZAR PENSIÓN',
    ];

    private const ACTECO_ARL = [
        1 => '1141001', 2 => '2141003', 3 => '3139202',
        4 => '4131301', 5 => '5131201',
    ];

    // ── Colores ───────────────────────────────────────────────────────────
    private const CLR_DARK   = '1F4E79'; // Azul navy  — grupos fila 17
    private const CLR_MED    = '2E75B6'; // Azul medio — headers fila 18
    private const CLR_SECT   = 'BDD7EE'; // Azul claro — bloques 7 y 12
    private const CLR_WHITE  = 'FFFFFF';
    private const CLR_ALT    = 'EAF3FB'; // Filas alternas empleados

    // ── Encabezados fila 18 (A→CT, 98 columnas) ──────────────────────────
    private const HEADERS = [
        // Empleado (A-O = 1-15)
        'No.','Tipo ID','No ID','Primer Apellido','Segundo Apellido',
        'Primer Nombre','Segundo Nombre','Departamento','Ciudad',
        'Tipo de Cotizante','Subtipo de Cotizante','Horas Laboradas',
        'Extranjero','Residente en el Exterior','Fecha Radicación en el Exterior',
        // Novedades (P-AT = 16-46)
        'ING','Fecha ING','RET','Fecha RET','TDE','TAE','TDP','TAP',
        'VSP','Fecha VSP','VST','SLN','Inicio SLN','Fin SLN',
        'IGE','Inicio IGE','Fin IGE','LMA','Inicio LMA','Fin LMA',
        'VAC-LR','Inicio VAC-LR','Fin VAC-LR','AVP','VCT','Inicio VCT','Fin VCT',
        'IRL','Inicio IRL','Fin IRL','Correcciones',
        // Salario (AU-AW = 47-49)
        'Salario Mensual($)','Salario Integral','Salario Variable',
        // Pensión (AX-BJ = 50-62)
        'Administradora','Días','IBC','Tarifa','Valor Cotización',
        'Indicador Alto Riesgo','Cotización Voluntaria Afiliado',
        'Cotización Voluntaria Empleador','Fondo Solidaridad Pensional',
        'Fondo Subsistencia','Valor no Retenido','Total','AFP Destino',
        // Salud (BK-BU = 63-73)
        'Administradora','Días','IBC','Tarifa','Valor Cotización',
        'Valor UPC','N° Autorización Incapacidad EG','Valor Incapacidad EG',
        'N° Autorización LMA','Valor Licencia Maternidad','EPS Destino',
        // Riesgos (BV-CC = 74-81)
        'Administradora','Días','IBC','Tarifa','Clase',
        'Centro de Trabajo','Actividad Económica','Valor Cotización',
        // Parafiscales (CD-CR = 82-96)
        'Días','Administradora CCF','IBC CCF','Tarifa CCF','Valor Cotización CCF',
        'IBC Otros Parafiscales','Tarifa SENA','Valor Cotización SENA',
        'Tarifa ICBF','Valor Cotización ICBF','Tarifa ESAP','Valor Cotización ESAP',
        'Tarifa MEN','Valor Cotización MEN',
        'Exonerado parafiscales y salud Ley 1607',
        // UPC Adicional (CS-CT = 97-98)
        'Tipo ID','N° ID',
    ];

    // ── Grupos fila 17 ────────────────────────────────────────────────────
    // [título, columna inicio, columna fin] (1-based)
    private const GRUPOS = [
        ['Empleado',                    1,  15],
        ['Novedades',                  16,  46],
        ['Salario',                    47,  49],
        ['Pensión',                    50,  62],
        ['Salud',                      63,  73],
        ['Riesgos',                    74,  81],
        ['Parafiscales',               82,  96],
        ['Cotizante de UPC Adicional', 97,  98],
    ];

    // ─────────────────────────────────────────────────────────────────────
    // PÚBLICO: generar()
    // ─────────────────────────────────────────────────────────────────────
    /**
     * Reúne los datos del plano: razón social, cotizantes, periodos y tipo de
     * planilla. Vive aparte de generar() porque ExcelAportesEnLinea2Service
     * necesita exactamente los mismos datos con otro envase (la plantilla del
     * portal).
     *
     * @return array{0:object,1:\Illuminate\Support\Collection,2:string,3:string,4:string,5:?string,6:bool}
     */
    protected function recolectar(array $params): array
    {
        $aliadoId      = $params['aliado_id'];
        $razonSocialId = $params['razon_social_id'];
        $mesPago       = (int) $params['mes'];
        $anioPago      = (int) $params['anio'];
        $nPlano        = (int) $params['n_plano'];
        $tiposModal    = array_map('intval', (array)($params['tipos_modalidad'] ?? []));

        $mesVencido  = $mesPago > 1 ? $mesPago - 1 : 12;
        $anioVencido = $mesPago > 1 ? $anioPago    : $anioPago - 1;

        // ── Razón Social ──────────────────────────────────────────────────
        $rs = DB::table('razones_sociales')
            ->where('id', $razonSocialId)
            ->where('aliado_id', $aliadoId)
            ->first();

        if (!$rs) {
            throw new \RuntimeException("Razón social {$razonSocialId} no encontrada.");
        }
        // La sucursal es la de la razón social; si está vacía, la del aliado.
        $rs = SucursalOperador::aplicar($rs);

        // Nombre ARL de la empresa
        $nombreArl = null;
        if (!empty($rs->arl_nit)) {
            $nombreArl = DB::table('arls')
                ->where(DB::raw('CAST(nit AS VARCHAR(20))'), (string)$rs->arl_nit)
                ->value('razon_social'); // Usar razon_social ("ARL SURA") en vez de nombre_arl ("SURA")
        }

        // ── Query planos ──────────────────────────────────────────────────
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
            // arl_tarifas NO se une: puede tener múltiples filas por nivel (por aliado) y causa duplicados.
            // La tarifa ARL la calcula PilaCotizanteCalculator desde su constante TARIFAS_ARL.
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
                'p.tipo_doc', 'p.no_identifi', 'p.tipo_modalidad_id', 'p.tipo_p', 'p.paga_mes_actual',
                'p.primer_nombre', 'p.segundo_nombre', 'p.primer_ape', 'p.segundo_ape',
                'p.cod_eps', 'p.cod_afp', 'p.cod_arl', 'p.cod_caja',
                'p.salario_basico', 'p.num_dias', 'p.nivel_riesgo',
                DB::raw("CONVERT(VARCHAR(10), p.fecha_ing, 23) AS fecha_ing"),
                DB::raw("CONVERT(VARCHAR(10), p.fecha_ret, 23) AS fecha_ret"),
                DB::raw('afp_t.codigo      AS codigo_afp'),
                DB::raw('afp_t.razon_social AS nombre_afp'),
                DB::raw('eps_t.codigo      AS codigo_eps'),
                DB::raw('eps_t.razon_social AS nombre_eps'),
                DB::raw('caj_t.codigo  AS codigo_caj'),
                DB::raw('caj_t.nombre   AS nombre_caj'),
                DB::raw('arl_m.codigo       AS codigo_arl_pila'),
                DB::raw('arl_m.razon_social AS nombre_arl'),
                // tarifa_arl: no se lee de arl_tarifas (join eliminado), PilaCotizanteCalculator usa su constante
                'f.v_eps', 'f.v_afp', 'f.v_arl', 'f.v_caja', 'f.total_ss', 'f.dias_cotizados',
                'cl.genero',
                DB::raw("DATEDIFF(YEAR, cl.fecha_nacimiento, GETDATE()) AS edad_calculada"),
                DB::raw('d.id AS cod_departamento'),
                DB::raw('d.dept_aportes AS nombre_departamento'),
                DB::raw('CAST(c.Municipio AS INT) AS cod_municipio'),
                DB::raw('c.nombre AS nombre_ciudad'),
                // Manda el snapshot del plano y, si no lo tiene (todo lo anterior
                // al cotizante 76), los días fijos de la modalidad.
                DB::raw('tm.es_tiempo_parcial    AS es_tiempo_parcial'),
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

        // Tipo planilla: K si todos son Estudiante K (-1), Y si hay modalidad 8, E en otro caso
        $todosK       = $planos->count() > 0 && $planos->every(fn($p) => (int)$p->tipo_modalidad_id === -1);
        $tieneY       = !$todosK && $planos->count() > 0 && $planos->contains(fn($p) => (int)$p->tipo_modalidad_id === 8);
        $tipoPlanilla = $todosK ? 'K' : ($tieneY ? 'Y' : 'E');
        // El aportante 15 (Contratante) solo cabe si TODOS los cotizantes son
        // contratistas de la planilla Y; en las tandas mixtas la mayoría son
        // dependientes de un empleador (aportante 01).
        $soloY        = $planos->count() > 0 && $planos->every(fn($p) => (int)$p->tipo_modalidad_id === 8);

        $periodoSS    = sprintf('%04d-%02d', $anioVencido, $mesVencido);
        // Para tipo Y: periodo salud = periodo vencido (igual que sistemas diferentes a salud)
        $periodoSalud = ($tipoPlanilla === 'Y')
            ? $periodoSS
            : sprintf('%04d-%02d', $anioPago, $mesPago);

        return [$rs, $planos, $periodoSS, $periodoSalud, $tipoPlanilla, $nombreArl, $soloY];
    }

    public function generar(array $params): Spreadsheet
    {
        // $soloY se calculaba en recolectar() pero no se devolvía: desde bee4416
        // (31-ago-2026) buildEncabezado() lo recibía indefinido y la descarga
        // respondía 500 para cualquier razón social.
        [$rs, $planos, $periodoSS, $periodoSalud, $tipoPlanilla, $nombreArl, $soloY] = $this->recolectar($params);

        // ── Construir Spreadsheet ─────────────────────────────────────────
        $spreadsheet = new Spreadsheet();
        // Traza invisible de quién exportó (propiedades del documento).
        app(TrazaArchivoService::class)->marcarExcel($spreadsheet);
        $spreadsheet->getProperties()
            ->setTitle('Aportes en Línea — ' . ($rs->razon_social ?? ''))
            ->setCreator('BryNex');

        // Hoja 1: Liquidaciones
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Liquidaciones');

        $this->buildEncabezado($sheet, $rs, $periodoSS, $periodoSalud, $tipoPlanilla, $nombreArl, $soloY);
        $this->buildGruposYHeaders($sheet);
        $this->buildDatosEmpleados($sheet, $planos);

        $spreadsheet->setActiveSheetIndex(0);
        return $spreadsheet;
    }

    // ─────────────────────────────────────────────────────────────────────
    // ETAPA 2: buildEncabezado — bloques filas 7-10 y 12-14
    // ─────────────────────────────────────────────────────────────────────
    private function buildEncabezado($sheet, object $rs, string $periodoSS, string $periodoSalud, string $tipoPlanilla, ?string $nombreArl, bool $soloY = false): void
    {
        // Altura de filas del encabezado
        foreach ([7,8,9,10,12,13,14] as $r) {
            $sheet->getRowDimension($r)->setRowHeight(18);
        }

        // ── BLOQUE 1: Datos Generales de la Liquidación (filas 7-10) ─────

        // Fila 7: título del bloque
        $sheet->mergeCells('A7:K7');
        $sheet->setCellValue('A7', 'Datos Generales de la Liquidación');
        $this->estilo($sheet, 'A7:K7', self::CLR_SECT, '1F3864', true, 'center');

        // Fila 8: sub-títulos de grupo
        $sheet->mergeCells('A8:C8');
        $sheet->mergeCells('E8:F8');
        $sheet->mergeCells('G8:H8');
        $sheet->mergeCells('I8:J8');
        $sheet->setCellValue('A8', 'Periodo');
        $sheet->setCellValue('D8', 'Tipo');
        $sheet->setCellValue('E8', 'Planilla Asociada');
        $sheet->setCellValue('G8', 'Sucursal');
        $sheet->setCellValue('I8', 'Tipo');
        $sheet->setCellValue('K8', 'Administradora');
        $this->estilo($sheet, 'A8:K8', 'D6E4F0', '1F3864', true, 'center');

        // Fila 9: sub-labels
        $sheet->mergeCells('A9:B9');
        $sheet->mergeCells('I9:J9');
        $sheet->setCellValue('A9', 'Pensión');
        $sheet->setCellValue('C9', 'Salud');
        $sheet->setCellValue('D9', 'Planilla');
        $sheet->setCellValue('E9', 'Fecha');
        $sheet->setCellValue('F9', 'Clave');
        $sheet->setCellValue('G9', 'Código');
        $sheet->setCellValue('H9', 'Nombre');
        $sheet->setCellValue('I9', 'Aportante');
        $sheet->setCellValue('K9', 'Riesgos');
        $this->estilo($sheet, 'A9:K9', 'EBF3FB', '1F3864', true, 'center');

        // Fila 10: valores
        $sheet->mergeCells('A10:B10');
        $sheet->mergeCells('I10:J10');
        $sheet->setCellValue('A10', $periodoSS);
        $sheet->setCellValue('C10', $periodoSalud);
        $sheet->setCellValue('D10', $tipoPlanilla);
        $sheet->setCellValue('E10', '');                          // fecha planilla asociada
        $sheet->setCellValue('F10', '');                          // clave planilla asociada
        $sheet->setCellValue('G10', $rs->codigo_sucursal ?? '');
        $sheet->setCellValue('H10', $rs->nombre_sucursal ?? '');
        // Tipo de aportante: la planilla Y la presenta el contratante del
        // independiente (15), no un empleador; en las demás va vacío como
        // hasta ahora (error `eo.val.2.707` de Enlace).
        $sheet->setCellValue('I10', ($tipoPlanilla === 'Y' && $soloY) ? '15' : '');
        $sheet->setCellValue('K10', $nombreArl ?? '');
        $this->estilo($sheet, 'A10:K10', self::CLR_WHITE, '1F3864', false, 'center');

        // ── BLOQUE 2: Datos Generales de Pago (filas 12-14) ──────────────

        // Fila 12: título
        $sheet->mergeCells('A12:G12');
        $sheet->setCellValue('A12', 'Datos Generales de Pago');
        $this->estilo($sheet, 'A12:G12', self::CLR_SECT, '1F3864', true, 'center');

        // Fila 13: sub-títulos
        $sheet->mergeCells('A13:B13');
        $sheet->mergeCells('C13:D13');
        $sheet->mergeCells('E13:G13');
        $sheet->setCellValue('A13', 'Clave');
        $sheet->setCellValue('C13', 'Fecha');
        $sheet->setCellValue('E13', 'Pago');
        $this->estilo($sheet, 'A13:G13', 'D6E4F0', '1F3864', true, 'center');

        // Fila 14: sub-labels
        $sheet->setCellValue('A14', 'Pago');
        $sheet->setCellValue('B14', 'Planilla');
        $sheet->setCellValue('C14', 'Límite');
        $sheet->setCellValue('D14', 'Pago');
        $sheet->setCellValue('E14', 'Banco');
        $sheet->setCellValue('F14', 'Días Mora');
        $sheet->setCellValue('G14', 'Valor');
        $this->estilo($sheet, 'A14:G14', 'EBF3FB', '1F3864', true, 'center');

        // Filas 15 y 16: datos de pago (vacías, disponibles para el operador)
        $sheet->getRowDimension(15)->setRowHeight(14);
        $sheet->getRowDimension(16)->setRowHeight(14);
    }

    // ─────────────────────────────────────────────────────────────────────
    // ETAPA 3a: buildGruposYHeaders — fila 17 (grupos) + fila 18 (headers)
    // ─────────────────────────────────────────────────────────────────────
    private function buildGruposYHeaders($sheet): void
    {
        // ── Fila 17: títulos de grupos ────────────────────────────────────
        $sheet->getRowDimension(17)->setRowHeight(20);
        foreach (self::GRUPOS as [$titulo, $ini, $fin]) {
            $cIni = $this->col($ini);
            $cFin = $this->col($fin);
            $rango = "{$cIni}17:{$cFin}17";
            if ($ini !== $fin) {
                $sheet->mergeCells($rango);
            }
            $sheet->setCellValue("{$cIni}17", $titulo);
            $this->estilo($sheet, $rango, self::CLR_DARK, self::CLR_WHITE, true, 'center');
        }

        // ── Fila 18: encabezados de columnas (98 columnas A→CT) ───────────
        $sheet->getRowDimension(18)->setRowHeight(40);
        foreach (self::HEADERS as $i => $header) {
            $col = $this->col($i + 1);
            $sheet->setCellValue("{$col}18", $header);
        }
        $this->estilo($sheet, 'A18:' . $this->col(count(self::HEADERS)) . '18',
            self::CLR_MED, self::CLR_WHITE, true, 'center');

        // ── Anchos de columna ─────────────────────────────────────────────
        // Columnas de ID y nombres: más anchas
        $anchos = [
            'A'=>6,  'B'=>8,  'C'=>14, 'D'=>18, 'E'=>18,
            'F'=>16, 'G'=>16, 'H'=>12, 'I'=>12, 'J'=>10,
            'K'=>10, 'L'=>8,  'M'=>8,  'N'=>10, 'O'=>14,
        ];
        foreach ($anchos as $col => $ancho) {
            $sheet->getColumnDimension($col)->setWidth($ancho);
        }
        // Columnas de novedades (P-AT): estrechas
        for ($i = 16; $i <= 46; $i++) {
            $sheet->getColumnDimension($this->col($i))->setWidth(6);
        }
        // Columnas de salario (AU-AW): medianas
        for ($i = 47; $i <= 49; $i++) {
            $sheet->getColumnDimension($this->col($i))->setWidth(14);
        }
        // Pensión, Salud, Riesgos, Parafiscales, UPC: estándar
        for ($i = 50; $i <= 98; $i++) {
            $sheet->getColumnDimension($this->col($i))->setWidth(12);
        }

        // ── Congelar paneles desde A19 ────────────────────────────────────
        $sheet->freezePane('A19');
    }

    // ─────────────────────────────────────────────────────────────────────
    // ETAPA 4: buildDatosEmpleados — fila 19+, 98 columnas
    // ─────────────────────────────────────────────────────────────────────
    private function buildDatosEmpleados($sheet, $planos): void
    {
        $fila = 19;
        foreach ($planos as $seq => $p) {
            $this->writeEmployeeRow($sheet, $fila, $p, $seq + 1);
            // Filas alternas para legibilidad
            if (($seq % 2) === 1) {
                $sheet->getStyle('A' . $fila . ':' . $this->col(98) . $fila)
                    ->getFill()->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB(self::CLR_ALT);
            }
            $sheet->getRowDimension($fila)->setRowHeight(16);
            $fila++;
        }

        // Formato número/texto en columnas clave (aplica al rango de datos)
        if ($planos->count() > 0) {
            $lastRow = 18 + $planos->count();
            // Documento: texto (col C = 3)
            $sheet->getStyle('C19:C' . $lastRow)
                ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            // Valores monetarios: número entero sin decimales (cols AZ,BB,BI,BM,BO,BX,CC,CF,CH,CK,CM)
            $moneyCols = [52,54,61,65,67,76,79,82,84,87,89]; // 1-based
            foreach ($moneyCols as $ci) {
                $cl = $this->col($ci);
                $sheet->getStyle("{$cl}19:{$cl}{$lastRow}")
                    ->getNumberFormat()->setFormatCode('#,##0');
            }
            // Porcentajes (cols BA=53, BN=66, CG=85, CJ=88, CL=90, CN=92, CP=94)
            $pctCols = [53, 66, 85, 88, 90, 92, 94];
            foreach ($pctCols as $ci) {
                $cl = $this->col($ci);
                $sheet->getStyle("{$cl}19:{$cl}{$lastRow}")
                    ->getNumberFormat()->setFormatCode('0.00%');
            }
            // ARL requiere más decimales (BY=77)
            $clArl = $this->col(77);
            $sheet->getStyle("{$clArl}19:{$clArl}{$lastRow}")
                ->getNumberFormat()->setFormatCode('0.000%');
        }
    }

    protected function writeEmployeeRow($sheet, int $fila, object $p, int $seq): void
    {
        $c = PilaCotizanteCalculator::calcular($p);

        // Tipo documento y extranjero (SI / NO)
        $tipoDoc      = strtoupper(trim($p->tipo_doc ?? 'CC'));
        $esExtranjero = !in_array($tipoDoc, ['CC','TI','RC','SC','NUIP']) ? 'SI' : 'NO';

        // Fechas → ING y RET como novedad
        $fechaIng = !empty($p->fecha_ing) ? $p->fecha_ing : null;
        $fechaRet = !empty($p->fecha_ret) ? $p->fecha_ret : null;
        $esIng    = $fechaIng ? 'Todos los sistemas (ARL, AFP, CCF, EPS)' : 'NO';
        $esRet    = $fechaRet ? 'Todos los sistemas (ARL, AFP, CCF, EPS)' : 'NO';

        // Salario integral → SI/NO. En los cotizantes a los que PILA le prohíbe
        // marcar el campo (23, 51 y 59) va vacío, no 'NO' (error `eo.val.2.237`).
        $esIntegral = $c['tipoSalarioAplica']
            ? (strtoupper(trim($p->tipo_p ?? '')) === 'I' ? 'SI' : 'NO')
            : null;
        // Salario variable: no manejado → NO
        $esVariable = 'NO';

        // Tarifa EPS (vacía en K)
        $tarifaEps = ($c['esKMatriz'] || $c['esTiempoParcial'])
            ? null
            : ($c['exonerado'] === 'S' ? 0.04 : 0.125);

        // Tarifa CCF (vacía en K)
        $tarifaCcf = $c['esKMatriz'] ? null : 0.04;

        // Actividad económica ARL
        $actEco = self::ACTECO_ARL[$c['nivelRiesgo']] ?? null;

        // Salario Mensual: factor por modalidad de tiempo parcial
        $modalidadId  = (int)($p->tipo_modalidad_id ?? 0);
        $factorSalario = match ($modalidadId) {
            -6 => 2,
            -7, -8 => 3,
            default => 1,
        };
        $salarioMensual = $c['esTiempoParcial']
            ? $c['ibcFull']
            : ((int)($p->salario_basico ?? 0) * $factorSalario);

        // Horas laboradas:
        // - Tiempo parcial (tipo_modalidad -6,-7,-8,1,2,3): usa dias_caja del plan × 8
        // - Normal (tipo 0 y cualquier otro): usa num_dias del plano × 8
        // - Sin aporte a CCF (cotizantes 23 y 59): 0, porque las horas son el
        //   insumo del aporte a caja (error `eo.val.2.636` de Enlace).
        $diasParaHoras = $c['esTiempoParcial']
            ? (int)($p->dias_caja ?? 30)   // ej: tipo -6 → dias_caja=14 → 14×8=112
            : (int)($p->num_dias  ?? 30);  // ej: dependiente → 30×8=240
        $horas = ((int)$c['horasLaboradas'] === 0) ? 0 : $diasParaHoras * 8;

        $esPlanillaY = ((int)$p->tipo_modalidad_id === 8);

        // ── Mapeo de las 98 columnas (1-based) ──────────────────────────
        $valores = [
             1 => $seq,                          // A  No.
             2 => $tipoDoc,                      // B  Tipo ID
             3 => (string)$p->no_identifi,        // C  No ID (texto)
             4 => $p->primer_ape   ?? '',         // D  Primer Apellido
             5 => $p->segundo_ape  ?? '',         // E  Segundo Apellido
             6 => $p->primer_nombre ?? '',        // F  Primer Nombre
             7 => $p->segundo_nombre ?? '',       // G  Segundo Nombre
             8 => $p->nombre_departamento ?? $c['depCod'],        // H  Departamento (nombre)
             9 => $c['sinCaja'] ? 'SIN CAJA' : ($p->nombre_ciudad ?? $p->cod_municipio ?? null), // I  Ciudad (nombre)
            10 => self::tipoCotizanteLabel($c['tipoCotizante']),  // J  Tipo Cotizante (código. NOMBRE)
            11 => self::subtipoCotizanteLabel($esPlanillaY ? 0 : $c['subtipoCotizante']), // K  Subtipo Cotizante (NINGUNO / código. NOMBRE)
            12 => $horas,                         // L  Horas laboradas
            13 => $esExtranjero,                  // M  Extranjero (SI/NO)
            14 => 'NO',                            // N  Residente en el Exterior
            15 => null,                           // O  Fecha radicación exterior
            // Novedades
            16 => $esIng,                         // P  ING (SI/NO)
            17 => $fechaIng,                      // Q  Fecha ING
            18 => $esRet,                         // R  RET (SI/NO)
            19 => $fechaRet,                      // S  Fecha RET
            20 => 'NO', 21 => 'NO', 22 => 'NO', 23 => 'NO', // TDE TAE TDP TAP
            24 => 'NO', 25 => null,  26 => 'NO', 27 => 'NO', // VSP FechaVSP VST SLN
            28 => null,  29 => null,  30 => 'NO', 31 => null,  // InicioSLN FinSLN IGE InicioIGE
            32 => null,  33 => 'NO',  34 => null, 35 => null,  // FinIGE LMA(NO) InicioLMA(null) FinLMA
            36 => 'NO', 37 => null,  38 => null,  39 => 'NO', // VAC-LR InicioVAC FinVAC AVP
            40 => 'NO', 41 => null,  42 => null,  43 => 0,    // VCT InicioVCT FinVCT IRL
            44 => null,  45 => null,                          // InicioIRL FinIRL
            46 => 'NO',                           // AT Correcciones
            // Salario
            47 => $salarioMensual ?: null,         // AU Salario Mensual (ajustado por modalidad)
            48 => $esIntegral,                    // AV Salario Integral (SI/NO)
            49 => $esVariable,                    // AW Salario Variable (NO)
            // Pensión
            50 => $esPlanillaY ? null : ($c['tienePension'] ? ($p->nombre_afp ?? $c['codAfpPila'] ?? null) : null), // AX AFP nombre
            51 => $c['tienePension'] ? $c['diasPension'] : 0,                               // AY Días
            52 => $c['tienePension'] ? $c['ibcAfp'] : 0,                                    // AZ IBC
            53 => $c['tienePension'] ? (float) ($c['tarifaAfpStr'] ?? 0.16) : null,     // BA Tarifa (el cotizante 33 trae la suya)
            54 => $c['tienePension'] ? ($c['vAfp'] ?: null) : 0,                            // BB Valor
            55 => 'Sin Riesgo',                                                             // BC Indicador Alto Riesgo
            56 => 0,                              // BD Vol Afiliado
            57 => 0,                              // BE Vol Empleador
            58 => 0,                              // BF FSP Solidaridad
            59 => 0,                              // BG FSP Subsistencia
            60 => 0,                              // BH No retenido
            61 => $c['tienePension'] ? ($c['vAfp'] ?: null) : 0,                            // BI Total
            62 => 'NINGUNA',                      // BJ AFP Destino
            // Salud
            63 => $esPlanillaY ? null : ($p->nombre_eps ? strtoupper($p->nombre_eps) : 'NINGUNA'), // BK Administradora EPS
            64 => $c['diasSalud'],                // BL Días
            65 => $c['ibcEps'],                   // BM IBC
            66 => $tarifaEps !== null ? (float)$tarifaEps : 0.0,                        // BN Tarifa
            67 => $c['vEps'] ?? 0,               // BO Valor Cotización (0 si null)
            68 => 0,                              // BP Valor UPC
            69 => null,                           // BQ Auth IGE
            70 => 0,                              // BR Valor IGE
            71 => null,                           // BS Auth LMA
            72 => 0,                              // BT Valor LMA
            73 => 'NINGUNA',                      // BU EPS Destino
            // Riesgos
            74 => $p->nombre_arl ? strtoupper($p->nombre_arl) : 'NINGUNA', // BV Administradora ARL (nombre)
            75 => $c['diasArl'],                  // BW Días
            76 => $c['ibcArl'],                   // BX IBC
            77 => $c['tarifaArlDecimal'] !== null ? (float)$c['tarifaArlDecimal'] : null, // BY Tarifa ARL
            78 => $c['nivelRiesgo'],              // BZ Clase
            79 => 1,                              // CA Centro de trabajo
            80 => $actEco,                        // CB Actividad económica
            81 => $c['vArl'] ?: null,             // CC Valor
            // Parafiscales
            82 => $c['diasCcf'],                  // CD Días
            83 => $esPlanillaY ? null : ($p->nombre_caj ? strtoupper($p->nombre_caj) : 'NINGUNA'), // CE Administradora CCF (nombre)
            84 => $salarioMensual ?: 0,           // CF IBC CCF (mismo factor que salario mensual)
            85 => $tarifaCcf !== null ? (float)$tarifaCcf : null,                       // CG Tarifa CCF
            86 => $c['vCcf'] ?? 0, // CH Valor CCF redondeado (del calculador centralizado con redondeo PILA)
            87 => 0,                              // CI IBC Otros Parafiscales
            88 => $c['exonerado'] === 'S' ? 0.0 : 0.02,                                 // CJ Tarifa SENA
            89 => 0,                              // CK Valor Cotización SENA
            90 => $c['exonerado'] === 'S' ? 0.0 : 0.03,                                 // CL Tarifa ICBF
            91 => 0,                              // CM Valor Cotización ICBF
            92 => 0,                              // CN Tarifa ESAP
            93 => 0,                              // CO Valor ESAP
            94 => 0,                              // CP Tarifa MEN
            95 => 0,                              // CQ Valor MEN
            96 => 'NO',                           // CR Exonerado Ley 1607
            // UPC Adicional
            97 => null,                           // CS Tipo ID
            98 => null,                           // CT N° ID
        ];

        foreach ($valores as $colIdx => $valor) {
            $celda = $this->col($colIdx) . $fila;
            $sheet->setCellValue($celda, $valor);
        }

        // Documento como texto para evitar notación científica
        $sheet->getStyle('C' . $fila)
            ->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

        // Fechas como texto ISO
        if ($fechaIng) {
            $sheet->getStyle('Q' . $fila)->getNumberFormat()
                ->setFormatCode('YYYY-MM-DD');
        }
        if ($fechaRet) {
            $sheet->getStyle('S' . $fila)->getNumberFormat()
                ->setFormatCode('YYYY-MM-DD');
        }
    }



    // ─────────────────────────────────────────────────────────────────────
    // Helper: aplicar estilo de fondo + texto a un rango
    // ─────────────────────────────────────────────────────────────────────
    private function estilo($sheet, string $rango, string $bgColor, string $fgColor = 'FFFFFF', bool $bold = true, string $hAlign = 'center'): void
    {
        $sheet->getStyle($rango)->applyFromArray([
            'font'      => ['bold' => $bold, 'color' => ['rgb' => $fgColor]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bgColor]],
            'alignment' => ['horizontal' => $hAlign, 'vertical' => 'center', 'wrapText' => true],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B0C4DE']]],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helper: obtener letra de columna desde índice 1-based
    // ─────────────────────────────────────────────────────────────────────
    // ─────────────────────────────────────────────────────────────────────
    // Helper: construye "CÓDIGO. NOMBRE" para Tipo de Cotizante
    // ─────────────────────────────────────────────────────────────────────
    private static function tipoCotizanteLabel(int|string $codigo): string
    {
        $cod    = (int) $codigo;
        $nombre = self::TIPO_COTIZANTE[$cod] ?? null;
        return $nombre ? "{$cod}. {$nombre}" : (string) $codigo;
    }

    private static function subtipoCotizanteLabel(int|string $codigo): string
    {
        $cod = (int) $codigo;
        if ($cod === 0) return 'NINGUNO';
        $nombre = self::SUBTIPO_COTIZANTE[$cod] ?? null;
        return $nombre ? "{$cod}. {$nombre}" : (string) $codigo;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Helper: obtener letra de columna desde índice 1-based
    // ─────────────────────────────────────────────────────────────────────
    protected function col(int $idx): string
    {
        return Coordinate::stringFromColumnIndex($idx);
    }

    // ─────────────────────────────────────────────────────────────────────
    // PÚBLICO: respuesta streamed
    // ─────────────────────────────────────────────────────────────────────
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
