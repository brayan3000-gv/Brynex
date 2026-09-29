<?php

namespace App\Services\RedesSociales;

use App\Models\RedSocialConfig;
use Illuminate\Support\Facades\Http;

/**
 * Publicador para Facebook Page e Instagram Business, ambas sobre la Meta Graph API.
 * Facebook: POST /{page_id}/photos con `url` (imagen pública) + caption.
 * Instagram: dos pasos — POST /{ig_id}/media (crea el contenedor) y luego
 * POST /{ig_id}/media_publish (lo publica) — así lo exige la API de Meta para IG.
 */
class MetaGraphPublicador implements PublicadorRed
{
    private const BASE_URL = 'https://graph.facebook.com/v19.0';

    public function __construct(private RedSocialConfig $config) {}

    public function publicarImagen(string $urlImagenPublica, string $texto): array
    {
        if (! $this->config->credencialesCompletas()) {
            return ['ok' => false, 'mensaje' => 'Faltan credenciales (identificador o token) para esta red.', 'id_publicacion' => null];
        }

        try {
            return $this->config->red === RedSocialConfig::INSTAGRAM
                ? $this->publicarInstagram($urlImagenPublica, $texto)
                : $this->publicarFacebook($urlImagenPublica, $texto);
        } catch (\Throwable $e) {
            return ['ok' => false, 'mensaje' => 'Error al publicar: '.$e->getMessage(), 'id_publicacion' => null];
        }
    }

    public function publicarVideo(string $urlVideoPublica, string $texto): array
    {
        if (! $this->config->credencialesCompletas()) {
            return ['ok' => false, 'mensaje' => 'Faltan credenciales (identificador o token) para esta red.', 'id_publicacion' => null];
        }

        try {
            return $this->config->red === RedSocialConfig::INSTAGRAM
                ? $this->publicarVideoInstagram($urlVideoPublica, $texto)
                : $this->publicarVideoFacebook($urlVideoPublica, $texto);
        } catch (\Throwable $e) {
            return ['ok' => false, 'mensaje' => 'Error al publicar el video: '.$e->getMessage(), 'id_publicacion' => null];
        }
    }

    /** Facebook procesa el video en segundo plano tras subirlo — el post aparece cuando termina, sin necesidad de sondear. */
    private function publicarVideoFacebook(string $url, string $texto): array
    {
        $resp = Http::timeout(30)->asForm()->post(self::BASE_URL."/{$this->config->identificador}/videos", [
            'file_url' => $url,
            'description' => $texto,
            'access_token' => $this->config->access_token,
        ]);

        if (! $resp->successful()) {
            return ['ok' => false, 'mensaje' => $this->errorDeMeta($resp), 'id_publicacion' => null];
        }

        return ['ok' => true, 'mensaje' => 'Video publicado en Facebook (procesando).', 'id_publicacion' => $resp->json('id')];
    }

    /** Reel de Instagram — mismo patrón de contenedor que la imagen, pero con presupuesto de espera mucho mayor (el video tarda más en procesarse). */
    private function publicarVideoInstagram(string $url, string $texto): array
    {
        $crear = Http::timeout(15)->asForm()->post(self::BASE_URL."/{$this->config->identificador}/media", [
            'media_type' => 'REELS',
            'video_url' => $url,
            'caption' => $texto,
            'access_token' => $this->config->access_token,
        ]);

        if (! $crear->successful()) {
            return ['ok' => false, 'mensaje' => $this->errorDeMeta($crear), 'id_publicacion' => null];
        }

        $creationId = $crear->json('id');

        if (! $this->esperarContenedorListo($creationId, 40, 5)) {
            return ['ok' => false, 'mensaje' => 'El video no terminó de procesarse en Instagram a tiempo. Puedes reintentar más tarde.', 'id_publicacion' => null];
        }

        return $this->publicarContenedor($creationId, 'Reel publicado en Instagram.');
    }

    private function publicarFacebook(string $url, string $texto): array
    {
        $resp = Http::timeout(15)->asForm()->post(self::BASE_URL."/{$this->config->identificador}/photos", [
            'url' => $url,
            'caption' => $texto,
            'access_token' => $this->config->access_token,
        ]);

        if (! $resp->successful()) {
            return ['ok' => false, 'mensaje' => $this->errorDeMeta($resp), 'id_publicacion' => null];
        }

        return ['ok' => true, 'mensaje' => 'Publicado en Facebook.', 'id_publicacion' => $resp->json('post_id') ?? $resp->json('id')];
    }

    private function publicarInstagram(string $url, string $texto): array
    {
        $crear = Http::timeout(15)->asForm()->post(self::BASE_URL."/{$this->config->identificador}/media", [
            'image_url' => $url,
            'caption' => $texto,
            'access_token' => $this->config->access_token,
        ]);

        if (! $crear->successful()) {
            return ['ok' => false, 'mensaje' => $this->errorDeMeta($crear), 'id_publicacion' => null];
        }

        $creationId = $crear->json('id');

        // La creación del contenedor es asíncrona en Meta (empieza en IN_PROGRESS mientras
        // descarga/procesa la imagen) — publicar antes de que quede FINISHED falla con
        // "Media ID is not available". Se espera activamente en vez de asumir que ya terminó.
        if (! $this->esperarContenedorListo($creationId)) {
            return ['ok' => false, 'mensaje' => 'La imagen no terminó de procesarse en Instagram a tiempo. Intenta de nuevo.', 'id_publicacion' => null];
        }

        return $this->publicarContenedor($creationId, 'Publicado en Instagram.');
    }

    /**
     * Publica el contenedor y, si la respuesta no llega, averigua si de todos modos se publicó.
     *
     * El 28-sep-2026 tres piezas quedaron grabadas como «instagram falló» —cURL error 28 a los
     * 15 segundos en media_publish— y las tres estaban publicadas en Instagram: Meta terminó el
     * trabajo después de que nosotros dejamos de escuchar. Una de ellas salió dos veces.
     *
     * Eso es peor que un error: el panel mostraba el destino en rojo con su botón de reintentar,
     * y darle habría puesto una tercera copia. Por eso ante un timeout no se declara el fracaso
     * sin preguntar — el contenedor pasa a status_code PUBLISHED cuando Meta lo publica, así que
     * se le pregunta a él antes de responder.
     *
     * @return array{ok: bool, mensaje: string, id_publicacion: ?string}
     */
    private function publicarContenedor(string $creationId, string $mensajeOk): array
    {
        // 60s, no 15: publicar un reel no es instantáneo y cortar antes es lo que produjo el
        // duplicado. El contenedor ya está FINISHED a esta altura, así que no hay espera larga.
        $publicar = Http::timeout(60)->asForm()->post(self::BASE_URL."/{$this->config->identificador}/media_publish", [
            'creation_id' => $creationId,
            'access_token' => $this->config->access_token,
        ]);

        if ($publicar->successful()) {
            return ['ok' => true, 'mensaje' => $mensajeOk, 'id_publicacion' => $publicar->json('id')];
        }

        $error = $this->errorDeMeta($publicar);

        if (! $this->contenedorYaPublicado($creationId)) {
            return ['ok' => false, 'mensaje' => $error, 'id_publicacion' => null];
        }

        return [
            'ok' => true,
            'mensaje' => $mensajeOk.' (la respuesta de Meta no llegó, pero el contenedor quedó publicado — NO reintentar: se duplica).',
            'id_publicacion' => $this->ultimaPublicacionDeLaCuenta(),
        ];
    }

    /** ¿Meta publicó el contenedor aunque no nos haya contestado? Le da unos segundos al estado. */
    private function contenedorYaPublicado(string $creationId): bool
    {
        for ($intento = 0; $intento < 4; $intento++) {
            $resp = Http::timeout(10)->get(self::BASE_URL."/{$creationId}", [
                'fields' => 'status_code',
                'access_token' => $this->config->access_token,
            ]);

            if ($resp->json('status_code') === 'PUBLISHED') {
                return true;
            }

            sleep(2);
        }

        return false;
    }

    /**
     * Id del último medio de la cuenta, para recuperar el de una publicación que sí salió pero
     * cuya respuesta se perdió. Sin él no se puede dejar el link de WhatsApp en el primer
     * comentario, que es de donde sale la atribución de las conversaciones.
     */
    private function ultimaPublicacionDeLaCuenta(): ?string
    {
        $resp = Http::timeout(20)->get(self::BASE_URL."/{$this->config->identificador}/media", [
            'fields' => 'id',
            'limit' => 1,
            'access_token' => $this->config->access_token,
        ]);

        return $resp->json('data.0.id');
    }

    /**
     * Sondea status_code del contenedor hasta FINISHED (o ERROR/timeout). Por defecto ~15s
     * (imagen); para video se pasa un presupuesto mucho mayor porque Meta tarda bastante más
     * en procesarlo (ver publicarVideoInstagram).
     */
    private function esperarContenedorListo(string $creationId, int $intentos = 8, float $segundosEntreSondeos = 1.5): bool
    {
        for ($intento = 0; $intento < $intentos; $intento++) {
            $resp = Http::timeout(10)->get(self::BASE_URL."/{$creationId}", [
                'fields' => 'status_code',
                'access_token' => $this->config->access_token,
            ]);

            $estado = $resp->json('status_code');

            if ($estado === 'FINISHED') {
                return true;
            }
            if ($estado === 'ERROR' || $estado === 'EXPIRED') {
                return false;
            }

            usleep((int) round($segundosEntreSondeos * 1000000));
        }

        return false;
    }

    /** Facebook e Instagram usan el mismo patrón: POST /{id_publicacion}/comments con `message`. */
    public function comentar(string $idPublicacion, string $texto): array
    {
        $resp = Http::timeout(15)->asForm()->post(self::BASE_URL."/{$idPublicacion}/comments", [
            'message' => $texto,
            'access_token' => $this->config->access_token,
        ]);

        if (! $resp->successful()) {
            return ['ok' => false, 'mensaje' => $this->errorDeMeta($resp)];
        }

        return ['ok' => true, 'mensaje' => 'Comentario publicado.'];
    }

    public function probarConexion(): array
    {
        if (! $this->config->credencialesCompletas()) {
            return ['ok' => false, 'mensaje' => 'Completa el identificador y el token de acceso primero.'];
        }

        $campo = $this->config->red === RedSocialConfig::INSTAGRAM ? 'username' : 'name';

        try {
            $resp = Http::timeout(10)->get(self::BASE_URL."/{$this->config->identificador}", [
                'fields' => "id,{$campo}",
                'access_token' => $this->config->access_token,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'mensaje' => 'No se pudo conectar con Meta: '.$e->getMessage()];
        }

        if (! $resp->successful()) {
            return ['ok' => false, 'mensaje' => $this->errorDeMeta($resp)];
        }

        $nombre = $resp->json($campo) ?? $resp->json('id');

        return ['ok' => true, 'mensaje' => "Conexión exitosa. Cuenta: {$nombre}"];
    }

    private function errorDeMeta(\Illuminate\Http\Client\Response $resp): string
    {
        $mensaje = $resp->json('error.message');

        return $mensaje ? "Meta respondió: {$mensaje}" : "Meta respondió con error HTTP {$resp->status()}.";
    }
}
