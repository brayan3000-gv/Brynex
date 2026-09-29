@extends('layouts.app')
@section('titulo','Incapacidades')
@section('modulo','Módulo de Incapacidades')

@push('styles')
<style>
:root{--v:#10b981;--a:#f59e0b;--r:#ef4444;--g:#6b7280;}
.page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.2rem;flex-wrap:wrap;gap:.6rem;}
.page-header h1{font-size:1.35rem;font-weight:700;color:#1e293b;}
.kpi-bar{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.75rem;margin-bottom:1.2rem;}
.kpi{background:#fff;border-radius:12px;padding:.9rem 1.1rem;border:1px solid #e2e8f0;box-shadow:0 1px 4px rgba(0,0,0,.05);}
.kpi .num{font-size:1.7rem;font-weight:700;line-height:1;}
.kpi .lbl{font-size:.72rem;color:#64748b;margin-top:.25rem;}
.kpi.warn .num{color:#d97706;} .kpi.danger .num{color:#dc2626;} .kpi.ok .num{color:#059669;}
.filter-bar{background:#fff;border-radius:12px;padding:.85rem 1rem;margin-bottom:1rem;border:1px solid #e2e8f0;display:flex;flex-wrap:wrap;gap:.6rem;align-items:flex-end;}
.filter-bar select,.filter-bar input{border:1px solid #cbd5e1;border-radius:8px;padding:.38rem .65rem;font-size:.8rem;background:#f8fafc;}
.filter-bar label{font-size:.72rem;color:#64748b;display:block;margin-bottom:.2rem;}
.btn{display:inline-flex;align-items:center;gap:.35rem;padding:.42rem .9rem;border-radius:8px;font-size:.8rem;font-weight:600;border:none;cursor:pointer;text-decoration:none;transition:all .15s;}
.btn-primary{background:#2563eb;color:#fff;} .btn-primary:hover{background:#1d4ed8;}
.btn-sm{padding:.3rem .65rem;font-size:.75rem;}
.btn-success{background:#059669;color:#fff;} .btn-success:hover{background:#047857;}
.btn-warning{background:#d97706;color:#fff;}
.btn-danger{background:#dc2626;color:#fff;}
.btn-secondary{background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;}
.btn-info{background:#0891b2;color:#fff;}
.card{background:#fff;border-radius:14px;box-shadow:0 1px 6px rgba(0,0,0,.07);border:1px solid #e2e8f0;overflow:hidden;}
.table-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;font-size:.82rem;}
thead th{background:#f8fafc;color:#475569;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;padding:.65rem .85rem;border-bottom:2px solid #e2e8f0;white-space:nowrap;}
tbody tr{border-bottom:1px solid #f1f5f9;transition:background .1s;}
tbody tr:hover{background:#f8fafc;}
tbody td{padding:.6rem .85rem;vertical-align:middle;}
.semaforo{display:inline-flex;align-items:center;gap:.3rem;font-size:.78rem;font-weight:600;padding:.25rem .6rem;border-radius:999px;}
.sem-verde{background:rgba(16,185,129,.12);color:#059669;}
.sem-amarillo{background:rgba(245,158,11,.12);color:#d97706;}
.sem-rojo{background:rgba(239,68,68,.12);color:#dc2626;}
.sem-gris{background:rgba(107,114,128,.12);color:#6b7280;}
.badge{display:inline-block;padding:.2rem .55rem;border-radius:999px;font-size:.7rem;font-weight:600;}
.badge-warning{background:#fef3c7;color:#92400e;}
.badge-success{background:#d1fae5;color:#065f46;}
.badge-info{background:#dbeafe;color:#1e40af;}
.badge-danger{background:#fee2e2;color:#991b1b;}
.badge-secondary{background:#f1f5f9;color:#475569;}
.badge-primary{background:#eff6ff;color:#1d4ed8;}
.alerta-180{background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:.3rem .6rem;font-size:.72rem;color:#991b1b;font-weight:600;}

/* ── Modal profesional ──────────────────────────────────────── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);backdrop-filter:blur(4px);z-index:1000;align-items:flex-start;justify-content:center;padding:1.5rem;overflow-y:auto;}
.modal-overlay.open{display:flex;animation:fadeInOverlay .18s ease;}
@keyframes fadeInOverlay{from{opacity:0}to{opacity:1}}
.modal{background:#fff;border-radius:20px;width:100%;max-width:820px;box-shadow:0 32px 80px rgba(15,23,42,.22),0 0 0 1px rgba(0,0,0,.06);margin:auto;animation:slideUpModal .22s cubic-bezier(.16,1,.3,1);}
@keyframes slideUpModal{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:translateY(0)}}
.modal-header{display:flex;align-items:center;justify-content:space-between;padding:1.1rem 1.4rem;border-bottom:1px solid #f1f5f9;background:linear-gradient(135deg,#1e40af 0%,#2563eb 60%,#0891b2 100%);border-radius:20px 20px 0 0;}
.modal-header h3{font-size:1.05rem;font-weight:700;color:#fff;}
.modal-header .modal-subtitle{font-size:.75rem;color:rgba(255,255,255,.75);margin-top:.2rem;}
.modal-body{padding:1.35rem;}
.modal-footer{padding:.9rem 1.35rem;border-top:1px solid #f1f5f9;display:flex;gap:.6rem;justify-content:flex-end;background:#f8fafc;border-radius:0 0 20px 20px;}
.btn-close-modal{background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.3);font-size:1rem;cursor:pointer;color:#fff;padding:.25rem .55rem;border-radius:8px;line-height:1;transition:background .15s;}
.btn-close-modal:hover{background:rgba(255,255,255,.32);}

/* Header del modal detalle (color azul) */
#modalDetalle .modal-header{background:linear-gradient(135deg,#1e293b 0%,#334155 100%);}

.form-group{margin-bottom:.85rem;}
.form-group label{display:block;font-size:.78rem;font-weight:600;color:#374151;margin-bottom:.3rem;}
.form-group input,.form-group select,.form-group textarea{width:100%;border:1px solid #d1d5db;border-radius:8px;padding:.45rem .7rem;font-size:.83rem;font-family:inherit;transition:border-color .15s,box-shadow .15s;}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.12);}
.form-group textarea{min-height:70px;resize:vertical;}
.form-control{width:100%;border:1px solid #d1d5db;border-radius:8px;padding:.45rem .7rem;font-size:.83rem;font-family:inherit;transition:border-color .15s,box-shadow .15s;}
.form-control:focus{outline:none;border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.12);}
.form-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.75rem;}
.section-title{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;border-bottom:1px solid #f1f5f9;padding-bottom:.4rem;margin-bottom:.85rem;margin-top:.6rem;}

/* Timeline gestiones */
.timeline{display:flex;flex-direction:column;gap:.6rem;max-height:300px;overflow-y:auto;padding-right:.25rem;}
.timeline-item{display:flex;gap:.75rem;align-items:flex-start;}
.tl-dot{width:34px;height:34px;border-radius:50%;background:#eff6ff;border:2px solid #bfdbfe;display:flex;align-items:center;justify-content:center;font-size:.9rem;flex-shrink:0;}
.tl-content{flex:1;background:#f8fafc;border-radius:10px;padding:.6rem .85rem;border:1px solid #e2e8f0;}
.tl-content .tl-tipo{font-size:.7rem;font-weight:700;color:#2563eb;text-transform:uppercase;letter-spacing:.04em;}
.tl-content .tl-tramite{font-size:.82rem;color:#374151;margin:.25rem 0;}
.tl-content .tl-meta{font-size:.68rem;color:#94a3b8;}

/* Tabs */
.tabs{display:flex;gap:0;border-bottom:2px solid #e2e8f0;margin-bottom:1.1rem;}
.tab-btn{padding:.55rem 1.1rem;font-size:.82rem;font-weight:600;background:none;border:none;cursor:pointer;color:#64748b;border-bottom:2px solid transparent;margin-bottom:-2px;transition:all .15s;border-radius:6px 6px 0 0;}
.tab-btn:hover{color:#2563eb;background:#f8fafc;}
.tab-btn.active{color:#2563eb;border-bottom-color:#2563eb;background:#eff6ff;}
.tab-pane{display:none;} .tab-pane.active{display:block;}
.proroga-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:.85rem 1rem;margin-bottom:.6rem;}
.page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.2rem;flex-wrap:wrap;gap:.6rem;}
.page-header h1{font-size:1.35rem;font-weight:700;color:#1e293b;}
.kpi-bar{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.75rem;margin-bottom:1.2rem;}
.kpi{background:#fff;border-radius:12px;padding:.9rem 1.1rem;border:1px solid #e2e8f0;box-shadow:0 1px 4px rgba(0,0,0,.05);}
.kpi .num{font-size:1.7rem;font-weight:700;line-height:1;}
.kpi .lbl{font-size:.72rem;color:#64748b;margin-top:.25rem;}
.kpi.warn .num{color:#d97706;} .kpi.danger .num{color:#dc2626;} .kpi.ok .num{color:#059669;}
.filter-bar{background:#fff;border-radius:12px;padding:.85rem 1rem;margin-bottom:1rem;border:1px solid #e2e8f0;display:flex;flex-wrap:wrap;gap:.6rem;align-items:flex-end;}
.filter-bar select,.filter-bar input{border:1px solid #cbd5e1;border-radius:8px;padding:.38rem .65rem;font-size:.8rem;background:#f8fafc;}
.filter-bar label{font-size:.72rem;color:#64748b;display:block;margin-bottom:.2rem;}
.btn{display:inline-flex;align-items:center;gap:.35rem;padding:.42rem .9rem;border-radius:8px;font-size:.8rem;font-weight:600;border:none;cursor:pointer;text-decoration:none;transition:all .15s;}
.btn-primary{background:#2563eb;color:#fff;} .btn-primary:hover{background:#1d4ed8;}
.btn-sm{padding:.3rem .65rem;font-size:.75rem;}
.btn-success{background:#059669;color:#fff;} .btn-success:hover{background:#047857;}
.btn-warning{background:#d97706;color:#fff;}
.btn-danger{background:#dc2626;color:#fff;}
.btn-secondary{background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;}
.btn-info{background:#0891b2;color:#fff;}
.card{background:#fff;border-radius:14px;box-shadow:0 1px 6px rgba(0,0,0,.07);border:1px solid #e2e8f0;overflow:hidden;}
.table-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;font-size:.82rem;}
thead th{background:#f8fafc;color:#475569;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;padding:.65rem .85rem;border-bottom:2px solid #e2e8f0;white-space:nowrap;}
tbody tr{border-bottom:1px solid #f1f5f9;transition:background .1s;}
tbody tr:hover{background:#f8fafc;}
tbody td{padding:.6rem .85rem;vertical-align:middle;}
.semaforo{display:inline-flex;align-items:center;gap:.3rem;font-size:.78rem;font-weight:600;padding:.25rem .6rem;border-radius:999px;}
.sem-verde{background:rgba(16,185,129,.12);color:#059669;}
.sem-amarillo{background:rgba(245,158,11,.12);color:#d97706;}
.sem-rojo{background:rgba(239,68,68,.12);color:#dc2626;}
.sem-gris{background:rgba(107,114,128,.12);color:#6b7280;}
.badge{display:inline-block;padding:.2rem .55rem;border-radius:999px;font-size:.7rem;font-weight:600;}
.badge-warning{background:#fef3c7;color:#92400e;}
.badge-success{background:#d1fae5;color:#065f46;}
.badge-info{background:#dbeafe;color:#1e40af;}
.badge-danger{background:#fee2e2;color:#991b1b;}
.badge-secondary{background:#f1f5f9;color:#475569;}
.badge-primary{background:#eff6ff;color:#1d4ed8;}
.alerta-180{background:#fee2e2;border:1px solid #fca5a5;border-radius:8px;padding:.3rem .6rem;font-size:.72rem;color:#991b1b;font-weight:600;}

/* ── Barra superior: título + KPIs + filtros + acciones, todo en una fila ── */
.barra-top{display:flex;align-items:center;gap:.7rem;flex-wrap:wrap;margin-bottom:.9rem;
           background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:.5rem .85rem;
           box-shadow:0 1px 4px rgba(0,0,0,.05);}
.barra-titulo{display:flex;align-items:center;gap:.45rem;flex-shrink:0;}
.barra-titulo-icon{font-size:1.15rem;line-height:1;}
.barra-titulo h1{font-size:1rem;font-weight:800;color:#0f172a;letter-spacing:-.01em;margin:0;}
.barra-sep{width:1px;height:26px;background:#e2e8f0;flex-shrink:0;}
.barra-top .kpi-bar{display:flex;gap:.4rem;margin:0;flex-wrap:wrap;}
.barra-top .kpi{display:flex;align-items:baseline;gap:.32rem;padding:.3rem .65rem;border-radius:9px;
                background:#f8fafc;border:1px solid #e2e8f0;box-shadow:none;}
.barra-top .kpi .num{font-size:1rem;font-weight:800;line-height:1;}
.barra-top .kpi .lbl{font-size:.68rem;color:#64748b;margin:0;white-space:nowrap;}
.barra-top .kpi-sep{display:inline-block;width:1px;height:12px;background:#cbd5e1;margin:0 .2rem;}
/* Chip de Anuladas: es un enlace al filtro, no un contador muerto. */
.kpi-link{text-decoration:none;cursor:pointer;transition:background .12s,border-color .12s;}
.kpi-link:hover{background:#eef2f7;border-color:#94a3b8;}
.kpi-link.activo{background:#e2e8f0;border-color:#64748b;}
.barra-top-form{display:flex;align-items:center;gap:.4rem;margin:0;margin-left:auto;}
.barra-top-form input,.barra-top-form select{border:1px solid #cbd5e1;border-radius:8px;padding:.35rem .6rem;font-size:.78rem;background:#fff;}
.barra-top-form input{width:180px;}
.barra-acciones{display:flex;gap:.35rem;flex-shrink:0;}
.btn-claves{background:linear-gradient(135deg,#fbbf24,#f59e0b);color:#1c1917;border:none;box-shadow:0 1px 4px rgba(0,0,0,.12);}
.btn-excel{background:#107c41;color:#fff;border:none;display:inline-flex;align-items:center;padding:.32rem .5rem;box-shadow:0 1px 4px rgba(0,0,0,.12);}
.btn-excel:hover{background:#0b5c30;}
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0;}
@media (max-width:1100px){ .barra-top-form{margin-left:0;} }

/* ── Encabezados oscuros, como en cobros ────────────────────────────────── */
/* Acotado a .table-wrap: los modales tienen sus propias tablas claras */
.table-wrap table thead th{background:#0f172a;color:#fff;border-bottom:none;position:sticky;top:0;z-index:2;}
.th-orden{color:#cbd5e1;text-decoration:none;display:inline-flex;align-items:center;gap:.25rem;}
.th-orden:hover{color:#fff;}
.th-orden-caret{font-size:.6rem;opacity:.45;}
.th-orden:hover .th-orden-caret{opacity:.85;}
.th-orden.sort-asc,.th-orden.sort-desc{color:#93c5fd;}
.th-orden.sort-asc .th-orden-caret,.th-orden.sort-desc .th-orden-caret{opacity:0;width:0;}
.th-orden.sort-asc::after{content:'\2191';color:#3b82f6;}
.th-orden.sort-desc::after{content:'\2193';color:#3b82f6;}

/* ── Filtros desplegables en los encabezados de columna ─────────────────── */
.th-filtro{position:relative;display:inline-block;}
.th-filtro-btn{background:none;border:none;padding:0;cursor:pointer;font:inherit;color:#cbd5e1;text-transform:inherit;letter-spacing:inherit;display:inline-flex;align-items:center;gap:.28rem;}
.th-filtro-btn:hover{color:#fff;}
.th-filtro-btn.activo{color:#93c5fd;}
.th-filtro-icon{opacity:.55;flex-shrink:0;}
.th-filtro-btn:hover .th-filtro-icon{opacity:.9;}
.th-filtro-btn.activo .th-filtro-icon{opacity:1;color:#3b82f6;}
.th-filtro-caret{font-size:.6rem;opacity:.55;}
.th-filtro-btn.activo .th-filtro-caret{opacity:1;}
.th-filtro-txt{max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.td-cliente{max-width:190px;}
/* position:fixed: el menú debe escapar del overflow de .table-wrap */
.th-filtro-menu{display:none;position:fixed;z-index:2500;background:#fff;border:1px solid #e2e8f0;border-radius:10px;box-shadow:0 12px 32px rgba(0,0,0,.16);padding:.3rem;min-width:190px;max-height:320px;overflow-y:auto;text-transform:none;letter-spacing:normal;}
.th-filtro-menu.open{display:block;}
.th-filtro-menu a{display:block;padding:.4rem .6rem;border-radius:7px;font-size:.78rem;font-weight:500;color:#334155;text-decoration:none;white-space:nowrap;}
.th-filtro-menu a:hover{background:#f1f5f9;}
.th-filtro-menu a.activo{background:#eff6ff;color:#1d4ed8;font-weight:700;}

</style>
@endpush

@section('contenido')
{{-- Todo en una sola fila: título · KPIs · filtros · acciones.
     Los filtros de entidad, estado y tipo viven en los encabezados. --}}
<div class="barra-top" x-data="filtrosInc()" x-init="init()">
    <div class="barra-titulo">
        <span class="barra-titulo-icon">🏥</span>
        <h1>Incapacidades</h1>
    </div>

    <div class="barra-sep"></div>

    <div class="kpi-bar">
        {{-- Cada tarjeta filtra la tabla de un clic, y un segundo clic quita el
             filtro. "Activas" es la vista por defecto, así que la marca estar
             sin filtro. Las que muestran cerradas salen con la más reciente
             arriba (ver el orden en index()). "No pagadas" son las rechazadas
             (cierre definitivo) y "Negadas" las que la entidad negó pero
             todavía se pueden pelear. --}}
        @php
            $sinTarjeta = ['estado' => null, 'kpi' => null, 'con_cerradas' => null,
                           'orden' => null, 'dir' => null, 'page' => null];
            $tarjetas = [
                ['activa' => ! request()->filled('estado') && ! request()->filled('kpi') && ! request()->boolean('con_cerradas'),
                 'query' => [], 'total' => $totalActivas, 'lbl' => 'Activas', 'color' => '#059669',
                 'title' => 'Ver las que aún requieren gestión', 'siempre' => true],
                ['activa' => request('kpi') === 'sin_gestion',
                 'query' => ['kpi' => 'sin_gestion'], 'total' => $sinGestion7dias, 'lbl' => 'Sin gestión +7d', 'color' => '#dc2626',
                 'title' => 'Ver las activas sin ninguna gestión en los últimos 7 días', 'siempre' => true],
                ['activa' => request('kpi') === 'pagadas',
                 'query' => ['kpi' => 'pagadas'], 'total' => $totalPagadas, 'lbl' => 'Pagadas', 'color' => '#059669',
                 'title' => 'Ver las pagadas, la más reciente primero', 'siempre' => true],
                ['activa' => request('estado') === 'rechazado',
                 'query' => ['estado' => 'rechazado'], 'total' => $totalNoPagadas, 'lbl' => 'No pagadas', 'color' => '#dc2626',
                 'title' => 'Ver las no pagadas (rechazadas), la más reciente primero'],
                ['activa' => request('estado') === 'negada',
                 'query' => ['estado' => 'negada'], 'total' => $totalNegadas, 'lbl' => 'Negadas', 'color' => '#b91c1c',
                 'title' => 'Ver las negadas, la más reciente primero'],
                ['activa' => request('estado') === 'anulada',
                 'query' => ['estado' => 'anulada'], 'total' => $totalAnuladas, 'lbl' => 'Anuladas', 'color' => '#64748b',
                 'title' => 'Ver las anuladas, la más reciente primero'],
            ];
        @endphp
        @foreach($tarjetas as $t)
            @if($t['total'] > 0 || ! empty($t['siempre']))
            <a class="kpi kpi-link {{ $t['activa'] ? 'activo' : '' }}"
               href="{{ request()->fullUrlWithQuery($t['activa'] ? $sinTarjeta : array_merge($sinTarjeta, $t['query'])) }}"
               title="{{ $t['activa'] && $t['query'] ? 'Quitar el filtro' : $t['title'] }}">
                <span class="num" style="color:{{ $t['color'] }}">{{ $t['total'] }}</span>
                <span class="lbl">{{ $t['lbl'] }}</span>
            </a>
            @endif
        @endforeach
    </div>

    <form id="filtro-form" method="GET" class="barra-top-form">
        {{-- Los filtros de columna viajan como hidden para no perderse al buscar --}}
        @foreach(['tipo_entidad', 'estado', 'kpi', 'tipo_incapacidad', 'con_cerradas', 'orden', 'dir'] as $h)
            @if(request()->filled($h))<input type="hidden" name="{{ $h }}" value="{{ request($h) }}">@endif
        @endforeach
        <input id="inp-busqueda" name="busqueda" value="{{ $busqueda }}"
               placeholder="🔍 Nombre o cédula..." x-model="busqueda"
               @input="debouncedSubmit()" autocomplete="off">
        {{-- Empresa del cliente: solo las que tienen clientes con incapacidad --}}
        @include('components.filtro-empresa', [
            'empresas' => $empresasDisponibles,
            'formId'   => 'filtro-form',
            'ancho'    => '180px',
        ])
        <select name="vista" @change="$el.form.submit()">
            <option value="agrupada" @selected($vista=='agrupada')>📁 Agrupada</option>
            <option value="plana"    @selected($vista=='plana')>📋 Plana</option>
        </select>
        <a href="{{ route('admin.incapacidades.index') }}" class="btn btn-secondary btn-sm" title="Limpiar filtros">✕</a>
    </form>

    <div class="barra-acciones">
        {{-- Descarga lo que esté filtrado en pantalla, en dos hojas: originales
             y prórrogas. Sin `page`: el archivo no se pagina. --}}
        <a class="btn btn-sm btn-excel"
           href="{{ route('admin.incapacidades.excel', Arr::except(request()->query(), ['page'])) }}"
           title="Descargar en Excel — hojas separadas para originales y prórrogas, con los filtros actuales">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
                <path d="m9.5 13 5 5"/><path d="m14.5 13-5 5"/>
            </svg>
            <span class="sr-only">Descargar en Excel</span>
        </a>
        <button class="btn btn-sm btn-claves" onclick="abrirModalClavesGlobal()">🔑 Claves</button>
        <button class="btn btn-sm btn-primary" onclick="abrirModalCrear()">➕ Nueva</button>
    </div>
</div>

@php
    // Filtro desplegable en el encabezado de una columna. Solo lista los
    // valores que el aliado tiene cargados (los arma el controlador).
    $thFiltro = function (string $param, string $label, array $opciones, array $extra = []) {
        $actual = request($param);
        // Elegir un estado a mano reemplaza la tarjeta de arriba (Pagadas,
        // Sin gestión): juntos casi siempre dejarían la tabla vacía.
        $limpia = $param === 'estado' ? ['kpi' => null] : [];
        $items  = [];
        $items[] = [
            'url'    => request()->fullUrlWithQuery([$param => null, 'page' => null] + $limpia),
            'label'  => 'Todos',
            'activo' => ! filled($actual),
        ];
        foreach ($opciones as $valor => $texto) {
            $items[] = [
                'url'    => request()->fullUrlWithQuery([$param => $valor, 'page' => null] + $limpia),
                'label'  => $texto,
                'activo' => (string) $actual === (string) $valor,
            ];
        }
        foreach ($extra as $ex) {
            $items[] = $ex;
        }

        $activo = filled($actual);
        $texto  = $activo ? ($opciones[$actual] ?? $actual) : $label;

        // Embudo en SVG y no emoji: no existe un emoji de "filtro" que se vea
        // igual en Windows y Mac, y el icono tiene que leerse como "aquí se filtra".
        $embudo = '<svg class="th-filtro-icon" viewBox="0 0 16 16" width="11" height="11" aria-hidden="true">'
                . '<path fill="currentColor" d="M1.5 2h13l-5 6v5.2l-3 1.8V8z"/></svg>';

        $html = '<div class="th-filtro">'
              . '<button type="button" class="th-filtro-btn' . ($activo ? ' activo' : '') . '" title="Filtrar por ' . e($label) . '">'
              . $embudo . '<span class="th-filtro-txt">' . e($texto) . '</span><span class="th-filtro-caret">▾</span></button>'
              . '<div class="th-filtro-menu">';
        foreach ($items as $it) {
            $html .= '<a href="' . e($it['url']) . '"' . (! empty($it['activo']) ? ' class="activo"' : '') . '>'
                   . e($it['label']) . '</a>';
        }

        return $html . '</div></div>';
    };

    // Encabezado ordenable: primer clic asc, segundo desc.
    $ordenActual = request('orden');
    $dirActual   = strtolower(request('dir')) === 'desc' ? 'desc' : 'asc';
    $thOrden = function (string $clave, string $label) use ($ordenActual, $dirActual) {
        $activo    = $ordenActual === $clave;
        $siguiente = ($activo && $dirActual === 'asc') ? 'desc' : 'asc';
        $clase     = 'th-orden' . ($activo ? ' sort-' . $dirActual : '');
        $url = request()->fullUrlWithQuery(['orden' => $clave, 'dir' => $siguiente, 'page' => null]);

        return '<a href="' . e($url) . '" class="' . $clase . '" title="Ordenar por ' . e($label) . '">'
             . e($label) . '<span class="th-orden-caret">⇅</span></a>';
    };

    // "Ver cerradas" perdió su casilla en la barra: se ofrece como una opción
    // más del menú de Estado, que es donde el usuario la busca.
    $extraEstado = [[
        'url'    => request()->fullUrlWithQuery(['con_cerradas' => request()->boolean('con_cerradas') ? null : 1, 'page' => null]),
        'label'  => (request()->boolean('con_cerradas') ? '✓ ' : '') . 'Incluir cerradas',
        'activo' => request()->boolean('con_cerradas'),
    ]];
@endphp

@if($busqueda)
<div style="background:#dbeafe;border:1px solid #93c5fd;border-radius:8px;padding:.5rem .9rem;font-size:.8rem;color:#1e40af;margin-bottom:.75rem">
    🔍 Mostrando <strong>todos</strong> los resultados para "<strong>{{ $busqueda }}</strong>"
    — <a href="{{ route('admin.incapacidades.index') }}" style="color:#1d4ed8;font-weight:600">Limpiar</a>
</div>
@endif

{{-- VISTA AGRUPADA --}}
@if($vista === 'agrupada')
<div class="card">
    <div class="table-wrap">
        <table>
            <thead><tr>
                <th>🚦</th>
                <th>{!! $thOrden('cliente', 'Cliente') !!}</th>
                <th>{!! $thFiltro('tipo_entidad', 'Entidad', $opcionesColumna['entidad']) !!}</th>
                <th>{!! $thFiltro('estado', 'Estado', $opcionesColumna['estado'], $extraEstado) !!}</th>
                <th style="text-align:center">Días</th>
                <th style="text-align:right">{!! $thOrden('valor', 'Valor Esperado') !!}</th>
                <th style="text-align:center">Familia</th>
                <th>Última Gestión</th>
                <th>Acciones</th>
            </tr></thead>
            <tbody>
            @forelse($incapacidades as $inc)
            @php
                $color       = $inc->_color_semaforo_cache;
                $diasGestion = $inc->_dias_gestion_cache;
                $totalDias   = $inc->_total_dias_familia_cache;
                $numPrr      = $inc->_num_prorrogas_cache;
                $alert180    = ($inc->tipo_entidad === 'eps') && ($totalDias >= 180);
                $icono       = match($color) { 'verde'=>'🟢','amarillo'=>'🟡','rojo'=>'🔴',default=>'⚫' };
                $estadoGrupo = $inc->estado_grupo;
                $estadoCfg   = \App\Models\Incapacidad::ESTADOS[$estadoGrupo] ?? ['label'=>$estadoGrupo,'color'=>'secondary'];
                $ult         = $inc->latestGestion;
            @endphp
            <tr id="inc-row-{{ $inc->id }}" style="{{ $estadoGrupo === 'pagada' ? 'opacity:.65' : '' }}">
                <td class="td-semaforo">
                    <span class="semaforo sem-{{ $color }}" title="{{ $diasGestion }} días sin gestión">
                        {{ $icono }}
                    </span>
                    @if($alert180)<br><span class="alerta-180" title="Más de 180 días en EPS">⚠️180d</span>@endif
                </td>
                {{-- Nombre y cédula en el mismo bloque: la cédula no se gana
                     una línea propia, el nombre envuelve y usa el ancho --}}
                <td class="td-cliente">
                    <div style="display:flex;align-items:flex-start;gap:.3rem">
                        <button type="button" class="btn-ficha-cliente"
                                data-cedula="{{ $inc->cedula_usuario }}"
                                data-nombre="{{ nombre_oracion($inc->_nombre_cliente_cache ?? $inc->cedula_usuario) }}"
                                title="Ver ficha del cliente" style="margin-top:.05rem">👤</button>
                        <div style="font-weight:600;font-size:.78rem;color:#1e293b;line-height:1.25;max-width:170px">
                            {{ nombre_oracion($inc->_nombre_cliente_cache ?? $inc->cedula_usuario) }}
                            <span style="font-weight:500;color:#94a3b8;white-space:nowrap">{{ $inc->_tipo_doc_cache ?: 'CC' }} {{ $inc->cedula_usuario }}</span>
                        </div>
                    </div>
                </td>
                <td class="td-entidad">
                    <span class="badge badge-secondary">{{ strtoupper($inc->entidad_grupo) }}</span>
                    <div style="font-size:.7rem;color:#64748b;margin-top:.2rem">{{ Str::limit($inc->entidad_nombre,22) }}</div>
                </td>
                <td class="td-estado"><span class="badge badge-{{ $estadoCfg['color'] }}">{{ $estadoCfg['label'] }}</span></td>
                <td class="td-dias" style="text-align:center">
                    <strong>{{ $totalDias }}</strong><span style="color:#94a3b8;font-size:.7rem">d</span>
                    <div style="font-size:.68rem;color:#64748b">{{ $inc->fecha_inicio?->format('d/m/y') }}</div>
                </td>
                <td class="td-valor" style="text-align:right">
                    @php
                    // Misma lista que usa el orden por valor en el controlador,
                    // para que lo que se ve y lo que se ordena no se separen.
                    $estadosPagados = \App\Http\Controllers\Admin\IncapacidadController::ESTADOS_SIN_PENDIENTE;
                    $valPendiente = 0;
                    if (!in_array($inc->estado, $estadosPagados)) $valPendiente += (float)($inc->valor_esperado ?? 0);
                    foreach ($inc->prorrogas as $p) {
                        if (!in_array($p->estado, $estadosPagados)) $valPendiente += (float)($p->valor_esperado ?? 0);
                    }
                @endphp
                @if($valPendiente > 0)
                <span style="font-weight:700;color:#059669;font-size:.83rem">${{ number_format($valPendiente,0,',','.') }}</span>
                @else<span style="color:#94a3b8;font-size:.75rem">—</span>@endif
                </td>
                <td class="td-familia" style="text-align:center">
                    @if($numPrr > 0)
                    <span class="badge badge-primary">+{{ $numPrr }} prórr.</span>
                    @php
                        $estadosFinales = ['pagada','pagado_afiliado','pagada_afiliado','pagada_razon_social','cierre_exitoso','rechazado','anulada'];
                        $hayPendiente = $inc->prorrogas->whereNotIn('estado', $estadosFinales)->count() > 0;
                    @endphp
                    @if($hayPendiente)
                    <span style="display:block;font-size:.68rem;color:#d97706;font-weight:700;margin-top:.15rem">⚠️ Prórr. activa</span>
                    @endif
                    @else<span style="color:#94a3b8;font-size:.72rem">Original</span>@endif
                </td>
                <td class="td-gestion">
                    @if($ult)
                    <div style="font-size:.72rem;font-weight:600;color:#2563eb">{{ $ult->tipoIcono() }} {{ $ult->tipoLabel() }}</div>
                    <div style="font-size:.68rem;color:#94a3b8">{{ $ult->created_at->format('d/m/y H:i') }}</div>
                    @else<span style="color:#ef4444;font-size:.72rem">Sin gestión</span>@endif
                </td>
                <td>
                    {{-- Solo "Ver": la gestión se hace dentro del detalle --}}
                    <button class="btn btn-info btn-sm" onclick="verDetalle({{ $inc->id }})">👁 Ver</button>
                </td>
            </tr>
            @empty
            <tr><td colspan="9" style="text-align:center;padding:2.5rem;color:#94a3b8">
                No hay incapacidades. @if($busqueda)<a href="{{ route('admin.incapacidades.index') }}">Limpiar búsqueda</a>@endif
            </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div style="padding:.85rem 1.1rem;border-top:1px solid #f1f5f9">{{ $incapacidades->links() }}</div>
</div>

{{-- VISTA PLANA --}}
@else
<div class="card">
    <div class="table-wrap">
        <table>
            <thead><tr>
                <th>🚦</th>
                <th>{!! $thOrden('cliente', 'Cliente') !!}</th>
                <th>{!! $thFiltro('tipo_incapacidad', 'Tipo', $opcionesColumna['tipo']) !!}</th>
                <th>{!! $thFiltro('tipo_entidad', 'Entidad', $opcionesColumna['entidad']) !!}</th>
                <th>Días</th>
                <th>{!! $thFiltro('estado', 'Estado', $opcionesColumna['estado'], $extraEstado) !!}</th>
                <th>{!! $thOrden('valor', 'Valor') !!}</th><th>Última Gestión</th><th>Acciones</th>
            </tr></thead>
            <tbody>
            @forelse($incapacidades as $inc)
            @php
                $color       = $inc->_color_semaforo_cache;
                $diasGestion = $inc->_dias_gestion_cache;
                $totalDias   = $inc->_total_dias_familia_cache;
                $numPrr      = $inc->_num_prorrogas_cache;
                $icono       = match($color) { 'verde'=>'🟢','amarillo'=>'🟡','rojo'=>'🔴',default=>'⚫' };
                $estadoCfg   = \App\Models\Incapacidad::ESTADOS[$inc->estado] ?? ['label'=>$inc->estado,'color'=>'secondary'];
                $ult         = $inc->latestGestion;
            @endphp
            <tr>
                <td><span class="semaforo sem-{{ $color }}" title="{{ $diasGestion }} días sin gestión">{{ $icono }} {{ $diasGestion }} días</span></td>
                <td class="td-cliente">
                    <div style="display:flex;align-items:flex-start;gap:.3rem">
                        <button type="button" class="btn-ficha-cliente"
                                data-cedula="{{ $inc->cedula_usuario }}"
                                data-nombre="{{ nombre_oracion($inc->_nombre_cliente_cache ?? $inc->cedula_usuario) }}"
                                title="Ver ficha del cliente" style="margin-top:.05rem">👤</button>
                        <div style="font-weight:600;font-size:.78rem;color:#1e293b;line-height:1.25;max-width:170px">
                            {{ nombre_oracion($inc->_nombre_cliente_cache ?? $inc->cedula_usuario) }}
                            <span style="font-weight:500;color:#94a3b8;white-space:nowrap">{{ $inc->_tipo_doc_cache ?: 'CC' }} {{ $inc->cedula_usuario }}</span>
                        </div>
                    </div>
                </td>
                <td style="font-size:.78rem">{{ $inc->tipoIncapacidadLabel() }}<br>
                    @if($inc->incapacidad_padre_id)<span class="badge badge-info">Prórroga {{ $inc->numero_proroga }}</span>@endif
                </td>
                <td><span class="badge badge-secondary">{{ strtoupper($inc->tipo_entidad) }}</span>
                    <div style="font-size:.7rem;color:#64748b">{{ Str::limit($inc->entidad_nombre,20) }}</div>
                </td>
                <td style="text-align:center"><strong>{{ $inc->dias_incapacidad }}</strong>d<br>
                    @if($numPrr>0)<span style="font-size:.68rem;color:#2563eb">Total:{{ $totalDias }}d</span>@endif
                </td>
                <td><span class="badge badge-{{ $estadoCfg['color'] }}">{{ $estadoCfg['label'] }}</span></td>
                <td>@if($inc->valor_esperado)<span style="font-weight:700;color:#059669">${{ number_format($inc->valor_esperado,0,',','.') }}</span>@else—@endif</td>
                <td>@if($ult)<div style="font-size:.72rem;font-weight:600;color:#2563eb">{{ $ult->tipoLabel() }}</div>
                    <div style="font-size:.68rem;color:#94a3b8">{{ $ult->created_at->format('d/m/y') }}</div>
                    @else<span style="color:#ef4444;font-size:.72rem">Sin gestión</span>@endif
                </td>
                <td>
                    <div style="display:flex;gap:.3rem;flex-wrap:wrap">
                        <button class="btn btn-info btn-sm" onclick="verDetalle({{ $inc->id }})">👁 Ver</button>
                    </div>
                </td>
            </tr>
            @empty
            <tr><td colspan="9" style="text-align:center;padding:2rem;color:#94a3b8">No hay incapacidades.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div style="padding:.85rem 1.1rem;border-top:1px solid #f1f5f9">{{ $incapacidades->links() }}</div>
</div>
@endif


{{-- ═══════════════════════════════════════════════════════════ --}}
{{-- MODALES (partials reutilizables)                           --}}
{{-- ═══════════════════════════════════════════════════════════ --}}
@include('admin.incapacidades.partials._modal_crear', ['trabajadores' => $trabajadores, 'razonesSociales' => $razonesSociales])
@include('admin.incapacidades.partials._modal_detalle')
@include('admin.partials._modal_claves_globales')
@include('components.modal-cliente')

@endsection

@push('scripts')
<script>
const TOKEN = document.querySelector('meta[name="csrf-token"]').content;
let _detalleActual = null;   // respuesta del último /show, para el modal de unir
let _editandoInc   = null;   // incapacidad abierta en el modal de editar
const EPS_LIST   = @json($epsList->map(fn($e)=>['id'=>$e->id,'nombre'=>$e->nombre]));
const ARL_LIST   = @json($arlList->map(fn($e)=>['id'=>$e->id,'nombre'=>$e->nombre_arl]));
const AFP_LIST   = @json($pensionList->map(fn($e)=>['id'=>$e->id,'nombre'=>$e->razon_social]));

// Cache para docs de familia
let _docsFamiliaLoaded = null;

// Mapas de labels para mostrar en el frontend
const TIPOS_INCAPACIDAD = @json(\App\Models\Incapacidad::TIPOS_INCAPACIDAD);
const TIPOS_ENTIDAD     = @json(\App\Models\Incapacidad::TIPOS_ENTIDAD);
const ESTADOS_INC       = @json(\App\Models\Incapacidad::ESTADOS);
const ESTADOS_PAGO_INC  = @json(\App\Models\Incapacidad::ESTADOS_PAGO);
const MOTIVOS_ANULACION = @json(\App\Models\Incapacidad::MOTIVOS_ANULACION);
// Deshacer un estado borra plata (gasto, abono, consignación): el botón solo
// existe para quien tenga el permiso. El backend lo vuelve a exigir igual.
const PUEDE_REVERSAR    = @json(auth()->user()?->can('incapacidades.revertir_estado') ?? false);

function labelTipo(key){ return TIPOS_INCAPACIDAD[key] || key; }
function labelEstado(key){ const v = ESTADOS_INC[key]; return (v && v.label) ? v.label : (key || '—'); }
function labelEstadoPago(key){ const v = ESTADOS_PAGO_INC[key]; return (v && v.label) ? v.label : (key || '—'); }
function colorEstado(key){ const v = ESTADOS_INC[key]; return (v && v.color) ? v.color : 'secondary'; }
function colorEstadoPago(key){ const v = ESTADOS_PAGO_INC[key]; return (v && v.color) ? v.color : 'secondary'; }
function formatFecha(str){ return str ? str.substring(0,10) : '—'; }
function formatFechaLarga(str){
    if(!str) return '—';
    const meses = ['enero','febrero','marzo','abril','mayo','junio','julio','agosto','septiembre','octubre','noviembre','diciembre'];
    const d = new Date(str.substring(0,10)+'T12:00:00');
    return `${d.getDate()}-${meses[d.getMonth()]}-${d.getFullYear()}`;
}

// ── Modales ──────────────────────────────────────────────────────────────────
function cerrarModal(id){
    document.getElementById(id).classList.remove('open');
    if (id === 'modalDetalle') {
        _docsFamiliaLoaded = null;
        const incId = document.getElementById('modalDetalle').dataset.incId;
        if (incId) {
            actualizarFilaIncapacidad(incId);
        }
    }
    if (id === 'modalCrear') { const p = document.getElementById('editarDocsPanel'); if(p) p.remove(); }
}
function abrirModal(id) { document.getElementById(id).classList.add('open'); }

function actualizarFilaIncapacidad(id) {
    const row = document.getElementById('inc-row-' + id);
    if (!row) return;

    fetch(`/admin/incapacidades/${id}/show`)
        .then(r => r.json())
        .then(data => {
            if (!data || !data.incapacidad) return;
            const inc = data.incapacidad;

            // Determinar opacidad de fila según estado
            const estadosPagados = ['pagada','pagado_afiliado','pagada_afiliado','pagada_razon_social','cierre_exitoso','anulada'];
            if (estadosPagados.includes(inc.estado)) {
                row.style.opacity = '0.65';
            } else {
                row.style.opacity = '1';
            }

            // 1. Semáforo
            const semTd = row.querySelector('.td-semaforo');
            if (semTd) {
                semTd.innerHTML = `
                    <span class="semaforo sem-${data.semaforo}" title="${data.dias_gestion} días sin gestión">
                        ${data.icono}
                    </span>
                    ${data.alerta_180 ? '<br><span class="alerta-180" title="Más de 180 días en EPS">⚠️180d</span>' : ''}
                `;
            }

            // 2. Estado
            const configEstados = {
                recibido:                  {label: '📬 Recibido',                     color: 'secondary'},
                transcripcion_ips:         {label: '🏥 Transcripción IPS',            color: 'info'},
                radicada:                  {label: '📋 Radicada',                     color: 'primary'},
                negada:                    {label: '🚫 Negada',                       color: 'danger'},
                derecho_peticion:          {label: '📄 Derecho de Petición',          color: 'warning'},
                derecho_peticion_radicado: {label: '📄 D. Petición Radicado',         color: 'warning'},
                tutela:                    {label: '⚖️ Tutela',                        color: 'warning'},
                tutela_radicada:           {label: '📜 Tutela Radicada',              color: 'warning'},
                rechazado:                 {label: '❌ Rechazado',                    color: 'danger'},
                en_liquidacion:            {label: '💰 En Liquidación',              color: 'info'},
                pagada_razon_social:       {label: '🏢 Pagada a Razón Social',       color: 'info'},
                pagada_afiliado:           {label: '🏦 Pagada al Afiliado',          color: 'success'},
                pagado_afiliado:           {label: '🏦 Pagada al Afiliado (legacy)', color: 'success'},
                cierre_exitoso:            {label: '✅ Cierre Exitoso',              color: 'success'},
                anulada:                   {label: '⛔ Anulada',                      color: 'secondary'}
            };
            const cfg = configEstados[inc.estado] || {label: inc.estado, color: 'secondary'};
            const estTd = row.querySelector('.td-estado');
            if (estTd) {
                estTd.innerHTML = `<span class="badge badge-${cfg.color}">${cfg.label}</span>`;
            }

            // 3. Días
            const diasTd = row.querySelector('.td-dias');
            if (diasTd) {
                let fIni = '—';
                if (inc.fecha_inicio) {
                    const pts = inc.fecha_inicio.substring(0, 10).split('-');
                    if (pts.length === 3) fIni = `${pts[2]}/${pts[1]}/${pts[0].substring(2, 4)}`;
                }
                diasTd.innerHTML = `<strong>${data.familia_dias}</strong><span style="color:#94a3b8;font-size:.7rem">d</span>
                                    <div style="font-size:.68rem;color:#64748b">${fIni}</div>`;
            }

            // 4. Valor Esperado / Pendiente
            let valPendiente = 0;
            if (!estadosPagados.includes(inc.estado)) {
                valPendiente += parseFloat(inc.valor_esperado || 0);
            }
            if (inc.prorrogas) {
                inc.prorrogas.forEach(p => {
                    if (!estadosPagados.includes(p.estado)) {
                        valPendiente += parseFloat(p.valor_esperado || 0);
                    }
                });
            }

            const valTd = row.querySelector('.td-valor');
            if (valTd) {
                if (valPendiente > 0) {
                    const fmtVal = new Intl.NumberFormat('es-CO', { minimumFractionDigits: 0, maximumFractionDigits: 0 }).format(valPendiente);
                    valTd.innerHTML = `<span style="font-weight:700;color:#059669;font-size:.83rem">$${fmtVal}</span>`;
                } else {
                    valTd.innerHTML = `<span style="color:#94a3b8;font-size:.75rem">—</span>`;
                }
            }

            // 5. Familia
            const famTd = row.querySelector('.td-familia');
            if (famTd) {
                if (data.num_prorrogas > 0) {
                    famTd.innerHTML = `
                        <span class="badge badge-primary">+${data.num_prorrogas} prórr.</span>
                        ${data.prorrogas_pendientes > 0 ? '<span style="display:block;font-size:.68rem;color:#d97706;font-weight:700;margin-top:.15rem">⚠️ Prórr. activa</span>' : ''}
                    `;
                } else {
                    famTd.innerHTML = `<span style="color:#94a3b8;font-size:.72rem">Original</span>`;
                }
            }

            // 6. Última gestión
            const gestTd = row.querySelector('.td-gestion');
            if (gestTd) {
                let ult = null;
                if (inc.gestiones && inc.gestiones.length > 0) {
                    ult = inc.gestiones.reduce((a, b) => new Date(a.created_at) > new Date(b.created_at) ? a : b);
                }
                if (ult) {
                    const icon = {llamada:'📞',correo:'📧',whatsapp:'💬',portal:'🌐',radico:'📋',tutela:'⚖️',
                                  transcripcion_ips:'🏥',respuesta_entidad:'📩',autorizacion:'✅',
                                  liquidacion:'💰',pago_afiliado:'🏦',otro:'📝'}[ult.tipo] || '📝';
                    const labels = {llamada:'Llamada',correo:'Correo',whatsapp:'WhatsApp',portal:'Portal Web',radico:'Radicado',tutela:'Tutela',
                                    transcripcion_ips:'Transcripción IPS',respuesta_entidad:'Respuesta Entidad',autorizacion:'Autorización',
                                    liquidacion:'Liquidación',pago_afiliado:'Pago Afiliado',otro:'Otro'};
                    const label = labels[ult.tipo] || ult.tipo;
                    
                    let fGest = '—';
                    if (ult.created_at) {
                        const dateObj = new Date(ult.created_at);
                        const meses = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
                        const h = String(dateObj.getHours()).padStart(2, '0');
                        const m = String(dateObj.getMinutes()).padStart(2, '0');
                        fGest = `${dateObj.getDate()} ${meses[dateObj.getMonth()]} ${h}:${m}`;
                    }
                    
                    gestTd.innerHTML = `
                        <div style="font-size:.72rem;font-weight:600;color:#2563eb">${icon} ${label}</div>
                        <div style="font-size:.68rem;color:#94a3b8">${fGest}</div>
                    `;
                } else {
                    gestTd.innerHTML = `<span style="color:#ef4444;font-size:.72rem">Sin gestión</span>`;
                }
            }
        }).catch(() => {});
}

function abrirModalCrear(){
    document.getElementById('modalCrearTitle').textContent = '➕ Nueva Incapacidad';
    descartarProrroga();
    document.getElementById('formMethod').value = 'POST';
    document.getElementById('formId').value = '';
    document.getElementById('padreId').value = '';
    document.getElementById('razonSocialHidden').value = '';
    document.getElementById('formCrear').reset();
    // Restaurar valores que reset() borra
    const qrs = document.getElementById('quienRecibeSelect');
    if(qrs) qrs.value = qrs.dataset.authId || '';
    const fr = document.querySelector('[name=fecha_recibido]');
    if(fr) fr.value = new Date().toISOString().substring(0,10);
    // Reset info boxes
    const b1=document.getElementById('contratoInfoBox'); if(b1) b1.style.display='none';
    const b2=document.getElementById('contratoInfoBoxInactivo'); if(b2) b2.style.display='none';
    const qr=document.getElementById('quienRemiteSelect'); if(qr) qr.innerHTML='<option value="">Seleccionar...</option>';
    const cs=document.getElementById('contratoSelect'); if(cs) cs.innerHTML='<option value="">Sin contrato</option>';
    const infoRsBox = document.getElementById('infoRazonSocialBox'); if(infoRsBox) infoRsBox.style.display = 'none';
    _contratosData=[]; _clienteEpsId=null; _clienteArlId=null; _clienteAfpId=null; _clienteNombre=''; _empresaNombre='';
    abrirModal('modalCrear');
}


function abrirModalProroga(padreId){
    abrirModalCrear();
    document.getElementById('modalCrearTitle').textContent = '➕ Registrar Prórroga';
    document.getElementById('padreId').value = padreId;

    fetch(`/admin/incapacidades/${padreId}/show`)
        .then(r=>r.json()).then(data=>{
            const inc = data.incapacidad;
            if(!inc) return;

            // Asegurar que el padreId no se pierda al restaurar
            document.getElementById('padreId').value = padreId;

            const f = document.getElementById('formCrear');
            f.querySelector('[name=cedula_usuario]').value          = inc.cedula_usuario;
            f.querySelector('[name=tipo_incapacidad]').value        = inc.tipo_incapacidad;
            f.querySelector('[name=tipo_entidad]').value            = inc.tipo_entidad;
            f.querySelector('[name=diagnostico]') && (f.querySelector('[name=diagnostico]').value = inc.diagnostico||'');

            // Encargado
            const qrSel = document.getElementById('quienRecibeSelect');
            if (qrSel && inc.quien_recibe_id) qrSel.value = inc.quien_recibe_id;

            // Quién remite
            const qrmSel = document.getElementById('quienRemiteSelect');
            if (qrmSel && inc.quien_remite) {
                if (!qrmSel.querySelector(`option[value="${inc.quien_remite}"]`)) {
                    const opt = document.createElement('option');
                    opt.value = inc.quien_remite;
                    opt.textContent = inc.quien_remite;
                    qrmSel.appendChild(opt);
                }
                qrmSel.value = inc.quien_remite;
            }

            // Nombre cliente
            _clienteNombre = data.cliente
                ? [data.cliente.primer_nombre, data.cliente.primer_apellido].filter(Boolean).join(' ')
                : inc.cedula_usuario;
            document.getElementById('nombreCliente').value = _clienteNombre;

            _empresaNombre = data.empresa || '';

            // Razón social hidden e inputs informativos
            const rsH = document.getElementById('razonSocialHidden');
            if (rsH) rsH.value = inc.razon_social_id || '';
            const rsInput = document.getElementById('razonSocialInput');
            const rsNitInput = document.getElementById('razonSocialNitInput');
            if (rsInput) rsInput.value = inc.razon_social_nombre || 'Sin razón social';
            if (rsNitInput) rsNitInput.value = (inc.razon_social && inc.razon_social.nit) ? inc.razon_social.nit : 'Sin registrar';

            // Sugerir la fecha de inicio de la prórroga (un día después del fin de la incapacidad padre)
            if (inc.fecha_terminacion) {
                const parts = inc.fecha_terminacion.substring(0, 10).split('-');
                if (parts.length === 3) {
                    const year = parseInt(parts[0], 10);
                    const month = parseInt(parts[1], 10);
                    const day = parseInt(parts[2], 10);
                    const nextDate = new Date(year, month - 1, day + 1);
                    
                    const yyyy = nextDate.getFullYear();
                    const mm = String(nextDate.getMonth() + 1).padStart(2, '0');
                    const dd = String(nextDate.getDate()).padStart(2, '0');
                    document.getElementById('fechaInicioInput').value = `${yyyy}-${mm}-${dd}`;
                }
            }

            // Inicializar fallbacks desde los datos del cliente
            _fallbackEpsId = data.cliente ? data.cliente.eps_id : null;
            _fallbackPensionId = data.cliente ? data.cliente.pension_id : null;

            // Cargar y seleccionar el contrato de manera temporal
            const csEl = document.getElementById('contratoSelect');
            if (csEl && inc.contrato_id) {
                const label = inc.razon_social_nombre
                    ? `Contrato #${inc.contrato_id} — ${inc.razon_social_nombre}`
                    : `Contrato #${inc.contrato_id}`;
                csEl.innerHTML = `<option value="">Sin contrato</option>
                    <option value="${inc.contrato_id}" selected>${label}</option>`;
                
                const infoBox = document.getElementById('contratoInfoBox');
                const infoTxt = document.getElementById('contratoInfoText');
                if (infoBox && infoTxt) {
                    infoTxt.textContent = label;
                    infoBox.style.display = 'block';
                }
            }

            // Cargar todos los contratos del cliente en segundo plano para poblar el selector completo
            _contratosData = [];
            _clienteEpsId = null;
            _clienteArlId = null;
            _clienteAfpId = null;
            fetch(`/admin/incapacidades/api/contratos?cedula=${encodeURIComponent(inc.cedula_usuario)}`)
                .then(r=>r.json()).then(contratos=>{
                    _contratosData = contratos;
                    
                    const csEl = document.getElementById('contratoSelect');
                    if (csEl) {
                        csEl.innerHTML = '<option value="">Sin contrato especifico</option>';
                        contratos.forEach(c=>{
                            const vigente = c.estado === 'vigente';
                            const estadoEmoji = vigente ? '🟢' : '🔴';
                            const rsNombre = c.razon_social_nombre || 'Sin razón social';
                            const opt = document.createElement('option');
                            opt.value = c.id;
                            opt.textContent = `${estadoEmoji} Contrato #${c.id} — ${c.estado.toUpperCase()} (${c.fecha_ingreso?.substring(0,10)||''}) — [${rsNombre}]`;
                            if (c.id == inc.contrato_id) {
                                opt.selected = true;
                            }
                            if (vigente) {
                                opt.style.color = '#059669';
                                opt.style.fontWeight = '600';
                            } else {
                                opt.style.color = '#ef4444';
                            }
                            csEl.appendChild(opt);
                        });
                    }
                    
                    const c = contratos.find(x => x.id == inc.contrato_id);
                    if (c) {
                        _clienteEpsId = c.eps_id || null;
                        _clienteArlId = c.arl_id || null;
                        _clienteAfpId = c.pension_id || null;
                    }
                });

            actualizarListaEntidades(inc.tipo_entidad, inc.entidad_responsable_id);
        });
}


function abrirModalEditar(id){
    fetch(`/admin/incapacidades/${id}/show`)
        .then(r=>r.json()).then(data=>{
            const inc = data.incapacidad;
            const esPrrroga = !!inc.incapacidad_padre_id;
            document.getElementById('modalCrearTitle').textContent =
                esPrrroga ? `✏️ Editar Prórroga #${inc.numero_proroga}` : '✏️ Editar Incapacidad';
            _editandoInc = inc;
            _pintarVinculoFamilia(inc, data);
            document.getElementById('formCrear').action = `/admin/incapacidades/${id}`;
            document.getElementById('formMethod').value = 'PUT';
            document.getElementById('formId').value = id;
            document.getElementById('formCrear').reset();
            // Poblar campos básicos
            const f = document.getElementById('formCrear');
            f.querySelector('[name=cedula_usuario]').value          = inc.cedula_usuario;
            f.querySelector('[name=dias_incapacidad]').value        = inc.dias_incapacidad;
            f.querySelector('[name=fecha_inicio]').value            = inc.fecha_inicio?.substring(0,10)||'';
            f.querySelector('[name=fecha_terminacion]').value       = inc.fecha_terminacion?.substring(0,10)||'';
            f.querySelector('[name=fecha_recibido]').value          = inc.fecha_recibido?.substring(0,10)||'';
            f.querySelector('[name=tipo_incapacidad]').value        = inc.tipo_incapacidad;
            f.querySelector('[name=tipo_entidad]').value            = inc.tipo_entidad;
            f.querySelector('[name=diagnostico]') && (f.querySelector('[name=diagnostico]').value = inc.diagnostico||'');
            f.querySelector('[name=observacion]') && (f.querySelector('[name=observacion]').value = inc.observacion||'');

            // Número radicado y fecha radicado
            const nrEl = f.querySelector('[name=numero_radicado]');
            if (nrEl) nrEl.value = inc.numero_radicado || '';
            const frEl = f.querySelector('[name=fecha_radicado]');
            if (frEl) frEl.value = inc.fecha_radicado?.substring(0,10) || '';

            // Razón social hidden e inputs informativos
            const rsH = document.getElementById('razonSocialHidden');
            if (rsH) rsH.value = inc.razon_social_id || '';
            const rsInput = document.getElementById('razonSocialInput');
            const rsNitInput = document.getElementById('razonSocialNitInput');
            if (rsInput) rsInput.value = inc.razon_social_nombre || 'Sin razón social';
            if (rsNitInput) rsNitInput.value = (inc.razon_social && inc.razon_social.nit) ? inc.razon_social.nit : 'Sin registrar';
            
            // Inicializar fallbacks desde los datos del cliente
            _fallbackEpsId = data.cliente ? data.cliente.eps_id : null;
            _fallbackPensionId = data.cliente ? data.cliente.pension_id : null;

            // Contrato — inyectar la opción actual en el select para que quede seleccionada
            const csEl = document.getElementById('contratoSelect');
            if (csEl && inc.contrato_id) {
                const label = inc.razon_social_nombre
                    ? `Contrato #${inc.contrato_id} — ${inc.razon_social_nombre}`
                    : `Contrato #${inc.contrato_id}`;
                // Limpiar y añadir la opción del contrato actual
                csEl.innerHTML = `<option value="">Sin contrato</option>
                    <option value="${inc.contrato_id}" selected>${label}</option>`;
                // Mostrar info del contrato
                const infoBox = document.getElementById('contratoInfoBox');
                const infoTxt = document.getElementById('contratoInfoText');
                if (infoBox && infoTxt) {
                    infoTxt.textContent = label;
                    infoBox.style.display = 'block';
                }
            }

            // Cargar todos los contratos del cliente en segundo plano para autoselección de EPS/ARL/AFP
            _contratosData = [];
            _clienteEpsId = null;
            _clienteArlId = null;
            _clienteAfpId = null;
            fetch(`/admin/incapacidades/api/contratos?cedula=${encodeURIComponent(inc.cedula_usuario)}`)
                .then(r=>r.json()).then(contratos=>{
                    _contratosData = contratos;
                    
                    // Repoblar el select de forma interactiva
                    const csEl = document.getElementById('contratoSelect');
                    if (csEl) {
                        csEl.innerHTML = '<option value="">Sin contrato especifico</option>';
                        contratos.forEach(c=>{
                            const vigente = c.estado === 'vigente';
                            const estadoEmoji = vigente ? '🟢' : '🔴';
                            const rsNombre = c.razon_social_nombre || 'Sin razón social';
                            const opt = document.createElement('option');
                            opt.value = c.id;
                            opt.textContent = `${estadoEmoji} Contrato #${c.id} — ${c.estado.toUpperCase()} (${c.fecha_ingreso?.substring(0,10)||''}) — [${rsNombre}]`;
                            if (c.id == inc.contrato_id) {
                                opt.selected = true;
                            }
                            if (vigente) {
                                opt.style.color = '#059669';
                                opt.style.fontWeight = '600';
                            } else {
                                opt.style.color = '#ef4444';
                            }
                            csEl.appendChild(opt);
                        });
                    }
                    
                    const c = contratos.find(x => x.id == inc.contrato_id);
                    if (c) {
                        _clienteEpsId = c.eps_id || null;
                        _clienteArlId = c.arl_id || null;
                        _clienteAfpId = c.pension_id || null;
                    }
                });

            // Encargado
            const qrSel = document.getElementById('quienRecibeSelect');
            if (qrSel && inc.quien_recibe_id) qrSel.value = inc.quien_recibe_id;

            // Quién remite — inyectar opción si tiene valor
            const qrmSel = document.getElementById('quienRemiteSelect');
            if (qrmSel && inc.quien_remite) {
                if (!qrmSel.querySelector(`option[value="${inc.quien_remite}"]`)) {
                    const opt = document.createElement('option');
                    opt.value = inc.quien_remite;
                    opt.textContent = inc.quien_remite;
                    qrmSel.appendChild(opt);
                }
                qrmSel.value = inc.quien_remite;
            }

            // Nombre cliente
            document.getElementById('nombreCliente').value = data.cliente
                ? [data.cliente.primer_nombre, data.cliente.primer_apellido].filter(Boolean).join(' ')
                : inc.cedula_usuario;

            actualizarListaEntidades(inc.tipo_entidad, inc.entidad_responsable_id);

            // Panel de documentos de esta incapacidad específica
            mostrarDocsEnEditar(id, esPrrroga ? `Prórroga #${inc.numero_proroga}` : 'Incapacidad Original');

            abrirModal('modalCrear');
        });
}

function mostrarDocsEnEditar(incId, labelGrupo) {
    // Buscar o crear el panel de docs dentro del modal crear
    let panel = document.getElementById('editarDocsPanel');
    if (!panel) {
        const footer = document.querySelector('#modalCrear .modal-footer');
        panel = document.createElement('div');
        panel.id = 'editarDocsPanel';
        panel.style.cssText = 'border-top:1px solid #e2e8f0;padding:.75rem 1.35rem;background:#f8fafc;max-height:220px;overflow-y:auto';
        footer.parentNode.insertBefore(panel, footer);
    }
    panel.innerHTML = `<div style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#64748b;letter-spacing:.05em;margin-bottom:.5rem">
        📎 Documentos de ${labelGrupo}
        <button class="btn btn-sm" style="background:#e0e7ff;color:#3730a3;font-size:.7rem;padding:.15rem .45rem;margin-left:.5rem"
            onclick="subirDocumento(${incId});_docsFamiliaLoaded=null">+ Subir</button>
    </div>
    <div id="editarDocsList" style="font-size:.78rem;color:#94a3b8">⏳ Cargando...</div>`;

    fetch(`/admin/incapacidades/${incId}/documentos-familia`)
        .then(r=>r.json()).then(data=>{
            const lista = document.getElementById('editarDocsList');
            if (!lista) return;
            // Solo mostrar documentos del grupo con ese incId
            const grupo = (data.familia||[]).find(g=>g.incapacidad_id==incId);
            const docs = grupo?.documentos || [];
            if (!docs.length) { lista.textContent = 'Sin documentos en este grupo.'; return; }
            const TIPOS_DOC = {
                incapacidad_original:'📄 Inc. Original', historia_clinica:'📋 Hist. Clínica',
                radicado_entidad:'📮 Radicado', soporte_pago:'💳 Soporte Pago',
                transcripcion:'🏥 Transcripción', cedula:'🪪 Cédula', examen:'🔬 Examen', otro:'📎 Otro'
            };
            lista.innerHTML = docs.map(d=>`
                <div style="display:flex;align-items:center;justify-content:space-between;padding:.3rem .5rem;border:1px solid #e2e8f0;border-radius:6px;margin-bottom:.3rem;background:#fff">
                    <span style="font-weight:600;color:#374151">${TIPOS_DOC[d.tipo_documento]||d.tipo_documento}</span>
                    <div style="display:flex;gap:.3rem">
                        ${d.es_pdf
                            ? `<button class="btn btn-info btn-sm" style="font-size:.7rem;padding:.15rem .4rem" onclick="verPdfDoc('${d.url_ver}','${TIPOS_DOC[d.tipo_documento]||d.tipo_documento}')">👁 Ver</button>`
                            : `<a href="${d.url_ver}" target="_blank" class="btn btn-info btn-sm" style="font-size:.7rem;padding:.15rem .4rem">👁 Ver</a>`
                        }
                        <a href="${d.url_descargar}" class="btn btn-secondary btn-sm" style="font-size:.7rem;padding:.15rem .4rem">⬇</a>
                    </div>
                </div>`).join('');
        }).catch(()=>{ const l=document.getElementById('editarDocsList'); if(l) l.textContent='Error al cargar.'; });
}


// ── Ver detalle completo ─────────────────────────────────────────────────────
function verDetalle(id){
    abrirModal('modalDetalle');
    document.getElementById('detalleCuerpo').innerHTML = '<div style="text-align:center;padding:2rem;color:#94a3b8">⏳ Cargando...</div>';

    fetch(`/admin/incapacidades/${id}/show`)
        .then(r=>r.json()).then(data=>{
            const inc = data.incapacidad;
            const cl  = data.cliente;

            // Header
            const nombre = cl ? nombreOracion(`${cl.primer_nombre||''} ${cl.primer_apellido||''}`) : inc.cedula_usuario;
            document.getElementById('detalleTitle').textContent = `🏥 Incapacidad #${inc.id} — ${nombre}`;
            document.getElementById('detalleSubtitle').innerHTML =
                `Cédula: ${inc.cedula_usuario} ${data.empresa?`· Empresa: ${data.empresa}`:''} ` +
                `· Recibida: ${formatFechaLarga(inc.fecha_recibido)}`;

            const colClass = {verde:'sem-verde',amarillo:'sem-amarillo',rojo:'sem-rojo',gris:'sem-gris'}[data.semaforo]||'sem-gris';
            document.getElementById('detalleSemaforo').innerHTML =
                `<span class="semaforo ${colClass}">${data.icono} ${data.dias_gestion} días sin gestión</span>`;

            // Alerta 180 días
            const al180 = data.alerta_180 ? `<div class="alerta-180" style="margin-bottom:.8rem">⚠️ Esta familia suma ${data.familia_dias} días — supera el límite de 180 días EPS. Debe radicar al AFP/Pensión.</div>` : '';

            // Resumen de la familia
            const resumenFam = data.num_prorrogas > 0
                ? `<div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:.8rem">
                    <span class="badge badge-primary">📁 ${data.num_prorrogas} prórroga(s)</span>
                    <span class="badge badge-info">📅 Total familia: ${data.familia_dias} días</span>
                   </div>` : '';

            // Gestiones (timeline) — se pintan aparte porque la pestaña filtra
            // por miembro de la familia. Todo lo que necesita el render vive en
            // estas globales; el HTML lo arma renderGestiones().
            GEST.familia   = data.gestiones_familia || inc.gestiones || [];
            GEST.miembros  = data.familia_miembros || [];
            GEST.incActivo = inc.id;
            GEST.reversable = data.gestion_reversable_id || null;
            GEST.filtro    = 'todas';

            // Prórrogas — tabla con original + prórrogas
            const _bcMap = {success:'#d1fae5;color:#065f46',danger:'#fee2e2;color:#991b1b',warning:'#fef3c7;color:#92400e',primary:'#dbeafe;color:#1e40af',info:'#cffafe;color:#155e75',secondary:'#f1f5f9;color:#475569'};
            const _tdStyle = 'padding:.55rem .7rem';
            const _renderFila = (esOrig, id, numLabel, tipo, dias, fIni, fFin, tipoEnt, entNom, badgeHtml, valEsp, botones) =>
                `<tr style="border-bottom:1px solid ${esOrig?'#bbf7d0':'#f1f5f9'};background:${esOrig?'#f0fdf4':''};transition:background .1s" onmouseover="this.style.background='${esOrig?'#dcfce7':'#f8fafc'}'" onmouseout="this.style.background='${esOrig?'#f0fdf4':''}'"> 
                    <td style="${_tdStyle};font-weight:700;color:${esOrig?'#059669':'#2563eb'}">${numLabel}</td>
                    <td style="${_tdStyle};color:#374151">${tipo}</td>
                    <td style="${_tdStyle};text-align:center;font-weight:600">${dias}d</td>
                    <td style="${_tdStyle};font-size:.75rem;color:#64748b;white-space:nowrap">${formatFechaLarga(fIni)}<br>→ ${formatFechaLarga(fFin)}</td>
                    <td style="${_tdStyle};font-size:.75rem">${TIPOS_ENTIDAD[tipoEnt]||tipoEnt?.toUpperCase()}<br><span style="color:#64748b">${entNom||''}</span></td>
                    <td style="${_tdStyle}">${badgeHtml}</td>
                    <td style="${_tdStyle};text-align:right;font-weight:600;color:#059669">${valEsp?'$'+Number(valEsp).toLocaleString('es-CO'):'—'}</td>
                    <td style="${_tdStyle};text-align:center"><div style="display:flex;gap:.3rem;justify-content:center;flex-wrap:wrap">${botones}</div></td>
                </tr>`;
            const _badge = (lbl,bg,col) => `<span style="display:inline-block;padding:.15rem .5rem;border-radius:999px;font-size:.68rem;font-weight:600;background:${bg};color:${col}">${lbl}</span>`;
            // Fila original
            const _ecOrig = colorEstado(inc.estado);
            const _bcO = (_bcMap[_ecOrig]||_bcMap.secondary).split(';');
            const _filaOrig = _renderFila(true,inc.id,'🏥 Original',labelTipo(inc.tipo_incapacidad),inc.dias_incapacidad,inc.fecha_inicio,inc.fecha_terminacion,inc.tipo_entidad,inc.entidad_nombre,_badge(labelEstado(inc.estado),_bcO[0],_bcO[1]||'color:#475569'),inc.valor_esperado,
                `<button class="btn btn-warning btn-sm" onclick="cerrarModal('modalDetalle');abrirModalEditar(${inc.id})" title="Editar">✏️</button>`);
            const prorrogas = (inc.prorrogas||[]).length === 0 ? '<p style="color:#94a3b8;font-size:.82rem">Sin prórrogas.</p>' : `
            <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse;font-size:.8rem">
                <thead><tr style="background:#f8fafc;border-bottom:2px solid #e2e8f0">
                    <th style="padding:.5rem .7rem;font-size:.7rem;text-transform:uppercase;color:#64748b;white-space:nowrap">#</th>
                    <th style="padding:.5rem .7rem;font-size:.7rem;text-transform:uppercase;color:#64748b">Tipo</th>
                    <th style="padding:.5rem .7rem;text-align:center;font-size:.7rem;text-transform:uppercase;color:#64748b">Días</th>
                    <th style="padding:.5rem .7rem;font-size:.7rem;text-transform:uppercase;color:#64748b">Período</th>
                    <th style="padding:.5rem .7rem;font-size:.7rem;text-transform:uppercase;color:#64748b">Entidad</th>
                    <th style="padding:.5rem .7rem;font-size:.7rem;text-transform:uppercase;color:#64748b">Estado</th>
                    <th style="padding:.5rem .7rem;text-align:right;font-size:.7rem;text-transform:uppercase;color:#64748b">Valor Esp.</th>
                    <th style="padding:.5rem .7rem;text-align:center;font-size:.7rem;text-transform:uppercase;color:#64748b">Acciones</th>
                </tr></thead>
                <tbody>
                ${_filaOrig}
                ${(inc.prorrogas||[]).map((p)=>{
                    const ec=colorEstado(p.estado); const bcp=(_bcMap[ec]||_bcMap.secondary).split(';');
                    // El número abre el detalle de la prórroga: sus gestiones (y
                    // el botón de reversar) viven ahí, no en las del original.
                    const numLink = `<a href="javascript:void(0)" onclick="verDetalle(${p.id})"
                        style="color:#2563eb;text-decoration:underline" title="Ver detalle y gestiones de esta prórroga">${p.numero_proroga}</a>`;
                    return _renderFila(false,p.id,numLink,labelTipo(p.tipo_incapacidad),p.dias_incapacidad,p.fecha_inicio,p.fecha_terminacion,p.tipo_entidad,p.entidad_nombre,_badge(labelEstado(p.estado),bcp[0],bcp[1]||'color:#475569'),p.valor_esperado,
                        `<button class="btn btn-info btn-sm" onclick="registrarGestion(${p.id})" title="Gestión">📞</button><button class="btn btn-primary btn-sm" onclick="registrarPago(${p.id})" title="Anticipo / Préstamo">💰</button><button class="btn btn-warning btn-sm" onclick="cerrarModal('modalDetalle');abrirModalEditar(${p.id})" title="Editar">✏️</button><button class="btn btn-secondary btn-sm" onclick="recalcularValor(${p.id})" title="Recalcular Valor">🔄</button>`);
                }).join('')}
                </tbody></table></div>`;

            // Valor esperado — solo lo pendiente (no pagado)
            const _estadosPag = ['pagada','pagado_afiliado','pagada_afiliado','pagada_razon_social','cierre_exitoso','anulada'];
            let _valPending = 0;
            if (!_estadosPag.includes(inc.estado)) _valPending += Number(inc.valor_esperado||0);
            (inc.prorrogas||[]).forEach(p => { if (!_estadosPag.includes(p.estado)) _valPending += Number(p.valor_esperado||0); });
            // Los días que dos miembros comparten se pagan una sola vez: sumarlos
            // dos veces sería esperar una plata que la entidad no va a girar.
            const _descCruce = Number(data.descuento_cruce||0);
            _valPending = Math.max(0, _valPending - _descCruce);
            const val = _valPending > 0 ? `$${_valPending.toLocaleString('es-CO')}` : '—';

            const avisoCruce = (data.dias_cruzados > 0) ? `
                <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:.6rem .9rem;
                            margin-bottom:.75rem;font-size:.8rem;color:#1e40af;display:flex;gap:.6rem;align-items:flex-start">
                    <span style="font-size:1.05rem">📌</span>
                    <div><strong>${data.dias_cruzados} día(s) compartido(s)</strong> entre incapacidades de esta familia
                    ${(data.pares_cruce||[]).map(p=>`(#${p.incapacidades[0]} y #${p.incapacidades[1]}: ${p.dias}d)`).join(' ')}.
                    La entidad los paga en una sola de las dos, así que
                    ${_descCruce > 0
                        ? `se descontaron <strong>$${_descCruce.toLocaleString('es-CO')}</strong> del valor esperado total.`
                        : `no suman al valor esperado (las que los comparten ya están pagadas).`}</div>
                </div>` : '';
            const totalPagado = data.total_pagado || 0;
            const prorrogasPend = data.prorrogas_pendientes || 0;

            document.getElementById('detalleCuerpo').innerHTML = `
                ${al180}${resumenFam}
                <div class="tabs">
                    <button class="tab-btn active" onclick="switchTab(this,'tabInfo')">📋 Datos</button>
                    <button class="tab-btn" onclick="switchTab(this,'tabDocumentos');cargarDocsFamilia(${inc.id})">📎 Documentos</button>
                    ${data.num_prorrogas>0?`<button class="tab-btn" onclick="switchTab(this,'tabProrrogas')">📄 Prórrogas (${data.num_prorrogas})</button>`:''}
                    <button class="tab-btn" onclick="switchTab(this,'tabGestiones')">📞 Gestiones (${GEST.familia.length})</button>
                    <button class="tab-btn" onclick="switchTab(this,'tabPago')">💰 Anticipo/Préstamo</button>
                </div>

                <div id="tabInfo" class="tab-pane active">
                    ${avisoCruce}
                    ${prorrogasPend>0?`<div style="background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:.6rem .9rem;margin-bottom:.75rem;display:flex;align-items:center;gap:.6rem;font-size:.82rem;color:#92400e"><span style="font-size:1.1rem">⚠️</span><div><strong>${prorrogasPend} prórroga(s) activa(s)</strong> pendiente(s) de gestión — revisa la pestaña <em>Prórrogas</em> para ver el detalle.</div></div>`:''}
                    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.65rem;margin-bottom:.8rem">
                        <div style="background:#eff6ff;border-radius:10px;padding:.7rem .85rem;border:1px solid #bfdbfe">
                            <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;color:#3b82f6;letter-spacing:.06em;margin-bottom:.25rem">🏷️ Tipo</div>
                            <div style="font-size:.85rem;font-weight:600;color:#1e3a8a">${labelTipo(inc.tipo_incapacidad)}</div>
                        </div>
                        <div style="background:#f0fdf4;border-radius:10px;padding:.7rem .85rem;border:1px solid #bbf7d0">
                            <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;color:#059669;letter-spacing:.06em;margin-bottom:.25rem">🏦 Entidad</div>
                            <div style="font-size:.85rem;font-weight:600;color:#065f46">${TIPOS_ENTIDAD[inc.tipo_entidad]||inc.tipo_entidad?.toUpperCase()||'—'} — <strong>${inc.entidad_nombre||'N/A'}</strong></div>
                        </div>
                        <div style="background:#faf5ff;border-radius:10px;padding:.7rem .85rem;border:1px solid #e9d5ff">
                            <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;color:#7c3aed;letter-spacing:.06em;margin-bottom:.25rem">📅 Días totales</div>
                            <div style="font-size:1.4rem;font-weight:800;color:#4c1d95;line-height:1">${data.familia_dias||inc.dias_incapacidad}</div>
                            ${data.num_prorrogas>0?`<div style="font-size:.65rem;color:#7c3aed">Original: ${inc.dias_incapacidad}d + ${data.num_prorrogas} prórr.</div>`:''}
                        </div>
                        <div style="background:#fff7ed;border-radius:10px;padding:.7rem .85rem;border:1px solid #fed7aa">
                            <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;color:#ea580c;letter-spacing:.06em;margin-bottom:.25rem">🗓️ Período</div>
                            <div style="font-size:.78rem;font-weight:600;color:#7c2d12">${formatFechaLarga(inc.fecha_inicio)}</div>
                            <div style="font-size:.72rem;color:#9a3412">→ ${formatFechaLarga(inc.fecha_terminacion)}</div>
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.65rem;margin-bottom:.8rem">
                        <div style="background:#f8fafc;border-radius:10px;padding:.65rem .85rem;border:1px solid #e2e8f0">
                            <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;color:#64748b;letter-spacing:.06em;margin-bottom:.2rem">📋 Radicado</div>
                            <div style="font-size:.83rem;color:${inc.numero_radicado?'#1e293b':'#94a3b8'}">${inc.numero_radicado||'Sin radicar'}</div>
                            ${inc.fecha_radicado?`<div style="font-size:.72rem;color:#64748b">${formatFechaLarga(inc.fecha_radicado)}</div>`:''}
                        </div>
                        <div style="background:#f8fafc;border-radius:10px;padding:.65rem .85rem;border:1px solid #e2e8f0">
                            <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;color:#64748b;letter-spacing:.06em;margin-bottom:.2rem">🏢 Razón Social</div>
                            <div style="font-size:.83rem;font-weight:600;color:#1e293b">${inc.razon_social_nombre||'—'}</div>
                        </div>
                        <div style="background:#f0fdf4;border-radius:10px;padding:.65rem .85rem;border:1px solid #bbf7d0">
                            <div style="font-size:.65rem;font-weight:700;text-transform:uppercase;color:#059669;letter-spacing:.06em;margin-bottom:.2rem">💰 Valor Esperado${data.num_prorrogas>0?' (Total)':''}</div>
                            <div style="font-size:1.05rem;font-weight:800;color:#059669">${val}</div>
                            ${_descCruce>0?`<div style="font-size:.65rem;color:#1e40af">−$${_descCruce.toLocaleString('es-CO')} por ${data.dias_cruzados} día(s) compartido(s)</div>`:''}
                            ${data.num_prorrogas>0&&totalPagado>0?`<div style="font-size:.65rem;color:#64748b">Pagado orig.: $${Number(totalPagado).toLocaleString('es-CO')}</div>`:''}
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:.5rem;background:#f8fafc;padding:.75rem;border-radius:10px;border:1px solid #e2e8f0;margin-bottom:.6rem">
                        <div><span style="font-size:.63rem;color:#64748b;display:block;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.2rem">Estado</span><span class="badge badge-${colorEstado(inc.estado)}">${labelEstado(inc.estado)}</span></div>
                        <div><span style="font-size:.63rem;color:#64748b;display:block;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.2rem">Pago</span><span class="badge badge-${colorEstadoPago(inc.estado_pago)}">${labelEstadoPago(inc.estado_pago)}</span></div>
                        <div><span style="font-size:.63rem;color:#64748b;display:block;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.2rem">Recibido</span><span style="font-size:.82rem">${formatFechaLarga(inc.fecha_recibido)}</span></div>
                        <div><span style="font-size:.63rem;color:#64748b;display:block;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.2rem">Diagnóstico</span><span style="font-size:.82rem;color:${inc.diagnostico?'#374151':'#94a3b8'}">${inc.diagnostico||'—'}</span></div>
                        ${inc.prorroga?'<div><span style="font-size:.72rem;color:#2563eb;font-weight:700;background:#eff6ff;padding:.2rem .5rem;border-radius:6px;display:inline-block">✓ Doc. prórroga</span></div>':''}
                    </div>
                    ${inc.observacion?`<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:.6rem .85rem;font-size:.82rem;color:#92400e;margin-bottom:.6rem"><strong>📝 Observación:</strong> ${inc.observacion}</div>`:''}
                    ${data.anulacion?`<div style="background:#f1f5f9;border:1px solid #cbd5e1;border-radius:8px;padding:.6rem .85rem;font-size:.82rem;color:#334155;margin-bottom:.6rem">
                        <strong>⛔ Anulada:</strong> ${data.anulacion.motivo_label}
                        ${data.anulacion.observacion?`<div style="margin-top:.25rem">${data.anulacion.observacion}</div>`:''}
                        <div style="font-size:.72rem;color:#64748b;margin-top:.3rem">
                            Por ${data.anulacion.por||'—'} el ${data.anulacion.en||'—'}
                            ${data.anulacion.estado_previo_label?` · antes estaba en ${data.anulacion.estado_previo_label}`:''}
                        </div>
                    </div>`:''}
                    <div style="display:flex;gap:.5rem;margin-top:1rem;flex-wrap:wrap;padding-top:.75rem;border-top:1px solid #f1f5f9">
                        ${inc.estado === 'anulada' ? `
                        <button class="btn btn-primary btn-sm" onclick="abrirModalReabrir(${inc.id})"
                                title="Devolver la incapacidad al flujo, en el estado que tenía antes de anularla">↩️ Reabrir</button>
                        <button class="btn btn-warning btn-sm" onclick="cerrarModal('modalDetalle'); abrirModalEditar(${inc.id})">✏️ Editar Incapacidad</button>
                        ` : `
                        <button class="btn btn-primary btn-sm" onclick="registrarGestion(${inc.id})">📞 Nueva Gestión</button>
                        <button class="btn btn-warning btn-sm" onclick="cerrarModal('modalDetalle'); abrirModalEditar(${inc.id})">✏️ Editar Incapacidad</button>
                        <button class="btn btn-secondary btn-sm" onclick="cerrarModal('modalDetalle'); abrirModalProroga(${inc.id})">➕ Agregar Prórroga</button>
                        ${(!inc.incapacidad_padre_id && (inc.prorrogas||[]).length === 0)
                            ? `<button class="btn btn-secondary btn-sm" onclick="abrirModalUnir(${inc.id})"
                                       title="Si esta incapacidad quedó suelta y en realidad continúa otra">🔗 Unir a otra incapacidad</button>` : ''}
                        <button class="btn btn-danger btn-sm" style="margin-left:auto" onclick="abrirModalAnular(${inc.id})"
                                title="Cerrarla sin trámite: el cliente no envió papeles, se creó por error...">⛔ Anular</button>
                        `}
                    </div>
                </div>

                <div id="tabGestiones" class="tab-pane">
                    <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;margin-bottom:.8rem">
                        <button class="btn btn-primary btn-sm" onclick="registrarGestion(${inc.id})">📞 Nueva Gestión</button>
                        <select id="gestionesFiltro" onchange="filtrarGestiones(this.value)"
                                style="display:none;flex:1 1 20rem;min-width:0;border:1px solid #cbd5e1;border-radius:8px;padding:.38rem .65rem;font-size:.8rem;background:#f8fafc"></select>
                    </div>
                    <div class="timeline" id="gestionesTimeline"></div>
                </div>

                ${data.num_prorrogas>0?`<div id="tabProrrogas" class="tab-pane">${prorrogas}</div>`:''}

                <div id="tabPago" class="tab-pane">
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.75rem;margin-bottom:1rem">
                        <div class="kpi"><div class="num" style="font-size:.95rem">${labelEstadoPago(inc.estado_pago)}</div><div class="lbl">Estado Pago/Anticipo</div></div>
                        <div class="kpi"><div class="num" style="font-size:1.1rem">${inc.valor_pago?'$'+Number(inc.valor_pago).toLocaleString('es-CO'):'—'}</div><div class="lbl">Total Anticipado</div></div>
                        <div class="kpi"><div class="num" style="font-size:1.1rem">${val}</div><div class="lbl">Valor Esperado</div></div>
                        <div class="kpi"><div class="num" style="font-size:.95rem">${inc.pagado_a==='cliente'?'Afiliado':inc.pagado_a==='empresa'?'Empresa':'—'}</div><div class="lbl">Pagado a</div></div>
                    </div>
                    ${inc.detalle_pago?`<p style="font-size:.82rem;color:#374151"><strong>Detalle:</strong> ${inc.detalle_pago}</p>`:''}
                    <button class="btn btn-success" onclick="registrarPago(${inc.id})" style="margin-top:.8rem">💰 Registrar Anticipo / Préstamo al Afiliado</button>
                </div>

                <div id="tabDocumentos" class="tab-pane">
                    <div id="docsFamiliaContainer" style="min-height:80px;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:.85rem">
                        Haz clic en la pestaña Documentos para cargar.
                    </div>
                </div>`;

            renderGestiones();

            // Guardar ID activo y el detalle en curso (lo usa el modal de unir)
            document.getElementById('modalDetalle').dataset.incId = id;
            _detalleActual = data;
        });
}

// ── Pestaña Gestiones: timeline de toda la familia con filtro por miembro ──
// Las gestiones marcadas "aplica a familia" cubren a todos los miembros, así
// que salen en cualquier filtro (no solo en el de la incapacidad donde se
// registraron) y además tienen su propio chip.
const GEST = {familia: [], miembros: [], incActivo: null, reversable: null, filtro: 'todas'};

function gestionesVisibles(filtro){
    const gs = GEST.familia || [];
    if (filtro === 'todas')   return gs;
    if (filtro === 'familia') return gs.filter(g => !!g.aplica_a_familia);
    return gs.filter(g => String(g.incapacidad_id) === String(filtro) || !!g.aplica_a_familia);
}

function filtrarGestiones(filtro){
    GEST.filtro = filtro;
    renderGestiones();
}

function renderGestiones(){
    const cont = document.getElementById('gestionesTimeline');
    const sel  = document.getElementById('gestionesFiltro');
    if (!cont) return;

    // El select solo tiene sentido si la familia tiene más de un miembro.
    if (sel) {
        const miembros = GEST.miembros || [];
        if (miembros.length < 2) {
            sel.style.display = 'none';
        } else {
            const nFam = (GEST.familia||[]).filter(g => !!g.aplica_a_familia).length;
            let opts = opcionGestion('todas', `📁 Todas · ${GEST.familia.length} gest.`);
            miembros.forEach(m => {
                const icono = m.es_padre ? '🏥' : '📄';
                // Ojo: sqlsrv devuelve los ids como string en el JSON, así que
                // toda comparación de ids va por String().
                const abierta = String(m.id) === String(GEST.incActivo) ? ' · abierta' : '';
                // El número de prórroga solo no dice cuál es: van también los
                // días, el período y el valor, que es como se reconocen.
                opts += opcionGestion(m.id, `${icono} ${m.label} (${periodoMiembro(m)}) · `
                    + `${gestionesVisibles(m.id).length} gest.${abierta}`);
            });
            if (nFam) opts += opcionGestion('familia', `👨‍👩‍👦 Solo gestiones de familia · ${nFam} gest.`);
            sel.innerHTML = opts;
            sel.style.display = '';
        }
    }

    const visibles = gestionesVisibles(GEST.filtro);
    const varios   = (GEST.miembros || []).length > 1;

    cont.innerHTML = visibles.map(g => {
        const revertida = !!g.revertida_at;
        // El botón solo se pinta en la gestión que el backend declaró
        // reversable, y solo sobre la incapacidad que está abierta: reversar
        // una gestión de otro miembro se hace abriendo ese miembro.
        const btnRevertir = (PUEDE_REVERSAR && !revertida && g.id === GEST.reversable
                             && String(g.incapacidad_id) === String(GEST.incActivo))
            ? `<button class="btn btn-danger btn-sm" style="margin-top:.4rem;font-size:.7rem"
                       onclick="abrirModalReversion(${GEST.incActivo})"
                       title="Deshacer este cambio de estado y anular lo que generó">↩️ Reversar estado</button>`
            : '';
        const avisoRevertida = revertida
            ? `<div style="margin-top:.35rem;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:.3rem .5rem;font-size:.7rem;color:#991b1b">
                   ↩️ Reversada el ${formatFechaLarga(g.revertida_at)}${g.revertida_motivo?` — ${g.revertida_motivo}`:''}
               </div>`
            : '';
        // De qué incapacidad viene: solo si hay más de una en la familia.
        const origen = varios
            ? `<span title="Esta gestión se registró en: ${g.origen_label}"
                     style="background:#f1f5f9;color:#475569;border-radius:999px;padding:.1rem .45rem;font-size:.65rem;font-weight:700;margin-left:.35rem">${g.origen_label}</span>`
            : '';
        return `
        <div class="timeline-item"${revertida?' style="opacity:.6"':''}>
            <div class="tl-dot">${g.es_reversion?'↩️':iconoTipoGestion(g.tipo)}</div>
            <div class="tl-content">
                <div class="tl-tipo">${g.tipo}${g.aplica_a_familia?' <span style="color:#d97706">· Familia</span>':''}${origen}</div>
                <div class="tl-tramite"${revertida?' style="text-decoration:line-through"':''}>${g.tramite || g.respuesta || '—'}</div>
                <div class="tl-meta">${g.user?.nombre||'Sistema'} · ${formatFechaLarga(g.created_at)} ${g.fecha_recordar?`· 🔔 Recordar: ${formatFechaLarga(g.fecha_recordar)}`:''}${g.estado_resultado?` · Estado: ${labelEstado(g.estado_resultado)}`:''}</div>
                ${avisoRevertida}${btnRevertir}
            </div>
        </div>`;
    }).join('') || '<div style="color:#94a3b8;font-size:.82rem">Sin gestiones para este filtro.</div>';
}

// "4d del 13-feb al 16-feb por $233.454" — lo que distingue a una prórroga de
// la siguiente cuando todas se llaman igual.
function periodoMiembro(m){
    const corta = f => {
        if (!f) return '—';
        const meses = ['ene','feb','mar','abr','may','jun','jul','ago','sep','oct','nov','dic'];
        const d = new Date(String(f).substring(0,10)+'T12:00:00');
        return `${d.getDate()}-${meses[d.getMonth()]}`;
    };
    const valor = Number(m.valor_esperado||0) > 0
        ? ` por $${Math.round(Number(m.valor_esperado)).toLocaleString('es-CO')}` : '';
    return `${m.dias_incapacidad}d del ${corta(m.fecha_inicio)} al ${corta(m.fecha_terminacion)}${valor}`;
}

function opcionGestion(valor, label){
    const sel = String(GEST.filtro) === String(valor) ? ' selected' : '';
    return `<option value="${valor}"${sel}>${label}</option>`;
}

// ── Unir una incapacidad suelta a la familia de otra ─────────────────────────
// Existe porque el aviso al crear solo previene de aquí en adelante: lo que ya
// quedó partido no había forma de juntarlo sin tocar la BD a mano.
// ── Vínculo familiar desde el modal de editar ───────────────────────────────
// Una prórroga se puede sacar de su familia (desligar) o mover a otra (ligar).
// Hace falta porque las familias se arman solas —el encadenamiento del legacy y
// el aviso de "vecinas" al crear— y a veces pegan dos que no son continuas.

function _pintarVinculoFamilia(inc, data){
    const box = document.getElementById('vinculoFamiliaBox');
    const txt = document.getElementById('vinculoFamiliaTexto');
    const btnDes = document.getElementById('btnDesligarProrroga');
    if(!box || !txt) return;

    const esProrroga = !!inc.incapacidad_padre_id;
    const tieneHijas = Number(data?.num_prorrogas || 0) > 0;

    // Una original CON prórrogas no se mueve: habría que arrastrar la familia
    // entera. Se desligan las hijas primero, como ya valida el servidor.
    if(!esProrroga && tieneHijas){
        txt.innerHTML = `Esta es la <strong>original</strong> y tiene
            <strong>${data.num_prorrogas}</strong> prórroga(s) colgando.
            Para reorganizar la familia, edita cada prórroga y desligala desde ahí.`;
        if(btnDes) btnDes.style.display = 'none';
        box.querySelector('button[onclick="ligarAOtraDesdeEditar()"]').style.display = 'none';
        box.style.display = 'block';
        return;
    }

    box.querySelector('button[onclick="ligarAOtraDesdeEditar()"]').style.display = '';

    if(esProrroga){
        txt.innerHTML = `Es la <strong>prórroga ${inc.numero_proroga}</strong> de la
            incapacidad <strong>#${inc.incapacidad_padre_id}</strong>.
            Si no son continuas, deslígala y quedará independiente.`;
        if(btnDes) btnDes.style.display = '';
    } else {
        txt.innerHTML = `Es una incapacidad <strong>independiente</strong>.
            Si en realidad continúa otra de la misma persona, ligala como prórroga.`;
        if(btnDes) btnDes.style.display = 'none';
    }

    box.style.display = 'block';
}

function ligarAOtraDesdeEditar(){
    if(!_editandoInc) return;
    // El modal de unir se dibuja encima del de editar; al confirmar se recarga.
    abrirModalUnir(_editandoInc.id, _editandoInc);
}

function desligarProrrogaActual(){
    const inc = _editandoInc;
    if(!inc || !inc.incapacidad_padre_id) return;

    if(!confirm(`¿Desligar la prórroga ${inc.numero_proroga} de la incapacidad #${inc.incapacidad_padre_id}?\n\n`
              + `Quedará como una incapacidad independiente y su valor esperado se recalcula `
              + `(salvo que ya esté pagada).`)) return;

    const btn = document.getElementById('btnDesligarProrroga');
    if(btn){ btn.disabled = true; btn.textContent = 'Desligando...'; }

    fetch(`/admin/incapacidades/${inc.id}/desligar-prorroga`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': TOKEN, 'Accept': 'application/json' },
    })
    .then(async r => ({ ok: r.ok, data: await r.json().catch(()=>({})) }))
    .then(({ok, data}) => {
        if(!ok || !data.ok){
            alert(data.message || 'No se pudo desligar.');
            if(btn){ btn.disabled = false; btn.textContent = '✂️ Desligar'; }
            return;
        }
        alert(data.message);
        location.reload();
    })
    .catch(e => {
        alert('Error de red: ' + e.message);
        if(btn){ btn.disabled = false; btn.textContent = '✂️ Desligar'; }
    });
}

function abrirModalUnir(incId, incData){
    // incData llega cuando se abre desde el modal de editar, donde
    // _detalleActual puede ser de otra incapacidad (o no existir todavía).
    const inc = incData || _detalleActual?.incapacidad;
    if(!inc) return;

    fetch(`/admin/incapacidades/api/vecinas?cedula=${encodeURIComponent(inc.cedula_usuario)}`
          + `&fecha_inicio=${(inc.fecha_inicio||'').substring(0,10)}&excluir_id=${incId}&todas=1`,
          { headers: { 'Accept': 'application/json' } })
        .then(r => r.ok ? r.json() : Promise.reject(new Error('no se pudo consultar')))
        .then(d => pintarModalUnir(incId, inc, d.vecinas || []))
        .catch(e => alert('Error al buscar incapacidades de esta persona: ' + e.message));
}

function pintarModalUnir(incId, inc, vecinas){
    const sugerida = v => ['continua','mismo_dia','solapa'].includes(v.relacion);
    const nota = v => ({
        continua:  '✓ termina justo el día antes de esta',
        mismo_dia: '✓ termina el mismo día en que esta empieza (ese día se paga una sola vez)',
        solapa:    `✓ se cruza ${Math.abs(v.gap)+1} día(s) con esta (esos días se pagan una sola vez)`,
    }[v.relacion] || '');

    const filas = vecinas.length ? vecinas.map(v => `
        <div style="display:flex;justify-content:space-between;align-items:center;gap:.6rem;flex-wrap:wrap;
                    border:1px solid ${sugerida(v)?'#bfdbfe':'#e2e8f0'};background:${sugerida(v)?'#eff6ff':'#fff'};
                    border-radius:8px;padding:.5rem .7rem;margin-bottom:.4rem">
            <div style="font-size:.78rem;color:#334155">
                <strong>#${v.id}</strong> ${v.label} · ${v.dias_incapacidad}d del ${v.fecha_inicio} al ${v.fecha_terminacion}<br>
                <span style="color:#64748b">${(v.tipo_entidad||'').toUpperCase()} ${v.entidad_nombre||''} · ${labelEstado(v.estado)}</span>
                ${sugerida(v)?`<span style="color:#1e40af;font-weight:600"> — ${nota(v)}</span>`:''}
            </div>
            <button class="btn btn-primary btn-sm" style="font-size:.72rem"
                    onclick="confirmarUnion(${incId}, ${v.padre_id}, '#${v.id}')">Unir a esta</button>
        </div>`).join('')
        : '<p style="color:#94a3b8;font-size:.82rem">Esta persona no tiene otras incapacidades registradas.</p>';

    const html = `
    <div class="modal-header">
        <div><h3>🔗 Unir como prórroga</h3></div>
        <button class="btn-close-modal" onclick="cerrarModalUnir()">✕</button>
    </div>
    <div class="modal-body">
        <p style="font-size:.82rem;color:#475569;margin-bottom:.7rem">
            La incapacidad <strong>#${incId}</strong> (${inc.dias_incapacidad}d desde
            ${(inc.fecha_inicio||'').substring(0,10)}) pasará a ser prórroga de la que elijas.
            ${inc.incapacidad_padre_id
                ? `Hoy cuelga de la <strong>#${inc.incapacidad_padre_id}</strong>: se mueve de familia y esa queda renumerada.`
                : ''}
            El valor esperado se recalcula, salvo que ya esté pagada.
        </p>
        ${filas}
    </div>`;

    let overlay = document.getElementById('modalUnir');
    if(!overlay){
        overlay = document.createElement('div');
        overlay.id = 'modalUnir';
        overlay.className = 'modal-overlay';
        overlay.innerHTML = `<div class="modal" style="max-width:620px">${html}</div>`;
        document.body.appendChild(overlay);
    } else {
        overlay.querySelector('.modal').innerHTML = html;
    }
    overlay.classList.add('open');
}

function cerrarModalUnir(){ document.getElementById('modalUnir')?.classList.remove('open'); }

function confirmarUnion(incId, padreId, etiqueta){
    if(!confirm(`¿Unir la incapacidad #${incId} como prórroga de ${etiqueta}?`)) return;

    fetch(`/admin/incapacidades/${incId}/unir-prorroga`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': TOKEN, 'Accept': 'application/json' },
        body: JSON.stringify({ padre_id: padreId }),
    })
    .then(async r => ({ ok: r.ok, data: await r.json().catch(()=>({})) }))
    .then(({ok, data}) => {
        if(!ok || !data.ok){ alert(data.message || 'No se pudo unir.'); return; }
        alert(data.message);
        cerrarModalUnir();
        location.reload();
    })
    .catch(e => alert('Error de red: ' + e.message));
}

// ── Anular / Reabrir ────────────────────────────────────────────────────────
// Anular es el cierre administrativo de una incapacidad que nunca entró a
// trámite. No pasa por el selector de estado de la gestión porque necesita un
// motivo obligatorio y, si la incapacidad tiene prórrogas abiertas, decidir
// qué hacer con ellas.

function abrirModalAnular(incId){
    const data = _detalleActual;
    const inc  = data?.incapacidad;
    if(!inc) return;

    const pendientes = Number(data.prorrogas_anulables || 0);
    const opciones = Object.entries(MOTIVOS_ANULACION)
        .map(([k,v]) => `<option value="${k}">${v}</option>`).join('');

    const html = `
    <div class="modal-header">
        <div><h3>⛔ Anular incapacidad #${incId}</h3></div>
        <button class="btn-close-modal" onclick="cerrarModalAnular()">✕</button>
    </div>
    <div class="modal-body">
        <p style="font-size:.82rem;color:#475569;margin-bottom:.8rem">
            Sale del listado de activas y deja de contarse en <strong>Sin gestión</strong>
            y en el valor por cobrar. Queda accesible desde el chip
            <strong>Anuladas</strong> y se puede reabrir cuando quieras.
        </p>

        <label style="display:block;font-size:.72rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.25rem">Motivo</label>
        <select id="anularMotivo" onchange="_anularToggleObs()"
                style="width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:.45rem .65rem;font-size:.83rem;background:#fff;margin-bottom:.7rem">
            ${opciones}
        </select>

        <label style="display:block;font-size:.72rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.25rem">
            Observación <span id="anularObsReq" style="color:#dc2626;display:none">(obligatoria)</span>
        </label>
        <textarea id="anularObs" rows="3" maxlength="500" placeholder="Detalle de por qué se anula (opcional, salvo en 'Otro')"
                  style="width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:.45rem .65rem;font-size:.83rem;resize:vertical"></textarea>

        ${pendientes > 0 ? `
        <label style="display:flex;align-items:flex-start;gap:.5rem;background:#fffbeb;border:1px solid #fde68a;
                      border-radius:8px;padding:.6rem .75rem;margin-top:.8rem;font-size:.82rem;color:#92400e;cursor:pointer">
            <input type="checkbox" id="anularProrrogas" checked style="margin-top:.15rem">
            <span>Anular también sus <strong>${pendientes}</strong> prórroga(s) abierta(s).
            Si lo dejas sin marcar, la original queda anulada y las prórrogas siguen activas por su cuenta.</span>
        </label>` : ''}

        <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem;padding-top:.8rem;border-top:1px solid #f1f5f9">
            <button class="btn btn-secondary btn-sm" onclick="cerrarModalAnular()">Cancelar</button>
            <button class="btn btn-danger btn-sm" id="btnConfirmarAnular" onclick="confirmarAnulacion(${incId})">⛔ Anular</button>
        </div>
    </div>`;

    _pintarModalSimple('modalAnular', html, 520);
}

function _anularToggleObs(){
    const esOtro = document.getElementById('anularMotivo')?.value === 'otro';
    const req = document.getElementById('anularObsReq');
    if(req) req.style.display = esOtro ? 'inline' : 'none';
}

function cerrarModalAnular(){ document.getElementById('modalAnular')?.classList.remove('open'); }

function confirmarAnulacion(incId){
    const motivo = document.getElementById('anularMotivo')?.value;
    const obs    = (document.getElementById('anularObs')?.value || '').trim();
    const prorr  = document.getElementById('anularProrrogas')?.checked || false;

    if(motivo === 'otro' && !obs){
        alert('Si el motivo es "Otro", explica en la observación por qué se anula.');
        document.getElementById('anularObs')?.focus();
        return;
    }

    const btn = document.getElementById('btnConfirmarAnular');
    if(btn){ btn.disabled = true; btn.textContent = 'Anulando...'; }

    fetch(`/admin/incapacidades/${incId}/anular`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': TOKEN, 'Accept': 'application/json' },
        body: JSON.stringify({ motivo, observacion: obs, incluir_prorrogas: prorr }),
    })
    .then(async r => ({ ok: r.ok, data: await r.json().catch(()=>({})) }))
    .then(({ok, data}) => {
        if(!ok || !data.ok){
            alert(data.message || 'No se pudo anular.');
            if(btn){ btn.disabled = false; btn.textContent = '⛔ Anular'; }
            return;
        }
        cerrarModalAnular();
        location.reload();
    })
    .catch(e => {
        alert('Error de red: ' + e.message);
        if(btn){ btn.disabled = false; btn.textContent = '⛔ Anular'; }
    });
}

function abrirModalReabrir(incId){
    const data = _detalleActual;
    const destino = data?.anulacion?.estado_previo_label || labelEstado('recibido');
    const anuladas = Number(data?.prorrogas_anuladas || 0);

    const html = `
    <div class="modal-header">
        <div><h3>↩️ Reabrir incapacidad #${incId}</h3></div>
        <button class="btn-close-modal" onclick="cerrarModalReabrir()">✕</button>
    </div>
    <div class="modal-body">
        <p style="font-size:.82rem;color:#475569;margin-bottom:.8rem">
            Vuelve al flujo en <strong>${destino}</strong>, que es donde estaba antes de anularse.
            La anulación no se borra: queda en el historial de gestiones junto con esta reapertura.
        </p>
        <label style="display:block;font-size:.72rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.25rem">Motivo de la reapertura (opcional)</label>
        <textarea id="reabrirObs" rows="3" maxlength="500" placeholder="Ej: el cliente por fin envió la epicrisis"
                  style="width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:.45rem .65rem;font-size:.83rem;resize:vertical"></textarea>
        ${anuladas > 0 ? `
        <label style="display:flex;align-items:flex-start;gap:.5rem;background:#f8fafc;border:1px solid #e2e8f0;
                      border-radius:8px;padding:.6rem .75rem;margin-top:.8rem;font-size:.82rem;color:#334155;cursor:pointer">
            <input type="checkbox" id="reabrirProrrogas" checked style="margin-top:.15rem">
            <span>Reabrir también sus <strong>${anuladas}</strong> prórroga(s) anulada(s).
            Cada una vuelve al estado que tenía antes de anularse.</span>
        </label>` : ''}
        <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1rem;padding-top:.8rem;border-top:1px solid #f1f5f9">
            <button class="btn btn-secondary btn-sm" onclick="cerrarModalReabrir()">Cancelar</button>
            <button class="btn btn-primary btn-sm" id="btnConfirmarReabrir" onclick="confirmarReapertura(${incId})">↩️ Reabrir</button>
        </div>
    </div>`;

    _pintarModalSimple('modalReabrir', html, 480);
}

function cerrarModalReabrir(){ document.getElementById('modalReabrir')?.classList.remove('open'); }

function confirmarReapertura(incId){
    const obs   = (document.getElementById('reabrirObs')?.value || '').trim();
    const prorr = document.getElementById('reabrirProrrogas')?.checked || false;
    const btn   = document.getElementById('btnConfirmarReabrir');
    if(btn){ btn.disabled = true; btn.textContent = 'Reabriendo...'; }

    fetch(`/admin/incapacidades/${incId}/reabrir`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': TOKEN, 'Accept': 'application/json' },
        body: JSON.stringify({ observacion: obs, incluir_prorrogas: prorr }),
    })
    .then(async r => ({ ok: r.ok, data: await r.json().catch(()=>({})) }))
    .then(({ok, data}) => {
        if(!ok || !data.ok){
            alert(data.message || 'No se pudo reabrir.');
            if(btn){ btn.disabled = false; btn.textContent = '↩️ Reabrir'; }
            return;
        }
        cerrarModalReabrir();
        location.reload();
    })
    .catch(e => {
        alert('Error de red: ' + e.message);
        if(btn){ btn.disabled = false; btn.textContent = '↩️ Reabrir'; }
    });
}

/** Crea (o reusa) un overlay de modal suelto, como hace pintarModalUnir. */
function _pintarModalSimple(id, html, maxWidth){
    let overlay = document.getElementById(id);
    if(!overlay){
        overlay = document.createElement('div');
        overlay.id = id;
        overlay.className = 'modal-overlay';
        overlay.innerHTML = `<div class="modal" style="max-width:${maxWidth}px">${html}</div>`;
        document.body.appendChild(overlay);
    } else {
        overlay.querySelector('.modal').innerHTML = html;
    }
    overlay.classList.add('open');
}

function switchTab(btn, tabId){
    btn.closest('.modal-body').querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    btn.closest('.modal-body').querySelectorAll('.tab-pane').forEach(p=>p.classList.remove('active'));
    const pane = document.getElementById(tabId);
    if(pane) pane.classList.add('active');
}

function iconoTipoGestion(tipo){
    const m={llamada:'📞',correo:'📧',whatsapp:'💬',portal:'🌐',radico:'📋',tutela:'⚖️',
             transcripcion_ips:'🏥',respuesta_entidad:'📩',autorizacion:'✅',
             liquidacion:'💰',pago_afiliado:'🏦',otro:'📝'};
    return m[tipo]||'📝';
}

// ── Gestión inline ───────────────────────────────────────────────────────────
function recalcularValor(incId) {
    if (!confirm('¿Seguro que deseas recalcular el valor esperado de esta incapacidad/prórroga?')) return;
    fetch(`/admin/incapacidades/${incId}/calcular-valor`, { method: 'POST', headers: {'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content} })
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                alert('Valor recalculado: ' + d.valor_formato);
                // Recargar el modal de detalle
                const detalleEl = document.getElementById('modalDetalle');
                if (detalleEl && detalleEl.classList.contains('open')) {
                    const padreId = detalleEl.dataset.incId || incId;
                    verDetalle(padreId);
                }
            } else {
                alert('Error al recalcular: ' + d.message);
            }
        })
        .catch(e => alert('Error de red: ' + e.message));
}

// ── Reversar un cambio de estado ─────────────────────────────────────────────
// Deshace el último cambio de estado y borra los movimientos que generó (gasto
// del pago, 4x1000, admon, abono y consignación). Antes de pedir confirmación
// se muestra exactamente qué se va a anular: es plata, no una etiqueta.
function abrirModalReversion(incId) {
    fetch(`/admin/incapacidades/${incId}/reversion/preview`, {headers: {'X-Requested-With': 'XMLHttpRequest'}})
        .then(async r => ({ok: r.ok, data: await r.json().catch(() => ({}))}))
        .then(({ok, data}) => {
            if (!ok || !data.ok) {
                alert(data.mensaje || data.message || 'No se pudo preparar la reversión.');
                return;
            }
            _pintarModalReversion(incId, data);
        })
        .catch(e => alert('Error de red: ' + e.message));
}

function _pintarModalReversion(incId, d) {
    const money = v => '$' + Number(v || 0).toLocaleString('es-CO');

    const movimientos = (d.movimientos || []).length === 0
        ? `<div style="font-size:.8rem;color:#64748b">Este cambio de estado no generó movimientos de plata: solo se devuelve el estado.</div>`
        : `<table style="width:100%;border-collapse:collapse;font-size:.78rem">
            ${d.movimientos.map(m => `
                <tr style="border-bottom:1px solid #fee2e2">
                    <td style="padding:.35rem .2rem">${m.etiqueta}<div style="color:#94a3b8;font-size:.68rem">${m.detalle || ''}</div></td>
                    <td style="padding:.35rem .2rem;white-space:nowrap;color:#64748b">${m.fecha || ''}</td>
                    <td style="padding:.35rem .2rem;text-align:right;font-weight:700;color:#991b1b;white-space:nowrap">− ${money(m.valor)}</td>
                </tr>`).join('')}
           </table>`;

    const bloqueos = (d.bloqueos || []).length === 0 ? '' : `
        <div style="background:${d.puede_forzar ? '#fff7ed' : '#fef2f2'};border:1px solid ${d.puede_forzar ? '#fed7aa' : '#fecaca'};
                    border-radius:8px;padding:.6rem .8rem;font-size:.78rem;color:${d.puede_forzar ? '#9a3412' : '#991b1b'};line-height:1.4">
            <strong>${d.puede_forzar ? '⚠️ Esto toca un día ya cerrado' : '🚫 No se puede reversar'}</strong>
            <ul style="margin:.35rem 0 0 1rem;padding:0">${d.bloqueos.map(b => `<li>${b}</li>`).join('')}</ul>
            ${d.puede_forzar
                ? '<div style="margin-top:.35rem">Como superadmin de BryNex puedes continuar, pero los totales de ese cuadre van a cambiar.</div>'
                : '<div style="margin-top:.35rem">Solo un superadmin de BryNex puede forzarlo.</div>'}
        </div>`;

    const bloqueado = (d.bloqueos || []).length > 0 && !d.puede_forzar;
    const titulo = d.es_prorroga ? `Prórroga ${d.numero_proroga} (#${d.incapacidad_id})` : `Incapacidad #${d.incapacidad_id}`;

    const html = `
    <div class="modal-header" style="background:linear-gradient(135deg,#b91c1c,#dc2626);border-radius:16px 16px 0 0">
        <div>
            <h3 style="color:#fff;font-size:1rem;font-weight:700;margin:0">↩️ Reversar estado</h3>
            <div style="font-size:.75rem;color:rgba(255,255,255,.8);margin-top:.1rem">${titulo}</div>
        </div>
        <button class="btn-close-modal" onclick="cerrarModalReversion()">×</button>
    </div>
    <div class="modal-body" style="display:flex;flex-direction:column;gap:.75rem">

        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:.7rem .85rem;font-size:.82rem">
            <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
                <span style="padding:.15rem .5rem;border-radius:999px;background:#fee2e2;color:#991b1b;font-weight:600;font-size:.72rem">${d.estado_actual_label}</span>
                <span style="color:#64748b">→</span>
                <span style="padding:.15rem .5rem;border-radius:999px;background:#dcfce7;color:#166534;font-weight:600;font-size:.72rem">${d.estado_destino_label}</span>
            </div>
            <div style="color:#64748b;font-size:.72rem;margin-top:.4rem">
                Gestión del ${d.gestion_fecha || '—'}${d.gestion_usuario ? ` · registrada por ${d.gestion_usuario}` : ''}
            </div>
        </div>

        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:10px;padding:.7rem .85rem">
            <div style="font-size:.72rem;font-weight:700;color:#991b1b;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.5rem">
                Se van a anular estos movimientos
            </div>
            ${movimientos}
            ${d.total_gastos ? `<div style="text-align:right;margin-top:.5rem;font-weight:800;color:#991b1b">Sale del gasto: − ${money(d.total_gastos)}</div>` : ''}
        </div>

        ${bloqueos}

        <div class="form-group" style="margin:0">
            <label style="font-weight:600;font-size:.82rem">Motivo de la reversión *</label>
            <textarea id="revMotivo" class="form-control" style="min-height:70px"
                      placeholder="Ej: se marcó pagada al afiliado por error, el pago nunca se hizo."></textarea>
            <div style="font-size:.7rem;color:#94a3b8;margin-top:.25rem">Queda en la bitácora junto con el detalle de lo anulado.</div>
        </div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="cerrarModalReversion()">Cancelar</button>
        <button class="btn btn-danger" id="revBtnConfirmar" ${bloqueado ? 'disabled style="opacity:.5;cursor:not-allowed"' : ''}
                onclick="confirmarReversion(${incId}, ${d.gestion_id})">↩️ Reversar y anular</button>
    </div>`;

    let overlay = document.getElementById('modalReversion');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'modalReversion';
        overlay.className = 'modal-overlay';
        overlay.innerHTML = `<div class="modal" style="max-width:560px">${html}</div>`;
        document.body.appendChild(overlay);
    } else {
        overlay.querySelector('.modal').innerHTML = html;
    }
    overlay.classList.add('open');
}

function cerrarModalReversion() {
    document.getElementById('modalReversion')?.classList.remove('open');
}

function confirmarReversion(incId, gestionId) {
    const motivo = (document.getElementById('revMotivo')?.value || '').trim();
    if (motivo.length < 5) {
        alert('Escribe el motivo de la reversión (mínimo 5 caracteres).');
        return;
    }

    const btn = document.getElementById('revBtnConfirmar');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Reversando...'; }

    fetch(`/admin/incapacidades/${incId}/reversion`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': TOKEN, 'X-Requested-With': 'XMLHttpRequest'},
        body: JSON.stringify({motivo, gestion_id: gestionId}),
    })
        .then(async r => ({ok: r.ok, data: await r.json().catch(() => ({}))}))
        .then(({ok, data}) => {
            if (!ok || !data.ok) {
                alert(data.mensaje || data.message || 'No se pudo reversar el estado.');
                if (btn) { btn.disabled = false; btn.textContent = '↩️ Reversar y anular'; }
                return;
            }
            cerrarModalReversion();
            alert(`${data.message} Estado actual: ${data.estado_label}`);
            const abierto = document.getElementById('modalDetalle')?.dataset.incId;
            if (abierto) verDetalle(abierto);
            actualizarFilaIncapacidad(incId);
        })
        .catch(e => {
            alert('Error de red: ' + e.message);
            if (btn) { btn.disabled = false; btn.textContent = '↩️ Reversar y anular'; }
        });
}

function registrarGestion(incId) {
    Promise.all([
        fetch(`/admin/incapacidades/${incId}/show`).then(r => r.json()).catch(() => ({})),
        fetch(`/admin/incapacidades/${incId}/documentos-familia`).then(r => r.json()).catch(() => ({}))
    ]).then(([showData, familiaData]) => {
        const inc    = showData.incapacidad || {};
        const familia = familiaData.familia || [];
        // Mapa id → radicado y estado para el panel de gestión
        window._familiaRadicadosMap = {};
        window._familiaEstadosMap   = {};
        familia.forEach(m => {
            window._familiaRadicadosMap[m.incapacidad_id] = {numero_radicado: m.numero_radicado, fecha_radicado: m.fecha_radicado};
            window._familiaEstadosMap[m.incapacidad_id]   = m.estado;
        });
        _mostrarModalGestion(incId, familia, inc);
    });
}

// 'pagada_afiliado' significa dos cosas distintas según de dónde venga: desde
// 'pagada a razón social' es el giro que hace el aliado con la plata que recibió;
// desde liquidación es un pago directo de la entidad al afiliado, donde el aliado
// no mueve un peso. Se distinguen en el selector para no registrar uno por el otro.
function _esPagoDirectoAfiliado(estadoDestino, estadoOrigen) {
    return estadoDestino === 'pagada_afiliado' && estadoOrigen !== 'pagada_razon_social';
}

function _labelEstadoDestino(estadoDestino, estadoOrigen) {
    if (_esPagoDirectoAfiliado(estadoDestino, estadoOrigen)) {
        return '🏛️ Pagada al Afiliado (pago directo de la entidad)';
    }
    return ((window.ESTADOS || {})[estadoDestino] || {}).label || estadoDestino;
}

function _mostrarModalGestion(incId, familia, inc = {}) {
    const TIPOS = {
        llamada:  '📞 Llamada',
        correo:   '📧 Correo',
        whatsapp: '💬 WhatsApp',
        portal:   '🌐 Portal Web',
        otro:     '📝 Otro',
    };
    const ESTADOS = @json(\App\Models\Incapacidad::ESTADOS);
    window.ESTADOS = ESTADOS; // Exponer para _actualizarSubtituloGestion

    // Estado actual de la incapacidad (para pre-seleccionar)
    const estadoActual     = inc.estado || '';
    // Si ya tiene número de radicado, no pedir de nuevo
    const yaRadicada       = !!(inc.numero_radicado);

    // Opciones de alcance
    let optsAlcance = '';
    if (familia.length <= 1) {
        optsAlcance = `<option value="esta_incapacidad" selected>📋 Esta incapacidad</option>`;
    } else {
        familia.forEach(g => {
            const selected = g.incapacidad_id == incId ? 'selected' : '';
            const icon = g.es_padre ? '🏥' : '🔁';
            optsAlcance += `<option value="incapacidad_${g.incapacidad_id}" ${selected}>${icon} ${g.label} #${g.incapacidad_id}</option>`;
        });
        optsAlcance += `<option value="toda_la_familia">👨‍👩‍👧 Toda la familia (seguimiento global)</option>`;
    }

    const optTipos = Object.entries(TIPOS)
        .map(([k,v]) => `<option value="${k}">${v}</option>`).join('');

    // ── Mapa de transiciones válidas (máquina de estados) ──────────────────
    const TRANSICIONES = {
        'pendiente':                   ['transcripcion_ips', 'radicada'],
        'recibido':                    ['transcripcion_ips', 'radicada'],
        'transcripcion_ips':           ['radicada'],
        'transcripcion':               ['radicada'],
        'radicada':                    ['negada', 'en_liquidacion'],
        'negada':                      ['radicada', 'rechazado', 'derecho_peticion'],
        'derecho_peticion':            ['derecho_peticion_radicado'],
        'derecho_peticion_radicado':   ['rechazado', 'tutela', 'en_liquidacion'],
        'tutela':                      ['tutela_radicada', 'rechazado'],
        'tutela_radicada':             ['en_liquidacion', 'rechazado'],
        // 'pagada_afiliado' directo desde liquidación: la entidad le pagó al afiliado
        // y no a la razón social (pasa sobre todo tras derecho de petición o tutela).
        'en_liquidacion':              ['pagada_razon_social', 'pagada_afiliado'],
        'liquidacion':                 ['pagada_razon_social', 'pagada_afiliado'],
        'pagada_razon_social':         ['pagada_afiliado'],
        'pagada_afiliado':             ['cierre_exitoso'],
        // Ortografía vieja que dejó la migración del legacy: sin esta entrada el
        // selector solo ofrece "mantener estado actual" y la incapacidad no cierra.
        'pagado_afiliado':             ['cierre_exitoso'],
        'cierre_exitoso':              [],
        // Se sale con el botón Reabrir, que devuelve al estado previo y pide
        // constancia; el selector no puede reconstruir a dónde volver.
        'anulada':                     [],
        'rechazado':                   [],
        'pagada':                      [],
    };

    const siguientesValidos = TRANSICIONES[estadoActual] || [];

    // Construir opciones: estado actual + posibles estados siguientes
    let optEstados = `<option value="${estadoActual}">— (Mantener estado actual)</option>`;
    if (siguientesValidos.length > 0) {
        optEstados += siguientesValidos.map(k => {
            const cfg = ESTADOS[k] || {};
            const lbl = _labelEstadoDestino(k, estadoActual);
            const icons = { secondary:'⬜', info:'🔵', primary:'📋', warning:'🟡', danger:'🔴', success:'🟢' };
            const dot = icons[cfg.color || 'secondary'] || '⬜';
            return `<option value="${k}">${dot} ${lbl}</option>`;
          }).join('');
    }

    // Indicador visual de estado actual
    const estadoActualCfg = ESTADOS[estadoActual] || {};
    const estadoActualLbl = estadoActualCfg.label || estadoActual || '—';

    const html = `
    <div class="modal-header" style="background:linear-gradient(135deg,#1e40af,#0891b2);border-radius:16px 16px 0 0">
        <div>
            <h3 style="color:#fff;font-size:1rem;font-weight:700;margin:0">📞 Registrar Gestión</h3>
            <div id="gModalSubtitle" style="font-size:.75rem;color:rgba(255,255,255,.75);margin-top:.1rem">Incapacidad #${incId}</div>
        </div>
        <button class="btn-close-modal" onclick="cerrarModalGestion()">×</button>
    </div>
    <div class="modal-body" style="display:flex;flex-direction:column;gap:.75rem">

        ${familia.length > 1 ? `
        <div class="form-group" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:.65rem .85rem">
            <label style="font-size:.72rem;font-weight:700;color:#1e40af;text-transform:uppercase;letter-spacing:.04em">
                📋 ¿A qué incapacidad aplica esta gestión? *
            </label>
            <select id="gAlcance" class="form-control" style="margin-top:.3rem" onchange="_actualizarSubtituloGestion(this)">
                ${optsAlcance}
            </select>
        </div>` : `<input type="hidden" id="gAlcance" value="esta_incapacidad">`}

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem">
            <div class="form-group">
                <label>Canal de Gestión *</label>
                <select id="gTipo" class="form-control">${optTipos}</select>
            </div>
            <div class="form-group">
                <label>Estado de la incapacidad</label>
                <select id="gEstado" class="form-control">
                    ${optEstados}
                </select>
            </div>
        </div>

        <div class="form-group">
            <label>Observación / Gestión</label>
            <textarea id="gRespuesta" class="form-control" style="min-height:90px"
                placeholder="Describe qué se hizo y qué respondió la entidad..."></textarea>
        </div>

        <div id="gAlertaCierre" style="display:none;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;padding:.6rem .85rem;font-size:.78rem;color:#92400e">
            ⚠️ Para el <strong>Cierre Exitoso</strong> se requiere haber registrado previamente <em>Pagada a Razón Social</em> y <em>Pagada al Afiliado</em>.
            Si la entidad le pagó <strong>directo al afiliado</strong>, basta con <em>Pagada al Afiliado</em>.
        </div>

        <div id="gPanelPagoRS" style="display:none;background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:.75rem .9rem">
            <div style="font-size:.72rem;font-weight:700;color:#0369a1;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.6rem">
                🏢 Pago recibido por Razón Social
            </div>
            <div id="gCuentasRSContent">
                <div style="font-size:.8rem;color:#94a3b8">⏳ Cargando cuentas...</div>
            </div>
        </div>

        <div id="gPanelPagoAfiliado" style="display:none;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:.75rem .9rem">
            <div id="gTituloPagoAfiliado" style="font-size:.72rem;font-weight:700;color:#15803d;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.6rem">
                🏦 Registrar Pago al Afiliado
            </div>

            <!-- Aviso del pago directo: la entidad le consignó al afiliado, no al aliado -->
            <div id="gAvisoPagoDirecto" style="display:none;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;
                        padding:.55rem .75rem;font-size:.76rem;color:#1e40af;margin-bottom:.6rem;line-height:1.35">
                🏛️ La entidad le pagó <strong>directamente al afiliado</strong>: no entró ni salió dinero de la razón social,
                así que <strong>no se registra gasto ni consignación</strong> y no se descuenta administración.
                Solo se deja constancia del valor para que la incapacidad no quede con saldo por entrar.
            </div>

            <div style="display:flex;flex-direction:column;gap:.55rem">
                <!-- Cuenta de origen -->
                <div class="form-group" id="gBloqueCuentaOrigen" style="margin:0">
                    <label style="font-weight:600;font-size:.8rem">Cuenta Bancaria de Origen</label>
                    <input type="text" id="gCuentaOrigenAfiliado" class="form-control" readonly style="background:#f8fafc;color:#475569;font-weight:500">
                    <input type="hidden" id="gBancoOrigenIdAfiliado" value="">
                </div>

                <!-- Valores y deducciones -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <div class="form-group" style="margin:0">
                        <label style="font-weight:600;font-size:.8rem" id="gLabelValorRecibido">Valor Recibido EPS *</label>
                        <input type="number" id="gValorRecibidoEps" class="form-control" readonly
                               style="background:#f8fafc;color:#475569;font-weight:600" oninput="_calcularNetoAfiliado()">
                    </div>
                    <div class="form-group" style="margin:0">
                        <label style="font-weight:600;font-size:.8rem">Fecha de Pago *</label>
                        <input type="date" id="gFechaPagoAfiliado" class="form-control">
                    </div>
                </div>

                <div id="gBloqueDescuentos" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.4rem">
                    <div class="form-group" style="margin:0">
                        <label style="font-weight:600;font-size:.76rem">Descuento Admon</label>
                        <input type="number" id="gDescuentoAdmon" class="form-control" value="0" min="0" oninput="_calcularNetoAfiliado()">
                    </div>
                    <div class="form-group" style="margin:0">
                        <label style="font-weight:600;font-size:.76rem">Descuento 4x1000</label>
                        <input type="number" id="gDescuento4x1000" class="form-control" value="0" min="0" oninput="_calcularNetoAfiliado()">
                    </div>
                    <div class="form-group" style="margin:0">
                        <label style="font-weight:600;font-size:.76rem">Otros Descuentos</label>
                        <input type="number" id="gDescuentoOtros" class="form-control" value="0" min="0" oninput="_calcularNetoAfiliado()">
                    </div>
                </div>

                <div id="gBloqueFormaPago" style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
                    <div class="form-group" style="margin:0">
                        <label style="font-weight:600;font-size:.8rem">Forma de Pago *</label>
                        <select id="gFormaPagoAfiliado" class="form-control" onchange="_cambioFormaPagoAfiliado(this.value)">
                            <option value="transferencia_bancaria">🏦 Transferencia Bancaria</option>
                            <option value="efectivo">💵 Efectivo</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0">
                        <label style="font-weight:600;font-size:.8rem;color:#15803d">Neto a Transferir</label>
                        <input type="number" id="gValorPagoAfiliado" class="form-control" readonly style="background:#f0fdf4;color:#166534;font-weight:700">
                    </div>
                </div>

                <!-- Foto del comprobante de soporte para el gasto -->
                <div class="form-group" id="gBloqueSoporteAfiliado" style="margin:.3rem 0 0">
                    <label style="font-size:.78rem;font-weight:600">Soporte del Gasto / Transferencia <span style="color:#94a3b8">(opcional)</span></label>
                    <div id="_mFotoZoneAfiliado" onclick="document.getElementById('_mFotoInputAfiliado').click()"
                        style="border:2px dashed #bbf7d0;border-radius:8px;padding:.6rem;text-align:center;
                               cursor:pointer;font-size:.78rem;color:#15803d;background:#f9fdfa;margin-top:.25rem"
                        ondragover="event.preventDefault()" ondrop="_dropFotoAfiliado(event)">
                        <div id="_mFotoPreviewAfiliado">📷 Haz clic, arrastra o <kbd style="background:#e2e8f0;padding:.1rem .3rem;border-radius:4px;font-size:.85em">Ctrl+V</kbd> para pegar comprobante</div>
                    </div>
                    <input type="file" id="_mFotoInputAfiliado" accept="image/*,application/pdf" style="display:none"
                        onchange="_previewFotoAfiliado(this)">
                </div>
            </div>
        </div>


        <div id="gCamposRadicada" style="display:none;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:.65rem .85rem">
            <div style="font-size:.72rem;font-weight:700;color:#065f46;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.5rem">📋 Datos del Radicado</div>
            <div id="gCamposRadicadaInner">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem">
                    <div class="form-group" style="margin:0">
                        <label>Número Radicado *</label>
                        <input type="text" id="gNumRadicado" class="form-control" placeholder="Ej: 2026-12345">
                    </div>
                    <div class="form-group" style="margin:0">
                        <label>Fecha Radicado *</label>
                        <input type="date" id="gFechaRadicado" class="form-control">
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-secondary" onclick="cerrarModalGestion()">Cancelar</button>
        <button class="btn btn-primary" onclick="enviarGestion(${incId})">💾 Guardar Gestión</button>
    </div>`;

    let overlay = document.getElementById('modalGestion');
    if (!overlay) {
        overlay = document.createElement('div');
        overlay.id = 'modalGestion';
        overlay.className = 'modal-overlay';
        overlay.innerHTML = `<div class="modal" style="max-width:580px">${html}</div>`;
        document.body.appendChild(overlay);
    } else {
        overlay.querySelector('.modal').innerHTML = html;
    }

    // Listener para mostrar/ocultar campos según estado seleccionado
    setTimeout(() => {
        const sel = document.getElementById('gEstado');
        if (sel) {
            window._gToggle = (val) => {
                const alcEl2 = document.getElementById('gAlcance');
                const alcVal2 = alcEl2 ? alcEl2.value : '';
                let selEstado = val;
                
                let incEstadoActual = estadoActual;
                if (alcVal2.startsWith('incapacidad_')) {
                    const tId2 = parseInt(alcVal2.replace('incapacidad_', ''));
                    incEstadoActual = (window._familiaEstadosMap || {})[tId2] || estadoActual;
                }
                
                if (!selEstado) {
                    selEstado = incEstadoActual;
                }

                const alerta       = document.getElementById('gAlertaCierre');
                const radicado     = document.getElementById('gCamposRadicada');
                const pagoRS       = document.getElementById('gPanelPagoRS');
                const pagoAfiliado = document.getElementById('gPanelPagoAfiliado');

                if (alerta) alerta.style.display = selEstado === 'cierre_exitoso' ? 'block' : 'none';
                
                if (radicado) {
                    radicado.style.display = selEstado === 'radicada' ? 'block' : 'none';
                    if (selEstado === 'radicada') {
                        const alcEl = document.getElementById('gAlcance');
                        const alcVal = alcEl ? alcEl.value : '';
                        let tId = incId;
                        if (alcVal.startsWith('incapacidad_')) tId = parseInt(alcVal.replace('incapacidad_', ''));
                        const info = (window._familiaRadicadosMap || {})[tId] || {};
                        const today = new Date().toISOString().substring(0,10);
                        radicado.innerHTML =
                            `<div style="font-size:.72rem;font-weight:700;color:#065f46;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.5rem">📋 Datos del Radicado</div>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem">
                                <div class="form-group" style="margin:0">
                                    <label>Número Radicado *</label>
                                    <input type="text" id="gNumRadicado" class="form-control" placeholder="Ej: 2026-12345" value="${info.numero_radicado || ''}">
                                </div>
                                <div class="form-group" style="margin:0">
                                    <label>Fecha Radicado *</label>
                                    <input type="date" id="gFechaRadicado" class="form-control" value="${info.fecha_radicado ? String(info.fecha_radicado).substring(0,10) : today}">
                                </div>
                            </div>`;
                    }
                }
                
                // Mostrar Pago RS solo si el nuevo estado es pagada_razon_social y NO lo estaba previamente
                if (pagoRS) {
                    const debeMostrarRS = selEstado === 'pagada_razon_social' && incEstadoActual !== 'pagada_razon_social';
                    pagoRS.style.display = debeMostrarRS ? 'block' : 'none';
                    if (debeMostrarRS) _cargarCuentasRS(incId);
                }

                // Mostrar Pago al Afiliado solo si el nuevo estado es pagada_afiliado y NO lo estaba previamente
                if (pagoAfiliado) {
                    const debeMostrarAfiliado = selEstado === 'pagada_afiliado' && incEstadoActual !== 'pagada_afiliado';
                    pagoAfiliado.style.display = debeMostrarAfiliado ? 'block' : 'none';
                    if (debeMostrarAfiliado) {
                        _cargarDatosPagoAfiliado(inc, incId, _esPagoDirectoAfiliado(selEstado, incEstadoActual));
                    } else {
                        // Con el panel oculto no hay pago que registrar: se limpia la
                        // bandera para no arrastrar el modo de una selección anterior.
                        window._pagoAfiliadoDirecto = false;
                    }
                }
            };
            window._gToggle(sel.value);
            sel.addEventListener('change', function() { window._gToggle(this.value); });
        }
    }, 100);

    overlay.classList.add('open');
}

// Carga el panel de pago a Razón Social con selector de forma de pago
function _cargarCuentasRS(incId) {
    const box = document.getElementById('gCuentasRSContent');
    if (!box || box.dataset.loaded === incId.toString()) return;
    box.dataset.loaded = incId.toString();
    box.innerHTML = '<div style="font-size:.8rem;color:#94a3b8">⏳ Cargando...</div>';

    fetch(`/admin/incapacidades/${incId}/cuentas-rs`)
        .then(r => r.json()).then(d => {
            if (!d.ok) { box.innerHTML = '<div style="color:#ef4444;font-size:.8rem">Error al cargar.</div>'; return; }
            // Guardar cuentas en variable global para usarlas en el mini-modal (filtrando que tengan NIT)
            const cuentasConNit = (d.cuentas || []).filter(c => c.nit && c.nit.toString().trim() !== '');
            window._rsIncId      = incId;
            window._rsCuentas    = cuentasConNit;
            window._rsCuentaOpts = cuentasConNit.length
                ? cuentasConNit.map(c =>
                    `<option value="${c.id}">${c.banco} · ${c.tipo_cuenta||''} · ****${(c.numero_cuenta||'').slice(-4)} (${c.nombre}) [NIT: ${c.nit}]</option>`
                  ).join('')
                : '';

            // Inputs ocultos para los valores que se enviarán al guardar la gestión
            box.innerHTML = `
            <input type="hidden" id="gFormaPagoRS" value="">
            <input type="hidden" id="gBancoCuentaId" value="">
            <input type="hidden" id="gValorPagoRS" value="">
            <input type="hidden" id="gFechaPagoRS" value="">
            <input type="hidden" id="gRefPagoRS" value="">

            <div class="form-group" style="margin:0 0 .35rem">
                <label style="font-weight:600;font-size:.82rem">¿Cómo se recibió el pago? *</label>
            </div>
            <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:.45rem" id="gFormasPago">
                ${[
                    // 'Directo al cliente' NO va aquí: si la entidad le pagó al
                    // afiliado, la plata nunca entró a la razón social y no puede
                    // registrarse como una forma de recibirla — eso infla el Canal 5.
                    // Ese caso se registra con el estado 'Pagada al afiliado'.
                    ['transferencia', '🏦', 'Transferencia\n/ Consignación'],
                    ['odi',           '📋', 'ODI'],
                    ['cheque',        '🧾', 'Cheque'],
                    ['otro',          '📎', 'Otro'],
                ].map(([val, ico, lbl]) => `
                    <button type="button" onclick="_selFormaRS('${val}')"
                        id="gBtnForma_${val}"
                        style="padding:.5rem .4rem;border:1.5px solid #cbd5e1;border-radius:10px;
                               background:#f8fafc;cursor:pointer;font-size:.74rem;text-align:center;
                               transition:all .15s;line-height:1.35;white-space:pre-line">
                        <div style="font-size:1.1rem;margin-bottom:.15rem">${ico}</div>${lbl}
                    </button>`).join('')}
            </div>
            <div id="gResumenFormaRS" style="display:none;margin-top:.55rem;background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:.45rem .65rem;font-size:.78rem;color:#0369a1">
            </div>`;
        }).catch(() => {
            box.innerHTML = '<div style="color:#ef4444;font-size:.8rem">Error de red.</div>';
        });
}

function _selFormaRS(forma) {
    // Resaltar botón activo
    document.querySelectorAll('#gFormasPago button').forEach(b => {
        b.style.background  = '#f8fafc';
        b.style.borderColor = '#cbd5e1';
        b.style.color       = '#374151';
    });
    const activeBtn = document.getElementById(`gBtnForma_${forma}`);
    if (activeBtn) {
        activeBtn.style.background  = '#1e40af';
        activeBtn.style.borderColor = '#1e40af';
        activeBtn.style.color       = '#fff';
    }

    _abrirMiniModalRS(forma);
}

function _abrirMiniModalRS(forma) {
    const today = new Date().toISOString().substring(0,10);

    let titulo = '', cuerpo = '';

    if (forma === 'transferencia') {
        const sinCuenta = !window._rsCuentaOpts;
        titulo = '🏦 Transferencia / Consignación';
        cuerpo = `
        ${sinCuenta ? `
        <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:.5rem .7rem;font-size:.79rem;color:#c2410c;margin-bottom:.6rem">
            ⚠️ <strong>Sin cuenta bancaria con NIT configurada</strong> para esta Razón Social.<br>
            Asegúrate de que la cuenta bancaria de la Razón Social tenga su NIT asignado.<br>
            <a href="/admin/configuracion/cuentas" target="_blank" style="color:#2563eb;text-decoration:underline">→ Configurar cuenta</a>
        </div>` : `
        <div class="form-group" style="margin:0 0 .5rem">
            <label>Cuenta que recibió el pago *</label>
            <select id="_mBancoCuenta" class="form-control">${window._rsCuentaOpts}</select>
        </div>`}
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:.5rem">
            <div class="form-group" style="margin:0">
                <label>Valor recibido *</label>
                <input type="number" id="_mValor" class="form-control" placeholder="0" min="0" step="1000" ${sinCuenta ? '' : 'autofocus'}>
            </div>
            <div class="form-group" style="margin:0">
                <label>Fecha de recepción *</label>
                <input type="date" id="_mFecha" class="form-control" value="${today}">
            </div>
        </div>
        <!-- Foto del comprobante -->
        <div class="form-group" style="margin:0">
            <label style="font-size:.78rem">Foto del comprobante <span style="color:#94a3b8">(opcional)</span></label>
            <div id="_mFotoZone" onclick="document.getElementById('_mFotoInput').click()"
                style="border:2px dashed #bae6fd;border-radius:8px;padding:.6rem;text-align:center;
                       cursor:pointer;font-size:.78rem;color:#0369a1;background:#f0f9ff;margin-top:.25rem"
                ondragover="event.preventDefault()" ondrop="_dropFotoRS(event)">
                <div id="_mFotoPreview">📷 Haz clic o arrastra la imagen / PDF</div>
            </div>
            <input type="file" id="_mFotoInput" accept="image/*,application/pdf" style="display:none"
                onchange="_previewFotoRS(this)">
        </div>
        <div style="font-size:.71rem;color:#0369a1;background:#e0f2fe;border-radius:5px;padding:.3rem .55rem;margin-top:.5rem">
            💡 Se registrará una consignación bancaria.
        </div>`;
        // Guardar flag para deshabilitar Confirmar si sin cuenta
        window._miniModalSinCuenta = sinCuenta;
    } else if (forma === 'odi') {
        titulo = '📋 ODI — Orden de la Entidad';
        cuerpo = `
        <div class="form-group" style="margin:0 0 .5rem">
            <label>Número de Referencia ODI</label>
            <input type="text" id="_mRef" class="form-control" placeholder="Ej: ODI-2026-001" autofocus>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
            <div class="form-group" style="margin:0">
                <label>Valor *</label>
                <input type="number" id="_mValor" class="form-control" placeholder="0" min="0" step="1000">
            </div>
            <div class="form-group" style="margin:0">
                <label>Fecha *</label>
                <input type="date" id="_mFecha" class="form-control" value="${today}">
            </div>
        </div>`;
    } else if (forma === 'cheque') {
        titulo = '🧾 Pago con Cheque';
        cuerpo = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:.5rem">
            <div class="form-group" style="margin:0">
                <label>Número de Cheque</label>
                <input type="text" id="_mRef" class="form-control" placeholder="Ej: 0012345" autofocus>
            </div>
            <div class="form-group" style="margin:0">
                <label>Banco Emisor</label>
                <input type="text" id="_mBancoEmisor" class="form-control" placeholder="Ej: Bancolombia">
            </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
            <div class="form-group" style="margin:0">
                <label>Valor *</label>
                <input type="number" id="_mValor" class="form-control" placeholder="0" min="0" step="1000">
            </div>
            <div class="form-group" style="margin:0">
                <label>Fecha *</label>
                <input type="date" id="_mFecha" class="form-control" value="${today}">
            </div>
        </div>`;
    } else { // otro
        titulo = '📎 Otro Medio de Pago';
        cuerpo = `
        <div class="form-group" style="margin:0 0 .5rem">
            <label>Referencia / Descripción</label>
            <input type="text" id="_mRef" class="form-control" placeholder="Describe el medio de pago" autofocus>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem">
            <div class="form-group" style="margin:0">
                <label>Valor *</label>
                <input type="number" id="_mValor" class="form-control" placeholder="0" min="0" step="1000">
            </div>
            <div class="form-group" style="margin:0">
                <label>Fecha *</label>
                <input type="date" id="_mFecha" class="form-control" value="${today}">
            </div>
        </div>`;
    }

    // Crear o reutilizar mini-modal
    let mini = document.getElementById('miniModalRS');
    if (!mini) {
        mini = document.createElement('div');
        mini.id = 'miniModalRS';
        mini.style.cssText = `position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;
            display:flex;align-items:center;justify-content:center`;
        document.body.appendChild(mini);
    }
    mini.innerHTML = `
    <div style="background:#fff;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.3);
                width:min(500px,94vw);animation:fadeInUp .2s ease">
        <div style="background:linear-gradient(135deg,#1e40af,#0891b2);border-radius:16px 16px 0 0;
                    padding:.85rem 1.2rem;display:flex;justify-content:space-between;align-items:center">
            <h4 style="color:#fff;margin:0;font-size:.95rem;font-weight:700">${titulo}</h4>
            <button onclick="_cerrarMiniModalRS()"
                style="background:rgba(255,255,255,.2);border:none;color:#fff;border-radius:8px;
                       cursor:pointer;font-size:1rem;padding:.2rem .55rem">✕</button>
        </div>
        <div style="padding:1.1rem 1.2rem">
            ${cuerpo}
        </div>
        <div style="padding:.75rem 1.2rem;border-top:1px solid #e2e8f0;display:flex;gap:.5rem;justify-content:flex-end">
            <button onclick="_cerrarMiniModalRS()"
                class="btn btn-secondary" style="font-size:.82rem">Cancelar</button>
            <button id="_mBtnConfirmar" onclick="_confirmarFormaRS('${forma}')"
                class="btn btn-primary" style="font-size:.82rem"
                ${forma === 'transferencia' && window._miniModalSinCuenta ? 'disabled title="Configura la cuenta bancaria primero"' : ''}>
                ✅ Confirmar
            </button>
        </div>
    </div>`;
    mini.style.display = 'flex';
    setTimeout(() => mini.querySelector('input:not([disabled]), select')?.focus(), 100);

    // ── Listener Ctrl+V para pegar imagen desde portapapeles ──────────────
    window._rsPasteHandler = (e) => {
        if (forma !== 'transferencia') return;
        const items = e.clipboardData?.items;
        if (!items) return;
        for (const item of items) {
            if (item.type.startsWith('image/')) {
                const file = item.getAsFile();
                if (!file) continue;
                // Simular selección en el input para reutilizar _previewFotoRS
                const dt = new DataTransfer();
                dt.items.add(file);
                const inp = document.getElementById('_mFotoInput');
                if (inp) { inp.files = dt.files; _previewFotoRS(inp); }
                // Mostrar hint de que se pegó
                const zone = document.getElementById('_mFotoZone');
                if (zone) {
                    zone.style.borderColor = '#22c55e';
                    zone.style.background  = '#f0fdf4';
                    setTimeout(() => {
                        if (zone) { zone.style.borderColor = '#bae6fd'; zone.style.background = '#f0f9ff'; }
                    }, 800);
                }
                e.preventDefault();
                break;
            }
        }
    };
    document.addEventListener('paste', window._rsPasteHandler);

    // Mostrar hint Ctrl+V en la zona de foto si existe
    setTimeout(() => {
        const prev = document.getElementById('_mFotoPreview');
        if (prev && prev.textContent.includes('clic')) {
            prev.innerHTML = '📷 Clic, arrastra o <kbd style="background:#e2e8f0;padding:.1rem .3rem;border-radius:4px;font-size:.85em">Ctrl+V</kbd> para pegar';
        }
    }, 150);
}

function _cerrarMiniModalRS() {
    if (window._rsPasteHandler) {
        document.removeEventListener('paste', window._rsPasteHandler);
        window._rsPasteHandler = null;
    }
    document.getElementById('miniModalRS')?.remove();
}


function _previewFotoRS(input) {
    const file = input.files[0];
    if (!file) return;
    const prev = document.getElementById('_mFotoPreview');
    if (!prev) return;
    if (file.type.startsWith('image/')) {
        const url = URL.createObjectURL(file);
        prev.innerHTML = `<img src="${url}" style="max-height:80px;border-radius:6px;object-fit:contain"> <div style="margin-top:.2rem;font-size:.72rem;color:#065f46">${file.name}</div>`;
    } else {
        prev.innerHTML = `📄 ${file.name}`;
    }
    window._rsFotoFile = file;
}
function _dropFotoRS(e) {
    e.preventDefault();
    const file = e.dataTransfer.files[0];
    if (!file) return;
    const input = document.getElementById('_mFotoInput');
    const dt = new DataTransfer(); dt.items.add(file); input.files = dt.files;
    _previewFotoRS(input);
}

function _confirmarFormaRS(forma) {
    if (forma === 'transferencia' && window._miniModalSinCuenta) {
        alert('Debes configurar una cuenta bancaria con NIT para esta Razón Social antes de continuar.');
        return;
    }

    const valor = document.getElementById('_mValor')?.value || '0';
    const fecha = document.getElementById('_mFecha')?.value || new Date().toISOString().substring(0,10);
    const ref   = document.getElementById('_mRef')?.value || '';
    const banco = document.getElementById('_mBancoCuenta')?.value || '';
    const bancoEmisor = document.getElementById('_mBancoEmisor')?.value || '';

    // Guardar en los inputs ocultos del panel principal
    document.getElementById('gFormaPagoRS').value  = forma;
    document.getElementById('gBancoCuentaId').value = banco;
    document.getElementById('gValorPagoRS').value  = valor;
    document.getElementById('gFechaPagoRS').value  = fecha;
    document.getElementById('gRefPagoRS').value    = ref + (bancoEmisor ? ` · Banco: ${bancoEmisor}` : '');

    // Resumen visible en el panel
    const formaLabel = {
        transferencia: '🏦 Transferencia/Consignación',
        odi:           '📋 ODI',
        cheque:        '🧾 Cheque',
        directo:       '👤 Directo al cliente',
        otro:          '📎 Otro',
    }[forma] || forma;

    const valorFmt = Number(valor) > 0 ? ' · $' + Number(valor).toLocaleString('es-CO') : '';
    const refTxt   = ref ? ` · Ref: ${ref}` : '';
    const fotoTxt  = (forma === 'transferencia' && window._rsFotoFile) ? ' 📸' : '';

    const resumen = document.getElementById('gResumenFormaRS');
    if (resumen) {
        resumen.style.display = 'block';
        resumen.innerHTML = `<strong>${formaLabel}</strong>${valorFmt}${refTxt} · ${fecha}${fotoTxt} ✅`;
    }

    document.getElementById('miniModalRS')?.remove();
}


// Actualiza subtítulo y panel de radicado al cambiar la incapacidad seleccionada
function _actualizarSubtituloGestion(sel) {
    const sub = document.getElementById('gModalSubtitle');
    if (sub) {
        const txt = sel.options[sel.selectedIndex]?.text || '';
        sub.textContent = txt.includes('familia') ? '👨‍👩‍👧 Gestión para toda la familia' : txt;
    }
    // Actualizar las opciones de gEstado según el estado del miembro seleccionado
    const alcVal = sel.value;
    const gEstadoEl = document.getElementById('gEstado');
    if (gEstadoEl && alcVal.startsWith('incapacidad_')) {
        const tId = parseInt(alcVal.replace('incapacidad_', ''));
        const estadoMiembro = (window._familiaEstadosMap || {})[tId] || '';
        const TRANS = {
            'pendiente':['transcripcion_ips','radicada'],'recibido':['transcripcion_ips','radicada'],
            'transcripcion_ips':['radicada'],'transcripcion':['radicada'],
            'radicada':['negada','en_liquidacion'],
            'negada':['radicada','rechazado','derecho_peticion'],
            'derecho_peticion':['derecho_peticion_radicado'],
            'derecho_peticion_radicado':['rechazado','tutela','en_liquidacion'],
            'tutela':['tutela_radicada','rechazado'],'tutela_radicada':['en_liquidacion','rechazado'],
            'en_liquidacion':['pagada_razon_social','pagada_afiliado'],
            'liquidacion':['pagada_razon_social','pagada_afiliado'],
            'pagada_razon_social':['pagada_afiliado'],'pagada_afiliado':['cierre_exitoso'],
            'cierre_exitoso':[],'rechazado':[],'pagada':[],
        };
        const sigs = TRANS[estadoMiembro] || [];
        const ESTADOS_LBL = window.ESTADOS || {};
        const icons = {secondary:'⬜',info:'🔵',primary:'📋',warning:'🟡',danger:'🔴',success:'🟢'};
        const opts = sigs.map(k => {
            const cfg = ESTADOS_LBL[k] || {};
            const dot = icons[cfg.color||'secondary']||'⬜';
            return `<option value="${k}">${dot} ${_labelEstadoDestino(k, estadoMiembro)}</option>`;
        }).join('');
        const estadoLbl = (ESTADOS_LBL[estadoMiembro]||{}).label || estadoMiembro || '—';
        gEstadoEl.disabled = sigs.length === 0;
        gEstadoEl.innerHTML = `<option value="${estadoMiembro}" selected>${estadoLbl}</option>${opts ||
            '<option value="${estadoMiembro}" disabled style="color:#94a3b8">— Sin transiciones disponibles —</option>'}`;
    }
    // Refrescar panel de campos si el estado seleccionado lo requiere
    if (typeof window._gToggle === 'function') {
        window._gToggle(gEstadoEl?.value || '');
    }
}

// El servidor responde 409 cuando el pago al afiliado se parece a un duplicado
// (ya hay un pago registrado, o saldría más plata de la que entró de la entidad).
// No es un error: es un "¿seguro?" que el operador puede aceptar.
function confirmarAvisosPago(d) {
    const avisos = (d.avisos || [d.message || '']).filter(Boolean);
    return confirm('⚠️ ' + avisos.join('\n\n') + '\n\n¿Registrar el pago de todas formas?');
}

function enviarGestion(incId, confirmarPago) {
    const alcanceEl = document.getElementById('gAlcance');
    const alcanceVal = alcanceEl?.value || 'esta_incapacidad';
    const tramite = document.getElementById('gRespuesta').value.trim();

    // Determinar incapacidad destino y alcance a enviar
    let targetId = incId;
    let alcance = 'esta_incapacidad';

    if (alcanceVal === 'toda_la_familia') {
        alcance = 'toda_la_familia';
    } else if (alcanceVal.startsWith('incapacidad_')) {
        targetId = parseInt(alcanceVal.replace('incapacidad_', ''));
        alcance = 'esta_incapacidad';
    }

    const estadoNuevo    = document.getElementById('gEstado').value || null;
    const esPagoRS       = estadoNuevo === 'pagada_razon_social';
    const esPagoAfiliado = estadoNuevo === 'pagada_afiliado';
    // Pago directo de la entidad al afiliado: no hay cuenta de origen, forma de pago
    // ni descuentos que mandar, porque no hubo movimiento de plata del aliado.
    const esPagoDirecto  = esPagoAfiliado && window._pagoAfiliadoDirecto === true;

    const body = {
        tipo:            document.getElementById('gTipo').value,
        tramite:         tramite,

        estado_nuevo:    estadoNuevo,
        alcance:         alcance,
        numero_radicado: estadoNuevo === 'radicada' ? (document.getElementById('gNumRadicado')?.value || null) : null,
        fecha_radicado:  estadoNuevo === 'radicada' ? (document.getElementById('gFechaRadicado')?.value || null) : null,
        // Pago a Razón Social
        forma_pago_rs:   esPagoRS ? (document.getElementById('gFormaPagoRS')?.value || null) : null,
        banco_cuenta_id: esPagoRS ? (document.getElementById('gBancoCuentaId')?.value || null) : ((esPagoAfiliado && !esPagoDirecto) ? (document.getElementById('gBancoOrigenIdAfiliado')?.value || null) : null),
        valor_pago_rs:   esPagoRS ? (document.getElementById('gValorPagoRS')?.value || null) : null,
        fecha_pago_rs:   esPagoRS ? (document.getElementById('gFechaPagoRS')?.value || null) : null,
        ref_pago_rs:     esPagoRS ? (document.getElementById('gRefPagoRS')?.value || null) : null,
        // Pago al Afiliado (Gasto)
        forma_pago:          (esPagoAfiliado && !esPagoDirecto) ? (document.getElementById('gFormaPagoAfiliado')?.value || null) : null,
        valor_pago_afiliado: esPagoAfiliado ? (document.getElementById(esPagoDirecto ? 'gValorRecibidoEps' : 'gValorPagoAfiliado')?.value || null) : null,
        fecha_pago_afiliado: esPagoAfiliado ? (document.getElementById('gFechaPagoAfiliado')?.value || null) : null,
        descuento_admon:     (esPagoAfiliado && !esPagoDirecto) ? (document.getElementById('gDescuentoAdmon')?.value || 0) : 0,
        descuento_4x1000:    (esPagoAfiliado && !esPagoDirecto) ? (document.getElementById('gDescuento4x1000')?.value || 0) : 0,
        descuento_otros:     (esPagoAfiliado && !esPagoDirecto) ? (document.getElementById('gDescuentoOtros')?.value || 0) : 0,
        confirmar_pago:      confirmarPago ? 1 : 0,
        _token:              TOKEN,
    };

    // Validar campos de radicado solo si el input existe (no hay radicado previo)
    const numRadicadoInput = document.getElementById('gNumRadicado');
    if (estadoNuevo === 'radicada' && numRadicadoInput && !body.numero_radicado) {
        alert('Por favor ingresa el Número Radicado.');
        return;
    }

    // Validar pago RS
    if (esPagoRS) {
        if (!body.forma_pago_rs) { alert('Selecciona la forma en que se recibió el pago.'); return; }
        if (body.forma_pago_rs === 'transferencia' && !body.banco_cuenta_id) {
            alert('Debes seleccionar una cuenta bancaria con NIT para la Razón Social.');
            return;
        }
        if (!body.valor_pago_rs || Number(body.valor_pago_rs) <= 0) { alert('Ingresa el valor recibido.'); return; }
    }

    // Validar pago al afiliado
    if (esPagoDirecto) {
        if (!body.valor_pago_afiliado || Number(body.valor_pago_afiliado) <= 0) {
            alert('Ingresa el valor que la entidad le pagó directamente al afiliado.');
            return;
        }
        if (!body.fecha_pago_afiliado) { alert('Ingresa la fecha del pago.'); return; }
    } else if (esPagoAfiliado) {
        if (body.forma_pago === 'transferencia_bancaria' && !body.banco_cuenta_id) {
            alert('No se detectó la cuenta bancaria de origen (Razón Social) asignada.');
            return;
        }
        if (body.valor_pago_afiliado === null || Number(body.valor_pago_afiliado) < 0) {
            alert('El valor neto a transferir al afiliado no es válido.');
            return;
        }
    }

    const btn = document.querySelector('#modalGestion .btn-primary');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Guardando...'; }

    fetch(`/admin/incapacidades/${targetId}/gestion`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': TOKEN, 'Accept': 'application/json' },
        body: JSON.stringify(body),
    }).then(r => {
        // Capturar status para detectar errores de validación (422)
        const status = r.status;
        return r.json().then(d => ({ d, status }));
    }).then(({ d, status }) => {
        if (btn) { btn.disabled = false; btn.textContent = '💾 Guardar Gestión'; }
        if (d.ok) {
            // Foto comprobante Razón Social
            if (d.consignacion_id && window._rsFotoFile) {
                const fd = new FormData();
                fd.append('imagen', window._rsFotoFile);
                fd.append('_token', TOKEN);
                fetch(`/admin/facturacion/consignacion/${d.consignacion_id}/imagen`, { method: 'POST', body: fd }).catch(() => {});
                window._rsFotoFile = null;
            }
            // Foto comprobante Afiliado (Gasto)
            if (d.gasto_id && window._afiliadoFotoFile) {
                const fd = new FormData();
                fd.append('imagen', window._afiliadoFotoFile);
                fd.append('_token', TOKEN);
                fetch(`/admin/informes/gastos/${d.gasto_id}/imagen`, { method: 'POST', body: fd }).catch(() => {});
                window._afiliadoFotoFile = null;
            }
            // Mensaje de éxito
            alert('✅ Gestión guardada correctamente');
            cerrarModalGestion();
            // Recargar detalle del padre y abrir pestaña Prórrogas si se guardó en una prórroga
            const detalleEl  = document.getElementById('modalDetalle');
            const detalleId  = parseInt(detalleEl?.dataset?.incId || incId);
            const esProrrogaDiferente = targetId !== detalleId;
            verDetalle(detalleId);
            if (esProrrogaDiferente) {
                // Dar tiempo para que el modal se renderice y luego abrir la pestaña
                setTimeout(() => {
                    const tabProrrogas = document.querySelector('#modalDetalle [data-tab="prorrogas"], #modalDetalle button[onclick*="prorrogas"]');
                    if (tabProrrogas) tabProrrogas.click();
                }, 600);
            }
        } else if (status === 409 && d.requiere_confirmacion) {
            if (confirmarAvisosPago(d)) enviarGestion(incId, true);
        } else if (status === 422 && d.errors) {
            // Error de validación de Laravel
            const msgs = Object.values(d.errors).flat().join('\n');
            alert('Error de validación:\n' + msgs);
        } else {
            alert('Error: ' + (d.message || 'Error desconocido'));
        }
    }).catch(e => {
        if (btn) { btn.disabled = false; btn.textContent = '💾 Guardar Gestión'; }
        alert('Error de red: ' + e.message);
    });
}




function cerrarModalGestion() {
    document.getElementById('modalGestion')?.classList.remove('open');
    if (window._afiliadoPasteHandler) {
        document.removeEventListener('paste', window._afiliadoPasteHandler);
        window._afiliadoPasteHandler = null;
    }
    window._afiliadoFotoFile = null;
}

// Alterna el panel entre los dos pagos al afiliado: el normal (el aliado gira la
// plata que le entró a la razón social → gasto + descuentos) y el directo (la
// entidad le pagó al afiliado → ningún movimiento de caja del aliado).
function _aplicarModoPagoAfiliado(esDirecto) {
    const mostrar = (id, visible, display = 'block') => {
        const el = document.getElementById(id);
        if (el) el.style.display = visible ? display : 'none';
    };

    mostrar('gAvisoPagoDirecto',     esDirecto);
    mostrar('gBloqueCuentaOrigen',   !esDirecto);
    mostrar('gBloqueDescuentos',     !esDirecto, 'grid');
    mostrar('gBloqueFormaPago',      !esDirecto, 'grid');
    mostrar('gBloqueSoporteAfiliado', !esDirecto);

    const titulo = document.getElementById('gTituloPagoAfiliado');
    if (titulo) titulo.textContent = esDirecto ? '🏛️ Pago directo de la entidad al afiliado' : '🏦 Registrar Pago al Afiliado';

    const label = document.getElementById('gLabelValorRecibido');
    if (label) label.textContent = esDirecto ? 'Valor que la entidad le pagó al afiliado *' : 'Valor Recibido EPS *';

    // En el pago directo el valor lo digita el usuario: no hay entrada registrada
    // de la cual deducirlo.
    const valorInp = document.getElementById('gValorRecibidoEps');
    if (valorInp) {
        valorInp.readOnly = !esDirecto;
        valorInp.style.background = esDirecto ? '#fff' : '#f8fafc';
        valorInp.style.color = esDirecto ? '#166534' : '#475569';
    }
}

function _cargarDatosPagoAfiliado(inc, incId, esDirecto = false) {
    window._pagoAfiliadoDirecto = !!esDirecto;
    _aplicarModoPagoAfiliado(!!esDirecto);

    const abonoEps = (inc.abonos || []).find(a => a.tipo === 'entrada_incapacidad');
    const today = new Date().toISOString().substring(0,10);

    document.getElementById('gFechaPagoAfiliado').value = today;
    document.getElementById('gDescuentoAdmon').value = 0;
    document.getElementById('gDescuentoOtros').value = 0;
    document.getElementById('gFormaPagoAfiliado').value = 'transferencia_bancaria';

    if (esDirecto) {
        // Sin descuentos: el aliado no retuvo nada porque la plata no le pasó por las manos.
        document.getElementById('gDescuento4x1000').value = 0;

        // El valor sugerido solo sirve si la gestión es sobre esta misma incapacidad;
        // si se eligió una prórroga de la familia, el esperado es otro y toca digitarlo.
        const alcVal = document.getElementById('gAlcance')?.value || 'esta_incapacidad';
        const esMismaInc = !alcVal.startsWith('incapacidad_') || parseInt(alcVal.replace('incapacidad_', '')) === incId;
        document.getElementById('gValorRecibidoEps').value = esMismaInc ? Math.round(Number(inc.valor_esperado || 0)) : '';

        document.getElementById('gBancoOrigenIdAfiliado').value = '';
        document.getElementById('gCuentaOrigenAfiliado').value = '';

        // Sin gasto ni consignación no hay a qué adjuntar el comprobante, así que
        // se apaga el pegado de imágenes para no dejar un archivo colgando.
        window._afiliadoFotoFile = null;
        if (window._afiliadoPasteHandler) {
            document.removeEventListener('paste', window._afiliadoPasteHandler);
            window._afiliadoPasteHandler = null;
        }

        _calcularNetoAfiliado();
        return;
    }

    // Limpiar vista previa de la foto al cargar
    const prev = document.getElementById('_mFotoPreviewAfiliado');
    if (prev) prev.innerHTML = '📷 Haz clic, arrastra o Ctrl+V para pegar soporte';
    window._afiliadoFotoFile = null;
    
    let valorEps = 0;
    if (abonoEps) {
        valorEps = Math.round(Number(abonoEps.valor || 0));
        document.getElementById('gValorRecibidoEps').value = valorEps;
        document.getElementById('gDescuento4x1000').value = Math.round(valorEps * 0.004);
        
        document.getElementById('gBancoOrigenIdAfiliado').value = abonoEps.banco_cuenta_id || '';
        if (abonoEps.banco_cuenta) {
            document.getElementById('gCuentaOrigenAfiliado').value = `${abonoEps.banco_cuenta.banco} · ****${(abonoEps.banco_cuenta.numero_cuenta || '').slice(-4)} (${abonoEps.banco_cuenta.nombre})`;
            _calcularNetoAfiliado();
        } else {
            document.getElementById('gCuentaOrigenAfiliado').value = 'Buscando cuenta de Razón Social...';
            fetch(`/admin/incapacidades/${incId}/cuentas-rs`)
                .then(r => r.json()).then(d => {
                    if (d.ok && d.cuentas && d.cuentas.length > 0) {
                        const c = d.cuentas[0];
                        document.getElementById('gBancoOrigenIdAfiliado').value = c.id;
                        document.getElementById('gCuentaOrigenAfiliado').value = `${c.banco} · ****${(c.numero_cuenta||'').slice(-4)} (${c.nombre})`;
                    } else {
                        document.getElementById('gCuentaOrigenAfiliado').value = 'Sin cuenta asignada a Razón Social';
                    }
                    _calcularNetoAfiliado();
                }).catch(() => {
                    document.getElementById('gCuentaOrigenAfiliado').value = 'Error al consultar cuentas';
                    _calcularNetoAfiliado();
                });
        }
    } else {
        valorEps = Math.round(Number(inc.valor_esperado || 0));
        document.getElementById('gValorRecibidoEps').value = valorEps;
        document.getElementById('gDescuento4x1000').value = Math.round(valorEps * 0.004);
        
        document.getElementById('gCuentaOrigenAfiliado').value = 'Buscando cuenta de Razón Social...';
        document.getElementById('gBancoOrigenIdAfiliado').value = '';
        
        fetch(`/admin/incapacidades/${incId}/cuentas-rs`)
            .then(r => r.json()).then(d => {
                if (d.ok && d.cuentas && d.cuentas.length > 0) {
                    const c = d.cuentas[0];
                    document.getElementById('gBancoOrigenIdAfiliado').value = c.id;
                    document.getElementById('gCuentaOrigenAfiliado').value = `${c.banco} · ****${(c.numero_cuenta||'').slice(-4)} (${c.nombre})`;
                } else {
                    document.getElementById('gCuentaOrigenAfiliado').value = 'Sin cuenta asignada a Razón Social';
                }
                _calcularNetoAfiliado();
            }).catch(() => {
                document.getElementById('gCuentaOrigenAfiliado').value = 'Error al consultar cuentas';
                _calcularNetoAfiliado();
            });
    }

    // Configurar listener para Ctrl+V en este panel
    if (window._afiliadoPasteHandler) {
        document.removeEventListener('paste', window._afiliadoPasteHandler);
    }
    window._afiliadoPasteHandler = (e) => {
        const pagoAfiliado = document.getElementById('gPanelPagoAfiliado');
        if (!pagoAfiliado || pagoAfiliado.style.display === 'none') return;
        
        const items = e.clipboardData?.items;
        if (!items) return;
        for (const item of items) {
            if (item.type.startsWith('image/')) {
                const file = item.getAsFile();
                if (!file) continue;
                const dt = new DataTransfer();
                dt.items.add(file);
                const inp = document.getElementById('_mFotoInputAfiliado');
                if (inp) { inp.files = dt.files; _previewFotoAfiliado(inp); }
                
                const zone = document.getElementById('_mFotoZoneAfiliado');
                if (zone) {
                    zone.style.borderColor = '#22c55e';
                    zone.style.background  = '#f0fdf4';
                    setTimeout(() => {
                        if (zone) { zone.style.borderColor = '#bbf7d0'; zone.style.background = '#f0fdf4'; }
                    }, 800);
                }
                e.preventDefault();
                break;
            }
        }
    };
    document.addEventListener('paste', window._afiliadoPasteHandler);
}

function _calcularNetoAfiliado() {
    const bruto = Number(document.getElementById('gValorRecibidoEps').value || 0);
    const admon = Number(document.getElementById('gDescuentoAdmon').value || 0);
    const x1000 = Number(document.getElementById('gDescuento4x1000').value || 0);
    const otros = Number(document.getElementById('gDescuentoOtros').value || 0);
    
    const neto = bruto - admon - x1000 - otros;
    document.getElementById('gValorPagoAfiliado').value = neto >= 0 ? neto : 0;
}

function _cambioFormaPagoAfiliado(val) {
    const cuentaInp = document.getElementById('gCuentaOrigenAfiliado');
    if (val === 'efectivo') {
        if (cuentaInp) cuentaInp.style.opacity = '0.5';
    } else {
        if (cuentaInp) cuentaInp.style.opacity = '1';
    }
}

function _previewFotoAfiliado(input) {
    const file = input.files[0];
    if (!file) return;
    const prev = document.getElementById('_mFotoPreviewAfiliado');
    if (!prev) return;
    if (file.type.startsWith('image/')) {
        const url = URL.createObjectURL(file);
        prev.innerHTML = `<img src="${url}" style="max-height:80px;border-radius:6px;object-fit:contain"> <div style="margin-top:.2rem;font-size:.72rem;color:#15803d">${file.name}</div>`;
    } else {
        prev.innerHTML = `📄 ${file.name}`;
    }
    window._afiliadoFotoFile = file;
}

function _dropFotoAfiliado(e) {
    e.preventDefault();
    const file = e.dataTransfer.files[0];
    if (!file) return;
    const input = document.getElementById('_mFotoInputAfiliado');
    const dt = new DataTransfer(); dt.items.add(file); input.files = dt.files;
    _previewFotoAfiliado(input);
}


// ── Pago al afiliado (Anticipo / Préstamo) ──────────────────────────────────
function registrarPago(incId){
    let overlay = document.getElementById('modalPago');
    if(!overlay){
        overlay = document.createElement('div');
        overlay.id = 'modalPago';
        overlay.className = 'modal-overlay';
        overlay.innerHTML = `<div class="modal" style="max-width:520px"><div style="padding:2rem;text-align:center;color:#64748b">⏳ Cargando opciones...</div></div>`;
        document.body.appendChild(overlay);
    } else {
        overlay.querySelector('.modal').innerHTML = `<div style="padding:2rem;text-align:center;color:#64748b">⏳ Cargando opciones...</div>`;
    }
    overlay.classList.add('open');

    fetch(`/admin/incapacidades/${incId}/cuentas-rs`)
        .then(r => r.json())
        .then(d => {
            const cuentas = d.cuentas || [];
            const cuentasOpts = cuentas.length
                ? cuentas.map(c => `<option value="${c.id}">${c.banco} · ${c.tipo_cuenta||''} · ****${(c.numero_cuenta||'').slice(-4)} (${c.nombre})</option>`).join('')
                : '<option value="">Sin cuentas registradas</option>';

            const html = `<div style="padding:1rem">
                <h4 style="margin-bottom:1rem;color:#0f172a;font-weight:700">💰 Registrar Anticipo / Préstamo — Incapacidad #${incId}</h4>
                <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem;margin-bottom:.8rem">
                    <div class="form-group">
                        <label style="font-weight:600;font-size:.85rem;color:#475569;margin-bottom:.3rem;display:block">Valor Anticipado *</label>
                        <input type="number" id="pValor" style="width:100%;padding:.5rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem" min="0" step="1000" placeholder="Ej. 150000">
                    </div>
                    <div class="form-group">
                        <label style="font-weight:600;font-size:.85rem;color:#475569;margin-bottom:.3rem;display:block">Fecha de Pago *</label>
                        <input type="date" id="pFecha" value="${new Date().toISOString().substring(0,10)}" style="width:100%;padding:.5rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem">
                    </div>
                </div>
                
                <div class="form-row" style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem;margin-bottom:.8rem">
                    <div class="form-group">
                        <label style="font-weight:600;font-size:.85rem;color:#475569;margin-bottom:.3rem;display:block">Forma de Pago *</label>
                        <select id="pFormaPago" style="width:100%;padding:.5rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem" onchange="toggleBancoOrigen(this.value)">
                            <option value="transferencia_bancaria">Transferencia Bancaria</option>
                            <option value="efectivo">Efectivo</option>
                        </select>
                    </div>
                    <div class="form-group" id="pBancoGroup">
                        <label style="font-weight:600;font-size:.85rem;color:#475569;margin-bottom:.3rem;display:block">Banco Origen *</label>
                        <select id="pBancoCuentaId" style="width:100%;padding:.5rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem">
                            ${cuentasOpts}
                        </select>
                    </div>
                </div>

                <input type="hidden" id="pPagadoA" value="cliente">

                <div class="form-group" style="margin-bottom:1rem">
                    <label style="font-weight:600;font-size:.85rem;color:#475569;margin-bottom:.3rem;display:block">Detalle / Observación</label>
                    <textarea id="pDetalle" style="width:100%;min-height:55px;padding:.5rem;border:1px solid #d1d5db;border-radius:6px;font-size:.9rem" placeholder="Detalles sobre el préstamo/anticipo..."></textarea>
                </div>
                
                <div style="display:flex;gap:.5rem;justify-content:flex-end;margin-top:1.2rem">
                    <button class="btn btn-secondary" onclick="document.getElementById('modalPago').classList.remove('open')">Cancelar</button>
                    <button class="btn btn-success" onclick="enviarPago(${incId})" style="background-color:#16a34a;border-color:#16a34a">💾 Registrar Anticipo</button>
                </div>
            </div>`;

            overlay.querySelector('.modal').innerHTML = html;
        })
        .catch(err => {
            overlay.querySelector('.modal').innerHTML = `<div style="padding:2rem;text-align:center;color:#ef4444">Error al cargar las cuentas bancarias.</div>`;
        });
}

function toggleBancoOrigen(val) {
    const group = document.getElementById('pBancoGroup');
    if (group) {
        group.style.display = val === 'transferencia_bancaria' ? 'block' : 'none';
    }
}

function enviarPago(incId, confirmarPago){
    const valor = document.getElementById('pValor').value;
    const fecha = document.getElementById('pFecha').value;
    const formaPago = document.getElementById('pFormaPago').value;
    const bancoId = document.getElementById('pBancoCuentaId')?.value || null;
    const pagadoA = document.getElementById('pPagadoA').value;
    const detalle = document.getElementById('pDetalle').value;

    if (!valor || Number(valor) <= 0) {
        alert('Por favor ingrese un valor válido para el anticipo.');
        return;
    }
    if (!fecha) {
        alert('Por favor ingrese la fecha del anticipo.');
        return;
    }
    if (formaPago === 'transferencia_bancaria' && !bancoId) {
        alert('Por favor seleccione una cuenta de banco origen.');
        return;
    }

    fetch(`/admin/incapacidades/${incId}/pago`,{
        method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':TOKEN},
        body: JSON.stringify({
            valor_pago: valor,
            fecha_pago: fecha,
            pagado_a:   pagadoA,
            forma_pago: formaPago,
            banco_cuenta_id: bancoId,
            detalle_pago: detalle,
            confirmar_pago: confirmarPago ? 1 : 0,
            _token: TOKEN
        })
    }).then(r => r.json().then(d => ({ d, status: r.status }))).then(({ d, status })=>{
        if (status === 409 && d.requiere_confirmacion) {
            if (confirmarAvisosPago(d)) enviarPago(incId, true);
            return;
        }
        if(d.ok){ 
            document.getElementById('modalPago').classList.remove('open'); 
            verDetalle(incId); 
            if (typeof window.recargarTabla === 'function') {
                window.recargarTabla();
            } else {
                location.reload();
            }
        }
        else alert('Error: '+(d.message||''));
    });
}

// ── Subir documento (modal persistente con lista de docs) ───────────────────
let _docIncId = null;
function subirDocumento(incId){
    _docIncId = incId;
    let overlay = document.getElementById('modalDoc');
    if(!overlay){
        overlay = document.createElement('div');
        overlay.id = 'modalDoc';
        overlay.className = 'modal-overlay';
        overlay.innerHTML = `<div class="modal" style="max-width:580px">
            <div class="modal-header" style="background:linear-gradient(135deg,#059669,#0891b2)">
                <div><h3 style="color:#fff;font-size:1rem;font-weight:700;margin:0">📎 Documentos de Incapacidad</h3>
                <div id="docModalSubtitle" style="font-size:.75rem;color:rgba(255,255,255,.8);margin-top:.1rem">Incapacidad #${incId}</div></div>
                <button class="btn-close-modal" onclick="cerrarModalDoc()">✕</button>
            </div>
            <div class="modal-body">
                <div id="docListaExistente" style="margin-bottom:1rem"></div>
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:.85rem">
                    <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#64748b;letter-spacing:.05em;margin-bottom:.7rem">➕ Agregar Documento</div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem">
                        <div class="form-group">
                            <label>Tipo de Documento *</label>
                            <select id="docTipo" class="form-control">
                                <option value="incapacidad_original">📄 Incapacidad Original</option>
                                <option value="historia_clinica">📋 Historia Clínica</option>
                                <option value="radicado_entidad">📮 Radicado Entidad</option>
                                <option value="soporte_pago">💳 Soporte de Pago</option>
                                <option value="transcripcion">🏥 Transcripción IPS</option>
                                <option value="cedula">🪪 Cédula</option>
                                <option value="examen">🔬 Examen / Diagnóstico</option>
                                <option value="otro">📎 Otro</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Observación</label>
                            <input type="text" id="docObs" class="form-control" placeholder="Opcional">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Archivo * <span style="font-size:.72rem;color:#64748b">(PDF, JPG, PNG)</span></label>
                        <input type="file" id="docArchivo" accept=".pdf,.jpg,.jpeg,.png,.webp" class="form-control">
                    </div>
                    <div id="docUploadMsg" style="font-size:.8rem;margin-bottom:.5rem"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="cerrarModalDoc()">Cerrar</button>
                <button class="btn btn-primary" id="btnSubirDoc" onclick="enviarDocumento()">📤 Subir Documento</button>
            </div>
        </div>`;
        document.body.appendChild(overlay);
    }
    document.getElementById('docModalSubtitle').textContent = `Incapacidad #${incId}`;
    overlay.classList.add('open');
    cargarDocumentosExistentes(incId);
}

function cargarDocumentosExistentes(incId){
    const box = document.getElementById('docListaExistente');
    if(!box) return;
    box.innerHTML = '<div style="font-size:.78rem;color:#94a3b8">⏳ Cargando documentos...</div>';
    fetch(`/admin/incapacidades/${incId}/show`)
        .then(r=>r.json()).then(data=>{
            const docs = data.incapacidad?.documentos || [];
            if(!docs.length){ box.innerHTML='<div style="font-size:.78rem;color:#94a3b8;padding:.5rem 0">Sin documentos aún.</div>'; return; }
            box.innerHTML = `<div style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#64748b;letter-spacing:.05em;margin-bottom:.5rem">📁 Documentos cargados (${docs.length})</div>`
                + docs.map(d=>`<div style="display:flex;align-items:center;justify-content:space-between;padding:.45rem .65rem;background:#fff;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:.35rem;font-size:.8rem">
                    <div><span style="font-weight:600;color:#374151">📄 ${d.tipo_documento||'Documento'}</span><span style="color:#64748b;margin-left:.5rem">${formatFechaLarga(d.created_at)}</span>${d.observacion?`<div style="color:#94a3b8;font-size:.72rem">${d.observacion}</div>`:''}</div>
                    <a href="/admin/incapacidades/documento/${d.id}/ver" target="_blank" style="color:#2563eb;font-size:.78rem;white-space:nowrap">👁 Ver</a>
                </div>`).join('');
        });
}

// Tope de subida. Debe ir alineado con la regla `max:10240` del controlador.
// El límite de PHP en public/.htaccess está deliberadamente 2 MB más arriba
// para que rechace Laravel con un mensaje claro y no Apache con un 413 en HTML.
const DOC_MAX_MB = 10;

/**
 * Reduce una imagen antes de subirla.
 *
 * Una foto de celular sale de 4-10 MB; a 2200 px y calidad 0.72 el documento se
 * lee igual y pesa ~300 KB. Así no se choca contra el límite de subida de PHP
 * ni se gasta disco del servidor. Los PDF se devuelven intactos: el navegador no
 * los puede recomprimir, de eso se encarga CompresorDocumentoService en el back.
 */
function comprimirImagenParaSubir(file, anchoMax = 2200, calidad = 0.72){
    if(!file.type.startsWith('image/')) return Promise.resolve(file);

    return new Promise(resolve => {
        const url = URL.createObjectURL(file);
        const img = new Image();
        img.onload = () => {
            URL.revokeObjectURL(url);
            try {
                const escala = Math.min(1, anchoMax / img.width);
                const canvas = document.createElement('canvas');
                canvas.width  = Math.round(img.width  * escala);
                canvas.height = Math.round(img.height * escala);
                const ctx = canvas.getContext('2d');
                // Fondo blanco: los PNG con transparencia salen negros en JPEG.
                ctx.fillStyle = '#fff';
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                canvas.toBlob(blob => {
                    // Si comprimir no ayudó, se sube el original.
                    if(!blob || blob.size >= file.size) return resolve(file);
                    const nombre = file.name.replace(/\.[^.]+$/, '') + '.jpg';
                    resolve(new File([blob], nombre, { type:'image/jpeg' }));
                }, 'image/jpeg', calidad);
            } catch(e){ resolve(file); }
        };
        img.onerror = () => { URL.revokeObjectURL(url); resolve(file); };
        img.src = url;
    });
}

/**
 * Lee la respuesta tolerando que NO sea JSON.
 *
 * Cuando PHP rechaza la subida por tamaño (413), expira el CSRF (419) o revienta
 * (500), Laravel responde una página HTML. Al hacer r.json() a ciegas eso salía
 * como "Unexpected token '<'", que no le dice nada al usuario. Aquí se traduce
 * el código de estado a un mensaje accionable.
 */
async function leerRespuestaDocumento(r){
    const texto = await r.text();
    try { return { ok: r.ok, data: JSON.parse(texto) }; } catch(e){ /* no era JSON */ }

    const porEstado = {
        413: `El archivo es muy grande para el servidor. Comprímelo o divídelo (máximo ${DOC_MAX_MB} MB).`,
        419: 'La sesión expiró. Recarga la página e intenta de nuevo.',
        401: 'La sesión expiró. Recarga la página e intenta de nuevo.',
        422: 'El archivo no es válido. Debe ser PDF, JPG o PNG.',
        500: 'El servidor falló al guardar el documento. Avisa a soporte.',
    };
    return { ok:false, data:{ ok:false, message: porEstado[r.status] || `Error ${r.status} al subir el documento.` } };
}

async function enviarDocumento(){
    const incId = _docIncId;
    let archivo = document.getElementById('docArchivo').files[0];
    if(!archivo){ alert('Selecciona un archivo'); return; }

    const btn = document.getElementById('btnSubirDoc');
    const msg = document.getElementById('docUploadMsg');
    btn.disabled = true; btn.textContent = '⏳ Optimizando...';
    msg.textContent = '';

    try {
        const pesoOriginal = archivo.size;
        archivo = await comprimirImagenParaSubir(archivo);

        if(archivo.size > DOC_MAX_MB * 1024 * 1024){
            btn.disabled=false; btn.textContent='📤 Subir Documento';
            msg.innerHTML = `<span style="color:#ef4444">El archivo pesa ${(archivo.size/1048576).toFixed(1)} MB y el máximo es ${DOC_MAX_MB} MB. Reduce la calidad del escaneo o súbelo por páginas.</span>`;
            return;
        }
        if(archivo.size < pesoOriginal){
            msg.innerHTML = `<span style="color:#64748b">Optimizado: ${(pesoOriginal/1048576).toFixed(1)} MB → ${(archivo.size/1048576).toFixed(1)} MB</span>`;
        }

        btn.textContent = '⏳ Subiendo...';
        const fd = new FormData();
        fd.append('tipo_documento', document.getElementById('docTipo').value);
        fd.append('observacion', document.getElementById('docObs').value);
        fd.append('archivo', archivo);
        fd.append('_token', TOKEN);

        const r = await fetch(`/admin/incapacidades/${incId}/documento`, {
            method: 'POST',
            body: fd,
            // Sin estas cabeceras Laravel responde con un redirect a HTML en vez
            // de un 422 JSON, y el front se queda sin saber qué falló.
            headers: { 'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest', 'X-CSRF-TOKEN': TOKEN },
        });

        const { data } = await leerRespuestaDocumento(r);
        btn.disabled=false; btn.textContent='📤 Subir Documento';

        if(data.ok){
            // Cerrar automáticamente y refrescar la pestaña Documentos
            cerrarModalDoc();
        } else {
            // Laravel manda los errores de validación en `errors`, no en `message`.
            const detalle = data.errors ? Object.values(data.errors).flat().join(' ') : null;
            msg.innerHTML=`<span style="color:#ef4444">${detalle || data.message || 'Error al subir'}</span>`;
        }
    } catch(e){
        btn.disabled=false;
        btn.textContent='📤 Subir Documento';
        msg.innerHTML=`<span style="color:#ef4444">No se pudo conectar con el servidor: ${e.message}</span>`;
    }
}


function cerrarModalDoc() {
    document.getElementById('modalDoc')?.classList.remove('open');

    // Resetear cache para que la pestaña Documentos recargue al volver
    _docsFamiliaLoaded = null;

    // Si la pestaña Documentos del modal de detalle está activa, recargarla ahora
    const tabDocBtn = document.querySelector('#modalDetalle .tab-btn.active');
    const incId = document.getElementById('modalDetalle')?.dataset?.incId;
    if (incId && tabDocBtn && tabDocBtn.textContent.includes('Documentos')) {
        cargarDocsFamilia(parseInt(incId));
    }

    // Si el panel de docs del modal editar está visible, refrescarlo
    if (_docIncId && document.getElementById('editarDocsList')) {
        mostrarDocsEnEditar(_docIncId, '');
    }
}

// ── Autocompletado de cliente ────────────────────────────────────────────────
let clienteTimeout;
function buscarCliente(val){
    clearTimeout(clienteTimeout);
    if(val.length < 3) return;
    clienteTimeout = setTimeout(()=>{
        fetch(`/admin/incapacidades/api/clientes?cedula=${encodeURIComponent(val)}`)
            .then(r=>r.json()).then(data=>{
                const box = document.getElementById('clienteSugerencias');
                if(!data.length){ box.style.display='none'; return; }
                box.innerHTML = data.map(c=>{
                    const emp = (c.empresa_nombre||'').replace(/'/g,"\\'");
                    const nom = `${c.primer_nombre||''} ${c.primer_apellido||''}`.trim().replace(/'/g,"\\'");
                    return `<div onclick="seleccionarCliente('${c.cedula}','${nom}','${emp}', ${c.eps_id || 'null'}, ${c.pension_id || 'null'})"
                         style="padding:.45rem .75rem;cursor:pointer;font-size:.82rem;border-bottom:1px solid #f1f5f9"
                         onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background=''">
                        <strong>${c.cedula}</strong> \u2014 ${nombreOracion((c.primer_nombre||'')+' '+(c.primer_apellido||''))}
                        ${c.empresa_nombre?`<span style="color:#64748b;font-size:.75rem"> \u00b7 ${c.empresa_nombre}</span>`:''}
                    </div>`;
                }).join('');
                box.style.display='block';
            });
    }, 350);
}

// Stored contracts data for quick lookup
let _contratosData = [];
let _clienteEpsId  = null;
let _clienteArlId  = null;
let _clienteAfpId  = null;
let _fallbackEpsId = null;
let _fallbackPensionId = null;
let _clienteNombre = '';
let _empresaNombre = '';

function seleccionarCliente(cedula, nombre, empresaNombre, clienteEpsId = null, clientePensionId = null){
    document.getElementById('cedulaInput').value = cedula;
    document.getElementById('nombreCliente').value = nombre;
    document.getElementById('clienteSugerencias').style.display='none';
    revisarPosibleProrroga();
    _clienteNombre = nombre;
    _empresaNombre = empresaNombre || '';
    _fallbackEpsId = clienteEpsId;
    _fallbackPensionId = clientePensionId;
    // Poblar Quien Remite inmediatamente con afiliado y empresa
    const qrSel = document.getElementById('quienRemiteSelect');
    if(qrSel){
        qrSel.innerHTML = `<option value="${nombre}">${nombre} (Afiliado)</option>`
            + (_empresaNombre ? `<option value="${_empresaNombre}">${_empresaNombre} (Empresa)</option>` : '');
    }
    // Cargar contratos
    fetch(`/admin/incapacidades/api/contratos?cedula=${encodeURIComponent(cedula)}`)
        .then(r=>r.json()).then(contratos=>{
            _contratosData = contratos;
            const sel = document.getElementById('contratoSelect');
            sel.innerHTML = '<option value="">Sin contrato especifico</option>';
            let primeraVigente = null;
            contratos.forEach(c=>{
                const vigente = c.estado === 'vigente';
                const estadoEmoji = vigente ? '🟢' : '🔴';
                const rsNombre = c.razon_social_nombre || 'Sin razón social';
                const opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = `${estadoEmoji} Contrato #${c.id} — ${c.estado.toUpperCase()} (${c.fecha_ingreso?.substring(0,10)||''}) — [${rsNombre}]`;
                if(vigente){ 
                    opt.style.color='#059669'; 
                    opt.style.fontWeight='600'; 
                    if(!primeraVigente) primeraVigente = c.id; 
                } else {
                    opt.style.color='#ef4444';
                }
                sel.appendChild(opt);
            });
            if(primeraVigente){ sel.value = primeraVigente; contratoSeleccionado(sel); }
            else if(contratos.length > 0){ sel.value = contratos[0].id; contratoSeleccionado(sel); }
        });
}


function contratoSeleccionado(sel){
    console.log('contratoSeleccionado disparado con valor:', sel.value);
    console.log('Colección _contratosData actual:', _contratosData);
    const id = parseInt(sel.value);
    const c  = _contratosData.find(x=>x.id==sel.value);
    console.log('Contrato encontrado:', c);
    const boxOk   = document.getElementById('contratoInfoBox');
    const boxWarn = document.getElementById('contratoInfoBoxInactivo');
    const txtOk   = document.getElementById('contratoInfoText');
    const txtWarn = document.getElementById('contratoInfoTextInactivo');
    
    const rsInput = document.getElementById('razonSocialInput');
    const rsNitInput = document.getElementById('razonSocialNitInput');
    
    if(c){
        const rsH = document.getElementById('razonSocialHidden');
        if(rsH) rsH.value = c.razon_social_id || '';
        _clienteEpsId = c.eps_id || _fallbackEpsId || null;
        _clienteArlId = c.arl_id || null;
        _clienteAfpId = c.pension_id || _fallbackPensionId || null;
        
        console.log('Entidades resueltas para autocompletado — EPS:', _clienteEpsId, 'ARL:', _clienteArlId, 'AFP:', _clienteAfpId);
        
        const rsNombre = c.razon_social_nombre || 'Sin razón social';
        const rsNit    = c.razon_social_nit    || 'Sin registrar';
        if(rsInput) rsInput.value = rsNombre;
        if(rsNitInput) rsNitInput.value = rsNit;
        
        const salario  = c.salario ? '$'+Number(c.salario).toLocaleString('es-CO') : 'No registrado';
        const txt = `${rsNombre} — Ingreso: ${c.fecha_ingreso?.substring(0,7)||'?'} — Salario: ${salario}`;
        if(c.estado === 'vigente'){
            if(boxOk){ boxOk.style.display='block'; if(txtOk) txtOk.textContent=txt; }
            if(boxWarn) boxWarn.style.display='none';
        } else {
            if(boxWarn){ boxWarn.style.display='block'; if(txtWarn) txtWarn.textContent=txt; }
            if(boxOk) boxOk.style.display='none';
        }
        
        const tipoEnt = document.getElementById('tipoEntidadSelect')?.value;
        if(tipoEnt === 'eps' && _clienteEpsId) actualizarListaEntidades('eps', _clienteEpsId);
        else if(tipoEnt === 'arl' && _clienteArlId) actualizarListaEntidades('arl', _clienteArlId);
        else if(tipoEnt === 'afp' && _clienteAfpId) actualizarListaEntidades('afp', _clienteAfpId);
        
        // Quien Remite: usa _empresaNombre (empresa real del cliente), NO razón social
        const qrSel = document.getElementById('quienRemiteSelect');
        if(qrSel){
            qrSel.innerHTML = `<option value="${_clienteNombre}">${_clienteNombre} (Afiliado)</option>`
                + (_empresaNombre ? `<option value="${_empresaNombre}">${_empresaNombre} (Empresa)</option>` : '');
        }
    } else {
        _clienteEpsId = _fallbackEpsId || null;
        _clienteArlId = null;
        _clienteAfpId = _fallbackPensionId || null;
        if(rsInput) rsInput.value = '';
        if(rsNitInput) rsNitInput.value = '';
        if(boxOk)   boxOk.style.display='none';
        if(boxWarn) boxWarn.style.display='none';
    }
}


function tipoIncapacidadCambiado(val){
    const MAP = {'accidente_laboral':'arl','accidente_transito':'arl','enfermedad_laboral':'arl'};
    const tipoEnt = MAP[val];
    const s = document.getElementById('tipoEntidadSelect');
    if(s){
        if(tipoEnt){
            s.value = tipoEnt;
            // Auto-seleccionar entidad del contrato (ARL o EPS)
            const autoId = tipoEnt==='arl' ? _clienteArlId : (tipoEnt==='eps' ? _clienteEpsId : null);
            actualizarListaEntidades(tipoEnt, autoId);
        } else if(val && s.value==='arl'){
            s.value=''; document.getElementById('entidadSelect').innerHTML='<option value="">Seleccionar...</option>';
        }
    }
}

async function guardarIncapacidad(){
    const form   = document.getElementById('formCrear');
    const isEdit = document.getElementById('formMethod').value === 'PUT';
    const incId  = document.getElementById('formId').value;
    const url    = isEdit ? `/admin/incapacidades/${incId}` : form.dataset.storeUrl;
    const fd = new FormData(form);
    if(isEdit) fd.set('_method','PUT');
    try {
        const resp = await fetch(url, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': TOKEN,
                'Accept': 'application/json'
            },
            body: fd
        });
        let data;
        try { data = await resp.json(); } catch(e){ throw new Error('Respuesta inesperada del servidor (HTTP '+resp.status+')'); }
        if(resp.ok && data.ok){
            cerrarModal('modalCrear');
            if (!isEdit && data.incapacidad_id) {
                setTimeout(() => { verDetalle(data.incapacidad_id); }, 250);
            } else {
                location.reload();
            }
        } else if(resp.status === 422 && data.errors) {
            // Mostrar errores de validación
            const msgs = Object.entries(data.errors).map(([f,e])=>`• ${f}: ${e[0]}`).join('\n');
            alert('⚠️ Hay campos requeridos incompletos:\n\n'+msgs);
        } else {
            alert('❌ Error: '+(data.message||JSON.stringify(data)));
        }
    } catch(e){ alert('❌ Error de red: '+e.message); }
}

// Lista dinamica de entidades
function actualizarListaEntidades(tipo, selId=null){
    const sel = document.getElementById('entidadSelect');
    const listas = {eps:EPS_LIST, arl:ARL_LIST, afp:AFP_LIST};
    const lista = listas[tipo]||[];
    sel.innerHTML = '<option value="">Seleccionar...</option>';
    lista.forEach(e=>{ sel.innerHTML+=`<option value="${e.id}" ${selId&&e.id==selId?'selected':''}>${e.nombre}</option>`; });
    
    if(!selId){
        if(tipo === 'eps' && _clienteEpsId) sel.value = _clienteEpsId;
        if(tipo === 'arl' && _clienteArlId) sel.value = _clienteArlId;
        if(tipo === 'afp' && _clienteAfpId) sel.value = _clienteAfpId;
    }
}


// ── Cálculo automático de fecha fin ─────────────────────────────────────────
function calcularFechaFin(){
    const inicio = document.querySelector('[name=fecha_inicio]').value;
    const dias   = parseInt(document.querySelector('[name=dias_incapacidad]').value||0);
    if(inicio && dias>0){
        const d = new Date(inicio);
        d.setDate(d.getDate()+dias-1);
        document.getElementById('fechaFinInput').value = d.toISOString().substring(0,10);
    }
    revisarPosibleProrroga();
}

// ── Aviso de posible prórroga al crear ───────────────────────────────────────
// El sistema no decide solo: propone. Fechas pegadas no implican mismo caso (un
// accidente el viernes y una gripa el lunes encadenan igual de bien), y ligar
// cambia el valor esperado en EPS, donde la prórroga no descuenta los 2 días.
let _vecinasTimeout = null;

function revisarPosibleProrroga(){
    const caja = document.getElementById('avisoProrroga');
    if(!caja) return;

    // No estorbar al editar ni cuando ya se está registrando una prórroga desde
    // el botón del detalle (ahí el padre ya viene decidido).
    const esCreacion = document.getElementById('formMethod')?.value === 'POST';
    if(!esCreacion || document.getElementById('padreId').value){ return; }

    const cedula = document.getElementById('cedulaInput')?.value?.trim();
    const inicio = document.querySelector('[name=fecha_inicio]')?.value;
    if(!cedula || cedula.length < 5 || !inicio){ caja.style.display='none'; return; }

    clearTimeout(_vecinasTimeout);
    _vecinasTimeout = setTimeout(()=>{
        fetch(`/admin/incapacidades/api/vecinas?cedula=${encodeURIComponent(cedula)}&fecha_inicio=${inicio}`,
              { headers: { 'Accept': 'application/json' } })
            .then(r => r.ok ? r.json() : null)
            .then(d => pintarAvisoProrroga(d?.vecinas || []))
            .catch(()=>{});
    }, 350);
}

function pintarAvisoProrroga(vecinas){
    const caja = document.getElementById('avisoProrroga');
    if(!caja) return;
    if(!vecinas.length){ caja.style.display='none'; caja.innerHTML=''; return; }

    const filas = vecinas.slice(0,3).map(v => {
        const motivo = {
            continua:  'termina el día anterior',
            mismo_dia: 'termina el mismo día en que empieza esta — ese día lo paga la entidad en una sola de las dos',
            solapa:    `se cruza ${Math.abs(v.gap)+1} día(s) con esta — esos días los paga la entidad en una sola de las dos`,
        }[v.relacion] || '';
        return `<div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;padding:.35rem 0;border-top:1px solid #fde68a">
            <span style="font-size:.78rem;color:#78350f">
                <strong>#${v.id}</strong> ${v.label} · ${v.dias_incapacidad}d del ${v.fecha_inicio} al ${v.fecha_terminacion}
                · ${(v.tipo_entidad||'').toUpperCase()} ${v.entidad_nombre||''} — ${motivo}
            </span>
            <button type="button" class="btn btn-sm btn-primary" style="font-size:.7rem"
                    onclick="marcarComoProrroga(${v.padre_id}, '${(v.label||'').replace(/'/g,"")} #${v.id}')">
                Sí, es prórroga de esta</button>
        </div>`;
    }).join('');

    caja.innerHTML = `<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:.6rem .85rem">
        <div style="font-size:.8rem;font-weight:700;color:#92400e;margin-bottom:.2rem">
            📎 Esta persona ya tiene ${vecinas.length===1?'una incapacidad':'incapacidades'} pegada${vecinas.length===1?'':'s'} a este período
        </div>
        ${filas}
        <div style="margin-top:.4rem"><button type="button" class="btn btn-sm btn-secondary" style="font-size:.7rem"
             onclick="descartarProrroga()">No, es independiente</button></div>
    </div>`;
    caja.style.display='';
}

function marcarComoProrroga(padreId, etiqueta){
    document.getElementById('padreId').value = padreId;
    const caja = document.getElementById('avisoProrroga');
    caja.innerHTML = `<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:.55rem .85rem;
                                  font-size:.8rem;color:#1e40af;display:flex;gap:.6rem;align-items:center;flex-wrap:wrap">
        <span>🔗 Se registrará como <strong>prórroga de ${etiqueta}</strong>.</span>
        <button type="button" class="btn btn-sm btn-secondary" style="font-size:.7rem"
                onclick="descartarProrroga();revisarPosibleProrroga()">Deshacer</button>
    </div>`;
    caja.style.display='';
}

function descartarProrroga(){
    document.getElementById('padreId').value = '';
    const caja = document.getElementById('avisoProrroga');
    caja.style.display='none'; caja.innerHTML='';
}

// Cerrar sugerencias al hacer clic fuera
document.addEventListener('click', e=>{
    if(!e.target.closest('#cedulaInput')) document.getElementById('clienteSugerencias').style.display='none';
});

// ── Filtros desplegables en los encabezados de columna ──────────────────────
// El menú es position:fixed y se posiciona a mano: dentro del <th> quedaría
// recortado por el overflow-x de .table-wrap.
document.addEventListener('click', e => {
    const btn = e.target.closest('.th-filtro-btn');
    const abierto = document.querySelector('.th-filtro-menu.open');

    if (abierto && (!btn || abierto.previousElementSibling !== btn)) {
        abierto.classList.remove('open');
    }
    if (!btn) return;

    const menu = btn.nextElementSibling;
    if (!menu) return;
    if (menu.classList.contains('open')) { menu.classList.remove('open'); return; }

    const r = btn.getBoundingClientRect();
    menu.style.top  = (r.bottom + 4) + 'px';
    menu.style.left = Math.min(r.left, window.innerWidth - 210) + 'px';
    menu.classList.add('open');
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') document.querySelector('.th-filtro-menu.open')?.classList.remove('open');
});
// Un menú fixed no sigue al contenido: se cierra al desplazar o redimensionar.
['scroll', 'resize'].forEach(ev => window.addEventListener(ev, () => {
    document.querySelector('.th-filtro-menu.open')?.classList.remove('open');
}, true));

// ── Alpine.js: filtros con debounce ─────────────────────────────────────────
function filtrosInc(){
    return {
        busqueda: document.getElementById('inp-busqueda')?.value || '',
        timer: null,
        init(){},
        debouncedSubmit(){
            clearTimeout(this.timer);
            this.timer = setTimeout(() => {
                document.getElementById('filtro-form').submit();
            }, 400);
        }
    };
}

// ── Gestión rápida desde lista ───────────────────────────────────────────────
function abrirModalGestion(id, solo_seguimiento = false){
    verDetalle(id); // Abre el detalle y dentro se puede registrar gestión
}

// ── Documentos de familia (pestaña en modal detalle) ─────────────────────────
function cargarDocsFamilia(incId) {
    const box = document.getElementById('docsFamiliaContainer');
    if (!box) return;
    if (_docsFamiliaLoaded === incId) return; // ya cargado
    // Resetear flex del placeholder para que el contenido sea block
    box.style.cssText = 'padding:.25rem 0';
    box.innerHTML = '<div style="text-align:center;padding:1.5rem;color:#94a3b8">⏳ Cargando documentos...</div>';
    fetch(`/admin/incapacidades/${incId}/documentos-familia`)
        .then(r => r.json()).then(data => {
            _docsFamiliaLoaded = incId;
            if (!data.ok) {
                box.innerHTML = '<div style="text-align:center;padding:1.5rem;color:#ef4444">Error al cargar.</div>';
                return;
            }
            const TIPOS_DOC = {
                incapacidad_original: '📄 Inc. Original',
                historia_clinica:     '📋 Hist. Clínica',
                radicado_entidad:     '📮 Radicado Entidad',
                soporte_pago:         '💳 Soporte Pago',
                transcripcion:        '🏥 Transcripción',
                cedula:               '🪪 Cédula',
                examen:               '🔬 Examen',
                otro:                 '📎 Otro',
            };
            const iconExt = e => ({pdf:'📄',jpg:'🖼️',jpeg:'🖼️',png:'🖼️',webp:'🖼️'}[e]||'📎');

            let html = '';

            // ── Documentos globales del cliente ──────────────────────────────
            const globales = data.docs_globales || [];
            if (globales.length > 0) {
                html += `<div style="border:1px solid #bbf7d0;border-radius:10px;overflow:hidden;margin-bottom:.75rem">
                    <div style="background:#f0fdf4;padding:.55rem .85rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.4rem">
                        <span style="font-weight:700;color:#059669;font-size:.83rem">
                            🗂️ Documentos Globales del Cliente
                            <span style="font-size:.7rem;color:#64748b;font-weight:400;margin-left:.5rem">cargados en afiliación / contrato</span>
                        </span>
                        <span style="font-size:.7rem;color:#064e3b;background:#d1fae5;padding:.15rem .5rem;border-radius:999px">${globales.length} doc(s)</span>
                    </div>`;
                globales.forEach(doc => {
                    const tipoLabel = doc.tipo_label || doc.tipo_documento;
                    html += `<div style="display:flex;align-items:center;justify-content:space-between;padding:.5rem .85rem;border-top:1px solid #f1f5f9;gap:.5rem">
                        <div style="flex:1;min-width:0">
                            <span style="font-weight:600;color:#374151;font-size:.82rem">${tipoLabel}</span>
                            <div style="font-size:.68rem;color:#94a3b8;margin-top:.1rem">
                                🕐 ${formatFechaLarga(doc.fecha)} · 👤 ${doc.subido_por||'Sistema'} · <span style="color:#059669">✓ Global</span>
                            </div>
                        </div>
                        <div style="display:flex;gap:.3rem;flex-shrink:0">
                            ${doc.url_ver
                                ? (doc.es_pdf
                                    ? `<button class="btn btn-info btn-sm" onclick="verPdfDoc('${doc.url_ver}','${tipoLabel}')" title="Ver PDF">👁 Ver</button>`
                                    : `<a href="${doc.url_ver}" target="_blank" class="btn btn-info btn-sm">👁 Ver</a>`)
                                : '<span style="font-size:.72rem;color:#94a3b8">Sin archivo</span>'
                            }
                        </div>
                    </div>`;
                });
                html += `</div>`;
            }

            // ── Documentos por incapacidad / prórroga ─────────────────────────
            const familia = data.familia || [];
            if (!familia.length && !globales.length) {
                html = '<div style="text-align:center;padding:1.5rem;color:#94a3b8">Sin documentos registrados.</div>';
                box.innerHTML = html;
                return;
            }

            familia.forEach(grupo => {
                const esPadre = grupo.es_padre;
                const headerBg     = esPadre ? '#eff6ff' : '#fdf4ff';
                const headerBorder = esPadre ? '#bfdbfe' : '#e9d5ff';
                const headerColor  = esPadre ? '#1e40af' : '#7c3aed';
                const periodo = grupo.fecha_inicio
                    ? `${grupo.fecha_inicio.substring(0,10)} → ${(grupo.fecha_terminacion||'').substring(0,10)}`
                    : '';

                html += `<div style="border:1px solid ${headerBorder};border-radius:10px;overflow:hidden;margin-bottom:.75rem">
                    <div style="background:${headerBg};padding:.55rem .85rem">
                        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.4rem">
                            <div>
                                <span style="font-weight:700;color:${headerColor};font-size:.83rem">
                                    ${esPadre ? '🏥 Incapacidad Original' : `🔁 ${grupo.label}`}
                                    <span style="font-size:.7rem;color:#64748b;font-weight:400;margin-left:.4rem">#${grupo.incapacidad_id}</span>
                                </span>
                                ${periodo ? `<div style="font-size:.7rem;color:#64748b;margin-top:.15rem">📅 ${periodo} · ${grupo.dias_incapacidad} días</div>` : ''}
                            </div>
                            <div style="display:flex;align-items:center;gap:.5rem">
                                <span style="font-size:.72rem;color:${headerColor};background:${esPadre?'#dbeafe':'#ede9fe'};padding:.15rem .5rem;border-radius:999px;font-weight:600">${grupo.total_docs} doc(s)</span>
                                <button class="btn btn-sm" style="background:#e0e7ff;color:#3730a3;font-size:.72rem;padding:.22rem .6rem;border-radius:6px"
                                    onclick="subirDocumentoEn(${grupo.incapacidad_id})">+ Agregar</button>
                            </div>
                        </div>
                    </div>`;

                if (!grupo.documentos?.length) {
                    html += `<div style="padding:.65rem .85rem;font-size:.78rem;color:#94a3b8;border-top:1px solid #f1f5f9">Sin documentos en este grupo.</div>`;
                } else {
                    grupo.documentos.forEach(doc => {
                        const tipoLabel = TIPOS_DOC[doc.tipo_documento] || doc.tipo_documento;
                        const ext = doc.extension || '';
                        html += `<div style="display:flex;align-items:center;justify-content:space-between;padding:.5rem .85rem;border-top:1px solid #f1f5f9;gap:.5rem">
                            <div style="flex:1;min-width:0">
                                <span style="font-weight:600;color:#374151;font-size:.82rem">${iconExt(ext)} ${tipoLabel}</span>
                                ${doc.observacion ? `<span style="color:#94a3b8;font-size:.72rem;margin-left:.4rem">— ${doc.observacion}</span>` : ''}
                                <div style="font-size:.68rem;color:#94a3b8;margin-top:.1rem">
                                    🕐 ${formatFechaLarga(doc.fecha)} · 👤 ${doc.subido_por||'Sistema'}
                                </div>
                            </div>
                            <div style="display:flex;gap:.3rem;flex-shrink:0">
                                ${doc.es_pdf
                                    ? `<button class="btn btn-info btn-sm" onclick="verPdfDoc('${doc.url_ver}','${tipoLabel}')" title="Ver PDF">👁 Ver</button>`
                                    : `<a href="${doc.url_ver}" target="_blank" class="btn btn-info btn-sm" title="Ver imagen">👁 Ver</a>`
                                }
                                <a href="${doc.url_descargar}" class="btn btn-secondary btn-sm" title="Descargar">⬇</a>
                            </div>
                        </div>`;
                    });
                }
                html += `</div>`;
            });

            // Botón subir al FINAL, con ancho completo
            const totalDocs = data.total_documentos || 0;
            html += `<div style="margin-top:.5rem;padding-top:.5rem;border-top:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between">
                <span style="font-size:.72rem;color:#64748b">📁 ${totalDocs} doc(s) en incapacidades${globales.length?` · ${globales.length} global(es)`:''}</span>
                <button class="btn btn-success btn-sm" onclick="subirDocumento(${incId})">📤 Subir Documento</button>
            </div>`;

            box.innerHTML = html;
        }).catch(() => {
            box.style.cssText = '';
            box.innerHTML = '<div style="text-align:center;padding:1rem;color:#ef4444">Error al cargar documentos.</div>';
        });
}


// Subir doc en una incapacidad específica de la familia
function subirDocumentoEn(incId) {
    _docsFamiliaLoaded = null; // forzar recarga
    subirDocumento(incId);
}

// Visor de PDF en modal ligero
function verPdfDoc(url, titulo) {
    let ov = document.getElementById('modalPdfViewer');
    if (!ov) {
        ov = document.createElement('div');
        ov.id = 'modalPdfViewer';
        ov.className = 'modal-overlay';
        ov.innerHTML = `<div class="modal" style="max-width:860px;height:90vh;display:flex;flex-direction:column">
            <div class="modal-header">
                <h3 id="pdfViewerTitle" style="color:#fff;font-size:1rem">📄 Documento</h3>
                <button class="btn-close-modal" onclick="document.getElementById('modalPdfViewer').classList.remove('open')">✕</button>
            </div>
            <div style="flex:1;overflow:hidden;background:#525659">
                <iframe id="pdfViewerFrame" style="width:100%;height:100%;border:none"></iframe>
            </div>
            <div class="modal-footer" style="background:#f8fafc">
                <a id="pdfDownloadLink" class="btn btn-primary btn-sm" download>⬇ Descargar</a>
                <button class="btn btn-secondary btn-sm" onclick="document.getElementById('modalPdfViewer').classList.remove('open')">Cerrar</button>
            </div>
        </div>`;
        document.body.appendChild(ov);
    }
    document.getElementById('pdfViewerTitle').textContent = '📄 ' + titulo;
    document.getElementById('pdfViewerFrame').src = url;
    document.getElementById('pdfDownloadLink').href = url;
    ov.classList.add('open');
}

// Cerrar modal de detalle al clic en overlay y abrir automáticamente si viene abrir_incId de la URL
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('modalDetalle')?.addEventListener('click', e => {
        if (e.target === document.getElementById('modalDetalle')) cerrarModal('modalDetalle');
    });
    document.getElementById('modalCrear')?.addEventListener('click', e => {
        if (e.target === document.getElementById('modalCrear')) cerrarModal('modalCrear');
    });

    // Auto-abrir modal si se proporciona abrir_incId en la URL
    const urlParams = new URLSearchParams(window.location.search);
    const abrirIncId = urlParams.get('abrir_incId');
    if (abrirIncId) {
        setTimeout(() => {
            verDetalle(abrirIncId);
        }, 300);
    }
});
</script>
@endpush
