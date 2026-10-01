<?php

namespace App\Services\Sanitas;

use App\Models\Contrato;
use App\Models\Radicado;
use App\Services\EpsPortal\EpsRadicado;
use App\Services\FormularioEpsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

/**
 * Novedad de inicio laboral en Sanitas por el formulario público "Novedades a la
 * afiliación" (tipo "Cambio de empleador", código 10513).
 *
 * Sanitas está detrás de Radware, así que el formulario lo llena la extensión
 * BryNex Portales en el Chrome de la persona; el clic en Enviar lo da ella. BryNex
 * prepara los datos, genera el formulario con "Reporte de novedades" y la
 * novedad 9 marcados, y al final guarda la constancia con el número de radicado
 * que muestra Sanitas —con el formulario enviado detrás, en un solo PDF— y deja
 * el radicado de BryNex en trámite (Sanitas responde
 * por correo en unos 3 días hábiles; la conciliación lo pasa a OK).
 */
class SanitasNovedadService
{
    public const ENTIDAD = 'sanitas';

    public const TIPO_NOVEDAD = '10513';

    /** Tipo de documento de BryNex → valor del formulario de Sanitas. */
    private const TIPOS = [
        'CC' => '1', 'CE' => '2', 'NI' => '4', 'NIT' => '4', 'PA' => '6', 'PP' => '6', 'RC' => '7', 'TI' => '8',
        'CD' => '9', 'CN' => '10', 'SC' => '11', 'PE' => '13', 'PT' => '14', 'PPT' => '14',
    ];

    public function __construct(private FormularioEpsService $formularios) {}

    /**
     * @return array{problemas: string[], avisos: string[], resumen: array, falta_firma: bool, url_firma: string|null, portal: array|null}
     */
    public function preparar(Contrato $contrato): array
    {
        $contrato->loadMissing(['cliente.eps', 'cliente.departamento', 'cliente.municipio', 'eps', 'plan', 'razonSocial']);
        $cliente = $contrato->cliente;
        $rs      = $contrato->razonSocial;
        $eps     = $contrato->eps ?: $cliente?->eps;
        $tipo    = strtoupper((string) $cliente?->tipo_doc);
        $radicado = Radicado::where('contrato_id', $contrato->id)->where('tipo', Radicado::TIPO_EPS)->first();
        $problemas = [];
        $avisos = [];

        if ($contrato->estado !== 'vigente') {
            $problemas[] = 'El contrato no está vigente.';
        }
        if ($eps?->codigo !== SanitasConciliacionService::CODIGO_EPS) {
            $problemas[] = 'La EPS del contrato no es Sanitas.';
        }
        if (! $contrato->plan?->incluye_eps) {
            $problemas[] = 'El plan del contrato no incluye EPS.';
        }
        if (! $rs || $rs->es_independiente) {
            $problemas[] = 'El cambio de empleador es para dependientes: el independiente no tiene empleador que reportar.';
        }
        if (! $cliente) {
            $problemas[] = 'El contrato no tiene cliente en BryNex.';
        } elseif (! isset(self::TIPOS[$tipo])) {
            $problemas[] = "Tipo de documento '{$cliente->tipo_doc}' sin equivalencia en Sanitas.";
        }
        if (! $contrato->fecha_ingreso) {
            $problemas[] = 'El contrato no tiene fecha de ingreso.';
        }
        if (! $cliente?->departamento_id || ! $cliente?->municipio_id) {
            $problemas[] = 'El cliente no tiene departamento y municipio de residencia.';
        }
        if ($radicado?->estado === Radicado::ESTADO_OK) {
            $problemas[] = 'El radicado de EPS ya está en OK.';
        }

        $celular = $this->digitos($cliente?->celular);
        if (strlen($celular) !== 10) {
            $problemas[] = 'Sanitas pide un celular de 10 dígitos y el del cliente no lo es.';
        }
        // Teléfono fijo obligatorio (7 a 10 dígitos): el del cliente, el de la empresa o el celular.
        // `telefonos` de la empresa puede traer varios números separados.
        $fijo = collect([$cliente?->telefono, $rs?->telefonos, $celular])
            ->flatMap(fn ($t) => preg_split('/[,;\/|]| - /', (string) $t))
            ->map(fn ($t) => $this->digitos($t))->first(fn ($t) => strlen($t) >= 7 && strlen($t) <= 10) ?? '';

        // Sanitas responde al correo del formulario: el correo de formularios de la razón
        // social si lo tiene (el que la empresa usa con las entidades), si no el buzón del
        // aliado, que revisa el agente, y por último el del cliente.
        $correoRs = filter_var(trim((string) $rs?->correo_formulario), FILTER_VALIDATE_EMAIL) ?: null;
        $buzon    = config("afiliaciones_correo.buzones.{$contrato->aliado_id}");
        $correo   = $correoRs ?: $buzon ?: $cliente?->correo;
        if (! $correo) {
            $problemas[] = 'No hay correo para la respuesta de Sanitas (ni correo de formularios de la razón social, ni buzón del aliado, ni correo del cliente).';
        } elseif (! $correoRs && ! $buzon) {
            $avisos[] = 'Ni la razón social tiene correo de formularios ni el aliado buzón configurado: la respuesta de Sanitas llegará al correo del cliente.';
        }
        // La plantilla de Sanitas lleva la firma del trabajador; sin la dibujada a mano
        // el espacio sale en blanco y Sanitas devuelve la novedad. El robot no sube un
        // formulario así: el modal pide la firma antes de radicar.
        $faltaFirma = $cliente && ! FormularioEpsService::tieneFirma($cliente);
        if ($radicado?->numero_radicado && $radicado->estado === Radicado::ESTADO_TRAMITE) {
            $avisos[] = "Este radicado ya está en trámite con el número {$radicado->numero_radicado}. Radica otra vez solo si Sanitas no lo recibió.";
        }

        $nombre = trim(implode(' ', array_filter([$cliente?->primer_nombre, $cliente?->segundo_nombre, $cliente?->primer_apellido, $cliente?->segundo_apellido])));
        $resumen = [
            'trabajador'    => $nombre,
            'documento'     => trim($tipo.' '.$contrato->cedula),
            'razon_social'  => $rs?->razon_social,
            'nit'           => $rs?->nit,
            'eps'           => $eps?->nombre,
            'fecha_ingreso' => $contrato->fecha_ingreso?->toDateString(),
            'residencia'    => trim(($cliente?->municipio?->nombre ?? '').' · '.($cliente?->departamento?->nombre ?? ''), ' ·'),
            'telefono_fijo' => $fijo,
            'celular'       => $celular,
            'correo'        => $correo,
            'estado_radicado' => $radicado?->estado,
            'numero_radicado' => $radicado?->numero_radicado,
        ];

        return ['problemas' => $problemas, 'avisos' => $avisos, 'resumen' => $resumen,
            'falta_firma' => $faltaFirma,
            'url_firma'   => $contrato->id ? route('admin.afiliaciones.formulario.eps.firma', $contrato->id, false) : null,
            'portal' => $problemas ? null : [
            'tipoDoc'       => self::TIPOS[$tipo],
            'documento'     => (string) $contrato->cedula,
            'departamento'  => str_pad((string) $cliente->departamento_id, 2, '0', STR_PAD_LEFT),
            'municipio'     => (string) $cliente->municipio?->nombre,
            'municipioDane' => str_pad((string) $cliente->municipio_id, 5, '0', STR_PAD_LEFT),
            'telefonoFijo'  => $fijo,
            'celular'       => $celular,
            'correo'        => $correo,
            'tipoNovedad'   => self::TIPO_NOVEDAD,
            'observaciones' => $this->observaciones($contrato),
            'archivo'       => route('admin.afiliaciones.sanitas.formulario', $contrato->id, false),
            'nombreArchivo' => 'Formulario_Sanitas_'.Str::slug($nombre, '_').'.pdf',
        ]];
    }

    /** Formulario de Sanitas como novedad de inicio laboral, guardado en los soportes del radicado. */
    public function formulario(Contrato $contrato): string
    {
        $contrato->loadMissing('cliente');
        if (! FormularioEpsService::tieneFirma($contrato->cliente)) {
            throw new RuntimeException('El formulario no tiene la firma del trabajador: ábrelo en «✍️ Firmar» y que la dibuje antes de radicar. Sanitas devuelve las novedades sin firma.');
        }

        $pdf = $this->formularios->generar($contrato, false, [], true);
        if (strlen($pdf) > 3_000_000) {
            throw new RuntimeException('El formulario pesa más de 3 MB, el límite del portal de Sanitas.');
        }
        $this->borrarBorradores($contrato);
        EpsRadicado::guardarPdf($contrato, $pdf, 'eps_formulario_sanitas');

        return $pdf;
    }

    /**
     * El robot pide el formulario en cada intento y cada pedido guardaba una copia.
     * Las que son posteriores a la última constancia no llegaron a radicarse: se
     * reemplazan por la nueva. Las anteriores quedan, porque son de una radicación
     * que sí se hizo. Nada en la BD apunta a estos archivos.
     */
    private function borrarBorradores(Contrato $contrato): void
    {
        $disco = Storage::disk('local');
        $archivos = collect($disco->files(EpsRadicado::carpeta($contrato)));
        $sello = fn (string $f, string $prefijo) => str_starts_with(basename($f), $prefijo) ? substr(basename($f), strlen($prefijo), 15) : null;

        $ultimaConstancia = $archivos->map(fn ($f) => $sello($f, 'eps_radicado_sanitas_'))->filter()->max();
        $borradores = $archivos->filter(function ($f) use ($sello, $ultimaConstancia) {
            $s = $sello($f, 'eps_formulario_sanitas_');

            return $s && (! $ultimaConstancia || $s > $ultimaConstancia);
        });
        if ($borradores->isNotEmpty()) {
            $disco->delete($borradores->values()->all());
        }
    }

    /**
     * Registra lo que la extensión leyó de Sanitas después de que la persona dio Enviar.
     *
     * $entrada: radicado (número que mostró Sanitas, o el que escribió la persona),
     * texto (lo que dice la página), captura (JPEG en base64, opcional), error (si
     * Sanitas no lo recibió).
     */
    public function aplicar(Contrato $contrato, array $entrada, ?int $usuarioId): array
    {
        $prep = $this->preparar($contrato);
        if ($prep['problemas']) {
            throw new RuntimeException(implode(' ', $prep['problemas']));
        }

        $radicado = EpsRadicado::deContrato($contrato);
        $numero   = trim((string) ($entrada['radicado'] ?? '')) ?: null;
        $texto    = trim(preg_replace('/\s+/', ' ', (string) ($entrada['texto'] ?? '')));
        $payload  = array_diff_key($prep['portal'], ['archivo' => 1]);

        if (! empty($entrada['error']) && ! $numero) {
            $mensaje = 'Sanitas: no recibió la novedad de cambio de empleador — '.mb_substr((string) $entrada['error'], 0, 300);
            EpsRadicado::marcar($radicado, null, Radicado::ESTADO_ERROR, null, $mensaje, $usuarioId);
            EpsRadicado::bitacora($contrato, $radicado, self::ENTIDAD, 'inicio_laboral', 'fallida', null, $payload, ['texto' => mb_substr($texto, 0, 2000)], (string) $entrada['error'], $usuarioId);

            return ['ok' => false, 'estado' => Radicado::ESTADO_ERROR, 'mensaje' => $mensaje, 'badge' => $this->badge($radicado)];
        }
        if (! $numero) {
            throw new RuntimeException('Falta el número de radicado que dio Sanitas.');
        }

        $constancia = $this->constancia($contrato, $numero, $texto, $entrada['captura'] ?? null);
        $soporte    = $this->conFormulario($contrato, $constancia);
        $ruta = EpsRadicado::guardarPdf($contrato, $soporte ?? $constancia, 'eps_radicado_sanitas');
        $observacion = sprintf('Sanitas: novedad de cambio de empleador radicada por el formulario web con el número %s el %s. Responde por correo en unos 3 días hábiles.',
            $numero, now()->format('d/m/Y H:i'));

        EpsRadicado::marcar($radicado, $numero, Radicado::ESTADO_TRAMITE, $ruta, $observacion, $usuarioId);
        EpsRadicado::bitacora($contrato, $radicado, self::ENTIDAD, 'inicio_laboral', 'exitosa', $numero, $payload, ['texto' => mb_substr($texto, 0, 2000)], null, $usuarioId, $ruta);

        return ['ok' => true, 'estado' => $radicado->estado, 'radicado' => $numero, 'mensaje' => $observacion, 'pdf' => (bool) $ruta, 'con_formulario' => (bool) $soporte, 'badge' => $this->badge($radicado)];
    }

    /**
     * Constancia y formulario enviado en un solo PDF: la primera hoja dice que
     * Sanitas lo recibió y las siguientes muestran qué se le mandó, con la firma.
     * El formulario es el último que se generó para adjuntar, que es el que tomó
     * el robot. Si no está o no se puede leer queda la constancia sola.
     */
    private function conFormulario(Contrato $contrato, ?string $constancia): ?string
    {
        if (! $constancia) {
            return null;
        }
        $disco = Storage::disk('local');
        $formulario = collect($disco->files(EpsRadicado::carpeta($contrato)))
            ->filter(fn ($f) => str_starts_with(basename($f), 'eps_formulario_sanitas_'))
            ->sort()->last();
        if (! $formulario) {
            return null;
        }

        try {
            $pdf = new Fpdi;
            foreach ([$constancia, $disco->get($formulario)] as $binario) {
                $paginas = $pdf->setSourceFile(StreamReader::createByString($binario));
                for ($n = 1; $n <= $paginas; $n++) {
                    $pagina = $pdf->importPage($n);
                    $tam = $pdf->getTemplateSize($pagina);
                    $pdf->AddPage($tam['orientation'], [$tam['width'], $tam['height']]);
                    $pdf->useTemplate($pagina);
                }
            }

            return $pdf->Output('S');
        } catch (\Throwable $e) {
            Log::warning("Sanitas: no se pudo unir el formulario a la constancia del contrato {$contrato->id}: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Cómo queda la insignia del radicado en el listado de Afiliaciones, para que el
     * modal la actualice en su sitio sin recargar la página (mismos datos que pinta
     * la fila en el servidor).
     */
    private function badge(Radicado $radicado): array
    {
        $r = $radicado->fresh();

        return [
            'id'     => $r->id,
            'clase'  => $r->estadoClaseEfectiva(),
            'texto'  => $r->estadoTextoEfectivo(),
            'titulo' => $r->textoConfirmacion(),
            'rad'    => [
                'id' => $r->id, 'tipo' => $r->tipo, 'estado' => $r->estado, 'numero_radicado' => $r->numero_radicado,
                'canal_envio' => $r->canal_envio, 'canal_envio_cliente' => $r->canal_envio_cliente,
                'enviado_al_cliente' => $r->enviado_al_cliente, 'ruta_pdf' => $r->ruta_pdf, 'observacion' => $r->observacion,
            ],
        ];
    }

    /** PDF con lo que mostró Sanitas al radicar: número, texto de la página y la captura si la hubo. */
    private function constancia(Contrato $contrato, string $numero, string $texto, ?string $captura): ?string
    {
        $imagen = $captura && preg_match('/^[A-Za-z0-9+\/=]+$/', $captura) ? 'data:image/jpeg;base64,'.$captura : null;
        $cliente = $contrato->cliente;
        $e = fn ($s) => e((string) $s);

        $html = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#0f172a}h1{font-size:15px;margin:0 0 6px}'
            .'table{border-collapse:collapse;margin:8px 0 10px}td{padding:3px 8px;border:1px solid #cbd5e1}.t{color:#475569}.txt{border:1px solid #e2e8f0;padding:8px;line-height:1.5}</style></head><body>'
            .'<h1>Constancia de radicación — EPS Sanitas</h1>'
            .'<div class="t">Formulario web "Novedades a la afiliación" · tipo de novedad: Cambio de empleador</div>'
            .'<table>'
            .'<tr><td class="t">Número de radicado</td><td><strong>'.$e($numero).'</strong></td></tr>'
            .'<tr><td class="t">Fecha</td><td>'.now()->format('d/m/Y H:i').'</td></tr>'
            .'<tr><td class="t">Trabajador</td><td>'.$e(trim(($cliente?->primer_nombre ?? '').' '.($cliente?->segundo_nombre ?? '').' '.($cliente?->primer_apellido ?? '').' '.($cliente?->segundo_apellido ?? ''))).' — '.$e($cliente?->tipo_doc).' '.$e($contrato->cedula).'</td></tr>'
            .'<tr><td class="t">Empleador</td><td>'.$e($contrato->razonSocial?->razon_social).' — NIT '.$e($contrato->razonSocial?->nit).'</td></tr>'
            .'</table>'
            .($texto !== '' ? '<div class="t">Lo que mostró la página de Sanitas:</div><div class="txt">'.$e(mb_substr($texto, 0, 4000)).'</div>' : '')
            .($imagen ? '<div style="margin-top:10px"><img src="'.$imagen.'" style="width:100%"></div>' : '')
            .'</body></html>';

        // Con la fuente completa la constancia pesaba ~880 KB; con solo los caracteres usados, ~20 KB.
        return Pdf::loadHTML($html)->setPaper('letter')->setOption('isFontSubsettingEnabled', true)->output();
    }

    private function observaciones(Contrato $contrato): string
    {
        $rs = $contrato->razonSocial;

        return sprintf('Cambio de empleador: inicio de relación laboral con %s NIT %s desde el %s. Se adjunta el formulario con la novedad 9 (inicio de relación laboral).',
            $rs?->razon_social, $rs?->nit, $contrato->fecha_ingreso?->format('d/m/Y'));
    }

    private function digitos(?string $s): string
    {
        return preg_replace('/\D/', '', (string) $s);
    }
}
