<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\{OperadorCredencial, OperadorPlanilla, RazonSocial};
use App\Services\SuaporteApiService;
use Illuminate\Http\Request;

/**
 * Credenciales de las APIs de los operadores de planilla, por razón social.
 *
 * Los secretos se guardan cifrados (casts `encrypted` en OperadorCredencial)
 * y nunca se devuelven a la vista: el formulario solo informa si ya hay una
 * credencial cargada y cuándo vence la clave secreta.
 */
class OperadorCredencialController extends Controller
{
    // ── Credenciales del aliado (Configuración → Operadores de planilla) ──
    // Es el lugar natural: la cuenta del operador pertenece al usuario del
    // aliado y cubre todos los aportantes que ese usuario administre. Lo del
    // formulario de razón social queda como excepción puntual.

    /** Guarda la credencial del aliado para un operador. */
    public function storeAliado(Request $request, int $operadorId)
    {
        $aliadoId = session('aliado_id_activo');

        $validated = $request->validate([
            'usuario'                 => 'required|string|max:100',
            'contrasena'              => 'nullable|string|max:200',
            'clave_secreta'           => 'nullable|string|max:500',
            'clave_secreta_expira_at' => 'nullable|date',
        ]);

        $operador = OperadorPlanilla::paraAliado($aliadoId)->where('id', $operadorId)->first();

        if (!$operador) {
            return back()->with('cred_error', '⚠️ El operador no está activo para este aliado.');
        }

        $credencial = OperadorCredencial::where('aliado_id', $aliadoId)
            ->whereNull('razon_social_id')
            ->where('operador_planilla_id', $operador->id)
            ->first();

        $datos = [
            'usuario'                 => $validated['usuario'],
            'clave_secreta_expira_at' => $validated['clave_secreta_expira_at'] ?? null,
        ];

        // En blanco = conservar los actuales, para poder corregir el usuario
        // o la fecha sin volver a teclear los secretos.
        if (!empty($validated['contrasena'])) {
            $datos['contrasena'] = $validated['contrasena'];
        }
        if (!empty($validated['clave_secreta'])) {
            $datos['clave_secreta'] = $validated['clave_secreta'];
        }

        if ($credencial) {
            $credencial->update($datos);
        } else {
            if (empty($datos['contrasena']) || empty($datos['clave_secreta'])) {
                return back()->with('cred_error', '⚠️ La contraseña y la clave secreta son obligatorias la primera vez.');
            }

            $credencial = OperadorCredencial::create($datos + [
                'aliado_id'            => $aliadoId,
                'razon_social_id'      => null,
                'operador_planilla_id' => $operador->id,
            ]);
        }

        // Quien la guarda la está corrigiendo: aunque sea la misma de antes,
        // se le vuelve a dar una oportunidad.
        self::apiDe($credencial)?->olvidarRechazo();

        return back()->with('success', "🔑 Credenciales de {$operador->nombre} guardadas para todo el aliado.");
    }

    /**
     * Prueba la credencial del aliado y reporta sobre cuáles razones sociales
     * tiene permisos, que es lo que realmente determina el alcance.
     */
    public function probarAliado(int $operadorId)
    {
        $aliadoId = session('aliado_id_activo');

        $operador = OperadorPlanilla::paraAliado($aliadoId)->where('id', $operadorId)->first();

        if (!$operador || !SuaporteApiService::soportaOperador($operador->codigo)) {
            return back()->with('cred_error', '⚠️ Ese operador todavía no tiene integración por API.');
        }

        $credencial = OperadorCredencial::where('aliado_id', $aliadoId)
            ->whereNull('razon_social_id')
            ->where('operador_planilla_id', $operador->id)
            ->first();

        if (!$credencial) {
            return back()->with('cred_error', '⚠️ No hay credenciales guardadas para este operador.');
        }

        $api = new SuaporteApiService([
            'operador'      => $operador->codigo,
            'usuario'       => $credencial->usuario,
            'contrasena'    => $credencial->contrasena,
            'clave_secreta' => $credencial->clave_secreta,
        ]);

        $auth = $api->autenticar(forzar: true);

        if (!$auth['success']) {
            return back()->with('cred_error', "❌ {$operador->nombre} rechazó el login: " . $auth['message']);
        }

        // El login sirve; el alcance real lo da la autorización por aportante.
        $razones = RazonSocial::where('aliado_id', $aliadoId)
            ->where('estado', 'Activa')
            ->whereNotNull('nit')
            ->where('nit', '<>', '')
            ->orderBy('razon_social')
            ->get(['id', 'nit', 'razon_social']);

        $cubiertas = [];
        $sinAcceso = [];

        foreach ($razones as $rs) {
            $nit       = preg_replace('/\D/', '', (string) $rs->nit);
            $aportante = $api->consultarAportante('NI', $nit);

            if ($aportante['success'] && $api->autorizar($aportante['id'], 'NI', $nit)['success']) {
                $cubiertas[] = $rs->razon_social;
            } else {
                $sinAcceso[] = $rs->razon_social;
            }
        }

        $mensaje = "✅ Login correcto en {$operador->nombre}. "
                 . 'Cubre ' . count($cubiertas) . ' de ' . $razones->count() . ' razones sociales.';

        if ($sinAcceso) {
            $mensaje .= ' Sin permisos sobre: ' . implode(', ', $sinAcceso)
                      . '. Solicítelos desde el portal del operador.';
        }

        return back()->with('success', $mensaje);
    }

    /** Elimina la credencial del aliado para un operador. */
    public function destroyAliado(int $operadorId)
    {
        $aliadoId = session('aliado_id_activo');

        $credencial = OperadorCredencial::where('aliado_id', $aliadoId)
            ->whereNull('razon_social_id')
            ->where('operador_planilla_id', $operadorId)
            ->first();

        if (!$credencial) {
            return back()->with('cred_error', '⚠️ No hay credenciales para eliminar.');
        }

        $credencial->delete();

        return back()->with('success', '🗑️ Credenciales eliminadas. Ninguna razón social podrá liquidar con ese operador.');
    }

    /** Estado de las credenciales del aliado, para la pantalla de operadores. */
    public static function estadoDelAliado(int $aliadoId)
    {
        return OperadorCredencial::where('aliado_id', $aliadoId)
            ->whereNull('razon_social_id')
            ->get()
            ->keyBy('operador_planilla_id');
    }

    // ── Contraseña rechazada por el operador ─────────────────────────────
    // Cuando Enlace rechaza la contraseña, SuaporteApiService deja de
    // intentar con ella (cada intento cuenta para el bloqueo de la cuenta) y
    // el layout abre un modal que la pide de nuevo. Ver `rechazadasDelAliado`.

    /** Cliente de la plataforma para una credencial, o null si el operador no tiene API. */
    private static function apiDe(OperadorCredencial $credencial): ?SuaporteApiService
    {
        $operador = OperadorPlanilla::find($credencial->operador_planilla_id);

        if (!$operador || !SuaporteApiService::soportaOperador($operador->codigo)) {
            return null;
        }

        return new SuaporteApiService([
            'operador'      => $operador->codigo,
            'usuario'       => $credencial->usuario,
            'contrasena'    => (string) $credencial->contrasena,
            'clave_secreta' => (string) $credencial->clave_secreta,
        ]);
    }

    /**
     * Credenciales del aliado que el operador rechazó (clave inválida o
     * cuenta bloqueada), para el modal del layout. Son pocas filas por aliado.
     */
    public static function rechazadasDelAliado(int $aliadoId): array
    {
        $rechazadas = [];

        $credenciales = OperadorCredencial::with('operadorPlanilla', 'razonSocial')
            ->where('aliado_id', $aliadoId)
            ->get();

        foreach ($credenciales as $credencial) {
            $rechazo = self::apiDe($credencial)?->rechazoVigente();

            if ($rechazo) {
                $rechazadas[] = [
                    'id'       => $credencial->id,
                    'operador' => $credencial->operadorPlanilla?->nombre,
                    'usuario'  => $credencial->usuario,
                    'razon'    => $credencial->razonSocial?->razon_social,
                    'tipo'     => $rechazo['tipo'],
                    'mensaje'  => $rechazo['mensaje'],
                    'en'       => \Carbon\Carbon::parse($rechazo['en'])->format('d/m g:i a'),
                    'hasta'    => $rechazo['hasta'] ? \Carbon\Carbon::parse($rechazo['hasta'])->format('g:i a') : null,
                    'marca'    => $credencial->id . '|' . $rechazo['en'],
                ];
            }
        }

        return $rechazadas;
    }

    /**
     * Guarda la contraseña nueva desde el modal y la prueba con un solo login.
     *
     * Se copia también a las otras credenciales del mismo usuario y operador
     * que tenían exactamente la misma contraseña vieja (la cuenta de Brayan
     * está en los aliados 1 y 2): solo quien conoce la cuenta las comparte,
     * y así no queda una copia vieja que la vuelva a bloquear.
     */
    public function actualizarContrasena(Request $request, int $credencialId)
    {
        $aliadoId = session('aliado_id_activo');

        $validated = $request->validate([
            'contrasena' => 'required|string|max:200',
        ]);

        $credencial = OperadorCredencial::where('aliado_id', $aliadoId)->findOrFail($credencialId);
        $vieja      = (string) $credencial->contrasena;

        $copias = OperadorCredencial::where('id', '<>', $credencial->id)
            ->where('operador_planilla_id', $credencial->operador_planilla_id)
            ->where('usuario', $credencial->usuario)
            ->get()
            ->filter(fn ($c) => (string) $c->contrasena === $vieja);

        foreach ($copias->prepend($credencial) as $c) {
            $c->update(['contrasena' => $validated['contrasena']]);
            self::apiDe($c)?->olvidarRechazo();
        }

        $operador = $credencial->operadorPlanilla?->nombre ?? 'el operador';
        $extra    = $copias->count() > 1 ? ' (también en ' . ($copias->count() - 1) . ' copia(s) del mismo usuario)' : '';

        $api = self::apiDe($credencial);

        if (!$api) {
            return back()->with('success', "🔑 Contraseña de {$operador} guardada{$extra}.");
        }

        // Si la cuenta sigue bloqueada no se prueba: sería otro intento fallido.
        if (($rechazo = $api->rechazoVigente()) && $rechazo['tipo'] === 'bloqueo') {
            return back()->with('success', "🔑 Contraseña de {$operador} guardada{$extra}. "
                . 'La cuenta sigue bloqueada por Enlace; se usará sola desde las '
                . \Carbon\Carbon::parse($rechazo['hasta'])->format('g:i a') . '.');
        }

        $auth = $api->autenticar(forzar: true);

        if (!$auth['success']) {
            return back()->with('cred_modal_error', "Se guardó, pero {$operador} rechazó el login: " . $auth['message']);
        }

        return back()->with('success', "✅ Contraseña de {$operador} actualizada y probada{$extra}.");
    }

    // ── Credenciales por razón social (excepción) ────────────────────────

    /**
     * Guarda o actualiza la credencial de un operador para una razón social.
     */
    public function store(Request $request, int $razonSocialId)
    {
        $aliadoId = session('aliado_id_activo');

        $rs = RazonSocial::where('aliado_id', $aliadoId)->find($razonSocialId);
        abort_if(!$rs, 404);

        $validated = $request->validate([
            'operador_planilla_id'    => 'required|integer',
            'usuario'                 => 'required|string|max:100',
            'contrasena'              => 'nullable|string|max:200',
            'clave_secreta'           => 'nullable|string|max:500',
            'clave_secreta_expira_at' => 'nullable|date',
            'todas_razones'           => 'boolean',
        ], [], [
            'operador_planilla_id' => 'operador',
        ]);

        // Alcance: NULL = credencial del aliado, sirve para todos los
        // aportantes que ese usuario administre en el operador.
        $alcance = !empty($validated['todas_razones']) ? null : $rs->id;

        $operador = OperadorPlanilla::paraAliado($aliadoId)
            ->where('id', $validated['operador_planilla_id'])
            ->first();

        if (!$operador) {
            return back()->with('cred_error', '⚠️ El operador seleccionado no está activo para este aliado.');
        }

        $credencial = OperadorCredencial::where('aliado_id', $aliadoId)
            ->where('razon_social_id', $alcance)
            ->where('operador_planilla_id', $operador->id)
            ->first();

        $datos = [
            'usuario'                 => $validated['usuario'],
            'clave_secreta_expira_at' => $validated['clave_secreta_expira_at'] ?? null,
        ];

        // Los secretos solo se sobrescriben si el usuario escribió algo nuevo:
        // así puede corregir el usuario o la fecha sin volver a teclearlos.
        if (!empty($validated['contrasena'])) {
            $datos['contrasena'] = $validated['contrasena'];
        }
        if (!empty($validated['clave_secreta'])) {
            $datos['clave_secreta'] = $validated['clave_secreta'];
        }

        if ($credencial) {
            $credencial->update($datos);
        } else {
            if (empty($datos['contrasena']) || empty($datos['clave_secreta'])) {
                return back()->with('cred_error', '⚠️ La contraseña y la clave secreta son obligatorias la primera vez.');
            }

            $credencial = OperadorCredencial::create($datos + [
                'aliado_id'            => $aliadoId,
                'razon_social_id'      => $alcance,
                'operador_planilla_id' => $operador->id,
            ]);
        }

        self::apiDe($credencial)?->olvidarRechazo();

        $destino = $alcance === null
            ? 'todas las razones sociales del aliado'
            : $rs->razon_social;

        return back()->with('success', "🔑 Credenciales de {$operador->nombre} guardadas para {$destino}.");
    }

    /**
     * Prueba las credenciales guardadas: login + autorización sobre el NIT.
     */
    public function probar(int $razonSocialId, int $operadorId)
    {
        $aliadoId = session('aliado_id_activo');

        $rs = RazonSocial::where('aliado_id', $aliadoId)->find($razonSocialId);
        abort_if(!$rs, 404);

        $operador = OperadorPlanilla::paraAliado($aliadoId)->where('id', $operadorId)->first();

        if (!$operador || !SuaporteApiService::soportaOperador($operador->codigo)) {
            return back()->with('cred_error', '⚠️ Ese operador todavía no tiene integración por API.');
        }

        $credencial = OperadorCredencial::paraOperador($aliadoId, $operadorId, $rs->id)->first();

        if (!$credencial) {
            return back()->with('cred_error', '⚠️ No hay credenciales guardadas para este operador.');
        }

        $api = new SuaporteApiService([
            'operador'      => $operador->codigo, // define el host: ARUS o SIMPLE
            'usuario'       => $credencial->usuario,
            'contrasena'    => $credencial->contrasena,
            'clave_secreta' => $credencial->clave_secreta,
        ]);

        $auth = $api->autenticar(forzar: true);

        if (!$auth['success']) {
            return back()->with('cred_error', '❌ Login rechazado por ' . $operador->nombre . ': ' . $auth['message']);
        }

        if (empty($rs->nit)) {
            return back()->with('success', '✅ Login correcto. (La razón social no tiene NIT, no se probó la autorización.)');
        }

        $nit = preg_replace('/\D/', '', (string) $rs->nit);

        $aportante = $api->consultarAportante('NI', $nit);
        if (!$aportante['success']) {
            return back()->with('cred_error', "✅ Login correcto, pero el aportante NI {$nit} no se encontró: " . $aportante['message']);
        }

        $autorizacion = $api->autorizar($aportante['id'], 'NI', $nit);
        if (!$autorizacion['success']) {
            return back()->with('cred_error', "✅ Login correcto, pero sin permisos sobre {$rs->razon_social}: " . $autorizacion['message']);
        }

        return back()->with('success', "✅ Conexión correcta con {$operador->nombre}. El usuario tiene permisos sobre {$rs->razon_social} (aportante {$aportante['id']}).");
    }

    /**
     * Elimina la credencial de un operador para la razón social.
     */
    public function destroy(int $razonSocialId, int $operadorId)
    {
        $aliadoId = session('aliado_id_activo');

        $rs = RazonSocial::where('aliado_id', $aliadoId)->find($razonSocialId);
        abort_if(!$rs, 404);

        // Se borra lo mismo que se está usando: la credencial propia si existe,
        // y si no, la del aliado — que afecta a TODAS las razones sociales.
        $credencial = OperadorCredencial::paraOperador($aliadoId, $operadorId, $rs->id)->first();

        if (!$credencial) {
            return back()->with('cred_error', '⚠️ No hay credenciales para eliminar.');
        }

        $eraDelAliado = $credencial->razon_social_id === null;
        $credencial->delete();

        return back()->with('success', $eraDelAliado
            ? '🗑️ Credenciales del aliado eliminadas. Ninguna razón social podrá liquidar con ese operador.'
            : '🗑️ Credenciales eliminadas.');
    }

    /**
     * Operadores del aliado con el estado de su credencial, para pintar la
     * tarjeta del formulario de razón social.
     */
    public static function estadoPorOperador(int $aliadoId, int $razonSocialId)
    {
        // Se traen tanto las credenciales propias de la razón social como las
        // del aliado (razon_social_id NULL). La misma cuenta del operador sirve
        // para todos los aportantes que ese usuario administre, así que lo
        // normal es tener una sola credencial a nivel de aliado.
        $credenciales = OperadorCredencial::where('aliado_id', $aliadoId)
            ->where(function ($q) use ($razonSocialId) {
                $q->whereNull('razon_social_id')->orWhere('razon_social_id', $razonSocialId);
            })
            ->get();

        $propias   = $credenciales->whereNotNull('razon_social_id')->keyBy('operador_planilla_id');
        $delAliado = $credenciales->whereNull('razon_social_id')->keyBy('operador_planilla_id');

        return OperadorPlanilla::paraAliado($aliadoId)->get()->map(function ($op) use ($propias, $delAliado) {
            // La credencial específica de la razón social gana sobre la general.
            $cred     = $propias->get($op->id) ?? $delAliado->get($op->id);
            $heredada = $cred !== null && $cred->razon_social_id === null;

            return (object) [
                'id'         => $op->id,
                'nombre'     => $op->nombre,
                'codigo'     => $op->codigo,
                'tiene_api'  => SuaporteApiService::soportaOperador($op->codigo),
                'usuario'    => $cred?->usuario,
                'expira_at'  => $cred?->clave_secreta_expira_at,
                'vencida'    => $cred?->claveSecretaVencida() ?? false,
                'configurado'=> (bool) $cred,
                'heredada'   => $heredada,
            ];
        });
    }
}
