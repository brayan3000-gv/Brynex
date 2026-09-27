<?php

namespace App\Console\Commands;

use App\Models\WhatsappConfig;
use App\Models\WhatsappPlantilla;
use App\Services\ReciboVisitaService;
use GuzzleHttp\Client;
use Illuminate\Console\Command;

/**
 * Crea en Meta la plantilla con la que sale el recibo de pago por WhatsApp
 * (header DOCUMENT con el PDF + cuerpo con nombre, aliado, valor y concepto).
 *
 * Hay una cuenta de WhatsApp (WABA) por cada aliado con número propio, más la
 * de BryNex que comparten los demás: la plantilla se crea en cada una y queda
 * registrada en whatsapp_plantillas para el aliado dueño de esa cuenta. Los
 * aliados que usan la cuenta de BryNex la encuentran por el nombre (ver
 * ReciboVisitaService::plantillaPara).
 *
 * Meta revisa la plantilla antes de dejarla usar: con --estado se consulta
 * cómo va la revisión y se actualiza el registro.
 */
class WhatsappPlantillaRecibo extends Command
{
    protected $signature = 'whatsapp:plantilla-recibo
                            {--estado : Solo consultar en Meta el estado de revisión y actualizarlo}';

    protected $description = 'Crea (o consulta) en Meta la plantilla del recibo de pago por WhatsApp';

    private const GRAPH = 'https://graph.facebook.com/v19.0';

    public function handle(): int
    {
        $http = new Client(['timeout' => 40, 'connect_timeout' => 10]);

        foreach ($this->cuentas() as $cuenta) {
            $this->line("── WABA {$cuenta['creds']['waba_id']} (aliado {$cuenta['aliado_id']})");

            $enMeta = $this->buscarEnMeta($http, $cuenta['creds']);

            if ($this->option('estado') || $enMeta) {
                if (! $enMeta) {
                    $this->warn('   No existe en Meta todavía.');

                    continue;
                }
                $this->registrar($cuenta['aliado_id'], $enMeta['id'], strtolower($enMeta['status']));
                $this->info("   Estado en Meta: {$enMeta['status']}");

                continue;
            }

            $handle = $this->subirEjemplo($http, $cuenta['creds']);
            if (! $handle) {
                $this->error('   No se pudo subir el PDF de ejemplo a Meta.');

                continue;
            }

            try {
                $resp = $http->post(self::GRAPH."/{$cuenta['creds']['waba_id']}/message_templates", [
                    'headers' => ['Authorization' => "Bearer {$cuenta['creds']['access_token']}"],
                    'json' => [
                        'name' => ReciboVisitaService::PLANTILLA,
                        'language' => 'es_ES',
                        'category' => 'UTILITY',
                        'components' => [
                            ['type' => 'HEADER', 'format' => 'DOCUMENT', 'example' => ['header_handle' => [$handle]]],
                            [
                                'type' => 'BODY',
                                'text' => ReciboVisitaService::CUERPO_PLANTILLA,
                                'example' => ['body_text' => [['Juan Pérez', 'BryNex', '$185.000', 'Seguridad social de octubre 2026']]],
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
     * Una entrada por WABA distinta: la de BryNex (a nombre del primer aliado
     * que la usa) y la de cada aliado con cuenta propia.
     *
     * @return array<int, array{aliado_id:int, creds:array}>
     */
    private function cuentas(): array
    {
        $cuentas = [];
        foreach (WhatsappConfig::orderBy('aliado_id')->get() as $config) {
            if (! $config->credencialesCompletas()) {
                continue;
            }
            $creds = $config->credencialesEfectivas();
            $cuentas[$creds['waba_id']] ??= ['aliado_id' => (int) $config->aliado_id, 'creds' => $creds];
        }

        return array_values($cuentas);
    }

    private function buscarEnMeta(Client $http, array $creds): ?array
    {
        $resp = $http->get(self::GRAPH."/{$creds['waba_id']}/message_templates", [
            'headers' => ['Authorization' => "Bearer {$creds['access_token']}"],
            'query' => ['name' => ReciboVisitaService::PLANTILLA, 'fields' => 'id,name,status,language'],
        ]);

        foreach (json_decode((string) $resp->getBody(), true)['data'] ?? [] as $t) {
            if (($t['name'] ?? '') === ReciboVisitaService::PLANTILLA) {
                return $t;
            }
        }

        return null;
    }

    /**
     * Meta exige un documento de muestra para una plantilla con header
     * DOCUMENT, y no acepta una URL: hay que subirlo por la API de subida
     * reanudable de la app y pasar el "handle" que devuelve.
     */
    private function subirEjemplo(Client $http, array $creds): ?string
    {
        $token = $creds['access_token'];

        try {
            $debug = json_decode((string) $http->get(self::GRAPH.'/debug_token', [
                'query' => ['input_token' => $token, 'access_token' => $token],
            ])->getBody(), true);
            $appId = $debug['data']['app_id'] ?? null;
            if (! $appId) {
                return null;
            }

            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML(
                '<h2 style="font-family:sans-serif">Recibo de pago</h2>'
                .'<p style="font-family:sans-serif">Juan Pérez · CC 1.000.000<br>Seguridad social de octubre 2026<br><b>Total pagado: $185.000</b></p>'
            )->setPaper([0, 0, 226.77, 400])->output();

            $sesion = json_decode((string) $http->post(self::GRAPH."/{$appId}/uploads", [
                'query' => [
                    'file_name' => 'recibo-de-pago.pdf',
                    'file_length' => strlen($pdf),
                    'file_type' => 'application/pdf',
                    'access_token' => $token,
                ],
            ])->getBody(), true);
            if (empty($sesion['id'])) {
                return null;
            }

            $subida = json_decode((string) $http->post(self::GRAPH.'/'.$sesion['id'], [
                'headers' => ['Authorization' => "OAuth {$token}", 'file_offset' => '0'],
                'body' => $pdf,
            ])->getBody(), true);

            return $subida['h'] ?? null;
        } catch (\Throwable $e) {
            $this->warn('   '.$e->getMessage());

            return null;
        }
    }

    private function registrar(int $aliadoId, ?string $metaId, string $estado): void
    {
        WhatsappPlantilla::updateOrCreate(
            ['aliado_id' => $aliadoId, 'nombre' => ReciboVisitaService::PLANTILLA],
            [
                'nombre_display' => 'Recibo de pago (PDF)',
                'categoria' => 'UTILITY',
                'idioma' => 'es_ES',
                'estado' => $estado,
                'meta_template_id' => $metaId,
                'creado_en_meta' => true,
                'header_tipo' => 'DOCUMENT',
                'cuerpo' => ReciboVisitaService::CUERPO_PLANTILLA,
                'variables_mapa' => ['1' => 'cliente.nombre', '2' => 'aliado.nombre', '3' => 'pago.valor', '4' => 'pago.concepto'],
            ]
        );
    }
}
