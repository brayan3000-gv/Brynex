<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\WhatsappEnvioMasivoJob;
use App\Models\{
    ConfiguracionAliado, MarketingCampana, MarketingContacto, MarketingLista,
    WhatsappConfig, WhatsappEnvioMasivo, WhatsappEnvioMasivoDetalle, WhatsappPlantilla
};
use App\Services\WhatsappBandejaCompartida;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth, DB};

/**
 * Campañas de marketing y lanzamiento de tandas de envío. Reusa el motor existente de
 * envío masivo (WhatsappEnvioMasivo + WhatsappEnvioMasivoJob) vía campana_id — nunca se
 * mezcla con los envíos de cobro (ver whereNull('campana_id') en WhatsappMasivoController
 * e InformeController).
 */
class MarketingCampanaController extends Controller
{
    public function index()
    {
        $alidoId = session('aliado_id_activo');

        $campanas = MarketingCampana::delAliado($alidoId)
            ->with('plantilla:id,nombre_display')
            ->withCount('envios')
            ->addSelect(['mensajes_enviados' => WhatsappEnvioMasivoDetalle::selectRaw('COUNT(*)')
                ->join('whatsapp_envios_masivos as e', 'e.id', '=', 'whatsapp_envios_masivos_detalle.envio_id')
                ->whereColumn('e.campana_id', 'marketing_campanas.id')
                ->whereIn('whatsapp_envios_masivos_detalle.estado', ['enviado', 'entregado', 'leido']),
            ])
            ->orderByDesc('created_at')
            ->get();

        return view('admin.marketing.campanas.index', compact('campanas'));
    }

    public function create()
    {
        $this->autorizarAdmin();
        $alidoId = session('aliado_id_activo');

        $plantillas = WhatsappPlantilla::delAliado($this->aliadoDuenoPlantillas($alidoId))->aprobadas()->deMarketing()->orderBy('nombre_display')->get();

        return view('admin.marketing.campanas.create', compact('plantillas'));
    }

    public function store(Request $request)
    {
        $this->autorizarAdmin();
        $alidoId = session('aliado_id_activo');

        $validated = $request->validate([
            'plantilla_id'               => 'required|integer|exists:whatsapp_plantillas,id',
            'nombre'                     => 'required|string|max:150',
            'descripcion_ia'             => 'required|string',
            'objetivo'                   => 'nullable|string|max:500',
            'boton_texto'                => 'nullable|array',
            'boton_instruccion'          => 'nullable|array',
            'incluir_clientes_vigentes'  => 'nullable|boolean',
        ]);

        $plantilla = WhatsappPlantilla::delAliado($this->aliadoDuenoPlantillas($alidoId))->aprobadas()->deMarketing()->findOrFail($validated['plantilla_id']);

        $guiaBotones = [];
        foreach ($validated['boton_texto'] ?? [] as $i => $texto) {
            $texto = trim($texto);
            $instruccion = trim($validated['boton_instruccion'][$i] ?? '');
            if ($texto !== '' && $instruccion !== '') {
                $guiaBotones[$texto] = $instruccion;
            }
        }

        $campana = MarketingCampana::create([
            'aliado_id'                  => $alidoId,
            'plantilla_id'               => $plantilla->id,
            'nombre'                     => $validated['nombre'],
            'descripcion_ia'             => $validated['descripcion_ia'],
            'objetivo'                   => $validated['objetivo'] ?? null,
            'guia_botones'               => $guiaBotones,
            'incluir_clientes_vigentes'  => $request->boolean('incluir_clientes_vigentes'),
            'estado'                     => 'activa',
            'creado_por'                 => Auth::id(),
        ]);

        return redirect()->route('admin.marketing.campanas.show', $campana->id)->with('ok', 'Campaña creada.');
    }

    public function show(int $id)
    {
        $alidoId = session('aliado_id_activo');
        $campana = MarketingCampana::delAliado($alidoId)->with('plantilla')->findOrFail($id);

        $listas = MarketingLista::delAliado($alidoId)->withCount('contactos')->orderBy('nombre')->get();

        $tandas = WhatsappEnvioMasivo::where('campana_id', $campana->id)
            ->orderByDesc('created_at')
            ->get();

        // Filtros disponibles para el lanzamiento de tanda — valores reales ya cargados
        // en el pool del aliado, para que el select no dependa de texto libre con errores.
        $departamentos = MarketingContacto::delAliado($alidoId)->whereNotNull('departamento')->distinct()->orderBy('departamento')->pluck('departamento');
        $ciudades      = MarketingContacto::delAliado($alidoId)->whereNotNull('ciudad')->distinct()->orderBy('ciudad')->pluck('ciudad');

        $metricas = $campana->metricas();

        return view('admin.marketing.campanas.show', compact('campana', 'listas', 'tandas', 'departamentos', 'ciudades', 'metricas'));
    }

    public function update(Request $request, int $id)
    {
        $this->autorizarAdmin();
        $alidoId = session('aliado_id_activo');
        $campana = MarketingCampana::delAliado($alidoId)->findOrFail($id);

        $validated = $request->validate([
            'estado' => 'required|in:activa,pausada,finalizada',
        ]);

        $campana->update($validated);

        return back()->with('ok', 'Campaña actualizada: ' . $campana->etiquetaEstado());
    }

    /**
     * Previsualiza cuántos contactos son elegibles para una tanda ANTES de lanzarla, con el
     * desglose de exclusiones (bloqueados, ya recibieron esta campaña, superan el límite de
     * frecuencia, ya son clientes vigentes).
     */
    public function previsualizar(Request $request, int $id)
    {
        $alidoId = session('aliado_id_activo');
        $campana = MarketingCampana::delAliado($alidoId)->findOrFail($id);

        $filtros = $request->only(['lista_id', 'departamento', 'ciudad', 'observacion']);

        $baseQuery = $this->queryBaseFiltrada($alidoId, $filtros);
        $totalEnFiltro = (clone $baseQuery)->count();

        $celularesBloqueados     = $this->celularesBloqueados($alidoId);
        $celularesYaEnviados     = $this->celularesYaEnviadosEnCampana($campana);
        $celularesSuperanLimite  = $this->celularesQueSuperanLimite($alidoId);
        $celularesClientes       = $campana->incluir_clientes_vigentes ? collect() : $this->celularesClientesVigentes($alidoId);

        $excluidos = $celularesBloqueados->merge($celularesYaEnviados)->merge($celularesSuperanLimite)->merge($celularesClientes)->unique();

        $elegibles = (clone $baseQuery)->whereNotIn('celular', $excluidos)->count();

        return response()->json([
            'total_en_filtro'         => $totalEnFiltro,
            'bloqueados'              => (clone $baseQuery)->whereIn('celular', $celularesBloqueados)->count(),
            'ya_recibieron_campana'   => (clone $baseQuery)->whereIn('celular', $celularesYaEnviados)->count(),
            'superan_limite'          => (clone $baseQuery)->whereIn('celular', $celularesSuperanLimite)->count(),
            'clientes_vigentes'       => (clone $baseQuery)->whereIn('celular', $celularesClientes)->count(),
            'elegibles'               => $elegibles,
        ]);
    }

    /**
     * Lanza una tanda: toma los primeros N elegibles según los filtros, crea el envío
     * masivo + detalle, y despacha el job de envío existente (sin parámetros globales, para
     * que use variables_mapa de la plantilla y personalice el nombre por destinatario).
     */
    public function lanzarTanda(Request $request, int $id)
    {
        $this->autorizarAdmin();
        $alidoId = session('aliado_id_activo');
        $campana = MarketingCampana::delAliado($alidoId)->with('plantilla')->findOrFail($id);

        if ($campana->estado !== 'activa') {
            return response()->json(['ok' => false, 'error' => 'Esta campaña no está activa.'], 422);
        }

        $validated = $request->validate([
            'cantidad'     => 'required|integer|min:1',
            'lista_id'     => 'nullable|integer|exists:marketing_listas,id',
            'departamento' => 'nullable|string',
            'ciudad'       => 'nullable|string',
            'observacion'  => 'nullable|string',
        ]);

        $config = WhatsappConfig::paraAliado($alidoId);
        if (!$config->credencialesCompletas()) {
            return response()->json(['ok' => false, 'error' => 'No hay credenciales de WhatsApp configuradas.'], 422);
        }

        $filtros = $request->only(['lista_id', 'departamento', 'ciudad', 'observacion']);
        $baseQuery = $this->queryBaseFiltrada($alidoId, $filtros);

        $excluidos = $this->celularesBloqueados($alidoId)
            ->merge($this->celularesYaEnviadosEnCampana($campana))
            ->merge($this->celularesQueSuperanLimite($alidoId))
            ->merge($campana->incluir_clientes_vigentes ? collect() : $this->celularesClientesVigentes($alidoId))
            ->unique();

        $contactos = (clone $baseQuery)
            ->whereNotIn('celular', $excluidos)
            ->orderBy('id')
            ->take($validated['cantidad'])
            ->get();

        if ($contactos->isEmpty()) {
            return response()->json(['ok' => false, 'error' => 'No hay contactos elegibles con estos filtros.'], 422);
        }

        $envio = WhatsappEnvioMasivo::create([
            'aliado_id'           => $alidoId,
            'plantilla_id'        => $campana->plantilla_id,
            'campana_id'          => $campana->id,
            'usuario_id'          => Auth::id(),
            'mes'                 => now()->month,
            'anio'                => now()->year,
            'tipo_envio'          => 'marketing',
            'total_destinatarios' => $contactos->count(),
            'estado'              => 'pendiente',
        ]);

        foreach ($contactos as $contacto) {
            WhatsappEnvioMasivoDetalle::create([
                'envio_id'            => $envio->id,
                'contrato_id'         => null,
                'empresa_id'          => null,
                'wa_numero'           => $contacto->celular,
                'nombre_destinatario' => $contacto->nombres ?: 'cliente',
                'estado'              => 'pendiente',
            ]);
        }

        // Historial del pool — para el límite de frecuencia y visibilidad en la lista.
        MarketingContacto::whereIn('id', $contactos->pluck('id'))->update([
            'veces_contactado'  => DB::raw('veces_contactado + 1'),
            'ultima_campana_at' => now(),
        ]);

        // Sin parámetros globales: el job usa variables_mapa de la plantilla (personaliza
        // {{1}} con el nombre de cada destinatario) en vez de repetir un valor fijo para todos.
        dispatch(new WhatsappEnvioMasivoJob($envio->id));

        return response()->json([
            'ok'       => true,
            'mensaje'  => "Tanda lanzada: {$envio->total_destinatarios} mensajes en proceso.",
            'envio_id' => $envio->id,
        ]);
    }

    // ── Elegibilidad ────────────────────────────────────────────────

    private function queryBaseFiltrada(int $alidoId, array $filtros)
    {
        $query = MarketingContacto::where('aliado_id', $alidoId);

        if (!empty($filtros['lista_id'])) {
            $query->whereHas('listas', fn ($q) => $q->where('marketing_listas.id', $filtros['lista_id']));
        }
        if (!empty($filtros['departamento'])) {
            $query->where('departamento', $filtros['departamento']);
        }
        if (!empty($filtros['ciudad'])) {
            $query->where('ciudad', $filtros['ciudad']);
        }
        if (!empty($filtros['observacion'])) {
            $query->where('observacion', 'like', '%' . $filtros['observacion'] . '%');
        }

        return $query;
    }

    private function celularesBloqueados(int $alidoId)
    {
        return DB::table('marketing_bloqueados')->where('aliado_id', $alidoId)->pluck('celular');
    }

    private function celularesYaEnviadosEnCampana(MarketingCampana $campana)
    {
        return DB::table('whatsapp_envios_masivos_detalle as d')
            ->join('whatsapp_envios_masivos as e', 'e.id', '=', 'd.envio_id')
            ->where('e.campana_id', $campana->id)
            ->where('d.estado', 'enviado')
            ->pluck('d.wa_numero');
    }

    /** Números que ya alcanzaron el máximo de campañas distintas configurado por el aliado en el período. */
    private function celularesQueSuperanLimite(int $alidoId)
    {
        $config = ConfiguracionAliado::paraAliado($alidoId);
        if (!$config || !$config->marketing_max_campanas) {
            return collect();
        }

        $dias = $config->marketing_dias_periodo ?? 30;

        return DB::table('whatsapp_envios_masivos_detalle as d')
            ->join('whatsapp_envios_masivos as e', 'e.id', '=', 'd.envio_id')
            ->where('e.aliado_id', $alidoId)
            ->whereNotNull('e.campana_id')
            ->where('d.estado', 'enviado')
            ->where('d.created_at', '>=', now()->subDays($dias))
            ->groupBy('d.wa_numero')
            ->havingRaw('COUNT(DISTINCT e.campana_id) >= ?', [$config->marketing_max_campanas])
            ->pluck('d.wa_numero');
    }

    /** Números de clientes con contrato vigente del aliado — excluidos salvo que la campaña los incluya. */
    private function celularesClientesVigentes(int $alidoId)
    {
        return DB::table('contratos as c')
            ->join('clientes as cl', function ($j) use ($alidoId) {
                $j->on('cl.cedula', '=', 'c.cedula')->where('cl.aliado_id', $alidoId);
            })
            ->where('c.aliado_id', $alidoId)
            ->whereIn('c.estado', ['vigente', 'activo'])
            ->whereNotNull('cl.celular')
            ->pluck('cl.celular')
            ->map(fn ($num) => $this->normalizarNumero((string) $num))
            ->filter()
            ->unique()
            ->values();
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

    /**
     * Las plantillas de la cuenta compartida se guardan a nombre de BryNex (así las crea
     * WhatsappPlantillaController), no del aliado que las usa: sin esto un aliado en la
     * cuenta compartida no ve ninguna plantilla al crear la campaña.
     */
    private function aliadoDuenoPlantillas(int $alidoId): int
    {
        return WhatsappConfig::paraAliado($alidoId)->usa_cuenta_brynex
            ? WhatsappBandejaCompartida::aliadoBandeja()
            : $alidoId;
    }

    private function autorizarAdmin(): void
    {
        abort_unless(Auth::user()->hasRole(['admin', 'superadmin']), 403);
    }
}
