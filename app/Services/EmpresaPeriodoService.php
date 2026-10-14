<?php

namespace App\Services;

use App\Models\BancoCuenta;
use App\Models\Contrato;
use App\Models\Empresa;
use App\Models\Factura;
use Illuminate\Support\Facades\DB;

/**
 * Lo que una empresa tiene en un período de facturación: quién está, qué ya
 * se facturó, qué falta, cuánto cuesta cada uno y cómo va su saldo.
 *
 * Vivía dentro de FacturacionController. Salió de ahí para que el portal de
 * empresas muestre exactamente lo mismo que ve el equipo en
 * admin/facturacion/empresa/{id}: si cada pantalla calculara por su lado, la
 * empresa vería un total y el aliado le cobraría otro.
 *
 * Todo recibe el aliado explícito, nunca lo lee de la sesión: el portal no
 * tiene aliado activo en sesión, lo trae el acceso de la empresa.
 */
class EmpresaPeriodoService
{
    /**
     * Los contratos que la planilla del período muestra: los que siguen
     * activos y los retiros que todavía se facturan ese mes.
     *
     * Vive aparte porque también hace falta saber a quién NO repetir en la
     * lista de retirados, y ahí solo se necesitan las cédulas: pedir la
     * planilla entera para eso costaría el cálculo completo de cotizaciones.
     */
    public function contratosDelPeriodo(int $aliadoId, $cedulasEmpresa, int $mes, int $anio)
    {
        $mesAnterior = $mes === 1 ? 12 : $mes - 1;
        $anioAnterior = $mes === 1 ? $anio - 1 : $anio;

        return Contrato::where('aliado_id', $aliadoId)
            ->whereIn('cedula', $cedulasEmpresa)
            ->where(function ($q) use ($mes, $anio, $mesAnterior, $anioAnterior) {
                $q->whereIn('estado', ['vigente', 'activo'])
                    ->orWhere(function ($q2) use ($mes, $anio, $mesAnterior, $anioAnterior) {
                        $q2->where('estado', 'retirado')
                            ->where(function ($q3) use ($mes, $anio, $mesAnterior, $anioAnterior) {
                                $q3->where(function ($qa) use ($mes, $anio) {
                                    // Mes actual: el retiro se factura en el MES del retiro
                                    $qa->where('paga_mes_actual', 1)
                                        ->whereMonth('fecha_retiro', $mes)
                                        ->whereYear('fecha_retiro', $anio);
                                })
                                    ->orWhere(function ($qb) use ($mesAnterior, $anioAnterior) {
                                        // Otros: retiro del mes anterior se factura este mes
                                        $qb->where('paga_mes_actual', 0)
                                            ->whereMonth('fecha_retiro', $mesAnterior)
                                            ->whereYear('fecha_retiro', $anioAnterior);
                                    })
                                    ->orWhere(function ($qc) use ($mes, $anio) {
                                        // Otros: retiro en el mes actual (se factura en el mismo mes)
                                        // Ej: ingresó julio, se retira agosto → aparece en agosto
                                        $qc->where('paga_mes_actual', 0)
                                            ->whereMonth('fecha_retiro', $mes)
                                            ->whereYear('fecha_retiro', $anio);
                                    })
                                    ->orWhere(function ($qd) use ($mes, $anio) {
                                        // Retirado que ingresó este mes: mostrar afiliación
                                        // aunque el retiro sea en un mes futuro
                                        // Ej: ingresó julio, retiro agosto → aparece en julio como afiliación
                                        $qd->whereMonth('fecha_ingreso', $mes)
                                            ->whereYear('fecha_ingreso', $anio);
                                    });
                            });
                    });
            });
    }

    /**
     * Obtiene y pre-calcula los datos de facturación para los contratos de una empresa en un período dado.
     */
    public function datosPeriodo(int $empresaId, int $mes, int $anio, int $aliadoId): array
    {
        $empresa = Empresa::where('aliado_id', $aliadoId)->findOrFail($empresaId);

        // Pre-cargar configuración global en 1 query (evita N+1 en calcularCotizacion)
        \App\Models\ConfiguracionBrynex::precargar();

        // Traer todos los contratos vigentes cuyos clientes pertenecen a esta empresa
        $cedulasEmpresa = DB::table('clientes')
            ->where('aliado_id', $aliadoId)
            ->where('cod_empresa', $empresaId)
            ->pluck('cedula');

        // El período anterior lo usa también el chequeo de facturas faltantes,
        // más abajo; contratosDelPeriodo() lo calcula por su cuenta.
        $mesAnterior = $mes === 1 ? 12 : $mes - 1;
        $anioAnterior = $mes === 1 ? $anio - 1 : $anio;

        $contratos = $this->contratosDelPeriodo($aliadoId, $cedulasEmpresa, $mes, $anio)
            ->with([
                'cliente', 'tipoModalidad', 'razonSocial', 'eps', 'arl', 'pension', 'caja', 'asesor',
                'plan',
            ])
            ->orderBy('cedula')
            ->get()
            // El listado se lee por nombre, no por cédula. El nombre vive en
            // clientes (relación por cédula), así que el orden se hace aquí.
            ->sortBy(
                fn ($c) => mb_strtolower(trim(
                    ($c->cliente?->primer_nombre ?? '').' '.
                    ($c->cliente?->primer_apellido ?? '').' '.
                    ($c->cliente?->segundo_apellido ?? '')
                )),
                SORT_NATURAL
            )
            ->values();

        $facturasExistentes = Factura::where('aliado_id', $aliadoId)
            ->periodo($mes, $anio)
            ->whereIn('tipo', ['planilla', 'afiliacion'])
            ->whereIn('cedula', $contratos->pluck('cedula'))
            ->whereNotNull('contrato_id')
            ->where('numero_factura', '>', 0)
            ->get()
            ->keyBy('contrato_id');

        $facturasRetiro0 = Factura::where('aliado_id', $aliadoId)
            ->whereIn('contrato_id', $contratos->pluck('id'))
            ->where('numero_factura', 0)
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('contrato_id');

        $contratoIds = $contratos->pluck('id')->all();

        $saldosTotales = DB::table('facturas')
            ->where('aliado_id', $aliadoId)
            ->whereIn('contrato_id', $contratoIds)
            ->whereNotNull('saldo_proximo')
            ->whereIn('estado', ['pagada', 'prestamo', 'abono'])
            ->whereNull('deleted_at')
            ->groupBy('contrato_id')
            ->select('contrato_id', DB::raw('SUM(saldo_proximo) as suma'))
            ->pluck('suma', 'contrato_id');

        $saldosPrevios = DB::table('facturas')
            ->where('aliado_id', $aliadoId)
            ->whereIn('contrato_id', $contratoIds)
            ->whereNull('empresa_id')
            ->whereNotNull('saldo_proximo')
            ->whereIn('estado', ['pagada', 'prestamo', 'abono'])
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('anio', '<', $anio)
                ->orWhere(fn ($q2) => $q2->where('anio', $anio)->where('mes', '<', $mes)))
            ->groupBy('contrato_id')
            ->select('contrato_id', DB::raw('SUM(saldo_proximo) as suma'))
            ->pluck('suma', 'contrato_id');

        // IVA: todos estos clientes pertenecen a esta empresa, así que manda la
        // marca de la empresa — la del cliente no cuenta aquí (ver IvaService).
        $empresaTieneIva = \App\Services\IvaService::bandera($empresa->iva);
        $ivaClientes = DB::table('clientes')
            ->where('aliado_id', $aliadoId)
            ->where('cod_empresa', $empresaId)
            ->pluck('iva', 'cedula')
            ->map(fn ($v) => $empresaTieneIva)
            ->toArray();

        // ── Contratos a los que les falta el mes anterior ──────────────────
        // Quien estuvo activo el mes pasado y no tiene factura de ese período se
        // le pasó a alguien: el listado del mes en curso no lo delataba por
        // ningún lado, porque cada mes se mira solo. Se resuelve con una
        // consulta, no una por fila.
        $facturasMesAnterior = DB::table('facturas')
            ->where('aliado_id', $aliadoId)
            ->whereIn('contrato_id', $contratoIds)
            ->where('mes', $mesAnterior)
            ->where('anio', $anioAnterior)
            // La factura 0 es el retiro pendiente de cobrar, no un cobro del mes.
            ->where(fn ($q) => $q->whereNull('numero_factura')->orWhere('numero_factura', '!=', 0))
            ->whereNull('deleted_at')
            ->pluck('contrato_id')
            ->flip();

        $inicioMesAnterior = \Carbon\Carbon::create($anioAnterior, $mesAnterior, 1)->startOfDay();
        $finMesAnterior = $inicioMesAnterior->copy()->endOfMonth();

        $hoy = now();

        $contratos = $contratos->map(function ($c) use ($mes, $anio, $facturasExistentes, $facturasRetiro0, $saldosTotales, $saldosPrevios, $ivaClientes, $facturasMesAnterior, $inicioMesAnterior, $finMesAnterior) {
            // ¿Le correspondía factura el mes pasado y no la tiene? Solo cuenta si
            // el contrato ya existía entonces y no se había retirado antes de que
            // empezara el mes; un ingreso de este mes no debe nada de atrás.
            $c->falta_mes_anterior = ! $facturasMesAnterior->has($c->id)
                && $c->fecha_ingreso
                && $c->fecha_ingreso->lte($finMesAnterior)
                && (! $c->fecha_retiro || $c->fecha_retiro->gte($inicioMesAnterior))
                // Gestión ARL y seguros no generan planilla mensual.
                && ! in_array((int) $c->tipo_modalidad_id, [15, \App\Models\Contrato::MODALIDAD_SEGUROS], true);

            $diasCotizar = 30;
            $esIndActPrimerMes = false;

            $esArlModalidad = (int) ($c->tipo_modalidad_id) === 15;
            // Solo seguro: no cotiza, no paga administración y no genera planilla.
            $esSoloSeguro = (int) ($c->tipo_modalidad_id) === \App\Models\Contrato::MODALIDAD_SEGUROS;
            if ($esSoloSeguro) {
                // No hay días que cotizar: se cobra el seguro completo cada mes.
                $diasCotizar = 0;
            } elseif ($esArlModalidad) {
                $fArl = $c->fecha_arl ?? $c->fecha_ingreso;
                if ($fArl) {
                    $mesArl = (int) $fArl->month;
                    $anioArl = (int) $fArl->year;
                    if ($mesArl === $mes && $anioArl === $anio) {
                        $diasCotizar = 0;
                    } else {
                        $diasCotizar = 0;
                    }
                } else {
                    $diasCotizar = 0;
                }
            } elseif ($c->fecha_ingreso) {
                $fIng = $c->fecha_ingreso;
                $mesIngreso = (int) $fIng->month;
                $anioIngreso = (int) $fIng->year;
                $esIndAct = (bool) ($c->paga_mes_actual ?? false);

                $periodoIngreso = $anioIngreso * 100 + $mesIngreso;
                $periodoActual = $anio * 100 + $mes;

                if ($periodoIngreso > $periodoActual) {
                    // Ingreso en mes futuro: el contrato aún no inicia en este período
                    $diasCotizar = 0;
                } elseif ($mesIngreso === $mes && $anioIngreso === $anio) {
                    if ($esIndAct) {
                        $esIndActPrimerMes = true;
                        $diasCotizar = max(1, 30 - $fIng->day + 1);
                    } else {
                        $diasCotizar = 0;
                    }
                } else {
                    $mesAnterior = $mes === 1 ? 12 : $mes - 1;
                    $anioAnterior = $mes === 1 ? $anio - 1 : $anio;

                    if ($mesIngreso === $mesAnterior && $anioIngreso === $anioAnterior) {
                        $diasCotizar = max(1, 30 - $fIng->day + 1);
                    }
                }
            }
            $c->dias_cotizar = $diasCotizar;
            $c->es_ind_act_primer_mes = $esIndActPrimerMes;

            // ── Retiro Pendiente (registrado desde vista empresa, aún no facturado) ──
            // Si el contrato vigente tiene fecha_retiro_pendiente, los días cotizables
            // son los días del mes hasta esa fecha (ej: día 20 = 20 días).
            if ($c->estado === 'vigente' && $c->fecha_retiro_pendiente) {
                $c->dias_cotizar = (int) $c->fecha_retiro_pendiente->day;
                $c->tiene_retiro_pendiente = true;
            } else {
                $c->tiene_retiro_pendiente = false;
            }

            $c->factura_exist = $facturasExistentes->get($c->id);

            $facturaRetiro0 = $facturasRetiro0->get($c->id);
            $c->factura_retiro_0 = $facturaRetiro0;
            $c->tiene_retiro_facturable = $c->estado === 'retirado'
                && $facturaRetiro0 !== null
                && $c->factura_exist === null;

            $ivaFlag = $ivaClientes[$c->cedula] ?? null;
            $c->tiene_iva = (bool) $ivaFlag;
            $c->cotizacion_calc = $c->calcularCotizacion($diasCotizar, $ivaFlag);

            $sumaPrev = (int) ($saldosPrevios[$c->id] ?? 0);
            $c->saldo_a_favor_facturar = $sumaPrev > 0 ? $sumaPrev : 0;
            $c->saldo_pendiente_facturar = $sumaPrev < 0 ? abs($sumaPrev) : 0;

            $sumaTotal = (int) ($saldosTotales[$c->id] ?? 0);
            $c->saldo_a_favor = $sumaTotal > 0 ? $sumaTotal : 0;
            $c->saldo_pendiente = $sumaTotal < 0 ? abs($sumaTotal) : 0;

            $sp = $c->factura_exist ? (int) ($c->factura_exist->saldo_proximo ?? 0) : 0;
            $c->saldo_proximo_favor = $sp > 0 ? $sp : 0;
            $c->saldo_proximo_pendiente = $sp < 0 ? abs($sp) : 0;

            return $c;
        });

        $filasMora = [];
        foreach ($contratos as $c) {
            if ($c->factura_exist) {
                continue;
            }
            if ($c->estado === 'retirado') {
                continue;
            }
            if ($c->es_ind_act_primer_mes === false && $c->cotizacion_calc['ss'] == 0) {
                continue;
            }
            if ((int) $c->tipo_modalidad_id === 15) {
                continue;
            }
            $rsNit = $c->nitParaMora();
            if (! $rsNit) {
                continue;
            }
            $vSS = (int) ($c->cotizacion_calc['ss'] ?? 0);
            if ($vSS <= 0) {
                continue;
            }
            $filasMora[$c->id] = [
                'contrato_id' => $c->id,
                'rs_nit' => $rsNit,
                'rs_dia_habil' => $c->diaHabilParaMora(),
                'total_ss' => $vSS,
                // Desglose por entidad para mora exacta (igual que módulo planos)
                'eps' => (int) ($c->cotizacion_calc['eps'] ?? 0),
                'arl' => (int) ($c->cotizacion_calc['arl'] ?? 0),
                'pen' => (int) ($c->cotizacion_calc['pen'] ?? 0),
                'caja' => (int) ($c->cotizacion_calc['caja'] ?? 0),
                'mes' => $mes,
                'anio' => $anio,
            ];
        }
        $moraPorContrato = [];
        if (! empty($filasMora)) {
            $resultadosMora = \App\Services\MoraClienteService::calcularLote($aliadoId, array_values($filasMora));
            foreach ($resultadosMora as $fila) {
                $moraPorContrato[$fila['contrato_id']] = (int) ($fila['mora'] ?? 0);
            }
        }

        $bancos = BancoCuenta::paraFacturacion($aliadoId);
        $asesores = \App\Models\Asesor::where('aliado_id', $aliadoId)
            ->orderBy('nombre')
            ->get(['id', 'nombre']);

        $planosActuales = DB::table('planos')
            ->where('aliado_id', $aliadoId)
            ->where('mes_plano', $mes)->where('anio_plano', $anio)
            ->select('razon_social', DB::raw('MAX(n_plano) as n_plano_max'))
            ->groupBy('razon_social')
            ->get()->keyBy('razon_social');

        ['favor' => $saldoEmpresaFavor, 'pendiente' => $saldoEmpresaPendiente] = $this->saldoEmpresa($aliadoId, (int) $empresa->id);

        // Las facturas que arman ese saldo neto, para poder ver de dónde sale.
        // Mismas condiciones que la suma de arriba, sin las que quedaron en cero.
        $facturasSaldoEmpresa = collect();
        if ($saldoEmpresaFavor > 0 || $saldoEmpresaPendiente > 0) {
            $facturasSaldoEmpresa = Factura::where('aliado_id', $aliadoId)
                ->where('empresa_id', $empresa->id)
                ->whereNotNull('saldo_proximo')
                ->where('saldo_proximo', '!=', 0)
                ->whereIn('estado', ['pagada', 'prestamo', 'abono'])
                ->whereNull('deleted_at')
                ->with('contrato.cliente')
                ->orderByDesc('anio')
                ->orderByDesc('mes')
                ->orderByDesc('id')
                ->get();
        }

        $anticiposEmpresa = \App\Models\Anticipo::disponiblesParaEmpresa($aliadoId, $empresa->id);
        $totalAnticipoDisponible = (int) $anticiposEmpresa->sum('valor_disponible');

        $anticiposPorContrato = \App\Models\Anticipo::aliado($aliadoId)
            ->whereIn('contrato_id', $contratoIds)
            ->conSaldo()
            ->get()
            ->groupBy('contrato_id');

        $saldoAnticipoPorContrato = $anticiposPorContrato->map(fn ($group) => $group->sum('valor_disponible'));
        $hayAnticipos = $saldoAnticipoPorContrato->isNotEmpty() || $totalAnticipoDisponible > 0;

        // ¿Alguien tiene mora este período? Si no, la columna se oculta.
        // Misma regla que la fila: lo ya pagado no muestra mora (ver empresa.blade).
        $hayMora = $contratos->contains(function ($c) use ($moraPorContrato) {
            $fact = $c->factura_exist;
            if ($fact && in_array($fact->estado, ['pagada', 'prestamo'])) {
                return false;
            }

            return $fact
                ? (int) ($fact->mora ?? 0) > 0
                : (int) ($moraPorContrato[$c->id] ?? 0) > 0;
        });

        $cobrosAdicionales = \App\Models\CobrosAdicionalEmpresa::where('aliado_id', $aliadoId)
            ->where('empresa_id', $empresa->id)
            ->where('activo', true)
            ->orderBy('tipo')
            ->orderBy('descripcion')
            ->get();
        $cobrosRecurrentes = $cobrosAdicionales->where('tipo', 'recurrente')->values();

        $meses = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
        ];

        return compact(
            'empresa', 'contratos', 'facturasExistentes', 'bancos', 'planosActuales', 'asesores',
            'saldoEmpresaFavor', 'saldoEmpresaPendiente', 'facturasSaldoEmpresa', 'moraPorContrato',
            'anticiposEmpresa', 'totalAnticipoDisponible', 'saldoAnticipoPorContrato', 'hayAnticipos',
            'hayMora', 'cobrosAdicionales', 'cobrosRecurrentes', 'meses'
        );
    }

    /**
     * Lo que cuesta y en qué va un contrato en el período, tal como lo pinta
     * la fila de admin/facturacion/empresa/{id}.
     *
     * Es el mismo código que vivía en @php dentro de esa vista, movido sin
     * cambios: la vista lo sigue usando y el portal de empresas lo usa para
     * mostrar los mismos valores. Si hay que corregir un cálculo, es aquí.
     *
     * @param  Contrato  $c  un contrato de datosPeriodo()['contratos'], que ya
     *                       trae factura_exist, cotizacion_calc, dias_cotizar…
     * @param  array<int,int>  $moraPorContrato  datosPeriodo()['moraPorContrato']
     */
    public function valoresFila(Contrato $c, int $mes, int $anio, array $moraPorContrato): array
    {
        $r100 = fn ($v) => (int) (ceil(($v ?? 0) / 100) * 100); // redondeo al centena superior
        // El IVA NO se redondea a centena: es impuesto, va exacto como se factura.
        $rIva = fn ($v) => (int) round($v ?? 0);
        // Solo existen en algunas ramas; la vista los lee con ?? y deben seguir
        // llegando vacíos cuando la rama no corre.
        $fIngC = $periodoIngresoVista = $periodoActualVista = $vAdmProporcional = $cotizRetPend = null;

        $fact = $c->factura_exist;
        $factRetiroPreview = (! $fact && ($c->tiene_retiro_facturable ?? false)) ? ($c->factura_retiro_0 ?? null) : null;
        // Para retiro facturable usamos la factura_0 como fuente de valores de preview
        $yaP = $fact && in_array($fact->estado, ['pagada', 'prestamo']);
        // Nombre: primer nombre + inicial del segundo + primer apellido (Candida R. Sierra)
        $nombre = nombre_con_inicial($c->cliente?->primer_nombre, $c->cliente?->segundo_nombre, $c->cliente?->primer_apellido);
        if (! $nombre) {
            $nombre = $c->cliente?->nombre_completo ?? '—';
        }
        // Tipo: campo tipo_modalidad directo (ej: 'E', 'I')
        $tipoMod = $c->tipoModalidad?->tipo_modalidad ?? '—';
        $tipoNom = $c->tipoModalidad?->nombre ?? '—';  // tooltip
        $rs = $c->razonSocial?->razon_social ?? '—';
        $esRetirado = $c->estado === 'retirado';
        $esIngRet = (int) ($c->tipo_modalidad_id) === 12;
        $fIng = $c->fecha_ingreso ? $c->fecha_ingreso->format('d/m/Y') : '—';
        $fRet = ($esRetirado && $c->fecha_retiro) ? $c->fecha_retiro->format('d/m/Y') : null;
        // Retiro pendiente (nuevo flujo desde vista empresa)
        $tieneRetiroPendiente = $c->tiene_retiro_pendiente ?? false; // seteado por controller
        $fechaRetiroPendienteStr = $tieneRetiroPendiente && $c->fecha_retiro_pendiente ? $c->fecha_retiro_pendiente->format('Y-m-d') : '';
        $diasRetiroPendiente = $tieneRetiroPendiente ? (int) ($c->fecha_retiro_pendiente?->day ?? 0) : 0;
        $cobrarAdmonRetiroPendiente = $tieneRetiroPendiente ? (bool) ($c->retiro_pendiente_cobrar_admon ?? false) : false;
        $dias = $fact
            ? (int) $fact->dias_cotizados
            : ($factRetiroPreview
                ? (int) $factRetiroPreview->dias_cotizados  // usar días reales del retiro marcado
                : ($c->dias_cotizar ?? 30));
        // Detectar si este período debe ser afiliación pura (I VENC, empresa, ARL)
        // vs I ACT primer mes (viene del controlador como es_ind_act_primer_mes)
        $esIndep = $c->tipoModalidad?->esIndependiente() ?? false;
        $esIndActPrimerMes = $c->es_ind_act_primer_mes ?? false; // flag del controller
        $esArlModalidad = (int) ($c->tipo_modalidad_id) === 15;
        $esAfil = false;
        $esIngresoFuturo = false;
        if ($esArlModalidad) {
            // Gestión ARL siempre es cobro de afiliación, no planilla
            $esAfil = true;
        } elseif ($c->fecha_ingreso) {
            $fIngC = $c->fecha_ingreso;
            $periodoIngresoVista = (int) $fIngC->year * 100 + (int) $fIngC->month;
            $periodoActualVista = $anio * 100 + $mes;
            if ($periodoIngresoVista > $periodoActualVista) {
                // Ingreso en mes futuro: no facturable en este período
                $esIngresoFuturo = true;
            } elseif ((int) $fIngC->month === $mes && (int) $fIngC->year === $anio) {
                // I ACT: NO es afiliación pura (cobra SS también)
                // I VENC y empresa: sí es afiliación pura
                if (! $esIndActPrimerMes) {
                    $esAfil = true;
                }
            }
        }
        // Si ya hay factura, usar su tipo (planilla o afiliacion)
        if ($fact) {
            // I ACT primer mes puede tener tipo='planilla' con afiliación incluida
            $esAfil = $fact->tipo === 'afiliacion' && ! ($fact->afiliacion > 0 && $fact->total_ss > 0);
        }
        // Corrección: si es mes de afiliación (ingresó este mes), ignorar los días del retiro preview
        if ($esAfil && ! $fact) {
            $dias = 0;
        }
        // Tiempo Parcial: detectar y obtener días por entidad
        $esTP = $c->tipoModalidad?->esTiempoParcial() ?? false;
        $diasTP = $esTP ? $c->tipoModalidad->diasPorEntidad() : null;
        // Valores: si hay factura usar los reales; si es retirado facturable → usar factura_0;
        // Si es retiro pendiente → calcular SS proporcional con los días del retiro pendiente;
        // si activo sin factura → estimar
        $vEps = $fact ? $r100($fact->v_eps) : 0;
        $vArl = $fact ? $r100($fact->v_arl) : 0;
        $vCaja = $fact ? $r100($fact->v_caja) : 0;
        $vPen = $fact ? $r100($fact->v_afp) : 0;
        $vAdm = $fact ? (int) ($fact->admon + $fact->admin_asesor) : (($esRetirado && ! $esAfil) ? 0 : (int) (($c->administracion ?? 0) + ($c->admon_asesor ?? 0)));
        $vIva = $fact ? $rIva($fact->iva) : 0;
        // Total y SS
        $cotiz = $c->cotizacion_calc ?? $c->calcularCotizacion($dias); // pre-calculado en controller
        if (! $fact) {
            if ($esRetirado && $factRetiroPreview && ! $esAfil) {
                // Retiro facturable: mostrar valores reales de la factura_0
                $vEps = $r100($factRetiroPreview->v_eps);
                $vArl = $r100($factRetiroPreview->v_arl);
                $vPen = $r100($factRetiroPreview->v_afp);
                $vCaja = $r100($factRetiroPreview->v_caja);
                $vSS = $r100($factRetiroPreview->total_ss);
                // Admon: el valor base de contrato (30 días); se recalculará por JS según el checkbox
                $vAdm = (int) (($c->administracion ?? 0) + ($c->admon_asesor ?? 0));
                $vAdmProporcional = (int) (($vAdm / 30) * $dias); // proporcional a días de retiro
                // El IVA del retiro grava la admon que se cobre; la factura_0 la guarda en 0
                // porque aún no se sabe si se cobrará (lo decide el checkbox de admon).
                $vIva = \App\Services\IvaService::calcular($vAdm, (bool) ($c->tiene_iva ?? false));
                $vTot = $vSS + $vAdm + $vIva; // total con admon completa (JS ajusta si es proporcional)
            } elseif ($tieneRetiroPendiente) {
                // Retiro pendiente nuevo: calcular SS proporcional con los días del retiro
                $cotizRetPend = $c->calcularCotizacion($diasRetiroPendiente);
                $vEps = $r100($cotizRetPend['eps'] ?? 0);
                $vArl = $r100($cotizRetPend['arl'] ?? 0);
                $vPen = $r100($cotizRetPend['pen'] ?? 0);
                $vCaja = $r100($cotizRetPend['caja'] ?? 0);
                $vIva = $rIva($cotizRetPend['iva'] ?? 0);
                $vSS = $r100($cotizRetPend['ss'] ?? 0);
                $vAdm = $cobrarAdmonRetiroPendiente
                    ? (int) (($c->administracion ?? 0) + ($c->admon_asesor ?? 0))
                    : 0;
                $vTot = $vSS + $vAdm + $vIva;
            } elseif ($esRetirado && ! $factRetiroPreview) {
                // Retirado sin retiro facturable — ya fue cobrado o es retiro masivo → 0
                $vEps = $vArl = $vPen = $vCaja = $vIva = $vAdm = $vSS = 0;
                $vTot = 0;
            } elseif ($esArlModalidad && ! $esAfil) {
                // ARL fuera de su mes de ARL → cobro es 0, no paga planilla
                $vEps = $vArl = $vPen = $vCaja = $vIva = $vAdm = $vSS = 0;
                $vTot = 0;
            } elseif ($esIndActPrimerMes) {
                // I ACT primer mes: SS reales (días del mes) + afiliación + admon
                $vEps = $r100($cotiz['eps'] ?? 0);
                $vArl = $r100($cotiz['arl'] ?? 0);
                $vPen = $r100($cotiz['pen'] ?? 0);
                $vCaja = $r100($cotiz['caja'] ?? 0);
                // IVA: admon (ya viene en $cotiz) + costo de afiliación
                $vIva = $rIva($cotiz['iva'] ?? 0)
                       + \App\Services\IvaService::calcular((int) ($c->costo_afiliacion ?? 0), (bool) ($c->tiene_iva ?? false));
                $vSS = $r100($cotiz['ss']);
                // admon ya calculado arriba desde contrato
                $vTot = $vSS + $vAdm + $vIva + (int) (($c->costo_afiliacion ?? 0) + ($c->seguro ?? 0));
            } elseif ($esAfil) {
                // Afiliación pura (I VENC, empresa): SS=0, admon=0 — el IVA grava el costo de afiliación
                $vEps = 0;
                $vArl = 0;
                $vPen = 0;
                $vCaja = 0;
                $vSS = 0;
                $vAdm = 0;
                $vIva = \App\Services\IvaService::calcular((int) ($c->costo_afiliacion ?? 0), (bool) ($c->tiene_iva ?? false));
                $vTot = (int) (($c->costo_afiliacion ?? 0) + ($c->seguro ?? 0)) + $vIva;
            } elseif ($esIngresoFuturo) {
                // Ingreso en mes futuro: el contrato aún no inicia → todo en 0
                $vEps = $vArl = $vPen = $vCaja = $vIva = $vAdm = $vSS = 0;
                $vTot = 0;
            } else {
                $vEps = $r100($cotiz['eps'] ?? 0);
                $vArl = $r100($cotiz['arl'] ?? 0);
                $vPen = $r100($cotiz['pen'] ?? 0);
                $vCaja = $r100($cotiz['caja'] ?? 0);
                $vIva = $rIva($cotiz['iva'] ?? 0);
                $vSS = $r100($cotiz['ss']);
                $vTot = $vSS + $vAdm + $vIva;
            }
        } else {
            $vSS = $r100($fact->total_ss);
            $vTot = (int) $fact->total;
        }
        // SENA e ICBF del aportante no exonerado. Ya van dentro de $vSS y del total; se
        // sacan aparte solo para poder cuadrar la fila contra la planilla del operador,
        // donde son dos aportes con su propia tarifa.
        $vParaf = $fact
            ? (int) ($fact->v_parafiscales ?? 0)
            : ($vSS > 0 ? (int) ($cotiz['parafiscales'] ?? 0) : 0);
        // Mora: solo mostrar si el contrato NO está pagado aún
        // - Con factura pendiente → usar mora guardada en la factura
        // - Sin factura → usar mora estimada del batch pre-calculado
        // - Ya pagado ($yaP) → ocultar mora (ya fue liquidada, no hay alerta pendiente)
        $vMora = 0;
        if (! $yaP) {
            if ($fact && ($fact->mora ?? 0) > 0) {
                $vMora = (int) $fact->mora;
            } elseif (! $fact) {
                $vMora = (int) ($moraPorContrato[$c->id] ?? 0);
            }
        }
        // Costo de afiliación para data-* (lo necesita el modal)
        $vAfiliacion = ($esAfil || $esIndActPrimerMes) ? (int) ($c->costo_afiliacion ?? 0) : 0;

        return [
            'fact' => $fact,
            'factRetiroPreview' => $factRetiroPreview,
            'yaP' => $yaP,
            'nombre' => $nombre,
            'tipoMod' => $tipoMod,
            'tipoNom' => $tipoNom,
            'rs' => $rs,
            'esRetirado' => $esRetirado,
            'esIngRet' => $esIngRet,
            'fIng' => $fIng,
            'fRet' => $fRet,
            'tieneRetiroPendiente' => $tieneRetiroPendiente,
            'fechaRetiroPendienteStr' => $fechaRetiroPendienteStr,
            'diasRetiroPendiente' => $diasRetiroPendiente,
            'cobrarAdmonRetiroPendiente' => $cobrarAdmonRetiroPendiente,
            'dias' => $dias,
            'esIndep' => $esIndep,
            'esIndActPrimerMes' => $esIndActPrimerMes,
            'esArlModalidad' => $esArlModalidad,
            'esAfil' => $esAfil,
            'esIngresoFuturo' => $esIngresoFuturo,
            'fIngC' => $fIngC,
            'periodoIngresoVista' => $periodoIngresoVista,
            'periodoActualVista' => $periodoActualVista,
            'esTP' => $esTP,
            'diasTP' => $diasTP,
            'vEps' => $vEps,
            'vArl' => $vArl,
            'vCaja' => $vCaja,
            'vPen' => $vPen,
            'vAdm' => $vAdm,
            'vIva' => $vIva,
            'cotiz' => $cotiz,
            'vSS' => $vSS,
            'vAdmProporcional' => $vAdmProporcional,
            'vTot' => $vTot,
            'cotizRetPend' => $cotizRetPend,
            'vParaf' => $vParaf,
            'vMora' => $vMora,
            'vAfiliacion' => $vAfiliacion,
        ];
    }

    /**
     * Lo que la empresa debe o tiene a favor en sus facturas de lote, con los
     * abonos posteriores y los ajustes de saldo ya descontados.
     *
     * @return array{favor: int, pendiente: int}
     */
    public function saldoEmpresa(int $aliadoId, int $empresaId): array
    {
        $saldoNetoEmpresa = Factura::where('aliado_id', $aliadoId)
            ->where('empresa_id', $empresaId)
            ->whereNotNull('saldo_proximo')
            ->whereIn('estado', ['pagada', 'prestamo', 'abono'])
            ->whereNull('deleted_at')
            ->sum('saldo_proximo');

        // Los abonos son pagos posteriores que NO tocan `saldo_proximo`, asi que
        // el pendiente hay que bajarlo con ellos o se le cobra al cliente algo
        // que ya pago: 18 empresas venian mostrando $21,1 millones de mas.
        // Solo se descuentan contra deuda: nunca convierten un pendiente en
        // saldo a favor.
        $abonosEmpresa = (int) DB::table('abonos')
            ->join('facturas', 'facturas.id', '=', 'abonos.factura_id')
            ->where('facturas.aliado_id', $aliadoId)
            ->where('facturas.empresa_id', $empresaId)
            ->whereNull('facturas.deleted_at')
            ->sum('abonos.valor');

        // El crédito que el aliado ya dio por consumido no se vuelve a ofrecer
        // (ver SaldoAjuste): se descuenta del saldo a favor, nunca del pendiente.
        $ajustesEmpresa = \App\Models\SaldoAjuste::totalDeEmpresa($aliadoId, $empresaId);

        $saldoEmpresaFavor = $saldoNetoEmpresa > 0
            ? max(0, (int) $saldoNetoEmpresa - $ajustesEmpresa)
            : 0;
        $saldoEmpresaPendiente = $saldoNetoEmpresa < 0
            ? max(0, (int) abs($saldoNetoEmpresa) - $abonosEmpresa)
            : 0;

        return ['favor' => $saldoEmpresaFavor, 'pendiente' => $saldoEmpresaPendiente];
    }

    /**
     * Gente que estuvo con la empresa y ya no sale en la tabla del período.
     *
     * Una fila por persona —la de su último retiro—, porque la misma cédula
     * puede haber entrado y salido varias veces y la lista es para saber quién
     * estuvo, no cuántas veces. Quien ya aparece arriba no se repite acá: puede
     * tener un retiro viejo y un contrato vigente al mismo tiempo.
     *
     * Va aparte de datosPeriodo() a propósito: esa la comparte el
     * Excel, que no lleva esta lista y no tiene por qué pagar la consulta.
     */
    public function retiradosPrevios(int $empresaId, int $aliadoId, $cedulasVisibles)
    {
        return $this->queryRetiradosPrevios($empresaId, $aliadoId, $cedulasVisibles)
            // DESC deja los retiros sin fecha de últimos, que es donde estorban
            // menos: son fichas viejas sin la fecha diligenciada.
            ->orderByDesc('ct.fecha_retiro')
            ->orderByDesc('ct.id')
            ->get([
                'ct.cedula', 'ct.fecha_ingreso', 'ct.fecha_retiro',
                'cl.id as cliente_id', 'cl.tipo_doc', 'cl.primer_nombre', 'cl.primer_apellido',
                'rs.razon_social',
            ])
            // La misma cédula puede tener más de una ficha de cliente y el join
            // la duplicaría; el unique cubre eso y el «una fila por persona».
            ->unique('cedula')
            ->values();
    }

    /**
     * El tronco de esa consulta, que comparten la lista y el conteo.
     */
    public function queryRetiradosPrevios(int $empresaId, int $aliadoId, $cedulasVisibles)
    {
        return DB::table('contratos as ct')
            ->join('clientes as cl', function ($j) use ($aliadoId) {
                $j->on('cl.cedula', '=', 'ct.cedula')->where('cl.aliado_id', $aliadoId);
            })
            ->leftJoin('razones_sociales as rs', 'rs.id', '=', 'ct.razon_social_id')
            ->where('ct.aliado_id', $aliadoId)
            ->where('cl.cod_empresa', $empresaId)
            ->where('ct.estado', 'retirado')
            ->when(
                $cedulasVisibles->isNotEmpty(),
                fn ($q) => $q->whereNotIn('ct.cedula', $cedulasVisibles)
            );
    }
}
