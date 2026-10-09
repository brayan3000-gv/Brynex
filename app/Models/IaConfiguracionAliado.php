<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class IaConfiguracionAliado extends BaseModel
{
    protected $table = 'ia_configuracion_aliado';

    protected $fillable = [
        'aliado_id',
        'proveedor',
        'usa_cuenta_brynex',
        'api_key',
        'gemini_api_key',
        'modelo',
        'nombre_bot',
        'activo_web',
        'activo_whatsapp',
    ];

    protected $casts = [
        'usa_cuenta_brynex' => 'boolean',
        'activo_web'        => 'boolean',
        'activo_whatsapp'   => 'boolean',
    ];

    public function aliado(): BelongsTo
    {
        return $this->belongsTo(Aliado::class);
    }

    // ── Accesor/Mutador para api_key encriptada ──────────────────────

    public function setApiKeyAttribute(?string $value): void
    {
        $this->attributes['api_key'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getApiKeyAttribute(?string $value): ?string
    {
        if (!$value) return null;
        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    // ── Accesor/Mutador para la clave de Gemini (solo generación de imágenes en publicidad) ──

    public function setGeminiApiKeyAttribute(?string $value): void
    {
        $this->attributes['gemini_api_key'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getGeminiApiKeyAttribute(?string $value): ?string
    {
        if (!$value) return null;
        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function tieneGemini(): bool
    {
        return !empty($this->gemini_api_key);
    }

    /**
     * Credenciales efectivas: propias del aliado o la clave global de Brynex
     * para el proveedor configurado (ver ConfiguracionBrynex ia_global_*).
     */
    public function credencialesEfectivas(): array
    {
        $proveedor = $this->proveedor ?: 'claude';

        if (!$this->usa_cuenta_brynex && $this->api_key) {
            return [
                'proveedor' => $proveedor,
                'api_key'   => $this->api_key,
                'modelo'    => $this->modelo ?: IaConfiguracionAliado::modeloGlobalPorDefecto($proveedor),
            ];
        }

        $claveEncriptada = ConfiguracionBrynex::obtener("ia_global_{$proveedor}_api_key");
        $apiKey = null;
        if ($claveEncriptada) {
            try {
                $apiKey = Crypt::decryptString($claveEncriptada);
            } catch (\Exception $e) {
                $apiKey = null;
            }
        }

        return [
            'proveedor' => $proveedor,
            'api_key'   => $apiKey,
            'modelo'    => $this->modelo ?: IaConfiguracionAliado::modeloGlobalPorDefecto($proveedor),
        ];
    }

    /** Nombre con el que se presenta el asistente para este aliado. */
    public function nombreBot(): string
    {
        return $this->nombre_bot ?: 'Asistente Virtual';
    }

    public static function modeloGlobalPorDefecto(string $proveedor): string
    {
        $clave = "ia_modelo_{$proveedor}_default";
        $default = match ($proveedor) {
            'openai' => 'gpt-4o-mini',
            'gemini' => 'gemini-3.6-flash',
            default  => 'claude-haiku-5-5',
        };
        return (string) ConfiguracionBrynex::obtener($clave, $default);
    }

    /** Obtiene o crea la configuración IA para un aliado (por defecto: cuenta Brynex, inactivo). */
    public static function paraAliado(int $alidoId): static
    {
        return static::firstOrCreate(
            ['aliado_id' => $alidoId],
            ['proveedor' => 'claude', 'usa_cuenta_brynex' => true]
        );
    }
}
