<?php

namespace App\Services\Ia;

use App\Models\IaConfiguracionAliado;
use App\Models\WhatsappConversacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Decide si a una conversación callada le corresponde un mensaje de seguimiento comercial.
 *
 * El seguimiento existe para no dejar enfriar una VENTA. No aplica cuando la conversación
 * terminó resuelta: un cliente activo que pidió su planilla, preguntó una duda y dio las
 * gracias no tiene ninguna afiliación pendiente, y escribirle "¿quieres avanzar con la
 * afiliación?" tres horas después queda fuera de lugar.
 *
 * Dos filtros en cascada, del más barato al más costoso:
 *   1. Señal dura: ¿la IA llegó a cotizar algo en este tramo de conversación? Sin cotización
 *      no hay venta que perseguir. Esto descarta la mayoría de casos sin gastar un token.
 *   2. Criterio de la IA: con las últimas vueltas del diálogo decide si quedó algo pendiente
 *      y, si es así, redacta el mensaje según lo que realmente se habló.
 */
class SeguimientoEvaluador
{
    /** Herramientas cuyo uso indica intención comercial (hay una cotización sobre la mesa). */
    private const TOOLS_VENTA = ['cotizar_plan', 'cotizar_plan_publico'];

    /**
     * Herramientas de gestión de cuenta: si la conversación giró solo alrededor de esto, es
     * un cliente resolviendo un trámite, no un prospecto.
     */
    private const TOOLS_SERVICIO = ['consultar_cliente', 'enviar_planilla'];

    /** Cuántas vueltas del diálogo se le muestran a la IA para decidir. */
    private const MENSAJES_CONTEXTO = 14;

    /**
     * @return array{seguir: bool, motivo: string, mensaje: ?string}
     */
    public static function evaluar(WhatsappConversacion $conversacion, IaConfiguracionAliado $iaConfig): array
    {
        $mensajes = self::historial($conversacion);

        if (empty($mensajes)) {
            return self::no('Sin historial de la IA para esta conversación.');
        }

        // ── Filtro 1: ¿hubo cotización en este tramo? ─────────────────────
        // Se mira solo desde el último mensaje del cliente hacia atrás dentro de la ventana
        // de contexto: una cotización de hace tres días, seguida de un "gracias" por un tema
        // distinto, no es una venta viva.
        $huboCotizacion = false;
        $soloServicio   = true;

        foreach ($mensajes as $m) {
            if (in_array($m->tool_name, self::TOOLS_VENTA, true)) {
                $huboCotizacion = true;
                $soloServicio = false;
            } elseif ($m->tool_name && !in_array($m->tool_name, self::TOOLS_SERVICIO, true)) {
                $soloServicio = false;
            }
        }

        if (!$huboCotizacion) {
            return self::no($soloServicio
                ? 'La conversación fue de gestión de cuenta (planilla/consulta), no de afiliación.'
                : 'No hubo ninguna cotización: no hay venta pendiente que perseguir.');
        }

        // ── Filtro 2: criterio de la IA sobre el cierre del diálogo ───────
        return self::preguntarALaIa($conversacion, $iaConfig, $mensajes);
    }

    /**
     * Mensajes de la conversación de la IA correspondientes a este número, del más antiguo al
     * más reciente. Solo se toman los posteriores al último mensaje del cliente que abrió el
     * tramo actual, para no arrastrar intenciones de días atrás ya resueltas.
     */
    private static function historial(WhatsappConversacion $conversacion): array
    {
        $telefono = preg_replace('/\D/', '', (string) $conversacion->wa_contact_id);
        if ($telefono === '') {
            return [];
        }

        $iaConversacionId = DB::table('ia_conversaciones')
            ->where('aliado_id', $conversacion->aliado_id)
            ->where(function ($q) use ($telefono) {
                $q->where('telefono', $telefono)
                  ->orWhere('telefono', 'like', '%' . substr($telefono, -10));
            })
            ->value('id');

        if (!$iaConversacionId) {
            return [];
        }

        $mensajes = DB::table('ia_mensajes')
            ->where('conversacion_id', $iaConversacionId)
            ->orderByDesc('created_at')
            ->limit(self::MENSAJES_CONTEXTO)
            ->get(['rol', 'contenido', 'tool_name', 'created_at']);

        return array_reverse($mensajes->all());
    }

    /**
     * @param array $mensajes
     * @return array{seguir: bool, motivo: string, mensaje: ?string}
     */
    private static function preguntarALaIa(WhatsappConversacion $conversacion, IaConfiguracionAliado $iaConfig, array $mensajes): array
    {
        $credenciales = $iaConfig->credencialesEfectivas();
        if (empty($credenciales['api_key'])) {
            // Sin IA de texto no se puede afinar: se prefiere NO escribir antes que
            // mandar un mensaje comercial que quizá no venga al caso.
            return self::no('No hay IA de texto configurada para evaluar el cierre.');
        }

        $transcripcion = collect($mensajes)
            ->filter(fn ($m) => in_array($m->rol, ['user', 'assistant'], true) && trim((string) $m->contenido) !== '')
            ->map(fn ($m) => ($m->rol === 'user' ? 'Cliente' : 'Asesora') . ': ' . trim(preg_replace('/\s+/', ' ', $m->contenido)))
            ->implode("\n");

        $nombre = $conversacion->nombreMostrar();

        // A quien quiere TRABAJAR con nosotros no se le hace seguimiento como a un cliente: el
        // 15-oct-2026 a un asesor con 35 clientes se le volvió a mandar, solo, el precio de $554.300
        // que lo había frenado.
        $esProspectoAliado = app(\App\Services\ProspectoAliadoService::class)->esProspectoAliado($conversacion);
        $reglaAliado = $esProspectoAliado
            ? "\nOJO: quien escribe NO es un cliente que quiere afiliarse, sino un asesor o una empresa que está viendo si trabaja con la agencia. Si corresponde seguimiento, retoma lo que le interesaba (lo que gana con sus clientes o el camino que se le explicó) y ofrécele resolverle una duda. NO repitas precios de planes ni cifras, y NO le pidas día ni hora.\n"
            : '';

        $prompt = <<<PROMPT
Eres quien supervisa a la asistente comercial de una agencia colombiana de afiliación a seguridad social. Han pasado unas horas desde el último mensaje y el cliente no ha vuelto a escribir. Decide si corresponde mandarle UN mensaje de seguimiento.

CONVERSACIÓN RECIENTE (lo más nuevo al final):
{$transcripcion}
{$reglaAliado}
Corresponde seguimiento SOLO si quedó una afiliación realmente en el aire: se le cotizó un plan y el cliente no cerró ni descartó, o dijo que lo iba a pensar y no volvió, o quedó de mandar datos y no los mandó.

NO corresponde seguimiento si:
- El cliente ya resolvió lo que necesitaba (le enviaron su planilla, le respondieron una duda) y se despidió o agradeció.
- El cliente dijo claramente que no le interesa o que ya se afilió en otro lado.
- La conversación quedó en manos de un asesor humano.
- El último intercambio fue de simple cortesía y no había nada pendiente.

Ante la duda, NO escribas: molestar a un cliente que ya quedó satisfecho cuesta más que dejar pasar un seguimiento.

Responde ÚNICAMENTE con un objeto JSON, sin bloque de código:
- "seguir": true o false
- "descartado": true SOLO si el cliente dijo claramente que no le interesa, que ya se afilió en otro lado o que no va a seguir. Si simplemente no ha respondido o quedó de pensarlo, es false.
- "motivo": en pocas palabras, por qué (para el registro interno, no lo lee el cliente)
- "mensaje": si "seguir" es true, el texto a enviarle a {$nombre}. Español colombiano, cercano y breve (máximo 2 líneas), que retome lo que se habló concretamente —no una frase genérica— y deje la puerta abierta sin presionar. Máximo 1 emoji. Si "seguir" es false, deja este campo vacío.
PROMPT;

        try {
            $provider = IaProviderFactory::make($credenciales['proveedor']);
            $resp = $provider->chat(
                $credenciales['api_key'],
                $credenciales['modelo'],
                'Respondes ÚNICAMENTE con JSON válido, sin texto adicional ni bloques de código markdown.',
                [['role' => 'user', 'content' => $prompt]],
                []
            );
        } catch (\Throwable $e) {
            Log::warning('Seguimiento IA: fallo al evaluar el cierre', [
                'conversacion_id' => $conversacion->id,
                'error' => $e->getMessage(),
            ]);
            return self::no('Error consultando a la IA: ' . $e->getMessage());
        }

        $texto = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($resp['content'] ?? '')));
        $datos = json_decode($texto, true);

        if (!is_array($datos) || !array_key_exists('seguir', $datos)) {
            return self::no('La IA no devolvió una decisión utilizable.');
        }

        $seguir  = (bool) $datos['seguir'];
        $motivo  = mb_substr((string) ($datos['motivo'] ?? ''), 0, 200);
        $mensaje = trim((string) ($datos['mensaje'] ?? ''));
        $descartado = (bool) ($datos['descartado'] ?? false);

        // Si el cliente cerró la puerta, el prospecto se marca para que ningún asesor
        // siga detrás de alguien que ya dijo que no.
        if ($descartado) {
            RegistroProspectoIa::marcarNoInteresado($conversacion, $motivo);
        }

        if (!$seguir) {
            return self::no($motivo ?: 'La IA determinó que no hay nada pendiente.');
        }

        if ($mensaje === '') {
            return self::no('La IA dijo seguir pero no redactó el mensaje.');
        }

        return ['seguir' => true, 'motivo' => $motivo ?: 'Afiliación pendiente.', 'mensaje' => mb_substr($mensaje, 0, 600)];
    }

    /** @return array{seguir: bool, motivo: string, mensaje: ?string} */
    private static function no(string $motivo): array
    {
        return ['seguir' => false, 'motivo' => $motivo, 'mensaje' => null];
    }
}
