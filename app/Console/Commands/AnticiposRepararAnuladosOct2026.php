<?php

namespace App\Console\Commands;

use App\Models\Anticipo;
use App\Models\Bitacora;
use App\Models\Factura;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Devuelve los anticipos de tres personas del lote #14266 de Brygar (empresa
 * 126, octubre-2026) a quienes se les anuló su factura el 15-oct-2026.
 *
 * Al facturar el lote, la primera factura (295890) se quedó ligada con los 161
 * anticipos, y la anulación solo devolvía los ligados a la factura anulada: a
 * ellas no les devolvió nada. Para cobrarles el mes se volvió a facturar
 * "en efectivo", con efectivo que nadie trajo (el anticipo ya era el pago).
 *
 * Por cada una: se devuelve lo que la factura anulada tomó de anticipos (con
 * Anticipo::devolverDeFactura, la misma regla que ahora usa la anulación), se
 * aplica a la factura nueva y se le quita el efectivo. Lo que sobra queda
 * disponible para la persona. La caja de Angela del 15-oct baja $1.348.100.
 *
 * Se verifica que cada factura siga como estaba al revisarla; si alguna
 * cambió, no se aplica nada.
 */
class AnticiposRepararAnuladosOct2026 extends Command
{
    protected $signature = 'anticipos:reparar-anulados-oct2026
                            {--ejecutar : Aplica los cambios. Sin este flag solo muestra lo que haría}';

    protected $description = 'Devuelve los anticipos de las 3 facturas anuladas del lote #14266 de Brygar y los aplica a sus facturas nuevas (oct-2026)';

    private const ALIADO = 2;

    /** Usuario al que se atribuyen los cambios hechos por comando. */
    private const USUARIO = 2;

    /** factura anulada => [factura nueva, cédula, anticipo que tomó la anulada, total de la nueva]. */
    private const PARES = [
        295935 => [296004, '1007225101', 472836, 472700], // Yuly Rodríguez
        295905 => [295939, '38610100', 402811, 402700],
        295896 => [296022, '1118299496', 472830, 472700],
    ];

    public function handle(): int
    {
        $ejecutar = (bool) $this->option('ejecutar');
        Auth::onceUsingId(self::USUARIO);   // ver [[feedback-usuario-brynex-comandos]]

        $problemas = $this->verificar();
        if ($problemas) {
            foreach ($problemas as $p) {
                $this->error($p);
            }
            $this->error('Algo cambió desde la revisión: no se aplica nada.');

            return self::FAILURE;
        }

        $filas = [];
        DB::beginTransaction();
        try {
            foreach (self::PARES as $anuladaId => [$nuevaId, $cedula, $tomado, $total]) {
                $anulada = Factura::onlyTrashed()->findOrFail($anuladaId);
                $nueva = Factura::findOrFail($nuevaId);

                $devueltos = Anticipo::devolverDeFactura($anulada);

                // Se paga la nueva con los devueltos de la misma persona.
                $pendiente = (int) $nueva->total;
                $aplicados = [];
                foreach (Anticipo::whereIn('id', array_keys($devueltos))->where('cedula', $cedula)->orderBy('id')->get() as $ant) {
                    if ($pendiente <= 0) {
                        break;
                    }
                    $x = $ant->aplicarAFactura($nueva->id, $pendiente);
                    if ($x > 0) {
                        $aplicados[$ant->id] = $x;
                        $pendiente -= $x;
                    }
                }

                if ($pendiente !== 0) {
                    throw new \RuntimeException("Factura {$nuevaId}: los anticipos devueltos no alcanzan (faltan {$pendiente}).");
                }

                $antes = $nueva->only(['valor_efectivo', 'anticipo_aplicado', 'saldo_proximo']);
                $nueva->update([
                    'valor_efectivo' => 0,
                    'anticipo_aplicado' => $total,
                    'saldo_proximo' => 0,
                ]);

                foreach ($devueltos as $antId => $d) {
                    Bitacora::registrar(
                        accion: 'updated',
                        modelo: 'Anticipo',
                        registroId: $antId,
                        descripcion: "Anticipo #{$antId}: se devolvieron $".number_format($d['devuelto'], 0, ',', '.')
                                    ." de la factura #{$anulada->numero_factura} anulada el 15-oct (no se habían devuelto)."
                                    .(isset($aplicados[$antId]) ? ' Aplicado a la factura #'.$nueva->numero_factura.'.' : ''),
                        detalle: $d + ['factura_id_anulada' => $anuladaId, 'aplicado_a' => $aplicados[$antId] ?? 0, 'factura_nueva' => $nuevaId],
                        alidoId: self::ALIADO
                    );
                }

                Bitacora::registrar(
                    accion: 'updated',
                    modelo: 'Factura',
                    registroId: $nuevaId,
                    descripcion: "Factura #{$nueva->numero_factura}: se paga con los anticipos de la #{$anulada->numero_factura} anulada, no con efectivo.",
                    detalle: ['antes' => $antes, 'anticipos' => $aplicados, 'factura_anulada' => $anuladaId],
                    alidoId: self::ALIADO
                );

                $libre = (int) Anticipo::where('aliado_id', self::ALIADO)->where('cedula', $cedula)
                    ->whereIn('id', array_keys($devueltos))->get()->sum('valor_disponible');

                $filas[] = [
                    $cedula,
                    "#{$anulada->numero_factura} → #{$nueva->numero_factura}",
                    $this->pesos(array_sum(array_column($devueltos, 'devuelto'))),
                    count($devueltos),
                    $this->pesos(array_sum($aplicados)),
                    $this->pesos($libre),
                    $this->pesos((int) $antes['valor_efectivo']).' → $0',
                ];
            }

            $this->table(['Cédula', 'Facturas', 'Devuelto', 'Anticipos', 'Aplicado a la nueva', 'Le queda disponible', 'Efectivo de la nueva'], $filas);

            if ($ejecutar) {
                DB::commit();
                $this->info('Aplicado.');
            } else {
                DB::rollBack();
                $this->warn('Simulación: no se guardó nada. Corre con --ejecutar para aplicarlo.');
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return string[] */
    private function verificar(): array
    {
        $problemas = [];
        foreach (self::PARES as $anuladaId => [$nuevaId, $cedula, $tomado, $total]) {
            $anulada = Factura::onlyTrashed()->where('aliado_id', self::ALIADO)->find($anuladaId);
            if (! $anulada || (string) $anulada->cedula !== $cedula || (int) $anulada->anticipo_aplicado !== $tomado) {
                $problemas[] = "Factura anulada {$anuladaId} no está como se revisó.";
            }

            $nueva = Factura::where('aliado_id', self::ALIADO)->find($nuevaId);
            if (! $nueva || (string) $nueva->cedula !== $cedula || (int) $nueva->total !== $total
                || (int) $nueva->valor_efectivo !== $total || (int) $nueva->anticipo_aplicado !== 0
                || (int) $nueva->valor_consignado !== 0) {
                $problemas[] = "Factura nueva {$nuevaId} no está como se revisó (¿ya se corrigió?).";
            }

            // Los anticipos de la persona del lote siguen ligados a otra factura del recibo.
            $ligados = (int) Anticipo::where('aliado_id', self::ALIADO)->where('cedula', $cedula)
                ->where('factura_id', 295890)->sum('valor_aplicado');
            if ($ligados < $tomado - 100) {
                $problemas[] = "Cédula {$cedula}: sus anticipos ya no están en la factura 295890 (hay {$ligados}).";
            }
        }

        return $problemas;
    }

    private function pesos(int $v): string
    {
        return '$'.number_format($v, 0, ',', '.');
    }
}
