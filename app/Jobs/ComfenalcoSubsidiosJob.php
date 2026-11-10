<?php

namespace App\Jobs;

use App\Services\Caja\ComfenalcoSubsidiosHeadless;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Consulta los bloqueos de subsidio (mora / inexactitud) de un trabajador en
 * Comfenalco Valle, en segundo plano: el robot entra al portal por empresa
 * (clave guardada), sale por el proxy colombiano y tarda 1–2 min, así que no
 * cabe en una petición HTTP. El resultado queda en caché bajo un token que el
 * modal de la ficha del contrato sondea.
 *
 * Análogo a ComfandiConsultaApiJob, pero Comfenalco entra POR EMPRESA (no hay
 * gestor único ni sesión caliente): cada consulta es un login.
 *
 * Ver ComfenalcoCajaController::subsidiosLanzar / subsidiosEstado.
 */
class ComfenalcoSubsidiosJob implements ShouldQueue
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
        public string $nit,
        public string $cedula,
        public int $meses = 4,
    ) {}

    public function handle(ComfenalcoSubsidiosHeadless $svc): void
    {
        $clave = self::clave($this->token);

        Cache::put($clave, [
            'estado' => 'corriendo',
            'paso' => 'Entrando al portal de Comfenalco y consultando (puede tardar 1–2 min)',
        ], now()->addMinutes(15));

        $r = $svc->bloqueos($this->nit, [$this->cedula], $this->meses);

        Cache::put($clave, ($r['ok'] ?? false)
            ? [
                'estado' => 'listo',
                'empresa' => $r['empresa'] ?? null,
                'movimientos' => $r['movimientos'] ?? [],
            ]
            : ['estado' => 'error', 'error' => $r['error'] ?? 'No se pudo consultar Comfenalco.'],
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
        return 'comfenalco-sub:'.$token;
    }
}
