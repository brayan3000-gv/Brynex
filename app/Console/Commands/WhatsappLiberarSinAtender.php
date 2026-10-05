<?php

namespace App\Console\Commands;

use App\Events\WhatsappConversacionActualizada;
use App\Models\Aliado;
use App\Models\WhatsappConfig;
use App\Services\WhatsappEsperandoRespuesta;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Devuelve al inbox general las conversaciones asignadas que el asesor no contestó.
 *
 * Cuando una persona escribe en un chat, la conversación queda asignada a ella y los
 * demás asesores dejan de verla. Si el cliente vuelve a escribir y ese asesor no está
 * (salió, está de vacaciones, tiene otra cosa encima), nadie más se entera: en Brygar
 * había 11 casos así en una semana, 7 con más de un día (oct-2026).
 *
 * A las HORAS sin respuesta —contadas desde el mensaje del cliente Y desde que se le
 * asignó— la conversación se libera: queda sin asignar, marcada como pendiente con el
 * nombre de quien la tenía, y entra a la pestaña «Esperando» de todos. No reactiva el
 * bot: así lo pidió el dueño, que la tome una persona.
 *
 * Ejecución manual: php artisan whatsapp:liberar-sin-atender --simular
 */
class WhatsappLiberarSinAtender extends Command
{
    protected $signature = 'whatsapp:liberar-sin-atender
        {--aliado= : Aliado a revisar (por defecto, todos los que tienen WhatsApp activo)}
        {--horas= : Horas sin respuesta del asesor asignado (por defecto, services.whatsapp.liberar_horas)}
        {--simular : Solo mostrar qué se liberaría, sin tocar nada}';

    protected $description = 'Devuelve al inbox general las conversaciones asignadas que llevan horas sin respuesta';

    public function handle(WhatsappEsperandoRespuesta $esperando): int
    {
        $horas = (int) ($this->option('horas') ?: config('services.whatsapp.liberar_horas', 4));
        if ($horas < 1) {
            $this->info('Liberación automática apagada (liberar_horas = 0).');

            return self::SUCCESS;
        }

        $aliados = $this->option('aliado')
            ? [(int) $this->option('aliado')]
            : WhatsappConfig::where('activo', true)->pluck('aliado_id')->map(fn ($id) => (int) $id)->all();

        $total = 0;
        foreach ($aliados as $aliadoId) {
            $lista = $esperando->paraLiberar($aliadoId, $horas);
            if ($lista->isEmpty()) {
                continue;
            }

            $this->line("\n<info>".(Aliado::find($aliadoId)?->nombre ?: "aliado {$aliadoId}").'</info>');

            foreach ($lista as $cv) {
                $asesor = $cv->asignado?->nombre ?: 'El asesor asignado';
                $hace = WhatsappEsperandoRespuesta::hace($cv->esperando_desde);
                $this->line("  {$cv->nombreMostrar()} ({$cv->wa_contact_id}) · {$asesor} · esperando hace {$hace} · «".mb_substr((string) $cv->esperando_dijo, 0, 50).'»');

                if ($this->option('simular')) {
                    continue;
                }

                $cv->liberarPorInactividad("{$asesor} no respondió en {$horas} h: volvió al inbox general.");
                $total++;

                try {
                    broadcast(new WhatsappConversacionActualizada($cv));
                } catch (\Throwable $e) {
                    // Sin Reverb el cambio igual queda hecho; el sidebar lo verá al recargar.
                    Log::warning('WhatsApp liberar: no se pudo emitir el evento', ['error' => $e->getMessage()]);
                }
            }
        }

        $this->info($this->option('simular') ? "\nSimulación: no se tocó nada." : "\nLiberadas: {$total}.");

        return self::SUCCESS;
    }
}
