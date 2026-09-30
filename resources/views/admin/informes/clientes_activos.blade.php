@extends('layouts.app')
@section('modulo','Clientes Activos')

@php
// Los textos largos (razón social, empresa, entidades) se cortan a 20 letras
// para que cada contrato quepa en una sola fila; el nombre entero va en el title.
$corto = fn($v) => $v ? \Illuminate\Support\Str::limit($v, 20, '…') : '—';

// Cada filtro del encabezado es su propio form: conserva los demás filtros
// y la búsqueda, y vuelve a la página 1.
$ocultos = fn($campo) => collect(request()->except([$campo, 'page', 'excel']))
    ->filter(fn($v) => $v !== null && $v !== '' && !is_array($v));

$filtros = [
    ['razon_social_id',   'Razón Social', $fRazon,     $razones,     fn($o) => $o->razon_social],
    ['eps_id',            'EPS',          $fEps,       $epsList,     fn($o) => $o->nombre],
    ['caja_id',           'Caja',         $fCaja,      $cajas,       fn($o) => $o->nombre],
    ['pension_id',        'Pensión',      $fPension,   $pensiones,   fn($o) => $o->razon_social],
    ['tipo_modalidad_id', 'Modalidad',    $fModalidad, $modalidades, fn($o) => $o->observacion ?: $o->tipo_modalidad],
    ['plan_id',           'Plan',         $fPlan,      $planes,      fn($o) => $o->nombre],
];
$filtroTh = [];
foreach ($filtros as [$campo, $titulo, $actual, $opciones, $texto]) {
    $filtroTh[$campo] = compact('campo', 'titulo', 'actual', 'opciones', 'texto');
}
$hayFiltros = $buscar || $fRazon || $fEps || $fCaja || $fPension || $fModalidad || $fPlan;
@endphp

@section('contenido')
<style>
.ca-wrap { display:flex; flex-direction:column; gap:.5rem; }

.ca-header {
    background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 60%,#1e40af 100%);
    border-radius:12px; padding:.6rem 1.2rem; color:#fff;
    display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.6rem;
}
.ca-title { font-size:1.2rem; font-weight:800; letter-spacing:.02em; display:flex; align-items:center; gap:.6rem; }
.ca-title a { color:#94a3b8; font-size:.75rem; font-weight:600; text-decoration:none; }
.ca-title a:hover { color:#fff; }
.ca-sub { font-size:.72rem; color:#94a3b8; margin-top:.15rem; }

.ca-acciones { display:flex; align-items:center; gap:.4rem; flex-wrap:wrap; }
.ca-acciones form { display:flex; gap:.4rem; margin:0; }
.ca-buscar {
    min-width:220px; padding:.35rem .65rem; height:30px; box-sizing:border-box;
    border:1px solid rgba(255,255,255,.2); border-radius:8px; outline:none;
    background:rgba(255,255,255,.08); color:#fff; font-size:.78rem;
}
.ca-buscar::placeholder { color:#94a3b8; }
.ca-buscar:focus { border-color:#3b82f6; background:rgba(255,255,255,.14); }
.ca-btn {
    height:30px; box-sizing:border-box; display:inline-flex; align-items:center; gap:.3rem;
    padding:.3rem .8rem; border-radius:8px; font-size:.76rem; font-weight:600;
    text-decoration:none; cursor:pointer; border:none; transition:background .15s;
}
.ca-btn-azul  { background:#2563eb; color:#fff; }
.ca-btn-azul:hover { background:#3b82f6; }
.ca-btn-glass { background:rgba(59,130,246,.15); border:1px solid rgba(59,130,246,.35); color:#93c5fd; }
.ca-btn-glass:hover { background:rgba(59,130,246,.3); }
.ca-btn-verde { background:#10b981; color:#fff; }
.ca-btn-verde:hover { background:#059669; }
.ca-kpi {
    background:rgba(59,130,246,.15); border:1px solid rgba(59,130,246,.35); color:#93c5fd;
    border-radius:999px; padding:.2rem .7rem; font-size:.72rem; font-weight:700; white-space:nowrap;
}
.ca-kpi strong { color:#fff; }

.ca-tbl-wrap {
    overflow:auto; border-radius:12px; border:1px solid #e2e8f0; background:#fff;
    max-height:calc(100vh - 220px);
}
.ca-tbl { width:100%; border-collapse:collapse; font-size:.75rem; white-space:nowrap; }
.ca-tbl thead th {
    background:#0f172a; color:#cbd5e1; padding:.5rem .45rem; text-align:left;
    font-weight:600; font-size:.68rem; text-transform:uppercase; letter-spacing:.04em;
    position:sticky; top:0; z-index:2;
}
.ca-tbl thead th.num { text-align:right; }
.th-select {
    width:100%; min-width:80px; background:transparent; border:none; border-bottom:1px solid rgba(255,255,255,.15);
    color:#fff; font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em;
    padding:.2rem .2rem; cursor:pointer; outline:none;
}
.th-select:hover { border-bottom-color:rgba(255,255,255,.5); }
.th-select:focus { border-bottom-color:#3b82f6; }
.th-select option { background:#0f172a; color:#fff; font-weight:600; text-transform:none; }
.th-select.activo { border-bottom-color:#3b82f6; color:#93c5fd; }

.ca-tbl tbody tr { border-bottom:1px solid #f1f5f9; transition:background .12s; }
.ca-tbl tbody tr:hover { background:#f8fafc; }
.ca-tbl td { padding:.4rem .45rem; vertical-align:middle; color:#475569; }
.ca-tbl td.num { text-align:right; font-variant-numeric:tabular-nums; }
.ca-tbl .c-nom { color:#0f172a; font-weight:600; }
.ca-tbl .c-ced {
    background:none; border:none; color:#3b82f6; font-weight:700; cursor:pointer; padding:0;
    font-family:monospace; font-size:.77rem; text-decoration:underline dotted;
}
.ca-tbl .c-ced:hover { color:#1e40af; }
.ca-tbl .c-cel { display:inline-flex; align-items:center; gap:.3rem; }
.ca-tbl .c-vacio { color:#cbd5e1; }
.ca-arl {
    display:inline-block; background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe;
    border-radius:20px; padding:.05rem .45rem; font-size:.66rem; font-weight:700;
}
.ca-vacia { padding:2.5rem; text-align:center; color:#94a3b8; }
@keyframes spinIframe { to { transform:rotate(360deg); } }
</style>

<div class="ca-wrap">
    {{-- ══ Encabezado ══ --}}
    <div class="ca-header">
        <div>
            <div class="ca-title">
                <a href="{{ route('admin.informes.hub') }}">← Informes</a>
                <span>👥 Clientes Activos</span>
            </div>
            <div class="ca-sub">Contratos vigentes · filtre desde el título de cada columna · clic en la cédula abre el contrato</div>
        </div>
        <div class="ca-acciones">
            @if($hayFiltros)
            <span class="ca-kpi">Filtrados <strong>{{ number_format($clientes->total(),0,',','.') }}</strong></span>
            @endif
            <span class="ca-kpi"><strong>{{ number_format($totalClientes,0,',','.') }}</strong> clientes / <strong>{{ number_format($total,0,',','.') }}</strong> contratos</span>
            <form method="GET">
                @foreach($ocultos('q') as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                <input name="q" value="{{ $buscar }}" placeholder="🔍 Cédula o nombre…" class="ca-buscar">
                <button type="submit" class="ca-btn ca-btn-azul">Buscar</button>
            </form>
            @if($hayFiltros)
            <a href="{{ route('admin.informes.clientes_activos') }}" class="ca-btn ca-btn-glass">✕ Limpiar</a>
            @endif
            <a href="?{{ http_build_query(array_merge(request()->except(['page']),['excel'=>1])) }}" class="ca-btn ca-btn-verde">📥 Excel</a>
        </div>
    </div>

    {{-- ══ Tabla ══ --}}
    <div class="ca-tbl-wrap">
        <svg width="0" height="0" style="position:absolute" aria-hidden="true"><symbol id="ico-wa" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></symbol></svg>
        <table class="ca-tbl">
            <thead>
                <tr>
                    <th>Cédula</th>
                    <th>Nombre</th>
                    <th>Celular</th>
                    @include('admin.informes.partials.th_filtro', $filtroTh['razon_social_id'] + ['ocultos' => $ocultos('razon_social_id')])
                    <th>Empresa</th>
                    @foreach(['eps_id','caja_id','pension_id','tipo_modalidad_id','plan_id'] as $campo)
                        @include('admin.informes.partials.th_filtro', $filtroTh[$campo] + ['ocultos' => $ocultos($campo)])
                    @endforeach
                    <th>ARL</th>
                    <th class="num">F. Ingreso</th>
                    <th class="num">Salario</th>
                </tr>
            </thead>
            <tbody>
                @forelse($clientes as $c)
                @php
                    $nombre  = nombre_oracion($c->nombre_completo);
                    $celular = preg_replace('/\D/', '', (string) $c->celular);
                @endphp
                <tr>
                    <td>
                        <button type="button" class="c-ced btn-abrir-contrato"
                            data-contrato-id="{{ $c->id }}" data-nombre="{{ $nombre }}"
                            title="Clic para abrir el contrato">{{ $c->cedula }}</button>
                    </td>
                    <td class="c-nom" title="{{ $nombre }}">{{ \Illuminate\Support\Str::limit($nombre, 24, '…') }}</td>
                    <td>
                        @if($celular)
                        <span class="c-cel">{{ $c->celular }}
                            <a href="https://wa.me/57{{ $celular }}" target="_blank" title="Abrir WhatsApp"><svg fill="#25d366" width="13" height="13"><use href="#ico-wa"/></svg></a>
                        </span>
                        @else<span class="c-vacio">—</span>@endif
                    </td>
                    <td title="{{ $c->razon_social }}">{{ $corto($c->razon_social) }}</td>
                    <td title="{{ $c->empresa }}">{{ $corto($c->empresa) }}</td>
                    <td title="{{ $c->eps_nombre }}">{{ $corto($c->eps_nombre) }}</td>
                    <td title="{{ $c->caja_nombre }}">{{ $corto($c->caja_nombre) }}</td>
                    <td title="{{ $c->pension_nombre }}">{{ $corto($c->pension_nombre) }}</td>
                    <td title="{{ $c->modalidad_nombre }}">{{ $corto($c->modalidad_nombre) }}</td>
                    <td title="{{ $c->plan_nombre }}">{{ $corto($c->plan_nombre) }}</td>
                    <td>@if($c->n_arl)<span class="ca-arl">Nivel {{ $c->n_arl }}</span>@else<span class="c-vacio">—</span>@endif</td>
                    <td class="num">{{ sqldate($c->fecha_ingreso)?->format('d/m/Y') }}</td>
                    <td class="num" style="color:#0f172a;font-weight:600;">$ {{ number_format($c->salario,0,',','.') }}</td>
                </tr>
                @empty
                <tr><td colspan="13" class="ca-vacia">Sin resultados con estos filtros</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $clientes->links() }}</div>
</div>

{{-- ═══ Modal: el contrato se abre encima, sin salir del informe ═══ --}}
<div id="modalContratoOverlay" style="display:none;position:fixed;inset:0;z-index:3000;background:rgba(10,10,20,.7);backdrop-filter:blur(4px);align-items:center;justify-content:center;padding:.75rem;"
     onclick="if(event.target===this)cerrarModalContrato()">
    <div style="background:#fff;border-radius:14px;width:min(1180px,97vw);height:94vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden;">
        <div style="background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%);padding:.65rem 1.2rem;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
            <div style="display:flex;align-items:center;gap:.6rem;">
                <span style="font-size:1.1rem;">📋</span>
                <div style="font-size:.9rem;font-weight:800;color:#fff;" id="iframeContratoTitulo">Contrato</div>
            </div>
            <div style="display:flex;align-items:center;gap:.5rem;">
                <a id="iframeContratoLink" href="#" target="_blank" class="ca-btn ca-btn-glass">↗ Abrir pestaña</a>
                <button type="button" onclick="cerrarModalContrato()" class="ca-btn ca-btn-glass" title="Cerrar">✕</button>
            </div>
        </div>
        <div style="position:relative;flex:1;overflow:hidden;">
            <div id="iframeLoading" style="position:absolute;inset:0;background:#f8fafc;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1rem;z-index:10;">
                <div style="width:44px;height:44px;border-radius:50%;border:4px solid #e2e8f0;border-top-color:#3b82f6;animation:spinIframe .7s linear infinite;"></div>
                <div style="font-size:.82rem;color:#64748b;font-weight:600;">Cargando contrato...</div>
            </div>
            <iframe id="iframeContrato" src="" style="width:100%;height:100%;border:none;display:block;"
                onload="document.getElementById('iframeLoading').style.display='none'"></iframe>
        </div>
    </div>
</div>

<script>
const BASE_CONTRATO = '{{ url("admin/contratos") }}';
function cerrarModalContrato() {
    document.getElementById('modalContratoOverlay').style.display = 'none';
    document.getElementById('iframeContrato').src = '';
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') cerrarModalContrato(); });
document.addEventListener('click', function (e) {
    const btn = e.target.closest('.btn-abrir-contrato');
    if (!btn) return;
    const url = `${BASE_CONTRATO}/${btn.dataset.contratoId}/edit`;
    document.getElementById('iframeContratoTitulo').textContent = btn.dataset.nombre || btn.textContent.trim();
    document.getElementById('iframeContratoLink').href = url;
    document.getElementById('iframeLoading').style.display = 'flex';
    document.getElementById('iframeContrato').src = url + '?iframe=1';
    document.getElementById('modalContratoOverlay').style.display = 'flex';
});
// Si desde el contrato se factura o se retira, se cierra el modal y se
// recarga para que el informe no muestre un contrato que ya no está vigente.
window.addEventListener('message', function (e) {
    if (e.data && e.data.type === 'brynex:iframe_done') { cerrarModalContrato(); location.reload(); }
});
</script>
@endsection
