<?php

namespace App\Console\Commands;

use App\Models\WhatsappConfig;
use App\Models\WhatsappPlantilla;
use App\Services\AlertaOperativaService;
use App\Services\WhatsappBandejaCompartida;
use GuzzleHttp\Client;
use Illuminate\Console\Command;

/**
 * Crea en Meta las plantillas que el sistema necesita en CADA cuenta de WhatsApp, en
 * las que todavía no las tienen:
 *
 *  - `notificar_brynex`: los avisos. Nació en la cuenta de Brygar, que era la única que
 *    los mandaba. Desde que el reporte de conversaciones esperando le llega a cada
 *    aliado por la cuenta con la que hace sus envíos (oct-2026), tiene que existir
 *    también en la compartida de BryNex y en la de cada aliado con número propio.
 *  - `reabrir_conversacion`: el botón «Reabrir conversación» del chat. Con la ventana
 *    de 24 h vencida solo se puede escribir con plantilla, y las que había (cobro,
 *    planilla) no vienen al caso; esta invita al cliente a tocar «Continuar», y con
 *    ese toque se abren otras 24 h de texto libre.
 *
 * Hay una cuenta (WABA) por cada aliado con número propio, más la de BryNex que
 * comparten los demás. Las de BryNex quedan registradas a nombre del aliado BryNex,
 * que es donde las buscan el chat y AlertaOperativaService.
 *
 * Meta revisa cada plantilla antes de dejarla usar: con --estado se consulta cómo va
 * la revisión y se actualiza el registro, sin crear nada.
 */
class WhatsappPlantillasSistema extends Command
{
    protected $signature = 'whatsapp:plantillas-sistema
                            {--estado : Solo consultar en Meta el estado de revisión y actualizarlo}';

    protected $description = 'Crea (o consulta) en Meta las plantillas de avisos y de reabrir conversación en cada cuenta';

    private const GRAPH = 'https://graph.facebook.com/v19.0';

    /**
     * El cuerpo de `notificar_brynex` es, letra por letra, el que ya está aprobado en
     * la cuenta de Brygar.
     */
    private const PLANTILLAS = [
        AlertaOperativaService::NOMBRE_PLANTILLA => [
            'display' => 'Notificar Brynex',
            'cuerpo' => "🔔 *Brynex* - Mensaje de {{1}}: {{2}}.\n\npresiona el botón para mantener activa la sesión.",
            'ejemplo' => ['Esperando respuesta', '2 personas esperan respuesta: 1) Ana 3001234567 3h «Buenas tardes»'],
            'boton' => 'Mantener activo',
            'variables' => [],
        ],
        WhatsappPlantilla::SISTEMA_REABRIR => [
            'display' => 'Reabrir conversación',
            'cuerpo' => "Hola 👋 Te escribimos de *{{1}}*: ya tenemos respuesta a tu mensaje.\n\nToca el botón para continuar la conversación por este chat.",
            'ejemplo' => ['BryNex'],
            'boton' => 'Continuar',
            'variables' => ['1' => 'aliado.nombre'],
        ],
    ];

    public function handle(): int
    {
        $http = new Client(['timeout' => 40, 'connect_timeout' => 10]);

        foreach ($this->cuentas() as $cuenta) {
            $this->line("── WABA {$cuenta['creds']['waba_id']} (aliado {$cuenta['aliado_id']})");

            foreach (self::PLANTILLAS as $nombre => $def) {
                $this->procesar($http, $cuenta, $nombre, $def);
            }
        }

        return self::SUCCESS;
    }

    private function procesar(Client $http, array $cuenta, string $nombre, array $def): void
    {
        $enMeta = $this->buscarEnMeta($http, $cuenta['creds'], $nombre);

        if ($enMeta) {
            $this->registrar($cuenta['aliado_id'], $nombre, $def, $enMeta['id'], strtolower($enMeta['status']));
            $this->info("   {$nombre}: ya existe en Meta. Estado: {$enMeta['status']}");

            return;
        }

        if ($this->option('estado')) {
            $this->warn("   {$nombre}: no existe en Meta todavía.");

            return;
        }

        try {
            $resp = $http->post(self::GRAPH."/{$cuenta['creds']['waba_id']}/message_templates", [
                'headers' => ['Authorization' => "Bearer {$cuenta['creds']['access_token']}"],
                'json' => [
                    'name' => $nombre,
                    'language' => 'es_ES',
                    'category' => 'UTILITY',
                    'components' => [
                        ['type' => 'BODY', 'text' => $def['cuerpo'], 'example' => ['body_text' => [$def['ejemplo']]]],
                        ['type' => 'BUTTONS', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => $def['boton']]]],
                    ],
                ],
            ]);
            $data = json_decode((string) $resp->getBody(), true);
            $this->registrar($cuenta['aliado_id'], $nombre, $def, $data['id'] ?? null, strtolower($data['status'] ?? 'pending'));
            $this->info("   {$nombre}: creada, id ".($data['id'] ?? '?').' · estado '.($data['status'] ?? '?'));
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $this->error("   {$nombre}: Meta la rechazó: ".($e->hasResponse() ? (string) $e->getResponse()->getBody() : $e->getMessage()));
        }
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

    private function buscarEnMeta(Client $http, array $creds, string $nombre): ?array
    {
        $resp = $http->get(self::GRAPH."/{$creds['waba_id']}/message_templates", [
            'headers' => ['Authorization' => "Bearer {$creds['access_token']}"],
            'query' => ['name' => $nombre, 'fields' => 'id,name,status,language'],
        ]);

        foreach (json_decode((string) $resp->getBody(), true)['data'] ?? [] as $t) {
            if (($t['name'] ?? '') === $nombre) {
                return $t;
            }
        }

        return null;
    }

    private function registrar(int $aliadoId, string $nombre, array $def, ?string $metaId, string $estado): void
    {
        $existente = WhatsappPlantilla::where('aliado_id', $aliadoId)->where('nombre', $nombre)->first();

        // La que ya estaba solo se pone al día: no se le pisa el resto.
        if ($existente) {
            $existente->update(['estado' => $estado, 'meta_template_id' => $metaId ?: $existente->meta_template_id]);

            return;
        }

        WhatsappPlantilla::create([
            'aliado_id' => $aliadoId,
            'nombre' => $nombre,
            'nombre_display' => $def['display'],
            'categoria' => 'UTILITY',
            'idioma' => 'es_ES',
            'estado' => $estado,
            'meta_template_id' => $metaId,
            'creado_en_meta' => true,
            'cuerpo' => $def['cuerpo'],
            'botones' => [['tipo' => 'QUICK_REPLY', 'texto' => $def['boton'], 'url' => null, 'telefono' => null]],
            'variables_mapa' => $def['variables'],
        ]);
    }
}
