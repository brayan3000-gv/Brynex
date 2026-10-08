<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Afiliaciones\DatosAfiliacion;
use App\Services\TrazaArchivoService;
use App\Models\Aliado;
use App\Models\Beneficiario;
use App\Models\Contrato;
use App\Models\DocumentoCliente;
use App\Models\Factura;
use App\Models\Radicado;
use App\Models\RadicadoMovimiento;
use App\Models\User;
use App\Traits\ResuelveArlEfectiva;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class AfiliacionController extends Controller
{
    use ResuelveArlEfectiva;

    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Vista principal del módulo de afiliaciones.
     * Filtra contratos cuya fecha_ingreso esté en el mes/año seleccionado.
     */
    public function index(Request $request)
    {
        /** @var User $user */
        $user    = Auth::user();
        $mes     = (int) $request->get('mes', now()->month);
        $anio    = (int) $request->get('anio', now()->year);
        // Encargado: default = todos si no se filtra explícitamente (se usa string vacío para no filtrar)
        $encId   = $request->has('encargado_id') ? $request->get('encargado_id') : '';

        // ── Nuevos filtros ──
        $rsId       = $request->get('razon_social_id');
        $tipoModId  = $request->get('tipo_modalidad_id');
        $epsF       = $request->get('eps_id');
        $arlF       = $request->get('arl_id');
        $cajaF      = $request->get('caja_id');
        $pensionF   = $request->get('pension_id');
        $empresaF   = $request->get('empresa_id'); // empresa cliente (clientes.cod_empresa)
        $estadoRad  = $request->get('estado_rad'); // estado del radicado
        // Estado del contrato: por defecto se muestran TODAS las afiliaciones del mes
        // (incluidos los que ya se retiraron), porque la afiliación sí ocurrió en el período.
        $estadoCont = $request->get('estado_contrato');
        if (!in_array($estadoCont, ['vigente', 'retirado'], true)) $estadoCont = '';
        $sort       = $request->get('sort', 'fecha_ingreso');
        $dir        = $request->get('dir', 'asc');

        // Whitelist de columnas ordenables
        $sortAllowed = ['fecha_ingreso', 'cedula', 'razon_social_id', 'eps_id', 'arl_id', 'caja_id', 'pension_id'];
        if (!in_array($sort, $sortAllowed)) $sort = 'fecha_ingreso';
        if (!in_array($dir, ['asc', 'desc'])) $dir = 'asc';

        // ── Aliado activo ──
        $alidoId = $this->resolverAliado($request, $user);

        // Normalmente se lista un aliado, el activo. Un usuario BryNex puede
        // pedir con el botón 🏢 los de todos los aliados cuyas afiliaciones
        // gestiona BryNex, para trabajarlos en una sola lista.
        $aliados = $this->aliadosDeLaVista($request, $user, $alidoId);
        $gestionados = count($aliados) > 1;

        // Capturar IDs del período sin filtros opcionales (para poblar selects dinámicos)
        $baseIds = Contrato::whereIn('aliado_id', $aliados)
            ->whereMonth('fecha_ingreso', $mes)
            ->whereYear('fecha_ingreso', $anio)
            ->where(fn ($q) => $this->sinRetiradosPorError($q))
            ->pluck('id');
        $baseContratos = Contrato::whereIn('id', $baseIds)
            ->get(['id','razon_social_id','tipo_modalidad_id','eps_id','arl_id','caja_id','pension_id','estado','encargado_id']);

        // La misma empresa puede estar registrada varias veces —una por aliado,
        // o repetida dentro del mismo— y en el filtro salía otras tantas. Se
        // agrupan por NIT (por nombre las que no lo tengan): en el desplegable
        // es una sola y al escogerla trae las de todos sus registros.
        $razonesPeriodo = DB::table('razones_sociales')
            ->whereIn('id', $baseContratos->pluck('razon_social_id')->filter()->unique())
            ->get(['id', 'razon_social', 'nit']);

        $grupoRazon = [];
        foreach ($razonesPeriodo->groupBy(fn ($r) => $this->claveRazon($r)) as $hermanas) {
            $ids = $hermanas->pluck('id')->map(fn ($i) => (int) $i)->all();
            foreach ($ids as $id) {
                $grupoRazon[$id] = $ids;
            }
        }
        $razonesDelFiltro = $this->razonesHermanas($rsId);

        // ── Contratos base (con eager loading) ──
        $query = Contrato::with([
            'cliente:id,tipo_doc,cedula,primer_nombre,segundo_nombre,primer_apellido,segundo_apellido,iva,cod_empresa,celular,correo,direccion_vivienda,barrio,municipio_id,pension_id',
            'cliente.empresa:id,empresa',
            'cliente.municipio:id,nombre,departamento_id',
            'cliente.municipio.departamento:id,nombre',
            'cliente.pension:id,razon_social',
            'razonSocial:id,razon_social,nit,arl_nit,es_independiente',
            'eps:id,nombre,formulario_pdf',
            'arl:id,nombre_arl,razon_social',
            'caja:id,nombre',
            'pension:id,razon_social,formulario_pdf',
            'plan:id,nombre,incluye_eps,incluye_arl,incluye_pension,incluye_caja',
            'tipoModalidad:id,tipo_modalidad,modalidad',
            'aliado:id,nombre,logo',
            'radicados' => fn($q) => $q->with([
                'movimientos' => fn($m) => $m->reorder()->orderByDesc('id')->limit(3),
                // Para los días en estado de cada radicado, en una sola consulta.
                'ultimoMovimiento',
            ]),
        ])
        ->whereIn('aliado_id', $aliados)
        ->whereMonth('fecha_ingreso', $mes)
        ->whereYear('fecha_ingreso', $anio)
        ->where(fn ($q) => $this->sinRetiradosPorError($q));

        if ($estadoCont) $query->where('estado', $estadoCont);

        // Búsqueda inteligente por nombre tokenizado y cédula
        $buscar = $this->buscarSinTipoDoc($request->get('buscar'));
        if ($buscar) {
            $query->where(function ($q) use ($buscar) {
                // Coincidencia directa en cédula (contrato)
                $q->where('cedula', 'LIKE', "%{$buscar}%")
                  ->orWhereHas('cliente', function ($qCli) use ($buscar) {
                      $qCli->where(function ($qId) use ($buscar) {
                          $qId->where('cedula', 'LIKE', "%{$buscar}%")
                              ->orWhere('celular', 'LIKE', "%{$buscar}%");
                      });

                      if (!ctype_digit(str_replace(' ', '', $buscar))) {
                          $palabras = array_filter(explode(' ', trim($buscar)));
                          $qCli->orWhere(function ($inner) use ($palabras) {
                              foreach ($palabras as $palabra) {
                                  $inner->where(function ($sub) use ($palabra) {
                                      $sub->whereSinTildes('primer_nombre', $palabra)
                                          ->orWhereSinTildes('segundo_nombre', $palabra)
                                          ->orWhereSinTildes('primer_apellido', $palabra)
                                          ->orWhereSinTildes('segundo_apellido', $palabra);
                                  });
                              }
                          });
                      }
                  });
            });
        }

        // Filtros opcionales
        if ($encId)     $query->where('encargado_id', $encId);
        if ($rsId)      $query->whereIn('razon_social_id', $razonesDelFiltro);
        if ($epsF)      $query->where('eps_id', $epsF);
        if ($arlF)      $query->where('arl_id', $arlF);
        if ($cajaF)     $query->where('caja_id', $cajaF);
        if ($pensionF)  $query->where('pension_id', $pensionF);
        if ($tipoModId) $query->where('tipo_modalidad_id', $tipoModId);
        if ($empresaF) {
            $query->whereHas('cliente', fn($q) => $q->whereIn('clientes.aliado_id', $aliados)
                                                    ->where('cod_empresa', $empresaF));
        }
        // Filtro por estado del radicado (al menos uno con ese estado)
        $estadosPermitidos = ['pendiente','tramite','traslado','error','ok'];
        if ($estadoRad && in_array($estadoRad, $estadosPermitidos)) {
            $query->whereHas('radicados', fn($q) => $q->where('estado', $estadoRad));
        }

        // Segundo filtro de cada columna: elegida la entidad, el estado de SU radicado.
        $radColF = $this->estadosPorColumna($request, compact('epsF', 'arlF', 'cajaF', 'pensionF'));
        foreach ($radColF as $tipoRad => $estadoCol) {
            // La pantalla solo dibuja la celda si el plan incluye el componente: un
            // radicado viejo de algo que el plan ya no cubre no debe contar.
            $query->whereHas('plan', fn($p) => $p->where('incluye_'.$tipoRad, true))
                  ->whereHas('radicados', fn($q) => $q->where('tipo', $tipoRad)->where('estado', $estadoCol));
        }

        // Ordenamiento
        if ($sort === 'fecha_ingreso') {
            $query->orderBy('fecha_ingreso', $dir)->orderBy('id', 'asc');
        } else {
            $query->orderBy($sort, $dir)->orderBy('fecha_ingreso', 'asc');
        }

        $contratos = $query->get();

        // Cada radicado apunta al contrato que ya está en memoria. Sin esto,
        // Radicado::esFuturoProgramado() lo volvía a pedir a la base, uno por
        // radicado: 197 consultas en el listado del aliado 7.
        $contratos->each(fn ($c) => $c->radicados->each(fn ($r) => $r->setRelation('contrato', $c)));


        // ARL desde la razón social (arl_nit) salvo en razones sociales de
        // independientes, donde cada contrato lleva su propia ARL.
        $arlsPorNit = self::arlsPorNitDeContratos($contratos);

        // Agregar ARL efectiva, tipo de contrato y aliado a cada contrato
        $contratos->each(function ($c) use ($arlsPorNit) {
            $esDep = $c->tipoModalidad?->modalidad === 'dependiente';
            $c->arl_efectiva_nombre = self::arlEfectiva($c, $arlsPorNit);
            $c->es_dependiente       = $esDep;
            $c->tipo_modalidad_label = $c->tipoModalidad?->tipo_modalidad ?? ($esDep ? 'Dependiente' : 'Independiente');
        });

        // Agregar número de factura del mes a cada contrato
        $contratoIds = $contratos->pluck('id');
        $facturas = Factura::whereIn('contrato_id', $contratoIds)
            ->whereMonth('created_at', $mes)
            ->whereYear('created_at', $anio)
            ->whereNull('deleted_at')
            ->pluck('numero_factura', 'contrato_id');

        $contratos->each(function ($c) use ($facturas) {
            $c->numero_factura_mes = $facturas->get($c->id);
        });

        // ── Datos para filtros dinámicos (basados en los contratos del período) ──
        $encargados = User::whereIn('aliado_id', $aliados)
            ->where('activo', true)
            ->orderBy('nombre')
            ->get(['id', 'nombre']);

        // Cada desplegable muestra solo lo que hay en la tabla con los demás
        // filtros puestos: con LALA Confecciones escogida, el de EPS lista las
        // EPS de LALA y no el catálogo entero. Se excluye su propio filtro para
        // no dejar el desplegable con una sola opción —la escogida— y sin
        // manera de cambiarla.
        $presentes = function (string $columna) use ($baseContratos, $rsId, $razonesDelFiltro, $tipoModId, $epsF, $arlF, $cajaF, $pensionF, $estadoCont, $encId) {
            $todos = [
                'razon_social_id' => $rsId,
                'tipo_modalidad_id' => $tipoModId,
                'eps_id' => $epsF,
                'arl_id' => $arlF,
                'caja_id' => $cajaF,
                'pension_id' => $pensionF,
                'estado' => $estadoCont,
                'encargado_id' => $encId,
            ];
            $filtros = array_diff_key($todos, [$columna => null]);

            $conteo = $baseContratos
                ->filter(function ($c) use ($filtros, $razonesDelFiltro) {
                    foreach ($filtros as $col => $valor) {
                        if (($valor ?? '') === '') {
                            continue;
                        }
                        // La razón social escogida vale por todos los registros
                        // de esa misma empresa.
                        $cabe = $col === 'razon_social_id'
                            ? in_array((int) $c->razon_social_id, $razonesDelFiltro, true)
                            : (string) $c->$col === (string) $valor;

                        if (! $cabe) {
                            return false;
                        }
                    }

                    return true;
                })
                ->groupBy(fn ($c) => (string) $c->$columna)
                ->map->count()
                ->reject(fn ($n, $id) => $id === '');

            // Lo ya escogido se mantiene en su lista aunque el resto de filtros
            // lo dejen fuera (en cero): si no, el desplegable se vería en otra
            // opción distinta a la que está filtrando.
            $actual = (string) ($todos[$columna] ?? '');
            if ($actual !== '' && ! $conteo->has($actual)) {
                $conteo[$actual] = 0;
            }

            return $conteo;
        };

        // Cada lista lleva su cuenta —"S.O.S. (3)"— para saber cuánto hay
        // detrás de cada opción antes de escogerla.
        $conteoRazon = $presentes('razon_social_id');
        $conteoModalidad = $presentes('tipo_modalidad_id');
        $conteoEps = $presentes('eps_id');
        $conteoArl = $presentes('arl_id');
        $conteoCaja = $presentes('caja_id');
        $conteoPension = $presentes('pension_id');

        $razonesDisponibles = $razonesPeriodo
            ->whereIn('id', $conteoRazon->keys()->all())
            ->groupBy(fn ($r) => implode(',', $grupoRazon[(int) $r->id] ?? [(int) $r->id]))
            ->map(function ($hermanas) use ($conteoRazon) {
                // Se muestra el registro con más afiliaciones: es el que la
                // gente reconoce, y los demás suelen ser copias a medio llenar.
                $principal = $hermanas->sortByDesc(fn ($r) => (int) ($conteoRazon[(string) $r->id] ?? 0))->first();

                return (object) [
                    'id' => $principal->id,
                    'ids' => $hermanas->pluck('id')->map(fn ($i) => (int) $i)->all(),
                    'razon_social' => $principal->razon_social,
                    'n' => $hermanas->sum(fn ($r) => (int) ($conteoRazon[(string) $r->id] ?? 0)),
                ];
            })
            ->sortBy('razon_social')
            ->values();

        $tiposModalidad = \App\Models\TipoModalidad::whereIn('id', $conteoModalidad->keys())
            ->orderBy('orden')->get(['id', 'tipo_modalidad', 'modalidad']);

        $epsDisponibles = DB::table('eps')
            ->whereIn('id', $conteoEps->keys())
            ->orderBy('nombre')->get(['id', 'nombre']);
        $arlDisponibles = DB::table('arls')
            ->whereIn('id', $conteoArl->keys())
            ->orderBy('nombre_arl')->get(['id', 'nombre_arl']);
        $cajaDisponibles = DB::table('cajas')
            ->whereIn('id', $conteoCaja->keys())
            ->orderBy('nombre')->get(['id', 'nombre']);
        $pensionDisponibles = DB::table('pensiones')
            ->whereIn('id', $conteoPension->keys())
            ->orderBy('razon_social')->get(['id', 'razon_social']);

        // Empresas cliente presentes en el período (vía cliente, que se une por
        // cédula + aliado). Se hace con JOIN y no con whereIn de cédulas para no
        // chocar con el límite de parámetros de SQL Server en meses grandes.
        $empresasDisponibles = DB::table('empresas as e')
            ->join('clientes as cl', 'cl.cod_empresa', '=', 'e.id')
            ->join('contratos as ct', function ($j) {
                $j->on('ct.cedula', '=', 'cl.cedula')
                  ->on('ct.aliado_id', '=', 'cl.aliado_id');
            })
            ->whereIn('ct.aliado_id', $aliados)
            ->whereMonth('ct.fecha_ingreso', $mes)
            ->whereYear('ct.fecha_ingreso', $anio)
            ->distinct()
            ->orderBy('e.empresa')
            ->get(['e.id', 'e.empresa']);

        // Para BryNex: lista de aliados accesibles
        $alidosDisponibles = [];
        if ($user->es_brynex) {
            $alidosDisponibles = $this->alidosParaBrynex($user);
        }

        // Razones sociales con las que se puede conciliar: las del aliado
        // activo que son empresas de verdad. Se excluyen las independientes y
        // las que no tienen un NIT real —la comodín con nit "2"—, porque contra
        // esas no hay portal al que entrar ni empresa que cruzar.
        $razonesConciliar = DB::table('razones_sociales')
            ->whereIn('aliado_id', $aliados)
            ->where('es_independiente', false)
            ->whereNotNull('nit')
            ->whereRaw('LEN(nit) >= 8')
            ->orderBy('razon_social')
            ->get(['id', 'razon_social', 'nit']);

        return view('admin.afiliaciones.index', compact(
            'razonesConciliar',
            'contratos', 'mes', 'anio', 'encId', 'encargados',
            'alidoId', 'alidosDisponibles', 'user', 'gestionados',
            'rsId', 'tipoModId', 'epsF', 'arlF', 'cajaF', 'pensionF', 'empresaF', 'estadoRad', 'estadoCont', 'radColF',
            'sort', 'dir', 'razonesDisponibles', 'tiposModalidad',
            'epsDisponibles', 'arlDisponibles', 'cajaDisponibles', 'pensionDisponibles',
            'empresasDisponibles',
            'conteoRazon', 'conteoModalidad', 'conteoEps', 'conteoArl', 'conteoCaja', 'conteoPension'
        ));
    }

    /**
     * Exporta el listado actual a Excel.
     */
    public function exportar(Request $request)
    {
        /** @var User $user */
        $user    = Auth::user();
        $mes     = (int) $request->get('mes', now()->month);
        $anio    = (int) $request->get('anio', now()->year);
        $encId   = $request->get('encargado_id');
        $alidoId = $this->resolverAliado($request, $user);
        // El Excel sale de lo mismo que está en pantalla, aliados incluidos.
        $aliados = $this->aliadosDeLaVista($request, $user, $alidoId);

        // Filtros adicionales
        $rsId       = $request->get('razon_social_id');
        $razonesDelFiltro = $this->razonesHermanas($rsId);
        $tipoModId  = $request->get('tipo_modalidad_id');
        $epsF       = $request->get('eps_id');
        $arlF       = $request->get('arl_id');
        $cajaF      = $request->get('caja_id');
        $pensionF   = $request->get('pension_id');
        $empresaF   = $request->get('empresa_id');
        $estadoRad  = $request->get('estado_rad');
        $estadoCont = $request->get('estado_contrato');
        if (!in_array($estadoCont, ['vigente', 'retirado'], true)) $estadoCont = '';

        $query = Contrato::with([
            'cliente:cedula,primer_nombre,primer_apellido',
            'razonSocial:id,razon_social,arl_nit,es_independiente',
            'eps:id,nombre,formulario_pdf',
            'arl:id,nombre_arl,razon_social',
            'caja:id,nombre',
            'pension:id,razon_social,formulario_pdf',
            'encargado:id,nombre',
            'radicados',
            'aliado:id,nombre',
        ])
        ->whereIn('aliado_id', $aliados)
        ->whereMonth('fecha_ingreso', $mes)
        ->whereYear('fecha_ingreso', $anio)
        ->where(fn ($q) => $this->sinRetiradosPorError($q));

        if ($estadoCont) $query->where('estado', $estadoCont);

        // Búsqueda inteligente por nombre tokenizado y cédula
        $buscar = $this->buscarSinTipoDoc($request->get('buscar'));
        if ($buscar) {
            $query->where(function ($q) use ($buscar) {
                // Coincidencia directa en cédula (contrato)
                $q->where('cedula', 'LIKE', "%{$buscar}%")
                  ->orWhereHas('cliente', function ($qCli) use ($buscar) {
                      $qCli->where(function ($qId) use ($buscar) {
                          $qId->where('cedula', 'LIKE', "%{$buscar}%")
                              ->orWhere('celular', 'LIKE', "%{$buscar}%");
                      });

                      if (!ctype_digit(str_replace(' ', '', $buscar))) {
                          $palabras = array_filter(explode(' ', trim($buscar)));
                          $qCli->orWhere(function ($inner) use ($palabras) {
                              foreach ($palabras as $palabra) {
                                  $inner->where(function ($sub) use ($palabra) {
                                      $sub->whereSinTildes('primer_nombre', $palabra)
                                          ->orWhereSinTildes('segundo_nombre', $palabra)
                                          ->orWhereSinTildes('primer_apellido', $palabra)
                                          ->orWhereSinTildes('segundo_apellido', $palabra);
                                  });
                              }
                          });
                      }
                  });
            });
        }

        if ($encId)     $query->where('encargado_id', $encId);
        if ($rsId)      $query->whereIn('razon_social_id', $razonesDelFiltro);
        if ($epsF)      $query->where('eps_id', $epsF);
        if ($arlF)      $query->where('arl_id', $arlF);
        if ($cajaF)     $query->where('caja_id', $cajaF);
        if ($pensionF)  $query->where('pension_id', $pensionF);
        if ($tipoModId) $query->where('tipo_modalidad_id', $tipoModId);
        if ($empresaF) {
            $query->whereHas('cliente', fn($q) => $q->whereIn('clientes.aliado_id', $aliados)
                                                    ->where('cod_empresa', $empresaF));
        }

        $estadosPermitidos = ['pendiente','tramite','traslado','error','ok'];
        if ($estadoRad && in_array($estadoRad, $estadosPermitidos)) {
            $query->whereHas('radicados', fn($q) => $q->where('estado', $estadoRad));
        }

        foreach ($this->estadosPorColumna($request, compact('epsF', 'arlF', 'cajaF', 'pensionF')) as $tipoRad => $estadoCol) {
            // La pantalla solo dibuja la celda si el plan incluye el componente: un
            // radicado viejo de algo que el plan ya no cubre no debe contar.
            $query->whereHas('plan', fn($p) => $p->where('incluye_'.$tipoRad, true))
                  ->whereHas('radicados', fn($q) => $q->where('tipo', $tipoRad)->where('estado', $estadoCol));
        }

        $contratos = $query->orderBy('fecha_ingreso', 'asc')->get();

        // Misma ARL efectiva que muestra la pantalla
        $arlsPorNit = self::arlsPorNitDeContratos($contratos);
        $contratos->each(fn($c) => $c->arl_efectiva_nombre = self::arlEfectiva($c, $arlsPorNit));

        // Obtener facturas
        $contratoIds = $contratos->pluck('id');
        $facturas = Factura::whereIn('contrato_id', $contratoIds)
            ->whereMonth('created_at', $mes)->whereYear('created_at', $anio)
            ->whereNull('deleted_at')
            ->pluck('numero_factura', 'contrato_id');

        $spreadsheet = new Spreadsheet();
        // Traza invisible de quién exportó (propiedades del documento).
        app(TrazaArchivoService::class)->marcarExcel($spreadsheet);
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Afiliaciones');

        $meses = ['', 'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

        // Encabezado
        $headers = [
            'Aliado', 'Razón Social', 'Día', 'Factura', 'Cédula', 'Nombres',
            'EPS', 'Estado EPS', 'ARL', 'Nivel ARL', 'Estado ARL',
            'Caja', 'Estado Caja', 'Pensión', 'Estado Pensión',
            'Encargado', 'Observación', 'Estado',
        ];
        $sheet->fromArray($headers, null, 'A1');

        // Estilo encabezado
        $sheet->getStyle('A1:R1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1e40af']],
        ]);

        $row = 2;
        foreach ($contratos as $c) {
            $radicados = $c->radicados->keyBy('tipo');

            $sheet->fromArray([
                $c->aliado?->nombre ?? '—',
                $c->razonSocial?->razon_social ?? '—',
                $c->fecha_ingreso?->format('d') ?? '',
                $facturas->get($c->id) ?? '',
                $c->cedula,
                trim(($c->cliente?->primer_nombre ?? '') . ' ' . ($c->cliente?->primer_apellido ?? '')),
                $c->eps?->nombre ?? '—',
                strtoupper($radicados->get('eps')?->estado ?? '—'),
                $c->arl_efectiva_nombre,
                $c->n_arl ?? '',
                strtoupper($radicados->get('arl')?->estado ?? '—'),
                $c->caja?->nombre ?? '—',
                strtoupper($radicados->get('caja')?->estado ?? '—'),
                $c->pension?->razon_social ?? '—',
                strtoupper($radicados->get('pension')?->estado ?? '—'),
                $c->encargado?->nombre ?? '—',
                $c->observacion_afiliacion ?? '',
                strtoupper($c->estado ?? '—'),
            ], null, "A{$row}");
            $row++;
        }

        // Auto-ancho columnas
        foreach (range('A', 'R') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer   = new Xlsx($spreadsheet);
        $filename = "afiliaciones_{$meses[$mes]}_{$anio}.xlsx";
        $tmpPath  = tempnam(sys_get_temp_dir(), 'afilxls');
        $writer->save($tmpPath);

        return response()->download($tmpPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    /**
     * Historial completo de una afiliación, en orden: quién ingresó el contrato,
     * lo que la bitácora anotó sobre él (afiliaciones y anulaciones de ARL por
     * API, modificaciones) y cada cambio de estado de EPS, ARL, caja y pensión.
     */
    public function historial($id)
    {
        $contrato = Contrato::paraTramite($id)->loadMissing([
            'cliente:cedula,primer_nombre,segundo_nombre,primer_apellido,segundo_apellido',
            'encargado:id,nombre',
        ]);

        $meses = [
            1 => 'ENERO', 2 => 'FEBRERO', 3 => 'MARZO', 4 => 'ABRIL',
            5 => 'MAYO', 6 => 'JUNIO', 7 => 'JULIO', 8 => 'AGOSTO',
            9 => 'SEPTIEMBRE', 10 => 'OCTUBRE', 11 => 'NOVIEMBRE', 12 => 'DICIEMBRE',
        ];
        $estados = [
            'pendiente' => 'PENDIENTE',
            'tramite' => 'EN TRÁMITE',
            'traslado' => 'TRASLADO',
            'error' => 'ERROR',
            'ok' => 'OK',
        ];

        $historial = collect();
        // $orden desempata los eventos del mismo segundo: el ingreso va primero.
        $agregar = function ($momento, ?string $usuario, string $descripcion, string $estado, string $estadoRaw, int $orden = 1) use ($historial, $meses) {
            $dt = \Carbon\Carbon::parse($momento);
            $historial->push([
                'fecha' => $dt->day.'-'.$meses[$dt->month].'-'.$dt->year,
                'hora' => str_replace(' ', '', $dt->format('g:i A')),
                'usuario' => $usuario ?: 'Sistema',
                'descripcion' => $descripcion,
                'estado' => $estado,
                'estado_raw' => $estadoRaw,
                'orden' => [$dt->getTimestamp(), $orden],
            ]);
        };

        // 1. Bitácora del contrato
        $bitacora = DB::table('bitacora')
            ->leftJoin('users', 'bitacora.user_id', '=', 'users.id')
            ->where('bitacora.modelo', 'Contrato')
            ->where('bitacora.registro_id', $contrato->id)
            ->orderBy('bitacora.created_at')
            ->select('bitacora.id', 'bitacora.accion', 'bitacora.created_at', 'users.nombre as usuario', 'bitacora.descripcion')
            ->get();

        $ingreso = $bitacora->first(fn ($b) => $b->accion === 'created'
            && str_starts_with((string) $b->descripcion, Contrato::BITACORA_INGRESO));

        if ($ingreso) {
            $agregar($ingreso->created_at, $ingreso->usuario, 'SE INGRESÓ EL CONTRATO'.mb_substr($ingreso->descripcion, mb_strlen(Contrato::BITACORA_INGRESO)), 'INGRESO', 'info', 0);
        } else {
            // Los contratos de antes de oct-2026 no anotaban quién los ingresó:
            // queda la fecha real del contrato y su encargado, dicho como tal.
            $agregar(
                $contrato->created_at ?? $contrato->fecha_created ?? now(),
                $contrato->encargado ? $contrato->encargado->nombre.' (encargado)' : null,
                'SE INGRESÓ EL CONTRATO'.($contrato->fecha_ingreso ? ' con ingreso del '.$contrato->fecha_ingreso->format('d/m/Y') : ''),
                'INGRESO', 'info', 0
            );
        }

        foreach ($bitacora as $b) {
            if ($ingreso && $b->id === $ingreso->id) {
                continue;
            }
            [$prefijo, $etiqueta] = match ($b->accion) {
                'created' => ['', 'REGISTRO'],
                'deleted' => ['SE QUITÓ: ', 'ANULACIÓN'],
                default => ['MODIFICACIÓN: ', 'CAMBIO'],
            };
            $agregar($b->created_at, $b->usuario, $prefijo.$b->descripcion, $etiqueta, 'info');
        }

        // 2. Cambios de estado de cada radicado (EPS, ARL, caja, pensión)
        $movimientos = RadicadoMovimiento::with('user:id,nombre')
            ->where('contrato_id', $contrato->id)
            ->get();

        foreach ($movimientos as $m) {
            $entidad = strtoupper($m->entidadLabel());
            $nuevo = $estados[$m->estado_nuevo] ?? strtoupper((string) $m->estado_nuevo);

            if ($m->estado_anterior && $m->estado_anterior !== $m->estado_nuevo) {
                $texto = "{$entidad}: ".($estados[$m->estado_anterior] ?? strtoupper($m->estado_anterior))." → {$nuevo}";
            } elseif (! $m->estado_anterior) {
                $texto = "{$entidad}: quedó en {$nuevo}";
            } else {
                $texto = $entidad; // sin cambio de estado: PDF subido, enviado al cliente…
            }
            if ($m->observacion) {
                $texto .= ' ('.$m->observacion.')';
            }

            $agregar($m->created_at, $m->user?->nombre, $texto, $nuevo, (string) $m->estado_nuevo);
        }

        // 3. Radicados cuyo estado actual no tiene movimiento que lo explique
        // (los robots de ARL no los dejaban antes de oct-2026, ni la migración).
        $radicados = Radicado::with('user:id,nombre')
            ->where('contrato_id', $contrato->id)
            ->where('estado', '!=', Radicado::ESTADO_PENDIENTE)
            ->get();

        foreach ($radicados as $r) {
            $tieneMovimiento = $movimientos->contains(fn ($m) => (int) $m->radicado_id === (int) $r->id
                && $m->estado_nuevo === $r->estado);
            if ($tieneMovimiento) {
                continue;
            }

            $nuevo = $estados[$r->estado] ?? strtoupper((string) $r->estado);
            $detalle = $r->textoConfirmacion()
                ?? ($r->numero_radicado ? 'radicación '.$r->numero_radicado : null);
            $agregar(
                $r->confirmado_en ?? $r->fecha_confirmacion ?? $r->updated_at ?? $r->created_at,
                $r->user?->nombre,
                strtoupper($r->tipoLabel()).": quedó en {$nuevo}".($detalle ? " ({$detalle})" : ''),
                $nuevo,
                (string) $r->estado
            );
        }

        $historialOrdenado = $historial->sortBy(fn ($h) => sprintf('%012d-%d', ...$h['orden']))->values()
            ->map(fn ($h) => collect($h)->except('orden')->all());

        return response()->json([
            'cotizante' => $contrato->cliente ? trim($contrato->cliente->primer_nombre.' '.$contrato->cliente->primer_apellido) : 'Cotizante',
            'cedula' => $contrato->cedula,
            'historial' => $historialOrdenado,
        ]);
    }

    // ── Helpers privados ──────────────────────────────────────────────────

    /**
     * La lista muestra la cédula con su tipo ("CC 1143944458"). Si pegan eso tal cual
     * en el buscador, el tipo no es parte del número: sin quitarlo no encontraría nada.
     */
    private function buscarSinTipoDoc(?string $buscar): ?string
    {
        if ($buscar && preg_match('/^\s*(CC|CE|TI|PA|PE|PT|PP|NI|SC|RC|AS|MS)\s+([\d.\-]+)\s*$/i', $buscar, $m)) {
            return str_replace(['.', '-'], '', $m[2]);
        }

        return $buscar;
    }

    /**
     * Aliados cuyas afiliaciones se listan: el activo, o —con `?gestionados=1`
     * y siendo usuario de BryNex— todos aquellos a los que BryNex les gestiona
     * las afiliaciones, que son los que tienen contratado el módulo BryNex
     * «Gestión de Afiliaciones». El aliado 1 queda fuera: es el de pruebas.
     *
     * @return int[]
     */
    private function aliadosDeLaVista(Request $request, User $user, int $alidoId): array
    {
        return DatosAfiliacion::aliadosVisibles($user, $alidoId, $request->boolean('gestionados'));
    }

    /** Con qué se reconoce a una empresa: su NIT y, si no lo tiene, su nombre. */
    /**
     * Saca de la lista los contratos retirados con el motivo «Error - Sin
     * Afiliación»: esa afiliación no debía existir y, si se deja a la vista,
     * alguien la radica sin fijarse. El motivo se busca por nombre porque el
     * catálogo de producción no tiene ids fijos.
     */
    private function sinRetiradosPorError($query)
    {
        static $motivos = null;
        $motivos ??= DB::table('motivos_retiro')->where('nombre', 'Error - Sin Afiliación')->pluck('id')->all();

        if (! $motivos) {
            return $query;
        }

        return $query->where(fn ($q) => $q->where('estado', '!=', 'retirado')
            ->orWhereNull('motivo_retiro_id')
            ->orWhereNotIn('motivo_retiro_id', $motivos));
    }

    private function claveRazon(object $razonSocial): string
    {
        $digitos = preg_replace('/\D/', '', (string) $razonSocial->nit);

        return strlen($digitos) >= 8 ? $digitos : mb_strtoupper(trim((string) $razonSocial->razon_social));
    }

    /**
     * Los registros de razón social que son la misma empresa que la escogida:
     * los del mismo NIT. Escoger una en el filtro las trae todas, porque la
     * empresa está repetida —una vez por aliado— y quien filtra busca la
     * empresa, no el registro.
     *
     * @return int[]
     */
    private function razonesHermanas($rsId): array
    {
        $rsId = (int) $rsId;
        if (! $rsId) {
            return [];
        }

        $nit = preg_replace('/\D/', '', (string) DB::table('razones_sociales')->where('id', $rsId)->value('nit'));
        if (strlen($nit) < 8) {
            return [$rsId];
        }

        $ids = DB::table('razones_sociales')
            ->whereRaw("REPLACE(REPLACE(REPLACE(ISNULL(nit,''),'-',''),'.',''),' ','') = ?", [$nit])
            ->pluck('id')->map(fn ($i) => (int) $i)->all();

        return $ids ?: [$rsId];
    }

    private function resolverAliado(Request $request, User $user): int
    {
        $alidoSesion = (int) session('aliado_id_activo', $user->aliado_id);

        if ($user->es_brynex) {
            // Puede cambiar aliado por parámetro si tiene acceso
            $alidoParam = (int) $request->get('aliado_id', $alidoSesion);
            if ($alidoParam && $user->puedeAccederAliado($alidoParam)) {
                return $alidoParam;
            }
            return $alidoSesion ?: $user->aliado_id;
        }

        return $user->aliado_id;
    }

    private function alidosParaBrynex(User $user): \Illuminate\Support\Collection
    {
        // Aliado principal + aliados de la tabla pivot activos
        $ids = collect([$user->aliado_id]);
        $user->aliados()->wherePivot('activo', true)->get(['aliados.id'])->each(
            fn($a) => $ids->push($a->id)
        );
        return Aliado::whereIn('id', $ids->unique()->filter())
            ->orderBy('nombre')->get(['id', 'nombre']);
    }

    /**
     * Segundo filtro de las columnas EPS, ARL, Caja y Pensión: el estado del
     * radicado de esa entidad (`eps_estado`, `arl_estado`, …). Es independiente
     * de la entidad: sirve para ver todos los pendientes de EPS sin escoger una.
     * Los parámetros de entidad ya no condicionan nada; se conservan en la
     * firma para no tocar los dos llamadores.
     *
     * @param  array<string,mixed>  $entidades
     * @return array<string,string>  tipo de radicado => estado
     */
    private function estadosPorColumna(Request $request, array $entidades = []): array
    {
        $validos = array_keys(\App\Models\Radicado::todosEstados());

        $filtros = [];
        foreach (['eps', 'arl', 'caja', 'pension'] as $tipo) {
            $estado = $request->get($tipo.'_estado');
            if (in_array($estado, $validos, true)) {
                $filtros[$tipo] = $estado;
            }
        }

        return $filtros;
    }
}
