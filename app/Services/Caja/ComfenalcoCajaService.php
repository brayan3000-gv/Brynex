<?php

namespace App\Services\Caja;

use App\Models\Contrato;
use App\Models\EpsAfiliacion;
use App\Models\Radicado;
use App\Models\RadicadoMovimiento;
use App\Services\EpsPortal\EpsClavePortal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Afiliación a la caja de compensación Comfenalco Valle por su Sucursal Virtual
 * Afiliación (`virtual.comfenalcovalle.com.co/ServiciosWebRyA`).
 *
 * El login es de la empresa y pide captcha, así que la persona entra en su Chrome
 * y la extensión BryNex Portales llena el asistente de 8 pasos con los datos de
 * BryNex; los botones Continuar, la aceptación de términos y Finalizar los pulsa
 * ella. Al terminar, el portal da un número de formulario (1089…) y Comfenalco
 * verifica en 2 días; después llega el correo "Afiliación exitosa" de sirap@.
 *
 * Mapeado y probado con Juan David Varela el 15-sep-2026 (formulario 1089000002377457).
 */
class ComfenalcoCajaService
{
    use ContactoDeCaja;

    public const ENTIDAD = 'comfenalco_caja';

    public const HOST = 'virtual.comfenalcovalle.com.co';

    /** Nombre de la caja en BryNex (tabla cajas). */
    private const CAJA = 'COMFENALCO VALLE';

    /**
     * Cómo puede llamarse esta caja en el llavero de claves.
     *
     * Comfenalco no es una sola empresa: Valle, Cartagena, Antioquia y Quindío
     * son cajas independientes, con portal y clave propios. Un `LIKE
     * '%COMFENALCO%'` las confunde y entrega la clave de otra ciudad, así que
     * el nombre tiene que estar en esta lista —sin el "CAJA " de adelante—.
     */
    private const NOMBRES = ['COMFENALCO', 'COMFENALCO VALLE', 'COMFENALCO VALLE DELAGENTE'];

    /** ¿Ese nombre del llavero es el de Comfenalco Valle y no el de otra regional? */
    public static function esDelValle(string $entidad): bool
    {
        $nombre = trim(preg_replace('/^\s*CAJA\s+/i', '', mb_strtoupper(trim($entidad))));

        return in_array($nombre, self::NOMBRES, true);
    }

    /** Tipo de documento de BryNex → valor del combo del portal. */
    private const TIPOS = ['CC' => 1, 'TI' => 2, 'CE' => 4, 'RC' => 5, 'PA' => 6, 'PP' => 6, 'PT' => 16, 'PPT' => 16];

    /** Estado civil del portal (lo elige la persona en el modal). */
    public const ESTADOS_CIVIL = [1 => 'Soltero', 2 => 'Casado', 3 => 'Viudo', 4 => 'Unión libre', 5 => 'Separado', 6 => 'Divorciado'];

    public const CONTRATOS = [1 => 'Término indefinido', 2 => 'Término fijo', 3 => 'Labor contratada'];

    public const FORMAS_PAGO = [13 => 'Kupi', 10 => 'Daviplata'];

    /**
     * @return array{problemas: string[], avisos: string[], resumen: array, portal: array|null}
     */
    public function preparar(Contrato $contrato): array
    {
        $contrato->loadMissing(['cliente.municipio', 'cliente.departamento', 'plan', 'razonSocial']);
        $cliente = $contrato->cliente;
        $rs      = $contrato->razonSocial;
        $tipo    = strtoupper((string) $cliente?->tipo_doc);
        $caja    = DB::table('cajas')->where('id', $contrato->caja_id)->value('nombre');
        $radicado = Radicado::where('contrato_id', $contrato->id)->where('tipo', 'caja')->first();
        $problemas = [];
        $avisos = [];

        if ($contrato->estado !== 'vigente') {
            $problemas[] = 'El contrato no está vigente.';
        }
        if (! $caja || ! str_contains(mb_strtoupper($caja), self::CAJA)) {
            $problemas[] = 'La caja del contrato no es Comfenalco Valle.';
        }
        if (! $contrato->plan?->incluye_caja) {
            $avisos[] = 'El plan del contrato no marca caja de compensación: revisa antes de afiliar.';
        }
        if (! $rs || $rs->es_independiente) {
            $problemas[] = 'El portal de empresa es para dependientes: el independiente va por correo a servicio al cliente.';
        }
        if (! $cliente) {
            $problemas[] = 'El contrato no tiene cliente en BryNex.';
        } elseif (! isset(self::TIPOS[$tipo])) {
            $problemas[] = "Tipo de documento '{$cliente->tipo_doc}' sin equivalencia en el portal.";
        }
        if (! $contrato->fecha_ingreso) {
            $problemas[] = 'El contrato no tiene fecha de ingreso.';
        }
        if (! $contrato->baseCaja()['salario']) {
            $problemas[] = 'El contrato no tiene salario.';
        }
        if ($radicado?->estado === Radicado::ESTADO_OK) {
            $problemas[] = 'El radicado de caja ya está en OK.';
        }

        $cred = $rs ? EpsClavePortal::para(self::ENTIDAD, '%COMFENALCO%', 'Comfenalco Valle', (string) $rs->nit, 'CAJA', self::esDelValle(...)) : ['error' => 'Sin razón social.'];
        if (isset($cred['error'])) {
            $avisos[] = $cred['error'].' Tendrás que iniciar sesión a mano en el portal.';
        }

        $celular = collect([$cliente?->celular, $cliente?->telefono])
            ->flatMap(fn ($t) => preg_split('/[,;\/|]| - /', (string) $t))
            ->map(fn ($t) => preg_replace('/\D/', '', $t))->first(fn ($t) => strlen($t) === 10) ?: null;
        if (! $celular) {
            $problemas[] = 'El cliente no tiene celular de 10 dígitos: el portal lo exige.';
        }
        if (! $cliente?->direccion_vivienda) {
            $avisos[] = 'El cliente no tiene dirección: habrá que escribirla en el portal (sin # ni -).';
        }
        $beneficiarios = $cliente ? $cliente->beneficiarios()->where('aliado_id', $contrato->aliado_id)->get() : collect();
        if ($beneficiarios->isNotEmpty()) {
            $avisos[] = 'El cliente tiene '.$beneficiarios->count().' beneficiario(s) en BryNex: agrégalos en el paso Beneficiarios del portal o después con una adición.';
        }
        if ($radicado?->numero_radicado && $radicado->estado === Radicado::ESTADO_TRAMITE) {
            $avisos[] = "Este radicado ya está en trámite (formulario {$radicado->numero_radicado}).";
        }

        // El salario que se le declara a la caja no es el del contrato cuando es
        // Tiempo Parcial: ese es el de pensión (ver Contrato::baseCaja()).
        $base = $contrato->baseCaja();

        // Si el trabajador no tiene correo se usa el de su empleador, que es
        // quien hace el trámite y a quien la caja le responde.
        $correo = $this->correo($cliente->correo)
            ?: $this->correoRazonSocial($rs)
            ?: $this->correo($contrato->aliado?->correo, config("afiliaciones_correo.buzones.{$contrato->aliado_id}"));

        if ($correo && ! $cliente->correo) {
            $avisos[] = "El cliente no tiene correo: se usa {$correo}.";
        } elseif (! $correo) {
            $avisos[] = 'Nadie tiene correo (ni el cliente, ni la empresa, ni el aliado): habrá que escribirlo en el portal.';
        }

        $nombre = trim(implode(' ', array_filter([$cliente?->primer_nombre, $cliente?->segundo_nombre, $cliente?->primer_apellido, $cliente?->segundo_apellido])));
        $resumen = [
            'trabajador'   => $nombre,
            'documento'    => trim($tipo.' '.$contrato->cedula),
            'razon_social' => $rs?->razon_social,
            'nit'          => $rs?->nit,
            'caja'         => $caja,
            'fecha_ingreso' => $contrato->fecha_ingreso?->toDateString(),
            'salario'      => $base['salario'],
            'residencia'   => trim(($cliente?->municipio?->nombre ?? '').' · '.($cliente?->departamento?->nombre ?? ''), ' ·'),
            'direccion'    => $cliente?->direccion_vivienda,
            'barrio'       => $cliente?->barrio,
            'celular'      => $celular,
            'beneficiarios' => $beneficiarios->count(),
            'usuario_portal' => $cred['usuario'] ?? null,
            'estado_radicado' => $radicado?->estado,
            'numero_radicado' => $radicado?->numero_radicado,
        ];

        return ['problemas' => $problemas, 'avisos' => $avisos, 'resumen' => $resumen, 'portal' => $problemas ? null : [
            'host'         => self::HOST,
            'empresa'      => $rs->razon_social,
            'nit'          => preg_replace('/\D/', '', (string) $rs->nit),
            'tipoDoc'      => self::TIPOS[$tipo],
            'documento'    => (string) $contrato->cedula,
            'apellido'     => (string) $cliente->primer_apellido,
            'departamento' => (string) $cliente->departamento?->nombre,
            'municipio'    => (string) $cliente->municipio?->nombre,
            'barrio'       => (string) $cliente->barrio,
            'direccion'    => (string) $cliente->direccion_vivienda,
            'direccionEmpresa' => (string) ($rs->direccion ?? ''),   // respaldo cuando la del trabajador no sirve
            'celular'      => $celular,
            'correo'       => $correo,
            'fechaIngreso' => $contrato->fecha_ingreso->format('Y-m-d'),
            // El portal pide «Fecha Registro Documento» cuando el documento es un permiso (PT/PPT).
            'fechaExpedicion' => $cliente->fecha_expedicion ? \Carbon\Carbon::parse($cliente->fecha_expedicion)->format('Y-m-d') : null,
            'salario'      => $base['salario'],
            'cargoTexto'   => mb_strtoupper(trim((string) $contrato->cargo)) ?: 'APOYO ADMINISTRATIVO',
            'listaBeneficiarios' => $beneficiarios->map(fn ($b) => [
                'tipo_doc'   => $b->tipo_doc,
                'documento'  => (string) $b->n_documento,
                'nombre'     => trim((string) $b->nombres),
                'parentesco' => $b->parentesco,
            ])->values()->all(),
        ]];
    }

    /**
     * Guarda los anexos que la caja ya tiene. Solo PDF o imagen, al disco privado
     * (llevan datos personales), y se omiten los que ya están: mismo nombre de
     * archivo para la misma persona.
     *
     * @param  array<int, array{requerido?:?string, nombre:string, doc_beneficiario?:?string, base64:string}>  $docs
     * @return array{guardados:int, repetidos:int, rechazados:int, detalle:string[]}
     */
    public function guardarDocumentos(Contrato $contrato, array $docs, ?int $usuarioId): array
    {
        $cedula = (string) $contrato->cedula;
        $guardados = $repetidos = $rechazados = 0;
        $detalle = [];

        foreach ($docs as $d) {
            $bin = base64_decode((string) $d['base64'], true);
            $esPdf = $bin !== false && str_starts_with($bin, '%PDF');
            $esImagen = $bin !== false && (str_starts_with($bin, "\xFF\xD8\xFF") || str_starts_with($bin, "\x89PNG"));
            if (! $esPdf && ! $esImagen) {
                $rechazados++;
                $detalle[] = "{$d['nombre']}: no es PDF ni imagen";

                continue;
            }

            $docBen = ! empty($d['doc_beneficiario']) ? ltrim(preg_replace('/\D/', '', (string) $d['doc_beneficiario']), '0') : null;
            $yaEsta = DB::table('documentos_cliente')->where('aliado_id', $contrato->aliado_id)->where('cc_cliente', $cedula)
                ->where('nombre_archivo', $d['nombre'])
                ->when($docBen, fn ($q) => $q->where('doc_beneficiario', $docBen), fn ($q) => $q->whereNull('doc_beneficiario'))
                ->exists();
            if ($yaEsta) {
                $repetidos++;

                continue;
            }

            $tipo = $this->tipoDocumento((string) ($d['requerido'] ?? ''), (string) $d['nombre']);
            $ruta = "documentos/{$contrato->aliado_id}/{$cedula}/{$tipo}_".time().'_'.\Illuminate\Support\Str::random(6).($esPdf ? '.pdf' : (str_starts_with($bin, "\x89PNG") ? '.png' : '.jpg'));
            \Illuminate\Support\Facades\Storage::disk('local')->put($ruta, $bin);
            \App\Models\DocumentoCliente::create([
                'aliado_id' => $contrato->aliado_id, 'cc_cliente' => $cedula, 'doc_beneficiario' => $docBen,
                'tipo_documento' => $tipo, 'nombre_archivo' => $d['nombre'], 'ruta' => $ruta, 'subido_por' => $usuarioId,
            ]);
            $guardados++;
            $detalle[] = ($docBen ? "beneficiario {$docBen}: " : 'trabajador: ').$d['nombre'];
        }

        return compact('guardados', 'repetidos', 'rechazados', 'detalle');
    }

    /** Tipo de documento de BryNex según el nombre que le da la caja al anexo. */
    private function tipoDocumento(string $requerido, string $archivo): string
    {
        $t = mb_strtolower($requerido.' '.$archivo);

        return match (true) {
            str_contains($t, 'juramentada') => 'decl_juramentada',
            str_contains($t, 'registro civil') || str_starts_with(mb_strtolower($archivo), 'rc') => 'registro_civil',
            str_contains($t, 'identidad') && str_contains(mb_strtolower($archivo), 'ti') => 'tarjeta_identidad',
            str_contains($t, 'identidad') || str_contains($t, 'cedula') || str_contains($t, 'cédula') => 'cedula',
            default => 'otro',
        };
    }

    /**
     * Posiciones (mm, desde la esquina superior izquierda de la hoja carta) donde se
     * estampa la firma en la declaración juramentada oficial del portal.
     * La hoja es tamaño oficio (215,9 × 330,2 mm). El único recuadro que lleva firma
     * del trabajador es «Firma del declarante», al pie; «Firma del padre/madre» (sección 3)
     * y «Firma del cónyuge… cuidador(a)» (sección 4) solo aplican si esas secciones
     * traen filas. Medido a ojo sobre el PDF real de la caja (30-sep-2026): verificar
     * con pdftoppm si la caja cambia el formato.
     */
    private const FIRMA_EN_DECLARACION = [
        // Firma del declarante (el trabajador) + su número de documento en «Documento de identidad:».
        'declarante' => ['x' => 16.0, 'y' => 299.0, 'ancho' => 45.0, 'alto' => 13.0, 'doc_x' => 42.0, 'doc_y' => 315.6],
        // Sección 3 (padres o hermanos huérfanos): una caja para el padre y otra para la madre.
        'padre' => ['x' => 16.0, 'y' => 161.5, 'ancho' => 45.0, 'alto' => 12.0, 'doc_x' => 16.0, 'doc_y' => 175.6],
        'madre' => ['x' => 118.0, 'y' => 161.5, 'ancho' => 45.0, 'alto' => 12.0, 'doc_x' => 118.0, 'doc_y' => 175.6],
    ];

    /**
     * Filas de la sección 3 (padres o hermanos huérfanos): y de la línea de texto (mm) y x de cada
     * columna. El portal llena la tabla de abajo hacia arriba y a veces deja fuera a la madre
     * aunque esté incluida en la afiliación; en ese caso se escribe su fila.
     */
    private const FILAS_PADRES_Y = [1 => 147.6, 2 => 150.2, 3 => 152.8, 4 => 155.4];

    private const COLUMNAS_PADRES_X = ['nombre' => 12.3, 'tipo' => 67.5, 'numero' => 72.0, 'parentesco' => 103.0];

    /** Texto plano del PDF (para saber qué llenó el portal); vacío si no se puede leer. */
    private function textoDelPdf(string $pdf): string
    {
        try {
            return (new \Smalot\PdfParser\Parser())->parseContent($pdf)->getText();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * ¿Hay firma guardada? Sin documento es la del trabajador; con documento, la de un beneficiario
     * (el padre o la madre que firman la sección 3). Es un documento más del cliente, en disco privado.
     */
    public function firmaGuardada(Contrato $contrato, ?string $docBeneficiario = null): ?string
    {
        $doc = $docBeneficiario ? ltrim(preg_replace('/\D/', '', $docBeneficiario), '0') : null;
        $fila = \App\Models\DocumentoCliente::where('aliado_id', $contrato->aliado_id)->where('cc_cliente', (string) $contrato->cedula)
            ->where('tipo_documento', 'firma')
            ->when($doc, fn ($q) => $q->where('doc_beneficiario', $doc), fn ($q) => $q->whereNull('doc_beneficiario'))
            ->orderByDesc('id')->first();
        $disco = \Illuminate\Support\Facades\Storage::disk('local');

        return $fila && $disco->exists($fila->ruta) ? $disco->get($fila->ruta) : null;
    }

    public function guardarFirma(Contrato $contrato, string $png, ?int $usuarioId, ?string $docBeneficiario = null): void
    {
        $doc = $docBeneficiario ? ltrim(preg_replace('/\D/', '', $docBeneficiario), '0') : null;
        $ruta = "documentos/{$contrato->aliado_id}/{$contrato->cedula}/firma_".($doc ?: 'trabajador').'_'.time().'_'.\Illuminate\Support\Str::random(6).'.png';
        \Illuminate\Support\Facades\Storage::disk('local')->put($ruta, $png);
        \App\Models\DocumentoCliente::create([
            'aliado_id' => $contrato->aliado_id, 'cc_cliente' => (string) $contrato->cedula, 'doc_beneficiario' => $doc,
            'tipo_documento' => 'firma', 'nombre_archivo' => 'firma.png', 'ruta' => $ruta, 'subido_por' => $usuarioId,
        ]);
    }

    /**
     * Estampa las firmas sobre la declaración juramentada que generó el portal, con el número de
     * documento de cada firmante, y (salvo en la vista previa) guarda el resultado como documento
     * del cliente.
     *
     * @param  array<string, string>  $firmas  rol (declarante|padre|madre) → PNG de la firma
     * @param  array<string, string>  $docs    rol → texto del documento, p. ej. «CC 27261598»
     * @return string PDF con las firmas
     */
    public function firmarDeclaracion(Contrato $contrato, string $pdf, array $firmas, array $docs, ?int $usuarioId, bool $previa = false, array $personas = []): string
    {
        // ¿Quién de los padres quedó fuera de la tabla de la sección 3? Se busca su documento en el
        // texto del PDF; los que falten se escriben en las filas libres (de abajo hacia arriba).
        $faltantes = [];
        $texto = preg_replace('/\D/', '', $this->textoDelPdf($pdf));
        if ($texto !== '') {
            foreach (['padre', 'madre'] as $rol) {
                $p = $personas[$rol] ?? null;
                if ($p && ! str_contains($texto, ltrim(preg_replace('/\D/', '', (string) $p['doc']), '0'))) {
                    $faltantes[$rol] = $p;
                }
            }
        }

        $tmp = sys_get_temp_dir().'/decl_'.\Illuminate\Support\Str::random(8);
        file_put_contents($tmp.'.pdf', $pdf);
        $archivos = [];
        foreach ($firmas as $rol => $png) {
            $archivos[$rol] = $tmp."_{$rol}.png";
            file_put_contents($archivos[$rol], $png);
        }

        try {
            $fpdi = new \setasign\Fpdi\Fpdi('P', 'mm');
            $paginas = $fpdi->setSourceFile($tmp.'.pdf');
            for ($n = 1; $n <= $paginas; $n++) {
                $id = $fpdi->importPage($n);
                $tam = $fpdi->getTemplateSize($id);
                $fpdi->AddPage($tam['orientation'], [$tam['width'], $tam['height']]);
                $fpdi->useTemplate($id);
                if ($n !== 1) {
                    continue;                                   // el formato es de una sola hoja
                }
                // Filas de la sección 3 que el portal no llenó: el primer hueco libre contando desde abajo
                // (la fila 4 es la que usa el portal para el padre cuando lo lista).
                $fila = count($personas) - count($faltantes) >= 1 ? 3 : 4;
                foreach (['padre', 'madre'] as $rol) {
                    if (! isset($faltantes[$rol])) {
                        continue;
                    }
                    $p = $faltantes[$rol];
                    $y = self::FILAS_PADRES_Y[$fila--];
                    $apellidosNombres = trim(($p['apellidos'] ?? '').' '.($p['nombres'] ?? '')) ?: (string) ($p['nombre'] ?? '');
                    $fpdi->SetFont('Arial', '', 6);
                    $fpdi->SetTextColor(20, 20, 20);
                    $t = fn ($v) => iconv('UTF-8', 'ISO-8859-1//TRANSLIT', (string) $v);
                    $fpdi->Text(self::COLUMNAS_PADRES_X['nombre'], $y, $t(mb_strtoupper($apellidosNombres)));
                    $fpdi->Text(self::COLUMNAS_PADRES_X['tipo'], $y, $t(strtoupper((string) ($p['tipo'] ?? 'CC'))));
                    $fpdi->Text(self::COLUMNAS_PADRES_X['numero'], $y, $t($p['doc']));
                    $fpdi->Text(self::COLUMNAS_PADRES_X['parentesco'], $y, $t(strtoupper($rol)));
                }
                foreach (self::FIRMA_EN_DECLARACION as $rol => $p) {
                    if (isset($archivos[$rol])) {
                        $fpdi->Image($archivos[$rol], $p['x'], $p['y'], $p['ancho'], $p['alto']);
                    }
                    if (! empty($docs[$rol]) && (isset($archivos[$rol]) || $rol === 'declarante')) {
                        $fpdi->SetFont('Arial', '', 7.5);
                        $fpdi->SetTextColor(20, 20, 20);
                        $fpdi->Text($p['doc_x'], $p['doc_y'], iconv('UTF-8', 'ISO-8859-1//TRANSLIT', (string) $docs[$rol]));
                    }
                }
            }
            $firmado = $fpdi->Output('S');
        } finally {
            @unlink($tmp.'.pdf');
            foreach ($archivos as $f) {
                @unlink($f);
            }
        }

        if (! $previa) {
            $this->guardarDocumentos($contrato, [[
                'requerido' => 'Formato declaracion juramentada caja',
                'nombre' => 'DeclaracionJuramentada_'.$contrato->cedula.'_'.now()->format('Ymd').'.pdf',
                'doc_beneficiario' => null,
                'base64' => base64_encode($firmado),
            ]], $usuarioId);
        }

        return $firmado;
    }

    /** Usuario (y clave, según permiso) del portal de la razón social. */
    public function credencial(Contrato $contrato): array
    {
        $contrato->loadMissing('razonSocial');
        $cred = EpsClavePortal::para(self::ENTIDAD, '%COMFENALCO%', 'Comfenalco Valle', (string) $contrato->razonSocial?->nit, 'CAJA', self::esDelValle(...));

        return isset($cred['error']) ? $cred : ['usuario' => $cred['usuario'], 'contrasena' => $cred['contrasena'], 'host' => self::HOST];
    }

    /**
     * Registra el resultado del portal en el radicado de caja.
     *
     * $entrada: numero (formulario 1089…), texto (mensaje del portal), error.
     */
    public function aplicar(Contrato $contrato, array $entrada, ?int $usuarioId): array
    {
        $prep = $this->preparar($contrato);
        if ($prep['problemas']) {
            throw new RuntimeException(implode(' ', $prep['problemas']));
        }

        $radicado = Radicado::firstOrCreate(
            ['contrato_id' => $contrato->id, 'tipo' => 'caja'],
            ['aliado_id' => $contrato->aliado_id, 'estado' => Radicado::ESTADO_PENDIENTE]
        );
        $numero = trim((string) ($entrada['numero'] ?? '')) ?: null;
        $texto  = trim(preg_replace('/\s+/', ' ', (string) ($entrada['texto'] ?? '')));

        if (! empty($entrada['error']) && ! $numero) {
            $mensaje = 'Comfenalco Valle (caja): el portal no radicó la afiliación — '.mb_substr((string) $entrada['error'], 0, 300);
            $this->marcar($radicado, null, Radicado::ESTADO_ERROR, $mensaje, $usuarioId);
            $this->bitacora($contrato, $radicado, 'fallida', null, $texto, (string) $entrada['error'], $usuarioId);

            return ['ok' => false, 'estado' => Radicado::ESTADO_ERROR, 'mensaje' => $mensaje];
        }
        if (! $numero) {
            throw new RuntimeException('Falta el número de formulario que dio el portal.');
        }

        $mensaje = sprintf('Comfenalco Valle (caja): afiliación radicada en la Sucursal Virtual con el formulario N° %s el %s. Comfenalco verifica y responde en máximo 2 días calendario.',
            $numero, now()->format('d/m/Y H:i'));
        $this->marcar($radicado, $numero, Radicado::ESTADO_TRAMITE, $mensaje, $usuarioId);
        $this->bitacora($contrato, $radicado, 'exitosa', $numero, $texto, null, $usuarioId);

        // El formulario radicado (PDF con el código de barras) queda en el radicado, como si se
        // hubiera subido a mano con «Subir PDF».
        $guardoPdf = ! empty($entrada['pdf']) && $this->guardarPdfRadicado($contrato, $radicado, (string) $entrada['pdf'], $usuarioId);
        if ($guardoPdf) {
            $mensaje .= ' El PDF del formulario quedó guardado en el radicado.';
        }

        return ['ok' => true, 'estado' => Radicado::ESTADO_TRAMITE, 'numero' => $numero, 'mensaje' => $mensaje, 'pdf_guardado' => $guardoPdf];
    }

    /** Adjunta el PDF del formulario al radicado de caja del contrato (cuando no se capturó al radicar). */
    public function adjuntarPdfRadicado(Contrato $contrato, string $base64, ?int $usuarioId): bool
    {
        $radicado = Radicado::where('contrato_id', $contrato->id)->where('tipo', 'caja')->first();

        return $radicado ? $this->guardarPdfRadicado($contrato, $radicado, $base64, $usuarioId) : false;
    }

    /** Guarda el PDF del formulario radicado en el mismo sitio que «Subir PDF» (disco privado). */
    private function guardarPdfRadicado(Contrato $contrato, Radicado $radicado, string $base64, ?int $usuarioId): bool
    {
        if (str_starts_with(ltrim($base64), '{')) {
            $base64 = (string) (json_decode($base64, true)['encodedString'] ?? '');
        }
        $bin = base64_decode($base64, true);
        if ($bin === false || ! str_starts_with($bin, '%PDF')) {
            return false;
        }

        $disco = \Illuminate\Support\Facades\Storage::disk('local');
        if ($radicado->ruta_pdf && $disco->exists($radicado->ruta_pdf)) {
            $disco->delete($radicado->ruta_pdf);
        }
        $ruta = "radicados/{$contrato->aliado_id}/{$contrato->id}/{$contrato->cedula}/caja_".now()->format('Ymd_His').'.pdf';
        $disco->put($ruta, $bin);
        $radicado->update(['ruta_pdf' => $ruta]);
        RadicadoMovimiento::create([
            'radicado_id' => $radicado->id, 'contrato_id' => $contrato->id, 'tipo_proceso' => 'afiliacion',
            'entidad' => 'caja', 'user_id' => $usuarioId, 'estado_anterior' => $radicado->estado,
            'estado_nuevo' => $radicado->estado, 'observacion' => 'PDF del formulario radicado guardado desde el portal de Comfenalco Valle.',
        ]);

        return true;
    }

    private function marcar(Radicado $radicado, ?string $numero, string $estado, string $observacion, ?int $usuarioId): void
    {
        DB::transaction(function () use ($radicado, $numero, $estado, $observacion, $usuarioId) {
            $r = Radicado::whereKey($radicado->id)->lockForUpdate()->first();
            $anterior = $r->estado;
            if ($anterior === Radicado::ESTADO_OK) {
                return;                                  // nunca se retrocede un OK
            }
            $r->update([
                'estado' => $estado, 'numero_radicado' => $numero ?? $r->numero_radicado, 'canal_envio' => 'portal',
                'user_id' => $usuarioId, 'fecha_inicio_tramite' => $r->fecha_inicio_tramite ?? now(),
                'observacion' => trim(($r->observacion ? $r->observacion.' | ' : '').$observacion),
            ]);
            RadicadoMovimiento::create([
                'radicado_id' => $r->id, 'contrato_id' => $r->contrato_id, 'tipo_proceso' => 'afiliacion',
                'entidad' => 'caja', 'user_id' => $usuarioId, 'estado_anterior' => $anterior,
                'estado_nuevo' => $estado, 'observacion' => $observacion,
            ]);
        });
        $radicado->refresh();
    }

    private function bitacora(Contrato $contrato, Radicado $radicado, string $estado, ?string $numero, string $texto, ?string $error, ?int $usuarioId): void
    {
        EpsAfiliacion::create([
            'aliado_id' => $contrato->aliado_id, 'contrato_id' => $contrato->id, 'radicado_id' => $radicado->id,
            'entidad' => self::ENTIDAD, 'operacion' => 'afiliacion_caja', 'estado' => $estado, 'numero_radicado' => $numero,
            'payload' => json_encode(['portal' => self::HOST], JSON_UNESCAPED_UNICODE),
            'respuesta' => json_encode(['texto' => mb_substr($texto, 0, 2000)], JSON_UNESCAPED_UNICODE),
            'mensaje_error' => $error ? mb_substr($error, 0, 500) : null, 'usuario_id' => $usuarioId,
        ]);
    }
}
