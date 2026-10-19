@extends(request()->boolean('modal') ? 'layouts.modal' : 'layouts.app')
@section('modulo', 'Recibo de Pago')

{{-- Recibo de un solo pago repartido en varios períodos de un cliente
     (PrestamosController::abonarCliente). Mismo formato que el recibo de un
     abono, con una línea por período. --}}
@php
$primero = $abonos->first();
$cliente = $primero->factura->contrato?->cliente;
$meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$fmt = fn($v) => '$' . number_format($v ?? 0, 0, ',', '.');
$total = (int) $abonos->sum('valor');
$queda = (int) $abonos->sum(fn ($a) => $a->factura->saldo_pendiente_prestamo);
$formas = $abonos->pluck('forma_pago')->unique();
$forma = $formas->count() > 1 ? 'Mixto' : ucfirst($formas->first());
@endphp

@section('contenido')
<style>
@page { size: letter portrait; margin: 12mm; }
@media print {
    .no-print, .sidebar, nav, header { display:none!important; }
    .ab-wrap { box-shadow:none!important;border:none!important;margin-top:0!important; }
}
.ab-wrap { max-width:520px;margin:1.5rem auto;background:#fff;border-radius:12px;border:2px solid #0f172a;padding:1.5rem 2rem;font-size:0.85rem; }
.ab-title{ text-align:center;font-size:1.3rem;font-weight:900;letter-spacing:0.06em;color:#0f172a;border-bottom:2px solid #0f172a;padding-bottom:0.6rem;margin-bottom:0.8rem; }
.ab-sub  { text-align:center;font-size:0.72rem;color:#64748b;margin-top:-0.4rem;margin-bottom:0.8rem; }
.ab-row  { display:flex;justify-content:space-between;padding:0.25rem 0;border-bottom:1px solid #f1f5f9;font-size:0.83rem; }
.ab-lbl  { color:#64748b; }
.ab-val  { font-weight:700;color:#0f172a; }
.ab-tbl  { width:100%;border-collapse:collapse;margin-top:0.7rem;font-size:0.8rem; }
.ab-tbl th { text-align:left;font-size:0.65rem;text-transform:uppercase;color:#64748b;border-bottom:1.5px solid #0f172a;padding:0.3rem 0.2rem; }
.ab-tbl td { padding:0.3rem 0.2rem;border-bottom:1px solid #f1f5f9; }
.ab-tbl .r { text-align:right;font-family:monospace; }
.ab-total{ display:flex;justify-content:space-between;font-size:1.1rem;font-weight:900;border-top:2px solid #0f172a;margin-top:0.4rem;padding-top:0.5rem; }
.ab-saldo{ background:#f8fafc;border-radius:8px;padding:0.45rem 0.6rem;text-align:center;margin-top:0.8rem; }
.ab-slbl { font-size:0.62rem;text-transform:uppercase;color:#64748b;font-weight:700; }
.ab-sval { font-size:0.95rem;font-weight:800;margin-top:0.1rem; }
.nota    { font-size:0.68rem;color:#94a3b8;margin-top:1rem;text-align:center; }
</style>

<div class="no-print" style="text-align:center;margin-bottom:1rem;">
    <button onclick="window.print()" style="padding:0.5rem 1.3rem;background:#0f172a;color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;">🖨 Imprimir</button>
    <a href="{{ route('admin.prestamos.index') }}" style="margin-left:1rem;color:#64748b;font-size:0.82rem;">← Volver a Préstamos</a>
</div>

<div class="ab-wrap">
    <div class="ab-title">RECIBO DE PAGO DE CARTERA</div>
    <div class="ab-sub">BRYGAR — Asesores en Seguridad Social</div>

    <div class="ab-row"><span class="ab-lbl">Abonos</span><span class="ab-val">{{ $abonos->map(fn ($a) => '#' . str_pad($a->id, 5, '0', STR_PAD_LEFT))->join(', ') }}</span></div>
    <div class="ab-row"><span class="ab-lbl">Fecha</span><span class="ab-val">{{ $primero->fecha?->format('d/m/Y') }}</span></div>
    <div class="ab-row"><span class="ab-lbl">Cédula</span><span class="ab-val">{{ $primero->factura->cedula }}</span></div>
    <div class="ab-row"><span class="ab-lbl">Trabajador</span><span class="ab-val">{{ nombre_oracion($cliente?->nombre_completo) }}</span></div>
    <div class="ab-row"><span class="ab-lbl">Forma de pago</span><span class="ab-val">{{ $forma }}</span></div>

    <table class="ab-tbl">
        <thead><tr><th>Período</th><th>Factura</th><th class="r">Abonado</th></tr></thead>
        <tbody>
        @foreach($abonos as $a)
            <tr>
                <td>{{ $meses[$a->factura->mes] }} {{ $a->factura->anio }}</td>
                <td>#{{ str_pad($a->factura->numero_factura, 6, '0', STR_PAD_LEFT) }}</td>
                <td class="r">{{ $fmt($a->valor) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="ab-total">
        <span>TOTAL PAGADO</span>
        <span style="color:#1d4ed8">{{ $fmt($total) }}</span>
    </div>

    <div class="ab-saldo">
        <div class="ab-slbl">Saldo pendiente de estos períodos</div>
        <div class="ab-sval" style="color:{{ $queda > 0 ? '#dc2626' : '#16a34a' }}">{{ $fmt($queda) }}</div>
    </div>

    <div style="display:flex;justify-content:space-between;margin-top:2rem;gap:2rem;">
        <div style="flex:1;text-align:center;border-top:1px solid #0f172a;padding-top:0.3rem;font-size:0.72rem;color:#64748b;">
            FIRMA DEL CLIENTE
        </div>
        <div style="flex:1;text-align:center;border-top:1px solid #0f172a;padding-top:0.3rem;font-size:0.72rem;color:#64748b;">
            RECIBIDO POR<br><strong>{{ $primero->usuario?->nombre ?? '—' }}</strong>
        </div>
    </div>

    <div class="nota">Pago de cartera de préstamos ya facturados: no genera factura nueva.
        @if($queda > 0) No exonera del saldo pendiente.@endif</div>
</div>
@endsection
