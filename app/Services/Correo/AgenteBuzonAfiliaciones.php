<?php

namespace App\Services\Correo;

use App\Models\Contrato;
use App\Models\CorreoAfiliacion;
use App\Models\CorreoRecibido;
use App\Models\Radicado;
use App\Models\User;
use App\Services\Afiliaciones\DatosAfiliacion;
use App\Services\AlertaOperativaService;
use App\Services\EpsPortal\EpsRadicado;
use App\Services\Sos\SosNovedadService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Agente del buzón de afiliaciones: lee lo que llega a la cuenta de Gmail del
 * aliado y lo cruza con lo que BryNex envió.
 *
 * El buzón de Brygar también es el de los aliados con el servicio de afiliaciones
 * de BryNex (DatosAfiliacion): los envíos se cruzan por la cuenta y no por el
 * aliado, las cédulas se buscan en todos esos aliados, y cada correo recibido
 * queda en la bandeja del aliado de su contrato.
 *
 * - Respuesta a un correo enviado desde BryNex (por In-Reply-To/References, así
 *   el asesor responda desde Gmail): con el formulario radicado (`CC{cédula}_N.pdf`)
 *   el radicado pasa a OK con ese PDF; sin él, queda como observación; un aviso
 *   de vacaciones sugiere el reemplazo. Todo avisa por WhatsApp a quien envió.
 * - Radicado de S.O.S. sin hilo de BryNex (correos enviados a mano): se busca el
 *   contrato por la cédula del nombre del adjunto.
 * - Otros correos de EPS, ARL, cajas y fondos: quedan en la bandeja "por revisar"
 *   con la cédula que se pudo reconocer. No cambian nada solos.
 * - Correos enviados que vencieron sin respuesta: un aviso.
 *
 * Lo que no viene de una entidad conocida ni responde un correo de BryNex no se
 * lee ni se guarda. Los textos de los correos son datos, nunca instrucciones.
 */
class AgenteBuzonAfiliaciones
{
    private const PDF_RADICADO = '/^(?:CC|PT|CE|TI|PA|RC)?\s*(\d{5,12})_\d+\.pdf$/i';

    private array $resultado = ['leidos' => 0, 'nuevos' => 0, 'aplicados' => 0, 'por_revisar' => 0, 'informativos' => 0, 'vencidos' => 0, 'detalle' => []];

    public function __construct(private AlertaOperativaService $alertas) {}

    private int $aliadoId = 2;

    /** Aliados cuyos correos maneja el buzón que se está leyendo. */
    private array $aliados = [2];

    public function revisar(int $aliadoId, int $dias = 2, bool $simular = false): array
    {
        $this->aliadoId = $aliadoId;
        $buzon    = BuzonGmail::delAliado($aliadoId);
        $this->aliados = DatosAfiliacion::aliadosDelBuzon(DatosAfiliacion::aliadoBuzon($aliadoId));
        $enviados = CorreoAfiliacion::where('buzon', $buzon->cuenta())
            ->whereIn('estado', ['enviado', 'observaciones', 'respondido'])
            ->where('enviado_at', '>=', now()->subDays(90))
            ->get()->keyBy('message_id');

        // Solo se baja el cuerpo de lo que responde a BryNex o viene de una entidad (sin boletines).
        $mensajes = $buzon->recibidos(now()->subDays($dias), function (array $h) use ($enviados) {
            $hilo = $h['in_reply_to'].' '.$h['referencias'];
            if ($enviados->contains(fn ($e) => $e->message_id && str_contains($hilo, $e->message_id))) {
                return true;
            }

            return $this->entidad($h['de']) && ! preg_match(config('afiliaciones_correo.remitentes_masivos'), $h['de']);
        }, 500, $this->busquedaGmail());

        foreach ($mensajes as $m) {
            $this->resultado['leidos']++;
            try {
                $this->procesar($aliadoId, $buzon->cuenta(), $m, $enviados, $simular);
            } catch (Throwable $e) {
                $this->anotar('error', "{$m['de']} «{$m['asunto']}»: {$e->getMessage()}");
            }
        }

        $this->vencidos($buzon->cuenta(), $simular);

        return $this->resultado;
    }

    private function procesar(int $aliadoId, string $cuenta, array $m, $enviados, bool $simular): void
    {
        $messageId = $m['message_id'] ?: 'sin-id-'.md5($m['de'].$m['fecha'].$m['asunto']);
        if (CorreoRecibido::where('buzon', $cuenta)->where('message_id', $messageId)->exists()) {
            return;
        }

        $hilo = $m['in_reply_to'].' '.$m['referencias'];
        $enviado = $enviados->first(fn ($e) => $e->message_id && str_contains($hilo, $e->message_id));
        $entidad = $this->entidad($m['de']);

        if (! $enviado && ! $entidad) {
            return;                                              // correo que no es de afiliaciones
        }
        if (! $enviado && preg_match(config('afiliaciones_correo.remitentes_masivos'), $m['de'])) {
            return;                                              // boletín o publicidad de la entidad
        }

        $this->resultado['nuevos']++;
        $base = [
            'aliado_id' => $aliadoId, 'buzon' => $cuenta, 'message_id' => $messageId, 'uid' => $m['uid'],
            'in_reply_to' => mb_substr($m['in_reply_to'], 0, 500), 'referencias' => $m['referencias'],
            'de' => $m['de'], 'de_nombre' => mb_substr($m['de_nombre'], 0, 200), 'asunto' => mb_substr($m['asunto'], 0, 500),
            'recibido_at' => $m['fecha'], 'texto' => $m['texto'], 'entidad' => $entidad['clave'] ?? ($enviado?->entidad),
        ];

        if ($simular) {
            $this->anotar('simulado', ($enviado ? "respuesta al correo #{$enviado->id}" : 'entidad '.($entidad['nombre'] ?? '?'))." · {$m['de']} «{$m['asunto']}» · adjuntos: "
                .implode(', ', array_column($m['adjuntos'], 'nombre')));

            return;
        }

        $adjuntos = $this->guardarAdjuntos($aliadoId, $messageId, $m['adjuntos']);

        if ($enviado) {
            $this->respuestaAsesor($enviado, $base + ['adjuntos' => $adjuntos], $m);
        } elseif (($entidad['clave'] ?? null) === 'sos' && $this->pdfRadicado($adjuntos)) {
            $this->radicadoSinHilo($aliadoId, $base + ['adjuntos' => $adjuntos], $m);
        } else {
            $this->otraEntidad($aliadoId, $base + ['adjuntos' => $adjuntos], $m, $entidad);
        }
    }

    /** Respuesta al correo que BryNex envió al asesor. */
    private function respuestaAsesor(CorreoAfiliacion $enviado, array $base, array $m): void
    {
        $base['aliado_id'] = $enviado->aliado_id;
        $contrato = Contrato::with('cliente')->find($enviado->contrato_id);
        $nombre   = $contrato?->cliente ? trim($contrato->cliente->primer_nombre.' '.$contrato->cliente->primer_apellido) : "contrato {$enviado->contrato_id}";
        $radicado = $enviado->radicado_id ? Radicado::find($enviado->radicado_id) : ($contrato ? EpsRadicado::deContrato($contrato) : null);
        $texto    = Str::limit($m['texto'], 400);
        $quien    = $m['de_nombre'] ?: $m['de'];
        $eps      = config("afiliaciones_correo.asesores.{$enviado->entidad}.nombre_entidad") ?? Str::upper($enviado->entidad);

        // Aviso automático de vacaciones: el trámite sigue esperando; se sugiere el reemplazo.
        if (preg_match('/vacaci|fuera de (la )?oficina|out of office|ausente|respuesta autom/iu', $m['asunto'].' '.$m['texto'])) {
            $reemplazo = config("afiliaciones_correo.asesores.{$enviado->entidad}.reemplazo");
            CorreoRecibido::create($base + ['clasificacion' => 'vacaciones', 'correo_afiliacion_id' => $enviado->id, 'contrato_id' => $enviado->contrato_id,
                'radicado_id' => $radicado?->id, 'estado' => 'informativo', 'accion' => 'Aviso de vacaciones del asesor.']);
            $this->whatsapp($enviado->usuario_id, "🌴 {$quien} está de vacaciones y no verá la afiliación de {$nombre}."
                .($reemplazo ? " Reenvíala a {$reemplazo['nombre']} ({$reemplazo['correo']}) desde el radicado." : ''));
            $this->anotar('informativo', "vacaciones de {$quien} (afiliación de {$nombre})");

            return;
        }

        $pdf = $this->pdfRadicado($base['adjuntos'], $contrato?->cedula);
        if ($pdf && $contrato && $radicado) {
            $ruta = $this->copiarAlRadicado($contrato, $pdf['ruta'], 'eps_radicado_'.$enviado->entidad);
            EpsRadicado::marcar($radicado, null, Radicado::ESTADO_OK, $ruta,
                "{$eps} respondió el correo ({$quien}, {$m['fecha']->format('d/m/Y H:i')}) con el soporte ({$pdf['nombre']})."
                .($texto ? " Mensaje: «{$texto}»" : ''), $enviado->usuario_id);
            EpsRadicado::bitacora($contrato, $radicado, $enviado->entidad, 'correo_respuesta', 'exitosa', null,
                ['correo_id' => $enviado->id, 'de' => $m['de']], ['adjunto' => $pdf['nombre'], 'texto' => $texto], null, $enviado->usuario_id, $ruta);
            $enviado->update(['estado' => 'respondido', 'respondido_at' => $m['fecha'], 'respuesta_de' => $m['de'], 'respuesta_resumen' => $texto]);
            CorreoRecibido::create($base + ['clasificacion' => 'respuesta_asesor', 'correo_afiliacion_id' => $enviado->id, 'contrato_id' => $contrato->id,
                'radicado_id' => $radicado->id, 'estado' => 'aplicado', 'accion' => "Radicado de EPS en OK con {$pdf['nombre']}."]);
            $this->whatsapp($enviado->usuario_id, "✅ {$eps} respondió la afiliación de {$nombre} (CC {$contrato->cedula}) con {$pdf['nombre']}. El radicado de EPS quedó en OK con ese soporte.");
            $this->anotar('aplicado', "{$nombre}: radicado en OK con {$pdf['nombre']}");

            return;
        }

        // Respondió sin el formulario radicado: una observación o una pregunta.
        if ($radicado && $contrato) {
            EpsRadicado::marcar($radicado, null, $radicado->estado === Radicado::ESTADO_OK ? Radicado::ESTADO_OK : Radicado::ESTADO_TRAMITE, null,
                "{$eps} respondió el correo sin radicado ({$quien}, {$m['fecha']->format('d/m/Y H:i')}): «{$texto}»", $enviado->usuario_id);
        }
        if ($enviado->estado !== 'respondido') {
            $enviado->update(['estado' => 'observaciones', 'respondido_at' => $m['fecha'], 'respuesta_de' => $m['de'], 'respuesta_resumen' => $texto]);
        }
        CorreoRecibido::create($base + ['clasificacion' => 'respuesta_asesor', 'correo_afiliacion_id' => $enviado->id, 'contrato_id' => $enviado->contrato_id,
            'radicado_id' => $radicado?->id, 'estado' => 'por_revisar', 'accion' => 'Respuesta sin formulario radicado: revisar lo que pide.']);
        $this->whatsapp($enviado->usuario_id, "⚠️ {$eps} respondió la afiliación de {$nombre} sin radicado: «".Str::limit($m['texto'], 250)."». Revísalo en BryNex.");
        $this->anotar('por_revisar', "{$nombre}: respuesta sin radicado");
    }

    /** Formulario radicado de S.O.S. que no responde un correo de BryNex (enviado a mano). */
    private function radicadoSinHilo(int $aliadoId, array $base, array $m): void
    {
        $pdf    = $this->pdfRadicado($base['adjuntos']);
        $cedula = preg_match(self::PDF_RADICADO, $pdf['nombre'], $x) ? $x[1] : null;

        $contratos = $cedula ? Contrato::with('cliente')
            ->whereIn('aliado_id', $this->aliados)->where('cedula', $cedula)->where('estado', 'vigente')
            ->whereHas('eps', fn ($q) => $q->where('codigo', SosNovedadService::CODIGO_EPS))
            ->get() : collect();

        if ($contratos->count() !== 1) {
            CorreoRecibido::create($base + ['clasificacion' => 'radicado_entidad', 'estado' => 'por_revisar',
                'accion' => $contratos->isEmpty() ? "Formulario radicado de S.O.S. ({$pdf['nombre']}) sin contrato vigente con esa cédula." : "Varios contratos con la cédula {$cedula}: elegir cuál."]);
            $this->anotar('por_revisar', "radicado S.O.S. {$pdf['nombre']} sin contrato único");

            return;
        }

        $contrato = $contratos->first();
        $base['aliado_id'] = $contrato->aliado_id;
        $radicado = EpsRadicado::deContrato($contrato);
        $ruta = $this->copiarAlRadicado($contrato, $pdf['ruta'], 'eps_radicado_sos');
        $quien = $m['de_nombre'] ?: $m['de'];
        EpsRadicado::marcar($radicado, null, Radicado::ESTADO_OK, $ruta,
            "S.O.S. envió por correo el formulario radicado ({$quien}, {$m['fecha']->format('d/m/Y H:i')}, {$pdf['nombre']}).", null);
        CorreoRecibido::create($base + ['clasificacion' => 'radicado_entidad', 'contrato_id' => $contrato->id, 'radicado_id' => $radicado->id,
            'estado' => 'aplicado', 'accion' => "Radicado de EPS en OK con {$pdf['nombre']}."]);
        $this->anotar('aplicado', "CC {$cedula}: radicado en OK con {$pdf['nombre']} (sin hilo de BryNex)");
    }

    /** Otros correos de entidades: a la bandeja, con la cédula si se reconoce. */
    private function otraEntidad(int $aliadoId, array $base, array $m, ?array $entidad): void
    {
        preg_match_all('/(?<!\d)(\d{6,10})(?!\d)/', $m['asunto'].' '.implode(' ', array_column($base['adjuntos'], 'nombre')).' '.Str::limit($m['texto'], 800, ''), $x);
        $cedulas = array_values(array_unique($x[1]));
        $contratos = $cedulas ? Contrato::whereIn('aliado_id', $this->aliados)->whereIn('cedula', $cedulas)->where('estado', 'vigente')->pluck('aliado_id', 'id') : collect();
        // Con un solo contrato el correo va a la bandeja de su aliado; si no, a la del buzón.
        if ($contratos->count() === 1) {
            $base['aliado_id'] = (int) $contratos->first();
        }

        CorreoRecibido::create($base + [
            'clasificacion' => 'otra_entidad',
            'contrato_id'   => $contratos->count() === 1 ? $contratos->keys()->first() : null,
            'estado'        => 'por_revisar',
            'accion'        => ($entidad['nombre'] ?? 'Entidad').($contratos->count() === 1 ? ': correo de un contrato vigente.' : ($contratos->count() > 1 ? ': menciona varios contratos.' : ': sin contrato reconocido.')),
        ]);
        $this->anotar('por_revisar', ($entidad['nombre'] ?? $m['de'])." «{$m['asunto']}»");
    }

    /** Correos enviados que vencieron sin respuesta: un aviso por correo. */
    private function vencidos(string $cuenta, bool $simular): void
    {
        $vencidos = CorreoAfiliacion::with('contrato.cliente')
            ->where('buzon', $cuenta)->where('estado', 'enviado')
            ->where('vence_at', '<', now())->whereNull('avisado_vencido_at')->get();

        foreach ($vencidos as $c) {
            $this->resultado['vencidos']++;
            $nombre = $c->contrato?->cliente ? trim($c->contrato->cliente->primer_nombre.' '.$c->contrato->cliente->primer_apellido) : "contrato {$c->contrato_id}";
            if ($simular) {
                $this->anotar('simulado', "vencido sin respuesta: {$nombre} (enviado {$c->enviado_at?->format('d/m H:i')})");

                continue;
            }
            $this->whatsapp($c->usuario_id, "⏰ {$c->para} no ha respondido la afiliación de {$nombre} enviada el {$c->enviado_at?->format('d/m/Y H:i')}. Reenvíala o llama al asesor.");
            $c->update(['avisado_vencido_at' => now()]);
            $this->anotar('vencido', "{$nombre}: sin respuesta de {$c->para}");
        }
    }

    // ── utilidades ─────────────────────────────────────────────────────────

    /**
     * Búsqueda para Gmail: correos de las entidades o con asunto de afiliación (así
     * entran las respuestas de asesores que escriben desde Gmail), sin boletines.
     */
    private function busquedaGmail(): string
    {
        $dominios = collect(array_keys(config('afiliaciones_correo.entidades')))->map(fn ($d) => "from:{$d}")->implode(' ');

        return '{'.$dominios.' subject:(solicitud afiliacion) subject:(afiliacion eps)} -from:noreply -from:no-reply -from:comunica -from:masivo -in:sent';
    }

    private function entidad(string $correo): ?array
    {
        $dominio = Str::after($correo, '@');
        foreach (config('afiliaciones_correo.entidades') as $d => $info) {
            if ($dominio === $d || str_ends_with($dominio, '.'.$d)) {
                return $info;
            }
        }

        return null;
    }

    /** @return array<int, array{nombre:string, ruta:string, tamano:int}> */
    private function guardarAdjuntos(int $aliadoId, string $messageId, array $adjuntos): array
    {
        $salida = [];
        foreach ($adjuntos as $i => $a) {
            $ext = strtolower(pathinfo($a['nombre'], PATHINFO_EXTENSION));
            if (! in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'xlsx', 'xls', 'docx', 'doc'], true) || strlen($a['contenido']) > 15 * 1024 * 1024) {
                continue;
            }
            $ruta = "correos/{$aliadoId}/".now()->format('Ym').'/'.substr(md5($messageId), 0, 12)."_{$i}_".Str::slug(pathinfo($a['nombre'], PATHINFO_FILENAME)).".{$ext}";
            Storage::disk('local')->put($ruta, $a['contenido']);
            $salida[] = ['nombre' => $a['nombre'], 'ruta' => $ruta, 'tamano' => strlen($a['contenido'])];
        }

        return $salida;
    }

    /** El PDF del formulario radicado entre los adjuntos (CC{cédula}_N.pdf, o uno con la cédula del contrato). */
    private function pdfRadicado(array $adjuntos, ?string $cedula = null): ?array
    {
        $pdfs = array_values(array_filter($adjuntos, fn ($a) => str_ends_with(strtolower($a['nombre']), '.pdf')));

        return collect($pdfs)->first(fn ($a) => preg_match(self::PDF_RADICADO, $a['nombre'], $x) && (! $cedula || $x[1] === (string) $cedula))
            ?? ($cedula ? collect($pdfs)->first(fn ($a) => str_contains($a['nombre'], (string) $cedula)) : null);
    }

    private function copiarAlRadicado(Contrato $contrato, string $origen, string $prefijo): ?string
    {
        return EpsRadicado::guardarPdf($contrato, Storage::disk('local')->get($origen), $prefijo);
    }

    /** Aviso a quien envió el correo; si no tiene teléfono, a los números del aliado. */
    private function whatsapp(?int $usuarioId, string $mensaje, ?int $aliadoId = null): void
    {
        $numero  = $usuarioId ? User::where('activo', true)->whereKey($usuarioId)->value('telefono') : null;
        $numeros = $numero ? [$numero] : (config('afiliaciones_correo.whatsapp_avisos.'.($aliadoId ?? $this->aliadoId)) ?: [$this->alertas->numeroDestino()]);
        foreach ($numeros as $n) {
            $this->alertas->enviarA($n, 'Afiliaciones EPS', $mensaje);
        }
    }

    private function anotar(string $tipo, string $texto): void
    {
        $clave = ['aplicado' => 'aplicados', 'por_revisar' => 'por_revisar', 'informativo' => 'informativos'][$tipo] ?? null;
        if ($clave) {
            $this->resultado[$clave]++;
        }
        $this->resultado['detalle'][] = "[{$tipo}] {$texto}";
    }
}
