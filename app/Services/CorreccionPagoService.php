<?php

namespace App\Services;

use App\Models\BancoCuenta;
use App\Models\Bitacora;
use App\Models\Consignacion;
use App\Models\Cuadre;
use App\Models\Factura;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Corrige CÓMO y CUÁNDO entró la plata de un recibo ya pagado: forma de pago
 * (efectivo / consignación / mixto), las consignaciones y la fecha de pago.
 *
 * Existe porque anular y volver a facturar —lo que hacían antes— ya no se
 * puede cuando el recibo tiene planilla pagada al operador (oct-2026,
 * Formalizate), y tampoco debería: la planilla ya está pagada.
 *
 * Reglas, todas a propósito:
 *  - Opera sobre el RECIBO completo (todo el numero_factura): las
 *    consignaciones reales cuelgan de la primera fila y las demás solo llevan
 *    su parte proporcional.
 *  - El total pagado NO cambia. Solo se reparte distinto entre efectivo y
 *    banco, fila por fila, así que saldo_proximo, mora y planilla quedan igual.
 *  - Si el día de caja de quien facturó ya está cuadrado (antes o después del
 *    cambio), se bloquea: primero se reabre el día.
 *  - Una consignación ya validada contra el extracto no se toca.
 *  - Préstamos fuera: ahí no entró plata y tienen su propio flujo.
 *  - Todo queda en la bitácora con el antes, el después y el motivo.
 */
class CorreccionPagoService
{
    /** Filas del recibo al que pertenece la factura, ordenadas por id. */
    public static function filasRecibo(int $aliadoId, int $facturaId, bool $bloquear = false): Collection
    {
        $factura = Factura::where('aliado_id', $aliadoId)->findOrFail($facturaId);

        $q = Factura::where('aliado_id', $aliadoId);
        $factura->numero_factura
            ? $q->where('numero_factura', $factura->numero_factura)
            : $q->where('id', $factura->id);

        if ($bloquear) {
            $q->lockForUpdate();
        }

        return $q->orderBy('id')->get();
    }

    /**
     * Lo que necesita el modal: el pago actual, las cuentas y, si no se puede
     * corregir, por qué.
     */
    public static function estado(int $aliadoId, int $facturaId): array
    {
        $filas = self::filasRecibo($aliadoId, $facturaId);
        $consignaciones = self::consignacionesDe($filas);

        $efectivo = (int) $filas->sum(fn ($f) => (int) $f->valor_efectivo);
        $consignado = (int) $filas->sum(fn ($f) => (int) $f->valor_consignado);

        $cuentas = BancoCuenta::paraFacturacion($aliadoId)
            ->merge(BancoCuenta::where('aliado_id', $aliadoId)
                ->whereIn('id', $consignaciones->pluck('banco_cuenta_id')->filter())->get())
            ->unique('id')
            ->map(fn ($b) => ['id' => (int) $b->id, 'nombre' => trim("{$b->banco} · {$b->nombre}", ' ·')])
            ->values();

        $bloqueo = self::bloqueoGeneral($filas)
            ?? self::bloqueoCuadre($aliadoId, $filas, [$filas->first()->fecha_pago?->toDateString()]);

        return [
            'numero_factura' => $filas->first()->numero_factura,
            'filas' => $filas->count(),
            'forma_pago' => $filas->first()->forma_pago,
            'fecha_pago' => $filas->first()->fecha_pago?->toDateString(),
            'total_pagado' => $efectivo + $consignado,
            'efectivo' => $efectivo,
            'consignado' => $consignado,
            'consignaciones' => $consignaciones->map(fn ($c) => [
                'id' => (int) $c->id,
                'banco_cuenta_id' => (int) $c->banco_cuenta_id,
                'fecha' => $c->fecha?->toDateString(),
                'valor' => (int) $c->valor,
                'referencia' => $c->referencia,
                'bloqueada' => self::consignacionBloqueada($c),
            ])->values(),
            'cuentas' => $cuentas,
            'bloqueo' => $bloqueo,
        ];
    }

    /**
     * Aplica la corrección. Lanza \RuntimeException con un mensaje para el
     * usuario si algo no cuadra; en ese caso no se escribe nada.
     *
     * @param  array{forma_pago:string, fecha_pago:?string, valor_efectivo:int,
     *               consignaciones:array, motivo:string}  $datos
     */
    public static function aplicar(int $aliadoId, int $facturaId, array $datos, int $usuarioId): array
    {
        return DB::transaction(function () use ($aliadoId, $facturaId, $datos, $usuarioId) {
            $filas = self::filasRecibo($aliadoId, $facturaId, true);

            if ($msg = self::bloqueoGeneral($filas)) {
                throw new \RuntimeException($msg);
            }

            $forma = $datos['forma_pago'];
            $hoy = now()->toDateString();
            $totalPagado = (int) $filas->sum(fn ($f) => (int) $f->valor_efectivo + (int) $f->valor_consignado);

            // ── Consignaciones pedidas ───────────────────────────────────
            $pedidas = collect($forma === 'efectivo' ? [] : ($datos['consignaciones'] ?? []))
                ->map(fn ($c) => [
                    'id' => ! empty($c['id']) ? (int) $c['id'] : null,
                    'banco_cuenta_id' => (int) ($c['banco_cuenta_id'] ?? 0),
                    'fecha' => Carbon::parse($c['fecha'])->toDateString(),
                    'valor' => (int) $c['valor'],
                    'referencia' => trim((string) ($c['referencia'] ?? '')) ?: null,
                ])
                ->filter(fn ($c) => $c['valor'] > 0)
                ->values();

            $cuentasValidas = BancoCuenta::where('aliado_id', $aliadoId)->pluck('id')->map(fn ($id) => (int) $id);
            foreach ($pedidas as $c) {
                if (! $cuentasValidas->contains($c['banco_cuenta_id'])) {
                    throw new \RuntimeException('Hay una consignación sin cuenta o con una cuenta que no es de este aliado.');
                }
                if ($c['fecha'] > $hoy) {
                    throw new \RuntimeException('Una consignación no puede tener fecha futura.');
                }
            }

            $consignado = (int) $pedidas->sum('valor');
            $efectivo = $forma === 'consignacion' ? 0 : (int) $datos['valor_efectivo'];
            if ($forma === 'efectivo') {
                $efectivo = $totalPagado;
            }

            if ($forma === 'consignacion' && $consignado <= 0) {
                throw new \RuntimeException('Agrega al menos una consignación.');
            }
            if ($forma === 'mixto' && ($consignado <= 0 || $efectivo <= 0)) {
                throw new \RuntimeException('Un pago mixto lleva efectivo y al menos una consignación.');
            }
            if ($efectivo + $consignado !== $totalPagado) {
                throw new \RuntimeException('El pago debe sumar lo mismo que antes: $'.number_format($totalPagado, 0, ',', '.')
                    .'. Ahora suma $'.number_format($efectivo + $consignado, 0, ',', '.').'.');
            }

            // ── Fecha de pago ────────────────────────────────────────────
            // Misma regla que al facturar (_fechaPagoRecibo): solo consignación →
            // el día en que entró al banco (la más reciente); con efectivo → el
            // día en que se recibió, que es el que cuenta en la caja.
            $fechaPago = $forma === 'consignacion'
                ? $pedidas->max('fecha')
                : Carbon::parse($datos['fecha_pago'] ?? $hoy)->toDateString();
            if ($fechaPago > $hoy) {
                throw new \RuntimeException('La fecha de pago no puede ser futura.');
            }

            $fechaAntes = $filas->first()->fecha_pago?->toDateString();
            if ($msg = self::bloqueoCuadre($aliadoId, $filas, [$fechaAntes, $fechaPago])) {
                throw new \RuntimeException($msg);
            }

            // ── Consignaciones existentes: se conservan, se cambian o se quitan ──
            $existentes = self::consignacionesDe($filas)->keyBy('id');

            $sinCambios = $forma === $filas->first()->forma_pago
                && $fechaPago === $fechaAntes
                && $existentes->count() === $pedidas->count()
                && $pedidas->every(fn ($c) => $c['id'] && $existentes->has($c['id']) && self::igual($existentes->get($c['id']), $c));
            if ($sinCambios) {
                throw new \RuntimeException('No hay nada que cambiar: el pago ya está así.');
            }
            $antesConsig = $existentes->map(fn ($c) => $c->only(['id', 'factura_id', 'banco_cuenta_id', 'fecha', 'valor', 'referencia', 'confirmado']))->values()->all();

            $seQuedan = [];
            $aCrear = [];
            foreach ($pedidas as $c) {
                $vieja = $c['id'] ? $existentes->get($c['id']) : null;
                if ($c['id'] && ! $vieja) {
                    throw new \RuntimeException('Una de las consignaciones ya no pertenece a este recibo. Recarga e intenta de nuevo.');
                }
                if ($vieja && self::igual($vieja, $c)) {
                    $seQuedan[] = (int) $vieja->id;

                    continue;
                }
                if ($vieja && self::consignacionBloqueada($vieja)) {
                    throw new \RuntimeException('La consignación de $'.number_format((int) $vieja->valor, 0, ',', '.')
                        .' ya está validada contra el banco y no se puede cambiar.');
                }
                $aCrear[] = $c + ['imagen_path' => $vieja?->imagen_path, 'observacion' => $vieja?->observacion];
            }

            foreach ($existentes as $vieja) {
                if (in_array((int) $vieja->id, $seQuedan, true)) {
                    continue;
                }
                if (self::consignacionBloqueada($vieja)) {
                    throw new \RuntimeException('La consignación de $'.number_format((int) $vieja->valor, 0, ',', '.')
                        .' ya está validada contra el banco: no se puede quitar.');
                }
                $vieja->delete();
            }

            // Las nuevas van en la primera fila del recibo, como al facturar.
            // Se crean (no se actualizan) para que el observer de facturación
            // electrónica vea la plata que entra a la cuenta emisora.
            $primera = $filas->first();
            foreach ($aCrear as $c) {
                Consignacion::create([
                    'aliado_id' => $aliadoId,
                    'factura_id' => $primera->id,
                    'banco_cuenta_id' => $c['banco_cuenta_id'],
                    'fecha' => $c['fecha'],
                    'valor' => $c['valor'],
                    'referencia' => $c['referencia'],
                    'imagen_path' => $c['imagen_path'],
                    'observacion' => $c['observacion'],
                    'confirmado' => false,
                    'usuario_id' => $usuarioId,
                ]);
            }

            // ── Reparto por fila: cada fila conserva lo que pagó ─────────
            $antesFilas = $filas->map(fn ($f) => [
                'id' => (int) $f->id,
                'forma_pago' => $f->forma_pago,
                'fecha_pago' => $f->fecha_pago?->toDateString(),
                'valor_efectivo' => (int) $f->valor_efectivo,
                'valor_consignado' => (int) $f->valor_consignado,
            ])->values()->all();

            $reparto = self::repartir($filas->map(fn ($f) => (int) $f->valor_efectivo + (int) $f->valor_consignado)->all(), $consignado);

            foreach ($filas->values() as $i => $f) {
                $pago = (int) $f->valor_efectivo + (int) $f->valor_consignado;
                $f->update([
                    'forma_pago' => $forma,
                    'fecha_pago' => $fechaPago,
                    'valor_consignado' => $reparto[$i],
                    'valor_efectivo' => $pago - $reparto[$i],
                ]);
            }

            $despuesFilas = $filas->map(fn ($f) => [
                'id' => (int) $f->id,
                'forma_pago' => $f->forma_pago,
                'fecha_pago' => $fechaPago,
                'valor_efectivo' => (int) $f->valor_efectivo,
                'valor_consignado' => (int) $f->valor_consignado,
            ])->values()->all();

            $despuesConsig = self::consignacionesDe($filas)
                ->map(fn ($c) => $c->only(['id', 'factura_id', 'banco_cuenta_id', 'fecha', 'valor', 'referencia', 'confirmado']))
                ->values()->all();

            $formaAntes = $antesFilas[0]['forma_pago'];
            $recibo = $primera->numero_factura ?: $primera->id;
            Bitacora::registrar(
                accion: 'pago_corregido',
                modelo: 'Factura',
                registroId: (int) $primera->id,
                descripcion: "Recibo #{$recibo}: pago corregido ({$formaAntes} {$fechaAntes} → {$forma} {$fechaPago}). Motivo: {$datos['motivo']}",
                detalle: [
                    'motivo' => $datos['motivo'],
                    'numero_factura' => $primera->numero_factura,
                    'total_pagado' => $totalPagado,
                    'antes' => ['filas' => $antesFilas, 'consignaciones' => $antesConsig],
                    'despues' => ['filas' => $despuesFilas, 'consignaciones' => $despuesConsig],
                ],
                alidoId: $aliadoId,
            );

            return ['forma_pago' => $forma, 'fecha_pago' => $fechaPago, 'filas' => $filas->count()];
        });
    }

    /**
     * Reparte $consignado entre las filas en proporción a lo que pagó cada
     * una (resto mayor). Ninguna fila recibe más consignado del que pagó, así
     * que su efectivo nunca queda negativo y su saldo no se mueve.
     *
     * @param  int[]  $pagos
     * @return int[]
     */
    public static function repartir(array $pagos, int $consignado): array
    {
        $total = array_sum($pagos);
        if ($total <= 0) {
            return array_fill(0, count($pagos), 0);
        }

        $base = [];
        $restos = [];
        foreach ($pagos as $i => $p) {
            $base[$i] = intdiv($consignado * $p, $total);
            $restos[$i] = ($consignado * $p) % $total;
        }

        $faltan = $consignado - array_sum($base);
        arsort($restos);
        foreach (array_keys($restos) as $i) {
            if ($faltan <= 0) {
                break;
            }
            $base[$i]++;
            $faltan--;
        }

        return array_values($base);
    }

    private static function consignacionesDe(Collection $filas): Collection
    {
        return Consignacion::whereIn('factura_id', $filas->pluck('id'))
            ->withCount('movimientosBanco')
            ->orderBy('id')
            ->get();
    }

    private static function consignacionBloqueada(Consignacion $c): bool
    {
        return (bool) $c->confirmado || (int) ($c->movimientos_banco_count ?? 0) > 0;
    }

    private static function igual(Consignacion $vieja, array $c): bool
    {
        return (int) $vieja->banco_cuenta_id === $c['banco_cuenta_id']
            && $vieja->fecha?->toDateString() === $c['fecha']
            && (int) $vieja->valor === $c['valor']
            && (trim((string) $vieja->referencia) ?: null) === $c['referencia'];
    }

    /** Lo que impide corregir el recibo sin importar qué se pida. */
    private static function bloqueoGeneral(Collection $filas): ?string
    {
        $esPrestamo = $filas->contains(fn ($f) => $f->es_prestamo
            || $f->forma_pago === 'prestamo'
            || $f->estado === Factura::ESTADO_PRESTAMO
            || (int) $f->valor_prestamo > 0);
        if ($esPrestamo) {
            return 'Este recibo es un préstamo: no entró plata, así que no hay forma de pago que corregir.';
        }

        if ($filas->contains(fn ($f) => $f->estado === Factura::ESTADO_PRE)) {
            return 'Este recibo es una pre-factura: todavía no tiene pago.';
        }

        $pagado = $filas->sum(fn ($f) => (int) $f->valor_efectivo + (int) $f->valor_consignado);
        if ($pagado <= 0) {
            return 'Este recibo se pagó solo con anticipo o saldo a favor: no tiene efectivo ni consignación que corregir.';
        }

        return null;
    }

    /**
     * El cuadre es por (usuario, día): si alguno de los días que toca la
     * corrección ya está cuadrado para quien facturó, se bloquea.
     */
    private static function bloqueoCuadre(int $aliadoId, Collection $filas, array $fechas): ?string
    {
        $usuarios = $filas->pluck('usuario_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $fechas = array_values(array_unique(array_filter($fechas)));
        if ($usuarios->isEmpty() || empty($fechas)) {
            return null;
        }

        foreach ($fechas as $fecha) {
            $cuadre = Cuadre::where('aliado_id', $aliadoId)
                ->whereIn('usuario_id', $usuarios)
                ->where('estado', 'cerrado')
                ->whereDate('fecha_inicio', '<=', $fecha)
                ->whereDate('fecha_fin', '>=', $fecha)
                ->first(['id', 'usuario_id']);

            if ($cuadre) {
                $nombre = User::where('id', $cuadre->usuario_id)->value('nombre') ?? 'quien facturó';

                return "La caja de {$nombre} del ".Carbon::parse($fecha)->format('d/m/Y')
                    .' ya está cuadrada. Un superadmin debe reabrir ese día en Cuadre diario antes de corregir el pago.';
            }
        }

        return null;
    }
}
