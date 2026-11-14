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
        <strong>Liquidar</strong> entra a Simple con la cuenta de BryNex. Si Simple rechaza el subtipo 4 a una parte (eo.val.2.655),
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
            @endphp
            <tr x-data="tandaX1(@js($datosTanda), @js($numeroRegistro))">
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
                    @if($t->registro)
                        {{ $t->registro->numero_planilla }}
                        @if($t->registro->valor_total)<div class="bx1-meta" style="font-family:sans-serif">{{ $fmt($t->registro->valor_total) }}</div>@endif
                    @else
                        —
                    @endif
                </td>
                <td>
                    @if($t->abierta)
                    <div style="display:flex;gap:.3rem;flex-wrap:wrap">
                        <button type="button" class="bx1-btn primario" @click="liquidar()" :disabled="ocupado">
                            <span x-text="ocupado === 'liquidar' ? 'Liquidando…' : 'Liquidar'">Liquidar</span>
                        </button>
                        <template x-if="numero">
                            <a class="bx1-btn verde" target="_blank"
                               :href="'{{ route('brynex.x1.pagar') }}?aliado_id={{ $t->aliado_id }}&numero=' + encodeURIComponent(numero)">Pagar (PSE)</a>
                        </template>
                        <button type="button" class="bx1-btn suave" @click="abrirConfirmar = !abrirConfirmar">Confirmar</button>
                    </div>
                    <div class="bx1-confirmar" x-show="abrirConfirmar" x-cloak>
                        <input type="text" x-model="numeroConfirmar" placeholder="N° planilla real" style="width:120px">
                        <select x-model="estadoConfirmar">
                            <option value="pagada">Pagada (Simple)</option>
                            <option value="doble_planilla">Doble planilla</option>
                            <option value="blindem">Blindem</option>
                        </select>
                        <button type="button" class="bx1-btn verde" @click="confirmar()" :disabled="ocupado">
                            <span x-text="ocupado === 'confirmar' ? 'Guardando…' : 'Guardar'">Guardar</span>
                        </button>
                    </div>
                    @else
                        <span class="bx1-meta">Cerrada</span>
                    @endif
                    <div class="bx1-msg" :class="ok ? 'ok' : 'error'" x-show="mensaje" x-cloak>
                        <span x-text="mensaje"></span>
                        <template x-if="errores.length">
                            <ul style="margin:.25rem 0 0;padding-left:1rem">
                                <template x-for="e in errores"><li x-text="e"></li></template>
                            </ul>
                        </template>
                    </div>
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
@endsection

@push('scripts')
<script>
function tandaX1(datos, numero) {
    return {
        datos,
        numero,
        numeroConfirmar: numero || '',
        estadoConfirmar: 'pagada',
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
                const r = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                               'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                    body: JSON.stringify(Object.assign({}, this.datos, extra)),
                });
                const d = await r.json().catch(() => ({ ok: false, mensaje: 'Respuesta no válida (HTTP ' + r.status + ').' }));
                this.ok = !!d.ok;
                this.mensaje = d.mensaje || d.message || (r.ok ? 'Listo.' : 'Error HTTP ' + r.status);
                this.errores = d.errores || [];
                if (d.numero_planilla) { this.numero = d.numero_planilla; this.numeroConfirmar = d.numero_planilla; }
                return d;
            } catch (e) {
                this.ok = false;
                this.mensaje = 'No se pudo completar: ' + e.message;
            } finally {
                this.ocupado = false;
            }
        },
        async liquidar() {
            if (!confirm('¿Liquidar esta tanda en Simple con la cuenta de BryNex?')) return;
            await this.enviar('{{ route('brynex.x1.liquidar') }}', {}, 'liquidar');
        },
        async confirmar() {
            if (!this.numeroConfirmar) { this.ok = false; this.mensaje = 'Escriba el número de planilla.'; return; }
            const d = await this.enviar('{{ route('brynex.x1.confirmar') }}',
                { numero_planilla: this.numeroConfirmar, estado: this.estadoConfirmar }, 'confirmar');
            if (d && d.ok) setTimeout(() => location.reload(), 900);
        },
    };
}
</script>
@endpush
