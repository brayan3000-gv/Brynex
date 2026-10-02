<?php

namespace App\Services\SaludTotal;

use App\Services\EpsPortal\EpsClavePortal;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;

/**
 * Sesión HTTP contra la Oficina Virtual de empleadores de Salud Total.
 *
 * No hace falta navegador (probado desde netcup el 14-sep-2026): el login es un
 * POST con la clave en MD5 y sin reCAPTCHA —el reCAPTCHA de la página es del
 * registro de usuarios—, y el perfil empleador no tiene 2FA activo. De ahí salen
 * tres cosas:
 *  - un JWT para el API de consulta de afiliados (`APIOFVAfiliadosPBSEmpleador`);
 *  - un JWT de la Oficina Virtual que entrega URLs firmadas a la app MVC
 *    `NovedadInicioLaboral`, cuya sesión va en cookies;
 *  - en esa app: firma del empleador, validación, registro y seguimiento.
 */
class SaludTotalCliente
{
    public const ENTIDAD = 'salud_total';

    private const BASE = 'https://transaccional.saludtotal.com.co';

    /** Tipos de documento de BryNex → los del portal. */
    public const TIPOS_DOC = ['CC' => 'C', 'CE' => 'E', 'PA' => 'P', 'PP' => 'P', 'PT' => 'PT', 'PPT' => 'PT', 'TI' => 'T', 'SC' => 'SC', 'PC' => 'PC'];

    private CookieJar $cookies;

    private ?string $jwtOficina = null;

    private ?string $jwtAfiliados = null;

    /** El GUID de sesión del portal: de él salen los demás tokens. */
    private ?string $tokenPortal = null;

    private ?string $jwtReportesCartera = null;

    private ?string $appAbierta = null;

    /** A quién contarle por dónde va, para que la pantalla lo muestre mientras ocurre. */
    private $alAvanzar = null;

    private function __construct(private string $nit, private string $tipoUsuario, private string $documentoUsuario)
    {
        $this->cookies = new CookieJar();
    }

    /** Avisa cada paso a quien pase la función: el modal lo pinta mientras pasa. */
    public function alAvanzar(?callable $f): self
    {
        $this->alAvanzar = $f;

        return $this;
    }

    private function paso(string $texto): void
    {
        if ($this->alAvanzar) {
            ($this->alAvanzar)($texto);
        }
    }

    /**
     * Inicia sesión. Lanza `SaludTotalLoginException` si el portal rechaza la clave.
     */
    public static function entrar(string $nit, string $usuario, string $contrasena, ?callable $alAvanzar = null): self
    {
        [$tipo, $numero] = EpsClavePortal::separarUsuario($usuario);
        $cliente = new self(preg_replace('/\D/', '', $nit), self::TIPOS_DOC[$tipo] ?? 'C', $numero);
        $cliente->alAvanzar($alAvanzar);
        $cliente->paso('Entrando al portal de Salud Total');

        $r = $cliente->http()->post(self::BASE.'/ApiOficinaVirtual/Login/Login', [
            'tipoUsuario'     => 2, // empleador
            'tipoIdEmpleador' => 'N',
            'IdEmpleador'     => $cliente->nit,
            'tipoIdUsuario'   => $cliente->tipoUsuario,
            'IdUsuario'       => $cliente->documentoUsuario,
            'CodigoAsesor'    => '',
            'Clave'           => md5($contrasena),
        ]);

        $data = $r->json('data') ?? [];

        if (! $r->ok() || ($data['error'] ?? 1) != 0 || empty($data['token'])) {
            throw new SaludTotalLoginException(trim((string) ($data['mensaje'] ?? $r->json('mensajeError') ?? '')) ?: "El portal no aceptó el usuario y la clave (HTTP {$r->status()}).");
        }

        $jwt = $cliente->http()->post(self::BASE.'/ApiOficinaVirtual/Login/CreateJWT', ['Token' => $data['token'], 'Origen' => 'OficinaVirtual']);
        $cliente->jwtOficina = is_string($jwt->json('data')) ? $jwt->json('data') : null;

        $cliente->tokenPortal = (string) $data['token'];

        $afi = $cliente->http()->get(self::BASE.'/APIOFVAfiliadosPBSEmpleador/api/Token/GetToken', ['Token' => $data['token'], 'Origen' => 'OficinaVirtual']);
        $cliente->jwtAfiliados = $afi->json('Valido') ? $afi->json('Token') : null;

        if (! $cliente->jwtOficina || ! $cliente->jwtAfiliados) {
            throw new RuntimeException('Salud Total aceptó la clave pero no entregó los tokens de sesión.');
        }

        $cliente->paso('Sesión abierta en el portal');

        return $cliente;
    }

    /**
     * Los tres reportes de cartera de un período (`'8/2026'`, sin cero delante).
     *
     * Salud Total ya los separa en el origen, que es justo lo que hace falta
     * para saber qué hacer con cada uno: lo que no se pagó, lo que se pagó de
     * más por alguien ya retirado, y lo que se pagó por quien nunca estuvo
     * afiliado. Los dos últimos son plata de la empresa, no deuda.
     *
     * Van en dos pasos, como en la pantalla: primero se pregunta si hay algo
     * (`Conulta…`, con la errata del portal) y solo si lo hay se pide el
     * archivo. El segundo paso **necesita** `formato=Excel`: sin él la petición
     * se queda colgada sin responder ni fallar.
     *
     * @return array<string, array{hay:bool, mensaje:?string, filas:array}>
     */
    public function cartera(string $periodo, bool $conFilas = true): array
    {
        $reportes = [
            'sin_pago'              => ['ConultaCotSinPago', 'CotSinPago'],
            'desafiliados_con_pago' => ['ConultaCoDesafiliadoCP', 'CoDesafiliadoCP'],
            'pago_sin_afiliacion'   => ['ConultaCotizanteConPagoNA', 'CotizanteConPagoNA'],
        ];

        $salida = [];

        foreach ($reportes as $nombre => [$consulta, $descarga]) {
            $j = $this->cartera1($consulta, $periodo) ?: [];
            $hay = (bool) ($j['Valido'] ?? false);

            $salida[$nombre] = [
                'hay'     => $hay,
                'mensaje' => ($j['Descripcion'] ?? null) ?: (($j['Error'] ?? null) ?: null),
                'filas'   => $hay && $conFilas ? $this->carteraFilas($descarga, $periodo) : [],
            ];
        }

        return $salida;
    }

    /** Un paso de los reportes de cartera. */
    private function cartera1(string $ruta, string $periodo, ?string $formato = null): ?array
    {
        return $this->http()
            ->timeout(180)
            ->withHeaders(['Authorization' => 'bearer '.$this->jwtCartera()])
            ->get(self::BASE."/STAPI_ReportesCRInternet/api/Cotizantes/{$ruta}", array_filter([
                'EmpleadorId'     => $this->nit,
                'EmpleadorTipoId' => 'N',
                'Periodo'         => $periodo,
                'formato'         => $formato,
            ]))
            ->json();
    }

    /**
     * Las filas del reporte, sacadas del Excel que el portal manda en base64.
     *
     * Viene en el campo `pdf` aunque sea una hoja de cálculo, y es un .xls
     * viejo (OLE2), así que hay que escribirlo a disco para leerlo.
     *
     * @return array<int, array<string, string>>
     */
    private function carteraFilas(string $ruta, string $periodo): array
    {
        $j = $this->cartera1($ruta, $periodo, 'Excel');
        $base64 = $j['pdf'] ?? null;

        if (! is_string($base64) || $base64 === '') {
            return [];
        }

        $archivo = tempnam(sys_get_temp_dir(), 'st_cartera_').'.xls';
        file_put_contents($archivo, base64_decode($base64));

        try {
            $hoja = IOFactory::load($archivo)->getActiveSheet()->toArray(null, true, false, false);
        } catch (\Throwable $e) {
            return [];
        } finally {
            @unlink($archivo);
        }

        // La primera fila con varias celdas llenas son los títulos; lo de
        // arriba es el encabezado del informe.
        $inicio = null;

        foreach ($hoja as $i => $fila) {
            if (count(array_filter($fila, fn ($c) => trim((string) $c) !== '')) >= 3) {
                $inicio = $i;
                break;
            }
        }

        if ($inicio === null) {
            return [];
        }

        $titulos = array_map(fn ($c) => Str::squish((string) $c), $hoja[$inicio]);
        $filas = [];

        foreach (array_slice($hoja, $inicio + 1) as $fila) {
            // Al final del informe vienen varias notas legales, cada una en una
            // sola celda: sin este corte entrarían como si fueran cotizantes.
            if (count(array_filter($fila, fn ($c) => trim((string) $c) !== '')) < 4) {
                continue;
            }

            $registro = [];

            foreach ($titulos as $c => $titulo) {
                if ($titulo !== '') {
                    $registro[$titulo] = self::celda($titulo, (string) ($fila[$c] ?? ''));
                }
            }

            $filas[] = $registro;
        }

        return $filas;
    }

    private function jwtCartera(): string
    {
        if (! $this->jwtReportesCartera) {
            $r = $this->http()->get(self::BASE.'/STAPI_ReportesCRInternet/api/Token/GetToken', [
                'Token' => $this->tokenPortal, 'Origen' => 'OficinaVirtual',
            ]);

            $this->jwtReportesCartera = $r->json('Valido') ? $r->json('Token') : null;

            if (! $this->jwtReportesCartera) {
                throw new RuntimeException('Salud Total no entregó el token de los reportes de cartera.');
            }
        }

        return $this->jwtReportesCartera;
    }

    /**
     * El valor de una celda, con las fechas ya legibles.
     *
     * Las columnas de fecha y de período llegan como el número de días de
     * Excel (46233 = 2026-07-07), que no le sirve a nadie tal cual.
     */
    private static function celda(string $titulo, string $valor): string
    {
        $valor = Str::squish($valor);
        $esFecha = preg_match('/fecha|periodo/i', $titulo);

        if ($esFecha && is_numeric($valor) && $valor > 20000 && $valor < 60000) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $valor)->format('Y-m-d');
            } catch (\Throwable $e) {
                return $valor;
            }
        }

        return $valor;
    }

    /**
     * Grupo familiar de la persona con esta empresa: vacío si no está afiliada
     * con ella. Cada integrante trae parentesco, estado, contrato vigente y
     * fecha de afiliación.
     */
    public function grupoFamiliar(string $tipoDocBrynex, string $documento): array
    {
        $this->paso('Consultando el grupo familiar con la empresa');
        $tipoId = $this->idTipoDocumento($tipoDocBrynex);

        $r = $this->http()->withToken($this->jwtAfiliados, 'bearer')
            ->get(self::BASE.'/APIOFVAfiliadosPBSEmpleador/api/ConsultaAfiliados/ConsultaGrupoFamiliar', [
                'BeneficiarioId' => $documento, 'BeneficiarioTipoId' => $tipoId,
                'Empleadorid' => $this->nit, 'Empleadortipoid' => 'N',
            ]);

        return is_array($r->json()) ? $r->json() : [];
    }

    /** Datos de la persona en Salud Total (nombres, estado, contratos activos e inactivos), sin importar la empresa. */
    public function datosPersona(string $tipoDocBrynex, string $documento): ?array
    {
        $this->paso('Buscando a la persona en Salud Total');
        $this->abrirApp('NovedadLaboral');

        $r = $this->ajax()->get(self::BASE.'/NovedadInicioLaboral/NovedadInicioLaboral/ConsultarDatosAfectadoPorDocumento/', [
            'tipoDocumento' => $this->tipoDocPortal($tipoDocBrynex), 'documento' => $documento,
        ]);

        return $r->json()[0] ?? null;
    }

    /** Novedades de inicio laboral registradas por la empresa en el rango (AAAA-MM-DD). */
    public function seguimiento(string $desde, string $hasta): array
    {
        $this->paso('Revisando las novedades ya radicadas por la empresa');
        $this->abrirApp('SeguimientoLaboral');

        $r = $this->ajax()->get(self::BASE.'/NovedadInicioLaboral/SeguimientoIngreso/GetConsultaNovedadesRelacion/', [
            'FechaInicio' => $desde, 'FechaFin' => $hasta,
        ]);

        return is_array($r->json()) ? $r->json() : [];
    }

    /** Motivos de rechazo de una novedad. */
    public function inconsistencias(string $numeroFormulario): array
    {
        $this->paso("Leyendo los motivos de rechazo del formulario {$numeroFormulario}");
        $this->abrirApp('SeguimientoLaboral');

        $r = $this->ajax()->get(self::BASE.'/NovedadInicioLaboral/SeguimientoIngreso/GetAfiliacionesGetInconsistenciasGrilla/', [
            'NumeroFormulario' => $numeroFormulario, 'Sucursal' => 'INTERNET',
        ]);

        return collect(is_array($r->json()) ? $r->json() : [])
            ->map(fn ($i) => trim(($i['Campo'] ?? '').': '.($i['Des_Inconsistencia'] ?? ''), ': '))
            ->filter()->values()->all();
    }

    /** PDF del certificado de una novedad aprobada. */
    public function certificado(string $numeroFormulario): ?string
    {
        $this->paso('Bajando el certificado de la novedad');
        $this->abrirApp('SeguimientoLaboral');

        $url = $this->ajax()->asForm()->post(self::BASE.'/NovedadInicioLaboral/SeguimientoIngreso/GenerarCertificado/', [
            'numeroFormulario' => $numeroFormulario,
        ])->json();

        return is_string($url) && str_starts_with($url, 'https://') ? $this->descargarPdf($url) : null;
    }

    /** PDF del Formulario Único de Afiliación de una novedad (existe desde que se radica). */
    public function formularioPdf(string $numeroFormulario): ?string
    {
        $this->paso('Bajando el Formulario Único en PDF');

        return $this->descargarPdf(self::BASE.'/generarpdffua/default.aspx?IDForm='.urlencode($numeroFormulario));
    }

    /** Tipos de cotizante que ofrece el formulario: código PILA → texto. */
    public function tiposCotizante(): array
    {
        $this->paso('Leyendo los tipos de cotizante del formulario');
        $this->abrirApp('NovedadLaboral');

        $html = $this->http()->get(self::BASE.'/NovedadInicioLaboral/NovedadInicioLaboral/RegistroNovedad')->body();

        if (! preg_match('#<select[^>]*id="slcCotizante"[^>]*>(.*?)</select>#s', $html, $m)) {
            return [];
        }

        preg_match_all('#<option[^>]*value="([^"]*)"[^>]*>(.*?)</option>#s', $m[1], $ops, PREG_SET_ORDER);

        return collect($ops)->filter(fn ($o) => $o[1] !== '0')
            ->mapWithKeys(fn ($o) => [$o[1] => trim(html_entity_decode(strip_tags($o[2])))])->all();
    }

    /**
     * Registra la novedad de inicio de relación laboral.
     *
     * @return array{numeroFormulario:string, urlPdf:?string, envio:array}
     */
    public function registrarNovedad(array $p): array
    {
        $this->abrirApp('NovedadLaboral');
        $this->paso('Leyendo la firma digitalizada de la empresa');

        $firma = $this->ajax()->get(self::BASE.'/NovedadInicioLaboral/NovedadInicioLaboral/ConsultaFirmaEmpleador/')->json()[0] ?? null;

        if (($firma['Estado'] ?? 0) != 1 || empty($firma['Firma'])) {
            throw new RuntimeException('La empresa no tiene firma digitalizada en Salud Total: cárguela en el portal (Afiliaciones → Firma digitalizada).');
        }

        $tipo = $this->tipoDocPortal($p['tipo_doc']);

        $this->paso('Validando los datos del formulario');
        $validacion = $this->ajax()->get(self::BASE.'/NovedadInicioLaboral/NovedadInicioLaboral/ValidacionFormulario/', [
            'datosFormulario' => implode(',', [$tipo, $p['documento'], $p['fecha_ingreso'], $p['tipo_cotizante'], $p['ibc'], '']),
        ])->json();

        if ($validacion !== '' && $validacion !== null) {
            throw new RuntimeException('Salud Total rechazó los datos: '.strip_tags(str_replace(['<br>', '<br/>'], ' ', (string) $validacion)));
        }

        $envio = [
            'beneficiarioTipoId' => $tipo,
            'TipoDocumento'      => $p['tipo_doc_texto'],
            'beneficiarioId'     => $p['documento'],
            'nombre1'            => $p['nombre1'],
            'nombre2'            => $p['nombre2'],
            'apellido1'          => $p['apellido1'],
            'apellido2'          => $p['apellido2'],
            'IBC'                => $p['ibc'],
            'FechaIngreso'       => $p['fecha_ingreso'],
            'IdTipoCotizante'    => $p['tipo_cotizante'],
            'TipoCotizante'      => $p['tipo_cotizante_texto'],
            'CodigoAsesor'       => '',
            'firmaEmpleador'     => $firma['Firma'],
            'fechaFirma'         => substr((string) $firma['fechaRegistro'], 0, 10),
            'codigoCertificado'  => (string) Str::uuid(),
        ];

        $this->paso('Registrando la novedad en el portal');
        $r = $this->ajax()->asForm()->post(self::BASE.'/NovedadInicioLaboral/NovedadInicioLaboral/PostFormularioUnico/', ['novedad' => $envio]);
        $numero = (string) ($r->json('numeroFormulario') ?? '');

        if (! $r->ok() || $r->json('result') !== 0 || ! preg_match('/^\d{6,}$/', $numero)) {
            throw new RuntimeException('Salud Total no confirmó el registro: '.($r->json('msg_err') ?: substr($r->body(), 0, 200)));
        }

        $this->paso("Novedad registrada: formulario {$numero}");

        return ['numeroFormulario' => $numero, 'urlPdf' => $r->json('urlPdf'), 'envio' => collect($envio)->except('firmaEmpleador')->all()];
    }

    /** Abre (una vez por sesión) la app MVC con la URL firmada que entrega la Oficina Virtual. */
    private function abrirApp(string $opcion): void
    {
        if ($this->appAbierta === $opcion) {
            return;
        }

        $url = trim((string) $this->http()->withToken($this->jwtOficina, 'bearer')
            ->get(self::BASE."/ApiOficinaVirtual/NovedadLaboral/{$opcion}", [
                'empleadorId' => $this->nit, 'empleadorTipoId' => 'N',
                'beneficiarioId' => $this->documentoUsuario, 'beneficiarioTipoId' => $this->tipoUsuario,
            ])->json('data'));

        if (! str_starts_with($url, self::BASE.'/NovedadInicioLaboral/')) {
            throw new RuntimeException("Salud Total no entregó el acceso a {$opcion}.");
        }

        $this->http()->get($url);
        $this->appAbierta = $opcion;
    }

    private function descargarPdf(string $url): ?string
    {
        // Con el Accept: application/json de http() los PDF responden 406.
        $r = $this->http()->accept('application/pdf,*/*')->timeout(90)->get($url);

        return $r->ok() && str_starts_with($r->body(), '%PDF') ? $r->body() : null;
    }

    private function tipoDocPortal(string $tipoBrynex): string
    {
        return self::TIPOS_DOC[strtoupper($tipoBrynex)]
            ?? throw new RuntimeException("Tipo de documento '{$tipoBrynex}' sin equivalencia en Salud Total.");
    }

    /** El API de consulta usa ids numéricos por tipo (PT = 16); con la letra responde "The request is invalid". */
    private function idTipoDocumento(string $tipoBrynex): string
    {
        // Sacados de `api/TipoDocumento/GetTipoDocInt?TipoDocumento=<letra>` el 14-sep-2026.
        return (string) ['C' => 1, 'T' => 2, 'E' => 5, 'P' => 10, 'SC' => 12, 'PT' => 16, 'PC' => 17][$this->tipoDocPortal($tipoBrynex)];
    }

    private function http(): PendingRequest
    {
        return Http::withOptions(['cookies' => $this->cookies])
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'])
            ->timeout(60)
            ->acceptJson();
    }

    private function ajax(): PendingRequest
    {
        return $this->http()->withHeaders(['X-Requested-With' => 'XMLHttpRequest']);
    }
}
