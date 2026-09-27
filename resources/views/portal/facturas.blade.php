@extends('layouts.portal')
@section('titulo', 'Facturas')

@php
    use App\Http\Controllers\Portal\PortalEmpresaController as P;
    $estado = [
        'pagada' => ['Pagada', 'chip-ok'],
        'abono' => ['Abono parcial', 'chip-warn'],
        'prestamo' => ['A crédito', 'chip-lila'],
        'pre_factura' => ['Pre-factura', 'chip-info'],
    ];
@endphp

@section('contenido')
<div class="pt-titulo aparece">
    <div>
        <h1>Facturas</h1>
        <p>Lo facturado mes a mes, en lote o a cada trabajador, y tu saldo con nosotros.</p>
    </div>
</div>

<div class="kpis kpis-2">
    <div class="tarjeta kpi {{ $saldo['pendiente'] > 0 ? 'destacado' : '' }} aparece" style="--i:1">
        <div class="et">@include('portal._icono', ['n' => 'reloj', 'clase' => 'ic-sm']) Saldo pendiente</div>
        <div class="valor num" x-data="contador({{ (int) $saldo['pendiente'] }})" x-text="texto"></div>
        <div class="nota">{{ $saldo['pendiente'] > 0 ? 'De facturas anteriores sin pagar completas' : 'Estás al día' }}</div>
    </div>
    <div class="tarjeta kpi aparece" style="--i:2">
        <div class="et">@include('portal._icono', ['n' => 'dinero', 'clase' => 'ic-sm']) Saldo a favor</div>
        <div class="valor num" style="color:{{ $saldo['favor'] > 0 ? '#047857' : 'inherit' }}" x-data="contador({{ (int) $saldo['favor'] }})" x-text="texto"></div>
        <div class="nota">Se descuenta de tus próximas facturas</div>
    </div>
</div>

@forelse($porMes as $i => $m)
    <section class="mes-bloque aparece" style="--i:{{ min($i + 3, 12) }}" x-data="{ abierto: {{ $i < 3 ? 'true' : 'false' }} }">
        <button type="button" class="mes-cab" @click="abierto = !abierto" :aria-expanded="abierto">
            <span class="punto"></span>
            <span class="mes-nombre">{{ P::MESES[$m['mes']] ?? $m['mes'] }} {{ $m['anio'] }}</span>
            <span class="sub">{{ $m['facturas']->count() }} {{ $m['facturas']->count() === 1 ? 'factura' : 'facturas' }}</span>
            <span class="num mes-total">${{ number_format($m['total'], 0, ',', '.') }}</span>
            <span class="flecha" :class="abierto && 'girada'">@include('portal._icono', ['n' => 'der', 'clase' => 'ic-sm'])</span>
        </button>
        <div class="tarjeta mes-facturas" x-show="abierto" x-cloak
             x-transition:enter="t-fade-enter" x-transition:enter-start="t-fade-from">
            @foreach($m['facturas'] as $f)
                @php [$et, $cl] = $estado[$f['estado']] ?? [ucfirst((string) $f['estado']), 'chip-gris']; @endphp
                <div class="factura">
                    <div class="fac-num">
                        <div style="font-weight:800">N.º {{ $f['numero'] }}</div>
                        <div class="sub">
                            @if($f['individual'])
                                <span class="chip chip-gris" style="margin-right:.2rem">Individual</span> {{ $f['individual'] }}
                            @else
                                {{ $f['personas'] }} {{ $f['personas'] === 1 ? 'persona' : 'personas' }}
                            @endif
                            @if($f['fecha_pago']) · {{ \Illuminate\Support\Carbon::parse($f['fecha_pago'])->format('d/m/Y') }} @endif
                        </div>
                    </div>
                    <span class="chip {{ $cl }}">{{ $et }}</span>
                    <div class="num fac-total">${{ number_format($f['total'], 0, ',', '.') }}</div>
                </div>
            @endforeach
        </div>
    </section>
@empty
    <div class="tarjeta vacio aparece" style="--i:3">
        @include('portal._icono', ['n' => 'facturas'])
        <div>Todavía no tienes facturas.</div>
    </div>
@endforelse
@endsection

@push('estilos')
<style>
    .kpis-2 { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; max-width: 640px; }
    .mes-bloque { margin-bottom: .7rem; }
    .mes-cab { width: 100%; display: flex; align-items: center; gap: .7rem; background: none; border: 0; font: inherit;
        color: inherit; padding: .6rem .2rem; cursor: pointer; text-align: left; }
    .mes-cab .punto { width: 10px; height: 10px; border-radius: 50%; background: var(--acento); box-shadow: 0 0 0 4px rgba(59,130,246,.15); flex-shrink: 0; }
    .mes-nombre { font-weight: 800; font-size: 1rem; }
    .mes-total { margin-left: auto; font-weight: 800; }
    .flecha { color: var(--tenue); transition: transform .2s; display: flex; }
    .flecha.girada { transform: rotate(90deg); }
    .mes-facturas { overflow: hidden; }
    .factura { display: grid; grid-template-columns: 1fr auto; gap: .3rem .8rem; align-items: center; padding: .8rem 1rem; border-bottom: 1px solid #f1f5f9; }
    .factura:last-child { border-bottom: 0; }
    .fac-total { grid-column: 1 / -1; font-weight: 700; font-size: 1.02rem; }
    .sub { font-size: .76rem; color: var(--tenue); }
    @media (min-width: 640px) {
        .factura { grid-template-columns: 1fr auto 150px; }
        .fac-total { grid-column: auto; text-align: right; }
    }
</style>
@endpush
