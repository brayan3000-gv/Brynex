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

        // Más alto que antes: ahora cada minuto deja una fila por aportante
        // vigilado, así que 160 filas son ~40 min con cuatro razones sociales.
        $limite = max(10, min(400, (int) $request->input('limite', 160)));
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

        // Nombre de cada aportante vigilado, para rotular las filas. El mismo
        // minuto trae una fila por razón social, así que se muestra cuál es.
        $rsIds = collect($sondeos)->pluck('rs')->filter()->unique()->values()->all();
        $nombres = $rsIds
            ? \DB::table('razones_sociales')->whereIn('id', $rsIds)->pluck('razon_social', 'id')
            : collect();
        $aportantes = [];
        foreach ($rsIds as $id) {
            $aportantes[$id] = $nombres[$id] ?? ('rs '.$id);
        }

        // "Sondeos del día" = minutos distintos, no filas: un minuto sondea
        // varias razones sociales y cada una deja su propia fila.
        $minutosHoy = count(array_unique(array_map(fn ($s) => $s['hora'], $deHoy)));

        return response()->json([
            'ahora' => now()->format('H:i:s'),
            'ultimo' => $ultimo,
            'edad_seg' => $edadSeg,
            'aportantes' => $aportantes,
            'resumen' => [
                'sondeos_hoy' => $minutosHoy,
                'huecos_hoy' => count(array_filter($deHoy, fn ($s) => $s['estado'] === 'hueco')),
                'caidas_portal_hoy' => count(array_filter($deHoy, fn ($s) => $s['estado'] === 'caido')),
                // Latencia "normal": mediana de los sondeos en estado normal
                // (la UGPP sí corrió). Es la línea base contra la cual un sondeo
                // notablemente más rápido delata que la validación se saltó.
                'lat_normal_hoy' => $this->medianaLatencia(array_filter($deHoy, fn ($s) => $s['estado'] === 'normal')),
                'lat_min_normal_hoy' => $this->minLatencia(array_filter($deHoy, fn ($s) => $s['estado'] === 'normal')),
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

        // Recoge, por sello de tiempo + aportante, qué cédulas dieron LIMPIO y
        // cuáles 2.655. La clave lleva el rs porque el mismo minuto trae un
        // bloque de cédulas por cada razón social vigilada.
        $cedulasPorSello = [];
        foreach ($lineas as $l) {
            if (preg_match('/^(\d{2}-\d{2} \d{2}:\d{2}:\d{2})\s+CC (\d+) rs=(\d+)\s+→\s+(.*)$/', $l, $m)) {
                $clave = $m[1].'|'.$m[3];
                $cedulasPorSello[$clave][] = [
                    'cedula' => $m[2],
                    'limpio' => ! str_contains($m[4], 'eo.val.2.655'),
                ];
            }
        }

        $sondeos = [];
        foreach ($lineas as $l) {
            $fila = $this->parsearTel($l);
            if ($fila === null) {
                continue;
            }
            $clave = $fila['fecha'].' '.$fila['hora'].'|'.$fila['rs'];
            $fila['cedulas'] = $cedulasPorSello[$clave] ?? [];
            $sondeos[] = $fila;
        }

        return array_reverse($sondeos); // más nuevo primero
    }

    /** Interpreta una línea '>> tel'. Devuelve null si no lo es. */
    private function parsearTel(string $l): ?array
    {
        // 11-11 12:21:07  >> tel rs=37  lat=2233ms · portal=OK · 2.655×4/4  ← HUECO (...)
        // 11-11 12:21:07  >> tel rs=28  lat=1200ms · portal=CAIDO · autorizacion · Empty reply...
        if (! preg_match('/^(\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})\s+>> tel rs=(\d+)\s+lat=(\d+|\?)ms · portal=(OK|CAIDO) · (.*)$/', $l, $m)) {
            return null;
        }

        $fecha = $m[1];
        $hora = $m[2];
        $rs = (int) $m[3];
        $lat = $m[4] === '?' ? null : (int) $m[4];
        $portalOk = $m[5] === 'OK';
        $resto = $m[6];

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
            'rs' => $rs,
            'lat' => $lat,
            'portal_ok' => $portalOk,
            'n655' => $n655,
            'total' => $total,
            'estado' => $estado,   // hueco | normal | caido
            'motivo' => $motivo,
        ];
    }

    /** @param array<int,array<string,mixed>> $sondeos */
    private function latencias(array $sondeos): array
    {
        return array_values(array_filter(
            array_map(fn ($s) => $s['lat'], $sondeos),
            fn ($v) => $v !== null
        ));
    }

    private function medianaLatencia(array $sondeos): ?int
    {
        $lats = $this->latencias($sondeos);
        if (! $lats) {
            return null;
        }
        sort($lats);
        $n = count($lats);
        $mid = intdiv($n, 2);

        return (int) ($n % 2 ? $lats[$mid] : round(($lats[$mid - 1] + $lats[$mid]) / 2));
    }

    private function minLatencia(array $sondeos): ?int
    {
        $lats = $this->latencias($sondeos);

        return $lats ? min($lats) : null;
    }
}
