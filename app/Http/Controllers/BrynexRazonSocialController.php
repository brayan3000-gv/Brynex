<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\BrynexObligacion;
use App\Models\BrynexRazonSocial;
use App\Models\RazonSocialCredencial;
use App\Models\User;
use App\Services\BrynexRazonSocialService;
use App\Services\LectorDocumentoRazonSocialService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Administración de razones sociales de BryNex.
 *
 * ── Por qué este módulo cruza aliados a propósito ────────────────────────
 *
 * Todo lo demás en BryNex filtra por `session('aliado_id_activo')`. Aquí no:
 * la pregunta que responde el módulo ("¿cuántas personas hay afiliadas a esta
 * empresa?") no tiene respuesta dentro de un solo aliado, porque la misma
 * razón social la usan varios. El aislamiento lo da el permiso, no el filtro:
 * `brynex_razones.*` está marcado `solo_brynex`, así que el Gate::before le
 * cierra la puerta a cualquier usuario que no sea de la casa.
 */
class BrynexRazonSocialController extends Controller
{
    public function __construct(private BrynexRazonSocialService $servicio)
    {
        $this->middleware(['auth']);
    }

    // ─── Listado: todas las razones sociales, agrupadas por NIT ───────

    public function index(Request $request)
    {
        $buscar = trim((string) $request->get('buscar'));
        $filtro = $request->get('filtro', 'todas'); // todas | seguidas | sin_seguir | propias
        $alidoId = $request->get('aliado');

        // 1) Las razones sociales de todos los aliados, agrupadas por NIT.
        //    Se descartan los NIT de menos de 9 dígitos: en la BD hay basura
        //    ('2', '5', '333333') de pruebas y de la migración legacy.
        $grupos = DB::table('razones_sociales')
            ->whereNotNull('nit')
            ->where('nit', '>=', 100000000)
            ->when($alidoId, fn ($q) => $q->where('aliado_id', $alidoId))
            ->groupBy('nit')
            ->get([
                'nit',
                DB::raw('MAX(razon_social) as razon_social'),
                // El DV y la fecha de constitución ya los tiene el aliado en su
                // fila: no hay por qué volver a pedirlos al poner la razón
                // social en seguimiento. MAX() se queda con el valor no nulo
                // cuando unos aliados lo tienen y otros no.
                DB::raw('MAX(dv) as dv'),
                DB::raw('MAX(fecha_constitucion) as fecha_constitucion'),
                DB::raw('COUNT(DISTINCT aliado_id) as n_aliados'),
                DB::raw('COUNT(*) as n_filas'),
                DB::raw("SUM(CASE WHEN estado = 'Activa' THEN 1 ELSE 0 END) as n_activas"),
            ]);

        // 2) Afiliados vigentes por NIT, en una sola consulta para las 249.
        $vigentes = DB::table('contratos as c')
            ->join('razones_sociales as rs', 'rs.id', '=', 'c.razon_social_id')
            ->where('c.estado', 'vigente')
            ->whereNotNull('rs.nit')
            ->groupBy('rs.nit')
            // Con alias, no con pluck(DB::raw('COUNT(*)')): sqlsrv devuelve la
            // columna llamada literalmente «COUNT(*)» y pluck no la encuentra.
            ->get(['rs.nit', DB::raw('COUNT(*) as total')])
            ->pluck('total', 'nit');

        // 3) Las fichas que ya existen.
        $fichas = BrynexRazonSocial::all()->keyBy('nit');

        $filas = $grupos->map(function ($g) use ($vigentes, $fichas) {
            $ficha = $fichas->get($g->nit);

            return (object) [
                'nit' => $g->nit,
                'razon_social' => $ficha->razon_social ?? $g->razon_social,
                'dv' => $g->dv,
                'fecha_constitucion' => $g->fecha_constitucion
                    ? \Carbon\Carbon::parse($g->fecha_constitucion)->toDateString()
                    : null,
                'n_aliados' => (int) $g->n_aliados,
                'n_activas' => (int) $g->n_activas,
                'afiliados' => (int) ($vigentes[$g->nit] ?? 0),
                'ficha' => $ficha,
                'seguida' => (bool) $ficha?->en_seguimiento,
                'propiedad' => $ficha->propiedad ?? null,
                'regimen' => $ficha->regimen ?? null,
            ];
        });

        // Una ficha cuyo NIT no está en `razones_sociales` no aparecería: el
        // listado se arma desde esa tabla. Pasa con las que se siguen solo por
        // la DIAN y con las que ningún aliado ha registrado todavía, y sin
        // esto quedan inalcanzables salvo escribiendo la URL a mano.
        //
        // No se agregan cuando se filtra por aliado: no pertenecen a ninguno.
        if (! $alidoId) {
            $yaListadas = $filas->pluck('nit')->map(fn ($n) => (string) $n)->flip();

            foreach ($fichas as $nit => $ficha) {
                if ($yaListadas->has((string) $nit)) {
                    continue;
                }

                $filas->push((object) [
                    'nit' => $nit,
                    'razon_social' => $ficha->razon_social,
                    'dv' => $ficha->dv,
                    'fecha_constitucion' => $ficha->fecha_constitucion?->toDateString(),
                    'n_aliados' => 0,
                    'n_activas' => 0,
                    'afiliados' => 0,
                    'ficha' => $ficha,
                    'seguida' => (bool) $ficha->en_seguimiento,
                    'propiedad' => $ficha->propiedad,
                    'regimen' => $ficha->regimen,
                ]);
            }
        }

        if ($buscar !== '') {
            $filas = $filas->filter(
                fn ($f) => stripos($f->razon_social, $buscar) !== false
                    || str_contains((string) $f->nit, preg_replace('/\D/', '', $buscar) ?: '§')
            );
        }

        $filas = match ($filtro) {
            'seguidas' => $filas->filter(fn ($f) => $f->seguida),
            'sin_seguir' => $filas->filter(fn ($f) => ! $f->seguida),
            'propias' => $filas->filter(fn ($f) => $f->propiedad === 'brynex'),
            default => $filas,
        };

        // Las que se están siguiendo van arriba: son las que el contador
        // abre todos los días. Dentro de cada grupo manda el tamaño.
        $filas = $filas->sortBy([
            ['seguida', 'desc'],
            ['afiliados', 'desc'],
        ])->values();

        // Paginación en PHP: son ~200 NIT y ya vinieron todos agrupados; pedir
        // la página al servidor costaría otro viaje de 250 ms sin ganar nada.
        $porPagina = 30;
        $pagina = max(1, (int) $request->get('page', 1));
        $razones = new LengthAwarePaginator(
            $filas->forPage($pagina, $porPagina),
            $filas->count(),
            $porPagina,
            $pagina,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('brynex.razones_sociales.index', [
            'razones' => $razones,
            'buscar' => $buscar,
            'filtro' => $filtro,
            'alidoId' => $alidoId,
            'aliados' => DB::table('aliados')->orderBy('nombre')->get(['id', 'nombre']),
            'resumen' => $this->resumenGeneral($filas),
        ]);
    }

    /** Las cifras de la cabecera del listado. */
    private function resumenGeneral($filas): array
    {
        return [
            'total' => $filas->count(),
            'seguidas' => $filas->where('seguida', true)->count(),
            'propias' => $filas->where('propiedad', 'brynex')->count(),
            'afiliados' => $filas->sum('afiliados'),
        ];
    }

    // ─── Leer la cámara o el RUT para no digitar ──────────────────────

    /**
     * Recibe un certificado de cámara de comercio o un RUT y devuelve los
     * datos que trae, para que el formulario se llene solo.
     *
     * El PDF NO se guarda aquí: esto solo lee. Guardarlo como soporte es otra
     * decisión, y se hace desde la ficha una vez creada.
     */
    public function leerDocumento(Request $request, LectorDocumentoRazonSocialService $lector)
    {
        $request->validate([
            'archivo' => 'required|file|mimes:pdf|max:15360',
            'nit' => 'nullable|numeric',
        ], [
            'archivo.mimes' => 'Tiene que ser un PDF.',
            'archivo.max' => 'El archivo no puede pesar más de 15 MB.',
        ]);

        $resultado = $lector->leer($request->file('archivo'), $request->get('nit'));

        return response()->json($resultado, $resultado['ok'] ? 200 : 422);
    }

    // ─── Poner una razón social en seguimiento ────────────────────────

    /**
     * Crea la ficha maestra. La fecha de constitución se pide aquí y es
     * obligatoria: marca desde qué año se genera el checklist, y está vacía en
     * 203 de las 249 filas de `razones_sociales`, así que no se puede heredar.
     */
    public function seguir(Request $request)
    {
        $datos = $request->validate([
            'nit' => 'required|numeric',
            'razon_social' => 'required|string|max:255',
            'dv' => 'nullable|integer|min:0|max:9',
            'propiedad' => 'required|in:brynex,tercero',
            'regimen' => 'required|in:RST,ORDINARIO',
            'periodicidad_iva' => 'required|in:no_responsable,bimestral,cuatrimestral,anual',
            'fecha_constitucion' => 'required|date|before_or_equal:today',
            'municipio_ica' => 'nullable|string|max:120',
            'periodicidad_ica' => 'nullable|in:bimestral,anual',
            'firma_electronica_vence' => 'nullable|date',
            'contador_id' => 'nullable|integer|exists:users,id',
            // Vienen del RUT leído, separadas por coma: '07,09,14,42,47,48'.
            'responsabilidades_rut_texto' => 'nullable|string|max:120',
        ], [
            'fecha_constitucion.required' => 'La fecha de constitución es obligatoria: define desde qué año se genera el checklist.',
        ]);

        // Sin ellas no se generan la retención ni la exógena, así que se
        // guardan desde el primer momento si el RUT las trajo.
        $datos['responsabilidades_rut'] = array_values(array_filter(
            array_map('trim', explode(',', $datos['responsabilidades_rut_texto'] ?? ''))
        ));
        unset($datos['responsabilidades_rut_texto']);

        if (BrynexRazonSocial::where('nit', $datos['nit'])->exists()) {
            return back()->with('error', 'Esa razón social ya tiene ficha.');
        }

        $datos['en_seguimiento'] = true;
        $datos['creado_por'] = auth()->id();

        $ficha = BrynexRazonSocial::create($datos);

        $vinculos = $this->servicio->sincronizarVinculos($ficha);
        $generadas = $this->servicio->generarObligaciones($ficha);

        Bitacora::registrar(
            'created', 'BrynexRazonSocial', $ficha->id,
            "Razón social {$ficha->razon_social} puesta en seguimiento",
            ['nit' => $ficha->nit, 'vinculos' => $vinculos, 'obligaciones' => $generadas]
        );

        return redirect()
            ->route('brynex.razones.show', $ficha->id)
            ->with('success', "Ficha creada: {$vinculos} razón(es) social(es) enlazada(s) y {$generadas} obligaciones generadas.");
    }

    // ─── Afiliados vigentes en Excel ──────────────────────────────────

    /**
     * Descarga los afiliados vigentes de un NIT, de todos los aliados.
     *
     * El listado muestra el conteo cruzado por NIT; esto es la lista detrás
     * de ese número. Va por NIT y no por ficha a propósito: la mayoría de las
     * razones sociales del listado no tienen ficha creada y el contador
     * necesita la lista igual.
     *
     * La última columna es el aliado: es la única forma de saber a quién
     * reclamarle por una persona de la lista.
     */
    public function afiliadosExcel(Request $request)
    {
        $nit = (int) preg_replace('/\D/', '', (string) $request->get('nit'));

        abort_if($nit < 100000000, 404, 'NIT inválido.');

        $rs = DB::table('razones_sociales')->where('nit', $nit)->first(['razon_social']);
        abort_if(! $rs, 404, 'Ese NIT no está registrado por ningún aliado.');

        // Cruza aliados a propósito, igual que el listado: el join a clientes
        // sí lleva el aliado, porque la cédula sola trae la ficha de otro.
        $filas = DB::table('contratos as c')
            ->join('razones_sociales as rs', 'rs.id', '=', 'c.razon_social_id')
            ->join('aliados as al', 'al.id', '=', 'c.aliado_id')
            ->leftJoin('clientes as cl', function ($j) {
                $j->on('cl.cedula', '=', 'c.cedula')->on('cl.aliado_id', '=', 'c.aliado_id');
            })
            ->leftJoin('empresas as em', function ($j) {
                $j->on('em.id', '=', 'cl.cod_empresa')->on('em.aliado_id', '=', 'cl.aliado_id');
            })
            ->leftJoin('eps as e', 'e.id', '=', 'c.eps_id')
            ->leftJoin('pensiones as p', 'p.id', '=', 'c.pension_id')
            ->leftJoin('cajas as cj', 'cj.id', '=', 'c.caja_id')
            ->leftJoin('arls as ar', 'ar.id', '=', 'c.arl_id')
            ->leftJoin('tipo_modalidad as tm', 'tm.id', '=', 'c.tipo_modalidad_id')
            ->leftJoin('planes_contrato as pl', 'pl.id', '=', 'c.plan_id')
            ->where('rs.nit', $nit)
            ->where('c.estado', 'vigente')
            ->orderBy('al.nombre')
            ->orderBy('cl.primer_apellido')
            ->get([
                'c.cedula', 'c.cargo', 'c.fecha_ingreso', 'c.salario', 'c.ibc', 'c.n_arl',
                'cl.tipo_doc', 'cl.celular', 'cl.telefono', 'cl.correo',
                DB::raw("LTRIM(RTRIM(ISNULL(cl.primer_nombre,'')+' '+ISNULL(cl.segundo_nombre,'')+' '+ISNULL(cl.primer_apellido,'')+' '+ISNULL(cl.segundo_apellido,''))) as nombre_completo"),
                'rs.razon_social',
                'em.empresa',
                'e.nombre as eps_nombre',
                'p.razon_social as pension_nombre',
                'cj.nombre as caja_nombre',
                'ar.nombre_arl as arl_nombre',
                'tm.tipo_modalidad as modalidad_nombre',
                'pl.nombre as plan_nombre',
                'al.nombre as aliado',
            ]);

        $archivo = 'afiliados_'.$nit.'_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($filas, $nit) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM: sin esto Excel parte las tildes
            fputcsv($out, [
                'NIT', 'Razón social', 'Tipo doc', 'Cédula', 'Nombre completo',
                'Celular', 'Teléfono', 'Correo', 'Empresa',
                'EPS', 'Pensión', 'Caja', 'ARL', 'Nivel ARL',
                'Modalidad', 'Plan', 'Cargo', 'Fecha ingreso', 'Salario', 'IBC',
                'Aliado',
            ], ';');

            foreach ($filas as $f) {
                fputcsv($out, [
                    $nit,
                    $f->razon_social,
                    $f->tipo_doc,
                    $f->cedula,
                    // Un contrato cuyo cliente no está en el mismo aliado deja
                    // el nombre vacío: se marca en vez de dejar la celda muda.
                    $f->nombre_completo !== '' ? $f->nombre_completo : '(sin ficha de cliente)',
                    $f->celular,
                    $f->telefono,
                    $f->correo,
                    $f->empresa,
                    $f->eps_nombre,
                    $f->pension_nombre,
                    $f->caja_nombre,
                    $f->arl_nombre,
                    $f->n_arl,
                    $f->modalidad_nombre,
                    $f->plan_nombre,
                    $f->cargo,
                    sqldate($f->fecha_ingreso)?->format('d/m/Y'),
                    $f->salario,
                    $f->ibc,
                    $f->aliado,
                ], ';');
            }

            fclose($out);
        }, $archivo, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ─── Ficha ────────────────────────────────────────────────────────

    public function show(Request $request, int $id)
    {
        $ficha = BrynexRazonSocial::findOrFail($id);
        $anio = (int) $request->get('anio', now()->year);

        // Los vínculos se resincronizan al abrir: si un aliado creó ayer otra
        // fila con este NIT, aparece sin que nadie tenga que acordarse.
        $this->servicio->sincronizarVinculos($ficha);

        // El año de la pestaña es el del PLAZO, no el gravable.
        //
        // `anio` guarda el año gravable, que es lo correcto para cruzar contra
        // el calendario. Pero el contador trabaja por plazo: la declaración
        // anual consolidada del año gravable 2025 se presenta en abril de 2026,
        // y buscarla en la pestaña de 2025 no tiene sentido — en 2025 no había
        // nada que hacer con ella.
        //
        // Cuando todavía no hay calendario cargado no se cae al año gravable a
        // secas, sino al año gravable más el desfase de la obligación: la
        // declaración anual del 2026 se presenta en 2027, aunque su fecha
        // exacta no se sepa hasta que la DIAN publique el calendario. Sin eso,
        // la anual aparecía dos veces en la misma pestaña — la del año pasado
        // con su fecha real y la del año en curso sin fecha.
        // Sin alias sobre `brynex_obligaciones`: el scope de SoftDeletes
        // califica `deleted_at` con el nombre real de la tabla y con alias
        // responde «multi-part identifier could not be bound».
        $t = 'brynex_obligaciones';
        $porPlazo = "COALESCE(YEAR({$t}.fecha_vencimiento), {$t}.anio + ISNULL(cat.anios_desfase, 0))";

        $base = fn () => $ficha->obligaciones()
            ->leftJoin('brynex_obligaciones_catalogo as cat', 'cat.codigo', '=', "{$t}.obligacion_codigo");

        // Un trámite de una sola vez que sigue pendiente se muestra también en
        // el año en curso: si la habilitación venció en 2023 y nadie la hizo,
        // esconderla en la pestaña de 2023 es como no tenerla.
        $esteAnio = (int) now()->year;

        $obligaciones = $base()
            ->with('documentos')
            ->whereRaw(
                "({$porPlazo} = ? OR (cat.periodicidad = 'unica' AND {$t}.estado NOT IN ('pagada', 'no_aplica') AND ? = ?))",
                [$anio, $anio, $esteAnio]
            )
            ->orderByRaw("CASE WHEN {$t}.fecha_vencimiento IS NULL THEN 1 ELSE 0 END")
            ->orderBy("{$t}.fecha_vencimiento")
            ->orderBy('cat.orden')
            ->orderBy("{$t}.periodo")
            ->select("{$t}.*")
            ->get();

        $aniosConDatos = $base()
            ->selectRaw("DISTINCT {$porPlazo} as anio")
            ->orderByDesc('anio')
            ->pluck('anio');

        return view('brynex.razones_sociales.show', [
            'ficha' => $ficha,
            'anio' => $anio,
            'anios' => $aniosConDatos,
            'obligaciones' => $obligaciones,
            'catalogo' => \App\Models\BrynexObligacionCatalogo::orderBy('orden')->get()->keyBy('codigo'),
            'afiliados' => $this->servicio->afiliadosActivos($ficha),
            'movimientos' => $this->servicio->movimientos($ficha, $anio),
            'credenciales' => $ficha->credenciales()->where('activo', true)->orderBy('tipo')->get(),
            // Con join y no con with('aliado'): son dos consultas de 170 ms
            // para pintar una lista de etiquetas.
            // Trae también el representante que cada aliado tiene anotado: de
            // ahí sale la tarjeta del representante sin otra consulta.
            'vinculos' => DB::table('brynex_razon_social_vinculos as v')
                ->leftJoin('aliados as a', 'a.id', '=', 'v.aliado_id')
                ->leftJoin('razones_sociales as rs', 'rs.id', '=', 'v.razon_social_id')
                ->where('v.ficha_id', $ficha->id)
                ->orderBy('a.nombre')
                ->get(['v.razon_social_id', 'v.aliado_id', 'a.nombre as aliado', 'rs.cedula_rep', 'rs.nombre_rep']),
            // Solo cuando se puso a mano: el nombre no está en ningún aliado.
            'representanteManual' => $ficha->representante_cedula
                ? \App\Models\BrynexRepresentante::where('cedula', $ficha->representante_cedula)->first(['cedula', 'nombre'])
                : null,
            'contadores' => User::where('es_brynex', true)->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            // Se le pasan las que ya se cargaron arriba: son exactamente las
            // mismas (todo el año), y otra consulta son 250 ms regalados.
            'resumenChecklist' => $this->resumenChecklist($obligaciones),
        ]);
    }

    /** Semáforo del año: cuántas al día, cuántas por vencer, cuántas vencidas. */
    private function resumenChecklist($obligaciones): array
    {
        $conteo = ['verde' => 0, 'amarillo' => 0, 'rojo' => 0, 'gris' => 0];

        foreach ($obligaciones as $o) {
            $conteo[$o->semaforo()]++;
        }

        return $conteo + [
            'total' => $obligaciones->count(),
            'pagado' => (float) $obligaciones->sum('valor_pagado'),
        ];
    }

    // ─── Editar la ficha ──────────────────────────────────────────────

    public function update(Request $request, int $id)
    {
        $ficha = BrynexRazonSocial::findOrFail($id);

        $datos = $request->validate([
            'razon_social' => 'required|string|max:255',
            'dv' => 'nullable|integer|min:0|max:9',
            'propiedad' => 'required|in:brynex,tercero',
            'regimen' => 'required|in:RST,ORDINARIO',
            'periodicidad_iva' => 'required|in:no_responsable,bimestral,cuatrimestral,anual',
            'responsabilidades_rut' => 'nullable|array',
            'responsabilidades_rut.*' => 'string|max:4',
            'fecha_constitucion' => 'required|date|before_or_equal:today',
            'firma_electronica_vence' => 'nullable|date',
            'municipio_ica' => 'nullable|string|max:120',
            'periodicidad_ica' => 'nullable|in:bimestral,anual',
            'contador_id' => 'nullable|integer|exists:users,id',
            'estado' => 'required|in:activa,inactiva',
            'notas' => 'nullable|string|max:2000',
        ]);

        $antes = $ficha->only(['regimen', 'periodicidad_iva', 'periodicidad_ica', 'fecha_constitucion']);

        $datos['actualizado_por'] = auth()->id();
        $ficha->update($datos);

        // Si cambió el perfil tributario aparecen obligaciones que antes no
        // aplicaban. Se generan las que falten; las que ya estaban NO se tocan,
        // ni siquiera si dejaron de aplicar — borrar un renglón que el contador
        // ya marcó como pagada sería perder el rastro del pago.
        $generadas = 0;
        if ($ficha->only(array_keys($antes)) != $antes) {
            $generadas = $this->servicio->generarObligaciones($ficha);
        }

        Bitacora::registrar(
            'updated', 'BrynexRazonSocial', $ficha->id,
            "Ficha de {$ficha->razon_social} actualizada",
            ['antes' => $antes, 'obligaciones_nuevas' => $generadas]
        );

        $aviso = 'Ficha actualizada.';
        if ($generadas) {
            $aviso .= " Se generaron {$generadas} obligaciones nuevas por el cambio de régimen.";
        }

        return back()->with('success', $aviso);
    }

    public function dejarDeSeguir(int $id)
    {
        $ficha = BrynexRazonSocial::findOrFail($id);
        $ficha->update(['en_seguimiento' => false, 'actualizado_por' => auth()->id()]);

        Bitacora::registrar(
            'updated', 'BrynexRazonSocial', $ficha->id,
            "Se dejó de hacer seguimiento a {$ficha->razon_social}"
        );

        // No se borra nada: el checklist y los soportes se quedan. Volver a
        // seguirla la deja como estaba.
        return redirect()
            ->route('brynex.razones.index')
            ->with('success', 'Razón social fuera de seguimiento. El histórico se conserva.');
    }

    // ─── Tablero de vencimientos ──────────────────────────────────────

    /**
     * Lo que vence pronto y lo que ya se venció, de todas las razones sociales
     * seguidas. Es la pantalla de entrada del contador.
     */
    public function tablero(Request $request)
    {
        $dias = (int) $request->get('dias', 30);

        $base = BrynexObligacion::query()
            ->join('brynex_razones_sociales as f', 'f.id', '=', 'brynex_obligaciones.ficha_id')
            ->whereNull('f.deleted_at')
            ->where('f.en_seguimiento', true)
            ->where('f.estado', 'activa')
            ->select('brynex_obligaciones.*', 'f.razon_social', 'f.nit', 'f.contador_id');

        $vencidas = (clone $base)->vencidas()
            ->orderBy('fecha_vencimiento')
            ->get();

        $porVencer = (clone $base)->porVencer($dias)
            ->orderBy('fecha_vencimiento')
            ->get();

        // La firma electrónica no es una obligación, pero si caduca no se puede
        // declarar nada: va en el mismo tablero.
        $firmas = BrynexRazonSocial::seguidas()
            ->whereNotNull('firma_electronica_vence')
            ->where('firma_electronica_vence', '<=', now()->addDays(60)->toDateString())
            ->orderBy('firma_electronica_vence')
            ->get();

        return view('brynex.razones_sociales.tablero', [
            'vencidas' => $vencidas,
            'porVencer' => $porVencer,
            'firmas' => $firmas,
            'dias' => $dias,
            'catalogo' => \App\Models\BrynexObligacionCatalogo::orderBy('orden')->get()->keyBy('codigo'),
        ]);
    }

    // ─── Claves de portales ───────────────────────────────────────────

    /**
     * Revelar una contraseña. Deja rastro en la bitácora siempre.
     *
     * La del banco no la ve el contador: mueve plata de terceros, así que pide
     * el permiso restringido `brynex_razones.claves_banco`, que ningún rol
     * hereda — ni el superadmin.
     */
    public function revelarClave(int $id)
    {
        $credencial = RazonSocialCredencial::findOrFail($id);

        if ($credencial->tipo === 'BANCO' && ! auth()->user()->can('brynex_razones.claves_banco')) {
            return response()->json([
                'error' => 'La clave del banco necesita el permiso «Ver claves de banco», que se otorga usuario por usuario.',
            ], 403);
        }

        Bitacora::registrar(
            'clave_revelada', 'RazonSocialCredencial', $credencial->id,
            "Se reveló la clave de {$credencial->entidad} ({$credencial->tipo})",
            ['ficha_id' => $credencial->ficha_id, 'ip' => request()->ip()]
        );

        return response()->json(['contrasena' => $credencial->contrasena]);
    }

    public function guardarClave(Request $request, int $id)
    {
        $ficha = BrynexRazonSocial::findOrFail($id);

        $datos = $request->validate([
            'credencial_id' => 'nullable|integer',
            'tipo' => 'required|in:DIAN,BANCO,CAMARA_COMERCIO,OTRO',
            'entidad' => 'required|string|max:150',
            'usuario' => 'nullable|string|max:150',
            'contrasena' => 'nullable|string|max:200',
            'link_acceso' => 'nullable|url|max:350',
            'observacion' => 'nullable|string|max:500',
        ]);

        // `?? null` y no acceso directo: un campo `nullable` que no viene en la
        // petición tampoco viene en el array de validate().
        $credencialId = $datos['credencial_id'] ?? null;

        $credencial = $credencialId
            ? RazonSocialCredencial::where('ficha_id', $ficha->id)->findOrFail($credencialId)
            : new RazonSocialCredencial([
                'ficha_id' => $ficha->id,
                // Se conserva por auditoría: qué aliado registró la clave.
                // La lectura va por `ficha_id`, que es lo que la comparte.
                'aliado_id' => session('aliado_id_activo') ?? 1,
                'razon_social_id' => $ficha->vinculos()->value('razon_social_id'),
                'creado_por' => auth()->id(),
            ]);

        $credencial->fill([
            'tipo' => $datos['tipo'],
            'entidad' => $datos['entidad'],
            'usuario' => $datos['usuario'] ?? null,
            'link_acceso' => $datos['link_acceso'] ?? null,
            'observacion' => $datos['observacion'] ?? null,
            'actualizado_por' => auth()->id(),
            'activo' => true,
        ]);

        // Dejar la contraseña vacía en el formulario significa "no la cambies",
        // no "bórrala": si no, editar el link de acceso borraría la clave.
        if (! empty($datos['contrasena'])) {
            $credencial->contrasena = $datos['contrasena'];
        }

        $credencial->save();

        Bitacora::registrar(
            $credencialId ? 'updated' : 'created',
            'RazonSocialCredencial', $credencial->id,
            "Clave de {$credencial->entidad} en {$ficha->razon_social}",
            ['cambio_contrasena' => ! empty($datos['contrasena'])]
        );

        return back()->with('success', 'Clave guardada. Los aliados que usan esta razón social ya ven el cambio.');
    }

    public function eliminarClave(int $id)
    {
        $credencial = RazonSocialCredencial::findOrFail($id);
        $credencial->delete();

        Bitacora::registrar(
            'deleted', 'RazonSocialCredencial', $credencial->id,
            "Clave de {$credencial->entidad} eliminada"
        );

        return back()->with('success', 'Clave eliminada.');
    }
}
