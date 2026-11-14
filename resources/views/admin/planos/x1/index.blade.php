@extends('layouts.app')

@section('titulo', 'Pagos X1')
@section('modulo', 'Tipo E - X1 · Pagos por BryNex')

@push('styles')
<style>
.x1-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:1rem 1.1rem; margin-bottom:1rem; box-shadow:0 1px 3px rgba(0,0,0,.04); }
.x1-card h3 { margin:0 0 .25rem; font-size:.95rem; font-weight:800; color:#0f172a; }
.x1-ayuda { font-size:.76rem; color:#64748b; margin:0 0 .8rem; line-height:1.45; }
.x1-tabla { width:100%; border-collapse:collapse; font-size:.78rem; }
.x1-tabla th { text-align:left; font-size:.66rem; text-transform:uppercase; letter-spacing:.04em; color:#64748b; padding:.45rem .5rem; border-bottom:2px solid #e2e8f0; white-space:nowrap; }
.x1-tabla td { padding:.5rem; border-bottom:1px solid #f1f5f9; vertical-align:top; }
.x1-tabla td.num, .x1-tabla th.num { text-align:right; white-space:nowrap; font-variant-numeric:tabular-nums; }
.x1-tabla tfoot td { font-weight:800; border-top:2px solid #e2e8f0; border-bottom:none; }
.x1-chip { display:inline-block; border-radius:20px; padding:.08rem .5rem; font-size:.66rem; font-weight:700; white-space:nowrap; }
.x1-chip.en_solicitud { background:#fef3c7; color:#92400e; }
.x1-chip.pagada, .x1-chip.blindem, .x1-chip.doble_planilla { background:#dcfce7; color:#166534; }
.x1-chip.esperando_ventana { background:#ede9fe; color:#5b21b6; }
.x1-chip.plano { background:#e0f2fe; color:#0369a1; }
.x1-personas summary { cursor:pointer; color:#1d4ed8; font-size:.72rem; font-weight:600; }
.x1-personas ul { margin:.35rem 0 0; padding-left:1rem; font-size:.72rem; color:#334155; }
.x1-total-bar { display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; margin-top:.9rem; padding:.75rem .9rem; background:#f0f9ff; border:1px solid #bae6fd; border-radius:10px; }
.x1-total-bar .x1-total { font-size:1.15rem; font-weight:800; color:#0c4a6e; }
.x1-btn { display:inline-flex; align-items:center; gap:.35rem; padding:.5rem 1.1rem; border-radius:8px; border:none; background:#1e40af; color:#fff; font-weight:700; font-size:.8rem; cursor:pointer; }
.x1-btn:disabled { opacity:.5; cursor:not-allowed; }
.x1-vacio { text-align:center; color:#94a3b8; font-size:.8rem; padding:1.2rem 0; }
.x1-sol-head { display:flex; justify-content:space-between; align-items:flex-start; gap:.75rem; flex-wrap:wrap; margin-bottom:.5rem; }
.x1-sol-head .x1-meta { font-size:.72rem; color:#64748b; }
@media (max-width: 720px) {
    .x1-tabla .x1-ocultable { display:none; }
}
</style>
@endpush

@section('contenido')
@php
    $mesesNombre = ['', 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
    $fmt = fn ($v) => '$'.number_format((float) $v, 0, ',', '.');
@endphp

<div class="modulo-header">
    <div class="modulo-titulo">🧾 Tipo E - X1 · Pagos por BryNex</div>
    <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
        <form method="GET" action="{{ route('admin.planos.x1.index') }}" style="display:flex;gap:.4rem;align-items:center">
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
        <a href="{{ route('admin.planos.index', ['mes' => $mes, 'anio' => $anio]) }}"
           style="padding:.3rem .8rem;border-radius:8px;border:1px solid #cbd5e1;background:#f8fafc;color:#475569;font-size:.78rem;font-weight:700;text-decoration:none">
            ← Volver a Planos
        </a>
    </div>
</div>

@if($errors->any())
<div class="flash warning">{{ $errors->first() }}</div>
@endif

@if(! $activo)
<div class="x1-card" style="border-color:#fde68a;background:#fffbeb">
    <p class="x1-ayuda" style="margin:0;color:#92400e">La solicitud de pagos X1 a BryNex está apagada: por ahora las X1 se liquidan desde Planos, como antes.</p>
</div>
@endif

{{-- ── Por solicitar ─────────────────────────────────────────────── --}}
<div class="x1-card" x-data="x1Solicitud(@js($resumen->map(fn ($r) => ['id' => $r['razon_social_id'], 'total' => $r['total']])->values()))">
    <h3>Por solicitar · período de pago {{ $mesesNombre[$mes] }} {{ $anio }}</h3>
    <p class="x1-ayuda">
        Las personas de Tipo E - X1 no se liquidan desde Planos: las paga BryNex.
        Marque las empresas, consigne el total a Brygar y pegue la foto de la consignación.
        El valor lleva la seguridad social con el día de pensión
        @if($admonPorPersona > 0)
            y {{ $fmt($admonPorPersona) }} de administración X1 por persona.
        @else
            (Brygar no paga administración X1).
        @endif
        Si el plazo de la planilla ya venció, también va la mora.
        Al solicitar, esas personas pasan a su propio plano; el resto del plano sigue igual.
    </p>

    @if($resumen->isEmpty())
        <div class="x1-vacio">No hay personas X1 por solicitar en este período.</div>
    @else
    <form method="POST" action="{{ route('admin.planos.x1.solicitar') }}" enctype="multipart/form-data" @submit="enviando = true">
        @csrf
        <input type="hidden" name="mes" value="{{ $mes }}">
        <input type="hidden" name="anio" value="{{ $anio }}">

        <div style="overflow-x:auto">
        <table class="x1-tabla">
            <thead>
                <tr>
                    <th style="width:1.5rem"><input type="checkbox" @change="marcarTodas($event.target.checked)" :checked="todasMarcadas"></th>
                    <th>Razón social</th>
                    <th class="x1-ocultable">Plano</th>
                    <th class="num">Personas</th>
                    <th class="num x1-ocultable">SS + día pensión</th>
                    <th class="num x1-ocultable">Admon X1</th>
                    <th class="num x1-ocultable">Mora</th>
                    <th class="num">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($resumen as $r)
                <tr>
                    <td><input type="checkbox" name="razones_sociales[]" value="{{ $r['razon_social_id'] }}" x-model.number="marcadas" :value="{{ $r['razon_social_id'] }}"></td>
                    <td>
                        <strong>{{ $r['razon_social'] }}</strong>
                        <details class="x1-personas">
                            <summary>Ver personas</summary>
                            <ul>
                                @foreach($r['filas'] as $f)
                                <li>{{ $f->tipo_doc }} {{ $f->no_identifi }} · {{ nombre_oracion(trim($f->primer_nombre.' '.$f->primer_ape)) }}
                                    · {{ (int) $f->tipo_modalidad_id === \App\Models\TipoModalidad::ID_EX1_TP ? 'X1 TP14' : 'X1' }}
                                    · {{ $f->num_dias }} d · {{ $fmt($f->valor_ss) }}</li>
                                @endforeach
                            </ul>
                        </details>
                    </td>
                    <td class="x1-ocultable">
                        @foreach($r['planos'] as $n)<span class="x1-chip plano">P{{ $n }}</span> @endforeach
                    </td>
                    <td class="num">{{ $r['personas'] }}</td>
                    <td class="num x1-ocultable">{{ $fmt($r['valor_ss']) }}</td>
                    <td class="num x1-ocultable">{{ $fmt($r['valor_admon']) }}</td>
                    <td class="num x1-ocultable" style="{{ $r['valor_mora'] > 0 ? 'color:#b91c1c;font-weight:700' : '' }}">{{ $fmt($r['valor_mora']) }}</td>
                    <td class="num"><strong>{{ $fmt($r['total']) }}</strong></td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td></td>
                    <td>Todas las empresas</td>
                    <td class="x1-ocultable"></td>
                    <td class="num">{{ $resumen->sum('personas') }}</td>
                    <td class="num x1-ocultable">{{ $fmt($resumen->sum('valor_ss')) }}</td>
                    <td class="num x1-ocultable">{{ $fmt($resumen->sum('valor_admon')) }}</td>
                    <td class="num x1-ocultable">{{ $fmt($resumen->sum('valor_mora')) }}</td>
                    <td class="num">{{ $fmt($resumen->sum('total')) }}</td>
                </tr>
            </tfoot>
        </table>
        </div>

        <div class="x1-total-bar">
            <div>
                <div style="font-size:.68rem;font-weight:700;color:#0369a1;text-transform:uppercase;letter-spacing:.04em">A consignar (marcadas)</div>
                <div class="x1-total" x-text="formato(totalMarcado)">$0</div>
            </div>
            @unless($esBrygar)
            <label style="font-size:.76rem;color:#334155;display:flex;flex-direction:column;gap:.25rem">
                Foto de la consignación
                <input type="file" name="soporte" accept="image/*,application/pdf" required @change="conFoto = $event.target.files.length > 0">
            </label>
            @endunless
            <button type="submit" class="x1-btn"
                    :disabled="enviando || marcadas.length === 0 {{ $esBrygar ? '' : '|| !conFoto' }} {{ $activo ? '' : '|| true' }}">
                <span x-text="enviando ? 'Enviando…' : 'Solicitar pago a BryNex'">Solicitar pago a BryNex</span>
            </button>
        </div>
    </form>
    @endif
</div>

{{-- ── Mis solicitudes ───────────────────────────────────────────── --}}
<div class="x1-card">
    <h3>Mis solicitudes</h3>
    <p class="x1-ayuda">Lo que ya mandó a BryNex y en qué va cada persona. Cuando BryNex la paga, aparece el número de planilla.</p>

    @forelse($solicitudes as $s)
    <div style="border:1px solid #e2e8f0;border-radius:10px;padding:.7rem .8rem;margin-bottom:.7rem">
        <div class="x1-sol-head">
            <div>
                <strong>Solicitud #{{ $s->id }}</strong>
                <span class="x1-meta">· período {{ $mesesNombre[(int) $s->mes] ?? $s->mes }} {{ $s->anio }}
                    · {{ \Carbon\Carbon::parse($s->created_at)->format('d/m/Y H:i') }}
                    · {{ $s->personas }} {{ $s->personas == 1 ? 'persona' : 'personas' }}</span>
            </div>
            <div style="text-align:right">
                <strong>{{ $fmt($s->total) }}</strong>
                @if($s->soporte_path)
                    · <a href="{{ route('admin.planos.x1.soporte', $s->id) }}" target="_blank" style="font-size:.72rem">Ver consignación</a>
                @endif
                <div class="x1-meta">SS {{ $fmt($s->valor_ss) }} · admon {{ $fmt($s->valor_admon) }}@if($s->valor_mora > 0) · mora {{ $fmt($s->valor_mora) }}@endif</div>
            </div>
        </div>
        <div style="overflow-x:auto">
        <table class="x1-tabla">
            <thead>
                <tr>
                    <th>Razón social</th>
                    <th>Persona</th>
                    <th class="x1-ocultable">Plano</th>
                    <th class="num x1-ocultable">SS</th>
                    <th>Estado</th>
                    <th>Planilla</th>
                </tr>
            </thead>
            <tbody>
                @foreach($s->filas as $f)
                <tr>
                    <td>{{ \Illuminate\Support\Str::limit(trim($f->razon_social), 28, '…') }}</td>
                    <td>{{ $f->tipo_doc }} {{ $f->no_identifi }}<br><span style="color:#64748b">{{ nombre_oracion(trim($f->primer_nombre.' '.$f->primer_ape)) }}</span></td>
                    <td class="x1-ocultable">
                        @if((int) $f->n_plano_origen !== (int) $f->n_plano)
                            <span title="Estaba en el P{{ $f->n_plano_origen }}">P{{ $f->n_plano_origen }} → </span>
                        @endif
                        <span class="x1-chip plano">P{{ $f->n_plano }}</span>
                    </td>
                    <td class="num x1-ocultable">{{ $fmt($f->valor_ss) }}</td>
                    <td><span class="x1-chip {{ $f->estado }}">{{ $etiquetasEstado[$f->estado] ?? $f->estado }}</span></td>
                    <td style="font-family:monospace">{{ $f->numero_planilla ?: ($f->planilla_plano ?: '—') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </div>
    @empty
        <div class="x1-vacio">Todavía no ha mandado solicitudes.</div>
    @endforelse
</div>
@endsection

@push('scripts')
<script>
function x1Solicitud(razones) {
    return {
        razones,
        marcadas: razones.map(r => r.id),
        conFoto: false,
        enviando: false,
        get todasMarcadas() { return this.marcadas.length === this.razones.length; },
        get totalMarcado() {
            return this.razones.filter(r => this.marcadas.includes(r.id)).reduce((s, r) => s + Number(r.total || 0), 0);
        },
        marcarTodas(si) { this.marcadas = si ? this.razones.map(r => r.id) : []; },
        formato(v) { return '$' + Math.round(v).toLocaleString('es-CO'); },
    };
}
</script>
@endpush
