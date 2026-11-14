<?php

namespace App\Http\Controllers;

use App\Models\Aliado;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BrynexController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    // ── Hub principal BryNex ─────────────────────────────────────────────
    public function hub()
    {
        $user = Auth::user();
        if (!$user->es_brynex) {
            abort(403);
        }

        $aliados      = Aliado::orderBy('nombre')->get();
        $totalAliados = $aliados->count();
        $activos      = $aliados->where('activo', true)->count();
        $usuariosBrynex = User::where('es_brynex', true)->count();

        // Configuraciones WhatsApp por aliado
        $whatsappConfigs = DB::table('whatsapp_configuracion_aliado')
            ->get()
            ->keyBy('aliado_id');
        $totalWaConfigurados = $whatsappConfigs->where('activo', 1)->count();

        // Pagos X1: solicitudes de los aliados que BryNex todavía no termina.
        $x1Puede = \App\Services\X1LiquidacionBrynex::puedeOperar($user);
        $x1Abiertas = $x1Puede
            ? DB::table('x1_solicitudes')->where('estado', '!=', 'pagada')->count()
            : 0;

        return view('brynex.hub', compact(
            'aliados', 'totalAliados', 'activos', 'usuariosBrynex',
            'whatsappConfigs', 'totalWaConfigurados', 'x1Puede', 'x1Abiertas'
        ));
    }

    // ── Gestión de accesos: qué BryNex puede entrar a qué aliado ────────
    public function accesos()
    {
        $user = Auth::user();
        if (!$user->es_brynex || !$user->hasRole('superadmin')) {
            abort(403);
        }

        // Todos los usuarios BryNex (excepto el superadmin actual)
        $usuariosBrynex = User::where('es_brynex', true)
            ->where('id', '<>', $user->id)
            ->whereNull('deleted_at')
            ->orderBy('nombre')
            ->with(['aliados' => fn($q) => $q->wherePivot('activo', true)])
            ->get();

        $aliados = Aliado::where('activo', true)->orderBy('nombre')->get();

        return view('brynex.accesos', compact('usuariosBrynex', 'aliados'));
    }

    // ── Toggle acceso usuario ↔ aliado ───────────────────────────────────
    public function toggleAcceso(Request $request)
    {
        $auth = Auth::user();
        if (!$auth->es_brynex || !$auth->hasRole('superadmin')) {
            abort(403);
        }

        $request->validate([
            'user_id'   => 'required|integer|exists:users,id',
            'aliado_id' => 'required|integer|exists:aliados,id',
        ]);

        $userId   = (int) $request->user_id;
        $alidoId  = (int) $request->aliado_id;

        // No puede modificarse a sí mismo ni a otros superadmins
        $targetUser = User::findOrFail($userId);
        if (!$targetUser->es_brynex || $targetUser->hasRole('superadmin')) {
            return response()->json(['ok' => false, 'mensaje' => 'No se puede modificar este usuario.'], 422);
        }

        $existing = DB::table('aliado_user')
            ->where('user_id', $userId)
            ->where('aliado_id', $alidoId)
            ->first();

        if ($existing) {
            // Toggle activo
            $nuevoEstado = !$existing->activo;
            DB::table('aliado_user')
                ->where('user_id', $userId)
                ->where('aliado_id', $alidoId)
                ->update(['activo' => $nuevoEstado, 'updated_at' => now()]);
        } else {
            // Crear nuevo acceso
            DB::table('aliado_user')->insert([
                'user_id'    => $userId,
                'aliado_id'  => $alidoId,
                'rol'        => 'staff',
                'activo'     => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $nuevoEstado = true;
        }

        return response()->json([
            'ok'     => true,
            'activo' => $nuevoEstado,
            'mensaje' => $nuevoEstado ? 'Acceso habilitado.' : 'Acceso revocado.',
        ]);
    }

    // ── Parámetros globales del sistema ──────────────────────────────────
    // Salían en la Configuración de cada aliado, pero no son del aliado: el salario
    // mínimo, los porcentajes de seguridad social y las tarifas ARL son los mismos
    // para todos. Verlos ahí invitaba a creer que se editaban solo para ese aliado.

    /** Solo el superadmin de BryNex toca estos números: mueven la cotización de todos. */
    private function soloSuperadminBrynex(): void
    {
        $u = Auth::user();
        if (! $u->es_brynex || ! $u->hasRole('superadmin')) {
            abort(403, 'Solo el superadmin de BryNex puede ver los parámetros globales.');
        }
    }

    public function parametros()
    {
        $this->soloSuperadminBrynex();

        return view('brynex.parametros', [
            'configBrynex' => \App\Models\ConfiguracionBrynex::all()->keyBy('clave'),
            'arlGlobal' => \App\Models\ArlTarifa::whereNull('aliado_id')->orderBy('nivel')->get()->keyBy('nivel'),
        ]);
    }

    public function guardarParametros(Request $request)
    {
        $this->soloSuperadminBrynex();

        DB::transaction(function () use ($request) {
            foreach ($request->input('brynex', []) as $clave => $valor) {
                if ($valor !== null && $valor !== '') {
                    \App\Models\ConfiguracionBrynex::establecer($clave, $valor);
                }
            }

            // Las tarifas ARL globales son las de aliado_id NULL. Aquí no se borran ni se
            // crean niveles: solo se actualiza el porcentaje de los cinco que ya existen.
            foreach ($request->input('arl', []) as $nivel => $data) {
                $pct = $data['porcentaje'] ?? null;
                if ($pct === null || $pct === '') {
                    continue;
                }
                \App\Models\ArlTarifa::whereNull('aliado_id')->where('nivel', (int) $nivel)
                    ->update(['porcentaje' => $pct, 'updated_at' => now()]);
            }
        });

        \App\Models\ConfiguracionBrynex::limpiarCache();

        // La grilla de seguridad social está cacheada 12h POR ALIADO y se calcula con el
        // salario mínimo y estos porcentajes: si no se limpia la de todos, media plataforma
        // sigue mostrando la cotización vieja hasta que venza el caché.
        foreach (Aliado::pluck('id') as $id) {
            \App\Services\TarifaAsesorService::olvidarGridSs((int) $id);
        }

        \App\Models\Bitacora::registrar(
            'updated', 'ConfiguracionBrynex', 0,
            'Parámetros globales del sistema actualizados desde BryNex.'
        );

        return redirect()->route('brynex.parametros')
            ->with('success', 'Parámetros globales actualizados.');
    }
}
