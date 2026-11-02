<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lo que se cuenta de un aliado en un mes: administración, afiliaciones,
 * clientes activos y WhatsApp. Lo usan el Resumen Consolidado Mensual (lo que
 * ve el aliado) y la página de consumo de BryNex (lo que se le cobra), para
 * que las dos den lo mismo. Antes cada una tenía sus consultas y en oct-2026
 * no coincidía nada: consumo daba 7.901 de administración a GiMave contra
 * 1.099 del consolidado.
 *
 * Todo se resuelve para varios meses a la vez con una tabla derivada de
 * períodos (ver tablaPeriodosSql): por el túnel cada consulta cuesta ~250 ms.
 */
class ConteoMensualAliado
{
    /**
     * Estados de un mensaje de plantilla que WhatsApp aceptó. El estado avanza
     * enviado → entregado → leído con los webhooks: contar solo «enviado»
     * dejaba por fuera casi todo (Fecop oct-2026: 20 contra 451).
     */
    public const ESTADOS_WA_ACEPTADOS = ['enviado', 'entregado', 'leido'];

    /** Un período del informe: mes, año y sus fechas de corte. */
    public static function periodo(int $mes, int $anio): array
    {
        $primerDia = Carbon::create($anio, $mes, 1)->startOfDay();

        return [
            'mes'        => $mes,
            'anio'       => $anio,
            'primer_dia' => $primerDia,
            'ultimo_dia' => $primerDia->copy()->endOfMonth(),
        ];
    }

    public static function clave($anio, $mes): string
    {
        return ((int) $anio) . '-' . ((int) $mes);
    }

    /**
     * Conteos por mes, con la clave "anio-mes":
     *   admon             contratos con factura del mes que entraron antes del mes
     *   traslados         los de admon que se retiraron antes del mes y volvieron
     *                     a entrar este mes con otro contrato (se cuentan una vez)
     *   afiliaciones      colección de contratos (id, cedula, fecha_ingreso) que
     *                     entraron en el mes, con ficha de cliente en el aliado
     *   total_activos     admon + afiliaciones − traslados (los clientes activos)
     *   wa_plantillas     mensajes de plantilla aceptados por WhatsApp en el mes
     *   wa_conversaciones chats en los que el cliente escribió en el mes
     *
     * @param array<int, array{mes:int, anio:int, primer_dia:Carbon, ultimo_dia:Carbon}> $periodos
     */
    public function porPeriodos(int $aid, array $periodos): array
    {
        $tp = self::tablaPeriodosSql($periodos);

        $admon        = $this->admonPorMes($aid, $tp);
        $traslados    = $this->trasladosPorMes($aid, $tp);
        $afiliaciones = $this->afiliacionesPorMes($aid, $periodos);
        $plantillas   = $this->waPlantillasPorMes($aid, $tp);
        $chats        = $this->waConversacionesPorMes($aid, $tp);

        $out = [];
        foreach ($periodos as $p) {
            $k = self::clave($p['anio'], $p['mes']);
            $afil = $afiliaciones->get($k, collect());
            $out[$k] = [
                'admon'             => (int) ($admon[$k] ?? 0),
                'traslados'         => (int) ($traslados[$k] ?? 0),
                'afiliaciones'      => $afil,
                'total_activos'     => (int) ($admon[$k] ?? 0) + $afil->count() - (int) ($traslados[$k] ?? 0),
                'wa_plantillas'     => (int) ($plantillas[$k] ?? 0),
                'wa_conversaciones' => (int) ($chats[$k] ?? 0),
            ];
        }

        return $out;
    }

    /** Los conteos de un solo mes. */
    public function delMes(int $aid, int $mes, int $anio): array
    {
        return $this->porPeriodos($aid, [self::periodo($mes, $anio)])[self::clave($anio, $mes)];
    }

    /**
     * Mensajes de plantilla que salieron en el rango y WhatsApp aceptó: lotes de
     * cobro y de planillas, reenvíos, cobros individuales, recibos y «reabrir
     * conversación». Las campañas de marketing van aparte (módulo marketing).
     */
    public static function waPlantillasQuery(int $aid): Builder
    {
        return DB::table('whatsapp_mensajes as wm')
            ->where('wm.aliado_id', $aid)
            ->where('wm.direccion', 'saliente')
            ->whereNotNull('wm.plantilla_id')
            ->whereIn('wm.estado', self::ESTADOS_WA_ACEPTADOS)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('whatsapp_envios_masivos_detalle as cd')
                    ->join('whatsapp_envios_masivos as ce', 'ce.id', '=', 'cd.envio_id')
                    ->whereColumn('cd.wa_message_id', 'wm.wa_message_id')
                    ->whereNotNull('ce.campana_id');
            });
    }

    /**
     * Mensajes que escribió el cliente. Se cuenta cada chat una vez por mes: el
     * que solo recibió una plantilla ya se cobra en wa_plantillas.
     */
    public static function waEntrantesQuery(int $aid): Builder
    {
        return DB::table('whatsapp_mensajes as wm')
            ->where('wm.aliado_id', $aid)
            ->where('wm.direccion', 'entrante');
    }

    /**
     * Tabla derivada `(VALUES ...) AS m(...)` con los períodos, para que una
     * consulta agrupada por (anio, mes) reemplace a una consulta por mes.
     *
     * Las fechas se materializan aquí, con CAST explícito, en vez de construirse
     * en SQL desde las columnas mes/anio: hay facturas con `mes = 0, anio = 0` y
     * un DATEFROMPARTS sobre ellas aborta la consulta entera.
     *
     * Sólo recibe enteros y fechas generadas por Carbon, nunca entrada del usuario.
     */
    public static function tablaPeriodosSql(array $periodos): string
    {
        $filas = array_map(fn ($p) => sprintf(
            "(%d, %d, CAST('%s' AS date), CAST('%s' AS date), CAST('%s' AS datetime), CAST('%s' AS datetime))",
            $p['anio'],
            $p['mes'],
            $p['primer_dia']->toDateString(),
            $p['ultimo_dia']->toDateString(),
            $p['primer_dia']->toDateTimeString(),
            $p['ultimo_dia']->toDateTimeString()
        ), $periodos);

        return '(VALUES ' . implode(', ', $filas) . ') AS m(anio, mes, ini_d, fin_d, ini_dt, fin_dt)';
    }

    // ── Consultas ────────────────────────────────────────────────────────────

    private function porMes($filas): array
    {
        return collect($filas)
            ->mapWithKeys(fn ($r) => [self::clave($r->anio, $r->mes) => $r->total])
            ->all();
    }

    /** Administración: la factura regular del mes, de contratos que entraron antes. */
    private function admonPorMes(int $aid, string $tp): array
    {
        return $this->porMes(DB::table(DB::raw($tp))
            ->join('facturas as f', function ($j) {
                $j->on('f.mes', '=', 'm.mes')->on('f.anio', '=', 'm.anio');
            })
            ->join('contratos as c', 'c.id', '=', 'f.contrato_id')
            ->where('c.aliado_id', $aid)
            ->where('f.numero_factura', '>', 0)
            ->whereNull('f.deleted_at')
            ->whereColumn('c.fecha_ingreso', '<', 'm.ini_d')
            ->groupBy('m.anio', 'm.mes')
            ->select('m.anio', 'm.mes', DB::raw('COUNT(DISTINCT c.id) as total'))
            ->get());
    }

    /**
     * Contados dos veces: el contrato está en Admon (mes vencido: la factura de
     * este mes cobra el anterior) pero se retiró antes de que el mes empezara, y
     * la misma cédula entró con otro contrato este mes (traslado de razón
     * social, cambio de plan, rotación). La persona sumaba en Admon con el viejo
     * y en Afiliaciones con el nuevo: Fecop en sep-2026 daba 685 activos con 549
     * vigentes por 142 traslados. Solo se resta del total de activos. El que se
     * retiró sin volver no se toca: no está en Afiliaciones.
     */
    private function trasladosPorMes(int $aid, string $tp): array
    {
        return $this->porMes(DB::table(DB::raw($tp))
            ->join('facturas as f', function ($j) {
                $j->on('f.mes', '=', 'm.mes')->on('f.anio', '=', 'm.anio');
            })
            ->join('contratos as c', 'c.id', '=', 'f.contrato_id')
            ->where('c.aliado_id', $aid)
            ->where('f.numero_factura', '>', 0)
            ->whereNull('f.deleted_at')
            ->whereColumn('c.fecha_ingreso', '<', 'm.ini_d')
            ->whereColumn('c.fecha_retiro', '<', 'm.ini_d')
            ->whereExists(function ($sq) use ($aid) {
                $sq->select(DB::raw(1))->from('contratos as n')
                    ->whereColumn('n.cedula', 'c.cedula')
                    ->where('n.aliado_id', $aid)
                    ->whereColumn('n.id', '<>', 'c.id')
                    ->whereColumn('n.fecha_ingreso', '>=', 'm.ini_d')
                    ->whereColumn('n.fecha_ingreso', '<=', 'm.fin_d')
                    // Como en Afiliaciones: solo cuenta si tiene ficha de cliente.
                    ->whereExists(fn ($fi) => $fi->select(DB::raw(1))->from('clientes as cl')
                        ->whereColumn('cl.cedula', 'n.cedula')->where('cl.aliado_id', $aid));
            })
            ->groupBy('m.anio', 'm.mes')
            ->select('m.anio', 'm.mes', DB::raw('COUNT(DISTINCT c.id) as total'))
            ->get());
    }

    /**
     * Afiliaciones: contratos por fecha de ingreso en el mes. Sin ficha de
     * cliente en el aliado no cuentan (contratos que migraron mal del sistema
     * viejo, sin nombre). Se devuelven las filas, no un COUNT, para que el
     * consolidado pueda separar reingresos de altas nuevas.
     */
    private function afiliacionesPorMes(int $aid, array $periodos): Collection
    {
        $desde = collect($periodos)->min(fn ($p) => $p['primer_dia']->toDateString());
        $hasta = collect($periodos)->max(fn ($p) => $p['ultimo_dia']->toDateString());

        return DB::table('contratos as c')
            ->where('c.aliado_id', $aid)
            ->whereExists(fn ($sq) => $sq->select(DB::raw(1))->from('clientes as cl')
                ->whereColumn('cl.cedula', 'c.cedula')->where('cl.aliado_id', $aid))
            ->where('c.fecha_ingreso', '>=', $desde)
            ->where('c.fecha_ingreso', '<=', $hasta)
            ->select('c.id', 'c.cedula', 'c.fecha_ingreso')
            ->get()
            ->groupBy(fn ($a) => self::clave(substr($a->fecha_ingreso, 0, 4), substr($a->fecha_ingreso, 5, 2)));
    }

    private function waPlantillasPorMes(int $aid, string $tp): array
    {
        return $this->porMes(self::waPlantillasQuery($aid)
            ->join(DB::raw($tp), function ($j) {
                $j->on('wm.created_at', '>=', 'm.ini_dt')->on('wm.created_at', '<=', 'm.fin_dt');
            })
            ->groupBy('m.anio', 'm.mes')
            ->select('m.anio', 'm.mes', DB::raw('COUNT(*) as total'))
            ->get());
    }

    private function waConversacionesPorMes(int $aid, string $tp): array
    {
        return $this->porMes(self::waEntrantesQuery($aid)
            ->join(DB::raw($tp), function ($j) {
                $j->on('wm.created_at', '>=', 'm.ini_dt')->on('wm.created_at', '<=', 'm.fin_dt');
            })
            ->groupBy('m.anio', 'm.mes')
            ->select('m.anio', 'm.mes', DB::raw('COUNT(DISTINCT wm.conversacion_id) as total'))
            ->get());
    }
}
