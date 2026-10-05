<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\{Contrato, Factura, BitacoraCobro, ConfiguracionBrynex, ArlTarifa, Empresa, BancoCuenta, TipoModalidad};
use App\Models\{WhatsappConfig, WhatsappEnvioMasivo, WhatsappEnvioMasivoDetalle, WhatsappMensaje};
use App\Services\MoraClienteService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB, Log};


class CobrosController extends Controller
{
    // ─── Porcentajes SS base ─────────────────────────────────────────
    // Dependiente empresa: EPS 12,5% total → empleador paga 8,5%, trabajador 4%
    // Para cobros mostramos la CUOTA TOTAL del empleador (lo que le facturamos)
    // Independiente: cotiza sobre el 40% del IBC → porcentajes fijos BryNex
    // Usamos los valores configurados en ConfiguracionBrynex.

    public function index(Request $request)
    {
        $data = $this->obtenerDatosCobros($request);
        return view('admin.cobros.index', $data);
    }

    public function exportar(Request $request)
    {
        $data = $this->obtenerDatosCobros($request);
        $contratos = $data['contratos'];
        $mes = $data['mes'];
        $anio = $data['anio'];

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Cobros');

        // Encabezado
        $headers = ['Cédula', 'Nombre', 'Celular', 'Razón Social', 'Modalidad', 'Valor'];
        $sheet->fromArray($headers, null, 'A1');

        // Estilo del encabezado
        $sheet->getStyle('A1:F1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1e40af']],
        ]);

        $row = 2;
        foreach ($contratos as $c) {
            $celularRaw = preg_replace('/\D/', '', $c->cliente?->celular ?? '');
            $celular = '';
            if (!empty($celularRaw)) {
                if (str_starts_with($celularRaw, '57')) {
                    $celular = $celularRaw;
                } else {
                    $celular = '57' . $celularRaw;
                }
            }

            $sheet->fromArray([
                $c->cedula,
                $c->cliente?->nombre_completo ?? '—',
                $celular ?: '—',
                $c->razonSocial?->razon_social ?? '—',
                $c->tipoModalidad?->nombre ?? '—',
                (int)($c->total_estimado ?? 0)
            ], null, "A{$row}");

            $sheet->getStyle("F{$row}")->getNumberFormat()->setFormatCode('$#,##0');
            $row++;
        }

        // Auto-ancho columnas
        foreach (range('A', 'F') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $meses = ['', 'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
        $filename = "cobros_individuales_{$meses[$mes]}_{$anio}.xlsx";
        $tmpPath = tempnam(sys_get_temp_dir(), 'cobxls');
        $writer->save($tmpPath);

        return response()->download($tmpPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private function obtenerDatosCobros(Request $request)
    {
        $aliadoId = session('aliado_id_activo');
        $mes      = (int) $request->get('mes',  now()->month);
        $anio     = (int) $request->get('anio', now()->year);

        // ── Pre-carga de configuraciones (1 query total en vez de N×6) ──────
        ConfiguracionBrynex::precargar();

        // ── Pre-carga de tarifas ARL (1 query total en vez de N×2) ──────────
        $arlTarifasRaw = DB::table('arl_tarifas')
            ->where(function ($q) use ($aliadoId) {
                $q->where('aliado_id', $aliadoId)->orWhereNull('aliado_id');
            })
            ->get()
            ->groupBy('nivel');

        // Resuelve pct ARL por nivel sin tocar BD
        $getArlPct = function (int $nivel) use ($arlTarifasRaw, $aliadoId): float {
            $grupo = $arlTarifasRaw->get($nivel);
            if (!$grupo) return 0.0;
            $porAliado = $grupo->firstWhere('aliado_id', $aliadoId);
            if ($porAliado) return (float) $porAliado->porcentaje;
            $global = $grupo->first(fn($t) => $t->aliado_id === null);
            return (float) ($global?->porcentaje ?? 0.0);
        };

        // ── Filtros opcionales ──────────────────────────────────────
        $rsId     = $request->get('razon_social_id');
        $asesorId = $request->get('asesor_id');
        $buscar   = $request->get('buscar');
        $soloInd  = $request->get('tipo', 'individual'); // individual | todos
        $soloPend = $request->get('estado', 'pendiente'); // pendiente | todos
        $sort     = $request->get('sort', 'nombre');
        $dir      = $request->get('dir', 'asc') === 'desc' ? 'desc' : 'asc';
        $afilPlan = $request->get('afil_plan');

        // ── Último día del periodo consultado (para excluir contratos futuros) ──
        $ultimoDiaPeriodo = Carbon::create($anio, $mes, 1)->endOfMonth()->toDateString();

        // ── Contratos vigentes del aliado ───────────────────────────
        // Solo incluir vigentes cuya fecha_ingreso <= último día del periodo consultado
        $q = Contrato::where('aliado_id', $aliadoId)
            ->whereIn('estado', ['vigente', 'activo'])
            ->where('contratos.fecha_ingreso', '<=', $ultimoDiaPeriodo)
            ->with(['cliente.empresa', 'tipoModalidad', 'razonSocial', 'asesor', 'plan', 'eps', 'arl', 'pension', 'caja']);

        // Filtro: tipo (individual / empresas / todos)
        if ($soloInd === 'individual') {
            // Subquery nativa: evita cargar cédulas a PHP y enviar un whereIn masivo
            $q->whereIn('cedula', function ($sub) use ($aliadoId) {
                $sub->from('clientes')
                    ->select('cedula')
                    ->where('aliado_id', $aliadoId)
                    ->where(function ($sq) {
                        $sq->where('cod_empresa', 1)
                           ->orWhereNull('cod_empresa');
                    });
            });
        } elseif ($soloInd === 'empresas') {
            $q->whereIn('cedula', function ($sub) use ($aliadoId) {
                $sub->from('clientes')
                    ->select('cedula')
                    ->where('aliado_id', $aliadoId)
                    ->where('cod_empresa', '>', 1)
                    ->whereNotNull('cod_empresa');
            });
        }

        // Capturar razones sociales disponibles en este período y tipo antes de aplicar filtros opcionales (búsqueda, RS, asesor)
        $rsIdsUsados = (clone $q)->pluck('razon_social_id')->filter()->unique()->toArray();

        // Filtro: razón social
        if ($rsId) $q->where('razon_social_id', $rsId);

        // Filtro: asesor
        if ($asesorId) $q->where('asesor_id', $asesorId);

        // Filtro: búsqueda nombre/cédula
        if ($buscar) {
            $q->where(function ($sq) use ($buscar) {
                $sq->where('cedula', 'like', "%$buscar%")
                   ->orWhereHas('cliente', fn($cq) => $cq
                       ->wherePalabrasSinTildes(['primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido'], $buscar));
            });
        }

        // --- Obtener modalidades únicas presentes en la consulta actual (antes de filtrar por modalidad) ---
        $modalidadesIds = (clone $q)->pluck('tipo_modalidad_id')->unique()->filter()->values()->toArray();
        $modalidadesDisponibles = TipoModalidad::whereIn('id', $modalidadesIds)
            ->orderBy('tipo_modalidad')
            ->get();

        // Filtro: tipo modalidad
        $tipoModalFiltro = $request->get('tipo_modal');
        if ($tipoModalFiltro) $q->where('tipo_modalidad_id', $tipoModalFiltro);

        // Ordenamiento
        $sortMap = [
            'cedula'   => 'contratos.cedula',
            'ingreso'  => 'contratos.fecha_ingreso',
            'contrato' => 'contratos.id',
        ];
        if (isset($sortMap[$sort])) {
            $q->orderBy($sortMap[$sort], $dir);
        }

        $contratos = $q->get();

        // ── Contratos RETIRADOS con factura de retiro en este periodo ──────
        // Criterio: estado=retirado + EXISTS factura (numero_factura=0, tipo=planilla, mes/anio del periodo)
        $qRet = Contrato::where('aliado_id', $aliadoId)
            ->where('estado', 'retirado')
            ->whereHas('facturas', fn($fq) => $fq
                ->where('mes', $mes)
                ->where('anio', $anio)
                ->where('numero_factura', 0)
                ->where('tipo', 'planilla')
                ->whereNull('deleted_at')
            )
            ->with(['cliente.empresa', 'tipoModalidad', 'razonSocial', 'asesor', 'plan', 'eps', 'arl', 'pension', 'caja']);

        // Aplicar los mismos filtros de tipo individual/empresa
        if ($soloInd === 'individual') {
            $qRet->whereIn('cedula', function ($sub) use ($aliadoId) {
                $sub->from('clientes')->select('cedula')->where('aliado_id', $aliadoId)
                    ->where(function ($sq) { $sq->where('cod_empresa', 1)->orWhereNull('cod_empresa'); });
            });
        } elseif ($soloInd === 'empresas') {
            $qRet->whereIn('cedula', function ($sub) use ($aliadoId) {
                $sub->from('clientes')->select('cedula')->where('aliado_id', $aliadoId)
                    ->where('cod_empresa', '>', 1)->whereNotNull('cod_empresa');
            });
        }
        if ($rsId)     $qRet->where('razon_social_id', $rsId);
        if ($asesorId) $qRet->where('asesor_id', $asesorId);
        if ($buscar) {
            $qRet->where(function ($sq) use ($buscar) {
                $sq->where('cedula', 'like', "%$buscar%")
                   ->orWhereHas('cliente', fn($cq) => $cq
                       ->wherePalabrasSinTildes(['primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido'], $buscar));
            });
        }
        if ($tipoModalFiltro) $qRet->where('tipo_modalidad_id', $tipoModalFiltro);

        $contratosRetirados = $qRet->get();

        // Marcar cada retirado (con factura de retiro en el periodo) para identificarlo más adelante
        $contratosRetirados->each(fn($c) => $c->_es_retirado_periodo = true);

        // ── TERCER GRUPO: Retirados con PLANILLA NORMAL en el periodo ─────────
        // Estos son contratos que estaban vigentes en el mes consultado (tenían planilla
        // o afiliación pagada en ese mes) pero se retiraron DESPUÉS (mes siguiente, etc.).
        // El informe consolidado los cuenta en ADMON VIGENTES o AFILIACIONES del mes.
        // Su factura de retiro (numero_factura=0) es de un mes POSTERIOR, no del periodo.
        $idsYaCaptados = $contratos->pluck('id')->merge($contratosRetirados->pluck('id'))->unique()->toArray();

        $qRetNormal = Contrato::where('aliado_id', $aliadoId)
            ->where('estado', 'retirado')
            ->whereNotIn('contratos.id', $idsYaCaptados)
            ->whereHas('facturas', fn($fq) => $fq
                ->where('mes', $mes)
                ->where('anio', $anio)
                ->where('numero_factura', '>', 0)
                ->whereIn('tipo', ['planilla', 'afiliacion'])
                ->whereNull('deleted_at')
            )
            ->with(['cliente.empresa', 'tipoModalidad', 'razonSocial', 'asesor', 'plan', 'eps', 'arl', 'pension', 'caja']);

        // Aplicar los mismos filtros opcionales
        if ($soloInd === 'individual') {
            $qRetNormal->whereIn('cedula', function ($sub) use ($aliadoId) {
                $sub->from('clientes')->select('cedula')->where('aliado_id', $aliadoId)
                    ->where(function ($sq) { $sq->where('cod_empresa', 1)->orWhereNull('cod_empresa'); });
            });
        } elseif ($soloInd === 'empresas') {
            $qRetNormal->whereIn('cedula', function ($sub) use ($aliadoId) {
                $sub->from('clientes')->select('cedula')->where('aliado_id', $aliadoId)
                    ->where('cod_empresa', '>', 1)->whereNotNull('cod_empresa');
            });
        }
        if ($rsId)     $qRetNormal->where('razon_social_id', $rsId);
        if ($asesorId) $qRetNormal->where('asesor_id', $asesorId);
        if ($buscar) {
            $qRetNormal->where(function ($sq) use ($buscar) {
                $sq->where('cedula', 'like', "%$buscar%")
                   ->orWhereHas('cliente', fn($cq) => $cq
                       ->wherePalabrasSinTildes(['primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido'], $buscar));
            });
        }
        if ($tipoModalFiltro) $qRetNormal->where('tipo_modalidad_id', $tipoModalFiltro);

        $contratosRetNormal = $qRetNormal->get();
        // Marcar como "retirado posterior al periodo" para tratamiento diferenciado
        $contratosRetNormal->each(fn($c) => $c->_es_retirado_posterior = true);

        // ── CUARTO GRUPO: Retiros Informativos por fecha_retiro (igual que InformeController) ──
        // Criterio (igual al informe consolidado):
        //   - tipo_modalidad=11: fecha_retiro en el mes consultado
        //   - Otros: fecha_retiro en el mes ANTERIOR al consultado
        // Estos tienen factura retiro con total_ss=0. Su invoice puede ser de CUALQUIER mes.
        $mesAntInf  = $mes === 1 ? 12 : $mes - 1;
        $anioAntInf = $mes === 1 ? $anio - 1 : $anio;

        $idsYaCaptadosTodos = $contratos->pluck('id')
            ->merge($contratosRetirados->pluck('id'))
            ->merge($contratosRetNormal->pluck('id'))
            ->unique()->toArray();

        $qRetInf = Contrato::where('aliado_id', $aliadoId)
            ->where('estado', 'retirado')
            ->whereNotIn('contratos.id', $idsYaCaptadosTodos)
            ->where(function ($q) use ($mes, $anio, $mesAntInf, $anioAntInf) {
                // Mes actual: fecha_retiro en el mes consultado
                $q->where(function ($q1) use ($mes, $anio) {
                    $q1->where('paga_mes_actual', 1)
                       ->whereMonth('fecha_retiro', $mes)
                       ->whereYear('fecha_retiro', $anio);
                // Resto: fecha_retiro en el mes ANTERIOR
                })->orWhere(function ($q2) use ($mesAntInf, $anioAntInf) {
                    $q2->where('paga_mes_actual', 0)
                    ->whereMonth('fecha_retiro', $mesAntInf)
                    ->whereYear('fecha_retiro', $anioAntInf);
                });
            })
            // Solo los que tengan factura retiro con total_ss=0 (informativos)
            ->whereHas('facturas', fn($fq) => $fq
                ->where('numero_factura', 0)
                ->where('tipo', 'planilla')
                ->whereNull('deleted_at')
                ->where('total_ss', '<=', 0)
            )
            ->with(['cliente.empresa', 'tipoModalidad', 'razonSocial', 'asesor', 'plan', 'eps', 'arl', 'pension', 'caja']);

        // Aplicar filtros opcionales
        if ($soloInd === 'individual') {
            $qRetInf->whereIn('cedula', function ($sub) use ($aliadoId) {
                $sub->from('clientes')->select('cedula')->where('aliado_id', $aliadoId)
                    ->where(function ($sq) { $sq->where('cod_empresa', 1)->orWhereNull('cod_empresa'); });
            });
        } elseif ($soloInd === 'empresas') {
            $qRetInf->whereIn('cedula', function ($sub) use ($aliadoId) {
                $sub->from('clientes')->select('cedula')->where('aliado_id', $aliadoId)
                    ->where('cod_empresa', '>', 1)->whereNotNull('cod_empresa');
            });
        }
        if ($rsId)     $qRetInf->where('razon_social_id', $rsId);
        if ($asesorId) $qRetInf->where('asesor_id', $asesorId);
        if ($buscar) {
            $qRetInf->where(function ($sq) use ($buscar) {
                $sq->where('cedula', 'like', "%$buscar%")
                   ->orWhereHas('cliente', fn($cq) => $cq
                       ->wherePalabrasSinTildes(['primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido'], $buscar));
            });
        }
        if ($tipoModalFiltro) $qRetInf->where('tipo_modalidad_id', $tipoModalFiltro);

        $contratosRetInf = $qRetInf->get();
        $contratosRetInf->each(fn($c) => $c->_es_retiro_informativo_directo = true);

        // Unir vigentes + retirados-con-factura-retiro + retirados-con-planilla-normal + informativos
        $contratos = $contratos->concat($contratosRetirados)->concat($contratosRetNormal)->concat($contratosRetInf);


        // ── Facturas del mes para estos contratos ───────────────────
        // Indexamos por contrato_id (primario) para soportar múltiples contratos
        // vigentes del mismo cliente (caso Ingreso-Retiro con nuevo contrato).
        // También incluimos facturas de retiro (numero_factura=0) del periodo.
        $contratoIds = $contratos->pluck('id')->toArray();
        $cedulas     = $contratos->pluck('cedula')->toArray();
        $facturasBruto = Factura::where('aliado_id', $aliadoId)
            ->periodo($mes, $anio)
            ->whereIn('tipo', ['planilla', 'afiliacion'])
            ->whereIn('cedula', $cedulas)
            ->whereNull('deleted_at')
            ->with('plano')
            ->get();

        // Para los retiros informativos (4° grupo), cargar su factura de retiro
        // SIN filtrar por mes/anio (puede ser de cualquier periodo)
        $idsInformativos = $contratosRetInf->pluck('id')->toArray();
        $facturasRetInf  = collect();
        if (!empty($idsInformativos)) {
            $facturasRetInf = Factura::where('aliado_id', $aliadoId)
                ->where('numero_factura', 0)
                ->where('tipo', 'planilla')
                ->whereIn('contrato_id', $idsInformativos)
                ->whereNull('deleted_at')
                ->with('plano')
                ->get()
                // Una factura por contrato (la más reciente)
                ->sortByDesc('id')
                ->unique('contrato_id');
        }

        // Índice principal: contrato_id → factura
        // Para retirados: priorizar la factura de retiro (numero_factura=0) sobre otras
        $facturasPorContrato = collect();
        // Primero añadir facturas normales (numero_factura > 0)
        $facturasBruto->filter(fn($f) => !empty($f->contrato_id) && (int)$f->numero_factura > 0)
            ->each(fn($f) => $facturasPorContrato->put((int)$f->contrato_id, $f));
        // Luego sobreescribir con facturas de retiro (numero_factura=0) para contratos retirados
        $facturasBruto->filter(fn($f) => !empty($f->contrato_id) && (int)$f->numero_factura === 0)
            ->each(fn($f) => $facturasPorContrato->put((int)$f->contrato_id, $f));
        // Agregar facturas de retiro de informativos (pueden ser de otro periodo)
        $facturasRetInf->each(fn($f) => $facturasPorContrato->put((int)$f->contrato_id, $f));

        // Índice secundario: cedula → factura (para facturas sin contrato_id)
        $facturasPorCedula = $facturasBruto
            ->filter(fn($f) => empty($f->contrato_id))
            ->keyBy(fn($f) => (string) $f->cedula);


        // ── Última llamada de cobro por contrato ────────────────────
        $contratoIds  = $contratos->pluck('id')->toArray();
        $ultimasLlamadas = BitacoraCobro::where('aliado_id', $aliadoId)
            ->whereIn('contrato_id', $contratoIds)
            ->orderByDesc('fecha_llamada')
            ->get()
            ->groupBy('contrato_id')
            ->map(fn($g) => $g->first()); // solo la más reciente por contrato

        // ── Préstamos pendientes — 1 query para badge ligero ───────────
        $cedulasConPrestamo = DB::table('facturas')
            ->where('aliado_id', $aliadoId)
            ->where('estado', 'prestamo')
            ->whereNull('deleted_at')
            ->pluck('cedula')
            ->flip(); // convierte a [cedula => index] para búsqueda O(1)

        // ── Pre-calcular SS por contrato (necesario antes del lote de mora) ──
        $r100 = fn($v) => (int)(ceil(($v ?? 0) / 100) * 100);

        // ── Lookup de ARL por nit (para fallback desde Razón Social) ──────
        // RazonSocial guarda el nit de la ARL en arl_nit; el contrato puede
        // tener arl_id nulo si hereda la ARL de su RS.
        $arlNitsFallback = $contratos
            ->filter(fn($c) => !$c->arl_id && $c->razonSocial?->arl_nit)
            ->map(fn($c) => $c->razonSocial->arl_nit)
            ->unique()->values()->toArray();
        $arlPorNit = \App\Models\Arl::whereIn('nit', $arlNitsFallback)
            ->get()->keyBy('nit');

        // Primera pasada: calcular vSS y flags por contrato sin tocar BD
        $vSsPorContrato = [];
        $flagsPorContrato = []; // [esAfil, esIndActPrimerMes, totalEstimado, ...]

        foreach ($contratos as $c) {
            $esArl = (int)($c->tipo_modalidad_id) === 15;
            // Solo seguro: se cobra el seguro todos los meses, sin seguridad social.
            $esSoloSeguro = (int)($c->tipo_modalidad_id) === \App\Models\Contrato::MODALIDAD_SEGUROS;
            $esAfil = false;
            $esIndActPrimerMes = false;
            if ($esSoloSeguro) {
                $esAfil = false;
            } elseif ($esArl) {
                // Gestión ARL siempre es cobro de afiliación, no planilla
                $esAfil = true;
            } elseif ($c->fecha_ingreso) {
                $fIng     = $c->fecha_ingreso;
                $esIndAct = (bool) ($c->paga_mes_actual ?? false);
                if ((int)$fIng->month === $mes && (int)$fIng->year === $anio) {
                    $esIndActPrimerMes = $esIndAct;
                    $esAfil            = !$esIndAct;
                }
            }

            $esIndep = $c->tipoModalidad?->esIndependiente() ?? false;
            $ibc     = (float)($c->salario ?? 0);
            $plan    = $c->plan;

            if ($esSoloSeguro) {
                $vEps = $vArl = $vPen = $vCaja = $vSS = 0;
                $totalEstimado = (int)($c->seguro ?? 0);
            } elseif ($esIndActPrimerMes) {
                $diasAct = max(1, 30 - (int)$c->fecha_ingreso->day + 1);
                $pctEps = ConfiguracionBrynex::pctSaludIndependiente();
                $pctPen = ConfiguracionBrynex::pctPensionIndependiente();
                $pctCaj = (float)($c->porcentaje_caja ?? ConfiguracionBrynex::pctCajaIndependienteAlto());
                $pctArl = $getArlPct((int)($c->n_arl ?? 1));
                $vEps  = ($plan?->incluye_eps)    ? $r100($ibc * $pctEps / 100 * $diasAct / 30) : 0;
                $vArl  = ($plan?->incluye_arl)    ? $r100($ibc * $pctArl / 100 * $diasAct / 30) : 0;
                $vPen  = ($plan?->incluye_pension) ? $r100($ibc * $pctPen / 100 * $diasAct / 30) : 0;
                $vCaja = ($plan?->incluye_caja)   ? $r100($ibc * $pctCaj / 100 * $diasAct / 30) : 0;
                $vSS   = $vEps + $vArl + $vPen + $vCaja;
                $totalEstimado = $vSS + (int)($c->administracion ?? 0) + (int)($c->seguro ?? 0) + (int)($c->costo_afiliacion ?? 0);
            } elseif ($esAfil) {
                $vEps = $vArl = $vPen = $vCaja = $vSS = 0;
                $totalEstimado = (int)(($c->costo_afiliacion ?? 0) + ($c->seguro ?? 0));
            } elseif ($esArl) {
                // ARL fuera de su mes de ARL → cobro es 0, no paga planilla
                $vEps = $vArl = $vPen = $vCaja = $vSS = 0;
                $totalEstimado = 0;
            } elseif ((int)($c->tipo_modalidad_id) === 12) {
                // Plan Ingreso-Retiro: si no es su primer mes (esAfil), siempre cobra costo_afiliacion + seguro, no planilla
                $vEps = $vArl = $vPen = $vCaja = $vSS = 0;
                $totalEstimado = (int)(($c->costo_afiliacion ?? 0) + ($c->seguro ?? 0));
            } else {
                $diasCotizar = 30;
                if ($c->fecha_ingreso) {
                    $mesAnt = $mes === 1 ? 12 : $mes - 1;
                    $anioAnt = $mes === 1 ? $anio - 1 : $anio;
                    if ((int)$c->fecha_ingreso->month === $mesAnt && (int)$c->fecha_ingreso->year === $anioAnt) {
                        $diasCotizar = max(1, 30 - $c->fecha_ingreso->day + 1);
                    }
                }

                if ($esIndep) {
                    $pctEps = ConfiguracionBrynex::pctSaludIndependiente();
                    $pctPen = ConfiguracionBrynex::pctPensionIndependiente();
                    $pctCaj = (float)($c->porcentaje_caja ?? ConfiguracionBrynex::pctCajaIndependienteAlto());
                } else {
                    $pctEps = ConfiguracionBrynex::pctSaludDependiente();
                    $pctPen = ConfiguracionBrynex::pctPensionDependiente();
                    $pctCaj = ConfiguracionBrynex::pctCajaDependiente();
                }
                $pctArl = $getArlPct((int)($c->n_arl ?? 1));

                if ($c->tipoModalidad?->esTiempoParcial()) {
                    // ── Tiempo Parcial: IBC distinto por entidad y sin EPS ─────
                    // Misma regla que Contrato::calcularCotizacion(): la ARL
                    // cotiza siempre sobre el SM completo (mes entero) y AFP/CAJA
                    // sobre el SM prorrateado por sus días. Nunca se prorratea
                    // por $diasCotizar.
                    $diasP     = $c->tipoModalidad->diasPorEntidad();
                    $factorMap = [7 => 0.25, 14 => 0.50, 21 => 0.75, 30 => 1.00];
                    $sm        = (float) ConfiguracionBrynex::obtener('salario_minimo', 1423500);
                    $ibcAfp    = round($sm * ($factorMap[$diasP['afp']]  ?? 1.0));
                    $ibcCaja   = round($sm * ($factorMap[$diasP['caja']] ?? 1.0));

                    $diasCotizar = 30;
                    $vEps  = 0;
                    $vArl  = ($plan?->incluye_arl)     ? $r100($sm      * $pctArl / 100) : 0;
                    $vPen  = ($plan?->incluye_pension) ? $r100($ibcAfp  * $pctPen / 100) : 0;
                    $vCaja = ($plan?->incluye_caja)    ? $r100($ibcCaja * $pctCaj / 100) : 0;
                } else {
                    $vEps  = ($plan?->incluye_eps)    ? $r100($ibc * $pctEps / 100 * $diasCotizar / 30) : 0;
                    $vArl  = ($plan?->incluye_arl)    ? $r100($ibc * $pctArl / 100 * $diasCotizar / 30) : 0;
                    $vPen  = ($plan?->incluye_pension) ? $r100($ibc * $pctPen / 100 * $diasCotizar / 30) : 0;
                    $vCaja = ($plan?->incluye_caja)   ? $r100($ibc * $pctCaj / 100 * $diasCotizar / 30) : 0;
                    if ($vCaja === 0 && $c->aplicaCargoSinCcf() && $diasCotizar === 30) {
                        $vCaja = \App\Models\Contrato::CARGO_SIN_CCF;
                    }
                }
                $vSS   = $vEps + $vArl + $vPen + $vCaja;
                $totalEstimado = $vSS + (int)($c->administracion ?? 0) + (int)($c->seguro ?? 0);
            }

            $vSsPorContrato[$c->id]   = $vSS;
            $flagsPorContrato[$c->id] = compact(
                'esAfil', 'esIndActPrimerMes', 'esIndep',
                'vEps', 'vArl', 'vPen', 'vCaja', 'vSS', 'totalEstimado'
            );
        }

        // ── Calcular mora en lote — 1 query BD para toda la lista ───────
        // Agrupa por RS para reutilizar fecha_vence: si hay dia_habil_global
        // todos comparten la misma fecha → O(1) en vez de O(N) queries.
        $moraLoteInput = [];
        foreach ($contratos as $c) {
            $rsNit  = $c->nitParaMora();
            $rsDiaH = $c->diaHabilParaMora();
            $vSS    = $vSsPorContrato[$c->id] ?? 0;
            $flags  = $flagsPorContrato[$c->id] ?? [];
            $moraLoteInput[] = [
                '_contrato_id' => $c->id,
                'rs_nit'       => $rsNit,
                'rs_dia_habil' => $rsDiaH,
                'total_ss'     => $vSS,
                // Desglose por entidad para mora exacta (igual que módulo planos)
                'eps'          => (int) ($flags['vEps']  ?? 0),
                'arl'          => (int) ($flags['vArl']  ?? 0),
                'pen'          => (int) ($flags['vPen']  ?? 0),
                'caja'         => (int) ($flags['vCaja'] ?? 0),
                'mes'          => $mes,
                'anio'         => $anio,
            ];
        }

        $moraLoteOutput = MoraClienteService::calcularLote($aliadoId, $moraLoteInput);

        // Indexar resultado de mora por contrato_id para O(1) en el map()
        $moraPorContrato = [];
        $morasDiasPorContrato = [];
        foreach ($moraLoteOutput as $fila) {
            $moraPorContrato[$fila['_contrato_id']]     = (int)($fila['mora']      ?? 0);
            $morasDiasPorContrato[$fila['_contrato_id']] = (int)($fila['dias_mora'] ?? 0);
        }

        // ── Segunda pasada: enriquecer modelos con datos calculados ─────
        // IDs de retirados-con-factura-retiro (badge RETIRO)
        $contratosRetiradosIds = $contratosRetirados->pluck('id')->flip()->all();
        // IDs de retirados-con-planilla-normal-del-periodo (tratados como vigentes con factura real)
        $contratosRetNormalIds = $contratosRetNormal->pluck('id')->flip()->all();
        // IDs de retiros informativos directos (4° grupo, por fecha_retiro)
        $contratosRetInfIds = $contratosRetInf->pluck('id')->flip()->all();

        $contratos = $contratos->map(function ($c) use (
            $mes, $anio, $facturasPorContrato, $facturasPorCedula, $ultimasLlamadas, $flagsPorContrato,
            $moraPorContrato, $cedulasConPrestamo, $morasDiasPorContrato, $arlPorNit,
            $contratosRetiradosIds, $contratosRetNormalIds, $contratosRetInfIds
        ) {
            $esRetiradoPeriodo   = isset($contratosRetiradosIds[$c->id]);
            $esRetiradoPosterior = isset($contratosRetNormalIds[$c->id]);
            $esRetiroInfDirecto  = isset($contratosRetInfIds[$c->id]);

            // ── Camino rápido para contratos RETIRADOS del periodo ──────────
            if ($esRetiradoPeriodo) {
                // Buscar su factura de retiro (numero_factura=0) del periodo
                $fact = $facturasPorContrato->get((int) $c->id)
                     ?? $facturasPorCedula->get((string) $c->cedula);

                $empresa = $c->cliente?->empresa;
                $arlObj  = $c->arl;

                $c->es_retiro           = true;
                $c->es_afil             = false;
                $c->es_ind_act_primer_mes = false;
                $c->es_ir_alerta        = false;
                $c->dias_cotiz_estim    = $fact?->dias_cotizados ?? 0;
                // Valores reales de la factura de retiro
                $c->v_eps               = (int)($fact?->v_eps  ?? 0);
                $c->v_arl               = (int)($fact?->v_arl  ?? 0);
                $c->v_pen               = (int)($fact?->v_afp  ?? 0);
                $c->v_caja              = (int)($fact?->v_caja ?? 0);
                $c->v_ss                = (int)($fact?->total_ss ?? 0);
                $c->total_estimado      = $c->v_ss;
                // Retiro informativo = factura de retiro con total_ss=0 (no se pagó SS)
                $c->es_retiro_informativo = ($c->v_ss === 0);
                $c->mora_estimada       = 0; // retirado no genera mora
                $c->mora_dias           = 0;
                // Factura
                $c->fact_emitida        = (bool)$fact;
                $c->fact_pagada         = $fact && in_array($fact->estado, ['pagada', 'abono', 'prestamo']);
                $c->fact_estado         = $fact?->estado;
                $c->fact_numero         = $fact?->numero_factura;
                $c->fact_id             = $fact?->id;
                $c->fact_n_planilla     = $fact?->plano?->numero_planilla;
                $c->fact_local_plano    = $fact?->n_plano;
                $c->fact_n_plano        = $c->fact_n_planilla ?? $c->fact_local_plano;
                $c->fact_saldo_pend     = 0;
                // Entidades
                $c->eps_nombre          = $c->eps?->nombre         ?? 'Ninguna';
                $c->arl_nombre          = $arlObj?->nombre_arl     ?? $arlObj?->razon_social ?? 'Ninguna';
                $c->afp_nombre          = $c->pension?->razon_social ?? 'Ninguna';
                $c->caja_nombre         = $c->caja?->nombre        ?? 'Ninguna';
                $c->plan_nombre         = $c->plan?->nombre        ?? 'Estándar';
                $c->tipo_mod_nombre     = $c->tipoModalidad?->nombre ?? '—';
                $c->dias_cotizados      = $fact?->dias_cotizados ?? 0;
                // Semáforo y llamadas — no aplica para retirados
                $c->ultima_llamada      = null;
                $c->dias_sin_llamar     = null;
                $c->semaforo            = 'gris';
                $c->tiene_prestamo      = false;
                $c->es_empresa          = $empresa && $empresa->id != 1;
                $c->nombre_empresa      = $c->es_empresa ? $empresa->empresa : null;
                $c->administracion      = 0; // retiro no cobra admon
                return $c;
            }

            // ── Retiros Informativos directos (4° grupo: por fecha_retiro) ──────────
            if ($esRetiroInfDirecto) {
                $fact    = $facturasPorContrato->get((int) $c->id)
                        ?? $facturasPorCedula->get((string) $c->cedula);
                $empresa = $c->cliente?->empresa;
                $arlObj  = $c->arl;

                $c->es_retiro              = true;
                $c->es_retiro_informativo  = true;
                $c->es_afil                = false;
                $c->es_ind_act_primer_mes  = false;
                $c->es_ir_alerta           = false;
                $c->dias_cotiz_estim       = $fact?->dias_cotizados ?? 0;
                $c->v_eps                  = (int)($fact?->v_eps   ?? 0);
                $c->v_arl                  = (int)($fact?->v_arl   ?? 0);
                $c->v_pen                  = (int)($fact?->v_afp   ?? 0);
                $c->v_caja                 = (int)($fact?->v_caja  ?? 0);
                $c->v_ss                   = (int)($fact?->total_ss ?? 0);
                $c->total_estimado         = 0;
                $c->mora_estimada          = 0;
                $c->mora_dias              = 0;
                $c->fact_emitida           = (bool)$fact;
                $c->fact_pagada            = $fact && in_array($fact->estado, ['pagada', 'abono', 'prestamo']);
                $c->fact_estado            = $fact?->estado;
                $c->fact_numero            = $fact?->numero_factura;
                $c->fact_id                = $fact?->id;
                $c->fact_n_planilla        = $fact?->plano?->numero_planilla;
                $c->fact_local_plano       = $fact?->n_plano;
                $c->fact_n_plano           = $c->fact_n_planilla ?? $c->fact_local_plano;
                $c->fact_saldo_pend        = 0;
                $c->eps_nombre             = $c->eps?->nombre         ?? 'Ninguna';
                $c->arl_nombre             = $arlObj?->nombre_arl     ?? $arlObj?->razon_social ?? 'Ninguna';
                $c->afp_nombre             = $c->pension?->razon_social ?? 'Ninguna';
                $c->caja_nombre            = $c->caja?->nombre        ?? 'Ninguna';
                $c->plan_nombre            = $c->plan?->nombre        ?? 'Estándar';
                $c->tipo_mod_nombre        = $c->tipoModalidad?->nombre ?? '—';
                $c->dias_cotizados         = $fact?->dias_cotizados ?? 0;
                $c->ultima_llamada         = null;
                $c->dias_sin_llamar        = null;
                $c->semaforo               = 'gris';
                $c->tiene_prestamo         = false;
                $c->es_empresa             = $empresa && $empresa->id != 1;
                $c->nombre_empresa         = $c->es_empresa ? $empresa->empresa : null;
                $c->administracion         = 0;
                return $c;
            }

            // ── Camino para RETIRADOS POSTERIORES con planilla normal en el periodo ─────
            // Eran vigentes en el mes consultado, se retiraron después.
            // Se muestran con su factura real del periodo, badge PLAN normal.
            if ($esRetiradoPosterior) {
                $fact    = $facturasPorContrato->get((int) $c->id)
                        ?? $facturasPorCedula->get((string) $c->cedula);
                $empresa = $c->cliente?->empresa;
                $arlObj  = $c->arl ?? ($c->razonSocial?->arl_nit ? ($arlPorNit->get($c->razonSocial->arl_nit) ?? null) : null);

                // Determinar tipo de factura para badge correcto
                $esAfilFact = $fact?->tipo === 'afiliacion' || ($fact && (int)$fact->afiliacion > 0 && (int)$fact->total_ss === 0);

                $c->es_retiro             = false;
                $c->es_retiro_posterior   = true;  // marcador para badge diferenciado
                $c->es_afil               = $esAfilFact;
                $c->es_ind_act_primer_mes = false;
                $c->es_ir_alerta          = false;
                $c->dias_cotiz_estim      = $fact?->dias_cotizados ?? 30;
                // Usar valores reales de la factura
                $c->v_eps               = (int)($fact?->v_eps    ?? 0);
                $c->v_arl               = (int)($fact?->v_arl    ?? 0);
                $c->v_pen               = (int)($fact?->v_afp    ?? 0);
                $c->v_caja              = (int)($fact?->v_caja   ?? 0);
                $c->v_ss                = (int)($fact?->total_ss ?? 0);
                $c->total_estimado      = (int)($fact?->total    ?? $fact?->total_ss ?? 0);
                $c->mora_estimada       = 0; // ya está retirado, sin mora
                $c->mora_dias           = 0;
                // Factura
                $c->fact_emitida        = (bool)$fact;
                $c->fact_pagada         = $fact && in_array($fact->estado, ['pagada', 'abono', 'prestamo']);
                $c->fact_estado         = $fact?->estado;
                $c->fact_numero         = $fact?->numero_factura;
                $c->fact_id             = $fact?->id;
                $c->fact_n_planilla     = $fact?->plano?->numero_planilla;
                $c->fact_local_plano    = $fact?->n_plano;
                $c->fact_n_plano        = $c->fact_n_planilla ?? $c->fact_local_plano;
                $c->fact_saldo_pend     = 0;
                // Entidades
                $pensionRaw = $c->pension?->razon_social ?? null;
                if ($pensionRaw) {
                    $pensionRaw = preg_replace('/^\d+[-\d]*_/u', '', $pensionRaw);
                    $pensionRaw = preg_replace('/\s*-\s*\d{6,}\s*$/u', '', $pensionRaw);
                    $pensionRaw = trim($pensionRaw);
                }
                $c->eps_nombre       = $c->eps?->nombre         ?? 'Ninguna';
                $c->arl_nombre       = $arlObj?->nombre_arl     ?? $arlObj?->razon_social ?? 'Ninguna';
                $c->afp_nombre       = $pensionRaw ?: 'Ninguna';
                $c->caja_nombre      = $c->caja?->nombre        ?? 'Ninguna';
                $c->plan_nombre      = $c->plan?->nombre        ?? 'Estándar';
                $c->tipo_mod_nombre  = $c->tipoModalidad?->nombre ?? '—';
                $c->dias_cotizados   = $fact?->dias_cotizados ?? 30;
                $c->ultima_llamada   = null;
                $c->dias_sin_llamar  = null;
                $c->semaforo         = 'gris';
                $c->tiene_prestamo   = false;
                $c->es_empresa       = $empresa && $empresa->id != 1;
                $c->nombre_empresa   = $c->es_empresa ? $empresa->empresa : null;
                return $c;
            }

            // ── Camino normal para contratos VIGENTES ──────────────────────
            // Buscar factura: primero por contrato_id, fallback por cédula
            $fact = $facturasPorContrato->get((int) $c->id)
                 ?? $facturasPorCedula->get((string) $c->cedula);
            $flags  = $flagsPorContrato[$c->id];

            $esAfil            = $flags['esAfil'];
            $esIndActPrimerMes = $flags['esIndActPrimerMes'];
            $vEps              = $flags['vEps'];
            $vArl              = $flags['vArl'];
            $vPen              = $flags['vPen'];
            $vCaja             = $flags['vCaja'];
            $vSS               = $flags['vSS'];
            $totalEstimado     = $flags['totalEstimado'];

            if ($esAfil) {
                $c->administracion = 0;
            }

            // ── Datos de la factura (solo estado y número) ───────
            $facturaEmitida  = $fact && !in_array($fact->estado, ['pre_factura', 'anulada']);
            $facturaPagada   = $fact && in_array($fact->estado, ['pagada', 'abono', 'prestamo']);
            $facturaEstado   = $fact?->estado;
            $facturaNumero   = $fact?->numero_factura;
            $facturaId       = $fact?->id;
            $facturaNPlanilla = $fact?->plano?->numero_planilla;
            $facturaLocalPlano = $fact?->n_plano;
            $facturaNPlano   = $facturaNPlanilla ?? $facturaLocalPlano;
            $facturaSaldoPend= 0; // saldo_pendiente eliminado — derivar de saldo_proximo si se necesita

            // ── Semáforo ─────────────────────────────────────────
            $ultimaLlamada = $ultimasLlamadas->get($c->id);
            $diasSinLlamar = $ultimaLlamada
                ? (int)$ultimaLlamada->fecha_llamada->diffInDays(now())
                : null;

            $semaforo = match(true) {
                $diasSinLlamar === null => 'gris',   // nunca llamado
                $diasSinLlamar < 3      => 'verde',
                $diasSinLlamar <= 7     => 'amarillo',
                default                 => 'rojo',
            };

            // ── Mora estimada (ya calculada en lote, O(1) aquí) ──────────
            $moraEst = $moraPorContrato[$c->id] ?? 0;

            $c->es_afil             = $esAfil;
            $c->es_ind_act_primer_mes = $esIndActPrimerMes; // I ACT: afiliación + planilla
            $c->v_eps            = $vEps;
            $c->v_arl            = $vArl;
            $c->v_pen            = $vPen;
            $c->v_caja           = $vCaja;
            $c->v_ss             = $vSS ?? 0;
            $c->total_estimado   = $totalEstimado;
            $c->mora_estimada    = $moraEst;
            $c->mora_dias        = $morasDiasPorContrato[$c->id] ?? 0;
            $c->fact_emitida     = $facturaEmitida;
            $c->fact_pagada      = $facturaPagada;
            $c->fact_estado      = $facturaEstado;
            $c->fact_numero      = $facturaNumero;
            $c->fact_id          = $facturaId;
            $c->fact_n_planilla  = $facturaNPlanilla;
            $c->fact_local_plano = $facturaLocalPlano;
            $c->fact_n_plano     = $facturaNPlano;
            $c->fact_saldo_pend  = $facturaSaldoPend;
            // ── Entidades para cuenta de cobro individual ─────────────
            $arlObj = $c->arl
                ?? ($c->razonSocial?->arl_nit ? ($arlPorNit->get($c->razonSocial->arl_nit) ?? null) : null);
            $c->eps_nombre      = $c->eps?->nombre         ?? 'Ninguna';
            $c->arl_nombre      = $arlObj?->nombre_arl     ?? $arlObj?->razon_social ?? 'Ninguna';
            // AFP/Pension: usar razon_social (limpiando prefijo "25-14_" y NIT final " - 900xxx")
            $pensionRaw = $c->pension?->razon_social ?? null;
            if ($pensionRaw) {
                $pensionRaw = preg_replace('/^\d+[-\d]*_/u', '', $pensionRaw);      // quita "25-14_"
                $pensionRaw = preg_replace('/\s*-\s*\d{6,}\s*$/u', '', $pensionRaw); // quita " - NIT"
                $pensionRaw = trim($pensionRaw);
            }
            $c->afp_nombre = $pensionRaw ?: 'Ninguna';
            $c->caja_nombre     = $c->caja?->nombre        ?? 'Ninguna';
            $c->plan_nombre     = $c->plan?->nombre        ?? 'Estándar';
            $c->tipo_mod_nombre = $c->tipoModalidad?->nombre ?? '—';
            $c->dias_cotizados  = $esAfil ? 0 : ($esIndActPrimerMes
                ? max(1, 30 - (int)($c->fecha_ingreso?->day ?? 1) + 1)
                : ($c->fecha_ingreso && (int)$c->fecha_ingreso->month === ($mes === 1 ? 12 : $mes - 1) && (int)$c->fecha_ingreso->year === ($mes === 1 ? $anio - 1 : $anio)
                    ? max(1, 30 - (int)$c->fecha_ingreso->day + 1)
                    : 30));
            $c->ultima_llamada   = $ultimaLlamada;
            $c->dias_sin_llamar  = $diasSinLlamar;
            $c->semaforo         = $semaforo;
            // Badge ligero: solo true/false usando el Set pre-cargado (O(1))
            $c->tiene_prestamo   = isset($cedulasConPrestamo[$c->cedula]);
            // Empresa vinculada: cod_empresa > 1 y existe en tabla empresas
            $empresa = $c->cliente?->empresa;
            $c->es_empresa    = $empresa && $empresa->id != 1;
            $c->nombre_empresa = $c->es_empresa ? $empresa->empresa : null;

            // ── Detección Plan Ingreso-Retiro: siempre cobra afiliación ──────────
            $esIngresoRetiro  = (int)($c->tipo_modalidad_id) === 12;
            $diasCotizEstim   = 30; // default: mes completo
            if ($esIngresoRetiro && !$esAfil && !$esIndActPrimerMes && $c->fecha_ingreso) {
                $fIng2        = $c->fecha_ingreso;
                $mesAnt       = $mes === 1 ? 12 : $mes - 1;
                $anioAnt      = $mes === 1 ? $anio - 1 : $anio;
                // Si ingresó el mes anterior → días = activos de ese mes
                if ((int)$fIng2->month === $mesAnt && (int)$fIng2->year === $anioAnt) {
                    $diasCotizEstim = max(1, 30 - $fIng2->day + 1);
                }
            }
            $c->es_ir_alerta     = $esIngresoRetiro && !$esAfil && !$esIndActPrimerMes;
            $c->dias_cotiz_estim = $diasCotizEstim;

            // ── Cuando debe rotar RS: el cobro es AFILIACIÓN del nuevo contrato ──
            if ($c->es_ir_alerta) {
                $c->total_estimado = (int)($c->costo_afiliacion ?? 0);
                $c->v_ss           = 0;
                $c->mora_estimada  = 0;
                $c->es_afil        = true; // mostrar badge AFIL en la columna
            }

            $c->es_retiro = false; // marcar explícitamente vigentes
            return $c;
        });

        // ── Ordenamiento en colección (campos calculados en PHP) ────
        if ($sort === 'n_planilla') {
            $contratos = $contratos->sort(function ($a, $b) use ($dir) {
                // 1. Agrupar afiliaciones juntas
                $aAfil = $a->es_afil ? 1 : 0;
                $bAfil = $b->es_afil ? 1 : 0;
                if ($aAfil !== $bAfil) {
                    return $dir === 'asc' ? ($bAfil <=> $aAfil) : ($aAfil <=> $bAfil);
                }
                
                // 2. Ordenar por planilla/plano
                $aVal = $a->fact_n_plano;
                $bVal = $b->fact_n_plano;
                
                if ($aVal === null && $bVal === null) return 0;
                if ($aVal === null) return 1;
                if ($bVal === null) return -1;
                
                $res = strnatcasecmp((string)$aVal, (string)$bVal);
                return $dir === 'asc' ? $res : -$res;
            })->values();
        }

        // ── Extraer opciones únicas de Empresa/Cliente para el filtro ──
        $opcionesEmpresaCliente = $contratos->map(function ($c) {
            return $c->es_empresa ? $c->nombre_empresa : 'Individual';
        })->unique()->filter()->values()->toArray();

        // ── Filtro estado de pago ───────────────────────────────────
        if ($soloPend === 'pendiente') {
            $contratos = $contratos->filter(function ($c) {
                // Retirados del periodo siempre están «pagados», nunca pendientes
                if ($c->es_retiro ?? false) return false;
                if ($c->fact_emitida) {
                    return in_array($c->fact_estado, ['pre_factura', 'abono']);
                }
                return true; // sin factura → pendiente
            })->values();
        } elseif ($soloPend === 'pagado') {
            $contratos = $contratos->filter(function ($c) {
                return (bool)($c->fact_pagada ?? false);
            })->values();
        }

        // ── Filtro TIPO (antes AFIL/PLAN, ahora incluye RETIRO) ────────────────────────────────────────
        if ($afilPlan && $afilPlan !== 'todos') {
            $contratos = $contratos->filter(function ($c) use ($afilPlan) {
                if ($afilPlan === 'retiro') {
                    return (bool)($c->es_retiro ?? false);
                } elseif ($afilPlan === 'afil') {
                    return !($c->es_retiro ?? false) && (bool)($c->es_afil ?? false);
                } elseif ($afilPlan === 'plan') {
                    return !($c->es_retiro ?? false) && !($c->es_afil ?? false);
                }
                return true;
            })->values();
        }

        // ── Filtro Empresa/Cliente ──────────────────────────────────
        $empresaCliente = $request->get('empresa_cliente');
        if ($empresaCliente && $empresaCliente !== 'todos') {
            $contratos = $contratos->filter(function ($c) use ($empresaCliente) {
                if ($empresaCliente === 'Individual') {
                    return !$c->es_empresa;
                } else {
                    return $c->es_empresa && $c->nombre_empresa === $empresaCliente;
                }
            })->values();
        }

        // ── Cards de resumen ────────────────────────────────────────
        $totalAdmon    = $contratos->sum(fn($c) => (int)($c->administracion ?? 0));
        $totalPendientes = $contratos->count();
        $sinLlamar     = $contratos->where('semaforo', 'gris')->count()
                       + $contratos->where('semaforo', 'rojo')->count();
        $prometieronPago = $contratos
            ->filter(fn($c) => $c->ultima_llamada?->resultado === 'promesa_pago')
            ->count();
        $totalSS         = $contratos->sum('v_ss');

        // ── Datos para filtros ──────────────────────────────────────
        $razonesDisponibles = DB::table('razones_sociales')
            ->whereIn('id', $rsIdsUsados)
            ->orderBy('razon_social')
            ->get(['id', 'razon_social']);

        $asesoresDisponibles = \App\Models\Asesor::where('aliado_id', $aliadoId)
            ->orderBy('nombre')
            ->get(['id', 'nombre']);

        $bancos       = BancoCuenta::paraFacturacion($aliadoId)->sortBy('nombre')->values();
        $cuentasCobro = BancoCuenta::paraCobro($aliadoId);

        // Modalidades disponibles pre-calculadas arriba según consulta

        return compact(
            'contratos', 'mes', 'anio',
            'totalAdmon', 'totalPendientes', 'sinLlamar', 'prometieronPago', 'totalSS',
            'razonesDisponibles', 'asesoresDisponibles',
            'rsId', 'asesorId', 'buscar', 'soloInd', 'soloPend', 'sort', 'dir',
            'bancos', 'cuentasCobro', 'afilPlan', 'empresaCliente', 'opcionesEmpresaCliente',
            'tipoModalFiltro', 'modalidadesDisponibles'
        );
    }

    // ─── Registrar llamada ───────────────────────────────────────────
    public function registrarLlamada(Request $request, int $contratoId)
    {
        $aliadoId = session('aliado_id_activo');

        $validated = $request->validate([
            'resultado'    => 'required|in:no_contesta,promesa_pago,pagado,numero_errado,otro',
            'observacion'  => 'nullable|string|max:1000',
            'factura_id'   => 'nullable|integer',
        ]);

        // Verificar que el contrato pertenece al aliado
        $contrato = Contrato::where('aliado_id', $aliadoId)->findOrFail($contratoId);

        $llamada = BitacoraCobro::create([
            'aliado_id'    => $aliadoId,
            'contrato_id'  => $contratoId,
            'factura_id'   => $validated['factura_id'] ?? null,
            'usuario_id'   => Auth::id(),
            'fecha_llamada'=> now(),
            'resultado'    => $validated['resultado'],
            'observacion'  => $validated['observacion'] ?? null,
        ]);

        return response()->json([
            'ok'           => true,
            'llamada_id'   => $llamada->id,
            'resultado'    => $llamada->resultado,
            'etiqueta'     => $llamada->etiqueta_resultado,
            'fecha'        => $llamada->fecha_llamada->format('d/m/Y H:i'),
            'usuario'      => Auth::user()->nombre ?? Auth::user()->name,
            'semaforo'     => 'verde', // acaba de llamar
            'dias'         => 0,
        ]);
    }

    // ─── Historial de llamadas ───────────────────────────────────────
    public function historialLlamadas(int $contratoId)
    {
        $aliadoId = session('aliado_id_activo');

        // Verificar que el contrato pertenece al aliado
        Contrato::where('aliado_id', $aliadoId)->findOrFail($contratoId);

        $llamadas = BitacoraCobro::where('contrato_id', $contratoId)
            ->where('aliado_id', $aliadoId)
            ->with('usuario')
            ->orderByDesc('fecha_llamada')
            ->get()
            ->map(fn($l) => [
                'id'          => $l->id,
                'fecha'       => $l->fecha_llamada->format('d/m/Y H:i'),
                'resultado'   => $l->resultado,
                'etiqueta'    => $l->etiqueta_resultado,
                'observacion' => $l->observacion,
                'usuario'     => $l->usuario?->nombre ?? $l->usuario?->name ?? '—',
                'dias'        => $l->dias,
            ]);

        return response()->json(['ok' => true, 'llamadas' => $llamadas]);
    }

    // ─── Vista EMPRESAS ──────────────────────────────────────────────
    public function empresas(Request $request)
    {
        $aliadoId = session('aliado_id_activo');
        $user     = Auth::user();
        $mes      = (int) $request->get('mes',  now()->month);
        $anio     = (int) $request->get('anio', now()->year);
        $buscar         = $request->get('buscar');
        $sort           = $request->get('sort', 'empresa');
        $dir            = $request->get('dir', 'asc') === 'desc' ? 'desc' : 'asc';
        $semaforoFiltro = $request->get('semaforo');
        $soloPlant      = $request->get('solo_plant');
        $soloPend       = $request->get('solo_pend');

        // ── Empresas: descubrir vía cod_empresa de los clientes del aliado ──
        // Más robusto que filtrar por aliado_id en empresas (puede estar mal tras migraciones)
        $empresaIdsClientes = DB::table('clientes')
            ->where('aliado_id', $aliadoId)
            ->where('cod_empresa', '>', 1)
            ->whereNotNull('cod_empresa')
            ->pluck('cod_empresa')
            ->unique()
            ->toArray();

        $q = Empresa::whereIn('id', $empresaIdsClientes)
            ->where('id', '!=', 1);

        $esAdmin = $user->hasRole('admin') || $user->hasRole('superadmin') || $user->hasRole('usuario');
        if (!$esAdmin) {
            $q->where('encargado_id', $user->id);
        }

        if ($buscar) {
            $q->whereSinTildes('empresa', $buscar);
        }

        $encargadoFiltro = $request->get('encargado_id');
        if ($encargadoFiltro && $esAdmin) {
            $q->where('encargado_id', $encargadoFiltro);
        }

        if (in_array($sort, ['empresa', 'contacto'])) {
            $q->orderBy($sort, $dir);
        }

        $empresas   = $q->get();
        $empresaIds = $empresas->pluck('id')->toArray();

        // ── Para cada empresa: obtener cédulas de sus clientes ──────
        $finMes = \Carbon\Carbon::create($anio, $mes, 1)->endOfMonth();

        // Subquery de cédulas por empresa (evita el límite 2100 params de SQL Server)
        $cedulasSubquery = DB::table('clientes')
            ->where('aliado_id', $aliadoId)
            ->whereIn('cod_empresa', $empresaIds)
            ->select('cedula', 'cod_empresa');

        // clientesPorEmpresa: agrupado en PHP (solo IDs de empresa, pocos)
        $clientesPorEmpresa = (clone $cedulasSubquery)->get()->groupBy('cod_empresa');

        // Contratos vigentes/activos usando subquery nativo
        $contratosActivos = Contrato::where('aliado_id', $aliadoId)
            ->whereIn('cedula', function ($sub) use ($aliadoId, $empresaIds) {
                $sub->from('clientes')
                    ->select('cedula')
                    ->where('aliado_id', $aliadoId)
                    ->whereIn('cod_empresa', $empresaIds);
            })
            ->whereIn('estado', ['vigente', 'activo'])
            // `cliente` y `plan` también: sin ellos, el cálculo de cotización de
            // más abajo los pedía uno por uno. En el aliado 7 eran 730 consultas
            // por el plan y 1.360 por el cliente —directas y a través de
            // IvaService— sobre 730 contratos.
            ->with(['tipoModalidad', 'razonSocial', 'cliente', 'plan'])
            ->get();

        // Cédulas reales de contratos activos (ya filtradas, número manejable por empresa)
        $cedulasActivas = $contratosActivos->pluck('cedula')->unique()->values()->toArray();

        // El IVA de cada cédula en dos consultas, como ya lo hace facturación:
        // resuelto por contrato son dos viajes más por cada uno.
        $ivaPorCedula = \App\Services\IvaService::mapaPorCedulas($aliadoId, $cedulasActivas);

        // Facturas del mes — subquery si hay muchas cédulas, array si son pocas
        $factQuery = Factura::where('aliado_id', $aliadoId)
            ->periodo($mes, $anio)
            ->whereIn('tipo', ['planilla', 'afiliacion'])
            ->whereNull('deleted_at');

        if (count($cedulasActivas) > 500) {
            $factQuery->whereIn('cedula', function ($sub) use ($aliadoId, $empresaIds) {
                $sub->from('clientes')->select('cedula')
                    ->where('aliado_id', $aliadoId)
                    ->whereIn('cod_empresa', $empresaIds);
            });
        } else {
            $factQuery->whereIn('cedula', $cedulasActivas);
        }

        $facturasMes = $factQuery->get()->keyBy(fn($f) => (string) $f->cedula);  // cast string: evita mismatch SQL Server

        // Última llamada por empresa (agrupado por empresa_id)
        $ultimasLlamadasEmp = BitacoraCobro::where('aliado_id', $aliadoId)
            ->whereIn('empresa_id', $empresaIds)
            ->whereNotNull('empresa_id')
            ->where('fecha_llamada', '>=', now()->subDays(30)) // solo últimos 30 días para semáforo
            ->orderByDesc('fecha_llamada')
            ->get(['empresa_id', 'fecha_llamada', 'resultado'])  // solo 3 columnas necesarias
            ->groupBy('empresa_id')
            ->map(fn($g) => $g->first());

        // ── Mora en lote para contratos de empresas (1 query BD) ────────
        // Pre-calcular SS estimado + mora para todos los contratos de empresa
        // antes del map(), evitando N×2 queries dentro del foreach por empresa.
        $cedulasPagadasEmp = $facturasMes
            ->filter(fn($f) => in_array($f->estado, ['pagada', 'abono', 'prestamo']))
            ->keys()
            ->flip()
            ->all(); // Set O(1) de cédulas pagadas

        $moraEmpLoteInput = [];
        foreach ($contratosActivos as $c) {
            if (isset($cedulasPagadasEmp[(string)$c->cedula])) continue; // ya pagó
            $rsNit   = $c->nitParaMora();
            $rsDiaH  = $c->diaHabilParaMora();
            // Calcular aportes exactos por entidad (evita estimación 28.5%)
            $cotiz   = $c->calcularCotizacion(30, $ivaPorCedula[$c->cedula] ?? null);
            $vSsCont = (int) ($cotiz['ss'] ?? 0);
            if ($rsNit && $vSsCont > 0) {
                $moraEmpLoteInput[] = [
                    '_contrato_id' => $c->id,
                    'rs_nit'       => $rsNit,
                    'rs_dia_habil' => $rsDiaH,
                    'total_ss'     => $vSsCont,
                    // Desglose por entidad para mora exacta (igual que módulo planos)
                    'eps'          => (int) ($cotiz['eps']  ?? 0),
                    'arl'          => (int) ($cotiz['arl']  ?? 0),
                    'pen'          => (int) ($cotiz['pen']  ?? 0),
                    'caja'         => (int) ($cotiz['caja'] ?? 0),
                    'mes'          => $mes,
                    'anio'         => $anio,
                ];
            }
        }

        $moraEmpLoteOutput = MoraClienteService::calcularLote($aliadoId, $moraEmpLoteInput);
        $moraEmpPorContrato = [];
        foreach ($moraEmpLoteOutput as $fila) {
            $moraEmpPorContrato[$fila['_contrato_id']] = (int)($fila['mora'] ?? 0);
        }

        // ── Procesar cada empresa ────────────────────────────────────
        // Los contratos se agrupan por cédula UNA vez. Antes cada empresa hacía
        // `$contratosActivos->whereIn('cedula', …)`, que recorre todos los
        // contratos del aliado: 359 empresas × 1.517 contratos en el aliado 7
        // eran 1,9 s de PHP puro, más de la mitad de lo que tardaba la página.
        $contratosPorCedula = $contratosActivos->groupBy(fn ($c) => (string) $c->cedula);

        $empresas = $empresas->map(function ($emp) use (
            $mes, $anio, $clientesPorEmpresa, $contratosPorCedula,
            $facturasMes, $ultimasLlamadasEmp, $cedulasPagadasEmp, $moraEmpPorContrato
        ) {
            $contrEmp = ($clientesPorEmpresa->get($emp->id)?->pluck('cedula') ?? collect())
                ->map(fn ($ced) => (string) $ced)
                ->unique()
                ->flatMap(fn ($ced) => $contratosPorCedula->get($ced, collect()));

            $cant = $contrEmp->count();

            // Calcular AFIL/PLAN para cada contrato de esta empresa
            $pagados = 0; $afil_pend = 0; $indep_pend = 0; $plan_pend = 0; $admon_pend = 0;

            foreach ($contrEmp as $c) {
                $fact = $facturasMes->get((string) $c->cedula);  // cast string: evita mismatch SQL Server
                $pagada = $fact && in_array($fact->estado, ['pagada', 'abono', 'prestamo']);

                // ¿Es afiliación?
                $esArl = (int)($c->tipo_modalidad_id) === 15;
                $esAfil = false;
                if ($esArl) {
                    // Gestión ARL siempre es cobro de afiliación, no planilla
                    $esAfil = true;
                } elseif ($c->fecha_ingreso) {
                    $fIng    = $c->fecha_ingreso;
                    $esIndep = $c->tipoModalidad?->esIndependiente() ?? false;
                    if ((int)$fIng->month === $mes && (int)$fIng->year === $anio) {
                        if (!$esIndep || !($c->cobrar_planilla_primer_mes ?? false)) {
                            $esAfil = true;
                        }
                    }
                }

                $esIndep = $c->tipoModalidad?->esIndependiente() ?? false;

                if ($pagada) {
                    $pagados++;
                } elseif ($esAfil) {
                    $afil_pend++;
                    // Para afiliación (AFIL) no se cobra la administración
                } elseif ($esArl) {
                    // Si es ARL y no es su mes de cobro, no se cobra nada (no se paga planilla)
                } elseif ($esIndep) {
                    $indep_pend++;
                    $admon_pend += (int)($c->administracion ?? 0);
                } else {
                    $plan_pend++;
                    $admon_pend += (int)($c->administracion ?? 0);
                }
            }

            $totalPend = $afil_pend + $indep_pend + $plan_pend;

            // Semáforo por empresa
            $ultimaLlamada = $ultimasLlamadasEmp->get($emp->id);
            $diasSinLlamar = $ultimaLlamada
                ? (int)$ultimaLlamada->fecha_llamada->diffInDays(now())
                : null;

            $semaforo = match(true) {
                $diasSinLlamar === null => 'gris',
                $diasSinLlamar < 3      => 'verde',
                $diasSinLlamar <= 7     => 'amarillo',
                default                 => 'rojo',
            };

            // ── Mora estimada empresa — suma desde el lote pre-calculado (O(1) por contrato)
            $moraEmpEst = 0;
            foreach ($contrEmp->all() as $cMora) {
                if (!isset($cedulasPagadasEmp[(string)$cMora->cedula])) {
                    $moraEmpEst += $moraEmpPorContrato[$cMora->id] ?? 0;
                }
            }

            $emp->cant          = $cant;
            $emp->pagados       = $pagados;
            $emp->afil_pend     = $afil_pend;
            $emp->indep_pend    = $indep_pend;
            $emp->plan_pend     = $plan_pend;
            $emp->total_pend    = $totalPend;
            $emp->admon_pend    = $admon_pend;
            $emp->mora_estimada = $moraEmpEst;
            $emp->ultima_llamada = $ultimaLlamada;
            $emp->dias_sin_llamar = $diasSinLlamar;
            $emp->semaforo      = $semaforo;

            return $emp;
        })->filter(fn($emp) => $emp->cant > 0)
          ->when($semaforoFiltro, fn($col) => $col->where('semaforo', $semaforoFiltro))
          ->when($soloPlant,      fn($col) => $col->where('plan_pend', '>', 0))
          ->when($soloPend,       fn($col) => $col->where('total_pend', '>', 0))
          ->values();

        // ── Usuarios para selector de encargado ─────────────────────
        $usuariosDisponibles = \App\Models\User::where('aliado_id', $aliadoId)
            ->orWhere(function ($q) use ($aliadoId) {
                $q->where('es_brynex', false)->whereHas('aliados', fn($s) => $s->where('aliados.id', $aliadoId));
            })
            ->orderBy('nombre')
            ->get(['id', 'nombre']);


        // Cards resumen
        $totalEmpresas   = $empresas->count();
        $totalContratos  = $empresas->sum('cant');
        $totalPagados    = $empresas->sum('pagados');
        $totalPendientes = $empresas->sum('total_pend');

        return view('admin.cobros.empresas', compact(
            'empresas', 'mes', 'anio', 'buscar', 'sort', 'dir',
            'totalEmpresas', 'totalContratos', 'totalPagados', 'totalPendientes',
            'usuariosDisponibles', 'encargadoFiltro', 'esAdmin', 'semaforoFiltro'
        ));
    }

    // ─── Registrar llamada a empresa ────────────────────────────────
    public function registrarLlamadaEmpresa(Request $request, int $empresaId)
    {
        $aliadoId = session('aliado_id_activo');

        $validated = $request->validate([
            'resultado'   => 'required|in:no_contesta,promesa_pago,pagado,numero_errado,otro',
            'observacion' => 'nullable|string|max:1000',
        ]);

        Empresa::where('aliado_id', $aliadoId)->findOrFail($empresaId);

        $llamada = BitacoraCobro::create([
            'aliado_id'      => $aliadoId,
            'contrato_id'    => 0, // no aplica para empresa
            'empresa_id'     => $empresaId,
            'usuario_id'     => Auth::id(),
            'fecha_llamada'  => now(),
            'resultado'      => $validated['resultado'],
            'observacion'    => $validated['observacion'] ?? null,
        ]);

        return response()->json([
            'ok'       => true,
            'llamada_id' => $llamada->id,
            'etiqueta' => $llamada->etiqueta_resultado,
            'fecha'    => $llamada->fecha_llamada->format('d/m/Y H:i'),
            'usuario'  => Auth::user()->nombre ?? Auth::user()->name,
            'semaforo' => 'verde',
        ]);
    }

    // ─── Historial de llamadas a empresa ────────────────────────────
    public function historialEmpresa(int $empresaId)
    {
        $aliadoId = session('aliado_id_activo');
        Empresa::where('aliado_id', $aliadoId)->findOrFail($empresaId);

        // La llamada a empresa se guarda con `empresa_id` (ver registrarLlamadaEmpresa):
        // `razon_social_id` no existe en bitacora_cobros, asi que el historial
        // reventaba con un 500 cada vez que alguien lo abria.
        $llamadas = BitacoraCobro::where('empresa_id', $empresaId)
            ->where('aliado_id', $aliadoId)
            ->with('usuario')
            ->orderByDesc('fecha_llamada')
            ->get()
            ->map(fn($l) => [
                'fecha'      => $l->fecha_llamada->format('d/m/Y H:i'),
                'resultado'  => $l->resultado,
                'etiqueta'   => $l->etiqueta_resultado,
                'observacion'=> $l->observacion,
                'usuario'    => $l->usuario?->nombre ?? $l->usuario?->name ?? '—',
            ]);

        return response()->json(['ok' => true, 'llamadas' => $llamadas]);
    }

    // ─── Asignar encargado a empresa ────────────────────────────────
    public function asignarEncargado(Request $request, int $empresaId)
    {
        $aliadoId = session('aliado_id_activo');
        $empresa = Empresa::where('aliado_id', $aliadoId)->findOrFail($empresaId);

        $validated = $request->validate([
            'encargado_id' => 'nullable|integer',
        ]);

        $empresa->update(['encargado_id' => $validated['encargado_id'] ?: null]);

        return response()->json(['ok' => true]);
    }

    /**
     * Devuelve la previsualización del mensaje de cobro configurado.
     */
    public function vistaPrevisualizarWhatsApp(Request $request)
    {
        $aliadoId = session('aliado_id_activo');
        $config = WhatsappConfig::paraAliado($aliadoId);

        // Si usa la cuenta global de Brynex, las plantillas están asociadas al aliado BryNex
        $effectiveAliadoId = $aliadoId;
        if ($config && $config->usa_cuenta_brynex) {
            $aliadoBrynex = \App\Models\Aliado::where('nombre', 'BryNex')->first();
            $effectiveAliadoId = $aliadoBrynex ? $aliadoBrynex->id : 1;
        }

        // Si el usuario elige una plantilla específica, usarla; si no, la del config
        $plantillaId = $request->get('plantilla_id');
        if ($plantillaId) {
            $plantilla = \App\Models\WhatsappPlantilla::delAliado($effectiveAliadoId)->find($plantillaId);
            if (!$plantilla) {
                return response()->json(['ok' => false, 'mensaje' => 'Plantilla no encontrada.'], 422);
            }
        } else {
            if (!$config || !$config->cobro_plantilla_id || !$config->cobroPlantilla) {
                return response()->json([
                    'ok'      => false,
                    'mensaje' => 'No has configurado la plantilla de WhatsApp para cobros. Ve a la configuración de WhatsApp para asignarla.',
                ], 422);
            }
            $plantilla = $config->cobroPlantilla;
        }

        // Cuentas de cobro
        $cuentasText = BancoCuenta::textoCobro($aliadoId);

        // Obtener el nombre del aliado
        $aliado = \App\Models\Aliado::find($aliadoId);
        $nombreAliado = $aliado ? $aliado->nombre : 'BryNex Global';

        // Parámetros de prueba
        if ($plantilla->usaMoldeCorto()) {
            $paramsPrueba = $plantilla->cantidadVariables() === 3
                ? ['Juan Pérez (PRUEBA)', $nombreAliado, '$150.000']
                : ['Juan Pérez (PRUEBA)', $nombreAliado];
        } else {
            $paramsPrueba = [
                'Juan Pérez (PRUEBA)',
                $nombreAliado,
                '10',
                $cuentasText,
                $config->numero_telefono ?: 'no tiene configurado',
                '$150.000',
            ];
        }

        $cuerpoPrevisualizado = $plantilla->cuerpo;
        foreach ($paramsPrueba as $i => $val) {
            $cuerpoPrevisualizado = str_replace('{{' . ($i + 1) . '}}', $val, $cuerpoPrevisualizado);
        }

        // Verificar si ya se envió hoy (para bloqueo del botón)
        $envioHoy = WhatsappEnvioMasivo::where('aliado_id', $aliadoId)
            ->whereDate('created_at', today())
            ->orderByDesc('created_at')
            ->first();

        // Conteo de destinatarios según filtro actual
        $data      = $this->obtenerDatosCobros($request);
        $contratos = $data['contratos'];

        // Excluir ya pagados
        $contratos = $contratos->filter(fn($c) => !($c->fact_pagada ?? false))->values();
        $cantClientes = $contratos->count();

        // Solo un celular colombiano de verdad. Lo demás («3», un fijo, dos
        // números en el mismo campo) cuenta como sin número: antes se mandaba
        // igual y Meta lo rechazaba (#100 Invalid parameter, 5-oct-2026).
        $normalizarCelular = fn (?string $raw): string =>
            \App\Services\WhatsappApiService::esCelularColombiano($raw)
                ? \App\Services\WhatsappApiService::normalizarNumero($raw)
                : '';

        // ── Encontrar contratos que ya fueron enviados hoy ──
        $lotesHoyIds = WhatsappEnvioMasivo::where('aliado_id', $aliadoId)
            ->whereDate('created_at', today())
            ->pluck('id');
        $detallesHoy = WhatsappEnvioMasivoDetalle::whereIn('envio_id', $lotesHoyIds)
            ->whereIn('estado', ['pendiente', 'enviado', 'entregado', 'leido'])
            ->get();
        $contratoIdsEnviadosSet = [];
        foreach ($detallesHoy as $det) {
            if ($det->contrato_id) {
                $contratoIdsEnviadosSet[$det->contrato_id] = true;
            }
            if ($det->contrato_ids_json) {
                $arr = json_decode($det->contrato_ids_json, true);
                if (is_array($arr)) {
                    foreach ($arr as $idC) {
                        $contratoIdsEnviadosSet[$idC] = true;
                    }
                }
            }
        }

        $contratosAfil     = $contratos->filter(fn($c) => ($c->es_afil ?? false))->values();
        $contratosPlanilla = $contratos->filter(fn($c) => !($c->es_afil ?? false))->values();

        $cantSoloUnContrato = 0;
        $cantVariosContratos = 0;
        $cantSinCelular = 0;
        $cantYaEnviadosHoy = 0;

        // Procesar afiliados
        foreach ($contratosAfil as $c) {
            if (isset($contratoIdsEnviadosSet[$c->id])) {
                $cantYaEnviadosHoy++;
                continue;
            }
            $num = $normalizarCelular($c->cliente?->celular);
            if (empty($num)) {
                $cantSinCelular++;
            } else {
                $cantSoloUnContrato++;
            }
        }

        // Agrupar planilla por número de celular
        $gruposPlanilla = [];
        foreach ($contratosPlanilla as $c) {
            $num = $normalizarCelular($c->cliente?->celular);
            $key = $num ?: 'sin_numero';

            if (!isset($gruposPlanilla[$key])) {
                $gruposPlanilla[$key] = [];
            }
            $gruposPlanilla[$key][] = $c;
        }

        foreach ($gruposPlanilla as $key => $grupoContratos) {
            $enviados = [];
            $noEnviados = [];
            foreach ($grupoContratos as $c) {
                if (isset($contratoIdsEnviadosSet[$c->id])) {
                    $enviados[] = $c;
                } else {
                    $noEnviados[] = $c;
                }
            }

            $cantYaEnviadosHoy += count($enviados);

            if (empty($noEnviados)) {
                continue;
            }

            if ($key === 'sin_numero') {
                $cantSinCelular += count($noEnviados);
            } else {
                if (count($noEnviados) === 1) {
                    $cantSoloUnContrato++;
                } else {
                    $cantVariosContratos++;
                }
            }
        }

        $totalEnviosValidos = $cantSoloUnContrato + $cantVariosContratos;
        $totalDestinatarios = $cantClientes;

        $previsualizacionesReales = [];
        $contratosValidosPreview = $contratos
            ->filter(fn($c) => !isset($contratoIdsEnviadosSet[$c->id]) && !empty($normalizarCelular($c->cliente?->celular)))
            ->take(30);

        // Día hábil configurado por el aliado: no depende de $c, se calcula una sola
        // vez fuera del loop en vez de repetir la misma consulta hasta 30 veces.
        $cfgAliado = DB::table('configuracion_aliado')
            ->where('aliado_id', $aliadoId)
            ->whereNull('plan_id')
            ->first(['mora_dia_habil_inicio']);
        $plazoDias = $cfgAliado?->mora_dia_habil_inicio ? (string)$cfgAliado->mora_dia_habil_inicio : '10';

        foreach ($contratosValidosPreview as $c) {
            $nombreCliente = $c->cliente?->nombre_corto ?? 'Cliente';
            $nombreAliadoEfectivo  = $config->nombre_cuenta ?: $nombreAliado;

            $celularSoporte = $config->numero_telefono ?: 'no tiene configurado';

            $valorCobro = (float)($c->total_estimado ?? 0) + (float)($c->mora_estimada ?? 0);
            $valorFormateado = '$' . number_format($valorCobro, 0, ',', '.');

            $cantVars = $plantilla->cantidadVariables();
            if ($plantilla->usaMoldeCorto()) {
                // Mismo molde que usa el envío real (WhatsappEnvioMasivoJob): si aquí se
                // arman los cinco del recordatorio de cobro, la vista previa muestra un
                // mensaje que no es el que va a salir.
                $params = $cantVars === 3
                    ? [$nombreCliente, $nombreAliadoEfectivo, $valorFormateado]
                    : [$nombreCliente, $nombreAliadoEfectivo];
            } else {
                $params = $cantVars <= 5
                    ? [$nombreCliente, $nombreAliadoEfectivo, $plazoDias, $cuentasText, $celularSoporte]
                    : [$nombreCliente, $nombreAliadoEfectivo, $plazoDias, $cuentasText, $celularSoporte, $valorFormateado];
            }

            $cuerpoReal = $plantilla->cuerpo;
            foreach ($params as $i => $val) {
                $cuerpoReal = str_replace('{{' . ($i + 1) . '}}', $val, $cuerpoReal);
            }

            $previsualizacionesReales[] = [
                'nombre' => $nombreCliente,
                'celular' => $normalizarCelular($c->cliente?->celular),
                'cuerpo' => $cuerpoReal,
                'valor'  => $valorFormateado
            ];
        }

        if (empty($previsualizacionesReales)) {
            $previsualizacionesReales[] = [
                'nombre' => 'Juan Pérez (PRUEBA)',
                'celular' => 'Prueba',
                'cuerpo' => $cuerpoPrevisualizado,
                'valor'  => '$150.000'
            ];
        }

        $plantillasDisponibles = \App\Models\WhatsappPlantilla::delAliado($effectiveAliadoId)->aprobadas()->get(['id', 'nombre_display', 'nombre']);

        return response()->json([
            'ok'             => true,
            'plantilla_id'   => $plantilla->id,
            'nombre_display' => $plantilla->nombre_display,
            'cuerpo'         => $cuerpoPrevisualizado,
            'footer'         => $plantilla->footer,
            'header_tipo'    => $plantilla->header_tipo,
            'header_imagen'  => $config->cobro_header_imagen ? asset('storage/' . $config->cobro_header_imagen) : null,
            'botones'        => $plantilla->botones,
            'cant_clientes'  => $cantClientes,
            'plantillas'     => $plantillasDisponibles,
            'resumen_envios' => [
                'solo_uno'            => $cantSoloUnContrato,
                'varios'              => $cantVariosContratos,
                'sin_celular'         => $cantSinCelular,
                'ya_enviados_hoy'     => $cantYaEnviadosHoy,
                'envios_validos'      => $totalEnviosValidos,
                'total_destinatarios' => $cantClientes,
            ],
            'envio_hoy'      => $envioHoy ? [
                'hora'    => $envioHoy->created_at->format('H:i'),
                'enviados'=> $envioHoy->total_enviados,
                'estado'  => $envioHoy->estado,
            ] : null,
            'previsualizaciones' => $previsualizacionesReales,
        ]);
    }

    /**
     * Envía una plantilla de prueba al número celular especificado.
     */
    public function enviarPruebaWhatsApp(Request $request)
    {
        $validated = $request->validate([
            'celular_prueba' => 'required|string',
            'plantilla_id'   => 'nullable|integer',
        ]);

        \Illuminate\Support\Facades\Log::info('WhatsApp Prueba: recibiendo petición', [
            'request_all' => $request->all(),
            'validated'   => $validated,
        ]);

        $aliadoId = session('aliado_id_activo');
        $config   = WhatsappConfig::paraAliado($aliadoId);

        if (!$config->credencialesCompletas()) {
            return response()->json(['ok' => false, 'mensaje' => 'Las credenciales de WhatsApp del aliado están incompletas.'], 422);
        }

        // Si usa la cuenta global de Brynex, las plantillas están asociadas al aliado BryNex
        $effectiveAliadoId = $aliadoId;
        if ($config && $config->usa_cuenta_brynex) {
            $aliadoBrynex = \App\Models\Aliado::where('nombre', 'BryNex')->first();
            $effectiveAliadoId = $aliadoBrynex ? $aliadoBrynex->id : 1;
        }

        // Usar la plantilla seleccionada o la por defecto de la configuración
        $plantillaId = $request->get('plantilla_id');
        if ($plantillaId) {
            $plantilla = \App\Models\WhatsappPlantilla::delAliado($effectiveAliadoId)->find($plantillaId);
            if (!$plantilla) {
                return response()->json(['ok' => false, 'mensaje' => 'Plantilla de prueba no encontrada.'], 422);
            }
        } else {
            if (!$config || !$config->cobro_plantilla_id || !$config->cobroPlantilla) {
                return response()->json(['ok' => false, 'mensaje' => 'Plantilla de cobros no configurada.'], 422);
            }
            $plantilla = $config->cobroPlantilla;
        }

        $cuentasText = BancoCuenta::textoCobro($aliadoId);

        $aliado = \App\Models\Aliado::find($aliadoId);
        $nombreAliado = $aliado ? $aliado->nombre : 'BryNex Global';

        // Obtener el día hábil configurado por el aliado
        $cfgAliado = DB::table('configuracion_aliado')
            ->where('aliado_id', $aliadoId)
            ->whereNull('plan_id')
            ->first(['mora_dia_habil_inicio']);
        $plazoDias = $cfgAliado?->mora_dia_habil_inicio ? (string)$cfgAliado->mora_dia_habil_inicio : '10';

        $cantVars = $plantilla->cantidadVariables();
        if ($plantilla->usaMoldeCorto()) {
            $params = $cantVars === 3
                ? ['Juan Pérez (PRUEBA)', $nombreAliado, '$150.000']
                : ['Juan Pérez (PRUEBA)', $nombreAliado];
        } else {
            $params   = $cantVars <= 5
                ? ['Juan Pérez (PRUEBA)', $nombreAliado, $plazoDias, $cuentasText, $config->numero_telefono ?: 'no tiene configurado']
                : ['Juan Pérez (PRUEBA)', $nombreAliado, $plazoDias, $cuentasText, $config->numero_telefono ?: 'no tiene configurado', '$150.000'];
        }

        $apiService = app(\App\Services\WhatsappApiService::class);

        // URL pública de la imagen del header (si la plantilla de cobros tiene header IMAGE)
        $headerImageUrl = $config->cobro_header_imagen
            ? asset('storage/' . $config->cobro_header_imagen)
            : null;

        $res = $apiService->enviarTemplate(
            $validated['celular_prueba'],
            $plantilla,
            $params,
            $config,
            $headerImageUrl
        );

        if ($res['ok']) {
            // Normalizar el número para el registro de la conversación
            $numeroNormalizado = preg_replace('/[^0-9]/', '', $validated['celular_prueba']);
            if (strlen($numeroNormalizado) === 10) {
                $numeroNormalizado = '57' . $numeroNormalizado;
            }

            // Obtener o crear conversación para que el webhook de respuesta sepa a qué aliado dirigirla
            $conversacion = \App\Models\WhatsappConversacion::firstOrCreate(
                [
                    'aliado_id'     => $aliadoId,
                    'wa_contact_id' => $numeroNormalizado,
                ],
                [
                    'nombre_contacto' => 'Contacto de Prueba',
                    'estado'          => 'abierta',
                ]
            );

            if ($conversacion->estado === 'cerrada') {
                $conversacion->update(['estado' => 'abierta']);
            }

            // Guardar el mensaje de prueba saliente
            \App\Models\WhatsappMensaje::create([
                'conversacion_id'      => $conversacion->id,
                'aliado_id'            => $aliadoId,
                'wa_message_id'        => $res['wa_message_id'] ?? 'prueba_' . uniqid(),
                'direccion'            => 'saliente',
                'tipo'                 => 'template',
                'plantilla_id'         => $plantilla->id,
                'plantilla_parametros' => $params,
                'estado'               => 'enviado',
                'usuario_id'           => Auth::id(),
            ]);

            $conversacion->update(['ultimo_mensaje_at' => now()]);

            return response()->json(['ok' => true, 'mensaje' => '¡Mensaje de prueba enviado correctamente!']);
        }

        return response()->json(['ok' => false, 'mensaje' => 'Error al enviar a Meta: ' . ($res['error'] ?? 'Desconocido')], 422);
    }

    /**
     * Confirma y despacha el envío masivo de cobros a todos los clientes del filtro.
     *
     * Reglas:
     *  - 1 envío masivo por aliado por día → bloquea si ya existe uno hoy.
     *  - Contratos de PLANILLA del mismo número → 1 solo detalle con valor sumado.
     *  - Contratos de AFILIACIÓN → 1 detalle separado por contrato.
     *  - Excluye clientes con factura pagada del mes.
     *  - Clientes sin celular → detalle en estado 'fallido'.
     */
    public function enviarFiltroWhatsApp(Request $request)
    {
        $aliadoId = session('aliado_id_activo');
        $config   = WhatsappConfig::paraAliado($aliadoId);

        // Si usa la cuenta global de Brynex, las plantillas están asociadas al aliado BryNex
        $effectiveAliadoId = $aliadoId;
        if ($config && $config->usa_cuenta_brynex) {
            $aliadoBrynex = \App\Models\Aliado::where('nombre', 'BryNex')->first();
            $effectiveAliadoId = $aliadoBrynex ? $aliadoBrynex->id : 1;
        }

        // Plantilla: usar la solicitada o la configurada por defecto
        $plantillaId = $request->get('plantilla_id');
        if ($plantillaId) {
            $plantilla = \App\Models\WhatsappPlantilla::delAliado($effectiveAliadoId)->find($plantillaId);
            if (!$plantilla) {
                return response()->json(['ok' => false, 'mensaje' => 'Plantilla no encontrada.'], 422);
            }
        } else {
            if (!$config || !$config->cobro_plantilla_id || !$config->cobroPlantilla) {
                return response()->json(['ok' => false, 'mensaje' => 'Plantilla de cobros no configurada.'], 422);
            }
            $plantilla = $config->cobroPlantilla;
        }

        if (!$config || !$config->credencialesCompletas()) {
            return response()->json(['ok' => false, 'mensaje' => 'Las credenciales de WhatsApp del aliado están incompletas.'], 422);
        }

        // ── Encontrar contratos que ya fueron enviados hoy ──
        $lotesHoyIds = WhatsappEnvioMasivo::where('aliado_id', $aliadoId)
            ->whereDate('created_at', today())
            ->pluck('id');
        $detallesHoy = WhatsappEnvioMasivoDetalle::whereIn('envio_id', $lotesHoyIds)
            ->whereIn('estado', ['pendiente', 'enviado', 'entregado', 'leido'])
            ->get();
        $contratoIdsEnviadosSet = [];
        foreach ($detallesHoy as $det) {
            if ($det->contrato_id) {
                $contratoIdsEnviadosSet[$det->contrato_id] = true;
            }
            if ($det->contrato_ids_json) {
                $arr = json_decode($det->contrato_ids_json, true);
                if (is_array($arr)) {
                    foreach ($arr as $idC) {
                        $contratoIdsEnviadosSet[$idC] = true;
                    }
                }
            }
        }

        // ── Obtener contratos del filtro actual ──────────────────────────
        $data      = $this->obtenerDatosCobros($request);
        $contratos = $data['contratos'];


        // ── Excluir ya pagados ───────────────────────────────────────────
        $contratos = $contratos->filter(fn($c) => !($c->fact_pagada ?? false))->values();

        if ($contratos->isEmpty()) {
            return response()->json(['ok' => false, 'mensaje' => 'No hay clientes pendientes de pago para enviar cobros.'], 422);
        }

        // ── Cuentas bancarias para mensajes ─────────────────────────────
        $cuentasCobro = BancoCuenta::paraCobro($aliadoId);
        $cuentasText  = $cuentasCobro->map(fn($bc) => "*{$bc->nombre}:* {$bc->numero_cuenta}")->join("\n");
        if (empty($cuentasText)) $cuentasText = 'Ahorros Bancolombia: 123-456789-01';

        $nombreAliado   = $config->nombre_cuenta ?: 'BryNex';
        $celularSoporte = $config->numero_telefono ?: '3001234567';

        // ── Normalizar número de celular ─────────────────────────────────
        // Solo un celular colombiano de verdad. Lo demás («3», un fijo, dos
        // números en el mismo campo) cuenta como sin número: antes se mandaba
        // igual y Meta lo rechazaba (#100 Invalid parameter, 5-oct-2026).
        $normalizarCelular = fn (?string $raw): string =>
            \App\Services\WhatsappApiService::esCelularColombiano($raw)
                ? \App\Services\WhatsappApiService::normalizarNumero($raw)
                : '';

        // ── Separar contratos en AFILIACIÓN vs PLANILLA ──────────────────
        $contratosAfil     = $contratos->filter(fn($c) => ($c->es_afil ?? false))->values();
        $contratosPlanilla = $contratos->filter(fn($c) => !($c->es_afil ?? false))->values();

        // ── Agrupar contratos de planilla por número de celular ──────────
        $gruposPlanilla = [];
        foreach ($contratosPlanilla as $c) {
            $num = $normalizarCelular($c->cliente?->celular);
            $key = $num ?: 'sin_numero_' . $c->id;

            if (!isset($gruposPlanilla[$key])) {
                $gruposPlanilla[$key] = [
                    'wa_numero'    => $num,
                    'nombre'       => $c->cliente?->nombre_corto ?? 'Cliente',
                    'contrato_id'  => $c->id,
                    'contratos'    => [],
                ];
            }
            $gruposPlanilla[$key]['contratos'][] = $c;
        }

        // ── Calcular total real de destinatarios ─────────────────────────
        $totalDestinatarios = count($gruposPlanilla) + $contratosAfil->count();

        // ── Crear cabecera del lote ──────────────────────────────────────
        $envio = WhatsappEnvioMasivo::create([
            'aliado_id'           => $aliadoId,
            'plantilla_id'        => $plantilla->id,
            'usuario_id'          => Auth::id(),
            'mes'                 => $data['mes'] ?? now()->month,
            'anio'                => $data['anio'] ?? now()->year,
            'tipo_envio'          => 'individual',
            'total_destinatarios' => $totalDestinatarios,
            'estado'              => 'pendiente',
        ]);

        $destinatariosProgramados = 0;
        $destinatariosOmitidos = 0;

        // ── Crear detalles para grupos de planilla ───────────────────────
        foreach ($gruposPlanilla as $grupo) {
            $sinNumero = empty($grupo['wa_numero']);

            // Clasificar contratos del grupo en enviados vs no enviados hoy
            $enviados = [];
            $noEnviados = [];
            foreach ($grupo['contratos'] as $c) {
                if (isset($contratoIdsEnviadosSet[$c->id])) {
                    $enviados[] = $c;
                } else {
                    $noEnviados[] = $c;
                }
            }

            $totalGrupoValor = 0.0;
            foreach ($grupo['contratos'] as $c) {
                $totalGrupoValor += (float)($c->total_estimado ?? 0) + (float)($c->mora_estimada ?? 0);
            }

            if (count($enviados) === count($grupo['contratos'])) {
                // Todos ya fueron enviados hoy en este grupo
                $idsJson = count($grupo['contratos']) > 1 ? json_encode(array_column($grupo['contratos'], 'id')) : null;
                WhatsappEnvioMasivoDetalle::create([
                    'envio_id'           => $envio->id,
                    'contrato_id'        => $grupo['contrato_id'],
                    'wa_numero'          => $sinNumero ? 'sin_numero' : $grupo['wa_numero'],
                    'nombre_destinatario'=> $grupo['nombre'],
                    'valor_cobro'        => $totalGrupoValor,
                    'contrato_ids_json'  => $idsJson,
                    'estado'             => 'omitido',
                    'error'              => 'Ya enviado hoy',
                ]);
                $destinatariosOmitidos++;
                continue;
            }

            // Ajustar valor y contratos para enviar solo los no enviados hoy
            $valorAjustado = 0.0;
            $idsNoEnviados = [];
            foreach ($noEnviados as $c) {
                $valorAjustado += (float)($c->total_estimado ?? 0) + (float)($c->mora_estimada ?? 0);
                $idsNoEnviados[] = $c->id;
            }

            $idsJson = count($idsNoEnviados) > 1 ? json_encode($idsNoEnviados) : null;

            WhatsappEnvioMasivoDetalle::create([
                'envio_id'           => $envio->id,
                'contrato_id'        => $grupo['contrato_id'],
                'wa_numero'          => $sinNumero ? 'sin_numero' : $grupo['wa_numero'],
                'nombre_destinatario'=> $grupo['nombre'],
                'valor_cobro'        => $valorAjustado,
                'contrato_ids_json'  => $idsJson,
                'estado'             => $sinNumero ? 'fallido' : 'pendiente',
                'error'              => $sinNumero ? 'Número celular vacío o inválido' : null,
            ]);

            if (!$sinNumero) $destinatariosProgramados++;

            foreach ($idsNoEnviados as $cId) {
                $cObj = $contratosPlanilla->firstWhere('id', $cId);
                BitacoraCobro::create([
                    'aliado_id'     => $aliadoId,
                    'contrato_id'   => $cId,
                    'factura_id'    => $cObj?->fact_id ?? null,
                    'usuario_id'    => Auth::id(),
                    'fecha_llamada' => now(),
                    'resultado'     => 'whatsapp',
                    'observacion'   => 'WhatsApp de cobro programado (envío masivo)',
                ]);
            }
        }

        // ── Crear detalles para afiliaciones (uno por contrato) ──────────
        foreach ($contratosAfil as $c) {
            $num       = $normalizarCelular($c->cliente?->celular);
            $sinNumero = empty($num);
            $valor     = (float)($c->total_estimado ?? 0) + (float)($c->mora_estimada ?? 0);

            if (isset($contratoIdsEnviadosSet[$c->id])) {
                WhatsappEnvioMasivoDetalle::create([
                    'envio_id'           => $envio->id,
                    'contrato_id'        => $c->id,
                    'wa_numero'          => $sinNumero ? 'sin_numero' : $num,
                    'nombre_destinatario'=> $c->cliente?->nombre_corto ?? 'Cliente',
                    'valor_cobro'        => $valor,
                    'contrato_ids_json'  => null,
                    'estado'             => 'omitido',
                    'error'              => 'Ya enviado hoy',
                ]);
                $destinatariosOmitidos++;
                continue;
            }

            WhatsappEnvioMasivoDetalle::create([
                'envio_id'           => $envio->id,
                'contrato_id'        => $c->id,
                'wa_numero'          => $sinNumero ? 'sin_numero' : $num,
                'nombre_destinatario'=> $c->cliente?->nombre_corto ?? 'Cliente',
                'valor_cobro'        => $valor,
                'contrato_ids_json'  => null,
                'estado'             => $sinNumero ? 'fallido' : 'pendiente',
                'error'              => $sinNumero ? 'Número celular vacío o inválido' : null,
            ]);

            if (!$sinNumero) $destinatariosProgramados++;

            BitacoraCobro::create([
                'aliado_id'     => $aliadoId,
                'contrato_id'   => $c->id,
                'factura_id'    => $c->fact_id ?? null,
                'usuario_id'    => Auth::id(),
                'fecha_llamada' => now(),
                'resultado'     => 'whatsapp',
                'observacion'   => 'WhatsApp de afiliación programado (envío masivo)',
            ]);
        }

        if ($destinatariosProgramados === 0) {
            $envio->update([
                'estado'         => 'fallido',
                'total_omitidos' => $destinatariosOmitidos,
            ]);
            return response()->json([
                'ok' => false,
                'mensaje' => 'No hay nuevos destinatarios pendientes de envío en este filtro para hoy (todos ya fueron enviados o no tienen celular válido).'
            ], 422);
        }

        $envio->update([
            'total_omitidos' => $destinatariosOmitidos,
        ]);

        // ── Obtener la URL de la imagen del header desde la petición HTTP actual
        $headerImageUrl = null;
        if ($config->cobro_header_imagen) {
            $headerImageUrl = url('storage/' . $config->cobro_header_imagen);
        }

        // ── Despachar Job asíncrono ───────────────────────────────────────
        dispatch(new \App\Jobs\WhatsappEnvioMasivoJob($envio->id, [], $headerImageUrl));

        return response()->json([
            'ok'      => true,
            'mensaje' => "Se ha programado el envío masivo a {$destinatariosProgramados} clientes en segundo plano.",
            'lote_id' => $envio->id,
        ]);
    }

    /**
     * Retorna los lotes de envío masivo del mes actual para el aliado.
     * Se usa para mostrar el historial en el modal de cobros.
     */
    public function historialEnviosWhatsApp(Request $request)
    {
        $aliadoId = session('aliado_id_activo');
        $mes      = (int) $request->get('mes',  now()->month);
        $anio     = (int) $request->get('anio', now()->year);

        $lotes = WhatsappEnvioMasivo::where('aliado_id', $aliadoId)
            ->where('mes', $mes)
            ->where('anio', $anio)
            ->with('usuario:id,nombre')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($l) => [
                'id'                  => $l->id,
                'fecha'               => $l->created_at->format('d/m/Y H:i'),
                'estado'              => $l->estado,
                'etiqueta'            => $l->etiquetaEstado(),
                'total_destinatarios' => $l->total_destinatarios,
                'total_enviados'      => $l->total_enviados,
                'total_fallidos'      => $l->total_fallidos,
                'total_omitidos'      => $l->total_omitidos,
                'usuario'             => $l->usuario?->nombre ?? '—',
                'es_hoy'              => $l->created_at->isToday(),
            ]);

        // Verificar bloqueo diario
        $envioHoy = $lotes->firstWhere('es_hoy', true);

        return response()->json([
            'ok'        => true,
            'lotes'     => $lotes,
            'envio_hoy' => $envioHoy,
        ]);
    }

    /**
     * Retorna el detalle completo de un lote de envío con estado de cada destinatario.
     */
    public function reporteLoteWhatsApp(Request $request, int $loteId)
    {
        $aliadoId = session('aliado_id_activo');

        $lote = WhatsappEnvioMasivo::where('aliado_id', $aliadoId)
            ->with(['plantilla:id,nombre_display', 'usuario:id,nombre'])
            ->findOrFail($loteId);

        $detalles = WhatsappEnvioMasivoDetalle::where('envio_id', $loteId)
            ->get()
            ->map(function ($d) use ($aliadoId) {
                // Obtener estado del mensaje de WhatsApp (leído, entregado, etc.)
                $estadoMensaje = null;
                $conversacionId = null;
                if ($d->wa_message_id) {
                    $msg = WhatsappMensaje::where('wa_message_id', $d->wa_message_id)->first();
                    $estadoMensaje  = $msg?->estado;
                    $conversacionId = $msg?->conversacion_id;
                }

                return [
                    'id'             => $d->id,
                    'nombre'         => $d->nombre_destinatario,
                    'wa_numero'      => $d->wa_numero !== 'sin_numero' ? $d->wa_numero : null,
                    'valor_cobro'    => $d->valor_cobro ? '$' . number_format($d->valor_cobro, 0, ',', '.') : null,
                    'estado'         => $d->estado,
                    'error'          => $d->error,
                    'estado_wa'      => $estadoMensaje, // enviado, entregado, leido, fallido
                    'conversacion_id'=> $conversacionId,
                    'wa_message_id'  => $d->wa_message_id,
                ];
            });

        return response()->json([
            'ok'    => true,
            'lote'  => [
                'id'                  => $lote->id,
                'fecha'               => $lote->created_at->format('d/m/Y H:i'),
                'estado'              => $lote->estado,
                'etiqueta'            => $lote->etiquetaEstado(),
                'plantilla'           => $lote->plantilla?->nombre_display ?? '—',
                'usuario'             => $lote->usuario?->nombre ?? '—',
                'total_destinatarios' => $lote->total_destinatarios,
                'total_enviados'      => $lote->total_enviados,
                'total_fallidos'      => $lote->total_fallidos,
            ],
            'detalles' => $detalles,
        ]);
    }

    /**
     * Reintenta el envío de los destinatarios fallidos de un lote.
     */
    public function reintentarLoteWhatsApp(Request $request, int $loteId)
    {
        $aliadoId = session('aliado_id_activo');

        $lote = WhatsappEnvioMasivo::where('aliado_id', $aliadoId)
            ->findOrFail($loteId);

        // La plantilla del lote pudo retirarse del catálogo después del envío
        // (la relación la trae con withTrashed para conservar el histórico). Hay que
        // frenar ANTES de tocar los detalles: si no, se resetean los fallidos a
        // pendiente y el job muere sin plantilla, dejando el lote en 'procesando'.
        if (!$lote->plantilla || $lote->plantilla->trashed()) {
            return response()->json([
                'ok'      => false,
                'mensaje' => 'La plantilla de este lote ya no está disponible. Cree un envío nuevo con una plantilla vigente.',
            ], 422);
        }

        // Solo reintentar si hay detalles fallidos
        $fallidos = WhatsappEnvioMasivoDetalle::where('envio_id', $loteId)
            ->where('estado', 'fallido')
            ->count();

        if ($fallidos === 0) {
            return response()->json(['ok' => false, 'mensaje' => 'No hay destinatarios fallidos en este lote.'], 422);
        }

        // Resetear fallidos a pendiente para que el job los procese
        WhatsappEnvioMasivoDetalle::where('envio_id', $loteId)
            ->where('estado', 'fallido')
            ->whereNotNull('wa_numero')
            ->where('wa_numero', '!=', 'sin_numero')
            ->update(['estado' => 'pendiente', 'error' => null]);

        $config = WhatsappConfig::paraAliado($lote->aliado_id);
        $headerImageUrl = null;
        if ($config && $config->cobro_header_imagen) {
            $headerImageUrl = url('storage/' . $config->cobro_header_imagen);
        }

        dispatch(new \App\Jobs\WhatsappEnvioMasivoJob($lote->id, [], $headerImageUrl));

        return response()->json([
            'ok'      => true,
            'mensaje' => "Se reintentará el envío a {$fallidos} destinatarios fallidos en segundo plano.",
        ]);
    }

    /**
     * Obtiene el listado de empresas con cobros pendientes y datos necesarios.
     */
    private function obtenerEmpresasConDatosCobro(Request $request): array
    {
        $aliadoId = session('aliado_id_activo');
        $user     = Auth::user();
        $mes      = (int) $request->get('mes',  now()->month);
        $anio     = (int) $request->get('anio', now()->year);
        $buscar         = $request->get('buscar');
        $semaforoFiltro = $request->get('semaforo');
        $soloPlant      = $request->get('solo_plant');
        $soloPend       = $request->get('solo_pend');

        $empresaIdsClientes = DB::table('clientes')
            ->where('aliado_id', $aliadoId)
            ->where('cod_empresa', '>', 1)
            ->whereNotNull('cod_empresa')
            ->pluck('cod_empresa')
            ->unique()
            ->toArray();

        $q = Empresa::whereIn('id', $empresaIdsClientes)
            ->where('id', '!=', 1);

        $esAdmin = $user->hasRole('admin') || $user->hasRole('superadmin') || $user->hasRole('usuario');
        if (!$esAdmin) {
            $q->where('encargado_id', $user->id);
        }

        if ($buscar) {
            $q->whereSinTildes('empresa', $buscar);
        }

        $encargadoFiltro = $request->get('encargado_id');
        if ($encargadoFiltro && $esAdmin) {
            $q->where('encargado_id', $encargadoFiltro);
        }

        $empresas = $q->get();
        $empresaIds = $empresas->pluck('id')->toArray();

        $clientesPorEmpresa = DB::table('clientes')
            ->where('aliado_id', $aliadoId)
            ->whereIn('cod_empresa', $empresaIds)
            ->select('cedula', 'cod_empresa')
            ->get()
            ->groupBy('cod_empresa');

        $contratosActivos = Contrato::where('aliado_id', $aliadoId)
            ->whereIn('cedula', function ($sub) use ($aliadoId, $empresaIds) {
                $sub->from('clientes')
                    ->select('cedula')
                    ->where('aliado_id', $aliadoId)
                    ->whereIn('cod_empresa', $empresaIds);
            })
            ->whereIn('estado', ['vigente', 'activo'])
            ->with(['tipoModalidad', 'razonSocial'])  // eager load para evitar N+1
            ->get();

        $cedulasActivas = $contratosActivos->pluck('cedula')->unique()->values()->toArray();

        $factQuery = Factura::where('aliado_id', $aliadoId)
            ->periodo($mes, $anio)
            ->whereIn('tipo', ['planilla', 'afiliacion'])
            ->whereNull('deleted_at');

        if (count($cedulasActivas) > 500) {
            $factQuery->whereIn('cedula', function ($sub) use ($aliadoId, $empresaIds) {
                $sub->from('clientes')->select('cedula')
                    ->where('aliado_id', $aliadoId)
                    ->whereIn('cod_empresa', $empresaIds);
            });
        } else {
            $factQuery->whereIn('cedula', $cedulasActivas);
        }

        $facturasMes = $factQuery->get()->keyBy(fn($f) => (string) $f->cedula);

        $ultimasLlamadasEmp = BitacoraCobro::where('aliado_id', $aliadoId)
            ->whereIn('empresa_id', $empresaIds)
            ->whereNotNull('empresa_id')
            ->where('fecha_llamada', '>=', now()->subDays(30))
            ->orderByDesc('fecha_llamada')
            ->get(['empresa_id', 'fecha_llamada', 'resultado'])
            ->groupBy('empresa_id')
            ->map(fn($g) => $g->first());

        $cedulasPagadasEmp = $facturasMes
            ->filter(fn($f) => in_array($f->estado, ['pagada', 'abono', 'prestamo']))
            ->keys()
            ->flip()
            ->all();

        $moraEmpLoteInput = [];
        foreach ($contratosActivos as $c) {
            if (isset($cedulasPagadasEmp[(string)$c->cedula])) continue;
            $rsNit   = $c->nitParaMora();
            $rsDiaH  = $c->diaHabilParaMora();
            // Calcular aportes exactos por entidad (evita estimación 28.5%)
            $cotiz   = $c->calcularCotizacion(30);
            $vSsCont = (int) ($cotiz['ss'] ?? 0);
            if ($rsNit && $vSsCont > 0) {
                $moraEmpLoteInput[] = [
                    '_contrato_id' => $c->id,
                    'rs_nit'       => $rsNit,
                    'rs_dia_habil' => $rsDiaH,
                    'total_ss'     => $vSsCont,
                    // Desglose por entidad para mora exacta (igual que módulo planos)
                    'eps'          => (int) ($cotiz['eps']  ?? 0),
                    'arl'          => (int) ($cotiz['arl']  ?? 0),
                    'pen'          => (int) ($cotiz['pen']  ?? 0),
                    'caja'         => (int) ($cotiz['caja'] ?? 0),
                    'mes'          => $mes,
                    'anio'         => $anio,
                ];
            }
        }

        $moraEmpLoteOutput = MoraClienteService::calcularLote($aliadoId, $moraEmpLoteInput);
        $moraEmpPorContrato = [];
        foreach ($moraEmpLoteOutput as $fila) {
            $moraEmpPorContrato[$fila['_contrato_id']] = (int)($fila['mora'] ?? 0);
        }

        $empresas = $empresas->map(function ($emp) use (
            $mes, $anio, $clientesPorEmpresa, $contratosActivos,
            $facturasMes, $ultimasLlamadasEmp, $cedulasPagadasEmp, $moraEmpPorContrato
        ) {
            $cedulas  = $clientesPorEmpresa->get($emp->id)?->pluck('cedula')->toArray() ?? [];
            $contrEmp = $contratosActivos->whereIn('cedula', $cedulas);
            $cant = $contrEmp->count();

            $pagados = 0; $afil_pend = 0; $indep_pend = 0; $plan_pend = 0; $admon_pend = 0;

            foreach ($contrEmp as $c) {
                $fact = $facturasMes->get((string) $c->cedula);
                $pagada = $fact && in_array($fact->estado, ['pagada', 'abono', 'prestamo']);

                $esArl = (int)($c->tipo_modalidad_id) === 15;
                $esAfil = false;
                if ($esArl) {
                    $esAfil = true;
                } elseif ($c->fecha_ingreso) {
                    $fIng    = $c->fecha_ingreso;
                    $esIndep = $c->tipoModalidad?->esIndependiente() ?? false;
                    if ((int)$fIng->month === $mes && (int)$fIng->year === $anio) {
                        if (!$esIndep || !($c->cobrar_planilla_primer_mes ?? false)) {
                            $esAfil = true;
                        }
                    }
                }

                $esIndep = $c->tipoModalidad?->esIndependiente() ?? false;

                if ($pagada) {
                    $pagados++;
                } elseif ($esAfil) {
                    $afil_pend++;
                } elseif ($esArl) {
                } elseif ($esIndep) {
                    $indep_pend++;
                    $admon_pend += (int)($c->administracion ?? 0);
                } else {
                    $plan_pend++;
                    $admon_pend += (int)($c->administracion ?? 0);
                }
            }

            $totalPend = $afil_pend + $indep_pend + $plan_pend;

            $ultimaLlamada = $ultimasLlamadasEmp->get($emp->id);
            $diasSinLlamar = $ultimaLlamada ? (int)$ultimaLlamada->fecha_llamada->diffInDays(now()) : null;

            $semaforo = match(true) {
                $diasSinLlamar === null => 'gris',
                $diasSinLlamar < 3      => 'verde',
                $diasSinLlamar <= 7     => 'amarillo',
                default                 => 'rojo',
            };

            $moraEmpEst = 0;
            foreach ($contrEmp->all() as $cMora) {
                if (!isset($cedulasPagadasEmp[(string)$cMora->cedula])) {
                    $moraEmpEst += $moraEmpPorContrato[$cMora->id] ?? 0;
                }
            }

            $emp->cant          = $cant;
            $emp->pagados       = $pagados;
            $emp->afil_pend     = $afil_pend;
            $emp->indep_pend    = $indep_pend;
            $emp->plan_pend     = $plan_pend;
            $emp->total_pend    = $totalPend;
            $emp->admon_pend    = $admon_pend;
            $emp->mora_estimada = $moraEmpEst;
            $emp->ultima_llamada = $ultimaLlamada;
            $emp->dias_sin_llamar = $diasSinLlamar;
            $emp->semaforo      = $semaforo;

            return $emp;
        })->filter(fn($emp) => $emp->cant > 0)
          ->when($semaforoFiltro, fn($col) => $col->where('semaforo', $semaforoFiltro))
          ->when($soloPlant,      fn($col) => $col->where('plan_pend', '>', 0))
          ->when($soloPend,       fn($col) => $col->where('total_pend', '>', 0))
          ->values();

        return [
            'empresas' => $empresas,
            'mes'      => $mes,
            'anio'     => $anio,
        ];
    }

    /**
     * Previsualiza los mensajes de WhatsApp para cobros masivos a empresas.
     */
    /**
     * Lo que se le anuncia a una empresa como valor a pagar: la administración de sus
     * contratos pendientes más la mora que ya corrieron.
     *
     * La mora se calcula en obtenerEmpresasConDatosCobro() y la tabla de cobros la
     * muestra en su propia columna, pero no entraba en el mensaje: al cliente le
     * llegaba un valor menor al que de verdad debía y pagaba de menos.
     */
    private function valorCobroEmpresa(object $empresa): float
    {
        return (float) ($empresa->admon_pend ?? 0) + (float) ($empresa->mora_estimada ?? 0);
    }

    public function previsualizarWhatsAppEmpresas(Request $request)
    {
        $aliadoId = session('aliado_id_activo');
        $config = WhatsappConfig::paraAliado($aliadoId);

        // Si usa la cuenta global de Brynex, las plantillas están asociadas al aliado BryNex
        $effectiveAliadoId = $aliadoId;
        if ($config && $config->usa_cuenta_brynex) {
            $aliadoBrynex = \App\Models\Aliado::where('nombre', 'BryNex')->first();
            $effectiveAliadoId = $aliadoBrynex ? $aliadoBrynex->id : 1;
        }

        $plantillaId = $request->get('plantilla_id');
        if ($plantillaId) {
            $plantilla = \App\Models\WhatsappPlantilla::delAliado($effectiveAliadoId)->find($plantillaId);
        } else {
            $plantilla = $config->cobroPlantilla;
        }

        if (!$plantilla) {
            return response()->json([
                'ok'      => false,
                'mensaje' => 'La plantilla seleccionada no existe o no está configurada.',
            ], 422);
        }

        $incluirValor = $request->get('incluir_valor', '1') === '1';

        // Cuentas de cobro
        $cuentasText = BancoCuenta::textoCobro($aliadoId);

        $aliado = \App\Models\Aliado::find($aliadoId);
        $nombreAliado = $aliado ? $aliado->nombre : 'BryNex Global';

        $dataResult = $this->obtenerEmpresasConDatosCobro($request);
        $empresas = $dataResult['empresas'];

        // Filtrar solo empresas con pendientes
        $empresas = $empresas->filter(fn($e) => $e->total_pend > 0)->values();

        // Solo un celular colombiano de verdad. Lo demás («3», un fijo, dos
        // números en el mismo campo) cuenta como sin número: antes se mandaba
        // igual y Meta lo rechazaba (#100 Invalid parameter, 5-oct-2026).
        $normalizarCelular = fn (?string $raw): string =>
            \App\Services\WhatsappApiService::esCelularColombiano($raw)
                ? \App\Services\WhatsappApiService::normalizarNumero($raw)
                : '';

        $lotesHoyIds = WhatsappEnvioMasivo::where('aliado_id', $aliadoId)
            ->whereDate('created_at', today())
            ->pluck('id');

        $detallesHoy = WhatsappEnvioMasivoDetalle::whereIn('envio_id', $lotesHoyIds)
            ->whereNotNull('empresa_id')
            ->whereIn('estado', ['pendiente', 'enviado', 'entregado', 'leido'])
            ->pluck('empresa_id')
            ->toArray();

        $empresaIdsEnviadosSet = array_flip($detallesHoy);

        $cantSoloUnContrato = 0;
        $cantSinCelular = 0;
        $cantYaEnviadosHoy = 0;

        foreach ($empresas as $e) {
            if (isset($empresaIdsEnviadosSet[$e->id])) {
                $cantYaEnviadosHoy++;
                continue;
            }
            $num = ($normalizarCelular($e->telefono) ?: $normalizarCelular($e->celular));
            if (empty($num)) {
                $cantSinCelular++;
            } else {
                $cantSoloUnContrato++;
            }
        }

        $totalEnviosValidos = $cantSoloUnContrato;
        $totalDestinatarios = $empresas->count();

        $previsualizacionesReales = [];
        $empresasValidasPreview = $empresas
            ->filter(fn($e) => !isset($empresaIdsEnviadosSet[$e->id]) && !empty(($normalizarCelular($e->telefono) ?: $normalizarCelular($e->celular))))
            ->take(30);

        // Día hábil configurado por el aliado: no depende de $e, se calcula una sola
        // vez fuera del loop en vez de repetir la misma consulta hasta 30 veces.
        $cfgAliado = DB::table('configuracion_aliado')
            ->where('aliado_id', $aliadoId)
            ->whereNull('plan_id')
            ->first(['mora_dia_habil_inicio']);
        $plazoDias = $cfgAliado?->mora_dia_habil_inicio ? (string)$cfgAliado->mora_dia_habil_inicio : '10';

        foreach ($empresasValidasPreview as $e) {
            $nombreContacto = $e->contacto ?: $e->empresa;
            $nombreAliadoEfectivo = $config->nombre_cuenta ?: $nombreAliado;

            $celularSoporte = $config->numero_telefono ?: 'no tiene configurado';

            $valorCobro = $this->valorCobroEmpresa($e);
            $valorFormateado = '$' . number_format($valorCobro, 0, ',', '.');

            $cantVars = $plantilla->cantidadVariables();
            if ($plantilla->usaMoldeCorto()) {
                $params = ($cantVars === 3 && $incluirValor)
                    ? [$nombreContacto, $nombreAliadoEfectivo, $valorFormateado]
                    : [$nombreContacto, $nombreAliadoEfectivo];
            } elseif ($cantVars <= 5 || !$incluirValor) {
                $params = [$nombreContacto, $nombreAliadoEfectivo, $plazoDias, $cuentasText, $celularSoporte];
            } else {
                $params = [$nombreContacto, $nombreAliadoEfectivo, $plazoDias, $cuentasText, $celularSoporte, $valorFormateado];
            }

            $cuerpoReal = $plantilla->cuerpo;
            foreach ($params as $i => $val) {
                $cuerpoReal = str_replace('{{' . ($i + 1) . '}}', $val, $cuerpoReal);
            }

            $previsualizacionesReales[] = [
                'nombre' => $e->empresa,
                'celular' => ($normalizarCelular($e->telefono) ?: $normalizarCelular($e->celular)),
                'cuerpo' => $cuerpoReal,
                'valor'  => $valorFormateado
            ];
        }

        if (empty($previsualizacionesReales)) {
            $paramsPrueba = [
                'Contacto Empresa (PRUEBA)',
                $config->nombre_cuenta ?: $nombreAliado,
                '10',
                $cuentasText,
                $config->numero_telefono ?: 'no tiene configurado',
                '$500.000'
            ];
            $cuerpoPrevisualizado = $plantilla->cuerpo;
            foreach ($paramsPrueba as $i => $val) {
                $cuerpoPrevisualizado = str_replace('{{' . ($i + 1) . '}}', $val, $cuerpoPrevisualizado);
            }

            $previsualizacionesReales[] = [
                'nombre' => 'Empresa de Prueba',
                'celular' => 'Prueba',
                'cuerpo' => $cuerpoPrevisualizado,
                'valor'  => '$500.000'
            ];
        }

        $envioHoy = WhatsappEnvioMasivo::where('aliado_id', $aliadoId)
            ->where('tipo_envio', 'empresa')
            ->whereDate('created_at', today())
            ->orderByDesc('created_at')
            ->first();

        $plantillasDisponibles = \App\Models\WhatsappPlantilla::delAliado($effectiveAliadoId)->aprobadas()->get(['id', 'nombre_display', 'nombre']);

        return response()->json([
            'ok'             => true,
            'plantilla_id'   => $plantilla->id,
            'nombre_display' => $plantilla->nombre_display,
            'cuerpo'         => $previsualizacionesReales[0]['cuerpo'],
            'footer'         => $plantilla->footer,
            'header_tipo'    => $plantilla->header_tipo,
            'header_imagen'  => $config->cobro_header_imagen ? asset('storage/' . $config->cobro_header_imagen) : null,
            'botones'        => $plantilla->botones,
            'cant_clientes'  => $totalDestinatarios,
            'plantillas'     => $plantillasDisponibles,
            'resumen_envios' => [
                'solo_uno'            => $cantSoloUnContrato,
                'varios'              => 0,
                'sin_celular'         => $cantSinCelular,
                'ya_enviados_hoy'     => $cantYaEnviadosHoy,
                'envios_validos'      => $totalEnviosValidos,
                'total_destinatarios' => $totalDestinatarios,
            ],
            'envio_hoy'      => $envioHoy ? [
                'hora'    => $envioHoy->created_at->format('H:i'),
                'enviados'=> $envioHoy->total_enviados,
                'estado'  => $envioHoy->estado,
            ] : null,
            'previsualizaciones' => $previsualizacionesReales,
        ]);
    }

    /**
     * Envía masivamente cobros a empresas vía WhatsApp.
     */
    public function enviarWhatsAppEmpresas(Request $request)
    {
        $aliadoId = session('aliado_id_activo');
        $config   = WhatsappConfig::paraAliado($aliadoId);

        // Si usa la cuenta global de Brynex, las plantillas están asociadas al aliado BryNex
        $effectiveAliadoId = $aliadoId;
        if ($config && $config->usa_cuenta_brynex) {
            $aliadoBrynex = \App\Models\Aliado::where('nombre', 'BryNex')->first();
            $effectiveAliadoId = $aliadoBrynex ? $aliadoBrynex->id : 1;
        }

        $plantillaId = $request->get('plantilla_id');
        if ($plantillaId) {
            $plantilla = \App\Models\WhatsappPlantilla::delAliado($effectiveAliadoId)->find($plantillaId);
        } else {
            $plantilla = $config->cobroPlantilla;
        }

        if (!$plantilla) {
            return response()->json(['ok' => false, 'mensaje' => 'Plantilla no encontrada.'], 422);
        }

        if (!$config->credencialesCompletas()) {
            return response()->json(['ok' => false, 'mensaje' => 'Las credenciales de WhatsApp del aliado están incompletas.'], 422);
        }

        $incluirValor = $request->get('incluir_valor', '1') === '1';

        $dataResult = $this->obtenerEmpresasConDatosCobro($request);
        $empresas = $dataResult['empresas'];

        $empresas = $empresas->filter(fn($e) => $e->total_pend > 0)->values();

        if ($empresas->isEmpty()) {
            return response()->json(['ok' => false, 'mensaje' => 'No hay empresas pendientes de pago para enviar cobros.'], 422);
        }

        $lotesHoyIds = WhatsappEnvioMasivo::where('aliado_id', $aliadoId)
            ->whereDate('created_at', today())
            ->pluck('id');

        $detallesHoy = WhatsappEnvioMasivoDetalle::whereIn('envio_id', $lotesHoyIds)
            ->whereNotNull('empresa_id')
            ->whereIn('estado', ['pendiente', 'enviado', 'entregado', 'leido'])
            ->pluck('empresa_id')
            ->toArray();

        $empresaIdsEnviadosSet = array_flip($detallesHoy);

        // Solo un celular colombiano de verdad. Lo demás («3», un fijo, dos
        // números en el mismo campo) cuenta como sin número: antes se mandaba
        // igual y Meta lo rechazaba (#100 Invalid parameter, 5-oct-2026).
        $normalizarCelular = fn (?string $raw): string =>
            \App\Services\WhatsappApiService::esCelularColombiano($raw)
                ? \App\Services\WhatsappApiService::normalizarNumero($raw)
                : '';

        $envio = WhatsappEnvioMasivo::create([
            'aliado_id'           => $aliadoId,
            'plantilla_id'        => $plantilla->id,
            'usuario_id'          => Auth::id(),
            'mes'                 => $dataResult['mes'] ?? now()->month,
            'anio'                => $dataResult['anio'] ?? now()->year,
            'tipo_envio'          => 'empresa',
            'total_destinatarios' => $empresas->count(),
            'estado'              => 'pendiente',
        ]);

        $destinatariosProgramados = 0;
        $destinatariosOmitidos = 0;

        foreach ($empresas as $e) {
            $num = ($normalizarCelular($e->telefono) ?: $normalizarCelular($e->celular));
            $sinNumero = empty($num);
            $valorCobro = $incluirValor ? $this->valorCobroEmpresa($e) : 0.0;

            if (isset($empresaIdsEnviadosSet[$e->id])) {
                WhatsappEnvioMasivoDetalle::create([
                    'envio_id'           => $envio->id,
                    'empresa_id'         => $e->id,
                    'wa_numero'          => $sinNumero ? 'sin_numero' : $num,
                    'nombre_destinatario'=> $e->empresa,
                    'valor_cobro'        => $valorCobro,
                    'estado'             => 'omitido',
                    'error'              => 'Ya enviado hoy',
                ]);
                $destinatariosOmitidos++;
                continue;
            }

            WhatsappEnvioMasivoDetalle::create([
                'envio_id'           => $envio->id,
                'empresa_id'         => $e->id,
                'wa_numero'          => $sinNumero ? 'sin_numero' : $num,
                'nombre_destinatario'=> $e->empresa,
                'valor_cobro'        => $valorCobro,
                'estado'             => $sinNumero ? 'fallido' : 'pendiente',
                'error'              => $sinNumero ? 'Número celular vacío o inválido' : null,
            ]);

            if (!$sinNumero) {
                $destinatariosProgramados++;
            }

            BitacoraCobro::create([
                'aliado_id'     => $aliadoId,
                'contrato_id'   => 0,
                'empresa_id'    => $e->id,
                'usuario_id'    => Auth::id(),
                'fecha_llamada' => now(),
                'resultado'     => 'whatsapp',
                'observacion'   => 'WhatsApp de cobro a empresa programado (envío masivo)',
            ]);
        }

        if ($destinatariosProgramados === 0) {
            $envio->update([
                'estado'         => 'fallido',
                'total_omitidos' => $destinatariosOmitidos,
            ]);
            return response()->json([
                'ok' => false,
                'mensaje' => 'No hay nuevas empresas pendientes de envío hoy.'
            ], 422);
        }

        $envio->update([
            'total_omitidos' => $destinatariosOmitidos,
        ]);

        $headerImageUrl = null;
        if ($config->cobro_header_imagen) {
            $headerImageUrl = url('storage/' . $config->cobro_header_imagen);
        }

        dispatch(new \App\Jobs\WhatsappEnvioMasivoJob($envio->id, [], $headerImageUrl));

        return response()->json([
            'ok'      => true,
            'mensaje' => "Se ha programado el envío masivo a {$destinatariosProgramados} empresas en segundo plano.",
            'lote_id' => $envio->id,
        ]);
    }
}


