<?php

namespace App\Console\Commands;

use App\Models\WhatsappConversacion;
use App\Services\ProspectoAliadoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reabre en lote las conversaciones de quienes escribieron para trabajar con el
 * aliado (asesores, empresas, contadores) y se quedaron sin respuesta concreta:
 * les manda la plantilla «ya tenemos respuesta a tu mensaje» con el botón
 * «Continuar» y deja la conversación lista para que la IA la retome sabiendo
 * dónde quedó. Sin --aplicar solo muestra la lista.
 *
 *   php artisan whatsapp:reabrir-prospectos --aliado=2
 *   php artisan whatsapp:reabrir-prospectos --aliado=2 --ids=1160,1090,1123 --aplicar
 */
class WhatsappReabrirProspectos extends Command
{
    protected $signature = 'whatsapp:reabrir-prospectos
        {--aliado=2 : Aliado}
        {--ids= : Conversaciones adicionales, separadas por coma (las que no llegaron por el anuncio ni están marcadas)}
        {--solo-ids : Reabrir únicamente las de --ids}
        {--desde=2026-09-01 : No incluir conversaciones más viejas que esta fecha}
        {--aplicar : Envía las invitaciones. Sin esto solo muestra qué haría}';

    protected $description = 'Reabre con la plantilla «Continuar» las conversaciones de prospectos aliados y las deja para que la IA retome';

    public function handle(ProspectoAliadoService $servicio): int
    {
        $aliadoId = (int) $this->option('aliado');
        $ids = array_filter(array_map('intval', explode(',', (string) $this->option('ids'))));

        $q = WhatsappConversacion::where('aliado_id', $aliadoId)->where('wa_contact_id', '<>', '0000000002');
        if ($this->option('solo-ids')) {
            $q->whereIn('id', $ids ?: [0]);
        } else {
            $q->where(fn ($w) => $w->where('created_at', '>=', $this->option('desde'))->orWhereIn('id', $ids ?: [0]));
        }
        $convs = $q->orderByDesc('id')->get();

        // Teléfonos que ya son asesores, clientes o empresas: a esos no se les reabre nada.
        $telefonos = $convs->pluck('wa_contact_id')->map(fn ($t) => substr(preg_replace('/\D/', '', $t), -10))->unique()->values();
        $convertidos = collect();
        foreach ($telefonos->chunk(50) as $bloque) {
            foreach (['asesores' => 'celular', 'clientes' => 'celular', 'empresas' => 'celular'] as $tabla => $col) {
                foreach (DB::table($tabla)->where('aliado_id', $aliadoId)->whereNotNull($col)->get([$col]) as $r) {
                    $t = substr(preg_replace('/\D/', '', (string) $r->$col), -10);
                    if ($t !== '' && $bloque->contains($t)) {
                        $convertidos[$t] = $tabla;
                    }
                }
            }
        }

        $filas = [];
        $enviadas = 0;
        foreach ($convs as $c) {
            $esProspecto = in_array($c->id, $ids, true) || $servicio->esProspectoAliado($c);
            if (! $esProspecto) {
                continue;
            }
            $tel = substr(preg_replace('/\D/', '', $c->wa_contact_id), -10);
            $ultimo = DB::table('whatsapp_mensajes')->where('conversacion_id', $c->id)->where('direccion', 'entrante')->orderByDesc('id')->value('contenido');
            if (isset($convertidos[$tel])) {
                $resultado = ['ok' => false, 'motivo' => 'ya es '.$convertidos[$tel]];
            } else {
                $resultado = $servicio->reabrir($c, simular: ! $this->option('aplicar'));
                if ($resultado['ok'] && $this->option('aplicar')) {
                    $enviadas++;
                    usleep(400000); // Meta no quiere ráfagas
                }
            }
            $filas[] = [
                $c->id,
                mb_substr($c->nombreMostrar(), 0, 22),
                $c->ultimo_mensaje_at?->format('d/m'),
                $c->perfil_aliado ? ($c->perfil_aliado.($c->personas_declaradas !== null ? ' '.$c->personas_declaradas : '')) : '-',
                mb_substr(preg_replace('/\s+/', ' ', (string) $ultimo), 0, 45),
                ($resultado['ok'] ? '✅ ' : '— ').$resultado['motivo'],
            ];
        }

        $this->table(['Conv', 'Contacto', 'Último', 'Perfil', 'Lo último que dijo', 'Resultado'], $filas);
        $this->line(count($filas).' prospectos revisados'.($this->option('aplicar') ? ", {$enviadas} invitaciones enviadas." : '.'));
        if (! $this->option('aplicar')) {
            $this->info('Modo de prueba: no se envió nada. Agregue --aplicar para enviar.');
        }

        return self::SUCCESS;
    }
}
