<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\SoftDeletes;

class OperadorCredencial extends BaseModel
{
    use SoftDeletes;

    protected $table = 'operadores_credenciales';

    protected $fillable = [
        'aliado_id',
        'razon_social_id',
        'operador_planilla_id',
        'usuario',
        'contrasena',
        'clave_secreta',
        'clave_secreta_expira_at',
        'config'
    ];

    /** Nunca serializar los secretos hacia una respuesta JSON o una vista. */
    protected $hidden = ['contrasena', 'clave_secreta'];

    protected $casts = [
        'config'                  => 'array',
        'contrasena'              => 'encrypted',
        'clave_secreta'           => 'encrypted',
        'clave_secreta_expira_at' => 'date',
    ];

    /**
     * Credencial vigente de un operador para el aliado.
     * Prioriza la credencial específica de la razón social; si no existe cae
     * a la credencial general del aliado (razon_social_id NULL), que es el
     * caso normal en Enlace: una sola cuenta autorizada sobre todos los NIT.
     */
    public function scopeParaOperador($query, int $aliadoId, int $operadorPlanillaId, ?int $razonSocialId = null)
    {
        return $query
            ->where('aliado_id', $aliadoId)
            ->where('operador_planilla_id', $operadorPlanillaId)
            ->where(function ($q) use ($razonSocialId) {
                $q->whereNull('razon_social_id');
                if ($razonSocialId) {
                    $q->orWhere('razon_social_id', $razonSocialId);
                }
            })
            ->orderByRaw('CASE WHEN razon_social_id IS NULL THEN 1 ELSE 0 END');
    }

    /**
     * Credencial para leer una planilla ya liquidada (soporte, comprobante,
     * totales): la del aliado y, si no tiene, la de BryNex con la que se liquidó.
     *
     * Al liquidar, un usuario de BryNex sin credencial del aliado usa la de su
     * propio aliado para las empresas que BryNex también maneja (mismo NIT):
     * ver PlanillaApiController::credencialDeCasa(). Así INTEGRA DUITAMA
     * liquidó en Simple sin tener usuario de Simple, y luego no podía bajar el
     * soporte: «Simple: sin credenciales» (nov-2026). Aquí no hay usuario de
     * BryNex en la sesión (lo baja el aliado o un comando), así que la cuenta
     * de BryNex solo se usa si la planilla quedó registrada como liquidada por
     * API para ese aliado: nunca para planillas que el aliado pagó por su lado.
     */
    public static function paraPlanilla(int $aliadoId, int $operadorPlanillaId, ?int $razonSocialId, ?string $numeroPlanilla): ?self
    {
        $propia = static::paraOperador($aliadoId, $operadorPlanillaId, $razonSocialId)->first();
        if ($propia || ! $razonSocialId || blank($numeroPlanilla)) {
            return $propia;
        }

        $liquidadaPorApi = \DB::table('operador_planillas_api')
            ->where('aliado_id', $aliadoId)
            ->where('operador_planilla_id', $operadorPlanillaId)
            ->where('numero_planilla', $numeroPlanilla)
            ->whereNull('deleted_at')
            ->exists();
        $nit = $liquidadaPorApi ? RazonSocial::whereKey($razonSocialId)->value('nit') : null;
        if (! $nit) {
            return null;
        }

        $casas = User::where('es_brynex', true)->whereNotNull('aliado_id')
            ->where('aliado_id', '<>', $aliadoId)
            ->distinct()->orderBy('aliado_id')->pluck('aliado_id');

        foreach ($casas as $casa) {
            $rsCasa = RazonSocial::where('aliado_id', $casa)->where('nit', $nit)->value('id');
            $cred = $rsCasa ? static::paraOperador((int) $casa, $operadorPlanillaId, (int) $rsCasa)->first() : null;
            if ($cred) {
                return $cred;
            }
        }

        return null;
    }

    /** ¿La clave secreta ya venció? Enlace la caduca al año de generada. */
    public function claveSecretaVencida(): bool
    {
        return $this->clave_secreta_expira_at !== null
            && $this->clave_secreta_expira_at->isPast();
    }

    public function razonSocial()
    {
        return $this->belongsTo(RazonSocial::class, 'razon_social_id');
    }

    public function operadorPlanilla()
    {
        return $this->belongsTo(OperadorPlanilla::class, 'operador_planilla_id');
    }
}
