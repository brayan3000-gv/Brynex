<?php

namespace App\Models;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Plano extends BaseModel
{
    use SoftDeletes;

    protected $table    = 'planos';
    protected $fillable = [
        'factura_id', 'contrato_id', 'aliado_id',
        // Tipo de registro
        'tipo_reg',               // 'afiliacion' | 'planilla'
        'tipo_doc',               // 'CC', 'CE', etc.
        'no_identifi',            // cédula
        // Número de factura (snapshot)
        'numero_factura',
        // Nombre (snapshot del cliente)
        'primer_ape', 'segundo_ape', 'primer_nombre', 'segundo_nombre',
        // Novedades
        'fecha_ing', 'fecha_ret',
        // Período y días
        'num_dias',               // días cotizados unificados
        'n_plano', 'mes_plano', 'anio_plano',
        // Salario
        'salario_basico',
        // Entidades (NIT snapshot al momento de facturar)
        'cod_eps',    // NIT de la EPS
        'nombre_eps', // Nombre snapshot EPS
        'cod_afp',    // NIT de la pensión
        'nombre_afp', // Nombre snapshot AFP
        'cod_arl',    // NIT de la ARL
        'nombre_arl', // Nombre snapshot ARL
        'cod_caja',   // NIT de la Caja
        'nombre_caja',// Nombre snapshot CAJA
        'nivel_riesgo',
        // Clasificación
        'razon_social',           // nombre RS (legacy, se mantiene por compatibilidad)
        'razon_social_id',        // ID de razones_sociales
        'tipo_p',                 // ID tipo modalidad (= tipo_modalidad_id, legacy smallint)
        'tipo_modalidad_id',      // ID de tipo_modalidad
        'paga_mes_actual',        // snapshot: el contrato pagaba el mes en curso
        'dias_tp_afp',            // snapshot: días de pensión del mes en tiempo parcial
        'dias_tp_caja',           // snapshot: días de caja del mes en tiempo parcial
        'grupo_fondo_solidaridad', // snapshot: grupo del PSAP, decide la tarifa de pensión del cotizante 33
        'plano_corregido_id',     // corrección N con valores: el plano pagado que corrige (de ahí sale la línea A)
        'usuario_id',
    ];

    protected $casts = [
        'fecha_ing' => 'date',
        'fecha_ret' => 'date',
        // sqlsrv devuelve los BIT como string: sin el cast, un === true falla en silencio.
        'paga_mes_actual' => 'boolean',
        // y los enteros también llegan como string.
        'dias_tp_afp' => 'integer',
        'dias_tp_caja' => 'integer',
    ];

    /**
     * El plano es un snapshot del período que cotiza el contrato. Quien lo crea
     * debería decirlo, pero no todos pasan por generarDesdeContrato(): los
     * retiros y los traslados de razón social arman el registro a mano. Si no
     * viene, se toma del contrato en este momento.
     *
     * Sin esto el plano nace en mes vencido y la persona aparece un mes tarde
     * en la planilla — o no aparece en la que le toca.
     */
    protected static function booted(): void
    {
        static::creating(function (self $plano) {
            if ($plano->paga_mes_actual === null && $plano->contrato_id) {
                $plano->paga_mes_actual = (bool) Contrato::whereKey($plano->contrato_id)
                    ->value('paga_mes_actual');
            }

            // Mismo caso con los días de tiempo parcial: son del contrato y del
            // mes. Si el plano nace sin ellos (retiro, traslado de razón social)
            // se copian ahora, porque el mes que viene el contrato puede cotizar
            // otras semanas y esta planilla ya no se puede recalcular.
            //
            // Y con el grupo del Fondo de Solidaridad, que fija la tarifa de
            // pensión: va en la misma consulta.
            if (($plano->dias_tp_afp === null || $plano->grupo_fondo_solidaridad === null) && $plano->contrato_id) {
                $dias = Contrato::whereKey($plano->contrato_id)
                    ->first(['dias_tp_afp', 'dias_tp_caja', 'grupo_fondo_solidaridad']);
                $plano->dias_tp_afp  = $plano->dias_tp_afp ?? $dias?->dias_tp_afp;
                $plano->dias_tp_caja = $plano->dias_tp_caja ?? $dias?->dias_tp_caja;
                $plano->grupo_fondo_solidaridad = $plano->grupo_fondo_solidaridad ?? $dias?->grupo_fondo_solidaridad;
            }
        });
    }

    public function factura()      { return $this->belongsTo(Factura::class); }
    public function contrato()     { return $this->belongsTo(Contrato::class); }
    public function razonSocial()  { return $this->belongsTo(RazonSocial::class, 'razon_social_id'); }
    public function enviosWhatsappDetalles() { return $this->hasMany(PlanillaEnvioWhatsappDetalle::class, 'plano_id'); }

    /**
     * Resuelve el NIT y el nombre de la ARL a utilizar para un contrato y su razón social.
     * Prioriza la ARL del contrato si es independiente, si tiene modalidad libre o si la RS es independiente.
     * De lo contrario, prioriza el ARL de la razón social.
     */
    public static function resolverArlSnapshot(Contrato $contrato, ?RazonSocial $rs): array
    {
        $arl = $contrato->arl;
        $esIndependiente = $contrato->esIndependiente()
            || ($rs?->es_independiente)
            || ($contrato->tipoModalidad && in_array($contrato->tipo_modalidad_id, \App\Models\TipoModalidad::IDS_ARL_LIBRE));

        $codArl = null;
        $nombreArl = null;

        if ($esIndependiente && $arl) {
            $codArl = $arl->nit ?? $arl->codigo_arl ?? null;
            $nombreArl = $arl->nombre_arl ?? null;
        }

        if (!$codArl) {
            $codArl = $rs?->arl_nit ?? $arl?->nit ?? $arl?->codigo_arl ?? null;
            if ($rs?->arl_nit) {
                $nombreArl = DB::table('arls')->where('nit', $rs->arl_nit)->value('nombre_arl');
            }
            if (!$nombreArl) {
                $nombreArl = $arl?->nombre_arl ?? null;
            }
        }

        return [
            'cod_arl' => $codArl,
            'nombre_arl' => $nombreArl,
        ];
    }

    /**
     * Período que cubre el plano PILA a partir del período de su factura.
     *
     * Convención vigente: la factura se registra en el mes en que se COBRA.
     * El plano es el mes de servicio que ese cobro paga:
     *
     *   • Contrato con `paga_mes_actual`          → paga el mes corriente   → plano = mes factura
     *   • Todos los demás                         → paga el mes vencido     → plano = mes factura − 1
     *   • Afiliación                              → va en el mes de ingreso → plano = mes factura
     *
     * Ejemplo (independiente vencido): cobro del 04/08/2026 → factura Ago 2026 → plano Jul 2026.
     *
     * Único lugar donde vive esta regla: cualquier proceso que mueva el período de
     * una factura debe recalcular el plano con este método en vez de copiar el mes.
     * Igualar ambos períodos hace que la siguiente facturación caiga en un mes ya
     * cubierto y mete dos veces a la misma persona en la planilla.
     *
     * @return array{0:int,1:int} [mes, anio]
     */
    public static function periodoPlano(int $mesFactura, int $anioFactura, bool $pagaMesActual, bool $esAfiliacion): array
    {
        if ($esAfiliacion || $pagaMesActual) {
            return [$mesFactura, $anioFactura];
        }

        return $mesFactura > 1
            ? [$mesFactura - 1, $anioFactura]
            : [12, $anioFactura - 1];
    }

    /**
     * Filtra planos por el período que le corresponde al mes de PAGO.
     *
     * Quien paga el mes en curso cotiza el mes del pago; el resto, el vencido.
     * La regla estaba copiada literal en trece consultas —los cuatro servicios
     * de Excel, el TXT, los envíos por WhatsApp, las correcciones…— y bastaba
     * con que una quedara sin actualizar para dejar a alguien dos veces en la
     * misma planilla. Vive aquí y solo aquí.
     *
     * Sirve tanto para Eloquent como para query builder: `$alias` es el alias
     * de la tabla `planos` en la consulta (null si no tiene), y `$aliasFlag`
     * permite leer el flag desde `contratos` cuando la consulta ya lo tiene
     * unido (los dos valores coinciden; manda el que la consulta ya trae).
     */
    public static function filtrarPeriodoDePago(
        $query,
        int $mesPago,
        int $anioPago,
        ?string $alias = 'p',
        ?string $aliasFlag = null
    ) {
        $col     = $alias ? "{$alias}." : '';
        $colFlag = ($aliasFlag ? "{$aliasFlag}." : $col).'paga_mes_actual';

        $mesVencido  = $mesPago > 1 ? $mesPago - 1 : 12;
        $anioVencido = $mesPago > 1 ? $anioPago : $anioPago - 1;

        return $query->where(function ($q) use ($col, $colFlag, $mesPago, $anioPago, $mesVencido, $anioVencido) {
            $q->where(function ($i) use ($col, $colFlag, $mesPago, $anioPago) {
                $i->where($colFlag, 1)
                  ->where("{$col}mes_plano", $mesPago)
                  ->where("{$col}anio_plano", $anioPago);
            })->orWhere(function ($i) use ($col, $colFlag, $mesVencido, $anioVencido) {
                $i->where($colFlag, 0)
                  ->where("{$col}mes_plano", $mesVencido)
                  ->where("{$col}anio_plano", $anioVencido);
            });
        });
    }

    /**
     * El archivo PILA declara UN período de cotización para toda la planilla
     * (campos 15 y 16 del registro tipo 1). Quien cotiza el mes en curso y quien
     * cotiza el vencido conviven en la misma tanda de PAGO, pero no pueden
     * viajar en el mismo archivo: el período que se declare deja a la mitad de
     * la gente reportada en un mes que no es el suyo.
     *
     * @throws \RuntimeException si la selección mezcla los dos esquemas
     */
    public static function validarPeriodoUnico($planos): void
    {
        $esquemas = collect($planos)
            ->map(fn ($p) => (int) (bool) ($p->paga_mes_actual ?? 0))
            ->unique();

        if ($esquemas->count() <= 1) {
            return;
        }

        $actual  = collect($planos)->filter(fn ($p) => (bool) ($p->paga_mes_actual ?? false))->count();
        $vencido = collect($planos)->count() - $actual;

        throw new \RuntimeException(
            "Esta selección mezcla {$actual} cotizante(s) de mes actual con {$vencido} de mes vencido, "
            .'y cada grupo cotiza un mes distinto. El archivo declara un solo período, así que hay que '
            .'descargarlos por separado: filtre primero un grupo y luego el otro.'
        );
    }

    /**
     * Genera el registro de plano a partir de un contrato y factura.
     * Snapshot de los datos al momento de facturar.
     */
    public static function generarDesdeContrato(Contrato $contrato, Factura $factura, ?string $fechaRetiro = null): ?static
    {
        // ── Modalidades que no cotizan seguridad social ──────────────────────────
        // Un contrato de solo seguro no va en ninguna planilla: no tiene EPS, ARL,
        // pensión ni caja que reportar. Generarle un plano metería una línea vacía
        // en el TXT del operador. Ningún llamador usa el valor de retorno.
        if ($contrato->esSoloSeguro()) {
            return null;
        }

        // ── Protección idempotente: no generar dos planos para la misma factura ──
        // Si ya existe un plano activo (no soft-deleted) para esta factura_id, retornarlo
        // sin crear un duplicado (ej: flujo abonar() que completa una factura ya planificada).
        $existente = static::where('factura_id', $factura->id)->whereNull('deleted_at')->first();
        if ($existente) {
            return $existente;
        }
    
        $cliente = $contrato->cliente;
        $eps     = $contrato->eps;
        $afp     = $contrato->pension;
        $arl     = $contrato->arl;
        $caja    = $contrato->caja;
        $rs      = $contrato->razonSocial;
        $modal   = $contrato->tipoModalidad;

        // ── Fallback AFP: si el contrato no tiene pension_id pero el plan incluye AFP
        //    y el cliente sí tiene pensión, usar la del cliente para el snapshot del plano.
        //    Esto evita que cod_afp quede NULL cuando se facturó con valor de pensión.
        if (!$afp && $cliente?->pension_id) {
            $plan = $contrato->plan;
            if ($plan?->incluye_pension && (int)($factura->v_afp ?? 0) > 0) {
                $afp = $cliente->pension; // relación pension en el modelo Cliente
            }
        }

        // cod_arl y nombre_arl utilizando el método centralizado
        $arlSnapshot = static::resolverArlSnapshot($contrato, $rs);
        $codArl = $arlSnapshot['cod_arl'];
        $nombreArl = $arlSnapshot['nombre_arl'];

        [$primerApe, $segundoApe] = static::splitNombre($cliente?->apellidos ?? ($cliente?->primer_apellido . ' ' . $cliente?->segundo_apellido ?? ''));
        [$primerNom, $segundoNom] = static::splitNombre($cliente?->nombres    ?? ($cliente?->primer_nombre   . ' ' . $cliente?->segundo_nombre   ?? ''));

        $esAfiliacion = $factura->tipo === 'afiliacion';

        // Quien paga el mes actual: el plano cubre el mes facturado.
        // Los demás: el plano cubre el MES ANTERIOR (billing mayo → plano abril).
        // Se lee del contrato y no de la relación, que puede no venir cargada y
        // haría divergir el período de la fecha de ingreso.
        $esIndepMesActual = (bool) ($contrato->paga_mes_actual ?? false);

        [$mesPlan, $anioPlan] = static::periodoPlano(
            (int)$factura->mes,
            (int)$factura->anio,
            $esIndepMesActual,
            $esAfiliacion
        );

        // fecha_ing: en afiliación siempre; en planilla solo el primer mes de cotización.
        // • Mes actual: fecha_ingreso = mes facturado (paga el mismo mes)
        // • Vencido:    fecha_ingreso = mes ANTERIOR al facturado
        $fechaIng = null;
        if ($esAfiliacion) {
            $fechaIng = $contrato->fecha_ingreso;
        } elseif ($contrato->fecha_ingreso) {
            $fIng = $contrato->fecha_ingreso;
            if ($esIndepMesActual) {
                // Independiente mes actual: primer plano cuando fecha_ingreso = mes facturado
                if ((int)$fIng->month === (int)$factura->mes && (int)$fIng->year === (int)$factura->anio) {
                    $fechaIng = $fIng;
                }
            } else {
                // Dependiente / I Vencido: primer plano cuando fecha_ingreso = mes anterior
                $mesPrev  = $factura->mes > 1 ? $factura->mes - 1 : 12;
                $anioPrev = $factura->mes > 1 ? $factura->anio    : $factura->anio - 1;
                if ((int)$fIng->month === $mesPrev && (int)$fIng->year === $anioPrev) {
                    $fechaIng = $fIng;
                }
            }
        }

        return static::create([
            'factura_id'        => $factura->id,
            'contrato_id'       => $contrato->id,
            'aliado_id'         => $contrato->aliado_id,
            'numero_factura'    => $factura->numero_factura,
            // Tipo de registro
            'tipo_reg'          => $esAfiliacion ? 'afiliacion' : 'planilla',
            'tipo_doc'          => strtoupper(trim($cliente?->tipo_doc ?? 'CC')) ?: 'CC',
            'no_identifi'       => $contrato->cedula,
            // Nombre snapshot
            'primer_ape'        => strtoupper($primerApe),
            'segundo_ape'       => strtoupper($segundoApe),
            'primer_nombre'     => strtoupper($primerNom),
            'segundo_nombre'    => strtoupper($segundoNom),
            // Novedades
            'fecha_ing'         => $fechaIng,
            'fecha_ret'         => $fechaRetiro ? \Carbon\Carbon::parse($fechaRetiro)->toDateString() : null,
            // Días
            'num_dias'          => $esAfiliacion ? 0 : ($factura->dias_cotizados ?? 30),
            // Entidades (NIT snapshot)
            'cod_eps'           => $eps?->nit  ?? $eps?->cod_eps  ?? null,
            'nombre_eps'        => $eps?->nombre ?? null,
            'cod_afp'           => $afp?->nit  ?? $afp?->cod_afp  ?? null,
            'nombre_afp'        => $afp?->razon_social ?? null,
            'cod_arl'           => $codArl,
            'nombre_arl'        => $nombreArl,
            'cod_caja'          => $caja?->nit ?? $caja?->cod_caja ?? null,
            'nombre_caja'       => $caja?->nombre ?? null,
            'nivel_riesgo'      => $contrato->n_arl ?? 1,
            // salario_basico: para Tiempo Parcial (planes 1-4) usamos ceil(SM × factor_afp)
            // para garantizar el redondeo PILA (Res. 2388). Para otros planes, salario del contrato.
            'salario_basico'    => static::calcularSalarioBasicoPlano($contrato, $modal),
            // Período — mes ANTERIOR para dependientes (billing mayo → plano abril)
            'n_plano'           => $factura->n_plano,
            'mes_plano'         => $mesPlan,
            'anio_plano'        => $anioPlan,
            // Clasificación
            'razon_social'      => $rs?->razon_social ?? null,
            'razon_social_id'   => $contrato->razon_social_id,
            'tipo_p'            => $contrato->tipo_modalidad_id,  // ID (evita texto largo)
            'tipo_modalidad_id' => $contrato->tipo_modalidad_id,
            // Snapshot: si el contrato cambia después, el período que cubrió
            // este plano no se puede recalcular desde el contrato.
            'paga_mes_actual'   => $esIndepMesActual,
            'dias_tp_afp'       => $contrato->dias_tp_afp,
            'dias_tp_caja'      => $contrato->dias_tp_caja,
            'grupo_fondo_solidaridad' => $contrato->grupo_fondo_solidaridad,
            'usuario_id'        => $factura->usuario_id,
        ]);
    }

    /**
     * Calcula el salario_basico que se almacena en el plano.
     *
     * Para planes de Tiempo Parcial (es_tiempo_parcial = true, planes 1-4):
     *   salario_basico = ceil(SM × factor_afp)
     *   donde factor_afp = dias_afp / 30
     *   Esto garantiza el redondeo PILA hacia arriba (Res. 2388) sin importar
     *   el salario del año (SM se lee de ConfiguracionBrynex).
     *
     * Para todos los demás planes: usa contrato->salario sin modificar.
     */
    private static function calcularSalarioBasicoPlano($contrato, $modal): int
    {
        if ($modal && $modal->esTiempoParcial()) {
            $sm      = (float) ConfiguracionBrynex::obtener('salario_minimo', 1423500);
            // Los días salen del contrato y, si no los tiene, de la modalidad.
            $diasP   = $contrato->diasTiempoParcial();               // ['afp'=>7|14|21|30, ...]
            $diasAfp = (int)($diasP['afp'] ?? 30);
            // ceil(SM × dias_afp / 30) garantiza redondeo hacia arriba requerido por PILA
            return (int)ceil($sm * $diasAfp / 30);
        }

        return (int)($contrato->salario ?? 0);
    }

    private static function splitNombre(string $nombre): array
    {
        $partes = preg_split('/\s+/', trim($nombre), 2);
        return [$partes[0] ?? '', $partes[1] ?? ''];
    }
}
