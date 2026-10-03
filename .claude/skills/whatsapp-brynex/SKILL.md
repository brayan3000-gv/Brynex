---
name: whatsapp-brynex
description: >
  Integración de WhatsApp Business API en Brynex: conversaciones, plantillas, envíos
  masivos y webhooks. Actívate cuando el usuario mencione: WhatsApp, mensajes masivos,
  plantilla WhatsApp, webhook Meta, conversación WhatsApp, WhatsappApiService,
  WhatsappWebhookService, WhatsappChatController, WhatsappMasivoController,
  envío masivo, cobros por WhatsApp.
---

# Skill: WhatsApp Brynex

## Arquitectura del Módulo

```
Controllers:
├── WhatsappChatController.php    ← Conversaciones individuales
├── WhatsappConfigController.php  ← Configuración por aliado  
├── WhatsappMasivoController.php  ← Envíos masivos
├── WhatsappPlantillaController.php ← CRUD de plantillas
└── WhatsappWebhookController.php ← Webhook Meta (mensajes entrantes)

Services:
├── WhatsappApiService.php        ← Llamadas a la API de Meta
└── WhatsappWebhookService.php    ← Procesamiento de eventos

Modelos:
├── WhatsappConfig.php            ← Config por aliado (token, número, etc.)
├── WhatsappConversacion.php      ← Hilo de conversación con un cliente
├── WhatsappMensaje.php           ← Mensajes individuales
├── WhatsappPlantilla.php         ← Plantillas aprobadas por Meta
├── WhatsappEnvioMasivo.php       ← Campaña de envío masivo
└── WhatsappEnvioMasivoDetalle.php ← Detalle por destinatario
```

## Configuración por Aliado (`WhatsappConfig`)

```php
// Columnas de configuración:
// - aliado_id
// - phone_number_id     ← ID del número en Meta
// - access_token        ← Token de acceso Meta API
// - webhook_verify_token
// - activo (boolean)
// - plantilla_cobros_id ← Plantilla para mensajes de cobro
// - campos de configuración de cobros automáticos
```

## Flujo de Envío Masivo

1. Crear `WhatsappEnvioMasivo` con selección de destinatarios
2. Generar `WhatsappEnvioMasivoDetalle` por cada destinatario
3. Procesar via Job en cola (`QUEUE_CONNECTION=sync` en local)
4. `WhatsappApiService::enviarPlantilla()` → API Meta
5. Actualizar estado: `pendiente` → `enviado` / `fallido`

## Integración con Cobros

El módulo de cobros (`CobrosController`) usa WhatsApp para:
- Enviar recordatorios de factura pendiente
- Adjuntar PDF de factura en el mensaje
- Registrar en `BitacoraCobro` el envío

## Webhook (Mensajes Entrantes)

```
POST /webhook/whatsapp
  → WhatsappWebhookController::receive()
  → WhatsappWebhookService::processMessage()
    → Busca/crea WhatsappConversacion
    → Crea WhatsappMensaje
    → Emite evento via Laravel Reverb (WebSockets)
```

## Vistas del Módulo

```
resources/views/admin/whatsapp/
├── chat/             ← Chat en tiempo real (Reverb)
├── config/           ← Configuración del número
├── masivo/           ← Gestión de envíos masivos
└── plantillas/       ← CRUD de plantillas Meta
```

## Quién está esperando respuesta (oct-2026)

- `WhatsappEsperandoRespuesta` decide quién espera (último mensaje del cliente que no
  sea despedida, o `pendiente_atencion`), tope `DIAS_MAX_ESPERA` = 30. La usan la
  pestaña **⏳ Esperando** del inbox (chip con lo que lleva y cuánto queda de ventana)
  y el comando `whatsapp:sin-respuesta`, que corre 8/11/14/17 L-S para todos los
  aliados con WhatsApp y avisa a `services.whatsapp.pendientes_por_aliado` o, si no,
  al `whatsapp`/`celular` de la ficha del aliado. Solo repite en el día si hay alguien
  nuevo o a punto de vencerse la ventana.
- El aviso sale por la cuenta con la que el aliado hace sus envíos
  (`AlertaOperativaService::enviarDesdeAliado`): la suya o la compartida de BryNex,
  nunca la de Brygar. Necesita la plantilla `notificar_brynex` aprobada en esa cuenta
  (`php artisan whatsapp:plantilla-aviso`; con `--estado` solo consulta). Los números
  del propio aliado (`numerosDelAliado`: ficha, línea y destinatarios del aviso) no
  cuentan como esperando, no reciben acuse y en el número compartido caen a su aliado.
- `ultimosMensajes()` trae el último mensaje por conversación en UNA consulta y con
  `contenido` recortado a 300 caracteres: `nvarchar(max)` se baja fila por fila.
  El `with(['mensajes' => limit(1)])` NO sirve en Laravel 10 (el límite es global).
- `WhatsappAcuseRecepcionJob`: en aliados sin IA de WhatsApp, 5 min después de un
  mensaje sin respuesta manda «🤖 Respuesta automática: recibimos tu mensaje…» y marca
  pendiente (`marcarPendiente`, sin tocar `bot_activo`). Se apaga con
  `WHATSAPP_ACUSE_SIN_BOT=false` o por aliado con `WHATSAPP_ACUSE_SIN_BOT_EXCLUIR`.
- **✔ Atendido por otro medio** (`chat.atendida`): el asesor contestó desde su celular,
  por llamada o en persona. Crea un `WhatsappMensaje` tipo `nota` (saliente, sin
  `wa_message_id`, no se envía a Meta) y la conversación sale de «Esperando». Se pinta
  como nota amarilla centrada en el chat.
- Número compartido: un mensaje sin conversación se resuelve por el celular en
  clientes/empresas (`WhatsappBandejaCompartida::resolverAliado`); si no se sabe, cae
  al inbox del aliado BryNex (id 1) como pendiente y se mueve con **🔀 Mover a aliado**
  (solo `es_brynex`, ruta `chat.mover_aliado`). Ya no se bota ningún mensaje.

## Notas Importantes

- Las plantillas deben estar **aprobadas por Meta** antes de usarse
- Los mensajes de plantilla solo aplican fuera de la ventana de 24h de conversación
- `laravel/reverb` maneja los WebSockets para el chat en tiempo real
- Índices de performance en `whatsapp_mensajes`: `conversacion_id`, `created_at`
