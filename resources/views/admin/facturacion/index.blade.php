@extends('layouts.app')
@section('modulo', 'Empresas')

@section('contenido')
<style>
.fat-header { background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%);padding:1.5rem 2rem;border-radius:14px;color:#fff;margin-bottom:1.5rem; }
.fat-title  { font-size:1.5rem;font-weight:800;letter-spacing:0.02em; }
.fat-sub    { font-size:0.82rem;color:#94a3b8;margin-top:0.2rem; }
.search-box { position:relative;max-width:480px; }
.search-box input { width:100%;padding:0.7rem 1rem 0.7rem 2.8rem;border:1.5px solid #e2e8f0;border-radius:10px;font-size:0.95rem;outline:none;transition:border .2s; }
.search-box input:focus { border-color:rgba(255,255,255,0.5);box-shadow:0 0 0 3px rgba(255,255,255,0.1); }
.fat-header .search-box input::placeholder { color:rgba(255,255,255,0.5); }
.fat-header .search-box input:focus { border-color:rgba(255,255,255,0.6);box-shadow:0 0 0 3px rgba(255,255,255,0.1); }
.search-icon { position:absolute;left:0.85rem;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:1rem; }
.emp-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:1rem;margin-top:1.2rem; }

/* Card base */
.emp-card {
    background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:1.1rem 1.3rem;
    cursor:pointer;transition:all .18s;text-decoration:none;color:inherit;
    position:relative;overflow:hidden;
}
.emp-card:hover { border-color:#3b82f6;box-shadow:0 4px 18px rgba(59,130,246,0.12);transform:translateY(-2px); }
.emp-card h3 { font-size:1rem;font-weight:700;color:#0f172a;margin:0 0 0.35rem; }
.emp-meta  { font-size:0.78rem;color:#64748b;display:flex;gap:0.6rem;flex-wrap:wrap;align-items:center;margin-top:0.4rem; }

/* Cards con contratos activos: borde verde izquierdo */
.emp-card.con-contratos {
    border-left:4px solid #10b981;
}
.emp-card.con-contratos:hover {
    border-left-color:#059669;
    border-top-color:#10b981;
    border-right-color:#10b981;
    border-bottom-color:#10b981;
    box-shadow:0 4px 18px rgba(16,185,129,0.15);
}

/* Badges */
.emp-badge { font-size:0.65rem;font-weight:700;padding:0.18rem 0.55rem;border-radius:20px; }
.badge-si  { background:#dcfce7;color:#15803d; }
.badge-no  { background:#f1f5f9;color:#64748b; }

/* Badge de contratos activos */
.badge-contratos {
    display:inline-flex;align-items:center;gap:0.3rem;
    font-size:0.68rem;font-weight:700;
    padding:0.2rem 0.6rem;border-radius:20px;
    background:#d1fae5;color:#065f46;
    border:1px solid #a7f3d0;
}
.badge-sin-contratos {
    display:inline-flex;align-items:center;gap:0.3rem;
    font-size:0.68rem;font-weight:600;
    padding:0.2rem 0.6rem;border-radius:20px;
    background:#f1f5f9;color:#94a3b8;
    border:1px solid #e2e8f0;
}

/* Separadores de grupo */
.grupo-label {
    font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;
    color:#64748b;margin:1.2rem 0 0.4rem;padding:0.3rem 0.5rem;
    display:flex;align-items:center;gap:0.5rem;
    border-bottom:1px solid #e2e8f0;padding-bottom:0.4rem;
}
.grupo-label span { font-size:0.65rem;font-weight:500;text-transform:none;
    background:#f1f5f9;color:#64748b;padding:0.1rem 0.45rem;border-radius:20px; }

.emp-icon  { font-size:1.4rem;margin-bottom:0.3rem;display:flex;align-items:center;gap:0.5rem; }
.empty-state { text-align:center;padding:3rem;color:#94a3b8; }
</style>

<div class="fat-header" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
    <div style="flex:1;min-width:200px;">
        <div class="fat-title">🏢 Empresas</div>
        <div class="fat-sub">Seleccione una empresa para gestionar la facturación del período</div>
    </div>
    <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
        <div class="search-box" style="width:300px;max-width:100%;">
            <span class="search-icon" style="color:#94a3b8;">🔍</span>
            <input type="text" id="buscadorEmpresa" placeholder="Buscar empresa o NIT..." autocomplete="off"
                   style="background:rgba(255,255,255,0.1);border-color:rgba(255,255,255,0.2);color:#fff;padding:0.45rem 1rem 0.45rem 2.4rem;font-size:0.85rem;">
        </div>
        <a href="{{ route('admin.facturacion.empresa.create') }}" style="background:#10b981;color:#fff;padding:0.5rem 1.2rem;border-radius:8px;font-size:0.85rem;font-weight:700;text-decoration:none;transition:background .15s;display:inline-flex;align-items:center;gap:0.4rem;white-space:nowrap;">
            ➕ Nueva Empresa
        </a>
    </div>
</div>

<div style="background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:1.2rem 1.4rem;">

    @if($empresas->isEmpty())
        <div class="empty-state">
            <div style="font-size:3rem;">🏢</div>
            <div>No hay empresas registradas para este aliado.</div>
        </div>
    @else
        @php
            $conContratos  = $empresas->filter(fn($e) => $e->contratos_activos_count > 0);
            $sinContratos  = $empresas->filter(fn($e) => $e->contratos_activos_count == 0);
        @endphp

        {{-- Grupo: Con contratos activos --}}
        @if($conContratos->isNotEmpty())
        <div class="grupo-label" id="grupo-activas">
            ✅ Con contratos activos <span>{{ $conContratos->count() }}</span>
        </div>
        <div class="emp-grid" id="gridActivas">
            @foreach($conContratos as $emp)
            <a class="emp-card con-contratos" href="{{ route('admin.facturacion.empresa', $emp->id) }}"
               data-nombre="{{ strtolower($emp->empresa) }}" data-nit="{{ $emp->nit ?? '' }}">
                {{-- Fila 1: ícono + nombre empresa --}}
                <div style="display:flex;align-items:center;gap:0.55rem;margin-bottom:0.45rem;">
                    <span style="font-size:1.4rem;line-height:1;">🏢</span>
                    <h3 style="margin:0;font-size:0.95rem;font-weight:800;color:#0f172a;text-transform:uppercase;letter-spacing:0.02em;">{{ $emp->empresa }}</h3>
                    @include('admin.facturacion.partials.portal_chip', ['emp' => $emp])
                </div>
                {{-- Fila 2: badge + contacto + celular --}}
                <div style="display:flex;align-items:center;gap:0.7rem;flex-wrap:wrap;font-size:0.78rem;color:#475569;">
                    <span class="badge-contratos">● {{ $emp->contratos_activos_count }}</span>
                    @if($emp->contacto)
                    <span style="display:flex;align-items:center;gap:0.25rem;">👤 {{ $emp->contacto }}</span>
                    @endif
                    @if($emp->telefono || $emp->celular)
                    <span style="display:flex;align-items:center;gap:0.25rem;">📞 {{ $emp->celular ?: $emp->telefono }}</span>
                    @endif
                </div>
            </a>
            @endforeach
        </div>
        @endif

        {{-- Grupo: Sin contratos activos --}}
        @if($sinContratos->isNotEmpty())
        <div class="grupo-label" id="grupo-inactivas">
            ⚪ Sin contratos activos <span>{{ $sinContratos->count() }}</span>
        </div>
        <div class="emp-grid" id="gridInactivas">
            @foreach($sinContratos as $emp)
            <a class="emp-card" href="{{ route('admin.facturacion.empresa', $emp->id) }}"
               data-nombre="{{ strtolower($emp->empresa) }}" data-nit="{{ $emp->nit ?? '' }}">
                {{-- Fila 1: ícono + nombre empresa --}}
                <div style="display:flex;align-items:center;gap:0.55rem;margin-bottom:0.45rem;">
                    <span style="font-size:1.4rem;line-height:1;">🏢</span>
                    <h3 style="margin:0;font-size:0.95rem;font-weight:800;color:#0f172a;text-transform:uppercase;letter-spacing:0.02em;">{{ $emp->empresa }}</h3>
                    @include('admin.facturacion.partials.portal_chip', ['emp' => $emp])
                </div>
                {{-- Fila 2: badge + contacto + celular --}}
                <div style="display:flex;align-items:center;gap:0.7rem;flex-wrap:wrap;font-size:0.78rem;color:#475569;">
                    <span class="badge-sin-contratos">Sin contratos</span>
                    @if($emp->contacto)
                    <span style="display:flex;align-items:center;gap:0.25rem;">👤 {{ $emp->contacto }}</span>
                    @endif
                    @if($emp->telefono || $emp->celular)
                    <span style="display:flex;align-items:center;gap:0.25rem;">📞 {{ $emp->celular ?: $emp->telefono }}</span>
                    @endif
                </div>
            </a>
            @endforeach
        </div>
        @endif
    @endif
</div>

@push('scripts')
<script>
const cards = document.querySelectorAll('.emp-card');
// Sin tildes: "construccion" debe encontrar a "Construcción" y al revés.
const norm = t => (t || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
document.getElementById('buscadorEmpresa').addEventListener('input', function() {
    const q = norm(this.value.trim());
    let visibles = 0;

    // Ocultar/mostrar cards
    cards.forEach(c => {
        const match = !q || norm(c.dataset.nombre).includes(q) || c.dataset.nit.includes(q);
        c.style.display = match ? '' : 'none';
        if (match) visibles++;
    });

    // Ocultar grupos completos si no tienen cards visibles
    ['Activas', 'Inactivas'].forEach(grupo => {
        const grid = document.getElementById('grid' + grupo);
        const label = document.getElementById('grupo-' + grupo.toLowerCase());
        if (!grid || !label) return;
        const hayVisibles = [...grid.querySelectorAll('.emp-card')].some(c => c.style.display !== 'none');
        grid.style.display  = hayVisibles ? '' : 'none';
        label.style.display = hayVisibles ? '' : 'none';
    });

    document.getElementById('contadorEmpresas').textContent = visibles;
});
</script>
@endpush
@endsection
