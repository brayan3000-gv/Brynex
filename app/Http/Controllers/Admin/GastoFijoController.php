<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BancoCuenta;
use App\Models\Gasto;
use App\Models\GastoFijo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * La lista de gastos fijos del aliado (botón «Gastos fijos» del estado
 * financiero) y el «No aplica este mes» de su aviso. Qué falta reportar lo
 * decide App\Services\Caja\GastosFijos. Las rutas piden gastos.gestionar.
 */
class GastoFijoController extends Controller
{
    private function aliadoId(): int
    {
        return (int) session('aliado_id_activo');
    }

    private function reglas(): array
    {
        return [
            'concepto'        => 'required|string|max:150',
            'tipo'            => ['required', Rule::in(array_keys(Gasto::TIPOS))],
            'pagado_a'        => 'nullable|string|max:255',
            'palabra_clave'   => 'nullable|string|max:100',
            'valor'           => 'required|integer|min:0',
            'frecuencia'      => ['required', Rule::in(array_keys(GastoFijo::FRECUENCIAS))],
            'dia'             => 'nullable|integer|between:1,31',
            'forma_pago'      => 'nullable|in:efectivo,transferencia_bancaria',
            'banco_origen_id' => 'nullable|integer',
            'desde'           => 'nullable|date',
        ];
    }

    private function datos(Request $request): array
    {
        $d = $request->validate($this->reglas());
        // La cuenta tiene que ser del aliado activo.
        if (! empty($d['banco_origen_id'])
            && ! BancoCuenta::where('aliado_id', $this->aliadoId())->whereKey($d['banco_origen_id'])->exists()) {
            $d['banco_origen_id'] = null;
        }
        if (($d['forma_pago'] ?? null) !== 'transferencia_bancaria') {
            $d['banco_origen_id'] = null;
        }

        return $d;
    }

    private function delAliado(int $id): GastoFijo
    {
        return GastoFijo::where('aliado_id', $this->aliadoId())->findOrFail($id);
    }

    public function store(Request $request)
    {
        GastoFijo::create($this->datos($request) + [
            'aliado_id'  => $this->aliadoId(),
            'usuario_id' => Auth::id(),
            'activo'     => true,
        ]);

        return back()->with('ver_fijos', true)->with('success', 'Gasto fijo agregado.');
    }

    public function update(Request $request, int $id)
    {
        $this->delAliado($id)->update($this->datos($request));

        return back()->with('ver_fijos', true)->with('success', 'Gasto fijo actualizado.');
    }

    /** Se desactiva (no se borra): los gastos registrados desde el aviso lo siguen nombrando. */
    public function destroy(int $id)
    {
        $this->delAliado($id)->update(['activo' => false]);

        return back()->with('ver_fijos', true)->with('success', 'Gasto fijo quitado de la lista.');
    }

    /** «No aplica este mes»: el aviso deja de pedirlo en ese mes. */
    public function omitir(Request $request, int $id)
    {
        $fijo = $this->delAliado($id);
        $v = $request->validate([
            'anio' => 'required|integer|min:2026',
            'mes'  => 'required|integer|between:1,12',
        ]);

        DB::table('gastos_fijos_omitidos')->updateOrInsert(
            ['gasto_fijo_id' => $fijo->id, 'anio' => $v['anio'], 'mes' => $v['mes']],
            ['usuario_id' => Auth::id(), 'created_at' => now(), 'updated_at' => now()]
        );

        return back()->with('success', "«{$fijo->concepto}» no aplica este mes.");
    }
}
