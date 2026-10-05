<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * NINGUNA tarea lleva ->user('www-data'), y es a propósito: el cron que
     * dispara `schedule:run` es de **www-data** (`crontab -u www-data`), así
     * que todo corre ya con el usuario correcto. Poner ->user() aquí sería
     * peor que redundante — Laravel lo implementa envolviendo el comando en
     * `sudo -u www-data`, y www-data NO está en sudoers: el subproceso muere
     * con "www-data is not in the sudoers file" mientras `schedule:run`
     * reporta DONE. Tareas caídas en silencio.
     *
     * Por qué el cron es de www-data y no de root: `withoutOverlapping()`
     * guarda su mutex en la CACHÉ (`framework/schedule-<hash>`), y con
     * CACHE_DRIVER=file eso escribe en storage/framework/cache en cada
     * corrida, haga lo que haga el comando. Peor: ese mutex lo crea el
     * proceso de `schedule:run` (ver Event::run() → shouldSkipDueToOverlapping()),
     * ANTES de que ->user() tenga efecto en start(). Con el cron de root eso
     * sembraba directorios de dueño root en la caché, y cuando a Apache le
     * tocaba una llave que caía en uno de ellos, la petición reventaba con 500.
     *
     * Rompió la consulta de cédula del modal de clientes el 2026-08-04 (commit
     * 49c0454) y el LOGIN completo el 2026-08-08 — el throttle del login
     * shardea por IP, así que dejó fuera solo a las IPs con mala suerte.
     * `storage/` quedó además con ACL por defecto para www-data como red de
     * seguridad, pero la causa se corta aquí: el cron corre como www-data.
     *
     * TODOS los withoutOverlapping() llevan minutos explícitos, nunca el
     * default. El default son 1440 minutos: si el proceso muere sin llegar a
     * `schedule:finish`, el mutex queda tomado y la tarea no vuelve a correr
     * **en 24 horas**, sin un solo error en ningún log. Eso ya pasó — el
     * 2026-08-07 siete tareas quedaron congeladas 17 horas por el problema de
     * permisos de la caché, y solo se notó al revisar los mutexes a mano
     * (`clientes:completar-ruaf` fue la única que siguió viva, justamente
     * porque era la única con expiración explícita).
     *
     * El criterio es por frecuencia, con holgura amplia sobre lo que tarda la
     * tarea de verdad: **15** para las que corren cada 1-5 min, **30** para las
     * de 15-60 min, **60** para las diarias y mensuales. El número solo tiene
     * que ser mayor que la duración real (si no, se solapa de verdad) y lo
     * bastante chico para que un atasco se cure solo.
     *
     * Para ver si hay mutexes atascados, recorrer los eventos y mirar
     * `$e->mutex->exists($e)`; se limpian con `schedule:clear-cache`.
     *
     * Al agregar una tarea nueva: NO le pongas ->user(), y SÍ dale minutos
     * explícitos a withoutOverlapping().
     */
    protected function schedule(Schedule $schedule): void
    {
        // ── Retención de accesos y bitácora ───────────────────────────
        // El día 2 de cada mes a las 02:30: borra accesos con más de 2 años y
        // bitácora con más de 5, que son los plazos que el aviso de tratamiento
        // de datos le promete al usuario. Si se cambian los plazos aquí, hay
        // que cambiarlos también en docs/clausulas-y-aviso-datos.md.
        // Ejecución manual: php artisan retencion:limpiar (sin --ejecutar simula)
        $schedule->command('retencion:limpiar --ejecutar')
            ->monthlyOn(2, '02:30')
            ->timezone('America/Bogota')
            ->withoutOverlapping(60)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/retencion.log'));

        // ── Retención de las entregas de datos a aliados ──────────────
        // Cada día a las 03:00 borra los ZIP que pasaron de la ventana de
        // config/exportacion (7 días). Son datos personales de miles de
        // personas: prometer que se borran y dejarlos ahí es peor que no
        // prometer nada. La pantalla también purga al entrar, pero eso depende
        // de que alguien entre.
        $schedule->call(fn () => app(\App\Services\Exportacion\ExportAliadoService::class)->purgarVencidas())
            ->dailyAt('03:00')
            ->timezone('America/Bogota')
            ->name('exportaciones-purgar')
            ->withoutOverlapping(30);

        // ── Facturación electrónica: cierre del día ───────────────────
        // Corre cada hora y el comando se queda solo con las configuraciones
        // cuya `hora_cierre` cae en esta hora. Así cada razón social cierra a
        // la hora que quiera sin volver a tocar este archivo, y las que están
        // en modo 'factura' (emiten al llegar la consignación) se saltan solas.
        // Reintenta también lo que quedó en `error` en corridas anteriores.
        // Ejecución manual: php artisan dataico:emitir --aliado=2 --simular
        $schedule->command('dataico:emitir')
            ->hourly()
            ->timezone('America/Bogota')
            ->name('dataico-emitir')
            ->withoutOverlapping(30)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/dataico.log'));

        // ── Confirmación de afiliaciones en EPS SURA ──────────────────
        // Cada noche a las 21:00 baja el informe de afiliados de cada empresa y
        // pasa a OK confirmado (verde fuerte) los radicados de EPS de quienes ya
        // son cotizantes con derecho. No vuelve a mirar confirmados ni retirados.
        // Ejecución manual: php artisan eps:confirmar-sura --aliado=2 --simular
        $schedule->command('eps:confirmar-sura')
            ->dailyAt('21:00')
            ->timezone('America/Bogota')
            ->name('eps-confirmar-sura')
            ->withoutOverlapping(180)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/eps-confirmacion.log'));

        // El mismo cruce, pero de ARL Colmena contra su informe de vigentes.
        // Media hora después del de EPS SURA: los dos levantan un navegador y
        // el servidor no tiene por qué cargar con los dos a la vez.
        // Ejecución manual: php artisan arl:confirmar-colmena --nit=901709476 --simular
        $schedule->command('arl:confirmar-colmena')
            ->dailyAt('21:30')
            ->timezone('America/Bogota')
            ->name('arl-confirmar-colmena')
            ->withoutOverlapping(180)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/arl-confirmacion.log'));

        // Los subsidios que Comfandi dejó bloqueados, convertidos en tareas. A las
        // 22:00 porque el portal avisa que está fuera de servicio de 5 a 8 PM y
        // porque a esa hora ya terminaron los dos cruces de arriba: cada uno
        // levanta su propio Chrome y no conviene solaparlos.
        // Si alguien ya la corrió desde Afiliaciones, esta no repite.
        // Ejecución manual: php artisan caja:revisar-subsidios --aliado=2 --simular
        $schedule->command('caja:revisar-subsidios')
            ->dailyAt('22:00')
            ->timezone('America/Bogota')
            ->name('caja-revisar-subsidios')
            ->withoutOverlapping(180)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/caja-subsidios.log'));

        // La misma revisión, en Comfenalco Valle. Media hora después de la de
        // Comfandi: cada una levanta su propio Chrome. Aquí la consulta es por
        // empresa —una pantalla cubre la nómina entera—, así que dura poco.
        // Sale por PROXY_COLOMBIA: el portal no atiende a la IP del servidor.
        $schedule->command('caja:revisar-subsidios --caja=COMFENALCO')
            ->dailyAt('22:40')
            ->timezone('America/Bogota')
            ->name('caja-revisar-subsidios-comfenalco')
            ->withoutOverlapping(120)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/caja-subsidios.log'));

        // Y el cruce con el listado de afiliados de Comfenalco, que es lo que
        // pasa los radicados de caja a OK confirmado. Hasta ahora solo ocurría
        // cuando alguien abría la pantalla. Después de los subsidios, para no
        // levantar dos Chrome a la vez.
        $schedule->command('caja:conciliar --caja=COMFENALCO')
            ->dailyAt('23:10')
            ->timezone('America/Bogota')
            ->name('caja-conciliar-comfenalco')
            ->withoutOverlapping(120)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/caja-conciliacion.log'));

        // Los aportes mal cobrados en los portales de las EPS —Nueva EPS y Salud
        // Total—, convertidos en tareas. Semanal y no diaria: los portales
        // generan sus reportes aparte, minutos por empresa, y esto solo cambia
        // cuando alguien paga o reclama. Los lunes, para que la semana empiece
        // sabiendo qué hacer.
        // Ninguna de las dos mira el mes en curso: un aporte de este mes todavía
        // está a tiempo de pagarse y no es mora.
        // Ejecución manual: php artisan eps:revisar-mora --eps=SALUD_TOTAL --simular
        // A las 19:00 y no más tarde: con tres EPS son 19 entradas a portal y
        // cerca de una hora, y a las 22:00 empiezan las cajas. Dos Chrome a la
        // vez en el mismo servidor terminan estorbándose.
        $schedule->command('eps:revisar-mora')
            ->weeklyOn(1, '19:00')
            ->timezone('America/Bogota')
            ->name('eps-revisar-mora')
            ->withoutOverlapping(240)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/eps-mora.log'));

        // Y el cruce de retiros con la EPS, que es la misma vigilancia vista
        // antes: un retiro que no le llegó a la EPS se cobra en silencio mes a
        // mes. Después de la mora, para no levantar dos Chrome a la vez.
        // Ejecución manual: php artisan eps:conciliar-retiros --nit=901904750 --simular
        $schedule->command('eps:conciliar-retiros')
            ->weeklyOn(1, '20:15')
            ->timezone('America/Bogota')
            ->name('eps-conciliar-retiros')
            ->withoutOverlapping(120)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/eps-mora.log'));

        // Cómo salió la agenda de anoche. Los treinta comandos escriben su propio
        // log y nadie los abre: sin esto, uno que empiece a fallar de madrugada
        // puede pasar semanas así. Solo avisa cuando hay algo que contar.
        $schedule->command('corridas:resumen --horas=12')
            ->dailyAt('07:30')
            ->timezone('America/Bogota')
            ->name('corridas-resumen')
            ->withoutOverlapping(30)
            ->appendOutputTo(storage_path('logs/corridas-resumen.log'));

        // Prospectos cotizados: pasa a «sin respuesta», cierra los abandonados y
        // avisa las llamadas del día, según lo que cada aliado configure en
        // Parámetros. Sin configuración no hace nada.
        $schedule->command('cotizaciones:seguimiento')
            ->dailyAt('07:30')
            ->timezone('America/Bogota')
            ->name('cotizaciones-seguimiento')
            ->withoutOverlapping(30)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/cotizaciones-seguimiento.log'));

        // S.O.S. no se puede revisar de noche: su login pide reCAPTCHA y hace
        // falta una persona. En vez de dejar los radicados sin confirmar, el
        // lunes por la mañana se pide por WhatsApp que alguien entre, y la
        // revisión (Afiliaciones → Conciliar → S.O.S.) cierra el pedido.
        // Avisa una sola vez por pedido: insistir es como se deja de leer.
        $schedule->command('portales:pedir-revision --entidad=sos')
            ->weeklyOn(1, '08:00')
            ->timezone('America/Bogota')
            ->name('portales-pedir-revision-sos')
            ->withoutOverlapping(30)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/portales-peticiones.log'));

        // ── Cruces con los portales: quién está afiliado de verdad ──────────
        // Estaban escritos pero dormidos: solo corrían cuando alguien abría la
        // pantalla. De madrugada no estorban a nadie —ni a la mora de los lunes
        // ni a las cajas de las 22:00— y cada uno levanta su propio Chrome.
        //
        // Van con el aliado 2 (Brygar), que es el único con el módulo
        // `automatizacion_portales` activo: entrar al portal de un aliado que no
        // lo autorizó no es cosa de la agenda. Ver [[automatizacion-portales-autorizacion]].
        $cruces = [
            // Comfandi va primero: su portal está más suelto de madrugada y es
            // el que más afiliados tiene que confirmar.
            ['caja:conciliar --caja=COMFANDI', '00:30', 'caja-conciliar-comfandi'],
            ['eps:conciliar-nueva-eps', '01:30', 'eps-conciliar-nueva-eps'],
            ['eps:conciliar-salud-total', '02:30', 'eps-conciliar-salud-total'],
            ['eps:conciliar-sura', '03:30', 'eps-conciliar-sura'],
            ['pension:conciliar', '04:30', 'pension-conciliar'],
            // ARL va de última: es la que más gente mueve y la que más tarda.
            ['arl:conciliar', '05:30', 'arl-conciliar'],
            // Coosalud no publica mora: lo que deja ver es a quién tiene
            // afiliado, y de ahí salen los retiros sin reportar y los radicados
            // que alguien hizo y nadie marcó. Emssanar usa el mismo portal y
            // entra aquí con `--eps=emssanar` en cuanto se vea su listado.
            ['boxalud:conciliar --eps=coosalud', '06:30', 'boxalud-conciliar-coosalud'],
        ];

        foreach ($cruces as [$comando, $hora, $nombre]) {
            $schedule->command($comando)
                ->dailyAt($hora)
                ->timezone('America/Bogota')
                ->name($nombre)
                ->withoutOverlapping(180)
                ->runInBackground()
                ->appendOutputTo(storage_path('logs/cruces-portales.log'));
        }

        // Agente del buzón de afiliaciones (seguridadsocial.brygar@gmail.com): cada
        // 30 min lee lo que llega de las entidades, aplica los radicados que
        // envían los asesores y avisa por WhatsApp. Solo lectura en Gmail.
        // Con --empresas lee además los correos de formularios de las empresas que
        // tengan contraseña de aplicación (ahí responde Sanitas).
        // Ejecución manual: php artisan correos:revisar-buzon --aliado=2 --empresas --simular
        $schedule->command('correos:revisar-buzon --aliado=2 --empresas --dias=2')
            ->everyThirtyMinutes()
            ->timezone('America/Bogota')
            ->name('correos-revisar-buzon')
            ->withoutOverlapping(25)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/correos-buzon.log'));

        // Resumen de afiliaciones por correo a los WhatsApp del aliado, L-V 5:30 p. m.
        $schedule->command('correos:resumen-afiliaciones --aliado=2')
            ->weekdays()
            ->dailyAt('17:30')
            ->timezone('America/Bogota')
            ->name('correos-resumen-afiliaciones')
            ->withoutOverlapping(10)
            ->appendOutputTo(storage_path('logs/correos-buzon.log'));

        // ── Reset mensual de n_plano ──────────────────────────────────
        // El día 1 de cada mes a las 00:01 (hora Colombia) resetea n_plano=1
        // y avanza mes_pagos/anio_pagos en todas las razones sociales.
        // Ejecución manual: php artisan planos:reset-mensual
        $schedule->command('planos:reset-mensual')
            ->monthlyOn(1, '00:01')
            ->timezone('America/Bogota')
            ->withoutOverlapping(60)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/reset-n-plano.log'));

        // ── Liquidación automática de intereses de préstamos (Finanzas) ──
        // Diario a la 1:00 AM Colombia: liquida los meses calendario completos
        // vencidos de cada préstamo activo/mora con tasa > 0.
        // Ejecución manual: php artisan finanzas:liquidar-intereses
        $schedule->command('finanzas:liquidar-intereses')
            ->dailyAt('01:00')
            ->timezone('America/Bogota')
            ->withoutOverlapping(60)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/finanzas-liquidacion.log'));

        // ── Recordatorios de préstamo por WhatsApp (Finanzas) ────────────
        // 9:00 AM Colombia: avisa 3 días antes del corte y cobra 3 días después,
        // una vez por ciclo. Va después de la liquidación de la 1:00 para que el
        // interés del corte ya esté causado cuando se decide qué mensaje mandar.
        // Ejecución manual: php artisan finanzas:recordar-prestamos [--dry-run]
        $schedule->command('finanzas:recordar-prestamos')
            ->dailyAt('09:00')
            ->timezone('America/Bogota')
            ->withoutOverlapping(30)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/finanzas-recordatorios.log'));

        // ── Vencimientos tributarios de las razones sociales ─────────────
        // 7:30 AM Colombia, de lunes a viernes: un solo mensaje por contador
        // con lo vencido y lo que vence en la semana. Entre semana porque los
        // vencimientos de la DIAN caen en día hábil y avisar en domingo no
        // sirve de nada.
        // Ejecución manual: php artisan brynex:alertar-vencimientos [--seco]
        $schedule->command('brynex:alertar-vencimientos --dias=7')
            ->weekdays()
            ->at('07:30')
            ->timezone('America/Bogota')
            ->withoutOverlapping(30)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/brynex-vencimientos.log'));

        // ── Seguimiento comercial del Asistente IA (WhatsApp) ────────────
        // Cada 15 min, en horario comercial: revisa conversaciones que la IA
        // dejó sin respuesta del cliente hace 3h+ y, SOLO si quedó una afiliación
        // pendiente (lo evalúa SeguimientoEvaluador), envía un único mensaje
        // de seguimiento (no repite hasta que el cliente vuelva a escribir).
        // Ejecución manual: php artisan whatsapp:seguimiento-ia
        $schedule->command('whatsapp:seguimiento-ia')
            ->everyFifteenMinutes()
            ->between('07:00', '21:00')
            ->timezone('America/Bogota')
            ->withoutOverlapping(30)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/whatsapp-seguimiento-ia.log'));

        // ── Despacho de publicidad programada (Fase 4 página web pública) ────
        // Cada 5 min: publica las piezas aprobadas cuya fecha programada ya llegó.
        // Ejecución manual: php artisan publicaciones:despachar
        $schedule->command('publicaciones:despachar')
            ->everyFiveMinutes()
            ->timezone('America/Bogota')
            ->withoutOverlapping(15)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/publicaciones-despacho.log'));

        // ── Procesamiento de video IA (Veo + overlay FFmpeg) ─────────────────
        // Cada minuto: consulta el estado de los videos en generación en Veo (1-3 min
        // típico), y cuando terminan les monta el overlay de texto+logo y los marca listos.
        // Ejecución manual: php artisan videos:procesar
        $schedule->command('videos:procesar')
            ->everyMinute()
            ->timezone('America/Bogota')
            ->withoutOverlapping(15)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/videos-procesar.log'));

        // ── Métricas de redes de las piezas publicadas ───────────────────────
        // Diario a las 21:30: lee likes/comentarios/compartidos/alcance de cada
        // pieza de los últimos 30 días — alimenta el aprendizaje del piloto.
        // Ejecución manual: php artisan marketing:metricas
        $schedule->command('marketing:metricas')
            ->dailyAt('21:30')
            ->timezone('America/Bogota')
            ->withoutOverlapping(60)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/marketing-metricas.log'));

        // ── Sincronización de pauta pagada (gasto real + tope de seguridad) ──
        // Cada hora: lee el gasto real de Meta y pausa automáticamente cualquier
        // pauta que se pase de su presupuesto o del tope mensual del aliado.
        // Solo APAGA gasto, nunca lo prende ni lo sube — eso es siempre manual.
        // Ejecución manual: php artisan marketing:pauta-sync
        $schedule->command('marketing:pauta-sync')
            ->hourly()
            ->timezone('America/Bogota')
            ->withoutOverlapping(30)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/marketing-pauta-sync.log'));

        // ── Conversaciones asignadas que nadie contestó ──────────────────────
        // Cada 15 min: si el cliente lleva 4 h esperando y el asesor asignado no respondió,
        // la conversación vuelve al inbox general marcada como pendiente, para que la tome
        // cualquiera. Antes solo la veía el asesor asignado, estuviera o no.
        // Ejecución manual: php artisan whatsapp:liberar-sin-atender --simular
        $schedule->command('whatsapp:liberar-sin-atender')
            ->everyFifteenMinutes()
            ->timezone('America/Bogota')
            ->withoutOverlapping(10)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/whatsapp-liberar.log'));

        // ── Estado de las plantillas del sistema en Meta ─────────────────────
        // Meta aprueba (o rechaza) las plantillas a su ritmo y nadie le avisa al sistema:
        // el botón «Reabrir conversación» no aparece hasta que el registro diga `approved`.
        // Cada hora se consulta; con --estado nunca crea nada.
        $schedule->command('whatsapp:plantillas-sistema --estado')
            ->hourly()
            ->between('07:00', '20:00')
            ->timezone('America/Bogota')
            ->withoutOverlapping(10)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/whatsapp-plantillas-sistema.log'));

        // ── Gente esperando respuesta en WhatsApp ────────────────────────────
        // Cuatro veces al día en jornada, lunes a sábado, para TODOS los aliados con WhatsApp:
        // la lista de quién escribió y lleva horas sin que una persona le conteste, con aviso
        // de a quién se le vence la ventana de 24 h. Ahí se perdían las ventas (sep-2026), no
        // en la pauta. La primera corrida del día manda todo; las demás solo si hay alguien
        // nuevo o alguien a punto de vencerse. Si no hay nadie esperando no manda nada.
        // Ejecución manual: php artisan whatsapp:sin-respuesta --no-enviar
        $schedule->command('whatsapp:sin-respuesta')
            ->cron('0 8,11,14,17 * * 1-6')
            ->timezone('America/Bogota')
            ->withoutOverlapping(15)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/whatsapp-sin-respuesta.log'));

        // ── Informe diario de la pauta ───────────────────────────────────────
        // Diario a las 20:00, con el día ya corrido: qué se gastó y qué trajo.
        // Va a esa hora y no en la mañana porque las métricas de Meta llegan con
        // retraso; a las 8am el día apenas empieza y el informe no diría nada.
        // Ejecución manual: php artisan marketing:informe-pauta
        $schedule->command('marketing:informe-pauta')
            ->dailyAt('20:00')
            ->timezone('America/Bogota')
            ->withoutOverlapping(15)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/marketing-informe-pauta.log'));

        // ── Vencimiento del token de pauta ───────────────────────────────────
        // Diario a las 08:00: el token de anuncios dura ~60 días y al vencer deja
        // de crear anuncios EN SILENCIO — el piloto sigue publicando, solo se
        // congela el gasto. Avisa a 7, 3 y 1 día, y cuando ya venció.
        // Ejecución manual: php artisan pauta:token-vigilar
        $schedule->command('pauta:token-vigilar')
            ->dailyAt('08:00')
            ->timezone('America/Bogota')
            ->withoutOverlapping(15)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/pauta-token-vigilar.log'));

        // ── Creatividades del conjunto permanente de pauta ───────────────────
        // Diario a las 12:00: mete la pieza publicada más reciente al conjunto
        // permanente (si queda cupo semanal) y pausa las que ya no compiten.
        // No enciende gasto: si el conjunto está en pausa, la creatividad entra
        // en pausa. Ejecución manual: php artisan marketing:pauta-creatividades
        $schedule->command('marketing:pauta-creatividades')
            ->dailyAt('12:00')
            ->timezone('America/Bogota')
            ->withoutOverlapping(30)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/marketing-pauta-creatividades.log'));

        // ── Corte semanal de la pauta ────────────────────────────────────────
        // Lunes 8:30: mide cada creatividad de la semana por conversaciones que
        // pasaron del saludo, apaga las que no jalan gente o cuestan el triple
        // que la mejor, y avisa por WhatsApp. Solo apaga gasto; subirlo o
        // prenderlo sigue pidiendo el clic del usuario, y la mejor de cada
        // conjunto nunca se apaga. Antes de esto nadie miraba: #86 gastó $17.781
        // para una conversación y #73 $2.439 para ninguna, semanas enteras.
        // Ejecución manual: php artisan marketing:pauta-semanal --no-enviar --no-pausar
        $schedule->command('marketing:pauta-semanal')
            ->weeklyOn(1, '08:30')
            ->timezone('America/Bogota')
            ->withoutOverlapping(30)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/marketing-pauta-semanal.log'));

        // ── Piloto automático de marketing (community manager IA) ────────────
        // Cada 30 min en horario diurno: genera la pieza publicitaria del día de
        // cada aliado con piloto activo (una por día, desde la hora configurada).
        // Ejecución manual: php artisan marketing:autopilot [--aliado=slug --force]
        $schedule->command('marketing:autopilot')
            ->everyThirtyMinutes()
            ->between('05:00', '21:00')
            ->timezone('America/Bogota')
            ->withoutOverlapping(30)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/marketing-autopilot.log'));

        // ── Completar EPS/pensión/nombres desde el registro oficial ──────────
        // Una tanda por hora hasta terminar los ~31.000 clientes. Se reparte en
        // tandas a propósito: 31.000 consultas seguidas contra el operador se
        // verían como abuso y pueden costar el bloqueo de la cuenta. Con 1.000
        // por hora y 250 ms entre consultas son ~4 minutos de trabajo por hora,
        // y el barrido completo toma poco más de un día.
        //
        // El orden lo decide el comando: primero los clientes VIGENTES a los
        // que les falta información, de últimos los retirados que ya están
        // completos. Cuando no queden pendientes la corrida no hace nada, así
        // que la tarea puede quedarse programada sin efecto.
        //
        // Solo rellena huecos: nunca sobrescribe un dato que el cliente ya
        // tiene. Las diferencias van a `clientes:informe-ruaf`.
        // Ejecución manual: php artisan clientes:completar-ruaf --limite=100
        $schedule->command('clientes:completar-ruaf --limite=1000 --pausa=250 --aplicar')
            ->hourly()
            ->timezone('America/Bogota')
            ->withoutOverlapping(30)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/completar-ruaf.log'));

        // ── Vigilancia de descuadres en la facturación ───────────────────
        // TEMPORAL: corre hasta el 25-sep-2026 y después se apaga sola (la
        // condición de abajo), así que puede quedarse escrita sin hacer nada.
        // Se puso el 10-sep-2026, después de corregir 127 facturas que cobraban
        // algo distinto de lo que el cliente pagó, para ver si el arreglo aguanta
        // dos ciclos de facturación completos.
        //
        // ── Residuos del reparto en los saldos a favor ─────────────────────
        // El 1 de cada mes a las 6:00 AM, antes de que empiece la facturación:
        // da por consumidos los lotes cuyo saldo a favor entero es menor a
        // $1.000 (pesos sueltos del redondeo), para que no se apliquen solos en
        // la factura del mes ni ensucien la pantalla de saldos. No toca
        // facturas —deja un SaldoAjuste que se puede deshacer— y no repite lo
        // ya marcado.
        // Ejecución manual: php artisan saldos:marcar-ruido --dry-run
        $schedule->command('saldos:marcar-ruido --tope=1000 --force')
            ->monthlyOn(1, '06:00')
            ->timezone('America/Bogota')
            ->withoutOverlapping(60)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/saldos-residuos.log'));

        // 7:30 PM Colombia: pasada la jornada, con lo facturado del día ya
        // registrado. Solo avisa por WhatsApp cuando aparece un caso NUEVO —
        // lo ya revisado queda en la baseline y no vuelve a sonar.
        // Ejecución manual: php artisan facturas:detectar-descuadres --dias=45
        $schedule->command('facturas:detectar-descuadres --dias=45 --avisar')
            ->dailyAt('19:30')
            ->timezone('America/Bogota')
            ->when(fn () => now('America/Bogota')->lessThanOrEqualTo(
                \Carbon\Carbon::parse('2026-09-25 23:59', 'America/Bogota')
            ))
            ->withoutOverlapping(30)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/facturas-descuadres.log'));
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
