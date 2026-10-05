<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Business API (Meta Cloud API) — Cuenta Global Brynex
    |--------------------------------------------------------------------------
    | Los aliados que no tengan cuenta propia usarán estas credenciales.
    | Los aliados con cuenta propia las configuran desde el panel (WhatsappConfig).
    |
    | waba_id:         WhatsApp Business Account ID de Meta
    | phone_number_id: ID del número de teléfono registrado en Meta
    | token:           Token de acceso permanente de Meta
    | numero:          Número visible del WhatsApp Business Brynex
    | app_secret:      App Secret de la Meta App (para validar firma HMAC del webhook)
    | webhook_verify_token: Token que Meta usa para verificar el webhook (GET)
    */
    /*
    | GARVIS, el asistente de Brayan (repo brayan3000-gv/garvis). Sus mensajes
    | de texto al número de Brygar se desvían al repo en vez de entrar a las
    | conversaciones. Ver App\Services\GarvisService.
    */
    'garvis' => [
        'activo' => env('GARVIS_ACTIVO', false),
        'numero' => env('GARVIS_NUMERO', env('WHATSAPP_ALERTAS_NUMERO', '3117762689')),
        'repo' => env('GARVIS_REPO', 'brayan3000-gv/garvis'),
        // Fine-grained, solo el repo garvis, Issues: lectura y escritura.
        'github_token' => env('GARVIS_GITHUB_TOKEN'),
    ],

    'whatsapp' => [
        'waba_id' => env('WHATSAPP_BRYNEX_WABA_ID'),
        'phone_number_id' => env('WHATSAPP_BRYNEX_PHONE_NUMBER_ID'),
        'token' => env('WHATSAPP_BRYNEX_TOKEN'),
        'numero' => env('WHATSAPP_BRYNEX_NUMERO'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        // Destino de las alertas operativas a BryNex (backups, accesos raros).
        'alertas_numero' => env('WHATSAPP_ALERTAS_NUMERO', '3117762689'),

        // A quién le llega el aviso de conversaciones esperando respuesta. Es lista aparte
        // porque no lo atiende quien recibe las alertas de infraestructura: aquí van los
        // números de quienes de verdad contestan. Separados por coma.
        //
        // Por aliado: `pendientes_por_aliado` manda; si el aliado no está, se usa el
        // WhatsApp (o celular) guardado en su ficha. `pendientes_numeros` queda para Brygar
        // (aliado 2), que es donde arrancó el aviso. `pendientes_copia` recibe el aviso de
        // TODOS los aliados, para que BryNex vea que a nadie lo dejan colgado.
        'pendientes_numeros' => env('WHATSAPP_PENDIENTES_NUMEROS', '3117762689'),
        'pendientes_por_aliado' => [
            2 => env('WHATSAPP_PENDIENTES_NUMEROS', '3117762689'),
            1 => env('WHATSAPP_PENDIENTES_NUMEROS', '3117762689'),
        ],
        'pendientes_copia' => env('WHATSAPP_PENDIENTES_COPIA', ''),

        // Acuse automático ("recibimos tu mensaje, ya te atendemos") en los aliados que no
        // tienen IA en WhatsApp, cuando pasan unos minutos sin que nadie conteste.
        // `acuse_sin_bot_excluir`: ids de aliado separados por coma que no lo quieren.
        // Horas que una conversación asignada puede tener al cliente esperando antes de
        // volver sola al inbox general (whatsapp:liberar-sin-atender). 0 lo apaga.
        'liberar_horas' => env('WHATSAPP_LIBERAR_HORAS', 4),

        'acuse_sin_bot' => env('WHATSAPP_ACUSE_SIN_BOT', true),
        'acuse_sin_bot_excluir' => env('WHATSAPP_ACUSE_SIN_BOT_EXCLUIR', ''),
        // Rechazar los payloads cuya firma no valide. Arranca en false a
        // propósito: si algún número llega firmado por otra app de Meta (un
        // aliado con su propia cuenta), su firma no cuadra con este App Secret
        // y encenderlo lo dejaría sin recibir mensajes. El propio webhook avisa,
        // número por número, cuáles no cuadran; un día sin avisos es la señal
        // de que es seguro ponerlo en true.
        'webhook_estricto' => env('WHATSAPP_WEBHOOK_ESTRICTO', false),
        'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN', 'brynex_wh_secret_2026'),
    ],

    // APIs PILA de Enlace Operativo (SuAporte / Arus Enlace).
    // Las credenciales reales se guardan cifradas en `operadores_credenciales`
    // (la BD sí se sincroniza a producción, el .env no). Lo de aquí es solo
    // fallback para pruebas locales. Ver App\Services\SuaporteApiService.
    'suaporte' => [
        'api_url' => env('SUAPORTE_API_URL', 'https://www.suaporte.com.co/api'),
        'auth_url' => env('SUAPORTE_AUTH_URL', 'https://www.suaporte.com.co/auth'),
        'usuario' => env('SUAPORTE_USUARIO'),        // tipo+número de documento, ej: CC1234567
        'contrasena' => env('SUAPORTE_CONTRASENA'),     // 4 dígitos numéricos
        'clave_secreta' => env('SUAPORTE_CLAVE_SECRETA'),  // generada en el tablero, vence al año
        'timeout' => env('SUAPORTE_TIMEOUT', 120),

        // Consultar BDUA/RUAF (afiliación a salud/pensión) es una operación
        // de solo lectura que no exige autorización por aportante — cualquier
        // cuenta de operador la puede consultar para cualquier cédula. Si el
        // aliado activo no tiene credenciales propias, se usan las del aliado
        // BryNex como respaldo, para que la verificación de cédula al crear un
        // cliente funcione en todos los aliados sin repetir la configuración.
        // NO aplica a liquidar planillas: eso sí exige la cuenta autorizada
        // sobre el aportante específico.
        'aliado_fallback_ruaf' => env('SUAPORTE_ALIADO_FALLBACK_RUAF', 1),
    ],

    // Binarios de FFmpeg para el overlay de texto animado + logo sobre video (VideoOverlayFfmpeg).
    'ffmpeg' => [
        'binario' => env('FFMPEG_BINARY', 'ffmpeg'),
        'ffprobe' => env('FFPROBE_BINARY', 'ffprobe'),
    ],

    /*
    | Meta (Facebook/Instagram) — lo que no es específico de WhatsApp.
    |
    | app_secret: clave secreta de la app con la que se crean los anuncios (BRYGAR). Solo se
    |             usa para canjear un token de usuario corto por uno de ~60 días, y para
    |             renovarlo antes de que venza. Sin esto el token hay que alargarlo a mano en
    |             el depurador de Meta, que es donde se pierde todo el mundo.
    |             Se saca de: Mis aplicaciones → la app → Configuración → Básica.
    */
    'meta' => [
        'app_secret' => env('META_APP_SECRET'),
    ],

    // Worker de Node que maneja el navegador contra ADRES (ver adres-worker/).
    // Debe escuchar solo en loopback: puede consultar el historial de salud de
    // cualquier cédula, así que no puede quedar expuesto en red.
    'adres_worker' => [
        'url' => env('ADRES_WORKER_URL', 'http://127.0.0.1:8801'),
        'token' => env('ADRES_WORKER_TOKEN'),
        'timeout' => env('ADRES_WORKER_TIMEOUT', 90),
    ],

    // Salida por IP colombiana para los sitios que rechazan la IP del servidor
    // (datacenter de netcup, geolocalizada en EE. UU.). El primero fue Nueva
    // EPS: 403 "El contenido a este sitio está Restringido". Es un solo proxy
    // para todo lo que lo necesite en el servidor, no uno por integración.
    // Formato: http://usuario:clave@host:puerto
    'proxy_colombia' => [
        'url' => env('PROXY_COLOMBIA'),
    ],

    // Nueva EPS tampoco acepta IPs de datacenter "colombianas" (se probó una de
    // IPRoyal): hace falta la IP de un ISP real. Un PC de la oficina mantiene un
    // túnel SSH inverso con destino fijo (`-R 127.0.0.1:18443:portal.nuevaeps.com.co:443`)
    // y Chrome manda ese dominio al puerto local. El servidor no alcanza nada más
    // de la red de la oficina. Formato: 127.0.0.1:18443 — ver scripts/tunel-nueva-eps/.
    'nueva_eps' => [
        'tunel' => env('NUEVA_EPS_TUNEL'),
    ],

    // El portal de Comfenalco solo atiende a un navegador con ventana desde una
    // conexión colombiana, así que la corrida del servidor sale por el mismo
    // túnel de la oficina que Nueva EPS. Un puerto por dominio: el destino es
    // fijo para que el servidor no alcance nada más de esa red.
    'comfenalco' => [
        'tunel' => env('COMFENALCO_TUNEL'),
        'tunel_auth' => env('COMFENALCO_TUNEL_AUTH'),
    ],

    // Llave global de Gemini para material gráfico (brynex:imagen-gemini);
    // el asistente de IA sigue usando la llave por aliado.
    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
    ],

];
