<?php

namespace App\Services;

use App\Models\Plano;
use Illuminate\Support\Facades\DB;

class PlanoPilaTxtService
{
    // Tarifa ARL por nivel: formato decimal 0.XXXXXXX (9 chars, campo 61)
    private const TARIFA_ARL = [
        1 => '0.0052200', 2 => '0.0104400', 3 => '0.0243600',
        4 => '0.0435000', 5 => '0.0696000',
    ];

    // Actividad económica por nivel de riesgo (Decreto 768/2022) — campo 98, 7 chars N
    private const ACTECO_ARL = [
        1 => '1141001', 2 => '2141003', 3 => '3139202',
        4 => '4131301', 5 => '5131201',
    ];

    // Mapeo NIT AFP → código PILA oficial (cuando pensiones.codigo no tiene el valor correcto)
    private const AFP_PILA = [
        '900336004' => '25-14',  // Colpensiones (NIT real en DB)
        '800197268' => '25-14',  // Colpensiones (NIT alterno)
        '830002385' => '25-11',  // Protección
        '830001236' => '25-05',  // Porvenir
        '800200780' => '25-07',  // Skandia / Old Mutual
        '830025804' => '25-10',  // Colfondos
    ];

    /** Alfanumérico → mayúsculas Latin-1, relleno espacios derecha */
    private function A(string $val, int $len): string
    {
        $val = mb_strtoupper(trim($val), 'UTF-8');
        $val = iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $val);

        return str_pad(substr($val, 0, $len), $len, ' ', STR_PAD_RIGHT);
    }

    /** Numérico → solo dígitos, relleno ceros izquierda */
    private function N(string $val, int $len): string
    {
        $val = preg_replace('/[^0-9]/', '', $val);
        if ($val === '') {
            $val = '0';
        }

        return str_pad(substr($val, -$len), $len, '0', STR_PAD_LEFT);
    }

    /** Fecha AAAA-MM-DD o 10 espacios — maneja formatos español y SQL Server */
    private function fecha(?string $val): string
    {
        if (empty($val)) {
            return str_repeat(' ', 10);
        }
        $val = trim((string) $val);

        // 1) Formato ISO directo: YYYY-MM-DD (lo que guarda SQL Server)
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $val, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }

        // 2) Formato español: "26-abr.", "26-abr-2026", "26/abr/2026"
        static $meses = [
            'ene' => '01', 'feb' => '02', 'mar' => '03', 'abr' => '04',
            'may' => '05', 'jun' => '06', 'jul' => '07', 'ago' => '08',
            'sep' => '09', 'oct' => '10', 'nov' => '11', 'dic' => '12',
        ];
        if (preg_match('/(\d{1,2})[-\/\s]([a-z]{3})\.?(?:[-\/\s](\d{4}))?/i', $val, $m)) {
            $d = str_pad($m[1], 2, '0', STR_PAD_LEFT);
            $mo = $meses[strtolower($m[2])] ?? '01';
            $y = ! empty($m[3]) ? $m[3] : date('Y');

            return "{$y}-{$mo}-{$d}";
        }

        // 3) Cualquier otro formato — dejar a Carbon
        try {
            return \Carbon\Carbon::parse($val)->format('Y-m-d');
        } catch (\Exception $e) {
            return str_repeat(' ', 10);
        }
    }

    /** Redondeo PILA: al multiplo de 100 superior (ceil) */
    private function roundPila(float $val): int
    {
        return (int) (ceil($val / 100) * 100);
    }

    /**
     * Genera el archivo plano y lo devuelve como descarga.
     */
    public function generar(array $params): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        ['filename' => $filename, 'contenido' => $contenido] = $this->construir($params);

        return response()->streamDownload(function () use ($contenido) {
            echo $contenido;
        }, $filename, [
            'Content-Type' => 'text/plain; charset=latin-1',
            'Cache-Control' => 'max-age=0',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Construye el archivo plano en memoria.
     * Se usa tanto para la descarga como para el envío a las APIs de los
     * operadores (ver SuaporteApiService).
     *
     * @return array{filename: string, contenido: string}
     */
    public function construir(array $params): array
    {
        $aliadoId = $params['aliado_id'];
        $razonSocialId = $params['razon_social_id'];
        $mesPago = (int) $params['mes'];
        $anioPago = (int) $params['anio'];
        $nPlano = (int) $params['n_plano'];
        $tiposModal = $params['tipos_modalidad'] ?? [];
        $codigoOperador = $params['codigo_operador'] ?? '88';
        $ignorarMesVenc = $params['ignorar_mes_vencido'] ?? false;
        // El contrato cotiza el mes en curso (`contratos.paga_mes_actual`): el
        // período que se reporta en el encabezado es el mes de pago mismo, no el
        // anterior. Va como parámetro propio y no colgado de
        // `ignorar_mes_vencido`, que solo filtra qué planos entran al archivo y
        // hoy únicamente lo usa el traslado de razón social.
        $periodoMesActual = $params['periodo_mes_actual'] ?? false;
        // Liquidación puntual de UN cotizante (contratista independiente):
        // el aportante del registro tipo 1 pasa a ser la persona (CC), no la
        // razón social genérica "INDEPENDIENTE" que agrupa a todos.
        $planoIdFiltro = isset($params['plano_id']) ? (int) $params['plano_id'] : null;
        // Solo estos registros, sin cambiar el aportante: la corrección N de un
        // traslado lleva únicamente los retiros, no a todos los que ya pagaron
        // esa planilla.
        $planoIdsFiltro = array_values(array_filter(array_map('intval', (array) ($params['plano_ids'] ?? []))));
        // Modalidad E-1 (ver PilaCotizanteE1): 1 = planilla E de un día,
        // 2 = corrección. Sin el parámetro todo se comporta como siempre.
        $pasoE1 = ((int) ($params['paso'] ?? 1) === 2) ? 2 : 1;
        // Banco de pruebas: forma alterna del archivo de la E-1 (ver PilaCotizanteE1).
        $varianteE1 = $params['variante_e1'] ?? null;
        // Qué hace la línea C con la pensión: 'igual' la deja como quedó pagada,
        // 'cero' la retira del registro por completo.
        $paso2Pension = $params['paso2_pension'] ?? 'igual';
        // Planilla que corrige este archivo: ['numero' => ..., 'fecha_pago' => 'AAAA-MM-DD'].
        // Campos 9 y 10 del registro tipo 1, obligatorios en una planilla N.
        $planillaAsociada = $params['planilla_asociada'] ?? null;

        // mesPago = mes de pago (ej: 5=Mayo)
        // mesVencido = mes que se liquida (ej: 4=Abril) — los dependientes tienen mes_plano = vencido
        // En mes actual el plano se guarda con el mes de pago, así que buscarlo
        // por el mes anterior no lo encuentra: la selección se comporta igual
        // que con `ignorar_mes_vencido`. El encabezado, en cambio, solo lo
        // cambia `periodo_mes_actual` (el traslado sigue reportando como antes).
        if ($ignorarMesVenc || $periodoMesActual) {
            $mesVencido = $mesPago;
            $anioVencido = $anioPago;
        } else {
            $mesVencido = $mesPago > 1 ? $mesPago - 1 : 12;
            $anioVencido = $mesPago > 1 ? $anioPago : $anioPago - 1;
        }

        $rs = DB::table('razones_sociales')
            ->where('id', $razonSocialId)->where('aliado_id', $aliadoId)->first();
        if (! $rs) {
            throw new \RuntimeException("Razón social {$razonSocialId} no encontrada.");
        }

        $codigoArlRs = null;
        if (! empty($rs->arl_nit)) {
            $codigoArlRs = DB::table('arls')
                ->where(DB::raw('CAST(nit AS VARCHAR(20))'), (string) $rs->arl_nit)
                ->value('codigo');
        }

        $query = DB::table('planos AS p')
            ->leftJoin('facturas AS f', 'f.id', '=', 'p.factura_id')
            // filtrar por aliado_id: evita duplicar filas si el cliente existe en múltiples aliados
            ->leftJoin('clientes AS cl', function ($join) use ($aliadoId) {
                $join->on('cl.cedula', '=', 'p.no_identifi')
                    ->where('cl.aliado_id', '=', $aliadoId);
            })
            ->leftJoin('ciudades AS c', 'c.id_ciudad_t', '=', 'cl.municipio_id')
            ->leftJoin('departamentos AS d', 'd.id', '=', 'cl.departamento_id')
            ->leftJoin('pensiones AS afp_t', DB::raw('CAST(afp_t.nit AS VARCHAR(20))'), '=', DB::raw('p.cod_afp'))
            ->leftJoin('pensiones AS afp_cli', 'afp_cli.id', '=', 'cl.pension_id')
            ->leftJoin('eps AS eps_t', DB::raw('CAST(eps_t.nit AS VARCHAR(20))'), '=', DB::raw('p.cod_eps'))
            ->leftJoin('cajas AS caj_t', DB::raw('CAST(caj_t.nit AS VARCHAR(20))'), '=', DB::raw('p.cod_caja'))
            // arl_tarifas NO se une: puede tener múltiples filas por nivel y causa duplicados
            ->leftJoin('arls AS arl_m', DB::raw('CAST(arl_m.nit AS VARCHAR(20))'), '=', DB::raw('p.cod_arl'))
            ->leftJoin('tipo_modalidad AS tm', 'tm.id', '=', 'p.tipo_modalidad_id')
            // Solo por la tarifa de caja del independiente, que se pacta por
            // contrato (2% o 0,6%) — ver PilaCotizanteCalculator.
            ->leftJoin('contratos AS ctr', 'ctr.id', '=', 'p.contrato_id')
            // El plan dice qué se vendió, y en Tipo E - Extras eso es lo que
            // decide la forma de las dos planillas. Ver PilaCotizanteDosPasos.
            ->leftJoin('planes_contrato AS pln', 'pln.id', '=', 'ctr.plan_id')
            // La exoneración de SENA e ICBF es del aportante, y quien tiene su
            // NIT es la empresa del cliente: la razón social del contrato suele
            // ser una genérica ("RAZON SOCIAL", "INDEPENDIENTE") compartida por
            // cientos de clientes, así que ahí no se puede marcar.
            ->leftJoin('empresas AS emp', function ($join) use ($aliadoId) {
                $join->on('emp.id', '=', 'cl.cod_empresa')
                    ->where('emp.aliado_id', '=', $aliadoId);
            })
            ->where('p.aliado_id', $aliadoId)
            ->where('p.razon_social_id', $razonSocialId)
            ->where('p.n_plano', $nPlano)
            ->whereIn('p.tipo_reg', ['planilla', 'retiro'])
            ->whereRaw('ISNULL(p.num_dias, 0) > 0')   // excluir num_dias=0 y NULL
            ->whereNull('p.deleted_at')
            ->tap(fn ($q) => Plano::filtrarPeriodoDePago($q, $mesPago, $anioPago))
            ->select([
                'p.tipo_doc', 'p.no_identifi', 'p.tipo_modalidad_id', 'p.tipo_p', 'p.paga_mes_actual',
                'p.primer_nombre', 'p.segundo_nombre', 'p.primer_ape', 'p.segundo_ape',
                'p.cod_eps', 'p.cod_afp', 'p.cod_arl', 'p.cod_caja',
                'p.salario_basico', 'p.num_dias', 'p.nivel_riesgo',
                DB::raw('CONVERT(VARCHAR(10), p.fecha_ing, 23) AS fecha_ing'),
                DB::raw('CONVERT(VARCHAR(10), p.fecha_ret, 23) AS fecha_ret'),
                DB::raw('eps_t.codigo  AS cod_eps_pila'),
                DB::raw('afp_t.codigo  AS cod_afp_pila'),
                // Solo lo usa la E-1: su plan no incluye pensión, así que el
                // plano va sin AFP y el día simbólico necesita una.
                DB::raw('afp_cli.codigo AS cod_afp_cliente'),
                DB::raw('arl_m.codigo  AS cod_arl_pila'),
                DB::raw('caj_t.codigo  AS cod_caj_pila'),
                'f.v_eps', 'f.v_afp', 'f.v_arl', 'f.v_caja', 'f.dias_cotizados',
                'cl.genero',
                DB::raw('DATEDIFF(YEAR, cl.fecha_nacimiento, GETDATE()) AS edad_calculada'),
                DB::raw('d.id                    AS dep_id'),
                DB::raw('CAST(c.Municipio AS INT) AS mun_id'),
                // Tiempo parcial: flag y días por subsistema (ARL siempre 30)
                // Manda el snapshot del plano y, si no lo tiene (todo lo anterior
                // al cotizante 76), los días fijos de la modalidad.
                DB::raw('tm.es_tiempo_parcial   AS es_tiempo_parcial'),
                DB::raw('ISNULL(p.dias_tp_afp, ISNULL(tm.dias_afp, 30)) AS dias_afp'),
                DB::raw('ISNULL(p.dias_tp_caja, ISNULL(p.dias_tp_afp, ISNULL(tm.dias_caja, 30))) AS dias_caja'),
                // Tarifa de caja del independiente: la marca de la razón social
                // es constante para todo el archivo, el porcentaje es por contrato.
                DB::raw(((int) ($rs->es_independiente ?? 0)).' AS rs_es_independiente'),
                'ctr.porcentaje_caja',
                DB::raw('pln.codigo AS plan_codigo'),
                // Fondo de Solidaridad: el grupo decide la tarifa de pensión.
                // Manda el snapshot del plano; sin él, el del contrato.
                DB::raw('ISNULL(p.grupo_fondo_solidaridad, ctr.grupo_fondo_solidaridad) AS grupo_fondo_solidaridad'),
                DB::raw('emp.exonerado_parafiscales AS exonerado_parafiscales'),
            ]);

        if (! empty($tiposModal)) {
            $query->whereIn('p.tipo_modalidad_id', $tiposModal);
        }
        if ($planoIdFiltro) {
            $query->where('p.id', $planoIdFiltro);
        }
        if ($planoIdsFiltro) {
            $query->whereIn('p.id', $planoIdsFiltro);
        }
        $planos =$query->orderBy('p.primer_ape')->orderBy('p.primer_nombre')->get();

        Plano::validarPeriodoUnico($planos);

        if ($planoIdFiltro && $planos->isEmpty()) {
            throw new \RuntimeException("El registro {$planoIdFiltro} no existe, no coincide con el período o ya fue eliminado.");
        }

        // ── Aportante = la persona (contratista independiente), no la RS ────
        $aportanteOverride = null;
        if ($planoIdFiltro) {
            $persona = $planos->first();
            $nombreAportante = trim(
                ($persona->primer_nombre ?? '').' '.($persona->segundo_nombre ?? '').' '.
                ($persona->primer_ape ?? '').' '.($persona->segundo_ape ?? '')
            );
            // PT (Permiso por Protección Temporal) es código PILA válido: no se traduce a CE
            $mapaDoc = ['C' => 'CC', 'NIT' => 'CC', 'NUIP' => 'CC'];
            $tipoDocRaw = strtoupper(trim($persona->tipo_doc ?? 'CC'));
            $aportanteOverride = [
                'tipo_doc' => $mapaDoc[$tipoDocRaw] ?? $tipoDocRaw,
                'numero' => preg_replace('/\D/', '', (string) $persona->no_identifi),
                'nombre' => $nombreAportante,
            ];
        }

        // Razón social genérica de independientes: cada persona se liquida con
        // su propia cédula como aportante, y eso solo cabe en la planilla I.
        // En la E, PILA prohíbe que el documento del cotizante sea el mismo del
        // aportante (Res. 2388) y no admite los tipos de cotizante 2 ni 59.
        $esRsIndependiente = (bool) ($rs->es_independiente ?? false);

        // Tipo de planilla: 'N' si hay algún registro con tipo_p = 16, 'K' si todos son Estudiante K (-1), 'Y' si tiene modalidad 8, 'I' si la RS es de independientes, 'E' en cualquier otro caso
        $tipoPlanilla = $params['tipo_planilla'] ?? null;
        if (! $tipoPlanilla) {
            $tieneN = $planos->count() > 0 && $planos->contains(fn ($p) => (int) ($p->tipo_p ?? 0) === 16);
            $todosK = ! $tieneN && $planos->count() > 0 && $planos->every(fn ($p) => (int) $p->tipo_modalidad_id === -1);
            $tieneY = ! $tieneN && ! $todosK && $planos->count() > 0 && $planos->contains(fn ($p) => (int) $p->tipo_modalidad_id === 8);
            $tipoPlanilla = $tieneN ? 'N' : ($todosK ? 'K' : ($tieneY ? 'Y' : ($esRsIndependiente ? 'I' : 'E')));
        }

        // El aportante 15 (Contratante) solo cabe si TODOS los cotizantes son
        // contratistas de la planilla Y. Basta un registro de modalidad 8 para
        // que el archivo se marque 'Y', y en esas tandas mixtas la mayoría son
        // dependientes de un empleador (aportante 01).
        //
        // La planilla I solo admite aportante 2 (independiente): con 01 Enlace
        // devuelve eo.val.1.159 ("los tipos de aportantes válidos son: 2") y,
        // en la liquidación puntual, eo.val.1.027 — porque BryNex mismo crea al
        // contratista allá como tipoAportanteId 2 en
        // SuaporteApiService::crearAportanteIndependiente y luego le mandaba 01.
        $soloY = $planos->count() > 0 && $planos->every(fn ($p) => (int) $p->tipo_modalidad_id === 8);
        $tipoAportante = match (true) {
            $tipoPlanilla === 'Y' && $soloY => '15',
            $tipoPlanilla === 'I' => '2',
            default => '01',
        };

        // El código de riesgos del registro tipo 1 sale de la razón social, y
        // la genérica de independientes no tiene ARL propia: la de cada
        // contratista viene en su plano. Sin esto Enlace reclama que el
        // cotizante aporta a riesgos sin código de riesgos en el tipo 1.
        if ($tipoPlanilla === 'I' && empty($codigoArlRs)) {
            $codigoArlRs = $planos->pluck('cod_arl_pila')->first(fn ($c) => ! empty($c));
        }

        $nit = $aportanteOverride['numero'] ?? preg_replace('/[^0-9]/', '', (string) ($rs->nit ?? ''));
        $periodo = sprintf('%04d%02d', $anioPago, $mesPago);
        $actEco = $this->N((string) ($rs->actividad_economica ?? '0'), 7);

        // Campo 20 Tipo1: valor_total_nomina = SUMA de IBC caja de cada cotizante
        // (MiPlanilla valida que coincida con sumatoria de campo 45 del Tipo 2)
        //
        // El estudiante K (tipo cotizante 23) no aporta a caja: su campo 45 va
        // en cero, así que aquí tampoco puede sumar los $100 de la convención
        // CCF68 o los dos campos dejan de cuadrar.
        $valorNomina = 0;
        foreach ($planos as $_p) {
            if ((int) $_p->tipo_modalidad_id === -1) {
                continue;
            }
            $ibcF = (int) ($_p->salario_basico ?? 0);
            $dias_ = (int) ($_p->num_dias ?? $_p->dias_cotizados ?? 30);
            $ibcP = $dias_ < 30 ? (int) (ceil($ibcF * $dias_ / 30 / 100) * 100) : $ibcF;
            // La E-1 en su paso 1 cotiza caja por un solo día: si aquí se suma el
            // mes completo, el campo 20 deja de cuadrar con la suma de los
            // campos 45 y el operador rechaza el archivo.
            if ((int) $_p->tipo_modalidad_id === PilaCotizanteCalculator::TIPO_E1 && $pasoE1 === 1 && ! $varianteE1) {
                $ibcP = PilaCotizanteE1::ibcUnDia($ibcF);
            }
            // Las de dos pasos que venden caja o pensión no cotizan caja en el
            // paso 1 —su campo 45 va en cero y el aporte entra en la
            // corrección—, así que no suman nada aquí. En la corrección
            // tampoco: el campo 20 de una planilla N repite el de la planilla
            // que corrige, que es la línea A.
            //
            // Las que venden salud con caja en el paso 1 (EPS y ARL) sí: pagan
            // el día de caja, y el campo 20 tiene que cuadrar con la suma de
            // los campos 45. "Solo EPS" ya no: su paso 1 es solo pensión.
            // Ver PilaCotizanteDosPasos.
            if (in_array((int) $_p->tipo_modalidad_id, \App\Models\TipoModalidad::IDS_DOS_PASOS, true)) {
                $planDosPasos = PilaCotizanteDosPasos::planVendido($_p);

                if (! PilaCotizanteDosPasos::paso1PagaCajaYArl($planDosPasos) || $pasoE1 === 2) {
                    continue;
                }

                $ibcP = PilaCotizanteDosPasos::ibcUnDia($ibcF);
            }
            // El cotizante 33 (Fondo de Solidaridad) no cotiza caja: su campo 45
            // va en cero, así que tampoco suma los $100 de la convención CCF68.
            if ((int) $_p->tipo_modalidad_id === \App\Models\TipoModalidad::ID_FONDO_SOLIDARIDAD) {
                continue;
            }
            $cajP = ! empty($_p->cod_caj_pila) ? $_p->cod_caj_pila : 'CCF68';
            $valorNomina += ($cajP === 'CCF68') ? 100 : $ibcP;
        }

        $lineas = [];
        $lineas[] = $this->tipo1($rs, $nit, $periodo, count($planos), $codigoArlRs, (int) $valorNomina, $tipoPlanilla, $codigoOperador, $aportanteOverride, $planillaAsociada, $tipoAportante, $periodoMesActual);

        // periodoLiq = mes vencido (para validar que ING/RET estén dentro del período)
        $periodoLiq = sprintf('%04d-%02d', $anioVencido, $mesVencido); // ej: '2026-04'

        $seqLineas = 1;
        foreach ($planos as $p) {
            if ($tipoPlanilla === 'N') {
                // La corrección lleva dos registros por cotizante: la A repite
                // lo que ya quedó pagado en la planilla anterior y la C trae
                // los valores nuevos. En la E-1 esa diferencia es todo el
                // punto del archivo (la A va sin salud, la C con salud, ARL y
                // caja completas), y quien decide qué sale en cada una es
                // `paso_e1`. Para el resto de modalidades las dos líneas
                // siguen saliendo iguales, como hasta ahora.
                $p->codigo_operador = $codigoOperador;
                $p->paso2_pension = $paso2Pension;
                $p->paso_e1 = 1;
                $lineas[] = $this->tipo2($p, $seqLineas++, $actEco, $codigoArlRs, $periodoLiq, 'A');
                $p->paso_e1 = 2;
                $lineas[] = $this->tipo2($p, $seqLineas++, $actEco, $codigoArlRs, $periodoLiq, 'C');
            } else {
                $p->paso_e1 = $pasoE1;
                $p->codigo_operador = $codigoOperador;
                if ($varianteE1) {
                    $p->variante_e1 = $varianteE1;
                }
                $lineas[] = $this->tipo2($p, $seqLineas++, $actEco, $codigoArlRs, $periodoLiq);
            }
        }

        // Nombre del archivo: NOMBRE_RS_MES_ANIO_Pn_plano.txt
        // Ej: AML_CONTACT_CENTER_5_2026_P2.txt
        $nombreRs = preg_replace('/[^A-Z0-9]+/', '_', strtoupper(trim($rs->razon_social ?? 'RS')));
        $nombreRs = trim($nombreRs, '_');
        $filename = "{$nombreRs}_{$mesPago}_{$anioPago}_P{$nPlano}.txt";
        $contenido = implode("\r\n", $lineas);

        return ['filename' => $filename, 'contenido' => $contenido];
    }

    // ── Registro Tipo 1 — 359 chars, 22 campos (Resolución 2388) ─────────────
    /**
     * @param  array{tipo_doc:string,numero:string,nombre:string}|null  $aportanteOverride
     *                                                                                      Cuando viene (liquidación puntual de un independiente), el
     *                                                                                      aportante del registro es la persona (CC), no la razón social.
     * @param  array{numero:string,fecha_pago:string}|null  $planillaAsociada
     *                                                                         Planilla que corrige este archivo. Obligatorio en una planilla N:
     *                                                                         sin el número y la fecha en que se pagó, el operador no sabe
     *                                                                         sobre cuál aplicar la corrección. Se ignora en los demás tipos.
     */
    private function tipo1(object $rs, string $nit, string $periodo, int $total, ?string $codigoArlRs, int $valorNomina, string $tipoPlanilla = 'E', string $codigoOperador = '88', ?array $aportanteOverride = null, ?array $planillaAsociada = null, string $tipoAportante = '01', bool $periodoMesActual = false): string
    {
        $anio = (int) substr($periodo, 0, 4);
        $mes = (int) substr($periodo, 4, 2);

        // periodoNoSalud = mes vencido (un mes antes del pago) ej: 2026-04
        // periodoSalud   = mes de pago ej: 2026-05
        //
        // Salvo cuando el contrato cotiza el mes en curso: ahí el mes de pago y
        // el mes cotizado son el mismo, y restarle uno reportaría un período que
        // el cotizante no trabajó.
        $mesVenc = $periodoMesActual ? $mes : ($mes > 1 ? $mes - 1 : 12);
        $anioVenc = $periodoMesActual ? $anio : ($mes > 1 ? $anio : $anio - 1);
        $periodoNoSalud = sprintf('%04d-%02d', $anioVenc, $mesVenc);
        // Para tipo Y: ambos periodos = mes vencido (sistemas diferentes a salud).
        // Para tipo I: Enlace exige que los dos periodos sean iguales
        // (eo.val.1.035, "los periodos de cotización y de servicio para salud
        // deben ser iguales"), así que la salud también va al mes vencido.
        $periodoSalud = in_array($tipoPlanilla, ['Y', 'I'], true)
            ? $periodoNoSalud
            : sprintf('%04d-%02d', $anio, $mes);

        // Independiente puntual: aportante = la persona (CC), sin DV (solo aplica a NIT).
        $nombreAportante = $aportanteOverride['nombre'] ?? ($rs->razon_social ?? '');
        $tipoDocAportante = $aportanteOverride['tipo_doc'] ?? 'NI';
        $dv = $aportanteOverride
            ? '0'
            : str_pad(preg_replace('/[^0-9]/', '', (string) ($rs->dv ?? '0')), 1, '0', STR_PAD_LEFT);

        // El independiente es persona natural: se presenta como aportante único
        // ('U') y no tiene sucursales. Con 'S' el operador reclama que la forma
        // de presentación no concuerda con la que tiene registrada, y de paso
        // exige un código y un nombre de sucursal que no existen — la razón
        // social genérica de independientes no los tiene.
        $esIndependiente = $tipoPlanilla === 'I';
        $formaPresentacion = $esIndependiente ? 'U' : 'S';
        $codigoSucursal = $esIndependiente ? '' : ($rs->codigo_sucursal ?? '');
        $nombreSucursal = $esIndependiente ? '' : ($rs->nombre_sucursal ?? '');

        // Campo 9: en una planilla que no corrige nada iban —y siguen yendo—
        // diez espacios. Solo cuando llega la planilla asociada se rellena con
        // ceros a la izquierda, que es como el campo está declarado (N 10).
        $numeroAsociada = trim((string) ($planillaAsociada['numero'] ?? ''));
        $numPlanillaAsoc = $numeroAsociada !== ''
            ? $this->N($numeroAsociada, 10)
            : $this->A('', 10);

        $linea =
            '01'                                                   // 1  tipo_registro       N 2   pos 1-2
            .'1'                                                  // 2  modalidad_planilla   N 1   pos 3   (1=electrónica)
            .'0001'                                               // 3  secuencia            N 4   pos 4-7
            .$this->A($nombreAportante, 200)                      // 4  razon_social         A 200 pos 8-207
            .$this->A($tipoDocAportante, 2)                       // 5  tipo_doc_aportante   A 2   pos 208-209
            .$this->A($nit, 16)                                   // 6  num_doc_aportante    A 16  pos 210-225
            .$dv                                                   // 7  digito_verificacion  N 1   pos 226
            .$this->A($tipoPlanilla, 1)                         // 8  tipo_planilla        A 1   pos 227 ('K'=Estudiante | 'I'=Independientes | 'E'=Ordinaria)
            .$numPlanillaAsoc                                     // 9  num_planilla_asoc    N 10  pos 228-237 (10 espacios si no es corrección)
            .$this->fecha($planillaAsociada['fecha_pago'] ?? null) // 10 fecha_pago_asoc     A 10  pos 238-247
            .$this->A($formaPresentacion, 1)                      // 11 forma_presentacion   A 1   pos 248 (S=sucursal | U=único)
            .$this->A($codigoSucursal, 10)                        // 12 codigo_sucursal      A 10  pos 249-258
            .$this->A($nombreSucursal, 40)                        // 13 nombre_sucursal      A 40  pos 259-298
            .$this->A($codigoArlRs ?? '', 6)                      // 14 codigo_arl           A 6   pos 299-304
            .$this->A($periodoNoSalud, 7)                         // 15 periodo_no_salud     A 7   pos 305-311
            .$this->A($periodoSalud, 7)                           // 16 periodo_salud        A 7   pos 312-318
            .str_pad('1', 10, '0', STR_PAD_LEFT)                  // 17 num_radicacion       N 10  pos 319-328
            .$this->A('', 10)                                     // 18 fecha_pago           A 10  pos 329-338
            .str_pad((string) $total, 5, '0', STR_PAD_LEFT)        // 19 total_empleados      N 5   pos 339-343
            .str_pad((string) $valorNomina, 12, '0', STR_PAD_LEFT) // 20 valor_total_nomina   N 12  pos 344-355
            .$this->N($tipoAportante, 2)                          // 21 tipo_aportante       N 2   pos 356-357 (01=empleador | 15=contratante)
            .$this->N($codigoOperador, 2);                        // 22 codigo_operador      N 2   pos 358-359

        if (strlen($linea) !== 359) {
            throw new \RuntimeException('Tipo 1 generó '.strlen($linea).' chars (esperado 359).');
        }

        return $linea;
    }

    // ── Registro Tipo 2 — 693 chars, 98 campos ───────────────────────────────
    private function tipo2(object $p, int $seq, string $actEco, ?string $codigoArlRs, string $periodoLiq = '', string $tipoCorr = ' '): string
    {
        // ── Calculador centralizado (todas las reglas de negocio PILA) ────────
        $c = PilaCotizanteCalculator::calcular($p);

        $esPlanillaY = ((int) $p->tipo_modalidad_id === 8);

        $tipoCot = str_pad((string) $c['tipoCotizante'], 2, '0', STR_PAD_LEFT); // '01','02','23'
        $subtipo = $esPlanillaY ? '00' : str_pad((string) $c['subtipoCotizante'], 2, '0', STR_PAD_LEFT);
        $exonerado = $c['exonerado'];
        $tienePension = $c['tienePension'];

        // ── Tipo documento ────────────────────────────────────────────────────
        $tipoDoc = strtoupper(trim($p->tipo_doc ?? 'CC'));
        // PT (Permiso por Protección Temporal) es código PILA válido: no se traduce a CE
        $mapaDoc = ['C' => 'CC', 'NIT' => 'CC', 'NUIP' => 'CC'];
        $tipoDoc = $mapaDoc[$tipoDoc] ?? $tipoDoc;
        $esExtranjero = $c['esExtranjero'] ? 'X' : ' ';

        // ── IBC y días (del calculador) ───────────────────────────────────────
        $ibcFull = $c['ibcFull'];
        $ibcProp = $c['ibcProp'];
        $dias = $c['dias'];
        // Tipo salario: blank para los tipos de cotizante a los que PILA le
        // prohíbe marcar el campo — 51 (tiempo parcial), 23 (estudiante K,
        // Decreto 055/2015, que solo aporta a riesgos) y 59 (contratista con
        // prestación de servicios). Marcarlo es el error `eo.val.2.237`.
        $esIntegral = ! $c['tipoSalarioAplica']
            ? ' '
            : (strtoupper(trim($p->tipo_p ?? '')) === 'I' ? 'X' : 'F');

        // ── Cotizaciones (del calculador) ──────────────────────────────────────
        $vAfp = $c['vAfp'];
        $vEps = $c['vEps'];
        $vArl = $c['vArl'];
        $vCaj = $c['vCcf'];

        // ── Código AFP PILA (lookup en tabla AFP_PILA del TXT) ─────────────────
        if ($tienePension && ! $esPlanillaY) {
            $nitAfp = preg_replace('/[^0-9]/', '', (string) ($p->cod_afp ?? ''));
            $codAfpDb = $p->cod_afp_pila ?? null;
            $codAfp = self::AFP_PILA[$nitAfp] ?? ((! empty($codAfpDb)) ? $codAfpDb : '');
            // La E-1 cotiza un día de pensión aunque el plan no la incluya, así
            // que su plano va sin AFP y el código lo resuelve el calculador
            // (fondo de la ficha del cliente, o COLPENSIONES). Sin esto el
            // registro sale con cotización a pensión y sin administradora, que
            // es lo que el operador rechaza.
            if ($codAfp === '') {
                $codAfp = (string) $c['codAfpPila'];
            }
        } else {
            $codAfp = '';
        }

        // ── Códigos EPS / ARL / CCF ────────────────────────────────────────────
        //
        // La E-1 además marca la novedad VAC-LR (campo 27) con 'L', licencia
        // remunerada, en todas sus filas y sin fechas en los campos 89/90. Es
        // lo que trae el archivo que el operador ya aceptó y pagó: sin esa
        // novedad, un dependiente con un día de pensión y treinta de salud no
        // le cuadra al validador, que pregunta por los veintinueve días que
        // faltan. No es una justificación legal —en una licencia remunerada la
        // pensión sí se cotiza—, es lo que hace que el archivo pase.
        // La novedad VAC-LR solo la lleva el paso 1 —y la línea A de la
        // corrección, que lo repite—. En la línea C no puede ir: ahí se paga la
        // ARL con su tarifa real, y el ausentismo obliga a que esa tarifa sea
        // cero (eo.val.2.447, y su consecuencia eo.val.2.061). Las variantes de
        // una sola planilla tampoco la llevan, por lo mismo.
        $esE1 = ((int) $p->tipo_modalidad_id === PilaCotizanteCalculator::TIPO_E1)
             && empty($p->variante_e1)
             && ((int) ($p->paso_e1 ?? 1) !== 2);
        // Con la marca de colombiano en el exterior la E-1 va sin administradora
        // de salud: quien está fuera del país no cotiza salud en Colombia, y el
        // calculador ya dejó el código vacío para decirlo.
        // `forzarSinSalud` es la misma decisión tomada por el calculador y no
        // por el paso: las modalidades de dos pasos van sin salud en las dos líneas de
        // la corrección, no solo en la A. Ver PilaCotizanteDosPasos.
        $sinSaludE1 = ($esE1 || ! empty($c['forzarSinSalud']))
                   && $c['codEpsPila'] === '' && (int) $c['diasSalud'] === 0;
        $codEps = ($esPlanillaY || $sinSaludE1) ? '' : (! empty($p->cod_eps_pila) ? $p->cod_eps_pila : $c['codEpsPila']);
        $codArl = ! empty($p->cod_arl_pila) ? $p->cod_arl_pila : ($codigoArlRs ?? '');
        $codCaj = $esPlanillaY ? '' : $c['codCcfPila'];

        // ── IBC por subsistema ─────────────────────────────────────────────────
        $ibcCaj = $c['ibcCcf'];

        // ── Depto / Municipio (del calculador: 99 si CCF68/K) ────────────────────
        $depId = $c['depCod'];
        $munId = $c['munCod'];

        // ── Tarifas (del calculador) ────────────────────────────────────────────
        $tarifaSalud = $c['tarifaEpsStr'];
        // El campo 61 (tarifa de riesgos) son 9 posiciones con 7 decimales,
        // a diferencia de las demás tarifas que van en 7 con 5 decimales.
        // Rellenarlo con espacios lo invalida: Enlace responde eo.val.2.C061.
        $tarifaArl = sprintf('%.7f', $c['tarifaArlDecimal']);
        $tarifaSENA = $c['tarifaSenaStr'];
        $tarifaICBF = $c['tarifaIcbfStr'];
        $nivel = $c['nivelRiesgo'];
        $ibcOtros = $c['ibcOtros'];
        $vSENA = $c['vSena'];
        $vICBF = $c['vIcbf'];

        // ── ING / RET ──────────────────────────────────────────────────────────
        $fechaIng = $this->fecha($p->fecha_ing ?? null);
        $fechaRet = $this->fecha($p->fecha_ret ?? null);
        $blanco10 = str_repeat(' ', 10);

        // En la línea A de la planilla de corrección N, no se reportan novedades de ingreso ni de retiro.
        if ($tipoCorr === 'A') {
            $fechaIng = $blanco10;
            $fechaRet = $blanco10;
        }

        $periodoYm = substr($fechaIng, 0, 7);
        $ing = ($fechaIng !== $blanco10 && $periodoYm >= $periodoLiq) ? 'X' : ' ';
        $periodoYmR = substr($fechaRet, 0, 7);
        $ret = ($fechaRet !== $blanco10 && $periodoYmR >= $periodoLiq) ? ((int) $p->tipo_modalidad_id === 8 ? 'T' : 'X') : ' ';

        // La E-1 marca siempre la novedad de ingreso, con fecha o sin ella —es
        // lo que trae el archivo que el operador aceptó—. Es lo que justifica
        // ante el validador que se coticen menos de 30 días: sin ING ni RET,
        // Enlace rechaza con eo.val.2.151 ("los días cotizados a pensión no
        // pueden ser menores a 30") y eo.val.2.484, su equivalente en riesgos.
        if ($esE1 || ! empty($c['forzarIng'])) {
            $ing = 'X';

            // Del segundo mes en adelante la fecha real de ingreso es de un
            // período viejo, y marcarla junto al ING es eo.val.2.429 ("la fecha
            // inicio reportada para la novedad ING no es válida, debe
            // corresponder al periodo de cotización reportado"). El archivo del
            // cliente que el operador ya aceptó marca ING **sin fecha**: la
            // novedad aquí no dice que la persona entró este mes, dice que no
            // se cotizan los 30 días. Solo aplica a quien pide forzarIng, para
            // no cambiarle el archivo a la E-1.
            if (! empty($c['forzarIng']) && $fechaIng !== $blanco10 && substr($fechaIng, 0, 7) < $periodoLiq) {
                $fechaIng = $blanco10;
            }
        }

        // Novedades que una variante de prueba quiera encender explícitamente.
        $nov = $c['novedades'] ?? [];
        if (isset($nov['ING'])) {
            $ing = $nov['ING'];
        }

        $linea =
            $this->N('02', 2)                                   // 1  pos 1-2
            .$this->N((string) $seq, 5)                         // 2  pos 3-7
            .$this->A($tipoDoc, 2)                             // 3  pos 8-9
            .$this->A((string) $p->no_identifi, 16)             // 4  pos 10-25
            .$this->N($tipoCot, 2)                             // 5  pos 26-27
            .$this->N($subtipo, 2)                             // 6  pos 28-29
            .$this->A($esExtranjero, 1)                        // 7  pos 30
            .$this->A(! empty($c['colombianoExterior']) ? 'X' : ' ', 1) // 8 colombiano exterior pos 31
            .$this->N($depId, 2)                               // 9  pos 32-33
            .$this->N($munId, 3)                               // 10 pos 34-36
            .$this->A($p->primer_ape ?? '', 20)             // 11 pos 37-56
            .$this->A($p->segundo_ape ?? '', 30)             // 12 pos 57-86
            .$this->A($p->primer_nombre ?? '', 20)             // 13 pos 87-106
            .$this->A($p->segundo_nombre ?? '', 30)            // 14 pos 107-136
            .$this->A($ing, 1)                                 // 15 pos 137
            .$this->A($ret, 1)                                 // 16 pos 138
            .$this->A(' ', 1)                                  // 17 TDE pos 139
            .$this->A(' ', 1)                                  // 18 TAE pos 140
            .$this->A(' ', 1)                                  // 19 TDP pos 141
            .$this->A(' ', 1)                                  // 20 TAP pos 142
            .$this->A(' ', 1)                                  // 21 VSP pos 143
            .$this->A($tipoCorr, 1)                            // 22 Correcciones pos 144
            .$this->A(' ', 1)                                  // 23 VST pos 145
            .$this->A($nov['SLN'] ?? ' ', 1)                   // 24 SLN pos 146
            .$this->A(' ', 1)                                  // 25 IGE pos 147
            .$this->A(' ', 1)                                  // 26 LMA pos 148
            .$this->A($nov['VACLR'] ?? ($esE1 ? 'L' : ' '), 1) // 27 VAC-LR pos 149
            .$this->A(' ', 1)                                  // 28 AVP pos 150
            .$this->A(' ', 1)                                  // 29 VCT pos 151
            .$this->N('0', 2)                                  // 30 IRL pos 152-153
            .$this->A($codAfp, 6)                              // 31 pos 154-159
            .$this->A('', 6)                                   // 32 AFP traslada pos 160-165
            .$this->A($codEps, 6)                              // 33 pos 166-171
            .$this->A('', 6)                                   // 34 EPS traslada pos 172-177
            .$this->A($codCaj, 6)                              // 35 pos 178-183
            .$this->N((string) $c['diasPension'], 2)            // 36 días pensión 184-185
            .$this->N((string) $c['diasSalud'], 2)              // 37 días salud 186-187
            .$this->N((string) $c['diasArl'], 2)                // 38 días riesgos 188-189
            .$this->N((string) $c['diasCcf'], 2)                // 39 días CCF 190-191
            .$this->N((string) $ibcFull, 9)                     // 40 salario completo 192-200
            .$this->A($esIntegral, 1)                          // 41 tipo salario 201
            .$this->N((string) $c['ibcAfp'], 9)                 // 42 IBC pensión 202-210
            .$this->N((string) $c['ibcEps'], 9)                 // 43 IBC salud 211-219
            .$this->N((string) $c['ibcArl'], 9)                 // 44 IBC riesgos 220-228
            .$this->N((string) $ibcCaj, 9)                      // 45 IBC CCF 229-237
            .($c['tarifaAfpStr'] ?? ($tienePension ? '0.16000' : '0.00000')) // 46 tarifa pensión 238-244 (el 33 trae la suya)
            .$this->N((string) $vAfp, 9)                        // 47 cotización pensión 245-253
            .$this->N('0', 9)                                  // 48 aporte vol afiliado 254-262
            .$this->N('0', 9)                                  // 49 aporte vol aportante 263-271
            .$this->N((string) $vAfp, 9)                        // 50 total pensión 272-280
            .$this->N('0', 9)                                  // 51 FSP solidaridad 281-289
            .$this->N('0', 9)                                  // 52 FSP subsistencia 290-298
            .$this->N('0', 9)                                  // 53 valor no retenido 299-307
            .$tarifaSalud                                       // 54 tarifa salud 308-314
            .$this->N((string) $vEps, 9)                        // 55 cotización salud 315-323
            .$this->N('0', 9)                                  // 56 ADRES/UPC 324-332
            .$this->A('', 15)                                  // 57 auth incapacidad 333-347
            .$this->N('0', 9)                                  // 58 valor incapacidad 348-356
            .$this->A('', 15)                                  // 59 auth licencia 357-371
            .$this->N('0', 9)                                  // 60 valor licencia 372-380
            .$tarifaArl                                        // 61 tarifa riesgos 381-389
            .$this->N('1', 9)                                  // 62 centro de trabajo 390-398
            .$this->N((string) $vArl, 9)                        // 63 cotización ARL 399-407
            .($c['esKMatriz'] ? '0.00000' : $c['tarifaCcfStr']) // 64 tarifa CCF 408-414 (4% dependiente, 2%/0,6% independiente)
            .$this->N((string) $vCaj, 9)                        // 65 valor CCF 415-423
            .$tarifaSENA                                        // 66 tarifa SENA 424-430
            .$this->N((string) $vSENA, 9)                       // 67 valor SENA 431-439
            .$tarifaICBF                                        // 68 tarifa ICBF 440-446
            .$this->N((string) $vICBF, 9)                       // 69 valor ICBF 447-455
            .'0.00000'                                          // 70 tarifa ESAP 456-462
            .$this->N('0', 9)                                  // 71 valor ESAP 463-471
            .'0.00000'                                          // 72 tarifa MEN 472-478
            .$this->N('0', 9)                                  // 73 valor MEN 479-487
            .$this->A('', 2)                                   // 74 tipo doc principal 488-489
            .$this->A('', 16)                                  // 75 num doc principal 490-505
            .$this->A($exonerado, 1)                           // 76 exonerado 506
            .$this->A($codArl, 6)                              // 77 código ARL 507-512
            .$this->A((string) $nivel, 1)                       // 78 clase de riesgo 513
            .' '                                               // 79 ind tarifa especial 514
            .$this->A($fechaIng, 10)                           // 80 fecha ingreso 515-524
            .$this->A($fechaRet, 10)                           // 81 fecha retiro 525-534
            .str_repeat(' ', 10)                               // 82 fecha VSP 535-544
            .str_repeat(' ', 10)                               // 83 fecha SLN ini 545-554
            .str_repeat(' ', 10)                               // 84 fecha SLN fin 555-564
            .str_repeat(' ', 10)                               // 85 fecha IGE ini 565-574
            .str_repeat(' ', 10)                               // 86 fecha IGE fin 575-584
            .str_repeat(' ', 10)                               // 87 fecha LMA ini 585-594
            .str_repeat(' ', 10)                               // 88 fecha LMA fin 595-604
            .str_repeat(' ', 10)                               // 89 fecha VAC ini 605-614
            .str_repeat(' ', 10)                               // 90 fecha VAC fin 615-624
            .str_repeat(' ', 10)                               // 91 fecha VCT ini 625-634
            .str_repeat(' ', 10)                               // 92 fecha VCT fin 635-644
            .str_repeat(' ', 10)                               // 93 fecha IRL ini 645-654
            .str_repeat(' ', 10)                               // 94 fecha IRL fin 655-664
            .$this->N((string) $ibcOtros, 9)                    // 95 IBC otros paraf 665-673
            .$this->N((string) $c['horasLaboradas'], 3)         // 96 horas laboradas 674-676 (tipo 51: dias_caja×8; otros: num_dias×8)
            .str_repeat(' ', 10)                               // 97 fecha radicación 677-686
            .self::ACTECO_ARL[$nivel];                         // 98 actividad económica 687-693

        if (strlen($linea) !== 693) {
            throw new \RuntimeException(
                'Tipo 2 generó '.strlen($linea).' chars (esperado 693) en cotizante '.$p->no_identifi
            );
        }

        return $linea;
    }
}
