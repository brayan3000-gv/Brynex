<?php

namespace App\Http\Controllers;

use App\Services\ValidadorSubtipoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Validador de cédulas de BryNex: dada una cédula, consulta a Simple si la UGPP
 * la deja usar el subtipo 03, 04 o 09, y recomienda el plan de salud-sin-pensión
 * que le corresponde legítimamente (X1 para quien califica, Ingreso-Retiro para
 * quien no). Solo consulta —con freno—, no liquida ni paga.
 */
class BrynexValidadorController extends Controller
{
    private function guard(): void
    {
        if (! Auth::user()->es_brynex) {
            abort(403, 'Acceso restringido a BryNex.');
        }
    }

    public function index()
    {
        $this->guard();

        return view('brynex.validador.index');
    }

    public function verificar(Request $request, ValidadorSubtipoService $svc)
    {
        $this->guard();

        $data = $request->validate([
            'tipo_doc' => 'required|string|max:2',
            'cedula' => 'required|string|max:16',
            'primer_apellido' => 'nullable|string|max:30',
            'segundo_apellido' => 'nullable|string|max:30',
            'primer_nombre' => 'nullable|string|max:30',
            'segundo_nombre' => 'nullable|string|max:30',
        ]);

        $cedula = trim($data['cedula']);

        // Si hay ficha del cliente, completa el nombre (el validador de archivos
        // cruza el nombre contra la RNEC; con el real evitamos ruido de formato).
        $cli = DB::table('clientes')->where('cedula', $cedula)->first();

        $persona = [
            'tipo_doc' => strtoupper(trim($data['tipo_doc'])),
            'cedula' => $cedula,
            'primer_apellido' => trim($data['primer_apellido'] ?? '') ?: ($cli->primer_apellido ?? ''),
            'segundo_apellido' => trim($data['segundo_apellido'] ?? '') ?: ($cli->segundo_apellido ?? ''),
            'primer_nombre' => trim($data['primer_nombre'] ?? '') ?: ($cli->primer_nombre ?? ''),
            'segundo_nombre' => trim($data['segundo_nombre'] ?? '') ?: ($cli->segundo_nombre ?? ''),
        ];

        if (! $persona['primer_apellido'] && ! $persona['primer_nombre']) {
            return response()->json([
                'ok' => false,
                'error' => 'No encontré ficha para esa cédula. Escribe al menos el primer apellido y el primer nombre.',
            ], 422);
        }

        return response()->json($svc->validar($persona));
    }
}
