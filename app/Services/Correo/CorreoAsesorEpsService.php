<?php

namespace App\Services\Correo;

use App\Models\Contrato;
use App\Models\CorreoAfiliacion;
use App\Models\DocumentoCliente;
use App\Models\Radicado;
use App\Services\Afiliaciones\DatosAfiliacion;
use App\Services\Afiliaciones\PortalesEntidades;
use App\Services\EpsPortal\EpsRadicado;
use App\Services\FormularioEpsService;
use App\Services\Sos\SosCorreoService;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Afiliación a una EPS por correo a su asesor comercial, para las EPS que no
 * tienen portal de empleador (hoy Comfenalco Valle / delagente con Lola Baena).
 *
 * Replica el correo que Brygar mandaba a mano desde su Gmail: el bloque de datos
 * del cotizante (el mismo de "📋 Ver Datos"), el formulario de la EPS firmado y,
 * si está cargada, la copia del documento de identidad. El agente del buzón
 * (`correos:revisar-buzon`) lee la respuesta del asesor y deja el radicado en OK.
 *
 * La configuración de cada EPS vive en `afiliaciones_correo.asesores.{entidad}`
 * (nombre, código de la EPS, asesor principal y reemplazo). S.O.S. tiene su
 * propio servicio porque además depende del portal (SosCorreoService).
 */
class CorreoAsesorEpsService
{
    public function __construct(private FormularioEpsService $formularios, private SosCorreoService $sos) {}

    /** Las EPS que se afilian por este correo (clave => configuración). */
    public static function entidades(): array
    {
        return collect(config('afiliaciones_correo.asesores', []))
            ->filter(fn ($c, $clave) => $clave !== 'sos' && ! empty($c['codigo_eps']))
            ->all();
    }

    public function conf(string $entidad): array
    {
        $conf = self::entidades()[$entidad] ?? null;
        if (! $conf) {
            throw new RuntimeException("La EPS «{$entidad}» no se afilia por correo al asesor.");
        }

        return $conf;
    }

    /** Arma el correo sin enviarlo: destinatario, asunto, texto, adjuntos y lo que falta. */
    public function preparar(Contrato $contrato, string $entidad): array
    {
        $conf = $this->conf($entidad);
        $contrato->loadMissing(['cliente.eps', 'cliente.municipio', 'cliente.departamento', 'eps', 'plan', 'razonSocial', 'arl', 'pension']);
        $cliente = $contrato->cliente;
        $rs      = $contrato->razonSocial;
        $eps     = $contrato->eps ?: $cliente?->eps;
        $problemas = [];
        $avisos    = [];

        if ($eps?->codigo !== $conf['codigo_eps']) {
            $problemas[] = "La EPS del contrato no es {$conf['nombre_entidad']}.";
        }
        if (! $cliente) {
            $problemas[] = 'El contrato no tiene cliente en BryNex.';
        }
        if (! $contrato->plan?->incluye_eps) {
            $problemas[] = 'El plan del contrato no incluye EPS.';
        }
        if (! $eps?->formulario_pdf) {
            $problemas[] = "No hay formulario de {$conf['nombre_entidad']} configurado en BryNex.";
        }

        $independiente = (bool) $rs?->es_independiente;
        $nombre  = $cliente ? $this->nombre($cliente) : '';
        $tipoDoc = strtoupper((string) $cliente?->tipo_doc) ?: 'CC';
        $cedula  = $cliente ? $this->documentoIdentidad($contrato) : null;
        if ($cliente && ! $cedula) {
            $avisos[] = 'No hay copia del documento de identidad del cliente (se envía preferiblemente). Puedes subirla aquí o enviar sin ella.';
        }
        // Sin firma dibujada el formulario sale con ese espacio en blanco y la
        // EPS lo devuelve, así que aquí no es un aviso más: el envío se frena.
        $faltaFirma = $cliente && ! FormularioEpsService::tieneFirma($cliente);
        if ($faltaFirma) {
            $avisos[] = 'Falta la firma del contratista: ábrele el formulario y pídele que la dibuje (✍️ Firmar). Sin ella no se puede enviar.';
        }
        if ($cliente && (! $cliente->direccion_vivienda || ! $cliente->celular)) {
            $avisos[] = 'Al cliente le falta dirección o celular: el asesor puede pedirlos.';
        }

        $beneficiarios = $cliente ? $cliente->beneficiarios()->where('aliado_id', $contrato->aliado_id)->get() : collect();
        $docsBenef = $beneficiarios->isNotEmpty()
            ? DocumentoCliente::where('aliado_id', $contrato->aliado_id)->where('cc_cliente', $contrato->cedula)
                ->whereNotNull('doc_beneficiario')->orderBy('id')->get()
            : collect();
        if ($beneficiarios->isNotEmpty() && $docsBenef->isEmpty()) {
            $avisos[] = 'El cliente tiene beneficiarios pero no hay documentos de ellos cargados (registro civil, declaración extrajuicio…).';
        }

        // El asesor de la empresa en su pestaña de portales; si no tiene, el general.
        [$propio, $reemplazo] = PortalesEntidades::asesores($entidad, $rs?->nit);
        $principal = $propio && strcasecmp($propio['correo'], $conf['principal']['correo']) !== 0
            ? $propio
            : $conf['principal'];
        $asunto = 'Solicitud de Afiliación'.($independiente ? ' Independiente' : '')." – {$nombre} – {$tipoDoc} {$contrato->cedula}";

        $salario = (float) ($contrato->salario ?: $contrato->ibc);
        $ciudad  = trim(implode(' - ', array_filter([mb_strtoupper((string) $cliente?->municipio?->nombre), mb_strtoupper((string) $cliente?->departamento?->nombre)])));
        $datos = [
            'AFILIACIÓN EPS "'.mb_strtoupper($conf['nombre_formulario'] ?? $conf['nombre_entidad']).'"',
            '',
            'RAZÓN SOCIAL: '.($independiente ? 'INDEPENDIENTE' : $rs?->razon_social),
            'NIT: '.($independiente ? $contrato->cedula : $rs?->nit),
            "NOMBRE: {$nombre}",
            "CÉDULA: {$contrato->cedula}",
            'ARL: '.(mb_strtoupper((string) ($contrato->arl?->nombre_arl ?? $contrato->arl?->razon_social)) ?: '—'),
            'PENSIÓN: '.(mb_strtoupper((string) $contrato->pension?->razon_social) ?: '—'),
            'SALARIO: '.($salario ? '$ '.number_format($salario, 0, ',', '.') : '—'),
            'FECHA_INGRESO: '.($contrato->fecha_ingreso?->format('d/m/Y') ?? '—'),
            'CARGO: '.(mb_strtoupper((string) $contrato->cargo) ?: 'x'),
            'DIRECCIÓN: '.mb_strtoupper((string) $cliente?->direccion_vivienda),
            'BARRIO: '.mb_strtoupper((string) $cliente?->barrio),
            "CIUDAD: {$ciudad}",
            'CELULAR: '.$cliente?->celular,
            'CORREO: '.$cliente?->correo,
        ];
        if ($beneficiarios->isNotEmpty()) {
            $datos[] = 'BENEFICIARIOS: '.$beneficiarios->map(fn ($b) => trim("{$b->nombres} ({$b->parentesco}, {$b->tipo_doc} {$b->n_documento})"))->implode('; ');
        }

        $adjuntosTxt = 'el formulario'.($cedula ? ' y la copia del documento de identidad' : '').($docsBenef->isNotEmpty() ? ', junto con los documentos de los beneficiarios' : '');
        $lineas = array_merge([
            'Un cordial saludo, '.Str::before($principal['nombre'], ' ').'.',
            '',
            'Solicito muy amablemente la afiliación a la EPS del cotizante '.($independiente ? 'independiente' : 'dependiente')."; le envío los datos y adjunto {$adjuntosTxt}.",
            '',
        ], $datos, [
            '',
            'Quedo atenta a cualquier requerimiento. Muchas gracias por su colaboración.',
            '',
            'Brygar Seguridad Social',
        ]);

        $adjuntos = [['clave' => 'formulario', 'nombre' => $this->archivo('Formulario_'.str_replace(' ', '_', mb_strtoupper($conf['nombre_formulario'] ?? $conf['nombre_entidad']))."_{$nombre}", 'pdf'), 'origen' => 'Se genera al enviar (firmado)']];
        if ($cedula) {
            $adjuntos[] = ['clave' => 'doc:'.$cedula->id, 'nombre' => $this->archivo("{$tipoDoc} {$nombre}", pathinfo($cedula->ruta, PATHINFO_EXTENSION)), 'origen' => 'Documentos del cliente'];
        }
        foreach ($docsBenef as $d) {
            $adjuntos[] = ['clave' => 'doc:'.$d->id, 'nombre' => $this->archivo(Str::upper(str_replace('_', ' ', $d->tipo_documento))." {$d->doc_beneficiario}", pathinfo($d->ruta, PATHINFO_EXTENSION)), 'origen' => 'Beneficiario '.$d->doc_beneficiario];
        }

        $previos = CorreoAfiliacion::where('contrato_id', $contrato->id)->where('entidad', $entidad)
            ->orderByDesc('id')->limit(5)->get(['id', 'para', 'asunto', 'estado', 'enviado_at', 'vence_at', 'respondido_at']);
        if ($previos->contains(fn ($p) => $p->estado === 'enviado')) {
            $avisos[] = "Ya hay un correo enviado a {$conf['nombre_entidad']} para este contrato esperando respuesta. Enviar otro sirve como recordatorio.";
        }
        $radicado = Radicado::where('contrato_id', $contrato->id)->where('tipo', Radicado::TIPO_EPS)->first();
        if ($radicado?->estado === Radicado::ESTADO_OK) {
            $avisos[] = 'El radicado de EPS ya está en OK.';
        }

        return [
            'entidad'       => $entidad,
            'nombre_entidad' => $conf['nombre_entidad'],
            'problemas'     => $problemas,
            'avisos'        => $avisos,
            'falta_firma'   => $faltaFirma,
            'independiente' => $independiente,
            'buzon'         => DatosAfiliacion::buzon($contrato->aliado_id),
            'para'          => $principal,
            'reemplazo'     => $reemplazo ?? ($conf['reemplazo'] ?? null),
            'asunto'        => $asunto,
            'cuerpo'        => implode("\n", $lineas),
            'adjuntos'      => $adjuntos,
            'beneficiarios' => $beneficiarios->count(),
            'vence'         => $this->sos->vencimiento(now())->format('d/m/Y H:i'),
            'previos'       => $previos,
        ];
    }

    /** Envía lo que la persona revisó en la vista previa y deja el radicado en trámite. */
    public function enviar(Contrato $contrato, string $entidad, array $datos, ?int $usuarioId): CorreoAfiliacion
    {
        $conf = $this->conf($entidad);
        $prep = $this->preparar($contrato, $entidad);
        if ($prep['problemas']) {
            throw new RuntimeException(implode(' ', $prep['problemas']));
        }

        $para = $this->correos($datos['para']);
        $cc   = $this->correos($datos['cc'] ?? '');
        if (! $para) {
            throw new RuntimeException('Indica a quién va el correo.');
        }

        $buzon    = BuzonGmail::delAliado($contrato->aliado_id);
        $radicado = EpsRadicado::deContrato($contrato);
        [$adjuntos, $guardados] = $this->armarAdjuntos($contrato, $entidad, $prep['adjuntos']);

        $registro = CorreoAfiliacion::create([
            'aliado_id'   => $contrato->aliado_id,
            'contrato_id' => $contrato->id,
            'radicado_id' => $radicado->id,
            'entidad'     => $entidad,
            'motivo'      => $prep['independiente'] ? 'independiente' : 'manual',
            'buzon'       => $buzon->cuenta(),
            'para'        => implode(', ', $para),
            'cc'          => $cc ? implode(', ', $cc) : null,
            'asunto'      => $datos['asunto'],
            'cuerpo'      => $datos['cuerpo'],
            'adjuntos'    => $guardados,
            'message_id'  => 'pendiente',
            'estado'      => 'fallido',
            'usuario_id'  => $usuarioId,
        ]);

        try {
            $messageId = $buzon->enviar($para, $datos['asunto'], $datos['cuerpo'], $adjuntos, $cc, 'Brygar Seguridad social');
        } catch (Throwable $e) {
            $registro->update(['error' => mb_substr($e->getMessage(), 0, 500)]);
            throw new RuntimeException('Gmail no envió el correo: '.$e->getMessage());
        }

        $vence = $this->sos->vencimiento(now());
        $registro->update(['message_id' => $messageId, 'estado' => 'enviado', 'enviado_at' => now(), 'vence_at' => $vence, 'error' => null]);

        EpsRadicado::marcar($radicado, null, Radicado::ESTADO_TRAMITE, $guardados[0]['ruta'] ?? null,
            sprintf('%s: afiliación enviada por correo a %s el %s. Se espera respuesta hasta el %s.',
                $conf['nombre_entidad'], implode(', ', $para), now()->format('d/m/Y H:i'), $vence->format('d/m/Y H:i')),
            $usuarioId);

        EpsRadicado::bitacora($contrato, $radicado, $entidad, 'correo_asesor', 'exitosa', null,
            ['para' => $para, 'cc' => $cc, 'asunto' => $datos['asunto'], 'adjuntos' => array_column($guardados, 'nombre'), 'correo_id' => $registro->id],
            ['message_id' => $messageId], null, $usuarioId, $guardados[0]['ruta'] ?? null);

        return $registro;
    }

    /** @return array{0: array, 1: array} adjuntos para enviar [nombre, contenido, tipo] y lo que queda registrado [nombre, ruta] */
    private function armarAdjuntos(Contrato $contrato, string $entidad, array $previstos): array
    {
        $disco = \Illuminate\Support\Facades\Storage::disk('local');
        $contrato->loadMissing(['cliente.municipio', 'cliente.departamento', 'cliente.beneficiarios', 'razonSocial', 'eps', 'arl', 'pension']);

        // Comfenalco Valle devolvió una afiliación el 16-sep-2026 porque la firma
        // iba en letra del PDF; ahora el formulario sale en blanco si nadie la
        // dibujó, y mandarlo así solo gasta otro viaje de ida y vuelta.
        if (! FormularioEpsService::tieneFirma($contrato->cliente)) {
            throw new RuntimeException('El formulario no tiene la firma del contratista. Ábrelo y dibújala en «✍️ Firmar» antes de enviarlo: las EPS devuelven los formularios sin firma a mano alzada.');
        }

        $rutaFormulario = EpsRadicado::guardarPdf($contrato, $this->formularios->generar($contrato, true, []), "eps_formulario_{$entidad}_correo");
        if (! $rutaFormulario) {
            throw new RuntimeException('No se pudo generar el formulario de EPS del contrato.');
        }

        $enviar = [];
        $guardados = [];
        foreach ($previstos as $a) {
            $ruta = match (true) {
                $a['clave'] === 'formulario' => $rutaFormulario,
                str_starts_with($a['clave'], 'doc:') => DocumentoCliente::where('aliado_id', $contrato->aliado_id)
                    ->where('cc_cliente', $contrato->cedula)->whereKey((int) substr($a['clave'], 4))->value('ruta'),
                default => null,
            };
            if (! $ruta || ! $disco->exists($ruta)) {
                throw new RuntimeException("No se encontró el archivo de «{$a['nombre']}».");
            }
            $enviar[] = ['nombre' => $a['nombre'], 'contenido' => $disco->get($ruta), 'tipo' => $disco->mimeType($ruta) ?: 'application/octet-stream'];
            $guardados[] = ['nombre' => $a['nombre'], 'ruta' => $ruta];
        }

        $total = array_sum(array_map(fn ($a) => strlen($a['contenido']), $enviar));
        if ($total > 20 * 1024 * 1024) {
            throw new RuntimeException('Los adjuntos pesan más de 20 MB y Gmail los rechazaría.');
        }

        return [$enviar, $guardados];
    }

    /** El documento de identidad más reciente del cliente (no de beneficiarios). */
    private function documentoIdentidad(Contrato $contrato): ?DocumentoCliente
    {
        return DocumentoCliente::where('aliado_id', $contrato->aliado_id)
            ->where('cc_cliente', $contrato->cedula)
            ->whereNull('doc_beneficiario')
            ->whereIn('tipo_documento', ['cedula', 'tarjeta_identidad'])
            ->orderByDesc('id')
            ->first();
    }

    private function nombre($cliente): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtoupper(implode(' ', array_filter([
            $cliente->primer_nombre, $cliente->segundo_nombre, $cliente->primer_apellido, $cliente->segundo_apellido,
        ])))));
    }

    private function archivo(string $base, string $extension): string
    {
        return trim(preg_replace('/[^\pL\pN ._-]+/u', '', $base)).'.'.strtolower($extension ?: 'pdf');
    }

    /** @return string[] */
    private function correos(string $lista): array
    {
        return collect(preg_split('/[,;\s]+/', $lista))->map(fn ($c) => trim($c))
            ->filter(fn ($c) => filter_var($c, FILTER_VALIDATE_EMAIL))->unique()->values()->all();
    }
}
