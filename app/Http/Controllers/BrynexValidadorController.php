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

        $apellido = trim($data['primer_apellido'] ?? '') ?: ($cli->primer_apellido ?? '');
        $nombre = trim($data['primer_nombre'] ?? '') ?: ($cli->primer_nombre ?? '');

        // El gate de la UGPP (2.655 / 2.506) es por cédula: el nombre no cambia
        // el resultado (verificado 12-nov-2026). Así que si no hay ficha ni se
        // escribió nombre —caso típico del lote de prospectos— se usa uno
        // genérico para que la línea quede bien formada, y la cédula igual se
        // valida. Se marca `con_ficha` para que la vista avise si el nombre no
        // es el real.
        $persona = [
            'tipo_doc' => strtoupper(trim($data['tipo_doc'])) ?: 'CC',
            'cedula' => $cedula,
            'primer_apellido' => $apellido ?: 'VALIDACION',
            'segundo_apellido' => trim($data['segundo_apellido'] ?? '') ?: ($cli->segundo_apellido ?? ''),
            'primer_nombre' => $nombre ?: 'CEDULA',
            'segundo_nombre' => trim($data['segundo_nombre'] ?? '') ?: ($cli->segundo_nombre ?? ''),
        ];

        $res = $svc->validar($persona);
        if (is_array($res)) {
            $res['con_ficha'] = (bool) $cli;
        }

        return response()->json($res);
    }
}
