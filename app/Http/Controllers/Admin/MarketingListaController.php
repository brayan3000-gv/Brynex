<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\{MarketingBloqueado, MarketingContacto, MarketingLista};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB};
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Gestión de listas de contactos de marketing: el pool de números por aliado (con cédula,
 * nombres, departamento, ciudad, observación) y las listas nombradas que los agrupan.
 * Solo admin/superadmin del aliado.
 */
class MarketingListaController extends Controller
{
    public function index()
    {
        $alidoId = session('aliado_id_activo');

        $listas = MarketingLista::delAliado($alidoId)
            ->withCount('contactos')
            ->orderByDesc('created_at')
            ->get();

        $totalContactos = MarketingContacto::delAliado($alidoId)->count();
        $totalBloqueados = MarketingBloqueado::where('aliado_id', $alidoId)->count();

        return view('admin.marketing.listas.index', compact('listas', 'totalContactos', 'totalBloqueados'));
    }

    public function create()
    {
        $this->autorizarAdmin();

        return view('admin.marketing.listas.create');
    }

    /**
     * Crea (o reutiliza) una lista y le agrega los contactos pegados como texto y/o
     * subidos en un archivo Excel/CSV. Nunca falla por un número inválido individual —
     * se reporta en el resumen, no interrumpe la carga del resto.
     */
    public function store(Request $request)
    {
        $this->autorizarAdmin();
        $alidoId = session('aliado_id_activo');

        $validated = $request->validate([
            'nombre_lista'  => 'required|string|max:150',
            'descripcion'   => 'nullable|string|max:500',
            'numeros_texto' => 'nullable|string',
            'archivo'       => 'nullable|file|mimes:xlsx,xls,csv|max:10240',
            'clientes_vigentes' => 'nullable|boolean',
        ]);

        if (empty($validated['numeros_texto']) && !$request->hasFile('archivo') && !$request->boolean('clientes_vigentes')) {
            return back()->withErrors(['carga' => 'Pega números, sube un archivo o marca tus clientes vigentes — necesitas al menos una fuente de contactos.'])->withInput();
        }

        $lista = MarketingLista::firstOrCreate(
            ['aliado_id' => $alidoId, 'nombre' => $validated['nombre_lista']],
            ['descripcion' => $validated['descripcion'] ?? null, 'creado_por' => Auth::id()]
        );

        $filas = [];
        if (!empty($validated['numeros_texto'])) {
            $filas = array_merge($filas, $this->parsearTexto($validated['numeros_texto']));
        }
        if ($request->hasFile('archivo')) {
            $filas = array_merge($filas, $this->parsearArchivo($request->file('archivo')));
        }
        if ($request->boolean('clientes_vigentes')) {
            $filas = array_merge($filas, $this->filasClientesVigentes($alidoId));
        }

        $resumen = $this->cargarContactos($alidoId, $lista, $filas);

        return redirect()->route('admin.marketing.listas.show', $lista->id)
            ->with('ok', "Carga completa: {$resumen['nuevos']} contactos nuevos, {$resumen['actualizados']} ya existían y se actualizaron, "
                . "{$resumen['bloqueados']} bloqueados omitidos, {$resumen['invalidos']} números inválidos omitidos.");
    }

    public function show(Request $request, int $id)
    {
        $alidoId = session('aliado_id_activo');
        $lista = MarketingLista::delAliado($alidoId)->findOrFail($id);

        $query = $lista->contactos()->orderBy('nombres');

        if ($request->filled('departamento')) {
            $query->where('departamento', $request->get('departamento'));
        }
        if ($request->filled('ciudad')) {
            $query->where('ciudad', $request->get('ciudad'));
        }
        if ($request->filled('buscar')) {
            $buscar = $request->get('buscar');
            $query->where(function ($q) use ($buscar) {
                $q->whereSinTildes('nombres', $buscar)
                  ->orWhere('celular', 'like', "%{$buscar}%")
                  ->orWhere('cedula', 'like', "%{$buscar}%");
            });
        }

        $contactos = $query->paginate(50)->withQueryString();

        // Valores distintos ya cargados en ESTA lista, para poblar los filtros.
        $departamentos = $lista->contactos()->whereNotNull('departamento')->distinct()->orderBy('departamento')->pluck('departamento');
        $ciudades      = $lista->contactos()->whereNotNull('ciudad')->distinct()->orderBy('ciudad')->pluck('ciudad');

        return view('admin.marketing.listas.show', compact('lista', 'contactos', 'departamentos', 'ciudades'));
    }

    public function destroy(int $id)
    {
        $this->autorizarAdmin();
        $alidoId = session('aliado_id_activo');
        $lista = MarketingLista::delAliado($alidoId)->findOrFail($id);

        // Solo se desvincula la lista — los contactos siguen en el pool del aliado.
        $lista->contactos()->detach();
        $lista->delete();

        return redirect()->route('admin.marketing.listas.index')->with('ok', 'Lista eliminada. Los contactos siguen disponibles en el pool general.');
    }

    // ── Parseo de fuentes ─────────────────────────────────────────────

    /**
     * Texto pegado: un contacto por línea. Acepta solo el número, o el número seguido de
     * más datos separados por coma (celular,cedula,nombres,departamento,ciudad,observacion).
     */
    private function parsearTexto(string $texto): array
    {
        $filas = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($texto)) as $linea) {
            $linea = trim($linea);
            if ($linea === '') continue;

            $partes = array_map('trim', explode(',', $linea));
            $filas[] = [
                'celular'      => $partes[0] ?? '',
                'cedula'       => $partes[1] ?? null,
                'nombres'      => $partes[2] ?? null,
                'departamento' => $partes[3] ?? null,
                'ciudad'       => $partes[4] ?? null,
                'observacion'  => $partes[5] ?? null,
            ];
        }
        return $filas;
    }

    /** Excel/CSV con columnas: celular (obligatoria), cedula, nombres, departamento, ciudad, observacion. */
    private function parsearArchivo($archivo): array
    {
        $reader = IOFactory::createReaderForFile($archivo->getRealPath());
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($archivo->getRealPath());
        $data = $spreadsheet->getActiveSheet()->toArray(null, false, false, true);

        if (empty($data)) return [];

        // Detectar encabezados en la primera fila (insensible a mayúsculas/tildes básicas).
        $primeraFila = array_map(fn ($v) => mb_strtolower(trim((string) $v)), reset($data));
        $mapaColumnas = [];
        foreach ($primeraFila as $col => $encabezado) {
            $mapaColumnas[$encabezado] = $col;
        }

        $tieneEncabezados = isset($mapaColumnas['celular']);
        $colCelular      = $mapaColumnas['celular']      ?? array_key_first(reset($data));
        $colCedula       = $mapaColumnas['cedula']        ?? null;
        $colNombres      = $mapaColumnas['nombres']       ?? null;
        $colDepartamento = $mapaColumnas['departamento']  ?? null;
        $colCiudad       = $mapaColumnas['ciudad']        ?? null;
        $colObservacion  = $mapaColumnas['observacion']   ?? null;

        $filas = [];
        foreach ($data as $numFila => $fila) {
            if ($tieneEncabezados && $numFila === array_key_first($data)) continue; // saltar encabezado

            $celular = trim((string) ($fila[$colCelular] ?? ''));
            if ($celular === '') continue;

            $filas[] = [
                'celular'      => $celular,
                'cedula'       => $colCedula ? trim((string) ($fila[$colCedula] ?? '')) : null,
                'nombres'      => $colNombres ? trim((string) ($fila[$colNombres] ?? '')) : null,
                'departamento' => $colDepartamento ? trim((string) ($fila[$colDepartamento] ?? '')) : null,
                'ciudad'       => $colCiudad ? trim((string) ($fila[$colCiudad] ?? '')) : null,
                'observacion'  => $colObservacion ? trim((string) ($fila[$colObservacion] ?? '')) : null,
            ];
        }
        return $filas;
    }

    /**
     * Clientes del aliado con al menos un contrato vigente y celular registrado. Varios
     * clientes pueden compartir celular (el de la empresa o un familiar): el pool guarda
     * el número una sola vez, así que les llega un solo mensaje.
     */
    private function filasClientesVigentes(int $alidoId): array
    {
        return DB::table('clientes as cl')
            ->leftJoin('departamentos as d', 'd.id', '=', 'cl.departamento_id')
            ->leftJoin('ciudades as ci', 'ci.id', '=', 'cl.municipio_id')
            ->where('cl.aliado_id', $alidoId)
            ->whereNotNull('cl.celular')
            ->where('cl.celular', '<>', '')
            ->whereExists(fn ($q) => $q->from('contratos as c')
                ->whereColumn('c.cedula', 'cl.cedula')
                ->where('c.aliado_id', $alidoId)
                ->whereIn('c.estado', ['vigente', 'activo']))
            ->orderBy('cl.id')
            ->get(['cl.celular', 'cl.cedula', 'cl.primer_nombre', 'cl.primer_apellido', 'd.nombre as departamento', 'ci.nombre as ciudad'])
            ->map(fn ($c) => [
                'celular'      => (string) $c->celular,
                'cedula'       => $c->cedula,
                'nombres'      => mb_convert_case(trim("{$c->primer_nombre} {$c->primer_apellido}"), MB_CASE_TITLE, 'UTF-8'),
                'departamento' => $c->departamento,
                'ciudad'       => $c->ciudad,
                'observacion'  => 'Cliente vigente',
            ])
            ->all();
    }

    /**
     * Normaliza, valida, descarta bloqueados, y hace upsert de cada fila en el pool del
     * aliado — luego adjunta todo a la lista. Nunca lanza excepción por una fila mala.
     */
    private function cargarContactos(int $alidoId, MarketingLista $lista, array $filas): array
    {
        $nuevos = 0;
        $actualizados = 0;
        $bloqueados = 0;
        $invalidos = 0;
        $idsParaAdjuntar = [];

        foreach ($filas as $fila) {
            $celular = $this->normalizarNumero($fila['celular'] ?? '');
            if (!$celular) {
                $invalidos++;
                continue;
            }

            if (MarketingBloqueado::estaBloqueado($alidoId, $celular)) {
                $bloqueados++;
                continue;
            }

            $datos = array_filter([
                'cedula'       => is_numeric($fila['cedula'] ?? null) ? (int) $fila['cedula'] : null,
                'nombres'      => $fila['nombres'] ?: null,
                'departamento' => $fila['departamento'] ?: null,
                'ciudad'       => $fila['ciudad'] ?: null,
                'observacion'  => $fila['observacion'] ?: null,
            ], fn ($v) => $v !== null);

            $existente = MarketingContacto::where('aliado_id', $alidoId)->where('celular', $celular)->first();

            $contacto = MarketingContacto::updateOrCreate(
                ['aliado_id' => $alidoId, 'celular' => $celular],
                $datos
            );

            $existente ? $actualizados++ : $nuevos++;
            $idsParaAdjuntar[] = $contacto->id;
        }

        if (!empty($idsParaAdjuntar)) {
            $lista->contactos()->syncWithoutDetaching($idsParaAdjuntar);
        }

        return compact('nuevos', 'actualizados', 'bloqueados', 'invalidos');
    }

    private function normalizarNumero(string $numero): ?string
    {
        $numero = preg_replace('/[^0-9]/', '', $numero);
        if (strlen($numero) < 7) return null;
        if (!str_starts_with($numero, '57') && strlen($numero) === 10) {
            $numero = '57' . $numero;
        }
        return '+' . $numero;
    }

    private function autorizarAdmin(): void
    {
        abort_unless(Auth::user()->hasRole(['admin', 'superadmin']), 403);
    }
}
