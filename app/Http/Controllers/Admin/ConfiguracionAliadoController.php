<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Aliado;
use App\Models\ConfiguracionAliado;
use App\Models\PlanContrato;
use App\Services\TarifaAsesorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ConfiguracionAliadoController extends Controller
{
    /**
     * Sin candado de rol: cada ruta de este controlador ya exige su permiso de
     * módulo (routes/web.php). El `role:superadmin|admin` que había aquí venía de
     * antes del control por permisos y le cerraba la puerta a quien recibía uno
     * suelto: a Jessica Arenas (rol usuario) le dieron gestionar cuentas bancarias
     * y aun así no entraba.
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /** Hub central de configuración */
    public function hub()
    {
        // Preferir EPS con PDF ya cargado; si ninguna, tomar la primera disponible
        $primeraEps = \App\Models\Eps::select('id', 'nombre', 'formulario_pdf')
            ->whereNotNull('formulario_pdf')->orderBy('nombre')->first()
            ?? \App\Models\Eps::select('id', 'nombre', 'formulario_pdf')
                ->orderBy('nombre')->first();

        // Fondo de pensión de entrada al editor: COLPENSIONES, que es el que
        // tiene formulario propio; si no, cualquiera con PDF ya cargado.
        $primeraPension = \App\Models\Pension::select('id', 'razon_social', 'formulario_pdf')
            ->whereNotNull('formulario_pdf')->orderBy('razon_social')->first()
            ?? \App\Models\Pension::select('id', 'razon_social', 'formulario_pdf')
                ->where('razon_social', 'like', '%COLPENSIONES%')->first()
            ?? \App\Models\Pension::select('id', 'razon_social', 'formulario_pdf')
                ->orderBy('razon_social')->first();

        // Obtener el primer operador de planilla disponible para el editor de planillas
        $primerOperador = \App\Models\OperadorPlanilla::orderBy('nombre')->first();

        // Slug del aliado activo, para el link de la tarjeta "Página Web Pública"
        $aliadoActivo = Aliado::select('id', 'slug')->find(session('aliado_id_activo'));

        return view('admin.configuracion.hub', compact('primeraEps', 'primeraPension', 'primerOperador', 'aliadoActivo'));
    }

    /**
     * Muestra la pantalla de configuración del aliado activo.
     */
    public function index()
    {
        $alidoId = session('aliado_id_activo');

        $planes = PlanContrato::where('activo', true)->get();
        $usuarios = \App\Models\User::where('aliado_id', $alidoId)->where('activo', true)->orderBy('nombre')->get();

        // Configuraciones por plan (y la global sin plan)
        $configs = ConfiguracionAliado::where('aliado_id', $alidoId)
            ->with('plan')
            ->orderBy('plan_id')
            ->get()
            ->keyBy(fn ($c) => $c->plan_id ?? 'global');

        // Las tarifas ARL y la config global de BryNex ya no se muestran aquí: viven en
        // BryNex → Parámetros BryNex. Ver BrynexController::parametros().

        // Aliado activo (para logo y parámetros especiales)
        $aliadoActual = Aliado::find($alidoId);

        // ── Tarifario por plan × modalidad × nivel de riesgo ARL ──────────────────────
        // Cada celda guarda afiliación, retiro, "otros" y admon mensual. Vacío = respaldo
        // (ver TarifaAsesorService, que es quien resuelve la cascada — no releer las columnas
        // a mano: la admon NO se cascadea por plan sino por la config genérica del aliado).
        // Los planes sin ARL traen un solo nivel, porque ahí el riesgo no cambia nada.
        $tarifario = $this->construirTarifario($alidoId, $configs);
        $gridSs = TarifaAsesorService::gridSeguridadSocial($alidoId);
        $seguroBase = (int) ($configs['global']?->seguro_valor ?? 0);

        // Celdas de niveles de asesor que quedaron imposibles con los precios actuales.
        $descuadradas = TarifaAsesorService::celdasDescuadradas($alidoId);

        // «Otros» de Valores generales: no es una columna de configuracion_aliado, es lo que
        // más se repite hoy en el tarifario. Así la casilla siempre dice la verdad y sirve de
        // atajo para cambiarlo en todos los planes, sin guardar un número que nadie lee.
        $otrosGeneral = (int) (DB::table('afiliacion_arl_modalidad')
            ->where('aliado_id', $alidoId)
            ->selectRaw('ISNULL(otros, 0) valor, count(*) c')
            ->groupBy(DB::raw('ISNULL(otros, 0)'))
            ->orderByDesc('c')->value('valor') ?? 0);

        return view('admin.configuracion.index', compact(
            'planes', 'usuarios', 'configs', 'aliadoActual',
            'tarifario', 'gridSs', 'seguroBase', 'descuadradas', 'otrosGeneral'
        ));
    }

    /**
     * Arma la estructura del tarifario para la vista: por plan, sus modalidades vendibles y,
     * por cada una, los valores configurados en cada nivel de riesgo.
     *
     * Una sola consulta a las celdas y una a las relaciones plan-modalidad: nada de consultar
     * dentro del bucle (son hasta 193 celdas y cada consulta cuesta ~250ms). Los respaldos
     * salen de $configs, que index() ya tiene cargado, en vez de un paraAliado() por plan.
     *
     * @param  \Illuminate\Support\Collection  $configs  filas de configuracion_aliado indexadas
     *                                                   por plan_id, con 'global' para la genérica
     */
    private function construirTarifario(int $alidoId, $configs): array
    {
        $global = $configs['global'] ?? null;

        $celdas = TarifaAsesorService::celdasDelAliado($alidoId);

        $relaciones = DB::table('modalidad_planes')
            ->where('solo_ia', false)
            ->get(['plan_id', 'tipo_modalidad_id']);

        // Solo activas: hay relaciones de modalidades apagadas que no se venden (ver
        // TarifaAsesorService::combinaciones, que aplica el mismo filtro).
        $modalidades = \App\Models\TipoModalidad::whereIn(
            'id', $relaciones->pluck('tipo_modalidad_id')->unique()
        )->where('activo', true)
            ->orderBy('orden')->get(['id', 'modalidad', 'observacion', 'es_tiempo_parcial'])->keyBy('id');

        $planes = PlanContrato::where('activo', true)->orderBy('id')->get()->keyBy('id');

        // Se agrupa por MODALIDAD y dentro por plan: así se ve de un vistazo lo que cuesta
        // "Independientes" en todos los planes, que es como se vende.
        $tarifario = [];
        foreach ($modalidades as $modalidad) {
            // Las modalidades con alias (Independientes Mes Actual) no se tarifan aparte:
            // heredan de su equivalente. Ver TarifaAsesorService::MODALIDADES_ALIAS.
            $modId = (int) $modalidad->id;
            if (array_key_exists($modId, TarifaAsesorService::MODALIDADES_ALIAS)) {
                continue;
            }

            $nombreMod = $modalidad->observacion ?: $modalidad->modalidad;
            $grupo = TarifaAsesorService::grupoDeModalidad($modId);
            $tarjeta = $grupo ? "g_{$grupo}" : "m_{$modId}";

            foreach ($relaciones->where('tipo_modalidad_id', $modalidad->id) as $rel) {
                $plan = $planes->get($rel->plan_id);
                if (! $plan) {
                    continue;
                }

                // Cada modalidad puede cubrir solo parte de los riesgos (K: 1-3, Y: 4-5).
                $nivelesArl = TarifaAsesorService::nivelesArlPara($plan, $modId);
                if (! $nivelesArl) {
                    continue;
                }

                $porNivel = [];
                foreach ($nivelesArl as $n) {
                    $celda = $celdas->get("{$plan->id}_{$modId}_{$n}");
                    $porNivel[$n] = [
                        'costo_afiliacion' => $celda?->costo_afiliacion,
                        'retiro' => $celda?->retiro,
                        'otros' => $celda?->otros,
                        'administracion' => $celda?->administracion,
                    ];
                }

                $cfgPlan = $configs[$plan->id] ?? null;

                $tarifario[$tarjeta]['clave'] = $tarjeta;
                // En una tarjeta de grupo el título es el del grupo («Solo ARL») y cada botón
                // nombra la modalidad; en una normal el título es la modalidad y los botones
                // son sus planes.
                $tarifario[$tarjeta]['nombre'] = $grupo
                    ? TarifaAsesorService::GRUPOS_TARIFARIO[$grupo]['nombre']
                    : $nombreMod;
                // Las 8 de Tiempo Parcial van en su propia tarjeta: son variantes de lo mismo
                // y llenaban la lista principal.
                $tarifario[$tarjeta]['tiempo_parcial'] = (bool) $modalidad->es_tiempo_parcial;
                $tarifario[$tarjeta]['opciones'][] = [
                    'etiqueta' => $grupo ? $nombreMod : $plan->nombre,
                    'modalidad_id' => $modId,
                    'plan' => $plan,
                    'niveles_arl' => $nivelesArl,
                    'niveles' => $porNivel,
                    // Respaldos que se muestran como marca de agua en las casillas vacías. Mismas
                    // cascadas que resuelve TarifaAsesorService: la afiliación y el % de retiro caen
                    // por plan, pero la ADMON sale siempre de la fila genérica (las filas por plan
                    // traen 0 en los datos reales — ver CotizacionPublicaService::cotizar).
                    'respaldos' => [
                        'costo_afiliacion' => (float) ($cfgPlan?->costo_afiliacion ?? $global?->costo_afiliacion ?? 0),
                        'administracion' => (float) ($global?->administracion ?? 0),
                        'retiro_pct' => (float) ($cfgPlan?->dist_retiro_pct ?? $global?->dist_retiro_pct ?? 0),
                    ],
                ];
            }
        }

        return $tarifario;
    }

    /**
     * Precios de afiliación que propone el sistema para los planes SIN AFP, contra el costo
     * mensual de cada uno. Solo devuelve el cálculo — ver TarifaAsesorService::proponerPrecios.
     */
    public function preciosSugeridos()
    {
        $alidoId = (int) session('aliado_id_activo');
        $p = TarifaAsesorService::proponerPrecios($alidoId);

        // Se cuenta sobre las CELDAS, no sobre el resumen por plan: una celda puede quedarse
        // como está porque su precio actual ya supera lo propuesto.
        $cambian = array_filter($p['celdas'], fn ($c) => $c['valor'] !== $c['hoy']);

        return response()->json([
            'ok' => true,
            'filas' => $p['filas'],
            'cambian' => count($cambian),
            'celdas' => count($p['celdas']),
            'pct' => (int) round(TarifaAsesorService::SUGERENCIA_PCT * 100),
            'piso' => TarifaAsesorService::SUGERENCIA_PISO,
            // Celdas donde el precio del plan no alcanzaba ni para el retiro de esa modalidad.
            'subidas' => $p['subidas'],
            // Celdas que se dejan como están porque ya valen más de lo propuesto.
            'conservadas' => $p['conservadas'],
        ]);
    }

    /**
     * Escribe esos precios. Reescribe la lista de precios completa del aliado,
     * por eso está detrás de superadmin y deja registro en bitácora.
     *
     * Solo toca costo_afiliacion: retiro, otros y administración se quedan como estén.
     */
    public function aplicarPreciosSugeridos()
    {
        $alidoId = (int) session('aliado_id_activo');
        $p = TarifaAsesorService::proponerPrecios($alidoId);

        // Nada de una consulta por celda: a 250ms de red, 72 celdas × leer+escribir se pasan
        // del max_execution_time. Se lee todo de una, se agrupan las celdas por precio y se
        // escribe un UPDATE por precio distinto más un INSERT con las que no existían.
        $existentes = \App\Models\AfiliacionArlModalidad::where('aliado_id', $alidoId)
            ->get()
            ->keyBy(fn ($f) => $f->plan_id.'_'.$f->tipo_modalidad_id.'_'.$f->nivel_arl);

        $porValor = [];
        $nuevas = [];
        $ahora = now();

        foreach ($p['celdas'] as $c) {
            $fila = $existentes->get($c['plan_id'].'_'.$c['tipo_modalidad_id'].'_'.$c['nivel_arl']);
            if (! $fila) {
                $nuevas[] = [
                    'aliado_id' => $alidoId,
                    'plan_id' => $c['plan_id'],
                    'tipo_modalidad_id' => $c['tipo_modalidad_id'],
                    'nivel_arl' => $c['nivel_arl'],
                    'costo_afiliacion' => $c['valor'],
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ];
            } elseif ((int) $fila->costo_afiliacion !== $c['valor']) {
                $porValor[$c['valor']][] = $fila->id;
            }
        }

        $tocadas = count($nuevas) + array_sum(array_map('count', $porValor));

        DB::transaction(function () use ($porValor, $nuevas, $ahora) {
            foreach ($porValor as $valor => $ids) {
                foreach (array_chunk($ids, 500) as $lote) {
                    DB::table('afiliacion_arl_modalidad')->whereIn('id', $lote)
                        ->update(['costo_afiliacion' => $valor, 'updated_at' => $ahora]);
                }
            }
            // 150 filas × 7 columnas = 1.050 parámetros, bajo el tope de 2.100 de SQL Server.
            foreach (array_chunk($nuevas, 150) as $lote) {
                DB::table('afiliacion_arl_modalidad')->insert($lote);
            }
        });

        TarifaAsesorService::limpiarCache();

        \App\Models\Bitacora::registrar(
            'updated', 'AfiliacionArlModalidad', null,
            "Precios de afiliación recalculados por el sistema: {$tocadas} celdas."
        );

        // El aviso de celdas de nivel descuadradas NO se calcula aquí: celdasDescuadradas()
        // recorre todas las tarifas de todos los niveles y, después de escribir, era lo que
        // hacía pasar la petición del max_execution_time. La pantalla ya lo muestra al recargar.
        return redirect()->route('admin.configuracion.index', ['seccion' => 'parametros'])
            ->with('success', "Precios recalculados: {$tocadas} celdas actualizadas. "
                .'Revisa los niveles de asesor: su comisión sale de lo que le queda al aliado y ese margen cambió.');
    }

    /**
     * Retiros que le corresponden al tarifario con el salario mínimo vigente.
     * Ver TarifaAsesorService::proponerRetiros — solo propone los que SUBEN.
     */
    public function retirosSugeridos()
    {
        $alidoId = (int) session('aliado_id_activo');
        $filas = TarifaAsesorService::proponerRetiros($alidoId);
        $suben = array_values(array_filter($filas, fn ($f) => $f['sube']));

        return response()->json([
            'ok' => true,
            'filas' => $suben,
            'suben' => count($suben),
            'total' => count($filas),
            'dias_tp' => TarifaAsesorService::DIAS_MINIMOS_TIEMPO_PARCIAL,
        ]);
    }

    /**
     * Escribe los retiros que subieron. Los que ya están por encima del cálculo NO se tocan:
     * el salario mínimo siempre sube, así que lo que quedó más alto es un ajuste del aliado.
     */
    public function aplicarRetirosSugeridos()
    {
        $alidoId = (int) session('aliado_id_activo');
        $filas = array_filter(TarifaAsesorService::proponerRetiros($alidoId), fn ($f) => $f['sube']);

        $existentes = \App\Models\AfiliacionArlModalidad::where('aliado_id', $alidoId)
            ->get()
            ->keyBy(fn ($f) => $f->plan_id.'_'.$f->tipo_modalidad_id.'_'.$f->nivel_arl);

        // Igual que en los precios: agrupado por valor, un UPDATE por valor distinto.
        // Celda por celda serían ~280 consultas de 250ms y se pasa del tiempo de la petición.
        $porValor = [];
        $nuevas = [];
        $ahora = now();

        foreach ($filas as $f) {
            $fila = $existentes->get($f['plan_id'].'_'.$f['tipo_modalidad_id'].'_'.$f['nivel_arl']);
            if ($fila) {
                $porValor[$f['calculado']][] = $fila->id;
            } else {
                $nuevas[] = [
                    'aliado_id' => $alidoId,
                    'plan_id' => $f['plan_id'],
                    'tipo_modalidad_id' => $f['tipo_modalidad_id'],
                    'nivel_arl' => $f['nivel_arl'],
                    'retiro' => $f['calculado'],
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ];
            }
        }

        $tocadas = count($nuevas) + array_sum(array_map('count', $porValor));

        DB::transaction(function () use ($porValor, $nuevas, $ahora) {
            foreach ($porValor as $valor => $ids) {
                foreach (array_chunk($ids, 500) as $lote) {
                    DB::table('afiliacion_arl_modalidad')->whereIn('id', $lote)
                        ->update(['retiro' => $valor, 'updated_at' => $ahora]);
                }
            }
            foreach (array_chunk($nuevas, 150) as $lote) {
                DB::table('afiliacion_arl_modalidad')->insert($lote);
            }
        });

        TarifaAsesorService::limpiarCache();

        \App\Models\Bitacora::registrar(
            'updated', 'AfiliacionArlModalidad', null,
            "Retiros recalculados con el salario mínimo vigente: {$tocadas} celdas."
        );

        return redirect()->route('admin.configuracion.index', ['seccion' => 'parametros'])
            ->with('success', "Retiros actualizados: {$tocadas} celdas. Las que ya estaban por encima del cálculo no se tocaron.");
    }

    /**
     * Guarda/actualiza la configuración general + por plan del aliado.
     */
    public function store(Request $request)
    {
        $alidoId = session('aliado_id_activo');

        $request->validate([
            'configs.*.administracion' => 'nullable|numeric|min:0',
            'configs.*.admon_asesor' => 'nullable|numeric|min:0',
            'configs.*.costo_afiliacion' => 'nullable|numeric|min:0',
            'configs.*.promocion_costo_afiliacion' => 'nullable|numeric|min:0',
            'configs.*.promocion_vencimiento' => 'nullable|date',
            'configs.*.dist_admon_pct' => 'nullable|numeric|min:0|max:100',
            'configs.*.dist_retiro_pct' => 'nullable|numeric|min:0|max:100',
            'configs.*.seguro_valor' => 'nullable|numeric|min:0',
            'configs.*.encargado_default_id' => 'nullable|exists:users,id',
            'configs.*.dia_ingreso_ir' => 'nullable|integer|min:1|max:28',
            // Mora al cliente
            'configs.*.mora_dia_habil_inicio' => 'nullable|integer|min:2|max:16',
            'configs.*.mora_minimo' => 'nullable|numeric|min:0',
            'configs.*.mora_segundo' => 'nullable|numeric|min:0',
            'arl.*.porcentaje' => 'nullable|numeric|min:0|max:100',
            'tarifario.*.*.*.costo_afiliacion' => 'nullable|numeric|min:0',
            'tarifario.*.*.*.retiro' => 'nullable|numeric|min:0',
            'tarifario.*.*.*.otros' => 'nullable|numeric|min:0',
            'tarifario.*.*.*.administracion' => 'nullable|numeric|min:0',
            'brynex.*' => 'nullable|numeric|min:0',
            // Sin SVG: se guarda en disco público y un SVG puede llevar <script>
            // embebido, que se ejecuta al abrirlo directamente (XSS).
            'seguro_logo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
            'recibo_doble_copia' => 'nullable|boolean',
            // Seguimiento de prospectos (cotizaciones)
            'prospectos.recordatorio' => 'nullable|boolean',
            'prospectos.recordatorio_celular' => 'nullable|string|max:20',
            'prospectos.dias_sin_respuesta' => 'nullable|integer|min:1|max:365',
            'prospectos.dias_cierre' => 'nullable|integer|min:1|max:365',
        ]);

        DB::transaction(function () use ($request, $alidoId) {
            // ── 0. Parámetros del aliado (tabla aliados, no por plan) ──
            // Solo si el formulario trae el campo, para que un POST parcial
            // no apague el interruptor sin querer.
            if ($request->has('recibo_doble_copia')) {
                Aliado::where('id', $alidoId)->update([
                    'recibo_doble_copia' => $request->boolean('recibo_doble_copia'),
                ]);
            }

            // Los parámetros globales de BryNex y las tarifas ARL ya no se guardan aquí:
            // se movieron a BryNex → Parámetros BryNex (BrynexController::guardarParametros),
            // porque son del sistema y no del aliado.

            // ── 1. Seguimiento de prospectos: vive solo en la fila global ──
            if ($request->has('prospectos')) {
                $p = $request->input('prospectos', []);
                $entero = fn ($v) => ($v !== null && $v !== '') ? (int) $v : null;
                ConfiguracionAliado::updateOrCreate(
                    ['aliado_id' => $alidoId, 'plan_id' => null],
                    [
                        'prospectos_recordatorio' => (bool) ($p['recordatorio'] ?? false),
                        'prospectos_recordatorio_celular' => preg_replace('/\D/', '', (string) ($p['recordatorio_celular'] ?? '')) ?: null,
                        'prospectos_dias_sin_respuesta' => $entero($p['dias_sin_respuesta'] ?? null),
                        'prospectos_dias_cierre' => $entero($p['dias_cierre'] ?? null),
                        'activo' => true,
                    ]
                );
            }

            // ── 2. Configuraciones por plan ──
            foreach ($request->input('configs', []) as $planKey => $data) {
                $planId = ($planKey === 'global') ? null : (int) $planKey;

                ConfiguracionAliado::updateOrCreate(
                    ['aliado_id' => $alidoId, 'plan_id' => $planId],
                    [
                        'administracion' => $data['administracion'] ?? 0,
                        'admon_asesor' => $data['admon_asesor'] ?? 0,
                        'costo_afiliacion' => $data['costo_afiliacion'] ?? 0,
                        'promocion_costo_afiliacion' => ($data['promocion_costo_afiliacion'] ?? '') !== ''
                                                     ? $data['promocion_costo_afiliacion'] : null,
                        'promocion_vencimiento' => ($data['promocion_vencimiento'] ?? '') !== ''
                                                     ? $data['promocion_vencimiento'] : null,
                        'dist_admon_pct' => $data['dist_admon_pct'] ?? 0,
                        'dist_retiro_pct' => $data['dist_retiro_pct'] ?? 0,
                        'seguro_valor' => $data['seguro_valor'] ?? 0,
                        'encargado_default_id' => $data['encargado_default_id'] ?: null,
                        'dia_ingreso_ir' => (($data['dia_ingreso_ir'] ?? null) !== '' && ($data['dia_ingreso_ir'] ?? null) !== null)
                                                     ? (int) $data['dia_ingreso_ir'] : 26,
                        // Mora al cliente
                        'mora_dia_habil_inicio' => (($data['mora_dia_habil_inicio'] ?? null) !== '' && ($data['mora_dia_habil_inicio'] ?? null) !== null)
                                                     ? (int) $data['mora_dia_habil_inicio'] : null,
                        'mora_minimo' => $data['mora_minimo'] ?? 2000,
                        'mora_segundo' => $data['mora_segundo'] ?? 5000,
                        // Un solo valor por aliado (solo tiene sentido en la fila global, pero
                        // guardarlo también en filas por plan no hace daño: nunca se leen de ahí).
                        'activo' => true,
                    ]
                );
            }

            // ── 3b. Tarifario por plan + modalidad + nivel de riesgo ARL ──
            // tarifario[plan_id][tipo_modalidad_id][nivel][campo] = valor, con campo en
            // {costo_afiliacion, retiro, otros, administracion}.
            //
            // Cada campo vacío se guarda como NULL, que significa "usa el respaldo" y NO "vale 0"
            // (ver AfiliacionArlModalidad). La fila solo se borra cuando los CUATRO quedan vacíos:
            // borrarla porque se vació el precio se llevaría por delante la admon y el retiro.
            TarifaAsesorService::sincronizarCeldas(
                \App\Models\AfiliacionArlModalidad::class,
                ['aliado_id' => $alidoId],
                $request->input('tarifario', []),
                ['costo_afiliacion', 'retiro', 'otros', 'administracion']
            );
        });

        // Las tarifas ARL y el salario mínimo cambian la seguridad social de la grilla: se bota
        // el caché para que el "Total mes" no quede mostrando el precio viejo.
        TarifaAsesorService::olvidarGridSs($alidoId);

        // ── 4. Logo de la aseguradora (fuera de la transacción para manejar archivos) ──
        if ($request->hasFile('seguro_logo') && $request->file('seguro_logo')->isValid()) {
            $configGlobal = ConfiguracionAliado::where('aliado_id', $alidoId)
                ->whereNull('plan_id')
                ->first();

            if ($configGlobal) {
                // Eliminar logo anterior si existe
                if ($configGlobal->seguro_logo && Storage::disk('public')->exists($configGlobal->seguro_logo)) {
                    Storage::disk('public')->delete($configGlobal->seguro_logo);
                }
                $path = $request->file('seguro_logo')->store('aliados/seguros', 'public');
                $configGlobal->update(['seguro_logo' => $path]);
            }
        }

        return redirect()->route('admin.configuracion.index')
            ->with('success', 'Configuración guardada correctamente.');
    }

    // ─── Cuentas Bancarias ────────────────────────────────────────────
    // ─── Catálogo de seguros del aliado ──────────────────────────────────────
    // Los seguros que este aliado vende ("Plan exequial 2" a $30.000, mascotas,
    // vida…). En el contrato se escoge uno y su valor se copia a `contratos.seguro`.

    public function seguros()
    {
        $alidoId = session('aliado_id_activo');

        $seguros = \App\Models\AliadoSeguro::where('aliado_id', $alidoId)
            ->withCount(['contratos as contratos_vigentes' => fn ($q) => $q->where('estado', 'vigente')])
            ->orderBy('orden')->orderBy('nombre')
            ->get();

        return view('admin.configuracion.seguros', compact('seguros'));
    }

    public function storeSeguro(Request $request)
    {
        $alidoId = session('aliado_id_activo');

        $v = $request->validate([
            'nombre' => 'required|string|max:120',
            'valor' => 'required|numeric|min:0',
            'descripcion' => 'nullable|string|max:500',
            'orden' => 'nullable|integer|min:0|max:999',
        ]);

        $v['aliado_id'] = $alidoId;
        $v['orden'] = $v['orden'] ?? 99;
        $v['activo'] = $request->boolean('activo', true);

        \App\Models\AliadoSeguro::create($v);

        return redirect()->route('admin.configuracion.seguros')
            ->with('success', 'Seguro agregado al catálogo.');
    }

    public function updateSeguro(Request $request, int $id)
    {
        $alidoId = session('aliado_id_activo');
        $seguro = \App\Models\AliadoSeguro::where('aliado_id', $alidoId)->findOrFail($id);

        $v = $request->validate([
            'nombre' => 'required|string|max:120',
            'valor' => 'required|numeric|min:0',
            'descripcion' => 'nullable|string|max:500',
            'orden' => 'nullable|integer|min:0|max:999',
        ]);

        $v['orden'] = $v['orden'] ?? $seguro->orden;
        $v['activo'] = $request->boolean('activo');

        // El valor nuevo es la tarifa de aquí en adelante: los contratos que ya lo
        // tienen conservan lo suyo, porque el precio vive en `contratos.seguro`.
        $seguro->update($v);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('admin.configuracion.seguros')
            ->with('success', 'Seguro actualizado.');
    }

    public function destroySeguro(int $id)
    {
        $alidoId = session('aliado_id_activo');
        $seguro = \App\Models\AliadoSeguro::where('aliado_id', $alidoId)->findOrFail($id);

        if ($seguro->contratos()->exists()) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'Este seguro ya está vendido en uno o más contratos. Solo puede inactivarse.',
            ], 422);
        }

        $seguro->delete();

        return response()->json(['ok' => true]);
    }

    public function cuentas()
    {
        $alidoId = session('aliado_id_activo');
        $cuentas = \App\Models\BancoCuenta::where('aliado_id', $alidoId)
            ->orderBy('banco')->get();

        return view('admin.configuracion.cuentas', compact('cuentas'));
    }

    public function storeCuenta(Request $request)
    {
        $alidoId = session('aliado_id_activo');
        $v = $request->validate([
            'banco' => 'required|string|max:100',
            'nombre' => 'nullable|string|max:150',
            'nit' => 'nullable|string|max:20',
            'tipo_cuenta' => 'nullable|in:Ahorros,Corriente',
            'numero_cuenta' => 'required|string|max:30',
            'activo' => 'boolean',
            'cobro' => 'boolean',
            'facturacion' => 'boolean',
            'incapacidad' => 'boolean',
            'uso_personal' => 'boolean',
            'observacion' => 'nullable|string|max:300',
            // La llave Bre-B se valida por formato: mal escrita, el cliente
            // intenta pagar, no le funciona y termina llamando igual.
            'llave' => ['nullable', 'string', 'max:100', function ($attr, $valor, $fail) {
                if (! \App\Models\BancoCuenta::llaveValida($valor)) {
                    $fail('La llave Bre-B no parece válida. Use @alias, un correo, un celular '
                        .'de 10 dígitos, o el documento o código de comercio (solo números).');
                }
            }],
        ]);
        $v['aliado_id'] = $alidoId;
        $v['activo'] = $request->boolean('activo');
        $v['cobro'] = $request->boolean('cobro');
        $v['facturacion'] = $request->boolean('facturacion');
        $v['incapacidad'] = $request->boolean('incapacidad');
        $v['uso_personal'] = $request->boolean('uso_personal');

        // El rol `usuario` solo puede dar de alta cuentas DE INCAPACIDAD: se le
        // fuerzan las marcas para que no cree por accidente (ni a propósito) una
        // cuenta que salga en el selector de facturar o en la cuenta de cobro.
        if (! auth()->user()->can('cuentas_bancarias.gestionar')) {
            $v['incapacidad'] = true;
            $v['cobro'] = false;
            $v['facturacion'] = false;
        }

        \App\Models\BancoCuenta::create($v);

        return redirect()->route('admin.configuracion.cuentas')
            ->with('success', 'Cuenta bancaria creada.');
    }

    public function updateCuenta(Request $request, int $id)
    {
        $alidoId = session('aliado_id_activo');
        $cuenta = \App\Models\BancoCuenta::where('aliado_id', $alidoId)->findOrFail($id);
        $v = $request->validate([
            'banco' => 'required|string|max:100',
            'nombre' => 'nullable|string|max:150',
            'nit' => 'nullable|string|max:20',
            'tipo_cuenta' => 'nullable|in:Ahorros,Corriente',
            'numero_cuenta' => 'required|string|max:30',
            'activo' => 'boolean',
            'cobro' => 'boolean',
            'facturacion' => 'boolean',
            'incapacidad' => 'boolean',
            'uso_personal' => 'boolean',
            'observacion' => 'nullable|string|max:300',
            // La llave Bre-B se valida por formato: mal escrita, el cliente
            // intenta pagar, no le funciona y termina llamando igual.
            'llave' => ['nullable', 'string', 'max:100', function ($attr, $valor, $fail) {
                if (! \App\Models\BancoCuenta::llaveValida($valor)) {
                    $fail('La llave Bre-B no parece válida. Use @alias, un correo, un celular '
                        .'de 10 dígitos, o el documento o código de comercio (solo números).');
                }
            }],
        ]);
        $v['activo'] = $request->boolean('activo');
        $v['cobro'] = $request->boolean('cobro');
        $v['facturacion'] = $request->boolean('facturacion');
        $v['incapacidad'] = $request->boolean('incapacidad');
        $v['uso_personal'] = $request->boolean('uso_personal');
        $cuenta->update($v);
        // Si petición AJAX (fetch) devuelve JSON
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('admin.configuracion.cuentas')
            ->with('success', 'Cuenta actualizada.');
    }

    public function destroyCuenta(int $id)
    {
        $alidoId = session('aliado_id_activo');
        $cuenta = \App\Models\BancoCuenta::where('aliado_id', $alidoId)->findOrFail($id);

        // Verificar que no tenga registros en consignaciones ni anticipos
        $tieneRegistros = DB::table('consignaciones')->where('banco_cuenta_id', $id)->exists()
            || DB::table('consignaciones')->where('banco_cuenta2_id', $id)->exists()
            || DB::table('anticipos')->where('banco_cuenta_id', $id)->exists();

        if ($tieneRegistros) {
            return response()->json([
                'ok' => false,
                'mensaje' => 'Esta cuenta tiene registros asociados (facturas o consignaciones). Solo puede inactivarse.',
            ], 422);
        }

        $cuenta->delete();

        return response()->json(['ok' => true]);
    }

    public function inactivarCuenta(int $id)
    {
        $alidoId = session('aliado_id_activo');
        $cuenta = \App\Models\BancoCuenta::where('aliado_id', $alidoId)->findOrFail($id);
        $cuenta->update(['activo' => false]);

        return response()->json(['ok' => true]);
    }

    public function estadoCuentaContratos(int $id)
    {
        $alidoId = session('aliado_id_activo');
        $cuenta = \App\Models\BancoCuenta::where('aliado_id', $alidoId)->find($id);

        if (! $cuenta) {
            return response()->json(['error' => 'No encontrada'], 404);
        }

        $tieneRegistros = DB::table('consignaciones')->where('banco_cuenta_id', $id)->exists()
            || DB::table('consignaciones')->where('banco_cuenta2_id', $id)->exists()
            || DB::table('anticipos')->where('banco_cuenta_id', $id)->exists();

        return response()->json([
            'banco' => $cuenta->banco,
            'numero_cuenta' => $cuenta->numero_cuenta,
            'activo' => (bool) $cuenta->activo,
            'tiene_registros' => $tieneRegistros,
            'puede_eliminar' => ! $tieneRegistros,
            'puede_inactivar' => (bool) $cuenta->activo,
        ]);
    }
}
