<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Monitor minuto a minuto del vigía del subtipo 4.
 *
 * Lee storage/logs/vigia-subtipo4.log —el que escribe PlanillaVigilarSubtipo4
 * cada minuto— y lo presenta: latencia del validador, estado del portal y la
 * cuenta del 2.655, para ver en vivo cuándo cae la validación de la UGPP (el
 * hueco útil) frente a cuándo se cae el portal entero (inútil).
 *
 * Solo lee el log; no sondea ni liquida. El sondeo lo hace el comando por cron.
 */
class BrynexVigiaController extends Controller
{
    /** Cuántos bytes del final del log leer (evita cargar un archivo de MB). */
    private const COLA_BYTES = 900 * 1024;

    private function guard(): void
    {
        if (! Auth::user()->es_brynex) {
            abort(403, 'Acceso restringido a BryNex.');
        }
    }

    public function index()
    {
        $this->guard();

        return view('brynex.vigia.index');
    }

    /** JSON para el auto-refresco de la vista. */
    public function datos(Request $request)
    {
        $this->guard();

        $limite = max(10, min(300, (int) $request->input('limite', 90)));
        $sondeos = $this->parsearLog();

        $hoy = now()->format('d-m');
        $deHoy = array_filter($sondeos, fn ($s) => $s['fecha'] === $hoy);

        $ultimo = $sondeos[0] ?? null;
        $edadSeg = null;
        if ($ultimo) {
            $ts = \Carbon\Carbon::createFromFormat('d-m H:i:s', $ultimo['fecha'].' '.$ultimo['hora']);
            // El log no trae año; si quedó en el futuro (cambio de año) se resta uno.
            if ($ts->isFuture()) {
                $ts->subYear();
            }
            $edadSeg = (int) $ts->diffInSeconds(now());
        }

        return response()->json([
            'ahora' => now()->format('H:i:s'),
            'ultimo' => $ultimo,
            'edad_seg' => $edadSeg,
            'resumen' => [
                'sondeos_hoy' => count($deHoy),
                'huecos_hoy' => count(array_filter($deHoy, fn ($s) => $s['estado'] === 'hueco')),
                'caidas_portal_hoy' => count(array_filter($deHoy, fn ($s) => $s['estado'] === 'caido')),
                'lat_promedio_hoy' => $this->promedioLatencia($deHoy),
            ],
            'sondeos' => array_slice($sondeos, 0, $limite),
        ]);
    }

    /**
     * Lee la cola del log y devuelve los sondeos (líneas '>> tel'), del más
     * nuevo al más viejo, con sus cédulas limpias/en-rechazo asociadas.
     *
     * @return array<int, array<string, mixed>>
     */
    private function parsearLog(): array
    {
        $path = storage_path('logs/vigia-subtipo4.log');
        if (! is_file($path)) {
            return [];
        }

        $tam = filesize($path);
        $fh = fopen($path, 'rb');
        if ($tam > self::COLA_BYTES) {
            fseek($fh, $tam - self::COLA_BYTES);
            fgets($fh); // descarta la primera línea, probablemente cortada
        }
        $lineas = [];
        while (($l = fgets($fh)) !== false) {
            $lineas[] = rtrim($l, "\r\n");
        }
        fclose($fh);

        // Recoge, por sello de tiempo, qué cédulas dieron LIMPIO y cuáles 2.655.
        $cedulasPorSello = [];
        foreach ($lineas as $l) {
            if (preg_match('/^(\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s+CC (\d+)\s+→\s+(.*)$/', $l, $m)) {
                $sello = $m[1];
                $cedulasPorSello[$sello][] = [
                    'cedula' => $m[2],
                    'limpio' => ! str_contains($m[3], 'eo.val.2.655'),
                ];
            }
        }

        $sondeos = [];
        foreach ($lineas as $l) {
            $fila = $this->parsearTel($l);
            if ($fila === null) {
                continue;
            }
            $sello = $fila['fecha'].' '.$fila['hora'];
            $fila['cedulas'] = $cedulasPorSello[$sello] ?? [];
            $sondeos[] = $fila;
        }

        return array_reverse($sondeos); // más nuevo primero
    }

    /** Interpreta una línea '>> tel'. Devuelve null si no lo es. */
    private function parsearTel(string $l): ?array
    {
        // 11-11 12:21:07  >> tel  lat=2233ms · portal=OK · 2.655×4/4  ← HUECO (...)
        // 11-11 12:21:07  >> tel  lat=1200ms · portal=CAIDO · autorizacion · Empty reply...
        if (! preg_match('/^(\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})\s+>> tel\s+lat=(\d+|\?)ms · portal=(OK|CAIDO) · (.*)$/', $l, $m)) {
            return null;
        }

        $fecha = $m[1];
        $hora = $m[2];
        $lat = $m[3] === '?' ? null : (int) $m[3];
        $portalOk = $m[4] === 'OK';
        $resto = $m[5];

        $n655 = null;
        $total = null;
        $estado = $portalOk ? 'normal' : 'caido';
        $motivo = $portalOk ? null : $resto;

        if ($portalOk && preg_match('/2\.655×(\d+)\/(\d+)/', $resto, $mm)) {
            $n655 = (int) $mm[1];
            $total = (int) $mm[2];
            if ($n655 === 0 && $total > 0) {
                $estado = 'hueco';
            }
        }

        return [
            'fecha' => $fecha,
            'hora' => $hora,
            'lat' => $lat,
            'portal_ok' => $portalOk,
            'n655' => $n655,
            'total' => $total,
            'estado' => $estado,   // hueco | normal | caido
            'motivo' => $motivo,
        ];
    }

    private function promedioLatencia(array $sondeos): ?int
    {
        $lats = array_values(array_filter(array_map(fn ($s) => $s['lat'], $sondeos), fn ($v) => $v !== null));
        if (! $lats) {
            return null;
        }

        return (int) round(array_sum($lats) / count($lats));
    }
}
