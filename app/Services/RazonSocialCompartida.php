<?php

namespace App\Services;

use App\Models\RazonSocial;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Una razón social prestada a otros aliados.
 *
 * La misma empresa existe una vez por aliado: los contratos, la facturación y
 * los planos filtran la razón social por `aliado_id`, así que cada uno necesita
 * su fila. Lo que se comparte es la empresa: la copia apunta a la original
 * (`origen_id`), los datos de la empresa se cambian en la original y pasan
 * solos a las copias, y cada aliado conserva lo suyo.
 *
 * Las claves de portales no se copian: van por NIT (ver PortalesEntidades), así
 * que al habilitar la empresa el aliado las ve sin hacer nada más.
 *
 * Solo la habilita un superadmin de BryNex: compartir una empresa con un
 * aliado le da acceso a sus claves.
 */
class RazonSocialCompartida
{
    /**
     * Datos de la empresa: los mismos en todos los aliados.
     *
     * Lista blanca a propósito. Lo demás es del aliado: la sucursal ante el
     * operador (01 Brygar, 02 Fecop, 03 Luis Lopez), el número de planilla y
     * el mes de pagos (cada aliado paga la suya), el estado, el encargado, lo
     * que reporta el operador de esa sucursal, las notas de la factura y la
     * observación.
     */
    public const EMPRESA = [
        'nit', 'dv', 'razon_social', 'tipo_persona',
        'direccion', 'telefonos', 'correos',
        'dir_formulario', 'tel_formulario', 'correo_formulario',
        'cedula_rep', 'nombre_rep', 'rep_tipo_doc',
        'actividad_economica', 'objeto_social', 'fecha_constitucion',
        'arl_nit', 'arl_poliza', 'caja_nit',
        'cod_departamento', 'cod_municipio',
        'fecha_limite_pago', 'dia_habil', 'forma_presentacion',
        'es_independiente', 'exonerado_parafiscales',
    ];

    private const ALIADO_PRUEBAS = 1;

    public static function puedeHabilitar(?User $user): bool
    {
        return (bool) ($user?->es_brynex && $user->hasRole('superadmin'));
    }

    /** La original de esta fila: ella misma si no es copia. */
    public static function original(object $rs): object
    {
        if (! $rs->origen_id) {
            return $rs;
        }

        return DB::table('razones_sociales')->where('id', $rs->origen_id)->first() ?? $rs;
    }

    /**
     * Cómo está la empresa en cada aliado activo: si ya tiene su fila, si es
     * copia de esta, y con qué sucursal.
     */
    public static function estadoPorAliado(object $rs): Collection
    {
        $original = self::original($rs);
        $nit = self::nit($original->nit);

        $filas = strlen($nit) >= 6
            ? DB::table('razones_sociales')
                ->whereRaw("REPLACE(REPLACE(REPLACE(ISNULL(nit,''),'-',''),'.',''),' ','') = ?", [$nit])
                ->get(['id', 'aliado_id', 'origen_id', 'estado', 'codigo_sucursal'])
                ->groupBy('aliado_id')
            : collect();

        // El aliado 1 (BryNex) es de pruebas: no se le prestan empresas reales.
        return DB::table('aliados')->where('activo', true)
            ->when((int) $original->aliado_id !== self::ALIADO_PRUEBAS, fn ($q) => $q->where('id', '<>', self::ALIADO_PRUEBAS))
            ->orderBy('nombre')->get(['id', 'nombre'])
            ->map(function ($a) use ($filas, $original) {
                $fila = $filas->get($a->id)?->sortBy(fn ($f) => $f->estado === 'Activa' ? 0 : 1)->first();

                return [
                    'aliado_id' => (int) $a->id,
                    'aliado' => $a->nombre,
                    'razon_social_id' => $fila?->id,
                    'estado' => match (true) {
                        (int) $a->id === (int) $original->aliado_id => 'original',
                        ! $fila => 'no_la_tiene',
                        (int) $fila->origen_id === (int) $original->id => 'copia',
                        default => 'sin_vincular',
                    },
                    'sucursal' => $fila?->codigo_sucursal,
                ];
            });
    }

    /**
     * Habilita la empresa en un aliado. Si ya tiene una fila con ese NIT, la
     * liga a la original y le pone los datos de la empresa; si no, la crea.
     *
     * @return array{accion: string, id: int}
     */
    public static function habilitar(object $rs, int $aliadoId, string $nota = ''): array
    {
        $original = self::original($rs);
        abort_if((int) $original->aliado_id === $aliadoId, 422, 'Ese aliado es el dueño de la razón social.');
        abort_if(strlen(self::nit($original->nit)) < 6, 422, 'La razón social no tiene NIT: no se puede compartir.');

        $existente = DB::table('razones_sociales')
            ->where('aliado_id', $aliadoId)
            ->whereRaw("REPLACE(REPLACE(REPLACE(ISNULL(nit,''),'-',''),'.',''),' ','') = ?", [self::nit($original->nit)])
            ->orderByRaw("CASE WHEN estado = 'Activa' THEN 0 ELSE 1 END")
            ->first();

        if ($existente) {
            DB::table('razones_sociales')->where('id', $existente->id)
                ->update(['origen_id' => $original->id] + self::datosEmpresa($original));

            return ['accion' => 'vinculada', 'id' => (int) $existente->id];
        }

        return ['accion' => 'creada', 'id' => self::crearCopia($original, $aliadoId, $nota)];
    }

    /**
     * Crea la fila del aliado como copia de la original, con lo propio del
     * aliado en blanco: la sucursal se crea en el operador y la planilla
     * arranca en 1.
     */
    public static function crearCopia(object $original, int $aliadoId, string $nota = ''): int
    {
        // `razones_sociales.id` no es IDENTITY (tabla legacy): el siguiente a mano.
        $fila = (array) DB::table('razones_sociales')->where('id', $original->id)->first();
        $fila['id'] = (int) DB::table('razones_sociales')->max('id') + 1;
        $fila['aliado_id'] = $aliadoId;
        $fila['origen_id'] = $original->id;
        $fila['estado'] = 'Activa';
        $fila['n_plano'] = 1;
        $fila['encargado_id'] = null;
        $fila['mes_pagos'] = null;
        $fila['anio_pagos'] = null;
        $fila['id_legacy'] = null;
        $fila['codigo_sucursal'] = null;
        $fila['nombre_sucursal'] = null;
        $fila['datos_operador'] = null;
        $fila['datos_operador_at'] = null;
        $fila['observacion'] = trim($nota) ?: null;

        DB::table('razones_sociales')->insert($fila);

        return $fila['id'];
    }

    /** Pasa los datos de la empresa de la original a sus copias. Devuelve cuántas. */
    public static function sincronizar(int $originalId): int
    {
        $original = DB::table('razones_sociales')->where('id', $originalId)->first();

        if (! $original) {
            return 0;
        }

        $datos = self::datosEmpresa($original);

        return $datos ? DB::table('razones_sociales')->where('origen_id', $originalId)->update($datos) : 0;
    }

    /** Las copias de una razón social, con el nombre del aliado y la sucursal. */
    public static function copias(int $originalId): Collection
    {
        return DB::table('razones_sociales as rs')
            ->join('aliados as a', 'a.id', '=', 'rs.aliado_id')
            ->where('rs.origen_id', $originalId)
            ->orderBy('a.nombre')
            ->get(['rs.id', 'a.nombre as aliado', 'rs.codigo_sucursal', 'rs.estado']);
    }

    /**
     * Los datos de la empresa que tiene la original. Lo vacío no se pasa: una
     * copia con el representante o la caja puestos no los pierde porque la
     * original los tenga en blanco (pasaba con varias de Brygar). La ARL y la
     * caja en «0» cuentan como vacías.
     */
    private static function datosEmpresa(object $original): array
    {
        return collect(self::EMPRESA)
            ->mapWithKeys(fn ($campo) => [$campo => $original->{$campo} ?? null])
            ->reject(fn ($v, $campo) => $v === null || trim((string) $v) === ''
                || (in_array($campo, ['arl_nit', 'caja_nit'], true) && trim((string) $v) === '0'))
            ->all();
    }

    private static function nit($nit): string
    {
        return preg_replace('/\D/', '', (string) $nit);
    }
}
