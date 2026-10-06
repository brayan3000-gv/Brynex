<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use Illuminate\Support\Facades\DB;
use App\Models\Plano;

/**
 * ExcelPlanoNIService
 *
 * Genera el archivo Excel de ayuda para pago de planillas SS
 * en el formato requerido por operadores Simple / ARUS / Enlace.
 *
 * Hoja: "Planilla base"
 *   Fila 1  -> encabezados del aportante (22 columnas)
 *   Fila 2  -> valores del aportante
 *   Fila 3  -> encabezados de los trabajadores (98 columnas)
 *   Fila 4+ -> un trabajador por fila
 */
class ExcelPlanoNIService
{
    // --- Actividad económica ARL por nivel de riesgo (Decreto 768/2022) ----------
    private const ACTECO_ARL = [
        1 => '1141001', 2 => '2141003', 3 => '3139202',
        4 => '4131301', 5 => '5131201',
    ];

    // --- Encabezados fila 1: Aportante (22 columnas formato NI Simple/ARUS) ------
    private const HEADERS_APORTANTE = [
        'Tipo de Registro',                                          // 1
        'Modalidad de la Planilla',                                  // 2  -> 1
        'Secuencia',                                                 // 3
        'Nombre o Razon Social del Aportante',                       // 4
        'Tipo de Documento de Identificacion del Aportante',         // 5  -> NI
        'Numero de Identificacion del Aportante',                    // 6
        'Digito de Verificacion',                                    // 7
        'Tipo Planilla',                                             // 8  -> E
        'Numero PI. Factura',                                        // 9
        'Fecha PI. Factura',                                         // 10
        'Forma de Presentacion',                                     // 11 -> S
        'Codigo Sucursal Aportante',                                 // 12 -> 01
        'Nombre Sucursal',                                           // 13 -> SUCURSAL
        'Codigo ARL',                                                // 14 -> de tabla arls
        'Periodo Pago Sistemas Diferentes a Salud',                  // 15 -> mes vencido
        'Periodo Pago al Sistema de Salud',                          // 16 -> mes actual
        'Numero de Planilla',                                        // 17
        'Fecha de Pago',                                             // 18 -> vacío planilla ordinaria
        'Numero de Cotizantes',                                      // 19
        'Valor Total de la Nomina',                                  // 20
        'Tipo de Aportante',                                         // 21 -> 1  (col U)
        'Codigo del Operador de Informacion',                        // 22
        'Version del Formato',                                       // 23
    ];

    // --- Encabezados fila 3: Trabajadores (98 columnas — formato operadores SS) ----
    private const HEADERS_TRABAJADORES = [
        'Tipo de registro',          //  1
        'Secuencia',                 //  2
        'Tipo documento cotizante',  //  3
        'Documento cotizante',       //  4
        'Tipo de cotizante',         //  5
        'Subtipo de cotizante',      //  6
        'Extranjero',                //  7
        'Colombiano en el exterior', //  8
        'Departamento',              //  9
        'Municipio',                 // 10
        'Primer apellido',           // 11
        'Segundo apellido',          // 12
        'Primer nombre',             // 13
        'Segundo nombre',            // 14
        'ING',                       // 15
        'RET',                       // 16
        'TDE',                       // 17
        'TAE',                       // 18
        'TDP',                       // 19
        'TAP',                       // 20
        'VSP',                       // 21
        'Línea',                      // 22
        'VST',                       // 23
        'SLN',                       // 24
        'IGE',                       // 25
        'LMA',                       // 26
        'VAC-LR',                    // 27
        'AVP',                       // 28
        'VCT',                       // 29
        'IRL',                       // 30
        'AFP',                       // 31
        'AFP Traslado',              // 32
        'EPS',                       // 33
        'EPS Traslado',              // 34
        'CCF',                       // 35
        'Días AFP',                  // 36
        'Días EPS',                  // 37
        'Días ARL',                  // 38
        'Días CCF',                  // 39
        'Salario básico',            // 40
        'Salario integral',          // 41
        'IBC AFP',                   // 42
        'IBC EPS',                   // 43
        'IBC ARL',                   // 44
        'IBC CCF',                   // 45
        'Tarifa AFP',                // 46
        'Cotización AFP',            // 47
        'AVP afiliado',              // 48
        'AVP aportante',             // 49
        'Total AFP',                 // 50
        'Aporte FSP',                // 51
        'Aporte FSPS',               // 52
        'Valor no retenido',         // 53
        'Tarifa EPS',                // 54
        'Cotización EPS',            // 55
        'Valor UPC',                 // 56
        'Número IGE',               // 57
        'Valor IGE',                 // 58
        'Número LMA',               // 59
        'Valor LMA',                 // 60
        'Tarifa ARL',                // 61
        'Centro de trabajo',         // 62
        'Cotización ARL',            // 63
        'Tarifa CCF',                // 64
        'Aporte CCF',                // 65
        'Tarifa SENA',               // 66
        'Aporte SENA',               // 67
        'Tarifa ICBF',               // 68
        'Aporte ICBF',               // 69
        'Tarifa ESAP',               // 70
        'Aporte ESAP',               // 71
        'Tarifa MEN',                // 72
        'Aporte MEN',                // 73
        'Tipo documento UPC',        // 74
        'Documento UPC',             // 75
        'Exonerado',                 // 76
        'ARL',                       // 77
        'Clase riesgo',              // 78
        'Tarifa especial AFP',       // 79
        'Fecha ING',                 // 80
        'Fecha RET',                 // 81
        'Fecha inicio VSP',          // 82
        'Fecha inicio SLN',          // 83
        'Fecha final SLN',           // 84
        'Fecha inicio IGE',          // 85
        'Fecha final IGE',           // 86
        'Fecha inicio LMA',          // 87
        'Fecha final LMA',           // 88
        'Fecha inicio VAC-LR',       // 89
        'Fecha final VAC-LR',        // 90
        'Fecha inicio VCT',          // 91
        'Fecha final VCT',           // 92
        'Fecha inicio IRL',          // 93
        'Fecha final IRL',           // 94
        'IBC otros parafiscales',    // 95
        'Número horas laboradas',    // 96
        'Fecha radicación exterior', // 97
        'Actividad económica ARL',   // 98
    ];

    /**
     * Genera el Spreadsheet con los datos del plano.
     *
     * @param array $params [
     *   'aliado_id'       => int,
     *   'razon_social_id' => string|int,
     *   'mes'             => int,   // mes de PAGO (UI)
     *   'anio'            => int,   // anno de pago
     *   'n_plano'         => int,
     *   'tipos_modalidad' => array, // IDs a filtrar (vacio = todos)
     * ]
     */
    public function generar(array $params): Spreadsheet
    {
        $aliadoId      = $params['aliado_id'];
        $razonSocialId = $params['razon_social_id'];
        $mesPago       = (int) $params['mes'];
        $anioPago      = (int) $params['anio'];
        $nPlano        = (int) $params['n_plano'];
        $tiposModal    = $params['tipos_modalidad'] ?? [];

        // -- Mes vencido para dependientes ------------------------------------------
        $mesVencido  = $mesPago > 1 ? $mesPago - 1 : 12;
        $anioVencido = $mesPago > 1 ? $anioPago    : $anioPago - 1;

        // -- 1. Datos del aportante ------------------------------------------------
        $rs = DB::table('razones_sociales')
            ->where('id', $razonSocialId)
            ->where('aliado_id', $aliadoId)
            ->first();

        if (!$rs) {
            throw new \RuntimeException("Razon social {$razonSocialId} no encontrada.");
        }
        // La sucursal es la de la razón social; si está vacía, la del aliado.
        $rs = SucursalOperador::aplicar($rs);

        // Operador seleccionado por el usuario (si pasa operador_id, busca ese; si no, el primero activo)
        $operadorId = $params['operador_id'] ?? null;
        $queryOp = DB::table('operadores_planilla')
            ->where(function ($q) use ($aliadoId) {
                $q->whereNull('aliado_id')->orWhere('aliado_id', $aliadoId);
            })
            ->where('activo', true);

        $operador = $operadorId
            ? $queryOp->where('id', $operadorId)->first()
            : $queryOp->orderBy('orden')->first();

        // Codigo de la ARL de la empresa (por NIT)
        $codigoArl = null;
        if ($rs->arl_nit) {
            $codigoArl = DB::table('arls')->where('nit', $rs->arl_nit)->value('codigo');
        }

        // -- 2. Planos del periodo (logica mes vencido) ----------------------------
        $query = DB::table('planos AS p')
            ->leftJoin('facturas AS f',   'f.id',  '=', 'p.factura_id')
            // ── clientes: filtrar por aliado_id para evitar duplicados cuando
            //    el mismo cliente está registrado en múltiples aliados (misma cédula)
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
            // ── ciudades/departamentos con TOP 1 para evitar duplicar si hay
            //    múltiples registros con el mismo id_ciudad_t o departamento id
            ->leftJoin('ciudades AS c',      'c.id_ciudad_t', '=', 'cl.municipio_id')
            ->leftJoin('departamentos AS d', 'd.id',          '=', 'cl.departamento_id')
            // Códigos PILA de entidades (NIT del plano → codigo en tabla maestra)
            ->leftJoin('pensiones AS afp_t', DB::raw('CAST(afp_t.nit AS VARCHAR(20))'), '=', DB::raw('p.cod_afp'))
            ->leftJoin('eps AS eps_t',       DB::raw('CAST(eps_t.nit AS VARCHAR(20))'), '=', DB::raw('p.cod_eps'))
            ->leftJoin('cajas AS caj_t',     DB::raw('CAST(caj_t.nit AS VARCHAR(20))'), '=', DB::raw('p.cod_caja'))
            ->leftJoin('arls AS arl_m', DB::raw('CAST(arl_m.nit AS VARCHAR(20))'), '=', DB::raw('p.cod_arl'))
            ->leftJoin('tipo_modalidad AS tm', 'tm.id', '=', 'p.tipo_modalidad_id')
            // Solo por la tarifa de caja del cotizante 76 (0,6% o 2%), que es
            // una elección del contrato y no vive en el plano.
            ->leftJoin('contratos AS ctr', 'ctr.id', '=', 'p.contrato_id')
            ->where('p.aliado_id',       $aliadoId)
            ->where('p.razon_social_id', $razonSocialId)
            ->where('p.n_plano',         $nPlano)
            ->whereIn('p.tipo_reg',       ['planilla', 'retiro'])
            ->whereRaw('ISNULL(p.num_dias, 0) > 0')   // excluir num_dias=0 Y num_dias=NULL
            ->whereNull('p.deleted_at')
            ->tap(fn ($q) => Plano::filtrarPeriodoDePago($q, $mesPago, $anioPago))
            ->select([
                // Identificación
                'p.tipo_doc',
                'p.no_identifi',
                'p.tipo_modalidad_id',
                'p.paga_mes_actual',
                'p.tipo_p',
                // Nombres
                'p.primer_nombre',
                'p.segundo_nombre',
                'p.primer_ape',
                'p.segundo_ape',
                // NITs originales del plano (para referencia interna)
                'p.cod_eps',
                'p.cod_afp',
                'p.cod_arl',
                'p.cod_caja',
                // Códigos PILA de las entidades
                DB::raw('afp_t.codigo  AS codigo_afp'),
                DB::raw('eps_t.codigo  AS codigo_eps'),
                DB::raw('caj_t.codigo  AS codigo_caj'),
                // cod_arl: primero el código PILA de la tabla arls (por NIT),
                // fallback al cod_arl del plano (NIT directo) para retiros donde el join falla
                DB::raw('ISNULL(arl_m.codigo, p.cod_arl) AS codigo_arl_pila'),
                // Tarifa ARL: calculada directamente en PilaCotizanteCalculator (TARIFAS_ARL const)
                // NO se incluye arl_tarifas join — evita multiplicar filas si hay tarifas por aliado
                // IBC / salario
                'p.salario_basico',
                'p.num_dias',
                'p.nivel_riesgo',
                // Fechas ING/RET como string YYYY-MM-DD desde SQL Server
                DB::raw("CONVERT(VARCHAR(10), p.fecha_ing, 23) AS fecha_ing"),
                DB::raw("CONVERT(VARCHAR(10), p.fecha_ret, 23) AS fecha_ret"),
                // Factura: valores reales de aporte
                'f.v_eps',
                'f.v_afp',
                'f.v_arl',
                'f.v_caja',
                'f.total_ss',
                'f.dias_cotizados',
                // Cliente: datos demográficos
                'cl.genero',
                DB::raw("DATEDIFF(YEAR, cl.fecha_nacimiento, GETDATE()) AS edad_calculada"),
                DB::raw("CONVERT(VARCHAR(10), p.fecha_ing, 23) AS fecha_ing"),
                DB::raw("CONVERT(VARCHAR(10), p.fecha_ret, 23) AS fecha_ret"),
                // Tipo modalidad (para tiempo parcial)
                // es_tiempo_parcial: flag en tipo_modalidad
                // dias de cotización por subsistema: definidos en tipo_modalidad (ARL siempre 30)
                DB::raw('tm.es_tiempo_parcial    AS es_tiempo_parcial'),
                // Manda el snapshot del plano y, si no lo tiene (todo lo anterior
                // al cotizante 76), los días fijos de la modalidad.
                DB::raw('ISNULL(p.dias_tp_afp, ISNULL(tm.dias_afp, 30)) AS dias_afp'),
                DB::raw('ISNULL(p.dias_tp_caja, ISNULL(p.dias_tp_afp, ISNULL(tm.dias_caja, 30))) AS dias_caja'),
                'ctr.porcentaje_caja',
                // Fondo de Solidaridad: el grupo decide la tarifa de pensión del cotizante 33.
                DB::raw('ISNULL(p.grupo_fondo_solidaridad, ctr.grupo_fondo_solidaridad) AS grupo_fondo_solidaridad'),
                DB::raw('emp.exonerado_parafiscales AS exonerado_parafiscales'),
                DB::raw('d.id                          AS cod_departamento'),
                DB::raw('CAST(c.Municipio AS INT)       AS cod_municipio'),
            ]);

        if (!empty($tiposModal)) {
            $query->whereIn('p.tipo_modalidad_id', $tiposModal);
        }

        $planos = $query
            ->orderBy('p.primer_ape')
            ->orderBy('p.primer_nombre')
            ->get();

        Plano::validarPeriodoUnico($planos);

        // -- 3. Numero planilla (del primer registro con numero_planilla) -----------
        $numeroPlanilla = DB::table('planos')
            ->where('aliado_id',       $aliadoId)
            ->where('razon_social_id', $razonSocialId)
            ->where('n_plano',         $nPlano)
            ->whereNotNull('numero_planilla')
            ->value('numero_planilla');

        // Si hay registros con tipo_p = 16, Tipo Planilla = 'N' (Correcciones).
        // Si TODOS los registros cargados son Estudiante K (tipo_modalidad_id = -1)
        // → Tipo Planilla = 'K'. Si hay modalidad 8 → 'Y'. Para cualquier otro caso → 'E' (Ordinaria).
        $tieneN          = $planos->count() > 0 && $planos->contains(fn($p) => (int)($p->tipo_p ?? 0) === 16);
        $todosK          = !$tieneN && $planos->count() > 0 && $planos->every(fn($p) => (int)$p->tipo_modalidad_id === -1);
        $tieneY          = !$tieneN && !$todosK && $planos->count() > 0 && $planos->contains(fn($p) => (int)$p->tipo_modalidad_id === 8);
        $tipoPlanilla    = $tieneN ? 'N' : ($todosK ? 'K' : ($tieneY ? 'Y' : 'E'));
        // El aportante 15 (Contratante) solo cabe si TODOS los cotizantes son
        // contratistas de la planilla Y: basta un registro de modalidad 8 para
        // marcar el archivo 'Y', y en esas tandas mixtas la mayoría son
        // dependientes de un empleador (aportante 01).
        $soloY           = $planos->count() > 0 && $planos->every(fn($p) => (int)$p->tipo_modalidad_id === 8);
        $totalCotizantes = $planos->count();
        $totalNomina     = $planos->sum('total_ss');

        // -- 5. Periodos AAAA-MM separados ------------------------------------------
        // Col 15: Sistemas diferentes a Salud (AFP, ARL, CCF) = mes VENCIDO
        $periodoSS     = sprintf('%04d-%02d', $anioVencido, $mesVencido);
        // Col 16: Sistema de Salud (EPS) = mes actual; para tipo Y = mes vencido
        $periodoSalud  = ($tipoPlanilla === 'Y')
            ? $periodoSS
            : sprintf('%04d-%02d', $anioPago, $mesPago);

        // -- 6. Construir Spreadsheet ----------------------------------------------
        $spreadsheet = new Spreadsheet();
        // Traza invisible de quién exportó (propiedades del documento).
        app(TrazaArchivoService::class)->marcarExcel($spreadsheet);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Planilla base');

        // Fila 1: Encabezados del aportante
        $col = 1;
        foreach (self::HEADERS_APORTANTE as $header) {
            $sheet->getCell([$col++, 1])->setValue($header);
        }

        // Fila 2: Valores del aportante
        $this->escribirFilaAportante($sheet, 2, [
            'tipo_registro'      => 1,
            'modalidad_plan'     => '1',         // 1 = electrónica ordinaria
            'secuencia'          => 1,
            'razon_social'       => $rs->razon_social,
            'tipo_doc_ap'        => 'NI',        // NI = NIT para aportante empresa
            'nit'                => preg_replace('/[^0-9]/', '', (string)($rs->nit ?? $rs->id)),
            'dv'                 => $rs->dv,
            'tipo_planilla'      => $tipoPlanilla,  // 'K' = Estudiante | 'E' = Ordinaria
            'nro_pi_factura'     => null,
            'fecha_p_factura'    => null,
            'forma_presentacion' => 'S',                 // S = electrónica (fijo)
            'codigo_suc'         => $rs->codigo_sucursal,
            'nombre_suc'         => $rs->nombre_sucursal,
            'codigo_arl'         => $codigoArl ?? '',  // NIT/código ARL de la empresa
            'periodo_ss'         => $periodoSS,       // mes vencido para AFP/ARL/CCF
            'periodo_salud'      => $periodoSalud,    // mes actual para EPS
            'numero_planilla'    => null,                          // vacío
            'fecha_pago'         => null,                          // vacío — planilla ordinaria
            'numero_cotizantes'  => $totalCotizantes,
            'valor_nomina'       => null,                          // vacío
            // 15 = Contratante: la planilla Y la presenta quien contrata al
            // independiente, no un empleador (error `eo.val.2.707` de Enlace).
            'tipo_aportante'     => ($tipoPlanilla === 'Y' && $soloY) ? 15 : 1,
            'codigo_operador'    => null,                          // vacío — auto-completa operador
            'version_formato'    => null,
        ]);

        // Fila 3: Encabezados de los trabajadores
        $col = 1;
        foreach (self::HEADERS_TRABAJADORES as $header) {
            $sheet->getCell([$col++, 3])->setValue($header);
        }

        // Filas 4+: Un trabajador por fila
        $fila = 4;
        $seq  = 1;
        foreach ($planos as $p) {
            $this->escribirFilaTrabajador($sheet, $fila, $p, $seq);
            $fila++;
            $seq++;
        }

        return $spreadsheet;
    }

    // --- Escribe fila 2 (aportante, 22 columnas) ---------------------------------
    private function escribirFilaAportante($sheet, int $fila, array $d): void
    {
        // Exactamente 22 columnas en el mismo orden que HEADERS_APORTANTE
        $valores = [
            /* 01 */ $d['tipo_registro'],       // 1
            /* 02 */ $d['modalidad_plan'],      // '1'
            /* 03 */ $d['secuencia'],           // 1
            /* 04 */ $d['razon_social'],        // Razón social
            /* 05 */ $d['tipo_doc_ap'],         // 'NI'
            /* 06 */ $d['nit'],                 // NIT (texto)
            /* 07 */ $d['dv'],                  // DV
            /* 08 */ $d['tipo_planilla'],       // 'E'
            /* 09 */ $d['nro_pi_factura'],      // null
            /* 10 */ $d['fecha_p_factura'],     // null
            /* 11 */ $d['forma_presentacion'],  // 'S'
            /* 12 */ $d['codigo_suc'],          // '01'
            /* 13 */ $d['nombre_suc'],          // 'SUCURSAL'
            /* 14 */ $d['codigo_arl'],          // NIT/código ARL
            /* 15 */ $d['periodo_ss'],          // AAAAMM mes vencido
            /* 16 */ $d['periodo_salud'],       // AAAAMM mes actual
            /* 17 */ $d['numero_planilla'],     // número planilla
            /* 18 */ $d['fecha_pago'],          // vacío — planilla ordinaria
            /* 19 */ $d['numero_cotizantes'],   // total personas
            /* 20 */ $d['valor_nomina'],        // total SS
            /* 21 */ $d['tipo_aportante'],      // 1  (col U)
            /* 22 */ $d['codigo_operador'],     // vacío — auto-completa operador
            /* 23 */ $d['version_formato'],     // null
        ];

        // Columnas que deben ser texto para evitar notacion cientifica
        $colTexto = [6, 14]; // NIT aportante, Código ARL

        $col = 1;
        foreach ($valores as $v) {
            $cell = $sheet->getCell([$col, $fila]);
            $cell->setValue($v ?? '');

            if (in_array($col, $colTexto)) {
                $cell->getStyle()->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            }

            $col++;
        }
    }

    // --- Escribe una fila de trabajador -------------------------------------------
    private function escribirFilaTrabajador($sheet, int $fila, object $p, int $seq): void
    {
        // ── Calculador centralizado (todas las reglas PILA) ───────────────────
        $c = PilaCotizanteCalculator::calcular($p);

        // ── Tipo documento ────────────────────────────────────────────────────
        $tipoDocNorm    = strtoupper(trim($p->tipo_doc ?? 'CC'));
        $docsColombiano = ['CC', 'TI', 'NUIP', 'RC'];
        // Extranjero: usar la regla centralizada del calculador
        // Regla: extranjero CON AFP → no marcar X; extranjero SIN AFP → marcar X
        $esExtranjero   = $c['esExtranjero'] ? 'X' : null;

        // ── Fechas ING / RET ──────────────────────────────────────────────────
        $fechaIng = !empty($p->fecha_ing) ? $p->fecha_ing : null;
        $fechaRet = !empty($p->fecha_ret) ? $p->fecha_ret : null;
        $esIng    = $fechaIng ? 'X' : null;
        $esRet    = $fechaRet ? ((int)$p->tipo_modalidad_id === 8 ? 'T' : 'X') : null;

        // ── Departamento: del calculador (99 si sin caja/K, real si tiene caja) ─
        $depExcel = $c['depCod'];

        // ── Horas laboradas ────────────────────────────────────────────────────
        // Horas laboradas: tipo 51 usa dias_caja (días reales) × 8; el resto usa num_dias × 8
        $horasLaboradas = $c['horasLaboradas'];

        $esPlanillaY = ((int)$p->tipo_modalidad_id === 8);

        $valores = [
            /*  1 */ 2,                                                // Tipo de registro
            /*  2 */ $seq,                                             // Secuencia
            /*  3 */ $tipoDocNorm,                                     // Tipo documento
            /*  4 */ (string)$p->no_identifi,                          // Documento
            /*  5 */ $c['tipoCotizante'],                              // Tipo cotizante (1/2/23)
            /*  6 */ $esPlanillaY ? 0 : (int)$c['subtipoCotizante'],   // Subtipo (0/3/4) — 0 cuando no aplica excepción
            /*  7 */ $esExtranjero,                                    // Extranjero
            /*  8 */ null,                                             // Colombiano exterior
            /*  9 */ $depExcel,                                        // Departamento
            /* 10 */ $c['sinCaja'] ? 1 : ($p->cod_municipio ?? null),  // Municipio (1 si sin caja)
            /* 11 */ $p->primer_ape,                                   // Primer apellido
            /* 12 */ $p->segundo_ape,                                  // Segundo apellido
            /* 13 */ $p->primer_nombre,                                // Primer nombre
            /* 14 */ $p->segundo_nombre,                               // Segundo nombre
            /* 15 */ $esIng,                                           // ING
            /* 16 */ $esRet,                                           // RET
            /* 17 */ null,                                             // TDE
            /* 18 */ null,                                             // TAE
            /* 19 */ null,                                             // TDP
            /* 20 */ null,                                             // TAP
            /* 21 */ null,                                             // VSP
            /* 22 */ null,                                             // Línea
            /* 23 */ null,                                             // VST
            /* 24 */ null,                                             // SLN
            /* 25 */ null,                                             // IGE
            /* 26 */ null,                                             // LMA
            /* 27 */ null,                                             // VAC-LR
            /* 28 */ null,                                             // AVP
            /* 29 */ null,                                             // VCT
            /* 30 */ null,                                             // IRL (vacío — no aplica)
            /* 31 */ $esPlanillaY ? null : ($c['codAfpPila'] ?: null), // AFP
            /* 32 */ null,                                             // AFP Traslado
            /* 33 */ $esPlanillaY ? null : ($c['codEpsPila'] ?: null), // EPS
            /* 34 */ null,                                             // EPS Traslado
            /* 35 */ $esPlanillaY ? null : ($c['codCcfPila'] ?: null), // CCF
            /* 36 */ $c['tienePension'] ? ($c['diasPension'] ?: null) : 0, // Días AFP (0 si sin pensión)
            /* 37 */ $c['diasSalud']   ?: null,                        // Días EPS
            /* 38 */ $c['diasArl'],                                    // Días ARL (30 si K)
            /* 39 */ $c['diasCcf']     ?: null,                        // Días CCF
            /* 40 */ $c['ibcFull'],                                    // Salario básico
            /* 41 */ $c['tipoSalarioAplica'] ? 'F' : null, // Tipo salario: blank en 51, 23 y 59 (PILA lo prohíbe)
            /* 42 */ $c['tienePension'] ? ($c['ibcAfp'] ?: null) : 0,  // IBC AFP (0 si sin pensión)
            /* 43 */ $c['ibcEps']      ?: null,                        // IBC EPS
            /* 44 */ $c['ibcArl'],                                     // IBC ARL
            /* 45 */ $c['ibcCcf']      ?: null,                        // IBC CCF
            /* 46 */ $c['tienePension'] ? (float) ($c['tarifaAfpStr'] ?? 0.16) : 0, // Tarifa AFP (0 si sin pensión; el 33 trae la suya)
            /* 47 */ $c['tienePension'] ? ($c['vAfp'] ?: null) : 0,    // Cotización AFP (0 si sin pensión)
            /* 48 */ 0,                                                // AVP afiliado
            /* 49 */ 0,                                                // AVP aportante
            /* 50 */ $c['tienePension'] ? ($c['vAfp'] ?: null) : 0,    // Total AFP (0 si sin pensión)
            /* 51 */ 0,                                                // FSP solidaridad
            /* 52 */ 0,                                                // FSP subsistencia
            /* 53 */ 0,                                                // Valor no retenido
            /* 54 */ ($c['esKMatriz'] || $c['esTiempoParcial']) ? null : ($c['exonerado'] === 'S' ? 0.04 : 0.125), // Tarifa EPS (vacía en K y TP)
            /* 55 */ $c['vEps']        ?: null,                        // Cotización EPS
            /* 56 */ 0,                                                // UPC
            /* 57 */ null,                                             // Número IGE
            /* 58 */ 0,                                                // Valor IGE
            /* 59 */ null,                                             // Número LMA
            /* 60 */ 0,                                                // Valor LMA
            /* 61 */ $c['tarifaArlDecimal'],                           // Tarifa ARL
            /* 62 */ $c['nivelRiesgo'],                                // Centro trabajo
            /* 63 */ $c['vArl']        ?: null,                        // Cotización ARL
            /* 64 */ $c['esKMatriz'] ? null : 0.04,                    // Tarifa CCF
            /* 65 */ $c['vCcf']        ?: null,                        // Aporte CCF
            /* 66 */ $c['esKMatriz'] ? null : (float)str_replace('0.0', '0.', $c['tarifaSenaStr']), // Tarifa SENA
            /* 67 */ $c['vSena']       ?: null,                        // Aporte SENA
            /* 68 */ $c['esKMatriz'] ? null : (float)str_replace('0.0', '0.', $c['tarifaIcbfStr']), // Tarifa ICBF
            /* 69 */ $c['vIcbf']       ?: null,                        // Aporte ICBF
            /* 70 */ 0,                                                // Tarifa ESAP
            /* 71 */ 0,                                                // Aporte ESAP
            /* 72 */ 0,                                                // Tarifa MEN
            /* 73 */ 0,                                                // Aporte MEN
            /* 74 */ null,                                             // Tipo doc UPC
            /* 75 */ null,                                             // Doc UPC
            /* 76 */ $c['exonerado'],                                  // Exonerado
            /* 77 */ $c['codArlPila']  ?: null,                        // ARL
            /* 78 */ $c['nivelRiesgo'],                                // Clase riesgo
            /* 79 */ null,                                             // Tarifa especial AFP
            /* 80 */ $fechaIng,                                        // Fecha ING
            /* 81 */ $fechaRet,                                        // Fecha RET
            /* 82 */ null, /* 83 */ null, /* 84 */ null,               // VSP/SLN
            /* 85 */ null, /* 86 */ null,                              // IGE
            /* 87 */ null, /* 88 */ null,                              // LMA
            /* 89 */ null, /* 90 */ null,                              // VAC-LR
            /* 91 */ null, /* 92 */ null,                              // VCT
            /* 93 */ null, /* 94 */ null,                              // IRL
            /* 95 */ $c['ibcOtros']    ?: null,                        // IBC parafiscales
            /* 96 */ $horasLaboradas,                                  // Horas laboradas
            /* 97 */ null,                                             // Fecha radicación
            /* 98 */ self::ACTECO_ARL[$c['nivelRiesgo']] ?? null,      // Actividad económica ARL (por nivel riesgo)
        ];

        $colTexto = [4, 31, 33, 35, 77];
        $col = 1;
        foreach ($valores as $v) {
            $cell = $sheet->getCell([$col, $fila]);
            $cell->setValue($v ?? '');
            if (in_array($col, $colTexto)) {
                $cell->getStyle()->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
            }
            $col++;
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
