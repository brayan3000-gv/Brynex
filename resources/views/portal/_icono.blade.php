{{-- Íconos de trazo del portal. Uso: @include('portal._icono', ['n' => 'inicio']) --}}
@php
    $trazos = [
        'inicio' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/>',
        'facturas' => '<path d="M6 2h9l5 5v15H6z"/><path d="M14 2v6h6M9 13h6M9 17h6"/>',
        'incapacidades' => '<path d="M12 21s-7-4.5-9-9.5C1.5 7.5 4 4 7.5 4c2 0 3.5 1 4.5 2.5C13 5 14.5 4 16.5 4 20 4 22.5 7.5 21 11.5 19 16.5 12 21 12 21z"/>',
        'retirados' => '<circle cx="9" cy="8" r="4"/><path d="M1 21v-1a7 7 0 0 1 12-5M16 16l5 5M21 16l-5 5"/>',
        'usuarios' => '<circle cx="9" cy="8" r="4"/><path d="M1 21v-1a8 8 0 0 1 16 0v1M17 4a4 4 0 0 1 0 8M23 21v-1a7 7 0 0 0-4-6.3"/>',
        'tramites' => '<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 3h6v3H9zM9 12l2 2 4-4M9 17h6"/>',
        'mas' => '<path d="M12 5v14M5 12h14"/>',
        'subir' => '<path d="M12 21V9M7 14l5-5 5 5M4 3h16"/>',
        'descarga' => '<path d="M12 3v12M7 10l5 5 5-5M4 21h16"/>',
        'buscar' => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-5-5"/>',
        'izq' => '<path d="M15 5l-7 7 7 7"/>',
        'der' => '<path d="M9 5l7 7-7 7"/>',
        'cerrar' => '<path d="M6 6l12 12M18 6L6 18"/>',
        'check' => '<path d="M4 12l5 5L20 6"/>',
        'reloj' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/>',
        'dinero' => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M6 12h.01M18 12h.01"/>',
        'escudo' => '<path d="M12 2l8 3v6c0 5-3.5 9-8 11-4.5-2-8-6-8-11V5z"/><path d="M8.5 12l2.5 2.5 4.5-5"/>',
        'llave' => '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M17 6l3 3M15 8l2 2"/>',
        'salir' => '<path d="M15 4h4a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-4M10 17l-5-5 5-5M5 12h11"/>',
        'whatsapp' => '<path d="M3 21l1.6-4.7A8.5 8.5 0 1 1 8 19.6z"/><path d="M9 9.5c0 3 2.5 5.5 5.5 5.5l1-1.5-2-1-1 .8a4 4 0 0 1-1.8-1.8l.8-1-1-2L9 9.5z"/>',
        'ojo' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.5v.01"/>',
    ];
@endphp
<svg class="{{ $clase ?? 'ic' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="{{ $grosor ?? 1.8 }}" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $trazos[$n] ?? '' !!}</svg>
