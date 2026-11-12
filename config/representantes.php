<?php

/*
 * Representantes legales de las razones sociales de BryNex.
 *
 * - buzon_reenvios: Gmail central donde llegan los correos de la DIAN de
 *   todos los representantes, sea porque es el correo de su RUT o porque su
 *   buzón los reenvía (filtro from:dian.gov.co). Es el que BryNex lee cuando
 *   el representante no tiene un Gmail propio con contraseña de aplicación.
 *   Su contraseña de aplicación va en el módulo de claves (entidad con GMAIL)
 *   o como clave «Correo» de cualquier representante.
 * - minutos_enlace: cuánto dura el enlace de acceso que manda la DIAN.
 */
return [
    // Un solo buzón para todos (decisión del 12-nov-2026): ya tiene su
    // contraseña de aplicación por el módulo de afiliaciones, y el agente de
    // afiliaciones no toca los correos de dian.gov.co (no es una entidad suya).
    'buzon_reenvios' => 'seguridadsocial.brygar@gmail.com',

    'minutos_enlace' => 60,
];
