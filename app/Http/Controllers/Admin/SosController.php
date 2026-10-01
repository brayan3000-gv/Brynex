<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contrato;
use App\Models\PortalPeticion;
use App\Services\Sos\SosConciliacionService;
use App\Services\Sos\SosCorreoService;
use App\Services\Sos\SosNovedadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Novedad de inicio laboral en S.O.S. desde el radicado de EPS de Afiliaciones.
 *
 * El portal lo opera la extensión BryNex Portales en el navegador de la persona
 * (el login pide captcha). Aquí solo: `precheck` arma los datos para la
 * extensión, `ladoB` entrega la página 2 del formulario firmada y `aplicar`
 * registra en el radicado lo que la extensión trajo del portal.
 */
class SosController extends Controller
{
    public function __construct(private SosNovedadService $servicio)
    {
        $this->middleware('auth');
    }

    public function precheck(int $contratoId)
    {
        $prep = $this->servicio->preparar($this->contrato($contratoId));

        return response()->json(['ok' => ! $prep['problemas']] + $prep);
    }

    /**
     * Usuario (y clave, si la persona tiene permiso de ver contraseñas del módulo
     * de claves) para que la extensión llene el login de S.O.S. Va aparte del
     * precheck para que la clave solo viaje al abrir el portal.
     */
    public function credencial(int $contratoId)
    {
        $contrato = $this->contrato($contratoId)->loadMissing('razonSocial');
        $cred = $this->servicio->credencial((string) $contrato->razonSocial?->nit);

        if (isset($cred['error'])) {
            return response()->json(['ok' => false, 'error' => $cred['error']], 422);
        }
        if (! Auth::user()->can('claves_acceso.ver_contrasena')) {
            unset($cred['contrasena']);
        }

        return response()->json(['ok' => true] + $cred)->header('Cache-Control', 'no-store');
    }

    /** Imagen del lado B (página 2 del formulario, firmada) para que la extensión la adjunte. */
    public function ladoB(int $contratoId)
    {
        try {
            [, $imagen] = $this->servicio->ladoB($this->contrato($contratoId));
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }

        return response(Storage::disk('local')->get($imagen), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function aplicar(Request $request, int $contratoId)
    {
        $datos = $request->validate([
            'novedad' => 'nullable|array',
            'novedad.radicado' => 'nullable|string|max:20',
            'novedad.estado' => 'nullable|string|max:120',
            'novedad.causal' => 'nullable|string|max:300',
            'registro' => 'nullable|array',
            'registro.fecha' => 'required_with:registro|date',
            'registro.envio' => 'nullable|array',
            'registro.resultado' => 'nullable|array',
            'adjunto' => 'nullable|boolean',
            'certificado' => 'nullable|string|max:8000000',
        ]);

        try {
            $contrato = $this->contrato($contratoId);
            if (isset($datos['registro'])) {
                $this->servicio->validarFecha($datos['registro']['fecha']);
            }

            return response()->json($this->servicio->aplicar($contrato, $datos, Auth::id()));
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    // ── Conciliación de radicados con el portal ────────────────────────────

    /**
     * A quién hay que buscar en el portal, y en qué rango de fechas.
     *
     * Lo pide la extensión antes de empezar: BryNex sabe qué radicados están
     * abiertos, y así solo se consulta a esa gente en vez de recorrer la nómina.
     */
    public function porConsultar(Request $request, SosConciliacionService $conciliacion)
    {
        $nit = $request->query('nit') ? preg_replace('/\D/', '', (string) $request->query('nit')) : null;

        return response()->json(['ok' => true] + $conciliacion->porConsultar($this->aliados($conciliacion, $nit), $nit));
    }

    /** Recibe lo que la extensión leyó del portal y pone al día los radicados. */
    public function conciliar(Request $request, SosConciliacionService $conciliacion)
    {
        $datos = $request->validate([
            'nit' => 'nullable|string|max:20',
            'resultados' => 'required|array|max:1000',
            'resultados.*' => 'array|max:100',
            'simular' => 'boolean',
        ]);

        $nit = isset($datos['nit']) ? preg_replace('/\D/', '', $datos['nit']) : null;
        $simular = (bool) ($datos['simular'] ?? false);

        try {
            $r = $conciliacion->conciliar($this->aliados($conciliacion, $nit), $nit, $datos['resultados'], $simular, Auth::id());
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'mensaje' => $e->getMessage()], 422);
        }

        // La petición se cierra solo con una revisión de verdad: una simulación
        // no arregló nada y el portal sigue esperando a que alguien entre.
        if (! $simular) {
            $conciliacion->cerrarPeticion(Auth::id(), $r);
        }

        $estado = $r + ['corriendo' => false, 'simulado' => $simular, 'fin' => now()->toIso8601String(), 'mensaje' => 'Terminado.'];
        Cache::put($this->claveEstado(), $estado, now()->addDay());

        return response()->json(['ok' => true] + $estado);
    }

    public function estadoConciliacion()
    {
        $peticion = PortalPeticion::abiertaDe('sos');

        return response()->json((Cache::get($this->claveEstado()) ?? ['corriendo' => false, 'vacio' => true]) + [
            // Lo que el portal está esperando. Va aquí para que la pantalla lo
            // muestre aunque el WhatsApp no haya salido o nadie lo haya leído.
            'peticion' => $peticion ? [
                'desde' => $peticion->created_at->format('d/m/Y'),
                'motivo' => $peticion->motivo,
                'pendientes' => $peticion->pendientes,
            ] : null,
        ]);
    }

    /**
     * Sobre qué aliados se trabaja.
     *
     * La clave del portal es de la empresa, no del aliado: quien entra desde
     * BryNex pone al día los radicados de todos los aliados que tengan esa razón
     * social, porque el portal no distingue entre ellos. Un aliado solo ve lo suyo.
     *
     * @return array<int>
     */
    private function aliados(SosConciliacionService $conciliacion, ?string $nit): array
    {
        return Auth::user()->es_brynex && $nit
            ? $conciliacion->aliadosDelNit($nit)
            : [(int) session('aliado_id_activo')];
    }

    /** La corrida consolidada de BryNex no se mezcla con la de cada aliado. */
    private function claveEstado(): string
    {
        return 'sos_conciliacion:estado:'.(Auth::user()->es_brynex ? 'brynex' : (int) session('aliado_id_activo'));
    }

    // ── Plan B: afiliación por correo al asesor ────────────────────────────

    /** Vista previa del correo al asesor: destinatario, asunto, texto, adjuntos y lo que falta. */
    public function correoPreparar(Request $request, SosCorreoService $correo, int $contratoId)
    {
        $motivo = in_array($request->query('motivo'), ['portal_rechazo', 'independiente', 'manual'], true) ? $request->query('motivo') : 'manual';

        return response()->json(['ok' => true] + $correo->preparar(
            $this->contrato($contratoId), $motivo, $request->boolean('con_beneficiarios', true), mb_substr((string) $request->query('detalle', ''), 0, 300)
        ));
    }

    /** El formulario que se adjuntaría, para verlo en el modal antes de enviar. */
    public function correoFormulario(Request $request, SosCorreoService $correo, int $contratoId)
    {
        try {
            $pdf = $correo->vistaPrevia($this->contrato($contratoId), $request->boolean('con_beneficiarios', true));
        } catch (Throwable $e) {
            return response($e->getMessage(), 422)->header('Content-Type', 'text/plain; charset=utf-8');
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="formulario_sos.pdf"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Sube la copia del documento de identidad del cliente, para poder enviarla. */
    public function correoDocumento(Request $request, SosCorreoService $correo, int $contratoId)
    {
        $request->validate(['archivo' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png']);
        $doc = $correo->subirDocumento($this->contrato($contratoId), $request->file('archivo'), Auth::id());

        return response()->json(['ok' => true, 'documento_id' => $doc->id]);
    }

    public function correoEnviar(Request $request, SosCorreoService $correo, int $contratoId)
    {
        $datos = $request->validate([
            'para' => 'required|string|max:500',
            'cc' => 'nullable|string|max:500',
            'asunto' => 'required|string|max:300',
            'cuerpo' => 'required|string|max:10000',
            'motivo' => 'required|in:portal_rechazo,independiente,manual',
            'con_beneficiarios' => 'nullable|boolean',
            'detalle' => 'nullable|string|max:300',
        ]);

        try {
            $registro = $correo->enviar($this->contrato($contratoId), $datos, Auth::id());

            return response()->json(['ok' => true, 'correo_id' => $registro->id, 'para' => $registro->para, 'vence' => $registro->vence_at?->format('d/m/Y H:i')]);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Siempre del aliado activo: el id llega por la URL. */
    private function contrato(int $id): Contrato
    {
        return Contrato::paraTramite($id);
    }
}
