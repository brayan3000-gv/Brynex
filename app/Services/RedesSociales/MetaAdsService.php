<?php

namespace App\Services\RedesSociales;

use App\Models\PautaConfig;
use App\Models\Publicacion;
use App\Models\RedSocialConfig;
use App\Models\WhatsappConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Pauta pagada (Meta Marketing API): anuncio "Click to WhatsApp" — un botón nativo "Enviar
 * mensaje" que abre WhatsApp directo con el mensaje precargado (con el código de referencia
 * de la pieza), en vez de un link largo en el texto. Es una creatividad NUEVA (no reutiliza
 * el post orgánico como sí hacía la versión anterior) porque este formato lo exige así.
 * Verificado contra la cuenta real de Brygar (act_763050131388073, moneda COP): los
 * presupuestos van en COP enteros, SIN multiplicar por 100 (confirmado leyendo
 * min_daily_budget=3319 de la cuenta real).
 *
 * Diseño de seguridad (no negociable, no lo decide la IA):
 * - crearBorrador() SIEMPRE crea todo en status=PAUSED → $0 de riesgo, se puede probar libre.
 * - activar() es la ÚNICA función que mueve dinero real (pasa el AdSet a ACTIVE) — antes de
 *   llamarla SIEMPRE se revalida el tope mensual del aliado, nunca se confía en una
 *   validación hecha antes en la sesión.
 * - Nada aquí sube presupuestos por su cuenta; sugerirPresupuesto() solo CALCULA un número,
 *   quien decide lanzarlo o activarlo es siempre una acción explícita del usuario.
 */
class MetaAdsService
{
    private const BASE_URL = 'https://graph.facebook.com/v23.0';

    /**
     * Prueba mínima antes de juzgar una creatividad: lo que llegue primero.
     *
     * Con $15.000 se compran unas 800 impresiones en Colombia, y ahí ya se ve si la gente
     * escribe o no: #90 llevaba 5 conversaciones con ese gasto, #73 y #82 ninguna. Juzgar antes
     * es tirar una moneda; esperar más es pagar por saber lo que ya se sabía.
     */
    private const MIN_PRUEBA_COP = 15000;

    private const DIAS_PRUEBA = 3;

    /**
     * Cuántas veces más caro que la mejor del conjunto puede ser una pieza antes de apagarla.
     *
     * No basta con «tiene pocas conversaciones»: una pieza puede traer menos y costar igual. Lo
     * que no se sostiene es pagar el triple por el mismo lead.
     */
    private const VECES_PEOR_TOLERADO = 3;

    /**
     * Token con el que se habla con la API de anuncios.
     *
     * Meta exige que quien crea un anuncio haya aceptado la certificación de no
     * discriminación. El token de redes del aliado es de PÁGINA y lo generó un usuario del
     * sistema, que no puede aceptarla: no tiene sesión ni navegador. Certificar a los
     * usuarios humanos tampoco sirve, porque ante Meta el anuncio no lo crea ninguno de
     * ellos. Por eso la pauta puede llevar su propio token, de una persona ya certificada.
     *
     * Sin token propio se usa el de la página, que es como funcionaba antes.
     */
    private static function tokenAds(PautaConfig $config, RedSocialConfig $fb): string
    {
        return $config->access_token_ads ?: (string) $fb->access_token;
    }

    /**
     * Crea Campaña + Conjunto de anuncios (destino WhatsApp) + Creatividad con botón nativo
     * "Enviar mensaje" + Anuncio, todo en PAUSED (cero gasto). No activa nada.
     *
     * @return array{ok: bool, mensaje: string}
     */
    public static function crearBorrador(Publicacion $publicacion, PautaConfig $config, float $presupuestoDiarioCop): array
    {
        if (! $config->activo || ! $config->ad_account_id) {
            return ['ok' => false, 'mensaje' => 'La pauta pagada no está configurada para este aliado.'];
        }
        if ($presupuestoDiarioCop > PautaConfig::TOPE_DIARIO_COP) {
            return ['ok' => false, 'mensaje' => 'El tope diario es de $'.number_format(PautaConfig::TOPE_DIARIO_COP, 0, ',', '.').' COP.'];
        }
        if ($presupuestoDiarioCop > $config->disponibleEsteMes()) {
            return ['ok' => false, 'mensaje' => 'Ese presupuesto supera el tope mensual disponible ($'.number_format($config->disponibleEsteMes(), 0, ',', '.').' COP restantes este mes).'];
        }

        $fb = RedSocialConfig::paraAliado($publicacion->aliado_id, 'facebook');
        if (! $fb->credencialesCompletas()) {
            return ['ok' => false, 'mensaje' => 'Faltan credenciales de Facebook (ver Redes Sociales).'];
        }
        $waConfig = WhatsappConfig::where('aliado_id', $publicacion->aliado_id)->where('activo', true)->first();
        if (! $waConfig?->numero_telefono) {
            return ['ok' => false, 'mensaje' => 'No hay un número de WhatsApp del bot configurado para este aliado.'];
        }

        $token = self::tokenAds($config, $fb);
        $cuenta = 'act_'.ltrim($config->ad_account_id, 'act_');
        $pageId = $fb->identificador;
        $numeroWa = preg_replace('/\D/', '', $waConfig->numero_telefono);

        // 0. Media al catálogo de la cuenta. Si la pieza es un Reel, el anuncio tiene que ser
        // el VIDEO: antes se pautaba el póster, o sea un cuadro fijo justo del formato que
        // más alcance da.
        $media = self::subirMedia($publicacion, $cuenta, $token);
        if (! $media['ok']) {
            return ['ok' => false, 'mensaje' => $media['mensaje']];
        }
        $imageHash = $media['image_hash'];
        $videoId = $media['video_id'];

        // 1. Campaña
        $campana = Http::asForm()->post(self::BASE_URL."/{$cuenta}/campaigns", [
            'name' => "Pieza #{$publicacion->id} — {$publicacion->titulo}",
            'objective' => 'OUTCOME_ENGAGEMENT',
            'status' => 'PAUSED',
            'special_ad_categories' => json_encode([]),
            'is_adset_budget_sharing_enabled' => 'false',
            'access_token' => $token,
        ]);
        if (! $campana->successful()) {
            return ['ok' => false, 'mensaje' => 'Campaña: '.self::errorDeMeta($campana)];
        }
        $campanaId = $campana->json('id');

        // 2. Conjunto de anuncios: destino WhatsApp — el clic abre un chat, no una página.
        $adset = Http::asForm()->post(self::BASE_URL."/{$cuenta}/adsets", [
            'name' => "Pieza #{$publicacion->id} — conjunto",
            'campaign_id' => $campanaId,
            'destination_type' => 'WHATSAPP',
            'daily_budget' => (int) round($presupuestoDiarioCop),
            'billing_event' => 'IMPRESSIONS',
            'optimization_goal' => 'CONVERSATIONS',
            'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
            'promoted_object' => json_encode(['page_id' => $pageId, 'whatsapp_phone_number' => $numeroWa]),
            'targeting' => json_encode(self::segmentacion($config, $token)),
            'status' => 'PAUSED',
            'access_token' => $token,
        ]);
        if (! $adset->successful()) {
            self::borrar($campanaId, $token);

            return ['ok' => false, 'mensaje' => 'Conjunto de anuncios: '.self::errorDeMeta($adset)];
        }
        $adsetId = $adset->json('id');

        // 3. Creatividad: botón "Enviar mensaje" + mensaje precargado con el código de
        // referencia — mismo texto que usa el link orgánico, para atribuir igual.
        $creativa = Http::asForm()->post(self::BASE_URL."/{$cuenta}/adcreatives", [
            'name' => "Pieza #{$publicacion->id} — creatividad",
            'object_story_spec' => json_encode(
                ['page_id' => $pageId] + self::historia($publicacion, $imageHash, $videoId)
            ),
            'access_token' => $token,
        ]);
        if (! $creativa->successful()) {
            self::borrar($campanaId, $token);

            return ['ok' => false, 'mensaje' => 'Creatividad: '.self::errorDeMeta($creativa)];
        }
        $creativaId = $creativa->json('id');

        // 4. Anuncio
        $ad = Http::asForm()->post(self::BASE_URL."/{$cuenta}/ads", [
            'name' => "Pieza #{$publicacion->id} — anuncio",
            'adset_id' => $adsetId,
            'creative' => json_encode(['creative_id' => $creativaId]),
            'status' => 'PAUSED',
            'access_token' => $token,
        ]);
        if (! $ad->successful()) {
            self::borrar($campanaId, $token);

            return ['ok' => false, 'mensaje' => 'Anuncio: '.self::errorDeMeta($ad)];
        }

        $publicacion->update([
            'pauta_estado' => 'borrador',
            'pauta_presupuesto_diario_cop' => $presupuestoDiarioCop,
            'meta_campana_id' => $campanaId,
            'meta_adset_id' => $adsetId,
            'meta_ad_id' => $ad->json('id'),
        ]);

        return ['ok' => true, 'mensaje' => 'Pauta creada en pausa (botón nativo de WhatsApp) — $0 gastado hasta que la actives.'];
    }

    /**
     * Sube la imagen (y el video, si la pieza es un Reel) al catálogo de la cuenta.
     *
     * En una pieza de video la imagen es el póster: sirve de miniatura, no de anuncio.
     *
     * @return array{ok: bool, image_hash: ?string, video_id: ?string, mensaje: string}
     */
    private static function subirMedia(Publicacion $publicacion, string $cuenta, string $token): array
    {
        if (! $publicacion->imagen_path || ! Storage::disk('public')->exists($publicacion->imagen_path)) {
            return ['ok' => false, 'image_hash' => null, 'video_id' => null, 'mensaje' => 'La pieza no tiene imagen (ni póster, si es video) para el anuncio.'];
        }

        $subida = Http::asMultipart()->attach(
            'source', Storage::disk('public')->get($publicacion->imagen_path), basename($publicacion->imagen_path)
        )->post(self::BASE_URL."/{$cuenta}/adimages", ['access_token' => $token]);
        if (! $subida->successful()) {
            return ['ok' => false, 'image_hash' => null, 'video_id' => null, 'mensaje' => 'Imagen: '.self::errorDeMeta($subida)];
        }
        $imagenes = $subida->json('images') ?? [];
        $imageHash = data_get(reset($imagenes) ?: [], 'hash');
        if (! $imageHash) {
            return ['ok' => false, 'image_hash' => null, 'video_id' => null, 'mensaje' => 'Meta no devolvió el hash de la imagen subida.'];
        }

        $videoId = null;
        if (($publicacion->tipo_pieza ?? null) === 'video' && $publicacion->video_path) {
            // Preferir el video que YA está en la página: no hay que volver a subir 3 MB ni
            // esperar a que Meta lo procese otra vez. Además el token del aliado es de PÁGINA,
            // y Meta no deja subir a la biblioteca de la cuenta publicitaria con ese tipo de
            // token —falla con "An unknown error has occurred."— aunque tenga ads_management.
            // ...pero solo si el token con el que se crea el anuncio puede LEERLO. Con un token
            // de usuario para pauta, el video de la pagina puede quedar fuera de su alcance, y
            // entonces Meta acepta la creatividad y revienta al crear el anuncio con un
            // "Especifica el contenido multimedia" que no dice nada del permiso que falta.
            $videoId = self::videoIdDePagina($publicacion);
            if ($videoId && ! self::videoLegiblePor($videoId, $token)) {
                $videoId = null;
            }

            if (! $videoId) {
                $sube = self::subirVideo($publicacion, $cuenta, $token);
                if (! $sube['ok']) {
                    return ['ok' => false, 'image_hash' => null, 'video_id' => null, 'mensaje' => $sube['mensaje']];
                }
                $videoId = $sube['video_id'];
            }
        }

        return ['ok' => true, 'image_hash' => $imageHash, 'video_id' => $videoId, 'mensaje' => 'Media lista.'];
    }

    /**
     * ¿El token con el que se va a crear el anuncio alcanza a ver ese video, y está listo?
     *
     * Una sola consulta barata que evita el error opaco de más adelante. `video_status` tiene
     * que ser `ready`: Meta acepta la creatividad con un video a medio procesar y recién falla
     * al crear el anuncio.
     */
    private static function videoLegiblePor(string $videoId, string $token): bool
    {
        $r = Http::get(self::BASE_URL."/{$videoId}", [
            'fields' => 'id,status',
            'access_token' => $token,
        ]);

        return $r->successful() && data_get($r->json(), 'status.video_status') === 'ready';
    }

    /** Id del video que quedó en la página al publicar la pieza, si la publicación fue a Facebook. */
    private static function videoIdDePagina(Publicacion $publicacion): ?string
    {
        $id = data_get($publicacion->resultado_publicacion, 'facebook.id');

        return (data_get($publicacion->resultado_publicacion, 'facebook.ok') && $id) ? (string) $id : null;
    }

    /**
     * Conjunto de anuncios para colombianos en el EXTERIOR.
     *
     * Va aparte del permanente porque la segmentación vive en el conjunto: no se puede tener
     * una pieza apuntando a España y otra a Colombia dentro del mismo. Separarlos además deja
     * comparar los dos mercados en vez de un promedio que no dice de dónde vino qué.
     *
     * Se crea PAUSADO, igual que el otro: encender gasto sigue siendo un acto explícito.
     *
     * @return array{ok: bool, adset_id: ?string, mensaje: string}
     */
    public static function asegurarConjuntoExterior(PautaConfig $config, int $aliadoId): array
    {
        if (! $config->exterior_activo || ! $config->exterior_pais) {
            return ['ok' => false, 'adset_id' => null, 'mensaje' => 'El conjunto del exterior no está configurado.'];
        }
        if (! $config->meta_campana_permanente_id) {
            return ['ok' => false, 'adset_id' => null, 'mensaje' => 'Primero tiene que existir la campaña permanente.'];
        }

        $fb = RedSocialConfig::paraAliado($aliadoId, 'facebook');
        $waConfig = WhatsappConfig::where('aliado_id', $aliadoId)->where('activo', true)->first();
        if (! $fb->credencialesCompletas() || ! $waConfig?->numero_telefono) {
            return ['ok' => false, 'adset_id' => null, 'mensaje' => 'Faltan credenciales de Facebook o el número de WhatsApp.'];
        }

        $token = self::tokenAds($config, $fb);
        $cuenta = 'act_'.ltrim($config->ad_account_id, 'act_');
        $diario = (int) round($config->exterior_presupuesto_diario_cop ?: 5000);
        $nombreAnunciante = \App\Models\Aliado::find($aliadoId)?->nombre ?: 'BRYGAR';

        if ($config->meta_adset_exterior_id) {
            return ['ok' => true, 'adset_id' => $config->meta_adset_exterior_id, 'mensaje' => 'El conjunto del exterior ya existía.'];
        }

        $adset = Http::asForm()->post(self::BASE_URL."/{$cuenta}/adsets", [
            'name' => 'Permanente — colombianos en el exterior',
            'campaign_id' => $config->meta_campana_permanente_id,
            'destination_type' => 'WHATSAPP',
            'daily_budget' => $diario,
            'billing_event' => 'IMPRESSIONS',
            'optimization_goal' => 'CONVERSATIONS',
            'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
            'promoted_object' => json_encode([
                'page_id' => $fb->identificador,
                'whatsapp_phone_number' => preg_replace('/\D/', '', $waConfig->numero_telefono),
            ]),
            'targeting' => json_encode(self::segmentacionExterior($config)),
            'status' => 'PAUSED',
            // Obligatorio para entregar en la UNIÓN EUROPEA (ley de servicios digitales):
            // hay que declarar a quién beneficia el anuncio y quién lo paga. El conjunto de
            // Colombia no lo necesita, y sin esto Meta rechaza la creación con un mensaje que
            // no menciona la UE ("Indica la persona u organización que se promociona").
            'dsa_beneficiary' => $nombreAnunciante,
            'dsa_payor' => $nombreAnunciante,
            'access_token' => $token,
        ]);

        if (! $adset->successful()) {
            return ['ok' => false, 'adset_id' => null, 'mensaje' => 'Conjunto del exterior: '.self::errorDeMeta($adset)];
        }

        $config->update(['meta_adset_exterior_id' => $adset->json('id')]);

        return ['ok' => true, 'adset_id' => $adset->json('id'), 'mensaje' => 'Conjunto del exterior creado (en pausa).'];
    }

    /**
     * A un colombiano en España solo se le llega por PROXY de interés: Meta eliminó en 2022 la
     * segmentación por expatriados. "Selección de fútbol de Colombia" es el mejor sustituto
     * —identifica al colombiano de verdad mejor que el interés genérico "Colombia", que
     * incluye a cualquiera que haya viajado— pero sigue siendo un proxy: parte del gasto va a
     * caer en gente de allá aficionada al fútbol sudamericano.
     */
    private static function segmentacionExterior(PautaConfig $config): array
    {
        $targeting = [
            'geo_locations' => ['countries' => [$config->exterior_pais]],
            'age_min' => $config->edad_min ?: 25,
            'age_max' => $config->edad_max ?: 55,
            'targeting_automation' => ['advantage_audience' => 0],
        ];

        if ($config->exterior_interes_id) {
            $targeting['interests'] = [[
                'id' => $config->exterior_interes_id,
                'name' => $config->exterior_interes_nombre ?: '',
            ]];
        }

        return $targeting;
    }

    /**
     * `object_story_spec` de la creatividad: botón nativo de WhatsApp + mensaje precargado con
     * el código de referencia de la pieza, que es lo que permite atribuir la conversación.
     *
     * `video_data` y `link_data` son excluyentes: Meta rechaza el creativo si van los dos.
     */
    private static function historia(Publicacion $publicacion, string $imageHash, ?string $videoId): array
    {
        $bienvenida = [
            'type' => 'VISUAL_EDITOR',
            'version' => 2,
            'landing_screen_type' => 'welcome_message',
            'media_type' => 'text',
            'text_format' => [
                'customer_action_type' => 'autofill_message',
                'message' => [
                    'text' => '¡Hola! 👋 Gracias por escribirnos.',
                    // Sin el código: en la pauta el anuncio lo identifica el `referral` que manda Meta,
                    // así que el texto queda lo más corto posible para que le den enviar.
                    'autofill_message' => ['content' => $publicacion->mensajeWhatsappRastreado(false)],
                ],
            ],
        ];
        $llamado = [
            'type' => 'WHATSAPP_MESSAGE',
            'value' => ['app_destination' => 'WHATSAPP'],
        ];

        if ($videoId) {
            return ['video_data' => [
                'video_id' => $videoId,
                'message' => $publicacion->copy ?: $publicacion->titulo,
                'title' => $publicacion->titulo,
                'image_hash' => $imageHash,
                'call_to_action' => $llamado,
                'page_welcome_message' => $bienvenida,
            ]];
        }

        return ['link_data' => [
            'message' => $publicacion->copy ?: $publicacion->titulo,
            'name' => $publicacion->titulo,
            'image_hash' => $imageHash,
            'link' => 'https://api.whatsapp.com/send',
            'call_to_action' => $llamado,
            'page_welcome_message' => $bienvenida,
        ]];
    }

    /**
     * Sube el video de la pieza al catálogo de la cuenta y espera a que Meta lo procese.
     *
     * El creativo no se puede crear con un video a medio procesar: Meta acepta la subida al
     * instante pero devuelve error al referenciarlo hasta que el estado es `ready`. Un Reel de
     * 16 segundos tarda entre 10 y 40 segundos.
     *
     * @return array{ok: bool, video_id: ?string, mensaje: string}
     */
    private static function subirVideo(Publicacion $publicacion, string $cuenta, string $token): array
    {
        if (! Storage::disk('public')->exists($publicacion->video_path)) {
            return ['ok' => false, 'video_id' => null, 'mensaje' => 'No se encuentra el archivo de video de la pieza.'];
        }

        $subida = Http::timeout(180)->asMultipart()->attach(
            'source',
            Storage::disk('public')->get($publicacion->video_path),
            basename($publicacion->video_path)
        )->post(self::BASE_URL."/{$cuenta}/advideos", ['access_token' => $token]);

        if (! $subida->successful()) {
            return ['ok' => false, 'video_id' => null, 'mensaje' => 'Video: '.self::errorDeMeta($subida)];
        }
        $videoId = $subida->json('id');
        if (! $videoId) {
            return ['ok' => false, 'video_id' => null, 'mensaje' => 'Meta no devolvió el id del video subido.'];
        }

        // Hasta 2 minutos de espera. Si Meta se demora más, es mejor fallar y reintentar que
        // dejar una campaña a medio armar apuntando a un video que no existe todavía.
        for ($intento = 0; $intento < 24; $intento++) {
            sleep(5);
            $estado = Http::get(self::BASE_URL."/{$videoId}", [
                'fields' => 'status',
                'access_token' => $token,
            ]);
            $fase = $estado->json('status.video_status');
            if ($fase === 'ready') {
                return ['ok' => true, 'video_id' => $videoId, 'mensaje' => 'Video listo.'];
            }
            if ($fase === 'error') {
                return ['ok' => false, 'video_id' => null, 'mensaje' => 'Meta no pudo procesar el video de la pieza.'];
            }
        }

        return ['ok' => false, 'video_id' => null, 'mensaje' => 'Meta sigue procesando el video (más de 2 minutos). Reintenta en un momento.'];
    }

    /**
     * Segmentación del conjunto de anuncios.
     *
     * Antes estaba quemada en toda Colombia de 18 a 65: con 5.000 COP/día eso reparte el
     * alcance entre 50 millones de personas. Ahora sale de `pauta_config`, y las ciudades se
     * traducen a las claves internas de Meta —que no se pueden escribir a mano— la primera
     * vez y quedan cacheadas.
     *
     * Sin ciudades configuradas se mantiene el país entero: es el comportamiento anterior y
     * nunca deja un conjunto sin geografía, que Meta rechazaría.
     */
    private static function segmentacion(PautaConfig $config, string $token): array
    {
        $base = [
            'age_min' => $config->edad_min ?: 25,
            'age_max' => $config->edad_max ?: 55,
            // Meta exige declararlo explícitamente o rechaza la creación del conjunto.
            // En 0 respeta la segmentación tal cual: con presupuesto chico y una etapa de
            // aprendizaje, que Meta amplíe el público por su cuenta haría imposible saber
            // qué funcionó. Se puede subir a 1 cuando ya haya un ganador claro.
            'targeting_automation' => ['advantage_audience' => 0],
        ];

        $ciudades = $config->ciudades ?: [];
        if (empty($ciudades)) {
            return $base + ['geo_locations' => ['countries' => ['CO']]];
        }

        $claves = $config->ciudades_claves ?: [];
        $faltan = array_diff($ciudades, array_keys($claves));

        foreach ($faltan as $ciudad) {
            $r = Http::get(self::BASE_URL.'/search', [
                'type' => 'adgeolocation',
                'location_types' => json_encode(['city']),
                'q' => $ciudad,
                'country_code' => 'CO',
                'limit' => 10,
                'access_token' => $token,
            ]);

            // Meta ignora el filtro de tipo cuando no encuentra la ciudad y devuelve BARRIOS:
            // "Palmira" trae "Ciudadela Palmira" de primero. Quedarse con data.0 significaba
            // pautarle a un barrio creyendo que era el municipio. Se exige tipo `city` y que
            // el nombre coincida de verdad.
            $clave = null;
            foreach ((array) $r->json('data') as $d) {
                if (($d['type'] ?? null) !== 'city') {
                    continue;
                }
                if (self::mismoNombre($d['name'] ?? '', $ciudad)) {
                    $clave = $d['key'] ?? null;
                    break;
                }
            }

            if ($clave) {
                $claves[$ciudad] = $clave;
            } else {
                Log::warning("Pauta: Meta no tiene la ciudad '{$ciudad}' como municipio; se excluye de la segmentación.");
            }
        }

        if ($claves !== ($config->ciudades_claves ?: [])) {
            $config->update(['ciudades_claves' => $claves]);
        }

        // Si ninguna ciudad se pudo resolver, país entero antes que un conjunto inválido.
        $resueltas = array_values(array_intersect_key($claves, array_flip($ciudades)));
        if (empty($resueltas)) {
            return $base + ['geo_locations' => ['countries' => ['CO']]];
        }

        return $base + [
            'geo_locations' => [
                'cities' => array_map(fn ($k) => ['key' => $k, 'radius' => 25, 'distance_unit' => 'kilometer'], $resueltas),
            ],
        ];
    }

    /** Compara nombres de ciudad ignorando tildes y mayúsculas: "Jamundi" debe casar con "Jamundí". */
    private static function mismoNombre(string $a, string $b): bool
    {
        $normalizar = fn (string $s) => mb_strtolower(trim(strtr(
            $s,
            ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'ñ' => 'n', 'Ñ' => 'N']
        )));

        return $normalizar($a) === $normalizar($b);
    }

    /**
     * Crea (una sola vez) el conjunto permanente del aliado y devuelve su id.
     *
     * Todo se crea en PAUSED: encender el gasto sigue siendo un acto explícito. De ahí en
     * adelante las piezas entran como anuncios dentro de este mismo conjunto, que ya viene
     * con historial — que es justamente lo que evita reiniciar el aprendizaje de Meta.
     *
     * Si el conjunto ya existe, sincroniza el presupuesto diario por si cambió el semanal.
     *
     * @return array{ok: bool, adset_id: ?string, mensaje: string}
     */
    public static function asegurarConjuntoPermanente(PautaConfig $config, int $aliadoId): array
    {
        if (! $config->activo || ! $config->ad_account_id) {
            return ['ok' => false, 'adset_id' => null, 'mensaje' => 'La pauta pagada no está configurada para este aliado.'];
        }

        $fb = RedSocialConfig::paraAliado($aliadoId, 'facebook');
        if (! $fb->credencialesCompletas()) {
            return ['ok' => false, 'adset_id' => null, 'mensaje' => 'Faltan credenciales de Facebook (ver Redes Sociales).'];
        }
        $waConfig = WhatsappConfig::where('aliado_id', $aliadoId)->where('activo', true)->first();
        if (! $waConfig?->numero_telefono) {
            return ['ok' => false, 'adset_id' => null, 'mensaje' => 'No hay un número de WhatsApp del bot configurado.'];
        }

        $token = self::tokenAds($config, $fb);
        $cuenta = 'act_'.ltrim($config->ad_account_id, 'act_');
        $diario = (int) round($config->presupuestoDiarioCop());
        $numeroWa = preg_replace('/\D/', '', $waConfig->numero_telefono);

        // Ya existe: solo alinear el presupuesto. Cambiarlo reinicia parcialmente el
        // aprendizaje, así que se toca únicamente cuando de verdad difiere.
        if ($config->meta_adset_permanente_id) {
            $actual = Http::get(self::BASE_URL."/{$config->meta_adset_permanente_id}", [
                'fields' => 'daily_budget,status',
                'access_token' => $token,
            ]);
            if ($actual->successful() && (int) $actual->json('daily_budget') !== $diario) {
                Http::asForm()->post(self::BASE_URL."/{$config->meta_adset_permanente_id}", [
                    'daily_budget' => $diario,
                    'access_token' => $token,
                ]);
            }

            return ['ok' => true, 'adset_id' => $config->meta_adset_permanente_id, 'mensaje' => 'Conjunto permanente ya existía.'];
        }

        $campana = Http::asForm()->post(self::BASE_URL."/{$cuenta}/campaigns", [
            'name' => 'BRYGAR — conjunto permanente (WhatsApp)',
            'objective' => 'OUTCOME_ENGAGEMENT',
            'status' => 'PAUSED',
            'special_ad_categories' => json_encode([]),
            'is_adset_budget_sharing_enabled' => 'false',
            'access_token' => $token,
        ]);
        if (! $campana->successful()) {
            return ['ok' => false, 'adset_id' => null, 'mensaje' => 'Campaña: '.self::errorDeMeta($campana)];
        }
        $campanaId = $campana->json('id');

        $adset = Http::asForm()->post(self::BASE_URL."/{$cuenta}/adsets", [
            'name' => 'Permanente — Cali y Valle',
            'campaign_id' => $campanaId,
            'destination_type' => 'WHATSAPP',
            'daily_budget' => $diario,
            'billing_event' => 'IMPRESSIONS',
            'optimization_goal' => 'CONVERSATIONS',
            'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
            'promoted_object' => json_encode(['page_id' => $fb->identificador, 'whatsapp_phone_number' => $numeroWa]),
            'targeting' => json_encode(self::segmentacion($config, $token)),
            'status' => 'PAUSED',
            'access_token' => $token,
        ]);
        if (! $adset->successful()) {
            self::borrar($campanaId, $token);

            return ['ok' => false, 'adset_id' => null, 'mensaje' => 'Conjunto: '.self::errorDeMeta($adset)];
        }

        $config->update([
            'meta_campana_permanente_id' => $campanaId,
            'meta_adset_permanente_id' => $adset->json('id'),
        ]);

        return ['ok' => true, 'adset_id' => $adset->json('id'), 'mensaje' => 'Conjunto permanente creado en pausa — $0 gastado hasta que lo actives.'];
    }

    /**
     * Conjunto propio para las piezas que reclutan ASESORES.
     *
     * No comparten conjunto con las piezas de clientes a propósito. Son dos públicos que no
     * se parecen —un independiente que quiere afiliarse y un asesor con cartera propia— y
     * Meta reparte el presupuesto según su propia señal, que no ve las conversaciones de
     * WhatsApp: el 6-sep-2026 le había dado el 85% del dinero a la pieza que costaba $12.595
     * por conversación mientras la de $913 se quedaba con las sobras.
     *
     * Separado, además, se puede saber cuánto cuesta reclutar un asesor.
     *
     * Nace en PAUSA como el permanente: encender el gasto es siempre un acto manual.
     */
    public static function asegurarConjuntoAsesores(PautaConfig $config, int $aliadoId): array
    {
        if (! $config->activo || ! $config->ad_account_id) {
            return ['ok' => false, 'adset_id' => null, 'mensaje' => 'La pauta pagada no está configurada para este aliado.'];
        }

        $fb = RedSocialConfig::paraAliado($aliadoId, 'facebook');
        if (! $fb->credencialesCompletas()) {
            return ['ok' => false, 'adset_id' => null, 'mensaje' => 'Faltan credenciales de Facebook (ver Redes Sociales).'];
        }
        $waConfig = WhatsappConfig::where('aliado_id', $aliadoId)->where('activo', true)->first();
        if (! $waConfig?->numero_telefono) {
            return ['ok' => false, 'adset_id' => null, 'mensaje' => 'No hay un número de WhatsApp del bot configurado.'];
        }

        $token = self::tokenAds($config, $fb);
        $cuenta = 'act_'.ltrim($config->ad_account_id, 'act_');
        $diario = (int) round((float) ($config->asesores_presupuesto_diario_cop ?: $config->presupuestoDiarioCop()));
        $numeroWa = preg_replace('/\D/', '', $waConfig->numero_telefono);

        if ($config->meta_adset_asesores_id) {
            $actual = Http::get(self::BASE_URL."/{$config->meta_adset_asesores_id}", [
                'fields' => 'daily_budget,status',
                'access_token' => $token,
            ]);
            // Cambiar el presupuesto reinicia parte del aprendizaje: solo si de verdad difiere.
            if ($actual->successful() && (int) $actual->json('daily_budget') !== $diario) {
                Http::asForm()->post(self::BASE_URL."/{$config->meta_adset_asesores_id}", [
                    'daily_budget' => $diario,
                    'access_token' => $token,
                ]);
            }

            return ['ok' => true, 'adset_id' => $config->meta_adset_asesores_id, 'mensaje' => 'Conjunto de asesores ya existía.'];
        }

        $campana = Http::asForm()->post(self::BASE_URL."/{$cuenta}/campaigns", [
            'name' => 'BRYGAR — asesores (WhatsApp)',
            'objective' => 'OUTCOME_ENGAGEMENT',
            'status' => 'PAUSED',
            'special_ad_categories' => json_encode([]),
            'is_adset_budget_sharing_enabled' => 'false',
            'access_token' => $token,
        ]);
        if (! $campana->successful()) {
            return ['ok' => false, 'adset_id' => null, 'mensaje' => 'Campaña: '.self::errorDeMeta($campana)];
        }
        $campanaId = $campana->json('id');

        $adset = Http::asForm()->post(self::BASE_URL."/{$cuenta}/adsets", [
            'name' => 'Asesores — Colombia',
            'campaign_id' => $campanaId,
            'destination_type' => 'WHATSAPP',
            'daily_budget' => $diario,
            'billing_event' => 'IMPRESSIONS',
            'optimization_goal' => 'CONVERSATIONS',
            'bid_strategy' => 'LOWEST_COST_WITHOUT_CAP',
            'promoted_object' => json_encode(['page_id' => $fb->identificador, 'whatsapp_phone_number' => $numeroWa]),
            'targeting' => json_encode(self::segmentacion($config, $token)),
            'status' => 'PAUSED',
            'access_token' => $token,
        ]);
        if (! $adset->successful()) {
            self::borrar($campanaId, $token);

            return ['ok' => false, 'adset_id' => null, 'mensaje' => 'Conjunto: '.self::errorDeMeta($adset)];
        }

        $config->update([
            'meta_campana_asesores_id' => $campanaId,
            'meta_adset_asesores_id' => $adset->json('id'),
        ]);

        return ['ok' => true, 'adset_id' => $adset->json('id'), 'mensaje' => 'Conjunto de asesores creado en pausa — $0 gastado hasta que lo actives.'];
    }

    /**
     * Mete una pieza como anuncio nuevo dentro del conjunto permanente.
     *
     * El anuncio entra ACTIVO si el conjunto ya lo está: el retador tiene que competir de
     * verdad contra las creatividades que ya viven ahí. El gasto no sube por esto — el
     * presupuesto es del conjunto y se reparte entre sus anuncios.
     *
     * @return array{ok: bool, mensaje: string}
     */
    public static function agregarPieza(Publicacion $publicacion): array
    {
        $config = PautaConfig::paraAliado($publicacion->aliado_id);

        // Las piezas de asesores van a su propio conjunto: ver asegurarConjuntoAsesores.
        $esDeAsesores = \App\Services\Ia\AsistenteIaService::esPiezaDeAsesores($publicacion);

        $conjunto = $esDeAsesores
            ? self::asegurarConjuntoAsesores($config, $publicacion->aliado_id)
            : self::asegurarConjuntoPermanente($config, $publicacion->aliado_id);
        if (! $conjunto['ok']) {
            return ['ok' => false, 'mensaje' => $conjunto['mensaje']];
        }
        if ($publicacion->meta_ad_id) {
            return ['ok' => false, 'mensaje' => 'Esta pieza ya está en el conjunto permanente.'];
        }

        // El piloto genera una pieza DIARIA, pero el presupuesto semanal es uno solo. Si
        // entraran las siete, 50.000 se partirían en siete y ninguna juntaría datos para
        // saber si sirve. Solo pasan las primeras del cupo semanal; el resto se queda en
        // orgánico, que no cuesta nada.
        $cupo = max(1, (int) ($config->piezas_semana_max ?: 3));
        $estaSemana = Publicacion::where('aliado_id', $publicacion->aliado_id)
            ->where('meta_adset_id', $conjunto['adset_id'])
            ->where('updated_at', '>=', now()->subDays(7))
            ->whereNotNull('meta_ad_id')
            ->count();
        if ($estaSemana >= $cupo) {
            return ['ok' => false, 'mensaje' => "Cupo semanal lleno: ya hay {$estaSemana} pieza(s) pautada(s) de {$cupo}. Esta se queda solo en orgánico."];
        }

        $fb = RedSocialConfig::paraAliado($publicacion->aliado_id, 'facebook');
        $token = self::tokenAds($config, $fb);
        $cuenta = 'act_'.ltrim($config->ad_account_id, 'act_');

        $media = self::subirMedia($publicacion, $cuenta, $token);
        if (! $media['ok']) {
            return ['ok' => false, 'mensaje' => $media['mensaje']];
        }

        $creativa = Http::asForm()->post(self::BASE_URL."/{$cuenta}/adcreatives", [
            'name' => "Pieza #{$publicacion->id} — creatividad",
            'object_story_spec' => json_encode(
                ['page_id' => $fb->identificador] + self::historia($publicacion, $media['image_hash'], $media['video_id'])
            ),
            'access_token' => $token,
        ]);
        if (! $creativa->successful()) {
            return ['ok' => false, 'mensaje' => 'Creatividad: '.self::errorDeMeta($creativa)];
        }

        // El anuncio sigue el estado del conjunto: si la pauta está encendida, el retador
        // entra compitiendo; si está en pausa, entra en pausa.
        $estadoConjunto = Http::get(self::BASE_URL."/{$conjunto['adset_id']}", [
            'fields' => 'status',
            'access_token' => $token,
        ])->json('status');

        $ad = Http::asForm()->post(self::BASE_URL."/{$cuenta}/ads", [
            'name' => "Pieza #{$publicacion->id} — anuncio",
            'adset_id' => $conjunto['adset_id'],
            'creative' => json_encode(['creative_id' => $creativa->json('id')]),
            'status' => $estadoConjunto === 'ACTIVE' ? 'ACTIVE' : 'PAUSED',
            'access_token' => $token,
        ]);
        if (! $ad->successful()) {
            return ['ok' => false, 'mensaje' => 'Anuncio: '.self::errorDeMeta($ad)];
        }

        // Si la pieza habla de PENSIÓN, entra también al conjunto del exterior: es el único
        // producto que le sirve igual a alguien en Cali que a un colombiano en España, porque
        // las semanas se siguen cotizando viva donde viva. Se reutiliza la MISMA creatividad
        // —no se vuelve a subir el video— y el anuncio queda con su propio id en ese conjunto.
        $extra = '';
        if (self::esDePension($publicacion) && $config->exterior_activo) {
            $ext = self::asegurarConjuntoExterior($config, $publicacion->aliado_id);
            if ($ext['ok']) {
                $estadoExt = Http::get(self::BASE_URL."/{$ext['adset_id']}", [
                    'fields' => 'status',
                    'access_token' => $token,
                ])->json('status');

                $adExt = Http::asForm()->post(self::BASE_URL."/{$cuenta}/ads", [
                    'name' => "Pieza #{$publicacion->id} — anuncio exterior",
                    'adset_id' => $ext['adset_id'],
                    'creative' => json_encode(['creative_id' => $creativa->json('id')]),
                    'status' => $estadoExt === 'ACTIVE' ? 'ACTIVE' : 'PAUSED',
                    'access_token' => $token,
                ]);

                $extra = $adExt->successful()
                    ? ' También entró al conjunto del exterior.'
                    : ' No entró al del exterior: '.self::errorDeMeta($adExt);
            } else {
                $extra = ' No entró al del exterior: '.$ext['mensaje'];
            }
        }

        // Si la pieza ya tenía anuncio, se guarda antes de pisarlo: su gasto sigue contando
        // para la pieza aunque el anuncio quede pausado en otro conjunto.
        $publicacion->archivarAnuncio($publicacion->meta_ad_id);

        $publicacion->update([
            'pauta_estado' => $estadoConjunto === 'ACTIVE' ? 'activa' : 'borrador',
            'pauta_presupuesto_diario_cop' => $config->presupuestoDiarioCop(),
            // La campaña es la del conjunto al que entró: las piezas de asesores viven en la
            // suya, y escribir siempre la permanente dejaba el dato cruzado.
            'meta_campana_id' => $esDeAsesores
                ? $config->meta_campana_asesores_id
                : $config->meta_campana_permanente_id,
            'meta_adset_id' => $conjunto['adset_id'],
            'meta_ad_id' => $ad->json('id'),
            'pauta_activada_at' => $estadoConjunto === 'ACTIVE' ? ($publicacion->pauta_activada_at ?: now()) : $publicacion->pauta_activada_at,
        ]);

        return ['ok' => true, 'mensaje' => "Pieza #{$publicacion->id} agregada al conjunto "
            .($esDeAsesores ? 'de asesores.' : 'permanente.').$extra];
    }

    /**
     * ¿La pieza habla de pensión?
     *
     * Se mira el tema y el título, no una marca aparte: el tema lo escribe la IA a partir del
     * catálogo y es lo que de verdad describe la pieza. "AFP" entra porque es como se nombra
     * el fondo en los planes ("Solo AFP").
     */
    private static function esDePension(Publicacion $publicacion): bool
    {
        $texto = mb_strtolower(($publicacion->tema ?? '').' '.($publicacion->titulo ?? ''), 'UTF-8');

        return str_contains($texto, 'pensi') || str_contains($texto, 'afp') || str_contains($texto, 'semanas');
    }

    /**
     * Conversaciones de WhatsApp que pasaron del saludo, por pieza.
     *
     * El anuncio lleva el mensaje escrito de antemano («Hola, quiero información»), así que la
     * primera entrada llega sola: contar conversaciones a secas premia a quien trae curiosos.
     * #61 gastó $56.412 para 7 conversaciones y casi ninguna respondió nada más. Dos mensajes
     * del cliente ya significan que leyó y contestó.
     *
     * @param  iterable<int>  $piezaIds
     * @return \Illuminate\Support\Collection<int, int> pieza_id => conversaciones reales
     */
    public static function conversacionesReales(iterable $piezaIds, ?\Carbon\Carbon $desde = null): \Illuminate\Support\Collection
    {
        $ids = collect($piezaIds)->filter()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        $conversaciones = \App\Models\WhatsappConversacion::whereIn('origen_publicacion_id', $ids)
            ->when($desde, fn ($q) => $q->where('created_at', '>=', $desde))
            ->get(['id', 'origen_publicacion_id']);

        if ($conversaciones->isEmpty()) {
            return collect();
        }

        $entrantesPorConversacion = \App\Models\WhatsappMensaje::whereIn('conversacion_id', $conversaciones->pluck('id'))
            ->where('direccion', 'entrante')
            ->selectRaw('conversacion_id, COUNT(*) as total')
            ->groupBy('conversacion_id')
            ->pluck('total', 'conversacion_id');

        return $conversaciones
            ->filter(fn ($c) => (int) ($entrantesPorConversacion[$c->id] ?? 0) >= 2)
            ->groupBy('origen_publicacion_id')
            ->map(fn ($grupo) => $grupo->count());
    }

    /**
     * Gasto y entrega de cada anuncio en una ventana de fechas, leído de Meta.
     *
     * `pauta_gasto_total_cop` guarda el acumulado de toda la vida del anuncio, que no sirve para
     * un corte semanal: una pieza de agosto arrastra su historia y parece peor de lo que fue
     * esta semana.
     *
     * @return array<string, array{gasto: float, impresiones: int, clics: int}>
     */
    public static function entregaPorAnuncio(PautaConfig $config, string $desde, string $hasta): array
    {
        if (! $config->ad_account_id || ! $config->access_token_ads) {
            return [];
        }

        $cuenta = 'act_'.ltrim($config->ad_account_id, 'act_');
        $resp = Http::timeout(60)->get(self::BASE_URL."/{$cuenta}/insights", [
            'access_token' => $config->access_token_ads,
            'level' => 'ad',
            'limit' => 200,
            'time_range' => json_encode(['since' => $desde, 'until' => $hasta]),
            'fields' => 'ad_id,spend,impressions,clicks',
        ]);

        $porAnuncio = [];
        foreach (($resp->json('data') ?? []) as $fila) {
            $porAnuncio[$fila['ad_id']] = [
                'gasto' => (float) ($fila['spend'] ?? 0),
                'impresiones' => (int) ($fila['impressions'] ?? 0),
                'clics' => (int) ($fila['clicks'] ?? 0),
            ];
        }

        return $porAnuncio;
    }

    /** Conjuntos de anuncios del aliado, con nombre legible. @return array<string, string> */
    public static function conjuntos(PautaConfig $config): array
    {
        return array_filter([
            'clientes' => $config->meta_adset_permanente_id,
            'asesores' => $config->meta_adset_asesores_id,
            'exterior' => $config->meta_adset_exterior_id,
        ]);
    }

    /** Apaga un anuncio suelto, sin tocar el conjunto. */
    public static function pausarAnuncio(Publicacion $publicacion): bool
    {
        if (! $publicacion->meta_ad_id) {
            return false;
        }

        $config = PautaConfig::paraAliado($publicacion->aliado_id);
        $fb = RedSocialConfig::paraAliado($publicacion->aliado_id, 'facebook');
        $resp = Http::asForm()->post(self::BASE_URL."/{$publicacion->meta_ad_id}", [
            'status' => 'PAUSED',
            'access_token' => self::tokenAds($config, $fb),
        ]);

        if (! $resp->successful() || $resp->json('error')) {
            return false;
        }

        $publicacion->update(['pauta_estado' => 'pausada']);

        return true;
    }

    /**
     * Deja activas solo las mejores creatividades de cada conjunto y pausa el resto.
     *
     * Se ordena por conversaciones que pasaron del saludo, no por likes ni por conversaciones a
     * secas: un like no paga una afiliación y un «Hola, quiero información» tampoco. Las piezas
     * que todavía no tuvieron su prueba mínima quedan protegidas — juzgar un anuncio sin datos
     * es tirar una moneda, no medir.
     *
     * Recorre TODOS los conjuntos, no solo el permanente. Mientras miró únicamente el de
     * clientes, el de asesores quedó sin tope: #90, #91, #101, #103, #104 y #105 activos a la
     * vez sobre $10.000/día, y Meta le dio el 99% del dinero a las dos con historia. Las tres
     * nuevas recibieron $694 entre las tres en veinte días: nunca se supo si servían.
     *
     * @return array{ok: bool, mensaje: string, pausadas: int}
     */
    public static function rotarCreatividades(PautaConfig $config, int $aliadoId): array
    {
        $conjuntos = self::conjuntos($config);
        if (empty($conjuntos)) {
            return ['ok' => false, 'mensaje' => 'Todavía no hay conjuntos de anuncios.', 'pausadas' => 0];
        }

        $maximo = max(1, (int) ($config->creatividades_max ?: 3));
        $pausadas = 0;
        $notas = [];

        foreach ($conjuntos as $etiqueta => $adsetId) {
            $activas = Publicacion::where('aliado_id', $aliadoId)
                ->where('meta_adset_id', $adsetId)
                ->where('pauta_estado', 'activa')
                ->whereNotNull('meta_ad_id')
                ->get();

            if ($activas->count() <= $maximo) {
                $notas[] = "{$etiqueta}: {$activas->count()} de {$maximo}";

                continue;
            }

            $reales = self::conversacionesReales($activas->pluck('id'));

            $ordenadas = $activas->sortByDesc(function ($p) use ($reales) {
                // Las protegidas van primero para que nunca caigan en la zona de pausa; entre
                // iguales manda la fecha, así el retador más nuevo desplaza al más viejo.
                return [
                    self::enPrueba($p) ? 1 : 0,
                    (int) ($reales[$p->id] ?? 0),
                    $p->pauta_activada_at?->timestamp ?? 0,
                ];
            })->values();

            $apagadas = 0;
            foreach ($ordenadas->slice($maximo) as $pieza) {
                if (self::pausarAnuncio($pieza)) {
                    $apagadas++;
                    $pausadas++;
                }
            }
            $notas[] = "{$etiqueta}: {$apagadas} pausada(s), se dejan {$maximo}";
        }

        return ['ok' => true, 'mensaje' => 'Rotación — '.implode(' | ', $notas), 'pausadas' => $pausadas];
    }

    /**
     * ¿La pieza todavía está en su prueba mínima?
     *
     * Protegida mientras no haya gastado lo suficiente NI llevado los días suficientes: basta
     * con que se cumpla una de las dos para poder juzgarla.
     */
    public static function enPrueba(Publicacion $publicacion): bool
    {
        $gastoSuficiente = (float) $publicacion->pauta_gasto_total_cop >= self::MIN_PRUEBA_COP;
        $diasSuficientes = $publicacion->pauta_activada_at
            && $publicacion->pauta_activada_at->lte(now()->subDays(self::DIAS_PRUEBA));

        return ! $gastoSuficiente && ! $diasSuficientes;
    }

    /** Lo que se le exige a una creatividad antes de poder apagarla. */
    public static function reglaDePrueba(): array
    {
        return ['cop' => self::MIN_PRUEBA_COP, 'dias' => self::DIAS_PRUEBA, 'veces' => self::VECES_PEOR_TOLERADO];
    }

    /**
     * ÚNICA función que mueve dinero real: pasa el conjunto de anuncios a ACTIVE.
     * Revalida el tope mensual justo antes, sin confiar en validaciones previas.
     *
     * @param  ?int  $diasDuracion  Si se pasa, además le pone `end_time` nativo de Meta al AdSet
     *                              (Meta lo pausa solo, sin depender de nuestro cron `marketing:pauta-sync` ni de que
     *                              alguien se acuerde de pausarlo a mano) — ideal para pruebas cortas con tope de días.
     */
    public static function activar(Publicacion $publicacion, ?int $diasDuracion = null): array
    {
        if (! $publicacion->meta_adset_id) {
            return ['ok' => false, 'mensaje' => 'Esta pieza no tiene una pauta creada todavía.'];
        }

        $config = PautaConfig::paraAliado($publicacion->aliado_id);
        if ((float) $publicacion->pauta_presupuesto_diario_cop > $config->disponibleEsteMes()) {
            return ['ok' => false, 'mensaje' => 'No se activó: superaría el tope mensual disponible ($'.number_format($config->disponibleEsteMes(), 0, ',', '.').' COP restantes).'];
        }

        $fb = RedSocialConfig::paraAliado($publicacion->aliado_id, 'facebook');
        $payload = [
            'status' => 'ACTIVE',
            'access_token' => self::tokenAds($config, $fb),
        ];
        if ($diasDuracion) {
            $payload['end_time'] = now()->addDays($diasDuracion)->toIso8601String();
        }

        $resp = Http::asForm()->post(self::BASE_URL."/{$publicacion->meta_adset_id}", $payload);

        if (! $resp->successful()) {
            return ['ok' => false, 'mensaje' => self::errorDeMeta($resp)];
        }

        $publicacion->update(['pauta_estado' => 'activa', 'pauta_activada_at' => $publicacion->pauta_activada_at ?: now()]);

        return ['ok' => true, 'mensaje' => 'Pauta activa — gastando presupuesto real desde ahora.'];
    }

    /** Pausa el gasto en cualquier momento (siempre seguro, no hay confirmación especial que pedir). */
    public static function pausar(Publicacion $publicacion): array
    {
        if (! $publicacion->meta_adset_id) {
            return ['ok' => false, 'mensaje' => 'Esta pieza no tiene una pauta creada.'];
        }

        $fb = RedSocialConfig::paraAliado($publicacion->aliado_id, 'facebook');
        $resp = Http::asForm()->post(self::BASE_URL."/{$publicacion->meta_adset_id}", [
            'status' => 'PAUSED',
            'access_token' => self::tokenAds(PautaConfig::paraAliado($publicacion->aliado_id), $fb),
        ]);

        if (! $resp->successful()) {
            return ['ok' => false, 'mensaje' => self::errorDeMeta($resp)];
        }

        $publicacion->update(['pauta_estado' => 'pausada']);

        return ['ok' => true, 'mensaje' => 'Pauta pausada.'];
    }

    /** Lee el gasto real acumulado de Meta y lo guarda — llamado por el comando diario de sincronización. */
    public static function sincronizarGasto(Publicacion $publicacion): void
    {
        // Del ANUNCIO, no del conjunto: el conjunto es uno solo y lo comparten todas las
        // piezas, así que consultarlo le escribía a cada una el gasto de todas juntas. Con
        // tres piezas activas el sistema creía que llevaba $212.355 cuando iban $71.000 --- y
        // como gastadoEsteMes() suma esta columna, el tope mensual habría pausado la pauta a
        // un tercio del presupuesto real.
        if (! $publicacion->meta_ad_id) {
            return;
        }

        $fb = RedSocialConfig::paraAliado($publicacion->aliado_id, 'facebook');
        $token = self::tokenAds(PautaConfig::paraAliado($publicacion->aliado_id), $fb);

        // El gasto es el de la PIEZA, no el de su anuncio de turno. Cuando una pieza se muda
        // de conjunto hay que recrear el anuncio —Meta no deja moverlo— y el nuevo empieza en
        // cero: sin sumar los anteriores, la mudanza le borraba a la pieza todo lo gastado.
        $ads = array_merge([$publicacion->meta_ad_id], $publicacion->meta_ads_previos ?? []);

        $gasto = 0.0;
        $alguno = false;
        foreach (array_unique($ads) as $adId) {
            $resp = Http::get(self::BASE_URL."/{$adId}/insights", [
                'fields' => 'spend',
                'date_preset' => 'maximum',
                'access_token' => $token,
            ]);

            if (! $resp->successful()) {
                // Un anuncio borrado en Meta ya no responde. Se ignora ese y se sigue: es
                // preferible un total al que le falte un anuncio muerto que no actualizar nada.
                continue;
            }

            $alguno = true;
            $gasto += (float) data_get($resp->json(), 'data.0.spend', 0);
        }

        if ($alguno) {
            $publicacion->update(['pauta_gasto_total_cop' => $gasto]);
        }
    }

    /**
     * Presupuesto diario sugerido: arranca en el default de prueba del aliado; si el tema de
     * esta pieza ya tiene historial de conversaciones de WhatsApp atribuidas con buen costo
     * por conversación, sugiere escalar — nunca decide activar, solo calcula el número.
     */
    public static function sugerirPresupuesto(Publicacion $publicacion, PautaConfig $config): float
    {
        $base = (float) $config->presupuesto_diario_default_cop;
        if (! $publicacion->tema) {
            return $base;
        }

        $piezasDelTema = Publicacion::where('aliado_id', $publicacion->aliado_id)
            ->where('tema', $publicacion->tema)
            ->where('pauta_gasto_total_cop', '>', 0)
            ->get();

        if ($piezasDelTema->isEmpty()) {
            return $base;
        }

        $gastoTotal = $piezasDelTema->sum('pauta_gasto_total_cop');
        $conversaciones = \App\Models\WhatsappConversacion::whereIn('origen_publicacion_id', $piezasDelTema->pluck('id'))->count();

        if ($conversaciones === 0) {
            return $base; // gastó y no trajo nada real todavía — no escalar a ciegas
        }

        $costoPorConversacion = $gastoTotal / $conversaciones;
        // Umbral simple: si el costo por conversación real es bueno (<15.000 COP), sugerir +40%.
        $sugerido = $costoPorConversacion < 15000 ? round($base * 1.4) : $base;

        return min($sugerido, PautaConfig::TOPE_DIARIO_COP);
    }

    private static function borrar(string $campanaId, string $token): void
    {
        // El cliente HTTP manda el body como JSON en DELETE, y Graph API no lo lee ahí —
        // el access_token tiene que ir en la query string.
        Http::delete(self::BASE_URL."/{$campanaId}?access_token=".urlencode($token));
    }

    private static function errorDeMeta(\Illuminate\Http\Client\Response $resp): string
    {
        $mensaje = $resp->json('error.error_user_msg') ?? $resp->json('error.message');

        return $mensaje ? "Meta respondió: {$mensaje}" : "Meta respondió con error HTTP {$resp->status()}.";
    }

    /**
     * Lo que Meta dice que se lleva gastado en el mes en curso, en toda la cuenta.
     *
     * Se le pregunta a Meta en vez de sumarlo aquí porque la columna `pauta_gasto_total_cop`
     * de cada pieza es su gasto DE TODA LA VIDA, no el del mes: sumarla daba dos errores a la
     * vez —dejaba fuera las piezas activadas en meses anteriores que siguen gastando, y de las
     * que sí contaba metía también lo gastado antes—. El 3-sep-2026 el sistema creía llevar
     * $102 del mes cuando en los dos primeros días iban $11.870, y el tope de $600.000 no
     * habría frenado nada.
     *
     * El mes lo cuenta Meta en la zona horaria DE LA CUENTA (la de Brygar está en
     * America/Los_Angeles), así que el corte no coincide exactamente con la medianoche
     * colombiana. Para un guardarraíl de tope mensual esa diferencia de horas es irrelevante.
     *
     * @return float|null null si no se pudo consultar; quien llama decide qué hacer con eso.
     */
    public static function gastoDelMes(PautaConfig $config): ?float
    {
        if (! $config->ad_account_id || ! $config->access_token_ads) {
            return null;
        }

        // Se cachea corto: esto lo consulta el cron de sincronización y el informe, y la API
        // de Meta admite una llamada cada 30 segundos.
        return Cache::remember(
            "pauta_gasto_mes_{$config->aliado_id}",
            now()->addMinutes(15),
            function () use ($config) {
                $cuenta = 'act_'.ltrim($config->ad_account_id, 'act_');

                $resp = Http::get(self::BASE_URL."/{$cuenta}/insights", [
                    'access_token' => $config->access_token_ads,
                    'fields' => 'spend',
                    'level' => 'account',
                    'time_range' => json_encode([
                        'since' => now()->startOfMonth()->toDateString(),
                        'until' => now()->toDateString(),
                    ]),
                ]);

                if (! $resp->successful() || $resp->json('error')) {
                    return null;
                }

                // Sin gasto en el mes Meta devuelve `data` vacío, que no es un error: son cero.
                return (float) ($resp->json('data.0.spend') ?? 0);
            }
        );
    }
}
