<?php

namespace App\Services\ArlColmena;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cliente del API de la Oficina Digital ARL de Colmena.
 *
 * Colmena no publica APIs: este es el backend REST de su propio portal
 * (`portalcliente.colmenaseguros.com/portal/back`), mapeado en septiembre de
 * 2026. Todo va con `Authorization: Bearer <token>`, el mismo JWT que el portal
 * guarda en la cookie `token` y que dura tres horas.
 *
 * Dos cosas que no son obvias:
 *
 *   1. El token está atado a UN contrato (`effectiveContractId`). Si el
 *      `contractId` del cuerpo no es el de ese token, el API responde
 *      401 "El contrato no coincide" aunque el token sea válido.
 *   2. Los errores de validación no dicen qué falta: un cuerpo incompleto
 *      devuelve 400 "Bad request" a secas. Por eso el payload se arma con el
 *      mismo mapa de campos que envía el portal — ver [[ColmenaPayloadBuilder]].
 *
 * La sesión se guarda por NIT: la misma empresa puede estar en varios aliados
 * de BryNex y todos operan sobre el mismo contrato con Colmena.
 */
class ColmenaApiService
{
    public const HOST = 'https://portalcliente.colmenaseguros.com';

    private const BASE = '/portal/back';

    /** El JWT dura 3 horas; se renueva antes para no fallar a mitad de trámite. */
    private const TTL_SESION_MINUTOS = 150;

    private ?string $token = null;

    private ?string $contrato = null;

    public function __construct(private string $nit)
    {
        $this->nit = preg_replace('/\D/', '', $nit);
    }

    // ─── Sesión ──────────────────────────────────────────────────────

    private function claveCache(): string
    {
        return "colmena:sesion:{$this->nit}";
    }

    public function olvidarSesion(): void
    {
        Cache::forget($this->claveCache());
        $this->token = $this->contrato = null;
    }

    /** Entra al portal si hace falta y deja token y contrato listos. */
    private function asegurarSesion(): void
    {
        if ($this->token && $this->contrato) {
            return;
        }

        if ($sesion = Cache::get($this->claveCache())) {
            $this->token = $sesion['token'];
            $this->contrato = $sesion['contrato'];

            return;
        }

        $sesion = ColmenaSesionService::abrir($this->nit);

        Cache::put($this->claveCache(), [
            'token' => $sesion['token'],
            'contrato' => $sesion['contrato'],
        ], now()->addMinutes(self::TTL_SESION_MINUTOS));

        $this->token = $sesion['token'];
        $this->contrato = $sesion['contrato'];
    }

    /** El consecutivo del contrato de esta empresa en Colmena (el `contractId`). */
    public function contrato(): string
    {
        $this->asegurarSesion();

        return $this->contrato;
    }

    // ─── Transporte ──────────────────────────────────────────────────

    private function peticion(bool $reintentable = false)
    {
        $this->asegurarSesion();

        $peticion = Http::withHeaders([
            'Accept' => 'application/json, text/plain, */*',
            'Authorization' => 'Bearer '.$this->token,
            // Imperva mira el User-Agent: sin uno de navegador devuelve el reto.
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '.
                               '(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            'Origin' => self::HOST,
            'Referer' => self::HOST.'/',
            'Cookie' => 'token='.$this->token.'; userContract='.$this->contrato.'; portalSelected=portalCliente',
        ])
            ->timeout(60);

        // Solo las lecturas se reintentan. Un timeout en `workers/dependent` no
        // significa que Colmena no lo haya radicado: reintentar dejaría dos
        // ingresos al mismo trabajador.
        // Un 4xx (p. ej. el 404 de «no está en Colmena») es una respuesta, no
        // un fallo de red: no se reintenta y pasa por `interpretar`.
        return $reintentable
            ? $peticion->retry(2, 500, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException
                || ($e instanceof \Illuminate\Http\Client\RequestException && $e->response->status() >= 500), throw: false)
            : $peticion;
    }

    public function get(string $ruta, array $query = []): array|string|null
    {
        $r = $this->peticion(reintentable: true)->get(self::HOST.self::BASE.$ruta, $query);

        return $this->interpretar($r, 'GET '.$ruta);
    }

    public function post(string $ruta, array $cuerpo = [], array $query = []): array|string|null
    {
        $url = self::HOST.self::BASE.$ruta.($query ? '?'.http_build_query($query) : '');
        $r = $this->peticion()->post($url, $cuerpo);

        return $this->interpretar($r, 'POST '.$ruta);
    }

    private function interpretar($r, string $contexto)
    {
        if ($r->failed()) {
            $cuerpo = $r->json();
            $mensaje = $cuerpo['message'] ?? $cuerpo['error'] ?? trim(strip_tags($r->body())) ?: 'sin detalle';

            // Una sesión caducada se nota en el 401: se olvida para que el
            // siguiente intento vuelva a entrar en vez de repetir el error.
            if ($r->status() === 401 && ! str_contains((string) $mensaje, 'contrato')) {
                $this->olvidarSesion();
            }

            Log::warning('ARL Colmena: fallo en '.$contexto, ['status' => $r->status(), 'mensaje' => $mensaje]);

            throw new RuntimeException("ARL Colmena ({$r->status()}) en {$contexto}: {$mensaje}");
        }

        return $r->json() ?? $r->body();
    }

    // ─── Catálogos ───────────────────────────────────────────────────

    /** @return array<int,array{id:string,name:string}> */
    public function tiposDocumento(): array
    {
        return $this->catalogo('tipos-doc', '/identification-type');
    }

    /** @return array<int,array{id:string,name:string}> */
    public function eps(): array
    {
        return $this->catalogo('eps', '/eps-type');
    }

    /** @return array<int,array{id:string,name:string}> */
    public function afp(): array
    {
        return $this->catalogo('afp', '/genericTypes/AFP-types');
    }

    /** ARL anteriores; el ingreso exige decir de cuál viene el trabajador. */
    public function arls(): array
    {
        return $this->catalogo('arl', '/genericTypes/ARP-types');
    }

    /** Departamentos con código DANE de dos dígitos ("05", "76"). */
    public function catalogoDepartamentos(): array
    {
        return $this->catalogo('departamentos', '/department');
    }

    /**
     * Centros de trabajo contratados, con su clase de riesgo, tasa y grado.
     *
     * Es el catálogo que llena el selector del formulario: `consecutive` es el
     * `headquarterId` que pide la afiliación.
     *
     * @return array<int,array{consecutive:string,name:string,riskClass:string,riskRate:string,grade:string}>
     */
    public function centrosDeTrabajo(): array
    {
        return $this->catalogo(
            'centros:'.$this->contrato(),
            '/work-center/contracted/',
            ['contractId' => $this->contrato()]
        );
    }

    /** El salario mínimo que maneja Colmena, para validar antes de enviar. */
    public function smlv(): float
    {
        $r = $this->get('/workers/SMLV');

        return (float) ($r['smlv'] ?? 0);
    }

    /**
     * Los catálogos cambian una vez al año: se guardan un día para no pedirlos
     * en cada afiliación.
     */
    private function catalogo(string $nombre, string $ruta, array $query = []): array
    {
        return Cache::remember(
            "colmena:catalogo:{$nombre}",
            now()->addDay(),
            fn () => $this->get($ruta, $query) ?: []
        );
    }

    // ─── Trabajadores ────────────────────────────────────────────────

    /**
     * Lo que Colmena sabe del trabajador en este contrato.
     *
     * Devuelve null cuando no está (el API responde 404, que aquí no es un
     * fallo sino la respuesta). `endEffectiveDate` 2050-12-31 = vigente.
     */
    public function consultarDependiente(string $tipoDoc, string $documento): ?array
    {
        try {
            $r = $this->get('/workers/dependent/', [
                'contractId' => $this->contrato(),
                'identification' => $documento,
                'identificationType' => $tipoDoc,
            ]);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), '(404)')) {
                return null;
            }

            throw $e;
        }

        return is_array($r) ? $r : null;
    }

    /**
     * Radica el ingreso de un dependiente. El payload lo arma
     * [[ColmenaPayloadBuilder]].
     *
     * Responde 201 con `filingNumber` y `filingNewDate` (las novedades de
     * anulación y retiro usan otros nombres: `radicationNumber` y
     * `newRadicationDate`; el API no es coherente consigo mismo).
     */
    public function afiliarDependiente(array $payload): array
    {
        $r = $this->post('/workers/dependent', $payload);

        if (! is_array($r) || empty($r['filingNumber'] ?? $r['radicationNumber'] ?? null)) {
            throw new RuntimeException('Colmena no devolvió número de radicación: '.json_encode($r));
        }

        return $r;
    }

    /**
     * Anula el ingreso: la vigencia desaparece, no queda como retiro.
     *
     * Colmena solo la acepta hasta un día calendario después del inicio de la
     * vigencia; pasado eso hay que retirar. Si no hay ingreso que anular,
     * responde 404 con ese mismo mensaje del plazo, que despista.
     */
    /**
     * Certificado de afiliación de un dependiente ("Certificados → Dependientes
     * → Certificado de afiliación"). Devuelve el PDF crudo.
     *
     * Es el mismo POST que dispara la pantalla: el trabajador va en el cuerpo y
     * el PDF vuelve en base64 dentro de `bytes`.
     */
    public function certificadoAfiliacionDependiente(string $tipoDoc, string $documento, bool $conSalario = true): string
    {
        $r = $this->post('/membership/special-certification/dependent-worker', [
            ['numberIdentification' => $documento, 'typeIdentification' => $tipoDoc],
        ], [
            'quoteType' => '0',
            'headquarterId' => '0',
            'sampleSalary' => $conSalario ? '1' : '0',
            'startDateCoverage' => '',
            'endDateCoverage' => '',
            'contractId' => $this->contrato(),
            'reportSizeLimit' => '5000',
            'reportType' => 'PDF',
        ]);

        $pdf = is_array($r) ? base64_decode((string) ($r['bytes'] ?? ''), true) : false;

        if (! $pdf || ! str_starts_with($pdf, '%PDF')) {
            throw new RuntimeException('Colmena no devolvió el PDF del certificado de afiliación.');
        }

        return $pdf;
    }

    public function anularIngresoDependiente(string $tipoDoc, string $documento): array
    {
        $r = $this->post('/news/dependent/cancellation-entry', [
            'identification' => [
                'identificationType' => $tipoDoc,
                'identificationNumber' => $documento,
            ],
            'consecutiveContract' => (int) $this->contrato(),
        ]);

        return is_array($r) ? $r : [];
    }

    /**
     * Retira al trabajador. `$consecutivoTrabajador` y `$consecutivoCentro` son
     * los que devuelve la consulta (`workerId` y `headquarterId`).
     */
    public function retirarDependiente(int $consecutivoTrabajador, int $consecutivoCentro, string $fecha): array
    {
        $r = $this->post('/news/new-dependent-retirement', [
            'consecutiveHeadquarters' => $consecutivoCentro,
            'consecutiveWorker' => $consecutivoTrabajador,
            'WithdrawalDate' => $fecha,
            'consecutiveContract' => (int) $this->contrato(),
        ]);

        return is_array($r) ? $r : [];
    }

    /**
     * Informe de trabajadores vigentes, en Excel.
     *
     * Colmena no devuelve el archivo: devuelve `{bytes: "<base64 de un .xls>"}`.
     */
    public function informeVigentes(): string
    {
        $r = $this->post('/workers/current-dependent/report/', [], [
            'reportType' => 'Excel',
            'consecutive' => '',
            'quoteType' => 0,
            'headquarterId' => 0,
            'cityCode' => '',
            'endDate' => '',
            'initDate' => '',
            'contractId' => $this->contrato(),
        ]);

        $bytes = is_array($r) ? ($r['bytes'] ?? null) : null;

        return $bytes
            ? base64_decode($bytes)
            : throw new RuntimeException('Colmena no devolvió el informe de vigentes.');
    }
}
