<?php

namespace App\Console\Commands;

use App\Models\Aliado;
use App\Models\PautaConfig;
use App\Services\AlertaOperativaService;
use App\Services\RedesSociales\MetaTokenLargo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Avisa antes de que se venza el token con el que se crean los anuncios.
 *
 * Meta no emite tokens de usuario perpetuos: el largo dura unos 60 días y se acabó. Cuando
 * vence, `marketing:pauta-creatividades` empieza a fallar y no lo hace a gritos — deja de
 * meter piezas al conjunto y ya. El piloto sigue generando y publicando como si nada, así que
 * lo único que se nota es que el gasto se queda quieto, y eso se descubre tarde.
 *
 * Por eso el aviso sale con una semana de anticipación y se repite acercándose a la fecha, en
 * vez de una sola vez que se puede pasar por alto.
 *
 * Ejecución manual: php artisan pauta:token-vigilar
 */
class PautaTokenVigilar extends Command
{
    protected $signature = 'pauta:token-vigilar
        {--dias=7 : Con cuántos días de anticipación actuar}
        {--solo-avisar : No renovar solo; limitarse a avisar}';

    protected $description = 'Revisa cuánto le queda al token de pauta y avisa por WhatsApp antes de que venza';

    /**
     * Días exactos en los que se avisa. Avisar los 7 seguidos convierte la alerta en ruido.
     *
     * El 0 va incluido: "quedan 0 días" es que vence HOY, en unas horas — el momento en que
     * más urge. Sin él la primera corrida real avisaba a un día y después se callaba justo
     * cuando se estaba rompiendo.
     */
    private const HITOS = [7, 3, 1, 0];

    public function handle(AlertaOperativaService $alertas): int
    {
        $configs = PautaConfig::where('activo', true)->whereNotNull('access_token_ads')->get();

        if ($configs->isEmpty()) {
            $this->line('Ningún aliado tiene token de pauta propio configurado.');
            return self::SUCCESS;
        }

        foreach ($configs as $config) {
            $this->revisar($config, $alertas);
        }

        return self::SUCCESS;
    }

    private function revisar(PautaConfig $config, AlertaOperativaService $alertas): void
    {
        $nombre = Aliado::find($config->aliado_id)?->nombre ?? "aliado {$config->aliado_id}";

        $resp = Http::get('https://graph.facebook.com/v23.0/debug_token', [
            'input_token'  => $config->access_token_ads,
            'access_token' => $config->access_token_ads,
        ]);
        $datos = $resp->json('data');

        // Un token vencido no puede depurarse a sí mismo: Meta responde con error 190 en vez de
        // is_valid=false. Eso se leía como «¿sin red?» y del 11 al 28-oct-2026 el aviso se calló
        // 17 días seguidos justo con el token muerto. El 190 es la respuesta, no un corte.
        if ((int) $resp->json('error.code') === 190) {
            $datos = ['is_valid' => false];
        }

        // Sin respuesta utilizable no se puede concluir nada: puede ser un corte de red, y
        // gritar "token vencido" por eso sería peor que callarse.
        if (!is_array($datos) || !array_key_exists('is_valid', $datos)) {
            $this->warn("{$nombre}: no se pudo consultar el token (¿sin red?). No se avisa.");
            return;
        }

        if (!$datos['is_valid']) {
            $this->error("{$nombre}: el token de pauta YA NO SIRVE.");
            $alertas->enviarUnaVez(
                "pauta_token_invalido_{$config->aliado_id}",
                'Pauta ' . $nombre,
                'El token de anuncios ya no sirve: no se están creando anuncios nuevos (la lectura sigue con el token de la página). Hay que regenerarlo con pauta:token.',
                60 * 12
            );
            return;
        }

        $expira = (int) ($datos['expires_at'] ?? 0);

        // 0 = no vence (usuario de sistema). Es justo lo que queremos que pase algún día.
        if ($expira === 0) {
            $this->info("{$nombre}: el token no vence. Nada que vigilar.");
            return;
        }

        $dias = (int) floor(($expira - time()) / 86400);
        $fecha = date('Y-m-d', $expira);

        if ($dias < 0) {
            $this->error("{$nombre}: token vencido el {$fecha}.");
            $alertas->enviarUnaVez(
                "pauta_token_vencido_{$config->aliado_id}",
                'Pauta ' . $nombre,
                "El token de anuncios venció el {$fecha}. El piloto sigue publicando pero ya no crea anuncios. Regeneralo con pauta:token.",
                60 * 12
            );
            return;
        }

        $this->line("{$nombre}: al token le quedan {$dias} día(s) — vence el {$fecha}.");

        $umbral = (int) $this->option('dias');
        if ($dias > $umbral) {
            return;
        }

        // Antes de molestar a nadie, intentar renovarlo. Canjear un token largo por otro largo
        // devuelve el reloj a cero, así que el vencimiento deja de ser un problema recurrente:
        // una alerta que se repite cada dos meses termina siendo una tarea manual disfrazada.
        if (!$this->option('solo-avisar') && MetaTokenLargo::hayClaveSecreta()) {
            $canje = MetaTokenLargo::canjear($config->access_token_ads, (string) ($datos['app_id'] ?? ''));

            if ($canje['ok']) {
                $config->update(['access_token_ads' => $canje['token']]);
                $nuevoHasta = $canje['expira_en'] ? date('Y-m-d', time() + $canje['expira_en']) : 'sin vencimiento';
                $this->info("  → renovado solo, ahora hasta {$nuevoHasta}. Sin avisar a nadie.");

                return;
            }

            // Si el canje falla, el aviso pasa a ser MÁS urgente, no menos: significa que la
            // renovación automática tampoco va a funcionar sola la próxima vez.
            $this->warn('  → no se pudo renovar solo: ' . $canje['error']);
            $alertas->enviarUnaVez(
                "pauta_token_renovacion_fallida_{$config->aliado_id}",
                'Pauta ' . $nombre,
                "El token de anuncios vence el {$fecha} y la renovación automática falló: {$canje['error']} Hay que renovarlo a mano con pauta:token.",
                60 * 20
            );

            return;
        }

        if (!in_array($dias, self::HITOS, true)) {
            return;
        }

        $enviada = $alertas->enviarUnaVez(
            "pauta_token_por_vencer_{$config->aliado_id}_{$dias}",
            'Pauta ' . $nombre,
            "El token de anuncios vence el {$fecha}, en {$dias} día(s). Cuando venza dejan de crearse anuncios. Renovalo con pauta:token.",
            60 * 20
        );

        $this->line($enviada
            ? "  → aviso enviado a {$alertas->numeroDestino()}"
            : '  → sin aviso: ya se avisó de este hito hace poco.');
    }
}
