<?php

namespace App\Services;

use App\Models\ConfiguracionBrynex;
use App\Models\OperadorCredencial;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Verifica, para una cédula, si la UGPP la deja usar el subtipo de cotizante
 * 03, 04 o 09 — y con eso recomienda el plan de salud-sin-pensión que le
 * corresponde legítimamente.
 *
 * Reusa el mismo mecanismo del vigía (PlanillaVigilarSubtipo4): arma una
 * planilla con la línea de la persona en el subtipo a probar + una línea de
 * FRENO (cédula inexistente) que hace imposible que el sondeo liquide, la manda
 * al validador de Simple y lee los códigos de error que le tocaron a la cédula.
 * Es una CONSULTA: no liquida, no paga, no radica nada.
 *
 * - subtipo 03/04 → la puerta es `eo.val.2.655` ("no puede usar el subtipo").
 *   Si NO sale, la persona está exenta de pensión → aplica X1 (EPS+ARL sin pensión).
 * - subtipo 09   → la puerta es `eo.val.2.506` (no figura en el registro de
 *   pensionados). Si NO sale, es pensionado real.
 *
 * El `2.379` (cruce de salud de ADRES) se ignora: es ruido de copiar una línea
 * modelo con otra EPS, no la lista de la UGPP.
 */
class ValidadorSubtipoService
{
    /** Cédula del freno: no existe, su línea siempre falla → nada liquida. */
    private const FRENO_CEDULA = '9999999999';

    /** Razón social de referencia por defecto: presta cabecera + línea modelo. */
    private const RS_REF_DEFECTO = 35; // HOMENURSE BYG SAS (aliado 2)

    private const SUBTIPOS = ['03', '04', '09'];

    /** Código de la UGPP que cierra cada subtipo. */
    private const GATE = [
        '03' => 'eo.val.2.655',
        '04' => 'eo.val.2.655',
        '09' => 'eo.val.2.506',
    ];

    /**
     * @param  array{tipo_doc:string,cedula:string,primer_apellido:string,segundo_apellido:?string,primer_nombre:string,segundo_nombre:?string}  $persona
     * @return array<string,mixed>
     */
    public function validar(array $persona, ?int $rsRef = null, ?int $mes = null, ?int $anio = null): array
    {
        $mes = $mes ?: (int) now()->month;
        $anio = $anio ?: (int) now()->year;
        $rsRef = $rsRef ?: (int) ConfiguracionBrynex::obtener('validador_subtipo_rs_ref', self::RS_REF_DEFECTO);

        $rs = DB::table('razones_sociales')->where('id', $rsRef)->first();
        if (! $rs) {
            return ['ok' => false, 'error' => "La razón social de referencia {$rsRef} no existe."];
        }

        $cred = OperadorCredencial::where('operador_planilla_id', 6)
            ->where('aliado_id', $rs->aliado_id)->first();
        if (! $cred) {
            return ['ok' => false, 'error' => "El aliado {$rs->aliado_id} no tiene credencial de Simple."];
        }

        try {
            $base = (new PlanoPilaTxtService)->construir([
                'aliado_id' => $rs->aliado_id, 'razon_social_id' => $rsRef,
                'mes' => $mes, 'anio' => $anio, 'n_plano' => 1,
                'tipos_modalidad' => [0], 'codigo_operador' => '88',
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'No se pudo armar el plano de referencia: '.$e->getMessage()];
        }

        $cabecera = null;
        $modelo = null;
        foreach (explode("\r\n", $base['contenido']) as $l) {
            if (str_starts_with($l, '01')) {
                $cabecera = $l;
            }
            if (str_starts_with($l, '02') && ! $modelo) {
                $modelo = $l;
            }
        }
        if (! $cabecera || ! $modelo) {
            return ['ok' => false, 'error' => "La razón social de referencia ({$rsRef}) no tiene cotizantes en el período {$mes}/{$anio}."];
        }

        $api = new SuaporteApiService([
            'operador' => 'SIMPLE', 'usuario' => $cred->usuario,
            'contrasena' => $cred->contrasena, 'clave_secreta' => $cred->clave_secreta,
        ]);

        $nit = preg_replace('/\D/', '', (string) $rs->nit);

        $subtipos = [];
        foreach (self::SUBTIPOS as $st) {
            $subtipos[$st] = $this->sondearSubtipo($api, $nit, $cabecera, $modelo, $persona, $st, $base['filename']);
        }

        return [
            'ok' => true,
            'persona' => $persona,
            'rs' => ['id' => $rs->id, 'nombre' => $rs->razon_social, 'nit' => $rs->nit],
            'periodo' => sprintf('%02d/%d', $mes, $anio),
            'subtipos' => $subtipos,
            'recomendacion' => $this->recomendar($subtipos),
        ];
    }

    /** @return array<string,mixed> */
    private function sondearSubtipo(SuaporteApiService $api, string $nit, string $cabecera, string $modelo, array $persona, string $subtipo, string $filename): array
    {
        $linea = $this->lineaPersona($modelo, $persona, $subtipo, 1);
        $freno = $this->freno($modelo, 2);
        $cab = substr_replace($cabecera, $this->n(2, 5), 338, 5);
        $contenido = implode("\r\n", [$cab, $linea, $freno]);

        $r = null;
        for ($i = 0; $i < 2; $i++) {
            $r = $api->liquidarPlanilla($nit, $contenido, $filename, ['tipoArchivo' => 'I']);
            if ($r['success'] ?? false) {
                break;
            }
            $api->autenticar(true);
            usleep(1200000);
        }

        if (! ($r['success'] ?? false)) {
            return [
                'portal' => false, 'pasa' => false, 'errores' => [],
                'motivo' => ($r['paso'] ?? '?').' · '.substr($r['message'] ?? '(sin mensaje)', 0, 80),
            ];
        }

        if ($r['liquidada'] ?? false) {
            Log::error('validador-subtipo: LIQUIDÓ pese al freno', ['planilla' => $r['numero_planilla'] ?? null]);
        }

        $ced = $persona['cedula'];
        $errores = [];
        foreach (array_merge($r['errores_cotizante'] ?? [], $r['errores_empresa'] ?? []) as $e) {
            $id = (string) ($e['identificacion'] ?? '');
            if (str_contains($id, self::FRENO_CEDULA)) {
                continue;
            }
            if (str_contains($id, (string) $ced)) {
                $errores[] = $e['idRegla'];
            }
        }
        $errores = array_values(array_unique($errores));

        return [
            'portal' => true,
            'gate' => self::GATE[$subtipo],
            'errores' => $errores,
            // "pasa" = no tiene el código de la UGPP de su subtipo
            'pasa' => ! in_array(self::GATE[$subtipo], $errores, true),
        ];
    }

    /** @return array<string,mixed> */
    private function recomendar(array $r): array
    {
        $p03 = $r['03']['pasa'] ?? false;
        $p04 = $r['04']['pasa'] ?? false;
        $p09 = $r['09']['pasa'] ?? false;

        if ($p03 || $p04) {
            $st = $p03 ? '03' : '04';

            return [
                'plan' => 'X1', 'subtipo' => $st, 'color' => 'ok',
                'titulo' => 'Plan X1 — Salud sin pensión (EPS + ARL)',
                'detalle' => "Califica para el subtipo {$st}: la UGPP lo deja cotizar salud (y ARL) sin pensión por Tipo E. Es su derecho — liquida limpio en cualquier operador.",
            ];
        }

        if ($p09) {
            return [
                'plan' => 'PENSIONADO', 'subtipo' => '09', 'color' => 'info',
                'titulo' => 'Pensionado real (subtipo 09)',
                'detalle' => 'Figura en el registro de pensionados. Aplica el tratamiento de pensionado — el subtipo 09 no admite salud por EPS.',
            ];
        }

        return [
            'plan' => 'INGRESO_RETIRO', 'subtipo' => null, 'color' => 'warn',
            'titulo' => 'Ingreso-Retiro (EPS + ARL)',
            'detalle' => 'No califica a subtipo 03/04/09 (la UGPP lo rechaza). Para darle EPS + ARL va por Ingreso-Retiro o Caja 30 — no se le puede declarar exención de pensión.',
        ];
    }

    /** La línea de la persona en el subtipo que se está probando. */
    private function lineaPersona(string $modelo, array $persona, string $subtipo, int $seq): string
    {
        $l = substr_replace($modelo, $this->n($seq, 5), 2, 5);
        $l = substr_replace($l, $this->a($persona['tipo_doc'] ?? 'CC', 2), 7, 2);
        $l = substr_replace($l, $this->a((string) $persona['cedula'], 16), 9, 16);
        $l = substr_replace($l, $this->a($persona['primer_apellido'] ?? '', 20), 36, 20);
        $l = substr_replace($l, $this->a($persona['segundo_apellido'] ?? '', 30), 56, 30);
        $l = substr_replace($l, $this->a($persona['primer_nombre'] ?? '', 20), 86, 20);
        $l = substr_replace($l, $this->a($persona['segundo_nombre'] ?? '', 30), 106, 30);
        $l = substr_replace($l, substr($subtipo.'  ', 0, 2), 27, 2);   // el subtipo a probar
        $l = substr_replace($l, $this->a('', 6), 153, 6);              // sin AFP
        $l = substr_replace($l, '00303030', 183, 8);
        $l = substr_replace($l, $this->n(0, 9), 201, 9);               // IBC pensión 0
        $l = substr_replace($l, '0.00000', 237, 7);
        $l = substr_replace($l, $this->n(0, 9), 244, 9);
        $l = substr_replace($l, $this->n(0, 9), 271, 9);
        $l = substr_replace($l, ' ', 136, 1);                          // sin ING
        $l = substr_replace($l, ' ', 148, 1);                          // sin VAC-LR
        $l = substr_replace($l, ' ', 30, 1);                           // sin marca de exterior

        return $l;
    }

    /** Línea de freno: cédula inexistente y todo en cero. */
    private function freno(string $modelo, int $seq): string
    {
        $l = substr_replace($modelo, $this->n($seq, 5), 2, 5);
        $l = substr_replace($l, $this->a('CC', 2), 7, 2);
        $l = substr_replace($l, $this->a(self::FRENO_CEDULA, 16), 9, 16);
        $l = substr_replace($l, '00000000', 183, 8);

        foreach ([191, 201, 210, 219, 228] as $i) {
            $l = substr_replace($l, $this->n(0, 9), $i, 9);
        }
        $l = substr_replace($l, '0.00000', 237, 7);
        $l = substr_replace($l, '0.00000', 307, 7);
        $l = substr_replace($l, '0.0000000', 380, 9);
        $l = substr_replace($l, '0.00000', 407, 7);
        foreach ([244, 271, 314, 398, 414] as $i) {
            $l = substr_replace($l, $this->n(0, 9), $i, 9);
        }

        return $l;
    }

    private function n(int|string $v, int $largo): string
    {
        return str_pad((string) $v, $largo, '0', STR_PAD_LEFT);
    }

    /** Alfanumérico de ancho fijo (mismo tratamiento que PlanoPilaTxtService::A). */
    private function a(?string $v, int $largo): string
    {
        $v = mb_strtoupper(trim((string) $v), 'UTF-8');
        $v = iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $v);

        return str_pad(substr($v, 0, $largo), $largo, ' ', STR_PAD_RIGHT);
    }
}
