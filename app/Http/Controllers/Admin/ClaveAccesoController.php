<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClaveAcceso;
use App\Services\ClavePortalSincronizador;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ClaveAccesoController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth']);
    }

    // ─── Vista global para buscar y filtrar todas las claves ──────────
    public function vistaGlobal(Request $request)
    {
        $aliadoId = session('aliado_id_activo') ?: auth()->user()->aliado_id;

        // Se ven también las de las empresas compartidas con otro aliado: es la
        // misma clave ante la entidad y es la que usan los procesos.
        $query = ClaveAcceso::visiblesPara($aliadoId, $this->verTodas())
            ->with(['razonSocial', 'cliente', 'empresa', 'aliado']);

        // Filtro por Razón Social
        if ($request->filled('razon_social_id')) {
            $query->where('razon_social_id', $request->get('razon_social_id'));
        }

        // Filtro por Entidad
        if ($request->filled('entidad')) {
            $query->where('entidad', 'LIKE', '%' . $request->get('entidad') . '%');
        }

        // Filtro por Tipo
        if ($request->filled('tipo')) {
            $query->where('tipo', $request->get('tipo'));
        }

        // Búsqueda general (usuario, correo, etc.)
        if ($request->filled('buscar')) {
            $buscar = $request->get('buscar');
            $query->where(function($q) use ($buscar) {
                $q->where('usuario', 'LIKE', "%$buscar%")
                  ->orWhere('correo_entidad', 'LIKE', "%$buscar%")
                  ->orWhere('observacion', 'LIKE', "%$buscar%")
                  ->orWhere('entidad', 'LIKE', "%$buscar%");
            });
        }

        $claves = $query->orderBy('tipo')->orderBy('entidad')->get();

        // Obtener razones sociales para el filtro select
        $razones = \App\Models\RazonSocial::where('aliado_id', $aliadoId)
            ->orderBy('razon_social')
            ->get(['id', 'razon_social']);

        $claves = $this->taparContrasenas($claves);

        return view('admin.claves.global', compact('claves', 'razones'));
    }

    /**
     * Quita la contraseña de la colección si el usuario no tiene el permiso
     * restringido `claves_acceso.ver_contrasena`.
     *
     * Se hace en el servidor y no ocultándola en la vista porque el listado la
     * mandaba entera al navegador (en base64, que no es cifrado) y los tres
     * endpoints JSON la devolvían en claro: bastaba abrir el inspector. Tapar
     * el <span> no habría tapado nada.
     */
    private function taparContrasenas($claves)
    {
        $aliadoId = (int) (session('aliado_id_activo') ?: auth()->user()->aliado_id);

        $claves->each(function ($c) use ($aliadoId) {
            $c->de_otro_aliado = $c->esDeOtroAliado($aliadoId);
            $c->cargada_por = $c->de_otro_aliado ? ($c->aliado?->nombre ?? 'otro aliado') : null;
        });

        if (auth()->user()->can('claves_acceso.ver_contrasena')) {
            return $claves;
        }

        return $claves->each(function ($c) {
            // Se conserva si hay o no clave guardada (el usuario necesita saber
            // que existe para pedirla), pero no su contenido.
            $c->contrasena = $c->contrasena ? '__oculta__' : null;
        });
    }

    // ─── Listar claves de un cliente (por cédula) ─────────────────────
    public function index(Request $request)
    {
        $aliadoId = session('aliado_id_activo');
        $cedula   = $request->get('cedula');

        if (!$cedula) {
            return response()->json(['error' => 'Cédula requerida'], 422);
        }

        $claves = ClaveAcceso::where('aliado_id', $aliadoId)
            ->where('cedula', $cedula)
            ->orderBy('tipo')
            ->orderBy('entidad')
            ->get();

        return response()->json($this->taparContrasenas($claves));
    }

    // ─── Listar claves de una razón social ────────────────────────────
    public function indexRazonSocial(int $razonSocialId)
    {
        $aliadoId = session('aliado_id_activo');

        $claves = ClaveAcceso::visiblesPara($aliadoId, $this->verTodas())
            ->where('razon_social_id', $razonSocialId)
            ->orderBy('tipo')
            ->orderBy('entidad')
            ->get();

        return response()->json($this->taparContrasenas($claves));
    }

    // ─── Listar claves de una empresa (por empresa_id directo) ────────
    public function indexEmpresa(int $empresaId)
    {
        $aliadoId = session('aliado_id_activo');

        $claves = ClaveAcceso::visiblesPara($aliadoId, $this->verTodas())
            ->where('empresa_id', $empresaId)
            ->orderBy('tipo')
            ->orderBy('entidad')
            ->get();

        return response()->json($this->taparContrasenas($claves));
    }

    // ─── Bitácora de una clave ────────────────────────────────────────

    /**
     * Quién cambió esta clave y qué había antes.
     *
     * La contraseña anterior se tapa con el mismo permiso que la actual: sirve
     * para restaurarla, no para repartirla.
     */
    public function historial(int $id)
    {
        $aliadoId = (int) session('aliado_id_activo');

        $clave = ClaveAcceso::visiblesPara($aliadoId, $this->verTodas())->where('id', $id)->firstOrFail();

        $puedeVer = auth()->user()->can('claves_acceso.ver_contrasena');

        $cambios = $clave->cambios()->with(['usuario:id,name', 'aliado:id,nombre'])->limit(50)->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'cuando' => $c->created_at?->format('d/m/Y H:i'),
                'quien' => $c->usuario?->name ?? 'sistema',
                'aliado' => $c->aliado?->nombre,
                'resumen' => $c->resumen(),
                'usuario_anterior' => $c->usuario_anterior,
                'usuario_nuevo' => $c->usuario_nuevo,
                'contrasena_anterior' => $puedeVer ? $c->contrasena_anterior : ($c->contrasena_anterior ? '__oculta__' : null),
                'contrasena_nueva' => $puedeVer ? $c->contrasena_nueva : ($c->contrasena_nueva ? '__oculta__' : null),
            ]);

        return response()->json($cambios);
    }

    // ─── Crear nueva clave ────────────────────────────────────────────
    public function store(Request $request)
    {
        $data = $this->validar($request);
        $data['aliado_id'] = session('aliado_id_activo');

        // Empresa prestada sin permiso de claves: tampoco se le cargan. Los
        // robots usan la más reciente, así que una clave de aquí pisaría la buena.
        if (! empty($data['razon_social_id']) && ($rs = \App\Models\RazonSocial::find($data['razon_social_id']))
            && \App\Services\Afiliaciones\PortalesEntidades::clavesVedadas($rs, (int) $data['aliado_id'], auth()->user())) {
            return response()->json(['success' => false, 'message' => 'Las claves de esta empresa las maneja BryNex.'], 403);
        }

        // Limpiar nulos
        $data = $this->limpiarNulos($data);

        $clave = ClaveAcceso::create($data);

        $extra = $this->propagarClave($clave);

        return response()->json([
            'success' => true,
            'clave'   => $clave,
            'message' => 'Clave registrada correctamente.'.$extra,
        ]);
    }

    // ─── Actualizar clave ─────────────────────────────────────────────
    public function update(Request $request, int $id)
    {
        $aliadoId = session('aliado_id_activo');
        // Se puede editar lo que se ve: si la empresa es compartida, la clave
        // es la misma ante la entidad y quien la tenga a mano debe poder
        // corregirla. Quién lo hizo queda en la bitácora (ClaveAcceso::booted).
        $clave    = ClaveAcceso::visiblesPara($aliadoId, $this->verTodas())
            ->where('id', $id)
            ->firstOrFail();

        $data = $this->validar($request, $id);
        $data = $this->limpiarNulos($data);

        $clave->update($data);

        // La clave del portal de ARL Sura es del usuario, no de la empresa: se
        // deja al día en las demás razones sociales que ese mismo usuario
        // administra, y en la copia que usa la afiliación automática.
        $extra = $this->propagarClave($clave);

        return response()->json([
            'success' => true,
            'clave'   => $clave->fresh(),
            'message' => 'Clave actualizada correctamente.'.$extra,
        ]);
    }

    // ─── Eliminar (desactivar) ────────────────────────────────────────
    public function destroy(int $id)
    {
        $aliadoId = session('aliado_id_activo');
        $clave    = ClaveAcceso::where('id', $id)
            ->where('aliado_id', $aliadoId)
            ->firstOrFail();

        $clave->delete();

        return response()->json([
            'success' => true,
            'message' => 'Clave eliminada correctamente.',
        ]);
    }

    // ─── Helpers ──────────────────────────────────────────────────────

    /** Los usuarios de BryNex ven también las claves de las empresas prestadas. */
    private function verTodas(): bool
    {
        return (bool) auth()->user()?->es_brynex;
    }

    /**
     * Deja esa contraseña como la única del grupo: las demás entradas del mismo
     * usuario en el mismo portal quedan al día.
     *
     * Solo al guardar, que es cuando alguien afirma cuál es la clave buena.
     * Devuelve el aviso para el mensaje de respuesta.
     */
    private function propagarClave(ClaveAcceso $clave): string
    {
        if (! $clave->usuario || ! $clave->contrasena) {
            return '';
        }

        $r = ClavePortalSincronizador::propagar($clave);

        if ($r['filas'] <= 1) {
            return '';
        }

        $otras = $r['filas'] - 1;
        $donde = ClavePortalSincronizador::esSura($clave->entidad)
            ? ' de Sura (ARL y EPS)'
            : '';

        return " Se actualizó también en las otras {$otras} entradas{$donde} del usuario {$clave->usuario}.";
    }

    private function validar(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'cedula'           => 'nullable|integer',
            'razon_social_id'  => 'nullable|integer',
            'empresa_id'       => 'nullable|integer',
            'tipo'             => 'required|string|max:80',
            'entidad'          => 'required|string|max:150',
            'usuario'          => 'nullable|string|max:150',
            'contrasena'       => 'nullable|string|max:200',
            'link_acceso'      => 'nullable|string|max:350',
            'correo_entidad'   => 'nullable|string|max:150',
            'observacion'      => 'nullable|string|max:300',
            'activo'           => 'nullable|boolean',
        ], [
            'tipo.required'    => 'El tipo es obligatorio.',
            'entidad.required' => 'La entidad es obligatoria.',
        ]);
    }

    private function limpiarNulos(array $data): array
    {
        foreach (['cedula', 'razon_social_id', 'empresa_id', 'usuario', 'contrasena', 'link_acceso', 'correo_entidad', 'observacion'] as $campo) {
            if (isset($data[$campo]) && $data[$campo] === '') {
                $data[$campo] = null;
            }
        }
        // activo por defecto true si no se envía
        if (!isset($data['activo'])) {
            $data['activo'] = true;
        }
        return $data;
    }
}
