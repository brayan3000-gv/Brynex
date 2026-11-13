<?php

namespace App\Providers;

use App\Http\Middleware\InyectarDemo;
use App\Services\Demo\SimuladorHttp;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Arma el modo demo (config/demo.php). Fuera de la demo no hace nada.
 *
 * Todo lo de la demo vive en archivos propios —este proveedor, routes/demo.php,
 * App\Services\Demo, resources/views/demo— y entra a las pantallas de siempre
 * por el middleware InyectarDemo, para que las vistas de producción no cambien.
 */
class DemoServiceProvider extends ServiceProvider
{
    /**
     * Lo que en la demo no se puede tocar: los robots reales. En su lugar va la
     * radicación simulada (routes/demo.php).
     */
    private const GATES_APAGADOS = [
        'automatizar-arl',
        'automatizar-portales',
    ];

    public function boot(): void
    {
        if (! config('demo.activo')) {
            // Producción: atiende a la demo (guardar prospectos y mandarles sus
            // WhatsApp de prueba) solo si se configuró el secreto compartido.
            if (config('demo.relevo.secreto')) {
                Route::group([], base_path('routes/demo-relevo.php'));
            }

            return;
        }

        $this->exigirBaseDeDemo();

        // Ninguna petición HTTP sale: las conocidas se contestan simuladas y el
        // resto se corta. Va antes que nada para que valga también en consola.
        Http::fake(fn (PeticionHttp $peticion) => SimuladorHttp::responder($peticion));

        Gate::before(function ($user, string $ability) {
            return in_array($ability, self::GATES_APAGADOS, true) ? false : null;
        });

        $this->app['router']->pushMiddlewareToGroup('web', InyectarDemo::class);

        Route::middleware('web')->group(base_path('routes/demo.php'));
    }

    /**
     * Se niega a arrancar con una base que no sea de demo. El `.env` de la demo
     * se arma a mano en el servidor; si alguien copia el de producción y le
     * prende BRYNEX_DEMO, los prospectos entrarían a los datos reales.
     */
    private function exigirBaseDeDemo(): void
    {
        $permitidas = (array) config('demo.bases_permitidas');

        $principal = (string) config('database.connections.sqlsrv.database');
        $finanzas = (string) config('database.connections.finanzas.database');

        $ok = in_array($principal, $permitidas, true)
            && ($finanzas === '' || str_starts_with($finanzas, 'BryNex_Finanzas_') || in_array($finanzas, $permitidas, true));

        if (! $ok) {
            throw new RuntimeException(
                "BRYNEX_DEMO está prendido pero la base es «{$principal}» / «{$finanzas}». ".
                'La demo solo arranca con '.implode(' o ', $permitidas).'.'
            );
        }
    }
}
