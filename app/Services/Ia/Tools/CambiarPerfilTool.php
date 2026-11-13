<?php

namespace App\Services\Ia\Tools;

use App\Models\WhatsappConversacion;
use App\Services\Ia\AsistenteIaService;

/**
 * Solo en WhatsApp. Cambia el perfil con que la IA atiende la conversación (cliente, asesor o
 * aliado; ver AsistenteIaService::PERFIL_*). Desde el mensaje siguiente el prompt trae el
 * módulo de ese perfil; para el turno en curso, la respuesta le dice qué hacer ya.
 *
 * Lo normal es que el perfil lo fije perfilar_aliado cuando sabe cuántas personas maneja. Esta
 * herramienta es para lo que perfilar_aliado no cubre: alguien que llegó como cliente y dice que
 * quiere trabajar con nosotros sin dar todavía su cantidad, o alguien que llegó por el anuncio
 * de asesores y en realidad quiere afiliarse.
 */
class CambiarPerfilTool implements IaToolInterface
{
    private const INSTRUCCIONES = [
        AsistenteIaService::PERFIL_CLIENTE => 'Desde ahora lo atiendes como CLIENTE que quiere afiliarse. En este mismo turno responde lo '
            .'que preguntó y dale un valor; si pide pensión, caja o solo ARL, cotiza con cotizar_plan. '
            .'Nada de comisiones, alianzas ni Plan Asesor.',
        AsistenteIaService::PERFIL_ASESOR => 'Desde ahora lo atiendes como ASESOR que quiere trabajar con nosotros. En este mismo turno, '
            .'en UNA sola pregunta, averigua si trabaja por su cuenta o tiene empresa y cuántas personas maneja hoy; si ya lo dijo, '
            .'no lo repitas y llama perfilar_aliado de una. No le cotices planes por iniciativa propia.',
        AsistenteIaService::PERFIL_ALIADO => 'Desde ahora lo atiendes como ALIADO: una empresa con muchos afiliados a la que se le ofrecen '
            .'los planes de BryNex (Alianza Esencial, Específica o Integral). Si no sabes cuántas personas maneja, pregúntaselo en una '
            .'frase; si ya lo dijo, llama perfilar_aliado con tipo empresa y dale las cifras que te devuelva.',
    ];

    public function nombre(): string
    {
        return 'cambiar_perfil';
    }

    public function descripcion(): string
    {
        return 'Cambia a quién estás atendiendo en esta conversación: cliente (quiere comprar un plan para él, su familia o sus '
            .'trabajadores), asesor (quiere trabajar con nosotros trayendo clientes) o aliado (empresa o persona con muchos '
            .'afiliados que busca los planes de BryNex). Úsala SOLO cuando quede claro que es otro perfil del que tienes: por '
            .'ejemplo, llegó preguntando como cliente y dice que vende afiliaciones, o llegó por el anuncio de asesores y lo que '
            .'quiere es afiliarse. Si ya sabes qué es y cuántas personas maneja, usa perfilar_aliado, que además cambia el perfil.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'perfil' => [
                    'type' => 'string',
                    'enum' => AsistenteIaService::PERFILES,
                    'description' => 'El perfil con que debes atenderlo desde ahora.',
                ],
                'motivo' => ['type' => 'string', 'description' => 'Una frase con lo que dijo que lo muestra.'],
            ],
            'required' => ['perfil'],
        ];
    }

    public function ejecutar(array $input, array $contexto): array
    {
        $perfil = (string) ($input['perfil'] ?? '');
        if (! in_array($perfil, AsistenteIaService::PERFILES, true)) {
            return ['ok' => false, 'error' => 'Perfil inválido: usa cliente, asesor o aliado.'];
        }

        // En el simulador la conversación es la de prueba (wa_contact_id 00000…), así que se guarda igual.
        $conv = ! empty($contexto['wa_conversacion_id']) ? WhatsappConversacion::find($contexto['wa_conversacion_id']) : null;
        $conv?->forceFill(['perfil_ia' => $perfil, 'perfil_ia_at' => now()])->save();

        $instrucciones = self::INSTRUCCIONES[$perfil];
        // El bloque del plan EPS sin pensión solo viene en el prompt de clientes, que empieza en el
        // mensaje siguiente: sin esto, quien pasaba a cliente en este turno recibía el valor con
        // pensión ($545.100) en vez de la oferta principal (prueba del 13-nov-2026).
        $plan = $perfil === AsistenteIaService::PERFIL_CLIENTE && ! empty($contexto['aliado_id'])
            ? (float) (\App\Models\ConfiguracionAliado::paraAliado((int) $contexto['aliado_id'])?->plan_sin_pension_valor ?? 0)
            : 0;
        if ($plan > 0) {
            $valor = '$'.number_format($plan, 0, ',', '.');
            $instrucciones .= " Si busca EPS o salud y no pidió pensión, la oferta principal es el plan EPS sin pensión de {$valor}: "
                ."ese primer pago es la afiliación y desde ahí {$valor} al mes, todo incluido (EPS con citas y medicamentos, ARL y "
                .'beneficiarios: cónyuge e hijos), sin pensión. Dale ese precio, sin llamar cotizar_plan, y pídele la cédula y el nombre completo.';
        }

        return ['ok' => true, 'perfil' => $perfil, 'instrucciones' => $instrucciones];
    }
}
