<?php

namespace App\Console\Commands;

use App\Models\WhatsappConfig;
use App\Models\WhatsappPlantilla;
use App\Services\AlertaOperativaService;
use App\Services\WhatsappBandejaCompartida;
use GuzzleHttp\Client;
use Illuminate\Console\Command;

/**
 * Crea en Meta la plantilla de avisos (`notificar_brynex`) en las cuentas de WhatsApp
 * que todavía no la tienen.
 *
 * Nació en la cuenta de Brygar, que era la única que mandaba avisos. Desde que el
 * reporte de conversaciones esperando respuesta le llega a cada aliado por la cuenta
 * con la que hace sus envíos (oct-2026), la plantilla tiene que existir también en la
 * cuenta compartida de BryNex y en la de cada aliado con número propio: sin ella, el
 * aviso solo sale a quien tenga abierta la ventana de 24 h.
 *
 * Hay una cuenta (WABA) por cada aliado con número propio, más la de BryNex que
 * comparten los demás. La de BryNex queda registrada a nombre del aliado BryNex, que
 * es donde la busca AlertaOperativaService.
 *
 * Meta revisa la plantilla antes de dejarla usar: con --estado se consulta cómo va la
 * revisión y se actualiza el registro, sin crear nada.
 */
class WhatsappPlantillaAviso extends Command
{
    protected $signature = 'whatsapp:plantilla-aviso
                            {--estado : Solo consultar en Meta el estado de revisión y actualizarlo}';

    protected $description = 'Crea (o consulta) en Meta la plantilla de avisos en cada cuenta de WhatsApp';

    private const GRAPH = 'https://graph.facebook.com/v19.0';

    /** El mismo texto que ya está aprobado en la cuenta de Brygar. */
    private const CUERPO = "🔔 *Brynex* - Mensaje de {{1}}: {{2}}.\n\npresiona el botón para mantener activa la sesión.";

    private const BOTON = 'Mantener activo';

    public function handle(): int
    {
        $http = new Client(['timeout' => 40, 'connect_timeout' => 10]);

        foreach ($this->cuentas() as $cuenta) {
            $this->line("── WABA {$cuenta['creds']['waba_id']} (aliado {$cuenta['aliado_id']})");

            $enMeta = $this->buscarEnMeta($http, $cuenta['creds']);

            if ($enMeta) {
                $this->registrar($cuenta['aliado_id'], $enMeta['id'], strtolower($enMeta['status']));
                $this->info("   Ya existe en Meta. Estado: {$enMeta['status']}");

                continue;
            }

            if ($this->option('estado')) {
                $this->warn('   No existe en Meta todavía.');

                continue;
            }

            try {
                $resp = $http->post(self::GRAPH."/{$cuenta['creds']['waba_id']}/message_templates", [
                    'headers' => ['Authorization' => "Bearer {$cuenta['creds']['access_token']}"],
                    'json' => [
                        'name' => AlertaOperativaService::NOMBRE_PLANTILLA,
                        'language' => 'es_ES',
                        'category' => 'UTILITY',
                        'components' => [
                            [
                                'type' => 'BODY',
                                'text' => self::CUERPO,
                                'example' => ['body_text' => [['Esperando respuesta', '2 personas esperan respuesta: 1) Ana 3001234567 3h «Buenas tardes»']]],
                            ],
                            [
                                'type' => 'BUTTONS',
                                'buttons' => [['type' => 'QUICK_REPLY', 'text' => self::BOTON]],
                            ],
                        ],
                    ],
                ]);
                $data = json_decode((string) $resp->getBody(), true);
                $this->registrar($cuenta['aliado_id'], $data['id'] ?? null, strtolower($data['status'] ?? 'pending'));
                $this->info('   Creada: id '.($data['id'] ?? '?').' · estado '.($data['status'] ?? '?'));
            } catch (\GuzzleHttp\Exception\RequestException $e) {
                $this->error('   Meta rechazó la plantilla: '.($e->hasResponse() ? (string) $e->getResponse()->getBody() : $e->getMessage()));
            }
        }

        return self::SUCCESS;
    }

    /**
     * Una entrada por WABA distinta: la de BryNex (a nombre del aliado BryNex) y la de
     * cada aliado con cuenta propia.
     *
     * @return array<int, array{aliado_id:int, creds:array}>
     */
    private function cuentas(): array
    {
        $cuentas = [];
        foreach (WhatsappConfig::where('activo', true)->orderBy('aliado_id')->get() as $config) {
            if (! $config->credencialesCompletas()) {
                continue;
            }
            $creds = $config->credencialesEfectivas();
            $dueno = $config->usa_cuenta_brynex ? WhatsappBandejaCompartida::aliadoBandeja() : (int) $config->aliado_id;
            $cuentas[$creds['waba_id']] ??= ['aliado_id' => $dueno, 'creds' => $creds];
        }

        return array_values($cuentas);
    }

    private function buscarEnMeta(Client $http, array $creds): ?array
    {
        $resp = $http->get(self::GRAPH."/{$creds['waba_id']}/message_templates", [
            'headers' => ['Authorization' => "Bearer {$creds['access_token']}"],
            'query' => ['name' => AlertaOperativaService::NOMBRE_PLANTILLA, 'fields' => 'id,name,status,language'],
        ]);

        foreach (json_decode((string) $resp->getBody(), true)['data'] ?? [] as $t) {
            if (($t['name'] ?? '') === AlertaOperativaService::NOMBRE_PLANTILLA) {
                return $t;
            }
        }

        return null;
    }

    private function registrar(int $aliadoId, ?string $metaId, string $estado): void
    {
        $existente = WhatsappPlantilla::where('aliado_id', $aliadoId)
            ->where('nombre', AlertaOperativaService::NOMBRE_PLANTILLA)
            ->first();

        // La que ya estaba (Brygar) solo se pone al día: no se le pisa el resto.
        if ($existente) {
            $existente->update(['estado' => $estado, 'meta_template_id' => $metaId ?: $existente->meta_template_id]);

            return;
        }

        WhatsappPlantilla::create([
            'aliado_id' => $aliadoId,
            'nombre' => AlertaOperativaService::NOMBRE_PLANTILLA,
            'nombre_display' => 'Notificar Brynex',
            'categoria' => 'UTILITY',
            'idioma' => 'es_ES',
            'estado' => $estado,
            'meta_template_id' => $metaId,
            'creado_en_meta' => true,
            'cuerpo' => self::CUERPO,
            'botones' => [['tipo' => 'QUICK_REPLY', 'texto' => self::BOTON, 'url' => null, 'telefono' => null]],
            'variables_mapa' => [],
        ]);
    }
}
