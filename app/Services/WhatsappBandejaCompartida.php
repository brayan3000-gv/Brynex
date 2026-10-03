<?php

namespace App\Services;

use App\Models\Aliado;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Empresa;
use App\Models\WhatsappConfig;
use App\Models\WhatsappConversacion;
use App\Models\WhatsappMensaje;
use Illuminate\Support\Facades\DB;

/**
 * Qué hacer con un mensaje que llega al número compartido de BryNex y no tiene una
 * conversación abierta que diga de qué aliado es.
 *
 * Antes se botaba sin dejar rastro (WhatsappWebhookService::procesarParaMultipleConfigs
 * tenía un comentario diciéndolo). Ahora se busca el celular en los clientes y empresas
 * de los aliados que comparten el número; si está en uno solo, la conversación nace ahí.
 * Si no está en ninguno, o está en varios, cae a la bandeja de BryNex marcada como
 * pendiente, y desde el chat se mueve al aliado correcto.
 */
class WhatsappBandejaCompartida
{
    /**
     * Aliado al que pertenece un celular según clientes y empresas, entre los aliados
     * dados. Devuelve el aliado si es uno solo; null y el motivo si no se puede decidir.
     *
     * @param  int[]  $aliadoIds
     * @return array{aliado_id: ?int, motivo: string}
     */
    public function resolverAliado(string $waFrom, array $aliadoIds): array
    {
        $tel10 = substr(preg_replace('/\D/', '', $waFrom), -10);
        // Un celular de relleno (3000000000, 3111111111) está en cualquier aliado y no dice nada.
        if (strlen($tel10) < 10 || empty($aliadoIds) || preg_match('/^(\d)\1{9}$/', $tel10)) {
            return ['aliado_id' => null, 'motivo' => 'Escribió al número compartido de BryNex y no se pudo identificar de qué aliado es.'];
        }

        // La gente del propio aliado (quien recibe el aviso de pendientes y toca «Mantener
        // activo») va al inbox de su aliado: ahí queda abierta la ventana de 24 h con la que
        // el siguiente aviso sale como texto libre. Si el número es de varios, sigue de largo.
        $porNumeroPropio = collect($aliadoIds)
            ->filter(fn ($id) => in_array($tel10, WhatsappEsperandoRespuesta::numerosDelAliado((int) $id), true))
            ->values();
        if ($porNumeroPropio->count() === 1) {
            return ['aliado_id' => (int) $porNumeroPropio->first(), 'motivo' => 'Es un número del propio aliado.'];
        }

        $clientes = Cliente::whereIn('aliado_id', $aliadoIds)
            ->where('celular', 'like', '%'.$tel10)
            ->distinct()
            ->pluck('aliado_id');

        $empresas = Empresa::whereIn('aliado_id', $aliadoIds)
            ->where(fn ($q) => $q->where('celular', 'like', '%'.$tel10)->orWhere('telefono', 'like', '%'.$tel10))
            ->distinct()
            ->pluck('aliado_id');

        $candidatos = $clientes->merge($empresas)->map(fn ($id) => (int) $id)->unique()->values();

        if ($candidatos->count() === 1) {
            return ['aliado_id' => $candidatos->first(), 'motivo' => 'Celular encontrado en el aliado.'];
        }

        if ($candidatos->isEmpty()) {
            return ['aliado_id' => null, 'motivo' => 'Escribió al número compartido de BryNex y el celular no está en ningún aliado. Hay que mover la conversación al aliado que corresponda.'];
        }

        $nombres = Aliado::whereIn('id', $candidatos)->pluck('nombre')->implode(', ');

        return ['aliado_id' => null, 'motivo' => "Escribió al número compartido y el celular está en varios aliados ({$nombres}). Hay que mover la conversación al que corresponda."];
    }

    /** Aliado dueño de la bandeja de «sin identificar»: BryNex. */
    public static function aliadoBandeja(): int
    {
        return (int) (Aliado::where('nombre', 'BryNex')->value('id') ?: 1);
    }

    /**
     * Mueve una conversación (y sus mensajes) a otro aliado que use el mismo número.
     *
     * Si el aliado destino ya tenía conversación con ese celular, los mensajes se pasan a
     * esa y la de origen se borra de verdad: el índice único (aliado_id, wa_contact_id)
     * cuenta también las borradas en blando, y un borrado en blando dejaría al número sin
     * poder volver a escribir a la bandeja.
     */
    public function mover(WhatsappConversacion $conversacion, int $aliadoDestinoId, ?int $usuarioId = null): WhatsappConversacion
    {
        $destinoConfig = WhatsappConfig::where('aliado_id', $aliadoDestinoId)->where('activo', true)->first();
        if (! $destinoConfig || ! $destinoConfig->usa_cuenta_brynex) {
            throw new \RuntimeException('El aliado destino no usa el número compartido de BryNex: la conversación no se puede seguir desde allá.');
        }

        return DB::transaction(function () use ($conversacion, $aliadoDestinoId, $usuarioId) {
            $existente = WhatsappConversacion::where('aliado_id', $aliadoDestinoId)
                ->where('wa_contact_id', $conversacion->wa_contact_id)
                ->first();

            $motivo = 'Movida desde la bandeja de '.($conversacion->aliado?->nombre ?: 'BryNex')
                .($usuarioId ? ' por '.(\App\Models\User::find($usuarioId)?->nombre ?: 'un usuario') : '').'.';

            if ($existente) {
                WhatsappMensaje::where('conversacion_id', $conversacion->id)->update([
                    'conversacion_id' => $existente->id,
                    'aliado_id' => $aliadoDestinoId,
                ]);

                $existente->update([
                    'estado' => $existente->estado === 'cerrada' ? 'abierta' : $existente->estado,
                    'ultimo_mensaje_at' => max($existente->ultimo_mensaje_at, $conversacion->ultimo_mensaje_at),
                    'ventana_activa_hasta' => max($existente->ventana_activa_hasta, $conversacion->ventana_activa_hasta),
                    'total_mensajes_no_leidos' => (int) $existente->total_mensajes_no_leidos + (int) $conversacion->total_mensajes_no_leidos,
                    'pendiente_atencion' => true,
                    'pendiente_motivo' => $motivo,
                ]);

                $conversacion->forceDelete();

                return $existente;
            }

            WhatsappMensaje::where('conversacion_id', $conversacion->id)->update(['aliado_id' => $aliadoDestinoId]);

            $vinculo = $this->vinculoPorTelefono($conversacion->wa_contact_id, $aliadoDestinoId);

            $conversacion->update([
                'aliado_id' => $aliadoDestinoId,
                'contrato_id' => $vinculo['contrato_id'],
                'empresa_id' => $vinculo['empresa_id'],
                'estado' => 'abierta',
                'asignado_a' => null,
                'pendiente_atencion' => true,
                'pendiente_motivo' => $motivo,
            ]);

            return $conversacion->fresh();
        });
    }

    /**
     * Contrato vigente y empresa del aliado que tengan este celular, para que la
     * conversación movida llegue ya enlazada como si hubiera nacido allá.
     *
     * @return array{contrato_id: ?int, empresa_id: ?int}
     */
    private function vinculoPorTelefono(string $waFrom, int $aliadoId): array
    {
        $tel10 = substr(preg_replace('/\D/', '', $waFrom), -10);

        $cliente = strlen($tel10) >= 10
            ? Cliente::where('aliado_id', $aliadoId)->where('celular', 'like', '%'.$tel10)->first()
            : null;

        $contrato = $cliente
            ? Contrato::where('aliado_id', $aliadoId)->where('cedula', $cliente->cedula)->whereIn('estado', ['vigente', 'activo'])->first()
            : null;

        $empresa = strlen($tel10) >= 10
            ? Empresa::where('aliado_id', $aliadoId)
                ->where(fn ($q) => $q->where('celular', 'like', '%'.$tel10)->orWhere('telefono', 'like', '%'.$tel10))
                ->first()
            : null;

        return ['contrato_id' => $contrato?->id, 'empresa_id' => $empresa?->id];
    }
}
