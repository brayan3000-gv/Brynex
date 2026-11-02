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
 * Módulos actuales (1 a 4 con la regla del consolidado, ver ConteoMensualAliado):
 *  1 → administracion   : contratos con factura del mes que entraron antes
 *  2 → afiliaciones     : contratos con fecha de ingreso en el mes
 *  3 → wa_plantillas    : mensajes de plantilla aceptados por WhatsApp
 *  4 → wa_conversaciones: chats en los que el cliente escribió en el mes
 *  9 → marketing        : mensajes de campañas
 */
class BrynexConsumoService
{
    // ════════════════════════════════════════════════════════════════════════
    // MÓDULOS 1 a 4 — Administración, afiliaciones y WhatsApp
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Los módulos que se cuentan con las reglas del Resumen Consolidado Mensual
     * (ConteoMensualAliado), para que el aliado vea en su informe lo mismo que
     * se le cobra. [modulo_id => [código, nombre, clave del conteo, descripción]].
     */
    private const MODULOS_CONTEO = [
        1 => ['administracion', 'Administración Mensual', 'admon', 'Contratos con factura del mes que entraron antes del mes'],
        2 => ['afiliaciones', 'Gestión de Afiliaciones', 'afiliaciones', 'Contratos con fecha de ingreso en el mes'],
        3 => ['wa_plantillas', 'WhatsApp — Plantillas', 'wa_plantillas', 'Mensajes de plantilla aceptados por WhatsApp en el mes'],
        4 => ['wa_conversaciones', 'WhatsApp — Conversaciones', 'wa_conversaciones', 'Chats en los que el cliente escribió en el mes'],
    ];

    /** Conteos del mes con la regla del consolidado (ver ConteoMensualAliado). */
    public function conteos(int $alidoId, int $mes, int $anio): array
    {
        $c = app(ConteoMensualAliado::class)->delMes($alidoId, $mes, $anio);

        return [
            'admon'             => $c['admon'],
            'afiliaciones'      => $c['afiliaciones']->count(),
            'clientes_activos'  => $c['total_activos'],
            'wa_plantillas'     => $c['wa_plantillas'],
            'wa_conversaciones' => $c['wa_conversaciones'],
        ];
    }

    /**
     * Línea de cobro de uno de los módulos 1 a 4 a partir de los conteos del mes.
     */
    public function calcularModuloConteo(int $moduloId, array $conteos, int $alidoId, int $mes, int $anio): array
    {
        [$codigo, $nombre, $clave, $descripcion] = self::MODULOS_CONTEO[$moduloId];
        $ultimoDia = Carbon::create($anio, $mes, 1)->endOfMonth();

        $calculo = BrynexTramoTarifa::calcularCobro(
            moduloId: $moduloId,
            cantidad: (int) $conteos[$clave],
            alidoId:  $alidoId,
            fecha:    $ultimoDia->toDateString()
        );

        return array_merge($calculo, [
            'modulo_id'     => $moduloId,
            'modulo_codigo' => $codigo,
            'modulo_nombre' => $nombre,
            'descripcion'   => "{$descripcion} ({$this->nombreMes($mes)} {$anio})",
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
     *   conteos: array,
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

        // Los cuatro conteos se calculan siempre, para mostrarlos aunque el
        // módulo no se cobre; solo los módulos activos entran al total.
        $conteos = $this->conteos($alidoId, $mes, $anio);

        // Administración: si el aliado no tiene módulos configurados, aplica.
        if (in_array(1, $modulosActivos) || $this->tieneModuloAdmin($alidoId)) {
            $r = $this->calcularModuloConteo(1, $conteos, $alidoId, $mes, $anio);
            $resultados[] = $r;
            $total += $r['subtotal'];
        }

        foreach ([2, 3, 4] as $moduloId) {
            if (in_array($moduloId, $modulosActivos)) {
                $r = $this->calcularModuloConteo($moduloId, $conteos, $alidoId, $mes, $anio);
                $resultados[] = $r;
                $total += $r['subtotal'];
            }
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
            'conteos'        => $conteos,
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
