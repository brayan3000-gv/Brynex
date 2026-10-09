<?php

namespace App\Services\Ia\Tools;

use App\Models\WhatsappConversacion;
use App\Services\ProspectoAliadoService;

/**
 * Solo disponible en el canal WhatsApp. Transfiere la conversación a un humano:
 * silencia al asistente en esa conversación (bot_activo = false) hasta que un
 * agente la reactive manualmente desde el chat en vivo.
 */
class HablarConAsesorTool implements IaToolInterface
{
    public function nombre(): string
    {
        return 'hablar_con_asesor';
    }

    public function descripcion(): string
    {
        return 'Transfiere la conversación a un asesor humano y deja de responder automáticamente en ella. '
            . 'Úsala cuando el cliente lo pida explícitamente, o cuando el tema requiere atención humana '
            . '(quejas, negociaciones, algo que ninguna otra herramienta resolvió).';
    }

    public function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'motivo' => ['type' => 'string', 'description' => 'Resumen breve (una frase) de por qué se transfiere, para que el asesor humano tenga contexto al abrir la conversación.'],
                'reunion' => ['type' => 'boolean', 'description' => 'true si el prospecto aceptó una reunión virtual: pasa a quien organiza la agenda.'],
            ],
        ];
    }

    public function ejecutar(array $input, array $contexto): array
    {
        $responsable = null;
        if (!empty($contexto['wa_conversacion_id'])) {
            $motivo = trim((string) ($input['motivo'] ?? '')) ?: 'El cliente pidió hablar con un asesor.';
            $conv = WhatsappConversacion::find($contexto['wa_conversacion_id']);
            // Un prospecto que quiere trabajar con nosotros va a quien lo atiende según su tamaño,
            // no al inbox general; el resto sigue el camino de siempre.
            $responsable = $conv && empty($contexto['modo_prueba'])
                ? app(ProspectoAliadoService::class)->pasarAlResponsable($conv, $motivo, ! empty($input['reunion']))
                : null;
            if ($conv && ! $responsable && empty($contexto['modo_prueba'])) {
                $conv->escalarAHumano($motivo);
            }
        }
        if ($responsable) {
            $numero = ($responsable['compartir'] ?? false) && ! empty($responsable['numero'])
                ? " Si prefiere llamar, puede dar como opción el número {$responsable['numero']}."
                : ' No le des ningún teléfono.';

            return [
                'ok'      => true,
                'mensaje' => "{$responsable['nombre']} quedó avisado y va a continuar esta conversación por este mismo WhatsApp. "
                    . 'Dile al cliente SOLO eso, en una o dos frases.' . $numero
                    . ' Si quiere una reunión, dile que esa persona le escribe para ponerse de acuerdo en el día y la hora,'
                    . ' pero NO digas «tomo nota», «anotado» ni que algo quedó agendado: la agenda la organiza esa persona.',
            ];
        }

        // Lo que el modelo diga después sale de aquí más que de las reglas generales del prompt:
        // con solo la regla, en las pruebas seguía diciendo "ya dejé registrado que inicias en
        // octubre" o "te dejamos agendado". Lo único que pasó es que una persona fue avisada.
        return [
            'ok'      => true,
            'mensaje' => 'Una persona del equipo quedó avisada y continuará esta conversación. Dile al cliente '
                . 'SOLO eso, en una o dos frases: que le pasaste su caso a un asesor y que lo va a contactar. '
                . 'NO digas que algo quedó registrado, agendado, programado, reservado ni guardado, ni prometas '
                . 'un día u hora: nada de eso se hizo.',
        ];
    }
}
