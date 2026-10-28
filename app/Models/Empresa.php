<?php

namespace App\Models;

class Empresa extends BaseModel
{
    protected $table = 'empresas';

    protected $casts = [
        'factura_electronica' => 'boolean',
        'exonerado_parafiscales' => 'boolean',
        'cobrar_retiros_cortos' => 'boolean',
    ];

    /**
     * Lista blanca explícita. Antes era `$guarded = []`, que permitía escribir
     * cualquier columna vía asignación masiva. `id` y `aliado_id` van incluidos
     * a propósito: la tabla es legacy sin IDENTITY y ambos se asignan de forma
     * deliberada al crear la empresa (ver FacturacionController::storeEmpresa).
     */
    protected $fillable = [
        'id',
        'nit',
        'tipo_documento',
        'empresa',
        'nombre_legal',
        'factura_electronica',
        'exonerado_parafiscales',
        'contacto',
        'contacto_celular',
        'telefono',
        'celular',
        'direccion',
        'departamento_id',
        'municipio_id',
        'observacion',
        'cliente_de',
        'tipo_facturacion',
        'iva',
        'correo',
        'actividad_economica',
        'aliado_id',
        'encargado_id',
        'asesor_id',
    ];

    /** Documentos que caben en `nit`, que es un bigint: todos numéricos. */
    public const TIPOS_DOC = [
        'NIT' => 'NIT',
        'CC' => 'Cédula de ciudadanía',
        'CE' => 'Cédula de extranjería',
        'PT' => 'Permiso por Protección Temporal',
    ];

    /** ¿El empleador es una persona natural y no una sociedad? */
    public function esPersonaNatural(): bool
    {
        return $this->tipo_documento !== null && $this->tipo_documento !== 'NIT';
    }

    /**
     * El nombre que va a la factura electrónica.
     *
     * Ante la DIAN el adquiriente es quien tiene el documento, no el
     * establecimiento: una factura a la cédula de ANCIZAR GARCIA no puede
     * salir a nombre de MAXIDROGAS. Si no se ha capturado el nombre legal, se
     * cae al del negocio para no dejar la factura sin nombre.
     */
    public function nombreLegal(): string
    {
        return trim((string) ($this->nombre_legal ?: $this->empresa));
    }

    /**
     * A qué número se le escribe.
     *
     * Si la empresa tiene un encargado de la seguridad social con celular
     * propio, las cuentas de cobro van a él; si no, al número general de la
     * empresa.
     *
     * El envío de planillas NO pasa por aquí: allá el destinatario lo fija el
     * selector de la vista («Contacto de la Empresa» = `celular` de la empresa,
     * los otros dos = celular del cliente), y el listado tiene que mostrar de
     * antemano el mismo número al que va a salir el mensaje.
     */
    public function celularParaEnviar(): ?string
    {
        foreach ([$this->contacto_celular, $this->celular, $this->telefono] as $n) {
            if (filled($n)) {
                return $n;
            }
        }

        return null;
    }

    public function departamento()
    {
        return $this->belongsTo(Departamento::class, 'departamento_id');
    }

    public function municipio()
    {
        return $this->belongsTo(Ciudad::class, 'municipio_id');
    }

    public function aliado()
    {
        return $this->belongsTo(Aliado::class);
    }

    public function clientes()
    {
        return $this->hasMany(Cliente::class, 'cod_empresa', 'id');
    }

    public function asesor()
    {
        return $this->belongsTo(\App\Models\Asesor::class, 'asesor_id');
    }

    /**
     * Etiqueta para mostrar — si es Id=1 devuelve "Individual"
     */
    public function getLabelAttribute(): string
    {
        return (int) $this->id === 1
            ? 'Individual'
            : ($this->empresa ?: "Empresa #{$this->id}");
    }

    /**
     * Lista para selects: id => nombre
     */
    public static function listaParaSelect(?int $aliadoId = null)
    {
        return static::when($aliadoId, fn ($q) => $q->where('aliado_id', $aliadoId))
            ->orderBy('empresa')
            ->get(['id', 'empresa'])
            ->mapWithKeys(fn ($e) => [
                $e->id => (int) $e->id === 1 ? '— Individual —' : $e->empresa,
            ]);
    }
}
