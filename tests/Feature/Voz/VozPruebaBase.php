<?php

namespace Tests\Feature\Voz;

use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Base de las pruebas de Avappi Voz: SQLite en memoria con solo las tablas que
 * tocan las llamadas, y ninguna petición HTTP real (Http::preventStrayRequests).
 */
abstract class VozPruebaBase extends TestCase
{
    protected const SECRETO = 'vzs_secreto_de_prueba_1234567890';

    protected const ALIADO = 2;

    /**
     * routes/web.php consulta `aliados` al cargarse, así que las tablas se
     * crean antes de que arranquen los providers (la BD en memoria vive lo que
     * vive la aplicación de cada prueba).
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../../../bootstrap/app.php';
        $app->beforeBootstrapping(BootProviders::class, function ($app) {
            // La BD local es la de producción: estas pruebas solo corren en SQLite.
            if ($app['db']->connection()->getDriverName() !== 'sqlite') {
                throw new \RuntimeException('Las pruebas de voz deben correr con DB_CONNECTION=sqlite (ver phpunit.xml).');
            }
            static::crearTablas();
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    private static function crearTablas(): void
    {
        Schema::create('aliados', function (Blueprint $t) {
            $t->id();
            $t->string('nombre');
            $t->string('slug')->nullable();
            $t->string('dominio_propio')->nullable();
            $t->boolean('activo')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('whatsapp_configuracion_aliado', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('aliado_id');
            $t->string('nombre_cuenta')->nullable();
            $t->string('numero_telefono')->nullable();
            $t->timestamps();
        });
        Schema::create('banco_cuentas', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('aliado_id');
            $t->string('nombre')->nullable();
            $t->string('banco')->nullable();
            $t->string('numero_cuenta')->nullable();
            $t->string('llave')->nullable();
            $t->boolean('activo')->default(true);
            $t->boolean('cobro')->default(true);
            $t->timestamps();
        });
        Schema::create('configuracion_aliado', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('aliado_id');
            $t->unsignedBigInteger('plan_id')->nullable();
            $t->unsignedTinyInteger('mora_dia_habil_inicio')->nullable();
            $t->decimal('mora_minimo', 10, 0)->nullable();
            $t->decimal('mora_segundo', 10, 0)->nullable();
        });
        // Como en SQL Server: usuario obligatorio y solo estos resultados.
        Schema::create('bitacora_cobros', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('aliado_id');
            $t->unsignedBigInteger('contrato_id')->nullable();
            $t->unsignedBigInteger('empresa_id')->nullable();
            $t->unsignedBigInteger('factura_id')->nullable();
            $t->unsignedBigInteger('usuario_id');
            $t->timestamp('fecha_llamada')->useCurrent();
            $t->enum('resultado', ['no_contesta', 'promesa_pago', 'pagado', 'numero_errado', 'whatsapp', 'otro']);
            $t->text('observacion')->nullable();
            $t->string('tipo')->nullable();
            $t->timestamps();
        });
        (require base_path('database/migrations/2026_10_22_200000_create_llamadas_voz_table.php'))->up();
        (require base_path('database/migrations/2026_10_22_230000_add_contestada_en_to_llamadas_voz_table.php'))->up();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-10-22 10:00:00', 'America/Bogota'));

        config([
            'app.url' => 'https://brynex.test',
            'services.avappi_voz.url' => 'https://avappi.test',
            'services.avappi_voz.key' => 'clave-de-prueba',
            'services.avappi_voz.secreto' => self::SECRETO,
            'services.avappi_voz.token_aviso' => 'token-de-prueba',
            'services.avappi_voz.agente' => 'cobro-seguridad-social',
            'services.avappi_voz.aliados' => [self::ALIADO],
            'services.avappi_voz.costo_minuto' => 99,
        ]);

        DB::table('aliados')->insert(['id' => self::ALIADO, 'nombre' => 'Brygar', 'activo' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
