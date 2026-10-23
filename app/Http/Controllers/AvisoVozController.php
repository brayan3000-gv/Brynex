<?php

namespace App\Http\Controllers;

use App\Services\Voz\LlamadasCobroVoz;
use Illuminate\Http\Request;

/**
 * POST /avisos/voz/{token}: Avappi Voz avisa cada intento de llamada.
 *
 * Sin sesión: lo protegen el token de la ruta y la firma X-Avappi-Firma
 * (HMAC con AVAPPI_VOZ_SECRETO). El mismo aviso puede llegar dos veces; se
 * reconoce por X-Avappi-Aviso (el id de la llamada) y no se aplica de nuevo.
 */
class AvisoVozController extends Controller
{
    public function __invoke(Request $request, string $token)
    {
        if (! hash_equals(LlamadasCobroVoz::tokenAviso(), $token)) {
            abort(404);
        }

        $cuerpo = $request->getContent();
        $secreto = (string) config('services.avappi_voz.secreto');
        if (! LlamadasCobroVoz::firmaValida($secreto, $cuerpo, $request->header('X-Avappi-Firma'))) {
            return response()->json(['ok' => false, 'error' => 'firma inválida'], 401);
        }

        $aviso = json_decode($cuerpo, true);
        $id = (string) ($request->header('X-Avappi-Aviso') ?: (is_array($aviso) ? ($aviso['id'] ?? '') : ''));
        if (! is_array($aviso) || ! preg_match('/^[0-9a-f-]{36}$/i', $id)) {
            return response()->json(['ok' => false, 'error' => 'aviso sin id'], 422);
        }

        $estado = LlamadasCobroVoz::recibirAviso($id, $aviso);

        return response()->json(['ok' => true, 'estado' => $estado, 'repetido' => $estado === 'repetido']);
    }
}
