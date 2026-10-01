<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contrato;
use App\Services\EpsSura\EpsSuraReingresoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Reingreso en EPS SURA desde el radicado de EPS de Afiliaciones.
 *
 * Los mismos tres pasos que Nueva EPS, para que nada se radique a ciegas:
 * `precheck` no toca el portal; `consultar` entra y dice si la persona existe
 * para reingreso (si no aparece, es traslado y por esa pantalla no se hace);
 * `registrar` radica. Los dos últimos abren un navegador en el servidor y
 * tardan cerca de un minuto.
 *
 * `explorar` devuelve el inventario de campos de la pantalla del portal: es
 * para ajustar los selectores sin radicar, y no escribe nada.
 */
class EpsSuraReingresoController extends Controller
{
    public function __construct(private EpsSuraReingresoService $servicio)
    {
        $this->middleware('auth');
    }

    public function precheck(Request $request, int $contratoId)
    {
        $prep = $this->servicio->preparar($this->contrato($contratoId));

        return response()->json(['ok' => ! $prep['problemas']] + $prep);
    }

    public function explorar(Request $request, int $contratoId)
    {
        @set_time_limit(300);

        try {
            return response()->json($this->servicio->explorar($this->contrato($contratoId)));
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function consultar(Request $request, int $contratoId)
    {
        @set_time_limit(300);

        try {
            return response()->json($this->servicio->consultar($this->contrato($contratoId)));
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    public function registrar(Request $request, int $contratoId)
    {
        @set_time_limit(300);

        try {
            return response()->json($this->servicio->registrar($this->contrato($contratoId), Auth::id()));
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Por dónde va el robot ahora mismo.
     *
     * El trámite tarda cerca de minuto y medio: la pantalla pregunta por aquí
     * cada pocos segundos para ir contándolo en vez de dejar un reloj girando.
     */
    public function progreso(int $contratoId)
    {
        $this->contrato($contratoId);

        return response()->json(
            Cache::get(EpsSuraReingresoService::claveDelPaso($contratoId)) ?: ['paso' => null]
        )->header('Cache-Control', 'no-store');
    }

    /** Lo que la extensión trajo del portal: número, transacción y comprobante. */
    public function aplicar(Request $request, int $contratoId)
    {
        // Sin comprobante que guardar se baja el certificado, y eso abre un
        // navegador en el servidor: pasa de ser una respuesta inmediata a tardar
        // cerca de un minuto.
        @set_time_limit(300);

        $datos = $request->validate([
            'ok' => 'nullable|boolean',
            'radicado' => 'nullable|string|max:60',
            'transaccion' => 'nullable|string|max:60',
            'periodoPago' => 'nullable|string|max:20',
            'resultado' => 'nullable|string|max:2000',
            'error' => 'nullable|string|max:2000',
            // Un comprobante de una página pesa unos 400 KB en base64.
            'pdf' => 'nullable|string|max:8000000',
        ]);

        try {
            return response()->json($this->servicio->aplicar($this->contrato($contratoId), $datos, Auth::id()));
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Credencial del portal para que la extensión entre sola.
     *
     * La contraseña solo viaja a quien puede verla en el módulo de claves.
     */
    public function credencial(int $contratoId)
    {
        $cred = $this->servicio->credencialPortal($this->contrato($contratoId));
        if (isset($cred['error'])) {
            return response()->json(['ok' => false, 'error' => $cred['error']], 422);
        }
        if (! Auth::user()->can('claves_acceso.ver_contrasena')) {
            unset($cred['contrasena']);
        }

        return response()->json(['ok' => true] + $cred)->header('Cache-Control', 'no-store');
    }

    private function contrato(int $id): Contrato
    {
        return Contrato::paraTramite($id);
    }
}
