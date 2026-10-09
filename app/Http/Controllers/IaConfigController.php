<?php

namespace App\Http\Controllers;

use App\Models\Aliado;
use App\Models\ConfiguracionBrynex;
use App\Models\IaConfiguracionAliado;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;

/**
 * Configuración del Asistente Virtual IA: proveedor global (Claude/OpenAI)
 * y activación/credenciales por aliado. Solo accesible por superadmin BryNex.
 */
class IaConfigController extends Controller
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

        $global = [
            'proveedor_default'   => ConfiguracionBrynex::obtener('ia_proveedor_default', 'claude'),
            'modelo_claude'       => ConfiguracionBrynex::obtener('ia_modelo_claude_default', 'claude-haiku-5-5'),
            'modelo_openai'       => ConfiguracionBrynex::obtener('ia_modelo_openai_default', 'gpt-4o-mini'),
            'modelo_gemini'       => ConfiguracionBrynex::obtener('ia_modelo_gemini_default', 'gemini-3.6-flash'),
            'tiene_key_claude'    => (bool) ConfiguracionBrynex::obtener('ia_global_claude_api_key'),
            'tiene_key_openai'    => (bool) ConfiguracionBrynex::obtener('ia_global_openai_api_key'),
            'tiene_key_gemini'    => (bool) ConfiguracionBrynex::obtener('ia_global_gemini_api_key'),
        ];

        return view('brynex.ia.index', compact('aliados', 'configs', 'global'));
    }

    public function guardarGlobal(Request $request)
    {
        $this->autorizarSuperadminBrynex();

        $validated = $request->validate([
            'proveedor_default'    => 'required|in:claude,openai,gemini',
            'modelo_claude'        => 'nullable|string|max:100',
            'modelo_openai'        => 'nullable|string|max:100',
            'modelo_gemini'        => 'nullable|string|max:100',
            'claude_api_key'       => 'nullable|string|max:2000',
            'openai_api_key'       => 'nullable|string|max:2000',
            'gemini_api_key'       => 'nullable|string|max:2000',
        ]);

        ConfiguracionBrynex::establecer('ia_proveedor_default', $validated['proveedor_default']);
        if (!empty($validated['modelo_claude'])) {
            ConfiguracionBrynex::establecer('ia_modelo_claude_default', $validated['modelo_claude']);
        }
        if (!empty($validated['modelo_openai'])) {
            ConfiguracionBrynex::establecer('ia_modelo_openai_default', $validated['modelo_openai']);
        }
        if (!empty($validated['modelo_gemini'])) {
            ConfiguracionBrynex::establecer('ia_modelo_gemini_default', $validated['modelo_gemini']);
        }
        if (!empty($validated['claude_api_key'])) {
            ConfiguracionBrynex::establecer('ia_global_claude_api_key', Crypt::encryptString($validated['claude_api_key']));
        }
        if (!empty($validated['openai_api_key'])) {
            ConfiguracionBrynex::establecer('ia_global_openai_api_key', Crypt::encryptString($validated['openai_api_key']));
        }
        if (!empty($validated['gemini_api_key'])) {
            ConfiguracionBrynex::establecer('ia_global_gemini_api_key', Crypt::encryptString($validated['gemini_api_key']));
        }

        return redirect()->route('brynex.ia.index')->with('success', 'Configuración global del asistente actualizada.');
    }

    public function guardarAliado(Request $request, int $alidoId)
    {
        $this->autorizarSuperadminBrynex();

        $aliado = Aliado::findOrFail($alidoId);

        $validated = $request->validate([
            'proveedor'          => 'required|in:claude,openai,gemini',
            'usa_cuenta_brynex'  => 'required|boolean',
            'api_key'            => 'nullable|string|max:2000',
            'modelo'             => 'nullable|string|max:100',
            'nombre_bot'         => 'nullable|string|max:100',
            'activo_web'         => 'required|boolean',
            'activo_whatsapp'    => 'required|boolean',
            'gemini_api_key'     => 'nullable|string|max:2000',
        ]);

        $config = IaConfiguracionAliado::paraAliado($alidoId);
        $config->proveedor         = $validated['proveedor'];
        $config->usa_cuenta_brynex = $validated['usa_cuenta_brynex'];
        $config->modelo            = $validated['modelo'] ?: null;
        $config->nombre_bot        = $validated['nombre_bot'] ?: null;
        $config->activo_web        = $validated['activo_web'];
        $config->activo_whatsapp   = $validated['activo_whatsapp'];
        if (!empty($validated['api_key'])) {
            $config->api_key = $validated['api_key'];
        }
        if (!empty($validated['gemini_api_key'])) {
            $config->gemini_api_key = $validated['gemini_api_key'];
        }
        $config->save();

        return redirect()->route('brynex.ia.index')->with('success', "Configuración IA actualizada para {$aliado->nombre}");
    }

    private function autorizarSuperadminBrynex(): void
    {
        $user = Auth::user();
        abort_unless($user->es_brynex && $user->hasRole('superadmin'), 403);
    }
}
