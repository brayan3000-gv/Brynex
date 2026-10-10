@extends('layouts.public')

@section('titulo', $config->seo_titulo ?: ($aliado->nombre . ' — Afiliación a seguridad social'))
@section('descripcion', $config->seo_descripcion ?: 'Afíliate a EPS, ARL, pensión y caja de compensación con ' . $aliado->nombre . '. Gestión 100% en línea.')

@section('estilos')
    .hero {
        position: relative;
        overflow: hidden;
        padding: 3.5rem 0 5rem;
        background:
            radial-gradient(1200px 500px at 85% -10%, var(--brand-soft), transparent 60%),
            linear-gradient(180deg, var(--blanco), var(--fondo));
    }
    .hero-grid {
        display: grid;
        grid-template-columns: 1.1fr 0.9fr;
        gap: 3rem;
        align-items: center;
    }
    .badge {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        background: var(--brand-soft);
        color: var(--brand-dark);
        border: 1px solid var(--brand-line);
        border-radius: 999px;
        padding: 0.35rem 0.9rem;
        font-size: 0.8rem;
        font-weight: 600;
        margin-bottom: 1.25rem;
    }
    .badge svg { width: 14px; height: 14px; }
    .hero h1 {
        font-size: clamp(2rem, 4vw, 2.9rem);
        font-weight: 800;
        letter-spacing: -0.02em;
        line-height: 1.12;
        margin-bottom: 1rem;
    }
    .hero h1 span { color: var(--brand-dark); }
    .hero p.lead {
        font-size: 1.1rem;
        color: var(--tinta-suave);
        max-width: 32rem;
        margin-bottom: 2rem;
    }
    .hero-actions { display: flex; gap: 0.9rem; flex-wrap: wrap; }
    .hero-art { display: flex; justify-content: center; align-items: center; position: relative; }
    .hero-glow {
        position: absolute; top: 50%; left: 50%;
        width: 78%; aspect-ratio: 1;
        transform: translate(-50%, -50%) scale(1);
        border-radius: 50%;
        background: var(--brand-soft);
        z-index: 0;
        pointer-events: none;
    }

    @keyframes heroLogoFlotar {
        0%, 100% { transform: translateY(0); }
        50%      { transform: translateY(-10px); }
    }
    @keyframes heroPuntoPulso {
        0%, 100% { transform: scale(1); opacity: 1; }
        50%      { transform: scale(1.25); opacity: 0.55; }
    }
    @keyframes heroGlowRespirar {
        0%   { transform: translate(-50%, -50%) scale(1);    background: var(--brand-soft); opacity: 0.55; }
        50%  { transform: translate(-50%, -50%) scale(1.15); background: var(--brand);      opacity: 0.3;  }
        100% { transform: translate(-50%, -50%) scale(1);    background: var(--brand-soft); opacity: 0.55; }
    }
    .hero-logo-flotante { position: relative; z-index: 1; animation: heroLogoFlotar 4.5s ease-in-out infinite; }
    .hero-punto-acento {
        transform-box: fill-box; transform-origin: center;
        animation: heroPuntoPulso 3s ease-in-out infinite;
    }
    .hero-punto-acento-2 { animation-delay: 1.2s; }
    .hero-glow { animation: heroGlowRespirar 6s ease-in-out infinite; }
    @media (prefers-reduced-motion: reduce) {
        .hero-logo-flotante, .hero-punto-acento, .hero-glow { animation: none; }
    }

    .logo-top {
        display: flex; align-items: center; gap: 0.6rem;
        padding: 1.25rem 0;
    }
    .logo-top img { height: 40px; width: auto; border-radius: 8px; }
    .logo-top strong { font-size: 1.05rem; letter-spacing: -0.01em; }

    .servicios { background: var(--blanco); }
    .titulo-seccion {
        text-align: center;
        max-width: 40rem;
        margin: 0 auto 2.75rem;
    }
    .titulo-seccion h2 { font-size: clamp(1.6rem, 3vw, 2.1rem); font-weight: 800; letter-spacing: -0.02em; margin-bottom: 0.6rem; }
    .titulo-seccion p { color: var(--tinta-suave); }

    .grid-servicios {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 1.25rem;
    }
    .card-servicio {
        background: var(--fondo);
        border: 1px solid var(--borde);
        border-radius: 20px;
        padding: 1.75rem 1.5rem;
        transition: transform .15s ease, box-shadow .15s ease;
    }
    .card-servicio:hover { transform: translateY(-3px); box-shadow: 0 16px 32px -16px rgba(10,22,40,0.15); }
    .card-servicio .icono {
        width: 46px; height: 46px;
        border-radius: 12px;
        background: var(--brand-soft);
        color: var(--brand-dark);
        display: flex; align-items: center; justify-content: center;
        margin-bottom: 1rem;
    }
    .card-servicio .icono svg { width: 24px; height: 24px; }
    .card-servicio h3 { font-size: 1.02rem; font-weight: 700; margin-bottom: 0.4rem; }
    .card-servicio p { font-size: 0.9rem; color: var(--tinta-suave); }

    .planes { background: var(--fondo); }
    .planes-tabs { display: flex; justify-content: center; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 2rem; }
    .tab-plan {
        border: 1.5px solid var(--borde); background: var(--blanco); color: var(--tinta-suave);
        font-family: inherit; font-size: 0.85rem; font-weight: 700; padding: 0.55rem 1.15rem;
        border-radius: 999px; cursor: pointer; transition: all .15s ease;
    }
    .tab-plan:hover { border-color: var(--brand); color: var(--brand-dark); }
    .tab-plan.activo { background: var(--brand); border-color: var(--brand); color: var(--brand-text); }
    .panel-grupo-plan { display: none; }
    .panel-grupo-plan.activo { display: block; }
    .selector-plan { width: 100%; margin-bottom: 1rem; text-align: left; }
    .selector-plan label { display: block; font-size: 0.72rem; font-weight: 700; color: var(--tinta-suave); text-transform: uppercase; letter-spacing: 0.03em; margin-bottom: 0.3rem; }
    .selector-plan select {
        width: 100%; padding: 0.55rem 0.7rem; border: 1.5px solid var(--borde); border-radius: 10px;
        font-size: 0.88rem; font-family: inherit; background: var(--blanco); cursor: pointer;
    }
    .carrusel-planes { position: relative; padding: 0 3rem; }
    .carrusel-viewport { overflow: hidden; padding: 1.2rem 0 0.5rem; }
    .carrusel-track { display: flex; gap: 1.5rem; transition: transform 0.45s ease; }
    .carrusel-planes.centrado .carrusel-track { justify-content: center; }
    .carrusel-slide { flex: 0 0 calc((100% - 3rem) / 3); min-width: 0; display: flex; }
    .carrusel-nav {
        position: absolute; top: 50%; transform: translateY(-50%);
        width: 2.6rem; height: 2.6rem; border-radius: 50%;
        border: 1.5px solid var(--borde); background: var(--blanco);
        color: var(--tinta); font-size: 1.3rem; line-height: 1; cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        z-index: 2; box-shadow: 0 6px 16px -8px rgba(0,0,0,0.2);
    }
    .carrusel-nav:hover { border-color: var(--brand); color: var(--brand); }
    .carrusel-nav.prev { left: 0; }
    .carrusel-nav.next { right: 0; }
    .carrusel-nav[disabled] { opacity: 0.35; cursor: default; }
    .carrusel-dots { display: flex; justify-content: center; gap: 0.5rem; margin-top: 1rem; }
    .carrusel-dots button {
        width: 0.55rem; height: 0.55rem; border-radius: 50%; border: none;
        background: var(--borde); cursor: pointer; padding: 0;
    }
    .carrusel-dots button.activo { background: var(--brand); }
    .card-plan {
        background: var(--blanco);
        border: 1.5px solid var(--borde);
        border-radius: 22px;
        padding: 2rem 1.5rem 1.75rem;
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        position: relative;
        width: 100%;
    }
    .card-plan.destacado {
        border-color: var(--brand);
        box-shadow: 0 20px 40px -20px color-mix(in srgb, var(--brand) 45%, transparent);
        transform: translateY(-6px);
    }
    .card-plan .cinta {
        position: absolute; top: -0.7rem; left: 50%; transform: translateX(-50%);
        background: linear-gradient(135deg, var(--brand), var(--brand-dark));
        color: var(--brand-text);
        font-size: 0.68rem; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase;
        padding: 0.3rem 0.8rem; border-radius: 999px;
        white-space: nowrap;
    }
    .icono-plan {
        width: 96px; height: 96px;
        margin-bottom: 1rem;
        border-radius: 50%;
        background: var(--brand-soft);
        border: 1px solid var(--brand-line);
        display: flex; align-items: center; justify-content: center;
        padding: 16px;
        flex-shrink: 0;
    }
    .icono-plan img { width: 100%; height: 100%; object-fit: contain; }
    .card-plan h3 { width: 100%; font-size: 1.1rem; font-weight: 800; margin-bottom: 0.4rem; }
    .card-plan .desc-plan { width: 100%; font-size: 0.85rem; color: var(--tinta-suave); margin-bottom: 1.1rem; min-height: 2.6em; }
    .chips-plan { display: flex; flex-wrap: wrap; justify-content: center; gap: 0.4rem; margin-bottom: 1.25rem; }
    .chip { background: var(--brand-soft); color: var(--brand-dark); font-size: 0.72rem; font-weight: 600; padding: 0.28rem 0.65rem; border-radius: 999px; }
    .precio-plan { margin-top: auto; margin-bottom: 1.1rem; width: 100%; }
    .precio-plan .valor { font-size: 1.7rem; font-weight: 800; color: var(--tinta); }
    .precio-plan .valor small { font-size: 0.85rem; font-weight: 600; color: var(--tinta-suave); }
    .precio-plan .afiliacion { font-size: 0.78rem; color: var(--tinta-suave); margin-top: 0.2rem; }
    .precio-plan .oculto { font-size: 0.95rem; font-weight: 700; color: var(--tinta); }

    .cotizador { background: var(--blanco); }
    .caja-cotizador {
        max-width: 42rem; margin: 0 auto;
        background: var(--fondo);
        border: 1px solid var(--borde);
        border-radius: 24px;
        padding: 2rem;
    }
    .cotizador-pasos { display: flex; gap: 0.5rem; margin-bottom: 1.75rem; }
    .cotizador-pasos span {
        flex: 1; height: 5px; border-radius: 999px; background: var(--borde);
        transition: background .2s ease;
    }
    .cotizador-pasos span.activo { background: var(--brand); }
    .cot-paso { display: none; }
    .cot-paso.activo { display: block; }
    .cot-titulo { font-size: 1.15rem; font-weight: 800; margin-bottom: 0.3rem; }
    .cot-sub { font-size: 0.85rem; color: var(--tinta-suave); margin-bottom: 1.25rem; }
    .lista-coberturas { display: flex; flex-direction: column; gap: 0.6rem; margin-bottom: 1.25rem; }
    .cobertura {
        display: flex; align-items: center; gap: 0.65rem;
        border: 1.5px solid var(--borde); border-radius: 12px; padding: 0.75rem 0.9rem;
        background: var(--blanco); cursor: pointer;
    }
    .cobertura input { width: 17px; height: 17px; accent-color: var(--brand); }
    .cobertura span { font-size: 0.88rem; font-weight: 600; }
    .cobertura-arl span { flex: 1; }
    .cobertura-arl .nivel-arl-inline {
        width: auto; padding: 0.35rem 0.55rem; border: 1.5px solid var(--borde); border-radius: 8px;
        font-size: 0.8rem; font-family: inherit; background: var(--blanco); cursor: pointer;
    }
    .cot-aviso {
        background: color-mix(in srgb, #d97706 10%, white); border: 1px solid color-mix(in srgb, #d97706 30%, white);
        border-radius: 12px; padding: 0.85rem 1rem; margin-bottom: 1.25rem; font-size: 0.8rem; color: #92400e;
    }
    .campo-cot { margin-bottom: 1rem; }
    .campo-cot label { display: block; font-size: 0.78rem; font-weight: 600; color: var(--tinta-suave); margin-bottom: 0.3rem; }
    .campo-cot input, .campo-cot select {
        width: 100%; padding: 0.6rem 0.75rem; border: 1.5px solid var(--borde); border-radius: 10px;
        font-size: 0.9rem; font-family: inherit; background: var(--blanco);
    }
    .ayuda-cot { font-size: 0.75rem; color: var(--tinta-suave); margin-top: 0.35rem; }
    .cot-nav { display: flex; justify-content: space-between; gap: 0.75rem; margin-top: 1.25rem; }
    .cot-nav .btn-volver { background: none; border: none; color: var(--tinta-suave); font-size: 0.85rem; font-weight: 600; cursor: pointer; padding: 0.6rem; }
    .resultado-columnas { display: grid; grid-template-columns: 1fr 1fr; gap: 1.1rem; margin-bottom: 1.25rem; }
    .resultado-columnas.una-sola { grid-template-columns: 1fr; }
    .resultado-cot { background: var(--blanco); border: 1.5px solid var(--borde); border-radius: 16px; padding: 1.5rem; display: flex; flex-direction: column; transition: border-color .15s ease, box-shadow .15s ease; }
    .resultado-cot.seleccionado { border-color: var(--brand); box-shadow: 0 12px 28px -18px color-mix(in srgb, var(--brand) 50%, transparent); }
    .resultado-cot .etiqueta-perfil { font-size: 0.72rem; font-weight: 700; color: var(--tinta-suave); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 0.3rem; }
    .resultado-cot .plan-elegido { font-size: 0.95rem; font-weight: 700; color: var(--brand-dark); margin-bottom: 0.5rem; }
    .resultado-cot .valor-grande { font-size: 1.8rem; font-weight: 800; color: var(--tinta); }
    .resultado-cot .valor-grande small { font-size: 0.85rem; font-weight: 600; color: var(--tinta-suave); }
    .resultado-cot .linea { display: flex; justify-content: space-between; font-size: 0.82rem; padding: 0.45rem 0; border-top: 1px dashed var(--borde); color: var(--tinta-suave); }
    .resultado-cot .base-cot { font-size: 0.76rem; color: var(--tinta-suave); margin-top: 0.4rem; }
    .resultado-cot .btn-elegir { margin-top: 1rem; }
    .nota-perfil { background: var(--fondo); border: 1px dashed var(--borde); border-radius: 12px; padding: 0.85rem 1rem; margin-bottom: 1.25rem; font-size: 0.8rem; color: var(--tinta-suave); }
    .form-lead .campo-cot { margin-bottom: 0.85rem; }
    .form-lead label.check {
        display: flex; align-items: flex-start; gap: 0.5rem; font-size: 0.76rem; color: var(--tinta-suave); cursor: pointer;
    }
    .form-lead input[type=checkbox] { margin-top: 0.15rem; }
    .cot-error { background: #fee2e2; color: #991b1b; border-radius: 10px; padding: 0.7rem 0.9rem; font-size: 0.82rem; margin-bottom: 1rem; }
    .cot-ok { text-align: center; padding: 1.5rem 0; }
    .cot-ok .icono-ok { width: 56px; height: 56px; border-radius: 50%; background: #dcfce7; color: #16a34a; display: flex; align-items: center; justify-content: center; margin: 0 auto 1rem; }

    .promos { background: var(--blanco); }
    .grid-promos { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.25rem; }
    .card-promo { border: 1px solid var(--borde); border-radius: 18px; overflow: hidden; background: var(--fondo); }
    .card-promo img { width: 100%; aspect-ratio: 1/1; object-fit: cover; }
    .card-promo p { font-size: 0.85rem; color: var(--tinta-suave); padding: 0.9rem 1rem; margin: 0; }
    .card-promo video { display: block; width: 100%; aspect-ratio: 9/16; object-fit: cover; background: #000; }
    .acciones-pieza { display: flex; flex-wrap: wrap; gap: 0.5rem; padding: 0.8rem 1rem 0; }
    .acciones-pieza:last-child { padding-bottom: 1rem; }
    .ver-mas-promos { text-align: center; margin-top: 1.75rem; }
    .acciones-pieza .btn { flex: 1 1 8rem; justify-content: center; padding: 0.55rem 0.8rem; font-size: 0.82rem; }

    .pasos { background: var(--fondo); }
    .grid-pasos { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1.5rem; }
    .paso { position: relative; background: var(--blanco); border: 1px solid var(--borde); border-radius: 20px; padding: 2rem 1.5rem 1.75rem; }
    .paso .num {
        width: 36px; height: 36px; border-radius: 50%;
        background: linear-gradient(135deg, var(--brand), var(--brand-dark));
        color: var(--brand-text);
        display: flex; align-items: center; justify-content: center;
        font-weight: 800; margin-bottom: 1rem;
    }
    .paso h3 { font-size: 1.05rem; font-weight: 700; margin-bottom: 0.4rem; }
    .paso p { font-size: 0.92rem; color: var(--tinta-suave); }

    .faq { background: var(--blanco); }
    .lista-faq { max-width: 44rem; margin: 0 auto; display: flex; flex-direction: column; gap: 0.75rem; }
    .lista-faq details {
        border: 1px solid var(--borde);
        border-radius: 14px;
        padding: 1.1rem 1.3rem;
        background: var(--fondo);
    }
    .lista-faq summary {
        cursor: pointer;
        font-weight: 600;
        list-style: none;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
    }
    .lista-faq summary::-webkit-details-marker { display: none; }
    .lista-faq summary::after {
        content: '+';
        font-size: 1.3rem;
        color: var(--brand-dark);
        flex-shrink: 0;
    }
    .lista-faq details[open] summary::after { content: '−'; }
    .lista-faq p.respuesta { margin-top: 0.75rem; color: var(--tinta-suave); font-size: 0.95rem; }

    .contacto {
        background: linear-gradient(135deg, var(--tinta), var(--brand-dark));
        color: white;
    }
    .grid-contacto { display: grid; grid-template-columns: 1.1fr 0.9fr; gap: 2.5rem; align-items: start; }
    .contacto h2 { font-size: clamp(1.6rem, 3vw, 2.1rem); font-weight: 800; margin-bottom: 0.75rem; letter-spacing: -0.02em; }
    .contacto p.sub { color: rgba(255,255,255,0.75); margin-bottom: 1.75rem; max-width: 30rem; }
    .lista-datos { display: flex; flex-direction: column; gap: 0.9rem; }
    .dato { display: flex; align-items: flex-start; gap: 0.75rem; }
    .dato .icono {
        width: 34px; height: 34px; border-radius: 9px;
        background: rgba(255,255,255,0.12);
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0;
    }
    .dato .icono svg { width: 17px; height: 17px; }
    .dato-texto small { display: block; color: rgba(255,255,255,0.6); font-size: 0.78rem; margin-bottom: 0.1rem; }
    .panel-cta {
        background: rgba(255,255,255,0.08);
        border: 1px solid rgba(255,255,255,0.15);
        border-radius: 20px;
        padding: 2rem;
        backdrop-filter: blur(6px);
    }
    .panel-cta h3 { font-size: 1.2rem; margin-bottom: 0.5rem; }
    .panel-cta p { color: rgba(255,255,255,0.75); font-size: 0.92rem; margin-bottom: 1.5rem; }

    footer {
        background: var(--tinta);
        color: rgba(255,255,255,0.55);
        padding: 1.75rem 0;
        font-size: 0.8rem;
        text-align: center;
    }
    footer strong { color: rgba(255,255,255,0.8); }

    /* ── Encabezado fijo ── */
    .barra-top {
        position: sticky; top: 0; z-index: 40;
        background: color-mix(in srgb, var(--blanco) 82%, transparent);
        backdrop-filter: saturate(1.4) blur(10px); -webkit-backdrop-filter: saturate(1.4) blur(10px);
        border-bottom: 1px solid transparent;
        transition: border-color .2s ease, box-shadow .2s ease;
    }
    .barra-top.con-sombra { border-color: var(--borde); box-shadow: 0 8px 24px -18px rgba(10,22,40,.35); }
    .barra-top .contenedor { display: flex; align-items: center; gap: 1rem; padding-top: .7rem; padding-bottom: .7rem; }
    .barra-marca { display: flex; align-items: center; gap: .65rem; min-width: 0; margin-right: auto; }
    .barra-marca img { height: 42px; width: auto; max-width: 140px; object-fit: contain; border-radius: 8px; }
    .barra-marca strong { font-family: 'Plus Jakarta Sans', 'Inter', sans-serif; font-size: 1.02rem; letter-spacing: -.01em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .barra-nav { display: flex; gap: 1.4rem; font-size: .9rem; font-weight: 600; color: var(--tinta-suave); }
    .barra-nav a { position: relative; padding: .3rem 0; }
    .barra-nav a::after { content: ''; position: absolute; left: 0; right: 100%; bottom: -2px; height: 2px; background: var(--brand); border-radius: 2px; transition: right .25s ease; }
    .barra-nav a:hover { color: var(--tinta); }
    .barra-nav a:hover::after { right: 0; }
    .barra-top .btn { padding: .6rem 1.15rem; font-size: .85rem; }

    /* ── Hero con más vida ── */
    .hero { padding-bottom: 7rem; }
    .hero::before {
        content: ''; position: absolute; inset: 0; pointer-events: none;
        background-image: radial-gradient(color-mix(in srgb, var(--brand) 22%, transparent) 1.2px, transparent 1.2px);
        background-size: 22px 22px;
        -webkit-mask-image: radial-gradient(ellipse 60% 70% at 85% 30%, #000 20%, transparent 75%);
                mask-image: radial-gradient(ellipse 60% 70% at 85% 30%, #000 20%, transparent 75%);
    }
    .hero-mancha { position: absolute; border-radius: 50%; filter: blur(60px); opacity: .45; pointer-events: none; animation: manchaDeriva 14s ease-in-out infinite alternate; }
    .hero-mancha.m1 { width: 420px; height: 420px; background: color-mix(in srgb, var(--brand) 35%, white); top: -140px; left: -120px; }
    .hero-mancha.m2 { width: 300px; height: 300px; background: color-mix(in srgb, var(--brand) 25%, #a5f3fc); bottom: -60px; right: 30%; animation-delay: -6s; }
    @keyframes manchaDeriva { from { transform: translate(0, 0) scale(1); } to { transform: translate(40px, 30px) scale(1.12); } }
    .hero .contenedor { position: relative; z-index: 1; }
    .hero h1 .resaltado {
        background: linear-gradient(120deg, var(--brand), color-mix(in srgb, var(--brand) 55%, #06b6d4));
        -webkit-background-clip: text; background-clip: text; color: transparent;
    }
    .hero-onda { position: absolute; left: 0; right: 0; bottom: -1px; width: 100%; height: 70px; display: block; }
    .hero-onda path { fill: var(--blanco); }

    /* Foto del encabezado: recortada en 4:5, con una lámina del color de marca detrás y el
       logo del aliado en una ficha encima. */
    .hero-foto { position: relative; z-index: 1; width: min(380px, 88%); aspect-ratio: 4 / 5; }
    .hero-foto::before {
        content: ''; position: absolute; inset: 0; border-radius: 34px;
        background: linear-gradient(135deg, var(--brand), color-mix(in srgb, var(--brand) 55%, #06b6d4));
        transform: translate(18px, 18px) rotate(3deg); opacity: .9;
    }
    .hero-foto img.foto {
        position: relative; width: 100%; height: 100%; object-fit: cover; border-radius: 34px;
        box-shadow: 0 30px 60px -28px rgba(10,22,40,.55);
    }
    .hero-foto .ficha-marca {
        position: absolute; left: -22px; bottom: 26px; z-index: 2;
        display: flex; align-items: center; gap: .6rem; max-width: 78%;
        background: var(--blanco); border-radius: 18px; padding: .55rem .9rem .55rem .55rem;
        box-shadow: 0 18px 36px -18px rgba(10,22,40,.5);
    }
    .hero-foto .ficha-marca img { width: 46px; height: 46px; object-fit: contain; border-radius: 10px; }
    .hero-foto .ficha-marca strong { font-family: 'Plus Jakarta Sans', 'Inter', sans-serif; font-size: .85rem; line-height: 1.2; display: block; }
    .hero-foto .ficha-marca small { display: block; color: var(--tinta-suave); font-size: .72rem; line-height: 1.3; }
    .hero-foto .orbita.o2 { top: 8%; right: -18px; }
    .hero-foto .orbita.o3 { top: 34%; left: -26px; bottom: auto; }

    /* Tarjeta de marca: el logo del aliado al centro del hero, con los cuatro servicios orbitando. */
    .tarjeta-marca {
        position: relative; z-index: 1; width: min(300px, 82%);
        background: var(--blanco); border: 1px solid var(--brand-line); border-radius: 28px;
        padding: 2rem 1.5rem 1.6rem; text-align: center;
        box-shadow: 0 30px 60px -30px color-mix(in srgb, var(--brand) 55%, transparent);
    }
    .tarjeta-marca img { width: 150px; height: 150px; object-fit: contain; margin: 0 auto 1rem; }
    .tarjeta-marca .inicial { width: 110px; height: 110px; margin: 0 auto 1rem; border-radius: 30px; display: flex; align-items: center; justify-content: center; font: 800 3rem 'Plus Jakarta Sans', sans-serif; color: var(--brand-text); background: linear-gradient(135deg, var(--brand), var(--brand-dark)); }
    .tarjeta-marca strong { display: block; font-family: 'Plus Jakarta Sans', 'Inter', sans-serif; font-size: 1.05rem; line-height: 1.25; }
    .tarjeta-marca small { display: block; color: var(--tinta-suave); font-size: .8rem; margin-top: .3rem; }
    .orbita {
        position: absolute; z-index: 2; display: inline-flex; align-items: center; gap: .4rem;
        background: var(--blanco); border: 1px solid var(--borde); border-radius: 999px;
        padding: .45rem .85rem; font-size: .78rem; font-weight: 700; color: var(--tinta);
        box-shadow: 0 12px 26px -14px rgba(10,22,40,.4);
        animation: orbitaFlota 5s ease-in-out infinite;
    }
    .orbita i { width: 9px; height: 9px; border-radius: 50%; background: var(--brand); }
    .orbita.o1 { top: 6%;  left: 2%; }
    .orbita.o2 { top: 16%; right: 0;  animation-delay: -1.2s; }
    .orbita.o2 i { background: #f59e0b; }
    .orbita.o3 { bottom: 14%; left: 0; animation-delay: -2.4s; }
    .orbita.o3 i { background: #10b981; }
    .orbita.o4 { bottom: 4%; right: 6%; animation-delay: -3.6s; }
    .orbita.o4 i { background: #ec4899; }
    /* El hero entra al cargar, sin esperar a JS ni al scroll. */
    .entrada { animation: entradaSube .8s cubic-bezier(.2,.7,.2,1) both; }
    @keyframes entradaSube { from { opacity: 0; transform: translateY(22px); } to { opacity: 1; transform: none; } }
    @keyframes orbitaFlota { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-9px); } }

    /* ── Títulos de sección con «ceja» ── */
    .ceja {
        display: inline-block; margin-bottom: .7rem;
        font-size: .74rem; font-weight: 800; letter-spacing: .14em; text-transform: uppercase;
        color: var(--brand-dark); background: var(--brand-soft); border-radius: 999px; padding: .3rem .8rem;
    }
    .titulo-seccion h2 { letter-spacing: -.025em; }

    /* ── Servicios ── */
    .card-servicio { position: relative; overflow: hidden; background: var(--blanco); }
    .card-servicio::after { content: ''; position: absolute; left: 0; right: 0; top: 0; height: 4px; background: linear-gradient(90deg, var(--brand), color-mix(in srgb, var(--brand) 50%, #06b6d4)); transform: scaleX(0); transform-origin: left; transition: transform .35s ease; }
    .card-servicio:hover::after { transform: scaleX(1); }
    .card-servicio:hover { border-color: var(--brand-line); }
    .card-servicio .icono { width: 52px; height: 52px; border-radius: 16px; background: linear-gradient(135deg, var(--brand), var(--brand-dark)); color: var(--brand-text); box-shadow: 0 10px 20px -10px color-mix(in srgb, var(--brand) 70%, transparent); }

    /* ── Planes y cotizador ── */
    .card-plan { transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease; }
    .card-plan:hover { transform: translateY(-6px); box-shadow: 0 24px 44px -24px rgba(10,22,40,.3); border-color: var(--brand-line); }
    .card-plan.destacado:hover { transform: translateY(-10px); }
    .precio-plan .valor { font-family: 'Plus Jakarta Sans', 'Inter', sans-serif; letter-spacing: -.02em; }
    .cotizador { background: radial-gradient(900px 400px at 10% 0%, var(--brand-softer), transparent 70%), var(--blanco); }
    .caja-cotizador { background: var(--blanco); box-shadow: 0 30px 60px -40px rgba(10,22,40,.35); }

    /* ── Pasos unidos por una línea ── */
    .grid-pasos { position: relative; }
    .grid-pasos::before { content: ''; position: absolute; top: 3.1rem; left: 16%; right: 16%; height: 2px; background: repeating-linear-gradient(90deg, var(--brand-line) 0 10px, transparent 10px 18px); }
    .paso { z-index: 1; transition: transform .2s ease, box-shadow .2s ease; }
    .paso:hover { transform: translateY(-4px); box-shadow: 0 20px 36px -24px rgba(10,22,40,.3); }
    .paso .num { width: 44px; height: 44px; font-size: 1.1rem; box-shadow: 0 0 0 6px var(--brand-soft); }

    @media (max-width: 860px) {
        .barra-nav { display: none; }
        .barra-marca strong { font-size: .92rem; }
        .grid-pasos::before { display: none; }
        .tarjeta-marca { width: 60%; padding: 1.4rem 1rem 1.2rem; }
        .tarjeta-marca img { width: 96px; height: 96px; margin-bottom: .7rem; }
        .tarjeta-marca strong { font-size: .92rem; }
        .orbita { font-size: .68rem; padding: .3rem .6rem; }
        .orbita.o1 { top: 0; left: 0; }
        .orbita.o2 { top: 10%; right: 0; }
        .orbita.o3 { bottom: 10%; left: 0; }
        .orbita.o4 { bottom: 0; right: 0; }
        .hero { padding-bottom: 5.5rem; }
        .hero-foto { width: 78%; }
        .hero-foto .ficha-marca { left: -12px; bottom: -16px; padding: .4rem .7rem .4rem .4rem; border-radius: 14px; }
        .hero-foto .ficha-marca img { width: 32px; height: 32px; }
        .hero-foto .ficha-marca strong { font-size: .78rem; }
        .hero-foto .ficha-marca small { display: none; }
        .hero-foto .orbita.o3 { top: 46%; }
        .hero-foto .orbita.o2 { right: -10px; }
        .hero-foto .orbita.o3 { left: -14px; }
    }
    @media (max-width: 480px) {
        .servicios .grid-servicios { grid-template-columns: 1fr; }
    }
    @media (prefers-reduced-motion: reduce) {
        .hero-mancha, .orbita, .entrada { animation: none; }
        .card-plan:hover, .paso:hover { transform: none; }
    }

    @media (max-width: 1024px) and (min-width: 861px) {
        .carrusel-slide { flex: 0 0 calc((100% - 1.5rem) / 2); }
    }

    @media (max-width: 860px) {
        .hero-grid, .grid-contacto { grid-template-columns: 1fr; }
        .hero-art { order: -1; max-width: 300px; margin: 0 auto 1rem; }
        .grid-servicios { grid-template-columns: repeat(2, 1fr); }
        .grid-pasos { grid-template-columns: 1fr; }
        .carrusel-planes { padding: 0 2.2rem; }
        .carrusel-slide { flex: 0 0 100%; }
        .grid-promos { grid-template-columns: repeat(2, 1fr); }
        .card-plan.destacado { transform: none; }
        .resultado-columnas { grid-template-columns: 1fr; }
        .caja-cotizador { padding: 1.5rem 1.1rem; }
    }
@endsection

@section('contenido')

    <header class="barra-top" id="barraTop">
        <div class="contenedor">
            <a href="#" class="barra-marca">
                @if($aliado->logo)
                    <img src="{{ asset('storage/' . $aliado->logo) }}" alt="{{ $aliado->nombre }}">
                @endif
                <strong>{{ $aliado->nombre }}</strong>
            </a>
            <nav class="barra-nav">
                @if($config->seccionActiva('planes'))<a href="#planes">Planes</a>@endif
                @if($config->seccionActiva('cotizador'))<a href="#cotizador">Cotizar</a>@endif
                <a href="#contacto">Contacto</a>
            </nav>
            <a href="#contacto" class="btn btn-brand">Afiliarme</a>
        </div>
    </header>

    <section class="hero">
        <span class="hero-mancha m1" aria-hidden="true"></span>
        <span class="hero-mancha m2" aria-hidden="true"></span>
        <div class="contenedor hero-grid">
            <div class="entrada">
                <span class="badge">
                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6l8-4z"/></svg>
                    Afiliación 100% en línea @if($aliado->ubicacion_publica)· {{ $aliado->ubicacion_publica }}@endif
                </span>
                @if($config->hero_titulo)
                    <h1>{{ $config->hero_titulo }}</h1>
                @else
                    <h1>Tu seguridad social, <span class="resaltado">sin filas ni papeleo</span></h1>
                @endif
                <p class="lead">
                    {{ $config->hero_subtitulo ?: ($aliado->nombre . ' te afilia a EPS, ARL, pensión y caja de compensación de forma rápida y segura, desde donde estés.') }}
                </p>
                <div class="hero-actions">
                    <a href="#contacto" class="btn btn-brand">{{ $config->hero_cta_texto ?: 'Quiero afiliarme' }}</a>
                    @if($config->seccionActiva('faq') && $faqs->isNotEmpty())
                        <a href="#faq" class="btn btn-ghost">Ver preguntas frecuentes</a>
                    @elseif($config->seccionActiva('planes'))
                        <a href="#planes" class="btn btn-ghost">Ver planes</a>
                    @endif
                </div>
            </div>
            <div class="hero-art entrada" style="animation-delay:.15s" aria-hidden="true">
                @if($config->hero_imagen)
                    <div class="hero-foto">
                        <img class="foto" src="{{ asset('storage/' . $config->hero_imagen) }}" alt="" fetchpriority="high">
                        <span class="orbita o2"><i></i>Salud · ARL · Pensión</span>
                        <span class="orbita o3"><i></i>100% en línea</span>
                        <div class="ficha-marca">
                            @if($aliado->logo)<img src="{{ asset('storage/' . $aliado->logo) }}" alt="">@endif
                            <div><strong>{{ $aliado->nombre }}</strong><small>Tu aliado en seguridad social</small></div>
                        </div>
                    </div>
                @elseif($aliado->logo_marca_claro)
                    <div class="hero-glow"></div>
                    <svg class="hero-logo-flotante" viewBox="0 0 320 320" width="100%" style="max-width:380px;">
                        <image href="{{ asset('storage/' . $aliado->logo_marca_claro) }}"
                               x="45" y="45" width="230" height="230" preserveAspectRatio="xMidYMid meet"/>
                        <circle class="hero-punto-acento" cx="285" cy="55" r="15" fill="var(--brand-dark)" opacity="0.5"/>
                        <circle class="hero-punto-acento hero-punto-acento-2" cx="35" cy="285" r="11" fill="var(--brand)" opacity="0.4"/>
                    </svg>
                @else
                    <div class="hero-glow"></div>
                    <div class="tarjeta-marca hero-logo-flotante">
                        @if($aliado->logo)
                            <img src="{{ asset('storage/' . $aliado->logo) }}" alt="">
                        @else
                            <span class="inicial">{{ mb_strtoupper(mb_substr($aliado->nombre, 0, 1)) }}</span>
                        @endif
                        <strong>{{ $aliado->nombre }}</strong>
                        <small>Tu aliado en seguridad social</small>
                    </div>
                    <span class="orbita o1"><i></i>Salud (EPS)</span>
                    <span class="orbita o2"><i></i>ARL</span>
                    <span class="orbita o3"><i></i>Pensión</span>
                    <span class="orbita o4"><i></i>Caja</span>
                @endif
            </div>
        </div>
        <svg class="hero-onda" viewBox="0 0 1440 70" preserveAspectRatio="none" aria-hidden="true"><path d="M0,40 C240,80 480,0 720,28 C960,56 1200,70 1440,22 L1440,70 L0,70 Z"/></svg>
    </section>

    @if($config->seccionActiva('servicios'))
    <section class="servicios">
        <div class="contenedor">
            <div class="titulo-seccion revelar">
                <span class="ceja">Servicios</span>
                <h2>Todo lo que necesitas, en un solo lugar</h2>
                <p>Gestionamos tu afiliación completa al sistema de seguridad social colombiano.</p>
            </div>
            <div class="grid-servicios">
                <div class="card-servicio revelar" style="--retraso:0.0s">
                    <div class="icono"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 14c1.5-1.5 3-3.5 3-6a5 5 0 0 0-10 0 5 5 0 0 0-10 0c0 2.5 1.5 4.5 3 6l7 7z"/></svg></div>
                    <h3>Salud (EPS)</h3>
                    <p>Afiliación a la entidad promotora de salud de tu elección.</p>
                </div>
                <div class="card-servicio revelar" style="--retraso:0.1s">
                    <div class="icono"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6l8-4z"/></svg></div>
                    <h3>Riesgos laborales (ARL)</h3>
                    <p>Protección ante accidentes de trabajo según tu nivel de riesgo.</p>
                </div>
                <div class="card-servicio revelar" style="--retraso:0.2s">
                    <div class="icono"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12l9-9 9 9M5 10v10h14V10"/></svg></div>
                    <h3>Pensión (AFP)</h3>
                    <p>Aportes a tu fondo de pensión para tu futuro.</p>
                </div>
                <div class="card-servicio revelar" style="--retraso:0.3s">
                    <div class="icono"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-6h6v6"/></svg></div>
                    <h3>Caja de compensación</h3>
                    <p>Acceso a subsidios y beneficios familiares.</p>
                </div>
            </div>
        </div>
    </section>
    @endif

    @php
        $etiquetasGrupoPlan = ['empleado' => 'Empleado', 'independiente' => 'Independiente', 'por_dias' => 'Tiempo Parcial', 'solo_arl' => 'Solo ARL'];
        $gruposPlanVisibles = collect(['empleado', 'independiente', 'por_dias', 'solo_arl'])
            ->filter(fn ($g) => isset($planes[$g]) && $planes[$g]->isNotEmpty())
            ->values();
    @endphp
    @if($config->seccionActiva('planes') && $gruposPlanVisibles->isNotEmpty())
    <section class="planes" id="planes">
        <div class="contenedor">
            <div class="titulo-seccion revelar">
                <span class="ceja">Planes</span>
                <h2>Planes disponibles</h2>
                <p>Precios configurados directamente por {{ $aliado->nombre }} — se actualizan automáticamente.</p>
            </div>

            @if($gruposPlanVisibles->count() > 1)
            <div class="planes-tabs" role="tablist">
                @foreach($gruposPlanVisibles as $grupo)
                    <button type="button" class="tab-plan {{ $loop->first ? 'activo' : '' }}" data-tab-grupo="{{ $grupo }}">{{ $etiquetasGrupoPlan[$grupo] }}</button>
                @endforeach
            </div>
            @endif

            @foreach($gruposPlanVisibles as $grupo)
                <div class="panel-grupo-plan {{ $loop->first ? 'activo' : '' }}" data-panel-grupo="{{ $grupo }}">
                    <div class="carrusel-planes revelar" data-grupo="{{ $grupo }}">
                        <button type="button" class="carrusel-nav prev" aria-label="Plan anterior">‹</button>
                        <div class="carrusel-viewport">
                            <div class="carrusel-track">
                                @foreach($planes[$grupo] as $plan)
                                    <div class="carrusel-slide">
                                        <div class="card-plan {{ $plan['destacado'] ? 'destacado' : '' }}">
                                            @if($plan['destacado'])<span class="cinta">Más elegido</span>@endif
                                            @if($plan['imagen'])
                                                <div class="icono-plan"><img src="{{ asset('storage/' . $plan['imagen']) }}" alt="" loading="lazy"></div>
                                            @endif
                                            <h3>{{ $plan['nombre'] }}</h3>
                                            <p class="desc-plan">{{ $plan['descripcion'] }}</p>
                                            <div class="chips-plan">
                                                @if($plan['componentes']['incluye_eps']) <span class="chip">Salud (EPS)</span> @endif
                                                @if($plan['componentes']['incluye_arl']) <span class="chip">ARL</span> @endif
                                                @if($plan['componentes']['incluye_pension']) <span class="chip">Pensión</span> @endif
                                                @if($plan['componentes']['incluye_caja']) <span class="chip">Caja</span> @endif
                                            </div>

                                            @if($plan['selector'] === 'dias')
                                                <div class="selector-plan">
                                                    <label>Días por mes</label>
                                                    <select class="selector-precio" data-precios='@json($plan['precios_por_dias'])'>
                                                        <option value="7">7 días</option>
                                                        <option value="14">14 días</option>
                                                        <option value="21">21 días</option>
                                                        <option value="30" selected>30 días</option>
                                                    </select>
                                                </div>
                                            @endif

                                            <div class="precio-plan">
                                                @if($plan['valor_mensual'] !== null)
                                                    <div class="valor" data-prefijo="{{ $config->precios_modo === 'desde' ? 'Desde ' : '' }}">
                                                        @if($config->precios_modo === 'desde')Desde @endif
                                                        ${{ number_format($plan['valor_mensual'], 0, ',', '.') }}<small>/mes</small>
                                                    </div>
                                                    @if(($plan['costo_afiliacion'] ?? 0) > 0)
                                                        <div class="afiliacion">Primer mes: ${{ number_format($plan['costo_afiliacion'], 0, ',', '.') }} (afiliación)</div>
                                                    @endif
                                                @else
                                                    <div class="oculto">Cotización personalizada</div>
                                                @endif
                                            </div>
                                            <a href="#cotizador" class="btn btn-brand" style="width:100%; justify-content:center;">Cotizar este plan</a>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <button type="button" class="carrusel-nav next" aria-label="Plan siguiente">›</button>
                        <div class="carrusel-dots" role="tablist"></div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>
    @endif

    @if($config->seccionActiva('cotizador'))
    <section class="cotizador" id="cotizador">
        <div class="contenedor">
            <div class="titulo-seccion revelar">
                <span class="ceja">Cotizador</span>
                <h2>Arma tu plan</h2>
                <p>Responde 2 preguntas y calculamos tu valor mensual al instante.</p>
            </div>
            <div class="caja-cotizador revelar"
                 id="cotizadorApp"
                 data-cotizar-url="{{ route('publico.aliado.cotizar', $aliado->slug) }}"
                 data-lead-url="{{ route('publico.aliado.lead', $aliado->slug) }}"
                 data-whatsapp="{{ $whatsapp }}"
                 data-mensaje-base="{{ $config->whatsapp_mensaje_base ?: ('Hola ' . $aliado->nombre . ', quiero información sobre afiliación a seguridad social.') }}"
                 data-salario-minimo="{{ (int) $salarioMinimo }}">

                <div class="cotizador-pasos">
                    <span class="paso-ind activo" data-paso="1"></span>
                    <span class="paso-ind" data-paso="2"></span>
                </div>

                {{-- Paso 1: coberturas --}}
                <div class="cot-paso activo" data-paso="1">
                    <div class="cot-titulo">¿Qué necesitas?</div>
                    <div class="cot-sub">Selecciona todo lo que quieras incluir — te mostramos el valor como empleado y como independiente.</div>
                    <div class="lista-coberturas">
                        <label class="cobertura"><input type="checkbox" data-cob="incluye_eps" checked> <span>Salud (EPS)</span></label>
                        <div class="cobertura cobertura-arl" id="filaArl">
                            <input type="checkbox" data-cob="incluye_arl" id="chkArlEntrada">
                            <span>Riesgos laborales (ARL)</span>
                            <select id="cotNivelArl" class="nivel-arl-inline" style="display:none;">
                                <option value="1">Nivel 1</option>
                                <option value="2">Nivel 2</option>
                                <option value="3">Nivel 3</option>
                                <option value="4">Nivel 4</option>
                                <option value="5">Nivel 5</option>
                            </select>
                        </div>
                        <label class="cobertura"><input type="checkbox" data-cob="incluye_pension"> <span>Pensión (AFP)</span></label>
                        <label class="cobertura"><input type="checkbox" data-cob="incluye_caja"> <span>Caja de compensación</span></label>
                    </div>
                    <div class="campo-cot">
                        <label>Tus ingresos mensuales (opcional)</label>
                        <input type="number" id="cotIngresos" min="0">
                        <p class="ayuda-cot">Como empleado se cotiza sobre este valor; como independiente, sobre el 40% (mínimo legal).</p>
                    </div>
                    <div id="cotError"></div>
                    <div class="cot-nav" style="justify-content:flex-end;">
                        <button type="button" class="btn btn-brand" id="btnVerPlan">Ver mi plan</button>
                    </div>
                </div>

                {{-- Paso 2: resultado dual + captura de lead --}}
                <div class="cot-paso" data-paso="2" id="cotPaso2">
                    <div id="cotResultado"></div>
                </div>

            </div>
        </div>
    </section>
    @endif

    @if($config->seccionActiva('promos') && $promos->isNotEmpty())
    <section class="promos" id="promos">
        <div class="contenedor">
            <div class="titulo-seccion">
                <h2>Novedades</h2>
                <p>Lo último de {{ $aliado->nombre }}.</p>
            </div>
            {{-- Se muestran de a 6; el resto queda oculto hasta "Ver más". Las portadas ocultas van en
                 data-poster (y las imágenes con loading="lazy") para no bajarlas sin que nadie las vea. --}}
            @php $porTanda = 6; @endphp
            <div class="grid-promos" id="gridPromos" data-por-tanda="{{ $porTanda }}">
                @foreach($promos as $promo)
                    @php
                        $esVideo = $promo->tipo_pieza === 'video' && $promo->video_path;
                        $oculta = $loop->index >= $porTanda;
                        $urlDescarga = route('publico.aliado.descargar', ['slug' => $aliado->slug, 'tipo' => $esVideo ? 'video' : 'imagen', 'publicacion' => $promo->id], false);
                        $portada = asset('storage/' . $promo->imagen_path);
                    @endphp
                    <div class="card-promo" @if($oculta) hidden @endif>
                        @if($esVideo)
                            <video controls playsinline preload="none" @if($oculta) data-poster="{{ $portada }}" @else poster="{{ $portada }}" @endif aria-label="{{ $promo->titulo }}">
                                <source src="{{ asset('storage/' . $promo->video_path) }}" type="video/mp4">
                            </video>
                        @else
                            <img src="{{ $portada }}" alt="{{ $promo->titulo }}" loading="lazy">
                        @endif
                        <div class="acciones-pieza">
                            <a href="{{ $urlDescarga }}" class="btn btn-ghost" download>⬇ Descargar</a>
                            <button type="button" class="btn btn-brand btn-compartir" hidden
                                    data-url="{{ $urlDescarga }}" data-titulo="{{ $promo->titulo }}"
                                    data-mime="{{ $esVideo ? 'video/mp4' : (str_ends_with(strtolower($promo->imagen_path), '.png') ? 'image/png' : 'image/jpeg') }}">Compartir</button>
                        </div>
                        @if($promo->copy)
                            <p>{{ Str::limit($promo->copy, 140) }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
            @if($promos->count() > $porTanda)
                <div class="ver-mas-promos">
                    <button type="button" class="btn btn-ghost" id="btnVerMasPromos">Ver más videos e imágenes</button>
                </div>
            @endif
        </div>
    </section>
    @endif

    @if($config->seccionActiva('pasos'))
    <section class="pasos">
        <div class="contenedor">
            <div class="titulo-seccion revelar">
                <span class="ceja">Cómo funciona</span>
                <h2>Así de simple funciona</h2>
                <p>Tres pasos y quedas afiliado, sin moverte de donde estás.</p>
            </div>
            <div class="grid-pasos">
                <div class="paso revelar" style="--retraso:0.0s">
                    <div class="num">1</div>
                    <h3>Escríbenos</h3>
                    <p>Cuéntanos qué necesitas por WhatsApp y te asesoramos sin costo.</p>
                </div>
                <div class="paso revelar" style="--retraso:0.1s">
                    <div class="num">2</div>
                    <h3>Envía tus datos</h3>
                    <p>Comparte tu documentación de forma segura, sin desplazarte.</p>
                </div>
                <div class="paso revelar" style="--retraso:0.2s">
                    <div class="num">3</div>
                    <h3>Quedas afiliado</h3>
                    <p>Recibes la confirmación y el soporte de tu afiliación.</p>
                </div>
            </div>
        </div>
    </section>
    @endif

    @if($config->seccionActiva('faq') && $faqs->isNotEmpty())
        <section class="faq" id="faq">
            <div class="contenedor">
                <div class="titulo-seccion revelar">
                    <span class="ceja">Dudas</span>
                    <h2>Preguntas frecuentes</h2>
                </div>
                <div class="lista-faq">
                    @foreach($faqs as $faq)
                        <details>
                            <summary>{{ $faq->pregunta }}</summary>
                            <p class="respuesta">{{ $faq->respuesta }}</p>
                        </details>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    <section class="contacto" id="contacto">
        <div class="contenedor grid-contacto revelar">
            <div>
                <h2>Hablemos</h2>
                <p class="sub">Escríbenos y un asesor te contacta para resolver tus dudas y afiliarte.</p>
                <div class="lista-datos">
                    @if($aliado->direccion)
                        <div class="dato">
                            <span class="icono"><svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M12 21s7-6.5 7-12a7 7 0 1 0-14 0c0 5.5 7 12 7 12z"/><circle cx="12" cy="9" r="2.5"/></svg></span>
                            <div class="dato-texto"><small>Dirección</small>{{ $aliado->direccion }}@if($aliado->ubicacion_publica), {{ $aliado->ubicacion_publica }}@endif</div>
                        </div>
                    @endif
                    @if($whatsapp)
                        <div class="dato">
                            <span class="icono"><svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.12.9.32 1.78.6 2.63a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.45-1.17a2 2 0 0 1 2.11-.45c.85.28 1.73.48 2.63.6A2 2 0 0 1 22 16.92z"/></svg></span>
                            <div class="dato-texto"><small>WhatsApp</small>{{ $whatsapp }}</div>
                        </div>
                    @endif
                    @if($aliado->correo)
                        <div class="dato">
                            <span class="icono"><svg viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M4 4h16v16H4z"/><path d="M4 4l8 8 8-8"/></svg></span>
                            <div class="dato-texto"><small>Correo</small>{{ $aliado->correo }}</div>
                        </div>
                    @endif
                </div>
            </div>
            <div class="panel-cta">
                <h3>¿Listo para afiliarte?</h3>
                <p>Escríbenos por WhatsApp y un asesor de {{ $aliado->nombre }} te acompaña en todo el proceso.</p>
                @if($whatsapp)
                    @php
                        $numeroWaCta = preg_replace('/\D/', '', $whatsapp);
                        if ($numeroWaCta && !str_starts_with($numeroWaCta, '57')) { $numeroWaCta = '57' . $numeroWaCta; }
                        $mensajeWaCta = rawurlencode($config->whatsapp_mensaje_base ?: ('Hola ' . $aliado->nombre . ', quiero información sobre afiliación a seguridad social.'));
                    @endphp
                    <a href="https://wa.me/{{ $numeroWaCta }}?text={{ $mensajeWaCta }}" target="_blank" rel="noopener" onclick="registrarClicWa()" class="btn btn-brand" style="width:100%; justify-content:center;">
                        Escribir por WhatsApp
                    </a>
                @endif
            </div>
        </div>
    </section>

    <footer>
        <div class="contenedor">
            <strong>{{ $aliado->nombre }}</strong> es un intermediario privado de afiliación al sistema de seguridad social colombiano, no una entidad estatal.
            &copy; {{ now()->year }} {{ $aliado->nombre }}. Todos los derechos reservados.
            <div style="margin-top:0.5rem;">
                <a href="{{ route('publico.aliado.privacidad', $aliado->slug) }}" style="text-decoration:underline;">Política de privacidad</a>
                &nbsp;·&nbsp;
                <a href="{{ route('publico.aliado.terminos', $aliado->slug) }}" style="text-decoration:underline;">Términos del servicio</a>
                &nbsp;·&nbsp;
                <a href="{{ route('publico.aliado.eliminacion_datos', $aliado->slug) }}" style="text-decoration:underline;">Eliminación de datos</a>
            </div>
        </div>
    </footer>

@endsection

@section('scripts')
<script>
(function () {
    var barra = document.getElementById('barraTop');
    if (!barra) return;
    var marcar = function () { barra.classList.toggle('con-sombra', window.scrollY > 8); };
    window.addEventListener('scroll', marcar, { passive: true });
    marcar();
})();
</script>
<script>
(function () {
    var app = document.getElementById('cotizadorApp');
    if (!app) return;

    var estado = { componentes: {}, ingresos: null, nivel_arl: 1, seleccionado: null, resultado: null };
    var csrfToken   = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var cotizarUrl  = app.dataset.cotizarUrl;
    var leadUrl     = app.dataset.leadUrl;
    var whatsapp    = app.dataset.whatsapp;
    var mensajeBase = app.dataset.mensajeBase;
    var salarioMinimo = app.dataset.salarioMinimo;

    var chkArl        = app.querySelector('[data-cob="incluye_arl"]');
    var selectNivelArl = document.getElementById('cotNivelArl');
    var filaArl        = document.getElementById('filaArl');

    var cotIngresos = document.getElementById('cotIngresos');
    if (salarioMinimo && !cotIngresos.value) {
        cotIngresos.value = salarioMinimo;
    }

    // La fila de ARL ya no es un <label> (para poder anidar el select de nivel de riesgo sin
    // que un clic en el select dispare también el toggle del checkbox) — se replica a mano el
    // comportamiento de "clic en toda la fila" de las demás coberturas.
    filaArl.addEventListener('click', function (e) {
        if (e.target === chkArl || e.target === selectNivelArl) return;
        chkArl.checked = !chkArl.checked;
        chkArl.dispatchEvent(new Event('change'));
    });

    chkArl.addEventListener('change', function () {
        selectNivelArl.style.display = chkArl.checked ? 'inline-block' : 'none';
    });

    function irAPaso(n) {
        app.querySelectorAll('.cot-paso').forEach(function (el) {
            el.classList.toggle('activo', el.dataset.paso === String(n));
        });
        app.querySelectorAll('.paso-ind').forEach(function (el) {
            el.classList.toggle('activo', parseInt(el.dataset.paso, 10) <= n);
        });
    }

    function formatoMoneda(v) {
        return '$' + Math.round(v).toLocaleString('es-CO');
    }

    document.getElementById('btnVerPlan').addEventListener('click', function () {
        var btn = this;
        var cobs = {};
        app.querySelectorAll('[data-cob]').forEach(function (c) { cobs[c.dataset.cob] = c.checked; });

        var errorBox = document.getElementById('cotError');
        errorBox.innerHTML = '';

        if (![cobs.incluye_eps, cobs.incluye_arl, cobs.incluye_pension, cobs.incluye_caja].some(Boolean)) {
            errorBox.innerHTML = '<div class="cot-error">Selecciona al menos una cobertura.</div>';
            return;
        }

        var ingresosInput = cotIngresos.value;
        estado.componentes = cobs;
        estado.ingresos  = ingresosInput ? parseFloat(ingresosInput) : null;
        estado.nivel_arl = parseInt(selectNivelArl.value || '1', 10);
        estado.seleccionado = null;
        estado.resultado = null;

        btn.disabled = true;
        btn.textContent = 'Calculando...';

        var body = Object.assign({}, cobs, {
            nivel_arl: estado.nivel_arl,
            ingresos: estado.ingresos
        });

        fetch(cotizarUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
            body: JSON.stringify(body)
        })
        .then(function (r) { return r.json().then(function (data) { return { status: r.status, data: data }; }); })
        .then(function (res) {
            btn.disabled = false;
            btn.textContent = 'Ver mi plan';
            if (res.status !== 200) {
                errorBox.innerHTML = '<div class="cot-error">' + (res.data.error || 'No pudimos calcular tu plan.') + '</div>';
                return;
            }
            renderResultado(res.data);
            irAPaso(2);
        })
        .catch(function () {
            btn.disabled = false;
            btn.textContent = 'Ver mi plan';
            errorBox.innerHTML = '<div class="cot-error">Hubo un problema de conexión. Intenta de nuevo.</div>';
        });
    });

    /** Arma el HTML de UNA columna (empleado o independiente) a partir del sub-objeto que
     *  devuelve el backend para ese perfil. `mostrarBoton` es false cuando es la única columna
     *  disponible (no hace falta "elegir" si no hay otra opción que comparar). */
    function renderColumna(perfil, etiqueta, info, base, precios_visibles, precios_modo, mostrarBoton) {
        var html = '<div class="resultado-cot" data-perfil="' + perfil + '">';
        html += '<div class="etiqueta-perfil">' + etiqueta + '</div>';
        html += '<div class="plan-elegido">' + info.plan_nombre + '</div>';

        if (precios_visibles) {
            html += '<div class="valor-grande">' + (precios_modo === 'desde' ? 'Desde ' : '')
                  + formatoMoneda(info.valor_mensual_total) + '<small>/mes</small></div>';

            if (info.costo_afiliacion_sugerido > 0) {
                html += '<div class="linea"><span>Afiliación (referencia)</span><strong>' + formatoMoneda(info.costo_afiliacion_sugerido) + '</strong></div>';
            }
            html += '<div class="base-cot">Base de cotización: ' + formatoMoneda(base) + '</div>';
        } else {
            html += '<div class="valor-grande" style="font-size:1.25rem;">Un asesor te confirma el valor por WhatsApp</div>';
        }

        if (info.nota_ajuste) {
            html += '<div class="cot-aviso">ℹ️ ' + info.nota_ajuste + '</div>';
        }
        if (info.nota_afp) {
            html += '<div class="cot-aviso">⚠️ ' + info.nota_afp + '</div>';
        }

        if (mostrarBoton) {
            html += '<button type="button" class="btn btn-brand btn-elegir" data-perfil="' + perfil + '">Elegir este plan</button>';
        }

        html += '</div>';
        return html;
    }

    function renderResultado(data) {
        var perfiles = [
            { key: 'dependiente',   etiqueta: 'Como empleado',      info: data.dependiente },
            { key: 'independiente', etiqueta: 'Como independiente', info: data.independiente }
        ].filter(function (p) { return !!p.info; });

        var html = '';
        var multiples = perfiles.length > 1;

        html += '<div class="resultado-columnas' + (multiples ? '' : ' una-sola') + '">';
        perfiles.forEach(function (p) {
            var base = p.key === 'dependiente' ? data.base_dependiente : data.base_independiente;
            html += renderColumna(p.key, p.etiqueta, p.info, base, data.precios_visibles, data.precios_modo, multiples);
        });
        html += '</div>';

        if (perfiles.length === 1) {
            var faltante = perfiles[0].key === 'dependiente' ? 'independientes' : 'empleados';
            html += '<div class="nota-perfil">Esta combinación solo aplica para '
                  + (perfiles[0].key === 'dependiente' ? 'empleados' : 'independientes')
                  + ' — no está disponible para ' + faltante + '.</div>';
        }

        html += '<div id="cotFormLeadWrap" style="display:none;">'
              + '<form class="form-lead" id="formLead">'
              + '<input type="text" name="sitio_web" style="position:absolute;left:-9999px;" tabindex="-1" autocomplete="off">'
              + '<div class="campo-cot"><label>Tu nombre</label><input type="text" id="leadNombre" required maxlength="150"></div>'
              + '<div class="campo-cot"><label>Tu celular (WhatsApp)</label><input type="tel" id="leadCelular" required maxlength="30" placeholder="Ej: 3001234567"></div>'
              + '<label class="check"><input type="checkbox" id="leadConsiento" required> Acepto que me contacten para esta cotización.</label>'
              + '<div class="cot-nav"><button type="button" class="btn-volver" data-volver="1">← Cambiar selección</button>'
              + '<button type="submit" class="btn btn-brand">Enviar por WhatsApp</button></div>'
              + '</form>'
              + '</div>';

        document.getElementById('cotResultado').innerHTML = html;

        var formWrap = document.getElementById('cotFormLeadWrap');

        function elegir(perfil) {
            estado.seleccionado = perfil;
            estado.resultado = perfil === 'dependiente' ? data.dependiente : data.independiente;
            document.querySelectorAll('#cotResultado .resultado-cot').forEach(function (card) {
                card.classList.toggle('seleccionado', card.dataset.perfil === perfil);
            });
            formWrap.style.display = 'block';
        }

        document.querySelectorAll('#cotResultado .btn-elegir').forEach(function (btn) {
            btn.addEventListener('click', function () { elegir(btn.dataset.perfil); });
        });

        // Con una sola columna disponible no hace falta el paso de "elegir": se selecciona sola.
        if (perfiles.length === 1) {
            elegir(perfiles[0].key);
        }

        document.querySelectorAll('#cotResultado [data-volver="1"]').forEach(function (btn) {
            btn.addEventListener('click', function () { irAPaso(1); });
        });

        document.getElementById('formLead').addEventListener('submit', function (e) {
            e.preventDefault();
            enviarLead();
        });
    }

    function enviarLead() {
        var resultado = estado.resultado;
        var nombre    = document.getElementById('leadNombre').value;
        var celular   = document.getElementById('leadCelular').value;
        var honeypot  = document.querySelector('#formLead [name="sitio_web"]').value;

        if (honeypot) return; // relleno solo por bots

        var body = {
            nombre: nombre,
            celular: celular,
            perfil: estado.seleccionado,
            incluye_eps: !!estado.componentes.incluye_eps,
            incluye_arl: !!estado.componentes.incluye_arl,
            incluye_pension: !!estado.componentes.incluye_pension,
            incluye_caja: !!estado.componentes.incluye_caja,
            ingreso_mensual: estado.ingresos,
            valor_mensual_cotizado: resultado.valor_mensual_total || null,
            plan_interes: resultado.plan_nombre,
            origen: 'cotizador',
            consiento_datos: document.getElementById('leadConsiento').checked,
            sitio_web: honeypot
        };

        // No se espera la respuesta (keepalive) para poder abrir WhatsApp en el mismo gesto
        // síncrono del click — si se espera un fetch, algunos navegadores bloquean el popup.
        fetch(leadUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
            body: JSON.stringify(body),
            keepalive: true
        }).catch(function () {});

        if (whatsapp) {
            var numero = whatsapp.replace(/\D/g, '');
            if (numero && numero.indexOf('57') !== 0) numero = '57' + numero;
            var msg = 'Hola, soy ' + nombre + '. Me interesa el plan "' + resultado.plan_nombre + '"'
                    + (resultado.valor_mensual_total ? ' (' + formatoMoneda(resultado.valor_mensual_total) + '/mes)' : '')
                    + '. ' + mensajeBase;
            window.open('https://wa.me/' + numero + '?text=' + encodeURIComponent(msg), '_blank');
        }

        document.getElementById('cotResultado').innerHTML =
            '<div class="cot-ok"><div class="icono-ok">✓</div><h3>¡Listo!</h3><p>Te escribimos por WhatsApp en un momento.</p></div>';
    }
})();

(function () {
    var reducirMov = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /** Fábrica: cada pestaña de "Planes disponibles" tiene su propio carrusel independiente. */
    function crearCarrusel(carrusel) {
        var viewport = carrusel.querySelector('.carrusel-viewport');
        var track    = carrusel.querySelector('.carrusel-track');
        var slides   = Array.prototype.slice.call(carrusel.querySelectorAll('.carrusel-slide'));
        var btnPrev  = carrusel.querySelector('.carrusel-nav.prev');
        var btnNext  = carrusel.querySelector('.carrusel-nav.next');
        var dotsWrap = carrusel.querySelector('.carrusel-dots');
        var total    = slides.length;

        if (total === 0) { carrusel.style.display = 'none'; return null; }

        var indice = 0;
        var timer  = null;
        var visibles = 1;
        var dots = [];

        function visiblesActuales() {
            var ancho = window.innerWidth;
            if (ancho <= 860) return 1;
            if (ancho <= 1024) return 2;
            return 3;
        }

        function maxIndice() {
            return Math.max(0, total - visibles);
        }

        function construirDots() {
            dotsWrap.innerHTML = '';
            dots = [];
            var pasos = maxIndice() + 1;
            if (pasos <= 1) { dotsWrap.style.display = 'none'; return; }
            dotsWrap.style.display = 'flex';
            for (var i = 0; i < pasos; i++) {
                var b = document.createElement('button');
                b.type = 'button';
                b.setAttribute('aria-label', 'Ir al plan ' + (i + 1));
                (function (idx) {
                    b.addEventListener('click', function () { irA(idx); reiniciar(); });
                })(i);
                dotsWrap.appendChild(b);
                dots.push(b);
            }
        }

        function actualizarDots() {
            dots.forEach(function (d, i) { d.classList.toggle('activo', i === indice); });
        }

        function actualizarNav() {
            var deshabilitado = maxIndice() === 0;
            btnPrev.disabled = deshabilitado;
            btnNext.disabled = deshabilitado;
        }

        function mover() {
            var slide = slides[0];
            var anchoSlide = slide.getBoundingClientRect().width;
            var gap = 24; // 1.5rem
            track.style.transform = 'translateX(-' + (indice * (anchoSlide + gap)) + 'px)';
            actualizarDots();
        }

        function irA(i) {
            indice = Math.max(0, Math.min(i, maxIndice()));
            mover();
        }

        function siguiente() {
            indice = indice >= maxIndice() ? 0 : indice + 1;
            mover();
        }

        function anterior() {
            indice = indice <= 0 ? maxIndice() : indice - 1;
            mover();
        }

        function iniciar() {
            if (reducirMov || maxIndice() === 0) return;
            detener();
            timer = setInterval(siguiente, 5000);
        }

        function detener() {
            if (timer) { clearInterval(timer); timer = null; }
        }

        function reiniciar() {
            detener();
            iniciar();
        }

        function recalcular() {
            visibles = visiblesActuales();
            indice = Math.min(indice, maxIndice());
            // Menos tarjetas que espacios visibles (ej. "Tiempo Parcial" con solo 2) -> centrar
            // en vez de dejarlas pegadas a la izquierda con espacio muerto a la derecha.
            carrusel.classList.toggle('centrado', total <= visibles);
            construirDots();
            actualizarNav();
            mover();
        }

        btnPrev.addEventListener('click', function () { anterior(); reiniciar(); });
        btnNext.addEventListener('click', function () { siguiente(); reiniciar(); });

        carrusel.addEventListener('mouseenter', detener);
        carrusel.addEventListener('mouseleave', iniciar);
        carrusel.addEventListener('focusin', detener);
        carrusel.addEventListener('focusout', iniciar);

        var touchStartX = null;
        viewport.addEventListener('touchstart', function (e) {
            touchStartX = e.touches[0].clientX;
            detener();
        }, { passive: true });
        viewport.addEventListener('touchend', function (e) {
            if (touchStartX === null) return;
            var delta = e.changedTouches[0].clientX - touchStartX;
            if (Math.abs(delta) > 40) {
                if (delta < 0) { siguiente(); } else { anterior(); }
            }
            touchStartX = null;
            iniciar();
        });

        recalcular();

        return { iniciar: iniciar, detener: detener, recalcular: recalcular };
    }

    var instancias = {}; // grupo -> {iniciar, detener, recalcular}
    document.querySelectorAll('.carrusel-planes').forEach(function (el) {
        var inst = crearCarrusel(el);
        if (inst) instancias[el.dataset.grupo] = inst;
    });

    // Solo el panel visible al cargar arranca su autoplay — los demás quedan pausados hasta
    // que su pestaña se active (evita animar carruseles ocultos y, sobre todo, evita medir
    // anchos de slides con display:none, que darían 0).
    document.querySelectorAll('.panel-grupo-plan.activo .carrusel-planes').forEach(function (el) {
        var inst = instancias[el.dataset.grupo];
        if (inst) inst.iniciar();
    });

    document.addEventListener('visibilitychange', function () {
        document.querySelectorAll('.panel-grupo-plan.activo .carrusel-planes').forEach(function (el) {
            var inst = instancias[el.dataset.grupo];
            if (inst) inst[document.hidden ? 'detener' : 'iniciar']();
        });
    });

    var resizeTimer = null;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function () {
            Object.keys(instancias).forEach(function (g) { instancias[g].recalcular(); });
        }, 150);
    });

    document.querySelectorAll('.tab-plan').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var grupoElegido = btn.dataset.tabGrupo;
            document.querySelectorAll('.tab-plan').forEach(function (b) {
                b.classList.toggle('activo', b === btn);
            });
            document.querySelectorAll('.panel-grupo-plan').forEach(function (panel) {
                var activo = panel.dataset.panelGrupo === grupoElegido;
                panel.classList.toggle('activo', activo);
                var inst = instancias[panel.dataset.panelGrupo];
                if (!inst) return;
                if (activo) { inst.recalcular(); inst.iniciar(); } else { inst.detener(); }
            });
        });
    });

    // Selectores de nivel de riesgo / días en las tarjetas "Solo ARL" y "Por días": el precio
    // por cada opción ya viene precalculado desde el backend (evita ida y vuelta al servidor).
    document.querySelectorAll('.selector-precio').forEach(function (sel) {
        sel.addEventListener('change', function () {
            var precios = JSON.parse(sel.dataset.precios);
            var valor = precios[sel.value];
            if (valor === null || valor === undefined) return;
            var card = sel.closest('.card-plan');
            var elValor = card && card.querySelector('.precio-plan .valor');
            if (!elValor) return;
            var prefijo = elValor.dataset.prefijo || '';
            elValor.innerHTML = prefijo + '$' + Math.round(valor).toLocaleString('es-CO') + '<small>/mes</small>';
        });
    });
})();
</script>
<script>
(function () {
    // "Ver más" de Novedades: destapa la siguiente tanda. Las portadas de los videos ocultos
    // vienen en data-poster para no bajarlas antes de tiempo.
    var grid = document.getElementById('gridPromos');
    var btnVerMas = document.getElementById('btnVerMasPromos');
    if (grid && btnVerMas) {
        var porTanda = parseInt(grid.dataset.porTanda, 10) || 6;
        btnVerMas.addEventListener('click', function () {
            var ocultas = grid.querySelectorAll('.card-promo[hidden]');
            for (var i = 0; i < ocultas.length && i < porTanda; i++) {
                ocultas[i].hidden = false;
                var video = ocultas[i].querySelector('video[data-poster]');
                if (video) { video.poster = video.dataset.poster; video.removeAttribute('data-poster'); }
            }
            if (ocultas.length <= porTanda) btnVerMas.parentNode.remove();
        });
    }

    // "Compartir" de Novedades: en el celular abre el menú del sistema con el ARCHIVO (no el
    // enlace), para mandarlo directo por WhatsApp. Solo aparece donde el navegador sabe
    // compartir archivos de ese tipo; en el computador queda "Descargar".
    if (!navigator.canShare || !window.File) return;
    document.querySelectorAll('.btn-compartir').forEach(function (btn) {
        var mime = btn.dataset.mime;
        var nombreArchivo = mime === 'video/mp4' ? 'video.mp4' : (mime === 'image/png' ? 'imagen.png' : 'imagen.jpg');
        try {
            if (!navigator.canShare({ files: [new File([''], nombreArchivo, { type: mime })] })) return;
        } catch (e) { return; }

        var textoOriginal = btn.textContent;
        var archivo = null;
        var compartir = function () {
            return navigator.share({ files: [archivo], title: btn.dataset.titulo })
                .then(function () { btn.textContent = textoOriginal; })
                .catch(function (e) {
                    if (e && e.name === 'AbortError') { btn.textContent = textoOriginal; return; }
                    // Safari solo abre el menú dentro del toque: si la descarga tardó, ya
                    // venció. El archivo queda listo y el siguiente toque lo comparte.
                    if (e && e.name === 'NotAllowedError') { btn.textContent = 'Toca para enviar'; return; }
                    window.location.href = btn.dataset.url;
                });
        };
        btn.hidden = false;
        btn.addEventListener('click', function () {
            if (archivo) { compartir(); return; }
            btn.disabled = true;
            btn.textContent = 'Preparando...';
            fetch(btn.dataset.url)
                .then(function (r) { if (!r.ok) throw new Error(r.status); return r.blob(); })
                .then(function (blob) {
                    archivo = new File([blob], nombreArchivo, { type: mime });
                    btn.disabled = false;
                    return compartir();
                })
                .catch(function () {
                    btn.disabled = false;
                    btn.textContent = textoOriginal;
                    window.location.href = btn.dataset.url;
                });
        });
    });
})();
</script>
@endsection
