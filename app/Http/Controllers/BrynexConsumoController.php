<?php

namespace App\Http\Controllers;

use App\Models\Aliado;
use App\Models\BrynexCobroAliado;
use App\Models\BrynexCobroDetalle;
use App\Models\BrynexModulo;
use App\Models\BrynexModuloAliado;
use App\Models\BrynexPagoAliado;
use App\Models\BrynexTramoTarifa;
use App\Services\BrynexConsumoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;

class BrynexConsumoController extends Controller
{
    protected BrynexConsumoService $service;

    public function __construct(BrynexConsumoService $service)
    {
        $this->service = $service;
    }

    private function checkSuperadminBrynex(): void
    {
        if (!Auth::user()->hasRole('superadmin') || !Auth::user()->es_brynex) {
            abort(403, 'Acceso restringido a administradores globales de BryNex.');
        }
    }

    /**
     * Dashboard general de consumo de aliados.
     * Muestra el resumen del mes seleccionado (vigente o cerrado) para todos los aliados.
     */
    public function index(Request $request)
    {
        $this->checkSuperadminBrynex();

        $mes  = (int) $request->input('mes', now()->month);
        $anio = (int) $request->input('anio', now()->year);

        // BryNex (aliado 1) no se cobra a sí mismo.
        $aliados = Aliado::activos()
            ->where('id', '<>', 1)
            ->orderBy('nombre')
            ->get();

        $resumenes = [];
        $totalGeneralFacturado = 0.0;
        $totalGeneralPendiente = 0.0;

        foreach ($aliados as $aliado) {
            // Obtener o calcular consumo
            $cobro = BrynexCobroAliado::where('aliado_id', $aliado->id)
                ->where('mes', $mes)
                ->where('anio', $anio)
                ->first();

            if ($cobro) {
                // Cobro cerrado, usar snapshot de base de datos
                $detalles = BrynexCobroDetalle::where('cobro_id', $cobro->id)->get();
                $modulosCalculados = $detalles->map(function ($det) {
                    return [
                        'modulo_id'     => $det->modulo_id,
                        'modulo_codigo' => $det->modulo?->codigo ?? '',
                        'modulo_nombre' => $det->modulo?->nombre ?? '',
                        'cant_unidades' => $det->cant_unidades,
                        'tarifa_unidad' => $det->tarifa_unidad,
                        'subtotal'      => $det->subtotal,
                        'descripcion'   => $det->descripcion,
                    ];
                });

                $total = $cobro->total_cobrado;
                $estado = $cobro->estado;
                $cobroId = $cobro->id;
                // Los clientes activos y los módulos que no se cobran no quedan
                // en el cierre: se cuentan en vivo.
                $conteos = $this->service->conteos($aliado->id, $mes, $anio);
            } else {
                // Cobro abierto, estimar consumo en tiempo real
                $estimacion = $this->service->resumenAliado($aliado->id, $mes, $anio);
                $modulosCalculados = collect($estimacion['modulos']);
                $conteos = $estimacion['conteos'];
                $total = $estimacion['total'];
                $estado = 'abierto';
                $cobroId = null;
            }

            // Calcular saldo pendiente histórico de este aliado (excluyendo el mes actual si está abierto)
            $saldoHistorico = BrynexCobroAliado::where('aliado_id', $aliado->id)
                ->where(function ($q) use ($mes, $anio) {
                    $q->where('anio', '<', $anio)
                      ->orWhere(function ($sq) use ($mes, $anio) {
                          $sq->where('anio', '=', $anio)
                             ->where('mes', '<', $mes);
                      });
                })
                ->get()
                ->sum(fn($c) => $c->saldo_pendiente);

            $resumenes[] = [
                'aliado'          => $aliado,
                'conceptos_cobro' => $this->conceptosCuentaCobro($modulosCalculados, $mes, $anio),
                'cobro_id'        => $cobroId,
                'modulos'         => $modulosCalculados,
                'conteos'         => $conteos,
                'total'           => $total,
                'estado'          => $estado,
                'saldo_historico' => $saldoHistorico,
            ];

            $totalGeneralFacturado += $total;
            $totalGeneralPendiente += $saldoHistorico + ($cobro ? $cobro->saldo_pendiente : 0);
        }

        return view('brynex.consumo.index', compact(
            'resumenes', 'mes', 'anio',
            'totalGeneralFacturado', 'totalGeneralPendiente'
        ));
    }

    /**
     * Muestra el desglose detallado del consumo de un aliado en un mes específico.
     */
    public function show(Aliado $aliado, int $mes, int $anio)
    {
        $this->checkSuperadminBrynex();

        // Obtener resumen del mes (abierto o cerrado)
        $datos = $this->service->resumenAliado($aliado->id, $mes, $anio);

        // Historial de cobros del aliado para cuenta de cobro y visualización
        $historial = BrynexCobroAliado::with('pagos')
            ->where('aliado_id', $aliado->id)
            ->orderByDesc('anio')
            ->orderByDesc('mes')
            ->get();

        return view('brynex.consumo.show', array_merge($datos, [
            'aliado'    => $aliado,
            'historial' => $historial,
            'mes'       => $mes,
            'anio'      => $anio,
        ]));
    }

    /**
     * Cierra el cobro del aliado y congela el consumo.
     */
    public function cerrar(Aliado $aliado, int $mes, int $anio)
    {
        $this->checkSuperadminBrynex();

        try {
            $cobro = $this->service->cerrarCobro($aliado->id, $mes, $anio);

            // Notificación simulada al WhatsApp del aliado
            if (!empty($aliado->whatsapp)) {
                $mensaje = "Hola *{$aliado->nombre}*, BryNex ha generado tu cobro de uso para el período {$cobro->periodo}.\n"
                         . "Total a pagar: *$" . number_format($cobro->total_cobrado, 0, ',', '.') . "*.\n"
                         . "Puedes ver el detalle y descargar tu cuenta de cobro ingresando a tu panel en la sección 'Informe Financiero'.";
                
                Log::channel('single')->info("Notificación WhatsApp Brynex -> Aliado {$aliado->id} ({$aliado->whatsapp}): {$mensaje}");
            }

            return redirect()->route('brynex.consumo.show', [$aliado->id, $mes, $anio])
                ->with('success', "Se ha cerrado y congelado exitosamente el cobro del período {$cobro->periodo}.");

        } catch (\Exception $e) {
            return redirect()->back()
                ->with('warning', "Error al cerrar el cobro: " . $e->getMessage());
        }
    }

    /**
     * Registra un abono o pago y lo distribuye multi-mes de forma cronológica.
     */
    public function registrarPago(Request $request, BrynexCobroAliado $cobro)
    {
        $this->checkSuperadminBrynex();

        $request->validate([
            'valor'       => 'required|numeric|min:1',
            'fecha_pago'  => 'required|date',
            'forma_pago'  => 'required|string|in:transferencia,efectivo,cheque,otro',
            'banco'       => 'nullable|string|max:150',
            'soporte'     => 'nullable|file|mimes:jpg,jpeg,png,pdf,webp|max:8192',
            'observacion' => 'nullable|string|max:500',
        ]);

        $soporteUrl = null;
        if ($request->hasFile('soporte')) {
            $path = $request->file('soporte')->store('soportes_pagos_brynex', 'public');
            $soporteUrl = Storage::disk('public')->url($path);
        }

        try {
            $resultado = $this->service->distribuirPago(
                alidoId:     $cobro->aliado_id,
                valorTotal:  (float) $request->input('valor'),
                fechaPago:   $request->input('fecha_pago'),
                banco:       $request->input('banco') ?? '',
                formaPago:   $request->input('forma_pago'),
                soporteUrl:  $soporteUrl,
                observacion: $request->input('observacion'),
                usuarioId:   Auth::id()
            );

            $mensaje = "Pago registrado exitosamente. Se registraron {$resultado['pagos_registrados']} abonos.";
            if ($resultado['remanente'] > 0) {
                $mensaje .= " Quedó un remanente a favor de $" . number_format($resultado['remanente'], 0, ',', '.') . " porque no hay más cobros pendientes.";
            }

            return redirect()->back()->with('success', $mensaje);

        } catch (\Exception $e) {
            return redirect()->back()->with('warning', "Error al aplicar el pago: " . $e->getMessage());
        }
    }

    /**
     * KPIs Financieros agregados de Brynex (Cobros globales a aliados).
     */
    public function contabilidad(Request $request)
    {
        $this->checkSuperadminBrynex();

        // KPIs Globales
        $totalCobrado = BrynexCobroAliado::sum('total_cobrado');
        $totalRecaudado = BrynexPagoAliado::sum('valor');
        $saldoPendiente = BrynexCobroAliado::all()->sum(fn($c) => $c->saldo_pendiente);

        // Recaudado por Banco
        $porBanco = BrynexPagoAliado::select('banco', DB::raw('SUM(valor) as total'))
            ->groupBy('banco')
            ->orderByDesc('total')
            ->get();

        // Recaudado por Forma de Pago
        $porForma = BrynexPagoAliado::select('forma_pago', DB::raw('SUM(valor) as total'))
            ->groupBy('forma_pago')
            ->orderByDesc('total')
            ->get();

        // Historial mensual agregados
        $historialMensual = BrynexCobroAliado::select(
                'anio', 'mes',
                DB::raw('SUM(total_cobrado) as cobrado'),
                DB::raw('SUM(total_pagado) as pagado')
            )
            ->groupBy('anio', 'mes')
            ->orderBy('anio', 'desc')
            ->orderBy('mes', 'desc')
            ->get()
            ->map(function ($row) {
                $meses = [
                    1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun',
                    7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic'
                ];
                $row->periodo = ($meses[$row->mes] ?? $row->mes) . ' ' . $row->anio;
                $row->pendiente = max(0, $row->cobrado - $row->pagado);
                return $row;
            });

        return view('brynex.consumo.contabilidad', compact(
            'totalCobrado', 'totalRecaudado', 'saldoPendiente',
            'porBanco', 'porForma', 'historialMensual'
        ));
    }

    /**
     * Vista de gestión de módulos contratados y tarifas por tramos del aliado.
     */
    public function modulosAliado(Aliado $aliado)
    {
        $this->checkSuperadminBrynex();

        // Módulos contratados por el aliado
        $modulosContratados = BrynexModuloAliado::where('aliado_id', $aliado->id)
            ->pluck('activo', 'modulo_id')
            ->toArray();

        // Tramos de tarifas específicos del aliado y globales
        $modulos = BrynexModulo::orderBy('orden')->get();

        $tramos = [];
        foreach ($modulos as $mod) {
            $tramosAliado = BrynexTramoTarifa::where('modulo_id', $mod->id)
                ->where('aliado_id', $aliado->id)
                ->orderBy('desde_cant')
                ->get();

            $tramosGlobales = BrynexTramoTarifa::where('modulo_id', $mod->id)
                ->whereNull('aliado_id')
                ->orderBy('desde_cant')
                ->get();

            $tramos[$mod->id] = [
                'personalizados' => $tramosAliado,
                'globales'       => $tramosGlobales,
            ];
        }

        return view('brynex.consumo.modulos', compact('aliado', 'modulos', 'modulosContratados', 'tramos'));
    }

    /**
     * Actualiza la configuración de módulos y tramos de tarifas de un aliado.
     */
    public function actualizarModulos(Request $request, Aliado $aliado)
    {
        $this->checkSuperadminBrynex();

        $modulosActivos = $request->input('modulos', []); // [modulo_id => 1, ...]
        $tramosInput = $request->input('tramos', []); // [modulo_id => [[desde, hasta, tarifa, minima], ...]]

        DB::transaction(function () use ($aliado, $modulosActivos, $tramosInput) {
            // 1. Guardar módulos contratados
            $todosModulos = BrynexModulo::all();
            foreach ($todosModulos as $mod) {
                $activo = isset($modulosActivos[$mod->id]) ? 1 : 0;
                BrynexModuloAliado::updateOrCreate(
                    ['aliado_id' => $aliado->id, 'modulo_id' => $mod->id],
                    ['activo' => $activo]
                );
            }

            // 2. Guardar tramos de tarifa personalizados para el aliado
            foreach ($tramosInput as $moduloId => $tramos) {
                // Los tramos nuevos conservan la vigencia de los que reemplazan: con
                // la fecha de hoy, una tarifa ajustada en noviembre no le aplicaba al
                // cobro de octubre, que se cierra en noviembre. Si el módulo no tenía
                // tramos propios, rigen desde el mes anterior por la misma razón.
                $vigenteDesde = BrynexTramoTarifa::where('modulo_id', $moduloId)
                    ->where('aliado_id', $aliado->id)
                    ->min('vigente_desde')
                    ?? now()->subMonthNoOverflow()->startOfMonth()->toDateString();

                // Eliminar tramos personalizados existentes para este módulo
                BrynexTramoTarifa::where('modulo_id', $moduloId)
                    ->where('aliado_id', $aliado->id)
                    ->delete();

                // Insertar los nuevos tramos
                foreach ($tramos as $tramo) {
                    $desde = (int) $tramo['desde'];
                    $hasta = empty($tramo['hasta']) ? null : (int) $tramo['hasta'];
                    $tarifa = empty($tramo['tarifa']) ? null : (float) $tramo['tarifa'];
                    $minima = empty($tramo['minima']) ? null : (float) $tramo['minima'];

                    if ($desde >= 0) {
                        BrynexTramoTarifa::create([
                            'modulo_id'     => $moduloId,
                            'aliado_id'     => $aliado->id,
                            'desde_cant'    => $desde,
                            'hasta_cant'    => $hasta,
                            'tarifa_unidad' => $tarifa,
                            'tarifa_minima' => $minima,
                            'vigente_desde' => $vigenteDesde,
                        ]);
                    }
                }
            }
        });

        return redirect()->back()->with('success', 'Módulos y tarifas personalizadas actualizados correctamente.');
    }

    /** A quién se le paga la cuenta de cobro. */
    private const CUENTA_COBRO_ACREEDOR = 'BRAYAN HUMBERTO GARCIA VIDAL';
    private const CUENTA_COBRO_CEDULA   = '1.143.944.458';

    /**
     * Los renglones de la cuenta de cobro del mes, a partir de los módulos que
     * se cobran (el cálculo en vivo o el cierre). Solo los que tienen valor, en
     * el orden de la cuenta que se hacía a mano: afiliaciones, pagos, mensajes.
     *
     * @return array<int, array{texto: string, valor: float}>
     */
    private function conceptosCuentaCobro($modulos, int $mes, int $anio): array
    {
        $nombreMes = mb_strtolower(\Carbon\Carbon::create($anio, $mes, 1)->locale('es')->monthName);
        $ultimo    = \Carbon\Carbon::create($anio, $mes, 1)->endOfMonth()->day;
        $periodo   = "en el período del 01 al {$ultimo} de {$nombreMes}";
        $n         = fn ($c) => number_format((float) $c, 0, ',', '.');

        $orden = ['afiliaciones' => 1, 'administracion' => 2, 'wa_plantillas' => 3, 'wa_conversaciones' => 4, 'marketing' => 5];

        return collect($modulos)
            ->filter(fn ($m) => (float) $m['subtotal'] > 0)
            ->sortBy(fn ($m) => $orden[$m['modulo_codigo']] ?? 9)
            ->map(function ($m) use ($periodo, $nombreMes, $n) {
                $cant   = (int) $m['cant_unidades'];
                $tarifa = (float) ($m['tarifa_unidad'] ?? 0);

                $texto = match ($m['modulo_codigo']) {
                    'afiliaciones'      => "{$n($cant)} afiliaciones realizadas {$periodo}",
                    'administracion'    => $tarifa > 0
                        ? "{$n($cant)} pagos realizados {$periodo}"
                        : "Administración de la plataforma BryNex de {$nombreMes}",
                    'wa_plantillas'     => "Mensajes de WhatsApp de {$nombreMes}: {$n($cant)}",
                    'wa_conversaciones' => "Conversaciones de WhatsApp de {$nombreMes}: {$n($cant)}",
                    'marketing'         => "Mensajes de campañas de marketing de {$nombreMes}: {$n($cant)}",
                    default             => ($m['modulo_nombre'] ?: 'Servicio')." de {$nombreMes}",
                };
                if ($tarifa > 0) {
                    $texto .= " c/u \${$n($tarifa)}";
                }

                return ['texto' => $texto, 'valor' => (float) $m['subtotal']];
            })
            ->values()
            ->all();
    }

    /**
     * Cuenta de cobro del mes en PDF, con los renglones que se revisaron en el
     * listado (se pueden corregir o agregar otros, como planes exequiales). No
     * guarda nada: para congelar el cobro está «cerrar».
     */
    public function cuentaCobro(Request $request, Aliado $aliado, int $mes, int $anio)
    {
        $this->checkSuperadminBrynex();

        $datos = $request->validate([
            'fecha'               => 'required|date',
            'conceptos'           => 'required|array|min:1',
            'conceptos.*.texto'   => 'nullable|string|max:300',
            'conceptos.*.valor'   => 'nullable|numeric',
        ]);

        $conceptos = collect($datos['conceptos'])
            ->filter(fn ($c) => trim((string) ($c['texto'] ?? '')) !== '' && (float) ($c['valor'] ?? 0) != 0)
            ->map(fn ($c) => ['texto' => trim($c['texto']), 'valor' => (float) $c['valor']])
            ->values();

        if ($conceptos->isEmpty()) {
            return back()->with('warning', 'La cuenta de cobro necesita al menos un concepto con valor.');
        }

        $total = $conceptos->sum('valor');
        $fecha = \Carbon\Carbon::parse($datos['fecha'])->locale('es');

        $pdf = Pdf::loadView('pdf.brynex_cuenta_cobro', [
            'aliado'      => $aliado,
            'conceptos'   => $conceptos,
            'total'       => $total,
            'totalLetras' => \App\Services\NumeroALetras::convertir($total),
            'fechaTexto'  => $fecha->day.' de '.$fecha->monthName.' del '.$fecha->year,
            'acreedor'    => self::CUENTA_COBRO_ACREEDOR,
            'cedula'      => self::CUENTA_COBRO_CEDULA,
        ])->setPaper('letter');
        app(\App\Services\TrazaArchivoService::class)->marcarPdf($pdf);

        $nombre = 'Cuenta_de_cobro_'.preg_replace('/[^A-Za-z0-9]+/', '_', $aliado->nombre).'_'.$anio.'_'.str_pad($mes, 2, '0', STR_PAD_LEFT).'.pdf';

        return $pdf->download($nombre);
    }

    /**
     * Genera la cuenta de cobro en PDF usando DomPDF.
     */
    public function descargarPdf(BrynexCobroAliado $cobro)
    {
        // El PDF lo puede descargar el superadmin de Brynex o el administrador/usuario de su propio aliado
        $user = Auth::user();
        if (!$user->es_brynex && $user->aliado_id !== $cobro->aliado_id) {
            abort(403, 'No tienes permisos para descargar esta cuenta de cobro.');
        }

        $cobro->load(['aliado', 'detalles.modulo', 'pagos']);

        // Calcular saldo anterior acumulado (antes de este mes)
        $saldoAnterior = BrynexCobroAliado::where('aliado_id', $cobro->aliado_id)
            ->where(function ($q) use ($cobro) {
                $q->where('anio', '<', $cobro->anio)
                  ->orWhere(function ($sq) use ($cobro) {
                      $sq->where('anio', '=', $cobro->anio)
                         ->where('mes', '<', $cobro->mes);
                  });
            })
            ->get()
            ->sum(fn($c) => $c->saldo_pendiente);

        $pdf = Pdf::loadView('pdf.brynex_cobro', compact('cobro', 'saldoAnterior'));
        app(\App\Services\TrazaArchivoService::class)->marcarPdf($pdf);

        $nombreArchivo = 'Cuenta_de_Cobro_Brynex_' . str_replace(' ', '_', $cobro->aliado->nombre) . '_' . $cobro->anio . '_' . str_pad($cobro->mes, 2, '0', STR_PAD_LEFT) . '.pdf';

        return $pdf->download($nombreArchivo);
    }
}
