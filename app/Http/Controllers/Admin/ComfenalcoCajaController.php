<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Contrato;
use App\Services\Caja\BeneficiariosCaja;
use App\Services\Caja\ComfenalcoCajaConciliacionService;
use App\Services\Caja\ComfenalcoCajaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Afiliación a la caja Comfenalco Valle por su Sucursal Virtual, desde el
 * radicado de caja. La extensión BryNex Portales llena el asistente; aquí se
 * arman los datos, se entrega la credencial y se registra el resultado.
 */
class ComfenalcoCajaController extends Controller
{
    public function __construct(private ComfenalcoCajaService $servicio)
    {
        $this->middleware('auth');
    }

    public function precheck(int $contratoId)
    {
        $prep = $this->servicio->preparar($this->contrato($contratoId));

        return response()->json(['ok' => ! $prep['problemas'], 'listas' => [
            'estados_civil' => ComfenalcoCajaService::ESTADOS_CIVIL,
            'contratos' => ComfenalcoCajaService::CONTRATOS,
            'formas_pago' => ComfenalcoCajaService::FORMAS_PAGO,
        ]] + $prep);
    }

    public function credencial(int $contratoId)
    {
        $cred = $this->servicio->credencial($this->contrato($contratoId));
        if (isset($cred['error'])) {
            return response()->json(['ok' => false, 'error' => $cred['error']], 422);
        }
        if (! Auth::user()->can('claves_acceso.ver_contrasena')) {
            unset($cred['contrasena']);
        }

        return response()->json(['ok' => true] + $cred)->header('Cache-Control', 'no-store');
    }

    public function aplicar(Request $request, int $contratoId)
    {
        $datos = $request->validate([
            'numero' => 'nullable|string|max:40',
            'texto' => 'nullable|string|max:20000',
            'error' => 'nullable|string|max:1000',
            'pdf' => 'nullable|string|max:12000000',   // formulario radicado, base64
        ]);

        try {
            return response()->json($this->servicio->aplicar($this->contrato($contratoId), $datos, Auth::id()));
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 422);
        }
    }

    /**
     * Guarda en BryNex los anexos que Comfenalco ya tiene del trabajador y de sus
     * beneficiarios (los baja la extensión del paso Anexos). Van al disco privado,
     * como el resto de documentos del cliente, y no se duplican.
     */
    public function documentos(Request $request, int $contratoId)
    {
        $datos = $request->validate([
            'docs' => 'required|array|max:60',
            'docs.*.requerido' => 'nullable|string|max:150',
            'docs.*.nombre' => 'required|string|max:200',
            'docs.*.doc_beneficiario' => 'nullable|string|max:20',
            'docs.*.base64' => 'required|string|max:20000000',
        ]);

        $contrato = $this->contrato($contratoId);

        return response()->json(['ok' => true] + $this->servicio->guardarDocumentos($contrato, $datos['docs'], Auth::id()));
    }

    /** Guarda en el radicado de caja el PDF del formulario recuperado del portal. */
    public function pdfRadicado(Request $request, int $contratoId)
    {
        $datos = $request->validate(['pdf' => 'required|string|max:12000000']);

        return response()->json(['ok' => $this->servicio->adjuntarPdfRadicado($this->contrato($contratoId), $datos['pdf'], Auth::id())]);
    }

    /**
     * ¿Hay firma guardada? Del trabajador y, si llegan `docs[]`, de cada beneficiario que firma
     * (el padre y la madre de la sección 3 de la declaración).
     */
    public function firma(Request $request, int $contratoId)
    {
        $contrato = $this->contrato($contratoId);
        $beneficiarios = [];
        foreach (array_filter((array) $request->query('docs', [])) as $doc) {
            $beneficiarios[(string) $doc] = (bool) $this->servicio->firmaGuardada($contrato, (string) $doc);
        }

        return response()->json(['ok' => true, 'tiene' => (bool) $this->servicio->firmaGuardada($contrato), 'beneficiarios' => $beneficiarios]);
    }

    /**
     * Estampa las firmas sobre la declaración juramentada oficial del portal: la del trabajador
     * (declarante) y, si hay padres en la sección 3, la del padre y la de la madre, cada una con su
     * documento. Las firmas nuevas (dibujadas en pantalla) se guardan para las próximas veces; si
     * no llega una, se usa la guardada. Con `previa` solo se genera el PDF para verlo: no se guarda
     * nada.
     */
    public function firmarDeclaracion(Request $request, int $contratoId)
    {
        $datos = $request->validate([
            'pdf' => 'required|string|max:20000000',
            'firma' => 'nullable|string|max:3000000',                      // compatibilidad: firma del trabajador
            'firmas' => 'nullable|array',
            'firmas.*' => 'nullable|string|max:3000000',
            'personas' => 'nullable|array',                                // rol → {doc, tipo, nombre} de quien firma además del trabajador
            'personas.*.doc' => 'required_with:personas|string|max:20',
            'personas.*.tipo' => 'nullable|string|max:5',
            'personas.*.nombre' => 'nullable|string|max:150',
            'personas.*.nombres' => 'nullable|string|max:100',
            'personas.*.apellidos' => 'nullable|string|max:100',
            'previa' => 'nullable|boolean',
        ]);
        $contrato = $this->contrato($contratoId);
        $previa = (bool) ($datos['previa'] ?? false);

        // El PDF del portal llega envuelto: {"encodedString":"<base64>"}.
        $crudo = $datos['pdf'];
        if (str_starts_with(ltrim($crudo), '{')) {
            $crudo = (string) (json_decode($crudo, true)['encodedString'] ?? '');
        }
        $pdf = base64_decode($crudo, true);
        if ($pdf === false || ! str_starts_with($pdf, '%PDF')) {
            return response()->json(['ok' => false, 'error' => 'El archivo de la declaración no es un PDF.'], 422);
        }

        $personas = array_intersect_key((array) ($datos['personas'] ?? []), array_flip(['padre', 'madre']));
        $roles = array_merge(['declarante'], array_keys($personas));
        $nuevas = (array) ($datos['firmas'] ?? []);
        if (! empty($datos['firma']) && empty($nuevas['declarante'])) {
            $nuevas['declarante'] = $datos['firma'];
        }

        $firmas = [];
        $faltan = [];
        foreach ($roles as $rol) {
            $doc = $rol === 'declarante' ? null : (string) $personas[$rol]['doc'];
            if (! empty($nuevas[$rol])) {
                $png = base64_decode(preg_replace('#^data:image/png;base64,#', '', $nuevas[$rol]), true);
                if ($png === false || ! str_starts_with($png, "\x89PNG")) {
                    return response()->json(['ok' => false, 'error' => "La firma de {$rol} no es una imagen PNG válida."], 422);
                }
                if (! $previa) {
                    $this->servicio->guardarFirma($contrato, $png, Auth::id(), $doc);
                }
                $firmas[$rol] = $png;
            } elseif ($png = $this->servicio->firmaGuardada($contrato, $doc)) {
                $firmas[$rol] = $png;
            } elseif (! $previa) {
                $faltan[] = $rol;
            }
        }
        if ($faltan) {
            return response()->json(['ok' => false, 'necesita_firma' => $faltan, 'error' => 'Faltan firmas: '.implode(', ', $faltan).'.'], 409);
        }

        // Número de documento bajo cada firma: «CC 27261598».
        $cliente = $contrato->loadMissing('cliente')->cliente;
        $docs = ['declarante' => trim(strtoupper((string) $cliente?->tipo_doc).' '.$contrato->cedula)];
        foreach ($personas as $rol => $p) {
            $docs[$rol] = trim(strtoupper((string) ($p['tipo'] ?? '')).' '.$p['doc']);
        }

        try {
            $firmado = $this->servicio->firmarDeclaracion($contrato, $pdf, $firmas, $docs, Auth::id(), $previa, $personas);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'No se pudo estampar la firma: '.$e->getMessage()], 500);
        }

        return response()->json(['ok' => true, 'pdf' => base64_encode($firmado), 'previa' => $previa, 'firmantes' => array_keys($firmas)]);
    }

    /**
     * Conciliación de los radicados de caja con "Trabajadores por Empresa" que
     * baja la extensión. BryNex concilia la empresa en todos los aliados del NIT.
     */
    public function conciliar(Request $request, ComfenalcoCajaConciliacionService $conciliacion)
    {
        $datos = $request->validate([
            'nit' => 'required|string|max:20',
            'filas' => 'required|array|max:6000',
            'filas.*' => 'array|max:8',
            'simular' => 'boolean',
        ]);
        $aliados = Auth::user()->es_brynex
            ? $conciliacion->aliadosDelNit($datos['nit'])
            : [(int) session('aliado_id_activo')];

        try {
            $r = $conciliacion->conciliar($aliados, $datos['nit'], $datos['filas'], (bool) ($datos['simular'] ?? false), Auth::id());
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'mensaje' => $e->getMessage()], 422);
        }

        $estado = $r + ['corriendo' => false, 'fin' => now()->toIso8601String(), 'mensaje' => 'Terminado.'];
        Cache::put($this->claveEstado(), $estado, now()->addDay());

        return response()->json(['ok' => true] + $estado);
    }

    /**
     * Guarda los grupos familiares que baja la extensión de la consulta
     * "Afiliación grupo familiar".
     *
     * Va aparte de la conciliación porque en Comfenalco hay que preguntar
     * trabajador por trabajador —no hay un archivo de toda la empresa como en
     * Comfandi—, así que es una pasada larga que no conviene repetir en cada
     * cruce.
     */
    public function beneficiarios(Request $request, ComfenalcoCajaConciliacionService $conciliacion, BeneficiariosCaja $beneficiarios)
    {
        $datos = $request->validate([
            'nit' => 'required|string|max:20',
            'familias' => 'required|array|max:1000',
            'familias.*' => 'array|max:20',
            'simular' => 'boolean',
        ]);

        $aliados = Auth::user()->es_brynex
            ? $conciliacion->aliadosDelNit($datos['nit'])
            : [(int) session('aliado_id_activo')];
        if (! $aliados) {
            return response()->json(['ok' => false, 'mensaje' => "El NIT {$datos['nit']} no es una razón social de este aliado."], 422);
        }

        // La extensión manda lo que ve el portal; aquí se queda solo lo que
        // tiene forma de beneficiario.
        $limpias = [];
        foreach ($datos['familias'] as $cedula => $suyos) {
            $doc = ltrim(preg_replace('/\D/', '', (string) $cedula), '0');
            if ($doc === '' || ! is_array($suyos)) {
                continue;
            }
            foreach ($suyos as $b) {
                if (! is_array($b) || empty($b['documento'])) {
                    continue;
                }
                $limpias[$doc][] = [
                    'tipo_doc' => isset($b['tipo_doc']) ? mb_substr((string) $b['tipo_doc'], 0, 5) : null,
                    'documento' => ltrim(preg_replace('/\D/', '', (string) $b['documento']), '0'),
                    'nombre' => mb_substr(trim((string) ($b['nombre'] ?? '')), 0, 150),
                    'parentesco' => isset($b['parentesco']) ? mb_substr(trim((string) $b['parentesco']), 0, 40) : null,
                    'nacimiento' => null,   // Comfenalco da la edad, no la fecha
                ];
            }
        }

        $r = $beneficiarios->guardar($aliados, $limpias, (bool) ($datos['simular'] ?? false), 'la consulta de grupo familiar de Comfenalco Valle');

        return response()->json(['ok' => true, 'trabajadores' => count($limpias)] + $r);
    }

    public function estado()
    {
        return response()->json(Cache::get($this->claveEstado()) ?? ['corriendo' => false, 'vacio' => true]);
    }

    /** La corrida consolidada de BryNex no se mezcla con la de cada aliado. */
    private function claveEstado(): string
    {
        return 'caja_comfenalco_conciliacion:estado:'.(Auth::user()->es_brynex ? 'brynex' : (int) session('aliado_id_activo'));
    }

    /** Siempre del aliado activo: el id llega por la URL. */
    private function contrato(int $id): Contrato
    {
        return Contrato::paraTramite($id);
    }
}
