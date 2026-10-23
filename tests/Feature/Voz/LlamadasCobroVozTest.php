<?php

namespace Tests\Feature\Voz;

use App\Models\LlamadaVoz;
use App\Services\Voz\LlamadasCobroVoz;
use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * El armado de las llamadas desde Cobros y el envío a un Avappi falso.
 */
class LlamadasCobroVozTest extends VozPruebaBase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('banco_cuentas')->insert([
            ['aliado_id' => self::ALIADO, 'banco' => 'Bancolombia', 'activo' => true, 'cobro' => true],
            ['aliado_id' => self::ALIADO, 'banco' => 'Nequi', 'activo' => true, 'cobro' => true],
            ['aliado_id' => self::ALIADO, 'banco' => 'Davivienda', 'activo' => true, 'cobro' => false],
            ['aliado_id' => 9, 'banco' => 'BBVA', 'activo' => true, 'cobro' => true],
        ]);
    }

    /** Una fila como las de CobrosController::obtenerDatosCobros(). */
    private function fila(int $id, ?string $celular, array $extra = []): object
    {
        return (object) ($extra + [
            'id' => $id,
            'fact_id' => null,
            'fact_pagada' => false,
            'es_empresa' => false,
            'es_retiro' => false,
            'es_afil' => false,
            'afil_atrasada' => null,
            'total_estimado' => 118000,
            'mora_estimada' => 2000,
            'cliente' => (object) ['celular' => $celular, 'nombre_corto' => "Cliente {$id}"],
            'vence_cobro' => Carbon::parse('2026-10-23'),
        ]);
    }

    private function lote(array $filas): array
    {
        $base = LlamadasCobroVoz::desdeCobros(self::ALIADO, $filas, 10, 2026);

        return LlamadasCobroVoz::armar(self::ALIADO, $base['filas'], 10, 2026, $base['omitidos']);
    }

    public function test_desde_cobros_deja_solo_individuales_sin_pagar_con_celular(): void
    {
        $base = LlamadasCobroVoz::desdeCobros(self::ALIADO, [
            $this->fila(1, '300 123 4567'),
            $this->fila(2, '3001234567', ['fact_pagada' => true]),
            $this->fila(3, '3001234567', ['es_empresa' => true]),
            $this->fila(4, '3001234567', ['es_afil' => true]),
            $this->fila(5, '3001234567', ['afil_atrasada' => (object) ['id' => 1]]),
            $this->fila(6, '6012345678'),
            $this->fila(7, null),
            $this->fila(8, '3001234567', ['es_retiro' => true]),
            $this->fila(9, '3001234567', ['total_estimado' => 0, 'mora_estimada' => 0]),
        ], 10, 2026);

        $this->assertCount(1, $base['filas']);
        $this->assertSame('3001234567', $base['filas'][0]['numero']);
        $this->assertSame(120000.0, $base['filas'][0]['valor']);
        $this->assertSame(['pagado' => 1, 'empresa' => 1, 'afiliacion' => 2, 'sin_celular' => 2, 'retiro' => 1, 'sin_valor' => 1], $base['omitidos']);
    }

    public function test_arma_una_llamada_por_celular_con_sus_variables(): void
    {
        $lote = $this->lote([
            $this->fila(1, '3001234567', ['fact_id' => 900]),
            $this->fila(2, '573001234567', ['vence_cobro' => Carbon::parse('2026-10-20'), 'total_estimado' => 80000, 'mora_estimada' => 0]),
            $this->fila(3, '3159876543', ['total_estimado' => 1000000, 'mora_estimada' => 0, 'vence_cobro' => Carbon::parse('2026-10-28')]),
        ]);

        $this->assertSame([
            'modo' => 'ia',
            'agente' => 'cobro-seguridad-social',
            'aviso_url' => 'https://brynex.test/avisos/voz/token-de-prueba',
            'variables' => [
                'organizacion' => 'Brygar',
                'formas_pago' => 'transferencia o consignación (Bancolombia o Nequi), o en efectivo',
            ],
        ], $lote['comunes']);
        $this->assertCount(2, $lote['llamadas']);

        [$martha, $otro] = $lote['llamadas'];
        $this->assertSame('3001234567', $martha['numero']);
        $this->assertSame('brynex:cobro:2:2026-10-22:3001234567', $martha['referencia']);
        $this->assertSame($martha['referencia'], $martha['idempotency_key']);
        $this->assertSame([
            'nombre' => 'Cliente 1',
            'monto' => 'doscientos mil pesos',
            'valor' => 200000,
            'concepto' => 'la seguridad social de octubre de las 2 personas que tiene afiliadas',
            'personas' => 2,
            // Manda el contrato que vence primero, y ese ya venció.
            'vence' => 'el martes 20 de octubre',
            'ya_vencio' => true,
        ], $martha['variables']);
        // Ya venció: hasta el final del siguiente día con horario.
        $this->assertSame('2026-10-23T19:00:00-05:00', $martha['responder_antes']);

        $this->assertSame('su seguridad social de octubre', $otro['variables']['concepto']);
        $this->assertSame('un millón de pesos', $otro['variables']['monto']);
        $this->assertSame('el miércoles 28 de octubre', $otro['variables']['vence']);
        $this->assertFalse($otro['variables']['ya_vencio']);
        // Antes del vencimiento: hasta la hora de corte de ese día.
        $this->assertSame('2026-10-28T16:30:00-05:00', $otro['responder_antes']);

        $serie = $lote['series']['brynex:cobro:2:2026-10-22:3001234567'];
        $this->assertSame([1, 2], $serie['contrato_ids']);
        $this->assertSame([1 => 900], $serie['factura_ids']);
        $this->assertSame((int) ceil(2 * 1.5 * 99), $lote['costo_estimado']);
        // Todo el lote cabe con holgura en el límite de variables de Avappi (4 KB).
        $this->assertLessThan(4096, strlen(json_encode($martha['variables'] + $lote['comunes']['variables'])));
    }

    public function test_no_llama_a_quien_no_debe(): void
    {
        $base = ['aliado_id' => self::ALIADO, 'mes' => 10, 'anio' => 2026, 'contrato_ids' => [99], 'referencia' => 'x', 'nombre' => 'X'];
        LlamadaVoz::create($base + ['avappi_id' => 'a1', 'numero' => '3000000001', 'resultado' => 'NO_CONTACTAR', 'created_at' => now()->subMonths(2)]);
        LlamadaVoz::create($base + ['avappi_id' => 'a2', 'numero' => '3000000002', 'resultado' => 'NUMERO_EQUIVOCADO', 'created_at' => now()->subMonth()]);
        LlamadaVoz::create($base + ['avappi_id' => 'a3', 'numero' => '3000000003', 'resultado' => 'PROMESA_DE_PAGO', 'fecha_promesa' => '2026-10-25', 'created_at' => now()->subDays(5)]);
        LlamadaVoz::create($base + ['avappi_id' => 'a4', 'numero' => '3000000004', 'created_at' => now()->subDay()]);
        // Promesa vencida, llamada vieja y la que Avappi rechazó ayer: esos sí se llaman.
        LlamadaVoz::create($base + ['avappi_id' => 'a5', 'numero' => '3000000005', 'resultado' => 'PROMESA_DE_PAGO', 'fecha_promesa' => '2026-10-15', 'created_at' => now()->subDays(10)]);
        LlamadaVoz::create($base + ['avappi_id' => 'a6', 'numero' => '3000000006', 'tipo' => 'llamada.rechazada', 'created_at' => now()->subDay()]);
        // «No me llamen» de otro aliado no cuenta aquí (Avappi igual lo rechaza).
        DB::table('aliados')->insert(['id' => 9, 'nombre' => 'Otro']);
        LlamadaVoz::create(['aliado_id' => 9, 'numero' => '3000000007', 'resultado' => 'NO_CONTACTAR'] + $base + ['avappi_id' => 'a7']);
        // WhatsApp de cobro hoy a un contrato.
        DB::table('bitacora_cobros')->insert(['aliado_id' => self::ALIADO, 'contrato_id' => 8, 'usuario_id' => 1, 'fecha_llamada' => now()->subHour(), 'resultado' => 'whatsapp']);

        $filas = [];
        foreach (range(1, 8) as $i) {
            $filas[] = $this->fila($i, '300000000'.$i);
        }
        $lote = $this->lote($filas);

        $this->assertSame(['3000000005', '3000000006', '3000000007'], array_column($lote['llamadas'], 'numero'));
        $this->assertSame([
            'no_contactar' => 1, 'numero_equivocado' => 1, 'promesa' => 1, 'llamado_hace_poco' => 1, 'whatsapp_hoy' => 1,
        ], $lote['omitidos'] + []);
    }

    public function test_responder_antes_salta_domingo_y_festivo_y_no_pasa_de_7_dias(): void
    {
        // Sábado 31 de octubre: el domingo 1 no cuenta y el lunes 2 de noviembre es festivo.
        $sabado = Carbon::parse('2026-10-31 09:00:00', 'America/Bogota');
        $this->assertSame('2026-11-03 19:00', LlamadasCobroVoz::responderAntes(Carbon::parse('2026-10-30'), $sabado)->format('Y-m-d H:i'));

        $hoy = Carbon::parse('2026-10-22 10:00:00', 'America/Bogota');
        $this->assertSame('2026-10-29 09:59', LlamadasCobroVoz::responderAntes(Carbon::parse('2026-11-12'), $hoy)->format('Y-m-d H:i'));
    }

    public function test_pesos_en_letras(): void
    {
        $this->assertSame('ciento veinte mil pesos', LlamadasCobroVoz::pesosEnLetras(120000));
        $this->assertSame('un millón de pesos', LlamadasCobroVoz::pesosEnLetras(1000000));
        $this->assertSame('un millón doscientos mil pesos', LlamadasCobroVoz::pesosEnLetras(1200000));
        $this->assertSame('ochenta y cinco mil quinientos pesos', LlamadasCobroVoz::pesosEnLetras(85500));
    }

    public function test_manda_el_lote_a_avappi_y_guarda_cada_llamada(): void
    {
        $lote = $this->lote([$this->fila(1, '3001234567', ['fact_id' => 900]), $this->fila(2, '3159876543')]);

        Http::fake(['https://avappi.test/api/voz/lotes' => Http::response([
            'ok' => true, 'creadas' => 1, 'no_creadas' => 1,
            'resultados' => [
                ['indice' => 0, 'ok' => true, 'llamada' => ['id' => 'AAAAAAAA-0000-4000-8000-000000000001', 'estado' => 'pendiente', 'intento' => 1]],
                ['indice' => 1, 'ok' => false, 'codigo' => 'NO_LLAMAR', 'error' => 'Pidió que no lo llamen',
                    'llamada' => ['id' => 'bbbbbbbb-0000-4000-8000-000000000002', 'estado' => 'rechazada']],
            ],
        ])]);

        $r = LlamadasCobroVoz::llamar(self::ALIADO, $lote, 10, 2026, 7);

        $this->assertSame(1, $r['en_cola']);
        $this->assertSame(0, $r['repetidas']);
        $this->assertSame('NO_LLAMAR', $r['no_salieron'][0]['codigo']);
        $this->assertArrayNotHasKey('error', $r);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $req) use ($lote) {
            return $req->url() === 'https://avappi.test/api/voz/lotes'
                && $req->method() === 'POST'
                && $req->header('X-Api-Key') === ['clave-de-prueba']
                && $req['comunes'] === $lote['comunes']
                && $req['llamadas'] === $lote['llamadas'];
        });

        $enCola = LlamadaVoz::where('avappi_id', 'aaaaaaaa-0000-4000-8000-000000000001')->first();
        $this->assertSame([1], $enCola->contrato_ids);
        $this->assertSame([1 => 900], $enCola->factura_ids);
        $this->assertSame(7, (int) $enCola->usuario_id);
        $this->assertSame(120000, (int) $enCola->valor);
        $this->assertFalse($enCola->final);
        $this->assertNull($enCola->aviso_en);

        $rechazada = LlamadaVoz::where('avappi_id', 'bbbbbbbb-0000-4000-8000-000000000002')->first();
        $this->assertTrue($rechazada->final);
        $this->assertSame('llamada.rechazada', $rechazada->tipo);
    }

    public function test_el_mismo_boton_dos_veces_no_duplica(): void
    {
        $lote = $this->lote([$this->fila(1, '3001234567')]);
        $respuesta = fn (bool $repetida) => Http::response(['ok' => true, 'resultados' => [
            ['indice' => 0, 'ok' => true, 'repetida' => $repetida, 'llamada' => ['id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'estado' => 'pendiente']],
        ]]);
        Http::fakeSequence('https://avappi.test/*')->pushResponse($respuesta(false))->pushResponse($respuesta(true));

        $this->assertSame(1, LlamadasCobroVoz::llamar(self::ALIADO, $lote, 10, 2026, 7)['en_cola']);
        $segunda = LlamadasCobroVoz::llamar(self::ALIADO, $lote, 10, 2026, 7);
        $this->assertSame(0, $segunda['en_cola']);
        $this->assertSame(1, $segunda['repetidas']);
        $this->assertSame(1, LlamadaVoz::count());
    }

    public function test_si_avappi_falla_lo_dice_sin_guardar(): void
    {
        $lote = $this->lote([$this->fila(1, '3001234567')]);
        Http::fake(['https://avappi.test/*' => Http::response(['ok' => false, 'codigo' => 'VOZ_INACTIVA', 'error' => 'Avappi Voz no está activo'], 403)]);

        $r = LlamadasCobroVoz::llamar(self::ALIADO, $lote, 10, 2026, 7);
        $this->assertStringContainsString('Avappi Voz no está activo', $r['error']);
        $this->assertSame(0, LlamadaVoz::count());
    }

    public function test_sin_clave_no_manda_nada(): void
    {
        config(['services.avappi_voz.key' => null]);
        $lote = $this->lote([$this->fila(1, '3001234567')]);
        Http::fake();

        $r = LlamadasCobroVoz::llamar(self::ALIADO, $lote, 10, 2026, 7);
        $this->assertStringContainsString('Falta configurar', $r['error']);
        Http::assertNothingSent();
    }

    public function test_solo_el_aliado_habilitado_ve_el_boton(): void
    {
        $this->assertTrue(LlamadasCobroVoz::habilitado(2));
        $this->assertFalse(LlamadasCobroVoz::habilitado(1));
        $this->assertFalse(LlamadasCobroVoz::habilitado(null));
    }

    public function test_solo_el_superadmin_de_brynex_puede_llamar(): void
    {
        $usuario = fn (bool $brynex, bool $superadmin) => new class($brynex, $superadmin)
        {
            public function __construct(public bool $es_brynex, private bool $superadmin) {}

            public function hasRole(string $rol): bool
            {
                return $rol === 'superadmin' && $this->superadmin;
            }
        };

        $this->assertTrue(LlamadasCobroVoz::puedeLlamar($usuario(true, true), 2));
        $this->assertFalse(LlamadasCobroVoz::puedeLlamar($usuario(true, true), 1), 'aliado sin habilitar');
        $this->assertFalse(LlamadasCobroVoz::puedeLlamar($usuario(true, false), 2), 'de BryNex pero no superadmin');
        $this->assertFalse(LlamadasCobroVoz::puedeLlamar($usuario(false, true), 2), 'superadmin de un aliado');
        $this->assertFalse(LlamadasCobroVoz::puedeLlamar(null, 2));
    }
}
