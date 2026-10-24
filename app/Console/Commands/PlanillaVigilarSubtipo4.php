<?php

namespace App\Console\Commands;

use App\Models\OperadorCredencial;
use App\Models\Plano;
use App\Models\TipoModalidad;
use App\Services\AlertaOperativaService;
use App\Services\PlanoPilaTxtService;
use App\Services\SuaporteApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Vigila si el operador vuelve a dejar pasar el subtipo de cotizante 03/04.
 *
 * El 13-oct-2026 Simple no consultaba la base de la UGPP y la planilla de Tipo
 * E - X1 liquidaba para cualquiera; el 24-oct ya la consulta y rechaza con
 * `eo.val.2.655` a la misma gente que Enlace. La hipótesis del dueño es que la
 * validación va y viene —que la apagan por ventanas de tiempo—, y esto es lo
 * que la pone a prueba: sondea cada media hora y deja constancia de cada
 * intento, así que aunque nunca vuelva a pasar, el registro dice si hubo
 * patrón horario o si simplemente la encendieron para siempre.
 *
 * ## Nunca liquida
 *
 * El archivo que envía lleva un segundo cotizante de **freno**: cédula
 * inexistente `CC9999999999` con todo en cero. El operador valida cotizante por
 * cotizante, así que informa lo de la persona real pero jamás crea la planilla
 * —el freno siempre tiene errores—. Es la misma técnica con la que se mapearon
 * todas las reglas sin gastar un peso. Un sondeo automático SIN freno podría
 * liquidar solo, de madrugada y por duplicado.
 *
 * Cuando una cédula vuelve limpia, avisa por WhatsApp y ahí sí la liquida una
 * persona, a mano.
 *
 * Ejecución manual: php artisan planilla:vigilar-subtipo4 --verbose
 */
class PlanillaVigilarSubtipo4 extends Command
{
    protected $signature = 'planilla:vigilar-subtipo4
        {--mes= : Mes de pago a sondear (por defecto, el actual)}
        {--anio= : Año de pago a sondear (por defecto, el actual)}
        {--sin-aviso : Sondea y registra, pero no manda WhatsApp}';

    protected $description = 'Sondea si el operador ya deja pasar el subtipo 03/04 y avisa por WhatsApp cuando sí';

    /** La regla que estamos esperando que desaparezca. */
    private const REGLA_UGPP = 'eo.val.2.655';

    /** Cédula del freno: no existe, así que su línea siempre falla. */
    private const FRENO_CEDULA = '9999999999';

    public function handle(AlertaOperativaService $alertas): int
    {
        $mes = (int) ($this->option('mes') ?: now()->month);
        $anio = (int) ($this->option('anio') ?: now()->year);

        $tandas = $this->tandasPendientes($mes, $anio);

        if ($tandas->isEmpty()) {
            $this->info('No hay planos de Tipo E - X1 pendientes en el período.');

            return self::SUCCESS;
        }

        Auth::loginUsingId(2);   // ver [[feedback-usuario-brynex-comandos]]

        $limpias = [];

        foreach ($tandas as $t) {
            foreach ($this->sondear($t, $mes, $anio) as $cedula => $errores) {
                $paso = ! in_array(self::REGLA_UGPP, $errores, true);

                Log::channel('single')->info('vigia-subtipo4', [
                    'rs' => $t->razon_social_id,
                    'n_plano' => $t->n_plano,
                    'cedula' => $cedula,
                    'paso' => $paso,
                    'errores' => $errores,
                ]);

                $this->line(sprintf(
                    '  rs %-5s n_plano %-3s CC %-12s → %s',
                    $t->razon_social_id, $t->n_plano, $cedula,
                    $errores ? implode(' ', $errores) : 'LIMPIO'
                ));

                if ($paso) {
                    $limpias[] = $cedula;
                }
            }
        }

        if ($limpias && ! $this->option('sin-aviso')) {
            // Una vez cada 6 h: si la ventana dura, no conviene repetir el
            // aviso cada media hora, pero sí volver a recordarlo si sigue
            // abierta un rato después.
            $alertas->enviarUnaVez(
                'vigia-subtipo4-'.implode('-', $limpias),
                'Vigía subtipo 4',
                "✅ El operador ya deja pasar el subtipo de cotizante 03/04.\n\n"
                ."Cédulas que pasaron limpias: ".implode(', ', $limpias)."\n"
                ."Período {$mes}/{$anio}.\n\n"
                ."Entra a liquidar ahora: la ventana puede cerrarse.",
                360
            );
            $this->info('Aviso enviado por WhatsApp.');
        }

        return self::SUCCESS;
    }

    /**
     * Tandas de Tipo E - X1 con planos sin planilla en el período.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function tandasPendientes(int $mes, int $anio)
    {
        return DB::table('planos AS p')
            ->whereIn('p.tipo_modalidad_id', TipoModalidad::IDS_EX1)
            ->whereIn('p.tipo_reg', ['planilla', 'retiro'])
            ->whereRaw('ISNULL(p.num_dias, 0) > 0')
            ->whereNull('p.deleted_at')
            ->whereNull('p.numero_planilla')
            ->tap(fn ($q) => Plano::filtrarPeriodoDePago($q, $mes, $anio))
            ->select('p.aliado_id', 'p.razon_social_id', 'p.n_plano', 'p.tipo_modalidad_id')
            ->distinct()
            ->get();
    }

    /**
     * Manda la tanda al validador con el freno puesto y devuelve, por cédula,
     * los códigos de error que le tocaron a esa persona.
     *
     * @return array<string, string[]>
     */
    private function sondear(object $tanda, int $mes, int $anio): array
    {
        $cred = OperadorCredencial::where('aliado_id', $tanda->aliado_id)
            ->where('operador_planilla_id', 6)   // Simple: el único que liquida X1
            ->first();

        if (! $cred) {
            $this->warn("Aliado {$tanda->aliado_id} sin credencial de Simple.");

            return [];
        }

        try {
            $base = (new PlanoPilaTxtService())->construir([
                'aliado_id' => $tanda->aliado_id,
                'razon_social_id' => $tanda->razon_social_id,
                'mes' => $mes,
                'anio' => $anio,
                'n_plano' => $tanda->n_plano,
                'tipos_modalidad' => [(int) $tanda->tipo_modalidad_id],
                'codigo_operador' => '88',
            ]);
        } catch (\Throwable $e) {
            $this->warn("No se pudo armar el plano: {$e->getMessage()}");

            return [];
        }

        $lineas = explode("\r\n", $base['contenido']);
        $cabecera = null;
        $cotizantes = [];

        foreach ($lineas as $l) {
            if (str_starts_with($l, '01')) {
                $cabecera = $l;
            }
            if (str_starts_with($l, '02')) {
                $cotizantes[] = $l;
            }
        }

        if (! $cabecera || ! $cotizantes) {
            return [];
        }

        $cotizantes[] = $this->freno($cotizantes[0], count($cotizantes) + 1);
        $cabecera = substr_replace($cabecera, str_pad((string) count($cotizantes), 5, '0', STR_PAD_LEFT), 338, 5);

        $nit = preg_replace('/\D/', '', (string) DB::table('razones_sociales')
            ->where('id', $tanda->razon_social_id)->value('nit'));

        $api = new SuaporteApiService([
            'operador' => 'SIMPLE',
            'usuario' => $cred->usuario,
            'contrasena' => $cred->contrasena,
            'clave_secreta' => $cred->clave_secreta,
        ]);

        $r = $api->liquidarPlanilla(
            $nit,
            implode("\r\n", array_merge([$cabecera], $cotizantes)),
            $base['filename'],
            ['tipoArchivo' => 'I']
        );

        if (! ($r['success'] ?? false)) {
            $this->warn('Operador no respondió: '.substr($r['message'] ?? '', 0, 120));

            return [];
        }

        // Paranoia: con el freno no debería pasar nunca, pero si el operador
        // llegara a liquidar hay que saberlo de inmediato y no en el extracto.
        if ($r['liquidada'] ?? false) {
            Log::error('vigia-subtipo4: el sondeo LIQUIDÓ pese al freno', [
                'planilla' => $r['numero_planilla'] ?? null,
                'rs' => $tanda->razon_social_id,
            ]);
            $this->error('¡El sondeo liquidó! planilla '.($r['numero_planilla'] ?? '?'));
        }

        $porCedula = [];
        foreach ($cotizantes as $l) {
            $cc = trim(substr($l, 9, 16));
            if ($cc !== self::FRENO_CEDULA) {
                $porCedula[$cc] = [];
            }
        }

        foreach (array_merge($r['errores_cotizante'] ?? [], $r['errores_empresa'] ?? []) as $e) {
            $id = (string) ($e['identificacion'] ?? '');
            if (str_contains($id, self::FRENO_CEDULA)) {
                continue;
            }
            foreach (array_keys($porCedula) as $cc) {
                if (str_contains($id, $cc)) {
                    $porCedula[$cc][] = $e['idRegla'];
                }
            }
        }

        return array_map(fn ($v) => array_values(array_unique($v)), $porCedula);
    }

    /** Línea de freno: cédula inexistente y todo en cero. */
    private function freno(string $modelo, int $seq): string
    {
        $num = fn ($v, $n) => str_pad((string) $v, $n, '0', STR_PAD_LEFT);
        $pad = fn ($v, $n) => str_pad(substr($v, 0, $n), $n, ' ', STR_PAD_RIGHT);

        $l = substr_replace($modelo, $num($seq, 5), 2, 5);
        $l = substr_replace($l, $pad('CC', 2), 7, 2);
        $l = substr_replace($l, $pad(self::FRENO_CEDULA, 16), 9, 16);
        $l = substr_replace($l, '00000000', 183, 8);

        foreach ([191, 201, 210, 219, 228] as $i) {
            $l = substr_replace($l, $num(0, 9), $i, 9);
        }
        $l = substr_replace($l, '0.00000', 237, 7);
        $l = substr_replace($l, '0.00000', 307, 7);
        $l = substr_replace($l, '0.0000000', 380, 9);
        $l = substr_replace($l, '0.00000', 407, 7);
        foreach ([244, 271, 314, 398, 414] as $i) {
            $l = substr_replace($l, $num(0, 9), $i, 9);
        }

        return $l;
    }
}
