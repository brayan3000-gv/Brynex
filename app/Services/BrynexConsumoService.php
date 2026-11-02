<?php

namespace App\Services;

use App\Models\Aliado;
use App\Models\BrynexCobroAliado;
use App\Models\BrynexCobroDetalle;
use App\Models\BrynexModulo;
use App\Models\BrynexModuloAliado;
use App\Models\BrynexPagoAliado;
use App\Models\BrynexTramoTarifa;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Servicio central de cálculo de consumo mensual y gestión de cobros
 * de Brynex hacia sus aliados.
 *
 * Módulos actuales:
 *  1 → administracion   : contratos activos en el mes
 *  2 → afiliaciones     : afiliaciones creadas en el mes (si aplica)
 *  3 → wa_plantillas    : mensajes de plantilla enviados
 *  4 → wa_conversaciones: conversaciones con actividad en el mes
 */
class BrynexConsumoService
{
    // ════════════════════════════════════════════════════════════════════════
    // MÓDULO 1 — Administración (contratos activos)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Calcula el consumo del módulo Administración.
     *
     * Un contrato está activo en el mes si:
     *   fecha_ingreso <= último día del mes
     *   AND (fecha_retiro IS NULL OR fecha_retiro >= primer día del mes)
     *
     * @return array{
     *   modulo_id: int,
     *   modulo_codigo: string,
     *   modulo_nombre: string,
     *   cant_unidades: int,
     *   tarifa_unidad: float|null,
     *   tarifa_minima: float|null,
     *   subtotal: float,
     *   tramo_aplicado: string,
     *   descripcion: string,
     *   contratos: Collection
     * }
     */
    public function calcularAdministracion(int $alidoId, int $mes, int $anio): array
    {
        $primerDia = Carbon::create($anio, $mes, 1)->startOfDay();
        $ultimoDia = $primerDia->copy()->endOfMonth();

        $contratos = DB::table('contratos as c')
            ->join('clientes as cl', 'cl.cedula', '=', 'c.cedula')
            ->where('c.aliado_id', $alidoId)
            ->where('c.fecha_ingreso', '<=', $ultimoDia->toDateString())
            ->where(function ($q) use ($primerDia) {
                $q->whereNull('c.fecha_retiro')
                  ->orWhere('c.fecha_retiro', '>=', $primerDia->toDateString());
            })
            ->select('c.id', 'c.cedula', DB::raw("LTRIM(RTRIM(cl.primer_nombre + ' ' + ISNULL(cl.segundo_nombre, '') + ' ' + cl.primer_apellido + ' ' + ISNULL(cl.segundo_apellido, ''))) as nombre_completo"), 'c.fecha_ingreso', 'c.fecha_retiro')
            ->orderBy('c.cedula')
            ->get();

        $cantidad = $contratos->count();
        $calculo  = BrynexTramoTarifa::calcularCobro(
            moduloId: 1,
            cantidad: $cantidad,
            alidoId:  $alidoId,
            fecha:    $ultimoDia->toDateString()
        );

        return array_merge($calculo, [
            'modulo_id'     => 1,
            'modulo_codigo' => 'administracion',
            'modulo_nombre' => 'Administración Mensual',
            'descripcion'   => "Contratos activos en {$this->nombreMes($mes)} {$anio}",
            'contratos'     => $contratos,
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // MÓDULO 2 — Afiliaciones creadas en el mes
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Calcula el consumo del módulo Afiliaciones.
     * Solo aplica si el aliado tiene el módulo activo y afiliaciones_brynex=true.
     */
    public function calcularAfiliaciones(int $alidoId, int $mes, int $anio): array
    {
        $primerDia = Carbon::create($anio, $mes, 1)->startOfDay();
        $ultimoDia = $primerDia->copy()->endOfMonth();

        // Contar contratos creados en el mes (tipo afiliación = primer ingreso)
        $afiliaciones = DB::table('contratos as c')
            ->join('clientes as cl', 'cl.cedula', '=', 'c.cedula')
            ->where('c.aliado_id', $alidoId)
            ->whereBetween('c.created_at', [$primerDia, $ultimoDia])
            ->select('c.id', 'c.cedula', DB::raw("LTRIM(RTRIM(cl.primer_nombre + ' ' + ISNULL(cl.segundo_nombre, '') + ' ' + cl.primer_apellido + ' ' + ISNULL(cl.segundo_apellido, ''))) as nombre_completo"), 'c.fecha_ingreso', 'c.created_at')
            ->orderBy('c.created_at')
            ->get();

        $cantidad = $afiliaciones->count();
        $calculo  = BrynexTramoTarifa::calcularCobro(
            moduloId: 2,
            cantidad: $cantidad,
            alidoId:  $alidoId,
            fecha:    $ultimoDia->toDateString()
        );

        return array_merge($calculo, [
            'modulo_id'     => 2,
            'modulo_codigo' => 'afiliaciones',
            'modulo_nombre' => 'Gestión de Afiliaciones',
            'descripcion'   => "Afiliaciones creadas en {$this->nombreMes($mes)} {$anio}",
            'contratos'     => $afiliaciones,
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // MÓDULO 3 — WhatsApp Plantillas enviadas
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Calcula el consumo del módulo WA Plantillas.
     * Cuenta mensajes salientes de plantilla (plantilla_id NOT NULL) del mes.
     * Excluye los que pertenecen a una campaña de marketing (ver calcularMarketing) —
     * esos se cobran aparte, en su propio módulo, para no facturarlos dos veces.
     */
    public function calcularWaPlantillas(int $alidoId, int $mes, int $anio): array
    {
        $primerDia = Carbon::create($anio, $mes, 1)->startOfDay();
        $ultimoDia = $primerDia->copy()->endOfMonth();

        $mensajes = DB::table('whatsapp_mensajes as m')
            ->leftJoin('whatsapp_plantillas as p', 'p.id', '=', 'm.plantilla_id')
            ->where('m.aliado_id', $alidoId)
            ->where('m.direccion', 'saliente')
            ->whereNotNull('m.plantilla_id')
            ->whereBetween('m.created_at', [$primerDia, $ultimoDia])
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('whatsapp_envios_masivos_detalle as d')
                    ->join('whatsapp_envios_masivos as e', 'e.id', '=', 'd.envio_id')
                    ->whereColumn('d.wa_message_id', 'm.wa_message_id')
                    ->whereNotNull('e.campana_id');
            })
            ->select('m.id', 'm.created_at', 'm.estado', 'p.nombre as plantilla_nombre')
            ->orderBy('m.created_at')
            ->get();

        $cantidad = $mensajes->count();
        $calculo  = BrynexTramoTarifa::calcularCobro(
            moduloId: 3,
            cantidad: $cantidad,
            alidoId:  $alidoId,
            fecha:    $ultimoDia->toDateString()
        );

        return array_merge($calculo, [
            'modulo_id'     => 3,
            'modulo_codigo' => 'wa_plantillas',
            'modulo_nombre' => 'WhatsApp — Plantillas',
            'descripcion'   => "Mensajes de plantilla enviados en {$this->nombreMes($mes)} {$anio}",
            'contratos'     => $mensajes,
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // MÓDULO 4 — WhatsApp Conversaciones únicas con actividad
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Calcula el consumo del módulo WA Conversaciones.
     * Cuenta conversaciones únicas que tuvieron al menos 1 mensaje en el mes.
     */
    public function calcularWaConversaciones(int $alidoId, int $mes, int $anio): array
    {
        $primerDia = Carbon::create($anio, $mes, 1)->startOfDay();
        $ultimoDia = $primerDia->copy()->endOfMonth();

        $conversaciones = DB::table('whatsapp_mensajes as m')
            ->join('whatsapp_conversaciones as c', 'c.id', '=', 'm.conversacion_id')
            ->where('m.aliado_id', $alidoId)
            ->whereBetween('m.created_at', [$primerDia, $ultimoDia])
            // whatsapp_conversaciones no tiene `numero_cliente` (el número está en
            // el contacto): con él, toda la página de consumo daba 500.
            ->select('c.id', 'c.nombre_contacto', DB::raw('COUNT(m.id) as total_mensajes'),
                     DB::raw('MIN(m.created_at) as primer_mensaje'), DB::raw('MAX(m.created_at) as ultimo_mensaje'))
            ->groupBy('c.id', 'c.nombre_contacto')
            ->orderBy('c.id')
            ->get();

        $cantidad = $conversaciones->count();
        $calculo  = BrynexTramoTarifa::calcularCobro(
            moduloId: 4,
            cantidad: $cantidad,
            alidoId:  $alidoId,
            fecha:    $ultimoDia->toDateString()
        );

        return array_merge($calculo, [
            'modulo_id'     => 4,
            'modulo_codigo' => 'wa_conversaciones',
            'modulo_nombre' => 'WhatsApp — Conversaciones',
            'descripcion'   => "Conversaciones activas en {$this->nombreMes($mes)} {$anio}",
            'contratos'     => $conversaciones,
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // MÓDULO 9 — Marketing (mensajes de campañas enviados)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Calcula el consumo del módulo Marketing: se cobra por mensaje enviado en campañas
     * (no por token de IA, ese consumo se mide y cobra aparte en el módulo ia_asistente).
     * Cuenta el detalle de envío masivo que sí llegó a salir (enviado/entregado/leído),
     * ligado a una campaña (campana_id NOT NULL).
     */
    public function calcularMarketing(int $alidoId, int $mes, int $anio): array
    {
        $primerDia = Carbon::create($anio, $mes, 1)->startOfDay();
        $ultimoDia = $primerDia->copy()->endOfMonth();

        $mensajes = DB::table('whatsapp_envios_masivos_detalle as d')
            ->join('whatsapp_envios_masivos as e', 'e.id', '=', 'd.envio_id')
            ->join('marketing_campanas as c', 'c.id', '=', 'e.campana_id')
            ->where('e.aliado_id', $alidoId)
            ->whereNotNull('e.campana_id')
            ->whereIn('d.estado', ['enviado', 'entregado', 'leido'])
            ->whereBetween('d.created_at', [$primerDia, $ultimoDia])
            ->select('d.id', 'd.wa_numero', 'd.estado', 'd.created_at', 'c.nombre as campana_nombre')
            ->orderBy('d.created_at')
            ->get();

        $cantidad = $mensajes->count();
        $calculo  = BrynexTramoTarifa::calcularCobro(
            moduloId: 9,
            cantidad: $cantidad,
            alidoId:  $alidoId,
            fecha:    $ultimoDia->toDateString()
        );

        return array_merge($calculo, [
            'modulo_id'     => 9,
            'modulo_codigo' => 'marketing',
            'modulo_nombre' => 'Marketing',
            'descripcion'   => "Mensajes de campañas de marketing enviados en {$this->nombreMes($mes)} {$anio}",
            'contratos'     => $mensajes,
        ]);
    }

    // ════════════════════════════════════════════════════════════════════════
    // RESUMEN GENERAL DEL ALIADO (orquestador)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Genera el resumen completo de consumo de un aliado para un mes dado.
     * Solo calcula los módulos que el aliado tiene activos en brynex_modulos_aliado.
     *
     * @return array{
     *   aliado: Aliado,
     *   mes: int,
     *   anio: int,
     *   periodo: string,
     *   modulos: array,
     *   total: float,
     *   cobro_cerrado: BrynexCobroAliado|null
     * }
     */
    public function resumenAliado(int $alidoId, int $mes, int $anio): array
    {
        $aliado = Aliado::with('modulosBrynex')->findOrFail($alidoId);

        // Módulos activos del aliado
        $modulosActivos = $aliado->modulosBrynex
            ->where('activo', true)
            ->pluck('modulo_id')
            ->toArray();

        $resultados = [];
        $total      = 0.0;

        // Módulo 1 — Administración (siempre incluido para todos los aliados)
        if (in_array(1, $modulosActivos) || $this->tieneModuloAdmin($alidoId)) {
            $r = $this->calcularAdministracion($alidoId, $mes, $anio);
            $resultados[] = $r;
            $total += $r['subtotal'];
        }

        // Módulo 2 — Afiliaciones
        if (in_array(2, $modulosActivos)) {
            $r = $this->calcularAfiliaciones($alidoId, $mes, $anio);
            $resultados[] = $r;
            $total += $r['subtotal'];
        }

        // Módulo 3 — WA Plantillas
        if (in_array(3, $modulosActivos)) {
            $r = $this->calcularWaPlantillas($alidoId, $mes, $anio);
            $resultados[] = $r;
            $total += $r['subtotal'];
        }

        // Módulo 4 — WA Conversaciones
        if (in_array(4, $modulosActivos)) {
            $r = $this->calcularWaConversaciones($alidoId, $mes, $anio);
            $resultados[] = $r;
            $total += $r['subtotal'];
        }

        // Módulo 9 — Marketing
        if (in_array(9, $modulosActivos)) {
            $r = $this->calcularMarketing($alidoId, $mes, $anio);
            $resultados[] = $r;
            $total += $r['subtotal'];
        }

        // ¿Ya existe un cobro cerrado para este período?
        $cobroCerrado = BrynexCobroAliado::with(['detalles.modulo', 'pagos.usuario'])
            ->where('aliado_id', $alidoId)
            ->where('mes', $mes)
            ->where('anio', $anio)
            ->first();

        return [
            'aliado'         => $aliado,
            'mes'            => $mes,
            'anio'           => $anio,
            'periodo'        => $this->nombreMes($mes) . ' ' . $anio,
            'modulos'        => $resultados,
            'total'          => $total,
            'cobro_cerrado'  => $cobroCerrado,
        ];
    }

    // ════════════════════════════════════════════════════════════════════════
    // CIERRE DE COBRO (congela el snapshot)
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Cierra el cobro del mes para un aliado:
     *  1. Calcula el resumen actual.
     *  2. Crea (o actualiza si ya existe en borrador) el registro en brynex_cobros_aliados.
     *  3. Crea las líneas de detalle por módulo.
     *
     * @throws \Exception Si ya existe un cobro cerrado y pagado para ese período.
     */
    public function cerrarCobro(int $alidoId, int $mes, int $anio): BrynexCobroAliado
    {
        // Verificar que no haya cobro ya pagado
        $existente = BrynexCobroAliado::where('aliado_id', $alidoId)
            ->where('mes', $mes)
            ->where('anio', $anio)
            ->first();

        if ($existente && $existente->estado === BrynexCobroAliado::ESTADO_PAGADO) {
            throw new \Exception("El cobro de {$this->nombreMes($mes)} {$anio} ya está marcado como pagado y no puede modificarse.");
        }

        $resumen = $this->resumenAliado($alidoId, $mes, $anio);

        return DB::transaction(function () use ($existente, $alidoId, $mes, $anio, $resumen) {
            // Crear o reemplazar el cobro
            if ($existente) {
                $existente->detalles()->delete();
                $cobro = $existente;
                $cobro->update([
                    'total_cobrado' => $resumen['total'],
                    'fecha_cierre'  => now(),
                    'estado'        => BrynexCobroAliado::ESTADO_PENDIENTE,
                ]);
            } else {
                $cobro = BrynexCobroAliado::create([
                    'aliado_id'     => $alidoId,
                    'mes'           => $mes,
                    'anio'          => $anio,
                    'total_cobrado' => $resumen['total'],
                    'total_pagado'  => 0,
                    'estado'        => BrynexCobroAliado::ESTADO_PENDIENTE,
                    'fecha_cierre'  => now(),
                ]);
            }

            // Crear líneas de detalle por módulo
            foreach ($resumen['modulos'] as $modulo) {
                BrynexCobroDetalle::create([
                    'cobro_id'              => $cobro->id,
                    'modulo_id'             => $modulo['modulo_id'],
                    'descripcion'           => $modulo['descripcion'],
                    'cant_unidades'         => $modulo['cant_unidades'],
                    'tarifa_unidad'         => $modulo['tarifa_unidad'],
                    'tarifa_minima_aplicada'=> $modulo['tarifa_minima'],
                    'subtotal'              => $modulo['subtotal'],
                ]);
            }

            return $cobro->fresh(['detalles.modulo', 'pagos']);
        });
    }

    // ════════════════════════════════════════════════════════════════════════
    // DISTRIBUCIÓN DE PAGOS MULTI-MES
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Registra un pago y lo distribuye cronológicamente entre los cobros pendientes del aliado.
     *
     * Lógica:
     *  1. Obtener cobros pendientes/parciales del aliado ordenados por mes/año ASC.
     *  2. Distribuir el valor del pago: primero completa el más antiguo, con el remanente
     *     abona al siguiente, y así hasta agotar el valor del pago.
     *  3. Cada tramo del pago se registra como BrynexPagoAliado vinculado al cobro correspondiente.
     *  4. Recalcular el estado de cada cobro afectado.
     *
     * @return array{pagos_registrados: int, cobros_afectados: array}
     */
    public function distribuirPago(
        int    $alidoId,
        float  $valorTotal,
        string $fechaPago,
        string $banco,
        string $formaPago,
        ?string $soporteUrl,
        ?string $observacion,
        int    $usuarioId
    ): array {
        $cobrosPendientes = BrynexCobroAliado::where('aliado_id', $alidoId)
            ->whereIn('estado', [BrynexCobroAliado::ESTADO_PENDIENTE, BrynexCobroAliado::ESTADO_PARCIAL])
            ->orderBy('anio')
            ->orderBy('mes')
            ->get();

        if ($cobrosPendientes->isEmpty()) {
            throw new \Exception('Este aliado no tiene cobros pendientes de pago.');
        }

        $remanente     = $valorTotal;
        $pagosCreados  = 0;
        $cobrosAfectados = [];

        DB::transaction(function () use (
            $cobrosPendientes, &$remanente, &$pagosCreados, &$cobrosAfectados,
            $fechaPago, $banco, $formaPago, $soporteUrl, $observacion, $usuarioId
        ) {
            foreach ($cobrosPendientes as $cobro) {
                if ($remanente <= 0) break;

                $saldo       = $cobro->saldo_pendiente;
                $aplicar     = min($remanente, $saldo);

                BrynexPagoAliado::create([
                    'cobro_id'    => $cobro->id,
                    'valor'       => $aplicar,
                    'fecha_pago'  => $fechaPago,
                    'banco'       => $banco,
                    'forma_pago'  => $formaPago,
                    'soporte_url' => $soporteUrl,
                    'observacion' => $observacion,
                    'usuario_id'  => $usuarioId,
                ]);

                $cobro->recalcularEstado();
                $remanente -= $aplicar;
                $pagosCreados++;
                $cobrosAfectados[] = [
                    'cobro_id' => $cobro->id,
                    'periodo'  => $cobro->periodo,
                    'aplicado' => $aplicar,
                    'estado'   => $cobro->fresh()->estado,
                ];
            }
        });

        return [
            'pagos_registrados' => $pagosCreados,
            'cobros_afectados'  => $cobrosAfectados,
            'remanente'         => $remanente, // si queda saldo positivo, no había suficientes cobros
        ];
    }

    // ════════════════════════════════════════════════════════════════════════
    // HELPERS PRIVADOS
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Determina si el aliado tiene el módulo de administración activo.
     * (Si no tiene registros en brynex_modulos_aliado, el admin aplica a todos por defecto)
     */
    private function tieneModuloAdmin(int $alidoId): bool
    {
        $tieneRegistros = BrynexModuloAliado::where('aliado_id', $alidoId)->exists();
        if (!$tieneRegistros) return true; // sin configuración → admin aplica a todos
        return BrynexModuloAliado::where('aliado_id', $alidoId)
            ->where('modulo_id', 1)
            ->where('activo', true)
            ->exists();
    }

    private function nombreMes(int $mes): string
    {
        return [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
        ][$mes] ?? "Mes {$mes}";
    }
}
