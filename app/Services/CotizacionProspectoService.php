<?php

namespace App\Services;

use App\Models\Aliado;
use App\Models\CotizacionGestion;
use App\Models\CotizacionProspecto;
use App\Models\WhatsappConfig;
use App\Models\WhatsappConversacion;
use App\Models\WhatsappMensaje;
use Illuminate\Support\Facades\Storage;

/**
 * Lo que se hace con una cotización ya guardada: el resultado con el que se
 * presenta, el PDF y el envío por WhatsApp.
 */
class CotizacionProspectoService
{
    /**
     * Resultado de la cotización tal como se mostró al guardarla. Si el prospecto
     * no trae el desglose (los que registra el asistente de IA solo dejan el valor
     * mensual) se recalcula con los mismos parámetros del cotizador del panel.
     *
     * @return array{completo: array, proporcional: array, dias: int, costo_afiliacion: float, administracion: float, trabajadores: array}
     */
    public function resultado(CotizacionProspecto $prospecto): array
    {
        $guardado = $prospecto->resultado_cotizacion ?: [];
        $dias = (int) ($guardado['dias_proporcionales'] ?? 30);
        $costo = (float) ($guardado['costo_afiliacion'] ?? $prospecto->costo_afiliacion ?? 0);
        $admon = (float) ($guardado['administracion'] ?? $prospecto->administracion ?? 0);

        if ($prospecto->esEmpresa()) {
            $trabajadores = $prospecto->trabajadores->map(function ($t) use ($prospecto, $admon, $dias) {
                $r = $t->resultado ?: [];
                if (! isset($r['completo']['total'])) {
                    $r = $this->calcular($prospecto->aliado_id, $t->modalidad_id, $t->plan_id, $t->salario, $t->n_arl, $admon, $dias);
                }

                return [
                    'cargo' => $t->cargo,
                    'nombre' => $t->nombre,
                    'plan' => $t->plan->nombre ?? '',
                    'modalidad' => $t->modalidad->observacion ?? $t->modalidad->tipo_modalidad ?? '',
                    'salario' => (float) $t->salario,
                    'n_arl' => (int) $t->n_arl,
                    'completo' => $r['completo'] ?? [],
                    'proporcional' => $r['proporcional'] ?? ($r['completo'] ?? []),
                ];
            })->values()->all();

            $sumar = function (string $campo) use ($trabajadores) {
                $total = ['eps' => 0, 'pen' => 0, 'arl' => 0, 'caja' => 0, 'admon' => 0, 'seguro' => 0, 'iva' => 0, 'total' => 0];
                foreach ($trabajadores as $t) {
                    foreach ($total as $k => $v) {
                        $total[$k] += (float) ($t[$campo][$k] ?? 0);
                    }
                }

                return $total;
            };

            return [
                'completo' => $sumar('completo'),
                'proporcional' => $sumar('proporcional'),
                'dias' => $dias,
                'costo_afiliacion' => $costo,
                'administracion' => $admon,
                'trabajadores' => $trabajadores,
            ];
        }

        if (! isset($guardado['completo']['total'])) {
            $guardado = $this->calcular(
                $prospecto->aliado_id, $prospecto->modalidad_id, $prospecto->plan_id,
                (float) $prospecto->salario_base, (int) ($prospecto->n_arl ?: 1), $admon, $dias
            );
        }

        return [
            'completo' => $guardado['completo'] ?? [],
            'proporcional' => $guardado['proporcional'] ?? ($guardado['completo'] ?? []),
            'dias' => $dias,
            'costo_afiliacion' => $costo,
            'administracion' => $admon,
            'trabajadores' => [],
        ];
    }

    /** Mismo cálculo que hace el formulario: mes completo y, si aplica, el proporcional. */
    private function calcular(int $aliadoId, $modalidadId, $planId, float $salario, int $nArl, float $admon, int $dias): array
    {
        if ($modalidadId === null || ! $planId || $salario <= 0) {
            return ['completo' => [], 'proporcional' => []];
        }

        $base = [
            'tipo_modalidad_id' => $modalidadId,
            'plan_id' => $planId,
            'salario' => $salario,
            'ibc' => $salario,
            'n_arl' => $nArl,
            'administracion' => $admon,
        ];
        $completo = CotizadorService::calcular($base + ['dias' => 30], $aliadoId);
        $proporcional = $dias < 30 ? CotizadorService::calcular($base + ['dias' => $dias], $aliadoId) : $completo;

        return ['completo' => $completo, 'proporcional' => $proporcional];
    }

    public function pdf(CotizacionProspecto $prospecto)
    {
        $prospecto->loadMissing(['modalidad', 'plan', 'trabajadores.plan', 'trabajadores.modalidad']);
        $aliado = Aliado::find($prospecto->aliado_id);
        $resultado = $this->resultado($prospecto);

        $pdf = \PDF::loadView('pdf.cotizacion_prospecto', compact('prospecto', 'resultado', 'aliado'));
        app(TrazaArchivoService::class)->marcarPdf($pdf);

        return $pdf;
    }

    public function nombreArchivoPdf(CotizacionProspecto $prospecto): string
    {
        $base = $prospecto->esEmpresa() ? ($prospecto->empresa_nombre ?: 'empresa') : ($prospecto->cedula ?: $prospecto->nombre_completo ?: $prospecto->id);
        $base = preg_replace('/[^A-Za-z0-9]+/', '_', \Illuminate\Support\Str::ascii($base));

        return 'Cotizacion_'.trim($base, '_').'.pdf';
    }

    /** Texto con el que se manda la cotización por WhatsApp. */
    public function mensajeWhatsapp(CotizacionProspecto $prospecto): string
    {
        $aliado = Aliado::find($prospecto->aliado_id);
        $r = $this->resultado($prospecto);
        $pesos = fn ($v) => '$'.number_format((float) $v, 0, ',', '.');
        $contacto = $prospecto->nombre_completo ? ' '.nombre_oracion(explode(' ', trim($prospecto->nombre_completo))[0]) : '';

        $lineas = ["Hola{$contacto}, le comparto la cotización de seguridad social de *".($aliado->nombre ?? 'BryNex').'*:', ''];

        if ($prospecto->esEmpresa()) {
            $lineas[] = '*'.$prospecto->empresa_nombre.'* — '.count($r['trabajadores']).' '.(count($r['trabajadores']) === 1 ? 'trabajador' : 'trabajadores');
            foreach ($r['trabajadores'] as $t) {
                $lineas[] = '• '.$t['cargo'].($t['plan'] ? ' ('.$t['plan'].')' : '').': '.$pesos($t['completo']['total'] ?? 0).' al mes';
            }
            $lineas[] = '';
            $lineas[] = '*Total mensual:* '.$pesos($r['completo']['total'] ?? 0);
            $afiliacion = $r['costo_afiliacion'] * max(1, count($r['trabajadores']));
        } else {
            $plan = $prospecto->plan->nombre ?? '';
            $lineas[] = '*Plan:* '.($plan ?: 'según lo conversado').' · IBC '.$pesos($prospecto->salario_base);
            $c = $r['completo'];
            foreach (['eps' => 'Salud', 'pen' => 'Pensión', 'arl' => 'ARL', 'caja' => 'Caja'] as $k => $nombre) {
                if (($c[$k] ?? 0) > 0) {
                    $lineas[] = '• '.$nombre.': '.$pesos($c[$k]);
                }
            }
            if (($c['admon'] ?? 0) > 0) {
                $lineas[] = '• Administración: '.$pesos($c['admon']);
            }
            $lineas[] = '';
            $lineas[] = '*Mensual:* '.$pesos($c['total'] ?? 0);
            $afiliacion = $r['costo_afiliacion'];
        }

        if ($r['dias'] < 30 && ($r['proporcional']['total'] ?? 0) > 0) {
            $lineas[] = '*Primer mes ('.$r['dias'].' días):* '.$pesos($r['proporcional']['total']);
        }
        if ($afiliacion > 0) {
            $lineas[] = '*Afiliación (pago único):* '.$pesos($afiliacion);
        }
        $lineas[] = '';
        $lineas[] = 'Quedo atento a cualquier duda. ¡Gracias!';

        return implode("\n", $lineas);
    }

    /**
     * Manda el PDF con el texto por la API de WhatsApp del aliado. Solo sale si la
     * conversación con ese número tiene la ventana de 24 h abierta: no hay una
     * plantilla aprobada para cotizaciones. Si no se puede, dice por qué.
     *
     * @return array{ok: bool, mensaje: string}
     */
    public function enviarWhatsapp(CotizacionProspecto $prospecto, string $texto, ?int $userId): array
    {
        $aliadoId = (int) $prospecto->aliado_id;
        $celular = (string) $prospecto->celular;

        if (! WhatsappApiService::esCelularColombiano($celular)) {
            return ['ok' => false, 'mensaje' => 'El celular del prospecto no es válido para WhatsApp.'];
        }

        $config = WhatsappConfig::paraAliado($aliadoId);
        if (! $config->activo || ! $config->credencialesCompletas()) {
            return ['ok' => false, 'mensaje' => 'El aliado no tiene WhatsApp configurado.'];
        }

        $numero = WhatsappApiService::normalizarNumero($celular);
        $conversacion = WhatsappConversacion::where('aliado_id', $aliadoId)
            ->where('wa_contact_id', $numero)
            ->first();

        if (! $conversacion || ! $conversacion->ventanaActiva()) {
            return ['ok' => false, 'mensaje' => 'Ese número no tiene una conversación abierta en las últimas 24 horas: envíela con el botón «Abrir en WhatsApp» y adjunte el PDF.'];
        }

        $archivo = $this->nombreArchivoPdf($prospecto);
        $ruta = "whatsapp/cotizaciones/{$aliadoId}/".uniqid().'_'.$archivo;
        Storage::disk('local')->put($ruta, $this->pdf($prospecto)->output());

        try {
            $api = app(WhatsappApiService::class);
            $resultado = $api->enviarMedia($numero, 'document', $ruta, 'application/pdf', $archivo, $config, $texto);
        } finally {
            Storage::disk('local')->delete($ruta);
        }

        if (! ($resultado['ok'] ?? false)) {
            return ['ok' => false, 'mensaje' => 'WhatsApp no aceptó el envío: '.($resultado['error'] ?? 'error desconocido')];
        }

        $conversacion->update(['ultima_actividad' => now()]);
        WhatsappMensaje::create([
            'conversacion_id' => $conversacion->id,
            'aliado_id' => $aliadoId,
            'wa_message_id' => $resultado['wa_message_id'] ?? null,
            'direccion' => 'saliente',
            'tipo' => 'document',
            'contenido' => $texto,
            'media_nombre' => $archivo,
            'estado' => 'enviado',
            'estado_at' => now(),
            'usuario_id' => $userId,
        ]);

        CotizacionGestion::create([
            'cotizacion_id' => $prospecto->id,
            'user_id' => $userId,
            'tipo_gestion' => 'WhatsApp',
            'descripcion' => 'Cotización enviada por WhatsApp con el PDF adjunto.',
            'resultado' => $prospecto->estado,
            'proxima_llamada' => null,
        ]);

        return ['ok' => true, 'mensaje' => 'Cotización enviada por WhatsApp.'];
    }
}
