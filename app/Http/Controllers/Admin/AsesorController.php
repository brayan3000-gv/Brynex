<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Asesor;
use App\Models\ComisionAsesor;
use App\Models\Departamento;
use Carbon\Carbon;
use Illuminate\Http\Request;

class AsesorController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth', 'role:superadmin|admin|usuario']);
    }

    // ─── Listado de asesores del aliado activo ────────────────────────
    public function index()
    {
        $alidoId = session('aliado_id_activo');

        $asesores = Asesor::delAliado($alidoId)
            ->withTrashed()
            ->withCount('comisiones')
            ->orderBy('nombre')
            ->get()
            ->map(function ($a) {
                $a->total_pendiente = $a->totalPendiente();

                return $a;
            });

        return view('admin.asesores.index', compact('asesores'));
    }

    // ─── Formulario crear ─────────────────────────────────────────────
    public function create()
    {
        $departamentos = Departamento::orderBy('nombre')->get();

        return view('admin.asesores.form', [
            'asesor' => new Asesor,
            'departamentos' => $departamentos,
        ]);
    }

    // ─── Guardar nuevo asesor ─────────────────────────────────────────
    public function store(Request $request)
    {
        $alidoId = session('aliado_id_activo');

        $data = $request->validate([
            'cedula' => "required|string|max:20|unique:asesores,cedula,NULL,id,aliado_id,{$alidoId}",
            'nombre' => 'required|string|max:200',
            'telefono' => 'nullable|string|max:50',
            'celular' => 'nullable|string|max:50',
            'correo' => 'nullable|email|max:150',
            'direccion' => 'nullable|string|max:255',
            'ciudad' => 'nullable|string|max:100',
            'departamento' => 'nullable|string|max:100',
            'cuenta_bancaria' => 'nullable|string|max:100',
            'comision_afil_tipo' => 'required|in:fijo,porcentaje',
            'comision_afil_valor' => 'required|numeric|min:0',
            'comision_admon_tipo' => 'required|in:fijo,porcentaje',
            'comision_admon_valor' => 'required|numeric|min:0',
            'tipo_cobro' => 'sometimes|required|in:comision,neta,interno',
            'tarifa_neta' => 'nullable|numeric|min:0',
            'forma_pago' => 'nullable|in:descuenta,quincenal,mensual',
            'fecha_ingreso' => 'nullable|date',
            'activo' => 'boolean',
        ], $this->mensajes());

        $data['aliado_id'] = $alidoId;
        $data['activo'] = $request->boolean('activo', true);

        $asesor = Asesor::create($data);

        return redirect()->route('admin.asesores.show', $asesor)
            ->with('success', "Asesor '{$asesor->nombre}' creado correctamente.");
    }

    // ─── Detalle del asesor con historial de comisiones ───────────────
    public function show(Asesor $asesor)
    {
        $this->autorizarAliado($asesor);

        $comisiones = $asesor->comisiones()
            ->orderByDesc('periodo')
            ->orderBy('tipo')
            ->paginate(20);

        return view('admin.asesores.show', compact('asesor', 'comisiones'));
    }

    // ─── Formulario editar ────────────────────────────────────────────
    public function edit(Asesor $asesor)
    {
        $this->autorizarAliado($asesor);
        $departamentos = Departamento::orderBy('nombre')->get();

        return view('admin.asesores.form', array_merge(
            compact('asesor', 'departamentos'),
            $this->datosNivel($asesor)
        ));
    }

    /**
     * Datos del bloque de nivel: los niveles disponibles, cuál le correspondería por cartera
     * y cuántas celdas propias tiene ya. La sugerencia solo se muestra, nunca se aplica sola.
     */
    private function datosNivel(Asesor $asesor): array
    {
        return [
            'nivelesDisponibles' => \App\Models\AsesorNivel::delAliado((int) $asesor->aliado_id)
                ->activos()->orderBy('orden')->get(),
            'nivelSugerido' => \App\Services\TarifaAsesorService::sugerirNivel($asesor),
            'contratosVigentes' => $asesor->contratosVigentes(),
            'celdasPropias' => \App\Models\AsesorTarifa::where('asesor_id', $asesor->id)->count(),
        ];
    }

    // ─── Actualizar asesor ────────────────────────────────────────────
    public function update(Request $request, Asesor $asesor)
    {
        $this->autorizarAliado($asesor);
        $alidoId = session('aliado_id_activo');

        $data = $request->validate([
            'cedula' => "required|string|max:20|unique:asesores,cedula,{$asesor->id},id,aliado_id,{$alidoId}",
            'nombre' => 'required|string|max:200',
            'telefono' => 'nullable|string|max:50',
            'celular' => 'nullable|string|max:50',
            'correo' => 'nullable|email|max:150',
            'direccion' => 'nullable|string|max:255',
            'ciudad' => 'nullable|string|max:100',
            'departamento' => 'nullable|string|max:100',
            'cuenta_bancaria' => 'nullable|string|max:100',
            'comision_afil_tipo' => 'required|in:fijo,porcentaje',
            'comision_afil_valor' => 'required|numeric|min:0',
            'comision_admon_tipo' => 'required|in:fijo,porcentaje',
            'comision_admon_valor' => 'required|numeric|min:0',
            'tipo_cobro' => 'sometimes|required|in:comision,neta,interno',
            'tarifa_neta' => 'nullable|numeric|min:0',
            'forma_pago' => 'nullable|in:descuenta,quincenal,mensual',
            'fecha_ingreso' => 'nullable|date',
            'activo' => 'boolean',
        ], $this->mensajes());

        $data['activo'] = $request->boolean('activo');
        $asesor->update($data);

        return redirect()->route('admin.asesores.show', $asesor)
            ->with('success', "Asesor '{$asesor->nombre}' actualizado.");
    }

    // ─── Desactivar (soft delete) ─────────────────────────────────────
    public function destroy(Asesor $asesor)
    {
        $this->autorizarAliado($asesor);
        $asesor->delete();

        return redirect()->route('admin.asesores.index')
            ->with('success', "Asesor '{$asesor->nombre}' desactivado.");
    }

    // ─── Restaurar ────────────────────────────────────────────────────
    public function restore(int $id)
    {
        $asesor = Asesor::withTrashed()->findOrFail($id);
        $this->autorizarAliado($asesor);
        $asesor->restore();

        return redirect()->route('admin.asesores.index')
            ->with('success', "Asesor '{$asesor->nombre}' restaurado.");
    }

    // ─── Reporte mensual de comisiones ───────────────────────────────
    public function reporteMensual(Request $request)
    {
        $alidoId = session('aliado_id_activo');
        $anio = (int) $request->get('anio', now()->year);
        $mes = (int) $request->get('mes', now()->month);

        $asesores = Asesor::delAliado($alidoId)
            ->activos()
            ->with(['comisiones' => fn ($q) => $q->delPeriodo($anio, $mes)])
            ->orderBy('nombre')
            ->get();

        // Totales del periodo
        $totalAfiliacion = ComisionAsesor::where('aliado_id', $alidoId)->delPeriodo($anio, $mes)->where('tipo', 'afiliacion')->sum('valor_comision');
        $totalAdmon = ComisionAsesor::where('aliado_id', $alidoId)->delPeriodo($anio, $mes)->where('tipo', 'administracion')->sum('valor_comision');
        $totalPendiente = ComisionAsesor::where('aliado_id', $alidoId)->delPeriodo($anio, $mes)->where('pagado', false)->sum('valor_comision');
        $totalPagado = ComisionAsesor::where('aliado_id', $alidoId)->delPeriodo($anio, $mes)->where('pagado', true)->sum('valor_comision');

        $periodoLabel = Carbon::createFromDate($anio, $mes, 1)->locale('es')->isoFormat('MMMM [de] YYYY');

        return view('admin.asesores.reporte-mensual', compact(
            'asesores', 'anio', 'mes', 'periodoLabel',
            'totalAfiliacion', 'totalAdmon', 'totalPendiente', 'totalPagado'
        ));
    }

    // ─── Cómo van los asesores: cartera del mes, nivel y lo que dejaron ─
    public function comoVan(Request $request, \App\Services\AsesorCarteraService $cartera)
    {
        $alidoId = (int) session('aliado_id_activo');
        $anio = (int) $request->get('anio', now()->year);
        $mes = min(12, max(1, (int) $request->get('mes', now()->month)));

        return view('admin.asesores.como-van', $cartera->comoVan($alidoId, $anio, $mes) + compact('anio', 'mes'));
    }

    // ─── Marcar comisión como pagada ─────────────────────────────────
    public function marcarPagada(Request $request, ComisionAsesor $comision)
    {
        $comision->update([
            'pagado' => true,
            'fecha_pago' => $request->get('fecha_pago', now()->toDateString()),
        ]);

        return back()->with('success', 'Comisión marcada como pagada.');
    }

    // ─── Registrar comisión manual ────────────────────────────────────
    public function registrarComision(Request $request, Asesor $asesor)
    {
        $this->autorizarAliado($asesor);

        $data = $request->validate([
            'contrato_ref' => 'nullable|string|max:50',
            'tipo' => 'required|in:afiliacion,administracion',
            'periodo' => 'required|date',
            'valor_base' => 'required|numeric|min:0',
            'tipo_calculo' => 'required|in:fijo,porcentaje',
            'valor_comision' => 'required|numeric|min:0',
            'observacion' => 'nullable|string|max:255',
        ]);

        ComisionAsesor::create(array_merge($data, [
            'aliado_id' => $asesor->aliado_id,
            'asesor_id' => $asesor->id,
            'pagado' => false,
        ]));

        return back()->with('success', 'Comisión registrada correctamente.');
    }

    // ─── Nivel y matriz de tarifas ────────────────────────────────────

    /**
     * Asigna un nivel y copia su plantilla a la matriz propia del asesor.
     *
     * Va aparte del update general a propósito: copiar la matriz pisa lo que el asesor tuviera
     * y esa es una acción deliberada, no un efecto secundario de guardar el teléfono.
     */
    public function aplicarNivel(Request $request, Asesor $asesor)
    {
        $this->autorizarAliado($asesor);

        $datos = $request->validate([
            'nivel_id' => 'required|integer|exists:asesor_niveles,id',
            'conservar_editadas' => 'nullable|boolean',
        ]);

        $nivel = \App\Models\AsesorNivel::where('aliado_id', $asesor->aliado_id)
            ->findOrFail($datos['nivel_id']);

        $copiadas = $asesor->aplicarNivel($nivel, $request->boolean('conservar_editadas'));

        return redirect()->route('admin.asesores.tarifas', $asesor)
            ->with('success', "Se aplicó «{$nivel->nombre}»: {$copiadas} valor(es) copiados. "
                .'Desde aquí puedes ajustarlos solo para este asesor.');
    }

    /** Matriz propia del asesor — la copia editable de la plantilla de su nivel. */
    public function tarifas(Asesor $asesor)
    {
        $this->autorizarAliado($asesor);

        if (! auth()->user()->can('asesores.ver')) {
            abort(403, 'No tienes permiso para ver las tarifas de asesores.');
        }

        $alidoId = (int) $asesor->aliado_id;
        $base = \App\Services\TarifaAsesorService::baseTarifario($alidoId);

        $valores = \App\Models\AsesorTarifa::where('asesor_id', $asesor->id)
            ->get()
            ->keyBy(fn ($t) => \App\Models\AsesorNivelTarifa::claveCelda(
                (int) $t->plan_id, (int) $t->tipo_modalidad_id, (int) $t->nivel_arl
            ));

        return view('admin.configuracion.niveles.matriz', [
            'nivel' => $asesor,
            'nivelDelAsesor' => $asesor->nivel,
            'admonValor' => $asesor->comision_admon_valor,
            'volverUrl' => route('admin.asesores.edit', $asesor),
            'volverTexto' => 'Volver al asesor',
            'matriz' => \App\Services\TarifaAsesorService::armarMatriz($base, $valores),
            'base' => $base,
            'valores' => $valores,
            'rutaGuarda' => route('admin.asesores.tarifas.guardar', $asesor),
            'titulo' => $asesor->nombre,
            'contexto' => 'asesor',
        ]);
    }

    public function guardarTarifas(Request $request, Asesor $asesor)
    {
        $this->autorizarAliado($asesor);

        if (! auth()->user()->can('asesores.gestionar')) {
            abort(403, 'No tienes permiso para modificar tarifas de asesores.');
        }

        $request->validate([
            'admon_asesor' => 'nullable|numeric|min:0',
            'matriz.*.*.*' => 'nullable|numeric|min:0',
        ]);

        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $asesor) {
            // La admon del asesor vive en comision_admon_*, que es de donde la lee la
            // facturación de planillas de siempre.
            $asesor->update([
                'comision_admon_tipo' => 'fijo',
                'comision_admon_valor' => (float) ($request->input('admon_asesor') ?: 0),
            ]);

            // Vacío = sin valor propio: la fila se borra para que caiga a la cascada
            // (nivel → comisión plana), no para dejar un 0 fijo.
            \App\Services\TarifaAsesorService::sincronizarCeldas(
                \App\Models\AsesorTarifa::class,
                ['asesor_id' => $asesor->id],
                $request->input('matriz', []),
                ['afil_asesor'],
                ['aliado_id' => $asesor->aliado_id]
            );
        });

        \App\Services\TarifaAsesorService::limpiarCache();

        return back()->with('success', "Tarifas de «{$asesor->nombre}» guardadas.");
    }

    /** Tarifario imprimible: lo que el asesor cobra, lo que entrega y lo que gana. */
    public function tarifarioPdf(Asesor $asesor)
    {
        $this->autorizarAliado($asesor);

        if (! auth()->user()->can('asesores.gestionar')) {
            abort(403, 'No tienes permiso para generar el tarifario del asesor.');
        }

        $alidoId = (int) $asesor->aliado_id;
        $base = \App\Services\TarifaAsesorService::baseTarifario($alidoId);

        $valores = \App\Models\AsesorTarifa::where('asesor_id', $asesor->id)
            ->get()
            ->keyBy(fn ($t) => \App\Models\AsesorNivelTarifa::claveCelda(
                (int) $t->plan_id, (int) $t->tipo_modalidad_id, (int) $t->nivel_arl
            ));

        $matriz = \App\Services\TarifaAsesorService::armarMatriz($base, $valores);
        $gridSs = \App\Services\TarifaAsesorService::gridSeguridadSocial($alidoId);

        $cfg = \App\Models\ConfiguracionAliado::paraAliado($alidoId);
        $seguro = (int) ($cfg?->seguro_valor ?? 0);
        $aliado = \App\Models\Aliado::find($alidoId);

        // Solo las tarjetas donde el asesor tiene algo configurado: un tarifario lleno de
        // ceros no le sirve a nadie. La matriz viene agrupada en tarjetas → opciones.
        $matriz = array_filter($matriz, function ($tarjeta) {
            foreach ($tarjeta['opciones'] as $o) {
                foreach ($o['filas'] as $f) {
                    if ($f['asesor'] !== null && $f['publico'] > 0) {
                        return true;
                    }
                }
            }

            return false;
        });

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.tarifario_asesor', [
            'asesor' => $asesor,
            'aliado' => $aliado,
            'matriz' => $matriz,
            'gridSs' => $gridSs,
            'seguro' => $seguro,
            'admonAses' => (int) $asesor->comision_admon_valor,
            'salarioMin' => (int) \App\Models\ConfiguracionBrynex::salarioMinimo(),
        ])->setPaper('letter', 'portrait');

        $nombre = 'Tarifario_'.preg_replace('/[^A-Za-z0-9]+/', '_', $asesor->nombre).'.pdf';

        return $pdf->download($nombre);
    }

    // ─── Privados ─────────────────────────────────────────────────────
    private function autorizarAliado(Asesor $asesor): void
    {
        if ((int) $asesor->aliado_id !== (int) session('aliado_id_activo')) {
            abort(403, 'No tiene acceso a este asesor.');
        }
    }

    private function mensajes(): array
    {
        return [
            'cedula.required' => 'La cédula es obligatoria.',
            'cedula.unique' => 'Ya existe un asesor con esa cédula en este aliado.',
            'nombre.required' => 'El nombre es obligatorio.',
            'comision_afil_tipo.required' => 'Seleccione el tipo de comisión de afiliación.',
            'comision_afil_valor.required' => 'Ingrese el valor de la comisión de afiliación.',
            'comision_admon_tipo.required' => 'Seleccione el tipo de comisión de administración.',
            'comision_admon_valor.required' => 'Ingrese el valor de la comisión de administración.',
        ];
    }
}
