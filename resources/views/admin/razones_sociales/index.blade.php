@extends('layouts.app')
@section('modulo', 'Razones Sociales')

@section('contenido')
<style>
.rs-header{background:linear-gradient(135deg,#0f172a,#1e3a5f);border-radius:14px;padding:1rem 1.4rem;margin-bottom:1rem;color:#fff;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.5rem}
.rs-h-title{font-size:1.05rem;font-weight:800}
.rs-h-sub{font-size:.72rem;color:#94a3b8;margin-top:.2rem}
.btn-nuevo{padding:.45rem 1.1rem;background:#2563eb;color:#fff;border:none;border-radius:8px;font-size:.82rem;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:.35rem;transition:background .15s}
.btn-nuevo:hover{background:#1d4ed8}
.tbl-wrap{background:#fff;border-radius:12px;border:1px solid #e2e8f0;overflow:hidden}
.tbl{width:100%;border-collapse:collapse;font-size:.8rem}
.tbl th{background:#f8fafc;color:#64748b;font-size:.62rem;text-transform:uppercase;letter-spacing:.05em;padding:.5rem .8rem;text-align:left;border-bottom:2px solid #e2e8f0;white-space:nowrap}
.tbl td{padding:.45rem .8rem;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.tbl tr:last-child td{border-bottom:none}
.tbl tr:hover td{background:#f8fafc}
.badge-activa{background:#dcfce7;color:#15803d;font-size:.6rem;font-weight:700;padding:.1rem .45rem;border-radius:20px;white-space:nowrap}
.badge-inactiva{background:#fee2e2;color:#dc2626;font-size:.6rem;font-weight:700;padding:.1rem .45rem;border-radius:20px;white-space:nowrap}
.badge-indep{background:#ede9fe;color:#7c3aed;font-size:.6rem;font-weight:700;padding:.1rem .45rem;border-radius:20px;white-space:nowrap}
.btn-edit{padding:.28rem .65rem;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:6px;font-size:.72rem;font-weight:600;color:#334155;text-decoration:none;cursor:pointer;transition:background .1s}
.btn-edit:hover{background:#e2e8f0}
.btn-del{padding:.28rem .65rem;background:#fee2e2;border:1px solid #fca5a5;border-radius:6px;font-size:.72rem;font-weight:600;color:#dc2626;cursor:pointer}
.btn-del:hover{background:#fecaca}
.empty-state{padding:2.5rem;text-align:center;color:#94a3b8;font-size:.85rem}
.nit-col{font-family:monospace;font-size:.75rem;color:#64748b}
.num-col{font-size:.7rem;color:#94a3b8;font-weight:600;text-align:center;width:32px}
.badge-activos{background:#dbeafe;color:#1d4ed8;font-size:.65rem;font-weight:700;padding:.15rem .5rem;border-radius:20px;white-space:nowrap}
.badge-sin-activos{background:#f1f5f9;color:#94a3b8;font-size:.65rem;font-weight:600;padding:.15rem .5rem;border-radius:20px;white-space:nowrap}
.badge-antiguedad{background:#f0fdf4;color:#15803d;font-size:.65rem;font-weight:600;padding:.15rem .5rem;border-radius:20px;white-space:nowrap}
.badge-sin-fecha{background:#fef9c3;color:#a16207;font-size:.65rem;font-weight:600;padding:.15rem .5rem;border-radius:20px;white-space:nowrap}
</style>

{{-- Header --}}
<div class="rs-header">
    <div>
        @can('entrar-configuracion')
        <a href="{{ route('admin.configuracion.hub') }}" style="color:#94a3b8;font-size:.73rem;text-decoration:none">← Configuración</a>
        @endcan
        <div class="rs-h-title">🏭 Razones Sociales</div>
        <div class="rs-h-sub">Empresas a través de las cuales se afilian trabajadores al sistema de seguridad social</div>
    </div>
    <div style="display:flex;gap:.5rem;align-items:center">
        @can('traslados_rs.ver')
        <a href="{{ route('admin.traslados.index') }}"
           class="btn-nuevo"
           style="background:#7c3aed"
           title="Trasladar personas de una empresa a otra">
            🔄 Traslado RS
        </a>
        @endcan
        @can('razones_sociales.gestionar')
        <a href="{{ route('admin.configuracion.razones.create') }}" class="btn-nuevo">+ Nueva Razón Social</a>
        @endcan
    </div>
</div>

{{-- Alertas --}}
@if(session('success'))
<div style="background:#dcfce7;border:1px solid #86efac;border-radius:8px;padding:.55rem 1rem;margin-bottom:.75rem;font-size:.82rem;color:#166534">✓ {{ session('success') }}</div>
@endif
@if(session('error'))
<div style="background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:.55rem 1rem;margin-bottom:.75rem;font-size:.82rem;color:#dc2626">⚠️ {{ session('error') }}</div>
@endif

{{-- Conteo --}}
<div style="font-size:.78rem;color:#64748b;margin-bottom:.5rem;display:flex;align-items:center;gap:.5rem">
    <span>
        <strong style="color:#0f172a">{{ number_format($razones->total()) }}</strong> razón(es) social(es)
        @if($buscar || $estado !== 'Todas') <span style="background:#fef9c3;color:#a16207;padding:.1rem .4rem;border-radius:6px;font-size:.7rem;font-weight:700">· Filtros activos</span> @endif
    </span>
    @if($buscar || $estado !== 'Activa')
    <a href="{{ route('admin.configuracion.razones.index') }}" style="font-size:.72rem;color:#94a3b8;text-decoration:none;margin-left:auto">✕ Limpiar filtros</a>
    @endif
</div>

{{-- Filtros --}}
<form method="GET" action="{{ route('admin.configuracion.razones.index') }}"
      style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:.7rem 1rem;margin-bottom:.85rem;display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;box-shadow:0 1px 6px rgba(0,0,0,.04)">

    {{-- Buscador por nombre --}}
    <div style="position:relative;flex:1;min-width:200px;display:flex;align-items:center">
        <svg style="position:absolute;left:.75rem;pointer-events:none" xmlns="http://www.w3.org/2000/svg" width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="#94a3b8" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
        <input type="text" name="buscar"
               value="{{ $buscar }}"
               placeholder="Buscar por nombre o NIT..."
               style="width:100%;padding:.48rem .9rem .48rem 2.2rem;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.85rem;color:#0f172a;background:#f8fafc;transition:border-color .2s;box-sizing:border-box"
               onfocus="this.style.borderColor='#3b82f6';this.style.background='#fff'"
               onblur="this.style.borderColor='#e2e8f0';this.style.background='#f8fafc'">
    </div>

    {{-- Filtro estado --}}
    <div style="display:flex;align-items:center;gap:.3rem">
        @foreach(['Activa' => ['🟢','#dcfce7','#15803d'], 'Inactiva' => ['🔴','#fee2e2','#991b1b'], 'Todas' => ['⚪','#f1f5f9','#334155']] as $opt => [$icon, $bg, $fg])
        <a href="{{ route('admin.configuracion.razones.index', array_filter(['buscar' => $buscar, 'estado' => $opt])) }}"
           style="padding:.38rem .75rem;border-radius:8px;font-size:.78rem;font-weight:600;text-decoration:none;white-space:nowrap;border:1.5px solid {{ $estado === $opt ? $fg : '#e2e8f0' }};background:{{ $estado === $opt ? $bg : '#fff' }};color:{{ $estado === $opt ? $fg : '#64748b' }};transition:all .15s">
            {{ $icon }} {{ $opt }}
        </a>
        @endforeach
    </div>

    {{-- Botón Buscar --}}
    <button type="submit"
            style="padding:.48rem 1.1rem;background:linear-gradient(135deg,#2563eb,#1d4ed8);border:none;border-radius:8px;color:#fff;font-size:.85rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:.35rem;white-space:nowrap;box-shadow:0 2px 8px rgba(37,99,235,.3)">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
        Buscar
    </button>
</form>

{{-- Tabla --}}
<div class="tbl-wrap">
    <table class="tbl">
        <thead>
            <tr>
                <th class="num-col">#</th>
                <th>ID</th>
                <th>NIT</th>
                <th>Razón Social</th>
                <th>ARL</th>
                <th>Caja</th>
                <th>Estado</th>
                <th>Tipo</th>
                <th style="text-align:center">Activos</th>
                <th>Antigüedad</th>
                <th style="text-align:right">Acciones</th>
            </tr>
        </thead>
        <tbody>
            @forelse($razones as $rs)
            @php
                $rowNum = ($razones->currentPage() - 1) * $razones->perPage() + $loop->iteration;

                // Formatear NIT con DV
                $nitFormateado = null;
                if (!is_null($rs->nit) && $rs->nit !== '') {
                    $nitStr = number_format((int)$rs->nit, 0, ',', '.');
                    $nitFormateado = $nitStr . ((!is_null($rs->dv) && $rs->dv !== '') ? '-' . $rs->dv : '');
                }

                // Calcular antigüedad desde fecha_constitucion
                $antiguedad = null;
                if (!empty($rs->fecha_constitucion)) {
                    $fechaConst = \Carbon\Carbon::parse($rs->fecha_constitucion);
                    $diff = $fechaConst->diff(\Carbon\Carbon::now());
                    $partes = [];
                    if ($diff->y > 0) $partes[] = $diff->y . ' año' . ($diff->y !== 1 ? 's' : '');
                    if ($diff->m > 0) $partes[] = $diff->m . ' mes' . ($diff->m !== 1 ? 'es' : '');
                    if (empty($partes)) $partes[] = $diff->d . ' día' . ($diff->d !== 1 ? 's' : '');
                    $antiguedad = implode(', ', $partes);
                }
            @endphp
            <tr>
                <td class="num-col" style="color:#94a3b8;font-size:.7rem">{{ $rowNum }}</td>
                <td class="nit-col">{{ $rs->id }}</td>
                <td class="nit-col">{{ $nitFormateado ?? '—' }}</td>
                <td style="font-weight:600;color:#1e293b">{{ $rs->razon_social }}</td>
                <td style="font-size:.75rem;color:#475569">{{ $rs->arl_nombre ?? '—' }}</td>
                <td style="font-size:.75rem;color:#475569">
                    {{ $rs->caja_nombre ?? '—' }}
                    @if($rs->cajas_extra > 0)
                    <span title="Tiene caja en {{ $rs->cajas_extra }} departamento{{ $rs->cajas_extra == 1 ? '' : 's' }} más"
                          style="font-size:.62rem;font-weight:700;color:#1d4ed8;background:#eff6ff;border-radius:6px;padding:.05rem .35rem;white-space:nowrap">+{{ $rs->cajas_extra }} dpto{{ $rs->cajas_extra == 1 ? '' : 's' }}</span>
                    @endif
                </td>
                <td>
                    @can('razones_sociales.gestionar')
                    <form method="POST" action="{{ route('admin.configuracion.razones.estado', $rs->id) }}" style="display:inline">
                        @csrf @method('PATCH')
                        <button type="submit" class="{{ $rs->estado === 'Activa' ? 'badge-activa' : 'badge-inactiva' }}" style="border:none;cursor:pointer;font-family:inherit">
                            {{ $rs->estado ?? 'Activa' }}
                        </button>
                    </form>
                    @else
                    <span class="{{ $rs->estado === 'Activa' ? 'badge-activa' : 'badge-inactiva' }}">{{ $rs->estado ?? 'Activa' }}</span>
                    @endcan
                </td>
                <td>
                    @if($rs->es_independiente)
                    <span class="badge-indep">Independiente</span>
                    @else
                    <span style="font-size:.7rem;color:#94a3b8">Normal</span>
                    @endif
                </td>
                <td style="text-align:center">
                    @if($rs->personas_activas > 0)
                    <span class="badge-activos">{{ $rs->personas_activas }} 👤</span>
                    @else
                    <span class="badge-sin-activos">0</span>
                    @endif
                </td>
                <td>
                    @if($antiguedad)
                    <span class="badge-antiguedad">{{ $antiguedad }}</span>
                    @else
                    <span class="badge-sin-fecha">Sin fecha</span>
                    @endif
                </td>
                <td style="text-align:right;white-space:nowrap">
                    {{-- Quien edita la razón social tiene los documentos en la
                         pestaña Archivos de la ficha; quien solo sube documentos,
                         en su página aparte. --}}
                    @can('razones_sociales.gestionar')
                    <a href="{{ route('admin.configuracion.razones.edit', $rs->id) }}#cardDocumentos" class="btn-edit" title="Ver / Cargar documentos" style="margin-right: 4px">📁 Docs</a>
                    <a href="{{ route('admin.configuracion.razones.edit', $rs->id) }}" class="btn-edit">✏️ Editar</a>
                    <button type="button" class="btn-del"
                            onclick="abrirModalGestion({{ $rs->id }}, '{{ addslashes($rs->razon_social) }}')"
                            title="Gestionar / Inactivar / Eliminar">
                        🗑
                    </button>
                    @elsecan('razones_sociales.documentos')
                    <a href="{{ route('admin.configuracion.razones.documentos.index', $rs->id) }}" class="btn-edit" title="Ver / Cargar documentos">📁 Docs</a>
                    @endcan
                </td>
            </tr>
            @empty
            <tr><td colspan="11"><div class="empty-state">🏭 No hay razones sociales. <a href="{{ route('admin.configuracion.razones.create') }}">Crear la primera</a></div></td></tr>
            @endforelse
        </tbody>
    </table>
</div>

{{-- ══ PAGINACIÓN ══════════════════════════════════════════════ --}}
@if($razones->hasPages())
<div class="rs-pagination">
    <span class="rs-page-info">
        Mostrando <strong>{{ $razones->firstItem() }}</strong> — <strong>{{ $razones->lastItem() }}</strong>
        de <strong>{{ number_format($razones->total()) }}</strong> razones sociales
    </span>
    <div class="rs-page-controls">
        {{-- Anterior --}}
        @if($razones->onFirstPage())
            <span class="rs-page-btn rs-page-btn--disabled">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                Anterior
            </span>
        @else
            <a href="{{ $razones->appends(['buscar' => $buscar])->previousPageUrl() }}" class="rs-page-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                Anterior
            </a>
        @endif

        {{-- Números de página --}}
        @php
            $currentPage = $razones->currentPage();
            $lastPage    = $razones->lastPage();
            $start = max(1, $currentPage - 2);
            $end   = min($lastPage, $currentPage + 2);
        @endphp

        @if($start > 1)
            <a href="{{ $razones->appends(['buscar' => $buscar])->url(1) }}" class="rs-page-num">1</a>
            @if($start > 2)<span class="rs-page-dots">…</span>@endif
        @endif

        @for($p = $start; $p <= $end; $p++)
            @if($p === $currentPage)
                <span class="rs-page-num rs-page-num--active">{{ $p }}</span>
            @else
                <a href="{{ $razones->appends(['buscar' => $buscar])->url($p) }}" class="rs-page-num">{{ $p }}</a>
            @endif
        @endfor

        @if($end < $lastPage)
            @if($end < $lastPage - 1)<span class="rs-page-dots">…</span>@endif
            <a href="{{ $razones->appends(['buscar' => $buscar])->url($lastPage) }}" class="rs-page-num">{{ $lastPage }}</a>
        @endif

        {{-- Siguiente --}}
        @if($razones->hasMorePages())
            <a href="{{ $razones->appends(['buscar' => $buscar])->nextPageUrl() }}" class="rs-page-btn">
                Siguiente
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
            </a>
        @else
            <span class="rs-page-btn rs-page-btn--disabled">
                Siguiente
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
            </span>
        @endif
    </div>
</div>
@endif

<style>
.rs-pagination {
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: .75rem;
    background: #fff; border: 1px solid #e2e8f0;
    border-radius: 12px; padding: .75rem 1.1rem;
    box-shadow: 0 1px 6px rgba(0,0,0,.04);
    margin-top: .75rem;
}
.rs-page-info { font-size: .8rem; color: #64748b; }
.rs-page-info strong { color: #0f172a; font-weight: 600; }
.rs-page-controls { display: flex; align-items: center; gap: .3rem; flex-wrap: wrap; }
.rs-page-btn {
    display: inline-flex; align-items: center; gap: .3rem;
    padding: .38rem .75rem; border-radius: 8px;
    border: 1.5px solid #e2e8f0; background: #fff;
    color: #334155; font-size: .8rem; font-weight: 600;
    text-decoration: none; transition: all .15s; white-space: nowrap;
}
.rs-page-btn:hover { background: #eff6ff; border-color: #93c5fd; color: #1d4ed8; }
.rs-page-btn--disabled {
    display: inline-flex; align-items: center; gap: .3rem;
    padding: .38rem .75rem; border-radius: 8px;
    border: 1.5px solid #f1f5f9; background: #f8fafc;
    color: #cbd5e1; font-size: .8rem; font-weight: 600;
    white-space: nowrap; cursor: default;
}
.rs-page-num {
    width: 34px; height: 34px;
    display: inline-flex; align-items: center; justify-content: center;
    border-radius: 8px; border: 1.5px solid #e2e8f0;
    background: #fff; color: #334155; font-size: .8rem; font-weight: 600;
    text-decoration: none; transition: all .15s;
}
.rs-page-num:hover { background: #eff6ff; border-color: #93c5fd; color: #1d4ed8; }
.rs-page-num--active {
    width: 34px; height: 34px;
    display: inline-flex; align-items: center; justify-content: center;
    border-radius: 8px; font-size: .8rem; font-weight: 700;
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: #fff; border: none;
    box-shadow: 0 2px 8px rgba(37,99,235,.35);
}
.rs-page-dots { color: #94a3b8; font-size: .8rem; padding: 0 .1rem; }
@media (max-width: 640px) {
    .rs-pagination { flex-direction: column; align-items: flex-start; }
    .rs-page-info { display: none; }
}
</style>

@endsection

{{-- ══ MODAL GESTIÓN RS ══════════════════════════════════════════ --}}
<div id="rsGestionModal" style="display:none;position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.5);backdrop-filter:blur(4px);align-items:center;justify-content:center">
    <div style="background:#fff;border-radius:16px;width:100%;max-width:440px;margin:1rem;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden">

        {{-- Header --}}
        <div id="rsModal-header" style="padding:1.2rem 1.4rem .8rem;border-bottom:1px solid #f1f5f9;display:flex;justify-content:space-between;align-items:flex-start">
            <div>
                <div style="font-size:.65rem;color:#94a3b8;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.2rem">Gestión</div>
                <div id="rsModal-nombre" style="font-size:.95rem;font-weight:800;color:#0f172a">Razón Social</div>
            </div>
            <button onclick="cerrarModalGestion()" style="border:none;background:none;font-size:1.2rem;cursor:pointer;color:#94a3b8;line-height:1">&#215;</button>
        </div>

        {{-- Cuerpo --}}
        <div id="rsModal-body" style="padding:1.2rem 1.4rem">

            {{-- Loading --}}
            <div id="rsModal-loading" style="text-align:center;padding:2rem;color:#64748b;font-size:.85rem">
                <div style="font-size:1.5rem;margin-bottom:.5rem">⏳</div>
                Verificando contratos...
            </div>

            {{-- Error: tiene vigentes --}}
            <div id="rsModal-bloqueado" style="display:none">
                <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:10px;padding:1rem;margin-bottom:1rem">
                    <div style="font-size:.9rem;font-weight:700;color:#dc2626;margin-bottom:.4rem">⚠️ Tiene afiliados activos</div>
                    <div style="font-size:.8rem;color:#b91c1c" id="rsModal-msg-vigentes"></div>
                </div>
                <p style="font-size:.8rem;color:#64748b">Para inactivar esta razón social primero debe retirar o trasladar todos los contratos vigentes.</p>
            </div>

            {{-- Solo inactivar (tuvo contratos pero no vigentes) --}}
            <div id="rsModal-solo-inactivar" style="display:none">
                <div style="background:#fef9c3;border:1px solid #fde68a;border-radius:10px;padding:1rem;margin-bottom:1rem">
                    <div style="font-size:.85rem;font-weight:600;color:#92400e">📄 Ha tenido contratos históricos</div>
                    <div style="font-size:.78rem;color:#78350f;margin-top:.3rem">Esta razón social ha tenido contratos vinculados. Solo puede <strong>inactivarse</strong>, no eliminarse.</div>
                </div>
                <form id="rsForm-inactivar" method="POST" action="">
                    @csrf @method('PATCH')
                    <button type="submit" style="width:100%;padding:.65rem;background:linear-gradient(135deg,#f59e0b,#d97706);border:none;border-radius:10px;color:#fff;font-size:.9rem;font-weight:700;cursor:pointer">
                        🔒 Inactivar Razón Social
                    </button>
                </form>
            </div>

            {{-- Sin contratos: inactivar O eliminar --}}
            <div id="rsModal-sin-contratos" style="display:none">
                <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:1rem;margin-bottom:1.2rem">
                    <div style="font-size:.85rem;font-weight:600;color:#15803d">✅ Sin contratos registrados</div>
                    <div style="font-size:.78rem;color:#166534;margin-top:.3rem">Esta razón social nunca ha tenido contratos. Puede inactivarla o eliminarla permanentemente.</div>
                </div>

                <div style="display:flex;flex-direction:column;gap:.75rem">
                    <form id="rsForm-inactivar2" method="POST" action="">
                        @csrf @method('PATCH')
                        <button type="submit" style="width:100%;padding:.6rem;background:#f1f5f9;border:1.5px solid #cbd5e1;border-radius:10px;color:#334155;font-size:.85rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:.4rem">
                            🔒 Inactivar Razón Social
                        </button>
                    </form>

                    <div style="text-align:center;font-size:.72rem;color:#94a3b8">o</div>

                    <form id="rsForm-eliminar" method="POST" action="" onsubmit="return confirmarEliminacion()">
                        @csrf @method('DELETE')
                        <button type="submit" style="width:100%;padding:.6rem;background:linear-gradient(135deg,#ef4444,#dc2626);border:none;border-radius:10px;color:#fff;font-size:.85rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:.4rem">
                            🗑️ Eliminar definitivamente
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let rsModalNombre = '';

function abrirModalGestion(id, nombre) {
    rsModalNombre = nombre;
    document.getElementById('rsModal-nombre').textContent = nombre;

    // Reset estados
    document.getElementById('rsModal-loading').style.display = 'block';
    document.getElementById('rsModal-bloqueado').style.display     = 'none';
    document.getElementById('rsModal-solo-inactivar').style.display = 'none';
    document.getElementById('rsModal-sin-contratos').style.display  = 'none';

    // Mostrar modal
    const modal = document.getElementById('rsGestionModal');
    modal.style.display = 'flex';

    // Consultar API
    fetch(`/admin/configuracion/razones-sociales/${id}/estado-contratos`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
    .then(r => r.json())
    .then(data => {
        document.getElementById('rsModal-loading').style.display = 'none';

        const urlInactivar = `/admin/configuracion/razones-sociales/${id}/inactivar`;
        const urlEliminar  = `/admin/configuracion/razones-sociales/${id}`;

        if (data.vigentes > 0) {
            // Bloqueado: tiene vigentes
            document.getElementById('rsModal-msg-vigentes').textContent =
                `Tiene ${data.vigentes} contrato(s) vigente(s). Retire o traslade los afiliados antes de continuar.`;
            document.getElementById('rsModal-bloqueado').style.display = 'block';

        } else if (!data.puede_eliminar) {
            // Solo inactivar (tuvo contratos en el pasado)
            document.getElementById('rsForm-inactivar').action = urlInactivar;
            document.getElementById('rsModal-solo-inactivar').style.display = 'block';

        } else {
            // Sin contratos: puede inactivar O eliminar
            document.getElementById('rsForm-inactivar2').action = urlInactivar;
            document.getElementById('rsForm-eliminar').action   = urlEliminar;
            document.getElementById('rsModal-sin-contratos').style.display = 'block';
        }
    })
    .catch(() => {
        document.getElementById('rsModal-loading').style.display = 'none';
        document.getElementById('rsModal-loading').innerHTML = '⚠️ Error al verificar el estado. Inténtalo de nuevo.';
        document.getElementById('rsModal-loading').style.display = 'block';
    });
}

function cerrarModalGestion() {
    document.getElementById('rsGestionModal').style.display = 'none';
}

function confirmarEliminacion() {
    return confirm(`¿Eliminar definitivamente «${rsModalNombre}»?\n\nEsta acción no se puede deshacer.`);
}

// Cerrar al hacer clic fuera del modal
document.getElementById('rsGestionModal').addEventListener('click', function(e) {
    if (e.target === this) cerrarModalGestion();
});
</script>
