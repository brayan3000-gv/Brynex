<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contrato;
use App\Services\Correo\CorreoAsesorEpsService;
use App\Services\Sos\SosCorreoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Afiliación por correo al asesor de las EPS sin portal de empleador (Comfenalco
 * Valle), desde el radicado de EPS: vista previa, subir la cédula y enviar.
 */
class CorreoAsesorEpsController extends Controller
{
    public function __construct(private CorreoAsesorEpsService $servicio)
    {
        $this->middleware('auth');
    }

    public function preparar(int $contratoId, string $entidad)
    {
        try {
            return response()->json(['ok' => true] + $this->servicio->preparar($this->contrato($contratoId), $entidad));
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /** Copia del documento de identidad (es el mismo cargue que usa S.O.S.). */
    public function documento(Request $request, SosCorreoService $sos, int $contratoId, string $entidad)
    {
        $request->validate(['archivo' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png']);
        $this->servicio->conf($entidad);
        $doc = $sos->subirDocumento($this->contrato($contratoId), $request->file('archivo'), Auth::id());

        return response()->json(['ok' => true, 'documento_id' => $doc->id]);
    }

    public function enviar(Request $request, int $contratoId, string $entidad)
    {
        $datos = $request->validate([
            'para'   => 'required|string|max:500',
            'cc'     => 'nullable|string|max:500',
            'asunto' => 'required|string|max:300',
            'cuerpo' => 'required|string|max:10000',
        ]);

        try {
            $registro = $this->servicio->enviar($this->contrato($contratoId), $entidad, $datos, Auth::id());

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
