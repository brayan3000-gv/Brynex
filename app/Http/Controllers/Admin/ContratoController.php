<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActividadEconomica;
use App\Models\Arl;
use App\Models\Asesor;
use App\Models\BancoCuenta;
use App\Models\Caja;
use App\Models\Cliente;
use App\Models\ConfiguracionBrynex;
use App\Models\Contrato;
use App\Models\Eps;
use App\Models\MotivoAfiliacion;
use App\Models\MotivoRetiro;
use App\Models\Pension;
use App\Models\PlanContrato;
use App\Models\Plano;
use App\Models\Radicado;
use App\Models\RazonSocial;
use App\Models\TipoModalidad;
use App\Services\MoraClienteService;
use App\Services\TarifaAsesorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ContratoController extends Controller
{
    use \App\Traits\ResuelveArlEfectiva;

    public function __construct()
    {
        $this->middleware(['auth', 'role:superadmin|admin|usuario']);
    }

    // ─── Listado de contratos del aliado activo ───────────────────────
    public function index(Request $request)
    {
        $alidoId = session('aliado_id_activo');
        $estado = $request->get('estado', 'vigente');
        $buscar = $request->get('q');

        $query = Contrato::where('contratos.aliado_id', $alidoId)
            ->when($estado !== 'todos', fn ($q) => $q->where('estado', $estado))
            ->when($buscar, function ($q) use ($buscar) {
                $q->where(function ($inner) use ($buscar) {
                    $inner->where('cedula', 'like', "%{$buscar}%")
                        ->orWhereHas('cliente', fn ($c) => $c->wherePalabrasSinTildes(['primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido'], $buscar));
                });
            })
            ->with(['cliente', 'razonSocial', 'plan', 'tipoModalidad', 'asesor'])
            ->orderByDesc('id');

        $contratos = $query->paginate(25)->withQueryString();

        return view('admin.contratos.index', compact('contratos', 'estado', 'buscar'));
    }

    // ─── Formulario crear ─────────────────────────────────────────────
    public function create(Request $request)
    {
        $alidoId = session('aliado_id_activo');
        $cedula = $request->get('cedula');
        $cliente = $cedula ? Cliente::where('cedula', $cedula)
            ->where('aliado_id', $alidoId)->first() : null;

        // Viene de una solicitud de ingreso del portal de empresas: la fecha y
        // el cargo que pidió la empresa llegan puestos, y el plan se muestra
        // arriba para escogerlo junto con la razón social y la modalidad.
        $solicitudPortal = $this->solicitudIngresoPendiente($request->integer('solicitud'), $alidoId, $cedula);
        $contrato = new Contrato;
        if ($solicitudPortal) {
            $contrato->fecha_ingreso = $solicitudPortal->datos['fecha_ingreso'] ?? null;
            $contrato->cargo = $solicitudPortal->datos['cargo'] ?? null;
        }

        return view('admin.contratos.form', array_merge(
            $this->datosFormulario($alidoId, $cliente, null, null),
            ['contrato' => $contrato, 'cliente' => $cliente, 'solicitudPortal' => $solicitudPortal]
        ));
    }

    /**
     * La solicitud de ingreso del portal que está pendiente para esa cédula.
     *
     * Sin id se busca la más reciente: el equipo suele crear el contrato con
     * «+ Nuevo Contrato» desde la ficha, no desde la tarea, y si no se liga la
     * empresa nunca vería su ingreso aprobado.
     */
    private function solicitudIngresoPendiente(int $id, $alidoId, ?string $cedula): ?\App\Models\EmpresaSolicitud
    {
        if (! $cedula) {
            return null;
        }

        return \App\Models\EmpresaSolicitud::where('aliado_id', $alidoId)
            ->where('tipo', 'ingreso')
            ->where('estado', 'pendiente')
            ->where('cedula', $cedula)
            ->when($id, fn ($q) => $q->whereKey($id), fn ($q) => $q->latest('id'))
            ->first();
    }

    // ─── Guardar nuevo contrato ───────────────────────────────────────
    public function store(Request $request)
    {
        $alidoId = session('aliado_id_activo');
        $data = $this->validar($request);
        $data['aliado_id'] = $alidoId;
        $data['estado'] = 'vigente';
        $data['encargado_id'] = $data['encargado_id'] ?? Auth::id();
        $data['fecha_created'] = now();

        // IBC = salario si no se indica diferente
        if (empty($data['ibc'])) {
            $data['ibc'] = $data['salario'];
        }

        // Auto-derivar nit cotizante ARL si no vino explícito del formulario
        if (empty($data['arl_nit_cotizante'])) {
            if (($data['arl_modo'] ?? null) === 'razon_social' && ! empty($data['razon_social_id'])) {
                $data['arl_nit_cotizante'] = (int) $data['razon_social_id']; // PK = NIT
            } elseif (($data['arl_modo'] ?? null) === 'independiente' && ! empty($data['cedula'])) {
                $data['arl_nit_cotizante'] = (int) $data['cedula'];
            }
        }

        $data = $this->completarArlDesdeRazonSocial($data);

        DB::transaction(function () use ($data, &$nuevoContrato) {
            $nuevoContrato = Contrato::create($data);
            // Generar radicados pendientes según el plan
            $nuevoContrato->load('plan');
            $nuevoContrato->crearRadicadosPendientes();
        });

        // Si la RS es independiente y viene operador_planilla_id, guardarlo en el cliente
        $operadorId = $data['operador_planilla_id'] ?? null;
        if ($operadorId) {
            $cedStore = $nuevoContrato->cedula ?? ($data['cedula'] ?? null);
            if ($cedStore) {
                $rsIdStore = $nuevoContrato->razon_social_id ?? ($data['razon_social_id'] ?? null);
                $esIndepRS = $rsIdStore && DB::table('razones_sociales')
                    ->where('id', $rsIdStore)->value('es_independiente');
                if ($esIndepRS) {
                    Cliente::where('cedula', $cedStore)
                        ->where('aliado_id', $alidoId)
                        ->update(['operador_planilla_id' => $operadorId]);
                }
            }
        }

        // Si nació de una solicitud de ingreso del portal, la empresa ve que
        // quedó aprobada y la tarea se cierra.
        if ($request->filled('empresa_solicitud_id')
            && $solicitud = $this->solicitudIngresoPendiente($request->integer('empresa_solicitud_id'), $alidoId, $nuevoContrato->cedula)) {
            app(\App\Services\EmpresaSolicitudService::class)->resolver(
                $solicitud,
                'aprobada',
                'Afiliamos a '.($solicitud->datos['nombre'] ?? $nuevoContrato->cedula).' con ingreso el '
                    .$nuevoContrato->fecha_ingreso?->format('d/m/Y').'. Ya sale en «Mi mes».',
                (int) $nuevoContrato->id
            );
        }

        // Redirigir al cliente del contrato creado
        $cedula = $nuevoContrato->cedula ?? ($data['cedula'] ?? null);
        $cliente = $cedula ? \App\Models\Cliente::where('cedula', $cedula)
            ->where('aliado_id', $alidoId)->first() : null;
        if ($cliente) {
            return redirect()->route('admin.clientes.edit', $cliente->id)
                ->with('success', 'Contrato creado correctamente. Se generaron los radicados pendientes.');
        }

        return redirect()->route('admin.contratos.index')
            ->with('success', 'Contrato creado correctamente.');
    }

    // ─── Formulario editar ────────────────────────────────────────────
    public function edit(int $id)
    {
        $alidoId = session('aliado_id_activo');
        $contrato = Contrato::where('aliado_id', $alidoId)->with(['cliente', 'radicados.user', 'plan', 'razonSocial'])->findOrFail($id);
        $cliente = $contrato->cliente;

        // URL de retorno: viene como ?back=... o se toma del referrer
        $backUrl = request('back') ?: url()->previous();

        // ── Radicados indexados por tipo (eps, arl, caja, pension) ──────
        $radicadosPorTipo = $contrato->radicados->keyBy('tipo');

        // ── ¿La RS está bloqueada por afiliaciones activas? ─────────────
        // Si algún radicado está en tramite u ok, no se puede cambiar la RS
        $estadosBloqueantes = ['tramite', 'ok'];
        $hayAfiliacionActiva = $contrato->radicados
            ->whereIn('estado', $estadosBloqueantes)
            ->isNotEmpty();

        // El superadmin no queda bloqueado: la vista le deja los campos
        // editables y le advierte antes de guardar (ver $rsDesbloqueoSuperadmin
        // en form.blade.php). El cambio forzado queda en bitácora.
        $puedeForzarBloqueo = Auth::user()->can('contratos.editar_radicado');
        $rsBloquedaPorAfiliacion = $hayAfiliacionActiva && ! $puedeForzarBloqueo;
        $rsDesbloqueoSuperadmin = $hayAfiliacionActiva && $puedeForzarBloqueo;

        // Salario, IBC y encargado no viajan en el radicado: la afiliación ya
        // quedó hecha y lo que cambian es lo que se cotiza y se cobra de ahí
        // en adelante. Por eso el admin sí los mueve aunque el contrato esté
        // radicado; las entidades y las fechas siguen siendo del superadmin.
        $economicoDesbloqueado = $rsBloquedaPorAfiliacion && $this->puedeEditarEconomico();

        // ── Otros contratos vigentes del mismo cliente (para modal multi-contrato) ──
        // Se excluye el contrato actual. Solo se muestran vigentes (no activo, no retirado).
        $otrosContratosVigentes = Contrato::where('aliado_id', $alidoId)
            ->where('cedula', $contrato->cedula)
            ->where('estado', 'vigente')
            ->where('id', '!=', $id)
            ->with('razonSocial')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'razon_social' => $c->razonSocial?->razon_social ?? 'Sin RS',
            ]);

        // Verificar si el contrato tiene planillas con días cotizados > 0
        // Para independientes (es_independiente=1): siempre se permite retiro informativo
        // porque el cliente paga la SS por sus propios medios.
        $rsEdit = $contrato->razonSocial;
        $esIndependienteEdit = $contrato->esIndependiente() || ($rsEdit && $rsEdit->es_independiente);
        $tienePlanillaConDias = $esIndependienteEdit
            ? false
            : \App\Models\Factura::where('contrato_id', $contrato->id)
                ->where('tipo', 'planilla')
                ->where('dias_cotizados', '>', 0)
                ->where('numero_factura', '>', 0)
                ->exists();

        // El superadmin sí puede marcar retiro informativo aunque haya planillas
        // con días cotizados: hay contratos migrados y otros a los que ya se les
        // marcó el retiro directamente en la planilla, fuera del sistema.
        $retiroInfoBloqueado = $tienePlanillaConDias && ! $puedeForzarBloqueo;
        $retiroInfoForzado = $tienePlanillaConDias && $puedeForzarBloqueo;

        // ── Modal Duplicar (Plan Ingreso-Retiro) ─────────────
        $rsIrOpciones = [];
        $rsIrPreviewId = null;
        $rsIrHayDisponible = false;

        if ($contrato->estaVigente() && (int) $contrato->tipo_modalidad_id === 12) {
            $alidoIdIr = $alidoId;
            $todasRsIr = \App\Models\RazonSocial::where('aliado_id', $alidoIdIr)
                ->where('es_independiente', false)
                ->where('estado', 'Activa')
                ->whereRaw("UPPER(razon_social) NOT LIKE '%RAZON SOCIAL%'")
                ->get(['id', 'razon_social']);

            $rsVigentesIrSet = DB::table('contratos')
                ->where('cedula', $contrato->cedula)
                ->where('aliado_id', $alidoIdIr)
                ->where('estado', 'vigente')
                ->pluck('razon_social_id')
                ->filter() // excluir NULLs para evitar error en flip()
                ->flip();

            $ultimosRetiros = DB::table('contratos')
                ->where('cedula', $contrato->cedula)
                ->where('aliado_id', $alidoIdIr)
                ->where('estado', 'retirado')
                ->whereNotNull('fecha_retiro')
                ->select('razon_social_id', DB::raw('MAX(fecha_retiro) as ultimo_retiro'))
                ->groupBy('razon_social_id')
                ->get()
                ->keyBy('razon_social_id');

            $rsConHistIr = DB::table('contratos')
                ->where('cedula', $contrato->cedula)
                ->where('aliado_id', $alidoIdIr)
                ->pluck('razon_social_id')
                ->unique()
                ->filter() // excluir NULLs para evitar error en flip()
                ->flip();

            $ahora = \Carbon\Carbon::now();

            foreach ($todasRsIr as $rsItem) {
                $esActual = (int) $rsItem->id === (int) $contrato->razon_social_id;
                $esVigente = isset($rsVigentesIrSet[$rsItem->id]);
                $bloqueada = $esActual || $esVigente;

                $tiempoTexto = null;
                $ultimoRet = $ultimosRetiros->get($rsItem->id);
                if ($ultimoRet && $ultimoRet->ultimo_retiro) {
                    $fechaRet = \Carbon\Carbon::parse($ultimoRet->ultimo_retiro);
                    $meses = (int) $fechaRet->diffInMonths($ahora);
                    $anios = (int) floor($meses / 12);
                    $mesesRest = $meses % 12;
                    if ($anios > 0 && $mesesRest > 0) {
                        $tiempoTexto = "Retirado hace {$anios}a {$mesesRest}m";
                    } elseif ($anios > 0) {
                        $tiempoTexto = "Retirado hace {$anios} año".($anios > 1 ? 's' : '');
                    } elseif ($meses > 0) {
                        $tiempoTexto = "Retirado hace {$meses} mes".($meses > 1 ? 'es' : '');
                    } else {
                        $tiempoTexto = 'Retirado este mes';
                    }
                }

                if ($bloqueada) {
                    $prioridad = 99;
                } elseif (! isset($rsConHistIr[$rsItem->id])) {
                    $prioridad = 0;
                } else {
                    $prioridad = $ultimoRet ? \Carbon\Carbon::parse($ultimoRet->ultimo_retiro)->timestamp : 50;
                }

                $rsIrOpciones[] = [
                    'id' => $rsItem->id,
                    'nombre' => $rsItem->razon_social,
                    'bloqueada' => $bloqueada,
                    'es_actual' => $esActual,
                    'es_vigente' => $esVigente,
                    'nunca_usada' => ! isset($rsConHistIr[$rsItem->id]),
                    'tiempo' => $tiempoTexto,
                    'prioridad' => $prioridad,
                ];
            }

            usort($rsIrOpciones, fn ($a, $b) => $a['bloqueada'] <=> $b['bloqueada'] ?: $a['prioridad'] <=> $b['prioridad']);

            foreach ($rsIrOpciones as $op) {
                if (! $op['bloqueada']) {
                    $rsIrPreviewId = $op['id'];
                    break;
                }
            }
            $rsIrHayDisponible = $rsIrPreviewId !== null;
        }

        $cfgAliado = \App\Models\ConfiguracionAliado::paraAliado($alidoId);
        $diaIngresoIr = max(1, min(28, (int) ($cfgAliado?->dia_ingreso_ir ?? 26)));

        // Qué ARL le aplica de verdad. No basta con `arl_id`: en una razón
        // social de empresa la ARL la pone la empresa por `arl_nit` y el
        // contrato la trae vacía, que es el caso de todo Gestión ARL. Se usa
        // el mismo criterio que las listas de Afiliaciones para que el botón
        // del certificado aparezca en los mismos contratos que allá.
        $contrato->loadMissing('arl');
        $arlEfectivaNombre = self::arlEfectiva(
            $contrato,
            self::arlsPorNitDeContratos(collect([$contrato]))
        );

        return view('admin.contratos.form', array_merge(
            $this->datosFormulario($alidoId, $cliente, $contrato->razon_social_id, $contrato->id),
            compact('contrato', 'cliente', 'backUrl', 'radicadosPorTipo', 'rsBloquedaPorAfiliacion', 'rsDesbloqueoSuperadmin', 'economicoDesbloqueado', 'otrosContratosVigentes', 'tienePlanillaConDias', 'retiroInfoBloqueado', 'retiroInfoForzado', 'rsIrOpciones', 'rsIrPreviewId', 'rsIrHayDisponible', 'diaIngresoIr', 'arlEfectivaNombre')
        ));
    }

    // ─── Actualizar contrato ──────────────────────────────────────────
    public function update(Request $request, int $id)
    {
        $alidoId = session('aliado_id_activo');
        $contrato = Contrato::where('aliado_id', $alidoId)->with('radicados')->findOrFail($id);
        $data = $this->validar($request, $contrato);

        // ── Protección RS por afiliaciones activas (tramite u ok) ──────
        // Si la RS ya tiene afiliaciones en proceso o confirmadas, NO se puede cambiar.
        // La única vía para desligar es marcar retiro del contrato.
        // Excepción: el superadmin sí puede forzarlo (la vista ya le advirtió);
        // el cambio queda registrado en bitácora más abajo.
        $estadosBloqueantes = ['tramite', 'ok'];
        $hayAfiliacionActiva = $contrato->radicados
            ->whereIn('estado', $estadosBloqueantes)
            ->isNotEmpty();

        $puedeForzarBloqueo = Auth::user()->can('contratos.editar_radicado');
        $rsBloquedaPorAfiliacion = $hayAfiliacionActiva && ! $puedeForzarBloqueo;

        if ($rsBloquedaPorAfiliacion &&
            isset($data['razon_social_id']) &&
            (int) $data['razon_social_id'] !== (int) $contrato->razon_social_id) {
            return redirect()
                ->route('admin.contratos.edit', array_filter([
                    $id,
                    'back' => $request->input('back_url'),
                    'iframe' => $request->input('iframe') ? '1' : null,
                ]))
                ->withErrors(['razon_social_id' => 'No se puede cambiar la Razón Social: ya existe una afiliación en trámite u OK. Para cambiarla, marque retiro del contrato.']);
        }

        // ── Contrato ya radicado: los datos de fondo quedan congelados ──
        // Un contrato con afiliación en trámite u OK ya viajó a la EPS/ARL con
        // unos valores concretos. Cambiarlos por detrás deja a Brynex diciendo
        // una cosa y a la entidad otra, y eso solo se descubre cuando rebota la
        // planilla. Entidades y fechas solo las mueve quien tenga
        // `contratos.editar_radicado` (hoy, solo superadmin).
        //
        // Salario e IBC son la excepción: no van en el radicado, cambian lo que
        // se cotiza y se cobra de ahí en adelante, así que el admin también los
        // mueve. El resto del formulario —cargo, motivo de afiliación, envío de
        // planilla, tarifas, asesor, observaciones— sigue editable por
        // cualquiera que pueda editar contratos.
        $puedeEditarEconomico = $this->puedeEditarEconomico();

        if ($rsBloquedaPorAfiliacion) {
            // El nivel de riesgo NO entra aquí: una persona cambia de cargo y su
            // riesgo sube o baja, y eso pasa con la afiliación ya radicada. Lo
            // que cambia es lo que se cotiza y lo que va en la planilla de ahí
            // en adelante, no la afiliación que ya viajó. Queda en bitácora.
            $congelados = [
                'eps_id', 'pension_id', 'arl_id', 'caja_id',
                'fecha_ingreso', 'fecha_retiro', 'fecha_arl',
            ];

            if (! $puedeEditarEconomico) {
                array_unshift($congelados, 'salario', 'ibc');
            }

            $cambiados = [];
            foreach ($congelados as $campo) {
                if (! array_key_exists($campo, $data)) {
                    continue;
                }
                // Comparación laxa a propósito: '1500000' y 1500000.00 son el
                // mismo salario, y las fechas llegan como texto contra Carbon.
                $actual = $contrato->{$campo};
                $actual = $actual instanceof \DateTimeInterface ? $actual->format('Y-m-d') : $actual;
                $nuevo = $data[$campo];

                // El formulario siempre manda un IBC: en los dependientes lo
                // iguala al salario aunque en BD esté en blanco. Eso no es un
                // cambio de fondo, y hacía rebotar el guardado completo de un
                // contrato radicado sin que nadie hubiera tocado nada.
                if ($campo === 'ibc' && blank($actual)) {
                    $salarioRef = $data['salario'] ?? $contrato->salario;
                    if (is_numeric($nuevo) && is_numeric($salarioRef)
                        && (float) $nuevo === (float) $salarioRef) {
                        continue;
                    }
                }

                $iguales = blank($actual) && blank($nuevo);
                if (is_numeric($actual) && is_numeric($nuevo)) {
                    $iguales = $iguales || (float) $actual === (float) $nuevo;
                } else {
                    $iguales = $iguales || (string) $actual === (string) $nuevo;
                }

                if (! $iguales) {
                    $cambiados[] = $campo;
                }
            }

            if ($cambiados) {
                return redirect()
                    ->route('admin.contratos.edit', array_filter([
                        $id,
                        'back' => $request->input('back_url'),
                        'iframe' => $request->input('iframe') ? '1' : null,
                    ]))
                    ->withErrors(['contrato_radicado' => 'Este contrato ya tiene afiliación radicada (trámite u OK): '
                        .implode(', ', $cambiados).' solo los puede cambiar el superadministrador.']);
            }
        }

        // ── Protección de ENTIDADES con radicados activos ──────────────
        // Regla: si el contrato tiene radicados en estado 'tramite' u 'ok'
        // para una entidad (eps, arl, pension, caja), el nuevo plan seleccionado
        // DEBE incluir esa misma entidad. Si el nuevo plan elimina una entidad
        // que ya tiene una afiliación en curso, se bloquea el cambio.
        //
        // Ejemplo BLOQUEADO: contrato con EPS ok → nuevo plan sin EPS.
        // Ejemplo PERMITIDO:  contrato I Venc → I Act (mismo plan, mismas entidades).
        // Ejemplo PERMITIDO:  agregar CAJA a un plan que antes no la tenía.
        $tiposConRadicadoActivo = $contrato->radicados
            ->whereIn('estado', $estadosBloqueantes)
            ->pluck('tipo')
            ->unique()
            ->values()
            ->toArray(); // ej: ['eps', 'arl']

        if (! empty($tiposConRadicadoActivo) && ! $puedeForzarBloqueo && isset($data['plan_id']) && (int) $data['plan_id'] !== (int) $contrato->plan_id) {
            $nuevoPlan = \App\Models\PlanContrato::find($data['plan_id']);
            if ($nuevoPlan) {
                $mapaNuevoPlan = [
                    'eps' => (bool) $nuevoPlan->incluye_eps,
                    'arl' => (bool) $nuevoPlan->incluye_arl,
                    'pension' => (bool) $nuevoPlan->incluye_pension,
                    'caja' => (bool) $nuevoPlan->incluye_caja,
                ];
                $entidadesExcluidas = array_filter(
                    $tiposConRadicadoActivo,
                    fn ($tipo) => ! ($mapaNuevoPlan[$tipo] ?? false)
                );
                if (! empty($entidadesExcluidas)) {
                    $nombresEntidades = array_map(fn ($t) => strtoupper($t), $entidadesExcluidas);

                    return redirect()
                        ->route('admin.contratos.edit', array_filter([
                            $id,
                            'back' => $request->input('back_url'),
                            'iframe' => $request->input('iframe') ? '1' : null,
                        ]))
                        ->withErrors(['plan_id' => 'No se puede cambiar al plan "'.$nuevoPlan->nombre.'": ya existe afiliación activa (tramite/ok) en '.implode(', ', $nombresEntidades).'. El nuevo plan debe incluir esas entidades.']);
                }
            }
        }

        // Protección razón social: solo admin puede cambiarla si está bloqueada
        if ($contrato->razon_social_bloqueada &&
            Auth::user()->hasAnyRole(['usuario']) &&
            isset($data['razon_social_id']) &&
            (int) $data['razon_social_id'] !== (int) $contrato->razon_social_id) {
            unset($data['razon_social_id']);
        }

        // Al first save de razon_social → bloquearla
        if (! $contrato->razon_social_bloqueada && ! empty($data['razon_social_id'])) {
            $data['razon_social_bloqueada'] = true;
        }

        // Auto-derivar nit cotizante ARL si no vino explícito del formulario
        if (empty($data['arl_nit_cotizante'])) {
            $rsId = $data['razon_social_id'] ?? $contrato->razon_social_id;
            $cedula = $data['cedula'] ?? $contrato->cedula;
            $modo = $data['arl_modo'] ?? $contrato->arl_modo;
            if ($modo === 'razon_social' && ! empty($rsId)) {
                $data['arl_nit_cotizante'] = (int) $rsId;
            } elseif ($modo === 'independiente' && ! empty($cedula)) {
                $data['arl_nit_cotizante'] = (int) $cedula;
            }
        }

        // Proteger plan_id: si llega vacío, conservar el plan original del contrato
        if (empty($data['plan_id']) && $contrato->plan_id) {
            $data['plan_id'] = $contrato->plan_id;
        }

        // Limpiar entidades que no aplican según el plan seleccionado
        // (evita que queden eps_id/pension_id/arl_id/caja_id con valores cuando el plan no los cubre)
        $planId = $data['plan_id'] ?? $contrato->plan_id;
        if ($planId) {
            $plan = \App\Models\PlanContrato::find($planId);
            if ($plan) {
                if (! $plan->incluye_eps) {
                    $data['eps_id'] = null;
                }
                if (! $plan->incluye_pension) {
                    $data['pension_id'] = null;
                }
                if (! $plan->incluye_arl) {
                    $data['arl_id'] = null;
                }
                if (! $plan->incluye_caja) {
                    $data['caja_id'] = null;
                }
            }
        }

        $data = $this->completarArlDesdeRazonSocial($data, $contrato);

        // ── Rastro de cambios forzados por superadmin ──────────────────
        // Si el contrato tenía afiliaciones activas y el usuario es superadmin,
        // los campos que normalmente estarían bloqueados sí pudieron cambiar.
        // Se deja constancia en bitácora de qué se tocó y quién lo tocó.
        $cambiosForzados = [];
        if ($hayAfiliacionActiva && ($puedeForzarBloqueo || $puedeEditarEconomico)) {
            $camposProtegidos = $puedeForzarBloqueo
                ? ['razon_social_id', 'plan_id', 'fecha_ingreso', 'tipo_modalidad_id', 'encargado_id', 'salario', 'ibc']
                : ['encargado_id', 'salario', 'ibc'];
            foreach ($camposProtegidos as $campo) {
                if (! array_key_exists($campo, $data)) {
                    continue;
                }
                $viejo = $contrato->$campo;
                $nuevo = $data[$campo];
                if ($campo === 'fecha_ingreso') {
                    $viejo = $viejo ? \Carbon\Carbon::parse($viejo)->format('Y-m-d') : null;
                    $nuevo = $nuevo ? \Carbon\Carbon::parse($nuevo)->format('Y-m-d') : null;
                } else {
                    $viejo = $viejo === null ? null : (int) $viejo;
                    $nuevo = $nuevo === null || $nuevo === '' ? null : (int) $nuevo;
                }
                if ($viejo !== $nuevo) {
                    $cambiosForzados[$campo] = ['old' => $viejo, 'new' => $nuevo];
                }
            }
        }

        // Cambio de nivel de riesgo: cualquiera lo puede mover, pero mueve lo que
        // se cotiza y lo que se le reporta a la ARL, así que queda registrado.
        $cambioNivelArl = null;
        if (array_key_exists('n_arl', $data) && (int) $data['n_arl'] !== (int) $contrato->n_arl) {
            $cambioNivelArl = ['old' => (int) $contrato->n_arl, 'new' => (int) $data['n_arl']];
        }

        $avisoMesActual = null;

        DB::transaction(function () use ($contrato, $data, $alidoId, $cambiosForzados, $cambioNivelArl, &$avisoMesActual) {
            $oldPlanId = $contrato->plan_id;

            // Detectar cambios en campos sensibles de tarifa
            $cambios = [];
            foreach (['administracion', 'admon_asesor', 'costo_afiliacion', 'seguro'] as $campo) {
                if (array_key_exists($campo, $data)) {
                    $oldVal = (float) ($contrato->$campo ?? 0);
                    $newVal = (float) ($data[$campo] ?? 0);
                    if ($oldVal !== $newVal) {
                        $cambios[$campo] = [
                            'old' => $oldVal,
                            'new' => $newVal,
                        ];
                    }
                }
            }

            // El cambio de período no se puede deshacer solo: deja un mes
            // duplicado o uno sin cotizar. Se avisa y queda en la bitácora.
            $avisoMesActual = $this->avisoCambioMesActual($contrato, $data);

            $contrato->update($data);

            if ($avisoMesActual) {
                \App\Models\Bitacora::registrar(
                    'updated', 'Contrato', $contrato->id,
                    'Período de cotización cambiado a '.($contrato->paga_mes_actual ? 'mes actual' : 'mes vencido')
                        ." (Cédula: {$contrato->cedula}). {$avisoMesActual}",
                    ['paga_mes_actual' => ['antes' => ! $contrato->paga_mes_actual, 'despues' => (bool) $contrato->paga_mes_actual]],
                    $alidoId
                );
            }

            if (! empty($cambios)) {
                \App\Models\Bitacora::registrar(
                    'updated', 'Contrato', $contrato->id,
                    "Tarifas de contrato modificadas (Cédula: {$contrato->cedula}).",
                    ['cambios' => $cambios],
                    $alidoId
                );
            }

            if ($cambioNivelArl) {
                \App\Models\Bitacora::registrar(
                    'updated', 'Contrato', $contrato->id,
                    "Nivel de riesgo ARL cambiado de {$cambioNivelArl['old']} a {$cambioNivelArl['new']} (Cédula: {$contrato->cedula}).",
                    ['n_arl' => $cambioNivelArl],
                    $alidoId
                );
            }

            if (! empty($cambiosForzados)) {
                \App\Models\Bitacora::registrar(
                    'updated', 'Contrato', $contrato->id,
                    'Se modificaron campos protegidos de un contrato con afiliaciones en trámite u OK (Cédula: '.$contrato->cedula.').',
                    ['cambios_forzados' => $cambiosForzados],
                    $alidoId
                );
            }

            // Si cambio el plan, agregar nuevos radicados pendientes
            if (isset($data['plan_id']) && $data['plan_id'] != $oldPlanId) {
                $contrato->load('plan');
                $contrato->crearRadicadosPendientes();
            }
        });

        // Si la RS es independiente y viene operador_planilla_id, guardarlo en el cliente
        $operadorIdUpd = $request->input('operador_planilla_id');
        if ($operadorIdUpd !== null) {
            $rsIdUpd = $data['razon_social_id'] ?? $contrato->razon_social_id;
            $esIndepRSUpd = $rsIdUpd && DB::table('razones_sociales')
                ->where('id', $rsIdUpd)->value('es_independiente');
            if ($esIndepRSUpd) {
                $cedUpd = $data['cedula'] ?? $contrato->cedula;
                if ($cedUpd) {
                    Cliente::where('cedula', $cedUpd)
                        ->where('aliado_id', $alidoId)
                        ->update(['operador_planilla_id' => $operadorIdUpd ?: null]);
                }
            }
        }

        $redirectParams = [$id, 'back' => $request->input('back_url')];
        if ($request->input('iframe')) {
            $redirectParams['iframe'] = '1';
        }

        $redirect = redirect()
            ->route('admin.contratos.edit', $redirectParams)
            ->with('success', 'Contrato actualizado correctamente.');

        return $avisoMesActual
            ? $redirect->with('warning', $avisoMesActual)
            : $redirect;
    }

    /**
     * Quién puede mover salario, IBC y encargado de un contrato ya radicado.
     *
     * Esos tres no viajan en el radicado —la afiliación a EPS/ARL ya se hizo—:
     * lo que cambian es lo que se cotiza y se cobra de ahí en adelante, así que
     * el admin también los mueve. Entidades y fechas sí son datos de fondo y
     * siguen pidiendo `contratos.editar_radicado` (hoy, solo superadmin).
     */
    private function puedeEditarEconomico(): bool
    {
        return Auth::user()->can('contratos.editar_radicado')
            || Auth::user()->hasRole('admin');
    }

    /**
     * Qué le pasa al calendario de cotización de un contrato al que se le cambia
     * el período de pago: o el mes que sigue ya está cubierto por un plano
     * —quedaría duplicado en la planilla— o se salta uno que nadie cotizó.
     *
     * Devuelve null si el flag no cambió, si el contrato no está vigente o si
     * todavía no tiene planillas: sin historial no hay salto que avisar.
     */
    private function avisoCambioMesActual(Contrato $contrato, array $data): ?string
    {
        if (! array_key_exists('paga_mes_actual', $data)) {
            return null;
        }

        $antes = (bool) $contrato->paga_mes_actual;
        $despues = (bool) $data['paga_mes_actual'];

        if ($antes === $despues || ! $contrato->estaVigente()) {
            return null;
        }

        // Último período que el contrato ya tiene cubierto, en meses absolutos.
        $ultimo = DB::table('planos')
            ->where('contrato_id', $contrato->id)
            ->whereNull('deleted_at')
            ->whereIn('tipo_reg', ['planilla', 'retiro'])
            ->whereRaw('ISNULL(num_dias, 0) > 0')
            ->max(DB::raw('anio_plano * 12 + mes_plano'));

        if (! $ultimo) {
            return null;
        }

        // Período que cubriría la próxima factura con el esquema nuevo.
        $hoy = now('America/Bogota');
        [$mesProx, $anioProx] = Plano::periodoPlano((int) $hoy->month, (int) $hoy->year, $despues, false);
        $proximo = $anioProx * 12 + $mesProx;

        $comoMes = fn (int $abs) => sprintf('%02d/%d', (($abs - 1) % 12) + 1, intdiv($abs - 1, 12));

        if ($proximo <= $ultimo) {
            return sprintf(
                'El período %s ya está cubierto por un plano de este contrato: al facturar quedaría dos veces en la misma planilla.',
                $comoMes($proximo)
            );
        }

        if ($proximo > $ultimo + 1) {
            $faltantes = [];
            for ($m = $ultimo + 1; $m < $proximo; $m++) {
                $faltantes[] = $comoMes($m);
            }

            return sprintf(
                'Queda sin cotizar %s: la última planilla cubrió %s y la próxima cubrirá %s.',
                implode(', ', $faltantes), $comoMes($ultimo), $comoMes($proximo)
            );
        }

        return null;
    }

    // ─── Retirar contrato ─────────────────────────────────────────────
    public function retirar(Request $request, int $id)
    {
        $alidoId = session('aliado_id_activo');
        $contrato = Contrato::where('aliado_id', $alidoId)
            ->with(['eps', 'arl', 'pension', 'caja', 'tipoModalidad', 'razonSocial', 'cliente', 'plan'])
            ->findOrFail($id);

        $validated = $request->validate([
            'motivo_retiro_id' => 'required|exists:motivos_retiro,id',
            'fecha_retiro' => 'required|date',
            'tipo_retiro' => 'required|in:real,informativo',
            'num_dias' => 'nullable|integer|min:0|max:30',
            'mes_plano' => 'required|integer|between:1,12',
            'anio_plano' => 'required|integer|min:2020|max:2099',
            'observacion' => 'nullable|string|max:500',
            'valor_ss' => 'nullable|numeric|min:0',
            'mora' => 'nullable|numeric|min:0',
        ]);

        $tipoRetiro = $validated['tipo_retiro'];
        $fechaRetiro = $validated['fecha_retiro'];
        $numDias = $tipoRetiro === 'real'
            ? max(1, min(30, (int) ($validated['num_dias'] ?? 1)))
            : 0;

        // Por seguridad: bloquear retiro informativo si tiene planillas con días > 0
        // Excepción 1: contratos de RS independiente (es_independiente=1) siempre pueden
        // hacer retiro informativo porque el cliente paga la SS por sus propios medios.
        // Excepción 2: el superadmin puede forzarlo — hay contratos migrados y otros a
        // los que ya se les marcó el retiro en la planilla fuera del sistema. Queda en
        // bitácora quién lo hizo.
        $retiroInfoForzado = false;
        if ($tipoRetiro === 'informativo') {
            $rsRetiroCheck = $contrato->razonSocial;
            $esIndependienteRetiro = $contrato->esIndependiente() || ($rsRetiroCheck && $rsRetiroCheck->es_independiente);

            if (! $esIndependienteRetiro) {
                $tienePlanillaConDias = \App\Models\Factura::where('contrato_id', $contrato->id)
                    ->where('tipo', 'planilla')
                    ->where('dias_cotizados', '>', 0)
                    ->where('numero_factura', '>', 0)
                    ->exists();
                if ($tienePlanillaConDias) {
                    if (! Auth::user()->hasRole('superadmin')) {
                        $motivo = 'No se puede aplicar retiro informativo porque el contrato ya tiene planillas pagadas con días cotizados.';

                        if ($request->expectsJson()) {
                            return response()->json(['ok' => false, 'mensaje' => $motivo], 422);
                        }

                        return redirect()
                            ->route('admin.contratos.edit', [$id, 'back' => $request->input('back_url')])
                            ->withErrors(['tipo_retiro' => $motivo]);
                    }
                    $retiroInfoForzado = true;
                }
            }
        }

        // Validar que mes_plano sea exactamente el periodo consecutivo permitido
        $ultimoPlano = DB::table('planos')
            ->where('contrato_id', $contrato->id)
            ->where('num_dias', '>', 0)
            ->whereNull('deleted_at')
            ->orderBy('anio_plano', 'desc')
            ->orderBy('mes_plano', 'desc')
            ->first();

        $mesEsperado = null;
        $anioEsperado = null;

        if ($ultimoPlano) {
            $mesEsperado = (int) $ultimoPlano->mes_plano + 1;
            $anioEsperado = (int) $ultimoPlano->anio_plano;
            if ($mesEsperado > 12) {
                $mesEsperado = 1;
                $anioEsperado++;
            }
        } else {
            if ($contrato->fecha_ingreso) {
                $ingreso = \Carbon\Carbon::parse($contrato->fecha_ingreso);
                $mesEsperado = $ingreso->month;
                $anioEsperado = $ingreso->year;
            } else {
                $mesEsperado = now()->month;
                $anioEsperado = now()->year;
            }
        }

        if ((int) $validated['mes_plano'] !== $mesEsperado || (int) $validated['anio_plano'] !== $anioEsperado) {
            $mesesNombres = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
            $nombreMes = $mesesNombres[$mesEsperado] ?? '';

            $motivo = "El retiro debe aplicarse exactamente en el periodo consecutivo: {$nombreMes} de {$anioEsperado}. No se permiten saltos de periodos sin planilla.";

            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'mensaje' => $motivo], 422);
            }

            return redirect()
                ->route('admin.contratos.edit', [$id, 'back' => $request->input('back_url')])
                ->withErrors(['mes_plano' => $motivo]);
        }

        // ── Calcular SS del retiro real usando calcularCotizacion() del modelo ─
        $vEpsRetiro = 0;
        $vArlRetiro = 0;
        $vAfpRetiro = 0;
        $vCajaRetiro = 0;
        $totalSsRetiro = 0;

        if ($tipoRetiro === 'real' && $numDias > 0) {
            // Fallback para contratos legacy donde ambos campos son 0: usar SM.
            // Si solo ibc=0 (dependiente) o solo salario=0 (independiente),
            // calcularCotizacion() lo resuelve internámente según modalidad.
            $ibcOriginal = (float) ($contrato->ibc ?? 0);
            $salOriginal = (float) ($contrato->salario ?? 0);
            if ($ibcOriginal <= 0 && $salOriginal <= 0) {
                // Ninguna base registrada → usar salario mínimo del sistema
                $sm = (float) ConfiguracionBrynex::obtener('salario_minimo', 1423500);
                $contrato->ibc = $sm;
                $contrato->salario = $sm;
            }

            // Una sola llamada — misma fuente de verdad que la facturación normal
            $cotizacion = $contrato->calcularCotizacion($numDias);
            $vEpsRetiro = (int) ($cotizacion['eps'] ?? 0);
            $vArlRetiro = (int) ($cotizacion['arl'] ?? 0);
            $vAfpRetiro = (int) ($cotizacion['pen'] ?? 0);
            $vCajaRetiro = (int) ($cotizacion['caja'] ?? 0);
            $totalSsRetiro = $vEpsRetiro + $vArlRetiro + $vAfpRetiro + $vCajaRetiro;

            // Si el usuario ingresó un valor total manual en el modal, lo usamos y distribuimos proporcionalmente
            if ($request->has('valor_ss') && ! is_null($request->input('valor_ss'))) {
                $valorSsManual = (int) $request->input('valor_ss');
                if ($valorSsManual >= 0) {
                    if ($totalSsRetiro > 0) {
                        $factor = $valorSsManual / $totalSsRetiro;
                        $vEpsRetiro = (int) round($vEpsRetiro * $factor);
                        $vArlRetiro = (int) round($vArlRetiro * $factor);
                        $vAfpRetiro = (int) round($vAfpRetiro * $factor);
                        $vCajaRetiro = (int) round($vCajaRetiro * $factor);

                        // Ajustar remanentes
                        $sumaTemp = $vEpsRetiro + $vArlRetiro + $vAfpRetiro + $vCajaRetiro;
                        $diff = $valorSsManual - $sumaTemp;
                        if ($diff !== 0) {
                            if ($vEpsRetiro > 0) {
                                $vEpsRetiro += $diff;
                            } elseif ($vAfpRetiro > 0) {
                                $vAfpRetiro += $diff;
                            } elseif ($vArlRetiro > 0) {
                                $vArlRetiro += $diff;
                            } else {
                                $vEpsRetiro += $diff;
                            }
                        }
                    } else {
                        $vEpsRetiro = $valorSsManual;
                    }
                    $totalSsRetiro = $valorSsManual;
                }
            }

            // Restaurar valores originales (evita mutar el objeto si se reutiliza)
            $contrato->ibc = $ibcOriginal;
            $contrato->salario = $salOriginal;
        }

        // ── Mora real del retiro (sin tramos mínimos) ─────────────────────────
        $moraRetiro = 0;
        $esMesActual = (bool) ($contrato->paga_mes_actual ?? false);

        if ($request->has('mora') && ! is_null($request->input('mora'))) {
            $moraRetiro = (int) $request->input('mora');
        } else {
            try {
                $rsNitRet = $contrato->nitParaMora();
                $rsDiaHRet = $contrato->diaHabilParaMora();

                $mesRet = (int) ($validated['mes_plano'] ?? now()->month);
                $anioRet = (int) ($validated['anio_plano'] ?? now()->year);

                if ($tipoRetiro === 'real') {
                    if ($esMesActual) {
                        $mesVence = $mesRet;
                        $anioVence = $anioRet;
                    } else {
                        // La planilla de mes_plano (periodo cotizado) vence y se paga en el mes siguiente
                        $mesVence = $mesRet + 1;
                        $anioVence = $anioRet;
                        if ($mesVence > 12) {
                            $mesVence = 1;
                            $anioVence++;
                        }
                    }
                } else {
                    // Retiro Informativo: vence en el mismo mes del plano (o no aplica mora)
                    $mesVence = $mesRet;
                    $anioVence = $anioRet;
                }

                if ($rsNitRet && $totalSsRetiro > 0) {
                    $periodoActualNum = now()->year * 100 + now()->month;
                    $periodoVenceNum = $anioVence * 100 + $mesVence;

                    if ($periodoVenceNum > $periodoActualNum) {
                        $moraRetiro = 0;
                    } else {
                        $moraInfo = MoraClienteService::calcular($alidoId, $rsNitRet, $rsDiaHRet, $totalSsRetiro, $mesVence, $anioVence);
                        $moraRetiro = (int) round($moraInfo['mora_real'] ?? 0); // solo el interés real
                    }
                }
            } catch (\Throwable) {
            }
        }

        $mesFactura = (int) $validated['mes_plano'];
        $anioFactura = (int) $validated['anio_plano'];
        if ($tipoRetiro === 'real' && ! $esMesActual) {
            $mesFactura++;
            if ($mesFactura > 12) {
                $mesFactura = 1;
                $anioFactura++;
            }
        }

        DB::transaction(function () use ($contrato, $validated, $alidoId, $fechaRetiro, $numDias,
            $vEpsRetiro, $vArlRetiro, $vAfpRetiro, $vCajaRetiro, $totalSsRetiro, $moraRetiro,
            $mesFactura, $anioFactura) {
            // 1) Actualizar contrato → retirado
            $contrato->update([
                'estado' => 'retirado',
                'motivo_retiro_id' => $validated['motivo_retiro_id'],
                'fecha_retiro' => $fechaRetiro,
                'observacion' => $validated['observacion'] ?? $contrato->observacion,
            ]);

            // 2) n_plano del retiro = plano actual de la RS.
            //    NOTA: El plano 100 es exclusivo del flujo "Duplicar Contrato" (IR rotation).
            //    El retiro normal — incluso en IR (id=12) — usa el n_plano de la RS.
            //    Se calcula ANTES de Factura::create() para que la factura también lo reciba.
            $nPlano = $contrato->razon_social_id
                ? (\App\Models\RazonSocial::find($contrato->razon_social_id)?->n_plano ?? 1)
                : 1;

            // 3) Crear factura de retiro (numero_factura=0, total=$0, pero SS calculado)
            //    El total sigue en $0 porque el dinero no entró como ingreso.
            //    Los campos v_eps/v_arl/v_afp/v_caja reflejan el COSTO del retiro en SS.
            //    Se excluyen de ingresos en informes filtrando WHERE numero_factura = 0.
            $factura = \App\Models\Factura::create([
                'aliado_id' => $alidoId,
                'numero_factura' => 0,
                'tipo' => 'planilla',
                'cedula' => $contrato->cedula,
                'contrato_id' => $contrato->id,
                'razon_social_id' => $contrato->razon_social_id,
                'empresa_id' => null,
                'mes' => $mesFactura,
                'anio' => $anioFactura,
                'fecha_pago' => now()->toDateString(),
                'estado' => 'pagada',
                'forma_pago' => 'efectivo',
                'valor_efectivo' => 0,
                'valor_consignado' => 0,
                'valor_prestamo' => 0,
                'otros' => $moraRetiro,  // mora real informativa para el aliado
                'otros_admon' => 0,
                'mensajeria' => 0,
                'dias_cotizados' => $numDias,
                'v_eps' => $vEpsRetiro,
                'v_arl' => $vArlRetiro,
                'v_afp' => $vAfpRetiro,
                'v_caja' => $vCajaRetiro,
                'total_ss' => $totalSsRetiro,
                'mora' => $moraRetiro,  // campo dedicado mora (no es ingreso)
                'admon' => 0,
                'admin_asesor' => 0,
                'seguro' => 0,
                'afiliacion' => 0,
                'iva' => 0,
                'total' => 0,   // el cliente no paga
                'saldo_proximo' => 0,
                'n_plano' => $nPlano, // ← FIX: la factura también debe tener el n_plano
                'usuario_id' => Auth::id(),
                'observacion' => $validated['observacion'] ?? null,
            ]);

            // 4) Mes/año del plano:
            //    validated['mes_plano'] = mes de cotización (vencido) que ingresa el usuario.
            //    El módulo de planos con mes=7 busca mes_plano=6 (mesVencido = mes-1),
            //    que coincide con validated['mes_plano'] cuando el usuario ingresa Junio=6
            //    y la factura queda registrada en Julio (mesFactura = mes_plano + 1).
            //    NO se resta 1: validated['mes_plano'] ya ES el mes de cotización correcto.
            $mesPlan = (int) $validated['mes_plano'];
            $anioPlan = (int) $validated['anio_plano'];

            // 4) Crear plano con fecha_ret y num_dias
            $cliente = $contrato->cliente;
            $eps = $contrato->eps;
            $afp = $contrato->pension;
            $arl = $contrato->arl;
            $caja = $contrato->caja;
            $rs = $contrato->razonSocial;

            $arlSnapshot = \App\Models\Plano::resolverArlSnapshot($contrato, $rs);
            $codArl = $arlSnapshot['cod_arl'];
            $nombreArl = $arlSnapshot['nombre_arl'];

            $apellidos = $cliente?->apellidos ?? trim(($cliente?->primer_apellido ?? '').' '.($cliente?->segundo_apellido ?? ''));
            $nombres = $cliente?->nombres ?? trim(($cliente?->primer_nombre ?? '').' '.($cliente?->segundo_nombre ?? ''));
            $partsApe = preg_split('/\s+/', trim($apellidos), 2);
            $partsNom = preg_split('/\s+/', trim($nombres), 2);

            \App\Models\Plano::create([
                'factura_id' => $factura->id,
                'contrato_id' => $contrato->id,
                'aliado_id' => $alidoId,
                'numero_factura' => 0,
                'tipo_reg' => 'retiro',
                'tipo_doc' => strtoupper(trim($cliente?->tipo_doc ?? 'CC')) ?: 'CC',
                'no_identifi' => $contrato->cedula,
                'primer_ape' => strtoupper($partsApe[0] ?? ''),
                'segundo_ape' => strtoupper($partsApe[1] ?? ''),
                'primer_nombre' => strtoupper($partsNom[0] ?? ''),
                'segundo_nombre' => strtoupper($partsNom[1] ?? ''),
                'fecha_ing' => null,
                'fecha_ret' => \Carbon\Carbon::parse($fechaRetiro)->toDateString(),
                'num_dias' => $numDias,
                'cod_eps' => $eps?->nit ?? $eps?->cod_eps ?? null,
                'nombre_eps' => $eps?->nombre ?? null,
                'cod_afp' => $afp?->nit ?? $afp?->cod_afp ?? null,
                'nombre_afp' => $afp?->razon_social ?? null,
                'cod_arl' => $codArl,
                'nombre_arl' => $nombreArl,
                'cod_caja' => $caja?->nit ?? $caja?->cod_caja ?? null,
                'nombre_caja' => $caja?->nombre ?? null,
                'nivel_riesgo' => $contrato->n_arl ?? 1,
                'salario_basico' => $contrato->salario ?? 0,
                'n_plano' => $nPlano,
                'mes_plano' => $mesPlan,
                'anio_plano' => $anioPlan,
                'razon_social' => $rs?->razon_social ?? null,
                'razon_social_id' => $contrato->razon_social_id,
                'tipo_p' => $contrato->tipo_modalidad_id,
                'tipo_modalidad_id' => $contrato->tipo_modalidad_id,
                'usuario_id' => Auth::id(),
            ]);
        });

        // Se registra ya aplicado el retiro: si la transacción falla, relanza y
        // no se llega aquí.
        if ($retiroInfoForzado) {
            \App\Models\Bitacora::registrar(
                accion: 'updated',
                modelo: 'Contrato',
                registroId: $contrato->id,
                descripcion: "Superadmin aplicó retiro informativo a un contrato con planillas pagadas con días cotizados (Cédula: {$contrato->cedula}). Fecha de retiro: {$fechaRetiro}.",
                detalle: [
                    'cedula' => $contrato->cedula,
                    'fecha_retiro' => $fechaRetiro,
                    'razon_social_id' => $contrato->razon_social_id,
                    'plan_id' => $contrato->plan_id,
                    'mes_plano' => $validated['mes_plano'] ?? null,
                    'anio_plano' => $validated['anio_plano'] ?? null,
                    'motivo_retiro_id' => $validated['motivo_retiro_id'] ?? null,
                    'observacion' => $validated['observacion'] ?? null,
                ],
                alidoId: $alidoId
            );
        }

        $retiroParams = [$id, 'back' => $request->input('back_url')];
        if ($request->input('iframe')) {
            $retiroParams['iframe'] = '1';
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'mensaje' => 'Contrato retirado correctamente.']);
        }

        return redirect()
            ->route('admin.contratos.edit', $retiroParams)
            ->with('success', 'Contrato retirado correctamente.');
    }

    // ─── API: Calcular Costo Retiro y Mora (devuelve JSON) ───────────
    public function apiCalcularRetiro(Request $request, int $contratoId)
    {
        $aliadoId = session('aliado_id_activo');
        $contrato = Contrato::where('aliado_id', $aliadoId)
            ->with(['eps', 'arl', 'pension', 'caja', 'tipoModalidad', 'razonSocial', 'cliente'])
            ->findOrFail($contratoId);

        $dias = (int) $request->get('dias', 1);
        $mesPlano = (int) $request->get('mes_plano', now()->month);
        $anioPlano = (int) $request->get('anio_plano', now()->year);
        $tipoRetiro = $request->get('tipo_retiro', 'real');

        $costoSs = 0;
        $mora = 0;
        $esMesActual = (bool) ($contrato->paga_mes_actual ?? false);

        $desglose = [
            'eps' => ['valor' => 0, 'mora' => 0],
            'arl' => ['valor' => 0, 'mora' => 0],
            'pen' => ['valor' => 0, 'mora' => 0],
            'caja' => ['valor' => 0, 'mora' => 0],
        ];

        if ($tipoRetiro === 'real' && $dias > 0) {
            $ibcOriginal = (float) ($contrato->ibc ?? 0);
            $salOriginal = (float) ($contrato->salario ?? 0);
            // Fallback solo cuando ambos son 0 (legacy sin ningún dato).
            // Si solo ibc=0 (dependiente) o solo salario=0 (independiente),
            // calcularCotizacion() lo resuelve internámente según modalidad.
            if ($ibcOriginal <= 0 && $salOriginal <= 0) {
                $sm = (float) \App\Models\ConfiguracionBrynex::obtener('salario_minimo', 1423500);
                $contrato->ibc = $sm;
                $contrato->salario = $sm;
            }

            $cotizacion = $contrato->calcularCotizacion($dias);
            $vEps = (int) ($cotizacion['eps'] ?? 0);
            $vArl = (int) ($cotizacion['arl'] ?? 0);
            $vPen = (int) ($cotizacion['pen'] ?? 0);
            $vCaja = (int) ($cotizacion['caja'] ?? 0);
            $costoSs = $vEps + $vArl + $vPen + $vCaja;

            // Restaurar
            $contrato->ibc = $ibcOriginal;
            $contrato->salario = $salOriginal;

            // Calcular mora
            if ($esMesActual) {
                $mesVence = $mesPlano;
                $anioVence = $anioPlano;
            } else {
                $mesVence = $mesPlano + 1;
                $anioVence = $anioPlano;
                if ($mesVence > 12) {
                    $mesVence = 1;
                    $anioVence++;
                }
            }

            $rsNitRet = $contrato->nitParaMora();
            $rsDiaHRet = $contrato->diaHabilParaMora();

            if ($rsNitRet && $costoSs > 0) {
                $periodoActualNum = now()->year * 100 + now()->month;
                $periodoVenceNum = $anioVence * 100 + $mesVence;

                if ($periodoVenceNum > $periodoActualNum) {
                    $mora = 0;
                } else {
                    $moraInfo = \App\Services\MoraClienteService::calcular($aliadoId, $rsNitRet, $rsDiaHRet, $costoSs, $mesVence, $anioVence);
                    $mora = (int) round($moraInfo['mora_real'] ?? 0);
                }
            }

            // Prorratear la mora proporcionalmente por entidad
            $mEps = 0;
            $mArl = 0;
            $mPen = 0;
            $mCaja = 0;
            if ($mora > 0 && $costoSs > 0) {
                $mEps = (int) round($mora * ($vEps / $costoSs));
                $mArl = (int) round($mora * ($vArl / $costoSs));
                $mPen = (int) round($mora * ($vPen / $costoSs));
                $mCaja = (int) round($mora * ($vCaja / $costoSs));

                // Ajustar remanentes con la diferencia
                $sumaMora = $mEps + $mArl + $mPen + $mCaja;
                $diff = $mora - $sumaMora;
                if ($diff !== 0) {
                    if ($vPen >= $vEps && $vPen >= $vArl && $vPen >= $vCaja) {
                        $mPen += $diff;
                    } elseif ($vEps >= $vArl && $vEps >= $vCaja) {
                        $mEps += $diff;
                    } else {
                        $mArl += $diff;
                    }
                }
            }

            $desglose = [
                'eps' => ['valor' => $vEps,  'mora' => $mEps],
                'arl' => ['valor' => $vArl,  'mora' => $mArl],
                'pen' => ['valor' => $vPen,  'mora' => $mPen],
                'caja' => ['valor' => $vCaja, 'mora' => $mCaja],
            ];
        }

        return response()->json([
            'ok' => true,
            'costo_ss' => $costoSs,
            'mora' => $mora,
            'desglose' => $desglose,
        ]);
    }

    // ─── API: Cotizador (devuelve JSON) ───────────────────────────────
    public function cotizar(Request $request)
    {
        $alidoId = session('aliado_id_activo');

        $resultado = \App\Services\CotizadorService::calcular($request->all(), $alidoId);
        unset($resultado['plan_nombre'], $resultado['tipo_modalidad_nombre']);

        return response()->json($resultado);
    }

    // ─── API: Cargar tarifas del aliado por plan ──────────────────────

    /**
     * Tarifas para precargar el formulario de contrato.
     *
     * Con modalidad y riesgo resuelve el tarifario fino (plan × modalidad × riesgo) y, si viene
     * un asesor, cuánto le toca a él de la afiliación y de la administración — ver
     * TarifaAsesorService y docs/plan-tarifario-asesores.md.
     *
     * Sin esos parámetros (o sin asesor) responde exactamente como antes, para que nada del
     * formulario dependa de que el tarifario esté configurado.
     */
    public function tarifasPorPlan(Request $request)
    {
        $alidoId = (int) session('aliado_id_activo');
        $planId = (int) $request->get('plan_id');
        $tarifas = Contrato::tarifasParaAliado($alidoId, $planId);

        $modalidadId = $request->filled('tipo_modalidad_id') ? (int) $request->get('tipo_modalidad_id') : null;
        $nivelArl = (int) ($request->get('n_arl') ?: 1);

        // Sin modalidad no hay celda que resolver: se responde el comportamiento de siempre.
        if (! $planId || $modalidadId === null) {
            return response()->json($tarifas);
        }

        $asesor = null;
        if ($request->filled('asesor_id')) {
            $asesor = \App\Models\Asesor::where('aliado_id', $alidoId)
                ->find((int) $request->get('asesor_id'));
        }

        $d = TarifaAsesorService::desglose($alidoId, $asesor, $planId, $modalidadId, $nivelArl);

        // La admon que se escribe en el contrato es la de la EMPRESA: el total menos lo del
        // asesor, igual que hace hoy el formulario al elegir asesor.
        $tarifas['administracion'] = $d['admon_aliado'];
        $tarifas['admon_total'] = $d['admon_total'];
        $tarifas['admon_asesor'] = $asesor ? $d['admon_asesor'] : 0;
        $tarifas['costo_afiliacion'] = $d['publico'];

        // NULL cuando no hay asesor: ese null es el que mantiene a la facturación en su
        // camino de siempre (ver la migración de contratos.afiliacion_asesor).
        $tarifas['afiliacion_asesor'] = $asesor ? $d['asesor'] : null;

        $tarifas['desglose'] = [
            'retiro' => $d['retiro'],
            'otros' => $d['otros'],
            'aliado' => $d['aliado'],
            'origen' => $d['origen_asesor'],
            'descuadrada' => $d['descuadrada'],
        ];

        // Aviso informativo: en IR y Gestión ARL, si el cliente ya tuvo una afiliación pagada,
        // al facturar se le pagará al asesor la admon y no la comisión de afiliación.
        $tarifas['es_renovacion'] = $request->filled('cedula')
            && TarifaAsesorService::esRenovacion($alidoId, $request->get('cedula'), $modalidadId);

        return response()->json($tarifas);
    }

    // ─── Actualizar estado de radicado (AJAX) ─────────────────────────
    public function actualizarRadicado(Request $request, int $radicadoId)
    {
        $alidoId = session('aliado_id_activo');
        $radicado = Radicado::where('aliado_id', $alidoId)->findOrFail($radicadoId);

        $data = $request->validate([
            'estado' => 'sometimes|in:pendiente,en_tramite,confirmado,rechazado',
            'numero_radicado' => 'sometimes|nullable|string|max:80',
            'canal_envio' => 'sometimes|nullable|in:web,correo,asesor,presencial,otro',
            'enviado_al_cliente' => 'sometimes|boolean',
            'canal_envio_cliente' => 'sometimes|nullable|in:correo,whatsapp,fisica,otro',
            'observacion' => 'sometimes|nullable|string|max:500',
        ]);

        if (isset($data['estado'])) {
            if ($data['estado'] === 'en_tramite' && ! $radicado->fecha_inicio_tramite) {
                $data['fecha_inicio_tramite'] = now();
            }
            if ($data['estado'] === 'confirmado' && ! $radicado->fecha_confirmacion) {
                $data['fecha_confirmacion'] = now();
            }
        }

        if (isset($data['enviado_al_cliente']) && $data['enviado_al_cliente'] && ! $radicado->fecha_envio_cliente) {
            $data['fecha_envio_cliente'] = now();
        }

        $data['user_id'] = Auth::id();
        $radicado->update($data);

        return response()->json(['ok' => true, 'radicado' => $radicado->fresh()]);
    }

    // ─── Datos comunes del formulario ─────────────────────────────────
    private function datosFormulario(int $alidoId, ?object $cliente = null, ?int $razonSocialId = null, ?int $excludeContratoId = null): array
    {
        // Planos ya generados para este contrato (para inhabilitar meses del plano en retiros)
        // Solo inhabilitamos meses que tengan planillas con más de 0 días (evita bloquear por afiliaciones de 0 días).
        $planosExistentes = [];
        if ($excludeContratoId) {
            $planosExistentes = DB::table('planos')
                ->where('contrato_id', $excludeContratoId)
                ->where('num_dias', '>', 0)
                ->whereNull('deleted_at')
                ->select('mes_plano', 'anio_plano')
                ->get()
                ->map(fn ($p) => ['mes' => (int) $p->mes_plano, 'anio' => (int) $p->anio_plano])
                ->toArray();
        }

        // ARL predeterminada de la razón social (por arl_nit)
        $arlIdRazonSocial = null;
        if ($razonSocialId) {
            $arlNit = DB::table('razones_sociales')->where('id', $razonSocialId)->value('arl_nit');
            if ($arlNit) {
                $arlIdRazonSocial = DB::table('arls')->where('nit', $arlNit)->value('id');
            }
        }

        // Modalidades que permiten cambiar ARL y muestran Modo ARL
        $modalidadesArlLibre = \App\Models\TipoModalidad::IDS_ARL_LIBRE;  // [10, -1, 8]
        // Niveles de riesgo que admite cada modalidad (K: 1-3, Y: 4-5). Se envía la MISMA
        // constante que usa el tarifario, para que el selector y los precios no se contradigan.
        $nivelesArlPorModalidad = TarifaAsesorService::NIVELES_ARL_POR_MODALIDAD;
        $modalidadesModoArl = \App\Models\TipoModalidad::IDS_MODO_ARL;   // [10, -1]

        // IDs de modalidades independientes (I Venc=10, UPC=13, En el Exterior=14,
        // Tiempo Parcial Independiente=18).
        // NOTA: las modalidades TP de DEPENDIENTES no entran acá; se manejan por su
        // propia lógica (es_tiempo_parcial=1 en la BD). La 18 sí, porque es la única
        // de tiempo parcial que se le vende a un independiente (cotizante 76).
        $modalidadesIndependientes = TipoModalidad::IDS_INDEPENDIENTE;

        // Modalidades que pueden cotizar el mes en curso en vez del vencido.
        // Es un atributo del contrato (`paga_mes_actual`), no una modalidad aparte:
        // Independientes (10), ARL Tipo Y (8) y En el Exterior (14).
        $modalidadesMesActual = Contrato::MODALIDADES_MES_ACTUAL;

        // Mapa: tipo_modalidad_id => [plan_ids] — para filtrado dinámico en el JS
        $planesPermitidos = DB::table('modalidad_planes')
            ->get()
            ->groupBy('tipo_modalidad_id')
            ->map(fn ($rows) => $rows->pluck('plan_id')->values())
            ->toArray();

        // ── RS ya ocupadas por contratos VIGENTES de este cliente ──────
        // Se excluye el contrato actual (en edición) para no bloquear su propia RS.
        $rsOcupadasIds = [];
        if ($cliente) {
            $query = Contrato::where('aliado_id', $alidoId)
                ->where('cedula', $cliente->cedula)
                ->where('estado', 'vigente')
                ->whereNotNull('razon_social_id');
            if ($excludeContratoId) {
                $query->where('id', '!=', $excludeContratoId);
            }
            $rsOcupadasIds = $query->pluck('razon_social_id')
                ->unique()->values()->toArray();
        }

        // ── Regla AFP obligatorio ───────────────────────────────────────
        // Modalidades donde AFP es obligatorio (a menos que el cliente esté exento):
        //   - Dependiente E (0), I Venc (10)
        //   - TODAS las modalidades con es_tiempo_parcial=1 (independiente del ID)
        //     → el plan "ARL+CCF" sin AFP (APTP) solo es válido para clientes exentos
        $idsTP = TipoModalidad::where('es_tiempo_parcial', true)->pluck('id')->map(fn ($id) => (int) $id)->toArray();
        $modalidadesAfpObligatorio = array_values(array_unique(array_merge([0, 10], $idsTP)));

        // Razones sociales: activas primero (ordenadas por nombre), inactivas al final
        $razonesSociales = RazonSocial::where('aliado_id', $alidoId)
            ->orderByRaw("CASE WHEN estado = 'Activa' THEN 0 ELSE 1 END")
            ->orderBy('razon_social')
            ->get();

        return [
            'razonesSociales' => $razonesSociales,
            // Cajas de cada razón social por departamento (la principal y las de
            // otros departamentos): el formulario sugiere la del cliente.
            'cajasRazonSocial' => \App\Models\RazonSocialCaja::porRazonSocial($razonesSociales),
            'deptCliente' => $this->departamentoCliente($cliente),
            'asesores' => Asesor::where('aliado_id', $alidoId)->where('activo', true)->orderBy('nombre')->get(),
            'epsList' => Eps::seleccionables()->orderBy('nombre')->get(),
            'pensiones' => Pension::orderBy('razon_social')->get(),
            'arlList' => Arl::orderBy('nombre_arl')->get(),
            'cajas' => $this->cajasOrdenadas($cliente),
            'tiposModalidad' => TipoModalidad::activos()->get(),
            // Catálogo de seguros del aliado: en la modalidad Seguros es el producto que
            // se vende, y en cualquier otro contrato es un adicional sobre la mensualidad.
            'segurosCatalogo' => \App\Models\AliadoSeguro::activos($alidoId)->get(),
            'planes' => PlanContrato::where('activo', true)->get(),
            'actividades' => ActividadEconomica::where('activo', true)->orderBy('nombre')->get(),
            'motivosAfiliacion' => MotivoAfiliacion::where('activo', true)->get(),
            'motivosRetiro' => MotivoRetiro::where('activo', true)->get(),
            'usuarios' => \App\Models\User::where('aliado_id', $alidoId)->where('activo', true)->orderBy('nombre')->get(),
            'salarioMinimo' => ConfiguracionBrynex::salarioMinimo(),
            'pctIbcSugerido' => ConfiguracionBrynex::pctIbcIndependienteSugerido(),
            // Defaults entidades
            'arlIdRazonSocial' => $arlIdRazonSocial,
            'clienteEpsId' => $cliente?->eps_id,
            'clientePensionId' => $cliente?->pension_id,
            'modalidadesArlLibre' => $modalidadesArlLibre,
            'nivelesArlPorModalidad' => $nivelesArlPorModalidad,
            'modalidadesModoArl' => $modalidadesModoArl,
            // Filtrado inteligente
            'planesPermitidos' => $planesPermitidos,
            'modalidadesIndependientes' => $modalidadesIndependientes,
            // Modalidades donde los días de tiempo parcial los pone el contrato y
            // no el catálogo: son las que muestran el selector de semanas.
            'modalidadesDiasContrato' => TipoModalidad::where('es_tiempo_parcial', true)
                ->whereNull('dias_afp')
                ->pluck('id')->map(fn ($id) => (int) $id)->values()->toArray(),
            'modalidadesMesActual' => $modalidadesMesActual,
            'clienteExentoAfp' => $this->detectarExencionAfp($cliente),
            'clientePensionado' => (int) ($cliente?->pension_id ?? 0) === \App\Models\Pension::ID_PENSIONADO,
            'clienteTipoDoc' => $cliente?->tipo_doc,
            'clienteEdad' => $cliente?->edad,
            'clienteGenero' => $cliente?->genero,
            // Regla AFP obligatorio
            'reglaAfpActiva' => ConfiguracionBrynex::reglaAfpObligatorio(),
            'modalidadesAfpObligatorio' => $modalidadesAfpObligatorio,
            // Defaults de tarifas
            'defaultTarifas' => Contrato::tarifasParaAliado($alidoId, null),
            'bancos' => BancoCuenta::paraFacturacion($alidoId),
            // RS ya usadas (para deshabilitar en el select de creación)
            'rsOcupadasIds' => $rsOcupadasIds,
            // Operador de planilla (todos los globales, para RS independiente)
            'operadoresPlanilla' => DB::table('operadores_planilla')
                ->whereNull('aliado_id')
                ->orderBy('orden')
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'codigo_ni']),
            // Valor actual del operador asignado al cliente
            'clienteOperadorId' => $cliente?->operador_planilla_id,
            'planosExistentes' => $planosExistentes,
            'fondoSolidaridad' => $this->datosFondoSolidaridad($alidoId, $cliente),
        ];
    }

    /**
     * Lo que el formulario necesita para la modalidad Fondo de Solidaridad: los
     * grupos con lo que paga cada uno, Colpensiones para fijar la pensión, y el
     * certificado de inscripción del cliente si ya lo subieron.
     */
    private function datosFondoSolidaridad(int $alidoId, ?object $cliente): array
    {
        $sm = ConfiguracionBrynex::salarioMinimo();
        $ceil100 = fn (float $v) => (int) (ceil($v / 100) * 100);
        $salud = $ceil100($sm * ConfiguracionBrynex::pctSaludIndependiente() / 100);

        $grupos = [];
        foreach (TipoModalidad::GRUPOS_FONDO_SOLIDARIDAD as $clave => $g) {
            $pension = $ceil100($sm * $g['pct_pension'] / 100);
            $grupos[$clave] = $g + [
                'pension' => $pension,
                'salud' => $salud,
                'total' => $pension + $salud,
                'solo_afp' => in_array($clave, TipoModalidad::GRUPOS_FONDO_SOLIDARIDAD_SOLO_AFP, true),
            ];
        }

        $certificado = $cliente
            ? \App\Models\DocumentoCliente::where('aliado_id', $alidoId)
                ->where('cc_cliente', $cliente->cedula)
                ->where('tipo_documento', 'certificado_fsp')
                ->latest('created_at')
                ->first()
            : null;

        return [
            'id' => TipoModalidad::ID_FONDO_SOLIDARIDAD,
            'grupos' => $grupos,
            'colpensionesId' => (int) Pension::where('nit', '900336004')->value('id'),
            'edadMin' => TipoModalidad::EDAD_MIN_FONDO_SOLIDARIDAD,
            'edadMax' => TipoModalidad::EDAD_MAX_FONDO_SOLIDARIDAD,
            'certificado' => $certificado ? [
                'fecha' => $certificado->created_at->format('d/m/Y'),
                'url' => route('admin.documentos.download', $certificado->id),
            ] : null,
            'urlSubirCertificado' => $cliente ? route('admin.clientes.documentos.store', $cliente->cedula) : null,
            // Para que la extensión BryNex Portales deje la consulta del Fondo ya llena.
            'documento' => $cliente?->cedula ? (string) $cliente->cedula : null,
            'tipoDoc' => $cliente?->tipo_doc ?: 'CC',
            // Se baja a mano: tiene reCAPTCHA y no se puede consultar desde el servidor.
            'urlConsultaCertificado' => 'https://nelfsp.equiedad.com.co:8001/faces/GenerarCertificadoPsapCAPTCHA.xhtml',
        ];
    }

    // ─── ARL de la razón social ───────────────────────────────────────
    /**
     * Completa la ARL del contrato con la de su razón social.
     *
     * En una razón social de empresa la ARL la define la empresa (`arl_nit`) y
     * el formulario deja su selector bloqueado; al estar deshabilitado ni
     * siquiera viaja en el POST, así que el contrato se guardaba con `arl_id`
     * vacío por más que en pantalla se viera la ARL correcta. Se rellena solo
     * cuando el plan cubre ARL y el contrato no trae ninguna.
     *
     * Las razones sociales de independientes quedan fuera: ahí conviven
     * afiliados de varias ARL y manda la del contrato (ver ResuelveArlEfectiva).
     */
    private function completarArlDesdeRazonSocial(array $data, ?Contrato $contrato = null): array
    {
        if (! empty($data['arl_id'])) {
            return $data;
        }

        $planId = $data['plan_id'] ?? $contrato?->plan_id;
        $plan = $planId ? \App\Models\PlanContrato::find($planId) : null;
        if (! $plan || ! $plan->incluye_arl) {
            return $data;
        }

        $rsId = $data['razon_social_id'] ?? $contrato?->razon_social_id;
        if (! $rsId) {
            return $data;
        }

        $rs = DB::table('razones_sociales')->where('id', $rsId)->first();
        if (! $rs || $rs->es_independiente || ! $rs->arl_nit) {
            return $data;
        }

        $arlId = DB::table('arls')->where('nit', $rs->arl_nit)->value('id');
        if ($arlId) {
            $data['arl_id'] = $arlId;
        }

        return $data;
    }

    // ─── Cajas ordenadas por departamento del cliente ─────────────────
    /**
     * Retorna las cajas de compensación ordenadas así:
     *   1. Las del departamento del cliente (según municipio_id → ciudades.departamento_id)
     *   2. El resto, alfabéticamente
     *
     * Agrega un atributo virtual 'es_local' para que la vista pueda destacarlas.
     */
    private function cajasOrdenadas(?object $cliente): \Illuminate\Support\Collection
    {
        $deptCliente = $this->departamentoCliente($cliente);

        $cajas = Caja::orderBy('nombre')->get();

        if (! $deptCliente) {
            // Sin departamento conocido: orden alfabético normal
            return $cajas->each(fn ($c) => $c->es_local = false);
        }

        // Separar cajas del departamento del cliente y el resto
        $locales = $cajas->where('id_dept', $deptCliente)->values();
        $resto = $cajas->where('id_dept', '!=', $deptCliente)
            ->whereNotNull('id_dept')
            ->merge($cajas->whereNull('id_dept'))
            ->sortBy('nombre')
            ->values();

        $locales->each(fn ($c) => $c->es_local = true);
        $resto->each(fn ($c) => $c->es_local = false);

        return $locales->merge($resto);
    }

    /** El departamento del cliente según su municipio (ciudades.departamento_id). */
    private function departamentoCliente(?object $cliente): ?int
    {
        if (! $cliente || ! $cliente->municipio_id) {
            return null;
        }

        $dpto = DB::table('ciudades')->where('id', $cliente->municipio_id)->value('departamento_id');

        return $dpto ? (int) $dpto : null;
    }

    // ─── Duplicar contrato Plan Ingreso-Retiro (id=12) ───────────────
    /**
     * Marca retiro en el contrato actual (n_plano=0, num_dias 1-3)
     * y crea un nuevo contrato con la siguiente RS disponible,
     * fecha_ingreso = 26 del mes actual y estado = vigente.
     */
    public function duplicarIngresoRetiro(Request $request, int $contrato)
    {
        $alidoId = session('aliado_id_activo');
        $original = Contrato::where('aliado_id', $alidoId)
            ->with(['eps', 'arl', 'pension', 'caja', 'tipoModalidad', 'razonSocial', 'cliente', 'plan'])
            ->findOrFail($contrato);

        // Validar que sea plan Ingreso-Retiro vigente
        if ((int) $original->tipo_modalidad_id !== 12 || ! $original->estaVigente()) {
            return response()->json(['error' => true, 'mensaje' => 'Este contrato no aplica para duplicación Ingreso-Retiro.'], 422);
        }

        $validated = $request->validate([
            'num_dias' => 'required|integer|min:1|max:3',
            'motivo_retiro_id' => 'required|exists:motivos_retiro,id',
            'observacion' => 'nullable|string|max:500',
            'nueva_rs_id' => 'nullable|integer',
        ]);

        // Seleccionar nueva RS: usar la del usuario si vino y es válida, sino el algoritmo automático
        $nuevaRsId = null;
        if (! empty($validated['nueva_rs_id'])) {
            // Verificar que sea una RS válida (dependiente, activa, del aliado, distinta a la actual)
            $rsManual = DB::table('razones_sociales')
                ->where('id', $validated['nueva_rs_id'])
                ->where('aliado_id', $alidoId)
                ->where('es_independiente', false)
                ->where('estado', 'Activa')
                ->where('id', '!=', $original->razon_social_id)
                ->whereRaw("UPPER(razon_social) NOT LIKE '%RAZON SOCIAL%'")
                ->exists();
            if ($rsManual) {
                $nuevaRsId = (int) $validated['nueva_rs_id'];
            }
        }
        if (! $nuevaRsId) {
            $nuevaRsId = $this->seleccionarRsParaIR($alidoId, $original->cedula, (int) $original->razon_social_id);
        }
        if (! $nuevaRsId) {
            return response()->json(['error' => true, 'mensaje' => 'No se encontró una Razón Social disponible para asignar. Verifique que existan RS dependientes activas.'], 422);
        }

        $nuevoContrato = null;

        DB::transaction(function () use ($original, $validated, $alidoId, $nuevaRsId, &$nuevoContrato) {
            $numDias = (int) $validated['num_dias'];
            $fechaIngreso = \Carbon\Carbon::parse($original->fecha_ingreso);
            $mesAnterior = now()->subMonth()->startOfMonth();

            // Si la afiliación fue exactamente el mes anterior → fecha_ingreso + (dias-1)
            // Si fue antes del mes anterior → día 1 del mes anterior
            if (
                $fechaIngreso->year === $mesAnterior->year &&
                $fechaIngreso->month === $mesAnterior->month
            ) {
                $fechaRetiro = $fechaIngreso->copy()->addDays($numDias - 1)->toDateString();
            } else {
                $fechaRetiro = $mesAnterior->toDateString(); // 1ro del mes anterior
            }

            // ── 1. Marcar retiro en contrato original ─────────────────────
            // Se usa DB::table directamente (no Eloquent) para garantizar que el UPDATE
            // persista en SQL Server dentro de la transacción, ya que el modelo $original
            // fue hidratado fuera del scope de la transacción.
            $filasAfectadas = DB::table('contratos')
                ->where('id', $original->id)
                ->where('aliado_id', $alidoId)
                ->where('estado', 'vigente') // Safety check: solo si sigue vigente
                ->update([
                    'estado' => 'retirado',
                    'motivo_retiro_id' => (int) $validated['motivo_retiro_id'],
                    'fecha_retiro' => $fechaRetiro,
                    'observacion' => $validated['observacion'] ?? $original->observacion,
                    'updated_at' => now(),
                ]);

            if ($filasAfectadas === 0) {
                throw new \RuntimeException('No se pudo marcar el retiro del contrato original. Puede que ya haya sido retirado por otra operación.');
            }

            // Refrescar el objeto en memoria para que el resto del closure use el estado actualizado
            $original->refresh();

            // ── 2. Crear plano de retiro con n_plano = 0 ─────────────────
            $cliente = $original->cliente;
            $eps = $original->eps;
            $afp = $original->pension;
            $arl = $original->arl;
            $caja = $original->caja;
            $rs = $original->razonSocial;

            $arlSnapshot = \App\Models\Plano::resolverArlSnapshot($original, $rs);
            $codArl = $arlSnapshot['cod_arl'];
            $nombreArl = $arlSnapshot['nombre_arl'];

            $apellidos = $cliente?->apellidos ?? trim(($cliente?->primer_apellido ?? '').' '.($cliente?->segundo_apellido ?? ''));
            $nombres = $cliente?->nombres ?? trim(($cliente?->primer_nombre ?? '').' '.($cliente?->segundo_nombre ?? ''));
            $partsApe = preg_split('/\s+/', trim($apellidos), 2);
            $partsNom = preg_split('/\s+/', trim($nombres), 2);

            // Calcular la cotización para el retiro real (aportes a seguridad social)
            $vEpsRetiro = 0;
            $vArlRetiro = 0;
            $vAfpRetiro = 0;
            $vCajaRetiro = 0;
            $totalSsRetiro = 0;
            if ($numDias > 0) {
                // Fallback solo cuando ambos son 0 (legacy sin ningún dato).
                // Si solo ibc=0 (dependiente) o solo salario=0 (independiente),
                // calcularCotizacion() lo resuelve internámente según modalidad.
                $ibcOriginal = (float) ($original->ibc ?? 0);
                $salOriginal = (float) ($original->salario ?? 0);
                if ($ibcOriginal <= 0 && $salOriginal <= 0) {
                    $sm = (float) \App\Models\ConfiguracionBrynex::obtener('salario_minimo', 1423500);
                    $original->ibc = $sm;
                    $original->salario = $sm;
                }

                $cotizacion = $original->calcularCotizacion($numDias);
                $vEpsRetiro = (int) ($cotizacion['eps'] ?? 0);
                $vArlRetiro = (int) ($cotizacion['arl'] ?? 0);
                $vAfpRetiro = (int) ($cotizacion['pen'] ?? 0);
                $vCajaRetiro = (int) ($cotizacion['caja'] ?? 0);
                $totalSsRetiro = $vEpsRetiro + $vArlRetiro + $vAfpRetiro + $vCajaRetiro;

                // Restaurar
                $original->ibc = $ibcOriginal;
                $original->salario = $salOriginal;
            }

            // Crear factura de retiro (costo interno, no ingreso)
            $facRetiro = \App\Models\Factura::create([
                'aliado_id' => $alidoId,
                'numero_factura' => 0,
                'tipo' => 'planilla',
                'cedula' => $original->cedula,
                'contrato_id' => $original->id,
                'razon_social_id' => $original->razon_social_id,
                'empresa_id' => null,
                'mes' => now()->month,
                'anio' => now()->year,
                'fecha_pago' => now()->toDateString(),
                'estado' => 'pagada',
                'forma_pago' => 'efectivo',
                'valor_efectivo' => 0,
                'valor_consignado' => 0,
                'valor_prestamo' => 0,
                'otros' => 0,
                'otros_admon' => 0,
                'mensajeria' => 0,
                'dias_cotizados' => $numDias,
                'v_eps' => $vEpsRetiro,
                'v_arl' => $vArlRetiro,
                'v_afp' => $vAfpRetiro,
                'v_caja' => $vCajaRetiro,
                'total_ss' => $totalSsRetiro,
                'admon' => 0,
                'admin_asesor' => 0,
                'seguro' => 0,
                'afiliacion' => 0,
                'iva' => 0,
                'total' => 0,
                'saldo_proximo' => 0,
                'usuario_id' => \Illuminate\Support\Facades\Auth::id(),
                'observacion' => $validated['observacion'] ?? null,
            ]);

            // Parsear la fecha de retiro para obtener el mes y año de la cotización real
            $carbonRetiro = \Carbon\Carbon::parse($fechaRetiro);

            \App\Models\Plano::create([
                'factura_id' => $facRetiro->id,
                'contrato_id' => $original->id,
                'aliado_id' => $alidoId,
                'numero_factura' => 0,
                'tipo_reg' => 'retiro',
                'tipo_doc' => strtoupper(trim($cliente?->tipo_doc ?? 'CC')) ?: 'CC',
                'no_identifi' => $original->cedula,
                'primer_ape' => strtoupper($partsApe[0] ?? ''),
                'segundo_ape' => strtoupper($partsApe[1] ?? ''),
                'primer_nombre' => strtoupper($partsNom[0] ?? ''),
                'segundo_nombre' => strtoupper($partsNom[1] ?? ''),
                'fecha_ing' => null,
                'fecha_ret' => $fechaRetiro,
                'num_dias' => $numDias,
                'cod_eps' => $eps?->nit ?? $eps?->cod_eps ?? null,
                'nombre_eps' => $eps?->nombre ?? null,
                'cod_afp' => $afp?->nit ?? $afp?->cod_afp ?? null,
                'nombre_afp' => $afp?->razon_social ?? null,
                'cod_arl' => $codArl,
                'nombre_arl' => $nombreArl,
                'cod_caja' => $caja?->nit ?? $caja?->cod_caja ?? null,
                'nombre_caja' => $caja?->nombre ?? null,
                'nivel_riesgo' => $original->n_arl ?? 1,
                'salario_basico' => $original->salario ?? 0,
                'n_plano' => 100, // IR siempre en plano 100 (separado de planillas normales)
                'mes_plano' => $carbonRetiro->month,
                'anio_plano' => $carbonRetiro->year,
                'razon_social' => $rs?->razon_social ?? null,
                'razon_social_id' => $original->razon_social_id,
                'tipo_p' => $original->tipo_modalidad_id,
                'tipo_modalidad_id' => $original->tipo_modalidad_id,
                'usuario_id' => \Illuminate\Support\Facades\Auth::id(),
            ]);

            // ── 3. Crear nuevo contrato ──
            $cfgAliado = \App\Models\ConfiguracionAliado::paraAliado($alidoId);
            $diaIngreso = max(1, min(28, (int) ($cfgAliado?->dia_ingreso_ir ?? 26)));
            $nuevaFechaIngreso = now()->startOfMonth()->addDays($diaIngreso - 1)->toDateString();

            // Derivar arl_nit_cotizante de la nueva RS
            $nuevaRsRow = DB::table('razones_sociales')->where('id', $nuevaRsId)->first();
            $nuevoArlNitCotizante = $nuevaRsRow ? (int) $nuevaRsId : null; // RS dependiente → cotiza por RS

            $nuevoContrato = Contrato::create([
                'aliado_id' => $alidoId,
                'cedula' => $original->cedula,
                'razon_social_id' => $nuevaRsId,
                'plan_id' => $original->plan_id,
                'tipo_modalidad_id' => $original->tipo_modalidad_id, // 12
                'eps_id' => $original->eps_id,
                'pension_id' => $original->pension_id,
                'arl_id' => $original->arl_id,
                'n_arl' => $original->n_arl,
                'arl_modo' => $original->arl_modo ?? 'razon_social',
                'arl_nit_cotizante' => $nuevoArlNitCotizante,
                'caja_id' => $original->caja_id,
                'cargo' => $original->cargo,
                'actividad_economica_id' => $original->actividad_economica_id,
                'salario' => $original->salario,
                'ibc' => $original->ibc,
                'porcentaje_caja' => $original->porcentaje_caja,
                'dias_tp_afp' => $original->dias_tp_afp,
                'dias_tp_caja' => $original->dias_tp_caja,
                'grupo_fondo_solidaridad' => $original->grupo_fondo_solidaridad,
                'administracion' => $original->administracion,
                'admon_asesor' => $original->admon_asesor,
                'costo_afiliacion' => $original->costo_afiliacion,
                'seguro' => $original->seguro,
                'asesor_id' => $original->asesor_id,
                'encargado_id' => $original->encargado_id,
                'motivo_afiliacion_id' => 8, // Ingreso-Retiro (rotación automática)
                'envio_planilla' => $original->envio_planilla,
                'observacion' => $original->observacion,
                'observacion_afiliacion' => $original->observacion_afiliacion,
                'np' => $original->np,
                // Campos modificados para el nuevo contrato:
                'fecha_ingreso' => $nuevaFechaIngreso,
                'estado' => 'vigente',
                'fecha_created' => now(),
                'razon_social_bloqueada' => false,
                'cobra_planilla_primer_mes' => false,
                // NO se copian: fecha_retiro, motivo_retiro_id
            ]);

            // ── 4. Crear radicados pendientes en el nuevo contrato ────────
            $nuevoContrato->load('plan');
            $nuevoContrato->crearRadicadosPendientes();
        });

        // Si es request AJAX (desde el modal) devolver JSON
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'ok' => true,
                'nuevo_id' => $nuevoContrato->id,
                'redirect_url' => route('admin.contratos.edit', $nuevoContrato->id),
                'mensaje' => 'Retiro marcado y contrato duplicado correctamente.',
            ]);
        }

        $redirectParams = [$nuevoContrato->id];
        if ($request->input('back_url')) {
            $redirectParams['back'] = $request->input('back_url');
        }

        return redirect()
            ->route('admin.contratos.edit', $redirectParams)
            ->with('success', '✅ Retiro marcado y contrato duplicado. Nueva RS: #'.$nuevoContrato->razon_social_id.' · Ingreso: '.\Carbon\Carbon::parse($nuevoContrato->fecha_ingreso)->format('d/m/Y'));
    }

    /**
     * Selecciona la mejor RS para el plan Ingreso-Retiro:
     * 1. RS donde el cliente NUNCA ha estado (sin contratos previos)
     * 2. Si ya estuvo en todas → la que tenga fecha_retiro más antigua
     *
     * Excluye: RS actuales (vigente), RS con "RAZON SOCIAL" en nombre, RS independientes.
     */
    private function seleccionarRsParaIR(int $alidoId, string $cedula, int $rsActualId): ?int
    {
        // Candidatas: dependientes, activas, sin "RAZON SOCIAL" en nombre, excluyendo la actual
        $candidatas = DB::table('razones_sociales')
            ->where('aliado_id', $alidoId)
            ->where('es_independiente', false)
            ->where('estado', 'Activa')
            ->where('id', '!=', $rsActualId)
            ->whereRaw("UPPER(razon_social) NOT LIKE '%RAZON SOCIAL%'")
            ->pluck('id');

        if ($candidatas->isEmpty()) {
            return null;
        }

        // RS donde el cliente tiene contrato VIGENTE (excluir — ya ocupada)
        $rsVigentes = DB::table('contratos')
            ->where('cedula', $cedula)
            ->where('aliado_id', $alidoId)
            ->where('estado', 'vigente')
            ->whereIn('razon_social_id', $candidatas)
            ->pluck('razon_social_id');

        $candidatasLibres = $candidatas->diff($rsVigentes);

        if ($candidatasLibres->isEmpty()) {
            return null;
        }

        // RS donde el cliente NUNCA ha estado (sin contratos históricos)
        $rsConHistorial = DB::table('contratos')
            ->where('cedula', $cedula)
            ->where('aliado_id', $alidoId)
            ->whereIn('razon_social_id', $candidatasLibres)
            ->pluck('razon_social_id')
            ->unique();

        $sinHistorial = $candidatasLibres->diff($rsConHistorial);

        if ($sinHistorial->isNotEmpty()) {
            // Prioridad 1: RS nunca usada → tomar la primera
            return $sinHistorial->first();
        }

        // Prioridad 2: ya estuvo en todas → la con fecha_retiro más antigua
        $rsOrdenada = DB::table('contratos')
            ->where('cedula', $cedula)
            ->where('aliado_id', $alidoId)
            ->where('estado', 'retirado')
            ->whereIn('razon_social_id', $candidatasLibres)
            ->whereNotNull('fecha_retiro')
            ->orderBy('fecha_retiro', 'asc') // más antigua = más tiempo sin usar
            ->value('razon_social_id');

        return $rsOrdenada ?: $candidatasLibres->first();
    }

    // ─── Detectar exención de AFP del cliente ─────────────────────────
    /**
     * Un cliente puede omitir AFP si:
     * - Ya está pensionado (fondo "PENSIONADO" en su ficha) — sin importar edad, género ni documento
     * - doc: CE (Cédula Extranjería), PT (Permiso Prot. Temporal), PE (Permiso Especial), PA (Pasaporte)
     * - Hombre ≥ 55 años  |  Mujer ≥ 50 años
     */
    private function detectarExencionAfp(?object $cliente): bool
    {
        // La regla vive en Cliente::motivoExencionAfp() — una sola fuente para admin, web e IA.
        return $cliente instanceof \App\Models\Cliente && $cliente->esExentoAfp();
    }

    // ─── Validación ───────────────────────────────────────────────────
    private function validar(Request $request, ?Contrato $contrato = null): array
    {
        $data = $request->validate([
            'cedula' => 'required|digits_between:6,15',
            'razon_social_id' => 'nullable|exists:razones_sociales,id',
            'plan_id' => 'nullable|exists:planes_contrato,id',
            'tipo_modalidad_id' => 'nullable|exists:tipo_modalidad,id',
            'eps_id' => 'nullable|exists:eps,id',
            'pension_id' => 'nullable|exists:pensiones,id',
            'arl_id' => 'nullable|exists:arls,id',
            'n_arl' => 'nullable|integer|min:1|max:5',
            'arl_modo' => 'nullable|in:razon_social,independiente',
            'arl_nit_cotizante' => 'nullable|integer|min:0',
            'caja_id' => 'nullable|exists:cajas,id',
            'cargo' => 'nullable|string|max:255',
            'fecha_ingreso' => 'nullable|date',
            'fecha_retiro' => 'nullable|date',
            'actividad_economica_id' => 'nullable|exists:actividades_economicas,id',
            'salario' => 'nullable|numeric|min:0',
            'ibc' => 'nullable|numeric|min:0',
            'porcentaje_caja' => 'nullable|numeric|min:0|max:100',
            // Tiempo Parcial Independiente: solo los cuatro bloques de la tabla
            // de cotizaciones mínimas semanales (1, 2, 3 o 4 semanas).
            'dias_tp_afp' => 'nullable|integer|in:7,14,21,30',
            'dias_tp_caja' => 'nullable|integer|in:7,14,21,30',
            // Fondo de Solidaridad: el grupo del PSAP decide la tarifa de pensión.
            'grupo_fondo_solidaridad' => 'nullable|string|in:'.implode(',', array_keys(TipoModalidad::GRUPOS_FONDO_SOLIDARIDAD)),
            'administracion' => 'nullable|numeric|min:0',
            'admon_asesor' => 'nullable|numeric|min:0',
            'costo_afiliacion' => 'nullable|numeric|min:0',
            // Lo que le toca al asesor de la afiliación de ESTE contrato. Su null no es 0:
            // significa "contrato sin tarifario", y mantiene a la facturación en la lógica
            // de siempre — ver la migración add_afiliacion_asesor_to_contratos_table.
            'afiliacion_asesor' => 'nullable|numeric|min:0',
            'seguro' => 'nullable|numeric|min:0',
            'seguro_id' => 'nullable|integer',
            'asesor_id' => 'nullable|exists:asesores,id',
            'encargado_id' => 'nullable|exists:users,id',
            'motivo_afiliacion_id' => 'nullable|exists:motivos_afiliacion,id',
            'motivo_retiro_id' => 'nullable|exists:motivos_retiro,id',
            'fecha_arl' => 'nullable|date',
            'envio_planilla' => 'nullable|string|max:55',
            'np' => 'nullable|string|max:255',
            'observacion' => 'nullable|string',
            'observacion_afiliacion' => 'nullable|string',
            'operador_planilla_id' => 'nullable|integer',
            'cobra_planilla_primer_mes' => 'boolean',
            'paga_mes_actual' => 'boolean',
        ]);

        // Los días de tiempo parcial solo existen en las modalidades donde el
        // catálogo no los trae. En cualquier otra se descartan, aunque el
        // navegador los mande: en el TP de dependientes los días SON la
        // modalidad, y dejarlos aquí los pisaría en silencio.
        //
        // Va antes de validarSalarioMinimo() porque el piso de salario de esas
        // modalidades es la fracción del mínimo que corresponde a estos días.
        $modalidadDias = isset($data['tipo_modalidad_id'])
            ? TipoModalidad::find((int) $data['tipo_modalidad_id'])
            : null;

        if (! $modalidadDias || ! $modalidadDias->diasEnElContrato()) {
            $data['dias_tp_afp'] = null;
            $data['dias_tp_caja'] = null;
        } else {
            // La caja sigue los días de pensión salvo que se diga otra cosa.
            // Las reglas `nullable` no crean la clave cuando el campo no se
            // envía, y el formulario no manda dias_tp_caja: hay que default-earlo.
            $data['dias_tp_afp'] = $data['dias_tp_afp'] ?? 7;
            $data['dias_tp_caja'] = ($data['dias_tp_caja'] ?? null) ?: null;
        }

        // El grupo del Fondo de Solidaridad solo existe en su modalidad; en las
        // demás se descarta aunque el navegador lo mande.
        if ($modalidadDias && $modalidadDias->esFondoSolidaridad()) {
            $data = $this->validarFondoSolidaridad($data);
        } else {
            $data['grupo_fondo_solidaridad'] = null;
        }

        $this->validarSalarioMinimo($data);
        $this->validarNivelArl($data, $contrato);

        // El mes actual solo existe en algunas modalidades. Se decide aquí y no en
        // el formulario: el checkbox se oculta al cambiar de modalidad, pero un
        // navegador puede mandar el valor igual.
        if (array_key_exists('paga_mes_actual', $data)) {
            $modalidadId = isset($data['tipo_modalidad_id']) ? (int) $data['tipo_modalidad_id'] : null;
            if (! in_array($modalidadId, Contrato::MODALIDADES_MES_ACTUAL, true)) {
                $data['paga_mes_actual'] = false;
            }
        }

        // El seguro escogido tiene que ser del catálogo de ESTE aliado: el id viene de un
        // <select> y nada impide mandar el de otro. Si no le pertenece, se descarta.
        if (! empty($data['seguro_id'])) {
            $esDelAliado = \App\Models\AliadoSeguro::where('id', $data['seguro_id'])
                ->where('aliado_id', session('aliado_id_activo'))
                ->exists();

            if (! $esDelAliado) {
                $data['seguro_id'] = null;
            }
        } else {
            $data['seguro_id'] = null;
        }

        // "" → null. Sin esto, un formulario sin asesor guardaría 0 y la facturación creería
        // que es un contrato con tarifario y comisión cero, en vez de caer a la lógica vieja.
        if (array_key_exists('afiliacion_asesor', $data) && $data['afiliacion_asesor'] === '') {
            $data['afiliacion_asesor'] = null;
        }

        // Sin asesor no hay comisión de afiliación que repartir: se limpia para no dejar un
        // valor huérfano si alguien le quita el asesor a un contrato que sí lo tenía.
        //
        // OJO: aquí NO se toca admon_asesor. Hay 251 contratos (111 vigentes) con
        // admon_asesor > 0 y asesor_id nulo, herencia de la migración; ponerlo en 0 al editar
        // bajaría en silencio lo que se le cobra al cliente, porque esa columna es una línea
        // de cobro, no solo un dato de comisión.
        if (empty($data['asesor_id'])) {
            $data['afiliacion_asesor'] = null;
        }

        return $data;
    }

    /**
     * Reglas del Fondo de Solidaridad (PSAP) que se pueden hacer cumplir con los
     * datos del contrato:
     *   - Tiene que decir el grupo: es lo que fija la tarifa de pensión.
     *   - Solo Colpensiones: el programa no subsidia aportes a fondos privados.
     *   - Solo AFP únicamente para el desempleado (decisión del negocio).
     *   - Salario e IBC de un salario mínimo, ni más ni menos: es la base sobre
     *     la que se subsidia, y cualquier otro valor lo rechaza el operador.
     *
     * Lo que no está en el contrato —edad, semanas, inscripción— no bloquea: el
     * formulario lo muestra como alerta y Enlace vuelve a validar la inscripción
     * cada mes al liquidar (eo.val.2.504).
     */
    private function validarFondoSolidaridad(array $data): array
    {
        $errores = [];

        if (empty($data['grupo_fondo_solidaridad'])) {
            $errores['grupo_fondo_solidaridad'] = 'Elija el grupo del Fondo de Solidaridad: de él sale la tarifa de pensión.';
        }

        $colpensiones = Pension::where('nit', '900336004')->value('id');
        if ((int) ($data['pension_id'] ?? 0) !== (int) $colpensiones) {
            $errores['pension_id'] = 'El Fondo de Solidaridad solo subsidia aportes a Colpensiones.';
        }

        $plan = ! empty($data['plan_id']) ? PlanContrato::find($data['plan_id']) : null;
        if ($plan && $plan->codigo === 'SOLO_AFP'
            && ! in_array($data['grupo_fondo_solidaridad'] ?? null, TipoModalidad::GRUPOS_FONDO_SOLIDARIDAD_SOLO_AFP, true)) {
            $errores['plan_id'] = 'El plan Solo AFP del Fondo de Solidaridad es solo para el grupo Desempleado.';
        }

        if ($errores) {
            throw ValidationException::withMessages($errores);
        }

        $data['salario'] = ConfiguracionBrynex::salarioMinimo();
        $data['ibc'] = ConfiguracionBrynex::salarioMinimo();

        return $data;
    }

    /**
     * El nivel de riesgo ARL debe ser uno de los que admite la modalidad: Estudiante K (−1)
     * cubre los riesgos 1-3 y ARL Tipo Y (8) los 4-5, porque son los dos tipos de planilla
     * PILA. Misma regla que el tarifario (TarifaAsesorService::NIVELES_ARL_POR_MODALIDAD), para
     * que no se pueda guardar un contrato cuyo precio nadie puede configurar.
     *
     * Solo se valida cuando el nivel CAMBIA: hay un contrato heredado (id 27031, ARL Tipo Y en
     * riesgo 1) que quedaría imposible de editar si se exigiera también al abrirlo.
     */
    private function validarNivelArl(array $data, ?Contrato $contrato): void
    {
        $modalidadId = isset($data['tipo_modalidad_id']) ? (int) $data['tipo_modalidad_id'] : null;
        $nivel = isset($data['n_arl']) ? (int) $data['n_arl'] : null;

        if ($modalidadId === null || ! $nivel) {
            return;
        }

        $permitidos = TarifaAsesorService::NIVELES_ARL_POR_MODALIDAD[$modalidadId] ?? null;
        if (! $permitidos || in_array($nivel, $permitidos, true)) {
            return;
        }

        // Sin cambio respecto a lo que ya estaba guardado: se deja pasar.
        if ($contrato && (int) $contrato->n_arl === $nivel && (int) $contrato->tipo_modalidad_id === $modalidadId) {
            return;
        }

        $modalidad = TipoModalidad::find($modalidadId);
        $nombre = $modalidad?->observacion ?: ($modalidad?->tipo_modalidad ?? "modalidad {$modalidadId}");

        // "1, 2 o 3" en vez de "1 y 2 y 3".
        $lista = count($permitidos) > 1
            ? implode(', ', array_slice($permitidos, 0, -1)).' o '.end($permitidos)
            : (string) $permitidos[0];

        throw \Illuminate\Validation\ValidationException::withMessages([
            'n_arl' => "«{$nombre}» solo admite nivel de riesgo {$lista}.",
        ]);
    }

    /**
     * El salario no puede quedar por debajo del mínimo legal de su modalidad.
     * Tiempo Parcial cotiza sobre una fracción del SMMLV (¼, ½, ¾); el resto
     * de modalidades sobre el SMMLV completo. UPC (13) no depende del salario.
     *
     * Sin este piso se guardaban dependientes con salario de tiempo parcial
     * (el formulario dejaba pegado el valor al cambiar de modalidad).
     */
    private function validarSalarioMinimo(array $data): void
    {
        $modalidad = ! empty($data['tipo_modalidad_id'])
            ? TipoModalidad::find($data['tipo_modalidad_id'])
            : null;

        // En Tiempo Parcial Independiente la fracción sale de los días que trae
        // el contrato, no del catálogo: sin pasárselos, la 18 exigiría el mínimo
        // completo y no dejaría guardar un contrato de una o dos semanas.
        $diasAfp = isset($data['dias_tp_afp']) ? (int) $data['dias_tp_afp'] : null;

        // En Tipo E - Extras la fracción sale del plan: "Solo CCF 14" vende
        // media jornada del mes y su piso es medio mínimo, igual que lo era
        // cuando "Caja 14" era su propia modalidad. Ver TipoModalidad::PLANES_EXTRAS.
        $codigoPlan = ! empty($data['plan_id'])
            ? PlanContrato::whereKey($data['plan_id'])->value('codigo')
            : null;

        // Sin modalidad definida se exige el SMMLV completo.
        $minimo = $modalidad
            ? $modalidad->salarioMinimoPermitido($diasAfp, $codigoPlan)
            : ConfiguracionBrynex::salarioMinimo();

        if ($minimo <= 0) {
            return;  // UPC: el valor de EPS sale de la edad/zona, no del salario
        }

        $salario = (float) ($data['salario'] ?? 0);

        // Tolerancia de $1 por el redondeo de la fracción del mínimo
        if ($salario >= $minimo - 1) {
            return;
        }

        // Cuando el piso es una fracción del mínimo —Tiempo Parcial o Solo Caja—
        // el mensaje nombra la modalidad: decir "salario mínimo legal" y mostrar
        // la mitad del mínimo se lee como un error del sistema.
        $etiqueta = $modalidad && $modalidad->factorSalario($diasAfp, $codigoPlan) < 1.0
            ? "mínimo de {$modalidad->nombre}"
            : 'salario mínimo legal';

        throw ValidationException::withMessages([
            'salario' => "El salario no puede ser menor al $etiqueta ("
                .number_format($minimo, 0, ',', '.').').',
        ]);
    }
}
