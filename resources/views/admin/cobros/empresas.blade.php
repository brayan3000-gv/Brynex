@extends('layouts.app')
@section('modulo', 'Cobros · Empresas')

@php
$meses = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$semLabel = fn($s) => match($s) {
    'verde'    => ['🟢', '#15803d', 'Llamado reciente'],
    'amarillo' => ['🟡', '#92400e', '3–7 días sin llamar'],
    'rojo'     => ['🔴', '#b91c1c', 'Más de 7 días'],
    default    => ['⬜', '#64748b', 'Sin llamadas'],
};
$sortUrlE = function($col, $cs, $cd) {
    $d = ($cs===$col && $cd==='asc') ? 'desc' : 'asc';
    $q = request()->except(['sort','dir']); $q['sort']=$col; $q['dir']=$d;
    return url()->current().'?'.http_build_query($q);
};
$sortClassE = function($col, $cs, $cd) {
    if($cs!==$col) return ''; return $cd==='asc'?'sort-asc':'sort-desc';
};
$fmt  = fn($v) => number_format($v ?? 0, 0, '', '');
$waUrl = fn($tel) => $tel ? 'https://wa.me/57' . preg_replace('/\D/', '', $tel) : null;
@endphp

@section('contenido')
<style>
/* Tabs */
.cobros-tabs {
    display:flex; gap:.45rem; margin-bottom:.75rem;
}
.cobros-tab {
    padding:.45rem 1.1rem; border-radius:9px; font-size:.8rem; font-weight:700;
    text-decoration:none; border:1px solid #e2e8f0; background:#f8fafc; color:#475569;
    transition:all .15s;
}
.cobros-tab:hover   { background:#e2e8f0; color:#1e3a5f; }
.cobros-tab.activo  { background:#0f172a; color:#fff; border-color:#0f172a; }

/* Header */
.cob-header {
    background: linear-gradient(135deg,#0f172a 0%,#1e3a5f 60%,#1e40af 100%);
    border-radius:14px; padding:1rem 1.4rem; color:#fff;
    display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.7rem;
    margin-bottom:.75rem;
}
.cob-title { font-size:1.25rem; font-weight:800; }
.cob-sub   { font-size:.75rem; color:#94a3b8; margin-top:.1rem; }

/* Cards */
.cards-row { display:grid; grid-template-columns:repeat(4,1fr); gap:.7rem; margin-bottom:.75rem; }
.card-item { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:.8rem 1rem; }
.card-item .ci-label { font-size:.65rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#64748b; }
.card-item .ci-val   { font-size:1.4rem; font-weight:800; color:#0f172a; font-family:monospace; }
.card-item .ci-sub   { font-size:.67rem; color:#94a3b8; }
.c-emp  { border-top:3px solid #1e40af; }
.c-cont { border-top:3px solid #0f172a; }
.c-pag  { border-top:3px solid #15803d; }
.c-pend { border-top:3px solid #dc2626; }

/* Filtros */
.filtros { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:.7rem 1rem;
    display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; margin-bottom:.75rem; }
.filtros select, .filtros input { padding:.36rem .65rem; border:1px solid #cbd5e1; border-radius:8px;
    font-size:.8rem; outline:none; background:#fff; }
.filtros select:focus, .filtros input:focus { border-color:#3b82f6; }
.btn-filtrar { padding:.36rem .9rem; background:#1e40af; color:#fff; border:none; border-radius:8px;
    font-size:.8rem; font-weight:600; cursor:pointer; }
.btn-limpiar { padding:.36rem .8rem; background:#f1f5f9; color:#475569; border:1px solid #e2e8f0;
    border-radius:8px; font-size:.8rem; font-weight:600; text-decoration:none; }

/* Tabla */
.tbl-wrap { overflow-x:auto; border-radius:12px; border:1px solid #e2e8f0; background:#fff; }
.tbl-emp  { width:100%; border-collapse:collapse; font-size:.77rem; white-space:nowrap; }
.tbl-emp thead th {
    background:#0f172a; color:#fff; padding:.5rem .6rem;
    font-weight:600; font-size:.67rem; text-transform:uppercase; letter-spacing:.04em;
    position:sticky; top:0; z-index:2; text-align:center;
}
.tbl-emp thead th a { color:#cbd5e1; text-decoration:none; display:flex; align-items:center; gap:.2rem; justify-content:center; }
.tbl-emp thead th a:hover { color:#fff; }
.tbl-emp thead th a.sort-asc::after  { content:'\2191'; color:#3b82f6; margin-left:.15rem; }
.tbl-emp thead th a.sort-desc::after { content:'\2193'; color:#3b82f6; margin-left:.15rem; }
.tbl-emp tbody tr { border-bottom:1px solid #f1f5f9; transition:background .12s; }
.tbl-emp tbody tr:hover { background:#f8fafc; }
.tbl-emp td { padding:.45rem .55rem; vertical-align:middle; }

/* Números */
.num-cell { text-align:center; font-family:monospace; font-weight:700; font-size:.8rem; }
.num-0    { color:#94a3b8; }
.num-afil { color:#6d28d9; }
.num-ind  { color:#0369a1; }
.num-plan { color:#1e40af; }
.num-pend-total { color:#dc2626; font-size:.88rem; }
.num-pag  { color:#15803d; }

/* Empresa link */
.emp-nombre { font-weight:700; color:#1e3a5f; font-size:.8rem; }
.emp-nombre:hover { color:#2563eb; }
.emp-contacto { font-size:.7rem; color:#64748b; }

/* th-select override: mismo aspecto que los demás títulos */
.tbl-emp thead th .th-select {
    background:transparent !important;
    border:none !important;
    color:#cbd5e1;
    font-size:.67rem;
    font-weight:600;
    text-transform:uppercase;
    letter-spacing:.04em;
    cursor:pointer;
    outline:none;
    appearance:none;
    -webkit-appearance:none;
    padding:0;
    width:100%;
}
.tbl-emp thead th .th-select:focus { color:#fff; }
.tbl-emp thead th .th-select.activo { color:#93c5fd; }
.tbl-emp thead th .th-select option { background:#0f172a; color:#fff; font-weight:600; text-transform:none; }

/* Encargado select */
.enc-select {
    font-size:.7rem; border:1px solid #e2e8f0; border-radius:6px; padding:.18rem .35rem;
    background:#f8fafc; color:#334155; outline:none; cursor:pointer; max-width:120px;
}
.enc-select:hover { border-color:#3b82f6; }
.enc-select.guardado { border-color:#15803d; background:#f0fdf4; }

/* Semáforo */
.sem-dot { display:inline-flex; align-items:center; gap:.3rem; font-size:.75rem; font-weight:600; }

/* Botón llamar */
.btn-llamar {
    padding:.25rem .6rem; border-radius:7px; font-size:.72rem; font-weight:700;
    cursor:pointer; border:none; transition:all .15s;
    background:linear-gradient(135deg,#1e40af,#2563eb); color:#fff;
}
.btn-llamar:hover { transform:translateY(-1px); box-shadow:0 3px 10px rgba(37,99,235,.3); }

/* Modal */
.modal-bg { display:none; position:fixed; inset:0; background:rgba(0,0,0,.55); z-index:1000; align-items:center; justify-content:center; backdrop-filter:blur(2px); }
.modal-bg.open { display:flex; }
.modal-box { background:#fff; border-radius:16px; padding:1.4rem; max-width:500px; width:95%; max-height:92vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,.22); animation:mIn .18s ease; }
@keyframes mIn { from{transform:translateY(-18px);opacity:0} to{transform:translateY(0);opacity:1} }
.modal-title  { font-size:.95rem; font-weight:800; color:#0f172a; margin-bottom:1rem; padding-bottom:.5rem; border-bottom:1px solid #f1f5f9; display:flex; align-items:center; justify-content:space-between; }
.modal-close  { background:none; border:none; font-size:1.2rem; cursor:pointer; color:#94a3b8; }
.modal-close:hover { color:#ef4444; }
.info-box { background:#f0f9ff; border-radius:9px; padding:.55rem .85rem; margin-bottom:.85rem; display:flex; flex-wrap:wrap; gap:.6rem; font-size:.77rem; }
.form-grp { display:flex; flex-direction:column; gap:.22rem; margin-bottom:.75rem; }
.form-grp label { font-size:.7rem; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:.04em; }
.form-grp select, .form-grp textarea { padding:.46rem .65rem; border:1px solid #cbd5e1; border-radius:8px; font-size:.85rem; outline:none; font-family:inherit; }
.form-grp select:focus, .form-grp textarea:focus { border-color:#3b82f6; }
.form-grp textarea { resize:vertical; min-height:75px; }
.btn-save { background:linear-gradient(135deg,#1e40af,#2563eb); color:#fff; border:none; border-radius:10px; padding:.55rem 1.4rem; font-size:.88rem; font-weight:700; cursor:pointer; width:100%; }
.timeline { padding-left:1.4rem; position:relative; }
.timeline::before { content:''; position:absolute; left:.45rem; top:0; bottom:0; width:2px; background:#e2e8f0; }
.tl-item { position:relative; margin-bottom:.8rem; }
.tl-item::before { content:''; position:absolute; left:-1.05rem; top:.28rem; width:9px; height:9px; border-radius:50%; border:2px solid #3b82f6; background:#fff; }
.tl-date { font-size:.66rem; color:#94a3b8; } .tl-user { font-size:.7rem; font-weight:700; color:#1e40af; }
.tl-res  { font-size:.68rem; font-weight:700; padding:.1rem .4rem; border-radius:5px; background:#f0fdf4; color:#15803d; display:inline-block; margin-top:.1rem; }
.tl-obs  { font-size:.77rem; color:#334155; margin-top:.15rem; }
.toast { position:fixed; bottom:1.2rem; right:1.2rem; z-index:9999; padding:.65rem 1.2rem; border-radius:10px; font-weight:600; font-size:.85rem; box-shadow:0 4px 16px rgba(0,0,0,.15); display:none; }
.toast.show { display:block; }
.toast.success { background:#dcfce7; color:#15803d; border:1px solid #86efac; }
.toast.error   { background:#fee2e2; color:#b91c1c; border:1px solid #fca5a5; }
</style>



{{-- HEADER --}}
<form method="GET" action="{{ route('admin.cobros.empresas') }}" id="fHead">
<div class="cob-header">
    <div>
        <div class="cob-title">🏢 Cobros · Empresas</div>
        <div class="cob-sub">Resumen por empresa cliente · {{ $meses[$mes] }} {{ $anio }}</div>
    </div>
    <div style="display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;">
        {{-- Tabs navegación --}}
        <a href="{{ route('admin.cobros.index', ['mes' => $mes, 'anio' => $anio]) }}"
           style="padding:.3rem .8rem;border-radius:7px;font-size:.78rem;font-weight:700;text-decoration:none;background:rgba(255,255,255,.15);color:#cbd5e1;border:1px solid rgba(255,255,255,.15);">
            👤 Individuales
        </a>
        <a href="{{ route('admin.cobros.empresas', ['mes' => $mes, 'anio' => $anio]) }}"
           style="padding:.3rem .8rem;border-radius:7px;font-size:.78rem;font-weight:700;text-decoration:none;background:#ffffff;color:#0f172a;border:1px solid rgba(255,255,255,.3);">
            🏢 Empresas
        </a>
        <button type="button" onclick="abrirModalWhatsAppMasivoEmpresas()"
           style="padding:.3rem .8rem;border-radius:7px;font-size:.78rem;font-weight:700;cursor:pointer;background:#22c55e;color:#fff;border:none;display:inline-flex;align-items:center;gap:.3rem;transition:all .15s;"
           title="Enviar cobros masivos a empresas por WhatsApp">
            💬 WhatsApp
        </button>
        <span style="width:1px;height:22px;background:rgba(255,255,255,.2);display:inline-block;"></span>
        <select name="mes" onchange="this.form.submit()" style="font-size:.8rem;padding:.3rem .5rem;border:1px solid rgba(255,255,255,.25);background:rgba(255,255,255,.1);color:#e2e8f0;border-radius:6px;">
            @foreach($meses as $i => $m)
            @if($i) <option value="{{ $i }}" {{ $mes==$i?'selected':'' }} style="background:#1e3a5f;">{{ $m }}</option> @endif
            @endforeach
        </select>
        <select name="anio" onchange="this.form.submit()" style="font-size:.8rem;padding:.3rem .5rem;border:1px solid rgba(255,255,255,.25);background:rgba(255,255,255,.1);color:#e2e8f0;border-radius:6px;">
            @for($y=date('Y');$y>=2023;$y--)
            <option value="{{ $y }}" {{ $anio==$y?'selected':'' }} style="background:#1e3a5f;">{{ $y }}</option>
            @endfor
        </select>
        <span style="width:1px;height:22px;background:rgba(255,255,255,.2);display:inline-block;"></span>
        {{-- Buscar empresa en el header --}}
        <div style="display:flex;align-items:center;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:7px;overflow:hidden;">
            <input type="text" name="buscar" value="{{ $buscar }}" placeholder="🔍 Empresa..."
                   style="background:transparent;border:none;outline:none;color:#fff;font-size:.78rem;padding:.3rem .55rem;width:145px;">
            <button type="submit" style="background:rgba(255,255,255,.15);border:none;color:#fff;padding:.3rem .55rem;cursor:pointer;font-size:.78rem;">&#9166;</button>
        </div>
        @if($buscar)
        <a href="{{ route('admin.cobros.empresas', ['mes'=>$mes,'anio'=>$anio]) }}"
           style="color:#fca5a5;font-size:.8rem;font-weight:700;text-decoration:none;" title="Limpiar búsqueda">×</a>
        @endif
        <span style="background:rgba(255,255,255,.15);color:#fff;font-size:.85rem;font-weight:800;padding:.3rem .7rem;border-radius:20px;">
            {{ $empresas->count() }} <span style="font-size:.7rem;opacity:.75;">empresas</span>
        </span>
    </div>
</div>
</form>

{{-- CARDS --}}
<div class="cards-row">
    <div class="card-item c-emp">
        <div class="ci-label">🏢 Empresas</div>
        <div class="ci-val">{{ $totalEmpresas }}</div>
        <div class="ci-sub">Con contratos activos</div>
    </div>
    <div class="card-item c-cont">
        <div class="ci-label">👥 Total contratos</div>
        <div class="ci-val">{{ $totalContratos }}</div>
        <div class="ci-sub">Acumulado del mes</div>
    </div>
    <div class="card-item c-pag">
        <div class="ci-label">✅ Pagados</div>
        <div class="ci-val" style="color:#15803d;">{{ $totalPagados }}</div>
        <div class="ci-sub">Con factura pagada</div>
    </div>
    <div class="card-item c-pend">
        <div class="ci-label">⏳ Pendientes</div>
        <div class="ci-val" style="color:#dc2626;">{{ $totalPendientes }}</div>
        <div class="ci-sub">Afil + Ind + Plan sin pagar</div>
    </div>
</div>



{{-- TABLA --}}
@if($empresas->isEmpty())
<div style="text-align:center;padding:3rem;color:#94a3b8;background:#fff;border-radius:12px;border:1px solid #e2e8f0;">
    <div style="font-size:3rem;">🏢</div>
    <div style="font-size:1rem;font-weight:600;margin-top:.5rem;">Sin empresas para este período</div>
    @if(!$esAdmin)<div style="font-size:.8rem;">No tienes empresas asignadas aún</div>@endif
</div>
@else
<div class="tbl-wrap">
<table class="tbl-emp">
<thead>
<tr>
    {{-- Encargado: filtro en th --}}
    @if($esAdmin)
    <th style="min-width:120px;">
        <form method="GET" action="{{ route('admin.cobros.empresas') }}" style="margin:0">
            @foreach(request()->except(['encargado_id','page']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
            <select name="encargado_id" onchange="this.form.submit()" class="th-select {{ $encargadoFiltro ? 'activo' : '' }}">
                <option value="">↓ Encargado</option>
                @foreach($usuariosDisponibles as $u)
                <option value="{{ $u->id }}" {{ $encargadoFiltro==$u->id?'selected':'' }}>{{ $u->nombre ?? $u->name }}</option>
                @endforeach
            </select>
        </form>
    </th>
    @endif
    <th style="text-align:left;min-width:160px;"><a href="{{ $sortUrlE('empresa',$sort,$dir) }}" class="{{ $sortClassE('empresa',$sort,$dir) }}">Empresa</a></th>
    <th style="text-align:left;min-width:100px;"><a href="{{ $sortUrlE('contacto',$sort,$dir) }}" class="{{ $sortClassE('contacto',$sort,$dir) }}">Contacto / Tel</a></th>
    <th title="Total contratos activos del mes">Cant</th>
    <th title="Contratos pagados este mes" style="color:#34d399;">Pag.</th>
    <th title="Afiliaciones pendientes de pago">Afil</th>
    <th title="Independientes pendientes">Ind.</th>
    {{-- Plant.: filtro solo con pendientes --}}
    <th>
        <form method="GET" action="{{ route('admin.cobros.empresas') }}" style="margin:0">
            @foreach(request()->except(['solo_plant','page']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
            <select name="solo_plant" onchange="this.form.submit()" class="th-select {{ request('solo_plant') ? 'activo' : '' }}">
                <option value="">↓ Plant.</option>
                <option value="1" {{ request('solo_plant')=='1'?'selected':'' }}>Solo pend.</option>
            </select>
        </form>
    </th>
    {{-- Total Pend.: filtro solo con pendientes --}}
    <th>
        <form method="GET" action="{{ route('admin.cobros.empresas') }}" style="margin:0">
            @foreach(request()->except(['solo_pend','page']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
            <select name="solo_pend" onchange="this.form.submit()" class="th-select {{ request('solo_pend') ? 'activo' : '' }}">
                <option value="">↓ Total Pend.</option>
                <option value="1" {{ request('solo_pend')=='1'?'selected':'' }}>Solo pend.</option>
            </select>
        </form>
    </th>
    <th title="$ Administración de pendientes" style="color:#fbbf24;">Admon $</th>
    <th title="Mora estimada por pago tardío" style="color:#fde68a;">⚠️ Mora</th>
    {{-- Semáforo: filtro en th --}}
    <th style="min-width:100px;text-align:center;">
        <form method="GET" action="{{ route('admin.cobros.empresas') }}" style="margin:0">
            @foreach(request()->except(['semaforo','page']) as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
            <select name="semaforo" onchange="this.form.submit()" class="th-select {{ request('semaforo') ? 'activo' : '' }}">
                <option value="">↓ Semáforo</option>
                <option value="gris"  {{ request('semaforo')=='gris'?'selected':''   }}>⬜ Sin llamadas</option>
                <option value="verde" {{ request('semaforo')=='verde'?'selected':''  }}>🟢 Al día</option>
                <option value="amarillo" {{ request('semaforo')=='amarillo'?'selected':'' }}>🟡 3–7 días</option>
                <option value="rojo"  {{ request('semaforo')=='rojo'?'selected':''   }}>🔴 +7 días</option>
            </select>
        </form>
    </th>
    <th style="min-width:130px;">&#218;ltima gestión</th>
    <th style="text-align:center;">📞</th>
    <th style="text-align:center;">🔗</th>
</tr>
</thead>
<tbody>
@foreach($empresas as $emp)
@php
[$semIco, $semColor, $semTip] = $semLabel($emp->semaforo);
$nombreEnc = $emp->encargado_id ? ($usuariosDisponibles->firstWhere('id', $emp->encargado_id)?->nombre ?? $usuariosDisponibles->firstWhere('id', $emp->encargado_id)?->name ?? '—') : '';
@endphp
<tr>
    {{-- Encargado (admin ve selector) --}}
    @if($esAdmin)
    <td>
        <select class="enc-select" data-empresa-id="{{ $emp->id }}" onchange="asignarEncargado(this)">
            <option value="">— Sin asignar —</option>
            @foreach($usuariosDisponibles as $u)
            <option value="{{ $u->id }}" {{ $emp->encargado_id == $u->id ? 'selected' : '' }}>
                {{ $u->nombre ?? $u->name }}
            </option>
            @endforeach
        </select>
    </td>
    @endif

    {{-- Empresa --}}
    <td>
        <a href="{{ route('admin.facturacion.empresa', ['id' => $emp->id, 'mes' => $mes, 'anio' => $anio]) }}"
           class="emp-nombre" target="_blank">
            {{ $emp->empresa ?? "Empresa #{$emp->id}" }}
        </a>
        @if($emp->nit)
        <div class="emp-contacto">NIT: {{ number_format($emp->nit, 0, '', '.') }}</div>
        @endif
    </td>

    {{-- Contacto --}}
    <td>
        <div class="emp-contacto" style="font-weight:600;color:#334155;">{{ $emp->contacto ?? '—' }}</div>
        @php $tel = $emp->telefono ?? $emp->celular ?? ''; $wa = $waUrl($tel); @endphp
        <div class="emp-contacto" style="display:flex;align-items:center;gap:.3rem;">
            {{ $tel }}
            @if($wa)
            <a href="{{ $wa }}" target="_blank" title="Abrir WhatsApp" style="text-decoration:none;line-height:1;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#25d366" width="14" height="14"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
            </a>
            @endif
        </div>
    </td>

    {{-- Cant --}}
    <td class="num-cell">{{ $fmt($emp->cant) }}</td>

    {{-- Pagados --}}
    <td class="num-cell num-pag">{{ $fmt($emp->pagados) }}</td>

    {{-- Afiliaciones pendientes --}}
    <td class="num-cell {{ $emp->afil_pend > 0 ? 'num-afil' : 'num-0' }}">
        {{ $fmt($emp->afil_pend) }}
        {{-- Afiliaciones de meses anteriores que nunca se cobraron (también de retirados) --}}
        @if(($emp->afil_atrasadas ?? 0) > 0)
        <div style="margin-top:.15rem;font-size:.6rem;font-weight:700;color:#b45309;white-space:nowrap;"
             title="Afiliación sin cobrar de {{ implode(', ', $emp->afil_atrasadas_periodos) }}. Se factura en la planilla de la empresa de ese mes.">
            ⚠ +{{ $emp->afil_atrasadas }} atrasada{{ $emp->afil_atrasadas > 1 ? 's' : '' }} · ${{ number_format($emp->afil_atrasadas_valor, 0, '', '.') }}
        </div>
        @endif
    </td>

    {{-- Independientes pendientes --}}
    <td class="num-cell {{ $emp->indep_pend > 0 ? 'num-ind' : 'num-0' }}">{{ $fmt($emp->indep_pend) }}</td>

    {{-- Planillas pendientes --}}
    <td class="num-cell {{ $emp->plan_pend > 0 ? 'num-plan' : 'num-0' }}">{{ $fmt($emp->plan_pend) }}</td>

    {{-- Total pendientes --}}
    <td class="num-cell {{ $emp->total_pend > 0 ? 'num-pend-total' : 'num-0' }}">
        <strong>{{ $fmt($emp->total_pend) }}</strong>
    </td>

    {{-- Admon pendientes --}}
    <td class="num-cell" style="{{ $emp->admon_pend > 0 ? 'color:#d97706;font-weight:800;' : 'color:#94a3b8;' }}">
        ${{ number_format($emp->admon_pend, 0, '', '.') }}
    </td>

    {{-- Mora estimada empresa --}}
    <td class="num-cell">
        @if(($emp->mora_estimada ?? 0) > 0)
            <span style="display:inline-block;padding:.1rem .4rem;border-radius:20px;font-size:.62rem;font-weight:700;background:#fef3c7;color:#92400e;" title="Mora estimada total de contratos pendientes">
                ${{ number_format($emp->mora_estimada, 0, '', '.') }}
            </span>
        @else
            <span style="color:#64748b;font-size:.7rem;">—</span>
        @endif
    </td>

    {{-- Semáforo --}}
    <td style="text-align:center;">
        <span class="sem-dot" style="color:{{ $semColor }};" title="{{ $semTip }}">
            {{ $semIco }}
            @if($emp->dias_sin_llamar !== null)
                <span style="font-size:.62rem;color:#94a3b8;">{{ $emp->dias_sin_llamar }}d</span>
            @endif
        </span>
    </td>

    {{-- Última gestión --}}
    <td>
        @if($emp->ultima_llamada)
            <div style="font-size:.7rem;font-weight:600;color:#334155;">
                {{ \App\Models\BitacoraCobro::RESULTADOS[$emp->ultima_llamada->resultado] ?? $emp->ultima_llamada->resultado }}
            </div>
            @if($emp->ultima_llamada->observacion)
            <div style="font-size:.66rem;color:#64748b;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                 title="{{ $emp->ultima_llamada->observacion }}">
                {{ $emp->ultima_llamada->observacion }}
            </div>
            @endif
        @else
            <span style="color:#cbd5e1;font-size:.7rem;">Sin gestiones</span>
        @endif
    </td>

    {{-- Botón llamar --}}
    <td style="text-align:center;">
        <button class="btn-llamar btn-llamar-emp"
            data-empresa-id="{{ $emp->id }}"
            data-nombre="{{ $emp->empresa ?? 'Empresa #'.$emp->id }}"
            data-contacto="{{ $emp->contacto ?? '—' }}"
            data-telefono="{{ $emp->telefono ?? $emp->celular ?? '—' }}"
            data-cant="{{ $emp->cant }}"
            data-pend="{{ $emp->total_pend }}"
            title="Registrar llamada">
            📞
        </button>
    </td>

    {{-- Link a facturación --}}
    <td style="text-align:center;">
        <a href="{{ route('admin.facturacion.empresa', ['id' => $emp->id, 'mes' => $mes, 'anio' => $anio]) }}"
           style="font-size:.8rem;text-decoration:none;padding:.22rem .55rem;background:#dbeafe;color:#1e40af;border-radius:7px;font-weight:700;"
           target="_blank" title="Ver contratos en facturación">
            📋
        </a>
    </td>
</tr>
@endforeach
</tbody>
<tfoot>
<tr style="background:#0f172a;color:#fff;font-weight:700;font-size:.72rem;">
    <td colspan="{{ $esAdmin ? 3 : 2 }}" style="padding:.5rem .6rem;">TOTALES ({{ $empresas->count() }})</td>
    <td class="num-cell" style="color:#93c5fd;">{{ $fmt($totalContratos) }}</td>
    <td class="num-cell" style="color:#34d399;">{{ $fmt($totalPagados) }}</td>
    <td class="num-cell" style="color:#c4b5fd;">{{ $fmt($empresas->sum('afil_pend')) }}</td>
    <td class="num-cell" style="color:#7dd3fc;">{{ $fmt($empresas->sum('indep_pend')) }}</td>
    <td class="num-cell" style="color:#93c5fd;">{{ $fmt($empresas->sum('plan_pend')) }}</td>
    <td class="num-cell" style="color:#fca5a5;">{{ $fmt($totalPendientes) }}</td>
    <td class="num-cell" style="color:#fbbf24;font-weight:800;">${{ number_format($empresas->sum('admon_pend'), 0, '', '.') }}</td>
    <td class="num-cell" style="color:#fde68a;font-weight:800;">
        @if($empresas->sum('mora_estimada') > 0)
            ${{ number_format($empresas->sum('mora_estimada'), 0, '', '.') }}
        @else
            —
        @endif
    </td>
    <td colspan="4"></td>
</tr>
</tfoot>
</table>
</div>
@endif

{{-- MODAL LLAMADA EMPRESA --}}
<div class="modal-bg" id="modalLlamadaEmp">
<div class="modal-box">
    <div class="modal-title">
        <span>📞 Registrar Gestión · Empresa</span>
        <button class="modal-close" onclick="document.getElementById('modalLlamadaEmp').classList.remove('open')">✕</button>
    </div>
    <div class="info-box">
        <div><span style="color:#64748b;">🏢 Empresa:</span> <strong id="me-nombre"></strong></div>
        <div><span style="color:#64748b;">👤 Contacto:</span> <strong id="me-contacto"></strong></div>
        <div style="display:flex;align-items:center;gap:.4rem;">
            <span style="color:#64748b;">📞 Tel:</span>
            <strong id="me-telefono"></strong>
            <a id="me-wa-link" href="#" target="_blank" title="WhatsApp"
               style="text-decoration:none;display:none;line-height:1;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#25d366" width="16" height="16"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
            </a>
        </div>
        <div><span style="color:#64748b;">Contratos:</span> <strong id="me-cant"></strong> total / <strong id="me-pend" style="color:#dc2626;"></strong> pendientes</div>
    </div>
    <input type="hidden" id="me-empresa-id">
    <div class="form-grp">
        <label>Resultado de la gestión *</label>
        <select id="me-resultado">
            <option value="no_contesta">📵 No contesta</option>
            <option value="promesa_pago">🤝 Promesa de pago</option>
            <option value="pagado">✅ Ya pagaron</option>
            <option value="numero_errado">❌ Número errado</option>
            <option value="otro">📝 Otro</option>
        </select>
    </div>
    <div class="form-grp">
        <label>Observación — ¿Qué dijo el contacto?</label>
        <textarea id="me-observacion" placeholder="Ej: Doña Luz dice que consigna el viernes..."></textarea>
    </div>
    <button class="btn-save" id="btnGuardarEmp" onclick="guardarLlamadaEmp()">💾 Guardar Gestión</button>

    <div style="margin-top:1rem;padding-top:.9rem;border-top:1px solid #f1f5f9;">
        <div style="font-size:.7rem;font-weight:700;color:#475569;text-transform:uppercase;margin-bottom:.5rem;">Historial de gestiones</div>
        <div id="me-historial" style="font-size:.75rem;color:#94a3b8;">Cargando...</div>
    </div>
</div>
</div>

<div class="toast" id="toastMsg"></div>

{{-- Modal WhatsApp Masivo Empresas --}}
<div id="modalWaMasivoEmp" class="modal-bg">
    <div class="modal-box wide" style="max-width:860px; border-radius: 20px;">
        <div class="modal-title" style="border-bottom: 1px solid #f1f5f9; padding-bottom: .8rem; margin-bottom: 1.2rem;">
            <span style="font-weight: 800; font-size: 1.1rem; color: #0f172a; display: flex; align-items: center; gap: .5rem;">💬 Envío Masivo por WhatsApp (Empresas)</span>
            <button type="button" class="modal-close" onclick="cerrarModal('modalWaMasivoEmp')">&times;</button>
        </div>

        <style>
            .wa-tab-container {
                display: inline-flex;
                gap: .4rem;
                margin-bottom: 1.25rem;
                background: #f1f5f9;
                padding: .3rem;
                border-radius: 50px;
                border: 1px solid #e2e8f0;
            }
            .wa-tab-btn {
                background: none;
                border: none;
                border-radius: 50px;
                padding: .45rem 1.2rem;
                font-size: .8rem;
                font-weight: 700;
                cursor: pointer;
                color: #64748b;
                transition: all .2s ease;
                display: inline-flex;
                align-items: center;
                gap: .35rem;
                outline: none;
            }
            .wa-tab-btn.active {
                background: #fff;
                color: #1e40af!important;
                box-shadow: 0 2px 6px rgba(0, 0, 0, .06);
            }
            .wa-pill-btn {
                padding: .55rem 1.25rem;
                border-radius: 50px;
                font-size: .82rem;
                font-weight: 700;
                cursor: pointer;
                border: none;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: .4rem;
                transition: all .2s ease;
                text-decoration: none;
                outline: none;
            }
            .wa-pill-btn-success {
                background: linear-gradient(135deg, #22c55e 0%, #15803d 100%);
                color: #fff;
                box-shadow: 0 4px 12px rgba(34, 197, 94, .25);
            }
            .wa-pill-btn-success:hover {
                transform: translateY(-1px);
                box-shadow: 0 6px 18px rgba(34, 197, 94, .38);
                background: linear-gradient(135deg, #26d063 0%, #168a41 100%);
            }
            .wa-pill-btn-success:active {
                transform: translateY(0);
            }
            .wa-pill-btn-outline {
                background: #fff;
                color: #475569;
                border: 1px solid #cbd5e1;
                box-shadow: 0 2px 4px rgba(0,0,0,.03);
            }
            .wa-pill-btn-outline:hover {
                background: #f8fafc;
                color: #0f172a;
                border-color: #94a3b8;
                transform: translateY(-1px);
            }
            .wa-pill-btn-outline:active {
                transform: translateY(0);
            }
        </style>

        {{-- Pestañas en forma de píldoras --}}
        <div class="wa-tab-container">
            <button id="waTabPreviewEmp" onclick="cambiarTabWaEmp('preview')" type="button" class="wa-tab-btn active">
                📱 Previsualización
            </button>
            <button id="waTabHistorialEmp" onclick="cambiarTabWaEmp('historial')" type="button" class="wa-tab-btn">
                📊 Historial del Mes
            </button>
        </div>

        <div id="waPrevisualizacionLoadingEmp" style="text-align:center;padding:2rem 0;">
            <span style="color:#64748b;">Cargando plantillas e información...</span>
        </div>

        <div id="waPrevisualizacionContentEmp" style="display:none;">
            <div id="waBannerEnvioHoyEmp" style="display:none;background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;border-radius:8px;padding:.65rem .9rem;font-size:.8rem;margin-bottom:1rem;"></div>

            {{-- Panel Previsualización --}}
            <div id="waPanelPreviewEmp">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;">
                    <div>
                        <div style="font-size:.68rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.55rem;">Vista previa (mensajes reales)</div>
                        <div style="background:#e5ddd5;border-radius:12px;padding:1rem;border:1px solid #cbd5e1;min-height:300px;display:flex;flex-direction:column;gap:.5rem;background-image:url('https://user-images.githubusercontent.com/15075759/28719144-86dc0f70-73b1-11e7-911d-60d70fcded21.png');background-repeat:repeat;margin-bottom:.5rem;">
                            <div style="background:#fff;border-radius:8px 8px 8px 0;padding:.5rem .75rem;box-shadow:0 1px 1px rgba(0,0,0,.1);max-width:90%;position:relative;align-self:flex-start;">
                                <div id="waPreviewHeaderImageContainerEmp" style="display:none;margin-bottom:.5rem;">
                                    <img id="waPreviewHeaderImageEmp" src="" alt="Encabezado" style="width:100%;border-radius:6px;max-height:120px;object-fit:cover;">
                                </div>
                                <div id="waPreviewBodyEmp" style="font-size:.82rem;color:#303030;white-space:pre-wrap;word-break:break-word;line-height:1.4;"></div>
                                <div id="waPreviewFooterEmp" style="font-size:.7rem;color:#868686;margin-top:.25rem;display:none;"></div>
                                <div id="waPreviewButtonsEmp" style="display:none;flex-direction:column;gap:.25rem;margin-top:.5rem;border-top:1px solid #f0f0f0;padding-top:.4rem;"></div>
                            </div>
                        </div>
                        
                        {{-- Control de paginación/navegación de previsualización --}}
                        <div id="waPreviewNavigationEmp" style="display:none;align-items:center;justify-content:center;gap:.75rem;background:#f8fafc;padding:.4rem .8rem;border-radius:12px;border:1px solid #e2e8f0;box-shadow:0 1px 3px rgba(0,0,0,.03);">
                            <button type="button" onclick="navegarVistaPreviaEmp(-1)" id="btnWaPrevEmp" style="background:#fff;border:1px solid #cbd5e1;border-radius:50%;width:32px;height:32px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-weight:bold;color:#475569;box-shadow:0 1px 2px rgba(0,0,0,.05);transition:all .15s ease;font-size:1.1rem;outline:none;">&larr;</button>
                            <span id="waPreviewIndexEmp" style="font-size:.72rem;font-weight:700;color:#334155;min-width:140px;text-align:center;line-height:1.3;user-select:none;">Empresa 1 de 1</span>
                            <button type="button" onclick="navegarVistaPreviaEmp(1)" id="btnWaNextEmp" style="background:#fff;border:1px solid #cbd5e1;border-radius:50%;width:32px;height:32px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-weight:bold;color:#475569;box-shadow:0 1px 2px rgba(0,0,0,.05);transition:all .15s ease;font-size:1.1rem;outline:none;">&rarr;</button>
                        </div>
                    </div>
                    <div style="display:flex;flex-direction:column;justify-content:space-between;">
                        <div>
                            <div style="font-size:.68rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.55rem;">Configurar Mensaje</div>
                            
                            {{-- Selección de Plantilla --}}
                            <div class="form-grp" style="margin-bottom: .8rem;">
                                <label style="font-size:.68rem;font-weight:700;color:#475569;">Plantilla a Utilizar</label>
                                <select id="waPlantillaSelectEmp" onchange="recargarPrevisualizacionEmpresa()" style="font-size:.8rem;padding:.4rem;width:100%;border-radius:8px;border:1px solid #cbd5e1;"></select>
                            </div>

                            {{-- Toggle Incluir Valor --}}
                            <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:1rem;background:#f8fafc;padding:.6rem;border-radius:8px;border:1px solid #e2e8f0;">
                                <input type="checkbox" id="waIncluirValorEmp" checked onchange="recargarPrevisualizacionEmpresa()" style="width:16px;height:16px;cursor:pointer;">
                                <label for="waIncluirValorEmp" style="font-size:.78rem;font-weight:600;color:#334155;cursor:pointer;user-select:none;">Incluir valor total de cobro pendiente</label>
                            </div>

                            <div style="background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;padding:.8rem;border-radius:10px;margin-bottom:1rem;">
                                <div style="font-size:1.1rem;font-weight:800;border-bottom:1px solid rgba(22,101,52,.12);padding-bottom:.4rem;margin-bottom:.4rem;">
                                    <span id="waDestinatariosCountEmp">0</span> empresas con saldo pendiente
                                </div>
                                <div id="waResumenEnviosEmp" style="font-size:.76rem;line-height:1.6;color:#15803d;display:none;"></div>
                                <small style="color:#1e8f49;margin-top:.4rem;display:block;font-size:.7rem;line-height:1.3;">Se enviará un mensaje por cada empresa con saldo pendiente.</small>
                            </div>
                        </div>
                        <div style="border-top:1px solid #f1f5f9;padding-top:1rem;display:flex;flex-direction:column;gap:.4rem;">
                            <button type="button" class="wa-pill-btn wa-pill-btn-success" onclick="confirmarEnvioMasivoWaEmp()" id="btnWaConfirmarMasivoEmp"
                                style="width:100%;padding:.65rem;font-size:.88rem;">
                                💬 Confirmar Envío Masivo a Empresas
                            </button>
                            <button type="button" class="wa-pill-btn wa-pill-btn-outline" onclick="cerrarModal('modalWaMasivoEmp')"
                                style="width:100%;padding:.5rem;">
                                Cancelar
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Panel Historial --}}
            <div id="waPanelHistorialEmp" style="display:none;">
                <div id="waHistorialContenidoEmp" style="min-height:200px;">
                    <div style="text-align:center;padding:2rem;color:#94a3b8;">Cargando historial...</div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Sub-modal Informe Detallado --}}
<div id="modalWaInformeEmp" class="modal-bg">
    <div class="modal-box wide" style="max-width:900px;">
        <div class="modal-title">
            <span>📋 Informe Detallado del Lote</span>
            <button type="button" class="modal-close" onclick="cerrarModal('modalWaInformeEmp')">&times;</button>
        </div>
        <div id="waInformeContenidoEmp" style="max-height:70vh;overflow-y:auto;">
            <div style="text-align:center;padding:2rem;color:#64748b;">Cargando...</div>
        </div>
    </div>
</div>

@push('scripts')
<script>
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content;
const URL_EMP_LLAMADA  = '{{ route("admin.cobros.empresa.llamada.store", ["id" => "__ID__"]) }}';
const URL_EMP_LLAMADAS = '{{ route("admin.cobros.empresa.llamadas",     ["id" => "__ID__"]) }}';
const URL_EMP_ENCARGADO= '{{ route("admin.cobros.empresa.encargado",    ["id" => "__ID__"]) }}';

function mostrarToast(msg, tipo='success') {
    const t = document.getElementById('toastMsg');
    t.textContent = msg; t.className = `toast show ${tipo}`;
    setTimeout(() => t.classList.remove('show'), 3500);
}
document.getElementById('modalLlamadaEmp').addEventListener('click', e => {
    if(e.target === document.getElementById('modalLlamadaEmp'))
        document.getElementById('modalLlamadaEmp').classList.remove('open');
});
document.addEventListener('keydown', e => {
    if(e.key === 'Escape') document.getElementById('modalLlamadaEmp').classList.remove('open');
});

// ── Abrir modal empresa ──
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-llamar-emp');
    if (!btn) return;
    document.getElementById('me-empresa-id').value  = btn.dataset.empresaId;
    document.getElementById('me-nombre').textContent    = btn.dataset.nombre;
    document.getElementById('me-contacto').textContent  = btn.dataset.contacto;
    document.getElementById('me-telefono').textContent  = btn.dataset.telefono;
    document.getElementById('me-cant').textContent      = btn.dataset.cant;
    document.getElementById('me-pend').textContent      = btn.dataset.pend;
    document.getElementById('me-resultado').value       = 'no_contesta';
    document.getElementById('me-observacion').value     = '';
    // WhatsApp link
    const waEl = document.getElementById('me-wa-link');
    const rawTel = (btn.dataset.telefono || '').replace(/\D/g, '');
    if (rawTel && rawTel !== '') {
        waEl.href = 'https://wa.me/57' + rawTel;
        waEl.style.display = 'inline';
    } else { waEl.style.display = 'none'; }
    cargarHistorialEmp(btn.dataset.empresaId);
    document.getElementById('modalLlamadaEmp').classList.add('open');
});

// ── Guardar gestión empresa ──
async function guardarLlamadaEmp() {
    const id         = document.getElementById('me-empresa-id').value;
    const resultado  = document.getElementById('me-resultado').value;
    const observacion= document.getElementById('me-observacion').value;
    const btn        = document.getElementById('btnGuardarEmp');
    btn.disabled = true; btn.textContent = 'Guardando...';
    try {
        const r = await fetch(URL_EMP_LLAMADA.replace('__ID__', id), {
            method: 'POST',
            headers: {'Content-Type':'application/json','X-CSRF-TOKEN':CSRF,'Accept':'application/json'},
            body: JSON.stringify({ resultado, observacion })
        });
        const data = await r.json();
        if (!data.ok) throw new Error('Error');
        document.getElementById('modalLlamadaEmp').classList.remove('open');
        mostrarToast('✅ Gestión registrada correctamente');
        setTimeout(() => location.reload(), 600);
    } catch(err) {
        mostrarToast('❌ Error al guardar', 'error');
    } finally {
        btn.disabled = false; btn.textContent = '💾 Guardar Gestión';
    }
}

// ── Cargar historial empresa ──
async function cargarHistorialEmp(id) {
    const el = document.getElementById('me-historial');
    el.innerHTML = '<span style="color:#94a3b8;">Cargando...</span>';
    try {
        const r = await fetch(URL_EMP_LLAMADAS.replace('__ID__', id), {
            headers: {'Accept':'application/json','X-CSRF-TOKEN':CSRF}
        });
        const data = await r.json();
        if (!data.llamadas?.length) { el.innerHTML = '<span style="color:#94a3b8;">Sin gestiones previas</span>'; return; }
        el.innerHTML = '<div class="timeline">' +
            data.llamadas.map(l => `
                <div class="tl-item">
                    <div class="tl-date">${l.fecha} &nbsp; <span class="tl-user">${l.usuario}</span></div>
                    <div class="tl-res">${l.etiqueta}</div>
                    ${l.observacion ? `<div class="tl-obs">${l.observacion}</div>` : ''}
                </div>`).join('') + '</div>';
    } catch { el.innerHTML = '<span style="color:#94a3b8;">Error al cargar</span>'; }
}

// ── Asignar encargado ──
async function asignarEncargado(sel) {
    const id = sel.dataset.empresaId;
    const encargadoId = sel.value;
    try {
        const r = await fetch(URL_EMP_ENCARGADO.replace('__ID__', id), {
            method: 'PATCH',
            headers: {'Content-Type':'application/json','X-CSRF-TOKEN':CSRF,'Accept':'application/json'},
            body: JSON.stringify({ encargado_id: encargadoId || null })
        });
        const data = await r.json();
        if (data.ok) {
            sel.classList.add('guardado');
            mostrarToast('✅ Encargado asignado');
            setTimeout(() => sel.classList.remove('guardado'), 2000);
        }
    } catch { mostrarToast('❌ Error al asignar', 'error'); }
}

function cerrarModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('open');
}

// ── ENVIOS MASIVOS POR WHATSAPP (EMPRESAS) ──
let cantClientesMasivoEmp = 0;
let cantEnviosValidosMasivoEmp = 0;
let waTabActivaEmp = 'preview';
let waPreviewsEmp = [];
let waPreviewIndexActEmp = 0;

async function abrirModalWhatsAppMasivoEmpresas() {
    const modal   = document.getElementById('modalWaMasivoEmp');
    const loading = document.getElementById('waPrevisualizacionLoadingEmp');
    const content = document.getElementById('waPrevisualizacionContentEmp');

    loading.style.display = 'block';
    content.style.display = 'none';
    modal.classList.add('open');

    cambiarTabWaEmp('preview');

    try {
        await cargarLotePrevisualizacionEmpresa(null);
    } catch(err) {
        cerrarModal('modalWaMasivoEmp');
        mostrarToast('❌ Error al cargar previsualización: ' + err.message, 'error');
    }
}

async function cargarLotePrevisualizacionEmpresa(plantillaId) {
    const loading = document.getElementById('waPrevisualizacionLoadingEmp');
    const content = document.getElementById('waPrevisualizacionContentEmp');
    const incluirValor = document.getElementById('waIncluirValorEmp').checked ? '1' : '0';

    loading.style.display = 'block';
    content.style.display = 'none';

    const queryParams = new URLSearchParams(window.location.search);
    if (plantillaId) queryParams.set('plantilla_id', plantillaId);
    queryParams.set('incluir_valor', incluirValor);

    const r = await fetch(`{{ route('admin.cobros.empresas.whatsapp.previsualizar') }}?${queryParams.toString()}`, {
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF }
    });
    const data = await r.json();

    if (!data.ok) {
        cerrarModal('modalWaMasivoEmp');
        mostrarToast('⚠️ ' + data.mensaje, 'error');
        return;
    }

    // Bloqueo / Habilitación del botón Confirmar según envíos válidos disponibles
    const btnConfirmar = document.getElementById('btnWaConfirmarMasivoEmp');
    const bannerHoy    = document.getElementById('waBannerEnvioHoyEmp');
    const resumen      = data.resumen_envios;

    if (resumen && resumen.envios_validos === 0) {
        btnConfirmar.disabled = true;
        btnConfirmar.style.opacity = '.5';
        btnConfirmar.style.cursor  = 'not-allowed';
        bannerHoy.innerHTML = `⚠️ No hay nuevas empresas pendientes por enviar en este filtro el día de hoy (todas ya fueron enviadas o no tienen celular válido).`;
        bannerHoy.style.display = 'block';
        bannerHoy.style.background = '#fef2f2';
        bannerHoy.style.borderColor = '#fecaca';
        bannerHoy.style.color = '#991b1b';
    } else {
        btnConfirmar.disabled = false;
        btnConfirmar.style.opacity = '1';
        btnConfirmar.style.cursor  = 'pointer';
        if (data.envio_hoy) {
            bannerHoy.innerHTML = `ℹ️ Se detectó un envío masivo previo realizado hoy a las <strong>${data.envio_hoy.hora}</strong>. Se omitirán las empresas ya procesadas.`;
            bannerHoy.style.display = 'block';
            bannerHoy.style.background = '#eff6ff';
            bannerHoy.style.borderColor = '#bfdbfe';
            bannerHoy.style.color = '#1e3a8a';
        } else {
            bannerHoy.style.display = 'none';
        }
    }

    cantClientesMasivoEmp = data.cant_clientes;
    cantEnviosValidosMasivoEmp = resumen ? resumen.envios_validos : cantClientesMasivoEmp;
    // El contador muestra el TOTAL de empresas pendientes (no solo las con celular)
    document.getElementById('waDestinatariosCountEmp').textContent = resumen ? resumen.total_destinatarios : cantClientesMasivoEmp;

    // Resumen envíos
    const resCont = document.getElementById('waResumenEnviosEmp');
    if (resumen) {
        resCont.innerHTML = `
            <div style="display:flex;justify-content:space-between;border-bottom:1px dashed rgba(22,101,52,.12);padding:.2rem 0;">
                <span>🏢 Listas para enviar:</span>
                <strong>${resumen.solo_uno} empresas</strong>
            </div>
            <div style="display:flex;justify-content:space-between;border-bottom:1px dashed rgba(22,101,52,.12);padding:.2rem 0;color:#991b1b;">
                <span>⚠️ Sin celular válido:</span>
                <strong>${resumen.sin_celular} omitidas</strong>
            </div>
            <div style="display:flex;justify-content:space-between;border-bottom:1px dashed rgba(22,101,52,.12);padding:.2rem 0;color:#b45309;padding-left:.8rem;">
                <span>ya enviadas hoy:</span>
                <strong>${resumen.ya_enviados_hoy} omitidas</strong>
            </div>
            <div style="display:flex;justify-content:space-between;padding:.3rem 0;font-size:.82rem;margin-top:.3rem;color:#15803d;">
                <strong>💬 TOTAL ENVIOS REALES:</strong>
                <strong>${resumen.envios_validos} de ${resumen.total_destinatarios}</strong>
            </div>
        `;
        resCont.style.display = 'block';
    } else {
        resCont.style.display = 'none';
    }

    // Poblar Selector de Plantillas
    const selectP = document.getElementById('waPlantillaSelectEmp');
    selectP.innerHTML = '';
    if (data.plantillas && data.plantillas.length > 0) {
        data.plantillas.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p.id;
            opt.textContent = p.nombre_display;
            if (p.id === data.plantilla_id) opt.selected = true;
            selectP.appendChild(opt);
        });
    }

    // Cargar previsualizaciones reales
    waPreviewsEmp = data.previsualizaciones || [];
    waPreviewIndexActEmp = 0;
    mostrarPrevisualizacionActualEmp();

    // Mostrar control de navegación si hay más de 1 previsualización
    const navCont = document.getElementById('waPreviewNavigationEmp');
    if (waPreviewsEmp.length > 1) {
        navCont.style.display = 'flex';
    } else {
        navCont.style.display = 'none';
    }

    const foot = document.getElementById('waPreviewFooterEmp');
    if (data.footer) { foot.textContent = data.footer; foot.style.display = 'block'; }
    else { foot.style.display = 'none'; }

    const imgCont = document.getElementById('waPreviewHeaderImageContainerEmp');
    const img     = document.getElementById('waPreviewHeaderImageEmp');
    if (data.header_tipo === 'IMAGE' && data.header_imagen) {
        img.src = data.header_imagen; imgCont.style.display = 'block';
    } else { imgCont.style.display = 'none'; }

    const btnCont = document.getElementById('waPreviewButtonsEmp');
    btnCont.innerHTML = '';
    if (data.botones && data.botones.length > 0) {
        data.botones.forEach(btn => {
            const bDiv = document.createElement('div');
            bDiv.style.cssText = 'background:#fff;border:1px solid #e2e8f0;border-radius:24px;padding:.45rem;font-size:.78rem;text-align:center;font-weight:700;color:#00a884;cursor:pointer;box-shadow:0 1px 3px rgba(0,0,0,.05);transition:background .15s;';
            bDiv.textContent = btn.texto;
            btnCont.appendChild(bDiv);
        });
        btnCont.style.display = 'flex';
    } else { btnCont.style.display = 'none'; }

    loading.style.display = 'none';
    content.style.display = 'block';
}

function recargarPrevisualizacionEmpresa() {
    const sel = document.getElementById('waPlantillaSelectEmp');
    if (sel) {
        cargarLotePrevisualizacionEmpresa(sel.value).catch(err => {
            mostrarToast('❌ Error al actualizar vista previa: ' + err.message, 'error');
        });
    }
}

function mostrarPrevisualizacionActualEmp() {
    if (waPreviewsEmp.length === 0) return;
    const item = waPreviewsEmp[waPreviewIndexActEmp];
    
    let textFormateado = item.cuerpo;
    textFormateado = textFormateado.replace(/\*(.*?)\*/g, '<strong>$1</strong>');
    textFormateado = textFormateado.replace(/_(.*?)_/g, '<em>$1</em>');
    
    document.getElementById('waPreviewBodyEmp').innerHTML = textFormateado;
    
    // Actualizar indicador
    document.getElementById('waPreviewIndexEmp').innerHTML = `<strong>${item.nombre}</strong><br/><span style="color:#64748b;font-size:.65rem;font-weight:600;">(${waPreviewIndexActEmp + 1} de ${waPreviewsEmp.length})</span>`;
    
    // Deshabilitar flechas en los extremos
    document.getElementById('btnWaPrevEmp').disabled = (waPreviewIndexActEmp === 0);
    document.getElementById('btnWaPrevEmp').style.opacity = (waPreviewIndexActEmp === 0) ? '.4' : '1';
    document.getElementById('btnWaPrevEmp').style.cursor = (waPreviewIndexActEmp === 0) ? 'not-allowed' : 'pointer';
    
    document.getElementById('btnWaNextEmp').disabled = (waPreviewIndexActEmp === waPreviewsEmp.length - 1);
    document.getElementById('btnWaNextEmp').style.opacity = (waPreviewIndexActEmp === waPreviewsEmp.length - 1) ? '.4' : '1';
    document.getElementById('btnWaNextEmp').style.cursor = (waPreviewIndexActEmp === waPreviewsEmp.length - 1) ? 'not-allowed' : 'pointer';
}

function navegarVistaPreviaEmp(direccion) {
    const nuevoIndice = waPreviewIndexActEmp + direccion;
    if (nuevoIndice >= 0 && nuevoIndice < waPreviewsEmp.length) {
        waPreviewIndexActEmp = nuevoIndice;
        mostrarPrevisualizacionActualEmp();
    }
}

function cambiarTabWaEmp(tab) {
    waTabActivaEmp = tab;
    document.getElementById('waTabPreviewEmp').classList.toggle('active', tab === 'preview');
    document.getElementById('waTabHistorialEmp').classList.toggle('active', tab === 'historial');
    document.getElementById('waPanelPreviewEmp').style.display   = tab === 'preview'   ? 'block' : 'none';
    document.getElementById('waPanelHistorialEmp').style.display = tab === 'historial' ? 'block' : 'none';
    if (tab === 'historial') cargarHistorialEnviosEmp();
}

async function cargarHistorialEnviosEmp() {
    const cont = document.getElementById('waHistorialContenidoEmp');
    cont.innerHTML = '<div style="text-align:center;padding:1.5rem;color:#64748b;">Cargando historial...</div>';
    try {
        const queryParams = new URLSearchParams(window.location.search);
        const r    = await fetch(`{{ route('admin.cobros.whatsapp.historial') }}?${queryParams.toString()}`);
        const data = await r.json();
        // Filtrar lotes de tipo 'empresa'
        const lotesEmp = (data.lotes || []).filter(l => l.tipo_envio === 'empresa' || l.tipo === 'empresa');
        
        if (!data.ok || lotesEmp.length === 0) {
            cont.innerHTML = '<div style="text-align:center;padding:2rem;color:#94a3b8;">Sin envíos masivos a empresas este mes.</div>';
            return;
        }
        let html = `<table style="width:100%;border-collapse:collapse;font-size:.78rem;">
            <thead><tr style="background:#f8fafc;">
                <th style="padding:.45rem .5rem;text-align:left;border-bottom:1px solid #e2e8f0;">Fecha</th>
                <th style="padding:.45rem .5rem;text-align:left;border-bottom:1px solid #e2e8f0;">Estado</th>
                <th style="padding:.45rem .5rem;text-align:center;border-bottom:1px solid #e2e8f0;">Total</th>
                <th style="padding:.45rem .5rem;text-align:center;border-bottom:1px solid #e2e8f0;">Env.</th>
                <th style="padding:.45rem .5rem;text-align:center;border-bottom:1px solid #e2e8f0;">Fal.</th>
                <th style="padding:.45rem .5rem;text-align:left;border-bottom:1px solid #e2e8f0;">Usuario</th>
                <th style="padding:.45rem .5rem;border-bottom:1px solid #e2e8f0;"></th>
            </tr></thead><tbody>`;
        lotesEmp.forEach(l => {
            const esHoyBadge = l.es_hoy ? '<span style="background:#dcfce7;color:#166534;border-radius:4px;padding:.1rem .35rem;font-size:.65rem;margin-left:.4rem;">HOY</span>' : '';
            html += `<tr style="border-bottom:1px solid #f1f5f9;">
                <td style="padding:.4rem .5rem;">${l.fecha}${esHoyBadge}</td>
                <td style="padding:.4rem .5rem;">${l.etiqueta}</td>
                <td style="padding:.4rem .5rem;text-align:center;">${l.total_destinatarios}</td>
                <td style="padding:.4rem .5rem;text-align:center;color:#16a34a;">${l.total_enviados}</td>
                <td style="padding:.4rem .5rem;text-align:center;color:#dc2626;">${l.total_fallidos}</td>
                <td style="padding:.4rem .5rem;">${l.usuario}</td>
                <td style="padding:.4rem .5rem;text-align:center;">
                    <button type="button" onclick="abrirInformeLoteEmp(${l.id})"
                        style="background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;border-radius:6px;padding:.25rem .55rem;font-size:.72rem;cursor:pointer;">
                        📋 Informe
                    </button>
                </td>
            </tr>`;
        });
        html += '</tbody></table>';
        cont.innerHTML = html;
    } catch(err) {
        cont.innerHTML = `<div style="color:#dc2626;padding:1rem;">Error: ${err.message}</div>`;
    }
}

async function abrirInformeLoteEmp(loteId) {
    const modal = document.getElementById('modalWaInformeEmp');
    const cont  = document.getElementById('waInformeContenidoEmp');
    cont.innerHTML = '<div style="text-align:center;padding:2rem;color:#64748b;">Cargando informe...</div>';
    modal.classList.add('open');
    try {
        const r    = await fetch(`{{ url('admin/cobros/whatsapp') }}/${loteId}/reporte`);
        const data = await r.json();
        if (!data.ok) throw new Error(data.mensaje || 'Error');
        const lote = data.lote;
        const hayFallidos = data.detalles.some(d => d.estado === 'fallido' && d.wa_numero);
        let html = `<div style="display:flex;gap:.75rem;margin-bottom:1rem;flex-wrap:wrap;">
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;padding:.5rem .8rem;border-radius:8px;font-size:.78rem;">
                📋 <strong>${lote.plantilla}</strong>
            </div>
            <div style="background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;padding:.5rem .8rem;border-radius:8px;font-size:.78rem;">
                📅 ${lote.fecha} — <em>${lote.usuario}</em>
            </div>
            <div style="padding:.5rem .8rem;border-radius:8px;font-size:.78rem;background:#f8fafc;border:1px solid #e2e8f0;">
                Total: ${lote.total_destinatarios} | ✅ ${lote.total_enviados} | ❌ ${lote.total_fallidos}
            </div>
            ${hayFallidos ? `<button type="button" onclick="reintentarLoteEmp(${lote.id})" id="btnReintentarLoteEmp"
                style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;border-radius:8px;padding:.4rem .75rem;font-size:.76rem;cursor:pointer;">
                🔄 Reintentar fallidos
            </button>` : ''}
        </div>
        <table style="width:100%;border-collapse:collapse;font-size:.77rem;">
            <thead><tr style="background:#f8fafc;">
                <th style="padding:.4rem .5rem;text-align:left;border-bottom:1px solid #e2e8f0;">Empresa</th>
                <th style="padding:.4rem .5rem;text-align:left;border-bottom:1px solid #e2e8f0;">Celular</th>
                <th style="padding:.4rem .5rem;text-align:right;border-bottom:1px solid #e2e8f0;">Valor</th>
                <th style="padding:.4rem .5rem;text-align:center;border-bottom:1px solid #e2e8f0;">Estado</th>
                <th style="padding:.4rem .5rem;text-align:center;border-bottom:1px solid #e2e8f0;">WA</th>
            </tr></thead><tbody>`;
        data.detalles.forEach(d => {
            const errTxt   = d.error ? `<div style="color:#dc2626;font-size:.68rem;">${d.error}</div>` : '';
            html += `<tr style="border-bottom:1px solid #f1f5f9;">
                <td style="padding:.38rem .5rem;">${d.nombre}</td>
                <td style="padding:.38rem .5rem;color:#64748b;">${d.wa_numero || '<span style="color:#94a3b8">Sin número</span>'}</td>
                <td style="padding:.38rem .5rem;text-align:right;">${d.valor_cobro || '—'}</td>
                <td style="padding:.38rem .5rem;text-align:center;">${waEstadoIconEmp(d.estado)}${errTxt}</td>
                <td style="padding:.38rem .5rem;text-align:center;">${waMsgEstadoIconEmp(d.estado_wa)}</td>
            </tr>`;
        });
        html += '</tbody></table>';
        cont.innerHTML = html;
    } catch(err) {
        cont.innerHTML = `<div style="color:#dc2626;padding:1rem;">Error: ${err.message}</div>`;
    }
}

function waEstadoIconEmp(estado) {
    const m = { pendiente:'<span style="color:#f59e0b;">⏳ Pendiente</span>', fallido:'<span style="color:#dc2626;">❌ Fallido</span>', enviado:'<span style="color:#16a34a;">✅ Enviado</span>' };
    return m[estado] || '<span style="color:#94a3b8;">—</span>';
}
function waMsgEstadoIconEmp(estado) {
    if (!estado) return '<span style="color:#94a3b8;font-size:.75rem;">—</span>';
    const m = { enviado:'<span title="Enviado a Meta" style="font-size:.85rem;">📤</span>', entregado:'<span title="Entregado" style="font-size:.85rem;">✓✓</span>', leido:'<span title="Leído" style="color:#22c55e;font-size:.85rem;">✓✓</span>', fallido:'<span title="Falló" style="color:#dc2626;font-size:.85rem;">✗</span>' };
    return m[estado] || `<span style="font-size:.7rem;color:#64748b;">${estado}</span>`;
}

async function reintentarLoteEmp(loteId) {
    const btn = document.getElementById('btnReintentarLoteEmp');
    if (btn) { btn.disabled = true; btn.textContent = 'Reintentando...'; }
    try {
        const r = await fetch(`{{ url('admin/cobros/whatsapp') }}/${loteId}/reintentar`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json', 'Content-Type': 'application/json' }
        });
        const data = await r.json();
        if (!data.ok) throw new Error(data.mensaje || 'Error');
        mostrarToast('🔄 ' + data.mensaje, 'success');
        cerrarModal('modalWaInformeEmp');
    } catch(err) {
        mostrarToast('❌ ' + err.message, 'error');
        if (btn) { btn.disabled = false; btn.textContent = '🔄 Reintentar fallidos'; }
    }
}

async function confirmarEnvioMasivoWaEmp() {
    if (cantEnviosValidosMasivoEmp === 0) { mostrarToast('⚠️ No hay destinatarios pendientes de envío con datos válidos.', 'error'); return; }
    if (!confirm(`¿Confirmar el envío masivo a ${cantEnviosValidosMasivoEmp} empresas? Las que ya recibieron hoy serán excluidas automáticamente.`)) return;

    const btn = document.getElementById('btnWaConfirmarMasivoEmp');
    btn.disabled = true; btn.textContent = 'Procesando envío...';
    
    const selPlantilla = document.getElementById('waPlantillaSelectEmp');
    const incluirValor = document.getElementById('waIncluirValorEmp').checked ? '1' : '0';

    try {
        const queryParams = new URLSearchParams(window.location.search);
        queryParams.set('plantilla_id', selPlantilla.value);
        queryParams.set('incluir_valor', incluirValor);

        const r = await fetch(`{{ route('admin.cobros.empresas.whatsapp.enviar') }}?${queryParams.toString()}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' }
        });
        const data = await r.json();
        if (!data.ok) throw new Error(data.mensaje || 'Error al programar');
        cerrarModal('modalWaMasivoEmp');
        mostrarToast('✅ ' + data.mensaje, 'success');
        setTimeout(() => { location.reload(); }, 1800);
    } catch(err) {
        mostrarToast('❌ ' + err.message, 'error');
        btn.disabled = false; btn.textContent = '💬 Confirmar Envío Masivo a Empresas';
    }
}

</script>
@endpush
@endsection
