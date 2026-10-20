<?php

namespace App\Services;

use App\Models\CartaLaboral;
use App\Models\ConfiguracionBrynex;
use App\Models\Contrato;
use App\Models\RazonSocial;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * Cartas laborales a nombre de una razón social: para la caja de
 * compensación (subsidio familiar monetario) o una certificación laboral
 * general.
 *
 * El texto se arma una sola vez en contenido() y de ahí salen el PDF y el
 * Word, para que los dos digan exactamente lo mismo. Lo que va en negrilla se
 * marca con **asteriscos** y cada salida lo traduce a su formato.
 *
 * Imágenes, todas en storage/app (nunca en public):
 * - logo de la razón social   → logos_razones/{nit}.png (se sube en la ficha)
 * - sello de la razón social  → sellos/{nit}.png (el mismo de los formularios)
 * - firma de quien firma      → firmas/{cedula}.png. Es la misma carpeta de la
 *   firma del cliente en los formularios de EPS: la firma es de la persona, no
 *   del papel que firma.
 */
class CartaLaboralService
{
    /** Horas al mes de la jornada completa: 42 h semanales (Ley 2101 de 2021, desde el 15-jul-2026) = 7 h × 30 días. */
    public const HORAS_MES_COMPLETO = 210;

    /** Ley 789 de 2002, art. 3: la cuota monetaria exige al menos 96 horas de trabajo al mes. */
    public const HORAS_MINIMAS_SUBSIDIO = 96;

    /** Cargos con que suele firmar un usuario; en pantalla también se puede escribir otro. */
    public const CARGOS_FIRMANTE = [
        'Recursos Humanos', 'Talento Humano', 'Gestión Humana',
        'Gerencia', 'Gerencia General', 'Gerencia Administrativa',
        'Dirección Administrativa', 'Administración', 'Coordinación de Nómina',
        'Contabilidad', 'Asistente Administrativa',
    ];

    public const TIPOS_CONTRATO = [
        'indefinido' => 'un contrato a término indefinido',
        'fijo' => 'un contrato a término fijo',
        'obra' => 'un contrato por obra o labor',
        'aprendizaje' => 'un contrato de aprendizaje',
    ];

    private const DOCUMENTOS = [
        'CC' => 'cédula de ciudadanía',
        'CE' => 'cédula de extranjería',
        'TI' => 'tarjeta de identidad',
        'PT' => 'permiso por protección temporal',
        'PE' => 'permiso especial de permanencia',
        'PA' => 'pasaporte',
        'PP' => 'pasaporte',
    ];

    // ── Rutas de las imágenes ────────────────────────────────────────

    public static function rutaLogo(string $nit): string
    {
        return storage_path('app/logos_razones/'.self::archivoSeguro($nit).'.png');
    }

    public static function rutaSello(string $nit): string
    {
        return storage_path('app/sellos/'.self::archivoSeguro($nit).'.png');
    }

    public static function rutaFirma(string $cedula): string
    {
        return storage_path('app/firmas/'.self::archivoSeguro($cedula).'.png');
    }

    /** Un NIT o una cédula convertidos en nombre de archivo: nada de barras ni puntos. */
    private static function archivoSeguro(string $valor): string
    {
        $limpio = preg_replace('/[^0-9A-Za-z]/', '', $valor);
        abort_if($limpio === '', 422, 'Documento inválido.');

        return $limpio;
    }

    /**
     * Guarda una imagen subida (o un PNG en base64 dibujado en pantalla) como
     * PNG, conservando la transparencia.
     */
    public static function guardarPng(UploadedFile|string $origen, string $destino): void
    {
        if ($origen instanceof UploadedFile) {
            $mime = $origen->getMimeType();
            $img = match (true) {
                str_contains($mime, 'jpeg') => @imagecreatefromjpeg($origen->getRealPath()),
                str_contains($mime, 'webp') => @imagecreatefromwebp($origen->getRealPath()),
                default => @imagecreatefrompng($origen->getRealPath()),
            };
        } else {
            $datos = str_contains($origen, ',') ? explode(',', $origen, 2)[1] : $origen;
            $img = @imagecreatefromstring((string) base64_decode($datos, true));
        }

        if (! $img) {
            throw ValidationException::withMessages(['imagen' => 'No se pudo leer la imagen.']);
        }

        if (! is_dir(dirname($destino))) {
            mkdir(dirname($destino), 0755, true);
        }

        imagesavealpha($img, true);
        imagepng($img, $destino, 6);
        imagedestroy($img);
    }

    // ── Datos de partida ─────────────────────────────────────────────

    /**
     * Valores iniciales del formulario a partir de un contrato. Todo es
     * editable en pantalla: el contrato no guarda la jornada real.
     */
    public function prefill(Contrato $contrato, int $aliadoId): array
    {
        $contrato->loadMissing(['cliente', 'tipoModalidad', 'caja']);
        $rs = $this->razonSocial((int) $contrato->razon_social_id, $aliadoId);
        $cliente = $contrato->cliente;

        // Días que trabaja: los del tiempo parcial del contrato o los de la
        // modalidad (la caja de un tiempo parcial cotiza por los días laborados).
        $dias = (int) ($contrato->dias_tp_caja ?: ($contrato->tipoModalidad?->dias_caja ?: 30));
        $dias = max(1, min(30, $dias));
        $horas = $dias >= 30 ? self::HORAS_MES_COMPLETO : $dias * 7;

        $ultima = CartaLaboral::where('aliado_id', $aliadoId)->latest('id')->first();
        $salario = (int) round((float) $contrato->salario);

        return [
            'contrato_id' => $contrato->id,
            'razon_social_id' => $rs?->id,
            'empresa' => $rs ? $this->empresa($rs) : null,
            'trabajador_nombre' => $cliente ? mb_convert_case($cliente->nombre_completo, MB_CASE_TITLE) : '',
            'trabajador_tipo_doc' => $cliente?->tipo_doc ?: 'CC',
            'trabajador_documento' => (string) $contrato->cedula,
            'trabajador_genero' => $cliente?->genero === 'M' ? 'M' : 'F',
            'cargo' => $contrato->cargo ? mb_convert_case(mb_strtolower($contrato->cargo), MB_CASE_TITLE) : '',
            'fecha_ingreso' => $contrato->fecha_ingreso ? Carbon::parse($contrato->fecha_ingreso)->toDateString() : null,
            'fecha_retiro' => $contrato->estado === 'retirado' && $contrato->fecha_retiro
                ? Carbon::parse($contrato->fecha_retiro)->toDateString() : null,
            'salario' => $salario,
            'auxilio_transporte' => $this->auxilioSugerido($salario, $dias),
            'tipo_contrato' => 'indefinido',
            'dias_mes' => $dias,
            'horas_mes' => $horas,
            'jornada' => $this->jornadaSugerida($dias, $horas),
            'anio' => (int) now()->year,
            'caja_nombre' => $contrato->caja?->nombre && $contrato->caja->nombre !== 'NINGUNA' ? $contrato->caja->nombre : '',
            'ciudad' => $ultima?->datos['ciudad'] ?? 'Santiago de Cali',
            'fecha' => now()->toDateString(),
            'representante' => $rs ? [
                'nombre' => (string) $rs->nombre_rep,
                'tipo_doc' => $rs->rep_tipo_doc ?: 'CC',
                'documento' => (string) $rs->cedula_rep,
                'tiene_firma' => $rs->cedula_rep && file_exists(self::rutaFirma($rs->cedula_rep)),
                'celular' => $rs->cedula_rep ? $this->celularRepresentante($rs, $aliadoId) : '',
            ] : null,
        ];
    }

    /** Auxilio de transporte de ley: solo hasta 2 mínimos, proporcional a los días trabajados. */
    public function auxilioSugerido(int $salario, int $dias): int
    {
        if ($salario <= 0 || $salario > 2 * ConfiguracionBrynex::salarioMinimo()) {
            return 0;
        }

        return (int) round(self::auxilioTransporte() * max(1, min(30, $dias)) / 30);
    }

    public static function auxilioTransporte(): int
    {
        return (int) ConfiguracionBrynex::obtener('auxilio_transporte', 249095);
    }

    /**
     * Celular del representante: el de su última carta en esa razón social o,
     * si nunca ha firmado, el suyo como cliente del aliado.
     */
    private function celularRepresentante(RazonSocial $rs, int $aliadoId): string
    {
        $ultima = CartaLaboral::where('aliado_id', $aliadoId)->where('razon_social_id', $rs->id)
            ->where('firmante_tipo', 'representante')->latest('id')->first();
        if ($ultima && ! empty($ultima->datos['firmante']['celular'])) {
            return $ultima->datos['firmante']['celular'];
        }

        return (string) (\App\Models\Cliente::where('aliado_id', $aliadoId)->where('cedula', $rs->cedula_rep)->value('celular') ?? '');
    }

    /** Texto de la jornada a partir de días y horas al mes. */
    public function jornadaSugerida(int $dias, int $horas): string
    {
        if ($dias >= 30 && $horas >= self::HORAS_MES_COMPLETO) {
            return 'tiempo completo, cuarenta y dos (42) horas semanales';
        }

        return 'tiempo parcial, '.$this->numero($dias).' días al mes, para un total de '
            .$this->numero($horas).' horas al mes';
    }

    /** Datos de la empresa para el membrete. */
    public function empresa(RazonSocial $rs): array
    {
        $nit = (string) $rs->nit;

        return [
            'nombre' => mb_strtoupper(trim((string) $rs->razon_social)),
            'nit' => $nit,
            'nit_formateado' => number_format((float) $nit, 0, ',', '.').($rs->dv !== null && $rs->dv !== '' ? '-'.$rs->dv : ''),
            'direccion' => trim((string) ($rs->dir_formulario ?: $rs->direccion)),
            'telefono' => trim((string) ($rs->tel_formulario ?: $rs->telefonos)),
            'correo' => mb_strtolower(trim((string) ($rs->correo_formulario ?: $rs->correos))),
            'tiene_logo' => $nit !== '' && file_exists(self::rutaLogo($nit)),
            'tiene_sello' => $nit !== '' && file_exists(self::rutaSello($nit)),
        ];
    }

    public function razonSocial(int $id, int $aliadoId): ?RazonSocial
    {
        return RazonSocial::where('id', $id)->where('aliado_id', $aliadoId)->first();
    }

    /** Quienes pueden firmar como usuario: los activos del aliado con cédula (las cuentas de servicio no tienen). */
    public function usuariosFirmantes(int $aliadoId)
    {
        return User::where('activo', true)
            ->whereNotNull('cedula')->where('cedula', '<>', '')
            ->where(function ($q) use ($aliadoId) {
                $q->where('aliado_id', $aliadoId)
                    ->orWhereHas('aliados', fn ($a) => $a->where('aliados.id', $aliadoId)->where('aliado_user.activo', true));
            })
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'cedula', 'telefono']);
    }

    // ── De lo que llega del formulario a los datos de la carta ──────

    /**
     * Valida y completa lo que llega del formulario. La empresa y el firmante
     * se leen de la BD (del aliado activo): de la pantalla solo se acepta lo
     * que es texto de la carta.
     */
    public function resolver(array $in, int $aliadoId): array
    {
        $contrato = Contrato::where('aliado_id', $aliadoId)->findOrFail((int) $in['contrato_id']);
        $rs = $this->razonSocial((int) $contrato->razon_social_id, $aliadoId);

        if (! $rs) {
            throw ValidationException::withMessages(['contrato_id' => 'El contrato no tiene una razón social de este aliado.']);
        }

        $empresa = $this->empresa($rs);
        foreach (['direccion', 'telefono', 'correo'] as $campo) {
            $empresa[$campo] = trim((string) ($in['empresa_'.$campo] ?? $empresa[$campo]));
        }

        if (($in['firmante_tipo'] ?? '') === 'usuario') {
            $user = $this->usuariosFirmantes($aliadoId)->firstWhere('id', (int) ($in['firmante_user_id'] ?? 0));
            if (! $user) {
                throw ValidationException::withMessages(['firmante_user_id' => 'Escoge el usuario que firma.']);
            }
            $firmante = [
                'tipo' => 'usuario',
                'user_id' => $user->id,
                'nombre' => mb_strtoupper(trim($user->nombre)),
                'tipo_doc' => 'CC',
                'documento' => (string) $user->cedula,
                'cargo' => trim((string) ($in['firmante_cargo'] ?? '')),
                'celular' => trim((string) ($in['firmante_celular'] ?? '')),
            ];
        } else {
            if (! $rs->nombre_rep || ! $rs->cedula_rep) {
                throw ValidationException::withMessages(['firmante_tipo' => 'La razón social no tiene representante legal registrado.']);
            }
            $firmante = [
                'tipo' => 'representante',
                'user_id' => null,
                'nombre' => mb_strtoupper(trim($rs->nombre_rep)),
                'tipo_doc' => $rs->rep_tipo_doc ?: 'CC',
                'documento' => (string) $rs->cedula_rep,
                'cargo' => 'Representante legal',
                'celular' => trim((string) ($in['firmante_celular'] ?? '')),
            ];
        }

        $destino = ($in['destino'] ?? 'caja') === 'certificacion' ? 'certificacion' : 'caja';

        return [
            'destino' => $destino,
            'contrato_id' => $contrato->id,
            'razon_social_id' => $rs->id,
            'empresa' => $empresa,
            'trabajador' => [
                'nombre' => trim((string) $in['trabajador_nombre']),
                'tipo_doc' => strtoupper((string) ($in['trabajador_tipo_doc'] ?? 'CC')),
                'documento' => (string) $contrato->cedula,
                'genero' => ($in['trabajador_genero'] ?? 'F') === 'M' ? 'M' : 'F',
            ],
            'cargo' => trim((string) $in['cargo']),
            'fecha_ingreso' => $in['fecha_ingreso'],
            'fecha_retiro' => $in['fecha_retiro'] ?? null,
            'salario' => (int) $in['salario'],
            'auxilio_transporte' => $destino === 'certificacion' && ! empty($in['incluir_salario']) ? (int) ($in['auxilio_transporte'] ?? 0) : 0,
            'tipo_contrato' => array_key_exists($in['tipo_contrato'] ?? '', self::TIPOS_CONTRATO) ? $in['tipo_contrato'] : '',
            // La carta a la caja siempre lleva jornada y salario: es lo que piden.
            'incluir_salario' => $destino === 'caja' || ! empty($in['incluir_salario']),
            'incluir_jornada' => $destino === 'caja' || ! empty($in['incluir_jornada']),
            'dias_mes' => (int) ($in['dias_mes'] ?? 30),
            'horas_mes' => (int) ($in['horas_mes'] ?? self::HORAS_MES_COMPLETO),
            'jornada' => trim((string) ($in['jornada'] ?? '')),
            'anio' => (int) ($in['anio'] ?? now()->year),
            'caja_nombre' => trim((string) ($in['caja_nombre'] ?? '')),
            'ciudad' => trim((string) ($in['ciudad'] ?? 'Santiago de Cali')),
            'fecha' => $in['fecha'] ?? now()->toDateString(),
            'firmante' => $firmante,
        ];
    }

    /**
     * Lo que conviene revisar antes de firmar. No bloquea: la carta la firma
     * una persona y la responsabilidad es de ella, pero que lo sepa.
     */
    public function avisos(array $d): array
    {
        $avisos = [];
        $smmlv = ConfiguracionBrynex::salarioMinimo();
        $horas = (int) $d['horas_mes'];

        if ($d['incluir_jornada'] && $horas > 0) {
            if ($d['destino'] === 'caja' && $horas < self::HORAS_MINIMAS_SUBSIDIO) {
                $avisos[] = "Con {$horas} horas al mes no alcanza las ".self::HORAS_MINIMAS_SUBSIDIO
                    .' que exige la Ley 789 de 2002 para la cuota monetaria.';
            }

            $minimo = (int) round($smmlv * min($horas, self::HORAS_MES_COMPLETO) / self::HORAS_MES_COMPLETO);
            if ($d['incluir_salario'] && $d['salario'] < $minimo) {
                $avisos[] = 'El salario ($'.number_format($d['salario'], 0, ',', '.').') queda por debajo del mínimo para '
                    .$horas.' horas al mes ($'.number_format($minimo, 0, ',', '.').', con jornada de 42 horas semanales).';
            }

            if ($horas > self::HORAS_MES_COMPLETO) {
                $avisos[] = "{$horas} horas al mes superan la jornada máxima legal (".self::HORAS_MES_COMPLETO.').';
            }

            $diasEquivalentes = (int) ceil($horas / 7);
            if ($d['dias_mes'] < 30 && $diasEquivalentes > $d['dias_mes']) {
                $avisos[] = "{$horas} horas equivalen a {$diasEquivalentes} días de jornada (7 h): revisa que la planilla no reporte menos días ({$d['dias_mes']}).";
            }
        }

        if ($d['destino'] === 'caja') {
            if ($d['salario'] > 4 * $smmlv) {
                $avisos[] = 'El salario supera 4 salarios mínimos: no da derecho a cuota monetaria.';
            }
            if ($d['firmante']['tipo'] !== 'representante') {
                $avisos[] = 'Las cajas suelen pedir la firma del representante legal.';
            }
            if ($d['fecha_retiro']) {
                $avisos[] = 'El contrato está retirado: la caja pide la carta de un trabajador vinculado.';
            }
            if ($d['caja_nombre'] === '') {
                $avisos[] = 'Falta el nombre de la caja de compensación.';
            }
        }

        if (($d['auxilio_transporte'] ?? 0) > 0 && $d['salario'] > 2 * $smmlv) {
            $avisos[] = 'Con más de 2 salarios mínimos no hay auxilio de transporte de ley.';
        }
        if (($d['firmante']['celular'] ?? '') === '') {
            $avisos[] = 'Quien firma no tiene celular: la carta sale sin él.';
        }

        if (! $d['empresa']['tiene_sello']) {
            $avisos[] = 'La razón social no tiene sello: la carta sale sin él.';
        }
        if (! file_exists(self::rutaFirma($d['firmante']['documento']))) {
            $avisos[] = 'Quien firma no tiene firma guardada: queda la línea para firmar a mano.';
        }

        return $avisos;
    }

    // ── El texto ────────────────────────────────────────────────────

    /**
     * La carta en bloques, lista para pintarse. Negrilla = **texto**.
     */
    public function contenido(array $d): array
    {
        if ($d['destino'] === 'certificacion') {
            return $this->contenidoCertificacion($d);
        }

        $t = $d['trabajador'];
        $f = $t['genero'] === 'F';
        $empresa = $d['empresa'];
        $nombre = mb_strtoupper($t['nombre']);
        $docLargo = self::DOCUMENTOS[$t['tipo_doc']] ?? 'documento de identidad';
        $doc = $this->documento($t['documento']);

        $vinculo = $d['fecha_retiro']
            ? 'laboró en esta empresa desde el '.$this->fecha($d['fecha_ingreso']).' hasta el '.$this->fecha($d['fecha_retiro'])
            : 'labora en esta empresa desde el '.$this->fecha($d['fecha_ingreso']);

        $cargo = $d['cargo'] !== '' ? ' en el cargo de '.$d['cargo'] : '';
        $sigue = $d['fecha_retiro'] ? '' : ', y que a la fecha continúa '.($f ? 'vinculada' : 'vinculado');

        $parrafos = [
            "**{$empresa['nombre']}**, identificada con NIT {$empresa['nit_formateado']}, certifica que "
            .($f ? 'la señora' : 'el señor')." **{$nombre}**, ".($f ? 'identificada' : 'identificado')
            ." con {$docLargo} {$doc}, {$vinculo}{$cargo}{$sigue}.",
        ];

        $condiciones = [];
        if ($d['incluir_jornada'] && $d['jornada'] !== '') {
            $condiciones[] = ['Jornada', rtrim($d['jornada'], '. ').'.'];
        }
        if ($d['incluir_salario'] && $d['salario'] > 0) {
            $condiciones[] = ['Salario', $this->salario($d['salario'], $d['anio']).'.'];
        }

        $interesado = $f ? 'la interesada' : 'el interesado';
        $cierre = $d['destino'] === 'caja'
            ? "La presente certificación se expide a solicitud de {$interesado}, con destino al trámite de subsidio familiar monetario ante {$d['caja_nombre']}."
            : "La presente certificación se expide a solicitud de {$interesado}.";

        $destinatario = $d['destino'] === 'caja'
            ? ['Señores', '**'.mb_strtoupper($d['caja_nombre']).'**', 'Subsidio Familiar Monetario', $d['ciudad']]
            : [];

        return [
            'estilo' => 'caja',
            'preambulo' => null,
            'ciudad_fecha' => $d['ciudad'].', '.$this->fecha($d['fecha']),
            'destinatario' => $destinatario,
            'titulo' => $d['destino'] === 'caja' ? null : 'A QUIEN INTERESE',
            'asunto' => $d['destino'] === 'caja'
                ? '**Asunto:** Certificación laboral de '.mb_convert_case($t['nombre'], MB_CASE_TITLE).", {$t['tipo_doc']} {$doc}"
                : null,
            'saludo' => $d['destino'] === 'caja' ? 'Cordial saludo.' : null,
            'parrafos' => $parrafos,
            'condiciones_intro' => $condiciones
                ? ($d['fecha_retiro'] ? 'Sus condiciones laborales fueron:' : "Sus condiciones laborales para el año {$d['anio']} son:")
                : null,
            'condiciones' => $condiciones,
            'cierre' => $cierre,
            'despedida' => 'Atentamente,',
            'firmante' => $this->firmante($d),
        ];
    }

    /**
     * La certificación «A quien interese», al estilo de las que expiden las
     * empresas: CERTIFICA: y una sucesión de «Que…», con el salario en letras
     * y la fecha de expedición al cierre.
     */
    private function contenidoCertificacion(array $d): array
    {
        $t = $d['trabajador'];
        $f = $t['genero'] === 'F';
        $empresa = $d['empresa'];
        $retirado = (bool) $d['fecha_retiro'];
        $docLargo = self::DOCUMENTOS[$t['tipo_doc']] ?? 'documento de identidad';

        $vinculo = $retirado
            ? 'estuvo '.($f ? 'vinculada' : 'vinculado').' con nuestra compañía desde el '.$this->fecha($d['fecha_ingreso']).' hasta el '.$this->fecha($d['fecha_retiro'])
            : 'se encuentra '.($f ? 'vinculada' : 'vinculado').' con nuestra compañía desde el '.$this->fecha($d['fecha_ingreso']);
        $cargo = $d['cargo'] !== '' ? ', desempeñando el cargo de **'.mb_strtoupper($d['cargo']).'**' : '';
        $contrato = isset(self::TIPOS_CONTRATO[$d['tipo_contrato'] ?? '']) ? ', por medio de '.self::TIPOS_CONTRATO[$d['tipo_contrato']] : '';

        $parrafos = [
            'Que '.($f ? 'la señora' : 'el señor').' **'.mb_strtoupper($t['nombre']).'**, '.($f ? 'identificada' : 'identificado')
            ." con {$docLargo} No. **".$this->documento($t['documento'])."**, {$vinculo}{$cargo}{$contrato}.",
        ];

        if ($d['incluir_jornada'] && $d['jornada'] !== '') {
            $parrafos[] = 'Que '.($retirado ? 'cumplía' : 'cumple').' una jornada laboral de '.rtrim($d['jornada'], '. ').'.';
        }

        if ($d['incluir_salario'] && $d['salario'] > 0) {
            $quien = $f ? 'la trabajadora' : 'el trabajador';
            $texto = 'Que '.$quien.' '.($retirado ? 'devengaba' : 'devenga').' un salario básico mensual';
            $equivale = $this->equivalenciaMinimo($d['salario'], (int) $d['anio']);
            $texto .= $equivale ? " {$equivale}, correspondiente a la suma de " : ' de ';
            $texto .= $this->pesosMcte($d['salario']);
            if (($d['auxilio_transporte'] ?? 0) > 0) {
                $texto .= ', más un auxilio de transporte mensual de '.$this->pesosMcte($d['auxilio_transporte'])
                    .', de acuerdo con la normatividad vigente';
            }
            $parrafos[] = $texto.'.';
        }

        $fecha = Carbon::parse($d['fecha'])->locale('es');
        $dia = (int) $fecha->day;
        $expedicion = ($dia === 1 ? 'al primer (1) día' : 'a los '.$this->numero($dia).' días')
            .' del mes de '.$fecha->translatedFormat('F').' de '.$fecha->year;

        return [
            'estilo' => 'certificacion',
            'ciudad_fecha' => null,
            'destinatario' => [],
            'preambulo' => 'Quien suscribe, en nombre de **'.$empresa['nombre'].'**,',
            'titulo' => 'CERTIFICA:',
            'asunto' => null,
            'saludo' => null,
            'parrafos' => $parrafos,
            'condiciones_intro' => null,
            'condiciones' => [],
            'cierre' => 'La presente certificación se expide a solicitud '.($f ? 'de la interesada' : 'del interesado')
                .", en {$d['ciudad']}, {$expedicion}.",
            'despedida' => 'Cordialmente,',
            'firmante' => $this->firmante($d),
        ];
    }

    /** Quien firma, como sale en la carta: sin su documento, con su celular. */
    private function firmante(array $d): array
    {
        return [
            'nombre' => $d['firmante']['nombre'],
            'cargo' => $d['firmante']['cargo'],
            'celular' => $this->celular($d['firmante']['celular'] ?? ''),
            'empresa' => $d['empresa']['nombre'],
        ];
    }

    /** "3001563615" → "300 156 3615"; lo que no sea un celular de 10 dígitos queda igual. */
    private function celular(string $numero): string
    {
        $digitos = preg_replace('/\D/', '', $numero);

        return strlen($digitos) === 10
            ? substr($digitos, 0, 3).' '.substr($digitos, 3, 3).' '.substr($digitos, 6)
            : trim($numero);
    }

    /** "UN MILLÓN … PESOS M/CTE ($1.750.905)" */
    private function pesosMcte(int $valor): string
    {
        $letras = NumeroALetras::convertir($valor);
        $de = str_ends_with($letras, 'MILLÓN') || str_ends_with($letras, 'MILLONES') ? ' DE' : '';

        return $letras.$de.' PESOS M/CTE ($'.number_format($valor, 0, ',', '.').')';
    }

    /** "equivalente al salario mínimo…" — solo cierto con el mínimo del año en curso. */
    private function equivalenciaMinimo(int $valor, int $anio): ?string
    {
        if ($anio !== (int) now()->year) {
            return null;
        }
        $smmlv = (int) round(ConfiguracionBrynex::salarioMinimo());

        return match ($valor) {
            $smmlv => 'equivalente al salario mínimo legal mensual vigente',
            (int) round($smmlv / 2) => 'equivalente a la mitad del salario mínimo legal mensual vigente',
            default => null,
        };
    }

    private function salario(int $valor, int $anio): string
    {
        $letras = NumeroALetras::convertir($valor);
        $de = str_ends_with($letras, 'MILLÓN') || str_ends_with($letras, 'MILLONES') ? ' de' : '';
        $texto = mb_strtolower($letras).$de.' pesos ($'.number_format($valor, 0, ',', '.').') mensuales';

        // La equivalencia solo es cierta con el mínimo del año en curso.
        if ($anio === (int) now()->year) {
            $smmlv = (int) round(ConfiguracionBrynex::salarioMinimo());
            if ($valor === $smmlv) {
                $texto .= ", equivalentes a un salario mínimo legal mensual vigente para {$anio}";
            } elseif ($valor === (int) round($smmlv / 2)) {
                $texto .= ", equivalentes a la mitad del salario mínimo legal mensual vigente para {$anio}";
            }
        }

        return $texto;
    }

    /** "catorce (14)" */
    private function numero(int $n): string
    {
        return mb_strtolower(NumeroALetras::convertir($n))." ({$n})";
    }

    private function fecha(?string $fecha): string
    {
        return $fecha ? Carbon::parse($fecha)->locale('es')->translatedFormat('j \d\e F \d\e Y') : '';
    }

    private function documento(string $doc): string
    {
        return ctype_digit($doc) ? number_format((float) $doc, 0, ',', '.') : $doc;
    }

    // ── Salidas ─────────────────────────────────────────────────────

    /**
     * Las imágenes de la carta, como ruta en disco o null si no existen. La
     * certificación lleva además el logo de fondo, como marca de agua.
     */
    public function imagenes(array $d): array
    {
        $existe = fn (string $ruta) => file_exists($ruta) ? $ruta : null;
        $logo = $existe(self::rutaLogo($d['empresa']['nit']));

        return [
            'logo' => $logo,
            'sello' => $existe(self::rutaSello($d['empresa']['nit'])),
            'firma' => $d['firmante']['documento'] ? $existe(self::rutaFirma($d['firmante']['documento'])) : null,
            'marca' => $logo && $d['destino'] === 'certificacion' ? $this->marcaAgua($logo) : null,
        ];
    }

    /**
     * El logo casi transparente, para ponerlo de fondo. Se hace la imagen en
     * vez de usar opacidad porque el Word no la tiene. Queda en el temporal,
     * con el mtime del logo en el nombre: si cambian el logo, se rehace.
     */
    private function marcaAgua(string $logo): ?string
    {
        $destino = sys_get_temp_dir().'/carta_marca_'.md5($logo.filemtime($logo)).'.png';
        if (file_exists($destino)) {
            return $destino;
        }

        $img = @imagecreatefrompng($logo);
        if (! $img) {
            return null;
        }
        imagepalettetotruecolor($img);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        // Suma transparencia a cada pixel: lo opaco queda al ~17 %, lo transparente sigue igual.
        imagefilter($img, IMG_FILTER_COLORIZE, 0, 0, 0, 105);
        imagepng($img, $destino, 6);
        imagedestroy($img);

        return $destino;
    }

    public function pdf(array $d): \Barryvdh\DomPDF\PDF
    {
        // DomPDF no lee fuera de su chroot: las imágenes van incrustadas.
        $img = array_map(
            fn ($ruta) => $ruta ? 'data:image/png;base64,'.base64_encode(file_get_contents($ruta)) : null,
            $this->imagenes($d)
        );

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView($d['destino'] === 'certificacion' ? 'pdf.carta_laboral_certificacion' : 'pdf.carta_laboral', [
            'c' => $this->contenido($d),
            'empresa' => $d['empresa'],
            'img' => $img,
            'negrilla' => fn (string $texto) => self::negrillaHtml($texto),
        ])->setPaper('letter');

        app(TrazaArchivoService::class)->marcarPdf($pdf);

        return $pdf;
    }

    /** **texto** → <strong>texto</strong>, escapando todo lo demás. */
    public static function negrillaHtml(string $texto): string
    {
        $partes = preg_split('/\*\*(.+?)\*\*/u', $texto, -1, PREG_SPLIT_DELIM_CAPTURE);
        $html = '';
        foreach ($partes as $i => $parte) {
            $html .= $i % 2 ? '<strong>'.e($parte).'</strong>' : e($parte);
        }

        return $html;
    }

    /** Ruta de un .docx temporal con la carta. */
    public function word(array $d): string
    {
        $c = $this->contenido($d);
        $img = $this->imagenes($d);
        $empresa = $d['empresa'];
        $certificacion = $c['estilo'] === 'certificacion';
        $azul = '1F3B64';

        $word = new PhpWord;
        $word->setDefaultFontName('Arial');
        $word->setDefaultFontSize(11);

        $seccion = $word->addSection([
            'marginTop' => $certificacion ? 2100 : 1700, 'marginBottom' => 1400,
            'marginLeft' => 1500, 'marginRight' => 1500,
            'headerHeight' => 500, 'footerHeight' => 500,
        ]);

        $encabezado = $seccion->addHeader();
        if ($certificacion) {
            // Membrete centrado: logo, nombre y NIT; el logo también de fondo.
            if ($img['logo']) {
                $encabezado->addImage($img['logo'], ['height' => 40, 'alignment' => Jc::CENTER]);
            }
            $encabezado->addText($empresa['nombre'], ['bold' => true, 'size' => 16, 'color' => $azul], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]);
            $encabezado->addText('NIT. '.$empresa['nit_formateado'], ['bold' => true, 'size' => 10.5], [
                'alignment' => Jc::CENTER, 'spaceAfter' => 0,
                'borderBottomSize' => 12, 'borderBottomColor' => $azul,
            ]);
            if ($img['marca']) {
                [$ancho, $alto] = getimagesize($img['marca']);
                $anchoMarca = 340;
                $encabezado->addWatermark($img['marca'], [
                    'width' => $anchoMarca, 'height' => (int) round($anchoMarca * $alto / max(1, $ancho)),
                    'posHorizontal' => 'center', 'posHorizontalRel' => 'page',
                    'posVertical' => 'center', 'posVerticalRel' => 'page',
                ]);
            }
        } else {
            // Membrete: logo a la izquierda, nombre y NIT a la derecha.
            $tabla = $encabezado->addTable(['width' => 100 * 50, 'unit' => 'pct']);
            $fila = $tabla->addRow();
            $celdaLogo = $fila->addCell(3500, ['valign' => 'center']);
            if ($img['logo']) {
                $celdaLogo->addImage($img['logo'], ['height' => 45]);
            }
            $celdaNombre = $fila->addCell(5500, ['valign' => 'center']);
            $celdaNombre->addText($empresa['nombre'], ['bold' => true, 'size' => 12], ['alignment' => Jc::END, 'spaceAfter' => 0]);
            $celdaNombre->addText('NIT: '.$empresa['nit_formateado'], ['size' => 10], ['alignment' => Jc::END, 'spaceAfter' => 0]);
        }

        $pie = $seccion->addFooter();
        foreach ($this->lineasPie($empresa) as $i => $linea) {
            $pie->addText($linea, ['size' => 8.5, 'color' => '555555'], ['alignment' => Jc::CENTER, 'spaceAfter' => 0]
                + ($certificacion && $i === 0 ? ['borderTopSize' => 6, 'borderTopColor' => '94A3B8'] : []));
        }

        $parrafo = ['spaceAfter' => 200, 'lineHeight' => 1.15, 'alignment' => Jc::BOTH];
        $sinEspacio = ['spaceAfter' => 0];

        if ($c['ciudad_fecha']) {
            $seccion->addText($c['ciudad_fecha'], [], ['spaceAfter' => 360]);
        }

        if ($c['destinatario']) {
            foreach ($c['destinatario'] as $i => $linea) {
                $this->runs($seccion->addTextRun($i === count($c['destinatario']) - 1 ? ['spaceAfter' => 360] : $sinEspacio), $linea);
            }
        }
        if ($c['preambulo']) {
            $this->runs($seccion->addTextRun(['alignment' => Jc::CENTER, 'spaceBefore' => 360, 'spaceAfter' => 240]), $c['preambulo']);
        }
        if ($c['titulo']) {
            $seccion->addText($c['titulo'], ['bold' => true, 'size' => $certificacion ? 15 : 12, 'color' => $certificacion ? $azul : null], [
                'alignment' => Jc::CENTER, 'spaceBefore' => $certificacion ? 120 : 240, 'spaceAfter' => $certificacion ? 360 : 480,
            ]);
        }
        if ($c['asunto']) {
            $this->runs($seccion->addTextRun(['spaceAfter' => 360]), $c['asunto']);
        }
        if ($c['saludo']) {
            $seccion->addText($c['saludo'], [], $parrafo);
        }
        foreach ($c['parrafos'] as $texto) {
            $this->runs($seccion->addTextRun($parrafo), $texto);
        }
        if ($c['condiciones']) {
            $seccion->addText($c['condiciones_intro'], [], $parrafo);
            foreach ($c['condiciones'] as [$etiqueta, $texto]) {
                $this->runs($seccion->addListItemRun(0, null, $parrafo), "**{$etiqueta}:** {$texto}");
            }
        }
        $seccion->addText($c['cierre'], [], $parrafo);
        $seccion->addText($c['despedida'], [], ['spaceBefore' => 240, 'spaceAfter' => 120]);

        // Firma y sello lado a lado.
        $tabla = $seccion->addTable();
        $fila = $tabla->addRow();
        $celda = $fila->addCell(5200, ['valign' => 'bottom']);
        if ($img['firma']) {
            $celda->addImage($img['firma'], ['height' => 55]);
        } else {
            $celda->addTextBreak(3);
        }
        foreach ($this->lineasFirma($c['firmante']) as [$linea, $negrilla]) {
            $celda->addText($linea, $negrilla ? ['bold' => true] : [], $sinEspacio);
        }
        $celdaSello = $fila->addCell(3800, ['valign' => 'center']);
        if ($img['sello']) {
            $celdaSello->addImage($img['sello'], ['height' => 75]);
        }

        $ruta = tempnam(sys_get_temp_dir(), 'carta_').'.docx';
        IOFactory::createWriter($word, 'Word2007')->save($ruta);

        return $ruta;
    }

    /** El bloque bajo la firma: [texto, negrilla]. El PDF y el Word lo pintan igual. */
    public function lineasFirma(array $firmante): array
    {
        return array_values(array_filter([
            ['_______________________________', false],
            [$firmante['nombre'], true],
            $firmante['cargo'] ? [mb_strtoupper($firmante['cargo']), false] : null,
            $firmante['celular'] ? ['Cel. '.$firmante['celular'], false] : null,
            [$firmante['empresa'], false],
        ]));
    }

    /** Agrega a un TextRun el texto con sus tramos en negrilla. */
    private function runs($run, string $texto): void
    {
        foreach (preg_split('/\*\*(.+?)\*\*/u', $texto, -1, PREG_SPLIT_DELIM_CAPTURE) as $i => $parte) {
            if ($parte !== '') {
                $run->addText($parte, $i % 2 ? ['bold' => true] : []);
            }
        }
    }

    public function lineasPie(array $empresa): array
    {
        $lineas = [];
        if ($empresa['direccion']) {
            $lineas[] = $empresa['direccion'];
        }
        $contacto = array_filter([
            $empresa['telefono'] ? (preg_match('/^3\d{9}$/', preg_replace('/\D/', '', $empresa['telefono'])) ? 'Cel. ' : 'Tel. ')
                .$this->celular($empresa['telefono']) : null,
            $empresa['correo'] ?: null,
        ]);
        if ($contacto) {
            $lineas[] = implode(' · ', $contacto);
        }

        return $lineas;
    }

    /** Nombre del archivo para descargar. */
    public function nombreArchivo(array $d, string $extension): string
    {
        $nombre = preg_replace('/[^A-Za-z0-9]+/', '_', \Illuminate\Support\Str::ascii($d['trabajador']['nombre']));

        return 'Carta_laboral_'.trim($nombre, '_').'_'.$d['trabajador']['documento'].'.'.$extension;
    }
}
