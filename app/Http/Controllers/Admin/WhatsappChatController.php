<?php

namespace App\Http\Controllers\Admin;

use App\Events\WhatsappConversacionActualizada;
use App\Http\Controllers\Controller;
use App\Models\{IaConfiguracionAliado, MarketingBloqueado, User, WhatsappConfig, WhatsappConversacion, WhatsappMensaje};
use App\Services\Finanzas\TelefonosDeudores;
use App\Services\WhatsappApiService;
use App\Services\WhatsappEsperandoRespuesta;
use App\Services\WhatsappTipoContacto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB, Storage};

/**
 * Controlador del Chat de WhatsApp.
 * Gestiona el inbox, visualización y envío de mensajes.
 */
class WhatsappChatController extends Controller
{
    /** Por dónde se atendió a alguien fuera de esta línea. */
    public const MEDIOS_ATENCION = [
        'otro_whatsapp' => 'Otro WhatsApp',
        'llamada'       => 'Llamada',
        'presencial'    => 'En la oficina / en persona',
        'correo'        => 'Correo',
        'otro'          => 'Otro medio',
    ];

    /**
     * Textos del acuse de recibido, según lo último que mandó el cliente. Sale solo
     * cuando un asesor pulsa el botón: nunca automático (decisión del dueño, 5-oct-2026).
     */
    private const TEXTOS_ACUSE = [
        'text'     => 'Hola 👋 Recibimos tu mensaje. En breve te respondemos.',
        'button'   => 'Hola 👋 Recibimos tu respuesta. En breve te atendemos.',
        'image'    => 'Recibimos tu imagen 📎. Si es un comprobante de pago, lo revisamos y te confirmamos en breve.',
        'document' => 'Recibimos tu documento 📎. Lo revisamos y te respondemos en breve.',
        'video'    => 'Recibimos tu video 📎. Lo revisamos y te respondemos en breve.',
        'audio'    => 'Recibimos tu nota de voz 🎙️. En breve te respondemos.',
    ];

    public function __construct(
        protected WhatsappApiService $apiService,
        protected WhatsappTipoContacto $tipos,
        protected WhatsappEsperandoRespuesta $esperando,
    ) {}

    /**
     * Inbox de conversaciones del aliado.
     * Muestra: General (todas sin asignar + asignadas) y Mis conversaciones.
     */
    public function index(Request $request)
    {
        $alidoId = session('aliado_id_activo');
        $tab     = $request->get('tab', 'general');
        $buscar  = $request->get('buscar');
        $tipo    = $this->tipoContactoPedido($request);

        [
            'conversaciones' => $conversaciones,
            'totalNoLeidos'  => $totalNoLeidos,
            'totalIa'        => $totalIa,
            'totalEsperando' => $totalEsperando,
            'totalSinAtender' => $totalSinAtender,
            'conteoTipos'    => $conteoTipos,
        ] = $this->cargarDatosSidebar($alidoId, $tab, $buscar, $tipo);

        // Usuarios del aliado para la asignación
        $usuarios = User::where('aliado_id', $alidoId)
            ->where('activo', true)
            ->orderBy('nombre')
            ->get(['id', 'nombre']);

        return view('admin.whatsapp.chat.index', compact(
            'conversaciones', 'tab', 'buscar', 'tipo', 'totalNoLeidos', 'totalIa', 'totalEsperando',
            'totalSinAtender', 'conteoTipos', 'usuarios'
        ));
    }

    /**
     * Vista individual del chat con todos los mensajes.
     */
    public function show(Request $request, int $id)
    {
        $alidoId      = session('aliado_id_activo');
        $tab          = $request->get('tab', 'general');
        $buscar       = $request->get('buscar');
        $tipo         = $this->tipoContactoPedido($request);

        $conversacion = $this->findConversacionProtected($alidoId, $id);
        $conversacion->resetNoLeidos();

        // Query directa desde WhatsappMensaje para evitar el ORDER BY heredado
        // de la relación hasMany (que tiene ->orderBy('created_at') fijo).
        // SQL Server rechaza tener la misma columna dos veces en ORDER BY.
        $mensajes = WhatsappMensaje::where('conversacion_id', $conversacion->id)
            ->with(['usuario:id,nombre', 'plantilla:id,nombre_display'])
            ->orderBy('created_at', 'desc')
            ->take(150)
            ->get()
            ->sortBy('created_at')
            ->values();

        // Usuarios y plantillas
        $usuarios = User::where('aliado_id', $alidoId)
            ->where('activo', true)
            ->orderBy('nombre')
            ->get(['id', 'nombre']);

        $config = WhatsappConfig::paraAliado($alidoId);
        $effectiveAliadoId = $alidoId;
        if ($config->usa_cuenta_brynex) {
            $aliadoBrynex = \App\Models\Aliado::where('nombre', 'BryNex')->first();
            $effectiveAliadoId = $aliadoBrynex ? $aliadoBrynex->id : 1;
        }

        $plantillas = \App\Models\WhatsappPlantilla::delAliado($effectiveAliadoId)
            ->aprobadas()
            ->select('id', 'nombre', 'nombre_display', 'cuerpo', 'variables_mapa')
            ->get();

        // Sidebar — método compartido, sin duplicar lógica
        [
            'conversaciones' => $conversaciones,
            'totalNoLeidos'  => $totalNoLeidos,
            'totalIa'        => $totalIa,
            'totalEsperando' => $totalEsperando,
            'totalSinAtender' => $totalSinAtender,
            'conteoTipos'    => $conteoTipos,
        ] = $this->cargarDatosSidebar($alidoId, $tab, $buscar, $tipo);

        // Mapear conversaciones para Alpine.js
        $conversacionesData = $this->mapearConversacionesSidebar($conversaciones);

        $mensajesData = $this->mapearMensajes($mensajes);

        $conversacionData = [
            'id'                 => $conversacion->id,
            'nombre'             => $conversacion->nombreMostrar(),
            'celular'            => $conversacion->wa_contact_id,
            'contrato_id'        => $conversacion->contrato_id,
            'contrato_url'       => $conversacion->cliente_url,
            'estado'             => $conversacion->estado,
            'asignado_a'         => $conversacion->asignado_a,
            'asignado_nombre'    => $conversacion->asignado?->nombre,
            'bot_activo'         => $conversacion->bot_activo,
            'atendida_por_ia'    => $this->esAtendidaPorIa($conversacion),
            'pendiente_atencion' => $conversacion->pendiente_atencion,
            'pendiente_motivo'   => $conversacion->pendiente_motivo,
            'ventana_activa'     => $conversacion->ventanaActiva(),
            'ventana_minutos'    => $conversacion->minutosVentanaRestante(),
        ] + $this->identidadContacto($conversacion, $alidoId);

        // El botón «Reabrir conversación» solo sale si Meta ya aprobó su plantilla aquí.
        $reabrirDisponible = $plantillas->contains('nombre', \App\Models\WhatsappPlantilla::SISTEMA_REABRIR);

        // Aliados a los que se puede mover esta conversación: solo tiene sentido en el
        // número compartido de BryNex (la bandeja de «sin identificar») y solo para BryNex.
        $aliadosMover = ($config->usa_cuenta_brynex && Auth::user()->es_brynex)
            ? \App\Models\Aliado::whereIn('id', WhatsappConfig::where('usa_cuenta_brynex', true)->where('activo', true)->pluck('aliado_id'))
                ->where('id', '<>', $alidoId)
                ->where('activo', true)
                ->orderBy('nombre')
                ->get(['id', 'nombre'])
            : collect();

        return view('admin.whatsapp.chat.show', compact(
            'conversacion', 'mensajes', 'usuarios', 'plantillas',
            'conversaciones', 'conversacionesData', 'tab', 'buscar', 'tipo',
            'totalNoLeidos', 'totalIa', 'totalEsperando', 'totalSinAtender', 'conteoTipos',
            'mensajesData', 'conversacionData', 'aliadosMover', 'reabrirDisponible'
        ));
    }

    /**
     * Envía un mensaje desde el sistema al cliente.
     * Soporta: texto, imagen, audio, documento, plantilla.
     */
    public function enviarMensaje(Request $request, int $id)
    {
        $alidoId      = session('aliado_id_activo');
        $conversacion = $this->findConversacionProtected($alidoId, $id);
        $config       = WhatsappConfig::paraAliado($alidoId);

        if (!$config->credencialesCompletas()) {
            return response()->json(['ok' => false, 'error' => 'No hay credenciales de WhatsApp configuradas.'], 422);
        }

        $tipo = $request->input('tipo', 'text');

        // ── Validación según tipo ──────────────────────────────────────
        $rules = ['tipo' => 'required|in:text,image,audio,document,template'];

        if ($tipo === 'text') {
            $rules['contenido'] = 'required|string|max:4096';
        } elseif ($tipo === 'template') {
            $rules['plantilla_id'] = 'required|integer|exists:whatsapp_plantillas,id';
            $rules['parametros']   = 'nullable|array';
        } else {
            $rules['archivo'] = 'required|file|max:25600'; // 25MB máx
            $rules['caption'] = 'nullable|string|max:900'; // Meta admite 1024 con la firma
        }

        $validated = $request->validate($rules);

        // ── Verificar ventana de 24h para mensajes libres ──────────────
        if ($tipo !== 'template' && !$conversacion->ventanaActiva()) {
            return response()->json([
                'ok'    => false,
                'error' => 'La ventana de 24h no está activa. Debes enviar una plantilla aprobada para iniciar o reabrir la conversación.',
            ], 422);
        }

        // ── Enviar según tipo ──────────────────────────────────────────
        $resultado = match($tipo) {
            'text'     => $this->enviarTexto($conversacion, $validated, $config),
            'template' => $this->enviarTemplate($conversacion, $validated, $config, $alidoId),
            default    => $this->enviarMedia($conversacion, $request, $tipo, $config),
        };

        if (!$resultado['ok']) {
            return response()->json(['ok' => false, 'error' => $resultado['error']], 422);
        }

        $this->trasEnvioHumano($conversacion);

        return response()->json([
            'ok'             => true,
            'mensaje'        => $resultado['mensaje'],
            // El mismo mensaje en el formato del chat (URL del adjunto ya servible, texto
            // firmado), para pintarlo tal cual sin recargar.
            'mensaje_chat'   => $this->mapearMensajes(collect([$resultado['mensaje']]))[0],
            'ventana_activa' => $conversacion->fresh()->ventanaActiva(),
            'ventana_minutos'=> $conversacion->fresh()->minutosVentanaRestante(),
        ]);
    }

    /**
     * Autoasignación al agente emisor actual. Si un humano escribe, el bot se silencia
     * en esta conversación hasta que alguien lo reactive explícitamente, y se resuelve
     * cualquier aviso de "pendiente por atender".
     */
    private function trasEnvioHumano(WhatsappConversacion $conversacion): void
    {
        $conversacion->update([
            'asignado_a'          => Auth::id(),
            'asignado_at'         => now(),
            'estado'              => 'asignada',
            'ultimo_mensaje_at'   => now(),
            'bot_activo'          => false,
            'pendiente_atencion'  => false,
            'pendiente_motivo'    => null,
        ]);
    }

    /**
     * Reabre una conversación con la ventana de 24 h vencida: le manda al cliente la
     * plantilla `reabrir_conversacion`, con el botón «Continuar». Cuando lo toca, Meta
     * abre otras 24 h de texto libre.
     *
     * Existe porque con la ventana vencida solo quedaban plantillas de cobro o de
     * planilla, que no vienen al caso, y el asesor terminaba contestando desde su
     * celular (donde el sistema ya no ve nada).
     *
     * Una sola invitación cada 24 h: insistir con la misma plantilla a quien no tocó
     * el botón es justo lo que hace que la gente reporte el número.
     */
    public function reabrir(int $id)
    {
        $alidoId      = session('aliado_id_activo');
        $conversacion = $this->findConversacionProtected($alidoId, $id);
        $config       = WhatsappConfig::paraAliado($alidoId);

        if (!$config->credencialesCompletas()) {
            return response()->json(['ok' => false, 'error' => 'No hay credenciales de WhatsApp configuradas.'], 422);
        }

        if ($conversacion->ventanaActiva()) {
            return response()->json(['ok' => false, 'error' => 'La ventana ya está abierta: puedes escribirle directamente.'], 422);
        }

        $plantilla = $this->plantillaReabrir($alidoId, $config);
        if (!$plantilla) {
            return response()->json(['ok' => false, 'error' => 'La plantilla para reabrir todavía no está aprobada por Meta en esta cuenta.'], 422);
        }

        $yaInvitado = WhatsappMensaje::where('conversacion_id', $conversacion->id)
            ->where('plantilla_id', $plantilla->id)
            ->where('created_at', '>=', now()->subHours(24))
            ->exists();
        if ($yaInvitado) {
            return response()->json([
                'ok'    => false,
                'error' => 'Ya se le envió la invitación a continuar hace menos de 24 h. Cuando toque «Continuar» se abre la ventana.',
            ], 422);
        }

        $nombreAliado = \App\Models\Aliado::find($alidoId)?->nombre ?: 'nuestro equipo';

        $resultado = $this->enviarTemplate($conversacion, [
            'plantilla_id' => $plantilla->id,
            'parametros'   => [$nombreAliado],
        ], $config, $alidoId);

        if (!$resultado['ok']) {
            return response()->json(['ok' => false, 'error' => $resultado['error']], 422);
        }

        $this->trasEnvioHumano($conversacion);

        $resultado['mensaje']->load(['usuario:id,nombre', 'plantilla:id,nombre_display']);

        return response()->json([
            'ok'      => true,
            'mensaje' => $this->mapearMensajes(collect([$resultado['mensaje']]))[0],
        ]);
    }

    /**
     * La plantilla de reapertura aprobada en la cuenta con la que envía este aliado.
     * Las de la cuenta compartida están a nombre del aliado BryNex.
     */
    private function plantillaReabrir(int $alidoId, WhatsappConfig $config): ?\App\Models\WhatsappPlantilla
    {
        $duenoCuenta = $config->usa_cuenta_brynex ? \App\Services\WhatsappBandejaCompartida::aliadoBandeja() : $alidoId;

        return \App\Models\WhatsappPlantilla::delAliado($duenoCuenta)
            ->aprobadas()
            ->where('nombre', \App\Models\WhatsappPlantilla::SISTEMA_REABRIR)
            ->first();
    }

    /**
     * Acuse de recibido con un clic: «recibimos tu mensaje, en breve te respondemos».
     *
     * Para cuando el asesor ve el mensaje pero todavía no puede atenderlo. El cliente
     * sabe que lo leyeron, y la conversación sigue pendiente y en «Esperando»: el acuse
     * no es la respuesta. No cambia la asignación ni toca el bot.
     */
    public function enviarAcuse(int $id)
    {
        $alidoId      = session('aliado_id_activo');
        $conversacion = $this->findConversacionProtected($alidoId, $id);
        $config       = WhatsappConfig::paraAliado($alidoId);

        if (!$config->credencialesCompletas()) {
            return response()->json(['ok' => false, 'error' => 'No hay credenciales de WhatsApp configuradas.'], 422);
        }

        if (!$conversacion->ventanaActiva()) {
            return response()->json(['ok' => false, 'error' => 'La ventana de 24 h venció: usa «Reabrir conversación» o una plantilla.'], 422);
        }

        $ultimoEntrante = WhatsappMensaje::where('conversacion_id', $conversacion->id)
            ->where('direccion', 'entrante')
            ->orderByDesc('id')
            ->value('tipo');

        $nombreAgente = Auth::user()->nombre;
        $texto = "*Atendido por {$nombreAgente}:*\n\n" . (self::TEXTOS_ACUSE[$ultimoEntrante] ?? self::TEXTOS_ACUSE['text']);

        $resultado = $this->apiService->enviarTexto($conversacion->wa_contact_id, $texto, $config);
        if (!$resultado['ok']) {
            return response()->json(['ok' => false, 'error' => $resultado['error']], 422);
        }

        $mensaje = WhatsappMensaje::create([
            'conversacion_id' => $conversacion->id,
            'aliado_id'       => $conversacion->aliado_id,
            'wa_message_id'   => $resultado['wa_message_id'],
            'direccion'       => 'saliente',
            'tipo'            => 'text',
            'contenido'       => $texto,
            'estado'          => 'enviado',
            'usuario_id'      => Auth::id(),
        ]);

        $motivo = "{$nombreAgente} le envió acuse de recibido; falta atenderla.";
        $conversacion->update(['ultimo_mensaje_at' => now()]);
        $conversacion->marcarPendiente($motivo);

        try {
            broadcast(new \App\Events\WhatsappMensajeNuevo($mensaje, $conversacion))->toOthers();
            broadcast(new WhatsappConversacionActualizada($conversacion))->toOthers();
        } catch (\Throwable $e) {
            // Sin Reverb el mensaje igual salió; los demás lo ven al recargar.
        }

        return response()->json([
            'ok'               => true,
            'mensaje_chat'     => $this->mapearMensajes(collect([$mensaje]))[0],
            'pendiente_motivo' => $motivo,
        ]);
    }

    /**
     * El cliente ya fue atendido por fuera de esta línea (otro WhatsApp, llamada, en
     * persona). Queda una nota interna en el chat —no se le envía nada a Meta— y, como
     * el último mensaje pasa a ser nuestro, la conversación sale de «Esperando» y del
     * aviso. Nació porque con la ventana vencida el asesor contestaba desde su celular
     * y aquí seguía figurando como sin respuesta.
     */
    public function marcarAtendida(Request $request, int $id)
    {
        $alidoId      = session('aliado_id_activo');
        $conversacion = $this->findConversacionProtected($alidoId, $id);

        $validated = $request->validate([
            'medio' => 'required|in:'.implode(',', array_keys(self::MEDIOS_ATENCION)),
            'nota'  => 'nullable|string|max:500',
        ]);

        $contenido = '📝 Atendido por '.mb_strtolower(self::MEDIOS_ATENCION[$validated['medio']])
            .(trim((string) ($validated['nota'] ?? '')) !== '' ? ': '.trim($validated['nota']) : '');

        $mensaje = WhatsappMensaje::create([
            'conversacion_id' => $conversacion->id,
            'aliado_id'       => $conversacion->aliado_id,
            'direccion'       => 'saliente',
            'tipo'            => 'nota',
            'contenido'       => $contenido,
            'usuario_id'      => Auth::id(),
        ]);

        $conversacion->update([
            'asignado_a'         => Auth::id(),
            'asignado_at'        => now(),
            'estado'             => 'asignada',
            'ultimo_mensaje_at'  => now(),
            'pendiente_atencion' => false,
            'pendiente_motivo'   => null,
        ]);

        $mensaje->setRelation('usuario', Auth::user());
        broadcast(new \App\Events\WhatsappMensajeNuevo($mensaje, $conversacion))->toOthers();
        broadcast(new WhatsappConversacionActualizada($conversacion))->toOthers();

        return response()->json([
            'ok'              => true,
            'mensaje'         => $this->mapearMensajes(collect([$mensaje]))[0],
            'asignado_a'      => Auth::id(),
            'asignado_nombre' => Auth::user()->nombre,
        ]);
    }

    /**
     * Devuelve al inbox general las conversaciones asignadas donde el cliente lleva
     * horas esperando y el asesor no ha respondido. Es el botón de la pestaña Esperando.
     *
     * Manual a propósito: lo decide el aliado (quien tenga permiso de asignar), no el
     * sistema. Antes de quedar así estuvo programado cada 15 minutos y el dueño lo frenó.
     */
    public function liberarSinAtender()
    {
        $alidoId = session('aliado_id_activo');
        $horas   = self::horasSinAtender();
        $quien   = Auth::user()->nombre;

        $lista = $this->esperando->paraLiberar($alidoId, $horas);

        foreach ($lista as $cv) {
            $asesor = $cv->asignado?->nombre ?: 'El asesor asignado';
            $cv->liberarPorInactividad("{$asesor} no respondió en {$horas} h: {$quien} la devolvió al inbox general.");

            try {
                broadcast(new WhatsappConversacionActualizada($cv))->toOthers();
            } catch (\Throwable $e) {
                // Sin Reverb el cambio igual queda hecho; los demás lo ven al recargar.
            }
        }

        return response()->json([
            'ok'      => true,
            'total'   => $lista->count(),
            'mensaje' => $lista->count() === 1
                ? '1 conversación devuelta al inbox general'
                : $lista->count().' conversaciones devueltas al inbox general',
        ]);
    }

    /** Horas con el cliente esperando para que una asignada cuente como «sin atender». */
    public static function horasSinAtender(): int
    {
        return max(1, (int) config('services.whatsapp.liberar_horas', 4));
    }

    /**
     * Asigna o reasigna una conversación a un usuario.
     */
    public function asignar(Request $request, int $id)
    {
        $alidoId      = session('aliado_id_activo');
        $conversacion = $this->findConversacionProtected($alidoId, $id);

        $validated = $request->validate([
            'user_id' => 'nullable|integer|exists:users,id',
        ]);

        if ($validated['user_id']) {
            $conversacion->asignarA($validated['user_id']);
            $usuario = User::find($validated['user_id']);
            $msg = "Conversación asignada a {$usuario->nombre}";
        } else {
            $conversacion->liberar();
            $msg = 'Conversación devuelta al inbox general';
        }

        // Sin esto, otros asesores viendo el mismo inbox nunca se enteran de la asignación
        // hasta que refrescan la página a mano — el sidebar no tenía ninguna señal en tiempo real.
        broadcast(new WhatsappConversacionActualizada($conversacion))->toOthers();

        return response()->json(['ok' => true, 'mensaje' => $msg]);
    }

    /**
     * Activa o desactiva el Asistente IA en una conversación puntual.
     */
    /**
     * Reactiva la IA, o la silencia y toma la conversación para el usuario actual
     * (apagar el bot desde el chat significa "voy a atenderla yo").
     */
    public function toggleBot(Request $request, int $id)
    {
        $alidoId      = session('aliado_id_activo');
        $conversacion = $this->findConversacionProtected($alidoId, $id);

        $validated = $request->validate(['activo' => 'required|boolean']);
        $iaActivaAliado = IaConfiguracionAliado::where('aliado_id', $alidoId)->value('activo_whatsapp') ?? false;

        if ($validated['activo']) {
            $conversacion->activarBot();
            $mensaje = $iaActivaAliado
                ? 'Asistente IA reactivado en esta conversación'
                : 'Reactivado aquí, pero el Asistente IA está apagado para todo el aliado en /brynex/ia — no responderá hasta que lo actives ahí.';
        } else {
            $conversacion->tomarConversacion(Auth::id());
            $mensaje = 'Tomaste esta conversación — el Asistente IA ya no responderá aquí';
        }

        $conversacion->refresh();

        // Mismo motivo que en asignar(): sin esto, otros asesores viendo el mismo inbox no se
        // enteran de que alguien tomó/reactivó la IA hasta que recargan la página.
        broadcast(new WhatsappConversacionActualizada($conversacion))->toOthers();

        return response()->json([
            'ok'                 => true,
            'bot_activo'         => $conversacion->bot_activo,
            'atendida_por_ia'    => $iaActivaAliado && $conversacion->bot_activo,
            'pendiente_atencion' => $conversacion->pendiente_atencion,
            'asignado_a'         => $conversacion->asignado_a,
            'asignado_nombre'    => $conversacion->asignado?->nombre,
            'estado'             => $conversacion->estado,
            'mensaje'            => $mensaje,
        ]);
    }

    /**
     * Mueve una conversación de la bandeja del número compartido al aliado que
     * corresponde. Solo BryNex, que es quien ve esa bandeja y conoce a todos los aliados.
     */
    public function moverAliado(Request $request, int $id, \App\Services\WhatsappBandejaCompartida $bandeja)
    {
        abort_unless(Auth::user()->es_brynex, 403, 'Solo BryNex puede mover conversaciones entre aliados.');

        $alidoId      = session('aliado_id_activo');
        $conversacion = $this->findConversacionProtected($alidoId, $id);

        $validated = $request->validate([
            'aliado_id' => 'required|integer|exists:aliados,id|not_in:'.$alidoId,
        ]);

        try {
            $destino = $bandeja->mover($conversacion, (int) $validated['aliado_id'], Auth::id());
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        // La conversación ya no es de este aliado: en el canal de origen se avisa como
        // cerrada para que desaparezca del sidebar de quien la esté mirando, y en el
        // destino entra como nueva.
        broadcast(new WhatsappConversacionActualizada($destino));

        $nombre = \App\Models\Aliado::find($destino->aliado_id)?->nombre ?: 'el aliado';

        return response()->json([
            'ok'       => true,
            'mensaje'  => "Conversación movida a {$nombre}",
            'redirect' => route('admin.whatsapp.chat.index', ['tab' => 'esperando']),
        ]);
    }

    /**
     * Cierra una conversación.
     */
    public function cerrar(int $id)
    {
        $alidoId      = session('aliado_id_activo');
        $conversacion = $this->findConversacionProtected($alidoId, $id);
        $conversacion->cerrar();

        return response()->json(['ok' => true]);
    }

    /**
     * Bloquea el número de esta conversación para que nunca más reciba campañas de
     * marketing (no afecta la conversación actual ni el servicio ya en curso).
     */
    public function noContactar(Request $request, int $id)
    {
        $alidoId      = session('aliado_id_activo');
        $conversacion = $this->findConversacionProtected($alidoId, $id);

        $validated = $request->validate(['motivo' => 'nullable|string|max:500']);

        MarketingBloqueado::bloquear(
            $alidoId,
            $conversacion->wa_contact_id,
            'asesor',
            $validated['motivo'] ?? 'Bloqueado manualmente desde el chat',
            Auth::id(),
            $conversacion->id
        );

        return response()->json(['ok' => true, 'mensaje' => 'Número bloqueado de futuras campañas de marketing']);
    }

    /**
     * Marca los mensajes de una conversación como leídos.
     */
    public function marcarLeido(int $id)
    {
        $alidoId      = session('aliado_id_activo');
        $conversacion = $this->findConversacionProtected($alidoId, $id);
        $conversacion->resetNoLeidos();

        return response()->json(['ok' => true]);
    }

    /**
     * Descarga/sirve un archivo media (imagen, audio, PDF).
     */
    public function descargarMedia(int $mensajeId)
    {
        $alidoId = session('aliado_id_activo');
        $mensaje = WhatsappMensaje::where('aliado_id', $alidoId)->findOrFail($mensajeId);

        if (!$mensaje->media_url || !Storage::disk('local')->exists($mensaje->media_url)) {
            abort(404, 'Archivo no encontrado');
        }

        $contenido = Storage::disk('local')->get($mensaje->media_url);
        $mimeType  = $mensaje->media_mime_type ?? 'application/octet-stream';
        $nombre    = $mensaje->media_nombre ?? basename($mensaje->media_url);

        return response($contenido, 200)
            ->header('Content-Type', $mimeType)
            ->header('Content-Disposition', 'inline; filename="' . $nombre . '"');
    }

    /**
     * API: total de mensajes no leídos del aliado activo (para badge en menú).
     */
    public function apiNoLeidos()
    {
        $alidoId = session('aliado_id_activo');
        $user    = Auth::user();
        $userId  = $user->id;
        $esAdmin = $user->es_brynex || $user->hasRole(['admin', 'superadmin']);

        $query = WhatsappConversacion::delAliado($alidoId)->activas();

        if (!$esAdmin) {
            $query->where(function ($q) use ($userId) {
                $q->where('asignado_a', $userId)->orWhereNull('asignado_a');
            });
        }

        $this->excluirDeudoresDelDueno($query, $userId);

        return response()->json(['total' => (int) $query->sum('total_mensajes_no_leidos')]);
    }

    /**
     * API: retorna los mensajes e información de una conversación (para cambio de chat SPA).
     * Limitado a los últimos 150 mensajes para máxima velocidad.
     */
    public function apiMensajes(int $id)
    {
        $alidoId      = session('aliado_id_activo');
        $conversacion = $this->findConversacionProtected($alidoId, $id);
        $conversacion->resetNoLeidos();

        $mensajes = WhatsappMensaje::where('conversacion_id', $conversacion->id)
            ->with(['usuario:id,nombre', 'plantilla:id,nombre_display'])
            ->orderBy('created_at', 'desc')
            ->take(150)
            ->get()
            ->sortBy('created_at')
            ->values();

        return response()->json([
            'ok'          => true,
            'conversacion' => [
                'id'                 => $conversacion->id,
                'nombre'             => $conversacion->nombreMostrar(),
                'celular'            => $conversacion->wa_contact_id,
                'contrato_id'        => $conversacion->contrato_id,
                'contrato_url'       => $conversacion->cliente_url,
                'estado'             => $conversacion->estado,
                'asignado_a'         => $conversacion->asignado_a,
                'asignado_nombre'    => $conversacion->asignado?->nombre,
                'bot_activo'         => $conversacion->bot_activo,
                'atendida_por_ia'    => $this->esAtendidaPorIa($conversacion),
                'pendiente_atencion' => $conversacion->pendiente_atencion,
                'pendiente_motivo'   => $conversacion->pendiente_motivo,
                'ventana_activa'     => $conversacion->ventanaActiva(),
                'ventana_minutos'    => $conversacion->minutosVentanaRestante(),
            ] + $this->identidadContacto($conversacion, $alidoId),
            'mensajes' => $this->mapearMensajes($mensajes),
        ]);
    }

    /**
     * API: retorna los datos de UNA conversación en formato sidebar.
     * Usado por el frontend cuando llega un evento Reverb de una conversación
     * que todavía no está en la lista del sidebar (contacto nuevo).
     */
    public function apiConversacionSidebar(int $id)
    {
        $alidoId = session('aliado_id_activo');

        $conversacion = WhatsappConversacion::delAliado($alidoId)
            ->activas()
            ->with('asignado')
            ->select($this->columnasSidebar())
            ->find($id);

        if (!$conversacion) {
            return response()->json(['ok' => false, 'error' => 'Conversación no encontrada'], 404);
        }

        $this->verificarAccesoConversacion($conversacion);

        $this->tipos->clasificar(collect([$conversacion]), $alidoId);
        $this->esperando->marcar(collect([$conversacion]));

        return response()->json([
            'ok'           => true,
            'conversacion' => $this->mapearConversacionSidebar($conversacion),
        ]);
    }

    /**
     * Prospectos que quieren trabajar con el aliado (asesores y empresas): qué dijeron
     * ser, cuántas personas manejan, qué les sugirió la IA y cómo va cada conversación.
     * Lo marca la herramienta perfilar_aliado o el comando whatsapp:perfil-aliado.
     */
    public function prospectosAliados(Request $request)
    {
        $alidoId = (int) session('aliado_id_activo');
        $tipo = $request->get('tipo');
        $desde = $request->get('desde');

        $q = WhatsappConversacion::delAliado($alidoId)
            ->whereNotNull('perfil_aliado')
            ->with('asignado')
            ->orderByDesc('perfil_aliado_at');
        if ($tipo && array_key_exists($tipo, \App\Services\ProspectoAliadoService::TIPOS)) {
            $q->where('perfil_aliado', $tipo);
        }
        if ($desde) {
            $q->where('perfil_aliado_at', '>=', $desde);
        }
        $prospectos = $q->get();

        // Cuánto tardó la primera respuesta humana desde el primer mensaje del prospecto,
        // y cuándo fue el último mensaje de cada lado. Una sola consulta para todas.
        $ids = $prospectos->pluck('id')->all();
        $tiempos = $ids ? collect(DB::select("
            select conversacion_id,
                   min(case when direccion = 'entrante' then created_at end) primer_cliente,
                   min(case when direccion = 'saliente' and (es_bot = 0 or es_bot is null) and tipo <> 'nota' then created_at end) primer_humano,
                   max(case when direccion = 'entrante' then created_at end) ultimo_cliente,
                   max(case when direccion = 'saliente' then created_at end) ultimo_nuestro
            from whatsapp_mensajes where conversacion_id in (".implode(',', array_map('intval', $ids)).")
            group by conversacion_id"))->keyBy('conversacion_id') : collect();

        foreach ($prospectos as $p) {
            $t = $tiempos->get($p->id);
            $p->primer_humano_min = ($t && $t->primer_cliente && $t->primer_humano)
                ? max(0, (int) round((strtotime($t->primer_humano) - strtotime($t->primer_cliente)) / 60)) : null;
            $p->sin_respuesta_humana = ! ($t && $t->primer_humano);
            $p->esperando_a_nosotros = $t && $t->ultimo_cliente && (! $t->ultimo_nuestro || $t->ultimo_cliente > $t->ultimo_nuestro);
        }

        $resumen = [
            'total' => $prospectos->count(),
            'asesores' => $prospectos->where('perfil_aliado', 'asesor')->count(),
            'empresas' => $prospectos->where('perfil_aliado', 'empresa')->count(),
            'personas' => (int) $prospectos->sum('personas_declaradas'),
            'sin_humano' => $prospectos->where('sin_respuesta_humana', true)->count(),
        ];

        return view('admin.whatsapp.prospectos-aliados', compact('prospectos', 'resumen', 'tipo', 'desde'));
    }

    // ── Helpers privados ─────────────────────────────────────────────

    /**
     * Identificación del contacto para el encabezado del chat abierto: si es
     * cliente, excliente o nuevo, desde cuándo está retirado y de qué campaña o
     * pieza llegó. Cuesta una consulta, la misma que clasifica el sidebar.
     */
    private function identidadContacto(WhatsappConversacion $c, int $alidoId): array
    {
        $this->tipos->clasificar(collect([$c]), $alidoId);

        return [
            'tipo_contacto'   => $c->tipo_contacto,
            'tipo_label'      => WhatsappTipoContacto::ETIQUETAS[$c->tipo_contacto] ?? null,
            'desde_marketing' => $c->desde_marketing,
            'retirado_desde'  => $c->fecha_retiro_contrato
                ? \Illuminate\Support\Carbon::parse($c->fecha_retiro_contrato)->format('d/m/Y')
                : null,
            // Solo si vino de marketing: `origen_campana` también se llena con la
            // plantilla de un envío de cobro, y ahí "llegó por" sería mentira.
            'origen'          => $c->desde_marketing
                ? \Illuminate\Support\Str::limit($c->origen_campana ?: $c->publicacionOrigen?->titulo, 45) ?: null
                : null,
        ];
    }

    /**
     * Filtro de tipo de contacto pedido en la URL, o null si no viene o no es uno
     * de los tres válidos. Se normaliza aquí para que las vistas puedan tratar
     * "hay filtro" como un simple truthy.
     */
    private function tipoContactoPedido(Request $request): ?string
    {
        $tipo = $request->get('tipo');

        return isset(WhatsappTipoContacto::ETIQUETAS[$tipo]) ? $tipo : null;
    }

    /**
     * Carga las conversaciones y el total de no leídos para el sidebar.
     * Método compartido entre index() y show() para eliminar duplicación.
     */
    private function cargarDatosSidebar(int $alidoId, ?string $tab, ?string $buscar, ?string $tipo = null): array
    {
        $userId  = Auth::id();
        $user    = Auth::user();
        $esAdmin = $user->es_brynex || $user->hasRole(['admin', 'superadmin']);

        // Se trae el inbox completo y las pestañas se aplican en memoria: así los
        // contadores (no leídos, IA, esperando) cuentan siempre todo el inbox, estén en la
        // pestaña que estén, y «esperando» —que depende del último mensaje— no se puede
        // filtrar en SQL sin otra consulta. Son ~450 filas por aliado como mucho.
        $query = WhatsappConversacion::delAliado($alidoId)
            ->activas()
            ->with('asignado')
            ->select($this->columnasSidebar())
            ->orderByDesc('ultimo_mensaje_at');

        $this->excluirDeudoresDelDueno($query, $userId);

        if (!$esAdmin) {
            $query->where(function ($q) use ($userId) {
                $q->where('asignado_a', $userId)->orWhereNull('asignado_a');
            });
        }

        $conversaciones = $query->get();

        // Badge total no leídos — se calcula antes de la búsqueda, que no debe restarle.
        $totalNoLeidos = $conversaciones->sum('total_mensajes_no_leidos');

        // "Atendida por IA" de verdad = permiso en la conversación (bot_activo) Y el
        // aliado tiene la IA de WhatsApp encendida. Sin esto, una conversación con
        // bot_activo=true por una prueba/reactivación se etiqueta como IA aunque el
        // interruptor general esté apagado y el bot nunca vaya a responder.
        // `foreach`, no `each()`: el closure devolvía el valor asignado, y en cuanto
        // una conversación daba `false` (la IA no la atiende), Collection::each
        // interpretaba ese false como "corta la iteración" y dejaba al resto en null.
        // Cada una de esas caía después en esAtendidaPorIa(), una consulta por fila:
        // 78 viajes a ia_configuracion_aliado, ~15 s de la carga del chat.
        $iaActivaAliado = IaConfiguracionAliado::where('aliado_id', $alidoId)->value('activo_whatsapp') ?? false;

        foreach ($conversaciones as $c) {
            $c->atendida_por_ia = $iaActivaAliado && (bool) $c->bot_activo;
        }

        // Cliente / excliente / nuevo, y quién está esperando respuesta — una sola
        // consulta cada uno para todo el sidebar.
        $this->tipos->clasificar($conversaciones, $alidoId);
        $this->esperando->marcar($conversaciones);

        $totalIa        = $conversaciones->where('atendida_por_ia', true)->count();
        $totalEsperando = $conversaciones->where('esperando', true)->count();

        // Asignadas con el cliente esperando hace horas: las que el botón «Devolver al
        // inbox general» soltaría. Solo se le ofrece a quien puede asignar.
        $limiteSinAtender = now()->subHours(self::horasSinAtender());
        $totalSinAtender = $user->can('whatsapp.asignar')
            ? $conversaciones->filter(fn ($c) => $c->estado === 'asignada' && $c->asignado_a
                && $c->esperando && $c->esperando_desde?->lte($limiteSinAtender)
                && (! $c->asignado_at || $c->asignado_at->lte($limiteSinAtender))
                // Las de los deudores del dueño no se sueltan: las atiende él directo.
                && ! TelefonosDeudores::esDeudor($c->wa_contact_id))->count()
            : 0;

        if ($buscar) {
            $sinTildes = fn (?string $s) => \Illuminate\Support\Str::ascii(mb_strtolower((string) $s));
            $aguja = $sinTildes($buscar);
            $conversaciones = $conversaciones->filter(fn ($c) => str_contains($sinTildes($c->nombre_contacto), $aguja)
                || str_contains((string) $c->wa_contact_id, $buscar))->values();
        }

        $conversaciones = match ($tab) {
            'mias'      => $conversaciones->where('asignado_a', $userId)->values(),
            'ia'        => $conversaciones->where('atendida_por_ia', true)->values(),
            // Primero a quienes todavía se les puede escribir libre, con la ventana más
            // corta arriba; después los de ventana vencida, el más reciente primero: es lo
            // que todavía se puede recuperar.
            'esperando' => $conversaciones->where('esperando', true)
                ->sortBy(fn ($c) => $c->ventanaActiva()
                    ? [0, $c->minutosVentanaRestante()]
                    : [1, -($c->esperando_desde?->timestamp ?? 0)])
                ->values(),
            default     => $conversaciones,
        };

        // Conteo por tipo ANTES de filtrar por tipo, para que los chips del filtro sigan
        // mostrando cuántos hay en cada grupo aunque haya uno seleccionado.
        $conteoTipos = $conversaciones->countBy('tipo_contacto')->all();

        if ($tipo) {
            $conversaciones = $conversaciones->where('tipo_contacto', $tipo)->values();
        }

        return compact('conversaciones', 'totalNoLeidos', 'totalIa', 'totalEsperando', 'totalSinAtender', 'conteoTipos');
    }

    /**
     * Columnas que necesita una fila del sidebar. Las de origen (`empresa_id`,
     * `origen_campana_categoria`, `origen_publicacion_id`) las lee
     * WhatsappTipoContacto para clasificar el contacto.
     */
    private function columnasSidebar(): array
    {
        return [
            'id', 'aliado_id', 'wa_contact_id', 'nombre_contacto',
            'total_mensajes_no_leidos', 'ultimo_mensaje_at',
            'asignado_a', 'estado', 'contrato_id', 'empresa_id',
            'origen_campana', 'origen_campana_categoria', 'origen_publicacion_id',
            'bot_activo', 'pendiente_atencion', 'pendiente_motivo',
            // Sin esta, ventanaActiva() da siempre false y el chip de espera marca
            // «vencida» a gente que escribió hace una hora.
            'ventana_activa_hasta', 'asignado_at',
            'perfil_aliado', 'personas_declaradas', 'perfil_sugerencia',
        ];
    }

    /**
     * Mapea una colección de conversaciones al formato del sidebar Alpine.js.
     */
    private function mapearConversacionesSidebar($conversaciones): array
    {
        return $conversaciones->map(fn($c) => $this->mapearConversacionSidebar($c))->toArray();
    }

    /**
     * Mapea UNA conversación al formato del sidebar Alpine.js.
     */
    /** ¿La IA realmente atenderá esta conversación? (permiso local Y aliado con WhatsApp IA encendido). */
    private function esAtendidaPorIa(WhatsappConversacion $c): bool
    {
        if (!$c->bot_activo) return false;
        return (bool) IaConfiguracionAliado::where('aliado_id', $c->aliado_id)->value('activo_whatsapp');
    }

    private function mapearConversacionSidebar(WhatsappConversacion $c): array
    {
        return [
            'id'                       => $c->id,
            'nombre'                   => $c->nombreMostrar(),
            'celular'                  => $c->wa_contact_id,
            'total_mensajes_no_leidos' => (int) $c->total_mensajes_no_leidos,
            'ultimo_mensaje_at'        => $c->ultimo_mensaje_at?->toIso8601String(),
            'hora_display'             => $c->ultimo_mensaje_at
                ? ($c->ultimo_mensaje_at->isToday()
                    ? $c->ultimo_mensaje_at->format('H:i')
                    : $c->ultimo_mensaje_at->format('d/m'))
                : '',
            'preview'                  => $c->previewUltimoMensaje(),
            'asignado_a'               => $c->asignado_a,
            'asignado_nombre'          => $c->asignado?->nombre,
            'bot_activo'               => (bool) $c->bot_activo,
            'atendida_por_ia'          => (bool) ($c->atendida_por_ia ?? $this->esAtendidaPorIa($c)),
            'pendiente_atencion'       => (bool) $c->pendiente_atencion,
            'pendiente_motivo'         => $c->pendiente_motivo,
            'tipo_contacto'            => $c->tipo_contacto,
            'tipo_label'               => WhatsappTipoContacto::ETIQUETAS[$c->tipo_contacto] ?? null,
            'desde_marketing'          => $c->desde_marketing,
            'perfil_aliado'            => $c->perfil_aliado,
            'perfil_label'             => $c->perfil_aliado
                ? '🤝 '.(\App\Services\ProspectoAliadoService::TIPOS[$c->perfil_aliado] ?? $c->perfil_aliado)
                    .($c->personas_declaradas !== null ? ' · '.$c->personas_declaradas : '')
                : null,
            'perfil_sugerencia'        => $c->perfil_sugerencia,
            'url_show'                 => route('admin.whatsapp.chat.show', $c->id),
        ] + $this->esperaSidebar($c);
    }

    /**
     * Lo que la fila del sidebar muestra de la espera: si hay alguien esperando, desde
     * hace cuánto, y cuánto queda de la ventana de 24 h para contestarle sin plantilla.
     * El chip lo pinta el JS con `ventana_minutos`, que así también sirve para que la
     * cuenta siga corriendo sin recargar.
     */
    private function esperaSidebar(WhatsappConversacion $c): array
    {
        $desde = $c->esperando_desde;

        return [
            'esperando'       => (bool) $c->esperando,
            'esperando_desde' => $desde?->toIso8601String(),
            'esperando_hace'  => $desde ? WhatsappEsperandoRespuesta::hace($desde) : null,
            'ventana_activa'  => $c->ventanaActiva(),
            'ventana_minutos' => $c->minutosVentanaRestante(),
        ];
    }

    /**
     * Mapea una colección de mensajes al formato Alpine.js.
     */
    private function mapearMensajes($mensajes): array
    {
        return $mensajes->map(function ($m) {
            return [
                'id'              => $m->id,
                'tipo'            => $m->tipo,
                'contenido'       => $m->contenido,
                'es_entrante'     => $m->esEntrante(),
                'usuario_nombre'  => $m->usuario?->nombre,
                'plantilla_nombre'=> $m->plantilla?->nombre_display,
                'tiene_media'     => $m->tieneMedia(),
                'media_url'       => $m->urlMedia(),
                'media_nombre'    => $m->media_nombre,
                'media_mime_type' => $m->media_mime_type,
                'hora'            => $m->created_at->format('H:i'),
                'icono_estado'    => $m->iconoEstado(),
                'error'           => $m->motivoFallo(),
            ];
        })->toArray();
    }

    private function enviarTexto(WhatsappConversacion $conv, array $data, WhatsappConfig $config): array
    {
        $nombreAgente = Auth::user()->nombre;
        $textoFirmado = "*Atendido por {$nombreAgente}:*\n\n" . $data['contenido'];

        $resultado = $this->apiService->enviarTexto($conv->wa_contact_id, $textoFirmado, $config);

        if (!$resultado['ok']) return $resultado;

        $mensaje = WhatsappMensaje::create([
            'conversacion_id' => $conv->id,
            'aliado_id'       => $conv->aliado_id,
            'wa_message_id'   => $resultado['wa_message_id'],
            'direccion'       => 'saliente',
            'tipo'            => 'text',
            'contenido'       => $textoFirmado,
            'estado'          => 'enviado',
            'usuario_id'      => Auth::id(),
        ]);

        return ['ok' => true, 'mensaje' => $mensaje];
    }

    private function enviarTemplate(WhatsappConversacion $conv, array $data, WhatsappConfig $config, int $alidoId): array
    {
        $effectiveAliadoId = $alidoId;
        if ($config->usa_cuenta_brynex) {
            $aliadoBrynex = \App\Models\Aliado::where('nombre', 'BryNex')->first();
            $effectiveAliadoId = $aliadoBrynex ? $aliadoBrynex->id : 1;
        }

        $plantilla = \App\Models\WhatsappPlantilla::delAliado($effectiveAliadoId)->findOrFail($data['plantilla_id']);
        $params    = $data['parametros'] ?? [];

        $resultado = $this->apiService->enviarTemplate($conv->wa_contact_id, $plantilla, $params, $config);

        if (!$resultado['ok']) return $resultado;

        $mensaje = WhatsappMensaje::create([
            'conversacion_id'      => $conv->id,
            'aliado_id'            => $conv->aliado_id,
            'wa_message_id'        => $resultado['wa_message_id'],
            'direccion'            => 'saliente',
            'tipo'                 => 'template',
            // El texto ya armado, para que en el chat se lea qué se le dijo y no solo
            // el nombre de la plantilla.
            'contenido'            => $plantilla->cuerpoRenderizado($params),
            'plantilla_id'         => $plantilla->id,
            'plantilla_parametros' => $params,
            'estado'               => 'enviado',
            'usuario_id'           => Auth::id(),
        ]);

        return ['ok' => true, 'mensaje' => $mensaje];
    }

    private function enviarMedia(WhatsappConversacion $conv, Request $request, string $tipo, WhatsappConfig $config): array
    {
        $archivo  = $request->file('archivo');
        $mimeType = $archivo->getMimeType();
        $nombre   = $archivo->getClientOriginalName();

        $directorio = 'whatsapp/' . now()->format('Y/m');
        $path = $archivo->store($directorio, 'local');

        // El texto que acompaña al archivo. Se armaba y se guardaba en el chat, pero nunca
        // se le pasaba a Meta: aquí se veía el texto bajo la foto y al cliente le llegaba
        // la foto pelada. Los audios no admiten texto (Meta rechaza el mensaje entero).
        $nombreAgente    = Auth::user()->nombre;
        $captionOriginal = trim((string) $request->input('caption'));
        $captionFirmado  = in_array($tipo, ['image', 'document', 'video'], true)
            ? ($captionOriginal !== ''
                ? "*Atendido por {$nombreAgente}:*\n\n" . $captionOriginal
                : "*Atendido por {$nombreAgente}*")
            : null;

        $resultado = $this->apiService->enviarMedia(
            $conv->wa_contact_id,
            $tipo,
            $path,
            $mimeType,
            $nombre,
            $config,
            $captionFirmado
        );

        if (!$resultado['ok']) return $resultado;

        $mensaje = WhatsappMensaje::create([
            'conversacion_id' => $conv->id,
            'aliado_id'       => $conv->aliado_id,
            'wa_message_id'   => $resultado['wa_message_id'],
            'direccion'       => 'saliente',
            'tipo'            => $tipo,
            'media_url'       => $path,
            'media_mime_type' => $mimeType,
            'media_nombre'    => $nombre,
            'contenido'       => $captionFirmado,
            'estado'          => 'enviado',
            'usuario_id'      => Auth::id(),
        ]);

        return ['ok' => true, 'mensaje' => $mensaje];
    }

    private function findConversacionProtected(int $alidoId, int $id): WhatsappConversacion
    {
        $conversacion = WhatsappConversacion::delAliado($alidoId)->findOrFail($id);
        $this->verificarAccesoConversacion($conversacion);
        return $conversacion;
    }

    private function verificarAccesoConversacion(WhatsappConversacion $conversacion): void
    {
        $duenoId = TelefonosDeudores::duenoId();

        if ($duenoId
            && Auth::id() !== $duenoId
            && TelefonosDeudores::esDeudor($conversacion->wa_contact_id)) {
            abort(403, 'No autorizado para acceder a esta conversación.');
        }
    }

    /**
     * Privacidad: las conversaciones con deudores del dueño del módulo de
     * finanzas no se le muestran al resto de usuarios.
     *
     * Antes esto eran N condiciones `not like '%<telefono>'`. El comodín al
     * inicio impide usar índice y obliga a comparar patrón por patrón contra
     * cada fila: medido el 21-ago-2026 sobre 908 conversaciones y 15 teléfonos,
     * costaba **13,8 ms** por consulta, casi una cuarta parte del tiempo total
     * de la petición del badge — que se sondea cada 30 s por pestaña abierta.
     *
     * Recortar a los últimos 10 dígitos y comparar por pertenencia baja lo
     * mismo a **0,88 ms**, 15 veces menos, sin cambiar el esquema ni lo que
     * filtra. Si algún día crece mucho, el siguiente paso es una columna
     * calculada PERSISTED con índice.
     *
     * Nota: una conversación con `wa_contact_id` nulo queda excluida, porque
     * `NULL NOT IN (...)` es UNKNOWN. Es el mismo comportamiento que tenía la
     * versión con `not like`, así que no cambia nada.
     */
    private function excluirDeudoresDelDueno($query, ?int $userId): void
    {
        $duenoId = TelefonosDeudores::duenoId();

        if (!$duenoId || $userId === $duenoId) {
            return;
        }

        $telefonos = TelefonosDeudores::ultimos10();

        if (empty($telefonos)) {
            return;
        }

        $query->whereNotIn(DB::raw('RIGHT(wa_contact_id, 10)'), $telefonos);
    }
}
