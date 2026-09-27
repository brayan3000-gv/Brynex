<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Anticipo;
use App\Models\BancoCuenta;
use App\Models\Cliente;
use App\Models\Consignacion;
use App\Models\Contrato;
use App\Models\Empresa;
use App\Models\Factura;
use App\Services\ReciboVisitaService;
use App\Services\VisitaCobroService;
use App\Services\WhatsappApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Cobro en visita: pantalla para el celular con la que se recorre una empresa
 * puesto por puesto, se busca al cliente, se ve lo que debe y se le cobra
 * (factura si alcanza, anticipo si no). El recibo le llega por WhatsApp.
 *
 * La lógica del cobro vive en VisitaCobroService; el recibo en
 * ReciboVisitaService.
 */
class VisitaCobroController extends Controller
{
    public function __construct(
        private VisitaCobroService $cobros,
        private ReciboVisitaService $recibos,
    ) {}

    public function index()
    {
        $aliadoId = (int) session('aliado_id_activo');

        return view('admin.visita.index', [
            'bancos' => BancoCuenta::paraFacturacion($aliadoId)->map(fn ($b) => [
                'id' => $b->id,
                'nombre' => trim($b->banco.' · '.$b->nombre),
            ])->values(),
            'empresas' => Empresa::where('aliado_id', $aliadoId)->where('id', '>', 1)
                ->orderBy('empresa')->get(['id', 'empresa'])
                ->map(fn ($e) => ['id' => $e->id, 'nombre' => $e->empresa])->values(),
            'hoy' => $this->resumenHoy($aliadoId),
        ]);
    }

    /** Clientes por nombre, cédula o celular; los de la empresa visitada primero. */
    public function buscar(Request $request)
    {
        $aliadoId = (int) session('aliado_id_activo');
        $texto = trim((string) $request->get('q', ''));
        $empresaId = (int) $request->get('empresa_id', 0);

        if (mb_strlen($texto) < 3 && ! $empresaId) {
            return response()->json(['clientes' => []]);
        }

        $q = Cliente::where('clientes.aliado_id', $aliadoId)
            ->select('clientes.id', 'clientes.cedula', 'clientes.cod_empresa', 'clientes.primer_nombre', 'clientes.segundo_nombre',
                'clientes.primer_apellido', 'clientes.segundo_apellido', 'clientes.celular')
            ->with('empresa:id,empresa');

        if ($texto !== '') {
            $q->where(function ($w) use ($texto) {
                $w->where('clientes.cedula', 'LIKE', "%{$texto}%")
                    ->orWhere('clientes.celular', 'LIKE', "%{$texto}%");
                if (! ctype_digit(str_replace(' ', '', $texto))) {
                    $w->orWherePalabrasSinTildes(
                        ['clientes.primer_nombre', 'clientes.segundo_nombre', 'clientes.primer_apellido', 'clientes.segundo_apellido'],
                        $texto
                    );
                }
            });
        } else {
            // Sin texto: la lista de la empresa visitada, solo con contrato vigente.
            $q->where('clientes.cod_empresa', $empresaId)
                ->whereExists(fn ($s) => $s->selectRaw('1')->from('contratos')
                    ->whereColumn('contratos.cedula', 'clientes.cedula')
                    ->where('contratos.aliado_id', $aliadoId)
                    ->where('contratos.estado', 'vigente'));
        }

        if ($empresaId) {
            $q->orderByRaw('CASE WHEN clientes.cod_empresa = ? THEN 0 ELSE 1 END', [$empresaId]);
        }
        $clientes = $q->orderBy('clientes.primer_nombre')->orderBy('clientes.primer_apellido')
            ->limit($texto !== '' ? 25 : 200)->get();

        $vigentes = Contrato::where('aliado_id', $aliadoId)
            ->whereIn('cedula', $clientes->pluck('cedula'))
            ->where('estado', 'vigente')
            ->selectRaw('cedula, COUNT(*) as n')->groupBy('cedula')->pluck('n', 'cedula');

        return response()->json(['clientes' => $clientes->map(fn ($c) => [
            'cedula' => (string) $c->cedula,
            'nombre' => $c->nombre_completo,
            'celular' => $c->celular,
            'empresa' => $c->empresa?->empresa,
            'de_la_empresa' => $empresaId && (int) $c->cod_empresa === $empresaId,
            'vigentes' => (int) ($vigentes[(string) $c->cedula] ?? 0),
        ])->values()]);
    }

    /** Ficha del cliente con lo que debe cada contrato vigente. */
    public function cliente(string $cedula)
    {
        $aliadoId = (int) session('aliado_id_activo');
        $cliente = Cliente::where('aliado_id', $aliadoId)->where('cedula', $cedula)->with('empresa:id,empresa')->firstOrFail();

        $contratos = Contrato::where('aliado_id', $aliadoId)
            ->where('cedula', $cedula)
            ->with(['razonSocial', 'tipoModalidad', 'plan', 'eps', 'arl', 'pension', 'caja', 'cliente', 'asesor'])
            ->orderByRaw("CASE WHEN estado = 'vigente' THEN 0 ELSE 1 END")
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'cliente' => [
                'cedula' => (string) $cliente->cedula,
                'nombre' => $cliente->nombre_completo,
                'celular' => $cliente->celular,
                'empresa' => $cliente->empresa?->empresa,
            ],
            'contratos' => $contratos->map(fn ($c) => $this->fichaContrato($c))->values(),
        ]);
    }

    public function cobrar(Request $request)
    {
        $aliadoId = (int) session('aliado_id_activo');

        $v = $request->validate([
            'contrato_id' => 'required|integer',
            'efectivo' => 'nullable|integer|min:0',
            'transferencia' => 'nullable|integer|min:0',
            'banco_cuenta_id' => 'nullable|integer',
            'referencia' => 'nullable|string|max:100',
            'foto' => 'nullable|file|mimes:jpg,jpeg,png,webp,heic,pdf|max:10240',
            'celular' => 'nullable|string|max:30',
            'nota' => 'nullable|string|max:200',
            'token' => 'required|string|max:64',
        ]);

        $contrato = Contrato::where('aliado_id', $aliadoId)->findOrFail($v['contrato_id']);
        $efectivo = (int) ($v['efectivo'] ?? 0);
        $transfer = (int) ($v['transferencia'] ?? 0);

        if ($transfer > 0 && ! BancoCuenta::where('aliado_id', $aliadoId)->whereKey($v['banco_cuenta_id'] ?? 0)->exists()) {
            return response()->json(['ok' => false, 'mensaje' => 'Escoge la cuenta a la que llegó la transferencia.'], 422);
        }

        // Un doble toque o un reintento con mala señal no puede cobrar dos
        // veces: el token lo genera la pantalla una vez por cobro.
        $claveToken = "visita-cobro:{$aliadoId}:".Auth::id().':'.$v['token'];
        if ($previo = Cache::get($claveToken)) {
            return response()->json($previo);
        }

        $candado = Cache::lock("visita-cobro-contrato:{$contrato->id}", 60);
        if (! $candado->get()) {
            return response()->json(['ok' => false, 'mensaje' => 'Ya se está registrando un cobro de este contrato. Espera un momento.'], 409);
        }

        try {
            if ($efectivo + $transfer <= 0) {
                $r = $this->cobros->resumen($contrato);
                if (! ($r['facturable'] && $r['falta'] === 0)) {
                    return response()->json(['ok' => false, 'mensaje' => 'Escribe cuánto recibiste.'], 422);
                }
            }

            $celular = $this->actualizarCelular($contrato, $v['celular'] ?? null);

            $resultado = $this->cobros->cobrar($contrato, [
                'efectivo' => $efectivo,
                'transferencia' => $transfer,
                'banco_cuenta_id' => $v['banco_cuenta_id'] ?? null,
                'referencia' => $v['referencia'] ?? null,
                'nota' => $v['nota'] ?? null,
            ], $request->file('foto'));

            $contrato->refresh();
            $respuesta = [
                'ok' => true,
                'mensaje' => $resultado['mensaje'],
                'factura_id' => $resultado['factura_id'],
                'anticipo_ids' => $resultado['anticipo_ids'],
                'celular' => $celular,
                'recibo_url' => route('admin.visita.recibo', array_filter([
                    'f' => $resultado['factura_id'],
                    'a' => $resultado['anticipo_ids'] ?: null,
                ])),
                'contrato' => $this->fichaContrato($contrato->load(['razonSocial', 'tipoModalidad', 'plan', 'cliente'])),
                'hoy' => $this->resumenHoy($aliadoId),
            ];
            Cache::put($claveToken, $respuesta, now()->addMinutes(30));

            return response()->json($respuesta);
        } finally {
            $candado->release();
        }
    }

    public function enviarRecibo(Request $request)
    {
        $aliadoId = (int) session('aliado_id_activo');
        $v = $request->validate([
            'factura_id' => 'nullable|integer',
            'anticipo_ids' => 'nullable|array',
            'anticipo_ids.*' => 'integer',
            'celular' => 'required|string|max:30',
        ]);

        if (empty($v['factura_id']) && empty($v['anticipo_ids'])) {
            return response()->json(['ok' => false, 'mensaje' => 'No hay recibo que enviar.'], 422);
        }

        $datos = $this->recibos->datos($aliadoId, $v['factura_id'] ?? null, $v['anticipo_ids'] ?? []);
        $r = $this->recibos->enviarWhatsapp($aliadoId, $v['celular'], $datos);

        return response()->json($r, $r['ok'] ? 200 : 422);
    }

    /** El PDF del recibo, para verlo o compartirlo desde el celular. */
    public function recibo(Request $request)
    {
        $aliadoId = (int) session('aliado_id_activo');
        $facturaId = $request->integer('f') ?: null;
        $anticipoIds = array_map('intval', (array) $request->get('a', []));
        abort_if(! $facturaId && ! $anticipoIds, 404);

        $datos = $this->recibos->datos($aliadoId, $facturaId, $anticipoIds);

        return response($this->recibos->pdf($datos), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->recibos->nombreArchivo($datos).'"',
        ]);
    }

    public function hoy()
    {
        return response()->json($this->resumenHoy((int) session('aliado_id_activo')));
    }

    private function fichaContrato(Contrato $c): array
    {
        $ficha = [
            'id' => $c->id,
            'estado' => $c->estado,
            'razon_social' => $c->razonSocial?->razon_social ?? '—',
            'modalidad' => $c->tipoModalidad?->observacion ?? '',
            'plan' => $c->plan?->nombre ?? '',
            'fecha_ingreso' => $c->fecha_ingreso?->format('d/m/Y'),
            'resumen' => null,
        ];

        if ($c->estado === 'vigente') {
            try {
                $ficha['resumen'] = $this->cobros->resumen($c);
            } catch (\Throwable $e) {
                report($e);
                $ficha['error'] = 'No se pudo calcular el cobro de este contrato.';
            }
        }

        return $ficha;
    }

    /**
     * Guarda en la ficha el celular que escribió quien cobra, si es válido y
     * cambió. Devuelve el que queda para mandar el recibo.
     */
    private function actualizarCelular(Contrato $contrato, ?string $celular): ?string
    {
        $cliente = Cliente::where('aliado_id', $contrato->aliado_id)->where('cedula', $contrato->cedula)->first();
        $celular = trim((string) $celular);

        if ($celular === '' || ! WhatsappApiService::esCelularColombiano($celular)) {
            return $cliente?->celular;
        }

        $nacional = WhatsappApiService::formatoNacional($celular);
        if ($cliente && $cliente->celular !== $nacional) {
            $cliente->update(['celular' => $nacional]);
        }

        return $nacional;
    }

    /**
     * La caja de hoy de quien cobra (la misma que suma el cuadre diario) y los
     * cobros que hizo desde esta pantalla.
     */
    private function resumenHoy(int $aliadoId): array
    {
        $usuarioId = Auth::id();
        $hoy = now()->toDateString();

        $facturas = Factura::where('aliado_id', $aliadoId)->where('usuario_id', $usuarioId)
            ->whereDate('fecha_pago', $hoy)->where('observacion', 'LIKE', 'Cobro en visita%')
            ->with('contrato.cliente')->orderByDesc('id')->get();
        $anticipos = Anticipo::where('aliado_id', $aliadoId)->where('usuario_id', $usuarioId)
            ->whereDate('fecha_pago', $hoy)->where('observacion', 'LIKE', 'Cobro en visita%')
            ->with('contrato.cliente')->orderByDesc('id')->get();

        $efectivo = (int) Factura::where('aliado_id', $aliadoId)->where('usuario_id', $usuarioId)
            ->whereDate('fecha_pago', $hoy)->where('es_prestamo', false)->sum('valor_efectivo')
            + (int) Anticipo::where('aliado_id', $aliadoId)->where('usuario_id', $usuarioId)
                ->whereDate('fecha_pago', $hoy)->whereIn('forma_pago', ['efectivo', 'nequi'])
                ->whereNotIn('estado', [Anticipo::ESTADO_DEVUELTO, Anticipo::ESTADO_DISTRIBUIDO])->sum('valor');
        $transferencias = (int) Consignacion::where('aliado_id', $aliadoId)->where('usuario_id', $usuarioId)
            ->whereDate('fecha', $hoy)->sum('valor');

        $movs = $facturas->map(fn ($f) => [
            'hora' => $f->created_at?->format('g:i a'),
            'nombre' => $f->contrato?->cliente?->nombre_corto ?: $f->cedula,
            'tipo' => 'Factura '.ReciboVisitaService::periodo((int) $f->mes, (int) $f->anio),
            'valor' => (int) $f->valor_efectivo + (int) $f->valor_consignado,
            'url' => route('admin.visita.recibo', ['f' => $f->id]),
            'orden' => $f->created_at?->timestamp,
        ])->concat($anticipos->map(fn ($a) => [
            'hora' => $a->created_at?->format('g:i a'),
            'nombre' => $a->contrato?->cliente?->nombre_corto ?: $a->cedula,
            'tipo' => 'Anticipo · '.(Anticipo::FORMAS_PAGO[$a->forma_pago] ?? $a->forma_pago),
            'valor' => (int) $a->valor,
            'url' => route('admin.visita.recibo', ['a' => [$a->id]]),
            'orden' => $a->created_at?->timestamp,
        ]))->sortByDesc('orden')->values();

        return [
            'efectivo' => $efectivo,
            'transferencias' => $transferencias,
            'cobros' => $movs->count(),
            'total_visita' => (int) $movs->sum('valor'),
            'movimientos' => $movs->take(40)->all(),
        ];
    }
}
