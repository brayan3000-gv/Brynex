@php
    $empresaPortal = $acceso->empresa;
    $aliadoPortal = $acceso->aliado;
    $nombreEmpresa = $empresaPortal->empresa ?: 'Mi empresa';
    $iniciales = collect(preg_split('/\s+/', trim($nombreEmpresa)))->filter()->take(2)
        ->map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)))->implode('');
    $whatsAliado = preg_replace('/\D/', '', (string) ($aliadoPortal->whatsapp ?: $aliadoPortal->celular));
    if ($whatsAliado && strlen($whatsAliado) === 10) {
        $whatsAliado = '57'.$whatsAliado;
    }
    $menu = [
        ['portal.inicio', 'inicio', 'Mi mes'],
        ['portal.tramites', 'tramites', 'Trámites'],
        ['portal.facturas', 'facturas', 'Facturas'],
        ['portal.incapacidades', 'incapacidades', 'Incapacidades'],
        ['portal.retirados', 'retirados', 'Retirados'],
    ];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0a1628">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">
    <title>@yield('titulo', 'Portal') · {{ $nombreEmpresa }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        :root {
            --azul-oscuro: #0a1628;
            --azul-medio: #0d2550;
            --azul-vivo: #1e40af;
            --azul-btn: #2563eb;
            --acento: #3b82f6;
            --fondo: #f0f4f8;
            --tinta: #0f172a;
            --tinta-2: #334155;
            --tenue: #64748b;
            --borde: #e2e8f0;
            --verde: #10b981;
            --radio: 12px;
            --sombra: 0 2px 8px rgba(15, 23, 42, .06);
            --sombra-alta: 0 10px 30px rgba(15, 23, 42, .12);
            --alto-barra: 64px;
        }
        * { box-sizing: border-box; }
        [x-cloak] { display: none !important; }
        html { -webkit-text-size-adjust: 100%; }
        body {
            margin: 0; font-family: 'Inter', system-ui, sans-serif; background: var(--fondo);
            color: var(--tinta); font-size: 15px; line-height: 1.45; min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }
        a { color: inherit; }
        .ic { width: 20px; height: 20px; flex-shrink: 0; }
        .ic-sm { width: 16px; height: 16px; flex-shrink: 0; }
        .num { font-variant-numeric: tabular-nums; }

        /* ── Barra de carga entre páginas ─────────────────────── */
        .pt-carga { position: fixed; top: 0; left: 0; height: 3px; width: 0; z-index: 100;
            background: linear-gradient(90deg, #60a5fa, #a78bfa); box-shadow: 0 0 10px #60a5fa;
            transition: width .6s ease, opacity .3s; }
        .pt-carga.activa { width: 85%; transition: width 8s cubic-bezier(.1,.7,.1,1); }

        /* ── Encabezado ───────────────────────────────────────── */
        .pt-header { background: linear-gradient(135deg, var(--azul-oscuro) 0%, var(--azul-medio) 60%, var(--azul-vivo) 100%);
            color: #fff; box-shadow: 0 2px 12px rgba(0,0,0,.35); position: sticky; top: 0; z-index: 40; }
        .pt-header-in { max-width: 1280px; margin: 0 auto; padding: .7rem 1rem; display: flex; align-items: center; gap: 1rem; }
        .pt-marca { display: flex; align-items: center; gap: .75rem; min-width: 0; flex: 1; }
        .pt-marca img { height: 34px; width: auto; max-width: 110px; object-fit: contain; }
        .pt-empresa { font-weight: 800; font-size: 1rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .pt-sub { font-size: .72rem; color: #93c5fd; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .pt-nav { display: none; gap: .25rem; }
        .pt-nav a { display: flex; align-items: center; gap: .45rem; text-decoration: none; color: rgba(255,255,255,.78);
            padding: .5rem .8rem; border-radius: 9px; font-size: .84rem; font-weight: 600; border: 1px solid transparent;
            transition: background .15s, color .15s; }
        .pt-nav a:hover { background: rgba(59,130,246,.2); color: #fff; }
        .pt-nav a.activo { background: rgba(59,130,246,.25); border-color: rgba(59,130,246,.45); color: #fff; }
        .pt-avatar { width: 38px; height: 38px; border-radius: 50%; border: 1px solid rgba(147,197,253,.5);
            background: rgba(59,130,246,.25); color: #fff; font-weight: 800; font-size: .82rem; cursor: pointer;
            display: grid; place-items: center; transition: transform .15s, background .15s; }
        .pt-avatar:hover { transform: scale(1.06); background: rgba(59,130,246,.4); }
        .pt-menu { position: absolute; right: 0; top: calc(100% + 8px); background: #fff; color: var(--tinta);
            border-radius: 12px; box-shadow: var(--sombra-alta); min-width: 230px; padding: .4rem; z-index: 50; }
        .pt-menu .cab { padding: .6rem .7rem .5rem; border-bottom: 1px solid var(--borde); margin-bottom: .3rem; }
        .pt-menu .cab b { display: block; font-size: .85rem; }
        .pt-menu .cab span { font-size: .75rem; color: var(--tenue); }
        .pt-menu a, .pt-menu button { display: flex; width: 100%; align-items: center; gap: .6rem; padding: .6rem .7rem;
            border-radius: 8px; border: 0; background: none; font: inherit; font-size: .85rem; color: var(--tinta-2);
            text-decoration: none; cursor: pointer; text-align: left; }
        .pt-menu a:hover, .pt-menu button:hover { background: #f1f5f9; }

        /* ── Contenido ────────────────────────────────────────── */
        .pt-main { max-width: 1280px; margin: 0 auto; padding: 1.1rem 1rem calc(var(--alto-barra) + 2rem + env(safe-area-inset-bottom)); }
        .pt-titulo { display: flex; align-items: flex-end; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin: .3rem 0 1.1rem; }
        .pt-titulo h1 { font-size: 1.45rem; margin: 0; letter-spacing: -.01em; }
        .pt-titulo p { margin: .2rem 0 0; color: var(--tenue); font-size: .88rem; }

        .tarjeta { background: #fff; border-radius: var(--radio); border: 1px solid var(--borde); box-shadow: var(--sombra); }
        .kpis { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .75rem; margin-bottom: 1rem; }
        .kpi { padding: .95rem 1rem; position: relative; overflow: hidden; transition: transform .2s, box-shadow .2s; }
        .kpi:hover { transform: translateY(-2px); box-shadow: 0 4px 18px rgba(59,130,246,.12); }
        .kpi .et { display: flex; align-items: center; gap: .45rem; font-size: .72rem; font-weight: 700; color: var(--tenue);
            text-transform: uppercase; letter-spacing: .04em; }
        .kpi .et .ic-sm { color: var(--acento); }
        .kpi .valor { font-size: 1.45rem; font-weight: 800; margin-top: .35rem; letter-spacing: -.02em; }
        .kpi .nota { font-size: .76rem; color: var(--tenue); margin-top: .15rem; }
        .kpi.destacado { background: linear-gradient(135deg, #0f172a, #1e3a5f); color: #fff; border-color: transparent; }
        .kpi.destacado .et, .kpi.destacado .nota { color: #93c5fd; }
        .kpi.destacado .et .ic-sm { color: #93c5fd; }
        .barra { height: 7px; border-radius: 99px; background: #e2e8f0; overflow: hidden; margin-top: .55rem; }
        .barra > i { display: block; height: 100%; width: 0; border-radius: 99px;
            background: linear-gradient(90deg, #10b981, #34d399); transition: width 1.1s cubic-bezier(.2,.7,.2,1); }

        .chip { display: inline-flex; align-items: center; gap: .3rem; border-radius: 999px; padding: .18rem .6rem;
            font-size: .72rem; font-weight: 700; white-space: nowrap; line-height: 1.3; }
        .chip-ok { background: #dcfce7; color: #15803d; }
        .chip-warn { background: #fef3c7; color: #92400e; }
        .chip-err { background: #fee2e2; color: #b91c1c; }
        .chip-info { background: #dbeafe; color: #1d4ed8; }
        .chip-lila { background: #ede9fe; color: #6d28d9; }
        .chip-gris { background: #f1f5f9; color: #475569; }

        .aviso { display: flex; gap: .7rem; align-items: flex-start; padding: .8rem 1rem; border-radius: 10px; font-size: .86rem; margin-bottom: 1rem; }
        .aviso-warn { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
        .aviso-ok { background: rgba(16,185,129,.1); border: 1px solid rgba(16,185,129,.3); color: #065f46; }
        .aviso-info { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }

        /* Herramientas: búsqueda y filtros */
        .herr { display: flex; gap: .6rem; flex-wrap: wrap; align-items: center; margin-bottom: .8rem; }
        .buscar { flex: 1 1 240px; display: flex; align-items: center; gap: .5rem; background: #fff; border: 1.5px solid var(--borde);
            border-radius: 10px; padding: 0 .75rem; transition: border-color .15s, box-shadow .15s; }
        .buscar:focus-within { border-color: var(--acento); box-shadow: 0 0 0 3px rgba(59,130,246,.12); }
        .buscar input { border: 0; outline: 0; font: inherit; font-size: .9rem; padding: .62rem 0; width: 100%; background: none; }
        .buscar .ic-sm { color: #94a3b8; }
        .filtros { display: flex; gap: .4rem; overflow-x: auto; scrollbar-width: none; -webkit-overflow-scrolling: touch; }
        .filtros::-webkit-scrollbar { display: none; }
        .filtro { border: 1.5px solid var(--borde); background: #fff; color: var(--tinta-2); border-radius: 999px;
            padding: .45rem .85rem; font: inherit; font-size: .8rem; font-weight: 600; cursor: pointer; white-space: nowrap;
            transition: all .15s; }
        .filtro:hover { border-color: #93c5fd; }
        .filtro.activo { background: var(--azul-btn); border-color: var(--azul-btn); color: #fff; box-shadow: 0 4px 12px rgba(37,99,235,.25); }
        .filtro .n { opacity: .7; margin-left: .2rem; }

        .btn { display: inline-flex; align-items: center; justify-content: center; gap: .45rem; background: var(--azul-btn);
            color: #fff; border: 0; border-radius: 9px; padding: .6rem 1rem; font: inherit; font-size: .85rem; font-weight: 700;
            cursor: pointer; text-decoration: none; transition: background .15s, transform .1s, box-shadow .15s; }
        .btn:hover { background: var(--acento); transform: translateY(-1px); box-shadow: 0 6px 16px rgba(37,99,235,.25); }
        .btn:active { transform: translateY(0); }
        .btn-sec { background: #f1f5f9; color: var(--tinta-2); }
        .btn-sec:hover { background: #e2e8f0; box-shadow: none; }
        .btn-verde { background: var(--verde); }
        .btn-verde:hover { background: #059669; box-shadow: 0 6px 16px rgba(16,185,129,.25); }
        .btn-ico { width: 34px; height: 34px; padding: 0; border-radius: 9px; }

        .vacio { text-align: center; padding: 2.5rem 1rem; color: var(--tenue); }
        .vacio .ic { width: 42px; height: 42px; color: #cbd5e1; margin-bottom: .5rem; }

        /* ── Panel de detalle: lateral en PC, hoja inferior en celular ── */
        .velo { position: fixed; inset: 0; background: rgba(10,22,40,.5); backdrop-filter: blur(2px); z-index: 60; }
        .panel { position: fixed; z-index: 61; background: #fff; box-shadow: var(--sombra-alta); overflow-y: auto;
            left: 0; right: 0; bottom: 0; max-height: 88vh; border-radius: 18px 18px 0 0;
            padding: 0 1.1rem calc(1.2rem + env(safe-area-inset-bottom)); }
        .panel .asa { width: 42px; height: 5px; border-radius: 9px; background: #cbd5e1; margin: .6rem auto .4rem; }
        .panel-cab { position: sticky; top: 0; background: #fff; padding: .6rem 0 .8rem; display: flex; gap: .8rem;
            align-items: flex-start; justify-content: space-between; border-bottom: 1px solid var(--borde); margin-bottom: .9rem; z-index: 1; }
        .panel-cab h2 { font-size: 1.1rem; margin: 0; }
        .panel-sec { margin-bottom: 1.1rem; }
        .panel-sec h3 { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: var(--tenue); margin: 0 0 .5rem; }
        .datos { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .5rem; }
        .dato { background: #f8fafc; border-radius: 9px; padding: .55rem .7rem; }
        .dato small { display: block; font-size: .68rem; color: var(--tenue); font-weight: 600; }
        .dato div { font-size: .88rem; font-weight: 600; margin-top: .1rem; overflow-wrap: anywhere; }
        .linea { display: flex; justify-content: space-between; gap: 1rem; padding: .45rem 0; border-bottom: 1px dashed var(--borde); font-size: .88rem; }
        .linea.total { border-bottom: 0; font-weight: 800; font-size: 1rem; padding-top: .7rem; }
        .planilla { display: flex; align-items: center; justify-content: space-between; gap: .8rem; padding: .7rem .8rem;
            border: 1px solid var(--borde); border-radius: 10px; margin-bottom: .5rem; }

        /* ── Navegación inferior (celular) ─────────────────────── */
        .pt-abajo { position: fixed; bottom: 0; left: 0; right: 0; z-index: 45; background: rgba(255,255,255,.96);
            backdrop-filter: blur(10px); border-top: 1px solid var(--borde); display: grid; grid-template-columns: repeat(5, 1fr);
            height: calc(var(--alto-barra) + env(safe-area-inset-bottom)); padding-bottom: env(safe-area-inset-bottom); }
        .pt-abajo a { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .2rem;
            text-decoration: none; color: #94a3b8; font-size: .62rem; font-weight: 700; position: relative; transition: color .15s; }
        .pt-abajo a .ic { transition: transform .2s; }
        .pt-abajo a.activo { color: var(--azul-btn); }
        .pt-abajo a.activo .ic { transform: translateY(-2px) scale(1.08); }
        .pt-abajo a.activo::before { content: ''; position: absolute; top: 0; width: 28px; height: 3px; border-radius: 0 0 4px 4px; background: var(--azul-btn); }

        .pt-pie { max-width: 1280px; margin: 0 auto; padding: 0 1rem calc(var(--alto-barra) + 1.5rem); color: var(--tenue);
            font-size: .78rem; display: flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap; align-items: center; }
        .pt-wa { display: inline-flex; align-items: center; gap: .4rem; color: #047857; font-weight: 700; text-decoration: none; }

        /* ── Animaciones ──────────────────────────────────────── */
        .aparece { opacity: 0; animation: pt-sube .5s cubic-bezier(.2,.7,.2,1) forwards; animation-delay: calc(var(--i, 0) * 60ms); }
        @keyframes pt-sube { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: none; } }
        @keyframes pt-brillo { 0% { background-position: -300px 0; } 100% { background-position: 300px 0; } }
        .t-sube-enter { transition: transform .3s cubic-bezier(.2,.8,.2,1), opacity .3s; }
        .t-sube-from { transform: translateY(100%); opacity: .6; }
        .t-fade-enter { transition: opacity .2s; }
        .t-fade-from { opacity: 0; }

        @media (min-width: 640px) {
            .kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .datos { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        @media (min-width: 900px) {
            body { font-size: 14.5px; }
            .pt-nav { display: flex; }
            .pt-abajo { display: none; }
            .pt-main { padding-bottom: 2rem; }
            .pt-pie { padding-bottom: 1.5rem; }
            .kpis { grid-template-columns: repeat(4, minmax(0, 1fr)); }
            .panel { left: auto; top: 0; bottom: 0; width: 460px; max-height: none; border-radius: 0; padding-top: .4rem; }
            .panel .asa { display: none; }
            .t-sube-from { transform: translateX(100%); opacity: 1; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation-duration: .01ms !important; animation-delay: 0ms !important; transition-duration: .01ms !important; }
        }
    </style>
    @stack('estilos')
</head>
<body x-data>
    <div class="pt-carga" id="pt-carga"></div>

    <header class="pt-header">
        <div class="pt-header-in">
            <div class="pt-marca">
                @if(!empty($aliadoPortal->logo))
                    <img src="{{ asset('storage/'.$aliadoPortal->logo) }}" alt="{{ $aliadoPortal->nombre }}">
                @else
                    <img src="{{ asset('img/logo-brynex.png') }}" alt="BryNex">
                @endif
                <div style="min-width:0">
                    <div class="pt-empresa">{{ $nombreEmpresa }}</div>
                    <div class="pt-sub">Portal de empresa · {{ $aliadoPortal->nombre }}</div>
                </div>
            </div>

            <nav class="pt-nav" aria-label="Secciones">
                @foreach($menu as [$ruta, $icono, $texto])
                    <a href="{{ route($ruta) }}" class="{{ request()->routeIs($ruta, $ruta.'.*') ? 'activo' : '' }}">
                        @include('portal._icono', ['n' => $icono, 'clase' => 'ic-sm']) {{ $texto }}
                    </a>
                @endforeach
            </nav>

            <div style="position:relative" x-data="{ abierto: false }" @keydown.escape.window="abierto = false">
                <button type="button" class="pt-avatar" @click="abierto = !abierto" :aria-expanded="abierto" aria-label="Mi cuenta">{{ $iniciales ?: 'E' }}</button>
                <div class="pt-menu" x-show="abierto" x-cloak @click.outside="abierto = false"
                     x-transition:enter="t-fade-enter" x-transition:enter-start="t-fade-from">
                    <div class="cab">
                        <b>{{ $nombreEmpresa }}</b>
                        <span>NIT {{ $acceso->usuario }}</span>
                    </div>
                    <a href="{{ route('portal.clave') }}">@include('portal._icono', ['n' => 'llave', 'clase' => 'ic-sm']) Cambiar clave</a>
                    <form method="POST" action="{{ route('portal.salir') }}">
                        @csrf
                        <button type="submit">@include('portal._icono', ['n' => 'salir', 'clase' => 'ic-sm']) Salir</button>
                    </form>
                </div>
            </div>
        </div>
    </header>

    <main class="pt-main">
        @if(session('ok'))
            <div class="aviso aviso-ok aparece" x-data="{ v: true }" x-show="v" x-init="setTimeout(() => v = false, 5000)"
                 x-transition:leave="t-fade-enter" x-transition:leave-end="t-fade-from">
                @include('portal._icono', ['n' => 'check', 'clase' => 'ic-sm']) {{ session('ok') }}
            </div>
        @endif

        @yield('contenido')
    </main>

    <footer class="pt-pie">
        <span>Para ingresos, retiros, incapacidades o cambios, usa <a href="{{ route('portal.tramites') }}" style="font-weight:700">Trámites</a>: nosotros los revisamos y ejecutamos.</span>
        @if($whatsAliado)
            <a class="pt-wa" href="https://wa.me/{{ $whatsAliado }}" target="_blank" rel="noopener">
                @include('portal._icono', ['n' => 'whatsapp', 'clase' => 'ic-sm']) Escribir a {{ $aliadoPortal->nombre }}
            </a>
        @endif
    </footer>

    <nav class="pt-abajo" aria-label="Secciones">
        @foreach($menu as [$ruta, $icono, $texto])
            <a href="{{ route($ruta) }}" class="{{ request()->routeIs($ruta, $ruta.'.*') ? 'activo' : '' }}">
                @include('portal._icono', ['n' => $icono]) {{ $texto }}
            </a>
        @endforeach
    </nav>

    <script>
        // Las páginas del portal hacen cálculos pesados: una barra arriba avisa
        // que el clic sí se tomó mientras llega la siguiente.
        document.addEventListener('click', (e) => {
            const a = e.target.closest('a[href]');
            if (!a || a.target === '_blank' || a.hasAttribute('download') || e.metaKey || e.ctrlKey) return;
            const url = new URL(a.href, location.href);
            if (url.origin !== location.origin || url.pathname.includes('/planilla/') || a.getAttribute('href').startsWith('#')) return;
            document.getElementById('pt-carga').classList.add('activa');
        });
        window.addEventListener('pageshow', () => document.getElementById('pt-carga').classList.remove('activa'));

        // Formato de pesos colombianos y un contador que sube hasta el valor.
        window.pesos = (v) => '$' + Math.round(v || 0).toLocaleString('es-CO');
        window.contador = (fin, esDinero = true) => ({
            texto: esDinero ? pesos(0) : '0',
            init() {
                const t0 = performance.now(), dur = 900;
                const paso = (t) => {
                    const p = Math.min(1, (t - t0) / dur), e = 1 - Math.pow(1 - p, 3);
                    const v = fin * e;
                    this.texto = esDinero ? pesos(v) : Math.round(v).toLocaleString('es-CO');
                    if (p < 1) requestAnimationFrame(paso);
                };
                requestAnimationFrame(paso);
                // Con la pestaña en segundo plano el navegador pausa la animación:
                // el número tiene que quedar exacto igual.
                setTimeout(() => { this.texto = esDinero ? pesos(fin) : Math.round(fin).toLocaleString('es-CO'); }, dur + 150);
            },
        });
    </script>
    @stack('scripts')
</body>
</html>
