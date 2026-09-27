<?php

namespace App\Models;

use App\Traits\HasSqlServerDates;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * El usuario con que una empresa cliente entra a su portal.
 *
 * Se autentica en el guard `empresa`, no en `web`: por eso no pasa por
 * SetAlidoContext, no tiene aliado en sesión y ninguna ruta de /admin la deja
 * entrar. Todo lo que el portal consulta se filtra con aliado_id y empresa_id
 * de este registro, nunca con algo que llegue en la petición.
 */
class EmpresaAcceso extends Authenticatable
{
    use HasSqlServerDates;

    protected $table = 'empresa_accesos';

    private ?Collection $cedulasCache = null;

    protected $fillable = [
        'aliado_id',
        'empresa_id',
        'usuario',
        'password',
        'activo',
        'ver_discriminado',
        'debe_cambiar_clave',
        'creado_por',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'password' => 'hashed',
        'activo' => 'boolean',
        'ver_discriminado' => 'boolean',
        'debe_cambiar_clave' => 'boolean',
        'ultimo_acceso_at' => 'datetime',
    ];

    public function empresa(): BelongsTo
    {
        return $this->belongsTo(Empresa::class, 'empresa_id');
    }

    public function aliado(): BelongsTo
    {
        return $this->belongsTo(Aliado::class, 'aliado_id');
    }

    /**
     * Las cédulas de los trabajadores de la empresa. Es el límite de todo lo
     * que el portal muestra: una planilla, una incapacidad o un retiro solo se
     * ve si su cédula está aquí.
     */
    public function cedulas(): Collection
    {
        return $this->cedulasCache ??= DB::table('clientes')
            ->where('aliado_id', $this->aliado_id)
            ->where('cod_empresa', $this->empresa_id)
            ->pluck('cedula');
    }

    /**
     * El NIT como lo escribe la gente: con puntos, espacios o el dígito de
     * verificación («900.123.456-7»). En la BD está solo el número.
     */
    public static function normalizarUsuario(?string $valor): string
    {
        $valor = trim((string) $valor);
        $valor = preg_replace('/-\s*\d\s*$/', '', $valor);

        return preg_replace('/\D/', '', $valor);
    }

    /** Una clave temporal que se pueda dictar por teléfono sin confundir letras. */
    public static function claveTemporal(): string
    {
        $letras = 'ABCDEFGHJKMNPQRSTUVWXYZ';
        $clave = '';
        for ($i = 0; $i < 4; $i++) {
            $clave .= $letras[random_int(0, strlen($letras) - 1)];
        }

        return $clave.'-'.random_int(1000, 9999);
    }
}
