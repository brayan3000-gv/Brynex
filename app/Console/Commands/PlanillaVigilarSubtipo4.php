<?php

namespace App\Console\Commands;

use App\Models\ConfiguracionBrynex;
use App\Models\OperadorCredencial;
use App\Models\OperadorPlanillaApi;
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
        {--rs-ref= : Razones sociales a sondear bajo su NIT, separadas por coma (pisa las configuradas)}
        {--credencial= : Id de la credencial de Simple con la que entra (pisa la configurada)}
        {--cada= : Minutos entre sondeos programados (pisa la configuración)}
        {--cada-seg= : Segundos entre sondeos dentro de cada minuto (pisa la configuración; 0 = por minutos)}
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

    /**
     * El 2.379 NO se vigila a propósito. Es un cruce de salud de ADRES ("el
     * cotizante aporta a la EPS X pero ADRES sugiere la EPS Y"), no la lista
     * negra de la UGPP, y solo aparece porque el sondeo copia una línea de
     * referencia que reporta salud a una EPS que no es la real de la persona.
     * En los planos reales (cotizante 51, sin salud) nunca sale. Es otra fuente
     * externa distinta del 2.655, así que no sirve para detectar el hueco: lo
     * que marca si la validación UGPP cayó es el 2.655, y si el portal entero
     * cayó lo dice que el sondeo responda o no (ver $portalRespondio).
     */

    /** Cédula del freno: no existe, así que su línea siempre falla. */
    private const FRENO_CEDULA = '9999999999';

    /**
     * Latencia del último sondeo al validador, en milisegundos. La escribe
     * sondear() y la lee el log: la hipótesis es que cuando Simple no logra
     * consultar la UGPP (y se abre el hueco) la validación responde más rápido
     * porque se salta esa llamada externa. Si se confirma, la latencia es un
     * aviso anticipado del hueco.
     */
    private ?int $latenciaSondeoMs = null;

    /**
     * Si el operador respondió el último sondeo. Es lo que separa los dos
     * mundos: con el portal arriba (respondió) y el 2.655 ausente, cayó solo
     * la validación de la UGPP → hueco útil, se puede liquidar. Si el portal no
     * respondió (autenticar/autorizar/validar fallan), cayó el portal entero →
     * no hay nada que liquidar, aunque el 2.655 "no aparezca".
     */
    private bool $portalRespondio = false;

    /** Por qué no respondió el portal (paso + mensaje), para el log. */
    private string $portalMotivo = '';

    /**
     * Razones sociales cuyo validador dejó pasar el subtipo 4 en el último
     * sondeo (2.655 ausente con el portal arriba) = hueco por ficha de ese
     * aportante. Lo llena sondeoConLog y lo lee el aviso para nombrar cuál abrió.
     *
     * @var int[]
     */
    private array $aportantesAbiertos = [];

    /**
     * Tandas que ya tienen planilla liquidada y siguen sin pagar, con su
     * registro. Las llena tandasEnEspera() y liquidarPendientes() les pide a
     * Simple un enlace de pago nuevo mientras el hueco está abierto.
     *
     * @var array<int, array{0: array<string, mixed>, 1: OperadorPlanillaApi}>
     */
    private array $sinPagar = [];

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
     * Segundos entre sondeos, para vigilar por debajo del minuto (10, 15, 20
     * o 30). En 0 —o sin configurar— manda la cadencia por minutos.
     *
     * El planificador solo llama cada minuto, así que el comando se queda ese
     * minuto haciendo varias vueltas. Tiene sentido solo con un usuario de
     * Simple propio para el vigía (`vigia_subtipo4_credencial_id`): con el de
     * la gente, sondear cada 15 s le tumbaría la sesión a quien esté
     * liquidando, y también la del enlace de pago recién pedido.
     */
    private const CONFIG_CADA_SEG = 'vigia_subtipo4_cada_seg';

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
     * Razones sociales a sondear, separadas por coma. Cada una viaja bajo SU
     * propio NIT (su cabecera), así que el sondeo prueba la ficha de cada
     * aportante por separado.
     *
     * Antes era una sola (la 35, neutra): solo veía el hueco GLOBAL de la UGPP
     * —la lista negra 2.655, que es por cotizante y no depende del aportante—.
     * Pero la config que de verdad abre el paso puede vivir en la ficha del
     * aportante (validación extra / pago asistido): si Simple se la cambia a
     * ELITES o Construtech y no a las demás, el subtipo 4 pasaría solo en esa
     * razón social. Por eso se vigila una lista —ELITES 37, Construtech 28,
     * Elitex 38— con la 35 de control: si el hueco abre en las SAS pero no en
     * la 35, fue config de ficha; si abre en las cuatro, fue el hueco global.
     *
     * Cada razón social tiene que ser una bien configurada y con al menos un
     * cotizante en el período (presta el formato de línea), o los errores de su
     * propia ficha —una sucursal sin asociar, por ejemplo— tapan lo que se
     * quiere ver.
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
     * Aliados cuyas tandas X1 pendientes se arman solas desde los planos, sin
     * tener que escribirlas en `vigia_subtipo4_tandas`. Separados por coma.
     *
     * Antes cada mes había que copiar a mano aliado, razón social, plano y
     * modalidad de cada tanda —y la que se olvidaba no se liquidaba en el
     * hueco—. Ahora se toman de los planos X1 que siguen sin planilla. La lista
     * de aliados existe porque liquidar crea una planilla real en el operador
     * a nombre de la empresa: se hace solo para quien lo autorizó (Brygar, el
     * 2). Un aliado sin credencial de Simple se salta aunque esté en la lista,
     * porque X1 solo liquida por Simple.
     */
    private const CONFIG_ALIADOS = 'vigia_subtipo4_aliados';

    private const ALIADOS_POR_DEFECTO = '2';

    /** Simple: el único operador que liquida Tipo E - X1. */
    private const OPERADOR_SIMPLE = 6;

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

        $cadaSeg = $this->cadaSegundos();

        if ($this->option('programado') && $cadaSeg > 0) {
            $this->vueltasDelMinuto($alertas, $mes, $anio, $cadaSeg);

            return self::SUCCESS;
        }

        if (! $this->tocaSondear()) {
            return self::SUCCESS;
        }

        $this->vuelta($alertas, $mes, $anio);

        return self::SUCCESS;
    }

    /**
     * Varias vueltas dentro del minuto que da el planificador, una cada
     * `$cadaSeg` segundos contados desde que arrancó (a 15 s: 0, 15, 30 y 45).
     * La última arranca a tiempo de terminar antes del minuto siguiente. Si un
     * sondeo se demora y se come un turno, ese turno se salta en vez de
     * encadenar sondeos seguidos. Si una vuelta encuentra el hueco, ya liquidó
     * y midió la ventana: no se sigue.
     */
    private function vueltasDelMinuto(AlertaOperativaService $alertas, int $mes, int $anio, int $cadaSeg): void
    {
        $inicio = time();

        for ($k = 0; $k * $cadaSeg <= 60 - $cadaSeg; $k++) {
            $espera = $inicio + $k * $cadaSeg - time();

            if ($espera > 0) {
                sleep($espera);
            } elseif ($k > 0 && -$espera >= $cadaSeg) {
                continue;
            }

            if ($this->vuelta($alertas, $mes, $anio)) {
                return;
            }
        }
    }

    /** 10, 15, 20 o 30; cualquier otra cosa (o nada) es 0 = por minutos. */
    private function cadaSegundos(): int
    {
        $seg = (int) ($this->option('cada-seg')
            ?? ConfiguracionBrynex::obtener(self::CONFIG_CADA_SEG, 0));

        return in_array($seg, [10, 15, 20, 30], true) ? $seg : 0;
    }

    /**
     * Un sondeo completo: sondea, y si el hueco está abierto liquida, avisa y
     * mide. Devuelve si encontró el hueco.
     */
    private function vuelta(AlertaOperativaService $alertas, int $mes, int $anio): bool
    {
        $cedulas = $this->cedulasAVigilar($mes, $anio);

        if (! $cedulas) {
            $this->info('No hay cédulas que vigilar.');

            return false;
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
        $resultados = ($limpias || $this->option('simular-ventana'))
            ? $this->liquidarPendientes($mes, $anio)
            : [];

        $hechas = array_filter($resultados, fn ($r) => $r['estado'] === 'liquidada');
        $enlaces = array_filter($resultados, fn ($r) => $r['estado'] === 'enlace');

        if (($limpias || $hechas || $enlaces) && ! $this->option('sin-aviso')) {
            $abiertos = $this->nombresRs($this->aportantesAbiertos);

            $mensaje = "✅ El operador ya deja pasar el subtipo de cotizante 03/04.\n\n"
                .($abiertos ? "Aportante(s) con hueco: {$abiertos}\n" : '')
                .($limpias ? 'Cédulas que pasaron limpias: '.implode(', ', $limpias)."\n" : '')
                ."Período {$mes}/{$anio}.\n\n"
                .($resultados
                    ? "Liquidación automática:\n\n".implode("\n\n", array_map(
                        fn ($r) => $this->lineaDelAviso($r), $resultados))
                    : 'Entra a liquidar ahora: la ventana puede cerrarse.');

            if ($hechas) {
                // Con planilla nueva el aviso sale siempre: trae el enlace para
                // pagarla y no puede quedar tapado por el de un hueco anterior.
                // No hay riesgo de repetirlo: cada planilla nace una sola vez
                // (el 409 del controlador y tandasEnEspera() lo impiden).
                $alertas->enviar('Vigía subtipo 4', $mensaje);
            } elseif ($enlaces) {
                // Enlace nuevo para una planilla que ya existía: si el hueco dura
                // varios minutos, el planificador vuelve a entrar y lo vuelve a
                // pedir. Un aviso por planilla cada 20 minutos basta.
                $alertas->enviarUnaVez(
                    'vigia-subtipo4-pago-'.implode('-', array_column($enlaces, 'planilla')),
                    'Vigía subtipo 4',
                    $mensaje,
                    20
                );
            } else {
                // Una vez cada 6 h: si la ventana dura, no conviene repetir el
                // aviso cada media hora, pero sí recordarlo si sigue abierta un
                // rato después. La llave incluye los aportantes que abrieron: un
                // hueco en ELITES y otro en Construtech son avisos distintos.
                $alertas->enviarUnaVez(
                    'vigia-subtipo4-'.implode('-', $this->aportantesAbiertos).'-'.implode('-', $limpias),
                    'Vigía subtipo 4',
                    $mensaje,
                    360
                );
            }
            $this->info('Aviso enviado por WhatsApp.');
        }

        if ($limpias && ! $this->option('sin-medir')) {
            $this->medirVentana($alertas, $cedulas, $mes, $anio, $limpias);
        }

        return (bool) $limpias;
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
     * @return array<int, array{etiqueta: string, estado: string, texto: string,
     *               planilla: ?string, valor: ?float, enlace: string}>
     */
    private function liquidarPendientes(int $mes, int $anio): array
    {
        if ($this->option('sin-liquidar')) {
            return [];
        }

        if (! (int) ConfiguracionBrynex::obtener(self::CONFIG_LIQUIDAR, 0)) {
            $this->line(now()->format('d-m H:i:s').'  (liquidación automática apagada)');

            return [];
        }

        $tandas = $this->tandasEnEspera($mes, $anio);

        if (! $tandas && ! $this->sinPagar) {
            $this->line(now()->format('d-m H:i:s').'  (sin tandas X1 esperando el hueco)');

            return [];
        }

        $resultados = [];

        foreach ($tandas as $t) {
            $resultados[] = $this->liquidarTanda($t, $t['etiqueta']) + [
                'etiqueta' => $t['etiqueta'],
                'enlace' => $this->enlaceDelPlano($t),
            ];
        }

        // Después de las nuevas: lo primero que el hueco tiene que alcanzar es
        // a crear las planillas que faltan.
        foreach ($this->sinPagar as [$t, $registro]) {
            $resultados[] = $this->renovarPago($t, $registro);
        }

        return $resultados;
    }

    /**
     * Pide a Simple un enlace de pago nuevo para una X1 liquidada y sin pagar.
     *
     * Simple vuelve a validar la planilla antes de entregar el enlace: fuera
     * del hueco responde «La planilla contiene errores» (el 2.655), y el enlace
     * que dio al liquidar caduca —el de la 1086799346, del 13-nov a las 17:47,
     * a las 20:38 ya mandaba al login—. Así que una X1 liquidada en un hueco y
     * no pagada a tiempo solo se puede pagar en el siguiente, y este es el
     * momento de pedirle el enlace: el aviso lo lleva y hay que pagar ya.
     *
     * @param  array<string, mixed>  $t
     * @return array<string, mixed>
     */
    private function renovarPago(array $t, OperadorPlanillaApi $registro): array
    {
        $base = [
            'etiqueta' => $t['etiqueta'],
            'enlace' => $this->enlaceDelPlano($t),
            'planilla' => (string) $registro->numero_planilla,
            'valor' => $registro->valor_total !== null ? (float) $registro->valor_total : null,
        ];

        try {
            session(['aliado_id_activo' => $t['aliado']]);
            $pago = app(\App\Http\Controllers\Admin\PlanillaApiController::class)
                ->renovarUrlPago($registro, $t['aliado']);
        } catch (\Throwable $e) {
            $pago = ['success' => false, 'message' => $e->getMessage()];
        }

        $sello = now()->format('d-m H:i:s');

        if ($pago['success']) {
            Log::warning('vigia-subtipo4: enlace de pago renovado en la ventana', [
                'tanda' => $t['etiqueta'], 'planilla' => $registro->numero_planilla,
            ]);
            $this->line("{$sello}  >> {$t['etiqueta']} planilla {$registro->numero_planilla} → enlace de pago nuevo");

            return $base + ['estado' => 'enlace', 'texto' => 'enlace de pago nuevo'];
        }

        $mensaje = (string) ($pago['message'] ?? '(sin mensaje)');
        $this->line("{$sello}  >> {$t['etiqueta']} planilla {$registro->numero_planilla} → sin enlace · ".substr($mensaje, 0, 120));

        $pagada = str_contains(mb_strtolower($mensaje), 'pagada') || str_contains(mb_strtolower($mensaje), 'proceso de pago');

        return $base + [
            'estado' => $pagada ? 'pagada' : 'sin_enlace',
            'texto' => $pagada
                ? "planilla {$registro->numero_planilla}: Simple dice que ya está pagada o en proceso de pago. Si se pagó, confírmala con «Ya pagué»."
                : "planilla {$registro->numero_planilla} sin pagar, Simple no dio enlace · ".substr($mensaje, 0, 120),
        ];
    }

    /**
     * Las tandas que se intentan liquidar en este hueco.
     *
     * Salen de dos lados: las escritas a mano en `vigia_subtipo4_tandas` y las
     * que arman los planos X1 que siguen sin planilla (tandasDesdePlanos). Se
     * juntan sin repetir y se descartan las que ya tienen planilla liquidada
     * en el operador: el plano no recibe el número hasta que se confirma el
     * pago, así que una tanda liquidada y sin pagar sigue "pendiente" en los
     * planos, y volver a mandarla en cada hueco solo gastaría segundos que el
     * hueco no tiene para toparse con el 409.
     *
     * @return array<int, array<string, mixed>>
     */
    private function tandasEnEspera(int $mes, int $anio): array
    {
        $config = json_decode((string) ConfiguracionBrynex::obtener(self::CONFIG_TANDAS, ''), true);
        $todas = array_merge(
            array_values(array_filter(is_array($config) ? $config : [],
                fn ($t) => is_array($t) && ! empty($t['aliado']) && ! empty($t['rs']))),
            $this->tandasDesdePlanos($mes, $anio)
        );

        $vistas = [];
        $tandas = [];
        $this->sinPagar = [];

        foreach ($todas as $t) {
            $mods = array_map('intval', (array) ($t['mods'] ?? []));
            sort($mods);

            $t = [
                'etiqueta' => (string) ($t['etiqueta'] ?? ('rs '.$t['rs'])),
                'aliado' => (int) $t['aliado'],
                'rs' => (int) $t['rs'],
                'operador' => (int) ($t['operador'] ?? self::OPERADOR_SIMPLE),
                'mes' => (int) ($t['mes'] ?? $mes),
                'anio' => (int) ($t['anio'] ?? $anio),
                'n_plano' => (int) ($t['n_plano'] ?? 1),
                'mods' => $mods,
            ];

            $llave = implode('|', [$t['aliado'], $t['rs'], $t['mes'], $t['anio'], $t['n_plano'], implode(',', $mods)]);

            if (isset($vistas[$llave])) {
                continue;
            }
            $vistas[$llave] = true;

            if ($previa = $this->planillaYaLiquidada($t)) {
                $this->line(now()->format('d-m H:i:s')."  >> {$t['etiqueta']} ya tiene planilla "
                    .($previa->numero_planilla ?: '(sin número)').', no se vuelve a liquidar');

                // El plano se queda sin número hasta que se confirma el pago:
                // si sigue aquí, la planilla está liquidada y sin pagar.
                if ($previa->numero_planilla) {
                    $this->sinPagar[] = [$t, $previa];
                }

                continue;
            }

            $tandas[] = $t;
        }

        return $tandas;
    }

    /**
     * Una tanda por cada (aliado, razón social, plano, modalidad X1) con planos
     * de planilla o retiro que siguen sin número de planilla en el período de
     * pago. Es el mismo criterio con el que cedulasAVigilar() elige a quién
     * sondear, así que lo que se vigila es lo que se liquida.
     *
     * Cada modalidad va en su propia tanda: X1 y X1 TP14 son archivos aparte
     * (ver CONFIG_TANDAS).
     *
     * @return array<int, array<string, mixed>>
     */
    private function tandasDesdePlanos(int $mes, int $anio): array
    {
        $aliados = array_values(array_filter(array_map('intval', explode(',',
            (string) ConfiguracionBrynex::obtener(self::CONFIG_ALIADOS, self::ALIADOS_POR_DEFECTO)
        ))));

        if (! $aliados) {
            return [];
        }

        $conSimple = OperadorCredencial::where('operador_planilla_id', self::OPERADOR_SIMPLE)
            ->whereIn('aliado_id', $aliados)
            ->pluck('aliado_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if (! $conSimple) {
            return [];
        }

        $nombres = [TipoModalidad::ID_EX1 => 'X1', TipoModalidad::ID_EX1_TP => 'X1 TP14'];

        return DB::table('planos AS p')
            ->join('razones_sociales AS rs', function ($j) {
                $j->on('rs.id', '=', 'p.razon_social_id')->on('rs.aliado_id', '=', 'p.aliado_id');
            })
            ->whereIn('p.aliado_id', $conSimple)
            ->whereIn('p.tipo_modalidad_id', TipoModalidad::IDS_EX1)
            ->whereIn('p.tipo_reg', ['planilla', 'retiro'])
            ->whereRaw('ISNULL(p.num_dias, 0) > 0')
            ->whereNull('p.deleted_at')
            ->whereNull('p.numero_planilla')
            ->tap(fn ($q) => Plano::filtrarPeriodoDePago($q, $mes, $anio))
            ->groupBy('p.aliado_id', 'p.razon_social_id', 'p.n_plano', 'p.tipo_modalidad_id', 'rs.razon_social')
            ->orderBy('p.aliado_id')->orderBy('p.razon_social_id')->orderBy('p.n_plano')
            ->selectRaw('p.aliado_id, p.razon_social_id, p.n_plano, p.tipo_modalidad_id, rs.razon_social, COUNT(*) AS personas')
            ->get()
            ->map(fn ($g) => [
                'etiqueta' => sprintf('%s · plano %d · %s (%d %s)',
                    mb_strimwidth(trim((string) $g->razon_social), 0, 40, '…'),
                    (int) $g->n_plano,
                    $nombres[(int) $g->tipo_modalidad_id] ?? $g->tipo_modalidad_id,
                    (int) $g->personas,
                    (int) $g->personas === 1 ? 'persona' : 'personas'),
                'aliado' => (int) $g->aliado_id,
                'rs' => (int) $g->razon_social_id,
                'mes' => $mes,
                'anio' => $anio,
                'n_plano' => (int) $g->n_plano,
                'mods' => [(int) $g->tipo_modalidad_id],
            ])
            ->all();
    }

    /**
     * El número de planilla si la tanda ya está liquidada en el operador. Es la
     * misma búsqueda del 409 de PlanillaApiController::liquidar —paso 1, estado
     * validada, mismo filtro o uno viejo sin filtro—, hecha antes para no
     * gastar una llamada en el hueco. Sin filtro de modalidades no se
     * adelanta nada: ese caso lo resuelve el controlador.
     *
     * @param  array<string, mixed>  $t
     */
    private function planillaYaLiquidada(array $t): ?OperadorPlanillaApi
    {
        if (! $t['mods']) {
            return null;
        }

        $filtro = implode(',', $t['mods']);

        $previo = OperadorPlanillaApi::where('aliado_id', $t['aliado'])
            ->where('razon_social_id', $t['rs'])
            ->where('operador_planilla_id', $t['operador'])
            ->where('anio', $t['anio'])
            ->where('mes', $t['mes'])
            ->where('n_plano', $t['n_plano'])
            ->where('paso', 1)
            ->where('estado', 'validada')
            ->where(fn ($q) => $q->whereNull('tipos_modalidad')->orWhere('tipos_modalidad', $filtro))
            ->latest('id')
            ->first();

        return $previo;
    }

    /**
     * La página del plano en BryNex, donde está el botón «Pagar con PSE».
     *
     * El aviso lleva a la página y no al enlace del operador: el enlace que
     * Simple entrega al liquidar caduca, y el botón pide uno nuevo desde el
     * servidor en el momento de pagar (PlanillaApiController::abrirPago).
     * `aliado` hace que BryNex cambie al aliado de la tanda al abrirlo. El
     * filtro de modalidades es obligatorio: sin él la página busca la planilla
     * de la tanda "Todos" y dice que no hay ninguna con ese filtro. `pagar=1`
     * abre solo el bloque de la liquidación, donde está el botón.
     *
     * @param  array<string, mixed>  $t
     */
    private function enlaceDelPlano(array $t): string
    {
        return route('admin.planos.index', array_filter([
            'aliado' => $t['aliado'],
            'anio' => $t['anio'],
            'mes' => $t['mes'],
            'razon_social_id' => $t['rs'],
            'n_plano' => $t['n_plano'],
            'estado_pago' => 'todas',
            'tipos_modalidad' => $t['mods'] ?: null,
            'pagar' => 1,
        ], fn ($v) => $v !== null));
    }

    /**
     * Una tanda en el aviso de WhatsApp.
     *
     * @param  array<string, mixed>  $r
     */
    private function lineaDelAviso(array $r): string
    {
        if ($r['estado'] === 'enlace') {
            return "💳 {$r['etiqueta']}\n"
                ."Planilla {$r['planilla']}"
                .($r['valor'] ? ' · $'.number_format((float) $r['valor'], 0, ',', '.') : '')
                ." — liquidada antes y sin pagar. Simple volvió a dar el enlace: págala YA, "
                ."fuera del hueco no deja pagarla.\n"
                ."Pagar con PSE: {$r['enlace']}";
        }

        if ($r['estado'] !== 'liquidada') {
            return "• {$r['etiqueta']}: {$r['texto']}";
        }

        return "✅ {$r['etiqueta']}\n"
            ."Planilla {$r['planilla']}"
            .($r['valor'] ? ' · $'.number_format((float) $r['valor'], 0, ',', '.') : '')."\n"
            ."Págala YA: cuando el hueco se cierra, Simple no deja pagarla.\n"
            ."Pagar con PSE: {$r['enlace']}";
    }

    /**
     * Una tanda: la liquida con reintentos y devuelve cómo le fue.
     *
     * @param  array<string, mixed>  $t
     * @return array{estado: string, texto: string, planilla: ?string, valor: ?float}
     */
    private function liquidarTanda(array $t, string $etiqueta): array
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

        $estado = 'transitorio';
        $texto = '(sin intento)';
        $planilla = null;
        $valor = null;

        for ($i = 1; $i <= $intentos; $i++) {
            [$estado, $texto, $planilla, $valor] = $this->intentarLiquidar($t, $etiqueta) + [2 => null, 3 => null];

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

        return ['estado' => $estado, 'texto' => $texto, 'planilla' => $planilla, 'valor' => $valor];
    }

    /**
     * Un intento de liquidar una tanda. Devuelve [estado, texto] y, si liquidó,
     * también [planilla, valor a pagar].
     *
     * estado: 'liquidada' | 'ya' (409) | 'rechazada' (regla, hueco cerrado) |
     *         'transitorio' (el backend se cayó; vale la pena reintentar).
     *
     * @param  array<string, mixed>  $t
     * @return array{0: string, 1: string, 2?: string, 3?: ?float}
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

                return ['liquidada', "planilla {$planilla}", (string) $planilla,
                    isset($data['valor_total']) ? (float) $data['valor_total'] : null];
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
        $this->aportantesAbiertos = [];

        $rsList = $this->rsAVigilar();

        if (! $rsList) {
            $this->warn('Sin razones sociales de referencia: configure '.self::CONFIG_RS_REF.'.');

            return [];
        }

        $limpias = [];

        // Un sondeo por aportante, bajo su propio NIT. Cada uno deja su propia
        // línea `>> tel rs=NN`, así el registro y el monitor distinguen si el
        // hueco abrió en una ficha y no en las otras (config del aportante) o
        // en todas a la vez (hueco global de la UGPP).
        foreach ($rsList as $rsRef) {
            $resultado = $this->sondearRs($rsRef, $cedulas, $mes, $anio);
            $sello = now()->format('d-m H:i:s');

            // Solo cuenta el 2.655: es el que marca si la validación de la UGPP
            // está corriendo. El 2.379 es otra cosa (ADRES-salud) y no se vigila.
            $n655 = 0;

            foreach ($resultado as $cedula => $errores) {
                $this->line(sprintf('%s  CC %s rs=%d → %s', $sello, $cedula, $rsRef,
                    $errores ? implode(' ', $errores) : 'LIMPIO'));

                if (in_array(self::REGLA_UGPP, $errores, true)) {
                    $n655++;
                } else {
                    $limpias[] = $cedula;
                }
            }

            // Telemetría. La idea es separar las dos caídas que se confundían:
            //
            //   portal=OK  + 2.655×0  → cayó SOLO la validación de la UGPP: hueco
            //                            útil, el flujo responde y se puede liquidar.
            //   portal=OK  + 2.655×N  → todo normal, la UGPP sigue rechazando.
            //   portal=CAIDO          → se cayó el portal entero (auth/autorizar/
            //                            validar no responden): NO es hueco, no hay
            //                            nada que liquidar aunque el 2.655 "falte".
            //
            // Con el sondeo cada minuto, `grep '>> tel'` da la serie; la latencia
            // acompaña para ver si el hueco responde más rápido (UGPP saltada).
            if ($this->latenciaSondeoMs !== null || ! $this->portalRespondio) {
                if ($this->portalRespondio) {
                    $esHueco = $n655 === 0 && $resultado;
                    if ($esHueco) {
                        $this->aportantesAbiertos[] = $rsRef;
                    }
                    $this->line(sprintf('%s  >> tel rs=%d  lat=%sms · portal=OK · 2.655×%d/%d%s',
                        $sello, $rsRef, $this->latenciaSondeoMs ?? '?', $n655, count($resultado),
                        $esHueco ? '  ← HUECO (validación UGPP caída)' : ''));
                } elseif ($this->latenciaSondeoMs !== null) {
                    // Se llamó al operador y no contestó: portal caído, con el motivo.
                    $this->line(sprintf('%s  >> tel rs=%d  lat=%sms · portal=CAIDO · %s',
                        $sello, $rsRef, $this->latenciaSondeoMs, $this->portalMotivo));
                }
                // Si latencia es null y el portal no respondió, fue un corte de
                // configuración antes de llamar al operador: no se registra tel.
            }
        }

        return array_values(array_unique($limpias));
    }

    /**
     * Las razones sociales a sondear, cada una bajo su propio NIT.
     *
     * @return int[]
     */
    private function rsAVigilar(): array
    {
        $crudo = (string) ($this->option('rs-ref')
            ?: ConfiguracionBrynex::obtener(self::CONFIG_RS_REF, ''));

        return array_values(array_unique(array_filter(array_map(
            fn ($x) => (int) trim($x), explode(',', $crudo)
        ))));
    }

    /**
     * Nombres de razones sociales para el aviso: "rs 37 (ELITES CREACIONES SAS)".
     *
     * @param  int[]  $ids
     */
    private function nombresRs(array $ids): string
    {
        if (! $ids) {
            return '';
        }

        $nombres = DB::table('razones_sociales')->whereIn('id', $ids)
            ->pluck('razon_social', 'id');

        return collect($ids)->map(fn ($id) => "rs {$id}"
            .(($nombres[$id] ?? null) ? " ({$nombres[$id]})" : ''))->implode(', ');
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

        // Solo de los aliados que el vigía atiende: la gente de otro aliado no
        // tiene ficha en el de la razón social de referencia y cada sondeo la
        // reportaba como «sin ficha» (diez cédulas de INTEGRA, cuatro veces
        // por minuto con la cadencia en segundos).
        $aliados = array_values(array_filter(array_map('intval', explode(',',
            (string) ConfiguracionBrynex::obtener(self::CONFIG_ALIADOS, self::ALIADOS_POR_DEFECTO)
        ))));

        $pendientes = DB::table('planos AS p')
            ->whereIn('p.aliado_id', $aliados ?: [0])
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
    private function sondearRs(int $rsRef, array $cedulas, int $mes, int $anio): array
    {
        // Estado de este sondeo: hasta que el operador conteste, se asume que
        // no respondió y que ni siquiera se llegó a llamarlo (latencia null, un
        // corte de configuración que no cuenta como caída del portal).
        $this->latenciaSondeoMs = null;
        $this->portalRespondio = false;
        $this->portalMotivo = '(no se llegó a sondear)';

        $rs = DB::table('razones_sociales')->where('id', $rsRef)->first();

        if (! $rs) {
            $this->warn("La razón social de referencia {$rsRef} no existe.");

            return [];
        }

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

        // Lo mismo con una credencial de una sola razón social (el usuario del
        // vigía es solo de HOMENURSE): con otro NIT no tiene permisos.
        if ($credId && $cred->razon_social_id && (int) $cred->razon_social_id !== $rsRef) {
            $this->warn("La credencial {$credId} es solo de la razón social {$cred->razon_social_id}: "
                ."no sirve para sondear la {$rsRef}. Deje en vigia_subtipo4_rs_ref solo la {$cred->razon_social_id}.");

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
        //
        // Se cronometra cada envío y se guarda el del intento que respondió: es
        // la latencia del validador (la autenticación va cacheada entre sondeos,
        // así que el grueso del tiempo es la validación misma). La hipótesis es
        // que en el hueco baja, porque Simple se salta la consulta a la UGPP.
        $r = null;
        for ($i = 0; $i < 2; $i++) {
            $t0 = microtime(true);
            $r = $api->liquidarPlanilla(
                $nit,
                implode("\r\n", array_merge([$cabecera], array_values($lineas))),
                $base['filename'],
                ['tipoArchivo' => 'I']
            );
            $this->latenciaSondeoMs = (int) round((microtime(true) - $t0) * 1000);

            if ($r['success'] ?? false) {
                break;
            }

            $api->autenticar(true);
            usleep(1500000);
        }

        if (! ($r['success'] ?? false)) {
            // El portal entero no respondió (autenticar/autorizar/validar). NO
            // es un hueco: es la caída que hay que distinguir de la de la UGPP.
            $this->portalRespondio = false;
            $this->portalMotivo = ($r['paso'] ?? '?').' · '.substr($r['message'] ?? '(sin mensaje)', 0, 90);
            $this->warn('El operador no respondió — '.$this->portalMotivo);

            return [];
        }

        // El portal contestó el flujo completo (autenticó, autorizó y validó):
        // si además falta el 2.655, cayó solo la validación de la UGPP = hueco.
        $this->portalRespondio = true;
        $this->portalMotivo = '';

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
