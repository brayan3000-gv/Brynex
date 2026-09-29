<?php

namespace App\Models;

use App\Services\PilaCotizanteCalculator;
use App\Services\PilaCotizanteDosPasos;
use App\Services\UpcAdicionalService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Contrato extends BaseModel
{
    // ID auto-incremental (IDENTITY en SQL Server)
    public $incrementing = true;

    protected $keyType = 'int';

    protected $table = 'contratos';

    protected $fillable = [
        'aliado_id', 'cedula', 'estado',
        'razon_social_id', 'razon_social_bloqueada',
        'plan_id', 'tipo_modalidad_id',
        'eps_id', 'pension_id', 'arl_id', 'n_arl', 'arl_modo', 'arl_nit_cotizante', 'caja_id',
        'cargo', 'fecha_ingreso', 'fecha_retiro', 'fecha_retiro_pendiente', 'retiro_pendiente_cobrar_admon', 'actividad_economica_id',
        'salario', 'ibc', 'porcentaje_caja', 'dias_tp_afp', 'dias_tp_caja', 'grupo_fondo_solidaridad',
        'administracion', 'admon_asesor', 'costo_afiliacion', 'afiliacion_asesor', 'seguro', 'seguro_id',
        'asesor_id', 'encargado_id',
        'motivo_afiliacion_id', 'motivo_retiro_id',
        'fecha_arl', 'envio_planilla', 'fecha_probable_pago', 'modo_probable_pago',
        'observacion', 'observacion_afiliacion', 'observacion_llamada', 'np',
        'fecha_created', 'cobra_planilla_primer_mes',
        'paga_mes_actual',
    ];

    protected $casts = [
        'fecha_ingreso' => 'date',
        'fecha_retiro' => 'date',
        'fecha_retiro_pendiente' => 'date',
        'retiro_pendiente_cobrar_admon' => 'boolean',
        'fecha_arl' => 'date',
        'razon_social_bloqueada' => 'boolean',
        'salario' => 'decimal:2',
        'ibc' => 'decimal:2',
        'administracion' => 'decimal:2',
        'admon_asesor' => 'decimal:2',
        'costo_afiliacion' => 'decimal:2',
        'afiliacion_asesor' => 'decimal:2',
        'seguro' => 'decimal:2',
        'porcentaje_caja' => 'decimal:2',
        // sqlsrv devuelve los enteros como string: sin el cast, un === int falla en silencio.
        'dias_tp_afp' => 'integer',
        'dias_tp_caja' => 'integer',
        'cobra_planilla_primer_mes' => 'boolean',
        'paga_mes_actual' => 'boolean',
    ];

    // ── Relaciones ──

    public function aliado(): BelongsTo
    {
        return $this->belongsTo(Aliado::class);
    }

    /**
     * El cliente del contrato, siempre del mismo aliado.
     *
     * La cédula sola no identifica a un cliente: la misma persona existe como
     * cliente en varios aliados, con datos distintos. Por eso la relación es un
     * BelongsToDelAliado y no un belongsTo con un where encima — ese where solo
     * se aplicaba en carga perezosa y en `with('cliente')` traía el cliente de
     * cualquier aliado.
     */
    public function cliente(): BelongsTo
    {
        return $this->belongsToDelAliado(Cliente::class, 'cedula', 'cedula', 'cliente');
    }

    public function razonSocial(): BelongsTo
    {
        return $this->belongsTo(RazonSocial::class, 'razon_social_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlanContrato::class, 'plan_id');
    }

    /**
     * El seguro del catálogo del aliado que se le vendió, si lleva alguno.
     *
     * Se llama `seguroPlan` y no `seguro` porque `seguro` ya es la columna con el valor
     * cobrado: cuál se vendió lo dice la relación, cuánto se cobra lo dice el campo.
     */
    public function seguroPlan(): BelongsTo
    {
        return $this->belongsTo(AliadoSeguro::class, 'seguro_id');
    }

    public function tipoModalidad(): BelongsTo
    {
        return $this->belongsTo(TipoModalidad::class, 'tipo_modalidad_id');
    }

    public function eps(): BelongsTo
    {
        return $this->belongsTo(Eps::class);
    }

    public function pension(): BelongsTo
    {
        return $this->belongsTo(Pension::class);
    }

    public function arl(): BelongsTo
    {
        return $this->belongsTo(Arl::class);
    }

    public function caja(): BelongsTo
    {
        return $this->belongsTo(Caja::class);
    }

    public function actividadEconomica(): BelongsTo
    {
        return $this->belongsTo(ActividadEconomica::class, 'actividad_economica_id');
    }

    public function asesor(): BelongsTo
    {
        return $this->belongsTo(Asesor::class);
    }

    public function encargado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'encargado_id');
    }

    public function motivoAfiliacion(): BelongsTo
    {
        return $this->belongsTo(MotivoAfiliacion::class, 'motivo_afiliacion_id');
    }

    public function motivoRetiro(): BelongsTo
    {
        return $this->belongsTo(MotivoRetiro::class, 'motivo_retiro_id');
    }

    public function radicados(): HasMany
    {
        return $this->hasMany(Radicado::class);
    }

    public function facturas(): HasMany
    {
        return $this->hasMany(Factura::class, 'contrato_id');
    }

    // ── Helpers de estado ──

    /**
     * Modalidades que admiten cotizar el mes en curso: Independientes (10),
     * ARL Tipo Y (8), En el Exterior (14) y Tiempo Parcial Independiente (18).
     *
     * En la Y y en el exterior es práctica del operador más que de la norma
     * —el Decreto 1273/2018 manda mes vencido para el contratista—, pero Enlace
     * liquida las dos con el período del mes de pago.
     */
    const MODALIDADES_MES_ACTUAL = [8, 10, 14, 18];

    /**
     * ¿El contrato cotiza el mes en curso en vez del vencido?
     *
     * Es un atributo del contrato, no de la modalidad: aplica igual a
     * Independientes, a la Y de solo ARL y a los del exterior. Antes era la
     * modalidad 11, jubilada al nacer este campo.
     */
    public function pagaMesActual(): bool
    {
        return (bool) $this->paga_mes_actual;
    }

    public function estaVigente(): bool
    {
        return $this->estado === 'vigente';
    }

    public function estaRetirado(): bool
    {
        return $this->estado === 'retirado';
    }

    /** ¿Es Fondo de Solidaridad (PSAP, cotizante 33)? */
    public function esFondoSolidaridad(): bool
    {
        return (int) $this->tipo_modalidad_id === TipoModalidad::ID_FONDO_SOLIDARIDAD;
    }

    /** ¿Es modalidad independiente? */
    public function esIndependiente(): bool
    {
        return $this->tipoModalidad && $this->tipoModalidad->esIndependiente();
    }

    /**
     * Cargo fijo de $100 cuando el cliente es dependiente E o Ingreso-Retiro
     * y su plan NO incluye Caja de Compensación (sin CCF).
     * Estos $100 se pagan a la caja y van en v_caja de la factura.
     * Solo aplica en planilla (no en afiliación pura).
     */
    const CARGO_SIN_CCF = 100;

    /** IDs de modalidades que generan el cargo sin-CCF (dependiente, no independiente) */
    const IDS_SIN_CCF = [0, 12]; // 0 = Dependiente E, 12 = Ingreso-Retiro

    /** Modalidad UPC: afiliar a alguien fuera del núcleo familiar del cotizante. Solo EPS. */
    const MODALIDAD_UPC = 13;

    /**
     * Modalidad Seguros: a esta persona solo se le vendió un seguro. No lleva EPS, ARL,
     * pensión ni caja, no entra en planilla y no paga administración — el mes vale lo que
     * valga el seguro. Ver AliadoSeguro y `seguro_id`.
     */
    const MODALIDAD_SEGUROS = 17;

    /** ¿Este contrato es de solo seguro (sin seguridad social)? */
    public function esSoloSeguro(): bool
    {
        return (int) $this->tipo_modalidad_id === self::MODALIDAD_SEGUROS;
    }

    public function aplicaCargoSinCcf(): bool
    {
        $esDependienteTarget = in_array((int) $this->tipo_modalidad_id, self::IDS_SIN_CCF);
        $sinCaja = $this->plan && ! $this->plan->incluye_caja;

        return $esDependienteTarget && $sinCaja;
    }

    /** Caché por empresa de la marca de exoneración, para no repetir la consulta por contrato. */
    private static array $cacheExoneracion = [];

    /**
     * ¿El aportante de este contrato paga SENA e ICBF?
     *
     * Casi todo empleador está exonerado (art. 114-1 ET) y por eso la salud a su
     * cargo es del 4% y no liquida parafiscales — es lo que el sistema asumía para
     * todos. No lo están los aportantes que no declaran renta (sindicatos y demás
     * entidades del art. 23 del ET) ni la persona natural con un solo trabajador:
     * ahí la salud va al 12,5% y encima se pagan SENA e ICBF. La marca vive en la
     * empresa, que es quien tiene el NIT del aportante — la razón social del
     * contrato suele ser una genérica compartida por muchos clientes.
     *
     * Solo aplica a las modalidades de dependiente puro: el tiempo parcial (51) y
     * el independiente (59, 76) nunca llevan parafiscales, sin importar quién sea
     * el aportante.
     */
    public function pagaParafiscales(): bool
    {
        if (! in_array((int) $this->tipo_modalidad_id, self::IDS_SIN_CCF, true)) {
            return false;
        }

        $empresaId = (int) ($this->empresaAportanteId() ?? 0);
        if ($empresaId <= 0) {
            return false;   // sin empresa no hay a quién preguntarle: se calcula como siempre
        }

        $clave = $this->aliado_id.':'.$empresaId;
        if (! array_key_exists($clave, self::$cacheExoneracion)) {
            $valor = DB::table('empresas')
                ->where('id', $empresaId)
                ->where('aliado_id', $this->aliado_id)
                ->value('exonerado_parafiscales');

            // Sin fila no hay respuesta, y `null` no es un "no está exonerada":
            // muchos clientes arrastran un `cod_empresa` que apunta a una empresa
            // de otro aliado (la 1, sobre todo), así que la consulta con el
            // aliado del contrato no encuentra nada. Tratarlo como no exonerada
            // le sumaba SENA e ICBF a la factura sin que nadie lo pidiera.
            self::$cacheExoneracion[$clave] = $valor === null ? true : (bool) $valor;
        }

        return ! self::$cacheExoneracion[$clave];
    }

    /** La empresa a la que pertenece el cliente: el vínculo vive en `clientes.cod_empresa`. */
    private function empresaAportanteId(): ?int
    {
        if ($this->relationLoaded('cliente') && $this->cliente) {
            return $this->cliente->cod_empresa ? (int) $this->cliente->cod_empresa : null;
        }

        $id = DB::table('clientes')
            ->where('aliado_id', $this->aliado_id)
            ->where('cedula', $this->cedula)
            ->value('cod_empresa');

        return $id ? (int) $id : null;
    }

    /**
     * Menos de estos dígitos no es un NIT, es basura del legacy: la razón social
     * "RAZON SOCIAL" que GiMave arrastra trae `nit = "2"` (ver nitParaMora).
     */
    private const MIN_DIGITOS_NIT = 5;

    /** Caché por empresa del NIT del aportante, para no repetir la consulta por contrato. */
    private static array $cacheNitEmpresa = [];

    /**
     * El NIT con el que se decide cuándo vence la planilla de este contrato, y
     * por tanto cuánta mora se le cobra al cliente: los dos últimos dígitos dan
     * el día hábil de vencimiento (Decreto 1990 de 2016).
     *
     * El independiente responde con su cédula. El dependiente, con el NIT de su
     * razón social — salvo que esa razón social sea una de las genéricas que el
     * legacy dejó sin NIT real ("RAZON SOCIAL" con nit "2", "PROCESOS" sin nit):
     * ahí manda el NIT de la empresa del cliente, que es el aportante de verdad.
     * Con el "2" el sistema creía que todo vencía el 2º día hábil —el más
     * temprano de la tabla— y cobraba mora que no existía (AGROMACZO, sep-2026:
     * $6.265 cobrados donde con su NIT real la planilla ni siquiera había
     * vencido). Mismo criterio que pagaParafiscales(): el NIT del aportante vive
     * en la empresa, no en la razón social compartida.
     */
    public function nitParaMora(): int
    {
        $rs = $this->razonSocial;

        if ($this->esIndependiente() || ($rs && $rs->es_independiente)) {
            return (int) $this->cedula;
        }

        $nitRs = preg_replace('/\D/', '', (string) ($rs->nit ?? ''));
        if (strlen($nitRs) >= self::MIN_DIGITOS_NIT) {
            return (int) $nitRs;
        }

        $nitEmpresa = $this->nitEmpresaAportante();
        if ($nitEmpresa > 0) {
            return $nitEmpresa;
        }

        // Sin NIT por ningún lado se conserva el criterio viejo (el id de la RS):
        // es arbitrario, pero al menos todos los contratos de esa RS vencen igual.
        return $rs ? (int) $rs->id : 0;
    }

    /** Día hábil de vencimiento fijado a mano en la razón social, si lo hay. */
    public function diaHabilParaMora(): ?int
    {
        $rs = $this->razonSocial;

        if ($this->esIndependiente() || ($rs && $rs->es_independiente)) {
            return null;
        }

        return $rs && $rs->dia_habil !== null ? (int) $rs->dia_habil : null;
    }

    /** NIT de la empresa del cliente, o 0 si no la hay o tampoco tiene NIT usable. */
    private function nitEmpresaAportante(): int
    {
        $empresaId = (int) ($this->empresaAportanteId() ?? 0);
        if ($empresaId <= 0) {
            return 0;
        }

        $clave = $this->aliado_id.':'.$empresaId;
        if (! array_key_exists($clave, self::$cacheNitEmpresa)) {
            // Filtrando por aliado: muchos clientes arrastran un cod_empresa que
            // apunta a la empresa de otro aliado (ver pagaParafiscales()).
            $nit = DB::table('empresas')
                ->where('id', $empresaId)
                ->where('aliado_id', $this->aliado_id)
                ->value('nit');

            $nit = preg_replace('/\D/', '', (string) $nit);
            self::$cacheNitEmpresa[$clave] = strlen($nit) >= self::MIN_DIGITOS_NIT ? (int) $nit : 0;
        }

        return self::$cacheNitEmpresa[$clave];
    }

    /**
     * Días a cotizar por entidad en Tiempo Parcial: ['arl' => 30, 'afp' => X, 'caja' => Y].
     *
     * Manda el contrato y, si no dice nada, el catálogo. Así conviven los dos
     * modelos: el Tiempo Parcial de dependientes, donde los días son la
     * modalidad misma (TP(7), TP(7-14)…), y el Tiempo Parcial Independiente,
     * donde son un campo del contrato porque cambian mes a mes.
     *
     * La caja cae en los días de AFP cuando no se dice otra cosa: pagar caja
     * por más semanas que pensión es la excepción, no la regla.
     */
    public function diasTiempoParcial(): array
    {
        $mod = $this->tipoModalidad;

        if (! $mod || ! $mod->esTiempoParcial()) {
            return ['arl' => 30, 'afp' => 30, 'caja' => 30];
        }

        $afp = $this->dias_tp_afp ?? $mod->dias_afp ?? 30;

        return [
            'arl' => 30,   // la ARL de tiempo parcial siempre se paga por el mes completo
            'afp' => (int) $afp,
            'caja' => (int) ($this->dias_tp_caja ?? $mod->dias_caja ?? $afp),
        ];
    }

    /**
     * Lo que se le declara a la caja de compensación: sueldo y jornada.
     *
     * En Tiempo Parcial el salario del contrato es el de pensión —un TP(7-14)
     * son 7 días de AFP y 14 de caja—, así que declararlo tal cual deja al
     * trabajador con la mitad del subsidio que le corresponde. La caja cotiza
     * por sus propias semanas (Decreto 2616 de 2013): el sueldo es el mínimo
     * por semana cotizada, y la jornada tiene que ir en la misma proporción
     * porque los portales validan que una cosa cuadre con la otra
     * (Comfandi: "El salario no es proporcional a las horas diarias trabajadas").
     *
     * 'salario_mes' es el sueldo de la jornada completa, con el que el portal
     * hace esa proporción; 'salario' es ya el declarado para esa jornada.
     *
     * @return array{salario: int, salario_mes: int, horas: int, dias: int}
     */
    public function baseCaja(): array
    {
        $salario = (int) round((float) ($this->salario ?: $this->ibc));

        if (! $this->tipoModalidad?->esTiempoParcial()) {
            return ['salario' => $salario, 'salario_mes' => $salario, 'horas' => 8, 'dias' => 30];
        }

        $dias = (int) $this->diasTiempoParcial()['caja'];
        $semanas = max(1, min(4, (int) ceil($dias / 7)));
        $sm = (int) ConfiguracionBrynex::salarioMinimo();

        return [
            // El IBC semanal queda fraccionado (SM/4): se redondea hacia arriba,
            // igual que en la planilla, para que no se separen por un peso.
            'salario' => (int) ceil($sm / 4 * $semanas),
            'salario_mes' => $sm,
            'horas' => 2 * $semanas,   // 8 horas es el mes completo (4 semanas)
            'dias' => $dias,
        ];
    }

    // ── COTIZADOR ──

    /**
     * Calcula el resumen de aportes según la modalidad del contrato.
     * Retorna un array con: eps, arl, pension, caja, ss, seguro, admon,
     * iva, total, y ibc (base de cotización).
     *
     * Para modalidades de Tiempo Parcial:
     *   - Cada entidad (ARL, AFP, CAJA) tiene sus propios días fijos.
     *   - No se usa $dias global para SS; se usan days del tipo_modalidad.
     *
     * @param  int  $dias  Días cotizados en el mes (1-30). Ignorado en Tiempo Parcial.
     */
    public function calcularCotizacion(int $dias = 30, ?bool $ivaCliente = null): array
    {
        $ibcRaw = (float) ($this->ibc ?? 0);
        $salRaw = (float) ($this->salario ?? 0);
        $alidoId = $this->aliado_id;
        $nivelArl = (int) ($this->n_arl ?? 1);
        $esIndep = $this->esIndependiente();
        $mod = $this->tipoModalidad;
        $esTP = $mod && $mod->esTiempoParcial();

        // Independientes cotizan sobre IBC; dependientes (razón social) sobre salario.
        // Si el campo principal es 0 (legacy), usar el otro como fallback.
        if ($esIndep) {
            $ibc = $ibcRaw > 0 ? $ibcRaw : $salRaw;
        } else {
            $ibc = $salRaw > 0 ? $salRaw : $ibcRaw;
        }

        // Obtener porcentajes
        if ($esIndep) {
            $pctEps = ConfiguracionBrynex::pctSaludIndependiente();
            $pctPen = ConfiguracionBrynex::pctPensionIndependiente();
            $pctCaja = (float) ($this->porcentaje_caja ?? ConfiguracionBrynex::pctCajaIndependienteAlto());
        } else {
            $pctEps = ConfiguracionBrynex::pctSaludDependiente();
            $pctPen = ConfiguracionBrynex::pctPensionDependiente();
            $pctCaja = ConfiguracionBrynex::pctCajaDependiente();
        }

        // Fondo de Solidaridad: el subsidio es sobre un salario mínimo, ni más ni
        // menos, y la pensión va a la tarifa del grupo (4 %, 4,8 %…) en vez del
        // 16 %. La salud sí va completa, al 12,5 % del independiente.
        if ($this->esFondoSolidaridad()) {
            $ibc = ConfiguracionBrynex::salarioMinimo();
            $pctPen = TipoModalidad::pctPensionFondoSolidaridad($this->grupo_fondo_solidaridad);
        }

        // Aportante no exonerado (ver pagaParafiscales): la salud deja de ser el
        // 4% del empleador exonerado y pasa al 12,5% completo, y se suman SENA e
        // ICBF. La caja no cambia: el dependiente ya cotiza al 4%.
        $pagaParafiscales = $this->pagaParafiscales();
        if ($pagaParafiscales) {
            $pctEps = ConfiguracionBrynex::pctSaludNoExonerado();
        }
        // SENA e ICBF son dos aportes distintos en la planilla y cada uno se
        // redondea por su lado. Liquidarlos juntos al 5% da hasta $100 de menos
        // que el operador (sobre un mínimo: 35.100 + 52.600 = 87.700, no 87.600).
        $pctSena = $pagaParafiscales ? ConfiguracionBrynex::pctSena() : 0.0;
        $pctIcbf = $pagaParafiscales ? ConfiguracionBrynex::pctIcbf() : 0.0;

        $pctArl = ArlTarifa::porcentajePara($nivelArl, $alidoId);
        $plan = $this->plan;
        $r = fn ($v) => (int) (ceil($v / 100) * 100);

        if ($esTP) {
            // ── Tiempo Parcial: IBC diferente por entidad, sin EPS ─────────
            // Regla confirmada:
            //   ARL  = SM_completo × tasaArl  (cotiza mes completo)
            //   AFP  = SM × factor_afp × pctPen  (factor = dias_afp/28 aprox)
            //   CAJA = SM × factor_caja × pctCaja (puede diferir de AFP)
            $diasP = $this->diasTiempoParcial(); // ['arl'=>30, 'afp'=>7, 'caja'=>14, ...]
            $factorMap = [7 => 0.25, 14 => 0.50, 21 => 0.75, 30 => 1.00];
            $factorAfp = $factorMap[$diasP['afp']] ?? 1.0;
            $factorCaja = $factorMap[$diasP['caja']] ?? 1.0;

            // SM desde ConfiguracionBrynex (fuente correcta del sistema)
            $sm = (float) ConfiguracionBrynex::obtener('salario_minimo', 1423500);

            $ibcArl = $sm;                       // ARL siempre SM completo
            $ibcAfp = round($sm * $factorAfp);   // AFP según factor dias_afp
            $ibcCaja = round($sm * $factorCaja);  // CAJA según factor dias_caja

            $eps = 0;
            $arl = ($plan && $plan->incluye_arl) ? $r($ibcArl * $pctArl / 100) : 0;
            $pen = ($plan && $plan->incluye_pension) ? $r($ibcAfp * $pctPen / 100) : 0;
            $caja = ($plan && $plan->incluye_caja) ? $r($ibcCaja * $pctCaja / 100) : 0;
            $parafiscales = 0;
        } elseif ((int) $this->tipo_modalidad_id === self::MODALIDAD_UPC) {
            // ── UPC adicional: EPS no es % de IBC, es la tarifa fija por edad/
            //    sexo/zona del beneficiario (Resolución 2764/2025). Siempre es
            //    solo salud: nunca lleva ARL, pensión ni caja. Se prorratea por
            //    días igual que las demás modalidades (confirmado con Brayan:
            //    el mes de arrastre tras el ingreso cobra proporcional, no el
            //    mes completo).
            $cliente = $this->cliente;
            $eps = 0;
            if ($cliente) {
                $upc = UpcAdicionalService::valorParaCliente($cliente);
                $eps = (int) ($upc['valor'] ?? 0);
            }
            $arl = 0;
            $pen = 0;
            $caja = 0;
            $parafiscales = 0;

            if ($dias < 30) {
                $eps = (int) (ceil($eps * $dias / 30 / 100) * 100);
            }
        } else {
            // ── Normal: calcular por mes completo y prorratear si $dias < 30 ─
            // Mes completo: ceil al centena superior para garantizar múltiplos de 100.
            $eps = ($plan && $plan->incluye_eps) ? $r($ibc * $pctEps / 100) : 0;
            $arl = ($plan && $plan->incluye_arl) ? $r($ibc * $pctArl / 100) : 0;
            $pen = ($plan && $plan->incluye_pension) ? $r($ibc * $pctPen / 100) : 0;
            $caja = ($plan && $plan->incluye_caja) ? $r($ibc * $pctCaja / 100) : 0;
            // SENA e ICBF se liquidan sobre el IBC completo, tenga el plan caja o no.
            $parafiscales = $pagaParafiscales ? $r($ibc * $pctSena / 100) + $r($ibc * $pctIcbf / 100) : 0;

            if ($dias < 30) {
                // Mes parcial: se prorratea el IBC y se redondea UNA sola vez,
                // igual que PilaCotizanteCalculator — que es de donde sale el
                // archivo plano y, por tanto, lo que el operador cobra de
                // verdad. Prorratear el aporte ya redondeado y volver a subirlo
                // al centenar (como se hacía antes) le sumaba hasta $100 por
                // subsistema y por persona, y la factura terminaba por encima
                // de la planilla sin que nadie pudiera explicar la diferencia.
                $ibcProp = (int) round($ibc * $dias / 30);

                $eps = ($plan && $plan->incluye_eps) ? $r($ibcProp * $pctEps / 100) : 0;
                $arl = ($plan && $plan->incluye_arl) ? $r($ibcProp * $pctArl / 100) : 0;
                $pen = ($plan && $plan->incluye_pension) ? $r($ibcProp * $pctPen / 100) : 0;
                $caja = ($plan && $plan->incluye_caja) ? $r($ibcProp * $pctCaja / 100) : 0;
                $parafiscales = $pagaParafiscales ? $r($ibcProp * $pctSena / 100) + $r($ibcProp * $pctIcbf / 100) : 0;
            }

            // ── Cargo sin-CCF: dependiente E o Ingreso-Retiro sin caja ─────
            // Se cobra $100 fijos a la caja cuando el plan no incluye CCF.
            // Solo aplica en planilla (dias > 0), no en afiliación pura.
            if ($caja === 0 && $dias > 0 && $this->aplicaCargoSinCcf()) {
                $caja = self::CARGO_SIN_CCF;
            }
        }

        // ── Dos pasos: el día simbólico de pensión también se paga ─────────
        // El operador no acepta al cotizante 01 sin pensión, así que la
        // planilla de estas modalidades lleva un día a la AFP aunque el plan no
        // la venda (PilaCotizanteDosPasos). Sin cobrarlo aquí, la factura y el
        // valor del plano quedaban $9.400 por debajo de lo que se paga.
        if ($dias > 0 && $pen === 0 && in_array((int) $this->tipo_modalidad_id, TipoModalidad::IDS_DOS_PASOS, true)) {
            $pen = PilaCotizanteCalculator::roundPila(
                PilaCotizanteDosPasos::ibcUnDia((int) round($ibc)) * ConfiguracionBrynex::pctPensionDependiente() / 100
            );
        }

        $ss = $eps + $arl + $pen + $caja + $parafiscales;

        $seguro = (float) ($this->seguro ?? 0);
        $admon = (float) ($this->administracion ?? 0);
        $admonAsesor = (float) ($this->admon_asesor ?? 0);

        // IVA sobre la administración completa: la del aliado + la del asesor,
        // igual que IvaService::deFactura() al generar la factura real. La
        // afiliación se grava aparte, en el controlador: este método solo
        // cotiza planilla. Ojo: 'admon' y 'total' siguen SIN incluir la parte
        // del asesor — los consumidores la suman por su cuenta.
        // Admon, seguro e IVA son cargos fijos mensuales: NO se prorratean
        // $ivaCliente: pre-cargado por el controller (evita N+1).
        // Si viene null, se resuelve con la regla completa (cliente o su empresa).
        $tieneIva = $ivaCliente ?? \App\Services\IvaService::aplicaContrato($this);
        $iva = \App\Services\IvaService::calcular($admon + $admonAsesor, $tieneIva);

        $total = $ss + $seguro + $admon + $iva;

        return compact('ibc', 'eps', 'arl', 'pen', 'caja', 'parafiscales', 'ss', 'seguro', 'admon', 'iva', 'total');
    }

    /**
     * Crea automáticamente los radicados en estado 'pendiente'
     * según los servicios que incluye el plan del contrato.
     * Se llama desde el observer/controller al crear el contrato.
     */
    public function crearRadicadosPendientes(): void
    {
        if (! $this->plan) {
            return;
        }

        $nuevos = [];

        foreach ($this->plan->tiposRadicado() as $tipo) {
            // Solo si no existe ya
            if (! $this->radicados()->where('tipo', $tipo)->exists()) {
                $this->radicados()->create([
                    'aliado_id' => $this->aliado_id,
                    'tipo' => $tipo,
                    'estado' => Radicado::ESTADO_PENDIENTE,
                ]);
                $nuevos[] = $tipo;
            }
        }

        // La pensión no se tramita: la persona ya está en un fondo y el RUAF lo
        // dice. Se pregunta y el radicado nace cerrado. Va después del commit
        // porque esto corre dentro de la transacción que crea el contrato.
        if (in_array(Radicado::TIPO_PENSION, $nuevos, true)) {
            \App\Jobs\ConfirmarPensionConRuaf::dispatch($this->id, \Illuminate\Support\Facades\Auth::id())->afterCommit();
        }
    }

    /**
     * Precarga tarifas desde configuracion_aliado según el aliado y plan.
     * Se invoca al seleccionar el plan en el formulario (vía API).
     */
    public static function tarifasParaAliado(int $alidoId, ?int $planId): array
    {
        $cfg = ConfiguracionAliado::paraAliado($alidoId, $planId);
        if (! $cfg) {
            return [];
        }

        return [
            'administracion' => $cfg->administracion,
            'admon_asesor' => $cfg->admon_asesor,
            'costo_afiliacion' => $cfg->costo_afiliacion,
            'seguro' => $cfg->seguro_valor,
            'encargado_id' => $cfg->encargado_default_id,
        ];
    }
}
