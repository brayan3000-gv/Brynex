<?php

namespace App\Services;

use App\Models\WhatsappConversacion;
use App\Models\WhatsappMensaje;
use App\Services\Finanzas\TelefonosDeudores;
use Illuminate\Support\Collection;

/**
 * Decide quién está esperando que una persona le conteste por WhatsApp.
 *
 * La misma regla la usan el inbox (pestaña «Esperando» y el chip de cada fila) y el
 * aviso `whatsapp:sin-respuesta`: si viviera en dos sitios, el aviso diría una cosa y
 * el panel otra, y es justo ahí donde se perdían las ventas (sep-2026).
 *
 * Cuenta como «esperando»:
 *  - el último mensaje de la conversación es del cliente y no es una despedida, o
 *  - la conversación quedó marcada como pendiente por atender (el bot la pasó a una
 *    persona, o se le mandó un acuse automático porque nadie respondía).
 *
 * Un «ok» o un «gracias» de cierre no pide respuesta, y una lista llena de despedidas
 * se deja de leer.
 */
class WhatsappEsperandoRespuesta
{
    /**
     * Lo que se contesta para despedirse: no pide respuesta.
     *
     * Tiene que haber una palabra de despedida ("gracias", "igualmente") o un "ok" solo; y nunca
     * cuenta si trae una pregunta o habla de plata: "ok, ¿cuánto vale?" o "soporte de pago,
     * gracias" sí piden que alguien haga algo.
     */
    private const DESPEDIDA = '/^(?!.*(\?|cu[aá]nt|c[oó]mo|qu[eé]\b|d[oó]nde|cu[aá]ndo|precio|valor|pag|soporte|comprobante|planilla|necesit|quiero|ayuda))\s*((ok+|okey|oki|vale|listo|bueno|dale|perfecto|entendido)[\s,.!]*)?(((muchas|mil)\s+)?(grac\p{L}{1,4}|igualmente|bendiciones)(\s+\p{L}+){0,4})?[\s\p{So}\p{Sk}\p{P}]*$/iu';

    /** Tope de parámetros por consulta en SQL Server (2100); se deja margen. */
    private const LOTE = 1500;

    /**
     * Pasado este tiempo ya nadie está «esperando»: esa venta se perdió y mostrarla en la
     * pestaña solo tapa a los que todavía se pueden recuperar. En Brygar había mensajes de
     * hace 125 días sin respuesta que habrían encabezado la lista.
     */
    public const DIAS_MAX_ESPERA = 30;

    public static function esDespedida(string $texto): bool
    {
        return (bool) preg_match(self::DESPEDIDA, $texto);
    }

    /** «35 min», «3 h», «2 d»: lo que lleva esperando, para leerlo de un vistazo. */
    public static function hace(\Carbon\Carbon $desde): string
    {
        $min = (int) $desde->diffInMinutes(now());

        return match (true) {
            $min < 60 => "{$min} min",
            $min < 1440 => intdiv($min, 60).' h',
            default => intdiv($min, 1440).' d',
        };
    }

    /**
     * Texto legible de un mensaje para listas y avisos: lo que dijo, o qué tipo de
     * archivo mandó si no trae texto.
     */
    public static function textoDe(WhatsappMensaje $m): string
    {
        $t = trim(preg_replace('/\s+/', ' ', (string) $m->contenido));

        if ($t === '' || str_starts_with($t, '[Tipo de mensaje no soportado')) {
            return match ($m->tipo) {
                'audio' => '[nota de voz]',
                'image' => '[foto]',
                'document' => '[documento]',
                'video' => '[video]',
                'template' => '[plantilla]',
                default => '[mensaje sin texto]',
            };
        }

        return $t;
    }

    /**
     * Último mensaje de cada conversación, en UNA consulta por lote.
     *
     * El `with(['mensajes' => limit(1)])` que usaba el sidebar no sirve para esto: en
     * Laravel 10 el límite aplica a la consulta completa, no a cada conversación, así
     * que traía un solo mensaje para todo el inbox y el resto quedaba «Sin mensajes».
     *
     * @return array<int, WhatsappMensaje> indexado por conversacion_id
     */
    public function ultimosMensajes(Collection $conversaciones): array
    {
        $ultimos = [];

        foreach ($conversaciones->pluck('id')->chunk(self::LOTE) as $ids) {
            // Solo columnas cortas. `contenido` es nvarchar(max) y el driver lo trae fila por
            // fila en viajes aparte: con 459 conversaciones, el `select *` tardaba 8,5 s desde
            // el Mac y recortado a 300 caracteres (de sobra para el preview) baja a 4 ms en
            // netcup. Medido el 2-oct-2026.
            $mensajes = WhatsappMensaje::query()
                ->select(['id', 'conversacion_id', 'direccion', 'tipo', 'created_at', 'es_bot', 'usuario_id', 'media_nombre'])
                ->selectRaw('CAST(LEFT(contenido, 300) AS nvarchar(300)) AS contenido')
                ->whereIn('id', function ($q) use ($ids) {
                    $q->selectRaw('MAX(id)')
                        ->from('whatsapp_mensajes')
                        ->whereIn('conversacion_id', $ids->values()->all())
                        ->groupBy('conversacion_id');
                })->get();

            foreach ($mensajes as $m) {
                $ultimos[$m->conversacion_id] = $m;
            }
        }

        return $ultimos;
    }

    /**
     * Deja en cada conversación `esperando`, `esperando_desde` y `esperando_dijo`, y le
     * cuelga su último mensaje en la relación `mensajes` para que el preview del inbox
     * no haga una consulta por fila. Modifica la colección en sitio.
     */
    public function marcar(Collection $conversaciones): void
    {
        if ($conversaciones->isEmpty()) {
            return;
        }

        $ultimos = $this->ultimosMensajes($conversaciones);
        $numeroDueno = preg_replace('/\D/', '', (string) config('finanzas.whatsapp_personal_dueno'));

        foreach ($conversaciones as $c) {
            $ultimo = $ultimos[$c->id] ?? null;
            $c->setRelation('mensajes', collect($ultimo ? [$ultimo] : []));

            $c->esperando = false;
            $c->esperando_desde = null;
            $c->esperando_dijo = null;

            if (! $ultimo) {
                continue;
            }

            $escribioUltimo = $ultimo->direccion === 'entrante';
            $texto = self::textoDe($ultimo);

            if ($ultimo->created_at->lt(now()->subDays(self::DIAS_MAX_ESPERA))) {
                continue;
            }

            // El dueño le escribe a su propia línea (alertas, «Mantener activo»): no espera a nadie.
            $tel = preg_replace('/\D/', '', (string) $c->wa_contact_id);
            if ($numeroDueno !== '' && $tel === $numeroDueno) {
                continue;
            }

            // Lo mismo la gente del propio aliado: quien recibe el aviso y toca «Mantener
            // activo», o el saludo automático de su WhatsApp Business.
            if (in_array(substr($tel, -10), self::numerosDelAliado((int) $c->aliado_id), true)) {
                continue;
            }

            if ($c->pendiente_atencion) {
                $c->esperando = true;
                // Si lo último fue nuestro (el acuse o la despedida del bot), lo que importa
                // es por qué quedó pendiente, no lo último que dijo el bot.
                $c->esperando_dijo = $escribioUltimo ? $texto : ($c->pendiente_motivo ?: 'pasada a un asesor');
                $c->esperando_desde = $escribioUltimo ? $ultimo->created_at : $this->ultimoEntranteAt($c, $ultimo);
            } elseif ($escribioUltimo && ! self::esDespedida($texto)) {
                $c->esperando = true;
                $c->esperando_dijo = $texto;
                $c->esperando_desde = $ultimo->created_at;
            }
        }
    }

    /**
     * Conversaciones de un aliado con alguien esperando desde hace al menos `$horas`.
     * Es lo que lista el aviso; excluye al dueño y a los deudores de sus préstamos, que
     * no son clientes del aliado y ya le llegan reenviados uno por uno.
     */
    public function paraAviso(int $aliadoId, int $horas = 2, int $dias = 14): Collection
    {
        $numeroDueno = preg_replace('/\D/', '', (string) config('finanzas.whatsapp_personal_dueno'));

        // Los que quedaron pendientes se miran con el doble de margen: son justo los casos
        // que alguien prometió atender. Un asesor con empresa propia llevaba 14 días así y,
        // con la ventana normal, ya no habría salido en el aviso.
        $conversaciones = WhatsappConversacion::where('aliado_id', $aliadoId)
            ->where(fn ($q) => $q->where('ultimo_mensaje_at', '>=', now()->subDays($dias))
                ->orWhere(fn ($q2) => $q2->where('pendiente_atencion', true)
                    ->where('ultimo_mensaje_at', '>=', now()->subDays($dias * 2))))
            ->get();

        $this->marcar($conversaciones);

        return $conversaciones
            ->filter(function (WhatsappConversacion $c) use ($numeroDueno, $horas) {
                if (! $c->esperando || ! $c->esperando_desde) {
                    return false;
                }

                $tel = preg_replace('/\D/', '', (string) $c->wa_contact_id);
                if ($tel === $numeroDueno || TelefonosDeudores::esDeudor($c->wa_contact_id)) {
                    return false;
                }

                // Todavía está fresco: el bot o alguien del equipo lo está atendiendo.
                return $c->esperando_desde->lte(now()->subHours($horas));
            })
            ->values();
    }

    /**
     * Conversaciones asignadas a un asesor donde el cliente lleva al menos `$horas`
     * esperando y el asesor lleva ese mismo tiempo con ella asignada. Son las que
     * `whatsapp:liberar-sin-atender` devuelve al inbox general.
     *
     * Las dos condiciones van juntas: a quien le asignaron hace diez minutos una
     * conversación que ya llevaba un día esperando no se le quita de inmediato.
     */
    public function paraLiberar(int $aliadoId, int $horas): Collection
    {
        $limite = now()->subHours($horas);

        $conversaciones = WhatsappConversacion::where('aliado_id', $aliadoId)
            ->where('estado', 'asignada')
            ->whereNotNull('asignado_a')
            ->where('ultimo_mensaje_at', '>=', now()->subDays(self::DIAS_MAX_ESPERA))
            ->where(fn ($q) => $q->whereNull('asignado_at')->orWhere('asignado_at', '<=', $limite))
            ->with('asignado:id,nombre')
            ->get();

        $this->marcar($conversaciones);

        return $conversaciones
            ->filter(fn (WhatsappConversacion $c) => $c->esperando
                && $c->esperando_desde
                && $c->esperando_desde->lte($limite)
                && ! TelefonosDeudores::esDeudor($c->wa_contact_id))
            ->values();
    }

    /**
     * A quién se le manda el aviso de conversaciones esperando de un aliado: lo que diga
     * `services.whatsapp.pendientes_por_aliado`; si el aliado no está ahí, el WhatsApp (o
     * celular) de su ficha; y siempre la copia de BryNex si está configurada.
     *
     * @return string[] números con solo dígitos
     */
    public static function destinatariosAviso(int $aliadoId): array
    {
        $porAliado = config('services.whatsapp.pendientes_por_aliado', []);
        $crudo = $porAliado[$aliadoId] ?? null;

        if ($crudo === null || trim((string) $crudo) === '') {
            $aliado = \App\Models\Aliado::find($aliadoId);
            $crudo = $aliado?->whatsapp ?: $aliado?->celular;
        }

        $crudos = array_merge(
            explode(',', (string) $crudo),
            explode(',', (string) config('services.whatsapp.pendientes_copia'))
        );

        $numeros = array_filter(array_map(fn ($n) => preg_replace('/\D/', '', $n), $crudos), fn ($n) => strlen($n) >= 10);

        return array_values(array_unique($numeros));
    }

    /**
     * Últimos 10 dígitos de los números del propio aliado: los de su ficha, el de su
     * línea de WhatsApp y los de quienes reciben el aviso.
     *
     * Esa gente le escribe a la línea (el saludo automático de su WhatsApp Business, el
     * botón «Mantener activo» del aviso) y no es un cliente: no cuenta como esperando,
     * no recibe acuse, y en el número compartido su conversación va al inbox de su aliado.
     *
     * @return string[]
     */
    public static function numerosDelAliado(int $aliadoId): array
    {
        static $cache = [];

        return $cache[$aliadoId] ??= (function () use ($aliadoId) {
            $aliado = \App\Models\Aliado::find($aliadoId);
            $config = \App\Models\WhatsappConfig::where('aliado_id', $aliadoId)->first();

            return collect([$aliado?->whatsapp, $aliado?->celular, $aliado?->telefono, $config?->numero_telefono])
                ->merge(self::destinatariosAviso($aliadoId))
                ->map(fn ($n) => substr(preg_replace('/\D/', '', (string) $n), -10))
                ->filter(fn ($n) => strlen($n) === 10)
                ->unique()
                ->values()
                ->all();
        })();
    }

    private function ultimoEntranteAt(WhatsappConversacion $c, WhatsappMensaje $ultimo): ?\Carbon\Carbon
    {
        $entrante = WhatsappMensaje::where('conversacion_id', $c->id)
            ->where('direccion', 'entrante')
            ->orderByDesc('id')
            ->value('created_at');

        return $entrante ? \Carbon\Carbon::parse($entrante) : $ultimo->created_at;
    }
}
