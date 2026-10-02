<?php

namespace App\Services;

use App\Models\Plano;
use App\Models\OperadorPlanillaTemplate;
use Illuminate\Support\Facades\DB;
use setasign\Fpdi\Fpdi;

class PlanillaFormularioService
{
    /**
     * Ensambla los datos del plano y genera el PDF rellenado.
     * @param Plano $plano
     * @param int|null $forceOperadorId Si se provee, ignora la autodetección y fuerza este operador.
     * @return string Contenido binario del PDF.
     */
    public function generar(Plano $plano, ?int $forceOperadorId = null): string
    {
        // 1. Intentar autodetectar el operador por el que se pagó la planilla (o usar el forzado)
        $operadorPlanillaId = $forceOperadorId ?? $this->detectarOperadorId($plano);

        // Una planilla pagada en Mi Planilla (u otro operador fuera de Enlace sin
        // plantilla propia) salía con el formato de ARUS: un soporte de Enlace
        // para algo que nunca pasó por Enlace (oct-2026).
        if (!self::tieneSoporte($operadorPlanillaId)) {
            $nombre = DB::table('operadores_planilla')->where('id', $operadorPlanillaId)->value('nombre');
            throw new \RuntimeException("BryNex no tiene el soporte de planilla de {$nombre}.");
        }

        // 2. La plantilla configurada del operador; si no tiene una usable, la de
        //    ARUS (es el mismo reporte de Enlace y está calibrada en el editor);
        //    y si tampoco, el dibujo estático de SuaportePdfService.
        //
        //    Antes, si faltaba el archivo se copiaba encima la plantilla del
        //    repositorio —un certificado real, con datos de Brygar y de un
        //    afiliado— y si no había campos se devolvía la plantilla tal cual: el
        //    cliente podía recibir el soporte de otra persona.
        $template = $this->plantillaUsable($operadorPlanillaId)
            ?? $this->plantillaUsable(DB::table('operadores_planilla')->where('codigo', 'ARUS')->value('id'));

        if (!$template) {
            return SuaportePdfService::generar($plano);
        }

        $rutaPdf = storage_path('app/formularios/planillas/' . $template->formulario_pdf);
        $campos = $template->formulario_campos;

        // 3. Obtener los datos del cotizante y del plano
        $datos = $this->ensamblarDatos($plano);

        // 4. Rellenar la plantilla PDF utilizando FPDI y FPDF
        return $this->rellenarPdf($rutaPdf, $campos, $datos);
    }

    /**
     * ¿Se puede armar el soporte de una planilla de este operador? Sí si tiene
     * plantilla propia, si corre sobre Enlace (la de ARUS es el mismo reporte)
     * o si no se sabe cuál fue (se asume Enlace, como siempre).
     */
    public static function tieneSoporte($operadorPlanillaId): bool
    {
        return !in_array((int) $operadorPlanillaId, self::operadoresSinSoporte(), true);
    }

    /** Ids de los operadores fuera de Enlace que no tienen plantilla. */
    public static function operadoresSinSoporte(): array
    {
        return DB::table('operadores_planilla')
            ->where(fn ($q) => $q->whereNull('codigo')->orWhereNotIn('codigo', array_keys(SuaporteApiService::HOSTS)))
            ->whereNotIn('id', DB::table('operador_planillas_templates')
                ->whereNotNull('operador_planilla_id') // un NULL en el NOT IN no deja pasar nada
                ->whereNotNull('formulario_pdf')
                ->where('formulario_pdf', '<>', '')
                ->select('operador_planilla_id'))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * La plantilla del operador si está completa: archivo en disco y campos
     * calibrados. Una a medias no sirve y no se intenta arreglar al vuelo.
     */
    protected function plantillaUsable($operadorPlanillaId): ?OperadorPlanillaTemplate
    {
        if (!$operadorPlanillaId) {
            return null;
        }

        $template = OperadorPlanillaTemplate::where('operador_planilla_id', $operadorPlanillaId)->first();

        $usable = $template
            && $template->formulario_pdf
            && !empty($template->formulario_campos)
            && file_exists(storage_path('app/formularios/planillas/' . $template->formulario_pdf));

        return $usable ? $template : null;
    }

    /**
     * Autodetecta el operador de planilla del plano.
     */
    protected function detectarOperadorId(Plano $plano): ?int
    {
        // a. Intentar buscar en el registro de la API
        $apiPlanilla = \DB::table('operador_planillas_api')
            ->where('numero_planilla', $plano->numero_planilla)
            ->where('aliado_id', $plano->aliado_id)
            ->first();

        if ($apiPlanilla && $apiPlanilla->operador_planilla_id) {
            return (int)$apiPlanilla->operador_planilla_id;
        }

        // b. Buscar en el operador asignado al cliente del plano
        $cliente = \App\Models\Cliente::where('cedula', $plano->no_identifi)->first();
        if ($cliente && $cliente->operador_planilla_id) {
            return (int)$cliente->operador_planilla_id;
        }

        // c. Si no, retornar ARUS Enlace (Enlace Operativo/SuAporte) por defecto
        $operadorDefault = \DB::table('operadores_planilla')
            ->where('codigo', 'ARUS')
            ->first();

        return $operadorDefault ? (int)$operadorDefault->id : null;
    }

    /**
     * Ensambla todos los datos dinámicos del cotizante y aportante para rellenar la plantilla.
     */
    public function ensamblarDatos(Plano $plano): array
    {
        $c = PilaCotizanteCalculator::calcular($this->conDatosDeCalculo($plano));

        $esPlanillaY = ($plano->tipo_modalidad_id == 8);

        // Sin entidad se dice "NINGUNA", como el operador; con entidad pero sin
        // nombre guardado, en blanco. Antes caían a PORVENIR, NUEVA EPS y SURA:
        // un tiempo parcial sin salud salía con una EPS que nunca tuvo.
        $sinAfp = $esPlanillaY || $c['codAfpPila'] === '';
        $sinEps = $esPlanillaY || ($c['codEpsPila'] === '' && (int) $c['vEps'] === 0);

        $nombreAfp = $sinAfp ? 'NINGUNA AFP' : (string) $plano->nombre_afp;
        $nombreEps = $sinEps ? 'NINGUNA EPS' : (string) $plano->nombre_eps;
        $nombreArl = (string) $plano->nombre_arl;

        $esIndependiente = (bool)($plano->razonSocial?->es_independiente ?? false);
        $sinCajaCcf = ($c['codCcfPila'] == 'CCF68');

        $nombreCaja = $esPlanillaY ? 'NINGUNA CCF' : ($plano->nombre_caja ?: ($sinCajaCcf ? 'COMCAJA' : ''));
        if ($esIndependiente && $sinCajaCcf && !$esPlanillaY) {
            $nombreCaja = 'NINGUNA CCF';
        }

        // Resolver el código de PILA real de AFP si es que viene como NIT
        $codAfpPilaReal = $c['codAfpPila'];
        if ($sinAfp) {
            $codAfpPilaReal = 'NIN-AF';
        } elseif (!empty($c['codAfpPila'])) {
            $nitAfpLimpio = preg_replace('/[^0-9]/', '', $c['codAfpPila']);
            if ($nitAfpLimpio !== '') {
                $pension = \App\Models\Pension::where('nit', $nitAfpLimpio)->orWhere('codigo', $c['codAfpPila'])->first();
                if ($pension && !empty($pension->codigo)) {
                    $codAfpPilaReal = $pension->codigo;
                }
            }
        }

        // Resolver el código de PILA real de EPS si es que viene como NIT
        $codEpsPilaReal = $c['codEpsPila'];
        if ($sinEps) {
            $codEpsPilaReal = 'NIN-EP';
        } elseif (!empty($c['codEpsPila'])) {
            $nitEpsLimpio = preg_replace('/[^0-9]/', '', $c['codEpsPila']);
            if ($nitEpsLimpio !== '') {
                $epsObj = \App\Models\Eps::where('nit', $nitEpsLimpio)->orWhere('codigo', $c['codEpsPila'])->first();
                if ($epsObj && !empty($epsObj->codigo)) {
                    $codEpsPilaReal = $epsObj->codigo;
                }
            }
        }

        // Periodo de Cotización: es el mes del plano (ej: 06)
        $perCot = $plano->anio_plano . str_pad($plano->mes_plano, 2, '0', STR_PAD_LEFT);
        
        // Buscar el gasto de tipo pago_planilla asociado al número de planilla y aliado
        $gasto = null;
        if (!empty($plano->numero_planilla)) {
            $gasto = \App\Models\Gasto::where('aliado_id', $plano->aliado_id)
                ->where('numero_planilla', $plano->numero_planilla)
                ->where('tipo', 'pago_planilla')
                ->first();
        }

        // La hora exacta del pago la da el operador (ver EnlaceInformeIndividualService):
        // el gasto se registra en BryNex antes o después, nunca en el mismo segundo.
        $pagoOperador = !empty($plano->numero_planilla)
            ? DB::table('planillas_pago_operador')
                ->where('aliado_id', $plano->aliado_id)
                ->where('numero_planilla', $plano->numero_planilla)
                ->value('fecha_pago')
            : null;

        $fechaPagoParaCarbon = null;
        if ($pagoOperador) {
            $fechaPagoParaCarbon = $pagoOperador;
        } elseif ($gasto) {
            $fechaPagoParaCarbon = $gasto->created_at ?? $gasto->fecha;
        } elseif ($plano->fecha_pago) {
            $fechaPagoParaCarbon = $plano->fecha_pago;
        } elseif ($plano->factura?->fecha_pago) {
            $fechaPagoParaCarbon = $plano->factura->fecha_pago;
        }

        // Periodo de Servicio: es el mes en que se EFECTÚA el pago / factura (ej: 07).
        // Se asume mes_plano + 1, o el mes real de la fecha de pago si existe.
        $mesPago = $plano->mes_plano == 12 ? 1 : $plano->mes_plano + 1;
        $anioPago = $plano->mes_plano == 12 ? $plano->anio_plano + 1 : $plano->anio_plano;
        
        if ($fechaPagoParaCarbon) {
            $dtPago = \Carbon\Carbon::parse($fechaPagoParaCarbon);
            $mesPago = $dtPago->month;
            $anioPago = $dtPago->year;
        }

        $perSer = $anioPago . str_pad($mesPago, 2, '0', STR_PAD_LEFT);

        // Lo que el operador dijo de esta planilla al liquidarla. Queda guardado
        // en `operador_planillas_api`, así que el PDF usa su cifra sin volver a
        // abrir sesión: el portal tarda más de un minuto y a veces ni responde.
        $delOperador = $plano->numero_planilla
            ? \DB::table('operador_planillas_api')
                ->where('aliado_id', $plano->aliado_id)
                ->where('numero_planilla', $plano->numero_planilla)
                ->orderByDesc('id')
                ->first([
                    'estado', 'valor_total', 'numero_afiliados', 'nombre_aportante',
                    'periodo_cotizacion', 'periodo_servicio', 'fecha_limite',
                ])
            : null;

        // Los dos períodos se deducen arriba —el de cotización del plano, el de
        // servicio sumándole un mes—, y esa cuenta ya se ha equivocado: es la
        // que mete a dos personas en la misma planilla cuando se igualan. Si el
        // operador los reporta, mandan ellos: es lo que quedó radicado.
        $perCot = $delOperador?->periodo_cotizacion ?: $perCot;
        $perSer = $delOperador?->periodo_servicio ?: $perSer;

        $granTotal = $c['vAfp'] + $c['vEps'] + $c['vArl'] + $c['vCcf'];



        // "PAGADA" solo cuando consta el pago. Antes iba fijo, así que el
        // soporte de una planilla liquidada pero sin pagar salía igual de
        // rotundo que el de una pagada.
        $estadoPlanilla = $fechaPagoParaCarbon
            ? 'PAGADA'
            : match ($delOperador->estado ?? null) {
                'validada'    => 'LIQUIDADA',
                'con_errores' => 'CON ERRORES',
                'error'       => 'CON ERRORES',
                default       => '',
            };

        // Total que el operador liquidó para la planilla completa. Queda a mano
        // de la plantilla, que hoy solo imprime el aporte de esta persona: el
        // documento pasa a poder decir de qué planilla forma parte y por cuánto.
        $totalOperador = isset($delOperador->valor_total)
            ? '$ '.number_format((float) $delOperador->valor_total, 0, ',', '.')
            : '';

        $afiliadosCount = Plano::where('numero_planilla', $plano->numero_planilla)
            ->where('aliado_id', $plano->aliado_id)
            ->count();

        // Sin fecha real no se pone ninguna. Antes caía a un 03/07/2026 14:03:12
        // fijo, así que un soporte sin pago registrado salía afirmando una hora
        // exacta que nunca ocurrió —y este documento es lo que el cliente
        // guarda como prueba—. En blanco se nota que falta; inventada, no.
        if ($fechaPagoParaCarbon) {
            $dt = \Carbon\Carbon::parse($fechaPagoParaCarbon);
            $pagoFecha = $dt->format('Y-m-d');
            $pagoHora  = $dt->format('H:i:s.0');
            $pagoHoraSinMs = $dt->format('H:i:s');
        } else {
            $pagoFecha = '';
            $pagoHora  = '';
            $pagoHoraSinMs = '';
        }

        $clienteObj = $plano->contrato?->cliente;

        // Ficha de la empresa tal como la tiene el operador (razones-sociales:
        // sincronizar-operador). Es lo que imprime su soporte, así que manda.
        // Lo que no esté ni ahí ni en la razón social queda en blanco: antes se
        // rellenaba con la dirección, el teléfono y el representante de Brygar,
        // y así salía en los soportes de empresas de otros aliados.
        $rs = $plano->razonSocial;
        $ficha = (!$esIndependiente && is_array($rs?->datos_operador)) ? $rs->datos_operador : [];
        $contactoOp = $ficha['informacionContacto'] ?? [];
        $repOp = $ficha['representanteLegal'] ?? [];
        $mayus = fn ($v) => mb_strtoupper(trim((string) $v));

        $razonSocialAportante = $esIndependiente
            ? $mayus(implode(' ', array_filter([$plano->primer_nombre, $plano->segundo_nombre, $plano->primer_ape, $plano->segundo_ape])))
            : $mayus($ficha['razonSocial'] ?? $plano->razon_social);

        $nitAportante = $esIndependiente
            ? (($plano->tipo_doc ?? 'CC') . ' ' . ($plano->no_identifi ?? ''))
            : trim('NI ' . ($rs?->nit ?? ''));

        $direccionAportante = $esIndependiente
            ? $mayus($clienteObj?->direccion_vivienda ?: $clienteObj?->direccion_cobro)
            : $mayus(($contactoOp['datosDireccion']['direccionCompleta'] ?? null) ?: $rs?->direccion);

        $tipoAportante = $esIndependiente ? 'INDEPENDIENTE' : 'EMPLEADOR';
        $tipoPersona = match ($ficha['tipoPersonaCodigo'] ?? $rs?->tipo_persona ?? ($esIndependiente ? 'N' : 'J')) {
            'N' => 'NATURAL',
            default => 'JURÍDICA',
        };

        $telefonoAportante = $esIndependiente
            ? (string) ($clienteObj?->celular ?: $clienteObj?->telefono)
            : (string) (($contactoOp['numeroTelefono'] ?? null) ?: $rs?->telefonos);

        // Cuánta gente lleva la planilla. Contar nuestros propios planos supone
        // que BryNex y el operador tienen exactamente la misma lista, y si
        // alguien quedó fuera al radicar, el soporte lo taparía. El número del
        // operador es el que de verdad se pagó.
        $afiliadosCountVal = $esIndependiente
            ? '1'
            : (string) ($delOperador?->numero_afiliados ?: max(1, $afiliadosCount));

        // El operador imprime apellidos y luego nombres.
        $representanteVal = '';
        $representanteCedVal = '';
        if (!$esIndependiente) {
            if (!empty($repOp['numeroIdentificacion'])) {
                $representanteVal = $mayus(implode(' ', array_filter([
                    $repOp['primerApellido'] ?? null, $repOp['segundoApellido'] ?? null,
                    $repOp['primerNombre'] ?? null, $repOp['segundoNombre'] ?? null,
                ])));
                $representanteCedVal = trim(($repOp['tipoIdentificacion'] ?? 'CC') . ' ' . $repOp['numeroIdentificacion']);
            } else {
                $representanteVal = $mayus($rs?->nombre_rep);
                $representanteCedVal = $rs?->cedula_rep ? trim(($rs->rep_tipo_doc ?: 'CC') . ' ' . $rs->cedula_rep) : '';
            }
        }

        // La exoneración la decide la calculadora con los datos de la empresa
        // del cliente, igual que en el TXT. Antes iba "S" fija para todo empleador.
        $exoneradoVal = $esPlanillaY ? 'N' : ($c['exonerado'] ?? 'N');

        $tipoPlanilla = $esPlanillaY ? 'Y' : ($esIndependiente ? 'I' : 'E');
        $formaPresentacion = $esIndependiente
            ? 'ÚNICO'
            : match ((int) ($ficha['formaPresentacionId'] ?? 0) ?: ($rs?->forma_presentacion === 'U' ? 1 : 3)) {
                1 => 'ÚNICO',
                default => 'SUCURSAL',
            };

        [$ciudadAportante, $departamentoAportante] = $esIndependiente
            ? [$mayus($clienteObj?->municipio?->nombre), $mayus($clienteObj?->departamento?->nombre)]
            : $this->municipioDane(($contactoOp['codigoMunicipio'] ?? null) ?: $rs?->cod_municipio);

        // Ciudad y ubicación laboral: para dependientes con caja, usar datos del cliente.
        // Solo cuando NO paga caja (sinCajaCcf) se usa el código de Guainía.
        $ciudadAfiliado = ($sinCajaCcf && !$esIndependiente && !$esPlanillaY)
            ? '94001000 - 94'
            : ($clienteObj?->municipio?->id && $clienteObj?->departamento?->id
                ? $clienteObj->municipio->id . '000 - ' . $clienteObj->departamento->id
                : '');

        // Ubicación laboral: GUAINIA solo para dependientes que NO pagan caja (sinCajaCcf).
        // Independientes, planilla Y y dependientes con caja usan el departamento del cliente.
        $ubicacionLaboralAfiliado = ($sinCajaCcf && !$esIndependiente && !$esPlanillaY)
            ? 'GUAINIA'
            // El operador lo imprime sin tildes ni eñes (NARINO, GUAINIA).
            : strtoupper(\Illuminate\Support\Str::ascii((string) $clienteObj?->departamento?->nombre));

        return [
            // Aportante
            'aportante.razon_social'         => $razonSocialAportante,
            'aportante.nit'                  => $nitAportante,
            'aportante.direccion'            => $direccionAportante,
            'aportante.tipo_aportante'       => $tipoAportante,
            'aportante.tipo_persona'         => $tipoPersona,
            'aportante.sucursal'             => $esIndependiente ? 'ÚNICO' : 'SUCURSAL',
            'aportante.departamento'         => $departamentoAportante,
            'aportante.ciudad'               => $ciudadAportante,
            'aportante.telefono'             => $telefonoAportante,
            'aportante.forma_presentacion'   => $formaPresentacion,
            'aportante.afiliados'            => $afiliadosCountVal,
            'aportante.representante'        => $representanteVal,
            'aportante.cedula_representante' => $representanteCedVal,

            // Metadatos
            'plano.fecha_creacion'          => now()->format('Y-m-d, h:i:s') . ' ' . (now()->format('a') === 'am' ? 'a. m.' : 'p. m.'),
            'plano.tipo_planilla'           => $tipoPlanilla,
            'plano.numero_planilla'         => $plano->numero_planilla,
            'plano.periodo_cotizacion'      => $perCot,
            'plano.periodo_servicio'        => $perSer,
            'plano.fecha_pago_completa'     => trim("{$pagoFecha} {$pagoHora}"),
            'plano.fecha_pago_estado'       => $estadoPlanilla,
            'plano.fecha_pago_fecha'        => $pagoFecha,
            'plano.fecha_pago_hora'         => $pagoHora,
            // Hasta cuándo daba plazo el operador. Sirve para leer si el pago
            // entró a tiempo o con mora, que hoy el documento no dice.
            'plano.fecha_limite'            => $delOperador?->fecha_limite
                ? \Carbon\Carbon::parse($delOperador->fecha_limite)->format('Y-m-d')
                : '',

            // Afiliado
            'afiliado.tipo_doc'             => $plano->tipo_doc,
            'afiliado.cedula'               => $plano->no_identifi,
            'afiliado.tipo_doc_cedula'      => $plano->tipo_doc . ' ' . $plano->no_identifi,
            'afiliado.nombre_completo'      => strtoupper($plano->primer_ape . ' ' . $plano->segundo_ape . ' ' . $plano->primer_nombre . ' ' . $plano->segundo_nombre),
            'afiliado.exonerado'            => $exoneradoVal,
            'afiliado.ciudad'               => $ciudadAfiliado,
            'afiliado.ubicacion_laboral'    => $ubicacionLaboralAfiliado,
            'afiliado.tipo_cotizante'       => str_pad($c['tipoCotizante'], 2, '0', STR_PAD_LEFT),
            'afiliado.subtipo_cotizante'    => str_pad($c['subtipoCotizante'], 2, '0', STR_PAD_LEFT),

            // Aportes Detallados
            'aporte.novedad_ing'  => !empty($plano->fecha_ing) ? 'X' : '',
            'aporte.novedad_ret'  => !empty($plano->fecha_ret) ? ($esPlanillaY ? 'T' : 'X') : '',
            'aporte.novedad_irp'  => (string)$this->calcularNovedadIrp($plano),
            'aporte.dias_afp'     => $c['diasPension'],
            'aporte.dias_eps'     => $c['diasSalud'],
            'aporte.dias_arl'     => $c['diasArl'],
            'aporte.dias_ccf'     => $c['diasCcf'],
            // En blanco donde PILA prohíbe marcarlo (23, 51, 59), igual que el TXT.
            'aporte.tipo_salario' => $c['tipoSalarioAplica'] ? 'F' : '',
            'aporte.salario'      => '$ ' . number_format($c['ibcFull'], 0, ',', '.'),
            
            // Pensión
            'aporte.afp_codigo'  => $codAfpPilaReal,
            'aporte.afp_tarifa'  => ($sinAfp ? '0' : number_format($c['tarifaAfpDecimal'] * 100, 0)) . ' %',
            'aporte.afp_ibc'     => '$ ' . number_format($c['ibcAfp'], 0, ',', '.'),
            'aporte.afp_aporte'  => '$ ' . number_format($c['vAfp'], 0, ',', '.'),
            'aporte.afp_fsp'     => '$ 0',
            'aporte.afp_fsps'    => '$ 0',

            // Salud
            'aporte.eps_codigo'  => $codEpsPilaReal,
            'aporte.eps_tarifa'  => ($sinEps ? '0' : number_format(floatval($c['tarifaEpsStr']) * 100, 0)) . ' %',
            'aporte.eps_ibc'     => '$ ' . number_format($sinEps ? 0 : $c['ibcEps'], 0, ',', '.'),
            'aporte.eps_aporte'  => '$ ' . number_format($c['vEps'], 0, ',', '.'),
            'aporte.eps_upc'     => '$ 0',

            // Riesgos
            'aporte.arl_codigo'  => ($c['codArlPila'] ?: '14-11'),
            'aporte.arl_clase'   => $c['nivelRiesgo'],
            'aporte.arl_tarifa'  => number_format($c['tarifaArlDecimal'] * 100, 3, ',', '') . ' %',
            'aporte.arl_ibc'     => '$ ' . number_format($c['ibcArl'], 0, ',', '.'),
            'aporte.arl_aporte'  => '$ ' . number_format($c['vArl'], 0, ',', '.'),

            // Caja
            'aporte.ccf_codigo'  => ($esPlanillaY ? 'NIN-CC' : ($esIndependiente && $sinCajaCcf ? 'NIN-CC' : $c['codCcfPila'])),
            'aporte.ccf_tarifa'  => ($esPlanillaY ? '0 %' : ($esIndependiente && $sinCajaCcf ? '0 %' : '4 %')),
            'aporte.ccf_ibc'     => ($esPlanillaY ? '$ 0' : ($esIndependiente && $sinCajaCcf ? '$ 0' : '$ ' . number_format($c['ibcCcf'], 0, ',', '.'))),
            'aporte.ccf_aporte'  => ($esPlanillaY ? '$ 0' : ($esIndependiente && $sinCajaCcf ? '$ 0' : '$ ' . number_format($c['vCcf'], 0, ',', '.'))),

            // Parafiscales
            'aporte.sena_tarifa' => '0 %',
            'aporte.sena_aporte' => '$ 0',
            'aporte.icbf_tarifa' => '0 %',
            'aporte.icbf_aporte' => '$ 0',

            // Totales Administradoras (Nombres fijos o calculados)
            'total.afp_nombre'  => $nombreAfp,
            'total.eps_nombre'  => $nombreEps,
            'total.arl_nombre'  => $nombreArl === '' ? '' : (str_starts_with(strtoupper($nombreArl), 'ARL') ? $nombreArl : 'ARL ' . $nombreArl),
            'total.ccf_nombre'  => $nombreCaja,
            'total.fsp_nombre'  => 'FSP SOLIDARIDAD',
            'total.fsps_nombre' => 'FSP SUBSISTENCIA',
            'total.sena_nombre' => 'SENA',
            'total.icbf_nombre' => 'ICBF',
            'total.esap_nombre' => 'ESAP',
            'total.men_nombre'  => 'MEN',

            // Totales Valores
            'total.afp'   => '$ ' . number_format($c['vAfp'], 0, ',', '.'),
            'total.fsp'   => '$ 0',
            'total.fsps'  => '$ 0',
            'total.eps'   => '$ ' . number_format($c['vEps'], 0, ',', '.'),
            'total.arl'   => '$ ' . number_format($c['vArl'], 0, ',', '.'),
            'total.ccf'   => ($esPlanillaY ? '$ 0' : ($esIndependiente && $sinCajaCcf ? '$ 0' : '$ ' . number_format($c['vCcf'], 0, ',', '.'))),
            'total.sena'  => '$ 0',
            'total.icbf'  => '$ 0',
            'total.esap'  => '$ 0',
            'total.men'   => '$ 0',
            'total.final' => '$ ' . number_format($granTotal, 0, ',', '.'),
            // Lo que el operador cobró por la planilla entera, tal como quedó
            // registrado al liquidarla. Vacío en las planillas que no pasaron
            // por el API: mejor sin dato que con uno deducido.
            'total.planilla_operador' => $totalOperador,
        ];
    }

    /**
     * [ciudad, departamento] a partir del código DANE que da el operador
     * ("76001000", o "76001"). En blanco si no se reconoce.
     */
    protected function municipioDane(?string $codigo): array
    {
        $codigo = substr(preg_replace('/\D/', '', (string) $codigo), 0, 5);

        if (strlen($codigo) !== 5) {
            return ['', ''];
        }

        $ciudad = DB::table('ciudades')->where('id_ciudad_t', $codigo)->first(['nombre', 'DescripcionDepartamento']);

        return [mb_strtoupper((string) $ciudad?->nombre), mb_strtoupper((string) $ciudad?->DescripcionDepartamento)];
    }

    /**
     * El plano con los mismos datos que le da PlanoPilaTxtService a la calculadora.
     *
     * Antes se le pasaba el plano pelado, y la calculadora no sabe de tiempo
     * parcial, días por subsistema, códigos PILA ni exoneración si no se los
     * dan: todo cotizante 51 salía como dependiente de 30 días con EPS, y el
     * soporte decía un total que no era el pagado. Estas columnas son las del
     * TXT, y deben seguir siéndolo: el PDF tiene que decir lo que se liquidó.
     *
     * Devuelve una copia: el plano original no se toca ni se guarda.
     */
    public function conDatosDeCalculo(Plano $plano): Plano
    {
        $aliadoId = (int) $plano->aliado_id;

        $fila = DB::table('planos AS p')
            ->leftJoin('facturas AS f', 'f.id', '=', 'p.factura_id')
            ->leftJoin('clientes AS cl', function ($join) use ($aliadoId) {
                $join->on('cl.cedula', '=', 'p.no_identifi')->where('cl.aliado_id', '=', $aliadoId);
            })
            ->leftJoin('ciudades AS c', 'c.id_ciudad_t', '=', 'cl.municipio_id')
            ->leftJoin('departamentos AS d', 'd.id', '=', 'cl.departamento_id')
            ->leftJoin('pensiones AS afp_t', DB::raw('CAST(afp_t.nit AS VARCHAR(20))'), '=', DB::raw('p.cod_afp'))
            ->leftJoin('pensiones AS afp_cli', 'afp_cli.id', '=', 'cl.pension_id')
            ->leftJoin('eps AS eps_t', DB::raw('CAST(eps_t.nit AS VARCHAR(20))'), '=', DB::raw('p.cod_eps'))
            ->leftJoin('cajas AS caj_t', DB::raw('CAST(caj_t.nit AS VARCHAR(20))'), '=', DB::raw('p.cod_caja'))
            ->leftJoin('arls AS arl_m', DB::raw('CAST(arl_m.nit AS VARCHAR(20))'), '=', DB::raw('p.cod_arl'))
            ->leftJoin('tipo_modalidad AS tm', 'tm.id', '=', 'p.tipo_modalidad_id')
            ->leftJoin('contratos AS ctr', 'ctr.id', '=', 'p.contrato_id')
            // Tipo E - Extras: el plan dice qué se vende y en cuántos días. Sin
            // él, un "Solo CCF 14" se calculaba como caja de 30 días.
            ->leftJoin('planes_contrato AS pln', 'pln.id', '=', 'ctr.plan_id')
            ->leftJoin('razones_sociales AS rs', 'rs.id', '=', 'p.razon_social_id')
            ->leftJoin('empresas AS emp', function ($join) use ($aliadoId) {
                $join->on('emp.id', '=', 'cl.cod_empresa')->where('emp.aliado_id', '=', $aliadoId);
            })
            ->where('p.id', $plano->id)
            ->first([
                DB::raw('eps_t.codigo  AS cod_eps_pila'),
                DB::raw('afp_t.codigo  AS cod_afp_pila'),
                DB::raw('afp_cli.codigo AS cod_afp_cliente'),
                DB::raw('arl_m.codigo  AS cod_arl_pila'),
                DB::raw('caj_t.codigo  AS cod_caj_pila'),
                'f.v_eps', 'f.v_afp', 'f.v_arl', 'f.v_caja', 'f.dias_cotizados',
                'cl.genero',
                DB::raw('DATEDIFF(YEAR, cl.fecha_nacimiento, GETDATE()) AS edad_calculada'),
                DB::raw('d.id AS dep_id'),
                DB::raw('CAST(c.Municipio AS INT) AS mun_id'),
                DB::raw('tm.es_tiempo_parcial AS es_tiempo_parcial'),
                DB::raw('ISNULL(p.dias_tp_afp, ISNULL(tm.dias_afp, 30)) AS dias_afp'),
                DB::raw('ISNULL(p.dias_tp_caja, ISNULL(p.dias_tp_afp, ISNULL(tm.dias_caja, 30))) AS dias_caja'),
                DB::raw('ISNULL(rs.es_independiente, 0) AS rs_es_independiente'),
                'ctr.porcentaje_caja',
                DB::raw('pln.codigo AS plan_codigo'),
                DB::raw('ISNULL(p.grupo_fondo_solidaridad, ctr.grupo_fondo_solidaridad) AS grupo_fondo_solidaridad'),
                DB::raw('emp.exonerado_parafiscales AS exonerado_parafiscales'),
            ]);

        $copia = clone $plano;

        foreach ((array) $fila as $columna => $valor) {
            $copia->setAttribute($columna, $valor);
        }

        return $copia;
    }

    /**
     * Calcula la cantidad de días de incapacidad por riesgos profesionales (IRP) del cotizante en el periodo.
     */
    protected function calcularNovedadIrp(Plano $plano): int
    {
        if (!$plano->contrato_id || !$plano->mes_plano || !$plano->anio_plano) {
            return 0;
        }

        try {
            $inicioMes = \Carbon\Carbon::create($plano->anio_plano, $plano->mes_plano, 1)->startOfMonth();
            $finMes = \Carbon\Carbon::create($plano->anio_plano, $plano->mes_plano, 1)->endOfMonth();

            $incapacidades = \DB::table('incapacidades')
                ->where('contrato_id', $plano->contrato_id)
                ->where(function($q) {
                    $q->where('tipo_entidad', 'arl')
                      ->orWhere('tipo_incapacidad', 'accidente_laboral');
                })
                ->where(function($q) use ($inicioMes, $finMes) {
                    $q->whereBetween('fecha_inicio', [$inicioMes->format('Y-m-d'), $finMes->format('Y-m-d')])
                      ->orWhereBetween('fecha_terminacion', [$inicioMes->format('Y-m-d'), $finMes->format('Y-m-d')])
                      ->orWhere(function($inner) use ($inicioMes, $finMes) {
                          $inner->where('fecha_inicio', '<=', $inicioMes->format('Y-m-d'))
                                ->where('fecha_terminacion', '>=', $finMes->format('Y-m-d'));
                      });
                })
                ->whereNull('deleted_at')
                ->get(['fecha_inicio', 'fecha_terminacion']);

            if ($incapacidades->isEmpty()) {
                return 0;
            }

            $diasTotales = 0;
            foreach ($incapacidades as $inc) {
                $ini = \Carbon\Carbon::parse($inc->fecha_inicio);
                $fin = \Carbon\Carbon::parse($inc->fecha_terminacion);

                $rangoInicio = $ini->greaterThan($inicioMes) ? $ini : $inicioMes;
                $rangoFin = $fin->lessThan($finMes) ? $fin : $finMes;

                if ($rangoInicio->lessThanOrEqualTo($rangoFin)) {
                    $diasTotales += $rangoInicio->diffInDays($rangoFin) + 1;
                }
            }

            return min(30, $diasTotales);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Rellena las celdas de la plantilla PDF a través de FPDF y FPDI.
     */
    protected function rellenarPdf(string $rutaPdf, array $campos, array $datos): string
    {
        $pdf = new BrynexFpdi('L', 'pt');
        $pdf->SetAutoPageBreak(false);

        $pdf->setSourceFile($rutaPdf);
        $tplId = $pdf->importPage(1);
        $size = $pdf->getTemplateSize($tplId);

        // Agregamos la página con las dimensiones exactas de la plantilla
        $pdf->AddPage('L', [$size['width'], $size['height']]);
        $pdf->useTemplate($tplId, 0, 0, $size['width'], $size['height']);

        // Color de texto por defecto: Negro
        $pdf->SetTextColor(0, 0, 0);

        foreach ($campos as $c) {
            $llave = $c['dato'] ?? '';
            $valor = $datos[$llave] ?? ($c['label'] ?? '');

            // Si es un campo custom o vacío y no hay datos, usar el valor por defecto si existe
            if (empty($valor) && isset($c['value'])) {
                $valor = $c['value'];
            }

            // Coordenadas físicas del campo en la plantilla
            $x = floatval($c['x'] ?? 0);
            $y = floatval($c['y'] ?? 0);
            $w = floatval($c['w'] ?? 0);
            $h = floatval($c['h'] ?? 0);

            // Ajustar estilos y fuente
            $fontFamily = 'Arial';
            $fontStyle = '';
            if (!empty($c['bold'])) $fontStyle .= 'B';
            if (!empty($c['italic'])) $fontStyle .= 'I';

            $fontSize = floatval($c['font_size'] ?? 7.5);
            $pdf->SetFont($fontFamily, $fontStyle, $fontSize);

            // Aplicar espaciado de caracteres (letter spacing / Tc)
            $charSpacing = floatval($c['letter_spacing'] ?? 0);
            $pdf->SetCharSpacing($charSpacing);

            // Determinar si debemos dibujar un rectángulo blanco antes para limpiar el original
            $limpiar = !empty($c['limpiar']) || !isset($c['limpiar']); // Limpiar por defecto si no se indica
            if ($limpiar && $w > 0 && $h > 0) {
                // Color de limpieza: blanco por defecto, o gris si se especifica en la configuración
                if (($c['color_fondo'] ?? '') === 'gris') {
                    $pdf->SetFillColor(204, 204, 204); // Gris de estado
                } else {
                    $pdf->SetFillColor(255, 255, 255); // Blanco
                }
                $pdf->Rect($x, $y, $w, $h, 'F');
            }

            // Lógica idéntica a formularios EPS: anclar texto al fondo de la caja
            $cellH = $fontSize + 1; // celda justa alrededor del texto
            $textY = $y + $h - $cellH; // anclar al fondo del rect

            // Ajuste global milimétrico para coincidir con la vista HTML
            // El HTML tiene un padding-left de 2px (1.5pt), mientras que FPDF usa 2.83pt por defecto.
            $pdf->setCMargin(1.5);

            $align = $c['align'] ?? 'left';
            $alignFpdf = 'L';
            if ($align === 'right') {
                $alignFpdf = 'R';
            } elseif ($align === 'center') {
                $alignFpdf = 'C';
            }

            // Restauramos el Y original ya que el +1.5 lo bajó demasiado
            $textY = $y + $h - $cellH;

            $pdf->SetXY($x, $textY);
            
            // FPDF solo soporta ISO-8859-1 (Latin-1). Convertimos el texto para soportar tildes y ñ.
            $valorIso = mb_convert_encoding((string)$valor, 'ISO-8859-1', 'UTF-8');
            
            if ($w > 0) {
                $pdf->Cell($w, $cellH, $valorIso, 0, 0, $alignFpdf);
            } else {
                $pdf->Write($cellH, $valorIso);
            }

            // Restaurar espaciado a 0
            $pdf->SetCharSpacing(0);
        }

        return $pdf->Output('S');
    }
}

/**
 * Subclase de FPDI para extender sus capacidades de FPDF con soporte a espaciado de caracteres (Tc).
 */
class BrynexFpdi extends Fpdi
{
    public function SetCharSpacing($spacing)
    {
        $this->_out(sprintf('%.3F Tc', $spacing));
    }

    public function setCMargin($margin)
    {
        $this->cMargin = $margin;
    }
}
