<?php

namespace App\Jobs;

use App\Models\{
    ConsentimientoDato,
    WhatsappConfig, WhatsappConversacion,
    WhatsappEnvioMasivo, WhatsappEnvioMasivoDetalle,
    WhatsappMensaje, WhatsappPlantilla
};
use App\Services\Cumplimiento\VentanaContactoLey2300;
use App\Services\WhatsappApiService;
use App\Services\WhatsappVariablesCobro;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\Log;

/**
 * Job para procesar envíos masivos de WhatsApp en background.
 *
 * Procesa cada destinatario del lote, respetando los rate limits de Meta:
 *  - Máximo ~80 mensajes por segundo en tier 1
 *  - El job hace micro-sleeps entre envíos para no superar el límite
 *
 * El job se puede reintentar si falla parcialmente.
 */
class WhatsappEnvioMasivoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 3600; // 1 hora máximo para lotes grandes

    /** Cachea los datos del aliado entre destinatarios; se crea al procesar, no al despachar. */
    private ?WhatsappVariablesCobro $variablesCobro = null;

    public function __construct(
        protected int   $envioId,
        protected array $parametrosGlobales = [],
        protected ?string $headerImageUrl = null
    ) {}

    public function handle(WhatsappApiService $apiService): void
    {
        $envio = WhatsappEnvioMasivo::with(['plantilla', 'detalles'])->find($this->envioId);

        if (!$envio || $envio->estado === 'completado') return;

        // Marcar como procesando
        $envio->update(['estado' => 'procesando']);

        $config = WhatsappConfig::paraAliado($envio->aliado_id);

        if (!$config->credencialesCompletas()) {
            $envio->update(['estado' => 'fallido']);
            Log::error("WhatsApp masivo #{$this->envioId}: sin credenciales configuradas");
            return;
        }

        $plantilla = $envio->plantilla;

        // Una plantilla retirada del catálogo no se vuelve a enviar: se retiró por algo
        // (típicamente porque ya no existe en Meta y todo el lote rebota con #132001).
        if (!$plantilla || $plantilla->trashed()) {
            $envio->update(['estado' => 'fallido']);
            Log::error("WhatsApp masivo #{$this->envioId}: la plantilla #{$envio->plantilla_id} ya no está disponible");
            return;
        }

        // Qué reglas aplican según lo que ES este mensaje:
        //  - Ventana horaria (Ley 2300 art. 3): cubre publicidad Y cobranza. Un envío de
        //    planilla es entrega de un servicio que el cliente pidió, y no cae ahí.
        //  - Lista de baja: solo publicidad. Que alguien no quiera promociones no significa
        //    que deje de deberle a la empresa, así que el cobro sigue saliendo.
        $esMarketing = strtoupper((string) $plantilla->categoria) === 'MARKETING';
        $esCobranza  = str_contains(strtolower((string) $plantilla->nombre), 'cobro');

        // Fuera de la ventana no se cancela nada: se reprograma para la próxima hora hábil.
        // Se despacha un job nuevo en vez de release() para no gastar los reintentos, que
        // están para fallos reales de la API.
        if (($esMarketing || $esCobranza) && !VentanaContactoLey2300::permite()) {
            $espera = VentanaContactoLey2300::segundosHastaApertura();

            $envio->update(['estado' => 'pendiente']);
            self::dispatch($this->envioId, $this->parametrosGlobales, $this->headerImageUrl)
                ->delay(now()->addSeconds($espera));

            Log::info("WhatsApp masivo #{$this->envioId} reprogramado por Ley 2300", [
                'motivo'          => VentanaContactoLey2300::motivoBloqueo(),
                'proxima_ventana' => VentanaContactoLey2300::proximaApertura()->format('Y-m-d H:i'),
            ]);

            return;
        }

        $enviados  = 0;
        $fallidos  = 0;
        $omitidos  = 0;

        // URL pública de imagen del header (prioriza la enviada desde el controlador, fallback a asset local)
        $headerImageUrl = $this->headerImageUrl;
        if (!$headerImageUrl && $config->cobro_header_imagen) {
            $headerImageUrl = asset('storage/' . $config->cobro_header_imagen);
        }

        // Procesar solo los pendientes (permite reanudar si el job falla a mitad)
        $pendientes = $envio->detalles()->where('estado', 'pendiente')->get();

        // Quiénes pidieron la baja. Se resuelve de una sola vez para todo el lote: con la
        // latencia al SQL Server, preguntar por destinatario agregaría minutos a una tanda
        // grande. Cubre tanto el botón "No me interesa" como la herramienta del Asistente IA.
        $excluidos = [];
        if ($esMarketing) {
            $excluidos = array_flip(ConsentimientoDato::filtrarContactables(
                $envio->aliado_id,
                $pendientes->pluck('wa_numero')->all()
            )['excluidos']);
        }

        foreach ($pendientes as $detalle) {
            // Baja pedida por el cliente: no se le manda publicidad nunca más.
            if ($esMarketing && isset($excluidos[ConsentimientoDato::normalizarTelefono($detalle->wa_numero)])) {
                $detalle->update([
                    'estado' => 'omitido',
                    'error'  => 'Excluido: el cliente pidió no recibir publicidad.',
                ]);
                $omitidos++;
                continue;
            }

            try {
                // Construir parámetros para esta plantilla
                $params = $this->construirParametros($plantilla, $detalle, $this->parametrosGlobales);

                // Obtener o crear la conversación para este destinatario
                $conversacion = $this->obtenerOCrearConversacion($detalle, $envio, $plantilla);

                // Enviar el template (con imagen del header si aplica)
                $resultado = $apiService->enviarTemplate(
                    $detalle->wa_numero,
                    $plantilla,
                    $params,
                    $config,
                    $headerImageUrl
                );

                if ($resultado['ok']) {
                    // Guardar el mensaje en la conversación
                    WhatsappMensaje::create([
                        'conversacion_id'      => $conversacion->id,
                        'aliado_id'            => $envio->aliado_id,
                        'wa_message_id'        => $resultado['wa_message_id'],
                        'direccion'            => 'saliente',
                        'tipo'                 => 'template',
                        'plantilla_id'         => $plantilla->id,
                        'plantilla_parametros' => $params,
                        // El texto tal como le llegó: sin esto el chat del panel muestra el
                        // mensaje vacío y quien atiende la respuesta no ve a qué contestan.
                        'contenido'            => $plantilla->cuerpoRenderizado($params),
                        'estado'               => 'enviado',
                        'usuario_id'           => $envio->usuario_id,
                    ]);

                    $conversacion->update(['ultimo_mensaje_at' => now()]);

                    $detalle->update([
                        'estado'        => 'enviado',
                        'wa_message_id' => $resultado['wa_message_id'],
                    ]);

                    $enviados++;
                } else {
                    $detalle->update([
                        'estado' => 'fallido',
                        'error'  => $resultado['error'] ?? 'Error desconocido',
                    ]);
                    $fallidos++;
                }
            } catch (\Exception $e) {
                $detalle->update([
                    'estado' => 'fallido',
                    'error'  => $e->getMessage(),
                ]);
                $fallidos++;
                Log::error("WhatsApp masivo: error en destinatario {$detalle->wa_numero}", [
                    'error' => $e->getMessage(),
                ]);
            }

            // Rate limiting: ~12 envíos/segundo es seguro para tier 1
            // Para lotes grandes pausamos cada 10 mensajes
            if (($enviados + $fallidos) % 10 === 0) {
                usleep(800000); // 0.8 segundos cada 10 mensajes
            }
        }

        // Marcar el envío como completado
        $envio->update([
            'estado'          => 'completado',
            'total_enviados'  => $enviados,
            'total_fallidos'  => $fallidos,
            'total_omitidos'  => $omitidos,
        ]);

        Log::info("WhatsApp masivo #{$this->envioId} completado", [
            'enviados' => $enviados,
            'fallidos' => $fallidos,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        WhatsappEnvioMasivo::find($this->envioId)?->update(['estado' => 'fallido']);
        Log::error("WhatsApp masivo #{$this->envioId}: job fallido", ['error' => $e->getMessage()]);
    }

    private function construirParametros(
        WhatsappPlantilla $plantilla,
        WhatsappEnvioMasivoDetalle $detalle,
        array $parametrosGlobales
    ): array {
        // Si hay parámetros globales definidos por el usuario, usarlos directamente
        if (!empty($parametrosGlobales)) {
            return array_values($parametrosGlobales);
        }

        // Plantilla con variables con nombre (ej. aviso_retiro_eps): se llena desde su mapa
        // con los datos del aliado que envía, no con los moldes por posición de abajo.
        if (WhatsappVariablesCobro::aplica($plantilla)) {
            $nombre = $detalle->nombre_destinatario;
            if ($detalle->empresa_id) {
                $nombre = \App\Models\Empresa::where('aliado_id', $detalle->envio->aliado_id)
                    ->find($detalle->empresa_id)?->contacto ?: $nombre;
            }

            $this->variablesCobro ??= new WhatsappVariablesCobro();

            return $this->variablesCobro->parametros(
                $plantilla,
                (int) $detalle->envio->aliado_id,
                (string) $nombre,
                $detalle->valor_cobro !== null ? (float) $detalle->valor_cobro : null
            );
        }

        // Plantilla de UNA variable: es el nombre de quien recibe y nada más. Lo de abajo está
        // construido para las de cobro, que llevan valor, cuentas, plazo y soporte; caer ahí
        // con una sola variable devuelve un arreglo que no encaja y Meta rechaza el envío.
        if ($plantilla->cantidadVariables() === 1) {
            return [$detalle->nombre_destinatario ?: 'Hola'];
        }

        // Determinar si es un envío a empresa o si hay un contrato primario
        if ($detalle->empresa_id) {
            $empresa = \App\Models\Empresa::find($detalle->empresa_id);
            if ($empresa) {
                $nombreContacto = $empresa->contacto ?: $detalle->nombre_destinatario;
                $nombreAliado  = $empresa->aliado?->nombre ?? 'BryNex';
                
                // Obtener el día hábil configurado por el aliado
                $cfgAliado = \Illuminate\Support\Facades\DB::table('configuracion_aliado')
                    ->where('aliado_id', $empresa->aliado_id ?: ($detalle->envio?->aliado_id ?: session('aliado_id_activo')))
                    ->whereNull('plan_id')
                    ->first(['mora_dia_habil_inicio']);
                $plazoDias = $cfgAliado?->mora_dia_habil_inicio ? (string)$cfgAliado->mora_dia_habil_inicio : '10';

                // Obtener cuentas para cobro
                $cuentasText = \App\Models\BancoCuenta::textoCobro($empresa->aliado_id ?: ($detalle->envio?->aliado_id ?: session('aliado_id_activo')));

                // Celular de soporte
                $configAliado   = \App\Models\WhatsappConfig::where('aliado_id', $empresa->aliado_id ?: ($detalle->envio?->aliado_id ?: session('aliado_id_activo')))->first();
                $celularSoporte = $configAliado?->numero_telefono ?: 'no tiene configurado';

                $valorCobro = $detalle->valor_cobro !== null ? (float) $detalle->valor_cobro : 0.0;
                $valorFormateado = '$' . number_format($valorCobro, 0, ',', '.');

                $cantVars = $plantilla->cantidadVariables();
                if ($plantilla->usaMoldeCorto()) {
                    if ($cantVars === 3) {
                        return [$nombreContacto, $nombreAliado, $valorFormateado];
                    } else {
                        return [$nombreContacto, $nombreAliado];
                    }
                } else {
                    if ($cantVars <= 5) {
                        return [$nombreContacto, $nombreAliado, $plazoDias, $cuentasText, $celularSoporte];
                    } else {
                        return [$nombreContacto, $nombreAliado, $plazoDias, $cuentasText, $celularSoporte, $valorFormateado];
                    }
                }
            }
        }

        $contratoIdPrimario = $detalle->contratoIdPrimario();

        if ($contratoIdPrimario) {
            $contrato = \App\Models\Contrato::with(['cliente', 'razonSocial', 'plan', 'tipoModalidad', 'aliado'])
                ->find($contratoIdPrimario);

            if ($contrato) {
                $nombreCliente = $contrato->cliente?->nombre_corto ?? $detalle->nombre_destinatario;
                $nombreAliado  = $contrato->aliado?->nombre ?? 'BryNex';
                
                // Obtener el día hábil configurado por el aliado
                $cfgAliado = \Illuminate\Support\Facades\DB::table('configuracion_aliado')
                    ->where('aliado_id', $contrato->aliado_id)
                    ->whereNull('plan_id')
                    ->first(['mora_dia_habil_inicio']);
                $plazoDias = $cfgAliado?->mora_dia_habil_inicio ? (string)$cfgAliado->mora_dia_habil_inicio : '10';

                // Obtener cuentas para cobro
                $cuentasText = \App\Models\BancoCuenta::textoCobro($contrato->aliado_id);

                // Celular de soporte
                $configAliado   = \App\Models\WhatsappConfig::where('aliado_id', $contrato->aliado_id)->first();
                $celularSoporte = $configAliado?->numero_telefono ?: 'no tiene configurado';

                // Usar valor_cobro pre-calculado si está disponible (evita recalcular)
                $valorCobro = $detalle->valor_cobro !== null
                    ? (float) $detalle->valor_cobro
                    : null;

                // Fallback: calcular desde factura o estimado
                if ($valorCobro === null) {
                    $envio   = $detalle->envio;
                    $factura = \App\Models\Factura::where('aliado_id', $contrato->aliado_id)
                        ->where('contrato_id', $contrato->id)
                        ->periodo($envio->mes ?? now()->month, $envio->anio ?? now()->year)
                        ->whereIn('tipo', ['planilla', 'afiliacion'])
                        ->whereNull('deleted_at')
                        ->first();

                    $valorCobro = $factura ? $factura->total : null;

                    if ($valorCobro === null) {
                        $mesEnvio = $envio->mes ?? now()->month;
                        $anioEnvio = $envio->anio ?? now()->year;

                        // Determinar si es afiliación o ingreso-retiro alertado
                        $esAfil = false;
                        $esIndActPrimerMes = false;
                        if ($contrato->fecha_ingreso) {
                            $fIng = $contrato->fecha_ingreso;
                            $esIndAct = (bool) ($contrato->paga_mes_actual ?? false);
                            if ((int)$fIng->month === $mesEnvio && (int)$fIng->year === $anioEnvio) {
                                $esIndActPrimerMes = $esIndAct;
                                $esAfil = !$esIndAct;
                            }
                        }

                        $esIngresoRetiro = (int)($contrato->tipo_modalidad_id) === 12;
                        $diasCotizEstim = 30;
                        if ($esIngresoRetiro && !$esAfil && !$esIndActPrimerMes && $contrato->fecha_ingreso) {
                            $fIng2 = $contrato->fecha_ingreso;
                            $mesAnt = $mesEnvio === 1 ? 12 : $mesEnvio - 1;
                            $anioAnt = $mesEnvio === 1 ? $anioEnvio - 1 : $anioEnvio;
                            if ((int)$fIng2->month === $mesAnt && (int)$fIng2->year === $anioAnt) {
                                $diasCotizEstim = max(1, 30 - $fIng2->day + 1);
                            }
                        }
                        $esIrAlerta = $esIngresoRetiro && !$esAfil && !$esIndActPrimerMes && ($diasCotizEstim > 5);

                        if ($esIrAlerta) {
                            $valorCobro = (float)($contrato->costo_afiliacion ?? 0);
                        } elseif ($esAfil) {
                            $valorCobro = (float)(($contrato->costo_afiliacion ?? 0) + ($contrato->seguro ?? 0));
                        } else {
                            $esIndep = $contrato->tipoModalidad?->esIndependiente() ?? false;
                            $ibc     = (float)($contrato->salario ?? 0);
                            $plan    = $contrato->plan;
                            $r100    = fn($v) => (int)(ceil(($v ?? 0) / 100) * 100);
                            $pctSS   = $esIndep ? 28.5 : 29.5;
                            $vSS     = ($plan?->incluye_eps || $plan?->incluye_arl || $plan?->incluye_pension || $plan?->incluye_caja)
                                ? $r100($ibc * $pctSS / 100)
                                : 0;
                            $valorCobro = $vSS + (int)($contrato->administracion ?? 0) + (int)($contrato->seguro ?? 0);
                        }
                        // Estimar mora en lote (fallback)
                        $moraLoteInput = [[
                            '_contrato_id' => $contrato->id,
                            'rs_nit'       => $contrato->nitParaMora(),
                            'rs_dia_habil' => $contrato->diaHabilParaMora(),
                            'total_ss'     => ($esAfil || $esIrAlerta) ? 0 : $vSS,
                            'mes'          => $mesEnvio,
                            'anio'         => $anioEnvio,
                        ]];
                        $moraLoteOutput = \App\Services\MoraClienteService::calcularLote($contrato->aliado_id, $moraLoteInput);
                        $moraEst = isset($moraLoteOutput[0]) ? (int)($moraLoteOutput[0]['mora'] ?? 0) : 0;
                        
                        $valorCobro = $valorCobro + $moraEst;
                    }
                }

                $valorFormateado = '$' . number_format($valorCobro, 0, ',', '.');

                // Determinar el número de variables de la plantilla (5 o 6)
                $cantVars = $plantilla->cantidadVariables();
                if ($plantilla->usaMoldeCorto()) {
                    if ($cantVars === 3) {
                        return [$nombreCliente, $nombreAliado, $valorFormateado];
                    } else {
                        return [$nombreCliente, $nombreAliado];
                    }
                } else {
                    if ($cantVars <= 5) {
                        // Cobro sin variable de valor
                        return [$nombreCliente, $nombreAliado, $plazoDias, $cuentasText, $celularSoporte];
                    } else {
                        // Cobro con variable de valor
                        return [$nombreCliente, $nombreAliado, $plazoDias, $cuentasText, $celularSoporte, $valorFormateado];
                    }
                }
            }
        }

        // Auto-generar parámetros desde el mapa de variables de la plantilla
        $mapa = $plantilla->variables_mapa ?? [];
        if (empty($mapa)) return [];

        $params = [];
        foreach ($mapa as $campo) {
            $params[] = $this->resolverCampo($campo, $detalle);
        }

        return $params;
    }

    private function resolverCampo(string $campo, WhatsappEnvioMasivoDetalle $detalle): string
    {
        return match($campo) {
            'cliente.nombre'              => $detalle->nombre_destinatario,
            'factura.total'               => '',
            'factura.fecha_vencimiento'   => '',
            default                       => '',
        };
    }

    /**
     * Conversación donde queda registrado este envío, con el contexto de por qué se le
     * escribió: qué plantilla recibió, de qué campaña viene y a qué empresa/contrato
     * corresponde el destinatario.
     *
     * El origen se escribe SIEMPRE, también en conversaciones que ya existían: antes solo
     * lo hacía WhatsappWebhookService al CREAR una conversación, y como este job crea la
     * conversación al mandar la plantilla, esa rama ya nunca se ejecutaba — el contacto
     * respondía al recordatorio de cobro y el Asistente IA lo recibía sin ningún contexto,
     * como un prospecto frío al que había que venderle una afiliación.
     */
    private function obtenerOCrearConversacion(
        WhatsappEnvioMasivoDetalle $detalle,
        WhatsappEnvioMasivo $envio,
        WhatsappPlantilla $plantilla
    ): WhatsappConversacion {
        $origen = [
            'origen_campana'            => $plantilla->nombre_display ?: $plantilla->nombre,
            'origen_campana_categoria'  => $plantilla->categoria,
            'origen_campana_id'         => $envio->campana_id,
        ];

        // Buscar conversación existente por aliado + número (sin importar el estado)
        $conversacion = WhatsappConversacion::where('aliado_id', $envio->aliado_id)
            ->where('wa_contact_id', $detalle->wa_numero)
            ->orderByDesc('updated_at')
            ->first();

        if ($conversacion) {
            // La atribución a una campaña de marketing no se pisa con un envío que no viene
            // de ninguna (ej. un cobro): esa atribución es la que alimenta las métricas de
            // MarketingCampana. Para el contexto del Asistente IA no hace falta pisarla —
            // él lee la última plantilla enviada directo de los mensajes de la conversación.
            $cambios = (!$conversacion->origen_campana_id || $envio->campana_id) ? $origen : [];

            // Re-abrir si estaba cerrada
            if ($conversacion->estado === 'cerrada') {
                $cambios['estado'] = 'abierta';
            }
            // El vínculo con empresa/contrato solo se completa si falta: lo que ya esté
            // asignado (a mano o por el webhook) manda sobre lo que traiga este envío.
            if (!$conversacion->empresa_id && $detalle->empresa_id) {
                $cambios['empresa_id'] = $detalle->empresa_id;
            }
            if (!$conversacion->contrato_id && $detalle->contrato_id) {
                $cambios['contrato_id'] = $detalle->contrato_id;
            }

            $conversacion->update($cambios);

            return $conversacion;
        }

        // Crear nueva conversación vinculada al aliado
        return WhatsappConversacion::create(array_merge($origen, [
            'aliado_id'             => $envio->aliado_id,
            'wa_contact_id'         => $detalle->wa_numero,
            'nombre_contacto'       => $detalle->nombre_destinatario,
            'empresa_id'            => $detalle->empresa_id,
            'contrato_id'           => $detalle->contrato_id,
            'estado'                => 'abierta',
            'ultimo_mensaje_at'     => now(),
        ]));
    }
}
