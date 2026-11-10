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
use Illuminate\Http\Request;
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
        {--credencial= : Id de la credencial de Simple con la que entra (pisa la configurada)}
        {--cada= : Minutos entre sondeos programados (pisa la configuración)}
        {--programado : Lo pone el planificador: respeta la cadencia configurada}
        {--medir-cada= : Segundos entre sondeos mientras la ventana está abierta}
        {--medir-max= : Minutos máximos midiendo una ventana abierta}
        {--sin-medir : No se queda midiendo cuando la ventana se abre}
        {--sin-liquidar : Detecta y avisa, pero no liquida las tandas en espera}
        {--simular-ventana : Dispara la liquidación aunque el hueco no esté abierto (prueba)}
        {--sin-aviso : Sondea y registra, pero no manda WhatsApp}';

    protected $description = 'Sondea si el operador ya deja pasar el subtipo 03/04 y avisa por WhatsApp cuando sí';

    /** La regla que estamos esperando que desaparezca. */
    private const REGLA_UGPP = 'eo.val.2.655';

    /** Cédula del freno: no existe, así que su línea siempre falla. */
    private const FRENO_CEDULA = '9999999999';

    /** Cédulas que se vigilan siempre, aunque no tengan plano pendiente. */
    private const CONFIG_CEDULAS = 'vigia_subtipo4_cedulas';

    /**
     * Minutos entre sondeos programados. Vive en configuración y no en el
     * planificador para poder apretar la cadencia —de 5 a 1 minuto— sin
     * desplegar, que en este proyecto significa tocar producción.
     */
    private const CONFIG_CADA = 'vigia_subtipo4_cada_min';

    private const CADA_POR_DEFECTO = 5;

    /**
     * Credencial de Simple con la que entra el vigía.
     *
     * El operador admite **una sola sesión por usuario**, así que si el robot
     * entra con el mismo usuario que la gente, cada sondeo le tumba la sesión
     * a quien esté trabajando en el portal —y cuanto más apretada la cadencia,
     * peor—. Con un usuario propio para los robots son dos sesiones y no se
     * estorban. Sin configurar, toma la primera credencial del aliado, que es
     * el comportamiento viejo.
     */
    private const CONFIG_CREDENCIAL = 'vigia_subtipo4_credencial_id';

    /**
     * Cuánto se queda midiendo una ventana abierta.
     *
     * Mientras mide, el vigía tiene tomada la sesión del operador —una sola
     * por usuario—, así que una medición larga le bloquea el paso a la
     * liquidación que la ventana venía a permitir. Vive en configuración para
     * poder acortarla cuando hay planillas esperando, sin desplegar.
     */
    private const CONFIG_MEDIR_CADA = 'vigia_subtipo4_medir_cada_seg';

    private const CONFIG_MEDIR_MAX = 'vigia_subtipo4_medir_max_min';

    /**
     * Aportante con el que se sondea: tiene que ser uno viejo y bien
     * configurado, o los errores de su propia ficha —una sucursal sin asociar,
     * por ejemplo— tapan lo que se quiere ver.
     */
    private const CONFIG_RS_REF = 'vigia_subtipo4_rs_ref';

    /**
     * Si el vigía liquida solo cuando encuentra el hueco (1) o solo avisa (0).
     *
     * El 9-nov-2026 el hueco de las 11:00 ya estaba cerrado a las 11:03, y de
     * los tres medidos hasta esa fecha dos cayeron de noche —viernes 8 p. m. y
     * domingo 1 a. m.—. Un hueco de menos de tres minutos a esas horas no lo
     * alcanza nadie a mano: cuando llega el WhatsApp ya pasó. El vigía, en
     * cambio, acaba de hablar con el operador y liquida en segundos.
     */
    private const CONFIG_LIQUIDAR = 'vigia_subtipo4_liquidar';

    /**
     * Las tandas que están esperando el hueco, en JSON:
     *
     *   [{"etiqueta":"Yuly","aliado":2,"rs":28,"mes":11,"anio":2026,
     *     "n_plano":2,"mods":[-14]}, …]
     *
     * Van en configuración y no en el código porque la lista cambia cada mes y
     * el hueco no espera un despliegue. `mes`/`anio` son el período de **pago**
     * —el plano es el mes de servicio—, y cada familia de modalidades va en su
     * propio archivo, así que una razón social con X1 y X1 TP14 son dos tandas.
     */
    private const CONFIG_TANDAS = 'vigia_subtipo4_tandas';

    /**
     * Reintentos de la liquidación dentro de un mismo hueco, y la espera entre
     * ellos. El hueco del 10-nov duró 36 s y se cayó al autorizar: 4 intentos
     * cada 3 s caben de sobra en esa ventana y le dan varias oportunidades al
     * backend de responder. No es insistir contra el validador —una rechazada
     * por regla corta el reintento—, es aguantar un tropiezo de red.
     */
    private const CONFIG_INTENTOS = 'vigia_subtipo4_intentos';

    private const CONFIG_INTENTO_ESPERA = 'vigia_subtipo4_intento_espera_seg';

    public function handle(AlertaOperativaService $alertas): int
    {
        $mes = (int) ($this->option('mes') ?: now()->month);
        $anio = (int) ($this->option('anio') ?: now()->year);

        Auth::loginUsingId(2);   // ver [[feedback-usuario-brynex-comandos]]

        if (! $this->tocaSondear()) {
            return self::SUCCESS;
        }

        $cedulas = $this->cedulasAVigilar($mes, $anio);

        if (! $cedulas) {
            $this->info('No hay cédulas que vigilar.');

            return self::SUCCESS;
        }

        $limpias = $this->sondeoConLog($cedulas, $mes, $anio);

        // Antes del aviso y antes de medir: los dos se llevan segundos que el
        // hueco puede no tener, y medir además le toma la sesión al operador.
        //
        // `--simular-ventana` es para probar este camino a mano: la liquidación
        // automática solo se ejecuta de verdad cuando el hueco se abre, y el
        // hueco aparece una vez al día y dura minutos, así que sin esto la
        // primera ejecución de la vida sería a ciegas y de madrugada. El
        // archivo que sale es el real: si el hueco está cerrado el operador lo
        // rechaza con 2.655 y no se crea ninguna planilla.
        $liquidadas = ($limpias || $this->option('simular-ventana'))
            ? $this->liquidarPendientes()
            : [];

        if ($limpias && ! $this->option('sin-aviso')) {
            // Una vez cada 6 h: si la ventana dura, no conviene repetir el aviso
            // cada media hora, pero sí recordarlo si sigue abierta un rato después.
            $alertas->enviarUnaVez(
                'vigia-subtipo4-'.implode('-', $limpias),
                'Vigía subtipo 4',
                "✅ El operador ya deja pasar el subtipo de cotizante 03/04.\n\n"
                .'Cédulas que pasaron limpias: '.implode(', ', $limpias)."\n"
                ."Período {$mes}/{$anio}.\n\n"
                .($liquidadas
                    ? "Liquidación automática:\n".implode("\n", $liquidadas)
                    : 'Entra a liquidar ahora: la ventana puede cerrarse.'),
                360
            );
            $this->info('Aviso enviado por WhatsApp.');
        }

        if ($limpias && ! $this->option('sin-medir')) {
            $this->medirVentana($alertas, $cedulas, $mes, $anio, $limpias);
        }

        return self::SUCCESS;
    }

    /**
     * Si la corrida viene del planificador, solo sondea cuando le toca por la
     * cadencia configurada. El planificador lo llama cada minuto y este if es
     * el que decide, así que apretar o soltar la cadencia es cambiar una fila
     * de configuración, no desplegar.
     *
     * Ojo con subirla: cada sondeo toma la sesión del operador, que admite
     * **una sola por usuario**, así que a un minuto el vigía atropella
     * cualquier trabajo manual en Simple hecho con esa misma credencial.
     */
    private function tocaSondear(): bool
    {
        if (! $this->option('programado')) {
            return true;   // a mano, siempre
        }

        $cada = (int) ($this->option('cada')
            ?: ConfiguracionBrynex::obtener(self::CONFIG_CADA, self::CADA_POR_DEFECTO));

        if ($cada < 1 || $cada > 60 || 60 % $cada !== 0) {
            $cada = self::CADA_POR_DEFECTO;
        }

        return now()->minute % $cada === 0;
    }

    /**
     * Liquida las tandas que estaban esperando el hueco, en el mismo instante
     * en que el sondeo lo encuentra.
     *
     * Pasa por `PlanillaApiController::liquidar`, el mismo camino que el botón
     * del panel: ahí viven el rechazo de la tanda mezclada, el filtro por
     * aliado, la validación del operador y de la credencial, y el registro del
     * número de planilla en los planos. Insertar la planilla a mano se saltaría
     * todo eso.
     *
     * **No duplica.** El controlador responde 409 cuando la tanda ya tiene
     * planilla liquidada y aquí nunca se manda `reliquidar`, así que si el
     * hueco dura y el vigía vuelve a entrar un minuto después, la segunda
     * corrida se topa con el 409 y no crea nada. Es la única barrera que
     * importa: lo demás son sondeos con freno que no liquidan jamás.
     *
     * @return string[] una línea por tanda, para el aviso
     */
    private function liquidarPendientes(): array
    {
        if ($this->option('sin-liquidar')) {
            return [];
        }

        if (! (int) ConfiguracionBrynex::obtener(self::CONFIG_LIQUIDAR, 0)) {
            $this->line(now()->format('d-m H:i:s').'  (liquidación automática apagada)');

            return [];
        }

        $tandas = json_decode((string) ConfiguracionBrynex::obtener(self::CONFIG_TANDAS, ''), true);

        if (! is_array($tandas) || ! $tandas) {
            $this->warn('Liquidación automática prendida pero sin tandas en '.self::CONFIG_TANDAS.'.');

            return [];
        }

        $lineas = [];

        foreach ($tandas as $t) {
            if (! is_array($t) || empty($t['aliado']) || empty($t['rs'])) {
                continue;
            }

            $etiqueta = (string) ($t['etiqueta'] ?? ('rs '.$t['rs']));
            $lineas[] = $etiqueta.': '.$this->liquidarTanda($t, $etiqueta);
        }

        return $lineas;
    }

    /**
     * Una tanda. Devuelve el texto que va al aviso y al registro.
     *
     * @param  array<string, mixed>  $t
     */
    private function liquidarTanda(array $t, string $etiqueta): string
    {
        // El 10-nov-2026 el hueco de las 12:51 duró 36 s y la liquidación se
        // cayó con "Empty reply from server" al autorizarse sobre el aportante:
        // el hueco se abre PORQUE el backend de Simple está caído, que es justo
        // cuando autorizar y liquidar también fallan. Un reintento rápido —la
        // ventana sigue abierta unos segundos— puede entrar donde el primero
        // tropezó. Acotado y corto: es manejo de un error transitorio, no
        // insistir contra una puerta que el validador ya cerró con 2.655.
        $intentos = max(1, (int) ConfiguracionBrynex::obtener(self::CONFIG_INTENTOS, 4));
        $esperaSeg = max(1, (int) ConfiguracionBrynex::obtener(self::CONFIG_INTENTO_ESPERA, 3));

        $texto = '(sin intento)';

        for ($i = 1; $i <= $intentos; $i++) {
            [$estado, $texto] = $this->intentarLiquidar($t, $etiqueta);

            $sello = now()->format('d-m H:i:s');
            $this->line("{$sello}  >> {$etiqueta} (intento {$i}/{$intentos}) → {$texto}");

            // Solo se reintenta el tropiezo del backend. Liquidó, ya estaba, o
            // el validador la rechazó por regla (hueco cerrado): en los tres no
            // hay nada que reintentar.
            if ($estado !== 'transitorio' || $i === $intentos) {
                break;
            }

            sleep($esperaSeg);
        }

        return $texto;
    }

    /**
     * Un intento de liquidar una tanda. Devuelve [estado, texto].
     *
     * estado: 'liquidada' | 'ya' (409) | 'rechazada' (regla, hueco cerrado) |
     *         'transitorio' (el backend se cayó; vale la pena reintentar).
     *
     * @param  array<string, mixed>  $t
     * @return array{0: string, 1: string}
     */
    private function intentarLiquidar(array $t, string $etiqueta): array
    {
        try {
            // El controlador lee el aliado de la sesión, igual que con un
            // usuario en el navegador, y de ahí sale el filtro multi-tenant.
            session(['aliado_id_activo' => (int) $t['aliado']]);

            $req = Request::create('/admin/planilla-api/liquidar', 'POST', [
                'razon_social_id' => (int) $t['rs'],
                'operador_planilla_id' => (int) ($t['operador'] ?? 6),   // Simple
                'mes' => (int) ($t['mes'] ?? now()->month),
                'anio' => (int) ($t['anio'] ?? now()->year),
                'n_plano' => (int) ($t['n_plano'] ?? 1),
                'tipos_modalidad' => array_map('intval', (array) ($t['mods'] ?? [])),
            ]);
            $req->setLaravelSession(app('session.store'));
            $req->setUserResolver(fn () => Auth::user());

            $resp = app(\App\Http\Controllers\Admin\PlanillaApiController::class)->liquidar($req);
            $data = json_decode($resp->getContent(), true) ?: [];
            $http = $resp->getStatusCode();

            $planilla = $data['numero_planilla']
                ?? ($data['planilla']['numero_planilla'] ?? null);

            if (($data['success'] ?? false) && ($data['liquidada'] ?? false) && $planilla) {
                Log::warning('vigia-subtipo4: liquidó en la ventana', [
                    'tanda' => $etiqueta, 'planilla' => $planilla,
                ]);

                return ['liquidada', "planilla {$planilla}"];
            }

            if ($http === 409) {
                return ['ya', 'ya tenía planilla '.($planilla ?: '').' (409), no se tocó'];
            }

            $reglas = collect(array_merge(
                $data['errores_cotizante'] ?? [], $data['errores_empresa'] ?? []
            ))->pluck('idRegla')->filter()->unique()->values();

            $mensaje = (string) ($data['message'] ?? '(sin mensaje)');

            // El validador respondió y rechazó por regla: el hueco ya se cerró,
            // reintentar no cambia nada.
            if ($reglas->isNotEmpty()) {
                Log::error('vigia-subtipo4: la ventana se cerró antes de liquidar', [
                    'tanda' => $etiqueta, 'reglas' => $reglas->all(),
                ]);

                return ['rechazada', "rechazada · {$reglas->take(6)->implode(' ')}"];
            }

            // Ni planilla ni reglas: el backend tropezó (autorizar vacío, 5xx…).
            // Eso sí vale la pena reintentarlo mientras el hueco siga abierto.
            Log::error('vigia-subtipo4: tropiezo del backend al liquidar en la ventana', [
                'tanda' => $etiqueta, 'http' => $http, 'respuesta' => $data,
            ]);

            return ['transitorio', "backend caído · http {$http} · ".substr($mensaje, 0, 120)];
        } catch (\Throwable $e) {
            // cURL 52 (Empty reply) y compañía llegan como excepción: también
            // es el backend cayéndose, también se reintenta.
            Log::error('vigia-subtipo4: excepción liquidando en la ventana', [
                'tanda' => $etiqueta, 'error' => $e->getMessage(),
            ]);

            return ['transitorio', 'backend caído · '.substr($e->getMessage(), 0, 120)];
        }
    }

    /**
     * Un sondeo con su línea en el registro por cada cédula.
     *
     * @param  string[]  $cedulas
     * @return string[] las cédulas que pasaron sin la regla de la UGPP
     */
    private function sondeoConLog(array $cedulas, int $mes, int $anio): array
    {
        $resultado = $this->sondear($cedulas, $mes, $anio);
        $limpias = [];
        $sello = now()->format('d-m H:i:s');

        foreach ($resultado as $cedula => $errores) {
            $this->line(sprintf('%s  CC %-12s → %s', $sello, $cedula,
                $errores ? implode(' ', $errores) : 'LIMPIO'));

            if (! in_array(self::REGLA_UGPP, $errores, true)) {
                $limpias[] = $cedula;
            }
        }

        return $limpias;
    }

    /**
     * Mientras la ventana siga abierta, vuelve a sondear cada pocos segundos
     * para saber cuánto dura.
     *
     * El 9-nov-2026 el aviso de la corrida de las 11:00 llegó con la ventana ya
     * cerrada: a las 11:03 las tres cédulas volvían a salir con `2.655`, y
     * cinco sondas seguidas lo confirmaron. Las tres a la vez y solo en esa
     * corrida: lo que falla es la consulta de Simple a la lista de la UGPP, y
     * su validador **falla abierto**. Sin esto el registro dice que pasó, pero
     * no cuánto duró, que es justo el dato que decide si se puede aprovechar.
     *
     * @param  string[]  $cedulas
     * @param  string[]  $primeras
     */
    private function medirVentana(
        AlertaOperativaService $alertas,
        array $cedulas,
        int $mes,
        int $anio,
        array $primeras
    ): void {
        $cadaSeg = max(5, (int) ($this->option('medir-cada')
            ?: ConfiguracionBrynex::obtener(self::CONFIG_MEDIR_CADA, 30)));
        $maxMin = max(1, (int) ($this->option('medir-max')
            ?: ConfiguracionBrynex::obtener(self::CONFIG_MEDIR_MAX, 30)));

        $abrio = now();
        $ultimaLimpia = $abrio;
        $sondeos = 1;
        $cerrada = false;

        $this->line($abrio->format('d-m H:i:s')
            ."  >> ventana ABIERTA, midiendo cada {$cadaSeg}s (máx {$maxMin} min)");

        while ($abrio->diffInMinutes(now()) < $maxMin) {
            sleep($cadaSeg);
            $sondeos++;

            if (! $this->sondeoConLog($cedulas, $mes, $anio)) {
                $cerrada = true;
                break;
            }

            $ultimaLimpia = now();
        }

        $duracion = $this->duracion($abrio, $ultimaLimpia);

        $this->line(now()->format('d-m H:i:s').'  << ventana '
            .($cerrada ? 'CERRADA' : 'SIGUE ABIERTA')
            ." · duró {$duracion} · {$sondeos} sondeos");

        if ($this->option('sin-aviso')) {
            return;
        }

        $alertas->enviarUnaVez(
            'vigia-subtipo4-duracion-'.$abrio->format('YmdHi'),
            'Vigía subtipo 4',
            ($cerrada
                ? "⏱️ La ventana del subtipo 03/04 se cerró. Duró {$duracion}.\n\n"
                : "⏱️ La ventana del subtipo 03/04 lleva {$duracion} abierta y sigue.\n\n")
            .'Abrió a las '.$abrio->format('H:i:s')
            .', último sondeo limpio a las '.$ultimaLimpia->format('H:i:s').".\n"
            .'Cédulas: '.implode(', ', $primeras)."\n"
            ."Período {$mes}/{$anio} · {$sondeos} sondeos cada {$cadaSeg}s.",
            60
        );
    }

    /** "4 min 30 s", o "45 s" si no alcanza el minuto. */
    private function duracion(\Carbon\Carbon $desde, \Carbon\Carbon $hasta): string
    {
        $s = max(0, $hasta->diffInSeconds($desde));

        return $s < 60 ? "{$s} s" : intdiv($s, 60).' min '.($s % 60).' s';
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
        $credId = (int) ($this->option('credencial')
            ?: ConfiguracionBrynex::obtener(self::CONFIG_CREDENCIAL, 0));

        $cred = OperadorCredencial::where('operador_planilla_id', 6)   // Simple: el único que liquida X1
            ->when($credId, fn ($q) => $q->where('id', $credId))
            ->when(! $credId, fn ($q) => $q->where('aliado_id', $rs->aliado_id))
            ->first();

        if (! $cred) {
            $this->warn($credId
                ? "La credencial de Simple {$credId} no existe."
                : "El aliado {$rs->aliado_id} no tiene credencial de Simple.");

            return [];
        }

        // Una credencial pineada que no sea del aportante de referencia manda
        // el archivo con un usuario que no lo representa: el operador responde
        // errores de permisos y el sondeo informa basura en vez de decir si la
        // persona pasa.
        if ($credId && (int) $cred->aliado_id !== (int) $rs->aliado_id) {
            $this->warn("La credencial {$credId} es del aliado {$cred->aliado_id}, "
                ."pero la razón social de referencia es del {$rs->aliado_id}.");

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
