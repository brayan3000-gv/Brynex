<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Caja;
use App\Services\FormularioCampos;
use Illuminate\Http\Request;

/**
 * Gemelo de PensionFormularioController para las cajas de compensación.
 *
 * Nace por el formulario de afiliación de dependientes de COMFABOY, pero sirve
 * igual para cualquier caja: el PDF y su mapeo viven sobre `cajas` y en
 * Afiliaciones salen por la caja del contrato.
 */
class CajaFormularioController extends Controller
{
    /** Carpeta (dentro de storage/app) donde viven los PDF de las cajas. */
    private const DIR_PDF = 'formularios/cajas';

    public function __construct()
    {
        // El control vive en la ruta (permiso:formularios_pdf.editar, que es
        // solo_brynex). Aquí solo se exige sesión, igual que en el de EPS.
        $this->middleware('auth');
    }

    /**
     * Editor visual de mapeo para una caja de compensación.
     * GET /admin/configuracion/cajas/{caja}/formulario
     */
    public function editor(Caja $caja)
    {
        $campos = FormularioCampos::disponibles();
        $mapeados = $caja->formulario_campos ?? [];

        $lista = Caja::orderBy('nombre')->get(['id', 'nombre', 'formulario_pdf']);

        return view('admin.configuracion.formulario_mapeo', [
            'entidad' => $caja,
            'lista' => $lista,
            'campos' => $campos,
            'mapeados' => $mapeados,
            'titulo' => 'Formularios Caja',
            'subtitulo' => 'la caja de compensación',
            'urlBase' => url('/admin/configuracion/cajas'),
            'urlSubirPdf' => route('admin.configuracion.cajas.formulario.pdf', $caja),
            'urlGuardar' => route('admin.configuracion.cajas.formulario.guardar', $caja),
            'urlVerPdf' => route('admin.configuracion.cajas.formulario.vpdf', $caja),
        ]);
    }

    /**
     * Sirve el PDF de la caja para verlo en el editor (fuera de public/).
     * GET /admin/configuracion/cajas/{caja}/formulario/pdf
     */
    public function verPdf(Caja $caja)
    {
        if (! $caja->formulario_pdf) {
            abort(404, 'No hay PDF configurado para esta caja de compensación.');
        }
        $ruta = storage_path('app/'.self::DIR_PDF.'/'.$caja->formulario_pdf);
        if (! file_exists($ruta)) {
            abort(404, 'Archivo PDF no encontrado.');
        }

        return response()->file($ruta, ['Content-Type' => 'application/pdf']);
    }

    /**
     * Guarda el mapeo de campos.
     * POST /admin/configuracion/cajas/{caja}/formulario
     */
    public function guardar(Request $request, Caja $caja)
    {
        $campos = json_decode($request->input('formulario_campos', '[]'), true) ?? [];
        $caja->update(['formulario_campos' => $campos]);

        return back()->with('success', 'Mapeo guardado correctamente para '.$caja->nombre);
    }

    /**
     * Sube el PDF del formulario de la caja.
     * POST /admin/configuracion/cajas/{caja}/formulario/pdf
     */
    public function subirPdf(Request $request, Caja $caja)
    {
        $request->validate(['pdf' => 'required|file|mimes:pdf|max:20480']);

        $archivo = $request->file('pdf');
        $nombre = strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', $caja->nombre)).'.pdf';
        $archivo->storeAs(self::DIR_PDF, $nombre, 'local');

        $caja->update(['formulario_pdf' => $nombre]);

        return back()->with('success', 'PDF subido correctamente: '.$nombre);
    }
}
