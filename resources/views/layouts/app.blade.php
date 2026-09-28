<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('titulo', 'BryNex') — @yield('modulo', 'Dashboard')</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Alpine.js: requerido para el cotizador reactivo --}}
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --azul-oscuro: #0a1628;
            --azul-medio:  #0d2550;
            --azul-vivo:   #1e40af;
            --azul-btn:    #2563eb;
            --acento:      #3b82f6;
            --texto:       #e2e8f0;
            --fondo:       #f0f4f8;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--fondo);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ── Barra superior aliado (solo BryNex) ───────────────────────── */
        .bar-brynex {
            background: linear-gradient(90deg, #0a1628, #0d2550);
            padding: 0.35rem 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .bar-brynex span {
            color: rgba(255,255,255,0.6);
            font-size: 0.72rem;
        }

        .bar-brynex .aliado-actual {
            color: #93c5fd;
            font-weight: 600;
            font-size: 0.75rem;
        }

        .btn-cambiar {
            background: rgba(59,130,246,0.2);
            border: 1px solid rgba(59,130,246,0.4);
            color: #93c5fd;
            font-size: 0.68rem;
            font-weight: 600;
            padding: 0.2rem 0.65rem;
            border-radius: 999px;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.15s;
        }

        .btn-cambiar:hover { background: rgba(59,130,246,0.35); }

        /* ── Header principal ───────────────────────────────────────────── */
        .header {
            background: linear-gradient(135deg, var(--azul-oscuro) 0%, var(--azul-medio) 60%, var(--azul-vivo) 100%);
            padding: 0.65rem 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 2px 12px rgba(0,0,0,0.35);
        }

        .header-logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .header-logo img.logo-aliado {
            height: 44px;
            object-fit: contain;
            border-radius: 8px;
            background: rgba(255,255,255,0.08);
            padding: 2px;
        }

        .header-aliado-info h2 {
            color: #fff;
            font-size: 0.95rem;
            font-weight: 600;
            line-height: 1.2;
        }

        .header-aliado-info small {
            color: rgba(255,255,255,0.5);
            font-size: 0.72rem;
        }

        .header-user {
            display: flex;
            align-items: center;
            gap: 0.85rem;
        }

        /* ── Dropdown de usuario ────────────────────────────────────────── */
        .user-dropdown {
            position: relative;
            cursor: pointer;
            user-select: none;
        }

        .user-dropdown-trigger {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.35rem 0.65rem;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.1);
            background: rgba(255,255,255,0.05);
            transition: background 0.15s, border-color 0.15s;
        }

        .user-dropdown-trigger:hover,
        .user-dropdown.open .user-dropdown-trigger {
            background: rgba(59,130,246,0.15);
            border-color: rgba(59,130,246,0.35);
        }

        .user-info {
            text-align: right;
        }

        .user-info .nombre {
            color: #fff;
            font-size: 0.82rem;
            font-weight: 500;
        }

        .user-info .rol {
            color: rgba(255,255,255,0.45);
            font-size: 0.68rem;
            text-transform: capitalize;
        }

        .user-arrow {
            font-size: 0.55rem;
            color: rgba(255,255,255,0.4);
            transition: transform 0.2s;
        }

        .user-dropdown.open .user-arrow {
            transform: rotate(180deg);
            color: #93c5fd;
        }

        /* Panel desplegable de usuario */
        .user-dropdown-panel {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            min-width: 200px;
            background: linear-gradient(135deg, #0d2550 0%, #0a1628 100%);
            border: 1px solid rgba(59,130,246,0.25);
            border-radius: 10px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.45), 0 2px 8px rgba(0,0,0,0.3);
            padding: 0.4rem;
            z-index: 9999;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-6px);
            transition: opacity 0.18s ease, transform 0.18s ease, visibility 0.18s;
            pointer-events: none;
        }

        .user-dropdown.open .user-dropdown-panel {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
            pointer-events: all;
        }

        /* Flecha decorativa del panel de usuario */
        .user-dropdown-panel::before {
            content: '';
            position: absolute;
            top: -6px;
            right: 18px;
            width: 0;
            height: 0;
            border-left: 6px solid transparent;
            border-right: 6px solid transparent;
            border-bottom: 6px solid rgba(59,130,246,0.25);
        }

        .user-dropdown-panel::after {
            content: '';
            position: absolute;
            top: -5px;
            right: 19px;
            width: 0;
            height: 0;
            border-left: 5px solid transparent;
            border-right: 5px solid transparent;
            border-bottom: 5px solid #0d2550;
        }

        /* Ítem del panel de usuario */
        .user-panel-item {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.45rem 0.65rem;
            border-radius: 7px;
            text-decoration: none;
            color: rgba(255,255,255,0.75);
            font-size: 0.78rem;
            font-weight: 500;
            transition: background 0.12s, color 0.12s;
            white-space: nowrap;
            width: 100%;
            background: none;
            border: none;
            cursor: pointer;
            text-align: left;
        }

        .user-panel-item:hover {
            background: rgba(59,130,246,0.2);
            color: #93c5fd;
        }

        .user-panel-item.danger {
            color: #fca5a5;
        }

        .user-panel-item.danger:hover {
            background: rgba(239,68,68,0.2);
            color: #fca5a5;
        }

        .user-panel-item .upi {
            width: 22px;
            height: 22px;
            border-radius: 5px;
            background: rgba(255,255,255,0.06);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            flex-shrink: 0;
        }

        .user-panel-sep {
            height: 1px;
            background: rgba(255,255,255,0.07);
            margin: 0.3rem 0;
        }

        .user-panel-header {
            padding: 0.3rem 0.65rem 0.25rem;
            font-size: 0.7rem;
            color: rgba(255,255,255,0.4);
            display: flex;
            align-items: center;
            gap: 0.4rem;
        }

        .user-panel-header strong {
            color: #93c5fd;
            font-size: 0.78rem;
        }

        /* Mantener btn-salir para compatibilidad si existe en otra parte */
        .btn-salir {
            display: none;
        }

        /* ── Menú de iconos (integrado en header) ────────────────────────── */
        .menu-iconos {
            background: transparent;
            padding: 0;
            display: flex;
            align-items: center;
            gap: 0.1rem;
            flex: 1;
            justify-content: center;
            overflow-x: auto;
            scrollbar-width: none;
        }

        .menu-iconos::-webkit-scrollbar { display: none; }

        .menu-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.18rem;
            padding: 0.3rem 0.7rem;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.15s, transform 0.15s;
            min-width: 58px;
            border: 1px solid transparent;
        }

        .menu-item:hover {
            background: rgba(59,130,246,0.15);
            border-color: rgba(59,130,246,0.25);
            transform: translateY(-1px);
        }

        .menu-item.activo {
            background: rgba(59,130,246,0.2);
            border-color: rgba(59,130,246,0.4);
        }

        .menu-item .icono {
            width: 28px;
            height: 28px;
            border-radius: 7px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            background: rgba(255,255,255,0.06);
        }

        .menu-item .label {
            color: rgba(255,255,255,0.75);
            font-size: 0.62rem;
            font-weight: 500;
            text-align: center;
            letter-spacing: 0.02em;
            white-space: nowrap;
        }

        .menu-item:hover .label,
        .menu-item.activo .label {
            color: #93c5fd;
        }

        .menu-sep {
            width: 1px;
            height: 36px;
            background: rgba(255,255,255,0.08);
            margin: 0 0.25rem;
            flex-shrink: 0;
        }

        /* ── Dropdown de menú ───────────────────────────────────────────── */
        .menu-dropdown {
            position: relative;
        }

        .menu-dropdown-trigger {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.18rem;
            padding: 0.3rem 0.7rem;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.15s, transform 0.15s;
            min-width: 60px;
            border: 1px solid transparent;
            background: none;
            outline: none;
            user-select: none;
        }

        .menu-dropdown-trigger:hover,
        .menu-dropdown:hover .menu-dropdown-trigger {
            background: rgba(59,130,246,0.15);
            border-color: rgba(59,130,246,0.25);
            transform: translateY(-1px);
        }

        .menu-dropdown-trigger.activo,
        .menu-dropdown:hover .menu-dropdown-trigger.activo {
            background: rgba(59,130,246,0.2);
            border-color: rgba(59,130,246,0.4);
        }

        .menu-dropdown-trigger .icono {
            width: 28px;
            height: 28px;
            border-radius: 7px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            background: rgba(255,255,255,0.06);
        }

        .menu-dropdown-trigger .label {
            color: rgba(255,255,255,0.75);
            font-size: 0.62rem;
            font-weight: 500;
            text-align: center;
            letter-spacing: 0.02em;
            white-space: nowrap;
        }

        .menu-dropdown:hover .menu-dropdown-trigger .label {
            color: #93c5fd;
        }

        /* Icono de flecha pequeña */
        .menu-dropdown-trigger .arrow {
            font-size: 0.48rem;
            color: rgba(255,255,255,0.4);
            margin-top: -2px;
        }

        .menu-dropdown:hover .menu-dropdown-trigger .arrow {
            color: #93c5fd;
        }

        /* Panel desplegable */
        .menu-dropdown-panel {
            position: absolute;
            top: calc(100% + 8px);
            left: 50%;
            transform: translateX(-50%);
            min-width: 180px;
            background: linear-gradient(135deg, #0d2550 0%, #0a1628 100%);
            border: 1px solid rgba(59,130,246,0.25);
            border-radius: 10px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.45), 0 2px 8px rgba(0,0,0,0.3);
            padding: 0.4rem;
            z-index: 999;
            opacity: 0;
            visibility: hidden;
            transform: translateX(-50%) translateY(-6px);
            transition: opacity 0.18s ease, transform 0.18s ease, visibility 0.18s;
            pointer-events: none;
        }

        .menu-dropdown:hover .menu-dropdown-panel {
            opacity: 1;
            visibility: visible;
            transform: translateX(-50%) translateY(0);
            pointer-events: all;
        }

        /* Flecha decorativa del panel */
        .menu-dropdown-panel::before {
            content: '';
            position: absolute;
            top: -6px;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 0;
            border-left: 6px solid transparent;
            border-right: 6px solid transparent;
            border-bottom: 6px solid rgba(59,130,246,0.25);
        }

        .menu-dropdown-panel::after {
            content: '';
            position: absolute;
            top: -5px;
            left: 50%;
            transform: translateX(-50%);
            width: 0;
            height: 0;
            border-left: 5px solid transparent;
            border-right: 5px solid transparent;
            border-bottom: 5px solid #0d2550;
        }

        /* Cabecera del panel (título del grupo) */
        .panel-header {
            padding: 0.3rem 0.5rem 0.2rem;
            font-size: 0.58rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: rgba(255,255,255,0.35);
            border-bottom: 1px solid rgba(255,255,255,0.06);
            margin-bottom: 0.2rem;
        }

        /* Separador dentro del panel */
        .panel-sep {
            height: 1px;
            background: rgba(255,255,255,0.07);
            margin: 0.3rem 0;
        }

        /* Ítem del panel */
        .panel-item {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.45rem 0.65rem;
            border-radius: 7px;
            text-decoration: none;
            color: rgba(255,255,255,0.75);
            font-size: 0.78rem;
            font-weight: 500;
            transition: background 0.12s, color 0.12s;
            white-space: nowrap;
        }

        .panel-item:hover {
            background: rgba(59,130,246,0.2);
            color: #93c5fd;
        }

        .panel-item.activo {
            background: rgba(59,130,246,0.25);
            color: #93c5fd;
        }

        .panel-item .pi {
            width: 22px;
            height: 22px;
            border-radius: 5px;
            background: rgba(255,255,255,0.06);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            flex-shrink: 0;
        }

        /* Colores especiales para el dropdown BryNex */
        .menu-dropdown.brynex .menu-dropdown-trigger .icono {
            background: rgba(29,78,216,0.3);
        }

        .menu-dropdown.brynex .menu-dropdown-panel {
            background: linear-gradient(135deg, #1e3a8a 0%, #0a1628 100%);
            border-color: rgba(99,179,237,0.3);
        }

        .menu-dropdown.brynex .panel-item:hover {
            background: rgba(99,179,237,0.2);
            color: #bfdbfe;
        }

        .menu-dropdown.brynex .menu-dropdown-panel::before {
            border-bottom-color: rgba(99,179,237,0.3);
        }

        .menu-dropdown.brynex .menu-dropdown-panel::after {
            border-bottom-color: #1e3a8a;
        }

        /* ── Contenido ──────────────────────────────────────────────────── */
        .contenido {
            flex: 1;
            padding: 1.5rem;
        }

        /* ── Flash messages ─────────────────────────────────────────────── */
        .flash {
            padding: 0.65rem 1rem;
            border-radius: 8px;
            font-size: 0.83rem;
            margin-bottom: 1rem;
        }

        .flash.success {
            background: rgba(16,185,129,0.1);
            border: 1px solid rgba(16,185,129,0.3);
            color: #065f46;
        }

        .flash.warning {
            background: rgba(245,158,11,0.12);
            border: 1px solid rgba(245,158,11,0.4);
            color: #92400e;
            font-weight: 600;
        }

        /* ── Botón hamburguesa (solo visible en móvil) ────────────────────── */
        .btn-hamburguesa {
            display: none;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.15);
            color: #fff;
            padding: 0.4rem 0.6rem;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1.15rem;
            line-height: 1;
            transition: background 0.15s, border-color 0.15s;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .btn-hamburguesa:hover { background: rgba(59,130,246,0.25); border-color: rgba(59,130,246,0.4); }

        /* ── Overlay oscuro del drawer ──────────────────────────────────────── */
        .mobile-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.55);
            z-index: 1000;
            backdrop-filter: blur(2px);
            -webkit-backdrop-filter: blur(2px);
        }
        .mobile-overlay.open { display: block; }

        /* ── Drawer lateral ─────────────────────────────────────────────────── */
        .mobile-drawer {
            position: fixed;
            top: 0;
            left: -100%;
            width: min(290px, 88vw);
            height: 100%;
            background: linear-gradient(160deg, #0a1628 0%, #0d2550 100%);
            z-index: 1001;
            display: flex;
            flex-direction: column;
            box-shadow: 4px 0 32px rgba(0,0,0,0.6);
            transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            overflow-y: auto;
            overflow-x: hidden;
            scrollbar-width: thin;
            scrollbar-color: rgba(59,130,246,0.3) transparent;
        }
        .mobile-drawer.open { left: 0; }

        /* Cabecera del drawer */
        .drawer-header {
            padding: 1rem 1.1rem;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: rgba(0,0,0,0.2);
            flex-shrink: 0;
        }
        .drawer-header-left {
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }
        .drawer-title {
            color: #fff;
            font-size: 0.88rem;
            font-weight: 700;
        }
        .drawer-empresa {
            color: rgba(255,255,255,0.45);
            font-size: 0.7rem;
        }
        .drawer-close {
            background: rgba(255,255,255,0.06);
            border: 1px solid rgba(255,255,255,0.1);
            color: rgba(255,255,255,0.5);
            font-size: 1rem;
            cursor: pointer;
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            transition: color 0.15s, background 0.15s;
            line-height: 1;
        }
        .drawer-close:hover { color: #fff; background: rgba(255,255,255,0.12); }

        /* Secciones del drawer */
        .drawer-section {
            padding: 0.4rem 0.65rem;
        }
        .drawer-section-label {
            font-size: 0.6rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: rgba(255,255,255,0.3);
            padding: 0.6rem 0.5rem 0.2rem;
        }
        .drawer-sep {
            height: 1px;
            background: rgba(255,255,255,0.07);
            margin: 0.2rem 0.65rem;
        }

        /* Ítems de navegación del drawer */
        .drawer-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.6rem 0.75rem;
            border-radius: 8px;
            text-decoration: none;
            color: rgba(255,255,255,0.72);
            font-size: 0.84rem;
            font-weight: 500;
            transition: background 0.12s, color 0.12s;
            margin-bottom: 0.05rem;
            width: 100%;
            background: none;
            border: none;
            cursor: pointer;
            text-align: left;
            box-sizing: border-box;
            border-left: 3px solid transparent;
        }
        .drawer-item:hover {
            background: rgba(59,130,246,0.15);
            color: #93c5fd;
        }
        .drawer-item.activo {
            background: rgba(59,130,246,0.18);
            color: #93c5fd;
            border-left-color: #3b82f6;
        }
        .drawer-item.danger { color: rgba(252,165,165,0.8); }
        .drawer-item.danger:hover { background: rgba(239,68,68,0.12); color: #fca5a5; }
        .di-icon {
            font-size: 1rem;
            width: 26px;
            text-align: center;
            flex-shrink: 0;
        }

        /* Footer del drawer */
        .drawer-footer {
            margin-top: auto;
            padding: 0.65rem;
            border-top: 1px solid rgba(255,255,255,0.08);
            flex-shrink: 0;
        }
        .drawer-user-card {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            padding: 0.55rem 0.75rem;
            margin-bottom: 0.4rem;
            background: rgba(255,255,255,0.04);
            border-radius: 8px;
            border: 1px solid rgba(255,255,255,0.07);
        }
        .drawer-user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: linear-gradient(135deg, #2563eb, #1e40af);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 0.85rem;
            font-weight: 700;
            flex-shrink: 0;
        }
        .du-nombre { color: #fff; font-size: 0.82rem; font-weight: 600; }
        .du-rol { color: rgba(255,255,255,0.4); font-size: 0.68rem; text-transform: capitalize; }

        /* ── Responsive ─────────────────────────────────────────────────── */
        @media (max-width: 768px) {
            .user-info { display: none; }
            .menu-iconos { display: none !important; }
            .btn-hamburguesa { display: flex; }
            .header { padding: 0.55rem 0.9rem; gap: 0.5rem; }
            .header-aliado-info h2 { font-size: 0.82rem; }
            .header-aliado-info small { display: none; }
            .header-logo { gap: 0.5rem; }
            .header-logo img.logo-aliado { height: 36px; }
            .contenido { padding: 0.9rem 0.7rem; }
        }
        @media (max-width: 480px) {
            .header { padding: 0.5rem 0.65rem; }
            .header-logo img.logo-aliado { height: 32px; }
            .contenido { padding: 0.75rem 0.5rem; }
        }
    </style>
    @stack('styles')
</head>
<body>

    {{-- Barra BryNex eliminada: opciones movidas al dropdown del usuario --}}

    {{-- Header con logo del aliado activo (oculto en modo iframe) --}}
    @unless(request()->has('iframe'))
    <header class="header">
        <a href="{{ route('dashboard') }}" class="header-logo" style="text-decoration:none;cursor:pointer;">
            @if (!empty($alidoActivo?->logo))
                <img class="logo-aliado" src="{{ asset('storage/' . $alidoActivo->logo) }}" alt="{{ $alidoActivo->nombre }}">
            @else
                <img class="logo-aliado" src="{{ asset('img/logo-brynex.png') }}" alt="BryNex">
            @endif
            <div class="header-aliado-info">
                <h2>{{ $alidoActivo->nombre ?? 'BryNex' }}</h2>
                <small>{{ $alidoActivo->razon_social ?? 'Asesores en Seguridad Social' }}</small>
            </div>
        </a>

        {{-- Menú de iconos integrado en el header --}}
        <nav class="menu-iconos">
            <a href="{{ route('dashboard') }}" class="menu-item {{ request()->routeIs('dashboard') ? 'activo' : '' }}">
                <div class="icono">🏠</div>
                <div class="label">Inicio</div>
            </a>

            @can('clientes.ver')
            <a href="{{ route('admin.clientes.index') }}" class="menu-item {{ request()->routeIs('admin.clientes*') ? 'activo' : '' }}">
                <div class="icono">👥</div>
                <div class="label">Clientes</div>
            </a>
            @endcan

            @can('facturacion.ver')
            <a href="{{ route('admin.facturacion.index') }}" class="menu-item {{ request()->routeIs('admin.facturacion*') ? 'activo' : '' }}">
                <div class="icono">🏢</div>
                <div class="label">Empresas</div>
            </a>
            @endcan

            @can('afiliaciones.ver')
            <a href="{{ route('admin.afiliaciones.index') }}" class="menu-item {{ request()->routeIs('admin.afiliaciones*') ? 'activo' : '' }}">
                <div class="icono">🤝</div>
                <div class="label">Afiliaciones</div>
            </a>
            @endcan



            @can('planos.ver')
            <a href="{{ route('admin.planos.index') }}" class="menu-item {{ request()->routeIs('admin.planos*') ? 'activo' : '' }}">
                <div class="icono">📄</div>
                <div class="label">Planos SS</div>
            </a>
            @endcan

            @can('cobros.ver')
            <a href="{{ route('admin.cobros.index') }}" class="menu-item {{ request()->routeIs('admin.cobros*') ? 'activo' : '' }}">
                <div class="icono">💰</div>
                <div class="label">Cobros</div>
            </a>
            @endcan

            @can('prestamos.ver')
            <a href="{{ route('admin.prestamos.index') }}" class="menu-item {{ request()->routeIs('admin.prestamos*') ? 'activo' : '' }}">
                <div class="icono">📋</div>
                <div class="label">Préstamos</div>
            </a>
            @endcan

            @can('tareas.ver')
            <a href="{{ route('admin.tareas.index') }}" class="menu-item {{ request()->routeIs('admin.tareas*') ? 'activo' : '' }}" style="position:relative">
                <div class="icono" style="position:relative">
                    📌
                    {{-- Solicitudes nuevas del portal de empresas (ver portalBadge abajo) --}}
                    <span id="portal-badge" title="Solicitudes nuevas de empresas" style="display:none;position:absolute;top:-5px;right:-5px;background:#2563eb;color:#fff;font-size:.55rem;font-weight:700;padding:.08rem .35rem;border-radius:999px;min-width:16px;text-align:center"></span>
                </div>
                <div class="label">Tareas</div>
            </a>
            @endcan

            @can('incapacidades.ver')
            <a href="{{ route('admin.incapacidades.index') }}" class="menu-item {{ request()->routeIs('admin.incapacidades*') ? 'activo' : '' }}">
                <div class="icono">🏥</div>
                <div class="label">Incapacidades</div>
            </a>
            @endcan

            @can('whatsapp.ver')
            <a href="{{ route('admin.whatsapp.chat.index') }}"
               class="menu-item {{ request()->routeIs('admin.whatsapp*') ? 'activo' : '' }}"
               style="position:relative" title="WhatsApp Chat">
                <div class="icono" style="position:relative">
                    💬
                    <span id="wa-badge" style="display:none;position:absolute;top:-5px;right:-5px;background:#ef4444;color:#fff;font-size:.55rem;font-weight:700;padding:.08rem .35rem;border-radius:999px;min-width:16px;text-align:center"></span>
                </div>
                <div class="label">WhatsApp</div>
            </a>
            @endcan

            @can('cuadre_diario.ver')
            <div class="menu-sep"></div>

            <a href="{{ route('admin.cuadre-diario.index') }}"
               class="menu-item {{ request()->routeIs('admin.cuadre-diario*') ? 'activo' : '' }}">
                <div class="icono">🧾</div>
                <div class="label">Cuadre Caja</div>
            </a>
            @endcan

            @can('informes.ver')
            <div class="menu-sep"></div>
            <a href="{{ route('admin.informes.hub') }}"
               class="menu-item {{ request()->routeIs('admin.informes*') && !request()->routeIs('admin.informes.comisiones*') ? 'activo' : '' }}">
                <div class="icono">📊</div>
                <div class="label">Informes</div>
            </a>
            @endcan



            {{-- ───────────────────────────────────────────────────────────── --}}

            {{-- DROPDOWN ADMIN: visible para admin y superadmin              --}}
            {{-- ───────────────────────────────────────────────────────────── --}}
            @canany(['asesores.ver', 'usuarios.ver', 'entrar-configuracion', 'bitacora.ver', 'traslados_rs.ejecutar', 'razones_sociales.gestionar', 'razones_sociales.documentos'])
            <div class="menu-sep"></div>
            <div class="menu-dropdown">
                {{-- Configuración pide `configuracion.ver`: a quien no la tiene
                     (un trabajador que sube documentos de razón social) el
                     botón lo mandaba a un 403. Se lleva a lo primero que sí ve. --}}
                @php
                    $adminInicio = match (true) {
                        auth()->user()->can('entrar-configuracion') => route('admin.configuracion.hub'),
                        auth()->user()->can('razones_sociales.documentos') => route('admin.configuracion.razones.index'),
                        auth()->user()->can('asesores.ver') => route('admin.asesores.index'),
                        default => '#',
                    };
                @endphp
                <a href="{{ $adminInicio }}" class="menu-dropdown-trigger {{ request()->routeIs('admin.asesores*', 'admin.bitacora*', 'admin.usuarios*', 'admin.configuracion*') ? 'activo' : '' }}">
                    <div class="icono">⚙️</div>
                    <div class="label">Admin</div>
                </a>
                <div class="menu-dropdown-panel">
                    <div class="panel-header">Administración</div>

                    @can('asesores.ver')
                    <a href="{{ route('admin.asesores.index') }}" class="panel-item {{ request()->routeIs('admin.asesores*') ? 'activo' : '' }}">
                        <div class="pi">🤝</div> Asesores
                    </a>
                    @endcan
                    @can('bitacora.ver')
                    <a href="{{ route('admin.bitacora.index') }}" class="panel-item {{ request()->routeIs('admin.bitacora*') ? 'activo' : '' }}">
                        <div class="pi">👁️</div> Auditoría
                    </a>
                    @endcan
                    {{-- Tiene tarjeta en Configuración, pero quien solo sube
                         documentos no entra allá: necesita su propio acceso. --}}
                    @canany(['razones_sociales.gestionar', 'razones_sociales.documentos'])
                    <a href="{{ route('admin.configuracion.razones.index') }}" class="panel-item {{ request()->routeIs('admin.configuracion.razones*') ? 'activo' : '' }}">
                        <div class="pi">🏭</div> Razones sociales
                    </a>
                    @endcanany

                    @canany(['usuarios.ver', 'entrar-configuracion'])
                    <div class="panel-sep"></div>
                    @endcanany

                    @can('usuarios.ver')
                    <a href="{{ route('admin.usuarios.index') }}" class="panel-item {{ request()->routeIs('admin.usuarios*') ? 'activo' : '' }}">
                        <div class="pi">👥</div> Usuarios
                    </a>
                    @endcan
                    {{-- Configuración se abre con cualquiera de sus permisos (Gate
                         `entrar-configuracion`); Parámetros sigue siendo de admin. --}}
                    @can('entrar-configuracion')
                    <a href="{{ route('admin.configuracion.hub') }}" class="panel-item {{ request()->routeIs('admin.configuracion*') ? 'activo' : '' }}">
                        <div class="pi">⚙️</div> Configuración
                    </a>
                    @endcan
                    @can('configuracion.ver')
                    <a href="{{ route('admin.configuracion.index') }}" class="panel-item {{ request()->routeIs('admin.configuracion.index') ? 'activo' : '' }}">
                        <div class="pi">💲</div> Parámetros / Precios
                    </a>
                    @endcan
                    @can('traslados_rs.ejecutar')
                    <div class="panel-sep"></div>
                    <a href="{{ route('admin.traslados.index') }}" class="panel-item {{ request()->routeIs('admin.traslados*') ? 'activo' : '' }}">
                        <div class="pi">🔄</div> Traslado de RS
                    </a>
                    @endcan
                </div>
            </div>
            @endcanany

            {{-- Razones sociales de BryNex. Va suelto y no dentro del dropdown
                 de abajo porque ese dropdown pide `superadmin`, y quien opera
                 este módulo es el contable de la casa. Para el superadmin no se
                 repite: ya lo tiene dentro del panel BryNex. --}}
            @can('brynex_razones.ver')
                @unless(Auth::user()->hasRole('superadmin') && Auth::user()->es_brynex)
                <a href="{{ route('brynex.razones.index') }}" class="menu-item {{ request()->routeIs('brynex.razones*') ? 'activo' : '' }}">
                    <div class="icono">🏛️</div>
                    <div class="label">Razones Sociales</div>
                </a>
                @endunless
            @endcan

            {{-- Consulta DIAN. Va suelto por lo mismo que razones sociales:
                 quien la usa está llenando la ficha de un cliente, no
                 administrando la plataforma. --}}
            @can('brynex_dian.ver')
                @unless(Auth::user()->hasRole('superadmin') && Auth::user()->es_brynex)
                <a href="{{ route('brynex.dian.index') }}" class="menu-item {{ request()->routeIs('brynex.dian*') ? 'activo' : '' }}">
                    <div class="icono">🔎</div>
                    <div class="label">Consulta DIAN</div>
                </a>
                @endunless
            @endcan

            {{-- ───────────────────────────────────────────────────────────── --}}
            {{-- DROPDOWN BRYNEX: solo para superadmin es_brynex              --}}
            {{-- ───────────────────────────────────────────────────────────── --}}
            @if(Auth::user()->hasRole('superadmin') && Auth::user()->es_brynex)
            <div class="menu-sep"></div>
            <div class="menu-dropdown brynex">
                <a href="{{ route('brynex.hub') }}" class="menu-dropdown-trigger {{ request()->routeIs('brynex*', 'admin.aliados*') ? 'activo' : '' }}">
                    <div class="icono">🔵</div>
                    <div class="label">BryNex</div>
                </a>
                <div class="menu-dropdown-panel">
                    <div class="panel-header">BryNex Global</div>

                    <a href="{{ route('brynex.hub') }}" class="panel-item {{ request()->routeIs('brynex.hub') ? 'activo' : '' }}">
                        <div class="pi">🔵</div> Hub BryNex
                    </a>
                    <a href="{{ route('admin.aliados.index') }}" class="panel-item {{ request()->routeIs('admin.aliados*') ? 'activo' : '' }}">
                        <div class="pi">🏢</div> Aliados
                    </a>
                    <a href="{{ route('brynex.accesos') }}" class="panel-item {{ request()->routeIs('brynex.accesos') ? 'activo' : '' }}">
                        <div class="pi">🔐</div> Accesos de Usuarios
                    </a>
                    @can('brynex_razones.ver')
                    <a href="{{ route('brynex.razones.index') }}" class="panel-item {{ request()->routeIs('brynex.razones*') ? 'activo' : '' }}">
                        <div class="pi">🏛️</div> Razones Sociales
                    </a>
                    @endcan
                    @can('brynex_dian.ver')
                    <a href="{{ route('brynex.dian.index') }}" class="panel-item {{ request()->routeIs('brynex.dian*') ? 'activo' : '' }}">
                        <div class="pi">🔎</div> Consulta DIAN
                    </a>
                    @endcan

                    <div class="panel-sep"></div>
                    <div class="panel-header" style="margin-top:0.2rem">Operaciones</div>

                    <a href="{{ route('admin.usuarios.index') }}" class="panel-item {{ request()->routeIs('admin.usuarios*') ? 'activo' : '' }}">
                        <div class="pi">👥</div> Usuarios
                    </a>
                    <a href="{{ route('admin.bitacora.index') }}" class="panel-item {{ request()->routeIs('admin.bitacora*') ? 'activo' : '' }}">
                        <div class="pi">👁️</div> Auditoría
                    </a>
                    <a href="{{ route('brynex.backups') }}" class="panel-item {{ request()->routeIs('brynex.backups*') ? 'activo' : '' }}">
                        <div class="pi">💾</div> Copias de Seguridad
                    </a>

                    @if(Auth::user()->cedula === config('finanzas.cedula_dueno'))
                    <div class="panel-sep"></div>
                    <a href="{{ route('finanzas.dashboard') }}" class="panel-item {{ request()->routeIs('finanzas*') ? 'activo' : '' }}" style="background: rgba(168, 85, 247, 0.08); border-left: 3px solid #a855f7;">
                        <div class="pi">💰</div> Finanzas Personales
                    </a>
                    @endif
                </div>
            </div>
            @endif
        </nav>

        <div class="header-user">
            {{-- Dropdown de usuario con opciones Salir y Cambiar Aliado --}}
            <div class="user-dropdown" id="userDropdown">
                <div class="user-dropdown-trigger" onclick="toggleUserDropdown()">
                    <div class="user-info">
                        <div class="nombre">{{ Auth::user()->nombre }}</div>
                        <div class="rol">{{ Auth::user()->getRoleNames()->first() ?? 'usuario' }}</div>
                    </div>
                    <span class="user-arrow">▼</span>
                </div>
                <div class="user-dropdown-panel">
                    <div class="user-panel-header">
                        👤 <strong>{{ Auth::user()->nombre }}</strong>
                    </div>
                    <div class="user-panel-sep"></div>

                    @if (Auth::check() && Auth::user()->es_brynex)
                    <a href="{{ route('aliado.selector') }}" class="user-panel-item">
                        <span class="upi">⇄</span> Cambiar aliado
                    </a>
                    <div class="user-panel-sep"></div>
                    @endif

                    <button type="button" class="user-panel-item danger" onclick="cerrarSesion()">
                        <span class="upi">⏻</span> Salir del sistema
                    </button>
                </div>
            </div>

            {{-- Botón hamburguesa (solo visible en móvil) --}}
            <button class="btn-hamburguesa" id="btnHamburguesa" onclick="abrirDrawer()" aria-label="Abrir menú">
                ☰
            </button>
        </div>
    </header>
    @endunless

    {{-- ═══════════════════════════════════════════════════════════════════ --}}
    {{-- MENÚ MÓVIL: Overlay + Drawer lateral (solo visible en pantallas pequeñas) --}}
    {{-- ═══════════════════════════════════════════════════════════════════ --}}
    @unless(request()->has('iframe'))
    <div class="mobile-overlay" id="mobileOverlay" onclick="cerrarDrawer()"></div>

    <nav class="mobile-drawer" id="mobileDrawer" aria-label="Menú de navegación">

        {{-- Cabecera del drawer --}}
        <div class="drawer-header">
            <div class="drawer-header-left">
                <div>
                    <div class="drawer-title">{{ $alidoActivo->nombre ?? 'BryNex' }}</div>
                    <div class="drawer-empresa">Panel de gestión</div>
                </div>
            </div>
            <button class="drawer-close" onclick="cerrarDrawer()" aria-label="Cerrar menú">✕</button>
        </div>

        {{-- Navegación principal --}}
        <div class="drawer-section">
            <div class="drawer-section-label">Navegación</div>

            <a href="{{ route('dashboard') }}" class="drawer-item {{ request()->routeIs('dashboard') ? 'activo' : '' }}">
                <span class="di-icon">🏠</span> Inicio
            </a>
            <a href="{{ route('admin.clientes.index') }}" class="drawer-item {{ request()->routeIs('admin.clientes*') ? 'activo' : '' }}">
                <span class="di-icon">👥</span> Clientes
            </a>
            <a href="{{ route('admin.facturacion.index') }}" class="drawer-item {{ request()->routeIs('admin.facturacion*') ? 'activo' : '' }}">
                <span class="di-icon">🏢</span> Empresas
            </a>
            @can('afiliaciones.ver')
            <a href="{{ route('admin.afiliaciones.index') }}" class="drawer-item {{ request()->routeIs('admin.afiliaciones*') ? 'activo' : '' }}">
                <span class="di-icon">🤝</span> Afiliaciones
            </a>
            @endcan
            @can('planos.ver')
            <a href="{{ route('admin.planos.index') }}" class="drawer-item {{ request()->routeIs('admin.planos*') ? 'activo' : '' }}">
                <span class="di-icon">📄</span> Planos SS
            </a>
            @endcan
            @can('cobros.ver')
            <a href="{{ route('admin.cobros.index') }}" class="drawer-item {{ request()->routeIs('admin.cobros*') ? 'activo' : '' }}">
                <span class="di-icon">💰</span> Cobros
            </a>
            @endcan
            @can('prestamos.ver')
            <a href="{{ route('admin.prestamos.index') }}" class="drawer-item {{ request()->routeIs('admin.prestamos*') ? 'activo' : '' }}">
                <span class="di-icon">📋</span> Préstamos
            </a>
            @endcan
            <a href="{{ route('admin.tareas.index') }}" class="drawer-item {{ request()->routeIs('admin.tareas*') ? 'activo' : '' }}">
                <span class="di-icon">📌</span> Tareas
            </a>
            <a href="{{ route('admin.incapacidades.index') }}" class="drawer-item {{ request()->routeIs('admin.incapacidades*') ? 'activo' : '' }}">
                <span class="di-icon">🏥</span> Incapacidades
            </a>
            @can('whatsapp.ver')
            <a href="{{ route('admin.whatsapp.chat.index') }}" class="drawer-item {{ request()->routeIs('admin.whatsapp*') ? 'activo' : '' }}">
                <span class="di-icon">💬</span> WhatsApp
            </a>
            @endcan
        </div>

        {{-- Sección financiero --}}
        @canany(['cuadre_diario.ver', 'cotizaciones.ver'])
        <div class="drawer-sep"></div>
        <div class="drawer-section">
            <div class="drawer-section-label">Financiero</div>
            @can('cuadre_diario.ver')
            <a href="{{ route('admin.cuadre-diario.index') }}" class="drawer-item {{ request()->routeIs('admin.cuadre-diario*') ? 'activo' : '' }}">
                <span class="di-icon">🧾</span> Cuadre Caja
            </a>
            @endcan
            @can('cotizaciones.ver')
            <a href="{{ route('admin.cotizaciones.index') }}" class="drawer-item {{ request()->routeIs('admin.cotizaciones*') ? 'activo' : '' }}">
                <span class="di-icon">💼</span> Cotizaciones
            </a>
            @endcan
        </div>
        @endcanany

        {{-- Sección reportes --}}
        @canany(['informes.ver', 'comisiones.ver'])
        <div class="drawer-sep"></div>
        <div class="drawer-section">
            <div class="drawer-section-label">Reportes</div>
            @can('informes.ver')
            <a href="{{ route('admin.informes.hub') }}" class="drawer-item {{ request()->routeIs('admin.informes*') && !request()->routeIs('admin.informes.comisiones*') ? 'activo' : '' }}">
                <span class="di-icon">📊</span> Informes
            </a>
            @endcan
            @can('comisiones.ver')
            <a href="{{ route('admin.informes.comisiones.index') }}" class="drawer-item {{ request()->routeIs('admin.informes.comisiones*') ? 'activo' : '' }}">
                <span class="di-icon">💼</span> Comisiones
            </a>
            @endcan
        </div>
        @endcanany

        {{-- Sección administración --}}
        @canany(['asesores.ver', 'usuarios.ver', 'entrar-configuracion', 'bitacora.ver', 'traslados_rs.ejecutar', 'razones_sociales.gestionar', 'razones_sociales.documentos'])
        <div class="drawer-sep"></div>
        <div class="drawer-section">
            <div class="drawer-section-label">Administración</div>
            @can('asesores.ver')
            <a href="{{ route('admin.asesores.index') }}" class="drawer-item {{ request()->routeIs('admin.asesores*') ? 'activo' : '' }}">
                <span class="di-icon">🤝</span> Asesores
            </a>
            @endcan
            @can('usuarios.ver')
            <a href="{{ route('admin.usuarios.index') }}" class="drawer-item {{ request()->routeIs('admin.usuarios*') ? 'activo' : '' }}">
                <span class="di-icon">👥</span> Usuarios
            </a>
            @endcan
            @can('entrar-configuracion')
            <a href="{{ route('admin.configuracion.hub') }}" class="drawer-item {{ request()->routeIs('admin.configuracion*') ? 'activo' : '' }}">
                <span class="di-icon">⚙️</span> Configuración
            </a>
            @endcan
            @can('bitacora.ver')
            <a href="{{ route('admin.bitacora.index') }}" class="drawer-item {{ request()->routeIs('admin.bitacora*') ? 'activo' : '' }}">
                <span class="di-icon">👁️</span> Auditoría
            </a>
            @endcan
            @canany(['razones_sociales.gestionar', 'razones_sociales.documentos'])
            <a href="{{ route('admin.configuracion.razones.index') }}" class="drawer-item {{ request()->routeIs('admin.configuracion.razones*') ? 'activo' : '' }}">
                <span class="di-icon">🏭</span> Razones sociales
            </a>
            @endcanany
            @can('traslados_rs.ejecutar')
            <a href="{{ route('admin.traslados.index') }}" class="drawer-item {{ request()->routeIs('admin.traslados*') ? 'activo' : '' }}">
                <span class="di-icon">🔄</span> Traslados RS
            </a>
            @endcan
        </div>
        @endcanany

        @if(Auth::user()->hasRole('superadmin') && Auth::user()->es_brynex)
        <div class="drawer-sep"></div>
        <div class="drawer-section">
            <div class="drawer-section-label">BryNex Global</div>
            <a href="{{ route('brynex.hub') }}" class="drawer-item {{ request()->routeIs('brynex.hub') ? 'activo' : '' }}">
                <span class="di-icon">🔵</span> Hub BryNex
            </a>
            <a href="{{ route('admin.aliados.index') }}" class="drawer-item {{ request()->routeIs('admin.aliados*') ? 'activo' : '' }}">
                <span class="di-icon">🏢</span> Aliados
            </a>
            <a href="{{ route('brynex.accesos') }}" class="drawer-item {{ request()->routeIs('brynex.accesos') ? 'activo' : '' }}">
                <span class="di-icon">🔐</span> Accesos de Usuarios
            </a>
            <a href="{{ route('brynex.backups') }}" class="drawer-item {{ request()->routeIs('brynex.backups*') ? 'activo' : '' }}">
                <span class="di-icon">💾</span> Copias de Seguridad
            </a>
        </div>
        @endif

        {{-- Footer con info del usuario --}}
        <div class="drawer-footer">
            <div class="drawer-user-card">
                <div class="drawer-user-avatar">
                    {{ strtoupper(substr(Auth::user()->nombre, 0, 1)) }}
                </div>
                <div>
                    <div class="du-nombre">{{ Auth::user()->nombre }}</div>
                    <div class="du-rol">{{ Auth::user()->getRoleNames()->first() ?? 'usuario' }}</div>
                </div>
            </div>
            @if (Auth::check() && Auth::user()->es_brynex)
            <a href="{{ route('aliado.selector') }}" class="drawer-item">
                <span class="di-icon">⇄</span> Cambiar aliado
            </a>
            @endif
            <div class="drawer-sep" style="margin: 0.4rem 0"></div>
            <button type="button" class="drawer-item danger" onclick="cerrarSesion()">
                <span class="di-icon">⏻</span> Salir del sistema
            </button>
        </div>

    </nav>
    @endunless


    {{-- Contenido de la página --}}
    <main class="contenido" style="{{ request()->has('iframe') ? 'padding:.75rem 1rem;' : '' }}">
        @if (session('success'))
            <div class="flash success">✅ {{ session('success') }}</div>
        @endif
        @if (session('warning'))
            <div class="flash warning">{{ session('warning') }}</div>
        @endif

        @yield('contenido')
    </main>

    <script>
        // Nombre de persona en tipo oración ("EDUARDO ALFONZO" → "Eduardo Alfonzo"),
        // el mismo criterio que nombre_oracion() en app/helpers.php.
        function nombreOracion(v) {
            return String(v ?? '').trim().toLocaleLowerCase('es')
                .replace(/(^|[\s\-'])(\p{L})/gu, (m, sep, l) => sep + l.toLocaleUpperCase('es'));
        }

        // ── Dropdown de usuario ────────────────────────────────────────
        function toggleUserDropdown() {
            const dd = document.getElementById('userDropdown');
            dd.classList.toggle('open');
        }

        // ── Drawer móvil ───────────────────────────────────────────────
        function abrirDrawer() {
            document.getElementById('mobileDrawer').classList.add('open');
            document.getElementById('mobileOverlay').classList.add('open');
            document.body.style.overflow = 'hidden';
        }
        function cerrarDrawer() {
            document.getElementById('mobileDrawer').classList.remove('open');
            document.getElementById('mobileOverlay').classList.remove('open');
            document.body.style.overflow = '';
        }
        // Cerrar drawer con tecla Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') cerrarDrawer();
        });

        // Cerrar al hacer clic fuera
        document.addEventListener('click', function(e) {
            const dd = document.getElementById('userDropdown');
            if (dd && !dd.contains(e.target)) {
                dd.classList.remove('open');
            }
        });

        // ── Cerrar sesión — usa CSRF del meta-tag, no del form (evita error 419) ──
        function cerrarSesion() {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '{{ route('logout') }}';
            form.style.display = 'none';

            const csrf = document.createElement('input');
            csrf.type  = 'hidden';
            csrf.name  = '_token';
            csrf.value = document.querySelector('meta[name="csrf-token"]')?.content
                         || '{{ csrf_token() }}';
            form.appendChild(csrf);

            document.body.appendChild(form);
            form.submit();
        }
        // ── Refresh automático del CSRF token (evita error 419 en forms largos) ──
        // Hace un GET liviano cada 20 minutos para mantener el token fresco.
        (function csrfKeepAlive() {
            const INTERVAL = 20 * 60 * 1000; // 20 minutos
            setInterval(async function() {
                try {
                    const r = await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' });
                    if (!r.ok) return; // silencioso si falla
                    // Actualizar el meta-tag con el token de la cookie XSRF-TOKEN
                    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
                    if (match) {
                        const token = decodeURIComponent(match[1]);
                        const meta = document.querySelector('meta[name="csrf-token"]');
                        if (meta) meta.content = token;
                        // También actualizar todos los inputs _token en el DOM
                        document.querySelectorAll('input[name="_token"]').forEach(el => el.value = token);
                    }
                } catch(e) { /* silencioso */ }
            }, INTERVAL);
        })();

        // ── Badge de mensajes no leídos de WhatsApp ───────────────────────────
        // Actualiza el badge del menú cada 30 segundos via polling, pero SOLO
        // con la pestaña a la vista. Cada pestaña abierta preguntaba por su
        // lado, también las de fondo donde nadie ve el número: con 3-6 pestañas
        // por oficina, el 17-sep-2026 fueron 23.000 consultas en el día, el 66%
        // de las peticiones y el 36% del tiempo real del servidor.
        @auth
        @can('whatsapp.ver')
        (function waBadge() {
            const badge = document.getElementById('wa-badge');
            if (!badge) return;

            let ultimaConsulta = 0;

            async function actualizarBadge() {
                ultimaConsulta = Date.now();
                try {
                    const resp = await fetch('{{ route('admin.whatsapp.api.no_leidos') }}', {
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                    });
                    if (!resp.ok) return;
                    const data = await resp.json();
                    const total = data.total || 0;
                    if (total > 0) {
                        badge.textContent = total > 99 ? '99+' : total;
                        badge.style.display = 'inline-block';
                    } else {
                        badge.style.display = 'none';
                    }
                } catch(e) { /* silencioso */ }
            }

            function siEstaVisible() {
                if (!document.hidden) actualizarBadge();
            }

            // Una página abierta en segundo plano (Ctrl+clic) carga oculta: no
            // pregunta hasta que se mira.
            siEstaVisible();
            setInterval(siEstaVisible, 30000); // cada 30 segundos, si se está viendo

            // Al volver a la pestaña el número se pone al día enseguida, sin
            // esperar al siguiente turno (salvo que acabe de consultarse).
            document.addEventListener('visibilitychange', function () {
                if (!document.hidden && Date.now() - ultimaConsulta > 5000) actualizarBadge();
            });
        })();
        @endcan
        @can('tareas.ver')
        // Solicitudes nuevas del portal de empresas: el globo en «Tareas» y un
        // aviso emergente la primera vez que se ve cada una. Mismo cuidado que
        // el de WhatsApp: solo pregunta con la pestaña a la vista.
        (function portalBadge() {
            const badge = document.getElementById('portal-badge');
            let ultimaConsulta = 0;
            const clave = 'brynex_portal_visto_{{ auth()->id() }}';
            let vistoHasta = 0;
            try { vistoHasta = parseInt(localStorage.getItem(clave) || '0', 10); } catch (e) {}

            function avisar(s) {
                const el = document.createElement('a');
                el.href = '{{ route('admin.tareas.index') }}?tarea=' + s.tarea_id;
                el.className = 'aviso-portal';
                el.innerHTML = '<span class="ap-ico">🌐</span><span><b></b><small></small></span><span class="ap-x" title="Cerrar">×</span>';
                el.querySelector('b').textContent = 'Nueva solicitud de ' + s.empresa;
                el.querySelector('small').textContent = s.tipo + ' · Toca para abrirla';
                el.querySelector('.ap-x').addEventListener('click', (ev) => { ev.preventDefault(); el.remove(); });
                document.body.appendChild(el);
                setTimeout(() => el.classList.add('ap-sale'), 12000);
                setTimeout(() => el.remove(), 12600);
            }

            async function consultar() {
                ultimaConsulta = Date.now();
                try {
                    const r = await fetch('{{ route('admin.portal_solicitudes.nuevas') }}', { headers: { 'Accept': 'application/json' } });
                    if (!r.ok) return;
                    const d = await r.json();
                    if (badge) {
                        badge.textContent = d.total > 99 ? '99+' : d.total;
                        badge.style.display = d.total > 0 ? 'inline-block' : 'none';
                    }
                    const nuevas = (d.ultimas || []).filter(s => s.id > vistoHasta);
                    nuevas.slice(0, 3).reverse().forEach(avisar);
                    if (nuevas.length) {
                        vistoHasta = Math.max(...nuevas.map(s => s.id));
                        try { localStorage.setItem(clave, String(vistoHasta)); } catch (e) {}
                    }
                } catch (e) { /* silencioso */ }
            }

            function siEstaVisible() { if (!document.hidden) consultar(); }
            siEstaVisible();
            setInterval(siEstaVisible, 60000);
            document.addEventListener('visibilitychange', function () {
                if (!document.hidden && Date.now() - ultimaConsulta > 5000) consultar();
            });
        })();
        @endcan
        @endauth
    </script>
    <style>
        .aviso-portal { position: fixed; right: 18px; bottom: 18px; z-index: 9999; display: flex; align-items: center; gap: .7rem;
            background: linear-gradient(135deg, #0a1628, #1e40af); color: #fff; text-decoration: none; border-radius: 14px;
            padding: .8rem 1rem; box-shadow: 0 12px 34px rgba(10,22,40,.35); max-width: 340px;
            animation: ap-entra .45s cubic-bezier(.2,.8,.2,1); transition: opacity .5s, transform .5s; }
        .aviso-portal + .aviso-portal { bottom: 92px; }
        .aviso-portal .ap-ico { font-size: 1.4rem; animation: ap-late 1.4s ease-in-out 2; }
        .aviso-portal b { display: block; font-size: .85rem; }
        .aviso-portal small { display: block; font-size: .72rem; color: #93c5fd; }
        .aviso-portal .ap-x { margin-left: .4rem; font-size: 1.1rem; opacity: .7; cursor: pointer; }
        .aviso-portal.ap-sale { opacity: 0; transform: translateY(12px); }
        @keyframes ap-entra { from { opacity: 0; transform: translateY(20px) scale(.96); } to { opacity: 1; transform: none; } }
        @keyframes ap-late { 50% { transform: scale(1.2) rotate(-8deg); } }
        @media (prefers-reduced-motion: reduce) { .aviso-portal, .aviso-portal .ap-ico { animation: none; } }
    </style>

    @auth
        @php($__iaAlidoId = session('aliado_id_activo'))
        @php($__iaConfig = $__iaAlidoId ? \App\Models\IaConfiguracionAliado::where('aliado_id', $__iaAlidoId)->where('activo_web', true)->first() : null)
        @if($__iaConfig)
            @include('components.asistente-ia-widget', ['nombreBot' => $__iaConfig->nombreBot()])
        @endif
    @endauth

    @stack('scripts')
</body>
</html>
