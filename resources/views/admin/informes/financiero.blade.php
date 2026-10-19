@extends('layouts.app')
@section('modulo','Estado Financiero')
@push('styles')
<style>
.fin-kpi{background:#fff;border-radius:14px;padding:1.1rem .8rem;box-shadow:0 1px 8px rgba(0,0,0,.06);border-left:4px solid var(--c);}
.fin-kpi .val{font-size:1.2rem;font-weight:800;color:var(--c);line-height:1.1;white-space:nowrap;}
.fin-kpi .lab{font-size:.8rem;font-weight:600;color:#334155;margin-top:.3rem;}
.fin-kpi .sub{font-size:.72rem;color:#94a3b8;margin-top:.15rem;}
.dia-row{display:grid;grid-template-columns:50px 1fr 1fr 1fr 1fr 1fr;gap:.5rem;padding:.5rem .75rem;border-bottom:1px solid #f1f5f9;font-size:.8rem;align-items:center;cursor:pointer;transition:background .12s;}
.dia-row:hover{background:#f8fafc;}
.dia-row.dia-head{background:#f8fafc;border-bottom:2px solid #e2e8f0;font-size:.7rem;text-transform:uppercase;color:#64748b;font-weight:700;cursor:default;}
.bank-card{
    background:#fff;
    border-radius:14px;
    padding:1.1rem 1.2rem 1rem;
    box-shadow:0 2px 10px rgba(0,0,0,.07);
    border-top:4px solid #3b82f6;
    cursor:pointer;
    transition:all .2s;
    display:flex;
    flex-direction:column;
    min-height:168px;
}
.bank-card:hover{transform:translateY(-3px);box-shadow:0 8px 24px rgba(0,0,0,.12);}
.audit-row{cursor:pointer;transition:background .12s;}
.audit-row:hover{background:#faf5ff !important;}
.audit-row .audit-hint{font-size:.68rem;color:#a78bfa;margin-top:.15rem;}
</style>
@endpush
@section('contenido')
@php
$mesesEs=['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$years=range(now()->year-3,now()->year);
$fmt=fn($v)=>'$ '.number_format($v,0,',','.');
@endphp
<div style="max-width:1200px;margin:0 auto;">

    {{-- Header --}}
    <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.25rem;flex-wrap:wrap;">
        <a href="{{ route('admin.informes.hub') }}" style="color:#64748b;font-size:.82rem;text-decoration:none;">← Informes</a>
        <h1 style="font-size:1.2rem;font-weight:700;color:#0d2550;flex:1;">💰 Estado Financiero — {{ $mesesEs[$mes] }} {{ $anio }}</h1>
        <form method="GET" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
            <select name="mes" onchange="this.form.submit()" style="padding:.4rem .65rem;border:1px solid #e2e8f0;border-radius:8px;font-size:.82rem;">
                @foreach($mesesEs as $n=>$nm) @if($n>0)<option value="{{ $n }}" {{ $mes==$n?'selected':'' }}>{{ $nm }}</option>@endif @endforeach
            </select>
            <select name="anio" onchange="this.form.submit()" style="padding:.4rem .65rem;border:1px solid #e2e8f0;border-radius:8px;font-size:.82rem;">
                @foreach($years as $y)<option value="{{ $y }}" {{ $anio==$y?'selected':'' }}>{{ $y }}</option>@endforeach
            </select>
            <a href="?mes={{ $mes }}&anio={{ $anio }}&excel=1" style="background:#16a34a;color:#fff;border-radius:8px;padding:.4rem .9rem;font-size:.78rem;font-weight:600;text-decoration:none;">📥 Excel</a>
            <a href="{{ route('admin.informes.gastos.index', ['mes'=>$mes,'anio'=>$anio]) }}" style="background:#7c3aed;color:#fff;border-radius:8px;padding:.4rem .9rem;font-size:.78rem;font-weight:600;text-decoration:none;">💸 Ver Gastos</a>
        </form>
    </div>

    @if(session('success'))
    <div style="background:#dcfce7;border:1px solid #86efac;color:#166534;padding:.6rem 1rem;border-radius:8px;margin-bottom:.8rem;font-size:.83rem">✅ {{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div style="background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:.6rem 1rem;border-radius:8px;margin-bottom:.8rem;font-size:.83rem">❌ {{ session('error') }}</div>
    @endif

    {{-- Cierre del mes: utilidad, lo que pasa al mes siguiente, por cobrar,
         dónde está la plata y si cuadra. Va antes que todo lo demás. --}}
    @if($cierre)
    @include('admin.informes.partials.cierre_mes')
    @endif

    {{-- KPIs principales --}}
    <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:1rem;margin-bottom:1.5rem;">
        <div class="fin-kpi" style="--c:#2563eb;">
            <div class="val">{{ $fmt($ingresos['total']) }}</div>
            <div class="lab">Ingresos Totales</div>
            <div class="sub">Cobrado en {{ $mesesEs[$mes] }} (base caja)</div>
        </div>
        <div class="fin-kpi" style="--c:#ef4444;cursor:pointer;transition:box-shadow .2s,transform .15s;"
             onclick="abrirGastosDetalle()"
             onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 8px 24px rgba(239,68,68,.18)'"
             onmouseout="this.style.transform='';this.style.boxShadow=''"
             title="Clic para ver detalle de gastos operativos">
            <div class="val">{{ $fmt($egresos['operativos']) }}</div>
            <div class="lab">Gastos Operativos <span style="font-size:.65rem;opacity:.6;">🔍</span></div>
            <div class="sub">Ver gastos</div>
        </div>
        <div class="fin-kpi" style="{{ $utilidad>=0?'--c:#10b981':'--c:#ef4444' }};">
            <div class="val">{{ $fmt($utilidad) }}</div>
            <div class="lab">Utilidad Neta</div>
            <div class="sub">Ingresos − Egresos</div>
        </div>
        <div class="fin-kpi" style="--c:#8b5cf6;">
            <div class="val">{{ $fmt($saldoSS) }}</div>
            <div class="lab">Saldo SS Terceros</div>
            <div class="sub">SS para siguiente mes</div>
        </div>
        {{-- Card nuevo: saldo retenido para asesores --}}
        <div class="fin-kpi" style="--c:#f59e0b; cursor:pointer;" onclick="window.location='{{ route('admin.informes.comisiones.index') }}'" title="Ver módulo Comisiones Asesores">
            <div class="val">{{ $fmt($saldoAsesores) }}</div>
            <div class="lab">💼 Comisión Asesores</div>
            <div class="sub">(desde mayo 2026)</div>
        </div>
        {{-- Card nuevo: recuperación de préstamos de meses anteriores --}}
        <div class="fin-kpi" style="--c:#0d9488; cursor:pointer;" onclick="abrirModalRecuperacionPrestamos()" title="Clic para ver detalle de recuperación de préstamos de meses anteriores">
            <div class="val">{{ $fmt($abonosMesesAnteriores) }}</div>
            <div class="lab">💸 Recuperación Préstamos</div>
            <div class="sub">Abonos meses anteriores</div>
        </div>
    </div>

    {{-- ══ 3 Canales Financieros ══ --}}
    @php
        $fmtC = fn($v) => ($v >= 0 ? '+' : '') . $fmt($v);
        $totalSScanalRaw = $recaudoSS + $moraRecogida + $saldoSSMesAnterior;
    @endphp

    <style>
    .fc-card          { background:#fff;border-radius:16px;box-shadow:0 2px 16px rgba(0,0,0,.08);overflow:hidden;display:flex;flex-direction:column;transition:box-shadow .2s,transform .15s; }
    .fc-card:hover    { box-shadow:0 8px 32px rgba(0,0,0,.13);transform:translateY(-2px); }
    .fc-header        { padding:.85rem 1.15rem; }
    .fc-label         { font-size:.58rem;font-weight:700;text-transform:uppercase;color:rgba(255,255,255,.55);letter-spacing:.09em; }
    .fc-title         { font-size:1rem;font-weight:800;color:#fff;margin-top:.2rem; }
    .fc-body          { padding:.9rem 1.1rem;flex:1;display:flex;flex-direction:column; }
    .fc-section-label { font-size:.57rem;font-weight:700;text-transform:uppercase;color:#94a3b8;letter-spacing:.07em;margin:.55rem 0 .4rem; }
    .fc-row           { display:flex;justify-content:space-between;align-items:center;padding:.28rem .35rem .28rem .15rem;border-radius:6px;transition:background .12s; }
    .fc-row:hover     { background:#f8fafc; }
    .fc-row-label     { display:flex;align-items:center;gap:.5rem;font-size:.77rem;color:#475569; }
    .fc-dot           { width:7px;height:7px;border-radius:50%;flex-shrink:0; }
    .fc-row-val       { font-size:.79rem;font-weight:700;font-family:monospace; }
    .fc-row-zero      { font-size:.79rem;font-weight:400;font-family:monospace;color:#cbd5e1; }
    .fc-divider       { border:none;border-top:1px dashed #e2e8f0;margin:.55rem 0; }
    .fc-subtotal      { display:flex;justify-content:space-between;align-items:center;padding:.32rem .45rem;background:#f1f5f9;border-radius:8px;margin:.1rem 0 .2rem; }
    .fc-subtotal-l    { font-size:.77rem;font-weight:700;color:#334155; }
    .fc-subtotal-v    { font-size:.84rem;font-weight:800;font-family:monospace; }
    .fc-footer        { padding:.9rem 1.15rem;display:flex;justify-content:space-between;align-items:center;margin-top:auto;gap:.5rem; }
    .fc-footer > div:first-child { flex-grow:1;flex-shrink:1;min-width:0; }
    .fc-footer-l      { font-size:.67rem;font-weight:700;text-transform:uppercase;color:rgba(255,255,255,.7);letter-spacing:.06em; }
    .fc-footer-sub    { font-size:.59rem;color:rgba(255,255,255,.45);margin-top:.1rem;line-height:1.2; }
    .fc-footer-v      { font-size:1.18rem;font-weight:900;color:#fff;font-family:monospace;white-space:nowrap;flex-shrink:0;text-align:right; }
    .fc-note          { display:flex;justify-content:space-between;align-items:center;padding:.18rem .35rem;opacity:.6; }
    .fc-note span     { font-size:.68rem;color:#94a3b8;font-style:italic; }
    .fc-btn-ss        { background:rgba(20,184,166,.1);border:1px solid rgba(20,184,166,.3);border-radius:7px;padding:.28rem .65rem;font-size:.67rem;font-weight:700;color:#0f766e;cursor:pointer;transition:background .15s; }
    .fc-btn-ss:hover  { background:rgba(20,184,166,.22); }
    .fc-btn-hdr       { background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);border-radius:6px;padding:.18rem .55rem;font-size:.62rem;font-weight:700;color:#fff;cursor:pointer;transition:background .15s;white-space:nowrap; }
    .fc-btn-hdr:hover { background:rgba(255,255,255,.28); }
    </style>

    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1.2rem;margin-bottom:1.5rem;align-items:stretch;">

        {{-- ══ Card 1: ADMINISTRACIÓN ══ --}}
        <div class="fc-card">
            <div class="fc-header" style="background:linear-gradient(135deg,#1e3a8a,#2563eb);">
                <div class="fc-label">Canal 1</div>
                <div class="fc-title">💼 Administración</div>
            </div>
            <div class="fc-body">
                <div class="fc-section-label">Ingresos cobrados</div>

                @php
                    $filasCanal1 = [
                        ['Administración', $desgloseAdmon['admon'],        '#3b82f6'],
                        ['Seguro',         $desgloseAdmon['seguro'],       '#0ea5e9'],
                        ['Mensajería',     $desgloseAdmon['mensajeria'],   '#06b6d4'],
                        ['IVA',            $desgloseAdmon['iva'],          '#8b5cf6'],
                        ['Otros admon',    $desgloseAdmon['otros_admon'],  '#a78bfa'],
                    ];
                    if (($desgloseAdmon['retiro_campo'] ?? 0) > 0) {
                        $filasCanal1[] = ['Comisión retiros', $desgloseAdmon['retiro_campo'], '#c2410c'];
                    }
                    if (($ingresos['tramites'] ?? 0) > 0) {
                        $filasCanal1[] = ['Trámites', $ingresos['tramites'], '#10b981'];
                    }
                    // Mora ganada se maneja en Canal 4, no se muestra aquí
                    // if (($moraGanancia ?? 0) > 0) {
                    //     $filasCanal1[] = ['Mora ganada', $moraGanancia, '#f43f5e'];
                    // }
                    if (($sobrantePlanilla ?? 0) > 0) {
                        $filasCanal1[] = ['Excedente planilla', $sobrantePlanilla, '#14b8a6'];
                    }
                @endphp

                @foreach($filasCanal1 as [$l,$v,$c])
                <div class="fc-row">
                    <div class="fc-row-label"><span class="fc-dot" style="background:{{ $c }};"></span>{{ $l }}</div>
                    @if($v > 0)
                        <span class="fc-row-val" style="color:{{ $c }};">{{ $fmt($v) }}</span>
                    @else
                        <span class="fc-row-zero">—</span>
                    @endif
                </div>
                @endforeach

 
                @if(($ingresos['admon_incapacidades'] ?? 0) > 0)
                <div class="fc-row" title="Ganancia de administración generada en pagos de incapacidades">
                    <div class="fc-row-label"><span class="fc-dot" style="background:#f97316;"></span>💊 Admon Incapacidades</div>
                    <span class="fc-row-val" style="color:#f97316;">{{ $fmt($ingresos['admon_incapacidades']) }}</span>
                </div>
                @endif
                @if(($ingresos['otros_incapacidades'] ?? 0) > 0)
                <div class="fc-row" title="Otros descuentos que se le hicieron al afiliado en el pago de la incapacidad: se quedan en la empresa">
                    <div class="fc-row-label"><span class="fc-dot" style="background:#fb923c;"></span>💊 Otros desc. incapacidades</div>
                    <span class="fc-row-val" style="color:#fb923c;">{{ $fmt($ingresos['otros_incapacidades']) }}</span>
                </div>
                @endif
                @if($desgloseAdmon['admin_asesor'] > 0)
                <div class="fc-note" title="Comisión ganada por asesores en planillas — no pagada aún">
                    <span>↳ Comisión asesor <em>(ganada, pendiente)</em></span>
                    <span style="font-family:monospace;color:#f59e0b;">{{ $fmt($desgloseAdmon['admin_asesor']) }}</span>
                </div>
                @endif
            </div>
            <div class="fc-footer" style="background:linear-gradient(135deg,#1e3a8a,#2563eb);">
                <div>
                    <div class="fc-footer-l">Total Administración</div>
                    <div class="fc-footer-sub">admon + seguro + iva + otros + trámites + comisiones + excedentes</div>
                </div>
                <div class="fc-footer-v">{{ $fmt($ingresos['planillas'] + $ingresos['tramites'] + $sobrantePlanilla + ($ingresos['admon_incapacidades'] ?? 0) + ($ingresos['otros_incapacidades'] ?? 0)) }}</div>
            </div>
        </div>

        {{-- ══ Card 2: AFILIACIONES ══ --}}
        <div class="fc-card">
            <div class="fc-header" style="background:linear-gradient(135deg,#5b21b6,#7c3aed);">
                <div class="fc-label">Canal 2</div>
                <div class="fc-title" style="display:flex;justify-content:space-between;align-items:center;">
                    <span>🔖 Afiliaciones</span>
                    <a href="{{ route('admin.informes.comisiones.afiliaciones', ['mes'=>$mes,'anio'=>$anio]) }}"
                       class="fc-btn-hdr" style="text-decoration:none;">
                        📋 Ver desglose
                    </a>
                </div>
            </div>
            <div class="fc-body">

                <div class="fc-section-label">Distribución del ingreso</div>

                {{-- dist_admon --}}
                <div class="fc-row">
                    <div class="fc-row-label"><span class="fc-dot" style="background:#3b82f6;"></span><span style="color:#94a3b8;font-size:.7rem;">→</span> Admon</div>
                    @if($desgloseAfiliaciones['dist_admon'] > 0)
                        <span class="fc-row-val" style="color:#3b82f6;">{{ $fmt($desgloseAfiliaciones['dist_admon']) }}</span>
                    @else <span class="fc-row-zero">—</span>@endif
                </div>

                {{-- dist_asesor — siempre visible --}}
                <div class="fc-row" style="{{ $desgloseAfiliaciones['dist_asesor'] > 0 ? '' : 'opacity:.55;' }}" title="Comisión distribuida al asesor por esta afiliación">
                    <div class="fc-row-label"><span class="fc-dot" style="background:#f59e0b;"></span><span style="color:#94a3b8;font-size:.7rem;">→</span> Comisión asesor</div>
                    @if($desgloseAfiliaciones['dist_asesor'] > 0)
                        <span class="fc-row-val" style="color:#f59e0b;">{{ $fmt($desgloseAfiliaciones['dist_asesor']) }}</span>
                    @else <span class="fc-row-zero">sin asignar</span>@endif
                </div>

                {{-- dist_retiro --}}
                <div class="fc-row">
                    <div class="fc-row-label"><span class="fc-dot" style="background:#c2410c;"></span><span style="color:#94a3b8;font-size:.7rem;">→</span> Retiro</div>
                    @if($desgloseAfiliaciones['dist_retiro'] > 0)
                        <span class="fc-row-val" style="color:#c2410c;">{{ $fmt($desgloseAfiliaciones['dist_retiro']) }}</span>
                    @else <span class="fc-row-zero">—</span>@endif
                </div>

                {{-- dist_utilidad --}}
                <div class="fc-row">
                    <div class="fc-row-label"><span class="fc-dot" style="background:#16a34a;"></span><span style="color:#94a3b8;font-size:.7rem;">→</span> Utilidad</div>
                    @if($desgloseAfiliaciones['dist_utilidad'] > 0)
                        <span class="fc-row-val" style="color:#16a34a;">{{ $fmt($desgloseAfiliaciones['dist_utilidad']) }}</span>
                    @else <span class="fc-row-zero">—</span>@endif
                </div>

                {{-- dist_encargado --}}
                <div class="fc-row" title="Comisión distribuida al encargado por esta afiliación">
                    <div class="fc-row-label"><span class="fc-dot" style="background:#8b5cf6;"></span><span style="color:#94a3b8;font-size:.7rem;">→</span> Comisión encargado</div>
                    @if(($desgloseAfiliaciones['dist_encargado'] ?? 0) > 0)
                        <span class="fc-row-val" style="color:#8b5cf6;">{{ $fmt($desgloseAfiliaciones['dist_encargado']) }}</span>
                    @else <span class="fc-row-zero">—</span>@endif
                </div>

                @php $sinDist = $desgloseAfiliaciones['afiliacion'] - $desgloseAfiliaciones['distribuido']; @endphp
                <div class="fc-row" style="{{ abs($sinDist) > 1 ? '' : 'opacity:.45;' }}">
                    <div class="fc-row-label"><span class="fc-dot" style="background:#94a3b8;"></span><span style="color:#94a3b8;font-size:.7rem;">→</span> Sin distribuir</div>
                    @if(abs($sinDist) > 1)
                        <span class="fc-row-val" style="color:#64748b;">{{ $fmt($sinDist) }}</span>
                    @else
                        <span class="fc-row-zero">—</span>
                    @endif
                </div>

                {{-- Fila agregada para mostrar Total Distribución (Bruto) --}}
                <div class="fc-row" style="border-top:1px dashed #dddfeb; margin-top:8px; padding-top:8px; font-weight:bold;">
                    <div class="fc-row-label"><span class="fc-dot" style="background:#5b21b6;"></span>Total Distribución (Bruto)</div>
                    <span class="fc-row-val" style="color:#5b21b6;">{{ $fmt($desgloseAfiliaciones['afiliacion']) }}</span>
                </div>
            </div>
            <div class="fc-footer" style="background:linear-gradient(135deg,#5b21b6,#7c3aed);">
                <div>
                    <div class="fc-footer-l">Total Afiliaciones (Neto)</div>
                    <div class="fc-footer-sub">admon + utilidad (sin comisión ni retiro)</div>
                </div>
                <div class="fc-footer-v">{{ $fmt($ingresos['afiliaciones']) }}</div>
            </div>
        </div>

        {{-- ══ Card 3: SEGURIDAD SOCIAL ══ --}}
        <div class="fc-card">
            <div class="fc-header" style="background:linear-gradient(135deg,#0f766e,#0d9488);">
                <div class="fc-label">Canal 3</div>
                <div class="fc-title" style="display:flex;justify-content:space-between;align-items:center;">
                    <span>🏥 Seguridad Social</span>
                    <button class="fc-btn-hdr" onclick="abrirConciliacionSS()">🔍 Conciliar SS</button>
                </div>
            </div>
            <div class="fc-body">
                <div class="fc-section-label">Detalle Recaudo SS</div>

                {{-- Saldo SS Anterior --}}
                @if($saldoSSMesAnterior > 0)
                <div class="fc-row" title="Saldo SS que quedó del mes anterior">
                    <div class="fc-row-label"><span class="fc-dot" style="background:#14b8a6;"></span>Saldo SS Anterior</div>
                    <span class="fc-row-val" style="color:#14b8a6;">{{ $fmt($saldoSSMesAnterior) }}</span>
                </div>
                @else
                <div class="fc-row" style="opacity:.5;">
                    <div class="fc-row-label"><span class="fc-dot" style="background:#14b8a6;"></span>Saldo SS Anterior</div>
                    <span class="fc-row-zero">—</span>
                </div>
                @endif

                {{-- Componentes SS Recaudados --}}
                @foreach([
                    ['EPS',         $ingresosSS['eps'],   '#0ea5e9'],
                    ['Pensión AFP', $ingresosSS['afp'],   '#10b981'],
                    ['ARL',         $ingresosSS['arl'],   '#8b5cf6'],
                    ['Caja Comp.',  $ingresosSS['caja'],  '#f59e0b'],
                    ['Otros',       $ingresosSS['otros'], '#64748b'],
                ] as [$l,$v,$c])
                <div class="fc-row">
                    <div class="fc-row-label"><span class="fc-dot" style="background:{{ $c }};"></span>{{ $l }}</div>
                    @if($v > 0)
                        <span class="fc-row-val" style="color:{{ $c }};">{{ $fmt($v) }}</span>
                    @else
                        <span class="fc-row-zero">—</span>
                    @endif
                </div>
                @endforeach

                {{-- Mora recogida --}}
                <div class="fc-row" style="{{ $moraRecogida > 0 ? '' : 'opacity:.5;' }}" title="Mora SS recogida en facturas pagadas este mes">
                    <div class="fc-row-label"><span class="fc-dot" style="background:#f43f5e;"></span>Mora Recogido</div>
                    @if($moraRecogida > 0)
                        <span class="fc-row-val" style="color:#f43f5e;">{{ $fmt($moraRecogida) }}</span>
                    @else
                        <span class="fc-row-zero">—</span>
                    @endif
                </div>

                <div style="margin-top:auto;"></div>
            </div>
            <div class="fc-footer" style="background:linear-gradient(135deg,#0f766e,#0d9488);">
                <div>
                    <div class="fc-footer-l">Total SS + Mora</div>
                    <div class="fc-footer-sub">recaudado en el mes</div>
                </div>
                <div class="fc-footer-v">{{ $fmt($totalSScanalRaw) }}</div>
            </div>
        </div>

        {{-- ══ Card Canal 5: INCAPACIDADES (solo si hay movimientos) ══ --}}
        @if($canal5Visible ?? false)
        @php
            $c5SaldoColor  = ($canal5SaldoAcumulado ?? 0) >= 0 ? '#16a34a' : '#dc2626';
            $c5SaldoBg     = ($canal5SaldoAcumulado ?? 0) >= 0 ? '#f0fdf4' : '#fef2f2';
            $c5SaldoPrefix = ($canal5SaldoAcumulado ?? 0) >= 0 ? '+' : '';
        @endphp
        <div class="fc-card">
            <div class="fc-header" style="background:linear-gradient(135deg,#78350f,#d97706);">
                <div class="fc-label">Canal 5</div>
                <div class="fc-title">🏥 Incapacidades</div>
            </div>
            <div class="fc-body">

                {{-- Saldo anterior arrastrado --}}
                @if(($canal5SaldoAnterior ?? 0) != 0)
                <div class="fc-section-label">Saldo arrastrado</div>
                <div class="fc-row">
                    <div class="fc-row-label"><span class="fc-dot" style="background:#f59e0b;"></span>Saldo mes anterior</div>
                    <span class="fc-row-val" style="color:#f59e0b;">{{ $fmt($canal5SaldoAnterior) }}</span>
                </div>
                @endif

                {{-- Entradas del mes --}}
                <div class="fc-section-label">Entradas incapacidad</div>
                <div class="fc-row">
                    <div class="fc-row-label"><span class="fc-dot" style="background:#16a34a;"></span>Entradas incapacidad</div>
                    @if(($canal5EntradaMes ?? 0) > 0)
                        <span class="fc-row-val" style="color:#16a34a;">{{ $fmt($canal5EntradaMes) }}</span>
                    @else
                        <span class="fc-row-zero">&mdash;</span>
                    @endif
                </div>

                {{-- Egresos del mes --}}
                <div class="fc-section-label">Egresos del mes</div>
                <div class="fc-row">
                    <div class="fc-row-label"><span class="fc-dot" style="background:#dc2626;"></span>Pago al afiliado (neto)</div>
                    @if(($canal5PagoAfiliado ?? 0) > 0)
                        <span class="fc-row-val" style="color:#dc2626;">{{ $fmt($canal5PagoAfiliado) }}</span>
                    @else
                        <span class="fc-row-zero">&mdash;</span>
                    @endif
                </div>
                @if(($canal5Cuatropormil ?? 0) > 0)
                <div class="fc-row">
                    <div class="fc-row-label"><span class="fc-dot" style="background:#f43f5e;"></span>4x1000</div>
                    <span class="fc-row-val" style="color:#f43f5e;">{{ $fmt($canal5Cuatropormil) }}</span>
                </div>
                @endif
                @if(($canal5OtrosDesc ?? 0) > 0)
                <div class="fc-row">
                    <div class="fc-row-label"><span class="fc-dot" style="background:#94a3b8;"></span>
                        <span>Otros descuentos <span style="color:#94a3b8;font-size:.68rem;">→ Canal 1</span></span>
                    </div>
                    <span class="fc-row-val" style="color:#94a3b8;">{{ $fmt($canal5OtrosDesc) }}</span>
                </div>
                @endif

                {{-- Ganancia admon → Canal 1 --}}
                @if(($canal5GananciaAdmon ?? 0) > 0)
                <div class="fc-row" title="Esta ganancia se transfiere automáticamente al Canal 1" style="border-top:1px dashed #e2e8f0;margin-top:.35rem;padding-top:.35rem;">
                    <div class="fc-row-label"><span class="fc-dot" style="background:#2563eb;"></span>
                        <span style="font-size:.68rem;">💼 Ganancia Admon <span style="color:#94a3b8;">→ Canal 1</span></span>
                    </div>
                    <span class="fc-row-val" style="color:#2563eb;">{{ $fmt($canal5GananciaAdmon) }}</span>
                </div>
                @endif

                {{-- ODI: la ARL las descuenta del aporte en planilla, no llegan al banco --}}
                @if(($odisPendientes ?? collect())->isNotEmpty())
                <div style="border-top:1px dashed #e2e8f0;margin-top:.35rem;padding-top:.35rem;" title="Órdenes de descuento de la ARL: se descuentan del aporte a la ARL en las próximas planillas">
                    <div class="fc-row-label" style="margin-bottom:.2rem;"><span class="fc-dot" style="background:#d97706;"></span>
                        <span style="font-size:.68rem;font-weight:700;">📋 ODI por descontar en planilla</span>
                    </div>
                    @foreach($odisPendientes as $o)
                    <div class="fc-row" style="padding-left:.9rem;">
                        <span style="font-size:.64rem;color:#64748b;">{{ \Illuminate\Support\Str::limit($o->razon_social, 24) }} ({{ $o->cantidad }})</span>
                        <span class="fc-row-val" style="color:#d97706;">{{ $fmt($o->saldo) }}</span>
                    </div>
                    @endforeach
                </div>
                @endif

                <div style="margin-top:auto;"></div>
            </div>

            {{-- Footer: Saldo Acumulado --}}
            <div style="padding:.85rem 1.15rem;background:{{ $c5SaldoBg }};border-top:2px solid {{ $c5SaldoColor }};border-radius:0 0 16px 16px;display:flex;justify-content:space-between;align-items:center;">
                <div>
                    <div style="font-size:.64rem;font-weight:700;text-transform:uppercase;color:{{ $c5SaldoColor }};letter-spacing:.06em;">
                        {{ ($canal5SaldoAcumulado ?? 0) >= 0 ? 'Saldo Disponible' : '⚠️ Saldo Negativo' }}
                    </div>
                    <div style="font-size:.58rem;color:#64748b;margin-top:.1rem;">
                        {{ ($canal5SaldoAcumulado ?? 0) < 0 ? 'Empresa adelantó pago al afiliado' : 'Pendiente de pagar al afiliado' }}
                    </div>
                </div>
                <div style="font-size:1.18rem;font-weight:900;color:{{ $c5SaldoColor }};font-family:monospace;">
                    {{ $c5SaldoPrefix }}{{ $fmt($canal5SaldoAcumulado ?? 0) }}
                </div>
            </div>
        </div>

        {{-- ══ Card Ancha: Detalle de Incapacidades en Canal 5 (ocupa 2 columnas) ══ --}}
        <div class="fc-card" style="grid-column: span 2; display: flex; flex-direction: column;">
            <div class="fc-header" style="background:linear-gradient(135deg,#475569,#334155);">
                <div class="fc-label">Pendientes por cuadrar</div>
                <div class="fc-title">📋 Detalle de Incapacidades (Canal 5)</div>
            </div>
            <div class="fc-body" style="padding: 0; overflow-y: auto; max-height: 298px; flex: 1;">
                @if(count($canal5Incapacidades) > 0)
                    <table style="width:100%; border-collapse:collapse; text-align:left; font-size:.78rem;">
                        <thead>
                            <tr style="background:#f8fafc; border-bottom:2px solid #e2e8f0; color:#475569; font-weight:700; text-transform:uppercase; font-size:.65rem; letter-spacing:0.5px; position: sticky; top: 0; z-index: 10;">
                                <th style="padding:.65rem .85rem;">Incapacidad / Cliente</th>
                                <th style="padding:.65rem .85rem; text-align:center;">Estado</th>
                                <th style="padding:.65rem .85rem; text-align:right;">Total Recibido EPS</th>
                                <th style="padding:.65rem .85rem; text-align:right;">Total Pagos/Desc.</th>
                                <th style="padding:.65rem .85rem; text-align:right;">Saldo Canal 5</th>
                                <th style="padding:.65rem .85rem; text-align:center;">Acción</th>
                            </tr>
                        </thead>
                        <tbody style="color:#334155;">
                            @foreach($canal5Incapacidades as $inc)
                                @php
                                    $nombreCompleto = $inc->nombre_cliente;
                                    $nombreCompleto = nombre_oracion($nombreCompleto);
                                    if (empty($nombreCompleto)) {
                                        $nombreCompleto = "C.C. {$inc->cedula_usuario}";
                                    }
                                    $saldoCanal5 = (float)$inc->total_entradas_historico - (float)$inc->total_pagos_historico;
                                    $descuentosInc = (float)$inc->total_pagos_historico - (float)$inc->pago_neto_afiliado;
                                    
                                    // Determinar estados y badges
                                    $estadosLabels = [
                                        'recibido' => '📬 Recibido',
                                        'transcripcion_ips' => '🏥 Transcripción',
                                        'radicada' => '📋 Radicada',
                                        'negada' => '🚫 Negada',
                                        'derecho_peticion' => '📄 D. Petición',
                                        'derecho_peticion_radicado' => '📄 D.P. Radicado',
                                        'tutela' => '⚖️ Tutela',
                                        'tutela_radicada' => '📜 Tutela Radicada',
                                        'rechazado' => '❌ Rechazado',
                                        'en_liquidacion' => '💰 Liq.',
                                        'pagada_razon_social' => '🏢 Pag. Razón Social',
                                        'pagada_afiliado' => '🏦 Pag. Afiliado',
                                        'cierre_exitoso' => '✅ Cierre Exitoso'
                                    ];
                                    $estadoLabel = $estadosLabels[$inc->estado] ?? $inc->estado;
                                    
                                    $badgeColors = [
                                        'recibido' => 'background:#f1f5f9;color:#475569;',
                                        'transcripcion_ips' => 'background:#e0f2fe;color:#0369a1;',
                                        'radicada' => 'background:#dbeafe;color:#1d4ed8;',
                                        'negada' => 'background:#fee2e2;color:#b91c1c;',
                                        'derecho_peticion' => 'background:#fef3c7;color:#b45309;',
                                        'derecho_peticion_radicado' => 'background:#fef3c7;color:#b45309;',
                                        'tutela' => 'background:#fef3c7;color:#b45309;',
                                        'tutela_radicada' => 'background:#fef3c7;color:#b45309;',
                                        'rechazado' => 'background:#fee2e2;color:#b91c1c;',
                                        'en_liquidacion' => 'background:#e0f2fe;color:#0369a1;',
                                        'pagada_razon_social' => 'background:#e0f2fe;color:#0369a1;',
                                        'pagada_afiliado' => 'background:#d1fae5;color:#065f46;',
                                        'cierre_exitoso' => 'background:#d1fae5;color:#065f46;'
                                    ];
                                    $badgeStyle = $badgeColors[$inc->estado] ?? 'background:#f1f5f9;color:#475569;';
                                @endphp
                                <tr style="border-bottom:1px solid #f1f5f9; cursor: pointer; transition: background-color 0.15s;" 
                                    onmouseover="this.style.backgroundColor='#f8fafc'" 
                                    onmouseout="this.style.backgroundColor=''"
                                    onclick="window.open('{{ route('admin.incapacidades.index') }}?abrir_incId={{ $inc->id }}', '_blank')">
                                    <td style="padding:.65rem .85rem;">
                                        <div style="font-weight:700; color:#1e293b;">{{ nombre_oracion($nombreCompleto) }}</div>
                                        <div style="font-size:.65rem; color:#64748b; margin-top:.1rem;">
                                            C.C. {{ $inc->cedula_usuario }} · <b>#{{ $inc->id }}</b>
                                        </div>
                                    </td>
                                    <td style="padding:.65rem .85rem; text-align:center; white-space:nowrap;">
                                        <span style="display:inline-block; font-size:.65rem; font-weight:700; padding:.15rem .45rem; border-radius:6px; {{ $badgeStyle }}">
                                            {{ $estadoLabel }}
                                        </span>
                                    </td>
                                    <td style="padding:.65rem .85rem; text-align:right; font-family:monospace; font-weight:600; color:#16a34a; white-space:nowrap;">
                                        {{ $inc->total_entradas_historico > 0 ? $fmt($inc->total_entradas_historico) : '—' }}
                                    </td>
                                    <td style="padding:.65rem .85rem; text-align:right; font-family:monospace; font-weight:600; color:#2563eb; white-space:nowrap;">
                                        {{ $inc->total_pagos_historico > 0 ? $fmt($inc->total_pagos_historico) : '—' }}
                                        @if($descuentosInc > 0)
                                            <div style="font-size:.6rem; color:#94a3b8; font-weight:600;" title="Neto girado al afiliado + descuentos (4x1000, admon, otros)">
                                                neto {{ $fmt($inc->pago_neto_afiliado) }} · desc. {{ $fmt($descuentosInc) }}
                                            </div>
                                        @endif
                                    </td>
                                    <td style="padding:.65rem .85rem; text-align:right; font-family:monospace; font-weight:700; color:{{ $saldoCanal5 >= 0 ? '#16a34a' : '#dc2626' }}; white-space:nowrap;">
                                        {{ $saldoCanal5 > 0 ? '+' : '' }}{{ $fmt($saldoCanal5) }}
                                    </td>
                                    <td style="padding:.65rem .85rem; text-align:center;" onclick="event.stopPropagation();">
                                        <a href="{{ route('admin.incapacidades.index') }}?abrir_incId={{ $inc->id }}" target="_blank" class="btn btn-info btn-sm" style="font-size:.65rem; padding:.2rem .4rem; line-height:1; display:inline-block; border-radius:4px; text-decoration:none; background:#0ea5e9; color:#fff; border:none; box-shadow: 0 1px 2px rgba(0,0,0,0.1);">
                                            👁 Abrir
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <div style="text-align:center; padding:3rem 1.5rem; color:#94a3b8; font-size:.85rem;">
                        <div>📭</div>
                        <div style="margin-top:.5rem;">No hay incapacidades pendientes por cuadrar: todo lo recibido de la EPS ya se pagó.</div>
                    </div>
                @endif
            </div>
        </div>
        @endif

    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.5rem;">
        <div style="background:#fff;border-radius:14px;padding:1.25rem;box-shadow:0 1px 8px rgba(0,0,0,.06);">
            <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#94a3b8;margin-bottom:.85rem;">Tendencia 6 Meses</div>
            <canvas id="chartTendencia" height="180"></canvas>
        </div>
        <div style="background:#fff;border-radius:14px;padding:1.25rem;box-shadow:0 1px 8px rgba(0,0,0,.06);">
            <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#94a3b8;margin-bottom:.85rem;">Mes Actual vs Anterior</div>
            <canvas id="chartComparacion" height="180"></canvas>
        </div>
    </div>

    {{-- Gráfica distribución ingresos --}}
    <div style="display:grid;grid-template-columns:300px 1fr;gap:1.25rem;margin-bottom:1.5rem;">
        <div style="background:#fff;border-radius:14px;padding:1.25rem;box-shadow:0 1px 8px rgba(0,0,0,.06);display:flex;flex-direction:column;">
            <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#94a3b8;margin-bottom:.85rem;">Distribución Ingresos</div>
            <canvas id="chartDona" height="140"></canvas>

            {{-- Cómo está la plata de la EMPRESA (todas las cuentas y cajas, no
                 una cuenta). Ver App\Services\Caja\CierreMes::plata(). --}}
            @php
                $pl = $plata;
                $fila = function ($label, $valor, $color = '#334155', $peso = 600, $title = '') use ($fmt) {
                    return '<div style="display:flex;justify-content:space-between;align-items:baseline;gap:.4rem;" title="'.e($title).'">'
                        .'<span style="font-size:.7rem;color:#475569;font-weight:'.$peso.';">'.$label.'</span>'
                        .'<span style="font-size:.74rem;font-weight:'.max($peso, 700).';color:'.$color.';font-family:monospace;white-space:nowrap;">'.$fmt($valor).'</span></div>';
                };
                $corteTxt = ($mes == now()->month && $anio == now()->year) ? 'hoy' : 'al cierre';
            @endphp
            <div style="margin-top:1rem;border-top:1px solid #f1f5f9;padding-top:.85rem;display:flex;flex-direction:column;gap:.3rem;">
                <div style="font-size:.6rem;font-weight:800;text-transform:uppercase;color:#94a3b8;letter-spacing:.06em;margin-bottom:.15rem;">
                    💰 Cómo está la plata {{ $corteTxt }}
                </div>
                <div style="font-size:.6rem;color:#94a3b8;margin:-.15rem 0 .25rem;">La empresa: todas las cuentas y cajas</div>
                {!! $fila('🏦 En bancos', $pl['bancos'], '#1d4ed8', 600, 'Saldo de todas las cuentas: cada una arranca el mes con lo verificado') !!}
                {!! $fila('💵 En efectivo', $pl['efectivo'], '#16a34a', 600, 'Lo que tiene cada persona por entregar (ver «Efectivo por recoger»)') !!}
                <div style="border-top:1px dashed #e2e8f0;margin:.1rem 0"></div>
                {!! $fila('= Hay', $pl['hay'], '#0f172a', 800) !!}
                {!! $fila('− No es de Brygar', -$pl['ajeno'], '#b45309', 700, 'Plata que está en las cuentas o en efectivo pero es de otros, o que Brygar debe') !!}
                @foreach($pl['ajeno_detalle'] as [$k, $n, $sub, $v])
                <div style="display:flex;justify-content:space-between;gap:.4rem;padding-left:.7rem;" title="{{ $sub }}">
                    <span style="font-size:.62rem;color:#64748b;">└ {{ $n }}</span>
                    <span style="font-size:.64rem;color:#b45309;font-family:monospace;white-space:nowrap;">{{ $fmt($v) }}</span>
                </div>
                @endforeach
                {!! $fila('+ Prestado, falta que entre', $pl['cobrar'], '#7c3aed', 700, 'Lo que le deben a Brygar: ya contó como ingreso y falta que llegue la plata') !!}
                @foreach($pl['cobrar_detalle'] as [$k, $n, $sub, $v])
                <div style="display:flex;justify-content:space-between;gap:.4rem;padding-left:.7rem;" title="{{ $sub }}">
                    <span style="font-size:.62rem;color:#64748b;">└ {{ $n }}</span>
                    <span style="font-size:.64rem;color:#7c3aed;font-family:monospace;white-space:nowrap;">{{ $fmt($v) }}</span>
                </div>
                @endforeach
                <div style="border-top:1.5px solid #e2e8f0;margin:.15rem 0"></div>
                {!! $fila('= Es de Brygar', $pl['es'], '#0f172a', 800, 'Lo que hay − lo que no es de Brygar + lo prestado') !!}
                @if($pl['deberia'] !== null)
                    {!! $fila('Debería ser', $pl['deberia'], '#64748b', 600, 'Lo que era de Brygar al cierre de '.$pl['mes_anterior'].' + la ganancia del mes − los retiros del dueño') !!}
                    <div style="display:flex;justify-content:space-between;align-items:baseline;padding:.3rem .4rem;border-radius:6px;background:{{ abs($pl['diferencia']) < 1 ? '#ecfdf5' : '#fef2f2' }};">
                        <span style="font-size:.7rem;font-weight:800;color:{{ abs($pl['diferencia']) < 1 ? '#065f46' : '#991b1b' }};">{{ abs($pl['diferencia']) < 1 ? '✅ Cuadra' : '⚠️ Diferencia' }}</span>
                        <span style="font-size:.78rem;font-weight:900;font-family:monospace;color:{{ abs($pl['diferencia']) < 1 ? '#065f46' : '#991b1b' }};">{{ $pl['diferencia'] > 0 ? '+' : '' }}{{ $fmt($pl['diferencia']) }}</span>
                    </div>
                    @if(abs($pl['diferencia']) >= 1)
                    <div style="font-size:.6rem;color:#94a3b8;line-height:1.35;">
                        {{ $pl['diferencia'] > 0 ? 'Hay más plata de la que se explica' : 'Falta plata' }}: revisa efectivo sin contar, consignaciones en otra cuenta, gastos o retiros sin anotar.
                        @if($pl['sin_verificar']) {{ $pl['sin_verificar'] }} {{ $pl['sin_verificar'] === 1 ? 'caja o cuenta arrancó' : 'cajas o cuentas arrancaron' }} el mes sin verificar.@endif
                    </div>
                    @endif
                @else
                    <div style="font-size:.6rem;color:#94a3b8;line-height:1.35;">Sin foto del cierre de {{ $pl['mes_anterior'] }}: no se puede decir cuánto debería ser.</div>
                @endif
            </div>
        </div>

        {{-- Bancos + Efectivo --}}
        <div style="background:#fff;border-radius:14px;padding:1.25rem;box-shadow:0 1px 8px rgba(0,0,0,.06);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.85rem;flex-wrap:wrap;gap:.5rem;">
                <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#94a3b8;" title="Los saldos de cada cuenta están arriba, en «Dónde está la plata»">🏦 Movimientos del mes por cuenta <span style="text-transform:none;font-weight:500">— lo que entró y salió en {{ $mesesEs[$mes] }}</span></div>
                <div style="font-size:.66rem;color:#94a3b8;">Cómo está la plata de la empresa: a la izquierda, debajo de la gráfica</div>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(185px,1fr));gap:.75rem;">

                {{-- Tarjetas banco — solo bancos con movimiento o saldo distinto de cero --}}
                @foreach($bancos as $b)
                @if($b->saldo_actual != 0 || $b->ing_mes != 0 || $b->sal_mes != 0)
                @php
                    // Cuenta de razón social (ni de cobros ni personal): la plata de
                    // incapacidades entra un mes y sale otro, así que el neto del mes
                    // asusta sin decir nada. Lo que importa es que quede en cero: se
                    // muestra el saldo de la cuenta.
                    $esCuentaRs = ! $b->cobro && ! $b->uso_personal;
                    $heroValor  = $esCuentaRs ? $b->saldo_actual : $b->saldo_mes;
                    $netColor = $heroValor >= 0 ? '#16a34a' : '#dc2626'; $netBg = $heroValor >= 0 ? '#f0fdf4' : '#fef2f2';
                @endphp
                <div class="bank-card" onclick="verMovimientosBanco({{ $b->id }},'{{ addslashes($b->nombre) }}')" title="Clic para ver movimientos del mes">
                    {{-- Cabecera --}}
                    <div style="font-size:.65rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">{{ $b->banco }}</div>
                    <div style="font-size:.78rem;font-weight:700;color:#334155;margin-top:.1rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="{{ $b->nombre }}">{{ $b->nombre }}</div>

                    {{-- Hero: neto del mes, o el saldo en las cuentas de razón social --}}
                    <div style="margin-top:.55rem;">
                        <div style="font-size:.6rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.03em;margin-bottom:.2rem;"
                             @if($esCuentaRs) title="Cuenta de razón social: lo que entra de incapacidades sale al afiliado, así que debe quedar en cero" @endif>
                            {{ $esCuentaRs ? 'Saldo cuenta razón social' : 'Neto '.$mesesEs[$mes] }}
                        </div>
                        <div style="font-size:1.3rem;font-weight:900;color:{{ $heroValor == 0 ? '#16a34a' : $netColor }};line-height:1.1;">
                            {{ $heroValor > 0 ? '+' : '' }}{{ $fmt($heroValor) }}
                        </div>
                    </div>

                    {{-- Movimientos del mes --}}
                    <div style="margin-top:auto;padding-top:.6rem;border-top:1px dashed #e2e8f0;display:flex;flex-direction:column;gap:.22rem;">
                        {{-- Entró total e hijos --}}
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <span style="font-size:.65rem;color:#475569;font-weight:700;">↑ Entró</span>
                            <span style="font-size:.72rem;font-weight:800;color:#16a34a;font-family:monospace;">{{ $fmt($b->ing_mes) }}</span>
                        </div>
                        <div style="display:flex;justify-content:space-between;align-items:center;padding-left:.5rem;font-size:.6rem;color:#64748b;">
                            <span>└ Facturas</span>
                            <span style="font-family:monospace;">{{ $fmt($b->ing_facturas) }}</span>
                        </div>
                        <div style="display:flex;justify-content:space-between;align-items:center;padding-left:.5rem;font-size:.6rem;color:#64748b;">
                            <span>└ Consignaciones</span>
                            <span style="font-family:monospace;">{{ $fmt($b->ing_consignaciones) }}</span>
                        </div>

                        {{-- Salió total e hijos --}}
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:.15rem;border-top:1px dashed #f1f5f9;padding-top:.15rem;">
                            <span style="font-size:.65rem;color:#475569;font-weight:700;">↓ Salió</span>
                            <span style="font-size:.72rem;font-weight:800;color:#dc2626;font-family:monospace;">{{ $fmt($b->sal_mes) }}</span>
                        </div>
                        <div style="display:flex;justify-content:space-between;align-items:center;padding-left:.5rem;font-size:.6rem;color:#64748b;">
                            <span>└ Planillas (SS)</span>
                            <span style="font-family:monospace;">{{ $fmt($b->sal_planillas) }}</span>
                        </div>
                        <div style="display:flex;justify-content:space-between;align-items:center;padding-left:.5rem;font-size:.6rem;color:#64748b;">
                            <span>└ Transferencias</span>
                            <span style="font-family:monospace;">{{ $fmt($b->sal_transferencias) }}</span>
                        </div>

                        {{-- Saldo final --}}
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:.2rem;padding-top:.3rem;border-top:1.5px solid #e2e8f0;">
                            <span style="font-size:.6rem;color:#94a3b8;font-weight:600;">{{ $b->label_saldo }}</span>
                            <span style="font-size:.68rem;font-weight:700;color:#334155;font-family:monospace;">{{ $fmt($b->saldo_actual) }}</span>
                        </div>
                    </div>
                </div>
                @endif
                @endforeach

                {{-- Tarjeta Efectivo --}}
                @php $efColor = $efMes->neto >= 0 ? '#16a34a' : '#dc2626'; @endphp
                <div class="bank-card" onclick="verMovimientosEfectivo()" title="Clic para ver facturas en efectivo y desglose por asesor" style="border-top-color:#16a34a;">
                    {{-- Cabecera --}}
                    <div style="font-size:.65rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">💵 Caja</div>
                    <div style="font-size:.78rem;font-weight:700;color:#334155;margin-top:.1rem;">Efectivo</div>

                    {{-- Hero: Neto del mes --}}
                    <div style="margin-top:.55rem;">
                        <div style="font-size:.6rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.03em;margin-bottom:.2rem;">Neto {{ $mesesEs[$mes] }}</div>
                        <div style="font-size:1.3rem;font-weight:900;color:{{ $efColor }};line-height:1.1;">
                            {{ $efMes->neto >= 0 ? '+' : '' }}{{ $fmt($efMes->neto) }}
                        </div>
                    </div>

                    {{-- Movimientos del mes --}}
                    <div style="margin-top:auto;padding-top:.6rem;border-top:1px dashed #e2e8f0;display:flex;flex-direction:column;gap:.22rem;">
                        {{-- Entró total e hijos --}}
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <span style="font-size:.65rem;color:#475569;font-weight:700;">↑ Entró</span>
                            <span style="font-size:.72rem;font-weight:800;color:#16a34a;font-family:monospace;">{{ $fmt($efMes->entradas) }}</span>
                        </div>
                        <div style="display:flex;justify-content:space-between;align-items:center;padding-left:.5rem;font-size:.6rem;color:#64748b;">
                            <span>└ Facturas</span>
                            <span style="font-family:monospace;">{{ $fmt($efMes->facturas) }}</span>
                        </div>
                        <div style="display:flex;justify-content:space-between;align-items:center;padding-left:.5rem;font-size:.6rem;color:#64748b;">
                            <span>└ Anticipos</span>
                            <span style="font-family:monospace;">{{ $fmt($efMes->anticipos) }}</span>
                        </div>
                        @if(($efMes->incapacidades ?? 0) > 0)
                        <div style="display:flex;justify-content:space-between;align-items:center;padding-left:.5rem;font-size:.6rem;color:#64748b;" title="OPI de la ARL cobradas en efectivo">
                            <span>└ OPI incapacidades</span>
                            <span style="font-family:monospace;">{{ $fmt($efMes->incapacidades) }}</span>
                        </div>
                        @endif

                        {{-- Salió total e hijos --}}
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:.15rem;border-top:1px dashed #f1f5f9;padding-top:.15rem;">
                            <span style="font-size:.65rem;color:#475569;font-weight:700;">↓ Salió</span>
                            <span style="font-size:.72rem;font-weight:800;color:#dc2626;font-family:monospace;">{{ $fmt($efMes->salidas) }}</span>
                        </div>
                        <div style="display:flex;justify-content:space-between;align-items:center;padding-left:.5rem;font-size:.6rem;color:#64748b;">
                            <span>└ Gastos</span>
                            <span style="font-family:monospace;">{{ $fmt($efMes->gastos) }}</span>
                        </div>
                        
                        {{-- Traslados a banco (Consignaciones) --}}
                        @if($efMes->consignaciones > 0)
                        <div style="display:flex;justify-content:space-between;align-items:center;padding-left:.5rem;font-size:.6rem;color:#d97706;" title="Efectivo consignado en cuentas de banco este mes">
                            <span>⇄ Consignado</span>
                            <span style="font-family:monospace;font-weight:700;">{{ $fmt($efMes->consignaciones) }}</span>
                        </div>
                        @endif

                        {{-- Retiros del dueño: no son gasto, pero la plata sale de la caja --}}
                        @if(($efMes->retiros ?? 0) > 0)
                        <div style="display:flex;justify-content:space-between;align-items:center;padding-left:.5rem;font-size:.6rem;color:#92400e;" title="Plata que el dueño sacó de la caja: no es gasto, se descuenta de la utilidad acumulada">
                            <span>👤 Retiro del dueño</span>
                            <span style="font-family:monospace;font-weight:700;">{{ $fmt($efMes->retiros) }}</span>
                        </div>
                        @endif

                        {{-- Saldo final --}}
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:.2rem;padding-top:.3rem;border-top:1.5px solid #e2e8f0;">
                            <span style="font-size:.6rem;color:#94a3b8;font-weight:600;">{{ $efMes->label_saldo }}</span>
                            <span style="font-size:.68rem;font-weight:700;color:#334155;font-family:monospace;">{{ $fmt($efMes->saldo_actual) }}</span>
                        </div>
                    </div>
                </div>

                {{-- Tarjeta Efectivo por recoger: lo que cada persona tiene por entregar --}}
                <div class="bank-card" onclick="window.location='{{ route('admin.cuadre-diario.consolidado', ['vista' => 'mes', 'mes' => sprintf('%04d-%02d', $anio, $mes)]) }}'"
                     title="Ver el consolidado de caja del mes" style="border-top-color:#0d9488;">
                    <div style="font-size:.65rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">💵 Efectivo</div>
                    <div style="font-size:.78rem;font-weight:700;color:#334155;margin-top:.1rem;">Por recoger</div>
                    <div style="margin-top:.55rem;">
                        <div style="font-size:.6rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.03em;margin-bottom:.2rem;">
                            {{ ($mes == now()->month && $anio == now()->year) ? 'Lo tienen hoy' : 'Lo tenían al cierre' }}
                        </div>
                        <div style="font-size:1.3rem;font-weight:900;color:#0f766e;line-height:1.1;">{{ $fmt(collect($porRecoger)->sum(fn ($e) => $e['real'] ?? $e['saldo'])) }}</div>
                    </div>
                    <div style="margin-top:.6rem;padding-top:.5rem;border-top:1px dashed #e2e8f0;display:flex;flex-direction:column;gap:.25rem;">
                        @forelse($porRecoger as $e)
                        @php $v = $e['real'] ?? $e['saldo']; @endphp
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:.3rem;">
                            <span style="font-size:.66rem;color:#475569;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0;"
                                  title="{{ $e['verificado'] ? 'Arrancó el mes con lo contado' : 'Arrancó el mes sin efectivo contado: se cuenta desde 0' }}">
                                {{ $e['nombre'] }}@unless($e['verificado']) <span style="color:#d97706">⚠</span>@endunless
                            </span>
                            <span style="font-size:.7rem;font-weight:800;font-family:monospace;white-space:nowrap;flex-shrink:0;color:{{ $v < 0 ? '#dc2626' : '#0f766e' }};">{{ $fmt($v) }}</span>
                        </div>
                        @empty
                        <div style="font-size:.66rem;color:#94a3b8;">Nadie tiene efectivo por entregar.</div>
                        @endforelse
                    </div>
                </div>

                {{-- Tarjeta Cartera Préstamos --}}
                <div class="bank-card" onclick="verPrestamos()" title="Clic para ver detalle de préstamos" style="border-top-color:#7c3aed; display:flex; flex-direction:column; min-height:165px;">
                    <div style="font-size:.65rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">💳 Cartera</div>
                    <div style="font-size:.78rem;font-weight:700;color:#334155;margin-top:.1rem;">Préstamos</div>

                    {{-- Hero: Neto / Saldo Pendiente --}}
                    <div style="margin-top:.55rem;">
                        <div style="font-size:.6rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.03em;margin-bottom:.2rem;">Neto {{ $mesesEs[$mes] }}</div>
                        <div style="font-size:1.3rem;font-weight:900;color:#dc2626;line-height:1.1;" id="kpi-prestamos-pendiente">—</div>
                    </div>

                    {{-- Detalles --}}
                    <div style="margin-top:auto;padding-top:.6rem;border-top:1px dashed #e2e8f0;display:flex;flex-direction:column;gap:.25rem;">
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <span style="font-size:.62rem;color:#475569;font-weight:700;text-transform:uppercase;">PRESTADO</span>
                            <span style="font-size:.72rem;font-weight:800;color:#4f46e5;font-family:monospace;" id="kpi-prestamos-total">—</span>
                        </div>
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <span style="font-size:.62rem;color:#475569;font-weight:700;text-transform:uppercase;">ABONADO</span>
                            <span style="font-size:.72rem;font-weight:800;color:#16a34a;font-family:monospace;" id="kpi-prestamos-abonado">—</span>
                        </div>
                    </div>

                    <div style="margin-top:.4rem;padding-top:.4rem;border-top:1px dashed #f1f5f9;display:flex;justify-content:space-between;align-items:center;">
                        <a href="{{ route('admin.prestamos.index') }}" target="_blank"
                           style="font-size:.65rem;color:#7c3aed;font-weight:700;text-decoration:none;"
                           onclick="event.stopPropagation()">→ Ver módulo</a>
                        <span style="font-size:.6rem;color:#94a3b8;" id="kpi-prestamos-cant">0</span>
                    </div>
                </div>

                {{-- Tarjeta Anticipos Disponibles --}}
                <div class="bank-card" onclick="window.location='{{ route('admin.anticipos.informe') }}'" title="Ver informe de anticipos" style="border-top-color:#d97706;">
                    <div style="font-size:.65rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">🟡 Anticipos</div>
                    <div style="font-size:.78rem;font-weight:700;color:#334155;margin-top:.1rem;">Disponibles</div>

                    <div style="margin-top:.6rem;">
                        <div style="font-size:1.25rem;font-weight:900;color:#d97706;line-height:1.1;">{{ $fmt($totalAnticiposDisponibles) }}</div>
                        <div style="font-size:.62rem;color:#94a3b8;margin-top:.15rem;">Sin aplicar a factura</div>
                    </div>

                    <div style="margin-top:auto;padding-top:.65rem;border-top:1px dashed #fde68a;display:flex;flex-direction:column;gap:.28rem;">
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <span style="font-size:.65rem;color:#64748b;font-weight:600;">Anticipos activos</span>
                            <span style="font-size:.72rem;font-weight:700;color:#d97706;font-family:monospace;">{{ $cantAnticiposDisponibles }}</span>
                        </div>
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:.1rem;">
                            <span style="font-size:.6rem;color:#94a3b8;">Disponibles + Parciales</span>
                            <span style="font-size:.68rem;color:#d97706;font-weight:700;">ver detalle →</span>
                        </div>
                    </div>
                </div>

                {{-- Tarjeta SS Pendiente de Pago --}}
                @if(($ssPendientePago ?? 0) > 0 || ($cantPlanillasPendientes ?? 0) > 0)
                <div class="bank-card" style="border-top-color:#f59e0b; cursor:default;">
                    <div style="font-size:.65rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.04em;">📋 Proyección</div>
                    <div style="font-size:.78rem;font-weight:700;color:#334155;margin-top:.1rem;">SS Pendiente de Pago</div>
                    <div style="margin-top:.55rem;">
                        <div style="font-size:.6rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.03em;margin-bottom:.2rem;">Estimado por pagar</div>
                        <div style="font-size:1.3rem;font-weight:900;color:#f59e0b;line-height:1.1;">{{ $fmt($ssPendientePago) }}</div>
                    </div>
                    <div style="margin-top:auto;padding-top:.6rem;border-top:1px dashed #fde68a;display:flex;flex-direction:column;gap:.22rem;">
                        <div style="display:flex;justify-content:space-between;align-items:center;">
                            <span style="font-size:.65rem;color:#475569;font-weight:700;">PLANILLAS PENDIENTES</span>
                            <span style="font-size:.72rem;font-weight:800;color:#f59e0b;font-family:monospace;">{{ $cantPlanillasPendientes }}</span>
                        </div>
                        <div style="font-size:.58rem;color:#94a3b8;font-style:italic;margin-top:.1rem;">
                            Planos del período sin gasto de pago registrado
                        </div>
                    </div>
                </div>
                @endif


            </div>
        </div>
    </div>

    {{-- Banner Anticipos / Cobros adelantados --}}
    @if($anticipos['cant'] > 0 || $cobradosAntes['cant'] > 0)
    <div style="display:grid;grid-template-columns:{{ ($anticipos['cant']>0 && $cobradosAntes['cant']>0) ? '1fr 1fr' : '1fr' }};gap:1rem;margin-bottom:1.25rem;">

        {{-- Anticipos cobrados este mes para períodos futuros --}}
        @if($anticipos['cant'] > 0)
        <div style="background:linear-gradient(135deg,#fff7ed,#ffedd5);border-radius:14px;padding:1rem 1.25rem;border-left:4px solid #f59e0b;box-shadow:0 1px 6px rgba(0,0,0,.05);">
            <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.65rem;">
                <span style="font-size:1.1rem;">📥</span>
                <div>
                    <div style="font-size:.82rem;font-weight:700;color:#92400e;">Anticipos cobrados este mes</div>
                    <div style="font-size:.7rem;color:#b45309;">{{ $anticipos['cant'] }} factura(s) de períodos futuros pagadas en {{ $mesesEs[$mes] }}</div>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.5rem;">
                <div style="background:rgba(255,255,255,.7);border-radius:8px;padding:.5rem .75rem;text-align:center;">
                    <div style="font-size:.68rem;font-weight:600;color:#92400e;margin-bottom:.15rem;">Admon (ingreso)</div>
                    <div style="font-size:.88rem;font-weight:800;color:#d97706;">{{ $fmt($anticipos['admon']) }}</div>
                </div>
                <div style="background:rgba(255,255,255,.7);border-radius:8px;padding:.5rem .75rem;text-align:center;">
                    <div style="font-size:.68rem;font-weight:600;color:#92400e;margin-bottom:.15rem;">SS (reservar)</div>
                    <div style="font-size:.88rem;font-weight:800;color:#d97706;">{{ $fmt($anticipos['ss']) }}</div>
                </div>
                <div style="background:rgba(245,158,11,.15);border-radius:8px;padding:.5rem .75rem;text-align:center;">
                    <div style="font-size:.68rem;font-weight:600;color:#92400e;margin-bottom:.15rem;">Total recibido</div>
                    <div style="font-size:.88rem;font-weight:800;color:#b45309;">{{ $fmt($anticipos['total']) }}</div>
                </div>
            </div>
            <div style="margin-top:.6rem;font-size:.68rem;color:#b45309;background:rgba(255,255,255,.5);border-radius:6px;padding:.35rem .65rem;">
                ⚠️ La SS de estos anticipos debe guardarse — se pagará cuando corresponda el período facturado.
            </div>
        </div>
        @endif

        {{-- Facturas del período cobradas en meses anteriores --}}
        @if($cobradosAntes['cant'] > 0)
        <div style="background:linear-gradient(135deg,#f0f9ff,#e0f2fe);border-radius:14px;padding:1rem 1.25rem;border-left:4px solid #0ea5e9;box-shadow:0 1px 6px rgba(0,0,0,.05);">
            <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.65rem;">
                <span style="font-size:1.1rem;">📤</span>
                <div>
                    <div style="font-size:.82rem;font-weight:700;color:#0c4a6e;">Ingresos del período cobrados antes</div>
                    <div style="font-size:.7rem;color:#0369a1;">{{ $cobradosAntes['cant'] }} factura(s) de {{ $mesesEs[$mes] }} pagadas en meses anteriores</div>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.5rem;">
                <div style="background:rgba(255,255,255,.7);border-radius:8px;padding:.5rem .75rem;text-align:center;">
                    <div style="font-size:.68rem;font-weight:600;color:#0c4a6e;margin-bottom:.15rem;">Admon cobrado</div>
                    <div style="font-size:.88rem;font-weight:800;color:#0369a1;">{{ $fmt($cobradosAntes['admon']) }}</div>
                </div>
                <div style="background:rgba(255,255,255,.7);border-radius:8px;padding:.5rem .75rem;text-align:center;">
                    <div style="font-size:.68rem;font-weight:600;color:#0c4a6e;margin-bottom:.15rem;">SS cobrada</div>
                    <div style="font-size:.88rem;font-weight:800;color:#0369a1;">{{ $fmt($cobradosAntes['ss']) }}</div>
                </div>
                <div style="background:rgba(14,165,233,.1);border-radius:8px;padding:.5rem .75rem;text-align:center;">
                    <div style="font-size:.68rem;font-weight:600;color:#0c4a6e;margin-bottom:.15rem;">Total anticipado</div>
                    <div style="font-size:.88rem;font-weight:800;color:#0c4a6e;">{{ $fmt($cobradosAntes['total']) }}</div>
                </div>
            </div>
            <div style="margin-top:.6rem;font-size:.68rem;color:#0369a1;background:rgba(255,255,255,.5);border-radius:6px;padding:.35rem .65rem;">
                ℹ️ Este dinero ya entró en caja en meses anteriores — incluido aquí por devengado del período.
            </div>
        </div>
        @endif

    </div>
    @endif

    {{-- ══ SEGURIDAD SOCIAL ══ --}}
    <div style="display:grid;grid-template-columns:1fr 2fr;gap:1.25rem;margin-bottom:1.5rem;">

        {{-- ── Card Flujo SS ── --}}
        <div style="background:#fff;border-radius:14px;box-shadow:0 1px 8px rgba(0,0,0,.06);overflow:hidden;display:flex;flex-direction:column;">

            {{-- Header --}}
            <div style="background:linear-gradient(135deg,#0f766e,#0d9488);padding:.8rem 1.1rem;display:flex;justify-content:space-between;align-items:center;">
                <div>
                    <div style="font-size:.6rem;font-weight:700;text-transform:uppercase;color:rgba(255,255,255,.55);letter-spacing:.08em;">Canal 4</div>
                    <div style="font-size:.95rem;font-weight:800;color:#fff;margin-top:.15rem;">🏥 Seguridad Social</div>
                </div>
                <div style="text-align:right;">
                    <div style="font-size:.62rem;color:rgba(255,255,255,.6);">{{ $mesesEs[$mes] }} {{ $anio }}</div>
                    <div style="font-size:1rem;font-weight:900;color:#fff;">{{ $fmt($totalSScanalRaw) }}</div>
                </div>
            </div>

            @php
                $ssColor    = $saldoSS >= 0 ? '#16a34a' : '#dc2626';
                $ssBg       = $saldoSS >= 0 ? '#f0fdf4' : '#fef2f2';
                $ssBorder   = $saldoSS >= 0 ? '#bbf7d0' : '#fecaca';
                $fmtSS      = fn($v) => ($v >= 0 ? '+' : '') . $fmt($v);
                
                // SS atrasada cobrada en este mes (se sumará a la visualización del mes actual)
                $ssAtrasadasCobradas = max(0.0, $recaudoSS - $ssActuales - $ingresosSS['ss_futuras']);
            @endphp

            {{-- Body --}}
            <div style="padding: 1.1rem; display: flex; flex-direction: column; gap: 0.75rem;">
                
                {{-- Sección 1: Seguridad Social Operativa --}}
                <div style="display: flex; flex-direction: column; gap: 0.35rem;">
                    
                    {{-- SS MES ANTERIOR --}}
                    @if($saldoSSMesAnterior > 0)
                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: .78rem;">
                        <span style="color: #475569; display: flex; align-items: center; gap: .35rem;">
                            <span style="font-size: .9rem;">📦</span> Ss mes {{ $mesesEs[$mesAnt] }}
                        </span>
                        <span style="font-family: monospace; font-weight: 700; color: #0369a1;">
                            {{ $fmt($saldoSSMesAnterior) }}
                        </span>
                    </div>
                    @endif
                    
                    {{-- SS RECAUDADO MES ACTUAL (Incluyendo lo cobrado atrasado) --}}
                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: .78rem;">
                        <span style="color: #475569; display: flex; align-items: center; gap: .35rem;">
                            <span style="font-size: .9rem;">📥</span> Ss recaudo {{ $mesesEs[$mes] }}
                        </span>
                        <span style="font-family: monospace; font-weight: 700; color: #0f766e;">
                            {{ $fmt($ssActuales + $ssAtrasadasCobradas) }}
                        </span>
                    </div>
                    
                    {{-- MORA MES ACTUAL --}}
                    @if($moraRecogida > 0)
                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: .78rem;">
                        <span style="color: #475569; display: flex; align-items: center; gap: .35rem;">
                            <span style="font-size: .9rem;">📈</span> Mora {{ $mesesEs[$mes] }}
                        </span>
                        <span style="font-family: monospace; font-weight: 700; color: #d97706;">
                            {{ $fmt($moraRecogida) }}
                        </span>
                    </div>
                    @endif




                    {{-- SUBTOTAL DE INGRESOS SS (sin Julio — solo el mes actual) --}}
                    <div style="display: flex; justify-content: flex-end; align-items: center; font-size: .78rem; border-top: 1px solid #e2e8f0; margin-top: .1rem; padding-top: .15rem; font-weight: 700; font-family: monospace; color: #334155;">
                        {{ $fmt($totalSSMesActual) }}
                    </div>

                    {{-- GASTOS PLANILLA MES ACTUAL --}}
                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: .78rem; margin-top: .15rem;">
                        <span style="color: #475569; display: flex; align-items: center; gap: .35rem;">
                            <span style="font-size: .9rem;">📥</span> Gastos planilla {{ $mesesEs[$mes] }}
                        </span>
                        <span style="font-family: monospace; font-weight: 700; color: #dc2626;">
                            − {{ $fmt($pagadoSSReg) }}
                        </span>
                    </div>

 
                </div>{{-- /sección 1 items SS --}}

                <div style="margin-top: .15rem; border-top: 1px solid #f1f5f9; padding-top: .15rem;"></div>

                {{-- Sección 2: Retiros --}}
                <div>
                    <div style="font-size: .82rem; font-weight: 800; color: #c2410c; margin-bottom: .4rem; display: flex; align-items: center; gap: .35rem;">
                        🛑 Retiros seguridad social
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 0.35rem;">
                        
                        {{-- RETIROS CONSOLIDADO --}}
                        <div style="display: flex; justify-content: space-between; align-items: center; font-size: .78rem;">
                            <span style="color: #475569; display: flex; align-items: center; gap: .35rem;">
                                <span style="font-size: .9rem;">⚖️</span> Retiros consolidado
                            </span>
                            <span style="font-family: monospace; font-weight: 700; color: #475569;">
                                {{ $costoRetiros > 0 ? $fmt($costoRetiros) : '0' }}
                            </span>
                        </div>

                        {{-- RETIROS DE AFILIACIONES --}}
                        <div style="display: flex; justify-content: space-between; align-items: center; font-size: .78rem;">
                            <span style="color: #475569; display: flex; align-items: center; gap: .35rem;">
                                <span style="font-size: .9rem;">📑</span> Retiros de afiliaciones
                            </span>
                            <span style="font-family: monospace; font-weight: 700; color: #0284c7;">
                                {{ $fmt($distRetiroAcumulado) }}
                            </span>
                        </div>
 
                        {{-- GASTOS EN RETIROS DEL MES --}}
                        <div style="display: flex; justify-content: space-between; align-items: center; font-size: .78rem;">
                            <span style="color: #475569; display: flex; align-items: center; gap: .35rem;">
                                <span style="font-size: .9rem;">🛑</span> Gastos en retiros del mes
                            </span>
                            <span style="font-family: monospace; font-weight: 700; color: #dc2626;">
                                − {{ $fmt($pagadoSSRetiro) }}
                            </span>
                        </div>
 
                        {{-- SUBTOTAL RETIROS --}}
                        <div style="display: flex; justify-content: space-between; align-items: center; font-size: .78rem; border-top: 1px dashed #ffedd5; margin-top: .15rem; padding-top: .25rem; font-weight: 800;">
                            <span style="color: #c2410c;">Total o déficit retiros</span>
                            <span style="font-family: monospace; color: {{ $subtotalRetiros >= 0 ? '#16a34a' : '#dc2626' }};">
                                {{ $subtotalRetiros >= 0 ? '+' : '' }}{{ $fmt($subtotalRetiros) }}
                            </span>
                        </div>
                    </div>
                </div>

                {{-- SALDO PLANILLAS (después de absorber retiros) --}}
                <div style="display: flex; justify-content: space-between; align-items: center; font-size: .78rem; border-top: 2px solid #0f766e; margin-top: .2rem; padding-top: .3rem; font-weight: 800;">
                    <span style="color: #0f766e;">Saldo planillas</span>
                    <span style="font-family: monospace; font-size: .88rem; color: {{ $saldoPlanillas >= 0 ? '#16a34a' : '#dc2626' }};">
                        {{ $fmt($saldoPlanillas) }}
                    </span>
                </div>

            </div>
 
            {{-- Bloque 4: Saldo SS para el Siguiente Mes --}}
            <div style="padding:.75rem 1.1rem;background:{{ $ssBg }};border-top:2px solid {{ $ssBorder }};margin-top:auto;">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div style="font-size:.8rem;font-weight:800;color:#334155;">saldo próximo mes</div>
                    <div style="font-size:1.05rem;font-weight:900;color:{{ $ssColor }};font-family:monospace;">{{ $fmt($saldoSS) }}</div>
                </div>
            </div>

        </div>



        {{-- Desglose Egresos SS --}}
        <div style="background:#fff;border-radius:14px;box-shadow:0 1px 8px rgba(0,0,0,.06);overflow:hidden;">
            <div style="background:linear-gradient(135deg,#7c3aed,#8b5cf6);padding:.85rem 1.25rem;display:flex;align-items:center;gap:.75rem;">
                <span style="font-size:1.2rem;">💸</span>
                <div>
                    <div style="color:#fff;font-weight:700;font-size:.9rem;">Desglose Egresos SS</div>
                    <div style="color:rgba(255,255,255,.7);font-size:.75rem;">Pagos planillas realizados</div>
                </div>
                <div style="margin-left:auto;display:flex;align-items:center;gap:.75rem;">
                    {{-- Botón nuevo: ver todas las facturas por planilla --}}
                    <a href="{{ route('admin.informes.financiero.ss_planillas', ['mes'=>$mes,'anio'=>$anio]) }}"
                       style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.5);color:#fff;border-radius:8px;padding:.3rem .75rem;font-size:.72rem;font-weight:700;text-decoration:none;white-space:nowrap;"
                       title="Ver todas las facturas vinculadas a cada planilla del mes">
                        📋 Facturas por planilla
                    </a>
                    {{-- Ordenar --}}
                    <div style="display:flex;gap:.4rem;">
                        <button onclick="sortEgresos('fecha')" id="sortFecha"
                            style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);color:#fff;border-radius:7px;padding:.25rem .65rem;font-size:.72rem;cursor:pointer;font-weight:600;transition:background .15s;">
                            📅 Fecha
                        </button>
                        <button onclick="sortEgresos('valor')" id="sortValor"
                            style="background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);color:#fff;border-radius:7px;padding:.25rem .65rem;font-size:.72rem;cursor:pointer;font-weight:600;transition:background .15s;">
                            💰 Valor
                        </button>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-weight:800;color:#fff;font-size:1.1rem;">{{ $fmt($pagadoSSRaw) }}</div>
                        <div style="font-size:.7rem;color:rgba(255,255,255,.6);">Saldo: {{ $fmt($saldoSS) }}</div>
                    </div>
                </div>
            </div>
            @if($egresosSSDetalle->isEmpty())
            <div style="padding:2rem;text-align:center;color:#94a3b8;font-size:.84rem;">Sin pagos de planilla este mes</div>
            @else
            {{-- Cabecera tabla --}}
            <div style="display:grid;grid-template-columns:90px 1fr 130px 120px 110px;gap:.4rem;padding:.4rem 1rem;background:#f8fafc;border-bottom:2px solid #e2e8f0;font-size:.67rem;font-weight:700;text-transform:uppercase;color:#64748b;">
                <span>Fecha</span>
                <span>Descripción / Planilla</span>
                <span>Banco / Cuenta</span>
                <span style="text-align:right;color:#10b981;">SS Cobrado</span>
                <span style="text-align:right;">Valor</span>
            </div>
            <div id="egresosSSList" style="max-height:320px;overflow-y:auto;">
                @foreach($egresosSSDetalle as $eg)
                @php
                    $numPlan      = $eg->numero_planilla ?? null;
                    $fechaEg      = sqldate($eg->fecha);
                    $fechaStr     = $fechaEg ? $fechaEg->format('d/m/Y') : '—';
                    $fechaIso     = $fechaEg ? $fechaEg->format('Y-m-d') : '';
                    $ssCobradoReg = (float)($eg->ss_cobrado_facturas ?? 0); // numero_factura > 0
                    $ssCobradoRet = (float)($eg->ss_retiro_facturas  ?? 0); // numero_factura = 0
                    $ssMora       = (float)($eg->ss_mora_facturas    ?? 0); // mora
                    $ssCobrado    = $ssCobradoReg + $ssCobradoRet + $ssMora; // total combinado
                    $ssPagado     = (float)($eg->total ?? 0);
                    $ssDiff       = abs($ssCobrado - $ssPagado);
                    $esAdvertencia = $numPlan && $ssCobrado > 0 && $ssDiff > 50000;
                @endphp
                @php $saldoFila = $ssCobrado - $ssPagado; @endphp
                <div class="audit-row egreso-ss-row"
                    data-fecha="{{ $fechaIso }}"
                    data-valor="{{ $eg->total }}"
                    style="display:grid;grid-template-columns:90px 1fr 130px 120px 110px;gap:.4rem;padding:.55rem 1rem;border-bottom:1px solid #f1f5f9;align-items:start;{{ $saldoFila < -1000 ? 'background:#fff7ed;border-left:3px solid #f59e0b;' : '' }}"
                    @if($numPlan)
                        onclick="auditarPlanilla('{{ addslashes($numPlan) }}','{{ addslashes($eg->descripcion ?? $eg->pagado_a) }}')"
                        title="🔍 Clic para auditar planilla {{ $numPlan }}"
                    @endif>
                    {{-- Fecha --}}
                    <div>
                        <div style="font-size:.8rem;font-weight:700;color:#0d2550;">{{ $fechaStr }}</div>
                    </div>
                    {{-- Descripción --}}
                    <div>
                        <div style="font-size:.78rem;color:#334155;line-height:1.4;word-break:break-word;">{{ $eg->descripcion ?: $eg->pagado_a }}</div>
                    </div>
                    {{-- Banco / Cuenta --}}
                    @php
                        $bancoLabel = $eg->banco_nombre
                            ? $eg->banco_nombre . ($eg->banco_titular ? ' — ' . $eg->banco_titular : '')
                            : null;
                    @endphp
                    <div style="display:flex;align-items:center;gap:.3rem;">
                        @if(!empty($eg->imagen_path))
                            <span onclick="verImagenGasto('{{ \Storage::url($eg->imagen_path) }}'); event.stopPropagation();" title="Clic para ver comprobante" style="cursor:pointer;font-size:.85rem;line-height:1;transition:transform 0.1s;" onmouseover="this.style.transform='scale(1.2)'" onmouseout="this.style.transform='scale(1)'">🖼️</span>
                        @endif
                        @if($bancoLabel)
                            <div style="font-size:.76rem;font-weight:600;color:#1e40af;">{{ $bancoLabel }}</div>
                        @else
                            <div style="font-size:.76rem;color:#94a3b8;">Efectivo</div>
                        @endif
                    </div>
                    {{-- SS Cobrado desglosado --}}
                    <div style="text-align:right;font-size:.78rem;line-height:1.25;color:#475569;">
                        <div>
                            <span style="font-weight:700;color:{{ $ssCobrado > 0 ? '#10b981' : '#64748b' }};font-size:.85rem;">{{ $fmt($ssCobrado) }}</span>
                        </div>
                        <div style="font-size:.68rem;margin-top:1px;">
                            <span style="color:#94a3b8;">Retiros.</span> 
                            <span style="font-weight:600;color:{{ $ssCobradoRet > 0 ? '#c2410c' : '#64748b' }};">{{ $fmt($ssCobradoRet) }}</span>
                        </div>
                        <div style="font-size:.68rem;margin-top:1px;">
                            <span style="color:#94a3b8;">Mora.</span> 
                            <span style="font-weight:600;color:{{ $ssMora > 0 ? '#b45309' : '#64748b' }};">{{ $fmt($ssMora) }}</span>
                        </div>
                    </div>
                    {{-- Valor pagado --}}
                    <div style="text-align:right;">
                        <div style="font-weight:700;color:#7c3aed;font-size:.88rem;">{{ $fmt($eg->total) }}</div>
                        @if($eg->cantidad > 1)
                        <div style="font-size:.67rem;color:#94a3b8;">{{ $eg->cantidad }} reg.</div>
                        @endif
                        @if(abs($saldoFila) > 1)
                            @if($saldoFila > 0)
                                <div style="font-size:.68rem;font-weight:600;color:#10b981;" title="Sobró (Exceso de recaudo)">
                                    +{{ $fmt($saldoFila) }}
                                </div>
                            @else
                                <div style="font-size:.68rem;font-weight:600;color:#dc2626;" title="Se pagó de más (Déficit)">
                                    -{{ $fmt(abs($saldoFila)) }}
                                </div>
                            @endif
                        @endif
                    </div>
                </div>
                @endforeach
            </div>
            {{-- Fila de totales y saldo --}}
            @php
                $sumaCobradoFact = $egresosSSDetalle->sum('ss_cobrado_facturas');
                $sumaMoraFact = $egresosSSDetalle->sum('ss_mora_facturas');
                $sumaRetiroFact = $egresosSSDetalle->sum('ss_retiro_facturas');
                
                $totalSsCobradoEgresos = $sumaCobradoFact + $sumaRetiroFact + $sumaMoraFact;
                $saldoEgresos = $totalSsCobradoEgresos - $pagadoSSRaw;
            @endphp
            <div style="display:grid;grid-template-columns:90px 1fr 130px 120px 110px;gap:.4rem;padding:.55rem 1rem;background:#f5f3ff;border-top:2px solid #ddd6fe;font-size:.78rem;font-weight:700;">
                <span></span>
                <span style="color:#7c3aed;">{{ $egresosSSDetalle->count() }} planilla(s)</span>
                <span></span>
                <span style="text-align:right;color:#10b981;">{{ $fmt($totalSsCobradoEgresos) }}</span>
                <span style="text-align:right;color:#7c3aed;">{{ $fmt($pagadoSSRaw) }}</span>
            </div>
            <div style="display:grid;grid-template-columns:90px 1fr 130px 120px 110px;gap:.4rem;padding:.5rem 1rem;background:{{ $saldoEgresos >= 0 ? '#f0fdf4' : '#fef2f2' }};border-top:1px solid {{ $saldoEgresos >= 0 ? '#bbf7d0' : '#fecaca' }};">
                <span></span>
                <span style="grid-column: span 3; font-size:.74rem;color:#64748b;font-weight:600;white-space:nowrap;">ss cobrado {{ $fmt($sumaCobradoFact) }} mora {{ $fmt($sumaMoraFact) }} retiros {{ $fmt($sumaRetiroFact) }}</span>
                <span style="text-align:right;font-size:.9rem;font-weight:800;color:{{ $saldoEgresos >= 0 ? '#15803d' : '#dc2626' }};">{{ $saldoEgresos >= 0 ? '+' : '' }}{{ $fmt($saldoEgresos) }}</span>
            </div>
            @endif
        </div>
    </div>

    {{-- Tabla diaria --}}


    <div style="background:#fff;border-radius:14px;box-shadow:0 1px 8px rgba(0,0,0,.06);overflow:hidden;margin-bottom:1.5rem;">
        <div style="padding:.85rem 1.25rem;background:#f8fafc;border-bottom:2px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
            <span style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#64748b;">Desglose Diario — {{ $mesesEs[$mes] }} {{ $anio }}</span>
            <span style="font-size:.72rem;color:#94a3b8;">Clic en un día para ver detalle</span>
        </div>
        {{-- Cabecera --}}
        <div style="display:grid;grid-template-columns:40px 48px 1fr 48px 1fr 1fr 1fr 1fr 1fr 68px;gap:.4rem;padding:.5rem .75rem;background:#f8fafc;border-bottom:2px solid #e2e8f0;font-size:.68rem;text-transform:uppercase;color:#64748b;font-weight:700;">
            <span>Día</span>
            <span style="color:#3b82f6;text-align:center;">#</span>
            <span style="color:#3b82f6;">Planillas</span>
            <span style="color:#8b5cf6;text-align:center;">#</span>
            <span style="color:#8b5cf6;">Afiliaciones</span>
            <span style="color:#10b981;">Trámites</span>
            <span style="color:#0e7490;">SS</span>
            <span style="color:#ef4444;">Gastos</span>
            <span>Utilidad</span>
            <span></span>
        </div>
        @php $totDia=['planillas'=>0,'afiliaciones'=>0,'tramites'=>0,'ss'=>0,'gastos'=>0,'utilidad'=>0,'cant_planillas'=>0,'cant_afiliaciones'=>0]; @endphp
        @foreach($diario as $d)
        @php
            foreach(['planillas','afiliaciones','tramites','ss','gastos','utilidad'] as $k) $totDia[$k]+=$d[$k];
            $totDia['cant_planillas']+=$d['cant_planillas'];
            $totDia['cant_afiliaciones']+=$d['cant_afiliaciones'];
            $hayData = $d['planillas']>0 || $d['afiliaciones']>0 || $d['tramites']>0 || $d['gastos']>0;
        @endphp
        @if($hayData)
        <div style="display:grid;grid-template-columns:40px 48px 1fr 48px 1fr 1fr 1fr 1fr 1fr 68px;gap:.4rem;padding:.48rem .75rem;border-bottom:1px solid #f1f5f9;font-size:.8rem;align-items:center;cursor:pointer;transition:background .12s;"
             onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''" onclick="verDetalleDia({{ $d['dia'] }},{{ $mes }},{{ $anio }})">
            <span style="font-weight:700;color:#0d2550;">{{ str_pad($d['dia'],2,'0',STR_PAD_LEFT) }}</span>
            <span style="text-align:center;background:#dbeafe;color:#1e40af;border-radius:6px;padding:.1rem .3rem;font-size:.72rem;font-weight:700;">{{ $d['cant_planillas']>0?$d['cant_planillas']:'' }}</span>
            <span style="color:#3b82f6;">{{ $d['planillas']>0?$fmt($d['planillas']):'—' }}</span>
            <span style="text-align:center;background:#ede9fe;color:#7c3aed;border-radius:6px;padding:.1rem .3rem;font-size:.72rem;font-weight:700;">{{ $d['cant_afiliaciones']>0?$d['cant_afiliaciones']:'' }}</span>
            <span style="color:#8b5cf6;">{{ $d['afiliaciones']>0?$fmt($d['afiliaciones']):'—' }}</span>
            <span style="color:#10b981;">{{ $d['tramites']>0?$fmt($d['tramites']):'—' }}</span>
            <span style="color:#0e7490;font-weight:600;">{{ $d['ss']>0?$fmt($d['ss']):'—' }}</span>
            <span style="color:#ef4444;">{{ $d['gastos']>0?'- '.$fmt($d['gastos']):'—' }}</span>
            <span style="font-weight:700;color:{{ $d['utilidad']>=0?'#10b981':'#ef4444' }};">{{ $fmt($d['utilidad']) }}</span>
            <span>
                <button onclick="event.stopPropagation();verDesgloseDia({{ $d['dia'] }},{{ $mes }},{{ $anio }})"
                        title="Ver desglose de administración del día (sin seguro)"
                        style="background:rgba(37,99,235,.1);border:1px solid rgba(37,99,235,.25);border-radius:6px;padding:.15rem .4rem;font-size:.68rem;font-weight:700;color:#1e40af;cursor:pointer;white-space:nowrap;">
                    📋
                </button>
            </span>
        </div>
        @endif
        @endforeach
        {{-- Totales --}}
        <div style="display:grid;grid-template-columns:40px 48px 1fr 48px 1fr 1fr 1fr 1fr 1fr 68px;gap:.4rem;padding:.6rem .75rem;background:#f8fafc;font-weight:700;border-top:2px solid #e2e8f0;font-size:.8rem;">
            <span style="color:#0d2550;">TOT</span>
            <span style="text-align:center;background:#dbeafe;color:#1e40af;border-radius:6px;padding:.1rem .3rem;font-size:.72rem;">{{ $totDia['cant_planillas'] }}</span>
            <span style="color:#2563eb;">{{ $fmt($totDia['planillas']) }}</span>
            <span style="text-align:center;background:#ede9fe;color:#7c3aed;border-radius:6px;padding:.1rem .3rem;font-size:.72rem;">{{ $totDia['cant_afiliaciones'] }}</span>
            <span style="color:#8b5cf6;">{{ $fmt($totDia['afiliaciones']) }}</span>
            <span style="color:#10b981;">{{ $fmt($totDia['tramites']) }}</span>
            <span style="color:#0e7490;">{{ $fmt($totDia['ss']) }}</span>
            <span style="color:#ef4444;">- {{ $fmt($totDia['gastos']) }}</span>
            <span style="color:{{ $totDia['utilidad']>=0?'#10b981':'#ef4444' }};">{{ $fmt($totDia['utilidad']) }}</span>
            <span></span>
        </div>
    </div>

    <div style="display:flex;justify-content:center;margin-top:1.2rem;margin-bottom:1.2rem;">
        <a href="{{ route('admin.informes.auditoria_facturas', ['anio' => $anio, 'mes_pago' => $mes]) }}" 
           style="display:inline-flex;align-items:center;gap:.5rem;background:linear-gradient(135deg,#1e3a8a,#2563eb);color:#fff;border-radius:10px;padding:.65rem 1.4rem;font-size:.85rem;font-weight:700;text-decoration:none;box-shadow:0 4px 12px rgba(37,99,235,.25);transition:transform .12s,box-shadow .12s;"
           onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 6px 16px rgba(37,99,235,.35)'" 
           onmouseout="this.style.transform='';this.style.boxShadow='0 4px 12px rgba(37,99,235,.25)'">
            🔍 Auditoría de Facturas
        </a>
    </div>
</div>

{{-- ══ Modal Egresos Operativos — detalle de gastos ══ --}}
<div id="modalGastos" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:flex-start;justify-content:center;padding-top:2.5vh;overflow-y:auto;">
    <div style="background:#fff;border-radius:18px;width:min(1100px,97vw);box-shadow:0 25px 60px rgba(0,0,0,.25);margin-bottom:2rem;">
        <div style="background:linear-gradient(135deg,#b91c1c,#ef4444);border-radius:18px 18px 0 0;padding:1rem 1.5rem;display:flex;align-items:center;justify-content:space-between;">
            <div>
                <div style="color:#fff;font-weight:800;font-size:1.05rem;">💸 Egresos Operativos — {{ $mesesEs[$mes] }} {{ $anio }}</div>
                <div style="color:rgba(255,255,255,.65);font-size:.74rem;margin-top:.15rem;">Por el mes al que corresponden (no el del pago) · haz clic en ✏️ para editar</div>
            </div>
            <div style="display:flex;gap:.5rem;align-items:center;">
                @if($esAdmin)
                <button onclick="abrirModalGasto()"
                   style="background:#22c55e;color:#fff;border:none;border-radius:8px;padding:.3rem .8rem;font-size:.75rem;font-weight:700;cursor:pointer;white-space:nowrap;">➕ Registrar Gasto</button>
                @endif
                <a href="{{ route('admin.informes.gastos.index', ['mes'=>$mes,'anio'=>$anio]) }}"
                   style="background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.4);color:#fff;border-radius:8px;padding:.3rem .8rem;font-size:.75rem;font-weight:700;text-decoration:none;white-space:nowrap;">→ Módulo Gastos</a>
                <button onclick="document.getElementById('modalGastos').style.display='none'"
                        style="background:rgba(255,255,255,.15);border:none;color:#fff;border-radius:8px;width:32px;height:32px;cursor:pointer;font-size:1.1rem;">✕</button>
            </div>
        </div>
        <div id="modalGastosResumen" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:.6rem;padding:.9rem 1.25rem;background:#fef2f2;border-bottom:1px solid #fecaca;"></div>
        <div id="modalGastosBody" style="padding:1rem 1.25rem;max-height:60vh;overflow-y:auto;font-size:.82rem;color:#475569;">
            <div style="text-align:center;padding:2rem;color:#94a3b8;">Cargando…</div>
        </div>
    </div>
</div>

{{-- ══ Lightbox imagen soporte ══ --}}
<div id="modalLightbox" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:10000;align-items:center;justify-content:center;">
    <div style="position:relative;max-width:92vw;max-height:92vh;display:flex;flex-direction:column;align-items:center;gap:.75rem;">
        <button onclick="document.getElementById('modalLightbox').style.display='none'"
                style="position:absolute;top:-42px;right:0;background:rgba(255,255,255,.15);border:none;color:#fff;border-radius:50%;width:34px;height:34px;cursor:pointer;font-size:1.2rem;">✕</button>
        <img id="lightboxImg" src="" alt="Soporte" style="max-width:88vw;max-height:80vh;border-radius:10px;object-fit:contain;box-shadow:0 8px 40px rgba(0,0,0,.5);">
        <a id="lightboxImgLink" href="" target="_blank" style="color:#93c5fd;font-size:.8rem;text-decoration:none;font-weight:600;">↗ Abrir en nueva pestaña</a>
    </div>
</div>

{{-- Modal detalle día (mejorado con fetch) --}}
<div id="modalDia" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:flex-start;justify-content:center;padding-top:3vh;overflow-y:auto;">
    <div style="background:#fff;border-radius:18px;width:min(860px,96vw);box-shadow:0 25px 60px rgba(0,0,0,.22);">
        <div style="background:linear-gradient(135deg,#0d2550,#1e40af);border-radius:18px 18px 0 0;padding:1rem 1.5rem;display:flex;align-items:center;justify-content:space-between;">
            <div>
                <div id="modalDiaTitulo" style="color:#fff;font-weight:800;font-size:1rem;"></div>
                <div id="modalDiaSub" style="color:rgba(255,255,255,.6);font-size:.74rem;margin-top:.15rem;"></div>
            </div>
            <div style="display:flex;gap:.5rem;align-items:center;">
                <select id="diaFiltroTipo" onchange="aplicarFiltroDia()" style="padding:.3rem .6rem;border-radius:7px;border:1px solid rgba(255,255,255,.3);background:rgba(255,255,255,.15);color:#fff;font-size:.75rem;cursor:pointer;">
                    <option value="todos">Todos</option>
                    <option value="planilla">Planillas</option>
                    <option value="afiliacion">Afiliaciones</option>
                    <option value="otro_ingreso">Trámites</option>
                    <option value="gastos">Gastos</option>
                </select>
                <button onclick="document.getElementById('modalDia').style.display='none'" style="background:rgba(255,255,255,.15);border:none;color:#fff;border-radius:8px;width:32px;height:32px;cursor:pointer;font-size:1.1rem;">✕</button>
            </div>
        </div>
        {{-- Resumen cards --}}
        <div id="modalDiaSummary" style="display:grid;grid-template-columns:repeat(6,1fr);gap:.5rem;padding:.85rem 1.25rem;background:#f8fafc;border-bottom:1px solid #e2e8f0;"></div>
        {{-- Tabla --}}
        <div id="modalDiaBody" style="padding:1rem 1.25rem;max-height:55vh;overflow-y:auto;font-size:.82rem;color:#475569;">Cargando…</div>
    </div>
</div>

{{-- Modal desglose de administración de un día (sin seguro, mensajería agrupada en otros) --}}
<div id="modalDesgloseDia" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:flex-start;justify-content:center;padding-top:4vh;overflow-y:auto;">
    <div style="background:#fff;border-radius:18px;width:min(560px,95vw);box-shadow:0 25px 60px rgba(0,0,0,.22);">
        <div style="background:linear-gradient(135deg,#1e3a8a,#2563eb);border-radius:18px 18px 0 0;padding:1rem 1.5rem;display:flex;align-items:center;justify-content:space-between;">
            <div>
                <div id="modalDesgloseDiaTitulo" style="color:#fff;font-weight:800;font-size:1rem;">📋 Desglose del día</div>
                <div style="color:rgba(255,255,255,.65);font-size:.7rem;margin-top:.15rem;">Efectivo · Consignación · Gastos del día</div>
            </div>
            <button onclick="document.getElementById('modalDesgloseDia').style.display='none'" style="background:rgba(255,255,255,.15);border:none;color:#fff;border-radius:8px;width:32px;height:32px;cursor:pointer;font-size:1.1rem;">✕</button>
        </div>
        <div id="modalDesgloseDiaBody" style="padding:1rem 1.25rem;max-height:75vh;overflow-y:auto;font-size:.82rem;color:#475569;">Cargando…</div>
    </div>
</div>

{{-- Modal préstamos del mes --}}
<div id="modalPrestamos" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:flex-start;justify-content:center;padding-top:4vh;overflow-y:auto;">
    <div style="background:#fff;border-radius:18px;width:min(720px,96vw);box-shadow:0 25px 60px rgba(0,0,0,.22);">
        <div style="background:linear-gradient(135deg,#4f46e5,#7c3aed);border-radius:18px 18px 0 0;padding:1rem 1.5rem;display:flex;align-items:center;justify-content:space-between;">
            <div>
                <div style="color:#fff;font-weight:800;font-size:1rem;">💳 Préstamos del Mes</div>
                <div style="color:rgba(255,255,255,.6);font-size:.74rem;">Servicios facturados como préstamo pendientes de cobro</div>
            </div>
            <div style="display:flex;gap:.5rem;">
                <a href="{{ route('admin.prestamos.index') }}" target="_blank" style="background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.4);color:#fff;border-radius:8px;padding:.3rem .8rem;font-size:.75rem;font-weight:700;text-decoration:none;">→ Módulo Préstamos</a>
                <button onclick="document.getElementById('modalPrestamos').style.display='none'" style="background:rgba(255,255,255,.15);border:none;color:#fff;border-radius:8px;width:32px;height:32px;cursor:pointer;font-size:1.1rem;">✕</button>
            </div>
        </div>
        <div id="modalPrestamosBody" style="padding:1.25rem;max-height:60vh;overflow-y:auto;font-size:.82rem;">Cargando…</div>
    </div>
</div>

{{-- Modal Efectivo --}}
<div id="modalEfectivo" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:flex-start;justify-content:center;padding-top:3vh;overflow-y:auto;">
    <div style="background:#fff;border-radius:18px;width:min(1000px,96vw);box-shadow:0 25px 60px rgba(0,0,0,.22);">
        <div style="background:linear-gradient(135deg,#15803d,#16a34a);border-radius:18px 18px 0 0;padding:1rem 1.5rem;display:flex;align-items:center;justify-content:space-between;">
            <div>
                <div style="color:#fff;font-weight:800;font-size:1rem;">💵 Movimientos en Efectivo — {{ $mesesEs[$mes] }} {{ $anio }}</div>
                <div style="color:rgba(255,255,255,.65);font-size:.74rem;margin-top:.15rem;">Facturas cobradas en efectivo · Gastos en efectivo · Cuadres abiertos por asesor</div>
            </div>
            <button onclick="document.getElementById('modalEfectivo').style.display='none'" style="background:rgba(255,255,255,.15);border:none;color:#fff;border-radius:8px;width:32px;height:32px;cursor:pointer;font-size:1.1rem;">✕</button>
        </div>
        <div id="modalEfectivoSummary" style="display:grid;grid-template-columns:repeat(3,1fr);gap:.65rem;padding:.85rem 1.25rem;background:#f0fdf4;border-bottom:1px solid #bbf7d0;"></div>
        <div id="modalEfectivoBody" style="padding:1.25rem;max-height:65vh;overflow-y:auto;font-size:.82rem;color:#475569;">Cargando...</div>
    </div>
</div>

{{-- Modal movimientos banco --}}
<div id="modalBanco" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:flex-start;justify-content:center;padding-top:3vh;overflow-y:auto;">
    <div style="background:#fff;border-radius:18px;width:min(980px,96vw);box-shadow:0 25px 60px rgba(0,0,0,.22);">
        <div style="background:linear-gradient(135deg,#0d2550,#1e40af);border-radius:18px 18px 0 0;padding:1rem 1.5rem;display:flex;align-items:center;justify-content:space-between;">
            <div>
                <div id="modalBancoTitulo" style="color:#fff;font-weight:800;font-size:1rem;"></div>
                <div id="modalBancoSub" style="color:rgba(255,255,255,.65);font-size:.74rem;margin-top:.15rem;"></div>
            </div>
            <button onclick="document.getElementById('modalBanco').style.display='none'" style="background:rgba(255,255,255,.15);border:none;color:#fff;border-radius:8px;width:32px;height:32px;cursor:pointer;font-size:1.1rem;">✕</button>
        </div>
        <div id="modalBancoSummary" style="display:grid;grid-template-columns:repeat(3,1fr);gap:.65rem;padding:.85rem 1.25rem;background:#f8fafc;border-bottom:1px solid #e2e8f0;"></div>
        <div id="modalBancoBody" style="padding:1.25rem;max-height:62vh;overflow-y:auto;font-size:.82rem;color:#475569;">Cargando...</div>
    </div>
</div>

{{-- Modal Auditoría Planilla --}}
<div id="modalAudit" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:flex-start;justify-content:center;padding-top:3vh;overflow-y:auto;">
    <div style="background:#fff;border-radius:18px;min-width:660px;max-width:920px;width:94%;box-shadow:0 25px 60px rgba(0,0,0,.22);">
        {{-- Header --}}
        <div style="background:linear-gradient(135deg,#4f46e5,#7c3aed);border-radius:18px 18px 0 0;padding:1.1rem 1.5rem;display:flex;align-items:center;gap:.85rem;">
            <span style="font-size:1.4rem;">🔍</span>
            <div style="flex:1;">
                <div style="color:#fff;font-weight:700;font-size:1rem;" id="auditTitulo">Auditoría Planilla</div>
                <div style="color:rgba(255,255,255,.7);font-size:.74rem;" id="auditSubtitulo">Comparativa SS cobrado vs pagado</div>
            </div>
            <button onclick="document.getElementById('modalAudit').style.display='none'" style="background:rgba(255,255,255,.15);border:none;color:#fff;border-radius:8px;width:32px;height:32px;cursor:pointer;font-size:1.1rem;display:flex;align-items:center;justify-content:center;">✕</button>
        </div>
        {{-- Body --}}
        <div id="auditBody" style="padding:1.25rem 1.5rem 1.5rem;">
            <div style="text-align:center;padding:2rem;color:#94a3b8;">Cargando auditoría…</div>
        </div>
    </div>
</div>

{{-- Modal Conciliación Seguridad Social (Desfases) --}}
<div id="modalConciliacion" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:flex-start;justify-content:center;padding-top:3vh;overflow-y:auto;">
    <div style="background:#fff;border-radius:18px;width:min(900px,96vw);box-shadow:0 25px 60px rgba(0,0,0,.22);margin-bottom:3vh;">
        <div style="background:linear-gradient(135deg,#0e7490,#0891b2);border-radius:18px 18px 0 0;padding:1rem 1.5rem;display:flex;align-items:center;justify-content:space-between;">
            <div>
                <div style="color:#fff;font-weight:800;font-size:1rem;">🔍 Conciliación y Desfases de Seguridad Social</div>
                <div style="color:rgba(255,255,255,.7);font-size:.74rem;margin-top:.15rem;">Análisis de la diferencia de recaudo contra planillas pagadas en el mes</div>
            </div>
            <button onclick="document.getElementById('modalConciliacion').style.display='none'" style="background:rgba(255,255,255,.15);border:none;color:#fff;border-radius:8px;width:32px;height:32px;cursor:pointer;font-size:1.1rem;">✕</button>
        </div>
        
        <div style="padding:1.25rem;max-height:75vh;overflow-y:auto;">
            {{-- Resumen de conciliación --}}
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.75rem;margin-bottom:1.25rem;background:#ecfeff;border:1px solid #a5f3fc;border-radius:12px;padding:.85rem 1rem;">
                <div style="text-align:center;">
                    <div style="font-size:.68rem;font-weight:600;color:#0e7490;text-transform:uppercase;">(+) Cobrado sin Planilla Pagada</div>
                    <div id="concilSinPlanillaTotal" style="font-size:1.25rem;font-weight:800;color:#0891b2;margin-top:.15rem;">—</div>
                    <div style="font-size:.62rem;color:#0e7490;margin-top:.1rem;">Dinero en caja sin pagar planilla al operador</div>
                </div>
                <div style="text-align:center;border-left:1px solid #a5f3fc;border-right:1px solid #a5f3fc;">
                    <div style="font-size:.68rem;font-weight:600;color:#9a3412;text-transform:uppercase;">(-) Planilla Pagada de Otros Meses / Retiro</div>
                    <div id="concilOtrosMesesTotal" style="font-size:1.25rem;font-weight:800;color:#c2410c;margin-top:.15rem;">—</div>
                    <div style="font-size:.62rem;color:#9a3412;margin-top:.1rem;">Planillas pagadas que se cobraron antes o son retiros</div>
                </div>
                <div style="text-align:center;">
                    <div style="font-size:.68rem;font-weight:600;color:#1e3a8a;text-transform:uppercase;">Diferencia Neta</div>
                    <div id="concilDiferenciaNeta" style="font-size:1.25rem;font-weight:800;color:#1d4ed8;margin-top:.15rem;">—</div>
                    <div style="font-size:.62rem;color:#1e3a8a;margin-top:.1rem;">Desfase neto entre Recaudo y Auditoría</div>
                </div>
            </div>
            
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;">
                {{-- Columna A: Cobrados sin Planilla --}}
                <div>
                    <div style="font-size:.8rem;font-weight:700;color:#1e293b;margin-bottom:.5rem;display:flex;justify-content:space-between;border-bottom:2px solid #e2e8f0;padding-bottom:.3rem;">
                        <span>(+) Cobrado sin Planilla Pagada</span>
                        <span id="badgeSinPlanillaCount" style="background:#dcfce7;color:#15803d;font-size:.7rem;border-radius:12px;padding:.05rem .4rem;">0</span>
                    </div>
                    <div id="concilSinPlanillaList" style="max-height:40vh;overflow-y:auto;font-size:.78rem;display:flex;flex-direction:column;gap:.4rem;padding-right:.25rem;">
                        Cargando…
                    </div>
                </div>
                
                {{-- Columna B: Pagados de Otros Meses o Retiros --}}
                <div>
                    <div style="font-size:.8rem;font-weight:700;color:#1e293b;margin-bottom:.5rem;display:flex;justify-content:space-between;border-bottom:2px solid #e2e8f0;padding-bottom:.3rem;">
                        <span>(-) Planilla Pagada de Otros Meses / Retiros</span>
                        <span id="badgeOtrosMesesCount" style="background:#ffedd5;color:#c2410c;font-size:.7rem;border-radius:12px;padding:.05rem .4rem;">0</span>
                    </div>
                    <div id="concilOtrosMesesList" style="max-height:40vh;overflow-y:auto;font-size:.78rem;display:flex;flex-direction:column;gap:.4rem;padding-right:.25rem;">
                        Cargando…
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Modal Recuperación Préstamos --}}
<div id="modalRecuperacionPrestamos" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:flex-start;justify-content:center;padding-top:3vh;overflow-y:auto;">
    <div style="background:#fff;border-radius:18px;width:min(900px,96vw);box-shadow:0 25px 60px rgba(0,0,0,.22);margin-bottom:3vh;">
        <div style="background:linear-gradient(135deg,#d97706,#f59e0b);border-radius:18px 18px 0 0;padding:1rem 1.5rem;display:flex;align-items:center;justify-content:space-between;">
            <div>
                <div style="color:#fff;font-weight:800;font-size:1rem;">💰 Recuperación de Préstamos — {{ $mesesEs[$mes] }} {{ $anio }}</div>
                <div style="color:rgba(255,255,255,.7);font-size:.74rem;margin-top:.15rem;">Detalle de los abonos recibidos sobre préstamos durante el mes actual</div>
            </div>
            <button onclick="document.getElementById('modalRecuperacionPrestamos').style.display='none'" style="background:rgba(255,255,255,.15);border:none;color:#fff;border-radius:8px;width:32px;height:32px;cursor:pointer;font-size:1.1rem;">✕</button>
        </div>
        
        <div style="padding:1.5rem;max-height:75vh;overflow-y:auto;">
            @if(count($abonosDetalleMes) === 0)
                <div style="text-align:center;padding:3rem;color:#94a3b8;">
                    <div style="font-size:2rem;margin-bottom:0.5rem;">💸</div>
                    No se registraron abonos ni recuperación de préstamos en este período.
                </div>
            @else
                <div style="border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);">
                    <table style="width:100%;border-collapse:collapse;text-align:left;font-size:.82rem;">
                        <thead>
                            <tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;color:#475569;font-weight:700;text-transform:uppercase;font-size:.7rem;letter-spacing:0.5px;">
                                <th style="padding:.75rem 1rem;">Fecha Abono</th>
                                <th style="padding:.75rem 1rem;">Cliente / Empresa</th>
                                <th style="padding:.75rem 1rem;text-align:center;">Factura</th>
                                <th style="padding:.75rem 1rem;text-align:center;">Forma Pago</th>
                                <th style="padding:.75rem 1rem;">Observación / Concepto</th>
                                <th style="padding:.75rem 1rem;text-align:right;">Valor</th>
                            </tr>
                        </thead>
                        <tbody style="color:#334155;">
                            @foreach($abonosDetalleMes as $abono)
                                <tr style="border-bottom:1px solid #f1f5f9;transition:background-color 0.15s;" onmouseover="this.style.backgroundColor='#f8fafc'" onmouseout="this.style.backgroundColor=''">
                                    <td style="padding:.75rem 1rem;font-family:monospace;white-space:nowrap;">{{ $abono->fecha_abono ? date('d/m/Y', strtotime($abono->fecha_abono)) : '—' }}</td>
                                    <td style="padding:.75rem 1rem;">
                                        <div style="font-weight:700;color:#1e293b;">{{ nombre_oracion($abono->nombre_cliente) }}</div>
                                    </td>
                                    <td style="padding:.75rem 1rem;text-align:center;white-space:nowrap;">
                                        @if($abono->numero_factura > 0)
                                            <span style="background:#f1f5f9;color:#475569;font-weight:600;padding:.2rem .5rem;border-radius:6px;font-size:.72rem;">#{{ $abono->numero_factura }}</span>
                                        @else
                                            <span style="color:#94a3b8;font-style:italic;">N/A</span>
                                        @endif
                                    </td>
                                    <td style="padding:.75rem 1rem;text-align:center;white-space:nowrap;">
                                        <span style="background:#fef3c7;color:#d97706;font-weight:700;padding:.2rem .5rem;border-radius:6px;font-size:.72rem;text-transform:uppercase;">{{ $abono->forma_pago }}</span>
                                    </td>
                                    <td style="padding:.75rem 1rem;color:#64748b;max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $abono->observacion }}">
                                        {{ $abono->observacion ?: '—' }}
                                    </td>
                                    <td style="padding:.75rem 1rem;text-align:right;font-family:monospace;font-weight:700;color:#16a34a;font-size:.9rem;">
                                        {{ $fmt($abono->valor) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr style="background:#f8fafc;border-top:2px solid #e2e8f0;font-weight:800;color:#1e293b;">
                                <td colspan="5" style="padding:.85rem 1rem;text-align:right;text-transform:uppercase;font-size:.7rem;letter-spacing:0.5px;">Total Recuperado</td>
                                <td style="padding:.85rem 1rem;text-align:right;font-family:monospace;color:#d97706;font-size:1rem;">
                                    {{ $fmt($abonosCobradosMes) }}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>

{{-- Modal Editar Consignacion --}}
<div id="modalEditarConsig" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:10000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:16px;width:min(500px,96vw);box-shadow:0 25px 60px rgba(0,0,0,.25);">
        <div style="background:linear-gradient(135deg,#d97706,#f59e0b);border-radius:16px 16px 0 0;padding:.9rem 1.25rem;display:flex;align-items:center;justify-content:space-between;">
            <div>
                <div style="color:#fff;font-weight:800;font-size:.95rem;">Editar Consignacion</div>
                <div id="editConsigSub" style="color:rgba(255,255,255,.75);font-size:.72rem;margin-top:.1rem;"></div>
            </div>
            <button onclick="cerrarEditarConsig()" style="background:rgba(255,255,255,.2);border:none;color:#fff;border-radius:8px;width:30px;height:30px;cursor:pointer;font-size:1rem;">X</button>
        </div>
        <div style="padding:1.2rem 1.5rem;">
            <input type="hidden" id="editConsigId">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.8rem;margin-bottom:.8rem;">
                <div>
                    <label style="font-size:.75rem;font-weight:600;color:#374151;display:block;margin-bottom:.3rem;">Fecha</label>
                    <input type="date" id="editConsigFecha" style="width:100%;padding:.5rem .7rem;border:1px solid #d1d5db;border-radius:8px;font-size:.85rem;box-sizing:border-box;">
                </div>
                <div>
                    <label style="font-size:.75rem;font-weight:600;color:#374151;display:block;margin-bottom:.3rem;">Valor ($)</label>
                    <input type="text" id="editConsigValor" placeholder="$ 0" oninput="fmtConsigValor(this)" style="width:100%;padding:.5rem .7rem;border:1px solid #d1d5db;border-radius:8px;font-size:.9rem;font-weight:700;font-family:monospace;color:#16a34a;box-sizing:border-box;">
                </div>
            </div>
            <div style="margin-bottom:.8rem;">
                <label style="font-size:.75rem;font-weight:600;color:#374151;display:block;margin-bottom:.3rem;">Banco / Cuenta</label>
                <select id="editConsigBanco" style="width:100%;padding:.5rem .7rem;border:1px solid #d1d5db;border-radius:8px;font-size:.85rem;box-sizing:border-box;">
                    @foreach($bancos as $b)
                        <option value="{{ $b->id }}">{{ $b->nombre }} ({{ $b->banco }})</option>
                    @endforeach
                </select>
            </div>
            <div style="margin-bottom:.8rem;">
                <label style="font-size:.75rem;font-weight:600;color:#374151;display:block;margin-bottom:.3rem;">Referencia</label>
                <input type="text" id="editConsigRef" maxlength="100" placeholder="Numero de referencia..." style="width:100%;padding:.5rem .7rem;border:1px solid #d1d5db;border-radius:8px;font-size:.85rem;box-sizing:border-box;">
            </div>
            @if(auth()->user()->hasRole(['admin', 'superadmin']))
            <div style="margin-bottom:.8rem;">
                <label style="font-size:.75rem;font-weight:600;color:#374151;display:block;margin-bottom:.3rem;">Estado de Validación</label>
                <select id="editConsigEstado" style="width:100%;padding:.5rem .7rem;border:1px solid #d1d5db;border-radius:8px;font-size:.85rem;box-sizing:border-box;font-weight:700;">
                    <option value="pendiente" style="color:#b45309;">🕐 Pendiente</option>
                    <option value="verificado" style="color:#15803d;">✅ Verificado</option>
                    <option value="no_aparece" style="color:#b91c1c;">❌ No aparece en banco</option>
                </select>
            </div>
            @else
            <input type="hidden" id="editConsigEstado" value="">
            @endif
            <div style="margin-bottom:.9rem;">
                <label style="font-size:.75rem;font-weight:600;color:#374151;display:block;margin-bottom:.3rem;">Observacion</label>
                <textarea id="editConsigObs" rows="2" maxlength="500" style="width:100%;padding:.5rem .7rem;border:1px solid #d1d5db;border-radius:8px;font-size:.85rem;box-sizing:border-box;resize:vertical;"></textarea>
            </div>
            <div style="margin-bottom:.9rem;">
                <div style="font-size:.75rem;font-weight:600;color:#374151;margin-bottom:.4rem;">Imagen de soporte</div>
                
                {{-- Contenedor cuando ya existe soporte --}}
                <div id="editConsigHasSoporte" style="display:none;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:.65rem .85rem;align-items:center;justify-content:space-between;margin-bottom:.5rem;">
                    <div style="display:flex;align-items:center;gap:.5rem;">
                        <span style="font-size:1.1rem;">📄</span>
                        <div>
                            <div style="font-size:.78rem;font-weight:700;color:#334155;">Soporte cargado</div>
                            <div style="font-size:.65rem;color:#64748b;">La consignación ya tiene un comprobante adjunto.</div>
                        </div>
                    </div>
                    <div style="display:flex;gap:.4rem;">
                        <a id="editConsigVerSoporteBtn" href="#" target="_blank" style="display:inline-flex;align-items:center;background:#7c3aed;color:#fff;border-radius:8px;padding:.35rem .75rem;font-size:.72rem;font-weight:700;text-decoration:none;">
                            👁️ Ver soporte
                        </a>
                        <button type="button" onclick="mostrarCambiarSoporte()" style="background:#475569;color:#fff;border:none;border-radius:8px;padding:.35rem .75rem;font-size:.72rem;font-weight:700;cursor:pointer;">
                            🔄 Cambiar soporte
                        </button>
                    </div>
                </div>

                {{-- Contenedor para subir nuevo soporte --}}
                <div id="editConsigUploadContainer">
                    <div id="editConsigDropZone"
                         onclick="document.getElementById('editConsigImagen').click()"
                         style="border:2px dashed #7c3aed;border-radius:10px;padding:.8rem;text-align:center;cursor:pointer;background:#faf5ff;transition:background .15s;position:relative;">
                        <span id="editConsigDropLabel" style="font-size:.75rem;color:#6d28d9;font-weight:600;">
                            📎 Clic, arrastra o pega (Ctrl+V) el comprobante
                        </span>
                        <input type="file" id="editConsigImagen" accept="image/*,.pdf" style="display:none;"
                               onchange="editConsigOnFile(this.files[0])">
                    </div>
                    <div id="editConsigPreview" style="display:none;margin-top:.5rem;position:relative;">
                        <img id="editConsigImgEl" src="" alt="preview"
                             style="max-width:100%;max-height:130px;border-radius:8px;border:1px solid #e2e8f0;object-fit:contain;display:block;">
                        <div id="editConsigPdfEl" style="display:none;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:.5rem;font-size:.75rem;color:#374151;font-weight:600;">
                            📄 <span id="editConsigPdfName"></span>
                        </div>
                        <button type="button" onclick="editConsigClearFile()"
                                style="position:absolute;top:4px;right:4px;background:#dc2626;color:#fff;border:none;border-radius:50%;width:20px;height:20px;cursor:pointer;font-size:.7rem;font-weight:800;">×</button>
                    </div>
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-top:.45rem;">
                        <span id="editConsigImgStatus" style="font-size:.72rem;color:#94a3b8;"></span>
                        <button onclick="subirImgConsig()" id="btnSubirImg"
                                style="background:#7c3aed;color:#fff;border:none;border-radius:8px;padding:.38rem .9rem;font-size:.78rem;font-weight:700;cursor:pointer;">
                            ↑ Subir imagen
                        </button>
                    </div>
                </div>
            </div>
            <div id="editConsigError" style="display:none;background:#fef2f2;border:1px solid #fca5a5;color:#dc2626;border-radius:8px;padding:.5rem .8rem;font-size:.78rem;margin-bottom:.8rem;"></div>
            <div style="display:flex;gap:.6rem;justify-content:flex-end;">
                <button onclick="cerrarEditarConsig()" style="background:#f1f5f9;color:#475569;border:none;border-radius:8px;padding:.48rem 1rem;font-size:.82rem;font-weight:600;cursor:pointer;">Cancelar</button>
                <button onclick="guardarConsig()" id="btnGuardarConsig" style="background:#16a34a;color:#fff;border:none;border-radius:8px;padding:.48rem 1.2rem;font-size:.82rem;font-weight:700;cursor:pointer;">Guardar</button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
// ── Datos de tendencia ──
const tendencia = @json($tendencia);
const anterior  = @json($anterior);
const ingresos  = @json($ingresos);
const esAdmin   = {{ $esAdmin ? 'true' : 'false' }};
const fmt = v => '$ '+Math.round(v).toLocaleString('es-CO');

// Gráfica tendencia
new Chart(document.getElementById('chartTendencia'), {
    type:'line',
    data:{
        labels: tendencia.map(t=>t.label),
        datasets:[
            {label:'Ingresos',data:tendencia.map(t=>t.ingresos),borderColor:'#3b82f6',backgroundColor:'rgba(59,130,246,.1)',tension:.4,fill:true},
            {label:'Egresos',data:tendencia.map(t=>t.egresos),borderColor:'#ef4444',backgroundColor:'rgba(239,68,68,.05)',tension:.4},
            {label:'Utilidad',data:tendencia.map(t=>t.utilidad),borderColor:'#10b981',backgroundColor:'rgba(16,185,129,.08)',tension:.4,fill:true},
        ]
    },
    options:{responsive:true,plugins:{legend:{labels:{font:{size:11}}}},scales:{y:{ticks:{callback:v=>'$'+Math.round(v/1000)+'k',font:{size:10}}}}}
});

// Gráfica comparación
new Chart(document.getElementById('chartComparacion'), {
    type:'bar',
    data:{
        labels:['Ingresos','Egresos','Utilidad'],
        datasets:[
            {label:'Mes actual',data:[tendencia.at(-1)?.ingresos,tendencia.at(-1)?.egresos,tendencia.at(-1)?.utilidad],backgroundColor:['#3b82f6','#ef4444','#10b981']},
            {label:'Mes anterior',data:[anterior.ingresos,anterior.egresos,anterior.utilidad],backgroundColor:['#93c5fd','#fca5a5','#6ee7b7']},
        ]
    },
    options:{responsive:true,plugins:{legend:{labels:{font:{size:11}}}},scales:{y:{ticks:{callback:v=>'$'+Math.round(v/1000)+'k',font:{size:10}}}}}
});

// Gráfica dona distribución
new Chart(document.getElementById('chartDona'), {
    type:'doughnut',
    data:{
        labels:['Planillas','Afiliaciones','Trámites'],
        datasets:[{data:[ingresos.planillas,ingresos.afiliaciones,ingresos.tramites],backgroundColor:['#3b82f6','#8b5cf6','#10b981'],borderWidth:2}]
    },
    options:{
        responsive:true,
        cutout:'60%',
        plugins:{
            legend:{
                position:'bottom',
                labels:{
                    font:{size:10},
                    boxWidth:10,
                    boxHeight:10,
                    padding:8,
                    usePointStyle:true,
                    pointStyle:'circle'
                }
            }
        },
        layout:{ padding:{ top:0, bottom:0 } }
    }
});

// ── Modal detalle día (con fetch) ──
let _diaCtx = {dia:0,mes:0,anio:0};
function verDetalleDia(dia,mes,anio) {
    _diaCtx = {dia,mes,anio};
    const meses=['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    document.getElementById('modalDiaTitulo').textContent = 'Detalle — '+String(dia).padStart(2,'0')+' '+meses[mes]+' '+anio;
    document.getElementById('diaFiltroTipo').value = 'todos';
    document.getElementById('modalDia').style.display = 'flex';
    cargarDetalleDia();
}
function aplicarFiltroDia() { cargarDetalleDia(); }
function cargarDetalleDia() {
    const {dia,mes,anio} = _diaCtx;
    const tipo = document.getElementById('diaFiltroTipo').value;
    document.getElementById('modalDiaBody').innerHTML = '<div style="text-align:center;padding:2rem;color:#94a3b8;">⏳ Cargando…</div>';
    fetch(`{{ route('admin.informes.financiero.detalle_dia') }}?dia=${dia}&mes=${mes}&anio=${anio}&tipo=${tipo}`)
        .then(r=>r.json()).then(data=>{
            const t = data.totales;
            const fmtN = v => '$ '+Math.round(v||0).toLocaleString('es-CO');
            // Cards resumen
            document.getElementById('modalDiaSummary').innerHTML = `
                <div style="background:#eff6ff;border-radius:9px;padding:.6rem;text-align:center;"><div style="font-weight:800;color:#2563eb;font-size:.92rem;">${fmtN(t.planillas)}</div><div style="font-size:.65rem;color:#64748b;">Planillas</div></div>
                <div style="background:#f5f3ff;border-radius:9px;padding:.6rem;text-align:center;"><div style="font-weight:800;color:#7c3aed;font-size:.92rem;">${fmtN(t.afiliaciones)}</div><div style="font-size:.65rem;color:#64748b;">Afiliaciones</div></div>
                <div style="background:#f0fdf4;border-radius:9px;padding:.6rem;text-align:center;"><div style="font-weight:800;color:#16a34a;font-size:.92rem;">${fmtN(t.tramites)}</div><div style="font-size:.65rem;color:#64748b;">Trámites</div></div>
                <div style="background:#ecfeff;border-radius:9px;padding:.6rem;text-align:center;"><div style="font-weight:800;color:#0e7490;font-size:.92rem;">${fmtN(t.ss||0)}</div><div style="font-size:.65rem;color:#64748b;">SS</div></div>
                <div style="background:#fef2f2;border-radius:9px;padding:.6rem;text-align:center;"><div style="font-weight:800;color:#dc2626;font-size:.92rem;">-${fmtN(t.gastos)}</div><div style="font-size:.65rem;color:#64748b;">Gastos</div></div>
                <div style="background:${t.utilidad>=0?'#f0fdf4':'#fef2f2'};border-radius:9px;padding:.6rem;text-align:center;"><div style="font-weight:800;color:${t.utilidad>=0?'#16a34a':'#dc2626'};font-size:.92rem;">${fmtN(t.utilidad)}</div><div style="font-size:.65rem;color:#64748b;">Utilidad</div></div>`;
            // Tabla facturas — agrupadas por numero_factura
            const tipoLabel = {'planilla':'Planilla','afiliacion':'Afiliación','otro_ingreso':'Trámite'};
            const tipoColor = {'planilla':'#2563eb','afiliacion':'#7c3aed','otro_ingreso':'#16a34a'};
            let html = '';
            if (data.facturas.length) {
                // Agrupar por numero_factura
                const grupos = {};
                data.facturas.forEach(f => {
                    const key = f.numero_factura || '__sin__';
                    if (!grupos[key]) grupos[key] = { numero_factura: f.numero_factura, tipo: f.tipo, nombres: [], ingreso: 0, ss: 0, total: 0 };
                    grupos[key].nombres.push(f.nombre);
                    grupos[key].ingreso += (f.ingreso || 0);
                    grupos[key].ss      += (f.total_ss || 0);
                    grupos[key].total   += (f.ingreso || 0) + (f.total_ss || 0);
                });
                const listaGrupos = Object.values(grupos);
                html += `<div style="font-size:.68rem;font-weight:700;text-transform:uppercase;color:#64748b;margin-bottom:.45rem;">📋 Facturas agrupadas (${listaGrupos.length} factura${listaGrupos.length!==1?'s':''})</div>`;
                html += `<div style="border-radius:10px;overflow:hidden;border:1px solid #e2e8f0;margin-bottom:1rem;">`;
                html += `<div style="display:grid;grid-template-columns:70px 1fr 90px 110px 100px 110px;gap:.3rem;padding:.4rem .75rem;background:#f8fafc;font-size:.65rem;font-weight:700;text-transform:uppercase;color:#64748b;border-bottom:2px solid #e2e8f0;">
                    <span>#Fact</span><span>Cliente / Empresa</span><span>Tipo</span>
                    <span style="text-align:right;color:#2563eb;">Ingreso</span>
                    <span style="text-align:right;color:#0e7490;">SS</span>
                    <span style="text-align:right;color:#0d2550;">Total</span></div>`;
                listaGrupos.forEach(g => {
                    const tl = tipoLabel[g.tipo] || g.tipo;
                    const tc = tipoColor[g.tipo] || '#64748b';
                    const nombreResumen = g.nombres.length === 1 ? g.nombres[0] : `${g.nombres[0]} <span style="color:#94a3b8;font-size:.68rem;">(+${g.nombres.length-1} más)</span>`;
                    html += `<div style="display:grid;grid-template-columns:70px 1fr 90px 110px 100px 110px;gap:.3rem;padding:.42rem .75rem;border-bottom:1px solid #f1f5f9;font-size:.78rem;align-items:center;cursor:pointer;"
                        title="${g.nombres.join(', ')}">
                        <span style="font-weight:700;color:#64748b;font-family:monospace;">#${g.numero_factura||'—'}</span>
                        <span style="color:#1e293b;font-weight:600;">${nombreResumen}</span>
                        <span style="background:${tc}18;color:${tc};border-radius:6px;padding:.1rem .4rem;font-size:.68rem;font-weight:700;">${tl}</span>
                        <span style="text-align:right;font-weight:700;color:#2563eb;font-family:monospace;">${fmtN(g.ingreso)}</span>
                        <span style="text-align:right;font-weight:700;color:#0e7490;font-family:monospace;">${g.ss>0?fmtN(g.ss):'—'}</span>
                        <span style="text-align:right;font-weight:800;color:#0d2550;font-family:monospace;">${fmtN(g.total)}</span>
                    </div>`;
                });
                // Fila de totales agrupados
                const totIng = listaGrupos.reduce((s,g)=>s+g.ingreso,0);
                const totSS  = listaGrupos.reduce((s,g)=>s+g.ss,0);
                const totTot = listaGrupos.reduce((s,g)=>s+g.total,0);
                html += `<div style="display:grid;grid-template-columns:70px 1fr 90px 110px 100px 110px;gap:.3rem;padding:.45rem .75rem;background:#f8fafc;font-size:.75rem;font-weight:700;border-top:2px solid #e2e8f0;">
                    <span></span><span style="color:#64748b;">TOTAL</span><span></span>
                    <span style="text-align:right;color:#2563eb;">${fmtN(totIng)}</span>
                    <span style="text-align:right;color:#0e7490;">${fmtN(totSS)}</span>
                    <span style="text-align:right;color:#0d2550;">${fmtN(totTot)}</span>
                </div>`;
                html += `</div>`;
            }
            if (data.gastos.length) {
                html += `<div style="font-size:.68rem;font-weight:700;text-transform:uppercase;color:#64748b;margin-bottom:.45rem;">💸 Gastos (${data.gastos.length})</div>`;
                html += `<div style="border-radius:10px;overflow:hidden;border:1px solid #fecaca;">`;
                data.gastos.forEach(g => {
                    html += `<div style="display:flex;justify-content:space-between;align-items:center;padding:.42rem .75rem;border-bottom:1px solid #fff1f2;font-size:.78rem;">
                        <span style="color:#334155;">${g.descripcion||g.tipo}</span>
                        <span style="font-weight:700;color:#dc2626;font-family:monospace;">-${fmtN(g.valor)}</span>
                    </div>`;
                });
                html += `</div>`;
            }
            if (!data.facturas.length && !data.gastos.length) html = '<div style="text-align:center;padding:2rem;color:#94a3b8;">Sin movimientos para este filtro</div>';
            document.getElementById('modalDiaBody').innerHTML = html;
        }).catch(()=>{ document.getElementById('modalDiaBody').innerHTML='<div style="color:#ef4444;padding:1.5rem;text-align:center;">Error al cargar detalle.</div>'; });
}

// ── Modal desglose de un día: efectivo/consignación, gastos y quién los reportó ──
// El HTML viene ya renderizado del servidor (partial admin.informes.partials.desglose_dia)
function verDesgloseDia(dia,mes,anio) {
    const meses=['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    document.getElementById('modalDesgloseDiaTitulo').textContent = '📋 Desglose — '+String(dia).padStart(2,'0')+' '+meses[mes]+' '+anio;
    document.getElementById('modalDesgloseDiaBody').innerHTML = '<div style="text-align:center;padding:1.5rem;color:#94a3b8;">⏳ Cargando…</div>';
    document.getElementById('modalDesgloseDia').style.display = 'flex';
    fetch(`{{ route('admin.informes.financiero.desglose_dia') }}?dia=${dia}&mes=${mes}&anio=${anio}`)
        .then(r=>r.text()).then(html=>{ document.getElementById('modalDesgloseDiaBody').innerHTML = html; })
        .catch(()=>{ document.getElementById('modalDesgloseDiaBody').innerHTML='<div style="color:#ef4444;padding:1.5rem;text-align:center;">Error al cargar desglose.</div>'; });
}

// ── Modal Recuperación Préstamos ──
function abrirModalRecuperacionPrestamos() {
    document.getElementById('modalRecuperacionPrestamos').style.display = 'flex';
}

// ── Modal Préstamos del mes ──
function verPrestamos() {
    document.getElementById('modalPrestamos').style.display = 'flex';
    document.getElementById('modalPrestamosBody').innerHTML = '<div style="text-align:center;padding:2rem;color:#94a3b8;">⏳ Cargando…</div>';
    fetch(`{{ route('admin.informes.financiero.prestamos_mes') }}?mes={{ $mes }}&anio={{ $anio }}`)
        .then(r=>r.json()).then(data=>{
            const fmtN = v => '$ '+Math.round(v||0).toLocaleString('es-CO');
            const t = data.totales;
            let html = `<div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:.6rem;margin-bottom:1.25rem;">
                <div style="background:#ede9fe;border-radius:12px;padding:.85rem .5rem;text-align:center;"><div style="font-size:1.05rem;font-weight:800;color:#7c3aed;">${t.cant}</div><div style="font-size:.68rem;color:#6d28d9;font-weight:600;">Préstamos</div></div>
                <div style="background:#eff6ff;border-radius:12px;padding:.85rem .5rem;text-align:center;"><div style="font-size:1.05rem;font-weight:800;color:#2563eb;">${fmtN(t.total_financiado)}</div><div style="font-size:.68rem;color:#1e40af;font-weight:600;">Prestado</div></div>
                <div style="background:#f0fdf4;border-radius:12px;padding:.85rem .5rem;text-align:center;"><div style="font-size:1.05rem;font-weight:800;color:#16a34a;">${fmtN(t.total_abonado)}</div><div style="font-size:.68rem;color:#166534;font-weight:600;">Abonado</div></div>
                <div style="background:#fef2f2;border-radius:12px;padding:.85rem .5rem;text-align:center;"><div style="font-size:1.05rem;font-weight:800;color:#dc2626;">${fmtN(t.saldo_pendiente)}</div><div style="font-size:.68rem;color:#991b1b;font-weight:600;">Saldo</div></div>
            </div>`;
            const renderLista = (lista, titulo) => {
                if (!lista.length) return '';
                let h = `<div style="font-size:.68rem;font-weight:700;text-transform:uppercase;color:#64748b;margin-bottom:.4rem;">${titulo} (${lista.length})</div>`;
                h += `<div style="border-radius:10px;overflow:hidden;border:1px solid #e2e8f0;margin-bottom:1rem;">`;
                h += `<div style="display:grid;grid-template-columns:1fr 90px 90px 90px 70px;gap:.3rem;padding:.38rem .75rem;background:#f8fafc;font-size:.64rem;font-weight:700;text-transform:uppercase;color:#64748b;border-bottom:2px solid #e2e8f0;">
                    <span>Nombre</span><span style="text-align:right;">Prestado</span><span style="text-align:right;">Abono</span><span style="text-align:right;">Saldo</span><span style="text-align:center;">Ver</span></div>`;
                lista.forEach(p => {
                    const url = p.factura_id ? `/admin/prestamos/${p.factura_id}` : `/admin/prestamos?tab=${p.es_empresa?'empresas':'individuales'}`;
                    const sub = p.cant_clientes ? ` <span style="color:#94a3b8;font-size:.65rem;">(${p.cant_clientes} clientes)</span>` : '';
                    h += `<div style="display:grid;grid-template-columns:1fr 90px 90px 90px 70px;gap:.3rem;padding:.42rem .75rem;border-bottom:1px solid #f1f5f9;font-size:.78rem;align-items:center;">
                        <span style="font-weight:600;color:#1e293b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${p.nombre}${sub}</span>
                        <span style="text-align:right;font-family:monospace;color:#4f46e5;">${fmtN(p.total_financiado)}</span>
                        <span style="text-align:right;font-family:monospace;color:#16a34a;">${fmtN(p.total_abonado)}</span>
                        <span style="text-align:right;font-family:monospace;font-weight:700;color:#dc2626;">${fmtN(p.saldo_pendiente)}</span>
                        <a href="${url}" style="display:block;text-align:center;background:#ede9fe;color:#7c3aed;border-radius:6px;padding:.2rem .5rem;font-size:.7rem;font-weight:700;text-decoration:none;">Ver</a>
                    </div>`;
                });
                return h + `</div>`;
            };
            html += renderLista(data.empresas, '🏢 Empresas');
            html += renderLista(data.individuales, '👤 Individuales');
            if (!data.empresas.length && !data.individuales.length) html += '<div style="text-align:center;padding:2rem;color:#94a3b8;">Sin préstamos registrados para este mes ✅</div>';
            document.getElementById('modalPrestamosBody').innerHTML = html;
        }).catch(()=>{ document.getElementById('modalPrestamosBody').innerHTML='<div style="color:#ef4444;padding:1.5rem;">Error al cargar.</div>'; });
}

// Modal movimientos banco — mejorado
const mesesEs = ['','enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
let _consigMap = {};
let _gastosMap = {};
let _bancoActualId = null, _bancoActualLabel = '';

function fmtFechaLargo(f) {
    if (!f) return '—';
    const p = f.substring(0,10).split('-');
    if (p.length < 3) return f;
    return parseInt(p[2])+'-'+(mesesEs[parseInt(p[1])]||p[1])+'-'+p[0];
}
function fmtHora(c) {
    if (!c) return null;
    const t = c.includes('T') ? c.split('T')[1] : (c.split(' ')[1]||'');
    if (!t) return null;
    const [h,m] = t.split(':');
    const hh = parseInt(h); const ampm = hh>=12?'pm':'am'; const h12 = hh%12||12;
    return h12+':'+m+' '+ampm;
}

function verMovimientosBanco(bancoId, label) {
    _bancoActualId = bancoId; _bancoActualLabel = label; _consigMap = {}; _gastosMap = {};
    const modal = document.getElementById('modalBanco');
    document.getElementById('modalBancoTitulo').textContent = '🏦 ' + label;
    document.getElementById('modalBancoSub').textContent = 'Consignaciones y salidas del mes';
    document.getElementById('modalBancoSummary').innerHTML = '';
    document.getElementById('modalBancoBody').innerHTML = '<div style="text-align:center;padding:2rem;color:#94a3b8;">Cargando...</div>';
    modal.style.display = 'flex';

    fetch(`{{ route('admin.informes.financiero.bancos') }}?banco_id=${bancoId}&mes={{ $mes }}&anio={{ $anio }}`)
        .then(r=>r.json()).then(data=>{
            const fmtN = v => '$ '+Math.round(Number(v)||0).toLocaleString('es-CO');
            const totEnt = data.entradas.reduce((s,e)=>s+Number(e.valor||0),0);
            const totSal = data.salidas.reduce((s,s2)=>s+Number(s2.valor||0),0);
            const saldo  = totEnt - totSal;

            document.getElementById('modalBancoSummary').innerHTML =
                '<div style="background:#f0fdf4;border-radius:10px;padding:.65rem .9rem;text-align:center;">'
                  +'<div style="font-size:.95rem;font-weight:800;color:#16a34a;">'+fmtN(totEnt)+'</div>'
                  +'<div style="font-size:.68rem;color:#15803d;margin-top:.1rem;">Entradas ('+data.entradas.length+')</div>'
                +'</div>'
                +'<div style="background:#fef2f2;border-radius:10px;padding:.65rem .9rem;text-align:center;">'
                  +'<div style="font-size:.95rem;font-weight:800;color:#dc2626;">'+fmtN(totSal)+'</div>'
                  +'<div style="font-size:.68rem;color:#b91c1c;margin-top:.1rem;">Salidas ('+data.salidas.length+')</div>'
                +'</div>'
                +'<div style="background:'+(saldo>=0?'#eff6ff':'#fff7ed')+';border-radius:10px;padding:.65rem .9rem;text-align:center;">'
                  +'<div style="font-size:.95rem;font-weight:800;color:'+(saldo>=0?'#2563eb':'#ea580c')+';">'+fmtN(saldo)+'</div>'
                  +'<div style="font-size:.68rem;color:'+(saldo>=0?'#1d4ed8':'#c2410c')+';margin-top:.1rem;">Neto del mes</div>'
                +'</div>';

            let html = '';

            // ── ENTRADAS ─────────────────────────────────────────────
            html += '<div style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#16a34a;letter-spacing:.04em;margin-bottom:.5rem;">Entradas ('+data.entradas.length+')</div>';
            if (!data.entradas.length) {
                html += '<div style="color:#94a3b8;font-size:.82rem;margin-bottom:1.25rem;padding:.5rem;">Sin entradas en este periodo.</div>';
            } else {
                html += '<div style="border-radius:10px;overflow:hidden;border:1px solid #bbf7d0;margin-bottom:1.25rem;">'
                      + '<div style="display:grid;grid-template-columns:110px 1fr 75px 95px 68px 90px 65px 32px;gap:.3rem;padding:.42rem .75rem;background:#f0fdf4;font-size:.63rem;font-weight:700;text-transform:uppercase;color:#15803d;border-bottom:2px solid #86efac;">'
                      + '<span>Fecha</span><span>Cliente / Empresa</span><span>Referencia</span>'
                      + '<span style="text-align:right;">Valor</span><span style="text-align:center;">Factura</span>'
                      + '<span style="text-align:center;">Estado</span><span style="text-align:center;">Soporte</span>'
                      + '<span style="text-align:center;">Ed.</span>'
                      + '</div>';

                data.entradas.forEach((e,i) => {
                    _consigMap[e.id] = e;
                    const bg      = i%2===0?'#fff':'#f9fef9';
                    const fechaStr= fmtFechaLargo(e.fecha);
                    const horaStr = fmtHora(e.created_at);
                    const nombre  = nombreOracion(e.nombre_cliente)||'—';
                    const icon    = (e.empresa_id&&e.empresa_id>0)?'🏢':'👤';
                    const refTxt  = e.referencia?'<span style="font-size:.74rem;color:#475569;">'+e.referencia+'</span>':'<span style="color:#cbd5e1;">—</span>';
                    const factTxt = e.numero_factura?'<span style="background:#dbeafe;color:#1e40af;border-radius:5px;padding:.1rem .35rem;font-size:.7rem;font-weight:700;">#'+e.numero_factura+'</span>':'<span style="color:#94a3b8;">—</span>';
                    const tipoLbl = (e.tipo&&e.tipo!=='cliente')?'<span style="font-size:.6rem;color:#94a3b8;display:block;">'+e.tipo+'</span>':'';
                    
                    let estadoLbl = '';
                    if (e.confirmado) {
                        estadoLbl = '<span style="background:#dcfce7;color:#15803d;border-radius:5px;padding:.15rem .35rem;font-size:.65rem;font-weight:700;white-space:nowrap;">✅ Verificado</span>';
                    } else if (e.no_aparece) {
                        estadoLbl = '<span style="background:#fee2e2;color:#b91c1c;border-radius:5px;padding:.15rem .35rem;font-size:.65rem;font-weight:700;white-space:nowrap;">❌ No aparece</span>';
                    } else {
                        estadoLbl = '<span style="background:#fef3c7;color:#b45309;border-radius:5px;padding:.15rem .35rem;font-size:.65rem;font-weight:700;white-space:nowrap;">🕐 Pendiente</span>';
                    }

                    let sopBtn = '<span style="color:#cbd5e1;font-size:.72rem;">Sin foto</span>';
                    if (e.imagen_path) {
                        const url = (e.imagen_path.startsWith('http') || e.imagen_path.startsWith('/storage')) ? e.imagen_path : '/storage/'+e.imagen_path;
                        sopBtn = '<button onclick="verImagenGasto(\''+url+'\')" style="display:inline-flex;align-items:center;background:#7c3aed;color:#fff;border-radius:7px;padding:.2rem .45rem;font-size:.68rem;font-weight:700;cursor:pointer;border:none;white-space:nowrap;">📷 Ver</button>';
                    }
                    html += '<div style="display:grid;grid-template-columns:110px 1fr 75px 95px 68px 90px 65px 32px;gap:.3rem;padding:.42rem .75rem;background:'+bg+';border-bottom:1px solid #f0fdf4;align-items:center;font-size:.77rem;">'
                        +'<div>'
                            +'<div style="font-weight:700;color:#0d2550;">'+fechaStr+'</div>'
                            +(horaStr?'<div style="font-size:.63rem;color:#6366f1;font-weight:600;">🕐 '+horaStr+'</div>':'')
                        +'</div>'
                        +'<div>'
                            +'<div style="font-weight:600;color:#1e293b;line-height:1.3;">'+icon+' '+nombre+'</div>'
                            +tipoLbl
                        +'</div>'
                        +'<div>'+refTxt+'</div>'
                        +'<div style="text-align:right;font-weight:800;color:#16a34a;font-family:monospace;white-space:nowrap;">'+fmtN(e.valor)+'</div>'
                        +'<div style="text-align:center;">'+factTxt+'</div>'
                        +'<div style="text-align:center;">'+estadoLbl+'</div>'
                        +'<div style="text-align:center;">'+sopBtn+'</div>'
                        {{-- Sin `informes.financiero_editar` (el caso del contable) no se
                             pinta el lápiz: el endpoint responde 403 igual, pero un botón
                             que siempre falla es peor que no tenerlo. --}}
                        +'<div style="text-align:center;">@can('informes.financiero_editar')<button onclick="abrirEditarConsig('+e.id+')" style="background:#f59e0b;border:none;color:#fff;border-radius:7px;width:26px;height:26px;cursor:pointer;font-size:.72rem;display:inline-flex;align-items:center;justify-content:center;" title="Editar">✏️</button>@endcan</div>'
                        +'</div>';
                });
                html += '</div>';
            }

            // ── SALIDAS ──────────────────────────────────────────────
            html += '<div style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#dc2626;letter-spacing:.04em;margin-bottom:.5rem;">Salidas ('+data.salidas.length+')</div>';
            if (!data.salidas.length) {
                html += '<div style="color:#94a3b8;font-size:.82rem;padding:.5rem;">Sin salidas en este periodo.</div>';
            } else {
                const cols = esAdmin ? '110px 1fr 90px 105px 65px 32px' : '110px 1fr 90px 105px 65px';
                html += '<div style="border-radius:10px;overflow:hidden;border:1px solid #fecaca;">'
                      + '<div style="display:grid;grid-template-columns:'+cols+';gap:.3rem;padding:.42rem .75rem;background:#fef2f2;font-size:.65rem;font-weight:700;text-transform:uppercase;color:#b91c1c;border-bottom:2px solid #fca5a5;">'
                      + '<span>Fecha</span><span>Descripción</span><span>Tipo</span><span style="text-align:right;">Valor</span><span style="text-align:center;">Soporte</span>'
                      + (esAdmin ? '<span style="text-align:center;">Ed.</span>' : '')
                      + '</div>';
                data.salidas.forEach((s,i) => {
                    _gastosMap[s.id] = s;
                    const bg      = i%2===0?'#fff':'#fff5f5';
                    const fechaStr= fmtFechaLargo(s.fecha);
                    const desc    = s.descripcion||s.pagado_a||s.tipo||'—';
                    const tipoLbl = s.tipo ? s.tipo.replace(/_/g, ' ').toUpperCase() : '—';
                    
                    let sopBtn = '<span style="color:#cbd5e1;font-size:.72rem;">Sin foto</span>';
                    if (s.imagen_path) {
                        const url = (s.imagen_path.startsWith('http') || s.imagen_path.startsWith('/storage')) ? s.imagen_path : '/storage/'+s.imagen_path;
                        sopBtn = '<button onclick="verImagenGasto(\''+url+'\')" style="display:inline-flex;align-items:center;background:#7c3aed;color:#fff;border-radius:7px;padding:.2rem .45rem;font-size:.68rem;font-weight:700;cursor:pointer;border:none;white-space:nowrap;">📷 Ver</button>';
                    }

                    html += '<div style="display:grid;grid-template-columns:'+cols+';gap:.3rem;padding:.45rem .75rem;background:'+bg+';border-bottom:1px solid #fff1f2;align-items:center;font-size:.78rem;">'
                        +'<div style="font-weight:700;color:#0d2550;">'+fechaStr+'</div>'
                        +'<div style="color:#334155;font-weight:600;">'+desc+'</div>'
                        +'<div style="color:#475569;font-size:.72rem;">'+tipoLbl+'</div>'
                        +'<div style="text-align:right;font-weight:800;color:#dc2626;font-family:monospace;white-space:nowrap;">'+fmtN(s.valor)+'</div>'
                        +'<div style="text-align:center;">'+sopBtn+'</div>'
                        + (esAdmin ? '<div style="text-align:center;"><button onclick="abrirEditarGasto('+s.id+')" style="background:#f59e0b;border:none;color:#fff;border-radius:7px;width:26px;height:26px;cursor:pointer;font-size:.72rem;display:inline-flex;align-items:center;justify-content:center;" title="Editar">✏️</button></div>' : '')
                        +'</div>';
                });
                html += '</div>';
            }

            document.getElementById('modalBancoBody').innerHTML = html;
        }).catch(()=>{
            document.getElementById('modalBancoBody').innerHTML = '<div style="color:#ef4444;padding:1.5rem;text-align:center;">Error al cargar movimientos.</div>';
        });
}

// ── Funciones modal editar consignacion ──────────────────────────────
// ── Formato moneda para el campo valor ────────────────────────────────
function fmtConsigValor(input) {
    let raw = input.value.replace(/[^0-9]/g, '');
    if (!raw) { input.value = ''; return; }
    input.value = '$ ' + parseInt(raw).toLocaleString('es-CO');
}
function editConsigGetValorRaw() {
    return parseInt((document.getElementById('editConsigValor').value || '').replace(/[^0-9]/g, '')) || 0;
}

// ── File helpers para drop zone ────────────────────────────────────────
let _editConsigFile = null;
let _editConsigPasteHandler = null;

function editConsigOnFile(file) {
    if (!file) return;
    _editConsigFile = file;
    const prev = document.getElementById('editConsigPreview');
    const img  = document.getElementById('editConsigImgEl');
    const pdf  = document.getElementById('editConsigPdfEl');
    prev.style.display = 'block';
    if (file.type === 'application/pdf') {
        img.style.display = 'none'; pdf.style.display = 'block';
        document.getElementById('editConsigPdfName').textContent = file.name;
    } else {
        pdf.style.display = 'none'; img.style.display = 'block';
        img.src = URL.createObjectURL(file);
    }
    document.getElementById('editConsigDropLabel').textContent = file.name;
}
function editConsigClearFile() {
    _editConsigFile = null;
    const fi = document.getElementById('editConsigImagen');
    if (fi) fi.value = '';
    document.getElementById('editConsigPreview').style.display = 'none';
    document.getElementById('editConsigImgEl').src = '';
    document.getElementById('editConsigDropLabel').textContent = '📎 Clic, arrastra o pega (Ctrl+V) el comprobante';
    document.getElementById('editConsigImgStatus').textContent = '';
}

function abrirEditarConsig(id) {
    const e = _consigMap[id]||{};
    document.getElementById('editConsigId').value    = id;
    document.getElementById('editConsigFecha').value = (e.fecha||'').substring(0,10);
    const vRaw = parseInt(e.valor)||0;
    document.getElementById('editConsigValor').value = vRaw > 0 ? '$ '+vRaw.toLocaleString('es-CO') : '';
    document.getElementById('editConsigBanco').value = e.banco_cuenta_id || '';
    document.getElementById('editConsigRef').value   = e.referencia||'';
    
    const elEstado = document.getElementById('editConsigEstado');
    if (elEstado) {
        let estVal = 'pendiente';
        if (e.confirmado) {
            estVal = 'verificado';
        } else if (e.no_aparece) {
            estVal = 'no_aparece';
        }
        elEstado.value = estVal;
    }

    document.getElementById('editConsigObs').value   = e.observacion||'';
    document.getElementById('editConsigError').style.display = 'none';
    editConsigClearFile();
    document.getElementById('editConsigSub').textContent = 'Consignacion #'+id;

    // Controlar visibilidad del soporte existente
    if (e.imagen_path) {
        document.getElementById('editConsigHasSoporte').style.display = 'flex';
        document.getElementById('editConsigUploadContainer').style.display = 'none';
        const url = (e.imagen_path.startsWith('http') || e.imagen_path.startsWith('/storage')) ? e.imagen_path : '/storage/' + e.imagen_path;
        document.getElementById('editConsigVerSoporteBtn').href = url;
    } else {
        document.getElementById('editConsigHasSoporte').style.display = 'none';
        document.getElementById('editConsigUploadContainer').style.display = 'block';
    }

    // Drag & drop en drop zone
    const dz = document.getElementById('editConsigDropZone');
    dz.ondragover  = (ev) => { ev.preventDefault(); dz.style.background='#ede9fe'; };
    dz.ondragleave = ()   => { dz.style.background='#faf5ff'; };
    dz.ondrop      = (ev) => { ev.preventDefault(); dz.style.background='#faf5ff'; const f=ev.dataTransfer?.files?.[0]; if(f) editConsigOnFile(f); };

    // Paste Ctrl+V
    if (_editConsigPasteHandler) document.removeEventListener('paste', _editConsigPasteHandler);
    _editConsigPasteHandler = (ev) => {
        const item = [...(ev.clipboardData?.items || [])].find(i => i.type.startsWith('image/'));
        if (item) editConsigOnFile(item.getAsFile());
    };
    document.addEventListener('paste', _editConsigPasteHandler);

    document.getElementById('modalEditarConsig').style.display = 'flex';
}
function cerrarEditarConsig() {
    document.getElementById('modalEditarConsig').style.display='none';
    if (_editConsigPasteHandler) { document.removeEventListener('paste', _editConsigPasteHandler); _editConsigPasteHandler = null; }
    editConsigClearFile();
}
function mostrarCambiarSoporte() {
    document.getElementById('editConsigHasSoporte').style.display = 'none';
    document.getElementById('editConsigUploadContainer').style.display = 'block';
}
function guardarConsig() {
    const id=document.getElementById('editConsigId').value;
    const fecha=document.getElementById('editConsigFecha').value;
    const valor=document.getElementById('editConsigValor').value;
    const bancoCuentaId=document.getElementById('editConsigBanco').value;
    const ref=document.getElementById('editConsigRef').value;
    const obs=document.getElementById('editConsigObs').value;
    const estado=document.getElementById('editConsigEstado')?.value || '';
    const err=document.getElementById('editConsigError');
    const btn=document.getElementById('btnGuardarConsig');
    const valorNum = editConsigGetValorRaw();
    if(!fecha||valorNum<1){err.textContent='Fecha y valor obligatorios.';err.style.display='block';return;}
    err.style.display='none';btn.disabled=true;btn.textContent='Guardando...';
    fetch('{{ route("admin.informes.financiero.consignacion.editar","_X_") }}'.replace('_X_',id),{
        method:'PATCH',
        headers:{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]')?.content||'','Accept':'application/json'},
        body:JSON.stringify({banco_cuenta_id:bancoCuentaId,fecha,valor:valorNum,referencia:ref,observacion:obs,estado})
    }).then(r=>r.json()).then(d=>{
        btn.disabled=false;btn.textContent='Guardar';
        if(d.ok){
            const oldBancoId = _consigMap[id]?.banco_cuenta_id;
            const newBancoId = d.consig.banco_cuenta_id;
            cerrarEditarConsig();
            if (oldBancoId && oldBancoId != newBancoId) {
                // Si cambió de banco, recargamos para actualizar balances
                window.location.reload();
            } else {
                if(_bancoActualId)verMovimientosBanco(_bancoActualId,_bancoActualLabel);
            }
        }
        else{err.textContent=d.mensaje||'Error.';err.style.display='block';}
    }).catch(()=>{btn.disabled=false;btn.textContent='Guardar';err.textContent='Error de conexion.';err.style.display='block';});
}
function subirImgConsig() {
    const id=document.getElementById('editConsigId').value;
    const file=_editConsigFile||document.getElementById('editConsigImagen').files[0];
    const stat=document.getElementById('editConsigImgStatus');
    const btn=document.getElementById('btnSubirImg');
    if(!file){stat.textContent='Selecciona o pega una imagen primero.';return;}
    stat.textContent='Subiendo...';btn.disabled=true;
    const fd=new FormData();fd.append('imagen',file);fd.append('_token',document.querySelector('meta[name="csrf-token"]')?.content||'');
    fetch('{{ route("admin.informes.financiero.consignacion.imagen","_X_") }}'.replace('_X_',id),{method:'POST',body:fd,headers:{'Accept':'application/json'}})
    .then(r=>r.json()).then(d=>{
        btn.disabled=false;
        if(d.ok){
            stat.textContent='OK';stat.style.color='#16a34a';
            document.getElementById('editConsigImagen').value='';
            if(_consigMap[id]) {
                _consigMap[id].imagen_path = d.url;
            }
            // Mostrar de nuevo el soporte cargado
            document.getElementById('editConsigHasSoporte').style.display = 'flex';
            document.getElementById('editConsigUploadContainer').style.display = 'none';
            document.getElementById('editConsigVerSoporteBtn').href = d.url;
        }else{
            stat.textContent='Error: '+(d.message||'');stat.style.color='#dc2626';
        }
    })
    .catch(()=>{btn.disabled=false;stat.textContent='Error.';stat.style.color='#dc2626';});
}
document.getElementById('modalEditarConsig').addEventListener('click',function(ev){if(ev.target===this)cerrarEditarConsig();});

// Cerrar modales al clic fuera
['modalDia','modalDesgloseDia','modalBanco','modalAudit','modalPrestamos','modalEfectivo','modalGastos','modalLightbox'].forEach(id=>{
    document.getElementById(id).addEventListener('click',function(e){
        if(e.target===this) this.style.display='none';
    });
});

// ── Modal Efectivo ──────────────────────────────────────────────────
function verMovimientosEfectivo() {
    const modal = document.getElementById('modalEfectivo');
    document.getElementById('modalEfectivoSummary').innerHTML = '';
    document.getElementById('modalEfectivoBody').innerHTML = '<div style="text-align:center;padding:2rem;color:#94a3b8;">⏳ Cargando movimientos en efectivo…</div>';
    modal.style.display = 'flex';

    fetch(`{{ route('admin.informes.financiero.efectivo') }}?mes={{ $mes }}&anio={{ $anio }}`)
        .then(r => r.json())
        .then(data => {
            const fmtN = v => '$ ' + Math.round(Number(v) || 0).toLocaleString('es-CO');

            // ── Cards resumen ──
            const subFacturas  = data.total_facturas  || 0;
            const subAnticipos = data.total_anticipos || 0;
            document.getElementById('modalEfectivoSummary').innerHTML = `
                <div style="background:#f0fdf4;border-radius:10px;padding:.75rem;text-align:center;border:1px solid #bbf7d0;">
                    <div style="font-weight:800;color:#16a34a;font-size:1.1rem;">${fmtN(data.total_entradas)}</div>
                    <div style="font-size:.68rem;color:#15803d;font-weight:600;margin-top:.2rem;">↑ Ingresos Efectivo</div>
                    <div style="font-size:.62rem;color:#94a3b8;">${data.entradas.length} factura(s)${subAnticipos > 0 ? ' + ' + (data.anticipos?.length||0) + ' anticipo(s)' : ''}</div>
                </div>
                <div style="background:#fef2f2;border-radius:10px;padding:.75rem;text-align:center;border:1px solid #fecaca;">
                    <div style="font-weight:800;color:#dc2626;font-size:1.1rem;">${fmtN(data.total_salidas)}</div>
                    <div style="font-size:.68rem;color:#b91c1c;font-weight:600;margin-top:.2rem;">↓ Salidas Efectivo</div>
                    <div style="font-size:.62rem;color:#94a3b8;">${data.salidas.length} gasto(s)</div>
                </div>
                <div style="background:${data.saldo_efectivo >= 0 ? '#f0fdf4' : '#fef2f2'};border-radius:10px;padding:.75rem;text-align:center;border:1px solid ${data.saldo_efectivo >= 0 ? '#bbf7d0' : '#fecaca'};">
                    <div style="font-weight:800;color:${data.saldo_efectivo >= 0 ? '#16a34a' : '#dc2626'};font-size:1.1rem;">${fmtN(data.saldo_efectivo)}</div>
                    <div style="font-size:.68rem;color:${data.saldo_efectivo >= 0 ? '#15803d' : '#b91c1c'};font-weight:600;margin-top:.2rem;">Neto en Efectivo</div>
                    <div style="font-size:.62rem;color:#94a3b8;">Ingresos − Salidas</div>
                </div>`;

            let html = '';

            // ── Sección: Efectivo por Asesor del mes ──
            if (data.por_asesor && data.por_asesor.length > 0) {
                const totalConsignado = data.total_consignaciones || 0;
                const totalSaldoCaja  = data.por_asesor.reduce((s, c) => s + (c.saldo_caja || 0), 0);
                html += `<div style="font-size:.7rem;font-weight:700;text-transform:uppercase;color:#15803d;margin-bottom:.5rem;">👤 Efectivo por Asesor — ${data.por_asesor.length} asesor(es)</div>`;
                html += `<div style="border-radius:10px;overflow:hidden;border:1px solid #bbf7d0;margin-bottom:1.25rem;">`;
                html += `<div style="display:grid;grid-template-columns:1fr 90px 90px 90px 90px 100px;gap:.3rem;padding:.4rem .75rem;background:#f0fdf4;font-size:.65rem;font-weight:700;text-transform:uppercase;color:#15803d;border-bottom:2px solid #bbf7d0;">
                    <span>Asesor</span>
                    <span style="text-align:right;">Facturas</span>
                    <span style="text-align:right;">Anticipos</span>
                    <span style="text-align:right;">Gastos</span>
                    <span style="text-align:right;">Consignado</span>
                    <span style="text-align:right;">Saldo Caja</span></div>`;
                data.por_asesor.forEach(c => {
                    const isOk = c.saldo_caja >= 0;
                    html += `<div style="display:grid;grid-template-columns:1fr 90px 90px 90px 90px 100px;gap:.3rem;padding:.42rem .75rem;border-bottom:1px solid #f0fdf4;font-size:.76rem;align-items:center;background:${isOk ? '#fff' : '#fef2f2'};">
                        <span style="font-weight:700;color:#1e293b;">👤 ${c.asesor_nombre || '—'}</span>
                        <span style="text-align:right;color:#16a34a;font-family:monospace;">${fmtN(c.ingresos_ef)}</span>
                        <span style="text-align:right;color:#0ea5e9;font-family:monospace;">${c.anticipos_ef > 0 ? fmtN(c.anticipos_ef) : '—'}</span>
                        <span style="text-align:right;color:#dc2626;font-family:monospace;">${c.gastos_ef > 0 ? '-'+fmtN(c.gastos_ef) : '—'}</span>
                        <span style="text-align:right;color:#d97706;font-family:monospace;">${c.consignaciones_ef > 0 ? '-'+fmtN(c.consignaciones_ef) : '—'}</span>
                        <span style="text-align:right;font-weight:800;color:${isOk ? '#15803d' : '#dc2626'};font-family:monospace;">${fmtN(c.saldo_caja)}</span>
                    </div>`;
                });
                html += `<div style="display:grid;grid-template-columns:1fr 90px 90px 90px 90px 100px;gap:.3rem;padding:.45rem .75rem;background:#f0fdf4;font-size:.75rem;font-weight:700;border-top:2px solid #bbf7d0;">
                    <span style="color:#15803d;">TOTAL ASESORES</span>
                    <span style="text-align:right;color:#16a34a;font-family:monospace;">${fmtN(data.total_entradas)}</span>
                    <span></span>
                    <span style="text-align:right;color:#dc2626;font-family:monospace;">${data.total_salidas > 0 ? '-'+fmtN(data.total_salidas) : '—'}</span>
                    <span style="text-align:right;color:#d97706;font-family:monospace;">${totalConsignado > 0 ? '-'+fmtN(totalConsignado) : '—'}</span>
                    <span style="text-align:right;color:#15803d;font-family:monospace;">${fmtN(totalSaldoCaja)}</span></div>`;
                html += `</div>`;
            } else {
                html += `<div style="background:#f0fdf4;border-radius:10px;padding:.7rem 1rem;font-size:.78rem;color:#15803d;margin-bottom:1rem;">Sin movimientos en efectivo en este mes.</div>`;
            }

            // ── Sección: Facturas cobradas en efectivo ──
            html += `<div style="font-size:.7rem;font-weight:700;text-transform:uppercase;color:#64748b;margin-bottom:.5rem;">📋 Facturas cobradas en efectivo (${data.entradas.length})</div>`;
            if (data.entradas.length) {
                html += `<div style="border-radius:10px;overflow:hidden;border:1px solid #e2e8f0;margin-bottom:1.25rem;">`;
                html += `<div style="display:grid;grid-template-columns:90px 70px 1fr 130px 100px 110px;gap:.3rem;padding:.4rem .75rem;background:#f8fafc;font-size:.64rem;font-weight:700;text-transform:uppercase;color:#64748b;border-bottom:2px solid #e2e8f0;">
                    <span>Fecha</span><span>#Fact</span><span>Cliente / Empresa</span><span>Asesor</span><span style="text-align:right;">Forma pago</span><span style="text-align:right;color:#16a34a;">Efectivo</span></div>`;
                data.entradas.forEach(e => {
                    const fp = e.forma_pago === 'mixto' ? '⚡ Mixto' : '💵 Efectivo';
                    html += `<div style="display:grid;grid-template-columns:90px 70px 1fr 130px 100px 110px;gap:.3rem;padding:.4rem .75rem;border-bottom:1px solid #f1f5f9;font-size:.76rem;align-items:center;">
                        <span style="color:#64748b;">${e.fecha || '—'}</span>
                        <span style="font-weight:700;color:#475569;font-family:monospace;">#${e.numero_factura || '—'}</span>
                        <span style="color:#1e293b;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="${e.nombre_cliente}">${nombreOracion(e.nombre_cliente) || '—'}</span>
                        <span style="font-size:.68rem;color:#7c3aed;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="${e.usuario_nombre}">👤 ${e.usuario_nombre || '—'}</span>
                        <span style="text-align:right;font-size:.68rem;color:#64748b;">${fp}</span>
                        <span style="text-align:right;font-weight:800;color:#16a34a;font-family:monospace;">${fmtN(e.valor)}</span>
                    </div>`;
                });
                html += `<div style="display:grid;grid-template-columns:90px 70px 1fr 130px 100px 110px;gap:.3rem;padding:.42rem .75rem;background:#f8fafc;font-size:.75rem;font-weight:700;border-top:2px solid #e2e8f0;">
                    <span></span><span></span><span style="color:#64748b;">TOTAL</span><span></span><span></span>
                    <span style="text-align:right;color:#16a34a;font-family:monospace;">${fmtN(data.total_facturas)}</span></div>`;
                html += `</div>`;
            } else {
                html += `<div style="background:#f8fafc;border-radius:10px;padding:.7rem 1rem;font-size:.78rem;color:#94a3b8;margin-bottom:1rem;">Sin facturas en efectivo para este mes.</div>`;
            }

            // ── Sección: Anticipos cobrados en efectivo ──
            if (data.anticipos && data.anticipos.length > 0) {
                html += `<div style="font-size:.7rem;font-weight:700;text-transform:uppercase;color:#d97706;margin-bottom:.5rem;margin-top:.25rem;">💰 Anticipos cobrados en efectivo (${data.anticipos.length})</div>`;
                html += `<div style="border-radius:10px;overflow:hidden;border:1px solid #fde68a;margin-bottom:1.25rem;">`;
                html += `<div style="display:grid;grid-template-columns:90px 1fr 120px 110px 110px;gap:.3rem;padding:.4rem .75rem;background:#fffbeb;font-size:.64rem;font-weight:700;text-transform:uppercase;color:#92400e;border-bottom:2px solid #fde68a;">
                    <span>Fecha</span><span>Cliente / Empresa</span><span>Asesor</span><span style="text-align:right;">Forma</span><span style="text-align:right;color:#d97706;">Valor</span></div>`;
                data.anticipos.forEach(a => {
                    const formaIcon = a.forma_pago === 'nequi' ? '📱 Nequi' : '💵 Efectivo';
                    const estadoBadge = a.estado === 'disponible' ? '🟢' : a.estado === 'parcial' ? '🟡' : '📋';
                    html += `<div style="display:grid;grid-template-columns:90px 1fr 120px 110px 110px;gap:.3rem;padding:.4rem .75rem;border-bottom:1px solid #fef3c7;font-size:.76rem;align-items:center;">
                        <span style="color:#64748b;">${a.fecha || '—'}</span>
                        <span style="color:#1e293b;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="${a.nombre_cliente}">${estadoBadge} ${nombreOracion(a.nombre_cliente) || '—'}</span>
                        <span style="font-size:.68rem;color:#7c3aed;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">👤 ${a.usuario_nombre || '—'}</span>
                        <span style="text-align:right;font-size:.68rem;color:#64748b;">${formaIcon}</span>
                        <span style="text-align:right;font-weight:800;color:#d97706;font-family:monospace;">${fmtN(a.valor)}</span>
                    </div>`;
                });
                html += `<div style="display:flex;justify-content:space-between;padding:.42rem .75rem;background:#fffbeb;font-size:.75rem;font-weight:700;border-top:2px solid #fde68a;">
                    <span style="color:#92400e;">TOTAL ANTICIPOS</span>
                    <span style="color:#d97706;font-family:monospace;">${fmtN(data.total_anticipos)}</span></div>`;
                html += `</div>`;
            }

            // ── Sección: Gastos en efectivo ──
            html += `<div style="font-size:.7rem;font-weight:700;text-transform:uppercase;color:#64748b;margin-bottom:.5rem;">💸 Gastos en efectivo (${data.salidas.length})</div>`;
            if (data.salidas.length) {
                html += `<div style="border-radius:10px;overflow:hidden;border:1px solid #fecaca;">`;
                data.salidas.forEach(g => {
                    html += `<div style="display:flex;justify-content:space-between;align-items:center;padding:.42rem .75rem;border-bottom:1px solid #fff1f2;font-size:.76rem;">
                        <div><span style="color:#475569;">${g.descripcion || g.tipo}</span>${g.pagado_a ? `<span style="font-size:.65rem;color:#94a3b8;margin-left:.4rem;">→ ${g.pagado_a}</span>` : ''}</div>
                        <span style="font-weight:700;color:#dc2626;font-family:monospace;">-${fmtN(g.valor)}</span>
                    </div>`;
                });
                html += `<div style="display:flex;justify-content:space-between;align-items:center;padding:.42rem .75rem;background:#fef2f2;font-size:.75rem;font-weight:700;border-top:2px solid #fecaca;">
                    <span style="color:#b91c1c;">TOTAL SALIDAS</span>
                    <span style="color:#dc2626;font-family:monospace;">-${fmtN(data.total_salidas)}</span></div>`;
                html += `</div>`;
            } else {
                html += `<div style="background:#f8fafc;border-radius:10px;padding:.7rem 1rem;font-size:.78rem;color:#94a3b8;">Sin gastos en efectivo para este mes.</div>`;
            }

            document.getElementById('modalEfectivoBody').innerHTML = html;
        })
        .catch(() => {
            document.getElementById('modalEfectivoBody').innerHTML = '<div style="color:#ef4444;padding:1.5rem;text-align:center;">Error al cargar movimientos en efectivo.</div>';
        });
}

// ── Cargar KPI préstamos al init ──
(function() {
    fetch(`{{ route('admin.informes.financiero.prestamos_mes') }}?mes={{ $mes }}&anio={{ $anio }}`)
        .then(r=>r.json()).then(data=>{
            const fmtN = v => '$ '+Math.round(v||0).toLocaleString('es-CO');
            const t = data.totales;
            document.getElementById('kpi-prestamos-total').textContent = fmtN(t.total_financiado);
            document.getElementById('kpi-prestamos-abonado').textContent = fmtN(t.total_abonado);
            document.getElementById('kpi-prestamos-pendiente').textContent = fmtN(t.saldo_pendiente);
            document.getElementById('kpi-prestamos-cant').textContent = t.cant+' préstamo(s)';
        }).catch(()=>{
            const el = document.getElementById('kpi-prestamos-cant');
            if (el) el.textContent = 'Error al cargar';
        });
})();

// ── Ordenar Egresos SS ──────────────────────────────────────────────
let egresosSort = { campo: 'valor', asc: false }; // default: valor desc
function sortEgresos(campo) {
    const list = document.getElementById('egresosSSList');
    if (!list) return;
    // Alternar dirección si mismo campo
    if (egresosSort.campo === campo) {
        egresosSort.asc = !egresosSort.asc;
    } else {
        egresosSort.campo = campo;
        egresosSort.asc = campo === 'fecha'; // fecha: asc por defecto, valor: desc
    }
    // Resaltar botón activo
    ['sortFecha','sortValor'].forEach(id => {
        const btn = document.getElementById(id);
        if (!btn) return;
        btn.style.background = 'rgba(255,255,255,.15)';
        btn.style.borderColor = 'rgba(255,255,255,.3)';
    });
    const activeId = campo === 'fecha' ? 'sortFecha' : 'sortValor';
    const activeBtn = document.getElementById(activeId);
    if (activeBtn) {
        activeBtn.style.background = 'rgba(255,255,255,.35)';
        activeBtn.style.borderColor = 'rgba(255,255,255,.8)';
        activeBtn.textContent = (campo === 'fecha' ? '📅 Fecha' : '💰 Valor')
            + (egresosSort.asc ? ' ↑' : ' ↓');
    }
    // Ordenar filas
    const rows = Array.from(list.querySelectorAll('.egreso-ss-row'));
    rows.sort((a, b) => {
        let va = a.dataset[campo] ?? '';
        let vb = b.dataset[campo] ?? '';
        if (campo === 'valor') { va = parseFloat(va) || 0; vb = parseFloat(vb) || 0; }
        if (va < vb) return egresosSort.asc ? -1 : 1;
        if (va > vb) return egresosSort.asc ? 1 : -1;
        return 0;
    });
    rows.forEach(r => list.appendChild(r));
}

// ── Auditoría de número de planilla ──
function auditarPlanilla(numPlanilla, descripcion) {
    const m = document.getElementById('modalAudit');
    document.getElementById('auditTitulo').textContent = 'Auditoría Planilla ' + numPlanilla;
    document.getElementById('auditSubtitulo').textContent = descripcion || 'SS cobrado a clientes vs pago registrado';
    document.getElementById('auditBody').innerHTML = '<div style="text-align:center;padding:2.5rem;color:#94a3b8;"><div style="font-size:1.6rem;margin-bottom:.5rem;">⏳</div>Consultando datos…</div>';
    m.style.display = 'flex';

    fetch(`{{ route('admin.informes.financiero.auditar_planilla') }}?numero_planilla=${encodeURIComponent(numPlanilla)}`)
        .then(r => r.json())
        .then(data => {
            if (data.error) { document.getElementById('auditBody').innerHTML = `<div style="color:#ef4444;padding:1.5rem;text-align:center;">${data.error}</div>`; return; }

            const fmtN = v => '$ ' + Math.round(v || 0).toLocaleString('es-CO');
            const dif  = data.diferencia;
            const difColor  = dif >= 0 ? '#10b981' : '#ef4444';
            const difBg     = dif >= 0 ? '#f0fdf4' : '#fef2f2';
            const difLabel  = dif >= 0 ? '✅ A favor (exceso cobrado)' : '⚠️ Déficit (cobrado menos de lo pagado)';

            let html = '';

            // ── Alerta pago duplicado ──────────────────────────────────
            if (data.es_duplicado) {
                html += `
                <div style="background:#fef2f2;border:2px solid #fca5a5;border-radius:12px;padding:.85rem 1rem;margin-bottom:1rem;">
                    <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.65rem;">
                        <span style="font-size:1.3rem;">🚨</span>
                        <div>
                            <div style="font-size:.88rem;font-weight:800;color:#dc2626;">PAGO DUPLICADO — ${data.cant_gastos} registros encontrados</div>
                            <div style="font-size:.72rem;color:#b91c1c;">La planilla ${data.numero_planilla} tiene más de un gasto registrado. Revisar posible pago doble.</div>
                        </div>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:.35rem;">`;
                (data.gastos_detalle || []).forEach((g, i) => {
                    const fecha = g.fecha ? new Date(g.fecha).toLocaleDateString('es-CO') : '—';
                    html += `
                        <div style="background:#fff;border-radius:8px;padding:.45rem .75rem;display:flex;justify-content:space-between;align-items:center;border-left:3px solid #ef4444;">
                            <div>
                                <span style="font-size:.75rem;font-weight:700;color:#dc2626;">#${i+1}</span>
                                <span style="font-size:.75rem;color:#475569;margin-left:.5rem;">${fecha}</span>
                                <span style="font-size:.72rem;color:#94a3b8;margin-left:.5rem;">${g.forma_pago || ''}</span>
                            </div>
                            <span style="font-weight:800;color:#dc2626;font-size:.85rem;">${fmtN(g.valor)}</span>
                        </div>`;
                });
                html += `</div></div>`;
            }

            // ── Resumen tarjetas ───────────────────────────────────────
            // total_ss_facturas = facturas con numero_factura > 0 (= valor columna tabla)
            // total_ss_retiros  = facturas con numero_factura = 0 (retiros)
            // total_mora        = mora de todas las facturas de la planilla
            // total_ss_todos    = suma completa (usado para calcular diferencia vs gasto)
            const ssTodos = data.total_ss_todos || 0;
            const ssRet   = data.total_ss_retiros || 0;
            const totalMora = data.total_mora || 0;

            html += `
            <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:.75rem;margin-bottom:1.25rem;">
                <div style="background:#ede9fe;border-radius:12px;padding:.85rem;text-align:center;">
                    <div style="font-size:1rem;font-weight:800;color:#7c3aed;">${fmtN(data.total_ss_facturas)}</div>
                    <div style="font-size:.68rem;color:#6d28d9;font-weight:600;margin-top:.2rem;">SS Cobrado (facturas)</div>
                    <div style="font-size:.63rem;color:#94a3b8;margin-top:.1rem;">= columna tabla · fact. regulares</div>
                </div>
                <div style="background:${ssRet > 0 ? '#fff7ed' : '#f8fafc'};border-radius:12px;padding:.85rem;text-align:center;${ssRet > 0 ? 'border:1px solid #fed7aa;' : ''}">
                    <div style="font-size:1rem;font-weight:800;color:${ssRet > 0 ? '#c2410c' : '#94a3b8'};">${ssRet > 0 ? fmtN(ssRet) : '$ 0'}</div>
                    <div style="font-size:.68rem;color:${ssRet > 0 ? '#c2410c' : '#64748b'};font-weight:600;margin-top:.2rem;">SS Retiros</div>
                    <div style="font-size:.63rem;color:#94a3b8;margin-top:.1rem;">fact. número_factura = 0</div>
                </div>
                <div style="background:${totalMora > 0 ? '#fffbeb' : '#f8fafc'};border-radius:12px;padding:.85rem;text-align:center;${totalMora > 0 ? 'border:1px solid #fde68a;' : ''}">
                    <div style="font-size:1rem;font-weight:800;color:${totalMora > 0 ? '#d97706' : '#94a3b8'};">${totalMora > 0 ? fmtN(totalMora) : '$ 0'}</div>
                    <div style="font-size:.68rem;color:${totalMora > 0 ? '#b45309' : '#64748b'};font-weight:600;margin-top:.2rem;">Mora Recogida</div>
                    <div style="font-size:.63rem;color:#94a3b8;margin-top:.1rem;">Recargo por pago tardío</div>
                </div>
                <div style="background:${data.es_duplicado ? '#fef2f2' : '#fef3c7'};border-radius:12px;padding:.85rem;text-align:center;${data.es_duplicado ? 'border:2px solid #fca5a5;' : ''}">
                    <div style="font-size:1rem;font-weight:800;color:${data.es_duplicado ? '#dc2626' : '#d97706'};">${fmtN(data.gasto_valor)}</div>
                    <div style="font-size:.68rem;color:${data.es_duplicado ? '#b91c1c' : '#b45309'};font-weight:600;margin-top:.2rem;">
                        Pagado ${data.cant_gastos > 1 ? '('+data.cant_gastos+' reg. ⚠️)' : '(gasto)'}
                    </div>
                    <div style="font-size:.63rem;color:#94a3b8;margin-top:.1rem;">${data.gasto ? new Date(data.gasto.fecha).toLocaleDateString('es-CO') : '—'}</div>
                </div>
                <div style="background:${difBg};border-radius:12px;padding:.85rem;text-align:center;">
                    <div style="font-size:1rem;font-weight:800;color:${difColor};">${fmtN(dif)}</div>
                    <div style="font-size:.68rem;color:${difColor};font-weight:600;margin-top:.2rem;">Diferencia</div>
                    <div style="font-size:.63rem;color:${difColor};margin-top:.1rem;">${difLabel}</div>
                </div>
            </div>
            ${ssRet > 0 || totalMora > 0 ? `<div style="background:#f0fdf4;border-left:3px solid #10b981;border-radius:0 8px 8px 0;padding:.5rem .85rem;font-size:.74rem;color:#15803d;margin-bottom:.85rem;">
                ℹ️ <strong>SS Total cobrado (facturas + retiros + mora): ${fmtN(ssTodos)}</strong> — Desglose: regulares <em>(${fmtN(data.total_ss_facturas)})</em> + retiros <em>(${fmtN(ssRet)})</em> + mora <em>(${fmtN(totalMora)})</em>.
            </div>` : ''}`;

            // Desglose por componente SS
            html += `
            <div style="background:#f8fafc;border-radius:10px;padding:.75rem 1rem;margin-bottom:1rem;display:flex;gap:1rem;flex-wrap:wrap;">
                <div style="flex:1;min-width:90px;text-align:center;">
                    <div style="font-size:.78rem;font-weight:700;color:#0ea5e9;">EPS</div>
                    <div style="font-size:.9rem;font-weight:700;color:#0284c7;">${fmtN(data.total_eps)}</div>
                </div>
                <div style="flex:1;min-width:90px;text-align:center;">
                    <div style="font-size:.78rem;font-weight:700;color:#10b981;">Pensión AFP</div>
                    <div style="font-size:.9rem;font-weight:700;color:#059669;">${fmtN(data.total_afp)}</div>
                </div>
                <div style="flex:1;min-width:90px;text-align:center;">
                    <div style="font-size:.78rem;font-weight:700;color:#8b5cf6;">ARL</div>
                    <div style="font-size:.9rem;font-weight:700;color:#7c3aed;">${fmtN(data.total_arl)}</div>
                </div>
                <div style="flex:1;min-width:90px;text-align:center;">
                    <div style="font-size:.78rem;font-weight:700;color:#f59e0b;">Caja Comp.</div>
                    <div style="font-size:.9rem;font-weight:700;color:#d97706;">${fmtN(data.total_caja)}</div>
                </div>
            </div>`;

            // Tabla de empleados
            if (!data.planos || data.planos.length === 0) {
                html += '<div style="text-align:center;color:#94a3b8;padding:1.5rem;font-size:.84rem;">No se encontraron registros de planos para esta planilla.</div>';
            } else {
                html += `
                <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;color:#64748b;margin-bottom:.5rem;">Detalle por empleado — ${data.planos.length} registro(s)</div>
                <div style="border-radius:10px;overflow:hidden;border:1px solid #e2e8f0;">
                    <div style="display:grid;grid-template-columns:30px 1fr 60px 70px 70px 70px 70px 80px;gap:.3rem;padding:.45rem .75rem;background:#f8fafc;font-size:.65rem;font-weight:700;text-transform:uppercase;color:#64748b;border-bottom:2px solid #e2e8f0;">
                        <span>#</span>
                        <span>Empleado</span>
                        <span style="text-align:right;">Días</span>
                        <span style="text-align:right;color:#0ea5e9;">EPS</span>
                        <span style="text-align:right;color:#10b981;">AFP</span>
                        <span style="text-align:right;color:#8b5cf6;">ARL</span>
                        <span style="text-align:right;color:#f59e0b;">Caja</span>
                        <span style="text-align:right;color:#7c3aed;">Total SS</span>
                    </div>
                    <div style="max-height:280px;overflow-y:auto;">`;

                let lastEmpresa = null;
                data.planos.forEach((p, i) => {
                    const bg = i % 2 === 0 ? '#fff' : '#fafafa';
                    const tipoIcon = p.tipo_reg === 'retiro' ? '🔴' : '🟢';
                    const empresa  = p.empresa_nombre || '—';
                    const nit      = p.empresa_nit ? ` <span style="color:#94a3b8;font-size:.63rem;">(${p.empresa_nit})</span>` : '';

                    // Separador de empresa cuando cambia
                    if (empresa !== lastEmpresa) {
                        html += `
                        <div style="background:#e0f2fe;padding:.3rem .75rem;font-size:.68rem;font-weight:700;color:#0c4a6e;border-bottom:1px solid #bae6fd;">
                            🏢 ${empresa}${nit}
                        </div>`;
                        lastEmpresa = empresa;
                    }

                    html += `
                    <div style="display:grid;grid-template-columns:30px 1fr 60px 70px 70px 70px 70px 80px;gap:.3rem;padding:.42rem .75rem;background:${bg};font-size:.73rem;border-bottom:1px solid #f1f5f9;align-items:center;">
                        <span style="color:#94a3b8;font-size:.65rem;">${i+1}</span>
                        <div>
                            <div style="font-weight:600;color:#1e293b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px;" title="${p.nombre_completo}">${tipoIcon} ${nombreOracion(p.nombre_completo) || p.no_identifi}</div>
                            <div style="font-size:.65rem;color:#94a3b8;">${p.no_identifi}${p.numero_factura ? ' · Fact. #'+p.numero_factura : ''}</div>
                        </div>
                        <span style="text-align:right;color:#64748b;">${p.num_dias ?? '—'}</span>
                        <span style="text-align:right;color:#0ea5e9;font-weight:600;">${p.v_eps != null ? fmtN(p.v_eps) : '—'}</span>
                        <span style="text-align:right;color:#10b981;font-weight:600;">${p.v_afp != null ? fmtN(p.v_afp) : '—'}</span>
                        <span style="text-align:right;color:#8b5cf6;font-weight:600;">${p.v_arl != null ? fmtN(p.v_arl) : '—'}</span>
                        <span style="text-align:right;color:#f59e0b;font-weight:600;">${p.v_caja != null ? fmtN(p.v_caja) : '—'}</span>
                        <span style="text-align:right;color:#7c3aed;font-weight:700;">${p.total_ss != null ? fmtN(p.total_ss) : '—'}</span>
                    </div>`;
                });

                // Fila total
                html += `
                    <div style="display:grid;grid-template-columns:30px 1fr 60px 70px 70px 70px 70px 80px;gap:.3rem;padding:.5rem .75rem;background:#f5f3ff;font-size:.73rem;border-top:2px solid #e2e8f0;font-weight:700;">
                        <span></span>
                        <span style="color:#7c3aed;">TOTAL</span>
                        <span></span>
                        <span style="text-align:right;color:#0ea5e9;">${fmtN(data.total_eps)}</span>
                        <span style="text-align:right;color:#10b981;">${fmtN(data.total_afp)}</span>
                        <span style="text-align:right;color:#8b5cf6;">${fmtN(data.total_arl)}</span>
                        <span style="text-align:right;color:#f59e0b;">${fmtN(data.total_caja)}</span>
                        <span style="text-align:right;color:#7c3aed;font-size:.82rem;">${fmtN(data.total_ss_facturas)}</span>
                    </div>
                    </div>
                </div>`;
            }

            // Nota si no hay gasto
            if (!data.gasto) {
                html += '<div style="margin-top:.85rem;background:#fff7ed;border-left:3px solid #f59e0b;padding:.65rem 1rem;border-radius:0 8px 8px 0;font-size:.78rem;color:#92400e;">⚠️ No se encontró un gasto <code>pago_planilla</code> asociado a esta planilla. El valor pagado puede estar en otro registro.</div>';
            }

            document.getElementById('auditBody').innerHTML = html;
        })
        .catch(() => {
            document.getElementById('auditBody').innerHTML = '<div style="color:#ef4444;padding:1.5rem;text-align:center;">Error al cargar la auditoría. Intente de nuevo.</div>';
        });
}

function abrirConciliacionSS() {
    const modal = document.getElementById('modalConciliacion');
    modal.style.display = 'flex';
    
    const listSin = document.getElementById('concilSinPlanillaList');
    const listOtr = document.getElementById('concilOtrosMesesList');
    const totSin  = document.getElementById('concilSinPlanillaTotal');
    const totOtr  = document.getElementById('concilOtrosMesesTotal');
    const totNet  = document.getElementById('concilDiferenciaNeta');
    const badgeSin = document.getElementById('badgeSinPlanillaCount');
    const badgeOtr = document.getElementById('badgeOtrosMesesCount');
    
    listSin.innerHTML = '<div style="color:#94a3b8;padding:1rem;text-align:center;">Cargando conciliación...</div>';
    listOtr.innerHTML = '<div style="color:#94a3b8;padding:1rem;text-align:center;">Cargando conciliación...</div>';
    
    const fmt = val => '$ ' + Math.round(Number(val || 0)).toLocaleString('es-CO');

    fetch(`{{ route('admin.informes.financiero.conciliacion_ss') }}?mes={{ $mes }}&anio={{ $anio }}`)
        .then(res => res.json())
        .then(data => {
            if (!data.exito) {
                listSin.innerHTML = 'Error al cargar los datos';
                listOtr.innerHTML = 'Error al cargar los datos';
                return;
            }
            
            totSin.textContent = fmt(data.total_sin_planilla);
            totOtr.textContent = fmt(data.total_otros_meses);
            
            const diff = data.diferencia_neta;
            totNet.textContent = (diff >= 0 ? '+' : '') + fmt(diff);
            totNet.style.color = diff >= 0 ? '#15803d' : '#dc2626';
            
            badgeSin.textContent = data.sin_planilla.length;
            badgeOtr.textContent = data.otros_meses.length;
            
            if (data.sin_planilla.length === 0) {
                listSin.innerHTML = '<div style="color:#94a3b8;padding:1.5rem;text-align:center;background:#f8fafc;border-radius:10px;">Todas las facturas cobradas este mes ya tienen planilla pagada.</div>';
            } else {
                listSin.innerHTML = data.sin_planilla.map(f => `
                    <div style="padding:.5rem .75rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;display:flex;justify-content:space-between;align-items:start;margin-bottom:.35rem;">
                        <div>
                            <div style="font-weight:700;color:#1e293b;">${f.cliente_nombre}</div>
                            <div style="font-size:.68rem;color:#64748b;margin-top:.15rem;">C.C. ${f.cedula} · Factura #${f.numero_factura} (${f.mes}/${f.anio})</div>
                            <span style="font-size:.62rem;background:#dbeafe;color:#1e40af;padding:.05rem .35rem;border-radius:6px;font-weight:700;display:inline-block;margin-top:.2rem;">${f.estado.toUpperCase()}</span>
                        </div>
                        <div style="font-weight:800;color:#15803d;font-size:.85rem;white-space:nowrap;">${fmt(f.total_ss)}</div>
                    </div>
                `).join('');
            }
            
            if (data.otros_meses.length === 0) {
                listOtr.innerHTML = '<div style="color:#94a3b8;padding:1.5rem;text-align:center;background:#f8fafc;border-radius:10px;">No hay planillas pagadas en el mes asociadas a facturas de otros periodos o retiros.</div>';
            } else {
                listOtr.innerHTML = data.otros_meses.map(f => {
                    const descPeriodo = f.numero_factura == 0 
                        ? '<span style="font-size:.62rem;background:#fee2e2;color:#991b1b;padding:.05rem .35rem;border-radius:6px;font-weight:700;display:inline-block;margin-top:.2rem;">RETIRO ASUMIDO</span>'
                        : `<span style="font-size:.62rem;background:#ffedd5;color:#9a3412;padding:.05rem .35rem;border-radius:6px;font-weight:700;display:inline-block;margin-top:.2rem;">PAGADO EN ${fmtFecha(f.fecha_pago)} (Per. ${f.mes}/${f.anio})</span>`;
                        
                    return `
                        <div style="padding:.5rem .75rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;display:flex;justify-content:space-between;align-items:start;margin-bottom:.35rem;">
                            <div>
                                <div style="font-weight:700;color:#1e293b;">${f.cliente_nombre}</div>
                                <div style="font-size:.68rem;color:#64748b;margin-top:.15rem;">C.C. ${f.cedula} · Factura #${f.numero_factura == 0 ? 'Retiro' : f.numero_factura} · Planilla PILA #${f.numero_planilla}</div>
                                ${descPeriodo}
                            </div>
                            <div style="font-weight:800;color:#c2410c;font-size:.85rem;white-space:nowrap;">${fmt(f.total_ss)}</div>
                        </div>
                    `;
                }).join('');
            }
        })
        .catch(err => {
            console.error("Error en conciliacion SS:", err);
            listSin.innerHTML = '<div style="color:#ef4444;padding:1rem;text-align:center;">Error de conexión: ' + err.message + '</div>';
            listOtr.innerHTML = '<div style="color:#ef4444;padding:1rem;text-align:center;">Error de conexión: ' + err.message + '</div>';
        });
}


// ── Modal Gastos Operativos ─────────────────────────────────────────
let _gastosData = [];

function abrirGastosDetalle() {
    const modal = document.getElementById('modalGastos');
    document.getElementById('modalGastosResumen').innerHTML = '';
    document.getElementById('modalGastosBody').innerHTML = '<div style="text-align:center;padding:2.5rem;color:#94a3b8;"><div style="font-size:2rem;margin-bottom:.5rem;">⏳</div>Cargando gastos…</div>';
    modal.style.display = 'flex';

    fetch(`{{ route('admin.informes.financiero.gastos_detalle') }}?mes={{ $mes }}&anio={{ $anio }}`)
        .then(r => r.json())
        .then(data => {
            if (!data.ok) throw new Error('Error de servidor');
            _gastosData = data.gastos;
            renderGastosModal(data);
        })
        .catch(() => {
            document.getElementById('modalGastosBody').innerHTML = '<div style="color:#ef4444;padding:1.5rem;text-align:center;">Error al cargar los gastos. Intente de nuevo.</div>';
        });
}

const _TIPOS_GASTO_LABEL = {
    'nomina':'💼 Nómina','gasto_fijo':'🏢 Gasto Fijo','gasto_variable':'⚡ Variable',
    'comision_asesor':'👤 Comisión','banco_banco':'💱 Banco→Banco',
    'otro':'📌 Otro','pago_planilla':'📋 Planilla'
};

function renderGastosModal(data) {
    const fmtN = v => '$ ' + Math.round(Number(v) || 0).toLocaleString('es-CO');

    // ── Resumen KPIs agrupados por tipo ──
    const agrupados = {};
    data.gastos.forEach(g => {
        const k = g.tipo || 'otro';
        if (!agrupados[k]) agrupados[k] = { total: 0, count: 0, items: [] };
        agrupados[k].total += Number(g.valor) || 0;
        agrupados[k].count++;
        agrupados[k].items.push(g);
    });

    const colores = {
        'nomina': '#8b5cf6',       // Lavanda/Morado
        'gasto_fijo': '#3b82f6',    // Azul
        'gasto_variable': '#f59e0b',// Ámbar/Naranja
        'comision_asesor': '#0ea5e9',// Celeste
        'banco_banco': '#10b981',    // Esmeralda/Verde
        'pago_planilla': '#14b8a6',  // Teal
        'otro': '#64748b'            // Pizarra
    };

    let resHtml = `
        <div style="background:#fef2f2;border-radius:12px;padding:.85rem;text-align:center;border:1px solid #fecaca;box-shadow:0 2px 4px rgba(220,38,38,0.04);display:flex;flex-direction:column;justify-content:center;">
            <div style="font-weight:900;color:#dc2626;font-size:1.2rem;white-space:nowrap;">${fmtN(data.total)}</div>
            <div style="font-size:.7rem;color:#b91c1c;font-weight:700;margin-top:.15rem;text-transform:uppercase;letter-spacing:.03em;">Total Egresos</div>
            <div style="font-size:.62rem;color:#94a3b8;margin-top:.1rem;">${data.count} registros</div>
        </div>`;

    Object.entries(agrupados).forEach(([tipo, info]) => {
        const lbl = _TIPOS_GASTO_LABEL[tipo] || tipo;
        const col = colores[tipo] || '#64748b';
        resHtml += `
        <div style="background:#fff;border-radius:12px;padding:.85rem;text-align:center;border:1px solid #e2e8f0;box-shadow:0 2px 4px rgba(0,0,0,0.02);display:flex;flex-direction:column;justify-content:center;">
            <div style="font-weight:800;color:${col};font-size:1.02rem;white-space:nowrap;">${fmtN(info.total)}</div>
            <div style="font-size:.7rem;color:#475569;font-weight:700;margin-top:.15rem;">${lbl}</div>
            <div style="font-size:.62rem;color:#94a3b8;margin-top:.1rem;">${info.count} reg.</div>
        </div>`;
    });
    document.getElementById('modalGastosResumen').innerHTML = resHtml;

    // ── Tabla gastos ──
    if (!data.gastos.length) {
        document.getElementById('modalGastosBody').innerHTML = '<div style="text-align:center;padding:2rem;color:#94a3b8;">Sin gastos operativos en este período.</div>';
        return;
    }

    let html = `<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:.8rem;min-width:760px;">
        <thead><tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0;">
            <th style="padding:.6rem .8rem;text-align:left;font-size:.67rem;text-transform:uppercase;color:#64748b;width:85px;">Fecha</th>
            <th style="padding:.6rem .8rem;text-align:left;font-size:.67rem;text-transform:uppercase;color:#64748b;">Descripción del Gasto</th>
            <th style="padding:.6rem .8rem;text-align:left;font-size:.67rem;text-transform:uppercase;color:#64748b;width:125px;">Pagado a</th>
            <th style="padding:.6rem .8rem;text-align:left;font-size:.67rem;text-transform:uppercase;color:#64748b;width:105px;">Reportado por</th>
            <th style="padding:.6rem .8rem;text-align:left;font-size:.67rem;text-transform:uppercase;color:#64748b;width:115px;">Banco / Forma</th>
            <th style="padding:.6rem .8rem;text-align:right;font-size:.67rem;text-transform:uppercase;color:#ef4444;width:105px;">Valor</th>
            <th style="padding:.6rem .8rem;text-align:center;font-size:.67rem;text-transform:uppercase;color:#64748b;width:75px;">Acc.</th>
        </tr></thead>
        <tbody>`;

    // ── Renderizado agrupado por tipo ──
    Object.entries(agrupados).forEach(([tipo, info]) => {
        const lbl = _TIPOS_GASTO_LABEL[tipo] || tipo;
        const col = colores[tipo] || '#64748b';

        // Fila divisoria de cabecera de grupo
        html += `
        <tr style="background:#f1f5f9;border-top:2px solid #e2e8f0;border-bottom:1.5px solid #cbd5e1;">
            <td colspan="5" style="padding:.65rem .8rem;font-size:.78rem;font-weight:800;color:#1e293b;">
                ${lbl} <span style="font-weight:600;color:#64748b;font-size:.7rem;margin-left:.35rem;">(${info.count} egresos en este grupo)</span>
            </td>
            <td style="padding:.65rem .8rem;text-align:right;font-weight:800;color:${col};font-family:monospace;font-size:.82rem;white-space:nowrap;">
                Subtotal: ${fmtN(info.total)}
            </td>
            <td></td>
        </tr>`;

        // Filas del grupo
        info.items.forEach(g => {
            const fechaStr = g.fecha ? g.fecha.substring(0,10).split('-').reverse().join('/') : '—';
            // Corresponde a este mes pero se pagó en otro: en bancos sale en el mes del pago
            const otroMes  = g.pagado_otro_mes
                ? `<div title="Se pagó en otro mes: en bancos y efectivo aparece en ${mesLargo(g.fecha)}" style="font-size:.62rem;font-weight:700;color:#b45309;background:#fef3c7;border-radius:5px;padding:.05rem .3rem;margin-top:.15rem;display:inline-block;">Pagado en ${mesCorto(g.fecha)}</div>`
                : '';
            const banco    = g.banco_nombre ? `🏦 ${g.banco_nombre}` : (g.forma_pago === 'efectivo' ? '💵 Efectivo' : (g.forma_pago || '—'));
            const tieneImg = !!g.imagen_url;
            const desc     = (g.descripcion || '—').replace(/</g,'&lt;');
            const obs      = (g.observacion || '').replace(/</g,'&lt;');
            const pagadoA  = (g.pagado_a    || '—').replace(/</g,'&lt;');
            const editDesc = (g.descripcion || '').replace(/"/g,'&quot;');
            const editObs  = (g.observacion || '').replace(/"/g,'&quot;');

            // Emoji de soporte interactivo si tiene imagen
            const imgBadge = tieneImg
                ? `<span onclick="verImagenGasto('${g.imagen_url}'); event.stopPropagation();" title="Clic para ver soporte" style="cursor:pointer;font-size:.9rem;margin-left:.35rem;display:inline-block;transition:transform 0.1s;" onmouseover="this.style.transform='scale(1.2)'" onmouseout="this.style.transform='scale(1)'">🖼️</span>`
                : '';

            html += `
            <tr id="gasto-row-${g.id}" style="border-bottom:1px solid #f1f5f9;transition:background .12s;"
                onmouseover="this.style.background='#fffcfc'" onmouseout="this.style.background=''">
                <td style="padding:.55rem .8rem;color:#64748b;font-size:.78rem;white-space:nowrap;">${fechaStr}${otroMes}</td>
                <td style="padding:.55rem .8rem;">
                    <div style="font-size:.73rem;color:#374151;display:flex;align-items:center;">
                        <span style="font-weight:600;">${desc}</span>${imgBadge}
                    </div>
                    ${obs ? `<div style="font-size:.65rem;color:#9ca3af;font-style:italic;margin-top:.1rem;">${obs}</div>` : ''}
                </td>
                <td style="padding:.55rem .8rem;font-size:.76rem;color:#374151;">${pagadoA}</td>
                <td style="padding:.55rem .8rem;"><span style="font-size:.73rem;color:#7c3aed;font-weight:600;">👤 ${g.usuario_nombre || '—'}</span></td>
                <td style="padding:.55rem .8rem;font-size:.73rem;color:#1e40af;">${banco}</td>
                <td style="padding:.55rem .8rem;text-align:right;font-weight:800;color:#dc2626;font-family:monospace;white-space:nowrap;">${fmtN(g.valor)}</td>
                <td style="padding:.55rem .8rem;text-align:center;">
                    <div style="display:flex;gap:.3rem;justify-content:center;">
                        ${tieneImg
                            ? `<button onclick="verImagenGasto('${g.imagen_url}')" title="Ver soporte" style="background:#dbeafe;border:none;border-radius:6px;padding:.25rem .45rem;cursor:pointer;font-size:.8rem;" onmouseover="this.style.background='#bfdbfe'" onmouseout="this.style.background='#dbeafe'">🖼️</button>`
                            : `<button style="background:#f3f4f6;border:none;border-radius:6px;padding:.25rem .45rem;cursor:default;font-size:.8rem;opacity:.35;" title="Sin imagen">🖼️</button>`}
                        <button onclick="editarGasto(${g.id})" title="Editar" style="background:#fef3c7;border:none;border-radius:6px;padding:.25rem .45rem;cursor:pointer;font-size:.8rem;" onmouseover="this.style.background='#fde68a'" onmouseout="this.style.background='#fef3c7'">✏️</button>
                    </div>
                </td>
            </tr>
            <tr id="gasto-edit-${g.id}" style="display:none;background:#fffbeb;">
                <td colspan="7" style="padding:.7rem 1rem;border-bottom:2px solid #fde68a;">
                    <form onsubmit="guardarGasto(event,${g.id})" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-end;">
                        <div style="flex:0 0 105px;">
                            <label style="font-size:.65rem;font-weight:700;color:#92400e;display:block;margin-bottom:.15rem;">Fecha</label>
                            <input type="date" id="edit-fecha-${g.id}" value="${g.fecha ? g.fecha.substring(0,10) : ''}" style="width:100%;padding:.3rem .5rem;border:1px solid #fcd34d;border-radius:6px;font-size:.78rem;">
                        </div>
                        <div style="flex:0 0 150px;">
                            <label style="font-size:.65rem;font-weight:700;color:#92400e;display:block;margin-bottom:.15rem;" title="Para la utilidad. La fecha sigue siendo la del pago (la que cuadra con el banco).">Mes al que corresponde</label>
                            <select id="edit-periodo-${g.id}" style="width:100%;padding:.3rem .5rem;border:1px solid #fcd34d;border-radius:6px;font-size:.78rem;">${opcionesPeriodoGasto(g)}</select>
                        </div>
                        <div style="flex:2;min-width:150px;">
                            <label style="font-size:.65rem;font-weight:700;color:#92400e;display:block;margin-bottom:.15rem;">Descripción</label>
                            <input type="text" id="edit-desc-${g.id}" value="${editDesc}" style="width:100%;padding:.3rem .5rem;border:1px solid #fcd34d;border-radius:6px;font-size:.78rem;">
                        </div>
                        <div style="flex:0 0 108px;">
                            <label style="font-size:.65rem;font-weight:700;color:#92400e;display:block;margin-bottom:.15rem;">Valor</label>
                            <input type="number" id="edit-valor-${g.id}" value="${g.valor}" min="1" style="width:100%;padding:.3rem .5rem;border:1px solid #fcd34d;border-radius:6px;font-size:.78rem;">
                        </div>
                        <div style="flex:1;min-width:110px;">
                            <label style="font-size:.65rem;font-weight:700;color:#92400e;display:block;margin-bottom:.15rem;">Observación</label>
                            <input type="text" id="edit-obs-${g.id}" value="${editObs}" style="width:100%;padding:.3rem .5rem;border:1px solid #fcd34d;border-radius:6px;font-size:.78rem;">
                        </div>
                        <div style="flex:0 0 auto;">
                            <label style="font-size:.65rem;font-weight:700;color:#92400e;display:block;margin-bottom:.15rem;">Imagen</label>
                            <input type="file" id="edit-img-${g.id}" accept="image/*,application/pdf" style="font-size:.7rem;max-width:175px;">
                        </div>
                        <div style="display:flex;gap:.35rem;flex-shrink:0;align-items:flex-end;">
                            <button type="submit" style="background:#16a34a;color:#fff;border:none;border-radius:7px;padding:.32rem .9rem;font-size:.78rem;font-weight:700;cursor:pointer;">💾 Guardar</button>
                            <button type="button" onclick="cancelarEdicionGasto(${g.id})" style="background:#f1f5f9;border:none;border-radius:7px;padding:.32rem .75rem;font-size:.78rem;cursor:pointer;">✕</button>
                        </div>
                        <span id="edit-status-${g.id}" style="font-size:.72rem;color:#16a34a;align-self:flex-end;"></span>
                    </form>
                </td>
            </tr>`;
        });
    });

    html += `<tr style="background:#fef2f2;border-top:2px solid #fecaca;">
        <td colspan="5" style="padding:.55rem .7rem;font-size:.78rem;font-weight:700;color:#b91c1c;">TOTAL — ${data.count} gasto(s)</td>
        <td style="padding:.55rem .7rem;text-align:right;font-weight:800;color:#dc2626;font-family:monospace;font-size:.88rem;">${fmtN(data.total)}</td>
        <td></td>
    </tr></tbody></table></div>`;

    html += htmlGastosDeOtroMes(data.de_otro_mes || []);

    document.getElementById('modalGastosBody').innerHTML = html;
}

// ── Mes al que corresponde (egresos operativos) ──────────────────────
const _MESES_LARGO = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
function mesLargo(ymd) { const [a, m] = String(ymd).split('-').map(Number); return _MESES_LARGO[m-1] + ' ' + a; }
function mesCorto(ymd) { const [a, m] = String(ymd).split('-').map(Number); return _MESES_LARGO[m-1].substring(0,3).toLowerCase() + ' ' + a; }

// Opciones del selector: '' = el mes de la fecha; 12 meses antes y 2 después.
function opcionesPeriodoGasto(g) {
    const f = (g.fecha || '').substring(0,7);
    if (!f) return '<option value="">Mismo mes de la fecha</option>';
    const [a, m] = f.split('-').map(Number);
    const actual = (g.periodo || '').substring(0,7);
    let html = `<option value="">Mes del pago (${mesLargo(f)})</option>`;
    let hay = !actual || actual === f;
    for (let i = -12; i <= 2; i++) {
        if (i === 0) continue;
        const d  = new Date(a, m - 1 + i, 1);
        const ym = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0');
        if (ym === actual) hay = true;
        html += `<option value="${ym}"${ym === actual ? ' selected' : ''}>${mesLargo(ym)}</option>`;
    }
    if (!hay) html += `<option value="${actual}" selected>${mesLargo(actual)}</option>`;
    return html;
}

// Pagados en este mes pero de otro: salen del banco aquí, no pesan en esta utilidad.
function htmlGastosDeOtroMes(lista) {
    if (!lista.length) return '';
    const fmtN  = v => '$ ' + Math.round(Number(v) || 0).toLocaleString('es-CO');
    const total = lista.reduce((t, g) => t + (Number(g.valor) || 0), 0);
    let html = `<div style="margin-top:1rem;border:1px dashed #f59e0b;border-radius:10px;background:#fffbeb;padding:.7rem .9rem;">
        <div style="font-size:.74rem;font-weight:800;color:#92400e;margin-bottom:.2rem;">Pagados este mes, de otro mes — ${fmtN(total)}</div>
        <div style="font-size:.68rem;color:#a16207;margin-bottom:.5rem;">Salen del banco y del efectivo en este mes, pero cuentan en la utilidad del mes al que corresponden.</div>`;
    lista.forEach(g => {
        const desc = (g.descripcion || g.pagado_a || '—').replace(/</g,'&lt;');
        html += `<div style="display:flex;gap:.6rem;align-items:center;font-size:.76rem;padding:.25rem 0;border-top:1px solid #fde68a;">
            <span style="color:#64748b;white-space:nowrap;">${g.fecha.substring(0,10).split('-').reverse().join('/')}</span>
            <span style="flex:1;color:#374151;font-weight:600;">${desc}</span>
            <span style="font-size:.66rem;font-weight:700;color:#b45309;background:#fef3c7;border-radius:5px;padding:.05rem .35rem;white-space:nowrap;">De ${mesLargo(g.periodo_efectivo)}</span>
            <span style="font-family:monospace;font-weight:700;color:#b45309;white-space:nowrap;">${fmtN(g.valor)}</span>
        </div>`;
    });
    return html + '</div>';
}

function verImagenGasto(url) {
    document.getElementById('lightboxImg').src      = url;
    document.getElementById('lightboxImgLink').href = url;
    document.getElementById('modalLightbox').style.display = 'flex';
}

function editarGasto(id) {
    const row = document.getElementById(`gasto-edit-${id}`);
    if (!row) return;
    const visible = row.style.display !== 'none';
    document.querySelectorAll('[id^="gasto-edit-"]').forEach(r => r.style.display = 'none');
    row.style.display = visible ? 'none' : 'table-row';
}

function cancelarEdicionGasto(id) {
    const row = document.getElementById(`gasto-edit-${id}`);
    if (row) row.style.display = 'none';
}

async function guardarGasto(ev, id) {
    ev.preventDefault();
    const status = document.getElementById(`edit-status-${id}`);
    const btn    = ev.target.querySelector('button[type="submit"]');
    status.textContent = 'Guardando…';
    status.style.color = '#92400e';
    btn.disabled = true;

    const g = _gastosData.find(x => x.id == id) || {};
    const fd = new FormData();
    fd.append('_method',     'PUT');
    fd.append('_token',      document.querySelector('meta[name="csrf-token"]')?.content || '');
    fd.append('fecha',       document.getElementById(`edit-fecha-${id}`).value);
    fd.append('descripcion', document.getElementById(`edit-desc-${id}`).value);
    fd.append('valor',       document.getElementById(`edit-valor-${id}`).value);
    fd.append('observacion', document.getElementById(`edit-obs-${id}`).value);
    fd.append('periodo',     document.getElementById(`edit-periodo-${id}`)?.value || '');
    fd.append('tipo',        g.tipo       || 'otro');
    fd.append('forma_pago',  g.forma_pago || 'efectivo');
    if (g.pagado_a)        fd.append('pagado_a',       g.pagado_a);
    if (g.banco_origen_id) fd.append('banco_origen_id', g.banco_origen_id);

    const imgInput = document.getElementById(`edit-img-${id}`);
    if (imgInput && imgInput.files[0]) fd.append('imagen', imgInput.files[0]);

    try {
        const resp = await fetch(`{{ url('/admin/informes/gastos') }}/${id}`, {
            method: 'POST', body: fd, headers: { 'Accept': 'application/json' }
        });
        if (resp.ok || resp.status === 302) {
            status.textContent = '✅ Guardado';
            status.style.color = '#16a34a';
            setTimeout(() => abrirGastosDetalle(), 850);
        } else {
            const err = await resp.json().catch(() => ({}));
            status.textContent = '❌ ' + (err.message || 'Error al guardar');
            status.style.color = '#dc2626';
            btn.disabled = false;
        }
    } catch(e) {
        status.textContent = '❌ Error de red';
        status.style.color = '#dc2626';
        btn.disabled = false;
    }
}

function fmtFecha(fechaStr) {
    if (!fechaStr) return '—';
    const date = new Date(fechaStr + 'T00:00:00');
    const meses = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    return date.getDate() + ' ' + meses[date.getMonth()];
}

function abrirModalGasto() {
    const form = document.getElementById('modal-gasto-form');
    if (form) {
        form.reset();
        
        // Limpiar _method si existe
        const mp = form.querySelector('input[name="_method"]');
        if (mp) mp.remove();
        
        // Restaurar Action original de creación
        form.action = "{{ route('admin.informes.gastos.store') }}";
        
        // Restaurar Título y botón
        const modal = document.getElementById('modal-gasto');
        const hdr = modal.querySelector('[style*="background:#0f172a"] span');
        if (hdr) hdr.textContent = '💼 Registrar Gasto';
        const btn = form.querySelector('button[type="submit"]');
        if (btn) btn.textContent = '✅ Registrar Gasto';

        // Restaurar select de tipo normal
        const selTipo = form.querySelector('select[name="tipo"]');
        if (selTipo) {
            selTipo.style.display = '';
            selTipo.setAttribute('required', '');
        }
        const prevHidden = form.querySelector('input[name="tipo"][type="hidden"]');
        if (prevHidden) prevHidden.remove();
        const prevBadge = form.querySelector('#badge-tipo-planilla');
        if (prevBadge) prevBadge.remove();

        const hide = ['modal-gasto-banco-origen','modal-gasto-banco-destino','modal-gasto-blq-usuario'];
        hide.forEach(id => { const el = document.getElementById(id); if(el) el.style.display='none'; });
        const zone = document.getElementById('modal-gasto-paste-zone');
        if (zone) {
            zone.style.borderColor = '#cbd5e1';
            zone.innerHTML = '<div style="font-size:1.3rem">📎</div><p style="font-size:.75rem;color:#64748b;margin:0">Pega imagen (Ctrl+V) o arrastra aquí</p><p style="font-size:.68rem;color:#94a3b8;margin:0">Clic para seleccionar archivo</p>';
        }
        const b64 = document.getElementById('modal-gasto-base64');
        if (b64) b64.value = '';
        gasto_periodoCargar('modal-gasto', null);
    }
    document.getElementById('modal-gasto').style.display = 'flex';
}

function abrirEditarGasto(id) {
    const g = _gastosMap[id] || {};
    const modal = document.getElementById('modal-gasto');
    const form  = document.getElementById('modal-gasto-form');
    if(!modal || !form) return;

    // Título y botón de guardar
    const hdr = modal.querySelector('[style*="background:#0f172a"] span');
    if(hdr) hdr.textContent = '✏️ Editar Gasto #' + (g.id || '');
    const btn = form.querySelector('button[type="submit"]');
    if(btn) btn.textContent = '💾 Guardar Cambios';

    // _method PUT
    let mp = form.querySelector('input[name="_method"]');
    if(!mp){ mp = document.createElement('input'); mp.type='hidden'; mp.name='_method'; form.appendChild(mp); }
    mp.value = 'PUT';

    // Action para actualización del gasto
    form.action = `/admin/informes/gastos/${g.id}`;

    // ── Manejar tipo pago_planilla ────────────────────────────────────
    const selTipo = form.querySelector('select[name="tipo"]');
    // Limpiar estado anterior
    const prevHidden = form.querySelector('input[name="tipo"][type="hidden"]');
    if(prevHidden) prevHidden.remove();
    const prevBadge = form.querySelector('#badge-tipo-planilla');
    if(prevBadge) prevBadge.remove();

    if(g.tipo === 'pago_planilla'){
        if(selTipo){ selTipo.style.display = 'none'; selTipo.removeAttribute('required'); }
        const ht = document.createElement('input');
        ht.type = 'hidden'; ht.name = 'tipo'; ht.value = 'pago_planilla';
        ht.id = 'hidden-tipo-planilla';
        if(selTipo) selTipo.parentNode.appendChild(ht);
        
        const badge = document.createElement('div');
        badge.id = 'badge-tipo-planilla';
        badge.innerHTML = '<span style="display:inline-flex;align-items:center;gap:.4rem;background:#f0fdf4;border:1px solid #86efac;color:#15803d;border-radius:7px;padding:.35rem .7rem;font-size:.78rem;font-weight:600;">🏢 Pago Planilla <span style="color:#6b7280;font-weight:400;font-size:.72rem;">(tipo fijo — administrar desde Planos)</span></span>';
        if(selTipo) selTipo.parentNode.insertBefore(badge, selTipo);
    } else {
        if(selTipo){ selTipo.style.display = ''; selTipo.setAttribute('required',''); }
    }

    // Rellenar campos del gasto
    const set = (name, val) => {
        const el = form.querySelector(`[name="${name}"]:not([type="hidden"])`);
        if(el) el.value = val ?? '';
    };
    set('fecha',            g.fecha ? g.fecha.substring(0,10) : '');
    gasto_periodoCargar('modal-gasto', g);
    if(g.tipo !== 'pago_planilla') set('tipo', g.tipo ?? '');
    set('descripcion',      g.descripcion ?? '');
    set('pagado_a',         g.pagado_a    ?? '');
    set('valor',            g.valor       ?? '');
    set('forma_pago',       g.forma_pago  ?? 'efectivo');
    set('banco_origen_id',  g.banco_origen_id  ?? '');
    set('banco_destino_id', g.banco_destino_id ?? '');
    set('recibo_caja',      g.recibo_caja ?? '');
    set('observacion',      g.observacion ?? '');

    // Visibilidad de bancos
    if(g.tipo !== 'pago_planilla') gasto_onTipoChange('modal-gasto');
    gasto_actualizarBancos('modal-gasto');

    modal.style.display = 'flex';
}
</script>

@if($esAdmin)
@include('admin.partials.modal_gasto', [
    'formAction'  => route('admin.informes.gastos.store'),
    'bancos'      => $bancos,
    'esAdmin'     => true,
    'modalId'     => 'modal-gasto',
    'imagenPaste' => true,
    'usuarios'    => $usuarios,
    'conPeriodo'  => true,
])
@endif

@endpush
@endsection
