<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CotizacionProspecto extends BaseModel
{
    use SoftDeletes;

    protected $table = 'cotizaciones_prospectos';

    public const ESTADOS = [
        'interesado' => 'Interesado',
        'sin_respuesta' => 'Sin Respuesta',
        'pendiente_resp' => 'Pendiente Respuesta',
        'no_interesado' => 'No Interesado',
        'convertido' => 'Convertido a Cliente',
    ];

    /** Estados en los que ya no se le hace seguimiento al prospecto. */
    public const ESTADOS_CERRADOS = ['no_interesado', 'convertido'];

    public const CANALES = [
        'redes_sociales' => 'Redes Sociales',
        'whatsapp' => 'WhatsApp',
        'campana' => 'Campaña Publicitaria',
        'referido' => 'Referido / Amigo',
        'empresa' => 'Empresa / Empleado',
    ];

    protected $fillable = [
        'aliado_id',
        'asesor_id',
        'tipo_doc',
        'cedula',
        'nombre_completo',
        'primer_nombre',
        'segundo_nombre',
        'primer_apellido',
        'segundo_apellido',
        'celular',
        'correo',
        'ocupacion',
        'es_independiente',
        'municipio_id',
        'municipio',
        'referido',
        'canal_origen',
        'modalidad_id',
        'plan_id',
        'salario_base',
        'fecha_ingreso',
        'n_arl',
        'costo_afiliacion',
        'administracion',
        'resultado_cotizacion',
        'estado',
        'razon_no_afiliacion',
        'cliente_id',
        'fecha_cotizacion',
        'proxima_llamada',
        'creado_por',
    ];

    protected $casts = [
        'fecha_cotizacion' => 'date',
        'proxima_llamada' => 'date',
        'fecha_ingreso' => 'date',
        'es_independiente' => 'boolean',
        'resultado_cotizacion' => 'array',
        'administracion' => 'decimal:2',
    ];

    public function getNombreCompletoAttribute($value)
    {
        if ($value) {
            return $value;
        }
        return trim("{$this->primer_nombre} {$this->segundo_nombre} {$this->primer_apellido} {$this->segundo_apellido}");
    }

    /**
     * Valor mensual cotizado. El cotizador del panel guarda el desglose en
     * `completo`; el asistente de IA solo deja `valor_mensual`. En Ingreso-Retiro
     * no hay mes completo y lo que se cobra es el proporcional.
     */
    public function getValorMensualAttribute(): ?float
    {
        $r = $this->resultado_cotizacion ?: [];
        $completo = (float) ($r['completo']['total'] ?? 0);
        $valor = $completo > 0
            ? $completo
            : ($r['proporcional']['total'] ?? $r['valor_mensual'] ?? null);

        return $valor ? (float) $valor : null;
    }

    // Relaciones
    public function aliado()
    {
        return $this->belongsTo(Aliado::class);
    }

    public function asesor()
    {
        return $this->belongsTo(Asesor::class);
    }

    public function municipio()
    {
        return $this->belongsTo(Ciudad::class, 'municipio_id');
    }

    public function modalidad()
    {
        return $this->belongsTo(TipoModalidad::class, 'modalidad_id');
    }

    public function plan()
    {
        return $this->belongsTo(PlanContrato::class, 'plan_id');
    }

    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'cliente_id', 'id');
    }

    public function gestiones()
    {
        return $this->hasMany(CotizacionGestion::class, 'cotizacion_id')->orderBy('created_at', 'desc');
    }

    public function creador()
    {
        return $this->belongsTo(User::class, 'creado_por');
    }
}
