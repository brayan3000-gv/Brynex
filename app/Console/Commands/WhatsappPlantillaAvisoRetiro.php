<?php

namespace App\Console\Commands;

use App\Models\WhatsappConfig;
use App\Models\WhatsappPlantilla;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Crea en la cuenta de WhatsApp compartida de BryNex la plantilla de cobro
 * «aviso_retiro_eps_imagen»: el aviso de que faltan pocos días para los retiros de la
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

    protected $description = 'Crea (o consulta) en la cuenta compartida la plantilla de cobro «aviso_retiro_eps_imagen»';

    /**
     * La primera, «aviso_retiro_eps», se creó sin encabezado y salía sin la imagen de
     * cuentas (21-oct-2026). Se hizo esta nueva en vez de editarla; aquella quedó en Meta.
     */
    public const NOMBRE = 'aviso_retiro_eps_imagen';

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

        if ($this->option('estado') && ! $enMeta) {
            $this->warn('No existe en Meta todavía.');

            return self::SUCCESS;
        }

        if ($this->option('estado') || ($enMeta && self::tieneImagen($enMeta))) {
            $this->registrar($enMeta['id'], strtolower($enMeta['status']), self::tieneImagen($enMeta));
            $this->info("Estado en Meta: {$enMeta['status']}");

            return self::SUCCESS;
        }

        // Encabezado de imagen como las demás de cobro: al enviar se pone la imagen de
        // cuentas de cada aliado (WhatsappConfig::cobro_header_imagen). Meta pide una de
        // muestra subida por su API, no una URL.
        $handle = $this->subirImagenEjemplo($http, $creds);
        if (! $handle) {
            $this->error('No se pudo subir la imagen de muestra a Meta.');

            return self::FAILURE;
        }

        $componentes = [
            ['type' => 'HEADER', 'format' => 'IMAGE', 'example' => ['header_handle' => [$handle]]],
            [
                'type' => 'BODY',
                'text' => self::CUERPO,
                'example' => ['body_text' => [['GRUPO FECOP', 'Melisa', '3 días', '$2.000', 'GRUPO FECOP']]],
            ],
        ];

        try {
            // Si ya existe sin imagen se edita, y conserva el nombre. Meta la vuelve a revisar.
            $resp = $enMeta
                ? $http->post(self::GRAPH."/{$enMeta['id']}", [
                    'headers' => ['Authorization' => "Bearer {$creds['access_token']}"],
                    'json' => ['components' => $componentes],
                ])
                : $http->post(self::GRAPH."/{$creds['waba_id']}/message_templates", [
                    'headers' => ['Authorization' => "Bearer {$creds['access_token']}"],
                    'json' => [
                        'name' => self::NOMBRE,
                        'language' => 'es_ES',
                        'category' => 'UTILITY',
                        'components' => $componentes,
                    ],
                ]);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $this->error('Meta rechazó la plantilla: '.($e->hasResponse() ? (string) $e->getResponse()->getBody() : $e->getMessage()));

            return self::FAILURE;
        }

        $data = json_decode((string) $resp->getBody(), true);
        $id = $enMeta['id'] ?? ($data['id'] ?? null);
        $this->registrar($id, strtolower($data['status'] ?? 'pending'), true);
        $this->info(($enMeta ? 'Editada' : 'Creada').': id '.($id ?? '?').' · estado '.($data['status'] ?? 'PENDING'));
        $this->line('Meta la revisa; consulta con --estado. Aprobada, sale en la lista de plantillas de Cobros.');

        return self::SUCCESS;
    }

    private static function tieneImagen(array $enMeta): bool
    {
        foreach ($enMeta['components'] ?? [] as $c) {
            if (($c['type'] ?? '') === 'HEADER' && ($c['format'] ?? '') === 'IMAGE') {
                return true;
            }
        }

        return false;
    }

    private function buscarEnMeta(Client $http, array $creds): ?array
    {
        $resp = $http->get(self::GRAPH."/{$creds['waba_id']}/message_templates", [
            'headers' => ['Authorization' => "Bearer {$creds['access_token']}"],
            'query' => ['name' => self::NOMBRE, 'fields' => 'id,name,status,language,components'],
        ]);

        foreach (json_decode((string) $resp->getBody(), true)['data'] ?? [] as $t) {
            if (($t['name'] ?? '') === self::NOMBRE) {
                return $t;
            }
        }

        return null;
    }

    /**
     * Sube por la API de subida reanudable la imagen de cuentas de un aliado de la
     * cuenta compartida (o el logo de BryNex si ninguno tiene) y devuelve el handle.
     */
    private function subirImagenEjemplo(Client $http, array $creds): ?string
    {
        $ruta = WhatsappConfig::where('usa_cuenta_brynex', true)
            ->whereNotNull('cobro_header_imagen')
            ->pluck('cobro_header_imagen')
            ->map(fn ($p) => Storage::disk('public')->path($p))
            ->first(fn ($p) => is_file($p)) ?? public_path('img/logo-brynex.png');

        if (! is_file($ruta)) {
            return null;
        }

        $token = $creds['access_token'];
        $contenido = file_get_contents($ruta);

        try {
            $debug = json_decode((string) $http->get(self::GRAPH.'/debug_token', [
                'query' => ['input_token' => $token, 'access_token' => $token],
            ])->getBody(), true);
            $appId = $debug['data']['app_id'] ?? null;
            if (! $appId) {
                return null;
            }

            $sesion = json_decode((string) $http->post(self::GRAPH."/{$appId}/uploads", [
                'query' => [
                    'file_name' => basename($ruta),
                    'file_length' => strlen($contenido),
                    'file_type' => mime_content_type($ruta) ?: 'image/png',
                    'access_token' => $token,
                ],
            ])->getBody(), true);
            if (empty($sesion['id'])) {
                return null;
            }

            $subida = json_decode((string) $http->post(self::GRAPH.'/'.$sesion['id'], [
                'headers' => ['Authorization' => "OAuth {$token}", 'file_offset' => '0'],
                'body' => $contenido,
            ])->getBody(), true);

            return $subida['h'] ?? null;
        } catch (\Throwable $e) {
            $this->warn($e->getMessage());

            return null;
        }
    }

    private function registrar(?string $metaId, string $estado, bool $conImagen): void
    {
        WhatsappPlantilla::updateOrCreate(
            ['aliado_id' => self::ALIADO_BRYNEX, 'nombre' => self::NOMBRE],
            [
                'nombre_display' => 'Aviso retiro EPS con imagen (días, mora y aliado)',
                'categoria' => 'UTILITY',
                'idioma' => 'es_ES',
                'estado' => $estado,
                'meta_template_id' => $metaId,
                'creado_en_meta' => true,
                'header_tipo' => $conImagen ? 'IMAGE' : null,
                'cuerpo' => self::CUERPO,
                'variables_mapa' => self::VARIABLES,
            ]
        );
    }
}
