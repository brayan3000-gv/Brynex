<?php

namespace App\Services\Demo;

use App\Models\Plano;
use App\Services\PlanillaFormularioService;
use Barryvdh\DomPDF\Facade\Pdf;
use setasign\Fpdi\Fpdi;
use Throwable;

/**
 * Los PDF que en la vida real entrega el operador de planilla (el informe
 * individual y el comprobante de pago). En la demo los arma BryNex y cada
 * página lleva la franja de MUESTRA.
 */
class SoporteSimulado
{
    public static function planilla(Plano $plano, ?int $operadorPlanillaId = null): array
    {
        $pdf = app(PlanillaFormularioService::class)->generar($plano, $operadorPlanillaId);

        return ['pdf' => self::marcar($pdf), 'origen' => 'brynex'];
    }

    public static function comprobante(Plano $plano): array
    {
        $pdf = Pdf::loadView('demo.pdf.comprobante-pago', ['plano' => $plano, 'ahora' => now()])
            ->setPaper('letter')->setOption('isFontSubsettingEnabled', true)->output();

        return ['pdf' => $pdf, 'origen' => 'brynex'];
    }

    /**
     * Pone la franja de MUESTRA arriba y abajo de cada página. Si el PDF no se
     * deja leer (FPDI libre no abre todos los formatos), se devuelve tal cual:
     * mejor el soporte sin franja que ninguno.
     */
    public static function marcar(string $pdf): string
    {
        try {
            $f = new Fpdi();
            $paginas = $f->setSourceFile(\setasign\Fpdi\PdfParser\StreamReader::createByString($pdf));

            for ($i = 1; $i <= $paginas; $i++) {
                $tpl = $f->importPage($i);
                $t = $f->getTemplateSize($tpl);
                $f->AddPage($t['orientation'], [$t['width'], $t['height']]);
                $f->useTemplate($tpl);

                foreach ([4, $t['height'] - 12] as $y) {
                    $f->SetFillColor(220, 38, 38);
                    $f->SetTextColor(255, 255, 255);
                    $f->SetFont('Helvetica', 'B', 11);
                    $f->SetXY(0, $y);
                    $f->Cell($t['width'], 8, 'MUESTRA - PRUEBA API - DEMO DE BRYNEX - SIN VALIDEZ', 0, 0, 'C', true);
                }
            }

            return $f->Output('S');
        } catch (Throwable) {
            return $pdf;
        }
    }
}
