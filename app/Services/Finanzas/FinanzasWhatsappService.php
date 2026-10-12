<?php

namespace App\Services\Finanzas;

use App\Models\Finanzas\CuentaCorrienteCliente;
use App\Models\Finanzas\Prestamo;
use App\Models\WhatsappConfig;
use App\Models\WhatsappConversacion;
use App\Models\WhatsappMensaje;
use App\Services\WhatsappApiService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class FinanzasWhatsappService
{
    protected WhatsappApiService $apiService;

    public function __construct(WhatsappApiService $apiService)
    {
        $this->apiService = $apiService;
    }

    /**
     * Envía un recordatorio de cobro por WhatsApp a un deudor de préstamo.
     *
     * @return array ['ok' => bool, 'message' => string, 'error' => string|null]
     */
    public function enviarRecordatorioPrestamo(Prestamo $prestamo): array
    {
        $celular = $prestamo->telefono_deudor;

        if (! $celular) {
            return ['ok' => false, 'message' => 'El deudor no tiene un número de teléfono configurado.'];
        }

        // Normalizar número
        $numeroNormalizado = preg_replace('/[^0-9]/', '', $celular);
        if (strlen($numeroNormalizado) === 10) {
            $numeroNormalizado = '57'.$numeroNormalizado; // Prefijo Colombia por defecto si tiene 10 dígitos
        }

        // El dueño del préstamo manda sobre la sesión: así el envío programado, que
        // corre por consola y no tiene usuario autenticado, resuelve el mismo aliado
        // que resolvería el botón del panel.
        // Se resuelve por id y no con la relación `user()`: el belongsTo hereda la
        // conexión `finanzas` del préstamo, donde la tabla `users` no existe.
        $user = ($prestamo->user_id ? \App\Models\User::find($prestamo->user_id) : null) ?: Auth::user();

        if (! $user) {
            return ['ok' => false, 'message' => 'El préstamo no tiene un usuario dueño y no hay sesión activa para resolver el aliado.'];
        }

        $aliadoId = $user->aliado_id;
        $config = WhatsappConfig::paraAliado($aliadoId);

        if (! $config->credencialesCompletas()) {
            return ['ok' => false, 'message' => 'No hay credenciales de WhatsApp configuradas en el sistema para tu aliado.'];
        }

        $saldoFormateado = '$'.number_of_format_or_custom($prestamo->saldo_actual);

        // Qué se le dice al deudor depende de si ya hay algo vencido o no:
        //   - con intereses liquidados sin pagar  → cobro de lo vencido
        //   - al día                              → aviso previo del próximo corte
        // `dias_mora` no sirve para decidirlo: cuenta días desde el último abono,
        // así que marca "vencido" a quien está perfectamente al día.
        // `esta_vencido` aplica un piso en pesos: un residuo de redondeo (Angela Ortiz
        // quedó con $600 pendientes) no es una deuda y no debe disparar un cobro formal.
        $vencido = $prestamo->esta_vencido;

        if ($vencido) {
            $nombrePlantilla = 'recordatorio_prestamo';
            $dias = $prestamo->dias_vencidos;
            $valor = $prestamo->intereses_acumulados;
            $referencia = $saldoFormateado;
        } else {
            $nombrePlantilla = 'aviso_previo_prestamo';
            $dias = max(0, $prestamo->dias_para_corte);
            // Sin tasa no hay interés que anunciar: lo que se recuerda es el saldo.
            $valor = $prestamo->interes_ciclo > 0 ? $prestamo->interes_ciclo : $prestamo->saldo_actual;
            $referencia = $prestamo->fecha_corte->format('d/m/Y');
        }

        $valorFormateado = '$'.number_of_format_or_custom($valor);

        $diasTexto = $dias.($dias === 1 ? ' día' : ' días');

        // Texto libre de respaldo (sólo viaja si hay ventana de 24 h abierta)
        if ($vencido) {
            $texto = "Hola *{$prestamo->nombre_deudor}*, te recordamos que tu préstamo presenta un saldo pendiente de *{$saldoFormateado}*, con *{$diasTexto}* de vencimiento. "
                ."Te invitamos a realizar el pago correspondiente; si no te es posible abonar a capital, puedes efectuar un pago mínimo de *{$valorFormateado}* para cubrir los intereses. Gracias por tu atención.";
        } elseif ($dias > 0) {
            $texto = "Hola *{$prestamo->nombre_deudor}*, te recordamos que la fecha de corte de tu préstamo es el *{$referencia}*: ".($dias === 1 ? 'falta' : 'faltan')." *{$diasTexto}*. "
                ."El valor estimado a pagar es de *{$valorFormateado}*. Si deseas abonar a capital, puedes hacerlo antes de esa fecha. Gracias por tu puntualidad.";
        } else {
            $texto = "Hola *{$prestamo->nombre_deudor}*, te recordamos que *hoy* es la fecha de corte de tu préstamo. "
                ."El valor estimado a pagar es de *{$valorFormateado}*. Gracias por tu puntualidad.";
        }

        try {
            // Obtener o crear conversación en base de datos principal
            $conversacion = WhatsappConversacion::firstOrCreate(
                [
                    'aliado_id' => $aliadoId,
                    'wa_contact_id' => $numeroNormalizado,
                ],
                [
                    'nombre_contacto' => $prestamo->nombre_deudor,
                    'estado' => 'abierta',
                ]
            );

            $conversacionUpdates = [];
            if ($prestamo->user_id) {
                $conversacionUpdates['asignado_a'] = $prestamo->user_id;
                $conversacionUpdates['estado'] = 'asignada';
                $conversacionUpdates['bot_activo'] = false;
            } else {
                if ($conversacion->estado === 'cerrada') {
                    $conversacionUpdates['estado'] = 'abierta';
                }
            }

            if (! empty($conversacionUpdates)) {
                $conversacion->update($conversacionUpdates);
            }

            // Buscar la plantilla aprobada que corresponde al caso (cobro o aviso previo)
            $plantilla = \App\Models\WhatsappPlantilla::delAliado($aliadoId)
                ->aprobadas()
                ->where('nombre', $nombrePlantilla)
                ->first();

            $tipoMensaje = 'text';
            $contenidoMensaje = $texto;

            if ($plantilla) {
                // Plantillas de 4 variables. En el cobro: 1 nombre, 2 saldo pendiente,
                // 3 días vencidos, 4 interés mínimo. En el aviso previo: 1 nombre,
                // 2 fecha de corte, 3 días que faltan, 4 valor estimado del ciclo.
                $params = [
                    $prestamo->nombre_deudor,
                    $referencia,
                    (string) $dias,
                    $valorFormateado,
                ];

                $res = $this->apiService->enviarTemplate($numeroNormalizado, $plantilla, $params, $config);
                $tipoMensaje = 'template';

                // Mapear contenido del mensaje para guardar en BD
                $contenidoMensaje = $plantilla->cuerpo;
                foreach ($params as $index => $paramValue) {
                    $contenidoMensaje = str_replace('{{'.($index + 1).'}}', $paramValue, $contenidoMensaje);
                }
            } else {
                // Intentar enviar mensaje de texto libre
                $res = $this->apiService->enviarTexto($numeroNormalizado, $texto, $config);
            }

            if (! $res['ok']) {
                $msgError = 'Meta API rechazó el mensaje.';
                if (! $plantilla) {
                    $msgError .= " Para iniciar la conversación fuera de la ventana de 24 horas, debes crear y aprobar una plantilla llamada \"{$nombrePlantilla}\".";
                } else {
                    $msgError .= " Asegúrate de que las variables de la plantilla \"{$nombrePlantilla}\" coincidan.";
                }

                return [
                    'ok' => false,
                    'message' => $msgError,
                    'error' => $res['error'] ?? 'Desconocido',
                ];
            }

            // Registrar mensaje en la base de datos
            WhatsappMensaje::create([
                'conversacion_id' => $conversacion->id,
                'aliado_id' => $aliadoId,
                'wa_message_id' => $res['wa_message_id'] ?? 'fin_wa_'.uniqid(),
                'direccion' => 'saliente',
                'tipo' => $tipoMensaje,
                'contenido' => $contenidoMensaje,
                'estado' => 'enviado',
                'usuario_id' => $user->id,
            ]);

            $conversacion->update(['ultimo_mensaje_at' => now()]);

            return [
                'ok' => true,
                'message' => $vencido
                    ? "Cobro enviado con éxito por WhatsApp ({$diasTexto} de vencimiento)."
                    : ($dias > 0
                        ? "Recordatorio enviado con éxito por WhatsApp (faltan {$diasTexto} para el corte del {$referencia})."
                        : 'Recordatorio enviado con éxito por WhatsApp (el corte es hoy).'),
            ];

        } catch (\Exception $e) {
            Log::error('Error enviando recordatorio de préstamo por WhatsApp: '.$e->getMessage());

            return [
                'ok' => false,
                'message' => 'Ocurrió un error interno al intentar procesar el envío del mensaje.',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Envía el aviso de un préstamo que lleva Luca (Avappi). Desde el 11-oct-2026 los
     * préstamos de Brayan viven en Luca: Luca decide a quién le toca y con qué valores
     * (GET /api/luca/cobros) y aquí solo se manda, por la cuenta y con las plantillas
     * aprobadas del aliado, y queda en su bandeja como cualquier otro mensaje.
     *
     * @param  array  $m  Un mensaje de Luca: deudor, telefono, plantilla, variables (4), texto.
     * @return array ['ok' => bool, 'message' => string, 'error' => string|null]
     */
    public function enviarRecordatorioDesdeLuca(array $m, \App\Models\User $user): array
    {
        $numero = preg_replace('/[^0-9]/', '', (string) ($m['telefono'] ?? ''));
        if (strlen($numero) === 10) {
            $numero = '57'.$numero;
        }
        if (strlen($numero) < 11) {
            return ['ok' => false, 'message' => 'El deudor no tiene un celular válido.'];
        }
        $nombrePlantilla = in_array($m['plantilla'] ?? '', ['aviso_previo_prestamo', 'recordatorio_prestamo'], true) ? $m['plantilla'] : null;
        $params = array_map('strval', array_slice((array) ($m['variables'] ?? []), 0, 4));
        if (! $nombrePlantilla || count($params) !== 4) {
            return ['ok' => false, 'message' => 'El mensaje de Luca no trae la plantilla o sus 4 variables.'];
        }

        $aliadoId = $user->aliado_id;
        $config = WhatsappConfig::paraAliado($aliadoId);
        if (! $config->credencialesCompletas()) {
            return ['ok' => false, 'message' => 'No hay credenciales de WhatsApp configuradas para el aliado.'];
        }

        try {
            $conversacion = WhatsappConversacion::firstOrCreate(
                ['aliado_id' => $aliadoId, 'wa_contact_id' => $numero],
                ['nombre_contacto' => $m['deudor'] ?? $numero, 'estado' => 'abierta']
            );
            $conversacion->update(['asignado_a' => $user->id, 'estado' => 'asignada', 'bot_activo' => false]);

            $plantilla = \App\Models\WhatsappPlantilla::delAliado($aliadoId)->aprobadas()->where('nombre', $nombrePlantilla)->first();
            if ($plantilla) {
                $res = $this->apiService->enviarTemplate($numero, $plantilla, $params, $config);
                $tipoMensaje = 'template';
                $contenidoMensaje = $plantilla->cuerpo;
                foreach ($params as $i => $valor) {
                    $contenidoMensaje = str_replace('{{'.($i + 1).'}}', $valor, $contenidoMensaje);
                }
            } else {
                // Sin plantilla solo sale si hay ventana de 24 h abierta.
                $res = $this->apiService->enviarTexto($numero, (string) ($m['texto'] ?? ''), $config);
                $tipoMensaje = 'text';
                $contenidoMensaje = (string) ($m['texto'] ?? '');
            }

            if (! $res['ok']) {
                return [
                    'ok' => false,
                    'message' => 'Meta API rechazó el mensaje.'.($plantilla ? " Revisa las variables de «{$nombrePlantilla}»." : " Falta la plantilla aprobada «{$nombrePlantilla}»."),
                    'error' => $res['error'] ?? 'Desconocido',
                ];
            }

            WhatsappMensaje::create([
                'conversacion_id' => $conversacion->id,
                'aliado_id' => $aliadoId,
                'wa_message_id' => $res['wa_message_id'] ?? 'fin_wa_'.uniqid(),
                'direccion' => 'saliente',
                'tipo' => $tipoMensaje,
                'contenido' => $contenidoMensaje,
                'estado' => 'enviado',
                'usuario_id' => $user->id,
            ]);
            $conversacion->update(['ultimo_mensaje_at' => now()]);

            return ['ok' => true, 'message' => 'Enviado.'];
        } catch (\Exception $e) {
            Log::error('Error enviando aviso de préstamo de Luca por WhatsApp: '.$e->getMessage());

            return ['ok' => false, 'message' => 'Error interno al enviar.', 'error' => $e->getMessage()];
        }
    }

    /**
     * Envía UN solo recordatorio al cliente de cuenta corriente con el consolidado
     * de sus trabajos pendientes, en vez de un mensaje por trabajo.
     *
     * Reusa las plantillas ya aprobadas de préstamos: `recordatorio_prestamo`
     * cuando hay interés vencido y `aviso_previo_prestamo` cuando está al día.
     */
    public function enviarRecordatorioCuentaCorriente(CuentaCorrienteCliente $cliente): array
    {
        if (! $cliente->telefono) {
            return ['ok' => false, 'message' => 'El cliente no tiene un número de teléfono configurado.'];
        }

        $trabajos = $cliente->trabajosPendientes()->orderBy('fecha_desembolso')->get();

        if ($trabajos->isEmpty()) {
            return ['ok' => false, 'message' => 'El cliente no tiene trabajos pendientes de pago.'];
        }

        $numeroNormalizado = preg_replace('/[^0-9]/', '', $cliente->telefono);
        if (strlen($numeroNormalizado) === 10) {
            $numeroNormalizado = '57'.$numeroNormalizado;
        }

        $user = Auth::user();
        $aliadoId = $user->aliado_id;
        $config = WhatsappConfig::paraAliado($aliadoId);

        if (! $config->credencialesCompletas()) {
            return ['ok' => false, 'message' => 'No hay credenciales de WhatsApp configuradas en el sistema para tu aliado.'];
        }

        $saldoTotal = (float) $trabajos->sum('saldo_actual');
        $interesesTotal = (float) $trabajos->sum(fn ($t) => $t->intereses_acumulados);
        // Basta con que UN trabajo esté vencido para que el mensaje sea de cobro.
        $vencido = $trabajos->contains(fn ($t) => $t->esta_vencido);

        $saldoFormateado = '$'.number_of_format_or_custom($saldoTotal);

        if ($vencido) {
            $nombrePlantilla = 'recordatorio_prestamo';
            // El vencimiento que se anuncia es el del trabajo más atrasado.
            $dias = max(1, (int) $trabajos->max(fn ($t) => $t->dias_vencidos));
            $interesesTotal = (float) $trabajos->where('esta_vencido', true)->sum(fn ($t) => $t->intereses_acumulados);
            $referencia = $saldoFormateado;
            $valorFormateado = '$'.number_of_format_or_custom($interesesTotal);
        } else {
            $nombrePlantilla = 'aviso_previo_prestamo';
            // El corte que se anuncia es el más próximo de todos sus trabajos.
            $proximo = $trabajos->sortBy(fn ($t) => $t->fecha_corte->timestamp)->first();
            $dias = max(0, $proximo->dias_para_corte);
            $referencia = $proximo->fecha_corte->format('d/m/Y');
            $interesCiclo = (float) $trabajos->sum(fn ($t) => $t->interes_ciclo);
            $valorFormateado = '$'.number_of_format_or_custom($interesCiclo > 0 ? $interesCiclo : $saldoTotal);
        }

        $diasTexto = $dias.($dias === 1 ? ' día' : ' días');

        // Detalle trabajo por trabajo: solo viaja en el texto libre (ventana de 24 h),
        // porque las plantillas aprobadas tienen 4 variables fijas.
        $detalle = $trabajos
            ->map(fn ($t) => '• '.($t->descripcion ?: 'Trabajo').': $'.number_of_format_or_custom($t->saldo_actual))
            ->implode("\n");

        if ($vencido) {
            $texto = "Hola *{$cliente->nombre}*, te recordamos que tu cuenta presenta un saldo pendiente de *{$saldoFormateado}*, con *{$diasTexto}* de vencimiento.\n\n{$detalle}\n\n"
                ."Te invitamos a realizar el pago correspondiente; si no te es posible abonar a capital, puedes efectuar un pago mínimo de *{$valorFormateado}* para cubrir los intereses. Gracias por tu atención.";
        } elseif ($dias > 0) {
            $texto = "Hola *{$cliente->nombre}*, tu cuenta tiene un saldo de *{$saldoFormateado}*.\n\n{$detalle}\n\n"
                ."La próxima fecha de corte es el *{$referencia}*: ".($dias === 1 ? 'falta' : 'faltan')." *{$diasTexto}*. Si cancelas antes de esa fecha no se generan intereses. Gracias por tu puntualidad.";
        } else {
            $texto = "Hola *{$cliente->nombre}*, tu cuenta tiene un saldo de *{$saldoFormateado}* y *hoy* es fecha de corte.\n\n{$detalle}\n\nGracias por tu puntualidad.";
        }

        try {
            $conversacion = WhatsappConversacion::firstOrCreate(
                [
                    'aliado_id' => $aliadoId,
                    'wa_contact_id' => $numeroNormalizado,
                ],
                [
                    'nombre_contacto' => $cliente->nombre,
                    'estado' => 'abierta',
                ]
            );

            $conversacion->update([
                'asignado_a' => $cliente->user_id,
                'estado' => 'asignada',
                'bot_activo' => false,
            ]);

            $plantilla = \App\Models\WhatsappPlantilla::delAliado($aliadoId)
                ->aprobadas()
                ->where('nombre', $nombrePlantilla)
                ->first();

            $tipoMensaje = 'text';
            $contenidoMensaje = $texto;

            if ($plantilla) {
                $params = [
                    $cliente->nombre,
                    $referencia,
                    (string) $dias,
                    $valorFormateado,
                ];

                $res = $this->apiService->enviarTemplate($numeroNormalizado, $plantilla, $params, $config);
                $tipoMensaje = 'template';

                $contenidoMensaje = $plantilla->cuerpo;
                foreach ($params as $index => $paramValue) {
                    $contenidoMensaje = str_replace('{{'.($index + 1).'}}', $paramValue, $contenidoMensaje);
                }
            } else {
                $res = $this->apiService->enviarTexto($numeroNormalizado, $texto, $config);
            }

            if (! $res['ok']) {
                $msgError = 'Meta API rechazó el mensaje.';
                if (! $plantilla) {
                    $msgError .= " Para iniciar la conversación fuera de la ventana de 24 horas, debes crear y aprobar una plantilla llamada \"{$nombrePlantilla}\".";
                } else {
                    $msgError .= " Asegúrate de que las variables de la plantilla \"{$nombrePlantilla}\" coincidan.";
                }

                return ['ok' => false, 'message' => $msgError, 'error' => $res['error'] ?? 'Desconocido'];
            }

            WhatsappMensaje::create([
                'conversacion_id' => $conversacion->id,
                'aliado_id' => $aliadoId,
                'wa_message_id' => $res['wa_message_id'] ?? 'fin_wa_'.uniqid(),
                'direccion' => 'saliente',
                'tipo' => $tipoMensaje,
                'contenido' => $contenidoMensaje,
                'estado' => 'enviado',
                'usuario_id' => $user->id,
            ]);

            $conversacion->update(['ultimo_mensaje_at' => now()]);

            return [
                'ok' => true,
                'message' => $vencido
                    ? "Cobro consolidado enviado a {$cliente->nombre} ({$trabajos->count()} trabajo(s), {$diasTexto} de vencimiento)."
                    : "Recordatorio enviado a {$cliente->nombre} ({$trabajos->count()} trabajo(s) por \${$saldoFormateado}).",
            ];
        } catch (\Exception $e) {
            Log::error('Error enviando recordatorio de cuenta corriente por WhatsApp: '.$e->getMessage());

            return [
                'ok' => false,
                'message' => 'Ocurrió un error interno al intentar procesar el envío del mensaje.',
                'error' => $e->getMessage(),
            ];
        }
    }
}

/**
 * Función auxiliar para formatear números de moneda si no existe la global
 */
if (! function_exists('number_of_format_or_custom')) {
    function number_of_format_or_custom($value)
    {
        return number_format($value, 0, ',', '.');
    }
}
