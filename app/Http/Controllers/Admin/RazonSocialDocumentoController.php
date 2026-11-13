<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bitacora;
use App\Models\DocumentoCliente;
use App\Services\BrynexRepresentanteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Controlador para la gestión de documentos de Razones Sociales.
 * Reutiliza la tabla 'documentos_cliente' guardando el NIT de la empresa en la columna 'cc_cliente'.
 */
class RazonSocialDocumentoController extends Controller
{
    public function __construct()
    {
        $this->middleware(['auth']);
    }

    /**
     * Página solo de documentos, para quien los sube sin poder editar los
     * datos de la empresa. Quien sí puede editarlos los tiene además en la
     * pestaña Archivos de la ficha.
     */
    public function index(int $id)
    {
        $rs = DB::table('razones_sociales')
            ->where('id', $id)
            ->where('aliado_id', session('aliado_id_activo'))
            ->first();

        abort_if(!$rs, 404, 'Razón Social no encontrada.');

        return view('admin.razones_sociales.documentos', compact('rs'));
    }

    /**
     * Subir un documento para la Razón Social.
     */
    public function store(Request $request, int $id)
    {
        $aliadoId = session('aliado_id_activo');

        // Validar existencia de la Razón Social
        $rs = DB::table('razones_sociales')
            ->where('id', $id)
            ->where('aliado_id', $aliadoId)
            ->first();

        abort_if(!$rs, 404, 'Razón Social no encontrada.');

        // Validar el request de subida
        $request->validate([
            'archivo'             => 'required|file|max:15360|mimes:pdf,jpg,jpeg,png,webp,zip,rar,doc,docx,xls,xlsx',
            'tipo_documento'      => 'required|string|max:100',
            'tipo_personalizado'  => 'nullable|string|max:100',
        ], [
            'archivo.required'    => 'Por favor, selecciona un archivo para subir.',
            'archivo.max'         => 'El archivo no puede pesar más de 15 MB.',
            'archivo.mimes'       => 'Formato de archivo no permitido. Solo se permiten PDFs, imágenes y comprimidos.',
            'tipo_documento.required' => 'El tipo de documento es obligatorio.',
        ]);

        $archivo = $request->file('archivo');

        // Calcular el nombre legible y clave del tipo de documento
        $tipoDocumento = $request->tipo_documento;
        if ($tipoDocumento === 'Otro') {
            $tipoDocumento = trim($request->tipo_personalizado);
            if (empty($tipoDocumento)) {
                $tipoDocumento = 'Documento Adjunto';
            }
        }

        // Sanitizar el nombre del tipo para el nombre físico del archivo
        $slugTipo = Str::slug($tipoDocumento, '_');

        // NIT como identificador para cc_cliente (si no tiene, usar el ID interno)
        $nit = $rs->nit ?? $rs->id;

        // Nombre de archivo único: slug_timestamp_random.ext
        $nombreUnico = $slugTipo . '_' . time() . '_' . Str::random(6) . '.' . $archivo->getClientOriginalExtension();
        $ruta = "documentos_razones/{$aliadoId}/{$nit}/{$nombreUnico}";

        // Almacenar en disco local privado
        Storage::disk('local')->put($ruta, file_get_contents($archivo->getRealPath()));

        // Un solo documento por tipo: el que existía de ese tipo se reemplaza.
        // Se busca antes de crear el nuevo para no borrarlo a él mismo.
        $anteriores = DocumentoCliente::where('aliado_id', $aliadoId)
            ->where('cc_cliente', $nit)
            ->whereNull('doc_beneficiario')
            ->where('tipo_documento', $tipoDocumento)
            ->get();

        // Crear registro en la tabla de documentos
        $nuevo = DocumentoCliente::create([
            'aliado_id'        => $aliadoId,
            'cc_cliente'       => $nit,
            'doc_beneficiario' => null, // null indica que es titular (en este caso la razón social)
            'tipo_documento'   => $tipoDocumento,
            'nombre_archivo'   => $archivo->getClientOriginalName(),
            'ruta'             => $ruta,
            'subido_por'       => auth()->id(),
        ]);

        foreach ($anteriores as $anterior) {
            Storage::disk('local')->delete($anterior->ruta);
            $anterior->delete();
        }

        if ($anteriores->isNotEmpty()) {
            Bitacora::registrar(
                'updated', 'DocumentoCliente', $nuevo->id,
                "«{$tipoDocumento}» de {$rs->razon_social} reemplazado",
                ['reemplazados' => $anteriores->pluck('nombre_archivo')->all()]
            );
        }

        return back()->with('success', $anteriores->isNotEmpty()
            ? "✅ {$tipoDocumento} actualizado: reemplazó al que había."
            : '✅ Documento subido y asociado correctamente.');
    }

    /**
     * Descargar la cédula o el RUT personal del representante legal, que se
     * guardan una sola vez por persona en Representantes (BryNex) y no en
     * cada razón social. Solo si la empresa está ligada a una ficha de BryNex
     * cuyo representante es esa persona (ver `representanteDeRazonSocial`).
     */
    public function downloadRepresentante(int $id, int $documentoId, BrynexRepresentanteService $representantes)
    {
        $rs = DB::table('razones_sociales')
            ->where('id', $id)
            ->where('aliado_id', session('aliado_id_activo'))
            ->first();

        abort_if(! $rs, 404, 'Razón Social no encontrada.');

        $representante = $representantes->representanteDeRazonSocial($id);
        abort_if(! $representante, 404, 'Esta razón social no tiene representante en BryNex.');

        $documento = $representante->documentos->firstWhere('id', $documentoId);
        abort_if(! $documento || ! Storage::disk('local')->exists($documento->ruta), 404, 'El archivo ya no está.');

        Bitacora::registrar(
            'descarga', 'BrynexRepresentanteDocumento', $documento->id,
            "Descarga de {$documento->tipoEtiqueta()} de {$representante->nombre} desde {$rs->razon_social}",
            ['aliado_id' => session('aliado_id_activo'), 'razon_social_id' => $id, 'ip' => request()->ip()]
        );

        return Storage::disk('local')->download($documento->ruta, $documento->nombre_original);
    }

    /**
     * Descargar de forma segura un documento.
     */
    public function download(int $id)
    {
        $aliadoId = session('aliado_id_activo');

        // Buscar el documento y verificar que pertenezca al aliado actual
        $doc = DocumentoCliente::where('aliado_id', $aliadoId)->findOrFail($id);

        if (!Storage::disk('local')->exists($doc->ruta)) {
            abort(404, 'El archivo físico no existe en el servidor.');
        }

        return Storage::disk('local')->download($doc->ruta, $doc->nombre_archivo);
    }

    /**
     * Eliminar físicamente y lógicamente un documento.
     */
    public function destroy(int $id)
    {
        $aliadoId = session('aliado_id_activo');

        // Buscar documento y validar pertenencia al aliado
        $doc = DocumentoCliente::where('aliado_id', $aliadoId)->findOrFail($id);

        // Eliminar archivo físico
        if (Storage::disk('local')->exists($doc->ruta)) {
            Storage::disk('local')->delete($doc->ruta);
        }

        // Eliminar registro de base de datos
        $doc->delete();

        return back()->with('success', '🗑️ Documento eliminado correctamente.');
    }
}
