<?php

/**
 * Modo demo: la copia de BryNex que se les da a los posibles clientes
 * (demo.brynex.co) para que prueben todo sin que nada salga de verdad.
 *
 * Con `BRYNEX_DEMO=true` la app:
 *   - no deja salir ninguna petición HTTP: las de las ARL, EPS, Enlace,
 *     WhatsApp y la IA se contestan con respuestas simuladas, y cualquier otra
 *     se corta (ver App\Services\Demo\SimuladorHttp);
 *   - apaga los robots reales de portales y ARL y en su lugar ofrece
 *     «Radicar por API (demo)» en cada radicado, que genera un PDF de muestra;
 *   - se niega a arrancar si la base no es una de `bases_permitidas`: un
 *     `.env` mal copiado no puede dejar a un prospecto dentro de producción.
 *
 * Ver App\Providers\DemoServiceProvider.
 */
return [
    'activo' => (bool) env('BRYNEX_DEMO', false),

    // El aliado inventado de la demo («Seguridad Social Demo», ver scripts/demo).
    'aliado_id' => (int) env('DEMO_ALIADO_ID', 100),

    // La cuenta compartida que se muestra en la pantalla de entrada.
    'usuario' => env('DEMO_USUARIO', '9985000001'),
    'clave' => env('DEMO_CLAVE'),

    // Espacios propios: cada prospecto que se registra trabaja en su aliado
    // (101, 102…) y no ve lo de los demás. Se siembran cada noche; con 0 todos
    // entran a la cuenta compartida. Ver App\Services\Demo\EspaciosDemo.
    'espacios' => (int) env('DEMO_ESPACIOS', 0),

    // A dónde escribe el prospecto que quiere BryNex de verdad.
    // El de Brygar: ahí el bot perfila a los prospectos de aliados y les ofrece
    // la alianza (decisión del dueño, 13-nov-2026). Info Avapp no tiene IA activa.
    'contacto_whatsapp' => env('DEMO_CONTACTO_WHATSAPP', '573205400870'),

    // En brynex.co: el ícono 🎬 con los videos del manual al lado de la IA, en
    // cada módulo (App\Http\Middleware\InyectarVideosManual).
    'videos_en_app' => (bool) env('VIDEOS_MANUAL_EN_APP', true),

    // Las únicas bases con las que arranca en modo demo. Nunca `BryNex`.
    'bases_permitidas' => ['BryNex_Demo', 'BryNex_Videos'],

    // El puente con producción (App\Services\Demo\RelevoDemo): ahí se guardan
    // los prospectos y de ahí salen los WhatsApp reales a quien prueba la demo.
    // El mismo secreto va en el .env de los dos lados. Sin secreto, no hay puente
    // y la demo solo simula.
    'relevo' => [
        'secreto' => env('DEMO_RELEVO_SECRETO'),
        'url' => env('DEMO_RELEVO_URL', 'https://brynex.co'),   // lo usa la demo
        // Por dónde sale y se verifica el WhatsApp de la demo: el de BRYGAR, el mismo
        // del botón «Quiero BryNex», donde el bot atiende (decisión del dueño, 13-nov-2026).
        // Así el prospecto tiene UN solo chat. Antes era Info Avapp, sin IA.
        'aliado_envio' => (int) env('DEMO_RELEVO_ALIADO', 2),
        'numero' => env('DEMO_RELEVO_NUMERO', '573205400870'),
        'max_envios' => (int) env('DEMO_RELEVO_MAX_ENVIOS', 5),
    ],
];
