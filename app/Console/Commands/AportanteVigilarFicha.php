<?php

namespace App\Console\Commands;

use App\Models\ConfiguracionBrynex;
use App\Models\OperadorCredencial;
use App\Services\AlertaOperativaService;
use App\Services\SuaporteApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Vigila la ficha del aportante en Simple y avisa cuando algo cambia.
 *
 * La hipótesis viva sobre los pagos sin pensión es que el permiso no vive en el
 * archivo sino en la **configuración del aportante**: la ficha trae un bloque
 * `validacionExtra` con interruptores por empresa que no existen en la norma
 * PILA (duplicacionPlanilla, exoneradoPagoParafiscal,
 * reemplazaAdministradoraSaludCotizante…). Ver [[mega-tech-simple-sin-pension]].
 *
 * Si un tercero —Blindem, por ejemplo— mueve algo ahí para habilitar su
 * modalidad, no hay notificación de nadie: la ficha simplemente queda distinta.
 * Esto guarda una copia y compara, así que el cambio se ve el día que pasa y no
 * meses después.
 *
 * ## Solo lectura
 *
 * Lee `GET /gestion/aportante/{pyme|corporate}?id=` y nada más. No escribe en el
 * operador: cambiar el tipo de empresa o un interruptor de la ficha sería una
 * declaración a nombre de ese NIT.
 *
 * ## Dónde queda el rastro
 *
 * La copia de cada ficha en `storage/app/vigia-fichas/{nit}.json` (disco local,
 * nunca público: es configuración de empresas ajenas). El historial legible en
 * `storage/logs/vigia-fichas.log`.
 *
 * Ejecución manual: php artisan aportante:vigilar-ficha
 */
class AportanteVigilarFicha extends Command
{
    protected $signature = 'aportante:vigilar-ficha
        {--nits= : NITs a vigilar, separados por coma (pisa los configurados)}
        {--rebase : Guarda la ficha actual como línea base, sin comparar ni avisar}
        {--sin-aviso : Compara y registra, pero no manda WhatsApp}';

    protected $description = 'Compara la ficha del aportante en Simple contra la última copia y avisa si cambió';

    /** NITs vigilados. Sin configurar, no vigila nada. */
    private const CONFIG_NITS = 'vigia_fichas_nits';

    /** Carpeta de las copias, en el disco local. */
    private const CARPETA = 'vigia-fichas';

    /**
     * Campos que cambian por su cuenta y no significan nada: compararlos
     * llenaría el aviso de ruido y acabaría en que nadie lo lee.
     */
    private const IGNORAR = ['fechaConsulta', 'updatedAt', 'fechaActualizacion', 'token'];

    public function handle(AlertaOperativaService $alertas): int
    {
        $nits = $this->nitsAVigilar();

        if (! $nits) {
            $this->warn('Sin NITs que vigilar: configure '.self::CONFIG_NITS.'.');

            return self::SUCCESS;
        }

        $sello = now()->format('d-m H:i:s');
        $cambios = [];

        foreach ($this->porCredencial($nits) as $credId => $grupo) {
            $api = $this->api((int) $credId);

            if (! $api) {
                $this->warn("{$sello}  credencial {$credId} no disponible");

                continue;
            }

            foreach ($grupo as $nit => $nombre) {
                $ficha = $this->leerFicha($api, (string) $nit);

                if ($ficha === null) {
                    $this->line("{$sello}  NIT {$nit} ({$nombre}) → no se pudo leer");

                    continue;
                }

                $diff = $this->comparar((string) $nit, $ficha);

                if ($diff === null) {
                    $this->line("{$sello}  NIT {$nit} ({$nombre}) → línea base guardada");

                    continue;
                }

                if (! $diff) {
                    $this->line("{$sello}  NIT {$nit} ({$nombre}) → sin cambios");

                    continue;
                }

                $this->line("{$sello}  NIT {$nit} ({$nombre}) → ¡CAMBIÓ!");

                foreach ($diff as $linea) {
                    $this->line("{$sello}     {$linea}");
                }

                $cambios[] = "{$nombre} (NIT {$nit}):\n  ".implode("\n  ", $diff);
            }
        }

        if ($cambios && ! $this->option('sin-aviso')) {
            $alertas->enviarUnaVez(
                'vigia-fichas-'.now()->format('YmdH'),
                'Vigía de fichas',
                "⚠️ Cambió la configuración del aportante en Simple.\n\n"
                .implode("\n\n", $cambios)."\n\n"
                .'Nadie avisa de esto: la ficha simplemente queda distinta.',
                60
            );
            $this->info('Aviso enviado por WhatsApp.');
        }

        return self::SUCCESS;
    }

    /** @return string[] */
    private function nitsAVigilar(): array
    {
        $crudo = (string) ($this->option('nits')
            ?: ConfiguracionBrynex::obtener(self::CONFIG_NITS, ''));

        $nits = array_filter(array_map(
            fn ($n) => preg_replace('/\D/', '', trim($n)),
            explode(',', $crudo)
        ));

        return array_values(array_unique($nits));
    }

    /**
     * Agrupa los NITs por la credencial de Simple con la que hay que entrar:
     * el operador admite **una sola sesión por usuario**, así que todos los
     * aportantes de una misma credencial se leen con una sola sesión.
     *
     * @param  string[]  $nits
     * @return array<int, array<string, string>>
     */
    private function porCredencial(array $nits): array
    {
        $grupos = [];

        foreach ($nits as $nit) {
            $rs = DB::table('razones_sociales')
                ->whereRaw("REPLACE(REPLACE(nit, '-', ''), '.', '') = ?", [$nit])
                ->orderBy('id')
                ->first();

            if (! $rs) {
                $this->warn("El NIT {$nit} no está en razones_sociales.");

                continue;
            }

            // La del aliado primero: una credencial de una sola razón social
            // (la del vigía, solo HOMENURSE) no tiene permisos sobre las demás.
            $cred = OperadorCredencial::where('aliado_id', $rs->aliado_id)
                ->where('operador_planilla_id', 6)   // Simple
                ->orderByRaw('CASE WHEN razon_social_id IS NULL THEN 0 ELSE 1 END')
                ->first();

            if (! $cred) {
                $this->warn("El aliado {$rs->aliado_id} (NIT {$nit}) no tiene credencial de Simple.");

                continue;
            }

            $grupos[(int) $cred->id][$nit] = (string) $rs->razon_social;
        }

        return $grupos;
    }

    private function api(int $credId): ?SuaporteApiService
    {
        $cred = OperadorCredencial::find($credId);

        if (! $cred) {
            return null;
        }

        $api = new SuaporteApiService([
            'operador' => 'SIMPLE',
            'usuario' => $cred->usuario,
            'contrasena' => $cred->contrasena,
            'clave_secreta' => $cred->clave_secreta,
        ]);

        return ($api->autenticar(true)['success'] ?? false) ? $api : null;
    }

    /**
     * La ficha no se sirve de una: hay que pedir el aportante por NIT, pasar
     * por `autorizar` —la sesión trabaja sobre un aportante a la vez— y solo
     * entonces leerla. Sin el `autorizar` el operador responde «Unauthorized
     * request» aunque la autenticación esté bien.
     */
    private function leerFicha(SuaporteApiService $api, string $nit): ?array
    {
        // Un reintento reautenticando: si otra cosa entró con la misma
        // credencial —el vigía de subtipo 4, por ejemplo— la sesión se cae.
        for ($i = 0; $i < 2; $i++) {
            $a = $api->consultarAportante('NI', $nit);
            $id = $a['id'] ?? ($a['aportante']['id'] ?? null);

            if ($id) {
                $api->autorizar((int) $id, 'NI', $nit);
                $f = $api->consultarFichaAportante((int) $id);

                if ($f['success'] ?? false) {
                    return $f['ficha'];
                }
            }

            $api->autenticar(true);
            usleep(1500000);
        }

        return null;
    }

    /**
     * @return string[]|null null = no había copia (se acaba de guardar);
     *                       [] = sin cambios; si no, las diferencias
     */
    private function comparar(string $nit, array $ficha): ?array
    {
        $ruta = self::CARPETA."/{$nit}.json";
        $plano = $this->aplanar($ficha);
        $guardar = fn () => Storage::disk('local')->put(
            $ruta, json_encode($plano, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        if ($this->option('rebase') || ! Storage::disk('local')->exists($ruta)) {
            $guardar();

            return null;
        }

        $antes = json_decode((string) Storage::disk('local')->get($ruta), true) ?: [];
        $diff = [];

        foreach (array_unique(array_merge(array_keys($antes), array_keys($plano))) as $k) {
            $a = $antes[$k] ?? '(no estaba)';
            $b = $plano[$k] ?? '(desapareció)';

            if ($a !== $b) {
                $diff[] = "{$k}: {$a} → {$b}";
            }
        }

        if ($diff) {
            $guardar();
        }

        return $diff;
    }

    /**
     * Aplana la ficha a `clave.subclave => valor` para poder comparar y, sobre
     * todo, para que el aviso diga QUÉ cambió y no solo que cambió algo.
     *
     * @return array<string, string>
     */
    private function aplanar(array $datos, string $prefijo = ''): array
    {
        $plano = [];

        foreach ($datos as $k => $v) {
            if (in_array($k, self::IGNORAR, true)) {
                continue;
            }

            $clave = $prefijo ? "{$prefijo}.{$k}" : (string) $k;

            if (is_array($v)) {
                $plano += $this->aplanar($v, $clave);

                continue;
            }

            $plano[$clave] = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
        }

        ksort($plano);

        return $plano;
    }
}
