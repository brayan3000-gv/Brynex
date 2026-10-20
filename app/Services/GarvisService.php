<?php

namespace App\Services;

use App\Jobs\GarvisFotoJob;
use App\Jobs\GarvisNotaDeVozJob;
use App\Models\ConfiguracionBrynex;
use App\Models\IaConfiguracionAliado;
use App\Models\WhatsappConfig;
use App\Services\Publicidad\LocucionIaService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * GARVIS: el asistente de Brayan, que vive en el repo brayan3000-gv/garvis.
 *
 * Brayan le escribe al número de Brygar, o a la línea aparte de sus gastos. Sus mensajes de texto, sus notas de voz
 * (ya pasadas a texto, ver GarvisNotaDeVozJob) y sus fotos (GarvisFotoJob) no entran a las
 * conversaciones de Brynex: se vuelven un comentario en el issue del día del
 * repo, y un workflow de ese repo le contesta. Cada línea es un canal con su propio
 * issue del día («WhatsApp …» o «Gastos …»), y la respuesta sale por la misma línea. La respuesta vuelve por
 * `php artisan garvis:responder`, que corre en este servidor.
 *
 * Solo se desvía con la firma de Meta verificada. El webhook de Brynex acepta
 * payloads sin firma mientras WHATSAPP_WEBHOOK_ESTRICTO esté apagado, y aquí eso no sirve: un
 * mensaje falso «desde el número de Brayan» le daría órdenes a algo con
 * acceso a sus repos.
 */
class GarvisService
{
    /** Brygar: su número es el de los avisos de despliegue, al que Brayan ya escribe. */
    public const ALIADO_ID = 2;

    /** El número de Brygar: todo lo que no es de gastos. Es también la etiqueta de su issue. */
    public const CANAL_BRYGAR = 'whatsapp';

    /** La línea aparte para los gastos personales de Brayan. */
    public const CANAL_GASTOS = 'gastos';

    /** El mensaje de WhatsApp más largo que acepta Meta es 4096; se deja margen. */
    private const MAX_TROZO = 3500;

    public function __construct(private WhatsappApiService $whatsappApi) {}

    public function numero(): string
    {
        return WhatsappApiService::normalizarNumero((string) config('services.garvis.numero'));
    }

    /**
     * ¿Este mensaje entrante es de Brayan para GARVIS?
     */
    public function esParaGarvis(array $msg, string $phoneNumberId, bool $firmaVerificada): bool
    {
        if (! config('services.garvis.activo')) {
            return false;
        }

        // Texto, nota de voz o foto; un sticker o una ubicación siguen a Brynex como siempre.
        if (($msg['from'] ?? null) !== $this->numero() || ! in_array($msg['type'] ?? null, ['text', 'audio', 'image'], true)) {
            return false;
        }

        // Solo lo que le escribe al número de Brygar o a la línea de gastos: a los
        // números propios de otros aliados les sigue escribiendo como cualquier cliente.
        if (! $this->canalDe($phoneNumberId)) {
            return false;
        }

        if (! $firmaVerificada) {
            Log::warning('GARVIS: mensaje de Brayan sin firma de Meta verificada; sigue a Brynex como siempre.');

            return false;
        }

        return true;
    }

    /** Por cuál línea llegó: la de Brygar, la de gastos, o ninguna de GARVIS. */
    public function canalDe(string $phoneNumberId): ?string
    {
        $gastos = (string) config('services.garvis.gastos_phone_number_id');
        if ($gastos !== '' && $phoneNumberId === $gastos) {
            return self::CANAL_GASTOS;
        }

        if ($phoneNumberId === ($this->config()->credencialesEfectivas()['phone_number_id'] ?? null)) {
            return self::CANAL_BRYGAR;
        }

        return null;
    }

    /**
     * Le pasa el mensaje a GARVIS. Nunca lanza: el webhook tiene que contestarle a Meta.
     */
    public function recibir(array $msg, string $canal = self::CANAL_BRYGAR): void
    {
        $waId = $msg['id'] ?? '';
        $tipo = $msg['type'] ?? 'text';
        $texto = trim($msg['text']['body'] ?? '');
        $mediaId = $msg[$tipo]['id'] ?? '';

        if ($tipo === 'text' ? $texto === '' : $mediaId === '') {
            return;
        }

        // Meta reenvía el mismo mensaje si tarda en recibir el 200.
        if (! Cache::add('garvis_msg:'.$waId, true, now()->addDay())) {
            return;
        }

        // El mensaje de Brayan abre la ventana de 24 h para texto libre. Como ya
        // no se guarda en whatsapp_mensajes, se deja aquí para quien la consulte.
        Cache::put('whatsapp_ventana:'.$this->numero(), now()->toIso8601String(), now()->addHours(24));

        try {
            $this->whatsappApi->marcarLeidoYEscribiendo($waId, $this->config($canal));
        } catch (\Throwable $e) {
            // Los chulos azules son cortesía; no pueden impedir que llegue el mensaje.
        }

        // Bajar el audio o la foto tarda más de lo que Meta espera el 200: va en cola.
        if ($tipo === 'audio') {
            GarvisNotaDeVozJob::dispatch($mediaId, $msg['audio']['mime_type'] ?? null, $canal);

            return;
        }

        if ($tipo === 'image') {
            GarvisFotoJob::dispatch($mediaId, $msg['image']['mime_type'] ?? null, trim($msg['image']['caption'] ?? ''), $canal);

            return;
        }

        $this->pasarAGarvis('📱 '.$texto, $canal);
    }

    /**
     * Deja el mensaje en el issue del día. Si GitHub no lo recibe, se lo dice a Brayan.
     */
    public function pasarAGarvis(string $cuerpo, string $canal = self::CANAL_BRYGAR): void
    {
        if (! $this->publicarEnGithub($cuerpo, $canal)) {
            $this->responder('No pude pasarle tu mensaje a GARVIS: GitHub no lo recibió. El detalle quedó en el log de Brynex.', $canal);
        }
    }

    /**
     * Manda a Brayan el texto de GARVIS, partido si no cabe en un mensaje.
     */
    public function responder(string $texto, string $canal = self::CANAL_BRYGAR): bool
    {
        $config = $this->config($canal);
        $ok = true;

        foreach ($this->trozos($texto) as $trozo) {
            $envio = $this->whatsappApi->enviarTexto($this->numero(), $trozo, $config);

            if (! ($envio['ok'] ?? false)) {
                Log::error('GARVIS: falló el envío por WhatsApp', ['error' => $envio['error'] ?? null]);
                $ok = false;
                break;
            }
        }

        return $ok;
    }

    /**
     * Manda a Brayan una captura que tomó GARVIS. La ruta es del disco local, dentro de garvis/.
     */
    public function enviarImagen(string $ruta, string $mime, string $canal = self::CANAL_BRYGAR): bool
    {
        $envio = $this->whatsappApi->enviarMedia($this->numero(), 'image', $ruta, $mime, basename($ruta), $this->config($canal));

        if (! ($envio['ok'] ?? false)) {
            Log::error('GARVIS: falló el envío de la captura por WhatsApp', ['error' => $envio['error'] ?? null]);

            return false;
        }

        return true;
    }

    /** La de Brygar, que es el número al que Brayan le escribe; si no tiene, la global de Brynex. */
    public function llaveGemini(): ?string
    {
        $propia = IaConfiguracionAliado::paraAliado(self::ALIADO_ID)->gemini_api_key;
        if ($propia) {
            return $propia;
        }

        $global = ConfiguracionBrynex::obtener('ia_global_gemini_api_key');
        try {
            return $global ? Crypt::decryptString($global) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Le dice a Brayan el texto en una nota de voz: Gemini lo lee (la misma locución de las
     * piezas de publicidad) y FFmpeg lo pasa a ogg/opus, que es lo único que WhatsApp
     * muestra como nota de voz y no como archivo.
     */
    public function enviarVoz(string $texto, string $canal = self::CANAL_BRYGAR): bool
    {
        $apiKey = $this->llaveGemini();
        if (! $apiKey) {
            Log::error('GARVIS: no hay llave de Gemini para la nota de voz');

            return false;
        }

        $disco = Storage::disk('local');
        $disco->makeDirectory('garvis');
        $base = 'garvis/voz-'.Str::random(12);
        $wav = $disco->path($base.'.wav');
        $ogg = $base.'.ogg';

        try {
            $r = LocucionIaService::generar(
                $apiKey,
                $texto,
                $wav,
                LocucionIaService::VOZ_MASCULINA,
                'Dilo como un asistente que le habla a su jefe por WhatsApp: tranquilo, claro y natural, en español colombiano'
            );
            if (! $r['ok']) {
                Log::error('GARVIS: Gemini no generó la voz', ['error' => $r['error']]);

                return false;
            }

            $ffmpeg = Process::timeout(60)->run([
                config('services.ffmpeg.binario', 'ffmpeg'), '-y', '-i', $wav,
                '-c:a', 'libopus', '-b:a', '32k', '-ac', '1', $disco->path($ogg),
            ]);
            if (! $ffmpeg->successful()) {
                Log::error('GARVIS: FFmpeg no pudo pasar la voz a ogg', ['error' => mb_substr($ffmpeg->errorOutput(), -300)]);

                return false;
            }

            $envio = $this->whatsappApi->enviarMedia($this->numero(), 'audio', $ogg, 'audio/ogg', basename($ogg), $this->config($canal));
            if (! ($envio['ok'] ?? false)) {
                Log::error('GARVIS: falló el envío de la nota de voz', ['error' => $envio['error'] ?? null]);

                return false;
            }

            return true;
        } finally {
            $disco->delete([$base.'.wav', $ogg]);
        }
    }

    /**
     * Un issue por día (hora de Colombia): el primer mensaje lo abre, los demás lo comentan.
     */
    private function publicarEnGithub(string $cuerpo, string $canal): bool
    {
        $repo = config('services.garvis.repo');
        $token = config('services.garvis.github_token');

        if (! $repo || ! $token) {
            Log::error('GARVIS: faltan GARVIS_REPO o GARVIS_GITHUB_TOKEN en el .env');

            return false;
        }

        $hoy = now('America/Bogota')->format('Y-m-d');
        $titulo = ($canal === self::CANAL_GASTOS ? 'Gastos ' : 'WhatsApp ').$hoy;
        $cache = 'garvis_issue:'.($canal === self::CANAL_GASTOS ? 'gastos:' : '').$hoy;
        $gh = Http::withToken($token)
            ->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(8)
            ->baseUrl('https://api.github.com/repos/'.$repo);

        try {
            $issue = Cache::get($cache) ?? $this->buscarIssue($gh, $titulo, $canal);

            if ($issue) {
                $resp = $gh->post("/issues/{$issue}/comments", ['body' => $cuerpo]);
            } else {
                $resp = $gh->post('/issues', ['title' => $titulo, 'body' => $cuerpo, 'labels' => [$canal]]);
                $issue = $resp->json('number');
            }

            if (! $resp->successful()) {
                Log::error('GARVIS: GitHub rechazó el mensaje', ['status' => $resp->status(), 'body' => Str::limit($resp->body(), 300)]);

                return false;
            }

            Cache::put($cache, $issue, now()->addDays(2));

            return true;
        } catch (\Throwable $e) {
            Log::error('GARVIS: no se pudo hablar con GitHub', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /** Si la caché se perdió, el issue del día se busca entre los abiertos. */
    private function buscarIssue($gh, string $titulo, string $canal): ?int
    {
        $abiertos = $gh->get('/issues', ['labels' => $canal, 'state' => 'open', 'per_page' => 20])->json() ?: [];

        foreach ($abiertos as $issue) {
            if (($issue['title'] ?? null) === $titulo) {
                return $issue['number'];
            }
        }

        return null;
    }

    /** @return string[] */
    private function trozos(string $texto): array
    {
        $texto = trim($texto);
        $trozos = [];

        while (mb_strlen($texto) > self::MAX_TROZO) {
            // Se corta en el último salto de línea que quepa, para no partir una frase.
            $corte = mb_strrpos(mb_substr($texto, 0, self::MAX_TROZO), "\n") ?: self::MAX_TROZO;
            $trozos[] = rtrim(mb_substr($texto, 0, $corte));
            $texto = ltrim(mb_substr($texto, $corte));
        }

        $trozos[] = $texto;

        return $trozos;
    }

    /** La cuenta de Brygar; para la línea de gastos, la misma cuenta hablando desde ese número. */
    public function config(string $canal = self::CANAL_BRYGAR): WhatsappConfig
    {
        $config = WhatsappConfig::paraAliado(self::ALIADO_ID);

        if ($canal === self::CANAL_GASTOS) {
            $config->phoneNumberIdForzado = (string) config('services.garvis.gastos_phone_number_id') ?: null;
        }

        return $config;
    }
}
