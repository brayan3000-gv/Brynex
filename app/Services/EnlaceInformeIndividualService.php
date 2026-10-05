<?php

namespace App\Services;

use App\Models\OperadorCredencial;
use App\Models\Plano;
use DOMDocument;
use DOMXPath;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Baja del operador el PDF original del "Informe individual" de un cotizante.
 *
 * El soporte que arma BryNex se calcula por su cuenta y no siempre coincide con
 * lo que quedó radicado; el del operador es, por definición, el de verdad —y es
 * el único que trae la hora exacta del pago—. El API documentado de Enlace no lo
 * expone, pero el portal viejo (JSF) sí, y acepta la sesión del login del API:
 * ese login también deja cookies, y con ellas la página abre con el aportante ya
 * autorizado. Todo por HTTP, sin navegador.
 *
 * El recorrido imita lo que hace la pantalla, paso por paso:
 *
 *   1. login + aportante + autorización (API)  → cookies de sesión
 *   2. GET  individuales.xhtml                  → ViewState y campos dinámicos
 *   3. AJAX obtenerPeriodos                     → la planilla aparece pagada
 *   4. AJAX btn_consultar                       → se acepta ese período
 *   5. POST btnGenerarComprobante (multipart)   → el reporte queda en cola
 *   6. AJAX btnVerifyFile                       → hasta que esté listo
 *   7. POST btnGetFile                          → application/pdf
 *
 * Trampa comprobada: hay que mandar SOLO los campos que manda el navegador. Los
 * que la pantalla deja deshabilitados no viajan; si se mandan, el portal
 * responde "Este campo es requerido" y el reporte nunca se genera.
 *
 * Es una pantalla interna, no un API: los `j_idtNNN` los genera JSF y cambian
 * con cada versión del portal, así que se leen del HTML y nunca se quemen.
 *
 * El PDF se guarda en el disco `local` (datos personales, nunca `public`) la
 * primera vez que se baja: una planilla pagada no cambia, y así no se abre
 * sesión con el operador cada vez que alguien lo pide.
 */
class EnlaceInformeIndividualService
{
    private const PAGINA = '/Web/faces/pages/comprobantes/individuales/individuales.xhtml';

    /** Consultas postpago → Comprobante pago de planilla (el del PSE). */
    private const PAGINA_COMPROBANTE = '/Web/faces/pages/comprobantes/despuesdepago/comprobantePagoPlanilla/comprobantePagoPlanilla.xhtml';

    /** Cuántas veces se pregunta si el reporte ya está, con un segundo entre cada una. */
    private const ESPERAS_MAXIMAS = 25;

    /** Mensaje con el que se rinde una descarga que pasó su tope de tiempo. */
    public const SIN_RESPUESTA = 'el operador no respondió a tiempo';

    /**
     * Momento (microtime) en que se deja de esperar al operador, o null para
     * esperar lo de siempre. Solo lo fija conTope(): el clic de un usuario.
     */
    private ?float $vence = null;

    /**
     * Sesiones abiertas en esta instancia, por operador + credencial + aportante.
     * Un envío masivo manda la planilla de toda una empresa: con una sesión por
     * empresa basta, en vez de un login por persona.
     */
    private array $sesiones = [];

    /**
     * Sesiones que no se pudieron abrir (credencial mala, NIT sin permisos,
     * portal caído). No se reintentan en la misma corrida: un portal caído no
     * debe costar 40 segundos por cada persona de un lote de cien.
     */
    private array $sesionesFallidas = [];

    /** A quién se le cuenta cada paso (el botón muestra en qué va). */
    private ?\Closure $alAvanzar = null;

    /** Cada paso de la descarga ("Ingresando a Simple", "Descargando el PDF") se le pasa a `$fn`. */
    public function alAvanzar(callable $fn): static
    {
        $this->alAvanzar = \Closure::fromCallable($fn);

        return $this;
    }

    private function paso(string $texto): void
    {
        if ($this->alAvanzar) {
            rescue(fn () => ($this->alAvanzar)($texto), report: false);
        }
    }

    /**
     * Una copia del servicio que se rinde con el operador a los `$segundos`.
     *
     * Para cuando alguien está esperando con la pantalla: cada paso de la
     * descarga puede esperar hasta 40 s, y son seis pasos más el sondeo del
     * reporte. Con el operador caído (17-sep-2026, 15:21, timeout SSL contra
     * simple.co) un certificado llegó a tardar 38 s. Con tope, obtener() falla
     * a tiempo y soporte() entrega el PDF que arma BryNex.
     *
     * Los procesos en segundo plano (la descarga tras confirmar el pago, el
     * envío masivo) no lo usan: ahí nadie espera y conviene insistir.
     */
    public function conTope(float $segundos): static
    {
        $copia = clone $this;
        $copia->vence = microtime(true) + $segundos;
        // Las sesiones abiertas antes no traen el tope: se abren de nuevo.
        $copia->sesiones = [];
        $copia->sesionesFallidas = [];

        return $copia;
    }

    /**
     * PDF del operador para el cotizante del plano, del disco si ya se bajó.
     *
     * Con `$operadorPlanillaId` se intenta solo ese operador; sin él, cada
     * operador de Enlace para el que el aliado tenga credenciales.
     *
     * @return array{success: bool, pdf?: string, origen?: string, message?: string}
     */
    public function obtener(Plano $plano, ?int $operadorPlanillaId = null): array
    {
        if (empty($plano->numero_planilla) || empty($plano->no_identifi)) {
            return ['success' => false, 'message' => 'El plano no tiene número de planilla o cédula.'];
        }

        $ruta = self::rutaEnDisco($plano);

        if (Storage::disk('local')->exists($ruta)) {
            $this->paso('Ya estaba descargada: abriéndola');
            $pdf = Storage::disk('local')->get($ruta);

            if ($operadorPlanillaId) {
                $this->registrarPago($plano, $operadorPlanillaId, $pdf);
            }

            return ['success' => true, 'pdf' => $pdf, 'origen' => 'disco'];
        }

        $operadores = DB::table('operadores_planilla')
            ->whereIn('codigo', array_keys(SuaporteApiService::HOSTS))
            ->when($operadorPlanillaId, fn ($q) => $q->where('id', $operadorPlanillaId))
            ->get(['id', 'codigo', 'nombre']);

        if ($operadores->isEmpty()) {
            return ['success' => false, 'message' => 'El operador de la planilla no corre sobre Enlace Operativo.'];
        }

        $mensajes = [];
        $conCredenciales = [];

        foreach ($operadores as $operador) {
            $cred = OperadorCredencial::paraOperador(
                (int) $plano->aliado_id, (int) $operador->id, $plano->razon_social_id ? (int) $plano->razon_social_id : null
            )->first();

            if ($cred) {
                $conCredenciales[] = [$operador, $cred];
            } else {
                $mensajes[] = "{$operador->nombre}: sin credenciales.";
            }
        }

        if (! $conCredenciales) {
            return ['success' => false, 'message' => implode(' ', $mensajes)];
        }

        // Un número que no es de planilla ("INFORMATIVO", "33", ".") no se le
        // pregunta al operador: se marca de una vez para que alguien lo corrija.
        if (self::numeroParaOperador($plano->numero_planilla) === null) {
            $mensaje = "«{$plano->numero_planilla}» no es un número de planilla válido.";
            $this->registrarVerificacion($plano, $operadorPlanillaId, 'invalida', $mensaje);

            return ['success' => false, 'message' => $mensaje];
        }

        foreach ($conCredenciales as [$operador, $cred]) {
            try {
                $this->paso("Ingresando a {$operador->nombre}");
                $pdf = $this->descargar($plano, $operador->codigo, $cred);
                Storage::disk('local')->put($ruta, $pdf);
                $this->registrarPago($plano, (int) $operador->id, $pdf);
                $this->registrarVerificacion($plano, (int) $operador->id, 'encontrada', null);

                return ['success' => true, 'pdf' => $pdf, 'origen' => 'operador'];
            } catch (Throwable $e) {
                $mensajes[] = "{$operador->nombre}: {$e->getMessage()}";
            }
        }

        Log::info('Informe individual: no se pudo bajar del operador', [
            'plano_id' => $plano->id,
            'planilla' => $plano->numero_planilla,
            'motivos'  => $mensajes,
        ]);

        $estado = self::estadoPorMensaje(implode(' ', $mensajes));
        if ($estado !== null) {
            $this->registrarVerificacion(
                $plano,
                count($conCredenciales) === 1 ? (int) $conCredenciales[0][0]->id : $operadorPlanillaId,
                $estado,
                implode(' ', $mensajes)
            );
        }

        return ['success' => false, 'message' => implode(' ', $mensajes)];
    }

    /**
     * El número tal como lo busca el operador. En las confirmaciones hay números
     * con un sufijo propio ("1084713676/2", "1083803025-1") que en el operador
     * son "1084713676"; lo que no es un número de planilla devuelve null.
     */
    public static function numeroParaOperador(?string $numero): ?string
    {
        $numero = trim((string) $numero);

        if (preg_match('/^\d{6,12}$/', $numero)) {
            return $numero;
        }

        if (preg_match('/^(\d{6,12})\s*[\/\-]\s*\d{1,2}$/', $numero, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Qué dice de la planilla un fallo del operador, o null si el fallo es de la
     * persona y no de la planilla ("No se encontraron datos": esa cédula no está,
     * pero la planilla sí) o si no hubo a quién preguntar.
     */
    public static function estadoPorMensaje(string $mensaje): ?string
    {
        return match (true) {
            str_contains($mensaje, 'no figura pagada')                => 'no_encontrada',
            str_contains($mensaje, 'no existe en el operador'),
            str_contains($mensaje, 'sin permisos'),
            str_contains($mensaje, 'login fue rechazado'),
            str_contains($mensaje, 'no tiene NIT')                    => 'sin_acceso',
            str_contains($mensaje, 'No se encontraron datos'),
            str_contains($mensaje, self::SIN_RESPUESTA),
            ! str_contains($mensaje, ':')                             => null,
            default                                                   => 'error',
        };
    }

    /**
     * Guarda si la planilla cruza con el operador. Una que ya cruzó no se
     * degrada por un fallo posterior: ese fallo es de una persona o del portal.
     */
    public function registrarVerificacion(Plano $plano, ?int $operadorPlanillaId, string $estado, ?string $mensaje): void
    {
        try {
            $llave = ['aliado_id' => $plano->aliado_id, 'numero_planilla' => (string) $plano->numero_planilla];
            $actual = DB::table('planillas_verificacion_operador')->where($llave)->value('estado');

            if ($actual === 'encontrada' && $estado !== 'encontrada') {
                return;
            }

            DB::table('planillas_verificacion_operador')->updateOrInsert($llave, [
                'operador_planilla_id' => $operadorPlanillaId,
                'estado'               => $estado,
                'mensaje'              => $mensaje !== null ? mb_substr($mensaje, 0, 500) : null,
                'verificada_at'        => now(),
                'updated_at'           => now(),
            ] + ($actual === null ? ['created_at' => now()] : []));
        } catch (Throwable $e) {
            Log::warning('Informe individual: no se pudo guardar la verificación', [
                'planilla' => $plano->numero_planilla,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * El soporte que se le entrega al cliente: el del operador si se puede, y si
     * no, el que arma BryNex. Es lo que deben usar la descarga, el envío masivo y
     * el asistente, para que los tres entreguen lo mismo.
     *
     * Cuando sale el de BryNex trae `motivo` (por qué no salió el real) y
     * `motivo_tipo` (ver tipoDeMotivo()), para avisarle a quien lo pidió.
     *
     * @return array{pdf: string, origen: string, motivo?: string, motivo_tipo?: string}
     */
    public function soporte(Plano $plano, ?int $operadorPlanillaId = null): array
    {
        // Mi Planilla no corre sobre Enlace ni tiene plantilla: o sale el PDF
        // real de su portal, o nada (antes salía uno con formato de ARUS).
        if (self::esMiPlanilla($operadorPlanillaId)) {
            return $this->deMiPlanilla($plano);
        }

        $delOperador = $this->obtener($plano, $operadorPlanillaId);

        if ($delOperador['success']) {
            return ['pdf' => $delOperador['pdf'], 'origen' => $delOperador['origen']];
        }

        $this->paso('El operador no la entregó: armando la de BryNex');
        $motivo = (string) ($delOperador['message'] ?? '');

        return [
            'pdf'         => app(PlanillaFormularioService::class)->generar($plano, $operadorPlanillaId),
            'origen'      => 'brynex',
            'motivo'      => $motivo,
            'motivo_tipo' => self::tipoDeMotivo($motivo),
        ];
    }

    /**
     * Solo el PDF real del operador, nunca la copia que arma BryNex. Es lo que
     * se manda por WhatsApp: al cliente le llega el soporte verdadero o nada.
     *
     * @return array{pdf: string, origen: string}
     *
     * @throws RuntimeException con el motivo si el operador no la entregó
     */
    public function soporteOriginal(Plano $plano, ?int $operadorPlanillaId = null): array
    {
        if (self::esMiPlanilla($operadorPlanillaId)) {
            return $this->deMiPlanilla($plano);
        }

        $delOperador = $this->obtener($plano, $operadorPlanillaId);

        if (! $delOperador['success']) {
            throw new RuntimeException('No se pudo bajar la planilla real del operador ('
                .rtrim((string) ($delOperador['message'] ?? 'sin respuesta'), '. ')
                .'). Por WhatsApp solo se envían originales, no la copia de BryNex.');
        }

        return ['pdf' => $delOperador['pdf'], 'origen' => $delOperador['origen']];
    }

    /**
     * Qué hacer con un fallo del operador, para decírselo a quien espera:
     * `sin_credenciales` (el aliado no tiene usuario del operador),
     * `credenciales` (el operador rechazó el usuario o no deja ver al aportante),
     * `planilla` (no aparece pagada o el número no sirve), `conexion` (el
     * portal no respondió: reintentar más tarde) u `otro`.
     */
    public static function tipoDeMotivo(string $mensaje): string
    {
        $m = mb_strtolower($mensaje);

        return match (true) {
            str_contains($m, 'sin credenciales')                                   => 'sin_credenciales',
            str_contains($m, 'no figura pagada'),
            str_contains($m, 'no es un número de planilla')                       => 'planilla',
            str_contains($m, self::SIN_RESPUESTA),
            (bool) preg_match('/curl|timed? ?out|a tiempo|ssl|connect|conexi|resolve|\b50[0-9]\b|no respondi/', $m) => 'conexion',
            self::estadoPorMensaje($mensaje) === 'sin_acceso'                      => 'credenciales',
            default                                                                => 'otro',
        };
    }

    public static function esMiPlanilla(?int $operadorPlanillaId): bool
    {
        return $operadorPlanillaId
            && strtoupper(trim((string) DB::table('operadores_planilla')->where('id', $operadorPlanillaId)->value('codigo'))) === 'MIPLANI';
    }

    /**
     * El PDF de la planilla tal como lo baja el tablero de Mi Planilla, con la
     * clave de la persona (cada independiente es su propio aportante, así que
     * la planilla es solo de ella). Se guarda en el mismo lugar que los de Enlace.
     *
     * @return array{pdf: string, origen: string}
     *
     * @throws RuntimeException si no tiene clave, no deja entrar o no está pagada
     */
    private function deMiPlanilla(Plano $plano): array
    {
        $ruta = self::rutaEnDisco($plano);

        if (Storage::disk('local')->exists($ruta)) {
            $this->paso('Ya estaba descargada: abriéndola');

            return ['pdf' => Storage::disk('local')->get($ruta), 'origen' => 'disco'];
        }

        $numero = self::numeroParaOperador($plano->numero_planilla);
        if ($numero === null) {
            throw new RuntimeException("«{$plano->numero_planilla}» no es un número de planilla válido.");
        }

        $robot = \App\Services\MiPlanilla\MiPlanillaPortalService::paraCedula((int) $plano->aliado_id, (string) $plano->no_identifi, $plano->tipo_doc);
        $this->paso('Ingresando a Mi Planilla');
        $robot->login();

        try {
            $pdf = $robot->pdfPagada($numero, fn ($t) => $this->paso($t));
        } finally {
            rescue(fn () => $robot->logout(), report: false);
        }

        Storage::disk('local')->put($ruta, $pdf);

        return ['pdf' => $pdf, 'origen' => 'operador'];
    }

    /**
     * Guarda la fecha y hora exactas del pago, una vez por planilla. Solo el
     * informe del operador la trae; el API no, y la lista de planillas pagadas
     * del portal solo da el día.
     */
    public function registrarPago(Plano $plano, int $operadorPlanillaId, string $pdf): void
    {
        try {
            $existe = DB::table('planillas_pago_operador')
                ->where('aliado_id', $plano->aliado_id)
                ->where('numero_planilla', (string) $plano->numero_planilla)
                ->exists();

            if ($existe) {
                return;
            }

            $informe = self::leerInforme($pdf);

            if (empty($informe['fecha_pago']) || ($informe['numero_planilla'] ?? null) !== self::numeroParaOperador($plano->numero_planilla)) {
                return;
            }

            DB::table('planillas_pago_operador')->insert([
                'aliado_id'            => $plano->aliado_id,
                'razon_social_id'      => $plano->razon_social_id,
                'operador_planilla_id' => $operadorPlanillaId,
                'numero_planilla'      => (string) $plano->numero_planilla,
                'fecha_pago'           => $informe['fecha_pago'],
                'tipo_planilla'        => $informe['tipo_planilla'],
                'periodo_cotizacion'   => $informe['periodo_cotizacion'],
                'periodo_servicio'     => $informe['periodo_servicio'],
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);
        } catch (Throwable $e) {
            // Guardar la fecha es un extra: nunca debe impedir entregar el PDF.
            Log::warning('Informe individual: no se pudo guardar la fecha de pago', [
                'planilla' => $plano->numero_planilla,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Lee el encabezado del informe individual: número de planilla, tipo,
     * períodos y fecha de pago.
     *
     * El PDF lo genera JasperReports con Helvetica, así que el texto va plano
     * dentro de streams comprimidos: cada texto es `1 0 0 1 x y Tm ... (texto)Tj`.
     * Una etiqueta y su valor están en la misma línea (misma y), el valor a la
     * derecha; así se emparejan, sin depender del orden en que vienen.
     *
     * @return array{numero_planilla: ?string, tipo_planilla: ?string, periodo_cotizacion: ?string, periodo_servicio: ?string, fecha_pago: ?string}
     */
    public static function leerInforme(string $pdf): array
    {
        $textos = [];

        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $streams)) {
            foreach ($streams[1] as $crudo) {
                $contenido = @gzuncompress($crudo);
                if ($contenido === false) {
                    continue; // imágenes y demás
                }

                preg_match_all(
                    '/1 0 0 1 ([\d.]+) ([\d.]+) Tm\s*\/F\d+ [\d.]+ Tf\s*[\d. ]*rg\s*\(((?:\\\\.|[^\\\\)])*)\)Tj/',
                    $contenido,
                    $m,
                    PREG_SET_ORDER
                );

                foreach ($m as [, $x, $y, $texto]) {
                    $texto = trim(mb_convert_encoding(stripcslashes($texto), 'UTF-8', 'Windows-1252'));
                    if ($texto !== '') {
                        $textos[] = ['x' => (float) $x, 'y' => round((float) $y), 'texto' => $texto];
                    }
                }
            }
        }

        $valorDe = function (string $etiqueta) use ($textos): ?string {
            foreach ($textos as $t) {
                if (mb_strtolower($t['texto']) !== mb_strtolower($etiqueta)) {
                    continue;
                }
                $derecha = array_filter($textos, fn ($o) => abs($o['y'] - $t['y']) <= 1 && $o['x'] > $t['x']);
                usort($derecha, fn ($a, $b) => $a['x'] <=> $b['x']);

                return $derecha ? reset($derecha)['texto'] : null;
            }

            return null;
        };

        $fechaPago = null;
        foreach ($textos as $t) {
            if (preg_match('/^PAGADA\s+(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $t['texto'], $f)) {
                $fechaPago = $f[1];
                break;
            }
        }

        $soloDigitos = fn ($v) => ($v = preg_replace('/\D/', '', (string) $v)) === '' ? null : $v;

        return [
            'numero_planilla'    => $soloDigitos($valorDe('Número Planilla')),
            'tipo_planilla'      => $valorDe('Tipo Planilla'),
            'periodo_cotizacion' => $soloDigitos($valorDe('Periodo Cotización')),
            'periodo_servicio'   => $soloDigitos($valorDe('Periodo Servicio')),
            'fecha_pago'         => $fechaPago,
        ];
    }

    /**
     * Dónde queda el PDF de un cotizante: una carpeta por aliado y planilla.
     * Sirve con el modelo o con cualquier fila que traiga aliado_id,
     * numero_planilla, tipo_doc y no_identifi.
     */
    public static function rutaEnDisco(object $plano): string
    {
        $limpiar = fn ($v) => preg_replace('/[^A-Za-z0-9]/', '', (string) $v);

        return sprintf(
            'planillas-operador/%d/%s/%s-%s.pdf',
            (int) $plano->aliado_id,
            $limpiar($plano->numero_planilla),
            $limpiar($plano->tipo_doc ?: 'CC'),
            $limpiar($plano->no_identifi)
        );
    }

    /** El recorrido completo contra un operador. Lanza con un mensaje legible si algo falla. */
    /**
     * Middleware de Guzzle que recorta cada llamada al tiempo que le queda al
     * tope y, pasado el tope, no deja salir ninguna más. Como el sondeo del
     * reporte también llama al portal en cada vuelta, el tope lo corta igual.
     */
    private function respetarTope(float $vence): callable
    {
        return function (callable $siguiente) use ($vence) {
            return function ($peticion, array $opciones) use ($siguiente, $vence) {
                $queda = $vence - microtime(true);

                if ($queda <= 0.5) {
                    throw new RuntimeException(self::SIN_RESPUESTA.'.');
                }

                $opciones['timeout'] = min((float) ($opciones['timeout'] ?? 40), $queda);
                $opciones['connect_timeout'] = min((float) ($opciones['connect_timeout'] ?? 10), $queda);

                return $siguiente($peticion, $opciones)->then(null, function ($razon) use ($vence) {
                    // Si se cortó por el tope, que lo diga así y no con el cURL 28.
                    if (microtime(true) >= $vence - 0.5) {
                        return Create::rejectionFor(new RuntimeException(self::SIN_RESPUESTA.'.'));
                    }

                    return Create::rejectionFor($razon);
                });
            };
        };
    }

    /**
     * La sesión con el operador para el aportante del plano: la que ya estaba
     * abierta en esta instancia o una nueva (login + aportante + autorización).
     *
     * @return array{0: Client, 1: bool, 2: string} cliente, si se reusó, llave
     */
    private function sesion(Plano $plano, string $codigoOperador, OperadorCredencial $cred): array
    {
        $host = SuaporteApiService::hostDeOperador($codigoOperador);
        [$tipoAportante, $numeroAportante] = $this->aportanteDe($plano);
        $llave = "{$codigoOperador}|{$cred->id}|{$tipoAportante}{$numeroAportante}";

        if (isset($this->sesionesFallidas[$llave])) {
            throw new RuntimeException($this->sesionesFallidas[$llave]);
        }

        $reusada = isset($this->sesiones[$llave]);

        if (! $reusada) {
            $opciones = [
                'cookies'         => new CookieJar(),
                'curl'            => SuaporteApiService::opcionesCurlTls(),
                'timeout'         => 40,
                'connect_timeout' => 10,
                'http_errors'     => false,
                'headers'         => ['User-Agent' => 'Mozilla/5.0'],
            ];

            if ($this->vence !== null) {
                $pila = HandlerStack::create();
                $pila->push($this->respetarTope($this->vence));
                $opciones['handler'] = $pila;
            }

            $http = new Client($opciones);

            try {
                $this->abrirSesion($http, $host, $codigoOperador, $cred, $tipoAportante, $numeroAportante);
            } catch (Throwable $e) {
                $this->sesionesFallidas[$llave] = $e->getMessage();
                throw $e;
            }

            $this->sesiones[$llave] = $http;
        }

        return [$this->sesiones[$llave], $reusada, $llave];
    }

    private function descargar(Plano $plano, string $codigoOperador, OperadorCredencial $cred): string
    {
        $host = SuaporteApiService::hostDeOperador($codigoOperador);
        $pagina = $host.self::PAGINA;
        $numero = self::numeroParaOperador($plano->numero_planilla)
            ?? throw new RuntimeException("«{$plano->numero_planilla}» no es un número de planilla válido.");

        [$http, $reusada, $llave] = $this->sesion($plano, $codigoOperador, $cred);

        // 2. La pantalla: de aquí salen el ViewState y los nombres que genera JSF.
        $html = (string) $http->get($pagina)->getBody();
        $xpath = $this->xpath($html);

        if (! $xpath->query('//*[@id="btnGenerarComprobante"]')->length) {
            // La sesión del operador dura unos minutos: si la que se reusaba ya
            // venció, se abre otra una sola vez.
            if ($reusada) {
                unset($this->sesiones[$llave]);

                return $this->descargar($plano, $codigoOperador, $cred);
            }

            throw new RuntimeException('el portal no abrió el informe individual (sesión no reconocida).');
        }

        $viewState = $this->valor($xpath, '//form[@id="form"]//input[@name="javax.faces.ViewState"]');
        $codigoOperadorPortal = $this->valor($xpath, '//input[@name="tx_ntu:operador"]');
        $estadosPago = $this->valor($xpath, '//input[@name="tx_ntu:estadoPago"]') ?: 'OK,PD,PWS';
        $campoToken = $xpath->query('//input[contains(concat(" ", @class, " "), " token_download ")]')->item(0)?->getAttribute('name');

        $tipoDoc = strtoupper(trim($plano->tipo_doc ?: 'CC'));
        $tiposValidos = array_map(
            fn ($o) => $o->getAttribute('value'),
            iterator_to_array($xpath->query('//select[@name="tipoDocumentoCotizante"]/option'))
        );

        if ($tiposValidos && ! in_array($tipoDoc, $tiposValidos, true)) {
            throw new RuntimeException("el portal no maneja el tipo de documento {$tipoDoc}.");
        }

        // 3. ¿La planilla figura pagada para este aportante?
        $this->paso('Buscando la planilla');
        $respuesta = $this->ajax($http, $pagina, $viewState, 'tx_ntu:obtenerPeriodos', 'click', 'click', [
            'javax.faces.partial.execute' => 'tx_ntu:obtenerPeriodos tx_ntu:numeroPlanilla tx_ntu:estadoPago tx_ntu:codigoEmpresaConsultante',
            'javax.faces.partial.render'  => 'tx_ntu:panelPeriodos',
            'tx_ntu:numeroPlanilla'       => $numero,
            'tx_ntu:estadoPago'           => $estadosPago,
            'tx_ntu:codigoEmpresaConsultante' => '',
        ]);
        $viewState = $this->viewStateParcial($respuesta) ?? $viewState;

        preg_match('/name="(tx_ntu:tableComponentePlanilla:0:j_idt\d+)"/', $respuesta, $radio);

        if (empty($radio[1])) {
            throw new RuntimeException("la planilla {$numero} no figura pagada para este aportante.");
        }

        $seleccion = [$radio[1] => 'true'];

        // 4. Se acepta el período, como el botón del modal.
        $respuesta = $this->ajax($http, $pagina, $viewState, 'tx_ntu:btn_consultar', 'click', 'action', [
            'javax.faces.partial.execute' => 'tx_ntu:btn_consultar tx_ntu:listaSeleccion',
        ] + $seleccion);
        $viewState = $this->viewStateParcial($respuesta) ?? $viewState;

        // 5. Generar. Exactamente los campos que manda el navegador.
        $this->paso('Descargando el PDF');
        $hoy = now();
        $campos = [
            'form' => 'form',
            'includeAyuda037:A32-nameHelp' => '',
            'includeAyuda037:A32-textoAyuda' => '',
            'radio_nroPlanilla' => 'PLANILLA',
            'tx_ntu:estadoPago' => $estadosPago,
            'tx_ntu:codigoEmpresaConsultante' => '',
            'tx_ntu:operador' => $codigoOperadorPortal,
            'tx_ntu:numeroPlanilla' => $numero,
            'tx_fechaInicial:fechaServidor' => $hoy->format('d/m/Y'),
            'tx_fechaInicial:textoFecha' => '',
            'tx_fechaFinal:fechaServidor' => $hoy->format('d/m/Y'),
            'tx_fechaFinal:textoFecha' => '',
            'periodoCotizacion:anioServidor' => $hoy->format('Y'),
            'periodoCotizacion:mesServidor' => (string) $hoy->month,
            'periodoCotizacion:textoPeriodo' => '',
            'periodoCotizacion:mes' => (string) $hoy->month,
            'periodoCotizacion:anio' => $hoy->format('Y'),
            'sucursales:codigoSucursalBusqueda' => '',
            'sucursales:nombreSucursalBusqueda' => '',
            'sucursales:listaSucursales:paginador_sucu:page' => '1',
            'sucursales:listaSucursales:paginador_sucu:lastPage' => '0',
            'sucursales:listaSucursales:paginador_sucu:pageSize' => '10',
            'sucursales:listaSucursales:paginador_sucu:onevent' => 'invalid',
            'sucursales:listaSucursales:paginador_sucu:pageSizeDrop' => '10',
            'radio_reporte' => 'REPORTE',
            'radioTipoReporte' => 'ACTIVOS',
            'radio_cotizante' => 'COTIZANTE',
            'tipoDocumentoCotizante' => $tipoDoc,
            'inputNroDocCotizante' => (string) $plano->no_identifi,
            'inputNroDocCot' => '',
            'areaDocCot' => '',
            'input_centroT' => '',
            'formatoSalida' => 'CONSOLIDADO',
            'javax.faces.ViewState' => $viewState,
            'btnGenerarComprobante' => 'btnGenerarComprobante',
        ] + $seleccion;

        if ($campoToken) {
            $campos[$campoToken] = (string) round(microtime(true) * 1000);
        }

        $html = (string) $http->post($pagina, [
            'multipart' => array_map(
                fn ($k, $v) => ['name' => $k, 'contents' => (string) $v],
                array_keys($campos),
                $campos
            ),
        ])->getBody();

        $estado = $this->fileready($html);

        if ($estado === 'stop') {
            throw new RuntimeException('el portal rechazó la solicitud'.$this->mensajePortal($html));
        }

        $viewState = $this->valor($this->xpath($html), '//form[@id="form"]//input[@name="javax.faces.ViewState"]') ?: $viewState;

        // 6. Esperar a que el reporte esté listo.
        for ($i = 0; $estado === 'waitingReport' && $i < self::ESPERAS_MAXIMAS; $i++) {
            $respuesta = $this->ajax($http, $pagina, $viewState, 'btnVerifyFile', 'action', 'action', [
                'javax.faces.partial.execute' => 'btnVerifyFile',
                'javax.faces.partial.render'  => 'fileready',
            ]);
            $viewState = $this->viewStateParcial($respuesta) ?? $viewState;
            $estado = $this->fileready($respuesta);

            if ($estado === 'waitingReport') {
                sleep(1);
            }
        }

        // 7. El archivo.
        $archivo = $http->post($pagina, ['form_params' => [
            'form' => 'form',
            'javax.faces.ViewState' => $viewState,
            'btnGetFile' => 'btnGetFile',
        ]]);
        $cuerpo = (string) $archivo->getBody();

        if (! str_starts_with($cuerpo, '%PDF')) {
            throw new RuntimeException('el portal no entregó el PDF'.$this->mensajePortal($cuerpo));
        }

        return $cuerpo;
    }

    /**
     * Comprobante de pago de la planilla (PSE): banco, CUS, valor, estado y hora
     * de la transacción, con el desglose por administradora. En Mi Planilla es
     * su «Reporte resumen de pago» (ver MiPlanillaPortalService::resumenPagoPdf). Es de la planilla
     * entera, no de una persona, y sale de "Consultas postpago → Comprobante
     * pago de planilla" del mismo portal JSF (mapeado 5-oct-2026 en Simple).
     *
     * A diferencia del informe individual, no hay cola: un POST normal del
     * formulario con el número y el tipo «P» devuelve el PDF de una vez.
     *
     * @return array{pdf: string, origen: string}
     *
     * @throws RuntimeException con el motivo si el operador no lo entrega
     */
    public function comprobantePago(Plano $plano, ?int $operadorPlanillaId = null): array
    {
        $numero = self::numeroParaOperador($plano->numero_planilla)
            ?? throw new RuntimeException("«{$plano->numero_planilla}» no es un número de planilla válido.");

        $ruta = self::rutaComprobanteEnDisco($plano);
        if (Storage::disk('local')->exists($ruta)) {
            $this->paso('Ya estaba descargado: abriéndolo');

            return ['pdf' => Storage::disk('local')->get($ruta), 'origen' => 'disco'];
        }

        // Mi Planilla: el «Reporte resumen de pago», con la clave de la persona.
        if (self::esMiPlanilla($operadorPlanillaId)) {
            $robot = \App\Services\MiPlanilla\MiPlanillaPortalService::paraCedula((int) $plano->aliado_id, (string) $plano->no_identifi, $plano->tipo_doc);
            $this->paso('Ingresando a Mi Planilla');
            $robot->login();

            try {
                $pdf = $robot->resumenPagoPdf($numero, fn ($t) => $this->paso($t));
            } finally {
                rescue(fn () => $robot->logout(), report: false);
            }

            Storage::disk('local')->put($ruta, $pdf);

            return ['pdf' => $pdf, 'origen' => 'operador'];
        }

        $operadores = DB::table('operadores_planilla')
            ->whereIn('codigo', array_keys(SuaporteApiService::HOSTS))
            ->when($operadorPlanillaId, fn ($q) => $q->where('id', $operadorPlanillaId))
            ->get(['id', 'codigo', 'nombre']);

        if ($operadores->isEmpty()) {
            throw new RuntimeException('el comprobante de pago solo se baja de los operadores de Enlace (Simple, ARUS).');
        }

        $mensajes = [];
        foreach ($operadores as $operador) {
            $cred = OperadorCredencial::paraOperador(
                (int) $plano->aliado_id, (int) $operador->id, $plano->razon_social_id ? (int) $plano->razon_social_id : null
            )->first();

            if (! $cred) {
                $mensajes[] = "{$operador->nombre}: sin credenciales.";
                continue;
            }

            try {
                $this->paso("Ingresando a {$operador->nombre}");
                $pdf = $this->descargarComprobante($plano, $numero, $operador->codigo, $cred);
                Storage::disk('local')->put($ruta, $pdf);

                return ['pdf' => $pdf, 'origen' => 'operador'];
            } catch (Throwable $e) {
                $mensajes[] = "{$operador->nombre}: {$e->getMessage()}";
            }
        }

        throw new RuntimeException(implode(' ', $mensajes));
    }

    /** Uno por planilla: el comprobante es del pago, no de cada cotizante. */
    public static function rutaComprobanteEnDisco(object $plano): string
    {
        $limpiar = fn ($v) => preg_replace('/[^A-Za-z0-9]/', '', (string) $v);

        return sprintf(
            'planillas-operador/%d/%s/comprobante-pago.pdf',
            (int) $plano->aliado_id,
            $limpiar($plano->numero_planilla)
        );
    }

    private function descargarComprobante(Plano $plano, string $numero, string $codigoOperador, OperadorCredencial $cred): string
    {
        $pagina = SuaporteApiService::hostDeOperador($codigoOperador).self::PAGINA_COMPROBANTE;
        [$http, $reusada, $llave] = $this->sesion($plano, $codigoOperador, $cred);

        $xpath = $this->xpath((string) $http->get($pagina)->getBody());

        if (! $xpath->query('//form[@id="form"]//input[@name="generar"]')->length) {
            if ($reusada) {
                unset($this->sesiones[$llave]);

                return $this->descargarComprobante($plano, $numero, $codigoOperador, $cred);
            }

            throw new RuntimeException('el portal no abrió el comprobante de pago (sesión no reconocida).');
        }

        // Lo mismo que manda el navegador al pulsar «Continuar» con «Número
        // planilla» y «Comprobante pago planilla» marcados (los dos vienen así
        // por defecto): el formulario tal cual, más el número y el botón.
        $this->paso('Buscando el comprobante');
        $campos = $this->camposComoNavegador($xpath, 'form');
        $campos['tx_ntu:numeroPlanilla'] = $numero;
        $campos['tipoComprobante'] = 'P';
        $campos['selectReporte'] = 'P';
        foreach ($xpath->query('//form[@id="form"]//input[contains(concat(" ", @class, " "), " token_download ")]') as $token) {
            $campos[$token->getAttribute('name')] = (string) round(microtime(true) * 1000);
        }
        $campos['generar'] = '';

        $this->paso('Descargando el PDF');
        $cuerpo = (string) $http->post($pagina, ['form_params' => $campos])->getBody();

        if (! str_starts_with($cuerpo, '%PDF')) {
            throw new RuntimeException('el portal no entregó el comprobante'.$this->mensajePortal($cuerpo));
        }

        return $cuerpo;
    }

    /**
     * Los campos de un formulario como los envía un navegador: sin los
     * deshabilitados ni los botones, radios y casillas solo si están marcados,
     * y de cada lista la opción seleccionada (o la primera).
     */
    private function camposComoNavegador(DOMXPath $xpath, string $formId): array
    {
        $campos = [];
        $elementos = $xpath->query("//form[@id='{$formId}']//*[self::input or self::select or self::textarea]");

        foreach ($elementos as $el) {
            $nombre = $el->getAttribute('name');
            if ($nombre === '' || $el->hasAttribute('disabled')) {
                continue;
            }

            if ($el->nodeName === 'select') {
                $opcion = $xpath->query('.//option[@selected]', $el)->item(0) ?? $xpath->query('.//option', $el)->item(0);
                $campos[$nombre] = $opcion ? ($opcion->hasAttribute('value') ? $opcion->getAttribute('value') : trim($opcion->textContent)) : '';
                continue;
            }

            $tipo = strtolower($el->getAttribute('type') ?: 'text');
            if (in_array($tipo, ['submit', 'button', 'image', 'reset', 'file'], true)) {
                continue;
            }
            if (in_array($tipo, ['radio', 'checkbox'], true) && ! $el->hasAttribute('checked')) {
                continue;
            }

            $campos[$nombre] = $el->nodeName === 'textarea'
                ? $el->textContent
                : ($el->hasAttribute('value') ? $el->getAttribute('value') : (in_array($tipo, ['radio', 'checkbox'], true) ? 'on' : ''));
        }

        return $campos;
    }

    /** Login, aportante y autorización. El mismo jar de cookies sirve después para el portal. */
    private function abrirSesion(Client $http, string $host, string $codigoOperador, OperadorCredencial $cred, string $tipoAportante, string $numeroAportante): void
    {
        $cifrador = new SuaporteApiService(['operador' => $codigoOperador]);
        $contrasena = $cifrador->cifrarDato((string) $cred->contrasena)
            ?? throw new RuntimeException('no se pudo cifrar la contraseña.');

        $login = $http->post("{$host}/auth/login", [
            'headers' => ['clave-secreta' => $cred->clave_secreta],
            'json'    => ['usuario' => SuaporteApiService::usuarioPortal($cred->usuario), 'contrasena' => $contrasena],
        ]);

        if ($login->getStatusCode() !== 200 || ! $login->getHeaderLine('token')) {
            throw new RuntimeException('el login fue rechazado.');
        }

        $headers = [];
        foreach (['token', 'refresh-token', 'refresh-token-ttl', 'refresh-token-date', 'faces'] as $nombre) {
            $headers[$nombre] = $login->getHeaderLine($nombre);
        }

        $aportante = $http->get("{$host}/api/gestion/aportante/{$tipoAportante}/{$numeroAportante}", ['headers' => $headers]);
        $idAportante = json_decode((string) $aportante->getBody(), true)['id'] ?? null;

        if (! $idAportante) {
            throw new RuntimeException("el aportante {$tipoAportante} {$numeroAportante} no existe en el operador.");
        }

        $autorizacion = $http->get("{$host}/api/gestion/authorization/user/contributor", [
            'headers' => $headers,
            'query'   => ['id' => $idAportante, 'tipoIdentificacion' => $tipoAportante, 'numeroIdentificacion' => $numeroAportante],
        ]);

        if ($autorizacion->getStatusCode() !== 200) {
            throw new RuntimeException("el usuario no tiene permisos sobre {$tipoAportante} {$numeroAportante}.");
        }
    }

    /** El aportante de un independiente es la persona; el de una empresa, su NIT. */
    private function aportanteDe(Plano $plano): array
    {
        $rs = $plano->razonSocial;

        [$tipo, $numero] = ($rs?->es_independiente)
            ? [strtoupper(trim($plano->tipo_doc ?: 'CC')), (string) $plano->no_identifi]
            : ['NI', preg_replace('/\D/', '', (string) $rs?->nit)];

        if ($numero === '') {
            throw new RuntimeException('la razón social no tiene NIT.');
        }

        return [$tipo, $numero];
    }

    /** Petición AJAX de JSF (mojarra.ab). */
    private function ajax(Client $http, string $pagina, string $viewState, string $fuente, string $evento, string $comportamiento, array $extra): string
    {
        return (string) $http->post($pagina, [
            'headers'     => ['Faces-Request' => 'partial/ajax'],
            'form_params' => [
                'form' => 'form',
                'javax.faces.ViewState' => $viewState,
                'javax.faces.source' => $fuente,
                'javax.faces.partial.event' => $evento,
                'javax.faces.behavior.event' => $comportamiento,
                'javax.faces.partial.ajax' => 'true',
            ] + $extra,
        ])->getBody();
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    private function valor(DOMXPath $xpath, string $consulta): string
    {
        return (string) $xpath->query($consulta)->item(0)?->getAttribute('value');
    }

    private function viewStateParcial(string $respuesta): ?string
    {
        return preg_match('/<update id="[^"]*ViewState[^"]*"><!\[CDATA\[([^\]]+)\]\]>/', $respuesta, $m) ? $m[1] : null;
    }

    private function fileready(string $html): string
    {
        return preg_match('/<span id="fileready"[^>]*>([^<]*)</', $html, $m) ? trim($m[1]) : '';
    }

    /** Lo que el portal le mostraría al usuario en el modal de error, si dijo algo. */
    private function mensajePortal(string $html): string
    {
        foreach (['/id="errorMessage"[^>]*>([^<]+)</', '/<ul[^>]*class="messages"[^>]*>(.*?)<\/ul>/s'] as $patron) {
            if (preg_match($patron, $html, $m) && trim(strip_tags($m[1])) !== '') {
                return ': '.mb_substr(trim(html_entity_decode(strip_tags($m[1]))), 0, 200);
            }
        }

        return '.';
    }
}
