<?php

namespace App\Services\Ia\Tools;

use App\Events\WhatsappConversacionActualizada;
use App\Events\WhatsappMensajeNuevo;
use App\Models\Plano;
use App\Models\WhatsappConfig;
use App\Models\WhatsappConversacion;
use App\Models\WhatsappMensaje;
use App\Services\Ia\ClienteWhatsappResolver;
use App\Services\EnlaceInformeIndividualService;
use App\Services\WhatsappApiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Solo canal WhatsApp. Busca la planilla de seguridad social ya pagada al operador
 * (numero_planilla registrado + gasto tipo_planilla confirmado) y, si la encuentra,
 * genera el PDF y lo envía directo por este mismo WhatsApp (dentro de la ventana de
 * 24h, sin necesidad de plantilla). Mismo criterio de verificación de identidad que
 * consultar_cliente: pide cédula si el número es de varias personas.
 *
 * Si no hay planilla generada todavía, NO habla de plazos en días (varía por aliado):
 * solo indica que en cuanto esté lista llega por este mismo WhatsApp, y ofrece
 * escalar a un asesor si es urgente.
 */
class EnviarPlanillaTool implements IaToolInterface
{
    public function nombre(): string
    {
        return 'enviar_planilla';
    }

    public function descripcion(): string
    {
        return 'Busca y envía por WhatsApp el PDF de la planilla de seguridad social (PILA) del cliente para el '
            . 'período correspondiente (el último vencido si no se especifica otro). Úsala cuando el cliente pida '
            . 'su planilla, certificado de pago, o comprobante de PILA. Si devuelve requiere_cedula=true, pídele '
            . 'la cédula y vuelve a llamarla con ese dato.';
    }

    public function schema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'cedula' => [
                    'type'        => 'string',
                    'description' => 'Cédula de la persona cuya planilla se pide. Inclúyela SIEMPRE que quien '
                        . 'escribe la mencione (la suya propia, o la de alguien más a quien está ayudando) — es '
                        . 'la verificación de identidad, tiene prioridad sobre el número de WhatsApp. También '
                        . 'inclúyela si la tool pidió verificarla (requiere_cedula=true).',
                ],
                'mes' => ['type' => 'integer', 'description' => 'Mes de la planilla que pide (1-12). Si no lo menciona, omítelo — se usa el último período vencido.'],
                'anio' => ['type' => 'integer', 'description' => 'Año de la planilla que pide. Si no lo menciona, omítelo.'],
            ],
        ];
    }

    public function ejecutar(array $input, array $contexto): array
    {
        $waConversacionId = $contexto['wa_conversacion_id'] ?? null;
        $conversacion = $waConversacionId ? WhatsappConversacion::find($waConversacionId) : null;

        if (!$conversacion) {
            return ['encontrada' => false, 'nota' => 'No pude verificar el número en este momento.'];
        }

        $resuelto = ClienteWhatsappResolver::resolver($conversacion, $input['cedula'] ?? null);
        if ($resuelto['requiere_cedula']) {
            return ['requiere_cedula' => true, 'nota' => ClienteWhatsappResolver::notaRequiereCedula()];
        }

        $cliente = $resuelto['cliente'];
        if (!$cliente) {
            return ['encontrada' => false, 'nota' => 'No encontré ningún cliente registrado con este número.'];
        }

        $aliadoId = $conversacion->aliado_id;
        $nombreCliente = trim(($cliente->primer_nombre ?? '') . ' ' . ($cliente->primer_apellido ?? ''));
        // Define si el mensaje debe decir "tu planilla" o "la planilla de {nombre}".
        $esPropia = $resuelto['es_propia'];
        $referenciaPendiente = $esPropia ? 'su planilla' : "la planilla de {$nombreCliente}";
        $mensajePendiente = "No hay una planilla generada todavía para ese período. Dile que estamos pendientes de "
            . "generar {$referenciaPendiente} y que en cuanto esté lista se envía por este mismo WhatsApp. Si la "
            . 'necesita con urgencia, ofrécele pasar con un asesor (hablar_con_asesor).';

        // Operadores con PDF configurado (mismo criterio que el envío masivo en /admin/planos/envio-planillas)
        $operadorIdsConfigurados = DB::table('operador_planillas_templates')->pluck('operador_planilla_id')->toArray();
        $operadores = DB::table('operadores_planilla')->whereIn('id', $operadorIdsConfigurados)->get(['id', 'nombre']);

        $query = Plano::where('aliado_id', $aliadoId)
            ->where('no_identifi', $cliente->cedula)
            ->where('tipo_reg', 'planilla')
            ->whereNotNull('numero_planilla')
            ->where('numero_planilla', '!=', '');

        if (!empty($input['mes']) && !empty($input['anio'])) {
            $query->where('mes_plano', (int) $input['mes'])->where('anio_plano', (int) $input['anio']);
        } else {
            $mes = (int) now()->month;
            $anio = (int) now()->year;
            $mesVencido = $mes > 1 ? $mes - 1 : 12;
            $anioVencido = $mes > 1 ? $anio : $anio - 1;

            Plano::filtrarPeriodoDePago($query, $mes, $anio, null);
        }

        $planos = $query->orderByDesc('id')->get();

        if ($planos->isEmpty()) {
            return ['encontrada' => false, 'nota' => $mensajePendiente];
        }

        // Verificar que cada plano tenga el pago confirmado (gasto pago_planilla) con un operador soportado
        $paraEnviar = [];
        foreach ($planos as $plano) {
            $gasto = DB::table('gastos')
                ->where('aliado_id', $aliadoId)
                ->where('tipo', 'pago_planilla')
                ->where('numero_planilla', $plano->numero_planilla)
                ->first(['pagado_a']);

            if (!$gasto) continue;

            $operadorNombreGasto = trim($gasto->pagado_a);
            $operadorDetectado = null;
            foreach ($operadores as $op) {
                if (
                    strcasecmp($op->nombre, $operadorNombreGasto) === 0 ||
                    stripos($operadorNombreGasto, $op->nombre) !== false ||
                    stripos($op->nombre, $operadorNombreGasto) !== false
                ) {
                    $operadorDetectado = $op;
                    break;
                }
            }

            if (!$operadorDetectado) continue;

            $paraEnviar[] = ['plano' => $plano, 'operador_id' => $operadorDetectado->id, 'operador_nombre' => $operadorDetectado->nombre];
        }

        if (empty($paraEnviar)) {
            return ['encontrada' => false, 'nota' => $mensajePendiente];
        }

        $config = WhatsappConfig::paraAliado($aliadoId);
        if (!$config || !$config->credencialesCompletas()) {
            return ['encontrada' => true, 'enviada' => false, 'nota' => 'Encontré la planilla pero no pude enviarla por un problema técnico. Ofrece escalar con un asesor (hablar_con_asesor).'];
        }

        $soportes = app(EnlaceInformeIndividualService::class);
        $whatsappApi = app(WhatsappApiService::class);

        $periodosEnviados = [];

        foreach ($paraEnviar as $item) {
            $plano = $item['plano'];

            try {
                // Solo el real del operador: la copia de BryNex no se manda por WhatsApp.
                $pdfContenido = $soportes->soporteOriginal($plano, $item['operador_id'])['pdf'];

                // El mes de servicio es el mes actual (cuando se paga/envía la planilla), no el
                // período cotizado que cubre el plano (mes_plano/anio_plano puede ser el vencido).
                $nombreMes = now()->translatedFormat('F Y');
                $sujeto = $esPropia ? 'tu última planilla' : "la última planilla de {$nombreCliente}";
                $caption = "Te enviamos {$sujeto}, período de servicio {$nombreMes}. 📄";

                $nombreArchivo = "Planilla_SS_{$cliente->cedula}_{$plano->mes_plano}_{$plano->anio_plano}.pdf";
                // OJO: enviarMedia() reenvía este path a subirMedia(), que lee el archivo con
                // Storage::disk('local')->get($pathLocal) — debe ser la ruta RELATIVA al disco
                // (igual que $archivo->store(...) en WhatsappChatController), nunca la absoluta.
                $pathTemporal = "temp_planillas_ia/{$aliadoId}/" . uniqid() . '_' . $nombreArchivo;
                Storage::disk('local')->put($pathTemporal, $pdfContenido);

                $resultado = $whatsappApi->enviarMedia(
                    $conversacion->wa_contact_id,
                    'document',
                    $pathTemporal,
                    'application/pdf',
                    $nombreArchivo,
                    $config,
                    $caption
                );

                Storage::disk('local')->delete($pathTemporal);

                if (!$resultado['ok']) {
                    Log::warning('IA WhatsApp: fallo al enviar planilla', ['error' => $resultado['error'] ?? null, 'plano_id' => $plano->id]);
                    continue;
                }

                // El envío real ya ocurrió en este punto: lo que siga (registrar el mensaje local,
                // notificar en tiempo real) es secundario y NUNCA debe hacer que se reporte "fallido"
                // un envío que en realidad sí llegó al cliente.
                $periodosEnviados[] = sprintf('%02d/%d (%s)', $plano->mes_plano, $plano->anio_plano, $item['operador_nombre']);

                try {
                    $mensaje = WhatsappMensaje::create([
                        'conversacion_id' => $conversacion->id,
                        'aliado_id'       => $aliadoId,
                        'wa_message_id'   => $resultado['wa_message_id'],
                        'direccion'       => 'saliente',
                        'tipo'            => 'document',
                        'contenido'       => $caption . " (PDF: Planilla {$plano->mes_plano}/{$plano->anio_plano})",
                        'estado'          => 'enviado',
                        'es_bot'          => true,
                    ]);

                    $conversacion->update(['ultimo_mensaje_at' => now()]);
                    broadcast(new WhatsappMensajeNuevo($mensaje, $conversacion));
                    broadcast(new WhatsappConversacionActualizada($conversacion));
                } catch (\Throwable $e) {
                    Log::warning('IA WhatsApp: planilla enviada pero falló el registro/notificación local', [
                        'error' => $e->getMessage(), 'plano_id' => $plano->id,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning('IA WhatsApp: error generando/enviando planilla', ['error' => $e->getMessage(), 'plano_id' => $plano->id]);
            }
        }

        if (empty($periodosEnviados)) {
            return ['encontrada' => true, 'enviada' => false, 'nota' => 'Encontré la planilla pero hubo un problema técnico al enviarla. Ofrece escalar con un asesor (hablar_con_asesor).'];
        }

        $referenciaPersona = $esPropia ? 'tu planilla' : "la planilla de {$nombreCliente}";

        return [
            'encontrada'        => true,
            'enviada'           => true,
            'es_propia'         => $esPropia,
            'nombre'            => $nombreCliente,
            'periodos_enviados' => $periodosEnviados,
            'nota'              => "El/los PDF ya se enviaron por este mismo WhatsApp (mensaje aparte), referidos "
                . "como \"{$referenciaPersona}\". Solo confírmalo brevemente usando esa misma referencia "
                . '(di "tu planilla" únicamente si es_propia=true; si es false, nombra a la persona en vez de '
                . 'decir "tu"), no repitas el contenido del documento.',
        ];
    }
}
