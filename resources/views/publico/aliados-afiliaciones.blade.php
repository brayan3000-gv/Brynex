<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Afiliaciones para aliados — Brygar</title>
    <meta name="description" content="BryNex entra a los portales de EPS, ARL y caja por usted. Su equipo deja de copiar la misma cédula cuatro veces y vuelve a atender clientes.">
    <meta property="og:title" content="Afiliaciones para aliados — Brygar">
    <meta property="og:description" content="Lo que entre antes de las 4 de la tarde, radicado hoy. Su equipo, libre para atender y vender.">
    <meta property="og:image" content="{{ asset('img/aliados/carga.jpg') }}">
    <link rel="icon" href="{{ asset('img/logo-brynex.png') }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500;12..96,700;12..96,800&family=Inter:wght@400;500;600&display=swap">
    <style>
        :root {
            --noche: #07111f;
            --noche-2: #0d1a2e;
            --dia: #f6f8fc;
            --dia-2: #ffffff;
            --tinta: #0a1628;
            --tinta-suave: #47526b;
            --hueso: #e8edf6;
            --hueso-suave: #9fb0c8;
            --acento: #16a0ab;
            --acento-suave: rgba(22, 160, 171, .16);
            --ambar: #f2b451;
            --ok: #2fbf71;
            --wa: #25D366;
            --display: 'Bricolage Grotesque', 'Helvetica Neue', Arial, sans-serif;
            --body: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body {
            font-family: var(--body); font-size: 17px; line-height: 1.55;
            background: var(--noche); color: var(--hueso);
            -webkit-font-smoothing: antialiased; overflow-x: hidden;
            transition: background-color .9s ease, color .9s ease;
        }
        body.claro { background: var(--dia); color: var(--tinta); }
        img { max-width: 100%; display: block; }
        a { color: inherit; }
        h1, h2, h3 { font-family: var(--display); line-height: 1.05; text-wrap: balance; letter-spacing: -0.02em; }
        h1 { font-size: clamp(38px, 7vw, 84px); font-weight: 800; }
        h2 { font-size: clamp(30px, 5vw, 58px); font-weight: 800; }
        h3 { font-size: clamp(19px, 2.2vw, 24px); font-weight: 700; text-wrap: pretty; }
        p { max-width: 62ch; }
        .lead { font-size: clamp(18px, 2.2vw, 24px); line-height: 1.4; color: var(--hueso-suave); }
        .claro .lead { color: var(--tinta-suave); }
        .eyebrow { font-size: 12px; letter-spacing: .14em; text-transform: uppercase; font-weight: 600; color: var(--acento); margin-bottom: 16px; }
        .wrap { width: min(1120px, 100%); margin: 0 auto; padding-inline: 20px; }
        section { padding-block: clamp(72px, 12vh, 140px); position: relative; }
        .dos { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: clamp(28px, 5vw, 72px); align-items: center; }
        .dos > * { min-width: 0; }
        figure { position: relative; border-radius: 18px; overflow: hidden; background: linear-gradient(135deg, var(--noche-2), #143a52); aspect-ratio: 16 / 9; }
        figure.cuadrada { aspect-ratio: 1; }
        figure.vertical { aspect-ratio: 9 / 16; max-height: 70vh; }
        figure.cuatro-tres { aspect-ratio: 4 / 3; }
        figure img { width: 100%; height: 100%; object-fit: cover; }
        figure.sin-imagen img { display: none; }

        /* ── Animaciones al hacer scroll ── */
        .reveal { opacity: 0; transform: translateY(28px); transition: opacity .8s cubic-bezier(.2,.7,.2,1), transform .8s cubic-bezier(.2,.7,.2,1); transition-delay: calc(var(--i, 0) * 120ms); }
        .reveal.in { opacity: 1; transform: none; }
        .reveal.zoom { transform: scale(.96) translateY(16px); }
        .reveal.zoom.in { transform: none; }
        @media (prefers-reduced-motion: reduce) {
            .reveal { opacity: 1; transform: none; transition: none; }
            html { scroll-behavior: auto; }
            * { animation-duration: 0s !important; }
        }

        /* ── Barra ── */
        nav { position: fixed; top: 0; left: 0; right: 0; z-index: 10; padding: calc(12px + env(safe-area-inset-top, 0px)) 20px 12px; display: flex; justify-content: space-between; align-items: center; backdrop-filter: blur(12px); background: rgba(7, 17, 31, .55); transition: background .9s; }
        .claro nav { background: rgba(246, 248, 252, .75); }
        .marca { font-family: var(--display); font-weight: 800; font-size: 22px; letter-spacing: -0.02em; text-decoration: none; }
        .marca span { color: var(--acento); }
        .marca small { font-family: var(--body); font-size: 12px; font-weight: 500; letter-spacing: 0; opacity: .75; margin-left: 6px; white-space: nowrap; }
        .btn { display: inline-flex; align-items: center; gap: 10px; padding: 12px 20px; border-radius: 999px; font-weight: 600; text-decoration: none; font-size: 15px; transition: transform .2s, box-shadow .2s; white-space: nowrap; }
        .btn:hover { transform: translateY(-2px); }
        .btn-wa { background: var(--wa); color: #06281a; box-shadow: 0 8px 24px rgba(37, 211, 102, .25); }
        .btn-wa svg { width: 20px; height: 20px; }
        .btn-grande { font-size: 18px; padding: 16px 28px; }

        /* ── Hero ── */
        .hero { min-height: 100svh; display: grid; align-items: end; padding-block: 120px 96px; overflow: hidden; }
        .hero .fondo { position: absolute; inset: 0; z-index: 0; }
        .hero .fondo img { width: 100%; height: 100%; object-fit: cover; object-position: 65% 40%; transform: scale(1.08); animation: respirar 14s ease-in-out infinite alternate; }
        @keyframes respirar { to { transform: scale(1) translateX(-1%); } }
        .hero .fondo::after { content: ""; position: absolute; inset: 0; background: linear-gradient(180deg, rgba(7,17,31,.35) 0%, rgba(7,17,31,.15) 35%, rgba(7,17,31,.92) 75%, var(--noche) 100%); }
        .hero .wrap { position: relative; z-index: 1; }
        .hero h1 { max-width: 14ch; margin-bottom: 20px; text-shadow: 0 2px 30px rgba(0,0,0,.4); }
        .hero .lead { color: var(--hueso); max-width: 48ch; }
        .hero .hora { font-family: var(--display); font-weight: 700; font-size: clamp(14px, 1.6vw, 18px); letter-spacing: .08em; color: var(--ambar); display: inline-flex; align-items: center; gap: 10px; margin-bottom: 18px; }
        .hero .hora i { width: 10px; height: 10px; border-radius: 50%; background: var(--ambar); animation: latir 1.6s ease-in-out infinite; }
        @keyframes latir { 50% { transform: scale(1.6); opacity: .5; } }
        .bajar { position: absolute; left: 50%; bottom: 18px; transform: translateX(-50%); color: var(--hueso-suave); font-size: 12px; letter-spacing: .12em; text-transform: uppercase; display: flex; flex-direction: column; align-items: center; gap: 6px; z-index: 1; }
        .bajar::after { content: ""; width: 1px; height: 36px; background: linear-gradient(var(--hueso-suave), transparent); animation: caer 1.8s ease-in-out infinite; }
        @keyframes caer { 0% { transform: scaleY(0); transform-origin: top; } 50% { transform: scaleY(1); transform-origin: top; } 51% { transform-origin: bottom; } 100% { transform: scaleY(0); transform-origin: bottom; } }

        /* ── Tareas a mano ── */
        .tareas { list-style: none; display: grid; gap: 10px; margin-top: 28px; }
        .tareas li { display: grid; grid-template-columns: 1fr auto; gap: 14px; align-items: center; padding: 14px 18px; border-radius: 12px; background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.08); }
        .tareas .min { font-family: var(--display); font-weight: 700; color: var(--ambar); font-variant-numeric: tabular-nums; }
        .total { margin-top: 22px; display: flex; align-items: baseline; gap: 14px; flex-wrap: wrap; }
        .total .n { font-family: var(--display); font-weight: 800; font-size: clamp(48px, 7vw, 88px); line-height: 1; color: var(--ambar); font-variant-numeric: tabular-nums; }
        .total .t { color: var(--hueso-suave); }
        .cifra { margin-top: 56px; padding: 28px 32px; border-radius: 18px; background: linear-gradient(135deg, rgba(242,180,81,.14), rgba(22,160,171,.10)); border: 1px solid rgba(255,255,255,.08); }
        .cifra strong { font-family: var(--display); font-size: clamp(22px, 3vw, 34px); font-weight: 700; display: block; margin-bottom: 8px; line-height: 1.2; }

        /* ── Chat ── */
        .chat { display: grid; gap: 10px; max-width: 420px; }
        .burbuja { padding: 12px 16px; border-radius: 16px 16px 16px 4px; background: #1f2d44; color: var(--hueso); font-size: 16px; width: fit-content; max-width: 100%; opacity: 0; transform: translateY(10px) scale(.98); transition: opacity .5s, transform .5s; }
        .burbuja.in { opacity: 1; transform: none; }
        .burbuja small { display: block; font-size: 11px; color: var(--hueso-suave); margin-top: 4px; text-align: right; }
        .burbuja.dia { align-self: center; justify-self: center; background: transparent; color: var(--hueso-suave); font-size: 12px; letter-spacing: .08em; text-transform: uppercase; padding: 4px 0; }
        .escribiendo { display: inline-flex; gap: 4px; padding: 12px 16px; border-radius: 16px 16px 16px 4px; background: #1f2d44; width: fit-content; }
        .escribiendo i { width: 7px; height: 7px; border-radius: 50%; background: var(--hueso-suave); animation: punto 1.2s infinite; }
        .escribiendo i:nth-child(2) { animation-delay: .2s; } .escribiendo i:nth-child(3) { animation-delay: .4s; }
        @keyframes punto { 0%, 60%, 100% { opacity: .3; } 30% { opacity: 1; } }
        .frase { font-family: var(--display); font-weight: 700; font-size: clamp(24px, 3.6vw, 44px); line-height: 1.15; margin-top: 40px; max-width: 20ch; }
        .frase em { font-style: normal; color: var(--ambar); }

        /* ── Giro ── */
        .giro { background: var(--dia); color: var(--tinta); }
        .giro::before { content: ""; position: absolute; left: 0; right: 0; top: -1px; height: 140px; background: linear-gradient(var(--noche), var(--dia)); pointer-events: none; }
        .giro .wrap { position: relative; }
        .claro-sec { background: var(--dia); color: var(--tinta); }
        .claro-sec .eyebrow { color: var(--acento); }
        .claro-sec .lead { color: var(--tinta-suave); }
        .claro-sec figure { background: linear-gradient(135deg, #dbe7f3, #c6dde8); }

        .pasos { list-style: none; display: grid; gap: 0; margin-top: 36px; position: relative; counter-reset: paso; }
        .pasos li { display: grid; grid-template-columns: 56px 1fr; gap: 18px; padding-block: 18px; position: relative; }
        .pasos li::before { counter-increment: paso; content: counter(paso); width: 44px; height: 44px; border-radius: 50%; display: grid; place-items: center; font-family: var(--display); font-weight: 800; font-size: 18px; background: var(--acento); color: #fff; box-shadow: 0 0 0 6px var(--acento-suave); }
        .pasos li + li::after { content: ""; position: absolute; left: 21px; top: -18px; height: 36px; width: 2px; background: var(--acento-suave); }
        .pasos p { color: var(--tinta-suave); font-size: 16px; margin-top: 4px; }

        /* ── Reloj ── */
        .reloj { width: min(320px, 70vw); aspect-ratio: 1; margin-inline: auto; }
        .reloj svg { width: 100%; height: 100%; }
        .reloj .manecilla { transform-origin: 100px 100px; transition: transform 2.4s cubic-bezier(.3,.9,.2,1); }
        .reloj .horas { transform: rotate(-30deg); }
        .reloj .minutos { transform: rotate(-120deg); }
        .reloj.in .horas { transform: rotate(120deg); }
        .reloj.in .minutos { transform: rotate(720deg); }
        .garantia { margin-top: 28px; padding: 20px 24px; border-left: 4px solid var(--ok); background: var(--dia-2); border-radius: 0 14px 14px 0; box-shadow: 0 10px 30px rgba(10,22,40,.06); }
        .garantia strong { color: var(--ok); }

        /* ── Beneficios ── */
        .tarjetas { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 18px; margin-top: 40px; }
        .tarjeta { background: var(--dia-2); border-radius: 16px; padding: 26px; box-shadow: 0 10px 30px rgba(10,22,40,.06); min-width: 0; }
        .tarjeta .ico { width: 44px; height: 44px; border-radius: 12px; background: var(--acento-suave); color: var(--acento); display: grid; place-items: center; margin-bottom: 16px; }
        .tarjeta .ico svg { width: 24px; height: 24px; }
        .tarjeta p { color: var(--tinta-suave); font-size: 16px; margin-top: 8px; }

        /* ── Calculadora ── */
        .calc { background: var(--dia-2); border-radius: 20px; padding: clamp(22px, 4vw, 40px); box-shadow: 0 16px 40px rgba(10,22,40,.08); margin-top: 36px; }
        .calc label { display: flex; justify-content: space-between; font-weight: 600; margin-bottom: 10px; }
        .calc label output { font-family: var(--display); font-size: 28px; color: var(--acento); font-variant-numeric: tabular-nums; }
        .calc input[type=range] { width: 100%; accent-color: var(--acento); }
        .res { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; margin-top: 28px; }
        .res div { min-width: 0; border-top: 2px solid var(--acento-suave); padding-top: 12px; }
        .res b { font-family: var(--display); font-size: clamp(28px, 4vw, 44px); font-weight: 800; display: block; line-height: 1; font-variant-numeric: tabular-nums; }
        .res span { font-size: 14px; color: var(--tinta-suave); }

        /* ── Precios ── */
        .precios { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; margin-top: 36px; }
        .precio { border-radius: 16px; padding: 24px; background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.1); min-width: 0; }
        .precio.destacado { border-color: var(--acento); background: var(--acento-suave); }
        .precio .v { font-family: var(--display); font-weight: 800; font-size: clamp(32px, 4vw, 44px); line-height: 1; margin: 10px 0 6px; font-variant-numeric: tabular-nums; }
        .precio .u { color: var(--hueso-suave); font-size: 14px; }
        .precio p { color: var(--hueso-suave); font-size: 15px; margin-top: 12px; }
        .precios.dos-col { grid-template-columns: repeat(2, minmax(0, 1fr)); margin-top: 16px; }
        .precios + .sub, h3.sub { margin-top: 44px; color: var(--hueso); }
        h3.sub + .precios { margin-top: 16px; }
        .chip { display: inline-block; margin-top: 14px; font-size: 12px; font-weight: 600; padding: 4px 10px; border-radius: 999px; background: rgba(255,255,255,.08); color: var(--hueso-suave); }
        .chip.ok { background: rgba(47,191,113,.16); color: var(--ok); }
        .final { text-align: center; padding-block: clamp(80px, 14vh, 160px); }
        .final h2 { max-width: 16ch; margin-inline: auto; }
        .final .lead { margin: 20px auto 36px; }
        footer { padding: 28px 20px calc(28px + env(safe-area-inset-bottom, 0px)); text-align: center; font-size: 13px; color: var(--hueso-suave); border-top: 1px solid rgba(255,255,255,.08); }

        @media (max-width: 820px) {
            .dos { grid-template-columns: 1fr; }
            .dos.invertir figure { order: -1; }
            figure.vertical { max-height: 60vh; width: min(100%, 320px); margin-inline: auto; }
            .tarjetas, .precios, .precios.dos-col, .res { grid-template-columns: 1fr; }
            .marca small { display: block; margin-left: 0; }
            .res { gap: 20px; }
            .hero { padding-top: 96px; }
            .hero .fondo img { object-position: 60% 30%; }
            .btn-nav span { display: none; }
            .btn-nav { padding: 10px 14px; }
        }
    </style>
</head>
<body>

<nav>
    <a class="marca" href="{{ url('/') }}">Brygar <small>con tecnología Bry<span>Nex</span></small></a>
    <a class="btn btn-wa btn-nav" href="{{ $urlWhatsapp }}" target="_blank" rel="noopener">
        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5.1-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.6.8-.8 1-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.8 11.9 11.9 0 0 0 4.6 4 5.3 5.3 0 0 0 3.2.7 2.7 2.7 0 0 0 1.8-1.3 2.2 2.2 0 0 0 .2-1.3c-.1-.1-.3-.2-.5-.3Z"/></svg>
        <span>Hablemos</span>
    </a>
</nav>

{{-- 1 · La noche --}}
<section class="hero">
    <div class="fondo">
        <img src="/img/aliados/carga.jpg" alt="" onerror="this.style.display='none'">
    </div>
    <div class="wrap">
        <div class="hora reveal"><i></i> 7:40 PM · MARTES</div>
        <h1 class="reveal" style="--i:1">Son las siete de la noche y su equipo sigue afiliando.</h1>
        <p class="lead reveal" style="--i:2">La misma cédula en cuatro portales. El correo que todavía no responden. El cliente que preguntó a las tres y sigue esperando.</p>
    </div>
    <div class="bajar">Siga bajando</div>
</section>

{{-- 2 · A mano --}}
<section>
    <div class="wrap dos invertir">
        <div>
            <div class="eyebrow reveal">Así se hace hoy</div>
            <h2 class="reveal" style="--i:1">Cada afiliación, a mano. Una por una.</h2>
            <ul class="tareas" id="tareas">
                <li class="reveal" style="--i:2"><span>Llenar el formato con los datos del trabajador</span><span class="min" data-min="5">5 min</span></li>
                <li class="reveal" style="--i:3"><span>Entrar al portal de la EPS y volver a escribir todo</span><span class="min" data-min="8">8 min</span></li>
                <li class="reveal" style="--i:4"><span>Entrar al de la ARL. Otra vez todo.</span><span class="min" data-min="6">6 min</span></li>
                <li class="reveal" style="--i:5"><span>La caja de compensación. Otra vez.</span><span class="min" data-min="6">6 min</span></li>
                <li class="reveal" style="--i:6"><span>Escribir el correo a la entidad que no tiene portal</span><span class="min" data-min="4">4 min</span></li>
                <li class="reveal" style="--i:7"><span>Volver a entrar al otro día a ver si quedó</span><span class="min" data-min="6">6 min</span></li>
                <li class="reveal" style="--i:8"><span>Responder al cliente que escribió «¿ya quedé?»</span><span class="min" data-min="5">5 min</span></li>
            </ul>
            <div class="total reveal" style="--i:9"><span class="n" id="total-min" data-hasta="40">0</span><span class="t">minutos por cada persona. Si todo sale bien.</span></div>
        </div>
        <figure class="reveal zoom">
            <img src="/img/aliados/pestanas.jpg" alt="Pantalla llena de pestañas de portales" loading="lazy" onerror="this.closest('figure').classList.add('sin-imagen')">
        </figure>
    </div>
    <div class="wrap">
        <div class="cifra reveal">
            <strong><span class="cont" data-hasta="200">0</span> afiliaciones al mes son <span class="cont" data-hasta="133">0</span> horas.</strong>
            <p class="lead">Casi una persona de tiempo completo dedicada a copiar la misma cédula en cuatro portales. Una persona que no está contestando el teléfono.</p>
        </div>
    </div>
</section>

{{-- 3 · El cliente espera --}}
<section>
    <div class="wrap dos">
        <div>
            <div class="eyebrow reveal">Mientras tanto</div>
            <h2 class="reveal" style="--i:1">El cliente también está esperando.</h2>
            <div class="chat" id="chat" style="margin-top:28px">
                <div class="burbuja dia">Martes</div>
                <div class="burbuja">Buenas, ¿ya quedé afiliado? Mañana empiezo en la obra. <small>3:10 PM</small></div>
                <div class="burbuja">¿Hola? <small>5:42 PM</small></div>
                <div class="burbuja dia">Miércoles</div>
                <div class="burbuja">Me dijeron que sin la ARL no me dejan entrar. Voy a preguntar en otra parte. <small>7:55 AM</small></div>
            </div>
            <p class="frase reveal" style="--i:2">Los clientes no se van por el precio. <em>Se van porque nadie les contestó a tiempo.</em></p>
        </div>
        <figure class="vertical reveal zoom">
            <img src="/img/aliados/espera.jpg" alt="Trabajador esperando respuesta en su celular" loading="lazy" onerror="this.closest('figure').classList.add('sin-imagen')">
        </figure>
    </div>
</section>

{{-- 4 · El giro --}}
<section class="giro claro-sec" data-claro>
    <div class="wrap dos">
        <div>
            <div class="eyebrow reveal">Lo nuevo para aliados</div>
            <h2 class="reveal" style="--i:1">Ahora BryNex entra a los portales por usted.</h2>
            <p class="lead reveal" style="--i:2">Usted registra la persona una sola vez. De ahí en adelante, BryNex hace el trámite en cada entidad, con sus razones sociales o con las de Brygar, y le deja el comprobante.</p>
            <ol class="pasos">
                <li class="reveal" style="--i:3"><div><h3>Registra la persona en BryNex</h3><p>Datos del contrato y documentos. Lo mismo que hoy captura, pero una sola vez.</p></div></li>
                <li class="reveal" style="--i:4"><div><h3>BryNex entra a la EPS, la ARL y la caja</h3><p>Con las razones sociales con las que usted afilia, propias o de Brygar. Lo que va por correo, BryNex lo envía y le hace seguimiento.</p></div></li>
                <li class="reveal" style="--i:5"><div><h3>Si hace falta firma, nosotros hablamos con el trabajador</h3><p>Le escribimos, le explicamos y conseguimos la firma. Su equipo no tiene que perseguir a nadie.</p></div></li>
                <li class="reveal" style="--i:6"><div><h3>El comprobante queda en el radicado</h3><p>Fecha, hora y soporte de cada entidad. Cuando confirman, el radicado pasa a OK solo.</p></div></li>
            </ol>
        </div>
        <figure class="reveal zoom">
            <img src="/img/aliados/entra.jpg" alt="Los datos registrados una vez llegan a cada entidad" loading="lazy" onerror="this.closest('figure').classList.add('sin-imagen')">
        </figure>
    </div>
</section>

{{-- 5 · Las 4 de la tarde --}}
<section class="claro-sec" data-claro>
    <div class="wrap dos invertir">
        <div>
            <div class="eyebrow reveal">El compromiso</div>
            <h2 class="reveal" style="--i:1">Antes de las cuatro de la tarde, radicada hoy.</h2>
            <p class="lead reveal" style="--i:2">Lo que entre completo a BryNex antes de las 4:00 pm de un día hábil queda radicado ese mismo día. Lo que llegue después, al siguiente día hábil. Sin que nadie de su oficina tenga que entrar a un portal.</p>
            <div class="garantia reveal" style="--i:3">
                <strong>Garantía.</strong> Si una afiliación de portal entra completa antes de las 4:00 pm y BryNex no la radica ese día, ese contrato no se cobra.
            </div>
        </div>
        <div class="reloj reveal" id="reloj">
            <svg viewBox="0 0 200 200" aria-label="Reloj marcando las cuatro de la tarde">
                <circle cx="100" cy="100" r="94" fill="#fff" stroke="#dbe3ef" stroke-width="2"/>
                <g stroke="#9fb0c8" stroke-width="3" stroke-linecap="round">
                    <line x1="100" y1="14" x2="100" y2="26"/><line x1="186" y1="100" x2="174" y2="100"/>
                    <line x1="100" y1="186" x2="100" y2="174"/><line x1="14" y1="100" x2="26" y2="100"/>
                </g>
                <g stroke="#dbe3ef" stroke-width="2" stroke-linecap="round">
                    <line x1="143" y1="25.5" x2="139" y2="32.4"/><line x1="174.5" y1="57" x2="167.6" y2="61"/>
                    <line x1="174.5" y1="143" x2="167.6" y2="139"/><line x1="143" y1="174.5" x2="139" y2="167.6"/>
                    <line x1="57" y1="174.5" x2="61" y2="167.6"/><line x1="25.5" y1="143" x2="32.4" y2="139"/>
                    <line x1="25.5" y1="57" x2="32.4" y2="61"/><line x1="57" y1="25.5" x2="61" y2="32.4"/>
                </g>
                <line class="manecilla horas" x1="100" y1="100" x2="100" y2="48" stroke="#0a1628" stroke-width="7" stroke-linecap="round"/>
                <line class="manecilla minutos" x1="100" y1="100" x2="100" y2="30" stroke="#16a0ab" stroke-width="5" stroke-linecap="round"/>
                <circle cx="100" cy="100" r="6" fill="#0a1628"/>
            </svg>
        </div>
    </div>
</section>

{{-- 6 · El equipo libre --}}
<section class="claro-sec" data-claro>
    <div class="wrap">
        <div class="dos">
            <div>
                <div class="eyebrow reveal">Lo que cambia en su oficina</div>
                <h2 class="reveal" style="--i:1">Su equipo, libre para lo que sí trae clientes.</h2>
                <p class="lead reveal" style="--i:2">Las horas que hoy se van en portales vuelven a donde está la plata: contestar rápido, vender y cuidar la cartera.</p>
            </div>
            <figure class="reveal zoom">
                <img src="/img/aliados/atencion.jpg" alt="Equipo atendiendo clientes con calma" loading="lazy" onerror="this.closest('figure').classList.add('sin-imagen')">
            </figure>
        </div>
        <div class="tarjetas">
            <div class="tarjeta reveal" style="--i:1">
                <div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.6 8.6 0 0 1-3.8-.9L3 21l2-5.2A8.4 8.4 0 0 1 3 11.5a8.4 8.4 0 0 1 9-8.4 8.4 8.4 0 0 1 9 8.4Z"/></svg></div>
                <h3>Contestar en minutos</h3>
                <p>El «¿ya quedé?» se responde con el comprobante en la mano, no con un «déjeme revisar».</p>
            </div>
            <div class="tarjeta reveal" style="--i:2">
                <div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3-8.7A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.4c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2Z"/></svg></div>
                <h3>Devolver cada llamada</h3>
                <p>Las llamadas que hoy no alcanzan a devolver son clientes que se fueron a otra oficina.</p>
            </div>
            <div class="tarjeta reveal" style="--i:3">
                <div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></div>
                <h3>Cobrar a tiempo</h3>
                <p>Tiempo para la cartera, para el cuadre y para los clientes que pagan tarde porque nadie les recordó.</p>
            </div>
        </div>
    </div>
</section>

{{-- 7 · Crecer --}}
<section class="claro-sec" data-claro>
    <div class="wrap">
        <div class="dos invertir">
            <div>
                <div class="eyebrow reveal">Haga la cuenta</div>
                <h2 class="reveal" style="--i:1">Más clientes con el mismo equipo.</h2>
                <p class="lead reveal" style="--i:2">Mueva el número a las afiliaciones que hace su oficina al mes y vea cuánto tiempo recupera.</p>
            </div>
            <figure class="reveal zoom">
                <img src="/img/aliados/crecer.jpg" alt="Oficina recibiendo más clientes" loading="lazy" onerror="this.closest('figure').classList.add('sin-imagen')">
            </figure>
        </div>
        <div class="calc reveal">
            <label for="afil">Afiliaciones al mes <output id="afil-out">200</output></label>
            <input type="range" id="afil" min="20" max="600" step="10" value="200">
            <div class="res">
                <div><b id="r-horas">133 h</b><span>al mes que vuelven a su equipo</span></div>
                <div><b id="r-personas">0,8</b><span>personas de tiempo completo liberadas</span></div>
                <div><b id="r-dias">17</b><span>días hábiles de trabajo recuperados cada mes</span></div>
            </div>
        </div>
    </div>
</section>

{{-- 8 · Precio y cierre --}}
<section data-oscuro>
    <div class="wrap">
        <div class="eyebrow reveal">Sin letra pequeña</div>
        <h2 class="reveal" style="--i:1">Lo que cuesta.</h2>
        <p class="lead reveal" style="--i:2">Su plan mensual no cambia. La afiliación es un complemento que se paga una sola vez por contrato, nunca por mes.</p>

        <h3 class="sub reveal">Su alianza con Brygar</h3>
        <div class="precios">
            <div class="precio reveal" style="--i:1">
                <div class="eyebrow" style="margin:0">Alianza Esencial</div>
                <div class="v">$800</div>
                <div class="u">por afiliado al mes</div>
                <p>Acceso a la plataforma BryNex, con sus propias razones sociales.</p>
                <span class="chip">Afiliaciones: opcional</span>
            </div>
            <div class="precio reveal" style="--i:2">
                <div class="eyebrow" style="margin:0">Alianza Específica</div>
                <div class="v">$5.500</div>
                <div class="u">por afiliado al mes</div>
                <p>Plataforma, automatización y las razones sociales de Brygar.</p>
                <span class="chip">Afiliaciones: opcional</span>
            </div>
            <div class="precio reveal" style="--i:3">
                <div class="eyebrow" style="margin:0">Alianza Integral</div>
                <div class="v">$15.000</div>
                <div class="u">por afiliado al mes</div>
                <p>Toda la operación gestionada por Brygar: afiliaciones, procesos, incapacidades y acompañamiento.</p>
                <span class="chip ok">Afiliaciones: incluidas</span>
            </div>
        </div>

        <h3 class="sub reveal">Afiliaciones, para Esencial y Específica</h3>
        <div class="precios dos-col">
            <div class="precio reveal" style="--i:1">
                <div class="eyebrow" style="margin:0">Su equipo afilia con BryNex</div>
                <div class="v">$3.000</div>
                <div class="u">por contrato, una sola vez</div>
                <p>Su gente marca «afiliar» y BryNex entra a los portales. Usted atiende lo que requiera una persona.</p>
            </div>
            <div class="precio destacado reveal" style="--i:2">
                <div class="eyebrow" style="margin:0">Nosotros afiliamos por usted</div>
                <div class="v">$6.000</div>
                <div class="u">por contrato, una sola vez</div>
                <p>EPS, ARL y caja. Firma con el trabajador. Radicado el mismo día antes de las 4 pm. Desde el contrato 301 del mes, $5.000.</p>
            </div>
        </div>
        <p style="margin-top:18px;font-size:14px;color:var(--hueso-suave)">Valores antes de IVA. Empiece con un piloto de un mes, con una razón social, sin permanencia.</p>
    </div>
</section>

<section class="final" data-oscuro>
    <div class="wrap">
        <h2 class="reveal">Que su equipo vuelva a atender gente, no portales.</h2>
        <p class="lead reveal" style="--i:1">Escríbanos y en una semana tiene el piloto andando con sus propias afiliaciones.</p>
        <a class="btn btn-wa btn-grande reveal" style="--i:2" href="{{ $urlWhatsapp }}" target="_blank" rel="noopener">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5.1-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.6.8-.8 1-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.8 11.9 11.9 0 0 0 4.6 4 5.3 5.3 0 0 0 3.2.7 2.7 2.7 0 0 0 1.8-1.3 2.2 2.2 0 0 0 .2-1.3c-.1-.1-.3-.2-.5-.3Z"/></svg>
            Hablemos por WhatsApp
        </a>
    </div>
</section>

<footer>Brygar · Asesores en Seguridad Social · 300 156 3615 · comercialbrygar@gmail.com · brynex.co</footer>

<script>
(function () {
    var reducido = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Aparición al hacer scroll
    var io = new IntersectionObserver(function (entradas) {
        entradas.forEach(function (e) {
            if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); }
        });
    }, { threshold: 0.15, rootMargin: '0px 0px -8% 0px' });
    document.querySelectorAll('.reveal').forEach(function (el) { io.observe(el); });
    // El hero no espera al observador: aparece apenas carga la página.
    document.querySelectorAll('.hero .reveal').forEach(function (el) { el.classList.add('in'); });

    // Fondo claro u oscuro según la sección que domina la pantalla
    var ioTema = new IntersectionObserver(function (entradas) {
        entradas.forEach(function (e) {
            if (e.isIntersecting) document.body.classList.toggle('claro', e.target.hasAttribute('data-claro'));
        });
    }, { threshold: 0.4 });
    document.querySelectorAll('section').forEach(function (s) { ioTema.observe(s); });

    // Contadores
    function contar(el, hasta, ms) {
        if (reducido) { el.textContent = hasta; return; }
        var inicio = null;
        function paso(t) {
            if (!inicio) inicio = t;
            var p = Math.min((t - inicio) / ms, 1);
            el.textContent = Math.round(hasta * (1 - Math.pow(1 - p, 3)));
            if (p < 1) requestAnimationFrame(paso);
        }
        requestAnimationFrame(paso);
    }
    var ioCont = new IntersectionObserver(function (entradas) {
        entradas.forEach(function (e) {
            if (!e.isIntersecting) return;
            contar(e.target, +e.target.dataset.hasta, e.target.id === 'total-min' ? 2200 : 1600);
            ioCont.unobserve(e.target);
        });
    }, { threshold: 0.6 });
    document.querySelectorAll('.cont, #total-min').forEach(function (el) { ioCont.observe(el); });

    // Chat que va llegando
    var chat = document.getElementById('chat');
    var ioChat = new IntersectionObserver(function (entradas) {
        if (!entradas[0].isIntersecting) return;
        ioChat.disconnect();
        var burbujas = chat.querySelectorAll('.burbuja');
        var t = 0;
        burbujas.forEach(function (b, i) {
            var esDia = b.classList.contains('dia');
            t += esDia ? 300 : 1300;
            if (!esDia && !reducido) {
                var esc = document.createElement('div');
                esc.className = 'escribiendo'; esc.innerHTML = '<i></i><i></i><i></i>';
                setTimeout(function () { chat.insertBefore(esc, b); }, t - 1100);
                setTimeout(function () { esc.remove(); }, t);
            }
            setTimeout(function () { b.classList.add('in'); }, reducido ? 0 : t);
        });
    }, { threshold: 0.5 });
    ioChat.observe(chat);

    // Reloj
    var reloj = document.getElementById('reloj');
    var ioReloj = new IntersectionObserver(function (entradas) {
        if (entradas[0].isIntersecting) { setTimeout(function () { reloj.classList.add('in'); }, 300); ioReloj.disconnect(); }
    }, { threshold: 0.5 });
    ioReloj.observe(reloj);

    // Calculadora: 40 minutos por afiliación, 160 horas útiles por persona, 8 horas por día
    var afil = document.getElementById('afil');
    function calcular() {
        var n = +afil.value, horas = n * 40 / 60;
        document.getElementById('afil-out').textContent = n;
        document.getElementById('r-horas').textContent = Math.round(horas) + ' h';
        document.getElementById('r-personas').textContent = (horas / 160).toFixed(1).replace('.', ',');
        document.getElementById('r-dias').textContent = Math.round(horas / 8);
    }
    afil.addEventListener('input', calcular);
    calcular();
})();
</script>
</body>
</html>
