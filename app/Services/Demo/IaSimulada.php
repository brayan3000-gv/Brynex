<?php

namespace App\Services\Demo;

use App\Services\Ia\IaProviderInterface;

/**
 * El asistente de IA de la demo: contesta sin llamar a Claude, OpenAI ni
 * Gemini. Las respuestas son de muestra y lo dicen, pero siguen el tema de la
 * pregunta para que el prospecto vea cómo atiende el bot a sus clientes.
 */
class IaSimulada implements IaProviderInterface
{
    private const TEMAS = [
        '/precio|cu[aá]nto (vale|cuesta)|tarifa|valor|plan(es)?\b/iu' =>
            'Con gusto 😊. Nuestros planes de seguridad social incluyen salud, pensión, ARL y caja según lo que necesites. '.
            'Para darte el valor exacto necesito tu salario o ingreso mensual y la ciudad. ¿Me los compartes?',
        '/afili|ingres|inscrib|vincul/iu' =>
            'Claro, te afiliamos hoy mismo. Envíame una foto de tu cédula por ambos lados, tu EPS y fondo de pensión actuales '.
            '(si los tienes) y la fecha en que quieres empezar. Con eso radicamos ARL y EPS por API.',
        '/planilla|pila|pagar|pago|recibo/iu' =>
            'Tu planilla del mes ya está liquidada. Te puedo enviar el soporte en PDF o el enlace de pago PSE. ¿Cuál prefieres?',
        '/incapacidad|licencia|m[eé]dic/iu' =>
            'Siento que estés enfermo 🙏. Envíame la incapacidad (foto o PDF) y la radicamos ante tu EPS para el reconocimiento.',
        '/certificado|constancia/iu' =>
            'Te genero el certificado de afiliación al momento. ¿Lo necesitas de salud, pensión o ARL?',
    ];

    public function chat(string $apiKey, string $modelo, string|array $systemPrompt, array $messages, array $tools): array
    {
        $ultimo = '';
        foreach (array_reverse($messages) as $m) {
            if (($m['role'] ?? '') === 'user' && is_string($m['content'] ?? null)) {
                $ultimo = $m['content'];
                break;
            }
        }

        $respuesta = 'Hola 👋, soy el asistente virtual de la demo. En tu BryNex real respondo con IA (Claude, OpenAI o Gemini) '.
            'usando los datos de tu empresa: planes, afiliaciones, planillas e incapacidades. Pregúntame por precios, cómo afiliarte o tu planilla.';

        foreach (self::TEMAS as $patron => $texto) {
            if (preg_match($patron, $ultimo)) {
                $respuesta = $texto;
                break;
            }
        }

        return [
            'content' => $respuesta."\n\n_(Respuesta de muestra · demo de BryNex)_",
            'tool_calls' => [],
            'tokens_entrada' => 0,
            'tokens_salida' => 0,
            'tokens_cache_lectura' => 0,
            'tokens_cache_escritura' => 0,
        ];
    }
}
