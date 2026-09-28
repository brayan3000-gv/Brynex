<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\{OperadorCredencial, OperadorPlanilla, OperadorPlanillaApi, RazonSocial};
use App\Services\CorreccionEnlaceService;
use App\Services\CorreccionNovedadesService;
use App\Services\CorreccionPensionFaltanteService;
use App\Services\PlanillaE1Service;
use App\Services\PlanillaDosPasosService;
use App\Services\PlanoPilaTxtService;
use App\Services\SuaporteApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Liquidación de planillas PILA contra las APIs de los operadores.
 * Cubre los que corren la plataforma Enlace Operativo — hoy ARUS Enlace y
 * Simple, que exponen exactamente los mismos endpoints en distinto dominio.
 *
 * El flujo reemplaza el paso manual de descargar el TXT y subirlo al portal
 * del operador: se genera el plano en memoria, se envía a validar y, si queda
 * limpio, se guarda el número de planilla y la URL de pago PSE.
 */
class PlanillaApiController extends Controller
{
    /**
     * Estado de la integración para una razón social: qué operadores tienen
     * credenciales configuradas y si ya hay planilla liquidada del periodo.
     */
    public function estado(Request $request)
    {
        $aliadoId = session('aliado_id_activo');

        $validated = $request->validate([
            'razon_social_id'   => 'required|integer',
            'mes'               => 'required|integer|min:1|max:12',
            'anio'              => 'required|integer|min:2000|max:2100',
            'n_plano'           => 'required|integer|min:1',
            'tipos_modalidad'   => 'array',
            'tipos_modalidad.*' => 'integer',
        ]);

        $filtro = $this->filtroModalidades($validated['tipos_modalidad'] ?? []);

        // La E-1, Solo Caja y Solo Pensión se pagan en dos liquidaciones
        // encadenadas, así que la pantalla necesita saber en cuál va la tanda.
        // Ver PlanillaE1Service y PlanillaDosPasosService.
        $esDosPasos = PlanillaDosPasosService::aplica($validated['tipos_modalidad'] ?? []);
        $esE1 = $esDosPasos || PlanillaE1Service::aplica($validated['tipos_modalidad'] ?? []);

        // Una tanda que mezcla extraordinarias con gente normal no se puede
        // liquidar: ver PlanillaDosPasosService::tandaMezclada.
        $mezclada = PlanillaDosPasosService::tandaMezclada(
            $aliadoId,
            (int) $validated['razon_social_id'],
            (int) $validated['mes'],
            (int) $validated['anio'],
            (int) $validated['n_plano'],
            $validated['tipos_modalidad'] ?? []
        );

        // Tanda de correcciones de novedades: la pantalla la marca como planilla
        // N, dice qué planilla corrige y deja solo el operador donde se pagó.
        $correccion = null;
        $pendientesN = CorreccionNovedadesService::pendientes(
            (int) $aliadoId,
            (int) $validated['razon_social_id'],
            (int) $validated['mes'],
            (int) $validated['anio'],
            (int) $validated['n_plano'],
            $validated['tipos_modalidad'] ?? []
        );
        if ($pendientesN->isNotEmpty()) {
            try {
                $correccion = CorreccionNovedadesService::planillaAsociada((int) $aliadoId, $pendientesN)
                    + ['cantidad' => $pendientesN->count(), 'error' => null];
            } catch (\RuntimeException $e) {
                $correccion = ['cantidad' => $pendientesN->count(), 'error' => $e->getMessage()];
            }
        }

        $operadores = [];

        foreach ($this->operadoresConApi($aliadoId) as $operador) {
            $credencial = $this->credencial($aliadoId, $operador->id, (int) $validated['razon_social_id']);

            // Solo se ofrecen los que ya tienen credencial cargada.
            if (!$credencial) {
                continue;
            }

            // El filtro de modalidades forma parte de la identidad: la planilla
            // de los K y la de los E son dos planillas distintas de la misma
            // tanda, y mostrar la de la otra confundía más que ayudar.
            $planilla = OperadorPlanillaApi::where('aliado_id', $aliadoId)
                ->where('razon_social_id', $validated['razon_social_id'])
                ->where('operador_planilla_id', $operador->id)
                ->where('anio', $validated['anio'])
                ->where('mes', $validated['mes'])
                ->where('n_plano', $validated['n_plano'])
                ->whereRaw("ISNULL(tipos_modalidad, '') = ?", [$filtro])
                // Todo lo que no sea la corrección de una E-1 es paso 1, así
                // que para el resto de modalidades esto no filtra nada.
                ->where('paso', 1)
                ->latest('id')
                ->first();

            // Si no hay ninguna con ESTE filtro, puede haberla de la misma
            // tanda liquidada con otro. Antes el bloque simplemente se quedaba
            // callado y parecía que nunca se había liquidado: basta marcar una
            // modalidad de más en la pantalla —aunque no aporte a nadie— para
            // dejar de reconocer la planilla que ya existe. No se devuelve como
            // `planilla` porque cubre a otra gente y su valor no es el de este
            // filtro; se devuelve aparte, solo para avisarlo.
            $otrosFiltros = collect();
            if (! $planilla) {
                $otrosFiltros = OperadorPlanillaApi::where('aliado_id', $aliadoId)
                    ->where('razon_social_id', $validated['razon_social_id'])
                    ->where('operador_planilla_id', $operador->id)
                    ->where('anio', $validated['anio'])
                    ->where('mes', $validated['mes'])
                    ->where('n_plano', $validated['n_plano'])
                    ->where('estado', 'validada')
                    ->orderBy('id')
                    ->get();
            }

            $operadores[] = [
                'id'            => $operador->id,
                'nombre'        => $operador->nombre,
                'codigo'        => $operador->codigo,
                'clave_vencida' => $credencial->claveSecretaVencida(),
                'sin_codigo_ni' => empty($operador->codigo_ni),
                // Estado del flujo de dos pasos; null cuando la tanda no es E-1
                // y la pantalla pinta el botón único de siempre.
                'e1'            => $esE1
                    ? $this->estadoE1($aliadoId, (int) $validated['razon_social_id'], $operador->id, $validated, $filtro, $planilla, $esDosPasos)
                    : null,
                'planilla'      => $planilla ? [
                    'estado'          => $planilla->estado,
                    'numero_planilla' => $planilla->numero_planilla,
                    'valor_total'     => $planilla->valor_total,
                    'url_pago'        => $planilla->url_pago,
                    'mensaje_error'   => $planilla->mensaje_error,
                    'fecha'           => optional($planilla->updated_at)->format('Y-m-d H:i'),
                ] : null,
                'planillas_tanda' => $otrosFiltros->map(fn ($p) => [
                    'numero_planilla' => $p->numero_planilla,
                    'valor_total'     => $p->valor_total,
                    'url_pago'        => $p->url_pago,
                    'fecha'           => optional($p->updated_at)->format('Y-m-d H:i'),
                    'modalidades'     => $this->nombresModalidades($p->tipos_modalidad),
                ])->values(),
            ];
        }

        return response()->json([
            'disponible' => count($operadores) > 0,
            'motivo'     => $operadores ? null : 'Ninguna razón social tiene credenciales de operador configuradas.',
            'mezcla_extraordinaria' => $mezclada,
            'correccion' => $correccion,
            'operadores' => $operadores,
            'pendientes' => $this->pendientesDelPeriodo(
                $aliadoId, (int) $validated['razon_social_id'],
                (int) $validated['mes'], (int) $validated['anio']
            ),
        ]);
    }

    /**
     * Dónde va la tanda dentro del flujo de dos pasos: si el paso 1 está
     * liquidado, si su pago ya se confirmó y si el paso 2 ya salió. Ver
     * PlanillaE1Service y PlanillaDosPasosService.
     *
     * En Solo Caja y Solo Pensión la confirmación del pago no bloquea: la fecha
     * la descubre el propio operador al primer intento (`automatico`), así que
     * ahí solo sirve para mostrarla cuando existe.
     */
    private function estadoE1(
        int $aliadoId,
        int $razonSocialId,
        int $operadorId,
        array $validated,
        string $filtro,
        ?OperadorPlanillaApi $paso1,
        bool $dosPasos = false
    ): array {
        $paso2 = OperadorPlanillaApi::where('aliado_id', $aliadoId)
            ->where('razon_social_id', $razonSocialId)
            ->where('operador_planilla_id', $operadorId)
            ->where('anio', $validated['anio'])
            ->where('mes', $validated['mes'])
            ->where('n_plano', $validated['n_plano'])
            ->whereRaw("ISNULL(tipos_modalidad, '') = ?", [$filtro])
            ->where('paso', 2)
            ->latest('id')
            ->first();

        $pago = ($paso1 && $paso1->estado === 'validada' && $paso1->numero_planilla)
            ? PlanillaE1Service::pagoConfirmado($aliadoId, (string) $paso1->numero_planilla)
            : null;

        // La corrección por portal no trae el enlace de pago: el portal la deja
        // guardada y ya. Se le pide al API la primera vez que se muestra
        // pendiente, para que el "Ir a pagar en PSE" sea el de la corrección y
        // no el del paso 1, que ya está pagado.
        if ($paso2 && $paso2->estado === 'validada' && $paso2->numero_planilla && ! $paso2->url_pago
            && ! PlanillaE1Service::pagoConfirmado($aliadoId, (string) $paso2->numero_planilla)) {
            $this->completarUrlPago($paso2, $aliadoId);
        }

        // Qué vende esta tanda. En Tipo E - Extras lo dice el plan y no la
        // modalidad, y de ahí sale tanto la etiqueta del botón como el camino
        // de la corrección: la de salud no la acepta la API del operador y hay
        // que hacerla por su portal. Ver PlanillaDosPasosService.
        $planes = $dosPasos
            ? PlanillaDosPasosService::planesDeLaTanda(
                $aliadoId,
                $razonSocialId,
                (int) $validated['mes'],
                (int) $validated['anio'],
                (int) $validated['n_plano'],
                $validated['tipos_modalidad'] ?? []
            )
            : [];

        $porPortal = $dosPasos && PlanillaDosPasosService::correccionPorPortal($planes);

        return [
            'paso1_liquidado' => (bool) ($paso1 && $paso1->estado === 'validada' && $paso1->numero_planilla),
            'pago_confirmado' => (bool) $pago,
            'fecha_pago'      => $pago ? $pago->fecha->format('Y-m-d') : null,
            // Solo Caja y Solo Pensión no esperan la confirmación del pago: el
            // operador revela la fecha y BryNex reintenta con ella. La de salud
            // sí la espera: el portal exige la planilla ya pagada.
            'automatico'      => $dosPasos && ! $porPortal,
            'planes'          => $planes,
            'por_portal'      => $porPortal,
            // Para la ficha del portal: sin el número de la planilla pagada no
            // se puede crear la corrección allá.
            'paso1'           => $paso1 ? [
                'numero_planilla' => $paso1->numero_planilla,
                'valor_total'     => $paso1->valor_total,
                'url_pago'        => $paso1->url_pago,
            ] : null,
            'etiqueta_paso2'  => $dosPasos
                ? PlanillaDosPasosService::etiquetaCorreccion($planes)
                : 'Corrección (salud + ARL + caja)',
            'paso2'           => $paso2 ? [
                'estado'          => $paso2->estado,
                'numero_planilla' => $paso2->numero_planilla,
                'valor_total'     => $paso2->valor_total,
                'url_pago'        => $paso2->url_pago,
                // El plano queda pagado solo con las dos planillas confirmadas.
                'pago_confirmado' => $paso2->estado === 'validada' && $paso2->numero_planilla
                    && PlanillaE1Service::pagoConfirmado($aliadoId, (string) $paso2->numero_planilla) !== null,
                'mensaje_error'   => $paso2->mensaje_error,
                'fecha'           => optional($paso2->updated_at)->format('Y-m-d H:i'),
            ] : null,
        ];
    }

    /**
     * Cuántos contratos vigentes de la razón social todavía no entran a
     * ninguna planilla del período. Es lo que Enlace reclama con la
     * advertencia `eo.val.2.270`, y sirve para saber, al liquidar la última
     * tanda, si de verdad quedó todo cubierto. Ver CierrePeriodoService.
     *
     * Solo para BryNex, igual que el informe: una razón social agrupa varias
     * empresas cliente, así que el número suelto siembra dudas en el aliado.
     * Devuelve null cuando no aplica y la vista no pinta nada.
     */
    private function pendientesDelPeriodo(int $aliadoId, int $razonSocialId, int $mes, int $anio): ?array
    {
        if (!\Illuminate\Support\Facades\Auth::user()?->can('brynex_cierre.ver')) {
            return null;
        }

        $total = (new \App\Services\CierrePeriodoService())
            ->contarPendientes($aliadoId, $razonSocialId, $mes, $anio);

        return [
            'total' => $total,
            'url'   => route('admin.informes.validacion_cierre', [
                'mes' => $mes, 'anio' => $anio, 'razon_social_id' => $razonSocialId,
            ]),
        ];
    }

    /**
     * Genera el plano y lo liquida en Enlace: validación → totales → URL PSE.
     */
    public function liquidar(Request $request)
    {
        $aliadoId = session('aliado_id_activo');

        $validated = $request->validate([
            'razon_social_id'      => 'required|integer',
            'operador_planilla_id' => 'required|integer',
            'mes'                  => 'required|integer|min:1|max:12',
            'anio'                 => 'required|integer|min:2000|max:2100',
            'n_plano'              => 'required|integer|min:1',
            'tipos_modalidad'      => 'array',
            'tipos_modalidad.*'    => 'integer',
            'solo_novedades'       => 'boolean',
            // El usuario ya vio que hay una planilla liquidada y aun así quiere
            // volver a liquidar (ver el 409 más abajo).
            'reliquidar'           => 'boolean',
            // Modalidad E-1: 1 = planilla E de un día, 2 = corrección de la
            // anterior. Cualquier otra liquidación es y sigue siendo paso 1.
            'paso'                 => 'integer|in:1,2',
        ]);

        $filtro = $this->filtroModalidades($validated['tipos_modalidad'] ?? []);
        $paso   = (int) ($validated['paso'] ?? 1);

        // ── Tanda mezclada: no se liquida ────────────────────────────────
        // Las modalidades extraordinarias van sin salud en el paso 1 y su
        // corrección se busca por el mismo filtro con el que se liquidaron.
        // Metidas en una tanda con gente normal quedan sin forma de corregirse
        // y hay que volver a liquidar, que duplica la planilla en el operador.
        // Ver PlanillaDosPasosService::tandaMezclada.
        if (PlanillaDosPasosService::tandaMezclada(
            $aliadoId,
            (int) $validated['razon_social_id'],
            (int) $validated['mes'],
            (int) $validated['anio'],
            (int) $validated['n_plano'],
            $validated['tipos_modalidad'] ?? []
        )) {
            return response()->json([
                'success' => false,
                'message' => 'Esta tanda mezcla modalidades extraordinarias (Tipo E - Caja / Pensión) '
                    .'con las normales, y esas van en su propio archivo: en el paso 1 se reportan sin '
                    .'salud, y su corrección se busca después por el mismo filtro con el que se '
                    .'liquidaron. Marque en el filtro de modalidades solo las extraordinarias, '
                    .'liquide, y después las demás.',
            ], 422);
        }

        // Multi-tenant: la razón social debe ser del aliado activo.
        $rs = RazonSocial::where('aliado_id', $aliadoId)
            ->find($validated['razon_social_id']);

        if (!$rs) {
            return response()->json(['success' => false, 'message' => 'Razón social no encontrada.'], 404);
        }

        if (empty($rs->nit)) {
            return response()->json([
                'success' => false,
                'message' => "La razón social {$rs->razon_social} no tiene NIT configurado.",
            ], 422);
        }

        $operador = $this->operadoresConApi($aliadoId)
            ->firstWhere('id', (int) $validated['operador_planilla_id']);

        if (!$operador) {
            return response()->json([
                'success' => false,
                'message' => 'Ese operador no está activo para este aliado o no tiene integración por API.',
            ], 422);
        }

        // El código del operador va en el registro tipo 1 del archivo plano
        // (pos. 358-359). Sin él, el operador rechaza la planilla.
        if (empty($operador->codigo_ni)) {
            return response()->json([
                'success' => false,
                'message' => "Falta el código PILA de {$operador->nombre}. Configúrelo en Configuración → Operadores de planilla antes de liquidar.",
            ], 422);
        }

        $credencial = $this->credencial($aliadoId, $operador->id, (int) $rs->id);

        if (!$credencial) {
            return response()->json([
                'success' => false,
                'message' => "No hay credenciales de {$operador->nombre} para esta razón social. Configúrelas antes de liquidar.",
            ], 422);
        }

        if ($credencial->claveSecretaVencida()) {
            return response()->json([
                'success' => false,
                'message' => "La clave secreta de {$operador->nombre} venció. Genere una nueva desde el tablero del operador.",
            ], 422);
        }

        // ── Corrección de novedades (planilla N) ─────────────────────────
        // Una tanda de correcciones —el retiro del traslado de razón social—
        // sale N con la planilla que corrige, y solo se liquida en el operador
        // donde esa planilla se pagó. Ver CorreccionNovedadesService.
        $correccionNovedades = null;
        $pendientesN = CorreccionNovedadesService::pendientes(
            (int) $aliadoId,
            (int) $rs->id,
            (int) $validated['mes'],
            (int) $validated['anio'],
            (int) $validated['n_plano'],
            $validated['tipos_modalidad'] ?? []
        );

        if ($pendientesN->isNotEmpty()) {
            try {
                $correccionNovedades = CorreccionNovedadesService::planillaAsociada((int) $aliadoId, $pendientesN);
            } catch (\RuntimeException $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            if ($correccionNovedades['operador_planilla_id']
                && $correccionNovedades['operador_planilla_id'] !== (int) $operador->id) {
                return response()->json([
                    'success' => false,
                    'message' => "La planilla {$correccionNovedades['numero']} se pagó en {$correccionNovedades['operador']}: "
                        .'su corrección solo se puede liquidar allá.',
                ], 422);
            }
        }

        // ── 1. ¿Esta tanda ya tiene planilla? ────────────────────────────
        // Se pregunta ANTES de tocar nada: si el usuario cancela en el 409, no
        // se corrigieron datos ni se generó archivo por una liquidación que
        // nunca ocurrió.
        //
        // La llave incluye el filtro de modalidades: sin él, liquidar los K
        // pisaba el registro de los E y se perdía su número de planilla.
        $llave = [
            'aliado_id'            => $aliadoId,
            'razon_social_id'      => $rs->id,
            'operador_planilla_id' => $operador->id,
            'anio'                 => $validated['anio'],
            'mes'                  => $validated['mes'],
            'n_plano'              => $validated['n_plano'],
            'tipos_modalidad'      => $filtro,
        ];

        // ── Dos pasos con el paso 1 ya pagado ────────────────────────────
        // Pagado el paso 1, la tanda queda atada a ese operador: la corrección
        // solo existe sobre esa planilla, y volver a liquidar el paso 1 (allá
        // o en otro operador) sería pagar dos veces el mismo mes.
        if (PlanillaDosPasosService::aplica($validated['tipos_modalidad'] ?? [])) {
            $pagadoEn = $this->paso1Pagado($llave);

            if ($pagadoEn && ((int) $pagadoEn->operador_planilla_id !== (int) $operador->id || $paso === 1)) {
                $nombreOp = \App\Models\OperadorPlanilla::whereKey($pagadoEn->operador_planilla_id)->value('nombre');

                return response()->json([
                    'success' => false,
                    'message' => $paso === 1 && (int) $pagadoEn->operador_planilla_id === (int) $operador->id
                        ? "La planilla del paso 1 ({$pagadoEn->numero_planilla}) ya está pagada. Solo falta la corrección."
                        : "El paso 1 se pagó en {$nombreOp} (planilla {$pagadoEn->numero_planilla}): la corrección solo se puede hacer allá.",
                ], 422);
            }
        }

        // ── Paso 2 de la E-1: la corrección ──────────────────────────────
        // Solo puede salir si la planilla del paso 1 ya está liquidada Y
        // pagada, porque de ahí salen los campos 9 y 10 del registro tipo 1.
        // Ver PlanillaE1Service.
        $opcionesPlano = [];
        // Solo Caja descubre la fecha de pago preguntándole al operador, así
        // que su corrección puede salir sin que nadie confirme el pago en
        // BryNex. Ver PlanillaDosPasosService.
        $esDosPasos = PlanillaDosPasosService::aplica($validated['tipos_modalidad'] ?? []);

        if ($paso === 2) {
            if (! $esDosPasos && ! PlanillaE1Service::aplica($validated['tipos_modalidad'] ?? [])) {
                return response()->json([
                    'success' => false,
                    'message' => 'La corrección solo existe en las modalidades E-1 y Solo Caja, y el '
                        . 'filtro de modalidades debe traer únicamente una de ellas. Con otras '
                        . 'mezcladas, el paso 1 dejaría sin salud a gente que no está en este esquema.',
                ], 422);
            }

            // La corrección que anexa salud no la acepta la API del operador
            // (eo.val.2.198 / 2.244) aunque su propio portal la liquide sin
            // una queja. Se corta aquí, antes de gastar una liquidación, y la
            // pantalla ofrece el camino del portal. Ver PlanillaDosPasosService.
            if ($esDosPasos) {
                $planesTanda = PlanillaDosPasosService::planesDeLaTanda(
                    $aliadoId,
                    (int) $rs->id,
                    (int) $validated['mes'],
                    (int) $validated['anio'],
                    (int) $validated['n_plano'],
                    $validated['tipos_modalidad'] ?? []
                );

                if (PlanillaDosPasosService::correccionPorPortal($planesTanda)) {
                    return $this->correccionPorPortal($llave, $aliadoId, (bool) ($validated['reliquidar'] ?? false));
                }
            }

            $contexto = $esDosPasos
                ? PlanillaDosPasosService::contextoCorreccion($llave)
                : PlanillaE1Service::contextoCorreccion($llave);

            if (! $contexto['ok']) {
                return response()->json([
                    'success'      => false,
                    'falta_pago'   => true,
                    'message'      => $contexto['message'],
                ], 422);
            }

            $opcionesPlano = [
                'tipo_planilla'     => 'N',
                'paso'              => 2,
                'planilla_asociada' => $contexto['planilla_asociada'],
            ];
        }

        // El paso distingue las dos liquidaciones de la misma tanda: sin él, la
        // corrección pisaría el registro del paso 1 y se perdería el número de
        // la planilla que acaba de pagarse.
        $llave['paso'] = $paso;

        // Re-liquidar borra el número anterior, así que no puede pasar de
        // largo: se devuelve 409 con el número que se va a perder y el front
        // reintenta con `reliquidar` si el usuario confirma.
        //
        // El aviso es a propósito más amplio que la llave: también entran los
        // registros anteriores a la columna `tipos_modalidad`, que están en
        // NULL y de los que no se sabe con qué filtro se liquidaron. Tratarlos
        // como "de cualquier filtro" hace que avisen de más una vez, en vez de
        // dejar liquidar dos veces la misma tanda y duplicar la planilla en el
        // operador —que cuesta dinero de verdad—.
        $previo = OperadorPlanillaApi::where('aliado_id', $aliadoId)
            ->where('razon_social_id', $rs->id)
            ->where('operador_planilla_id', $operador->id)
            ->where('anio', $validated['anio'])
            ->where('mes', $validated['mes'])
            ->where('n_plano', $validated['n_plano'])
            // La corrección no compite con la planilla que corrige: son dos
            // liquidaciones legítimas de la misma tanda, no un duplicado.
            ->where('paso', $paso)
            ->where('estado', 'validada')
            ->where(function ($q) use ($filtro) {
                $q->whereNull('tipos_modalidad')->orWhere('tipos_modalidad', $filtro);
            })
            ->latest('id')
            ->first();

        if ($previo && !($validated['reliquidar'] ?? false)) {
            // Solo se pisa el registro si el filtro coincide exactamente. Si el
            // anterior es de otro filtro —o es uno viejo, sin filtro guardado—
            // lo que se arriesga es duplicar la planilla en el operador, que es
            // un problema distinto y hay que decirlo distinto.
            $reemplaza = $previo->tipos_modalidad === $filtro;

            return response()->json([
                'success'               => false,
                'requiere_confirmacion' => true,
                'reemplaza'             => $reemplaza,
                'numero_planilla'       => $previo->numero_planilla,
                'valor_total'           => $previo->valor_total,
                'fecha'                 => optional($previo->updated_at)->format('Y-m-d H:i'),
                'message'               => $reemplaza
                    ? "Esta tanda ya tiene la planilla {$previo->numero_planilla} liquidada. "
                      ."Si vuelve a liquidar, ese número se reemplaza."
                    : "Esta tanda ya tiene la planilla {$previo->numero_planilla} liquidada con otro filtro. "
                      ."Si la gente que va en este archivo ya está en esa planilla, quedaría duplicada en el operador.",
            ], 409);
        }

        // ── 2. Fondo de pensión faltante ─────────────────────────────────
        // Quien va al archivo sin AFP no cotiza pensión, aunque su factura se
        // la haya cobrado. Se corrige aquí, antes de armar el TXT, para que la
        // planilla salga bien de una vez en lugar de liquidar de menos y tener
        // que anularla en el operador — ver CorreccionPensionFaltanteService.
        // En una corrección no: la línea A tiene que repetir lo que ya se pagó.
        $pensionCorregida = $correccionNovedades ? ['aplicadas' => []] : (new CorreccionPensionFaltanteService())->corregir([
            'aliado_id'       => $aliadoId,
            'razon_social_id' => (int) $rs->id,
            'mes'             => (int) $validated['mes'],
            'anio'            => (int) $validated['anio'],
            'n_plano'         => (int) $validated['n_plano'],
        ], $validated['tipos_modalidad'] ?? []);

        if (!empty($pensionCorregida['aplicadas'])) {
            Log::info('Enlace API: fondo de pensión corregido antes de liquidar', [
                'razon_social_id' => $rs->id,
                'n_plano'         => $validated['n_plano'],
                'correcciones'    => $pensionCorregida['aplicadas'],
            ]);
        }

        if ($correccionNovedades) {
            $opcionesPlano['planilla_asociada'] = [
                'numero'     => $correccionNovedades['numero'],
                'fecha_pago' => $correccionNovedades['fecha_pago'],
            ];
        }

        // ── 3. Generar el archivo plano en memoria ───────────────────────
        try {
            $plano = (new PlanoPilaTxtService())->construir(array_merge([
                'aliado_id'       => $aliadoId,
                'razon_social_id' => $rs->id,
                'mes'             => $validated['mes'],
                'anio'            => $validated['anio'],
                'n_plano'         => $validated['n_plano'],
                'tipos_modalidad' => $validated['tipos_modalidad'] ?? [],
                'codigo_operador' => (string) $operador->codigo_ni,
            ], $opcionesPlano));
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('Enlace API: error al construir el plano', [
                'razon_social_id' => $rs->id,
                'message'         => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al generar el archivo plano: ' . $e->getMessage(),
            ], 500);
        }

        // ── 4. Registro de trazabilidad ──────────────────────────────────
        $registro = OperadorPlanillaApi::updateOrCreate(
            $llave,
            ['estado' => 'procesando', 'mensaje_error' => null]
        );

        // ── 5. Liquidar contra el operador ───────────────────────────────
        $api = new SuaporteApiService([
            'operador'      => $operador->codigo, // define el host de la plataforma
            'usuario'       => $credencial->usuario,
            'contrasena'    => $credencial->contrasena,
            'clave_secreta' => $credencial->clave_secreta,
        ]);

        $opcionesApi = [
            // La corrección de un retiro no cambia valores: solo reporta la novedad.
            'planillaNSoloNovedades' => (bool) ($validated['solo_novedades'] ?? false) || (bool) $correccionNovedades,
            'tipoArchivo'            => 'I',
        ];

        $resultado = $api->liquidarPlanilla($rs->nit, $plano['contenido'], $plano['filename'], $opcionesApi);

        // ── 5b. La fecha de pago que revela el operador ──────────────────
        // La corrección de Solo Caja sale con una fecha tentativa porque la API
        // no devuelve la real. Si era otra, el rechazo la dice
        // (`eo.val.1.043`): se rearma el archivo con ella y se reintenta una
        // sola vez. Ver PlanillaDosPasosService.
        if ((($paso === 2 && $esDosPasos) || $correccionNovedades)
            && ($resultado['success'] ?? false) && ! ($resultado['liquidada'] ?? false)) {

            $fechaReal = PlanillaDosPasosService::fechaPagoDelError(
                $resultado['errores_empresa'] ?? [],
                (string) $opcionesPlano['planilla_asociada']['fecha_pago']
            );

            if ($fechaReal) {
                Log::info('Enlace API: el operador corrigió la fecha de pago de la planilla asociada', [
                    'razon_social_id' => $rs->id,
                    'planilla'        => $opcionesPlano['planilla_asociada']['numero'],
                    'enviada'         => $opcionesPlano['planilla_asociada']['fecha_pago'],
                    'real'            => $fechaReal,
                ]);

                $opcionesPlano['planilla_asociada']['fecha_pago'] = $fechaReal;

                try {
                    $plano = (new PlanoPilaTxtService())->construir(array_merge([
                        'aliado_id'       => $aliadoId,
                        'razon_social_id' => $rs->id,
                        'mes'             => $validated['mes'],
                        'anio'            => $validated['anio'],
                        'n_plano'         => $validated['n_plano'],
                        'tipos_modalidad' => $validated['tipos_modalidad'] ?? [],
                        'codigo_operador' => (string) $operador->codigo_ni,
                    ], $opcionesPlano));

                    $resultado = $api->liquidarPlanilla($rs->nit, $plano['contenido'], $plano['filename'], $opcionesApi);
                } catch (\Exception $e) {
                    Log::error('Enlace API: error al rearmar la corrección con la fecha real', [
                        'razon_social_id' => $rs->id,
                        'message'         => $e->getMessage(),
                    ]);
                }
            }
        }

        // Deja constancia de qué planilla corrige esta, para no tener que
        // reconstruir el número después leyendo el archivo.
        $datosAsociada = isset($opcionesPlano['planilla_asociada'])
            ? [
                'planilla_asociada_numero'     => $opcionesPlano['planilla_asociada']['numero'],
                'planilla_asociada_fecha_pago' => $opcionesPlano['planilla_asociada']['fecha_pago'],
            ]
            : [];

        // ── 4. Persistir el resultado ────────────────────────────────────
        if (!($resultado['success'] ?? false)) {
            $registro->update([
                'estado'        => 'error',
                'mensaje_error' => $resultado['message'] ?? 'Error desconocido.',
                'response_log'  => $resultado['response'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'paso'    => $resultado['paso'] ?? null,
                'message' => $resultado['message'] ?? 'No fue posible liquidar la planilla.',
            ], 422);
        }

        // Planilla con errores: Enlace la crea pero sin número, para corregir.
        if (!($resultado['liquidada'] ?? false)) {
            $registro->update([
                'estado'          => 'con_errores',
                'api_planilla_id' => $resultado['codigo_planilla'] ?? null,
                'mensaje_error'   => "La planilla tiene {$resultado['total_errores']} error(es).",
                'response_log'    => $resultado['response'] ?? null,
            ]);

            $correcciones = (new CorreccionEnlaceService())
                ->interpretar($resultado['errores_cotizante'] ?? [], $aliadoId);

            // El operador no encuentra la planilla del paso 1 entre las
            // pagadas: no es un archivo mal armado, es que falta el pago. Se
            // cuenta distinto para que nadie salga a buscar un error que no
            // existe. Ver PlanillaDosPasosService.
            if ($paso === 2 && $esDosPasos
                && PlanillaDosPasosService::faltaElPago($resultado['errores_empresa'] ?? [])) {

                $numero = $opcionesPlano['planilla_asociada']['numero'] ?? '';

                return response()->json([
                    'success'    => false,
                    'falta_pago' => true,
                    'message'    => "La planilla {$numero} del paso 1 todavía no aparece pagada en el "
                        .'operador. La corrección solo se puede enviar sobre una planilla ya pagada: '
                        .'pague el paso 1 y vuelva a intentarlo.',
                ], 422);
            }

            return response()->json([
                'success'          => true,
                'liquidada'        => false,
                'codigo_planilla'  => $resultado['codigo_planilla'] ?? null,
                'total_errores'    => $resultado['total_errores'] ?? 0,
                'errores_cotizante'=> $resultado['errores_cotizante'] ?? [],
                'errores_empresa'  => $resultado['errores_empresa'] ?? [],
                'advertencias'     => $resultado['advertencias'] ?? [],
                'correcciones'     => $correcciones,
                'pension_corregida'=> $pensionCorregida['aplicadas'],
                'razon_social_id'  => $rs->id,
                'message'          => 'El archivo tiene errores. Corríjalos y vuelva a liquidar.',
            ]);
        }

        $registro->update(array_merge([
            'estado'          => 'validada',
            'api_planilla_id' => $resultado['codigo_planilla'] ?? null,
            'numero_planilla' => $resultado['numero_planilla'] ?? null,
            'valor_total'     => $resultado['totales']['total_pagar'] ?? null,
            'url_pago'        => $resultado['url_pago'] ?? null,
            'mensaje_error'   => null,
            'response_log'    => $resultado['response'] ?? null,
        ], $datosAsociada));

        return response()->json([
            'success'         => true,
            'liquidada'       => true,
            'numero_planilla' => $resultado['numero_planilla'],
            'codigo_planilla' => $resultado['codigo_planilla'] ?? null,
            'valor_total'     => $resultado['totales']['total_pagar'] ?? null,
            'valor_mora'      => $resultado['totales']['valor_mora'] ?? null,
            'fecha_limite'    => $resultado['totales']['fecha_limite'] ?? null,
            'url_pago'        => $resultado['url_pago'] ?? null,
            'advertencias'    => $resultado['advertencias'] ?? [],
            'pension_corregida' => $pensionCorregida['aplicadas'],
            'pendientes'      => $this->pendientesDelPeriodo(
                $aliadoId, (int) $rs->id, (int) $validated['mes'], (int) $validated['anio']
            ),
            'paso'            => $paso,
            'message'         => "Planilla {$resultado['numero_planilla']} liquidada en Enlace Operativo.",
        ]);
    }

    /**
     * Liquidación puntual de UN contratista independiente (fila de `planos`),
     * no de un lote de empresa. Todos los independientes de un aliado
     * comparten la misma razón social genérica ("INDEPENDIENTE"), así que
     * cada uno se liquida por su cuenta con su propia cédula como aportante
     * — ver PlanoPilaTxtService::construir() con `plano_id`.
     */
    public function liquidarIndependiente(Request $request)
    {
        $aliadoId = session('aliado_id_activo');

        $validated = $request->validate([
            'plano_id'             => 'required|integer',
            'operador_planilla_id' => 'required|integer',
        ]);

        $plano = DB::table('planos')
            ->where('id', $validated['plano_id'])
            ->where('aliado_id', $aliadoId)
            ->whereNull('deleted_at')
            ->first();

        if (!$plano) {
            return response()->json(['success' => false, 'message' => 'Registro no encontrado.'], 404);
        }

        if (!empty($plano->numero_planilla)) {
            return response()->json(['success' => false, 'message' => 'Este registro ya tiene una planilla liquidada.'], 422);
        }

        $rs = RazonSocial::where('aliado_id', $aliadoId)->find($plano->razon_social_id);
        if (!$rs || !$rs->es_independiente) {
            return response()->json(['success' => false, 'message' => 'Este registro no pertenece a la razón social de independientes.'], 422);
        }

        $operador = $this->operadoresApiIndependiente()
            ->firstWhere('id', (int) $validated['operador_planilla_id']);

        if (!$operador) {
            return response()->json([
                'success' => false,
                'message' => 'Ese operador no tiene integración por API.',
            ], 422);
        }

        if (empty($operador->codigo_ni)) {
            return response()->json([
                'success' => false,
                'message' => "Falta el código PILA de {$operador->nombre}. Configúrelo en Configuración → Operadores de planilla antes de liquidar.",
            ], 422);
        }

        $credencial = $this->credencial($aliadoId, $operador->id, (int) $rs->id);

        if (!$credencial) {
            return response()->json([
                'success' => false,
                'message' => "No hay credenciales de {$operador->nombre} configuradas para este aliado.",
            ], 422);
        }

        if ($credencial->claveSecretaVencida()) {
            return response()->json([
                'success' => false,
                'message' => "La clave secreta de {$operador->nombre} venció. Genere una nueva desde el tablero del operador.",
            ], 422);
        }

        // El período que espera construir() es "mes de pago"; el plano guarda
        // mes_plano/anio_plano ya sea como mes de pago (paga_mes_actual) o como
        // mes vencido (el resto) — mismo criterio que el resto del módulo.
        // La señal es `paga_mes_actual`, no la modalidad: la 11 se jubiló y hoy
        // el mes en curso es un campo del contrato que aplica a varias
        // modalidades. Con el `=== 11` de antes, cualquier independiente de mes
        // actual se liquidaba con el período corrido un mes.
        $mesActual = (bool) $plano->paga_mes_actual;
        if ($mesActual) {
            $mesPago = (int) $plano->mes_plano; $anioPago = (int) $plano->anio_plano;
        } else {
            $mesPago  = $plano->mes_plano == 12 ? 1 : (int) $plano->mes_plano + 1;
            $anioPago = $plano->mes_plano == 12 ? (int) $plano->anio_plano + 1 : (int) $plano->anio_plano;
        }

        // Mismo arreglo que en el lote de empresa: si la factura le cobró
        // pensión pero el registro va sin AFP, se le pone el fondo antes de
        // armar el TXT — ver CorreccionPensionFaltanteService.
        $pensionCorregida = (new CorreccionPensionFaltanteService())->corregir([
            'aliado_id'       => $aliadoId,
            'razon_social_id' => (int) $rs->id,
            'mes'             => $mesPago,
            'anio'            => $anioPago,
            'n_plano'         => (int) $plano->n_plano,
            'plano_id'        => $plano->id,
        ]);

        if (!empty($pensionCorregida['aplicadas'])) {
            Log::info('Enlace API: fondo de pensión corregido antes de liquidar (independiente)', [
                'plano_id'     => $plano->id,
                'correcciones' => $pensionCorregida['aplicadas'],
            ]);
        }

        try {
            $planoTxt = (new PlanoPilaTxtService())->construir([
                'aliado_id'       => $aliadoId,
                'razon_social_id' => $rs->id,
                'mes'             => $mesPago,
                'anio'            => $anioPago,
                'n_plano'         => (int) $plano->n_plano,
                'plano_id'        => $plano->id,
                'codigo_operador' => (string) $operador->codigo_ni,
                // En mes actual el período cotizado es el mes de pago, no el
                // anterior; sin esto el encabezado sale corrido un mes.
                'periodo_mes_actual' => $mesActual,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('Enlace API: error al construir el plano de independiente', [
                'plano_id' => $plano->id,
                'message'  => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al generar el archivo plano: ' . $e->getMessage(),
            ], 500);
        }

        $registro = OperadorPlanillaApi::updateOrCreate(
            [
                'aliado_id'            => $aliadoId,
                'razon_social_id'      => $rs->id,
                'plano_id'             => $plano->id,
                'operador_planilla_id' => $operador->id,
            ],
            ['anio' => $anioPago, 'mes' => $mesPago, 'n_plano' => $plano->n_plano, 'estado' => 'procesando', 'mensaje_error' => null]
        );

        $nombreCotizante = trim($plano->primer_nombre . ' ' . $plano->primer_ape);

        $api = new SuaporteApiService([
            'operador'      => $operador->codigo,
            'usuario'       => $credencial->usuario,
            'contrasena'    => $credencial->contrasena,
            'clave_secreta' => $credencial->clave_secreta,
        ]);

        // PT (Permiso por Protección Temporal) es código PILA válido: no se traduce a CE
        $mapaDoc = ['C' => 'CC', 'NIT' => 'CC', 'NUIP' => 'CC'];
        $tipoDoc = $mapaDoc[strtoupper(trim($plano->tipo_doc ?? 'CC'))] ?? strtoupper(trim($plano->tipo_doc ?? 'CC'));

        // Datos de contacto para registrar al contratista como aportante
        // independiente en Enlace si todavía no existe ahí (ver
        // SuaporteApiService::crearAportanteIndependiente). codigoMunicipio
        // = DIVIPOLA depto(2) + municipio(3) + '000', igual que exige
        // el formulario web de Enlace.
        $cliente = DB::table('clientes')
            ->where('cedula', $plano->no_identifi)
            ->where('aliado_id', $aliadoId)
            ->first();

        $contactoAportante = [];
        if ($cliente) {
            $depCod  = $cliente->departamento_id ? str_pad((string) $cliente->departamento_id, 2, '0', STR_PAD_LEFT) : '';
            $munPila = $cliente->municipio_id
                ? DB::table('ciudades')->where('id_ciudad_t', $cliente->municipio_id)->value('Municipio')
                : null;
            $munCod  = ($depCod && $munPila !== null)
                ? $depCod . str_pad((string) $munPila, 3, '0', STR_PAD_LEFT) . '000'
                : '';

            $contactoAportante = [
                'correo'              => $cliente->correo ?? '',
                'telefono'            => $cliente->telefono ?? '',
                'celular'             => $cliente->celular ?? '',
                'codigo_departamento' => $depCod,
                'codigo_municipio'    => $munCod,
                'direccion'           => $cliente->direccion_vivienda ?? '',
            ];
        }

        $resultado = $api->liquidarPlanilla($plano->no_identifi, $planoTxt['contenido'], $planoTxt['filename'], [
            'tipo_documento'     => $tipoDoc,
            'crear_si_no_existe' => true,
            'nombre_aportante'   => $nombreCotizante,
            'contacto_aportante' => $contactoAportante,
            'tipoArchivo'        => 'I',
        ]);

        if (!($resultado['success'] ?? false)) {
            $registro->update([
                'estado'        => 'error',
                'mensaje_error' => $resultado['message'] ?? 'Error desconocido.',
                'response_log'  => $resultado['response'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'paso'    => $resultado['paso'] ?? null,
                'message' => $resultado['message'] ?? 'No fue posible liquidar la planilla.',
            ], 422);
        }

        if (!($resultado['liquidada'] ?? false)) {
            $registro->update([
                'estado'          => 'con_errores',
                'api_planilla_id' => $resultado['codigo_planilla'] ?? null,
                'mensaje_error'   => "La planilla tiene {$resultado['total_errores']} error(es).",
                'response_log'    => $resultado['response'] ?? null,
            ]);

            $correcciones = (new CorreccionEnlaceService())
                ->interpretar($resultado['errores_cotizante'] ?? [], $aliadoId);

            return response()->json([
                'success'          => true,
                'liquidada'        => false,
                'codigo_planilla'  => $resultado['codigo_planilla'] ?? null,
                'total_errores'    => $resultado['total_errores'] ?? 0,
                'errores_cotizante'=> $resultado['errores_cotizante'] ?? [],
                'errores_empresa'  => $resultado['errores_empresa'] ?? [],
                'correcciones'     => $correcciones,
                'pension_corregida'=> $pensionCorregida['aplicadas'],
                'razon_social_id'  => $rs->id,
                'message'          => 'El archivo tiene errores. Corríjalos y vuelva a liquidar.',
            ]);
        }

        $registro->update([
            'estado'          => 'validada',
            'api_planilla_id' => $resultado['codigo_planilla'] ?? null,
            'numero_planilla' => $resultado['numero_planilla'] ?? null,
            'valor_total'     => $resultado['totales']['total_pagar'] ?? null,
            'url_pago'        => $resultado['url_pago'] ?? null,
            'mensaje_error'   => null,
            'response_log'    => $resultado['response'] ?? null,
        ]);

        return response()->json([
            'success'         => true,
            'liquidada'       => true,
            'numero_planilla' => $resultado['numero_planilla'],
            'valor_total'     => $resultado['totales']['total_pagar'] ?? null,
            'valor_mora'      => $resultado['totales']['valor_mora'] ?? null,
            'fecha_limite'    => $resultado['totales']['fecha_limite'] ?? null,
            'url_pago'        => $resultado['url_pago'] ?? null,
            'pension_corregida' => $pensionCorregida['aplicadas'],
            'message'         => "Planilla {$resultado['numero_planilla']} liquidada en {$operador->nombre} para {$nombreCotizante}.",
        ]);
    }

    /**
     * Le pide a Enlace que corrija los errores que su validación marcó como
     * autocorregibles y refleja el mismo cambio en Brynex.
     *
     * Es una acción aparte y explícita, no un paso automático de liquidar():
     * corregir solo del lado de Enlace dejaría el dato malo en el contrato y
     * el error volvería el mes siguiente, así que el usuario ve primero a
     * quién y qué se le va a cambiar (ver `correcciones` en liquidar()).
     */
    public function autocorregir(Request $request)
    {
        $aliadoId = session('aliado_id_activo');

        $validated = $request->validate([
            'codigo_planilla' => 'required|integer',
            'solo_novedades'  => 'boolean',
        ]);

        $registro = OperadorPlanillaApi::where('aliado_id', $aliadoId)
            ->where('api_planilla_id', $validated['codigo_planilla'])
            ->latest('id')
            ->first();

        if (!$registro) {
            return response()->json(['success' => false, 'message' => 'Planilla no encontrada.'], 404);
        }

        if ($registro->estado === 'validada') {
            return response()->json(['success' => false, 'message' => 'Esta planilla ya está liquidada.'], 422);
        }

        // Las correcciones se leen de la validación original: son las que el
        // usuario vio en pantalla antes de aceptar.
        $erroresPrevios = $registro->response_log['validacionPlanillas'][0]['erroresCotizantePlanilla'] ?? [];
        $servicio       = new CorreccionEnlaceService();
        $correcciones   = $servicio->interpretar($erroresPrevios, $aliadoId);

        if (!$correcciones) {
            return response()->json([
                'success' => false,
                'message' => 'Esta planilla no tiene errores que Enlace pueda autocorregir.',
            ], 422);
        }

        $sesion = $this->abrirSesion($registro, $aliadoId);

        if (!$sesion['success']) {
            return response()->json(['success' => false, 'message' => $sesion['message']], 422);
        }

        $resultado = $sesion['api']->corregirPlanilla((int) $validated['codigo_planilla'], [
            'planillaNSoloNovedades' => (bool) ($validated['solo_novedades'] ?? false),
            'tipoArchivo'            => 'I',
        ]);

        if (!($resultado['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $resultado['message'] ?? 'Enlace no pudo autocorregir la planilla.',
            ], 422);
        }

        // Enlace ya corrigió su lado: se replica en Brynex aunque queden otros
        // errores no autocorregibles, para no volver a arrastrar el dato malo.
        $aplicado = $servicio->aplicarEnBrynex($correcciones, [
            'aliado_id'       => $aliadoId,
            'razon_social_id' => $registro->razon_social_id,
            'n_plano'         => $registro->n_plano,
            'mes'             => $registro->mes,
            'anio'            => $registro->anio,
            'plano_id'        => $registro->plano_id,
        ]);

        Log::info('Enlace API: planilla autocorregida', [
            'aliado_id'       => $aliadoId,
            'codigo_planilla' => $validated['codigo_planilla'],
            'correcciones'    => count($aplicado['aplicadas']),
            'planos'          => $aplicado['planos'],
            'contratos'       => $aplicado['contratos'],
            'clientes'        => $aplicado['clientes'],
        ]);

        if (!($resultado['liquidada'] ?? false)) {
            $registro->update([
                'estado'        => 'con_errores',
                'mensaje_error' => "Quedan {$resultado['total_errores']} error(es) que Enlace no puede corregir.",
                'response_log'  => $resultado['response'] ?? null,
            ]);

            return response()->json([
                'success'          => true,
                'liquidada'        => false,
                'codigo_planilla'  => $resultado['codigo_planilla'] ?? null,
                'total_errores'    => $resultado['total_errores'] ?? 0,
                'errores_cotizante'=> $resultado['errores_cotizante'] ?? [],
                'errores_empresa'  => $resultado['errores_empresa'] ?? [],
                'advertencias'     => $resultado['advertencias'] ?? [],
                'correcciones'     => $servicio->interpretar($resultado['errores_cotizante'] ?? [], $aliadoId),
                'aplicado'         => $aplicado,
                'message'          => "Quedan {$resultado['total_errores']} error(es) que Enlace no puede corregir.",
            ]);
        }

        // Quedó limpia: faltan los totales y la URL de pago, que la corrección
        // no devuelve.
        $totales = $sesion['api']->consultarTotales($resultado['numero_planilla']);
        $pago    = $sesion['api']->obtenerUrlPago($resultado['numero_planilla']);

        $registro->update([
            'estado'          => 'validada',
            'numero_planilla' => $resultado['numero_planilla'],
            'valor_total'     => $totales['total_pagar'] ?? null,
            'url_pago'        => $pago['url_pago'] ?? null,
            'mensaje_error'   => null,
            'response_log'    => $resultado['response'] ?? null,
        ]);

        return response()->json([
            'success'         => true,
            'liquidada'       => true,
            'numero_planilla' => $resultado['numero_planilla'],
            'codigo_planilla' => $resultado['codigo_planilla'] ?? null,
            'valor_total'     => $totales['total_pagar'] ?? null,
            'valor_mora'      => $totales['valor_mora'] ?? null,
            'fecha_limite'    => $totales['fecha_limite'] ?? null,
            'url_pago'        => $pago['url_pago'] ?? null,
            'advertencias'    => $resultado['advertencias'] ?? [],
            'aplicado'        => $aplicado,
            'message'         => "Planilla {$resultado['numero_planilla']} liquidada tras la autocorrección.",
        ]);
    }

    /**
     * Detalle paginado de inconsistencias de una planilla con errores.
     * La validación solo devuelve las primeras 100 líneas.
     */
    public function inconsistencias(Request $request, int $codigoPlanilla)
    {
        $aliadoId = session('aliado_id_activo');

        $validated = $request->validate([
            'razon_social_id'  => 'required|integer',
            'registro_inicial' => 'integer|min:0',
            'limite'           => 'integer|min:1|max:500',
        ]);

        // El código de planilla debe pertenecer a una liquidación del aliado.
        $registro = OperadorPlanillaApi::where('aliado_id', $aliadoId)
            ->where('razon_social_id', $validated['razon_social_id'])
            ->where('api_planilla_id', $codigoPlanilla)
            ->first();

        if (!$registro) {
            return response()->json(['success' => false, 'message' => 'Planilla no encontrada.'], 404);
        }

        // Las inconsistencias también exigen sesión + autorización.
        $sesion = $this->abrirSesion($registro, $aliadoId);

        if (!$sesion['success']) {
            return response()->json(['success' => false, 'message' => $sesion['message']], 422);
        }

        $api = $sesion['api'];

        $resultado = $api->consultarInconsistencias(
            $codigoPlanilla,
            (int) ($validated['registro_inicial'] ?? 0),
            (int) ($validated['limite'] ?? 100)
        );

        return response()->json($resultado, $resultado['success'] ? 200 : 422);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Deja lista una sesión de Enlace autorizada sobre el aportante dueño de
     * una liquidación ya registrada: login → aportante → autorización.
     *
     * El aportante es la razón social (NI) salvo en los independientes, donde
     * cada contratista liquida con su propia cédula (ver liquidarIndependiente).
     *
     * @return array{success: bool, api?: SuaporteApiService, message?: string}
     */
    private function abrirSesion(OperadorPlanillaApi $registro, int $aliadoId): array
    {
        $rs = RazonSocial::where('aliado_id', $aliadoId)->find($registro->razon_social_id);

        // En independientes el operador lo trae el contratista, no el pivot
        // del aliado — mismo criterio que liquidarIndependiente().
        $operador = ($rs?->es_independiente
                ? $this->operadoresApiIndependiente()
                : $this->operadoresConApi($aliadoId))
            ->firstWhere('id', (int) $registro->operador_planilla_id);

        if (!$rs || !$operador) {
            return ['success' => false, 'message' => 'Configuración incompleta para esta planilla.'];
        }

        $credencial = $this->credencial($aliadoId, $operador->id, (int) $rs->id);

        if (!$credencial) {
            return ['success' => false, 'message' => "Faltan credenciales de {$operador->nombre}."];
        }

        if ($credencial->claveSecretaVencida()) {
            return ['success' => false, 'message' => "La clave secreta de {$operador->nombre} venció."];
        }

        // Independiente: el aportante es el contratista, no la razón social.
        $tipoDocumento = 'NI';
        $documento     = preg_replace('/\D/', '', (string) $rs->nit);

        if ($registro->plano_id) {
            $plano = DB::table('planos')
                ->where('id', $registro->plano_id)
                ->where('aliado_id', $aliadoId)
                ->first(['no_identifi', 'tipo_doc']);

            if (!$plano) {
                return ['success' => false, 'message' => 'No se encontró el registro del contratista.'];
            }

            // PT (Permiso por Protección Temporal) es código PILA válido: no se traduce a CE
            $mapaDoc       = ['C' => 'CC', 'NIT' => 'CC', 'NUIP' => 'CC'];
            $doc           = strtoupper(trim($plano->tipo_doc ?? 'CC'));
            $tipoDocumento = $mapaDoc[$doc] ?? ($doc ?: 'CC');
            $documento     = preg_replace('/\D/', '', (string) $plano->no_identifi);
        }

        $api = new SuaporteApiService([
            'operador'      => $operador->codigo,
            'usuario'       => $credencial->usuario,
            'contrasena'    => $credencial->contrasena,
            'clave_secreta' => $credencial->clave_secreta,
        ]);

        $auth = $api->autenticar();
        if (!$auth['success']) {
            return ['success' => false, 'message' => $auth['message']];
        }

        $aportante = $api->consultarAportante($tipoDocumento, $documento);
        if (!$aportante['success']) {
            return ['success' => false, 'message' => $aportante['message']];
        }

        $autorizacion = $api->autorizar($aportante['id'], $tipoDocumento, $documento);
        if (!$autorizacion['success']) {
            return ['success' => false, 'message' => $autorizacion['message']];
        }

        return ['success' => true, 'api' => $api];
    }

    /**
     * Operadores del aliado que corren sobre la plataforma Enlace Operativo
     * (hoy ARUS Enlace y Simple, ver SuaporteApiService::HOSTS).
     *
     * Respeta el pivot `aliado_operadores_planilla`: qué operadores usa el
     * aliado para las planillas de sus empresas.
     */
    private function operadoresConApi(int $aliadoId)
    {
        return OperadorPlanilla::paraAliado($aliadoId)
            ->whereIn('codigo', array_keys(SuaporteApiService::HOSTS))
            ->get();
    }

    /**
     * Lo mismo, pero para independientes: ahí el operador lo trae cada
     * contratista (`clientes.operador_planilla_id`), no la configuración del
     * aliado, así que el pivot NO aplica. Es el mismo criterio con el que
     * PlanoPagoController arma `$operadoresApiIds` y habilita el botón PSE de
     * la fila; si aquí se filtrara por pivot, el botón se vería habilitado y
     * el POST respondería "operador no activo para este aliado".
     */
    private function operadoresApiIndependiente()
    {
        return OperadorPlanilla::whereNull('aliado_id')
            ->where('activo', true)
            ->whereIn('codigo', array_keys(SuaporteApiService::HOSTS))
            ->orderBy('orden')
            ->get();
    }

    /**
     * El filtro de modalidades, normalizado para poder compararlo: ids únicos,
     * ordenados y unidos por coma. Cadena vacía cuando no hay filtro.
     *
     * Se normaliza porque el mismo filtro puede llegar en cualquier orden
     * desde la interfaz, y `[12,0]` y `[0,12]` son la misma planilla.
     */
    /**
     * Los ids guardados en `tipos_modalidad` traducidos a nombres, para poder
     * decirle al usuario con qué modalidades se liquidó una planilla en vez de
     * mostrarle "-6,0,12". Cadena vacía = sin filtro, o sea todas.
     */
    private function nombresModalidades(?string $csv): string
    {
        if (trim((string) $csv) === '') {
            return 'todas las modalidades';
        }

        // La modalidad 0 es un id válido, así que no se puede descartar el cero
        // que devuelve intval(): el filtro se aplica sobre las cadenas.
        $ids = array_map('intval', array_filter(array_map('trim', explode(',', $csv)), 'strlen'));

        $nombres = DB::table('tipo_modalidad')->whereIn('id', $ids)
            ->orderByRaw('CHARINDEX(CAST(id AS VARCHAR(10)), ?)', [$csv])
            ->pluck('tipo_modalidad', 'id');

        // Un id que no esté en el catálogo se muestra crudo antes que perderse.
        return implode(', ', array_map(fn ($id) => $nombres[$id] ?? "#$id", $ids));
    }

    /**
     * La planilla del paso 1 de esta tanda que ya tiene el pago confirmado,
     * en cualquier operador, o null.
     */
    private function paso1Pagado(array $llave): ?OperadorPlanillaApi
    {
        $candidatas = OperadorPlanillaApi::where('aliado_id', $llave['aliado_id'])
            ->where('razon_social_id', $llave['razon_social_id'])
            ->where('anio', $llave['anio'])
            ->where('mes', $llave['mes'])
            ->where('n_plano', $llave['n_plano'])
            ->whereRaw("ISNULL(tipos_modalidad, '') = ?", [(string) $llave['tipos_modalidad']])
            ->where('paso', 1)
            ->where('estado', 'validada')
            ->whereNotNull('numero_planilla')
            ->orderByDesc('id')
            ->get();

        return $candidatas->first(fn ($f) => PlanillaE1Service::pagoConfirmado(
            (int) $llave['aliado_id'], (string) $f->numero_planilla
        ) !== null);
    }

    private function filtroModalidades(array $tipos): string
    {
        $tipos = array_values(array_unique(array_map('intval', $tipos)));
        sort($tipos);

        return implode(',', $tipos);
    }

    /** Credencial de la razón social, o la general del aliado. */
    /**
     * El paso 2 de los planes que venden salud: por el portal, no por la API.
     *
     * El archivo es el mismo que se manda por API —lo arma PlanoPilaTxtService
     * igual—, pero entra por la pantalla de carga del operador y se completa
     * con el botón "Corrección en línea", que es el único camino donde el
     * cotejo `eo.val.2.198` / `2.244` no aplica. Ver PortalCorreccionSaludService.
     *
     * Exige el pago confirmado del paso 1: el portal solo corrige planillas
     * pagadas, y de ahí sale la fecha del registro tipo 1.
     */
    private function correccionPorPortal(array $llave, int $aliadoId, bool $reliquidar = false)
    {
        // El robot crea una planilla nueva en el portal cada vez: si ya hay una
        // corrección, se pregunta antes (el mismo 409 del resto), y si ya está
        // pagada no se rehace.
        $previa = OperadorPlanillaApi::where($llave)->where('paso', 2)->where('estado', 'validada')->latest('id')->first();

        if ($previa && $previa->numero_planilla
            && PlanillaE1Service::pagoConfirmado($aliadoId, (string) $previa->numero_planilla)) {
            return response()->json([
                'success' => false,
                'message' => "La corrección {$previa->numero_planilla} ya está pagada. No hay nada que rehacer.",
            ], 422);
        }

        if ($previa && ! $reliquidar) {
            return response()->json([
                'success'               => false,
                'requiere_confirmacion' => true,
                'reemplaza'             => true,
                'numero_planilla'       => $previa->numero_planilla,
                'valor_total'           => $previa->valor_total,
                'fecha'                 => optional($previa->updated_at)->format('Y-m-d H:i'),
                'message'               => "Ya existe la corrección {$previa->numero_planilla}, pendiente de pago. "
                    .'Si la vuelve a liquidar, el robot crea otra planilla en el portal.',
            ], 409);
        }

        $contexto = PlanillaDosPasosService::contextoCorreccion($llave);

        if (! $contexto['ok']) {
            return response()->json(['success' => false, 'falta_pago' => true, 'message' => $contexto['message']], 422);
        }

        $paso1 = OperadorPlanillaApi::where($llave)->where('paso', 1)->where('estado', 'validada')->latest('id')->first();
        $pago = PlanillaE1Service::pagoConfirmado($aliadoId, (string) $paso1->numero_planilla);

        if (! $pago) {
            return response()->json([
                'success' => false,
                'falta_pago' => true,
                'message' => 'El portal solo deja corregir una planilla ya pagada. Confirme el pago del '
                    ."paso 1 (planilla {$paso1->numero_planilla}) y vuelva a intentarlo.",
            ], 422);
        }

        $resultado = (new \App\Services\PortalCorreccionSaludService())->liquidar(
            $llave,
            $paso1,
            $pago->fecha->format('Y-m-d')
        );

        if (! ($resultado['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'por_portal' => true,
                'message' => $resultado['message'] ?? 'El portal no liquidó la corrección.',
            ], 422);
        }

        $registro = OperadorPlanillaApi::find($resultado['registro_id'] ?? null);
        $urlPago = $registro ? $this->completarUrlPago($registro, $aliadoId) : null;

        return response()->json([
            'success' => true,
            'url_pago' => $urlPago,
            // Sin esto la pantalla toma el éxito por un archivo con errores
            // ("La planilla tiene undefined error(es)").
            'liquidada' => true,
            'por_portal' => true,
            'numero_planilla' => $resultado['numero_planilla'],
            'valor_total' => $resultado['valor_total'],
            'message' => "Corrección {$resultado['numero_planilla']} liquidada en el portal del operador.",
        ]);
    }

    /**
     * Le pide al API el enlace PSE de una planilla que se liquidó por fuera de
     * él (la corrección por portal) y lo guarda. Si el API no responde se
     * queda sin enlace: se paga desde el portal, como antes.
     */
    private function completarUrlPago(OperadorPlanillaApi $registro, int $aliadoId): ?string
    {
        try {
            $sesion = $this->abrirSesion($registro, $aliadoId);
            if (! $sesion['success']) {
                return null;
            }

            $pago = $sesion['api']->obtenerUrlPago((int) $registro->numero_planilla);
            if (! ($pago['success'] ?? false) || empty($pago['url_pago'])) {
                return null;
            }

            $registro->update(['url_pago' => $pago['url_pago']]);

            return $pago['url_pago'];
        } catch (\Throwable $e) {
            Log::warning('No se pudo obtener la URL de pago de la corrección', [
                'planilla' => $registro->numero_planilla,
                'error'    => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function credencial(int $aliadoId, int $operadorId, ?int $razonSocialId): ?OperadorCredencial
    {
        $propia = OperadorCredencial::paraOperador($aliadoId, $operadorId, $razonSocialId)->first();
        if ($propia) {
            return $propia;
        }

        // Sin credencial propia, un usuario de BryNex usa la de su aliado, pero
        // solo para las empresas de BryNex (la misma razón social, por NIT, en
        // su aliado): la planilla es del aportante, y su usuario ya está
        // autorizado sobre él. Para empresas ajenas no: autorizaría al usuario
        // de BryNex sobre el aportante de otro.
        $usuario = \Illuminate\Support\Facades\Auth::user();
        $casa    = (int) ($usuario?->aliado_id);
        if (! $usuario?->es_brynex || ! $razonSocialId || ! $casa || $casa === $aliadoId) {
            return null;
        }

        $nit = RazonSocial::whereKey($razonSocialId)->value('nit');
        $rsCasa = $nit ? RazonSocial::where('aliado_id', $casa)->where('nit', $nit)->value('id') : null;
        if (! $rsCasa) {
            return null;
        }

        return OperadorCredencial::paraOperador($casa, $operadorId, (int) $rsCasa)->first();
    }
}
