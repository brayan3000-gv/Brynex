<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Alianzas Brygar — planes y cotizador</title>
    <meta name="description" content="Tres formas de trabajar con Brygar: Esencial, Específica e Integral. Cotice su alianza y vea cuánto tiempo de su equipo recupera con el servicio de afiliaciones.">
    <meta property="og:title" content="Alianzas Brygar — planes y cotizador">
    <meta property="og:description" content="Experiencia, tecnología propia BryNex y automatización para la gestión de la seguridad social.">
    <meta property="og:image" content="{{ asset('img/aliados/atencion.jpg') }}">
    <link rel="icon" href="{{ asset('img/logo-brynex.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700;12..96,800&family=Inter:wght@400;500;600&display=swap">
    <style>
        :root {
            --fondo: #f4f8fd;
            --blanco: #ffffff;
            --marino: #0d2a5c;
            --tinta: #12233f;
            --tinta-suave: #4d5d78;
            --azul: #2b7bd3;
            --azul-suave: #e3effb;
            --lila: #7566d1;
            --lila-suave: #ebe8fa;
            --cielo: #4aa8e8;
            --cielo-suave: #e2f1fc;
            --linea: #d8e3f0;
            --ok: #1f9d5b;
            --ok-suave: #dcf4e6;
            --wa: #25D366;
            --display: 'Bricolage Grotesque', 'Helvetica Neue', Arial, sans-serif;
            --body: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body { font-family: var(--body); font-size: 17px; line-height: 1.55; background: var(--fondo); color: var(--tinta); -webkit-font-smoothing: antialiased; overflow-x: hidden; }
        img { max-width: 100%; display: block; }
        a { color: inherit; }
        h1, h2, h3 { font-family: var(--display); line-height: 1.08; text-wrap: balance; letter-spacing: -0.02em; color: var(--marino); }
        h1 { font-size: clamp(36px, 6vw, 68px); font-weight: 800; }
        h2 { font-size: clamp(28px, 4.4vw, 46px); font-weight: 800; }
        h3 { font-size: clamp(18px, 2vw, 22px); font-weight: 700; text-wrap: pretty; }
        p { max-width: 62ch; }
        .lead { font-size: clamp(18px, 2vw, 22px); line-height: 1.45; color: var(--tinta-suave); }
        .eyebrow { font-size: 12px; letter-spacing: .14em; text-transform: uppercase; font-weight: 600; color: var(--azul); margin-bottom: 14px; }
        .wrap { width: min(1120px, 100%); margin: 0 auto; padding-inline: 20px; }
        section { padding-block: clamp(56px, 9vh, 104px); }
        .num { font-variant-numeric: tabular-nums; }

        .reveal { opacity: 0; transform: translateY(24px); transition: opacity .7s cubic-bezier(.2,.7,.2,1), transform .7s cubic-bezier(.2,.7,.2,1); transition-delay: calc(var(--i, 0) * 100ms); }
        .reveal.in { opacity: 1; transform: none; }
        @media (prefers-reduced-motion: reduce) { .reveal { opacity: 1; transform: none; transition: none; } html { scroll-behavior: auto; } }

        nav { position: sticky; top: 0; z-index: 10; padding: calc(12px + env(safe-area-inset-top, 0px)) 20px 12px; display: flex; justify-content: space-between; align-items: center; gap: 12px; backdrop-filter: blur(12px); background: rgba(244, 248, 253, .85); border-bottom: 1px solid var(--linea); }
        .marca { font-family: var(--display); font-weight: 800; font-size: 22px; letter-spacing: -0.02em; text-decoration: none; color: var(--marino); }
        .marca small { font-family: var(--body); font-size: 12px; font-weight: 500; letter-spacing: 0; color: var(--tinta-suave); margin-left: 6px; white-space: nowrap; }
        .marca span { color: var(--azul); }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 10px; padding: 12px 20px; border-radius: 999px; font-weight: 600; text-decoration: none; font-size: 15px; transition: transform .2s, box-shadow .2s; white-space: nowrap; border: 0; cursor: pointer; font-family: inherit; }
        .btn:hover { transform: translateY(-2px); }
        .btn:focus-visible, .seg button:focus-visible, input:focus-visible { outline: 3px solid var(--cielo); outline-offset: 2px; }
        .btn-wa { background: var(--wa); color: #06281a; box-shadow: 0 8px 24px rgba(37, 211, 102, .25); }
        .btn-azul { background: var(--marino); color: #fff; box-shadow: 0 8px 24px rgba(13, 42, 92, .22); }
        .btn-linea { background: transparent; color: var(--marino); box-shadow: inset 0 0 0 2px var(--linea); }
        .btn svg { width: 20px; height: 20px; flex: 0 0 20px; }
        .btn-grande { font-size: 17px; padding: 15px 26px; }

        .hero { padding-block: clamp(40px, 7vh, 80px); background: radial-gradient(1200px 500px at 85% -10%, var(--cielo-suave), transparent 60%), radial-gradient(900px 500px at -10% 110%, var(--lila-suave), transparent 55%); }
        .dos { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: clamp(28px, 5vw, 64px); align-items: center; }
        .dos > * { min-width: 0; }
        .hero h1 span { color: var(--azul); }
        .hero .lead { margin-top: 18px; }
        .acciones { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 28px; }
        figure { border-radius: 22px; overflow: hidden; aspect-ratio: 16 / 10; background: linear-gradient(135deg, var(--azul-suave), var(--lila-suave)); box-shadow: 0 24px 60px rgba(13, 42, 92, .16); }
        figure img { width: 100%; height: 100%; object-fit: cover; }

        .pilares { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 18px; margin-top: 36px; }
        .pilar { background: var(--blanco); border-radius: 18px; padding: 26px; border: 1px solid var(--linea); min-width: 0; }
        .ico { width: 52px; height: 52px; border-radius: 50%; display: grid; place-items: center; margin-bottom: 16px; background: var(--azul-suave); color: var(--azul); }
        .ico svg { width: 26px; height: 26px; }
        .pilar:nth-child(2) .ico { background: var(--cielo-suave); color: var(--cielo); }
        .pilar:nth-child(3) .ico { background: var(--lila-suave); color: var(--lila); }
        .pilar p { color: var(--tinta-suave); font-size: 16px; margin-top: 6px; }

        .recibe { list-style: none; display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-top: 32px; }
        .recibe li { background: var(--blanco); border: 1px solid var(--linea); border-radius: 14px; padding: 16px; font-size: 15px; font-weight: 500; display: flex; gap: 10px; align-items: flex-start; min-width: 0; }
        .recibe li::before { content: ""; flex: 0 0 20px; height: 20px; margin-top: 1px; border-radius: 50%; background: var(--azul) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='3.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M5 12.5l4.5 4.5L19 7.5'/%3E%3C/svg%3E") center / 12px no-repeat; }

        .planes { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 18px; margin-top: 36px; align-items: stretch; }
        .plan { background: var(--blanco); border-radius: 20px; border: 1px solid var(--linea); overflow: hidden; display: flex; flex-direction: column; min-width: 0; --c: var(--azul); --cs: var(--azul-suave); }
        .plan.especifica { --c: var(--lila); --cs: var(--lila-suave); }
        .plan.esencial { --c: var(--cielo); --cs: var(--cielo-suave); }
        .plan header { background: var(--c); color: #fff; padding: 20px 22px; display: flex; justify-content: space-between; align-items: flex-end; gap: 12px; flex-wrap: wrap; }
        .plan header h3 { color: #fff; font-size: 21px; }
        .plan .precio { text-align: right; line-height: 1.1; }
        .plan .precio b { font-family: var(--display); font-size: 28px; font-weight: 800; display: block; }
        .plan .precio small { font-size: 12px; opacity: .9; }
        .plan ul { list-style: none; padding: 20px 22px 8px; display: grid; gap: 8px; font-size: 15px; flex: 1; align-content: start; }
        .plan li { display: flex; gap: 10px; }
        .plan li::before { content: "✓"; color: var(--c); font-weight: 700; }
        .plan .nota { margin: 12px 22px 22px; padding: 12px 14px; border-radius: 12px; background: var(--cs); font-size: 14px; font-weight: 500; color: var(--marino); }
        .plan .afil { margin: 0 22px 22px; font-size: 13px; font-weight: 600; padding: 6px 12px; border-radius: 999px; align-self: flex-start; background: var(--fondo); color: var(--tinta-suave); border: 1px solid var(--linea); }
        .plan .afil.ok { background: var(--ok-suave); color: var(--ok); border-color: transparent; }

        .anexo { background: var(--marino); color: #e8eef8; border-radius: 28px; padding: clamp(28px, 5vw, 56px); }
        .anexo h2, .anexo h3 { color: #fff; }
        .anexo .eyebrow { color: var(--cielo); }
        .anexo .lead { color: #b9c7de; }
        .opciones { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; margin-top: 28px; }
        .opcion { border-radius: 16px; padding: 22px; background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.12); min-width: 0; }
        .opcion.destacada { background: rgba(74,168,232,.16); border-color: var(--cielo); }
        .opcion .v { font-family: var(--display); font-weight: 800; font-size: clamp(30px, 4vw, 40px); line-height: 1; margin: 10px 0 4px; color: #fff; }
        .opcion .u { font-size: 14px; color: #b9c7de; }
        .opcion p { font-size: 15px; color: #b9c7de; margin-top: 12px; }
        .anexo .acciones .btn-linea { color: #fff; box-shadow: inset 0 0 0 2px rgba(255,255,255,.3); }

        .cot { background: var(--blanco); border-radius: 24px; border: 1px solid var(--linea); box-shadow: 0 24px 60px rgba(13, 42, 92, .10); margin-top: 32px; display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.05fr); overflow: hidden; }
        .cot > div { padding: clamp(22px, 3.5vw, 40px); min-width: 0; }
        .cot .salida { background: linear-gradient(160deg, var(--azul-suave), var(--lila-suave)); }
        .campo { margin-bottom: 26px; }
        .campo > label, .campo > .etq { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; font-weight: 600; font-size: 15px; margin-bottom: 10px; color: var(--marino); }
        .campo input[type=number] { width: 110px; font: inherit; font-weight: 700; font-family: var(--display); font-size: 22px; text-align: right; padding: 4px 10px; border: 1px solid var(--linea); border-radius: 10px; color: var(--azul); background: var(--fondo); font-variant-numeric: tabular-nums; }
        .campo input[type=range] { width: 100%; accent-color: var(--azul); }
        .campo .ayuda { font-size: 13px; color: var(--tinta-suave); margin-top: 6px; }
        .seg { display: grid; gap: 8px; grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .seg button { font: inherit; font-size: 14px; font-weight: 600; padding: 10px 8px; border-radius: 12px; border: 1px solid var(--linea); background: var(--fondo); color: var(--tinta-suave); cursor: pointer; line-height: 1.25; min-width: 0; }
        .seg button small { display: block; font-weight: 500; font-size: 12px; opacity: .85; }
        .seg button[aria-pressed=true] { background: var(--marino); color: #fff; border-color: var(--marino); }
        .seg button:disabled { opacity: .45; cursor: not-allowed; }
        details.sup { font-size: 14px; color: var(--tinta-suave); }
        details.sup summary { cursor: pointer; font-weight: 600; color: var(--azul); }
        details.sup .fila { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-top: 12px; }
        details.sup input { width: 130px; font: inherit; text-align: right; padding: 6px 10px; border: 1px solid var(--linea); border-radius: 8px; background: var(--fondo); color: var(--tinta); font-variant-numeric: tabular-nums; }
        .lineas { display: grid; gap: 10px; }
        .linea { display: flex; justify-content: space-between; gap: 16px; font-size: 15px; align-items: baseline; }
        .linea span:last-child { font-weight: 600; white-space: nowrap; }
        .total { margin-top: 14px; padding-top: 14px; border-top: 2px solid rgba(13,42,92,.15); display: flex; justify-content: space-between; align-items: baseline; gap: 12px; flex-wrap: wrap; }
        .total b { font-family: var(--display); font-size: clamp(32px, 4.4vw, 46px); font-weight: 800; color: var(--marino); line-height: 1; }
        .total small { display: block; font-size: 13px; color: var(--tinta-suave); font-weight: 500; }
        .ahorro { margin-top: 24px; background: var(--blanco); border-radius: 18px; padding: 20px; }
        .ahorro h3 { font-size: 17px; margin-bottom: 14px; }
        .ahorro .tres { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
        .ahorro .tres div { min-width: 0; }
        .ahorro .tres b { font-family: var(--display); font-size: clamp(22px, 2.8vw, 30px); font-weight: 800; color: var(--ok); display: block; line-height: 1.1; }
        .ahorro .tres span { font-size: 12.5px; color: var(--tinta-suave); line-height: 1.3; display: block; margin-top: 4px; }
        .ahorro .neto { margin-top: 16px; padding: 12px 14px; border-radius: 12px; background: var(--ok-suave); color: #0f5a33; font-size: 15px; font-weight: 500; }
        .ahorro .neto.neutro { background: var(--fondo); color: var(--tinta-suave); }
        .salida .btn { width: 100%; margin-top: 20px; }
        .pie-cot { font-size: 13px; color: var(--tinta-suave); margin-top: 14px; }

        .final { text-align: center; }
        .final h2 { max-width: 18ch; margin-inline: auto; }
        .final .lead { margin: 16px auto 28px; }
        .final .acciones { justify-content: center; }
        footer { padding: 26px 20px calc(26px + env(safe-area-inset-bottom, 0px)); text-align: center; font-size: 13px; color: var(--tinta-suave); border-top: 1px solid var(--linea); }

        @media (max-width: 900px) {
            .dos, .cot { grid-template-columns: 1fr; }
            .pilares, .planes, .opciones { grid-template-columns: 1fr; }
            .recibe { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .hero figure { order: -1; }
            .marca small { display: block; margin-left: 0; }
            .btn-nav span { display: none; }
            .btn-nav { padding: 10px 14px; }
        }
        @media (max-width: 420px) {
            .recibe { grid-template-columns: 1fr; }
            .ahorro .tres { grid-template-columns: 1fr; gap: 14px; }
            .seg { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

@php
    $iconoWa = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5.1-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.6.8-.8 1-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.8 11.9 11.9 0 0 0 4.6 4 5.3 5.3 0 0 0 3.2.7 2.7 2.7 0 0 0 1.8-1.3 2.2 2.2 0 0 0 .2-1.3c-.1-.1-.3-.2-.5-.3Z"/></svg>';
@endphp

<nav>
    <a class="marca" href="{{ route('publico.aliados.planes') }}">Brygar <small>con tecnología Bry<span>Nex</span></small></a>
    <a class="btn btn-wa btn-nav" href="{{ $urlWhatsapp }}" target="_blank" rel="noopener">{!! $iconoWa !!}<span>Hablemos</span></a>
</nav>

<section class="hero">
    <div class="wrap dos">
        <div>
            <div class="eyebrow reveal in">Propuesta de alianza estratégica</div>
            <h1 class="reveal in">Transformemos juntos la gestión de la <span>seguridad social.</span></h1>
            <p class="lead reveal in">Experiencia, tecnología propia y automatización para que su operación sea más simple, eficiente y segura. Más que un proveedor, un aliado.</p>
            <div class="acciones reveal in">
                <a class="btn btn-azul btn-grande" href="#cotizador">Cotizar mi alianza</a>
                <a class="btn btn-linea btn-grande" href="#planes">Ver los tres planes</a>
            </div>
        </div>
        <figure class="reveal in">
            <img src="/img/aliados/atencion.jpg" alt="Asesora de Brygar atendiendo a una pareja de clientes">
        </figure>
    </div>
</section>

<section>
    <div class="wrap">
        <div class="eyebrow reveal">¿Por qué Brygar?</div>
        <h2 class="reveal" style="--i:1">Tres cosas que juntas hacen la diferencia.</h2>
        <div class="pilares">
            <div class="pilar reveal" style="--i:1">
                <div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><circle cx="17.5" cy="9" r="2.5"/><path d="M17 14.2a5 5 0 0 1 4.5 5"/></svg></div>
                <h3>Conocimiento especializado</h3>
                <p>Un equipo experto en seguridad social que conoce cada entidad y cada trámite.</p>
            </div>
            <div class="pilar reveal" style="--i:2">
                <div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="5" width="16" height="11" rx="1.5"/><path d="M2 19h20"/></svg></div>
                <h3>Tecnología propia: BryNex</h3>
                <p>Centraliza la información de sus afiliados y facilita la gestión del día a día.</p>
            </div>
            <div class="pilar reveal" style="--i:3">
                <div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="6.2"/><circle cx="12" cy="12" r="2.4"/><path d="M12 2.6v3.2M12 18.200v3.200M2.600 12h3.200M18.200 12h3.200M5.350 5.350l2.250 2.250M16.400 16.400l2.250 2.250M5.350 18.650l2.250-2.250M16.400 7.600l2.250-2.250"/></svg></div>
                <h3>Automatización</h3>
                <p>Menos procesos manuales, menos errores y más productividad para su equipo.</p>
            </div>
        </div>

        <h2 class="reveal" style="margin-top:clamp(48px,8vh,88px)">Todo lo que necesita, en un solo lugar.</h2>
        <ul class="recibe">
            <li class="reveal" style="--i:1">Afiliaciones y novedades</li>
            <li class="reveal" style="--i:2">Salud, pensión, riesgos laborales y parafiscales</li>
            <li class="reveal" style="--i:3">Gestión documental</li>
            <li class="reveal" style="--i:4">Incapacidades y licencias</li>
            <li class="reveal" style="--i:1">Cambios de salario</li>
            <li class="reveal" style="--i:2">Traslados, inclusiones y reingresos</li>
            <li class="reveal" style="--i:3">Razones sociales</li>
            <li class="reveal" style="--i:4">Reportes y seguimiento</li>
        </ul>
    </div>
</section>

<section id="planes" style="background:var(--blanco)">
    <div class="wrap">
        <div class="eyebrow reveal">Nuestras alternativas</div>
        <h2 class="reveal" style="--i:1">Elija la que mejor se adapte a su operación.</h2>
        <div class="planes">
            <article class="plan reveal" style="--i:1">
                <header><h3>Alianza Integral</h3><div class="precio"><b class="num">$15.000</b><small>por afiliado al mes</small></div></header>
                <ul>
                    <li>Plataforma BryNex</li>
                    <li>Gestión de afiliaciones</li>
                    <li>Procesos de seguridad social</li>
                    <li>Automatización integral</li>
                    <li>Acompañamiento especializado</li>
                    <li>Reportes y seguimiento</li>
                    <li>Gestión de razones sociales</li>
                    <li>Gestión de incapacidades</li>
                </ul>
                <div class="nota">La solución más completa para su operación.</div>
                <span class="afil ok">Afiliaciones incluidas</span>
            </article>
            <article class="plan especifica reveal" style="--i:2">
                <header><h3>Alianza Específica</h3><div class="precio"><b class="num">$5.500</b><small>por afiliado al mes</small></div></header>
                <ul>
                    <li>Plataforma BryNex</li>
                    <li>Automatización</li>
                    <li>Gestión de razones sociales: las empresas las pone Brygar</li>
                </ul>
                <div class="nota">Ideal para optimizar procesos clave.</div>
                <span class="afil">Afiliaciones: opcional</span>
            </article>
            <article class="plan esencial reveal" style="--i:3">
                <header><h3>Alianza Esencial</h3><div class="precio"><b class="num">$800</b><small>por afiliado al mes</small></div></header>
                <ul>
                    <li>Acceso a la plataforma BryNex, con sus propias razones sociales</li>
                </ul>
                <div class="nota">Una puerta de entrada a la digitalización.</div>
                <span class="afil">Afiliaciones: opcional</span>
            </article>
        </div>
    </div>
</section>

<section>
    <div class="wrap">
        <div class="anexo reveal">
            <div class="eyebrow">Servicio de afiliaciones</div>
            <h2>¿Y quién afilia? Si quiere, nosotros.</h2>
            <p class="lead" style="margin-top:14px">Un complemento para las alianzas Esencial y Específica. BryNex entra a los portales de EPS, ARL y caja, y usted paga una sola vez por cada contrato nuevo, nunca por mes.</p>
            <div class="opciones">
                <div class="opcion">
                    <div class="eyebrow" style="margin:0">Su equipo afilia con BryNex</div>
                    <div class="v num">$3.000</div>
                    <div class="u">por contrato, una sola vez</div>
                    <p>Su gente marca «afiliar» y BryNex entra a los portales. Usted atiende lo que requiera una persona.</p>
                </div>
                <div class="opcion destacada">
                    <div class="eyebrow" style="margin:0">Nosotros afiliamos por usted</div>
                    <div class="v num">$6.000</div>
                    <div class="u">por contrato, una sola vez</div>
                    <p>EPS, ARL y caja, con la firma del trabajador. Lo que entre antes de las 4:00 pm queda radicado ese día. Desde el contrato 301 del mes, $5.000.</p>
                </div>
            </div>
            <div class="acciones">
                <a class="btn btn-linea" href="{{ route('publico.aliados.afiliaciones') }}">Ver cómo funciona el servicio</a>
            </div>
        </div>
    </div>
</section>

<section id="cotizador" style="padding-top:0">
    <div class="wrap">
        <div class="eyebrow reveal">Cotizador</div>
        <h2 class="reveal" style="--i:1">¿Cuánto vale y cuánto se ahorra?</h2>
        <p class="lead reveal" style="--i:2">Ponga sus números. El resultado es una referencia antes de IVA; la propuesta final la armamos con usted.</p>

        <div class="cot reveal">
            <div>
                <div class="campo">
                    <label for="q-afiliados">Afiliados activos <input type="number" id="q-afiliados" min="1" max="20000" step="10" value="500" inputmode="numeric"></label>
                    <input type="range" id="q-afiliados-r" min="20" max="3000" step="10" value="500" aria-label="Afiliados activos">
                </div>
                <div class="campo">
                    <label for="q-nuevos">Afiliaciones nuevas al mes <input type="number" id="q-nuevos" min="0" max="5000" step="5" value="80" inputmode="numeric"></label>
                    <input type="range" id="q-nuevos-r" min="0" max="800" step="5" value="80" aria-label="Afiliaciones nuevas al mes">
                    <div class="ayuda">Ingresos y reingresos que su oficina tramita en un mes.</div>
                </div>
                <div class="campo">
                    <div class="etq">Alianza</div>
                    <div class="seg" id="q-plan" role="group" aria-label="Alianza">
                        <button type="button" data-v="esencial" aria-pressed="true">Esencial<small>$800</small></button>
                        <button type="button" data-v="especifica" aria-pressed="false">Específica<small>$5.500</small></button>
                        <button type="button" data-v="integral" aria-pressed="false">Integral<small>$15.000</small></button>
                    </div>
                </div>
                <div class="campo">
                    <div class="etq">¿Quién hace las afiliaciones?</div>
                    <div class="seg" id="q-afil" role="group" aria-label="Quién hace las afiliaciones">
                        <button type="button" data-v="manual" aria-pressed="false">Mi equipo, a mano<small>como hoy</small></button>
                        <button type="button" data-v="equipo" aria-pressed="false">Mi equipo con BryNex<small>$3.000</small></button>
                        <button type="button" data-v="brygar" aria-pressed="true">Brygar<small>$6.000</small></button>
                    </div>
                    <div class="ayuda" id="q-afil-nota"></div>
                </div>
                <details class="sup">
                    <summary>Ajustar los supuestos del ahorro</summary>
                    <div class="fila"><label for="q-min">Minutos por afiliación a mano</label><input type="number" id="q-min" min="5" max="180" step="5" value="40" inputmode="numeric"></div>
                    <div class="fila"><label for="q-costo">Costo mensual de una persona</label><input type="number" id="q-costo" min="0" step="100000" value="2700000" inputmode="numeric"></div>
                    <p style="margin-top:10px">40 minutos es llenar el formato, entrar a EPS, ARL y caja, los correos y volver a revisar. $2.700.000 es un salario mínimo de 2026 con transporte y prestaciones. Una persona rinde unas 160 horas útiles al mes.</p>
                </details>
            </div>
            <div class="salida" aria-live="polite">
                <div class="lineas">
                    <div class="linea"><span id="o-plan-etq">Alianza Esencial</span><span class="num" id="o-plan">—</span></div>
                    <div class="linea"><span id="o-afil-etq">Afiliaciones</span><span class="num" id="o-afil">—</span></div>
                </div>
                <div class="total">
                    <div>Total del mes<small id="o-por">—</small></div>
                    <b class="num" id="o-total">—</b>
                </div>

                <div class="ahorro">
                    <h3>Lo que recupera su equipo</h3>
                    <div class="tres">
                        <div><b class="num" id="o-horas">—</b><span>horas al mes que dejan de irse en portales</span></div>
                        <div><b class="num" id="o-personas">—</b><span>personas de tiempo completo liberadas</span></div>
                        <div><b class="num" id="o-nomina">—</b><span>de nómina que hoy paga por ese trabajo</span></div>
                    </div>
                    <div class="neto" id="o-neto"></div>
                </div>

                <a class="btn btn-wa btn-grande" id="o-wa" href="{{ $urlWhatsapp }}" target="_blank" rel="noopener">{!! $iconoWa !!}Pedir esta cotización</a>
                <p class="pie-cot">Valores antes de IVA. El botón abre WhatsApp con estos números ya escritos; usted decide si lo envía.</p>
            </div>
        </div>
    </div>
</section>

<section class="final" style="background:var(--blanco)">
    <div class="wrap">
        <h2 class="reveal">Hablemos de cómo llevar esto a sus afiliados.</h2>
        <p class="lead reveal" style="--i:1">Empiece con un piloto de un mes y decida con sus propios números.</p>
        <div class="acciones reveal" style="--i:2">
            <a class="btn btn-wa btn-grande" href="{{ $urlWhatsapp }}" target="_blank" rel="noopener">{!! $iconoWa !!}Hablemos por WhatsApp</a>
            <a class="btn btn-linea btn-grande" href="{{ route('publico.aliados.afiliaciones') }}">Conocer el servicio de afiliaciones</a>
        </div>
    </div>
</section>

<footer>Brygar · Asesores en Seguridad Social · 300 156 3615 · comercialbrygar@gmail.com · brynex.co</footer>

<script>
(function () {
    var io = new IntersectionObserver(function (es) {
        es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
    }, { threshold: 0.12, rootMargin: '0px 0px -6% 0px' });
    document.querySelectorAll('.reveal:not(.in)').forEach(function (el) { io.observe(el); });

    // ── Cotizador ──
    var PLANES = { esencial: ['Alianza Esencial', 800], especifica: ['Alianza Específica', 5500], integral: ['Alianza Integral', 15000] };
    var TRAMO = 300, P_BRYGAR = 6000, P_BRYGAR_VOL = 5000, P_EQUIPO = 3000;
    // Con BryNex el equipo todavía atiende lo que requiere una persona (firmas, rechazos): se libera 3/4 del tiempo.
    var LIBERA = { manual: 0, equipo: 0.75, brygar: 1 };
    var HORAS_PERSONA = 160;
    var WA = @json($urlWhatsappBase);
    var $ = function (id) { return document.getElementById(id); };
    var cop = function (n) { return '$' + Math.round(n).toLocaleString('es-CO'); };
    var estado = { plan: 'esencial', afil: 'brygar' };

    function enlazar(numId, rangoId) {
        var n = $(numId), r = $(rangoId);
        n.addEventListener('input', function () { r.value = n.value; calcular(); });
        r.addEventListener('input', function () { n.value = r.value; calcular(); });
    }
    enlazar('q-afiliados', 'q-afiliados-r');
    enlazar('q-nuevos', 'q-nuevos-r');
    ['q-min', 'q-costo'].forEach(function (id) { $(id).addEventListener('input', calcular); });

    function segmento(id, clave) {
        $(id).addEventListener('click', function (e) {
            var b = e.target.closest('button');
            if (!b || b.disabled) return;
            estado[clave] = b.dataset.v;
            calcular();
        });
    }
    segmento('q-plan', 'plan');
    segmento('q-afil', 'afil');

    function pintarSegmento(id, valor, bloqueado) {
        $(id).querySelectorAll('button').forEach(function (b) {
            b.setAttribute('aria-pressed', b.dataset.v === valor ? 'true' : 'false');
            b.disabled = !!bloqueado && b.dataset.v !== valor;
        });
    }

    function calcular() {
        var afiliados = Math.max(+$('q-afiliados').value || 0, 0);
        var nuevos = Math.max(+$('q-nuevos').value || 0, 0);
        var min = Math.max(+$('q-min').value || 0, 0);
        var costo = Math.max(+$('q-costo').value || 0, 0);
        var integral = estado.plan === 'integral';
        var afil = integral ? 'brygar' : estado.afil;

        pintarSegmento('q-plan', estado.plan, false);
        pintarSegmento('q-afil', afil, integral);
        $('q-afil-nota').textContent = integral ? 'En la Alianza Integral las afiliaciones ya van incluidas y las hace Brygar.' : '';

        var plan = PLANES[estado.plan];
        var mensual = afiliados * plan[1];
        var vAfil = 0, etq = 'Afiliaciones';
        if (integral) { etq = 'Afiliaciones (' + nuevos + ')'; }
        else if (afil === 'equipo') { vAfil = nuevos * P_EQUIPO; etq = nuevos + ' afiliaciones × ' + cop(P_EQUIPO); }
        else if (afil === 'brygar') {
            vAfil = Math.min(nuevos, TRAMO) * P_BRYGAR + Math.max(nuevos - TRAMO, 0) * P_BRYGAR_VOL;
            etq = nuevos > TRAMO ? TRAMO + ' × ' + cop(P_BRYGAR) + ' + ' + (nuevos - TRAMO) + ' × ' + cop(P_BRYGAR_VOL) : nuevos + ' afiliaciones × ' + cop(P_BRYGAR);
        } else { etq = 'Afiliaciones a mano, con su equipo'; }
        var total = mensual + vAfil;

        $('o-plan-etq').textContent = plan[0] + ' · ' + afiliados.toLocaleString('es-CO') + ' × ' + cop(plan[1]);
        $('o-plan').textContent = cop(mensual);
        $('o-afil-etq').textContent = etq;
        $('o-afil').textContent = integral ? 'Incluidas' : (afil === 'manual' ? '$0' : cop(vAfil));
        $('o-total').textContent = cop(total);
        $('o-por').textContent = afiliados ? cop(total / afiliados) + ' por afiliado' : '';

        var horasManual = nuevos * min / 60;
        var horas = horasManual * LIBERA[afil];
        var personas = horas / HORAS_PERSONA;
        var nomina = personas * costo;
        $('o-horas').textContent = Math.round(horas) + ' h';
        $('o-personas').textContent = personas.toFixed(1).replace('.', ',');
        $('o-nomina').textContent = cop(nomina);

        var neto = $('o-neto');
        neto.className = 'neto';
        if (afil === 'manual') {
            neto.className = 'neto neutro';
            neto.textContent = 'Hoy su equipo gasta unas ' + Math.round(horasManual) + ' horas al mes afiliando a mano (' + cop(horasManual / HORAS_PERSONA * costo) + ' de nómina). Elija una opción de afiliaciones para ver cuánto recupera.';
        } else if (integral) {
            neto.textContent = 'Además de las afiliaciones, Brygar asume los procesos de seguridad social, las incapacidades y el acompañamiento.';
        } else if (nomina > vAfil) {
            neto.textContent = 'Ese trabajo le cuesta hoy ' + cop(nomina) + ' y con Brygar paga ' + cop(vAfil) + ': le quedan ' + cop(nomina - vAfil) + ' al mes y su equipo libre para atender y vender.';
        } else {
            neto.className = 'neto neutro';
            neto.textContent = 'Con este volumen el servicio vale ' + cop(vAfil) + ' al mes y libera ' + Math.round(horas) + ' horas de su equipo para atender y vender.';
        }

        var texto = 'Hola, quiero cotizar una alianza con Brygar.\n' +
            '• ' + plan[0] + ': ' + afiliados + ' afiliados\n' +
            '• Afiliaciones nuevas al mes: ' + nuevos + '\n' +
            '• Afiliaciones: ' + (integral ? 'incluidas' : afil === 'brygar' ? 'las hace Brygar' : afil === 'equipo' ? 'mi equipo con BryNex' : 'mi equipo a mano') + '\n' +
            '• Total estimado: ' + cop(total) + ' al mes, antes de IVA';
        $('o-wa').href = WA + '?text=' + encodeURIComponent(texto);
    }
    calcular();
})();
</script>
</body>
</html>
