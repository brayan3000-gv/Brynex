<?php

namespace App\Services\MiPlanilla;

use App\Models\ClaveAcceso;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\RedirectMiddleware;

/**
 * Robot del portal de independientes de Mi Planilla
 * (independientes2.miplanilla.com). Mi Planilla no tiene API: se entra por
 * HTTP con la cuenta de cada independiente, que en Mi Planilla es su propio
 * aportante, así que cada persona tiene su usuario (CC + cédula) y su clave.
 * Las claves viven en `clave_accesos`, por cédula (entidad MI PLANILLA).
 *
 * ## El login (29-sep-2026)
 *
 * Formulario ASP.NET MVC sin captcha: GET de la página pública para el
 * `__RequestVerificationToken` (y su cookie), y POST con `usuario`, `clave`
 * y el token. Los campos ocultos `MPuser`, `UserName` y `UserPass` son una
 * trampa para robots: si llegan con algo el portal descarta el envío, así que
 * van vacíos, como los manda cualquier navegador.
 *
 * Adentro todo cuelga de /PrivadoIndependientes/. Ver la memoria del proyecto
 * «miplanilla-portal-sin-api» para el recorrido de las correcciones.
 */
class MiPlanillaPortalService
{
    public const BASE = 'https://independientes2.miplanilla.com';

    private const LOGIN = '/PublicoIndependientes/Publico/IndexIndependientes';

    private Client $http;

    private CookieJar $cookies;

    private bool $adentro = false;

    public function __construct(private string $usuario, private string $clave)
    {
        $this->cookies = new CookieJar;

        $opciones = [
            'base_uri' => self::BASE,
            'cookies' => $this->cookies,
            'timeout' => 45,
            'connect_timeout' => 15,
            'http_errors' => false,
            'allow_redirects' => ['max' => 10, 'track_redirects' => true],
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36',
                'Accept-Language' => 'es-CO,es;q=0.9',
            ],
        ];

        // Desde netcup se sale por el proxy colombiano (ver PROXY_COLOMBIA).
        if ($proxy = config('services.proxy_colombia.url')) {
            $opciones['proxy'] = $proxy;
        }

        $this->http = new Client($opciones);
    }

    /**
     * El robot de una persona, con su clave guardada en BryNex.
     *
     * @throws \RuntimeException si no tiene clave de Mi Planilla
     */
    public static function paraCedula(int $aliadoId, string $cedula, ?string $tipoDoc = null): self
    {
        $clave = self::clave($aliadoId, $cedula);

        if (! $clave) {
            throw new \RuntimeException("La cédula {$cedula} no tiene clave de Mi Planilla guardada en BryNex.");
        }

        return new self(self::usuarioPortal((string) $clave->usuario, $cedula, $tipoDoc), (string) $clave->contrasena);
    }

    /**
     * El usuario del portal es tipo y número de documento pegados (CC15817622).
     * En Claves muchas quedaron con el número solo ("10385420"), y así el
     * portal no deja entrar: se le antepone el tipo.
     */
    public static function usuarioPortal(string $usuario, string $cedula, ?string $tipoDoc = null): string
    {
        $usuario = strtoupper(preg_replace('/\s+/', '', $usuario));
        $tipo = strtoupper(trim((string) $tipoDoc)) ?: 'CC';

        if ($usuario === '') {
            return $tipo.$cedula;
        }

        return ctype_digit($usuario) ? $tipo.$usuario : $usuario;
    }

    /** ¿La persona tiene su clave de Mi Planilla guardada en BryNex? */
    public static function tieneClave(int $aliadoId, string $cedula): bool
    {
        return self::clave($aliadoId, $cedula) !== null;
    }

    private static function clave(int $aliadoId, string $cedula): ?ClaveAcceso
    {
        $clave = ClaveAcceso::where('aliado_id', $aliadoId)
            ->where('cedula', $cedula)
            ->where('entidad', 'like', '%PLANILLA%')
            ->where('activo', true)
            ->orderByDesc('updated_at')
            ->first(['usuario', 'contrasena']);

        return $clave && trim((string) $clave->contrasena) !== '' ? $clave : null;
    }

    /**
     * @throws \RuntimeException con el mensaje del portal si no deja entrar
     */
    public function login(): void
    {
        $pagina = (string) $this->http->get(self::LOGIN)->getBody();
        $token = $this->token($pagina);
        if (! $token) {
            throw new \RuntimeException('Mi Planilla no entregó el formulario de ingreso (sin token).');
        }

        $resp = $this->http->post(self::LOGIN, [
            'form_params' => [
                '__RequestVerificationToken' => $token,
                'usuario' => $this->usuario,
                'clave' => $this->clave,
                // Trampa para robots: tienen que ir vacíos.
                'MPuser' => '',
                'UserName' => '',
                'UserPass' => '',
            ],
            'headers' => ['Referer' => self::BASE.self::LOGIN],
        ]);

        $destino = $this->urlFinal($resp);
        if (! str_contains($destino, '/PrivadoIndependientes/')) {
            throw new \RuntimeException('Mi Planilla no dejó entrar: '.($this->mensajeError((string) $resp->getBody()) ?: 'usuario o clave no aceptados.'));
        }

        $this->adentro = true;
    }

    /** GET dentro de la sesión; devuelve el HTML (o JSON) tal cual. */
    public function get(string $ruta): string
    {
        return (string) $this->http->get($ruta)->getBody();
    }

    /**
     * Planillas de la persona según el tablero: las que esperan pago y las
     * pagadas (número, período, valor, estado, fechas). Son las mismas
     * llamadas que hace el tablero del portal.
     *
     * @return array{disponibles: array, pagadas: array}
     */
    public function planillas(): array
    {
        $principal = $this->get('/PrivadoIndependientes/Principal');

        // Los nombres de los métodos están ofuscados en el script del tablero y
        // pueden cambiar con cada versión: se leen de ahí en cada corrida.
        $js = preg_match('~src="(/PrivadoIndependientes/Site/Vue/Dashboard[^"]*)"~', $principal, $m)
            ? $this->get(html_entity_decode($m[1]))
            : '';
        $disponibles = preg_match('~sr\("(__\w+)","PlanillaAPI",\{pagina~', $js, $a) ? $a[1] : null;
        $pagadas = preg_match('~sr\("(__\w+)","PlanillaAPI",\{bloque~', $js, $b) ? $b[1] : null;
        if (! $disponibles || ! $pagadas) {
            throw new \RuntimeException('No se encontraron las llamadas del tablero de Mi Planilla (¿cambió el portal?).');
        }

        return [
            'disponibles' => $this->api($principal, $disponibles, ['pagina' => 1]),
            'pagadas' => $this->api($principal, $pagadas, ['bloque' => 1]),
        ];
    }

    /**
     * Las personas que el portal pondría en una planilla del tipo y período
     * dados (lo que pinta "Generar planilla" al escoger tipo y período). Solo
     * lectura: devuelve el HTML parcial del portal.
     */
    public function cotizantesPlanilla(string $periodo, string $tipoPlanilla = 'I'): string
    {
        $generar = $this->get('/PrivadoIndependientes/Planilla/GenerarPlanilla');
        $idEmpresa = preg_match('~id="IdEmpresa"[^>]*value="(\d+)"~', $generar, $m) ? $m[1] : '';
        $fecha = \Carbon\Carbon::parse($periodo);
        $meses = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

        return (string) $this->http->get('/PrivadoIndependientes/Planilla/CotizantesPlanilla', [
            'query' => [
                'IdEmpresa' => $idEmpresa,
                'TipoPlanilla' => $tipoPlanilla,
                'Periodo' => $fecha->format('Y-m-d'),
                'DescripcionPeriodo' => $meses[$fecha->month - 1].' de '.$fecha->year,
                'NumeroRegistrosPaginado' => 5,
                'NumeroPagina' => 1,
            ],
            'headers' => ['X-Requested-With' => 'XMLHttpRequest'],
        ])->getBody();
    }

    /**
     * PDF de una planilla pagada, el mismo que baja el enlace del tablero.
     *
     * @return string contenido del PDF
     *
     * @throws \RuntimeException si no está entre las pagadas o no llega un PDF
     */
    public function pdfPagada(string $numero, ?callable $paso = null): string
    {
        $paso ??= fn () => null;
        $paso('Buscando la planilla');
        $planilla = $this->pagada($numero);

        // La fecha va como la arma el portal (getDateTohref): MM/dd/yyyy H:m:s en hora de Colombia.
        $f = self::fecha($planilla['FechaPlanilla']);
        $fecha = sprintf('%02d/%02d/%d %d:%d:%d', $f->month, $f->day, $f->year, $f->hour, $f->minute, $f->second);

        $paso('Descargando el PDF');
        $resp = $this->http->get('/PrivadoIndependientes/Planilla/GenerarPDF', ['query' => [
            'planillaId' => $planilla['IdPlanilla'],
            'fechaPlanilla' => $fecha,
            'numeroRadicado' => $numero,
            'origen' => 'PlanillasPagadas',
        ]]);

        $pdf = (string) $resp->getBody();
        if (! str_starts_with($pdf, '%PDF')) {
            throw new \RuntimeException("Mi Planilla no devolvió el PDF de la planilla {$numero}.");
        }

        return $pdf;
    }

    /** La planilla pagada con ese número, tal como la lista el tablero. */
    private function pagada(string $numero): array
    {
        $pagadas = collect($this->planillas()['pagadas']);
        $planilla = $pagadas->first(fn ($p) => (string) ($p['NumeroRadicado'] ?? '') === $numero);
        if (! $planilla) {
            // Con lo que sí trae se ve si es otra cuenta o una planilla vieja que
            // no está en el primer bloque del tablero.
            $vistas = $pagadas->pluck('NumeroRadicado')->filter()->take(6)->implode(', ');
            throw new \RuntimeException("La planilla {$numero} no está entre las pagadas de la cuenta {$this->usuario} en Mi Planilla"
                .($vistas !== '' ? " (aparecen: {$vistas})." : ' (la cuenta no muestra ninguna pagada).'));
        }

        return $planilla;
    }

    /**
     * El «Reporte resumen de pago» de una planilla pagada: medio de pago (PSE),
     * banco, número de autorización, estado de la transacción y total por
     * administradora. Mapeado el 5-oct-2026 con la 95087579 de Casimiro.
     *
     * El portal no lo entrega de una: «Planillas Pagadas → Opciones → Resumen
     * de pago → Guardar en PDF» llama GenerarReportePago, que responde "OK" y
     * deja el archivo en Históricos como `Resumen_de_pago<n>.pdf.zip` (tardó
     * menos de 8 s). De ahí se baja el ZIP y se saca el PDF. Si ya había uno
     * disponible de antes, se usa ese sin pedir otro.
     *
     * @throws \RuntimeException con el motivo si no se pudo
     */
    public function resumenPagoPdf(string $numero, ?callable $paso = null): string
    {
        $paso ??= fn () => null;
        $nombre = "Resumen_de_pago{$numero}.pdf";

        $paso('Buscando el resumen de pago');
        if ($url = $this->historicoDisponible($nombre)) {
            $paso('Descargando el PDF');

            return $this->pdfDelZip($this->get($url), $nombre);
        }

        $planilla = $this->pagada($numero);

        // `fechaPlanilla` va en ticks de .NET, como GetDateTicks() del portal.
        $ms = (int) preg_replace('~\D~', '', (string) $planilla['FechaPlanilla']);
        [$tipoDoc, $documento] = preg_match('/^([A-Z]+)(\d+)$/', $this->usuario, $m) ? [$m[1], $m[2]] : ['CC', preg_replace('/\D/', '', $this->usuario)];
        $resumen = $this->get('/PrivadoIndependientes/Pagos/ResumenPago?'.http_build_query([
            'idPlanilla' => $planilla['IdPlanilla'],
            'fechaPlanilla' => (string) ($ms * 10000 + 621355968000000000),
            'ref1' => '',
            'ref2' => $tipoDoc,
            'ref3' => $documento,
        ]));

        if (! preg_match("~GenerarCertificado\('([^']+)','([^']+)','false'\)~", $resumen, $g)) {
            throw new \RuntimeException("Mi Planilla no abrió el resumen de pago de la planilla {$numero}.");
        }

        $paso('Pidiéndole el PDF a Mi Planilla');
        $ok = $this->get('/PrivadoIndependientes/Pagos/GenerarReportePago?'.http_build_query([
            'idPlanilla' => $g[1], 'fechaPlanilla' => $g[2], 'esExcel' => 'false',
        ]));
        if (! str_contains($ok, 'OK')) {
            throw new \RuntimeException('Mi Planilla no aceptó generar el resumen de pago.');
        }

        $paso('Esperando que Mi Planilla lo genere');
        for ($i = 0; $i < 20; $i++) {
            sleep(2);
            if ($url = $this->historicoDisponible($nombre)) {
                $paso('Descargando el PDF');

                return $this->pdfDelZip($this->get($url), $nombre);
            }
        }

        throw new \RuntimeException('Mi Planilla no terminó de generar el resumen de pago a tiempo: reintenta en un minuto.');
    }

    /** El enlace de descarga del primer archivo «Disponible» con ese nombre en Históricos. */
    private function historicoDisponible(string $nombre): ?string
    {
        $html = $this->get('/PrivadoIndependientes/Certificados/HistoricoReporte');
        preg_match_all(
            '~Nombre Archivo\s*<strong[^>]*>([^<]+)</strong>.*?<label>([^<]+)</label>.*?href="(/PrivadoIndependientes/Certificados/AbrirArchivoHistorico\?[^"]+)"~s',
            $html, $items, PREG_SET_ORDER
        );

        foreach ($items as [, $archivo, $estado, $url]) {
            if (str_starts_with(trim($archivo), $nombre) && stripos($estado, 'Disponible') !== false) {
                return html_entity_decode($url);
            }
        }

        return null;
    }

    private function pdfDelZip(string $zip, string $nombre): string
    {
        if (str_starts_with($zip, '%PDF')) {
            return $zip;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'mpzip');
        try {
            file_put_contents($tmp, $zip);
            $archivo = new \ZipArchive;
            if ($archivo->open($tmp) !== true) {
                throw new \RuntimeException("Mi Planilla no entregó un archivo válido para {$nombre}.");
            }
            $pdf = $archivo->getFromName($nombre) ?: $archivo->getFromIndex(0);
            $archivo->close();
        } finally {
            @unlink($tmp);
        }

        if (! is_string($pdf) || ! str_starts_with($pdf, '%PDF')) {
            throw new \RuntimeException("El archivo de Mi Planilla no trae el PDF {$nombre}.");
        }

        return $pdf;
    }

    /**
     * Genera EN LÍNEA la planilla I del período con las personas que el portal
     * tiene guardadas para la cuenta. Se detiene antes de validar: devuelve la
     * URL y el HTML a los que llega el portal.
     *
     * @return array{url: string, html: string, cotizantes: int}
     */
    public function generarEnLinea(string $periodo, array $soloDocumentos = []): array
    {
        $generar = $this->get('/PrivadoIndependientes/Planilla/GenerarPlanilla');
        $form = $this->camposFormulario($generar, 'formGenerarPlanilla');
        $parcial = $this->cotizantesPlanilla($periodo);
        $cotizantes = $this->camposSueltos($parcial);

        // El portal propone a todas las personas de la cuenta (el independiente y
        // sus beneficiarios de UPC), pero cada una se paga por su contrato en
        // BryNex: solo van las que se liquidan. Si una ya está pagada en el
        // período, el portal rechaza toda la planilla ("El cotizante se
        // encuentra en la planilla X para el mismo periodo").
        if ($soloDocumentos) {
            $lista = json_decode($cotizantes['Cotizantes'] ?? '[]', true) ?: [];
            $lista = array_values(array_filter($lista, fn ($c) => in_array((string) ($c['NumeroDocumento'] ?? ''), $soloDocumentos, true)));
            $cotizantes['Cotizantes'] = json_encode($lista, JSON_UNESCAPED_UNICODE);
            $cotizantes['pnlNumeroCotizantes'] = (string) count($lista);
        }

        if ((int) ($cotizantes['pnlNumeroCotizantes'] ?? 0) === 0) {
            throw new \RuntimeException('El portal no trae a esas personas para esa planilla.');
        }

        $fecha = \Carbon\Carbon::parse($periodo)->format('Y-m-d');
        $campos = array_merge($form, $cotizantes, [
            'filtros' => 'I',
            'TipoPlanilla' => 'I',
            'generar' => 'enlinea',
            'PeriodoPension' => $fecha,
            'Periodo' => $fecha,
        ]);

        $multipart = [];
        foreach ($campos as $nombre => $valor) {
            $multipart[] = ['name' => $nombre, 'contents' => (string) $valor];
        }
        // El formulario siempre manda el campo del archivo, vacío en línea.
        $multipart[] = ['name' => 'file', 'contents' => '', 'filename' => ''];

        $resp = $this->http->post('/PrivadoIndependientes/Planilla/GenerarPlanilla', [
            'multipart' => $multipart,
            'headers' => ['Referer' => self::BASE.'/PrivadoIndependientes/Planilla/GenerarPlanilla'],
        ]);

        return [
            'url' => $this->urlFinal($resp),
            'html' => (string) $resp->getBody(),
            'cotizantes' => (int) $cotizantes['pnlNumeroCotizantes'],
        ];
    }

    /**
     * Sube un archivo plano PILA por "Cargar archivo plano" (planilla I). Se
     * detiene donde lo deje el portal: devuelve la URL y el HTML.
     *
     * @return array{url: string, html: string}
     */
    public function subirArchivo(string $contenido, string $nombreArchivo): array
    {
        $generar = $this->get('/PrivadoIndependientes/Planilla/GenerarPlanilla');
        $campos = array_merge($this->camposFormulario($generar, 'formGenerarPlanilla'), [
            'filtros' => 'I',
            'TipoPlanilla' => 'I',
            'generar' => 'archivo',
        ]);

        $multipart = [];
        foreach ($campos as $nombre => $valor) {
            $multipart[] = ['name' => $nombre, 'contents' => (string) $valor];
        }
        $multipart[] = ['name' => 'file', 'contents' => $contenido, 'filename' => $nombreArchivo, 'headers' => ['Content-Type' => 'text/plain']];

        $resp = $this->http->post('/PrivadoIndependientes/Planilla/GenerarPlanilla', [
            'multipart' => $multipart,
            'headers' => ['Referer' => self::BASE.'/PrivadoIndependientes/Planilla/GenerarPlanilla'],
        ]);

        return ['url' => $this->urlFinal($resp), 'html' => (string) $resp->getBody()];
    }

    /**
     * Las planillas sin pagar como las pinta "Administrar planillas": las que
     * están en generación (borradores) y las disponibles para pago. De cada
     * elemento con datos de planilla se devuelven sus atributos data-*.
     *
     * @return array{generacion: string, pendientes: string, items: array<int, array<string, string>>}
     */
    public function sinPagar(): array
    {
        $this->get('/PrivadoIndependientes/Planilla/AdministrarPlanillas');
        $ajax = ['X-Requested-With' => 'XMLHttpRequest', 'Referer' => self::BASE.'/PrivadoIndependientes/Planilla/AdministrarPlanillas'];

        $generacion = (string) $this->http->post('/PrivadoIndependientes/Planilla/Recargar', ['headers' => $ajax])->getBody();
        $pendientes = (string) $this->http->post('/PrivadoIndependientes/Planilla/paginarPlanillasPendientesPago', [
            'form_params' => ['Parametro' => '', 'Valor' => '', 'Pagina' => 1],
            'headers' => $ajax,
        ])->getBody();

        $items = [];
        foreach (['generacion' => $generacion, 'pendientes' => $pendientes] as $origen => $html) {
            preg_match_all('~<[a-z]+\b[^>]*\bdata-(?:id|planillaactiva)="[^"]+"[^>]*>~i', $html, $tags);
            foreach ($tags[0] as $tag) {
                preg_match_all('~\bdata-([a-z0-9\-]+)="([^"]*)"~i', $tag, $attrs, PREG_SET_ORDER);
                $item = ['origen' => $origen];
                foreach ($attrs as [, $k, $v]) {
                    $item[strtolower($k)] = html_entity_decode($v, ENT_QUOTES);
                }
                $items[] = $item;
            }
        }

        return ['generacion' => $generacion, 'pendientes' => $pendientes, 'items' => $items];
    }

    /** La pantalla de edición de una planilla sin pagar (donde está "Validar planilla"). */
    public function editarPlanilla(string $idPlanilla, string $fechaPlanilla): string
    {
        return (string) $this->http->get('/PrivadoIndependientes/Planilla/EditarPlanilla', [
            'query' => ['idPlanilla' => $idPlanilla, 'fechaPlanilla' => $fechaPlanilla],
        ])->getBody();
    }

    /**
     * Borra una planilla sin pagar (el botón Eliminar de "Administrar planillas").
     * Como el navegador: primero pide la ventana de confirmación (GetPopUpPlanilla
     * con la acción DEL) y después el borrado.
     *
     * @return array{popup: string, respuesta: string}
     */
    public function borrarPlanilla(string $idPlanilla, string $fechaPlanilla, string $idLog = ''): array
    {
        $pagina = $this->get('/PrivadoIndependientes/Planilla/AdministrarPlanillas');
        $token = $this->token($pagina);
        $headers = array_filter([
            'X-Requested-With' => 'XMLHttpRequest',
            'Referer' => self::BASE.'/PrivadoIndependientes/Planilla/AdministrarPlanillas',
            'RequestVerificationToken' => $token,
        ]);

        $popup = (string) $this->http->post('/PrivadoIndependientes/Planilla/GetPopUpPlanilla', [
            'form_params' => ['Action' => 'DEL'],
            'headers' => $headers,
        ])->getBody();

        $respuesta = (string) $this->http->post('/PrivadoIndependientes/Planilla/BorrarPlanillaNew', [
            'form_params' => array_filter([
                'idPlanilla' => $idPlanilla,
                'fechaPlanilla' => $fechaPlanilla,
                'idLog' => $idLog,
                '__RequestVerificationToken' => $token,
            ], fn ($v) => $v !== null),
            'headers' => $headers,
        ])->getBody();

        return ['popup' => $popup, 'respuesta' => $respuesta];
    }

    /**
     * Deja en la planilla solo a las personas pedidas y finaliza la edición,
     * como se hace a mano en "Editar planilla": Borrar a cada persona que no va
     * (ventana de confirmación y "Sí, continuar") y "Finalizar edición", que
     * vuelve a validar. El portal propone siempre a todas las personas de la
     * cuenta y no deja quitarlas al generar.
     *
     * @param  string  $fechaPlanilla  MM/dd/yyyy HH:mm:ss (como la lee el portal)
     * @return array{quitadas: string[], html: string, url: string}
     */
    public function depurarYFinalizar(string $idPlanilla, string $fechaPlanilla, array $soloDocumentos): array
    {
        $editar = $this->editarPlanilla($idPlanilla, $fechaPlanilla);
        preg_match_all("~ModalBorrarEmpleadoPlanilla\\('([^']+)', '([^']+)', '([^']+)', '([^']+)'\\)~", $editar, $personas, PREG_SET_ORDER);
        if (! $personas) {
            throw new \RuntimeException('La pantalla de edición no trae personas (¿la planilla sigue validándose?).');
        }

        $quitadas = [];
        foreach ($personas as [, $idP, $fechaP, $tipoDoc, $doc]) {
            if (in_array($doc, $soloDocumentos, true)) {
                continue;
            }
            $query = ['idPlanilla' => $idP, 'fechaPlanilla' => $fechaP, 'tipoDocumento' => $tipoDoc, 'numeroDocumento' => $doc];
            $popup = $this->get('/PrivadoIndependientes/Planilla/PopupBorrarEmpleadoPlanillaEditarPlanilla?'.http_build_query($query));
            if (! preg_match('~href="(/PrivadoIndependientes/Planilla/EditarPlanillaBorrarEmpleadoPlanilla\?[^"]+)"~', $popup, $m)) {
                throw new \RuntimeException("No apareció la confirmación para quitar a {$tipoDoc} {$doc}.");
            }
            $this->get(html_entity_decode($m[1]));
            $quitadas[] = "{$tipoDoc} {$doc}";
        }

        if (count($quitadas) === count($personas)) {
            throw new \RuntimeException('Se quitaría a todas las personas de la planilla: no se finaliza.');
        }

        // "Finalizar edición" envía el formulario tal cual (con sus campos repetidos).
        $editar = $this->editarPlanilla($idPlanilla, $fechaPlanilla);
        if (! preg_match('~<form[^>]*id="formEditarPlanilla"[^>]*>(.*?)</form>~is', $editar, $f)) {
            throw new \RuntimeException('Mi Planilla cambió la pantalla: no está el formulario de edición.');
        }
        // El formulario no declara enctype: va urlencoded, como lo manda el
        // navegador (en multipart el portal responde "No fue posible procesar
        // la planilla").
        // Cada campo una vez: el período viene repetido (oculto y visible, en
        // formatos distintos) y los dos juntos no se pueden leer como fecha.
        $campos = [];
        foreach ($this->camposEnOrden($f[1]) as [$nombre, $valor]) {
            $campos[$nombre] ??= $valor;
        }
        $cuerpo = http_build_query($campos, '', '&', PHP_QUERY_RFC3986);

        $resp = $this->http->post('/PrivadoIndependientes/Planilla/EditarPlanilla', [
            'body' => $cuerpo,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Referer' => self::BASE.'/PrivadoIndependientes/Planilla/EditarPlanilla',
            ],
        ]);

        return ['quitadas' => $quitadas, 'html' => (string) $resp->getBody(), 'url' => $this->urlFinal($resp)];
    }

    /**
     * Espera a que el portal termine de validar una planilla en generación (la
     * lista "en proceso de generación" la muestra con su estado mientras tanto).
     *
     * @return string el estado que muestra al terminar (o al agotar la espera)
     */
    public function esperarValidacion(string $idPlanilla, int $segundos = 90): string
    {
        $estado = '';
        $ausente = 0;
        // Recién enviada, la planilla puede tardar en aparecer en la lista.
        sleep(5);
        for ($t = 0; $t < $segundos; $t += 5) {
            $generacion = $this->sinPagar()['generacion'];
            if (! str_contains($generacion, $idPlanilla)) {
                if (++$ausente >= 2) {
                    return 'terminada';
                }
                sleep(5);

                continue;
            }
            $ausente = 0;
            $texto = trim(preg_replace('~\s+~', ' ', strip_tags($generacion)));
            $estado = preg_match('~Estado\s+(.*?)(?:Ver Errores|Eliminar|$)~u', $texto, $m) ? trim($m[1]) : $texto;
            if (str_contains($estado, 'Finalizado') || str_contains($estado, 'Error')) {
                return $estado;
            }
            sleep(5);
        }

        return $estado;
    }

    /** Los campos de un formulario en orden, con repetidos, incluidos los select (su opción marcada). */
    private function camposEnOrden(string $html): array
    {
        $campos = [];
        preg_match_all('~<input\b[^>]*>|<select\b[^>]*>.*?</select>~is', $html, $tags);
        foreach ($tags[0] as $tag) {
            if (! preg_match('~\bname="([^"]+)"~', $tag, $n) || preg_match('~^<input[^>]*type="(?:radio|checkbox|file|button|submit)"~i', $tag)) {
                continue;
            }
            if (stripos($tag, '<select') === 0) {
                $valor = preg_match('~<option[^>]*selected[^>]*value="([^"]*)"~i', $tag, $v) || preg_match('~<option[^>]*value="([^"]*)"[^>]*selected~i', $tag, $v)
                    ? $v[1]
                    : (preg_match('~<option[^>]*value="([^"]*)"~i', $tag, $v) ? $v[1] : '');
            } else {
                $valor = preg_match('~\bvalue="([^"]*)"~', $tag, $v) ? $v[1] : '';
            }
            $campos[] = [$n[1], html_entity_decode($valor, ENT_QUOTES)];
        }

        return $campos;
    }

    /** Los campos (hidden y text) de un formulario, por su id, sin radios ni archivos. */
    private function camposFormulario(string $html, string $id): array
    {
        if (! preg_match('~<form[^>]*id="'.preg_quote($id, '~').'"[^>]*>(.*?)</form>~is', $html, $m)) {
            throw new \RuntimeException("Mi Planilla cambió la pantalla: no está el formulario {$id}.");
        }

        return $this->camposSueltos($m[1]);
    }

    private function camposSueltos(string $html): array
    {
        $campos = [];
        preg_match_all('~<input\b[^>]*>~i', $html, $inputs);
        foreach ($inputs[0] as $input) {
            if (! preg_match('~\bname="([^"]+)"~', $input, $n) || preg_match('~type="(?:radio|checkbox|file|button|submit)"~i', $input)) {
                continue;
            }
            $campos[$n[1]] = preg_match('~\bvalue="([^"]*)"~', $input, $v) ? html_entity_decode($v[1], ENT_QUOTES) : '';
        }

        return $campos;
    }

    /** Las fechas del portal vienen como /Date(ms)/: se pasan a hora de Colombia. */
    public static function fecha($valor): ?\Carbon\Carbon
    {
        return preg_match('~(-?\d{10,})~', (string) $valor, $m)
            ? \Carbon\Carbon::createFromTimestampMs((int) $m[1])->setTimezone('America/Bogota')
            : null;
    }

    /** POST JSON a PlanillaAPI con las llaves que trae la página (X-Key-Ajax y VerificationToken). */
    private function api(string $pagina, string $metodo, array $datos): array
    {
        $kaj = preg_match('~id="kaj"[^>]*value="([^"]*)"~', $pagina, $m) ? $m[1] : '';
        $fk = preg_match('~id="fk"[^>]*value="([^"]*)"~', $pagina, $m) ? $m[1] : '';

        $resp = $this->http->post('/PrivadoIndependientes/PlanillaAPI/'.$metodo, [
            'json' => $datos,
            // `fk` no siempre viene en la página: sin él, la llave no se manda.
            'headers' => array_filter([
                'X-Key-Ajax' => $kaj,
                'VerificationToken' => $fk,
                'X-Requested-With' => 'XMLHttpRequest',
                'Accept' => 'application/json',
            ]),
        ]);

        $json = json_decode((string) $resp->getBody(), true);

        return $json['Datos']['Datos'] ?? [];
    }

    /**
     * Cierra la sesión si encuentra el enlace de salir en la página que se le
     * pase (o en el inicio). Sin esto la sesión queda viva en el portal.
     */
    public function logout(?string $html = null): void
    {
        if (! $this->adentro) {
            return;
        }

        $html ??= $this->get('/PrivadoIndependientes/Principal');
        if (preg_match('~href="([^"]*(?:LogOff|Logout|CerrarSesion|Salir)[^"]*)"~i', $html, $m)) {
            $this->http->get(html_entity_decode($m[1]));
        }
        $this->adentro = false;
    }

    /**
     * Recorrido de solo lectura para mapear el portal: entra, guarda las
     * pantallas donde se genera y se administra la planilla, y los scripts
     * que las mueven, y sale. No oprime nada ni crea planillas.
     *
     * @return array<string, int> archivo => bytes guardados
     */
    public function diagnostico(string $carpeta, ?string $periodo = null): array
    {
        $guardados = [];
        $guardar = function (string $nombre, string $contenido) use ($carpeta, &$guardados) {
            file_put_contents($carpeta.'/'.$nombre, $contenido);
            $guardados[$nombre] = strlen($contenido);
        };

        $this->login();
        $principal = null;

        try {
            $paginas = [
                'principal.html' => '/PrivadoIndependientes/Principal',
                'generar_planilla.html' => '/PrivadoIndependientes/Planilla/GenerarPlanilla',
                'administrar_planillas.html' => '/PrivadoIndependientes/Planilla/AdministrarPlanillas',
                'planillas_pagadas.html' => '/PrivadoIndependientes/Planilla/PlanillasPagadas',
            ];

            foreach ($paginas as $nombre => $ruta) {
                $html = $this->get($ruta);
                $guardar($nombre, $html);
                if ($nombre === 'principal.html') {
                    $principal = $html;
                }

                // Los scripts propios de cada pantalla dicen qué llamadas hace.
                if (preg_match_all('~<script[^>]+src="(/PrivadoIndependientes/(?:Scripts/Site|Site)[^"]+)"~i', $html, $m)) {
                    foreach (array_unique($m[1]) as $src) {
                        $archivo = 'js_'.preg_replace('~[^A-Za-z0-9]+~', '_', parse_url(html_entity_decode($src), PHP_URL_PATH)).'.js';
                        if (! isset($guardados[$archivo])) {
                            $guardar($archivo, $this->get(html_entity_decode($src)));
                        }
                    }
                }
            }

            $guardar('planillas.json', json_encode($this->planillas(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            foreach (array_filter([$periodo, now()->startOfMonth()->toDateString()]) as $p) {
                $guardar('cotizantes_I_'.substr($p, 0, 7).'.html', $this->cotizantesPlanilla($p));
            }
        } finally {
            $this->logout($principal);
        }

        return $guardados;
    }

    private function token(string $html): ?string
    {
        return preg_match('~name="__RequestVerificationToken"[^>]*value="([^"]+)"~', $html, $m)
            || preg_match('~value="([^"]+)"[^>]*name="__RequestVerificationToken"~', $html, $m)
            ? html_entity_decode($m[1])
            : null;
    }

    private function urlFinal($resp): string
    {
        $historial = $resp->getHeader(RedirectMiddleware::HISTORY_HEADER);

        return $historial ? (string) end($historial) : self::BASE.self::LOGIN;
    }

    /** El aviso que el portal pinta en la página de ingreso cuando rechaza. */
    private function mensajeError(string $html): ?string
    {
        foreach (['~class="[^"]*(?:alert|error|validation-summary)[^"]*"[^>]*>(.*?)</~is', '~msg=([^"&]+)~'] as $patron) {
            if (preg_match($patron, $html, $m)) {
                $texto = trim(preg_replace('~\s+~', ' ', strip_tags(urldecode(html_entity_decode($m[1])))));
                if ($texto !== '') {
                    return mb_substr($texto, 0, 200);
                }
            }
        }

        return null;
    }
}
