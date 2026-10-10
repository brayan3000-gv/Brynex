<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('titulo', $aliado->nombre)</title>
    <script>document.documentElement.classList.add('js');</script>
    <meta name="description" content="@yield('descripcion', $config->seo_descripcion ?? ($aliado->nombre . ' — Afiliación a seguridad social'))">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @php $urlCanonica = $urlCanonica ?? route('publico.aliado', $aliado->slug); @endphp

    <meta property="og:title" content="@yield('titulo', $aliado->nombre)">
    <meta property="og:description" content="@yield('descripcion', $config->seo_descripcion ?? '')">
    <meta property="og:url" content="{{ $urlCanonica }}">
    @if($aliado->logo)
        <meta property="og:image" content="{{ asset('storage/' . $aliado->logo) }}">
        <link rel="icon" href="{{ asset('storage/' . $aliado->logo) }}">
    @endif

    <link rel="canonical" href="{{ $urlCanonica }}">

    <script type="application/ld+json">
        {!! json_encode([
            '@context'  => 'https://schema.org',
            '@type'     => 'LocalBusiness',
            'name'      => $aliado->nombre,
            'address'   => array_filter([
                '@type'         => 'PostalAddress',
                'streetAddress' => $aliado->direccion,
                'addressLocality' => $aliado->ciudad,
                'addressCountry' => 'CO',
            ]),
            'telephone' => $whatsapp,
            'email'     => $aliado->correo,
            'url'       => $urlCanonica,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --brand: {{ $colorPrimario }};
            --brand-soft: color-mix(in srgb, var(--brand) 10%, white);
            --brand-softer: color-mix(in srgb, var(--brand) 5%, white);
            --brand-dark: color-mix(in srgb, var(--brand) 78%, black);
            --brand-line: color-mix(in srgb, var(--brand) 25%, white);
            --brand-text: {{ $textoSobreBrand }};
            --tinta: #0a1628;
            --tinta-suave: #47526b;
            --fondo: #f6f8fc;
            --borde: #e6eaf2;
            --blanco: #ffffff;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        html { scroll-behavior: smooth; }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--fondo);
            color: var(--tinta);
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        /* Títulos con una letra de más carácter; el texto corrido sigue en Inter. */
        h1, h2, h3, .titulo-display { font-family: 'Plus Jakarta Sans', 'Inter', sans-serif; }

        a { color: inherit; text-decoration: none; }
        img { max-width: 100%; display: block; }

        .contenedor { max-width: 1080px; margin: 0 auto; padding: 0 1.5rem; }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.85rem 1.6rem;
            border-radius: 999px;
            font-weight: 600;
            font-size: 0.95rem;
            border: none;
            cursor: pointer;
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .btn:hover { transform: translateY(-1px); }

        .btn-brand {
            background: linear-gradient(135deg, var(--brand), var(--brand-dark));
            color: var(--brand-text);
            box-shadow: 0 8px 24px -8px color-mix(in srgb, var(--brand) 55%, transparent);
        }

        .btn-ghost {
            background: var(--blanco);
            color: var(--tinta);
            border: 1px solid var(--borde);
        }

        section { padding: 4.5rem 0; }

        /* Aparición al bajar: solo con JS (la clase `js` la pone el script del <head>), así
           sin JS nada queda oculto. */
        .js .revelar { opacity: 0; transform: translateY(26px); transition: opacity .7s ease, transform .7s cubic-bezier(.2,.7,.2,1); transition-delay: var(--retraso, 0s); }
        .js .revelar.visible { opacity: 1; transform: none; }

        .wa-flotante::before {
            content: ''; position: absolute; inset: 0; border-radius: 50%;
            background: #25D366; z-index: -1; animation: waLatido 2.4s ease-out infinite;
        }
        @keyframes waLatido { 0% { transform: scale(1); opacity: .55; } 80%, 100% { transform: scale(1.6); opacity: 0; } }

        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            .btn:hover { transform: none; }
            .js .revelar { opacity: 1; transform: none; transition: none; }
            .wa-flotante::before { animation: none; }
        }

        @media (max-width: 720px) {
            section { padding: 3rem 0; }
        }

        @yield('estilos')
    </style>
</head>
<body>

    @yield('contenido')

    {{-- WhatsApp flotante --}}
    @if($whatsapp)
        @php
            $numeroWa = preg_replace('/\D/', '', $whatsapp);
            if ($numeroWa && !str_starts_with($numeroWa, '57')) { $numeroWa = '57' . $numeroWa; }
            $mensajeWa = rawurlencode($config->whatsapp_mensaje_base ?: ('Hola ' . $aliado->nombre . ', quiero información sobre afiliación a seguridad social.'));
        @endphp
        <a href="https://wa.me/{{ $numeroWa }}?text={{ $mensajeWa }}"
           target="_blank" rel="noopener"
           aria-label="Escribir por WhatsApp"
           onclick="registrarClicWa()"
           class="wa-flotante"
           style="position:fixed; bottom:1.5rem; right:1.5rem; width:58px; height:58px; border-radius:50%;
                  background:#25D366; display:flex; align-items:center; justify-content:center;
                  box-shadow:0 10px 24px -6px rgba(0,0,0,0.35); z-index:50;">
            <svg width="30" height="30" viewBox="0 0 24 24" fill="white" aria-hidden="true">
                <path d="M12.04 2c-5.52 0-10 4.48-10 10 0 1.85.5 3.58 1.38 5.06L2 22l5.06-1.36A9.94 9.94 0 0 0 12.04 22c5.52 0 10-4.48 10-10s-4.48-10-10-10zm5.84 14.16c-.24.68-1.4 1.32-1.94 1.4-.5.08-1.12.11-1.8-.11-.42-.14-.96-.32-1.66-.62-2.92-1.26-4.82-4.2-4.96-4.4-.14-.2-1.18-1.56-1.18-2.98 0-1.42.74-2.12 1-2.4.26-.28.58-.36.78-.36.2 0 .4 0 .58.01.18.01.44-.07.68.52.26.62.86 2.14.94 2.3.08.16.14.34.02.54-.12.2-.18.32-.36.5-.18.18-.38.4-.54.54-.18.16-.36.34-.16.68.2.34.9 1.48 1.94 2.4 1.34 1.18 2.46 1.56 2.82 1.72.36.16.58.14.8-.08.26-.28.9-1.06 1.14-1.42.24-.36.48-.3.8-.18.32.12 2.02.96 2.36 1.14.34.18.56.26.64.4.08.16.08.9-.16 1.58z"/>
            </svg>
        </a>
    @endif

    <script>
        function registrarClicWa() {
            try {
                fetch(@json(route('publico.aliado.metrica', $aliado->slug)), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ tipo: 'clic_whatsapp' }),
                    keepalive: true,
                }).catch(function () {});
            } catch (e) {}
        }
    </script>

    <script>
        // Aparición de secciones y tarjetas al entrar en pantalla.
        (function () {
            var els = document.querySelectorAll('.revelar');
            if (!('IntersectionObserver' in window)) { els.forEach(function (e) { e.classList.add('visible'); }); return; }
            var io = new IntersectionObserver(function (entradas) {
                entradas.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('visible'); io.unobserve(en.target); } });
            }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
            els.forEach(function (e) { io.observe(e); });
        })();
    </script>

    @yield('scripts')
</body>
</html>
