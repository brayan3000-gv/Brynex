<?php

namespace App\Services;

use App\Models\Anticipo;
use App\Models\Cliente;
use App\Models\Contrato;
use App\Models\Factura;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Lo que se mira después de (o durante) una visita: qué se recogió en un
 * rango de fechas, con el abono de la empresa a cada persona, y qué le falta
 * a cada persona de una empresa para completar su mes.
 *
 * No calcula nada propio: lo que falta sale de VisitaCobroService::resumen,
 * el mismo cálculo que ve quien cobra en el puesto.
 */
class VisitaInformeService
{
    public function __construct(private VisitaCobroService $cobros) {}

    /**
     * Lo cobrado desde /visita entre dos fechas, una fila por persona, más el
     * detalle de cada cobro y de cada abono de la empresa en esas fechas.
     *
     * @return array{personas: Collection, cobros: Collection, empresa: Collection}
     */
    public function cobros(int $aliadoId, string $desde, string $hasta, ?int $empresaId): array
    {
        $enRango = fn ($q) => $q->where('aliado_id', $aliadoId)
            ->whereDate('fecha_pago', '>=', $desde)->whereDate('fecha_pago', '<=', $hasta);

        $facturas = Factura::query()->tap($enRango)
            ->where('observacion', 'LIKE', 'Cobro en visita%')->where('estado', '!=', 'anulada')
            ->with('usuario:id,nombre')->get();
        $anticipos = Anticipo::query()->tap($enRango)
            ->where('observacion', 'LIKE', 'Cobro en visita%')
            ->whereNotIn('estado', [Anticipo::ESTADO_DEVUELTO, Anticipo::ESTADO_ANULADO])
            ->with('usuario:id,nombre')->get();

        // Un cobro que alcanzó para el mes quedó en factura + anticipo: va en
        // una sola fila, la del anticipo, por todo lo recibido y con la parte
        // de la factura como aplicada (igual que el recibo que le llegó).
        $delCobro = VisitaCobroService::facturasDelCobro($facturas, $anticipos);
        $yaEnAnticipo = collect($delCobro)->pluck('id')->flip();
        $periodo = fn ($f) => ReciboVisitaService::periodo((int) $f->mes, (int) $f->anio);

        // Igual que la caja de la pantalla: de la factura solo cuenta la plata
        // nueva, no lo que puso de anticipos anteriores.
        $cobros = $facturas->reject(fn ($f) => $yaEnAnticipo->has($f->id))->map(fn ($f) => [
            'fecha' => $f->fecha_pago, 'hora' => $f->created_at?->format('g:i a'), 'orden' => $f->created_at?->timestamp,
            'cedula' => (string) $f->cedula,
            'tipo' => 'Factura '.$periodo($f),
            'numero' => (string) ($f->numero_factura ?: $f->id),
            'efectivo' => (int) $f->valor_efectivo, 'transferencia' => (int) $f->valor_consignado,
            'aplicado' => (int) $f->valor_efectivo + (int) $f->valor_consignado,
            'factura' => (string) ($f->numero_factura ?: $f->id).' · '.$periodo($f),
            'cobro' => $f->usuario?->nombre, 'nota' => $this->nota($f->observacion),
        ])->concat($anticipos->map(function ($a) use ($delCobro, $periodo) {
            $f = $delCobro[(int) $a->id] ?? null;

            return [
                'fecha' => $a->fecha_pago, 'hora' => $a->created_at?->format('g:i a'), 'orden' => $a->created_at?->timestamp,
                'cedula' => (string) $a->cedula,
                'tipo' => 'Anticipo · '.(Anticipo::FORMAS_PAGO[$a->forma_pago] ?? $a->forma_pago),
                'numero' => 'ANT-'.$a->id,
                'efectivo' => ($a->forma_pago === 'efectivo' ? (int) $a->valor : 0) + (int) $f?->valor_efectivo,
                'transferencia' => ($a->forma_pago === 'efectivo' ? 0 : (int) $a->valor) + (int) $f?->valor_consignado,
                'aplicado' => (int) $f?->valor_efectivo + (int) $f?->valor_consignado,
                'factura' => $f ? (string) ($f->numero_factura ?: $f->id).' · '.$periodo($f) : '',
                'cobro' => $a->usuario?->nombre, 'nota' => $this->nota($a->observacion),
            ];
        }));

        $clientes = $this->clientes($aliadoId, $cobros->pluck('cedula')->unique()->values());
        if ($empresaId) {
            $cobros = $cobros->filter(fn ($c) => (int) ($clientes[$c['cedula']]->cod_empresa ?? 0) === $empresaId);
        }
        $cobros = $cobros->sortBy('orden')->values();
        $cedulas = $cobros->pluck('cedula')->unique()->values();

        // Lo que la empresa repartió a esas mismas personas en esas fechas
        // (las partes de un anticipo por NIT).
        $empresa = collect();
        foreach ($cedulas->chunk(1000) as $lote) {
            $empresa = $empresa->concat(Anticipo::query()->tap($enRango)
                ->whereNotNull('anticipo_padre_id')
                ->whereIn('cedula', $lote->all())
                ->whereNotIn('estado', [Anticipo::ESTADO_DEVUELTO, Anticipo::ESTADO_ANULADO])
                ->with('factura:id,numero_factura,mes,anio,fecha_pago')
                ->orderBy('fecha_pago')->get());
        }

        // La parte de la empresa que ya pagó una factura dentro de esas fechas
        // fue para el cobro anterior, no para el que se está recogiendo: no
        // suma al abono (Kevin Montenegro, oct-2026: los $107.000 del 6-oct
        // pagaron la #14296 y el abono salía con el de noviembre encima).
        $yaUsado = fn ($a) => $a->factura && (int) $a->valor_aplicado > 0
            && $a->factura->fecha_pago && $a->factura->fecha_pago->toDateString() <= $hasta;

        $contratos = collect();
        foreach ($cedulas->chunk(1000) as $lote) {
            $contratos = $contratos->concat(Contrato::where('aliado_id', $aliadoId)->where('estado', 'vigente')
                ->whereIn('cedula', $lote->all())->orderBy('id')->get());
        }
        $contratos = $contratos->groupBy(fn ($k) => (string) $k->cedula);

        $personas = $cedulas->map(function ($cedula) use ($cobros, $empresa, $clientes, $yaUsado, $contratos) {
            $suyos = $cobros->where('cedula', $cedula);
            $efectivo = (int) $suyos->sum('efectivo');
            $transfer = (int) $suyos->sum('transferencia');
            $deEmpresa = (int) $empresa->where('cedula', $cedula)
                ->sum(fn ($a) => (int) $a->valor - ($yaUsado($a) ? (int) $a->valor_aplicado : 0));

            return $this->proximoPago($contratos->get($cedula, collect())) + [
                'cedula' => $cedula,
                'nombre' => $clientes->get($cedula)?->nombre_completo ?? '',
                'empresa' => $clientes->get($cedula)?->empresa?->empresa ?? 'Individual',
                'efectivo' => $efectivo,
                'transferencia' => $transfer,
                'abono' => $efectivo + $transfer,
                'abono_empresa' => $deEmpresa,
                'total' => $efectivo + $transfer + $deEmpresa,
                'cobros' => $suyos->count(),
                'cobro' => $suyos->pluck('cobro')->filter()->unique()->implode(', '),
            ];
        })->sortBy([['empresa', 'asc'], ['nombre', 'asc']])->values();

        $nombres = $personas->pluck('nombre', 'cedula');
        $empresas = $personas->pluck('empresa', 'cedula');

        return [
            'personas' => $personas,
            'cobros' => $cobros->map(fn ($c) => $c + ['nombre' => $nombres[$c['cedula']] ?? '', 'empresa' => $empresas[$c['cedula']] ?? '']),
            'empresa' => $empresa->map(fn ($a) => [
                'fecha' => $a->fecha_pago, 'cedula' => (string) $a->cedula,
                'nombre' => $nombres[(string) $a->cedula] ?? '', 'empresa' => $empresas[(string) $a->cedula] ?? '',
                'valor' => (int) $a->valor, 'numero' => 'ANT-'.$a->id, 'de' => 'ANT-'.$a->anticipo_padre_id,
                'periodo' => $a->periodo_mes ? ReciboVisitaService::periodo((int) $a->periodo_mes, (int) $a->periodo_anio) : '',
                'usado' => $yaUsado($a) ? (int) $a->valor_aplicado : 0,
                'factura' => $yaUsado($a) ? '#'.$a->factura->numero_factura.' · '
                    .ReciboVisitaService::periodo((int) $a->factura->mes, (int) $a->factura->anio) : '',
            ]),
        ];
    }

    /** El Excel de cobros(): personas, cada cobro y cada abono de la empresa. Devuelve la ruta temporal. */
    public function excelCobros(array $datos): string
    {
        $libro = new Spreadsheet;

        $hoja = $libro->getActiveSheet()->setTitle('Por persona');
        $this->tabla($hoja, ['Cédula', 'Nombre completo', 'Empresa', 'Efectivo', 'Transferencia', 'Abono de la persona',
            'Abono de la empresa', 'Total', 'Próximo pago', 'Valor del próximo pago', 'Anticipos libres hoy', 'Le falta',
            'Cobros', 'Cobró'], $datos['personas']->map(fn ($p) => [
                $p['cedula'], $p['nombre'], $p['empresa'], $p['efectivo'], $p['transferencia'], $p['abono'],
                $p['abono_empresa'], $p['total'], $p['proximo'], $p['proximo_valor'], $p['libres'], $p['falta'],
                $p['cobros'], $p['cobro'],
            ]), ['D', 'E', 'F', 'G', 'H', 'J', 'K', 'L'], '1e40af', true);

        $this->tabla($libro->createSheet()->setTitle('Cada cobro'), ['Fecha', 'Hora', 'Cédula', 'Nombre completo', 'Empresa',
            'Tipo', 'N.º', 'Efectivo', 'Transferencia', 'Valor', 'Aplicado a la factura', 'Quedó de anticipo', 'Factura', 'Cobró', 'Nota'], $datos['cobros']->map(fn ($c) => [
                $c['fecha']?->format('Y-m-d'), $c['hora'], $c['cedula'], $c['nombre'], $c['empresa'], $c['tipo'], $c['numero'],
                $c['efectivo'], $c['transferencia'], $c['efectivo'] + $c['transferencia'], $c['aplicado'],
                $c['efectivo'] + $c['transferencia'] - $c['aplicado'], $c['factura'], $c['cobro'], $c['nota'],
            ]), ['H', 'I', 'J', 'K', 'L'], '059669');

        $this->tabla($libro->createSheet()->setTitle('Abonos de la empresa'), ['Fecha', 'Cédula', 'Nombre completo', 'Empresa',
            'Valor', 'Ya pagó una factura', 'Cuenta en el abono', 'Factura que pagó', 'Para el mes', 'N.º', 'Parte de'], $datos['empresa']->map(fn ($a) => [
                $a['fecha']?->format('Y-m-d'), $a['cedula'], $a['nombre'], $a['empresa'], $a['valor'], $a['usado'],
                $a['valor'] - $a['usado'], $a['factura'], $a['periodo'], $a['numero'], $a['de'],
            ]), ['E', 'F', 'G'], '7c3aed');

        $libro->setActiveSheetIndex(0);
        $ruta = tempnam(sys_get_temp_dir(), 'visita');
        (new Xlsx($libro))->save($ruta);

        return $ruta;
    }

    /**
     * Los contratos vigentes de la gente de una empresa (por cod_empresa del
     * cliente, el mismo criterio de la lista de la pantalla). Lo que falta de
     * cada uno se pide aparte, por tandas: ver faltantes().
     */
    public function contratosDeEmpresa(int $aliadoId, int $empresaId): array
    {
        $clientes = Cliente::where('aliado_id', $aliadoId)->where('cod_empresa', $empresaId)->get()->keyBy(fn ($c) => (string) $c->cedula);

        $contratos = collect();
        foreach ($clientes->keys()->chunk(1000) as $lote) {
            $contratos = $contratos->concat(Contrato::where('aliado_id', $aliadoId)->where('estado', 'vigente')
                ->whereIn('cedula', $lote->all())->with('razonSocial')->get());
        }
        $porCedula = $contratos->countBy(fn ($k) => (string) $k->cedula);

        return $contratos->map(fn ($k) => [
            'contrato_id' => (int) $k->id,
            'cedula' => (string) $k->cedula,
            'nombre' => $clientes->get((string) $k->cedula)?->nombre_completo ?? '',
            'razon_social' => $k->razonSocial?->razon_social ?? '—',
            // Con dos contratos vigentes, los anticipos de la cédula se ven
            // en los dos: no se suman dos veces.
            'varios' => $porCedula[(string) $k->cedula] > 1,
        ])->sortBy('nombre')->values()->all();
    }

    /** Lo que falta en cada contrato, separando lo que abonó la empresa de lo que abonó la persona. */
    public function faltantes(int $aliadoId, array $contratoIds): array
    {
        return Contrato::where('aliado_id', $aliadoId)->where('estado', 'vigente')->whereIn('id', $contratoIds)->get()
            ->map(function ($k) {
                try {
                    $r = $this->cobros->resumen($k);
                } catch (\Throwable $e) {
                    report($e);

                    return ['contrato_id' => (int) $k->id, 'error' => 'No se pudo calcular.'];
                }

                return [
                    'contrato_id' => (int) $k->id,
                    'periodo' => $r['periodo'],
                    'mes' => $r['mes'],
                    'anio' => $r['anio'],
                    'total_mes' => $r['total_mes'],
                    'abono_empresa' => $r['anticipos_empresa'],
                    'abono_persona' => $r['anticipos'] - $r['anticipos_empresa'] + $r['saldo_favor'],
                    'saldo_favor' => $r['saldo_favor'],
                    'falta' => $r['falta'],
                    'facturable' => $r['facturable'],
                    'motivo' => $r['motivo_no_factura'],
                ];
            })->values()->all();
    }

    /**
     * Los abonos libres con que cuenta un contrato (los mismos que descuenta
     * faltantes()), separados en los de la persona y los de la empresa, con lo
     * que se pregunta de cada uno: cuándo, cómo, a qué cuenta, quién lo recibió
     * y el soporte. El soporte de una parte de la empresa es el del anticipo
     * por NIT del que salió.
     *
     * @return array{persona: array, empresa: array}
     */
    public function abonos(int $aliadoId, int $contratoId): array
    {
        $anticipos = Anticipo::disponiblesParaContrato($aliadoId, $contratoId)
            ->load(['usuario:id,nombre', 'bancoCuenta', 'padre.bancoCuenta', 'padre.empresa:id,empresa', 'contrato.razonSocial']);

        // La consignación (con su foto) cuelga del anticipo que entró al banco.
        $consignaciones = \App\Models\Consignacion::where('aliado_id', $aliadoId)
            ->whereIn('anticipo_id', $anticipos->pluck('id')->concat($anticipos->pluck('anticipo_padre_id'))->filter()->unique()->values())
            ->get(['id', 'anticipo_id', 'imagen_path', 'referencia', 'banco_cuenta_id'])
            ->keyBy('anticipo_id');

        $fila = function (Anticipo $a) use ($consignaciones) {
            $origen = $a->padre ?? $a;
            $cs = $consignaciones->get($a->id) ?? $consignaciones->get($a->anticipo_padre_id);
            $cuenta = $origen->bancoCuenta ?? $a->bancoCuenta;

            return [
                'id' => (int) $a->id,
                'fecha' => $a->fecha_pago?->format('d/m/Y'),
                'forma' => Anticipo::FORMAS_PAGO[$a->forma_pago] ?? $a->forma_pago,
                'efectivo' => in_array($a->forma_pago, ['efectivo', 'nequi'], true),
                'cuenta' => $cuenta ? trim($cuenta->banco.' · '.$cuenta->nombre.' '.$cuenta->numero_cuenta) : null,
                'referencia' => $a->referencia ?: $origen->referencia ?: $cs?->referencia,
                'valor' => (int) $a->valor,
                'disponible' => (int) $a->valor_disponible,
                'recibio' => $a->usuario?->nombre,
                'para' => $a->periodo_mes ? ReciboVisitaService::periodo((int) $a->periodo_mes, (int) $a->periodo_anio) : null,
                'de' => $a->anticipo_padre_id ? 'ANT-'.$a->anticipo_padre_id.($a->padre?->empresa ? ' · '.$a->padre->empresa->empresa : '') : null,
                'contrato' => $a->contrato?->razonSocial?->razon_social,
                'nota' => $this->nota($a->observacion),
                'soporte_url' => $cs?->imagen_path ? route('admin.visita.soporte', $cs->id) : null,
                'recibo_url' => $a->anticipo_padre_id ? null : route('admin.visita.recibo', ['a' => [$a->id]]),
            ];
        };

        return [
            'persona' => $anticipos->whereNull('anticipo_padre_id')->map($fila)->values()->all(),
            'empresa' => $anticipos->whereNotNull('anticipo_padre_id')->map($fila)->values()->all(),
        ];
    }

    /**
     * El próximo pago de la persona y cuánto le falta, con el mismo cálculo de
     * la pantalla (VisitaCobroService::resumen). Con dos contratos vigentes se
     * suman los dos meses; los anticipos son de la cédula y se cuentan una vez.
     * "Libres" incluye el saldo a favor, que también se descuenta del pago.
     */
    private function proximoPago(Collection $contratos): array
    {
        if ($contratos->isEmpty()) {
            return ['proximo' => 'Sin contrato vigente', 'proximo_valor' => null, 'libres' => null, 'falta' => null];
        }

        try {
            $resumenes = $contratos->map(fn ($k) => $this->cobros->resumen($k));
        } catch (\Throwable $e) {
            report($e);

            return ['proximo' => 'No se pudo calcular', 'proximo_valor' => null, 'libres' => null, 'falta' => null];
        }

        $valor = (int) $resumenes->sum('total_mes');
        $libres = (int) $resumenes->first()['anticipos'] + (int) $resumenes->sum('saldo_favor');

        return [
            'proximo' => $resumenes->pluck('periodo')->unique()->implode(' + '),
            'proximo_valor' => $valor,
            'libres' => $libres,
            'falta' => max(0, $valor - $libres),
        ];
    }

    private function clientes(int $aliadoId, Collection $cedulas): Collection
    {
        $clientes = collect();
        foreach ($cedulas->chunk(1000) as $lote) {
            $clientes = $clientes->concat(Cliente::where('aliado_id', $aliadoId)->whereIn('cedula', $lote->all())
                ->with('empresa:id,empresa')->get());
        }

        return $clientes->keyBy(fn ($c) => (string) $c->cedula);
    }

    /** "Cobro en visita · SAMASU · puesto 4" → "SAMASU · puesto 4". */
    private function nota(?string $observacion): string
    {
        return trim(preg_replace('/^Cobro en visita\s*·?\s*/u', '', (string) $observacion));
    }

    private function tabla(Worksheet $hoja, array $titulos, Collection $filas, array $colsPlata, string $color, bool $totales = false): void
    {
        $ultima = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($titulos));
        $hoja->fromArray($titulos, null, 'A1');
        $hoja->getStyle("A1:{$ultima}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => $color]],
        ]);
        // strictNullComparison: sin esto fromArray descarta los 0.
        $hoja->fromArray($filas->values()->all(), null, 'A2', true);
        $fin = $filas->count() + 1;

        // La cédula como texto: Excel no la redondea ni la pone en notación científica.
        foreach ($titulos as $i => $t) {
            if ($t === 'Cédula') {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1);
                for ($f = 2; $f <= $fin; $f++) {
                    $hoja->setCellValueExplicit("{$col}{$f}", (string) $hoja->getCell("{$col}{$f}")->getValue(),
                        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
            }
        }

        if ($totales && $filas->isNotEmpty()) {
            $hoja->setCellValue('A'.($fin + 1), 'TOTAL');
            foreach ($colsPlata as $col) {
                $hoja->setCellValue("{$col}".($fin + 1), "=SUM({$col}2:{$col}{$fin})");
            }
            $hoja->getStyle('A'.($fin + 1).":{$ultima}".($fin + 1))->getFont()->setBold(true);
            $fin++;
        }
        foreach ($colsPlata as $col) {
            $hoja->getStyle("{$col}2:{$col}{$fin}")->getNumberFormat()->setFormatCode('$#,##0');
        }
        foreach (range(1, count($titulos)) as $i) {
            $hoja->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }
        $hoja->freezePane('A2');
    }
}
