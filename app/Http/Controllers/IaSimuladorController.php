<?php

namespace App\Http\Controllers;

use App\Models\Aliado;
use App\Models\IaConfiguracionAliado;
use App\Models\IaConversacion;
use App\Models\IaEntrenamientoNota;
use App\Models\IaMensaje;
use App\Models\MarketingBloqueado;
use App\Models\WhatsappConversacion;
use App\Services\Ia\AsistenteIaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Simulador de conversación: le permite a un entrenador escribirle a la IA real de un
 * aliado (mismo prompt, mismas tools, mismo proveedor configurado) sin tocar WhatsApp de
 * verdad. Corre en modo_prueba: las tools con efectos externos reales (ADRES, registro de
 * prospecto) se simulan en vez de ejecutarse — ver AsistenteIaService::responderWhatsapp()
 * y los guards en ChequeoSeguridadSocialTool/RegistroProspectoIa.
 *
 * Cada respuesta se puede marcar como incorrecta con una nota de corrección, que queda
 * guardada en ia_entrenamiento_notas para revisar después y ajustar el prompt o una tool.
 */
class IaSimuladorController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        $this->autorizarSuperadminBrynex();

        $aliados = Aliado::orderBy('nombre')->get();
        $configs = IaConfiguracionAliado::all()->keyBy('aliado_id');
        $notas = IaEntrenamientoNota::with('aliado:id,nombre', 'creadoPor:id,name')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return view('brynex.ia.simulador', compact('aliados', 'configs', 'notas'));
    }

    public function historial(int $alidoId)
    {
        $this->autorizarSuperadminBrynex();

        $conversacion = IaConversacion::where('aliado_id', $alidoId)
            ->where('canal', 'whatsapp')
            ->where('telefono', $this->telefonoPlayground($alidoId))
            ->first();

        $mensajes = $conversacion
            ? IaMensaje::where('conversacion_id', $conversacion->id)->orderBy('id')->get(['rol', 'contenido', 'tool_name'])
            : collect();

        return response()->json(['mensajes' => $mensajes]);
    }

    public function mensaje(Request $request)
    {
        $this->autorizarSuperadminBrynex();

        $validated = $request->validate([
            'aliado_id' => 'required|integer|exists:aliados,id',
            'mensaje'   => 'required|string|max:2000',
        ]);

        $alidoId = (int) $validated['aliado_id'];
        $waConversacion = $this->conversacionPlayground($alidoId);

        try {
            $resultado = (new AsistenteIaService())->responderWhatsapp(
                $alidoId,
                $this->telefonoPlayground($alidoId),
                $validated['mensaje'],
                $waConversacion->id,
                null,
                null,
                null,
                modoPrueba: true,
            );
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'respuesta'           => $resultado['respuesta'],
            'nombre_bot'          => $resultado['nombre_bot'],
            'herramientas_usadas' => $resultado['herramientas_usadas'],
        ]);
    }

    public function guardarNota(Request $request)
    {
        $this->autorizarSuperadminBrynex();

        $validated = $request->validate([
            'aliado_id'       => 'required|integer|exists:aliados,id',
            'mensaje_cliente' => 'required|string',
            'respuesta_ia'    => 'required|string',
            'correccion'      => 'required|string',
            'contexto'        => 'nullable|string',
        ]);

        $nota = IaEntrenamientoNota::create($validated + ['creado_por' => Auth::id()]);

        return response()->json(['ok' => true, 'nota_id' => $nota->id]);
    }

    public function resolverNota(int $id)
    {
        $this->autorizarSuperadminBrynex();

        IaEntrenamientoNota::where('id', $id)->update(['estado' => 'resuelta']);

        return response()->json(['ok' => true]);
    }

    public function reiniciar(Request $request)
    {
        $this->autorizarSuperadminBrynex();

        $validated = $request->validate(['aliado_id' => 'required|integer|exists:aliados,id']);
        $alidoId = (int) $validated['aliado_id'];

        $conversacionIa = IaConversacion::where('aliado_id', $alidoId)
            ->where('canal', 'whatsapp')
            ->where('telefono', $this->telefonoPlayground($alidoId))
            ->first();

        if ($conversacionIa) {
            IaMensaje::where('conversacion_id', $conversacionIa->id)->delete();
        }

        $waConversacion = WhatsappConversacion::where('aliado_id', $alidoId)
            ->where('wa_contact_id', $this->telefonoPlayground($alidoId))
            ->first();

        // hablar_con_asesor / no_contactar pueden haber dejado la conversación de prueba
        // apagada o el número bloqueado de marketing — se restaura para la próxima corrida.
        $waConversacion?->update(['bot_activo' => true, 'pendiente_atencion' => false, 'pendiente_motivo' => null, 'perfil_ia' => null, 'perfil_ia_at' => null]);
        MarketingBloqueado::where('aliado_id', $alidoId)->where('celular', $this->telefonoPlayground($alidoId))->delete();

        return response()->json(['ok' => true]);
    }

    private function telefonoPlayground(int $alidoId): string
    {
        // Debe tener EXACTAMENTE 10 dígitos: resolverContactoExistente() limpia todo lo que no sea
        // número y compara los últimos 10 contra celular con LIKE — con menos de 10 dígitos (ej.
        // "playground-2" quedaba en solo "2") ese comodín hacía match con cualquier cliente real
        // cuyo celular terminara en ese dígito, filtrando su nombre al simulador (bug real,
        // detectado probando: el simulador saludó a un cliente real por su nombre).
        return '00000' . str_pad((string) $alidoId, 5, '0', STR_PAD_LEFT);
    }

    private function conversacionPlayground(int $alidoId): WhatsappConversacion
    {
        return WhatsappConversacion::firstOrCreate(
            ['aliado_id' => $alidoId, 'wa_contact_id' => $this->telefonoPlayground($alidoId)],
            [
                'nombre_contacto' => 'SIMULADOR DE ENTRENAMIENTO — no es cliente real',
                'estado'          => 'abierta',
                'bot_activo'      => true,
            ]
        );
    }

    private function autorizarSuperadminBrynex(): void
    {
        $user = Auth::user();
        abort_unless($user->es_brynex && $user->hasRole('superadmin'), 403);
    }
}
