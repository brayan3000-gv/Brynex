<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ClienteController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth']);
    }

    // ─── Listado de clientes ──────────────────────────────────────────
    public function index(Request $request)
    {
        $aliadoId = session('aliado_id_activo');
        $buscar = $request->get('buscar');
        $filtroEstado = $request->get('estado');

        $query = Cliente::with(['empresa'])
            ->where('clientes.aliado_id', $aliadoId)
            ->select('id', 'cedula', 'cod_empresa', 'primer_nombre', 'segundo_nombre',
                'primer_apellido', 'segundo_apellido',
                'celular', 'telefono', 'correo', 'municipio_id', 'eps_id', 'pension_id');

        // Búsqueda inteligente: toda la lógica de búsqueda se envuelve en UN SOLO where()
        // para que el orWhere de nombre NO escape el filtro aliado_id de la query raíz.
        // SQL resultante: WHERE aliado_id = X AND (cedula LIKE... OR (nombre1... AND nombre2...))
        if ($buscar) {
            $query->where(function ($q) use ($buscar) {
                // Coincidencia directa en cédula o celular
                $q->where(function ($inner) use ($buscar) {
                    $inner->where('cedula', 'LIKE', "%{$buscar}%")
                        ->orWhere('celular', 'LIKE', "%{$buscar}%");
                });

                // Si no es puramente numérico, también buscar por nombre tokenizado
                if (! ctype_digit(str_replace(' ', '', $buscar))) {
                    $palabras = array_filter(explode(' ', trim($buscar)));
                    $q->orWhere(function ($inner) use ($palabras) {
                        foreach ($palabras as $palabra) {
                            // Cada palabra debe matchear en ALGUNO de los 4 campos de nombre
                            $inner->where(function ($sub) use ($palabra) {
                                foreach (['primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido'] as $campo) {
                                    $sub->orWhereSinTildes($campo, $palabra);
                                }
                            });
                        }
                    });
                }
            });
        }

        // Filtro por estado del contrato. Debe coincidir con el estado que se
        // pinta en la tabla, que NO es "cualquier contrato" sino el contrato
        // ganador por cédula (vigente/activo primero, luego el de mayor id).
        // Subconsulta correlacionada para que aproveche el índice de cedula
        // en contratos en vez de materializar todos los contratos del aliado.
        // Un cliente sin contratos devuelve NULL y queda fuera de ambos filtros.
        if (in_array($filtroEstado, ['vigente', 'retirado'], true)) {
            $query->whereRaw(
                "? = (SELECT TOP 1 c.estado
                        FROM contratos c
                       WHERE c.aliado_id = ?
                         AND c.cedula = clientes.cedula
                    ORDER BY CASE WHEN c.estado IN ('vigente','activo') THEN 0 ELSE 1 END ASC,
                             c.id DESC)",
                [$filtroEstado, $aliadoId]
            );
        } else {
            $filtroEstado = null;
        }

        $clientes = $query->orderByDesc('id')->paginate(30);

        // Cargar último contrato de cada cliente (por cédula) en una sola consulta
        $cedulas = $clientes->pluck('cedula')->filter()->values()->toArray();
        $ultimosContratos = [];
        if (! empty($cedulas)) {
            // Subquery con prioridad: primero contrato vigente/activo, luego el de mayor ID.
            // Evita mostrar "retirado" cuando el cliente tiene un contrato más antiguo pero vigente.
            // Lógica:
            //   - CASE: vigente=0, activo=1, cualquier otro=2 → ORDER BY prioridad ASC, id DESC
            //   - CROSS APPLY TOP 1 selecciona el contrato ganador por cédula.
            $subs = DB::table('contratos as c')
                ->join(
                    DB::raw("(
                        SELECT TOP 1 WITH TIES c2.cedula, c2.id AS pref_id
                        FROM contratos c2
                        WHERE c2.aliado_id = {$aliadoId}
                        ORDER BY ROW_NUMBER() OVER (
                            PARTITION BY c2.cedula
                            ORDER BY
                                CASE
                                    WHEN c2.estado IN ('vigente','activo') THEN 0
                                    ELSE 1
                                END ASC,
                                c2.id DESC
                        )
                    ) AS pref"),
                    fn ($j) => $j->on('c.cedula', '=', 'pref.cedula')->on('c.id', '=', 'pref.pref_id')
                )
                ->leftJoin('tipo_modalidad as tm', 'tm.id', '=', 'c.tipo_modalidad_id')
                ->select('c.cedula', 'c.estado', 'c.fecha_ingreso', 'c.fecha_retiro',
                    DB::raw('COALESCE(tm.observacion, tm.tipo_modalidad) AS modalidad'))
                ->whereIn('c.cedula', $cedulas)
                ->get()
                ->keyBy('cedula');
            $ultimosContratos = $subs->toArray();
        }

        // El modal "Nuevo Cliente" deja escoger el tipo de documento antes de
        // consultar: BDUA/RUAF responde por tipo + número, y un tipo distinto
        // del real devuelve vacío (no error), que parece "no registrado".
        $tiposDoc = $this->getLookups()['tipos_doc'];

        return view('admin.clientes.index', compact('clientes', 'buscar', 'filtroEstado', 'ultimosContratos', 'tiposDoc'));
    }

    // ─── Crear nuevo cliente ──────────────────────────────────────────
    public function create()
    {
        $cliente = new Cliente;
        $lookups = $this->getLookups();
        $contratos = collect();

        // Pre-llenado desde el modal "Nuevo Cliente" del listado: ya consultó
        // BDUA/RUAF y el usuario confirmó que la persona es la correcta.
        // Deliberadamente no se toca "observacion" aquí.
        // El resto viene de una solicitud de ingreso del portal de empresas
        // (EmpresaSolicitudPanel): la ficha nace ya ligada a la empresa.
        $campos = ['cedula', 'tipo_doc', 'primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido', 'eps_id', 'pension_id',
            'fecha_nacimiento', 'genero', 'celular', 'correo', 'departamento_id', 'municipio_id', 'direccion_vivienda', 'cod_empresa'];
        foreach (request()->only($campos) as $campo => $valor) {
            if ($valor !== null && $valor !== '') {
                $cliente->{$campo} = $valor;
            }
        }

        return view('admin.clientes.form', compact('cliente', 'lookups', 'contratos'));
    }

    // ─── Guardar nuevo ────────────────────────────────────────────────
    public function store(Request $request)
    {
        $aliadoId = session('aliado_id_activo');

        // Verificar duplicado: cédula ya registrada en este aliado
        $cedula = $request->input('cedula');
        $clienteExistente = Cliente::where('cedula', $cedula)
            ->where('aliado_id', $aliadoId)
            ->first();

        if ($clienteExistente) {
            return redirect()->back()
                ->withInput()
                ->withErrors([
                    'cedula' => "Ya existe un cliente con la cédula {$cedula} en este aliado. "
                              ."Puedes editarlo desde su perfil (ID #{$clienteExistente->id}).",
                ]);
        }

        $data = $this->validarCliente($request);
        $data = $this->limpiarDatos($data);

        // Obtener siguiente ID (la tabla no es autoincrement)
        $maxId = DB::table('clientes')->max('id') ?? 0;
        $data['id'] = $maxId + 1;
        $data['aliado_id'] = $aliadoId;

        Cliente::create($data);

        return redirect()->route('admin.clientes.edit', $data['id'])
            ->with('success', 'Cliente creado exitosamente.');
    }

    // ─── Ficha del cliente resuelta por cédula ────────────────────────
    // Los módulos que listan por cédula (tareas, incapacidades, cobros)
    // no tienen el id del cliente a la mano. Esta ruta lo resuelve dentro
    // del aliado activo y redirige a la ficha normal, conservando ?iframe=1
    // para que el modal reutilizable la muestre sin layout.
    public function fichaPorCedula(string $cedula, Request $request)
    {
        $cliente = Cliente::where('aliado_id', session('aliado_id_activo'))
            ->where('cedula', $cedula)
            ->first();

        // La cédula puede venir de un registro viejo sin ficha de cliente en
        // este aliado; dentro del iframe un 404 se ve como un error del sistema,
        // así que se responde con un aviso legible.
        if (! $cliente) {
            if ($request->boolean('iframe')) {
                return response(
                    '<div style="font-family:Inter,sans-serif;padding:3rem;text-align:center;color:#64748b">'
                    .'<div style="font-size:2rem;margin-bottom:.75rem">🔍</div>'
                    .'<div style="font-weight:700;color:#0f172a">No se encontró la ficha del cliente</div>'
                    .'<div style="font-size:.85rem;margin-top:.35rem">La cédula '.e($cedula)
                    .' no tiene un cliente registrado en este aliado.</div></div>',
                    404
                );
            }
            abort(404);
        }

        return redirect()->route(
            'admin.clientes.edit',
            $request->boolean('iframe') ? [$cliente->id, 'iframe' => 1] : [$cliente->id]
        );
    }

    // ─── Editar cliente existente ─────────────────────────────────────
    public function edit(int $id, Request $request)
    {
        $isIframe = $request->boolean('iframe');
        $aliadoId = session('aliado_id_activo');
        $cliente = Cliente::where('aliado_id', $aliadoId)->findOrFail($id);
        $lookups = $this->getLookups();
        $contratos = DB::table('contratos as ct')
            ->leftJoin('tipo_modalidad as tm', 'tm.id', '=', 'ct.tipo_modalidad_id')
            ->leftJoin('planes_contrato as pc', 'pc.id', '=', 'ct.plan_id')
            ->where('ct.cedula', $cliente->cedula)
            ->where('ct.aliado_id', session('aliado_id_activo'))
            ->orderByRaw("CASE WHEN ct.estado = 'vigente' THEN 0 ELSE 1 END")
            ->orderByDesc('ct.id')
            ->select(
                'ct.*',
                DB::raw("COALESCE(tm.tipo_modalidad, '') AS tipo_mod"),
                DB::raw("COALESCE(pc.nombre, '') AS plan_nombre")
            )
            ->get();

        // Precargar razones_sociales para evitar N+1 en la vista
        $razonSocialIds = $contratos->pluck('razon_social_id')->filter()->unique()->values()->toArray();
        $razonesMap = [];
        if (! empty($razonSocialIds)) {
            $razonesMap = DB::table('razones_sociales')
                ->whereIn('id', $razonSocialIds)
                ->pluck('razon_social', 'id')
                ->toArray();
        }

        // ── Últimos 2 pagos (histórico corto bajo la tabla de contratos) ──
        // Una sola query, TOP 2: factura de planilla + su plano (N° planilla)
        // + el operador con el que se liquidó por API (si aplica).
        $ultimosPagos = DB::table('facturas as f')
            ->leftJoin('planos as p', function ($j) {
                $j->on('p.factura_id', '=', 'f.id')->whereNull('p.deleted_at');
            })
            ->leftJoin(DB::raw('(SELECT plano_id, MAX(id) AS max_id
                                   FROM operador_planillas_api
                                  WHERE deleted_at IS NULL AND plano_id IS NOT NULL
                               GROUP BY plano_id) AS opa_max'), 'opa_max.plano_id', '=', 'p.id')
            ->leftJoin('operador_planillas_api as opa', 'opa.id', '=', 'opa_max.max_id')
            ->leftJoin('operadores_planilla as op', 'op.id', '=', 'opa.operador_planilla_id')
            ->where('f.aliado_id', $aliadoId)
            ->where('f.cedula', $cliente->cedula)
            ->where('f.tipo', \App\Models\Factura::TIPO_PLANILLA)
            ->whereNull('f.deleted_at')
            ->orderByDesc('f.anio')->orderByDesc('f.mes')->orderByDesc('f.id')
            ->limit(2)
            ->select(
                'f.id', 'f.mes', 'f.anio', 'f.total', 'f.estado', 'f.numero_factura',
                DB::raw('COALESCE(p.numero_planilla, opa.numero_planilla) AS numero_planilla'),
                'op.nombre AS operador_nombre'
            )
            ->get();

        // Resumen del cliente para el card lateral
        $resumen = [
            'beneficiarios' => DB::table('beneficiarios')->where('cc_cliente', $cliente->cedula)->count(),
            'incapacidades' => DB::table('incapacidades')->where('cedula_usuario', $cliente->cedula)->count(),
            'contratos_vigent' => $contratos->where('estado', 'vigente')->count(),
            'claves' => DB::table('clave_accesos')
                ->where('aliado_id', session('aliado_id_activo'))
                ->where('cedula', $cliente->cedula)
                ->where('activo', 1)
                ->count(),
        ];

        // ¿Tiene al menos un contrato con RS independiente?
        // Determina si se muestra el selector de operador en el formulario.
        $tieneContratoIndependiente = false;
        if ($contratos->count() > 0) {
            $rsIds = $contratos->pluck('razon_social_id')->filter()->unique()->values()->toArray();
            if (! empty($rsIds)) {
                $tieneContratoIndependiente = DB::table('razones_sociales')
                    ->whereIn('id', $rsIds)
                    ->where('es_independiente', true)
                    ->exists();
            }
        }

        // Todos los operadores de planilla (sin filtro de activos: el usuario puede asignar cualquiera)
        $operadoresPlanilla = DB::table('operadores_planilla')
            ->whereNull('aliado_id')   // solo globales (no del aliado específico)
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'codigo_ni']);

        $bancos = \App\Models\BancoCuenta::paraFacturacion(session('aliado_id_activo'));

        return view('admin.clientes.form', compact(
            'cliente', 'lookups', 'contratos', 'razonesMap', 'resumen', 'bancos',
            'tieneContratoIndependiente', 'operadoresPlanilla', 'isIframe', 'ultimosPagos'
        ));
    }

    // ─── Actualizar ───────────────────────────────────────────────────
    public function update(Request $request, int $id)
    {
        $aliadoId = session('aliado_id_activo');
        $cliente = Cliente::where('aliado_id', $aliadoId)->findOrFail($id);
        $data = $this->validarCliente($request, $id);
        $data = $this->limpiarDatos($data);

        $cliente->update($data);

        $redirectUrl = route('admin.clientes.edit', $id);
        if ($request->input('iframe')) {
            $redirectUrl .= '?iframe=1';
        }

        return redirect($redirectUrl)
            ->with('success', 'Cliente actualizado correctamente.');
    }

    // ─── Buscar cliente por cédula (AJAX) ─────────────────────────────
    public function buscarPorCedula(Request $request)
    {
        $cedula = $request->get('cedula');
        $aliadoId = session('aliado_id_activo');
        if (! $cedula) {
            return response()->json(null);
        }

        // El tipo llega del selector del modal. Se valida contra el catálogo
        // porque va directo en la URL del operador; cualquier cosa rara cae
        // a CC, que es el 94% de los clientes.
        $tipoDoc = strtoupper((string) $request->get('tipo_doc', 'CC'));
        if (! array_key_exists($tipoDoc, Cliente::TIPOS_DOC)) {
            $tipoDoc = 'CC';
        }

        // El duplicado se busca solo por número, a propósito: el mismo número
        // con otro tipo puede ser la misma persona que cambió de documento
        // (TI → CC al cumplir 18, PE → CC al cedularse), y crear un segundo
        // registro sería peor que avisar de más. Por eso se devuelve el
        // tipo_doc del que ya existe: si no coincide, el asesor lo ve.
        $cliente = Cliente::where('cedula', $cedula)
            ->where('aliado_id', $aliadoId)
            ->first();

        if ($cliente) {
            return response()->json([
                'encontrado' => true,
                'id' => $cliente->id,
                'nombre' => $cliente->nombre_completo,
                'tipo_doc' => $cliente->tipo_doc ?: 'CC',
                'url_editar' => route('admin.clientes.edit', $cliente->id),
                'eps' => $cliente->eps_nombre ?? null,
                'celular' => $cliente->celular ?? null,
                'oficial' => $this->consultarRegistroOficial($aliadoId, $cedula, $tipoDoc),
            ]);
        }

        return response()->json([
            'encontrado' => false,
            'oficial' => $this->consultarRegistroOficial($aliadoId, $cedula, $tipoDoc),
        ]);
    }

    /**
     * El registro oficial (BDUA/RUAF) se consulta a través del operador de
     * planilla. La lógica vive en el servicio porque también la usa la API
     * que consume Cuenta_facil.
     */
    private function consultarRegistroOficial(int $aliadoId, string $cedula, string $tipoDoc = 'CC'): ?array
    {
        return app(\App\Services\RegistroOficialService::class)
            ->consultar($aliadoId, $cedula, $tipoDoc);
    }

    // ─── Helpers Privados ─────────────────────────────────────────────

    private function validarCliente(Request $request, ?int $id = null): array
    {
        $aliadoId = session('aliado_id_activo');

        // Regla unique compuesta (cedula + aliado_id):
        // - En store ($id=null): la cédula no debe existir en este aliado.
        // - En update ($id!=null): ignorar el propio registro, pero no permitir
        //   cambiar a una cédula que ya usa OTRO cliente del mismo aliado.
        $reglaCedula = Rule::unique('clientes', 'cedula')
            ->where('aliado_id', $aliadoId)
            ->when($id !== null, fn ($rule) => $rule->ignore($id));

        return $request->validate([
            // Contra el catálogo, no un string libre: un cliente es persona
            // natural y el NIT es de la empresa. Así entró el 'NI' de los
            // registros legacy y no debe volver a entrar.
            'tipo_doc' => ['nullable', Rule::in(array_keys(Cliente::TIPOS_DOC))],
            'cod_empresa' => 'nullable|integer',
            'cedula' => ['required', 'numeric', $reglaCedula],
            'primer_nombre' => 'required|string|max:55',
            'segundo_nombre' => 'nullable|string|max:55',
            'primer_apellido' => 'required|string|max:55',
            'segundo_apellido' => 'nullable|string|max:55',
            'genero' => 'nullable|string|max:10',
            'fecha_nacimiento' => 'nullable|date',
            'fecha_expedicion' => 'nullable|date',
            'telefono' => 'nullable|string|max:20',
            // A este celular salen las planillas por WhatsApp de los clientes
            // individuales y de los que están dentro de una empresa, así que
            // se valida contra la misma regla que usa el envío: un campo con
            // dos números metidos o un fijo lo rechaza Meta sin avisar, y el
            // mensaje simplemente no llega.
            'celular' => ['nullable', 'string', 'max:20', function ($atributo, $valor, $fallar) {
                if (filled($valor) && ! \App\Services\WhatsappApiService::esCelularColombiano($valor)) {
                    $fallar("El celular («{$valor}») no es un celular colombiano. Debe ser un solo número de diez dígitos que empiece por 3; si hay dos, deja el que recibe WhatsApp.");
                }
            }],
            'correo' => 'nullable|string|max:100',
            'rh' => 'nullable|string|max:10',
            'departamento_id' => 'nullable|integer',
            'municipio_id' => 'nullable|integer',
            'direccion_vivienda' => 'nullable|string|max:150',
            'direccion_cobro' => 'nullable|string|max:150',
            'ocupacion' => 'nullable|string|max:80',
            'referido' => 'nullable|string|max:80',
            'eps_id' => 'nullable|integer',
            'pension_id' => 'nullable|integer',
            'operador_planilla_id' => 'nullable|integer',
            'sisben' => 'nullable|string|max:50',
            'ips' => 'nullable|string|max:100',
            'iva' => 'nullable|string|max:20',
            'observacion' => 'nullable|string',
        ], [
            'cedula.required' => 'La cédula es obligatoria.',
            'cedula.unique' => 'Ya existe un cliente con esta cédula registrado en este aliado.',
            'primer_nombre.required' => 'El primer nombre es obligatorio.',
            'primer_apellido.required' => 'El primer apellido es obligatorio.',
        ]);
    }

    private function limpiarDatos(array $data): array
    {
        // Celular: los diez dígitos y nada más. El cast a int de antes
        // desbordaba con un campo de dos números —veinte dígitos no caben en
        // PHP_INT_MAX— y guardaba el tope del entero como si fuera un celular.
        if (isset($data['celular']) && $data['celular'] !== null) {
            $data['celular'] = \App\Services\WhatsappApiService::formatoNacional((string) $data['celular']) ?: null;
        }
        // EPS y Pensión: NULL si vacío
        foreach (['eps_id', 'pension_id'] as $campo) {
            if (empty($data[$campo])) {
                $data[$campo] = null;
            }
        }
        // Departamento/Municipio: NULL si vacío
        foreach (['departamento_id', 'municipio_id'] as $campo) {
            if (empty($data[$campo])) {
                $data[$campo] = null;
            }
        }
        // cod_empresa: NULL si vacío
        if (empty($data['cod_empresa'])) {
            $data['cod_empresa'] = null;
        }

        return $data;
    }

    private function getLookups(): array
    {
        $departamentos = DB::table('departamentos')
            ->orderBy('nombre')
            ->pluck('nombre', 'id')
            ->toArray();

        $ciudades = DB::table('ciudades')
            ->orderBy('nombre')
            ->select('id', 'departamento_id', 'nombre')
            ->get();

        return [
            'eps' => Cliente::listaEps(),
            'pension' => Cliente::listaPension(),
            'arl' => DB::table('arls')->orderBy('nombre_arl')->pluck('nombre_arl', 'id')->toArray(),
            'caja' => DB::table('cajas')->orderBy('nombre')->pluck('nombre', 'id')->toArray(),
            'razon_social' => Cliente::listaRazonSocial(),
            'asesores' => Cliente::listaAsesores(),
            'empresas' => \App\Models\Empresa::where('aliado_id', session('aliado_id_activo'))
                ->orderBy('empresa')
                ->get(['id', 'empresa']),
            'departamentos' => $departamentos,
            'ciudades' => $ciudades,
            'tipos_doc' => Cliente::TIPOS_DOC,
            'generos' => ['M' => 'Masculino', 'F' => 'Femenino'],
            'rh' => ['O+' => 'O+', 'O-' => 'O-', 'A+' => 'A+', 'A-' => 'A-', 'B+' => 'B+', 'B-' => 'B-', 'AB+' => 'AB+', 'AB-' => 'AB-'],
            'sisben' => ['NC' => 'NC - Sin Sisben', 'A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D'],
        ];
    }
}
