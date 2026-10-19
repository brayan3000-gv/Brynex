<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\{Anticipo, Contrato, Empresa, BancoCuenta, Consignacion, Factura};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB};

class AnticipoController extends Controller
{
    /**
     * Estados en los que una factura le reconoce saldo a favor al cliente.
     * Los mismos que mira Factura::saldoClienteMesPrevio: una pre-factura
     * todavía no le debe nada a nadie.
     */
    private const ESTADOS_CON_SALDO = ['pagada', 'prestamo', 'abono'];

    // ── 1. Registrar anticipo ──────────────────────────────────────────
    public function store(Request $request)
    {
        $aliadoId = session('aliado_id_activo');

        $validated = $request->validate([
            'contrato_id'    => 'nullable|integer|exists:contratos,id',
            'empresa_id'     => 'nullable|integer|exists:empresas,id',
            'fecha_pago'     => 'required|date',
            'valor'          => 'required|integer|min:1',
            'forma_pago'     => 'required|in:efectivo,transferencia',
            'origen'         => 'nullable|in:empresa,clientes,individual',
            'banco_cuenta_id'=> 'nullable|integer|exists:banco_cuentas,id',
            'referencia'     => 'nullable|string|max:100',
            'observacion'    => 'nullable|string|max:300',
            'imagen'         => 'nullable|file|mimes:jpg,jpeg,png,pdf,webp|max:8192',
        ]);

        if ($r = $this->confirmarEfectivoGrande($request, $validated, ! empty($validated['empresa_id']))) {
            return $r;
        }

        // Debe venir al menos un vínculo (contrato O empresa)
        if (empty($validated['contrato_id']) && empty($validated['empresa_id'])) {
            return response()->json([
                'ok'     => false,
                'mensaje'=> 'Debe asociar el anticipo a un contrato o una empresa.',
            ], 422);
        }

        // ── Validar referencia duplicada (prevención de doble registro) ──
        if (!empty($validated['referencia'])) {
            $dup = Anticipo::where('aliado_id', $aliadoId)
                ->where('referencia', $validated['referencia'])
                ->where('forma_pago', $validated['forma_pago'])
                ->whereNotIn('estado', [Anticipo::ESTADO_DEVUELTO])
                ->exists();

            if ($dup) {
                return response()->json([
                    'ok'     => false,
                    'alerta' => true,
                    'mensaje'=> "⚠️ Ya existe un anticipo con referencia {$validated['referencia']} ({$validated['forma_pago']}). ¿Estás seguro de que no es el mismo pago?",
                ], 409);
            }
        }

        // ── Obtener cédula del contrato (si aplica) ────────────────────
        $cedula = null;
        if (!empty($validated['contrato_id'])) {
            $contrato = Contrato::where('aliado_id', $aliadoId)
                ->findOrFail($validated['contrato_id']);
            $cedula = $contrato->cedula;
        }

        DB::beginTransaction();
        try {
            $anticipo = Anticipo::create([
                'aliado_id'      => $aliadoId,
                'cedula'         => $cedula,
                'contrato_id'    => $validated['contrato_id'] ?? null,
                'empresa_id'     => $validated['empresa_id']  ?? null,
                'fecha_pago'     => $validated['fecha_pago'],
                'valor'          => $validated['valor'],
                'valor_aplicado' => 0,
                'forma_pago'     => $validated['forma_pago'],
                'banco_cuenta_id'=> $validated['banco_cuenta_id'] ?? null,
                'referencia'     => $validated['referencia'] ?? null,
                'observacion'    => $validated['observacion'] ?? null,
                'estado'         => Anticipo::ESTADO_DISPONIBLE,
                'origen'         => $validated['origen'] ?? 'empresa',
                'usuario_id'     => Auth::id(),
            ]);

            // ── Si es transferencia: crear registro bancario ──
            // Así el saldo bancario refleja este dinero desde el día que llegó.
            if ($validated['forma_pago'] === 'transferencia'
                && !empty($validated['banco_cuenta_id']))
            {
                // Guardar imagen si viene en el request
                $imagenPath = null;
                if ($request->hasFile('imagen') && $request->file('imagen')->isValid()) {
                    $file = $request->file('imagen');
                    $imagenPath = $file->storeAs(
                        'anticipos',
                        'ant_' . $anticipo->id . '_' . time() . '.' . $file->getClientOriginalExtension(),
                        'public'
                    );
                }

                Consignacion::create([
                    'aliado_id'       => $aliadoId,
                    'banco_cuenta_id' => $validated['banco_cuenta_id'],
                    'factura_id'      => null,
                    'anticipo_id'     => $anticipo->id,
                    'fecha'           => $validated['fecha_pago'],
                    'valor'           => $validated['valor'],
                    'tipo'            => Consignacion::TIPO_ANTICIPO,
                    'referencia'      => $validated['referencia'] ?? null,
                    'confirmado'      => false,
                    'observacion'     => $validated['observacion'] ?? 'Anticipo',
                    'usuario_id'      => Auth::id(),
                    'imagen_path'     => $imagenPath,
                ]);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'ok'     => false,
                'mensaje'=> 'Error al registrar el anticipo: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'ok'          => true,
            'mensaje'     => trim('Anticipo registrado correctamente. '
                . (\App\Services\Caja\CierreMes::avisoMesVerificado((int) $aliadoId, $validated['fecha_pago']) ?? '')),
            'anticipo_id' => $anticipo->id,
            'saldo_total' => Anticipo::saldoDisponible(
                $aliadoId,
                $anticipo->contrato_id,
                $anticipo->empresa_id
            ),
        ]);
    }

    // ── 2. API: Anticipos disponibles de un contrato ───────────────────
    public function porContrato(int $contratoId)
    {
        $aliadoId = session('aliado_id_activo');

        $contrato = Contrato::where('aliado_id', $aliadoId)->findOrFail($contratoId);

        $anticipos = Anticipo::disponiblesParaContrato($aliadoId, $contratoId)
            ->map(fn($a) => [
                'id'               => $a->id,
                'fecha_pago'       => $a->fecha_pago->format('d/m/Y'),
                'forma_pago'       => $a->forma_pago,
                'forma_label'      => Anticipo::FORMAS_PAGO[$a->forma_pago] ?? $a->forma_pago,
                'referencia'       => $a->referencia,
                'valor'            => $a->valor,
                'valor_aplicado'   => $a->valor_aplicado,
                'valor_disponible' => $a->valor_disponible,
                'estado'           => $a->estado,
                'descripcion_pago' => $a->descripcion_pago,
            ]);

        return response()->json([
            'anticipos'       => $anticipos,
            'total_disponible'=> $anticipos->sum('valor_disponible'),
        ]);
    }

    // ── 3. API: Anticipos disponibles de una empresa ───────────────────
    public function porEmpresa(int $empresaId)
    {
        $aliadoId = session('aliado_id_activo');

        $empresa = Empresa::where('aliado_id', $aliadoId)->findOrFail($empresaId);

        // 1. Anticipos de la empresa (colectivos, sin contrato asignado y que no estén en estado 'distribuido')
        $anticiposEmpresa = Anticipo::aliado($aliadoId)
            ->porEmpresa($empresaId)
            ->whereNull('contrato_id')
            ->where('estado', '!=', Anticipo::ESTADO_DISTRIBUIDO)
            ->conSaldo()
            ->orderBy('fecha_pago')
            ->get();

        // 2. Anticipos individuales de contratos asociados a clientes de la empresa
        $anticiposIndividuales = Anticipo::porContratoDeEmpresa($aliadoId, $empresaId)
            ->conSaldo()
            ->orderBy('fecha_pago')
            ->get();

        $mapAnticipo = fn($a) => [
            'id'               => $a->id,
            'fecha_pago'       => $a->fecha_pago->format('d/m/Y'),
            'forma_pago'       => $a->forma_pago,
            'forma_label'      => Anticipo::FORMAS_PAGO[$a->forma_pago] ?? $a->forma_pago,
            'referencia'       => $a->referencia,
            'valor'            => $a->valor,
            'valor_aplicado'   => $a->valor_aplicado,
            'valor_disponible' => $a->valor_disponible,
            'descripcion_pago' => $a->descripcion_pago,
            'contrato_id'      => $a->contrato_id,
            'cliente_nombre'   => $a->contrato?->cliente?->nombre_completo ?? '—',
        ];

        $empresarialesMapped = $anticiposEmpresa->map($mapAnticipo);
        $individualesMapped  = $anticiposIndividuales->map($mapAnticipo);

        return response()->json([
            'empresariales'    => $empresarialesMapped,
            'individuales'     => $individualesMapped,
            'total_disponible' => $empresarialesMapped->sum('valor_disponible') + $individualesMapped->sum('valor_disponible'),
        ]);
    }

    // ── 4. Marcar como devuelto ────────────────────────────────────────
    public function devolver(Request $request, int $id)
    {
        $aliadoId = session('aliado_id_activo');

        if (!Auth::user()->hasRole(['admin', 'superadmin'])) {
            return response()->json(['ok' => false, 'mensaje' => 'Sin permiso.'], 403);
        }

        $anticipo = Anticipo::where('aliado_id', $aliadoId)
            ->whereIn('estado', [Anticipo::ESTADO_DISPONIBLE, Anticipo::ESTADO_PARCIAL])
            ->findOrFail($id);

        $anticipo->update([
            'estado'      => Anticipo::ESTADO_DEVUELTO,
            'observacion' => ($anticipo->observacion ? $anticipo->observacion . ' | ' : '')
                           . 'Devuelto el ' . now()->format('d/m/Y')
                           . ($request->motivo ? ': ' . $request->motivo : ''),
        ]);

        return response()->json([
            'ok'     => true,
            'mensaje'=> 'Anticipo marcado como devuelto.',
        ]);
    }

    // ── 5. Anular anticipo (soft delete + observación obligatoria) ─────
    public function anular(Request $request, int $id)
    {
        $aliadoId = session('aliado_id_activo');

        if (!Auth::user()->hasRole(['admin', 'superadmin'])) {
            return response()->json(['ok' => false, 'mensaje' => 'Sin permiso.'], 403);
        }

        $request->validate([
            'motivo_anulacion' => 'required|string|min:5|max:500',
        ]);

        $anticipo = Anticipo::where('aliado_id', $aliadoId)
            ->where('estado', Anticipo::ESTADO_DISPONIBLE)
            ->where('valor_aplicado', 0)
            ->findOrFail($id);

        // Protección extra: no debe tener factura vinculada
        if ($anticipo->factura_id) {
            return response()->json([
                'ok'     => false,
                'mensaje'=> 'Este anticipo está vinculado a una factura y no puede anularse directamente. Anule primero la factura.',
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Guardar datos de anulación ANTES del soft delete
            $anticipo->motivo_anulacion = $request->motivo_anulacion;
            $anticipo->anulado_por      = Auth::id();
            $anticipo->save();

            // Soft delete (pone deleted_at = now())
            $anticipo->delete();

            // Eliminar consignación bancaria asociada (si existe)
            DB::table('consignaciones')->where('anticipo_id', $anticipo->id)->delete();

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'ok'     => false,
                'mensaje'=> 'Error al anular el anticipo: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'ok'     => true,
            'mensaje'=> 'Anticipo anulado correctamente.',
        ]);
    }

    // ── 5b. Eliminar físicamente (solo superadmin, legado) ────────────
    public function destroy(int $id)
    {
        $aliadoId = session('aliado_id_activo');

        if (!Auth::user()->hasRole(['superadmin'])) {
            return response()->json(['ok' => false, 'mensaje' => 'Sin permiso. Use Anular.'], 403);
        }

        $anticipo = Anticipo::withTrashed()
            ->where('aliado_id', $aliadoId)
            ->findOrFail($id);

        // Si tenía consignación bancaria asociada, eliminarla también
        DB::table('consignaciones')
            ->where('anticipo_id', $anticipo->id)
            ->delete();

        $anticipo->forceDelete();

        return response()->json([
            'ok'     => true,
            'mensaje'=> 'Anticipo eliminado físicamente.',
        ]);
    }

    // ── 6. Informe de anticipos ────────────────────────────────────────
    public function informe(Request $request)
    {
        $aliadoId = session('aliado_id_activo');

        if (!Auth::user()->hasRole(['admin', 'superadmin'])) {
            abort(403);
        }

        $desde  = $request->input('desde', now()->startOfMonth()->toDateString());
        $hasta  = $request->input('hasta', now()->toDateString());
        $estado = $request->input('estado'); // null = todos

        // Si se filtra por 'anulado', usar onlyTrashed
        $incluyeAnulados = $estado === 'anulado';

        // Al entrar por el card "Disponible" se listan los anticipos que forman
        // ese saldo — todos los que aun tienen plata sin aplicar, de cualquier
        // fecha — para poder cuadrar la cifra contra la lista renglon por
        // renglon. El rango de fechas no aplica aqui: un saldo no depende de
        // cuando entro el dinero.
        $soloDisponibles = $request->input('ver') === 'disponibles';

        $query = Anticipo::where('aliado_id', $aliadoId)
            ->when(! $soloDisponibles, fn ($q) => $q->whereBetween('fecha_pago', [$desde, $hasta]))
            ->when($soloDisponibles, fn ($q) => $q
                ->whereIn('estado', [Anticipo::ESTADO_DISPONIBLE, Anticipo::ESTADO_PARCIAL])
                ->whereRaw('valor - valor_aplicado > 0'))
            ->with(['contrato.cliente', 'empresa', 'factura', 'usuario'])
            ->orderBy('fecha_pago')
            ->orderBy('id');

        if ($incluyeAnulados) {
            $query->onlyTrashed(); // solo los anulados (soft deleted)
        } elseif ($estado) {
            $query->whereNull('deleted_at')->where('estado', $estado);
        } else {
            $query->whereNull('deleted_at'); // por defecto: excluir anulados
        }

        $anticipos = $query->get();

        // Los maestros ('distribuido') no son plata aparte: son el contenedor del
        // anticipo que la empresa entregó de una vez, y su valor ya está repartido
        // en los hijos. Sumarlos contaba dos veces lo mismo — en Brygar inflaba el
        // disponible de $3.163.100 a $22.579.800, y el recibido igual. Siguen en
        // el listado (son el agrupador que explica de dónde salen los hijos),
        // pero fuera de los totales. Mismo criterio que el informe financiero.
        $paraTotales = $anticipos->whereNull('deleted_at')
            ->where('estado', '!=', Anticipo::ESTADO_DISTRIBUIDO);

        $totales = [
            'recibido'  => $paraTotales->sum('valor'),
            'aplicado'  => $paraTotales->sum('valor_aplicado'),
            'disponible'=> $paraTotales->sum('valor_disponible'),
            'devuelto'  => $paraTotales->where('estado', Anticipo::ESTADO_DEVUELTO)->sum('valor'),
            'anulado'   => $incluyeAnulados ? $anticipos->sum('valor') : 0,
        ];

        // "Disponible" es un saldo, no un movimiento del periodo: cuanta plata
        // hay sin usar HOY no depende de en que fechas entro. Filtrado por rango
        // decia $568.700 cuando el aliado tenia $3.163.100 para aplicar, y la
        // vista de empresa mostraba saldos que aqui no aparecian. Se calcula
        // aparte, sin el rango; el del periodo queda como dato secundario.
        // Nombre de empresa por id, para la columna del listado: el anticipo
        // individual no trae empresa propia, la hereda del cliente
        // (clientes.cod_empresa). Un solo query en vez de uno por fila.
        $empresasPorId = Empresa::where('aliado_id', $aliadoId)->pluck('empresa', 'id');

        $disponibleTotal = (int) Anticipo::where('aliado_id', $aliadoId)
            ->whereNull('deleted_at')
            ->where('estado', '!=', Anticipo::ESTADO_DISTRIBUIDO)
            ->sum(DB::raw('valor - valor_aplicado'));

        return view('admin.anticipos.informe', compact(
            'anticipos', 'totales', 'desde', 'hasta', 'estado', 'disponibleTotal', 'soloDisponibles', 'empresasPorId'
        ));
    }

    /**
     * Saldos a favor por cliente: lo que el sistema le reconoce hoy a cada uno.
     *
     * No es lo mismo que un anticipo. El anticipo es plata que el cliente
     * entregó y está registrada como tal; el saldo a favor sale de facturas
     * donde pagó de más — y buena parte nació de "Otros" que se escribían y no
     * se cobraban, así que hay créditos que no corresponden. Esta pantalla
     * sirve para separarlos: los que son de verdad se dejan, y los que no se
     * marcan como utilizados por el aliado (SaldoAjuste), sin tocar la factura.
     */
    public function saldosFavor(Request $request)
    {
        $aliadoId = session('aliado_id_activo');

        // Los mismos estados que mira Factura::saldoClienteMesPrevio: una
        // pre-factura todavía no le reconoce saldo a nadie.
        $estados = self::ESTADOS_CON_SALDO;

        // Saldo neto por cliente, contando solo lo que el saldo de verdad mide.
        $saldos = DB::table('facturas')
            ->where('aliado_id', $aliadoId)
            ->whereNull('deleted_at')
            ->whereIn('estado', $estados)
            ->groupBy('cedula')
            ->havingRaw('SUM(saldo_proximo) > 0')
            ->selectRaw('cedula, SUM(saldo_proximo) AS neto, COUNT(*) AS facturas')
            ->get();

        $cedulas = $saldos->pluck('cedula');
        $ajustes = \App\Models\SaldoAjuste::mapaPorCedulas($aliadoId, $cedulas);

        // Datos del cliente y su empresa, en dos consultas en vez de una por fila.
        $clientes = DB::table('clientes')
            ->where('aliado_id', $aliadoId)
            ->whereIn('cedula', $cedulas)
            ->get(['cedula', 'primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido', 'cod_empresa'])
            ->keyBy('cedula');

        $empresas = Empresa::where('aliado_id', $aliadoId)->pluck('empresa', 'id');

        // De dónde viene el saldo y si huele a los "Otros" que no se cobraron:
        // esas facturas tienen el campo lleno pero el total no lo incluye.
        $origen = DB::table('facturas')
            ->where('aliado_id', $aliadoId)
            ->whereNull('deleted_at')
            ->whereIn('estado', $estados)
            ->where('saldo_proximo', '>', 0)
            ->whereIn('cedula', $cedulas)
            ->selectRaw("id, cedula, numero_factura, mes, anio, saldo_proximo,
                         CASE WHEN (otros + otros_admon) > 0
                               AND total = total_ss + admon + admin_asesor + seguro + afiliacion + iva + mora
                              THEN 1 ELSE 0 END AS sospechoso")
            ->orderByDesc('saldo_proximo')
            ->get()
            ->groupBy('cedula');

        $filas = $saldos->map(function ($s) use ($ajustes, $clientes, $empresas, $origen) {
            $ajustado = (int) ($ajustes[(string) $s->cedula] ?? 0);
            $disponible = max(0, (int) $s->neto - $ajustado);
            $cli = $clientes[$s->cedula] ?? null;
            $empId = $cli->cod_empresa ?? null;
            $facturas = $origen[$s->cedula] ?? collect();

            return (object) [
                'cedula' => $s->cedula,
                'nombre' => $cli
                    ? trim("{$cli->primer_nombre} {$cli->segundo_nombre} {$cli->primer_apellido} {$cli->segundo_apellido}")
                    : '—',
                'empresa' => ($empId && (int) $empId !== 1) ? ($empresas[$empId] ?? null) : null,
                'neto' => (int) $s->neto,
                'ajustado' => $ajustado,
                'disponible' => $disponible,
                'sospechoso' => $facturas->contains(fn ($f) => (int) $f->sospechoso === 1),
                'facturas' => $facturas->take(4),
            ];
        })
        ->filter(fn ($f) => $f->disponible > 0)
        ->sortByDesc('disponible')
        ->values();

        $totales = [
            'clientes' => $filas->count(),
            'disponible' => $filas->sum('disponible'),
            'sospechoso' => $filas->where('sospechoso', true)->sum('disponible'),
            'ajustado' => \App\Models\SaldoAjuste::where('aliado_id', $aliadoId)->sum('valor'),
        ];

        // Lo que ya se dio por consumido, para poder devolverlo si fue un error.
        // Sus clientes no salen en la lista de arriba —ya no tienen saldo—, así
        // que los nombres se buscan aparte.
        $ajustesHechos = \App\Models\SaldoAjuste::where('aliado_id', $aliadoId)
            ->with('usuario:id,nombre')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $nombresAjustados = DB::table('clientes')
            ->where('aliado_id', $aliadoId)
            ->whereIn('cedula', $ajustesHechos->pluck('cedula')->unique())
            ->selectRaw("cedula, LTRIM(RTRIM(primer_nombre + ' ' + primer_apellido)) AS nombre")
            ->pluck('nombre', 'cedula');

        $ajustesHechos->each(function ($a) use ($nombresAjustados) {
            $a->nombre_cliente = $nombresAjustados[$a->cedula] ?? null;
        });

        return view('admin.anticipos.saldos', compact('filas', 'totales', 'ajustesHechos'));
    }

    /**
     * Marca como utilizado por el aliado el saldo de uno o varios clientes.
     * No toca ninguna factura: deja el ajuste, y el saldo se calcula restándolo.
     */
    public function ajustarSaldos(Request $request)
    {
        $aliadoId = session('aliado_id_activo');

        $validated = $request->validate([
            'cedulas' => 'required|array|min:1',
            'cedulas.*' => 'required|string|max:20',
            'motivo' => 'nullable|string|max:255',
        ]);

        $motivo = trim($validated['motivo'] ?? '') ?: \App\Models\SaldoAjuste::MOTIVO_ALIADO;
        $hechos = 0;
        $total = 0;

        DB::transaction(function () use ($aliadoId, $validated, $motivo, &$hechos, &$total) {
            foreach (array_unique($validated['cedulas']) as $cedula) {
                $neto = (int) DB::table('facturas')
                    ->where('aliado_id', $aliadoId)->where('cedula', $cedula)
                    ->whereNull('deleted_at')->whereIn('estado', self::ESTADOS_CON_SALDO)
                    ->sum('saldo_proximo');

                $disponible = max(0, $neto - \App\Models\SaldoAjuste::totalDe($aliadoId, $cedula));
                if ($disponible <= 0) {
                    continue;   // ya no tiene crédito: nada que ajustar
                }

                $facturas = DB::table('facturas')
                    ->where('aliado_id', $aliadoId)->where('cedula', $cedula)
                    ->whereNull('deleted_at')->whereIn('estado', self::ESTADOS_CON_SALDO)
                    ->where('saldo_proximo', '>', 0)
                    ->get(['id', 'numero_factura', 'mes', 'anio', 'saldo_proximo']);

                $ajuste = \App\Models\SaldoAjuste::create([
                    'aliado_id' => $aliadoId,
                    'cedula' => (string) $cedula,
                    'valor' => $disponible,
                    'motivo' => $motivo,
                    'usuario_id' => Auth::id(),
                    'detalle' => $facturas->map(fn ($f) => [
                        'factura_id' => $f->id,
                        'numero_factura' => $f->numero_factura,
                        'periodo' => "{$f->mes}/{$f->anio}",
                        'saldo' => (int) $f->saldo_proximo,
                    ])->all(),
                ]);

                \App\Models\Bitacora::registrar(
                    'ajustar', 'SaldoAjuste', $ajuste->id,
                    "Saldo a favor de la c.c. {$cedula} por $"
                        .number_format($disponible, 0, ',', '.')." dado por consumido: {$motivo}",
                    ['cedula' => (string) $cedula, 'valor' => $disponible, 'motivo' => $motivo],
                    $aliadoId
                );

                $hechos++;
                $total += $disponible;
            }
        });

        return response()->json([
            'ok' => true,
            'mensaje' => $hechos > 0
                ? "Se marcaron {$hechos} saldo(s) por $".number_format($total, 0, ',', '.').'.'
                : 'No había saldo disponible para marcar.',
            'clientes' => $hechos,
            'total' => $total,
        ]);
    }

    /** Deshace un ajuste: el cliente recupera ese saldo a favor. */
    public function deshacerAjuste(int $id)
    {
        $aliadoId = session('aliado_id_activo');

        $ajuste = \App\Models\SaldoAjuste::where('aliado_id', $aliadoId)->findOrFail($id);
        $ajuste->delete();

        \App\Models\Bitacora::registrar(
            'deshacer', 'SaldoAjuste', $ajuste->id,
            "Ajuste deshecho: la c.c. {$ajuste->cedula} recupera $"
                .number_format($ajuste->valor, 0, ',', '.').' de saldo a favor',
            ['cedula' => $ajuste->cedula, 'valor' => $ajuste->valor],
            $aliadoId
        );

        return response()->json([
            'ok' => true,
            'mensaje' => 'Ajuste deshecho: el cliente vuelve a tener ese saldo a favor.',
        ]);
    }

    // ── 7. Registrar anticipo distribuido desde empresa ────────────────
    /** Desde este valor, un anticipo de empresa en efectivo pide confirmar que fueron billetes. */
    private const UMBRAL_EFECTIVO_EMPRESA = 1000000;

    /**
     * Un anticipo de empresa grande en efectivo pide confirmación.
     *
     * En agosto de 2026 los anticipos de SAMASU se registraron en efectivo y
     * el extracto mostró que llegaron por transferencia a BRYGAR SAS: la caja
     * quedó con plata que nadie tenía y el banco sin la entrada. Lo decidió el
     * dueño el 19-oct-2026. La pantalla repite el envío con confirmo_efectivo=1.
     */
    private function confirmarEfectivoGrande(Request $request, array $validated, bool $esEmpresa)
    {
        if (! $esEmpresa || $validated['forma_pago'] !== 'efectivo'
            || (int) $validated['valor'] < self::UMBRAL_EFECTIVO_EMPRESA || $request->boolean('confirmo_efectivo')) {
            return null;
        }

        return response()->json([
            'ok' => false,
            'confirmar_efectivo' => true,
            'mensaje' => '¿Seguro que este anticipo de $' . number_format((int) $validated['valor'], 0, ',', '.')
                . ' llegó en EFECTIVO (billetes)? Si la empresa lo transfirió, cámbialo a transferencia y elige la cuenta: '
                . 'si no, la caja queda con plata que nadie tiene y el banco sin la entrada.',
        ], 409);
    }

    public function storeDistribuido(Request $request)
    {
        $aliadoId = session('aliado_id_activo');

        $validated = $request->validate([
            'empresa_id'               => 'required|integer|exists:empresas,id',
            'fecha_pago'               => 'required|date',
            'valor'                    => 'required|integer|min:1',
            'forma_pago'               => 'required|in:efectivo,transferencia',
            'origen'                   => 'nullable|in:empresa,clientes',
            'banco_cuenta_id'          => 'nullable|integer|exists:banco_cuentas,id',
            'referencia'               => 'nullable|string|max:100',
            'observacion'              => 'nullable|string|max:300',
            'imagen'                   => 'nullable|file|mimes:jpg,jpeg,png,pdf,webp|max:8192',
            'distribucion'             => 'required|array|min:1',
            'distribucion.*.contrato_id'=> 'required|integer|exists:contratos,id',
            'distribucion.*.valor'     => 'required|integer|min:1',
            'distribucion.*.periodo_mes'=> 'nullable|integer|min:1|max:12',
            'distribucion.*.periodo_anio'=> 'nullable|integer|min:2020|max:2100',
        ]);

        if ($r = $this->confirmarEfectivoGrande($request, $validated, true)) {
            return $r;
        }

        // Validar que forma de pago transferencia venga con banco_cuenta_id
        if ($validated['forma_pago'] === 'transferencia' && empty($validated['banco_cuenta_id'])) {
            return response()->json([
                'ok'      => false,
                'mensaje' => 'Debe seleccionar una cuenta bancaria para pagos por transferencia.'
            ], 422);
        }

        // Validar que la suma de la distribución sea igual al valor total
        $sumaDistribucion = collect($validated['distribucion'])->sum('valor');
        if ((int)$sumaDistribucion !== (int)$validated['valor']) {
            return response()->json([
                'ok'      => false,
                'mensaje' => 'La suma de los valores distribuidos (' . number_format($sumaDistribucion) . ') no coincide con el valor total del anticipo (' . number_format($validated['valor']) . ').'
            ], 422);
        }

        // Validar referencia duplicada si se provee
        if (!empty($validated['referencia'])) {
            $dup = Anticipo::where('aliado_id', $aliadoId)
                ->where('referencia', $validated['referencia'])
                ->where('forma_pago', $validated['forma_pago'])
                ->whereNotIn('estado', [Anticipo::ESTADO_DEVUELTO])
                ->exists();

            if ($dup) {
                return response()->json([
                    'ok'      => false,
                    'alerta'  => true,
                    'mensaje' => "⚠️ Ya existe un anticipo con referencia {$validated['referencia']} ({$validated['forma_pago']}). ¿Estás seguro de que no es el mismo pago?",
                ], 409);
            }
        }

        DB::beginTransaction();
        try {
            // Guardar imagen si viene
            $imagenPath = null;
            if ($request->hasFile('imagen') && $request->file('imagen')->isValid()) {
                $file = $request->file('imagen');
                $imagenPath = $file->storeAs(
                    'anticipos',
                    'ant_dist_' . time() . '_' . rand(100,999) . '.' . $file->getClientOriginalExtension(),
                    'public'
                );
            }

            // 1. Crear el anticipo maestro
            $maestro = Anticipo::create([
                'aliado_id'       => $aliadoId,
                'empresa_id'      => $validated['empresa_id'],
                'contrato_id'     => null,
                'cedula'          => null,
                'fecha_pago'      => $validated['fecha_pago'],
                'valor'           => $validated['valor'],
                'valor_aplicado'  => 0,
                'forma_pago'      => $validated['forma_pago'],
                'banco_cuenta_id' => $validated['banco_cuenta_id'] ?? null,
                'referencia'      => $validated['referencia'] ?? null,
                'observacion'     => $validated['observacion'] ?? null,
                'estado'          => Anticipo::ESTADO_DISTRIBUIDO, // 'distribuido'
                'origen'          => $validated['origen'] ?? 'empresa',
                'usuario_id'      => Auth::id(),
            ]);

            // 2. Crear la consignación bancaria única vinculada al maestro si es transferencia
            if ($validated['forma_pago'] === 'transferencia' && !empty($validated['banco_cuenta_id'])) {
                Consignacion::create([
                    'aliado_id'       => $aliadoId,
                    'banco_cuenta_id' => $validated['banco_cuenta_id'],
                    'factura_id'      => null,
                    'anticipo_id'     => $maestro->id,
                    'fecha'           => $validated['fecha_pago'],
                    'valor'           => $validated['valor'],
                    'tipo'            => Consignacion::TIPO_ANTICIPO,
                    'referencia'      => $validated['referencia'] ?? null,
                    'confirmado'      => false,
                    'observacion'     => $validated['observacion'] ?? 'Anticipo empresa distribuido',
                    'usuario_id'      => Auth::id(),
                    'imagen_path'     => $imagenPath,
                ]);
            }

            // 3. Crear los anticipos hijos
            foreach ($validated['distribucion'] as $item) {
                $contrato = Contrato::where('aliado_id', $aliadoId)->findOrFail($item['contrato_id']);
                Anticipo::create([
                    'aliado_id'         => $aliadoId,
                    'empresa_id'        => $validated['empresa_id'],
                    'contrato_id'       => $contrato->id,
                    'cedula'            => $contrato->cedula,
                    'fecha_pago'        => $validated['fecha_pago'],
                    'valor'             => $item['valor'],
                    'valor_aplicado'    => 0,
                    'forma_pago'        => $validated['forma_pago'],
                    'banco_cuenta_id'   => $validated['banco_cuenta_id'] ?? null,
                    'referencia'        => $validated['referencia'] ?? null,
                    'observacion'       => $validated['observacion'] ?? null,
                    'estado'            => Anticipo::ESTADO_DISPONIBLE,
                    'origen'            => $validated['origen'] ?? 'empresa',
                    'anticipo_padre_id' => $maestro->id,
                    'periodo_mes'       => $item['periodo_mes'] ?? null,
                    'periodo_anio'      => $item['periodo_anio'] ?? null,
                    'usuario_id'        => Auth::id(),
                ]);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'ok'      => false,
                'mensaje' => 'Error al registrar el anticipo distribuido: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'ok'          => true,
            'mensaje'     => trim('Anticipo distribuido registrado correctamente. '
                . (\App\Services\Caja\CierreMes::avisoMesVerificado((int) $aliadoId, $validated['fecha_pago']) ?? '')),
            'anticipo_id' => $maestro->id,
        ]);
    }

    // ── 8. API: Listar contratos activos/vigentes de una empresa ──────────
    public function contratosEmpresa(int $empresaId)
    {
        $aliadoId = session('aliado_id_activo');

        $empresa = Empresa::where('aliado_id', $aliadoId)->findOrFail($empresaId);

        $cedulas = DB::table('clientes')
            ->where('aliado_id', $aliadoId)
            ->where('cod_empresa', $empresaId)
            ->pluck('cedula');

        $contratos = Contrato::where('aliado_id', $aliadoId)
            ->whereIn('cedula', $cedulas)
            ->whereIn('estado', ['vigente', 'activo', 'retirado'])
            ->with(['cliente', 'plan'])
            ->get();

        $meses = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
        ];

        // Última factura por contrato en UNA sola consulta.
        // Antes se llamaba Anticipo::periodoDestinoInfo() dentro del map, lo que
        // disparaba una consulta por contrato (y con 50+ empleados el modal
        // se pasaba del máximo de ejecución de PHP).
        $ultimaFacturaPorContrato = [];
        foreach (
            DB::table('facturas')
                ->select('contrato_id', 'mes', 'anio')
                ->where('aliado_id', $aliadoId)
                ->whereIn('contrato_id', $contratos->pluck('id')->all())
                ->whereIn('estado', ['pagada', 'pre_factura', 'abono', 'prestamo'])
                ->whereNull('deleted_at')
                ->get() as $f
        ) {
            $cid   = (int) $f->contrato_id;
            $orden = ((int) $f->anio * 100) + (int) $f->mes;
            if (!isset($ultimaFacturaPorContrato[$cid]) || $orden > $ultimaFacturaPorContrato[$cid]['orden']) {
                $ultimaFacturaPorContrato[$cid] = [
                    'orden' => $orden,
                    'mes'   => (int) $f->mes,
                    'anio'  => (int) $f->anio,
                ];
            }
        }

        $contratosMapped = $contratos->map(function ($c) use ($meses, $ultimaFacturaPorContrato) {
            $periodo = $this->periodoDestinoDesdeCache($c, $ultimaFacturaPorContrato);
            return [
                'id'              => $c->id,
                'cedula'          => $c->cedula,
                'estado'          => $c->estado,
                'fecha_ingreso'   => $c->fecha_ingreso?->format('Y-m-d'),
                'cliente_nombre'  => $c->cliente?->nombre_completo ?? '—',
                'plan_nombre'     => $c->plan?->nombre ?? '—',
                'periodo_mes'     => $periodo['mes'],
                'periodo_anio'    => $periodo['anio'],
                'periodo_label'   => ($meses[$periodo['mes']] ?? '') . ' ' . $periodo['anio'],
            ];
        })->sortBy('cliente_nombre')->values();

        return response()->json([
            'ok'        => true,
            'contratos' => $contratosMapped,
        ]);
    }

    /**
     * Mismo criterio que Anticipo::periodoDestinoInfo(), pero resolviendo la
     * última factura desde un arreglo ya cargado: el mes siguiente al de la
     * última factura, o el mes de ingreso si el contrato aún no tiene ninguna.
     */
    private function periodoDestinoDesdeCache(Contrato $contrato, array $ultimaFacturaPorContrato): array
    {
        $ultima = $ultimaFacturaPorContrato[(int) $contrato->id] ?? null;

        if (!$ultima) {
            if ($contrato->fecha_ingreso) {
                $carbon = \Carbon\Carbon::parse($contrato->fecha_ingreso);

                return ['mes' => $carbon->month, 'anio' => $carbon->year];
            }

            return ['mes' => (int) now()->month, 'anio' => (int) now()->year];
        }

        return [
            'mes'  => $ultima['mes'] === 12 ? 1 : $ultima['mes'] + 1,
            'anio' => $ultima['mes'] === 12 ? $ultima['anio'] + 1 : $ultima['anio'],
        ];
    }

    // ── 9. Ver recibo de anticipo ─────────────────────────────────────
    public function reciboAnticipo(int $id)
    {
        $aliadoId = session('aliado_id_activo');
        
        $anticipo = Anticipo::withTrashed() // mostrar recibo aunque esté anulado
            ->where('aliado_id', $aliadoId)
            ->with([
                'hijos.contrato.cliente', 
                'hijos.contrato.plan', 
                'contrato.cliente', 
                'contrato.plan', 
                'contrato.eps', 
                'contrato.pension', 
                'contrato.arl', 
                'contrato.caja', 
                'contrato.tipoModalidad', 
                'empresa', 
                'usuario', 
                'bancoCuenta'
            ])
            ->findOrFail($id);

        return view('admin.facturacion.recibo_anticipo', compact('anticipo'));
    }

    // ── 10. API: Anticipos del cliente (historial) ─────────────────────
    public function porCliente(string $cedula)
    {
        $aliadoId = session('aliado_id_activo');

        $anticipos = Anticipo::withTrashed()
            ->where('aliado_id', $aliadoId)
            ->where('cedula', $cedula)
            // Excluir maestros distribuidos (solo los hijos/individuales del cliente)
            ->where(function ($q) {
                $q->where('estado', '!=', Anticipo::ESTADO_DISTRIBUIDO)
                  ->orWhereNotNull('deleted_at'); // si fue anulado, mostrarlo igual
            })
            ->with(['contrato.plan', 'empresa', 'factura', 'usuario'])
            ->orderByDesc('fecha_pago')
            ->orderByDesc('id')
            ->get()
            ->map(fn($a) => [
                'id'               => $a->id,
                'fecha_pago'       => $a->fecha_pago?->format('d/m/Y'),
                'forma_pago'       => $a->forma_pago,
                'forma_label'      => Anticipo::FORMAS_PAGO[$a->forma_pago] ?? ucfirst($a->forma_pago),
                'valor'            => $a->valor,
                'valor_aplicado'   => $a->valor_aplicado,
                'valor_disponible' => $a->trashed() ? 0 : $a->valor_disponible,
                'estado'           => $a->trashed() ? 'anulado' : $a->estado,
                'estado_label'     => $a->etiqueta_estado,
                'anulado'          => $a->trashed(),
                'motivo_anulacion' => $a->motivo_anulacion,
                'puede_anularse'   => $a->puedeAnularse(),
                'recibo_url'       => route('admin.anticipos.recibo', $a->id),
                'factura_numero'   => $a->factura?->numero_factura,
                'factura_mes'      => $a->factura?->mes,
                'factura_anio'     => $a->factura?->anio,
                'factura_recibo_url' => $a->factura_id ? route('admin.facturacion.recibo', $a->factura_id) : null,
                'empresa_nombre'   => $a->empresa?->empresa,
                'plan_nombre'      => $a->contrato?->plan?->nombre,
                'origen'           => $a->origen ?? 'individual',
            ]);

        return response()->json([
            'ok'       => true,
            'anticipos'=> $anticipos,
            'total'    => $anticipos->count(),
        ]);
    }
}
