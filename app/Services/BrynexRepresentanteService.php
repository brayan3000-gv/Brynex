<?php

namespace App\Services;

use App\Models\BrynexRepresentante;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Arma la lista de representantes legales cruzando las fichas de BryNex con la
 * cédula del representante que ya tienen los aliados en `razones_sociales`.
 *
 * Qué empresas representa alguien no se guarda en ningún lado a propósito: si
 * un aliado cambia el representante de una razón social, la lista lo refleja
 * sin que nadie tenga que acordarse de actualizar una segunda copia.
 *
 * Son tres consultas en total (fichas, representantes, claves); cada una
 * cuesta ~250 ms desde el Mac, así que no hay consultas por representante.
 */
class BrynexRepresentanteService
{
    /**
     * @return array{representantes: Collection, sin_representante: Collection}
     */
    public function agrupar(): array
    {
        // `whereNull` a mano: es Query Builder, no Eloquent, y el scope de
        // SoftDeletes de la ficha no aplica aquí.
        $filas = DB::table('brynex_razones_sociales as f')
            // leftJoin: una ficha que no está en ningún aliado (AVAPP, CIT,
            // STRATEGY) también debe salir, en «sin representante».
            ->leftJoin('brynex_razon_social_vinculos as v', 'v.ficha_id', '=', 'f.id')
            ->leftJoin('razones_sociales as rs', 'rs.id', '=', 'v.razon_social_id')
            ->whereNull('f.deleted_at')
            ->where('f.en_seguimiento', true)
            ->get([
                'f.id as ficha_id', 'f.razon_social', 'f.nit', 'f.regimen', 'f.representante_cedula',
                'rs.cedula_rep', 'rs.nombre_rep', 'rs.rep_tipo_doc',
            ]);

        // Una ficha puede tener varias filas (una por aliado). Normalmente
        // todas dicen el mismo representante; si dos aliados dicen personas
        // distintas, la empresa sale con cada una y se marca para revisar.
        $porFicha = $filas->groupBy('ficha_id');

        $empresasPorCedula = [];
        $datosPorCedula = [];
        $sinRepresentante = collect();

        foreach ($porFicha as $fichaId => $filasFicha) {
            $primera = $filasFicha->first();

            // El puesto a mano en la ficha manda sobre lo que tengan los aliados.
            $manual = self::normalizarCedula($primera->representante_cedula);
            $cedulas = $manual
                ? collect([$manual])
                : $filasFicha
                    ->map(fn ($f) => self::normalizarCedula($f->cedula_rep))
                    ->filter()
                    ->unique()
                    ->values();

            $empresa = (object) [
                'ficha_id' => (int) $fichaId,
                'razon_social' => $primera->razon_social,
                'nit' => $primera->nit,
                'regimen' => $primera->regimen,
                'en_conflicto' => $cedulas->count() > 1,
                'manual' => (bool) $manual,
            ];

            if ($cedulas->isEmpty()) {
                $sinRepresentante->push($empresa);
                continue;
            }

            foreach ($cedulas as $cedula) {
                $empresasPorCedula[$cedula][] = $empresa;

                foreach ($filasFicha as $f) {
                    if (self::normalizarCedula($f->cedula_rep) !== $cedula) {
                        continue;
                    }
                    if ($f->nombre_rep) {
                        $datosPorCedula[$cedula]['nombres'][] = trim($f->nombre_rep);
                    }
                    if ($f->rep_tipo_doc) {
                        $datosPorCedula[$cedula]['tipos'][] = trim($f->rep_tipo_doc);
                    }
                }
            }
        }

        // También salen los que se registraron a mano y hoy no figuran como
        // representantes de ninguna ficha: sus claves no deben quedar huérfanas.
        $registros = BrynexRepresentante::with(['credenciales' => fn ($q) => $q->where('activo', true)->orderBy('tipo')])
            ->get()
            ->keyBy('cedula');

        $cedulas = collect(array_keys($empresasPorCedula))
            ->map(fn ($c) => (string) $c)
            ->merge($registros->keys())
            ->unique();

        $representantes = $cedulas->map(function (string $cedula) use ($empresasPorCedula, $datosPorCedula, $registros) {
            $registro = $registros->get($cedula);
            $nombres = collect($datosPorCedula[$cedula]['nombres'] ?? []);
            $tipos = collect($datosPorCedula[$cedula]['tipos'] ?? []);

            $empresas = collect($empresasPorCedula[$cedula] ?? [])
                ->unique('ficha_id')
                ->sortBy('razon_social')
                ->values();

            return (object) [
                'cedula' => $cedula,
                'registro' => $registro,
                'nombre' => $registro?->nombre ?: (self::masFrecuente($nombres) ?? 'Sin nombre'),
                'tipo_documento' => $registro?->tipo_documento ?: (self::masFrecuente($tipos) ?? 'CC'),
                'correo_rut' => $registro?->correo_rut,
                'telefono' => $registro?->telefono,
                'notas' => $registro?->notas,
                'credenciales' => $registro?->credenciales ?? collect(),
                'empresas' => $empresas,
                // Los aliados escriben el nombre a mano; si no coincide entre
                // ellos, conviene verlo antes de confiar en uno.
                'nombres_distintos' => $nombres->map(fn ($n) => mb_strtoupper($n))->unique()->count() > 1,
            ];
        })
            // Los de más empresas primero: son los que más se usan.
            ->sortBy(fn ($r) => [-$r->empresas->count(), $r->nombre])
            ->values();

        return [
            'representantes' => $representantes,
            'sin_representante' => $sinRepresentante->sortBy('razon_social')->values(),
        ];
    }

    /**
     * `cedula_rep` es BIGINT en `razones_sociales` y sqlsrv lo devuelve como
     * string; aquí se le quitan puntos, espacios y ceros a la izquierda, y el
     * 0 que algunos aliados dejaron de relleno cuenta como vacío. Las letras
     * se conservan (en mayúscula) por los pasaportes registrados a mano.
     */
    public static function normalizarCedula($valor): ?string
    {
        $limpio = ltrim(mb_strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) $valor)), '0');

        return $limpio === '' ? null : $limpio;
    }

    /**
     * Ancla de la tarjeta del representante en la lista. Va con un hash y no
     * con la cédula para no dejar el documento en el historial del navegador.
     */
    public static function ancla(string $cedula): string
    {
        return 'rep-' . substr(md5($cedula), 0, 10);
    }

    private static function masFrecuente(Collection $valores): ?string
    {
        if ($valores->isEmpty()) {
            return null;
        }

        return $valores->countBy()->sortDesc()->keys()->first();
    }
}
