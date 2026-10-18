@extends(request()->boolean('modal') ? 'layouts.modal' : 'layouts.app')
@section('modulo', 'Recibo de Pago')

@php
$factura = $abono->factura;
$cliente = $factura->contrato?->cliente;
$meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$fmt = fn($v) => '$' . number_format($v ?? 0, 0, ',', '.');
$totalAbonado = $factura->abonos->sum('valor');
// El préstamo ya se facturó al prestar: su pago es un pago de cartera y lleva
// el número de la factura electrónica que lo respalda, si ya salió.
$esCartera = (bool) $factura->es_prestamo;
$facturaElectronica = $esCartera
    ? \App\Models\DataicoEnvio::where('aliado_id', $factura->aliado_id)
        ->where('numero_factura', $factura->numero_factura)
        ->where('estado', 'enviado')->value('dataico_numero')
    : null;
@endphp

@section('contenido')
<style>
/* Hoja CARTA VERTICAL: el recibo queda arriba y la mitad inferior en blanco. */
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
.ab-total{ font-size:1.1rem;font-weight:900;border-top:2px solid #0f172a;margin-top:0.4rem;padding-top:0.5rem; }
.ab-saldo{ display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;margin-top:0.8rem; }
.ab-sbox { background:#f8fafc;border-radius:8px;padding:0.45rem 0.6rem;text-align:center; }
.ab-slbl { font-size:0.62rem;text-transform:uppercase;color:#64748b;font-weight:700; }
.ab-sval { font-size:0.95rem;font-weight:800;margin-top:0.1rem; }
.nota    { font-size:0.68rem;color:#94a3b8;margin-top:1rem;text-align:center; }
</style>

<div class="no-print" style="text-align:center;margin-bottom:1rem;">
    <button onclick="window.print()" style="padding:0.5rem 1.3rem;background:#0f172a;color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;">🖨 Imprimir</button>
    <a href="{{ url()->previous() }}" style="margin-left:1rem;color:#64748b;font-size:0.82rem;">← Volver</a>
</div>

<div class="ab-wrap">
    <div class="ab-title">{{ $esCartera ? 'RECIBO DE PAGO DE CARTERA' : 'RECIBO DE ABONO' }}</div>
    <div class="ab-sub">BRYGAR — Asesores en Seguridad Social</div>

    <div class="ab-row"><span class="ab-lbl">Nº Abono</span><span class="ab-val">#{{ str_pad($abono->id, 5, '0', STR_PAD_LEFT) }}</span></div>
    <div class="ab-row"><span class="ab-lbl">Fecha</span><span class="ab-val">{{ $abono->fecha?->format('d/m/Y') }}</span></div>
    <div class="ab-row"><span class="ab-lbl">Factura asociada</span><span class="ab-val">#{{ str_pad($factura->numero_factura, 6, '0', STR_PAD_LEFT) }}</span></div>
    @if($facturaElectronica)
    <div class="ab-row"><span class="ab-lbl">Factura electrónica</span><span class="ab-val">{{ $facturaElectronica }}</span></div>
    @endif
    <div class="ab-row"><span class="ab-lbl">Período</span><span class="ab-val">{{ $meses[$factura->mes] }} {{ $factura->anio }}</span></div>
    <div class="ab-row"><span class="ab-lbl">Cédula</span><span class="ab-val">{{ $factura->cedula }}</span></div>
    <div class="ab-row"><span class="ab-lbl">Trabajador</span><span class="ab-val">{{ $cliente?->nombres }} {{ $cliente?->apellidos }}</span></div>
    <div class="ab-row"><span class="ab-lbl">Forma de pago</span><span class="ab-val">{{ ucfirst($abono->forma_pago) }}</span></div>
    @if($abono->observacion)
    <div class="ab-row"><span class="ab-lbl">Observación</span><span class="ab-val">{{ $abono->observacion }}</span></div>
    @endif

    <div class="ab-row ab-total">
        <span>VALOR ABONADO</span>
        <span style="color:#1d4ed8">{{ $fmt($abono->valor) }}</span>
    </div>

    <div class="ab-saldo">
        <div class="ab-sbox">
            <div class="ab-slbl">Total Factura</div>
            <div class="ab-sval">{{ $fmt($factura->total) }}</div>
        </div>
        <div class="ab-sbox">
            <div class="ab-slbl">Saldo Restante</div>
            <div class="ab-sval" style="color:{{ $factura->saldo_restante > 0 ? '#dc2626' : '#16a34a' }}">
                {{ $fmt($factura->saldo_restante) }}
            </div>
        </div>
    </div>

    <div style="display:flex;justify-content:space-between;margin-top:2rem;gap:2rem;">
        <div style="flex:1;text-align:center;border-top:1px solid #0f172a;padding-top:0.3rem;font-size:0.72rem;color:#64748b;">
            FIRMA DEL CLIENTE
        </div>
        <div style="flex:1;text-align:center;border-top:1px solid #0f172a;padding-top:0.3rem;font-size:0.72rem;color:#64748b;">
            RECIBIDO POR<br><strong>{{ $abono->usuario?->nombre ?? '—' }}</strong>
        </div>
    </div>

    @if($esCartera)
    <div class="nota">Pago de cartera de un préstamo ya facturado: no genera factura nueva.
        @if($factura->saldo_restante > 0) No exonera del saldo pendiente.@endif</div>
    @else
    <div class="nota">Este documento es constancia de abono parcial, no exonera de deuda pendiente.</div>
    @endif
</div>
@endsection
