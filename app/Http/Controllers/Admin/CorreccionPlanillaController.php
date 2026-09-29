<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CorreccionPlanillaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Corrección N desde el modal de Facturar: cuando escogen un mes ya pagado,
 * el modal ofrece corregir su planilla en vez de saltar al mes siguiente.
 * Ver CorreccionPlanillaService.
 */
class CorreccionPlanillaController extends Controller
{
    public function store(Request $request, int $contratoId): JsonResponse
    {
        $aliadoId = (int) session('aliado_id_activo');

        $validated = $request->validate([
            'tipo' => 'required|in:novedad',
            'plano_id' => 'required|integer',
            'fecha_ret' => 'required|date',
            'tipo_retiro' => 'required|in:'.implode(',', array_keys(CorreccionPlanillaService::TIPOS_RETIRO)),
            'motivo_retiro_id' => 'required|integer|exists:motivos_retiro,id',
            'observacion' => 'nullable|string|max:300',
        ]);

        try {
            ['factura' => $factura, 'plano' => $plano] = CorreccionPlanillaService::registrarNovedad(
                $aliadoId,
                $contratoId,
                (int) $validated['plano_id'],
                $validated,
                (int) Auth::id()
            );
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'mensaje' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok' => true,
            'mensaje' => 'Corrección creada y contrato retirado el '.$plano->fecha_ret->format('d-m-Y').'.',
            'factura_id' => $factura->id,
            'plano_id' => $plano->id,
            'n_plano' => (int) $plano->n_plano,
        ]);
    }
}
