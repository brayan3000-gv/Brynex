<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Listados del cierre de un mes, en un solo Excel de cuatro hojas: activos,
 * pagos, retiros y afiliaciones. Es lo que el aliado entrega mes a mes.
 *
 * Cada hoja tiene su propia regla de "qué es del mes", y no son la misma:
 *
 * - Activos: foto al último día del mes. Ingresó en o antes del corte y no se
 *   había retirado a esa fecha. No es `estado = vigente`, que es la foto de
 *   hoy: sacar agosto a mediados de septiembre daría otra gente.
 * - Pagos: las facturas del período (`mes`/`anio`), pagadas o prestadas. Las
 *   facturas de retiro (`numero_factura = 0`) quedan fuera: van en $0 porque
 *   no entró plata, y esas personas ya salen en la hoja de retiros.
 * - Retiros: la misma regla del informe "Retirados del mes". El de mes
 *   vencido se retira en la planilla del mes anterior, así que a agosto le
 *   tocan sus retiros de julio.
 * - Afiliaciones: fecha de ingreso dentro del mes.
 */
class ListadosMesExcelService
{
    private const MESES = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio',
        'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

    public function __construct(
        private int $aliadoId,
        private int $mes,
        private int $anio,
    ) {}

    public function nombreArchivo(): string
    {
        return 'listados_'.strtolower(self::MESES[$this->mes]).'_'.$this->anio.'.xlsx';
    }

    public function construir(): Spreadsheet
    {
        $corte = Carbon::create($this->anio, $this->mes, 1)->endOfMonth();
        $cierre = 'con cierre al '.$corte->day.' de '.strtolower(self::MESES[$this->mes]).' de '.$this->anio;

        $libro = new Spreadsheet();
        $libro->removeSheetByIndex(0);

        $this->hoja($libro, 'Activos', 'Listado de total activos', $cierre,
            ['Cédula', 'Nombre', 'Razón Social', 'Empresa', 'Fecha Ingreso'],
            $this->activos($corte)->map(fn ($r) => [
                $r->cedula, $r->nombre, $r->razon_social, $r->empresa, $this->fecha($r->fecha_ingreso),
            ]),
            fechas: ['E'],
        );

        $this->hoja($libro, 'Pagos del mes', 'Listado de pagos del mes', $cierre,
            ['Cédula', 'Nombre', 'Razón Social', 'Empresa', 'Concepto pagado', 'Tipo', 'Recibo', 'Fecha de pago', 'Valor'],
            $this->pagos()->map(fn ($r) => [
                $r->cedula, $r->nombre, $r->razon_social, $r->empresa,
                $this->concepto($r),
                $r->estado === 'prestamo' ? 'Préstamo' : 'Facturación',
                (int) $r->numero_factura,
                $this->fecha($r->fecha_pago),
                (float) $r->total,
            ]),
            fechas: ['H'],
            dinero: ['I'],
        );

        // Las mismas columnas del informe "Retirados del mes", más la empresa.
        $this->hoja($libro, 'Retiros', 'Listado de retiros', $cierre,
            ['Cédula', 'Nombre', 'Razón Social', 'Empresa', 'Plan', 'Modalidad', 'Días retiro',
                'Fecha de retiro', 'Fecha marcado retiro', 'Motivo del retiro', 'Tipo retiro', 'Renovó',
                'Costo SS retiro', 'Observación de retiro', 'Última factura', 'Valor última factura'],
            $this->retiros()->map(fn ($r) => [
                $r->cedula, $r->nombre, $r->razon_social, $r->empresa, $r->plan_nombre, $r->modalidad_nombre,
                (int) ($r->dias_retiro ?? 0),
                $this->fecha($r->fecha_retiro), $this->fechaHora($r->fecha_marcado_retiro),
                $r->motivo, $r->tipo_retiro, $r->renovo ? 'Sí' : 'No',
                (float) ($r->costo_ss ?? 0), $r->observacion,
                $r->factura_numero ? (int) $r->factura_numero : null,
                $r->factura_total !== null ? (float) $r->factura_total : null,
            ]),
            fechas: ['H'],
            fechasHora: ['I'],
            dinero: ['M', 'P'],
        );

        // Nueva o reingreso con la misma regla del Resumen Consolidado Mensual.
        $afiliaciones = $this->afiliaciones();
        $reingresos = ReingresosAfiliacion::porContrato($this->aliadoId, $afiliaciones);

        $this->hoja($libro, 'Afiliaciones', 'Listado de afiliaciones', $cierre,
            ['Cédula', 'Nombre', 'Razón Social', 'Empresa', 'Fecha Ingreso', 'Motivo de afiliación',
                'Tipo (nueva/reingreso)', 'Retiro anterior', 'Encargado de afiliación', 'Factura afiliación', 'Valor factura afiliación'],
            $afiliaciones->map(fn ($r) => [
                $r->cedula, $r->nombre, $r->razon_social, $r->empresa, $this->fecha($r->fecha_ingreso), $r->motivo,
                isset($reingresos[$r->id]) ? 'Reingreso' : 'Nueva',
                isset($reingresos[$r->id]) ? $this->fecha($reingresos[$r->id]->fecha_retiro) : null,
                $r->encargado,
                $r->factura_numero ? (int) $r->factura_numero : null,
                $r->factura_total !== null ? (float) $r->factura_total : null,
            ]),
            fechas: ['E', 'H'],
            dinero: ['K'],
        );

        $libro->setActiveSheetIndex(0);

        return $libro;
    }

    // ── Consultas ──────────────────────────────────────────────────────

    /** Contratos con la persona, su razón social y su empresa. */
    private function baseContratos()
    {
        $aid = $this->aliadoId;

        // Sin ficha de cliente en el aliado no es una persona real: son
        // contratos que migraron mal del sistema viejo, sin nombre (13 de
        // GiMave con ingreso y retiro en agosto de 2026).
        return DB::table('contratos AS c')
            ->join('clientes AS cl', function ($j) use ($aid) {
                $j->on('cl.cedula', '=', 'c.cedula')->where('cl.aliado_id', $aid);
            })
            ->leftJoin('razones_sociales AS rs', 'rs.id', '=', 'c.razon_social_id')
            // `cod_empresa` a veces apunta a una empresa de otro aliado: sin
            // este filtro saldría el nombre de una empresa ajena.
            ->leftJoin('empresas AS em', function ($j) use ($aid) {
                $j->on('em.id', '=', 'cl.cod_empresa')->where('em.aliado_id', $aid);
            })
            ->where('c.aliado_id', $aid)
            ->select('c.cedula', 'rs.razon_social', 'em.empresa', DB::raw($this->sqlNombre().' AS nombre'))
            ->orderBy('cl.primer_apellido')
            ->orderBy('cl.primer_nombre');
    }

    private function activos(Carbon $corte)
    {
        $fecha = $corte->toDateString();

        // Un retirado sin fecha de retiro (legado del sistema viejo) no se
        // puede ubicar en el tiempo, así que no cuenta como activo.
        return $this->baseContratos()
            ->where('c.fecha_ingreso', '<=', $fecha)
            ->where(function ($q) use ($fecha) {
                $q->where(function ($v) use ($fecha) {
                    $v->where('c.estado', 'vigente')
                        ->where(fn ($f) => $f->whereNull('c.fecha_retiro')->orWhere('c.fecha_retiro', '>', $fecha));
                })->orWhere(function ($r) use ($fecha) {
                    $r->where('c.estado', 'retirado')->where('c.fecha_retiro', '>', $fecha);
                });
            })
            ->addSelect('c.fecha_ingreso')
            ->get();
    }

    private function pagos()
    {
        $aid = $this->aliadoId;

        return DB::table('facturas AS f')
            ->leftJoin('clientes AS cl', function ($j) use ($aid) {
                $j->on('cl.cedula', '=', 'f.cedula')->where('cl.aliado_id', $aid);
            })
            ->leftJoin('razones_sociales AS rs', 'rs.id', '=', 'f.razon_social_id')
            // La empresa del pago es a quien se le facturó, no la de la ficha
            // del cliente (esa es el canal comercial).
            ->leftJoin('empresas AS em', function ($j) use ($aid) {
                $j->on('em.id', '=', 'f.empresa_id')->where('em.aliado_id', $aid);
            })
            ->where('f.aliado_id', $aid)
            ->whereNull('f.deleted_at')
            ->where('f.mes', $this->mes)
            ->where('f.anio', $this->anio)
            ->whereIn('f.estado', ['pagada', 'abono', 'prestamo'])
            ->where('f.numero_factura', '<>', 0)
            ->select('f.cedula', 'f.tipo', 'f.estado', 'f.numero_factura', 'f.fecha_pago', 'f.total',
                'f.descripcion_tramite', 'rs.razon_social', 'em.empresa',
                DB::raw($this->sqlNombre().' AS nombre'))
            ->orderBy('f.fecha_pago')
            ->orderBy('f.numero_factura')
            ->get();
    }

    /**
     * Retiros del mes, con lo que muestra el informe "Retirados del mes"
     * (que también los saca de aquí, para que la pantalla y el Excel no se
     * desfasen).
     *
     * - Tipo: "Real" si la factura de retiro (`numero_factura = 0`) cobró
     *   seguridad social; si fue en $0, "Informativo".
     * - Renovó: la cédula tiene hoy un contrato vigente con el aliado.
     * - Fecha marcado: `updated_at` del contrato, la última vez que se tocó.
     */
    public function retiros()
    {
        $mes = $this->mes;
        $anio = $this->anio;
        $mesAnterior = $mes === 1 ? 12 : $mes - 1;
        $anioAnterior = $mes === 1 ? $anio - 1 : $anio;

        return $this->baseContratos()
            ->leftJoin('motivos_retiro AS mr', 'mr.id', '=', 'c.motivo_retiro_id')
            ->where('c.estado', 'retirado')
            ->where(function ($q) use ($mes, $anio, $mesAnterior, $anioAnterior) {
                $q->where(function ($q1) use ($mes, $anio) {
                    $q1->where('c.paga_mes_actual', 1)
                        ->whereMonth('c.fecha_retiro', $mes)
                        ->whereYear('c.fecha_retiro', $anio);
                })->orWhere(function ($q2) use ($mesAnterior, $anioAnterior) {
                    $q2->where('c.paga_mes_actual', 0)
                        ->whereMonth('c.fecha_retiro', $mesAnterior)
                        ->whereYear('c.fecha_retiro', $anioAnterior);
                });
            })
            ->leftJoin('planes_contrato AS pl', 'pl.id', '=', 'c.plan_id')
            ->leftJoin('tipo_modalidad AS tm', 'tm.id', '=', 'c.tipo_modalidad_id')
            ->addSelect('c.id AS contrato_id', 'c.fecha_retiro', 'c.observacion', 'c.updated_at AS fecha_marcado_retiro',
                'mr.nombre AS motivo', 'pl.nombre AS plan_nombre', 'tm.tipo_modalidad AS modalidad_nombre',
                DB::raw("(SELECT COUNT(*) FROM contratos WHERE cedula = c.cedula AND aliado_id = c.aliado_id AND estado = 'vigente') AS vigentes"),
                DB::raw('(SELECT TOP 1 total_ss FROM facturas WHERE contrato_id = c.id AND numero_factura = 0 AND deleted_at IS NULL ORDER BY id DESC) AS costo_ss'),
                DB::raw('(SELECT TOP 1 dias_cotizados FROM facturas WHERE contrato_id = c.id AND numero_factura = 0 AND deleted_at IS NULL ORDER BY id DESC) AS dias_retiro'),
                // La de retiro va con número 0: la que sirve de referencia es
                // la última que se le cobró al contrato.
                DB::raw("(SELECT TOP 1 numero_factura FROM facturas WHERE contrato_id = c.id AND numero_factura > 0 AND tipo <> 'afiliacion' AND deleted_at IS NULL ORDER BY anio DESC, mes DESC, id DESC) AS factura_numero"),
                DB::raw("(SELECT TOP 1 total FROM facturas WHERE contrato_id = c.id AND numero_factura > 0 AND tipo <> 'afiliacion' AND deleted_at IS NULL ORDER BY anio DESC, mes DESC, id DESC) AS factura_total"))
            ->get()
            ->each(function ($r) {
                $r->tipo_retiro = (float) ($r->costo_ss ?? 0) > 0 ? 'Real' : 'Informativo';
                $r->renovo = (int) $r->vigentes > 0;
            });
    }

    private function afiliaciones()
    {
        return $this->baseContratos()
            ->leftJoin('motivos_afiliacion AS ma', 'ma.id', '=', 'c.motivo_afiliacion_id')
            ->leftJoin('users AS u', 'u.id', '=', 'c.encargado_id')
            ->whereMonth('c.fecha_ingreso', $this->mes)
            ->whereYear('c.fecha_ingreso', $this->anio)
            // La factura que cobró la afiliación: la de tipo afiliación o una
            // de planilla que la trae sumada (campo `afiliacion` > 0).
            ->addSelect('c.id', 'c.fecha_ingreso', 'ma.nombre AS motivo', 'u.nombre AS encargado',
                DB::raw("(SELECT TOP 1 numero_factura FROM facturas WHERE contrato_id = c.id AND numero_factura > 0 AND deleted_at IS NULL AND (tipo = 'afiliacion' OR afiliacion > 0) ORDER BY id) AS factura_numero"),
                DB::raw("(SELECT TOP 1 total FROM facturas WHERE contrato_id = c.id AND numero_factura > 0 AND deleted_at IS NULL AND (tipo = 'afiliacion' OR afiliacion > 0) ORDER BY id) AS factura_total"))
            ->get();
    }

    // ── Presentación ───────────────────────────────────────────────────

    private function hoja(Spreadsheet $libro, string $nombre, string $titulo, string $cierre,
        array $encabezados, $filas, array $fechas = [], array $fechasHora = [], array $dinero = []): void
    {
        $hoja = new Worksheet($libro, $nombre);
        $libro->addSheet($hoja);

        $ultima = chr(ord('A') + count($encabezados) - 1);
        $total = $filas->count();

        $hoja->setCellValue('A1', $titulo);
        $hoja->setCellValue('A2', $cierre.' · '.$total.' '.($total === 1 ? 'registro' : 'registros'));
        $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $hoja->getStyle('A2')->getFont()->setItalic(true)->getColor()->setRGB('64748B');

        $hoja->fromArray($encabezados, null, 'A3');
        $hoja->getStyle("A3:{$ultima}3")->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'FCE4D6']],
            'borders' => ['bottom' => ['borderStyle' => 'thin']],
        ]);

        $fila = 4;
        foreach ($filas as $valores) {
            foreach (array_values($valores) as $i => $valor) {
                $col = chr(ord('A') + $i);
                if ($i === 0) {
                    // La cédula como texto: como número Excel le quita los
                    // ceros a la izquierda de un pasaporte o la vuelve 1E+10.
                    $hoja->setCellValueExplicit("{$col}{$fila}", (string) $valor, DataType::TYPE_STRING);
                } elseif (is_string($valor)) {
                    // Los nombres vienen de captura manual: "DIEGO  FERNANDO".
                    if (($valor = $this->limpiar($valor)) !== '') {
                        $hoja->setCellValueExplicit("{$col}{$fila}", $valor, DataType::TYPE_STRING);
                    }
                } elseif ($valor !== null) {
                    $hoja->setCellValue("{$col}{$fila}", $valor);
                }
            }
            $fila++;
        }

        if ($total > 0) {
            $fin = $fila - 1;
            foreach ($fechas as $col) {
                $hoja->getStyle("{$col}4:{$col}{$fin}")->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            }
            foreach ($fechasHora as $col) {
                $hoja->getStyle("{$col}4:{$col}{$fin}")->getNumberFormat()->setFormatCode('dd/mm/yyyy hh:mm');
            }
            foreach ($dinero as $col) {
                $hoja->getStyle("{$col}4:{$col}{$fin}")->getNumberFormat()->setFormatCode('$#,##0');
            }
        }

        $hoja->setAutoFilter("A3:{$ultima}".max(3, $fila - 1));
        $hoja->freezePane('A4');
        foreach (range('A', $ultima) as $col) {
            $hoja->getColumnDimension($col)->setAutoSize(true);
        }
    }

    private function concepto(object $f): string
    {
        return match ($f->tipo) {
            'planilla' => 'Planilla',
            'afiliacion' => 'Afiliación',
            'otro_ingreso' => trim((string) $f->descripcion_tramite) ?: 'Otro ingreso',
            default => ucfirst(str_replace('_', ' ', (string) $f->tipo)),
        };
    }

    /** Fecha como número de serie de Excel, para que se pueda ordenar y filtrar. */
    private function fecha($valor): ?float
    {
        $c = sqldate($valor);

        return $c ? ExcelDate::PHPToExcel($c->copy()->startOfDay()) : null;
    }

    private function fechaHora($valor): ?float
    {
        $c = sqldate($valor);

        return $c ? ExcelDate::PHPToExcel($c) : null;
    }

    private function limpiar(?string $v): string
    {
        return trim(preg_replace('/\s+/', ' ', (string) $v));
    }

    private function sqlNombre(): string
    {
        return "ISNULL(cl.primer_nombre,'')+' '+ISNULL(cl.segundo_nombre,'')+' '"
            ."+ISNULL(cl.primer_apellido,'')+' '+ISNULL(cl.segundo_apellido,'')";
    }
}
