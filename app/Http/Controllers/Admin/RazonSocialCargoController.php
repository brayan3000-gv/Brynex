<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RazonSocial;
use App\Models\RazonSocialCargo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Catálogo de cargos de una razón social, cada uno con su nivel de riesgo.
 *
 * Alimenta el selector del formulario de contratos: al elegir un cargo queda
 * resuelto también el nivel de riesgo y, con él, el centro de trabajo que exige
 * ARL Sura al afiliar.
 */
class RazonSocialCargoController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /** Cargos activos de una razón social, para el datalist del contrato. */
    public function index(Request $request, int $razonSocialId)
    {
        $this->razonSocialDelAliado($razonSocialId);

        // Los del catálogo común y los propios de esta razón social.
        $cargos = RazonSocialCargo::visiblesPara($razonSocialId)
            ->activos()
            ->get(['id', 'cargo', 'codigo_ocupacion', 'nivel_riesgo', 'por_defecto', 'razon_social_id']);

        return response()->json(['cargos' => $cargos]);
    }

    public function store(Request $request, int $razonSocialId)
    {
        $this->razonSocialDelAliado($razonSocialId);

        $datos = $request->validate([
            'cargo'        => 'required|string|max:150',
            'nivel_riesgo' => 'required|integer|min:1|max:5',
            'por_defecto'  => 'boolean',
        ]);

        $cargo = RazonSocialCargo::updateOrCreate(
            [
                'razon_social_id' => $razonSocialId,
                'cargo'           => mb_strtoupper(trim($datos['cargo'])),
            ],
            [
                'aliado_id'    => $this->aliado(),
                'nivel_riesgo' => $datos['nivel_riesgo'],
                'por_defecto'  => $datos['por_defecto'] ?? false,
                'activo'       => true,
            ]
        );

        return response()->json(['ok' => true, 'cargo' => $cargo]);
    }

    /**
     * La razón social tiene que ser del aliado activo: el id viene en la URL y,
     * sin esto, cualquiera con sesión leía o le agregaba cargos a la de otro aliado.
     */
    private function razonSocialDelAliado(int $razonSocialId): void
    {
        abort_unless(
            RazonSocial::where('id', $razonSocialId)->where('aliado_id', $this->aliado())->exists(),
            404
        );
    }

    private function aliado(): int
    {
        return (int) session('aliado_id_activo', Auth::user()->aliado_id);
    }
}
