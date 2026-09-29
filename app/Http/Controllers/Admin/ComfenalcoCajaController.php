<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contrato;
use App\Services\Caja\BeneficiariosCaja;
use App\Services\Caja\ComfenalcoCajaConciliacionService;
use App\Services\Caja\ComfenalcoCajaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Afiliación a la caja Comfenalco Valle por su Sucursal Virtual, desde el
 * radicado de caja. La extensión BryNex Portales llena el asistente; aquí se
 * arman los datos, se entrega la credencial y se registra el resultado.
 */
class ComfenalcoCajaController extends Controller
{
    public function __construct(private ComfenalcoCajaService $servicio)
    {
        $this->middleware('auth');
    }

    public function precheck(int $contratoId)
    {
        $prep = $this->servicio->preparar($this->contrato($contratoId));

        return response()->json(['ok' => ! $prep['problemas'], 'listas' => [
            'estados_civil' => ComfenalcoCajaService::ESTADOS_CIVIL,
            'contratos' => ComfenalcoCajaService::CONTRATOS,
            'formas_pago' => ComfenalcoCajaService::FORMAS_PAGO,
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
     * Conciliación de los radicados de caja con "Trabajadores por Empresa" que
     * baja la extensión. BryNex concilia la empresa en todos los aliados del NIT.
     */
    public function conciliar(Request $request, ComfenalcoCajaConciliacionService $conciliacion)
    {
        $datos = $request->validate([
            'nit' => 'required|string|max:20',
            'filas' => 'required|array|max:6000',
            'filas.*' => 'array|max:8',
            'simular' => 'boolean',
        ]);
        $aliados = Auth::user()->es_brynex
            ? $conciliacion->aliadosDelNit($datos['nit'])
            : [(int) session('aliado_id_activo')];

        try {
            $r = $conciliacion->conciliar($aliados, $datos['nit'], $datos['filas'], (bool) ($datos['simular'] ?? false), Auth::id());
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'mensaje' => $e->getMessage()], 422);
        }

        $estado = $r + ['corriendo' => false, 'fin' => now()->toIso8601String(), 'mensaje' => 'Terminado.'];
        Cache::put($this->claveEstado(), $estado, now()->addDay());

        return response()->json(['ok' => true] + $estado);
    }

    /**
     * Guarda los grupos familiares que baja la extensión de la consulta
     * "Afiliación grupo familiar".
     *
     * Va aparte de la conciliación porque en Comfenalco hay que preguntar
     * trabajador por trabajador —no hay un archivo de toda la empresa como en
     * Comfandi—, así que es una pasada larga que no conviene repetir en cada
     * cruce.
     */
    public function beneficiarios(Request $request, ComfenalcoCajaConciliacionService $conciliacion, BeneficiariosCaja $beneficiarios)
    {
        $datos = $request->validate([
            'nit' => 'required|string|max:20',
            'familias' => 'required|array|max:1000',
            'familias.*' => 'array|max:20',
            'simular' => 'boolean',
        ]);

        $aliados = Auth::user()->es_brynex
            ? $conciliacion->aliadosDelNit($datos['nit'])
            : [(int) session('aliado_id_activo')];
        if (! $aliados) {
            return response()->json(['ok' => false, 'mensaje' => "El NIT {$datos['nit']} no es una razón social de este aliado."], 422);
        }

        // La extensión manda lo que ve el portal; aquí se queda solo lo que
        // tiene forma de beneficiario.
        $limpias = [];
        foreach ($datos['familias'] as $cedula => $suyos) {
            $doc = ltrim(preg_replace('/\D/', '', (string) $cedula), '0');
            if ($doc === '' || ! is_array($suyos)) {
                continue;
            }
            foreach ($suyos as $b) {
                if (! is_array($b) || empty($b['documento'])) {
                    continue;
                }
                $limpias[$doc][] = [
                    'tipo_doc' => isset($b['tipo_doc']) ? mb_substr((string) $b['tipo_doc'], 0, 5) : null,
                    'documento' => ltrim(preg_replace('/\D/', '', (string) $b['documento']), '0'),
                    'nombre' => mb_substr(trim((string) ($b['nombre'] ?? '')), 0, 150),
                    'parentesco' => isset($b['parentesco']) ? mb_substr(trim((string) $b['parentesco']), 0, 40) : null,
                    'nacimiento' => null,   // Comfenalco da la edad, no la fecha
                ];
            }
        }

        $r = $beneficiarios->guardar($aliados, $limpias, (bool) ($datos['simular'] ?? false), 'la consulta de grupo familiar de Comfenalco Valle');

        return response()->json(['ok' => true, 'trabajadores' => count($limpias)] + $r);
    }

    public function estado()
    {
        return response()->json(Cache::get($this->claveEstado()) ?? ['corriendo' => false, 'vacio' => true]);
    }

    /** La corrida consolidada de BryNex no se mezcla con la de cada aliado. */
    private function claveEstado(): string
    {
        return 'caja_comfenalco_conciliacion:estado:'.(Auth::user()->es_brynex ? 'brynex' : (int) session('aliado_id_activo'));
    }

    /** Siempre del aliado activo: el id llega por la URL. */
    private function contrato(int $id): Contrato
    {
        return Contrato::paraTramite($id);
    }
}
