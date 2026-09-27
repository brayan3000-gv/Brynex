@php
    $fmt = fn ($v) => '$' . number_format((int) $v, 0, ',', '.');
    $f = $factura;
    $color = $aliado?->color_primario ?: '#1e40af';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 14px 16px; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { font-size: 9.5px; color: #0f172a; margin: 0; }
    .centro { text-align: center; }
    .logo { max-width: 120px; max-height: 46px; }
    .aliado { font-size: 11px; font-weight: bold; margin-top: 4px; }
    .tenue { color: #64748b; }
    .titulo { margin: 8px 0 2px; padding: 5px 0; border-top: 1.5px solid {{ $color }}; border-bottom: 1.5px solid {{ $color }};
        text-align: center; font-weight: bold; font-size: 11px; color: {{ $color }}; letter-spacing: .5px; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 2.2px 0; vertical-align: top; }
    td.v { text-align: right; white-space: nowrap; }
    .sec { margin-top: 8px; font-weight: bold; font-size: 8.5px; text-transform: uppercase; color: #475569; border-bottom: .6px solid #cbd5e1; padding-bottom: 2px; }
    .total td { border-top: 1px solid #0f172a; font-weight: bold; font-size: 10.5px; padding-top: 4px; }
    .pagado { margin-top: 10px; background: #ecfdf5; border: 1px solid #10b981; border-radius: 5px; padding: 6px 8px; }
    .pagado .monto { font-size: 15px; font-weight: bold; color: #047857; }
    .sello { color: #047857; font-weight: bold; font-size: 10px; }
    .pie { margin-top: 12px; font-size: 7.5px; color: #64748b; text-align: center; line-height: 1.4; }
</style>
</head>
<body>

<div class="centro">
    @if($logo)<img class="logo" src="{{ $logo }}">@endif
    <div class="aliado">{{ $aliado?->razon_social ?: $aliado?->nombre }}</div>
    @if($aliado?->nit)<div class="tenue">NIT {{ $aliado->nit }}</div>@endif
    @if($aliado?->celular || $aliado?->whatsapp)<div class="tenue">Cel. {{ $aliado->whatsapp ?: $aliado->celular }}</div>@endif
</div>

<div class="titulo">RECIBO DE PAGO N.º {{ $numero }}</div>
<table>
    <tr><td class="tenue">Fecha</td><td class="v">{{ \Carbon\Carbon::parse($fecha)->format('d/m/Y g:i a') }}</td></tr>
    <tr><td class="tenue">Cliente</td><td class="v">{{ $cliente?->nombre_completo }}</td></tr>
    <tr><td class="tenue">Cédula</td><td class="v">{{ $contrato->cedula }}</td></tr>
    <tr><td class="tenue">Razón social</td><td class="v">{{ \Illuminate\Support\Str::limit($contrato->razonSocial?->razon_social ?? '—', 34) }}</td></tr>
</table>

@if($f)
    <div class="sec">{{ $f->tipo === 'afiliacion' ? 'Afiliación' : 'Seguridad social' }} · {{ \App\Services\ReciboVisitaService::periodo((int) $f->mes, (int) $f->anio) }}</div>
    <table>
        @if((int) $f->v_eps)<tr><td>Salud (EPS)</td><td class="v">{{ $fmt($f->v_eps) }}</td></tr>@endif
        @if((int) $f->v_afp)<tr><td>Pensión</td><td class="v">{{ $fmt($f->v_afp) }}</td></tr>@endif
        @if((int) $f->v_arl)<tr><td>Riesgos (ARL)</td><td class="v">{{ $fmt($f->v_arl) }}</td></tr>@endif
        @if((int) $f->v_caja)<tr><td>Caja de compensación</td><td class="v">{{ $fmt($f->v_caja) }}</td></tr>@endif
        @if((int) $f->v_parafiscales)<tr><td>Parafiscales</td><td class="v">{{ $fmt($f->v_parafiscales) }}</td></tr>@endif
        @if((int) $f->afiliacion)<tr><td>Afiliación</td><td class="v">{{ $fmt($f->afiliacion) }}</td></tr>@endif
        @if((int) $f->admon + (int) $f->admin_asesor)<tr><td>Administración</td><td class="v">{{ $fmt((int) $f->admon + (int) $f->admin_asesor) }}</td></tr>@endif
        @if((int) $f->seguro)<tr><td>Seguro</td><td class="v">{{ $fmt($f->seguro) }}</td></tr>@endif
        @if((int) $f->iva)<tr><td>IVA</td><td class="v">{{ $fmt($f->iva) }}</td></tr>@endif
        @if((int) $f->mora)<tr><td>Intereses de mora</td><td class="v">{{ $fmt($f->mora) }}</td></tr>@endif
        @if((int) $f->otros + (int) $f->otros_admon)<tr><td>Otros</td><td class="v">{{ $fmt((int) $f->otros + (int) $f->otros_admon) }}</td></tr>@endif
        <tr class="total"><td>Total del mes</td><td class="v">{{ $fmt($f->total) }}</td></tr>
    </table>

    <div class="sec">Cómo se pagó</div>
    <table>
        @if((int) $f->valor_efectivo)<tr><td>Efectivo</td><td class="v">{{ $fmt($f->valor_efectivo) }}</td></tr>@endif
        @foreach($f->consignaciones as $cs)
            <tr><td>Transferencia{{ $cs->bancoCuenta ? ' · ' . $cs->bancoCuenta->banco : '' }}{{ $cs->referencia ? ' · ref ' . $cs->referencia : '' }}</td><td class="v">{{ $fmt($cs->valor) }}</td></tr>
        @endforeach
        @foreach($anticiposAplicados as $ap)
            <tr><td>Anticipo del {{ $ap->fecha_pago->format('d/m/Y') }}</td><td class="v">{{ $fmt(min((int) $ap->valor_aplicado, (int) $ap->valor)) }}</td></tr>
        @endforeach
        @if($favorAplicado > 0)<tr><td>Saldo a favor anterior</td><td class="v">{{ $fmt($favorAplicado) }}</td></tr>@endif
    </table>
    <div class="sello">✔ MES PAGADO</div>
@endif

@if($anticipos->isNotEmpty())
    <div class="sec">{{ $f ? 'Queda como anticipo' : 'Anticipo recibido' }}</div>
    <table>
        @foreach($anticipos as $a)
            <tr><td>{{ \App\Models\Anticipo::FORMAS_PAGO[$a->forma_pago] ?? $a->forma_pago }}{{ $a->bancoCuenta ? ' · ' . $a->bancoCuenta->banco : '' }}{{ $a->referencia ? ' · ref ' . $a->referencia : '' }}</td><td class="v">{{ $fmt($a->valor) }}</td></tr>
        @endforeach
    </table>
    <div class="tenue">Se descuenta de tu próximo pago.</div>
@endif

<div class="pagado">
    <div class="tenue">Recibimos hoy</div>
    <div class="monto">{{ $fmt($pagadoHoy) }}</div>
    @if($saldoAnticipo > 0)<div class="tenue">Anticipos a tu favor: {{ $fmt($saldoAnticipo) }}</div>@endif
</div>

<div class="pie">
    Recibió: {{ $usuario }}<br>
    Contrato {{ $contrato->id }} · {{ $contrato->tipoModalidad?->observacion }}<br>
    Conserva este recibo como soporte de tu pago.
</div>

</body>
</html>
