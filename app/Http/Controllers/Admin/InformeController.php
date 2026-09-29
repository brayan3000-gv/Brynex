<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BancoCuenta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class InformeController extends Controller
{
    private function aliadoId(): int
    {
        return (int) session('aliado_id_activo');
    }

    private function checkAdmin(): void
    {
        if (! Auth::user()->can('informes.ver')) {
            abort(403, 'No tienes permiso para «Ver informes (Informes)».');
        }
    }

    /**
     * El estado financiero va aparte del resto de informes: un admin gestiona
     * la operación del aliado sin tener por qué ver ingresos, egresos,
     * utilidad ni saldos. Solo superadmin y contable, más el admin puntual al
     * que se le otorgue `informes.financiero` a mano.
     *
     * Las rutas ya lo exigen; esto es el segundo cerrojo, para que quitar el
     * middleware por descuido no abra la caja.
     */
    private function checkFinanciero(): void
    {
        if (! Auth::user()->can('informes.financiero')) {
            abort(403, 'No tienes permiso para «Ver el estado financiero (Informes)».');
        }
    }

    /** Corregir consignaciones y subir soportes desde el financiero. */
    private function checkFinancieroEscritura(): void
    {
        if (! Auth::user()->can('informes.financiero_editar')) {
            abort(403, 'No tienes permiso para «Corregir consignaciones y subir soportes desde el financiero (Informes)».');
        }
    }

    // ── HUB principal ─────────────────────────────────────────────────
    public function hub()
    {
        $this->checkAdmin();
        $aid = $this->aliadoId();

        $mes  = now()->month;
        $anio = now()->year;
        $mesAnterior  = $mes === 1 ? 12 : $mes - 1;
        $anioAnterior = $mes === 1 ? $anio - 1 : $anio;

        $retirosBaseQuery = DB::table('contratos AS c')
            ->where('c.aliado_id',$aid)
            ->where('c.estado','retirado')
            ->where(function ($q) use ($mes, $anio, $mesAnterior, $anioAnterior) {
                $q->where(function ($q1) use ($mes, $anio) {
                    $q1->where('c.paga_mes_actual', 1)
                       ->whereMonth('c.fecha_retiro', $mes)
                       ->whereYear('c.fecha_retiro', $anio);
                })->orWhere(function ($q2) use ($mesAnterior, $anioAnterior) {
                    $q2->where('c.paga_mes_actual', 0)
                    ->whereMonth('c.fecha_retiro', $mesAnterior)
                    ->whereYear('c.fecha_retiro', $anioAnterior);
                });
            });

        $retirosTotal = (clone $retirosBaseQuery)->count();
        $retirosNoRenovados = (clone $retirosBaseQuery)->whereNotExists(function ($query) use ($aid) {
            $query->select(DB::raw(1))
                ->from('contratos AS c2')
                ->whereColumn('c2.cedula', 'c.cedula')
                ->where('c2.aliado_id', $aid)
                ->where('c2.estado', 'vigente');
        })->count();

        $afiliacionesBaseQuery = DB::table('contratos AS c')
            ->where('c.aliado_id',$aid)
            ->whereMonth('c.fecha_ingreso', $mes)
            ->whereYear('c.fecha_ingreso', $anio);

        $afiliacionesTotal = (clone $afiliacionesBaseQuery)->count();
        $afiliacionesNuevas = (clone $afiliacionesBaseQuery)
            ->whereNotExists(function ($query) use ($aid, $mes, $anio, $mesAnterior, $anioAnterior) {
                $query->select(DB::raw(1))
                    ->from('contratos AS c2')
                    ->whereColumn('c2.cedula', 'c.cedula')
                    ->where('c2.aliado_id', $aid)
                    ->where('c2.estado', 'retirado')
                    ->where(function ($q) use ($mes, $anio, $mesAnterior, $anioAnterior) {
                        $q->where(function ($q1) use ($mes, $anio) {
                            $q1->where('c2.paga_mes_actual', 1)
                               ->whereMonth('c2.fecha_retiro', $mes)
                               ->whereYear('c2.fecha_retiro', $anio);
                        })->orWhere(function ($q2) use ($mesAnterior, $anioAnterior) {
                            $q2->where('c2.paga_mes_actual', 0)
                            ->whereMonth('c2.fecha_retiro', $mesAnterior)
                            ->whereYear('c2.fecha_retiro', $anioAnterior);
                        });
                    });
            })
            ->count();

        $kpis = [
            'clientes_activos'          => DB::table('contratos')->where('aliado_id',$aid)->where('estado','vigente')->count(),
            'clientes_unicos'           => DB::table('contratos')->where('aliado_id',$aid)->where('estado','vigente')->count(DB::raw('DISTINCT cedula')),
            'razones_sociales'          => DB::table('razones_sociales')->where('aliado_id',$aid)->where('estado','Activa')->count(),
            'afiliaciones_mes_total'    => $afiliacionesTotal,
            'afiliaciones_mes_nuevas'   => $afiliacionesNuevas,
            'retiros_mes_total'         => $retirosTotal,
            'retiros_mes_sin_renovar'   => $retirosNoRenovados,
            'empresas'                  => DB::table('empresas')->where('aliado_id',$aid)->count(),
            'incapacidades'             => DB::table('incapacidades')->where('aliado_id',$aid)->whereNull('deleted_at')->whereNotIn('estado',['cerrado','rechazado'])->count(),
            'tareas'                    => DB::table('tareas')->where('aliado_id',$aid)->whereNull('deleted_at')->whereIn('estado',['pendiente','en_gestion','en_espera'])->count(),
        ];

        // Lo que le falta cerrar al aliado del mes. Son 3 consultas de ~250ms
        // cada una (viaje al servidor remoto, no complejidad), pero es el
        // número que da sentido a la tarjeta.
        $kpis['cierre_operacion'] = (new \App\Services\CierrePeriodoService())
            ->contadoresOperacion($aid, $mes, $anio);

        // Ojo: NO es `informes.ver`. El bloque financiero del hub (ingresos,
        // egresos, utilidad, saldos) es solo de superadmin y contable.
        $esFinanciero = Auth::user()->can('informes.financiero');
        if ($esFinanciero) {
            // Misma regla que el informe financiero de abajo, que es la buena:
            // `otros` (Otros planilla) es un gasto de seguridad social que el
            // aliado solo recauda, no un ingreso suyo; el ingreso es
            // `otros_admon`. Este KPI los tenía al revés y por eso el tablero y
            // el informe daban cifras distintas para el mismo mes.
            // Excepción: en 'otro_ingreso' (trámites) `otros` SÍ es el valor
            // cobrado, así que ahí sigue contando.
            $kpis['ingresos_mes'] = DB::table('facturas')
                ->where('aliado_id',$aid)->whereNull('deleted_at')
                ->where('mes',$mes)->where('anio',$anio)
                ->whereIn('estado',['pagada','abono'])
                ->sum(DB::raw("admon + seguro + afiliacion + mensajeria + iva + retiro
                               + ISNULL(otros_admon,0)
                               + CASE WHEN tipo = 'otro_ingreso' THEN otros ELSE 0 END"));
        }

        return view('admin.informes.hub', compact('kpis','esFinanciero'));
    }

    // ── Listados del cierre de mes (Excel de 4 hojas) ─────────────────
    public function listadosMes(Request $request)
    {
        $this->checkAdmin();
        $datos = $request->validate([
            'mes'  => 'required|integer|between:1,12',
            'anio' => 'required|integer|between:2020,2100',
        ]);

        $servicio = new \App\Services\ListadosMesExcelService($this->aliadoId(), (int) $datos['mes'], (int) $datos['anio']);
        $tmp = tempnam(sys_get_temp_dir(), 'lstmes');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($servicio->construir()))->save($tmp);

        return response()->download($tmp, $servicio->nombreArchivo(), [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    // ── 1. Clientes activos ───────────────────────────────────────────
    public function clientesActivos(Request $request)
    {
        $this->checkAdmin();
        $aid    = $this->aliadoId();
        $buscar = $request->input('q','');

        $fRazon      = $request->input('razon_social_id');
        $fEps        = $request->input('eps_id');
        $fCaja       = $request->input('caja_id');
        $fPension    = $request->input('pension_id');
        $fModalidad  = $request->input('tipo_modalidad_id');
        $fPlan       = $request->input('plan_id');

        $query = DB::table('contratos AS c')
            ->join('clientes AS cl', function($j) use($aid){ $j->on('cl.cedula','=','c.cedula')->where('cl.aliado_id',$aid); })
            ->leftJoin('razones_sociales AS rs','rs.id','=','c.razon_social_id')
            ->leftJoin('empresas AS em','em.id','=','cl.cod_empresa')
            ->leftJoin('eps AS e','e.id','=','c.eps_id')
            ->leftJoin('pensiones AS p','p.id','=','c.pension_id')
            ->leftJoin('cajas AS cj','cj.id','=','c.caja_id')
            ->leftJoin('tipo_modalidad AS tm','tm.id','=','c.tipo_modalidad_id')
            ->leftJoin('planes_contrato AS pl','pl.id','=','c.plan_id')
            ->where('c.aliado_id',$aid)
            ->where('c.estado','vigente')
            ->select('c.id','c.cedula','c.fecha_ingreso','c.salario',
                DB::raw("LTRIM(RTRIM(cl.primer_nombre+' '+ISNULL(cl.segundo_nombre,'')+' '+cl.primer_apellido+' '+ISNULL(cl.segundo_apellido,''))) AS nombre_completo"),
                'rs.razon_social','em.empresa','e.nombre AS eps_nombre',
                'cj.nombre AS caja_nombre','p.razon_social AS pension_nombre',
                'tm.tipo_modalidad AS modalidad_nombre','pl.nombre AS plan_nombre');

        if ($buscar) {
            $query->where(function($q) use($buscar){
                $q->where('c.cedula','like',"%$buscar%")
                  ->orWherePalabrasSinTildes(['cl.primer_nombre', 'cl.segundo_nombre', 'cl.primer_apellido', 'cl.segundo_apellido'], $buscar);
            });
        }

        if ($fRazon)      $query->where('c.razon_social_id', $fRazon);
        if ($fEps)        $query->where('c.eps_id', $fEps);
        if ($fCaja)       $query->where('c.caja_id', $fCaja);
        if ($fPension)    $query->where('c.pension_id', $fPension);
        if ($fModalidad)  $query->where('c.tipo_modalidad_id', $fModalidad);
        if ($fPlan)       $query->where('c.plan_id', $fPlan);

        if ($request->input('excel')) {
            $todosClientes = $query->orderBy('cl.primer_apellido')->get();
            return $this->exportExcel($todosClientes, 'clientes_activos',
                ['Cédula','Nombre','Razón Social','Empresa','EPS','Caja','Pensión','Modalidad','Plan','Fecha Ingreso','Salario'],
                fn($r)=>[$r->cedula,$r->nombre_completo,$r->razon_social,$r->empresa,$r->eps_nombre,$r->caja_nombre,$r->pension_nombre,$r->modalidad_nombre,$r->plan_nombre,sqldate($r->fecha_ingreso)?->format('d/m/Y'),$r->salario]);
        }

        $clientes = $query->orderBy('cl.primer_apellido')->paginate(50)->withQueryString();
        $total    = DB::table('contratos')->where('aliado_id',$aid)->where('estado','vigente')->count();

        // Carga de catálogos para los selects (solo opciones con clientes activos y su respectivo conteo)
        $razones = DB::table('contratos AS c')
            ->join('razones_sociales AS rs', 'rs.id', '=', 'c.razon_social_id')
            ->where('c.aliado_id', $aid)
            ->where('c.estado', 'vigente')
            ->groupBy('rs.id', 'rs.razon_social')
            ->select('rs.id', 'rs.razon_social', DB::raw('COUNT(*) AS total'))
            ->orderBy('rs.razon_social')
            ->get();

        $epsList = DB::table('contratos AS c')
            ->join('eps AS e', 'e.id', '=', 'c.eps_id')
            ->where('c.aliado_id', $aid)
            ->where('c.estado', 'vigente')
            ->groupBy('e.id', 'e.nombre')
            ->select('e.id', 'e.nombre', DB::raw('COUNT(*) AS total'))
            ->orderBy('e.nombre')
            ->get();

        $cajas = DB::table('contratos AS c')
            ->join('cajas AS cj', 'cj.id', '=', 'c.caja_id')
            ->where('c.aliado_id', $aid)
            ->where('c.estado', 'vigente')
            ->groupBy('cj.id', 'cj.nombre')
            ->select('cj.id', 'cj.nombre', DB::raw('COUNT(*) AS total'))
            ->orderBy('cj.nombre')
            ->get();

        $pensiones = DB::table('contratos AS c')
            ->join('pensiones AS p', 'p.id', '=', 'c.pension_id')
            ->where('c.aliado_id', $aid)
            ->where('c.estado', 'vigente')
            ->groupBy('p.id', 'p.razon_social')
            ->select('p.id', 'p.razon_social', DB::raw('COUNT(*) AS total'))
            ->orderBy('p.razon_social')
            ->get();

        $modalidades = DB::table('contratos AS c')
            ->join('tipo_modalidad AS tm', 'tm.id', '=', 'c.tipo_modalidad_id')
            ->where('c.aliado_id', $aid)
            ->where('c.estado', 'vigente')
            ->groupBy('tm.id', 'tm.tipo_modalidad', 'tm.observacion')
            ->select('tm.id', 'tm.tipo_modalidad', 'tm.observacion', DB::raw('COUNT(*) AS total'))
            ->orderBy('tm.tipo_modalidad')
            ->get();

        $planes = DB::table('contratos AS c')
            ->join('planes_contrato AS pl', 'pl.id', '=', 'c.plan_id')
            ->where('c.aliado_id', $aid)
            ->where('c.estado', 'vigente')
            ->groupBy('pl.id', 'pl.nombre')
            ->select('pl.id', 'pl.nombre', DB::raw('COUNT(*) AS total'))
            ->orderBy('pl.nombre')
            ->get();

        $totalClientes = DB::table('contratos')->where('aliado_id',$aid)->where('estado','vigente')->count(DB::raw('DISTINCT cedula'));

        return view('admin.informes.clientes_activos', compact(
            'clientes','total','totalClientes','buscar',
            'razones','epsList','cajas','pensiones','modalidades','planes',
            'fRazon','fEps','fCaja','fPension','fModalidad','fPlan'
        ));
    }

    // ── 2. Por razón social ───────────────────────────────────────────
    public function porRazonSocial()
    {
        $this->checkAdmin();
        $aid = $this->aliadoId();

        $data = DB::table('contratos AS c')
            ->join('razones_sociales AS rs','rs.id','=','c.razon_social_id')
            ->where('c.aliado_id',$aid)->where('c.estado','vigente')
            ->groupBy('rs.id','rs.razon_social','rs.estado')
            ->select('rs.id','rs.razon_social','rs.estado', DB::raw('COUNT(*) AS total'))
            ->orderByDesc('total')->get();

        $max = $data->max('total') ?: 1;
        return view('admin.informes.por_razon_social', compact('data','max'));
    }

    // ── 3. Afiliaciones y retiros ─────────────────────────────────────
    public function afiliacionesRetiros(Request $request)
    {
        $this->checkAdmin();
        $aid  = $this->aliadoId();
        $mes  = (int)$request->input('mes', now()->month);
        $anio = (int)$request->input('anio', now()->year);
        $mesAnterior  = $mes  === 1 ? 12 : $mes  - 1;
        $anioAnterior = $mes  === 1 ? $anio - 1 : $anio;

        $afiliaciones = DB::table('contratos AS c')
            ->leftJoin('motivos_afiliacion AS ma','ma.id','=','c.motivo_afiliacion_id')
            ->where('c.aliado_id',$aid)
            ->whereMonth('c.fecha_ingreso',$mes)->whereYear('c.fecha_ingreso',$anio)
            ->groupBy('ma.id','ma.nombre')
            ->select('ma.nombre AS motivo', DB::raw('COUNT(*) AS total'))
            ->orderByDesc('total')->get();

        $retiros = DB::table('contratos AS c')
            ->leftJoin('motivos_retiro AS mr','mr.id','=','c.motivo_retiro_id')
            ->where('c.aliado_id',$aid)->where('c.estado','retirado')
            ->where(function ($q) use ($mes, $anio, $mesAnterior, $anioAnterior) {
                $q->where(function ($q1) use ($mes, $anio) {
                    $q1->where('c.paga_mes_actual', 1)
                       ->whereMonth('c.fecha_retiro', $mes)
                       ->whereYear('c.fecha_retiro', $anio);
                })->orWhere(function ($q2) use ($mesAnterior, $anioAnterior) {
                    $q2->where('c.paga_mes_actual', 0)
                    ->whereMonth('c.fecha_retiro', $mesAnterior)
                    ->whereYear('c.fecha_retiro', $anioAnterior);
                });
            })
            ->groupBy('mr.id','mr.nombre')
            ->select('mr.nombre AS motivo', DB::raw('COUNT(*) AS total'))
            ->orderByDesc('total')->get();

        return view('admin.informes.afiliaciones_retiros', compact('afiliaciones','retiros','mes','anio'));
    }

    // ── 4. Empresas clientes ──────────────────────────────────────────
    public function empresasClientes()
    {
        $this->checkAdmin();
        $aid = $this->aliadoId();

        $data = DB::table('empresas AS em')
            ->where('em.aliado_id',$aid)
            ->leftJoin('clientes AS cl','cl.cod_empresa','=','em.id')
            ->leftJoin('contratos AS co', function($j) use($aid){
                $j->on('co.cedula','=','cl.cedula')->where('co.aliado_id',$aid)->where('co.estado','vigente');
            })
            ->groupBy('em.id','em.empresa','em.nit')
            ->select('em.id','em.empresa','em.nit',
                DB::raw('COUNT(DISTINCT cl.cedula) AS clientes'),
                DB::raw('COUNT(DISTINCT co.id) AS contratos'))
            ->orderByDesc('contratos')->get();

        return view('admin.informes.empresas_clientes', compact('data'));
    }

    // ── 5. Por entidades ──────────────────────────────────────────────
    public function porEntidades()
    {
        $this->checkAdmin();
        $aid = $this->aliadoId();

        $eps = DB::table('contratos AS c')->join('eps AS e','e.id','=','c.eps_id')
            ->where('c.aliado_id',$aid)->where('c.estado','vigente')
            ->groupBy('e.id','e.nombre')->select('e.nombre', DB::raw('COUNT(*) AS total'))->orderByDesc('total')->get();

        $pension = DB::table('contratos AS c')->join('pensiones AS p','p.id','=','c.pension_id')
            ->where('c.aliado_id',$aid)->where('c.estado','vigente')
            ->groupBy('p.id','p.razon_social')->select('p.razon_social AS nombre', DB::raw('COUNT(*) AS total'))->orderByDesc('total')->get();

        $arl = DB::table('contratos AS c')->join('arls AS a','a.id','=','c.arl_id')
            ->where('c.aliado_id',$aid)->where('c.estado','vigente')
            ->groupBy('a.id','a.nombre_arl')->select('a.nombre_arl AS nombre', DB::raw('COUNT(*) AS total'))->orderByDesc('total')->get();

        $caja = DB::table('contratos AS c')->join('cajas AS cj','cj.id','=','c.caja_id')
            ->where('c.aliado_id',$aid)->where('c.estado','vigente')
            ->groupBy('cj.id','cj.nombre')->select('cj.nombre', DB::raw('COUNT(*) AS total'))->orderByDesc('total')->get();

        return view('admin.informes.por_entidades', compact('eps','pension','arl','caja'));
    }

    public function retiradosMes(Request $request)
    {
        $this->checkAdmin();
        $aid  = $this->aliadoId();
        $mes  = (int)$request->input('mes', now()->month);
        $anio = (int)$request->input('anio', now()->year);
        $mesAnterior  = $mes  === 1 ? 12 : $mes  - 1;
        $anioAnterior = $mes  === 1 ? $anio - 1 : $anio;

        $retiradosBase = DB::table('contratos AS c')
            ->join('clientes AS cl', function($j) use($aid){ $j->on('cl.cedula','=','c.cedula')->where('cl.aliado_id',$aid); })
            ->leftJoin('razones_sociales AS rs','rs.id','=','c.razon_social_id')
            ->leftJoin('motivos_retiro AS mr','mr.id','=','c.motivo_retiro_id')
            ->leftJoin('planes_contrato AS pl','pl.id','=','c.plan_id')
            ->leftJoin('tipo_modalidad AS tm','tm.id','=','c.tipo_modalidad_id')
            ->where('c.aliado_id',$aid)->where('c.estado','retirado')
            ->where(function ($q) use ($mes, $anio, $mesAnterior, $anioAnterior) {
                $q->where(function ($q1) use ($mes, $anio) {
                    $q1->where('c.paga_mes_actual', 1)
                       ->whereMonth('c.fecha_retiro', $mes)
                       ->whereYear('c.fecha_retiro', $anio);
                })->orWhere(function ($q2) use ($mesAnterior, $anioAnterior) {
                    $q2->where('c.paga_mes_actual', 0)
                    ->whereMonth('c.fecha_retiro', $mesAnterior)
                    ->whereYear('c.fecha_retiro', $anioAnterior);
                });
            })
            ->select('c.cedula','c.fecha_retiro','c.observacion','c.updated_at AS fecha_marcado_retiro',
                DB::raw("LTRIM(RTRIM(cl.primer_nombre+' '+ISNULL(cl.segundo_nombre,'')+' '+cl.primer_apellido+' '+ISNULL(cl.segundo_apellido,''))) AS nombre_completo"),
                'rs.razon_social','mr.nombre AS motivo',
                'pl.nombre AS plan_nombre','tm.tipo_modalidad AS modalidad_nombre',
                DB::raw("(SELECT COUNT(*) FROM contratos WHERE cedula = c.cedula AND aliado_id = c.aliado_id AND estado = 'vigente') AS tiene_contrato_vigente"),
                DB::raw("(SELECT TOP 1 total_ss FROM facturas WHERE contrato_id = c.id AND numero_factura = 0 AND deleted_at IS NULL ORDER BY id DESC) AS costo_ss"),
                DB::raw("(SELECT TOP 1 dias_cotizados FROM facturas WHERE contrato_id = c.id AND numero_factura = 0 AND deleted_at IS NULL ORDER BY id DESC) AS dias_retiro"))
            ->orderBy('c.fecha_retiro')->get();

        // Enriquecer cada registro con tipo_retiro en memoria
        $retiradosBase = $retiradosBase->map(function($r) {
            $r->tipo_retiro = ($r->costo_ss ?? 0) > 0 ? 'Real' : 'Informativo';
            return $r;
        });

        // Extraer opciones únicas para los filtros basados en la consulta actual del mes
        $opcRs = $retiradosBase->pluck('razon_social')->unique()->filter()->values()->toArray();
        $opcPlan = $retiradosBase->pluck('plan_nombre')->unique()->filter()->values()->toArray();
        $opcModalidad = $retiradosBase->pluck('modalidad_nombre')->unique()->filter()->values()->toArray();
        $opcMotivo = $retiradosBase->pluck('motivo')->unique()->filter()->values()->toArray();
        $opcTipo = ['Real', 'Informativo'];

        // Capturar filtros
        $fRs = $request->get('f_rs');
        $fPlan = $request->get('f_plan');
        $fModalidad = $request->get('f_modalidad');
        $fMotivo = $request->get('f_motivo');
        $fTipo = $request->get('f_tipo');

        // Filtrar la colección (excluir el valor 'todos' que representa resetear filtro)
        $retirados = $retiradosBase;
        if ($fRs && $fRs !== 'todos') {
            $retirados = $retirados->where('razon_social', $fRs);
        }
        if ($fPlan && $fPlan !== 'todos') {
            $retirados = $retirados->where('plan_nombre', $fPlan);
        }
        if ($fModalidad && $fModalidad !== 'todos') {
            $retirados = $retirados->where('modalidad_nombre', $fModalidad);
        }
        if ($fMotivo && $fMotivo !== 'todos') {
            $retirados = $retirados->where('motivo', $fMotivo);
        }
        if ($fTipo && $fTipo !== 'todos') {
            $retirados = $retirados->where('tipo_retiro', $fTipo);
        }
        $retirados = $retirados->values();

        if ($request->input('excel')) return $this->exportExcel($retirados,'retirados_mes',
            ['Cédula','Nombre','Razón Social','Plan','Modalidad','Días Retiro','Fecha Retiro','Fecha Marcado Retiro','Motivo','Costo SS Retiro','Tipo Retiro','Observación','Renovó'],
            fn($r)=>[$r->cedula,$r->nombre_completo,$r->razon_social,$r->plan_nombre ?? '—',$r->modalidad_nombre ?? '—',$r->dias_retiro ?? 0,sqldate($r->fecha_retiro)?->format('d/m/Y'),sqldate($r->fecha_marcado_retiro)?->format('d/m/Y H:i'),$r->motivo,$r->costo_ss ? (int)$r->costo_ss : 0, $r->tipo_retiro, $r->observacion, $r->tiene_contrato_vigente > 0 ? 'Sí' : 'No']);

        return view('admin.informes.retirados_mes', compact(
            'retirados','mes','anio',
            'opcRs','opcPlan','opcModalidad','opcMotivo','opcTipo',
            'fRs','fPlan','fModalidad','fMotivo','fTipo'
        ));
    }

    /**
     * Validación de cierre: qué contratos vigentes se quedaron por fuera de la
     * planilla del período, por razón social, junto con cómo va la liquidación
     * por API de cada una.
     *
     * Mientras el mes corre es normal tener pendientes —la nómina se liquida
     * en tandas—; al cerrarlo, el que siga aquí es un retiro que nunca se
     * registró. Ver CierrePeriodoService.
     *
     * Solo BryNex: una razón social agrupa varias empresas cliente, así que
     * el conteo de faltantes sin ese contexto siembra dudas en el aliado.
     *
     * A diferencia del resto de informes, este NO se limita al aliado activo:
     * recorre todos y junta por NIT las razones sociales repetidas, con las de
     * BRYGAR de primeras. El filtro por aliado sirve para aislar a uno.
     */
    public function validacionCierre(Request $request)
    {
        if (! Auth::user()->can('brynex_cierre.ver')) {
            abort(403, 'No tienes permiso para «Ver pendientes de planilla (Validación de cierre)».');
        }

        $mes  = (int) $request->input('mes',  now()->month);
        $anio = (int) $request->input('anio', now()->year);
        $rsId = $request->input('razon_social_id');
        $fAliado = $request->input('aliado_id') ? (int) $request->input('aliado_id') : null;

        $servicio = new \App\Services\CierrePeriodoService();
        $periodo  = $servicio->periodo($mes, $anio);
        $resumen  = $servicio->resumen($fAliado, $mes, $anio);
        $aliados  = $this->aliadosConVigentes();

        // La URL trae un solo id —viene del aviso de /admin/planos—, pero la
        // fila del informe puede juntar varias razones sociales del mismo NIT:
        // el detalle se abre para el grupo completo.
        $grupo = $rsId
            ? $resumen->first(fn($g) => in_array((int) $rsId, $g->razon_social_ids, true))
            : null;

        // El detalle es pesado: solo se arma para la razón social abierta.
        $detalle = $grupo
            ? $servicio->pendientes($fAliado, $mes, $anio, $grupo->razon_social_ids)
            : collect();

        if ($request->input('excel')) {
            $filas = $grupo ? $detalle : $servicio->pendientes($fAliado, $mes, $anio);

            return $this->exportExcel($filas, 'validacion_cierre',
                ['Cédula','Nombre','Aliado','Razón Social','Plan','Modalidad','Celular','Fecha Ingreso','Último período en planilla'],
                fn($r) => [
                    $r->cedula, $r->nombre, $r->aliado, $r->razon_social, $r->plan_nombre ?? '—',
                    $r->modalidad ?? '—', $r->celular, $r->fecha_ingreso,
                    $r->ultimo_periodo ? substr((string) $r->ultimo_periodo, 4, 2).'/'.substr((string) $r->ultimo_periodo, 0, 4) : 'nunca',
                ]);
        }

        return view('admin.informes.validacion_cierre', compact(
            'resumen', 'detalle', 'grupo', 'mes', 'anio', 'periodo', 'rsId', 'fAliado', 'aliados'
        ));
    }

    /**
     * Los vigentes que NO deben cotizar el período, para el modal de «sin
     * planilla». Se pide por AJAX: son la minoría de las filas y cargarlas
     * todas de entrada duplicaría el peso del informe.
     */
    public function validacionCierreSinPlanilla(Request $request)
    {
        if (! Auth::user()->can('brynex_cierre.ver')) {
            abort(403);
        }

        $mes  = (int) $request->input('mes',  now()->month);
        $anio = (int) $request->input('anio', now()->year);
        $fAliado = $request->input('aliado_id') ? (int) $request->input('aliado_id') : null;

        $ids = collect(explode(',', (string) $request->input('ids')))
            ->map(fn($i) => (int) trim($i))->filter()->values()->all();

        if (empty($ids)) {
            return response()->json(['filas' => []]);
        }

        $filas = (new \App\Services\CierrePeriodoService())
            ->noAplican($fAliado, $mes, $anio, $ids);

        return response()->json([
            'filas' => $filas->map(fn($f) => [
                'cedula'        => $f->cedula,
                'nombre'        => $f->nombre ?: '—',
                'aliado'        => $f->aliado,
                'aliado_id'     => (int) $f->aliado_id,
                'razon_social'  => $f->razon_social,
                'plan'          => $f->plan_nombre ?? '—',
                'modalidad'     => $f->modalidad ?? '—',
                'fecha_ingreso' => $f->fecha_ingreso ?? '—',
                'motivo'        => $f->motivo,
                'ficha'         => route('admin.informes.validacion_cierre.ficha', [
                    'aliado_id' => $f->aliado_id, 'cedula' => $f->cedula,
                ]),
            ])->values(),
        ]);
    }

    /**
     * Abre la ficha de un cliente de otro aliado desde el informe global.
     *
     * La ficha vive detrás del aliado activo de la sesión, así que hay que
     * moverlo antes de redirigir; el informe no se afecta —ya es global— pero
     * el resto del panel sí queda parado en ese aliado. Por eso el enlace del
     * informe lo advierte y se abre en pestaña nueva.
     */
    public function validacionCierreFicha(Request $request)
    {
        if (! Auth::user()->can('brynex_cierre.ver')) {
            abort(403);
        }

        $request->validate([
            'aliado_id' => 'required|integer|exists:aliados,id',
            'cedula'    => 'required|string',
        ]);

        $user     = Auth::user();
        $aliadoId = (int) $request->input('aliado_id');

        if (! $user->puedeAccederAliado($aliadoId)) {
            abort(403, 'No tiene acceso a este aliado.');
        }

        session(['aliado_id_activo' => $aliadoId]);

        return redirect()->route('admin.clientes.ficha_cedula', [
            'cedula' => $request->input('cedula'),
        ]);
    }

    /** Aliados con contratos vigentes: los únicos que pueden salir en el informe. */
    private function aliadosConVigentes()
    {
        return DB::table('aliados AS a')
            ->join('contratos AS c', 'c.aliado_id', '=', 'a.id')
            ->where('c.estado', 'vigente')
            ->groupBy('a.id', 'a.nombre')
            ->orderBy('a.nombre')
            ->get(['a.id', 'a.nombre']);
    }

    /**
     * Cierre de operación: qué le falta cerrar al aliado del mes.
     *
     * Tres pendientes distintos que hoy solo se descubren mirando pantalla por
     * pantalla: tandas de planilla sin número (o sin liquidar, o liquidadas sin
     * confirmar el pago), vigentes sin factura del mes, y afiliaciones del mes
     * sin facturar o sin cobrar la afiliación.
     *
     * A diferencia de validacionCierre(), este SÍ es del aliado.
     */
    public function cierreOperacion(Request $request)
    {
        $this->checkAdmin();
        $aid  = $this->aliadoId();
        $mes  = (int) $request->input('mes',  now()->month);
        $anio = (int) $request->input('anio', now()->year);

        $servicio     = new \App\Services\CierrePeriodoService();
        $lotes        = $servicio->lotesSinConfirmar($aid);
        $cotizantes   = $servicio->cotizantesDeLotes($aid);
        $vigentes     = $servicio->vigentesSinFacturar($aid, $mes, $anio);
        $afiliaciones = $servicio->afiliacionesDelMes($aid, $mes, $anio);

        if ($excel = $request->input('excel')) {
            if ($excel === 'lotes') {
                return $this->exportExcel($lotes, 'planillas_sin_confirmar',
                    ['Razón Social','Período plano','Tanda','Cotizantes','Valor SS','Estado','N° planilla'],
                    fn($r) => [
                        $r->razon_social, $r->anio_plano.'-'.str_pad($r->mes_plano, 2, '0', STR_PAD_LEFT),
                        $r->n_plano, $r->cotizantes, (int) $r->valor_ss,
                        $r->api ? 'Liquidada, falta confirmar el pago' : 'Sin liquidar',
                        $r->api->numero_planilla ?? '',
                    ]);
            }

            $filas  = $excel === 'afiliaciones' ? $afiliaciones : $vigentes;
            $nombre = $excel === 'afiliaciones' ? 'afiliaciones_sin_facturar' : 'vigentes_sin_facturar';

            return $this->exportExcel($filas, $nombre,
                ['Cédula','Nombre','Razón Social','Plan','Modalidad','Celular','Fecha Ingreso','Pendiente'],
                fn($r) => [
                    $r->cedula, $r->nombre, $r->razon_social, $r->plan_nombre ?? '—',
                    $r->modalidad ?? '—', $r->celular, $r->fecha_ingreso,
                    isset($r->sin_factura)
                        ? ((int) $r->sin_factura === 1 ? 'Sin factura' : 'Sin cobro de afiliación')
                        : 'Sin factura del mes',
                ]);
        }

        return view('admin.informes.cierre_operacion', compact(
            'lotes', 'cotizantes', 'vigentes', 'afiliaciones', 'mes', 'anio'
        ));
    }

    // ── 7. Incapacidades ──────────────────────────────────────────────
    public function resumenIncapacidades()
    {
        $this->checkAdmin();
        $aid = $this->aliadoId();

        $base = DB::table('incapacidades')->where('aliado_id',$aid)->whereNull('deleted_at');

        $kpis = [
            'total'    => (clone $base)->count(),
            // De la constante y no a mano: esta copia listaba un 'cerrado' que
            // no existe en el catálogo y le faltaban cierre_exitoso, negada y
            // las pagadas parciales, así que contaba como activas cosas ya
            // cerradas. Ahora también deja fuera las anuladas.
            'activas'  => (clone $base)->whereNotIn('estado', \App\Http\Controllers\Admin\IncapacidadController::ESTADOS_CERRADOS)->count(),
            'dias'     => (clone $base)->sum('dias_incapacidad'),
            'v_esperado'=> (clone $base)->sum('valor_esperado'),
        ];

        $porTipo    = (clone $base)->groupBy('tipo_incapacidad')->select('tipo_incapacidad', DB::raw('COUNT(*) AS total'))->orderByDesc('total')->get();
        $porEstado  = (clone $base)->groupBy('estado')->select('estado', DB::raw('COUNT(*) AS total'))->orderByDesc('total')->get();
        $porEntidad = (clone $base)->groupBy('tipo_entidad')->select('tipo_entidad', DB::raw('COUNT(*) AS total, SUM(valor_esperado) AS valor'))->orderByDesc('total')->get();

        return view('admin.informes.incapacidades', compact('kpis','porTipo','porEstado','porEntidad'));
    }

    // ── 8. Tareas ─────────────────────────────────────────────────────
    public function resumenTareas()
    {
        $this->checkAdmin();
        $aid = $this->aliadoId();

        $base = DB::table('tareas')->where('aliado_id',$aid)->whereNull('deleted_at');

        $kpis = [
            'total'      => (clone $base)->count(),
            'pendiente'  => (clone $base)->where('estado','pendiente')->count(),
            'en_gestion' => (clone $base)->where('estado','en_gestion')->count(),
            'en_espera'  => (clone $base)->where('estado','en_espera')->count(),
            'cerradas'   => (clone $base)->where('estado','cerrada')->count(),
        ];

        $porTipo      = (clone $base)->whereIn('estado',['pendiente','en_gestion','en_espera'])->groupBy('tipo')->select('tipo', DB::raw('COUNT(*) AS total'))->orderByDesc('total')->get();
        $porEncargado = (clone $base)->whereIn('estado',['pendiente','en_gestion','en_espera'])
            ->join('users AS u','u.id','=','tareas.encargado_id')
            ->groupBy('u.id','u.nombre')->select('u.nombre', DB::raw('COUNT(*) AS total'))->orderByDesc('total')->get();

        return view('admin.informes.tareas', compact('kpis','porTipo','porEncargado'));
    }

    // ── 9. Estado financiero ──────────────────────────────────────────
    public function estadoFinanciero(Request $request)
    {
        $this->checkFinanciero();
        $aid  = $this->aliadoId();
        $mes  = (int)$request->input('mes', now()->month);
        $anio = (int)$request->input('anio', now()->year);
        $esMesActual = ($mes == now()->month && $anio == now()->year);

        // ── Ingresos base CAJA: dinero recibido este mes (fecha_pago) ────────────
        // Se usa fecha_pago (no mes/anio del período) para reflejar el efectivo
        // real cobrado en el mes — incluye facturas de meses anteriores pagadas ahora.
        $ingresosRaw = DB::table('facturas')
            ->where('aliado_id', $aid)->whereNull('deleted_at')
            ->whereNotNull('fecha_pago')
            ->whereMonth('fecha_pago', $mes)->whereYear('fecha_pago', $anio)
            ->whereIn('estado', ['pagada', 'abono', 'prestamo'])
            ->groupBy('tipo')
            ->selectRaw('
                tipo,
                SUM(admon)        as sum_admon,
                SUM(seguro)       as sum_seguro,
                SUM(afiliacion)   as sum_afiliacion,
                SUM(mensajeria)   as sum_mensajeria,
                SUM(otros)        as sum_otros,
                SUM(iva)          as sum_iva,
                SUM(retiro)       as sum_retiro,
                SUM(ISNULL(admin_asesor,0))   as sum_admin_asesor,
                SUM(ISNULL(otros_admon,0))    as sum_otros_admon,
                SUM(ISNULL(dist_admon,0))     as sum_dist_admon,
                SUM(ISNULL(dist_asesor,0))    as sum_dist_asesor,
                SUM(ISNULL(dist_retiro,0))    as sum_dist_retiro,
                SUM(ISNULL(dist_encargado,0)) as sum_dist_encargado,
                SUM(ISNULL(dist_utilidad,0))  as sum_dist_utilidad
            ')
            ->get()
            ->keyBy('tipo');

        $planillasRaw = $ingresosRaw->get('planilla');
        // Canal ADMON: admon + seguro + mensajeria + iva + otros_admon
        // 'otros' pertenece al canal SS, no a admon
        $ingAdmon = $planillasRaw
            ? ((float)($planillasRaw->sum_admon      ?? 0)
             + (float)($planillasRaw->sum_seguro     ?? 0)
             + (float)($planillasRaw->sum_mensajeria ?? 0)
             + (float)($planillasRaw->sum_iva        ?? 0)
             + (float)($planillasRaw->sum_otros_admon?? 0))
            : 0.0;
        // Retiro campo: lo cobra el aliado por procesar el retiro del empleado
        $ingRetiroCampo = $planillasRaw ? (float)($planillasRaw->sum_retiro ?? 0) : 0.0;
        $ingPlanillas   = $ingAdmon + $ingRetiroCampo;

        $afiliacionesRaw = $ingresosRaw->get('afiliacion');
        // Canal AFILIACIONES: solo 'afiliacion'; dist_* son su distribución interna
        $ingAfiliaciones = $afiliacionesRaw
            ? (float)($afiliacionesRaw->sum_afiliacion ?? 0)
            : 0.0;

        $tramitesRaw = $ingresosRaw->get('otro_ingreso');
        $ingTramites = $tramitesRaw
            ? ((float)($tramitesRaw->sum_admon ?? 0) + (float)($tramitesRaw->sum_otros ?? 0))
            : 0.0;

        // Abonos a préstamos cobrados en el mes actual
        $abonosCobradosMes = (float) DB::table('abonos as a')
            ->join('facturas as f', 'f.id', '=', 'a.factura_id')
            ->where('f.aliado_id', $aid)
            ->whereMonth('a.fecha', $mes)->whereYear('a.fecha', $anio)
            ->sum('a.valor');

        // Abonos cobrados este mes pero que corresponden a préstamos de meses anteriores
        $abonosMesesAnteriores = (float) DB::table('abonos as a')
            ->join('facturas as f', 'f.id', '=', 'a.factura_id')
            ->where('f.aliado_id', $aid)
            ->whereNull('f.deleted_at')
            ->whereMonth('a.fecha', $mes)->whereYear('a.fecha', $anio)
            ->where(function($q) use ($mes, $anio) {
                $q->whereYear('f.fecha_pago', '<', $anio)
                  ->orWhere(function($sq) use ($mes, $anio) {
                      $sq->whereYear('f.fecha_pago', '=', $anio)
                         ->whereMonth('f.fecha_pago', '<', $mes);
                  });
            })
            ->sum('a.valor');

        $moraRecogida = (float) DB::table('facturas')
            ->where('aliado_id', $aid)->whereNull('deleted_at')
            ->whereNotNull('fecha_pago')
            ->whereMonth('fecha_pago', $mes)->whereYear('fecha_pago', $anio)
            ->whereIn('estado', ['pagada', 'abono', 'prestamo'])
            ->sum('mora');

        // ── Desglose canal ADMON (para la vista de 3 canales) ────────────────
        $desgloseAdmon = [
            'admon'        => (float)($planillasRaw->sum_admon       ?? 0),
            'seguro'       => (float)($planillasRaw->sum_seguro      ?? 0),
            'mensajeria'   => (float)($planillasRaw->sum_mensajeria  ?? 0),
            'iva'          => (float)($planillasRaw->sum_iva         ?? 0),
            'otros_admon'  => (float)($planillasRaw->sum_otros_admon ?? 0),
            'retiro_campo' => (float)($planillasRaw->sum_retiro      ?? 0),
            'admin_asesor' => (float)($planillasRaw->sum_admin_asesor?? 0), // comisión informativa
        ];

        // ── Desglose canal AFILIACIONES (para la vista de 3 canales) ──────────
        $desgloseAfiliaciones = [
            'afiliacion'    => (float)($afiliacionesRaw->sum_afiliacion  ?? 0),
            'dist_admon'    => (float)($afiliacionesRaw->sum_dist_admon  ?? 0),
            'dist_asesor'   => (float)($afiliacionesRaw->sum_dist_asesor ?? 0),
            'dist_retiro'   => (float)($afiliacionesRaw->sum_dist_retiro ?? 0),
            'dist_encargado'=> (float)($afiliacionesRaw->sum_dist_encargado?? 0),
            'dist_utilidad' => (float)($afiliacionesRaw->sum_dist_utilidad?? 0),
            // Distribuido total para verificar que sume igual a afiliacion
            'distribuido'   => (float)(($afiliacionesRaw->sum_dist_admon ?? 0)
                                     + ($afiliacionesRaw->sum_dist_asesor  ?? 0)
                                     + ($afiliacionesRaw->sum_dist_retiro  ?? 0)
                                     + ($afiliacionesRaw->sum_dist_encargado ?? 0)
                                     + ($afiliacionesRaw->sum_dist_utilidad?? 0)),
        ];

        $distAsesorAfil = (float)($desgloseAfiliaciones['dist_asesor'] ?? 0);
        $distRetiroAfil = (float)($desgloseAfiliaciones['dist_retiro'] ?? 0);
        $distEncargadoAfil = (float)($desgloseAfiliaciones['dist_encargado'] ?? 0);
        $ingAfiliacionesNeto = max(0.0, $ingAfiliaciones - $distAsesorAfil - $distRetiroAfil - $distEncargadoAfil);

        $ingresos = [
            'planillas'   => (float)$ingPlanillas,
            'afiliaciones'=> (float)$ingAfiliacionesNeto,
            'tramites'    => (float)$ingTramites,
            'mora'        => (float)$moraRecogida,
            'prestamos'   => $abonosCobradosMes,
            'total'       => (float)($ingPlanillas + $ingAfiliacionesNeto + $ingTramites) // Ingresos iniciales sin moras
        ];


        // ── SS de terceros, mora recogida y desglose ingresos SS en una sola consulta ──
        $mesAnt  = $mes > 1 ? $mes - 1 : 12;
        $anioAnt = $mes > 1 ? $anio : $anio - 1;

        $mesSig  = $mes < 12 ? $mes + 1 : 1;
        $anioSig = $mes < 12 ? $anio : $anio + 1;

        $ssPrestamosMesSiguiente = (float) DB::table('facturas')
            ->where('aliado_id', $aid)->whereNull('deleted_at')
            ->where('mes', $mesSig)->where('anio', $anioSig)
            ->where('es_prestamo', 1)
            ->whereNotNull('fecha_pago')
            ->whereMonth('fecha_pago', $mes)->whereYear('fecha_pago', $anio)
            ->sum('total_ss');

        $ssMesAnteriorParaActual = (float) DB::table('facturas')
            ->where('aliado_id', $aid)->whereNull('deleted_at')
            ->whereIn('estado', ['pagada', 'abono', 'prestamo'])
            ->where('es_prestamo', 0) // Excluir préstamos del saldo arrastrado
            ->whereNotNull('fecha_pago')
            ->whereMonth('fecha_pago', $mesAnt)->whereYear('fecha_pago', $anioAnt)
            ->where('numero_factura', '>', 0)
            ->where('mes', $mes)->where('anio', $anio)
            ->sum('total_ss');

        $facturasData = DB::table('facturas')
            ->where('aliado_id', $aid)->whereNull('deleted_at')
            ->whereNotNull('fecha_pago')
            ->whereMonth('fecha_pago', $mes)->whereYear('fecha_pago', $anio)
            ->selectRaw("
                SUM(CASE WHEN estado IN ('pagada','abono','prestamo') AND numero_factura > 0 THEN total_ss + otros ELSE 0 END) AS recaudo_ss,
                SUM(CASE WHEN estado IN ('pagada','abono','prestamo') AND numero_factura = 0 THEN total_ss ELSE 0 END) AS costo_retiros,
                SUM(CASE WHEN estado IN ('pagada','abono','prestamo') THEN mora ELSE 0 END) AS mora_recogida,
                SUM(CASE WHEN estado IN ('pagada','abono','prestamo') AND numero_factura > 0 AND tipo = 'planilla' THEN v_eps ELSE 0 END) AS ss_eps,
                SUM(CASE WHEN estado IN ('pagada','abono','prestamo') AND numero_factura > 0 AND tipo = 'planilla' THEN v_arl ELSE 0 END) AS ss_arl,
                SUM(CASE WHEN estado IN ('pagada','abono','prestamo') AND numero_factura > 0 AND tipo = 'planilla' THEN v_afp ELSE 0 END) AS ss_afp,
                SUM(CASE WHEN estado IN ('pagada','abono','prestamo') AND numero_factura > 0 AND tipo = 'planilla' THEN v_caja ELSE 0 END) AS ss_caja,
                SUM(CASE WHEN estado IN ('pagada','abono','prestamo') AND numero_factura > 0 AND tipo = 'planilla' AND anio = ? AND mes = ? THEN total_ss + otros ELSE 0 END) AS ss_actuales,
                SUM(CASE WHEN estado IN ('pagada','abono','prestamo') AND numero_factura > 0 AND tipo = 'planilla' AND (anio > ? OR (anio = ? AND mes > ?)) THEN total_ss + otros ELSE 0 END) AS ss_futuras,
                SUM(CASE WHEN estado IN ('pagada','abono','prestamo') THEN retiro ELSE 0 END) AS retiro_campo,
                SUM(CASE WHEN estado IN ('pagada','abono','prestamo') THEN c_asesor ELSE 0 END) AS comisiones_asesor
            ", [$anio, $mes, $anio, $anio, $mes])
            ->first();

        $recaudoSS    = (float)($facturasData->recaudo_ss ?? 0);
        $moraRecogida = (float)($facturasData->mora_recogida ?? 0);

        // Bolsa acumulada neta de retiros que viene de los meses anteriores al inicio de este mes
        $fechaInicioMes = \Carbon\Carbon::createFromDate($anio, $mes, 1)->startOfMonth()->toDateString();

        $cobradoRetirosFPrev = 0.0;

        $cobradoDistRetPrev = DB::table('facturas')
            ->where('aliado_id', $aid)->whereNull('deleted_at')->whereNotNull('fecha_pago')
            ->where('fecha_pago', '>=', '2026-07-01')
            ->where('fecha_pago', '<', $fechaInicioMes)
            ->where('numero_factura', '>', 0)
            ->whereIn('estado', ['pagada','abono','prestamo'])
            ->sum('dist_retiro');

        $pagadoRetirosPPrev = (float) DB::table('planos as p2')
            ->join('facturas as f', 'f.id', '=', 'p2.factura_id')
            ->where('p2.aliado_id', $aid)
            ->whereNull('p2.deleted_at')
            ->whereNull('f.deleted_at')
            ->where('f.numero_factura', 0)
            ->whereIn('p2.numero_planilla', function($query) use ($aid, $fechaInicioMes) {
                $query->select('numero_planilla')
                    ->from('gastos')
                    ->where('aliado_id', $aid)
                    ->where('tipo', 'pago_planilla')
                    ->where('fecha', '>=', '2026-07-01')
                    ->where('fecha', '<', $fechaInicioMes)
                    ->whereNotNull('numero_planilla');
            })
            ->sum('f.total_ss');

        $costoRetiros = max(0.0, (float)$cobradoRetirosFPrev + (float)$cobradoDistRetPrev - (float)$pagadoRetirosPPrev);

        $ingresosSS = [
            'eps'          => (float)($facturasData->ss_eps ?? 0),
            'arl'          => (float)($facturasData->ss_arl ?? 0),
            'afp'          => (float)($facturasData->ss_afp ?? 0),
            'caja'         => (float)($facturasData->ss_caja ?? 0),
            'otros'        => (float)($recaudoSS - (($facturasData->ss_eps ?? 0) + ($facturasData->ss_arl ?? 0) + ($facturasData->ss_afp ?? 0) + ($facturasData->ss_caja ?? 0))),
            'total_ss'     => (float)($facturasData->recaudo_ss ?? 0),
            'ss_actuales'  => (float)($facturasData->ss_actuales ?? 0),
            'ss_anteriores'=> $ssMesAnteriorParaActual,
            'ss_futuras'   => (float)($facturasData->ss_futuras ?? 0),
            'retiro_campo' => (float)($facturasData->retiro_campo ?? 0),
        ];

        // Egresos SS: gastos pago_planilla del mes seleccionado (consulta base rápida)
        $egresosSSDetalle = DB::table('gastos AS g')
            ->leftJoin('banco_cuentas AS bc', 'bc.id', '=', 'g.banco_origen_id')
            ->where('g.aliado_id', $aid)
            ->where('g.tipo', 'pago_planilla')
            ->whereMonth('g.fecha', $mes)
            ->whereYear('g.fecha', $anio)
            ->selectRaw("
                g.numero_planilla, g.descripcion, g.pagado_a,
                MAX(g.fecha) AS fecha,
                SUM(g.valor) AS total,
                COUNT(*) AS cantidad,
                MAX(bc.banco) AS banco_nombre,
                MAX(bc.nombre) AS banco_titular,
                MAX(g.imagen_path) AS imagen_path
            ")
            ->groupBy('g.numero_planilla', 'g.descripcion', 'g.pagado_a')
            ->orderByDesc('total')
            ->get();

        // Enriquecer en memoria cruzando con planillas
        $planillasMes = $egresosSSDetalle->pluck('numero_planilla')->filter()->unique()->toArray();
        $ssFacturas = collect();
        if (!empty($planillasMes)) {
            $ssFacturas = DB::table('planos as p2')
                ->join('facturas as f', 'f.id', '=', 'p2.factura_id')
                ->where('p2.aliado_id', $aid)
                ->whereIn('p2.numero_planilla', $planillasMes)
                ->whereNull('p2.deleted_at')
                ->whereNull('f.deleted_at')
                ->groupBy('p2.numero_planilla')
                ->selectRaw("
                    p2.numero_planilla,
                    SUM(CASE WHEN f.numero_factura > 0 THEN f.total_ss ELSE 0 END) AS ss_cobrado_facturas,
                    SUM(CASE WHEN f.numero_factura = 0 THEN f.total_ss ELSE 0 END) AS ss_retiro_facturas,
                    SUM(CASE WHEN f.estado IN ('pagada','abono','prestamo') THEN f.mora ELSE 0 END) AS ss_mora_facturas
                ")
                ->get()
                ->keyBy('numero_planilla');
        }

        $egresosSSDetalle = $egresosSSDetalle->map(function ($eg) use ($ssFacturas) {
            $numPlanilla = $eg->numero_planilla;
            $facData = $ssFacturas->get($numPlanilla);

            $eg->ss_cobrado_facturas = (float)($facData->ss_cobrado_facturas ?? 0);
            $eg->ss_retiro_facturas  = (float)($facData->ss_retiro_facturas  ?? 0);
            $eg->ss_mora_facturas    = (float)($facData->ss_mora_facturas    ?? 0);
            return $eg;
        });

        // ── Base CAJA: anticipos y cobradosAntes ya no aplican como ajuste ─────
        // Con base caja (fecha_pago), TODAS las facturas pagadas este mes ya están
        // en $facturasBase — no hay que sumar ni restar períodos futuros/anteriores.
        // Se mantienen como informativos con valor 0 para no romper la vista.
        $anticipos     = ['admon' => 0, 'ss' => 0, 'cant' => 0, 'total' => 0];
        $cobradosAntes = ['admon' => 0, 'ss' => 0, 'cant' => 0, 'total' => 0];

        $pagadoSSRaw = (float) $egresosSSDetalle->sum('total');
        $pagadoSSRetiro = (float)($egresosSSDetalle->sum('ss_retiro_facturas'));
        $pagadoSSReg = max(0.0, $pagadoSSRaw - $pagadoSSRetiro);

        $distRetiroAcumulado = (float)($desgloseAfiliaciones['dist_retiro'] ?? 0);
        $retirosCobradosMesActual = (float)($facturasData->costo_retiros ?? 0);
        $subtotalRetiros = $costoRetiros + $distRetiroAcumulado - $pagadoSSRetiro;

        $saldoSSMesAnterior = $ingresosSS['ss_anteriores'];
        $ssActuales = $ingresosSS['ss_actuales'];
        $totalSScanalRaw = $recaudoSS + $moraRecogida + $saldoSSMesAnterior;
        $subtotalOperativo = $totalSScanalRaw - $pagadoSSReg;

        // SS futura regular excluyendo los préstamos del mes siguiente
        $ssFuturasRegular = max(0.0, (float)$ingresosSS['ss_futuras'] - $ssPrestamosMesSiguiente);

        // Subtotal solo del mes actual: se excluye Julio (ss_futuras) del total del canal
        // para que el "Saldo planillas" refleje el excedente puro del mes (sin mezclar arrastre de Julio)
        $totalSSMesActual = $totalSScanalRaw - $ssFuturasRegular;

        // Saldo planillas = excedente real del mes actual + neto de retiros
        // Julio se muestra por separado al final como arrastre garantizado al próximo mes
        $saldoPlanillas = ($totalSSMesActual - $pagadoSSReg) + $subtotalRetiros;

        // Saldo próximo mes = únicamente lo recaudado para Julio (ssFuturasRegular)
        $saldoSS = $ssFuturasRegular;

        $sobrantePlanillaRaw = ($pagadoSSReg == 0.0) ? 0.0 : max(0.0, $saldoPlanillas);

        // Solo contar como ingreso real si el mes ya cerró
        if ($esMesActual) {
            $sobrantePlanilla = 0.0;                   // NO suma a ingresos

            // Solo se muestra/calcula como provisional después del 28 de cada mes
            if (now()->day >= 28) {
                $sobrantePlanillaProvisional = $sobrantePlanillaRaw;
            } else {
                $sobrantePlanillaProvisional = 0.0;
            }
        } else {
            $sobrantePlanilla            = $sobrantePlanillaRaw;
            $sobrantePlanillaProvisional = 0.0;
        }

        $moraGanancia = 0.0;
        $moraUtilizada = 0.0;

        // Actualizamos los ingresos con ganancia por mora y excedentes
        $ingresos['mora_ganancia'] = $moraGanancia;
        $ingresos['sobrante_planilla'] = $sobrantePlanilla;
        $ingresos['total'] = (float)($ingPlanillas + $ingresos['afiliaciones'] + $ingTramites + $sobrantePlanilla);

        // ── Reconciliación SS: planillas con gap entre cobrado y pagado ──
        // diferencia = gasto - (SS cobrado en facturas regulares + SS de retiros)
        // Si diferencia > 0: se pagó más SS del que se cobró al cliente
        // Si diferencia < 0: se cobró más SS del que se pagó (caso raro)
        $gapSS = $egresosSSDetalle->map(function ($eg) {
            $gasto      = (float)($eg->total ?? 0);
            $cobReg     = (float)($eg->ss_cobrado_facturas ?? 0);   // facturas numero_factura > 0
            $cobRetiro  = (float)($eg->ss_retiro_facturas  ?? 0);   // facturas numero_factura = 0
            $cobMora    = (float)($eg->ss_mora_facturas    ?? 0);   // mora
            $cobTotal   = $cobReg + $cobRetiro + $cobMora;
            $diff       = $gasto - $cobTotal;
            $tieneGap   = abs($diff) > 100;   // tolerancia 100 pesos por redondeo

            // Clasificar la causa principal del gap
            $causa = null;
            if ($tieneGap) {
                if ($cobReg == 0 && $cobRetiro > 0) {
                    $causa = 'retiro_sin_ingreso';   // Solo retiros — el gasto es real pero no hay recaudo
                } elseif ($cobReg == 0 && $cobRetiro == 0) {
                    $causa = 'sin_factura';           // No hay ninguna factura ligada a esta planilla
                } else {
                    $causa = 'diferencia_parcial';    // Hay facturas pero los montos no cuadran (período distinto, etc.)
                }
            }

            return [
                'numero_planilla' => $eg->numero_planilla,
                'descripcion'     => $eg->descripcion ?: $eg->pagado_a,
                'pagado_a'        => $eg->pagado_a,
                'fecha'           => $eg->fecha,
                'gasto'           => $gasto,
                'ss_cobrado_reg'  => $cobReg,
                'ss_cobrado_ret'  => $cobRetiro,
                'ss_cobrado'      => $cobTotal,
                'diferencia'      => $diff,
                'tiene_gap'       => $tieneGap,
                'causa'           => $causa,
                'cant_registros'  => (int)($eg->cantidad ?? 1),
            ];
        })->filter(fn($r) => $r['tiene_gap'])->values();

        // Resumen del gap agrupado por causa
        $gapResumen = [
            'total_gap'         => (float)($pagadoSSReg - $recaudoSS),
            'planillas_con_gap' => $gapSS->count(),
            'por_retiro'        => $gapSS->where('causa', 'retiro_sin_ingreso')->sum('diferencia'),
            'sin_factura'       => $gapSS->where('causa', 'sin_factura')->sum('diferencia'),
            'diferencia_parcial'=> $gapSS->where('causa', 'diferencia_parcial')->sum('diferencia'),
        ];


        // Comisiones asesor (acumuladas en facturas del mes)
        $comisionesAsesor = (float)($facturasData->comisiones_asesor ?? 0);

        // ── CANAL 5: Gastos de incapacidades (excluidos del Canal 1) ────────
        $tiposIncapacidad = \App\Models\Gasto::TIPOS_INCAPACIDAD;

        // Gastos operativos (sin planillas SS, traslados, ni gastos de incapacidad)
        $gastosOp = DB::table('gastos')->where('aliado_id',$aid)
            ->where('tipo','!=','pago_planilla')
            ->where('tipo','!=','efectivo_banco')
            ->where('forma_pago','!=','banco_banco')
            ->whereNotIn('tipo', $tiposIncapacidad)
            ->whereMonth('fecha',$mes)->whereYear('fecha',$anio)
            ->selectRaw('ISNULL(SUM(CAST(valor AS BIGINT)), 0) AS total')
            ->value('total');

        // ── Canal 5: Saldo acumulado histórico ───────────────────────────────
        $fechaInicioMesActual = \Carbon\Carbon::createFromDate($anio, $mes, 1)->startOfMonth()->toDateString();

        $canal5EntradaHistorica = (float) DB::table('abonos_incapacidades')
            ->where('aliado_id', $aid)
            ->where('tipo', 'entrada_incapacidad')
            ->where('fecha', '<', $fechaInicioMesActual)
            ->sum('valor');

        $canal5EgresoHistorico = (float) DB::table('gastos')
            ->where('aliado_id', $aid)
            ->whereIn('tipo', $tiposIncapacidad)
            ->where('fecha', '<', $fechaInicioMesActual)
            ->sum('valor');

        $canal5SaldoAnterior = $canal5EntradaHistorica - $canal5EgresoHistorico;

        // ── Canal 5: Movimientos del mes actual ──────────────────────────────
        $canal5EntradaMes = (float) DB::table('abonos_incapacidades')
            ->where('aliado_id', $aid)
            ->where('tipo', 'entrada_incapacidad')
            ->whereMonth('fecha', $mes)->whereYear('fecha', $anio)
            ->sum('valor');

        $canal5PagoAfiliado = (float) DB::table('gastos')
            ->where('aliado_id', $aid)->where('tipo', 'pago_incapacidad')
            ->whereMonth('fecha', $mes)->whereYear('fecha', $anio)
            ->sum('valor');

        $canal5Cuatropormil = (float) DB::table('gastos')
            ->where('aliado_id', $aid)->where('tipo', 'cuatropormil_incapacidad')
            ->whereMonth('fecha', $mes)->whereYear('fecha', $anio)
            ->sum('valor');

        $canal5OtrosDesc = (float) DB::table('gastos')
            ->where('aliado_id', $aid)->where('tipo', 'otros_incapacidad')
            ->whereMonth('fecha', $mes)->whereYear('fecha', $anio)
            ->sum('valor');

        // Ganancia admon → pasa al Canal 1 como ingreso
        $canal5GananciaAdmon = (float) DB::table('gastos')
            ->where('aliado_id', $aid)->where('tipo', 'admon_incapacidad')
            ->whereMonth('fecha', $mes)->whereYear('fecha', $anio)
            ->sum('valor');

        $canal5EgresosMes     = $canal5PagoAfiliado + $canal5Cuatropormil + $canal5OtrosDesc + $canal5GananciaAdmon;
        $canal5SaldoMes       = $canal5EntradaMes - $canal5EgresosMes;
        $canal5SaldoAcumulado = $canal5SaldoAnterior + $canal5SaldoMes;

        // Hay movimientos de Canal 5 este mes o hay saldo acumulado
        $canal5Visible = ($canal5EntradaMes > 0 || abs($canal5SaldoAcumulado) > 0
                          || $canal5EgresosMes > 0 || $canal5SaldoAnterior != 0);

        // ── Sumar ganancia admon de incapacidades al Canal 1 ────────────────
        $ingresos['admon_incapacidades'] = $canal5GananciaAdmon;
        $ingresos['total'] += $canal5GananciaAdmon;

        // La mora utilizada ya fue descontada de la mora ganada en ingresos, no se debe sumar a los gastos operativos para evitar doble resta
        $gastosOpActualizado = $gastosOp;

        $egresos = [
            'comisiones' => $comisionesAsesor,
            'operativos' => $gastosOpActualizado,
            'total'      => $comisionesAsesor + $gastosOpActualizado
        ];

        // La reserva de retiros de afiliación se resta de la utilidad
        $utilidad = $ingresos['total'] - $egresos['total'];

        // Tendencia 6 meses
        $tendencia = $this->tendencia6Meses($aid, $mes, $anio);

        // Mes anterior para comparación
        $mesAnt  = $mes > 1 ? $mes - 1 : 12;
        $anioAnt = $mes > 1 ? $anio : $anio - 1;
        $anterior = $this->resumenMes($aid, $mesAnt, $anioAnt);

        // ── Saldo SS del mes anterior ─────────────────────────────────
        // Recaudo SS y Costo Retiros del mes anterior en una sola consulta
        $facturasDataPrev = DB::table('facturas')
            ->where('aliado_id', $aid)->whereNull('deleted_at')
            ->whereNotNull('fecha_pago')
            ->whereMonth('fecha_pago', $mesAnt)->whereYear('fecha_pago', $anioAnt)
            ->selectRaw("
                SUM(CASE WHEN estado IN ('pagada','abono','prestamo') AND numero_factura > 0 THEN total_ss ELSE 0 END) AS recaudo_ss_prev,
                SUM(CASE WHEN estado IN ('pagada','abono','prestamo') AND numero_factura = 0 THEN total_ss ELSE 0 END) AS costo_retiros_prev
            ")
            ->first();

        $recaudoSSPrev    = (float)($facturasDataPrev->recaudo_ss_prev ?? 0);
        $costoRetirosPrev = (float)($facturasDataPrev->costo_retiros_prev ?? 0);

        // Pagado SS del mes anterior (gastos pago_planilla)
        $pagadoSSPrevRaw = (float) DB::table('gastos')
            ->where('aliado_id', $aid)
            ->where('tipo', 'pago_planilla')
            ->whereMonth('fecha', $mesAnt)->whereYear('fecha', $anioAnt)
            ->sum('valor');

        $pagadoSSPrev = max(0.0, $pagadoSSPrevRaw - $costoRetirosPrev);

        // Saldo SS disponible del mes anterior es la SS cobrada en el mes anterior para el período actual
        $saldoSSMesAnterior = $ingresosSS['ss_anteriores'];

        // El saldo SS que queda reservado para el mes siguiente es la SS cobrada este mes para períodos futuros + el excedente de retiros
        $saldoSS = $ingresosSS['ss_futuras'] + $subtotalRetiros;

        // Bancos: saldo al cierre del mes filtrado (si es mes pasado) o saldo actual (mes en curso)
        // $esMesActual ya está definido al inicio del método
        $bancos = BancoCuenta::where('aliado_id',$aid)->where('activo',true)->get();
        $bancoIds = $bancos->pluck('id')->toArray();

        $fechaFin = $esMesActual ? null : \Carbon\Carbon::createFromDate($anio, $mes, 1)->endOfMonth()->toDateString();
        $saldosBancos = \App\Models\Consignacion::saldosBancosOptimizados($aid, $bancoIds, $fechaFin);

        $bancos = $bancos->map(function($b) use($saldosBancos, $esMesActual, $mes, $anio){
            $b->saldo_actual = (int)($saldosBancos[$b->id] ?? 0);
            $b->label_saldo  = $esMesActual ? 'Saldo actual' : 'Saldo al ' . \Carbon\Carbon::createFromDate($anio,$mes,1)->endOfMonth()->format('d/m/y');
            return $b;
        });

        // ── Calulo de arrastre del mes anterior (Bancos + Efectivo) ──
        $fechaFinMesAnt = \Carbon\Carbon::createFromDate($anioAnt, $mesAnt, 1)->endOfMonth()->toDateString();
        // Si el mes anterior es anterior al corte de julio 2026, el arrastre es $0
        $fechaCorte = '2026-07-01';
        if ($fechaFinMesAnt < $fechaCorte) {
            $totalBancosMesAnt = 0.0;
        } else {
            $saldosBancosMesAnt = \App\Models\Consignacion::saldosBancosOptimizados($aid, $bancoIds, $fechaFinMesAnt);
            $totalBancosMesAnt = (float)collect($saldosBancosMesAnt)->sum();
        }

        // Calcular el arrastre de efectivo secuencialmente mes a mes para respetar la regla de no saldo negativo físico
        $saldoEfectivoMesAnt = 0.0;
        $start = \Carbon\Carbon::createFromDate(2026, 7, 1)->startOfMonth();
        $end = \Carbon\Carbon::createFromDate($anioAnt, $mesAnt, 1)->startOfMonth();

        if ($end->greaterThanOrEqualTo($start)) {
            $curr = $start->copy();
            while ($curr->lessThanOrEqualTo($end)) {
                $mCurr = $curr->month;
                $yCurr = $curr->year;

                $entM = (float) DB::table('facturas')
                    ->where('aliado_id', $aid)->whereNull('deleted_at')
                    ->whereIn('estado', ['pagada','abono'])
                    ->whereNotNull('fecha_pago')
                    ->whereMonth('fecha_pago', $mCurr)->whereYear('fecha_pago', $yCurr)
                    ->where('valor_efectivo', '>', 0)
                    ->where('es_prestamo', false)
                    ->sum('valor_efectivo');

                // Sin maestros 'distribuido' ni borrados: ver $efAnticipos más abajo.
                $antM = (float) DB::table('anticipos')
                    ->where('aliado_id', $aid)
                    ->whereIn('forma_pago', ['efectivo', 'nequi'])
                    ->whereMonth('fecha_pago', $mCurr)->whereYear('fecha_pago', $yCurr)
                    ->whereNull('deleted_at')
                    ->whereNotIn('estado', ['devuelto', 'distribuido'])
                    ->sum('valor');

                $gastM = (float) DB::table('gastos')
                    ->where('aliado_id', $aid)
                    ->whereMonth('fecha', $mCurr)->whereYear('fecha', $yCurr)
                    ->where('forma_pago', 'efectivo')
                    ->where('tipo', '!=', 'efectivo_banco')
                    ->sum('valor');

                $consM = (float) DB::table('gastos')
                    ->where('aliado_id', $aid)
                    ->whereMonth('fecha', $mCurr)->whereYear('fecha', $yCurr)
                    ->where('tipo', 'efectivo_banco')
                    ->sum('valor');

                $saldoEfectivoMesAnt = max(0.0, $saldoEfectivoMesAnt + ($entM + $antM) - ($gastM + $consM));
                $curr->addMonth();
            }
        }

        // Para julio de 2026, el saldo inicial de la caja es el recaudo de SS de junio
        if ($mes == 7 && $anio == 2026) {
            $saldoEfectivoMesAnt = $ssMesAnteriorParaActual;
        }
        $saldoTotalMesAnterior = $totalBancosMesAnt + $saldoEfectivoMesAnt;

        // ── Desglose de ingresos y egresos del mes por banco ──
        $ingMesPorBancoDetalle = DB::table('consignaciones')
            ->where('aliado_id', $aid)
            ->whereIn('banco_cuenta_id', $bancoIds)
            ->whereMonth('fecha', $mes)->whereYear('fecha', $anio)
            ->groupBy('banco_cuenta_id')
            ->selectRaw("
                banco_cuenta_id,
                SUM(CASE WHEN factura_id IS NOT NULL AND factura_id > 0 THEN valor ELSE 0 END) AS por_facturas,
                SUM(CASE WHEN factura_id IS NULL OR factura_id = 0 THEN valor ELSE 0 END) AS por_otros
            ")
            ->get()
            ->keyBy('banco_cuenta_id');

        $salMesPorBancoDetalle = DB::table('gastos')
            ->where('aliado_id', $aid)
            ->whereIn('banco_origen_id', $bancoIds)
            ->whereMonth('fecha', $mes)->whereYear('fecha', $anio)
            ->where('tipo', '!=', 'efectivo_banco')
            ->groupBy('banco_origen_id')
            ->selectRaw("
                banco_origen_id,
                SUM(CASE WHEN tipo = 'pago_planilla' THEN valor ELSE 0 END) AS por_planillas,
                SUM(CASE WHEN tipo <> 'pago_planilla' THEN valor ELSE 0 END) AS por_gastos
            ")
            ->get()
            ->keyBy('banco_origen_id');

        $bancos = $bancos->map(function ($b) use ($ingMesPorBancoDetalle, $salMesPorBancoDetalle) {
            $ingData = $ingMesPorBancoDetalle->get($b->id);
            $salData = $salMesPorBancoDetalle->get($b->id);

            $b->ing_mes            = (float)(($ingData->por_facturas ?? 0) + ($ingData->por_otros ?? 0));
            $b->ing_facturas       = (float)($ingData->por_facturas ?? 0);
            $b->ing_consignaciones = (float)($ingData->por_otros ?? 0);

            $b->sal_mes            = (float)(($salData->por_planillas ?? 0) + ($salData->por_gastos ?? 0));
            $b->sal_planillas      = (float)($salData->por_planillas ?? 0);
            $b->sal_transferencias = (float)($salData->por_gastos ?? 0);

            $b->saldo_mes          = $b->ing_mes - $b->sal_mes;
            return $b;
        });

        // ── Efectivo del mes (para la tarjeta de caja) ──
        $abonosEfectivoMes = (float) DB::table('abonos as a')
            ->join('facturas as f', 'f.id', '=', 'a.factura_id')
            ->where('f.aliado_id', $aid)
            ->whereMonth('a.fecha', $mes)->whereYear('a.fecha', $anio)
            ->where('a.forma_pago', 'efectivo')
            ->sum('a.valor_efectivo');

        $efEntradas = (float) DB::table('facturas')
            ->where('aliado_id', $aid)->whereNull('deleted_at')
            ->whereIn('estado', ['pagada','abono'])
            ->whereNotNull('fecha_pago')
            ->whereMonth('fecha_pago', $mes)->whereYear('fecha_pago', $anio)
            ->where('valor_efectivo', '>', 0)
            ->sum('valor_efectivo') + $abonosEfectivoMes;

        // Anticipos cobrados en efectivo o nequi en el mes (no devueltos ni borrados).
        // El maestro 'distribuido' de un anticipo de empresa no es plata aparte: su
        // valor ya está en los hijos que se repartieron por trabajador. Sumarlo
        // contaba doble cada anticipo de empresa (BRYGAR sep-2026: $13M de más).
        $efAnticipos = (float) DB::table('anticipos')
            ->where('aliado_id', $aid)
            ->whereIn('forma_pago', ['efectivo', 'nequi'])
            ->whereMonth('fecha_pago', $mes)->whereYear('fecha_pago', $anio)
            ->whereNull('deleted_at')
            ->whereNotIn('estado', ['devuelto', 'distribuido'])
            ->sum('valor');

        // Gastos ordinarios pagados en efectivo (excluyendo traslados a banco)
        $efGastos = (float) DB::table('gastos')
            ->where('aliado_id', $aid)
            ->whereMonth('fecha', $mes)->whereYear('fecha', $anio)
            ->where('forma_pago', 'efectivo')
            ->where('tipo', '!=', 'efectivo_banco')
            ->sum('valor');

        // Consignaciones de efectivo al banco (traslados)
        $efConsignaciones = (float) DB::table('gastos')
            ->where('aliado_id', $aid)
            ->whereMonth('fecha', $mes)->whereYear('fecha', $anio)
            ->where('tipo', 'efectivo_banco')
            ->sum('valor');

        $efSalidas = $efGastos + $efConsignaciones;
        $saldoEfectivoActual = $saldoEfectivoMesAnt + ($efEntradas + $efAnticipos) - $efSalidas;

        $efMes = (object)[
            'entradas'       => $efEntradas + $efAnticipos,
            'anticipos'      => $efAnticipos,
            'facturas'       => $efEntradas,
            'salidas'        => $efSalidas,
            'gastos'         => $efGastos,
            'consignaciones' => $efConsignaciones,
            'neto'           => ($efEntradas + $efAnticipos) - $efSalidas,
            'saldo_actual'   => $saldoEfectivoActual,
            'label_saldo'    => $esMesActual ? 'Saldo actual' : 'Saldo al ' . \Carbon\Carbon::createFromDate($anio,$mes,1)->endOfMonth()->format('d/m/y'),
        ];

        // Desglose diario
        $diario = $this->desgloseDiario($aid, $mes, $anio);

        if ($request->input('excel')) return $this->exportExcel(collect($diario),'estado_financiero',
            ['Día','# Plan','Planillas','# Afil','Afiliaciones','Trámites','SS','Gastos','Utilidad'],
            fn($r)=>[$r['dia'],(int)$r['cant_planillas'],(float)$r['planillas'],(int)$r['cant_afiliaciones'],(float)$r['afiliaciones'],(float)$r['tramites'],(float)$r['ss'],(float)$r['gastos'],(float)$r['utilidad']]);

        // ── Saldo retenido para asesores (comisiones ganadas - pagadas, desde mayo 2025) ──
        $saldoAsesores = \App\Http\Controllers\Admin\ComisionesController::calcularSaldoRetenido($aid);

        // ── Anticipos disponibles (recibidos, sin aplicar a factura) ─────────────
        $anticiposDisponibles = \App\Models\Anticipo::where('aliado_id', $aid)
            ->whereIn('estado', [\App\Models\Anticipo::ESTADO_DISPONIBLE, \App\Models\Anticipo::ESTADO_PARCIAL])
            ->selectRaw('COUNT(*) AS cant, ISNULL(SUM(valor - valor_aplicado), 0) AS total')
            ->first();
        $totalAnticiposDisponibles = (int)($anticiposDisponibles->total ?? 0);
        $cantAnticiposDisponibles  = (int)($anticiposDisponibles->cant  ?? 0);

        // Detalle de abonos a préstamos cobrados en el mes actual
        $abonosDetalleMes = DB::table('abonos as a')
            ->join('facturas as f', 'f.id', '=', 'a.factura_id')
            ->leftJoin('clientes as cl', function($j) use ($aid) {
                $j->on('cl.cedula', '=', 'f.cedula')->where('cl.aliado_id', $aid);
            })
            ->leftJoin('empresas as em', 'em.id', '=', 'f.empresa_id')
            ->where('f.aliado_id', $aid)
            ->whereMonth('a.fecha', $mes)->whereYear('a.fecha', $anio)
            ->selectRaw("
                a.id,
                a.fecha as fecha_abono,
                a.valor,
                a.forma_pago,
                a.observacion,
                f.numero_factura,
                f.empresa_id,
                CASE
                    WHEN f.empresa_id IS NOT NULL AND f.empresa_id > 0 THEN UPPER(ISNULL(em.empresa, '—'))
                    ELSE LTRIM(RTRIM(
                        ISNULL(cl.primer_nombre,'') + ' ' +
                        ISNULL(cl.segundo_nombre,'') + ' ' +
                        ISNULL(cl.primer_apellido,'') + ' ' +
                        ISNULL(cl.segundo_apellido,'')
                    ))
                END as nombre_cliente
            ")
            ->orderBy('a.fecha')
            ->get();

        // Planos del período sin gasto de pago_planilla asociado (Opción B)
        $ssPendientePago = (float) DB::table('planos as p')
            ->join('facturas as f', 'f.id', '=', 'p.factura_id')
            ->where('p.aliado_id', $aid)
            ->whereNull('p.deleted_at')
            ->whereNull('f.deleted_at')
            ->where('p.mes_plano', $mes)
            ->where('p.anio_plano', $anio)
            ->where('f.numero_factura', '>', 0)
            ->whereNotNull('p.numero_planilla')
            ->whereNotIn('p.numero_planilla', function($query) use ($aid) {
                $query->select('numero_planilla')
                    ->from('gastos')
                    ->where('aliado_id', $aid)
                    ->where('tipo', 'pago_planilla')
                    ->whereNotNull('numero_planilla');
            })
            ->sum('f.total_ss');

        $cantPlanillasPendientes = DB::table('planos as p')
            ->join('facturas as f', 'f.id', '=', 'p.factura_id')
            ->where('p.aliado_id', $aid)
            ->whereNull('p.deleted_at')
            ->whereNull('f.deleted_at')
            ->where('p.mes_plano', $mes)
            ->where('p.anio_plano', $anio)
            ->where('f.numero_factura', '>', 0)
            ->whereNotNull('p.numero_planilla')
            ->whereNotIn('p.numero_planilla', function($query) use ($aid) {
                $query->select('numero_planilla')
                    ->from('gastos')
                    ->where('aliado_id', $aid)
                    ->where('tipo', 'pago_planilla')
                    ->whereNotNull('numero_planilla');
            })
            ->distinct()
            ->count('p.numero_planilla');

        $totalSScanalRaw   = $recaudoSS + $moraRecogida + $saldoSSMesAnterior;
        $subtotalOperativo = $totalSScanalRaw - $pagadoSSReg;
        $ssFuturasRegular  = max(0.0, (float)$ingresosSS['ss_futuras'] - $ssPrestamosMesSiguiente);
        $totalSSMesActual  = $totalSScanalRaw - $ssFuturasRegular;
        $saldoPlanillas    = ($totalSSMesActual - $pagadoSSReg) + $subtotalRetiros;
        $saldoSS           = $ssFuturasRegular;

        // ── Incapacidades Canal 5: solo las que tienen algo pendiente ──────────
        // Pendiente = descuadre entre lo que entró de la EPS y lo que salió de la
        // caja por esa incapacidad. Los egresos del canal viven en `gastos`
        // (neto al afiliado + 4x1000 + otros + admon), NO en abonos_incapacidades:
        // restar solo el abono `pago_cliente` dejaba un residuo falso del tamaño
        // de los descuentos y las incapacidades cerradas nunca desaparecían.
        // Umbral de $1.000 para ignorar residuos de redondeo del 4x1000.
        $tiposGastoC5 = "'pago_incapacidad','cuatropormil_incapacidad','otros_incapacidad','admon_incapacidad'";
        $sqlEntradas  = "(SELECT ISNULL(SUM(valor), 0) FROM abonos_incapacidades WHERE incapacidad_id = incapacidades.id AND aliado_id = {$aid} AND tipo = 'entrada_incapacidad')";
        $sqlEgresos   = "(SELECT ISNULL(SUM(valor), 0) FROM gastos WHERE incapacidad_id = incapacidades.id AND aliado_id = {$aid} AND tipo IN ({$tiposGastoC5}))";
        $sqlNetoAfil  = "(SELECT ISNULL(SUM(valor), 0) FROM abonos_incapacidades WHERE incapacidad_id = incapacidades.id AND aliado_id = {$aid} AND tipo = 'pago_cliente')";

        $canal5Incapacidades = DB::table('incapacidades')
            ->where('incapacidades.aliado_id', $aid)
            ->whereNull('incapacidades.deleted_at')
            ->select([
                'incapacidades.id',
                'incapacidades.cedula_usuario',
                'incapacidades.estado',
                'incapacidades.valor_esperado',
                // Nombre completo del cliente por subconsulta TOP 1 única para evitar duplicidades
                DB::raw("(SELECT TOP 1 LTRIM(RTRIM(
                    ISNULL(c.primer_nombre,'') + ' ' + 
                    ISNULL(c.segundo_nombre,'') + ' ' + 
                    ISNULL(c.primer_apellido,'') + ' ' + 
                    ISNULL(c.segundo_apellido,'')
                )) FROM clientes c WHERE c.cedula = incapacidades.cedula_usuario AND c.aliado_id = {$aid}) as nombre_cliente"),
                // Total que entró de la EPS/ARL/AFP a la razón social
                DB::raw("{$sqlEntradas} as total_entradas_historico"),
                // Total que salió de la caja por esta incapacidad (neto + descuentos)
                DB::raw("{$sqlEgresos} as total_pagos_historico"),
                // Neto girado al afiliado, para el detalle de la fila
                DB::raw("{$sqlNetoAfil} as pago_neto_afiliado"),
            ])
            ->whereRaw("ABS({$sqlEntradas} - {$sqlEgresos}) >= 1000")
            ->get();

        $usuarios = DB::table('users')->where('aliado_id', $aid)->where('activo', 1)->orderBy('nombre')->get(['id', 'nombre']);
        $esAdmin = Auth::user()->hasRole('superadmin');

        return view('admin.informes.financiero', compact(
            'mes','anio','ingresos','egresos','utilidad',
            'usuarios', 'esAdmin',
            'recaudoSS','pagadoSSRaw','saldoSS',
            'saldoSSMesAnterior','recaudoSSPrev','pagadoSSPrev','mesAnt','anioAnt',
            'ingresosSS','egresosSSDetalle',
            'gapSS','gapResumen',
            'comisionesAsesor','gastosOp','tendencia','anterior','bancos','diario',
            'anticipos','cobradosAntes',
            'moraRecogida', 'saldoAsesores',
            'aid', 'efMes',
            'totalAnticiposDisponibles', 'cantAnticiposDisponibles',
            'desgloseAdmon', 'desgloseAfiliaciones', 'costoRetiros', 'ingRetiroCampo',
            'saldoTotalMesAnterior', 'abonosCobradosMes', 'abonosDetalleMes', 'abonosMesesAnteriores',
            'mesSig', 'anioSig', 'ssPrestamosMesSiguiente',
            'pagadoSSReg', 'pagadoSSRetiro', 'moraUtilizada', 'moraGanancia', 'sobrantePlanilla', 'subtotalRetiros', 'subtotalOperativo',
            'ssActuales', 'distRetiroAcumulado', 'retirosCobradosMesActual', 'totalSScanalRaw', 'totalSSMesActual', 'ssFuturasRegular', 'saldoPlanillas',
            'sobrantePlanillaProvisional', 'ssPendientePago', 'cantPlanillasPendientes',
            // Canal 5 Incapacidades
            'canal5EntradaMes', 'canal5PagoAfiliado', 'canal5Cuatropormil',
            'canal5OtrosDesc', 'canal5GananciaAdmon', 'canal5EgresosMes',
            'canal5SaldoMes', 'canal5SaldoAcumulado', 'canal5SaldoAnterior',
            'canal5Visible', 'canal5Incapacidades'
        ));
    }


    // ── PATCH: editar datos de una consignación (desde informe financiero) ────
    /**
     * PATCH /admin/informes/financiero/consignacion/{id}
     * Permite editar: fecha, valor, referencia, observacion de una consignación.
     * Registra el cambio en la bitácora.
     */
    public function editarConsignacion(Request $request, int $id)
    {
        $this->checkFinancieroEscritura();
        $aid = $this->aliadoId();

        $consig = \App\Models\Consignacion::where('id', $id)
            ->where('aliado_id', $aid)
            ->firstOrFail();

        $validated = $request->validate([
            'banco_cuenta_id' => [
                'required',
                'integer',
                \Illuminate\Validation\Rule::exists('banco_cuentas', 'id')->where(function ($query) use ($aid) {
                    $query->where('aliado_id', $aid);
                }),
            ],
            'fecha'       => 'required|date',
            'valor'       => 'required|numeric|min:1',
            'referencia'  => 'nullable|string|max:100',
            'observacion' => 'nullable|string|max:500',
            'estado'      => 'nullable|string|in:pendiente,verificado,no_aparece',
        ]);

        // Capturar valores anteriores para la bitácora
        $antes = [
            'banco_cuenta_id' => $consig->banco_cuenta_id,
            'fecha'           => $consig->fecha,
            'valor'           => $consig->valor,
            'referencia'      => $consig->referencia,
            'observacion'     => $consig->observacion,
            'confirmado'      => $consig->confirmado,
            'no_aparece'      => $consig->no_aparece,
        ];

        $updateData = [
            'banco_cuenta_id' => $validated['banco_cuenta_id'],
            'fecha'           => $validated['fecha'],
            'valor'           => (int)$validated['valor'],
            'referencia'      => $validated['referencia'] ?? null,
        ];

        // Procesar estado de validación si es admin/superadmin
        if (Auth::user()->hasRole(['admin', 'superadmin']) && isset($validated['estado'])) {
            $nuevoEstado = $validated['estado'];
            
            // Limpiar firmas anteriores de la observación enviada
            $observacionBase = $validated['observacion'] ?? '';
            $regexFirma = '/\s*\[Soporte\s*-\s*Validado\s*por:\s*[^\]]+\]/i';
            $observacionLimpia = preg_replace($regexFirma, '', $observacionBase);

            if ($nuevoEstado === 'verificado') {
                $updateData['confirmado'] = 1;
                $updateData['no_aparece'] = 0;
                
                // Si cambia a verificado, setear validador y estampar firma
                if (!$consig->confirmado) {
                    $updateData['usuario_validador_id'] = Auth::id();
                    $updateData['fecha_validacion'] = now();
                } else {
                    $updateData['usuario_validador_id'] = $consig->usuario_validador_id ?? Auth::id();
                    $updateData['fecha_validacion'] = $consig->fecha_validacion ?? now();
                }
                
                $nombreValidador = \App\Models\User::find($updateData['usuario_validador_id'])->nombre ?? Auth::user()->nombre;
                $updateData['observacion'] = trim($observacionLimpia) . ' [Soporte - Validado por: ' . $nombreValidador . ']';
            } elseif ($nuevoEstado === 'no_aparece') {
                $updateData['confirmado'] = 0;
                $updateData['no_aparece'] = 1;
                $updateData['usuario_validador_id'] = null;
                $updateData['fecha_validacion'] = null;
                $updateData['observacion'] = trim($observacionLimpia) ?: null;
            } else {
                // pendiente
                $updateData['confirmado'] = 0;
                $updateData['no_aparece'] = 0;
                $updateData['usuario_validador_id'] = null;
                $updateData['fecha_validacion'] = null;
                $updateData['observacion'] = trim($observacionLimpia) ?: null;
            }
        } else {
            $updateData['observacion'] = $validated['observacion'] ?? null;
        }

        $consig->update($updateData);
        $fresh = $consig->fresh();

        $despues = [
            'banco_cuenta_id' => $fresh->banco_cuenta_id,
            'fecha'           => $fresh->fecha,
            'valor'           => $fresh->valor,
            'referencia'      => $fresh->referencia,
            'observacion'     => $fresh->observacion,
            'confirmado'      => $fresh->confirmado,
            'no_aparece'      => $fresh->no_aparece,
        ];

        \App\Models\Bitacora::registrar(
            'updated',
            'Consignacion',
            $id,
            "Consignación #{$id} editada desde informe financiero. Factura #{$consig->factura_id}.",
            ['antes' => $antes, 'despues' => $despues],
            $aid
        );

        return response()->json([
            'ok'      => true,
            'mensaje' => 'Consignación actualizada correctamente.',
            'consig'  => [
                'id'              => $fresh->id,
                'banco_cuenta_id' => $fresh->banco_cuenta_id,
                'fecha'           => $fresh->fecha,
                'valor'           => $fresh->valor,
                'referencia'      => $fresh->referencia,
                'observacion'     => $fresh->observacion,
                'confirmado'      => $fresh->confirmado,
                'no_aparece'      => $fresh->no_aparece,
            ],
        ]);
    }

    // ── POST: subir imagen de soporte (desde informe financiero) ─────────────
    /**
     * POST /admin/informes/financiero/consignacion/{id}/imagen
     * Permite subir o reemplazar la imagen de soporte de una consignación.
     * Registra el evento en bitácora.
     */
    public function subirImagenConsignacionFinanciero(Request $request, int $id)
    {
        $this->checkFinancieroEscritura();
        $aid = $this->aliadoId();

        $consig = \App\Models\Consignacion::where('id', $id)
            ->where('aliado_id', $aid)
            ->firstOrFail();

        $request->validate([
            'imagen' => 'required|file|mimes:jpg,jpeg,png,pdf,webp|max:8192',
        ]);

        $habia = $consig->imagen_path;

        // Eliminar imagen anterior si existe
        if ($habia && \Storage::disk('public')->exists($habia)) {
            \Storage::disk('public')->delete($habia);
        }

        $file = $request->file('imagen');
        $ext  = $file->getClientOriginalExtension();
        $path = $file->storeAs(
            "consignaciones/{$aid}/{$consig->factura_id}",
            "{$id}_fin.{$ext}",
            'public'
        );

        $consig->update(['imagen_path' => $path]);

        \App\Models\Bitacora::registrar(
            'updated',
            'Consignacion',
            $id,
            "Imagen de soporte " . ($habia ? 'reemplazada' : 'subida') . " para consignación #{$id} (Factura #{$consig->factura_id}) desde informe financiero.",
            ['imagen_anterior' => $habia, 'imagen_nueva' => $path],
            $aid
        );

        return response()->json([
            'ok'  => true,
            'url' => \Storage::url($path),
        ]);
    }

    // ── JSON: detalle gastos operativos (para modal Egresos Operativos) ──────
    public function gastosDetalle(Request $request)
    {
        $this->checkFinanciero();
        $aid  = $this->aliadoId();
        $mes  = (int)$request->input('mes',  now()->month);
        $anio = (int)$request->input('anio', now()->year);

        $tiposIncapacidad = \App\Models\Gasto::TIPOS_INCAPACIDAD;
        $gastos = DB::table('gastos AS g')
            ->leftJoin('users AS u',         'u.id',  '=', 'g.usuario_id')
            ->leftJoin('banco_cuentas AS bc', 'bc.id', '=', 'g.banco_origen_id')
            ->where('g.aliado_id', $aid)
            ->where('g.tipo', '!=', 'pago_planilla')
            ->where('g.tipo', '!=', 'efectivo_banco')   // traslado interno
            ->where('g.forma_pago', '!=', 'banco_banco') // transferencia entre cuentas, no es egreso
            ->whereNotIn('g.tipo', $tiposIncapacidad)
            ->whereMonth('g.fecha', $mes)
            ->whereYear('g.fecha', $anio)
            ->select(
                'g.id', 'g.fecha', 'g.tipo', 'g.descripcion', 'g.pagado_a',
                'g.forma_pago', 'g.banco_origen_id', 'g.banco_destino_id',
                'g.valor', 'g.recibo_caja', 'g.observacion', 'g.imagen_path',
                'u.nombre AS usuario_nombre',
                'bc.banco AS banco_nombre', 'bc.nombre AS banco_titular'
            )
            ->orderByDesc('g.fecha')
            ->orderByDesc('g.id')
            ->get()
            ->map(function ($g) {
                $g->imagen_url = $g->imagen_path
                    ? \Storage::url($g->imagen_path)
                    : null;
                return $g;
            });

        return response()->json([
            'ok'     => true,
            'gastos' => $gastos,
            'total'  => $gastos->sum('valor'),
            'count'  => $gastos->count(),
        ]);
    }

    /**
     * Conciliación de bancos: movimientos del mes por cuenta (entradas por
     * consignación, salidas por gasto) contra el saldo calculado.
     *
     * Las acciones de escritura (confirmar, reversar, subir imagen) siguen
     * viviendo en CuadreDiarioController y verifican el rol internamente.
     */
    public function conciliacionBancos(Request $request)
    {
        $this->checkAdmin();

        set_time_limit(120); // protección ante meses con muchos movimientos

        $aliadoId = $this->aliadoId();
        $mes      = $request->input('mes', now()->format('Y-m'));
        [$anio, $mesNum] = explode('-', $mes);
        $inicio = "{$anio}-{$mesNum}-01";
        $fin    = date('Y-m-t', strtotime($inicio));

        $bancos   = BancoCuenta::where('aliado_id', $aliadoId)->where('activo', true)->get();
        $bancoIds = $bancos->pluck('id')->toArray();

        // ── 1. Cargar TODAS las consignaciones con nombres via JOIN (igual que financiero) ──
        // Un solo query con LEFT JOIN a clientes y empresas — replica exactamente
        // el patrón de InformeController::movimientosBancos() que funciona correctamente.
        $todasConsigRaw = DB::table('consignaciones AS cs')
            ->leftJoin('facturas AS f',  'f.id',  '=', 'cs.factura_id')
            ->leftJoin('clientes AS cl', function ($j) use ($aliadoId) {
                $j->on('cl.cedula', '=', 'f.cedula')
                  ->where('cl.aliado_id', $aliadoId);
            })
            ->leftJoin('empresas AS em', 'em.id', '=', 'f.empresa_id')
            ->leftJoin('users AS u',     'u.id',  '=', 'cs.usuario_id')
            ->where('cs.aliado_id', $aliadoId)
            ->whereIn('cs.banco_cuenta_id', $bancoIds)
            ->whereBetween('cs.fecha', [$inicio, $fin])
            ->selectRaw("
                cs.id,
                cs.banco_cuenta_id,
                cs.factura_id,
                cs.anticipo_id,
                cs.tipo,
                cs.referencia,
                cs.observacion,
                cs.valor,
                cs.confirmado,
                cs.no_aparece,
                cs.imagen_path,
                CONVERT(VARCHAR(10), cs.fecha, 120) AS fecha,
                f.numero_factura,
                f.empresa_id,
                CASE
                    WHEN f.empresa_id IS NOT NULL AND f.empresa_id > 0
                        THEN UPPER(ISNULL(em.empresa, '—'))
                    ELSE
                        LTRIM(RTRIM(
                            ISNULL(cl.primer_nombre,'') + ' ' +
                            ISNULL(cl.segundo_nombre,'') + ' ' +
                            ISNULL(cl.primer_apellido,'') + ' ' +
                            ISNULL(cl.segundo_apellido,'')
                        ))
                END AS nombre_cliente,
                u.nombre AS usuario_nombre
            ")
            ->orderByDesc('cs.fecha')
            ->orderByDesc('cs.id')
            ->get();

        // ── 2. Cargar anticipos relacionados (batch) ─────────────────────────
        $anticIds = $todasConsigRaw->where('tipo', 'anticipo')->pluck('anticipo_id')->filter()->unique();
        $anticipos = collect();
        if ($anticIds->isNotEmpty()) {
            $anticipos = \App\Models\Anticipo::whereIn('id', $anticIds)
                ->with(['factura', 'cliente', 'empresa'])
                ->get()
                ->keyBy('id');
        }

        // ── 3. Cargar TODOS los gastos del período (batch) ───────────────────
        // NOTA: gastos tipo 'pago_planilla' se guardan con forma_pago='transferencia'
        //       (no 'transferencia_bancaria'), por eso se incluyen explícitamente por tipo.
        $todasSalidas = \App\Models\Gasto::where('aliado_id', $aliadoId)
            ->whereIn('banco_origen_id', $bancoIds)
            ->where(function ($q) {
                $q->whereIn('forma_pago', ['transferencia_bancaria', 'banco_banco'])
                  ->orWhere('tipo', 'pago_planilla');   // ← pago_planilla usa forma_pago='transferencia'
            })
            ->whereBetween('fecha', [$inicio, $fin])
            ->with(['usuario', 'bancoDestino'])
            ->orderByDesc('fecha')->orderByDesc('id')
            ->get()
            ->groupBy('banco_origen_id');

        // ── 4. Pre-calcular saldos (uno por banco) ────────────────────────────
        // Dos saldos distintos y los dos hacen falta: el histórico —todo lo que
        // lleva la cuenta desde su apertura— y el del cierre del mes filtrado,
        // que es el que se compara contra el extracto.
        $saldosBanco = [];
        foreach ($bancoIds as $bid) {
            $saldosBanco[$bid] = \App\Models\Consignacion::saldoBanco($aliadoId, $bid);
        }

        // El saldo al cierre del mes se calcula para todas las cuentas de una,
        // no cuenta por cuenta: `saldoBancoAlFin` hace tres consultas cada vez
        // y con trece bancos eran ocho segundos de la pantalla.
        $saldosMes = $this->saldosAlCierre($aliadoId, $bancoIds, $fin);

        // Cuánto de lo que se movió en el mes quedó marcado como personal. En
        // las cuentas mixtas explica buena parte de la diferencia, así que se
        // muestra al lado en vez de dejar que el descuadre parezca un error.
        $personalMes = DB::table('banco_movimientos')
            ->whereIn('banco_cuenta_id', $bancoIds)
            ->where('clasificacion', \App\Models\BancoMovimiento::CLASIFICACION_PERSONAL)
            ->whereBetween('fecha', [$inicio, $fin])
            ->groupBy('banco_cuenta_id')
            ->selectRaw("banco_cuenta_id, SUM(CASE WHEN tipo = 'credito' THEN valor ELSE -valor END) AS neto, COUNT(*) AS n")
            ->get()
            ->keyBy('banco_cuenta_id');

        // Saldo que reporta el banco al cierre del mes: es el `saldo_despues`
        // del último movimiento del extracto. Sale en una sola consulta con
        // ROW_NUMBER porque hacerlo por cuenta serían trece viajes al servidor.
        $saldosExtracto = [];
        if ($bancoIds !== []) {
            $marcas = implode(',', array_fill(0, count($bancoIds), '?'));
            $filas = DB::select(
                "SELECT banco_cuenta_id, saldo_despues FROM (
                    SELECT banco_cuenta_id, saldo_despues,
                           ROW_NUMBER() OVER (PARTITION BY banco_cuenta_id ORDER BY fecha DESC, id DESC) AS rn
                    FROM banco_movimientos
                    WHERE banco_cuenta_id IN ($marcas)
                      AND fecha BETWEEN ? AND ?
                      AND saldo_despues IS NOT NULL
                 ) t WHERE rn = 1",
                array_merge($bancoIds, [$inicio, $fin])
            );
            foreach ($filas as $f) {
                $saldosExtracto[(int) $f->banco_cuenta_id] = (int) round((float) $f->saldo_despues);
            }
        }

        // ── 4-bis. Respaldo en el extracto del banco ─────────────────────────
        // Qué consignaciones tienen detrás un movimiento real del extracto. Se
        // trae en una consulta agregada y no con un JOIN a la consulta grande:
        // un cobro puede llegar partido en varias transferencias y el JOIN
        // duplicaría la fila de la consignación.
        $respaldoExtracto = DB::table('banco_movimiento_consignacion AS p')
            ->join('consignaciones AS cs2', 'cs2.id', '=', 'p.consignacion_id')
            ->where('p.aliado_id', $aliadoId)
            ->whereIn('cs2.banco_cuenta_id', $bancoIds)
            ->whereBetween('cs2.fecha', [$inicio, $fin])
            ->groupBy('p.consignacion_id')
            ->selectRaw('p.consignacion_id, COUNT(*) AS piezas, MIN(p.regla) AS regla, MAX(p.dias_diferencia) AS dias')
            ->get()
            ->keyBy('consignacion_id');

        // Lo mismo para las salidas: qué gastos tienen detrás un movimiento real
        // del extracto. Se agrega aparte por la misma razón que las entradas —un
        // pago grande sale partido en varias transferencias.
        $respaldoGastos = DB::table('banco_movimiento_gasto AS pg')
            ->join('gastos AS g2', 'g2.id', '=', 'pg.gasto_id')
            ->where('pg.aliado_id', $aliadoId)
            ->whereIn('g2.banco_origen_id', $bancoIds)
            ->whereBetween('g2.fecha', [$inicio, $fin])
            ->groupBy('pg.gasto_id')
            ->selectRaw('pg.gasto_id, COUNT(*) AS piezas, MIN(pg.regla) AS regla, MAX(pg.dias_diferencia) AS dias')
            ->get()
            ->keyBy('gasto_id');

        // Sin extracto cargado, «sin respaldo» no significa nada: hay que poder
        // distinguir «el banco no lo reporta» de «todavía no subieron el mes».
        $hayExtracto = DB::table('banco_movimientos')
            ->whereIn('banco_cuenta_id', $bancoIds)
            ->whereBetween('fecha', [$inicio, $fin])
            ->groupBy('banco_cuenta_id')
            ->selectRaw('banco_cuenta_id, COUNT(*) AS n')
            ->pluck('n', 'banco_cuenta_id');

        // ── 5. Agrupar y transformar por banco ────────────────────────────────
        $consigPorBanco = $todasConsigRaw->groupBy('banco_cuenta_id');

        $saldos = $bancos->map(function ($bc) use ($consigPorBanco, $todasSalidas, $saldosBanco, $saldosMes, $saldosExtracto, $personalMes, $anticipos, $respaldoExtracto, $respaldoGastos, $hayExtracto) {

            $extractoCargado = (int) ($hayExtracto[$bc->id] ?? 0) > 0;

            $movEntradas = ($consigPorBanco[$bc->id] ?? collect())->map(function ($c) use ($anticipos, $respaldoExtracto, $extractoCargado) {
                // nombre_cliente ya viene del CASE WHEN en SQL Server (igual que financiero)
                $pagador   = trim($c->nombre_cliente ?? '');
                $esEmpresa = ($c->empresa_id ?? 0) > 0;

                if ($pagador === '' || $pagador === '— — — —') $pagador = null;

                // Trazabilidad anticipo
                $anticFact = $anticFactNum = null;
                if (($c->tipo ?? '') === 'anticipo' && $c->anticipo_id) {
                    $ant = $anticipos[$c->anticipo_id] ?? null;
                    if ($ant) {
                        $anticFact    = $ant->factura_id;
                        $anticFactNum = $ant->factura?->numero_factura;

                        // Si no se obtuvo pagador del join (porque es anticipo disponible sin factura), resolverlo del anticipo
                        if (!$pagador) {
                            if ($ant->empresa) {
                                $pagador   = trim($ant->empresa->empresa);
                                $esEmpresa = true;
                            } elseif ($ant->cliente) {
                                $pagador   = trim($ant->cliente->nombre_completo);
                                $esEmpresa = false;
                            }
                        }
                    }
                }

                return (object)[
                    'id'                   => $c->id,
                    'cs_id'                => $c->id,
                    'fecha'                => $c->fecha,
                    'tipo'                 => $c->tipo ?? 'cliente',
                    'confirmado'           => (bool)$c->confirmado,
                    'no_aparece'           => (bool)($c->no_aparece ?? false),
                    'factura_id'           => $c->factura_id,
                    'num_factura'          => $c->numero_factura ?? $c->factura_id,
                    'anticipo_id'          => $c->anticipo_id,
                    'anticipo_factura_id'  => $anticFact,
                    'anticipo_factura_num' => $anticFactNum,
                    'pagador'              => $pagador ?: null,
                    'es_empresa'           => $esEmpresa,
                    'descripcion'          => match($c->tipo ?? 'cliente') {
                        'traslado_efectivo' => 'Traslado efectivo → banco',
                        'banco_recibido'    => 'Transferencia banco recibida',
                        'anticipo'          => 'Anticipo' . ($c->referencia ? ' · Ref: ' . $c->referencia : ''),
                        default             => $c->observacion,
                    },
                    'usuario'              => $c->usuario_nombre,
                    'valor'                => $c->valor,
                    'imagen_path'          => $c->imagen_path,
                    'imagen_url'           => $c->imagen_path ? \Storage::url($c->imagen_path) : null,
                    'es_salida'            => false,
                    'es_gasto'             => false,
                    'referencia'           => $c->referencia,
                    // Respaldo en el extracto del banco: cruzado, sin respaldo,
                    // o sin extracto cargado para ese mes.
                    'extracto'             => ($r = $respaldoExtracto[$c->id] ?? null)
                        ? ['estado' => 'cruzado', 'regla' => $r->regla, 'piezas' => (int) $r->piezas, 'dias' => (int) $r->dias]
                        : ['estado' => $extractoCargado ? 'sin_respaldo' : 'sin_extracto'],
                ];
            });

            $movSalidas = ($todasSalidas[$bc->id] ?? collect())->map(fn ($g) => (object) [
                'id'               => $g->id,
                'cs_id'            => null,
                'fecha'            => $g->fecha,
                'tipo'             => $g->tipo,
                'confirmado'       => true,
                'no_aparece'       => false,
                'factura_id'       => null,
                'num_factura'      => $g->tipo === 'pago_planilla' ? ($g->numero_planilla ?? null) : null,
                'pagador'          => $g->pagado_a,
                'descripcion'      => $g->tipo === 'pago_planilla'
                    ? '📋 Planilla SS' . ($g->numero_planilla ? ' #' . $g->numero_planilla : '')
                        . ($g->pagado_a ? ' · ' . $g->pagado_a : '')
                    : ($g->descripcion . ($g->bancoDestino ? ' → ' . $g->bancoDestino->banco : '')),
                'usuario'          => $g->usuario?->nombre,
                'valor'            => $g->valor,
                'imagen_path'      => $g->imagen_path,
                'imagen_url'       => $g->imagen_path ? \Storage::url($g->imagen_path) : null,
                'es_salida'        => true,
                'es_gasto'         => true,
                'extracto'         => ($rg = $respaldoGastos[$g->id] ?? null)
                    ? ['estado' => 'cruzado', 'regla' => $rg->regla, 'piezas' => (int) $rg->piezas, 'dias' => (int) $rg->dias]
                    : ['estado' => $extractoCargado ? 'sin_respaldo' : 'sin_extracto'],
                'es_planilla'      => $g->tipo === 'pago_planilla',
                'referencia'       => $g->numero_planilla ?? null,
            ]);

            $movimientos = $movEntradas->merge($movSalidas)
                ->sort(function ($a, $b) {
                    $fA = $a->fecha instanceof \Carbon\Carbon ? $a->fecha->toDateString() : $a->fecha;
                    $fB = $b->fecha instanceof \Carbon\Carbon ? $b->fecha->toDateString() : $b->fecha;
                    
                    if ($fA !== $fB) {
                        return strcmp($fB, $fA);
                    }
                    
                    // Si son del mismo día, entradas (es_salida = false) van primero que salidas (es_salida = true)
                    if ($a->es_salida !== $b->es_salida) {
                        return $a->es_salida ? 1 : -1;
                    }
                    
                    // Si son del mismo día y del mismo tipo, ordenar por valor de mayor a menor
                    return $b->valor <=> $a->valor;
                })
                ->values();

            return [
                'banco'          => $bc,
                'saldo'          => $saldosBanco[$bc->id] ?? 0,
                'saldo_mes'      => $saldosMes[$bc->id] ?? 0,
                // null cuando no se ha cargado el extracto del mes: ahí no hay
                // nada que comparar y la pantalla no debe inventar un cero.
                'saldo_extracto' => $saldosExtracto[$bc->id] ?? null,
                'personal_neto'  => (int) round((float) ($personalMes[$bc->id]->neto ?? 0)),
                'personal_n'     => (int) ($personalMes[$bc->id]->n ?? 0),
                'movimientos'    => $movimientos,
            ];
        });

        return view('admin.informes.conciliacion_bancos', compact('saldos', 'bancos', 'mes'));
    }

    /**
     * Saldo del libro al cierre de una fecha, para varias cuentas a la vez.
     *
     * Replica lo que hace `Consignacion::saldoBancoAlFin` —saldo de apertura
     * más consignaciones menos gastos— pero en dos consultas agrupadas en vez
     * de tres por cuenta. Cada cuenta arranca en su propia fecha de apertura,
     * y las que no tienen apertura suman desde el principio; eso es lo que
     * resuelve el CASE.
     *
     * @return array<int,int> saldo por banco_cuenta_id
     */
    private function saldosAlCierre(int $aliadoId, array $bancoIds, string $fechaFin): array
    {
        if ($bancoIds === []) {
            return [];
        }

        $aperturas = DB::table('saldos_banco')
            ->where('aliado_id', $aliadoId)
            ->whereIn('banco_cuenta_id', $bancoIds)
            ->where('tipo', 'saldo_inicial')
            ->where('fecha', '<=', $fechaFin)
            ->orderBy('fecha')
            ->get(['banco_cuenta_id', 'fecha', 'saldo_acumulado']);

        $base = [];
        $desde = [];
        foreach ($aperturas as $a) {
            $base[(int) $a->banco_cuenta_id] = (int) $a->saldo_acumulado;
            $desde[(int) $a->banco_cuenta_id] = $a->fecha;
        }

        // Sin apertura, la cuenta arranca en el corte del 1-jul-2026, igual que
        // `saldoBancoAlFin`. Esa fecha está quemada en el modelo desde el ajuste
        // de apertura; replicarla es lo que hace que los dos den lo mismo.
        $corte = '2026-06-30';
        $casos = '';
        $bind = [];
        foreach ($desde as $bid => $fecha) {
            $casos .= " WHEN $bid THEN ? ";
            $bind[] = $fecha;
        }
        $expr = $casos !== ''
            ? "CASE banco_cuenta_id $casos ELSE '$corte' END"
            : "'$corte'";

        $marcas = implode(',', array_fill(0, count($bancoIds), '?'));

        $entradas = DB::select(
            "SELECT banco_cuenta_id, ISNULL(SUM(CAST(valor AS BIGINT)), 0) AS total
             FROM consignaciones
             WHERE aliado_id = ? AND banco_cuenta_id IN ($marcas)
               AND deleted_at IS NULL AND fecha > $expr AND fecha <= ?
             GROUP BY banco_cuenta_id",
            array_merge([$aliadoId], $bancoIds, $bind, [$fechaFin])
        );

        $salidas = DB::select(
            "SELECT banco_origen_id AS banco_cuenta_id, ISNULL(SUM(CAST(valor AS BIGINT)), 0) AS total
             FROM gastos
             WHERE aliado_id = ? AND banco_origen_id IN ($marcas)
               AND tipo <> 'ajuste_apertura'
               AND fecha > ".str_replace('banco_cuenta_id', 'banco_origen_id', $expr)." AND fecha <= ?
             GROUP BY banco_origen_id",
            array_merge([$aliadoId], $bancoIds, $bind, [$fechaFin])
        );

        $saldos = [];
        foreach ($bancoIds as $bid) {
            $saldos[$bid] = $base[$bid] ?? 0;
        }
        foreach ($entradas as $e) {
            $saldos[(int) $e->banco_cuenta_id] = ($saldos[(int) $e->banco_cuenta_id] ?? 0) + (int) $e->total;
        }
        foreach ($salidas as $x) {
            $saldos[(int) $x->banco_cuenta_id] = ($saldos[(int) $x->banco_cuenta_id] ?? 0) - (int) $x->total;
        }

        return $saldos;
    }

    // ── JSON: movimientos de un banco ────────────────────────────────
    public function financieroBancos(Request $request)
    {
        $this->checkFinanciero();
        $aid      = $this->aliadoId();
        $bancoId  = (int)$request->input('banco_id');
        $mes      = (int)$request->input('mes', now()->month);
        $anio     = (int)$request->input('anio', now()->year);

        $entradas = DB::table('consignaciones AS cs')
            ->leftJoin('facturas AS f', 'f.id', '=', 'cs.factura_id')
            // Si empresa_id IS NULL → cliente individual → join clientes por cédula
            ->leftJoin('clientes AS cl', function ($j) use ($aid) {
                $j->on('cl.cedula', '=', 'f.cedula')
                  ->where('cl.aliado_id', $aid);
            })
            // Si empresa_id > 0 → empresa → join empresas por id
            ->leftJoin('empresas AS em', 'em.id', '=', 'f.empresa_id')
            ->where('cs.aliado_id', $aid)
            ->where('cs.banco_cuenta_id', $bancoId)
            ->whereMonth('cs.fecha', $mes)
            ->whereYear('cs.fecha', $anio)
            ->selectRaw("
                cs.id,
                cs.banco_cuenta_id,
                cs.observacion,
                CONVERT(VARCHAR(10), cs.fecha, 120) AS fecha,
                cs.created_at,
                cs.valor,
                cs.tipo,
                cs.referencia,
                cs.imagen_path,
                cs.confirmado,
                cs.no_aparece,
                cs.anticipo_id,
                f.numero_factura,
                f.empresa_id,
                CASE
                    WHEN f.empresa_id IS NOT NULL AND f.empresa_id > 0
                        THEN UPPER(ISNULL(em.empresa, '—'))
                    ELSE
                        LTRIM(RTRIM(
                            ISNULL(cl.primer_nombre,'') + ' ' +
                            ISNULL(cl.segundo_nombre,'') + ' ' +
                            ISNULL(cl.primer_apellido,'') + ' ' +
                            ISNULL(cl.segundo_apellido,'')
                        ))
                END AS nombre_cliente
            ")
            ->orderBy('cs.fecha')
            ->orderBy('cs.created_at')
            ->get();

        // Cargar anticipos en lote para resolver pagador
        $anticIds = $entradas->where('tipo', 'anticipo')->pluck('anticipo_id')->filter()->unique();
        $anticipos = collect();
        if ($anticIds->isNotEmpty()) {
            $anticipos = \App\Models\Anticipo::whereIn('id', $anticIds)
                ->with(['cliente', 'empresa'])
                ->get()
                ->keyBy('id');
        }

        $entradas = $entradas->map(function ($c) use ($anticipos) {
            $c->confirmado = (int)$c->confirmado;
            $c->no_aparece = (int)$c->no_aparece;

            $pagador = trim($c->nombre_cliente ?? '');
            if ($pagador === '' || $pagador === '— — — —') $pagador = null;

            if (($c->tipo ?? '') === 'anticipo' && $c->anticipo_id) {
                $ant = $anticipos[$c->anticipo_id] ?? null;
                if ($ant && !$pagador) {
                    if ($ant->empresa) {
                        $pagador = trim($ant->empresa->empresa);
                    } elseif ($ant->cliente) {
                        $pagador = trim($ant->cliente->nombre_completo);
                    }
                }
            }
            $c->nombre_cliente = $pagador ?: '—';
            return $c;
        });

        $salidas = DB::table('gastos')
            ->where('aliado_id', $aid)->where('banco_origen_id', $bancoId)
            ->whereMonth('fecha', $mes)->whereYear('fecha', $anio)
            ->where('tipo', '!=', 'efectivo_banco')   // traslados efectivo→banco no son salida del banco
            ->select('id', 'fecha', 'valor', 'tipo', 'descripcion', 'pagado_a', 'forma_pago', 'banco_origen_id', 'banco_destino_id', 'recibo_caja', 'observacion', 'imagen_path')
            ->orderBy('fecha')->get();

        return response()->json(['entradas' => $entradas, 'salidas' => $salidas]);
    }

    // ── JSON: movimientos en EFECTIVO del mes ────────────────────────
    public function financieroEfectivo(Request $request)
    {
        $this->checkFinanciero();
        $aid  = $this->aliadoId();
        $mes  = (int)$request->input('mes',  now()->month);
        $anio = (int)$request->input('anio', now()->year);

        // ── Entradas efectivo: UNA fila por numero_factura (excluye préstamos) ──
        // Estrategia: subquery interna agrupa solo por numero_factura (MIN/SUM),
        // query externa resuelve nombre cliente/empresa/asesor sin duplicados.
        $sub = DB::table('facturas AS f')
            ->where('f.aliado_id', $aid)
            ->whereNull('f.deleted_at')
            ->whereIn('f.estado', ['pagada', 'abono'])
            ->whereNotNull('f.fecha_pago')
            ->whereMonth('f.fecha_pago', $mes)
            ->whereYear('f.fecha_pago', $anio)
            ->where('f.valor_efectivo', '>', 0)
            ->where('f.es_prestamo', false)          // ← excluir préstamos
            ->groupBy('f.numero_factura')
            ->selectRaw("
                f.numero_factura,
                CONVERT(VARCHAR(10), MIN(f.fecha_pago), 120) AS fecha,
                SUM(f.valor_efectivo)                        AS valor,
                MIN(f.forma_pago)                            AS forma_pago,
                MIN(f.empresa_id)                            AS empresa_id,
                MIN(f.cedula)                                AS cedula,
                MIN(f.usuario_id)                            AS usuario_id
            ");

        $entradas = DB::table(DB::raw("({$sub->toSql()}) AS g"))
            ->mergeBindings($sub)
            ->selectRaw("
                g.numero_factura,
                g.fecha,
                g.valor,
                g.forma_pago,
                g.empresa_id,
                CASE
                    WHEN g.empresa_id IS NOT NULL AND g.empresa_id > 0
                        THEN UPPER(ISNULL(
                            (SELECT TOP 1 em.empresa FROM empresas em WHERE em.id = g.empresa_id),
                            '—'))
                    ELSE
                        ISNULL(
                            (SELECT TOP 1 LTRIM(RTRIM(
                                ISNULL(cl.primer_nombre,'') + ' ' +
                                ISNULL(cl.segundo_nombre,'') + ' ' +
                                ISNULL(cl.primer_apellido,'') + ' ' +
                                ISNULL(cl.segundo_apellido,'')
                            ))
                            FROM clientes cl
                            WHERE cl.cedula = g.cedula
                              AND cl.aliado_id = {$aid}),
                            g.cedula
                        )
                END AS nombre_cliente,
                ISNULL(
                    (SELECT TOP 1 u.nombre FROM users u WHERE u.id = g.usuario_id),
                    '—'
                ) AS usuario_nombre
            ")
            ->orderBy('g.fecha')
            ->orderBy('g.numero_factura')
            ->get();

        // ── Salidas efectivo: gastos con forma_pago efectivo del mes (excluye traslados) ──
        $salidas = DB::table('gastos')
            ->where('aliado_id', $aid)
            ->whereMonth('fecha', $mes)
            ->whereYear('fecha', $anio)
            ->where('forma_pago', 'efectivo')
            ->where('tipo', '!=', 'efectivo_banco')
            ->select('fecha', 'valor', 'tipo', 'descripcion', 'pagado_a')
            ->orderBy('fecha')
            ->get();

        // ── Abonos en efectivo del mes ──
        $abonos = DB::table('abonos as a')
            ->join('facturas as f', 'f.id', '=', 'a.factura_id')
            ->leftJoin('clientes as cl', function($j) use ($aid) {
                $j->on('cl.cedula', '=', 'f.cedula')->where('cl.aliado_id', $aid);
            })
            ->leftJoin('empresas as em', 'em.id', '=', 'f.empresa_id')
            ->leftJoin('users as u', 'u.id', '=', 'a.usuario_id')
            ->where('f.aliado_id', $aid)
            ->whereMonth('a.fecha', $mes)->whereYear('a.fecha', $anio)
            ->where('a.forma_pago', 'efectivo')
            ->where('a.valor_efectivo', '>', 0)
            ->selectRaw("
                f.numero_factura,
                CONVERT(VARCHAR(10), a.fecha, 120) AS fecha,
                a.valor_efectivo AS valor,
                a.forma_pago,
                f.empresa_id,
                CASE
                    WHEN f.empresa_id IS NOT NULL AND f.empresa_id > 0 THEN UPPER(ISNULL(em.empresa, '—'))
                    ELSE LTRIM(RTRIM(
                        ISNULL(cl.primer_nombre,'') + ' ' +
                        ISNULL(cl.segundo_nombre,'') + ' ' +
                        ISNULL(cl.primer_apellido,'') + ' ' +
                        ISNULL(cl.segundo_apellido,'')
                    ))
                END as nombre_cliente,
                ISNULL(u.nombre, '—') AS usuario_nombre
            ")
            ->orderBy('a.fecha')
            ->get();

        $entradas = $entradas->concat($abonos)->sortBy('fecha')->values();

        // ── Efectivo por asesor en el mes (facturas + anticipos + abonos del mes) ─────
        // Se agrupa por usuario del mes — así la suma de asesores = total ingresos.
        $efectivoFacturasAsesor = DB::table('facturas AS f')
            ->join('users AS u', 'u.id', '=', 'f.usuario_id')
            ->where('f.aliado_id', $aid)
            ->whereNull('f.deleted_at')
            ->whereIn('f.estado', ['pagada', 'abono'])
            ->whereNotNull('f.fecha_pago')
            ->whereMonth('f.fecha_pago', $mes)
            ->whereYear('f.fecha_pago', $anio)
            ->where('f.valor_efectivo', '>', 0)
            ->where('f.es_prestamo', false)
            ->groupBy('f.usuario_id', 'u.nombre')
            ->selectRaw('f.usuario_id, u.nombre AS asesor_nombre, SUM(f.valor_efectivo) AS ingresos_ef')
            ->get()
            ->keyBy('usuario_id');

        $efectivoAnticiposAsesor = DB::table('anticipos AS a')
            ->join('users AS u', 'u.id', '=', 'a.usuario_id')
            ->where('a.aliado_id', $aid)
            ->whereIn('a.forma_pago', ['efectivo', 'nequi'])
            ->whereMonth('a.fecha_pago', $mes)
            ->whereYear('a.fecha_pago', $anio)
            ->whereNull('a.deleted_at')
            ->whereNotIn('a.estado', ['devuelto', 'distribuido'])
            ->groupBy('a.usuario_id', 'u.nombre')
            ->selectRaw('a.usuario_id, u.nombre AS asesor_nombre, SUM(a.valor) AS anticipos_ef')
            ->get()
            ->keyBy('usuario_id');

        $efectivoAbonosAsesor = DB::table('abonos AS a')
            ->join('facturas AS f', 'f.id', '=', 'a.factura_id')
            ->join('users AS u', 'u.id', '=', 'a.usuario_id')
            ->where('f.aliado_id', $aid)
            ->whereMonth('a.fecha', $mes)
            ->whereYear('a.fecha', $anio)
            ->where('a.forma_pago', 'efectivo')
            ->where('a.valor_efectivo', '>', 0)
            ->groupBy('a.usuario_id', 'u.nombre')
            ->selectRaw('a.usuario_id, u.nombre AS asesor_nombre, SUM(a.valor_efectivo) AS abonos_ef')
            ->get()
            ->keyBy('usuario_id');

        $efectivoGastosAsesor = DB::table('gastos AS g')
            ->join('users AS u', 'u.id', '=', 'g.usuario_id')
            ->where('g.aliado_id', $aid)
            ->whereMonth('g.fecha', $mes)
            ->whereYear('g.fecha', $anio)
            ->where('g.forma_pago', 'efectivo')
            ->where('g.tipo', '!=', 'efectivo_banco')
            ->groupBy('g.usuario_id', 'u.nombre')
            ->selectRaw('g.usuario_id, u.nombre AS asesor_nombre, SUM(g.valor) AS gastos_ef')
            ->get()
            ->keyBy('usuario_id');

        $efectivoConsignacionesAsesor = DB::table('gastos AS g')
            ->join('users AS u', 'u.id', '=', 'g.usuario_id')
            ->where('g.aliado_id', $aid)
            ->whereMonth('g.fecha', $mes)
            ->whereYear('g.fecha', $anio)
            ->where('g.tipo', 'efectivo_banco')
            ->groupBy('g.usuario_id', 'u.nombre')
            ->selectRaw('g.usuario_id, u.nombre AS asesor_nombre, SUM(g.valor) AS consignaciones_ef')
            ->get()
            ->keyBy('usuario_id');

        // Combinar todos los usuario_ids que aparecen en cualquier flujo
        $todosUsuarios = $efectivoFacturasAsesor->keys()
            ->merge($efectivoAnticiposAsesor->keys())
            ->merge($efectivoGastosAsesor->keys())
            ->merge($efectivoConsignacionesAsesor->keys())
            ->merge($efectivoAbonosAsesor->keys())
            ->unique();

        $porAsesor = $todosUsuarios->map(function ($uid) use (
            $efectivoFacturasAsesor, $efectivoAnticiposAsesor, $efectivoGastosAsesor, $efectivoConsignacionesAsesor, $efectivoAbonosAsesor
        ) {
            $nombre     = $efectivoFacturasAsesor[$uid]->asesor_nombre
                       ?? $efectivoAnticiposAsesor[$uid]->asesor_nombre
                       ?? $efectivoGastosAsesor[$uid]->asesor_nombre
                       ?? $efectivoConsignacionesAsesor[$uid]->asesor_nombre
                       ?? $efectivoAbonosAsesor[$uid]->asesor_nombre
                       ?? '—';
            $ingresosEf = (float)($efectivoFacturasAsesor[$uid]->ingresos_ef ?? 0);
            $abonosEf   = (float)($efectivoAbonosAsesor[$uid]->abonos_ef ?? 0);
            $anticiposEf= (float)($efectivoAnticiposAsesor[$uid]->anticipos_ef ?? 0);
            $gastosEf   = (float)($efectivoGastosAsesor[$uid]->gastos_ef ?? 0);
            $consignacionesEf = (float)($efectivoConsignacionesAsesor[$uid]->consignaciones_ef ?? 0);

            $ingTotal = $ingresosEf + $abonosEf;

            return [
                'usuario_id'        => $uid,
                'asesor_nombre'     => $nombre,
                'ingresos_ef'       => $ingTotal,
                'anticipos_ef'      => $anticiposEf,
                'gastos_ef'         => $gastosEf,
                'consignaciones_ef' => $consignacionesEf,
                'saldo_caja'        => $ingTotal + $anticiposEf - $gastosEf - $consignacionesEf,
            ];
        })->sortBy('asesor_nombre')->values();

        // ── Totales reales usando la misma lógica que el desglose por asesor ──
        // Así el neto del card = TOTAL ASESORES de la tabla (facturas + anticipos - gastos)
        $totalFacturas       = (float)$entradas->sum('valor');
        $totalAnticipos      = (float)collect($porAsesor)->sum('anticipos_ef');
        $totalEntradas       = $totalFacturas + $totalAnticipos;
        $totalGastosReal     = (float)collect($porAsesor)->sum('gastos_ef');
        $totalConsignaciones = (float)collect($porAsesor)->sum('consignaciones_ef');
        $totalSalidas        = $totalGastosReal + $totalConsignaciones;
        $saldoEfectivo       = $totalEntradas - $totalSalidas;

        // Lista de anticipos para el desglose en el modal
        $anticiposDetalle = DB::table('anticipos AS a')
            ->where('a.aliado_id', $aid)
            ->whereIn('a.forma_pago', ['efectivo', 'nequi'])
            ->whereMonth('a.fecha_pago', $mes)
            ->whereYear('a.fecha_pago', $anio)
            ->whereNull('a.deleted_at')
            ->whereNotIn('a.estado', ['devuelto', 'distribuido'])
            ->leftJoin('users AS u', 'u.id', '=', 'a.usuario_id')
            ->selectRaw("
                a.id,
                CONVERT(VARCHAR(10), a.fecha_pago, 120) AS fecha,
                a.valor,
                a.forma_pago,
                a.referencia,
                a.estado,
                ISNULL(u.nombre, '—') AS usuario_nombre,
                CASE
                    WHEN a.empresa_id IS NOT NULL
                        THEN ISNULL((SELECT TOP 1 em.empresa FROM empresas em WHERE em.id = a.empresa_id), '—')
                    WHEN a.cedula IS NOT NULL
                        THEN ISNULL((SELECT TOP 1 LTRIM(RTRIM(
                            ISNULL(cl.primer_nombre,'') + ' ' +
                            ISNULL(cl.segundo_nombre,'') + ' ' +
                            ISNULL(cl.primer_apellido,'') + ' ' +
                            ISNULL(cl.segundo_apellido,'')
                        )) FROM clientes cl WHERE cl.cedula = a.cedula AND cl.aliado_id = {$aid}), a.cedula)
                    ELSE '—'
                END AS nombre_cliente
            ")
            ->orderBy('a.fecha_pago')
            ->get();

        return response()->json([
            'entradas'             => $entradas,
            'anticipos'            => $anticiposDetalle,
            'salidas'              => $salidas,
            'por_asesor'           => $porAsesor,
            'total_facturas'       => $totalFacturas,
            'total_anticipos'      => $totalAnticipos,
            'total_entradas'       => $totalEntradas,
            'total_salidas'        => $totalSalidas,
            'total_consignaciones' => $totalConsignaciones,
            'saldo_efectivo'       => $saldoEfectivo,
        ]);
    }

    // ── JSON: auditoría de un número de planilla ─────────────────────
    public function auditarPlanilla(Request $request)
    {
        $this->checkFinanciero();
        $aid          = $this->aliadoId();
        $numPlanilla  = trim($request->input('numero_planilla', ''));

        if (!$numPlanilla) {
            return response()->json(['error' => 'Número de planilla requerido.'], 422);
        }

        // ── 1. Gastos registrados para este número de planilla ────────
        // Busca TODOS los gastos (detectar pago duplicado si hay más de 1)
        $gastosAll = DB::table('gastos')
            ->where('aliado_id', $aid)
            ->where('tipo', 'pago_planilla')
            ->where('numero_planilla', $numPlanilla)
            ->orderBy('fecha')
            ->get();

        $cantGastos  = $gastosAll->count();
        $gasto       = $gastosAll->first();          // registro principal
        $gastoValor  = (float)$gastosAll->sum('valor'); // suma total (detecta dobles)
        $esDuplicado = $cantGastos > 1;

        // ── 2. Planos con ese numero_planilla (un plano = un empleado) ─
        $planos = DB::table('planos AS p')
            ->leftJoin('facturas AS f',         'f.id',  '=', 'p.factura_id')
            ->leftJoin('razones_sociales AS rs', 'rs.id', '=', 'p.razon_social_id')
            ->where('p.aliado_id', $aid)
            ->whereNull('p.deleted_at')
            ->where('p.numero_planilla', $numPlanilla)
            ->select([
                'p.id',
                'p.no_identifi',
                DB::raw("LTRIM(RTRIM(ISNULL(p.primer_nombre,'')+' '+ISNULL(p.segundo_nombre,'')+' '+ISNULL(p.primer_ape,'')+' '+ISNULL(p.segundo_ape,''))) AS nombre_completo"),
                'p.razon_social_id',
                DB::raw("ISNULL(rs.razon_social, p.razon_social) AS empresa_nombre"),
                'rs.nit AS empresa_nit',
                'p.n_plano',
                'p.mes_plano',
                'p.anio_plano',
                'p.num_dias',
                'p.tipo_reg',
                'f.id AS factura_id',
                'f.numero_factura',
                'f.v_eps', 'f.v_afp', 'f.v_arl', 'f.v_caja', 'f.total_ss', 'f.mora',
            ])
            ->orderBy('rs.razon_social')
            ->orderBy('p.primer_ape')
            ->get();

        // ── 3. Totales SS cobrados a clientes (desde facturas) ────────
        // Separar facturas regulares (numero_factura > 0) de retiros (numero_factura = 0)
        // La columna "SS Cobrado" de la tabla usa solo numero_factura > 0
        $planosRegulares = $planos->filter(fn($p) => (int)($p->numero_factura ?? 0) > 0);
        $planosRetiros   = $planos->filter(fn($p) => (int)($p->numero_factura ?? 0) === 0);

        $totalSSFacturas   = (float)$planosRegulares->sum('total_ss'); // == valor columna tabla
        $totalSSRetiros    = (float)$planosRetiros->sum('total_ss');   // retiros (numero_factura=0)
        $totalMora         = (float)$planos->sum('mora');              // mora de todas las facturas de la planilla
        $totalSSTodos      = $totalSSFacturas + $totalSSRetiros + $totalMora;       // suma completa

        $totalEPS          = (float)$planosRegulares->sum('v_eps');
        $totalAFP          = (float)$planosRegulares->sum('v_afp');
        $totalARL          = (float)$planosRegulares->sum('v_arl');
        $totalCaja         = (float)$planosRegulares->sum('v_caja');

        // La diferencia se calcula contra el total (regulares + retiros + mora) para cuadrar con el gasto real
        $diferencia        = $totalSSTodos - $gastoValor;

        return response()->json([
            'numero_planilla'      => $numPlanilla,
            'es_duplicado'         => $esDuplicado,
            'cant_gastos'          => $cantGastos,
            'gastos_detalle'       => $gastosAll,   // lista completa (para mostrar duplicados)
            'gasto'                => $gasto,
            'gasto_valor'          => $gastoValor,
            'total_ss_facturas'    => $totalSSFacturas,   // facturas regulares (numero_factura > 0)
            'total_ss_retiros'     => $totalSSRetiros,    // retiros (numero_factura = 0)
            'total_mora'           => $totalMora,         // mora recogida
            'total_ss_todos'       => $totalSSTodos,      // suma completa
            'total_eps'            => $totalEPS,
            'total_afp'            => $totalAFP,
            'total_arl'            => $totalARL,
            'total_caja'           => $totalCaja,
            'diferencia'           => $diferencia,
            'cant_empleados'       => $planos->count(),
            'planos'               => $planos,
        ]);
    }

    // ── Todas las facturas ligadas a planillas de gastos de un mes ──────
    public function ssPlanillas(Request $request)
    {
        $this->checkFinanciero();
        $aid  = $this->aliadoId();
        $mes  = (int)$request->input('mes',  now()->month);
        $anio = (int)$request->input('anio', now()->year);

        // 1. Gastos pago_planilla del mes (agrupados por numero_planilla)
        $gastos = DB::table('gastos AS g')
            ->leftJoin('banco_cuentas AS bc', 'bc.id', '=', 'g.banco_origen_id')
            ->where('g.aliado_id', $aid)
            ->where('g.tipo', 'pago_planilla')
            ->whereMonth('g.fecha', $mes)
            ->whereYear('g.fecha', $anio)
            ->selectRaw('
                g.numero_planilla,
                g.descripcion,
                g.pagado_a,
                MAX(g.fecha)   AS fecha_gasto,
                SUM(g.valor)   AS gasto_total,
                COUNT(*)       AS cant_gastos,
                MAX(bc.banco)  AS banco_nombre
            ')
            ->groupBy('g.numero_planilla', 'g.descripcion', 'g.pagado_a')
            ->orderBy('g.numero_planilla')
            ->get();

        $numeros = $gastos->pluck('numero_planilla')->filter()->unique()->values();

        // 2. Todas las facturas ligadas a esos numero_planilla (sin filtrar por período)
        //    Una factura puede ser de cualquier mes/año — eso es lo que queremos ver
        $facturasRaw = DB::table('planos AS p')
            ->join('facturas AS f', 'f.id', '=', 'p.factura_id')
            ->leftJoin('razones_sociales AS rs', 'rs.id', '=', 'p.razon_social_id')
            ->where('p.aliado_id', $aid)
            ->whereNull('p.deleted_at')
            ->whereNull('f.deleted_at')
            ->whereIn('p.numero_planilla', $numeros)
            ->selectRaw('
                p.numero_planilla,
                f.id             AS factura_id,
                f.numero_factura,
                f.mes            AS f_mes,
                f.anio           AS f_anio,
                f.estado,
                f.fecha_pago,
                SUM(f.total_ss)  AS total_ss,
                SUM(f.v_eps)     AS v_eps,
                SUM(f.v_afp)     AS v_afp,
                SUM(f.v_arl)     AS v_arl,
                SUM(f.v_caja)    AS v_caja,
                COUNT(p.id)      AS cant_empleados,
                MAX(ISNULL(rs.razon_social, p.razon_social)) AS razon_social
            ')
            ->groupBy(
                'p.numero_planilla',
                'f.id', 'f.numero_factura',
                'f.mes', 'f.anio', 'f.estado', 'f.fecha_pago'
            )
            ->orderBy('p.numero_planilla')
            ->orderBy('f.anio')
            ->orderBy('f.mes')
            ->get()
            ->groupBy('numero_planilla');

        // 3. Planillas en gastos que NO tienen ningún plano/factura ligado
        $sinPlanos = DB::table('gastos AS g')
            ->where('g.aliado_id', $aid)
            ->where('g.tipo', 'pago_planilla')
            ->whereMonth('g.fecha', $mes)
            ->whereYear('g.fecha', $anio)
            ->whereIn('g.numero_planilla', $numeros)
            ->whereNotExists(function ($q) use ($aid) {
                $q->select(DB::raw(1))
                  ->from('planos AS p2')
                  ->whereRaw('p2.numero_planilla = g.numero_planilla')
                  ->where('p2.aliado_id', $aid)
                  ->whereNull('p2.deleted_at');
            })
            ->selectRaw('g.numero_planilla, g.descripcion, g.pagado_a, MAX(g.fecha) AS fecha_gasto, SUM(g.valor) AS gasto_total')
            ->groupBy('g.numero_planilla', 'g.descripcion', 'g.pagado_a')
            ->get();

        // 4. Ensamblar: por cada gasto, sus facturas clasificadas (mismo período / otro período)
        $mesesEs = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

        $resumen = $gastos->map(function ($g) use ($facturasRaw, $mes, $anio, $mesesEs) {
            $facturas = collect($facturasRaw->get($g->numero_planilla, []));

            $ssMismoPeriodo = $facturas->where('f_mes', $mes)->where('f_anio', $anio)->where('numero_factura', '>', 0)->sum('total_ss');
            $ssOtroPeriodo  = $facturas->where(fn($f) => !($f->f_mes == $mes && $f->f_anio == $anio))->where('numero_factura', '>', 0)->sum('total_ss');
            $ssRetiros      = $facturas->where('numero_factura', 0)->sum('total_ss');
            $ssTotalCobrado = $ssMismoPeriodo + $ssOtroPeriodo + $ssRetiros;
            $diferencia     = $g->gasto_total - $ssTotalCobrado;

            return [
                'numero_planilla'  => $g->numero_planilla,
                'descripcion'      => $g->descripcion ?: $g->pagado_a,
                'pagado_a'         => $g->pagado_a,
                'fecha_gasto'      => $g->fecha_gasto,
                'banco'            => $g->banco_nombre,
                'gasto_total'      => (float)$g->gasto_total,
                'cant_gastos'      => (int)$g->cant_gastos,
                'ss_mismo_periodo' => (float)$ssMismoPeriodo,
                'ss_otro_periodo'  => (float)$ssOtroPeriodo,
                'ss_retiros'       => (float)$ssRetiros,
                'ss_total_cobrado' => (float)$ssTotalCobrado,
                'diferencia'       => (float)$diferencia,
                'facturas'         => $facturas->map(fn($f) => [
                    'factura_id'      => $f->factura_id,
                    'numero_factura'  => $f->numero_factura,
                    'periodo'         => ($mesesEs[$f->f_mes] ?? $f->f_mes) . ' ' . $f->f_anio,
                    'f_mes'           => $f->f_mes,
                    'f_anio'          => $f->f_anio,
                    'estado'          => $f->estado,
                    'fecha_pago'      => $f->fecha_pago,
                    'es_retiro'       => $f->numero_factura == 0,
                    'es_otro_periodo' => !($f->f_mes == $mes && $f->f_anio == $anio),
                    'total_ss'        => (float)$f->total_ss,
                    'v_eps'           => (float)$f->v_eps,
                    'v_afp'           => (float)$f->v_afp,
                    'v_arl'           => (float)$f->v_arl,
                    'v_caja'          => (float)$f->v_caja,
                    'cant_empleados'  => (int)$f->cant_empleados,
                    'razon_social'    => $f->razon_social,
                ])->values()->toArray(),
            ];
        });

        // Totales globales
        $totales = [
            'gasto_total'      => (float)$resumen->sum('gasto_total'),
            'ss_mismo_periodo' => (float)$resumen->sum('ss_mismo_periodo'),
            'ss_otro_periodo'  => (float)$resumen->sum('ss_otro_periodo'),
            'ss_retiros'       => (float)$resumen->sum('ss_retiros'),
            'ss_total_cobrado' => (float)$resumen->sum('ss_total_cobrado'),
            'diferencia'       => (float)$resumen->sum('diferencia'),
            'cant_planillas'   => $resumen->count(),
        ];

        return view('admin.informes.ss_planillas', compact(
            'mes', 'anio', 'resumen', 'totales', 'sinPlanos', 'mesesEs'
        ));
    }

    // ── Vista de Auditoría de Facturas ────────────────────────────────
    public function auditoriaFacturas(Request $request)
    {
        $this->checkAdmin();
        $aid  = $this->aliadoId();
        // $mes  = período de facturación (no tiene default, el usuario lo filtra desde la columna)
        $mes  = $request->has('mes') && $request->input('mes') !== ''
                ? (int)$request->input('mes') : null;
        $anio    = (int)$request->input('anio',    now()->year);
        $tipo    = $request->input('tipo',          'todos');
        $estado  = $request->input('estado',        'todos');
        $forma   = $request->input('forma',         'todos');
        $cobro   = $request->input('cobro',         'todos');
        $asId    = $request->input('asesor_id',     'todos');
        // mes_pago: mes del PAGO — default = mes actual al entrar por primera vez
        $mesPago = $request->has('mes_pago')
                   ? $request->input('mes_pago', 'todos')
                   : (string)now()->month;
        $sortDir = $request->input('sort_dir',     'desc');
        $buscar  = trim($request->input('buscar',  ''));
        $banco   = $request->input('banco',          'todos');  // filtro por banco_cuentas.banco

        // ── Closure de filtros reutilizable ──────────────────────────
        $applyFiltros = function($q) use ($mes,$tipo,$estado,$forma,$cobro,$asId,$mesPago,$buscar,$banco) {
            if ($mes)                                  { $q->where('f.mes', $mes); }
            if ($mesPago !== '' && $mesPago !== 'todos') { $q->whereMonth('f.fecha_pago', (int)$mesPago); }
            if ($tipo   !== 'todos') { $q->where('f.tipo',       $tipo); }
            if ($estado !== 'todos') {
                if ($estado === 'retiro') {
                    $q->where('f.numero_factura', 0)->where('f.total', 0);
                } else {
                    $q->where('f.estado', $estado);
                    if ($estado === 'pagada') {
                        $q->where(function($sq) {
                            $sq->where('f.numero_factura', '<>', 0)
                               ->orWhere('f.total', '<>', 0);
                        });
                    }
                }
            }
            if ($forma  !== 'todos') { $q->where('f.forma_pago', $forma); }
            if ($cobro === 'consignado') { $q->where('f.valor_consignado',  '>', 0); }
            if ($cobro === 'efectivo')   { $q->where('f.valor_efectivo',    '>', 0); }
            if ($cobro === 'prestamo')   { $q->where('f.valor_prestamo',    '>', 0); }
            if ($cobro === 'anticipo')   { $q->where('f.anticipo_aplicado', '>', 0); }
            if ($asId   !== 'todos')     { $q->where('f.usuario_id', $asId); }
            if ($buscar !== '') {
                $q->where(function($sq) use ($buscar) {
                    $sq->where('f.cedula',         'like', "%{$buscar}%")
                       ->orWhere('f.numero_factura','like', "%{$buscar}%")
                       ->orWhere('f.np',           'like', "%{$buscar}%");
                });
            }
            if ($banco !== 'todos') {
                $q->whereExists(function($sq) use ($banco) {
                    $sq->from('consignaciones AS cs')
                       ->join('banco_cuentas AS bc', 'bc.id', '=', 'cs.banco_cuenta_id')
                       ->join('facturas AS fx', 'fx.id', '=', 'cs.factura_id')
                       ->whereRaw("
                           (
                               fx.id = f.id
                               OR (
                                   fx.numero_factura = f.numero_factura 
                                   AND fx.numero_factura <> '0'
                                   AND fx.numero_factura IS NOT NULL
                                   AND fx.aliado_id = f.aliado_id
                                   AND fx.anio = f.anio
                                   AND fx.deleted_at IS NULL
                               )
                           )
                       ")
                       ->whereRaw('UPPER(bc.nombre) = UPPER(?)', [$banco]);
                });
            }
        };

        // ── Query principal ───────────────────────────────────────────


        $q = DB::table('facturas AS f')
            ->leftJoin('contratos AS c', 'c.id', '=', 'f.contrato_id')
            ->where('f.aliado_id', $aid)
            ->whereNull('f.deleted_at')
            ->where('f.anio', $anio);

        $applyFiltros($q);

        $q->selectRaw("
            f.id,
            f.numero_factura,
            CONVERT(VARCHAR(10), f.fecha_pago, 120)  AS fecha_pago,
            f.mes,
            f.anio,
            f.tipo,
            f.estado,
            f.cedula,
            f.empresa_id,
            f.contrato_id,
            f.np,
            f.forma_pago,
            f.es_prestamo,
            f.usuario_id,
            ISNULL(f.valor_consignado,  0) AS valor_consignado,
            ISNULL(f.valor_efectivo,    0) AS valor_efectivo,
            ISNULL(f.valor_prestamo,    0) AS valor_prestamo,
            ISNULL(f.anticipo_aplicado, 0) AS anticipo_aplicado,
            ISNULL(f.admon,       0) AS admon,
            ISNULL(f.admin_asesor,0) AS admin_asesor,
            ISNULL(f.seguro,      0) AS seguro,
            ISNULL(f.mensajeria,  0) AS mensajeria,
            ISNULL(f.iva,         0) AS iva,
            ISNULL(f.otros,       0) AS otros,
            ISNULL(f.retiro,      0) AS retiro,
            ISNULL(f.mora,        0) AS mora,
            ISNULL(f.afiliacion,  0) AS afiliacion,
            ISNULL(f.v_eps,       0) AS v_eps,
            ISNULL(f.v_afp,       0) AS v_afp,
            ISNULL(f.v_arl,       0) AS v_arl,
            ISNULL(f.v_caja,      0) AS v_caja,
            ISNULL(f.total_ss,    0) AS total_ss,
            ISNULL(f.c_asesor,    0) AS c_asesor,
            ISNULL(f.c_utilidad,  0) AS c_utilidad,
            ISNULL(f.total,       0) AS total,
            ISNULL(f.saldo_proximo, 0) AS saldo_proximo,
            ISNULL(c.administracion, 0) AS contrato_admon,
            ISNULL(c.admon_asesor,   0) AS contrato_admon_asesor,
            ISNULL(c.costo_afiliacion, 0) AS contrato_costo_afiliacion,
            ISNULL(c.seguro,           0) AS contrato_seguro,
            (SELECT COUNT(*) FROM bitacora b WHERE b.modelo = 'Contrato' AND b.registro_id = f.contrato_id AND b.accion = 'updated' AND b.descripcion LIKE '%Tarifas%') AS cant_cambios_contrato,
            CASE
                WHEN f.empresa_id IS NOT NULL
                    THEN ISNULL((SELECT TOP 1 em.empresa FROM empresas em WHERE em.id = f.empresa_id), '—')
                WHEN f.cedula IS NOT NULL
                    THEN ISNULL((SELECT TOP 1 LTRIM(RTRIM(
                        ISNULL(cl.primer_nombre,'') + ' ' + ISNULL(cl.primer_apellido,'')
                    )) FROM clientes cl WHERE cl.cedula = f.cedula AND cl.aliado_id = {$aid}), f.cedula)
                ELSE '—'
            END AS nombre_cliente,
            ISNULL((SELECT TOP 1 u.nombre FROM users u WHERE u.id = f.usuario_id), '—') AS asesor_nombre,
            ISNULL((SELECT TOP 1 bc.nombre
                    FROM consignaciones cs
                    JOIN banco_cuentas bc ON bc.id = cs.banco_cuenta_id
                    JOIN facturas fx ON fx.id = cs.factura_id
                    WHERE (
                        fx.id = f.id
                        OR (
                            fx.numero_factura = f.numero_factura 
                            AND fx.numero_factura <> '0'
                            AND fx.numero_factura IS NOT NULL
                            AND fx.aliado_id = f.aliado_id
                            AND fx.anio = f.anio
                            AND fx.deleted_at IS NULL
                        )
                    )
                    ORDER BY cs.id), NULL) AS nombre_banco
        ");

        if ($sortDir === 'asc') {
            $q->orderBy('f.fecha_pago')->orderBy('f.numero_factura');
        } else {
            $q->orderByDesc('f.fecha_pago')->orderByDesc('f.numero_factura');
        }
        $facturas = $q->get();

        // ── Totalizadores ─────────────────────────────────────────────
        $qTot = DB::table('facturas AS f')
            ->where('f.aliado_id', $aid)
            ->whereNull('f.deleted_at')
            ->where('f.anio', $anio);
        $applyFiltros($qTot);
        $tots = $qTot->selectRaw("
            COUNT(*)                                AS cant,
            SUM(ISNULL(f.total,          0))        AS total,
            SUM(ISNULL(f.valor_consignado,  0))     AS tot_consig,
            SUM(ISNULL(f.valor_efectivo,    0))     AS tot_efect,
            SUM(ISNULL(f.valor_prestamo,    0))     AS tot_prestamo,
            SUM(ISNULL(f.anticipo_aplicado, 0))     AS tot_anticipo,
            SUM(ISNULL(f.admon,       0))           AS tot_admon,
            SUM(ISNULL(f.seguro,      0))           AS tot_seguro,
            SUM(ISNULL(f.mensajeria,  0))           AS tot_mensajeria,
            SUM(ISNULL(f.iva,         0))           AS tot_iva,
            SUM(ISNULL(f.otros,       0))           AS tot_otros,
            SUM(ISNULL(f.retiro,      0))           AS tot_retiro,
            SUM(ISNULL(f.mora,        0))           AS tot_mora,
            SUM(ISNULL(f.afiliacion,  0))           AS tot_afiliacion,
            SUM(ISNULL(f.v_eps,       0))           AS tot_eps,
            SUM(ISNULL(f.v_afp,       0))           AS tot_afp,
            SUM(ISNULL(f.v_arl,       0))           AS tot_arl,
            SUM(ISNULL(f.v_caja,      0))           AS tot_caja,
            SUM(ISNULL(f.total_ss,    0))           AS tot_ss,
            SUM(ISNULL(f.c_asesor,    0))           AS tot_asesor,
            SUM(ISNULL(f.c_utilidad,  0))           AS tot_utilidad
        ")->first();

        $meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio',
                  'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

        // ── Opciones para dropdowns ───────────────────────────────────
        $baseOpc = DB::table('facturas')->where('aliado_id', $aid)
            ->whereNull('deleted_at')->where('anio', $anio);

        $opcionesForma = (clone $baseOpc)->whereNotNull('forma_pago')
            ->distinct()->orderBy('forma_pago')->pluck('forma_pago');

        $opcionesPeriodo = (clone $baseOpc)
            ->selectRaw('DISTINCT mes AS nmes')->orderByRaw('mes')->pluck('nmes');

        $opcionesTipo = (clone $baseOpc)->whereNotNull('tipo')
            ->distinct()->orderBy('tipo')->pluck('tipo');

        $opcionesAsesor = DB::table('facturas AS f')
            ->where('f.aliado_id', $aid)->whereNull('f.deleted_at')
            ->where('f.anio', $anio)->whereNotNull('f.usuario_id')
            ->join('users AS u', 'u.id', '=', 'f.usuario_id')
            ->selectRaw('DISTINCT f.usuario_id AS id, u.nombre')
            ->orderBy('u.nombre')->get();

        // Bancos disponibles en la consulta actual (ya con filtros aplicados)
        $qBancos = DB::table('facturas AS f')
            ->where('f.aliado_id', $aid)->whereNull('f.deleted_at')->where('f.anio', $anio);
        $applyFiltros($qBancos);
        $opcionesBanco = $qBancos
            ->join('consignaciones AS cs', 'cs.factura_id', '=', 'f.id')
            ->join('banco_cuentas AS bc', 'bc.id', '=', 'cs.banco_cuenta_id')
            ->whereNotNull('bc.nombre')->where('bc.nombre', '<>', '')
            ->selectRaw('DISTINCT UPPER(bc.nombre) AS banco')
            ->orderBy('banco')->pluck('banco');

        return view('admin.informes.auditoria_facturas', compact(
            'facturas','tots','mes','anio','tipo','estado','forma','cobro','asId',
            'mesPago','sortDir','buscar','banco',
            'meses','opcionesForma','opcionesPeriodo','opcionesTipo','opcionesAsesor','opcionesBanco'
        ));
    }


    private function desgloseDiario(int $aid, int $mes, int $anio): array
    {
        $factDia = DB::table('facturas')
            ->where('aliado_id',$aid)->whereNull('deleted_at')
            ->whereNotNull('fecha_pago')
            ->whereMonth('fecha_pago',$mes)->whereYear('fecha_pago',$anio)
            ->whereIn('estado',['pagada','abono','prestamo'])
            ->selectRaw('DAY(fecha_pago) AS dia, tipo,
                COUNT(*) AS cant_filas,
                SUM(admon+seguro+mensajeria+ISNULL(otros_admon,0)+iva+retiro) AS ing_planilla,
                SUM(afiliacion+admon+seguro+iva) AS ing_afil,
                SUM(admon+otros) AS ing_tramite,
                SUM(mora) AS mora_dia,
                SUM(ISNULL(dist_retiro,0)) AS dist_retiro_dia,
                SUM(ISNULL(dist_asesor,0)) AS dist_asesor_dia,
                SUM(ISNULL(dist_encargado,0)) AS dist_encargado_dia,
                SUM(CASE WHEN numero_factura > 0 THEN total_ss + otros ELSE 0 END) AS ss_dia')
            ->groupByRaw('DAY(fecha_pago), tipo')
            ->get()->groupBy('dia');

        $tiposIncapacidad = \App\Models\Gasto::TIPOS_INCAPACIDAD;
        $gastosDia = DB::table('gastos')
            ->where('aliado_id',$aid)
            ->where('tipo','!=','pago_planilla')
            ->where('tipo','!=','efectivo_banco')   // traslado interno, no es egreso real
            ->where('forma_pago','!=','banco_banco') // transferencia entre cuentas, no es egreso
            ->whereNotIn('tipo', $tiposIncapacidad)
            ->whereMonth('fecha',$mes)->whereYear('fecha',$anio)
            ->selectRaw('DAY(fecha) AS dia, ISNULL(SUM(CAST(valor AS BIGINT)), 0) AS total')
            ->groupByRaw('DAY(fecha)')
            ->pluck('total','dia');

        $diasEnMes = now()->setDate($anio,$mes,1)->daysInMonth;
        $resultado = [];
        for($d=1;$d<=$diasEnMes;$d++){
            $filas           = $factDia->get($d, collect());
            $filaPlan        = $filas->where('tipo','planilla')->first();
            $filaAfil        = $filas->where('tipo','afiliacion')->first();
            $filaTramite     = $filas->where('tipo','otro_ingreso')->first();
            $planillas       = (float)($filaPlan->ing_planilla    ?? 0);
            $afil            = (float)($filaAfil->ing_afil        ?? 0);
            $tramites        = (float)($filaTramite->ing_tramite  ?? 0);
            $cantPlanillas   = (int)($filaPlan->cant_filas        ?? 0);
            $cantAfiliaciones= (int)($filaAfil->cant_filas        ?? 0);
            $ssDia           = (float)($filaPlan->ss_dia          ?? 0);
            $moraDia         = (float)($filas->sum('mora_dia'));
            $distRetiroDia   = (float)($filaAfil->dist_retiro_dia ?? 0);
            $distAsesorDia   = (float)($filaAfil->dist_asesor_dia ?? 0);
            $distEncargadoDia = (float)($filaAfil->dist_encargado_dia ?? 0);
            $gastos          = (int)($gastosDia[$d] ?? 0);
            $afilNeto = $afil;
            $fechaFiltro = \Carbon\Carbon::createFromDate($anio, $mes, $d);
            $fechaCorte = \Carbon\Carbon::createFromDate(2026, 7, 1);
            if ($fechaFiltro->greaterThanOrEqualTo($fechaCorte)) {
                $afilNeto = max(0.0, $afil - $distAsesorDia - $distRetiroDia - $distEncargadoDia);
            }
            $resultado[] = [
                'dia'               => $d,
                'cant_planillas'    => $cantPlanillas,
                'planillas'         => $planillas,
                'cant_afiliaciones' => $cantAfiliaciones,
                'afiliaciones'      => $afilNeto,
                'tramites'          => $tramites,
                'ss'                => $ssDia,
                'gastos'            => $gastos,
                'utilidad'          => $planillas + $afilNeto + $tramites + $moraDia - $gastos,
            ];
        }
        return $resultado;
    }

    // ── Helper: resumen de un mes ─────────────────────────────────────
    private function resumenMes(int $aid, int $mes, int $anio): array
    {
        $factData = DB::table('facturas')
            ->where('aliado_id', $aid)->whereNull('deleted_at')
            ->whereNotNull('fecha_pago')
            ->whereMonth('fecha_pago', $mes)->whereYear('fecha_pago', $anio)
            ->whereIn('estado', ['pagada', 'abono'])
            ->selectRaw("
                SUM(admon+seguro+afiliacion+mensajeria+otros+iva+retiro) AS ingresos,
                SUM(c_asesor) AS comisiones,
                SUM(CASE WHEN numero_factura = 0 THEN total_ss ELSE 0 END) AS retiros,
                SUM(ISNULL(dist_retiro,0)) AS dist_retiro,
                SUM(ISNULL(dist_asesor,0)) AS dist_asesor,
                SUM(ISNULL(dist_encargado,0)) AS dist_encargado
            ")
            ->first();

        $tiposIncapacidad = \App\Models\Gasto::TIPOS_INCAPACIDAD;
        $gastosOp = DB::table('gastos')->where('aliado_id', $aid)
            ->where('tipo', '!=', 'pago_planilla')
            ->where('tipo', '!=', 'efectivo_banco')
            ->where('forma_pago', '!=', 'banco_banco')
            ->whereNotIn('tipo', $tiposIncapacidad)
            ->whereMonth('fecha', $mes)->whereYear('fecha', $anio)
            ->sum('valor');

        $ing = (float)($factData->ingresos ?? 0);
        $distRet = (float)($factData->dist_retiro ?? 0);
        $distAses = (float)($factData->dist_asesor ?? 0);
        $distEncarg = (float)($factData->dist_encargado ?? 0);
        $fechaPeriodo = \Carbon\Carbon::createFromDate($anio, $mes, 1);
        $fechaCorte = \Carbon\Carbon::createFromDate(2026, 7, 1);
        if ($fechaPeriodo->greaterThanOrEqualTo($fechaCorte)) {
            $ing = max(0.0, $ing - $distRet - $distAses - $distEncarg);
        }
        $com = (float)($factData->comisiones ?? 0);
        $ret = (float)($factData->retiros ?? 0);
        $gas = (float)($gastosOp ?? 0);

        $egresosTotal = $com + $ret + $gas;

        return ['ingresos' => $ing, 'egresos' => $egresosTotal, 'utilidad' => $ing - $egresosTotal];
    }

    // ── Helper: tendencia 6 meses ─────────────────────────────────────
    private function tendencia6Meses(int $aid, int $mes, int $anio): array
    {
        $fechaInicio = \Carbon\Carbon::createFromDate($anio, $mes, 1)->subMonths(5)->startOfMonth()->toDateString();
        $fechaFin = \Carbon\Carbon::createFromDate($anio, $mes, 1)->endOfMonth()->toDateString();

        $facturasPeriodos = DB::table('facturas')
            ->where('aliado_id', $aid)->whereNull('deleted_at')
            ->whereNotNull('fecha_pago')
            ->whereBetween('fecha_pago', [$fechaInicio, $fechaFin])
            ->whereIn('estado', ['pagada', 'abono'])
            ->groupByRaw('YEAR(fecha_pago), MONTH(fecha_pago)')
            ->selectRaw('
                YEAR(fecha_pago) as anio,
                MONTH(fecha_pago) as mes,
                SUM(admon+seguro+afiliacion+mensajeria+otros+iva+retiro) as ingresos,
                SUM(c_asesor) as comisiones,
                SUM(CASE WHEN numero_factura = 0 THEN total_ss ELSE 0 END) as retiros,
                SUM(ISNULL(dist_retiro,0)) as dist_retiro,
                SUM(ISNULL(dist_asesor,0)) as dist_asesor,
                SUM(ISNULL(dist_encargado,0)) as dist_encargado
            ')
            ->get()
            ->keyBy(function($r) { return $r->anio . '-' . $r->mes; });

        $tiposIncapacidad = \App\Models\Gasto::TIPOS_INCAPACIDAD;
        $gastosPeriodos = DB::table('gastos')
            ->where('aliado_id', $aid)
            ->where('tipo', '!=', 'pago_planilla')
            ->where('tipo', '!=', 'efectivo_banco')
            ->where('forma_pago', '!=', 'banco_banco')
            ->whereNotIn('tipo', $tiposIncapacidad)
            ->whereBetween('fecha', [$fechaInicio, $fechaFin])
            ->groupByRaw('YEAR(fecha), MONTH(fecha)')
            ->selectRaw('
                YEAR(fecha) as anio,
                MONTH(fecha) as mes,
                SUM(valor) as total_gastos
            ')
            ->get()
            ->keyBy(function($r) { return $r->anio . '-' . $r->mes; });

        $resultado = [];
        $meses = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

        for ($i = 5; $i >= 0; $i--) {
            $m = $mes - $i;
            $a = $anio;
            while ($m < 1) {
                $m += 12;
                $a--;
            }

            $key = $a . '-' . $m;
            $factData = $facturasPeriodos->get($key);
            $gastoData = $gastosPeriodos->get($key);

            $ing = (float)($factData->ingresos ?? 0);
            $distRet = (float)($factData->dist_retiro ?? 0);
            $distAses = (float)($factData->dist_asesor ?? 0);
            $distEncarg = (float)($factData->dist_encargado ?? 0);
            $fechaPeriodo = \Carbon\Carbon::createFromDate($a, $m, 1);
            $fechaCorte = \Carbon\Carbon::createFromDate(2026, 7, 1);
            if ($fechaPeriodo->greaterThanOrEqualTo($fechaCorte)) {
                $ing = max(0.0, $ing - $distRet - $distAses - $distEncarg);
            }
            $com = (float)($factData->comisiones ?? 0);
            $ret = (float)($factData->retiros ?? 0);
            $gas = (float)($gastoData->total_gastos ?? 0);

            $egresosTotal = $com + $ret + $gas;

            $resultado[] = [
                'label'    => $meses[$m] . ' ' . substr($a, 2),
                'ingresos' => $ing,
                'egresos'  => $egresosTotal,
                'utilidad' => $ing - $egresosTotal,
            ];
        }

        return $resultado;
    }

    // ── Helper: exportar Excel ───────────────────────────────────────
    /**
     * Antes era un CSV con ";" detrás de un botón que decía Excel: al abrirlo
     * las cédulas perdían ceros o salían en notación científica y las fechas
     * no se podían ordenar.
     *
     * sqlsrv devuelve todo como texto, así que el tipo se decide aquí: las
     * columnas de identificación (cédula, celular, planilla…) van siempre
     * como texto; cualquier otro número, como número; y d/m/Y o Y-m-d, como
     * fecha de Excel.
     */
    private function exportExcel($data, string $nombre, array $headers, callable $mapFn)
    {
        $libro = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $hoja  = $libro->getActiveSheet();
        $hoja->setTitle(mb_substr(ucfirst(str_replace('_', ' ', $nombre)), 0, 31));

        $ultima = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
        $hoja->fromArray($headers, null, 'A1');
        $hoja->getStyle("A1:{$ultima}1")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'FCE4D6']],
        ]);

        $esTexto = array_map(
            fn ($h) => (bool) preg_match('/c[ée]dula|documento|nit\b|celular|tel[ée]fono|planilla|recibo/iu', $h),
            array_values($headers)
        );

        $fila = 2;
        foreach ($data as $row) {
            foreach (array_values($mapFn($row)) as $i => $valor) {
                $celda = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1).$fila;
                if ($valor === null || $valor === '') {
                    continue;
                }
                if (is_int($valor) || is_float($valor)) {
                    $hoja->setCellValue($celda, $valor);
                    if (is_float($valor)) {
                        $hoja->getStyle($celda)->getNumberFormat()->setFormatCode('#,##0');
                    }
                    continue;
                }
                $valor = trim(preg_replace('/\s+/u', ' ', (string) $valor));
                if (! ($esTexto[$i] ?? false)) {
                    if (preg_match('/^-?\d+(\.\d+)?$/', $valor)) {
                        $hoja->setCellValue($celda, (float) $valor);
                        if (str_contains($valor, '.')) {
                            $hoja->getStyle($celda)->getNumberFormat()->setFormatCode('#,##0');
                        }
                        continue;
                    }
                    if (preg_match('#^(\d{2})/(\d{2})/(\d{4})( \d{2}:\d{2})?$#', $valor, $m)
                        || preg_match('#^(\d{4})-(\d{2})-(\d{2})( \d{2}:\d{2})?(:\d{2}(\.\d+)?)?$#', $valor)) {
                        $fecha = str_contains($valor, '/')
                            ? \Carbon\Carbon::createFromFormat('Y-m-d H:i', "{$m[3]}-{$m[2]}-{$m[1]} ".trim($m[4] ?? '00:00'))
                            : \Carbon\Carbon::parse($valor);
                        $conHora = $fecha->format('H:i') !== '00:00';
                        $hoja->setCellValue($celda, \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($fecha));
                        $hoja->getStyle($celda)->getNumberFormat()->setFormatCode($conHora ? 'dd/mm/yyyy hh:mm' : 'dd/mm/yyyy');
                        continue;
                    }
                }
                $hoja->setCellValueExplicit($celda, $valor, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            $fila++;
        }

        $hoja->setAutoFilter("A1:{$ultima}".max(1, $fila - 1));
        $hoja->freezePane('A2');
        for ($c = 1; $c <= count($headers); $c++) {
            $hoja->getColumnDimensionByColumn($c)->setAutoSize(true);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'infxls');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($libro))->save($tmp);

        return response()->download($tmp, "{$nombre}_".now()->format('Ymd_His').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    // ── JSON: Conciliación de Seguridad Social (Desfase de Recaudo vs Planillas) ──
    public function conciliacionSS(Request $request)
    {
        $this->checkFinanciero();
        $aid  = $this->aliadoId();
        $mes  = (int)$request->input('mes', now()->month);
        $anio = (int)$request->input('anio', now()->year);

        // 1. Obtener los números de planilla PILA que se pagaron en este mes
        $planillasIds = DB::table('gastos')
            ->where('aliado_id', $aid)
            ->where('tipo', 'pago_planilla')
            ->whereMonth('fecha', $mes)->whereYear('fecha', $anio)
            ->whereNotNull('numero_planilla')
            ->pluck('numero_planilla')
            ->unique()
            ->toArray();

        // 2. Facturas cobradas/prestadas en el mes actual sin planilla pagada este mes (A favor de caja)
        $sinPlanilla = DB::table('facturas as f')
            ->leftJoin('clientes as cl', 'cl.cedula', '=', 'f.cedula')
            ->where('f.aliado_id', $aid)->whereNull('f.deleted_at')
            ->whereNotNull('f.fecha_pago')
            ->whereMonth('f.fecha_pago', $mes)->whereYear('f.fecha_pago', $anio)
            ->whereIn('f.estado', ['pagada', 'abono', 'prestamo'])
            ->where('f.numero_factura', '>', 0)
            ->where('f.total_ss', '>', 0)
            ->whereNotExists(function($q) use ($planillasIds) {
                $q->select(DB::raw(1))
                  ->from('planos as p')
                  ->whereColumn('p.factura_id', 'f.id')
                  ->whereNull('p.deleted_at')
                  ->whereIn('p.numero_planilla', $planillasIds);
            })
            ->select('f.id', 'f.cedula', 'f.numero_factura', 'f.total_ss', 'f.estado', 'f.mes', 'f.anio', 'f.fecha_pago',
                DB::raw("ISNULL(LTRIM(RTRIM(cl.primer_nombre+' '+cl.primer_apellido)), '—') AS cliente_nombre"))
            ->orderBy('f.fecha_pago')
            ->get();

        // 3. Facturas de planillas pagadas que NO se cobraron en el mes actual (o son retiros) (En contra de caja)
        $cobradasOtrosMeses = [];
        if (!empty($planillasIds)) {
            $cobradasOtrosMeses = DB::table('planos as p')
                ->join('facturas as f', 'f.id', '=', 'p.factura_id')
                ->leftJoin('clientes as cl', 'cl.cedula', '=', 'f.cedula')
                ->where('p.aliado_id', $aid)
                ->whereNull('p.deleted_at')
                ->whereNull('f.deleted_at')
                ->whereIn('p.numero_planilla', $planillasIds)
                ->where('f.total_ss', '>', 0)
                ->where(function($q) use ($mes, $anio) {
                    $q->where('f.numero_factura', 0) // Retiro
                      ->orWhereNull('f.fecha_pago')
                      ->orWhereMonth('f.fecha_pago', '<>', $mes)
                      ->orWhereYear('f.fecha_pago', '<>', $anio)
                      ->orWhereNotIn('f.estado', ['pagada', 'abono', 'prestamo']);
                })
                ->select('f.id', 'f.cedula', 'f.numero_factura', 'f.total_ss', 'f.estado', 'f.mes', 'f.anio', 'f.fecha_pago', 'p.numero_planilla',
                    DB::raw("ISNULL(LTRIM(RTRIM(cl.primer_nombre+' '+cl.primer_apellido)), '—') AS cliente_nombre"),
                    DB::raw("CASE WHEN f.numero_factura = 0 THEN 'Retiro asumido' ELSE 'Mes anterior / anticipado' END AS tipo_desfase"))
                ->orderBy('p.numero_planilla')
                ->get();
        }

        $totalSinPlanilla = $sinPlanilla->sum('total_ss');
        $totalOtrosMeses  = collect($cobradasOtrosMeses)->sum('total_ss');

        return response()->json([
            'exito'             => true,
            'sin_planilla'      => $sinPlanilla,
            'total_sin_planilla'=> $totalSinPlanilla,
            'otros_meses'       => $cobradasOtrosMeses,
            'total_otros_meses' => $totalOtrosMeses,
            'diferencia_neta'   => $totalSinPlanilla - $totalOtrosMeses,
        ]);
    }

    // ── JSON: facturas y gastos de un día específico ──────────────────
    public function detalleDia(Request $request)
    {
        $this->checkFinanciero();
        $aid  = $this->aliadoId();
        $dia  = (int)$request->input('dia');
        $mes  = (int)$request->input('mes', now()->month);
        $anio = (int)$request->input('anio', now()->year);
        $tipo = $request->input('tipo', 'todos'); // todos|planilla|afiliacion|otro_ingreso|gastos

        // ── Facturas del día ──────────────────────────────────────────
        $qFact = DB::table('facturas AS f')
            ->where('f.aliado_id', $aid)
            ->whereNull('f.deleted_at')
            ->whereNotNull('f.fecha_pago')
            ->whereRaw('DAY(f.fecha_pago) = ?', [$dia])
            ->whereMonth('f.fecha_pago', $mes)
            ->whereYear('f.fecha_pago', $anio)
            ->whereIn('f.estado', ['pagada', 'abono'])
            ->leftJoin('clientes AS cl', function($j) use($aid) {
                $j->on('cl.cedula', '=', 'f.cedula')->where('cl.aliado_id', $aid);
            })
            ->leftJoin('empresas AS em', 'em.id', '=', 'f.empresa_id');

        if ($tipo !== 'todos' && $tipo !== 'gastos') {
            $qFact->where('f.tipo', $tipo);
        }

        $facturas = $qFact->select([
            'f.id', 'f.numero_factura', 'f.tipo', 'f.mes', 'f.anio',
            'f.cedula', 'f.empresa_id', 'f.estado', 'f.fecha_pago',
            DB::raw("ISNULL(LTRIM(RTRIM(cl.primer_nombre+' '+cl.primer_apellido)), '—') AS nombre_cliente"),
            DB::raw("ISNULL(em.empresa, '—') AS nombre_empresa"),
            DB::raw('(f.admon + f.seguro + f.mensajeria + f.otros + f.iva + f.retiro) AS ing_planilla'),
            DB::raw('(f.afiliacion + f.admon + f.seguro + f.iva) AS ing_afil'),
            DB::raw('(f.admon + f.otros) AS ing_tramite'),
            'f.total_ss',
        ])
        ->orderBy('f.tipo')
        ->orderBy('f.numero_factura')
        ->get()
        ->map(function($r) {
            $ingreso = match($r->tipo) {
                'planilla'     => (float)$r->ing_planilla,
                'afiliacion'   => (float)$r->ing_afil,
                'otro_ingreso' => (float)$r->ing_tramite,
                default        => 0,
            };
            $nombre = $r->empresa_id && $r->empresa_id != 1
                ? '🏢 '.$r->nombre_empresa
                : '👤 '.$r->nombre_cliente;

            return [
                'id'             => $r->id,
                'numero_factura' => $r->numero_factura,
                'tipo'           => $r->tipo,
                'nombre'         => $nombre,
                'cedula'         => $r->cedula,
                'ingreso'        => $ingreso,
                'total_ss'       => (float)$r->total_ss,
                'estado'         => $r->estado,
                'fecha_pago'     => $r->fecha_pago,
            ];
        });

        // ── Gastos del día (no pago_planilla) ─────────────────────────
        $gastos = [];
        if ($tipo === 'todos' || $tipo === 'gastos') {
            $tiposIncapacidad = \App\Models\Gasto::TIPOS_INCAPACIDAD;
            $gastos = DB::table('gastos AS g')
                ->where('g.aliado_id', $aid)
                ->where('g.tipo', '!=', 'pago_planilla')
                ->where('g.tipo', '!=', 'efectivo_banco')
                ->where('g.forma_pago', '!=', 'banco_banco')
                ->whereNotIn('g.tipo', $tiposIncapacidad)
                ->whereRaw('DAY(g.fecha) = ?', [$dia])
                ->whereMonth('g.fecha', $mes)
                ->whereYear('g.fecha', $anio)
                ->select(['g.id', 'g.tipo', 'g.descripcion', 'g.pagado_a', 'g.valor', 'g.fecha'])
                ->orderBy('g.tipo')
                ->get()
                ->map(fn($g) => [
                    'id'          => $g->id,
                    'tipo'        => $g->tipo,
                    'descripcion' => $g->descripcion ?: $g->pagado_a,
                    'valor'       => (float)$g->valor,
                ]);
        }

        // ── Totales ───────────────────────────────────────────────────
        $planillas   = $facturas->where('tipo','planilla')->sum('ingreso');
        $afiliaciones= $facturas->where('tipo','afiliacion')->sum('ingreso');
        $tramites    = $facturas->where('tipo','otro_ingreso')->sum('ingreso');
        $totalGastos = collect($gastos)->sum('valor');
        $totalSS     = $facturas->where('tipo','planilla')->sum('total_ss');

        return response()->json([
            'ok'          => true,
            'dia'         => $dia,
            'mes'         => $mes,
            'anio'        => $anio,
            'facturas'    => $facturas->values(),
            'gastos'      => array_values($gastos->toArray()),
            'totales'     => [
                'planillas'    => $planillas,
                'afiliaciones' => $afiliaciones,
                'tramites'     => $tramites,
                'gastos'       => $totalGastos,
                'ss'           => $totalSS,
                'utilidad'     => $planillas + $afiliaciones + $tramites - $totalGastos,
            ],
        ]);
    }

    // ── Vista parcial: desglose de un día — efectivo / consignación, gastos y quién los reportó ──
    // Seguro queda fuera del cálculo por decisión del usuario; mensajería se suma dentro de "otros".
    public function desgloseDia(Request $request)
    {
        $this->checkFinanciero();
        $aid  = $this->aliadoId();
        $dia  = (int)$request->input('dia');
        $mes  = (int)$request->input('mes', now()->month);
        $anio = (int)$request->input('anio', now()->year);

        // ── Ingresos del día: se separan por cuánto de cada factura quedó realmente en
        // efectivo / consignación / préstamo, usando valor_efectivo/valor_consignado/valor_prestamo
        // (que sí traen el split real, incluso para facturas con forma_pago='mixto'), en vez del
        // campo categórico forma_pago que no distingue proporciones dentro de un pago mixto.
        $filas = DB::table('facturas')
            ->where('aliado_id', $aid)->whereNull('deleted_at')
            ->whereNotNull('fecha_pago')
            ->whereRaw('DAY(fecha_pago) = ?', [$dia])
            ->whereMonth('fecha_pago', $mes)->whereYear('fecha_pago', $anio)
            ->whereIn('estado', ['pagada', 'abono', 'prestamo'])
            ->select([
                'tipo', 'numero_factura', 'admon', 'iva', 'mensajeria', 'otros_admon', 'otros',
                'retiro', 'admin_asesor', 'total_ss', 'afiliacion',
                'dist_admon', 'dist_asesor', 'dist_retiro', 'dist_utilidad', 'dist_encargado',
                'valor_efectivo', 'valor_consignado', 'valor_prestamo',
            ])
            ->get();

        $camposVacios = ['admon'=>0.0,'iva'=>0.0,'otros'=>0.0,'retiro_campo'=>0.0,'admin_asesor'=>0.0,'ss'=>0.0,
            'tramites'=>0.0,'afiliacion'=>0.0,'dist_admon'=>0.0,'dist_asesor'=>0.0,'dist_retiro'=>0.0,
            'dist_utilidad'=>0.0,'dist_encargado'=>0.0];

        $buckets = [
            'efectivo'     => ['label' => 'EFECTIVO'] + $camposVacios,
            'consignacion' => ['label' => 'CONSIGNACIÓN'] + $camposVacios,
            'prestamo'     => ['label' => 'PRÉSTAMO'] + $camposVacios,
        ];

        foreach ($filas as $f) {
            $ve = (float)$f->valor_efectivo;
            $vc = (float)$f->valor_consignado;
            $vp = (float)$f->valor_prestamo;
            $totalPago = $ve + $vc + $vp;
            $ratios = $totalPago > 0
                ? ['efectivo' => $ve / $totalPago, 'consignacion' => $vc / $totalPago, 'prestamo' => $vp / $totalPago]
                : ['efectivo' => 1.0, 'consignacion' => 0.0, 'prestamo' => 0.0]; // sin split registrado: se asume efectivo

            if ($f->tipo === 'planilla') {
                $otrosVal = (float)$f->mensajeria + (float)($f->otros_admon ?? 0);
                $ssVal    = $f->numero_factura > 0 ? (float)$f->total_ss : 0.0;
                foreach ($ratios as $bk => $r) {
                    if ($r <= 0) continue;
                    $buckets[$bk]['admon']        += (float)$f->admon * $r;
                    $buckets[$bk]['iva']          += (float)$f->iva * $r;
                    $buckets[$bk]['otros']        += $otrosVal * $r;
                    $buckets[$bk]['retiro_campo'] += (float)$f->retiro * $r;
                    $buckets[$bk]['admin_asesor'] += (float)($f->admin_asesor ?? 0) * $r;
                    $buckets[$bk]['ss']           += $ssVal * $r;
                }
            } elseif ($f->tipo === 'afiliacion') {
                foreach ($ratios as $bk => $r) {
                    if ($r <= 0) continue;
                    $buckets[$bk]['afiliacion']     += (float)$f->afiliacion * $r;
                    $buckets[$bk]['dist_admon']     += (float)($f->dist_admon ?? 0) * $r;
                    $buckets[$bk]['dist_asesor']    += (float)($f->dist_asesor ?? 0) * $r;
                    $buckets[$bk]['dist_retiro']    += (float)($f->dist_retiro ?? 0) * $r;
                    $buckets[$bk]['dist_utilidad']  += (float)($f->dist_utilidad ?? 0) * $r;
                    $buckets[$bk]['dist_encargado'] += (float)($f->dist_encargado ?? 0) * $r;
                }
            } elseif ($f->tipo === 'otro_ingreso') {
                $tramiteVal = (float)$f->admon + (float)$f->otros;
                foreach ($ratios as $bk => $r) {
                    if ($r <= 0) continue;
                    $buckets[$bk]['tramites'] += $tramiteVal * $r;
                }
            }
        }

        foreach ($buckets as &$b) {
            $b['dist_sin_asignar'] = max(0.0, $b['afiliacion'] - (
                $b['dist_admon'] + $b['dist_asesor'] + $b['dist_retiro'] + $b['dist_utilidad'] + $b['dist_encargado']
            ));
            $b['total_admin']    = $b['admon'] + $b['iva'] + $b['otros'] + $b['retiro_campo'] + $b['tramites'];
            // Total real de la factura completa: admon + afiliaciones + SS recaudado
            $b['total_entradas'] = $b['total_admin'] + $b['afiliacion'] + $b['ss'];
            $b['gasto_operativo'] = 0.0;
        }
        unset($b);

        // ── Gastos del día: todos (para la lista final) + subtotales por forma de pago ──
        // Se excluyen traspasos internos (efectivo_banco / banco_banco) y pago de planilla SS
        // (ese pago se audita en el módulo de gastos/conciliación SS, no en este modal).
        $tiposIncapacidad = \App\Models\Gasto::TIPOS_INCAPACIDAD;
        $gastosRaw = DB::table('gastos AS g')
            ->leftJoin('users AS u', 'u.id', '=', 'g.usuario_id')
            ->where('g.aliado_id', $aid)
            ->whereRaw('DAY(g.fecha) = ?', [$dia])
            ->whereMonth('g.fecha', $mes)->whereYear('g.fecha', $anio)
            ->where('g.tipo', '!=', 'efectivo_banco')
            ->where('g.tipo', '!=', 'pago_planilla')
            ->where('g.forma_pago', '!=', 'banco_banco')
            ->select('g.id', 'g.tipo', 'g.descripcion', 'g.pagado_a', 'g.valor', 'g.forma_pago',
                DB::raw("ISNULL(u.nombre, '—') AS usuario_nombre"))
            ->orderBy('g.tipo')
            ->get();

        // gastos.forma_pago solo distingue 'efectivo' vs el resto (transferencia, transferencia_bancaria,
        // interno, etc. — todos salen de una cuenta bancaria) ya que no hay gastos "a crédito".
        foreach ($gastosRaw as $g) {
            $bk = ($g->forma_pago ?: 'efectivo') === 'efectivo' ? 'efectivo' : 'consignacion';
            if (!in_array($g->tipo, $tiposIncapacidad)) {
                $buckets[$bk]['gasto_operativo'] += (float)$g->valor;
            }
        }

        foreach ($buckets as &$b) {
            $b['total_gastos'] = $b['gasto_operativo'];
            $b['saldo_neto']   = $b['total_entradas'] - $b['total_gastos'];
        }
        unset($b);

        // Solo mostrar buckets con algún movimiento ese día
        $buckets = array_filter($buckets, fn($b) => abs($b['total_entradas']) > 0.5 || abs($b['total_gastos']) > 0.5);

        $gastos = $gastosRaw->map(fn($g) => [
            'tipo_label'  => \App\Models\Gasto::TIPOS[$g->tipo] ?? ucfirst($g->tipo),
            'descripcion' => $g->descripcion ?: $g->pagado_a,
            'usuario'     => $g->usuario_nombre,
            'forma_pago'  => $g->forma_pago ?: 'efectivo',
            'valor'       => (float)$g->valor,
        ]);

        $mesesEs = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

        return view('admin.informes.partials.desglose_dia', [
            'dia' => $dia, 'mes' => $mes, 'anio' => $anio, 'mesesEs' => $mesesEs,
            'buckets' => $buckets, 'gastos' => $gastos,
        ]);
    }

    // ── JSON: resumen préstamos pendientes del mes ────────────────────
    public function prestamesMes(Request $request)
    {
        if (! Auth::user()->can('prestamos.ver')) {
            abort(403, 'No tienes permiso para «Ver préstamos (Préstamos)».');
        }
        $aid  = $this->aliadoId();
        $mes  = (int)$request->input('mes', now()->month);
        $anio = (int)$request->input('anio', now()->year);

        // Misma lógica que PrestamosController@index (tab empresas):
        //   saldo_lote = SUM(valor_prestamo) - SUM(abonos_lote)
        // valor_prestamo = monto explícito capturado al facturar (fuente de verdad).
        // Para individuales: abs(saldo_proximo) - abonos (correcto por fila).
        $rows = DB::table('facturas AS f')
            ->where('f.aliado_id', $aid)
            ->whereNull('f.deleted_at')
            ->where('f.estado', 'prestamo')
            ->whereNotNull('f.fecha_pago')
            ->whereMonth('f.fecha_pago', $mes)
            ->whereYear('f.fecha_pago', $anio)
            ->leftJoin('clientes AS cl', function($j) use($aid) {
                $j->on('cl.cedula', '=', 'f.cedula')->where('cl.aliado_id', $aid);
            })
            ->leftJoin('empresas AS em', 'em.id', '=', 'f.empresa_id')
            ->select([
                'f.id', 'f.numero_factura', 'f.cedula', 'f.empresa_id',
                'f.total', 'f.saldo_proximo', 'f.valor_prestamo',
                DB::raw("ISNULL((SELECT SUM(a.valor) FROM abonos a WHERE a.factura_id = f.id), 0) AS total_abonado"),
                DB::raw("ISNULL(LTRIM(RTRIM(cl.primer_nombre+' '+cl.primer_apellido)),'—') AS nombre_cliente"),
                DB::raw("ISNULL(em.empresa,'—') AS nombre_empresa"),
            ])
            ->get()
            ->map(function($r) {
                $esEmpresa = $r->empresa_id && $r->empresa_id != 1;
                // Para individuales: saldo por fila usando abs(saldo_proximo)
                $saldoFila = max(0, (int)max(0.0, -(float)($r->saldo_proximo ?? 0)) - (int)($r->total_abonado ?? 0));
                return [
                    'id'             => $r->id,
                    'numero_factura' => $r->numero_factura,
                    'cedula'         => $r->cedula,
                    'empresa_id'     => $r->empresa_id,
                    'nombre'         => $esEmpresa ? '🏢 '.$r->nombre_empresa : '👤 '.$r->nombre_cliente,
                    'valor_prestamo' => (int)($r->valor_prestamo ?? 0),  // fuente de verdad para lotes
                    'saldo_proximo'  => (float)($r->saldo_proximo ?? 0),
                    'total_abonado'  => (int)($r->total_abonado ?? 0),
                    'saldo_fila'     => $saldoFila,
                    'es_empresa'     => $esEmpresa,
                ];
            });

        // ── Individuales ─────────────────────────────────────────────
        $individuales = $rows
            ->where('es_empresa', false)
            ->filter(fn($r) => (int)max(0.0, -(float)($r['saldo_proximo'] ?? 0)) > 0)
            ->map(fn($r) => [
                'id'               => $r['id'],
                'numero_factura'   => $r['numero_factura'],
                'nombre'           => $r['nombre'],
                'cedula'           => $r['cedula'],
                'total_financiado' => (int)max(0.0, -(float)($r['saldo_proximo'] ?? 0)),
                'total_abonado'    => (int)($r['total_abonado'] ?? 0),
                'saldo_pendiente'  => $r['saldo_fila'],
                'es_empresa'       => false,
                'empresa_id'       => null,
            ])->values();

        // ── Empresas (lotes) ─────────────────────────────────────────
        // Igual que PrestamosController: saldo a nivel de LOTE completo.
        // Se cargan TODAS las filas del lote (sin filtro per-fila) para
        // que SUM(valor_prestamo) cubra los $22K del lote completo.
        $empresasLotes = $rows
            ->where('es_empresa', true)
            ->groupBy('numero_factura')
            ->map(function($lote) {
                $abonosLote    = (int)$lote->sum('total_abonado');
                $valorPrestamo = $lote->sum('valor_prestamo');

                // Usar valor_prestamo como fuente de verdad (igual que módulo préstamos)
                $prestadoLote = $valorPrestamo > 0
                    ? (int)$valorPrestamo
                    : abs((int)$lote->sum('saldo_proximo'));

                $saldoLote = max(0, $prestadoLote - $abonosLote);

                if ($prestadoLote <= 0) return null;

                $first = $lote->first();
                return [
                    'numero_factura'   => $first['numero_factura'],
                    'nombre'           => $first['nombre'],
                    'empresa_id'       => $first['empresa_id'],
                    'total_financiado' => $prestadoLote,
                    'total_abonado'    => $abonosLote,
                    'saldo_pendiente'  => $saldoLote,
                    'cant_clientes'    => $lote->count(),
                    'factura_id'       => $first['id'],
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'ok'          => true,
            'individuales'=> $individuales,
            'empresas'    => $empresasLotes,
            'totales'     => [
                'total_financiado' => $individuales->sum('total_financiado') + $empresasLotes->sum('total_financiado'),
                'total_abonado'    => $individuales->sum('total_abonado')    + $empresasLotes->sum('total_abonado'),
                'saldo_pendiente'  => $individuales->sum('saldo_pendiente')  + $empresasLotes->sum('saldo_pendiente'),
                'cant'             => $individuales->count() + $empresasLotes->count(),
            ],
        ]);
    }

    public function brynexCobros()
    {
        $this->checkAdmin();
        $aid = $this->aliadoId();

        $cobros = \App\Models\BrynexCobroAliado::where('aliado_id', $aid)
            ->orderBy('anio', 'desc')
            ->orderBy('mes', 'desc')
            ->get();

        return view('admin.informes.brynex_cobros', compact('cobros'));
    }

    public function brynexCobroPdf($cobroId)
    {
        $this->checkAdmin();
        $cobro = \App\Models\BrynexCobroAliado::findOrFail($cobroId);

        if (!Auth::user()->es_brynex && $cobro->aliado_id !== $this->aliadoId()) {
            abort(403, 'No tienes permisos para descargar este cobro.');
        }

        $cobro->load(['aliado', 'detalles.modulo', 'pagos']);

        $saldoAnterior = \App\Models\BrynexCobroAliado::where('aliado_id', $cobro->aliado_id)
            ->where(function ($q) use ($cobro) {
                $q->where('anio', '<', $cobro->anio)
                  ->orWhere(function ($sq) use ($cobro) {
                      $sq->where('anio', '=', $cobro->anio)
                         ->where('mes', '<', $cobro->mes);
                  });
            })
            ->get()
            ->sum(fn($c) => $c->saldo_pendiente);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.brynex_cobro', compact('cobro', 'saldoAnterior'));
        app(\App\Services\TrazaArchivoService::class)->marcarPdf($pdf);
        $nombreArchivo = 'Cuenta_de_Cobro_Brynex_' . str_replace(' ', '_', $cobro->aliado->nombre) . '_' . $cobro->anio . '_' . str_pad($cobro->mes, 2, '0', STR_PAD_LEFT) . '.pdf';
        return $pdf->download($nombreArchivo);
    }

    /**
     * Tabla derivada `(VALUES ...) AS m(...)` con los períodos del informe, para
     * que una consulta agrupada por (anio, mes) reemplace a una consulta por mes.
     *
     * Las fechas se materializan aquí, con CAST explícito, en vez de construirse
     * en SQL desde las columnas mes/anio: hay facturas con `mes = 0, anio = 0` y
     * un DATEFROMPARTS sobre ellas aborta la consulta entera.
     *
     * Sólo recibe enteros y fechas generadas por Carbon, nunca entrada del usuario.
     */
    private function tablaPeriodosSql(array $periodos): string
    {
        $filas = array_map(fn ($p) => sprintf(
            "(%d, %d, CAST('%s' AS date), CAST('%s' AS date), CAST('%s' AS datetime), CAST('%s' AS datetime))",
            $p['anio'],
            $p['mes'],
            $p['primer_dia']->toDateString(),
            $p['ultimo_dia']->toDateString(),
            $p['primer_dia']->toDateTimeString(),
            $p['ultimo_dia']->toDateTimeString()
        ), $periodos);

        return '(VALUES ' . implode(', ', $filas) . ') AS m(anio, mes, ini_d, fin_d, ini_dt, fin_dt)';
    }

    public function consolidadoMensual()
    {
        if (! Auth::user()->can('informes.ver')) {
            abort(403, 'Acceso restringido.');
        }

        $aid = $this->aliadoId();
        $nombresMeses = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
        ];

        // Los 7 períodos del informe (el séptimo solo existe para poder calcular
        // la variación del sexto). Se arman primero porque todas las consultas de
        // abajo resuelven los 7 meses de una sola vez.
        $periodos = [];
        $fechaIteracion = now()->startOfMonth();
        for ($i = 0; $i < 7; $i++) {
            $primerDia = \Carbon\Carbon::create($fechaIteracion->year, $fechaIteracion->month, 1)->startOfDay();
            $periodos[] = [
                'mes'        => $fechaIteracion->month,
                'anio'       => $fechaIteracion->year,
                'primer_dia' => $primerDia,
                'ultimo_dia' => $primerDia->copy()->endOfMonth(),
            ];
            $fechaIteracion->subMonth();
        }

        // Tabla derivada con esos períodos: convierte el mes/año y las fechas de
        // corte en columnas, para que una sola consulta agrupada por (anio, mes)
        // reemplace a las siete que había antes. Cada viaje al servidor remoto
        // cuesta ~250ms, así que lo que se ahorra son round-trips, no cómputo.
        $tp = $this->tablaPeriodosSql($periodos);

        $clave  = fn ($anio, $mes) => ((int) $anio) . '-' . ((int) $mes);
        $porMes = fn ($filas) => collect($filas)
            ->mapWithKeys(fn ($r) => [$clave($r->anio, $r->mes) => $r->total])
            ->all();

        $rangoIni = $periodos[count($periodos) - 1]['primer_dia']; // el mes más viejo
        $rangoFin = $periodos[0]['ultimo_dia'];                    // el mes más reciente

        // 1. Admon Vigentes (factura regular de administración en M)
        $admonPorMes = $porMes(DB::table(DB::raw($tp))
            ->join('facturas as f', function ($j) {
                $j->on('f.mes', '=', 'm.mes')->on('f.anio', '=', 'm.anio');
            })
            ->join('contratos as c', 'c.id', '=', 'f.contrato_id')
            ->where('c.aliado_id', $aid)
            ->where('f.numero_factura', '>', 0)
            ->whereNull('f.deleted_at')
            ->whereColumn('c.fecha_ingreso', '<', 'm.ini_d')
            ->groupBy('m.anio', 'm.mes')
            ->select('m.anio', 'm.mes', DB::raw('COUNT(DISTINCT c.id) as total'))
            ->get());

        // 3. Afiliaciones del Mes (nuevos contratos ingresados en el período por fecha de ingreso)
        //    Se traen las cédulas (no un COUNT) para poder cruzarlas más abajo
        //    contra los retiros del mes y separar reingresos de altas nuevas.
        $afiliacionesPorMes = DB::table('contratos as c')
            ->where('c.aliado_id', $aid)
            ->where('c.fecha_ingreso', '>=', $rangoIni->toDateString())
            ->where('c.fecha_ingreso', '<=', $rangoFin->toDateString())
            ->select('c.id', 'c.cedula', 'c.fecha_ingreso')
            ->get()
            ->groupBy(fn ($a) => $clave(substr($a->fecha_ingreso, 0, 4), substr($a->fecha_ingreso, 5, 2)));

        // 4. Retiros Puros (guiados estrictamente por el período mes/año de la factura de retiro #0)
        $retiradosPorMes = DB::table(DB::raw($tp))
            ->join('contratos as c', function ($j) use ($aid) {
                $j->where('c.aliado_id', '=', $aid)->where('c.estado', '=', 'retirado');
            })
            ->whereExists(function ($sq) {
                $sq->select(DB::raw(1))
                   ->from('facturas as f')
                   ->whereColumn('f.contrato_id', 'c.id')
                   ->where('f.numero_factura', 0)
                   ->where('f.tipo', '<>', 'afiliacion')
                   ->whereColumn('f.mes', 'm.mes')
                   ->whereColumn('f.anio', 'm.anio')
                   ->whereNull('f.deleted_at');
            })
            ->whereNotExists(function ($sq) {
                $sq->select(DB::raw(1))
                   ->from('facturas as f')
                   ->whereColumn('f.contrato_id', 'c.id')
                   ->where('f.numero_factura', '>', 0)
                   ->where('f.tipo', '<>', 'afiliacion')
                   ->whereColumn('f.mes', 'm.mes')
                   ->whereColumn('f.anio', 'm.anio')
                   ->whereNull('f.deleted_at');
            })
            ->select('m.anio', 'm.mes', 'c.id', 'c.cedula',
                DB::raw("(SELECT TOP 1 total_ss FROM facturas WHERE contrato_id = c.id AND numero_factura = 0 AND tipo <> 'afiliacion' AND mes = m.mes AND anio = m.anio AND deleted_at IS NULL ORDER BY id DESC) as costo_ss"),
                // Retiro que en realidad fue una renovación ("ingreso-retiro"):
                // o se le abrió otro contrato con fecha de ingreso dentro del mismo
                // mes del retiro, o esa cédula hoy tiene un contrato vigente con
                // este mismo aliado. Basta con una de las dos.
                DB::raw("CASE WHEN EXISTS (
                        SELECT 1 FROM contratos c2
                        WHERE c2.cedula = c.cedula
                          AND c2.aliado_id = c.aliado_id
                          AND c2.id <> c.id
                          AND (
                                (c2.fecha_ingreso >= m.ini_d
                                 AND c2.fecha_ingreso <= m.fin_d)
                             OR c2.estado = 'vigente'
                          )
                    ) THEN 1 ELSE 0 END as es_renovado")
            )
            ->get()
            ->groupBy(fn ($r) => $clave($r->anio, $r->mes));

        // WA Enviados: mensajes de cobro que Meta si acepto.
        // Se cuenta el detalle (una fila por destinatario) y no total_destinatarios de
        // la cabecera: ese es el planeado e incluye los que fallaron o se omitieron.
        // Tampoco sirve total_enviados de la cabecera, que el worker va acumulando y
        // queda desfasado cuando el lote se corta. Misma regla en las tres consultas.
        $waCobrosPorMes = $porMes(DB::table(DB::raw($tp))
            ->join('whatsapp_envios_masivos as e', function ($j) use ($aid) {
                $j->on('e.mes', '=', 'm.mes')
                  ->on('e.anio', '=', 'm.anio')
                  ->where('e.aliado_id', '=', $aid);
            })
            ->join('whatsapp_envios_masivos_detalle as d', 'd.envio_id', '=', 'e.id')
            ->where('d.estado', 'enviado')
            ->groupBy('m.anio', 'm.mes')
            ->select('m.anio', 'm.mes', DB::raw('COUNT(*) as total'))
            ->get());

        // WA Enviados: mensajes de planilla efectivamente enviados en lotes
        $waPlanillasPorMes = $porMes(DB::table(DB::raw($tp))
            ->join('planilla_envios_whatsapp as pe', function ($j) use ($aid) {
                $j->on('pe.mes', '=', 'm.mes')
                  ->on('pe.anio', '=', 'm.anio')
                  ->where('pe.aliado_id', '=', $aid);
            })
            ->join('planilla_envios_whatsapp_detalle as ped', 'ped.envio_id', '=', 'pe.id')
            ->where('ped.estado', 'enviado')
            ->groupBy('m.anio', 'm.mes')
            ->select('m.anio', 'm.mes', DB::raw('COUNT(*) as total'))
            ->get());

        // WA Enviados: reenvíos/envíos individuales de planillas
        $waIndivPorMes = $porMes(DB::table(DB::raw($tp))
            ->join('planilla_envios_whatsapp_detalle as ped', function ($j) {
                $j->on('ped.created_at', '>=', 'm.ini_dt')->on('ped.created_at', '<=', 'm.fin_dt');
            })
            ->join('planos as p', 'p.id', '=', 'ped.plano_id')
            ->where('p.aliado_id', $aid)
            ->where('ped.envio_id', 0)
            ->where('ped.estado', 'enviado')
            ->groupBy('m.anio', 'm.mes')
            ->select('m.anio', 'm.mes', DB::raw('COUNT(*) as total'))
            ->get());

        // Respuestas Clientes: conversaciones iniciadas por el cliente (primer mensaje entrante pasadas 24h de inactividad)
        $respuestasPorMes = $porMes(DB::table(DB::raw($tp))
            ->join('whatsapp_mensajes as msj', function ($j) {
                $j->on('msj.created_at', '>=', 'm.ini_dt')->on('msj.created_at', '<=', 'm.fin_dt');
            })
            ->where('msj.aliado_id', $aid)
            ->where('msj.direccion', 'entrante')
            ->whereNotExists(function ($sq) {
                $sq->select(DB::raw(1))
                   ->from('whatsapp_mensajes as prev')
                   ->whereColumn('prev.conversacion_id', 'msj.conversacion_id')
                   ->whereColumn('prev.id', '<>', 'msj.id')
                   ->whereRaw('prev.created_at >= DATEADD(hour, -24, msj.created_at)')
                   ->whereRaw('prev.created_at < msj.created_at');
            })
            ->groupBy('m.anio', 'm.mes')
            ->select('m.anio', 'm.mes', DB::raw('COUNT(*) as total'))
            ->get());

        // Asistente IA: consultas del mes (web + whatsapp) y valor configurado en BryNex
        // (tramos de tarifa del módulo "ia_asistente", editables en /brynex/consumo/{aliado}/modulos).
        $iaPorMes = $porMes(DB::table(DB::raw($tp))
            ->join('ia_consumo as ic', function ($j) {
                $j->on('ic.created_at', '>=', 'm.ini_dt')->on('ic.created_at', '<=', 'm.fin_dt');
            })
            ->where('ic.aliado_id', $aid)
            ->groupBy('m.anio', 'm.mes')
            ->select('m.anio', 'm.mes', DB::raw('COUNT(*) as total'))
            ->get());

        // Los tramos de tarifa se traen una vez y la vigencia de cada mes se
        // resuelve en memoria; antes eran hasta 2 consultas por mes.
        $iaModuloId = \App\Models\BrynexModulo::where('codigo', 'ia_asistente')->value('id');
        $iaTramos   = $iaModuloId
            ? \App\Models\BrynexTramoTarifa::tramosDelModulo($iaModuloId, $aid)
            : collect();

        $meses = [];

        foreach ($periodos as $p) {
            $mesVal    = $p['mes'];
            $anioVal   = $p['anio'];
            $ultimoDia = $p['ultimo_dia'];
            $kMes      = $clave($anioVal, $mesVal);

            $admonVigentes = (int) ($admonPorMes[$kMes] ?? 0);

            $afiliacionesMes = $afiliacionesPorMes->get($kMes, collect());
            $afilPorFecha    = $afiliacionesMes->count();

            $retiradosRaw = $retiradosPorMes->get($kMes, collect());

            $retirosReales = 0;
            $retirosInformativos = 0;
            $retirosRenovados = 0;
            // Renovado es un corte transversal, no una tercera categoría: se abre
            // dentro de reales y dentro de informativos para poder mostrarlo bajo
            // cada uno en la tabla.
            $retirosRealesRenov = 0;
            $retirosInformRenov = 0;
            foreach ($retiradosRaw as $r) {
                $esRenovado = (int) $r->es_renovado === 1;

                if (($r->costo_ss ?? 0) > 0) {
                    $retirosReales++;
                    if ($esRenovado) {
                        $retirosRealesRenov++;
                    }
                } else {
                    $retirosInformativos++;
                    if ($esRenovado) {
                        $retirosInformRenov++;
                    }
                }

                if ($esRenovado) {
                    $retirosRenovados++;
                }
            }

            // Reingresos del mes ("ingreso-retiro"): afiliaciones cuya cédula también
            // aparece entre los retiros de ESTE mes, en un contrato distinto. El
            // contrato que entra y sale dentro del mismo mes no cuenta — es una
            // afiliación que se retiró, no alguien que volvió.
            $retiradosPorCedula = [];
            foreach ($retiradosRaw as $r) {
                $retiradosPorCedula[$r->cedula][] = $r->id;
            }

            $afilReingresos = 0;
            foreach ($afiliacionesMes as $a) {
                foreach ($retiradosPorCedula[$a->cedula] ?? [] as $idRetirado) {
                    if ((int) $idRetirado !== (int) $a->id) {
                        $afilReingresos++;
                        break;
                    }
                }
            }
            $afilNuevas = $afilPorFecha - $afilReingresos;

            $label = $nombresMeses[$mesVal] . ' ' . $anioVal;

            $totalRetiros = $retirosReales + $retirosInformativos;
            $totalActivos = $admonVigentes + $afilPorFecha;
            $retirosDefinitivos = $totalRetiros - $retirosRenovados;

            $waEnviados = (int) ($waCobrosPorMes[$kMes] ?? 0)
                        + (int) ($waPlanillasPorMes[$kMes] ?? 0)
                        + (int) ($waIndivPorMes[$kMes] ?? 0);

            $respuestasClientes = (int) ($respuestasPorMes[$kMes] ?? 0);

            $iaConsultas = (int) ($iaPorMes[$kMes] ?? 0);

            $iaValorMensual = $iaModuloId
                ? (\App\Models\BrynexTramoTarifa::calcularCobroConTramos($iaTramos, $iaConsultas, $aid, $ultimoDia->toDateString())['subtotal'] ?? 0)
                : 0;

            $meses[] = [
                'label'            => $label,
                'mes'              => $mesVal,
                'anio'             => $anioVal,
                'admon_vigentes'   => $admonVigentes,
                'afil_por_fecha'   => $afilPorFecha,
                'afil_nuevas'      => $afilNuevas,
                'afil_reingresos'  => $afilReingresos,
                'retiros_reales'   => $retirosReales,
                'retiros_inform'   => $retirosInformativos,
                'retiros_reales_renov' => $retirosRealesRenov,
                'retiros_inform_renov' => $retirosInformRenov,
                'retiros_renovados'   => $retirosRenovados,
                'retiros_definitivos' => $retirosDefinitivos,
                'total_retiros'    => $totalRetiros,
                'total_activos'    => $totalActivos,
                'neto_periodo'     => $totalActivos - $totalRetiros,
                'wa_enviados'        => (int) $waEnviados,
                'respuestas_clientes' => $respuestasClientes,
                'ia_consultas'     => (int) $iaConsultas,
                'ia_valor_mensual' => (float) $iaValorMensual,
            ];
        }

        // Calcular la variación para los primeros 6 meses sobre Total Activos
        for ($k = 0; $k < 6; $k++) {
            $meses[$k]['variacion'] = $meses[$k]['total_activos'] - $meses[$k+1]['total_activos'];
        }

        // Nos quedamos solo con los 6 meses más recientes
        $mesesFinal = array_slice($meses, 0, 6);

        // Los KPIs del mes actual son el primer elemento
        $kpisActual = $mesesFinal[0];

        return view('admin.informes.consolidado_mensual', compact('mesesFinal', 'kpisActual'));
    }

    public function consolidadoMensualDetalle(Request $request)
    {
        if (! Auth::user()->can('informes.ver')) {
            return response()->json(['error' => 'Acceso restringido.'], 403);
        }

        $aid = $this->aliadoId();
        $mes = (int) $request->input('mes');
        $anio = (int) $request->input('anio');
        $tipo = $request->input('tipo'); // 'admon_vigentes', 'afiliaciones', 'retiros_reales', 'retiros_informativos'

        if ($tipo === 'retiros_inform') {
            $tipo = 'retiros_informativos';
        }

        if (!$mes || !$anio || !$tipo) {
            return response()->json(['error' => 'Parámetros inválidos.'], 400);
        }

        $nombresMeses = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
        ];

        $primerDia = \Carbon\Carbon::create($anio, $mes, 1)->startOfDay();
        $ultimoDia = $primerDia->copy()->endOfMonth();

        $query = DB::table('contratos as c')
            ->join('clientes as cl', function($j) use($aid){ 
                $j->on('cl.cedula','=','c.cedula')->where('cl.aliado_id',$aid); 
            })
            ->leftJoin('planes_contrato as pl', 'pl.id', '=', 'c.plan_id')
            ->leftJoin('tipo_modalidad as tm', 'tm.id', '=', 'c.tipo_modalidad_id');

        if ($tipo === 'admon_vigentes') {
            $query->where('c.aliado_id', $aid)
                ->where('c.fecha_ingreso', '<', $primerDia->toDateString())
                ->whereExists(function($sq) use ($mes, $anio) {
                    $sq->select(DB::raw(1))
                       ->from('facturas as f')
                       ->whereColumn('f.contrato_id', 'c.id')
                       ->where('f.numero_factura', '>', 0)
                       ->where('f.mes', $mes)
                       ->where('f.anio', $anio)
                       ->whereNull('f.deleted_at');
                });
        } elseif ($tipo === 'afiliaciones') {
            $query->where('c.aliado_id', $aid)
                ->where('c.fecha_ingreso', '>=', $primerDia->toDateString())
                ->where('c.fecha_ingreso', '<=', $ultimoDia->toDateString());
        } elseif ($tipo === 'retiros_reales' || $tipo === 'retiros_informativos') {
            $query->where('c.aliado_id', $aid)
                ->where('c.estado', 'retirado')
                ->whereExists(function($sq) use ($mes, $anio) {
                    $sq->select(DB::raw(1))
                       ->from('facturas as f')
                       ->whereColumn('f.contrato_id', 'c.id')
                       ->where('f.numero_factura', 0)
                       ->where('f.tipo', '<>', 'afiliacion')
                       ->where('f.mes', $mes)
                       ->where('f.anio', $anio)
                       ->whereNull('f.deleted_at');
                })->whereNotExists(function($sq) use ($mes, $anio) {
                    $sq->select(DB::raw(1))
                       ->from('facturas as f')
                       ->whereColumn('f.contrato_id', 'c.id')
                       ->where('f.numero_factura', '>', 0)
                       ->where('f.tipo', '<>', 'afiliacion')
                       ->where('f.mes', $mes)
                       ->where('f.anio', $anio)
                       ->whereNull('f.deleted_at');
                });
        } else {
            return response()->json(['error' => 'Tipo de detalle no soportado.'], 400);
        }

        $query->select(
            'c.id', 'c.cedula', 'c.estado', 'c.fecha_ingreso', 'c.fecha_retiro',
            'pl.nombre as plan_nombre',
            DB::raw("ISNULL(NULLIF(tm.observacion, ''), tm.tipo_modalidad) as modalidad_nombre"),
            DB::raw("LTRIM(RTRIM(cl.primer_nombre+' '+ISNULL(cl.segundo_nombre,'')+' '+cl.primer_apellido+' '+ISNULL(cl.segundo_apellido,''))) AS nombre_completo"),
            DB::raw("(SELECT TOP 1 mes FROM facturas WHERE contrato_id = c.id AND numero_factura = 0 AND tipo <> 'afiliacion' AND deleted_at IS NULL ORDER BY id DESC) as mes_factura_retiro")
        );

        if ($tipo === 'retiros_reales' || $tipo === 'retiros_informativos') {
            $query->addSelect(DB::raw("(SELECT TOP 1 total_ss FROM facturas WHERE contrato_id = c.id AND numero_factura = 0 AND deleted_at IS NULL ORDER BY id DESC) as costo_ss"));
        }

        $contratos = $query->orderBy('cl.primer_apellido')->get();

        // Filtrar retiros reales o informativos
        if ($tipo === 'retiros_reales') {
            $contratos = $contratos->filter(fn($c) => ($c->costo_ss ?? 0) > 0);
        } elseif ($tipo === 'retiros_informativos') {
            $contratos = $contratos->filter(fn($c) => ($c->costo_ss ?? 0) <= 0);
        }

        // Obtener facturas de este período
        $facturas = DB::table('facturas')
            ->where('aliado_id', $aid)
            ->where('mes', $mes)
            ->where('anio', $anio)
            ->whereNull('deleted_at')
            ->select('contrato_id', 'numero_factura', 'tipo', 'created_at')
            ->get()
            ->groupBy('contrato_id');

        $personas = [];
        foreach ($contratos as $c) {
            // Formatear facturas y extraer fechas de generación
            $facturasContrato = [];
            $fechasFacturaContrato = [];
            if (isset($facturas[$c->id])) {
                foreach ($facturas[$c->id] as $f) {
                    if ((int)$f->numero_factura === 0) {
                        if ($f->tipo === 'afiliacion') {
                            $facturasContrato[] = 'Afil. Pre';
                        } else {
                            $facturasContrato[] = 'Retiro';
                        }
                    } else {
                        $facturasContrato[] = '#' . str_pad($f->numero_factura, 6, '0', STR_PAD_LEFT);
                    }
                    if ($f->created_at) {
                        $fechasFacturaContrato[] = \Carbon\Carbon::parse($f->created_at)->format('d/m/Y');
                    }
                }
            }

            // Formatear fecha de retiro
            $fechaRetiroStr = '—';
            if ($c->fecha_retiro || ($c->estado ?? null) === 'retirado') {
                $mesMarcado = $c->mes_factura_retiro ?: $mes;
                $fechaRetiroStr = 'Retirado en ' . $nombresMeses[$mesMarcado];
            }

            $personas[] = [
                'cedula' => $c->cedula,
                'nombre_completo' => $c->nombre_completo,
                'fecha_ingreso' => $c->fecha_ingreso ? \Carbon\Carbon::parse($c->fecha_ingreso)->format('d/m/Y') : '—',
                'fecha_retiro' => $fechaRetiroStr,
                'facturas' => !empty($facturasContrato) ? implode(', ', $facturasContrato) : '—',
                'fecha_factura' => !empty($fechasFacturaContrato) ? implode(', ', array_unique($fechasFacturaContrato)) : '—',
                'plan' => $c->plan_nombre ?: '—',
                'modalidad' => $c->modalidad_nombre ?: '—',
            ];
        }

        $tiposLabel = [
            'admon_vigentes' => 'Administraciones Vigentes',
            'afiliaciones' => 'Afiliaciones del Mes',
            'retiros_reales' => 'Retiros Reales',
            'retiros_informativos' => 'Retiros Informativos',
        ];

        return response()->json([
            'mes_label' => $nombresMeses[$mes] . ' ' . $anio,
            'tipo_label' => $tiposLabel[$tipo],
            'personas' => array_values($personas),
        ]);
    }

    public function consolidadoMensualWhatsapp(Request $request)
    {
        if (! Auth::user()->can('informes.ver')) {
            return response()->json(['error' => 'Acceso restringido.'], 403);
        }

        $aid  = $this->aliadoId();
        $mes  = (int) $request->input('mes');
        $anio = (int) $request->input('anio');

        if (!$mes || !$anio) {
            return response()->json(['error' => 'Parámetros inválidos.'], 400);
        }

        $nombresMeses = [
            1=>'Enero', 2=>'Febrero', 3=>'Marzo', 4=>'Abril',
            5=>'Mayo', 6=>'Junio', 7=>'Julio', 8=>'Agosto',
            9=>'Septiembre', 10=>'Octubre', 11=>'Noviembre', 12=>'Diciembre'
        ];

        $primerDia = \Carbon\Carbon::create($anio, $mes, 1)->startOfDay();
        $ultimoDia = $primerDia->copy()->endOfMonth();

        // 1. Lotes de cobro masivos
        // whereNull('e.campana_id'): los envíos de campañas de marketing viven en esta misma
        // tabla (para reusar el motor de envío), pero NO son cobro a clientes — sin este
        // filtro contaminarían el consolidado de cobros con lotes de publicidad fría.
        $lotesCobro = DB::table('whatsapp_envios_masivos as e')
            ->join('whatsapp_plantillas as p', 'p.id', '=', 'e.plantilla_id')
            ->where('e.aliado_id', $aid)
            ->where('e.mes', $mes)
            ->where('e.anio', $anio)
            ->whereNull('e.campana_id')
            ->select(
                'e.id as lote_id',
                DB::raw('CAST(e.created_at AS DATE) as fecha_envio'),
                'p.nombre_display as plantilla',
                'e.tipo_envio',
                'e.total_destinatarios',
                // Enviados y fallidos se cuentan del detalle, no de los contadores de la
                // cabecera: esos los va acumulando el worker y quedan desfasados cuando
                // un lote se corta. El detalle tiene una fila por destinatario.
                DB::raw("(SELECT COUNT(*) FROM whatsapp_envios_masivos_detalle d WHERE d.envio_id = e.id AND d.estado = 'enviado') as total_enviados"),
                DB::raw("(SELECT COUNT(*) FROM whatsapp_envios_masivos_detalle d WHERE d.envio_id = e.id AND d.estado = 'fallido') as total_fallidos"),
                'e.total_omitidos',
                'e.estado',
                DB::raw("'cobro' as tipo_lote")
            )
            ->get();

        // 2. Lotes de planilla masivos
        $lotesPlanilla = DB::table('planilla_envios_whatsapp as pe')
            ->leftJoin('whatsapp_plantillas as p', 'p.id', '=', 'pe.plantilla_id')
            ->where('pe.aliado_id', $aid)
            ->where('pe.mes', $mes)
            ->where('pe.anio', $anio)
            ->select(
                'pe.id as lote_id',
                DB::raw('CAST(pe.created_at AS DATE) as fecha_envio'),
                DB::raw("COALESCE(p.nombre_display, 'Envío Planilla') as plantilla"),
                'pe.tipo_envio',
                'pe.total_destinatarios',
                DB::raw("(SELECT COUNT(*) FROM planilla_envios_whatsapp_detalle d WHERE d.envio_id = pe.id AND d.estado = 'enviado') as total_enviados"),
                DB::raw("(SELECT COUNT(*) FROM planilla_envios_whatsapp_detalle d WHERE d.envio_id = pe.id AND d.estado = 'fallido') as total_fallidos"),
                'pe.total_omitidos',
                'pe.estado',
                DB::raw("'planilla' as tipo_lote")
            )
            ->get();

        // 3. Unificar todos los lotes
        $lotesUnificados = collect($lotesCobro)
            ->concat($lotesPlanilla)
            ->sortByDesc(fn($l) => $l->fecha_envio . '_' . $l->lote_id)
            ->values();

        // 4. Envíos individuales de planillas
        $individualesCount = DB::table('planilla_envios_whatsapp_detalle as ped')
            ->join('planos as p', 'p.id', '=', 'ped.plano_id')
            ->where('p.aliado_id', $aid)
            ->where('ped.envio_id', 0)
            ->where('ped.estado', 'enviado')
            ->whereBetween('ped.created_at', [$primerDia->toDateTimeString(), $ultimoDia->toDateTimeString()])
            ->count();

        // 5. Resumen de conversaciones iniciadas por el cliente
        $conversaciones = DB::table('whatsapp_conversaciones as conv')
            ->join('whatsapp_mensajes as m', 'm.conversacion_id', '=', 'conv.id')
            ->where('conv.aliado_id', $aid)
            ->whereNull('conv.deleted_at')
            ->where('m.direccion', 'entrante')
            ->whereBetween('m.created_at', [$primerDia->toDateTimeString(), $ultimoDia->toDateTimeString()])
            ->whereNotExists(function($sq) {
                $sq->select(DB::raw(1))
                   ->from('whatsapp_mensajes as prev')
                   ->whereColumn('prev.conversacion_id', 'm.conversacion_id')
                   ->whereColumn('prev.id', '<>', 'm.id')
                   ->whereRaw('prev.created_at >= DATEADD(hour, -24, m.created_at)')
                   ->whereRaw('prev.created_at < m.created_at');
            })
            ->select(
                'conv.id',
                'conv.nombre_contacto',
                'conv.wa_contact_id',
                'conv.estado',
                DB::raw('CAST(m.created_at AS DATE) as fecha_primera_respuesta')
            )
            ->orderBy('m.created_at', 'desc')
            ->get();

        return response()->json([
            'mes_label'           => $nombresMeses[$mes] . ' ' . $anio,
            'lotes'               => $lotesUnificados,
            'total_lotes'         => $lotesUnificados->count(),
            'total_enviados'      => $lotesUnificados->sum('total_enviados') + $individualesCount,
            'total_fallidos'      => $lotesUnificados->sum('total_fallidos'),
            'conversaciones'      => $conversaciones,
            'total_conv'          => $conversaciones->count(),
            
            // Estadísticas detalladas
            'lotes_planillas_cant' => $lotesPlanilla->count(),
            'lotes_planillas_env'  => $lotesPlanilla->sum('total_enviados'),
            'lotes_cobros_cant'    => $lotesCobro->count(),
            'lotes_cobros_env'     => $lotesCobro->sum('total_enviados'),
            'individuales_cant'    => $individualesCount
        ]);
    }
}



