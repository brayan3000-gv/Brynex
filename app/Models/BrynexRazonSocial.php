<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Ficha maestra de una razón social administrada por BryNex.
 *
 * La identidad es el NIT, no la fila de `razones_sociales`: el mismo NIT vive
 * como fila distinta en cada aliado que usa esa empresa. Todo lo que este
 * módulo cuenta (afiliados, dinero, obligaciones) se consolida por ficha
 * atravesando `brynex_razon_social_vinculos`.
 *
 * No tiene `aliado_id`: es de BryNex, no de ningún aliado.
 */
class BrynexRazonSocial extends BaseModel
{
    use SoftDeletes;

    protected $table = 'brynex_razones_sociales';

    protected $fillable = [
        'nit', 'dv', 'razon_social', 'propiedad',
        'regimen', 'periodicidad_iva', 'responsabilidades_rut',
        'fecha_constitucion', 'firma_electronica_vence',
        'municipio_ica', 'periodicidad_ica',
        'contador_id', 'en_seguimiento', 'estado', 'notas',
        // Puesto a mano cuando ningún aliado tiene la cédula del representante.
        'representante_cedula',
        'creado_por', 'actualizado_por',
    ];

    protected $casts = [
        'en_seguimiento' => 'boolean',
        'fecha_constitucion' => 'date',
        'firma_electronica_vence' => 'date',
        'responsabilidades_rut' => 'array',
    ];

    public const PROPIEDAD = [
        'brynex' => '🏠 Propia de BryNex',
        'tercero' => '🤝 De un tercero',
    ];

    public const REGIMENES = [
        'RST' => 'Régimen Simple (RST)',
        'ORDINARIO' => 'Régimen Ordinario',
    ];

    public const PERIODICIDAD_IVA = [
        'no_responsable' => 'No responsable de IVA',
        'bimestral' => 'IVA bimestral',
        'cuatrimestral' => 'IVA cuatrimestral',
        'anual' => 'IVA anual',
    ];

    /**
     * Responsabilidades del RUT que cambian qué obligaciones aplican.
     * El código es el de la casilla 53 del RUT.
     */
    public const RESPONSABILIDADES_RUT = [
        '05' => '05 — Impuesto de renta régimen ordinario',
        '07' => '07 — Retención en la fuente a título de renta',
        '09' => '09 — Retención en la fuente en el impuesto sobre las ventas',
        '11' => '11 — Ventas régimen común',
        '14' => '14 — Informante de exógena',
        '42' => '42 — Obligado a llevar contabilidad',
        '47' => '47 — Régimen simple de tributación (SIMPLE)',
        '48' => '48 — Impuesto sobre las ventas (IVA)',
        '49' => '49 — No responsable de IVA',
        '52' => '52 — Facturador electrónico',
    ];

    // ─── Relaciones ───────────────────────────────────────────────────

    public function vinculos()
    {
        return $this->hasMany(BrynexRazonSocialVinculo::class, 'ficha_id');
    }

    public function obligaciones()
    {
        return $this->hasMany(BrynexObligacion::class, 'ficha_id');
    }

    public function credenciales()
    {
        return $this->hasMany(RazonSocialCredencial::class, 'ficha_id');
    }

    public function contador()
    {
        return $this->belongsTo(User::class, 'contador_id');
    }

    // ─── Scopes ───────────────────────────────────────────────────────

    public function scopeSeguidas($query)
    {
        return $query->where('en_seguimiento', true);
    }

    public function scopePropias($query)
    {
        return $query->where('propiedad', 'brynex');
    }

    // ─── Ayudas ───────────────────────────────────────────────────────

    /** La DIAN vence por el último dígito del NIT, sin contar el DV. */
    public function ultimoDigitoNit(): int
    {
        return (int) substr((string) $this->nit, -1);
    }

    public function esResponsableIva(): bool
    {
        return $this->periodicidad_iva !== null
            && $this->periodicidad_iva !== 'no_responsable';
    }

    /** NIT formateado con el dígito de verificación: 901.904.750-1 */
    public function nitFormateado(): string
    {
        $nit = number_format((float) $this->nit, 0, ',', '.');

        return $this->dv !== null ? "{$nit}-{$this->dv}" : $nit;
    }
}
