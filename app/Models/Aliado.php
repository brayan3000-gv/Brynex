<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Aliado extends BaseModel
{
    use SoftDeletes;

    // Sin conexión fija: usa el 'default' de config/database.php (sqlsrv en
    // producción vía DB_CONNECTION en .env). Fijarla a mano rompía los tests
    // — se carga en routes/web.php en CADA request (dominios propios de
    // aliado), así que el override a sqlite de phpunit.xml no podía aplicarse.
    // Ver docs/auditoria-calidad.md.

    protected $table = 'aliados';

    protected $fillable = [
        'nombre',
        'eslogan',
        'nit',
        'razon_social',
        'contacto',
        'telefono',
        'celular',
        'whatsapp',
        'correo',
        'direccion',
        'ciudad',
        'departamento_id',
        'municipio_id',
        'logo',
        'logo_oscuro',
        'logo_marca_claro',
        'imagen_planes',
        'logo_marca_recorte',
        'color_primario',
        'slug',
        'dominio_propio',
        'activo',
        'afiliaciones_brynex',
        'encargado_afil_id',
        'brynex_fecha_inicio',
        'brynex_fecha_fin',
        'recibo_doble_copia',
        'codigo_sucursal',
        'nombre_sucursal',
    ];

    protected $casts = [
        'activo'              => 'boolean',
        'afiliaciones_brynex' => 'boolean',
        'recibo_doble_copia'  => 'boolean',
        'brynex_fecha_inicio' => 'date',
        'brynex_fecha_fin'    => 'date',
        'logo_marca_recorte'  => 'array',
    ];

    /**
     * El slug es único y SQL Server solo deja UN NULL en un índice único: un
     * aliado creado sin slug bloqueaba la creación de todos los siguientes.
     * Se arma del nombre, igual que el backfill de la migración del slug.
     */
    protected static function booted(): void
    {
        static::creating(function (Aliado $aliado) {
            if (! empty($aliado->slug)) {
                return;
            }

            $base = \Illuminate\Support\Str::limit(\Illuminate\Support\Str::slug($aliado->nombre) ?: 'aliado', 70, '');
            $slug = $base;
            for ($i = 2; static::withTrashed()->where('slug', $slug)->exists(); $i++) {
                $slug = "{$base}-{$i}";
            }
            $aliado->slug = $slug;
        });
    }

    // Usuario BryNex asignado por defecto como encargado de afiliación
    public function encargadoAfiliacion(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'encargado_afil_id');
    }

    // Usuarios que tienen este aliado como empresa principal
    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class, 'aliado_id');
    }

    // Usuarios BryNex con acceso a este aliado (pivot)
    public function usuariosBrynex(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'aliado_user', 'aliado_id', 'user_id')
                    ->withPivot('rol', 'activo')
                    ->withTimestamps();
    }

    // Scope: solo aliados activos
    public function scopeActivos($query)
    {
        return $query->where('activo', true);
    }

    // Configuración WhatsApp Business API de este aliado
    public function whatsappConfig(): HasOne
    {
        return $this->hasOne(WhatsappConfig::class, 'aliado_id');
    }

    // Configuración de la página web pública (/aliado/{slug})
    public function paginaConfig(): HasOne
    {
        return $this->hasOne(PaginaAliadoConfig::class, 'aliado_id');
    }

    // Preguntas frecuentes de la página pública
    public function paginaFaqs(): HasMany
    {
        return $this->hasMany(PaginaFaq::class, 'aliado_id')->where('activo', true)->orderBy('orden');
    }

    // Módulos de Brynex contratados por este aliado
    public function modulosBrynex(): HasMany
    {
        return $this->hasMany(BrynexModuloAliado::class, 'aliado_id');
    }

    // Cobros mensuales de Brynex hacia este aliado
    public function cobrosBrynex(): HasMany
    {
        return $this->hasMany(BrynexCobroAliado::class, 'aliado_id')->orderByDesc('anio')->orderByDesc('mes');
    }
}
