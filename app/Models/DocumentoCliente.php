<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class DocumentoCliente extends BaseModel
{
    use HasFactory;

    protected $table = 'documentos_cliente';

    protected $fillable = [
        'aliado_id',
        'cc_cliente',
        'doc_beneficiario',
        'tipo_documento',
        'nombre_archivo',
        'ruta',
        'subido_por',
    ];

    // ── Relaciones ─────────────────────────────────────────
    public function cliente()
    {
        return $this->belongsTo(Cliente::class, 'cc_cliente', 'cedula');
    }

    public function subidor()
    {
        return $this->belongsTo(User::class, 'subido_por');
    }

    public function aliado()
    {
        return $this->belongsTo(Aliado::class);
    }

    // ── Accesores ──────────────────────────────────────────
    public function getTipoLegibleAttribute(): string
    {
        $tipos = [
            'cedula'              => 'Cédula',
            'carta_laboral'       => 'Carta Laboral',
            'registro_civil'      => 'Registro Civil',
            'tarjeta_identidad'   => 'Tarjeta Identidad',
            'decl_juramentada'    => 'Declaración Juramentada',
            'firma'               => 'Firma',
            'acta_matrimonio'     => 'Acta de Matrimonio',
            'otro'                => 'Otro',
        ];
        return $tipos[$this->tipo_documento] ?? ucfirst($this->tipo_documento);
    }

    public function esDeTitular(): bool
    {
        return is_null($this->doc_beneficiario);
    }

    // Scope multi-tenant
    public function scopeDeAliado($query, $alidoId)
    {
        return $query->where('aliado_id', $alidoId);
    }
}
