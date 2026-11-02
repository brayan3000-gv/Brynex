<?php

namespace App\Models;

class BancoCuenta extends BaseModel
{
    protected $table = 'banco_cuentas';

    protected $fillable = [
        'aliado_id', 'nombre', 'nit', 'banco',
        'tipo_cuenta', 'numero_cuenta', 'activo', 'cobro', 'facturacion', 'incapacidad',
        // Cuenta personal por la que también pasa la operación: su extracto trae
        // movimientos que el libro del negocio nunca va a tener.
        'uso_personal',
        'observacion', 'llave',
    ];

    protected $casts = [
        'activo' => 'boolean',
        'cobro' => 'boolean',
        'facturacion' => 'boolean',
        'incapacidad' => 'boolean',
        'uso_personal' => 'boolean',
    ];

    /**
     * Formatos que acepta una llave Bre-B.
     *
     *   @alias           alfanumérica que el titular elige
     *   correo          el registrado en el banco
     *   celular         10 dígitos, empieza en 3
     *   documento       cédula o NIT
     *   código de comercio  numérico, el que usan los negocios
     *
     * Los dos últimos son solo dígitos y no se distinguen entre sí, así que la
     * regla es a propósito permisiva: rechaza lo que evidentemente no es una
     * llave (espacios, símbolos sueltos, tres caracteres), no lo que no logra
     * clasificar. Una llave buena rechazada le cuesta un cobro al aliado.
     */
    const REGEX_LLAVE = '/^(@[A-Za-z0-9._-]{2,30}|[^@\s]+@[^@\s]+\.[A-Za-z]{2,}|\d{5,20})$/';

    /**
     * ¿El valor tiene forma de llave Bre-B?
     *
     * Vive aquí y no como regla `regex:` suelta en el controlador para no
     * repetir la expresión en los dos métodos que guardan cuentas, y para que
     * cualquier otra entrada de llaves (una importación, el asistente) valide
     * con el mismo criterio.
     */
    public static function llaveValida(?string $llave): bool
    {
        $llave = trim((string) $llave);

        return $llave === '' || (bool) preg_match(self::REGEX_LLAVE, $llave);
    }

    /** Etiqueta legible del tipo de llave, deducida del formato. */
    public function getTipoLlaveAttribute(): ?string
    {
        $llave = trim((string) $this->llave);

        if ($llave === '') {
            return null;
        }
        if (str_starts_with($llave, '@')) {
            return 'alias';
        }
        if (str_contains($llave, '@')) {
            return 'correo';
        }
        if (preg_match('/^3\d{9}$/', $llave)) {
            return 'celular';
        }
        if (ctype_digit($llave)) {
            return 'documento o código de comercio';
        }

        return null;   // no cuadra con ningún formato conocido
    }

    /**
     * Cómo se le presenta esta cuenta al cliente que va a pagar.
     *
     * Antes esto estaba copiado en cinco lugares (el job de envío masivo y tres
     * pantallas de cobros), y decía «o llave X», que se lee como si la llave
     * fuera un número de cuenta alterno. Con Bre-B ya no: la llave es su propio
     * medio de pago y conviene nombrarla como el cliente la ve en su banco.
     */
    public function getTextoCobroAttribute(): string
    {
        $tipo = $this->tipo_cuenta ? " {$this->tipo_cuenta}" : '';
        $llave = $this->llave ? " — Llave Bre-B: {$this->llave}" : '';

        return "{$this->banco}{$tipo} {$this->numero_cuenta} {$this->nombre}{$llave}";
    }

    /** Las cuentas de cobro del aliado, ya formateadas para el mensaje. */
    public static function textoCobro(int $aliadoId): string
    {
        $texto = static::paraCobro($aliadoId)
            ->map(fn ($bc) => $bc->texto_cobro)
            ->join('  •  ');

        return $texto !== '' ? '•  '.$texto : 'no tiene configurada';
    }

    /**
     * Cuentas que salen en la cuenta de cobro y todavía no tienen llave.
     *
     * Sin llave el cliente tiene que copiar el número de cuenta a mano; con
     * llave paga en segundos desde cualquier banco. Por eso la pantalla de
     * cuentas las señala en vez de dejarlas pasar en silencio.
     */
    public static function cobroSinLlave(int $aliadoId)
    {
        return static::where('aliado_id', $aliadoId)
            ->where('activo', true)
            ->where('cobro', true)
            ->where(function ($q) {
                $q->whereNull('llave')->orWhere('llave', '');
            })
            ->get();
    }

    public function getEtiquetaAttribute(): string
    {
        return "{$this->banco} — {$this->nombre} | {$this->tipo_cuenta} {$this->numero_cuenta}";
    }

    public static function activas(int $aliadoId)
    {
        return static::where('aliado_id', $aliadoId)
            ->where('activo', true)
            ->orderBy('banco')
            ->get();
    }

    /** Cuentas marcadas para aparecer en la Cuenta de Cobro */
    public static function paraCobro(int $aliadoId)
    {
        return static::where('aliado_id', $aliadoId)
            ->where('activo', true)
            ->where('cobro', true)
            ->orderBy('banco')
            ->get();
    }

    /**
     * Cuentas que pueden escogerse al facturar y al registrar movimientos
     * internos (gastos, comisiones, préstamos, cuadre diario, planos).
     */
    public static function paraFacturacion(int $aliadoId)
    {
        return static::where('aliado_id', $aliadoId)
            ->where('activo', true)
            ->where('facturacion', true)
            ->orderBy('banco')
            ->get();
    }

    /**
     * Cuentas marcadas como destino de entradas de incapacidades.
     * Es una marca informativa: el selector de Incapacidades sigue
     * resolviéndose por el NIT de la razón social, no por esta bandera.
     */
    public static function paraIncapacidad(int $aliadoId)
    {
        return static::where('aliado_id', $aliadoId)
            ->where('activo', true)
            ->where('incapacidad', true)
            ->orderBy('banco')
            ->get();
    }

    /**
     * Logo del banco por su nombre (public/img/bancos, 96x96). Se busca por
     * palabra porque el campo `banco` es texto libre («BANCO DE BOGOTA»,
     * «NEQUI O BRE-B»…). null si no hay logo (Davivienda y Scotiabank aún no).
     */
    public static function logo(?string $banco): ?string
    {
        $b = mb_strtoupper(\Illuminate\Support\Str::ascii((string) $banco));
        foreach ([
            'DAVIPLATA' => 'daviplata', 'NEQUI' => 'nequi', 'BANCOLOMBIA' => 'bancolombia',
            'BBVA' => 'bbva', 'BOGOTA' => 'bogota', 'ITAU' => 'itau', 'CAJA SOCIAL' => 'cajasocial',
            'AGRARIO' => 'agrario', 'CITI' => 'citibank', 'VILLAS' => 'avvillas',
            'FALABELLA' => 'falabella', 'OCCIDENTE' => 'occidente', 'POPULAR' => 'popular',
        ] as $clave => $archivo) {
            if (str_contains($b, $clave)) {
                return asset("img/bancos/{$archivo}.png");
            }
        }

        return null;
    }
}
