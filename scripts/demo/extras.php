<?php

/**
 * Lo que solo lleva la demo pública, encima del sembrado de los videos
 * (scripts/videos/demo/sembrar.php lo incluye con DEMO_PUBLICO=1, así que aquí
 * se usan sus variables: ALIADO, $usuarios, $ahora).
 *
 * Son llaves FALSAS: con BRYNEX_DEMO=true nada sale a internet, pero las
 * pantallas exigen que existan para mostrar el botón de liquidar, el chat de
 * WhatsApp o el asistente. Si alguien copiara esta base a producción, ninguna
 * sirve para entrar a nada.
 */

use App\Models\IaConfiguracionAliado;
use App\Models\OperadorCredencial;
use App\Models\OperadorPlanilla;
use App\Models\WhatsappConfig;
use App\Services\SuaporteApiService;
use Illuminate\Support\Facades\DB;

// ── Operadores de planilla por API (Enlace y Simple) ──────────────────────
// Una credencial por operador para todas las razones sociales del aliado
// (razon_social_id NULL), también las que cree el prospecto.
$operadores = OperadorPlanilla::whereNull('aliado_id')->where('activo', true)
    ->whereIn('codigo', array_keys(SuaporteApiService::HOSTS))->get();
foreach ($operadores as $op) {
    OperadorCredencial::updateOrCreate(
        ['aliado_id' => ALIADO, 'operador_planilla_id' => $op->id, 'razon_social_id' => null],
        ['usuario' => 'demo', 'contrasena' => 'demo-sin-valor', 'clave_secreta' => 'demo-sin-valor',
            'clave_secreta_expira_at' => now()->addYears(5)->toDateString()]
    );
}
echo 'Demo: credenciales de prueba para '.$operadores->pluck('nombre')->implode(', ')."\n";

// ── WhatsApp: cuenta propia del aliado, con datos de mentira ──────────────
WhatsappConfig::updateOrCreate(['aliado_id' => ALIADO], [
    'waba_id' => 'DEMO-WABA', 'phone_number_id' => 'DEMO-PHONE', 'access_token' => 'demo-sin-valor',
    'numero_telefono' => '+57 399 500 0000', 'nombre_cuenta' => 'Seguridad Social Demo',
    'usa_cuenta_brynex' => false, 'activo' => true, 'webhook_verificado' => true,
]);

// ── Asistente de IA: contesta IaSimulada, pero la pantalla pide una llave ──
IaConfiguracionAliado::updateOrCreate(['aliado_id' => ALIADO], [
    'proveedor' => 'claude', 'usa_cuenta_brynex' => false, 'api_key' => 'demo-sin-valor',
    'nombre_bot' => 'Avi (demo)', 'activo_web' => true, 'activo_whatsapp' => true,
]);

// ── Nadie de la demo es de BryNex: los módulos globales quedan fuera ──────
DB::table('users')->where('aliado_id', ALIADO)->update(['es_brynex' => 0]);
echo "Demo: WhatsApp e IA de prueba configurados\n";
