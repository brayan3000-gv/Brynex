<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Demo\PilaSimulada;
use App\Services\Demo\RadicacionSimulada;
use App\Services\Demo\RelevoDemo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Lo que solo existe en la demo (routes/demo.php): el registro de quien la
 * prueba, su WhatsApp, la radicación y la anulación simuladas ante las
 * entidades y la pasarela PSE de prueba.
 */
class DemoController extends Controller
{
    /**
     * La entrada a la demo: nombre, empresa y celular, y adentro con la cuenta
     * compartida. Lo que escribe se guarda en producción (RelevoDemo), porque la
     * base de la demo se borra cada noche.
     */
    public function registro(Request $request)
    {
        $datos = $request->validate([
            'nombre' => 'required|string|min:3|max:120',
            'empresa' => 'nullable|string|max:150',
            'celular' => ['required', 'string', 'regex:/^\+?[\d\s\-]{10,16}$/'],
            'acepto' => 'accepted',
        ], [
            'celular.regex' => 'Escribe un celular válido, por ejemplo 300 123 4567.',
            'acepto.accepted' => 'Necesitamos tu autorización para guardar tus datos y escribirte.',
        ]);

        $r = RelevoDemo::pedir('registro', [
            'nombre' => trim($datos['nombre']),
            'empresa' => trim((string) ($datos['empresa'] ?? '')) ?: null,
            'celular' => preg_replace('/\D/', '', $datos['celular']),
            'acepto_contacto' => true,
            'ip' => $request->ip(),
        ]);

        $usuario = User::where('cedula', (string) config('demo.usuario'))->where('activo', 1)->firstOrFail();
        Auth::login($usuario);
        $request->session()->regenerate();
        session()->forget('aliado_id_activo');

        session(['demo_prospecto' => [
            'id' => $r['id'] ?? null,
            'nombre' => trim($datos['nombre']),
            'codigo' => $r['codigo'] ?? null,
            'numero' => $r['numero'] ?? null,
            'bienvenida' => (bool) ($r['bienvenida'] ?? false),
            'celular' => preg_replace('/\D/', '', $datos['celular']),
            'verificado' => false,
            'envios' => 0,
            'max' => $r['max'] ?? RelevoDemo::maxEnvios(),
        ]]);

        return redirect()->route('dashboard');
    }

    /** La presentación se reprodujo (lo avisa el reproductor de la bienvenida). */
    public function actividad(Request $request)
    {
        if ($request->boolean('presentacion')) {
            session(['demo_presentacion' => true]);
            RelevoDemo::sincronizarActividad();
        }

        return response()->json(['ok' => true]);
    }

    /** ¿Ya escribió el código desde su WhatsApp? Lo consulta el panel cada pocos segundos. */
    public function whatsappEstado()
    {
        $p = session('demo_prospecto');

        if (! $p || empty($p['id'])) {
            return response()->json(['activo' => false]);
        }

        $r = RelevoDemo::pedir('estado', ['id' => $p['id']]);

        if ($r && ! empty($r['ok'])) {
            $p = ['verificado' => (bool) $r['verificado'], 'envios' => (int) $r['envios'], 'max' => (int) $r['max']] + $p;
            session(['demo_prospecto' => $p]);
        }

        return response()->json([
            'activo' => (bool) $r,
            'verificado' => $p['verificado'],
            'envios' => $p['envios'],
            'max' => $p['max'],
            'codigo' => $p['codigo'],
            'numero' => $p['numero'],
            'bienvenida' => (bool) ($p['bienvenida'] ?? false),
            'celular' => $p['celular'] ?? null,
        ]);
    }

    /** Anular o retirar la afiliación a la ARL, simulado (la contracara de radicar). */
    public function anular(Request $request, int $id)
    {
        $datos = $request->validate([
            'operacion' => 'required|in:anular,retirar',
            'fecha' => 'nullable|date',
        ]);

        try {
            $resultado = RadicacionSimulada::para($id)->anular($datos['operacion'], $datos['fecha'] ?? null, Auth::id());
        } catch (Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'mensaje' => 'No se pudo simular: '.$e->getMessage()], 422);
        }

        $radicado = RadicacionSimulada::para($id)->radicado();

        return response()->json($resultado + [
            'ok' => true,
            'pdf_url' => route('demo.radicar.pdf', $id),
            'pdf_descarga' => route('admin.radicados.pdf.download', $id),
            'radicado' => $radicado->paraLaLista(),
        ]);
    }

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
            'pasos_anulacion' => ['anular' => $r->pasosAnulacion('anular'), 'retirar' => $r->pasosAnulacion('retirar')],
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
