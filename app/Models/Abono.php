<?php

namespace App\Models;

use App\Models\BaseModel;

class Abono extends BaseModel
{
    protected $table    = 'abonos';
    protected $fillable = [
        'factura_id','pago_id','valor','forma_pago',
        'valor_efectivo','valor_consignado',
        'banco_cuenta_id','fecha','usuario_id','observacion',
        'soporte_path','soporte_nombre',
    ];

    protected $casts = ['fecha' => 'date'];

    public function factura() { return $this->belongsTo(Factura::class); }
    public function usuario() { return $this->belongsTo(User::class, 'usuario_id'); }
    public function bancoCuenta() { return $this->belongsTo(BancoCuenta::class, 'banco_cuenta_id'); }
}
