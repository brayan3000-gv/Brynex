<?php

namespace App\Http\Controllers;

use App\Models\DemoProspecto;
use App\Services\Demo\RelevoDemo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Producción atiende a la demo pública (routes/demo-relevo.php). Las tres
 * primeras rutas solo responden a pedidos firmados por la demo; la última es la
 * lista de prospectos para los usuarios de BryNex.
 */
class DemoRelevoController extends Controller
{
    public function registro(Request $request)
    {
        abort_unless(RelevoDemo::firmaValida($request), 403);

        $datos = $request->validate([
            'nombre' => 'required|string|max:120',
            'empresa' => 'nullable|string|max:150',
            'celular' => 'required|string|max:20',
            'acepto_contacto' => 'boolean',
            'origen' => 'nullable|in:asesor,redes,campana,otro',
            'origen_detalle' => 'nullable|string|max:120',
            'ip' => 'nullable|string|max:45',
        ]);

        $p = RelevoDemo::registrar($datos, $datos['ip'] ?? null);
        $bienvenida = $p->acepto_contacto && RelevoDemo::enviarBienvenida($p);
        RelevoDemo::avisarRegistro($p, $bienvenida);

        return response()->json([
            'ok' => true, 'id' => $p->id, 'codigo' => $p->codigo, 'bienvenida' => $bienvenida,
            'numero' => preg_replace('/\D/', '', (string) config('demo.relevo.numero')), 'max' => RelevoDemo::maxEnvios(),
        ]);
    }

    public function estado(Request $request)
    {
        abort_unless(RelevoDemo::firmaValida($request), 403);
        $p = $this->prospecto($request);

        return response()->json([
            'ok' => true,
            'verificado' => (bool) $p->verificado_at,
            'telefono' => $p->telefono_whatsapp,
            'envios' => $p->envios,
            'max' => RelevoDemo::maxEnvios(),
        ]);
    }

    public function enviar(Request $request)
    {
        abort_unless(RelevoDemo::firmaValida($request), 403);
        $p = $this->prospecto($request);

        $pedido = $request->validate([
            'pedido.tipo' => 'required|in:texto,documento',
            'pedido.texto' => 'nullable|string|max:4000',
            'pedido.archivo' => 'nullable|string',
            'pedido.nombre_archivo' => 'nullable|string|max:150',
            'pedido.mime' => 'nullable|string|max:80',
        ])['pedido'];

        return response()->json(RelevoDemo::enviarPedido($p, $pedido));
    }

    /** Lo que va haciendo en la demo (pasos, módulos, presentación). */
    public function actividad(Request $request)
    {
        abort_unless(RelevoDemo::firmaValida($request), 403);
        $p = $this->prospecto($request);

        $datos = $request->validate([
            'pasos' => 'array', 'pasos.*' => 'string|max:20',
            'modulos' => 'array', 'modulos.*' => 'string|max:40',
            'presentacion' => 'boolean',
        ]);

        return response()->json(['ok' => true, 'actividad' => RelevoDemo::anotarActividad($p, $datos)]);
    }

    /** Quiénes han probado la demo, para volver a escribirles. Solo usuarios de BryNex. */
    public function listado()
    {
        abort_unless(Auth::user()?->es_brynex, 403);

        $prospectos = DemoProspecto::orderByDesc('id')->limit(500)->get();

        // Cuántos trajo cada asesor o campaña, para las comisiones. El asesor es
        // texto libre: «juan perez» y «Juan Pérez» se cuentan como el mismo.
        $resumen = $prospectos->groupBy(fn ($p) => mb_strtolower(\Illuminate\Support\Str::ascii($p->origenTexto())))
            ->map(fn ($grupo) => [
                'origen' => $grupo->first()->origenTexto(),
                'total' => $grupo->count(),
                'verificados' => $grupo->whereNotNull('verificado_at')->count(),
                'completaron' => $grupo->filter(fn ($p) => count((array) (($p->actividad ?? [])['pasos'] ?? [])) >= RelevoDemo::PASOS_RECORRIDO)->count(),
            ])
            ->sortByDesc('total')
            ->values();

        return view('demo.prospectos', ['prospectos' => $prospectos, 'resumen' => $resumen]);
    }

    private function prospecto(Request $request): DemoProspecto
    {
        $p = DemoProspecto::whereKey((int) $request->input('id'))
            ->where('created_at', '>', now()->subDays(2))
            ->first();
        abort_unless($p, 404);
        $p->update(['ultimo_uso_at' => now()]);

        return $p;
    }
}
