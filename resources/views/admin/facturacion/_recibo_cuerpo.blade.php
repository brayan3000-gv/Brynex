@php
// Copia de la EMPRESA (modo doble copia). En ella se omiten la nota legal y
// el "Resumen Financiero": el aviso es para el cliente y el resumen queda
// reemplazado por el desglose completo de _recibo_desglose_empresa.
$esCopiaEmp = ($copia ?? 'cliente') === 'empresa';
@endphp
{{-- ══ RECIBO EMPRESARIAL (modo NP) ═════════════════════════════════════ --}}
@if($esGrupo)
@php
$aliadoGObj  = \App\Models\Aliado::find($factura->aliado_id);
$logoAliadoG = $aliadoGObj?->logo ? asset('storage/'.$aliadoGObj->logo) : null;
$nomAliadoG  = $aliadoGObj?->nombre ?? $aliadoGObj?->razon_social ?? 'BryNex';
$numGrupo    = str_pad($filas->first()?->numero_factura ?? $factura->numero_factura, 6, '0', STR_PAD_LEFT);
@endphp

{{-- HEADER 3 COLUMNAS (igual que individual) --}}
<div class="recibo-inner-wrap">
<div class="fact-header" style="position:relative;overflow:hidden;border-radius:6px 6px 0 0;border:1px solid #e2e8f0;">

    {{-- Sello diagonal --}}
    <div class="fact-sello-wrap">
        <div class="fact-sello {{ $estadoCls($estadoVisual) === 'badge-pago' ? 'sello-pagado' : ($estadoCls($estadoVisual) === 'badge-prest' ? 'sello-prest' : ($estadoCls($estadoVisual) === 'badge-abono' ? 'sello-abono' : 'sello-pre')) }}">
            {{ $estadoLabel($estadoVisual) }}
        </div>
    </div>

    {{-- Col 1: Empresa --}}
    <div class="fact-h-empresa">
        @if($empresaObj)
            <div style="font-size:.55rem;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.07em;margin-bottom:.04rem">Empresa</div>
            <div style="font-size:1.4rem;font-weight:900;color:#0f172a;line-height:1.15;letter-spacing:-.02em">{{ $empresaObj->empresa }}</div>
            <div style="font-size:.65rem;color:#64748b;margin-top:.05rem">NIT: {{ $empresaObj->nit ?? '—' }}</div>
            {{-- Datos de entrega: los usa el mensajero que lleva el recibo --}}
            @php
                $entTel  = collect([$empresaObj->celular, $empresaObj->telefono])->filter()->implode(' / ');
                $entCont = collect([$empresaObj->contacto, $entTel])->filter()->implode(' — ');
            @endphp
            @if($entCont || $empresaObj->direccion)
            <div style="font-size:.63rem;color:#64748b;margin-top:.12rem;line-height:1.45">
                @if($entCont)<div>{{ $entCont }}</div>@endif
                @if($empresaObj->direccion)<div>{{ $empresaObj->direccion }}</div>@endif
            </div>
            @endif
        @else
            {{-- Sin empresa_id: el recibo va a nombre de la persona (o de la
                 razón social compartida si el lote trae varios sin empresa). --}}
            @php
                $rsUno   = $filas->count() === 1 ? ($filas->first()->contrato?->razonSocial?->razon_social ?? null) : null;
                $etqCol1 = $filas->count() === 1 ? 'Afiliado' : 'Razón Social';
            @endphp
            <div style="font-size:.55rem;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.07em;margin-bottom:.04rem">{{ $etqCol1 }}</div>
            <div style="font-size:1.4rem;font-weight:900;color:#0f172a;line-height:1.15;letter-spacing:-.02em">{{ $tituloPersona ?? '—' }}</div>
            @if($subtituloPersona)
            <div style="font-size:.65rem;color:#64748b;margin-top:.05rem">{{ $subtituloPersona }}</div>
            @endif
            @if($filas->count() === 1)
                <div style="margin-top:.22rem">
                @if($rsUno)
                    <span style="font-size:.6rem;font-weight:800;color:#1d4ed8;background:#eff6ff;border:1px solid #bfdbfe;padding:.12rem .45rem;border-radius:20px;text-transform:uppercase;letter-spacing:.05em;display:inline-block">Dependiente &middot; {{ $rsUno }}</span>
                @else
                    <span style="font-size:.6rem;font-weight:800;color:#15803d;background:#f0fdf4;border:1px solid #bbf7d0;padding:.12rem .45rem;border-radius:20px;text-transform:uppercase;letter-spacing:.05em;display:inline-block">Independiente</span>
                @endif
                </div>
            @endif
        @endif
        <div style="font-size:.68rem;color:#64748b;margin-top:.3rem;display:flex;gap:.8rem;align-items:center">
            <span>Fecha: <strong style="color:#0f172a">{{ sqldate($factura->fecha_pago)->format('d/m/Y') }}</strong></span>
            @if($factura->np)
            <span style="background:#1e3a5f;color:#93c5fd;font-size:.6rem;font-weight:800;padding:.1rem .45rem;border-radius:20px">NP {{ $factura->np }}</span>
            @endif
        </div>
    </div>

    {{-- Col 2: Número de recibo --}}
    <div class="fact-h-recibo">
        <div style="font-size:.58rem;font-weight:700;letter-spacing:.15em;color:#93c5fd;text-transform:uppercase;margin-bottom:.18rem">Recibo de Pago</div>
        <div style="font-size:2rem;font-weight:900;color:#fbbf24;letter-spacing:-.03em;line-height:1">
            {{ $numGrupo }}
        </div>
        <div style="margin-top:.35rem">
            <span class="badge {{ $estadoCls($estadoVisual) }}">{{ $estadoLabel($estadoVisual) }}</span>
        </div>
    </div>

    {{-- Col 3: Logo aliado --}}
    <div class="fact-h-logo">
        @if($logoAliadoG)
        <img src="{{ $logoAliadoG }}" alt="{{ $nomAliadoG }}" style="max-width:140px;max-height:70px;object-fit:contain">
        @else
        <img src="{{ asset('img/logo-brynex.png') }}" alt="BryNex" style="max-width:140px;max-height:70px;object-fit:contain">
        @endif
        <div style="font-size:.55rem;color:#64748b;text-align:center;margin-top:.35rem;font-weight:600;letter-spacing:.04em">
            {{ strtoupper($nomAliadoG) }}
        </div>
    </div>

</div>{{-- /fact-header --}}
</div>{{-- /recibo-inner-wrap --}}

{{-- CUERPO --}}
<div class="recibo-inner">

{{-- ALERTA PRÉSTAMO --}}
@if($totPrest > 0 || $factura->estado === 'prestamo')
<div class="alerta-prest">
    <span style="font-size:1.3rem">💳</span>
    <div>
        <div style="font-weight:700;color:#6d28d9;font-size:.84rem">Préstamo pendiente de cobro</div>
        <div style="font-size:.77rem;color:#7c3aed">
            Total: <strong>{{ $fmt($totTotal) }}</strong> &middot;
            Recibido: <strong>{{ $fmt($totTotal - $totPrest) }}</strong> &middot;
            Pendiente: <strong>{{ $fmt($totPrest) }}</strong>
        </div>
    </div>
</div>
@endif

{{-- TABLA TRABAJADORES --}}
{{-- Cuando el recibo es de una sola persona, su nombre, cédula y razón
     social ya salen en el encabezado: repetirlos en la tabla solo roba
     ancho a las entidades. Se omiten las columnas No / Nombre / RS. --}}
@php $unaPersona = ($filas->count() === 1 && !$empresaObj); @endphp
@php $esTramiteG = $filas->count() === 1 && $filas->first()->tipo === 'otro_ingreso'; @endphp
<div class="fact-section-title">
    @if($esTramiteG) DETALLE DEL TRÁMITE
    @elseif($unaPersona) DETALLE DE LA LIQUIDACIÓN
    @else TRABAJADORES &mdash; {{ $filas->count() }} registros @endif
</div>

@if($unaPersona && !$esCopiaEmp)
{{-- ── Una sola persona, copia del cliente ──────────────────────────────
     En vez de una tabla de 6 columnas y una sola fila (muy ancha y baja,
     que obliga a escalar el recibo hacia abajo y deja la media hoja medio
     vacía), las entidades van como etiqueta/valor en dos columnas. --}}
@php
$fU = $filas->first();
if ($esTramiteG) {
    // Un trámite no liquida seguridad social: EPS, ARL, pensión, caja y días
    // saldrían todos en guiones y el recibo no diría qué se cobró. En su lugar
    // van el concepto y los valores que sí tiene.
    $uEntidades = [];
    if (($fU->admon ?? 0) > 0)           $uEntidades[] = ['Administración',   $fmt($fU->admon),           null, '#0f172a'];
    if (($fU->admon_asesor_oi ?? 0) > 0) $uEntidades[] = ['Honorarios asesor', $fmt($fU->admon_asesor_oi), null, '#0f172a'];
    if (($fU->iva ?? 0) > 0)             $uEntidades[] = ['IVA',              $fmt($fU->iva),             null, '#92400e'];
} else {
    $uArlNit = $fU->contrato?->razonSocial?->arl_nit ?? null;
    $uArl    = $uArlNit ? (\App\Models\Arl::where('nit',$uArlNit)->value('nombre_arl') ?? $uArlNit) : null;
    if (!$uArl) { $uArl = $fU->contrato?->arl?->nombre_arl ?? '—'; }
    if ($fU->contrato?->n_arl) { $uArl .= ' N'.$fU->contrato->n_arl; }
    $uEntidades = [
        ['EPS',            $fU->contrato?->eps?->nombre ?? '—', (int)($fU->v_eps ?? 0),  '#1d4ed8'],
        ['ARL',            $uArl,                               (int)($fU->v_arl ?? 0),  '#15803d'],
        ['Pensión',        $fU->contrato?->pension?->razon_social ?? '—', (int)($fU->v_afp ?? 0),  '#7c3aed'],
        ['Caja de compensación', $fU->contrato?->caja?->nombre ?? $fU->contrato?->caja?->razon_social ?? 'Ninguna', (int)($fU->v_caja ?? 0), '#0369a1'],
    ];
    // Solo el aportante no exonerado del art. 114-1 los paga: en los demás
    // recibos la línea no existe en vez de salir en cero.
    if ((int)($fU->v_parafiscales ?? 0) > 0) {
        $uEntidades[] = ['Parafiscales', 'SENA + ICBF', (int)$fU->v_parafiscales, '#c2410c'];
    }
    $uEntidades[] = ['Días cotizados', $fU->dias_cotizados ?? 30, null, '#0f172a'];
    if ($retU = $retiroDe($fU)) {
        $uEntidades[] = ['Retiro', '⛔ '.$retU, null, '#b91c1c'];
    }
}
@endphp
<div style="padding:.45rem .85rem .1rem">
    <div class="liq-grid">
        @if($esTramiteG)
        <div class="liq-item liq-item-full">
            <span>Concepto</span>
            <b>💼 {{ $fU->descripcion_tramite ?: 'Trámite / Servicio' }}</b>
        </div>
        @endif
        @foreach($uEntidades as [$lbl, $val, $imp, $col])
        <div class="liq-item">
            <span>{{ $lbl }}</span>
            <b style="color:{{ $col }}">
                {{ $val }}
                @if($imp !== null && $imp > 0)<em class="g-val">{{ $fmt($imp) }}</em>@endif
            </b>
        </div>
        @endforeach
    </div>
</div>
<div class="liq-total">
    <span>TOTAL A PAGAR</span>
    <b>${{ number_format($totTotal,0,',','.') }}</b>
</div>
@else
@php
// La columna de parafiscales solo aparece cuando el aportante no está exonerado:
// en los demás recibos sería una columna en cero gastando ancho de hoja.
$hayParaf = $filas->sum(fn ($x) => (int) ($x->v_parafiscales ?? 0)) > 0;
@endphp
<div style="padding:0 .85rem">
<table class="fact-table" style="font-size:.72rem;table-layout:auto;width:100%">
<thead>
<tr>
    @unless($unaPersona)
    <th style="width:22px;text-align:center">No</th>
    <th style="width:18%">Nombre / CC</th>
    <th style="width:16%">Razón Social</th>
    @endunless
    <th style="width:36px;text-align:center">Días</th>
    <th style="width:11%">EPS</th>
    <th style="width:11%">ARL</th>
    <th style="width:13%">Pensión</th>
    <th style="width:11%">Caja</th>
    @if($hayParaf)<th style="width:11%">Parafiscales</th>@endif
    <th class="right" style="width:88px;white-space:nowrap">TOTAL</th>
</tr>
</thead>
<tbody>
@php $tEps=$tArl=$tPen=$tCaj=$tAdm=$tIva=$tOtros=$tParaf=0; @endphp
@foreach($filas as $idx => $f)
@php
$cli  = $f->contrato?->cliente;
$nom  = trim(($cli?->primer_nombre ?? '').' '.($cli?->primer_apellido ?? ''));
$nom = nombre_oracion($nom);
$rsG  = $f->contrato?->razonSocial?->razon_social ?? $f->razonSocial?->razon_social ?? null;
$enEpsG = $f->contrato?->eps?->nombre ?? '—';
$enArlNomG = null;
$enArlNitG = $f->contrato?->razonSocial?->arl_nit ?? null;
if ($enArlNitG) {
    $enArlNomG = \App\Models\Arl::where('nit', $enArlNitG)->value('nombre_arl') ?? $enArlNitG;
}
if (!$enArlNomG) { $enArlNomG = $f->contrato?->arl?->nombre_arl ?? '—'; }
$enArlNivelG = $f->contrato?->n_arl ?? '';
$enArlG = $enArlNomG . ($enArlNivelG ? ' N'.$enArlNivelG : '');
$enPenG = $f->contrato?->pension?->razon_social ?? '—';
$enCajG = $f->contrato?->caja?->nombre ?? $f->contrato?->caja?->razon_social ?? '—';
$vEpsG  = (int)($f->v_eps  ?? 0);
$vArlG  = (int)($f->v_arl  ?? 0);
$vPenG  = (int)($f->v_afp  ?? 0);
$vCajG  = (int)($f->v_caja ?? 0);
$vAdmG  = (int)($f->admon  ?? 0) + (int)($f->admin_asesor ?? 0);
$vIvaG  = (int)($f->iva    ?? 0);
$vOtrG  = (int)($f->mensajeria ?? 0) + (int)($f->otros ?? 0);
$diasG  = $f->dias_cotizados ?? 30;
$vParafG = (int)($f->v_parafiscales ?? 0);
$tParaf += $vParafG;
$tEps += $vEpsG; $tArl += $vArlG; $tPen += $vPenG; $tCaj += $vCajG; $tAdm += $vAdmG;
$tIva += $vIvaG; $tOtros += $vOtrG;
@endphp
<tr>
    @unless($unaPersona)
    <td style="text-align:center;color:#94a3b8;font-weight:700;font-size:.72rem">{{ $idx+1 }}</td>
    <td>
        <div style="font-weight:700;font-size:.78rem;color:#0f172a;display:flex;align-items:center;gap:.35rem">
            {{ $nom ?: '—' }}
            <a href="{{ route('admin.facturacion.recibo', $f->id) }}?modal={{ request()->get('modal', 0) }}&individual=1" class="no-print" title="Ver recibo individual" style="text-decoration:none;font-size:.85rem;cursor:pointer">👤</a>
        </div>
        <div style="font-size:.63rem;color:#94a3b8">CC {{ $f->cedula }}</div>
        @php $retG = $retiroDe($f); @endphp
        @if($retG)
        <div style="font-size:.6rem;font-weight:700;color:#b91c1c;margin-top:.12rem">
            ⛔ RETIRO {{ $retG }}
        </div>
        @endif
    </td>
    <td>
        @if($rsG)
            <span style="font-size:.7rem;font-weight:700;color:#1d4ed8">{{ $rsG }}</span>
        @else
            <span style="font-size:.65rem;font-weight:800;color:#15803d;text-transform:uppercase;letter-spacing:.05em">Independiente</span>
        @endif
    </td>
    @endunless
    <td style="text-align:center;font-weight:700;color:{{ $diasG < 30 ? '#d97706' : '#0f172a' }}">{{ $diasG }}</td>
    <td class="entidad" style="font-size:.72rem">
        {{ $enEpsG }}
        <div class="g-val">{{ $vEpsG > 0 ? $fmt($vEpsG) : '' }}</div>
    </td>
    <td class="entidad" style="font-size:.72rem;color:#15803d">
        {{ $enArlG }}
        <div class="g-val">{{ $vArlG > 0 ? $fmt($vArlG) : '' }}</div>
    </td>
    <td class="entidad" style="font-size:.72rem;color:#7c3aed">
        {{ $enPenG }}
        <div class="g-val">{{ $vPenG > 0 ? $fmt($vPenG) : '' }}</div>
    </td>
    <td class="entidad" style="font-size:.72rem;color:#0369a1">
        {{ $enCajG !== '—' ? $enCajG : 'Ninguna' }}
        <div class="g-val">{{ $vCajG > 0 ? $fmt($vCajG) : '' }}</div>
    </td>
    @if($hayParaf)
    <td class="entidad" style="font-size:.72rem;color:#c2410c">
        SENA + ICBF
        <div class="g-val">{{ $vParafG > 0 ? $fmt($vParafG) : '' }}</div>
    </td>
    @endif
    <td class="right" style="font-weight:800;color:#0f172a">${{ number_format($f->total,0,',','.') }}</td>
</tr>
@endforeach
</tbody>
@php
$tSS = $tEps + $tArl + $tPen + $tCaj + $tParaf;
@endphp
<tfoot>
{{-- Fila: TOTAL FACTURA (siempre visible) --}}
<tr style="background:#0f172a">
    {{-- colspan según si se dibujaron las 3 columnas de identificación --}}
    <td colspan="{{ ($unaPersona ? 5 : 8) + ($hayParaf ? 1 : 0) }}" style="font-size:.78rem;font-weight:800;color:#93c5fd;letter-spacing:.07em;padding:.7rem .55rem">
        TOTAL @unless($unaPersona)&mdash; {{ $filas->count() }} trabajadores @endunless
    </td>
    <td class="right" style="font-size:1.3rem;font-weight:900;color:#fbbf24;font-family:monospace;white-space:nowrap;padding:.7rem .55rem">${{ number_format($totTotal,0,',','.') }}</td>
</tr>
</tfoot>
</table>
</div>{{-- cierre div padding --}}
@endif
{{-- RESUMEN FINANCIERO + FORMA DE PAGO (solo visible en detallado).
     Nada de esto va en la copia empresa: el desglose del final ya trae el
     resumen y la forma de pago, y así no se gasta una franja de la hoja. --}}
@unless($esCopiaEmp)
<div class="fact-pago-area bloque-resumen" style="margin:.75rem .85rem 0">

    {{-- Columna izquierda: Resumen Financiero --}}
    @if(!$esCopiaEmp)
    <div class="fact-pago-col">
        <div class="fact-pago-hdr">Resumen Financiero</div>
        @if($totSS > 0)
        <div class="fact-pago-row">
            <span>Seguridad Social</span>
            <strong>{{ $fmt($totSS) }}</strong>
        </div>
        @endif
        @if($totAdmon > 0)
        <div class="fact-pago-row">
            <span>Administración</span><strong>{{ $fmt($totAdmon) }}</strong>
        </div>
        @endif
        @if($totAfil > 0)
        <div class="fact-pago-row">
            <span>Afiliación</span><strong>{{ $fmt($totAfil) }}</strong>
        </div>
        @endif
        @if(($totOtros ?? 0) > 0)
        <div class="fact-pago-row">
            <span>Otros</span><strong>{{ $fmt($totOtros) }}</strong>
        </div>
        @endif
        @if(($totMora ?? 0) > 0)
        <div class="fact-pago-row" style="color:#92400e">
            <span>Mora</span><strong>{{ $fmt($totMora) }}</strong>
        </div>
        @endif
        @if($totSeg > 0)
        <div class="fact-pago-row">
            <span>Seguro</span><strong>{{ $fmt($totSeg) }}</strong>
        </div>
        @endif
        @if($totIva > 0)
        <div class="fact-pago-row" style="color:#92400e">
            <span>IVA / 4×mil</span><strong>{{ $fmt($totIva) }}</strong>
        </div>
        @endif
        @php
        // Anticipo real aplicado en este lote = suma de anticipo_aplicado por factura.
        // NO usar saldo_proximo (que es el neto contable: puede tener + y - que se cancelen
        // y no refleja el anticipo real pagado). $totAnticipo ya fue calculado arriba (línea 33).
        $saldoFavorG = $totAnticipo > 0 ? $totAnticipo : 0;
        @endphp
        @if($saldoFavorG > 0)
        <div class="fact-pago-row" style="color:#15803d;border-top:1px solid #d1fae5;padding-top:.2rem;margin-top:.2rem">
            <span>✅ Anticipo aplicado</span>
            <strong>−{{ $fmt($saldoFavorG) }}</strong>
        </div>
        @endif
        @if($totPrest > 0)
        <div class="fact-pago-row" style="color:#dc2626;border-top:1px solid #fee2e2;padding-top:.2rem;margin-top:.2rem">
            <span>🔴 Recuper. préstamo</span>
            <strong>+{{ $fmt($totPrest) }}</strong>
        </div>
        @endif
        @if($factura->observacion)
        <div style="margin-top:.4rem;font-size:.68rem;color:#94a3b8;font-style:italic">{{ $factura->observacion }}</div>
        @endif
    </div>
    @endif

    {{-- Columna derecha: Forma de Pago --}}
    <div class="fact-pago-col" @if($esCopiaEmp) style="border-right:none" @endif>
        <div class="fact-pago-hdr">Forma de Pago</div>
        <div class="fact-pago-row">
            <span>Tipo</span>
            <strong>{{ ucfirst(str_replace('_', ' ', $factura->forma_pago ?? '—')) }}</strong>
        </div>
        @if($totEfect > 0)
        <div class="fact-pago-row" style="color:#15803d">
            <span>💵 Efectivo</span><strong>{{ $fmt($totEfect) }}</strong>
        </div>
        @endif
        @foreach($consignacionesGrupo as $csg)
        <div style="padding:.14rem 0;border-bottom:.5px solid #f1f5f9">
            <div style="display:flex;justify-content:space-between;align-items:flex-start">
                <div>
                    <span style="color:#1d4ed8;font-weight:600;font-size:.76rem">
                        🏦 {{ $csg->bancoCuenta?->nombre ?? 'Banco' }}
                        @if($csg->confirmado) <span style="color:#15803d;font-size:.62rem;font-weight:700">✓</span> @endif
                    </span>
                    @if($csg->imagen_path)
                    <a href="#"
                       onclick="verSoporte('{{ route('admin.facturacion.consignacion.imagen.ver', $csg->id) }}');return false;"
                       style="margin-left:.3rem;background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;border-radius:4px;padding:0 5px;font-size:.58rem;text-decoration:none;vertical-align:middle">🖼️ soporte</a>
                    @endif
                    <div style="font-size:.62rem;color:#94a3b8">
                        {{ $csg->bancoCuenta?->tipo_cuenta }} {{ $csg->bancoCuenta?->numero_cuenta }}
                        · {{ sqldate($csg->fecha)->format('d/m/Y') }}
                        @if($csg->referencia) · Ref: {{ $csg->referencia }} @endif
                    </div>
                </div>
                <strong style="white-space:nowrap;font-size:.78rem">{{ $fmt($csg->valor) }}</strong>
            </div>
        </div>
        @endforeach
        @if($totPrest > 0)
        <div class="fact-pago-row" style="color:#7c3aed">
            <span>💳 Préstamo (pendiente)</span><strong>{{ $fmt($totPrest) }}</strong>
        </div>
        @endif
    </div>

</div>
@endunless
{{-- ESTADO DE CUENTA — si quedó debiendo o a favor (copia cliente) --}}
@unless($esCopiaEmp)
<div style="margin:.7rem .85rem 0;border:1px solid #e2e8f0;border-radius:6px;overflow:hidden">
    @include('admin.facturacion._recibo_saldo_cliente')
</div>
@endunless

{{-- NOTA LEGAL — es un aviso para el cliente, no va en la copia empresa --}}
@if(!$esCopiaEmp)
<div style="margin:.7rem .85rem 0;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:.5rem .85rem;font-size:.68rem;color:#92400e;line-height:1.5">
    <span style="font-weight:800">⚠️ IMPORTANTE &mdash;</span>
    Las incapacidades por enfermedad común o accidente laboral serán reconocidas por la EPS y ARL
    <strong>únicamente</strong> cuando los aportes se hayan realizado oportunamente,
    <strong>antes del décimo (10º) día hábil de cada mes</strong>.
</div>
@endif
{{-- DESGLOSE EMPRESA (solo en la copia de la empresa) --}}
@if($esCopiaEmp)
<div style="margin:.7rem .85rem 0">
    @include('admin.facturacion._recibo_desglose_empresa')
</div>
@endif
<div style="height:.9rem"></div>
{{-- BARRA INFERIOR dentro del cuadro --}}
<div class="fact-bottom-bar" style="border-top:1px solid rgba(255,255,255,.07);margin: 0 0 0; border-radius:0">
    <span>{{ $nomAliadoG }} — Asesoría en Seguridad Social</span>
    @php
    // El lote agrupa varios trabajadores y sus filas pueden venir de personas y
    // momentos distintos, así que un solo nombre mentiría: se firma con el nombre
    // únicamente cuando todas las filas son del mismo usuario, y si no se listan
    // todos. Lo mismo con la fecha de creación (la del primer registro del lote).
    $usrsG   = $filas->map(fn ($x) => $x->usuario?->nombre ?? $x->usuario?->name)->filter()->unique()->values();
    $firmaG  = $usrsG->count() === 1 ? $usrsG->first() : ($usrsG->isEmpty() ? null : $usrsG->implode(', '));
    $creadoG = $filas->min('created_at');
    @endphp
    <span style="font-size:.65rem;color:#94a3b8">
        @if($firmaG)Facturó: {{ $firmaG }} &nbsp;&middot;&nbsp;@endif
        @if($creadoG)Creado: {{ \Carbon\Carbon::parse($creadoG)->format('d/m/Y H:i') }} &nbsp;&middot;&nbsp;@endif
        Impreso: {{ now()->format('d/m/Y H:i') }}
    </span>
</div>
</div>{{-- /recibo-inner --}}
@else
{{-- ══════════════════════════════════════════════════════════════════════
     VISTA INDIVIDUAL — Diseño Tipo Factura Premium
══════════════════════════════════════════════════════════════════════════ --}}
@php
$cli1   = $factura->contrato?->cliente;
if (!$cli1 && $factura->tipo === 'otro_ingreso') {
    $cli1 = \App\Models\Cliente::where('aliado_id', $factura->aliado_id)
        ->where('cedula', $factura->cedula)->first();
}
$nom1     = trim(($cli1?->primer_nombre ?? '').' '.($cli1?->segundo_nombre ?? '').' '.($cli1?->primer_apellido ?? '').' '.($cli1?->segundo_apellido ?? ''));
$nom1 = nombre_oracion($nom1);
$rs1      = $factura->contrato?->razonSocial?->razon_social ?? $factura->razonSocial?->razon_social ?? null;
$arlNom   = $factura->contrato?->arl?->nombre_arl;
if (!$arlNom) {
    $arlNit = $factura->contrato?->razonSocial?->arl_nit;
    $arlNom = $arlNit ? (\App\Models\Arl::where('nit',$arlNit)->value('nombre_arl') ?? $arlNit) : null;
}
$arlNivel = $factura->contrato?->n_arl ?? '';
$cajaNom  = $factura->contrato?->caja?->nombre ?? $factura->contrato?->caja?->razon_social ?? null;
$penNom   = $factura->contrato?->pension?->razon_social ?? null;
$epsNom   = $factura->contrato?->eps?->nombre ?? null;
$vEps1    = (int)($factura->v_eps  ?? 0);
$vArl1    = (int)($factura->v_arl  ?? 0);
$vPen1    = (int)($factura->v_afp  ?? 0);
$vCaj1    = (int)($factura->v_caja ?? 0);
$vAdm1    = (int)($factura->admon  ?? 0) + (int)($factura->admin_asesor ?? 0);
$vSeg1    = (int)($factura->seguro ?? 0);
$vAfil1   = $esPar ? $totAfil : (int)($factura->afiliacion ?? 0);
$vMens1   = (int)($factura->mensajeria ?? 0);
$vOtros1  = (int)($factura->otros ?? 0);
$vIva1    = (int)($factura->iva ?? 0);
// La mora entra en factura->total pero no estaba en ninguna linea del resumen: un
// recibo con mora cobraba mas de lo que su propio desglose sumaba.
$vMora1   = (int)($factura->mora ?? 0);
$dias1    = $factura->dias_cotizados ?? 30;

// Sello de estado
$selloTxt = match($estadoVisual) {
    'pagada'      => 'PAGADO',
    'pre_factura' => 'PRE-FACT',
    'prestamo'    => 'PRÉSTAMO',
    'abono'       => 'ABONO',
    default       => strtoupper($estadoVisual ?? '')
};
$selloCls = match($estadoVisual) {
    'pagada'   => 'sello-pagado',
    'prestamo' => 'sello-prest',
    'abono'    => 'sello-abono',
    default    => 'sello-pre'
};

// Dirección/contacto del cliente
$tel1 = trim(($cli1?->telefono ?? '') ?: ($cli1?->celular ?? ''));
$sal1 = (int)($factura->contrato?->salario ?? 0);

// Logo del aliado
$aliadoObj  = \App\Models\Aliado::find($factura->aliado_id);
$logoAliado = $aliadoObj?->logo ? asset('storage/'.$aliadoObj->logo) : null;
$nomAliado  = $aliadoObj?->nombre ?? $aliadoObj?->razon_social ?? 'BryNex';

$empresaCliente = $cli1?->empresa ?? ($cli1?->cod_empresa ? \App\Models\Empresa::find($cli1->cod_empresa) : null);

// Dirección a imprimir: la de la empresa cuando el trabajador es de una, y si
// no la del cliente —de cobro antes que la de vivienda, que es a donde va el
// mensajero—. Venía leyendo `clientes.direccion`, que no existe (las columnas
// son `direccion_cobro` y `direccion_vivienda`), así que el renglón nunca se
// pintaba. El respaldo importa: hay empresas con la dirección en blanco.
$dir1 = trim((string) ($empresaCliente?->direccion ?? ''))
    ?: trim((string) ($cli1?->direccion_cobro ?? ''))
    ?: trim((string) ($cli1?->direccion_vivienda ?? ''));
@endphp

{{-- HEADER TIPO FACTURA (con margen superior) --}}
<div class="recibo-inner-wrap">
<div class="fact-header" style="position:relative;overflow:hidden;border-radius:6px 6px 0 0;border:1px solid #e2e8f0;">

    {{-- Sello diagonal --}}
    <div class="fact-sello-wrap">
        <div class="fact-sello {{ $selloCls }}">{{ $selloTxt }}</div>
    </div>

    {{-- Col 1: Afiliado / Trabaj. / Empresa --}}
    <div class="fact-h-empresa">
        @if($factura->tipo === 'otro_ingreso' && $factura->empresa)
            {{-- Otro ingreso de empresa --}}
            <div style="font-size:1.15rem;font-weight:900;color:#0f172a;line-height:1.1">{{ $factura->empresa->empresa }}</div>
            <div style="font-size:.68rem;color:#64748b;margin-top:.15rem">NIT: {{ $factura->empresa->nit ?? '—' }}</div>
            {{-- Datos de entrega: los usa el mensajero que lleva el recibo --}}
            @php
                $oiEmp  = $factura->empresa;
                $oiTel  = collect([$oiEmp->celular, $oiEmp->telefono])->filter()->implode(' / ');
                $oiCont = collect([$oiEmp->contacto, $oiTel])->filter()->implode(' — ');
            @endphp
            @if($oiCont || $oiEmp->direccion)
            <div style="font-size:.63rem;color:#64748b;margin-top:.12rem;line-height:1.45">
                @if($oiCont)<div>{{ $oiCont }}</div>@endif
                @if($oiEmp->direccion)<div>{{ $oiEmp->direccion }}</div>@endif
            </div>
            @endif
        @elseif($rs1)
            {{-- Con razón social → DEPENDIENTE --}}
            <div style="font-size:1.1rem;font-weight:900;color:#0f172a;line-height:1.1">{{ $nom1 ?: 'CC '.$factura->cedula }}</div>
            <div style="font-size:.68rem;color:#64748b;margin-top:.12rem">C.C. {{ $factura->cedula }}</div>
        @else
            {{-- Sin razón social → INDEPENDIENTE --}}
            <div style="font-size:1.1rem;font-weight:900;color:#0f172a;line-height:1.1">{{ $nom1 ?: 'CC '.$factura->cedula }}</div>
            <div style="font-size:.68rem;color:#64748b;margin-top:.12rem">C.C. {{ $factura->cedula }}</div>
            <div style="font-size:.65rem;font-weight:800;color:#15803d;text-transform:uppercase;letter-spacing:.07em;margin-top:.2rem">Independiente</div>
        @endif
    </div>

    {{-- Col 2: Solo Número de recibo (centrado) --}}
    <div class="fact-h-recibo">
        <div style="font-size:.58rem;font-weight:700;letter-spacing:.15em;color:#93c5fd;text-transform:uppercase;margin-bottom:.18rem">Recibo de Pago</div>
        <div style="font-size:2rem;font-weight:900;color:#fbbf24;letter-spacing:-.03em;line-height:1">
            {{ str_pad($factura->numero_factura, 6, '0', STR_PAD_LEFT) }}
        </div>
        <div style="margin-top:.35rem">
            <span class="badge {{ $estadoCls($estadoVisual) }}">{{ $estadoLabel($estadoVisual) }}</span>
        </div>
    </div>

    {{-- Col 3: Logo del aliado --}}
    <div class="fact-h-logo">
        @if($logoAliado)
        <img src="{{ $logoAliado }}" alt="{{ $nomAliado }}" style="max-width:140px;max-height:70px;object-fit:contain">
        @else
        <img src="{{ asset('img/logo-brynex.png') }}" alt="BryNex" style="max-width:140px;max-height:70px;object-fit:contain">
        @endif
        <div style="font-size:.55rem;color:#64748b;text-align:center;margin-top:.35rem;font-weight:600;letter-spacing:.04em">
            {{ strtoupper($nomAliado) }}
        </div>
    </div>

</div>{{-- /fact-header --}}
</div>{{-- /recibo-inner-wrap --}}

{{-- DATOS DEL CLIENTE (con margen interior) --}}
<div class="recibo-inner">
<div class="fact-cliente">
    @if($nom1 && $rs1)
    <div class="fact-cliente-row">
        <span class="fact-cliente-lbl">Trabajador</span>
        <span class="fact-cliente-val">{{ $nom1 }}</span>
    </div>
    @elseif($nom1)
    <div class="fact-cliente-row">
        <span class="fact-cliente-lbl">Nombres</span>
        <span class="fact-cliente-val">{{ $nom1 }}</span>
    </div>
    @endif

    {{-- La empresa del trabajador no se imprime en el recibo. El renglón queda
         vacío en ese caso: no se sustituye por la razón social, que es otro dato. --}}
    @if($rs1 && ! $empresaCliente)
    <div class="fact-cliente-row">
        <span class="fact-cliente-lbl">Razón Social</span>
        <span class="fact-cliente-val" style="color:#1d4ed8">{{ $rs1 }}</span>
    </div>
    @endif

    <div class="fact-cliente-row">
        <span class="fact-cliente-lbl">Cédula</span>
        <span class="fact-cliente-val">{{ $factura->cedula }}</span>
    </div>
    @if($tel1)
    <div class="fact-cliente-row">
        <span class="fact-cliente-lbl">Teléfono</span>
        <span class="fact-cliente-val">{{ $tel1 }}</span>
    </div>
    @endif
    @if($dir1)
    <div class="fact-cliente-row">
        <span class="fact-cliente-lbl">Dirección</span>
        <span class="fact-cliente-val">{{ $dir1 }}</span>
    </div>
    @endif
    @if($sal1 > 0)
    <div class="fact-cliente-row">
        <span class="fact-cliente-lbl">Salario IBC</span>
        <span class="fact-cliente-val" style="color:#1d4ed8">{{ $fmt($sal1) }}</span>
    </div>
    @endif
    <div class="fact-cliente-row">
        <span class="fact-cliente-lbl">Mes Liquidado</span>
        <span class="fact-cliente-val" style="color:#1d4ed8;font-weight:800">
            {{ $meses[$factura->mes-1] }}&nbsp;&nbsp;AÑO: {{ $factura->anio }}
        </span>
    </div>
    <div class="fact-cliente-row">
        <span class="fact-cliente-lbl">Período</span>
        <span class="fact-cliente-val" style="color:#0f172a;font-weight:700">
            {{ $meses[$factura->mes-1] }} {{ $factura->anio }}
            @if($esPar)
                @php $filaAfil = $filas->firstWhere('tipo','afiliacion'); @endphp
                @if($filaAfil)
                <span style="font-size:.65rem;background:#ede9fe;color:#7c3aed;padding:.1rem .4rem;border-radius:4px;margin-left:.3rem;font-weight:700">+ Afil: {{ $meses[$filaAfil->mes-1] }} {{ $filaAfil->anio }}</span>
                @endif
            @endif
        </span>
    </div>
    <div class="fact-cliente-row">
        <span class="fact-cliente-lbl">Facturó</span>
        <span class="fact-cliente-val" style="color:#64748b;font-size:.73rem">
            {{ $factura->usuario?->nombre ?? $factura->usuario?->name ?? ('Usuario #'.($factura->usuario_id ?? '?')) }}
        </span>
    </div>
    <div class="fact-cliente-row">
        <span class="fact-cliente-lbl">Fecha</span>
        <span class="fact-cliente-val" style="color:#64748b;font-size:.73rem">
            {{ sqldate($factura->fecha_pago)->format('d/m/Y') }}
        </span>
    </div>
    @php $ret1 = $retiroDe($factura); @endphp
    @if($ret1)
    <div class="fact-cliente-row">
        <span class="fact-cliente-lbl">Retiro</span>
        <span class="fact-cliente-val" style="color:#b91c1c;font-size:.73rem;font-weight:700">
            ⛔ {{ $ret1 }}
        </span>
    </div>
    @endif
</div>

{{-- ALERTA PRÉSTAMO --}}
@if($totPrest > 0 || $factura->estado === 'prestamo')
<div class="alerta-prest">
    <span style="font-size:1.3rem">💳</span>
    <div>
        <div style="font-weight:700;color:#6d28d9;font-size:.84rem">Préstamo pendiente de cobro</div>
        <div style="font-size:.77rem;color:#7c3aed">
            Total: <strong>{{ $fmt($totTotal) }}</strong> ·
            Recibido: <strong>{{ $fmt($totTotal - $totPrest) }}</strong> ·
            Pendiente: <strong>{{ $fmt($totPrest) }}</strong>
        </div>
    </div>
</div>
@endif

{{-- TABLA ENTIDADES --}}
<div class="fact-body">
<div class="fact-section-title">DESCRIPCIÓN DE SERVICIOS</div>

@if($factura->tipo === 'otro_ingreso')
{{-- ── Otro Ingreso ─────────────────────────────── --}}
<table class="fact-table">
<thead>
    <tr>
        <th style="width:40%">Concepto / Trámite</th>
        <th>Detalle</th>
        <th class="right" style="width:140px">Valor</th>
    </tr>
</thead>
<tbody>
    <tr>
        <td class="concepto" style="font-weight:800;color:#065f46">
            💼 {{ $factura->descripcion_tramite ?? 'Trámite / Servicio' }}
        </td>
        <td class="tag">
            @if($factura->empresa) Empresa: {{ $factura->empresa->empresa }} @endif
        </td>
        <td class="right" style="color:#0f172a">
            @if(($factura->admon ?? 0) > 0) {{ $fmt($factura->admon) }} @endif
        </td>
    </tr>
    @if(($factura->admon_asesor_oi ?? 0) > 0)
    <tr>
        <td class="concepto">Honorarios asesor</td>
        <td></td>
        <td class="right">{{ $fmt($factura->admon_asesor_oi) }}</td>
    </tr>
    @endif
    @if($vIva1 > 0)
    <tr>
        <td class="concepto">IVA</td>
        <td class="tag">Impuesto al valor agregado</td>
        <td class="right" style="color:#92400e">{{ $fmt($vIva1) }}</td>
    </tr>
    @endif
</tbody>
<tfoot>
    <tr>
        <td colspan="2" style="font-size:.75rem;letter-spacing:.06em">SUBTOTAL</td>
        <td class="right">{{ $fmt($totTotal) }}</td>
    </tr>
</tfoot>
</table>

@elseif($factura->tipo === 'afiliacion')
{{-- ── Afiliación ───────────────────────────────── --}}
<table class="fact-table">
<thead>
    <tr>
        <th style="width:30%">Descripción</th>
        <th>Entidad</th>
        <th class="right" style="width:140px">Valor</th>
    </tr>
</thead>
<tbody>
    @if($epsNom)
    <tr>
        <td class="concepto">EPS</td>
        <td class="entidad">{{ $epsNom }}</td>
        <td class="right">—</td>
    </tr>
    @endif
    @if($arlNom)
    <tr>
        <td class="concepto">ARL{{ $arlNivel ? ' · Riesgo '.$arlNivel : '' }}</td>
        <td class="entidad" style="color:#15803d">{{ $arlNom }}</td>
        <td class="right">—</td>
    </tr>
    @endif
    @if($penNom)
    <tr>
        <td class="concepto">PENSIÓN</td>
        <td class="entidad" style="color:#7c3aed">{{ $penNom }}</td>
        <td class="right">—</td>
    </tr>
    @endif
    @if($cajaNom)
    <tr>
        <td class="concepto">CAJA COMPENSACIÓN</td>
        <td class="entidad" style="color:#0369a1">{{ $cajaNom }}</td>
        <td class="right">—</td>
    </tr>
    @endif
    @if($vAdm1 > 0)
    <tr>
        <td class="concepto">ADMINISTRACIÓN</td>
        <td class="tag">Honorarios gestión BryNex</td>
        <td class="right">{{ $fmt($vAdm1) }}</td>
    </tr>
    @endif
    @if($vSeg1 > 0)
    <tr>
        <td class="concepto">SEGURO</td>
        <td class="tag"></td>
        <td class="right">{{ $fmt($vSeg1) }}</td>
    </tr>
    @endif
    @if($vIva1 > 0)
    <tr>
        <td class="concepto">IVA</td>
        <td class="tag">Impuesto al valor agregado</td>
        <td class="right" style="color:#92400e">{{ $fmt($vIva1) }}</td>
    </tr>
    @endif
    <tr style="background:#f0fdf4 !important">
        <td class="concepto" style="font-size:.7rem;color:#64748b;font-style:italic" colspan="3">
            ⚡ Trámite de afiliación ante entidades del Sistema de Seguridad Social
            @if($dias1 < 30) — <span style="color:#d97706;font-weight:700">{{ $dias1 }} días cotizados</span> @endif
        </td>
    </tr>
</tbody>
<tfoot>
    <tr>
        <td colspan="2" style="font-size:.75rem;letter-spacing:.06em">TOTAL AFILIACIÓN</td>
        <td class="right">{{ $fmt($totTotal) }}</td>
    </tr>
</tfoot>
</table>

@else
{{-- ── Planilla (Seguridad Social) ─────────────── --}}
<table class="fact-table">
<thead>
    <tr>
        <th style="width:26%">Descripción</th>
        <th>Entidad</th>
        <th style="width:70px;text-align:center">Días</th>
        <th class="right col-valor-det" style="width:130px">Valor</th>
    </tr>
</thead>
<tbody>
    @if($epsNom)
    <tr>
        <td class="concepto">EPS</td>
        <td class="entidad">{{ $epsNom }}</td>
        <td style="text-align:center;font-weight:700;color:{{ $dias1 < 30 ? '#d97706' : '#0f172a' }}">{{ $dias1 }}</td>
        <td class="right col-valor-det">{{ $vEps1 > 0 ? $fmt($vEps1) : '—' }}</td>
    </tr>
    @endif
    @if($arlNom)
    <tr>
        <td class="concepto">ARL{{ $arlNivel ? ' · Riesgo '.$arlNivel : '' }}</td>
        <td class="entidad" style="color:#15803d">{{ $arlNom }}</td>
        <td style="text-align:center;font-weight:700;color:{{ $dias1 < 30 ? '#d97706' : '#0f172a' }}">{{ $dias1 }}</td>
        <td class="right col-valor-det">{{ $vArl1 > 0 ? $fmt($vArl1) : '—' }}</td>
    </tr>
    @endif
    @if($penNom)
    <tr>
        <td class="concepto">PENSIÓN</td>
        <td class="entidad" style="color:#7c3aed">{{ $penNom }}</td>
        <td style="text-align:center;font-weight:700;color:{{ $dias1 < 30 ? '#d97706' : '#0f172a' }}">{{ $dias1 }}</td>
        <td class="right col-valor-det">{{ $vPen1 > 0 ? $fmt($vPen1) : '—' }}</td>
    </tr>
    @endif
    @if($cajaNom)
    <tr>
        <td class="concepto">CAJA COMPENSACIÓN</td>
        <td class="entidad" style="color:#0369a1">{{ $cajaNom !== '—' ? $cajaNom : 'NINGUNA' }}</td>
        <td style="text-align:center;color:#94a3b8">{{ $dias1 }}</td>
        <td class="right col-valor-det">{{ $vCaj1 > 0 ? $fmt($vCaj1) : '—' }}</td>
    </tr>
    @endif
    @if($vAdm1 > 0)
    <tr>
        <td class="concepto">ADMINISTRACIÓN</td>
        <td class="tag">Honorarios gestión {{ $nomAliado }}</td>
        <td></td>
        <td class="right col-valor-det">{{ $fmt($vAdm1) }}</td>
    </tr>
    @endif
    @if($vSeg1 > 0)
    <tr>
        <td class="concepto">SEGURO</td>
        <td class="tag"></td>
        <td></td>
        <td class="right col-valor-det">{{ $fmt($vSeg1) }}</td>
    </tr>
    @endif
    @if($vAfil1 > 0)
    <tr style="background:#f0fdf4 !important">
        <td class="concepto" style="color:#065f46">AFILIACIÓN</td>
        <td class="tag">Trámite de afiliación incluido</td>
        <td></td>
        <td class="right col-valor-det" style="color:#15803d">{{ $fmt($vAfil1) }}</td>
    </tr>
    @endif
    @if($vMens1 > 0)
    <tr>
        <td class="concepto">MENSAJERÍA</td>
        <td class="tag"></td>
        <td></td>
        <td class="right col-valor-det">{{ $fmt($vMens1) }}</td>
    </tr>
    @endif
    @if($vOtros1 > 0)
    <tr>
        <td class="concepto">OTROS</td>
        <td class="tag"></td>
        <td></td>
        <td class="right col-valor-det">{{ $fmt($vOtros1) }}</td>
    </tr>
    @endif
    @if($vIva1 > 0)
    <tr>
        <td class="concepto">IVA / 4×MIL</td>
        <td class="tag">Impuesto al valor agregado</td>
        <td></td>
        <td class="right col-valor-det" style="color:#92400e">{{ $fmt($vIva1) }}</td>
    </tr>
    @endif
</tbody>
<tfoot>
    <tr>
        <td colspan="2" style="font-size:.75rem;letter-spacing:.06em">SUBTOTAL</td>
        <td style="text-align:center;font-size:.7rem;color:#93c5fd;font-weight:600">
            @if($dias1 < 30)<span style="color:#fbbf24">{{ $dias1 }}d</span>@endif
        </td>
        <td class="right col-valor-det">{{ $fmt($totTotal) }}</td>
    </tr>
</tfoot>
</table>
@endif

{{-- BLOQUE PAGO (solo en vista detallada).
     No va en la copia empresa: el Resumen Financiero lo reemplaza el
     desglose del final, y la forma de pago ya sale en el bloque de abajo. --}}
@if(!$esCopiaEmp)
<div class="fact-pago-area bloque-resumen">
    <div class="fact-pago-col">
        <div class="fact-pago-hdr">Resumen Financiero</div>
        @if($factura->tipo !== 'otro_ingreso')
        @if($vEps1+$vArl1+$vPen1+$vCaj1 > 0)
        <div class="fact-pago-row">
            <span>Seguridad Social</span>
            <strong>{{ $fmt($vEps1+$vArl1+$vPen1+$vCaj1) }}</strong>
        </div>
        @endif
        @endif
        @if($vAdm1 > 0)
        <div class="fact-pago-row">
            <span>Administración</span><strong>{{ $fmt($vAdm1) }}</strong>
        </div>
        @endif
        @if($vAfil1 > 0)
        <div class="fact-pago-row">
            <span>Afiliación</span><strong>{{ $fmt($vAfil1) }}</strong>
        </div>
        @endif
        @if($vSeg1 > 0)
        <div class="fact-pago-row">
            <span>Seguro</span><strong>{{ $fmt($vSeg1) }}</strong>
        </div>
        @endif
        @if($vIva1 > 0)
        <div class="fact-pago-row" style="color:#92400e">
            <span>IVA / 4×mil</span><strong>{{ $fmt($vIva1) }}</strong>
        </div>
        @endif
        @if($vMora1 > 0)
        <div class="fact-pago-row" style="color:#92400e">
            <span>Mora</span><strong>{{ $fmt($vMora1) }}</strong>
        </div>
        @endif
        @php
        // saldo_proximo: negativo = consumió anticipo (aplico a favor), positivo = generó anticipo
        $spIndiv = (int)($factura->saldo_proximo ?? 0);
        $saldoFavorMostrar = $spIndiv < 0 ? abs($spIndiv) : 0;
        @endphp
        @if($saldoFavorMostrar > 0)
        @php
        $mesAnt2  = $factura->mes > 1 ? $factura->mes - 1 : 12;
        $anioAnt2 = $factura->mes > 1 ? $factura->anio : $factura->anio - 1;
        @endphp
        <div class="fact-pago-row" style="color:#15803d;border-top:1px solid #d1fae5;padding-top:.2rem;margin-top:.2rem">
            <span>✅ Anticipo aplicado <small style="font-size:.62rem">{{ $meses[$mesAnt2-1] }} {{ $anioAnt2 }}</small></span>
            <strong>−{{ $fmt($saldoFavorMostrar) }}</strong>
        </div>
        @endif
        {{-- Saldo pendiente heredado ya no se almacena -- se omite esta fila --}}
        @if($factura->observacion)
        <div style="margin-top:.4rem;font-size:.68rem;color:#94a3b8;font-style:italic">{{ $factura->observacion }}</div>
        @endif
    </div>

    <div class="fact-pago-col">
        <div class="fact-pago-hdr">Forma de Pago</div>
        <div class="fact-pago-row">
            <span>Tipo</span>
            <strong>{{ ucfirst(str_replace('_', ' ', $factura->forma_pago ?? '—')) }}</strong>
        </div>
        @if($totEfect > 0)
        <div class="fact-pago-row" style="color:#15803d">
            <span>💵 Efectivo</span><strong>{{ $fmt($totEfect) }}</strong>
        </div>
        @endif
        @foreach($consignacionesGrupo as $csg)
        <div style="padding:.14rem 0;border-bottom:.5px solid #f1f5f9">
            <div style="display:flex;justify-content:space-between;align-items:flex-start">
                <div>
                    <span style="color:#1d4ed8;font-weight:600;font-size:.76rem">
                        🏦 {{ $csg->bancoCuenta?->nombre ?? 'Banco' }}
                        @if($csg->confirmado) <span style="color:#15803d;font-size:.62rem;font-weight:700">✓</span> @endif
                    </span>
                    @if($csg->imagen_path)
                    <a href="#"
                       onclick="verSoporte('{{ route('admin.facturacion.consignacion.imagen.ver', $csg->id) }}');return false;"
                       style="margin-left:.3rem;background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;border-radius:4px;padding:0 5px;font-size:.58rem;text-decoration:none;vertical-align:middle">🖼️ soporte</a>
                    @endif
                    <div style="font-size:.62rem;color:#94a3b8">
                        {{ $csg->bancoCuenta?->tipo_cuenta }} {{ $csg->bancoCuenta?->numero_cuenta }}
                        · {{ sqldate($csg->fecha)->format('d/m/Y') }}
                        @if($csg->referencia) · Ref: {{ $csg->referencia }} @endif
                    </div>
                </div>
                <strong style="white-space:nowrap;font-size:.78rem">{{ $fmt($csg->valor) }}</strong>
            </div>
        </div>
        @endforeach
        @if($totPrest > 0)
        <div class="fact-pago-row" style="color:#7c3aed">
            <span>💳 Préstamo (pendiente)</span><strong>{{ $fmt($totPrest) }}</strong>
        </div>
        @endif
        {{-- Anticipos aplicados (detallado) --}}
        @if(isset($anticiposAplicados) && $anticiposAplicados->isNotEmpty())
        <div style="margin-top:.3rem;padding-top:.3rem;border-top:1px solid #d1fae5;">
            @foreach($anticiposAplicados as $antApl)
            <div style="display:flex;justify-content:space-between;padding:.1rem 0;font-size:.73rem;color:#15803d;">
                <span>
                    💰 Anticipo {{ \App\Models\Anticipo::FORMAS_PAGO[$antApl->forma_pago] ?? $antApl->forma_pago }}
                    <small style="font-size:.62rem;color:#64748b">{{ $antApl->fecha_pago->format('d/m/Y') }}</small>
                    @if($antApl->referencia)<small style="color:#94a3b8"> · {{ $antApl->referencia }}</small>@endif
                </span>
                <strong>−{{ $fmt($antApl->valor_aplicado > 0 ? $antApl->valor_aplicado : $antApl->valor) }}</strong>
            </div>
            @endforeach
        </div>
        @endif
    </div>
</div>
@endif

</div>{{-- /fact-body --}}

{{-- ══ FORMA DE PAGO — siempre visible (vista simple y detallada) ══
     En la copia empresa no se dibuja: va dentro del desglose del final. --}}
@unless($esCopiaEmp)
@php
$fpLabel = match($factura->forma_pago ?? '') {
    'consignacion' => '🏦 Consignación',
    'efectivo'     => '💵 Efectivo',
    'mixto'        => '💰 Mixto (efectivo + consignación)',
    'prestamo'     => '💳 Préstamo',
    default        => ucfirst(str_replace('_',' ', $factura->forma_pago ?? '—')),
};
@endphp
<div style="border-top:1.5px solid #e2e8f0;padding:.55rem 1.2rem;background:#f8fafc;">
    <div style="font-size:.6rem;font-weight:800;color:#1e3a5f;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.35rem;padding-bottom:.2rem;border-bottom:1.5px solid #bfdbfe;">
        Forma de Pago
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:.5rem 1.5rem;align-items:flex-start;">

        {{-- Tipo --}}
        <div style="display:flex;align-items:center;gap:.35rem;font-size:.77rem;">
            <span style="color:#64748b;font-weight:600;">Tipo:</span>
            <span style="font-weight:800;color:#0f172a;">{{ $fpLabel }}</span>
        </div>

        {{-- Efectivo --}}
        @if($totEfect > 0)
        <div style="display:flex;align-items:center;gap:.35rem;font-size:.77rem;color:#15803d;">
            <span style="font-weight:600;">💵 Efectivo:</span>
            <strong>{{ $fmt($totEfect) }}</strong>
        </div>
        @endif

        {{-- Consignaciones --}}
        @foreach($consignacionesGrupo as $csg)
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:7px;padding:.3rem .65rem;font-size:.75rem;display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
            <span style="color:#1d4ed8;font-weight:700;">
                🏦 {{ $csg->bancoCuenta?->nombre ?? 'Banco' }}
                @if($csg->confirmado) <span style="color:#15803d;font-size:.65rem;">✓ Confirmado</span> @endif
            </span>
            <span style="color:#475569;font-weight:800;">{{ $fmt($csg->valor) }}</span>
            <span style="color:#94a3b8;font-size:.68rem;">
                {{ sqldate($csg->fecha)->format('d/m/Y') }}
                @if($csg->referencia) · Ref: {{ $csg->referencia }} @endif
                @if($csg->bancoCuenta?->tipo_cuenta) · {{ $csg->bancoCuenta->tipo_cuenta }} @endif
            </span>
            @if($csg->imagen_path)
            <a href="#"
               onclick="verSoporte('{{ route('admin.facturacion.consignacion.imagen.ver', $csg->id) }}');return false;"
               style="background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;border-radius:4px;padding:0 6px;font-size:.62rem;text-decoration:none;font-weight:700;">
                🖼️ Ver soporte
            </a>
            @endif
        </div>
        @endforeach

        {{-- Préstamo --}}
        @if($totPrest > 0)
        <div style="display:flex;align-items:center;gap:.35rem;font-size:.77rem;color:#7c3aed;">
            <span style="font-weight:600;">💳 Préstamo pendiente:</span>
            <strong>{{ $fmt($totPrest) }}</strong>
        </div>
        @endif

        {{-- Abonos al préstamo: cuándo y cómo se pagó, con su soporte --}}
        @if(isset($abonosPrestamo) && $abonosPrestamo->isNotEmpty())
        <div style="display:flex;flex-direction:column;gap:.3rem;width:100%;margin-top:.25rem;padding-top:.25rem;border-top:1.5px solid #ede9fe;">
            <span style="font-size:.6rem;font-weight:800;color:#6d28d9;text-transform:uppercase;letter-spacing:.06em;">
                {{ $factura->estado === 'prestamo' ? '💳 Abonos al préstamo' : '✅ Préstamo pagado' }}
            </span>
            @foreach($abonosPrestamo as $ab)
            <div style="background:#f5f3ff;border:1px solid #ddd6fe;border-radius:7px;padding:.3rem .65rem;font-size:.75rem;display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
                <span style="color:#6d28d9;font-weight:700;">
                    {{ match($ab->forma_pago) { 'efectivo' => '💵 Efectivo', 'consignacion' => '🏦 '.($bancosAbonos[$ab->banco_cuenta_id] ?? 'Consignación'), 'mixto' => '💰 Mixto', default => '💰 '.ucfirst((string) $ab->forma_pago) } }}
                </span>
                <span style="color:#475569;font-weight:800;">{{ $fmt($ab->valor) }}</span>
                <span style="color:#94a3b8;font-size:.68rem;">
                    {{ $ab->fecha ? sqldate($ab->fecha)->format('d/m/Y') : '' }}
                    @if($ab->observacion) · {{ \Illuminate\Support\Str::limit($ab->observacion, 60) }} @endif
                </span>
                @if($ab->soporte_path)
                <a href="#"
                   onclick="verSoporte('{{ route('admin.prestamos.abono.soporte', $ab->id) }}?ver=1');return false;"
                   style="background:#ede9fe;color:#6d28d9;border:1px solid #ddd6fe;border-radius:4px;padding:0 6px;font-size:.62rem;text-decoration:none;font-weight:700;">
                    🖼️ Ver comprobante
                </a>
                @endif
            </div>
            @endforeach
        </div>
        @endif

        {{-- ─── ANTICIPOS APLICADOS (desglose por anticipo) ─── --}}
        @if(isset($anticiposAplicados) && $anticiposAplicados->isNotEmpty())
        <div style="display:flex;flex-direction:column;gap:.3rem;width:100%;margin-top:.25rem;padding-top:.25rem;border-top:1.5px solid #d1fae5;">
            <span style="font-size:.6rem;font-weight:800;color:#15803d;text-transform:uppercase;letter-spacing:.06em;">💰 Anticipos aplicados</span>
            @foreach($anticiposAplicados as $antApl)
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:7px;padding:.3rem .65rem;font-size:.74rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.3rem;">
                <div style="display:flex;flex-direction:column;gap:.08rem;">
                    <span style="color:#15803d;font-weight:700;">
                        {{ match($antApl->forma_pago) {
                            'efectivo'      => '💵',
                            'nequi'         => '📱',
                            'consignacion'  => '🏦',
                            'transferencia' => '↔️',
                            default         => '💰',
                        } }}
                        {{ \App\Models\Anticipo::FORMAS_PAGO[$antApl->forma_pago] ?? ucfirst($antApl->forma_pago) }}
                        <span style="font-weight:500;color:#64748b;font-size:.68rem;">registrado el {{ $antApl->fecha_pago->format('d/m/Y') }}</span>
                    </span>
                    @if($antApl->referencia)
                    <span style="font-size:.65rem;color:#64748b;">Ref: {{ $antApl->referencia }}</span>
                    @endif
                </div>
                <strong style="color:#15803d;font-family:monospace;white-space:nowrap;">
                    −{{ $fmt($antApl->valor_aplicado > 0 ? $antApl->valor_aplicado : $antApl->valor) }}
                </strong>
            </div>
            @endforeach
            @if($totAnticipo > 0)
            <div style="font-size:.7rem;font-weight:800;color:#15803d;text-align:right;">Total anticipos: {{ $fmt($totAnticipo) }}</div>
            @endif
        </div>
        @endif

    </div>
    {{-- Observación --}}
    @if($factura->observacion)
    <div style="margin-top:.35rem;font-size:.68rem;color:#94a3b8;font-style:italic;">
        📝 {{ $factura->observacion }}
    </div>
    @endif
</div>

{{-- ESTADO DE CUENTA — si quedó debiendo o a favor (copia cliente) --}}
@include('admin.facturacion._recibo_saldo_cliente')
@endunless

{{-- DESGLOSE EMPRESA (solo en la copia de la empresa) --}}
@if($esCopiaEmp)
<div style="padding:.6rem 1.2rem;background:#f8fafc;border-top:1.5px solid #e2e8f0;">
    @include('admin.facturacion._recibo_desglose_empresa')
</div>
@endif

{{-- PIE: Nota Legal + Total --}}
{{-- En la copia empresa la nota legal se omite (es un aviso al cliente) y el
     bloque de total se alinea a la derecha con display:flex. --}}
<div class="fact-footer-area" @if($esCopiaEmp) style="display:flex;justify-content:flex-end" @endif>
    @if(!$esCopiaEmp)
    <div class="fact-nota">
        <span style="font-size:1.15rem;flex-shrink:0">⚠️</span>
        <span>
            <strong>NOTA:</strong> Las incapacidades por enfermedades y/o accidentes laborales serán reconocidas por la
            EPS y ARL solo si los aportes se han realizado oportunamente <strong>antes del décimo día hábil de cada mes</strong>.
        </span>
    </div>
    @endif
    <div class="fact-total-bloque">
        <span class="fact-total-label">Total a Pagar</span>
        <span class="fact-total-valor">{{ $fmt($totTotal) }}</span>
        @if($totPrest > 0)
        <div style="font-size:.65rem;color:#a78bfa;margin-top:.2rem">Préstamo: {{ $fmt($totPrest) }}</div>
        @endif
    </div>
</div>
</div>{{-- /recibo-inner --}}

{{-- BARRA INFERIOR (con margen inferior) --}}
<div style="margin: 0 1.2rem 1rem; border-radius: 0 0 6px 6px; overflow:hidden; border: 1px solid #e2e8f0; border-top: none;">
<div class="fact-bottom-bar" style="border-radius:0">
    <span>{{ $nomAliado }} — Asesoría en Seguridad Social</span>
    <span>Facturó: {{ $factura->usuario?->nombre ?? 'Usuario' }} &nbsp;&middot;&nbsp; Creado: {{ $factura->created_at?->format('d/m/Y H:i') ?? '—' }} &nbsp;&middot;&nbsp; Impreso: {{ now()->format('d/m/Y H:i') }}</span>
</div>{{-- /fact-bottom-bar --}}
</div>{{-- /bottom-wrapper --}}

@endif {{-- esGrupo --}}
