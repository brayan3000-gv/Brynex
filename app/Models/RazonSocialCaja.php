<?php

namespace App\Models;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Caja de compensación de una razón social en un departamento.
 *
 * La principal sigue en razones_sociales.caja_nit; estas son las de los otros
 * departamentos donde la empresa tiene gente (Comfandi en el Valle, Compensar
 * en Bogotá), una por departamento y ninguna obligatoria. Una copia de otro
 * aliado usa las de su original, igual que la caja principal.
 */
class RazonSocialCaja extends BaseModel
{
    protected $table = 'razon_social_cajas';

    protected $fillable = ['razon_social_id', 'departamento_id', 'caja_id'];

    /**
     * Las cajas de varias razones sociales a la vez, para el formulario del
     * contrato: [rs_id => [['departamento_id', 'departamento', 'caja_id',
     * 'caja', 'principal'], …]], la principal primero.
     *
     * El departamento de la principal es el de la razón social (lo reporta el
     * operador) o, si no lo tiene, el de la caja.
     */
    public static function porRazonSocial(Collection $razones): array
    {
        if ($razones->isEmpty()) {
            return [];
        }

        // Cada fila lee las cajas de su original.
        $origenDe = $razones->mapWithKeys(fn ($rs) => [(int) $rs->id => (int) ($rs->origen_id ?: $rs->id)]);
        $originales = DB::table('razones_sociales')
            ->whereIn('id', $origenDe->values()->unique()->all())
            ->get(['id', 'caja_nit', 'cod_departamento'])
            ->keyBy('id');

        $cajas = DB::table('cajas')->get(['id', 'nit', 'nombre', 'id_dept']);
        $cajaPorNit = $cajas->filter(fn ($c) => (string) $c->nit !== '0')->keyBy(fn ($c) => (string) $c->nit);
        $cajaPorId = $cajas->keyBy('id');
        $dptos = DB::table('departamentos')->pluck('nombre', 'id');

        $extra = self::whereIn('razon_social_id', $origenDe->values()->unique()->all())
            ->get()
            ->groupBy('razon_social_id');

        $resultado = [];
        foreach ($origenDe as $rsId => $origenId) {
            $o = $originales->get($origenId);
            $lista = [];

            $principal = $o && $o->caja_nit ? $cajaPorNit->get((string) $o->caja_nit) : null;
            if ($principal) {
                $dpto = (int) substr(str_pad((string) ($o->cod_departamento ?? ''), 2, '0', STR_PAD_LEFT), 0, 2)
                    ?: (int) $principal->id_dept;
                $lista[] = [
                    'departamento_id' => $dpto ?: null,
                    'departamento' => $dpto ? ($dptos[$dpto] ?? null) : null,
                    'caja_id' => (int) $principal->id,
                    'caja' => $principal->nombre,
                    'principal' => true,
                ];
            }

            foreach ($extra->get($origenId, collect())->sortBy(fn ($f) => $dptos[$f->departamento_id] ?? '') as $f) {
                if (! ($c = $cajaPorId->get($f->caja_id))) {
                    continue;
                }
                $lista[] = [
                    'departamento_id' => (int) $f->departamento_id,
                    'departamento' => $dptos[$f->departamento_id] ?? null,
                    'caja_id' => (int) $c->id,
                    'caja' => $c->nombre,
                    'principal' => false,
                ];
            }

            if ($lista) {
                $resultado[$rsId] = $lista;
            }
        }

        return $resultado;
    }

    /** Las de la ficha de la razón social: [['departamento_id', 'caja_id'], …]. */
    public static function deFicha(object $rs): Collection
    {
        return self::where('razon_social_id', (int) ($rs->origen_id ?: $rs->id))
            ->get(['departamento_id', 'caja_id'])
            ->map(fn ($f) => ['departamento_id' => (int) $f->departamento_id, 'caja_id' => (int) $f->caja_id])
            ->values();
    }

    /**
     * Reemplaza las cajas por departamento de una razón social original.
     * Las filas sin departamento o sin caja se ignoran; si un departamento
     * viene dos veces, gana la última.
     */
    public static function guardar(int $razonSocialId, array $filas): void
    {
        $limpias = collect($filas)
            ->filter(fn ($f) => ! empty($f['departamento_id']) && ! empty($f['caja_id']))
            ->keyBy(fn ($f) => (int) $f['departamento_id'])
            ->map(fn ($f, $dpto) => [
                'razon_social_id' => $razonSocialId,
                'departamento_id' => $dpto,
                'caja_id' => (int) $f['caja_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->values()
            ->all();

        DB::transaction(function () use ($razonSocialId, $limpias) {
            self::where('razon_social_id', $razonSocialId)->delete();
            if ($limpias) {
                self::insert($limpias);
            }
        });
    }
}
