<?php

namespace App\Console\Commands;

use App\Models\IaConfiguracionAliado;
use App\Models\WhatsappConversacion;
use App\Services\AlertaOperativaService;
use App\Services\AtencionVentas;
use App\Services\WhatsappEsperandoRespuesta;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Segundo aviso de los casos que la IA de WhatsApp pasó a una persona y llevan más de
 * atencion.segundo_aviso_horas (2 h) sin que nadie conteste. Le llega a quien tiene asignado el
 * caso y, en copia, a atencion.copia. Una sola vez por caso: si el cliente vuelve a escribir y
 * nadie contesta, cuenta como caso nuevo.
 *
 * Es distinto del aviso de las 8 a. m. (whatsapp:sin-respuesta), que lista a TODA la gente que
 * espera, una vez al día. Aquí solo van los traspasos de la IA: alguien que ya pidió una persona o
 * ya dio su cédula o su comprobante. Los comprobantes de clientes actuales no entran (son la tarea
 * diaria de facturar y ya salen a las 8). Nació el 20-nov-2026: Tatiana y José Elimer, del anuncio
 * del plan sin pensión, esperaron 6 y 7 días con la cédula entregada, y tres asesores que aceptaron
 * la reunión llevaban días sin respuesta.
 *
 * Ejecución manual: php artisan whatsapp:avisar-traspasos --no-enviar
 */
class WhatsappAvisarTraspasos extends Command
{
    protected $signature = 'whatsapp:avisar-traspasos
        {--horas= : Horas sin respuesta (por defecto atencion.segundo_aviso_horas)}
        {--no-enviar : Solo mostrar en pantalla, sin enviar ni marcar como avisado}';

    protected $description = 'Segundo aviso, una vez por caso, de lo que la IA pasó a una persona y nadie ha contestado';

    private const MAX_CARACTERES = 560;

    public function __construct(private WhatsappEsperandoRespuesta $esperando)
    {
        parent::__construct();
    }

    public function handle(AlertaOperativaService $alertas): int
    {
        $horas = (int) ($this->option('horas') ?: config('atencion.segundo_aviso_horas', 2));
        $aliados = IaConfiguracionAliado::where('activo_whatsapp', true)->pluck('aliado_id')->map(fn ($id) => (int) $id);

        foreach ($aliados as $aliadoId) {
            $casos = $this->esperando->paraAviso($aliadoId, $horas, 14)
                ->filter(fn (WhatsappConversacion $c) => $this->esTraspaso($c))
                ->reject(fn (WhatsappConversacion $c) => Cache::has($this->clave($c)))
                ->values();

            if ($casos->isEmpty()) {
                $this->line("aliado {$aliadoId}: nada nuevo");
                continue;
            }

            // Un mensaje por persona: a quien tiene asignado cada caso, y la copia recibe todos.
            $porNumero = [];
            foreach ($casos as $c) {
                $linea = '• '.mb_substr($c->nombreMostrar() ?: (string) $c->wa_contact_id, 0, 28)
                    .' · '.WhatsappEsperandoRespuesta::hace($c->esperando_desde)
                    .' · '.mb_substr($this->motivoCorto((string) $c->pendiente_motivo), 0, 70);
                $numeros = array_filter(array_merge(
                    [$c->asignado_a ? AtencionVentas::numeroDe((int) $c->asignado_a) : null],
                    array_map(fn ($u) => AtencionVentas::numeroDe((int) $u), config("atencion.copia.{$aliadoId}", []))
                ));
                foreach (array_unique($numeros) as $n) {
                    $porNumero[$n][] = $linea;
                }
                $this->line("  {$c->id} {$linea}");
            }

            if ($this->option('no-enviar')) {
                continue;
            }

            foreach ($porNumero as $numero => $lineas) {
                $texto = "Llevan más de {$horas} h esperando a una persona después de que la IA los pasó:\n";
                foreach ($lineas as $i => $l) {
                    if (mb_strlen($texto.$l) > self::MAX_CARACTERES - 40) {
                        $texto .= '… y '.(count($lineas) - $i).' más en la bandeja (pestaña Esperando).';
                        break;
                    }
                    $texto .= $l."\n";
                }
                $ok = $alertas->enviarDesdeAliado($aliadoId, (string) $numero, 'Sin respuesta', trim($texto));
                $this->line($ok ? "  → enviado a {$numero}" : "  → no se pudo enviar a {$numero}");
            }

            foreach ($casos as $c) {
                Cache::put($this->clave($c), true, now()->addDays(15));
            }
        }

        return self::SUCCESS;
    }

    /**
     * Lo pasó la IA a una persona y está apagada: un traspaso. Los comprobantes de clientes
     * actuales (los marca WhatsappLeerImagenJob) no cuentan; los de una venta sí (llevan «🛒»).
     */
    private function esTraspaso(WhatsappConversacion $c): bool
    {
        $motivo = (string) $c->pendiente_motivo;

        return $c->pendiente_atencion && ! $c->bot_activo && $motivo !== ''
            && ! str_starts_with($motivo, 'Comprobante de pago')
            && ! str_starts_with($motivo, 'Envió ');
    }

    private function motivoCorto(string $motivo): string
    {
        return trim(preg_replace('/\s+/', ' ', preg_replace('/ → lo atiende [^.]+\.?| → [^.]+\./u', '', $motivo)));
    }

    /** Un aviso por caso: el mismo cliente esperando desde el mismo mensaje. */
    private function clave(WhatsappConversacion $c): string
    {
        return "traspaso_avisado:{$c->id}:".$c->esperando_desde->timestamp;
    }
}
