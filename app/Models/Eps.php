<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class Eps extends BaseModel
{
    protected $table = 'eps';
    public $timestamps = false;

    protected $fillable = [
        'nit', 'codigo', 'nombre', 'razon_social',
        'direccion', 'telefono', 'ciudad', 'email',
        'nombre_aportes', 'nombre_asopagos',
        'formulario_pdf', 'formulario_campos',
    ];

    protected $casts = [
        'formulario_campos' => 'array',
    ];

    /**
     * Sin las EPS de movilidad (COOSALUD MOVILIDAD, NUEVA EPS CM): siguen en la
     * tabla por los planos viejos, pero a un contrato se le pone la de contributivo.
     */
    public function scopeSeleccionables(Builder $q): Builder
    {
        return $q->whereNull('reemplazada_por_id');
    }

    /** La EPS que va en el contrato para un código de la BDUA (ESSC24 → la de EPS042). */
    public static function idPorCodigo(?string $codigo): ?int
    {
        if (blank($codigo)) {
            return null;
        }

        $fila = DB::table('eps')->where('codigo', $codigo)->orderBy('id')->first(['id', 'reemplazada_por_id']);

        return $fila ? (int) ($fila->reemplazada_por_id ?: $fila->id) : null;
    }

    /** La EPS que va en el contrato para un NIT (el de la cooperativa vieja → Coosalud). */
    public static function idPorNit(int|string|null $nit): ?int
    {
        if (blank($nit)) {
            return null;
        }

        $fila = DB::table('eps')->where('nit', $nit)->orderBy('id')->first(['id', 'reemplazada_por_id']);

        return $fila ? (int) ($fila->reemplazada_por_id ?: $fila->id) : null;
    }
}
