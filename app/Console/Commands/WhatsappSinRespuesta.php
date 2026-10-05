<?php

namespace App\Console\Commands;

use App\Models\Aliado;
use App\Models\Publicacion;
use App\Models\WhatsappConfig;
use App\Models\WhatsappConversacion;
use App\Services\AlertaOperativaService;
use App\Services\Ia\AsistenteIaService;
use App\Services\WhatsappEsperandoRespuesta;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Aviso de la gente que escribió por WhatsApp y está esperando que una persona le conteste.
 *
 * Existe porque ahí es donde se estaban perdiendo las ventas, no en los anuncios. Al revisar las
 * 48 conversaciones de clientes que llegaron por pauta (sep-2026) había gente lista para
 * afiliarse sin respuesta: una señora de 63 años que ya había pedido los requisitos llevaba 11
 * días esperando, un asesor con empresa propia 14, y otro cliente pidió "márcame porfa" y nadie
 * lo llamó. Ninguno se perdió por el anuncio ni por el bot: se perdieron porque nadie les volvió
 * a escribir, y en el panel no había nada que lo hiciera evidente.
 *
 * Al medir los demás aliados (oct-2026) fue peor: en Fecop el 81 % de lo que escribían los
 * clientes no tenía ninguna respuesta, y el aviso solo existía para Brygar y una vez al día, a
 * una hora en la que a casi todos ya se les había vencido la ventana de 24 h. Ahora corre para
 * todos los aliados con WhatsApp, varias veces al día, y marca a quién se le está venciendo la
 * ventana (después toca con plantilla).
 *
 * Qué cuenta como «esperando» lo decide WhatsappEsperandoRespuesta, la misma regla del inbox.
 *
 * Para no cansar: la primera corrida del día manda la lista completa; las siguientes solo si
 * hay alguien nuevo desde el aviso anterior, o alguien a quien se le vence la ventana en menos de
 * HORAS_URGENTE y aún no se avisó como urgente. Si no hay nadie esperando no manda nada: un
 * aviso que siempre llega diciendo "todo bien" termina ignorándose el día que sí importa.
 *
 * Ejecución manual: php artisan whatsapp:sin-respuesta --no-enviar
 */
class WhatsappSinRespuesta extends Command
{
    protected $signature = 'whatsapp:sin-respuesta
        {--aliado= : Aliado a revisar (por defecto, todos los que tienen WhatsApp activo)}
        {--horas=2 : Horas sin respuesta para que cuente como esperando}
        {--dias=14 : No mirar conversaciones más viejas que esto}
        {--forzar : Enviar aunque no haya nada nuevo desde el aviso anterior}
        {--no-enviar : Solo mostrarlo en pantalla}';

    protected $description = 'Avisa por WhatsApp quién escribió y lleva horas sin que una persona le responda';

    /** El tope de la plantilla es 600 caracteres; lo que no quepa se resume en "y N más". */
    private const MAX_CARACTERES = 560;

    /** Con menos de esto de ventana, la persona se marca como urgente. */
    private const HORAS_URGENTE = 3;

    public function __construct(private WhatsappEsperandoRespuesta $esperando)
    {
        parent::__construct();
    }

    public function handle(AlertaOperativaService $alertas): int
    {
        $aliados = $this->option('aliado')
            ? [(int) $this->option('aliado')]
            : WhatsappConfig::where('activo', true)->pluck('aliado_id')->map(fn ($id) => (int) $id)->all();

        foreach ($aliados as $aliadoId) {
            $this->revisarAliado($aliadoId, $alertas);
        }

        return self::SUCCESS;
    }

    private function revisarAliado(int $aliadoId, AlertaOperativaService $alertas): void
    {
        $nombreAliado = Aliado::find($aliadoId)?->nombre ?: "aliado {$aliadoId}";
        $this->line("\n<info>{$nombreAliado}</info>");

        $lista = $this->esperando->paraAviso($aliadoId, (int) $this->option('horas'), (int) $this->option('dias'))
            ->map(fn (WhatsappConversacion $cv) => $this->item($cv));

        // Primero los asesores (traen cartera), después los que se les vence la ventana, después
        // los que el bot ya pasó a una persona, después el resto. Dentro de cada grupo, lo más
        // reciente primero: es lo que todavía se puede recuperar.
        $lista = $lista->sortBy(fn ($e) => [$e['prioridad'], -$e['desde']->timestamp])->values();

        $this->mostrar($lista->all());

        if ($lista->isEmpty()) {
            $this->info('Nadie esperando respuesta. No se envía nada.');

            return;
        }

        if ($this->option('no-enviar')) {
            return;
        }

        if (! $this->option('forzar') && ! $this->hayNovedad($aliadoId, $lista)) {
            $this->info('Nada nuevo desde el aviso anterior. No se envía nada.');

            return;
        }

        $texto = $this->resumen($lista->all());
        $destinatarios = WhatsappEsperandoRespuesta::destinatariosAviso($aliadoId);

        if (empty($destinatarios)) {
            $this->warn('Sin números a quién avisar: ponga el WhatsApp del aliado en su ficha o en services.whatsapp.pendientes_por_aliado.');

            return;
        }

        // Sale por la cuenta con la que el aliado hace sus envíos (la suya o la compartida
        // de BryNex), no por la de Brygar: el aviso es del aliado y lo recibe su gente.
        if (! $alertas->plantillaDisponible($aliadoId)) {
            $this->warn('  La cuenta de WhatsApp de este aliado no tiene aprobada la plantilla «'.AlertaOperativaService::NOMBRE_PLANTILLA
                .'»: solo saldrá a quien tenga la ventana de 24 h abierta. Créela con: php artisan whatsapp:plantillas-sistema');
        }

        foreach ($destinatarios as $numero) {
            $ok = $alertas->enviarDesdeAliado($aliadoId, $numero, "Esperando respuesta · {$nombreAliado}", $texto);
            $this->line($ok ? "  → enviado a {$numero}" : "  → no se pudo enviar a {$numero} (ver el log).");
        }

        $this->recordarAvisado($aliadoId, $lista);
    }

    private function item(WhatsappConversacion $cv): array
    {
        $pieza = $cv->origen_publicacion_id ? Publicacion::find($cv->origen_publicacion_id) : null;
        $esAsesor = $pieza && AsistenteIaService::esPiezaDeAsesores($pieza);
        $ventanaMin = $cv->minutosVentanaRestante();
        $urgente = $cv->ventanaActiva() && $ventanaMin <= self::HORAS_URGENTE * 60;

        return [
            'id' => $cv->id,
            // Si desde el aviso anterior volvió a escribir, cuenta como novedad.
            'marca' => $cv->mensajes->first()?->id ?? 0,
            'nombre' => $this->nombreCorto($cv),
            'telefono' => preg_replace('/\D/', '', (string) $cv->wa_contact_id),
            'desde' => $cv->esperando_desde,
            'dijo' => (string) $cv->esperando_dijo,
            'asesor' => $esAsesor,
            'urgente' => $urgente,
            'ventana' => $cv->ventanaActiva() ? $this->ventanaCorta($ventanaMin) : 'vencida',
            'prioridad' => $esAsesor ? 0 : ($urgente ? 1 : ($cv->pendiente_atencion ? 2 : 3)),
            'pendiente' => (bool) $cv->pendiente_atencion,
        ];
    }

    /**
     * ¿Hay alguien que no estaba en el aviso anterior de hoy, o alguien que pasó a urgente?
     * Lo avisado se guarda en caché hasta medianoche: al día siguiente se arranca de cero y
     * la primera corrida vuelve a mandar la lista completa.
     */
    private function hayNovedad(int $aliadoId, \Illuminate\Support\Collection $lista): bool
    {
        $avisado = Cache::get($this->claveCache($aliadoId));
        if (! is_array($avisado)) {
            return true;
        }

        foreach ($lista as $e) {
            $previo = $avisado[$e['id']] ?? null;
            if ($previo === null || $previo['marca'] !== $e['marca']) {
                return true;
            }
            if ($e['urgente'] && ! $previo['urgente']) {
                return true;
            }
        }

        return false;
    }

    private function recordarAvisado(int $aliadoId, \Illuminate\Support\Collection $lista): void
    {
        $avisado = Cache::get($this->claveCache($aliadoId));
        $avisado = is_array($avisado) ? $avisado : [];

        foreach ($lista as $e) {
            $avisado[$e['id']] = ['marca' => $e['marca'], 'urgente' => $e['urgente']];
        }

        Cache::put($this->claveCache($aliadoId), $avisado, now()->endOfDay());
    }

    private function claveCache(int $aliadoId): string
    {
        return 'wa_sin_respuesta_avisado:'.$aliadoId.':'.now()->toDateString();
    }

    private function nombreCorto(WhatsappConversacion $cv): string
    {
        $n = trim((string) $cv->nombre_contacto);
        // Los nombres de perfil vienen de todo tipo ("te quiero hija 😘"). Se toman las dos
        // primeras palabras; si no hay nombre, los últimos dígitos del número.
        $n = $n !== '' ? implode(' ', array_slice(preg_split('/\s+/', $n), 0, 2)) : '…'.substr((string) $cv->wa_contact_id, -4);

        return mb_substr($n, 0, 22);
    }

    private function hace(\Carbon\Carbon $f): string
    {
        $h = (int) $f->diffInHours(now());

        return $h < 24 ? "{$h}h" : intdiv($h, 24).'d';
    }

    private function ventanaCorta(int $minutos): string
    {
        return $minutos < 60 ? "{$minutos}min" : intdiv($minutos, 60).'h';
    }

    /** Una sola tira: la plantilla aplana los saltos de línea. */
    private function resumen(array $esperando): string
    {
        $total = count($esperando);
        $urgentes = count(array_filter($esperando, fn ($e) => $e['urgente']));
        $cabeza = ($total === 1 ? '1 persona espera respuesta' : "{$total} personas esperan respuesta")
            .($urgentes > 0 ? " ({$urgentes} con la ventana por vencer)" : '').': ';

        $partes = [];
        $largo = mb_strlen($cabeza);
        foreach ($esperando as $i => $e) {
            $item = ($i + 1).') '.($e['asesor'] ? 'ASESOR ' : '').($e['urgente'] ? '⏳ ' : '')
                .$e['nombre'].' '.$e['telefono'].' '.$this->hace($e['desde'])
                .' «'.mb_substr($e['dijo'], 0, 38).'»'
                .($e['ventana'] === 'vencida' ? ' [ventana vencida]' : ($e['urgente'] ? ' [vence en '.$e['ventana'].']' : ''));
            // Se reserva sitio para el "y N más" del final.
            if ($largo + mb_strlen($item) + 20 > self::MAX_CARACTERES) {
                break;
            }
            $partes[] = $item;
            $largo += mb_strlen($item) + 1;
        }

        $faltan = $total - count($partes);

        return $cabeza.implode(' ', $partes).($faltan > 0 ? " y {$faltan} más en el chat." : '');
    }

    private function mostrar(array $esperando): void
    {
        $this->table(
            ['', 'Quién', 'Teléfono', 'Hace', 'Ventana', 'Lo último'],
            array_map(fn ($e) => [
                $e['asesor'] ? 'ASESOR' : ($e['urgente'] ? 'URGENTE' : ($e['pendiente'] ? 'pendiente' : '')),
                $e['nombre'],
                $e['telefono'],
                $this->hace($e['desde']),
                $e['ventana'],
                mb_substr($e['dijo'], 0, 60),
            ], $esperando)
        );

        if (! empty($esperando)) {
            $this->line('Aviso: '.$this->resumen($esperando));
        }
    }
}
