<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CajaRevision;
use App\Models\Contrato;
use App\Services\Caja\ComfandiCajaConciliacionService;
use App\Services\Caja\ComfandiCajaService;
use App\Services\Caja\ComfandiListadoDescarga;
use App\Services\Caja\ComfenalcoSubsidiosHeadless;
use App\Services\Caja\SubsidioCandidatosService;
use App\Services\Caja\SubsidioTareasService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Afiliación a la caja Comfandi por su Sucursal Virtual Empresas, desde el
 * radicado de caja. La extensión BryNex Portales llena el formulario de
 * Afiliación individual; aquí se arman los datos, se entrega la credencial y
 * se registra el número de radicado que devuelve el portal.
 */
class ComfandiCajaController extends Controller
{
    public function __construct(private ComfandiCajaService $servicio)
    {
        $this->middleware('auth');
    }

    public function precheck(int $contratoId)
    {
        $prep = $this->servicio->preparar($this->contrato($contratoId));

        return response()->json(['ok' => ! $prep['problemas'], 'listas' => [
            'estados_civil' => ComfandiCajaService::ESTADOS_CIVIL,
            'contratos' => ComfandiCajaService::CONTRATOS,
            'salarios' => ComfandiCajaService::SALARIOS,
            'horas' => ComfandiCajaService::HORAS,
            'niveles' => ComfandiCajaService::NIVELES,
            'generos' => ['1' => 'Femenino', '2' => 'Masculino'],
        ]] + $prep);
    }

    public function credencial(int $contratoId)
    {
        $cred = $this->servicio->credencial($this->contrato($contratoId));
        if (isset($cred['error'])) {
            return response()->json(['ok' => false, 'error' => $cred['error']], 422);
        }
        if (! Auth::user()->can('claves_acceso.ver_contrasena')) {
            unset($cred['contrasena']);
        }

        return response()->json(['ok' => true] + $cred)->header('Cache-Control', 'no-store');
    }

    public function aplicar(Request $request, int $contratoId)
    {
        $datos = $request->validate([
            'numero' => 'nullable|string|max:40',
            'texto' => 'nullable|string|max:20000',
            'error' => 'nullable|string|max:1000',
        ]);

        try {
            return response()->json($this->servicio->aplicar($this->contrato($contratoId), $datos, Auth::id()));
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Conciliación con las dos listas del portal: el Listado de trabajadores
     * dice quién está afiliado y la pestaña Radicados explica a los que aún no
     * lo están. BryNex concilia la empresa en todos los aliados del NIT.
     */
    public function conciliar(Request $request, ComfandiCajaConciliacionService $conciliacion, ComfandiListadoDescarga $descarga)
    {
        $datos = $request->validate([
            'nit' => 'required|string|max:20',
            // El listado llega como el Excel del portal (lo normal) o, si no se
            // pudo bajar, como las filas raspadas de la tabla.
            'archivo_url' => 'nullable|string|max:4000',
            'filas' => 'array|max:6000',
            'filas.*' => 'array|max:8',
            'radicados' => 'array|max:6000',
            'radicados.*' => 'array|max:8',
            'radicados_ok' => 'boolean',
            'simular' => 'boolean',
        ]);

        $filas = $datos['filas'] ?? [];
        $beneficiarios = [];

        if (! empty($datos['archivo_url'])) {
            try {
                $listado = $descarga->bajar($datos['archivo_url']);
            } catch (Throwable $e) {
                return response()->json(['ok' => false, 'mensaje' => $e->getMessage()], 422);
            }
            $filas = $listado['filas'];
            $beneficiarios = $listado['beneficiarios'];
        }

        if (! $filas) {
            return response()->json(['ok' => false, 'mensaje' => 'No llegó el listado de trabajadores.'], 422);
        }
        $aliados = Auth::user()->es_brynex
            ? $conciliacion->aliadosDelNit($datos['nit'])
            : [(int) session('aliado_id_activo')];

        try {
            $r = $conciliacion->conciliar($aliados, $datos['nit'], $filas, $datos['radicados'] ?? [],
                (bool) ($datos['simular'] ?? false), Auth::id(), (bool) ($datos['radicados_ok'] ?? true), $beneficiarios);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'mensaje' => $e->getMessage()], 422);
        }

        $estado = $r + ['corriendo' => false, 'fin' => now()->toIso8601String(), 'mensaje' => 'Terminado.'];
        Cache::put($this->claveEstado(), $estado, now()->addDay());

        return response()->json(['ok' => true] + $estado);
    }

    /**
     * A quién hay que consultarle los bloqueos de subsidio, agrupado por NIT.
     *
     * La extensión pide esta lista, recorre el portal trabajador por trabajador
     * y devuelve lo que encuentre a `subsidios()`. El alcance normal son los
     * sospechosos del día (pago con mora, tarea abierta, afiliado nuevo); el
     * completo se usa una vez al mes, después del ciclo de la caja.
     */
    public function subsidiosCandidatos(Request $request, SubsidioCandidatosService $candidatos)
    {
        $datos = $request->validate([
            'nit' => 'nullable|string|max:20',
            'alcance' => 'nullable|in:candidatos,completa',
            'caja' => 'nullable|in:COMFANDI,COMFENALCO',
        ]);

        $aliadoId = (int) session('aliado_id_activo');
        $completa = ($datos['alcance'] ?? 'candidatos') === 'completa';
        $caja = $datos['caja'] ?? SubsidioCandidatosService::CAJA_POR_DEFECTO;
        $entidad = $caja === 'COMFENALCO' ? CajaRevision::ENTIDAD_COMFENALCO : CajaRevision::ENTIDAD_COMFANDI;

        // El portal da el NIT con el dígito de verificación pegado
        // (9016037383) y BryNex lo guarda sin él: sin traducirlo, la empresa no
        // existe y la lista sale vacía.
        $nit = ! empty($datos['nit'])
            ? ComfandiCajaConciliacionService::nitComoLoGuardaBryNex($datos['nit'])
            : null;

        $lista = $completa
            ? $candidatos->todos($aliadoId, $nit, $caja)
            : $candidatos->candidatos($aliadoId, $nit, $caja);

        return response()->json([
            'ok' => true,
            'alcance' => $completa ? 'completa' : 'candidatos',
            // El candado es por empresa: sin NIT no hay nada que preguntar, y
            // la pantalla solo lo usa para no repetir la de la empresa abierta.
            'ya_revisado_hoy' => $nit ? CajaRevision::yaSeHizo($nit, $entidad) : false,
            'total' => collect($lista)->map(fn ($f) => count($f))->sum(),
            'por_empresa' => $lista,
        ]);
    }

    /**
     * Recibe los movimientos de subsidio que la extensión leyó del portal y los
     * convierte en tareas.
     *
     * `revisados` son las cédulas que sí se alcanzaron a consultar: solo de esas
     * se cierran tareas, porque un bloqueo que nadie miró no está resuelto.
     */
    public function subsidios(Request $request, SubsidioTareasService $servicio)
    {
        $datos = $request->validate([
            'movimientos' => 'array|max:8000',
            'movimientos.*' => 'array|max:10',
            'nit' => 'nullable|string|max:20',
            'revisados' => 'present|array|max:3000',
            'revisados.*' => 'string|max:20',
            'alcance' => 'nullable|in:candidatos,completa',
            'simular' => 'boolean',
            'cerrar_revision' => 'boolean',
            // Qué caja mandó estos movimientos. El proceso es el mismo para
            // todas; lo único propio de cada una es quién lee su portal.
            'caja' => 'nullable|in:COMFANDI,COMFENALCO',
        ]);

        $aliadoId = (int) session('aliado_id_activo');
        $simular = (bool) ($datos['simular'] ?? false);
        $alcance = $datos['alcance'] ?? CajaRevision::ALCANCE_CANDIDATOS;
        $caja = $datos['caja'] ?? SubsidioCandidatosService::CAJA_POR_DEFECTO;
        $entidad = $caja === 'COMFENALCO' ? CajaRevision::ENTIDAD_COMFENALCO : CajaRevision::ENTIDAD_COMFANDI;

        $nit = ! empty($datos['nit']) ? ComfandiCajaConciliacionService::nitComoLoGuardaBryNex($datos['nit']) : null;

        $revision = $simular || ! $nit
            ? null
            : CajaRevision::abrir($nit, $entidad, $alcance, $aliadoId);

        // Sin nadie consultado no hay revisión que valer: el portal no dejó
        // entrar a ninguna pantalla de subsidio. Se marca fallida para poder
        // reintentar hoy mismo, en vez de dar el día por revisado en falso.
        if (! $datos['revisados']) {
            $revision?->fallar('El portal no dejó consultar a ninguno de los trabajadores.');

            return response()->json([
                'ok' => false,
                'mensaje' => 'El portal no dejó abrir el subsidio monetario de ningún trabajador. Revisa que la extensión esté al día y vuelve a intentar.',
            ], 422);
        }

        // De Comfenalco llegan las filas tal como las pinta el portal, sin el
        // motivo redactado: eso se hace en un solo sitio, que es el que sabe
        // qué significa cada tabla suya.
        $movimientos = $caja === 'COMFENALCO'
            ? ComfenalcoSubsidiosHeadless::traducir($datos['movimientos'] ?? [], $datos['revisados'])
            : ($datos['movimientos'] ?? []);

        try {
            $r = $servicio->procesar($aliadoId, $movimientos, $datos['revisados'], $simular, $nit, $caja);
        } catch (Throwable $e) {
            $revision?->fallar($e->getMessage());

            return response()->json(['ok' => false, 'mensaje' => $e->getMessage()], 422);
        }

        // La revisión se cierra cuando la extensión termina con todas las
        // empresas, no con la primera: hasta entonces queda en `corriendo`.
        if ($revision && ($datos['cerrar_revision'] ?? true)) {
            $revision->terminar([
                'revisados' => $revision->revisados + count($datos['revisados']),
                'bloqueados' => $revision->bloqueados + $r['bloqueos'],
                'tareas_nuevas' => $revision->tareas_nuevas + $r['nuevas'],
                'tareas_cerradas' => $revision->tareas_cerradas + $r['cerradas'],
            ]);
        }

        return response()->json(['ok' => true] + $r);
    }

    public function estado()
    {
        return response()->json(Cache::get($this->claveEstado()) ?? ['corriendo' => false, 'vacio' => true]);
    }

    /** La corrida consolidada de BryNex no se mezcla con la de cada aliado. */
    private function claveEstado(): string
    {
        return 'comfandi_conciliacion:estado:'.(Auth::user()->es_brynex ? 'brynex' : (int) session('aliado_id_activo'));
    }

    /** Siempre del aliado activo: el id llega por la URL. */
    private function contrato(int $id): Contrato
    {
        return Contrato::paraTramite($id);
    }
}
