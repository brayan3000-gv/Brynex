<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\{Factura, Abono, BitacoraCobro, Contrato, Empresa, BancoCuenta};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB, Storage};

class PrestamosController extends Controller
{
    // ─── Index: lista de préstamos pendientes ────────────────────────
    public function index(Request $request)
    {
        $aliadoId = session('aliado_id_activo');
        $buscar   = $request->get('buscar');
        $tab      = $request->get('tab', 'individuales'); // individuales | empresas | pagados
        $sort     = $request->get('sort', 'antiguedad');

        // ── Préstamos individuales (empresa_id NULL o empresa_id=1) ──
        $qInd = Factura::where('aliado_id', $aliadoId)
            ->prestamoPendiente()
            ->whereNull('empresa_id')
            ->with(['contrato.cliente', 'contrato.asesor', 'abonos'])
            ->get()
            ->filter(fn($f) => $f->saldo_pendiente_prestamo > 0);

        // ── Préstamos de empresas (empresa_id NOT NULL, excluyendo empresa=1) ──
        // NO filtrar por fila individual (saldo_pendiente_prestamo > 0) aquí:
        // muchas filas tienen abs(saldo_proximo)=0 aunque el LOTE siga debiendo
        // (porque el efectivo se distribuyó a esas filas pero no a otras).
        // El filtro correcto es a nivel de LOTE completo, después de agrupar.
        $qEmp = Factura::where('aliado_id', $aliadoId)
            ->prestamoPendiente()   // estado='prestamo'
            ->whereNotNull('empresa_id')
            ->where('empresa_id', '!=', 1)
            ->with(['empresa', 'abonos'])
            ->get(); // sin filtro por fila — se filtra a nivel de lote abajo

        // ── Búsqueda ──────────────────────────────────────────────────
        if ($buscar) {
            $b = strtolower($buscar);
            $qInd = $qInd->filter(function ($f) use ($b) {
                $nombre = strtolower(
                    ($f->contrato?->cliente?->primer_nombre ?? '') . ' ' .
                    ($f->contrato?->cliente?->primer_apellido ?? '')
                );
                return str_contains((string)$f->cedula, $b) || str_contains($nombre, $b);
            });
            $qEmp = $qEmp->filter(function ($f) use ($b) {
                return str_contains(strtolower($f->empresa?->empresa ?? ''), $b);
            });
        }

        // ── Pre-cargar BitacoraCobro en BATCH (evitar N+1 queries) ───────
        $empresaIds  = $qEmp->pluck('empresa_id')->unique()->filter()->values()->all();
        $facturaIds  = $qInd->pluck('id')->all();

        // Una sola query para todas las gestiones de empresas
        $gestionesPorEmpresa = BitacoraCobro::whereIn('empresa_id', $empresaIds)
            ->where('tipo', BitacoraCobro::TIPO_PRESTAMO)
            ->with('usuario')
            ->orderByDesc('fecha_llamada')
            ->get()
            ->groupBy('empresa_id');

        // Una sola query para todas las gestiones de individuales
        $gestionesPorFactura = BitacoraCobro::whereIn('factura_id', $facturaIds)
            ->where('tipo', BitacoraCobro::TIPO_PRESTAMO)
            ->with('usuario')
            ->orderByDesc('fecha_llamada')
            ->get()
            ->groupBy('factura_id');

        // ── Agrupar empresas por empresa_id ───────────────────────────
        $empresasAgrupadas = $qEmp->groupBy('empresa_id')->map(function ($facturas) use ($gestionesPorEmpresa) {
            $empresa     = $facturas->first()->empresa;
            $totalDeuda  = $facturas->sum('saldo_pendiente_prestamo');
            $totalOrig   = $facturas->sum('total');
            $totalAbonado= $facturas->sum(fn($f) => (int)$f->abonos->sum('valor'));

            // Agrupar por numero_factura para que un lote empresa (mismo numero_factura)
            // cuente como 1 solo préstamo, independientemente de cuántos clientes tenga
            $lotes = $facturas->groupBy('numero_factura')->map(function ($lote) {
                $primera = $lote->first();
                $abonosLote    = (int)$lote->sum(fn($f) => $f->abonos->sum('valor'));
                $valorPrestamo = (int)$lote->sum('valor_prestamo');
                // Saldo a nivel de lote — usa valor_prestamo como fuente de verdad.
                // valor_prestamo = monto explícito del préstamo al facturar ($22K total).
                // Fallback: abs(saldo_proximo) para facturas antiguas sin valor_prestamo.
                // Manda la deuda mas grande de las dos: `valor_prestamo` es lo que
                // se pacto al facturar y a veces quedo por debajo de lo que la
                // factura realmente debia (17 lotes asi en la base). Cuando eso
                // pasa y hay un abono, la resta daba 0 y el lote DESAPARECIA del
                // listado con deuda viva — EL EMBAL escondia $549.200 de julio.
                $deudaLote = max($valorPrestamo, abs((int) $lote->sum('saldo_proximo')));
                $saldoLote = max(0, $deudaLote - $abonosLote);
                return (object)[
                    'numero_factura'  => $primera->numero_factura,
                    'mes'             => $primera->mes,
                    'anio'            => $primera->anio,
                    'facturas'        => $lote,
                    'total'           => $lote->sum('total'),
                    'total_abonado'   => $abonosLote,
                    'saldo_pendiente' => $saldoLote,
                    'factura_id'      => $primera->id,
                ];
            })
            ->filter(fn($l) => $l->saldo_pendiente > 0) // filtrar lotes ya pagados
            ->values();

            // Total deuda empresa = suma de saldos reales de todos sus lotes pendientes
            $totalDeuda = $lotes->sum('saldo_pendiente');

            // Última gestión de cobro de esta empresa (ya pre-cargada)
            $ultimaGestion = $gestionesPorEmpresa->get($empresa?->id)?->first();

            $diasSinGestion = $ultimaGestion
                ? (int)$ultimaGestion->fecha_llamada->diffInDays(now())
                : null;

            return (object)[
                'empresa'         => $empresa,
                'facturas'        => $facturas,   // todos los registros individuales
                'lotes'           => $lotes,       // agrupados por numero_factura
                'total_deuda'     => $totalDeuda,
                'total_original'  => $totalOrig,
                'total_abonado'   => $totalAbonado,
                'ultima_gestion'  => $ultimaGestion,
                'dias_sin_gestion'=> $diasSinGestion,
                'semaforo'        => $this->calcularSemaforo($diasSinGestion),
                'cant_facturas'   => $lotes->count(), // 1 lote = 1 préstamo
            ];
        })->sortByDesc('total_deuda')->values();

        // ── Enriquecer individuales con última gestión y semáforo ─────
        $individuales = $qInd->map(function ($f) use ($gestionesPorFactura) {
            $ultimaGestion = $gestionesPorFactura->get($f->id)?->first();
            $dias = $ultimaGestion
                ? (int)$ultimaGestion->fecha_llamada->diffInDays(now())
                : null;

            $f->ultima_gestion   = $ultimaGestion;
            $f->dias_sin_gestion = $dias;
            $f->semaforo         = $this->calcularSemaforo($dias);
            return $f;
        })->sortByDesc('total')->values();


        // ── Cards resumen ─────────────────────────────────────────────
        $totalDeudaInd  = $individuales->sum('saldo_pendiente_prestamo');
        $totalDeudaEmp  = $empresasAgrupadas->sum('total_deuda');
        $totalPrestamos = $individuales->count() + $empresasAgrupadas->sum('cant_facturas');
        $sinGestion     = $individuales->whereIn('semaforo', ['gris', 'rojo'])->count()
                        + $empresasAgrupadas->whereIn('semaforo', ['gris', 'rojo'])->count();

        // El abono se registra desde este listado, y `abonar()` exige la cuenta
        // cuando el pago entra por el banco: sin las cuentas aquí el modal no
        // podía preguntarla y el guardado rebotaba.
        $bancos = BancoCuenta::paraFacturacion($aliadoId);

        // ── Pestaña "Pagados" ─────────────────────────────────────────
        // El listado completo solo se arma cuando se está mirando esa
        // pestaña; en las otras basta con el conteo para la etiqueta.
        $pagados      = collect();
        $cantPagados  = 0;
        $totalPagado  = 0;

        if ($tab === 'pagados') {
            $pagados     = $this->prestamosPagados($aliadoId, $buscar);
            $cantPagados = $pagados->count();
            $totalPagado = (int) $pagados->sum('valor');
        } else {
            $cantPagados = $this->contarPrestamosPagados($aliadoId);
        }

        return view('admin.prestamos.index', compact(
            'individuales', 'empresasAgrupadas',
            'tab', 'buscar', 'sort',
            'totalDeudaInd', 'totalDeudaEmp', 'totalPrestamos', 'sinGestion',
            'bancos', 'pagados', 'cantPagados', 'totalPagado'
        ));
    }

    // ─── Detalle de un préstamo ──────────────────────────────────────
    public function show(int $facturaId)
    {
        $aliadoId = session('aliado_id_activo');

        $factura = Factura::where('aliado_id', $aliadoId)
            ->with([
                'contrato.cliente',
                'contrato.asesor',
                'empresa',
                'abonos.usuario',
                'usuario',
            ])
            ->findOrFail($facturaId);

        $bancos = BancoCuenta::paraFacturacion($aliadoId);

        // ── Detectar si es un lote empresarial ────────────────────────
        $esLoteEmpresa = $factura->empresa_id && $factura->empresa_id != 1;

        if ($esLoteEmpresa) {
            // Cargar todo el lote: misma factura # + misma empresa
            $lote = Factura::where('aliado_id', $aliadoId)
                ->where('numero_factura', $factura->numero_factura)
                ->where('empresa_id', $factura->empresa_id)
                ->whereNull('deleted_at')
                ->with([
                    'contrato.cliente',
                    'contrato.asesor',
                    'abonos.usuario',
                    'usuario',
                ])
                ->get();

            // Totales del lote completo
            $lote_total          = $lote->sum('total');
            $lote_total_abonado  = $lote->sum(fn($f) => (int)$f->abonos->sum('valor'));

            // Saldo real = valor_prestamo (fuente de verdad explícita) - abonos posteriores.
            // NO usar saldo_proximo: puede ser incorrecto si efectivo se distribuyó mal.
            // Fallback a abs(saldo_proximo) para facturas antiguas sin valor_prestamo.
            $valorPrestamoLote = (int)$lote->sum('valor_prestamo');
            $lote_saldo_pendiente = $valorPrestamoLote > 0
                ? max(0, $valorPrestamoLote - $lote_total_abonado)
                : max(0, abs((int)$lote->sum('saldo_proximo')) - $lote_total_abonado);

            $lote_estado = $lote_saldo_pendiente > 0 ? Factura::ESTADO_PRESTAMO : Factura::ESTADO_PAGADA;

            // Gestiones por empresa_id (no por factura_id individual)
            $gestiones = BitacoraCobro::where('empresa_id', $factura->empresa_id)
                ->where('tipo', BitacoraCobro::TIPO_PRESTAMO)
                ->with('usuario')
                ->orderByDesc('fecha_llamada')
                ->get();

            // Todos los abonos del lote (de todos los registros)
            $lote_abonos = $lote->flatMap(fn($f) => $f->abonos)->sortByDesc('fecha');

            return view('admin.prestamos.show', compact(
                'factura', 'gestiones', 'bancos',
                'esLoteEmpresa', 'lote',
                'lote_total', 'lote_total_abonado', 'lote_saldo_pendiente',
                'lote_estado', 'lote_abonos'
            ));
        }

        // ── Préstamo individual ───────────────────────────────────────
        $gestiones = BitacoraCobro::where('factura_id', $facturaId)
            ->where('tipo', BitacoraCobro::TIPO_PRESTAMO)
            ->with('usuario')
            ->orderByDesc('fecha_llamada')
            ->get();

        $esLoteEmpresa = false;

        return view('admin.prestamos.show', compact('factura', 'gestiones', 'bancos', 'esLoteEmpresa'));
    }

    // ─── Registrar abono al préstamo ─────────────────────────────────
    public function abonar(Request $request, int $facturaId)
    {
        $aliadoId = session('aliado_id_activo');
        $factura  = Factura::where('aliado_id', $aliadoId)
            ->where('estado', Factura::ESTADO_PRESTAMO)
            ->whereNull('deleted_at')
            ->findOrFail($facturaId);

        // El certificado es obligatorio cuando entra plata por el banco:
        // sin él no hay cómo probar la consignación después.
        $exigeSoporte = in_array($request->input('forma_pago'), ['consignacion', 'mixto'], true);

        $validated = $request->validate([
            'valor'            => 'required|numeric|min:1',
            'forma_pago'       => 'required|in:efectivo,consignacion,mixto',
            'valor_efectivo'   => 'nullable|numeric|min:0',
            'valor_consignado' => 'nullable|numeric|min:0',
            // La cuenta se exige cuando entra plata por el banco, igual que el
            // certificado. Sin ella no se sabe qué recaudo le toca a cada razón
            // social, y la facturación electrónica —que emite lo que entra a la
            // cuenta de la emisora— no ve el pago del préstamo.
            'banco_cuenta_id'  => ($exigeSoporte ? 'required' : 'nullable')
                                  . '|integer|exists:banco_cuentas,id',
            'observacion'      => 'nullable|string|max:500',
            'soporte'          => ($exigeSoporte ? 'required' : 'nullable') . '|file|mimes:jpeg,jpg,png,pdf|max:10240',
        ], [
            'banco_cuenta_id.required' => 'Dinos a qué cuenta entró la consignación.',
            'soporte.required' => 'Adjunta el certificado de la consignación.',
            'soporte.mimes'    => 'El certificado debe ser una imagen (JPG/PNG) o un PDF.',
            'soporte.max'      => 'El certificado no puede pesar más de 10 MB.',
        ]);

        // El desglose lo manda el formulario, pero lo decide la forma de pago:
        // en efectivo o consignación puros es el valor completo del abono. Sin
        // esto una consignación se guardaba con la plata anotada en efectivo
        // (25 de los 49 abonos que había en agosto de 2026) y el recaudo del
        // banco quedaba en cero.
        $valorAbono = (int) $validated['valor'];
        if ($validated['forma_pago'] === 'efectivo') {
            $validated['valor_efectivo'] = $valorAbono;
            $validated['valor_consignado'] = 0;
        } elseif ($validated['forma_pago'] === 'consignacion') {
            $validated['valor_efectivo'] = 0;
            $validated['valor_consignado'] = $valorAbono;
        } else {
            $efectivo = (int) ($validated['valor_efectivo'] ?? 0);
            $consignado = (int) ($validated['valor_consignado'] ?? 0);
            if ($efectivo + $consignado !== $valorAbono) {
                return response()->json([
                    'ok' => false,
                    'message' => 'En un pago mixto el efectivo y la consignación tienen que sumar el valor del abono.',
                ], 422);
            }
            $validated['valor_efectivo'] = $efectivo;
            $validated['valor_consignado'] = $consignado;
        }

        // La cuenta tiene que ser del aliado en sesión: el id viaja desde el
        // formulario y `exists` solo comprueba que exista en la tabla.
        if (! empty($validated['banco_cuenta_id'])
            && ! BancoCuenta::where('id', $validated['banco_cuenta_id'])->where('aliado_id', $aliadoId)->exists()) {
            return response()->json(['ok' => false, 'message' => 'Esa cuenta no es de este aliado.'], 422);
        }

        // Soporte al disco `local` (storage/app): lleva datos bancarios del cliente
        $soportePath   = null;
        $soporteNombre = null;
        if ($request->hasFile('soporte')) {
            $archivo       = $request->file('soporte');
            $soportePath   = $archivo->store("prestamos/soportes/{$aliadoId}", 'local');
            $soporteNombre = mb_substr($archivo->getClientOriginalName(), 0, 200);
        }

        // Detectar si es un lote de empresa (mismo numero_factura, varios clientes)
        $esLote = $factura->empresa_id && $factura->empresa_id != 1;

        DB::transaction(function () use ($factura, $validated, $esLote, $aliadoId, $soportePath, $soporteNombre) {
            // El abono se registra siempre en la factura de referencia del lote
            Abono::create([
                'factura_id'       => $factura->id,
                'valor'            => (int)$validated['valor'],
                'forma_pago'       => $validated['forma_pago'],
                'valor_efectivo'   => (int)($validated['valor_efectivo']   ?? 0),
                'valor_consignado' => (int)($validated['valor_consignado'] ?? 0),
                'banco_cuenta_id'  => $validated['banco_cuenta_id'] ?? null,
                'observacion'      => $validated['observacion'] ?? null,
                'soporte_path'     => $soportePath,
                'soporte_nombre'   => $soporteNombre,
                'fecha'            => today()->toDateString(),
                'usuario_id'       => Auth::id(),
            ]);

            if ($esLote) {
                // Recargar todo el lote con abonos frescos para calcular saldo real
                $lote = Factura::where('aliado_id', $aliadoId)
                    ->where('numero_factura', $factura->numero_factura)
                    ->where('empresa_id', $factura->empresa_id)
                    ->whereNull('deleted_at')
                    ->with('abonos')
                    ->get();

                // Saldo ORIGINAL del lote: usar valor_prestamo como fuente de verdad
                // o fallback a abs(saldo_proximo)
                $valorPrestamoLote = (int)$lote->sum('valor_prestamo');
                $saldoOriginalLote = $valorPrestamoLote > 0
                    ? $valorPrestamoLote
                    : abs((int)$lote->sum('saldo_proximo'));
                $totalAbonadoLote  = $lote->sum(fn($f) => (int)$f->abonos->sum('valor'));
                $loteCompleto      = $totalAbonadoLote >= $saldoOriginalLote;

                foreach ($lote as $f) {
                    $f->update([
                        'estado'        => $loteCompleto ? Factura::ESTADO_PAGADA : Factura::ESTADO_PRESTAMO,
                        'saldo_proximo' => $loteCompleto ? 0 : $f->saldo_proximo, // conservar si parcial
                    ]);
                }
            } else {
                // Préstamo individual: lógica original
                $factura->refresh();
                if ($factura->estaCompletamentePagada()) {
                    $factura->update([
                        'estado'        => Factura::ESTADO_PAGADA,
                        'saldo_proximo' => 0,
                    ]);
                } else {
                    $factura->update([
                        'saldo_proximo' => -$factura->saldo_pendiente_prestamo,
                    ]);
                }
            }
        });

        $factura->refresh()->load('abonos');

        if ($esLote) {
            $loteActualizado = Factura::where('aliado_id', $aliadoId)
                ->where('numero_factura', $factura->numero_factura)
                ->where('empresa_id', $factura->empresa_id)
                ->whereNull('deleted_at')
                ->with('abonos')
                ->get();

            // Calcular el saldo restante real del lote de forma unificada
            $valorPrestamoLote = (int)$loteActualizado->sum('valor_prestamo');
            $totalAbonadoLote  = $loteActualizado->sum(fn($f) => (int)$f->abonos->sum('valor'));
            $saldoLote = $valorPrestamoLote > 0
                ? max(0, $valorPrestamoLote - $totalAbonadoLote)
                : max(0, abs((int)$loteActualizado->sum('saldo_proximo')) - $totalAbonadoLote);

            $loteCompleto = $saldoLote <= 0;

            return response()->json([
                'ok'             => true,
                'mensaje'        => $loteCompleto
                    ? '✅ Préstamo de empresa saldado completamente.'
                    : '💰 Abono registrado. Saldo pendiente del lote: $' . number_format($saldoLote, 0, ',', '.'),
                'saldo_restante' => $saldoLote,
                'pagado'         => $loteCompleto,
                'estado'         => $loteCompleto ? Factura::ESTADO_PAGADA : Factura::ESTADO_PRESTAMO,
            ]);
        }

        return response()->json([
            'ok'             => true,
            'mensaje'        => $factura->estaCompletamentePagada()
                ? '✅ Préstamo saldado completamente.'
                : '💰 Abono registrado. Saldo pendiente: $' . number_format($factura->saldo_pendiente_prestamo, 0, ',', '.'),
            'saldo_restante' => $factura->saldo_pendiente_prestamo,
            'pagado'         => $factura->estado === Factura::ESTADO_PAGADA,
            'estado'         => $factura->estado,
        ]);
    }

    // ─── Condonar préstamo (solo superadmin) ─────────────────────────
    public function condonar(Request $request, int $facturaId)
    {
        if (!Auth::user()->hasRole('superadmin')) {
            return response()->json(['ok' => false, 'mensaje' => 'Sin permisos.'], 403);
        }

        $aliadoId = session('aliado_id_activo');
        $factura  = Factura::where('aliado_id', $aliadoId)
            ->where('estado', Factura::ESTADO_PRESTAMO)
            ->findOrFail($facturaId);

        $motivo = trim($request->input('motivo', ''));
        if (!$motivo) {
            return response()->json(['ok' => false, 'mensaje' => 'Debe indicar el motivo de la condonación.'], 422);
        }

        $factura->update([
            'estado'        => Factura::ESTADO_PAGADA,
            'saldo_proximo' => 0,
            'observacion'   => ($factura->observacion ? $factura->observacion . ' | ' : '') .
                               'CONDONADO: ' . $motivo . ' — ' . Auth::user()->nombre . ' ' . now()->format('d/m/Y'),
        ]);

        return response()->json([
            'ok'     => true,
            'mensaje'=> 'Préstamo condonado correctamente.',
        ]);
    }

    // ─── Registrar gestión de cobro ──────────────────────────────────
    public function registrarGestion(Request $request, int $facturaId)
    {
        $aliadoId = session('aliado_id_activo');

        $factura = Factura::where('aliado_id', $aliadoId)->findOrFail($facturaId);

        $validated = $request->validate([
            'resultado'   => 'required|in:no_contesta,promesa_pago,pagado,numero_errado,otro',
            'observacion' => 'nullable|string|max:1000',
        ]);

        $gestion = BitacoraCobro::create([
            'aliado_id'    => $aliadoId,
            'contrato_id'  => $factura->contrato_id ?? 0,
            'empresa_id'   => $factura->empresa_id ?? null,
            'factura_id'   => $factura->id,
            'usuario_id'   => Auth::id(),
            'fecha_llamada'=> now(),
            'resultado'    => $validated['resultado'],
            'observacion'  => $validated['observacion'] ?? null,
            'tipo'         => BitacoraCobro::TIPO_PRESTAMO,
        ]);

        return response()->json([
            'ok'         => true,
            'gestion_id' => $gestion->id,
            'resultado'  => $gestion->resultado,
            'etiqueta'   => $gestion->etiqueta_resultado,
            'fecha'      => $gestion->fecha_llamada->format('d/m/Y H:i'),
            'usuario'    => Auth::user()->nombre ?? Auth::user()->name,
            'semaforo'   => 'verde',
            'dias'       => 0,
        ]);
    }

    // ─── Historial de gestiones ──────────────────────────────────────
    public function historialGestiones(int $facturaId)
    {
        $aliadoId = session('aliado_id_activo');
        Factura::where('aliado_id', $aliadoId)->findOrFail($facturaId);

        $gestiones = BitacoraCobro::where('factura_id', $facturaId)
            ->where('tipo', BitacoraCobro::TIPO_PRESTAMO)
            ->with('usuario')
            ->orderByDesc('fecha_llamada')
            ->get()
            ->map(fn($g) => [
                'id'          => $g->id,
                'fecha'       => $g->fecha_llamada->format('d/m/Y H:i'),
                'resultado'   => $g->resultado,
                'etiqueta'    => $g->etiqueta_resultado,
                'observacion' => $g->observacion,
                'usuario'     => $g->usuario?->nombre ?? $g->usuario?->name ?? '—',
                'dias'        => $g->dias,
            ]);

        return response()->json(['ok' => true, 'gestiones' => $gestiones]);
    }

    // ─── Adjuntar el certificado a un abono ya registrado ────────────
    // Para los abonos viejos, que se guardaron antes de que existiera el campo.
    public function adjuntarSoporteAbono(Request $request, int $abonoId)
    {
        $aliadoId = session('aliado_id_activo');

        $abono = Abono::whereHas('factura', fn($q) => $q->where('aliado_id', $aliadoId))
            ->findOrFail($abonoId);

        $request->validate([
            'soporte' => 'required|file|mimes:jpeg,jpg,png,pdf|max:10240',
        ], [
            'soporte.required' => 'Selecciona el certificado de la consignación.',
            'soporte.mimes'    => 'El certificado debe ser una imagen (JPG/PNG) o un PDF.',
            'soporte.max'      => 'El certificado no puede pesar más de 10 MB.',
        ]);

        $anterior = $abono->soporte_path;
        $archivo  = $request->file('soporte');

        $abono->update([
            'soporte_path'   => $archivo->store("prestamos/soportes/{$aliadoId}", 'local'),
            'soporte_nombre' => mb_substr($archivo->getClientOriginalName(), 0, 200),
        ]);

        // Reemplazar deja rastro: el soporte es la prueba del pago
        \App\Models\Bitacora::registrar(
            accion: 'updated',
            modelo: 'Abono',
            registroId: $abono->id,
            descripcion: $anterior
                ? "Certificado del abono #{$abono->id} reemplazado por '{$abono->soporte_nombre}'."
                : "Certificado '{$abono->soporte_nombre}' adjuntado al abono #{$abono->id}.",
            detalle: ['anterior' => $anterior, 'nuevo' => $abono->soporte_path],
            alidoId: $aliadoId
        );

        if ($anterior) {
            Storage::disk('local')->delete($anterior);
        }

        return response()->json([
            'ok'      => true,
            'mensaje' => $anterior ? '📎 Certificado reemplazado.' : '📎 Certificado adjuntado.',
            'url'     => route('admin.prestamos.abono.soporte', $abono->id),
            'nombre'  => $abono->soporte_nombre,
        ]);
    }

    // ─── Descargar el certificado de consignación de un abono ────────
    public function descargarSoporteAbono(Request $request, int $abonoId)
    {
        $aliadoId = session('aliado_id_activo');

        // El abono no tiene aliado_id: se filtra por el de su factura
        $abono = Abono::whereHas('factura', fn($q) => $q->where('aliado_id', $aliadoId))
            ->findOrFail($abonoId);

        abort_if(!$abono->soporte_path || !Storage::disk('local')->exists($abono->soporte_path), 404, 'El abono no tiene certificado adjunto.');

        $nombre = $abono->soporte_nombre ?: basename($abono->soporte_path);

        // Con `ver` se muestra dentro del recibo en vez de descargarse.
        return $request->boolean('ver')
            ? Storage::disk('local')->response($abono->soporte_path, $nombre, [], 'inline')
            : Storage::disk('local')->download($abono->soporte_path, $nombre);
    }

    // ─── API ligera: cédulas con préstamo pendiente ──────────────────
    // Usada por CobrosController para badges (1 query, sin N+1)
    public function apiPendientes()
    {
        $aliadoId = session('aliado_id_activo');

        $pendientes = DB::table('facturas')
            ->where('aliado_id', $aliadoId)
            ->where('estado', 'prestamo')
            ->whereNull('deleted_at')
            ->select('cedula', 'empresa_id', DB::raw('SUM(total) as total_prestado'))
            ->groupBy('cedula', 'empresa_id')
            ->get()
            ->mapWithKeys(fn($r) => [$r->cedula => (int)$r->total_prestado]);

        return response()->json(['ok' => true, 'pendientes' => $pendientes]);
    }

    // ─── Préstamos ya saldados ───────────────────────────────────────
    // Cuando un préstamo se paga, `estado` pasa a 'pagada' y `saldo_proximo`
    // a 0: lo único que queda para reconocer que esa factura nació como
    // préstamo es `valor_prestamo`, la misma fuente de verdad que ya usan los
    // lotes de empresa en el listado de pendientes.

    /** Query base de los préstamos que ya no deben nada. */
    private function queryPagados(int $aliadoId)
    {
        return Factura::where('aliado_id', $aliadoId)
            ->where('estado', Factura::ESTADO_PAGADA)
            ->whereNull('deleted_at')
            ->where('valor_prestamo', '>', 0);
    }

    /** ¿La factura es de un lote de empresa? (empresa_id=1 es el genérico) */
    private function esLoteDeEmpresa($factura): bool
    {
        return $factura->empresa_id && (int) $factura->empresa_id !== 1;
    }

    /** Conteo para la etiqueta de la pestaña: un lote de empresa cuenta como 1. */
    private function contarPrestamosPagados(int $aliadoId): int
    {
        $filas = $this->queryPagados($aliadoId)->get(['id', 'empresa_id', 'numero_factura']);

        return $filas->reject(fn ($f) => $this->esLoteDeEmpresa($f))->count()
             + $filas->filter(fn ($f) => $this->esLoteDeEmpresa($f))
                     ->groupBy(fn ($f) => $f->empresa_id.'-'.$f->numero_factura)
                     ->count();
    }

    /** Listado de préstamos saldados, individuales y lotes de empresa juntos. */
    private function prestamosPagados(int $aliadoId, ?string $buscar)
    {
        $filas = $this->queryPagados($aliadoId)
            ->with(['contrato.cliente', 'contrato.asesor', 'empresa', 'abonos'])
            ->get();

        // ── Individuales: una factura = un préstamo ──
        $individuales = $filas->reject(fn ($f) => $this->esLoteDeEmpresa($f))->map(function ($f) {
            $nombre = trim(
                ($f->contrato?->cliente?->primer_nombre ?? '').' '.
                ($f->contrato?->cliente?->primer_apellido ?? '')
            );

            return (object) [
                'tipo'           => 'individual',
                'nombre'         => $nombre ?: '—',
                'detalle'        => (string) $f->cedula,
                'asesor'         => $f->contrato?->asesor?->nombre,
                'mes'            => $f->mes,
                'anio'           => $f->anio,
                'numero_factura' => $f->numero_factura,
                'valor'          => (int) $f->valor_prestamo,
                'abonado'        => (int) $f->abonos->sum('valor'),
                'fecha_pago'     => $this->fechaSaldado($f->abonos, $f->updated_at),
                'condonado'      => $this->fueCondonado($f),
                'factura_id'     => $f->id,
            ];
        });

        // ── Empresas: un lote (mismo numero_factura) = un préstamo ──
        $empresas = $filas->filter(fn ($f) => $this->esLoteDeEmpresa($f))
            ->groupBy(fn ($f) => $f->empresa_id.'-'.$f->numero_factura)
            ->map(function ($lote) {
                $primera = $lote->first();
                $abonos  = $lote->flatMap->abonos;

                return (object) [
                    'tipo'           => 'empresa',
                    'nombre'         => $primera->empresa?->empresa ?? 'Empresa #'.$primera->empresa_id,
                    'detalle'        => $lote->count().' cliente'.($lote->count() === 1 ? '' : 's'),
                    'asesor'         => null,
                    'mes'            => $primera->mes,
                    'anio'           => $primera->anio,
                    'numero_factura' => $primera->numero_factura,
                    'valor'          => (int) $lote->sum('valor_prestamo'),
                    'abonado'        => (int) $abonos->sum('valor'),
                    'fecha_pago'     => $this->fechaSaldado($abonos, $lote->max('updated_at')),
                    'condonado'      => $lote->contains(fn ($f) => $this->fueCondonado($f)),
                    'factura_id'     => $primera->id,
                ];
            })->values();

        $pagados = $individuales->concat($empresas);

        if ($buscar) {
            $b = mb_strtolower($buscar);
            $pagados = $pagados->filter(fn ($p) =>
                str_contains(mb_strtolower($p->nombre), $b) ||
                str_contains(mb_strtolower($p->detalle), $b) ||
                str_contains((string) $p->numero_factura, $b)
            );
        }

        return $pagados->sortByDesc(fn ($p) => $p->fecha_pago?->timestamp ?? 0)->values();
    }

    /** Cuándo se saldó: el último abono, o la última vez que se tocó la factura. */
    private function fechaSaldado($abonos, $fallback): ?\Illuminate\Support\Carbon
    {
        $fecha = $abonos->max('fecha') ?: $fallback;

        return $fecha ? \Illuminate\Support\Carbon::parse($fecha) : null;
    }

    /** `condonar()` no deja bandera: la huella queda en la observación. */
    private function fueCondonado($factura): bool
    {
        return str_contains(mb_strtoupper((string) $factura->observacion), 'CONDONADO');
    }

    // ─── Helper: calcular semáforo por días sin gestión ──────────────
    private function calcularSemaforo(?int $dias): string
    {
        return match(true) {
            $dias === null  => 'gris',
            $dias < 3       => 'verde',
            $dias <= 7      => 'amarillo',
            default         => 'rojo',
        };
    }
}
