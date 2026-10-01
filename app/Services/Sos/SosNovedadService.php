<?php

namespace App\Services\Sos;

use App\Models\Contrato;
use App\Models\Radicado;
use App\Services\EpsPortal\EpsClavePortal;
use App\Services\EpsPortal\EpsRadicado;
use App\Services\FormularioEpsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Novedad de inicio de relación laboral en S.O.S. desde el radicado de EPS.
 *
 * El login de S.O.S. pide reCAPTCHA y Google no deja pasar un Chrome del
 * servidor, así que el portal lo opera la extensión BryNex Portales en el
 * navegador de la persona, con la sesión que ella abre
 * (`extensiones/brynex-portales`). BryNex prepara los datos, genera el lado B y
 * registra en el radicado lo que la extensión trae del portal.
 *
 * Primer caso a mano: Elizabeth Acosta, radicado 00276553, 14-sep-2026.
 */
class SosNovedadService
{
    public const ENTIDAD = 'sos';

    public const CODIGO_EPS = 'EPS018';

    /** S.O.S. solo acepta fechas de ingreso a ±10 días de hoy. */
    public const DIAS_FECHA = 10;

    /** Tipo de documento de BryNex → id del formulario de S.O.S. y texto de la consulta. */
    private const TIPOS = [
        'CC' => [1, 'CC'], 'TI' => [2, 'TI'], 'CE' => [3, 'CE'], 'PA' => [4, 'PA'], 'PP' => [4, 'PA'],
        'RC' => [5, 'RC'], 'CD' => [11, 'CD'], 'CN' => [12, 'CN'], 'SC' => [13, 'SC'], 'PE' => [14, 'PE'],
        'PT' => [15, 'PT'], 'PPT' => [15, 'PT'], 'PC' => [16, 'PC'],
    ];

    public function __construct(private FormularioEpsService $formularios) {}

    /**
     * Revisa el contrato y arma lo que la extensión necesita para el portal.
     *
     * @return array{problemas: string[], resumen: array, portal: array|null}
     */
    public function preparar(Contrato $contrato): array
    {
        $contrato->loadMissing(['cliente.eps', 'eps', 'plan', 'razonSocial', 'arl', 'pension']);
        $cliente = $contrato->cliente;
        $rs      = $contrato->razonSocial;
        $eps     = $contrato->eps ?: $cliente?->eps;
        $ibc     = (int) round((float) ($contrato->ibc ?: $contrato->salario));
        $tipo    = strtoupper((string) $cliente?->tipo_doc);
        $problemas = [];

        if ($contrato->estado !== 'vigente') {
            $problemas[] = 'El contrato no está vigente.';
        }
        if ($eps?->codigo !== self::CODIGO_EPS) {
            $problemas[] = 'La EPS del contrato no es S.O.S.';
        }
        if (! $contrato->plan?->incluye_eps) {
            $problemas[] = 'El plan del contrato no incluye EPS.';
        }
        if (! $rs || $rs->es_independiente) {
            $problemas[] = 'Solo se tramitan dependientes: los independientes entran al portal con su propio usuario.';
        }
        if (! $cliente) {
            $problemas[] = 'El contrato no tiene cliente en BryNex.';
        } elseif (! isset(self::TIPOS[$tipo])) {
            $problemas[] = "Tipo de documento '{$cliente->tipo_doc}' sin equivalencia en S.O.S.";
        }
        if ($ibc <= 0) {
            $problemas[] = 'El contrato no tiene IBC ni salario.';
        }
        if (! $contrato->fecha_ingreso) {
            $problemas[] = 'El contrato no tiene fecha de ingreso.';
        }

        [$minimo, $maximo] = $this->ventana();
        $enPlazo = $contrato->fecha_ingreso && $contrato->fecha_ingreso->between($minimo, $maximo);
        $aReportar = $contrato->fecha_ingreso ? $this->fechaAReportar($contrato->fecha_ingreso) : null;
        // Sin clave no tiene sentido mandar a la persona a iniciar sesión: el
        // modal lo avisa y deja solo el correo al asesor.
        $cred = $rs ? $this->credencial((string) $rs->nit) : ['error' => 'El contrato no tiene razón social.'];

        $resumen = [
            'trabajador'    => trim(implode(' ', array_filter([$cliente?->primer_nombre, $cliente?->segundo_nombre, $cliente?->primer_apellido, $cliente?->segundo_apellido]))),
            'documento'     => trim($tipo.' '.$contrato->cedula),
            'razon_social'  => $rs?->razon_social,
            'nit'           => $rs?->nit,
            'eps'           => $eps?->nombre,
            'plan'          => $contrato->plan?->nombre,
            'ibc'           => $ibc,
            'arl'           => $this->arl($contrato),
            'afp'           => $this->afp($contrato),
            'fecha_ingreso' => $contrato->fecha_ingreso?->toDateString(),
            'fecha_minima'  => $minimo->toDateString(),
            'fecha_maxima'  => $maximo->toDateString(),
            'en_plazo'      => $enPlazo,
            'fecha_reportar' => $aReportar?->toDateString(),
            'usuario_portal' => $cred['usuario'] ?? null,
            'clave_portal'  => ! isset($cred['error']),
            'clave_error'   => $cred['error'] ?? null,
            'independiente' => (bool) $rs?->es_independiente,
        ];

        return ['problemas' => $problemas, 'resumen' => $resumen, 'portal' => $problemas ? null : [
            'filtro' => [
                'tipo'      => self::TIPOS[$tipo][1],
                'documento' => (string) $contrato->cedula,
                'desde'     => $contrato->fecha_ingreso->copy()->subDays(45)->format('d/m/Y'),
                'hasta'     => today()->format('d/m/Y'),
            ],
            // La fecha ya viene resuelta: la real si S.O.S. la acepta y, si no,
            // la más cercana que sí (ver fechaAReportar).
            'envio' => [
                'tipoId' => self::TIPOS[$tipo][0], 'documento' => (string) $contrato->cedula, 'ibc' => $ibc,
                'arl' => $this->arl($contrato), 'afp' => $this->afp($contrato), 'guardar' => true,
            ],
            'lado_b_url' => route('admin.afiliaciones.sos.lado-b', $contrato->id, false),
        ]];
    }

    /**
     * Registra en BryNex lo que la extensión trajo del portal y dice qué falta.
     *
     * $entrada: novedad (fila de la consulta de S.O.S.) y, según el paso,
     * registro {fecha, envio, resultado}, adjunto (true tras subir el lado B) o
     * certificado (PDF en base64).
     *
     * @return array{ok: bool, siguiente: ?string, radicado?: string, estado_eps?: string, mensaje: string}
     */
    public function aplicar(Contrato $contrato, array $entrada, ?int $usuarioId): array
    {
        $prep = $this->preparar($contrato);
        if ($prep['problemas']) {
            throw new RuntimeException(implode(' ', $prep['problemas']));
        }

        $radicado = EpsRadicado::deContrato($contrato);
        $novedad  = $entrada['novedad'] ?? null;
        $registro = $entrada['registro'] ?? null;
        $prefijo  = 'S.O.S.:';

        if ($registro) {
            $fecha = Carbon::parse($registro['fecha'] ?? '')->startOfDay();
            $ok    = ($registro['resultado']['ok'] ?? false) && $novedad;
            EpsRadicado::bitacora($contrato, $radicado, self::ENTIDAD, 'inicio_laboral', $ok ? 'exitosa' : 'fallida',
                $novedad['radicado'] ?? null, ($registro['envio'] ?? []) + ['fecha' => $fecha->format('d/m/Y')],
                $registro['resultado'] ?? null, $ok ? null : ($registro['resultado']['error'] ?? 'No apareció en la consulta'), $usuarioId);

            if (! $ok) {
                throw new RuntimeException('S.O.S.: '.($registro['resultado']['error'] ?? 'guardó la novedad pero no aparece en la consulta; vuelve a consultar en un minuto.'));
            }

            $prefijo = 'Novedad de inicio laboral radicada en S.O.S. desde BryNex el '.now()->format('d/m/Y').'.'
                .($fecha->isSameDay($contrato->fecha_ingreso) ? '' : ' Ingreso reportado '.$fecha->format('d/m/Y')
                    .' por el plazo de S.O.S.; ingreso real '.$contrato->fecha_ingreso->format('d/m/Y').'.');
        }

        if (! $novedad || empty($novedad['radicado'])) {
            return ['ok' => true, 'siguiente' => null, 'mensaje' => 'No hay novedad en S.O.S. para registrar.'];
        }

        $numero = (string) $novedad['radicado'];
        $estado = Str::lower(Str::ascii((string) ($novedad['estado'] ?? '')));

        if (str_contains($estado, 'cara b')) {
            if ($entrada['adjunto'] ?? false) {
                $this->marcar($radicado, $numero, Radicado::ESTADO_TRAMITE, null, "{$prefijo} Radicado S.O.S. {$numero}: se adjuntó el lado B pero S.O.S. sigue pidiéndolo. Revisar en el portal (plazo 48 h).", $usuarioId);

                return ['ok' => false, 'siguiente' => null, 'radicado' => $numero, 'estado_eps' => $novedad['estado'], 'mensaje' => 'S.O.S. sigue pidiendo el lado B. Revísalo en el portal.'];
            }
            [$rutaPdf] = $this->ladoB($contrato);
            $this->marcar($radicado, $numero, Radicado::ESTADO_TRAMITE, $rutaPdf, "{$prefijo} Radicado S.O.S. {$numero}: pendiente de adjuntar el lado B (48 h).", $usuarioId);

            return ['ok' => true, 'siguiente' => 'adjuntar', 'radicado' => $numero, 'estado_eps' => $novedad['estado'], 'mensaje' => 'Falta adjuntar el lado B.'];
        }

        if (str_contains($estado, 'aprobado') && ! str_contains($estado, 'no aprobado')) {
            $pdf = isset($entrada['certificado']) ? base64_decode((string) $entrada['certificado'], true) : null;
            if (! $pdf) {
                return ['ok' => true, 'siguiente' => 'certificado', 'radicado' => $numero, 'estado_eps' => $novedad['estado'], 'mensaje' => 'Aprobada: falta bajar el certificado.'];
            }
            $ruta = EpsRadicado::guardarPdf($contrato, $pdf, 'eps_certificado_sos');
            $this->marcar($radicado, $numero, Radicado::ESTADO_OK, $ruta, "{$prefijo} Radicado S.O.S. {$numero} APROBADO; certificado adjunto.", $usuarioId);

            return ['ok' => true, 'siguiente' => null, 'radicado' => $numero, 'estado_eps' => $novedad['estado'], 'mensaje' => 'Aprobada: el radicado quedó en OK con el certificado.'];
        }

        if (preg_match('/no aprobado|incorrecto|declinado/', $estado)) {
            $causal = trim((string) ($novedad['causal'] ?? ''));
            $this->marcar($radicado, $numero, Radicado::ESTADO_ERROR, null,
                "{$prefijo} Radicado S.O.S. {$numero} {$novedad['estado']}".($causal ? ". Motivo de S.O.S.: {$causal}" : ' (sin motivo leído del portal)')
                .'. Se tramita por correo con el asesor.', $usuarioId);

            return ['ok' => true, 'siguiente' => 'correo', 'radicado' => $numero, 'estado_eps' => $novedad['estado'], 'causal' => $causal,
                'mensaje' => 'S.O.S. la devolvió'.($causal ? ": {$causal}" : '').'. Envíala por correo al asesor.'];
        }

        $this->marcar($radicado, $numero, Radicado::ESTADO_TRAMITE, null, "{$prefijo} Radicado S.O.S. {$numero}: {$novedad['estado']}.", $usuarioId);

        return ['ok' => true, 'siguiente' => null, 'radicado' => $numero, 'estado_eps' => $novedad['estado'], 'mensaje' => 'En trámite: S.O.S. responde en unas 24 horas.'];
    }

    /**
     * La fecha de ingreso que se le reporta a S.O.S.: la real del contrato si
     * el portal la acepta y, si no, el extremo más cercano de su ventana de
     * ±10 días. No hay nada que preguntar —dejarla fuera de plazo hace que el
     * portal la rechace— y la real queda anotada en el radicado y va, esa sí,
     * en el lado B del formulario que se adjunta.
     */
    public function fechaAReportar(\Carbon\CarbonInterface $ingreso): \Carbon\CarbonInterface
    {
        [$minimo, $maximo] = $this->ventana();

        $f = $ingreso->copy()->startOfDay();

        return $f->lt($minimo) ? $minimo->copy() : ($f->gt($maximo) ? $maximo->copy() : $f);
    }

    /** Valida la fecha elegida para una novedad nueva. */
    public function validarFecha(string $fecha): Carbon
    {
        $f = Carbon::parse($fecha)->startOfDay();
        [$minimo, $maximo] = $this->ventana();
        if (! $f->between($minimo, $maximo)) {
            throw new RuntimeException('S.O.S. solo acepta fechas de ingreso entre '.$minimo->format('d/m/Y').' y '.$maximo->format('d/m/Y').'.');
        }

        return $f;
    }

    /**
     * Formulario de EPS del contrato (con la firma) y su página 2 —el lado B—
     * en PNG gris, que es lo que pide S.O.S. y pesa poco. Si ya se generó hoy,
     * se reutiliza.
     *
     * @return array{0: string, 1: string} rutas en el disco local
     */
    public function ladoB(Contrato $contrato): array
    {
        $disco = Storage::disk('local');
        $hoy = collect($disco->files(EpsRadicado::carpeta($contrato)))
            ->filter(fn ($f) => str_contains($f, '/eps_formulario_sos_'.now()->format('Ymd')) && str_ends_with($f, '_lado_b.png'))
            ->sort()->last();
        if ($hoy && $disco->exists($pdf = preg_replace('/_lado_b\.png$/', '.pdf', $hoy))) {
            return [$pdf, $hoy];
        }

        $contrato->loadMissing(['cliente.municipio', 'cliente.departamento', 'cliente.beneficiarios', 'razonSocial', 'eps', 'arl', 'pension']);
        $rutaPdf = EpsRadicado::guardarPdf($contrato, $this->formularios->generar($contrato, false, []), 'eps_formulario_sos');
        if (! $rutaPdf) {
            throw new RuntimeException('No se pudo generar el formulario de EPS del contrato.');
        }

        $rutaImagen = preg_replace('/\.pdf$/', '_lado_b.png', $rutaPdf);
        exec(sprintf(
            '%s -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=pnggray -r120 -dFirstPage=2 -dLastPage=2 -o %s %s 2>&1',
            is_executable('/usr/bin/gs') ? '/usr/bin/gs' : 'gs',
            escapeshellarg($disco->path($rutaImagen)),
            escapeshellarg($disco->path($rutaPdf))
        ), $salida, $codigo);

        if ($codigo !== 0 || ! $disco->exists($rutaImagen)) {
            throw new RuntimeException('No se pudo convertir el lado B a imagen: '.implode(' ', $salida));
        }

        return [$rutaPdf, $rutaImagen];
    }

    /** Marca el radicado solo si algo cambió: consultar varias veces no llena la bitácora. */
    private function marcar(Radicado $radicado, string $numero, string $estado, ?string $ruta, string $observacion, ?int $usuarioId): void
    {
        $igual = $radicado->estado === $estado && (string) $radicado->numero_radicado === $numero && ! $ruta
            && str_contains((string) $radicado->observacion, $observacion);
        if (! $igual) {
            EpsRadicado::marcar($radicado, $numero, $estado, $ruta, $observacion, $usuarioId);
        }
    }

    /**
     * Usuario y clave de S.O.S. de la empresa en el módulo de claves, para que la
     * extensión llene el login. La clave solo sale para quien tiene permiso de
     * verla (lo decide el controlador).
     *
     * @return array{usuario:string, contrasena:string}|array{error:string}
     */
    public function credencial(string $nit): array
    {
        $cred = EpsClavePortal::para(self::ENTIDAD, '%SOS%', 'S.O.S.', $nit);

        return isset($cred['error']) ? $cred : ['usuario' => $cred['usuario'], 'contrasena' => $cred['contrasena']];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function ventana(): array
    {
        return [today()->subDays(self::DIAS_FECHA), today()->addDays(self::DIAS_FECHA)];
    }

    private function arl(Contrato $contrato): string
    {
        return mb_strtoupper(trim((string) ($contrato->arl?->nombre_arl ?: $contrato->arl?->razon_social))) ?: 'NO APLICA';
    }

    private function afp(Contrato $contrato): string
    {
        return mb_strtoupper(trim((string) $contrato->pension?->razon_social)) ?: 'NO APLICA';
    }
}
