<?php

namespace App\Services;

use App\Models\Plano;
use App\Models\Gasto;
use App\Models\Cliente;
use App\Models\Empresa;
use App\Models\PlanillaEnvioWhatsapp;
use App\Models\PlanillaEnvioWhatsappDetalle;
use App\Models\WhatsappPlantilla;
use App\Models\WhatsappConfig;
use App\Services\WhatsappApiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Auth;

class PlanillaWhatsappService
{
    /**
     * Códigos de operadores autorizados para envío de PDF por WhatsApp.
     * Solo ARUS Enlace, Enlace y Simple tienen plantilla PDF configurada.
     */
    public const OPERADORES_AUTORIZADOS = ['SIMPLE', 'ARUS'];

    /**
     * Consulta las planillas pagadas en el periodo para el aliado y detecta su operador.
     * Retorna TODOS los planos con gasto registrado; el campo `es_operador_autorizado`
     * indica si el operador tiene plantilla PDF habilitada para envío.
     *
     * Con `$cedulas` se limita a esas personas: el portal de empresas pide
     * solo las de sus trabajadores en vez de todo el aliado.
     */
    public function obtenerPlanosPagados(int $aliadoId, int $mes, int $anio, $cedulas = null)
    {
        $mesVencido = $mes > 1 ? $mes - 1 : 12;
        $anioVencido = $mes > 1 ? $anio : $anio - 1;

        // 1. Cargar TODOS los operadores activos
        $operadores = DB::table('operadores_planilla')
            ->get(['id', 'nombre', 'codigo']);

        // 2. Closure de periodos mixtos
        $wherePeriodo = fn ($q) => Plano::filtrarPeriodoDePago($q, $mes, $anio);

        // 3. Consultar planos con número de planilla (pagadas)
        $planosQuery = DB::table('planos AS p')
            ->leftJoin('contratos AS c', 'c.id', '=', 'p.contrato_id')
            ->leftJoin('clientes AS cl', function ($join) use ($aliadoId) {
                $join->on('cl.cedula', '=', 'p.no_identifi')
                     ->where('cl.aliado_id', '=', $aliadoId);
            })
            ->leftJoin('empresas AS em', 'em.id', '=', 'cl.cod_empresa')
            ->leftJoin('razones_sociales AS rs', 'rs.id', '=', 'p.razon_social_id')
            ->where('p.aliado_id', $aliadoId)
            ->whereNull('p.deleted_at')
            ->where('p.tipo_reg', 'planilla')
            ->whereNotNull('p.numero_planilla')
            ->where('p.numero_planilla', '!=', '')
            ->where($wherePeriodo)
            // Acepta una lista o una subconsulta (Closure): con una lista larga
            // SQL Server se queda sin parámetros (tope de 2100).
            ->when($cedulas !== null, fn ($q) => $q->whereIn(
                'p.no_identifi',
                $cedulas instanceof \Closure ? $cedulas : collect($cedulas)->all()
            ))
            ->select([
                'p.id',
                'p.no_identifi AS cedula',
                'p.numero_planilla',
                'p.mes_plano',
                'p.anio_plano',
                'p.tipo_modalidad_id',
                'p.primer_nombre', 'p.segundo_nombre',
                'p.primer_ape', 'p.segundo_ape',
                'cl.id AS cliente_id',
                'cl.celular AS cliente_celular',
                'cl.cod_empresa',
                'em.id AS empresa_id',
                'em.empresa AS empresa_nombre',
                'em.contacto AS empresa_contacto',
                'em.celular AS empresa_celular',
                'rs.razon_social AS razon_social_nombre',
                'rs.es_independiente',
                'p.contrato_id',
                'p.factura_id',
            ]);

        $planos = $planosQuery->get()->map(function ($plano) {
            $plano->nombre_completo = trim("{$plano->primer_nombre} {$plano->segundo_nombre} {$plano->primer_ape} {$plano->segundo_ape}");
            return $plano;
        });

        // 4. Cruzar con gastos tipo 'pago_planilla'
        $planosFiltrados = collect();

        $numerosPlanillas = $planos->pluck('numero_planilla')->filter()->unique()->toArray();
        $planoIds = $planos->pluck('id')->toArray();

        // Cargar todos los gastos en una sola query
        $gastos = collect();
        if (!empty($numerosPlanillas)) {
            $gastos = DB::table('gastos')
                ->where('aliado_id', $aliadoId)
                ->where('tipo', 'pago_planilla')
                ->whereIn('numero_planilla', $numerosPlanillas)
                ->get(['pagado_a', 'fecha', 'numero_planilla'])
                ->groupBy('numero_planilla');
        }

        // Cargar todos los detalles de envío en una sola query
        $detallesEnvios = collect();
        if (!empty($planoIds)) {
            $detallesEnvios = DB::table('planilla_envios_whatsapp_detalle')
                ->whereIn('plano_id', $planoIds)
                ->orderBy('id', 'desc')
                ->get(['plano_id', 'estado', 'wa_message_id', 'error', 'enviado_at'])
                ->groupBy('plano_id');
        }

        foreach ($planos as $plano) {
            $gastosPlano = $gastos->get($plano->numero_planilla);
            $gasto = $gastosPlano ? $gastosPlano->first() : null;

            if (!$gasto) {
                continue; // si no hay gasto, no podemos verificar el operador de pago
            }

            // Detectar el operador del gasto
            $operadorNombreGasto = trim($gasto->pagado_a);
            $operadorDetectado = null;

            foreach ($operadores as $op) {
                if (
                    strcasecmp($op->nombre, $operadorNombreGasto) === 0 ||
                    stripos($operadorNombreGasto, $op->nombre) !== false ||
                    stripos($op->nombre, $operadorNombreGasto) !== false
                ) {
                    $operadorDetectado = $op;
                    break;
                }
            }

            // Si no se detecta operador, marcar como no autorizado pero mostrar igual
            if (!$operadorDetectado) {
                $plano->operador_id = null;
                $plano->operador_nombre = $operadorNombreGasto ?: 'Desconocido';
                $plano->operador_codigo = null;
                $plano->es_operador_autorizado = false;
            } else {
                $plano->operador_id = $operadorDetectado->id;
                $plano->operador_nombre = $operadorDetectado->nombre;
                $plano->operador_codigo = $operadorDetectado->codigo;
                $plano->es_operador_autorizado = in_array(
                    strtoupper($operadorDetectado->codigo),
                    self::OPERADORES_AUTORIZADOS
                );
            }

            $plano->fecha_pago = $gasto->fecha;

            // Obtener el último estado de envío para este plano
            $detallesPlano = $detallesEnvios->get($plano->id);
            $ultimoDetalleEnvio = $detallesPlano ? $detallesPlano->first() : null;

            $plano->envio_estado = $ultimoDetalleEnvio ? $ultimoDetalleEnvio->estado : 'pendiente';
            $plano->envio_error = $ultimoDetalleEnvio ? $ultimoDetalleEnvio->error : null;
            $plano->envio_fecha = $ultimoDetalleEnvio ? $ultimoDetalleEnvio->enviado_at : null;

            $planosFiltrados->push($plano);
        }

        // Ordenamos por fecha de pago desc
        return $planosFiltrados->sortByDesc('fecha_pago')->values();
    }

    /**
     * Prepara la lista de destinatarios dependiendo del tipo de envío.
     */
    public function obtenerDestinatarios($planos, string $tipoEnvio)
    {
        $destinatarios = collect();

        foreach ($planos as $plano) {
            $codEmpresa = (int)($plano->cod_empresa ?? 0);

            if ($tipoEnvio === 'individual') {
                // Solo clientes con cod_empresa NULL o 1 (Individual)
                if ($codEmpresa === 0 || $codEmpresa === 1) {
                    $destinatarios->push([
                        'plano_id'               => $plano->id,
                        'contrato_id'            => $plano->contrato_id,
                        'cliente_cedula'         => $plano->cedula,
                        'cliente_nombre'         => $plano->nombre_completo,
                        'empresa_id'             => null,
                        'empresa_nombre'         => 'Individual',
                        'wa_numero'              => $plano->cliente_celular,
                        'nombre_destinatario'    => $plano->nombre_completo,
                        'numero_planilla'        => $plano->numero_planilla,
                        'operador_id'            => $plano->operador_id,
                        'operador_nombre'        => $plano->operador_nombre,
                        'tipo_modalidad_id'      => $plano->tipo_modalidad_id,
                        'periodo_mes'            => $plano->mes_plano,
                        'periodo_anio'           => $plano->anio_plano,
                        'envio_estado'           => $plano->envio_estado,
                        // el motivo del último intento: sin él, un «omitido» en
                        // la tabla no dice por qué quedó sin enviar
                        'envio_error'            => $plano->envio_error,
                        'envio_fecha'            => $plano->envio_fecha,
                        'es_operador_autorizado' => $plano->es_operador_autorizado,
                    ]);
                }
            } elseif ($tipoEnvio === 'empleado_empresa') {
                // Solo clientes con cod_empresa > 1 (dentro de empresa)
                if ($codEmpresa > 1) {
                    $destinatarios->push([
                        'plano_id'               => $plano->id,
                        'contrato_id'            => $plano->contrato_id,
                        'cliente_cedula'         => $plano->cedula,
                        'cliente_nombre'         => $plano->nombre_completo,
                        'empresa_id'             => $plano->empresa_id,
                        'empresa_nombre'         => $plano->empresa_nombre ?? 'Sin Empresa',
                        'wa_numero'              => $plano->cliente_celular,
                        'nombre_destinatario'    => $plano->nombre_completo,
                        'numero_planilla'        => $plano->numero_planilla,
                        'operador_id'            => $plano->operador_id,
                        'operador_nombre'        => $plano->operador_nombre,
                        'tipo_modalidad_id'      => $plano->tipo_modalidad_id,
                        'periodo_mes'            => $plano->mes_plano,
                        'periodo_anio'           => $plano->anio_plano,
                        'envio_estado'           => $plano->envio_estado,
                        'envio_error'            => $plano->envio_error,
                        'envio_fecha'            => $plano->envio_fecha,
                        'es_operador_autorizado' => $plano->es_operador_autorizado,
                    ]);
                }
            } elseif ($tipoEnvio === 'contacto_empresa') {
                // Envía al contacto de la empresa con el nombre del CLIENTE afiliado
                if ($codEmpresa > 1 && $plano->empresa_celular) {
                    $destinatarios->push([
                        'plano_id'               => $plano->id,
                        'contrato_id'            => $plano->contrato_id,
                        'cliente_cedula'         => $plano->cedula,
                        // nombre del CLIENTE (para columna "Cliente" en tabla y saludo WA)
                        'cliente_nombre'         => $plano->nombre_completo,
                        'empresa_id'             => $plano->empresa_id,
                        'empresa_nombre'         => $plano->empresa_nombre ?? 'Sin Empresa',
                        // wa_numero = celular del CONTACTO de empresa (quien recibe)
                        'wa_numero'              => $plano->empresa_celular,
                        // nombre_destinatario = nombre del CLIENTE (variable {{1}} en plantilla WA)
                        'nombre_destinatario'    => $plano->nombre_completo,
                        // contacto_nombre para referencia de quién recibe físicamente
                        'contacto_nombre'        => $plano->empresa_contacto,
                        'numero_planilla'        => $plano->numero_planilla,
                        'operador_id'            => $plano->operador_id,
                        'operador_nombre'        => $plano->operador_nombre,
                        'tipo_modalidad_id'      => $plano->tipo_modalidad_id,
                        'periodo_mes'            => $plano->mes_plano,
                        'periodo_anio'           => $plano->anio_plano,
                        'envio_estado'           => $plano->envio_estado,
                        'envio_error'            => $plano->envio_error,
                        'envio_fecha'            => $plano->envio_fecha,
                        'es_operador_autorizado' => $plano->es_operador_autorizado,
                    ]);
                }
            }
        }

        return $destinatarios;
    }

    /**
     * Genera el nombre del archivo PDF de planilla con el período de SERVICIO (mes actual del filtro).
     *
     * Siempre usa el mes del filtro activo de la UI (mes de facturación/servicio actual),
     * sin importar si el plano corresponde al mes vencido (dependiente) o actual (independiente).
     *
     * Formato: Planilla_SS_Nombre_Apellido_MesNombre_Anio.pdf
     */
    public static function generarNombreArchivoPdf(
        string $nombreCompleto,
        int $mesFiltro,
        int $anioFiltro
    ): string {
        $mesesEs = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
        ];

        // Limpiar nombre: mayúsculas, sin tildes ni eñes, espacios a guion bajo.
        // Primero a mayúsculas: hay nombres guardados como "PEñA", con la eñe
        // en minúscula, y cambiarla por "n" dejaba el archivo como "PEnA".
        $nombreLimpio = \Illuminate\Support\Str::ascii(mb_strtoupper($nombreCompleto, 'UTF-8'));
        $nombreLimpio = preg_replace('/[^A-Z0-9\s]/', '', $nombreLimpio);
        $nombreLimpio = str_replace(' ', '_', trim($nombreLimpio));
        $nombreLimpio = preg_replace('/_+/', '_', $nombreLimpio);

        $mesNombre = $mesesEs[$mesFiltro] ?? "Mes{$mesFiltro}";

        return "Planilla_SS_{$nombreLimpio}_{$mesNombre}_{$anioFiltro}.pdf";
    }

    /**
     * Crea un nuevo lote de envío masivo con sus detalles.
     */
    public function crearLoteEnvio(int $aliadoId, int $usuarioId, ?int $plantillaId, int $mes, int $anio, string $tipoEnvio, $destinatarios)
    {
        return DB::transaction(function () use ($aliadoId, $usuarioId, $plantillaId, $mes, $anio, $tipoEnvio, $destinatarios) {
            $lote = PlanillaEnvioWhatsapp::create([
                'aliado_id'           => $aliadoId,
                'usuario_id'          => $usuarioId,
                'plantilla_id'        => $plantillaId,
                'mes'                 => $mes,
                'anio'                => $anio,
                'tipo_envio'          => $tipoEnvio,
                'total_destinatarios' => count($destinatarios),
                'total_enviados'      => 0,
                'total_fallidos'      => 0,
                'total_omitidos'      => 0,
                'estado'              => 'pendiente',
            ]);

            foreach ($destinatarios as $d) {
                PlanillaEnvioWhatsappDetalle::create([
                    'envio_id'            => $lote->id,
                    'plano_id'            => $d['plano_id'],
                    'contrato_id'         => $d['contrato_id'],
                    'cliente_cedula'      => $d['cliente_cedula'],
                    'empresa_id'          => $d['empresa_id'],
                    'wa_numero'           => $d['wa_numero'],
                    'nombre_destinatario' => $d['nombre_destinatario'],
                    'numero_planilla'     => $d['numero_planilla'],
                    'operador_nombre'     => $d['operador_nombre'],
                    'periodo_mes'         => $d['periodo_mes'],
                    'periodo_anio'        => $d['periodo_anio'],
                    'estado'              => 'pendiente',
                ]);
            }

            return $lote;
        });
    }

    /**
     * Registra un reenvío individual ya despachado a Meta.
     *
     * `planilla_envios_whatsapp_detalle.envio_id` tiene FK a
     * `planilla_envios_whatsapp.id`, así que un reenvío suelto no puede
     * apuntar a un id inventado: necesita su propio lote de un destinatario.
     * Sin él el INSERT rebota contra la FK, el detalle nunca se guarda y la
     * planilla se queda «pendiente» en la tabla aunque el mensaje ya salió,
     * lo que lleva a reenviarla una y otra vez.
     */
    public function registrarReenvioIndividual(
        int $aliadoId,
        int $usuarioId,
        ?int $plantillaId,
        int $mes,
        int $anio,
        string $tipoEnvio,
        array $detalle
    ): PlanillaEnvioWhatsappDetalle {
        return DB::transaction(function () use ($aliadoId, $usuarioId, $plantillaId, $mes, $anio, $tipoEnvio, $detalle) {
            $lote = PlanillaEnvioWhatsapp::create([
                'aliado_id'           => $aliadoId,
                'usuario_id'          => $usuarioId,
                'plantilla_id'        => $plantillaId,
                'mes'                 => $mes,
                'anio'                => $anio,
                'tipo_envio'          => $tipoEnvio,
                'total_destinatarios' => 1,
                'total_enviados'      => 1,
                'total_fallidos'      => 0,
                'total_omitidos'      => 0,
                'estado'              => 'completado',
            ]);

            return PlanillaEnvioWhatsappDetalle::create($detalle + [
                'envio_id'   => $lote->id,
                'estado'     => 'enviado',
                'enviado_at' => now(),
            ]);
        });
    }

    /**
     * Crea automáticamente la plantilla transaccional en Meta si no existe.
     */
    public function crearPlantillaEnMeta(int $aliadoId)
    {
        $aliado = DB::table('aliados')->where('id', $aliadoId)->first();
        if (!$aliado) {
            throw new \Exception("Aliado no encontrado.");
        }

        // Buscar si ya existe la plantilla
        $plantillaExistente = WhatsappPlantilla::where('aliado_id', $aliadoId)
            ->where('nombre', 'envio_planilla_seguridad_social')
            ->first();

        if ($plantillaExistente) {
            return $plantillaExistente;
        }

        // Estructura de la plantilla
        $cuerpo = "Hola {{1}}, la planilla de seguridad social ha sido pagada exitosamente.\n\nOperador: {{2}}\nNúmero de planilla: {{3}}\n\nAdjuntamos tu certificado de pago. Cualquier duda comunícate con nosotros.";

        $plantilla = WhatsappPlantilla::create([
            'aliado_id'       => $aliadoId,
            'nombre'          => 'envio_planilla_seguridad_social',
            'nombre_display'  => 'Envío Planilla Seguridad Social',
            'categoria'       => 'UTILITY',
            'idioma'          => 'es_ES',
            'estado'          => 'approved', // se asume aprobada para Meta tras crearla
            'creado_en_meta'  => true,
            'header_tipo'     => 'DOCUMENT',
            'cuerpo'          => $cuerpo,
            'variables_mapa'  => json_encode([
                "1" => "cliente.nombre",
                "2" => "operador.nombre",
                "3" => "planilla.numero"
            ]),
        ]);

        // Registrar en configuración del aliado
        $config = WhatsappConfig::where('aliado_id', $aliadoId)->first();
        if ($config) {
            $config->update([
                'planilla_envio_plantilla_id' => $plantilla->id
            ]);
        }

        // Nota: en producción esto llamaría a WhatsappApiService::crearPlantillaEnMeta()
        // pero la creación directa en Meta vía API a veces requiere validación de negocio manual,
        // por lo que creamos el registro local y dejamos que el usuario lo verifique.
        try {
            if ($config) {
                $apiService = app(WhatsappApiService::class);
                $apiService->crearPlantillaEnMeta($plantilla, $config);
            }
        } catch (\Exception $e) {
            // Logear error pero no romper la ejecución local
            \Log::error("Error al crear plantilla en Meta: " . $e->getMessage());
        }

        return $plantilla;
    }
}
