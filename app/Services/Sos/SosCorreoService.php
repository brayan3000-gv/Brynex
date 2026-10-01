<?php

namespace App\Services\Sos;

use App\Models\Contrato;
use App\Models\CorreoAfiliacion;
use App\Models\DocumentoCliente;
use App\Models\Radicado;
use App\Services\Afiliaciones\DatosAfiliacion;
use App\Services\Correo\BuzonGmail;
use App\Services\EpsPortal\EpsRadicado;
use App\Services\FormularioEpsService;
use App\Services\MoraClienteService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Plan B de S.O.S.: la afiliación por correo al asesor comercial.
 *
 * Se usa cuando el portal marca la X (validación no favorable) y para los
 * independientes, que el portal de empleadores no cubre. Replica lo que Brygar
 * hacía a mano desde seguridadsocial.brygar@gmail.com (156 hilos desde 2021):
 * formulario firmado + cédula (+ documentos de beneficiarios), y el asesor
 * responde con `CC{cédula}_N.pdf` radicado, casi siempre al día hábil siguiente.
 */
class SosCorreoService
{
    public const ENTIDAD = 'sos';

    public function __construct(private FormularioEpsService $formularios) {}

    /**
     * Arma el correo sin enviarlo: destinatario, asunto, texto, adjuntos y lo que falta.
     */
    public function preparar(Contrato $contrato, string $motivo = 'manual', bool $conBeneficiarios = true, string $detalle = ''): array
    {
        $contrato->loadMissing(['cliente.eps', 'cliente.municipio', 'cliente.departamento', 'eps', 'plan', 'razonSocial']);
        $cliente = $contrato->cliente;
        $rs      = $contrato->razonSocial;
        $eps     = $contrato->eps ?: $cliente?->eps;
        $problemas = [];
        $avisos    = [];

        if ($eps?->codigo !== SosNovedadService::CODIGO_EPS) {
            $problemas[] = 'La EPS del contrato no es S.O.S.';
        }
        if (! $cliente) {
            $problemas[] = 'El contrato no tiene cliente en BryNex.';
        }
        if (! $contrato->plan?->incluye_eps) {
            $problemas[] = 'El plan del contrato no incluye EPS.';
        }

        $independiente = (bool) $rs?->es_independiente;
        $nombre        = $cliente ? $this->nombre($cliente) : '';
        $tipoDoc       = strtoupper((string) $cliente?->tipo_doc) ?: 'CC';

        $cedula = $cliente ? $this->documentoIdentidad($contrato) : null;
        if ($cliente && ! $cedula) {
            // Opcional: se puede enviar sin ella y subirla aquí si se tiene.
            $avisos[] = 'No hay copia del documento de identidad del cliente. Puedes subirla aquí o enviar sin ella.';
        }

        // La firma del contratista va dibujada: en letra del PDF las EPS
        // devuelven el formulario (le pasó a Comfenalco el 16-sep-2026). Sin
        // ella el envío se frena, no es un aviso más.
        $faltaFirma = $cliente && ! FormularioEpsService::tieneFirma($cliente);
        if ($faltaFirma) {
            $avisos[] = 'Falta la firma del contratista: ábrele el formulario y pídele que la dibuje. Sin ella no se puede enviar.';
        }

        $beneficiarios = $cliente ? $cliente->beneficiarios()->where('aliado_id', $contrato->aliado_id)->get() : collect();
        $docsBenef = $conBeneficiarios && $beneficiarios->isNotEmpty()
            ? DocumentoCliente::where('aliado_id', $contrato->aliado_id)->where('cc_cliente', $contrato->cedula)
                ->whereNotNull('doc_beneficiario')->orderBy('id')->get()
            : collect();
        if ($conBeneficiarios && $beneficiarios->isNotEmpty() && $docsBenef->isEmpty()) {
            $avisos[] = 'El cliente tiene beneficiarios pero no hay documentos de ellos cargados (registro civil, declaración extrajuicio…).';
        }

        $residencia = trim(implode(', ', array_filter([$cliente?->municipio?->nombre, $cliente?->departamento?->nombre])));
        if ($cliente && ! $cliente->municipio_id) {
            $avisos[] = 'El cliente no tiene municipio de residencia: S.O.S. puede devolverlo si no coincide con su sistema.';
        }

        [$principal, $reemplazo] = $this->asesores($contrato);

        $tipoTxt = $independiente ? 'Independiente' : 'Dependiente';
        $asunto = 'Solicitud de Afiliación EPS S.O.S. '.($independiente ? 'Independiente ' : '')
            .($conBeneficiarios && $beneficiarios->isNotEmpty() ? 'con Beneficiarios ' : '')
            ."– {$nombre} – {$tipoDoc} {$contrato->cedula}".($independiente ? '' : ' – '.$rs?->razon_social);

        $lineas = [
            'Un cordial saludo, '.Str::before($principal['nombre'], ' ').'.',
            '',
            "Solicito muy amablemente la afiliación a la EPS S.O.S. del cotizante {$tipoTxt}:",
            '',
            "• Nombre: {$nombre}",
            "• Documento: {$tipoDoc} {$contrato->cedula}",
        ];
        if (! $independiente) {
            $lineas[] = "• Empresa: {$rs?->razon_social} (NIT {$rs?->nit})";
        }
        $lineas[] = '• Fecha de ingreso: '.($contrato->fecha_ingreso?->format('d/m/Y') ?? '—');
        if ($residencia) {
            $lineas[] = "• Municipio de residencia: {$residencia}";
        }
        if ($conBeneficiarios && $beneficiarios->isNotEmpty()) {
            $lineas[] = '• Beneficiarios: '.$beneficiarios->map(fn ($b) => trim("{$b->nombres} ({$b->parentesco}, {$b->tipo_doc} {$b->n_documento})"))->implode('; ');
        }
        if ($motivo === 'portal_rechazo') {
            $lineas[] = '';
            $lineas[] = trim($detalle) !== ''
                ? 'Por el portal de empleadores la novedad fue devuelta con el motivo: «'.trim($detalle).'».'
                : 'El portal de empleadores no permitió registrar la novedad (validación no favorable).';
        }
        $lineas = array_merge($lineas, [
            '',
            'Adjunto el formulario debidamente firmado'.($cedula ? ', la carta de derechos y la copia del documento de identidad' : ' y la carta de derechos')
                .($docsBenef->isNotEmpty() ? ', junto con los documentos de los beneficiarios' : '').'.',
            '',
            'Quedo atenta a cualquier requerimiento. Muchas gracias por su colaboración.',
            '',
            'Brygar Seguridad Social',
        ]);

        $adjuntos = [
            ['clave' => 'formulario', 'nombre' => $this->archivo("Formulario_S.O.S._{$nombre}", 'pdf'), 'origen' => 'Se genera al enviar (3 páginas, firmado)'],
            ['clave' => 'carta', 'nombre' => $this->archivo("Carta_derechos_{$nombre}", 'pdf'), 'origen' => 'Página 3 del formulario'],
        ];
        if ($cedula) {
            $adjuntos[] = ['clave' => 'doc:'.$cedula->id, 'nombre' => $this->archivo("{$tipoDoc} {$nombre}", pathinfo($cedula->ruta, PATHINFO_EXTENSION)), 'origen' => 'Documentos del cliente'];
        }
        foreach ($docsBenef as $d) {
            $adjuntos[] = ['clave' => 'doc:'.$d->id, 'nombre' => $this->archivo(Str::upper(str_replace('_', ' ', $d->tipo_documento))." {$d->doc_beneficiario}", pathinfo($d->ruta, PATHINFO_EXTENSION)), 'origen' => 'Beneficiario '.$d->doc_beneficiario];
        }

        $previos = CorreoAfiliacion::where('contrato_id', $contrato->id)->where('entidad', self::ENTIDAD)
            ->orderByDesc('id')->limit(5)->get(['id', 'para', 'asunto', 'estado', 'enviado_at', 'vence_at', 'respondido_at']);
        if ($previos->contains(fn ($p) => $p->estado === 'enviado')) {
            $avisos[] = 'Ya hay un correo enviado a S.O.S. para este contrato esperando respuesta. Enviar otro sirve como recordatorio.';
        }

        return [
            'problemas'     => $problemas,
            'avisos'        => $avisos,
            'falta_firma'   => $faltaFirma,
            'independiente' => $independiente,
            'buzon'         => DatosAfiliacion::buzon($contrato->aliado_id),
            'para'          => $principal,
            'reemplazo'     => $reemplazo,
            'asunto'        => $asunto,
            'cuerpo'        => implode("\n", $lineas),
            'adjuntos'      => $adjuntos,
            'beneficiarios' => $beneficiarios->count(),
            'vence'         => $this->vencimiento(now())->format('d/m/Y H:i'),
            'previos'       => $previos,
        ];
    }

    /**
     * Envía el correo (con lo que la persona revisó en la vista previa) y deja el
     * radicado en trámite.
     *
     * @param  array{para:string, cc?:?string, asunto:string, cuerpo:string, motivo:string, con_beneficiarios?:bool}  $datos
     */
    public function enviar(Contrato $contrato, array $datos, ?int $usuarioId): CorreoAfiliacion
    {
        $prep = $this->preparar($contrato, $datos['motivo'], (bool) ($datos['con_beneficiarios'] ?? true), (string) ($datos['detalle'] ?? ''));
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
        [$adjuntos, $guardados] = $this->armarAdjuntos($contrato, $prep['adjuntos'], (bool) ($datos['con_beneficiarios'] ?? true));

        $registro = CorreoAfiliacion::create([
            'aliado_id'   => $contrato->aliado_id,
            'contrato_id' => $contrato->id,
            'radicado_id' => $radicado->id,
            'entidad'     => self::ENTIDAD,
            'motivo'      => $datos['motivo'],
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

        $vence = $this->vencimiento(now());
        $registro->update(['message_id' => $messageId, 'estado' => 'enviado', 'enviado_at' => now(), 'vence_at' => $vence, 'error' => null]);

        $motivos = ['portal_rechazo' => 'el portal no validó la novedad', 'independiente' => 'independiente', 'manual' => 'envío manual'];
        EpsRadicado::marcar($radicado, null, Radicado::ESTADO_TRAMITE, $guardados[0]['ruta'] ?? null,
            sprintf('S.O.S.: afiliación enviada por correo a %s el %s (%s). Se espera respuesta hasta el %s.',
                implode(', ', $para), now()->format('d/m/Y H:i'), $motivos[$datos['motivo']] ?? $datos['motivo'], $vence->format('d/m/Y H:i')),
            $usuarioId);

        EpsRadicado::bitacora($contrato, $radicado, self::ENTIDAD, 'correo_asesor', 'exitosa', null,
            ['para' => $para, 'cc' => $cc, 'asunto' => $datos['asunto'], 'adjuntos' => array_column($guardados, 'nombre'), 'correo_id' => $registro->id],
            ['message_id' => $messageId], null, $usuarioId, $guardados[0]['ruta'] ?? null);

        return $registro;
    }

    /** Sube la copia del documento de identidad del cliente desde el modal. */
    public function subirDocumento(Contrato $contrato, UploadedFile $archivo, ?int $usuarioId): DocumentoCliente
    {
        $contrato->loadMissing('cliente');
        $tipo = strtoupper((string) $contrato->cliente?->tipo_doc) === 'TI' ? 'tarjeta_identidad' : 'cedula';
        $ruta = "documentos/{$contrato->aliado_id}/{$contrato->cedula}/{$tipo}_".time().'_'.Str::random(6).'.'.strtolower($archivo->getClientOriginalExtension());
        Storage::disk('local')->put($ruta, file_get_contents($archivo->getRealPath()));

        return DocumentoCliente::create([
            'aliado_id'      => $contrato->aliado_id,
            'cc_cliente'     => $contrato->cedula,
            'tipo_documento' => $tipo,
            'nombre_archivo' => $archivo->getClientOriginalName(),
            'ruta'           => $ruta,
            'subido_por'     => $usuarioId,
        ]);
    }

    /**
     * Hasta cuándo se espera respuesta: enviado en la mañana de un día hábil, hasta
     * las 6:00 p. m. de ese día; en la tarde o en día no hábil, hasta las 12:00 m.
     * del siguiente día hábil.
     */
    public function vencimiento(\Carbon\CarbonInterface $desde): Carbon
    {
        $habil = fn ($d) => ! $d->isWeekend()
            && ! in_array($d->format('Y-m-d'), MoraClienteService::festivosColombia((int) $d->format('Y')), true);

        if ($habil($desde) && $desde->hour < (int) config('afiliaciones_correo.corte_manana', 12)) {
            return Carbon::instance($desde)->setTime((int) config('afiliaciones_correo.vence_manana', 18), 0);
        }

        $dia = Carbon::instance($desde)->addDay()->setTime((int) config('afiliaciones_correo.vence_siguiente', 12), 0);
        while (! $habil($dia)) {
            $dia->addDay();
        }

        return $dia;
    }

    /**
     * @return array{0: array, 1: array} adjuntos para enviar [nombre, contenido, tipo] y lo que queda registrado [nombre, ruta]
     */
    private function armarAdjuntos(Contrato $contrato, array $previstos, bool $conBeneficiarios): array
    {
        $disco = Storage::disk('local');
        $contrato->loadMissing(['cliente.municipio', 'cliente.departamento', 'cliente.beneficiarios', 'razonSocial', 'eps', 'arl', 'pension']);

        // Igual que en el correo a los demás asesores: sin firma dibujada el
        // formulario sale con ese espacio en blanco y lo devuelven.
        if (! FormularioEpsService::tieneFirma($contrato->cliente)) {
            throw new RuntimeException('El formulario no tiene la firma del contratista. Ábrelo y dibújala en «✍️ Firmar» antes de enviarlo: las EPS devuelven los formularios sin firma a mano alzada.');
        }

        $rutaFormulario = EpsRadicado::guardarPdf($contrato, $this->formularios->generar($contrato, $conBeneficiarios, []), 'eps_formulario_sos_correo');
        if (! $rutaFormulario) {
            throw new RuntimeException('No se pudo generar el formulario de EPS del contrato.');
        }

        $rutaCarta = preg_replace('/\.pdf$/', '_carta_derechos.pdf', $rutaFormulario);
        exec(sprintf(
            '%s -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=pdfwrite -dFirstPage=3 -dLastPage=3 -o %s %s 2>&1',
            is_executable('/usr/bin/gs') ? '/usr/bin/gs' : 'gs',
            escapeshellarg($disco->path($rutaCarta)), escapeshellarg($disco->path($rutaFormulario))
        ), $salida, $codigo);
        if ($codigo !== 0 || ! $disco->exists($rutaCarta)) {
            throw new RuntimeException('No se pudo separar la carta de derechos: '.implode(' ', $salida));
        }

        $enviar = [];
        $guardados = [];
        foreach ($previstos as $a) {
            $ruta = match (true) {
                $a['clave'] === 'formulario' => $rutaFormulario,
                $a['clave'] === 'carta'      => $rutaCarta,
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

    /**
     * Asesor de la razón social: "correo de la entidad" de su clave de S.O.S. en el
     * módulo de claves (o el link, donde se guardó al principio); si no, el de config.
     *
     * @return array{0: array{nombre:string, correo:string}, 1: ?array{nombre:string, correo:string}}
     */
    private function asesores(Contrato $contrato): array
    {
        $conf = config('afiliaciones_correo.asesores.sos');
        // La clave es de la empresa y no del aliado (ver ClaveAcceso::visiblesPara): se
        // busca por NIT, así un contrato de Fecop usa el asesor que se guardó en Brygar.
        $contrato->loadMissing('razonSocial');
        $nit = preg_replace('/\D/', '', (string) $contrato->razonSocial?->nit);
        $clave = DB::table('clave_accesos as c')
            ->join('razones_sociales as rs', 'rs.id', '=', 'c.razon_social_id')
            ->when(strlen($nit) >= 8,
                fn ($q) => $q->whereRaw("REPLACE(REPLACE(REPLACE(ISNULL(rs.nit,''),'-',''),'.',''),' ','') = ?", [$nit]),
                fn ($q) => $q->where('c.razon_social_id', $contrato->razon_social_id))
            ->where('c.tipo', 'EPS')->where('c.entidad', 'like', '%SOS%')->where('c.activo', true)
            ->where('rs.aliado_id', '<>', 1)
            ->orderByRaw('CASE WHEN rs.aliado_id = ? THEN 0 ELSE 1 END', [DatosAfiliacion::ALIADO_PRINCIPAL])
            ->orderByDesc('c.updated_at')
            ->first(['c.correo_entidad', 'c.link_acceso']);

        $correo = collect([$clave?->correo_entidad, $clave?->link_acceso])
            ->map(fn ($v) => trim((string) $v))
            ->first(fn ($v) => filter_var($v, FILTER_VALIDATE_EMAIL));

        $principal = $correo && strcasecmp($correo, $conf['principal']['correo']) !== 0
            ? ['nombre' => 'Asesor S.O.S.', 'correo' => $correo]
            : $conf['principal'];

        return [$principal, $conf['reemplazo'] ?? null];
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
