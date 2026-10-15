@extends('layouts.app')
@section('modulo', 'Informes')
@section('contenido')

<div style="max-width:1200px;margin:0 auto;">

    {{-- Header --}}
    <div style="margin-bottom:1.5rem;">
        <h1 style="font-size:1.4rem;font-weight:700;color:#0d2550;">📊 Centro de Informes</h1>
        <p style="color:#64748b;font-size:0.85rem;margin-top:0.25rem;">Resumen ejecutivo · {{ now()->isoFormat('MMMM [de] YYYY') }}
            @if(now()->day < now()->daysInMonth)
                · <span style="color:#d97706;font-weight:600;">mes en curso, cifras a la fecha ({{ now()->isoFormat('D [de] MMMM') }})</span>
            @endif
        </p>
    </div>

    @can('informes.ver')
    @php
        $co = $kpis['cierre_operacion'];
        $alertas = array_values(array_filter([
            ['n' => $kpis['tareas_vencidas'], 'titulo' => 'Tareas vencidas', 'sub' => 'Pasaron su fecha límite',
                'url' => route('admin.tareas.index', ['semaforo' => 'vencidas'])],
            ['n' => $kpis['incapacidades_sin_gestion'], 'titulo' => 'Incapacidades sin gestión', 'sub' => 'Más de 7 días sin moverse',
                'url' => route('admin.incapacidades.index', ['kpi' => 'sin_gestion'])],
            ['n' => $co['vigentes'] + $co['afiliaciones'], 'titulo' => 'Sin facturar este mes',
                'sub' => number_format($co['vigentes'], 0, ',', '.').' contratos · '.number_format($co['afiliaciones'], 0, ',', '.').' afiliaciones',
                'url' => route('admin.informes.cierre_operacion')],
            ['n' => $co['lotes'], 'titulo' => 'Planillas sin confirmar', 'sub' => 'Tandas de planos sin número de planilla',
                'url' => route('admin.informes.cierre_operacion')],
        ], fn ($a) => $a['n'] > 0));
    @endphp
    {{-- Requiere atención: solo lo que tiene algo pendiente; cada número abre
         la lista ya filtrada con la misma regla con la que se contó. --}}
    @if(count($alertas))
    <div style="background:#fff;border-radius:14px;border:1px solid #fecaca;box-shadow:0 1px 6px rgba(0,0,0,.06);overflow:hidden;margin-bottom:1.75rem;">
        <div style="background:linear-gradient(90deg,#7f1d1d,#b91c1c);color:#fff;padding:.7rem 1.2rem;font-weight:800;font-size:.9rem;">
            ⚠️ Requiere atención <span style="font-weight:500;font-size:.75rem;opacity:.85;">· cada número abre la lista</span>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));">
            @foreach($alertas as $a)
            <a href="{{ $a['url'] }}" style="display:flex;gap:.8rem;align-items:center;padding:1rem 1.2rem;border-right:1px solid #f1f5f9;text-decoration:none;color:inherit;"
               onmouseover="this.style.background='#fef2f2'" onmouseout="this.style.background=''">
                <div style="font-size:1.6rem;font-weight:800;color:#dc2626;min-width:2.6rem;text-align:center;">{{ number_format($a['n'], 0, ',', '.') }}</div>
                <div>
                    <div style="font-size:.82rem;font-weight:700;color:#1e293b;">{{ $a['titulo'] }}</div>
                    <div style="font-size:.7rem;color:#94a3b8;margin-top:.1rem;">{{ $a['sub'] }}</div>
                </div>
                <div style="margin-left:auto;color:#3b82f6;font-weight:800;">→</div>
            </a>
            @endforeach
        </div>
    </div>
    @else
    <div style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:12px;padding:.75rem 1rem;font-size:.82rem;font-weight:600;margin-bottom:1.75rem;">
        ✅ Todo al día: sin tareas vencidas, incapacidades con gestión reciente y el mes facturado.
        <a href="{{ route('admin.informes.cierre_operacion') }}" style="color:#047857;margin-left:.5rem;">Ver cierre de operación →</a>
    </div>
    @endif
    @endcan

    {{-- Grid KPIs operativos --}}
    @can('informes.ver')
    <h2 style="font-size:0.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin-bottom:.75rem;">Operaciones</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(175px,1fr));gap:1rem;margin-bottom:1.75rem;">

        @php
        $mesAnt = now()->startOfMonth()->subMonthNoOverflow();
        $mesAntCorto = mb_substr($mesAnt->isoFormat('MMMM'), 0, 3);
        $diaRef = min(now()->day, $mesAnt->daysInMonth);
        // Delta frente al mes anterior. `bueno` dice de qué color va la subida.
        $delta = fn (int $n, string $txt, bool $buenoSiSube = true) => ['n' => $n, 'txt' => $txt, 'bueno' => $buenoSiSube];
        $cards = [
            ['icono'=>'👥','label'=>'Clientes Activos','val'=>number_format($kpis['clientes_unicos'],0,',','.').' / '.number_format($kpis['clientes_activos'],0,',','.'),'color'=>'#3b82f6','url'=>route('admin.informes.clientes_activos'),'desc'=>'Clientes únicos / Contratos',
                'delta'=>$delta($kpis['clientes_activos'] - $kpis['activos_cierre_anterior'], 'vs. cierre '.$mesAntCorto)],
            ['icono'=>'🏢','label'=>'Por Razón Social','val'=>$kpis['razones_sociales'],'color'=>'#06b6d4','url'=>route('admin.informes.por_razon_social'),'desc'=>'RS activas'],
            ['icono'=>'📥','label'=>'Afiliaciones del Mes','val'=>$kpis['afiliaciones_mes_nuevas'].' + '.($kpis['afiliaciones_mes_total'] - $kpis['afiliaciones_mes_nuevas']),'color'=>'#8b5cf6','url'=>route('admin.informes.consolidado_mensual'),'desc'=>'Nuevas + Reingresos',
                'delta'=>$delta($kpis['afiliaciones_mes_total'] - $kpis['afiliaciones_ant_mismo_dia'], 'vs. 1–'.$diaRef.' '.$mesAntCorto)],
            ['icono'=>'🏭','label'=>'Empresas Clientes','val'=>$kpis['empresas_con_activos'],'color'=>'#0ea5e9','url'=>route('admin.informes.empresas_clientes'),'desc'=>'Con contratos activos · de '.number_format($kpis['empresas'],0,',','.')],
            ['icono'=>'🏥','label'=>'Por Entidades','val'=>'EPS/AFP/ARL','color'=>'#10b981','url'=>route('admin.informes.por_entidades'),'desc'=>'Ver distribución'],
            ['icono'=>'🚪','label'=>'Retirados del Mes','val'=>$kpis['retiros_mes_sin_renovar'].' / '.$kpis['retiros_mes_total'],'color'=>'#f59e0b','url'=>route('admin.informes.retirados_mes'),'desc'=>'Sin renovar / Total',
                'ref'=>ucfirst($mesAntCorto).' completo: '.$kpis['retiros_mes_anterior']],
            ['icono'=>'🏨','label'=>'Incapacidades','val'=>$kpis['incapacidades'],'color'=>'#ef4444','url'=>route('admin.informes.incapacidades'),'desc'=>'Casos activos'],
            ['icono'=>'📌','label'=>'Tareas','val'=>$kpis['tareas'],'color'=>'#f97316','url'=>route('admin.informes.tareas'),'desc'=>'Tareas activas'],
        ];
        @endphp

        @foreach($cards as $c)
        <a href="{{ $c['url'] }}" style="display:flex;flex-direction:column;gap:.6rem;background:#fff;border-radius:14px;padding:1.25rem 1rem;text-decoration:none;border:2px solid transparent;box-shadow:0 1px 6px rgba(0,0,0,.06);transition:all .18s;cursor:pointer;"
           onmouseover="this.style.borderColor='{{ $c['color'] }}';this.style.transform='translateY(-3px)';this.style.boxShadow='0 8px 24px rgba(0,0,0,.1)'"
           onmouseout="this.style.borderColor='transparent';this.style.transform='';this.style.boxShadow='0 1px 6px rgba(0,0,0,.06)'">
            <div style="font-size:1.6rem;">{{ $c['icono'] }}</div>
            <div style="font-size:1.5rem;font-weight:800;color:{{ $c['color'] }};line-height:1;">{{ $c['val'] }}</div>
            <div style="font-size:.8rem;font-weight:700;color:#1e293b;">{{ $c['label'] }}</div>
            <div style="font-size:.72rem;color:#94a3b8;">{{ $c['desc'] }}</div>
            @if(isset($c['delta']))
                @php
                    $d = $c['delta'];
                    $bien = $d['n'] === 0 ? null : (($d['n'] > 0) === $d['bueno']);
                    [$fondo, $tinta] = $bien === null ? ['#f1f5f9', '#64748b'] : ($bien ? ['#d1fae5', '#059669'] : ['#fee2e2', '#dc2626']);
                @endphp
                <span style="font-size:.7rem;font-weight:700;padding:.12rem .45rem;border-radius:999px;width:fit-content;white-space:nowrap;background:{{ $fondo }};color:{{ $tinta }};">
                    {{ $d['n'] > 0 ? '▲ +' : ($d['n'] < 0 ? '▼ ' : '● ') }}{{ number_format($d['n'], 0, ',', '.') }} {{ $d['txt'] }}
                </span>
            @elseif(isset($c['ref']))
                <span style="font-size:.7rem;font-weight:600;color:#64748b;">{{ $c['ref'] }}</span>
            @endif
        </a>
        @endforeach
    </div>

    {{-- Listados del cierre: por defecto el mes anterior, que es el que se
         entrega. Activos, pagos, retiros y afiliaciones en un solo Excel. --}}
    @php
        $mesCierre = now()->subMonthNoOverflow();
        $mesesNombre = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    @endphp
    <form method="GET" action="{{ route('admin.informes.listados_mes') }}"
          style="display:flex;flex-wrap:wrap;align-items:center;gap:1rem;background:#fff;border-radius:14px;padding:1.1rem 1.25rem;box-shadow:0 1px 6px rgba(0,0,0,.06);margin-bottom:1.75rem;">
        <div style="font-size:1.6rem;">📑</div>
        <div style="flex:1;min-width:220px;">
            <div style="font-size:.9rem;font-weight:700;color:#1e293b;">Listados del mes</div>
            <div style="font-size:.74rem;color:#94a3b8;margin-top:.15rem;">Activos al cierre · Pagos · Retiros · Afiliaciones — un Excel con una hoja por listado</div>
        </div>
        <select name="mes" style="padding:.45rem .6rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;">
            @foreach($mesesNombre as $i => $nombreMes)
                <option value="{{ $i + 1 }}" @selected($mesCierre->month === $i + 1)>{{ $nombreMes }}</option>
            @endforeach
        </select>
        <select name="anio" style="padding:.45rem .6rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;">
            @for($a = now()->year; $a >= now()->year - 3; $a--)
                <option value="{{ $a }}" @selected($mesCierre->year === $a)>{{ $a }}</option>
            @endfor
        </select>
        <button type="submit" style="display:inline-flex;align-items:center;gap:.4rem;background:#15803d;color:#fff;border:none;border-radius:8px;padding:.5rem 1rem;font-size:.85rem;font-weight:600;cursor:pointer;">
            ⬇ Exportar Excel
        </button>
    </form>
    @endcan

    <h2 style="font-size:0.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin-bottom:.75rem;">Financiero</h2>

    {{-- Estado Financiero: solo superadmin y contable (informes.financiero).
         Un admin ve la conciliación de abajo pero no la utilidad del aliado. --}}
    @if($esFinanciero)
    <a href="{{ route('admin.informes.financiero') }}" style="display:flex;align-items:center;gap:1.25rem;background:linear-gradient(135deg,#0d2550,#1e40af);border-radius:14px;padding:1.5rem 1.75rem;text-decoration:none;border:2px solid transparent;box-shadow:0 4px 20px rgba(30,64,175,.3);transition:all .18s;"
       onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 12px 32px rgba(30,64,175,.4)'"
       onmouseout="this.style.transform='';this.style.boxShadow='0 4px 20px rgba(30,64,175,.3)'">
        <div style="font-size:2.5rem;">💰</div>
        <div>
            <div style="font-size:1rem;font-weight:700;color:#fff;">Estado Financiero</div>
            <div style="font-size:.82rem;color:rgba(255,255,255,.6);margin-top:.2rem;">Ingresos · Egresos · Utilidad · Bancos · Gráficas de tendencia</div>
        </div>
        @if(isset($kpis['ingresos_mes']))
        <div style="margin-left:auto;text-align:right;">
            <div style="font-size:1.4rem;font-weight:800;color:#93c5fd;">$ {{ number_format($kpis['ingresos_mes'],0,',','.') }}</div>
            <div style="font-size:.72rem;color:rgba(255,255,255,.5);">Ingresos este mes</div>
        </div>
        @endif
    </a>
    @endif

    {{-- Conciliación de Bancos --}}
    <a href="{{ route('admin.informes.conciliacion_bancos') }}" style="display:flex;align-items:center;gap:1.25rem;background:linear-gradient(135deg,#0369a1,#075985);border-radius:14px;padding:1.5rem 1.75rem;text-decoration:none;border:2px solid transparent;box-shadow:0 4px 20px rgba(3,105,161,.3);transition:all .18s;margin-top:1rem;"
       onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 12px 32px rgba(3,105,161,.4)'"
       onmouseout="this.style.transform='';this.style.boxShadow='0 4px 20px rgba(3,105,161,.3)'">
        <div style="font-size:2.5rem;">🏦</div>
        <div>
            <div style="font-size:1rem;font-weight:700;color:#fff;">Conciliación de Bancos</div>
            <div style="font-size:.82rem;color:rgba(255,255,255,.7);margin-top:.2rem;">Movimientos del mes por cuenta · Confirmar consignaciones · Saldos</div>
        </div>
    </a>

    {{-- Extracto del banco: la bandeja del cruce automático. Va con el mismo
         permiso que el resto de la plata del aliado. --}}
    @can('informes.financiero')
    <a href="{{ route('admin.informes.extracto_banco') }}" style="display:flex;align-items:center;gap:1.25rem;background:linear-gradient(135deg,#0f766e,#115e59);border-radius:14px;padding:1.5rem 1.75rem;text-decoration:none;border:2px solid transparent;box-shadow:0 4px 20px rgba(15,118,110,.3);transition:all .18s;margin-top:1rem;"
       onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 12px 32px rgba(15,118,110,.4)'"
       onmouseout="this.style.transform='';this.style.boxShadow='0 4px 20px rgba(15,118,110,.3)'">
        <div style="font-size:2.5rem;">🧾</div>
        <div>
            <div style="font-size:1rem;font-weight:700;color:#fff;">Extracto del banco</div>
            <div style="font-size:.82rem;color:rgba(255,255,255,.7);margin-top:.2rem;">Lo que entró y nadie registró · Lo registrado sin respaldo · Cruce automático</div>
        </div>
    </a>
    @endcan

    {{-- Distribución de Afiliaciones: entrar con `comisiones.ver` (admin,
         contable y superadmin), repartir con `comisiones.gestionar` (solo
         admin y superadmin, que es quien puede editar). --}}
    @can('comisiones.ver')
    <a href="{{ route('admin.informes.comisiones.afiliaciones', ['mes' => now()->month, 'anio' => now()->year]) }}" style="display:flex;align-items:center;gap:1.25rem;background:linear-gradient(135deg,#7c3aed,#5b21b6);border-radius:14px;padding:1.5rem 1.75rem;text-decoration:none;border:2px solid transparent;box-shadow:0 4px 20px rgba(124,58,237,.3);transition:all .18s;margin-top:1rem;"
       onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 12px 32px rgba(124,58,237,.4)'"
       onmouseout="this.style.transform='';this.style.boxShadow='0 4px 20px rgba(124,58,237,.3)'">
        <div style="font-size:2.5rem;">📋</div>
        <div>
            <div style="font-size:1rem;font-weight:700;color:#fff;">Distribución de Afiliaciones</div>
            <div style="font-size:.82rem;color:rgba(255,255,255,.7);margin-top:.2rem;">Repartir el valor de cada afiliación entre los asesores · Comisiones del mes</div>
        </div>
    </a>
    @endcan

    {{-- Historial --}}
    @can('informes.ver')
    <h2 style="font-size:0.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin-top:1.5rem;margin-bottom:.75rem;">Historial Informes</h2>

    {{-- Reporte de Ingresos y Retiros (Consolidado Mensual) --}}
    <a href="{{ route('admin.informes.consolidado_mensual') }}" style="display:flex;align-items:center;gap:1.25rem;background:linear-gradient(135deg,#0d9488,#0f766e);border-radius:14px;padding:1.5rem 1.75rem;text-decoration:none;border:2px solid transparent;box-shadow:0 4px 20px rgba(13,148,136,.3);transition:all .18s;"
       onmouseover="this.style.transform='translateY(-3px)';this.style.boxShadow='0 12px 32px rgba(13,148,136,.4)'"
       onmouseout="this.style.transform='';this.style.boxShadow='0 4px 20px rgba(13,148,136,.3)'">
        <div style="font-size:2.5rem;">📈</div>
        <div>
            <div style="font-size:1rem;font-weight:700;color:#fff;">Reporte de Ingresos y Retiros</div>
            <div style="font-size:.82rem;color:rgba(255,255,255,.8);margin-top:.2rem;">Administración y afiliaciones históricas por mes · 6 meses de tendencia</div>
        </div>
    </a>
    @endcan

</div>
@endsection
