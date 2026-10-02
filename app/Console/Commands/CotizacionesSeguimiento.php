<?php

namespace App\Console\Commands;

use App\Models\Aliado;
use App\Models\Asesor;
use App\Models\ConfiguracionAliado;
use App\Models\CotizacionGestion;
use App\Models\CotizacionProspecto;
use App\Services\AlertaOperativaService;
use App\Services\WhatsappApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Seguimiento diario de los prospectos cotizados, según lo que cada aliado
 * configure en Parámetros:
 *
 *  1. Los interesados / pendientes que llevan N días sin gestión pasan a «sin
 *     respuesta».
 *  2. Los abiertos que llevan M días sin gestión se cierran como «no
 *     interesado», con una nota en el historial para saber que fue automático.
 *  3. Se avisa por WhatsApp quién tiene llamada para hoy o vencida: al asesor
 *     asignado si tiene celular, si no al número configurado por el aliado.
 */
class CotizacionesSeguimiento extends Command
{
    protected $signature = 'cotizaciones:seguimiento
                            {--aliado= : Solo este aliado}
                            {--simular : Muestra lo que haría sin cambiar nada ni avisar}';

    protected $description = 'Prospectos: pasa a sin respuesta, cierra los abandonados y avisa las llamadas del día';

    /** Cuántos prospectos se listan en un aviso antes de resumir el resto. */
    private const MAX_EN_AVISO = 15;

    public function handle(AlertaOperativaService $alertas): int
    {
        $simular = (bool) $this->option('simular');

        $configs = ConfiguracionAliado::whereNull('plan_id')
            ->where('activo', true)
            ->when($this->option('aliado'), fn ($q, $id) => $q->where('aliado_id', (int) $id))
            ->where(function ($q) {
                $q->where('prospectos_recordatorio', true)
                    ->orWhereNotNull('prospectos_dias_sin_respuesta')
                    ->orWhereNotNull('prospectos_dias_cierre');
            })
            ->get();

        if ($configs->isEmpty()) {
            $this->info('Ningún aliado tiene seguimiento de prospectos configurado.');

            return self::SUCCESS;
        }

        foreach ($configs as $cfg) {
            $aliadoId = (int) $cfg->aliado_id;
            $this->info("Aliado {$aliadoId}");

            if ($cfg->prospectos_dias_sin_respuesta) {
                $this->pasarASinRespuesta($aliadoId, (int) $cfg->prospectos_dias_sin_respuesta, $simular);
            }
            if ($cfg->prospectos_dias_cierre) {
                $this->cerrarAbandonados($aliadoId, (int) $cfg->prospectos_dias_cierre, $simular);
            }
            if ($cfg->prospectos_recordatorio) {
                $this->avisarLlamadas($aliadoId, (string) $cfg->prospectos_recordatorio_celular, $alertas, $simular);
            }
        }

        return self::SUCCESS;
    }

    /** Fecha de la última gestión de cada prospecto (o su creación si no tiene). */
    private function sinGestionDesde(int $aliadoId, int $dias)
    {
        $limite = now()->subDays($dias);

        return CotizacionProspecto::where('aliado_id', $aliadoId)
            ->where(function ($q) use ($limite) {
                $q->whereRaw('(select max(created_at) from cotizacion_gestiones g where g.cotizacion_id = cotizaciones_prospectos.id) < ?', [$limite])
                    ->orWhere(function ($q2) use ($limite) {
                        $q2->whereNotExists(function ($sub) {
                            $sub->select(DB::raw(1))->from('cotizacion_gestiones as g')
                                ->whereColumn('g.cotizacion_id', 'cotizaciones_prospectos.id');
                        })->where('created_at', '<', $limite);
                    });
            });
    }

    private function pasarASinRespuesta(int $aliadoId, int $dias, bool $simular): void
    {
        $prospectos = $this->sinGestionDesde($aliadoId, $dias)
            ->whereIn('estado', ['interesado', 'pendiente_resp'])
            ->get();

        foreach ($prospectos as $p) {
            $this->line("  → sin respuesta: #{$p->id} {$p->nombre_mostrar} ({$dias} días sin gestión)");
            if ($simular) {
                continue;
            }
            $this->cambiar($p, 'sin_respuesta', "Pasó a «Sin respuesta» automáticamente: {$dias} días sin gestión.");
        }
        $this->info('  '.$prospectos->count().' prospecto(s) a sin respuesta.');
    }

    private function cerrarAbandonados(int $aliadoId, int $dias, bool $simular): void
    {
        $prospectos = $this->sinGestionDesde($aliadoId, $dias)
            ->whereNotIn('estado', CotizacionProspecto::ESTADOS_CERRADOS)
            ->get();

        foreach ($prospectos as $p) {
            $this->line("  → cerrado: #{$p->id} {$p->nombre_mostrar} ({$dias} días sin gestión)");
            if ($simular) {
                continue;
            }
            $this->cambiar($p, 'no_interesado', "Cerrado automáticamente como «No interesado»: {$dias} días sin gestión ni respuesta. Si vuelve a escribir, reábralo cambiando el estado.");
        }
        $this->info('  '.$prospectos->count().' prospecto(s) cerrado(s).');
    }

    private function cambiar(CotizacionProspecto $p, string $estado, string $nota): void
    {
        $p->update(['estado' => $estado, 'proxima_llamada' => $estado === 'no_interesado' ? null : $p->proxima_llamada]);
        CotizacionGestion::create([
            'cotizacion_id' => $p->id,
            'user_id' => null,
            'tipo_gestion' => 'Nota',
            'descripcion' => $nota,
            'resultado' => $estado,
            'proxima_llamada' => null,
        ]);
    }

    private function avisarLlamadas(int $aliadoId, string $celularAliado, AlertaOperativaService $alertas, bool $simular): void
    {
        $prospectos = CotizacionProspecto::with('asesor')
            ->where('aliado_id', $aliadoId)
            ->whereNotIn('estado', CotizacionProspecto::ESTADOS_CERRADOS)
            ->whereDate('proxima_llamada', '<=', today())
            ->orderBy('proxima_llamada')
            ->get();

        if ($prospectos->isEmpty()) {
            $this->info('  Sin llamadas pendientes hoy.');

            return;
        }

        $aliado = Aliado::find($aliadoId);
        $celularAliado = preg_replace('/\D/', '', $celularAliado ?: (string) ($aliado->whatsapp ?: $aliado->celular ?: ''));

        // Cada asesor recibe solo los suyos; los que no tienen asesor (o cuyo
        // asesor no tiene celular) van al número del aliado.
        $porNumero = [];
        foreach ($prospectos as $p) {
            $celAsesor = preg_replace('/\D/', '', (string) ($p->asesor->celular ?? ''));
            $destino = WhatsappApiService::esCelularColombiano($celAsesor) ? $celAsesor : $celularAliado;
            if (! WhatsappApiService::esCelularColombiano($destino)) {
                $this->warn("  Sin número a quién avisar por #{$p->id} {$p->nombre_mostrar}.");

                continue;
            }
            $porNumero[$destino][] = $p;
        }

        foreach ($porNumero as $numero => $lista) {
            $mensaje = $this->mensaje($lista, $aliado?->nombre ?? 'BryNex');
            $this->line("  → aviso a {$numero}: ".count($lista).' prospecto(s)');
            $this->line('    '.str_replace("\n", "\n    ", $mensaje));
            if ($simular) {
                continue;
            }
            if (! $alertas->enviarA($numero, 'Cotizaciones', $mensaje)) {
                $this->error("  No se pudo avisar a {$numero}.");
            }
        }
    }

    private function mensaje(array $lista, string $aliado): string
    {
        $lineas = ['Prospectos por llamar hoy ('.$aliado.'):'];
        foreach (array_slice($lista, 0, self::MAX_EN_AVISO) as $p) {
            $fecha = $p->proxima_llamada->isToday() ? 'hoy' : 'vencida desde el '.$p->proxima_llamada->format('d/m');
            $valor = $p->valor_mensual ? ' · $'.number_format($p->valor_mensual, 0, ',', '.') : '';
            $lineas[] = '• '.$p->nombre_mostrar.' — '.($p->celular ?: 'sin celular').' ('.$fecha.')'.$valor;
        }
        if (count($lista) > self::MAX_EN_AVISO) {
            $lineas[] = '… y '.(count($lista) - self::MAX_EN_AVISO).' más.';
        }
        $lineas[] = '';
        $lineas[] = route('admin.cotizaciones.index', ['llamar' => 1]);

        return implode("\n", $lineas);
    }
}
