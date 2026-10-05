@extends('layouts.app')

@section('titulo', 'BryNex')
@section('modulo', 'Accesos de Usuarios')

@section('contenido')
<div style="max-width:960px;margin:0 auto">

    {{-- Breadcrumb --}}
    <div style="display:flex;align-items:center;gap:.5rem;font-size:.8rem;color:#64748b;margin-bottom:1.25rem">
        <a href="{{ route('brynex.hub') }}" style="color:#3b82f6;text-decoration:none">🔵 BryNex</a>
        <span>›</span>
        <span>Accesos de Usuarios</span>
    </div>

    {{-- Header --}}
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem">
        <div>
            <h1 style="font-size:1.3rem;font-weight:700;color:#1e293b;margin:0">👥 Accesos de Usuarios BryNex</h1>
            <p style="font-size:.82rem;color:#64748b;margin:.2rem 0 0">
                Define qué usuarios BryNex pueden acceder a cada aliado.
                Los superadmin siempre tienen acceso total.
            </p>
        </div>
    </div>

    {{-- Toast --}}
    <div id="toast-acc" style="display:none;position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999;
         background:#1e293b;color:#fff;padding:.65rem 1.2rem;border-radius:10px;
         font-size:.82rem;box-shadow:0 4px 20px rgba(0,0,0,.3);transition:opacity .3s"></div>

    @if($usuariosBrynex->isEmpty())
    <div style="background:#fff;border-radius:12px;padding:3rem;text-align:center;box-shadow:0 1px 6px rgba(0,0,0,.07);color:#94a3b8">
        <div style="font-size:2rem;margin-bottom:.5rem">👤</div>
        <p>No hay otros usuarios BryNex registrados.</p>
    </div>
    @else

    <div style="display:flex;flex-direction:column;gap:1rem">
        @foreach($usuariosBrynex as $usr)
        @php
            $accesosActivos = $usr->aliados->pluck('id')->toArray();
        @endphp
        <div class="usr-card">
            {{-- Info del usuario --}}
            <div class="usr-header">
                <div class="usr-avatar">{{ strtoupper(substr($usr->nombre, 0, 1)) }}</div>
                <div>
                    <div class="usr-nombre">{{ $usr->nombre }}</div>
                    <div class="usr-email">{{ $usr->email }}</div>
                </div>
                <div class="usr-rol">{{ $usr->getRoleNames()->first() ?? 'sin rol' }}</div>
            </div>

            {{-- Grid de aliados. El superadmin entra a todos (User::puedeAccederAliado):
                 sus casillas no se tocan y el servidor tampoco deja cambiarlas. --}}
            @if($usr->hasRole('superadmin'))
            <div style="padding:.85rem 1.25rem;font-size:.8rem;color:#14532d;background:#f0fdf4;border-top:1px solid #dcfce7">
                ✅ <strong>Acceso total:</strong> por ser superadmin entra a todos los aliados activos. No hace falta habilitarle ninguno.
            </div>
            @else
            <div class="aliados-grid">
                @foreach($aliados as $al)
                @php $tieneAcceso = in_array($al->id, $accesosActivos); @endphp
                <div class="aliado-toggle {{ $tieneAcceso ? 'activo' : '' }}"
                     id="toggle-{{ $usr->id }}-{{ $al->id }}"
                     onclick="toggleAcceso({{ $usr->id }}, {{ $al->id }}, this)"
                     title="{{ $tieneAcceso ? 'Clic para revocar acceso' : 'Clic para habilitar acceso' }}">
                    <span class="at-ico">{{ $tieneAcceso ? '✅' : '🔒' }}</span>
                    <span class="at-nombre">{{ $al->nombre }}</span>
                </div>
                @endforeach
            </div>
            @endif
        </div>
        @endforeach
    </div>
    @endif
</div>
@endsection

@push('styles')
<style>
.usr-card {
    background: #fff; border-radius: 12px; overflow: hidden;
    box-shadow: 0 1px 6px rgba(0,0,0,.07); border: 1px solid #f1f5f9;
}
.usr-header {
    display: flex; align-items: center; gap: 1rem;
    padding: 1rem 1.25rem; border-bottom: 1px solid #f1f5f9;
    background: #f8fafc;
}
.usr-avatar {
    width: 38px; height: 38px; border-radius: 50%;
    background: linear-gradient(135deg, #1e3a8a, #3b82f6);
    color: #fff; font-weight: 700; font-size: 1rem;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.usr-nombre { font-weight: 600; font-size: .9rem; color: #1e293b; }
.usr-email  { font-size: .75rem; color: #64748b; }
.usr-rol {
    margin-left: auto; background: #eff6ff; color: #1d4ed8;
    border: 1px solid #bfdbfe; border-radius: 999px;
    padding: .15rem .7rem; font-size: .7rem; font-weight: 600;
}

.aliados-grid {
    display: flex; flex-wrap: wrap; gap: .6rem; padding: 1rem 1.25rem;
}
.aliado-toggle {
    display: inline-flex; align-items: center; gap: .4rem;
    padding: .4rem .85rem; border-radius: 8px; cursor: pointer;
    border: 1px solid #e2e8f0; background: #f8fafc;
    font-size: .8rem; transition: all .15s; user-select: none;
}
.aliado-toggle:hover {
    border-color: #93c5fd; background: #eff6ff; transform: translateY(-1px);
}
.aliado-toggle.activo {
    background: #dcfce7; border-color: #86efac; color: #14532d;
}
.aliado-toggle.activo:hover {
    background: #bbf7d0; border-color: #4ade80;
}
.at-ico   { font-size: .85rem; }
.at-nombre { font-weight: 500; color: #334155; }
.aliado-toggle.activo .at-nombre { color: #14532d; font-weight: 600; }
</style>
@endpush

@push('scripts')
<script>
const CSRF = document.querySelector('meta[name="csrf-token"]').content;

async function toggleAcceso(userId, alidoId, el) {
    el.style.opacity = '.5';
    try {
        const resp = await fetch('{{ route("brynex.accesos.toggle") }}', {
            method : 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
            body   : JSON.stringify({ user_id: userId, aliado_id: alidoId }),
        });
        const data = await resp.json();
        if (data.ok) {
            const ico    = el.querySelector('.at-ico');
            if (data.activo) {
                el.classList.add('activo');
                ico.textContent = '✅';
                el.title = 'Clic para revocar acceso';
            } else {
                el.classList.remove('activo');
                ico.textContent = '🔒';
                el.title = 'Clic para habilitar acceso';
            }
            mostrarToastAcc(data.mensaje, data.activo ? 'success' : 'warn');
        } else {
            mostrarToastAcc(data.mensaje || 'Error.', 'error');
        }
    } catch(e) {
        mostrarToastAcc('Error de conexión.', 'error');
    } finally {
        el.style.opacity = '1';
    }
}

function mostrarToastAcc(msg, tipo) {
    const t = document.getElementById('toast-acc');
    t.textContent = msg;
    t.style.background = tipo === 'success' ? '#15803d' : tipo === 'error' ? '#dc2626' : '#92400e';
    t.style.display = 'block';
    t.style.opacity = '1';
    setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.style.display = 'none', 300); }, 2500);
}
</script>
@endpush
