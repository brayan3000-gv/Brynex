<?php

namespace App\Console\Commands;

use App\Models\Aliado;
use App\Models\CierreMes as CierreMesModel;
use App\Services\AlertaOperativaService;
use App\Services\Caja\CierreMes;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Le recuerda al superadmin por WhatsApp que haga el cierre de mes.
 *
 * Corre todos los días y solo habla tres veces al mes, como se acordó:
 *   3 días antes del fin de mes  → se acerca el cierre
 *   el último día                → hoy cierra
 *   el día 1                     → anotar los saldos reales del mes que cerró,
 *                                  con la diferencia de cada aliado
 *
 * Va al número de guardia (AlertaOperativaService), que es el del superadmin:
 * solo él anota los saldos reales. Es una excepción autorizada a la regla de
 * no mandar WhatsApp solos (17-oct-2026).
 */
class CierresRecordar extends Command
{
    protected $signature = 'cierres:recordar
        {--forzar= : antes | ultimo | inicio, para probar sin esperar el día}
        {--simular : muestra el mensaje sin enviarlo}';

    protected $description = 'Recordatorio por WhatsApp del cierre de mes';

    public function handle(AlertaOperativaService $alertas): int
    {
        $hoy  = now('America/Bogota')->startOfDay();
        $tipo = $this->option('forzar') ?: match (true) {
            $hoy->day === 1                                 => 'inicio',
            $hoy->isSameDay($hoy->copy()->endOfMonth())     => 'ultimo',
            $hoy->isSameDay($hoy->copy()->endOfMonth()->subDays(3)) => 'antes',
            default                                         => null,
        };
        if (! $tipo) {
            $this->line('Hoy no toca recordatorio.');

            return self::SUCCESS;
        }

        $mensaje = $tipo === 'inicio' ? $this->mensajeInicio($hoy) : $this->mensajeAntes($hoy, $tipo);

        if ($this->option('simular')) {
            $this->line($mensaje);

            return self::SUCCESS;
        }

        $ok = $alertas->enviar('Cierre de mes', $mensaje);
        $this->line($ok ? 'Recordatorio enviado.' : 'No se pudo enviar (ver el log).');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function mensajeAntes(Carbon $hoy, string $tipo): string
    {
        $mes = mb_strtoupper($hoy->locale('es')->monthName);

        return ($tipo === 'ultimo'
                ? "⚠️⚠️ HOY CIERRA {$mes}. "
                : "⚠️ EN 3 DÍAS CIERRA {$mes}. ")
            . 'ANTES DEL CIERRE: CONFIRMA LAS CONSIGNACIONES, REGISTRA LAS ENTREGAS DE EFECTIVO Y LOS RETIROS DEL DUEÑO. '
            . ($tipo === 'ultimo' ? 'Mañana' : 'El día 1')
            . ' a las 00:30 se guarda sola la foto del mes; después anotas los saldos reales en el estado financiero.';
    }

    private function mensajeInicio(Carbon $hoy): string
    {
        $mes  = $hoy->copy()->subMonth();
        $filas = CierreMesModel::where('anio', $mes->year)->where('mes', $mes->month)->whereNotNull('foto')->get();
        $nombres = Aliado::whereIn('id', $filas->pluck('aliado_id'))->pluck('nombre', 'id');

        $lineas = $filas->map(function ($f) use ($nombres) {
            $c = CierreMes::componer((int) $f->aliado_id, (int) $f->anio, (int) $f->mes, $f->foto);
            $estado = $c['verificadas'] === 0 ? 'sin verificar'
                : (abs($c['diferencia']) < 1 ? 'cuadra' : 'diferencia $' . number_format($c['diferencia'], 0, ',', '.'));

            return '• ' . ($nombres[$f->aliado_id] ?? "Aliado {$f->aliado_id}") . ': '
                . ($f->saldos_reales ? 'saldos anotados' : 'FALTAN SALDOS REALES') . " · {$estado}";
        })->implode(' ');

        $nombreMes = mb_strtoupper($mes->locale('es')->monthName);

        return "⚠️ CERRÓ {$nombreMes}. ANOTA LOS SALDOS REALES DE CADA CUENTA Y EL EFECTIVO CONTADO "
            . "en el estado financiero de {$mes->locale('es')->monthName}. "
            . ($lineas ?: 'No se guardó ninguna foto: revisa el log de cierres:foto.');
    }
}
