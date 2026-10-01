<?php

namespace App\Services\Boxalud;

use App\Models\Contrato;
use App\Models\Radicado;
use App\Services\Afiliaciones\DatosAfiliacion;
use App\Services\EpsPortal\EpsRadicado;
use App\Services\FormularioEpsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Afiliación en los portales Boxalud (Emssanar) desde el radicado de EPS.
 *
 * El login tiene reCAPTCHA, así que la persona entra con el usuario de la razón
 * social en su Chrome y la extensión BryNex Portales llena "Ingreso de afiliación":
 * valida al afiliado en ADRES, completa contacto y relación laboral, valida al
 * aportante y adjunta el formulario firmado y la encuesta de la carta de derechos.
 * El botón GUARDAR del portal lo pulsa la persona; BryNex registra lo que salió.
 *
 * Configuración por EPS en config/boxalud.php. Mapeado el 15-sep-2026 sin guardar
 * ninguna afiliación: la lectura del resultado se ajusta con el primer caso real.
 */
class BoxaludService
{
    public function __construct(private FormularioEpsService $formularios) {}

    public function conf(string $eps): array
    {
        $conf = config("boxalud.{$eps}");
        if (! $conf) {
            throw new RuntimeException("La EPS «{$eps}» no está configurada para el portal Boxalud.");
        }

        return $conf;
    }

    /**
     * @return array{problemas: string[], avisos: string[], resumen: array, portal: array|null}
     */
    public function preparar(Contrato $contrato, string $eps): array
    {
        $conf = $this->conf($eps);
        $contrato->loadMissing(['cliente.eps', 'cliente.municipio', 'cliente.departamento', 'eps', 'plan', 'razonSocial', 'arl', 'pension']);
        $cliente = $contrato->cliente;
        $rs      = DatosAfiliacion::deContrato($contrato);
        $epsContrato = $contrato->eps ?: $cliente?->eps;
        $tipo    = strtoupper((string) $cliente?->tipo_doc);
        $radicado = Radicado::where('contrato_id', $contrato->id)->where('tipo', Radicado::TIPO_EPS)->first();
        $problemas = [];
        $avisos = [];

        if ($contrato->estado !== 'vigente') {
            $problemas[] = 'El contrato no está vigente.';
        }
        if ($epsContrato?->codigo !== $conf['codigo_eps']) {
            $problemas[] = "La EPS del contrato no es {$conf['nombre']}.";
        }
        if (! $contrato->plan?->incluye_eps) {
            $problemas[] = 'El plan del contrato no incluye EPS.';
        }
        if (! $rs || $rs->es_independiente) {
            $problemas[] = 'Por ahora el portal se usa para dependientes: el independiente va por correo.';
        }
        if (! $cliente) {
            $problemas[] = 'El contrato no tiene cliente en BryNex.';
        } elseif (! isset($conf['tipos_documento'][$tipo])) {
            $problemas[] = "Tipo de documento '{$cliente->tipo_doc}' sin equivalencia en {$conf['nombre']}.";
        }
        if (! $contrato->fecha_ingreso) {
            $problemas[] = 'El contrato no tiene fecha de ingreso.';
        }
        if ($radicado?->estado === Radicado::ESTADO_OK) {
            $problemas[] = 'El radicado de EPS ya está en OK.';
        }

        $cred = $rs ? BoxaludPortalService::credencial($eps, (string) $rs->nit) : ['error' => 'Sin razón social.'];
        if (isset($cred['error'])) {
            $problemas[] = $cred['error'].' Usa el correo como plan B.';
        }

        $celulares = collect([$cliente?->celular, $cliente?->telefono])
            ->flatMap(fn ($t) => preg_split('/[,;\/|]| - /', (string) $t))
            ->map(fn ($t) => preg_replace('/\D/', '', $t))->filter(fn ($t) => strlen($t) === 10 && str_starts_with($t, '3'))->unique()->values();
        $fijo = collect([$cliente?->telefono, $rs?->telefonos])
            ->flatMap(fn ($t) => preg_split('/[,;\/|]| - /', (string) $t))
            ->map(fn ($t) => preg_replace('/\D/', '', $t))->first(fn ($t) => strlen($t) >= 7 && strlen($t) <= 10 && ! str_starts_with($t, '3'));
        if ($celulares->isEmpty()) {
            $problemas[] = 'El cliente no tiene celular de 10 dígitos: el portal lo exige.';
        }
        if (! $cliente?->direccion_vivienda) {
            $avisos[] = 'El cliente no tiene dirección en BryNex: habrá que escribirla en el portal.';
        }
        if (! $cliente?->correo) {
            $avisos[] = 'El cliente no tiene correo: se usa el del buzón del aliado.';
        }
        if ($radicado?->numero_radicado && $radicado->estado === Radicado::ESTADO_TRAMITE) {
            $avisos[] = "Este radicado ya está en trámite (N° {$radicado->numero_radicado}). Radica otra vez solo si no quedó en el portal.";
        }

        $nombre = trim(implode(' ', array_filter([$cliente?->primer_nombre, $cliente?->segundo_nombre, $cliente?->primer_apellido, $cliente?->segundo_apellido])));
        $salario = (int) round((float) ($contrato->salario ?: $contrato->ibc));
        $resumen = [
            'trabajador'   => $nombre,
            'documento'    => trim($tipo.' '.$contrato->cedula),
            'razon_social' => $rs?->razon_social,
            'nit'          => $rs?->nit,
            'eps'          => $epsContrato?->nombre,
            'fecha_ingreso' => $contrato->fecha_ingreso?->toDateString(),
            'salario'      => $salario,
            'afp'          => $contrato->pension?->razon_social,
            'arl'          => $contrato->arl?->nombre_arl ?? $contrato->arl?->razon_social,
            'direccion'    => $cliente?->direccion_vivienda,
            'celulares'    => $celulares->implode(' · '),
            'usuario_portal' => $cred['usuario'] ?? null,
            'portal'       => $conf['nombre'],
        ];

        return ['problemas' => $problemas, 'avisos' => $avisos, 'resumen' => $resumen, 'portal' => $problemas ? null : [
            'host'          => $conf['host'],
            'empresa'       => $rs->razon_social,
            'nit'           => preg_replace('/\D/', '', (string) $rs->nit),
            'tipoDoc'       => $conf['tipos_documento'][$tipo],
            'documento'     => (string) $contrato->cedula,
            'apellido'      => (string) $cliente->primer_apellido,
            'fechaIngreso'  => $contrato->fecha_ingreso->format('Y-m-d'),
            'direccion'     => (string) $cliente->direccion_vivienda,
            'celular'       => $celulares->get(0),
            'celular2'      => $celulares->get(1),
            'fijo'          => $fijo,
            'correo'        => $cliente->correo ?: DatosAfiliacion::buzon($contrato->aliado_id),
            'afp'           => (string) $contrato->pension?->razon_social,
            'arl'           => (string) ($contrato->arl?->nombre_arl ?? $contrato->arl?->razon_social),
            'salario'       => $salario,
            'cargo'         => mb_strtoupper(trim((string) $contrato->cargo)) ?: 'OPERARIO',
            'documentos'    => [
                ['tipo' => $conf['documentos']['formulario'], 'url' => route('admin.afiliaciones.boxalud.archivo', [$contrato->id, $eps, 'formulario'], false), 'nombre' => 'Formulario_'.Str::slug($nombre, '_').'.pdf'],
                ['tipo' => $conf['documentos']['encuesta'], 'url' => route('admin.afiliaciones.boxalud.archivo', [$contrato->id, $eps, 'encuesta'], false), 'nombre' => 'Encuesta_carta_derechos_'.Str::slug($nombre, '_').'.pdf'],
            ],
        ]];
    }

    /** Usuario (y clave, según permiso) del portal para la razón social del contrato. */
    public function credencial(Contrato $contrato, string $eps): array
    {
        $conf = $this->conf($eps);
        $contrato->loadMissing('razonSocial');
        // Por BoxaludPortalService y no por EpsClavePortal a secas: ahí vive la
        // regla de que el usuario del empleador es el NIT con una P detrás.
        $cred = BoxaludPortalService::credencial($eps, (string) $contrato->razonSocial?->nit);

        return isset($cred['error']) ? $cred : ['usuario' => $cred['usuario'], 'contrasena' => $cred['contrasena'], 'host' => $conf['host']];
    }

    /**
     * El formulario de la EPS firmado, partido como lo pide el portal: "formulario"
     * (páginas del formulario) o "encuesta" (la última página: encuesta de la carta
     * de derechos y deberes). Se guarda copia en los soportes del radicado.
     */
    public function archivo(Contrato $contrato, string $eps, string $tipo): string
    {
        $this->conf($eps);
        if (! in_array($tipo, ['formulario', 'encuesta'], true)) {
            throw new RuntimeException('Archivo desconocido.');
        }

        $disco = Storage::disk('local');
        $ruta = EpsRadicado::guardarPdf($contrato, $this->formularios->generar($contrato, true, []), "eps_formulario_{$eps}_portal");
        if (! $ruta) {
            throw new RuntimeException('No se pudo generar el formulario de EPS del contrato.');
        }

        $origen = $disco->path($ruta);
        $paginas = $this->paginas($origen);
        [$desde, $hasta] = $tipo === 'encuesta' ? [$paginas, $paginas] : [1, max(1, $paginas - 1)];
        $destino = preg_replace('/\.pdf$/', "_{$tipo}.pdf", $origen);

        exec(sprintf('%s -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=pdfwrite -dFirstPage=%d -dLastPage=%d -o %s %s 2>&1',
            is_executable('/usr/bin/gs') ? '/usr/bin/gs' : 'gs', $desde, $hasta, escapeshellarg($destino), escapeshellarg($origen)), $salida, $codigo);
        if ($codigo !== 0 || ! is_file($destino)) {
            throw new RuntimeException('No se pudo separar el formulario: '.implode(' ', $salida));
        }

        return file_get_contents($destino);
    }

    /**
     * Registra lo que salió del portal después de que la persona pulsó GUARDAR.
     *
     * $entrada: numero (radicado o id de afiliación que muestre el portal, o el que
     * escribió la persona), texto (mensajes del portal), error (si no quedó).
     */
    public function aplicar(Contrato $contrato, string $eps, array $entrada, ?int $usuarioId): array
    {
        $conf = $this->conf($eps);
        $radicado = EpsRadicado::deContrato($contrato);
        $numero = trim((string) ($entrada['numero'] ?? '')) ?: null;
        $texto = trim(preg_replace('/\s+/', ' ', (string) ($entrada['texto'] ?? '')));

        if (! empty($entrada['error']) && ! $numero) {
            $mensaje = "{$conf['nombre']}: el portal no registró la afiliación — ".mb_substr((string) $entrada['error'], 0, 300);
            EpsRadicado::marcar($radicado, null, Radicado::ESTADO_ERROR, null, $mensaje, $usuarioId);
            EpsRadicado::bitacora($contrato, $radicado, $eps, 'ingreso_afiliacion', 'fallida', null, [], ['texto' => mb_substr($texto, 0, 2000)], (string) $entrada['error'], $usuarioId);

            return ['ok' => false, 'estado' => Radicado::ESTADO_ERROR, 'mensaje' => $mensaje];
        }
        if (! $numero && $texto === '') {
            throw new RuntimeException('Falta el número o el mensaje que dio el portal.');
        }

        $ruta = EpsRadicado::guardarPdf($contrato, $this->constancia($contrato, $conf['nombre'], $numero, $texto), "eps_radicado_{$eps}");
        $observacion = sprintf('%s: afiliación registrada en el portal Boxalud%s el %s. Se confirma con la certificación de relación laboral.',
            $conf['nombre'], $numero ? " (N° {$numero})" : '', now()->format('d/m/Y H:i'));

        EpsRadicado::marcar($radicado, $numero, Radicado::ESTADO_TRAMITE, $ruta, $observacion, $usuarioId);
        EpsRadicado::bitacora($contrato, $radicado, $eps, 'ingreso_afiliacion', 'exitosa', $numero, [], ['texto' => mb_substr($texto, 0, 2000)], null, $usuarioId, $ruta);

        return ['ok' => true, 'estado' => $radicado->estado, 'numero' => $numero, 'mensaje' => $observacion];
    }

    private function constancia(Contrato $contrato, string $nombreEps, ?string $numero, string $texto): ?string
    {
        $cliente = $contrato->cliente;
        $e = fn ($s) => e((string) $s);
        $html = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#0f172a}h1{font-size:15px;margin:0 0 6px}'
            .'table{border-collapse:collapse;margin:8px 0 10px}td{padding:3px 8px;border:1px solid #cbd5e1}.t{color:#475569}.txt{border:1px solid #e2e8f0;padding:8px;line-height:1.5}</style></head><body>'
            ."<h1>Constancia de afiliación — {$e($nombreEps)}</h1>"
            .'<div class="t">Portal de empleadores Boxalud · Ingreso de afiliación</div><table>'
            .'<tr><td class="t">Número</td><td><strong>'.$e($numero ?? '—').'</strong></td></tr>'
            .'<tr><td class="t">Fecha</td><td>'.now()->format('d/m/Y H:i').'</td></tr>'
            .'<tr><td class="t">Trabajador</td><td>'.$e(trim(($cliente?->primer_nombre ?? '').' '.($cliente?->primer_apellido ?? '').' '.($cliente?->segundo_apellido ?? ''))).' — '.$e($cliente?->tipo_doc).' '.$e($contrato->cedula).'</td></tr>'
            .'<tr><td class="t">Aportante</td><td>'.$e($contrato->razonSocial?->razon_social).' — NIT '.$e($contrato->razonSocial?->nit).'</td></tr></table>'
            .($texto !== '' ? '<div class="t">Lo que mostró el portal:</div><div class="txt">'.$e(mb_substr($texto, 0, 4000)).'</div>' : '')
            .'</body></html>';

        return Pdf::loadHTML($html)->setPaper('letter')->output();
    }

    private function paginas(string $pdf): int
    {
        $salida = shell_exec(sprintf('%s -q -dNODISPLAY -dNOSAFER -c %s 2>/dev/null',
            is_executable('/usr/bin/gs') ? '/usr/bin/gs' : 'gs',
            escapeshellarg('('.$pdf.') (r) file runpdfbegin pdfpagecount = quit')));
        $n = (int) trim((string) $salida);

        return $n > 0 ? $n : 3;
    }
}
