<?php

namespace App\Services;

use App\Exceptions\PagoIncompletoException;
use App\Http\Controllers\Admin\FacturacionController;
use App\Models\Anticipo;
use App\Models\Consignacion;
use App\Models\Contrato;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cobro en visita: lo que se cobra puesto por puesto desde el celular.
 *
 * No tiene cálculo propio. Lo que el contrato debe sale de las mismas piezas
 * que usa el facturador individual (mesPagado + CobroContratoService), y la
 * factura se crea llamando a FacturacionController::facturar con el mismo
 * cuerpo que arma el modal. Así no hay dos lógicas que puedan descuadrarse.
 *
 * La regla del cobro (decidida con el dueño, 27-sep-2026):
 *   - Si lo que paga más lo que ya tenía (anticipos y saldo a favor) cubre el
 *     mes pendiente, se factura ese mes. Lo que sobre del efectivo queda como
 *     anticipo para el siguiente — nunca se facturan varios meses.
 *   - Si no alcanza, todo queda como anticipo.
 *   - La mora se cobra tal cual la calcula el sistema: quien cobra en la
 *     calle no puede quitarla.
 *   - No hay caja aparte: todo cae en la caja del día del usuario (el cuadre
 *     une por usuario_id + fecha).
 */
class VisitaCobroService
{
    /**
     * Lo que el contrato debe hoy y con qué lo puede pagar.
     */
    public function resumen(Contrato $contrato): array
    {
        $aliadoId = (int) $contrato->aliado_id;
        [$mes, $anio, $mp] = $this->periodoPendiente($contrato);

        $calc = CobroContratoService::calcular($contrato, $mes, $anio);

        $mora = (int) ($mp['mora_cliente'] ?? 0);
        $totalMes = (int) $calc['total'] - (int) $calc['mora'] + $mora;
        $saldoFavor = (int) ($mp['saldo_a_favor'] ?? 0);
        // Los de la cédula en cualquier contrato (ver Anticipo::disponiblesParaContrato).
        $anticipos = Anticipo::disponiblesParaContrato($aliadoId, $contrato->id);
        $totalAnticipos = (int) $anticipos->sum('valor_disponible');
        $falta = max(0, $totalMes - $saldoFavor - $totalAnticipos);

        // Solo se factura desde la calle el cobro mensual corriente. El mes de
        // afiliación pide repartir comisiones y escoger modo (afiliación sola o
        // con planilla), y un hueco de meses sin facturar hay que resolverlo en
        // la oficina: en esos casos la plata entra como anticipo.
        $motivoNoFactura = null;
        $esPrimerMes = $contrato->fecha_ingreso
            && (int) $contrato->fecha_ingreso->month === $mes
            && (int) $contrato->fecha_ingreso->year === $anio;
        $iniciaDespues = $contrato->fecha_ingreso
            && ((int) $contrato->fecha_ingreso->year * 100 + (int) $contrato->fecha_ingreso->month) > ($anio * 100 + $mes);
        if ($iniciaDespues) {
            // Antes del mes de ingreso no hay seguridad social que cobrar: la
            // factura saldría solo con administración.
            $motivoNoFactura = 'El contrato inicia en '.ReciboVisitaService::periodo(
                (int) $contrato->fecha_ingreso->month, (int) $contrato->fecha_ingreso->year).': se factura en la oficina.';
        } elseif (! empty($mp['gap_bloquea'])) {
            $motivoNoFactura = $mp['gap_mensaje'] ?: 'Tiene meses anteriores sin facturar.';
        } elseif ($calc['tipo'] === 'afiliacion' || ($esPrimerMes && ! $contrato->esSoloSeguro())) {
            $motivoNoFactura = 'Es el mes de afiliación: se factura en la oficina.';
        } elseif ($totalMes <= 0) {
            $motivoNoFactura = 'Este mes no tiene valor a cobrar.';
        } elseif ($contrato->estado !== 'vigente') {
            $motivoNoFactura = 'El contrato está '.$contrato->estado.'.';
        }

        return [
            'contrato_id' => $contrato->id,
            'mes' => $mes,
            'anio' => $anio,
            'periodo' => ReciboVisitaService::periodo($mes, $anio),
            'tipo' => $calc['tipo'],
            'desglose' => [
                'ss' => (int) $calc['ss'],
                'admon' => (int) $calc['admon'],
                'seguro' => (int) $calc['seguro'],
                'afiliacion' => (int) $calc['afiliacion'],
                'iva' => (int) $calc['iva'],
                'mora' => $mora,
            ],
            'dias' => (int) $calc['dias'],
            'mora_info' => $mp['mora_info'] ?? '',
            'total_mes' => $totalMes,
            'saldo_favor' => $saldoFavor,
            'anticipos' => $totalAnticipos,
            'anticipo_ids' => $anticipos->pluck('id')->all(),
            // Cada anticipo con su recibo: el cliente suele preguntar cuáles
            // fueron cuando tiene dos o tres.
            'anticipos_detalle' => $this->detalleAnticipos($anticipos),
            'falta' => $falta,
            'facturable' => $motivoNoFactura === null,
            'motivo_no_factura' => $motivoNoFactura,
            'cartera' => (int) collect($mp['prestamos_pendientes'] ?? [])->sum('saldo'),
        ];
    }

    /**
     * Los anticipos con saldo de una persona, en todos sus contratos. El
     * anticipo es de quien pagó (la cédula), no del contrato: quien cobra
     * tiene que verlos todos antes de escoger contrato.
     */
    public function anticiposDeCedula(int $aliadoId, string $cedula): array
    {
        $anticipos = Anticipo::aliado($aliadoId)->conSaldo()
            ->where('cedula', $cedula)
            ->where('estado', '!=', Anticipo::ESTADO_DISTRIBUIDO)
            ->with('contrato.razonSocial')
            ->orderBy('fecha_pago')->get();

        return $this->detalleAnticipos($anticipos);
    }

    /**
     * Los anticipos con saldo que pagó la empresa (por NIT) y todavía no se
     * han repartido a ningún contrato.
     */
    public function anticiposDeEmpresa(int $aliadoId, int $empresaId): array
    {
        $anticipos = Anticipo::aliado($aliadoId)->conSaldo()
            ->where('empresa_id', $empresaId)
            ->whereNull('contrato_id')
            ->where('estado', '!=', Anticipo::ESTADO_DISTRIBUIDO)
            ->orderBy('fecha_pago')->get();

        return $this->detalleAnticipos($anticipos);
    }

    /** Cada anticipo con lo que el cliente pregunta: cuándo, cómo, quién y su recibo. */
    private function detalleAnticipos($anticipos): array
    {
        return $anticipos->load('usuario:id,nombre')->map(fn ($a) => [
            'id' => $a->id,
            'fecha' => $a->fecha_pago?->format('d/m/Y'),
            'forma' => Anticipo::FORMAS_PAGO[$a->forma_pago] ?? $a->forma_pago,
            'valor' => (int) $a->valor,
            'disponible' => (int) $a->valor_disponible,
            'recibio' => $a->usuario?->nombre,
            'contrato' => $a->contrato_id ? ($a->contrato?->razonSocial?->razon_social ?? 'Contrato '.$a->contrato_id) : null,
            'recibo_url' => route('admin.visita.recibo', ['a' => [$a->id]]),
        ])->values()->all();
    }

    /**
     * Registra lo que el cliente pagó en el puesto.
     *
     * @param  array{efectivo:int, transferencia:int, banco_cuenta_id:?int, referencia:?string, nota:?string}  $pago
     * @return array{factura_id:?int, anticipo_ids:int[], mensaje:string}
     */
    public function cobrar(Contrato $contrato, array $pago, ?UploadedFile $foto = null): array
    {
        $efectivo = max(0, (int) $pago['efectivo']);
        $transfer = max(0, (int) $pago['transferencia']);
        $r = $this->resumen($contrato);

        $facturaId = null;
        $restoEfectivo = $efectivo;
        $transferEnAnticipo = $transfer;
        $aviso = null;

        if ($r['facturable'] && $efectivo + $transfer >= $r['falta']) {
            // La transferencia entra completa a la factura: partirla en dos
            // registros rompería la conciliación contra el extracto. El
            // efectivo solo pone lo que falta y el resto queda de anticipo.
            $transferFactura = $r['falta'] > 0 ? $transfer : 0;
            $efectivoFactura = min($efectivo, max(0, $r['falta'] - $transferFactura));

            $resultado = $this->facturar($contrato, $r, $efectivoFactura, $transferFactura, $pago);

            // El backend puede cobrar unos pesos más que el estimado (redondeos
            // de la cotización). Si el efectivo que sobra alcanza, se reintenta
            // una vez con esa diferencia.
            if (isset($resultado['faltante']) && $resultado['faltante'] <= $efectivo - $efectivoFactura) {
                $efectivoFactura += $resultado['faltante'];
                $resultado = $this->facturar($contrato, $r, $efectivoFactura, $transferFactura, $pago);
            }

            if (isset($resultado['factura_id'])) {
                $facturaId = $resultado['factura_id'];
                $restoEfectivo = $efectivo - $efectivoFactura;
                $transferEnAnticipo = $transfer - $transferFactura;
                if ($foto && ! empty($resultado['consignacion_ids'])) {
                    $this->guardarFotoConsignacion((int) $resultado['consignacion_ids'][0], $facturaId, $foto);
                    $foto = null;
                }
            } else {
                $aviso = $resultado['error'] ?? 'No se pudo facturar.';
            }
        }

        $anticipoIds = [];
        if ($restoEfectivo > 0) {
            $anticipoIds[] = $this->crearAnticipo($contrato, $restoEfectivo, 'efectivo', null, $pago);
        }
        if ($transferEnAnticipo > 0) {
            $anticipoIds[] = $this->crearAnticipo($contrato, $transferEnAnticipo, 'transferencia', $foto, $pago);
        }

        $fmt = fn (int $v) => '$'.number_format($v, 0, ',', '.');
        if ($facturaId) {
            $mensaje = "Facturado {$r['periodo']}.";
            if ($restoEfectivo > 0) {
                $mensaje .= ' Sobraron '.$fmt($restoEfectivo).' y quedaron como anticipo.';
            }
        } else {
            $mensaje = 'Quedó como anticipo: '.$fmt($efectivo + $transfer).'.';
            if ($aviso) {
                $mensaje .= ' No se facturó: '.$aviso;
            } elseif (! $r['facturable']) {
                $mensaje .= ' '.$r['motivo_no_factura'];
            } else {
                $mensaje .= ' Faltan '.$fmt($r['falta'] - $efectivo - $transfer).' para facturar '.$r['periodo'].'.';
            }
        }

        return ['factura_id' => $facturaId, 'anticipo_ids' => $anticipoIds, 'mensaje' => $mensaje];
    }

    /**
     * Mismo período que propone el modal al abrirse: el primer mes pendiente
     * si es anterior al actual; si el actual ya está facturado, el siguiente
     * libre.
     *
     * @return array{0:int, 1:int, 2:array}
     */
    private function periodoPendiente(Contrato $contrato): array
    {
        $mes = (int) now()->month;
        $anio = (int) now()->year;
        $mp = $this->mesPagado($contrato, $mes, $anio);

        $p = $mp['pendiente'] ?? null;
        if ($p && ($p['anio'] * 100 + $p['mes']) < ($anio * 100 + $mes)) {
            [$mes, $anio] = [(int) $p['mes'], (int) $p['anio']];
            $mp = $this->mesPagado($contrato, $mes, $anio);
        }

        for ($i = 0; $i < 24 && ! empty($mp['pagado']); $i++) {
            [$mes, $anio] = [(int) $mp['mes'], (int) $mp['anio']];
            $mp = $this->mesPagado($contrato, $mes, $anio);
        }

        return [$mes, $anio, $mp];
    }

    private function mesPagado(Contrato $contrato, int $mes, int $anio): array
    {
        return app(FacturacionController::class)
            ->mesPagado(new Request(['mes' => $mes, 'anio' => $anio]), $contrato->id)
            ->getData(true);
    }

    /**
     * Llama al facturador con el cuerpo que arma el modal individual
     * (modal_facturar_v2.js → guardar). Sin overrides de seguridad social: el
     * backend la calcula con los días del período, igual que el cotizador.
     *
     * @return array{factura_id?:int, consignacion_ids?:int[], faltante?:int, error?:string}
     */
    private function facturar(Contrato $contrato, array $r, int $efectivo, int $transfer, array $pago): array
    {
        $formaPago = match (true) {
            $efectivo > 0 && $transfer > 0 => 'mixto',
            $transfer > 0 => 'consignacion',
            default => 'efectivo',
        };

        $body = [
            'contratos' => [(string) $contrato->id],
            'tipo' => 'planilla',
            'mes' => $r['mes'],
            'anio' => $r['anio'],
            'forma_pago' => $formaPago,
            'estado' => 'pagada',
            'consignaciones' => $transfer > 0 ? [[
                'banco_cuenta_id' => (int) $pago['banco_cuenta_id'],
                'valor' => $transfer,
                'fecha' => now()->toDateString(),
                'referencia' => $pago['referencia'] ?? null,
            ]] : [],
            'valor_efectivo' => $efectivo,
            'valor_prestamo' => 0,
            'otros' => 0,
            'otros_admon' => 0,
            'mora' => $r['desglose']['mora'],
            'mora_manual' => false,
            'mensajeria' => 0,
            'observacion' => $this->observacion($pago),
            'anticipo_ids' => $r['anticipo_ids'],
            'aplicar_saldo' => true,
            'incluir_cartera' => false,
            'es_retiro' => false,
            'indep_modo' => 'normal',
        ];

        try {
            $resp = app(FacturacionController::class)->facturar(Request::create('/', 'POST', $body));
        } catch (PagoIncompletoException $e) {
            return ['faltante' => $e->faltante, 'error' => 'faltan $'.number_format($e->faltante, 0, ',', '.')];
        } catch (ValidationException $e) {
            return ['error' => collect($e->errors())->flatten()->first() ?? 'datos inválidos'];
        }

        $data = $resp->getData(true);
        if (empty($data['ok']) || empty($data['facturas'])) {
            return ['error' => strip_tags((string) ($data['mensaje'] ?? 'el facturador no creó la factura'))];
        }

        return ['factura_id' => (int) $data['facturas'][0], 'consignacion_ids' => $data['consignacion_ids'] ?? []];
    }

    /** Mismo registro que AnticipoController::store, incluida la consignación de la transferencia. */
    private function crearAnticipo(Contrato $contrato, int $valor, string $forma, ?UploadedFile $foto, array $pago): int
    {
        return DB::transaction(function () use ($contrato, $valor, $forma, $foto, $pago) {
            $anticipo = Anticipo::create([
                'aliado_id' => $contrato->aliado_id,
                'cedula' => $contrato->cedula,
                'contrato_id' => $contrato->id,
                'fecha_pago' => now()->toDateString(),
                'valor' => $valor,
                'valor_aplicado' => 0,
                'forma_pago' => $forma,
                'banco_cuenta_id' => $forma === 'transferencia' ? $pago['banco_cuenta_id'] : null,
                'referencia' => $forma === 'transferencia' ? ($pago['referencia'] ?? null) : null,
                'observacion' => $this->observacion($pago),
                'estado' => Anticipo::ESTADO_DISPONIBLE,
                'origen' => 'individual',
                'usuario_id' => Auth::id(),
            ]);

            if ($forma === 'transferencia') {
                $ruta = $foto?->storeAs('anticipos', 'ant_'.$anticipo->id.'_'.time().'.'.$this->extension($foto), 'public');

                Consignacion::create([
                    'aliado_id' => $contrato->aliado_id,
                    'banco_cuenta_id' => $pago['banco_cuenta_id'],
                    'factura_id' => null,
                    'anticipo_id' => $anticipo->id,
                    'fecha' => now()->toDateString(),
                    'valor' => $valor,
                    'tipo' => Consignacion::TIPO_ANTICIPO,
                    'referencia' => $pago['referencia'] ?? null,
                    'confirmado' => false,
                    'observacion' => $this->observacion($pago),
                    'usuario_id' => Auth::id(),
                    'imagen_path' => $ruta ?: null,
                ]);
            }

            return (int) $anticipo->id;
        });
    }

    /** Misma ruta que FacturacionController::subirImagenConsignacion, para que el cuadre la encuentre. */
    private function guardarFotoConsignacion(int $consignacionId, int $facturaId, UploadedFile $foto): void
    {
        $consig = Consignacion::where('aliado_id', session('aliado_id_activo'))->find($consignacionId);
        if (! $consig) {
            return;
        }
        $ruta = $foto->storeAs("consignaciones/{$consig->aliado_id}/{$facturaId}", "{$consig->id}.".$this->extension($foto), 'public');
        $consig->update(['imagen_path' => $ruta]);
    }

    private function extension(UploadedFile $foto): string
    {
        return strtolower($foto->getClientOriginalExtension() ?: $foto->guessExtension() ?: 'jpg');
    }

    private function observacion(array $pago): string
    {
        $nota = trim((string) ($pago['nota'] ?? ''));

        return mb_substr('Cobro en visita'.($nota !== '' ? ' · '.$nota : ''), 0, 300);
    }
}
