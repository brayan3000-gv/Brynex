{{--
    Trámites abiertos que la empresa mandó desde su portal. La tarjeta ya es
    un enlace, así que el chip navega con JS en vez de ser otro <a> adentro.
    Con alguno sin mirar, late para que se note.
--}}
@if((int) $emp->portal_abiertas > 0)
    <span class="portal-chip {{ (int) $emp->portal_nuevas > 0 ? 'nuevo' : '' }}" role="link" tabindex="0"
          title="{{ $emp->portal_abiertas }} trámite(s) del portal abiertos{{ (int) $emp->portal_nuevas > 0 ? ', '.$emp->portal_nuevas.' sin ver' : '' }}"
          onclick="event.preventDefault();event.stopPropagation();location.href='{{ route('admin.tareas.index', ['empresa_id' => $emp->id]) }}'">
        🌐 {{ $emp->portal_abiertas }}
    </span>
    @once
    <style>
        .portal-chip { margin-left: auto; flex-shrink: 0; display: inline-flex; align-items: center; gap: .2rem; font-size: .72rem; font-weight: 800;
            color: #1d4ed8; background: #dbeafe; border: 1px solid #93c5fd; border-radius: 999px; padding: .12rem .55rem; cursor: pointer;
            transition: transform .15s, background .15s; }
        .portal-chip:hover { transform: scale(1.08); background: #bfdbfe; }
        .portal-chip.nuevo { color: #fff; background: #2563eb; border-color: #2563eb; animation: portal-late 1.6s ease-in-out infinite; }
        @keyframes portal-late { 0%, 100% { box-shadow: 0 0 0 0 rgba(37,99,235,.45); } 50% { box-shadow: 0 0 0 7px rgba(37,99,235,0); } }
        @media (prefers-reduced-motion: reduce) { .portal-chip.nuevo { animation: none; } }
    </style>
    @endonce
@endif
