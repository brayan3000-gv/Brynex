<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\WhatsappConversacion;
use Illuminate\Support\Facades\Log;

/**
 * Los clientes nuevos que la IA deja listos para comprar van a una persona fija (config
 * atencion.ventas), asignados en la bandeja y con aviso por WhatsApp, igual que los prospectos
 * asesores van a su responsable (ProspectoAliadoService). Hasta el 20-nov-2026 caían en la
 * bandeja general sin dueño y nadie se enteraba.
 */
class AtencionVentas
{
    public function __construct(private AlertaOperativaService $alertas) {}

    /** @return array{nombre: string, user_id: int, numero: ?string}|null */
    public static function responsable(int $aliadoId): ?array
    {
        $r = config("atencion.ventas.{$aliadoId}");
        if (! $r || empty($r['user_id'])) {
            return null;
        }

        return $r + ['numero' => self::numeroDe((int) $r['user_id'])];
    }

    /** El WhatsApp interno de un usuario para los avisos, o null si no está configurado. */
    public static function numeroDe(int $userId): ?string
    {
        $n = config("atencion.numeros.{$userId}");

        return $n ? (string) $n : null;
    }

    /**
     * ¿Es alguien que quiere comprar? Ni asesor ni aliado, ni empresa que ya nos paga, ni cliente
     * con contrato vigente (esos son servicio y siguen con quien los atiende hoy).
     */
    public function esVenta(WhatsappConversacion $conv): bool
    {
        if (in_array($conv->perfil_aliado, ['asesor', 'empresa'], true)
            || in_array($conv->perfil_ia, ['asesor', 'aliado'], true)
            || $conv->empresa_id) {
            return false;
        }

        $tel = substr(preg_replace('/\D/', '', (string) $conv->wa_contact_id), -10);
        if (strlen($tel) < 10) {
            return false;
        }
        $cedulas = Cliente::where('aliado_id', $conv->aliado_id)->where('celular', 'like', '%'.$tel)->pluck('cedula');

        return $cedulas->isEmpty() || ! Contrato::where('aliado_id', $conv->aliado_id)
            ->whereIn('cedula', $cedulas->map(fn ($c) => (string) $c)->all())
            ->whereIn('estado', ['vigente', 'activo'])
            ->exists();
    }

    /**
     * La deja asignada al responsable de ventas, pendiente y sin IA, y le avisa. No toca una
     * conversación que ya tiene otro dueño (p. ej. un préstamo asignado a Brayan).
     *
     * @return array{nombre: string, user_id: int, numero: ?string}|null
     */
    public function pasar(WhatsappConversacion $conv, string $motivo): ?array
    {
        $r = self::responsable((int) $conv->aliado_id);
        if (! $r || ($conv->asignado_a && (int) $conv->asignado_a !== (int) $r['user_id'])) {
            return null;
        }

        $conv->forceFill([
            'bot_activo' => false,
            'pendiente_atencion' => true,
            'pendiente_motivo' => '🛒 Venta → '.$r['nombre'].'. '.$motivo,
            'asignado_a' => $r['user_id'],
            'asignado_at' => now(),
            'estado' => 'asignada',
        ])->save();

        if ($r['numero']) {
            $quien = ($conv->nombreMostrar() ?: 'Sin nombre').' ('.$conv->wa_contact_id.')';
            try {
                $this->alertas->enviarDesdeAliado((int) $conv->aliado_id, $r['numero'], 'Cliente para atender',
                    "{$quien}: {$motivo} Te toca escribirle: ".route('admin.whatsapp.chat.show', $conv->id));
            } catch (\Throwable $e) {
                Log::warning('AtencionVentas: no se pudo avisar', ['conversacion' => $conv->id, 'error' => $e->getMessage()]);
            }
        }

        return $r;
    }
}
