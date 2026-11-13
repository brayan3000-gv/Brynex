<?php

namespace App\Http\Controllers;

use App\Models\Bitacora;
use App\Models\BrynexRepresentante;
use App\Models\BrynexRepresentanteCredencial;
use App\Models\BrynexRepresentanteDocumento;
use App\Services\BrynexRepresentanteService;
use App\Services\Correo\CorreoDianRepresentante;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Representantes legales de las razones sociales de BryNex y sus claves
 * personales. Igual que el resto del módulo, cruza aliados a propósito: la
 * persona es una sola aunque varios aliados usen sus empresas.
 *
 * Abrir la lista no escribe nada: el representante se crea en la BD la primera
 * vez que se le guarda un dato o una clave.
 */
class BrynexRepresentanteController extends Controller
{
    public function __construct(private BrynexRepresentanteService $servicio)
    {
    }

    public function index()
    {
        return view('brynex.razones_sociales.representantes', $this->servicio->agrupar());
    }

    /** Datos de la persona: nombre, tipo de documento, correo del RUT, teléfono. */
    public function guardar(Request $request)
    {
        $datos = $request->validate([
            'cedula' => 'required|string|max:20',
            'tipo_documento' => ['required', Rule::in(array_keys(BrynexRepresentante::TIPOS_DOCUMENTO))],
            'nombre' => 'required|string|max:150',
            'correo_rut' => 'nullable|email|max:150',
            'telefono' => 'nullable|string|max:30',
            'notas' => 'nullable|string|max:1000',
        ]);

        $cedula = BrynexRepresentanteService::normalizarCedula($datos['cedula']);
        if (! $cedula) {
            return back()->with('error', 'La cédula no es válida.');
        }

        $representante = BrynexRepresentante::firstOrNew(['cedula' => $cedula]);
        $nuevo = ! $representante->exists;

        $representante->fill([
            'tipo_documento' => $datos['tipo_documento'],
            'nombre' => mb_strtoupper(trim($datos['nombre'])),
            // `?? null`: un campo nullable que no viene tampoco está en el array.
            'correo_rut' => isset($datos['correo_rut']) ? mb_strtolower(trim($datos['correo_rut'])) : null,
            'telefono' => $datos['telefono'] ?? null,
            'notas' => $datos['notas'] ?? null,
            'actualizado_por' => auth()->id(),
        ]);
        if ($nuevo) {
            $representante->creado_por = auth()->id();
        }
        $representante->save();

        Bitacora::registrar(
            $nuevo ? 'created' : 'updated',
            'BrynexRepresentante', $representante->id,
            "Representante {$representante->nombre}"
        );

        return redirect()->to(route('brynex.razones.representantes.index') . '#' . BrynexRepresentanteService::ancla($representante->cedula))
            ->with('success', 'Datos del representante guardados.');
    }

    /**
     * Poner a mano el representante de una empresa, para las que no están en
     * ningún aliado o cuyo aliado no tiene la cédula. Sin cédula se quita y la
     * empresa vuelve a tomar lo que digan los aliados.
     */
    public function asignar(Request $request)
    {
        $datos = $request->validate([
            'ficha_id' => 'required|integer',
            'cedula' => 'nullable|string|max:20',
        ]);

        $ficha = \App\Models\BrynexRazonSocial::findOrFail($datos['ficha_id']);
        $cedula = BrynexRepresentanteService::normalizarCedula($datos['cedula'] ?? null);

        if ($cedula) {
            // Solo a alguien que ya está en la lista: así no queda una empresa
            // apuntando a una cédula mal escrita que nadie reconoce.
            $rep = $this->servicio->agrupar()['representantes']->firstWhere('cedula', $cedula);
            if (! $rep) {
                return back()->with('error', 'Esa cédula no está en la lista de representantes. Agrégalo primero.');
            }

            // Se guarda la persona para que la ficha de la empresa pueda
            // mostrar su nombre aunque ningún aliado la tenga anotada.
            BrynexRepresentante::firstOrCreate(
                ['cedula' => $cedula],
                [
                    'nombre' => $rep->nombre,
                    'tipo_documento' => $rep->tipo_documento,
                    'creado_por' => auth()->id(),
                    'actualizado_por' => auth()->id(),
                ]
            );
        }

        $antes = $ficha->representante_cedula;
        $ficha->update(['representante_cedula' => $cedula, 'actualizado_por' => auth()->id()]);

        Bitacora::registrar(
            'updated', 'BrynexRazonSocial', $ficha->id,
            $cedula ? "Representante de {$ficha->razon_social} puesto a mano" : "Se quitó el representante puesto a mano de {$ficha->razon_social}",
            ['antes' => $antes, 'despues' => $cedula]
        );

        return redirect()->to(route('brynex.razones.representantes.index') . ($cedula ? '#' . BrynexRepresentanteService::ancla($cedula) : ''))
            ->with('success', $cedula ? "Representante asignado a {$ficha->razon_social}." : "{$ficha->razon_social} vuelve a tomar el representante de los aliados.");
    }

    // ─── Claves ───────────────────────────────────────────────────────

    public function guardarClave(Request $request)
    {
        $datos = $request->validate([
            // Vienen por si el representante todavía no existe en la BD: se
            // crea con lo que dicen los aliados la primera vez que se le
            // guarda una clave.
            'cedula' => 'required|string|max:20',
            'nombre' => 'required|string|max:150',
            'tipo_documento' => ['required', Rule::in(array_keys(BrynexRepresentante::TIPOS_DOCUMENTO))],

            'credencial_id' => 'nullable|integer',
            'tipo' => ['required', Rule::in(array_keys(BrynexRepresentanteCredencial::TIPOS))],
            'entidad' => 'required|string|max:150',
            'usuario' => 'nullable|string|max:150',
            'contrasena' => 'nullable|string|max:200',
            'link_acceso' => 'nullable|url|max:350',
            'observacion' => 'nullable|string|max:500',
        ]);

        $cedula = BrynexRepresentanteService::normalizarCedula($datos['cedula']);
        if (! $cedula) {
            return back()->with('error', 'La cédula no es válida.');
        }

        $representante = BrynexRepresentante::firstOrCreate(
            ['cedula' => $cedula],
            [
                'nombre' => mb_strtoupper(trim($datos['nombre'])),
                'tipo_documento' => $datos['tipo_documento'],
                'creado_por' => auth()->id(),
                'actualizado_por' => auth()->id(),
            ]
        );

        $credencialId = $datos['credencial_id'] ?? null;

        $credencial = $credencialId
            ? BrynexRepresentanteCredencial::where('representante_id', $representante->id)->findOrFail($credencialId)
            : new BrynexRepresentanteCredencial([
                'representante_id' => $representante->id,
                'creado_por' => auth()->id(),
            ]);

        $credencial->fill([
            'tipo' => $datos['tipo'],
            'entidad' => $datos['entidad'],
            'usuario' => $datos['usuario'] ?? null,
            'link_acceso' => $datos['link_acceso'] ?? null,
            'observacion' => $datos['observacion'] ?? null,
            'actualizado_por' => auth()->id(),
            'activo' => true,
        ]);

        // Vacía = "no la cambies", no "bórrala": si no, editar el link borraría la clave.
        if (! empty($datos['contrasena'])) {
            $credencial->contrasena = $datos['contrasena'];
        }

        $credencial->save();

        Bitacora::registrar(
            $credencialId ? 'updated' : 'created',
            'BrynexRepresentanteCredencial', $credencial->id,
            "Clave de {$credencial->entidad} de {$representante->nombre}",
            ['cambio_contrasena' => ! empty($datos['contrasena'])]
        );

        return redirect()->to(route('brynex.razones.representantes.index') . '#' . BrynexRepresentanteService::ancla($representante->cedula))
            ->with('success', 'Clave guardada. Sirve para todas las empresas de este representante.');
    }

    // ─── Correo de la DIAN ────────────────────────────────────────────

    /**
     * Prueba que BryNex puede leer el buzón del representante y lista los
     * correos de la DIAN del último mes, SIN el enlace: un enlace vigente da
     * acceso a las empresas y solo lo entrega `enlaceDian()`, que deja rastro.
     */
    public function probarBuzon(int $id, CorreoDianRepresentante $correo)
    {
        $rep = BrynexRepresentante::findOrFail($id);

        try {
            return response()->json(['ok' => true] + $correo->revisar($rep));
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => self::errorLegible($e)], 422);
        }
    }

    /**
     * El enlace de acceso vigente que mandó la DIAN al representante. Es lo
     * que usa el robot de facturación electrónica después de pedirlo en el
     * portal; `desde` (ISO) descarta los enlaces anteriores a esa petición.
     */
    public function enlaceDian(Request $request, int $id, CorreoDianRepresentante $correo)
    {
        $rep = BrynexRepresentante::findOrFail($id);
        $desde = $request->filled('desde') ? \Illuminate\Support\Carbon::parse($request->input('desde')) : null;

        try {
            // Con `nit` se busca el enlace exacto de esa empresa: en el buzón
            // central todos los enlaces saludan al mismo correo.
            $enlace = $request->filled('nit')
                ? $correo->enlaceDeEmpresa($rep, (string) $request->input('nit'), $desde)
                : $correo->enlaceVigente($rep, $desde);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => self::errorLegible($e)], 422);
        }

        if (! $enlace) {
            return response()->json(['ok' => false, 'error' => 'No hay un enlace de la DIAN vigente en el buzón todavía.'], 404);
        }

        Bitacora::registrar(
            'enlace_dian_leido', 'BrynexRepresentante', $rep->id,
            "Se leyó el enlace de acceso de la DIAN de {$rep->nombre}",
            ['cuenta' => $enlace['cuenta'], 'fecha_correo' => $enlace['fecha'], 'ip' => request()->ip()]
        );

        return response()->json(['ok' => true] + $enlace);
    }

    /** Gmail responde en inglés y con detalles del protocolo; lo esencial en español. */
    private static function errorLegible(\Throwable $e): string
    {
        $m = $e->getMessage();

        if (stripos($m, 'AUTHENTICATIONFAILED') !== false || stripos($m, 'Invalid credentials') !== false || stripos($m, 'authenticate') !== false) {
            return 'Gmail rechazó la clave. Tiene que ser una contraseña de aplicación (16 letras) de esa cuenta, con la verificación en dos pasos activa.';
        }

        return $m;
    }

    // ─── Documentos ───────────────────────────────────────────────────

    /**
     * Cédula, RUT personal u otro documento del representante. Se guarda una
     * vez por persona y sirve para todas sus empresas. Va al disco `local`:
     * son datos personales (C-4 de la auditoría).
     */
    public function subirDocumento(Request $request)
    {
        $datos = $request->validate([
            'cedula' => 'required|string|max:20',
            'nombre' => 'required|string|max:150',
            'tipo_documento' => ['required', Rule::in(array_keys(BrynexRepresentante::TIPOS_DOCUMENTO))],
            'tipo' => ['required', Rule::in(array_keys(BrynexRepresentanteDocumento::TIPOS))],
            'observacion' => 'nullable|string|max:300',
            'archivo' => 'required|file|max:15360|mimes:pdf,jpg,jpeg,png,webp',
        ], [
            'archivo.max' => 'El archivo no puede pesar más de 15 MB.',
            'archivo.mimes' => 'Solo se aceptan PDF o imágenes.',
        ]);

        $cedula = BrynexRepresentanteService::normalizarCedula($datos['cedula']);
        if (! $cedula) {
            return back()->with('error', 'La cédula no es válida.');
        }

        $representante = BrynexRepresentante::firstOrCreate(
            ['cedula' => $cedula],
            [
                'nombre' => mb_strtoupper(trim($datos['nombre'])),
                'tipo_documento' => $datos['tipo_documento'],
                'creado_por' => auth()->id(),
                'actualizado_por' => auth()->id(),
            ]
        );

        $archivo = $request->file('archivo');
        $ruta = $archivo->store("brynex/representantes/{$cedula}", 'local');

        $documento = BrynexRepresentanteDocumento::create([
            'representante_id' => $representante->id,
            'tipo' => $datos['tipo'],
            'nombre_original' => $archivo->getClientOriginalName(),
            'ruta' => $ruta,
            'mime' => $archivo->getClientMimeType(),
            'tamano' => $archivo->getSize(),
            'observacion' => $datos['observacion'] ?? null,
            'subido_por' => auth()->id(),
        ]);

        Bitacora::registrar(
            'created', 'BrynexRepresentanteDocumento', $documento->id,
            "{$documento->tipoEtiqueta()} de {$representante->nombre}"
        );

        return redirect()->to(route('brynex.razones.representantes.index') . '#' . BrynexRepresentanteService::ancla($representante->cedula))
            ->with('success', "{$documento->tipoEtiqueta()} guardado. Sirve para todas las empresas de este representante.");
    }

    public function descargarDocumento(int $id)
    {
        $documento = BrynexRepresentanteDocumento::with('representante')->findOrFail($id);

        abort_unless(Storage::disk('local')->exists($documento->ruta), 404, 'El archivo ya no está.');

        Bitacora::registrar(
            'descarga', 'BrynexRepresentanteDocumento', $documento->id,
            "Descarga de {$documento->tipoEtiqueta()} de {$documento->representante?->nombre}",
            ['ip' => request()->ip()]
        );

        return Storage::disk('local')->download($documento->ruta, $documento->nombre_original);
    }

    public function eliminarDocumento(int $id)
    {
        $documento = BrynexRepresentanteDocumento::with('representante')->findOrFail($id);
        $cedula = $documento->representante?->cedula ?? '';
        $descripcion = "{$documento->tipoEtiqueta()} de {$documento->representante?->nombre}";

        Storage::disk('local')->delete($documento->ruta);
        $documento->delete();

        Bitacora::registrar('deleted', 'BrynexRepresentanteDocumento', $id, "{$descripcion} eliminado");

        return redirect()->to(route('brynex.razones.representantes.index') . '#' . BrynexRepresentanteService::ancla($cedula))
            ->with('success', 'Documento eliminado.');
    }

    /** Revelar una contraseña. Deja rastro en la bitácora siempre. */
    public function revelarClave(int $id)
    {
        $credencial = BrynexRepresentanteCredencial::with('representante')->findOrFail($id);

        Bitacora::registrar(
            'clave_revelada', 'BrynexRepresentanteCredencial', $credencial->id,
            "Se reveló la clave de {$credencial->entidad} ({$credencial->tipo}) de {$credencial->representante?->nombre}",
            ['representante_id' => $credencial->representante_id, 'ip' => request()->ip()]
        );

        return response()->json(['contrasena' => $credencial->contrasena]);
    }

    public function eliminarClave(int $id)
    {
        $credencial = BrynexRepresentanteCredencial::with('representante')->findOrFail($id);
        $credencial->delete();

        Bitacora::registrar(
            'deleted', 'BrynexRepresentanteCredencial', $credencial->id,
            "Clave de {$credencial->entidad} del representante eliminada"
        );

        return redirect()->to(route('brynex.razones.representantes.index') . '#' . BrynexRepresentanteService::ancla($credencial->representante?->cedula ?? ''))
            ->with('success', 'Clave eliminada.');
    }
}
