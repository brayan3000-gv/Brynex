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

    // A dónde escribe el prospecto que quiere BryNex de verdad.
    'contacto_whatsapp' => env('DEMO_CONTACTO_WHATSAPP', '573000000000'),

    // Las únicas bases con las que arranca en modo demo. Nunca `BryNex`.
    'bases_permitidas' => ['BryNex_Demo', 'BryNex_Videos'],
];
