<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class WhatsappConfig extends BaseModel
{
    protected $table = 'whatsapp_configuracion_aliado';

    protected $fillable = [
        'aliado_id',
        'waba_id',
        'phone_number_id',
        'access_token',
        'numero_telefono',
        'nombre_cuenta',
        'usa_cuenta_brynex',
        'activo',
        'webhook_verificado',
        'cobro_plantilla_id',
        'cobro_header_imagen',
        'planilla_envio_plantilla_id',
    ];

    protected $casts = [
        'usa_cuenta_brynex'  => 'boolean',
        'activo'             => 'boolean',
        'webhook_verificado' => 'boolean',
    ];

    // ── Relaciones ──────────────────────────────────────────────────

    public function aliado(): BelongsTo
    {
        return $this->belongsTo(Aliado::class);
    }

    public function cobroPlantilla(): BelongsTo
    {
        return $this->belongsTo(WhatsappPlantilla::class, 'cobro_plantilla_id');
    }

    public function planillaEnvioPlantilla(): BelongsTo
    {
        return $this->belongsTo(WhatsappPlantilla::class, 'planilla_envio_plantilla_id');
    }

    // ── Accesor/Mutador para token encriptado ────────────────────────

    public function setAccessTokenAttribute(?string $value): void
    {
        $this->attributes['access_token'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getAccessTokenAttribute(?string $value): ?string
    {
        if (!$value) return null;
        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    // ── Métodos de negocio ───────────────────────────────────────────

    /**
     * Retorna las credenciales efectivas para hacer llamadas a Meta.
     * Si el aliado usa la cuenta de Brynex, retorna las del .env.
     * Si tiene su propia cuenta, retorna las del registro.
     */
    /**
     * Otro número de la misma cuenta (mismo token y WABA). No se guarda: lo pone quien
     * necesita hablar desde ese número, como la línea de gastos de GARVIS.
     */
    public ?string $phoneNumberIdForzado = null;

    public function credencialesEfectivas(): array
    {
        $creds = $this->credencialesPropias();

        if ($this->phoneNumberIdForzado) {
            $creds['phone_number_id'] = $this->phoneNumberIdForzado;
        }

        return $creds;
    }

    private function credencialesPropias(): array
    {
        if ($this->usa_cuenta_brynex) {
            $dbToken = \App\Models\ConfiguracionBrynex::obtener('whatsapp_global_access_token');
            if ($dbToken) {
                try {
                    $dbToken = \Illuminate\Support\Facades\Crypt::decryptString($dbToken);
                } catch (\Exception $e) {
                    // Fallback
                }
            }

            return [
                'waba_id'         => \App\Models\ConfiguracionBrynex::obtener('whatsapp_global_waba_id') ?: config('services.whatsapp.waba_id'),
                'phone_number_id' => \App\Models\ConfiguracionBrynex::obtener('whatsapp_global_phone_number_id') ?: config('services.whatsapp.phone_number_id'),
                'access_token'    => $dbToken ?: config('services.whatsapp.token'),
                'numero_telefono' => \App\Models\ConfiguracionBrynex::obtener('whatsapp_global_numero_telefono') ?: config('services.whatsapp.numero'),
            ];
        }

        return [
            'waba_id'         => $this->waba_id,
            'phone_number_id' => $this->phone_number_id,
            'access_token'    => $this->access_token,
            'numero_telefono' => $this->numero_telefono,
        ];
    }

    /**
     * Verifica si las credenciales están completas para hacer llamadas.
     */
    public function credencialesCompletas(): bool
    {
        $creds = $this->credencialesEfectivas();
        return !empty($creds['waba_id'])
            && !empty($creds['phone_number_id'])
            && !empty($creds['access_token']);
    }

    /**
     * Obtiene o crea la configuración de WhatsApp para un aliado.
     * Si no existe, la crea con usa_cuenta_brynex = true por defecto.
     */
    public static function paraAliado(int $alidoId): static
    {
        return static::firstOrCreate(
            ['aliado_id' => $alidoId],
            ['usa_cuenta_brynex' => true, 'activo' => true]
        );
    }
}
