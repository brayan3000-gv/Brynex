<?php

namespace App\Services\Boxalud;

use App\Models\Radicado;
use App\Models\RadicadoMovimiento;
use App\Models\Tarea;
use App\Services\TareaAutomaticaService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cruza lo que una EPS de Boxalud tiene afiliado con lo que dice BryNex.
 *
 * Coosalud no publica mora por trabajador —de recaudo solo tiene la radicación
 * de devolución de aportes—, así que aquí la pregunta no es quién debe sino
 * quién está mal afiliado, que es de donde sale la mora un mes después:
 *
 *  - el radicado sigue pendiente en BryNex y la persona ya está afiliada allá →
 *    se cierra el radicado, que es trabajo que alguien ya hizo y nadie marcó;
 *  - el contrato está retirado aquí y la afiliación sigue viva allá → se sigue
 *    cobrando el aporte hasta que alguien reporte la finalización;
 *  - el contrato está vigente y con radicado en OK, y allá no aparece → hay
 *    alguien trabajando sin EPS, que es lo que más caro sale.
 *
 * Los estados del portal se interpretan por su nombre, y el que no se reconoce
 * no decide nada: se reporta para mirarlo, porque cerrar un radicado con un
 * estado que no se entendió es peor que no cerrarlo.
 */
class BoxaludCruceService
{
    /** Estados del radicado que todavía esperan gestión. */
    private const ESTADOS_ABIERTOS = [Radicado::ESTADO_PENDIENTE, Radicado::ESTADO_TRAMITE, Radicado::ESTADO_ERROR];

    /**
     * Días de margen antes de reclamar un retiro que no aparece.
     *
     * El portal no se entera el mismo día, y una tarea que nace el lunes y se
     * cierra sola el martes enseña a no mirar las tareas.
     */
    private const DIAS_DE_GRACIA = 10;

    public function __construct(private TareaAutomaticaService $tareas) {}

    /**
     * @param  string  $eps  clave en config/boxalud.php ('coosalud', 'emssanar')
     * @return array{ok:bool, error?:string, nit?:string, afiliados?:int, radicados?:int, nuevas?:int, cerradas?:int, estados?:array, detalle?:array}
     */
    public function revisar(string $eps, string $nit, bool $simular = false, ?int $usuarioId = null): array
    {
        $conf = BoxaludPortalService::conf($eps);
        $nit = preg_replace('/\D/', '', $nit);

        $lectura = BoxaludPortalService::afiliados($eps, $nit);

        if (! ($lectura['ok'] ?? false)) {
            return ['ok' => false, 'error' => $lectura['error'] ?? 'El portal no respondió.', 'nit' => $nit];
        }

        // Una persona puede tener varias afiliaciones con la misma empresa
        // (entró, salió y volvió): manda la que esté viva, y si ninguna lo
        // está, la última que hubo.
        $delPortal = collect($lectura['afiliados'] ?? [])
            ->map(fn ($a) => $a + ['situacion' => self::situacion((string) $a['estado'])])
            ->groupBy('documento')
            ->map(fn ($filas) => $filas->sortBy(fn ($a) => [
                $a['situacion'] === 'vigente' ? 0 : ($a['situacion'] === 'en_tramite' ? 1 : 2),
                -self::orden($a['fecha_inicio'] ?? null),
            ])->first());

        $detalle = [];
        $vistas = [];
        $nuevas = 0;

        foreach ($this->contratos($nit, $conf['codigos_eps'] ?? [$conf['codigo_eps']]) as $contrato) {
            $documento = self::documento((string) $contrato->cedula);
            $enPortal = $delPortal->get($documento);
            $caso = $this->analizar($conf, $contrato, $enPortal);

            if (! $caso) {
                continue;
            }

            $fin = count($detalle);
            $detalle[] = ['documento' => $documento, 'nombre' => $enPortal['nombre'] ?? null] + $caso;

            if ($caso['causa'] === 'radicado_confirmado') {
                $detalle[$fin]['accion'] = $this->confirmarRadicado($conf, $contrato, $caso, $simular, $usuarioId);

                continue;
            }

            // Un estado que no se entendió se mira, no se convierte en tarea:
            // el texto de la tarea diría lo mismo que no se entendió.
            if ($caso['causa'] === 'estado_sin_reconocer') {
                continue;
            }

            $llave = "{$eps}:cruce:{$nit}:{$documento}:{$caso['causa']}";
            $vistas[] = $llave;

            if ($ya = $this->tareas->activaPorLlave((int) $contrato->aliado_id, $llave)) {
                $detalle[$fin]['accion'] = 'ya_existe';
                $detalle[$fin]['tarea_id'] = $ya->id;

                continue;
            }

            if ($simular) {
                $nuevas++;
                $detalle[$fin]['accion'] = 'abriria';

                continue;
            }

            $tarea = $this->tareas->abrir([
                'aliado_id' => (int) $contrato->aliado_id,
                'tipo' => 'mora_eps',
                'cedula' => (string) $contrato->cedula,
                'contrato_id' => $contrato->id,
                'razon_social_id' => $contrato->razon_social_id,
                'entidad' => $conf['nombre'],
                'tarea' => $caso['tarea'],
                'observacion' => $caso['observacion'],
                'llave_auto' => $llave,
            ]);

            if ($tarea) {
                $nuevas++;
                $detalle[$fin]['accion'] = 'abierta';
                $detalle[$fin]['tarea_id'] = $tarea->id;
            }
        }

        return [
            'ok' => true,
            'nit' => $nit,
            'afiliados' => $delPortal->count(),
            'nuevas' => $nuevas,
            'cerradas' => $this->cerrarResueltas($eps, $nit, $vistas, $simular, $detalle),
            // El vocabulario del portal se aprende mirándolo: así la primera
            // corrida de verdad dice qué estados hay que reconocer.
            'estados' => collect($lectura['afiliados'] ?? [])->countBy(fn ($a) => trim((string) $a['estado']) ?: '(vacío)')->all(),
            'detalle' => $detalle,
        ];
    }

    /**
     * Qué pasa con este contrato, si es que pasa algo.
     *
     * @return array{causa:string, tarea?:string, observacion?:string, estado:?string}|null
     */
    private function analizar(array $conf, object $contrato, ?array $enPortal): ?array
    {
        $estado = $enPortal['estado'] ?? null;
        $situacion = $enPortal['situacion'] ?? null;
        $retiro = $contrato->fecha_retiro ? Carbon::parse($contrato->fecha_retiro) : null;
        $vigente = $contrato->estado === 'vigente' && ! $retiro;

        // No aparece en la EPS.
        if (! $enPortal) {
            // Solo se echa de menos a quien BryNex da por afiliado a esta EPS:
            // el que aún tiene el radicado abierto ya está en la lista de
            // trabajo de alguien, y decirlo otra vez es ruido.
            if ($vigente && $contrato->es_de_esta_eps && $contrato->radicado_estado === Radicado::ESTADO_OK) {
                return [
                    'causa' => 'no_esta_en_la_eps',
                    'estado' => null,
                    'tarea' => "En BryNex figura afiliado a {$conf['nombre']} y la EPS no lo tiene: verificar la afiliación.",
                    'observacion' => "El radicado de EPS está en OK desde BryNex, pero {$conf['nombre']} no lo lista entre los afiliados de la empresa"
                        .' al '.now()->format('d/m/Y').'. Si de verdad no está afiliado, está trabajando sin EPS: hay que radicarlo de nuevo.',
                ];
            }

            return null;
        }

        // Aparece y el contrato ya terminó: mientras la afiliación siga viva se
        // sigue cotizando por alguien que no está.
        if ($retiro && $situacion === 'vigente' && $retiro->lte(now()->subDays(self::DIAS_DE_GRACIA))) {
            return [
                'causa' => 'retiro_sin_reportar',
                'estado' => $estado,
                'tarea' => "Reportar a {$conf['nombre']} la finalización de la relación laboral del ".$retiro->format('d/m/Y').'.',
                'observacion' => 'En BryNex el contrato está retirado desde el '.$retiro->format('d/m/Y')
                    ." y en {$conf['nombre']} la afiliación sigue en «".trim((string) $estado).'»'
                    .($enPortal['afiliacion'] ? " (afiliación {$enPortal['afiliacion']})" : '')
                    .'. Radicar la finalización antes de que la EPS siga cobrando el aporte.',
            ];
        }

        // Aparece vigente y el radicado sigue abierto: el trámite ya está hecho.
        if ($situacion === 'vigente' && $vigente && in_array($contrato->radicado_estado, self::ESTADOS_ABIERTOS, true)) {
            return [
                'causa' => 'radicado_confirmado',
                'estado' => $estado,
                'numero' => $enPortal['afiliacion'] ?: null,
                'observacion' => "Ya afiliado en {$conf['nombre']} con la empresa al ".now()->format('d/m/Y')
                    .' («'.trim((string) $estado).'»'
                    .($enPortal['fecha_inicio'] ? ', desde el '.$enPortal['fecha_inicio'] : '').')'
                    .' — conciliación automática del portal.',
            ];
        }

        // Un borrador no afilia a nadie y además estorba: mientras exista, el
        // portal no deja volver a afiliar a esa persona («ya tiene una
        // afiliación en ese plan en estado Borrador»), y solo lo ve y lo anula
        // la empresa que lo dejó. Hay que terminarlo o anularlo a mano.
        if ($situacion === 'borrador' && $vigente) {
            return [
                'causa' => 'afiliacion_en_borrador',
                'estado' => $estado,
                'tarea' => "Terminar o anular la afiliación en borrador de {$conf['nombre']}.",
                'observacion' => "La afiliación quedó **en borrador** en {$conf['nombre']}"
                    .($enPortal['fecha_inicio'] ? ' (inicio '.$enPortal['fecha_inicio'].')' : '')
                    .': así no está afiliado y el portal tampoco deja radicarla de nuevo hasta que se termine o se anule'
                    .' (Consulta afiliaciones → estado Borrador, con la clave de esta empresa).',
            ];
        }

        // Un estado que no se entendió no decide nada, pero se deja ver.
        if ($situacion === 'desconocida' && in_array($contrato->radicado_estado, self::ESTADOS_ABIERTOS, true)) {
            return ['causa' => 'estado_sin_reconocer', 'estado' => $estado, 'accion' => 'revisar'];
        }

        return null;
    }

    /** Deja el radicado en OK con lo que dijo el portal. */
    private function confirmarRadicado(array $conf, object $contrato, array $caso, bool $simular, ?int $usuarioId): string
    {
        if ($simular) {
            return 'cerraria';
        }

        $cerrado = DB::transaction(function () use ($contrato, $caso, $conf, $usuarioId) {
            $fresco = Radicado::whereKey($contrato->radicado_id)->lockForUpdate()->first();

            // Alguien pudo cerrarlo a mano mientras el portal respondía.
            if (! $fresco || ! in_array($fresco->estado, self::ESTADOS_ABIERTOS, true)) {
                return false;
            }

            $anterior = $fresco->estado;
            // El número de afiliación del portal es el que la EPS reconoce; si
            // el radicado no traía ninguno (se cerró a mano, o lo radicó la
            // empresa), se queda con él en vez de dejarlo vacío.
            $fresco->update([
                'estado' => Radicado::ESTADO_OK,
                'numero_radicado' => $fresco->numero_radicado ?: ($caso['numero'] ?? null),
                'canal_envio' => 'portal',
                'fecha_confirmacion' => now(),
                'user_id' => $usuarioId,
                'observacion' => trim(($fresco->observacion ? $fresco->observacion.' | ' : '').$caso['observacion']),
            ] + $fresco->datosConfirmacion(strtolower(str_replace(' ', '_', $conf['nombre']))));

            RadicadoMovimiento::create([
                'radicado_id' => $fresco->id,
                'contrato_id' => $fresco->contrato_id,
                'tipo_proceso' => 'afiliacion',
                'entidad' => Radicado::TIPO_EPS,
                'user_id' => $usuarioId,
                'estado_anterior' => $anterior,
                'estado_nuevo' => Radicado::ESTADO_OK,
                'observacion' => $caso['observacion'],
            ]);

            return true;
        });

        return $cerrado ? 'cerrado' : 'revisar';
    }

    /**
     * Los contratos de esa empresa que tienen algo que ver con esta EPS.
     *
     * Trae también los de otras EPS porque el portal puede tener afiliado a
     * alguien que en BryNex figura en otra parte, y eso es en sí mismo el
     * hallazgo; lo que no se hace es echar de menos a quien nunca fue de aquí.
     */
    private function contratos(string $nit, array $codigosEps)
    {
        return DB::table('contratos as c')
            ->join('razones_sociales as rs', 'rs.id', '=', 'c.razon_social_id')
            ->leftJoin('eps', 'eps.id', '=', 'c.eps_id')
            ->leftJoin('radicados as r', function ($j) {
                $j->on('r.contrato_id', '=', 'c.id')->where('r.tipo', '=', Radicado::TIPO_EPS);
            })
            ->where('rs.nit', $nit)
            // Los muy viejos no dicen nada útil: la EPS ya los depuró.
            ->where(function ($q) {
                $q->whereNull('c.fecha_retiro')->orWhere('c.fecha_retiro', '>=', now()->subMonths(12)->toDateString());
            })
            ->orderByDesc('c.id')
            ->select([
                'c.id', 'c.aliado_id', 'c.cedula', 'c.razon_social_id', 'c.fecha_retiro', 'c.estado',
                'r.id as radicado_id', 'r.estado as radicado_estado',
            ])
            ->selectRaw(
                'CASE WHEN eps.codigo IN ('.implode(',', array_fill(0, count($codigosEps), '?')).') THEN 1 ELSE 0 END as es_de_esta_eps',
                array_values($codigosEps),
            )
            ->get()
            ->unique(fn ($c) => self::documento((string) $c->cedula));
    }

    /**
     * El estado del portal, reducido a lo que hay que decidir.
     *
     * Los cuatro primeros son los que de verdad usa Boxalud, leídos del combo
     * de «Consulta afiliaciones» de Coosalud el 1-oct-2026: Radicado, Borrador,
     * Para radicar pendiente por documentos y Por aprobación de traslado. Van
     * antes que los patrones porque dos de ellos engañan: **«Radicado» es la
     * afiliación hecha**, no una en trámite, y **«Por aprobación de traslado»
     * todavía no afilia a nadie** aunque lleve la palabra «aprobación», que es
     * la que marcaba «vigente» y habría cerrado el radicado antes de tiempo.
     *
     * Los patrones de abajo se quedan para Emssanar y Asmet, que son el mismo
     * software pero nunca se han mirado por dentro; lo que no cuadre con
     * ninguno queda como desconocido a propósito.
     */
    public static function situacion(string $estado): string
    {
        $estado = strtr(mb_strtolower(trim($estado)), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);

        return match (true) {
            $estado === '' => 'desconocida',
            $estado === 'radicado' => 'vigente',
            $estado === 'borrador' => 'borrador',
            str_starts_with($estado, 'para radicar pendiente por documentos') => 'en_tramite',
            str_starts_with($estado, 'por aprobacion de traslado') => 'en_tramite',
            (bool) preg_match('/anulad|rechazad|retirad|finalizad|inactiv|termin|excluid|cancelad|no activ/', $estado) => 'terminada',
            (bool) preg_match('/activ|aprobad|vigente|afiliad|matriculad/', $estado) => 'vigente',
            (bool) preg_match('/radicad|tramit|proceso|pendiente|estudio|revision/', $estado) => 'en_tramite',
            default => 'desconocida',
        };
    }

    private function cerrarResueltas(string $eps, string $nit, array $vistas, bool $simular, array &$detalle): int
    {
        $abiertas = Tarea::whereNotNull('llave_auto')
            ->where('llave_auto', 'like', "{$eps}:cruce:{$nit}:%")
            ->whereIn('estado', Tarea::ESTADOS_ACTIVOS)
            ->get();

        $cerradas = 0;

        foreach ($abiertas as $tarea) {
            if (in_array($tarea->llave_auto, $vistas, true)) {
                continue;
            }

            if ($simular) {
                $cerradas++;
                $detalle[] = ['documento' => (string) $tarea->cedula, 'accion' => 'cerraria', 'tarea_id' => $tarea->id];

                continue;
            }

            if ($this->tareas->cerrar($tarea, 'El portal ya no muestra ese descuadre el '.now()->format('d/m/Y').'.')) {
                $cerradas++;
                $detalle[] = ['documento' => (string) $tarea->cedula, 'accion' => 'cerrada', 'tarea_id' => $tarea->id];
            }
        }

        return $cerradas;
    }

    /** '0001005878149' y '1.005.878.149' son la misma persona. */
    private static function documento(string $cedula): string
    {
        return ltrim(preg_replace('/\D/', '', $cedula), '0');
    }

    /** '01/03/2026' → 20260301, para ordenar sin inventar fechas. */
    private static function orden(?string $fecha): int
    {
        return preg_match('#^(\d{2})/(\d{2})/(\d{4})#', trim((string) $fecha), $m) ? (int) ($m[3].$m[2].$m[1]) : 0;
    }
}
