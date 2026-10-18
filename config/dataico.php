<?php

/**
 * Facturación electrónica por API de Dataico.
 *
 * Las credenciales NO viven aquí: van cifradas en `dataico_configuraciones`,
 * una fila por razón social emisora. Este archivo solo tiene lo que es igual
 * para todas: a dónde se pega y con cuánta paciencia.
 */
return [

    'base_url' => env('DATAICO_BASE_URL', 'https://api.dataico.com'),

    'endpoints' => [
        'crear_factura' => '/direct/dataico_api/v2/invoices',
        'consultar_factura' => '/direct/dataico_api/v2/invoices',
        'crear_nota_credito' => '/direct/dataico_api/v2/credit_notes',
    ],

    // Segundos. El API tarda porque valida contra la DIAN en línea.
    'timeout' => (int) env('DATAICO_TIMEOUT', 45),
    'connect_timeout' => (int) env('DATAICO_CONNECT_TIMEOUT', 10),

    // Reintentos del cliente HTTP ante fallas de red o 5xx. Un 4xx NUNCA se
    // reintenta: si la DIAN rechazó por datos, repetir emite basura dos veces.
    'reintentos' => (int) env('DATAICO_REINTENTOS', 2),
    'espera_ms' => (int) env('DATAICO_ESPERA_MS', 1500),

    // Piso de tiempo entre dos llamadas seguidas al API. Emitir un mes son
    // ~200 envíos: sin esto Dataico responde 429 a mitad del lote.
    'pausa_ms' => (int) env('DATAICO_PAUSA_MS', 400),

    // Tope de intentos por factura antes de dejarla quieta esperando revisión.
    'max_intentos' => (int) env('DATAICO_MAX_INTENTOS', 5),

    // Cuántas facturas emite como máximo una corrida del cierre diario.
    'lote_maximo' => (int) env('DATAICO_LOTE_MAXIMO', 400),

    // Desde qué fecha de préstamo la factura sale AL PRESTAR, a crédito, y el
    // pago posterior es solo un recibo de pago de cartera (lo decidió el
    // contador, 17-oct-2026). Los préstamos de antes siguen saliendo cuando
    // el cliente paga: sin este corte, la primera corrida emitía de golpe los
    // 47 que BRYGAR tenía sin facturar desde julio.
    'prestamo_al_prestar_desde' => env('DATAICO_PRESTAMO_AL_PRESTAR_DESDE', '2026-10-18'),

    // Días que se le dan al cliente para pagar una factura a crédito.
    'dias_credito' => (int) env('DATAICO_DIAS_CREDITO', 30),
];
