<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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

        // Capturar IDs del período sin filtros opcionales (para poblar selects dinámicos)
        $baseIds = Contrato::where('aliado_id', $alidoId)
            ->whereMonth('fecha_ingreso', $mes)
            ->whereYear('fecha_ingreso', $anio)
            ->pluck('id');
        $baseContratos = Contrato::whereIn('id', $baseIds)
            ->get(['id','razon_social_id','tipo_modalidad_id','eps_id','arl_id','caja_id','pension_id','estado','encargado_id']);

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
            'aliado:id,nombre',
            'radicados' => fn($q) => $q->with([
                'movimientos' => fn($m) => $m->reorder()->orderByDesc('id')->limit(3),
                // Para los días en estado de cada radicado, en una sola consulta.
                'ultimoMovimiento',
            ]),
        ])
        ->where('aliado_id', $alidoId)
        ->whereMonth('fecha_ingreso', $mes)
        ->whereYear('fecha_ingreso', $anio);

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
        if ($rsId)      $query->where('razon_social_id', $rsId);
        if ($epsF)      $query->where('eps_id', $epsF);
        if ($arlF)      $query->where('arl_id', $arlF);
        if ($cajaF)     $query->where('caja_id', $cajaF);
        if ($pensionF)  $query->where('pension_id', $pensionF);
        if ($tipoModId) $query->where('tipo_modalidad_id', $tipoModId);
        if ($empresaF) {
            $query->whereHas('cliente', fn($q) => $q->where('clientes.aliado_id', $alidoId)
                                                    ->where('cod_empresa', $empresaF));
        }
        // Filtro por estado del radicado (al menos uno con ese estado)
        $estadosPermitidos = ['pendiente','tramite','traslado','error','ok'];
        if ($estadoRad && in_array($estadoRad, $estadosPermitidos)) {
            $query->whereHas('radicados', fn($q) => $q->where('estado', $estadoRad));
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
        $encargados = User::where('aliado_id', $alidoId)
            ->where('activo', true)
            ->orderBy('nombre')
            ->get(['id', 'nombre']);

        // Cada desplegable muestra solo lo que hay en la tabla con los demás
        // filtros puestos: con LALA Confecciones escogida, el de EPS lista las
        // EPS de LALA y no el catálogo entero. Se excluye su propio filtro para
        // no dejar el desplegable con una sola opción —la escogida— y sin
        // manera de cambiarla.
        $presentes = function (string $columna) use ($baseContratos, $rsId, $tipoModId, $epsF, $arlF, $cajaF, $pensionF, $estadoCont, $encId) {
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
                ->filter(function ($c) use ($filtros) {
                    foreach ($filtros as $col => $valor) {
                        if (($valor ?? '') !== '' && (string) $c->$col !== (string) $valor) {
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

        $razonesDisponibles = DB::table('razones_sociales')
            ->whereIn('id', $conteoRazon->keys())
            ->orderBy('razon_social')
            ->get(['id', 'razon_social']);

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
            ->where('ct.aliado_id', $alidoId)
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
            ->where('aliado_id', $alidoId)
            ->where('es_independiente', false)
            ->whereNotNull('nit')
            ->whereRaw('LEN(nit) >= 8')
            ->orderBy('razon_social')
            ->get(['id', 'razon_social', 'nit']);

        return view('admin.afiliaciones.index', compact(
            'razonesConciliar',
            'contratos', 'mes', 'anio', 'encId', 'encargados',
            'alidoId', 'alidosDisponibles', 'user',
            'rsId', 'tipoModId', 'epsF', 'arlF', 'cajaF', 'pensionF', 'empresaF', 'estadoRad', 'estadoCont',
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

        // Filtros adicionales
        $rsId       = $request->get('razon_social_id');
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
        ])
        ->where('aliado_id', $alidoId)
        ->whereMonth('fecha_ingreso', $mes)
        ->whereYear('fecha_ingreso', $anio);

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
        if ($rsId)      $query->where('razon_social_id', $rsId);
        if ($epsF)      $query->where('eps_id', $epsF);
        if ($arlF)      $query->where('arl_id', $arlF);
        if ($cajaF)     $query->where('caja_id', $cajaF);
        if ($pensionF)  $query->where('pension_id', $pensionF);
        if ($tipoModId) $query->where('tipo_modalidad_id', $tipoModId);
        if ($empresaF) {
            $query->whereHas('cliente', fn($q) => $q->where('clientes.aliado_id', $alidoId)
                                                    ->where('cod_empresa', $empresaF));
        }

        $estadosPermitidos = ['pendiente','tramite','traslado','error','ok'];
        if ($estadoRad && in_array($estadoRad, $estadosPermitidos)) {
            $query->whereHas('radicados', fn($q) => $q->where('estado', $estadoRad));
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
            'Razón Social', 'Día', 'Factura', 'Cédula', 'Nombres',
            'EPS', 'Estado EPS', 'ARL', 'Estado ARL',
            'Caja', 'Estado Caja', 'Pensión', 'Estado Pensión',
            'Encargado', 'Observación', 'Estado',
        ];
        $sheet->fromArray($headers, null, 'A1');

        // Estilo encabezado
        $sheet->getStyle('A1:P1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => '1e40af']],
        ]);

        $row = 2;
        foreach ($contratos as $c) {
            $radicados = $c->radicados->keyBy('tipo');

            $sheet->fromArray([
                $c->razonSocial?->razon_social ?? '—',
                $c->fecha_ingreso?->format('d') ?? '',
                $facturas->get($c->id) ?? '',
                $c->cedula,
                trim(($c->cliente?->primer_nombre ?? '') . ' ' . ($c->cliente?->primer_apellido ?? '')),
                $c->eps?->nombre ?? '—',
                strtoupper($radicados->get('eps')?->estado ?? '—'),
                $c->arl_efectiva_nombre,
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
        foreach (range('A', 'P') as $col) {
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
     * Retorna el historial completo de una afiliación (contrato + modificaciones + movimientos de radicados).
     */
    public function historial($id)
    {
        $alidoId  = session('aliado_id_activo');
        $contrato = Contrato::with([
            'cliente:cedula,primer_nombre,segundo_nombre,primer_apellido,segundo_apellido',
            'encargado:id,nombre',
        ])->where('aliado_id', $alidoId)->findOrFail($id);

        $historial = collect();

        $meses = [
            1 => 'ENERO', 2 => 'FEBRERO', 3 => 'MARZO', 4 => 'ABRIL',
            5 => 'MAYO', 6 => 'JUNIO', 7 => 'JULIO', 8 => 'AGOSTO',
            9 => 'SEPTIEMBRE', 10 => 'OCTUBRE', 11 => 'NOVIEMBRE', 12 => 'DICIEMBRE'
        ];

        $formatear = function($timestamp) use ($meses) {
            $dt = \Carbon\Carbon::parse($timestamp);
            return [
                'fecha' => $dt->day . '-' . $meses[$dt->month] . '-' . $dt->year,
                'hora'  => str_replace(' ', '', $dt->format('g:i A')),
            ];
        };

        // 1. Obtener evento de creación del contrato en bitacora
        $creadoBitacora = DB::table('bitacora')
            ->leftJoin('users', 'bitacora.user_id', '=', 'users.id')
            ->where('bitacora.modelo', 'Contrato')
            ->where('bitacora.registro_id', $contrato->id)
            ->where('bitacora.accion', 'created')
            ->select('bitacora.created_at', 'users.nombre as usuario', 'bitacora.descripcion')
            ->first();

        if ($creadoBitacora) {
            $fmt = $formatear($creadoBitacora->created_at);
            $historial->push([
                'fecha'       => $fmt['fecha'],
                'hora'        => $fmt['hora'],
                'usuario'     => $creadoBitacora->usuario ?? 'Sistema',
                'descripcion' => 'SE CREÓ LA AFILIACIÓN: ' . $creadoBitacora->descripcion,
                'estado'      => 'PENDIENTE',
                'estado_raw'  => 'pendiente',
                'timestamp'   => $creadoBitacora->created_at,
            ]);
        } else {
            // Fallback usando el created_at del contrato
            $fechaC = $contrato->created_at ?? $contrato->fecha_created ?? now();
            $fmt = $formatear($fechaC);
            $historial->push([
                'fecha'       => $fmt['fecha'],
                'hora'        => $fmt['hora'],
                'usuario'     => $contrato->encargado?->nombre ?? 'Sistema',
                'descripcion' => 'SE CREÓ LA AFILIACIÓN',
                'estado'      => 'PENDIENTE',
                'estado_raw'  => 'pendiente',
                'timestamp'   => $fechaC,
            ]);
        }

        // 2. Obtener actualizaciones del contrato en bitacora
        $actualizacionesBitacora = DB::table('bitacora')
            ->leftJoin('users', 'bitacora.user_id', '=', 'users.id')
            ->where('bitacora.modelo', 'Contrato')
            ->where('bitacora.registro_id', $contrato->id)
            ->where('bitacora.accion', 'updated')
            ->select('bitacora.created_at', 'users.nombre as usuario', 'bitacora.descripcion')
            ->get();

        foreach ($actualizacionesBitacora as $act) {
            $fmt = $formatear($act->created_at);
            $historial->push([
                'fecha'       => $fmt['fecha'],
                'hora'        => $fmt['hora'],
                'usuario'     => $act->usuario ?? 'Sistema',
                'descripcion' => 'MODIFICACIÓN AFILIACIÓN: ' . $act->descripcion,
                'estado'      => 'VIGENTE',
                'estado_raw'  => 'tramite',
                'timestamp'   => $act->created_at,
            ]);
        }

        // 3. Obtener movimientos de radicados asociados al contrato
        $movimientosRadicados = RadicadoMovimiento::with(['user:id,nombre', 'radicado'])
            ->where('contrato_id', $contrato->id)
            ->get();

        $estadoMap = [
            'pendiente' => 'PENDIENTE',
            'tramite'   => 'EN TRAMITE',
            'traslado'  => 'TRASLADO',
            'error'     => 'ERROR',
            'ok'        => 'OK',
        ];

        foreach ($movimientosRadicados as $m) {
            $fmt = $formatear($m->created_at);
            $entidad = strtoupper($m->entidadLabel());
            
            // Descripción personalizada
            $accion = "SE ACTUALIZÓ ESTADO DE {$entidad}";
            if ($m->estado_nuevo === 'tramite') {
                $accion = "RE RADICÓ {$entidad}";
            } else if ($m->estado_nuevo === 'ok') {
                $accion = "RE RADICÓ {$entidad}"; // Para coincidir con el estilo del usuario que quiere ver "RE RADICO ARL , OK"
            } else if ($m->estado_nuevo === 'error') {
                $accion = "ERROR EN {$entidad}";
            } else if ($m->estado_nuevo === 'traslado') {
                $accion = "TRASLADO EN {$entidad}";
            }

            if ($m->observacion) {
                $accion .= " (" . $m->observacion . ")";
            }

            $historial->push([
                'fecha'       => $fmt['fecha'],
                'hora'        => $fmt['hora'],
                'usuario'     => $m->user?->nombre ?? 'Sistema',
                'descripcion' => $accion,
                'estado'      => $estadoMap[$m->estado_nuevo] ?? strtoupper($m->estado_nuevo),
                'estado_raw'  => $m->estado_nuevo,
                'timestamp'   => $m->created_at,
            ]);
        }

        // Ordenar historial cronológicamente (ascendente)
        $historialOrdenado = $historial->sortBy('timestamp')->values();

        return response()->json([
            'cotizante' => $contrato->cliente ? trim($contrato->cliente->primer_nombre . ' ' . $contrato->cliente->primer_apellido) : 'Cotizante',
            'cedula'    => $contrato->cedula,
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
}
