<?php

namespace App\Console\Commands;

use App\Models\ConfiguracionBrynex;
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
 * E - X1 liquidaba para cualquiera; las de Kelly salieron el 14-oct a las 20:14.
 * El 24-oct ya la consulta y rechaza con `eo.val.2.655` a la misma gente que
 * Enlace. La hipótesis del dueño es que la apagan por ventanas de tiempo, y
 * esto es lo que la pone a prueba: sondea cada media hora y deja constancia con
 * hora, así que aunque nunca vuelva a pasar, el registro dice si hubo patrón o
 * si la encendieron para siempre.
 *
 * ## Todas en un solo archivo
 *
 * El validador informa error **por cotizante**, así que las cuatro personas
 * caben en una sola planilla y se resuelven con UNA petición. No es solo
 * eficiencia: el operador admite **una sola sesión por usuario**, y la versión
 * anterior —que mandaba un archivo por grupo— se pisaba a sí misma. La corrida
 * automática de las 13:30 del 24-oct reportó «sigue bloqueado» para tres
 * cédulas que en realidad nunca llegó a preguntar, que es justo el fallo que
 * dejaría pasar la ventana sin avisar.
 *
 * ## Nunca liquida
 *
 * El archivo lleva un cotizante de **freno**: cédula inexistente
 * `CC9999999999` con todo en cero. El operador valida cotizante por cotizante,
 * así que informa lo de cada persona real pero jamás crea la planilla —el freno
 * siempre tiene errores—. Es la misma técnica con la que se mapearon todas las
 * reglas sin gastar un peso. Un sondeo automático SIN freno podría liquidar
 * solo, de madrugada y por duplicado.
 *
 * ## Dónde queda el rastro
 *
 * En `storage/logs/vigia-subtipo4.log`, con día y hora en cada línea: ese
 * archivo ES el registro. No se usa `Log::` para el resultado de cada sondeo
 * porque en producción `LOG_LEVEL=warning` descarta los `info`, y subirlos a
 * `warning` metería 48 líneas diarias de «sigue bloqueado» en el log
 * operativo, donde solo estorban. Lo único que va por `Log::error` es el caso
 * imposible: que el sondeo llegue a liquidar pese al freno.
 *
 * Ejecución manual: php artisan planilla:vigilar-subtipo4
 */
class PlanillaVigilarSubtipo4 extends Command
{
    protected $signature = 'planilla:vigilar-subtipo4
        {--mes= : Mes de pago a sondear (por defecto, el actual)}
        {--anio= : Año de pago a sondear (por defecto, el actual)}
        {--cedulas= : Cédulas a sondear, separadas por coma (pisa las configuradas)}
        {--rs-ref= : Razón social con la que se sondea (pisa la configurada)}
        {--sin-aviso : Sondea y registra, pero no manda WhatsApp}';

    protected $description = 'Sondea si el operador ya deja pasar el subtipo 03/04 y avisa por WhatsApp cuando sí';

    /** La regla que estamos esperando que desaparezca. */
    private const REGLA_UGPP = 'eo.val.2.655';

    /** Cédula del freno: no existe, así que su línea siempre falla. */
    private const FRENO_CEDULA = '9999999999';

    /** Cédulas que se vigilan siempre, aunque no tengan plano pendiente. */
    private const CONFIG_CEDULAS = 'vigia_subtipo4_cedulas';

    /**
     * Aportante con el que se sondea: tiene que ser uno viejo y bien
     * configurado, o los errores de su propia ficha —una sucursal sin asociar,
     * por ejemplo— tapan lo que se quiere ver.
     */
    private const CONFIG_RS_REF = 'vigia_subtipo4_rs_ref';

    public function handle(AlertaOperativaService $alertas): int
    {
        $mes = (int) ($this->option('mes') ?: now()->month);
        $anio = (int) ($this->option('anio') ?: now()->year);

        Auth::loginUsingId(2);   // ver [[feedback-usuario-brynex-comandos]]

        $cedulas = $this->cedulasAVigilar($mes, $anio);

        if (! $cedulas) {
            $this->info('No hay cédulas que vigilar.');

            return self::SUCCESS;
        }

        $resultado = $this->sondear($cedulas, $mes, $anio);
        $limpias = [];
        $sello = now()->format('d-m H:i');

        foreach ($resultado as $cedula => $errores) {
            $paso = ! in_array(self::REGLA_UGPP, $errores, true);

            $this->line(sprintf('%s  CC %-12s → %s', $sello, $cedula,
                $errores ? implode(' ', $errores) : 'LIMPIO'));

            if ($paso) {
                $limpias[] = $cedula;
            }
        }

        if ($limpias && ! $this->option('sin-aviso')) {
            // Una vez cada 6 h: si la ventana dura, no conviene repetir el aviso
            // cada media hora, pero sí recordarlo si sigue abierta un rato después.
            $alertas->enviarUnaVez(
                'vigia-subtipo4-'.implode('-', $limpias),
                'Vigía subtipo 4',
                "✅ El operador ya deja pasar el subtipo de cotizante 03/04.\n\n"
                .'Cédulas que pasaron limpias: '.implode(', ', $limpias)."\n"
                ."Período {$mes}/{$anio}.\n\n"
                .'Entra a liquidar ahora: la ventana puede cerrarse.',
                360
            );
            $this->info('Aviso enviado por WhatsApp.');
        }

        return self::SUCCESS;
    }

    /**
     * Las configuradas más las de los planos de Tipo E - X1 que sigan sin
     * planilla: unas son testigos con historia —ya les salió una planilla
     * cuando la ventana estuvo abierta— y las otras son trabajo pendiente de
     * verdad.
     *
     * @return string[]
     */
    private function cedulasAVigilar(int $mes, int $anio): array
    {
        if ($sueltas = (string) $this->option('cedulas')) {
            return array_values(array_filter(array_map('trim', explode(',', $sueltas))));
        }

        $configuradas = array_filter(array_map('trim', explode(',',
            (string) ConfiguracionBrynex::obtener(self::CONFIG_CEDULAS, '')
        )));

        $pendientes = DB::table('planos AS p')
            ->whereIn('p.tipo_modalidad_id', TipoModalidad::IDS_EX1)
            ->whereIn('p.tipo_reg', ['planilla', 'retiro'])
            ->whereRaw('ISNULL(p.num_dias, 0) > 0')
            ->whereNull('p.deleted_at')
            ->whereNull('p.numero_planilla')
            ->tap(fn ($q) => Plano::filtrarPeriodoDePago($q, $mes, $anio))
            ->pluck('p.no_identifi')
            ->map(fn ($c) => trim((string) $c))
            ->all();

        return array_values(array_unique(array_merge($configuradas, $pendientes)));
    }

    /**
     * Arma UNA planilla con todas las cédulas y el freno, la manda al validador
     * y devuelve, por cédula, los códigos de error que le tocaron.
     *
     * Las personas no necesitan contrato ni plano en el aportante de
     * referencia: se toma una línea real suya y se le cambia el afiliado y la
     * forma —subtipo 04, sin AFP, 0/30/30/30 y sin novedades—, que es
     * exactamente la que pasó limpia el 13-oct-2026.
     *
     * @param  string[]  $cedulas
     * @return array<string, string[]>
     */
    private function sondear(array $cedulas, int $mes, int $anio): array
    {
        $rsRef = (int) ($this->option('rs-ref') ?: ConfiguracionBrynex::obtener(self::CONFIG_RS_REF, 0));

        if (! $rsRef) {
            $this->warn('Sin razón social de referencia: configure '.self::CONFIG_RS_REF.'.');

            return [];
        }

        $rs = DB::table('razones_sociales')->where('id', $rsRef)->first();
        $cred = OperadorCredencial::where('aliado_id', $rs->aliado_id)
            ->where('operador_planilla_id', 6)   // Simple: el único que liquida X1
            ->first();

        if (! $cred) {
            $this->warn("El aliado {$rs->aliado_id} no tiene credencial de Simple.");

            return [];
        }

        try {
            $base = (new PlanoPilaTxtService)->construir([
                'aliado_id' => $rs->aliado_id, 'razon_social_id' => $rsRef,
                'mes' => $mes, 'anio' => $anio, 'n_plano' => 1,
                'tipos_modalidad' => [0], 'codigo_operador' => '88',
            ]);
        } catch (\Throwable $e) {
            $this->warn('No se pudo armar el plano de referencia: '.$e->getMessage());

            return [];
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
            $this->warn("La razón social de referencia ({$rsRef}) no tiene cotizantes en el período.");

            return [];
        }

        $lineas = [];
        $seq = 1;

        foreach ($cedulas as $ced) {
            $cl = DB::table('clientes')->where('aliado_id', $rs->aliado_id)
                ->where('cedula', $ced)->first();

            if (! $cl) {
                $this->warn("Cédula {$ced} sin ficha en el aliado {$rs->aliado_id}.");

                continue;
            }

            $lineas[$ced] = $this->lineaVigilada($modelo, $cl, $ced, $seq++);
        }

        if (! $lineas) {
            return [];
        }

        $lineas['__freno'] = $this->freno($modelo, $seq);
        $cabecera = substr_replace($cabecera, $this->n(count($lineas), 5), 338, 5);
        $nit = preg_replace('/\D/', '', (string) $rs->nit);

        $api = new SuaporteApiService([
            'operador' => 'SIMPLE', 'usuario' => $cred->usuario,
            'contrasena' => $cred->contrasena, 'clave_secreta' => $cred->clave_secreta,
        ]);

        // Un reintento reautenticando: el operador admite una sola sesión por
        // usuario, y si otra cosa entró con la misma credencial la de aquí se cae.
        $r = null;
        for ($i = 0; $i < 2; $i++) {
            $r = $api->liquidarPlanilla(
                $nit,
                implode("\r\n", array_merge([$cabecera], array_values($lineas))),
                $base['filename'],
                ['tipoArchivo' => 'I']
            );

            if ($r['success'] ?? false) {
                break;
            }

            $api->autenticar(true);
            usleep(1500000);
        }

        if (! ($r['success'] ?? false)) {
            $this->warn('El operador no respondió — '.substr($r['message'] ?? '(sin mensaje)', 0, 140));

            return [];
        }

        // Con el freno no debería pasar nunca, pero si llegara a liquidar hay
        // que saberlo de inmediato y no en el extracto.
        if ($r['liquidada'] ?? false) {
            Log::error('vigia-subtipo4: el sondeo LIQUIDÓ pese al freno', [
                'planilla' => $r['numero_planilla'] ?? null,
            ]);
            $this->error('¡El sondeo liquidó! planilla '.($r['numero_planilla'] ?? '?'));
        }

        $porCedula = array_fill_keys(array_keys($lineas), []);
        unset($porCedula['__freno']);

        foreach (array_merge($r['errores_cotizante'] ?? [], $r['errores_empresa'] ?? []) as $e) {
            $id = (string) ($e['identificacion'] ?? '');

            if (str_contains($id, self::FRENO_CEDULA)) {
                continue;
            }

            foreach (array_keys($porCedula) as $ced) {
                if (str_contains($id, $ced)) {
                    $porCedula[$ced][] = $e['idRegla'];
                }
            }
        }

        return array_map(fn ($v) => array_values(array_unique($v)), $porCedula);
    }

    /** La línea de la persona con la forma que se está vigilando. */
    private function lineaVigilada(string $modelo, object $cl, string $cedula, int $seq): string
    {
        $l = substr_replace($modelo, $this->n($seq, 5), 2, 5);
        $l = substr_replace($l, $this->a($cl->tipo_doc, 2), 7, 2);
        $l = substr_replace($l, $this->a($cedula, 16), 9, 16);
        $l = substr_replace($l, $this->a($cl->primer_apellido, 20), 36, 20);
        $l = substr_replace($l, $this->a($cl->segundo_apellido, 30), 56, 30);
        $l = substr_replace($l, $this->a($cl->primer_nombre, 20), 86, 20);
        $l = substr_replace($l, $this->a($cl->segundo_nombre, 30), 106, 30);
        $l = substr_replace($l, '04', 27, 2);                 // el subtipo que se vigila
        $l = substr_replace($l, $this->a('', 6), 153, 6);     // sin AFP
        $l = substr_replace($l, '00303030', 183, 8);
        $l = substr_replace($l, $this->n(0, 9), 201, 9);      // IBC pensión 0
        $l = substr_replace($l, '0.00000', 237, 7);
        $l = substr_replace($l, $this->n(0, 9), 244, 9);
        $l = substr_replace($l, $this->n(0, 9), 271, 9);
        $l = substr_replace($l, ' ', 136, 1);                 // sin ING
        $l = substr_replace($l, ' ', 148, 1);                 // sin VAC-LR
        $l = substr_replace($l, ' ', 30, 1);                  // sin marca de exterior

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

    /**
     * Alfanumérico de ancho fijo, con el mismo tratamiento que
     * PlanoPilaTxtService::A().
     *
     * El `iconv` no es cosmético: la Ñ y las tildes ocupan dos bytes en UTF-8 y
     * `substr`/`str_pad` cuentan bytes, así que un apellido como NUÑEZ corría la
     * línea entera un byte y el operador devolvía una cascada de errores de
     * formato (`2.C029`, `2.C030`, `2.C032`…). El sondeo informaba basura en vez
     * de responder si la persona pasa o no.
     */
    private function a(?string $v, int $largo): string
    {
        $v = mb_strtoupper(trim((string) $v), 'UTF-8');
        $v = iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $v);

        return str_pad(substr($v, 0, $largo), $largo, ' ', STR_PAD_RIGHT);
    }
}
