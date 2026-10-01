<?php

namespace App\Console\Commands;

use App\Models\CorreoAfiliacion;
use App\Models\CorreoRecibido;
use App\Services\Afiliaciones\DatosAfiliacion;
use App\Services\AlertaOperativaService;
use Illuminate\Console\Command;

/**
 * Resumen diario por WhatsApp de las afiliaciones por correo del aliado: lo
 * enviado hoy, lo que respondieron las entidades, lo que venció sin respuesta y
 * lo que quedó en la bandeja por revisar. Lunes a viernes a las 5:30 p. m.
 */
class CorreosResumenAfiliaciones extends Command
{
    protected $signature = 'correos:resumen-afiliaciones
                            {--aliado=2 : Aliado}
                            {--simular : Muestra el mensaje sin enviarlo}';

    protected $description = 'Envía por WhatsApp el resumen del día de las afiliaciones por correo';

    public function handle(AlertaOperativaService $alertas): int
    {
        $aliadoId = (int) $this->option('aliado');
        $hoy = today();

        // Lo del buzón entero: también lo de los aliados que usan el de Brygar (DatosAfiliacion).
        $cuenta = DatosAfiliacion::buzon($aliadoId);
        $enviado  = fn () => $cuenta ? CorreoAfiliacion::where('buzon', $cuenta) : CorreoAfiliacion::where('aliado_id', $aliadoId);
        $recibido = fn () => $cuenta ? CorreoRecibido::where('buzon', $cuenta) : CorreoRecibido::where('aliado_id', $aliadoId);

        $enviados   = $enviado()->where('estado', '<>', 'fallido')->whereDate('enviado_at', $hoy)->count();
        $radicados  = $recibido()->where('estado', 'aplicado')->whereDate('created_at', $hoy)->count();
        $respuestas = $recibido()->where('clasificacion', 'respuesta_asesor')->where('estado', 'por_revisar')->whereDate('created_at', $hoy)->count();
        $esperando  = $enviado()->where('estado', 'enviado')->count();
        $vencidos   = $enviado()->where('estado', 'enviado')->where('vence_at', '<', now())->count();
        $porRevisar = $recibido()->where('estado', 'por_revisar')->count();

        if (! ($enviados + $radicados + $respuestas + $esperando + $porRevisar)) {
            $this->info('Sin movimiento: no se envía resumen.');

            return self::SUCCESS;
        }

        $lineas = [
            "📧 Enviados hoy a asesores: {$enviados}",
            "✅ Radicados que llegaron hoy: {$radicados}",
            "⚠️ Respuestas con observaciones hoy: {$respuestas}",
            "⏳ Esperando respuesta: {$esperando}".($vencidos ? " ({$vencidos} vencidos)" : ''),
            "📬 Correos por revisar en BryNex: {$porRevisar}",
        ];
        $mensaje = 'Resumen de afiliaciones por correo del '.$hoy->format('d/m/Y').': '.implode(' · ', $lineas);

        if ($this->option('simular')) {
            $this->line($mensaje);

            return self::SUCCESS;
        }

        foreach (config("afiliaciones_correo.whatsapp_avisos.{$aliadoId}", []) as $numero) {
            $ok = $alertas->enviarA($numero, 'Afiliaciones EPS', $mensaje);
            $this->line(($ok ? '✓ ' : '✗ ').$numero);
        }

        return self::SUCCESS;
    }
}
