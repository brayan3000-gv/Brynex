@extends('layouts.public')

@php
    $titulos = [
        'privacidad'        => 'Política de Privacidad',
        'terminos'          => 'Términos y Condiciones del Servicio',
        'eliminacion-datos' => 'Instrucciones para la Eliminación de Datos',
    ];
    $tituloPagina = $titulos[$tipo] ?? 'Documento legal';
@endphp

@section('titulo', $tituloPagina . ' — ' . $aliado->nombre)
@section('descripcion', $tituloPagina . ' de ' . $aliado->nombre . '.')

@section('estilos')
    .legal { background: var(--blanco); padding: 3rem 0 4rem; }
    .legal-doc { max-width: 46rem; margin: 0 auto; }
    .legal-doc h1 { font-size: 1.7rem; font-weight: 800; margin-bottom: 0.4rem; letter-spacing: -0.01em; }
    .legal-doc .fecha { font-size: 0.82rem; color: var(--tinta-suave); margin-bottom: 2rem; }
    .legal-doc h2 { font-size: 1.15rem; font-weight: 700; margin: 2rem 0 0.75rem; color: var(--brand-dark); }
    .legal-doc p { font-size: 0.92rem; line-height: 1.7; color: var(--tinta); margin-bottom: 0.9rem; }
    .legal-doc ul { margin: 0 0 0.9rem 1.3rem; }
    .legal-doc li { font-size: 0.92rem; line-height: 1.7; color: var(--tinta); margin-bottom: 0.4rem; }
    .legal-doc a { color: var(--brand-dark); text-decoration: underline; }
    .legal-nav { display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 2rem; font-size: 0.82rem; }
    .legal-nav a { color: var(--tinta-suave); text-decoration: none; border-bottom: 1px solid var(--borde); padding-bottom: 0.2rem; }
    .legal-nav a.activo { color: var(--brand-dark); border-color: var(--brand-dark); font-weight: 600; }
@endsection

@section('contenido')

    <header class="contenedor logo-top">
        @if($aliado->logo)
            <img src="{{ asset('storage/' . $aliado->logo) }}" alt="{{ $aliado->nombre }}">
        @endif
        <strong>{{ $aliado->nombre }}</strong>
    </header>

    <section class="legal">
        <div class="contenedor legal-doc">

            <div class="legal-nav">
                <a href="{{ route('publico.aliado', $aliado->slug) }}">← Volver al inicio</a>
                <a href="{{ route('publico.aliado.privacidad', $aliado->slug) }}" class="{{ $tipo === 'privacidad' ? 'activo' : '' }}">Política de privacidad</a>
                <a href="{{ route('publico.aliado.terminos', $aliado->slug) }}" class="{{ $tipo === 'terminos' ? 'activo' : '' }}">Términos del servicio</a>
                <a href="{{ route('publico.aliado.eliminacion_datos', $aliado->slug) }}" class="{{ $tipo === 'eliminacion-datos' ? 'activo' : '' }}">Eliminación de datos</a>
            </div>

            <h1>{{ $tituloPagina }}</h1>
            <p class="fecha">Última actualización: {{ now()->translatedFormat('d \d\e F \d\e Y') }}</p>

            @if($tipo === 'privacidad')

                <p>
                    Esta Política de Privacidad describe cómo <strong>{{ $aliado->nombre }}</strong>
                    ({{ $aliado->razon_social ?: $aliado->nombre }}@if($aliado->nit), NIT {{ $aliado->nit }}@endif),
                    con domicilio en {{ $aliado->direccion ?: 'Colombia' }}@if($aliado->ubicacion_publica), {{ $aliado->ubicacion_publica }}@endif,
                    recolecta, usa, almacena y protege los datos personales de las personas que nos contactan para
                    afiliación a EPS, ARL, fondo de pensión y caja de compensación familiar, o que interactúan con
                    nosotros a través de este sitio web, WhatsApp, Facebook o Instagram.
                </p>
                <p>
                    Este tratamiento se rige por la Ley 1581 de 2012 y el Decreto 1377 de 2013 de la República de
                    Colombia (régimen de protección de datos personales / Habeas Data).
                </p>

                <h2>1. Datos que recolectamos</h2>
                <ul>
                    <li>Datos de identificación: nombre completo, número de cédula, fecha de nacimiento.</li>
                    <li>Datos de contacto: teléfono, WhatsApp, correo electrónico, dirección.</li>
                    <li>Datos requeridos para la afiliación: ingresos o IBC, tipo de vinculación laboral, y —cuando aplique— datos de tu núcleo familiar o beneficiarios (nombre, documento, parentesco).</li>
                    <li>Datos de navegación básicos del sitio web (páginas visitadas, dispositivo), sin fines distintos a mejorar el servicio.</li>
                </ul>

                <h2>2. Finalidad del tratamiento</h2>
                <ul>
                    <li>Gestionar tu afiliación o traslado ante la EPS, ARL, fondo de pensión y/o caja de compensación que elijas.</li>
                    <li>Contactarte para asesorarte, dar seguimiento a tu solicitud y resolver tus dudas.</li>
                    <li>Enviarte información sobre nuestros servicios y promociones, cuando hayas dado tu consentimiento.</li>
                    <li>Cumplir con obligaciones legales y regulatorias del sector de seguridad social.</li>
                </ul>

                <h2>3. A quién compartimos tus datos</h2>
                <p>
                    Compartimos los datos estrictamente necesarios con las entidades del Sistema General de Seguridad
                    Social (EPS, ARL, administradoras de fondos de pensión, cajas de compensación) para poder radicar
                    y gestionar tu afiliación. <strong>No vendemos ni cedemos tus datos personales a terceros con fines
                    comerciales distintos a este propósito.</strong>
                </p>

                <h2>4. Tus derechos como titular de los datos</h2>
                <p>De acuerdo con la ley colombiana, tienes derecho a:</p>
                <ul>
                    <li>Conocer, actualizar y rectificar tus datos personales.</li>
                    <li>Solicitar prueba de la autorización otorgada para el tratamiento de tus datos.</li>
                    <li>Ser informado sobre el uso que se le ha dado a tus datos.</li>
                    <li>Revocar la autorización y/o solicitar la eliminación de tus datos, cuando no exista un deber legal de conservarlos.</li>
                    <li>Acceder de forma gratuita a tus datos personales que hayan sido objeto de tratamiento.</li>
                </ul>
                <p>
                    Para ejercer cualquiera de estos derechos, escríbenos a
                    @if($aliado->correo)<a href="mailto:{{ $aliado->correo }}">{{ $aliado->correo }}</a>@else nuestro correo de contacto @endif
                    @if($whatsapp) o por WhatsApp al {{ $whatsapp }}@endif.
                    Ver también nuestras <a href="{{ route('publico.aliado.eliminacion_datos', $aliado->slug) }}">instrucciones específicas para eliminación de datos</a>.
                </p>

                <h2>5. Seguridad de la información</h2>
                <p>
                    Adoptamos medidas técnicas y administrativas razonables para proteger tus datos personales contra
                    pérdida, uso indebido, acceso no autorizado o divulgación.
                </p>

                <h2>6. Redes sociales</h2>
                <p>
                    Si interactúas con nosotros a través de Facebook o Instagram, el tratamiento de tus datos en esas
                    plataformas también está sujeto a las políticas de privacidad de Meta.
                </p>

                <h2>7. Cambios a esta política</h2>
                <p>
                    Podemos actualizar esta política para reflejar cambios en nuestras prácticas o en la normativa
                    aplicable. La fecha de la última actualización aparece al inicio de este documento.
                </p>

            @elseif($tipo === 'terminos')

                <p>
                    Al usar este sitio web o contactar a <strong>{{ $aliado->nombre }}</strong> para solicitar nuestros
                    servicios, aceptas los siguientes Términos y Condiciones.
                </p>

                <h2>1. Naturaleza del servicio</h2>
                <p>
                    <strong>{{ $aliado->nombre }}</strong> es un intermediario privado que presta servicios de asesoría
                    y gestión para la afiliación de personas naturales y empresas al Sistema General de Seguridad
                    Social en Colombia (salud, riesgos laborales, pensión y caja de compensación familiar).
                    <strong>{{ $aliado->nombre }}</strong> no es una entidad estatal, ni una EPS, ARL, fondo de pensión
                    o caja de compensación — actuamos como enlace entre tú y esas entidades.
                </p>

                <h2>2. Servicios ofrecidos</h2>
                <ul>
                    <li>Asesoría y trámite de afiliación a EPS, ARL, fondo de pensión y caja de compensación.</li>
                    <li>Trámite de traslados entre entidades.</li>
                    <li>Cotización de planes de seguridad social según tu perfil (dependiente o independiente).</li>
                </ul>

                <h2>3. Tus responsabilidades</h2>
                <p>
                    Te comprometes a suministrar información veraz, completa y actualizada para poder gestionar tu
                    afiliación correctamente. La responsabilidad por la exactitud de los datos suministrados es tuya.
                </p>

                <h2>4. Tarifas</h2>
                <p>
                    Los valores de afiliación y administración se calculan según el plan que elijas y se confirman
                    contigo antes de radicar cualquier trámite. Los precios mostrados en nuestra página web y
                    cotizador se actualizan directamente por {{ $aliado->nombre }} y pueden variar sin previo aviso;
                    el valor final siempre lo confirma un asesor antes de la afiliación.
                </p>

                <h2>5. Limitación de responsabilidad</h2>
                <p>
                    {{ $aliado->nombre }} gestiona los trámites ante las entidades correspondientes, pero los tiempos
                    de aprobación final, cobertura y demás condiciones dependen de cada EPS, ARL, fondo de pensión o
                    caja de compensación, no de nosotros.
                </p>

                <h2>6. Ley aplicable</h2>
                <p>
                    Estos términos se rigen por las leyes de la República de Colombia.
                </p>

                <h2>7. Contacto</h2>
                <p>
                    Para dudas sobre estos términos, contáctanos
                    @if($aliado->correo)en <a href="mailto:{{ $aliado->correo }}">{{ $aliado->correo }}</a>@endif
                    @if($whatsapp) o por WhatsApp al {{ $whatsapp }}@endif.
                </p>

            @else {{-- eliminacion-datos --}}

                <p>
                    Si quieres que eliminemos los datos personales que <strong>{{ $aliado->nombre }}</strong> tiene
                    sobre ti, sigue estas instrucciones.
                </p>

                <h2>1. Solicitar la eliminación de tus datos con nosotros</h2>
                <p>
                    Envíanos tu solicitud indicando tu nombre completo y número de cédula
                    @if($aliado->correo)a <a href="mailto:{{ $aliado->correo }}">{{ $aliado->correo }}</a>@endif
                    @if($whatsapp) o por WhatsApp al {{ $whatsapp }}@endif.
                    Verificaremos tu identidad y eliminaremos tus datos de nuestros sistemas, salvo la información que
                    debamos conservar por obligación legal (por ejemplo, registros de afiliación exigidos por las
                    autoridades del Sistema General de Seguridad Social).
                </p>
                <p>Procesamos estas solicitudes en un plazo máximo de 15 días hábiles, conforme a la Ley 1581 de 2012.</p>

                <h2>2. Si nos contactaste a través de Facebook o Instagram</h2>
                <p>
                    Si además quieres revocar el acceso que le diste a nuestra aplicación en Meta (Facebook/Instagram),
                    puedes hacerlo tú mismo en cualquier momento desde tu cuenta:
                </p>
                <ul>
                    <li>Facebook: Configuración → Aplicaciones y sitios web → busca "{{ $aliado->nombre }}" → Eliminar.</li>
                    <li>Instagram: Configuración → Seguridad → Apps y sitios web → busca "{{ $aliado->nombre }}" → Eliminar.</li>
                </ul>
                <p>
                    Revocar el acceso ahí detiene la conexión con la app, pero si además quieres que eliminemos
                    cualquier dato que hayamos guardado (por ejemplo, si nos escribiste por Messenger o Instagram
                    Direct), envíanos la solicitud como se indica en el punto 1.
                </p>

            @endif

        </div>
    </section>

@endsection
