<?php

namespace App\Models;

/**
 * Catálogo de obligaciones: qué existe y a quién le aplica.
 *
 * Es parametría pura, sembrada por `BrynexObligacionesSeeder`. Lo que decide
 * si una obligación se le genera a una razón social es el cruce de tres cosas:
 * su régimen, si es responsable de IVA y con qué periodicidad.
 */
class BrynexObligacionCatalogo extends BaseModel
{
    protected $table = 'brynex_obligaciones_catalogo';

    protected $fillable = [
        'codigo', 'nombre', 'entidad', 'formulario', 'regimen',
        'periodicidad', 'requiere_iva', 'periodicidad_iva_requerida',
        // Responsabilidad(es) del RUT que exige la obligación, separadas por
        // coma. Basta con tener una.
        'requiere_responsabilidad',
        // Años entre el año gravable y el plazo: la anual del 2026 se presenta
        // en 2027, pero la renovación de cámara del 2026 se hace en 2026.
        'anios_desfase',
        // Solo para periodicidad 'unica' (trámites de una sola vez, como la
        // habilitación de facturación electrónica): meses desde la
        // constitución para hacerlo. Null = sin plazo fijo.
        'meses_plazo',
        'descripcion', 'orden', 'activo',
    ];

    protected $casts = [
        'requiere_iva' => 'boolean',
        'activo' => 'boolean',
    ];

    public const ENTIDADES = [
        'DIAN' => '🏛️ DIAN',
        'CAMARA' => '📜 Cámara de Comercio',
        'MUNICIPIO' => '🏙️ Municipio',
    ];

    /**
     * Trámite de una sola vez: se genera un único renglón por razón social, no
     * uno por año. Ver `BrynexRazonSocialService::generarObligaciones()`.
     */
    public function esUnica(): bool
    {
        return $this->periodicidad === 'unica';
    }

    /** Cuántos renglones genera al año. */
    public function periodosPorAnio(): int
    {
        return match ($this->periodicidad) {
            'mensual' => 12,
            'bimestral' => 6,
            'cuatrimestral' => 3,
            default => 1,
        };
    }

    /** ¿Esta obligación le aplica a esta razón social? */
    public function aplicaA(BrynexRazonSocial $ficha): bool
    {
        if (! $this->activo) {
            return false;
        }

        // null = aplica a los dos regímenes
        if ($this->regimen !== null && $this->regimen !== $ficha->regimen) {
            return false;
        }

        if ($this->requiere_iva && ! $ficha->esResponsableIva()) {
            return false;
        }

        // El IVA ordinario es bimestral o cuatrimestral según los ingresos del
        // año anterior: solo se genera el que coincida con la ficha.
        if ($this->periodicidad_iva_requerida !== null
            && $this->periodicidad_iva_requerida !== $ficha->periodicidad_iva) {
            return false;
        }

        // Lo que de verdad decide si una obligación aplica no siempre es el
        // régimen: la retención la declara quien sea agente retenedor (07/09)
        // aunque esté en el simple, y la exógena la informa quien tenga la 14.
        //
        // Si la ficha no tiene las responsabilidades cargadas, la obligación NO
        // se genera. Es deliberado: generar de más ensucia el checklist con
        // renglones que el contador tiene que ir marcando «no aplica» uno por
        // uno. La ficha avisa en pantalla cuando le faltan, y con subir el RUT
        // quedan puestas.
        if ($this->requiere_responsabilidad) {
            $exigidas = array_map('trim', explode(',', $this->requiere_responsabilidad));
            $tiene = array_map('strval', $ficha->responsabilidades_rut ?? []);

            if (! array_intersect($exigidas, $tiene)) {
                return false;
            }
        }

        // El ICA no lo fija la DIAN sino cada municipio, y su periodicidad
        // cambia de uno a otro. Por eso hay una entrada de catálogo por
        // periodicidad y se elige la que coincida con la ficha.
        if ($this->entidad === 'MUNICIPIO') {
            return $this->periodicidad === $ficha->periodicidad_ica;
        }

        return true;
    }

    private const MESES_CORTOS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun',
        'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    private const MESES = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
        'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    /**
     * Cómo se nombra el período. Se guarda en el renglón para no rearmarlo.
     *
     * Va en el orden en que se dice en la práctica contable: «1 bimestre», no
     * «Bimestre 1». Los meses del período van aparte, en `mesesDelPeriodo()`,
     * para que la vista los pinte como apoyo y no dentro del nombre.
     */
    public function etiquetaPeriodo(int $periodo): string
    {
        return match ($this->periodicidad) {
            'mensual' => self::MESES[$periodo - 1] ?? ('Mes '.$periodo),
            'bimestral' => $periodo.' bimestre',
            'cuatrimestral' => $periodo.' cuatrimestre',
            'unica' => 'Una sola vez',
            default => 'Anual',
        };
    }

    /** 'ene-feb' — el rango que cubre el período. Vacío en las anuales. */
    public function mesesDelPeriodo(int $periodo): string
    {
        return match ($this->periodicidad) {
            'bimestral' => self::MESES_CORTOS[($periodo - 1) * 2]
                .'-'.self::MESES_CORTOS[($periodo - 1) * 2 + 1],
            'cuatrimestral' => self::MESES_CORTOS[($periodo - 1) * 4]
                .'-'.self::MESES_CORTOS[($periodo - 1) * 4 + 3],
            default => '',
        };
    }
}
