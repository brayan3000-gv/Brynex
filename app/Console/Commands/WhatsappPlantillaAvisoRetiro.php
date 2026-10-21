<?php

namespace App\Console\Commands;

use App\Models\WhatsappConfig;
use App\Models\WhatsappPlantilla;
use GuzzleHttp\Client;
use Illuminate\Console\Command;

/**
 * Crea en la cuenta de WhatsApp compartida de BryNex la plantilla de cobro
 * «aviso_retiro_eps»: el aviso de que faltan pocos días para los retiros de la
 * EPS. La pidió Grupo Fecop (21-oct-2026), pero sus variables son con nombre
 * (WhatsappVariablesCobro): cualquier aliado de la cuenta compartida la usa con
 * su nombre, su mora mínima y su día de retiros (Configuración → Mora).
 *
 * Queda registrada para el aliado BryNex (id 1), que es donde CobrosController
 * busca las plantillas de la cuenta compartida. Con --estado solo consulta
 * cómo va la revisión de Meta y la actualiza.
 */
class WhatsappPlantillaAvisoRetiro extends Command
{
    protected $signature = 'whatsapp:plantilla-aviso-retiro
                            {--estado : Solo consultar en Meta el estado de revisión y actualizarlo}';

    protected $description = 'Crea (o consulta) en la cuenta compartida la plantilla de cobro «aviso_retiro_eps»';

    public const NOMBRE = 'aviso_retiro_eps';

    private const GRAPH = 'https://graph.facebook.com/v19.0';

    private const ALIADO_BRYNEX = 1;

    public const CUERPO = <<<'TXT'
🚨 *¡ATENCIÓN! AVISO IMPORTANTE DE {{1}}* 🚨

🌞 ¡Hola, *{{2}}*!

A la fecha no hemos visto reflejado tu pago de seguridad social y queremos saber si se ha presentado alguna novedad.

⚠️ ¡Faltan solo *{{3}}* para los retiros de la EPS de este mes! Te invitamos a realizar tu pago lo antes posible para evitar inconvenientes con tu afiliación y no quedarte sin tu servicio de salud.

💰 *¡Importante!* Si realizas tu pago fuera del tiempo establecido, recuerda incluir los *{{4}}* correspondientes a la mora por pago extemporáneo.

🙏 Si tienes alguna dificultad o situación especial, escríbenos para conocer tu caso y poder orientarte.

💙 No dejes tu salud para después. ¡Paga a tiempo y evita inconvenientes con tu afiliación!

Cordialmente,
*{{5}}* 🤝
TXT;

    /** El nombre del aliado va dos veces (arriba y en la firma): Meta no deja repetir una variable. */
    public const VARIABLES = [
        '1' => 'aliado.nombre',
        '2' => 'cliente.nombre',
        '3' => 'eps.tiempo_retiros',
        '4' => 'mora.minimo',
        '5' => 'aliado.nombre',
    ];

    public function handle(): int
    {
        $config = WhatsappConfig::where('aliado_id', self::ALIADO_BRYNEX)->first();
        if (! $config?->credencialesCompletas()) {
            $this->error('La cuenta compartida de BryNex no tiene credenciales completas.');

            return self::FAILURE;
        }
        $creds = $config->credencialesEfectivas();
        $http = new Client(['timeout' => 40, 'connect_timeout' => 10]);

        $enMeta = $this->buscarEnMeta($http, $creds);

        if ($this->option('estado') || $enMeta) {
            if (! $enMeta) {
                $this->warn('No existe en Meta todavía.');

                return self::SUCCESS;
            }
            $this->registrar($enMeta['id'], strtolower($enMeta['status']));
            $this->info("Estado en Meta: {$enMeta['status']}");

            return self::SUCCESS;
        }

        try {
            $resp = $http->post(self::GRAPH."/{$creds['waba_id']}/message_templates", [
                'headers' => ['Authorization' => "Bearer {$creds['access_token']}"],
                'json' => [
                    'name' => self::NOMBRE,
                    'language' => 'es_ES',
                    'category' => 'UTILITY',
                    'components' => [[
                        'type' => 'BODY',
                        'text' => self::CUERPO,
                        'example' => ['body_text' => [['GRUPO FECOP', 'Melisa', '3 días', '$2.000', 'GRUPO FECOP']]],
                    ]],
                ],
            ]);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $this->error('Meta rechazó la plantilla: '.($e->hasResponse() ? (string) $e->getResponse()->getBody() : $e->getMessage()));

            return self::FAILURE;
        }

        $data = json_decode((string) $resp->getBody(), true);
        $this->registrar($data['id'] ?? null, strtolower($data['status'] ?? 'pending'));
        $this->info('Creada: id '.($data['id'] ?? '?').' · estado '.($data['status'] ?? '?'));
        $this->line('Meta la revisa; consulta con --estado. Aprobada, sale en la lista de plantillas de Cobros.');

        return self::SUCCESS;
    }

    private function buscarEnMeta(Client $http, array $creds): ?array
    {
        $resp = $http->get(self::GRAPH."/{$creds['waba_id']}/message_templates", [
            'headers' => ['Authorization' => "Bearer {$creds['access_token']}"],
            'query' => ['name' => self::NOMBRE, 'fields' => 'id,name,status,language'],
        ]);

        foreach (json_decode((string) $resp->getBody(), true)['data'] ?? [] as $t) {
            if (($t['name'] ?? '') === self::NOMBRE) {
                return $t;
            }
        }

        return null;
    }

    private function registrar(?string $metaId, string $estado): void
    {
        WhatsappPlantilla::updateOrCreate(
            ['aliado_id' => self::ALIADO_BRYNEX, 'nombre' => self::NOMBRE],
            [
                'nombre_display' => 'Aviso retiro EPS (días, mora y aliado)',
                'categoria' => 'UTILITY',
                'idioma' => 'es_ES',
                'estado' => $estado,
                'meta_template_id' => $metaId,
                'creado_en_meta' => true,
                'header_tipo' => null,
                'cuerpo' => self::CUERPO,
                'variables_mapa' => self::VARIABLES,
            ]
        );
    }
}
