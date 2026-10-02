<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Contrato;
use App\Models\EmpresaAcceso;
use App\Models\Factura;
use App\Models\Incapacidad;
use App\Models\Plano;
use App\Services\EmpresaPeriodoService;
use App\Services\EnlaceInformeIndividualService;
use App\Services\PlanillaWhatsappService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

/**
 * El portal de una empresa cliente: solo lectura de sus trabajadores.
 *
 * Nada de lo que se consulta sale de la petición salvo el mes, el año y el
 * id de una planilla, y esa se comprueba contra las cédulas de la empresa.
 * El aliado y la empresa salen siempre del acceso autenticado.
 *
 * Los valores se calculan con EmpresaPeriodoService, el mismo que pinta
 * admin/facturacion/empresa/{id}: la empresa ve lo que el aliado le va a
 * cobrar, no una cuenta aparte.
 */
class PortalEmpresaController extends Controller
{
    public const MESES = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    /**
     * Los estados de la incapacidad en palabras de la empresa: «Pagada a
     * razón social» no le dice nada, «La entidad ya pagó» sí.
     */
    public const ESTADOS_INCAPACIDAD = [
        'recibido' => 'Recibida',
        'transcripcion_ips' => 'En transcripción',
        'transcripcion' => 'En transcripción',
        'radicada' => 'Radicada ante la entidad',
        'negada' => 'Negada por la entidad',
        'derecho_peticion' => 'Derecho de petición',
        'derecho_peticion_radicado' => 'Derecho de petición radicado',
        'tutela' => 'En tutela',
        'tutela_radicada' => 'Tutela radicada',
        'rechazado' => 'Rechazada',
        'en_liquidacion' => 'En liquidación',
        'liquidacion' => 'En liquidación',
        'pagada_razon_social' => 'La entidad ya pagó',
        'pagada_afiliado' => 'Pagada',
        'pagado_afiliado' => 'Pagada',
        'pagada' => 'Pagada',
        'cierre_exitoso' => 'Cerrada',
        'anulada' => 'Anulada',
    ];

    public function __construct(
        private EmpresaPeriodoService $periodo,
    ) {}

    // ─── Mes: trabajadores, qué se facturó, qué falta y la PILA ──────────

    public function inicio(Request $request)
    {
        $acceso = $this->acceso();
        [$mes, $anio] = $this->mesAnio($request);

        $datos = $this->periodo->datosPeriodo($acceso->empresa_id, $mes, $anio, $acceso->aliado_id);

        $planillas = $this->sinLotesAjenos(
            $acceso,
            app(PlanillaWhatsappService::class)
                ->obtenerPlanosPagados($acceso->aliado_id, $mes, $anio, $this->subconsultaCedulas($acceso))
        )->groupBy('cedula');

        // El dependiente cotiza a la ARL de su razón social, no a una propia:
        // cuando el contrato no la trae, se muestra la que dice el plano PILA.
        $arlsPorNit = \App\Models\Arl::get(['nit', 'nombre_arl'])
            ->mapWithKeys(fn ($a) => [(string) $a->nit => $a->nombre_arl]);

        $idsVisibles = $datos['contratos']->pluck('id')->map(fn ($id) => (int) $id)->flip();

        $filas = $datos['contratos']
            ->map(fn (Contrato $c) => $this->fila(
                $c,
                $this->periodo->valoresFila($c, $mes, $anio, $datos['moraPorContrato']),
                $this->planillasDelContrato($c, $planillas->get($c->cedula, collect()), $idsVisibles),
                $arlsPorNit
            ))
            ->values();

        $porFacturar = $filas->where('grupo', 'por_facturar');
        $facturados = $filas->where('grupo', 'facturado');

        // Personas, no contratos: alguien puede tener dos en el mismo mes.
        $trabajadores = $filas->where('retirado', false)->pluck('cedula')->unique()->count();
        // No se compara contra «quién cotiza»: la afiliación del mes se cobra
        // ahora y su PILA puede pagarse ya o el mes siguiente, según la modalidad.
        $conPila = $filas->filter(fn ($f) => count($f['planillas']) > 0)->pluck('cedula')->unique()->count();

        $resumen = [
            'trabajadores' => $trabajadores,
            'por_facturar_n' => $porFacturar->count(),
            'por_facturar_valor' => $porFacturar->sum(fn ($f) => $f['total'] + $f['mora']),
            'facturado_n' => $facturados->count(),
            'facturado_valor' => $facturados->sum('total'),
            'pagado_n' => $facturados->where('factura_estado', 'pagada')->count(),
            'pila_pagadas' => $conPila,
            'pila_total' => max($trabajadores, $conPila),
            'saldo_pendiente' => (int) $datos['saldoEmpresaPendiente'],
            'saldo_favor' => (int) $datos['saldoEmpresaFavor'],
        ];

        return view('portal.inicio', [
            'empresa' => $datos['empresa'],
            'filas' => $filas,
            'resumen' => $resumen,
            'mes' => $mes,
            'anio' => $anio,
            'discriminado' => $acceso->ver_discriminado,
            'hayIva' => $filas->sum('iva') > 0,
            'hayParaf' => $filas->sum('parafiscales') > 0,
            'hayOtros' => $filas->sum('otros') > 0,
        ]);
    }

    /**
     * El PDF de la planilla pagada de un trabajador: el del operador si se
     * puede y si no el que arma BryNex, igual que la descarga del panel y el
     * envío por WhatsApp.
     */
    public function planilla(int $planoId)
    {
        $acceso = $this->acceso();

        $plano = Plano::where('aliado_id', $acceso->aliado_id)
            ->whereKey($planoId)
            ->whereNull('deleted_at')
            ->whereNotNull('numero_planilla')
            ->where('numero_planilla', '!=', '')
            ->whereIn('no_identifi', $this->subconsultaCedulas($acceso))
            // La de un lote de otro cliente no: es de cuando el trabajador
            // estaba con otra empresa (ver sinLotesAjenos()).
            ->where(fn ($q) => $q->whereNull('factura_id')
                ->orWhereIn('factura_id', $this->subconsultaFacturasPropias($acceso)))
            ->firstOrFail();

        // Mes en que se pagó: el del plano si es mes actual, el siguiente si
        // es mes vencido (ver Plano::filtrarPeriodoDePago). Con eso se reusa la
        // consulta de planillas pagadas, que es la que sabe qué operador fue.
        $servicio = Carbon::create((int) $plano->anio_plano, (int) $plano->mes_plano, 1);
        $pago = $plano->paga_mes_actual ? $servicio : $servicio->copy()->addMonth();

        $pagada = app(PlanillaWhatsappService::class)
            ->obtenerPlanosPagados($acceso->aliado_id, $pago->month, $pago->year, [$plano->no_identifi])
            ->firstWhere('id', $plano->id);

        // Con número pero sin el pago registrado: todavía no hay soporte.
        abort_unless($pagada, 404);
        // Solo la original del operador (Simple, ARUS o Mi Planilla con la clave
        // de la persona), nunca la copia que arma BryNex: la empresa la usa
        // como soporte ante terceros.
        if (! $pagada->es_operador_autorizado) {
            return $this->planillaNoDisponible($plano->numero_planilla, false);
        }

        try {
            $soporte = app(EnlaceInformeIndividualService::class)
                ->conTope(15)
                ->soporteOriginal($plano, $pagada->operador_id);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Portal empresas: no salió la planilla original', [
                'plano_id' => $plano->id,
                'planilla' => $plano->numero_planilla,
                'error'    => $e->getMessage(),
            ]);

            return $this->planillaNoDisponible($plano->numero_planilla, true);
        }

        $nombre = PlanillaWhatsappService::generarNombreArchivoPdf(
            trim("{$plano->primer_nombre} {$plano->segundo_nombre} {$plano->primer_ape} {$plano->segundo_ape}"),
            $pago->month,
            $pago->year
        );

        return response($soporte['pdf'])
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', "inline; filename=\"{$nombre}\"");
    }

    /**
     * Lo que ve la empresa cuando la original no se puede entregar. Sin
     * detalles internos (credenciales, operador): solo qué hacer.
     */
    private function planillaNoDisponible(string $numero, bool $reintentar)
    {
        $texto = $reintentar
            ? 'No pudimos traer en este momento la planilla original del operador. Intenta de nuevo en unos minutos; si sigue igual, escríbenos y te la enviamos.'
            : 'La planilla original de este pago no se puede descargar desde el portal. Escríbenos y te la enviamos.';

        return response(
            '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Planilla '.e($numero).'</title>'
            .'<body style="font-family:system-ui,sans-serif;background:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:90vh;margin:0;padding:16px">'
            .'<div style="max-width:460px;background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:1.4rem 1.6rem;box-shadow:0 8px 24px rgba(0,0,0,.06)">'
            .'<div style="font-weight:800;color:#0f172a;margin-bottom:.5rem">Planilla '.e($numero).'</div>'
            .'<div style="color:#334155;font-size:.95rem;line-height:1.5">'.e($texto).'</div></div></body>',
            $reintentar ? 503 : 404
        )->header('Content-Type', 'text/html; charset=utf-8');
    }

    // ─── Facturas y saldo ────────────────────────────────────────────────

    public function facturas()
    {
        $acceso = $this->acceso();

        // Las de lote de la empresa y las individuales de sus trabajadores: la
        // empresa ve lo que se les cobra aunque se facture a cada uno. Las de
        // lote de otro cliente no (de cuando la persona estaba en otra empresa).
        $facturas = Factura::where('aliado_id', $acceso->aliado_id)
            ->whereIn('id', $this->subconsultaFacturasPropias($acceso))
            ->where('numero_factura', '>', 0)
            ->orderByDesc('anio')
            ->orderByDesc('mes')
            ->orderByDesc('id')
            ->get(['id', 'numero_factura', 'mes', 'anio', 'fecha_pago', 'estado', 'tipo', 'total', 'mora', 'cedula', 'empresa_id']);

        $nombres = $this->nombres($acceso, $facturas->whereNull('empresa_id')->pluck('cedula'));

        // Un lote es una fila por trabajador con el mismo número. El número
        // solo no identifica una factura (se repite entre años en lo que vino
        // del sistema viejo): se agrupa por número y período.
        $grupos = $facturas
            ->groupBy(fn ($f) => $f->empresa_id
                ? "L{$f->numero_factura}-{$f->anio}-{$f->mes}"
                : "I{$f->id}")
            ->map(function ($g) use ($nombres) {
                $f = $g->first();

                return [
                    'numero' => $f->numero_factura,
                    'mes' => (int) $f->mes,
                    'anio' => (int) $f->anio,
                    'fecha_pago' => $f->fecha_pago,
                    'estado' => $f->estado,
                    'total' => (int) $g->sum('total'),
                    'personas' => $g->pluck('cedula')->unique()->count(),
                    // Individual: a nombre de un trabajador, no del lote.
                    'individual' => $f->empresa_id ? null : ($nombres[$f->cedula] ?? $f->cedula),
                ];
            })->values();

        $porMes = $grupos->groupBy(fn ($g) => $g['anio'] * 100 + $g['mes'])
            ->map(fn ($gs) => [
                'mes' => $gs->first()['mes'],
                'anio' => $gs->first()['anio'],
                'total' => $gs->sum('total'),
                'facturas' => $gs->values(),
            ])
            ->sortKeysDesc()
            ->values();

        return view('portal.facturas', [
            'porMes' => $porMes,
            'saldo' => $this->periodo->saldoEmpresa($acceso->aliado_id, $acceso->empresa_id),
        ]);
    }

    // ─── Incapacidades ───────────────────────────────────────────────────

    public function incapacidades()
    {
        $acceso = $this->acceso();

        $incapacidades = Incapacidad::where('aliado_id', $acceso->aliado_id)
            ->whereIn('cedula_usuario', $this->subconsultaCedulas($acceso))
            // Las anuladas por error o duplicadas nunca existieron para la empresa.
            ->where(fn ($q) => $q->where('estado', '!=', 'anulada')
                ->orWhereNull('estado')
                ->orWhereNull('motivo_anulacion')
                ->orWhereNotIn('motivo_anulacion', ['creada_por_error', 'duplicada']))
            ->with('abonos')
            ->orderByDesc('fecha_inicio')
            ->orderByDesc('id')
            ->get();

        $nombres = $this->nombres($acceso, $incapacidades->pluck('cedula_usuario'));

        // El diagnóstico no sale: es dato de salud del trabajador, y la
        // empresa no lo necesita para saber en qué va el pago.
        $filas = $incapacidades->map(function (Incapacidad $i) use ($nombres) {
            $grupo = $this->grupoIncapacidad($i);
            $entregado = (int) $i->total_pago_cliente + (int) $i->total_prestado;
            $directo = (int) $i->abonos->where('tipo', 'pago_directo_entidad')->sum('valor');

            return [
                'nombre' => $nombres[$i->cedula_usuario] ?? $i->cedula_usuario,
                'cedula' => $i->cedula_usuario,
                'tipo' => Incapacidad::TIPOS_INCAPACIDAD[$i->tipo_incapacidad] ?? ucfirst(str_replace('_', ' ', (string) $i->tipo_incapacidad)),
                'prorroga' => (int) ($i->numero_proroga ?? 0),
                'inicio' => $i->fecha_inicio,
                'fin' => $i->fecha_terminacion,
                'dias' => (int) $i->dias_incapacidad,
                'entidad' => $i->entidad_nombre ?: strtoupper((string) $i->tipo_entidad),
                'estado' => $i->estado,
                'estado_label' => self::ESTADOS_INCAPACIDAD[$i->estado] ?? ucfirst(str_replace('_', ' ', (string) $i->estado)),
                'grupo' => $grupo,
                'radicado' => $i->fecha_radicado,
                'esperado' => (int) $i->valor_esperado,
                'pago_entidad' => (int) $i->total_pago_eps + $directo,
                // Lo que ya le llegó: el pago y el anticipo que le dio el aliado.
                'entregado' => $entregado,
                'anticipado' => (int) $i->total_prestado,
                // Lo que le falta recibir. No es el saldo_pendiente del sistema,
                // que se da por saldado cuando la EPS le paga a la razón social:
                // para la empresa esa plata todavía no ha llegado.
                'por_recibir' => $grupo === 'tramite'
                    ? max(0, (int) $i->valor_esperado - $entregado - $directo)
                    : 0,
            ];
        });

        return view('portal.incapacidades', ['filas' => $filas]);
    }

    // ─── Retirados ───────────────────────────────────────────────────────

    public function retirados()
    {
        $acceso = $this->acceso();

        // Quien hoy tiene contrato no es un retirado, aunque tenga retiros viejos.
        $activos = Contrato::where('aliado_id', $acceso->aliado_id)
            ->whereIn('cedula', $this->subconsultaCedulas($acceso))
            ->whereIn('estado', ['vigente', 'activo'])
            ->pluck('cedula');

        $retirados = $this->periodo
            ->retiradosPrevios($acceso->empresa_id, $acceso->aliado_id, $activos)
            ->map(fn ($r) => [
                'tipo_doc' => $r->tipo_doc,
                'cedula' => $r->cedula,
                'nombre' => trim(($r->primer_nombre ?? '').' '.($r->primer_apellido ?? '')) ?: '—',
                'razon_social' => $r->razon_social,
                'ingreso' => $r->fecha_ingreso ? Carbon::parse($r->fecha_ingreso) : null,
                'retiro' => $r->fecha_retiro ? Carbon::parse($r->fecha_retiro) : null,
            ]);

        return view('portal.retirados', ['retirados' => $retirados]);
    }

    // ─── Clave ───────────────────────────────────────────────────────────

    public function clave()
    {
        return view('portal.clave');
    }

    public function guardarClave(Request $request)
    {
        $acceso = $this->acceso();

        $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [
            'password.confirmed' => 'Las dos claves no coinciden.',
        ], ['password' => 'clave']);

        if (password_verify($request->password, $acceso->password)) {
            return back()->withErrors(['password' => 'Escoge una clave distinta a la temporal.']);
        }

        $acceso->forceFill([
            'password' => $request->password,
            'debe_cambiar_clave' => false,
        ])->save();

        // auth.session compara la clave con la que quedó en la sesión: sin
        // esto, la empresa se saldría sola por haber cambiado su propia clave.
        $request->session()->put('password_hash_empresa', $acceso->getAuthPassword());

        return redirect()->route('portal.inicio')->with('ok', 'Listo, tu clave quedó guardada.');
    }

    public function salir(Request $request)
    {
        Auth::guard('empresa')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    // ─── Apoyo ───────────────────────────────────────────────────────────

    private function acceso(): EmpresaAcceso
    {
        return Auth::guard('empresa')->user();
    }

    /** @return array{0:int,1:int} */
    private function mesAnio(Request $request): array
    {
        $mes = (int) $request->query('mes', now()->month);
        $anio = (int) $request->query('anio', now()->year);

        if ($mes < 1 || $mes > 12 || $anio < 2020 || $anio > now()->year + 1) {
            return [now()->month, now()->year];
        }

        return [$mes, $anio];
    }

    /**
     * Las cédulas de la empresa como subconsulta: con miles de trabajadores
     * una lista se pasaría del tope de 2100 parámetros de SQL Server.
     */
    private function subconsultaCedulas(EmpresaAcceso $acceso): \Closure
    {
        return fn ($q) => $q->select('cedula')->from('clientes')
            ->where('aliado_id', $acceso->aliado_id)
            ->where('cod_empresa', $acceso->empresa_id);
    }

    /**
     * Ids de las facturas que la empresa puede ver: las de su lote y las
     * individuales (sin empresa) de sus trabajadores.
     */
    private function subconsultaFacturasPropias(EmpresaAcceso $acceso): \Closure
    {
        return fn ($q) => $q->select('id')->from('facturas')
            ->where('aliado_id', $acceso->aliado_id)
            ->whereNull('deleted_at')
            ->where(fn ($w) => $w->where('empresa_id', $acceso->empresa_id)
                ->orWhere(fn ($i) => $i->whereNull('empresa_id')
                    ->whereIn('cedula', $this->subconsultaCedulas($acceso))));
    }

    /**
     * Quita las planillas que salieron del lote de otro cliente: la persona
     * trabajó antes con otra empresa del mismo aliado y esa planilla es de
     * esa relación, no de esta. Las que no tienen factura se quedan.
     */
    private function sinLotesAjenos(EmpresaAcceso $acceso, Collection $planos): Collection
    {
        $facturaIds = $planos->pluck('factura_id')->filter()->unique()->values();
        if ($facturaIds->isEmpty()) {
            return $planos;
        }

        $ajenas = collect();
        foreach ($facturaIds->chunk(1000) as $lote) {
            $ajenas = $ajenas->merge(DB::table('facturas')
                ->whereIn('id', $lote->all())
                ->whereNotNull('empresa_id')
                ->where('empresa_id', '!=', $acceso->empresa_id)
                ->pluck('id'));
        }
        $ajenas = $ajenas->map(fn ($id) => (int) $id)->flip();

        return $planos->reject(fn ($p) => $p->factura_id && $ajenas->has((int) $p->factura_id))->values();
    }

    /** Nombre completo por cédula, solo de trabajadores de la empresa. */
    private function nombres(EmpresaAcceso $acceso, Collection $cedulas): array
    {
        if ($cedulas->isEmpty()) {
            return [];
        }

        return DB::table('clientes')
            ->where('aliado_id', $acceso->aliado_id)
            ->where('cod_empresa', $acceso->empresa_id)
            ->whereIn('cedula', $cedulas->unique()->values()->all())
            ->get(['cedula', 'primer_nombre', 'segundo_nombre', 'primer_apellido', 'segundo_apellido'])
            ->mapWithKeys(fn ($c) => [$c->cedula => $this->nombreCompleto($c)])
            ->all();
    }

    private function nombreCompleto($c): string
    {
        return trim(preg_replace('/\s+/', ' ', implode(' ', [
            $c->primer_nombre ?? '', $c->segundo_nombre ?? '', $c->primer_apellido ?? '', $c->segundo_apellido ?? '',
        ])));
    }

    /**
     * Las planillas de esa persona que van en la fila de este contrato.
     *
     * Cada una va con su contrato si ese contrato está en la lista del mes;
     * si no está (quien reingresó y pagó la planilla del contrato anterior) o
     * no trae contrato, va con la persona. Así nadie ve la misma planilla en
     * dos filas por tener dos contratos, y ninguna se pierde.
     */
    private function planillasDelContrato(Contrato $c, Collection $deLaPersona, Collection $idsVisibles): Collection
    {
        return $deLaPersona->filter(function ($p) use ($c, $idsVisibles) {
            $contrato = (int) $p->contrato_id;

            return $contrato === (int) $c->id || ! $idsVisibles->has($contrato);
        })->values();
    }

    private function grupoIncapacidad(Incapacidad $i): string
    {
        return match (true) {
            in_array($i->estado, ['pagada_afiliado', 'pagado_afiliado', 'cierre_exitoso', 'pagada'], true) => 'cerrada',
            in_array($i->estado, ['rechazado', 'anulada'], true) => 'cerrada',
            default => 'tramite',
        };
    }

    /**
     * Una fila del mes lista para la vista. Recibe los valores ya calculados
     * por EmpresaPeriodoService::valoresFila(); aquí solo se ordenan.
     */
    private function fila(Contrato $c, array $v, Collection $planillas, Collection $arlsPorNit): array
    {
        $fact = $v['fact'];
        $facturado = $fact && (int) $fact->numero_factura !== 0;
        $total = (int) $v['vTot'];
        $ss = (int) $v['vEps'] + (int) $v['vArl'] + (int) $v['vPen'] + (int) $v['vCaja'];
        // La factura ya trae la mora dentro del total (aunque esté pagada, que
        // es cuando el panel la oculta). Lo que falta por facturar la suma aparte.
        $mora = $facturado ? (int) ($fact->mora ?? 0) : (int) $v['vMora'];

        $grupo = match (true) {
            $facturado => 'facturado',
            $total > 0 => 'por_facturar',
            default => 'sin_cobro',
        };

        $estado = match (true) {
            $v['tieneRetiroPendiente'] => 'Retiro en proceso',
            $v['esRetirado'] => 'Retirado',
            $v['esIngresoFuturo'] => 'Ingresa más adelante',
            $v['esAfil'] => 'Afiliación',
            default => 'Activo',
        };

        return [
            'contrato_id' => $c->id,
            'cedula' => $c->cedula,
            'tipo_doc' => $c->cliente?->tipo_doc,
            'nombre' => $c->cliente ? $this->nombreCompleto($c->cliente) : $c->cedula,
            'plan' => $c->plan?->nombre,
            'modalidad' => $c->tipoModalidad?->nombre,
            'razon_social' => $c->razonSocial?->razon_social,
            'cargo' => $c->cargo,
            'eps' => $c->eps?->nombre,
            'afp' => $c->pension?->razon_social,
            'arl' => $c->arl?->nombre_arl
                ?? $arlsPorNit->get((string) $c->razonSocial?->arl_nit),
            'nivel_arl' => $c->n_arl,
            'caja' => $c->caja?->nombre,
            'ingreso' => $c->fecha_ingreso,
            'retiro' => $v['esRetirado'] ? $c->fecha_retiro : ($v['tieneRetiroPendiente'] ? $c->fecha_retiro_pendiente : null),
            'retirado' => (bool) $v['esRetirado'],
            'estado' => $estado,
            'dias' => (int) $v['dias'],
            'cotiza' => $ss > 0,
            // Valores
            'eps_v' => (int) $v['vEps'],
            'arl_v' => (int) $v['vArl'],
            'afp_v' => (int) $v['vPen'],
            'caja_v' => (int) $v['vCaja'],
            // Con retiro pendiente la SS se cotiza por los días del retiro, pero
            // vParaf viene del mes completo: se toma la de esos días para que
            // el desglose sume lo mismo que el total.
            'parafiscales' => ($v['tieneRetiroPendiente'] && ! $facturado && $v['cotizRetPend'])
                ? (int) ($v['cotizRetPend']['parafiscales'] ?? 0)
                : (int) $v['vParaf'],
            'admon' => (int) $v['vAdm'],
            'iva' => (int) $v['vIva'],
            // Afiliación y seguro: lo que queda del total fuera de SS, admón, IVA
            // y la mora ya facturada.
            'otros' => max(0, $total - (int) $v['vSS'] - (int) $v['vAdm'] - (int) $v['vIva'] - ($facturado ? $mora : 0)),
            'total' => $total,
            'mora' => $mora,
            // En lo facturado la mora ya va dentro del total; en lo que falta
            // por facturar se suma aparte (el estimado no la trae).
            'mora_incluida' => $facturado,
            // Factura
            'grupo' => $grupo,
            'factura_estado' => $facturado ? $fact->estado : null,
            'factura_numero' => $facturado ? $fact->numero_factura : null,
            'factura_fecha' => $facturado ? $fact->fecha_pago : null,
            // PILA
            'planillas' => $planillas->map(fn ($p) => [
                'id' => $p->id,
                'numero' => $p->numero_planilla,
                'fecha' => $p->fecha_pago ? Carbon::parse($p->fecha_pago) : null,
                'operador' => $p->operador_nombre,
            ])->values()->all(),
        ];
    }
}
