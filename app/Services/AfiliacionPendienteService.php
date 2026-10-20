<?php

namespace App\Services;

use App\Models\Contrato;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Afiliaciones que nunca se cobraron.
 *
 * Una afiliación queda pendiente mientras su contrato no tenga ninguna factura
 * de afiliación (tipo `afiliacion`, o una planilla con `afiliacion` > 0 como
 * la del I ACT del primer mes), en cualquier estado: pagada, préstamo o
 * pre-factura ya la dejan registrada. Va por contrato y no por cédula: si la
 * persona se cambia de plan o de razón social, el contrato viejo debe su
 * afiliación aunque el nuevo haya pagado la suya (regla del dueño, oct-2026).
 *
 * Se descarta:
 *   • el retiro por "Error - Sin Afiliación": afiliaciones equivocadas o de prueba;
 *   • el contrato sin costo de afiliación y el de solo seguro;
 *   • los ingresos anteriores a `cobros.afiliacion_pendiente.desde`.
 *
 * Solo aplica a los aliados de `cobros.afiliacion_pendiente.aliados`.
 */
class AfiliacionPendienteService
{
    private const MESES = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
        'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    public static function aplica(?int $aliadoId): bool
    {
        return $aliadoId !== null
            && in_array($aliadoId, array_map('intval', config('cobros.afiliacion_pendiente.aliados', [])), true);
    }

    /**
     * Contratos del aliado con la afiliación sin cobrar, de los que ingresaron
     * ANTES del período: la del mes de ingreso ya la pide Cobros por su cuenta.
     *
     * @return Collection<int, array{mes:int, anio:int, periodo:string, valor:int}> por contrato_id
     */
    public static function anterioresAlPeriodo(int $aliadoId, int $mes, int $anio): Collection
    {
        if (! self::aplica($aliadoId)) {
            return collect();
        }

        $inicioPeriodo = sprintf('%04d-%02d-01', $anio, $mes);

        return self::query($aliadoId)
            ->where('contratos.fecha_ingreso', '<', $inicioPeriodo)
            ->get(['contratos.id', 'contratos.fecha_ingreso', 'contratos.costo_afiliacion'])
            ->mapWithKeys(fn ($c) => [(int) $c->id => self::detalle($c)]);
    }

    /** La afiliación sin cobrar de un contrato, o null si no debe nada. */
    public static function deContrato(Contrato $contrato): ?array
    {
        if (! self::aplica((int) $contrato->aliado_id)) {
            return null;
        }

        $c = self::query((int) $contrato->aliado_id)
            ->where('contratos.id', $contrato->id)
            ->first(['contratos.id', 'contratos.fecha_ingreso', 'contratos.costo_afiliacion']);

        return $c ? self::detalle($c) : null;
    }

    /**
     * Lo mismo que deContrato(), más a dónde ir a facturarla: la planilla de
     * la empresa en el mes de ingreso, o el contrato si es individual. En ese
     * mes la facturación de empresa muestra al retirado y le cobra la afiliación.
     */
    public static function conEnlace(Contrato $contrato): ?array
    {
        $afil = self::deContrato($contrato);
        if (! $afil) {
            return null;
        }

        $codEmpresa = (int) DB::table('clientes')
            ->where('aliado_id', $contrato->aliado_id)
            ->where('cedula', $contrato->cedula)
            ->value('cod_empresa');

        $afil['url'] = $codEmpresa > 1
            ? route('admin.facturacion.empresa', [$codEmpresa, 'mes' => $afil['mes'], 'anio' => $afil['anio']])
            : route('admin.contratos.edit', $contrato->id);

        return $afil;
    }

    private static ?array $motivosError = null;

    /** Ids de los motivos de retiro que significan "no hubo afiliación". */
    public static function motivosError(): array
    {
        return self::$motivosError ??= DB::table('motivos_retiro')
            ->where('nombre', 'like', 'Error%')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private static function query(int $aliadoId)
    {
        $motivosError = self::motivosError();

        return Contrato::where('contratos.aliado_id', $aliadoId)
            ->where('contratos.costo_afiliacion', '>', 0)
            ->where(fn ($q) => $q->whereNull('contratos.tipo_modalidad_id')
                ->orWhere('contratos.tipo_modalidad_id', '!=', Contrato::MODALIDAD_SEGUROS))
            ->where('contratos.fecha_ingreso', '>=', config('cobros.afiliacion_pendiente.desde'))
            ->where(function ($q) use ($motivosError) {
                $q->whereIn('contratos.estado', ['vigente', 'activo'])
                    ->orWhere(function ($r) use ($motivosError) {
                        $r->where('contratos.estado', 'retirado')
                            ->where(function ($m) use ($motivosError) {
                                $m->whereNull('contratos.motivo_retiro_id');
                                if ($motivosError) {
                                    $m->orWhereNotIn('contratos.motivo_retiro_id', $motivosError);
                                }
                            });
                    });
            })
            ->whereNotExists(function ($q) {
                $q->from('facturas as fa')
                    ->whereColumn('fa.contrato_id', 'contratos.id')
                    ->whereNull('fa.deleted_at')
                    ->where(fn ($w) => $w->where('fa.tipo', 'afiliacion')->orWhere('fa.afiliacion', '>', 0));
            });
    }

    private static function detalle($c): array
    {
        $fecha = \Carbon\Carbon::parse($c->fecha_ingreso);
        $mes = (int) $fecha->month;
        $anio = (int) $fecha->year;

        return [
            'mes' => $mes,
            'anio' => $anio,
            'periodo' => self::MESES[$mes].' '.$anio,
            'valor' => (int) $c->costo_afiliacion,
        ];
    }
}
