<?php

namespace App\Services;

use App\Models\Pension;
use Illuminate\Support\Facades\DB;
use App\Models\Plano;

/**
 * Encuentra a los cotizantes que van al archivo plano SIN fondo de pensión
 * aunque su factura sí les cobró el aporte, y les pone el fondo que les
 * corresponde antes de que la planilla salga hacia el operador.
 *
 * El plano sale sin pensión cuando `planos.cod_afp` viene en 0 o vacío —lo
 * que arrastra un contrato con `pension_id` en NINGUNA—, y
 * PilaCotizanteCalculator usa ese campo como única señal: sin AFP, IBC y
 * cotización de pensión van en cero. El resultado es que el operador liquida
 * menos de lo que se facturó y la persona se queda sin cotización de pensión
 * ese mes, con la plata ya cobrada.
 *
 * ## Por qué no basta con "el que tenga NINGUNA"
 *
 * Ir sin AFP es legítimo en muchos casos: pensionados, exentos por edad
 * (hombre ≥55 / mujer ≥50) y planes que sencillamente no incluyen pensión. En
 * jun–ago 2026 había ~1.700 planos sin AFP en toda la plataforma y solo 5 con
 * la factura cobrando el aporte. Poner COLPENSIONES a todos los demás
 * cotizaría —y cobraría— pensión que nadie facturó.
 *
 * La señal de que es un error y no una exención es la factura: se corrige
 * únicamente cuando `facturas.v_afp > 0` y el plano va sin AFP. Son los dos
 * datos contradiciéndose, y el que manda es el que el cliente ya pagó.
 *
 * El fondo destino se busca en este orden: el del contrato, el de la ficha
 * del cliente, y si ninguno tiene uno real, COLPENSIONES (el régimen público,
 * al que se entra por defecto sin trámite de traslado).
 *
 * La escritura la hace CorreccionEnlaceService::aplicarEnBrynex(), que ya
 * sabe reflejar una administradora en los tres lugares donde vive: el plano
 * del período, los contratos vigentes de la persona y su ficha de cliente.
 */
class CorreccionPensionFaltanteService
{
    /** COLPENSIONES: el fondo al que se manda a quien no tenga uno definido. */
    private const NIT_POR_DEFECTO = '900336004';

    /**
     * Detecta y corrige en un solo paso. Devuelve lo que se cambió para que
     * quien liquida lo vea; si no había nada que corregir, `aplicadas` viene
     * vacío y no se escribió nada.
     *
     * @param  array  $lote  aliado_id, razon_social_id, n_plano, mes, anio y
     *                       opcionalmente plano_id (liquidación de un solo
     *                       cotizante independiente)
     * @param  array  $tiposModalidad  mismo filtro con el que se arma el TXT
     * @return array{aplicadas: array, planos: int, contratos: int, clientes: int}
     */
    public function corregir(array $lote, array $tiposModalidad = []): array
    {
        $correcciones = $this->detectar($lote, $tiposModalidad);

        if (empty($correcciones)) {
            return ['aplicadas' => [], 'planos' => 0, 'contratos' => 0, 'clientes' => 0];
        }

        return (new CorreccionEnlaceService)->aplicarEnBrynex($correcciones, $lote);
    }

    /**
     * Los cotizantes del lote cuya factura cobró pensión pero cuyo plano va
     * sin AFP, ya traducidos al formato que entiende
     * CorreccionEnlaceService::aplicarEnBrynex().
     */
    public function detectar(array $lote, array $tiposModalidad = []): array
    {
        // Sin COLPENSIONES en el catálogo no hay a dónde mandar a quien no
        // tenga fondo propio; los que sí lo tienen se corrigen igual.
        $porDefecto = $this->fondoPorDefecto();
        $correcciones = [];

        foreach ($this->planosSinAfpConAporte($lote, $tiposModalidad) as $fila) {
            $destino = $this->fondoDe($fila->contrato_pension_id)
                ?? $this->fondoDe($fila->cliente_pension_id)
                ?? $porDefecto;

            $nombre = trim(preg_replace('/\s+/', ' ', implode(' ', array_filter([
                $fila->primer_nombre, $fila->segundo_nombre, $fila->primer_ape, $fila->segundo_ape,
            ]))));

            $correcciones[] = [
                'ambito' => 'pension',
                'etiqueta' => 'Pensión',
                'documento' => (string) $fila->no_identifi,
                'nombre' => $nombre !== '' ? $nombre : null,
                'actual' => [
                    'codigo' => (string) ($fila->cod_afp ?? ''),
                    'nombre' => trim((string) $fila->nombre_afp) !== '' ? $fila->nombre_afp : 'NINGUNA',
                ],
                'nueva' => $destino,
                // El aporte facturado, para que se vea qué se estaba dejando
                // de cotizar en esa planilla.
                'v_afp_facturado' => (int) $fila->v_afp,
                'aplicable' => $destino !== null,
                'motivo' => $destino === null
                    ? 'La factura le cobró pensión pero no tiene fondo, y COLPENSIONES ('
                      .self::NIT_POR_DEFECTO.') no está en el catálogo de pensiones de Brynex.'
                    : null,
            ];
        }

        return $correcciones;
    }

    /**
     * Filas del lote que entran al TXT (planilla/retiro con días) sin AFP y
     * con aporte de pensión facturado. Se excluyen las que ya tienen número
     * de planilla: ahí el snapshot debe seguir reflejando lo que se pagó.
     *
     * El período se resuelve igual que en PlanoPilaTxtService: los
     * los de `paga_mes_actual` guardan el mes de pago y los demás el mes
     * vencido.
     */
    private function planosSinAfpConAporte(array $lote, array $tiposModalidad)
    {
        $query = DB::table('planos AS p')
            ->join('facturas AS f', 'f.id', '=', 'p.factura_id')
            ->leftJoin('contratos AS c', 'c.id', '=', 'p.contrato_id')
            ->leftJoin('clientes AS cl', function ($join) use ($lote) {
                $join->on('cl.cedula', '=', 'p.no_identifi')
                    ->where('cl.aliado_id', '=', (int) $lote['aliado_id']);
            })
            ->where('p.aliado_id', (int) $lote['aliado_id'])
            ->whereNull('p.deleted_at')
            ->whereIn('p.tipo_reg', ['planilla', 'retiro'])
            ->whereRaw('ISNULL(p.num_dias, 0) > 0')
            ->where(function ($q) {
                $q->whereNull('p.numero_planilla')->orWhere('p.numero_planilla', '');
            })
            // El plano va sin fondo de pensión…
            ->where(function ($q) {
                $q->whereNull('p.cod_afp')->orWhereIn('p.cod_afp', ['0', '']);
            })
            // …pero la factura sí cobró el aporte.
            ->where('f.v_afp', '>', 0)
            // Tipo E - X1 va sin pensión A PROPÓSITO (subtipo 04) y su factura
            // cobra un día de pensión por si toca la doble planilla
            // (PilaCotizanteEX1::diaPension). Ahí la contradicción es buscada:
            // ponerle fondo le metería pensión a la planilla.
            ->whereNotIn('p.tipo_modalidad_id', \App\Models\TipoModalidad::IDS_EX1)
            ->select([
                'p.id', 'p.no_identifi', 'p.cod_afp', 'p.nombre_afp',
                'p.primer_nombre', 'p.segundo_nombre', 'p.primer_ape', 'p.segundo_ape',
                'f.v_afp',
                DB::raw('c.pension_id  AS contrato_pension_id'),
                DB::raw('cl.pension_id AS cliente_pension_id'),
            ]);

        if (! empty($lote['plano_id'])) {
            $query->where('p.id', (int) $lote['plano_id']);

            return $query->get();
        }

        $mesPago = (int) $lote['mes'];
        $anioPago = (int) $lote['anio'];
        $mesVencido = $mesPago === 1 ? 12 : $mesPago - 1;
        $anioVencido = $mesPago === 1 ? $anioPago - 1 : $anioPago;

        $query->where('p.razon_social_id', (int) $lote['razon_social_id'])
            ->where('p.n_plano', (int) $lote['n_plano'])
            ->tap(fn ($q) => Plano::filtrarPeriodoDePago($q, $mesPago, $anioPago));

        if (! empty($tiposModalidad)) {
            $query->whereIn('p.tipo_modalidad_id', $tiposModalidad);
        }

        return $query->get();
    }

    /**
     * El fondo del catálogo, solo si es una AFP de verdad.
     *
     * "NINGUNA" tiene NIT 0 y `Pension::ID_PENSIONADO` no es una AFP sino la
     * marca de que la persona ya está pensionada: ninguno de los dos sirve
     * como destino.
     */
    private function fondoDe($pensionId): ?array
    {
        if (empty($pensionId) || (int) $pensionId === Pension::ID_PENSIONADO) {
            return null;
        }

        $fila = DB::table('pensiones')->find((int) $pensionId);

        if (! $fila || in_array(trim((string) $fila->nit), ['', '0'], true)) {
            return null;
        }

        return [
            'id' => (int) $fila->id,
            'nit' => (string) $fila->nit,
            'codigo' => (string) $fila->codigo,
            'nombre' => (string) $fila->razon_social,
        ];
    }

    /** COLPENSIONES, buscado por NIT para no depender del id del catálogo. */
    private function fondoPorDefecto(): ?array
    {
        $fila = DB::table('pensiones')
            ->whereRaw('CAST(nit AS VARCHAR(20)) = ?', [self::NIT_POR_DEFECTO])
            ->first();

        return $fila ? $this->fondoDe($fila->id) : null;
    }
}
