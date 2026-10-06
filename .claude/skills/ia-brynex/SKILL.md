---
name: ia-brynex
description: >
  Asistente de IA conversacional de Brynex: proveedor configurable, tool use,
  base de conocimiento con aprobación de entrenador y WhatsApp. Actívate
  cuando el usuario mencione: asistente IA, chatbot, IaProviderFactory,
  IaConocimiento, entrenador, herramienta IA, tool, IaConsumo, costo de IA,
  AsistenteIaController, prompt del asistente, IaSimuladorController.
---

# Skill: Asistente de IA — Brynex

## Arquitectura: proveedor intercambiable + tool use

```
IaProviderFactory::make($proveedor)  →  ClaudeProvider | GeminiProvider | OpenAiProvider
                                          (implementan IaProviderInterface)
```

El proveedor es configurable por aliado (`IaConfiguracionAliado`), no fijo en
código. Al añadir un proveedor nuevo: crear la clase en
`app/Services/Ia/Providers/`, implementar `IaProviderInterface`, registrarla
en el `match()` de `IaProviderFactory`.

## Tools (function calling)

`app/Services/Ia/Tools/` — cada archivo implementa `IaToolInterface`
(`nombre()`, `descripcion()`, `schema()`, `ejecutar()`). El LLM decide cuándo
llamarlas según la `descripcion()`, así que esa descripción **es** la
especificación de comportamiento — hay que redactarla con cuidado, no como
comentario decorativo.

Tools existentes: `BuscarConocimientoTool`, `BuscarInternetTool`,
`CatalogoModulosTool`, `ChequeoSeguridadSocialTool`, `ConsultarClienteTool`,
`ConsultarParametrosTool`, `CotizarPlanTool` / `CotizarPlanPublicoTool`,
`EnviarPlanillaTool`, `EnviarTablaPlanesTool`, `HablarConAsesorTool`,
`NoContactarTool`, `PerfilarAliadoTool` (prospectos que quieren trabajar con
el aliado: ver la sección en [[whatsapp-brynex]]), `PreguntarEntrenadorTool`.

Para agregar una tool nueva: implementar `IaToolInterface` en
`app/Services/Ia/Tools/`, registrarla donde se arma la lista de tools
disponibles (`AsistenteIaService`) — el proveedor la recibe como parte del
schema de function calling.

## Conocimiento: con aprobación humana, no autoaprendizaje libre

```
IaConocimiento          ← base de conocimiento activa (lo que la IA puede citar)
IaPreguntaEntrenador     ← cola de preguntas que la IA NO supo responder
```

El flujo es deliberado: cuando ni `BuscarConocimientoTool` ni
`BuscarInternetTool` dan una respuesta confiable, la IA usa
`PreguntarEntrenadorTool` en vez de inventar — registra la pregunta en
`IaPreguntaEntrenador` con `estado='pendiente')` para que un humano
(el "entrenador") la responda. Solo entonces esa respuesta puede pasar a
`IaConocimiento` para consultas futuras. **No agregar un mecanismo que meta
respuestas de la IA directamente en `IaConocimiento` sin ese paso humano** —
es una decisión de producto, no un descuido.

`IaConocimiento` tiene vigencia (`vigente_desde`/`vigente_hasta`) y
`categoria`/`fuente` — al consultarla, filtrar por vigente y por
`aliado_id` (el conocimiento es por aliado, no global).

## Consumo y costo

`IaConsumo` registra tokens de entrada/salida y `costo_estimado_usd` por
`aliado_id` + `canal` + `proveedor` + `modelo`. Cualquier llamada nueva al
LLM debe loguear aquí — es lo que alimenta `BrynexConsumoController` (cobro
a aliados por uso de IA).

## Canales

- **Admin** (`/asistente-ia/chat`, `AsistenteIaController`) — chat interno
  para el equipo del aliado.
- **WhatsApp** — mismo `AsistenteIaService`, pero disparado desde
  `WhatsappWebhookService`/`ClienteWhatsappResolver` (resuelve qué cliente
  está escribiendo por su número antes de armar el contexto de la conversación).
- **Simulador** (`/ia/simulador`, `IaSimuladorController`) — para probar
  prompts/tools sin gastar en un canal real; revisar aquí antes de tocar
  el prompt base si se quiere ver el efecto sin arriesgar una conversación real.

## Configuración y visibilidad

`IaConfigController` + `IaConocimientoController` (rutas `ia/config`,
`ia/conocimiento`) son administración; solo visibles para BryNex
(módulo interno, no para los aliados) — ver memoria de proyecto: decisión
explícita de que este panel es exclusivo de Brygar/BryNex, no un feature que
se le vende al aliado.
