<?php

namespace App\Services;

use App\Models\BrynexCalendarioVencimiento;
use App\Models\BrynexObligacion;
use App\Models\BrynexObligacionCatalogo;
use App\Models\BrynexRazonSocial;
use App\Models\BrynexRazonSocialVinculo;
use Illuminate\Support\Facades\DB;

/**
 * Consolida por NIT lo que en BryNex está repartido por aliado.
 *
 * Todo lo de aquí atraviesa `brynex_razon_social_vinculos`: la ficha conoce su
 * NIT, el vínculo sabe qué filas de `razones_sociales` le corresponden en cada
 * aliado, y de ahí salen los afiliados, el dinero y el checklist.
 *
 * Ojo con la latencia: el SQL Server es remoto y cada consulta cuesta ~250 ms
 * (ver docs y la nota de rendimiento en UsuarioPermisoController). Los métodos
 * de aquí están escritos para hacer UNA consulta por pregunta, no una por
 * razón social.
 */
class BrynexRazonSocialService
{
    /**
     * Ids de razón social por ficha, memorizados dentro de la petición.
     *
     * La ficha los pide dos veces (afiliados y movimientos) y cada viaje al
     * SQL Server cuesta ~250 ms: sin esto, medio segundo tirado por pantalla.
     */
    private array $idsCache = [];

    // ─── Vínculos ─────────────────────────────────────────────────────

    /**
     * Reconstruye los vínculos de la ficha cruzando el NIT contra
     * `razones_sociales`. Es idempotente y se puede correr cuando sea: si un
     * aliado creó hoy una fila nueva con ese mismo NIT, aquí queda enganchada.
     *
     * @return int cuántos vínculos quedaron
     */
    public function sincronizarVinculos(BrynexRazonSocial $ficha): int
    {
        $filas = DB::table('razones_sociales')
            ->where('nit', $ficha->nit)
            ->get(['id', 'aliado_id']);

        $actuales = $ficha->vinculos()->get(['razon_social_id', 'aliado_id'])
            ->keyBy(fn ($v) => (int) $v->razon_social_id);

        $deben = $filas->pluck('id')->map(fn ($id) => (int) $id)->all();

        // Los que sobran: el aliado borró la razón social o le cambió el NIT.
        $sobran = array_diff($actuales->keys()->all(), $deben);
        if ($sobran) {
            $ficha->vinculos()->whereIn('razon_social_id', $sobran)->delete();
        }

        // Solo se escribe lo que cambió. Este método corre en CADA apertura de
        // la ficha, y el caso normal es que no haya cambiado nada: con
        // updateOrCreate por fila serían dos consultas por vínculo cada vez.
        $nuevos = [];
        foreach ($filas as $fila) {
            $previo = $actuales->get((int) $fila->id);

            if ($previo && (int) $previo->aliado_id === (int) $fila->aliado_id) {
                continue;
            }

            $nuevos[] = [
                'ficha_id' => $ficha->id,
                'razon_social_id' => (int) $fila->id,
                'aliado_id' => (int) $fila->aliado_id,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($nuevos) {
            // Los que cambiaron de aliado se reemplazan; el resto es alta nueva.
            $ficha->vinculos()
                ->whereIn('razon_social_id', array_column($nuevos, 'razon_social_id'))
                ->delete();
            BrynexRazonSocialVinculo::insert($nuevos);
        }

        // Ya se sabe cuáles son: sembrar la memoria evita que el siguiente
        // razonSocialIds() vuelva a preguntarle lo mismo a la base.
        $this->idsCache[$ficha->id] = $deben;

        return count($deben);
    }

    /** Los ids de `razones_sociales` que representan a esta ficha. */
    public function razonSocialIds(BrynexRazonSocial $ficha): array
    {
        return $this->idsCache[$ficha->id] ??= $ficha->vinculos()
            ->pluck('razon_social_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    // ─── Afiliados ────────────────────────────────────────────────────

    /**
     * Cuántos afiliados vigentes tiene la razón social, sin importar el
     * aliado. Devuelve el desglose por aliado más el total.
     *
     * Este es el número que no se puede ver desde el panel de un aliado:
     * ELITES CREACIONES tiene 214 vigentes en Grupo Fecop y 143 en BRYGAR, y
     * ante la ley son 357 personas en la misma empresa.
     */
    public function afiliadosActivos(BrynexRazonSocial $ficha): array
    {
        $ids = $this->razonSocialIds($ficha);

        if (! $ids) {
            return ['total' => 0, 'por_aliado' => collect()];
        }

        $filas = DB::table('contratos as c')
            ->join('aliados as a', 'a.id', '=', 'c.aliado_id')
            ->whereIn('c.razon_social_id', $ids)
            ->where('c.estado', 'vigente')
            ->groupBy('c.aliado_id', 'a.nombre')
            ->orderByRaw('COUNT(*) DESC')
            ->get([
                'c.aliado_id',
                'a.nombre as aliado',
                DB::raw('COUNT(*) as total'),
            ]);

        return [
            'total' => (int) $filas->sum(fn ($f) => (int) $f->total),
            'por_aliado' => $filas,
        ];
    }

    // ─── Dinero ───────────────────────────────────────────────────────

    /**
     * Cuentas bancarias que pertenecen a esta razón social.
     *
     * Se resuelven por dos vías, a propósito:
     *
     *  1. `banco_cuentas.razon_social_id`, que un humano puede corregir a mano.
     *     Es la única forma de enganchar una cuenta cuyo campo `nit` está en
     *     blanco o trae un celular en vez del NIT (los Nequi y Daviplata).
     *  2. El NIT normalizado. `banco_cuentas.nit` es texto libre y viene tanto
     *     '901175588' como '901175588-8', así que se compara solo la parte
     *     numérica antes del guion.
     *
     * Se cargan las 79 cuentas de una sola vez y se filtra en PHP: sale más
     * barato que armar la normalización en SQL Server.
     */
    public function cuentasDe(BrynexRazonSocial $ficha): array
    {
        $vinculadas = $this->razonSocialIds($ficha);

        return DB::table('banco_cuentas')
            ->get(['id', 'aliado_id', 'nombre', 'nit', 'banco', 'numero_cuenta', 'razon_social_id'])
            ->filter(function ($cuenta) use ($ficha, $vinculadas) {
                if ($cuenta->razon_social_id && in_array((int) $cuenta->razon_social_id, array_map('intval', $vinculadas), true)) {
                    return true;
                }

                return $this->nitNormalizado($cuenta->nit) === (string) $ficha->nit;
            })
            ->values()
            ->all();
    }

    /** '901175588-8' → '901175588'. Null y basura devuelven cadena vacía. */
    private function nitNormalizado(?string $nit): string
    {
        if (! $nit) {
            return '';
        }

        // Se queda con lo de antes del guion y solo con dígitos.
        $base = preg_replace('/\D/', '', explode('-', trim($nit))[0]);

        // Un NIT de empresa tiene 9 o 10 dígitos. Lo demás (celulares de
        // Nequi, cédulas, '333333') no se cruza: daría falsos positivos.
        return strlen((string) $base) >= 9 && strlen((string) $base) <= 10 ? (string) $base : '';
    }

    /** El gasto con el que se paga la planilla: seguridad social de terceros. */
    private const TIPO_PLANILLA = 'pago_planilla';

    /** Lo único que se le factura a la DIAN: administración más afiliación. */
    private const BASE = 'ISNULL(CAST(f.admon AS BIGINT), 0) + ISNULL(CAST(f.afiliacion AS BIGINT), 0)';

    /**
     * Si la factura ya tiene su electrónica emitida en Dataico.
     *
     * Llega por un join y no por un `EXISTS` porque SQL Server no deja sumar
     * una expresión que contenga una subconsulta. El estado importa: un intento
     * que la DIAN rechazó también deja fila en `dataico_envios`, y contarlo
     * como facturado es justo lo contrario de lo que la columna quiere decir.
     */
    private const EMITIDA = 'fe.numero_factura IS NOT NULL';

    /**
     * Movimientos del año: qué entró y qué salió de las cuentas de la razón
     * social, mes a mes.
     *
     * Entradas = `consignaciones` (lo que consignaron los clientes).
     * Salidas  = `gastos` pagados desde esas cuentas (`banco_origen_id`).
     * Base     = administración + afiliación de las facturas que se pagaron
     *            por esas cuentas: lo único que se le factura a la DIAN.
     *
     * Ojo con lo que las entradas NO son: la mayor parte de lo que entra a una
     * razón social de estas es plata de los afiliados para pagar su seguridad
     * social, no ingreso de la empresa — por eso al lado va la columna de
     * terceros, que es esa misma plata saliendo. Sirve para conciliar contra el
     * extracto, no como base gravable. La base gravable es la de admón más
     * afiliación, entre un 15 y un 25 % de las entradas.
     *
     * Enero a abril de 2026 salían en cero porque la migración no trajo el
     * `banco_cuenta_id` de las consignaciones. Eso ya no se arregla aquí sino
     * en el dato: `consignaciones:vincular-legacy` les devolvió la cuenta y la
     * factura leyendo `Brygar_BD`, así que esos meses ahora se calculan como
     * todos los demás.
     */
    public function movimientos(BrynexRazonSocial $ficha, int $anio): array
    {
        $cuentas = $this->cuentasDe($ficha);
        $ids = array_column($cuentas, 'id');

        $vacio = [
            'cuentas' => $cuentas,
            'meses' => $this->mesesEnCero(),
            'total_entradas' => 0.0,
            'total_salidas' => 0.0,
            'total_base' => 0.0,
            'neto' => 0.0,
            'por_aliado' => collect(),
        ];

        if (! $ids) {
            return $vacio;
        }

        $entradas = DB::table('consignaciones')
            ->whereIn('banco_cuenta_id', $ids)
            ->whereYear('fecha', $anio)
            ->whereNull('deleted_at')
            ->groupBy(DB::raw('MONTH(fecha)'))
            ->get([
                DB::raw('MONTH(fecha) as mes'),
                DB::raw('SUM(valor) as total'),
                DB::raw('COUNT(*) as cuantas'),
            ]);

        // La plata que sale se parte en dos porque son cosas distintas.
        //
        // `pago_planilla` es la seguridad social de los afiliados: plata que
        // entró para pagarle a las EPS, las ARL y los fondos, y que solo está
        // de paso. Es la contrapartida de casi toda la columna de entradas, y
        // mezclarla con el arriendo y los salarios esconde lo único que
        // interesa mirar: cuánto de lo que se movió era de la empresa.
        $salidas = DB::table('gastos')
            ->whereIn('banco_origen_id', $ids)
            ->whereYear('fecha', $anio)
            ->groupBy(DB::raw('MONTH(fecha)'), 'tipo')
            ->get([
                DB::raw('MONTH(fecha) as mes'),
                'tipo',
                DB::raw('SUM(valor) as total'),
                DB::raw('COUNT(*) as cuantas'),
            ]);

        $meses = $this->mesesEnCero();

        foreach ($entradas as $fila) {
            $meses[(int) $fila->mes]['entradas'] = (float) $fila->total;
            $meses[(int) $fila->mes]['n_entradas'] = (int) $fila->cuantas;
        }

        foreach ($salidas as $fila) {
            $columna = $fila->tipo === self::TIPO_PLANILLA ? 'terceros' : 'salidas';
            $meses[(int) $fila->mes][$columna] += (float) $fila->total;
            $meses[(int) $fila->mes]['n_'.$columna] += (int) $fila->cuantas;
        }

        foreach ($this->detalleSalidas($ids, $anio) as $mes => $filas) {
            $meses[$mes]['detalle_salidas'] = $filas;
        }

        foreach ($this->baseFacturada($cuentas, $anio) as $mes => $fila) {
            $meses[$mes]['base'] = $fila['base'];
            $meses[$mes]['n_base'] = $fila['n'];
            $meses[$mes]['facturado'] = $fila['facturado'];
            $meses[$mes]['n_facturado'] = $fila['n_facturado'];
        }

        // Un mes con entradas y sin una sola salida no tiene neto, y no se
        // inventa.
        //
        // Los gastos migrados llegaron sin `banco_origen_id`: existen —83 en
        // enero por 78 millones— pero no dicen de qué cuenta salieron, y no hay
        // forma de repartirlos entre las cuentas de la razón social. Restarle
        // cero a una entrada real da un neto falso y un acumulado que arrastra
        // el error hasta diciembre, así que esos meses van en blanco y el
        // acumulado arranca donde los datos vuelven a estar completos.
        $acumulado = 0.0;
        foreach ($meses as $m => $datos) {
            if ($datos['entradas'] > 0.0 && $datos['salidas'] + $datos['terceros'] == 0.0) {
                $meses[$m]['neto'] = null;
                $meses[$m]['acumulado'] = null;
                $meses[$m]['salidas_incompletas'] = true;

                continue;
            }

            $neto = $datos['entradas'] - $datos['terceros'] - $datos['salidas'];
            $acumulado += $neto;
            $meses[$m]['neto'] = $neto;
            $meses[$m]['acumulado'] = $acumulado;
        }

        // Quién consignó: el desglose por aliado es lo que muestra que la
        // cuenta la mueven varios.
        $porAliado = DB::table('consignaciones as c')
            ->join('aliados as a', 'a.id', '=', 'c.aliado_id')
            ->whereIn('c.banco_cuenta_id', $ids)
            ->whereYear('c.fecha', $anio)
            ->whereNull('c.deleted_at')
            ->groupBy('c.aliado_id', 'a.nombre')
            ->orderByRaw('SUM(c.valor) DESC')
            ->get([
                'a.nombre as aliado',
                DB::raw('SUM(c.valor) as total'),
                DB::raw('COUNT(*) as cuantas'),
            ]);

        $totalEntradas = array_sum(array_column($meses, 'entradas'));
        $totalTerceros = array_sum(array_column($meses, 'terceros'));
        $totalSalidas = array_sum(array_column($meses, 'salidas'));

        return [
            'cuentas' => $cuentas,
            'meses' => $meses,
            'total_entradas' => $totalEntradas,
            'total_terceros' => $totalTerceros,
            'total_salidas' => $totalSalidas,
            'total_base' => array_sum(array_column($meses, 'base')),
            'total_facturado' => array_sum(array_column($meses, 'facturado')),
            'neto' => $totalEntradas - $totalTerceros - $totalSalidas,
            'neto_parcial' => (bool) array_filter(array_column($meses, 'salidas_incompletas')),
            'por_aliado' => $porAliado,
        ];
    }

    /**
     * Uno por uno, los gastos que forman la columna de salidas.
     *
     * Van precargados y no por AJAX porque son pocos —veinte en todo 2026 para
     * la cuenta de BRYGAR— y una consulta más al abrir la ficha pesa menos que
     * una ruta nueva con su permiso y su control de aliado. Los pagos de
     * planilla quedan fuera a propósito: son cientos y tienen su propia
     * columna.
     *
     * @param  array<int,int>  $ids
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function detalleSalidas(array $ids, int $anio): array
    {
        return DB::table('gastos as g')
            ->leftJoin('users as u', 'u.id', '=', 'g.usuario_id')
            ->whereIn('g.banco_origen_id', $ids)
            ->whereYear('g.fecha', $anio)
            ->where('g.tipo', '!=', self::TIPO_PLANILLA)
            ->orderBy('g.fecha')
            ->orderByDesc('g.valor')
            ->get([
                DB::raw('MONTH(g.fecha) as mes'),
                DB::raw('CONVERT(varchar(10), g.fecha, 23) as fecha'),
                'g.tipo', 'g.valor', 'g.descripcion', 'g.pagado_a', 'g.observacion',
                'u.nombre as usuario',
            ])
            ->groupBy('mes')
            // `valor` se castea porque el driver de SQL Server devuelve los
            // números como texto, y al otro lado hay un `Intl.NumberFormat`.
            ->map(fn ($g) => $g->map(fn ($x) => [
                'fecha' => $x->fecha,
                'tipo' => $x->tipo,
                'valor' => (int) $x->valor,
                'descripcion' => $x->descripcion,
                'pagado_a' => $x->pagado_a,
                'observacion' => $x->observacion,
                'usuario' => $x->usuario,
            ])->values()->all())
            ->all();
    }

    /**
     * Administración + afiliación de las facturas que se pagaron por estas
     * cuentas, mes a mes. Es exactamente la base que se le sube a Dataico.
     *
     * Se agrupa por `fecha_pago` y no por la fecha de la consignación porque
     * así es como la emisión arma los meses: una factura se emite en el mes en
     * que se pagó. Y la plata llega por dos caminos — la consignación del
     * momento de facturar y el abono posterior contra un préstamo — así que
     * mirar solo consignaciones dejaba los préstamos fuera.
     *
     * @param  array<int,object>  $cuentas
     * @return array<int,array{base:float,n:int}>
     */
    private function baseFacturada(array $cuentas, int $anio): array
    {
        $ids = array_column($cuentas, 'id');
        $aliados = array_values(array_unique(array_map(fn ($c) => (int) $c->aliado_id, $cuentas)));

        $emitidas = DB::table('dataico_envios')
            ->where('estado', 'enviado')
            ->distinct()
            ->select('aliado_id', 'numero_factura');

        // Los grupos que pasaron por la cuenta, no las facturas sueltas.
        //
        // La electrónica se emite por `numero_factura`: una empresa con cinco
        // afiliados son cinco facturas y una sola factura electrónica por el
        // total. El pago, en cambio, entra como una consignación colgada de una
        // de las cinco. Mirar factura por factura contaba una de cinco y dejaba
        // la columna por debajo de lo que de verdad se le sube a la DIAN —en
        // junio, 11,6 millones donde son 16,4.
        $grupos = DB::table('facturas as g')
            ->whereIn('g.aliado_id', $aliados)
            ->whereNull('g.deleted_at')
            ->whereYear('g.fecha_pago', $anio)
            ->where(function ($w) use ($ids) {
                $w->whereExists(fn ($s) => $s->select(DB::raw(1))->from('consignaciones as cs')
                    ->whereColumn('cs.factura_id', 'g.id')->whereIn('cs.banco_cuenta_id', $ids)
                    ->whereNull('cs.deleted_at'))
                    ->orWhereExists(fn ($s) => $s->select(DB::raw(1))->from('abonos as ab')
                        ->whereColumn('ab.factura_id', 'g.id')->whereIn('ab.banco_cuenta_id', $ids));
            })
            ->distinct()
            ->select('g.aliado_id', 'g.numero_factura');

        $filas = DB::table('facturas as f')
            ->joinSub($grupos, 'gr', fn ($j) => $j
                ->on('gr.aliado_id', '=', 'f.aliado_id')
                ->on('gr.numero_factura', '=', 'f.numero_factura'))
            ->leftJoinSub($emitidas, 'fe', fn ($j) => $j
                ->on('fe.aliado_id', '=', 'f.aliado_id')
                ->on('fe.numero_factura', '=', 'f.numero_factura'))
            ->whereIn('f.aliado_id', $aliados)
            ->whereNull('f.deleted_at')
            ->whereYear('f.fecha_pago', $anio)
            ->groupBy(DB::raw('MONTH(f.fecha_pago)'))
            ->get([
                DB::raw('MONTH(f.fecha_pago) as mes'),
                DB::raw('SUM('.self::BASE.') as base'),
                DB::raw('COUNT(DISTINCT f.numero_factura) as cuantas'),
                // Lo mismo, pero solo de lo que ya tiene factura electrónica.
                // El envío es por grupo y el grupo es el número de factura, así
                // que la cuenta de emitidas va por número y no por fila.
                DB::raw('SUM(CASE WHEN '.self::EMITIDA.' THEN '.self::BASE.' ELSE 0 END) as facturado'),
                DB::raw('COUNT(DISTINCT CASE WHEN '.self::EMITIDA.' THEN f.numero_factura END) as n_facturado'),
            ]);

        $salida = [];
        foreach ($filas as $f) {
            $salida[(int) $f->mes] = [
                'base' => (float) $f->base,
                'n' => (int) $f->cuantas,
                'facturado' => (float) $f->facturado,
                'n_facturado' => (int) $f->n_facturado,
            ];
        }

        return $salida;
    }

    private function mesesEnCero(): array
    {
        $meses = [];
        for ($m = 1; $m <= 12; $m++) {
            $meses[$m] = [
                'entradas' => 0.0, 'terceros' => 0.0, 'salidas' => 0.0,
                'base' => 0.0, 'facturado' => 0.0,
                'n_entradas' => 0, 'n_terceros' => 0, 'n_salidas' => 0,
                'n_base' => 0, 'n_facturado' => 0,
                'neto' => 0.0, 'acumulado' => 0.0,
                'salidas_incompletas' => false, 'detalle_salidas' => [],
            ];
        }

        return $meses;
    }

    // ─── Checklist ────────────────────────────────────────────────────

    /**
     * Genera los renglones que le faltan a la ficha, desde el año de
     * constitución hasta el año en curso.
     *
     * Nunca toca un renglón existente: si el contador ya lo marcó como pagada,
     * se queda como está aunque cambie el régimen. Lo único que hace es crear
     * lo que falta.
     *
     * @return int cuántos renglones nuevos creó
     */
    public function generarObligaciones(BrynexRazonSocial $ficha, ?int $soloAnio = null): int
    {
        if (! $ficha->regimen || ! $ficha->fecha_constitucion) {
            return 0;
        }

        $desde = $soloAnio ?? $ficha->fecha_constitucion->year;
        $hasta = $soloAnio ?? (int) now()->year;

        $catalogo = BrynexObligacionCatalogo::where('activo', true)->orderBy('orden')->get();
        $digito = $ficha->ultimoDigitoNit();

        // Lo que ya existe, de una sola consulta: evita un SELECT por renglón.
        $existentes = $ficha->obligaciones()
            ->whereBetween('anio', [$desde, $hasta])
            ->get(['anio', 'obligacion_codigo', 'periodo'])
            ->map(fn ($o) => "{$o->anio}|{$o->obligacion_codigo}|{$o->periodo}")
            ->flip();

        // El calendario completo del rango, también de una sola consulta.
        $calendario = BrynexCalendarioVencimiento::whereBetween('anio', [$desde, $hasta])
            ->where(fn ($q) => $q->where('ultimo_digito', $digito)->orWhereNull('ultimo_digito'))
            ->get()
            ->keyBy(fn ($c) => "{$c->anio}|{$c->obligacion_codigo}|{$c->periodo}");

        $nuevos = [];

        for ($anio = $desde; $anio <= $hasta; $anio++) {
            foreach ($catalogo as $obligacion) {
                if (! $obligacion->aplicaA($ficha)) {
                    continue;
                }

                for ($periodo = 1; $periodo <= $obligacion->periodosPorAnio(); $periodo++) {
                    $llave = "{$anio}|{$obligacion->codigo}|{$periodo}";

                    if ($existentes->has($llave)) {
                        continue;
                    }

                    if ($this->periodoAnteriorALaConstitucion($ficha, $obligacion, $anio, $periodo)) {
                        continue;
                    }

                    $nuevos[] = [
                        'ficha_id' => $ficha->id,
                        'anio' => $anio,
                        'obligacion_codigo' => $obligacion->codigo,
                        'periodo' => $periodo,
                        'periodo_etiqueta' => $obligacion->etiquetaPeriodo($periodo),
                        // Null en los años sin calendario cargado: el renglón
                        // existe para poder chulear y subir el soporte, pero
                        // no entra al semáforo ni dispara alertas.
                        'fecha_vencimiento' => $calendario->get($llave)?->fecha_vencimiento?->toDateString(),
                        'estado' => 'pendiente',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }
        }

        foreach (array_chunk($nuevos, 200) as $lote) {
            BrynexObligacion::insert($lote);
        }

        return count($nuevos);
    }

    /**
     * No tiene sentido pedirle a una empresa constituida en septiembre el
     * anticipo del primer bimestre de ese año.
     */
    private function periodoAnteriorALaConstitucion(
        BrynexRazonSocial $ficha,
        BrynexObligacionCatalogo $obligacion,
        int $anio,
        int $periodo
    ): bool {
        if ($anio !== $ficha->fecha_constitucion->year) {
            return false;
        }

        $mesConstitucion = $ficha->fecha_constitucion->month;

        // Último mes que cubre el período.
        $mesFinal = match ($obligacion->periodicidad) {
            'mensual' => $periodo,
            'bimestral' => $periodo * 2,
            'cuatrimestral' => $periodo * 4,
            default => 12,
        };

        return $mesFinal < $mesConstitucion;
    }
}
