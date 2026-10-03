<?php

namespace App\Services;

use App\Models\WhatsappConfig;
use App\Models\WhatsappConversacion;
use App\Models\WhatsappPlantilla;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Avisos operativos a BryNex por WhatsApp: backups caídos, accesos raros,
 * usuarios creados por un aliado, despliegues.
 *
 * Sale por la cuenta de Brygar (salvo enviarDesdeAliado(), que usa la del aliado que
 * se le indique). Si el destinatario tiene abierta la ventana de
 * 24h (escribió o tocó «Mantener activo» hace menos de un día), va como texto
 * libre, que no gasta envíos de plantilla. Si no, va con la plantilla aprobada
 * `notificar_brynex` (dos variables: origen y mensaje), que sale aunque la
 * ventana esté cerrada — una alerta tiene que salir siempre. Es la misma regla
 * de ReenvioAlDuenoService.
 *
 * Esta clase la comparten el comando `whatsapp:alerta-backup` y las alertas de
 * seguridad, para que el número, la plantilla y el saneado vivan en un solo sitio.
 */
class AlertaOperativaService
{
    private const ALIADO_ID = 2;                 // Brygar

    public const NOMBRE_PLANTILLA = 'notificar_brynex';

    /** Tope de Meta por variable: 1024. Se recorta muy por debajo. */
    private const MAX_PARAM = 600;

    /** El texto libre admite 4096; una alerta se lee en la notificación. */
    private const MAX_TEXTO = 1500;

    public function __construct(private WhatsappApiService $whatsappApi) {}

    public function numeroDestino(): string
    {
        return (string) config('services.whatsapp.alertas_numero', '3117762689');
    }

    /**
     * Envía la alerta. Devuelve false y deja rastro en el log si no pudo:
     * un aviso que no sale nunca debe tumbar la operación que lo disparó.
     */
    public function enviar(string $origen, string $mensaje): bool
    {
        return $this->enviarA($this->numeroDestino(), $origen, $mensaje);
    }

    /**
     * Igual que enviar(), pero a un número concreto en vez del de guardia.
     *
     * Lo usa el módulo de razones sociales para avisarle los vencimientos al
     * contador asignado a cada una, que no es quien recibe las alertas de
     * infraestructura.
     */
    public function enviarA(string $numero, string $origen, string $mensaje): bool
    {
        return $this->enviarDesdeAliado(self::ALIADO_ID, $numero, $origen, $mensaje);
    }

    /**
     * Igual que enviarA(), pero sale por la cuenta de WhatsApp con la que ese aliado
     * hace sus envíos: la suya si tiene número propio, o la compartida de BryNex.
     *
     * Lo usa el aviso de conversaciones esperando respuesta: es un mensaje del aliado
     * para su propia gente, y llegarle desde la línea de Brygar —otro aliado— no tiene
     * sentido. La plantilla tiene que existir aprobada en esa cuenta
     * (`whatsapp:plantilla-aviso` la crea); si no, solo sale con la ventana abierta.
     */
    public function enviarDesdeAliado(int $aliadoId, string $numero, string $origen, string $mensaje): bool
    {
        try {
            $config = WhatsappConfig::paraAliado($aliadoId);

            if (! $config->credencialesCompletas()) {
                Log::error('AlertaOperativa: credenciales de WhatsApp incompletas', ['aliado_id' => $aliadoId]);

                return false;
            }

            if ($this->ventanaAbierta($numero, $aliadoId, $config)) {
                $envio = $this->whatsappApi->enviarTexto(
                    $numero,
                    '🔔 *'.$this->sanear($origen)."*\n".Str::limit(trim($mensaje), self::MAX_TEXTO, '…'),
                    $config
                );

                if ($envio['ok'] ?? false) {
                    return true;
                }

                // La ventana pudo cerrarse entre la consulta y el envío: se cae a la plantilla.
                Log::warning('AlertaOperativa: el texto libre no salió, se intenta con plantilla', [
                    'origen' => $origen,
                    'error' => $envio['error'] ?? null,
                ]);
            }

            $plantilla = $this->plantilla($aliadoId, $config);

            if (! $plantilla) {
                Log::error('AlertaOperativa: plantilla no encontrada', [
                    'plantilla' => self::NOMBRE_PLANTILLA,
                    'aliado_id' => $aliadoId,
                ]);

                return false;
            }

            $envio = $this->whatsappApi->enviarTemplate(
                $numero,
                $plantilla,
                [$this->sanear($origen), $this->sanear($mensaje)],
                $config
            );

            if (! ($envio['ok'] ?? false)) {
                Log::error('AlertaOperativa: falló el envío', [
                    'origen' => $origen,
                    'aliado_id' => $aliadoId,
                    'error' => $envio['error'] ?? null,
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('AlertaOperativa: excepción al enviar', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /** ¿La cuenta con la que envía este aliado tiene aprobada la plantilla de avisos? */
    public function plantillaDisponible(int $aliadoId): bool
    {
        return $this->plantilla($aliadoId, WhatsappConfig::paraAliado($aliadoId)) !== null;
    }

    /**
     * La plantilla de avisos en la cuenta de este aliado. Las de la cuenta compartida
     * están registradas a nombre del aliado BryNex, igual que en el chat.
     */
    private function plantilla(int $aliadoId, WhatsappConfig $config): ?WhatsappPlantilla
    {
        $duenoCuenta = $config->usa_cuenta_brynex ? WhatsappBandejaCompartida::aliadoBandeja() : $aliadoId;

        return WhatsappPlantilla::delAliado($duenoCuenta)
            ->aprobadas()
            ->where('nombre', self::NOMBRE_PLANTILLA)
            ->first();
    }

    /**
     * Igual que enviar(), pero se calla si ya avisó de lo mismo hace poco.
     *
     * Sin esto, un equipo nuevo que reintenta el login manda una alerta por
     * intento: a la tercera se dejan de leer, y una alerta que no se lee es
     * peor que ninguna porque da sensación de cobertura.
     */
    public function enviarUnaVez(string $clave, string $origen, string $mensaje, int $minutos = 60): bool
    {
        $cacheKey = 'alerta_operativa:'.md5($clave);

        if (Cache::has($cacheKey)) {
            return false;
        }

        Cache::put($cacheKey, true, now()->addMinutes($minutos));

        return $this->enviar($origen, $mensaje);
    }

    /**
     * Si el destinatario le escribió a la línea de este aliado en las últimas 24h.
     *
     * En la línea de Brygar se mira en dos sitios. La conversación guarda el número con
     * el 57 adelante, igual que llega de Meta. Y los mensajes que se desvían a GARVIS no
     * llegan a la conversación: por cada uno, GARVIS deja la clave
     * `whatsapp_ventana:<número>` en caché con 24 horas de vida.
     *
     * La ventana es del par número-línea, no del aliado: en la cuenta compartida sirve la
     * conversación que ese número tenga con cualquiera de los aliados que la usan.
     */
    private function ventanaAbierta(string $numero, int $aliadoId, WhatsappConfig $config): bool
    {
        $numero = WhatsappApiService::normalizarNumero($numero);

        if ($aliadoId === self::ALIADO_ID && Cache::has('whatsapp_ventana:'.$numero)) {
            return true;
        }

        $aliadosDeLaLinea = $config->usa_cuenta_brynex
            ? WhatsappConfig::where('usa_cuenta_brynex', true)->pluck('aliado_id')->all()
            : [$aliadoId];

        return WhatsappConversacion::whereIn('aliado_id', $aliadosDeLaLinea)
            ->where('wa_contact_id', $numero)
            ->where('ventana_activa_hasta', '>', now())
            ->exists();
    }

    /**
     * Meta rechaza variables con saltos de línea, tabulaciones o cadenas de
     * espacios. Se aplanan a " · " para que el mensaje siga siendo legible.
     */
    private function sanear(string $texto): string
    {
        // La regla de Meta la aplica la plantilla; aquí solo se acorta más, que
        // una alerta se lee en la notificación del teléfono.
        return Str::limit(WhatsappPlantilla::sanearParametro($texto), self::MAX_PARAM, '…');
    }
}
