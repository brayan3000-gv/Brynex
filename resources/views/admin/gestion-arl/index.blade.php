@extends('layouts.app')
@section('modulo', 'Gestión ARL')

@push('styles')
<style>
html,body{height:100%;overflow:hidden}
body{display:flex;flex-direction:column}
.header{flex-shrink:0}
.contenido{flex:1!important;min-height:0!important;overflow:hidden!important;display:flex!important;flex-direction:column!important;padding:.75rem 1rem!important;gap:.5rem}
</style>
@endpush

@section('contenido')
<style>
/* ── Header ── */
.garl-header{background:linear-gradient(135deg,#0f172a 0%,#1a3a5c 50%,#0e4d2f 100%);padding:.8rem 1.2rem;border-radius:12px;color:#fff;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;flex-shrink:0}
.garl-title{font-size:1.3rem;font-weight:800;letter-spacing:.02em}
.garl-sub{font-size:.78rem;color:#94a3b8;margin-top:.15rem}

/* ── Tabla ── */
.tbl-wrap{overflow-x:auto;overflow-y:auto;border-radius:12px;border:1px solid #e2e8f0;background:#fff;flex:1;min-height:0}
.tbl-arl{width:100%;border-collapse:collapse;font-size:.78rem;white-space:nowrap}
.tbl-arl thead th{background:#0f172a;color:#fff;padding:.55rem .6rem;font-weight:600;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;position:sticky;top:0;z-index:2}
.tbl-arl tbody tr{border-bottom:1px solid #f1f5f9;transition:background .12s}
.tbl-arl tbody tr:hover{background:#f8fafc}
.tbl-arl td{padding:.45rem .55rem;vertical-align:middle}

/* ── Semáforo ── */
.sem-dot{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;font-size:.7rem;font-weight:800;flex-shrink:0;box-shadow:0 2px 6px rgba(0,0,0,.15)}
.sem-verde   {background:#16a34a;color:#fff}
.sem-amarillo{background:#d97706;color:#fff}
.sem-rojo    {background:#dc2626;color:#fff;animation:pulse-red 1.5s ease-in-out infinite}
.sem-sin_fecha{background:#94a3b8;color:#fff}
@keyframes pulse-red{0%,100%{box-shadow:0 2px 6px rgba(220,38,38,.3)}50%{box-shadow:0 2px 16px rgba(220,38,38,.7)}}

.dias-badge{font-size:.65rem;font-weight:700;padding:.1rem .4rem;border-radius:10px;display:inline-block;margin-left:.3rem}
.dias-verde   {background:#dcfce7;color:#15803d}
.dias-amarillo{background:#fef3c7;color:#b45309}
.dias-rojo    {background:#fee2e2;color:#b91c1c}
.dias-sin_fecha{background:#f1f5f9;color:#64748b}

/* ── Razon badge ── */
.razon-badge{font-weight:700;font-size:.75rem;padding:.2rem .6rem;border-radius:6px;background:#dbeafe;color:#1e40af;display:inline-block;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

/* ── Fact badge ── */
.fact-badge{background:#f0fdf4;color:#15803d;font-size:.68rem;font-weight:700;padding:.15rem .45rem;border-radius:6px;border:1px solid #86efac;white-space:nowrap}
.fact-none{color:#cbd5e1;font-size:.68rem}

/* ── Primer mes badge ── */
.primer-mes{background:#ede9fe;color:#7c3aed;font-size:.6rem;font-weight:700;padding:.1rem .35rem;border-radius:10px;margin-left:.3rem}

/* ── Botones ── */
.btn-accion{border:none;border-radius:7px;padding:.28rem .6rem;font-size:.68rem;font-weight:700;cursor:pointer;transition:all .15s;white-space:nowrap;display:inline-flex;align-items:center;gap:.25rem}
.btn-renovar {background:#0d9488;color:#fff}.btn-renovar:hover{background:#0f766e}
.btn-certificado {background:#b91c1c;color:#fff;font-weight:700}.btn-certificado:hover{background:#991b1b}
.btn-facturar{background:#1e40af;color:#fff}.btn-facturar:hover{background:#1d4ed8}
.btn-retirar {background:#f1f5f9;color:#64748b;border:1px solid #e2e8f0}.btn-retirar:hover{background:#fee2e2;color:#b91c1c}
.btn-contrato{background:#64748b;color:#fff;text-decoration:none}.btn-contrato:hover{background:#475569;color:#fff}

/* ── Select en th ── */
.th-select{width:100%;background:transparent;border:none;border-bottom:1px solid rgba(255,255,255,.15);color:#fff;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;padding:.22rem .2rem;cursor:pointer;outline:none;appearance:auto}
.th-select.activo{border-bottom-color:#3b82f6;color:#93c5fd}
.th-select option{background:#0f172a;color:#fff}

/* ── Sort links en th ── */
.th-sort{color:#94a3b8;text-decoration:none;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;display:block;white-space:nowrap;transition:color .15s}
.th-sort:hover{color:#e2e8f0}
.th-sort.activo{color:#93c5fd}

/* ── Modales ── */
.modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;backdrop-filter:blur(2px)}
.modal-bg.open{display:flex}
.modal-box{background:#fff;border-radius:16px;padding:1.5rem;max-width:480px;width:94%;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);animation:modalIn .2s ease}
.modal-box.wide{max-width:680px}
@keyframes modalIn{from{transform:translateY(-20px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal-title{font-size:1rem;font-weight:800;color:#0f172a;margin-bottom:1rem;padding-bottom:.6rem;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between}
.modal-close{background:none;border:none;font-size:1.2rem;cursor:pointer;color:#94a3b8}
.modal-close:hover{color:#ef4444}
.form-group{display:flex;flex-direction:column;gap:.25rem;margin-bottom:.8rem}
.form-group label{font-size:.72rem;font-weight:600;color:#475569;text-transform:uppercase;letter-spacing:.04em}
.form-group input,.form-group select,.form-group textarea{padding:.5rem .7rem;border:1px solid #cbd5e1;border-radius:8px;font-size:.85rem;outline:none;font-family:inherit}
.form-group input:focus,.form-group select:focus{border-color:#3b82f6;box-shadow:0 0 0 2px rgba(59,130,246,.1)}
.btn-save{background:linear-gradient(135deg,#1e40af,#2563eb);color:#fff;border:none;border-radius:10px;padding:.6rem 1.5rem;font-size:.88rem;font-weight:700;cursor:pointer;box-shadow:0 3px 10px rgba(37,99,235,.3);transition:all .15s;width:100%}
.btn-save:hover{transform:translateY(-1px);box-shadow:0 5px 15px rgba(37,99,235,.4)}

/* ── Alerta vencidos ── */
.banner-alerta{background:linear-gradient(135deg,#7f1d1d,#991b1b);color:#fff;border-radius:10px;padding:.6rem 1rem;font-size:.82rem;font-weight:600;flex-shrink:0;display:flex;align-items:center;gap:.5rem}
</style>

{{-- ══ HEADER + FILTROS ══ --}}
<form method="GET" action="{{ route('admin.gestion-arl.index') }}" id="formFiltros" style="flex-shrink:0;">
<div class="garl-header">
    <div>
        <div class="garl-title">🛡️ Gestión ARL</div>
        <div class="garl-sub">Seguimiento de contratos ARL — {{ now()->format('d/m/Y') }}</div>
    </div>
    <div style="display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;margin-left:auto;">
        <button type="button" onclick="abrirModalClavesGlobal()" style="background:linear-gradient(135deg,#fbbf24,#f59e0b);color:#1c1917;padding:.32rem .75rem;border-radius:6px;font-size:.78rem;font-weight:800;border:none;box-shadow:0 2px 6px rgba(0,0,0,0.25);cursor:pointer;">🔑 Claves</button>
        <span style="color:#4b6a8b;">|</span>

        {{-- Aliado (solo BryNex) --}}
        @if($user->es_brynex && count($alidosDisponibles) > 1)
        <select name="aliado_id" onchange="this.form.submit()" style="font-size:.78rem;padding:.3rem .5rem;border:1px solid #334155;background:#1a3a5c;color:#e2e8f0;border-radius:6px;font-weight:700;">
            @foreach($alidosDisponibles as $al)
            <option value="{{ $al->id }}" {{ $alidoId == $al->id ? 'selected' : '' }}>{{ $al->nombre }}</option>
            @endforeach
        </select>
        <span style="color:#4b6a8b;">|</span>
        @endif

        {{-- Encargado --}}
        <select name="encargado_id" onchange="this.form.submit()" style="font-size:.78rem;padding:.3rem .5rem;border:1px solid #334155;background:#1a3a5c;color:#e2e8f0;border-radius:6px;">
            <option value="">— Todos —</option>
            @foreach($encargados as $enc)
            <option value="{{ $enc->id }}" {{ $encId == $enc->id ? 'selected' : '' }}>{{ $enc->nombre }}</option>
            @endforeach
        </select>

        <span style="color:#4b6a8b;">|</span>

        {{-- Buscador por nombre / cédula --}}
        <div style="display:flex;align-items:center;background:#1a3a5c;border:1px solid #334155;border-radius:6px;overflow:hidden;">
            <span style="padding:0 .4rem;color:#64748b;font-size:.85rem;">🔍</span>
            <input type="text" name="buscar" value="{{ $buscar }}"
                   placeholder="Nombre o cédula…"
                   style="background:transparent;border:none;outline:none;color:#e2e8f0;font-size:.78rem;padding:.3rem .4rem .3rem 0;width:160px;"
                   onkeydown="if(event.key==='Enter'){this.form.submit();}">
            @if($buscar)
            <a href="{{ request()->fullUrlWithQuery(['buscar'=>'']) }}"
               style="padding:0 .4rem;color:#94a3b8;font-size:.75rem;text-decoration:none;" title="Limpiar búsqueda">✕</a>
            @endif
        </div>

        <span style="background:rgba(255,255,255,.15);color:#fff;font-size:.88rem;font-weight:800;padding:.3rem .7rem;border-radius:20px;white-space:nowrap;">
            {{ $contratos->count() }} <span style="font-size:.7rem;font-weight:500;opacity:.75;">vigentes</span>
        </span>
    </div>
</div>
</form>

{{-- ── Banner si hay vencidos ── --}}
@php $totalRojos = $contratos->where('semaforo','rojo')->count(); @endphp
@if($totalRojos > 0)
<div class="banner-alerta" style="flex-shrink:0;">
    🚨 <strong>{{ $totalRojos }} ARL {{ $totalRojos == 1 ? 'vencida' : 'vencidas' }}</strong> — Requiere atención inmediata. El retiro ya no puede ser informativo.
</div>
@endif

{{-- ══ TABLA ══ --}}
@if($contratos->isEmpty())
<div style="flex:1;display:flex;align-items:center;justify-content:center;">
<div style="text-align:center;padding:3rem;color:#94a3b8;background:#fff;border-radius:12px;border:1px solid #e2e8f0;max-width:420px;">
    <div style="font-size:3rem;">🛡️</div>
    <div style="font-size:1rem;font-weight:600;margin-top:.5rem;">Sin contratos ARL vigentes</div>
    <div style="font-size:.8rem;margin-top:.25rem;">No hay contratos de Gestión ARL activos.</div>
</div>
</div>
@else
<div class="tbl-wrap">
<table class="tbl-arl">
    @php
        // Helpers de ordenamiento
        $si = fn($col) => $sort === $col ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
        $su = fn($col) => request()->fullUrlWithQuery(['sort' => $col, 'dir' => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc']);
        $sc = fn($col) => 'th-sort' . ($sort === $col ? ' activo' : '');
    @endphp
    <thead>
        <tr>
            <th style="width:36px;text-align:center;">
                <a href="{{ $su('semaforo') }}" class="{{ $sc('semaforo') }}" title="Ordenar por urgencia">⚡{{ $si('semaforo') }}</a>
            </th>
            <th style="max-width:130px;">
                <form method="GET" action="{{ route('admin.gestion-arl.index') }}" style="margin:0;">
                    @foreach(request()->except(['razon_social_id']) as $k=>$v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                    <select name="razon_social_id" onchange="this.form.submit()" class="th-select {{ $rsId ? 'activo' : '' }}" style="max-width:125px;">
                        <option value="">↓ Razón Social</option>
                        @foreach($razonesDisponibles as $rs)<option value="{{ $rs->id }}" {{ $rsId == $rs->id ? 'selected' : '' }}>{{ $rs->razon_social }}</option>@endforeach
                    </select>
                </form>
            </th>
            <th>
                <a href="{{ $su('fecha_arl') }}" class="{{ $sc('fecha_arl') }}" title="Ordenar por fecha ARL">Fecha ARL{{ $si('fecha_arl') }}</a>
            </th>
            <th style="white-space:nowrap;">
                <a href="{{ $su('semaforo') }}" class="{{ $sc('semaforo') }}" title="Ordenar por días restantes">D. Rest. 📅{{ $si('semaforo') }}</a>
            </th>
            <th>
                <a href="{{ $su('dias_fact') }}" class="{{ $sc('dias_fact') }}" title="Ordenar por última factura">Fact.{{ $si('dias_fact') }}</a>
            </th>
            <th style="white-space:nowrap;">
                <a href="{{ $su('dias_fact') }}" class="{{ $sc('dias_fact') }}" title="Ordenar por días sin facturar">⏱ Sin fact.{{ $si('dias_fact') }}</a>
            </th>
            <th>
                <a href="{{ $su('cedula') }}" class="{{ $sc('cedula') }}" title="Ordenar por documento">Documento{{ $si('cedula') }}</a>
            </th>
            <th>
                <a href="{{ $su('nombre') }}" class="{{ $sc('nombre') }}" title="Ordenar por nombre">Nombres{{ $si('nombre') }}</a>
            </th>
            <th>
                <form method="GET" action="{{ route('admin.gestion-arl.index') }}" style="margin:0;">
                    @foreach(request()->except(['arl_id']) as $k=>$v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                    <select name="arl_id" onchange="this.form.submit()" class="th-select {{ $arlF ? 'activo' : '' }}">
                        <option value="">↓ ARL</option>
                        @foreach($arlDisponibles as $a)<option value="{{ $a->id }}" {{ $arlF == $a->id ? 'selected' : '' }}>{{ $a->nombre_arl }}</option>@endforeach
                    </select>
                </form>
            </th>
            <th>N.ARL</th>
            <th>
                <form method="GET" action="{{ route('admin.gestion-arl.index') }}" style="margin:0;">
                    @foreach(request()->except(['empresa_id']) as $k=>$v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
                    <select name="empresa_id" onchange="this.form.submit()" class="th-select {{ $empresaId ? 'activo' : '' }}" style="max-width:120px;">
                        <option value="">↓ Empresa</option>
                        @foreach($empresasDisponibles as $emp)<option value="{{ $emp->id }}" {{ $empresaId == $emp->id ? 'selected' : '' }}>{{ $emp->empresa }}</option>@endforeach
                    </select>
                </form>
            </th>
            <th style="min-width:160px;">Acciones</th>
        </tr>
    </thead>
    <tbody>
    @foreach($contratos as $c)
    @php
        $nombre = trim(collect([$c->cliente?->primer_nombre,$c->cliente?->primer_apellido])->filter()->implode(' '));
        $nombre = nombre_oracion($nombre);
        $nombreCompleto = trim(collect([$c->cliente?->primer_nombre,$c->cliente?->segundo_nombre,$c->cliente?->primer_apellido,$c->cliente?->segundo_apellido])->filter()->implode(' '));
        $nombreCompleto = nombre_oracion($nombreCompleto);
        $empresa = $c->cliente?->empresa?->empresa ?? '—';
        // Última factura display
        $uf = $c->ultima_factura;
        $fechaUf   = $uf ? ($uf->fecha_pago ? \Carbon\Carbon::parse($uf->fecha_pago) : \Carbon\Carbon::create($uf->anio, $uf->mes, 1)) : null;
        $ufDisplay = $uf ? $uf->numero_factura . ' (' . $fechaUf->format('d/m') . ')' : null;
        // Semáforo
        $sem = $c->semaforo;
        $diasR = $c->dias_restantes;
        $diasText = $diasR !== null ? ($diasR >= 0 ? "{$diasR}d" : 'VEN') : '—';
        // Fecha ARL display (fallback a fecha_ingreso con indicador visual)
        $esFechaIngreso  = !$c->fecha_arl && $c->fecha_ingreso;
        $fechaArlDisplay = $c->fecha_arl
            ? $c->fecha_arl->format('d/m/Y')
            : ($c->fecha_ingreso ? $c->fecha_ingreso->format('d/m/Y') : '—');
        // Data para modales
        $ctx = json_encode([
            'id'            => $c->id,
            'cedula'        => $c->cedula,
            'nombre'        => $nombre,
            'nombre_completo'=> $nombreCompleto,
            'razon_social'  => $c->razonSocial?->razon_social ?? '—',
            'fecha_arl'     => $c->fecha_arl?->format('Y-m-d') ?? '',
            'fecha_ingreso' => $c->fecha_ingreso?->format('d/m/Y') ?? '—',
            'arl'           => $c->arl_efectiva_nombre,
            'n_arl'         => $c->n_arl ?? 1,
            'semaforo'      => $sem,
            'dias_restantes'=> $diasR,
            'empresa_id'    => $c->cliente?->empresa?->id ?? null,
        ]);
    @endphp
    <tr id="row-{{ $c->id }}">
        {{-- Semáforo --}}
        <td style="text-align:center;">
            <span class="sem-dot sem-{{ $sem }}" title="{{ $sem === 'verde' ? 'Vigente' : ($sem === 'amarillo' ? 'Por vencer' : ($sem === 'rojo' ? 'VENCIDO' : 'Sin fecha')) }}">
                {{ $sem === 'verde' ? '✓' : ($sem === 'amarillo' ? '!' : ($sem === 'rojo' ? '✗' : '?')) }}
            </span>
        </td>

        {{-- Razón Social --}}
        <td><span class="razon-badge" title="{{ $c->razonSocial?->razon_social }}">{{ $c->razonSocial?->razon_social ?? '—' }}</span></td>

        {{-- Fecha ARL --}}
        <td style="font-size:.75rem;font-weight:700;color:{{ $esFechaIngreso ? '#64748b' : ($sem === 'rojo' ? '#dc2626' : ($sem === 'amarillo' ? '#b45309' : '#15803d')) }};">
            {{ $fechaArlDisplay }}
            @if($esFechaIngreso)
                <span style="font-size:.58rem;font-weight:600;background:#f1f5f9;color:#64748b;padding:.1rem .35rem;border-radius:8px;margin-left:.2rem;border:1px solid #e2e8f0;">ing.</span>
            @elseif($c->es_primer_mes)
                <span class="primer-mes">1er mes</span>
            @endif
        </td>

        {{-- Días restantes --}}
        <td>
            <span class="dias-badge dias-{{ $sem }}">{{ $diasText }}</span>
        </td>

        {{-- Última factura --}}
        <td>
            @if($ufDisplay)<span class="fact-badge">{{ $ufDisplay }}</span>
            @else<span class="fact-none">—</span>@endif
        </td>

        {{-- Días desde última factura --}}
        <td style="text-align:center;">
            @if($c->dias_desde_factura !== null)
                @php $df = $c->dias_desde_factura; @endphp
                <span class="dias-badge {{ $df <= 10 ? 'dias-verde' : ($df <= 20 ? 'dias-amarillo' : 'dias-rojo') }}"
                      title="{{ $df }} días desde la última factura">
                    {{ $df }}d
                </span>
            @else
                <span class="fact-none">—</span>
            @endif
        </td>

        {{-- Documento --}}
        <td style="font-family:monospace;font-size:.77rem;font-weight:700;">
            <a href="/admin/clientes/{{ $c->cliente?->id }}/edit" style="color:#3b82f6;text-decoration:none;" title="Ver ficha del cliente">
                {{ strtoupper($c->cliente?->tipo_doc ?? 'CC') }} {{ $c->cedula }}
            </a>
        </td>

        {{-- Nombres --}}
        <td style="font-weight:600;color:#1e3a5f;max-width:140px;overflow:hidden;text-overflow:ellipsis;" title="{{ nombre_oracion($nombreCompleto) }}">
            {{ $nombre }}
        </td>

        {{-- ARL --}}
        <td style="font-size:.7rem;color:#475569;max-width:80px;overflow:hidden;text-overflow:ellipsis;" title="{{ $c->arl_efectiva_nombre }}">
            {{ $c->arl_efectiva_nombre }}
        </td>

        {{-- N.ARL --}}
        <td style="text-align:center;">
            @php $nivel = (int)($c->n_arl ?? 1); @endphp
            <span style="background:#f0fdf4;color:#15803d;font-size:.68rem;font-weight:800;padding:.15rem .45rem;border-radius:6px;border:1px solid #86efac;">N{{ $nivel }}</span>
        </td>

        {{-- Empresa --}}
        <td style="font-size:.65rem;max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="{{ $empresa }}">
            @if($c->cliente?->empresa?->id)
                <a href="/admin/facturacion/empresa/{{ $c->cliente->empresa->id }}" style="color:#3b82f6;text-decoration:none;" title="Ver Empresa">
                    {{ $empresa }}
                </a>
            @else
                —
            @endif
        </td>

        {{-- Acciones --}}
        <td style="white-space:nowrap;">
            <a class="btn-accion btn-contrato" href="/admin/contratos/{{ $c->id }}/edit" title="Ver/Editar Contrato">
                📄
            </a>
            @can('automatizar-arl')
            <button class="btn-accion btn-renovar" onclick="abrirRenovar({{ $ctx }})" title="Mover la cobertura del trabajador a la fecha del mes nuevo en ARL Sura">
                📅 Renovar
            </button>
            <button class="btn-accion btn-certificado" onclick="descargarCertificado(this, {{ $ctx }})" title="Bajar del portal el certificado y el carné al día">
                <svg width="13" height="15" viewBox="0 0 12 14" fill="none" style="vertical-align:-2px" aria-hidden="true">
                    <path d="M1 1.5A.5.5 0 0 1 1.5 1H7l4 4v7.5a.5.5 0 0 1-.5.5h-9a.5.5 0 0 1-.5-.5v-11Z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/>
                    <path d="M7 1v4h4" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/>
                    <path d="M6 7.6v3.2m0 0L4.7 9.6M6 10.8l1.3-1.2" stroke="currentColor" stroke-width="1.1" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>
            @endcan
            <button class="btn-accion btn-facturar" onclick="abrirFacturar({{ $ctx }})" title="Facturar afiliación ARL">
                💳 Facturar
            </button>
            <button class="btn-accion btn-retirar" onclick="abrirRetirar({{ $ctx->id ?? $c->id }}, '{{ addslashes($nombre) }}')" title="{{ Gate::allows('automatizar-arl') ? 'Anular la cobertura en Sura y dejar el contrato retirado' : 'Dejar el contrato retirado' }}">
                ❌
            </button>
        </td>
    </tr>
    @endforeach
    </tbody>
</table>
</div>
@endif

{{-- ══ MODAL RENOVAR EN ARL SURA ══ --}}
<div class="modal-bg" id="modalRenovar">
<div class="modal-box">
    <div class="modal-title">
        <span>📅 Renovar ARL en Sura</span>
        <button class="modal-close" onclick="cerrarModal('modalRenovar')">✕</button>
    </div>

    <div id="renovar-cargando" style="padding:1.5rem;text-align:center;color:#64748b;font-size:.82rem;">
        ⏳ Revisando los datos del contrato...
    </div>

    <div id="renovar-contenido" style="display:none;">
        <div id="renovar-resumen" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:.65rem .8rem;margin-bottom:.75rem;font-size:.76rem;line-height:1.6;"></div>

        {{-- Lo que el botón va a hacer en el portal, dicho antes de hacerlo --}}
        <div id="renovar-aviso" style="background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:.65rem .8rem;margin-bottom:.75rem;font-size:.75rem;color:#92400e;line-height:1.55;"></div>

        {{-- Datos que le faltan al contrato: se listan todos juntos --}}
        <div id="renovar-problemas" style="display:none;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:.65rem .8rem;margin-bottom:.75rem;font-size:.75rem;color:#991b1b;">
            <strong>Faltan datos para poder renovar:</strong>
            <ul id="renovar-problemas-lista" style="margin:.4rem 0 0 1rem;padding:0;"></ul>
        </div>

        {{-- Sin la contraseña del portal no se puede tocar Sura. Se pide aquí
             mismo y queda guardada contra el NIT, así sirve para todos los
             aliados donde esa empresa esté registrada. --}}
        <div id="renovar-credencial" style="display:none;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:.7rem .8rem;margin-bottom:.75rem;">
            <div style="font-size:.75rem;color:#1e40af;margin-bottom:.5rem;line-height:1.5;">
                Usuario y contraseña con que <strong id="renovar-cred-empresa"></strong> entra al portal de Sura. Se guarda una sola vez.
            </div>
            <div style="display:grid;grid-template-columns:110px 1fr;gap:.5rem;margin-bottom:.5rem;">
                <div>
                    <label style="font-size:.7rem;color:#475569;">Tipo</label>
                    <select id="renovar-cred-tipo" style="width:100%;padding:.35rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.78rem;">
                        <option value="C">Cédula</option>
                        <option value="N">NIT</option>
                        <option value="E">C. extranjería</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:.7rem;color:#475569;">Número de identificación</label>
                    <input type="text" id="renovar-cred-usuario" autocomplete="off" style="width:100%;padding:.35rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.78rem;">
                </div>
            </div>
            <label style="font-size:.7rem;color:#475569;">Contraseña del portal</label>
            <input type="password" id="renovar-cred-clave" autocomplete="new-password" style="width:100%;padding:.35rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.78rem;margin-bottom:.5rem;">
            <button class="btn-save" id="renovar-cred-btn" style="width:100%" onclick="guardarCredencialRenovar()">🔐 Guardar y buscar la póliza</button>
            <div id="renovar-cred-nota" style="font-size:.68rem;color:#b45309;margin-top:.4rem;line-height:1.35"></div>
        </div>

        <div class="form-group" id="renovar-fecha-group">
            <label>Fecha de inicio de la cobertura nueva *</label>
            <input type="date" id="renovar-fecha" required>
            <span style="font-size:.7rem;color:#94a3b8;">Se propone mañana, lo más pronto que cubre Sura: la cobertura se mueve a esa fecha, así que una posterior lo deja descubierto mientras tanto.</span>
        </div>

        <input type="hidden" id="renovar-contrato-id">
        <button class="btn-save" id="renovar-btn" onclick="confirmarRenovacion()">🔄 Renovar la cobertura</button>

        {{-- La cobertura del portal ya arranca en la fecha elegida: mover no
             haría nada. Lo que falta es poner esa fecha en BryNex, así que el
             aviso trae el botón que lo hace. --}}
        <div id="renovar-sin-cambio" style="display:none;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:.7rem .8rem;margin-top:.75rem;font-size:.76rem;color:#1e40af;line-height:1.5;">
            <div id="renovar-sin-cambio-texto" style="margin-bottom:.55rem;"></div>
            <button class="btn-accion btn-renovar" style="width:100%" onclick="registrarFechaDelPortal()">
                📅 Registrar esa fecha en BryNex
            </button>
        </div>

        {{-- Salida para las empresas cuya credencial del portal todavía no está
             cargada: sin ella no se puede tocar Sura, pero el semáforo sí se
             puede poner al día a mano, como se hacía antes. --}}
        <div style="margin-top:.85rem;border-top:1px solid #e2e8f0;padding-top:.6rem;">
            <button onclick="document.getElementById('renovar-manual').style.display='block';this.style.display='none'"
                    style="background:none;border:none;color:#64748b;font-size:.72rem;cursor:pointer;padding:0;text-decoration:underline;">
                La renovación ya se hizo en el portal: solo registrar la fecha
            </button>
            <div id="renovar-manual" style="display:none;margin-top:.5rem;">
                <div style="font-size:.72rem;color:#64748b;margin-bottom:.4rem;line-height:1.5;">
                    Esto no toca la ARL: solo mueve el semáforo con la fecha que escribas. Úsalo cuando el trámite se hizo por fuera de BryNex.
                </div>
                <button class="btn-accion btn-renovar" style="width:100%" onclick="guardarFechaManual()">📅 Registrar solo la fecha</button>
            </div>
        </div>
    </div>

    <div id="renovar-resultado" style="display:none;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:.75rem .9rem;font-size:.8rem;color:#166534;">
        <div id="renovar-resultado-texto"></div>
        <button class="btn-accion btn-certificado" id="renovar-cert-btn" style="width:100%;margin-top:.7rem;"
                onclick="descargarCertificado(this, renovarCtx)">
            <svg width="13" height="15" viewBox="0 0 12 14" fill="none" style="vertical-align:-2px" aria-hidden="true">
                <path d="M1 1.5A.5.5 0 0 1 1.5 1H7l4 4v7.5a.5.5 0 0 1-.5.5h-9a.5.5 0 0 1-.5-.5v-11Z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/>
                <path d="M7 1v4h4" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/>
                <path d="M6 7.6v3.2m0 0L4.7 9.6M6 10.8l1.3-1.2" stroke="currentColor" stroke-width="1.1" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            Descargar certificado y carné
        </button>
    </div>
</div>
</div>

{{-- ══ MODAL RETIRO ══ --}}
<div class="modal-bg" id="modalRetirar">
<div class="modal-box">
    <div class="modal-title">
        <span>❌ Retirar — <span id="retirar-nombre"></span></span>
        <button class="modal-close" onclick="cerrarModal('modalRetirar')">✕</button>
    </div>

    <div id="retirar-cargando" style="padding:1.5rem;text-align:center;color:#64748b;font-size:.82rem;">
        ⏳ Revisando el contrato...
    </div>

    <div id="retirar-contenido" style="display:none;">
        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:.65rem .8rem;margin-bottom:.75rem;font-size:.75rem;color:#991b1b;line-height:1.55;">
            @can('automatizar-arl')
            El sistema va a <strong>anular la cobertura en el portal de Sura</strong> y dejar el contrato
            <strong>retirado</strong>, con retiro informativo (0 días cotizados).<br>
            <span style="color:#7f1d1d;">Si Sura ya no deja anularla, se te preguntará antes de hacer nada más.</span>
            @else
            El sistema va a dejar el contrato <strong>retirado</strong>, con retiro informativo (0 días cotizados).<br>
            <span style="color:#7f1d1d;">La cobertura en el portal de Sura la cierras tú.</span>
            @endcan
        </div>

        <div id="retirar-periodo" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:.5rem .75rem;margin-bottom:.75rem;font-size:.75rem;color:#166534;"></div>

        {{-- La afiliación nunca se cobró: retirarlo no la borra, sigue pendiente en Cobros --}}
        <div id="retirar-afil-pendiente" style="display:none;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:.55rem .75rem;margin-bottom:.75rem;font-size:.75rem;color:#92400e;line-height:1.5;"></div>

        {{-- Sin clave del portal no se puede tocar Sura: se pide aquí mismo --}}
        <div id="retirar-credencial" style="display:none;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:.7rem .8rem;margin-bottom:.75rem;">
            <div style="font-size:.75rem;color:#1e40af;margin-bottom:.5rem;line-height:1.5;">
                Falta la clave del portal de Sura de esta empresa. Cárgala una vez y el retiro sigue.
            </div>
            <div style="display:grid;grid-template-columns:110px 1fr;gap:.5rem;margin-bottom:.5rem;">
                <div>
                    <label style="font-size:.7rem;color:#475569;">Tipo</label>
                    <select id="retirar-cred-tipo" style="width:100%;padding:.35rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.78rem;">
                        <option value="C">Cédula</option>
                        <option value="N">NIT</option>
                        <option value="E">C. extranjería</option>
                    </select>
                </div>
                <div>
                    <label style="font-size:.7rem;color:#475569;">Número de identificación</label>
                    <input type="text" id="retirar-cred-usuario" autocomplete="off" style="width:100%;padding:.35rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.78rem;">
                </div>
            </div>
            <label style="font-size:.7rem;color:#475569;">Contraseña del portal</label>
            <input type="password" id="retirar-cred-clave" autocomplete="new-password" style="width:100%;padding:.35rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.78rem;margin-bottom:.5rem;">
            <button class="btn-save" id="retirar-cred-btn" style="width:100%" onclick="guardarCredencialRetiro()">🔐 Guardar y buscar la póliza</button>
            <div id="retirar-cred-nota" style="font-size:.68rem;color:#b45309;margin-top:.4rem;line-height:1.35"></div>
        </div>

        <div class="form-group">
            <label>Motivo del retiro *</label>
            <select id="retirar-motivo" required></select>
        </div>

        <div class="form-group">
            <label>Observación</label>
            <textarea id="retirar-observacion" rows="2" placeholder="Opcional: por qué se retira" style="width:100%;padding:.4rem;border:1px solid #cbd5e1;border-radius:6px;font-size:.8rem;font-family:inherit;"></textarea>
        </div>

        <input type="hidden" id="retirar-contrato-id">
        <input type="hidden" id="retirar-mes-plano">
        <input type="hidden" id="retirar-anio-plano">
        <button class="btn-save" id="retirar-btn" style="background:#b91c1c" onclick="confirmarRetiro()">❌ {{ Gate::allows('automatizar-arl') ? 'Anular en Sura y retirar' : 'Retirar' }}</button>
    </div>

    <div id="retirar-resultado" style="display:none;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;padding:.75rem .9rem;font-size:.8rem;color:#166534;"></div>
</div>
</div>

{{-- ══ MODAL FACTURAR (iframe partial) ══ --}}
<div class="modal-bg" id="modalFacturar">
<div class="modal-box wide" style="max-width:96vw;width:96vw;padding:0;overflow:hidden;">
    <div style="background:#1e40af;color:#fff;padding:.75rem 1rem;display:flex;align-items:center;justify-content:space-between;">
        <strong style="font-size:.9rem;">💳 Facturar ARL — <span id="facturar-nombre"></span></strong>
        <button class="modal-close" onclick="cerrarModal('modalFacturar')" style="color:#bfdbfe;font-size:1.2rem;">✕</button>
    </div>
    <iframe id="facturar-iframe" src="" style="width:100%;height:780px;border:none;"></iframe>
</div>
</div>

@push('scripts')
<script>
const CSRF = document.querySelector('meta[name="csrf-token"]')?.content;
// Automatización de portales: usuarios BryNex o aliado autorizado por BryNex.
const PUEDE_AUTOMATIZAR = @json(Gate::allows('automatizar-arl'));
const RETIRAR_TEXTO = PUEDE_AUTOMATIZAR ? '❌ Anular en Sura y retirar' : '❌ Retirar';

/* ── Helpers modales ── */
function cerrarModal(id) {
    document.getElementById(id).classList.remove('open');
    const iframe = document.querySelector(`#${id} iframe`);
    if (iframe) iframe.src = '';

    // La renovación ya no recarga sola —así la fila no se mueve mientras se
    // baja el certificado—, así que la lista se pone al día al cerrar.
    if (id === 'modalRenovar' && renovarPendienteRecargar) {
        renovarPendienteRecargar = false;
        location.reload();
    }
}

/* ── Modal Renovar: el ciclo mensual contra ARL Sura ── */
let renovarCtx = null;
let renovarPrecheck = null;
let renovarPendienteRecargar = false;
let renovarBotonNormal = null;

// Los trámites abren un navegador dentro del servidor y tardan un buen rato:
// sin un contador a la vista el botón parece congelado.
function gaEsperar(btn, texto) {
    const desde = Date.now();
    const pintar = () => btn.textContent = `⏳ ${texto} ${Math.round((Date.now() - desde) / 1000)}s`;
    btn.disabled = true; pintar();
    const reloj = setInterval(pintar, 1000);
    return () => clearInterval(reloj);
}

async function gaPedir(url, opciones, limiteSeg) {
    const corte  = new AbortController();
    const alarma = setTimeout(() => corte.abort(), limiteSeg * 1000);
    try {
        const res = await fetch(url, { ...opciones, signal: corte.signal });
        return await res.json();
    } finally {
        clearTimeout(alarma);
    }
}

async function abrirRenovar(ctx) {
    renovarCtx = ctx;
    document.getElementById('renovar-contrato-id').value = ctx.id;
    document.getElementById('renovar-cargando').style.display  = 'block';
    document.getElementById('renovar-contenido').style.display = 'none';
    document.getElementById('renovar-resultado').style.display = 'none';
    document.getElementById('renovar-manual').style.display    = 'none';
    document.getElementById('modalRenovar').classList.add('open');

    let data;
    try {
        const res = await fetch(`/admin/gestion-arl/${ctx.id}/precheck`, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
        });
        data = await res.json();
    } catch (e) {
        document.getElementById('renovar-cargando').textContent = '⚠️ No se pudo revisar el contrato.';
        return;
    }

    renovarPrecheck = data;
    document.getElementById('renovar-cargando').style.display  = 'none';
    document.getElementById('renovar-contenido').style.display = 'block';
    document.getElementById('renovar-sin-cambio').style.display = 'none';

    const r = data.resumen || {};
    const linea = (etiqueta, valor) => valor
        ? `<div><span style="color:#64748b;">${etiqueta}:</span> <strong>${valor}</strong></div>`
        : '';

    document.getElementById('renovar-resumen').innerHTML =
        linea('Trabajador', `${r.trabajador} — ${r.documento}`) +
        linea('Empresa', `${r.razon_social} (póliza ${r.poliza ?? '—'})`) +
        linea('Tipo', `${r.tipo}${r.modalidad ? ' · ' + r.modalidad : ''}`) +
        linea('Seguridad social', `${r.eps ?? '—'} / ${r.afp ?? '—'}`) +
        linea('IBC', r.ibc ? '$' + Number(r.ibc).toLocaleString('es-CO') : null) +
        linea('Cargo', r.cargo) +
        linea('Riesgo', r.nivel_riesgo ? `${r.nivel_riesgo} · centro ${r.centro ?? '—'}${r.tasa ? ' · tasa ' + r.tasa : ''}` : null);

    // Qué va a pasar en el portal. Sura no deja mover la fecha de una cobertura
    // ya creada, así que renovar son dos trámites, y hay que decirlo antes.
    const cob   = data.cobertura_actual;
    const cred  = data.requiere_credencial;
    const falta = data.problemas || [];
    let bloquea = falta.length > 0;
    let aviso;

    const formCred = document.getElementById('renovar-credencial');
    formCred.style.display = cred ? 'block' : 'none';

    if (cred) {
        document.getElementById('renovar-cred-empresa').textContent = cred.razon_social ?? 'esta empresa';
        aviso = `🔒 <strong>${cred.razon_social ?? 'Esta empresa'}</strong> todavía no tiene cargada la contraseña del portal de Sura. ` +
                `Cárgala aquí abajo y la renovación queda habilitada; se guarda contra el NIT, así que sirve para todos los aliados.`;
        bloquea = true;
    } else if (cob && !cob.se_puede_anular) {
        aviso = `⛔ La cobertura arrancó el <strong>${cob.desde}</strong> y ya pasaron los 30 días que da Sura para anular. ` +
                `Esta cobertura ya no se puede reemplazar: hay que <strong>retirar</strong> al trabajador y afiliarlo de nuevo.`;
        bloquea = true;
    } else if (cob) {
        aviso = `El sistema va a <strong>mover la cobertura</strong> que hoy arranca el <strong>${cob.desde}</strong>` +
                (cob.confirmada_en_sura ? ` (confirmada en el portal${cob.centro ? ', centro ' + cob.centro : ''})` : '') +
                ` a la fecha que elijas. Es un solo trámite: no se anula ni se vuelve a afiliar.<br>` +
                `<span style="color:#78350f;">Después baja el certificado y el carné nuevos y actualiza la fecha ARL del contrato. ` +
                `Si Sura no deja moverla, te dice por qué y la cobertura queda como está.</span>`;
    } else {
        aviso = `En el portal de Sura este trabajador <strong>no tiene ninguna cobertura activa</strong>, así que solo se <strong>creará la afiliación nueva</strong>. Queda en el historial.`;
    }
    document.getElementById('renovar-aviso').innerHTML = aviso;

    const cajaProb = document.getElementById('renovar-problemas');
    if (falta.length) {
        document.getElementById('renovar-problemas-lista').innerHTML = falta.map(p => `<li>${p}</li>`).join('');
        cajaProb.style.display = 'block';
    } else {
        cajaProb.style.display = 'none';
    }

    document.getElementById('renovar-fecha').value = data.fecha_sugerida || '';

    // El estado que le corresponde al botón por el contrato en sí. Se guarda
    // porque el aviso de trámite nulo lo bloquea y, si el usuario cambia el
    // día, hay que devolverlo a esto y no a "habilitado" a secas: puede seguir
    // bloqueado por falta de datos o de credencial.
    renovarBotonNormal = {
        disabled: bloquea,
        texto: falta.length ? '🚫 Completa los datos primero'
             : cred         ? '🔒 Falta la contraseña del portal'
             : (cob && !cob.se_puede_anular) ? '⛔ Fuera del plazo para anular'
             : '🔄 Renovar la cobertura',
    };

    const btn = document.getElementById('renovar-btn');
    btn.disabled      = renovarBotonNormal.disabled;
    btn.style.opacity = renovarBotonNormal.disabled ? '.5' : '1';
    btn.textContent   = renovarBotonNormal.texto;

    // Se revisa al abrir y cada vez que se cambie el día: es la fecha elegida,
    // no la propuesta, la que decide si el trámite haría algo.
    revisarSiSobraElTramite();
    document.getElementById('renovar-fecha').onchange = revisarSiSobraElTramite;
}

/**
 * Avisa cuando la cobertura del portal ya arranca en la fecha elegida.
 *
 * Mover una cobertura al mismo día no cambia nada y Sura devuelve la pantalla
 * sin confirmar, así que el trámite parece fallar. Le pasa a todo el que ya se
 * renovó por el portal y quedó desactualizado en BryNex, que es justo lo que se
 * viene a arreglar aquí: por eso el aviso trae el botón que registra la fecha.
 */
function revisarSiSobraElTramite() {
    const caja  = document.getElementById('renovar-sin-cambio');
    const cob   = renovarPrecheck?.cobertura_actual;
    const fecha = document.getElementById('renovar-fecha').value;
    const btn   = document.getElementById('renovar-btn');

    // `desde` viene como d/m/Y y el input como Y-m-d.
    const [d, m, a] = (cob?.desde || '').split('/');
    const sobra = cob && fecha && `${a}-${m}-${d}` === fecha;

    caja.style.display = sobra ? 'block' : 'none';

    if (!sobra) {
        // Vuelve a como estaba, que no siempre es "habilitado".
        btn.disabled      = renovarBotonNormal?.disabled ?? false;
        btn.style.opacity = btn.disabled ? '.5' : '1';
        btn.textContent   = renovarBotonNormal?.texto ?? '🔄 Renovar la cobertura';
        return;
    }

    document.getElementById('renovar-sin-cambio-texto').innerHTML =
        `En el portal la cobertura <strong>ya arranca el ${cob.desde}</strong>, así que no hay nada que mover. ` +
        `Lo que falta es registrar esa fecha en BryNex.`;
    btn.disabled = true;
    btn.style.opacity = '.5';
    btn.textContent = '✓ La cobertura ya arranca ese día';
}

/** Registra en BryNex la fecha que el portal ya tiene, sin tocar Sura. */
function registrarFechaDelPortal() {
    const texto = document.getElementById('renovar-sin-cambio-texto').textContent;
    const visto = texto.match(/(\d{2})\/(\d{2})\/(\d{4})/);
    if (!visto) return;
    guardarFechaManual(`${visto[3]}-${visto[2]}-${visto[1]}`);
}

async function confirmarRenovacion() {
    const id    = document.getElementById('renovar-contrato-id').value;
    const fecha = document.getElementById('renovar-fecha').value;
    if (!fecha) { alert('Selecciona la fecha de inicio de la cobertura nueva.'); return; }

    const btn   = document.getElementById('renovar-btn');
    const parar = gaEsperar(btn, 'Renovando en Sura...');

    let data;
    try {
        data = await gaPedir(`/admin/gestion-arl/${id}/renovar-sura`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
            body: JSON.stringify({ fecha_inicio_cobertura: fecha }),
        }, 290);
    } catch (e) {
        // No se reintenta solo: si la anulación ya pasó, repetir crearía un lío
        // mayor que el que resuelve.
        data = { ok: false, mensaje: 'Se perdió la conexión con el servidor. Revisa en el portal de Sura cómo quedó la cobertura antes de volver a intentarlo.' };
    }

    parar();

    if (data.ok) {
        document.getElementById('renovar-contenido').style.display = 'none';
        const caja = document.getElementById('renovar-resultado');
        document.getElementById('renovar-resultado-texto').innerHTML = `✅ <strong>${data.mensaje}</strong><br>` +
            `Cobertura desde <strong>${data.fecha_display}</strong>` +
            (data.codigo_transaccion ? ` · transacción <strong>${data.codigo_transaccion}</strong>` : '') + `<br>` +
            `<span style="color:#475569;">El certificado y el carné al día quedaron archivados en los documentos del cliente, ` +
            `y la fecha ARL del contrato ya está actualizada.</span>` +
            (data.aviso ? `<br><span style="color:#b45309;">⚠️ ${data.aviso}</span>` : '');
        caja.style.display = 'block';

        // Sin recarga automática: al renovar, la fila cambia de sitio en la
        // lista —está ordenada por semáforo— y encontrar a la persona otra vez
        // para bajarle el certificado costaba más que el trámite. Se baja aquí
        // mismo, y la lista se refresca cuando se cierre la ventana.
        renovarPendienteRecargar = true;
        document.getElementById('renovar-cert-btn').click();
    } else if (data.sin_cambio) {
        // La cobertura ya arrancaba ese día. No es un fallo: se ofrece lo que
        // de verdad falta, que es poner la fecha del portal en BryNex.
        document.getElementById('renovar-sin-cambio-texto').innerHTML =
            `En el portal la cobertura <strong>ya arranca el ${data.fecha_display}</strong>, así que no hubo nada que mover. ` +
            `Lo que falta es registrar esa fecha en BryNex.`;
        document.getElementById('renovar-sin-cambio').style.display = 'block';
        btn.disabled = true; btn.style.opacity = '.5';
        btn.textContent = '✓ La cobertura ya arranca ese día';
    } else {
        btn.disabled = false; btn.textContent = '🔄 Reintentar';
        alert(data.mensaje || 'No se pudo renovar.');
    }
}

/**
 * Guarda la contraseña del portal de esa empresa y descubre su póliza.
 *
 * Abre un navegador dentro del servidor, así que tarda: por eso el contador.
 */
async function guardarCredencialRenovar() {
    const usuario = document.getElementById('renovar-cred-usuario').value.trim();
    const clave   = document.getElementById('renovar-cred-clave').value;
    if (!usuario || !clave) { alert('Ingresa el usuario y la contraseña del portal.'); return; }

    const btn   = document.getElementById('renovar-cred-btn');
    const nota  = document.getElementById('renovar-cred-nota');
    const parar = gaEsperar(btn, 'Entrando al portal de Sura...');
    nota.textContent = 'Abriendo el portal y leyendo la póliza. Suele tardar entre 1 y 2 minutos: no cierres esta ventana.';

    let data;
    try {
        data = await gaPedir(`/admin/gestion-arl/${renovarCtx.id}/credencial`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
            body: JSON.stringify({
                tipo_documento: document.getElementById('renovar-cred-tipo').value,
                usuario, contrasena: clave,
            }),
        }, 240);
    } catch (e) {
        // Consultar es inofensivo: solo lee lo que ya quedó guardado.
        let quedo = false;
        try {
            const r = await fetch(`/admin/gestion-arl/${renovarCtx.id}/precheck`, { headers: { 'Accept': 'application/json' } });
            quedo = !!(await r.json()).resumen?.poliza;
        } catch (e2) { /* sin conexión: se reporta abajo */ }
        data = quedo
            ? { ok: true, mensaje: 'La póliza quedó guardada. La conexión se cortó, pero el proceso sí terminó.' }
            : { ok: false, mensaje: 'Se perdió la conexión antes de terminar. Vuelve a intentarlo.' };
    }

    parar();
    btn.disabled = false; btn.textContent = '🔐 Guardar y buscar la póliza';
    nota.textContent = '';

    alert(data.mensaje || (data.ok ? 'Credencial guardada.' : 'No se pudo guardar la credencial.'));
    if (data.ok) abrirRenovar(renovarCtx); // vuelve a revisar, ya con póliza
}

/**
 * Baja del portal el certificado del momento y lo archiva en los documentos.
 *
 * El estado cambia solo con el tiempo: el certificado que se saca el mismo día
 * en que se mueve la cobertura sale como "POR INICIAR", y al llegar la fecha ya
 * aparece activo. Por eso el botón vive aparte de la renovación.
 */
async function descargarCertificado(btn, ctx) {
    const contenidoOriginal = btn.innerHTML;
    const parar = gaEsperar(btn, 'Bajando...');

    try {
        const res = await gaPedirArchivo(`/admin/gestion-arl/${ctx.id}/certificado`, 280);

        // Sin póliza no hay certificado que bajar, pero tampoco es un callejón
        // sin salida: se pide la clave del portal ahí mismo y la póliza sale de
        // ahí. El formulario ya vive en la ventana de renovación.
        if (res.requiereCredencial) {
            alert(res.error);
            abrirRenovar(ctx);
            return;
        }

        if (res.error) { alert(res.error); return; }

        // El navegador no puede abrir el disco `local` del servidor: el PDF
        // llega en la respuesta y se guarda desde aquí.
        const url = URL.createObjectURL(res.blob);
        const a = document.createElement('a');
        a.href = url; a.download = res.nombre || `certificado_arl_${ctx.id}.pdf`;
        document.body.appendChild(a); a.click(); a.remove();
        setTimeout(() => URL.revokeObjectURL(url), 4000);
    } finally {
        parar();
        btn.disabled = false; btn.innerHTML = contenidoOriginal;
    }
}

/** Como gaPedir, pero la respuesta es un PDF y no un JSON. */
async function gaPedirArchivo(url, limiteSeg) {
    const corte  = new AbortController();
    const alarma = setTimeout(() => corte.abort(), limiteSeg * 1000);
    try {
        const res = await fetch(url, { headers: { 'X-CSRF-TOKEN': CSRF }, signal: corte.signal });

        if (!res.ok) {
            const d = await res.json().catch(() => ({}));
            return {
                error: d.mensaje || 'No se pudo bajar el certificado del portal.',
                requiereCredencial: !!d.requiere_credencial,
            };
        }

        const cd = res.headers.get('content-disposition') || '';
        const m  = cd.match(/filename="?([^"';]+)"?/i);
        return { blob: await res.blob(), nombre: m ? m[1] : null };
    } catch (e) {
        return { error: 'Se perdió la conexión mientras se bajaba el certificado.' };
    } finally {
        clearTimeout(alarma);
    }
}

/** Respaldo: mueve el semáforo sin tocar la ARL, para trámites hechos por fuera. */
async function guardarFechaManual(fechaDada) {
    const id    = document.getElementById('renovar-contrato-id').value;
    const fecha = fechaDada || document.getElementById('renovar-fecha').value;
    if (!fecha) { alert('Selecciona la fecha de afiliación ARL.'); return; }
    if (!confirm('Esto NO toca la ARL: solo registra la fecha en BryNex. ¿Seguir?')) return;

    const res = await fetch(`/admin/gestion-arl/${id}/renovar`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
        body: JSON.stringify({ fecha_arl: fecha }),
    });
    const data = await res.json();

    if (data.ok) { cerrarModal('modalRenovar'); location.reload(); }
    else { alert(data.message || data.mensaje || 'Error al guardar.'); }
}

/* ── Modal Facturar (iframe hacia la vista de facturación individual o empresa) ── */
function abrirFacturar(ctx) {
    let url;
    if (ctx.empresa_id && parseInt(ctx.empresa_id) > 1) {
        document.getElementById('facturar-nombre').textContent = ctx.razon_social;
        url = `/admin/facturacion/empresa/${ctx.empresa_id}?iframe=1`;
    } else {
        document.getElementById('facturar-nombre').textContent = ctx.nombre;
        url = `/admin/contratos/${ctx.id}/edit?iframe=1&facturar=1`;
    }
    document.getElementById('facturar-iframe').src = url;
    document.getElementById('modalFacturar').classList.add('open');
}

/* ── Modal Retiro Informativo ── */
/* ── Retiro: anula en Sura y deja el contrato retirado ── */

async function abrirRetirar(id, nombre) {
    document.getElementById('retirar-nombre').textContent = nombre;
    document.getElementById('retirar-contrato-id').value  = id;
    document.getElementById('retirar-cargando').style.display   = 'block';
    document.getElementById('retirar-contenido').style.display  = 'none';
    document.getElementById('retirar-resultado').style.display  = 'none';
    document.getElementById('retirar-credencial').style.display = 'none';
    document.getElementById('retirar-afil-pendiente').style.display = 'none';
    document.getElementById('retirar-observacion').value = '';
    document.getElementById('modalRetirar').classList.add('open');

    let d;
    try {
        const r = await fetch(`/admin/gestion-arl/${id}/datos-retiro`, { headers: { 'Accept': 'application/json' } });
        d = await r.json();
    } catch (e) {
        document.getElementById('retirar-cargando').textContent = '⚠️ No se pudo revisar el contrato.';
        return;
    }

    document.getElementById('retirar-cargando').style.display  = 'none';
    document.getElementById('retirar-contenido').style.display = 'block';

    // El periodo no se elige: el retiro tiene que caer en el mes consecutivo al
    // último cotizado, y el contrato lo rechaza si no coincide.
    document.getElementById('retirar-mes-plano').value  = d.mes_plano;
    document.getElementById('retirar-anio-plano').value = d.anio_plano;
    document.getElementById('retirar-periodo').innerHTML =
        `Se registrará en el plano de <strong>${d.periodo}</strong>, con <strong>0 días</strong> cotizados.` +
        (d.fecha_arl ? `<br>Cobertura ARL vigente desde <strong>${d.fecha_arl}</strong>.` : '');

    document.getElementById('retirar-motivo').innerHTML =
        '<option value="">-- Seleccione --</option>' +
        (d.motivos || []).map(m => `<option value="${m.id}">${m.nombre}</option>`).join('');

    // Afiliación sin cobrar: se avisa salvo que el motivo sea un error, que no se cobra.
    const afil = d.afiliacion_pendiente;
    const cajaAfil = document.getElementById('retirar-afil-pendiente');
    const motivosError = (d.motivos_error || []).map(String);
    const pintarAfil = () => {
        const esError = motivosError.includes(document.getElementById('retirar-motivo').value);
        cajaAfil.style.display = afil && !esError ? 'block' : 'none';
    };
    cajaAfil.innerHTML = afil
        ? `⚠️ La afiliación de <strong>${afil.periodo}</strong> (<strong>$${Number(afil.valor).toLocaleString('es-CO')}</strong>) nunca se cobró. ` +
          `Retirarlo no la borra: sigue pendiente en Cobros hasta que se facture. ` +
          `<a href="${afil.url}" target="_blank" style="color:#92400e;font-weight:700;">Facturarla</a>` +
          `<br><span style="opacity:.8;">Si fue un error o una prueba, usa el motivo «Error - Sin Afiliación» y no se cobra.</span>`
        : '';
    document.getElementById('retirar-motivo').onchange = pintarAfil;
    pintarAfil();

    const btn = document.getElementById('retirar-btn');
    btn.disabled = false; btn.textContent = RETIRAR_TEXTO;
}

async function confirmarRetiro() {
    const id     = document.getElementById('retirar-contrato-id').value;
    const motivo = document.getElementById('retirar-motivo').value;
    if (!motivo) { alert('Selecciona el motivo del retiro.'); return; }

    const btn   = document.getElementById('retirar-btn');
    const parar = gaEsperar(btn, PUEDE_AUTOMATIZAR ? 'Anulando en Sura...' : 'Retirando...');

    try {
        // Sin la automatización autorizada, el portal lo maneja la persona: solo se retira en BryNex.
        let r = PUEDE_AUTOMATIZAR ? await pedirAnulacionSura(id, false) : { ok: true, mensaje: 'La cobertura en ARL Sura no se tocó: ciérrala en el portal.' };

        // Falta la clave del portal: se pide aquí y el usuario vuelve a darle.
        if (r.requiere_credencial) {
            document.getElementById('retirar-credencial').style.display = 'block';
            document.getElementById('retirar-cred-usuario').focus();
            alert(r.mensaje);
            return;
        }

        // Fuera del plazo de anulación. La decisión es del usuario: retirar deja
        // constancia de que la persona estuvo afiliada, anular la borraba.
        if (!r.ok && r.puede_retirar) {
            const seguir = confirm(
                r.mensaje + '\n\n¿Quieres hacer el RETIRO en Sura en su lugar? ' +
                'El retiro cierra la cobertura con fecha y deja constancia de que estuvo afiliado.'
            );
            if (!seguir) return;

            r = await pedirAnulacionSura(id, true);
        }

        if (!r.ok) { alert(r.mensaje || 'No se pudo cerrar la cobertura en Sura.'); return; }

        // Sura ya está resuelto: ahora el retiro de siempre, que es quien sabe
        // de planillas, planos y facturas.
        btn.textContent = '⏳ Marcando el retiro...';
        const marcado = await marcarRetiroInformativo(id, motivo);

        if (!marcado.ok) { alert(marcado.mensaje || 'La cobertura se cerró en Sura, pero el contrato no quedó retirado.'); return; }

        document.getElementById('retirar-contenido').style.display = 'none';
        const caja = document.getElementById('retirar-resultado');
        caja.innerHTML = `✅ <strong>${marcado.mensaje}</strong><br>` +
            `<span style="color:#475569;">${r.mensaje}</span>`;
        caja.style.display = 'block';
        setTimeout(() => location.reload(), 3000);
    } finally {
        parar();
        btn.disabled = false;
        if (btn.textContent.startsWith('⏳')) btn.textContent = RETIRAR_TEXTO;
    }
}

/** Cierra la cobertura en el portal. Con `retirar` en true hace retiro, no anulación. */
async function pedirAnulacionSura(id, retirar) {
    try {
        return await gaPedir(`/admin/gestion-arl/${id}/anular-sura`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
            body: JSON.stringify({ retirar }),
        }, 290);
    } catch (e) {
        return { ok: false, mensaje: 'Se perdió la conexión con el servidor. Revisa en el portal cómo quedó la cobertura antes de reintentar.' };
    }
}

/** Marca el contrato como retirado con el retiro de siempre, en modo informativo. */
async function marcarRetiroInformativo(id, motivo) {
    const hoy = new Date();
    const fecha = `${hoy.getFullYear()}-${String(hoy.getMonth() + 1).padStart(2, '0')}-${String(hoy.getDate()).padStart(2, '0')}`;

    try {
        return await gaPedir(`/admin/contratos/${id}/retirar`, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
            body: JSON.stringify({
                motivo_retiro_id: motivo,
                tipo_retiro: 'informativo',
                fecha_retiro: fecha,
                num_dias: 0,
                mes_plano: document.getElementById('retirar-mes-plano').value,
                anio_plano: document.getElementById('retirar-anio-plano').value,
                observacion: document.getElementById('retirar-observacion').value || null,
            }),
        }, 120);
    } catch (e) {
        return { ok: false, mensaje: 'La cobertura se cerró en Sura, pero se perdió la conexión al marcar el contrato.' };
    }
}

/** Carga la clave del portal sin salir del retiro. */
async function guardarCredencialRetiro() {
    const id      = document.getElementById('retirar-contrato-id').value;
    const usuario = document.getElementById('retirar-cred-usuario').value.trim();
    const clave   = document.getElementById('retirar-cred-clave').value;
    if (!usuario || !clave) { alert('Ingresa el usuario y la contraseña del portal.'); return; }

    const btn   = document.getElementById('retirar-cred-btn');
    const nota  = document.getElementById('retirar-cred-nota');
    const parar = gaEsperar(btn, 'Entrando al portal de Sura...');
    nota.textContent = 'Abriendo el portal y leyendo la póliza. Suele tardar entre 1 y 2 minutos: no cierres esta ventana.';

    let data;
    try {
        data = await gaPedir(`/admin/gestion-arl/${id}/credencial`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
            body: JSON.stringify({
                tipo_documento: document.getElementById('retirar-cred-tipo').value,
                usuario, contrasena: clave,
            }),
        }, 240);
    } catch (e) {
        data = { ok: false, mensaje: 'Se perdió la conexión antes de terminar. Vuelve a intentarlo.' };
    }

    parar();
    btn.disabled = false; btn.textContent = '🔐 Guardar y buscar la póliza';
    nota.textContent = '';
    alert(data.mensaje || (data.ok ? 'Credencial guardada.' : 'No se pudo guardar la credencial.'));

    if (data.ok) document.getElementById('retirar-credencial').style.display = 'none';
}

/* ── Cerrar al click fuera ── */
document.querySelectorAll('.modal-bg').forEach(bg => {
    bg.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('open');
            const iframe = this.querySelector('iframe');
            if (iframe) iframe.src = '';
        }
    });
});
</script>
@endpush
@include('admin.partials._modal_claves_globales')

@endsection
