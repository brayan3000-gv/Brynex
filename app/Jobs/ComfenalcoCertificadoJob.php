<?php

namespace App\Jobs;

use App\Services\Caja\ComfenalcoCertificadoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Genera el certificado de afiliación a la caja Comfenalco de un trabajador, en
 * segundo plano: el robot entra al portal por empresa, trae el PDF en base64 y lo
 * guarda como documento del cliente. Tarda 1–2 min (login + proxy colombiano),
 * así que no cabe en una petición HTTP; el modal sondea el resultado por token.
 *
 * Análogo a ComfenalcoSubsidiosJob. Ver ComfenalcoCajaController::certificadoLanzar.
 */
class ComfenalcoCertificadoJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Una sola corrida: reintentar un robot de 2 min con proxy sería carísimo. */
    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public string $token,
        public int $aliadoId,
        public string $nit,
        public string $tipoDoc,
        public string $cedula,
        public ?int $userId = null,
    ) {}

    public function handle(ComfenalcoCertificadoService $svc): void
    {
        $clave = self::clave($this->token);

        Cache::put($clave, [
            'estado' => 'corriendo',
            'paso' => 'Entrando al portal de Comfenalco y generando el certificado (puede tardar 1–2 min)',
        ], now()->addMinutes(15));

        $r = $svc->generar($this->aliadoId, $this->nit, $this->tipoDoc, $this->cedula, $this->userId);

        Cache::put($clave, ($r['ok'] ?? false)
            ? [
                'estado' => 'listo',
                'empresa' => $r['empresa'] ?? null,
                'documento_id' => $r['documento_id'] ?? null,
                'nombre' => $r['nombre'] ?? null,
            ]
            : [
                'estado' => 'error',
                'motivo' => $r['motivo'] ?? null,
                'error' => $r['error'] ?? 'No se pudo generar el certificado de Comfenalco.',
            ],
            now()->addMinutes(15));
    }

    public function failed(\Throwable $e): void
    {
        Cache::put(self::clave($this->token),
            ['estado' => 'error', 'error' => 'La generación del certificado falló: '.$e->getMessage()],
            now()->addMinutes(15));
    }

    public static function clave(string $token): string
    {
        return 'comfenalco-cert:'.$token;
    }
}
