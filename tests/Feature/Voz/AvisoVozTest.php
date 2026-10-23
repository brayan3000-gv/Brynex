<?php

namespace Tests\Feature\Voz;

use App\Models\LlamadaVoz;
use Illuminate\Support\Facades\DB;

/**
 * POST /avisos/voz/{token}: firma buena, mala, vieja, aviso repetido y lo que
 * cada resultado deja en la bitácora de cobros.
 */
class AvisoVozTest extends VozPruebaBase
{
    private const ID1 = '11111111-1111-4111-8111-111111111111';

    private const ID2 = '22222222-2222-4222-8222-222222222222';

    private const REF = 'brynex:cobro:2:2026-10-22:3001234567';

    protected function setUp(): void
    {
        parent::setUp();

        // Lo que deja el botón: la llamada en cola, con sus dos contratos.
        LlamadaVoz::create([
            'aliado_id' => self::ALIADO,
            'avappi_id' => self::ID1,
            'referencia' => self::REF,
            'numero' => '3001234567',
            'nombre' => 'Martha',
            'contrato_ids' => [10, 11],
            'factura_ids' => [10 => 500],
            'mes' => 10,
            'anio' => 2026,
            'valor' => 240000,
            'vence' => '2026-10-23',
            'usuario_id' => 7,
            'estado' => 'pendiente',
        ]);
    }

    private function firmar(string $cuerpo, ?int $t = null, string $secreto = self::SECRETO): string
    {
        $t ??= time();

        return "t={$t},v1=".hash_hmac('sha256', "{$t}.{$cuerpo}", $secreto);
    }

    private function aviso(array $campos = []): array
    {
        return $campos + [
            'tipo' => 'llamada.terminada',
            'id' => self::ID1,
            'referencia' => self::REF,
            'numero' => '3001234567',
            'modo' => 'ia',
            'estado' => 'terminada',
            'causa' => 'contestada',
            'duracion_seg' => 84,
            'costo_cop' => 198,
            'intento' => 1,
            'final' => true,
            'resultado' => 'PROMESA_DE_PAGO',
            'resumen' => 'Dice que paga el jueves por Nequi.',
            'datos' => ['fecha_pago' => '2026-10-30', 'monto' => 240000, 'medio_pago' => 'transferencia'],
        ];
    }

    private function enviar(array $aviso, ?string $firma = null, string $token = 'token-de-prueba', ?string $id = null)
    {
        $cuerpo = json_encode($aviso, JSON_UNESCAPED_UNICODE);

        return $this->call('POST', "/avisos/voz/{$token}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_AVAPPI_FIRMA' => $firma ?? $this->firmar($cuerpo),
            'HTTP_X_AVAPPI_AVISO' => $id ?? ($aviso['id'] ?? self::ID1),
        ], $cuerpo);
    }

    public function test_firma_buena_aplica_la_promesa_en_la_bitacora(): void
    {
        $this->enviar($this->aviso())->assertOk()->assertJson(['ok' => true, 'estado' => 'procesado', 'repetido' => false]);

        $f = LlamadaVoz::where('avappi_id', self::ID1)->first();
        $this->assertSame('PROMESA_DE_PAGO', $f->resultado);
        $this->assertSame('2026-10-30', $f->fecha_promesa->toDateString());
        $this->assertNotNull($f->aviso_en);
        $this->assertTrue($f->final);
        $this->assertSame(84, (int) $f->duracion_seg);

        $bitacora = DB::table('bitacora_cobros')->orderBy('contrato_id')->get();
        $this->assertCount(2, $bitacora);
        $this->assertSame(['promesa_pago', 'promesa_pago'], $bitacora->pluck('resultado')->all());
        $this->assertEquals([10, 11], $bitacora->pluck('contrato_id')->map(fn ($v) => (int) $v)->all());
        $this->assertEquals(500, $bitacora[0]->factura_id);
        $this->assertNull($bitacora[1]->factura_id);
        $this->assertEquals(7, $bitacora[0]->usuario_id);
        $this->assertStringContainsString('30/10/2026', $bitacora[0]->observacion);
        $this->assertStringContainsString('$240.000', $bitacora[0]->observacion);
        $this->assertStringContainsString('Nequi', $bitacora[0]->observacion);
    }

    public function test_firma_mala_se_rechaza_sin_tocar_nada(): void
    {
        $cuerpo = json_encode($this->aviso());
        $this->enviar($this->aviso(), $this->firmar($cuerpo, null, 'vzs_otro_secreto_que_no_es'))->assertStatus(401);
        $this->enviar($this->aviso(), 't='.time().',v1=deadbeef')->assertStatus(401);
        $this->enviar($this->aviso(), 'sin firma')->assertStatus(401);

        $this->assertNull(LlamadaVoz::where('avappi_id', self::ID1)->first()->aviso_en);
        $this->assertSame(0, DB::table('bitacora_cobros')->count());
    }

    public function test_firma_de_otro_cuerpo_se_rechaza(): void
    {
        $firmaDeOtro = $this->firmar(json_encode($this->aviso(['resultado' => 'YA_PAGO'])));
        $this->enviar($this->aviso(), $firmaDeOtro)->assertStatus(401);
        $this->assertSame(0, DB::table('bitacora_cobros')->count());
    }

    public function test_firma_vieja_o_adelantada_se_rechaza(): void
    {
        $cuerpo = json_encode($this->aviso(), JSON_UNESCAPED_UNICODE);
        $this->enviar($this->aviso(), $this->firmar($cuerpo, time() - 301))->assertStatus(401);
        $this->enviar($this->aviso(), $this->firmar($cuerpo, time() + 301))->assertStatus(401);
        $this->assertSame(0, DB::table('bitacora_cobros')->count());

        // Dentro de los 5 minutos sí pasa.
        $this->enviar($this->aviso(), $this->firmar($cuerpo, time() - 290))->assertOk();
    }

    public function test_token_equivocado_da_404(): void
    {
        $this->enviar($this->aviso(), null, 'otro-token')->assertNotFound();
        $this->assertSame(0, DB::table('bitacora_cobros')->count());
    }

    public function test_sin_secreto_configurado_no_acepta_nada(): void
    {
        config(['services.avappi_voz.secreto' => null]);
        $this->enviar($this->aviso())->assertStatus(401);
    }

    public function test_aviso_repetido_no_se_aplica_dos_veces(): void
    {
        $this->enviar($this->aviso())->assertOk()->assertJson(['repetido' => false]);
        $this->enviar($this->aviso())->assertOk()->assertJson(['repetido' => true, 'estado' => 'repetido']);

        $this->assertSame(2, DB::table('bitacora_cobros')->count());
        $this->assertSame(1, LlamadaVoz::count());
    }

    public function test_aviso_sin_id_se_rechaza(): void
    {
        $this->enviar($this->aviso(['id' => 'no-es-un-uuid']), null, 'token-de-prueba', 'no-es-un-uuid')->assertStatus(422);
    }

    public function test_intento_sin_contestar_con_reintento_no_va_a_la_bitacora_y_el_ultimo_si(): void
    {
        $sinContestar = ['resultado' => null, 'resumen' => null, 'datos' => null, 'causa' => 'no_contesta', 'duracion_seg' => 0];

        $this->enviar($this->aviso($sinContestar + ['final' => false, 'proximo_intento_en' => '2026-10-22T14:00:00-05:00']))->assertOk();
        $this->assertSame(0, DB::table('bitacora_cobros')->count());

        // El reintento llega con otro id y la misma referencia.
        $this->enviar($this->aviso($sinContestar + ['id' => self::ID2, 'intento' => 2, 'final' => true]))->assertOk();

        $this->assertSame(2, LlamadaVoz::count());
        $segundo = LlamadaVoz::where('avappi_id', self::ID2)->first();
        $this->assertSame([10, 11], $segundo->contrato_ids);
        $this->assertSame(2, (int) $segundo->intento);
        $this->assertSame(self::ALIADO, (int) $segundo->aliado_id);

        $bitacora = DB::table('bitacora_cobros')->get();
        $this->assertCount(2, $bitacora);
        $this->assertSame(['no_contesta'], $bitacora->pluck('resultado')->unique()->values()->all());
        $this->assertStringContainsString('intento 2', $bitacora[0]->observacion);
    }

    public function test_ya_pago_y_no_continua_quedan_para_el_equipo_sin_marcar_pagado(): void
    {
        $this->enviar($this->aviso(['resultado' => 'YA_PAGO', 'datos' => null, 'resumen' => 'Pagó ayer en la oficina.']))->assertOk();
        $this->enviar($this->aviso(['id' => self::ID2, 'resultado' => 'NO_CONTINUA', 'datos' => ['motivo' => 'consiguió trabajo'], 'resumen' => 'Ya no sigue.']))->assertOk();

        $bitacora = DB::table('bitacora_cobros')->get();
        $this->assertCount(4, $bitacora);
        $this->assertSame(['otro'], $bitacora->pluck('resultado')->unique()->values()->all());
        $this->assertTrue($bitacora->every(fn ($b) => str_starts_with($b->observacion, '⚠️ Para el equipo.')));
        $this->assertStringContainsString('consiguió trabajo', $bitacora[3]->observacion);
    }

    public function test_promesa_que_pide_las_cuentas_queda_para_el_equipo(): void
    {
        $datos = ['fecha_pago' => '30/10/2026', 'monto' => 240000, 'medio_pago' => 'transferencia', 'pide_cuentas' => true];
        $this->enviar($this->aviso(['datos' => $datos]))->assertOk();

        $b = DB::table('bitacora_cobros')->first();
        $this->assertSame('promesa_pago', $b->resultado);
        $this->assertStringStartsWith('⚠️ Para el equipo.', $b->observacion);
        $this->assertStringContainsString('pide las cuentas por WhatsApp', $b->observacion);
        $this->assertSame('2026-10-30', LlamadaVoz::first()->fecha_promesa->toDateString());
    }

    public function test_contesto_y_colgo_sin_resultado_no_es_no_contesta(): void
    {
        $this->enviar($this->aviso(['resultado' => null, 'datos' => null, 'resumen' => null, 'contestada_en' => '2026-10-22T15:00:05Z']))->assertOk();

        $b = DB::table('bitacora_cobros')->first();
        $this->assertSame('otro', $b->resultado);
        $this->assertStringContainsString('Contestó y colgó', $b->observacion);
        $this->assertNotNull(LlamadaVoz::first()->contestada_en);
    }

    public function test_pregunta_sin_responder_y_visita_quedan_para_el_equipo(): void
    {
        $datos = ['fecha_pago' => '2026-10-30', 'monto' => 240000, 'medio_pago' => 'visita', 'pregunta' => '¿sigo activa en la EPS?'];
        $this->enviar($this->aviso(['datos' => $datos]))->assertOk();

        $b = DB::table('bitacora_cobros')->first();
        $this->assertStringStartsWith('⚠️ Para el equipo.', $b->observacion);
        $this->assertStringContainsString('hay que coordinar la visita', $b->observacion);
        $this->assertStringContainsString('preguntó: ¿sigo activa en la EPS?', $b->observacion);
    }

    public function test_ya_pago_anota_cuando_y_por_donde(): void
    {
        $this->enviar($this->aviso(['resultado' => 'YA_PAGO', 'datos' => ['fecha_pago' => '2026-10-21', 'medio_pago' => 'transferencia']]))->assertOk();

        $obs = DB::table('bitacora_cobros')->value('observacion');
        $this->assertStringContainsString('dice que pagó el 2026-10-21', $obs);
        $this->assertStringContainsString('por transferencia', $obs);
        $this->assertNull(LlamadaVoz::first()->fecha_promesa);
    }

    public function test_numero_equivocado_queda_como_numero_errado(): void
    {
        $this->enviar($this->aviso(['resultado' => 'NUMERO_EQUIVOCADO', 'datos' => null]))->assertOk();
        $this->assertSame(['numero_errado'], DB::table('bitacora_cobros')->pluck('resultado')->unique()->values()->all());
    }

    public function test_llamada_rechazada_o_cancelada_no_va_a_la_bitacora(): void
    {
        $this->enviar($this->aviso(['tipo' => 'llamada.cancelada', 'resultado' => null, 'datos' => null, 'final' => true]))->assertOk();
        $this->assertSame(0, DB::table('bitacora_cobros')->count());
        $this->assertSame('llamada.cancelada', LlamadaVoz::first()->tipo);
    }

    public function test_aviso_de_una_llamada_que_no_es_de_brynex_se_ignora(): void
    {
        $this->enviar($this->aviso(['id' => self::ID2, 'referencia' => 'mega:entrega:2026-10-22:3000000000']))
            ->assertOk()->assertJson(['estado' => 'ignorado']);
        $this->assertSame(1, LlamadaVoz::count());
        $this->assertSame(0, DB::table('bitacora_cobros')->count());
    }
}
