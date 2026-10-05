@extends('layouts.app')
@section('modulo', 'Facturas del Día')

@php
$fmt = fn($v) => '$'.number_format($v ?? 0, 0, ',', '.');

$colorTipo = [
    'planilla'     => '#1d4ed8',
    'afiliacion'   => '#15803d',
    'retiro'       => '#b45309',
    'prestamo'     => '#7c3aed',
    'otro_ingreso' => '#64748b',
];

$ruta     = route('admin.cuadre-diario.facturas-dia');
// array_filter sin callback se comería tipo_modalidad_id=0 (Dependiente E),
// que es una modalidad real y la más frecuente.
$qsExport = array_filter(
    request()->only(['fecha','tipo','forma_pago','banco_cuenta_id','empresa_id','usuario_id','razon_social_id','tipo_modalidad_id','sort','dir']),
    fn($v) => $v !== null && $v !== ''
);
$hayFiltro = request()->hasAny(['tipo','forma_pago','banco_cuenta_id','empresa_id','usuario_id','razon_social_id','tipo_modalidad_id']);

// Filtro de modalidad activo: '0' es un id válido, así que no vale un truthy.
$modalSel = request('tipo_modalidad_id');
$modalSel = ($modalSel === null || $modalSel === '') ? null : (string) $modalSel;

// Enlace de ordenamiento: repite la columna → alterna asc/desc
$sortUrl = function (string $col) use ($sort, $dir) {
    $nuevo = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    return request()->fullUrlWithQuery(['sort' => $col, 'dir' => $nuevo]);
};
$sortIco = fn(string $col) => $sort === $col ? ($dir === 'asc' ? ' ▲' : ' ▼') : ' ↕';
@endphp

@section('contenido')
<style>
.fd-header{background:linear-gradient(135deg,#0f172a,#1e3a5f);border-radius:14px;color:#fff;padding:1rem 1.4rem;margin-bottom:1rem}
.fd-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.7rem;margin-bottom:.8rem}
.fd-card{background:#fff;border-radius:10px;border:1px solid #e2e8f0;padding:.7rem .9rem}
.fd-card .lbl{font-size:.66rem;font-weight:700;text-transform:uppercase;color:#64748b;letter-spacing:.04em}
.fd-card .val{font-size:1.15rem;font-weight:800;margin-top:.15rem}
table.tbl{width:100%;border-collapse:collapse;font-size:.78rem}
.tbl th{background:#0f172a;color:#94a3b8;font-size:.62rem;text-transform:uppercase;letter-spacing:.05em;padding:.45rem .55rem;position:sticky;top:0;white-space:nowrap;z-index:2;vertical-align:bottom}
.tbl td{padding:.35rem .55rem;border-bottom:1px solid #f1f5f9;white-space:nowrap}
.tbl tr:hover td{background:#f8fafc}
.tbl tfoot td{background:#f1f5f9;font-weight:800;border-top:2px solid #cbd5e1;position:sticky;bottom:0}
.num{text-align:right;font-family:ui-monospace,monospace}
.badge-tipo{padding:.1rem .45rem;border-radius:20px;font-size:.64rem;font-weight:700;color:#fff}
.btn-exp{padding:.35rem .85rem;font-size:.78rem;font-weight:700;border-radius:7px;background:#16a34a;color:#fff;text-decoration:none;display:inline-block}

/* Selector de fecha: claro para que resalte sobre el encabezado oscuro.
   color-scheme:light deja visible el ícono nativo del calendario. */
.fd-fecha{
    color-scheme:light;
    padding:.32rem .6rem;border-radius:7px;border:1.5px solid #60a5fa;
    background:#fff;color:#0f172a;font-size:.8rem;font-weight:700;
    cursor:pointer;outline:none;transition:box-shadow .12s,border-color .12s;
}
.fd-fecha:hover{border-color:#3b82f6}
.fd-fecha:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.35)}
.fd-fecha::-webkit-calendar-picker-indicator{cursor:pointer;opacity:.7}
.fd-fecha::-webkit-calendar-picker-indicator:hover{opacity:1}

/* Desplegable dentro del encabezado (mismo patrón que Cobros) */
.th-select{
    width:100%;background:transparent;border:none;border-bottom:1px solid rgba(255,255,255,.15);
    color:#94a3b8;font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;
    padding:.2rem .2rem;cursor:pointer;outline:none;appearance:auto;-webkit-appearance:auto;
}
.th-select:hover{border-bottom-color:rgba(255,255,255,.5)}
.th-select:focus{border-bottom-color:#3b82f6}
.th-select option{background:#0f172a;color:#fff;font-weight:600;text-transform:none}
.th-select.activo{border-bottom-color:#3b82f6;color:#93c5fd}

/* Encabezado ordenable */
.th-sort{color:#94a3b8;text-decoration:none;cursor:pointer;white-space:nowrap}
.th-sort:hover{color:#fff}
.th-sort.activo{color:#93c5fd}

/* Número de factura: abre el recibo en modal */
.link-fact{background:none;border:none;padding:0;font:inherit;font-weight:800;color:#dc2626;cursor:pointer;text-decoration:underline;text-underline-offset:2px}
.link-fact:hover{color:#991b1b}

/* Modal del recibo (mismo patrón que cuadre-diario/bancos) */
.modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;align-items:center;justify-content:center}
.modal-bg.open{display:flex}
.modal-box{background:#fff;border-radius:14px;width:min(900px,98vw);max-height:94vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.35)}
.modal-head{background:#1e3a5f;padding:.75rem 1rem;display:flex;justify-content:space-between;align-items:center}
.btn-close{background:rgba(255,255,255,.18);color:#fff;border:none;border-radius:5px;width:28px;height:28px;cursor:pointer;font-weight:800;font-size:1rem}
</style>

<div class="fd-header">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.6rem">
        <div>
            <a href="{{ route('admin.cuadre-diario.index') }}"
               style="color:#94a3b8;font-size:.78rem;text-decoration:none">← Volver a mi cuadre</a>
            <div style="font-size:1.1rem;font-weight:800;margin-top:.2rem">
                🧾 Facturas del Día
                <span style="color:#93c5fd;font-weight:600">
                    {{ \Carbon\Carbon::parse($fecha)->translatedFormat('d M Y') }}
                </span>
            </div>
        </div>
        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
            <form method="GET" action="{{ $ruta }}" style="margin:0;display:flex;gap:.4rem;align-items:center">
                @foreach(request()->except(['fecha','page']) as $k => $v)
                    <input type="hidden" name="{{ $k }}" value="{{ $v }}">
                @endforeach
                <input type="date" name="fecha" value="{{ $fecha }}" onchange="this.value && this.form.submit()"
                       class="fd-fecha" title="Cambiar de día">
            </form>
            @if($hayFiltro)
            <a href="{{ $ruta }}?fecha={{ $fecha }}"
               style="color:#fca5a5;font-size:.75rem;text-decoration:none">✕ Limpiar filtros</a>
            @endif
            <a href="{{ route('admin.cuadre-diario.facturas-dia.exportar', $qsExport) }}" class="btn-exp">
                ⬇ Exportar Excel
            </a>
        </div>
    </div>
</div>

{{-- Tarjetas de totales --}}
<div class="fd-cards">
    <div class="fd-card">
        <div class="lbl">Facturas</div>
        <div class="val" style="color:#0f172a">{{ $totales['cantidad'] }}</div>
    </div>
    <div class="fd-card">
        <div class="lbl">Pago total</div>
        <div class="val" style="color:#1d4ed8">{{ $fmt($totales['total']) }}</div>
    </div>
    <div class="fd-card">
        <div class="lbl">💵 Efectivo</div>
        <div class="val" style="color:#15803d">{{ $fmt($totales['efectivo']) }}</div>
    </div>
    <div class="fd-card">
        <div class="lbl">🏦 Consignado</div>
        <div class="val" style="color:#0369a1">{{ $fmt($totales['consignado']) }}</div>
    </div>
    <div class="fd-card">
        <div class="lbl">Seg. social</div>
        <div class="val" style="color:#475569">{{ $fmt($totales['seg_social']) }}</div>
        @if($hayParafiscales ?? false)
        <div style="font-size:.66rem;color:#c2410c;margin-top:.15rem">
            incluye {{ $fmt($totales['parafiscales']) }} de parafiscales
        </div>
        @endif
    </div>
    <div class="fd-card">
        <div class="lbl">Admón</div>
        <div class="val" style="color:#475569">{{ $fmt($totales['admon'] + $totales['asesor']) }}</div>
    </div>
</div>

<div style="background:#fff;border-radius:12px;border:1px solid #e2e8f0;overflow:hidden">
    <div style="overflow:auto;max-height:70vh">
    <table class="tbl">
        <thead><tr>
            <th>No.</th>
            <th><a href="{{ $sortUrl('factura') }}" class="th-sort {{ $sort === 'factura' ? 'activo' : '' }}">Factura{{ $sortIco('factura') }}</a></th>

            {{-- Tipo --}}
            <th>
                <form method="GET" action="{{ $ruta }}" style="margin:0">
                    @foreach(request()->except(['tipo','page']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                    <select name="tipo" onchange="this.form.submit()" class="th-select {{ request('tipo') ? 'activo' : '' }}">
                        <option value="">↓ Tipo</option>
                        @foreach($tiposDisp as $k => $label)
                        <option value="{{ $k }}" @selected(request('tipo') === $k)>{{ $label }}</option>
                        @endforeach
                    </select>
                </form>
            </th>

            <th><a href="{{ $sortUrl('cedula') }}" class="th-sort {{ $sort === 'cedula' ? 'activo' : '' }}">Cédula{{ $sortIco('cedula') }}</a></th>
            <th>Nombres</th>

            {{-- Forma de pago --}}
            <th>
                <form method="GET" action="{{ $ruta }}" style="margin:0">
                    @foreach(request()->except(['forma_pago','page']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                    <select name="forma_pago" onchange="this.form.submit()" class="th-select {{ request('forma_pago') ? 'activo' : '' }}">
                        <option value="">↓ Forma pago</option>
                        @foreach($formasDisp as $k => $label)
                        <option value="{{ $k }}" @selected(request('forma_pago') === $k)>{{ $label }}</option>
                        @endforeach
                    </select>
                </form>
            </th>

            <th class="num">Pago total</th>
            <th class="num">Efectivo</th>
            <th class="num">Consignado</th>
            <th class="num">Admón empresa</th>
            <th class="num">Admón asesor</th>
            <th class="num">Seguro</th>
            @if($hayParafiscales ?? false)<th class="num" style="color:#c2410c">Parafiscales</th>@endif
            <th class="num">Seg. social</th>
            <th class="num">IVA</th>

            {{-- Empresa --}}
            <th style="min-width:130px">
                <form method="GET" action="{{ $ruta }}" style="margin:0">
                    @foreach(request()->except(['empresa_id','page']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                    <select name="empresa_id" onchange="this.form.submit()" class="th-select {{ request('empresa_id') ? 'activo' : '' }}">
                        <option value="">↓ Empresa</option>
                        @if($hayIndiv)
                        <option value="individuales" @selected(request('empresa_id') === 'individuales')>👤 INDIVIDUALES</option>
                        @endif
                        @foreach($empresasDisp as $e)
                        <option value="{{ $e->id }}" @selected(request('empresa_id') == $e->id)>
                            🏢 {{ \Illuminate\Support\Str::limit($e->empresa, 25, '…') }}
                        </option>
                        @endforeach
                    </select>
                </form>
            </th>

            {{-- Razón social --}}
            <th style="min-width:130px">
                <form method="GET" action="{{ $ruta }}" style="margin:0">
                    @foreach(request()->except(['razon_social_id','page']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                    <select name="razon_social_id" onchange="this.form.submit()" class="th-select {{ request('razon_social_id') ? 'activo' : '' }}">
                        <option value="">↓ Razón social</option>
                        @foreach($razonesDisp as $rs)
                        <option value="{{ $rs->id }}" @selected(request('razon_social_id') == $rs->id)>
                            {{ \Illuminate\Support\Str::limit($rs->razon_social, 25, '…') }}
                        </option>
                        @endforeach
                    </select>
                </form>
            </th>

            {{-- Modalidad --}}
            <th style="min-width:85px">
                <form method="GET" action="{{ $ruta }}" style="margin:0">
                    @foreach(request()->except(['tipo_modalidad_id','page']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                    {{-- "Mod." y no "Tipo": la tercera columna ya se llama así --}}
                    <select name="tipo_modalidad_id" onchange="this.form.submit()" class="th-select {{ $modalSel !== null ? 'activo' : '' }}">
                        <option value="">↓ Mod.</option>
                        @if($haySinModal)
                        <option value="sin" @selected($modalSel === 'sin')>— Sin modalidad</option>
                        @endif
                        @foreach($modalidadesDisp as $m)
                        <option value="{{ $m->id }}" @selected($modalSel === (string) $m->id)>
                            {{ $m->tipo_modalidad }} — {{ \Illuminate\Support\Str::limit($m->nombre, 22, '…') }}
                        </option>
                        @endforeach
                    </select>
                </form>
            </th>

            {{-- Banco --}}
            <th style="min-width:120px">
                <form method="GET" action="{{ $ruta }}" style="margin:0">
                    @foreach(request()->except(['banco_cuenta_id','page']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                    <select name="banco_cuenta_id" onchange="this.form.submit()" class="th-select {{ request('banco_cuenta_id') ? 'activo' : '' }}">
                        <option value="">↓ Banco</option>
                        @foreach($bancosDisp as $b)
                        <option value="{{ $b->id }}" @selected(request('banco_cuenta_id') == $b->id)>
                            {{ $b->nombre ?: $b->banco }} — {{ $b->banco }}
                        </option>
                        @endforeach
                    </select>
                </form>
            </th>

            {{-- Facturó --}}
            <th style="min-width:120px">
                <form method="GET" action="{{ $ruta }}" style="margin:0">
                    @foreach(request()->except(['usuario_id','page']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                    <select name="usuario_id" onchange="this.form.submit()" class="th-select {{ request('usuario_id') ? 'activo' : '' }}">
                        <option value="">↓ Facturó</option>
                        @foreach($usuariosDisp as $u)
                        <option value="{{ $u->id }}" @selected(request('usuario_id') == $u->id)>{{ $u->nombre }}</option>
                        @endforeach
                    </select>
                </form>
            </th>
        </tr></thead>

        <tbody>
        @forelse($facturas as $i => $f)
        <tr>
            <td style="color:#94a3b8">{{ $i + 1 }}</td>
            <td>
                <button type="button" class="link-fact" onclick="abrirRecibo({{ $f->id }})"
                        title="Ver recibo">{{ $f->numero_factura }}</button>
            </td>
            <td>
                <span class="badge-tipo" style="background:{{ $colorTipo[$f->tipo_dia] ?? '#64748b' }}">
                    {{ $tipos[$f->tipo_dia] ?? $f->tipo_dia }}
                </span>
            </td>
            <td class="num">{{ $f->cedula }}</td>
            <td style="font-weight:600">{{ nombre_oracion($f->nombre_cliente) }}</td>
            <td>{{ $formas[$f->forma_pago] ?? $f->forma_pago }}</td>
            <td class="num" style="font-weight:800;color:#1d4ed8">{{ $fmt($f->total) }}</td>
            <td class="num" style="color:#15803d">{{ $fmt($f->valor_efectivo) }}</td>
            <td class="num" style="color:#0369a1">{{ $fmt($f->valor_consignado) }}</td>
            <td class="num">{{ $fmt($f->admon) }}</td>
            <td class="num">{{ $fmt($f->admin_asesor) }}</td>
            <td class="num">{{ $fmt($f->seguro) }}</td>
            @if($hayParafiscales ?? false)
            <td class="num" style="color:#c2410c">{{ $fmt($f->v_parafiscales) }}</td>
            @endif
            <td class="num">{{ $fmt($f->total_ss) }}</td>
            <td class="num">{{ $fmt($f->iva) }}</td>
            @php
                $empresaTxt = $f->empresa?->empresa ?? 'INDIVIDUALES';
                $bancoTxt   = $f->banco_texto ?: '—';
            @endphp
            <td style="font-size:.72rem" title="{{ $empresaTxt }}">
                {{ \Illuminate\Support\Str::limit($empresaTxt, 20, '…') }}
            </td>
            <td style="font-size:.72rem;color:#b45309" title="{{ $f->razon_social_texto }}">
                {{ \Illuminate\Support\Str::limit($f->razon_social_texto, 20, '…') }}
            </td>
            <td style="font-size:.72rem;color:#475569" title="{{ $f->modalidad_nombre }}">
                {{ $f->modalidad_texto }}
            </td>
            <td style="font-size:.72rem" title="{{ $bancoTxt }}">
                {{ \Illuminate\Support\Str::limit($bancoTxt, 20, '…') }}
            </td>
            <td style="font-size:.72rem;color:#64748b">{{ $f->usuario?->nombre ?? '—' }}</td>
        </tr>
        @empty
        <tr><td colspan="19" style="padding:2.5rem;text-align:center;color:#94a3b8">
            Sin facturas para la fecha y filtros seleccionados
        </td></tr>
        @endforelse
        </tbody>

        @if($facturas->isNotEmpty())
        <tfoot><tr>
            <td colspan="6">TOTALES ({{ $totales['cantidad'] }} facturas)</td>
            <td class="num">{{ $fmt($totales['total']) }}</td>
            <td class="num">{{ $fmt($totales['efectivo']) }}</td>
            <td class="num">{{ $fmt($totales['consignado']) }}</td>
            <td class="num">{{ $fmt($totales['admon']) }}</td>
            <td class="num">{{ $fmt($totales['asesor']) }}</td>
            <td class="num"></td>
            @if($hayParafiscales ?? false)
            <td class="num" style="color:#c2410c">{{ $fmt($totales['parafiscales']) }}</td>
            @endif
            <td class="num">{{ $fmt($totales['seg_social']) }}</td>
            <td class="num">{{ $fmt($totales['iva']) }}</td>
            <td colspan="5"></td>
        </tr></tfoot>
        @endif
    </table>
    </div>
</div>

{{-- Quién recibió el dinero (solo usuarios con efectivo o consignación) --}}
@if($porUsuario->isNotEmpty())
<div style="background:#fff;border-radius:12px;border:1px solid #e2e8f0;overflow:hidden;margin-top:.8rem">
    <div style="padding:.55rem .9rem;background:#f8fafc;border-bottom:1px solid #e2e8f0;font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.05em;color:#475569">
        👤 Recaudo por usuario que facturó
    </div>
    <div style="overflow-x:auto">
    <table class="tbl">
        <thead><tr>
            <th>Usuario</th>
            <th class="num">Facturas</th>
            <th class="num">Pago total</th>
            <th class="num">💵 Efectivo</th>
            <th class="num">🏦 Consignado</th>
        </tr></thead>
        <tbody>
        @foreach($porUsuario as $nombre => $t)
        <tr>
            <td style="font-weight:700">{{ $nombre }}</td>
            <td class="num">{{ $t['cantidad'] }}</td>
            <td class="num" style="font-weight:800;color:#1d4ed8">{{ $fmt($t['total']) }}</td>
            <td class="num" style="color:#15803d">{{ $fmt($t['efectivo']) }}</td>
            <td class="num" style="color:#0369a1">{{ $fmt($t['consignado']) }}</td>
        </tr>
        @endforeach
        </tbody>
    </table>
    </div>
</div>
@endif

{{-- ═══ MODAL: Recibo de la factura ═══ --}}
<div id="modal-recibo" class="modal-bg" onclick="if(event.target===this)cerrarRecibo()">
    <div class="modal-box">
        <div class="modal-head">
            <span style="color:#fff;font-weight:700;font-size:.9rem">📋 Recibo de Factura</span>
            <div style="display:flex;gap:.4rem;align-items:center">
                <a id="btn-abrir-recibo" href="#" target="_blank"
                   style="background:rgba(255,255,255,.18);color:#fff;text-decoration:none;border-radius:5px;padding:.3rem .7rem;font-size:.78rem;font-weight:600">🔗 Abrir</a>
                <button onclick="cerrarRecibo()" class="btn-close">×</button>
            </div>
        </div>
        <div style="padding:0;flex:1;overflow:hidden">
            <iframe id="iframe-recibo" src="" style="width:100%;height:82vh;border:none"></iframe>
        </div>
    </div>
</div>

<script>
const FD_BASE_URL = '{{ url('') }}';

function abrirRecibo(facturaId) {
    const url = `${FD_BASE_URL}/admin/facturacion/recibo/${facturaId}?modal=1`;
    document.getElementById('iframe-recibo').src = url;
    document.getElementById('btn-abrir-recibo').href = url;
    document.getElementById('modal-recibo').classList.add('open');
}

function cerrarRecibo() {
    document.getElementById('modal-recibo').classList.remove('open');
    // Limpiar el src evita que el iframe siga cargado en segundo plano
    document.getElementById('iframe-recibo').src = '';
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') cerrarRecibo();
});
</script>
@endsection
