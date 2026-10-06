<?php

namespace App\Services\Ia\Tools;

use App\Models\WhatsappConversacion;
use App\Services\ProspectoAliadoService;

/**
 * Solo en WhatsApp. Para quien quiere TRABAJAR con el aliado (asesor o empresa
 * aliada), no para quien quiere afiliarse: guarda qué es y cuántas personas
 * maneja, devuelve el camino y las cifras públicas, y avisa a la persona que lo
 * atiende según el tamaño. La IA pone eso en palabras; no inventa números.
 */
class PerfilarAliadoTool implements IaToolInterface
{
    public function nombre(): string
    {
        return 'perfilar_aliado';
    }

    public function descripcion(): string
    {
        return 'Para prospectos que quieren TRABAJAR CON NOSOTROS: asesores con cartera propia, empresas que quieren '
            .'su marca con nuestra plataforma, o negocios que quieren afiliar a sus propios empleados. Llámala en '
            .'cuanto sepas dos cosas: qué es (asesor, empresa o empleador) y cuántas personas maneja hoy. Guarda ese '
            .'perfil, te devuelve el camino que le conviene con los números públicos de brynex.co/aliados y el enlace '
            .'con su número ya puesto, y avisa a la persona del equipo que lo va a atender. Vuelve a llamarla si el '
            .'prospecto corrige la cantidad. NO la uses con quien quiere afiliarse él mismo: a ese se le cotiza.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'tipo' => [
                    'type' => 'string',
                    'enum' => ['asesor', 'empresa', 'empleador'],
                    'description' => 'asesor: trabaja por su cuenta y afilia a sus propios clientes. empresa: tiene empresa y quiere su propia marca con la plataforma. empleador: quiere afiliar a SUS trabajadores (es cliente).',
                ],
                'personas' => ['type' => 'integer', 'description' => 'Cuántas personas o afiliados maneja hoy, según él. Si dio un rango, el menor; si dijo "más de 30", 30.'],
                'detalle' => ['type' => 'string', 'description' => 'Una frase con lo que contó (a qué se dedica, ciudad, qué le interesa), para quien lo atienda.'],
            ],
            'required' => ['tipo', 'personas'],
        ];
    }

    public function ejecutar(array $input, array $contexto): array
    {
        $tipo = (string) ($input['tipo'] ?? 'asesor');
        $personas = max(0, (int) ($input['personas'] ?? 0));
        $conv = ! empty($contexto['wa_conversacion_id']) ? WhatsappConversacion::find($contexto['wa_conversacion_id']) : null;
        $servicio = app(ProspectoAliadoService::class);

        $r = $conv
            ? $servicio->aplicar($conv, $tipo, $personas, avisar: true, simular: (bool) ($contexto['modo_prueba'] ?? false))
            : $servicio->orientar(2, $tipo, $personas) + ['responsable' => null];

        if (! empty($input['detalle']) && $conv && empty($contexto['modo_prueba'])) {
            $conv->forceFill(['pendiente_motivo' => mb_substr('🤝 '.ucfirst($tipo).' · '.$personas.' personas · '.$input['detalle'], 0, 255)])->save();
        }

        $resp = $r['responsable'] ?? null;
        $quien = $resp
            ? ($resp['compartir'] ?? false
                ? "{$resp['nombre']} ya quedó avisado y le va a escribir por este mismo WhatsApp. Si el prospecto prefiere llamar, dale el número {$resp['numero']} como opción; no lo mandes a llamar."
                : "{$resp['nombre']} ya quedó avisada y le va a escribir por este mismo WhatsApp. No le des ningún teléfono: la conversación sigue aquí.")
            : '';

        return [
            'ok' => true,
            'camino' => $r['camino'],
            'resumen' => $r['resumen'],
            'enlace' => $r['url'],
            'instrucciones' => trim(($r['camino'] === 'cliente' ? '' : 'Responde con el camino y UNA cifra que le sirva, en lenguaje natural y breve, y pásale el enlace para que juegue con la calculadora. ')
                .$quien.' Usa solo estas cifras; lo que no esté aquí, lo responde la persona (hablar_con_asesor cuando quiera cerrar o negociar).'),
        ];
    }
}
