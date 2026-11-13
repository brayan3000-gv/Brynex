<?php

namespace App\Http\Controllers;

use App\Services\Demo\PilaSimulada;
use App\Services\Demo\RadicacionSimulada;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Lo que solo existe en la demo (routes/demo.php): la radicación simulada ante
 * las entidades y la pasarela PSE de prueba.
 */
class DemoController extends Controller
{
    /** Qué se va a enviar y qué falta, como el precheck de los trámites reales. */
    public function precheck(int $id)
    {
        $r = RadicacionSimulada::para($id);

        return response()->json([
            'ok' => empty($r->problemas()),
            'problemas' => $r->problemas(),
            'entidad' => $r->entidad(),
            'resumen' => $r->resumen(),
            'pasos' => $r->pasos(),
            'payload' => $r->payload(),
            'fecha_sugerida' => $r->fechaSugerida(),
            'estado' => $r->radicado()->estado,
        ]);
    }

    public function radicar(Request $request, int $id)
    {
        $datos = $request->validate(['fecha_cobertura' => 'nullable|date']);
        $r = RadicacionSimulada::para($id);

        if ($faltan = $r->problemas()) {
            return response()->json(['ok' => false, 'mensaje' => 'Faltan datos para radicar.', 'problemas' => $faltan], 422);
        }

        try {
            $resultado = $r->radicar($datos['fecha_cobertura'] ?? null, Auth::id());
        } catch (Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'mensaje' => 'No se pudo simular la radicación: '.$e->getMessage()], 422);
        }

        $radicado = $r->radicado()->refresh();

        return response()->json([
            'ok' => true,
            'numero' => $resultado['numero'],
            'fecha_cobertura' => $resultado['fecha_cobertura'],
            'respuesta' => $resultado['respuesta'],
            'pdf_url' => route('demo.radicar.pdf', $radicado->id),
            'pdf_descarga' => route('admin.radicados.pdf.download', $radicado->id),
            'radicado' => $radicado->paraLaLista(),
            'tipo' => $radicado->tipo,
        ]);
    }

    /** El PDF del radicado para verlo dentro del modal (la ruta de siempre lo descarga). */
    public function pdf(int $id)
    {
        $radicado = RadicacionSimulada::para($id)->radicado();
        abort_unless($radicado->ruta_pdf && Storage::disk('local')->exists($radicado->ruta_pdf), 404);

        return response(Storage::disk('local')->get($radicado->ruta_pdf), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="radicado_demo_'.$radicado->id.'.pdf"',
        ]);
    }

    /** La pasarela PSE de prueba a la que lleva «Pagar» en las planillas liquidadas. */
    public function pagoPse(int $numero)
    {
        $planilla = Cache::get("demo_pila_{$numero}");
        abort_unless($planilla, 404, 'Planilla no encontrada en el operador de prueba.');

        return view('demo.pago-pse', ['numero' => $numero, 'planilla' => $planilla]);
    }

    public function aprobarPse(int $numero)
    {
        $planilla = Cache::get("demo_pila_{$numero}");
        abort_unless($planilla, 404);

        $planilla['pagada'] = true;
        $planilla['cus'] = $planilla['cus'] ?? 'DEMO'.Str::upper(Str::random(10));
        $planilla['pagada_en'] = now()->format('d/m/Y H:i');
        Cache::put("demo_pila_{$numero}", $planilla, now()->addDays(40));

        return view('demo.pago-pse', ['numero' => $numero, 'planilla' => $planilla]);
    }
}
