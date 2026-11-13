@extends('layouts.app')
@section('modulo','Cierre de Operación')
@section('contenido')
@php
    $meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    $porConfirmar = $lotes->filter(fn($l) => $l->api)->count();
    $sinLiquidar  = $lotes->count() - $porConfirmar;
    $afiSinFactura = $afiliaciones->where('sin_factura', 1)->count();
    $afiSinCobro   = $afiliaciones->count() - $afiSinFactura;

    $tabs = [
        'planillas' => ['📄 Planillas sin confirmar', $lotes->count()],
        'vigentes'  => ['👥 Vigentes sin facturar',   $vigentes->count()],
        'afiliados' => ['📥 Afiliaciones del mes',    $afiliaciones->count()],
    ];
@endphp

{{-- Controles: mismos tokens del layout (azul #2563eb, hover #3b82f6, radio 8px). --}}
<style>
    .co-select {
        padding:.5rem .75rem; border:1px solid #cbd5e1; border-radius:8px;
        font-family:inherit; font-size:.82rem; color:#1e293b; background:#fff;
        cursor:pointer; transition:border-color .15s, box-shadow .15s;
    }
    .co-select:hover  { border-color:#3b82f6; }
    .co-select:focus  { outline:none; border-color:#2563eb; box-shadow:0 0 0 3px rgba(59,130,246,.15); }

    .co-btn {
        display:inline-flex; align-items:center; gap:.4rem;
        background:#2563eb; color:#fff; border:none; border-radius:8px;
        padding:.5rem 1.1rem; font-family:inherit; font-size:.82rem; font-weight:600;
        cursor:pointer; transition:background .15s, transform .1s, box-shadow .15s;
        box-shadow:0 1px 3px rgba(37,99,235,.3);
    }
    .co-btn:hover  { background:#3b82f6; transform:translateY(-1px); box-shadow:0 4px 12px rgba(37,99,235,.28); }
    .co-btn:active { transform:translateY(0); }

    .co-btn-ghost {
        display:inline-flex; align-items:center; gap:.35rem;
        background:rgba(59,130,246,.1); border:1px solid rgba(59,130,246,.3);
        color:#1d4ed8; border-radius:8px; padding:.35rem .75rem;
        font-family:inherit; font-size:.75rem; font-weight:600;
        cursor:pointer; text-decoration:none; transition:background .15s, border-color .15s;
    }
    .co-btn-ghost:hover { background:rgba(59,130,246,.2); border-color:rgba(59,130,246,.5); }

    .co-tab {
        background:none; border:none; border-bottom:3px solid transparent;
        padding:.6rem 1.1rem; margin-bottom:-2px;
        font-family:inherit; font-size:.84rem; font-weight:600; color:#64748b;
        cursor:pointer; transition:color .15s, border-color .15s;
    }
    .co-tab:hover   { color:#1e293b; }
    .co-tab-activa  { color:#2563eb; border-bottom-color:#2563eb; }

    .co-fila-lote:hover { background:#f8fafc; }

    /* Tablas: en clases y no inline porque estas celdas se repiten miles de
       veces y el estilo repetido inflaba el HTML a casi un mega. */
    .co-tabla       { width:100%; border-collapse:collapse; font-size:.8rem; }
    .co-tabla thead tr { background:#f8fafc; position:sticky; top:0; z-index:1; }
    .co-tabla th    { padding:.55rem .6rem; font-size:.7rem; text-transform:uppercase;
                      color:#64748b; text-align:left; font-weight:600; }
    .co-tabla td    { padding:.5rem .6rem; color:#334155; }
    .co-tabla tbody tr { border-bottom:1px solid #f1f5f9; }
    .co-tabla .pri  { padding-left:1rem; }
    .co-tabla .ult  { padding-right:1rem; }
    .co-c           { text-align:center; }
    .co-d           { text-align:right; }
    .co-rs          { font-weight:600; color:#0d2550; }
    .co-mut         { color:#64748b; }
    .co-tenue       { color:#94a3b8; }

    .co-sub         { width:100%; border-collapse:collapse; font-size:.75rem;
                      background:#fff; border-radius:8px; overflow:hidden; }
    .co-sub th      { padding:.4rem .6rem; text-align:left; color:#64748b;
                      font-size:.68rem; text-transform:uppercase; background:#eef2f7; font-weight:600; }
    .co-sub td      { padding:.4rem .6rem; color:#334155; }
    .co-sub tbody tr { border-top:1px solid #f1f5f9; }

    .co-chip        { border-radius:999px; padding:.1rem .5rem; font-size:.68rem; font-weight:600; }
    .co-chip-plan   { background:rgba(59,130,246,.12); color:#1d4ed8; }
    .co-chip-ret    { background:rgba(234,179,8,.15);  color:#a16207; }
</style>

<div style="max-width:1200px;margin:0 auto;" x-data="{tab:'planillas', abierto:null}">
    <div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.25rem;flex-wrap:wrap;">
        <a href="{{ route('admin.informes.hub') }}" style="color:#64748b;font-size:.82rem;text-decoration:none;">← Informes</a>
        <h1 style="font-size:1.2rem;font-weight:700;color:#0d2550;flex:1;">✅ Cierre de Operación</h1>

        <form method="GET" style="display:flex;gap:.5rem;align-items:center;">
            <select name="mes" class="co-select">
                @for($m = 1; $m <= 12; $m++)
                    <option value="{{ $m }}" @selected($m === $mes)>{{ $meses[$m] }}</option>
                @endfor
            </select>
            <select name="anio" class="co-select">
                @for($a = now()->year - 2; $a <= now()->year + 1; $a++)
                    <option value="{{ $a }}" @selected($a === $anio)>{{ $a }}</option>
                @endfor
            </select>
            <button type="submit" class="co-btn">Ver</button>
        </form>
    </div>

    {{-- Resumen --}}
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem;margin-bottom:1.1rem;">
        @foreach([
            ['Liquidadas sin confirmar', $porConfirmar, '#f59e0b', 'Ya tienen N° en el operador'],
            ['Tandas sin liquidar', $sinLiquidar, $sinLiquidar > 0 ? '#ef4444' : '#10b981', 'Sin número de planilla'],
            ['Vigentes sin facturar', $vigentes->count(), $vigentes->count() > 0 ? '#ef4444' : '#10b981', $meses[$mes].' '.$anio],
            ['Afiliaciones pendientes', $afiliaciones->count(), $afiliaciones->count() > 0 ? '#f59e0b' : '#10b981', $afiSinFactura.' sin factura · '.$afiSinCobro.' sin cobro'],
        ] as [$label, $valor, $color, $pie])
        <div style="background:#fff;border-radius:12px;padding:.9rem 1rem;box-shadow:0 1px 6px rgba(0,0,0,.06);">
            <div style="font-size:1.5rem;font-weight:800;color:{{ $color }};line-height:1;">{{ number_format($valor) }}</div>
            <div style="font-size:.76rem;font-weight:600;color:#334155;margin-top:.25rem;">{{ $label }}</div>
            <div style="font-size:.7rem;color:#94a3b8;margin-top:.1rem;">{{ $pie }}</div>
        </div>
        @endforeach
    </div>

    {{-- Tabs --}}
    <div style="display:flex;gap:.5rem;margin-bottom:1rem;border-bottom:2px solid #e2e8f0;flex-wrap:wrap;">
        @foreach($tabs as $key => [$label, $n])
        <button @click="tab='{{ $key }}'" class="co-tab" :class="tab==='{{ $key }}' && 'co-tab-activa'">
            {{ $label }} <span style="color:#94a3b8;font-weight:700;">({{ $n }})</span>
        </button>
        @endforeach
    </div>

    {{-- ── Planillas sin confirmar ─────────────────────────────────────── --}}
    <div x-show="tab==='planillas'" style="background:#fff;border-radius:14px;box-shadow:0 1px 8px rgba(0,0,0,.06);overflow:hidden;">
        <div style="padding:.8rem 1rem;background:#f8fafc;border-bottom:1px solid #e2e8f0;font-size:.76rem;color:#475569;line-height:1.45;">
            Tandas cuyos planos no tienen número de planilla. El número se estampa al
            <strong>confirmar el pago</strong> en el módulo de planos, así que una tanda ya liquidada en el
            operador sigue apareciendo aquí hasta que se registre el pago.
            <a href="{{ route('admin.informes.cierre_operacion', ['mes'=>$mes,'anio'=>$anio,'excel'=>'lotes']) }}"
               class="co-btn-ghost" style="margin-left:.4rem;">⬇ Excel</a>
        </div>
        <div style="max-height:60vh;overflow:auto;">
            <table class="co-tabla">
                <thead><tr>
                    <th class="pri">Razón social</th>
                    <th class="co-c">Mes de pago</th>
                    <th class="co-c">Tanda</th>
                    <th class="co-c">Cotizantes</th>
                    <th class="co-d">Valor SS</th>
                    <th>Estado</th>
                    <th class="ult"></th>
                </tr></thead>
                <tbody>
                    @forelse($lotes as $l)
                    @php $detalle = $cotizantes->get($l->llave, collect()); @endphp
                    <tr class="co-fila-lote" style="cursor:pointer;"
                        @click="abierto = (abierto === '{{ $l->llave }}' ? null : '{{ $l->llave }}')">
                        <td class="pri co-rs">{{ $l->razon_social }}</td>
                        <td class="co-c" style="white-space:nowrap;">
                            <div style="font-size:.92rem;font-weight:700;color:#0d2550;">
                                {{ $meses[$l->mes_pago] }} {{ $l->anio_pago }}
                            </div>
                            <div style="font-size:.68rem;" class="co-tenue">
                                período {{ $meses[(int) $l->mes_plano] }} {{ $l->anio_plano }}
                            </div>
                        </td>
                        <td class="co-c co-mut">{{ $l->n_plano }}</td>
                        <td class="co-c" style="font-weight:700;">{{ $l->cotizantes }}</td>
                        <td class="co-d">$ {{ number_format((float) $l->valor_ss) }}</td>
                        <td>
                            @if($l->api)
                                <span style="color:#b45309;font-weight:700;">Liquidada · falta confirmar el pago</span>
                                <div style="font-size:.72rem;" class="co-tenue">
                                    {{ $l->api->operador ?? 'Operador' }} · N° {{ $l->api->numero_planilla }}
                                    @if($l->api->valor_total) · $ {{ number_format((float) $l->api->valor_total) }} @endif
                                </div>
                                @if($l->api->url_pago)
                                    <a href="{{ $l->api->numero_planilla ? route('admin.planos.api_operador.pagar', $l->api->numero_planilla) : $l->api->url_pago }}" target="_blank" rel="noopener" @click.stop
                                       style="font-size:.72rem;color:#15803d;font-weight:700;">Ir a pagar en PSE →</a>
                                @endif
                            @else
                                <span style="color:#b91c1c;font-weight:700;">Sin liquidar</span>
                            @endif
                        </td>
                        <td class="ult co-d" style="white-space:nowrap;">
                            <span style="color:#2563eb;font-size:.74rem;font-weight:700;">
                                <span x-text="abierto === '{{ $l->llave }}' ? 'Ocultar ▲' : 'Ver quiénes ▼'"></span>
                            </span>
                        </td>
                    </tr>

                    {{-- Detalle: quiénes están en la tanda y en qué plano quedaron --}}
                    <tr x-show="abierto === '{{ $l->llave }}'" x-cloak>
                        <td colspan="7" style="padding:0;background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                            <div style="padding:.75rem 1rem;">
                                <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.5rem;flex-wrap:wrap;">
                                    <strong style="font-size:.76rem;color:#334155;">
                                        {{ $detalle->count() }} cotizante(s) en la tanda {{ $l->n_plano }}
                                    </strong>
                                    <a href="{{ route('admin.planos.index', [
                                            'anio' => $l->anio_pago, 'mes' => $l->mes_pago,
                                            'razon_social_id' => $l->razon_social_id, 'n_plano' => $l->n_plano,
                                       ]) }}" @click.stop class="co-btn-ghost">Abrir en Planos →</a>
                                </div>
                                <table class="co-sub">
                                    <thead><tr>
                                        <th>Cédula</th><th>Nombre</th><th>Modalidad</th>
                                        <th class="co-c">Tipo</th><th class="co-c">Días</th>
                                        <th class="co-d">Valor SS</th><th class="co-d">Plano</th>
                                    </tr></thead>
                                    <tbody>
                                        @foreach($detalle as $d)
                                        <tr>
                                            <td style="font-weight:600;">{{ $d->cedula }}</td>
                                            <td style="color:#0d2550;">{{ trim($d->nombre) ?: '—' }}</td>
                                            <td class="co-mut">{{ $d->modalidad ?? '—' }}</td>
                                            <td class="co-c">
                                                <span class="co-chip {{ $d->tipo_reg === 'retiro' ? 'co-chip-ret' : 'co-chip-plan' }}">
                                                    {{ $d->tipo_reg === 'retiro' ? 'Retiro' : 'Planilla' }}
                                                </span>
                                            </td>
                                            <td class="co-c co-mut">{{ $d->num_dias }}</td>
                                            <td class="co-d">$ {{ number_format((float) $d->valor_ss) }}</td>
                                            <td class="co-d co-tenue">#{{ $d->plano_id }}</td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="7" style="padding:2rem;text-align:center;color:#94a3b8;">Todo confirmado. Nada pendiente.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div style="padding:.6rem 1rem;font-size:.7rem;color:#94a3b8;border-top:1px solid #f1f5f9;">
            Cuenta desde abril de 2026, que es cuando el número de planilla empezó a registrarse.
            Lo anterior queda fuera a propósito.
        </div>
    </div>

    {{-- ── Vigentes sin facturar / Afiliaciones ────────────────────────── --}}
    @foreach([
        ['vigentes',  $vigentes,     'vigentes',     'Contratos vigentes sin factura de '.$meses[$mes].' '.$anio.'.'],
        ['afiliados', $afiliaciones, 'afiliaciones', 'Afiliaciones de '.$meses[$mes].' '.$anio.' sin factura, o facturadas sin cobrar el valor de afiliación.'],
    ] as [$key, $filas, $exp, $ayuda])
    <div x-show="tab==='{{ $key }}'" style="background:#fff;border-radius:14px;box-shadow:0 1px 8px rgba(0,0,0,.06);overflow:hidden;">
        <div style="padding:.8rem 1rem;background:#f8fafc;border-bottom:1px solid #e2e8f0;font-size:.76rem;color:#475569;">
            {{ $ayuda }}
            <a href="{{ route('admin.informes.cierre_operacion', ['mes'=>$mes,'anio'=>$anio,'excel'=>$exp]) }}"
               class="co-btn-ghost" style="margin-left:.4rem;">⬇ Excel</a>
        </div>
        <div style="max-height:60vh;overflow:auto;">
            <table class="co-tabla">
                <thead><tr>
                    <th class="pri">Cédula</th><th>Nombre</th><th>Razón social</th><th>Plan</th>
                    <th class="co-c">Ingreso</th>
                    @if($key === 'afiliados')<th class="ult">Pendiente</th>@endif
                </tr></thead>
                <tbody>
                    @forelse($filas as $f)
                    <tr>
                        <td class="pri" style="font-weight:600;">{{ $f->cedula }}</td>
                        <td style="color:#0d2550;">{{ $f->nombre ?: '—' }}</td>
                        <td class="co-mut">{{ $f->razon_social }}</td>
                        <td class="co-mut">{{ $f->plan_nombre ?? '—' }}</td>
                        <td class="co-c co-mut">{{ $f->fecha_ingreso ?? '—' }}</td>
                        @if($key === 'afiliados')
                        <td class="ult">
                            @if((int) $f->sin_factura === 1)
                                <span style="color:#b91c1c;font-weight:700;">Sin factura</span>
                            @else
                                <span style="color:#b45309;font-weight:700;">Sin cobro de afiliación</span>
                            @endif
                        </td>
                        @endif
                    </tr>
                    @empty
                    <tr><td colspan="6" style="padding:2rem;text-align:center;" class="co-tenue">Nada pendiente.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @endforeach
</div>
@endsection
