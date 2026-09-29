<?php

namespace App\Services;

use App\Models\Bitacora;
use App\Models\Contrato;
use App\Models\Factura;
use App\Models\Plano;
use App\Models\TipoModalidad;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Corrección N con valores: subir el salario o los días de una planilla ya
 * pagada. El operador cobra solo la diferencia (más la mora).
 *
 * ## Cómo se arma
 *
 * Es una corrección de CorreccionNovedadesService (tipo_p 16, en su propio
 * número de plano, una N por planilla corregida) con una diferencia: el plano
 * de corrección guarda los valores NUEVOS y apunta con `plano_corregido_id` al
 * plano pagado. PlanoPilaTxtService arma la línea C con el primero y la A con
 * el segundo. El operador compara la A con lo que quedó pagado, no con el
 * archivo, así que la A tiene que ser exactamente la línea pagada.
 *
 * Solo cambian salario, días y fechas de ingreso/retiro. Lo demás (EPS, AFP,
 * subtipo) no se puede cambiar en una N: eo.val.2.085 exige la misma EPS en
 * la A y en la C, y eo.val.2.746 prohíbe cambiar el subtipo.
 *
 * ## Reglas de negocio (decididas el 29-sep-2026)
 *
 * - Solo la planilla del mes de pago en curso: en septiembre, la del período
 *   agosto pagada en septiembre. Nunca más atrás.
 * - Solo se sube. Bajar el salario o los días no devuelve plata: el operador
 *   no reembolsa, se le pide a cada administradora por fuera.
 * - Los días salen de las fechas, no se escriben: menos de 30 días exige una
 *   novedad de ingreso o retiro que los justifique (eo.val.2.151).
 */
class CorreccionPlanillaService
{
    /** Los campos que la corrección puede cambiar: los que la A toma de lo pagado. */
    private const CAMPOS_LINEA_A = ['salario_basico', 'num_dias', 'fecha_ing', 'fecha_ret'];

    /** Las opciones de retiro que ofrecen los portales (Mi Planilla las nombra así). */
    public const TIPOS_RETIRO = [
        'general' => 'general a todos los sistemas',
        'servicios' => 'por terminación del contrato de prestación de servicios',
        'pension' => 'solo a pensión',
        'arl' => 'solo a riesgos laborales',
        'caja' => 'solo a caja de compensación',
    ];

    /**
     * La línea pagada de cada corrección con valores de la tanda, por id del
     * plano pagado.
     *
     * @param  Collection  $planos  filas del TXT (con plano_corregido_id)
     * @return array<int, object>
     */
    public static function lineasPagadas(int $aliadoId, Collection $planos): array
    {
        $ids = $planos->pluck('plano_corregido_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        return DB::table('planos')
            ->where('aliado_id', $aliadoId)
            ->whereIn('id', $ids)
            ->get([
                'id', 'salario_basico', 'num_dias',
                DB::raw('CONVERT(VARCHAR(10), fecha_ing, 23) AS fecha_ing'),
                DB::raw('CONVERT(VARCHAR(10), fecha_ret, 23) AS fecha_ret'),
            ])
            ->keyBy(fn ($p) => (int) $p->id)
            ->all();
    }

    /**
     * La fila de la línea C con los valores de lo que se pagó: es la línea A.
     * Todo lo demás (administradoras, tipo, nivel) es igual en las dos.
     */
    public static function comoLineaA(object $lineaC, object $pagado): object
    {
        $a = clone $lineaC;
        foreach (self::CAMPOS_LINEA_A as $campo) {
            $a->{$campo} = $pagado->{$campo};
        }

        return $a;
    }

    /**
     * El plano que se puede corregir de un contrato: el pagado en el mes de
     * pago en curso.
     *
     * @throws \RuntimeException con el motivo, para mostrarlo tal cual
     */
    public static function planoCorregible(int $aliadoId, int $contratoId, ?Carbon $hoy = null): Plano
    {
        $hoy ??= now();

        $plano = Plano::query()
            ->where('aliado_id', $aliadoId)
            ->where('contrato_id', $contratoId)
            ->whereIn('tipo_reg', ['planilla', 'retiro'])
            ->whereRaw('ISNULL(num_dias, 0) > 0')
            ->whereRaw('ISNULL(tipo_p, 0) <> ?', [CorreccionNovedadesService::TIPO_P])
            ->tap(fn ($q) => Plano::filtrarPeriodoDePago($q, $hoy->month, $hoy->year, null))
            ->orderByDesc('id')
            ->first();

        $mesPago = self::nombreMes($hoy->month).' '.$hoy->year;

        if (! $plano) {
            throw new \RuntimeException("Este contrato no tiene planilla del mes de pago en curso ({$mesPago}). Solo se corrige la última.");
        }

        $numero = trim((string) $plano->numero_planilla);
        if ($numero === '' || strtoupper($numero) === 'EXTERNO') {
            throw new \RuntimeException("La planilla de {$mesPago} todavía no está pagada: no hay nada que corregir.");
        }

        self::validarModalidad($plano);

        $yaCorregida = Plano::query()
            ->where('aliado_id', $aliadoId)
            ->where('contrato_id', $contratoId)
            ->where('mes_plano', $plano->mes_plano)
            ->where('anio_plano', $plano->anio_plano)
            ->where('tipo_p', CorreccionNovedadesService::TIPO_P)
            ->first(['id', 'numero_planilla']);

        if ($yaCorregida) {
            $estado = trim((string) $yaCorregida->numero_planilla) !== ''
                ? "ya tiene una corrección pagada ({$yaCorregida->numero_planilla})"
                : 'ya tiene una corrección pendiente de liquidar';
            throw new \RuntimeException("La planilla {$numero} {$estado}.");
        }

        $pagada = DB::table('gastos')
            ->where('aliado_id', $aliadoId)
            ->where('tipo', 'pago_planilla')
            ->where('numero_planilla', $numero)
            ->exists();

        if (! $pagada) {
            throw new \RuntimeException(
                "La planilla {$numero} no tiene el pago registrado, y la corrección necesita su fecha de pago. "
                .'Confírmelo primero en Planos SS.'
            );
        }

        return $plano;
    }

    /**
     * Días del período según las novedades, con el mes de 30 días de PILA.
     */
    public static function diasPorFechas(int $mes, int $anio, ?string $fechaIng, ?string $fechaRet): int
    {
        $diaIng = self::diaDelPeriodo($fechaIng, $mes, $anio);
        $diaRet = self::diaDelPeriodo($fechaRet, $mes, $anio);

        $desde = $diaIng ?? 1;
        $hasta = $diaRet ?? 30;

        return max(1, $hasta - $desde + 1);
    }

    /**
     * Valida los cambios contra lo pagado y devuelve cómo queda la línea C.
     *
     * @param  array  $cambios  salario_basico, fecha_ing, fecha_ret. Una llave
     *                          ausente deja lo pagado; una fecha en null quita
     *                          la novedad.
     * @return array{salario_basico: int, fecha_ing: ?string, fecha_ret: ?string, num_dias: int, antes: array}
     *
     * @throws \RuntimeException con el motivo, para mostrarlo tal cual
     */
    public static function preparar(Plano $pagado, array $cambios, bool $soloNovedad = false): array
    {
        $mes = (int) $pagado->mes_plano;
        $anio = (int) $pagado->anio_plano;
        $periodo = self::nombreMes($mes).' '.$anio;

        $antes = [
            'salario_basico' => (int) $pagado->salario_basico,
            'fecha_ing' => $pagado->fecha_ing?->toDateString(),
            'fecha_ret' => $pagado->fecha_ret?->toDateString(),
            'num_dias' => (int) $pagado->num_dias,
        ];

        // Solo novedad: la línea C repite salario y días pagados, así que la
        // corrección no cobra nada y se puede marcar un retiro de mitad de mes
        // sin que los días "bajen".
        $salario = ! $soloNovedad && array_key_exists('salario_basico', $cambios)
            ? (int) preg_replace('/\D/', '', (string) $cambios['salario_basico'])
            : $antes['salario_basico'];
        $fechaIng = array_key_exists('fecha_ing', $cambios) ? self::fechaONull($cambios['fecha_ing']) : $antes['fecha_ing'];
        $fechaRet = array_key_exists('fecha_ret', $cambios) ? self::fechaONull($cambios['fecha_ret']) : $antes['fecha_ret'];

        if ($salario < $antes['salario_basico']) {
            throw new \RuntimeException(
                'El salario solo se puede subir: el operador no devuelve lo pagado de más. '
                .'La devolución se pide a cada administradora.'
            );
        }

        // Solo se validan las fechas que se cambian: las que vienen de lo
        // pagado ya pasaron por el operador.
        $cambiaFechas = false;
        foreach (['fecha_ing' => 'ingreso', 'fecha_ret' => 'retiro'] as $campo => $novedad) {
            if (! array_key_exists($campo, $cambios)) {
                continue;
            }
            $cambiaFechas = true;
            $fecha = $campo === 'fecha_ing' ? $fechaIng : $fechaRet;
            if ($fecha && self::diaDelPeriodo($fecha, $mes, $anio) === null) {
                throw new \RuntimeException("La fecha de {$novedad} tiene que ser del período que se corrige ({$periodo}).");
            }
        }

        if ($fechaIng && $fechaRet && $fechaRet < $fechaIng) {
            throw new \RuntimeException('La fecha de retiro no puede ser anterior a la de ingreso.');
        }

        // Los días salen de las fechas solo si se tocan: si no, quedan los
        // pagados (el plano puede traer una fecha vieja que no los explica).
        $dias = $cambiaFechas && ! $soloNovedad
            ? self::diasPorFechas($mes, $anio, $fechaIng, $fechaRet)
            : $antes['num_dias'];

        if ($dias < $antes['num_dias']) {
            throw new \RuntimeException(
                "Con esas fechas quedan {$dias} días y se pagaron {$antes['num_dias']}: los días solo se pueden subir, "
                .'el operador no devuelve lo pagado de más.'
            );
        }

        if ($salario === $antes['salario_basico'] && $dias === $antes['num_dias']
            && $fechaIng === $antes['fecha_ing'] && $fechaRet === $antes['fecha_ret']) {
            throw new \RuntimeException('No hay nada que corregir: salario, días y fechas quedan igual que en la planilla pagada.');
        }

        return [
            'salario_basico' => $salario,
            'fecha_ing' => $fechaIng,
            'fecha_ret' => $fechaRet,
            'num_dias' => $dias,
            'antes' => $antes,
        ];
    }

    /**
     * Crea el plano de corrección: copia de la línea pagada con los valores
     * nuevos, en su propio número de plano. Planos SS lo liquida como planilla
     * N de la planilla pagada.
     *
     * @throws \RuntimeException con el motivo, para mostrarlo tal cual
     */
    public static function crear(Plano $pagado, array $cambios, int $usuarioId, ?int $facturaId = null, bool $soloNovedad = false): Plano
    {
        return DB::transaction(function () use ($pagado, $cambios, $usuarioId, $facturaId, $soloNovedad) {
            // Se revalida adentro: entre abrir el modal y guardar pudo nacer otra corrección.
            $vigente = self::planoCorregible((int) $pagado->aliado_id, (int) $pagado->contrato_id);
            if ((int) $vigente->id !== (int) $pagado->id) {
                throw new \RuntimeException('La planilla que se iba a corregir ya no es la del mes de pago en curso.');
            }

            $nuevo = self::preparar($pagado, $cambios, $soloNovedad);

            $nPlano = CorreccionNovedadesService::siguienteNPlano(
                (int) $pagado->aliado_id,
                (int) ($pagado->razon_social_id ?: 0),
                (int) $pagado->mes_plano,
                (int) $pagado->anio_plano,
                (bool) $pagado->paga_mes_actual
            );

            $datos = collect($pagado->getAttributes())
                ->only((new Plano)->getFillable())
                ->merge([
                    'factura_id' => $facturaId,
                    'numero_factura' => 0,
                    'tipo_reg' => $nuevo['fecha_ret'] ? 'retiro' : 'planilla',
                    'fecha_ing' => $nuevo['fecha_ing'],
                    'fecha_ret' => $nuevo['fecha_ret'],
                    'num_dias' => $nuevo['num_dias'],
                    'salario_basico' => $nuevo['salario_basico'],
                    'n_plano' => $nPlano,
                    'tipo_p' => CorreccionNovedadesService::TIPO_P,
                    'plano_corregido_id' => $pagado->id,
                    'usuario_id' => $usuarioId,
                ])
                ->all();

            return Plano::create($datos);
        });
    }

    /**
     * Las modalidades que se pagan en dos planillas ya usan la N para su paso
     * 2: esas no entran aquí.
     */
    private static function validarModalidad(Plano $plano): void
    {
        $modalidad = (int) $plano->tipo_modalidad_id;

        if ($modalidad === PilaCotizanteCalculator::TIPO_E1 || in_array($modalidad, TipoModalidad::IDS_DOS_PASOS, true)) {
            throw new \RuntimeException('Esta modalidad se paga en dos planillas y ya usa la corrección N: no se puede corregir aparte.');
        }
    }

    /**
     * Lo que el modal de Facturar ofrece cuando escogen un mes ya facturado:
     * la corrección de su planilla, si esa factura es la del mes de pago en
     * curso. Null si el mes escogido es otro (el modal sigue saltando al
     * siguiente, como siempre).
     *
     * @return array{disponible: bool, motivo?: string}|null
     */
    public static function oferta(int $aliadoId, int $contratoId, int $mes, int $anio): ?array
    {
        try {
            $pagado = self::planoCorregible($aliadoId, $contratoId);
        } catch (\RuntimeException $e) {
            // Solo se explica el motivo si el mes escogido es el del pago en curso;
            // cualquier otro mes ni siquiera es candidato.
            return ($mes === now()->month && $anio === now()->year)
                ? ['disponible' => false, 'motivo' => $e->getMessage()]
                : null;
        }

        $factura = DB::table('facturas')->where('id', $pagado->factura_id)->first(['numero_factura', 'mes', 'anio']);
        if (! $factura || (int) $factura->mes !== $mes || (int) $factura->anio !== $anio) {
            return null;
        }

        $numero = trim((string) $pagado->numero_planilla);
        $gasto = DB::table('gastos')
            ->where('aliado_id', $aliadoId)
            ->where('tipo', 'pago_planilla')
            ->where('numero_planilla', $numero)
            ->orderBy('fecha')
            ->first(['fecha', 'pagado_a']);

        $periodo = Carbon::create((int) $pagado->anio_plano, (int) $pagado->mes_plano, 1);
        $pago = Carbon::parse($gasto->fecha);
        $siguiente = $pago->copy()->startOfMonth()->addMonth();
        $limite = MoraClienteService::getNthDiaHabil($siguiente->year, $siguiente->month, 5);

        return [
            'disponible' => true,
            'plano_id' => (int) $pagado->id,
            'factura' => (int) $factura->numero_factura,
            'planilla' => $numero,
            'periodo' => self::nombreMes($periodo->month).' '.$periodo->year,
            'periodo_desde' => $periodo->toDateString(),
            'periodo_hasta' => $periodo->copy()->endOfMonth()->toDateString(),
            'operador' => $gasto->pagado_a ? trim((string) $gasto->pagado_a) : null,
            'operador_con_api' => self::operadorConApi($gasto->pagado_a),
            'fecha_pago' => $pago->toDateString(),
            'dias' => (int) $pagado->num_dias,
            'salario' => (int) $pagado->salario_basico,
            'fecha_ing' => $pagado->fecha_ing?->toDateString(),
            'fecha_ret' => $pagado->fecha_ret?->toDateString(),
            'limite_novedad' => $limite?->toDateString(),
            'motivos_retiro' => DB::table('motivos_retiro')->whereRaw('ISNULL(activo, 1) = 1')->orderBy('id')->get(['id', 'nombre'])
                ->map(fn ($m) => ['id' => (int) $m->id, 'nombre' => $m->nombre])->all(),
        ];
    }

    /**
     * Corrección de solo novedad (hoy, el retiro que faltó): la línea C repite
     * los días y el salario pagados y agrega la novedad, así que el operador la
     * liquida en $0. Deja lo mismo que el traslado de razón social —factura en
     * $0 del mes que se corrige y plano N en su propio número— y retira el
     * contrato con la fecha real, que la pantalla de retiro no deja poner
     * porque ese mes ya está pagado.
     *
     * @param  array  $datos  fecha_ret, tipo_retiro, motivo_retiro_id, observacion
     * @return array{factura: Factura, plano: Plano}
     *
     * @throws \RuntimeException con el motivo, para mostrarlo tal cual
     */
    public static function registrarNovedad(int $aliadoId, int $contratoId, int $planoId, array $datos, int $usuarioId): array
    {
        return DB::transaction(function () use ($aliadoId, $contratoId, $planoId, $datos, $usuarioId) {
            $pagado = self::planoCorregible($aliadoId, $contratoId);
            if ((int) $pagado->id !== $planoId) {
                throw new \RuntimeException('La planilla que se iba a corregir ya no es la del mes de pago en curso. Vuelva a abrir el modal.');
            }

            $contrato = Contrato::where('aliado_id', $aliadoId)->lockForUpdate()->findOrFail($contratoId);
            $facturaPagada = DB::table('facturas')->where('id', $pagado->factura_id)->first(['numero_factura', 'mes', 'anio']);
            $fechaRet = Carbon::parse($datos['fecha_ret'])->toDateString();
            $tipoRetiro = self::TIPOS_RETIRO[$datos['tipo_retiro'] ?? 'general'] ?? self::TIPOS_RETIRO['general'];

            $nota = "Corrección N de la planilla {$pagado->numero_planilla} (factura #{$facturaPagada->numero_factura}, "
                .'período '.self::nombreMes((int) $pagado->mes_plano).' '.$pagado->anio_plano.'): '
                ."retiro {$tipoRetiro} con fecha ".Carbon::parse($fechaRet)->format('d-m-Y').'. Sin valor: los días quedan como se pagaron.'
                .(! empty($datos['observacion']) ? ' '.trim($datos['observacion']) : '');

            // Se crea primero el plano: preparar() valida la fecha y que haya algo que corregir.
            $plano = self::crear($pagado, ['fecha_ret' => $fechaRet], $usuarioId, null, true);

            $factura = Factura::create([
                'aliado_id' => $aliadoId,
                'numero_factura' => 0,
                'tipo' => 'planilla',
                'cedula' => $contrato->cedula,
                'contrato_id' => $contrato->id,
                'razon_social_id' => $pagado->razon_social_id,
                'empresa_id' => null,
                // Mismo mes de cobro que la factura que se corrige.
                'mes' => (int) $facturaPagada->mes,
                'anio' => (int) $facturaPagada->anio,
                'fecha_pago' => now()->toDateString(),
                'estado' => 'pagada',
                'forma_pago' => 'efectivo',
                'valor_efectivo' => 0, 'valor_consignado' => 0, 'valor_prestamo' => 0,
                'dias_cotizados' => (int) $plano->num_dias,
                'v_eps' => 0, 'v_arl' => 0, 'v_afp' => 0, 'v_caja' => 0,
                'total_ss' => 0, 'admon' => 0, 'admin_asesor' => 0,
                'otros_admon' => 0, 'seguro' => 0, 'afiliacion' => 0,
                'mensajeria' => 0, 'otros' => 0, 'mora' => 0, 'iva' => 0,
                'total' => 0,
                'saldo_proximo' => 0,
                'n_plano' => (int) $plano->n_plano,
                'usuario_id' => $usuarioId,
                'observacion' => mb_substr($nota, 0, 500),
            ]);

            $plano->update(['factura_id' => $factura->id]);

            $antes = $contrato->only(['estado', 'fecha_retiro', 'motivo_retiro_id']);
            $contrato->update([
                'estado' => 'retirado',
                'fecha_retiro' => $fechaRet,
                'motivo_retiro_id' => (int) $datos['motivo_retiro_id'],
            ]);

            Bitacora::registrar(
                accion: 'updated',
                modelo: 'Contrato',
                registroId: $contrato->id,
                descripcion: "Retiro por corrección N de la planilla {$pagado->numero_planilla} (Cédula: {$contrato->cedula}). Fecha de retiro: {$fechaRet}.",
                detalle: ['antes' => $antes, 'plano_id' => $plano->id, 'factura_id' => $factura->id, 'tipo_retiro' => $datos['tipo_retiro'] ?? 'general'],
                alidoId: $aliadoId
            );

            return ['factura' => $factura, 'plano' => $plano];
        });
    }

    /** Operadores que BryNex liquida por API: el resto se hace en su portal. */
    public static function operadorConApi(?string $operador): bool
    {
        return in_array(mb_strtolower(trim((string) $operador)), ['arus enlace', 'simple'], true);
    }

    /** Día de la fecha dentro del período (1–30), o null si es de otro mes. */
    private static function diaDelPeriodo(?string $fecha, int $mes, int $anio): ?int
    {
        if (! $fecha) {
            return null;
        }

        $f = Carbon::parse($fecha);
        if ($f->month !== $mes || $f->year !== $anio) {
            return null;
        }

        // Mes de 30 días: el 31, y el último de febrero, cuentan como el 30.
        return $f->isLastOfMonth() ? 30 : min(30, $f->day);
    }

    private static function fechaONull($valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : Carbon::parse($valor)->toDateString();
    }

    private static function nombreMes(int $mes): string
    {
        return ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto',
            'septiembre', 'octubre', 'noviembre', 'diciembre'][$mes];
    }
}
