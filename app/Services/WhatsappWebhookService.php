<?php

namespace App\Services;

use App\Events\WhatsappConversacionActualizada;
use App\Events\WhatsappMensajeNuevo;
use App\Jobs\MarketingConfirmarBloqueoJob;
use App\Jobs\ResolverCaptchaAdresJob;
use App\Jobs\WhatsappAcuseRecepcionJob;
use App\Jobs\WhatsappDescargarMediaJob;
use App\Jobs\WhatsappEscalarMultimediaJob;
use App\Jobs\WhatsappResponderIaJob;
use App\Jobs\WhatsappTranscribirAudioJob;
use App\Models\{
    AdresChequeo,
    ConsentimientoDato,
    IaConfiguracionAliado,
    MarketingBloqueado,
    PlanillaEnvioWhatsappDetalle,
    WhatsappConfig,
    WhatsappConversacion,
    WhatsappMensaje
};
use App\Services\Adres\RespuestaCaptcha;
use App\Services\GarvisService;
use App\Services\Cumplimiento\DetectorBajaPublicidad;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WhatsappWebhookService
{
    /** Segundos de silencio a esperar antes de responder, para agrupar mensajes seguidos. */
    private const SEGUNDOS_DEBOUNCE = 6;

    /**
     * Tope máximo de espera total: aunque el cliente siga escribiendo sin pausar (lo que
     * reiniciaría el debounce indefinidamente), a los este tiempo se fuerza la respuesta.
     * Evita que un mensaje de hace varios minutos quede "pendiente" y se combine con algo
     * dicho mucho después, como si fuera parte del mismo pensamiento.
     */
    private const SEGUNDOS_MAX_ESPERA_TOTAL = 20;

    /** Frases de botón (ya normalizadas: minúsculas, sin tildes) que se interpretan como rechazo de publicidad. */
    /**
     * Botón "por ahora no": NO es una baja. El cliente dijo que más adelante, así que se
     * aplaza y vuelve a entrar solo cuando venza. Meterlo en FRASES_NO_INTERESA lo bloquearía
     * para siempre y perdería un cliente que dijo que sí, pero después.
     */
    private const FRASES_APLAZAR = ['por ahora no', 'mas adelante', 'luego', 'despues', 'otro dia'];

    /** Botón "hablar con un asesor": lo atiende una persona, no la IA. */
    private const FRASES_ASESOR = ['hablar con un asesor', 'con un asesor', 'asesor'];

    private const FRASES_NO_INTERESA = [
        'no me interesa', 'no interesa', 'no gracias', 'no quiero', 'no deseo recibir mas',
        'dejar de recibir', 'eliminarme', 'quitarme', 'no contactar', 'unsubscribe', 'stop',
    ];

    public function __construct(protected WhatsappApiService $whatsappApi) {}

    /** Detecta si el texto de un botón de plantilla equivale a un rechazo de publicidad. */
    private static function esBotonNoInteresa(string $textoBoton): bool
    {
        $normalizado = Str::ascii(mb_strtolower(trim($textoBoton)));

        foreach (self::FRASES_NO_INTERESA as $frase) {
            if (str_contains($normalizado, $frase)) return true;
        }

        return false;
    }

    /** ¿El botón pide que le escribamos más adelante? */
    private static function esBotonAplazar(string $textoBoton): bool
    {
        $n = Str::ascii(mb_strtolower(trim($textoBoton)));

        foreach (self::FRASES_APLAZAR as $f) {
            if (str_contains($n, $f)) return true;
        }

        return false;
    }

    /** ¿El botón pide hablar con una persona? */
    private static function esBotonAsesor(string $textoBoton): bool
    {
        $n = Str::ascii(mb_strtolower(trim($textoBoton)));

        foreach (self::FRASES_ASESOR as $f) {
            if (str_contains($n, $f)) return true;
        }

        return false;
    }

    /** Clave de cache compartida con WhatsappResponderIaJob para el debounce por conversación. */
    public static function claveDebounce(int $conversacionId): string
    {
        return "wa_debounce_conv_{$conversacionId}";
    }

    /** Marca cuándo empezó el lote de mensajes pendiente actual, para poder aplicar el tope máximo. */
    public static function claveInicioLote(int $conversacionId): string
    {
        return "wa_debounce_inicio_lote_{$conversacionId}";
    }

    /**
     * Procesa el payload completo de un webhook de Meta.
     * Meta puede enviar múltiples entradas en un solo request.
     */
    public function procesarPayload(array $data, bool $firmaVerificada = false): void
    {
        $entries = $data['entry'] ?? [];

        foreach ($entries as $entry) {
            $changes = $entry['changes'] ?? [];
            foreach ($changes as $change) {
                $value = $change['value'] ?? [];

                // Verificar firma del número para identificar el aliado
                $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
                if (!$phoneNumberId) continue;

                // Las actualizaciones de estado (entregado, leído, fallido) van
                // ANTES de resolver la config, y a propósito: se resuelven por
                // `wa_message_id`, que es único en toda la cuenta, así que no
                // necesitan saber de qué aliado son. Colgaban de la rama de
                // config propia y la del número compartido de BryNex hace
                // `continue` sin llegar hasta allá: todo aliado que usa la
                // cuenta de BryNex perdía cada acuse que Meta reportaba. Por
                // eso 46 planillas seguidas se quedaron en «enviado» sin una
                // sola entrega ni un solo rebote — no es que no pasara nada,
                // es que nadie lo escuchaba.
                foreach ($value['statuses'] ?? [] as $status) {
                    $this->procesarActualizacionEstado($status);
                }

                // Lo que Brayan le escribe a GARVIS no es una conversación de
                // Brynex: se desvía antes de buscar aliado y no sigue de largo.
                if (!empty($value['messages'])) {
                    $garvis = app(GarvisService::class);
                    $value['messages'] = array_values(array_filter(
                        $value['messages'],
                        function ($msg) use ($garvis, $phoneNumberId, $firmaVerificada) {
                            if (!$garvis->esParaGarvis($msg, $phoneNumberId, $firmaVerificada)) {
                                return true;
                            }
                            $garvis->recibir($msg);

                            return false;
                        }
                    ));
                }

                $config = WhatsappConfig::where('phone_number_id', $phoneNumberId)
                    ->where('activo', true)
                    ->first();

                // Si no encontramos config específica, buscar si es el número Brynex
                if (!$config) {
                    $brynexPhoneId = \App\Models\ConfiguracionBrynex::obtener('whatsapp_global_phone_number_id') ?: config('services.whatsapp.phone_number_id');
                    if ($phoneNumberId === $brynexPhoneId) {
                        // Buscar aliados que usen la cuenta Brynex — procesar para todos
                        $configs = WhatsappConfig::where('usa_cuenta_brynex', true)
                            ->where('activo', true)
                            ->get();
                        // Para el webhook, necesitamos saber a qué aliado corresponde el número del cliente
                        // Buscamos la conversación existente
                        $this->procesarParaMultipleConfigs($value, $configs->toArray());
                        continue;
                    }
                    continue;
                }

                // Mensajes entrantes
                $mensajes = $value['messages'] ?? [];
                foreach ($mensajes as $msg) {
                    $this->procesarMensajeEntrante($msg, $config->aliado_id, $config, $value);
                }
            }
        }
    }

    /**
     * Procesa un mensaje entrante del cliente.
     */
    public function procesarMensajeEntrante(array $msg, int $alidoId, WhatsappConfig $config, array $changeValue = []): void
    {
        $waFrom = $msg['from'] ?? null;
        $waId   = $msg['id']   ?? null;
        $tipo   = $msg['type'] ?? 'text';

        if (!$waFrom || !$waId) return;

        // Evitar duplicados (Meta puede reenviar mensajes)
        if (WhatsappMensaje::where('wa_message_id', $waId)->exists()) return;

        // Obtener o crear la conversación
        $conversacion = $this->obtenerOCrearConversacion($waFrom, $alidoId, $msg, $changeValue);

        // Si es el número personal de Brayan García, desactivar el bot por completo en esta conversación
        if ($waFrom === '573117762689') {
            $conversacion->update(['bot_activo' => false]);
        }

        // Construir el mensaje
        $dataMensaje = [
            'conversacion_id' => $conversacion->id,
            'aliado_id'       => $alidoId,
            'wa_message_id'   => $waId,
            'direccion'       => 'entrante',
            'tipo'            => $tipo,
        ];

        // Extraer contenido según el tipo
        switch ($tipo) {
            case 'text':
                $dataMensaje['contenido'] = $msg['text']['body'] ?? '';
                break;

            case 'image':
            case 'audio':
            case 'document':
            case 'video':
                $media = $msg[$tipo] ?? [];
                $dataMensaje['media_wa_id']    = $media['id'] ?? null;
                $dataMensaje['media_mime_type']= $media['mime_type'] ?? null;
                $dataMensaje['media_nombre']   = $media['filename'] ?? ($tipo . '_' . now()->timestamp);
                $dataMensaje['contenido']      = $media['caption'] ?? null;
                break;

            case 'button':
                $dataMensaje['contenido'] = $msg['button']['text'] ?? '';
                break;

            default:
                $dataMensaje['contenido'] = '[Tipo de mensaje no soportado: ' . $tipo . ']';
        }

        $mensaje = WhatsappMensaje::create($dataMensaje);

        // Si tiene media, programar descarga en background
        if (!empty($dataMensaje['media_wa_id']) && $config) {
            dispatch(new WhatsappDescargarMediaJob($mensaje->id, $config->aliado_id));
        }

        // Avisos al WhatsApp personal del dueño. Hay dos conversaciones que él atiende
        // directo y no desde el inbox: los deudores de sus préstamos personales y los
        // asesores que responden a las piezas de reclutamiento.
        $textoMensaje = ($dataMensaje['contenido'] ?? '') ?: '['.ucfirst($tipo).']';
        $nombreContacto = $conversacion->nombre_contacto ?: $waFrom;

        if (\App\Services\Finanzas\TelefonosDeudores::esDeudor($waFrom)) {
            app(\App\Services\ReenvioAlDuenoService::class)
                ->enviar($waFrom, $nombreContacto, $textoMensaje, 'Reenvío Préstamo');
        } elseif ($this->vieneDePiezaDeAsesores($conversacion)) {
            // El asesor lo cierra él a mano: la IA solo toma el dato de cuántos clientes
            // maneja y lo deriva. Sin este aviso, esa conversación se queda esperando a que
            // alguien abra el panel.
            app(\App\Services\ReenvioAlDuenoService::class)
                ->enviar($waFrom, $nombreContacto, $textoMensaje, 'Asesor desde anuncio');
        }

        // Actualizar conversación
        $conversacion->renovarVentana();
        $conversacion->incrementarNoLeidos();

        // Emitir evento Reverb para actualizar el chat en tiempo real
        broadcast(new WhatsappMensajeNuevo($mensaje, $conversacion))->toOthers();
        broadcast(new WhatsappConversacionActualizada($conversacion))->toOthers();

        // Botones de la plantilla de reactivación que NO son un rechazo. Se atienden antes
        // que nada porque cada uno contradice lo que haría el flujo normal: aplazar no puede
        // terminar en bloqueo, y pedir un asesor no puede terminar contestado por la IA.
        if ($tipo === 'button') {
            $textoBoton = (string) ($dataMensaje['contenido'] ?? '');

            if (self::esBotonAplazar($textoBoton)) {
                \App\Models\MarketingAplazado::aplazar(
                    $alidoId,
                    $waFrom,
                    \App\Models\MarketingAplazado::DIAS_POR_DEFECTO,
                    'boton_por_ahora_no',
                    'Tocó el botón "' . $textoBoton . '" en una plantilla de marketing.',
                    $conversacion->id
                );
            }

            if (self::esBotonAsesor($textoBoton)) {
                // Sin esto le respondería el bot a alguien que acaba de pedir una persona.
                $conversacion->update(['bot_activo' => false]);

                try {
                    app(\App\Services\AlertaOperativaService::class)->enviar(
                        'Reactivación',
                        'Un contacto pidió hablar con un asesor: ' . ($conversacion->nombre_contacto ?: $waFrom)
                        . ' (' . $waFrom . '). El bot quedó apagado en esa conversación.'
                    );
                } catch (\Throwable $e) {
                    Log::warning('No se pudo avisar del traspaso a asesor', ['error' => $e->getMessage()]);
                }
            }
        }

        // Botón "No me interesa" (u otro equivalente) de una plantilla de marketing: se
        // bloquea de una vez, sin pasar por la IA — es un rechazo explícito, no algo que
        // requiera interpretación.
        $esRechazoPublicidad = $tipo === 'button' && self::esBotonNoInteresa($dataMensaje['contenido'] ?? '');

        // La misma baja pero escrita a mano ("no me escriban más", "BAJA"). Se atiende aquí
        // y no en la IA porque la baja no puede depender de que el modelo interprete bien:
        // si el cliente lo pidió, se honra. DetectorBajaPublicidad es deliberadamente
        // estricto para no confundir un trámite con una baja — en este negocio "cancelar"
        // es pagar y "dar de baja" es retirar a un empleado.
        $esBajaEscrita = !$esRechazoPublicidad
            && $tipo === 'text'
            && DetectorBajaPublicidad::esPeticionDeBaja($dataMensaje['contenido'] ?? null);

        if ($esRechazoPublicidad || $esBajaEscrita) {
            $motivo = $esRechazoPublicidad
                ? 'Tocó el botón "' . $dataMensaje['contenido'] . '" de una plantilla de marketing.'
                : (DetectorBajaPublicidad::motivo($dataMensaje['contenido'] ?? null) ?? 'Pidió por escrito no recibir publicidad.');

            MarketingBloqueado::bloquear(
                $alidoId,
                $waFrom,
                $esRechazoPublicidad ? 'boton_no_interesa' : 'texto_baja',
                $motivo,
                null,
                $conversacion->id
            );

            // Además del bloqueo operativo, queda la prueba de que el titular pidió la baja
            // y de cuándo: es lo que hay que poder mostrar si preguntan por qué se le
            // escribía antes y ya no.
            ConsentimientoDato::revocar(
                $alidoId,
                $waFrom,
                $esRechazoPublicidad ? 'boton_no_interesa' : 'texto_baja',
                [
                    'motivo'          => $motivo,
                    'mensaje'         => mb_substr((string) ($dataMensaje['contenido'] ?? ''), 0, 500),
                    'conversacion_id' => $conversacion->id,
                ]
            );

            dispatch(new MarketingConfirmarBloqueoJob($conversacion->id));
        }

        // Deshacer: quien está bloqueado responde al acuse pidiendo volver. Solo se evalúa
        // si YA está bloqueado, así que un "sí" suelto aquí es inequívoco — es la respuesta
        // al mensaje que le acabamos de mandar, no parte de otra conversación.
        //
        // Se registra como autorización expresa: el titular pidió por escrito que le
        // volviéramos a escribir, y ese texto queda como prueba del opt-in.
        if (!$esRechazoPublicidad && !$esBajaEscrita && $tipo === 'text'
            && DetectorBajaPublicidad::esReactivacion($dataMensaje['contenido'] ?? null)
            && MarketingBloqueado::estaBloqueado($alidoId, $waFrom)) {

            MarketingBloqueado::where('aliado_id', $alidoId)->where('celular', $waFrom)->delete();

            ConsentimientoDato::otorgar(
                $alidoId,
                $waFrom,
                'reactivacion_whatsapp',
                mb_substr((string) ($dataMensaje['contenido'] ?? ''), 0, 500),
                ['conversacion_id' => $conversacion->id],
                ConsentimientoDato::CANAL_WHATSAPP,
                null,
                $conversacion->nombre_contacto
            );

            dispatch(new MarketingConfirmarBloqueoJob($conversacion->id, 'reactivacion'));
        }

        // Si hay un chequeo de ADRES esperando el código de seguridad, este mensaje
        // es la respuesta y no debe llegarle a la IA: el modelo trataría de
        // conversar con un número suelto en vez de completar la consulta.
        if (!$esRechazoPublicidad && $tipo === 'text'
            && $this->encaminarCaptchaAdres($conversacion, $dataMensaje['contenido'] ?? '', $waId, $config)) {
            return;
        }

        // Las notas de voz se transcriben SIEMPRE, atienda el bot o una persona: el texto queda en
        // el inbox y en el aviso de pendientes, donde antes solo se leía "[nota de voz]" y había
        // que escucharlas una por una. Responder sí depende del bot; eso lo decide el propio job.
        if ($tipo === 'audio' && !$esRechazoPublicidad) {
            WhatsappTranscribirAudioJob::dispatch($mensaje->id)->delay(now()->addSeconds(5));
        }

        $iaActiva = (bool) IaConfiguracionAliado::where('aliado_id', $alidoId)->value('activo_whatsapp');

        // Aliados sin IA en WhatsApp: si en unos minutos nadie le ha contestado, se le manda
        // un acuse ("recibimos tu mensaje, ya te atendemos") y la conversación queda
        // pendiente. En Fecop, el 81 % de lo que escribían los clientes (sep-2026) no tuvo
        // ninguna respuesta, ni un «lo recibimos». Los rechazos de publicidad ya reciben su
        // propia confirmación, y las reacciones no son un mensaje que haya que contestar.
        if (!$iaActiva && !$esRechazoPublicidad && !$esBajaEscrita
            && !in_array($tipo, ['reaction', 'unsupported'], true)
            && WhatsappAcuseRecepcionJob::aplicaA($alidoId, $waFrom)) {
            WhatsappAcuseRecepcionJob::dispatch($conversacion->id, $mensaje->id)
                ->delay(now()->addMinutes(WhatsappAcuseRecepcionJob::MINUTOS_ESPERA));
        }

        // Asistente IA: solo si el bot está activo en esta conversación y el aliado
        // tiene la IA activada para WhatsApp. Se procesa en un Job para no bloquear
        // la respuesta al webhook de Meta (~20s de margen).
        if ($conversacion->bot_activo && !$esRechazoPublicidad) {
            if ($iaActiva) {
                if (in_array($tipo, ['text', 'button'], true) && !empty($dataMensaje['contenido'])) {
                    // Indicador de "escribiendo" — gratis, no pasa por la IA ni consume tokens.
                    // Se apaga solo al enviar la respuesta real o a los ~25s si no se envía nada.
                    $this->whatsappApi->marcarLeidoYEscribiendo($waId, $config);

                    // Debounce: si el cliente manda varios mensajes seguidos, esperamos
                    // unos segundos de silencio y los respondemos todos juntos en un solo
                    // turno de IA. El token es el id de este mensaje; si llega uno más
                    // nuevo antes de cumplirse la espera, ese token queda desactualizado y
                    // este job se aborta solo (ver WhatsappResponderIaJob::handle()) — el
                    // job programado para el mensaje más reciente es el que agrupa todo.
                    //
                    // Tope máximo: si el cliente no deja de escribir, el debounce se
                    // reiniciaría sin fin y un mensaje de hace varios minutos (ej. una
                    // petición vieja de planilla) terminaría combinado con algo dicho mucho
                    // después. Por eso se marca cuándo empezó el lote actual, y si ya pasó
                    // el tope, se fuerza la respuesta casi de inmediato en vez de esperar de nuevo.
                    $claveInicioLote = self::claveInicioLote($conversacion->id);
                    $inicioLote = Cache::get($claveInicioLote);
                    if (!$inicioLote) {
                        $inicioLote = now();
                        Cache::put($claveInicioLote, $inicioLote, now()->addSeconds(self::SEGUNDOS_MAX_ESPERA_TOTAL + self::SEGUNDOS_DEBOUNCE + 10));
                    }
                    $superoTope = now()->diffInSeconds($inicioLote) >= self::SEGUNDOS_MAX_ESPERA_TOTAL;
                    $delay = $superoTope ? 1 : self::SEGUNDOS_DEBOUNCE;

                    Cache::put(self::claveDebounce($conversacion->id), $mensaje->id, now()->addSeconds(30));
                    WhatsappResponderIaJob::dispatch($conversacion->id, $mensaje->id)
                        ->delay(now()->addSeconds($delay));
                } elseif (in_array($tipo, ['image', 'document', 'video'], true)) {
                    // El bot no puede leer multimedia: avisa al cliente y escala a un humano
                    // en vez de quedarse en silencio (ej. comprobantes de pago requieren revisión humana).
                    dispatch(new WhatsappEscalarMultimediaJob($conversacion->id, $tipo));
                }
            }
        }
    }

    /**
     * Encamina la respuesta al código de seguridad de ADRES si hay un chequeo
     * esperándola en esta conversación.
     *
     * No todo lo que escriba el cliente mientras espera es el código: puede decir
     * "ya voy", "no la veo bien" o mandar una pregunta. Solo se intercepta lo que
     * tenga forma de código; el resto sigue de largo hacia la IA, que ahí sí puede
     * ayudarle a leerlo o reenviárselo.
     *
     * @return bool true si el mensaje se consumió como captcha.
     */
    private function encaminarCaptchaAdres(
        WhatsappConversacion $conversacion,
        string $texto,
        ?string $waId = null,
        ?WhatsappConfig $config = null
    ): bool {
        $chequeo = AdresChequeo::where('conversacion_id', $conversacion->id)
            ->esperandoCaptcha()
            ->latest('id')
            ->first();

        if (!$chequeo) {
            return false;
        }

        if (!RespuestaCaptcha::pareceCodigo($texto)) {
            return false;
        }

        $limpio = RespuestaCaptcha::normalizar($texto);

        // La consulta tarda unos segundos: el indicador de "escribiendo" evita que
        // parezca que el bot se quedó mudo.
        if ($waId && $config) {
            $this->whatsappApi->marcarLeidoYEscribiendo($waId, $config);
        }

        ResolverCaptchaAdresJob::dispatch($chequeo->id, $limpio);

        return true;
    }

    /**
     * Procesa una actualización de estado de mensaje saliente.
     * Meta notifica cuando un mensaje fue: enviado, entregado o leído.
     */
    public function procesarActualizacionEstado(array $status): void
    {
        $waMessageId = $status['id']     ?? null;
        $nuevoEstado = $status['status'] ?? null;

        if (!$waMessageId || !$nuevoEstado) return;

        $mapEstados = [
            'sent'      => 'enviado',
            'delivered' => 'entregado',
            'read'      => 'leido',
            'failed'    => 'fallido',
        ];

        $estadoLocal = $mapEstados[$nuevoEstado] ?? null;
        if (!$estadoLocal) return;

        $mensaje = WhatsappMensaje::where('wa_message_id', $waMessageId)->first();
        if (!$mensaje) {
            // Incluso si el mensaje no existe en la BD local, loguear la falla de Meta para auditoría
            if ($estadoLocal === 'fallido') {
                Log::error('WhatsApp webhook (auditoría): Mensaje sin registro local falló en Meta', [
                    'wa_message_id' => $waMessageId,
                    'errors' => $status['errors'] ?? 'Sin detalles de error',
                ]);
            }
            return;
        }

        if ($estadoLocal === 'fallido') {
            Log::error("WhatsApp webhook: Mensaje ID {$mensaje->id} falló en Meta", [
                'wa_message_id' => $waMessageId,
                'errors' => $status['errors'] ?? 'Sin detalles de error',
            ]);
        }

        $mensaje->update([
            'estado'    => $estadoLocal,
            'estado_at' => now(),
        ]);

        // Actualizar el estado del detalle de envío masivo si existe
        if ($waMessageId) {
            $detalle = \App\Models\WhatsappEnvioMasivoDetalle::where('wa_message_id', $waMessageId)->first();
            if ($detalle) {
                $detalle->update([
                    'estado' => $estadoLocal,
                    'error'  => $estadoLocal === 'fallido' ? (json_encode($status['errors'] ?? 'Fallo reportado por Meta')) : null,
                ]);

                // Ajustar contadores de la cabecera del lote masivo
                $envio = $detalle->envio;
                if ($envio) {
                    if ($estadoLocal === 'fallido') {
                        $envio->increment('total_fallidos');
                        if ($envio->total_enviados > 0) {
                            $envio->decrement('total_enviados');
                        }
                    }
                }
            }
        }

        $this->reflejarEstadoEnPlanilla($waMessageId, $estadoLocal, $status);

        // Emitir evento para actualizar ícono de estado en el chat
        broadcast(new WhatsappConversacionActualizada($mensaje->conversacion))->toOthers();
    }

    /**
     * Lleva el estado que reporta Meta al detalle del envío de planillas.
     *
     * Hasta ahora esto solo se hacía con los masivos de cobros, y las planillas
     * se quedaban en «enviado» para siempre. Pero «enviado» solo significa que
     * Meta aceptó el mensaje: si el número no tiene WhatsApp, el rebote llega
     * segundos después por webhook y quedaba únicamente en `whatsapp_mensajes`,
     * donde nadie del módulo de planillas mira. Así se perdieron las 136 de una
     * empresa sin que la pantalla dijera nada: verde, y ninguna entregada.
     *
     * Las reglas de qué estado pisa a cuál viven en el modelo, porque la misma
     * decisión la toma `planillas:conciliar-entregas` al reconstruir el pasado.
     */
    private function reflejarEstadoEnPlanilla(string $waMessageId, string $estadoLocal, array $status): void
    {
        PlanillaEnvioWhatsappDetalle::where('wa_message_id', $waMessageId)
            ->first()
            ?->aplicarEstadoDeMeta($estadoLocal, $status['errors'] ?? null);
    }

    /**
     * Obtiene una conversación existente o crea una nueva.
     * Intenta vincularla con un contrato/cliente si el número coincide.
     */
    public function obtenerOCrearConversacion(
        string $waFrom,
        int $alidoId,
        array $msgData = [],
        array $changeValue = []
    ): WhatsappConversacion {
        // Buscar cualquier conversación existente (incluyendo cerrada) para este contacto
        $conversacion = WhatsappConversacion::where('aliado_id', $alidoId)
            ->where('wa_contact_id', $waFrom)
            ->first();

        if ($conversacion) {
            // Atribuir también cuando la conversación YA existía: el referral viene en cada
            // mensaje, no en la conversación. Antes, un cliente de siempre que hiciera clic en
            // un anuncio no contaba para nada — el 16-ago-2026 Meta reportó una conversación
            // desde anuncio y aquí quedó en cero por exactamente esto.
            if (!$conversacion->origen_publicacion_id) {
                $piezaId = $this->piezaDelReferral($msgData, $alidoId);
                if ($piezaId) {
                    $conversacion->update(['origen_publicacion_id' => $piezaId]);
                }
            }

            // Si la conversación ya existe pero tiene un nombre genérico o vacío, intentar corregirlo
            $nombreActual = $conversacion->nombre_contacto;
            if (!$nombreActual || strtolower(trim($nombreActual)) === 'contacto de prueba') {
                $nombreContacto = null;
                $contacts = $changeValue['contacts'] ?? $msgData['contacts'] ?? [];
                foreach ($contacts as $contact) {
                    if (($contact['wa_id'] ?? '') === $waFrom) {
                        $nombreContacto = $contact['profile']['name'] ?? null;
                        break;
                    }
                }
                if (!$nombreContacto && !empty($contacts)) {
                    $nombreContacto = $contacts[0]['profile']['name'] ?? $msgData['profile']['name'] ?? null;
                }

                // Buscar en BD por número celular
                $numeroLimpio = preg_replace('/[^0-9]/', '', $waFrom);
                $clienteConCelular = \App\Models\Cliente::where('aliado_id', $alidoId)
                    ->where(function ($q) use ($numeroLimpio) {
                        $q->where('celular', $numeroLimpio)
                          ->orWhere('celular', '+57' . $numeroLimpio)
                          ->orWhere('celular', 'like', '%' . substr($numeroLimpio, -10));
                    })
                    ->first();

                $nuevoNombre = null;
                if ($clienteConCelular) {
                    $nuevoNombre = trim(
                        ($clienteConCelular->primer_nombre ?? '') . ' ' .
                        ($clienteConCelular->primer_apellido ?? '')
                    );
                }

                if (!$nuevoNombre) {
                    $nuevoNombre = $nombreContacto;
                }

                if ($nuevoNombre && $nuevoNombre !== $nombreActual) {
                    $conversacion->update(['nombre_contacto' => $nuevoNombre]);
                }
            }

            // Si la conversación estaba cerrada, la reabrimos asignada al último agente que le contestó
            if ($conversacion->estado === 'cerrada') {
                $ultimoMensajeSaliente = WhatsappMensaje::where('conversacion_id', $conversacion->id)
                    ->where('direccion', 'saliente')
                    ->whereNotNull('usuario_id')
                    ->latest()
                    ->first();

                if ($ultimoMensajeSaliente) {
                    $conversacion->update([
                        'estado'     => 'asignada',
                        'asignado_a' => $ultimoMensajeSaliente->usuario_id,
                    ]);
                } else {
                    $conversacion->update([
                        'estado'     => 'abierta',
                        'asignado_a' => null,
                    ]);
                }
            }
            return $conversacion;
        }

        // Buscar nombre del perfil en los datos del webhook
        $nombreContacto = null;
        $contacts = $changeValue['contacts'] ?? $msgData['contacts'] ?? [];
        foreach ($contacts as $contact) {
            if (($contact['wa_id'] ?? '') === $waFrom) {
                $nombreContacto = $contact['profile']['name'] ?? null;
                break;
            }
        }
        if (!$nombreContacto) {
            $nombreContacto = $contacts[0]['profile']['name'] ?? $msgData['profile']['name'] ?? null;
        }

        // Intentar vincular con un contrato/cliente y/o empresa por número de celular
        $numeroLimpio = preg_replace('/[^0-9]/', '', $waFrom);
        $contrato = null;
        $empresa  = null;

        // 1. Buscar en la BD (por celular del cliente)
        $clienteConCelular = \App\Models\Cliente::where('aliado_id', $alidoId)
            ->where(function ($q) use ($numeroLimpio) {
                $q->where('celular', $numeroLimpio)
                  ->orWhere('celular', '+57' . $numeroLimpio)
                  ->orWhere('celular', 'like', '%' . substr($numeroLimpio, -10));
            })
            ->first();

        if ($clienteConCelular) {
            // Buscar el contrato vigente de este cliente
            $contrato = \App\Models\Contrato::where('aliado_id', $alidoId)
                ->where('cedula', $clienteConCelular->cedula)
                ->whereIn('estado', ['vigente', 'activo'])
                ->first();

            if (!$nombreContacto) {
                $nombreContacto = trim(
                    ($clienteConCelular->primer_nombre ?? '') . ' ' .
                    ($clienteConCelular->primer_apellido ?? '')
                );
            }
        }

        // 2. Buscar en la BD (por celular o teléfono de la empresa)
        $empresaConTelefono = \App\Models\Empresa::where('aliado_id', $alidoId)
            ->where(function ($q) use ($numeroLimpio) {
                $q->where('celular', $numeroLimpio)
                  ->orWhere('celular', '+57' . $numeroLimpio)
                  ->orWhere('celular', 'like', '%' . substr($numeroLimpio, -10))
                  ->orWhere('telefono', $numeroLimpio)
                  ->orWhere('telefono', '+57' . $numeroLimpio)
                  ->orWhere('telefono', 'like', '%' . substr($numeroLimpio, -10));
            })
            ->first();

        if ($empresaConTelefono) {
            $empresa = $empresaConTelefono;
            if (!$nombreContacto) {
                $nombreContacto = $empresaConTelefono->contacto ?: $empresaConTelefono->empresa;
            }
        }

        $campana = $this->buscarCampanaOrigen($numeroLimpio, $alidoId);
        $publicacionOrigenId = $this->piezaDelReferral($msgData, $alidoId)
            ?? $this->buscarPublicacionOrigen($msgData['text']['body'] ?? '', $alidoId);

        return WhatsappConversacion::create([
            'aliado_id'                 => $alidoId,
            'wa_contact_id'             => $waFrom,
            'nombre_contacto'           => $nombreContacto,
            'contrato_id'               => $contrato?->id,
            'empresa_id'                => $empresa?->id,
            'origen_campana'            => $campana['nombre'],
            'origen_campana_categoria'  => $campana['categoria'],
            'origen_campana_id'         => $campana['campana_id'],
            'origen_publicacion_id'     => $publicacionOrigenId,
            'estado'                    => 'abierta',
            // Explícito porque el default de BD no se refleja en el objeto en memoria que
            // devuelve create() — sin esto, bot_activo queda null aquí y la IA no responde
            // al primer mensaje de un contacto nuevo (bot_activo=null es falsy en el if).
            'bot_activo'                => true,
        ]);
    }

    /**
     * Si el número respondió recientemente (últimos 7 días) a una plantilla de un envío
     * masivo, devuelve su nombre y categoría de Meta (MARKETING|UTILITY|AUTHENTICATION)
     * para dar contexto a la IA (ej. "respondió a una campaña de marketing" vs "respondió
     * a un recordatorio de cobro"). No aplica a conversaciones que ya existían.
     *
     * Si el envío pertenece a una campaña de marketing, también se devuelve su
     * campana_id para que la IA reciba el contexto de venta completo de esa campaña
     * (descripción, objetivo y guía de botones) en vez de partir de cero.
     *
     * @return array{nombre: ?string, categoria: ?string, campana_id: ?int}
     */
    /**
     * Si el primer mensaje del contacto trae el código "ref: P{id}" (el que se agrega
     * automáticamente al link de WhatsApp de cada pieza publicada en redes), atribuye la
     * conversación a esa publicación concreta — atribución real, no una correlación por
     * ventana de tiempo. Solo se llama al CREAR una conversación nueva.
     */
    /**
     * Pieza de la que viene el mensaje, según el `referral` que Meta adjunta a los mensajes
     * que nacen de un anuncio de Click-to-WhatsApp.
     *
     * Es mejor que leer el "(ref: P58)" del texto por dos razones: no obliga a ensuciar el
     * mensaje precargado con un código —que el usuario ve y puede borrar, o que lo hace dudar
     * antes de enviar— y funciona aunque la conversación ya existiera, porque el referral
     * viaja en cada mensaje.
     *
     * `source_id` es el id del anuncio, que es justo lo que se guarda en meta_ad_id al crearlo.
     */
    /**
     * ¿Esta conversación llegó por una de las piezas que buscan asesores?
     *
     * La regla de qué es una pieza de asesores vive en el asistente, que es quien la usa para
     * cambiar todo su guion. Aquí solo se consulta: duplicarla haría que un cambio en una
     * moviera el guion pero no el aviso, o al revés.
     */
    private function vieneDePiezaDeAsesores(WhatsappConversacion $conversacion): bool
    {
        $pieza = $conversacion->origen_publicacion_id
            ? \App\Models\Publicacion::find($conversacion->origen_publicacion_id)
            : null;

        return $pieza ? \App\Services\Ia\AsistenteIaService::esPiezaDeAsesores($pieza) : false;
    }

    private function piezaDelReferral(array $msgData, int $alidoId): ?int
    {
        $ref = $msgData['referral'] ?? null;
        $adId = $ref['source_id'] ?? null;

        if (!$adId) {
            return null;
        }

        return \App\Models\Publicacion::where('aliado_id', $alidoId)
            ->where('meta_ad_id', (string) $adId)
            ->value('id');
    }

    private function buscarPublicacionOrigen(string $texto, int $alidoId): ?int
    {
        if (!preg_match('/ref:\s*P(\d+)/i', $texto, $m)) {
            return null;
        }

        return \App\Models\Publicacion::where('id', (int) $m[1])
            ->where('aliado_id', $alidoId)
            ->value('id');
    }

    private function buscarCampanaOrigen(string $numeroLimpio, int $alidoId): array
    {
        $detalle = \App\Models\WhatsappEnvioMasivoDetalle::query()
            ->where(function ($q) use ($numeroLimpio) {
                $q->where('wa_numero', $numeroLimpio)
                  ->orWhere('wa_numero', '+57' . $numeroLimpio)
                  ->orWhere('wa_numero', 'like', '%' . substr($numeroLimpio, -10));
            })
            ->whereIn('estado', ['enviado', 'entregado', 'leido'])
            ->whereHas('envio', fn ($q) => $q->where('aliado_id', $alidoId))
            ->where('created_at', '>=', now()->subDays(7))
            ->with('envio.plantilla:id,nombre_display,categoria')
            ->latest()
            ->first();

        return [
            'nombre'     => $detalle?->envio?->plantilla?->nombre_display,
            'categoria'  => $detalle?->envio?->plantilla?->categoria,
            'campana_id' => $detalle?->envio?->campana_id,
        ];
    }

    /**
     * Cuando múltiples aliados comparten el número Brynex,
     * buscamos a qué aliado pertenece la conversación por el número del cliente.
     */
    private function procesarParaMultipleConfigs(array $value, array $configs): void
    {
        $mensajes = $value['messages'] ?? [];
        $aliadoIds = array_map('intval', array_column($configs, 'aliado_id'));

        foreach ($mensajes as $msg) {
            $waFrom = $msg['from'] ?? null;
            if (!$waFrom) continue;

            // 1. Conversación activa con este número en alguno de los aliados del número
            //    compartido (la más reciente; COALESCE evita problemas con NULLs).
            //    Si no hay activa, sirve una cerrada: procesarMensajeEntrante la reabre.
            $convExistente = WhatsappConversacion::where('wa_contact_id', $waFrom)
                ->whereIn('aliado_id', $aliadoIds)
                ->orderByRaw("CASE WHEN estado IN ('abierta','asignada') THEN 0 ELSE 1 END")
                ->orderByRaw('COALESCE(ultimo_mensaje_at, updated_at) DESC')
                ->first();

            if ($convExistente) {
                $config = WhatsappConfig::where('aliado_id', $convExistente->aliado_id)->first();
                if ($config) {
                    $this->procesarMensajeEntrante($msg, $convExistente->aliado_id, $config, $value);
                }
                continue;
            }

            // 2. Sin conversación: se busca el celular en clientes y empresas. Antes aquí el
            //    mensaje se botaba sin dejar rastro; nadie se enteraba de que alguien escribió.
            $bandeja = app(WhatsappBandejaCompartida::class);
            $resuelto = $bandeja->resolverAliado($waFrom, $aliadoIds);
            $aliadoId = $resuelto['aliado_id'];
            $motivoBandeja = null;

            if (!$aliadoId) {
                // 3. No se sabe de quién es (o está en varios): a la bandeja de BryNex,
                //    pendiente, para moverla a mano desde el chat.
                $aliadoId = WhatsappBandejaCompartida::aliadoBandeja();
                $motivoBandeja = $resuelto['motivo'];

                if (!in_array($aliadoId, $aliadoIds, true)) {
                    Log::error('WhatsApp número compartido: mensaje sin aliado y la bandeja de BryNex no usa la cuenta compartida', [
                        'from' => $waFrom, 'motivo' => $resuelto['motivo'],
                    ]);
                    continue;
                }
            }

            $config = WhatsappConfig::where('aliado_id', $aliadoId)->first();
            if (!$config) continue;

            $this->procesarMensajeEntrante($msg, $aliadoId, $config, $value);

            if ($motivoBandeja) {
                WhatsappConversacion::where('aliado_id', $aliadoId)
                    ->where('wa_contact_id', $waFrom)
                    ->first()
                    ?->marcarPendiente($motivoBandeja);

                Log::info('WhatsApp número compartido: mensaje a la bandeja de BryNex', [
                    'from' => $waFrom, 'motivo' => $motivoBandeja,
                ]);
            }
        }
    }
}
