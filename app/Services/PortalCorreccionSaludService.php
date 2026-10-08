<?php

namespace App\Services;

use App\Models\OperadorCredencial;
use App\Models\OperadorPlanillaApi;
use App\Models\RazonSocial;
use DOMDocument;
use DOMXPath;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * El paso 2 de los planes que venden salud: por el portal del operador, no por
 * la API.
 *
 * ## Por qué
 *
 * La corrección que anexa la salud del mes no la acepta el validador de
 * archivos planos: exige días e IBC iguales entre pensión, salud y riesgos
 * (`eo.val.2.198` / `2.244`) y rechaza la línea C con 1/30. Da lo mismo si el
 * archivo entra por la API o por la pantalla de carga del portal: es el mismo
 * validador y responde lo mismo.
 *
 * Lo que NO aplica ese cotejo es la **liquidación en línea**. Y el portal tiene
 * un puente entre los dos mundos: en la pantalla de inconsistencias, el botón
 * "Corrección en línea" pasa el archivo rechazado al editor con todo cargado, y
 * de ahí la planilla liquida dando Continuar. Eso es lo que hace esta clase.
 *
 * ## Por qué por HTTP y no con un navegador
 *
 * Se intentó con Playwright y no sirve: la aplicación nueva del portal guarda
 * su token en memoria —las cookies del login del API le responden 401— y su
 * pantalla de ingreso usa **teclado virtual**, que es un control
 * anti-automatización y no se rodea.
 *
 * Las pantallas viejas (JSF) sí aceptan la sesión del API, igual que en
 * EnlaceInformeIndividualService, y todo el recorrido vive ahí. Por HTTP no hay
 * navegador, ni clave de portal, ni problema con la IP del servidor.
 *
 * ## El recorrido, probado el 17-sep-2026
 *
 *   1. login + aportante + autorización             → cookies de sesión
 *   2. GET  cargararchivo.xhtml                     → formulario `upload`
 *   3. POST multipart con el TXT y `btnvalidar`     → arranca el validador
 *   4. ajax `btnConsultar` hasta `estadoValidacion = true`
 *   5. POST `btnFinalizar`                          → pantalla de inconsistencias
 *   6. POST `bt_aceptarCorLinea` (formCorreccionLinea) → el puente al editor
 *   7. POST Continuar ×2 y `btn_guardarplanilla`    → planilla liquidada
 *
 * Resultado de la prueba: planilla **1085292497**, tipo N, período 202608,
 * $70.500 —la corrección de Solo EPS de HOMENURSE sobre la 1085268529 ya
 * pagada—, idéntica a la que se había armado a mano campo por campo.
 *
 * No paga: la deja GUARDADA y devuelve número y valor, igual que el paso 1.
 */
class PortalCorreccionSaludService
{
    /** Pantallas del portal. */
    private const CARGA = '/Web/faces/pages/planilla/validadorarchivos/archivos/cargararchivo.xhtml';

    private const ERRORES = '/Web/faces/pages/planilla/validadorarchivos/errores/errores.xhtml';

    private const LINEA = '/Web/faces/pages/planilla/moduloEnLinea/moduloenlinea.xhtml';

    /** Cuántas veces se le pregunta al validador si terminó, cada 3 segundos. */
    private const ESPERAS = 40;

    private Client $http;

    /** Cabeceras de la sesión del API, para cerrarla al terminar. */
    private array $cabeceras = [];

    /** Última pantalla del editor en línea, para salir de él al terminar. */
    private ?string $editor = null;

    public function __construct(
        private ?PlanoPilaTxtService $planos = null,
    ) {
        $this->planos ??= new PlanoPilaTxtService;
    }

    /**
     * Liquida la corrección y la deja guardada en el operador, sin pagar.
     *
     * @param  array  $llave  aliado_id, razon_social_id, operador_planilla_id, anio, mes, n_plano, tipos_modalidad
     * @return array{success: bool, numero_planilla?: string, valor_total?: float, message?: string}
     */
    public function liquidar(array $llave, OperadorPlanillaApi $paso1, string $fechaPago): array
    {
        $rs = RazonSocial::where('aliado_id', $llave['aliado_id'])->findOrFail($llave['razon_social_id']);
        $operador = \App\Models\OperadorPlanilla::findOrFail($llave['operador_planilla_id']);

        $credencial = OperadorCredencial::paraOperador(
            (int) $llave['aliado_id'],
            (int) $operador->id,
            (int) $rs->id
        )->first();

        if (! $credencial) {
            return ['success' => false, 'message' => "No hay credenciales de {$operador->nombre} para esta razón social."];
        }

        // El archivo es exactamente el del paso 2: lo que cambia es por dónde
        // entra, no qué dice.
        try {
            $plano = $this->planos->construir([
                'aliado_id' => $llave['aliado_id'],
                'razon_social_id' => $rs->id,
                'mes' => $llave['mes'],
                'anio' => $llave['anio'],
                'n_plano' => $llave['n_plano'],
                // En la llave del controlador el filtro viaja como texto
                // ("-12" o "-12,-5"); el generador del plano lo quiere en lista.
                'tipos_modalidad' => collect(explode(',', (string) ($llave['tipos_modalidad'] ?? '')))
                    ->map(fn ($id) => trim($id))
                    ->filter(fn ($id) => $id !== '' && is_numeric($id))
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all(),
                'codigo_operador' => (string) $operador->codigo_ni,
                'tipo_planilla' => 'N',
                'paso' => 2,
                'planilla_asociada' => [
                    'numero' => (string) $paso1->numero_planilla,
                    'fecha_pago' => $fechaPago,
                ],
            ]);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'No se pudo armar el archivo: '.$e->getMessage()];
        }

        $host = SuaporteApiService::hostDeOperador((string) $operador->codigo);

        try {
            $this->abrirSesion($host, $operador, $credencial, $rs);
            [$numero, $valor] = $this->subirYCorregir($host, $plano['contenido'], $plano['filename']);
        } catch (RuntimeException $e) {
            $this->cerrarSesion($host);

            Log::warning('Portal: la corrección de salud no liquidó', [
                'razon_social_id' => $rs->id,
                'planilla_base' => $paso1->numero_planilla,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => $e->getMessage()];
        }

        $this->cerrarSesion($host);

        // Queda como paso 2 de la tanda, igual que si hubiera salido por API:
        // así la pantalla de planos la muestra con su valor y el cuadre no
        // distingue de dónde vino.
        $registro = OperadorPlanillaApi::updateOrCreate(
            $llave + ['paso' => 2],
            [
                'estado' => 'validada',
                'numero_planilla' => $numero,
                'valor_total' => $valor,
                'mensaje_error' => null,
                'planilla_asociada_numero' => (string) $paso1->numero_planilla,
                'planilla_asociada_fecha_pago' => $fechaPago,
            ]
        );

        Log::info('Portal: corrección de salud liquidada', [
            'razon_social_id' => $rs->id,
            'planilla' => $numero,
            'valor' => $valor,
        ]);

        return [
            'success' => true,
            'numero_planilla' => $numero,
            'valor_total' => (float) $valor,
            'registro_id' => $registro->id,
        ];
    }

    /**
     * Login del API: deja las cookies con las que abren las pantallas viejas.
     *
     * Mismo recorrido de EnlaceInformeIndividualService. Sin la autorización
     * sobre el aportante, el portal responde como si la empresa no existiera.
     */
    private function abrirSesion(string $host, $operador, OperadorCredencial $credencial, RazonSocial $rs): void
    {
        $nit = preg_replace('/\D/', '', (string) $rs->nit);

        if ($nit === '') {
            throw new RuntimeException('La razón social no tiene NIT.');
        }

        $this->http = new Client([
            'cookies' => new CookieJar,
            'curl' => SuaporteApiService::opcionesCurlTls(),
            'timeout' => 120,
            'http_errors' => false,
            'headers' => ['User-Agent' => 'Mozilla/5.0', 'Accept-Language' => 'es-CO,es;q=0.9'],
        ]);

        // Con las credenciales completas para respetar el freno de claves
        // rechazadas: cada intento fallido cuenta para el bloqueo de la cuenta.
        $cifrador = new SuaporteApiService([
            'operador'      => $operador->codigo,
            'usuario'       => $credencial->usuario,
            'contrasena'    => (string) $credencial->contrasena,
            'clave_secreta' => (string) $credencial->clave_secreta,
        ]);

        if ($freno = $cifrador->mensajeFreno()) {
            throw new RuntimeException($freno);
        }

        $contrasena = $cifrador->cifrarDato((string) $credencial->contrasena)
            ?? throw new RuntimeException('No se pudo cifrar la contraseña del operador.');

        $login = $this->http->post("{$host}/auth/login", [
            'headers' => ['clave-secreta' => $credencial->clave_secreta],
            'json' => [
                'usuario' => SuaporteApiService::usuarioPortal($credencial->usuario),
                'contrasena' => $contrasena,
            ],
        ]);

        $exitoso = $login->getStatusCode() === 200 && $login->getHeaderLine('token');
        $cifrador->anotarLogin((bool) $exitoso, (string) $login->getBody());

        if (! $exitoso) {
            throw new RuntimeException('El operador rechazó el inicio de sesión.');
        }

        $headers = [];
        foreach (['token', 'refresh-token', 'refresh-token-ttl', 'refresh-token-date', 'faces'] as $nombre) {
            $headers[$nombre] = $login->getHeaderLine($nombre);
        }
        $this->cabeceras = $headers;

        $aportante = $this->http->get("{$host}/api/gestion/aportante/NI/{$nit}", ['headers' => $headers]);
        $idAportante = json_decode((string) $aportante->getBody(), true)['id'] ?? null;

        if (! $idAportante) {
            throw new RuntimeException("El aportante NI {$nit} no existe en el operador.");
        }

        $autorizacion = $this->http->get("{$host}/api/gestion/authorization/user/contributor", [
            'headers' => $headers,
            'query' => ['id' => $idAportante, 'tipoIdentificacion' => 'NI', 'numeroIdentificacion' => $nit],
        ]);

        if ($autorizacion->getStatusCode() !== 200) {
            throw new RuntimeException("El usuario del operador no tiene permisos sobre el aportante {$nit}.");
        }
    }

    /**
     * Sube el archivo, cruza el puente al editor y guarda la planilla.
     *
     * @return array{0: string, 1: float} número y valor de la corrección
     */
    private function subirYCorregir(string $host, string $contenido, string $nombreArchivo): array
    {
        $carga = $host.self::CARGA;
        $html = (string) $this->http->get($carga)->getBody();

        if (! str_contains($html, 'id="myFile"')) {
            throw new RuntimeException('El portal no abrió la pantalla de carga de archivo.');
        }

        // ── 1. El archivo ────────────────────────────────────────────────
        //
        // El "Continuar" de la pantalla no envía nada: su JavaScript marca
        // `inicioValidacion` y dispara `btnvalidar`, que es el submit que
        // lleva el archivo. Mandarlo con `btnCargarArchivo` —el botón que se
        // ve— sube el archivo a la nada y el validador nunca arranca.
        $partes = [];
        foreach ($this->camposDe($html, 'upload') as $nombre => $valor) {
            $partes[] = ['name' => $nombre, 'contents' => (string) $valor];
        }

        $partes[] = ['name' => 'selectActivosPensionados', 'contents' => 'I'];   // cotizantes activos
        $partes[] = ['name' => 'inicioValidacion', 'contents' => 'true'];
        $partes[] = ['name' => 'errorValidacionCarga', 'contents' => 'false'];
        $partes[] = ['name' => 'myFile', 'contents' => $contenido, 'filename' => $nombreArchivo,
            'headers' => ['Content-Type' => 'text/plain']];
        $partes[] = ['name' => 'btnvalidar', 'contents' => 'btnvalidar'];

        $html = (string) $this->http->post($this->accionDe($html, 'upload', $host), [
            'multipart' => $partes,
            'headers' => ['Referer' => $carga],
        ])->getBody();

        // ── 2. Esperar al validador ──────────────────────────────────────
        $viewState = $this->viewStateDe($html, 'upload');
        $listo = false;

        for ($i = 0; $i < self::ESPERAS && ! $listo; $i++) {
            sleep(3);

            $xml = (string) $this->http->post($carga, [
                'headers' => ['Faces-Request' => 'partial/ajax', 'Referer' => $carga],
                'form_params' => [
                    'upload' => 'upload',
                    'javax.faces.ViewState' => $viewState,
                    'javax.faces.source' => 'btnConsultar',
                    'javax.faces.partial.execute' => '@all',
                    'javax.faces.partial.render' => 'errorValidacionCarga estadoValidacion porcentajeValidacion mensajeDeErrorParaUsuario',
                    'javax.faces.partial.ajax' => 'true',
                    'javax.faces.partial.event' => 'click',
                    'javax.faces.behavior.event' => 'action',
                ],
            ])->getBody();

            if (preg_match('/ViewState[^>]*><!\[CDATA\[([^\]]+)\]\]>/', $xml, $v)) {
                $viewState = $v[1];
            }

            $listo = (bool) preg_match('/id="estadoValidacion"[^>]*value="true"/', $xml);

            if (preg_match('/id="mensajeDeErrorParaUsuario"[^>]*value="([^"]+)"/', $xml, $m) && trim($m[1]) !== '') {
                throw new RuntimeException('El operador rechazó el archivo: '.html_entity_decode($m[1]));
            }
        }

        if (! $listo) {
            throw new RuntimeException('El validador del operador no terminó a tiempo.');
        }

        // ── 3. A la pantalla de inconsistencias ──────────────────────────
        $campos = $this->camposDe($html, 'upload');
        $campos['inicioValidacion'] = 'false';
        $campos['selectActivosPensionados'] = 'I';

        $errores = (string) $this->http->post($carga, [
            'form_params' => $campos + ['upload' => 'upload', 'btnFinalizar' => 'btnFinalizar'],
            'headers' => ['Referer' => $carga],
        ])->getBody();

        // ── 4. El puente: del archivo rechazado al editor en línea ───────
        //
        // Las dos inconsistencias del cotejo entre subsistemas son las
        // esperadas; si no aparece el botón del puente, el rechazo fue por otra
        // cosa y eso sí hay que leerlo.
        if (! str_contains($errores, 'formCorreccionLinea')) {
            throw new RuntimeException('El portal no ofreció la corrección en línea. '.$this->inconsistencias($errores));
        }

        $editor = (string) $this->http->post($host.self::ERRORES, [
            'form_params' => [
                'formCorreccionLinea' => 'formCorreccionLinea',
                'bt_aceptarCorLinea' => 'bt_aceptarCorLinea',
                'javax.faces.ViewState' => $this->viewStateDe($errores, 'formCorreccionLinea'),
            ],
            'headers' => ['Referer' => $host.self::ERRORES],
        ])->getBody();

        // ── 5. Continuar y guardar ───────────────────────────────────────
        $linea = $host.self::LINEA;

        foreach (['btn_siguiente_datoplanilla', 'btn_siguiente_listaCotizante', 'btn_guardarplanilla'] as $boton) {
            $editor = (string) $this->http->post($linea, [
                'form_params' => $this->camposDe($editor, 'formPlanillaenlinea')
                    + ['formPlanillaenlinea' => 'formPlanillaenlinea', $boton => $boton],
                'headers' => ['Referer' => $linea],
            ])->getBody();
        }

        $this->editor = $editor;
        $numero = $this->numeroDe($editor);

        if ($numero === null) {
            throw new RuntimeException('El portal no devolvió el número de la corrección.');
        }

        return [$numero, $this->totalDe($editor)];
    }

    /** Los campos de un formulario, tal como los mandaría el navegador. */
    private function camposDe(string $html, string $form): array
    {
        $xpath = $this->xpath($html);
        $campos = [];

        foreach ($xpath->query("//form[@id='{$form}']//input|//form[@id='{$form}']//select") as $campo) {
            $nombre = $campo->getAttribute('name');
            $tipo = strtolower($campo->getAttribute('type'));

            if ($nombre === '' || in_array($tipo, ['file', 'image', 'submit', 'button'], true)) {
                continue;
            }

            if (in_array($tipo, ['radio', 'checkbox'], true)) {
                if ($campo->hasAttribute('checked')) {
                    $campos[$nombre] = $campo->getAttribute('value') ?: 'on';
                }

                continue;
            }

            if ($campo->tagName === 'select') {
                $opcion = $xpath->query('.//option[@selected]', $campo)->item(0)
                    ?? $xpath->query('.//option', $campo)->item(0);
                $campos[$nombre] = $opcion?->getAttribute('value') ?? '';

                continue;
            }

            $campos[$nombre] = $campo->getAttribute('value');
        }

        return $campos;
    }

    /** La URL del formulario: trae el `jsessionid` y sin él se pierde la sesión. */
    private function accionDe(string $html, string $form, string $host): string
    {
        $accion = (string) $this->xpath($html)->query("//form[@id='{$form}']")->item(0)?->getAttribute('action');

        return str_starts_with($accion, 'http') ? $accion : $host.$accion;
    }

    private function viewStateDe(string $html, string $form): string
    {
        return (string) $this->xpath($html)
            ->query("//form[@id='{$form}']//input[@name='javax.faces.ViewState']")
            ->item(0)?->getAttribute('value');
    }

    /**
     * El número de la corrección recién guardada.
     *
     * El portal lo deja en tres sitios y no siempre en los tres: el aviso verde
     * ("La planilla fue guardada exitosamente con el número de planilla: …"),
     * el campo oculto `numeroPlanilla` de la pantalla de totales, y la ficha de
     * datos. Se buscan los tres porque el primero que aparezca depende de en
     * qué pestaña quedó el editor, y quedarse con uno solo fue lo que hizo
     * fallar la primera corrección automática del 17-sep-2026 —la planilla
     * 1085294570 sí se creó, pero BryNex la dio por perdida—.
     */
    private function numeroDe(string $html): ?string
    {
        $texto = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(
            preg_replace('#<script.*?</script>#is', '', $html)
        )));

        if (preg_match('/n[úu]mero de planilla:?\s*(\d{6,})/iu', $texto, $m)) {
            return $m[1];
        }

        $xpath = $this->xpath($html);

        foreach (['numeroPlanilla', 'numeroPlanillaGenerada'] as $id) {
            $valor = (string) $xpath->query("//input[@id='{$id}']")->item(0)?->getAttribute('value');

            if (preg_match('/^\d{6,}$/', $valor)) {
                return $valor;
            }
        }

        return null;
    }

    /**
     * Sale del editor y cierra la sesión, como lo haría una persona.
     *
     * Sin esto Simple deja la planilla "en uso" por el usuario del robot hasta
     * que la sesión vence, y nadie más puede pagarla ni borrarla: "no es
     * posible procesar su solicitud debido a que el usuario ... está
     * trabajando en la planilla" (17-sep-2026, más de 30 minutos bloqueada).
     *
     * Salir del editor es `canceladorPlanilla` (su aviso: "si la planilla no se
     * ha guardado, todos los datos se perderán"; aquí ya está guardada). El
     * cierre de sesión es el mismo que usa el menú de Simple: DELETE
     * /auth/session. Nunca tumba la liquidación: si falla, queda en el log.
     */
    private function cerrarSesion(string $host): void
    {
        if (! isset($this->http)) {
            return;
        }

        try {
            if ($this->editor && str_contains($this->editor, 'canceladorPlanilla')) {
                $this->http->post($host.self::LINEA, [
                    'form_params' => $this->camposDe($this->editor, 'formPlanillaenlinea')
                        + ['formPlanillaenlinea' => 'formPlanillaenlinea', 'canceladorPlanilla' => 'canceladorPlanilla'],
                    'headers' => ['Referer' => $host.self::LINEA],
                ]);
            }

            $salida = $this->http->delete("{$host}/auth/session", ['headers' => $this->cabeceras]);

            if ($salida->getStatusCode() >= 300) {
                Log::warning('PortalCorreccionSalud: el cierre de sesión no respondió bien', [
                    'status' => $salida->getStatusCode(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('PortalCorreccionSalud: no se pudo cerrar la sesión', ['error' => $e->getMessage()]);
        } finally {
            $this->editor = null;
            $this->cabeceras = [];
        }
    }

    /** Total a pagar de la planilla recién guardada. */
    private function totalDe(string $html): float
    {
        $texto = preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(
            preg_replace('#<script.*?</script>#is', '', $html)
        )));

        if (preg_match('/Total a pagar[^0-9]{0,40}([\d.]+)/i', $texto, $m)) {
            return (float) str_replace('.', '', $m[1]);
        }

        // Pantalla de totales tras guardar (visto el 17-sep-2026): la fila
        // "Subtotales" trae valor sin mora, mora y total, en formato
        // "$70.100,00 $500,00 $70.600,00". Si el total no viene, se suman.
        $monto = '\$\s*([\d.]+),\d{2}';
        if (preg_match("/Subtotales\s*{$monto}\s*{$monto}(?:\s*{$monto})?/u", $texto, $m)) {
            $num = fn ($v) => (float) str_replace('.', '', $v);

            return ! empty($m[3]) ? $num($m[3]) : $num($m[1]) + $num($m[2]);
        }

        // No se sabe aún cómo pinta Simple el total tras guardar: se deja
        // rastro de lo que dice cerca de "total" para ajustar el patrón. El
        // valor queda en cero y la pantalla deja escribirlo al confirmar.
        preg_match_all('/.{0,60}total.{0,80}/iu', $texto, $cerca);
        \Illuminate\Support\Facades\Log::warning('PortalCorreccionSalud: no se leyó el total', [
            'cerca_de_total' => array_slice($cerca[0] ?? [], 0, 8),
        ]);

        return 0.0;
    }

    /** Lo que reclamó el validador, para que el error que llegue a BryNex diga algo. */
    private function inconsistencias(string $html): string
    {
        if (preg_match_all('/([A-ZÁÉÍÓÚÑ][^<>]{20,140}(?:deben ser iguales|no es v[áa]lid[oa])[^<>]{0,60})/u', $html, $m)) {
            return implode(' | ', array_slice(array_unique($m[1]), 0, 3));
        }

        return 'El portal no explicó el rechazo.';
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }
}
