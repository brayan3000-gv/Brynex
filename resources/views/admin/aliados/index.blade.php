@extends('layouts.app')
@section('modulo','Aliados')

@section('contenido')
<div style="background:#fff;border-radius:14px;padding:1.5rem;box-shadow:0 1px 8px rgba(0,0,0,0.06);">

    @include('admin.partials.table-header', [
        'titulo'    => '🏢 Empresas Aliadas',
        'subtitulo' => $total . ' aliados registrados',
        'btnTexto'  => 'Nuevo Aliado',
        'btnRuta'   => route('admin.aliados.create'),
    ])

    {{-- Filtro de estado: por defecto solo los activos --}}
    <div style="display:flex;gap:0.4rem;flex-wrap:wrap;margin-bottom:1rem;">
        @foreach (['activos' => 'Activos', 'pausados' => 'Pausados', 'inactivos' => 'Inactivos', 'todos' => 'Todos'] as $clave => $etiqueta)
            @php $sel = $estado === $clave; @endphp
            <a href="{{ route('admin.aliados.index', ['estado' => $clave]) }}"
                style="padding:0.35rem 0.85rem;border-radius:999px;font-size:0.78rem;font-weight:600;text-decoration:none;
                       border:1px solid {{ $sel ? '#2563eb' : '#e2e8f0' }};background:{{ $sel ? '#2563eb' : '#fff' }};color:{{ $sel ? '#fff' : '#475569' }};">
                {{ $etiqueta }}
                <span style="opacity:0.75;">{{ $clave === 'todos' ? $total : ($conteos[$clave] ?? 0) }}</span>
            </a>
        @endforeach
    </div>

<div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:0.85rem;">
            <thead>
                <tr style="background:#f1f5f9;border-radius:8px;">
                    <th style="padding:0.7rem 1rem;text-align:left;color:#475569;font-weight:600;white-space:nowrap;">Logo</th>
                    <th style="padding:0.7rem 1rem;text-align:left;color:#475569;font-weight:600;">Nombre / Razón Social</th>
                    <th style="padding:0.7rem 1rem;text-align:left;color:#475569;font-weight:600;">NIT</th>
                    <th style="padding:0.7rem 1rem;text-align:left;color:#475569;font-weight:600;">Contacto</th>
                    <th style="padding:0.7rem 1rem;text-align:left;color:#475569;font-weight:600;">WhatsApp</th>
                    <th style="padding:0.7rem 1rem;text-align:left;color:#475569;font-weight:600;">Usuarios</th>
                    <th style="padding:0.7rem 1rem;text-align:center;color:#475569;font-weight:600;">Estado</th>
                    <th style="padding:0.7rem 1rem;text-align:center;color:#475569;font-weight:600;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($aliados as $aliado)
                <tr style="border-bottom:1px solid #f1f5f9;{{ $aliado->trashed() ? 'opacity:0.5;' : '' }}">
                    <td style="padding:0.65rem 1rem;">
                        @if($aliado->logo)
                            <img src="{{ asset('storage/'.$aliado->logo) }}" alt="{{ $aliado->nombre }}"
                                style="height:36px;width:36px;border-radius:8px;object-fit:cover;border:1px solid #e2e8f0;">
                        @else
                            <div style="height:36px;width:36px;border-radius:8px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;font-size:1.1rem;">🏢</div>
                        @endif
                    </td>
                    <td style="padding:0.65rem 1rem;">
                        <div style="font-weight:600;color:#0f172a;">{{ $aliado->nombre }}</div>
                        <div style="font-size:0.75rem;color:#94a3b8;">{{ $aliado->razon_social }}</div>
                    </td>
                    <td style="padding:0.65rem 1rem;color:#475569;">{{ $aliado->nit ?? '—' }}</td>
                    <td style="padding:0.65rem 1rem;">
                        <div style="color:#334155;">{{ $aliado->contacto ?? '—' }}</div>
                        <div style="font-size:0.75rem;color:#94a3b8;">{{ $aliado->correo }}</div>
                    </td>
                    <td style="padding:0.65rem 1rem;color:#334155;">
                        @if($aliado->whatsapp)
                            <span style="font-weight:600;color:#16a34a;">🟢 {{ $aliado->whatsapp }}</span>
                        @else
                            <span style="color:#cbd5e1;">—</span>
                        @endif
                    </td>
                    <td style="padding:0.65rem 1rem;text-align:center;">
                        <span style="background:#dbeafe;color:#1d4ed8;padding:0.15rem 0.6rem;border-radius:999px;font-size:0.75rem;font-weight:600;">
                            {{ $aliado->usuarios_count }}
                        </span>
                    </td>
                    <td style="padding:0.65rem 1rem;text-align:center;">
                        @if($aliado->trashed())
                            <span style="background:#fee2e2;color:#dc2626;padding:0.2rem 0.6rem;border-radius:999px;font-size:0.72rem;font-weight:600;">Inactivo</span>
                        @elseif($aliado->activo)
                            <span style="background:#dcfce7;color:#16a34a;padding:0.2rem 0.6rem;border-radius:999px;font-size:0.72rem;font-weight:600;">Activo</span>
                        @else
                            <span style="background:#fef9c3;color:#ca8a04;padding:0.2rem 0.6rem;border-radius:999px;font-size:0.72rem;font-weight:600;">Pausado</span>
                        @endif
                    </td>
                    <td style="padding:0.65rem 1rem;text-align:center;white-space:nowrap;">
                        @if($aliado->trashed())
                            <form method="POST" action="{{ route('admin.aliados.restore', $aliado->id) }}" style="display:inline;">
                                @csrf @method('PATCH')
                                <button type="submit" title="Restaurar"
                                    style="background:#dcfce7;border:none;color:#16a34a;padding:0.35rem 0.75rem;border-radius:6px;font-size:0.76rem;font-weight:600;cursor:pointer;">
                                    ↩ Restaurar
                                </button>
                            </form>
                        @else
                            <a href="{{ route('admin.aliados.edit', $aliado) }}" title="Editar"
                                style="background:#dbeafe;border:none;color:#2563eb;padding:0.35rem 0.75rem;border-radius:6px;font-size:0.76rem;font-weight:600;text-decoration:none;display:inline-block;margin-right:0.25rem;">
                                ✏️ Editar
                            </a>
                            <form method="POST" action="{{ route('admin.aliados.destroy', $aliado) }}" style="display:inline;"
                                onsubmit="return confirm('¿Desactivar aliado {{ $aliado->nombre }}?')">
                                @csrf @method('DELETE')
                                <button type="submit" title="Desactivar"
                                    style="background:#fee2e2;border:none;color:#dc2626;padding:0.35rem 0.75rem;border-radius:6px;font-size:0.76rem;font-weight:600;cursor:pointer;">
                                    🗑 Desactivar
                                </button>
                            </form>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" style="text-align:center;padding:2rem;color:#94a3b8;">
                        @if ($total > 0)
                            No hay aliados en este estado.
                        @else
                        No hay aliados registrados. <a href="{{ route('admin.aliados.create') }}" style="color:#2563eb;">Crear el primero →</a>
                        @endif
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
