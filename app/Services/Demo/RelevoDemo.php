<?php

namespace App\Services\Demo;

use App\Models\DemoProspecto;
use App\Models\WhatsappConfig;
use App\Models\WhatsappConversacion;
use App\Models\WhatsappMensaje;
use App\Models\WhatsappPlantilla;
use App\Services\AlertaOperativaService;
use App\Services\WhatsappApiService;
use App\Services\WhatsappBandejaCompartida;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
 * Sale por el número de BRYGAR (config demo.relevo.aliado_envio), el mismo del
 * botón «Quiero BryNex»: el prospecto tiene un solo chat y ahí lo atiende el bot.
 */
class RelevoDemo
{
    /** «DEMO 1234», «demo-1234», «DEMO1234». */
    private const PATRON = '/\bDEMO[\s\-]*(\d{4})\b/i';

    /**
     * La plantilla que le llega al celular que escribió al registrarse, con dos
     * botones. Tocar «Sí» verifica sin escribir ningún código; un desconocido
     * cuyo número alguien escribió recibe solo este mensaje y puede ignorarlo.
     * Existe con el mismo nombre en la cuenta de Info Avapp y en la de Brygar
     * (13-nov-2026, UTILITY): sale por la de demo.relevo.aliado_envio.
     */
    public const PLANTILLA_BIENVENIDA = 'demo_bienvenida_brynex';

    private const BOTON_SI = '/s[ií],?\s*quiero los ejemplos/iu';

    private const BOTON_NO = '/no fui yo/iu';

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

    /**
     * Lado de la demo: manda a producción lo que lleva hecho el prospecto de esta
     * sesión (pasos, módulos, presentación), solo cuando cambió.
     */
    public static function sincronizarActividad(): void
    {
        $p = session('demo_prospecto');
        if (! $p || empty($p['id'])) {
            return;
        }

        $actividad = [
            'pasos' => array_values((array) session('demo_pasos', [])),
            'modulos' => array_values((array) session('demo_modulos', [])),
            'presentacion' => (bool) session('demo_presentacion', false),
        ];
        $firma = md5(json_encode($actividad));

        if (session('demo_actividad_firma') !== $firma) {
            session(['demo_actividad_firma' => $firma]);
            self::pedir('actividad', ['id' => $p['id']] + $actividad);
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

    /** Los pasos del recorrido de la demo (resources/views/demo/partials/franja). */
    public const PASOS_RECORRIDO = 6;

    /** Un aviso al WhatsApp del dueño (el mismo canal de los avisos operativos). */
    public static function avisar(string $mensaje): void
    {
        try {
            app(AlertaOperativaService::class)->enviar('Demo BryNex', $mensaje);
        } catch (Throwable $e) {
            Log::warning('Demo: no salió el aviso al dueño', ['error' => $e->getMessage()]);
        }
    }

    /** El aviso de un registro nuevo. */
    public static function avisarRegistro(DemoProspecto $p, bool $bienvenida): void
    {
        self::avisar('🧪 Nuevo en la demo: '.$p->nombre
            .($p->empresa ? ' · '.$p->empresa : '')
            .' · '.$p->celular
            .' · '.$p->origenTexto()
            .($bienvenida ? ' · le llegó la bienvenida por WhatsApp' : ''));
    }

    /**
     * Lo que hizo en la demo: pasos del recorrido, módulos abiertos y si vio la
     * presentación. Se guarda la unión con lo anterior (nunca se pierde un paso)
     * y, la primera vez que completa el recorrido, se avisa: es un prospecto caliente.
     */
    public static function anotarActividad(DemoProspecto $p, array $datos): array
    {
        $antes = (array) ($p->actividad ?? []);
        $actividad = [
            'pasos' => array_values(array_unique(array_merge((array) ($antes['pasos'] ?? []), (array) ($datos['pasos'] ?? [])))),
            'modulos' => array_values(array_unique(array_merge((array) ($antes['modulos'] ?? []), (array) ($datos['modulos'] ?? [])))),
            'presentacion' => ! empty($antes['presentacion']) || ! empty($datos['presentacion']),
        ];
        $p->update(['actividad' => $actividad, 'ultimo_uso_at' => now()]);

        if (count($actividad['pasos']) >= self::PASOS_RECORRIDO && count((array) ($antes['pasos'] ?? [])) < self::PASOS_RECORRIDO) {
            self::avisar('🔥 '.$p->nombre.($p->empresa ? ' ('.$p->empresa.')' : '')
                .' completó el recorrido de la demo. Celular: '.($p->telefono_whatsapp ?: $p->celular)
                .' · '.$p->origenTexto().'.');
        }

        return $actividad;
    }

    /** ¿Es uno de los dos botones de la plantilla de bienvenida? */
    public static function esBotonDemo(?string $texto): bool
    {
        return $texto && (preg_match(self::BOTON_SI, $texto) || preg_match(self::BOTON_NO, $texto));
    }

    /**
     * La plantilla de bienvenida al celular que escribió. Una por número por
     * hora: registrarse diez veces con el número de otro no le manda diez mensajes.
     */
    public static function enviarBienvenida(DemoProspecto $p): bool
    {
        $numero = WhatsappApiService::normalizarNumero($p->celular);
        $config = self::configEnvio();

        if (strlen($numero) < 11 || ! $config) {
            return false;
        }

        // Cuenta las bienvenidas que de verdad salieron, no los registros: si no,
        // un registro sin envío bloqueaba el siguiente (13-nov-2026).
        $llave = 'demo_bienvenida_'.$numero;
        if (Cache::has($llave)) {
            return false;
        }

        $plantilla = (new WhatsappPlantilla)->forceFill(['nombre' => self::PLANTILLA_BIENVENIDA, 'idioma' => 'es']);
        $primerNombre = Str::limit(trim(explode(' ', trim($p->nombre))[0]), 30, '');

        try {
            $r = app(WhatsappApiService::class)->enviarTemplate($numero, $plantilla, [$primerNombre ?: 'hola'], $config);
        } catch (Throwable $e) {
            Log::warning('Demo: no salió la bienvenida', ['prospecto' => $p->id, 'error' => $e->getMessage()]);

            return false;
        }

        if (! $r['ok']) {
            Log::warning('Demo: Meta no aceptó la bienvenida', ['prospecto' => $p->id, 'error' => $r['error'] ?? null]);
        } else {
            Cache::put($llave, true, now()->addHour());
            // En el chat de BryNex: así Meta anota si se entregó, se leyó o falló.
            self::anotarEnChat($numero, $p->nombre, $r['wa_message_id'] ?? null, 'template',
                'Bienvenida de la demo (plantilla '.self::PLANTILLA_BIENVENIDA.'): «¿Quieres recibir aquí los ejemplos?»');
        }

        return (bool) $r['ok'];
    }

    /**
     * Respuesta a los botones de la bienvenida. «Sí» verifica con el número que
     * tocó el botón; «No fui yo» le quita la autorización y no se le escribe más.
     */
    public static function responderBoton(string $telefono, ?string $nombrePerfil, ?string $texto): ?DemoProspecto
    {
        if (! self::esBotonDemo($texto)) {
            return null;
        }

        $telefono = preg_replace('/\D/', '', $telefono);
        $p = DemoProspecto::whereIn('celular', [$telefono, substr($telefono, 2)])
            ->where('created_at', '>', now()->subDay())
            ->orderByDesc('id')
            ->first();

        if (! $p) {
            return null;
        }

        if (preg_match(self::BOTON_NO, (string) $texto)) {
            $p->update(['acepto_contacto' => false, 'ultimo_uso_at' => now()]);

            return $p;
        }

        return self::marcarVerificado($p, $telefono, $nombrePerfil);
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
            'origen' => $datos['origen'] ?? null,
            'origen_detalle' => $datos['origen_detalle'] ?? null,
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

        return self::marcarVerificado($p, $telefono, $nombrePerfil);
    }

    /** Deja verificado al prospecto con el número que de verdad escribió, y se lo confirma. */
    private static function marcarVerificado(DemoProspecto $p, string $telefono, ?string $nombrePerfil): DemoProspecto
    {
        $primeraVez = ! $p->verificado_at;
        $p->update([
            'telefono_whatsapp' => preg_replace('/\D/', '', $telefono),
            'nombre_whatsapp' => $nombrePerfil,
            'verificado_at' => now(),
            'ultimo_uso_at' => now(),
        ]);

        if ($primeraVez) {
            $quedan = max(0, self::maxEnvios() - $p->envios);
            $primerNombre = trim(explode(' ', trim($p->nombre))[0]);
            self::enviarTexto($p, "✅ ¡Listo, {$primerNombre}! Tu WhatsApp quedó conectado a la demo de BryNex.\n\n"
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
        $config = self::configEnvio();
        if (! $config) {
            return ['ok' => false, 'motivo' => 'sin_config'];
        }

        $r = app(WhatsappApiService::class)->enviarTexto($p->telefono_whatsapp, $texto, $config);
        self::anotar($p, $r, 'text', $texto, $contar);

        return ['ok' => (bool) $r['ok'], 'wa_message_id' => $r['wa_message_id'] ?? null, 'error' => $r['error'] ?? null];
    }

    private static function enviarDocumento(DemoProspecto $p, string $contenido, string $nombre, string $mime, string $caption): array
    {
        $config = self::configEnvio();
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

    /**
     * Deja el mensaje en la conversación de la cuenta que lo envió (Brygar) con
     * ese número, creándola si no existe. Con el wa_message_id guardado, el
     * webhook anota después si Meta lo entregó, lo leyó o falló (y por qué), y
     * lo que el prospecto conteste cae en esa misma conversación, donde atiende
     * el bot.
     *
     * Si la cuenta fuera un número compartido (usa_cuenta_brynex) y ese celular
     * ya conversara con OTRO aliado, no se le crea una: el webhook encamina las
     * respuestas a la conversación más reciente y le quitaría el contacto.
     */
    private static function anotarEnChat(string $numero, ?string $nombre, ?string $waMessageId, string $tipo, string $contenido, ?string $archivo = null): void
    {
        try {
            $config = self::configEnvio();
            if (! $config) {
                return;
            }
            $aliado = (int) $config->aliado_id;
            $numero = preg_replace('/\D/', '', $numero);

            $conversacion = WhatsappConversacion::where('aliado_id', $aliado)->where('wa_contact_id', $numero)->first();

            if (! $conversacion) {
                $deOtro = $config->usa_cuenta_brynex
                    && WhatsappConversacion::where('wa_contact_id', $numero)->where('aliado_id', '<>', $aliado)->exists();
                if ($deOtro) {
                    return;
                }
                $conversacion = WhatsappConversacion::create([
                    'aliado_id' => $aliado,
                    'wa_contact_id' => $numero,
                    'nombre_contacto' => $nombre ? Str::limit($nombre, 100, '') : $numero,
                    'estado' => 'abierta',
                ]);
            }

            WhatsappMensaje::create([
                'conversacion_id' => $conversacion->id,
                'aliado_id' => $aliado,
                'wa_message_id' => $waMessageId,
                'direccion' => 'saliente',
                'tipo' => $tipo,
                'contenido' => $contenido,
                'media_nombre' => $archivo,
                'estado' => 'enviado',
                'es_bot' => true,
            ]);
            $conversacion->update(['ultimo_mensaje_at' => now()]);
        } catch (Throwable $e) {
            Log::warning('Demo: no se pudo anotar el mensaje en el chat', ['numero' => $numero, 'error' => $e->getMessage()]);
        }
    }

    /** La cuenta por la que sale el WhatsApp de la demo (config demo.relevo.aliado_envio: Brygar). */
    private static function configEnvio(): ?WhatsappConfig
    {
        return WhatsappConfig::where('aliado_id', (int) config('demo.relevo.aliado_envio', 2))->where('activo', true)->first();
    }

    /** El phone_number_id de esa cuenta: el webhook verifica los códigos y botones que lleguen a él. */
    public static function phoneNumberIdEnvio(): ?string
    {
        $config = self::configEnvio();

        return $config ? (string) ($config->credencialesEfectivas()['phone_number_id'] ?? '') ?: null : null;
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

        self::anotarEnChat($p->telefono_whatsapp, $p->nombre, $r['wa_message_id'] ?? null, $tipo, $contenido, $archivo);
    }
}
