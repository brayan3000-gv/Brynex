<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bitacora;
use App\Models\CartaLaboral;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\RazonSocial;
use App\Services\CartaLaboralService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Módulo Cartas laborales: se busca a la persona por cédula, se escoge el
 * contrato y se genera la carta (caja de compensación o certificación) en PDF
 * o Word, con el logo y el sello de la razón social y la firma de quien la
 * suscribe. Todo filtrado por el aliado activo.
 *
 * Permiso: contratos.carta_laboral (admin; a un usuario se le da a mano).
 */
class CartaLaboralController extends Controller
{
    public function __construct(private CartaLaboralService $servicio) {}

    private function aliadoId(): int
    {
        return (int) session('aliado_id_activo');
    }

    public function index()
    {
        $aliadoId = $this->aliadoId();

        $historial = CartaLaboral::where('aliado_id', $aliadoId)
            ->with('usuario:id,nombre')
            ->latest('id')
            ->limit(40)
            ->get();

        // Cargo y celular con que firmó cada usuario la última vez (keyBy deja la última por llave).
        $ultimas = CartaLaboral::where('aliado_id', $aliadoId)->whereNotNull('firmante_user_id')
            ->orderBy('id')->get(['firmante_user_id', 'firmante_cargo', 'datos'])->keyBy('firmante_user_id');

        return view('admin.cartas_laborales.index', [
            'historial' => $historial,
            'usuarios' => $this->servicio->usuariosFirmantes($aliadoId)->map(fn ($u) => [
                'id' => $u->id,
                'nombre' => $u->nombre,
                'cedula' => $u->cedula,
                'tiene_firma' => $u->cedula && file_exists(CartaLaboralService::rutaFirma($u->cedula)),
                'cargo' => $ultimas[$u->id]->firmante_cargo ?? '',
                'celular' => ($ultimas[$u->id]->datos['firmante']['celular'] ?? '') ?: (string) $u->telefono,
            ])->values(),
            'cargos' => CartaLaboralService::CARGOS_FIRMANTE,
            'cedulaInicial' => request('cedula'),
        ]);
    }

    /** Cliente y contratos de una cédula, en el aliado activo. */
    public function buscar(Request $request)
    {
        $cedula = trim((string) $request->query('cedula'));
        abort_if($cedula === '', 422, 'Escribe una cédula.');
        $aliadoId = $this->aliadoId();

        $cliente = Cliente::where('aliado_id', $aliadoId)->where('cedula', $cedula)->first();

        $contratos = Contrato::where('aliado_id', $aliadoId)
            ->where('cedula', $cedula)
            ->with(['razonSocial:id,razon_social', 'tipoModalidad:id,tipo_modalidad,observacion'])
            ->orderByRaw("CASE WHEN estado = 'vigente' THEN 0 ELSE 1 END")
            ->orderByDesc('fecha_ingreso')
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'estado' => $c->estado,
                'empresa' => $c->razonSocial?->razon_social ?? '— sin razón social —',
                'modalidad' => $c->tipoModalidad?->observacion ?? $c->tipoModalidad?->tipo_modalidad,
                'cargo' => $c->cargo,
                'fecha_ingreso' => $c->fecha_ingreso ? \Carbon\Carbon::parse($c->fecha_ingreso)->format('d/m/Y') : null,
                'fecha_retiro' => $c->fecha_retiro ? \Carbon\Carbon::parse($c->fecha_retiro)->format('d/m/Y') : null,
                'salario' => (int) round((float) $c->salario),
                'tiene_razon_social' => (bool) $c->razon_social_id,
            ]);

        return response()->json([
            'cliente' => $cliente ? ['nombre' => $cliente->nombre_completo, 'tipo_doc' => $cliente->tipo_doc] : null,
            'contratos' => $contratos,
        ]);
    }

    /** Valores iniciales del formulario para un contrato. */
    public function contrato(int $id)
    {
        $contrato = Contrato::where('aliado_id', $this->aliadoId())->findOrFail($id);

        return response()->json($this->servicio->prefill($contrato, $this->aliadoId()));
    }

    /** Avisos en vivo mientras se llena el formulario. */
    public function revisar(Request $request)
    {
        $datos = $this->servicio->resolver($this->validar($request), $this->aliadoId());

        return response()->json([
            'avisos' => $this->servicio->avisos($datos),
            'jornada_sugerida' => $this->servicio->jornadaSugerida($datos['dias_mes'], $datos['horas_mes']),
        ]);
    }

    /**
     * Genera la carta. `vista_previa` la muestra en el navegador sin dejarla
     * en el historial; descargarla sí queda registrada.
     */
    public function generar(Request $request)
    {
        $request->validate(['formato' => 'required|in:pdf,word']);
        $datos = $this->servicio->resolver($this->validar($request), $this->aliadoId());

        if ($request->boolean('vista_previa')) {
            return $this->servicio->pdf($datos)->stream($this->servicio->nombreArchivo($datos, 'pdf'));
        }

        $carta = CartaLaboral::create([
            'aliado_id' => $this->aliadoId(),
            'contrato_id' => $datos['contrato_id'],
            'razon_social_id' => $datos['razon_social_id'],
            'cedula' => $datos['trabajador']['documento'],
            'destino' => $datos['destino'],
            'firmante_tipo' => $datos['firmante']['tipo'],
            'firmante_user_id' => $datos['firmante']['user_id'],
            'firmante_nombre' => $datos['firmante']['nombre'],
            'firmante_cargo' => $datos['firmante']['cargo'],
            'datos' => $datos,
            'user_id' => Auth::id(),
        ]);

        Bitacora::registrar(
            'created', 'CartaLaboral', $carta->id,
            'Carta laboral '.($datos['destino'] === 'caja' ? 'para la caja' : 'de certificación')
                ." de {$datos['trabajador']['nombre']} (Cédula: {$datos['trabajador']['documento']}) en {$datos['empresa']['nombre']}, firma {$datos['firmante']['nombre']}.",
            ['contrato_id' => $datos['contrato_id'], 'formato' => $request->input('formato')],
            $this->aliadoId()
        );

        return $this->descargarDatos($datos, $request->input('formato'));
    }

    /** Vuelve a descargar una carta del historial con el mismo texto. */
    public function descargar(Request $request, int $id)
    {
        $carta = CartaLaboral::where('aliado_id', $this->aliadoId())->findOrFail($id);

        return $this->descargarDatos($carta->datos, $request->query('formato') === 'word' ? 'word' : 'pdf');
    }

    private function descargarDatos(array $datos, string $formato)
    {
        if ($formato === 'word') {
            $ruta = $this->servicio->word($datos);

            return response()->download($ruta, $this->servicio->nombreArchivo($datos, 'docx'))->deleteFileAfterSend();
        }

        return $this->servicio->pdf($datos)->download($this->servicio->nombreArchivo($datos, 'pdf'));
    }

    /**
     * Guarda la firma de quien firma: el representante legal de una razón
     * social del aliado o un usuario del aliado. Nadie más.
     */
    public function guardarFirma(Request $request)
    {
        $request->validate([
            'cedula' => 'required|string|max:20',
            'firma' => 'nullable|file|mimes:png,jpg,jpeg,webp|max:5120',
            'firma_dibujo' => 'nullable|string',
        ]);
        abort_if(! $request->hasFile('firma') && ! $request->filled('firma_dibujo'), 422, 'Sube o dibuja la firma.');

        $aliadoId = $this->aliadoId();
        $cedula = $request->input('cedula');

        $esRepresentante = RazonSocial::where('aliado_id', $aliadoId)->where('cedula_rep', $cedula)->exists();
        $esUsuario = $this->servicio->usuariosFirmantes($aliadoId)->contains('cedula', $cedula);
        abort_unless($esRepresentante || $esUsuario, 403, 'Esa cédula no es de un representante ni de un usuario de este aliado.');

        CartaLaboralService::guardarPng($request->file('firma') ?? $request->input('firma_dibujo'), CartaLaboralService::rutaFirma($cedula));

        Bitacora::registrar('updated', 'FirmaCartaLaboral', null, "Firma guardada para cartas laborales (Cédula: {$cedula}).", null, $aliadoId);

        return response()->json(['ok' => true]);
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'contrato_id' => 'required|integer',
            'destino' => 'required|in:caja,certificacion',
            'trabajador_nombre' => 'required|string|max:150',
            'trabajador_tipo_doc' => 'required|string|max:4',
            'trabajador_genero' => 'required|in:F,M',
            'cargo' => 'nullable|string|max:120',
            'fecha_ingreso' => 'required|date',
            'fecha_retiro' => 'nullable|date|after_or_equal:fecha_ingreso',
            'salario' => 'required|integer|min:0',
            'auxilio_transporte' => 'nullable|integer|min:0',
            'tipo_contrato' => 'nullable|in:indefinido,fijo,obra,aprendizaje',
            'incluir_salario' => 'nullable|boolean',
            'incluir_jornada' => 'nullable|boolean',
            'dias_mes' => 'nullable|integer|min:1|max:30',
            'horas_mes' => 'nullable|integer|min:0|max:400',
            'jornada' => 'nullable|string|max:600',
            'anio' => 'required|integer|min:2000|max:2100',
            'caja_nombre' => 'nullable|required_if:destino,caja|string|max:120',
            'ciudad' => 'required|string|max:80',
            'fecha' => 'required|date',
            'firmante_tipo' => 'required|in:representante,usuario',
            'firmante_user_id' => 'nullable|required_if:firmante_tipo,usuario|integer',
            'firmante_cargo' => 'nullable|required_if:firmante_tipo,usuario|string|max:120',
            'firmante_celular' => 'nullable|string|max:30',
            'empresa_direccion' => 'nullable|string|max:200',
            'empresa_telefono' => 'nullable|string|max:80',
            'empresa_correo' => 'nullable|string|max:120',
        ], [
            'caja_nombre.required_if' => 'Escribe el nombre de la caja de compensación.',
            'firmante_user_id.required_if' => 'Escoge el usuario que firma.',
            'firmante_cargo.required_if' => 'Escoge o escribe el cargo de quien firma.',
        ]);
    }
}
