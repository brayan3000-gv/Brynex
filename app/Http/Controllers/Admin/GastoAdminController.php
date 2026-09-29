<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\{Gasto, BancoCuenta, User, Consignacion};
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\{Auth, DB, Storage};

class GastoAdminController extends Controller
{
    private function aliadoId(): int
    {
        return (int) session('aliado_id_activo');
    }

    private function checkAcceso(): void
    {
        if (! Auth::user()->can('gastos.ver')) {
            abort(403, 'No tienes permiso para «Ver gastos (Gastos administrativos)».');
        }
    }

    private function checkSuperAdmin(): void
    {
        if (!Auth::user()->hasRole('superadmin')) {
            abort(403, 'Solo el superadmin puede realizar esta acción.');
        }
    }

    // ── Index ─────────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $this->checkAcceso();
        $aid   = $this->aliadoId();
        $mes   = (int) $request->input('mes',  now()->month);
        $anio  = (int) $request->input('anio', now()->year);
        $tab   = $request->input('tab', 'general');

        // ── Tab: Gastos Generales (todos excepto pago_planilla) ────────
        $generales = DB::table('gastos AS g')
            ->leftJoin('users AS u',         'u.id',  '=', 'g.usuario_id')
            ->leftJoin('banco_cuentas AS bc', 'bc.id', '=', 'g.banco_origen_id')
            ->where('g.aliado_id', $aid)
            ->where('g.tipo', '!=', 'pago_planilla')
            ->whereMonth('g.fecha', $mes)
            ->whereYear('g.fecha',  $anio)
            ->select([
                'g.id', 'g.fecha', 'g.tipo', 'g.descripcion',
                'g.pagado_a', 'g.forma_pago', 'g.valor',
                'g.observacion', 'g.recibo_caja', 'g.imagen_path',
                'g.created_at',
                'u.nombre AS usuario_nombre',
                'bc.banco AS banco_nombre',
                'bc.nombre AS banco_titular',
            ])
            ->orderBy('g.fecha')
            ->orderBy('g.id')
            ->get();

        // ── Tab: Pagos Planilla agrupados por razón social ─────────────
        $planillas = DB::table('gastos AS g')
            ->leftJoin('users AS u',            'u.id',  '=', 'g.usuario_id')
            ->leftJoin('banco_cuentas AS bc',   'bc.id', '=', 'g.banco_origen_id')
            ->leftJoin('planos AS pl',          'pl.numero_planilla', '=', 'g.numero_planilla')
            ->leftJoin('razones_sociales AS rs','rs.id', '=', 'pl.razon_social_id')
            ->where('g.aliado_id', $aid)
            ->where('g.tipo', 'pago_planilla')
            ->whereMonth('g.fecha', $mes)
            ->whereYear('g.fecha',  $anio)
            ->select([
                'g.id', 'g.fecha', 'g.numero_planilla', 'g.descripcion',
                'g.pagado_a', 'g.valor', 'g.imagen_path',
                'g.created_at',
                'g.tipo', 'g.forma_pago', 'g.banco_origen_id',
                'u.nombre AS usuario_nombre',
                'bc.banco AS banco_nombre',
                'bc.nombre AS banco_titular',
                DB::raw('MAX(ISNULL(rs.razon_social, g.pagado_a)) AS razon_social'),
            ])
            ->groupBy(
                'g.id','g.fecha','g.numero_planilla','g.descripcion',
                'g.pagado_a','g.valor','g.imagen_path','g.created_at',
                'g.tipo','g.forma_pago','g.banco_origen_id',
                'u.nombre','bc.banco','bc.nombre'
            )
            ->orderByRaw('MAX(ISNULL(rs.razon_social, g.pagado_a))')
            ->orderBy('g.fecha')
            ->get()
            ->groupBy('razon_social');

        $totalGeneral  = $generales->sum('valor');
        $totalPlanilla = DB::table('gastos')
            ->where('aliado_id', $aid)
            ->where('tipo', 'pago_planilla')
            ->whereMonth('fecha', $mes)
            ->whereYear('fecha',  $anio)
            ->selectRaw('ISNULL(SUM(CAST(valor AS BIGINT)),0) AS total')
            ->value('total');

        $bancos      = BancoCuenta::paraFacturacion($aid);
        $usuarios    = User::where('aliado_id', $aid)->where('activo', true)->orderBy('nombre')->get(['id','nombre']);
        $esSuperAdmin = Auth::user()->hasRole('superadmin');
        $tiposGasto   = collect(Gasto::TIPOS)
                            ->filter(fn($l,$k) => $k !== 'pago_planilla');
        $tiposGrupos  = Gasto::TIPOS_GRUPOS;
        $tiposNomina  = Gasto::TIPOS_NOMINA;

        $mesesNombres = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio',
                         'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

        return view('admin.informes.gastos', compact(
            'mes','anio','tab','generales','planillas',
            'totalGeneral','totalPlanilla',
            'bancos','usuarios','esSuperAdmin','tiposGasto','tiposGrupos','tiposNomina','mesesNombres'
        ));
    }

    // ── Crear gasto ───────────────────────────────────────────────────
    public function store(Request $request)
    {
        $this->checkSuperAdmin();
        $aid = $this->aliadoId();
        $usuarioId = Auth::id();

        $data = $request->validate([
            'fecha'             => 'required|date',
            'tipo'              => 'required|string',
            'descripcion'       => 'required|string|max:500',
            'pagado_a'          => 'nullable|string|max:255',
            'forma_pago'        => 'required|in:efectivo,transferencia_bancaria,banco_banco',
            'banco_origen_id'   => 'nullable|integer',
            'banco_destino_id'  => 'nullable|integer',
            'valor'             => 'required|integer|min:1',
            'recibo_caja'       => 'nullable|string|max:100',
            'observacion'       => 'nullable|string|max:1000',
            'imagen_base64'     => 'nullable|string',   // paste Ctrl+V
            'imagen'            => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        DB::beginTransaction();
        try {
            $gasto = Gasto::create(array_merge($data, [
                'aliado_id'  => $aid,
                'usuario_id' => $usuarioId,
                'cuadre_id'  => null,
            ]));

            // ── Traslado efectivo → banco ────────────────────────────────
            // Registra consignación interna para que el saldo bancario suba.
            if ($data['tipo'] === 'efectivo_banco' && !empty($data['banco_origen_id'])) {
                Consignacion::create([
                    'aliado_id'       => $aid,
                    'banco_cuenta_id' => $data['banco_origen_id'],
                    'factura_id'      => null,
                    'fecha'           => $data['fecha'],
                    'valor'           => $data['valor'],
                    'tipo'            => Consignacion::TIPO_TRASLADO_EFECTIVO,
                    'referencia'      => 'Gasto #' . $gasto->id,
                    'confirmado'      => true,
                    'observacion'     => $data['descripcion'],
                    'usuario_id'      => $usuarioId,
                ]);
            }

            // ── Banco → Banco ────────────────────────────────────────────
            // El gasto descuenta el banco origen. Creamos la entrada en el destino.
            if ($data['forma_pago'] === 'banco_banco' && !empty($data['banco_destino_id'])) {
                Consignacion::create([
                    'aliado_id'       => $aid,
                    'banco_cuenta_id' => $data['banco_destino_id'],
                    'factura_id'      => null,
                    'fecha'           => $data['fecha'],
                    'valor'           => $data['valor'],
                    'tipo'            => Consignacion::TIPO_BANCO_RECIBIDO,
                    'referencia'      => 'Gasto #' . $gasto->id,
                    'confirmado'      => true,
                    'observacion'     => $data['descripcion'],
                    'usuario_id'      => $usuarioId,
                ]);
            }

            // Imagen pegada (base64)
            if (!empty($data['imagen_base64'])) {
                $path = $this->guardarBase64($data['imagen_base64'], $gasto->id);
                if ($path) $gasto->update(['imagen_path' => $path]);
            }
            // Imagen subida como archivo
            elseif ($request->hasFile('imagen')) {
                $path = $request->file('imagen')->store("gastos/{$aid}", 'public');
                $gasto->update(['imagen_path' => $path]);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Error al registrar el gasto: ' . $e->getMessage());
        }

        return redirect()->back()->with('success', 'Gasto registrado correctamente.');
    }

    // ── Actualizar gasto ──────────────────────────────────────────────
    public function update(Request $request, int $id)
    {
        $this->checkSuperAdmin();
        $aid   = $this->aliadoId();
        $gasto = Gasto::where('aliado_id', $aid)->findOrFail($id);

        // Planos SS guarda los pagos de planilla con forma_pago='transferencia',
        // que no está entre las opciones del modal: se acepta la que el gasto ya
        // tiene para que corregirle la fecha no obligue a cambiarla.
        $formasPago = array_values(array_unique(array_filter([
            'efectivo', 'transferencia_bancaria', 'banco_banco', $gasto->forma_pago,
        ])));

        $data = $request->validate([
            'fecha'             => 'required|date',
            'tipo'              => 'required|string',
            'descripcion'       => 'required|string|max:500',
            'pagado_a'          => 'nullable|string|max:255',
            'forma_pago'        => ['required', Rule::in($formasPago)],
            'banco_origen_id'   => 'nullable|integer',
            'banco_destino_id'  => 'nullable|integer',
            'valor'             => 'required|integer|min:1',
            'recibo_caja'       => 'nullable|string|max:100',
            'observacion'       => 'nullable|string|max:1000',
            'imagen_base64'     => 'nullable|string',
            'imagen'            => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        $gasto->update($data);

        // Actualizar imagen si se proporcionó
        if (!empty($data['imagen_base64'])) {
            $path = $this->guardarBase64($data['imagen_base64'], $gasto->id);
            if ($path) $gasto->update(['imagen_path' => $path]);
        } elseif ($request->hasFile('imagen')) {
            if ($gasto->imagen_path) Storage::disk('public')->delete($gasto->imagen_path);
            $path = $request->file('imagen')->store("gastos/{$aid}", 'public');
            $gasto->update(['imagen_path' => $path]);
        }

        return redirect()->back()->with('success', 'Gasto actualizado.');
    }

    // ── Impacto de eliminar un pago de planilla ───────────────────────
    // Devuelve los planos que quedarían sin pago si se elimina el gasto,
    // para confirmarlo en un modal antes de borrar.
    public function impactoPlanilla(int $id)
    {
        $this->checkSuperAdmin();
        $aid   = $this->aliadoId();
        $gasto = Gasto::where('aliado_id', $aid)->findOrFail($id);

        if (! $gasto->esPagoPlanilla()) {
            return response()->json([
                'ok'              => true,
                'es_planilla'     => false,
                'numero_planilla' => null,
                'total_registros' => 0,
                'planos'          => [],
            ]);
        }

        $planos = $gasto->planosPagados()
            ->leftJoin('razones_sociales AS rs', 'rs.id', '=', 'p.razon_social_id')
            ->groupBy('p.razon_social_id', 'rs.razon_social', 'p.n_plano', 'p.mes_plano', 'p.anio_plano')
            ->select([
                'p.n_plano', 'p.mes_plano', 'p.anio_plano',
                DB::raw('MAX(rs.razon_social) AS razon_social'),
                DB::raw('COUNT(*) AS registros'),
            ])
            ->orderBy('rs.razon_social')
            ->orderBy('p.anio_plano')
            ->orderBy('p.mes_plano')
            ->orderBy('p.n_plano')
            ->get();

        return response()->json([
            'ok'              => true,
            'es_planilla'     => true,
            'numero_planilla' => trim((string) $gasto->numero_planilla),
            'valor'           => (int) $gasto->valor,
            'total_registros' => (int) $planos->sum('registros'),
            'planos'          => $planos,
        ]);
    }

    // ── Eliminar gasto ────────────────────────────────────────────────
    public function destroy(int $id)
    {
        $this->checkSuperAdmin();
        $aid   = $this->aliadoId();
        $gasto = Gasto::where('aliado_id', $aid)->findOrFail($id);

        $numeroPlanilla = trim((string) $gasto->numero_planilla);
        $esPagoPlanilla = $gasto->esPagoPlanilla();

        DB::transaction(function () use ($aid, $id, $gasto) {
            // Eliminar consignaciones internas generadas por traslados banco a banco
            // que referencian a este gasto (identificadas por la referencia 'Gasto #ID')
            if (in_array($gasto->tipo, ['efectivo_banco', 'banco_banco'])) {
                Consignacion::where('aliado_id', $aid)
                    ->where('referencia', 'Gasto #' . $id)
                    ->delete();
            }

            // Si es un pago de planilla, el modelo libera los planos
            // (les quita el número de planilla) dentro del mismo delete.
            $gasto->delete();
        });

        if ($gasto->imagen_path) {
            Storage::disk('public')->delete($gasto->imagen_path);
        }

        $msg = 'Gasto eliminado.';
        if ($esPagoPlanilla) {
            $msg = "Gasto eliminado. Se quitó la planilla N° {$numeroPlanilla} a {$gasto->planosLiberados} registro(s) "
                 . 'del plano, que quedaron pendientes de pago.';
        }

        return redirect()->back()->with('success', $msg);
    }

    // ── Subir imagen individual ───────────────────────────────────────
    public function imagen(Request $request, int $id)
    {
        $this->checkSuperAdmin();
        $aid   = $this->aliadoId();
        $gasto = Gasto::where('aliado_id', $aid)->findOrFail($id);

        $request->validate(['imagen' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120']);

        if ($gasto->imagen_path) Storage::disk('public')->delete($gasto->imagen_path);
        $path = $request->file('imagen')->store("gastos/{$aid}", 'public');
        $gasto->update(['imagen_path' => $path]);

        return redirect()->back()->with('success', 'Imagen guardada.');
    }

    // ── Helper: guardar imagen base64 ─────────────────────────────────
    private function guardarBase64(string $base64, int $gastoId): ?string
    {
        if (!preg_match('/^data:image\/(\w+);base64,/', $base64, $matches)) {
            return null;
        }
        $ext      = strtolower($matches[1]) === 'jpeg' ? 'jpg' : strtolower($matches[1]);
        $data     = substr($base64, strpos($base64, ',') + 1);
        $decoded  = base64_decode($data);
        if (!$decoded) return null;

        $aid      = $this->aliadoId();
        $filename = "gastos/{$aid}/gasto_{$gastoId}_" . time() . ".{$ext}";
        Storage::disk('public')->put($filename, $decoded);
        return $filename;
    }
}
