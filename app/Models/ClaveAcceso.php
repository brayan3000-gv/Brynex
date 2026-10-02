<?php

namespace App\Models;

use App\Models\BaseModel;
use App\Services\Afiliaciones\PortalesEntidades;

class ClaveAcceso extends BaseModel
{
    protected $table = 'clave_accesos';

    protected $fillable = [
        'aliado_id',
        'cedula',
        'razon_social_id',
        'empresa_id',
        'tipo',
        'entidad',
        'usuario',
        'contrasena',
        'link_acceso',
        'correo_entidad',
        'observacion',
        'activo',
        // Portales de entidades (ver PortalesEntidades)
        'entidad_tipo',
        'entidad_id',
        'sin_portal',
        'no_aplica',
        'asesor_nombre',
        'asesor_correo',
        'asesor_telefono',
        'asesor2_nombre',
        'asesor2_correo',
        'asesor2_telefono',
    ];

    protected $casts = [
        'activo'     => 'boolean',
        'cedula'     => 'integer',
        'sin_portal' => 'boolean',
        'no_aplica'  => 'boolean',
    ];

    /**
     * Deja en la bitácora cada cambio, con lo que había antes.
     *
     * Va en el modelo y no en el controlador porque la clave se actualiza desde
     * varios sitios —el módulo, el sincronizador de ARL Sura, un comando— y la
     * pregunta "¿quién me cambió esto?" aparece justo cuando el cambio no vino
     * del sitio de siempre. Ahora que la clave es de la empresa y la puede
     * tocar cualquier aliado que la comparta, sin esto no habría a quién
     * preguntarle.
     */
    /** La entidad la dijo quien guarda (la pestaña de portales): no se deduce del nombre. */
    public bool $entidadFijada = false;

    protected static function booted(): void
    {
        // Las claves de empresa que se escriben a mano (módulo de claves, panel
        // de Afiliaciones) quedan ligadas al catálogo por el nombre, para que la
        // pestaña de portales las encuentre. Si quien guarda ya dijo la entidad
        // (la pestaña misma), se respeta.
        static::saving(function (self $clave) {
            if (! $clave->razon_social_id || $clave->entidadFijada || $clave->isDirty('entidad_id')) {
                return;
            }
            if ($clave->exists && ! $clave->isDirty(['entidad', 'tipo'])) {
                return;
            }

            [$tipo, $id] = PortalesEntidades::clasificar($clave, $clave->razonSocial);
            $clave->entidad_tipo = $tipo;
            $clave->entidad_id = $id;
        });

        static::updating(function (self $clave) {
            $cambios = collect($clave->getDirty())
                ->except(['updated_at'])
                ->map(fn ($nuevo, $campo) => [$clave->getOriginal($campo), $nuevo]);

            if ($cambios->isEmpty()) {
                return;
            }

            ClaveAccesoCambio::create([
                'clave_acceso_id' => $clave->id,
                'aliado_id' => session('aliado_id_activo') ?: $clave->aliado_id,
                'user_id' => auth()->id(),
                'usuario_anterior' => $clave->getOriginal('usuario'),
                'contrasena_anterior' => $clave->getOriginal('contrasena'),
                'usuario_nuevo' => $clave->usuario,
                'contrasena_nueva' => $clave->contrasena,
                // El resto, tal cual: activo, link, correo, observación…
                'otros_cambios' => $cambios->except(['usuario', 'contrasena'])->isEmpty()
                    ? null
                    : json_encode($cambios->except(['usuario', 'contrasena']), JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);
        });
    }

    /** La bitácora de esta clave, de lo más nuevo a lo más viejo. */
    public function cambios()
    {
        return $this->hasMany(ClaveAccesoCambio::class, 'clave_acceso_id')->orderByDesc('id');
    }

    /**
     * Las claves que ese aliado puede ver: las suyas y las de las empresas que
     * comparte con otro aliado.
     *
     * La clave es **de la empresa**, no de quien la factura: la misma razón
     * social existe como una fila por aliado, pero ante la entidad es una sola
     * y el usuario y la contraseña son los mismos. Los procesos automáticos ya
     * la buscaban así —por NIT, sin mirar el aliado—, de modo que un aliado
     * dependía de una clave que no podía ver ni corregir.
     *
     * Se comparte por NIT, que es lo que identifica a la empresa ante la
     * entidad. Las claves de persona (por cédula) no entran aquí.
     */
    public function scopeVisiblesPara($query, int $aliadoId)
    {
        return $query->where(function ($q) use ($aliadoId) {
            $q->where('aliado_id', $aliadoId)
                ->orWhereIn('razon_social_id', function ($sub) use ($aliadoId) {
                    $sub->select('suyas.id')
                        ->from('razones_sociales as suyas')
                        ->whereIn('suyas.nit', function ($nits) use ($aliadoId) {
                            $nits->select('nit')
                                ->from('razones_sociales')
                                ->where('aliado_id', $aliadoId)
                                ->whereNotNull('nit')
                                ->where('nit', '<>', '');
                        });
                });
        });
    }

    /** ¿Esta clave la cargó otro aliado? Se muestra para saber a quién avisar. */
    public function esDeOtroAliado(?int $aliadoId = null): bool
    {
        return (int) $this->aliado_id !== (int) ($aliadoId ?: session('aliado_id_activo'));
    }

    public function aliado()
    {
        return $this->belongsTo(\App\Models\Aliado::class, 'aliado_id');
    }

    // ─── Relaciones ───────────────────────────────────────────────────

    /**
     * La cédula se repite entre aliados: sin el aliado en la relación, un
     * with('cliente') trae el cliente de cualquiera. Ver BelongsToDelAliado.
     */
    public function cliente()
    {
        return $this->belongsToDelAliado(Cliente::class, 'cedula', 'cedula', 'cliente');
    }

    public function razonSocial()
    {
        return $this->belongsTo(\App\Models\RazonSocial::class, 'razon_social_id');
    }

    public function empresa()
    {
        return $this->belongsTo(\App\Models\Empresa::class, 'empresa_id');
    }

    // ─── Scopes ───────────────────────────────────────────────────────

    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    public function scopeDeAliado($query, int $aliadoId)
    {
        return $query->where('aliado_id', $aliadoId);
    }

    public function scopeDeCliente($query, $cedula)
    {
        return $query->where('cedula', $cedula);
    }

    public function scopeDeRazonSocial($query, int $razonSocialId)
    {
        return $query->where('razon_social_id', $razonSocialId);
    }

    public function scopeDeEmpresa($query, int $empresaId)
    {
        return $query->where('empresa_id', $empresaId);
    }
}
