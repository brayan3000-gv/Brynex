<?php

namespace App\Services;

use App\Models\Aliado;
use App\Models\Anticipo;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Factura;
use App\Models\WhatsappConfig;
use App\Models\WhatsappConversacion;
use App\Models\WhatsappMensaje;
use App\Models\WhatsappPlantilla;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Recibo del cobro en visita: un PDF corto con la factura y/o los anticipos
 * de un mismo cobro, que se le manda al cliente por WhatsApp.
 *
 * Un cobro puede dejar una factura, uno o dos anticipos (efectivo y
 * transferencia), o la factura más el anticipo de lo que sobró: el recibo los
 * junta en una sola hoja para que el cliente vea lo que pagó ese día.
 */
class ReciboVisitaService
{
    /** Nombre de la plantilla en Meta (ver comando whatsapp:plantilla-recibo). */
    public const PLANTILLA = 'recibo_de_pago';

    public const CUERPO_PLANTILLA = "Hola {{1}} 👋\n\n*{{2}}* recibió tu pago de *{{3}}*.\nConcepto: {{4}}\n\nAdjuntamos tu recibo en PDF. ¡Gracias por estar al día! ✅";

    private const MESES = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
        'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /**
     * Todo lo que el recibo muestra, ya filtrado por el aliado.
     *
     * @param  int[]  $anticipoIds
     */
    public function datos(int $aliadoId, ?int $facturaId, array $anticipoIds): array
    {
        $factura = $facturaId
            ? Factura::where('aliado_id', $aliadoId)->with(['consignaciones.bancoCuenta', 'usuario'])->findOrFail($facturaId)
            : null;

        $anticipos = Anticipo::where('aliado_id', $aliadoId)
            ->whereIn('id', $anticipoIds ?: [0])
            ->with(['bancoCuenta', 'usuario'])
            ->orderBy('id')
            ->get();

        // La factura también puede haber consumido anticipos de antes: el
        // cliente los ve en el recibo como parte de lo que la cubrió.
        $anticiposAplicados = $factura
            ? Anticipo::where('aliado_id', $aliadoId)->where('factura_id', $factura->id)->orderBy('fecha_pago')->get()
            : collect();

        $contratoId = $factura?->contrato_id ?? $anticipos->first()?->contrato_id;
        abort_if(! $contratoId, 404);

        $contrato = Contrato::where('aliado_id', $aliadoId)->with(['cliente', 'razonSocial', 'tipoModalidad'])->findOrFail($contratoId);
        $aliado = Aliado::find($aliadoId);

        $pagadoHoy = $anticipos->sum('valor');
        $favorAplicado = 0;
        if ($factura) {
            $pagadoHoy += (int) $factura->valor_efectivo + (int) $factura->valor_consignado;
            // Lo que no cubrió la plata ni los anticipos lo cubrió el saldo a favor que traía.
            $favorAplicado = max(0, (int) $factura->total - (int) $factura->valor_efectivo
                - (int) $factura->valor_consignado - (int) $factura->anticipo_aplicado);
        }

        $usuario = $factura?->usuario ?? $anticipos->first()?->usuario ?? Auth::user();

        return [
            'aliado' => $aliado,
            'logo' => $this->logoDataUri($aliado),
            'contrato' => $contrato,
            'cliente' => $contrato->cliente,
            'factura' => $factura,
            'anticipos' => $anticipos,
            'anticiposAplicados' => $anticiposAplicados,
            'favorAplicado' => $favorAplicado,
            'pagadoHoy' => (int) $pagadoHoy,
            'concepto' => $this->concepto($factura, $anticipos),
            'numero' => $factura?->numero_factura ?: ('A-'.$anticipos->first()?->id),
            // La hora en que se registró el pago, no la de abrir el recibo.
            'fecha' => $factura?->created_at ?? $anticipos->first()?->created_at ?? now(),
            'usuario' => $usuario?->nombre ?? $usuario?->name ?? '',
            'saldoAnticipo' => Anticipo::saldoDisponible($aliadoId, $contrato->id),
        ];
    }

    public function pdf(array $datos): string
    {
        return \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.recibo_visita', $datos)
            // Formato de tirilla, ancho de celular: se lee sin hacer zoom. El
            // alto sale de las filas que se pintan (medido: ~255 pt fijos y
            // ~16 pt por fila), para que no quede media hoja en blanco.
            ->setPaper([0, 0, 300, 275 + $this->filas($datos) * 16])
            ->output();
    }

    /** Filas de largo variable del recibo (ver pdf/recibo_visita). */
    private function filas(array $datos): int
    {
        $n = 1; // "Anticipos a tu favor"
        if ($f = $datos['factura']) {
            $conceptos = [$f->v_eps, $f->v_afp, $f->v_arl, $f->v_caja, $f->v_parafiscales, $f->afiliacion,
                (int) $f->admon + (int) $f->admin_asesor, $f->seguro, $f->iva, $f->mora, (int) $f->otros + (int) $f->otros_admon];
            $n += 4 + count(array_filter($conceptos, fn ($v) => (int) $v !== 0))
                + ((int) $f->valor_efectivo ? 1 : 0) + $f->consignaciones->count()
                + $datos['anticiposAplicados']->count() + ($datos['favorAplicado'] > 0 ? 1 : 0);
        }
        if ($datos['anticipos']->isNotEmpty()) {
            $n += 2 + $datos['anticipos']->count();
        }

        return $n;
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
        Storage::disk('local')->put($ruta, $this->pdf($datos));

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

    /** El logo embebido: DomPDF no descarga imágenes remotas. */
    private function logoDataUri(?Aliado $aliado): ?string
    {
        $ruta = $aliado?->logo ? storage_path('app/public/'.$aliado->logo) : null;
        // DomPDF no pinta webp: ese logo cae al de BryNex.
        if (! $ruta || ! is_file($ruta) || str_ends_with(strtolower($ruta), '.webp')) {
            $ruta = public_path('img/logo-brynex.png');
        }
        if (! is_file($ruta)) {
            return null;
        }
        $mime = mime_content_type($ruta) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode(file_get_contents($ruta));
    }

    public static function periodo(int $mes, int $anio): string
    {
        return ucfirst(self::MESES[$mes] ?? '').' '.$anio;
    }
}
