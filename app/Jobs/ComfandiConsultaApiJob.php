<?php

namespace App\Jobs;

use App\Services\Caja\ComfandiApi;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Consulta a Comfandi por API en segundo plano: el robot tarda 1–3 min y no cabe
 * en una sola petición HTTP (se colgaba). El resultado se guarda en caché bajo un
 * token que el modal de la ficha del contrato sondea.
 *
 * Ver ComfandiCajaController::comfandiApiLanzar / comfandiApiEstado.
 */
class ComfandiConsultaApiJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Una sola corrida: reintentar un robot de 3 min sería carísimo. */
    public int $tries = 1;

    public int $timeout = 360;

    public function __construct(
        public string $token,
        public string $tipo,          // 'datos' | 'certificado' | 'afiliar'
        public string $nit,
        public string $cedula,
        public string $tipoDoc = 'CO1C',
        public ?int $contratoId = null, // para 'afiliar'
        public ?int $usuarioId = null,  // quién lanzó (para la bitácora del radicado)
    ) {}

    public function handle(ComfandiApi $api): void
    {
        $clave = self::clave($this->token);

        // Cada paso del robot queda en la caché que sondea el modal.
        $avisar = fn (string $paso) => Cache::put($clave, ['estado' => 'corriendo', 'paso' => $paso], now()->addMinutes(15));
        $avisar('Preparando la consulta');
        $api->alAvanzar($avisar);

        if ($this->tipo === 'afiliar') {
            $avisar('Entrando al portal');
            $contrato = \App\Models\Contrato::findOrFail($this->contratoId);
            // El daemon va escribiendo el paso actual en este archivo; comfandiApiEstado
            // lo lee para mostrarlo en vivo (el job está bloqueado en la llamada al robot).
            $progFile = self::progresoFile($this->token);
            $r = $api->afiliarIndividual($contrato, true, $progFile);
            @unlink($progFile);

            if (($r['ok'] ?? false) && ! empty($r['numero'])) {
                app(\App\Services\Caja\ComfandiCajaService::class)
                    ->aplicar($contrato, ['numero' => $r['numero'], 'texto' => $r['texto'] ?? '', 'error' => null], $this->usuarioId);
                Cache::put($clave, ['estado' => 'listo', 'afiliado' => true, 'numero' => $r['numero'],
                    'mensaje' => 'Afiliación radicada en Comfandi con el N° '.$r['numero'].' (queda En proceso en Radicados).'], now()->addMinutes(15));
            } elseif ($r['yaAfiliado'] ?? false) {
                Cache::put($clave, ['estado' => 'listo', 'yaAfiliado' => true,
                    'mensaje' => 'El trabajador ya está afiliado en Comfandi'.(! empty($r['desde']) ? ' desde '.$r['desde'] : '').'.'], now()->addMinutes(15));
            } else {
                Cache::put($clave, ['estado' => 'error', 'error' => $r['error'] ?? 'No se pudo radicar la afiliación.'], now()->addMinutes(15));
            }

            return;
        }

        if ($this->tipo === 'certificado') {
            $r = $api->certificado($this->nit, $this->cedula, $this->tipoDoc, true);
            Cache::put($clave, ($r['ok'] ?? false)
                ? ['estado' => 'listo', 'base64' => $r['base64'], 'contentType' => $r['contentType'] ?? 'application/pdf']
                : ['estado' => 'error', 'error' => $r['error'] ?? 'No se pudo obtener el certificado.'],
                now()->addMinutes(15));

            return;
        }

        $r = $api->datos(
            $this->nit,
            $this->cedula,
            now()->subMonths(12)->startOfMonth()->format('Y-m-d'),
            now()->format('Y-m-d'),
        );

        Cache::put($clave, ($r['ok'] ?? false)
            ? [
                'estado' => 'listo',
                'titular' => $r['titular'] ?? null,
                'beneficiarios' => $r['titular']['PACS'] ?? [],
                'subsidios' => $r['movimientos'] ?? [],
            ]
            : ['estado' => 'error', 'error' => $r['error'] ?? 'No se pudo consultar Comfandi.'],
            now()->addMinutes(15));
    }

    public function failed(\Throwable $e): void
    {
        Cache::put(self::clave($this->token),
            ['estado' => 'error', 'error' => 'La consulta falló: '.$e->getMessage()],
            now()->addMinutes(15));
    }

    public static function clave(string $token): string
    {
        return 'comfandi-api:'.$token;
    }

    /** Archivo donde el daemon escribe el paso actual de la afiliación (lo lee el modal). */
    public static function progresoFile(string $token): string
    {
        return storage_path('app/comfandi-prog/'.$token.'.json');
    }
}
