<?php

/*
 * Correos de afiliación a las EPS.
 *
 * - buzones: cuenta de Gmail de cada aliado desde la que salen los correos. La
 *   contraseña de aplicación vive en el módulo de claves (misma cuenta en
 *   "usuario", entidad con "GMAIL").
 * - asesores: a quién va el correo si la razón social no tiene "correo de la
 *   entidad" en su clave del portal. El reemplazo se ofrece cuando el asesor
 *   está de vacaciones (Juan Torres las anunció del 19-ago al 8-sep-2026).
 */
return [
    'buzones' => [
        2 => 'seguridadsocial.brygar@gmail.com',
    ],

    'asesores' => [
        'sos' => [
            'nombre_entidad' => 'S.O.S.',
            'principal'      => ['nombre' => 'Juan Carlos Torres', 'correo' => 'jtorres.qta@sos.com.co'],
            'reemplazo'      => ['nombre' => 'Marien Ruiz Mina', 'correo' => 'mrmina@sos.com.co'],
        ],
        // EPS sin portal de empleador: se afilian solo por correo (CorreoAsesorEpsService).
        // Comfenalco Valle (delagente): la asesora responde el mismo día con el
        // certificado o el radicado en PDF; pide el formulario como "Reporte de novedades".
        'comfenalco' => [
            'nombre_entidad'    => 'Comfenalco Valle',
            'nombre_formulario' => 'Comfenalco Valle',
            'codigo_eps'        => 'EPS012',
            'principal'         => ['nombre' => 'Lola Baena', 'correo' => 'lbaena88@gmail.com'],
            'reemplazo'         => null,
        ],
        // Emssanar: buzón institucional (no asesor). Plan B del portal Boxalud;
        // responde con la "Certificación de relación laboral" en PDF.
        'emssanar' => [
            'nombre_entidad'    => 'Emssanar',
            'nombre_formulario' => 'Emssanar',
            'codigo_eps'        => 'ESSC18',
            'principal'         => ['nombre' => 'Afiliaciones Emssanar', 'correo' => 'afiliate@emssanareps.co'],
            'reemplazo'         => null,
        ],
    ],

    // Dominios de las entidades cuyos correos revisa el agente del buzón. Lo que
    // llega de otros remitentes no se toca, salvo que responda un correo enviado
    // desde BryNex (hay asesores que responden desde Gmail).
    'entidades' => [
        'sos.com.co'            => ['clave' => 'sos', 'tipo' => 'eps', 'nombre' => 'S.O.S.'],
        'nuevaeps.com.co'       => ['clave' => 'nueva_eps', 'tipo' => 'eps', 'nombre' => 'Nueva EPS'],
        'saludtotal.com.co'     => ['clave' => 'salud_total', 'tipo' => 'eps', 'nombre' => 'Salud Total'],
        'epssura.com'           => ['clave' => 'sura', 'tipo' => 'eps', 'nombre' => 'EPS SURA'],
        'sura.com.co'           => ['clave' => 'sura', 'tipo' => 'eps_arl', 'nombre' => 'SURA'],
        'comunicaciones.sura.com' => ['clave' => 'sura', 'tipo' => 'eps_arl', 'nombre' => 'SURA'],
        'epssanitas.com'        => ['clave' => 'sanitas', 'tipo' => 'eps', 'nombre' => 'Sanitas'],
        'colsanitas.com'        => ['clave' => 'sanitas', 'tipo' => 'eps', 'nombre' => 'Sanitas'],
        // Avisos de trámites en línea de Sanitas (tramitesenlinea@epssanitas.notify-it.com).
        'epssanitas.notify-it.com' => ['clave' => 'sanitas', 'tipo' => 'eps', 'nombre' => 'Sanitas'],
        'comfenalcovalle.com.co' => ['clave' => 'comfenalco', 'tipo' => 'eps_caja', 'nombre' => 'Comfenalco Valle'],
        'epsdelagente.com.co'   => ['clave' => 'comfenalco', 'tipo' => 'eps', 'nombre' => 'Comfenalco Valle (delagente)'],
        'comfandi.com.co'       => ['clave' => 'comfandi', 'tipo' => 'caja', 'nombre' => 'Comfandi'],
        'emssanar.org.co'       => ['clave' => 'emssanar', 'tipo' => 'eps', 'nombre' => 'Emssanar'],
        'emssanareps.co'        => ['clave' => 'emssanar', 'tipo' => 'eps', 'nombre' => 'Emssanar'],
        'coosalud.com'          => ['clave' => 'coosalud', 'tipo' => 'eps', 'nombre' => 'Coosalud'],
        'asmetsalud.com'        => ['clave' => 'asmet', 'tipo' => 'eps', 'nombre' => 'Asmet Salud'],
        'famisanar.com.co'      => ['clave' => 'famisanar', 'tipo' => 'eps', 'nombre' => 'Famisanar'],
        'compensar.com'         => ['clave' => 'compensar', 'tipo' => 'eps_caja', 'nombre' => 'Compensar'],
        'positiva.gov.co'       => ['clave' => 'positiva', 'tipo' => 'arl', 'nombre' => 'Positiva'],
        'segurosbolivar.com'    => ['clave' => 'bolivar', 'tipo' => 'arl', 'nombre' => 'Seguros Bolívar'],
        'axacolpatria.co'       => ['clave' => 'colpatria', 'tipo' => 'arl', 'nombre' => 'AXA Colpatria'],
        'colmena.com.co'        => ['clave' => 'colmena', 'tipo' => 'arl', 'nombre' => 'Colmena'],
        'colpensiones.gov.co'   => ['clave' => 'colpensiones', 'tipo' => 'afp', 'nombre' => 'Colpensiones'],
        'porvenir.com.co'       => ['clave' => 'porvenir', 'tipo' => 'afp', 'nombre' => 'Porvenir'],
        'proteccion.com.co'     => ['clave' => 'proteccion', 'tipo' => 'afp', 'nombre' => 'Protección'],
    ],

    // Remitentes masivos de esas entidades (boletines, publicidad): se ignoran.
    // Se compara con la dirección completa (suracomunicaciones@, epssura@comunicaciones.sura.com…).
    'remitentes_masivos' => '/no-?reply|noresponder|comunica|masivo|mailing|boletin|newsletter|mercadeo|marketing|publicidad|encuesta/i',

    // Plazo de respuesta del asesor (regla de Brygar): si se envía en la mañana de
    // un día hábil, se espera hasta las 6:00 p. m. de ese día; si se envía en la
    // tarde (o en día no hábil), hasta las 12:00 m. del siguiente día hábil.
    'corte_manana'    => 12,
    'vence_manana'    => 18,
    'vence_siguiente' => 12,

    // A qué WhatsApp llegan el resumen diario y los avisos que no tienen dueño.
    'whatsapp_avisos' => [
        2 => ['3158204135', '3117762689'],
    ],
];
