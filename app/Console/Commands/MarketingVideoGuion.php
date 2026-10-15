<?php

namespace App\Console\Commands;

use App\Models\Aliado;
use App\Models\AutopilotConfig;
use App\Models\IaConfiguracionAliado;
use App\Models\PublicidadVideoIa;
use App\Models\RedSocialConfig;
use App\Services\Publicidad\ClipDeCapturas;
use App\Services\Publicidad\VeoVideoGenerator;
use Illuminate\Console\Command;

/**
 * Video largo con GUION FIJO, escena por escena.
 *
 * El piloto diario deja que la IA invente la escena a partir de un tema, y para una pieza de
 * 8 segundos está bien. Para una de 30 no: un video largo necesita que las tres escenas
 * cuenten una historia en orden —problema, propuesta, respaldo— y eso no se puede pedir "en
 * general" y esperar que salga coherente tres veces seguidas.
 *
 * El guion de "mejorar cotización" existe porque es el ÚNICO ángulo con evidencia: la pieza
 * #60 trajo 16 conversaciones a $1.568 cada una, contra $11.404 de la peor. Le habla a quien
 * ya está decidido y comparando precio, no a quien está aprendiendo qué es una EPS.
 */
class MarketingVideoGuion extends Command
{
    protected $signature = 'marketing:video-guion
        {guion=mejorar-cotizacion : Nombre del guion a usar}
        {--aliado=brygar : Slug del aliado}
        {--listar : Solo muestra los guiones disponibles}
        {--calidad=lite : lite (USD 0,05/s) o standard (USD 0,40/s, imagen bastante mejor)}';

    protected $description = 'Genera un video largo siguiendo un guion escena por escena';

    /**
     * Cada escena es un prompt para Veo (en inglés: entiende mejor la dirección de escena) y
     * las frases van en pantalla, no habladas — así el mensaje se lee aunque lo vean sin
     * sonido, que es como se ve la mayoría de los Reels.
     */
    /**
     * Número al que se derivan los asesores.
     *
     * Va en el COPY, no en el video: el cierre de marca es el mismo para todas las piezas y
     * lleva el WhatsApp comercial. Meter otro número dentro del clip obligaría a mantener una
     * variante del cierre solo para estas dos.
     */
    private const WHATSAPP_ASESORES = '311 776 2689';

    private const GUIONES = [
        'mejorar-cotizacion' => [
            'tema' => 'te mejoramos cualquier cotización que ya tengas, con verificación de tus pagos y asesoría sin costo',
            'titulo' => 'Te mejoramos cualquier cotización — y verificamos que tus pagos estén al día',
            'escenas' => [
                // 1. El problema. Una duda concreta, no un eslogan: el espectador se reconoce
                //    en la incomodidad de no saber si le están cobrando de más.
                'Vertical 9:16 cinematic shot. A Colombian independent worker in his 30s sits on the steps '
                .'outside a workshop during a break, still in work clothes, looking at a quote on his phone '
                .'with a doubtful frown. He scratches his head, looks away thinking. Natural afternoon light, '
                .'handheld camera, shallow depth of field. Ambient street sounds. No text on screen, nobody speaks.',

                // 2. La propuesta. Se ve la acción concreta que queremos que copie: escribir por
                //    WhatsApp. Mostrarlo vende mejor que decirlo.
                'Vertical 9:16 cinematic shot. Close-up of the same Colombian worker typing a message on his '
                .'phone with both thumbs, then a warm smile as he sees a reply arrive. Over his shoulder we '
                .'see a bright office out of focus. Natural light, handheld, shallow depth of field. Ambient '
                .'sounds only. No text on screen, nobody speaks.',

                // 3. El respaldo. Sin pantallas: pedirle a Veo "una pantalla con confirmaciones"
                //    y a la vez "que no se lea texto" es contradictorio, y devolvió la escena
                //    vacía. El alivio se actúa, no se muestra en un monitor.
                'Vertical 9:16 cinematic shot. A Colombian woman advisor in her late 20s sits beside a client '
                .'at a desk in a bright modern office, both looking at a printed document she points to. '
                .'The client nods slowly and smiles with relief, then they shake hands. Warm natural light, '
                .'medium shot, shallow depth of field. Ambient office sounds. Nobody speaks.',
            ],
            'frases' => [
                '¿Estás pagando de más?',
                'Cuéntanos qué pagas hoy',
                'Verificamos que estés al día',
            ],
            // Las frases en pantalla tienen que ser cortas para leerse; la narración puede
            // explicar. En un video de 30 segundos, tres frases de cuatro palabras dejan el
            // audio casi vacío y la pieza no "explica" nada.
            'narracion' => 'Si ya tienes una cotización de seguridad social, cuéntanos cuánto estás pagando hoy '
                .'y te la mejoramos. Y algo que casi nadie hace: verificamos que tus aportes hayan quedado '
                .'bien reportados, para que sepas que tu plata sí llegó. La asesoría no te cuesta nada.',
            'copy' => '¿Ya tienes una cotización de seguridad social? Cuéntanos cuánto pagas hoy y te la mejoramos. '
                .'Además verificamos que tus aportes hayan quedado bien reportados — tú mismo lo compruebas. '
                .'Asesoría sin costo. 📲 Escríbenos.',
        ],
        // Misma estructura que el anterior, con dos cambios pedidos: un oficio más cotidiano
        // —un vendedor de tienda de barrio en vez de alguien de taller— y el diferencial de
        // "mejoramos cualquier cotización" dicho también en voz alta, no solo en pantalla.
        // Todas las escenas describen el audio como "Ambient sounds only": es la fórmula que
        // pasó el filtro de Veo, mientras que "ambient office sounds" lo hizo fallar dos veces.
        'mejorar-cotizacion-2' => [
            'tema' => 'te mejoramos cualquier cotización que ya tengas, con verificación de tus pagos y asesoría sin costo',
            'titulo' => 'Mejoramos cualquier cotización — y verificamos que tus pagos estén al día',
            'escenas' => [
                'Vertical 9:16 cinematic shot. A Colombian man in his 40s, a small neighborhood shop owner in '
                .'a simple polo shirt, stands behind the counter of his corner store during a quiet moment, '
                .'looking at a quote on his phone with a doubtful frown. Shelves with products behind him. '
                .'Natural daylight, handheld camera, shallow depth of field. Ambient sounds only. '
                .'No text on screen, nobody speaks.',

                'Vertical 9:16 cinematic shot. Close-up of the same Colombian shop owner typing a message on '
                .'his phone with both thumbs, then a warm smile as he sees a reply arrive. The store shelves '
                .'are out of focus behind him. Natural daylight, handheld, shallow depth of field. '
                .'Ambient sounds only. No text on screen, nobody speaks.',

                'Vertical 9:16 cinematic shot. A Colombian woman advisor in her late 20s sits beside a client '
                .'at a desk in a bright modern office, both looking at a printed document she points to. '
                .'The client nods slowly and smiles with relief. Warm natural light, medium shot, '
                .'shallow depth of field. Ambient sounds only. No text on screen, nobody speaks.',
            ],
            'frases' => [
                '¿Estás pagando de más?',
                'Cuéntanos qué pagas hoy',
                'Mejoramos cualquier cotización',
            ],
            'narracion' => 'Si ya tienes una cotización de seguridad social, cuéntanos cuánto estás pagando hoy: '
                .'te mejoramos cualquier cotización. Y algo que casi nadie hace: verificamos que tus aportes '
                .'hayan quedado bien reportados y que estés al día, para que sepas que tu plata sí llegó. '
                .'La asesoría no te cuesta nada.',
            'copy' => 'Mejoramos cualquier cotización de seguridad social. Cuéntanos cuánto pagas hoy y te decimos '
                .'cómo quedarías. Además verificamos que tus aportes estén al día — tú mismo lo compruebas. '
                .'Asesoría sin costo. 📲 Escríbenos.',
        ],
        // Reclutamiento de ASESORES: otro público y otra promesa. No le vendemos seguridad
        // social a nadie — le hablamos a quien YA la vende y tiene cartera propia, y le
        // ofrecemos quitarle el papeleo. Por eso ninguna escena muestra clientes ni planes.
        'asesores' => [
            'tema' => 'reclutamiento de asesores que ya venden seguridad social: mejores comisiones y nosotros hacemos el trabajo operativo',
            'titulo' => '¿Eres asesor y ya tienes clientes? Te mejoramos tus comisiones',
            'escenas' => [
                'Vertical 9:16 cinematic shot. A Colombian man in his 30s in a casual shirt sits at a small '
                .'desk at home surrounded by stacks of paper folders and forms, rubbing his eyes with '
                .'tiredness, overwhelmed by paperwork. Warm lamp light, handheld camera, shallow depth of '
                .'field. Ambient sounds only. No text on screen, nobody speaks.',

                // La plataforma REAL, no una inventada por Veo: a un asesor con experiencia una
                // pantalla falsa lo pierde. Ver ClipDeCapturas.
                'CAPTURAS',

                'Vertical 9:16 cinematic shot. The same Colombian man walks outdoors on a city street '
                .'looking down at his phone with a calm, satisfied expression, one hand in his pocket. '
                .'Late afternoon golden light, medium shot, shallow depth of field. Ambient street sounds '
                .'only. No text on screen, nobody speaks.',

                'Vertical 9:16 cinematic shot. The same Colombian man shakes hands with a client across a '
                .'table in a bright cafe, both smiling confidently, a phone on the table between them. '
                .'Natural daylight, medium shot, shallow depth of field. Ambient sounds only. '
                .'No text on screen, nobody speaks.',
            ],
            // Capturas tomadas con el navegador a 800px de ancho: la plataforma se acomoda
            // vertical sola y entran enteras, sin panear sobre una pantalla apaisada.
            'capturas' => [
                ['archivo' => '11-modulos.png', 'modo' => 'ancho', 'zoom' => 1.1, 'arriba' => 0.25],
                ['archivo' => '12-cobros.png', 'modo' => 'ancho', 'zoom' => 1.1, 'arriba' => 0.3],
                ['archivo' => '13-tendencia.png', 'modo' => 'ancho', 'zoom' => 1.1, 'arriba' => 0.5],
            ],
            'frases' => [
                '¿Ya tienes clientes afiliados?',
                'Más por cada afiliación',
                'Más por administración mensual',
                'Comunícate ya',
            ],
            // Dice QUÉ buscamos y CUÁNTO gana, no en qué le ayudamos. Es lo único que a un
            // asesor con cartera le hace parar el dedo: lo operativo —API, WhatsApp, la app—
            // se cuenta en el copy, donde ya está leyendo porque le interesó.
            'narracion' => 'En BRYGAR buscamos asesores de seguridad social que ya tengan clientes afiliados. '
                .'Te mejoramos tus condiciones actuales: ganas más por cada afiliación y más por la administración '
                .'mensual. Con garantía, respaldo y todo verificable. '
                .'Escríbenos y te damos la información completa, para que con los clientes que ya manejas empieces '
                .'a ganar mucho más. Y si quieres, también te ayudamos a crear tu propia empresa. Comunícate ya.',
            'copy' => 'En BRYGAR buscamos asesores de seguridad social que YA tengan clientes afiliados. '
                .'Te mejoramos tus condiciones actuales: más por cada afiliación y más por la administración '
                .'mensual, con garantía, respaldo y todo verificable.'."\n\n"
                .'Y el trabajo operativo lo hacemos nosotros: afiliaciones al instante, planillas pagadas por API '
                .'(sin que subas archivos a ningún operador), cobros automáticos por WhatsApp e incapacidades. '
                .'A tus clientes los sigues desde la aplicación, y no necesitas razones sociales propias.'."\n\n"
                .'¿Prefieres dar el siguiente paso? También te ayudamos a crear tu propia empresa.'."\n\n"
                .'📲 Escríbenos al '.self::WHATSAPP_ASESORES.' y cuéntanos cuántos clientes manejas hoy.',
        ],
        // El paso siguiente para el asesor que ya tiene volumen: dejar de ser intermediario y
        // tener su propia empresa. Es otro momento de la misma persona, por eso va en un video
        // aparte en vez de amontonarlo con el de comisiones.
        'asesores-empresa' => [
            'tema' => 'ayudamos al asesor con cartera propia a crear su propia empresa de seguridad social',
            'titulo' => '¿Ya tienes suficientes clientes? Te ayudamos a montar tu propia empresa',
            'escenas' => [
                'Vertical 9:16 cinematic shot. A Colombian man in his 30s stands looking out of a window '
                .'holding a phone, thoughtful and ambitious, morning light on his face. A small desk with a '
                .'laptop behind him. Handheld camera, shallow depth of field. Ambient sounds only. '
                .'No text on screen, nobody speaks.',

                'CAPTURAS',

                'Vertical 9:16 cinematic shot. The same Colombian man sits on a sofa at home holding his '
                .'phone with both hands, scrolling calmly with a slight smile, evening lamp light behind him. '
                .'Medium close shot, shallow depth of field. Ambient sounds only. '
                .'No text on screen, nobody speaks.',

                // Reescrita: la version con "two coworkers at desks behind him" la filtro Veo tres
                // veces seguidas. Una sola persona y un encuadre simple pasan sin problema.
                'Vertical 9:16 cinematic shot. The same Colombian man, now wearing a shirt, stands in a '
                .'bright modern office next to a large window with a city view, arms relaxed, calm '
                .'confident smile. Natural daylight, medium shot, shallow depth of field. '
                .'Ambient sounds only. No text on screen, nobody speaks.',
            ],
            'capturas' => [
                ['archivo' => '01-login.png', 'desde' => 0.5, 'hasta' => 0.5, 'zoom' => 1.15],
                ['archivo' => '11-modulos.png', 'modo' => 'ancho', 'zoom' => 1.1, 'arriba' => 0.25],
                ['archivo' => '13-tendencia.png', 'modo' => 'ancho', 'zoom' => 1.1, 'arriba' => 0.5],
            ],
            'frases' => [
                '¿Ya manejas tu propia cartera?',
                'Te montamos la plataforma',
                'Con respaldo y verificable',
                'Comunícate ya',
            ],
            // Mismo criterio que la pieza de comisiones: primero a quién le hablamos y qué gana,
            // después el cómo. Ver la narración de 'asesores'.
            'narracion' => 'En BRYGAR ayudamos a los asesores de seguridad social que ya tienen su propia cartera '
                .'a montar su propia empresa. Te damos la plataforma y el respaldo para manejarla, con garantía y '
                .'todo verificable. Y si prefieres trabajar con nosotros, te mejoramos tus condiciones actuales: '
                .'ganas más por cada afiliación y más por la administración mensual. '
                .'Escríbenos y te damos la información completa. Comunícate ya.',
            'copy' => '¿Eres asesor de seguridad social y ya tienes tu propia cartera? Te ayudamos a montar tu '
                .'propia empresa, con garantía, respaldo y todo verificable.'."\n\n"
                .'Te damos la plataforma de BRYNEX.co para manejarla: afiliaciones al instante, planillas pagadas '
                .'por API (sin subir archivos a ningún operador), cobros automáticos por WhatsApp e incapacidades, '
                .'y una aplicación para seguir a tus clientes.'."\n\n"
                .'¿Prefieres trabajar con nosotros? Te mejoramos tus condiciones: más por cada afiliación y más '
                .'por la administración mensual.'."\n\n"
                .'📲 Escríbenos al '.self::WHATSAPP_ASESORES.' y cuéntanos cuántos clientes manejas.',
        ],
        // Para quien se retiró hace meses o años. No sirve el discurso de "no te quedes sin
        // cobertura" que se le manda al que se fue el mes pasado: este ya lleva un año sin
        // ella y sobrevivió. Lo que lo mueve es que volver hoy cuesta poco y no le toca hacer
        // nada. Por eso la cifra va en el video, no solo en el copy: la plantilla de
        // reactivación sin cifra ni imagen tuvo 0 respuestas en 58 envíos, todas leídas.
        'retirados' => [
            'tema' => 'reactivación de clientes retirados hace meses: volver a estar afiliado es barato y sin trámite',
            'titulo' => '¿Te retiraste hace un tiempo? Volver es más fácil de lo que crees',
            'escenas' => [
                'Vertical 9:16 cinematic shot. A Colombian man in his 40s in work clothes takes a break '
                .'at his small workshop, looking at his phone with a thoughtful expression, as if '
                .'remembering something he left pending. Warm afternoon light through a window, handheld '
                .'camera, shallow depth of field. Ambient workshop sounds only. No text on screen, '
                .'nobody speaks.',

                'Vertical 9:16 cinematic shot. The same Colombian man back at work, relaxed and focused, '
                .'a slight satisfied smile, his phone face down on the workbench beside him. Natural '
                .'daylight, medium shot, shallow depth of field. Ambient sounds only. No text on screen, '
                .'nobody speaks.',
            ],
            'frases' => [
                '¿Te retiraste hace un tiempo?',
                'Volver cuesta desde $42.600',
            ],
            'narracion' => 'Si estuviste afiliado con BRYGAR y te retiraste, volver es más fácil de lo que crees. '
                .'Hoy puedes retomar tu seguridad social desde noventa y nueve mil novecientos pesos el primer mes, '
                .'o solo aereele desde cuarenta y dos mil seiscientos. Nosotros hacemos todo el trámite: tú solo '
                .'nos dices que sí. Escríbenos y te contamos cómo retomar.',
            'copy' => '¿Estuviste afiliado con BRYGAR y te retiraste? Volver es más fácil de lo que crees.'."\n\n"
                .'Hoy puedes retomar desde $99.900 el primer mes, o solo ARL desde $42.600. Nosotros hacemos todo '
                .'el trámite.'."\n\n"
                .'📲 Escríbenos y te contamos cómo retomar.',
        ],
        // El cruce de las dos cosas que mejor funcionan: el público de asesores —7 veces más
        // barato por conversación que el de clientes— y el mensaje de "te mejoramos cualquier
        // cotización", que fue el mejor ángulo del lado de clientes. Le habla a la oficina o
        // agencia que YA afilia y hoy paga de más, no al independiente suelto.
        'empresas' => [
            'tema' => 'empresas y oficinas con cartera propia que ya afilian: mejoramos su cotización y las condiciones de asesor',
            'titulo' => '¿Tu empresa ya afilia clientes? Te mejoramos lo que pagas hoy',
            'escenas' => [
                'Vertical 9:16 cinematic shot. A Colombian woman in her 30s in a small office reviews a '
                .'printed quote at her desk with a doubtful frown, pen in hand, a second folder open '
                .'beside her. Soft window light, handheld camera, shallow depth of field. Ambient office '
                .'sounds only. No text on screen, nobody speaks.',

                'CAPTURAS',

                'Vertical 9:16 cinematic shot. The same Colombian woman, now relaxed and confident, '
                .'shakes hands with a client across her desk while a coworker works in the background. '
                .'Bright natural light, medium shot, shallow depth of field. Ambient sounds only. '
                .'No text on screen, nobody speaks.',
            ],
            'capturas' => [
                ['archivo' => '11-modulos.png', 'modo' => 'ancho', 'zoom' => 1.1, 'arriba' => 0.25],
                ['archivo' => '12-cobros.png', 'modo' => 'ancho', 'zoom' => 1.1, 'arriba' => 0.3],
                ['archivo' => '13-tendencia.png', 'modo' => 'ancho', 'zoom' => 1.1, 'arriba' => 0.5],
            ],
            'frases' => [
                '¿Tu empresa ya afilia clientes?',
                'Te mejoramos lo que pagas hoy',
                'Mándanos tu cotización',
            ],
            'narracion' => 'Si tu empresa o tu oficina ya afilia clientes a seguridad social, en BRYGAR te '
                .'mejoramos lo que estás pagando hoy: mejores tarifas por afiliación y por administración '
                .'mensual, con garantía, respaldo y todo verificable. La operación la hacemos nosotros. '
                .'Mándanos tu cotización actual y te decimos cuánto te ahorras. Escríbenos ya.',
            'copy' => '¿Tu empresa u oficina ya afilia clientes a seguridad social? Te mejoramos lo que estás '
                .'pagando hoy: mejores tarifas por afiliación y por administración mensual, con garantía, '
                .'respaldo y todo verificable.'."\n\n"
                .'Y la operación la hacemos nosotros: afiliaciones al instante, planillas pagadas por API '
                .'(sin subir archivos a ningún operador), cobros automáticos por WhatsApp e incapacidades, '
                .'con una plataforma para que sigas a tus clientes.'."\n\n"
                .'📲 Mándanos tu cotización actual al '.self::WHATSAPP_ASESORES.' y te decimos cuánto te ahorras.',
        ],
        // Video de producto: qué hace la plataforma. El contenido sale de las láminas de
        // brynex.co/presentacion.html —las cifras y los ocho procesos son los de ahí—, pero
        // las láminas NO se muestran: son apaisadas y en un Reel vertical quedan ilegibles.
        // Lo que se ve son escenas del oficio y las capturas del panel real.
        'funcionalidades' => [
            'tema' => 'funcionalidades de la plataforma: registro oficial, ARL en un paso, planillas por API y cobros por WhatsApp',
            'titulo' => 'Ocho procesos que se ejecutan solos, en una sola plataforma',
            'escenas' => [
                'Vertical 9:16 cinematic shot. Close on the hands of a Colombian office worker typing an ID '
                .'number on a keyboard, a computer screen glowing in front of her, a coffee cup beside the '
                .'desk. Soft morning office light, shallow depth of field, camera slowly pushing in. '
                .'Ambient office sounds only. No text on screen, nobody speaks.',

                'CAPTURAS',

                'Vertical 9:16 cinematic shot. Close on the hands of a Colombian man holding a phone on a '
                .'work desk, a message notification lighting up the screen, papers neatly stacked aside. '
                .'Warm indoor light, shallow depth of field. Ambient sounds only. No text on screen, '
                .'nobody speaks.',

                'Vertical 9:16 cinematic shot. A small Colombian office at the end of the day, two coworkers '
                .'at their desks working calmly, no paper piles anywhere, warm late afternoon light through '
                .'a window. Medium wide shot, shallow depth of field. Ambient sounds only. No text on '
                .'screen, nobody speaks.',
            ],
            // Las del panel real, que en vertical se leen; las láminas de la presentación no.
            'capturas' => [
                ['archivo' => '11-modulos.png', 'modo' => 'ancho', 'zoom' => 1.1, 'arriba' => 0.25],
                ['archivo' => '12-cobros.png', 'modo' => 'ancho', 'zoom' => 1.1, 'arriba' => 0.3],
                ['archivo' => '13-tendencia.png', 'modo' => 'ancho', 'zoom' => 1.1, 'arriba' => 0.5],
            ],
            'frases' => [
                'El documento trae los datos',
                'Planillas por API',
                'Cobros por WhatsApp',
                'Todo en una sola plataforma',
            ],
            'narracion' => 'BRYGAR no trabaja con un software cualquiera: usa BRYNEX punto co, el back office '
                .'completo de una agremiadora. Ocho procesos se ejecutan solos. El documento del cliente trae '
                .'sus datos del registro oficial, sin digitar. La afiliación a riesgos sale en un paso, con '
                .'carné en PDF. Las planillas se liquidan por API y vuelven numeradas con su enlace de pago. '
                .'Y los cobros salen por WhatsApp con número propio. Escríbenos y te mostramos la plataforma.',
            'copy' => 'BRYGAR opera sobre BRYNEX.co: 41 módulos, 7 entidades conectadas y ocho procesos que se '
                .'ejecutan solos.'."\n\n"
                .'• El documento del cliente recupera nombres, EPS y fondo de pensiones del registro oficial.'."\n"
                .'• Afiliación a riesgos en un solo paso, con carné y soporte en PDF.'."\n"
                .'• Liquidación de planillas por API: vuelven numeradas y con enlace de pago.'."\n"
                .'• Verificación en salud ante la fuente oficial.'."\n"
                .'• Facturación electrónica en lote ante la DIAN, cada factura con su acuse.'."\n"
                .'• Cobros por WhatsApp con número propio y asistente que responde con datos del sistema.'."\n"
                .'• Conciliación bancaria: cruce del extracto contra el libro.'."\n\n"
                .'📲 Escríbenos al '.self::WHATSAPP_ASESORES.' y te mostramos la plataforma.',
        ],
        // Variantes del único ángulo que trae asesores (#90: 6 conversaciones a $2.585, contra
        // 44 conversaciones y CERO contratos del lado de clientes). Mismo mensaje y mismo
        // cierre; lo que cambia es el gancho de los primeros segundos, que es donde se decide
        // si siguen viendo. Con una sola creatividad la audiencia se quema y el costo sube:
        // la #90 pasó de $913 a $2.585 por conversación en cinco días.
        'asesores-comision' => [
            'tema' => 'asesores con cartera propia: cuánto les queda por afiliado y cuánto podrían ganar con mejores comisiones',
            'titulo' => '¿Cuánto te queda a ti por cada afiliado?',
            'escenas' => [
                'Vertical 9:16 cinematic shot. A Colombian man in his 30s sits in his car after a work '
                .'visit, looking at his phone with a disappointed expression, doing numbers in his head. '
                .'Late afternoon light through the windshield, handheld camera, shallow depth of field. '
                .'Ambient street sounds only. No text on screen, nobody speaks.',

                'CAPTURAS',

                'Vertical 9:16 cinematic shot. The same Colombian man walking into a bright cafe, '
                .'greeting a client with a confident handshake, relaxed posture. Natural daylight, '
                .'medium shot, shallow depth of field. Ambient sounds only. No text on screen, '
                .'nobody speaks.',
            ],
            'capturas' => [
                ['archivo' => '11-modulos.png', 'modo' => 'ancho', 'zoom' => 1.1, 'arriba' => 0.25],
                ['archivo' => '12-cobros.png', 'modo' => 'ancho', 'zoom' => 1.1, 'arriba' => 0.3],
            ],
            'frases' => [
                '¿Cuánto te queda por afiliado?',
                'Con nosotros, más',
                'Escríbenos ya',
            ],
            'narracion' => 'Si vendes seguridad social, haz la cuenta: ¿cuánto te queda a ti por cada afiliado? '
                .'En BRYGAR te mejoramos lo que ganas hoy, por cada afiliación y por la administración mensual, '
                .'con garantía y todo verificable. Y la operación la hacemos nosotros. '
                .'Escríbenos y cuéntanos cuántos clientes manejas.',
            'copy' => 'Si vendes seguridad social, haz la cuenta: ¿cuánto te queda a ti por cada afiliado?'."\n\n"
                .'En BRYGAR te mejoramos lo que ganas hoy — por cada afiliación y por la administración mensual —, '
                .'con garantía, respaldo y todo verificable. La operación la hacemos nosotros: afiliaciones al '
                .'instante, planillas por API, cobros automáticos por WhatsApp e incapacidades.'."\n\n"
                .'📲 Escríbenos al '.self::WHATSAPP_ASESORES.' y cuéntanos cuántos clientes manejas.',
        ],

        'asesores-respaldo' => [
            'tema' => 'asesoras con cartera propia que cargan solas con la operación: nosotros ponemos el respaldo',
            'titulo' => '¿Tienes los clientes pero no el respaldo?',
            'escenas' => [
                'Vertical 9:16 cinematic shot. A Colombian woman in her 40s works late at her kitchen '
                .'table surrounded by folders and a laptop, rubbing her neck with tiredness while her '
                .'phone lights up with messages. Warm lamp light, handheld camera, shallow depth of '
                .'field. Ambient sounds only. No text on screen, nobody speaks.',

                'CAPTURAS',

                'Vertical 9:16 cinematic shot. The same Colombian woman, rested and smiling, closes her '
                .'laptop at a tidy desk and picks up a cup of coffee. Bright morning light, medium shot, '
                .'shallow depth of field. Ambient sounds only. No text on screen, nobody speaks.',
            ],
            'capturas' => [
                ['archivo' => '24-cartera.png', 'modo' => 'ancho', 'fondo' => '0xeef2f7'],
                ['archivo' => '21-automatizaciones.png', 'modo' => 'ancho', 'fondo' => '0x0d1b3e'],
            ],
            'frases' => [
                '¿Tienes clientes y no respaldo?',
                'Nosotros hacemos la operación',
                'Tú solo vendes',
            ],
            'narracion' => 'Si ya tienes tus clientes de seguridad social pero cargas sola con todo el trabajo, '
                .'en BRYGAR ponemos el respaldo: afiliaciones al instante, planillas pagadas por API, cobros '
                .'automáticos por WhatsApp e incapacidades. Tú te quedas con tus clientes y con mejores '
                .'condiciones. Escríbenos y cuéntanos cuántos manejas.',
            'copy' => '¿Ya tienes tus clientes de seguridad social pero cargas solo con toda la operación?'."\n\n"
                .'En BRYGAR ponemos el respaldo: afiliaciones al instante, planillas pagadas por API, cobros '
                .'automáticos por WhatsApp e incapacidades. Tú te quedas con tus clientes y con mejores '
                .'condiciones por afiliación y por administración mensual.'."\n\n"
                .'📲 Escríbenos al '.self::WHATSAPP_ASESORES.' y cuéntanos cuántos clientes manejas.',
        ],
        // El público no es solo quien vende seguridad social: es quien YA tiene la relación con
        // el cliente y hoy le dice "eso yo no lo manejo". Un contador tiene decenas de
        // independientes y empresas pequeñas preguntándole por EPS, ARL y planillas.
        // Asesores y aliados, pero esta vez el protagonista es el SOFTWARE: lo que el sistema
        // hace solo mientras ellos venden. Existe porque la retención dijo que el video no se
        // ve —4 segundos de promedio en #90— y aun así trae leads: el gancho de los 3 primeros
        // segundos y el texto en pantalla son lo que decide. Por eso abre con la pregunta al
        // asesor, con la frase grande desde el primer cuadro, y las dos escenas de capturas
        // enseñan la plataforma real y la calculadora pública de brynex.co/aliados.
        //
        // Las cifras son las públicas de /aliados (ver memoria plan-asesor-brygar): mitad de la
        // afiliación y 20/30/40/50 % de administración según cartera. Por unidad, nunca un
        // total, y sin «garantizado».
        'aliados-plataforma' => [
            'tema' => 'asesores y empresas con cartera: solo el programa, razones sociales o todo lo operativo, según sus afiliados',
            'titulo' => 'Somos tu aliado: solo el programa, razones sociales o todo lo operativo',
            'escenas' => [
                'Vertical 9:16 cinematic shot. A Colombian woman in her 30s, a sales advisor, sits at a '
                .'cafe table with a phone in one hand and a thick folder of forms in the other, glancing '
                .'between them with mild frustration, a coffee going cold. Natural window light, medium '
                .'close-up, shallow depth of field, slow push in. Ambient cafe sounds only. No text on '
                .'screen, nobody speaks.',

                // La plataforma real: módulos, cobros y tendencia.
                'CAPTURAS',

                'Vertical 9:16 cinematic shot. The same Colombian woman now relaxed on a park bench in '
                .'late afternoon light, her phone face-up beside her lighting up with notification after '
                .'notification while she sips coffee and smiles, not touching it. Medium shot, shallow '
                .'depth of field, golden hour. Ambient park sounds only. No text on screen, nobody speaks.',

                // La calculadora pública y la página de Brygar: lo que ella misma puede abrir.
                'CAPTURAS',
            ],
            'capturas' => [
                [
                    ['archivo' => '11-modulos.png', 'modo' => 'ancho', 'zoom' => 1.1, 'arriba' => 0.25],
                    ['archivo' => '12-cobros.png', 'modo' => 'ancho', 'zoom' => 1.1, 'arriba' => 0.3],
                    ['archivo' => '13-tendencia.png', 'modo' => 'ancho', 'zoom' => 1.1, 'arriba' => 0.5],
                ],
                [
                    ['archivo' => '31-cuantas-personas.png', 'modo' => 'ancho', 'zoom' => 1.1, 'arriba' => 0.0],
                    // La captura viene recortada al encabezado (800×810): más abajo la página carga por
                    // tramos y sale a medias, y con zoom alto el título se cortaba por los lados.
                    ['archivo' => '32-pagina-brygar.png', 'modo' => 'ancho', 'zoom' => 1.1, 'arriba' => 0.0],
                ],
            ],
            // Las tres puertas del volante de alianzas, en el orden en que crece el aliado. Son
            // las de brynex.co/aliados (Esencial, Específica, Integral) dichas sin nombre de plan.
            'frases' => [
                '¿Cuántos clientes manejas hoy?',
                'Solo el programa',
                'O nuestras razones sociales',
                'O todo lo operativo',
                'Tú eliges, según tus afiliados',
            ],
            'narracion' => 'Si eres asesor o tienes empresa de seguridad social, en BRYGAR somos tu aliado. '
                .'Te ayudamos con lo que necesites. Solo el programa: BRYNEX, con afiliaciones automáticas, '
                .'planillas por API y una inteligencia artificial que atiende a tus clientes por WhatsApp. '
                .'O también nuestras razones sociales, para que afilies sin tener empresa propia. '
                .'O te hacemos todo lo operativo, y tú solo vendes. '
                .'Tú eliges, según tus afiliados. Escríbenos y armamos tu plan.',
            'copy' => '¿Eres asesor o tienes empresa de seguridad social? En BRYGAR somos tu aliado y te ayudamos '
                .'con lo que necesites, según cuántos afiliados manejes:'."\n\n"
                .'• Solo el programa: BRYNEX.co, con afiliaciones automáticas a ARL y EPS, planillas por API, cobros '
                .'por WhatsApp y una IA que atiende a tus clientes, lee comprobantes y responde notas de voz.'."\n"
                .'• El programa más nuestras razones sociales, para afiliar sin tener empresa propia.'."\n"
                .'• O todo lo operativo hecho por nosotros: tú solo vendes.'."\n\n"
                .'Y si trabajas con menos de 100 afiliados, el Plan Asesor: la mitad de cada afiliación y del 20 % al '
                .'50 % de la administración según tu cartera, sin pagar plataforma.'."\n\n"
                .'📲 Escríbenos al '.self::WHATSAPP_ASESORES.' con cuántos clientes manejas, o mira las opciones en brynex.co/aliados.'
        ],
        'contadores' => [
            'tema' => 'contadores y asesores con cartera propia: sus clientes piden afiliaciones y ellos ganan por traerlas',
            'titulo' => '¿Tus clientes te preguntan por EPS y ARL?',
            'escenas' => [
                'Vertical 9:16 cinematic shot. A Colombian accountant in his 40s at his desk with '
                .'spreadsheets on screen glances at his phone showing a client message, with a resigned '
                .'expression of "not my area". Soft office light, handheld camera, shallow depth of '
                .'field. Ambient office sounds only. No text on screen, nobody speaks.',

                'CAPTURAS',

                'Vertical 9:16 cinematic shot. The same Colombian accountant, relaxed and satisfied, '
                .'closes a folder and shakes hands with a client across his desk. Bright natural light, '
                .'medium shot, shallow depth of field. Ambient sounds only. No text on screen, '
                .'nobody speaks.',
            ],
            'capturas' => [
                ['archivo' => '11-modulos.png', 'modo' => 'ancho', 'zoom' => 1.1, 'arriba' => 0.25],
                ['archivo' => '21-automatizaciones.png', 'modo' => 'ancho', 'fondo' => '0x0d1b3e'],
            ],
            'frases' => [
                '¿Te piden EPS, ARL y planillas?',
                'Nosotros lo hacemos por ti',
                'Y tú ganas por cada uno',
            ],
            'narracion' => 'Si eres contador, tus clientes te preguntan por EPS, aereele y planillas, y eso no '
                .'es lo tuyo. En BRYGAR lo hacemos nosotros: afiliaciones al instante, planillas pagadas por API '
                .'y cobros automáticos. Tú no pones razón social ni haces trámites, y ganas por cada cliente que '
                .'nos traes. Escríbenos y cuéntanos cuántos manejas.',
            'copy' => '¿Eres contador y tus clientes te preguntan por EPS, ARL y planillas?'."\n\n"
                .'En BRYGAR lo hacemos nosotros: afiliaciones al instante, planillas pagadas por API, cobros '
                .'automáticos por WhatsApp e incapacidades. Tú no pones razón social, no haces trámites, y ganas '
                .'por cada cliente que nos traes.'."\n\n"
                .'También trabajamos con asesores de EPS, de seguros y con oficinas que ya manejan cartera.'."\n\n"
                .'📲 Escríbenos al '.self::WHATSAPP_ASESORES.' y cuéntanos cuántos clientes manejas.',
        ],
    ];

    public function handle(): int
    {
        if ($this->option('listar')) {
            foreach (self::GUIONES as $nombre => $g) {
                $this->line("  {$nombre} — ".count($g['escenas']).' escenas ('.count($g['escenas']) * 8 .'s) — '.mb_substr($g['titulo'], 0, 50));
            }

            return self::SUCCESS;
        }

        $guion = self::GUIONES[$this->argument('guion')] ?? null;
        if (! $guion) {
            $this->error('No existe ese guion. Ver --listar.');

            return self::FAILURE;
        }

        $aliado = Aliado::where('slug', $this->option('aliado'))->first();
        if (! $aliado) {
            $this->error('No existe ese aliado.');

            return self::FAILURE;
        }

        $iaConfig = IaConfiguracionAliado::paraAliado($aliado->id);
        if (! $iaConfig->tieneGemini()) {
            $this->error('No hay clave de Gemini configurada.');

            return self::FAILURE;
        }

        $config = AutopilotConfig::paraAliado($aliado->id);

        // El piloto diario usa lite porque son piezas de 8s y salen a diario. Para una pieza
        // que se va a pautar semanas vale la pena el standard: ocho veces más caro por
        // segundo, pero la diferencia de imagen se nota en pantalla completa.
        $modelo = $this->option('calidad') === 'standard'
            ? VeoVideoGenerator::MODELO_STANDARD
            : $config->modeloVideo();
        $duracion = count($guion['escenas']) * 8;
        $escenasVeo = count(array_filter($guion['escenas'], fn ($e) => $e !== 'CAPTURAS'));

        $this->line("Guion: {$this->argument('guion')} — {$duracion}s en ".count($guion['escenas']).' escenas');
        $this->line('Costo estimado: ~USD '.number_format(VeoVideoGenerator::costoEstimadoUsd($modelo, $escenasVeo * 8), 2)
            .' ('.$escenasVeo.' de '.count($guion['escenas']).' escenas van a Veo)');

        $escenas = [];
        $capturasUsadas = 0;
        foreach ($guion['escenas'] as $orden => $prompt) {
            // La escena marcada CAPTURAS no la genera Veo: se arma con pantallazos reales de la
            // plataforma. Veo no sabe escribir texto legible, y a un asesor con experiencia una
            // interfaz inventada lo pierde en dos segundos.
            if ($prompt === 'CAPTURAS') {
                // Con varias escenas de capturas, `capturas` trae un grupo por escena: así un
                // video puede recorrer láminas distintas en cada tramo en vez de repetir las
                // mismas tres. Con una sola escena sigue siendo la lista de siempre.
                $grupos = $guion['capturas'] ?? [];
                $lote = isset($grupos[0][0]) && is_array($grupos[0][0])
                    ? ($grupos[$capturasUsadas] ?? [])
                    : $grupos;
                $capturasUsadas++;

                $clip = $this->clipDeCapturas($lote);
                if (! $clip['ok']) {
                    $this->error('Escena '.($orden + 1).': '.$clip['error']);

                    return self::FAILURE;
                }
                $this->line('  escena '.($orden + 1).' armada con capturas reales');
                $escenas[] = [
                    'orden' => $orden,
                    'prompt' => 'Capturas de la plataforma',
                    'operation_name' => null,
                    'estado' => 'lista',
                    'video_bruto_path' => $clip['path'],
                ];

                continue;
            }

            $inicio = VeoVideoGenerator::iniciar($iaConfig->gemini_api_key, $prompt, $modelo, '9:16', '720p', 8);
            if (! $inicio['ok']) {
                $this->error('Escena '.($orden + 1).': '.$inicio['error']);

                return self::FAILURE;
            }
            $this->line('  escena '.($orden + 1).' encolada en Veo');
            $escenas[] = [
                'orden' => $orden,
                'prompt' => $prompt,
                'operation_name' => $inicio['operationName'],
                'estado' => 'generando',
                'video_bruto_path' => null,
            ];
        }

        $video = PublicidadVideoIa::create([
            'aliado_id' => $aliado->id,
            'prompt_video' => implode(' / ', $guion['escenas']),
            'frases_texto' => $guion['frases'],
            // Con guion propio nadie habla: las frases van en pantalla y la narración en off
            // las lee, que es lo que hace que se entienda sin sonido y también con él.
            'narrar' => true,
            'modelo' => $modelo,
            'duracion_seg' => $duracion,
            'costo_estimado_usd' => VeoVideoGenerator::costoEstimadoUsd($modelo, $escenasVeo * 8),
            'escenas' => $escenas,
            'autopilot_payload' => [
                'tema' => $guion['tema'],
                'titulo' => $guion['titulo'],
                'copy' => $guion['copy'] ?? $guion['titulo'],
                'narracion' => $guion['narracion'] ?? null,
                'modo' => AutopilotConfig::MODO_APROBAR,
                'destinos' => array_merge(['web'], RedSocialConfig::where('aliado_id', $aliado->id)->where('activo', true)->pluck('red')->all()),
            ],
            'creado_por' => null,
        ]);

        $this->info("Video #{$video->id} en generación. La pieza se crea al terminar.");

        return self::SUCCESS;
    }

    /**
     * Arma el clip de 8s con las capturas de la plataforma.
     *
     * @param  string[]  $nombres  Archivos dentro de storage/app/public/publicidad/capturas.
     * @return array{ok: bool, path: ?string, error: ?string}
     */
    private function clipDeCapturas(array $nombres): array
    {
        if (empty($nombres)) {
            return ['ok' => false, 'path' => null, 'error' => 'El guion no dice qué capturas usar.'];
        }

        // Van en `resources` y no en `storage/app/public` a propósito: storage no se sincroniza
        // a producción, y el video se genera allá. En resources viajan con el mismo pull que el
        // código, sin un scp aparte que alguien tenga que acordarse de hacer.
        $base = base_path('resources/publicidad/capturas');
        $rutas = [];
        $faltan = [];
        foreach ($nombres as $entrada) {
            $entrada = is_array($entrada) ? $entrada : ['archivo' => $entrada];
            $entrada['archivo'] = $base.'/'.$entrada['archivo'];
            is_file($entrada['archivo']) ? $rutas[] = $entrada : $faltan[] = basename($entrada['archivo']);
        }

        if ($faltan) {
            return [
                'ok' => false,
                'path' => null,
                'error' => 'Faltan capturas en '.$base.': '.implode(', ', $faltan),
            ];
        }

        return ClipDeCapturas::generar($rutas, 8, ClipDeCapturas::rutaTemporal());
    }
}
