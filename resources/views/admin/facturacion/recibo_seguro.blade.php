@extends(request()->boolean('modal') ? 'layouts.modal' : 'layouts.app')
@section('modulo','Recibo de Pago · Seguro')

@php
use Carbon\Carbon;

$meses = ['Enero','Febrero','Marzo','Abril','Mayo','Junio',
          'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$fmt   = fn ($v) => '$'.number_format($v ?? 0, 0, ',', '.');

$contrato = $factura->contrato;
$cliente  = $contrato?->cliente;
$seguro   = $contrato?->seguroPlan;

$nombre = trim(($cliente->primer_nombre ?? '').' '.($cliente->segundo_nombre ?? '')
              .' '.($cliente->primer_apellido ?? '').' '.($cliente->segundo_apellido ?? ''));
$nombre = nombre_oracion($nombre);
$nombre = $nombre ?: ('C.C. '.$factura->cedula);

$aliadoObj  = \App\Models\Aliado::find($factura->aliado_id);
$logoAliado = $aliadoObj?->logo ? asset('storage/'.$aliadoObj->logo) : null;
$nomAliado  = $aliadoObj?->nombre ?? $aliadoObj?->razon_social ?? 'BryNex';

// Lo cobrado. La modalidad Seguros no lleva seguridad social ni administración, pero
// `otros` puede traer un ajuste manual puesto al facturar, y las facturas anteriores a
// que el contrato pasara a esta modalidad sí traen administración: se muestra cuando
// viene, para que las líneas sumen el total y no quede un hueco sin explicar.
$vSeguro = (int) ($factura->seguro ?? 0);
$vAdmon  = (int) ($factura->admon ?? 0) + (int) ($factura->admin_asesor ?? 0);
$vOtros  = (int) ($factura->otros ?? 0) + (int) ($factura->otros_admon ?? 0);
$vTotal  = (int) ($factura->total ?? 0);

$periodo = ($meses[($factura->mes ?? 1) - 1] ?? '').' '.($factura->anio ?? '');

$estadoLabel = match ($factura->estado) {
    'pagada'   => 'Pagada',
    'abono'    => 'Abono',
    'prestamo' => 'Préstamo',
    'anulada'  => 'Anulada',
    default    => ucfirst((string) $factura->estado),
};
$estadoColor = match ($factura->estado) {
    'pagada'   => ['#dcfce7', '#166534'],
    'abono'    => ['#fef3c7', '#92400e'],
    'prestamo' => ['#dbeafe', '#1e40af'],
    'anulada'  => ['#fee2e2', '#991b1b'],
    default    => ['#f1f5f9', '#475569'],
};
@endphp

@section('contenido')
<style>
.rs-wrap{max-width:760px;margin:0 auto;background:#fff;border-radius:14px;
         box-shadow:0 2px 14px rgba(0,0,0,.08);overflow:hidden}
.rs-head{background:linear-gradient(135deg,#0f172a,#1e3a5f);color:#fff;padding:1.2rem 1.5rem;
         display:grid;grid-template-columns:1fr auto auto;gap:1.2rem;align-items:center}
.rs-head .et{font-size:.58rem;font-weight:700;letter-spacing:.15em;color:#93c5fd;text-transform:uppercase}
.rs-num{font-size:1.9rem;font-weight:900;color:#fbbf24;letter-spacing:-.03em;line-height:1}
.rs-badge{display:inline-block;border-radius:999px;padding:.18rem .7rem;font-size:.7rem;font-weight:800;margin-top:.35rem}
.rs-body{padding:1.3rem 1.5rem}
.rs-datos{display:grid;grid-template-columns:1fr 1fr;gap:.5rem 1.5rem;margin-bottom:1.3rem}
.rs-dato{border-bottom:1px dotted #e2e8f0;padding-bottom:.35rem}
.rs-dato .k{font-size:.62rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em}
.rs-dato .v{font-size:.88rem;font-weight:700;color:#0f172a}
.rs-tabla{width:100%;border-collapse:collapse;margin-bottom:1.1rem}
.rs-tabla th{background:#f8fafc;color:#475569;font-size:.64rem;text-transform:uppercase;letter-spacing:.05em;
             text-align:left;padding:.5rem .8rem;border-bottom:2px solid #e2e8f0}
.rs-tabla td{padding:.65rem .8rem;border-bottom:1px solid #f1f5f9;font-size:.85rem;color:#0f172a}
.rs-tabla td.num{text-align:right;font-weight:700;white-space:nowrap}
.rs-tabla .cubre{font-size:.72rem;color:#64748b;margin-top:.2rem}
.rs-total{background:#0f172a;color:#fff;border-radius:10px;padding:.8rem 1.1rem;
          display:flex;align-items:center;justify-content:space-between}
.rs-total .lb{font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#93c5fd}
.rs-total .vl{font-size:1.5rem;font-weight:900;color:#fbbf24}
.rs-pago{margin-top:1rem;font-size:.76rem;color:#475569;background:#f8fafc;border:1px solid #e2e8f0;
         border-radius:10px;padding:.7rem .9rem;line-height:1.7}
.rs-nota{margin-top:1rem;font-size:.68rem;color:#94a3b8;line-height:1.6;text-align:center}
.rs-acciones{max-width:760px;margin:0 auto 1rem;display:flex;gap:.5rem;justify-content:flex-end}
.btn-imp{background:#2563eb;color:#fff;border:none;padding:.45rem 1rem;border-radius:8px;
         font-size:.8rem;font-weight:600;cursor:pointer}
@media print{
    .rs-acciones{display:none}
    .rs-wrap{box-shadow:none;max-width:100%}
}
</style>

<div class="rs-acciones">
    <button class="btn-imp" onclick="window.print()">🖨 Imprimir</button>
</div>

<div class="rs-wrap">
    <div class="rs-head">
        <div>
            <div class="et">Recibo de Pago</div>
            <div style="font-size:1.05rem;font-weight:800;margin-top:.2rem">{{ strtoupper($nombre) }}</div>
            <div style="font-size:.72rem;color:rgba(255,255,255,.6)">C.C. {{ number_format($factura->cedula, 0, ',', '.') }}</div>
        </div>
        <div style="text-align:center">
            <div class="et">N°</div>
            <div class="rs-num">{{ str_pad($factura->numero_factura ?? $factura->id, 6, '0', STR_PAD_LEFT) }}</div>
            <span class="rs-badge" style="background:{{ $estadoColor[0] }};color:{{ $estadoColor[1] }}">{{ $estadoLabel }}</span>
        </div>
        <div style="text-align:center">
            <img src="{{ $logoAliado ?? asset('img/logo-brynex-claro.png') }}" alt="{{ $nomAliado }}"
                 style="max-width:130px;max-height:64px;object-fit:contain">
            <div style="font-size:.55rem;color:rgba(255,255,255,.6);margin-top:.3rem;font-weight:600;letter-spacing:.04em">
                {{ strtoupper($nomAliado) }}
            </div>
        </div>
    </div>

    <div class="rs-body">
        <div class="rs-datos">
            <div class="rs-dato">
                <div class="k">Período</div>
                <div class="v">{{ $periodo }}</div>
            </div>
            <div class="rs-dato">
                <div class="k">Fecha de pago</div>
                <div class="v">{{ $factura->fecha_pago ? Carbon::parse($factura->fecha_pago)->format('d/m/Y') : '—' }}</div>
            </div>
            <div class="rs-dato">
                <div class="k">Forma de pago</div>
                <div class="v">{{ ucfirst($factura->forma_pago ?? '—') }}</div>
            </div>
            <div class="rs-dato">
                <div class="k">Recibido por</div>
                <div class="v">{{ $factura->usuario?->nombre ?? '—' }}</div>
            </div>
        </div>

        <table class="rs-tabla">
            <thead>
                <tr>
                    <th>Concepto</th>
                    <th style="text-align:right;width:28%">Valor</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <strong>{{ $seguro?->nombre ?? 'Seguro' }}</strong>
                        @if($seguro?->descripcion)
                        <div class="cubre">{{ $seguro->descripcion }}</div>
                        @endif
                    </td>
                    <td class="num">{{ $fmt($vSeguro) }}</td>
                </tr>
                @if($vAdmon > 0)
                <tr>
                    <td>Administración</td>
                    <td class="num">{{ $fmt($vAdmon) }}</td>
                </tr>
                @endif
                @if($vOtros > 0)
                <tr>
                    <td>Otros conceptos</td>
                    <td class="num">{{ $fmt($vOtros) }}</td>
                </tr>
                @endif
            </tbody>
        </table>

        <div class="rs-total">
            <span class="lb">Total pagado</span>
            <span class="vl">{{ $fmt($vTotal) }}</span>
        </div>

        @if($factura->consignaciones && $factura->consignaciones->count())
        <div class="rs-pago">
            @foreach($factura->consignaciones as $cons)
            <div>💳 Consignación {{ $fmt($cons->valor) }}
                 @if($cons->bancoCuenta) · {{ $cons->bancoCuenta->banco }} {{ $cons->bancoCuenta->numero_cuenta }} @endif
                 @if($cons->fecha_pago) · {{ Carbon::parse($cons->fecha_pago)->format('d/m/Y') }} @endif
            </div>
            @endforeach
        </div>
        @endif

        @if($factura->observacion)
        <div class="rs-pago">📝 {{ $factura->observacion }}</div>
        @endif

        <div class="rs-nota">
            Este recibo corresponde únicamente al seguro contratado. No incluye aportes a
            seguridad social: {{ strtoupper($nombre) }} no está afiliado a EPS, ARL, pensión
            ni caja de compensación a través de {{ $nomAliado }}.
        </div>
    </div>
</div>
@endsection
