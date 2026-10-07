<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CorreccionPagoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * «Corregir pago» del recibo: forma de pago, consignaciones y fecha de pago
 * de un recibo ya pagado, sin anularlo. Solo admin y superadmin.
 * Ver CorreccionPagoService.
 */
class CorreccionPagoController extends Controller
{
    public function show(int $id): JsonResponse
    {
        if ($r = $this->sinPermiso()) {
            return $r;
        }

        return response()->json([
            'ok' => true,
            ...CorreccionPagoService::estado((int) session('aliado_id_activo'), $id),
        ]);
    }

    public function store(Request $request, int $id): JsonResponse
    {
        if ($r = $this->sinPermiso()) {
            return $r;
        }

        $validated = $request->validate([
            'forma_pago' => 'required|in:efectivo,consignacion,mixto',
            'fecha_pago' => 'nullable|date',
            'valor_efectivo' => 'nullable|integer|min:0',
            'consignaciones' => 'nullable|array',
            'consignaciones.*.id' => 'nullable|integer',
            'consignaciones.*.banco_cuenta_id' => 'required|integer',
            'consignaciones.*.fecha' => 'required|date',
            'consignaciones.*.valor' => 'required|integer|min:0',
            'consignaciones.*.referencia' => 'nullable|string|max:100',
            'motivo' => 'required|string|min:5|max:200',
        ], [
            'motivo.required' => 'Escribe el motivo de la corrección.',
            'motivo.min' => 'El motivo es muy corto.',
            'consignaciones.*.banco_cuenta_id.required' => 'Cada consignación necesita su cuenta.',
            'consignaciones.*.fecha.required' => 'Cada consignación necesita su fecha.',
        ]);

        $validated['valor_efectivo'] = (int) ($validated['valor_efectivo'] ?? 0);
        $validated['motivo'] = trim($validated['motivo']);

        try {
            $r = CorreccionPagoService::aplicar((int) session('aliado_id_activo'), $id, $validated, (int) Auth::id());
        } catch (\RuntimeException $e) {
            return response()->json(['ok' => false, 'mensaje' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'mensaje' => 'Pago corregido. Quedó registrado en la bitácora.', ...$r]);
    }

    private function sinPermiso(): ?JsonResponse
    {
        $user = Auth::user();
        if ($user && $user->hasRole(['admin', 'superadmin'])) {
            return null;
        }

        return response()->json(['ok' => false, 'mensaje' => 'Solo un admin puede corregir el pago de un recibo.'], 403);
    }
}
