<?php

namespace App\Services\Demo;

use App\Models\DemoProspecto;
use App\Models\WhatsappConfig;
use App\Models\WhatsappConversacion;
use App\Models\WhatsappMensaje;
use App\Services\WhatsappApiService;
use App\Services\WhatsappBandejaCompartida;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * El puente entre la demo pública y producción para lo único que la demo hace
 * de verdad: guardar quién la probó y mandarle ejemplos a SU WhatsApp.
 *
 * Producción es la que guarda (la base de la demo se borra cada noche) y la que
 * envía (el token de Meta nunca sale de brynex.co). La demo solo pide, firmado
 * con un secreto compartido (DEMO_RELEVO_SECRETO), y producción decide:
 *   - solo al número que escribió «DEMO 1234» desde su propio WhatsApp —nadie
 *     puede usar la demo para escribirle a un tercero—;
 *   - máximo `demo.relevo.max_envios` envíos por prospecto;
 *   - dentro de las 24 h desde que escribió (la ventana de Meta para mensajes
 *     libres: sin plantillas, sin costo).
 *
 * Sale por el número de BryNex (Info Avapp), el mismo que usa su bandeja.
 */
class RelevoDemo
{
    /** «DEMO 1234», «demo-1234», «DEMO1234». */
    private const PATRON = '/\bDEMO[\s\-]*(\d{4})\b/i';

    public static function maxEnvios(): int
    {
        return (int) config('demo.relevo.max_envios', 5);
    }

    public static function activo(): bool
    {
        return (bool) config('demo.relevo.secreto');
    }

    // ─── Firma (los dos lados) ────────────────────────────────────────

    public static function firmar(int $tiempo, string $cuerpo): string
    {
        return hash_hmac('sha256', $tiempo.'.'.$cuerpo, (string) config('demo.relevo.secreto'));
    }

    public static function firmaValida(Request $request): bool
    {
        $tiempo = (int) $request->header('X-Demo-Tiempo');

        return self::activo()
            && abs(time() - $tiempo) < 300
            && hash_equals(self::firmar($tiempo, $request->getContent()), (string) $request->header('X-Demo-Firma'));
    }

    // ─── Lado de la demo: pedirle a producción ───────────────────────

    /** POST firmado a producción. null si no hay relevo o no respondió. */
    public static function pedir(string $ruta, array $datos): ?array
    {
        if (! self::activo() || ! config('demo.relevo.url')) {
            return null;
        }

        $cuerpo = json_encode($datos, JSON_UNESCAPED_UNICODE);
        $tiempo = time();

        try {
            $r = Http::withHeaders([
                'X-Demo-Tiempo' => (string) $tiempo,
                'X-Demo-Firma' => self::firmar($tiempo, $cuerpo),
                'Accept' => 'application/json',
            ])->withBody($cuerpo, 'application/json')
                ->timeout(25)
                ->post(rtrim((string) config('demo.relevo.url'), '/').'/demo-relevo/'.ltrim($ruta, '/'));

            return $r->json() ?: null;
        } catch (Throwable $e) {
            Log::warning('Demo: el relevo no respondió', ['ruta' => $ruta, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** Lo que usa SimuladorHttp para dejar pasar estas peticiones. */
    public static function esPeticionDelRelevo(string $url): bool
    {
        $base = rtrim((string) config('demo.relevo.url'), '/');

        return $base !== '' && str_starts_with($url, $base.'/demo-relevo/');
    }

    // ─── Lado de producción ──────────────────────────────────────────

    public static function codigoEn(?string $texto): ?string
    {
        return $texto && preg_match(self::PATRON, $texto, $m) ? $m[1] : null;
    }

    public static function registrar(array $datos, ?string $ip): DemoProspecto
    {
        do {
            $codigo = (string) random_int(1000, 9999);
        } while (DemoProspecto::where('codigo', $codigo)->where('created_at', '>', now()->subDay())->exists());

        return DemoProspecto::create([
            'aliado_id' => WhatsappBandejaCompartida::aliadoBandeja(),
            'nombre' => $datos['nombre'],
            'empresa' => $datos['empresa'] ?? null,
            'celular' => $datos['celular'],
            'codigo' => $codigo,
            'acepto_contacto' => (bool) ($datos['acepto_contacto'] ?? false),
            'ip' => $ip,
            'ultimo_uso_at' => now(),
        ]);
    }

    /**
     * Lo llama el webhook de WhatsApp con cada mensaje entrante del número de
     * BryNex. Si trae un código de la demo, deja verificado a ese prospecto con
     * el número que DE VERDAD escribió y le confirma por el mismo chat.
     */
    public static function verificarDesdeWhatsapp(string $telefono, ?string $nombrePerfil, ?string $texto): ?DemoProspecto
    {
        $codigo = self::codigoEn($texto);
        if (! $codigo) {
            return null;
        }

        $p = DemoProspecto::where('codigo', $codigo)
            ->where('created_at', '>', now()->subDay())
            ->orderByDesc('id')
            ->first();

        if (! $p) {
            return null;
        }

        $primeraVez = ! $p->verificado_at;
        $p->update([
            'telefono_whatsapp' => preg_replace('/\D/', '', $telefono),
            'nombre_whatsapp' => $nombrePerfil,
            'verificado_at' => now(),
            'ultimo_uso_at' => now(),
        ]);

        if ($primeraVez) {
            $quedan = max(0, self::maxEnvios() - $p->envios);
            self::enviarTexto($p, "✅ ¡Listo, {$p->nombre}! Tu WhatsApp quedó conectado a la demo de BryNex.\n\n"
                ."Ahora, cuando en la demo envíes una planilla, un cobro o un recibo por WhatsApp, te llega aquí a ti "
                ."(máximo {$quedan} envíos de prueba). Los datos son inventados.", contar: false);
        }

        return $p;
    }

    /**
     * Un envío pedido por la demo. Siempre al número verificado del prospecto,
     * nunca al que trae el pedido.
     *
     * @param  array{tipo: string, texto?: string, archivo?: string, nombre_archivo?: string, mime?: string}  $pedido
     */
    public static function enviarPedido(DemoProspecto $p, array $pedido): array
    {
        if (! $p->puedeRecibir(self::maxEnvios())) {
            return ['ok' => false, 'motivo' => $p->envios >= self::maxEnvios() ? 'sin_envios' : 'sin_verificar'];
        }

        $texto = '🧪 *DEMO BryNex* — '.trim((string) ($pedido['texto'] ?? ''));

        if (($pedido['tipo'] ?? '') === 'documento' && ! empty($pedido['archivo'])) {
            $r = self::enviarDocumento($p, base64_decode($pedido['archivo']), (string) ($pedido['nombre_archivo'] ?? 'documento.pdf'),
                (string) ($pedido['mime'] ?? 'application/pdf'), Str::limit($texto, 1000));
        } else {
            $r = self::enviarTexto($p, Str::limit($texto, 3500));
        }

        return $r + ['envios' => $p->fresh()->envios, 'max' => self::maxEnvios()];
    }

    private static function enviarTexto(DemoProspecto $p, string $texto, bool $contar = true): array
    {
        $config = self::configBrynex();
        if (! $config) {
            return ['ok' => false, 'motivo' => 'sin_config'];
        }

        $r = app(WhatsappApiService::class)->enviarTexto($p->telefono_whatsapp, $texto, $config);
        self::anotar($p, $r, 'text', $texto, $contar);

        return ['ok' => (bool) $r['ok'], 'wa_message_id' => $r['wa_message_id'] ?? null, 'error' => $r['error'] ?? null];
    }

    private static function enviarDocumento(DemoProspecto $p, string $contenido, string $nombre, string $mime, string $caption): array
    {
        $config = self::configBrynex();
        if (! $config || $contenido === '' || strlen($contenido) > 8 * 1024 * 1024) {
            return ['ok' => false, 'motivo' => 'documento_invalido'];
        }

        $ruta = 'demo-relevo/'.Str::uuid().'-'.Str::slug(pathinfo($nombre, PATHINFO_FILENAME)).'.pdf';
        Storage::disk('local')->put($ruta, $contenido);

        try {
            $r = app(WhatsappApiService::class)->enviarMedia($p->telefono_whatsapp, 'document', $ruta, $mime, $nombre, $config, $caption);
        } finally {
            Storage::disk('local')->delete($ruta);
        }

        self::anotar($p, $r, 'document', $caption, true, $nombre);

        return ['ok' => (bool) $r['ok'], 'wa_message_id' => $r['wa_message_id'] ?? null, 'error' => $r['error'] ?? null];
    }

    /** La cuenta del aliado BryNex (usa el número compartido: Info Avapp). */
    private static function configBrynex(): ?WhatsappConfig
    {
        return WhatsappConfig::where('aliado_id', WhatsappBandejaCompartida::aliadoBandeja())->where('activo', true)->first();
    }

    /** Cuenta el envío y lo deja en la conversación de la bandeja, para que se vea qué se le mandó. */
    private static function anotar(DemoProspecto $p, array $r, string $tipo, string $contenido, bool $contar, ?string $archivo = null): void
    {
        if (! $r['ok']) {
            Log::warning('Demo: no salió el envío al prospecto', ['prospecto' => $p->id, 'error' => $r['error'] ?? null]);

            return;
        }

        if ($contar) {
            $p->update(['envios' => $p->envios + 1, 'ultimo_envio_at' => now(), 'ultimo_uso_at' => now()]);
        }

        $conversacion = WhatsappConversacion::where('aliado_id', $p->aliado_id)
            ->where('wa_contact_id', $p->telefono_whatsapp)->first();

        if ($conversacion) {
            WhatsappMensaje::create([
                'conversacion_id' => $conversacion->id,
                'aliado_id' => $p->aliado_id,
                'wa_message_id' => $r['wa_message_id'] ?? null,
                'direccion' => 'saliente',
                'tipo' => $tipo,
                'contenido' => $contenido,
                'media_nombre' => $archivo,
                'estado' => 'enviado',
                'es_bot' => true,
            ]);
            $conversacion->update(['ultimo_mensaje_at' => now()]);
        }
    }
}
