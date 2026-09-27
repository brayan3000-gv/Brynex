<?php

namespace App\Services;

use App\Http\Controllers\Admin\AnticipoController;
use App\Http\Controllers\Admin\FacturacionController;
use App\Models\Aliado;
use App\Models\Anticipo;
use App\Models\Contrato;
use App\Models\Factura;
use App\Models\WhatsappConfig;
use App\Models\WhatsappConversacion;
use App\Models\WhatsappMensaje;
use App\Models\WhatsappPlantilla;
use App\Services\ArlSura\ArlSuraSesionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;

/**
 * Recibo del cobro en visita, para mandarlo por WhatsApp.
 *
 * Es el MISMO recibo de facturación y de anticipos que se imprime en la
 * oficina, en vista simple (sin separar seguridad social y administración):
 * se renderizan esas vistas y el Chrome del servidor las imprime a PDF
 * (scripts/html-a-pdf.mjs). No hay un diseño aparte que mantener.
 *
 * Un cobro puede dejar una factura, uno o dos anticipos (efectivo y
 * transferencia), o la factura más el anticipo de lo que sobró: cada recibo
 * va en su página, dentro de un solo PDF.
 */
class ReciboVisitaService
{
    /** Nombre de la plantilla en Meta (ver comando whatsapp:plantilla-recibo). */
    public const PLANTILLA = 'recibo_de_pago';

    public const CUERPO_PLANTILLA = "Hola {{1}} 👋\n\n*{{2}}* recibió tu pago de *{{3}}*.\nConcepto: {{4}}\n\nAdjuntamos tu recibo en PDF. ¡Gracias por estar al día! ✅";

    private const MESES = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
        'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /**
     * Lo que forma el recibo de un cobro, ya filtrado por el aliado.
     *
     * @param  int[]  $anticipoIds
     */
    public function datos(int $aliadoId, ?int $facturaId, array $anticipoIds): array
    {
        $factura = $facturaId ? Factura::where('aliado_id', $aliadoId)->findOrFail($facturaId) : null;

        $anticipos = Anticipo::where('aliado_id', $aliadoId)
            ->whereIn('id', $anticipoIds ?: [0])
            ->orderBy('id')
            ->get();

        $contratoId = $factura?->contrato_id ?? $anticipos->first()?->contrato_id;
        abort_if(! $contratoId, 404);

        $contrato = Contrato::where('aliado_id', $aliadoId)->with('cliente')->findOrFail($contratoId);

        $pagadoHoy = (int) $anticipos->sum('valor')
            + (int) $factura?->valor_efectivo + (int) $factura?->valor_consignado;

        return [
            'aliado' => Aliado::find($aliadoId),
            'contrato' => $contrato,
            'cliente' => $contrato->cliente,
            'factura' => $factura,
            'anticipos' => $anticipos,
            'pagadoHoy' => $pagadoHoy,
            'concepto' => $this->concepto($factura, $anticipos),
            'numero' => $factura?->numero_factura ?: ('ANT-'.$anticipos->first()?->id),
        ];
    }

    /**
     * El PDF del cobro: el recibo de la factura y el de cada anticipo, uno por
     * página.
     */
    public function pdf(array $datos): string
    {
        $htmls = [];
        if ($datos['factura']) {
            $htmls[] = $this->vistaOficina(fn () => app(FacturacionController::class)->recibo($datos['factura']->id));
        }
        foreach ($datos['anticipos'] as $anticipo) {
            $htmls[] = $this->vistaOficina(fn () => app(AnticipoController::class)->reciboAnticipo($anticipo->id));
        }

        $resultado = Process::path(base_path())
            ->timeout(90)
            ->input(json_encode(['htmls' => $htmls, 'recorte' => '#recibo-print-area'], JSON_UNESCAPED_UNICODE))
            ->run(ArlSuraSesionService::binarioNode().' scripts/html-a-pdf.mjs');

        $salida = json_decode(trim($resultado->output()), true) ?: [];
        if (! ($salida['ok'] ?? false) || empty($salida['pdfs'])) {
            throw new RuntimeException('No se pudo generar el PDF del recibo: '
                .($salida['error'] ?? (trim($resultado->errorOutput()) ?: 'el proceso no respondió')));
        }

        $pdfs = array_map('base64_decode', $salida['pdfs']);

        return count($pdfs) === 1 ? $pdfs[0] : $this->unir($pdfs);
    }

    /**
     * Renderiza un recibo tal como sale en el modal de la oficina: sin menú
     * (modal), solo el contrato (individual), sin botón de anular y en su
     * vista por defecto, que es la simple. Las vistas leen esas banderas de
     * request(), así que se cambia la petición mientras se pinta.
     */
    private function vistaOficina(callable $vista): string
    {
        $original = app('request');
        $copia = $original->duplicate(['modal' => 1, 'individual' => 1, 'no_anular' => 1]);
        if ($original->hasSession()) {
            $copia->setLaravelSession($original->session());
        }
        app()->instance('request', $copia);

        try {
            return $vista()->render();
        } finally {
            app()->instance('request', $original);
        }
    }

    /** @param string[] $pdfs */
    private function unir(array $pdfs): string
    {
        $fpdi = new Fpdi;
        foreach ($pdfs as $contenido) {
            $paginas = $fpdi->setSourceFile(StreamReader::createByString($contenido));
            for ($i = 1; $i <= $paginas; $i++) {
                $plantilla = $fpdi->importPage($i);
                $tam = $fpdi->getTemplateSize($plantilla);
                $fpdi->AddPage($tam['orientation'], [$tam['width'], $tam['height']]);
                $fpdi->useTemplate($plantilla);
            }
        }

        return $fpdi->Output('S');
    }

    public function nombreArchivo(array $datos): string
    {
        $nombre = \Illuminate\Support\Str::slug($datos['cliente']?->nombre_corto ?: $datos['contrato']->cedula, '_');

        return "recibo_{$datos['numero']}_{$nombre}.pdf";
    }

    /**
     * Manda el recibo al celular del cliente.
     *
     * Con la plantilla aprobada sale siempre. Sin ella, solo si el cliente
     * escribió en las últimas 24 horas (Meta deja mandar el documento suelto
     * dentro de esa ventana).
     *
     * @return array{ok:bool, mensaje:string}
     */
    public function enviarWhatsapp(int $aliadoId, string $celular, array $datos): array
    {
        if (! WhatsappApiService::esCelularColombiano($celular)) {
            return ['ok' => false, 'mensaje' => 'El celular no es válido: '.$celular];
        }

        $config = WhatsappConfig::paraAliado($aliadoId);
        if (! $config->activo || ! $config->credencialesCompletas()) {
            return ['ok' => false, 'mensaje' => 'El aliado no tiene WhatsApp configurado.'];
        }

        $numero = WhatsappApiService::normalizarNumero($celular);
        $conversacion = WhatsappConversacion::where('aliado_id', $aliadoId)->where('wa_contact_id', $numero)->first();
        $plantilla = $this->plantillaPara($aliadoId, $config);

        if (! $plantilla && ! $conversacion?->ventanaActiva()) {
            return ['ok' => false, 'mensaje' => 'La plantilla del recibo todavía no la aprueba Meta y el cliente no ha escrito en las últimas 24 horas. Compártelo con el botón «Compartir».'];
        }

        $archivo = $this->nombreArchivo($datos);
        $ruta = "whatsapp/recibos/{$aliadoId}/".uniqid().'_'.$archivo;
        try {
            Storage::disk('local')->put($ruta, $this->pdf($datos));
        } catch (RuntimeException $e) {
            report($e);

            return ['ok' => false, 'mensaje' => 'No se pudo generar el PDF del recibo. Intenta de nuevo.'];
        }

        $api = app(WhatsappApiService::class);
        $valor = '$'.number_format($datos['pagadoHoy'], 0, ',', '.');
        $nombreCliente = $datos['cliente']?->nombre_corto ?: 'cliente';
        $nombreAliado = $datos['aliado']?->nombre ?: 'BryNex';
        $parametros = [$nombreCliente, $nombreAliado, $valor, $datos['concepto']];

        try {
            if ($plantilla) {
                $mediaId = $api->subirMedia($ruta, 'application/pdf', $config->credencialesEfectivas());
                if (! $mediaId) {
                    return ['ok' => false, 'mensaje' => 'No se pudo subir el PDF a WhatsApp. Intenta de nuevo.'];
                }
                $resultado = $api->enviarTemplateConDocumento($numero, $plantilla, $parametros, $mediaId, $archivo, $config);
            } else {
                $texto = "Hola {$nombreCliente}, {$nombreAliado} recibió tu pago de {$valor}. Concepto: {$datos['concepto']}.";
                $resultado = $api->enviarMedia($numero, 'document', $ruta, 'application/pdf', $archivo, $config, $texto);
            }
        } finally {
            Storage::disk('local')->delete($ruta);
        }

        if (! ($resultado['ok'] ?? false)) {
            return ['ok' => false, 'mensaje' => 'WhatsApp no aceptó el envío: '.($resultado['error'] ?? 'error desconocido')];
        }

        $conversacion ??= WhatsappConversacion::create([
            'aliado_id' => $aliadoId,
            'wa_contact_id' => $numero,
            'nombre_contacto' => $datos['cliente']?->nombre_completo ?: $nombreCliente,
            'estado' => 'abierta',
            'mensajes_no_leidos' => 0,
            'ultima_actividad' => now(),
        ]);
        $conversacion->update(['ultima_actividad' => now()]);

        WhatsappMensaje::create([
            'conversacion_id' => $conversacion->id,
            'aliado_id' => $aliadoId,
            'wa_message_id' => $resultado['wa_message_id'] ?? null,
            'direccion' => 'saliente',
            'tipo' => $plantilla ? 'template' : 'document',
            'contenido' => "Recibo de pago {$datos['numero']} (PDF adjunto) · {$valor}",
            'media_nombre' => $archivo,
            'plantilla_id' => $plantilla?->id,
            'plantilla_parametros' => $plantilla ? $parametros : null,
            'estado' => 'enviado',
            'estado_at' => now(),
            'usuario_id' => Auth::id(),
        ]);

        return ['ok' => true, 'mensaje' => 'Recibo enviado por WhatsApp al '.WhatsappApiService::formatoNacional($numero).'.'];
    }

    /**
     * La plantilla aprobada que le sirve al aliado: la suya si tiene cuenta
     * propia, o la registrada para la cuenta de BryNex si usa esa.
     */
    public function plantillaPara(int $aliadoId, WhatsappConfig $config): ?WhatsappPlantilla
    {
        $q = WhatsappPlantilla::where('nombre', self::PLANTILLA)->where('estado', 'approved');

        if (! $config->usa_cuenta_brynex) {
            return $q->where('aliado_id', $aliadoId)->first();
        }

        return $q->whereIn('aliado_id', WhatsappConfig::where('usa_cuenta_brynex', true)->select('aliado_id'))->first();
    }

    private function concepto(?Factura $factura, $anticipos): string
    {
        if ($factura) {
            $periodo = (self::MESES[(int) $factura->mes] ?? '').' '.$factura->anio;
            $base = $factura->tipo === Factura::TIPO_AFILIACION ? 'Afiliación de '.$periodo : 'Seguridad social de '.$periodo;

            return $anticipos->isNotEmpty() ? $base.' y anticipo' : $base;
        }

        return 'Anticipo (abono a tu próximo pago)';
    }

    public static function periodo(int $mes, int $anio): string
    {
        return ucfirst(self::MESES[$mes] ?? '').' '.$anio;
    }
}
