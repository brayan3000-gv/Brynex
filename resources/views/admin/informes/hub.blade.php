@extends('layouts.app')
@section('modulo', 'Informes')
@section('contenido')
@php
    $mesesNombre = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    $nombreMes = $mesesNombre[$mes - 1];
    $mesAnt = \Carbon\Carbon::create($anio, $mes, 1)->subMonthNoOverflow();
    $mesAntCorto = mb_strtolower(mb_substr($mesesNombre[$mesAnt->month - 1], 0, 3));
    $diaRef = min($ref->day, $mesAnt->daysInMonth);
    $fmt = fn ($n) => number_format((float) $n, 0, ',', '.');
    $co = $kpis['cierre_operacion'];

    // Lo del cierre (sin facturar, planillas sin confirmar) es normal a mitad
    // de mes: se factura y se liquida en tandas. Se advierte desde el 25, o
    // siempre si el mes elegido ya cerró. Tareas e incapacidades no dependen
    // del mes y se advierten siempre.
    $esCierre = ! $esMesActual || now()->day >= 25;

    // Delta frente al mes anterior: [n, texto, color].
    $delta = function (int $n, string $txt, bool $buenoSiSube = true) {
        $bien = $n === 0 ? null : (($n > 0) === $buenoSiSube);
        $clase = $bien === null ? 'igual' : ($bien ? 'sube' : 'baja');
        $signo = $n > 0 ? '▲ +' : ($n < 0 ? '▼ ' : '● ');

        return '<span class="ci-delta '.$clase.'">'.$signo.number_format($n, 0, ',', '.').' '.e($txt).'</span>';
    };

    $alertas = array_values(array_filter([
        ['n' => $kpis['tareas_vencidas'], 'titulo' => 'Tareas vencidas', 'sub' => 'Pasaron su fecha límite',
            'url' => route('admin.tareas.index', ['semaforo' => 'vencidas'])],
        ['n' => $kpis['incapacidades_sin_gestion'], 'titulo' => 'Incapacidades sin gestión', 'sub' => 'Más de 7 días sin moverse',
            'url' => route('admin.incapacidades.index', ['kpi' => 'sin_gestion'])],
        ['n' => $esCierre ? $co['vigentes'] + $co['afiliaciones'] : 0, 'titulo' => 'Sin facturar en '.mb_strtolower($nombreMes),
            'sub' => $fmt($co['vigentes']).' contratos · '.$fmt($co['afiliaciones']).' afiliaciones',
            'url' => route('admin.informes.cierre_operacion', ['mes' => $mes, 'anio' => $anio])],
        ['n' => $esCierre ? $co['lotes'] : 0, 'titulo' => 'Planillas sin confirmar', 'sub' => 'Tandas de planos sin número de planilla',
            'url' => route('admin.informes.cierre_operacion', ['mes' => $mes, 'anio' => $anio])],
    ], fn ($a) => $a['n'] > 0));

    // Listados: el mes elegido si ya cerró; si va corrido, el anterior.
    $mesListados = $esMesActual ? $mesAnt : \Carbon\Carbon::create($anio, $mes, 1);
@endphp

<style>
.ci-wrap { max-width: 1200px; margin: 0 auto; }
.ci-hero {
    background: linear-gradient(90deg, #0a1628, #0d2550 55%, #1e40af);
    border-radius: 14px; padding: 1.3rem 1.6rem; color: #fff;
    display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;
}
.ci-hero h1 { margin: 0; font-size: 1.3rem; font-weight: 800; }
.ci-hero p { margin: .2rem 0 0; color: #93c5fd; font-size: .8rem; }
.ci-hero form { margin-left: auto; display: flex; gap: .5rem; align-items: center; }
.ci-hero label { font-size: .72rem; color: #93c5fd; font-weight: 600; }
.ci-hero select { padding: .4rem .55rem; border: 1px solid rgba(255,255,255,.25); background: rgba(255,255,255,.1); color: #fff; border-radius: 8px; font-size: .8rem; font-family: inherit; }
.ci-hero select option { color: #0f172a; }

.ci-delta { font-size: .68rem; font-weight: 700; padding: .1rem .45rem; border-radius: 999px; white-space: nowrap; display: inline-block; }
.ci-delta.sube { background: #d1fae5; color: #059669; }
.ci-delta.baja { background: #fee2e2; color: #dc2626; }
.ci-delta.igual, .ci-delta.ref { background: #f1f5f9; color: #64748b; }

/* Requiere atención */
.ci-alertas { background: #fff; border-radius: 14px; border: 1px solid #fecaca; overflow: hidden; margin-top: 1rem; display: flex; flex-wrap: wrap; }
.ci-alertas .tit { background: linear-gradient(90deg, #7f1d1d, #b91c1c); color: #fff; font-weight: 800; font-size: .82rem; padding: .8rem 1.1rem; display: flex; align-items: center; }
.ci-alerta { flex: 1; min-width: 210px; display: flex; gap: .7rem; align-items: center; padding: .7rem 1.1rem; border-left: 1px solid #f1f5f9; text-decoration: none; color: inherit; }
.ci-alerta:hover { background: #fef2f2; }
.ci-alerta .n { font-size: 1.35rem; font-weight: 800; color: #dc2626; }
.ci-alerta .t { font-size: .78rem; font-weight: 700; color: #1e293b; }
.ci-alerta .s { font-size: .68rem; color: #94a3b8; }
.ci-alerta .ir { margin-left: auto; color: #3b82f6; font-weight: 800; }
.ci-al-dia { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; border-radius: 12px; padding: .7rem 1rem; font-size: .8rem; font-weight: 600; margin-top: 1rem; }

/* Bloques Hoy / Este mes / Gestión */
.ci-bloques { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-top: 1rem; }
.ci-bloque { background: #fff; border-radius: 14px; border: 1px solid #e2e8f0; overflow: hidden; }
.ci-bloque-head { padding: .75rem 1rem; background: #eff6ff; border-bottom: 3px solid #3b82f6; display: flex; gap: .5rem; align-items: center; }
.ci-bloque-head b { color: #0d2550; font-size: .9rem; }
.ci-bloque-head span { margin-left: auto; font-size: .7rem; color: #64748b; }
.ci-fila { display: flex; align-items: center; gap: .75rem; padding: .75rem 1rem; border-bottom: 1px solid #f1f5f9; text-decoration: none; color: inherit; }
.ci-fila:last-child { border-bottom: none; }
.ci-fila:hover { background: #f8fbff; }
.ci-fila .ico { font-size: 1.2rem; width: 1.7rem; text-align: center; }
.ci-fila .t { font-size: .82rem; font-weight: 700; color: #1e293b; }
.ci-fila .s { font-size: .68rem; color: #94a3b8; margin-top: .1rem; }
.ci-fila .v { margin-left: auto; text-align: right; white-space: nowrap; }
.ci-fila .v b { display: block; font-size: 1.1rem; font-weight: 800; color: #0d2550; }
.ci-fila .v .ver { font-size: .8rem; font-weight: 800; color: #3b82f6; }
.ci-fila form { margin: 0; }
.ci-fila button.excel { background: #15803d; color: #fff; border: none; border-radius: 8px; padding: .35rem .7rem; font-size: .75rem; font-weight: 700; cursor: pointer; font-family: inherit; }
.ci-fila button.excel:hover { background: #166534; }

/* Financiero */
.ci-sec { font-size: .74rem; font-weight: 800; text-transform: uppercase; letter-spacing: .08em; color: #94a3b8; margin: 1.6rem 0 .75rem; }
.ci-fin { display: grid; grid-template-columns: repeat(var(--cols), 1fr); gap: 1rem; }
.ci-fin-estado {
    grid-column: span 2; position: relative; overflow: hidden;
    background: linear-gradient(135deg, #0a1628, #0d2550 50%, #1e40af);
    border-radius: 14px; padding: 1.25rem 1.4rem; color: #fff; text-decoration: none;
    display: flex; flex-direction: column; gap: .9rem; box-shadow: 0 6px 24px rgba(30,64,175,.25);
    transition: transform .15s, box-shadow .15s;
}
.ci-fin-estado:hover { transform: translateY(-2px); box-shadow: 0 12px 32px rgba(30,64,175,.35); }
.ci-fin-estado::after { content: '💰'; position: absolute; right: -.6rem; bottom: -1.2rem; font-size: 6rem; opacity: .08; }
.ci-fin-estado .cab { display: flex; align-items: center; gap: .6rem; }
.ci-fin-estado .cab .ic { width: 2.3rem; height: 2.3rem; border-radius: 10px; background: rgba(255,255,255,.12); display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
.ci-fin-estado .cab b { font-size: .95rem; }
.ci-fin-estado .cab span { display: block; font-size: .72rem; color: #93c5fd; font-weight: 500; }
.ci-fin-estado .monto { font-size: 2rem; font-weight: 800; letter-spacing: -.02em; line-height: 1; }
.ci-fin-estado .monto small { display: block; font-size: .72rem; font-weight: 600; color: #93c5fd; letter-spacing: 0; margin-top: .35rem; }
.ci-fin-estado .chips { display: flex; gap: .4rem; flex-wrap: wrap; }
.ci-fin-estado .chips span { font-size: .68rem; font-weight: 700; background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.18); border-radius: 999px; padding: .2rem .6rem; color: #dbeafe; }
.ci-fin-estado .ci-delta { background: rgba(16,185,129,.2); color: #6ee7b7; }
.ci-fin-estado .ci-delta.baja { background: rgba(239,68,68,.2); color: #fca5a5; }
.ci-fin-estado .ci-delta.igual { background: rgba(255,255,255,.12); color: #dbeafe; }

.ci-fin-card {
    background: #fff; border-radius: 14px; border: 1px solid #e2e8f0; padding: 1.1rem 1.15rem;
    text-decoration: none; color: inherit; display: flex; flex-direction: column; gap: .55rem;
    position: relative; overflow: hidden; transition: transform .15s, box-shadow .15s, border-color .15s;
}
.ci-fin-card::before { content: ''; position: absolute; inset: 0 0 auto 0; height: 4px; background: var(--c); }
.ci-fin-card:hover { transform: translateY(-2px); border-color: var(--c); box-shadow: 0 8px 24px rgba(15,23,42,.08); }
.ci-fin-card .ic { width: 2.3rem; height: 2.3rem; border-radius: 10px; background: var(--fondo); display: flex; align-items: center; justify-content: center; font-size: 1.2rem; }
.ci-fin-card b { font-size: .9rem; color: #0f172a; }
.ci-fin-card ul { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: .2rem; }
.ci-fin-card li { font-size: .72rem; color: #64748b; }
.ci-fin-card li::before { content: '•'; color: var(--c); font-weight: 800; margin-right: .35rem; }
.ci-fin-card .abrir { margin-top: auto; font-size: .76rem; font-weight: 800; color: var(--c); }

@media (max-width: 960px) {
    .ci-bloques { grid-template-columns: 1fr; }
    .ci-fin { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 600px) {
    .ci-fin { grid-template-columns: 1fr; }
    .ci-fin-estado { grid-column: span 1; }
}
</style>

<div class="ci-wrap">

    {{-- Encabezado con el selector de mes --}}
    <div class="ci-hero">
        <div>
            <h1>📊 Centro de Informes</h1>
            <p>Resumen ejecutivo · {{ mb_strtolower($nombreMes) }} de {{ $anio }}
                @if($esMesActual && now()->day < now()->daysInMonth)
                    · en curso, cifras al {{ now()->day }}
                @endif
            </p>
        </div>
        <form method="GET">
            <label for="ci-mes">Mes</label>
            <select id="ci-mes" name="mes" onchange="this.form.submit()">
                @foreach($mesesNombre as $i => $nm)
                    <option value="{{ $i + 1 }}" @selected($mes === $i + 1)>{{ $nm }}</option>
                @endforeach
            </select>
            <select name="anio" onchange="this.form.submit()">
                @for($a = now()->year; $a >= now()->year - 3; $a--)
                    <option value="{{ $a }}" @selected($anio === $a)>{{ $a }}</option>
                @endfor
            </select>
        </form>
    </div>

    @can('informes.ver')
    {{-- Requiere atención: solo lo que tiene algo pendiente; cada número abre
         la lista ya filtrada con la misma regla con la que se contó. --}}
    @if(count($alertas))
        <div class="ci-alertas">
            <div class="tit">⚠️ Requiere atención</div>
            @foreach($alertas as $a)
                <a href="{{ $a['url'] }}" class="ci-alerta">
                    <div class="n">{{ $fmt($a['n']) }}</div>
                    <div><div class="t">{{ $a['titulo'] }}</div><div class="s">{{ $a['sub'] }}</div></div>
                    <div class="ir">→</div>
                </a>
            @endforeach
        </div>
    @else
        <div class="ci-al-dia">✅ Todo al día: sin tareas vencidas{{ $esCierre ? ', incapacidades con gestión reciente y el mes facturado' : ' e incapacidades con gestión reciente' }}.</div>
    @endif

    <div class="ci-bloques">
        {{-- Hoy: foto actual, no cambia con el mes elegido --}}
        <div class="ci-bloque">
            <div class="ci-bloque-head">🟢 <b>Hoy</b><span>foto actual</span></div>
            <a href="{{ route('admin.informes.clientes_activos') }}" class="ci-fila">
                <div class="ico">👥</div>
                <div><div class="t">Clientes activos</div><div class="s">{{ $fmt($kpis['clientes_activos']) }} contratos</div></div>
                <div class="v"><b>{{ $fmt($kpis['clientes_unicos']) }}</b>{!! $delta($kpis['clientes_activos'] - $kpis['activos_cierre_anterior'], 'vs. cierre '.mb_strtolower(mb_substr($mesesNombre[now()->subMonthNoOverflow()->month - 1], 0, 3))) !!}</div>
            </a>
            <a href="{{ route('admin.informes.empresas_clientes') }}" class="ci-fila">
                <div class="ico">🏭</div>
                <div><div class="t">Empresas clientes</div><div class="s">Con contratos activos · de {{ $fmt($kpis['empresas']) }}</div></div>
                <div class="v"><b>{{ $fmt($kpis['empresas_con_activos']) }}</b></div>
            </a>
            <a href="{{ route('admin.informes.por_razon_social') }}" class="ci-fila">
                <div class="ico">🏢</div>
                <div><div class="t">Razones sociales</div><div class="s">Activas</div></div>
                <div class="v"><b>{{ $fmt($kpis['razones_sociales']) }}</b></div>
            </a>
            <a href="{{ route('admin.informes.por_entidades') }}" class="ci-fila">
                <div class="ico">🏥</div>
                <div><div class="t">Por entidades</div><div class="s">EPS · AFP · ARL · Caja</div></div>
                <div class="v"><span class="ver">Ver →</span></div>
            </a>
        </div>

        {{-- Este mes: sigue al mes elegido --}}
        <div class="ci-bloque">
            <div class="ci-bloque-head">📅 <b>{{ $esMesActual ? 'Este mes' : $nombreMes }}</b><span>{{ $esMesActual ? mb_strtolower($nombreMes).' · al '.now()->day : 'mes cerrado' }}</span></div>
            <a href="{{ route('admin.informes.consolidado_mensual') }}" class="ci-fila">
                <div class="ico">📥</div>
                <div><div class="t">Afiliaciones</div><div class="s">Nuevas + reingresos</div></div>
                <div class="v"><b>{{ $kpis['afiliaciones_mes_nuevas'] }} + {{ $kpis['afiliaciones_mes_total'] - $kpis['afiliaciones_mes_nuevas'] }}</b>{!! $delta($kpis['afiliaciones_mes_total'] - $kpis['afiliaciones_ant_mismo_dia'], $esMesActual ? 'vs. 1–'.$diaRef.' '.$mesAntCorto : 'vs. '.$mesAntCorto) !!}</div>
            </a>
            <a href="{{ route('admin.informes.retirados_mes', ['mes' => $mes, 'anio' => $anio]) }}" class="ci-fila">
                <div class="ico">🚪</div>
                <div><div class="t">Retirados</div><div class="s">Sin renovar / total</div></div>
                <div class="v"><b>{{ $kpis['retiros_mes_sin_renovar'] }} / {{ $kpis['retiros_mes_total'] }}</b><span class="ci-delta ref">{{ $mesAntCorto }} completo: {{ $kpis['retiros_mes_anterior'] }}</span></div>
            </a>
            <a href="{{ route('admin.informes.consolidado_mensual') }}" class="ci-fila">
                <div class="ico">📈</div>
                <div><div class="t">Ingresos y retiros</div><div class="s">Consolidado · 6 meses de tendencia</div></div>
                <div class="v"><span class="ver">Ver →</span></div>
            </a>
            <a href="{{ route('admin.informes.afiliaciones_retiros', ['mes' => $mes, 'anio' => $anio]) }}" class="ci-fila">
                <div class="ico">🗂️</div>
                <div><div class="t">Por motivo</div><div class="s">Por qué entran y por qué salen</div></div>
                <div class="v"><span class="ver">Ver →</span></div>
            </a>
            {{-- Listados del cierre: activos, pagos, retiros y afiliaciones en un Excel --}}
            <div class="ci-fila">
                <div class="ico">📑</div>
                <div><div class="t">Listados de {{ mb_strtolower($mesesNombre[$mesListados->month - 1]) }}</div><div class="s">Activos · pagos · retiros · afiliaciones</div></div>
                <div class="v">
                    <form method="GET" action="{{ route('admin.informes.listados_mes') }}">
                        <input type="hidden" name="mes" value="{{ $mesListados->month }}">
                        <input type="hidden" name="anio" value="{{ $mesListados->year }}">
                        <button type="submit" class="excel">⬇ Excel</button>
                    </form>
                </div>
            </div>
        </div>

        {{-- Gestión: pendientes de hoy --}}
        <div class="ci-bloque">
            <div class="ci-bloque-head">🛠️ <b>Gestión</b><span>pendientes</span></div>
            <a href="{{ route('admin.informes.incapacidades') }}" class="ci-fila">
                <div class="ico">🏨</div>
                <div><div class="t">Incapacidades</div><div class="s">Casos activos</div></div>
                <div class="v"><b style="color:#dc2626;">{{ $fmt($kpis['incapacidades']) }}</b></div>
            </a>
            <a href="{{ route('admin.informes.tareas') }}" class="ci-fila">
                <div class="ico">📌</div>
                <div><div class="t">Tareas</div><div class="s">Activas{{ $kpis['tareas_vencidas'] ? ' · '.$kpis['tareas_vencidas'].' vencidas' : '' }}</div></div>
                <div class="v"><b style="color:#ea580c;">{{ $fmt($kpis['tareas']) }}</b></div>
            </a>
            <a href="{{ route('admin.informes.cierre_operacion', ['mes' => $mes, 'anio' => $anio]) }}" class="ci-fila">
                <div class="ico">✅</div>
                <div><div class="t">Cierre de operación</div><div class="s">{{ $fmt($co['vigentes']) }} sin facturar · {{ $fmt($co['afiliaciones']) }} afiliaciones{{ $esCierre ? '' : ' · se advierte desde el 25' }}</div></div>
                <div class="v"><b style="color:{{ $co['total'] === 0 ? '#059669' : ($esCierre ? '#dc2626' : '#64748b') }};">{{ $fmt($co['lotes']) }}</b><span class="s">tandas</span></div>
            </a>
        </div>
    </div>
    @endcan

    {{-- ── Financiero ── --}}
    <div class="ci-sec">Financiero</div>
    @php
        // Todas en una fila: el estado financiero ocupa dos columnas y cada
        // rol ve un número distinto de tarjetas.
        $colsFin = ($esFinanciero ? 2 : 0) + 1
            + (auth()->user()->can('informes.financiero') ? 1 : 0)
            + (auth()->user()->can('comisiones.ver') ? 1 : 0);
    @endphp
    <div class="ci-fin" style="--cols: {{ $colsFin }};">
        {{-- Estado Financiero: solo superadmin y contable (informes.financiero).
             Un admin ve las demás tarjetas pero no la utilidad del aliado. --}}
        @if($esFinanciero)
            @php
                $ing = $kpis['ingresos_mes'] ?? 0;
                $ingAnt = $kpis['ingresos_mes_anterior'] ?? 0;
            @endphp
            <a href="{{ route('admin.informes.financiero', ['mes' => $mes, 'anio' => $anio]) }}" class="ci-fin-estado">
                <div class="cab">
                    <div class="ic">💰</div>
                    <div><b>Estado financiero</b><span>Ingresos · egresos · utilidad · bancos · tendencia</span></div>
                </div>
                <div class="monto">
                    $ {{ $fmt($ing) }}
                    <small>Ingresos de {{ mb_strtolower($nombreMes) }}{{ $esMesActual ? ' a la fecha' : '' }} · {{ $mesAntCorto }}: $ {{ $fmt($ingAnt) }}</small>
                </div>
                <div class="chips">
                    @unless($esMesActual)
                        {!! $delta((int) round($ing - $ingAnt), 'vs. '.$mesAntCorto) !!}
                    @endunless
                    <span>Ver utilidad del mes →</span>
                </div>
            </a>
        @endif

        <a href="{{ route('admin.informes.conciliacion_bancos') }}" class="ci-fin-card" style="--c:#0369a1;--fondo:#e0f2fe;">
            <div class="ic">🏦</div>
            <b>Conciliación de bancos</b>
            <ul><li>Movimientos del mes por cuenta</li><li>Confirmar consignaciones</li><li>Saldos</li></ul>
            <span class="abrir">Abrir →</span>
        </a>

        @can('informes.financiero')
        <a href="{{ route('admin.informes.extracto_banco') }}" class="ci-fin-card" style="--c:#0f766e;--fondo:#ccfbf1;">
            <div class="ic">🧾</div>
            <b>Extracto del banco</b>
            <ul><li>Lo que entró y nadie registró</li><li>Lo registrado sin respaldo</li><li>Cruce automático</li></ul>
            <span class="abrir">Abrir →</span>
        </a>
        @endcan

        {{-- Distribución de Afiliaciones: entrar con `comisiones.ver` (admin,
             contable y superadmin), repartir con `comisiones.gestionar`. --}}
        @can('comisiones.ver')
        <a href="{{ route('admin.informes.comisiones.afiliaciones', ['mes' => $mes, 'anio' => $anio]) }}" class="ci-fin-card" style="--c:#7c3aed;--fondo:#ede9fe;">
            <div class="ic">📋</div>
            <b>Distribución de afiliaciones</b>
            <ul><li>Repartir cada afiliación entre asesores</li><li>Comisiones del mes</li></ul>
            <span class="abrir">Abrir →</span>
        </a>
        @endcan
    </div>
</div>

@endsection
