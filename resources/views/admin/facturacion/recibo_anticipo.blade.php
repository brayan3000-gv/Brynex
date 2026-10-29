@extends(request()->boolean('modal') ? 'layouts.modal' : 'layouts.app')
@section('modulo', 'Recibo de Anticipo')

@php
    $aliadoId = session('aliado_id_activo');
    $aliadoObj = \App\Models\Aliado::find($aliadoId);
    $logoAliado = $aliadoObj?->logo ? asset('storage/'.$aliadoObj->logo) : null;
    $nomAliado = $aliadoObj?->nombre ?? 'BryNex';
    $nitAliado = $aliadoObj?->nit ?? '—';
    $dirAliado = $aliadoObj?->direccion ?? '—';
    $telAliado = $aliadoObj?->telefono ?? '—';
    $correoAliado = $aliadoObj?->correo ?? '—';
    
    // Formateador de pesos
    $fmt = fn($v) => '$'.number_format($v ?? 0, 0, ',', '.');
    
    // Determinar si es colectivo o individual
    $esColectivo = $anticipo->estado === \App\Models\Anticipo::ESTADO_DISTRIBUIDO 
        || $anticipo->hijos->count() > 0 
        || ($anticipo->empresa_id && !$anticipo->contrato_id);
    
    // Configurar pagador
    if ($esColectivo) {
        $pagadorNombre = $anticipo->empresa?->empresa ?? '—';
        $pagadorDocumento = $anticipo->empresa?->nit ?? '—';
        $pagadorDireccion = $anticipo->empresa?->direccion ?? '—';
        // Celular primero: muchas empresas solo tienen celular, no fijo
        // (mismo orden que se usa para el pagador individual, abajo).
        $pagadorTelefono = $anticipo->empresa?->celular ?: ($anticipo->empresa?->telefono ?: '—');
        $pagadorTipo = 'Empresa';
    } else {
        $pagadorNombre = $anticipo->contrato?->cliente?->nombre_completo ?? '—';
        $pagadorNombre = nombre_oracion($pagadorNombre);
        $pagadorDocumento = $anticipo->contrato?->cedula ?? $anticipo->cedula ?? '—';
        $pagadorDireccion = $anticipo->contrato?->cliente?->direccion_vivienda ?? $anticipo->contrato?->cliente?->direccion_cobro ?? '—';
        $pagadorTelefono = $anticipo->contrato?->cliente?->celular ?? $anticipo->contrato?->cliente?->telefono ?? '—';
        $pagadorTipo = 'Cliente Individual';
    }

    $meses = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
    ];
@endphp

@section('contenido')
<style>
/* ─── Google Fonts ───────────────────────────── */
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap');

/* ─── PRINT ──────────────────────────────────── */
/* Hoja CARTA VERTICAL. El recibo mantiene su diseño ancho (277mm) y se
   reduce con transform:scale para caber en el ancho útil de la carta,
   quedando pegado arriba y dejando la mitad inferior en blanco. */
@page {
    size: letter portrait;
    margin: 8mm;
}
@media print {
    body * { visibility: hidden !important; }
    #recibo-print-area, #recibo-print-area * { visibility: visible !important; }
    #recibo-print-area {
        position: fixed;
        top: 0; left: 0; right: auto; bottom: auto;
        width: 277mm;                /* ancho de diseño original */
        height: auto;
        transform: scale(0.721);     /* 199.9mm útiles de carta / 277mm */
        transform-origin: top left;
        padding: 0; background: #fff; z-index: 9999;
        box-shadow: none !important;
    }
    .no-print { display: none !important; }
    .recibo-wrap { box-shadow: none !important; border-radius: 0 !important; border: none !important; overflow: visible !important; }
    .recibo-inner { margin: 0 !important; border: none !important; box-shadow: none !important; border-radius: 0 !important; overflow: visible !important; }
    .recibo-inner-wrap { margin: 0 !important; overflow: visible !important; }
    .fact-header { border: none !important; border-radius: 0 !important; }
    .fact-sello { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .hoja-fondo { background: #fff !important; padding: 0 !important; }
    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}

/* ─── Fondo tipo hoja ─────────────────────────── */
.hoja-fondo {
    background: #f1f5f9;
    padding: 1.5rem 1.2rem;
    min-height: 100vh;
}

/* ─── Base ───────────────────────────────────── */
#recibo-print-area, #recibo-print-area * { font-family: 'Inter', sans-serif; }
.recibo-wrap {
    max-width: 1150px; margin: 0 auto; background: #fff;
    border-radius: 6px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.08), 0 1px 3px rgba(0,0,0,0.05);
    overflow: hidden;
    border: 1px solid #cbd5e1;
    position: relative;
}

.recibo-inner {
    margin: 1rem 1.2rem;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    overflow: hidden;
    box-shadow: 0 1px 4px rgba(30,58,95,.07);
}

.recibo-inner-wrap {
    margin: 1rem 1.2rem 0;
    border-radius: 6px 6px 0 0;
    overflow: hidden;
}
.recibo-inner-wrap + .recibo-inner {
    margin-top: 0;
    border-top: none;
    border-radius: 0 0 6px 6px;
}

/* ─── BOTONES ─────────────────────────────────── */
.btn-a {
    padding: .4rem .9rem; border-radius: 7px; border: none;
    font-weight: 600; cursor: pointer; font-size: .82rem;
    text-decoration: none; font-family: 'Inter', sans-serif;
}

/* ─── BADGE ESTADO ───────────────────────────── */
.badge {
    display: inline-block; padding: .18rem .6rem;
    border-radius: 20px; font-size: .72rem; font-weight: 700;
}
.badge-pago  { background: #fef3c7; color: #b45309; }
.badge-pre   { background: #f1f5f9; color: #475569; }
.badge-prest { background: #ede9fe; color: #6d28d9; }
.badge-abono { background: #dcfce7; color: #15803d; }

/* ─── SELLO DIAGONAL ───────────── */
.fact-sello-wrap {
    position: absolute; top: 0; right: 0;
    width: 160px; height: 160px; overflow: hidden;
    pointer-events: none; z-index: 10;
}
.fact-sello {
    position: absolute; top: 32px; right: -32px;
    width: 170px; text-align: center;
    padding: 7px 0; font-size: .72rem; font-weight: 900;
    letter-spacing: .12em; text-transform: uppercase;
    transform: rotate(45deg);
    box-shadow: 0 3px 10px rgba(0,0,0,.15);
    border-radius: 3px;
    background: #d97706; color: #fff;
}
.sello-distribuido { background: #2563eb; }
.sello-disponible  { background: #16a34a; }
.sello-aplicado    { background: #64748b; }
.sello-devuelto    { background: #dc2626; }

/* ─── CABECERA FACTURA ───────────────────────── */
.fact-header {
    display: grid;
    grid-template-columns: 1fr auto 220px;
    gap: 0;
    border-bottom: 3px solid #d97706;
    padding: 0;
}
.fact-h-empresa {
    padding: 1rem 1.2rem;
    border-right: 1.5px solid #e2e8f0;
}
.fact-h-recibo {
    background: linear-gradient(135deg, #b45309, #d97706);
    color: #fff;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 0.6rem 1.2rem;
    min-width: 200px;
    text-align: center;
}
.fact-h-logo {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 0.75rem 1rem;
    background: #f8fafc;
    border-left: 1.5px solid #e2e8f0;
    position: relative;
}

/* ─── DATOS CLIENTE ──────────────────────────── */
.fact-cliente {
    background: linear-gradient(to right, #fffbeb, #fff);
    border-bottom: 1.5px solid #e2e8f0;
    padding: .65rem 1.2rem;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: .2rem .6rem;
    font-size: .78rem;
}
.fact-cliente-row {
    display: flex;
    gap: .4rem;
    align-items: baseline;
    padding: .12rem 0;
    border-bottom: .5px solid #fef3c7;
}
.fact-cliente-lbl {
    font-weight: 700;
    color: #92400e;
    min-width: 90px;
    font-size: .72rem;
    text-transform: uppercase;
    letter-spacing: .04em;
    flex-shrink: 0;
}
.fact-cliente-val {
    color: #0f172a;
    font-weight: 600;
    font-size: .8rem;
}

/* ─── TABLA ───────── */
.fact-body { padding: 0; }
.fact-section-title {
    background: linear-gradient(90deg, #b45309, #f59e0b);
    color: #fff;
    font-size: .65rem;
    font-weight: 800;
    letter-spacing: .1em;
    text-transform: uppercase;
    padding: .35rem 1.2rem;
}
.fact-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .79rem;
}
.fact-table th {
    background: #fffbeb;
    color: #92400e;
    font-size: .62rem;
    font-weight: 800;
    text-transform: uppercase;
    padding: .32rem .55rem;
    letter-spacing: .05em;
    border-bottom: 2px solid #fde68a;
    text-align: left;
}
.fact-table td {
    padding: .35rem .55rem;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}
.fact-table tbody tr:nth-child(odd) td  { background: #fdfdfd; }
.fact-table tbody tr:nth-child(even) td { background: #ffffff; }
.fact-table td.right { text-align: right; font-family: monospace; font-weight: 700; white-space: nowrap; }

/* ─── PIE: Nota + Total ──────────────────────── */
.fact-footer-area {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 0;
    border-top: 2px solid #d97706;
    min-height: 64px;
}
.fact-nota {
    padding: .65rem 1rem;
    font-size: .68rem;
    color: #92400e;
    background: #fffbeb;
    border-right: 1.5px solid #fde68a;
    line-height: 1.55;
    display: flex;
    align-items: center;
    gap: .5rem;
}
.fact-total-bloque {
    background: linear-gradient(135deg, #b45309, #d97706);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 2rem;
    min-width: 200px;
    text-align: center;
}
</style>

<div class="hoja-fondo">
    <!-- BARRA DE ACCIONES SUPERIOR -->
    <div class="no-print" style="max-width:1150px; margin:0 auto 1rem; display:flex; justify-content:space-between; align-items:center;">
        <div>
            @if(request()->boolean('modal'))
                <span style="font-size: 1.1rem; font-weight: 800; color: #1e293b;">📄 Vista de Recibo</span>
            @else
                @if($anticipo->empresa_id)
                    <a href="{{ route('admin.facturacion.empresa', $anticipo->empresa_id) }}" class="btn-a" style="background:#cbd5e1; color:#334155;">
                        ← Volver a Facturación
                    </a>
                @elseif($anticipo->contrato_id)
                    <a href="{{ url()->previous() }}" class="btn-a" style="background:#cbd5e1; color:#334155;">
                        ← Volver
                    </a>
                @else
                    <a href="{{ route('admin.anticipos.informe') }}" class="btn-a" style="background:#cbd5e1; color:#334155;">
                        ← Volver al Informe
                    </a>
                @endif
            @endif
        </div>
        <div style="display:flex; gap:.5rem;">
            <button onclick="window.print()" class="btn-a" style="background:#d97706; color:#fff;">
                🖨️ Imprimir Recibo
            </button>
            @if(request()->boolean('modal'))
                <button onclick="parent.cerrarRecibo()" class="btn-a" style="background:#64748b; color:#fff;">
                    Cerrar Vista
                </button>
            @endif
        </div>
    </div>

    <!-- AREA DE IMPRESION -->
    <div id="recibo-print-area">
        <div class="recibo-wrap">
            <!-- SELLO ESTADO -->
            <div class="fact-sello-wrap">
                <div class="fact-sello 
                    @if($anticipo->estado === 'distribuido') sello-distribuido
                    @elseif($anticipo->estado === 'disponible') sello-disponible
                    @elseif($anticipo->estado === 'aplicado') sello-aplicado
                    @elseif($anticipo->estado === 'devuelto') sello-devuelto
                    @endif">
                    {{ $anticipo->etiqueta_estado }}
                </div>
            </div>

            <!-- CABECERA -->
            <div class="recibo-inner-wrap">
                <div class="fact-header">
                    <!-- Datos de Empresa / Cliente -->
                    <div class="fact-h-empresa">
                        @if($esColectivo)
                            <div style="font-size:.55rem;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.07em;margin-bottom:.04rem">Empresa Cliente</div>
                            <div style="font-size:1.15rem;font-weight:900;color:#0f172a;line-height:1.15;letter-spacing:-.02em">{{ $anticipo->empresa?->empresa ?? '—' }}</div>
                            <div style="font-size:.65rem;color:#64748b;margin-top:.05rem">NIT: {{ $anticipo->empresa?->nit ?? '—' }}</div>
                        @else
                            <div style="font-size:.55rem;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.07em;margin-bottom:.04rem">Cliente</div>
                            <div style="font-size:1.15rem;font-weight:900;color:#0f172a;line-height:1.15">{{ nombre_oracion($anticipo->contrato?->cliente?->nombre_completo ?? '—') }}</div>
                            <div style="font-size:.65rem;color:#64748b;margin-top:.05rem">C.C. {{ $anticipo->contrato?->cedula ?? $anticipo->cedula ?? '—' }}</div>
                        @endif
                    </div>

                    <!-- Datos del Recibo -->
                    <div class="fact-h-recibo">
                        <span style="font-size:.62rem; font-weight:800; letter-spacing:.12em; text-transform:uppercase; opacity:0.85;">
                            Recibo de Anticipo
                        </span>
                        <h2 style="font-size:1.2rem; font-weight:900; margin:.15rem 0 .2rem 0; letter-spacing:-.02em;">
                            N° ANT-{{ str_pad($anticipo->id, 6, '0', STR_PAD_LEFT) }}
                        </h2>
                        <span style="font-size:.56rem; font-weight:700; opacity:0.85;">
                            Factura: N/A
                        </span>
                    </div>

                    <!-- Logo del Aliado -->
                    <div class="fact-h-logo">
                        @if($logoAliado)
                            <img src="{{ $logoAliado }}" alt="{{ $nomAliado }}" style="max-width:140px; max-height:70px; object-fit:contain">
                        @else
                            <img src="{{ asset('img/logo-brynex-claro.png') }}" alt="BryNex" style="max-width:140px; max-height:70px; object-fit:contain" onerror="this.style.display='none'">
                        @endif
                        <div style="font-size:.55rem;font-weight:800;color:#64748b;margin-top:.4rem;letter-spacing:.05em;text-align:center;text-transform:uppercase">
                            {{ strtoupper($nomAliado) }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- DATOS DEL PAGADOR -->
            <div class="fact-cliente">
                @if($esColectivo)
                    <div class="fact-cliente-row">
                        <span class="fact-cliente-lbl">Pagador ({{ $pagadorTipo }}):</span>
                        <span class="fact-cliente-val">{{ $pagadorNombre }}</span>
                    </div>
                    <div class="fact-cliente-row">
                        <span class="fact-cliente-lbl">Identificación:</span>
                        <span class="fact-cliente-val">{{ $pagadorDocumento }}</span>
                    </div>
                    <div class="fact-cliente-row">
                        <span class="fact-cliente-lbl">Dirección:</span>
                        <span class="fact-cliente-val">{{ $pagadorDireccion }}</span>
                    </div>
                    <div class="fact-cliente-row">
                        <span class="fact-cliente-lbl">Teléfono:</span>
                        <span class="fact-cliente-val">{{ $pagadorTelefono }}</span>
                    </div>
                @else
                    <div class="fact-cliente-row" style="grid-column: span 2;">
                        <span class="fact-cliente-lbl">Pagador ({{ $pagadorTipo }}):</span>
                        <span class="fact-cliente-val" style="color: #92400e; font-size: 0.85rem; font-weight: 800;">{{ $pagadorNombre }}</span>
                    </div>
                    <div class="fact-cliente-row">
                        <span class="fact-cliente-lbl">Identificación:</span>
                        <span class="fact-cliente-val">{{ $pagadorDocumento }}</span>
                    </div>
                    <div class="fact-cliente-row">
                        <span class="fact-cliente-lbl">Teléfono:</span>
                        <span class="fact-cliente-val">{{ $pagadorTelefono }}</span>
                    </div>
                    <div class="fact-cliente-row">
                        <span class="fact-cliente-lbl">Dirección:</span>
                        <span class="fact-cliente-val">{{ $pagadorDireccion }}</span>
                    </div>
                    <div class="fact-cliente-row">
                        <span class="fact-cliente-lbl">Modalidad:</span>
                        <span class="fact-cliente-val">{{ $anticipo->contrato?->tipoModalidad?->tipo_modalidad ?? '—' }}</span>
                    </div>
                    <div class="fact-cliente-row">
                        <span class="fact-cliente-lbl">EPS:</span>
                        <span class="fact-cliente-val">{{ $anticipo->contrato?->eps?->nombre ?? '—' }}</span>
                    </div>
                    <div class="fact-cliente-row">
                        <span class="fact-cliente-lbl">ARL:</span>
                        @php
                            $arlNom = $anticipo->contrato?->arl?->nombre_arl;
                            if (!$arlNom) {
                                $arlNit = $anticipo->contrato?->razonSocial?->arl_nit;
                                $arlNom = $arlNit ? (\App\Models\Arl::where('nit', $arlNit)->value('nombre_arl') ?? $arlNit) : '—';
                            }
                        @endphp
                        <span class="fact-cliente-val">{{ $arlNom }}</span>
                    </div>
                    <div class="fact-cliente-row">
                        <span class="fact-cliente-lbl">Pensión:</span>
                        <span class="fact-cliente-val">{{ $anticipo->contrato?->pension?->razon_social ?? '—' }}</span>
                    </div>
                    <div class="fact-cliente-row">
                        <span class="fact-cliente-lbl">Caja:</span>
                        <span class="fact-cliente-val">{{ $anticipo->contrato?->caja?->nombre ?? 'no aplica' }}</span>
                    </div>
                @endif
            </div>

            <!-- DETALLE DE DISTRIBUCIÓN -->
            <div class="fact-body">
                <div class="fact-section-title">
                    Detalle de los Beneficiarios / Contratos Asociados
                </div>
                <table class="fact-table">
                    <thead>
                        <tr>
                            <th style="width: 50px; text-align: center;">Item</th>
                            <th>Cliente</th>
                            <th>Identificación</th>
                            <th>Contrato N°</th>
                            <th>Plan / Convenio</th>
                            <th style="text-align: right; width: 150px; padding-right: 1.5rem;">Valor Asignado</th>
                        </tr>
                    </thead>
                    <tbody>
                        @if($esColectivo)
                            @foreach($anticipo->hijos as $index => $hijo)
                                <tr>
                                    <td style="text-align: center; font-weight: 700; color: #64748b;">
                                        {{ $index + 1 }}
                                    </td>
                                    <td style="font-weight: 700; color: #0f172a;">
                                        {{ nombre_oracion($hijo->contrato?->cliente?->nombre_completo ?? '—') }}
                                    </td>
                                    <td style="font-family: monospace; font-size: 0.75rem;">
                                        {{ $hijo->cedula }}
                                    </td>
                                    <td style="font-weight: 700; color: #64748b;">
                                        {{ $hijo->contrato_id }}
                                    </td>
                                    <td style="color: #b45309; font-weight: 600;">
                                        {{ $hijo->contrato?->plan?->nombre ?? '—' }}
                                    </td>
                                    <td class="right" style="padding-right: 1.5rem;">
                                        {{ $fmt($hijo->valor) }}
                                    </td>
                                </tr>
                            @endforeach
                        @else
                            <tr>
                                <td style="text-align: center; font-weight: 700; color: #64748b;">1</td>
                                <td style="font-weight: 700; color: #0f172a;">
                                    {{ nombre_oracion($anticipo->contrato?->cliente?->nombre_completo ?? '—') }}
                                </td>
                                <td style="font-family: monospace; font-size: 0.75rem;">
                                    {{ $anticipo->contrato?->cedula ?? $anticipo->cedula ?? '—' }}
                                </td>
                                <td style="font-weight: 700; color: #64748b;">
                                    {{ $anticipo->contrato_id ?? '—' }}
                                </td>
                                <td style="color: #b45309; font-weight: 600;">
                                    {{ $anticipo->contrato?->plan?->nombre ?? '—' }}
                                </td>
                                <td class="right" style="padding-right: 1.5rem;">
                                    {{ $fmt($anticipo->valor) }}
                                </td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>

            <!-- DETALLES DE PAGO Y FIRMA -->
            <div class="fact-footer-area">
                <!-- Información de Pago -->
                <div class="fact-nota" style="font-size: 0.72rem;">
                    <div style="flex: 1;">
                        <b>Forma de Pago:</b> {{ ucfirst($anticipo->forma_pago) }} 
                        @if($anticipo->bancoCuenta) 
                            ({{ $anticipo->bancoCuenta->descripcion }})
                        @endif
                        @if($anticipo->empresa_id && in_array($anticipo->origen, ['empresa', 'clientes']))
                            <br>
                            <b>Dinero recibido de:</b>
                            {{ $anticipo->origen === 'clientes' ? 'Los clientes (aporte individual)' : 'La empresa' }}
                        @endif
                        <br>
                        <b>Fecha de Pago:</b> {{ $anticipo->fecha_pago->format('d/m/Y') }} 
                        @if($anticipo->referencia) 
                            | <b>Referencia:</b> {{ $anticipo->referencia }}
                        @endif
                        @if($anticipo->observacion)
                            <br><b>Observación:</b> {{ $anticipo->observacion }}
                        @endif
                        <br>
                        <span style="font-size: 0.65rem; color: #64748b;">
                            Registrado por: {{ $anticipo->usuario?->name ?? 'Sistema' }} el {{ $anticipo->created_at->format('d/m/Y h:i a') }}
                        </span>
                    </div>
                    <!-- Área de firma digitalizada o sello -->
                    <div style="width: 180px; text-align: center; border-left: 1px dashed #fcd34d; padding-left: 1rem; font-size: 0.6rem; color: #92400e;">
                        <div style="height: 40px;"></div>
                        <div style="border-top: 1px solid #92400e; padding-top: 0.15rem; font-weight: 700;">
                            Recibido Conforme
                        </div>
                    </div>
                </div>

                <!-- Bloque de Total -->
                <div class="fact-total-bloque">
                    <div style="text-align: center;">
                        <span style="font-size: 0.62rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; opacity: 0.9;">
                            Total Recibido
                        </span>
                        <h1 style="font-size: 1.6rem; font-weight: 950; margin: 0.1rem 0 0 0; font-family: monospace;">
                            {{ $fmt($anticipo->valor) }}
                        </h1>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
