<?php

namespace App\Services\Ia;

use App\Models\Aliado;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Empresa;
use App\Models\IaConfiguracionAliado;
use App\Models\IaConsumo;
use App\Models\IaConversacion;
use App\Models\IaMensaje;
use App\Models\MarketingCampana;
use App\Models\WhatsappConversacion;
use App\Models\WhatsappMensaje;
use App\Models\WhatsappPlantilla;
use App\Services\Ia\Tools\BuscarConocimientoTool;
use App\Services\Ia\Tools\BuscarInternetTool;
use App\Services\Ia\Tools\CatalogoModulosTool;
use App\Services\Ia\Tools\ChequeoSeguridadSocialTool;
use App\Services\Ia\Tools\ConsultarClienteTool;
use App\Services\Ia\Tools\ConsultarParametrosTool;
use App\Services\Ia\Tools\CotizarPlanPublicoTool;
use App\Services\Ia\Tools\CotizarPlanTool;
use App\Services\Ia\Tools\EnviarPlanillaTool;
use App\Services\Ia\Tools\EnviarTablaPlanesTool;
use App\Services\Ia\Tools\HablarConAsesorTool;
use App\Services\Ia\Tools\IaToolInterface;
use App\Services\Ia\Tools\NoContactarTool;
use App\Services\Ia\Tools\PerfilarAliadoTool;
use App\Services\Ia\Tools\PreguntarEntrenadorTool;
use Illuminate\Support\Facades\Log;

class AsistenteIaService
{
    private const MAX_ITERACIONES_TOOL = 5;

    private const MENSAJES_HISTORIAL = 12; // últimos N mensajes de contexto

    /**
     * Procesa un mensaje del usuario en el canal web (empleados/asesores autenticados)
     * y devuelve la respuesta del asistente, con las herramientas completas.
     *
     * @return array{respuesta: string, acciones: array, conversacion_id: int}
     */
    public function responderWeb(int $alidoId, int $userId, string $mensajeUsuario): array
    {
        $config = IaConfiguracionAliado::paraAliado($alidoId);
        if (! $config->activo_web) {
            throw new \RuntimeException('El asistente IA no está activo para este aliado.');
        }

        $credenciales = $config->credencialesEfectivas();
        if (empty($credenciales['api_key'])) {
            throw new \RuntimeException('El asistente IA no tiene una clave de API configurada. Contacta a BryNex.');
        }

        $conversacion = IaConversacion::paraUsuarioWeb($alidoId, $userId);

        $aliado = Aliado::find($alidoId);
        $systemPrompt = $this->construirSystemPromptWeb($aliado?->nombre ?? 'tu aliado', $config->nombreBot());
        $tools = $this->construirToolsWeb($credenciales);
        $contextoExtra = ['canal' => 'web'];

        $resultado = $this->ejecutarTurno($conversacion, $mensajeUsuario, $systemPrompt, $tools, $credenciales, $contextoExtra, 'web');

        return [
            'respuesta' => $resultado['respuesta'],
            'acciones' => $resultado['acciones'],
            'conversacion_id' => $conversacion->id,
            'nombre_bot' => $config->nombreBot(),
        ];
    }

    /**
     * Procesa un mensaje entrante de WhatsApp (cliente externo) y devuelve la respuesta
     * del asistente, con un set de herramientas restringido: nunca expone parámetros
     * internos del aliado ni navegación del sistema.
     *
     * @return array{respuesta: string, conversacion_id: int, herramientas_usadas: string[]}
     */
    public function responderWhatsapp(
        int $alidoId,
        string $telefono,
        string $mensajeUsuario,
        ?int $waConversacionId,
        ?string $origenCampana = null,
        ?string $origenCampanaCategoria = null,
        ?int $origenCampanaId = null,
        bool $modoPrueba = false
    ): array {
        $config = IaConfiguracionAliado::paraAliado($alidoId);
        if (! $config->activo_whatsapp) {
            throw new \RuntimeException('El asistente IA no está activo en WhatsApp para este aliado.');
        }

        $credenciales = $config->credencialesEfectivas();
        if (empty($credenciales['api_key'])) {
            throw new \RuntimeException('El asistente IA no tiene una clave de API configurada.');
        }

        $conversacion = IaConversacion::paraTelefono($alidoId, $telefono);
        $waConversacion = $waConversacionId ? WhatsappConversacion::find($waConversacionId) : null;
        $clienteInfo = $this->resolverContactoExistente($alidoId, $telefono, $waConversacion);
        $campana = $origenCampanaId ? MarketingCampana::find($origenCampanaId) : null;
        $ultimoEnvio = $this->resolverUltimaPlantillaEnviada($waConversacionId);
        // Pieza de publicidad que originó la conversación (el "ref: P##" del anuncio). Un lead
        // que llega de pauta se paga, así que no puede recibir el mismo saludo tibio que un
        // cliente de toda la vida: ver el bloque de guion en construirSystemPromptWhatsapp.
        $piezaOrigen = $waConversacion?->origen_publicacion_id
            ? \App\Models\Publicacion::find($waConversacion->origen_publicacion_id)
            : null;

        // Prospecto que quiere trabajar con nosotros: la IA necesita saber dónde quedó la
        // conversación, incluido lo que escribió una persona del equipo (eso no está en su
        // propio historial), para retomar sin repetir preguntas ni prometer lo mismo otra vez.
        $prospectos = app(\App\Services\ProspectoAliadoService::class);
        $retomaAliado = ($waConversacion && $prospectos->esProspectoAliado($waConversacion))
            ? $prospectos->resumenRetoma($waConversacion)
            : '';

        $aliado = Aliado::find($alidoId);
        $systemPrompt = $this->construirSystemPromptWhatsapp(
            $aliado?->nombre ?? 'nuestra empresa',
            $config->nombreBot(),
            $origenCampana,
            $origenCampanaCategoria,
            $clienteInfo,
            $campana,
            $ultimoEnvio,
            $piezaOrigen,
            $retomaAliado
        );
        $tools = $this->construirToolsWhatsapp($credenciales);
        // modo_prueba: usado por el simulador de conversación (/brynex/ia/simulador) para que las
        // tools con efectos externos reales (ej. chequeo_seguridad_social contra ADRES, o el
        // registro de prospecto) se simulen en vez de ejecutarse de verdad.
        $contextoExtra = ['canal' => 'whatsapp', 'wa_conversacion_id' => $waConversacionId, 'modo_prueba' => $modoPrueba];

        $resultado = $this->ejecutarTurno($conversacion, $mensajeUsuario, $systemPrompt, $tools, $credenciales, $contextoExtra, 'whatsapp');

        return [
            'respuesta' => $resultado['respuesta'],
            'conversacion_id' => $conversacion->id,
            'nombre_bot' => $config->nombreBot(),
            'herramientas_usadas' => $resultado['herramientas_usadas'],
        ];
    }

    /**
     * Herramientas para empleados/asesores (canal web): acceso completo a consultas y navegación.
     * buscar_internet solo se agrega si el proveedor es Claude (server tool nativo de Anthropic).
     *
     * @return IaToolInterface[]
     */
    private function construirToolsWeb(array $credenciales): array
    {
        $tools = [
            new CotizarPlanTool,
            new ConsultarParametrosTool,
            new CatalogoModulosTool,
            new BuscarConocimientoTool,
        ];

        if (($credenciales['proveedor'] ?? null) === 'claude') {
            $tools[] = new BuscarInternetTool;
        }

        $tools[] = new PreguntarEntrenadorTool;

        return $tools;
    }

    /**
     * Herramientas para clientes externos (canal WhatsApp): cotización simplificada
     * (sin desglose interno ni comisiones), conocimiento y opción de pasar con un humano.
     * Nunca incluye consultar_parametros ni catalogo_modulos (son información/navegación interna).
     *
     * @return IaToolInterface[]
     */
    /**
     * ¿La pieza es de reclutamiento de asesores?
     *
     * Se mira el tema, que es lo que describe la pieza. Un asesor y un cliente llegan por el
     * mismo WhatsApp, así que sin esto la IA le cotizaría un plan a alguien que viene a
     * preguntar por comisiones.
     */
    public static function esPiezaDeAsesores(\App\Models\Publicacion $pieza): bool
    {
        // El COPY entra en la comparación además del tema y el título: el tema es el campo más
        // fácil de perder —las piezas #90 y #91 se recrearon con él vacío, y la #91 dejó de
        // reconocerse pese a estar pautada— mientras que el copy es el texto del anuncio y
        // siempre está. Sin esto, un asesor recibe el guion de cliente y nadie se entera.
        // Sin tildes, porque las señales se comparan como texto plano: "comision" NO casaba con
        // "comisión" en singular, así que una pieza que dijera «mejor comisión» se clasificaba
        // como de clientes y el asesor recibía el guion equivocado.
        $texto = strtr(
            mb_strtolower(($pieza->tema ?? '').' '.($pieza->titulo ?? '').' '.($pieza->copy ?? ''), 'UTF-8'),
            ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']
        );

        // No solo el asesor de seguridad social: el público es quien YA tiene la relación con el
        // cliente y hoy no maneja las afiliaciones —contadores, asesores de EPS o de seguros,
        // oficinas con cartera—. A todos se les atiende con el mismo guion.
        //
        // Palabra COMPLETA, no pedazo: con str_contains, "asesoría sin costo" —que dicen varias
        // piezas de clientes— contaba como "asesor". Así la #81, de confianza para clientes, cayó
        // en el conjunto de asesores (sep-2026) y gastó ahí $13.852.
        $esDelOficio = preg_match(
            '/(?<!\p{L})(asesor|asesora|asesores|asesoras|contador|contadora|contadores|contadoras|contabilidad)(?!\p{L})/u',
            $texto
        ) === 1;

        if (! $esDelOficio) {
            return false;
        }

        // Las señales de que se le habla a alguien con CARTERA, no a alguien que quiere afiliarse.
        // "trabaj" salió de la lista: casaba con "trabajadores", que está en medio copy de clientes.
        //
        // "afilia clientes" entró por la pieza #97 («¿tu empresa u oficina ya afilia clientes?»),
        // que hablaba de tarifas por afiliación y administración mensual sin nombrar comisión ni
        // cartera, y por eso iba a caer en el conjunto de clientes. Se probó contra las 87 piezas
        // del histórico antes de agregarla: mueve esa sola y ninguna más.
        foreach (['comision', 'cartera', 'propia empresa', 'empresa propia', 'afilia clientes', 'afilian clientes'] as $senal) {
            if (str_contains($texto, $senal)) {
                return true;
            }
        }

        return false;
    }

    private function construirToolsWhatsapp(array $credenciales): array
    {
        $tools = [
            new CotizarPlanPublicoTool,
            new BuscarConocimientoTool,
        ];

        if (($credenciales['proveedor'] ?? null) === 'claude') {
            $tools[] = new BuscarInternetTool;
        }

        $tools[] = new PreguntarEntrenadorTool;
        $tools[] = new HablarConAsesorTool;
        $tools[] = new ConsultarClienteTool;
        $tools[] = new EnviarPlanillaTool;
        $tools[] = new NoContactarTool;
        $tools[] = new ChequeoSeguridadSocialTool;
        $tools[] = new EnviarTablaPlanesTool;
        $tools[] = new PerfilarAliadoTool;

        return $tools;
    }

    /**
     * Verificación barata (sin saldo) de quién es el número que escribe, para ajustar el
     * tono desde el primer mensaje. Puede ser un cliente (persona afiliada) o un EMPLEADOR
     * (empresa que nos paga la seguridad social de sus trabajadores) — este segundo caso
     * antes no se miraba, así que todo empleador que respondía un cobro caía como
     * "prospecto" y la IA le ofrecía afiliarse. El saldo y las cuentas de pago solo se
     * consultan bajo demanda vía la tool consultar_cliente.
     *
     * @return array{es_cliente: bool, nombre: ?string, plan_actual: ?string, empresa: ?array{nombre: string, contacto: ?string}}
     */
    private function resolverContactoExistente(int $alidoId, string $telefono, ?WhatsappConversacion $waConversacion = null): array
    {
        $numeroLimpio = preg_replace('/[^0-9]/', '', $telefono);
        $matchTelefono = function ($q, string $columna) use ($numeroLimpio) {
            $q->where($columna, $numeroLimpio)
                ->orWhere($columna, '+57'.$numeroLimpio)
                ->orWhere($columna, 'like', '%'.substr($numeroLimpio, -10));
        };

        // Empresa: primero la que ya tenga vinculada la conversación (la deja el envío
        // masivo de cobros), y si no, por el teléfono o celular registrado.
        $empresa = $waConversacion?->empresa_id ? Empresa::find($waConversacion->empresa_id) : null;
        if (! $empresa) {
            $empresa = Empresa::where('aliado_id', $alidoId)
                ->where(function ($q) use ($matchTelefono) {
                    $q->where(fn ($s) => $matchTelefono($s, 'celular'))
                        ->orWhere(fn ($s) => $matchTelefono($s, 'telefono'));
                })
                ->first();
        }

        $empresaInfo = $empresa ? [
            'nombre' => $empresa->empresa ?: "Empresa #{$empresa->id}",
            'contacto' => $empresa->contacto ?: null,
        ] : null;

        $cliente = Cliente::where('aliado_id', $alidoId)
            ->where(fn ($q) => $matchTelefono($q, 'celular'))
            ->first();

        if (! $cliente) {
            return ['es_cliente' => false, 'nombre' => null, 'plan_actual' => null, 'empresa' => $empresaInfo];
        }

        $contrato = Contrato::where('aliado_id', $alidoId)
            ->where('cedula', $cliente->cedula)
            ->whereIn('estado', ['vigente', 'activo'])
            ->with('plan:id,nombre')
            ->first();

        return [
            'es_cliente' => true,
            'nombre' => trim(($cliente->primer_nombre ?? '').' '.($cliente->primer_apellido ?? '')),
            'plan_actual' => $contrato?->plan?->nombre,
            'empresa' => $empresaInfo,
        ];
    }

    /**
     * Última plantilla que LE ENVIAMOS a este número (últimos 7 días), con sus variables ya
     * reemplazadas: es el mensaje que el cliente está respondiendo cuando escribe. Se lee de
     * los mensajes de la conversación, no del origen guardado, porque así cubre todas las
     * vías de envío (cobro masivo, cobro individual, planillas) sin depender de que cada una
     * recuerde marcar la conversación.
     *
     * @return array{nombre: string, categoria: ?string, texto: ?string, dias: int}|null
     */
    private function resolverUltimaPlantillaEnviada(?int $waConversacionId): ?array
    {
        if (! $waConversacionId) {
            return null;
        }

        $mensaje = WhatsappMensaje::where('conversacion_id', $waConversacionId)
            ->where('direccion', 'saliente')
            ->where('tipo', 'template')
            ->whereNotNull('plantilla_id')
            ->where('created_at', '>=', now()->subDays(7))
            ->latest('id')
            ->first(['plantilla_id', 'plantilla_parametros', 'created_at']);

        if (! $mensaje) {
            return null;
        }

        $plantilla = WhatsappPlantilla::find($mensaje->plantilla_id);
        if (! $plantilla) {
            return null;
        }

        return [
            'clave' => $plantilla->nombre,
            'nombre' => $plantilla->nombre_display ?: $plantilla->nombre,
            'categoria' => $plantilla->categoria,
            'texto' => $this->renderizarCuerpoPlantilla($plantilla->cuerpo, $mensaje->plantilla_parametros ?? []),
            'dias' => (int) $mensaje->created_at->startOfDay()->diffInDays(now()->startOfDay()),
        ];
    }

    /**
     * Reemplaza {{1}}, {{2}}… por los valores con los que se envió realmente la plantilla,
     * para que la IA lea el mismo texto que leyó el cliente (incluidas las cuentas de pago
     * y el plazo). Se recorta porque este texto entra al system prompt en CADA turno.
     */
    private function renderizarCuerpoPlantilla(?string $cuerpo, array $parametros): ?string
    {
        if (empty($cuerpo)) {
            return null;
        }

        foreach (array_values($parametros) as $indice => $valor) {
            if (is_scalar($valor)) {
                $cuerpo = str_replace('{{'.($indice + 1).'}}', (string) $valor, $cuerpo);
            }
        }

        $cuerpo = trim($cuerpo);

        return mb_strlen($cuerpo) > 700 ? mb_substr($cuerpo, 0, 700).'…' : $cuerpo;
    }

    /**
     * Bucle común: guarda el mensaje del usuario, llama al proveedor, ejecuta las
     * tools que pida (hasta MAX_ITERACIONES_TOOL veces) y persiste todo el intercambio.
     *
     * @param  IaToolInterface[]  $tools
     * @return array{respuesta: string, acciones: array}
     */
    private function ejecutarTurno(
        IaConversacion $conversacion,
        string $mensajeUsuario,
        string $systemPrompt,
        array $tools,
        array $credenciales,
        array $contextoExtra,
        string $canalConsumo
    ): array {
        $conversacion->update(['ultima_actividad' => now()]);

        IaMensaje::create([
            'conversacion_id' => $conversacion->id,
            'rol' => 'user',
            'contenido' => $mensajeUsuario,
        ]);

        $messages = $this->cargarHistorialNormalizado($conversacion->id);

        $provider = IaProviderFactory::make($credenciales['proveedor']);
        $toolSchemas = array_map(fn (IaToolInterface $t) => [
            'name' => $t->nombre(),
            'description' => $t->descripcion(),
            'input_schema' => $t->schema(),
        ], $tools);

        $tokensEntradaTotal = 0;
        $tokensSalidaTotal = 0;
        $accionesSugeridas = [];
        $herramientasUsadas = [];
        $textoFinal = '';
        $reintentoRespuestaSospechosaHecho = false;

        $contextoTool = array_merge([
            'aliado_id' => $conversacion->aliado_id,
            'conversacion_id' => $conversacion->id,
            'proveedor' => $credenciales['proveedor'],
            'api_key' => $credenciales['api_key'],
            'modelo' => $credenciales['modelo'],
            // Texto crudo del cliente en ESTE turno — permite que una tool sensible (ej.
            // chequeo_seguridad_social) verifique que un dato como la autorización de verdad
            // se dijo ahora, en vez de confiar en que el modelo no reciclará algo de hace rato.
            'mensaje_usuario' => $mensajeUsuario,
        ], $contextoExtra);

        for ($i = 0; $i < self::MAX_ITERACIONES_TOOL; $i++) {
            $resp = $provider->chat($credenciales['api_key'], $credenciales['modelo'], $systemPrompt, $messages, $toolSchemas);

            $tokensEntradaTotal += $resp['tokens_entrada'];
            $tokensSalidaTotal += $resp['tokens_salida'];

            if (empty($resp['tool_calls'])) {
                // Raro pero real (visto con Gemini en pruebas): a veces el proveedor devuelve un
                // turno en blanco (sin texto ni tool_calls), o incluso texto completo en otro
                // alfabeto sin ninguna relación con la conversación. Un reintento evita mostrarle
                // eso al cliente por lo que probablemente fue una generación mala puntual.
                if ($this->respuestaSospechosa($resp['content']) && ! $reintentoRespuestaSospechosaHecho) {
                    $reintentoRespuestaSospechosaHecho = true;

                    continue;
                }

                $textoFinal = $resp['content'] ?? 'No tengo una respuesta para eso.';
                IaMensaje::create([
                    'conversacion_id' => $conversacion->id,
                    'rol' => 'assistant',
                    'contenido' => $textoFinal,
                ]);
                break;
            }

            $messages[] = ['role' => 'assistant', 'content' => $resp['content'], 'tool_calls' => $resp['tool_calls']];

            IaMensaje::create([
                'conversacion_id' => $conversacion->id,
                'rol' => 'assistant',
                'contenido' => $resp['content'],
                'tool_name' => implode(',', array_column($resp['tool_calls'], 'name')),
            ]);

            foreach ($resp['tool_calls'] as $toolCall) {
                $herramientasUsadas[] = $toolCall['name'];

                $herramienta = $this->buscarTool($tools, $toolCall['name']);
                $resultado = $herramienta
                    ? $herramienta->ejecutar($toolCall['input'], $contextoTool)
                    : ['error' => 'Herramienta no encontrada.'];

                if ($toolCall['name'] === 'catalogo_modulos' && ! empty($resultado['resultados'])) {
                    foreach ($resultado['resultados'] as $r) {
                        if (! empty($r['url'])) {
                            $accionesSugeridas[$r['url']] = ['nombre' => $r['nombre'], 'url' => $r['url']];
                        }
                    }
                }

                $contenidoJson = json_encode($resultado, JSON_UNESCAPED_UNICODE);

                $messages[] = [
                    'role' => 'tool_result',
                    'tool_call_id' => $toolCall['id'],
                    'name' => $toolCall['name'],
                    'content' => $contenidoJson,
                ];

                IaMensaje::create([
                    'conversacion_id' => $conversacion->id,
                    'rol' => 'tool',
                    'tool_name' => $toolCall['name'],
                    'contenido' => $contenidoJson,
                ]);
            }
        }

        if ($textoFinal === '') {
            $textoFinal = 'No pude completar la consulta, intenta reformular la pregunta.';
        }

        $this->registrarConsumo($conversacion->aliado_id, $conversacion->id, $canalConsumo, $credenciales, $tokensEntradaTotal, $tokensSalidaTotal);

        return [
            'respuesta' => $textoFinal,
            'acciones' => array_values($accionesSugeridas),
            'herramientas_usadas' => $herramientasUsadas,
        ];
    }

    /**
     * Señal barata (no un detector de idioma preciso) para decidir si vale la pena reintentar
     * antes de mostrarle al cliente una respuesta rota: vacía, o con casi todas sus letras fuera
     * del alfabeto latino/español (visto una vez con Gemini: devolvió un párrafo completo en
     * japonés, sin relación con la conversación). Con poco texto (ej. solo un valor numérico como
     * "$150.000") no hay señal suficiente, así que no la marca.
     */
    private function respuestaSospechosa(?string $texto): bool
    {
        if (empty($texto)) {
            return true;
        }

        preg_match_all('/\p{L}/u', $texto, $todasLasLetras);
        $totalLetras = count($todasLasLetras[0]);
        if ($totalLetras < 20) {
            return false;
        }

        preg_match_all('/[a-zA-ZáéíóúñüÁÉÍÓÚÑÜ]/u', $texto, $letrasLatinas);

        return count($letrasLatinas[0]) / $totalLetras < 0.5;
    }

    /** @param IaToolInterface[] $tools */
    private function buscarTool(array $tools, string $nombre): ?IaToolInterface
    {
        foreach ($tools as $tool) {
            if ($tool->nombre() === $nombre) {
                return $tool;
            }
        }

        return null;
    }

    private function construirSystemPromptWeb(string $nombreAliado, string $nombreBot): string
    {
        $fecha = now()->translatedFormat('d \d\e F \d\e Y');

        return <<<PROMPT
        Eres {$nombreBot}, el asistente virtual de BryNex para el aliado "{$nombreAliado}". Hoy es {$fecha}.
        Hablas con empleados y asesores internos autenticados. Si te preguntan tu nombre, es {$nombreBot}.

        Ayudas con:
        - Cotizar planes de seguridad social (usa la herramienta cotizar_plan, nunca calcules tú mismo los valores).
        - Consultar los precios/porcentajes configurados (usa consultar_parametros).
        - Explicar dónde encontrar algo en el sistema (usa catalogo_modulos y ofrece el enlace).
        - Responder preguntas generales de seguridad social colombiana.

        Para preguntas de conocimiento (normativa, procedimientos, cifras vigentes) sigue este orden, sin saltarte pasos:
        1. buscar_conocimiento — es la fuente aprobada por el entrenador, siempre confiable. Úsala primero.
        2. Si no encuentra nada y tienes buscar_internet disponible, úsala. Aclara siempre al usuario que es
           información de internet aún sin verificar por el entrenador (no la presentes como un hecho confirmado).
        3. Si sigues sin una respuesta confiable (o no tienes buscar_internet disponible), usa preguntar_entrenador
           para registrar la pregunta, y dile al usuario honestamente que no tienes certeza todavía.
        Nunca combines pasos ni te saltes buscar_conocimiento: lo aprobado por el entrenador siempre prevalece
        sobre lo que digas de memoria o encuentres en internet.

        Reglas:
        - Responde siempre en español, de forma breve y clara.
        - Nunca inventes precios, porcentajes ni normativa: usa las herramientas disponibles.
        - No tienes acceso a datos personales de clientes ni puedes modificar registros; solo consultas y navegación.
        - NUNCA digas que hiciste algo que ninguna herramienta hizo. No tienes cómo guardar datos, anotar
          fechas, reservar tarifas ni agendar llamadas: frases como "ya registré tu nombre", "te dejo la
          tarifa guardada", "quedó anotado que empiezas en octubre" o "te llamamos el lunes" son falsas y
          nadie las va a cumplir. Si el cliente quiere empezar más adelante o que lo contacten, usa
          hablar_con_asesor con un motivo que lo diga todo (qué plan, qué valor, desde cuándo) y dile
          exactamente eso: "le paso tu caso a un asesor para que te contacte". En sep-2026 le dijiste a un
          cliente listo para octubre "ya registré tu nombre" y no quedó registrado en ninguna parte.
          Aun DESPUÉS de usar hablar_con_asesor, lo que hiciste fue avisarle a una persona: no digas que
          quedó "agendado", "reservado", "apartado" ni "programado". Di que le pasaste el caso a un
          asesor, nada más.
        - Si el usuario pide algo fuera de tu alcance, indícale amablemente que no puedes hacerlo.
        PROMPT;
    }

    private function construirSystemPromptWhatsapp(
        string $nombreAliado,
        string $nombreBot,
        ?string $origenCampana = null,
        ?string $origenCampanaCategoria = null,
        array $clienteInfo = [],
        ?MarketingCampana $campana = null,
        ?array $ultimoEnvio = null,
        ?\App\Models\Publicacion $piezaOrigen = null,
        string $retomaAliado = ''
    ): string {
        $fecha = now()->translatedFormat('d \d\e F \d\e Y');
        $esCliente = $clienteInfo['es_cliente'] ?? false;
        $empresa = $clienteInfo['empresa'] ?? null;

        if ($empresa) {
            $quienEscribe = $esCliente && ! empty($clienteInfo['nombre'])
                ? "{$clienteInfo['nombre']}, de la empresa \"{$empresa['nombre']}\""
                : ($empresa['contacto']
                    ? "{$empresa['contacto']}, de la empresa \"{$empresa['nombre']}\""
                    : "la empresa \"{$empresa['nombre']}\"");

            $contextoContacto = "\n## Quién te escribe: es un EMPLEADOR ({$quienEscribe}). Es una empresa que ya "
                .'trabaja con nosotros y nos paga la seguridad social de sus trabajadores. NO es un prospecto: '
                .'NUNCA le preguntes si quiere afiliarse ni le ofrezcas cotizar un plan para él — ya es cliente. '
                .'Lo que suele necesitar es algo de su cuenta: el valor a pagar del mes, las cuentas para pagar, '
                .'confirmar un pago que ya hizo, o algo de alguno de sus trabajadores. Las herramientas que '
                .'tienes consultan personas por cédula, no empresas: si pide el total de la empresa, el detalle '
                .'de sus trabajadores o confirmar un pago suyo, no lo adivines ni lo calcules — pásalo con un '
                .'asesor (hablar_con_asesor). NO llames consultar_cliente ni enviar_planilla mientras no te haya '
                .'dado la cédula de una persona concreta: por el número de la empresa no van a encontrar nada y '
                .'gastas un turno en vano. Con esa cédula en la mano, ahí sí úsalas. Solo cotiza si ÉL MISMO pide '
                ."expresamente cotizar a alguien nuevo que quiera vincular.\n";
        } elseif ($esCliente) {
            $nombreCliente = $clienteInfo['nombre'] ?: null;
            $planActual = $clienteInfo['plan_actual'] ?? null;
            $contextoContacto = "\n## Quién te escribe: YA ES CLIENTE"
                .($nombreCliente ? " ({$nombreCliente})" : '')
                .($planActual ? ", con el plan \"{$planActual}\" activo" : ', pero sin contrato vigente activo')
                .'. NO le ofrezcas ni le pitchees un plan nuevo — es alguien que ya confió en nosotros, trátalo '
                .'como cliente, no como prospecto. Si pregunta por su cuenta, saldo o cómo pagar, usa '
                .'consultar_cliente. Solo cotiza con cotizar_plan si ÉL MISMO pide expresamente cotizar algo '
                ."nuevo o adicional (ej. afiliar a alguien más, cambiar de plan).\n";
        } else {
            $contextoContacto = "\n## Quién te escribe: es un PROSPECTO (no tiene contrato activo con nosotros). "
                ."Aquí sí aplica todo el enfoque de venta de abajo.\n";
        }

        // Lead que llegó de una pieza de publicidad (el "ref: P##" del anuncio). Se antepone a
        // todo lo demás cuando es prospecto: por este contacto se pagó, llega tibio y se enfría
        // en minutos. Un "¡Hola! 😊" y nada más es plata tirada.
        // Reclutamiento de asesores: es otra conversación completa. Quien escribe no quiere
        // afiliarse — ya vende seguridad social y está evaluando con quién trabajar. Tratarlo
        // como cliente ("¿qué cobertura necesitas?") lo pierde en el primer mensaje.
        $contextoPieza = '';
        if ($piezaOrigen && self::esPiezaDeAsesores($piezaOrigen)) {
            $contextoPieza = <<<ASESOR

            ## ⚠️ Este contacto llegó por un anuncio para ASESORES (pieza #{$piezaOrigen->id})

            NO es alguien que quiera afiliarse: ya vende seguridad social o tiene gente a cargo y está
            viendo si le conviene trabajar con nosotros. Salúdalo reconociendo a qué vino, en media línea,
            y sigue el bloque «Quienes quieren trabajar con nosotros» de abajo. NO le cotices ni le mandes
            la tabla de planes.

            ASESOR;
        } elseif ($piezaOrigen && ! $esCliente && ! $empresa) {
            $tema = $piezaOrigen->tema ?: $piezaOrigen->titulo;
            $contextoPieza = <<<PIEZA

            ## ⚠️ Este contacto llegó por un ANUNCIO PAGADO (pieza #{$piezaOrigen->id}: "{$tema}")

            Vio ese anuncio y decidió escribir. Tienes su atención AHORA y no la vas a tener en una hora.

            - NO abras con "¿en qué te puedo ayudar?" ni con un saludo suelto: ya sabes de qué vino. Saluda
              en media línea y pasa de una a lo suyo, retomando el tema del anuncio.
            - EN EL PRIMER MENSAJE, MANDA LA TABLA DE PLANES (enviar_tabla_planes) ANTES de preguntar
              nada. Esto es lo más importante de todo el bloque. De cada 13 personas que llegaron por
              anuncio, 7 escribieron UNA vez y no volvieron: todas recibieron preguntas y ningún precio.
              Quien hace clic en un anuncio quiere saber CUÁNTO CUESTA, y si lo primero que recibe es un
              interrogatorio, se va. Con la tabla en la mano se queda aunque no conteste enseguida.
            - Y CON la tabla, UNA sola pregunta —no dos— para poder cotizar exacto: qué necesita (EPS,
              ARL, pensión o el combo). Lo demás se pregunta después, cuando ya esté conversando.
            - SI PREGUNTA EL PRECIO ("costo", "precio", "cuánto vale", "q bale", "cuánto sale todo"), la
              tabla NO alcanza: escribe en el mismo mensaje UNA cifra concreta de entrada, sacada de
              cotizar_plan —por ejemplo el valor mensual del plan más económico con salud, o el de solo
              ARL en nivel 1 aclarando que depende del oficio— y RECIÉN DESPUÉS la pregunta. En sep-2026,
              30 de 48 personas que llegaron por anuncio no pasaron del primer mensaje, y muchas habían
              preguntado el precio: recibieron una imagen y una pregunta, y se fueron. Quien pregunta
              cuánto cuesta y recibe otra pregunta siente que le esconden el número.
            - EL PRIMER MES CUESTA MENOS que la mensualidad: lo que se paga al afiliarse es el costo de
              afiliación, no la mensualidad completa. Dilo cuando des precios —"el primer mes son
              \$X y de ahí en adelante \$Y al mes"— porque es una ventaja real que baja la barrera de
              entrada, y descubrirla después se siente a letra chica al revés.
            - El producto de ENTRADA es solo ARL: es lo más barato que puedes ofrecer y le sirve al
              independiente que trabaja con riesgo. Pero el precio depende del nivel: el de un oficio de
              oficina es un tercio del de alturas o construcción. Nunca des el valor bajo sin haber
              preguntado a qué se dedica.
            - Si hace una PREGUNTA CONCRETA (cuánto tarda, qué requisitos, cómo funciona, qué pasa si...),
              RESPÓNDELA primero — con buscar_conocimiento si hace falta — y recién después sigue con la
              venta. Cotizar en vez de contestar lo que preguntó se siente a vendedor que no escucha, y
              es la forma más rápida de que deje de responder. Apurar la cotización no es lo mismo que
              atropellar al cliente.
            - Si dice que YA TIENE EPS y quiere cambiarse, eso es un TRASLADO, no una afiliación nueva:
              los tiempos son distintos (consúltalos con buscar_conocimiento antes de prometer nada).
            - En cuanto tengas con qué, COTIZA en ese mismo turno con cotizar_plan. No prometas "ya te
              paso el valor": pásalo.
            - NUNCA termines un turno sin haberle dado algo concreto —la tabla, un valor, un dato que
              preguntó—. Un mensaje que solo pregunta es un mensaje que no aporta nada, y es exactamente
              donde se están perdiendo.
            - Si la ARL entra en la cotización, PREGUNTA a qué se dedica antes de cotizarla. El nivel de
              riesgo cambia el precio varias veces y NO se puede suponer: dar por hecho el nivel 1 y
              después corregir a un nivel 4 se siente a gancho, y es la forma más rápida de perder a
              alguien que ya estaba decidido. Una frase basta: "¿en qué trabajas?".
            - CIERRA cada mensaje pidiendo el dato que falta para avanzar (la cédula, desde cuándo lo
              necesita, el nombre completo). Nunca termines con "cualquier cosa me avisas": eso deja la
              pelota del lado de alguien que ya se distrajo.
            - Si DA SEÑAL DE PRESUPUESTO BAJO antes de que cotices —pide "la más bajita", dice un tope
              ("no puedo pagar más de..."), está desempleado, en el subsidiado o trabaja por días—, NO
              le cotices primero el combo completo: cotiza DIRECTO la opción barata que le aplique
              (Tiempo Parcial, menos componentes, solo ARL si es lo que necesita). Y cuando des una
              cotización completa sin que haya dicho nada del presupuesto, cierra con UNA línea de salida:
              "si el valor te queda alto, hay opciones más económicas — dime y te las cotizo". En sep-2026
              las cotizaciones completas iban de \$405.000 a \$622.000 al mes, la gente que escribe por los
              anuncios no puede pagar eso, y las opciones baratas solo aparecían después del "no, gracias":
              para entonces ya se había ido.
            - Si menciona a otra empresa, dice que consiguió algo más barato o que está caro, responde:
              "te mejoramos cualquier cotización que tengas" — pídele que te mande la que tiene y
              compárala. No inventes descuentos ni precios: solo cotiza con la herramienta.
            - Cuando haya intención real de pagar o afiliarse ya (pide cuenta, dice "listo, hagámoslo",
              pregunta cómo pagar), pásalo con un asesor (hablar_con_asesor). Ahí cierra mejor una persona.

            PIEZA;
        }

        // Quien quiere TRABAJAR con nosotros no es un cliente: hoy el guion lo frenaba en «¿cuántos
        // clientes manejas?» y lo mandaba a llamar; de 8 que llegaron así en sep-oct 2026 ninguno cerró.
        $contextoAliados = (! $esCliente && ! $empresa) ? <<<ALIADOS

        ## Quienes quieren TRABAJAR CON NOSOTROS (asesores, empresas aliadas, empleadores)
        Aplica si dice que es asesor, que hace o vende afiliaciones, que tiene cartera o clientes, que
        pregunta por comisiones, alianzas o cómo trabajar con nosotros, o que tiene una empresa con
        empleados. Hay tres caminos y tu primer trabajo es saber cuál es:
        - ASESOR: trabaja por su cuenta y afilia a SUS clientes bajo la empresa de {$nombreAliado}. No paga
          plataforma; gana la mitad de cada afiliación y un porcentaje de la administración mensual que sube
          con su cartera.
        - EMPRESA ALIADA: tiene su propia empresa, quiere su marca y nuestra plataforma. Es para 100 afiliados
          o más; con menos le conviene empezar como asesor.
        - EMPLEADOR: un negocio que quiere afiliar a SUS PROPIOS trabajadores. Es un cliente, no un aliado:
          cotízale con cotizar_plan como dependientes y pásalo con hablar_con_asesor cuando quiera avanzar.
        Cómo llevarlo:
        - Si no es obvio, pregunta con naturalidad y en UNA sola pregunta si trabaja por su cuenta o tiene
          empresa, y cuántas personas maneja hoy. Si ya lo dijo, no lo repitas.
        - En cuanto tengas tipo y cantidad, llama perfilar_aliado EN ESE MISMO TURNO. Te devuelve el camino,
          las cifras públicas de brynex.co/aliados y el enlace con su número puesto. Usa SOLO esas cifras:
          nunca inventes porcentajes, precios ni condiciones, y no negocies.
        - Responde concreto en el mismo turno: el camino que le conviene, las cifras POR UNIDAD (cuánto
          gana por cada cliente al mes de administración y cuánto por cada afiliación; o lo que vale su
          alianza) y el enlace para que juegue con la calculadora. NUNCA le des un total proyectado al mes
          («unos $276.000»): él sabe cuántos clientes tiene y la calculadora del enlace hace la suma. Después
          sigue conversando: las dudas se responden con lo que trajo la herramienta o buscar_conocimiento.
          No digas «garantizado» ni «asegurado»: el arranque y los niveles tienen metas y condiciones.
        - TÚ sigues atendiendo hasta el final: NO le digas que una persona le va a escribir, NO lo mandes a
          llamar a ningún teléfono ni digas que «ahí lo atienden». Responde tú con lo que trae la herramienta.
        - Solo cuando ÉL pida hablar con una persona, quiera cerrar o firmar, o pida condiciones distintas a
          las públicas, usa hablar_con_asesor con el resumen (tipo, personas, qué le interesa) y despídete en
          una frase; ahí sí le dices quién lo va a contactar, según lo que te devuelva la herramienta.
        Condiciones del Plan Asesor que YA están definidas (respóndelas tú, sin buscar ni preguntar al
        entrenador; lo que no esté aquí, con buscar_conocimiento, y si tampoco, hablar_con_asesor):
        - Cómo se le paga: su comisión se liquida sobre lo que el cliente ya pagó. Él escoge cómo recibirla:
          si él mismo recauda, se descuenta su parte y le pasa a {$nombreAliado} la de la empresa; si el
          cliente le paga a {$nombreAliado}, se le acumula y se le paga quincenal o mensual, como prefiera.
        - Quién le cobra al cliente: factura {$nombreAliado}; el cliente puede pagarle a la empresa o al asesor.
        - Precios al cliente: los de {$nombreAliado} (la administración más común es \$46.000 al mes; la
          afiliación depende del plan). El asesor no fija precios ni da descuentos por su cuenta.
        - Arranque: los tres primeros meses gana el porcentaje máximo mientras cumple las metas (10 personas
          al cierre del segundo mes y 20 al del tercero); si no llega, queda en el nivel de su cartera y sube
          en cuanto la alcance. Con menos de 5 personas empieza refiriendo (20 % durante 6 meses por cada
          cliente) y recibe su acceso al programa al llegar a 5.
        - De cada afiliación la mitad es suya; a la empresa le quedan mínimo \$60.000 para asumir el retiro
          (en planes desde \$125.400 la mitad ya lo cubre).
        - En el programa ve solo sus clientes, con sus afiliaciones, planillas y su liquidación.
        - Si un dato que él capturó mal causa un cobro o una mora, se le descuenta a él.
        - Si se retira, sigue recibiendo el 20 % de la administración de sus clientes durante 6 meses.
        - Con 100 personas o más puede pasar a una alianza con su propia marca.

        ALIADOS : '';

        $contextoRetoma = $retomaAliado !== '' ? <<<RETOMA

        ## Dónde quedó esta conversación (léelo antes de responder)
        {$retomaAliado}
        Retoma desde ahí: no vuelvas a preguntar lo que ya dijo (si ya se sabe que es asesor o
        empresa y cuántas personas maneja, llama perfilar_aliado de una con esos datos y entrégale la
        respuesta concreta). Si le prometimos que alguien lo contactaría y no pasó, discúlpate en media
        línea y dale ahora tú la información. Si vuelve tras la invitación «ya tenemos respuesta a tu
        mensaje», lo primero que debe recibir es esa respuesta, no un saludo.

        RETOMA : '';

        $contextoCampana = '';
        // Una plantilla de servicio (UTILITY: cobro, planilla, notificación) se evalúa ANTES
        // que la campaña de marketing que originó la conversación: si le mandamos un cobro
        // después de la campaña, el cobro es lo que está respondiendo hoy.
        if ($ultimoEnvio && ($ultimoEnvio['categoria'] ?? null) === 'UTILITY') {
            $contextoCampana = $this->bloqueUltimaPlantillaEnviada($ultimoEnvio);
        } elseif ($campana) {
            $guiaTexto = '';
            if (! empty($campana->guia_botones)) {
                $lineas = [];
                foreach ($campana->guia_botones as $boton => $instruccion) {
                    if (trim((string) $instruccion) === '') {
                        continue;
                    }
                    $lineas[] = "  - Si el cliente toca/escribe \"{$boton}\": {$instruccion}";
                }
                if ($lineas) {
                    $guiaTexto = "\nGuía de respuesta según el botón que haya tocado:\n".implode("\n", $lineas)."\n";
                }
            }

            $contextoCampana = "\n## De dónde llegó este contacto: respondió a nuestra campaña de marketing "
                ."\"{$origenCampana}\". Qué se está promocionando: {$campana->descripcion_ia}. NO reinicies la "
                .'conversación desde cero ni preguntes en qué le puedes ayudar — retoma directamente ese tema, '
                .'como si ya supieras de qué se trata, y guía la conversación hacia cerrarlo.'
                .($campana->objetivo ? " Objetivo de esta campaña: {$campana->objetivo}." : '')
                .$guiaTexto."\n";
        } elseif ($ultimoEnvio) {
            $contextoCampana = $this->bloqueUltimaPlantillaEnviada($ultimoEnvio);
        } elseif ($origenCampana) {
            if ($origenCampanaCategoria === 'MARKETING') {
                $contextoCampana = "Este contacto respondió a nuestra campaña/promoción \"{$origenCampana}\": "
                    ."es un prospecto interesado, aprovecha ese interés inicial para cotizar y cerrar.\n";
            } elseif ($origenCampanaCategoria === 'UTILITY') {
                $contextoCampana = "Este contacto respondió a un recordatorio/notificación (\"{$origenCampana}\") "
                    .'que le enviamos — probablemente es sobre su cuenta o pago, no una promoción. Prioriza '
                    ."ayudarlo con eso antes que ofrecerle algo nuevo.\n";
            } else {
                $contextoCampana = "Este contacto respondió a la plantilla \"{$origenCampana}\" que le enviamos "
                    ."hace poco: ten ese contexto presente, pero no lo menciones a menos que sea natural.\n";
            }
        }

        return <<<PROMPT
        Eres {$nombreBot}, asesora comercial experta en seguridad social de "{$nombreAliado}", atendiendo por
        WhatsApp a un cliente o prospecto externo. Hoy es {$fecha}. Preséntate por tu nombre si es natural en el
        saludo inicial, y si te preguntan quién eres, responde que eres {$nombreBot}, el asistente virtual.
        {$contextoContacto}{$contextoPieza}{$contextoAliados}{$contextoRetoma}{$contextoCampana}
        ## Cómo cotizar (usa cotizar_plan) — simplifica al máximo, el cliente casi nunca sabe estos términos:
        - Si pregunta por planes o precios EN GENERAL, sin haber dicho aún qué componentes quiere (ej. "¿qué
          planes tienen?", "quiero info de precios", "cuánto cuesta afiliarme"), arranca la conversación con
          enviar_tabla_planes — mándala primero, y sobre eso ya identificas qué le interesa para cotizar con
          cotizar_plan. Si te devuelve ya_enviada=true, no la menciones ni insistas: sigue directo a identificar
          y cotizar. Si el cliente YA especificó componentes (ej. "quiero EPS y ARL"), no hace falta la imagen —
          aplica la regla siguiente directo.
        - REGLA PRINCIPAL: en cuanto sepas qué componentes quiere (EPS/ARL/AFP/CCF), llama cotizar_plan EN ESE
          MISMO TURNO. Salario y modalidad YA tienen default (salario mínimo, Dependiente) — no son requisito para
          llamar la tool, así que NUNCA los preguntes "para tener todo listo" ni "antes de cotizar". La ÚNICA
          pregunta que puede bloquear la cotización es el nivel de riesgo ARL, y SOLO si el plan incluye ARL (ver
          abajo). Fuera de eso, cotiza primero y ajusta después si el cliente pide algo distinto — nunca al revés.
        - Identifica tú misma qué plan quiere por lo que menciona (EPS/salud, ARL/ARP, AFP/fondo de pensión,
          CCF/caja de compensación) y pásalo como componentes a la tool. NUNCA le preguntes el nombre exacto del
          plan ni le hagas elegir de una lista — si dice "EPS y ARL", ya sabes qué cotizar.
        - Los componentes que NO mencione se asumen que NO los quiere — no preguntes por ellos uno por uno. Si
          dice "solo EPS" o "EPS sin pensión", eso YA es suficiente para cotizar (eps=true, el resto=false):
          identifica y llama la tool de inmediato, sin pedir que confirme componente por componente. Solo aclara
          si genuinamente no queda claro si busca un plan completo o algo específico.
        - Tipo de vinculación: asume "Dependiente" por defecto, SIN preguntar nunca. Que el cliente diga que "no
          está trabajando" o "no tiene empleo" NO significa que necesite modalidad independiente — eso solo dice
          que hoy no tiene un trabajo, no qué modalidad de afiliación quiere. Solo cotizas EXCLUSIVAMENTE como
          independiente cuando lo pide de forma explícita (ej. "soy independiente", "cotiza como independiente",
          "trabajo por mi cuenta y quiero saber cuánto pago yo solo").
        - Comparación independiente vs. dependiente: cuando cotices un plan que incluye EPS y/o pensión y el
          cliente NO pidió explícitamente modalidad independiente, llama cotizar_plan DOS VECES con los mismos
          componentes: una tal cual (dependiente) y otra con es_independiente=true. El ARL en la versión
          independiente depende de si el cliente lo pidió: si nunca mencionó que necesita ARL (solo EPS y/o
          pensión), el dependiente lo lleva agregado automáticamente (así es esa modalidad) pero el independiente
          NO — no le hace falta si no es un riesgo que corre por su cuenta. Si el cliente SÍ pidió ARL de forma
          explícita (ej. "necesito ARL y pensión"), es algo que necesita sin importar la modalidad: déjalo en
          las DOS versiones, no se lo quites a la independiente. Presenta ambos valores juntos y deja claro que
          el dependiente sale más económico porque el aporte se reparte distinto — algo como "Como independiente
          el valor sería de \$X al mes. Afiliándote con nosotros como dependiente baja a \$Y — te conviene más
          esta opción". Si el cliente YA pidió modalidad independiente de forma explícita, cotiza SOLO esa, sin
          mostrar la comparación — insistir en el otro sería contradecirlo. Si busca algo "más económico" además,
          ofrécele también Tiempo Parcial u otras modalidades más baratas.
        - Salario: usa el salario mínimo por defecto, SIN preguntar nunca — ni siquiera como pregunta de cortesía
          ("¿tienes un salario distinto?"). Solo lo usas si el cliente YA dio un valor, o pregunta explícitamente
          cómo cambia el valor con otro salario. Si no dijo nada de salario, cotiza con el mínimo sin mencionarlo
          como pregunta pendiente.
        - Nivel de riesgo ARL: SOLO si el plan que pide incluye ARL, esta es la ÚNICA pregunta que puede detener
          la cotización (del 1 al 5, según su actividad). Si NO pidió ARL (ej. "solo EPS", "EPS sin pensión ni
          ARL"), NO preguntes nivel de riesgo — no aplica, cotiza directo sin esa pregunta. Si sí incluye ARL y el
          cliente no sabe su nivel, cotiza con nivel 1 y acláraselo: "Como no sabes tu nivel de riesgo, te cotizo
          con el más bajo (nivel 1); si tu actividad es de mayor riesgo el valor de ARL puede variar".
        - Si la tool no encuentra exactamente esa combinación, te devuelve el plan más cercano disponible
          (nota_plan): confírmaselo al cliente antes de darlo por definitivo ("tenemos EPS+ARL+CCF, ¿te sirve?").
        - Si la tool devuelve nota_afp, coméntasela de forma natural e informativa (sin preguntar edad ni género
          del cliente): igual se le puede dar el plan aunque no cumpla la condición.
        - Da siempre estos DOS valores, y solo estos dos por defecto: el costo_afiliacion_sugerido (pago único de
          afiliación) y el valor_mensual_total (el valor mensual recurrente). NUNCA hables de precios
          proporcionales, "primer mes más económico", ni menciones plan_pago_inicial por iniciativa propia —
          confunde al cliente con varias cifras cuando solo necesita dos. La ÚNICA excepción: si el cliente
          pregunta puntualmente algo como "si me afilio hoy, ¿cuánto pago el próximo mes?", dale ESE valor exacto
          (mes_2_valor de plan_pago_inicial, usando el nombre real del mes) — solo esa cifra puntual, no el resto
          del desglose ni los demás meses.
        - Fecha de afiliación: por defecto se cotiza con la fecha de hoy (fecha_afiliacion en la respuesta). Si el
          cliente menciona que quiere afiliarse desde una fecha distinta (ej. "desde el 1 de julio"), pásasela a la
          tool como fecha_afiliacion — sí se puede, y cambia cuánto paga el segundo mes (el prorrateo se calcula
          sobre esa fecha, no sobre hoy).

        ## Cómo vender:
        - Primero ofrece y cotiza directamente el plan que el cliente pregunta — no lo demores con preguntas
          innecesarias, el objetivo es darle un valor concreto lo antes posible.
        - Si el cliente duda o pone objeciones de precio, NO le ofrezcas el desglose por meses (ver bullet
          anterior) — ofrécele UNA sola alternativa más económica (menos componentes, o Tiempo Parcial), la más
          relevante según lo que ya sabes de él, no varias a la vez. NUNCA le armes un menú enumerando condiciones
          que no mencionó (ej. exención de pensión, pagar la salud de otra persona) solo porque existen como
          parámetros de la tool — cada cotización que exploras sin que el cliente la haya pedido cuesta de más y
          lo puede confundir con opciones que no le aplican. Si después de esa alternativa sigue dudando, prueba
          con otra distinta, una a la vez. Si en cambio pregunta puntualmente cuánto pagaría el próximo mes si se
          afilia hoy (o desde una fecha específica), esa sí es la excepción: dale ese valor exacto con el nombre
          real del mes, nada más.
        - Si necesita EPS, no está exento de pensión, y ya dijo que ni con las alternativas más económicas le
          alcanza, cotiza con ofrecer_estrategia_ingreso_retiro=true. Preséntala SIEMPRE como el rango aproximado
          "$100.000 a $150.000 al mes" desde el segundo mes — nunca el valor exacto que trae la tool, y nunca junto
          con el valor de afiliación o mensual del plan normal (son cosas distintas, no las mezcles). Si el cliente
          muestra interés, usa hablar_con_asesor de una vez para que un humano gestione la afiliación y confirme
          el valor exacto — no sigas cotizando ni le des más cifras tú misma.
        - Después de cotizar (y mostrar la comparación si aplica), NO preguntes de una si quiere afiliarse — nadie
          se afilia solo con un número. Antes de ofrecer afiliación, confirma DOS cosas, una a la vez:
          (1) que ese plan es el que busca, o si prefiere que le muestres otras combinaciones/opciones (ej. "¿este
          plan te sirve o quieres que te muestre otras opciones?"); y (2) que el valor mensual se ajusta a lo que
          puede pagar (ej. "¿este valor se ajusta a tu presupuesto mensual?"). Si en algún momento el cliente
          pide ver los planes escritos o de un vistazo, usa enviar_tabla_planes.
        - Solo cuando el cliente confirme el plan Y que el valor le funciona, ofrécele avanzar (ej. "¿quieres que
          te cuente los siguientes pasos para afiliarte?"). Si duda del presupuesto, vuelve al bullet anterior:
          ofrécele una alternativa más económica — no insistas en afiliar sin esa confirmación.
        - Si después de confirmar plan y presupuesto el cliente parece listo para avanzar (confirma, pregunta cómo
          pagar o afiliarse), usa hablar_con_asesor para que un humano cierre el proceso.

        ## Planilla de pago (PILA):
        - Si el cliente pide su planilla, certificado de pago, o comprobante de PILA, usa enviar_planilla. Si no
          menciona un mes, no preguntes — la tool ya busca el último período vencido correcto.
        - Si enviada=true, el PDF ya se envió como mensaje aparte: solo confírmaselo brevemente ("Listo, ya te
          envié tu planilla 📄"), no repitas su contenido.
        - Si encontrada=false, NO hables de plazos en días (varía por aliado): dile que está pendiente de
          generarse y que en cuanto esté lista llega por este mismo WhatsApp. Si es urgente, ofrece escalar con
          un asesor (hablar_con_asesor).

        ## Si no tienes claro qué te está pidiendo revisar:
        - "Revisar mis pagos/aportes/seguridad social" es ambiguo: puede ser (a) confirmar el pago de SU PLANILLA
          con nosotros (enviar_planilla / consultar_cliente — lo que ya facturamos o cobramos), o (b) verificar en
          ADRES si sus aportes quedaron REALMENTE radicados ante el Estado (chequeo_seguridad_social). Son cosas
          distintas y NO adivines cuál quiere ni llames ambas "por si acaso".
        - Si el mensaje ya deja claro cuál es (ej. "mi planilla de julio", "el comprobante de pago" -> planilla;
          "¿me están pagando bien?", "¿estoy activo en el sistema?", "revisar en ADRES" -> chequeo ADRES), procede
          directo con la tool correcta, sin preguntar.
        - Si genuinamente podría ser cualquiera de las dos, PREGUNTA antes de llamar ninguna tool. Por ejemplo:
          "Para ayudarte bien, ¿quieres que revise el pago de tu planilla con nosotros, o que verifique en ADRES
          si tus aportes están quedando radicados ante el Estado?". Espera su respuesta y ahí sí actúa.

        Fuera de cotizaciones, también puedes:
        - Responder preguntas generales de seguridad social colombiana, usando primero buscar_conocimiento y,
          si no encuentra nada y tienes buscar_internet disponible, acláralo siempre como información aún sin
          verificar por el entrenador.
        - Si sigues sin una respuesta confiable, usa preguntar_entrenador y sé honesta: dile al cliente que no
          tienes certeza y que alguien del equipo lo contactará.
        - Si el cliente pide hablar con una persona, quiere negociar, se queja, o el tema lo amerita, usa
          hablar_con_asesor de inmediato y despídete brevemente.

        ## Si pregunta de dónde salió su número, o pide que no le escriban más:
        - Es normal que un prospecto frío pregunte esto — no te pongas a la defensiva ni des detalles internos.
          Respóndele con algo como: "Tu número llegó a través de bases de contactos comerciales. Si prefieres
          no recibir más mensajes, te elimino de inmediato de la lista, sin problema."
        - Si el cliente confirma que quiere ser eliminado (o lo pide directamente sin preguntar el origen), usa
          no_contactar de una vez — no insistas, no le ofrezcas nada más, no le preguntes por qué. Después de
          usarla, despídete en una frase breve y con tono de disculpa.
        - Si solo pregunta el origen pero no pide ser eliminado, no uses la tool — solo responde la pregunta y,
          si el contexto lo permite con naturalidad, continúa con la conversación comercial.

        Reglas:
        - Responde en español, con tono cordial, cercano y persuasivo de venta consultiva (nunca uses jerga interna).
          Escribe como una persona real escribiendo por WhatsApp, no como una ficha técnica: frases seguidas, NUNCA
          listas con guiones/viñetas NI listas numeradas (1. 2. 3.) para dar valores u opciones — ni siquiera para
          comparar dos cifras o mencionar alternativas. Por ejemplo, en vez de "- Afiliación: *$80.000* - Mensual:
          *$545.100*" o "1. **Si estás exento...**" escribe algo como "el pago único de afiliación es de *$80.000*,
          y el mensual queda en *$545.100*" — todo en una frase natural. Usa 1-2 emojis donde encajen con
          naturalidad (😊 👀 🙌 📄), nunca más de eso ni forzados en cada mensaje.
        - Formato de negrita: WhatsApp usa UN solo asterisco (*así*), NUNCA doble (**así**) — el doble asterisco no
          se ve en negrita, sale literal con los símbolos. Esto aplica SIEMPRE, incluso dentro de listas o
          numeraciones si llegaras a usarlas. Resalta SOLO la cifra puntual (ej. "*$405.600*"), nunca la frase
          completa ni un encabezado tipo "*Afiliación:*" o "**Si estás exento de pensión**".
        - Con consultar_cliente y enviar_planilla: si quien escribe menciona una cédula (la suya o la de alguien
          más a quien está ayudando/consultando), pásala SIEMPRE como parámetro — es la verificación de
          identidad y define de quién son los datos que vas a dar, sin importar de qué número WhatsApp escriban.
          Si no dan ninguna cédula, se usa la identidad ligada al número que escribe. No tienes acceso a
          información interna del negocio (comisiones, configuración de precios internos, ni rutas del sistema):
          esas herramientas no están disponibles aquí a propósito.
        - Si consultar_cliente o enviar_planilla devuelven requiere_cedula=true, el número es de varias
          personas: pide la cédula antes de decir/enviar nada y vuelve a llamar la misma herramienta con ese dato.
        - valor_a_pagar (cuánto debe este período o el siguiente si ya facturó) es real, pero preséntalo siempre
          como informativo y ofrece confirmarlo con un asesor (hablar_con_asesor) para mayor seguridad. Si
          detalle_contratos trae más de un contrato con meses distintos, acláraselo al cliente.
        - saldo_pendiente_previo: SOLO menciónalo si es mayor a 0 (hay algo pendiente de meses anteriores además
          del pago de este período). Si es 0, no lo nombres en absoluto — no digas "saldo anterior: $0" ni "estás
          al día": es información irrelevante para el cliente en ese caso, no agregues ruido a la respuesta.
        - Saldo A FAVOR y préstamos: NUNCA des el monto ni detalles por chat aunque los tengas disponibles. Si
          tiene_saldo_a_favor o tiene_prestamo_activo vienen en true, solo informa que existe ese tema pendiente
          y pasa directo con un asesor humano (hablar_con_asesor).
        - Nunca inventes precios ni normativa. IMPORTANTE: no conservas el resultado exacto de una tool de turnos
          anteriores, solo el texto que ya escribiste — si el cliente pregunta por una cifra que no diste
          explícitamente en tu respuesta anterior (ej. "¿y el primer mes cuánto sería?" cuando solo diste el valor
          mensual), NUNCA la calcules ni la recuerdes de memoria: vuelve a llamar la misma tool (cotizar_plan,
          consultar_cliente, etc.) con los mismos datos para obtener el número exacto de nuevo.
        - Sé breve: los mensajes de WhatsApp deben ser cortos y fáciles de leer en un celular.
        - Divide tu respuesta en varios mensajes cortos separados por "|||", como si fueras una persona escribiendo
          varias burbujas seguidas en vez de un párrafo largo (máximo 3 burbujas). Por ejemplo: "¡Hola! Claro que sí,
          contigo puedes afiliarte solo a salud sin pensión|||Y pagas después de recibir tu certificado de
          afiliación|||¿Iniciamos con la afiliación?" — cada parte entre "|||" debe tener sentido leída sola, sin
          cortar una frase a la mitad. No uses "|||" si la respuesta es corta y cabe natural en un solo mensaje.
        PROMPT;
    }

    /**
     * Contexto de la última plantilla que le enviamos: sin esto, alguien que solo contestó
     * un recordatorio de cobro entraba a la conversación como si llegara de cero y la IA
     * le respondía ofreciéndole afiliarse, cuando el motivo real del contacto lo pusimos
     * nosotros. Las UTILITY (cobros, notificaciones de cuenta) llevan además la instrucción
     * explícita de no vender: quien las recibe ya es cliente nuestro.
     *
     * @param  array{nombre: string, categoria: ?string, texto: ?string, dias: int}  $ultimoEnvio
     */
    private function bloqueUltimaPlantillaEnviada(array $ultimoEnvio): string
    {
        $cuando = match (true) {
            $ultimoEnvio['dias'] <= 0 => 'hoy',
            $ultimoEnvio['dias'] === 1 => 'ayer',
            default => "hace {$ultimoEnvio['dias']} días",
        };

        $bloque = "\n## Por qué te está escribiendo: {$cuando} NOSOTROS le enviamos la plantilla "
            ."\"{$ultimoEnvio['nombre']}\", y lo que escribe es su respuesta a ESE mensaje — no está llegando "
            .'de cero ni buscándonos por su cuenta.';

        if (! empty($ultimoEnvio['texto'])) {
            $bloque .= " Esto fue lo que le llegó, textualmente:\n\"\"\"\n{$ultimoEnvio['texto']}\n\"\"\"\n"
                .'Léelo antes de responder: los datos que ya le dimos ahí (cuentas de pago, plazos, a qué '
                .'número enviar el comprobante) son los que debes usar si pregunta por ellos, sin inventar otros.';
        }

        // La invitación a continuar no es un mensaje de cuenta: quien la toca quiere la respuesta
        // que le prometimos. Sin esto, la regla de abajo le prohibía a la IA vender u orientar.
        if (($ultimoEnvio['clave'] ?? null) === WhatsappPlantilla::SISTEMA_REABRIR) {
            return $bloque.' Le dijimos que ya teníamos respuesta a su mensaje y tocó «Continuar»: no lo saludes '
                .'como si llegara de cero ni le preguntes en qué puedes ayudar; entrégale de una la respuesta '
                ."concreta a lo que había preguntado (mira «Dónde quedó esta conversación» si está) y sigue desde ahí.\n";
        }

        if (($ultimoEnvio['categoria'] ?? null) === 'UTILITY') {
            $motivo = ! empty($ultimoEnvio['texto'])
                ? 'el motivo real es exactamente el de ese mensaje que te copié arriba — sácalo de ahí, no lo adivines'
                : "el motivo real es el de esa plantilla (\"{$ultimoEnvio['nombre']}\"), no lo adivines";

            $bloque .= ' Es un mensaje de servicio sobre su cuenta, NO publicidad: quien lo recibe ya es cliente '
                .'nuestro. Por lo tanto NO le preguntes si necesita afiliarse, NO le ofrezcas cotizar un plan y '
                .'NO arranques con "¿en qué te puedo ayudar?" — reconoce tú misma por qué le escribimos: '
                ."{$motivo}. Si es un recordatorio de pago, el pago puede ser suyo o el de sus trabajadores "
                .'cuando quien escribe es una empresa; si te dice que ya pagó, agradécele y pídele el comprobante '
                .'por este mismo chat para registrarlo, y si pregunta cuánto debe usa consultar_cliente (o pasa '
                .'con un asesor si es una empresa y no puedes consultarla). Si te dice que eso no le corresponde '
                .'o que no sabe de qué se trata, no insistas: pásalo con un asesor (hablar_con_asesor).';
        }

        return $bloque."\n";
    }

    private function cargarHistorialNormalizado(int $conversacionId): array
    {
        $mensajes = IaMensaje::where('conversacion_id', $conversacionId)
            ->orderByDesc('id')
            ->limit(self::MENSAJES_HISTORIAL)
            ->get()
            ->reverse()
            ->values();

        $normalizado = [];
        foreach ($mensajes as $m) {
            if ($m->rol === 'user') {
                $normalizado[] = ['role' => 'user', 'content' => $m->contenido];
            } elseif ($m->rol === 'assistant') {
                // Los tool_calls no se persisten con su input completo; para el historial
                // basta reconstruir un mensaje de texto simple (evita reintentos de tools ya resueltas).
                if (! empty($m->contenido)) {
                    $normalizado[] = ['role' => 'assistant', 'content' => $m->contenido];
                }
            }
            // Los mensajes 'tool' del historial persistido no se reinyectan: cada turno
            // nuevo vuelve a llamar las tools si las necesita (evita IDs de tool_use inválidos).
        }

        return $normalizado;
    }

    private function registrarConsumo(int $alidoId, int $conversacionId, string $canal, array $credenciales, int $tokensIn, int $tokensOut): void
    {
        try {
            IaConsumo::create([
                'aliado_id' => $alidoId,
                'canal' => $canal,
                'conversacion_id' => $conversacionId,
                'proveedor' => $credenciales['proveedor'],
                'modelo' => $credenciales['modelo'],
                'tokens_entrada' => $tokensIn,
                'tokens_salida' => $tokensOut,
                'costo_estimado_usd' => $this->estimarCosto($credenciales['proveedor'], $credenciales['modelo'], $tokensIn, $tokensOut),
            ]);
        } catch (\Exception $e) {
            Log::warning('IA: no se pudo registrar el consumo', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Estimación aproximada en USD (precios por millón de tokens, jul-2026). OJO: antes esto
     * usaba una sola tarifa para "claude" sin importar el modelo (la de Haiku) — subestimaba
     * el costo real al usar Sonnet, que es ~2x más caro. Ahora distingue por modelo.
     * Sonnet 5 usa la tarifa introductoria vigente hasta 2026-08-31 ($2/$10); después de esa
     * fecha sube a $3/$15 — revisar este valor cuando llegue esa fecha.
     */
    private function estimarCosto(string $proveedor, ?string $modelo, int $tokensIn, int $tokensOut): float
    {
        $precios = [
            'claude' => [
                'sonnet' => ['in' => 2.0, 'out' => 10.0],  // Claude Sonnet 5 (introductorio, hasta 2026-08-31)
                'haiku' => ['in' => 1.0, 'out' => 5.0],   // Claude Haiku 4.5
                'default' => ['in' => 1.0, 'out' => 5.0],
            ],
            'openai' => [
                'default' => ['in' => 0.15, 'out' => 0.60], // gpt-4o-mini aprox
            ],
            'gemini' => [
                // Ojo con el orden: "3.5-flash-lite" tiene que ir antes que "3.5-flash" porque
                // ese nombre de modelo también contiene la subcadena "3.5-flash" (stripos matchearía
                // el precio equivocado, mucho más caro, si "3.5-flash" fuera primero).
                '3.6-flash' => ['in' => 1.5,  'out' => 7.5],  // Gemini 3.6 Flash
                '3.5-flash-lite' => ['in' => 0.3,  'out' => 2.5],  // Gemini 3.5 Flash-Lite
                '3.5-flash' => ['in' => 1.5,  'out' => 9.0],  // Gemini 3.5 Flash
                '2.5-flash' => ['in' => 0.3,  'out' => 2.5],  // Gemini 2.5 Flash
                '2.5-pro' => ['in' => 1.25, 'out' => 10.0], // Gemini 2.5 Pro, tramo <=200k tokens
                'default' => ['in' => 1.5,  'out' => 7.5],
            ],
        ];

        $tabla = $precios[$proveedor] ?? $precios['claude'];
        $p = $tabla['default'];
        foreach ($tabla as $clave => $valores) {
            if ($clave !== 'default' && $modelo && stripos($modelo, $clave) !== false) {
                $p = $valores;
                break;
            }
        }

        return round(($tokensIn / 1_000_000 * $p['in']) + ($tokensOut / 1_000_000 * $p['out']), 5);
    }
}
