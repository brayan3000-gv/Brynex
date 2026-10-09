<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Oferta pública para aliados (brynex.co/aliados)
    |--------------------------------------------------------------------------
    | Los mismos números que muestra la página. La IA de WhatsApp los usa por la
    | herramienta perfilar_aliado para orientar a quien quiere trabajar con el
    | aliado; nunca da otros. Si cambia un precio aquí, cambiarlo también en la
    | vista publico/aliados-planes.blade.php (lo tiene escrito en su JS).
    */
    'url' => 'https://brynex.co/aliados',

    // Las alianzas son para empresas desde este número de afiliados; por debajo
    // se recomienda el Plan Asesor. Entre 'cerca' y el umbral puede empezar y crecer.
    'umbral_empresa' => 100,
    'cerca' => 75,
    'min_plataforma' => 180000,

    'planes' => [
        'esencial' => ['nombre' => 'Alianza Esencial', 'valor' => 800, 'incluye' => 'la plataforma BryNex con sus propias razones sociales'],
        'especifica' => ['nombre' => 'Alianza Específica', 'valor' => 5500, 'incluye' => 'plataforma, automatización y las razones sociales las pone Brygar'],
        'integral' => ['nombre' => 'Alianza Integral', 'valor' => 15000, 'incluye' => 'toda la operación gestionada por Brygar, afiliaciones incluidas'],
    ],

    // Complemento de afiliaciones, una sola vez por contrato nuevo
    'afiliacion' => ['equipo' => 3000, 'brygar' => 6000, 'brygar_volumen' => 5000, 'tramo' => 300],

    // Plan Asesor: la escalera de administración vive en config/asesores.php
    'asesor' => [
        'admon_lista' => 46000,            // administración mensual más común que paga el cliente
        'afiliacion_ejemplo' => 125400,    // afiliación del plan básico (EPS + ARL, riesgo 1)
        'afiliacion_minimo_aliado' => 60000,
        'arranque_meses' => 3,
    ],

    /*
    | A quién se le avisa y quién atiende a cada prospecto, por aliado.
    | Más de 'mayores_de' personas → 'mayor'; el resto → 'menor'. El número es al
    | que se le avisa por WhatsApp; 'user_id' (si existe) es a quien se le asigna
    | la conversación al pasarla a humano; 'compartir' dice si la IA puede darle
    | ese número al prospecto para que llame.
    */
    'contactos' => [
        2 => [ // Brygar
            'mayores_de' => 50,
            // Quien organiza la agenda de las reuniones virtuales, sin importar cuántas personas maneje el prospecto.
            'agenda' => 'menor',
            'mayor' => ['nombre' => 'Brayan García', 'numero' => '3117762689', 'user_id' => 2, 'compartir' => true],
            'menor' => ['nombre' => 'Angela Ortiz', 'numero' => '3123561665', 'user_id' => null, 'compartir' => false],
        ],
    ],

];
