<?php

/*
 * Representantes legales de las razones sociales de BryNex.
 *
 * - buzon_reenvios: Gmail central que reciben los reenvíos de la DIAN desde
 *   los correos que BryNex no puede leer solo (Hotmail/Outlook: Microsoft
 *   cerró en 2024 la entrada por IMAP con contraseña). En cada Hotmail se pone
 *   una regla que reenvía lo de dian.gov.co a esta cuenta. Su contraseña de
 *   aplicación va como clave «Correo» de cualquier representante o en el
 *   módulo de claves (entidad con GMAIL). Null mientras no exista.
 * - minutos_enlace: cuánto dura el enlace de acceso que manda la DIAN.
 */
return [
    'buzon_reenvios' => null,

    'minutos_enlace' => 60,
];
