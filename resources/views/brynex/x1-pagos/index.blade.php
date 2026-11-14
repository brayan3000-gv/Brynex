@extends('layouts.app')

@section('titulo', 'Pagos X1')
@section('modulo', 'BryNex · Pagos X1')

@push('styles')
<style>
.bx1-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:1rem 1.1rem; margin-bottom:1rem; box-shadow:0 1px 3px rgba(0,0,0,.04); }
.bx1-card h3 { margin:0 0 .3rem; font-size:.95rem; font-weight:800; color:#0f172a; }
.bx1-ayuda { font-size:.75rem; color:#64748b; margin:0 0 .7rem; line-height:1.45; }
.bx1-tabla { width:100%; border-collapse:collapse; font-size:.77rem; }
.bx1-tabla th { text-align:left; font-size:.64rem; text-transform:uppercase; letter-spacing:.04em; color:#64748b; padding:.4rem .45rem; border-bottom:2px solid #e2e8f0; white-space:nowrap; }
.bx1-tabla td { padding:.45rem; border-bottom:1px solid #f1f5f9; vertical-align:top; }
.bx1-tabla .num { text-align:right; white-space:nowrap; font-variant-numeric:tabular-nums; }
.bx1-chip { display:inline-block; border-radius:20px; padding:.06rem .45rem; font-size:.64rem; font-weight:700; white-space:nowrap; margin:0 .15rem .15rem 0; }
.bx1-chip.en_solicitud { background:#fef3c7; color:#92400e; }
.bx1-chip.liquidada { background:#dbeafe; color:#1e40af; }
.bx1-chip.pagada, .bx1-chip.blindem, .bx1-chip.doble_planilla { background:#dcfce7; color:#166534; }
.bx1-chip.esperando_ventana { background:#ede9fe; color:#5b21b6; }
.bx1-chip.plano { background:#e0f2fe; color:#0369a1; }
.bx1-btn { display:inline-flex; align-items:center; gap:.25rem; padding:.3rem .65rem; border-radius:7px; border:1px solid transparent; font-weight:700; font-size:.72rem; cursor:pointer; text-decoration:none; white-space:nowrap; }
.bx1-btn.primario { background:#1e40af; color:#fff; }
.bx1-btn.verde { background:#15803d; color:#fff; }
.bx1-btn.suave { background:#f8fafc; border-color:#cbd5e1; color:#334155; }
.bx1-btn:disabled { opacity:.5; cursor:wait; }
.bx1-sol { border:1px solid #e2e8f0; border-radius:10px; padding:.7rem .8rem; margin-bottom:.8rem; }
.bx1-sol-head { display:flex; justify-content:space-between; gap:.75rem; flex-wrap:wrap; margin-bottom:.45rem; }
.bx1-meta { font-size:.7rem; color:#64748b; }
.bx1-msg { margin-top:.35rem; font-size:.72rem; border-radius:7px; padding:.35rem .5rem; }
.bx1-msg.ok { background:#f0fdf4; color:#166534; }
.bx1-msg.error { background:#fef2f2; color:#991b1b; }
.bx1-confirmar { display:flex; gap:.3rem; flex-wrap:wrap; align-items:center; margin-top:.35rem; }
.bx1-confirmar input, .bx1-confirmar select { border:1px solid #cbd5e1; border-radius:6px; padding:.2rem .35rem; font-size:.72rem; }
.bx1-personas summary { cursor:pointer; color:#1d4ed8; font-size:.7rem; font-weight:600; }
.bx1-personas ul { margin:.3rem 0 0; padding-left:1rem; font-size:.7rem; color:#334155; }
.bx1-detalle { border-collapse:collapse; margin-top:.4rem; font-size:.7rem; min-width:100%; }
.bx1-detalle th { font-size:.58rem; text-transform:uppercase; letter-spacing:.03em; color:#64748b; padding:.28rem .4rem; border-bottom:1px solid #e2e8f0; text-align:left; white-space:nowrap; }
.bx1-detalle td { padding:.28rem .4rem; border-bottom:1px solid #f1f5f9; color:#334155; }
.bx1-detalle .num { text-align:right; white-space:nowrap; font-variant-numeric:tabular-nums; }
.bx1-detalle tfoot td { font-weight:800; border-top:1px solid #e2e8f0; border-bottom:none; }
</style>
@endpush

@section('contenido')
@php
    $mesesNombre = ['', 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
    $fmt = fn ($v) => '$'.number_format((float) $v, 0, ',', '.');
    $estadoSolicitud = ['solicitada' => 'Solicitada', 'en_proceso' => 'En proceso', 'parcial' => 'Parcial', 'pagada' => 'Pagada'];
@endphp

<div class="modulo-header">
    <div class="modulo-titulo">🧾 Pagos X1 · BryNex</div>
    <form method="GET" action="{{ route('brynex.x1.index') }}" style="display:flex;gap:.4rem;align-items:center">
        <input type="hidden" name="vista" value="{{ $vista }}">
        <select name="anio" onchange="this.form.submit()" style="border:1px solid #cbd5e1;border-radius:8px;padding:.25rem .4rem;font-weight:700;font-size:.78rem">
            @for($y = now()->year; $y >= now()->year - 2; $y--)
            <option value="{{ $y }}" @selected($anio == $y)>{{ $y }}</option>
            @endfor
        </select>
        <select name="mes" onchange="this.form.submit()" style="border:1px solid #cbd5e1;border-radius:8px;padding:.25rem .4rem;font-weight:700;font-size:.78rem">
            @for($m = 1; $m <= 12; $m++)
            <option value="{{ $m }}" @selected($mes == $m)>{{ $mesesNombre[$m] }}</option>
            @endfor
        </select>
    </form>
</div>

@if(! $activo)
<div class="bx1-card" style="border-color:#fde68a;background:#fffbeb">
    <p class="bx1-ayuda" style="margin:0;color:#92400e">El flujo de pagos X1 por BryNex está apagado (<code>x1_pagos_brynex_activo</code> = 0): los aliados liquidan su X1 desde Planos.</p>
</div>
@endif

{{-- ── Pestañas ──────────────────────────────────────────────────── --}}
@php
    $pestanas = ['razon' => '🏢 Por razón social', 'consignaciones' => '🧾 Consignaciones', 'aliados' => '👥 Por aliado'];
    $porVerificar = $solicitudes->filter(fn ($s) => (int) $s->aliado_id !== $aliadoBrygar && empty($s->consignacion_verificada_at))->count();
@endphp
<div style="display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:.8rem">
    @foreach($pestanas as $clave => $etiqueta)
    <a href="{{ route('brynex.x1.index', ['vista' => $clave, 'mes' => $mes, 'anio' => $anio]) }}"
       class="bx1-btn {{ $vista === $clave ? 'primario' : 'suave' }}" style="font-size:.78rem;padding:.4rem .85rem">
        {{ $etiqueta }}
        @if($clave === 'consignaciones' && $porVerificar > 0)
        <span style="background:#f59e0b;color:#fff;border-radius:20px;padding:0 .4rem;font-size:.66rem">{{ $porVerificar }}</span>
        @endif
    </a>
    @endforeach
</div>

@if($vista === 'razon')
{{-- ── Por razón social: una planilla con la gente de todos los aliados ── --}}
<div class="bx1-card">
    <h3>Por razón social · {{ $mesesNombre[$mes] }} {{ $anio }}</h3>
    <p class="bx1-ayuda">
        Cada empresa junta a las personas X1 de <strong>todos los aliados</strong> en <strong>una sola planilla</strong>, con la sucursal de Brygar:
        un solo pago PSE y un solo comprobante. Quien rebote por el subtipo 4 pasa al plano X1 de su aliado «Esperando ventana».
        La consignación se puede verificar después, en la pestaña Consignaciones.
    </p>
</div>

@forelse($grupos as $g)
@php
    $datosConjunta = ['nit' => $g->nit, 'tipo_modalidad_id' => $g->tipo_modalidad_id, 'mes' => $mes, 'anio' => $anio];
    $urlsConjunta = [
        'liquidar' => route('brynex.x1.conjunta.liquidar'),
        'confirmar' => route('brynex.x1.conjunta.confirmar'),
        'pagar' => route('brynex.x1.pagar').'?aliado_id='.$aliadoBrygar.'&numero=',
    ];
    $numeroConjunta = $g->registro->numero_planilla ?? null;
    $valorConjunta = $g->registro?->valor_total ? (int) round($g->registro->valor_total) : null;
@endphp
<div class="bx1-card" x-data="tandaX1(@js($datosConjunta), @js($numeroConjunta), @js($valorConjunta), null, @js($g->por_pagar), @js($urlsConjunta))">
    <div class="bx1-sol-head">
        <div>
            <strong style="font-size:.92rem">{{ $g->razon_social }}</strong>
            <span class="bx1-chip plano">{{ $g->modalidad }}</span>
            <div class="bx1-meta">
                {{ $g->aliados->count() }} {{ $g->aliados->count() === 1 ? 'aliado' : 'aliados' }} · {{ $g->personas }} {{ $g->personas === 1 ? 'persona' : 'personas' }}
                · NIT {{ $g->nit }}
                @if($g->rs_brygar) · sucursal Brygar @endif
            </div>
        </div>
        <div style="text-align:right">
            <strong style="font-size:.92rem">{{ $fmt($g->valor_ss) }}</strong>
            <div class="bx1-meta">SS con día de pensión</div>
            @foreach($g->estados as $estado => $n)
            <span class="bx1-chip {{ $estado }}">{{ $etiquetasEstado[$estado] ?? $estado }} · {{ $n }}</span>
            @endforeach
        </div>
    </div>

    <div style="overflow-x:auto">
    <table class="bx1-tabla">
        <thead><tr><th>Aliado</th><th class="num">Personas</th><th>Consignación</th><th>Estado</th><th class="num">SS</th></tr></thead>
        <tbody>
        @foreach($g->aliados as $a)
        <tr>
            <td>{{ $a->nombre }}</td>
            <td class="num">{{ $a->personas }}</td>
            <td>
                @if($a->aliado_id === $aliadoBrygar)
                    <span class="bx1-chip pagada">No aplica</span>
                @elseif($a->verificada)
                    <span class="bx1-chip pagada">Verificada</span>
                @else
                    <a class="bx1-chip en_solicitud" style="text-decoration:none"
                       href="{{ route('brynex.x1.index', ['vista' => 'consignaciones', 'mes' => $mes, 'anio' => $anio]) }}">Por verificar · ver foto</a>
                @endif
            </td>
            <td>
                @foreach($a->estados as $estado => $n)
                <span class="bx1-chip {{ $estado }}">{{ $etiquetasEstado[$estado] ?? $estado }} · {{ $n }}</span>
                @endforeach
            </td>
            <td class="num">{{ $fmt($a->valor_ss) }}</td>
        </tr>
        @endforeach
        </tbody>
    </table>
    </div>

    <details class="bx1-personas" style="margin-top:.4rem">
        <summary>Personas ({{ $g->personas }})</summary>
        <div style="overflow-x:auto">
        <table class="bx1-detalle">
            <thead><tr><th>Persona</th><th>Aliado</th><th class="num">Días</th><th class="num">EPS</th><th class="num">ARL</th><th class="num">Caja</th><th class="num">Pensión (1 día)</th><th class="num">SS</th><th>Estado</th><th>Planilla</th></tr></thead>
            <tbody>
            @foreach($g->filas as $f)
            <tr>
                <td style="white-space:nowrap">{{ $f->tipo_doc }} {{ $f->no_identifi }} · {{ nombre_oracion(trim($f->primer_nombre.' '.$f->primer_ape)) }}</td>
                <td>{{ $f->aliado_nombre }}</td>
                <td class="num">{{ $f->num_dias }}</td>
                <td class="num">{{ $fmt($f->v_eps) }}</td>
                <td class="num">{{ $fmt($f->v_arl) }}</td>
                <td class="num">{{ $fmt($f->v_caja) }}</td>
                <td class="num">{{ $fmt($f->valor_afp) }}</td>
                <td class="num"><strong>{{ $fmt($f->valor_ss) }}</strong></td>
                <td><span class="bx1-chip {{ $f->estado }}">{{ $etiquetasEstado[$f->estado] ?? $f->estado }}</span></td>
                <td style="font-family:monospace">{{ $f->numero_planilla ?: '—' }}</td>
            </tr>
            @endforeach
            </tbody>
        </table>
        </div>
    </details>

    <div style="display:flex;justify-content:space-between;gap:.8rem;flex-wrap:wrap;margin-top:.6rem;align-items:flex-start">
        <div style="font-family:monospace;font-size:.74rem">
            @if($g->registro)
                Planilla conjunta {{ $g->registro->numero_planilla }}
                @if($g->registro->valor_total)<span class="bx1-meta" style="font-family:sans-serif">· {{ $fmt($g->registro->valor_total) }}</span>@endif
                @unless($g->por_pagar)<div class="bx1-meta" style="font-family:sans-serif">anterior, sin pagar</div>@endunless
            @endif
        </div>
        <div>
            @if(! $g->rs_brygar)
                <span class="bx1-meta">Esta empresa no está en Brygar: no se puede juntar. Páguela por aliado.</span>
            @elseif($g->abierta)
            <div style="display:flex;gap:.3rem;flex-wrap:wrap;justify-content:flex-end">
                <button type="button" class="bx1-btn primario" @click="liquidar()" :disabled="ocupado">
                    <span x-text="ocupado === 'liquidar' ? 'Liquidando…' : 'Liquidar {{ $g->para_liquidar ?: '' }} en una planilla'">Liquidar en una planilla</span>
                </button>
                <template x-if="urlPago">
                    <a class="bx1-btn verde" target="_blank" rel="noopener" :href="urlPago">Pagar ahora</a>
                </template>
                <template x-if="numero && porPagar && !urlPago">
                    <a class="bx1-btn verde" target="_blank" :href="urls.pagar + encodeURIComponent(numero)">Pagar (PSE)</a>
                </template>
                <button type="button" class="bx1-btn suave" @click="abrirConfirmar = !abrirConfirmar">Confirmar</button>
            </div>
            @include('brynex.x1-pagos._confirmar')
            @else
                <span class="bx1-meta">Cerrada</span>
            @endif
            @include('brynex.x1-pagos._mensaje')
        </div>
    </div>
</div>
@empty
<div class="bx1-card"><div class="bx1-meta">No hay personas X1 solicitadas en este período.</div></div>
@endforelse

@elseif($vista === 'consignaciones')
{{-- ── Consignaciones: validar que el soporte es real ─────────────────── --}}
<div class="bx1-card">
    <h3>Consignaciones · {{ $mesesNombre[$mes] }} {{ $anio }}</h3>
    <p class="bx1-ayuda">Lo que cada aliado consignó y mandó a pagar. Compare la foto con el banco y márquela como verificada. No frena la liquidación: se puede pagar primero y verificar después.</p>
</div>
@forelse($solicitudes as $s)
@php
    $esBrygarSol = (int) $s->aliado_id === $aliadoBrygar;
    $extension = strtolower(pathinfo((string) $s->soporte_nombre, PATHINFO_EXTENSION));
    $esImagen = in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
@endphp
<div class="bx1-card" style="display:flex;gap:1rem;flex-wrap:wrap">
    <div style="flex:0 0 200px">
        @if($s->soporte_path && $esImagen)
            <a href="{{ route('admin.planos.x1.soporte', $s->id) }}" target="_blank" title="Abrir la foto completa">
                <img src="{{ route('admin.planos.x1.soporte', $s->id) }}" alt="Consignación de la solicitud #{{ $s->id }}"
                     style="width:200px;max-height:260px;object-fit:contain;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc">
            </a>
        @elseif($s->soporte_path)
            <a class="bx1-btn suave" href="{{ route('admin.planos.x1.soporte', $s->id) }}" target="_blank">Ver consignación ({{ strtoupper($extension ?: 'archivo') }})</a>
        @else
            <div class="bx1-meta">{{ $esBrygarSol ? 'Brygar no consigna.' : 'Sin foto.' }}</div>
        @endif
    </div>
    <div style="flex:1 1 320px;min-width:0">
        <div class="bx1-sol-head">
            <div>
                <strong>#{{ $s->id }} · {{ $s->aliado_nombre }}</strong>
                <div class="bx1-meta">{{ \Carbon\Carbon::parse($s->created_at)->format('d/m/Y H:i') }} · {{ $s->personas }} {{ $s->personas == 1 ? 'persona' : 'personas' }}</div>
            </div>
            <div style="text-align:right">
                <strong style="font-size:1rem">{{ $fmt($s->total) }}</strong>
                <div class="bx1-meta">SS {{ $fmt($s->valor_ss) }} · admon {{ $fmt($s->valor_admon) }}@if($s->valor_mora > 0) · mora {{ $fmt($s->valor_mora) }}@endif</div>
            </div>
        </div>
        <table class="bx1-detalle">
            <thead><tr><th>Empresa</th><th class="num">Personas</th><th class="num">SS</th><th>Estado</th></tr></thead>
            <tbody>
            @foreach($s->tandas->groupBy('razon_social_id') as $deRs)
            <tr>
                <td>{{ \Illuminate\Support\Str::limit($deRs->first()->razon_social, 40, '…') }}</td>
                <td class="num">{{ $deRs->sum('personas') }}</td>
                <td class="num">{{ $fmt($deRs->sum('valor_ss')) }}</td>
                <td>
                    @foreach($deRs->flatMap(fn ($t) => $t->filas)->countBy('estado') as $estado => $n)
                    <span class="bx1-chip {{ $estado }}">{{ $etiquetasEstado[$estado] ?? $estado }} · {{ $n }}</span>
                    @endforeach
                </td>
            </tr>
            @endforeach
            </tbody>
        </table>
        <div style="margin-top:.6rem;display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
            @if($esBrygarSol)
                <span class="bx1-chip pagada">No aplica (Brygar)</span>
            @elseif($s->consignacion_verificada_at)
                <span class="bx1-chip pagada">✅ Verificada {{ \Carbon\Carbon::parse($s->consignacion_verificada_at)->format('d/m H:i') }}</span>
                <form method="POST" action="{{ route('brynex.x1.verificar') }}" style="margin:0">
                    @csrf
                    <input type="hidden" name="solicitud_id" value="{{ $s->id }}">
                    <input type="hidden" name="verificada" value="0">
                    <button class="bx1-btn suave" type="submit">Quitar verificación</button>
                </form>
            @else
                <span class="bx1-chip en_solicitud">Por verificar</span>
                <form method="POST" action="{{ route('brynex.x1.verificar') }}" style="margin:0">
                    @csrf
                    <input type="hidden" name="solicitud_id" value="{{ $s->id }}">
                    <input type="hidden" name="verificada" value="1">
                    <button class="bx1-btn verde" type="submit">La consignación es real: verificar</button>
                </form>
            @endif
        </div>
    </div>
</div>
@empty
<div class="bx1-card"><div class="bx1-meta">No hay solicitudes en este período.</div></div>
@endforelse

@else
{{-- ── Sin solicitar ─────────────────────────────────────────────── --}}
<div class="bx1-card">
    <h3>Sin solicitar · período de pago {{ $mesesNombre[$mes] }} {{ $anio }}</h3>
    <p class="bx1-ayuda">Personas X1 facturadas que el aliado todavía no ha mandado. Las de Brygar no necesitan foto: se pasan a la cola desde aquí.</p>
    @if($sinSolicitar->isEmpty())
        <div class="bx1-meta">Nadie pendiente.</div>
    @else
    <table class="bx1-tabla" style="max-width:520px">
        <tbody>
        @foreach($sinSolicitar as $a)
        <tr>
            <td>{{ $a->aliado }}</td>
            <td class="num">{{ $a->personas }} {{ $a->personas == 1 ? 'persona' : 'personas' }}</td>
            <td class="num">
                @if((int) $a->aliado_id === \App\Services\X1PagosBrynex::ALIADO_BRYGAR)
                <form method="POST" action="{{ route('brynex.x1.cola_brygar') }}" style="margin:0">
                    @csrf
                    <input type="hidden" name="mes" value="{{ $mes }}">
                    <input type="hidden" name="anio" value="{{ $anio }}">
                    <button class="bx1-btn suave" type="submit">Pasar a la cola</button>
                </form>
                @endif
            </td>
        </tr>
        @endforeach
        </tbody>
    </table>
    @endif
</div>

{{-- ── Solicitudes ───────────────────────────────────────────────── --}}
<div class="bx1-card">
    <h3>Solicitudes</h3>
    <p class="bx1-ayuda">
        <strong>Liquidar</strong> entra a Simple con la cuenta de BryNex. Si la tanda ya tenía planilla, primero pregunta si se pagó: pagada → confirmar;
        vigente → enlace de pago; sin pagar y sin enlace → liquida una nueva. Si Simple rechaza el subtipo 4 a una parte (eo.val.2.655),
        esas personas pasan al siguiente plano X1 «Esperando ventana» y se liquida el resto. Después <strong>Pagar (PSE)</strong> pide un enlace nuevo,
        y <strong>Confirmar</strong> graba el número real de planilla en los planos del aliado (sin gasto en el aliado).
        Las que esperan ventana se confirman igual cuando se paguen, por Simple, doble planilla o Blindem.
    </p>

    @forelse($solicitudes as $s)
    <div class="bx1-sol">
        <div class="bx1-sol-head">
            <div>
                <strong>#{{ $s->id }} · {{ $s->aliado_nombre }}</strong>
                <span class="bx1-chip {{ $s->estado === 'pagada' ? 'pagada' : ($s->estado === 'solicitada' ? 'en_solicitud' : 'liquidada') }}">{{ $estadoSolicitud[$s->estado] ?? $s->estado }}</span>
                <div class="bx1-meta">{{ \Carbon\Carbon::parse($s->created_at)->format('d/m/Y H:i') }} · {{ $s->personas }} {{ $s->personas == 1 ? 'persona' : 'personas' }}</div>
            </div>
            <div style="text-align:right">
                <strong>{{ $fmt($s->total) }}</strong>
                @if($s->soporte_path)
                    · <a href="{{ route('admin.planos.x1.soporte', $s->id) }}" target="_blank" style="font-size:.72rem">Ver consignación</a>
                @else
                    <span class="bx1-meta">· sin foto</span>
                @endif
                <div class="bx1-meta">SS {{ $fmt($s->valor_ss) }} · admon {{ $fmt($s->valor_admon) }} ({{ $fmt($s->admon_por_persona) }}/persona)@if($s->valor_mora > 0) · mora {{ $fmt($s->valor_mora) }}@endif</div>
            </div>
        </div>

        <div style="overflow-x:auto">
        <table class="bx1-tabla">
            <thead>
                <tr>
                    <th>Razón social</th>
                    <th>Plano</th>
                    <th class="num">Personas</th>
                    <th class="num">SS</th>
                    <th>Estado</th>
                    <th>Planilla en Simple</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
            @foreach($s->tandas as $t)
            @php
                $datosTanda = ['aliado_id' => $t->aliado_id, 'razon_social_id' => $t->razon_social_id, 'n_plano' => $t->n_plano,
                               'tipo_modalidad_id' => $t->tipo_modalidad_id, 'mes' => $mes, 'anio' => $anio];
                $numeroRegistro = $t->registro->numero_planilla ?? null;
                $valorRegistro = $t->registro?->valor_total ? (int) round($t->registro->valor_total) : null;
            @endphp
            @php $porPagar = isset($t->estados['liquidada']); @endphp
            <tr x-data="tandaX1(@js($datosTanda), @js($numeroRegistro), @js($valorRegistro), @js($t->otra_planilla->numero_planilla ?? null), @js($porPagar))">
                <td>
                    <strong>{{ \Illuminate\Support\Str::limit($t->razon_social, 34, '…') }}</strong>
                    <div class="bx1-meta">{{ $t->modalidad }}</div>
                    <details class="bx1-personas">
                        <summary>Personas</summary>
                        <div style="overflow-x:auto">
                        <table class="bx1-detalle">
                            <thead>
                                <tr>
                                    <th>Persona</th>
                                    <th class="num">Días</th>
                                    <th class="num">EPS</th>
                                    <th class="num">ARL</th>
                                    <th class="num">Caja</th>
                                    <th class="num" title="Un día de pensión: por si toca la doble planilla">Pensión (1 día)</th>
                                    <th class="num">Admon BryNex</th>
                                    <th class="num">Total</th>
                                    <th>Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($t->filas as $f)
                                <tr>
                                    <td style="white-space:nowrap">{{ $f->tipo_doc }} {{ $f->no_identifi }} · {{ nombre_oracion(trim($f->primer_nombre.' '.$f->primer_ape)) }}</td>
                                    <td class="num">{{ $f->num_dias }}</td>
                                    <td class="num">{{ $fmt($f->v_eps) }}</td>
                                    <td class="num">{{ $fmt($f->v_arl) }}</td>
                                    <td class="num">{{ $fmt($f->v_caja) }}</td>
                                    <td class="num">{{ $fmt($f->valor_afp) }}</td>
                                    <td class="num">{{ $fmt($s->admon_por_persona) }}</td>
                                    <td class="num"><strong>{{ $fmt($f->valor_ss + $s->admon_por_persona) }}</strong></td>
                                    <td><span class="bx1-chip {{ $f->estado }}">{{ $etiquetasEstado[$f->estado] ?? $f->estado }}</span></td>
                                </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td>Total</td>
                                    <td></td>
                                    <td class="num">{{ $fmt($t->filas->sum('v_eps')) }}</td>
                                    <td class="num">{{ $fmt($t->filas->sum('v_arl')) }}</td>
                                    <td class="num">{{ $fmt($t->filas->sum('v_caja')) }}</td>
                                    <td class="num">{{ $fmt($t->filas->sum('valor_afp')) }}</td>
                                    <td class="num">{{ $fmt($s->admon_por_persona * $t->personas) }}</td>
                                    <td class="num">{{ $fmt($t->valor_ss + $s->admon_por_persona * $t->personas) }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                        </div>
                    </details>
                </td>
                <td>
                    <a class="bx1-chip plano" target="_blank"
                       href="{{ route('admin.planos.index', ['anio' => $anio, 'mes' => $mes, 'razon_social_id' => $t->razon_social_id, 'n_plano' => $t->n_plano, 'estado_pago' => 'todas']) }}"
                       title="Abre el plano (cambie primero al aliado {{ $s->aliado_nombre }})">P{{ $t->n_plano }}</a>
                </td>
                <td class="num">{{ $t->personas }}</td>
                <td class="num">{{ $fmt($t->valor_ss) }}</td>
                <td>
                    @foreach($t->estados as $estado => $n)
                    <span class="bx1-chip {{ $estado }}">{{ $etiquetasEstado[$estado] ?? $estado }} · {{ $n }}</span>
                    @endforeach
                </td>
                <td style="font-family:monospace;font-size:.72rem">
                    @if($t->registro && ($porPagar || ! $t->abierta))
                        {{ $t->registro->numero_planilla }}
                        @if($t->registro->valor_total)<div class="bx1-meta" style="font-family:sans-serif">{{ $fmt($t->registro->valor_total) }}</div>@endif
                    @elseif($t->registro)
                        {{-- Planilla vieja que ya no se puede pagar (Simple revalida el subtipo 4) --}}
                        <span style="color:#94a3b8" title="Se liquidó en una ventana anterior y no se pagó: fuera de la ventana Simple no la deja pagar. Liquidar vuelve a intentarlo.">{{ $t->registro->numero_planilla }}</span>
                        <div class="bx1-meta" style="font-family:sans-serif">anterior, sin pagar</div>
                    @else
                        —
                    @endif
                    @if($t->otra_planilla)
                    <div style="font-family:sans-serif;margin-top:.3rem;background:#fff7ed;color:#9a3412;border-radius:6px;padding:.25rem .35rem;font-size:.66rem;line-height:1.35"
                         title="Liquidada con otro filtro (no por BryNex). Liquidar aquí está frenado para no duplicar.">
                        ⚠ Ya tiene la {{ $t->otra_planilla->numero_planilla }}
                        @if($t->otra_planilla->valor_total)({{ $fmt($t->otra_planilla->valor_total) }})@endif
                        del {{ \Carbon\Carbon::parse($t->otra_planilla->created_at)->format('d/m H:i') }}, no liquidada por BryNex. Revise en Simple si se pagó.
                    </div>
                    @endif
                    @foreach($t->pagos as $pg)
                    <div style="font-family:sans-serif;margin-top:.3rem;background:#f0fdf4;color:#166534;border-radius:6px;padding:.25rem .35rem;font-size:.66rem;line-height:1.35">
                        ✅ Pagada {{ \Carbon\Carbon::parse($pg->fecha_pago)->format('d/m') }} · {{ $fmt($pg->valor) }}
                        @if($pg->comprobante)<br>Comprobante {{ $pg->comprobante }}@endif
                        @if($pg->soporte_path)<br><a href="{{ route('brynex.x1.soporte_pago', $pg->id) }}" target="_blank">Ver comprobante</a>@endif
                    </div>
                    @endforeach
                </td>
                <td>
                    @if($t->abierta)
                    <div style="display:flex;gap:.3rem;flex-wrap:wrap">
                        <button type="button" class="bx1-btn primario" @click="liquidar()" :disabled="ocupado">
                            <span x-text="ocupado === 'liquidar' ? 'Liquidando…' : 'Liquidar'">Liquidar</span>
                        </button>
                        <template x-if="otraPlanilla">
                            <button type="button" class="bx1-btn suave" style="border-color:#fdba74;color:#9a3412"
                                    @click="liquidar(true)" :disabled="ocupado">No está pagada: liquidar igual</button>
                        </template>
                        <template x-if="urlPago">
                            <a class="bx1-btn verde" target="_blank" rel="noopener" :href="urlPago"
                               title="Enlace recién entregado por Simple: caduca en minutos">Pagar ahora</a>
                        </template>
                        <template x-if="numero && porPagar && !urlPago">
                            <a class="bx1-btn verde" target="_blank" :href="urls.pagar + encodeURIComponent(numero)">Pagar (PSE)</a>
                        </template>
                        <button type="button" class="bx1-btn suave" @click="abrirConfirmar = !abrirConfirmar">Confirmar</button>
                    </div>
                    @include('brynex.x1-pagos._confirmar')
                    @else
                        <span class="bx1-meta">Cerrada</span>
                    @endif
                    @include('brynex.x1-pagos._mensaje')
                </td>
            </tr>
            @endforeach
            </tbody>
        </table>
        </div>
    </div>
    @empty
        <div class="bx1-meta">No hay solicitudes en este período.</div>
    @endforelse
</div>

{{-- ── Administración X1 por aliado ──────────────────────────────── --}}
<div class="bx1-card">
    <h3>Administración X1 por persona</h3>
    <p class="bx1-ayuda">Lo que el aliado paga a BryNex por cada persona X1, además de la seguridad social. Vacío = el valor por defecto. Brygar no paga.</p>
    <form method="POST" action="{{ route('brynex.x1.admon') }}">
        @csrf
        <table class="bx1-tabla" style="max-width:520px">
            <tbody>
                <tr>
                    <td><strong>Por defecto</strong></td>
                    <td class="num"><input type="number" name="por_defecto" min="0" step="500" value="{{ $admonDefecto }}" style="width:110px;border:1px solid #cbd5e1;border-radius:6px;padding:.2rem .35rem;text-align:right"></td>
                </tr>
                @foreach($admonAliados as $a)
                <tr>
                    <td>{{ $a->aliado }}</td>
                    <td class="num">
                        @if($a->es_brygar)
                            <span class="bx1-meta">$0 (Brygar)</span>
                        @else
                            <input type="number" name="aliados[{{ $a->aliado_id }}]" min="0" step="500"
                                   value="{{ $a->propia ? $a->admon : '' }}" placeholder="{{ $admonDefecto }}"
                                   style="width:110px;border:1px solid #cbd5e1;border-radius:6px;padding:.2rem .35rem;text-align:right">
                        @endif
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        <button type="submit" class="bx1-btn primario" style="margin-top:.6rem">Guardar administración</button>
    </form>
</div>
@endif
@endsection

@push('scripts')
<script>
function tandaX1(datos, numero, valor, otra, porPagar, urls) {
    return {
        datos,
        // La tanda de un aliado usa las rutas de siempre; la planilla conjunta pasa las suyas.
        urls: urls || {
            liquidar: '{{ route('brynex.x1.liquidar') }}',
            confirmar: '{{ route('brynex.x1.confirmar') }}',
            pagar: '{{ route('brynex.x1.pagar') }}?aliado_id=' + datos.aliado_id + '&numero=',
        },
        numero,
        numeroConfirmar: porPagar ? (numero || '') : '',
        estadoConfirmar: 'pagada',
        valorConfirmar: porPagar ? (valor || '') : '',
        comprobanteConfirmar: '',
        fechaConfirmar: new Date().toLocaleDateString('en-CA'),
        observacionConfirmar: '',
        otraPlanilla: otra || null,
        urlPago: null,
        porPagar: !!porPagar,
        abrirConfirmar: false,
        ocupado: false,
        mensaje: '',
        ok: false,
        errores: [],
        async enviar(url, extra, accion) {
            this.ocupado = accion;
            this.mensaje = '';
            this.errores = [];
            try {
                // FormData para que el comprobante (foto/PDF) viaje con lo demás.
                const fd = new FormData();
                Object.entries(Object.assign({}, this.datos, extra)).forEach(([k, v]) => {
                    if (v !== null && v !== undefined && v !== '') fd.append(k, v);
                });
                const r = await fetch(url, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: fd,
                });
                const d = await r.json().catch(() => ({ ok: false, mensaje: 'Respuesta no válida (HTTP ' + r.status + ').' }));
                this.ok = !!d.ok;
                this.mensaje = d.mensaje || d.message || (r.ok ? 'Listo.' : 'Error HTTP ' + r.status);
                this.errores = d.errores || [];
                if (d.numero_planilla) { this.numero = d.numero_planilla; this.numeroConfirmar = d.numero_planilla; }
                if (d.valor_total) { this.valorConfirmar = Math.round(d.valor_total); }
                if (accion === 'liquidar') this.otraPlanilla = d.otra_planilla || null;
                if (accion === 'liquidar') this.urlPago = d.url_pago || null;
                // Solo hay qué pagar si quedó liquidada; esperando ventana o rechazada, no.
                if (accion === 'liquidar') this.porPagar = d.estado === 'liquidada';
                if (!r.ok && d.errors) { this.errores = Object.values(d.errors).flat(); }
                return d;
            } catch (e) {
                this.ok = false;
                this.mensaje = 'No se pudo completar: ' + e.message;
            } finally {
                this.ocupado = false;
            }
        },
        async liquidar(ignorarOtra = false) {
            const pregunta = ignorarOtra
                ? '¿Ya revisó en Simple que la planilla ' + this.otraPlanilla + ' NO está pagada? Si estuviera pagada, esas personas quedarían pagadas dos veces.'
                : '¿Liquidar esta tanda en Simple con la cuenta de BryNex?';
            if (!confirm(pregunta)) return;
            await this.enviar(this.urls.liquidar, ignorarOtra ? { ignorar_otra: 1 } : {}, 'liquidar');
        },
        async confirmar() {
            if (!this.numeroConfirmar) { this.ok = false; this.mensaje = 'Escriba el número de planilla.'; return; }
            if (!this.valorConfirmar) { this.ok = false; this.mensaje = 'Escriba el valor pagado.'; return; }
            const extra = {
                numero_planilla: this.numeroConfirmar, estado: this.estadoConfirmar, valor: this.valorConfirmar,
                comprobante: this.comprobanteConfirmar, fecha_pago: this.fechaConfirmar, observacion: this.observacionConfirmar,
            };
            const archivo = this.$refs.soporte?.files?.[0];
            if (archivo) extra.soporte = archivo;
            const d = await this.enviar(this.urls.confirmar, extra, 'confirmar');
            if (d && d.ok) setTimeout(() => location.reload(), 900);
        },
    };
}
</script>
@endpush
