<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ArlAfiliacion;
use App\Models\ArlCredencial;
use App\Models\ArlCentroTrabajo;
use App\Models\Bitacora;
use App\Models\Contrato;
use App\Models\DocumentoCliente;
use App\Services\ArlSura\ArlAfiliacionService;
use App\Services\ArlSura\ArlCentrosService;
use App\Services\ArlSura\ArlDatosFaltantesService;
use App\Services\ClavePortalSincronizador;
use App\Services\ArlSura\ArlSuraApiService;
use App\Services\ArlSura\ArlSuraPayloadBuilder;
use App\Services\ArlSura\ArlSuraSesionService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Afiliación a ARL Sura desde BryNex, sin pasar por el portal.
 *
 * Dos pasos deliberados: `precheck` dice qué falta antes de tocar nada, y
 * `afiliar` ejecuta. Se separan porque la afiliación es irreversible pasados 30
 * días y porque la mitad de los contratos tienen algún dato incompleto: es mejor
 * que el usuario los vea todos juntos que descubrirlos de a uno.
 */
class ArlAfiliacionController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Qué se va a enviar y qué falta. No llama al portal, así que funciona
     * aunque no haya sesión abierta con Sura.
     */
    public function precheck(Request $request, int $contratoId)
    {
        $contrato = $this->contrato($request, $contratoId);
        $builder  = new ArlSuraPayloadBuilder(new ArlSuraApiService(
            (int) $contrato->aliado_id,
            (string) ($contrato->razonSocial?->arl_poliza ?? '')
        ));

        // Antes de decirle a nadie que faltan datos, se intenta conseguirlos:
        // la AFP la sabe el RUAF y el sexo lo sabe Sura si la persona ya estuvo
        // afiliada. Solo se consulta cuando de verdad faltan.
        $completado = (new ArlDatosFaltantesService)->completar($contrato);

        if ($completado) {
            $contrato->refresh()->load(['cliente', 'eps', 'pension']);
        }

        $problemas = $builder->problemas($contrato);
        $centro    = (int) $contrato->n_arl
            ? ArlCentroTrabajo::paraRiesgo((int) $contrato->razon_social_id, (int) $contrato->n_arl)
            : null;

        $cliente = $contrato->cliente;
        $vigente = ArlAfiliacion::vigenteDe($contrato->id);

        // Si lo que falta es la credencial del portal, no es un dato del
        // trabajador: se le pide al usuario en el momento y queda guardada.
        $rs = $contrato->razonSocial;
        // Se busca también por NIT: una empresa puede tener credencial cargada
        // aunque todavía no se le haya descubierto la póliza.
        $credencial = ArlSuraSesionService::credencialPara(
            (int) $contrato->aliado_id,
            (string) ($rs?->arl_poliza ?? ''),
            $rs?->nit
        );

        $faltaCredencial = ! $credencial || ! $rs?->arl_poliza;

        return response()->json([
            'ok'         => empty($problemas) && ! $faltaCredencial,
            'problemas'  => $faltaCredencial ? [] : $problemas,
            'completado' => $completado,
            'requiere_credencial' => $faltaCredencial ? [
                'nit'          => $rs?->nit,
                'razon_social' => $rs?->razon_social,
            ] : null,
            'resumen'   => [
                'trabajador'   => trim(collect([
                    $cliente?->primer_nombre, $cliente?->segundo_nombre,
                    $cliente?->primer_apellido, $cliente?->segundo_apellido,
                ])->filter()->implode(' ')),
                'documento'    => $contrato->cedula,
                'razon_social' => $contrato->razonSocial?->razon_social,
                'poliza'       => $contrato->razonSocial?->arl_poliza,
                'tipo'         => $this->etiquetaTipo($builder->tipoAfiliado($contrato)),
                'modalidad'    => $contrato->tipoModalidad?->tipo_modalidad,
                'eps'          => ($contrato->eps ?: $cliente?->eps)?->razon_social,
                'afp'          => ($contrato->pension ?: $cliente?->pension)?->razon_social,
                'ibc'          => (int) ($contrato->ibc ?: $contrato->salario),
                // El cargo efectivo: el del contrato o, si está vacío, el que
                // la razón social tenga por defecto para ese nivel de riesgo.
                // Es el que va a quedar registrado en la ARL, así que se muestra.
                'cargo'        => $contrato->cargo
                    ?: optional(\App\Models\RazonSocialCargo::porDefecto(
                        (int) $contrato->razon_social_id,
                        (int) $contrato->n_arl
                    ))->cargo.($contrato->cargo ? '' : ' (por defecto)'),
                'nivel_riesgo' => $contrato->n_arl,
                'centro'       => $centro ? $centro->codigo_centro.' — '.$centro->nombre_centro : null,
                'tasa'         => $centro?->tasa,
            ],
            'fecha_sugerida' => $this->fechaSugerida(),
            // Lo que la renovación va a anular. Se mira también `fecha_arl`
            // porque los contratos afiliados a mano antes de la integración no
            // tienen historial en BryNex, pero su cobertura sí existe en Sura.
            'cobertura_actual' => $this->coberturaActual($contrato, $vigente, $faltaCredencial),
            'ya_afiliado'    => $vigente ? [
                'codigo_transaccion' => $vigente->codigo_transaccion,
                'desde'              => $vigente->fecha_inicio_cobertura?->format('d/m/Y'),
                'se_puede_anular'    => $vigente->sePuedeAnular(),
            ] : null,
        ]);
    }

    /**
     * Fecha que se propone para la cobertura nueva: siempre mañana.
     *
     * Antes se proponía el día del vencimiento (`fecha_arl` + 29), pensando en
     * empalmar una cobertura con la siguiente. Pero renovar no agrega un
     * período: MUEVE el inicio de la cobertura que ya existe. Proponer una
     * fecha futura la corre hacia adelante y deja al trabajador descubierto
     * desde hoy hasta ese día —a CRISTIAN PAJA, que arranca el 12/08, moverlo
     * al 10/09 lo dejaba sin ARL 8 días—. Mañana es lo más pronto que Sura
     * cubre, así que es lo que menos hueco deja.
     *
     * De paso se cae el desfase de la cuenta vieja: salía de la `fecha_arl` de
     * BryNex mientras el aviso del modal muestra la del portal, y no siempre
     * coinciden (Cristian: 11/08 aquí, 12/08 allá).
     */
    private function fechaSugerida(): string
    {
        return now()->addDay()->startOfDay()->toDateString();
    }

    /**
     * La cobertura viva del contrato.
     *
     * Se le pregunta al portal antes que nada: 45 de los contratos del módulo
     * se afiliaron a mano y tienen `fecha_arl` vacía aunque su cobertura exista
     * en Sura. Mostrar "no tiene cobertura" en esos casos llevaría a crear una
     * segunda encima de la primera.
     */
    private function coberturaActual(Contrato $contrato, ?ArlAfiliacion $vigente, bool $faltaCredencial): ?array
    {
        $enSura = null;

        if (! $faltaCredencial) {
            try {
                $enSura = ArlAfiliacionService::paraContrato($contrato)->coberturaEnSura($contrato);
            } catch (Throwable $e) {
                Log::warning('ARL Sura: no se pudo consultar la cobertura vigente', [
                    'contrato' => $contrato->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        $desde = $enSura && ! empty($enSura['fechaInicioCobertura'])
            ? Carbon::createFromFormat('d/m/Y', $enSura['fechaInicioCobertura'])->startOfDay()
            : ($vigente?->fecha_inicio_cobertura ?? $contrato->fecha_arl);

        if (! $desde) {
            return null;
        }

        return [
            'desde'              => $desde->format('d/m/Y'),
            'se_puede_anular'    => $desde->diffInDays(now(), false) <= 30,
            'codigo_transaccion' => $vigente?->codigo_transaccion,
            'en_historial'       => (bool) $vigente,
            'confirmada_en_sura' => (bool) $enSura,
            'centro'             => $enSura['dsCentroTrabajo'] ?? null,
        ];
    }

    /** Ejecuta la afiliación y archiva soporte y carné. */
    public function afiliar(Request $request, int $contratoId)
    {
        // Si la sesión con Sura caducó hay que reabrirla con un navegador, y eso
        // solo tarda más que los 30 s por defecto de PHP.
        $this->sinLimiteDeTiempo();

        $contrato = $this->contrato($request, $contratoId);

        $datos = $request->validate([
            'fecha_inicio_cobertura' => 'required|date',
        ]);

        try {
            $afiliacion = ArlAfiliacionService::paraContrato($contrato)->afiliar(
                $contrato,
                Carbon::parse($datos['fecha_inicio_cobertura']),
                Auth::id()
            );
        } catch (Throwable $e) {
            return response()->json([
                'ok'      => false,
                'mensaje' => $e->getMessage(),
            ], 422);
        }

        Bitacora::registrar(
            'created',
            'Contrato',
            $contrato->id,
            "Afiliación ARL Sura: transacción {$afiliacion->codigo_transaccion}, cobertura desde ".
                $afiliacion->fecha_inicio_cobertura->format('d/m/Y'),
            ['arl_afiliacion_id' => $afiliacion->id],
            (int) $contrato->aliado_id
        );

        return response()->json([
            'ok'                 => true,
            'mensaje'            => 'Trabajador afiliado en ARL Sura.',
            'codigo_transaccion' => $afiliacion->codigo_transaccion,
            'fecha_arl'          => $afiliacion->fecha_inicio_cobertura->format('Y-m-d'),
            'fecha_display'      => $afiliacion->fecha_inicio_cobertura->format('d/m/Y'),
            'aviso'              => $afiliacion->mensaje_error,
        ]);
    }

    /**
     * Guarda la credencial del portal de esa empresa y descubre su póliza.
     *
     * La credencial se ata al NIT, no al aliado ni a la razón social: la misma
     * empresa está registrada en varios aliados, así que cargarla una vez sirve
     * para todos y cambiarla la cambia en todas partes.
     */
    public function credencial(Request $request, int $contratoId)
    {
        $this->sinLimiteDeTiempo();

        $contrato = $this->contrato($request, $contratoId);
        $nit      = preg_replace('/\D/', '', (string) $contrato->razonSocial?->nit);

        if (! $nit) {
            return response()->json(['ok' => false, 'mensaje' => 'La razón social no tiene NIT registrado.'], 422);
        }

        $datos = $request->validate([
            'tipo_documento' => 'required|string|max:4',
            'usuario'        => 'required|string|max:30',
            'contrasena'     => 'required|string|max:100',
        ]);

        // La contraseña se guarda contra el usuario del portal, no contra la
        // empresa: si esa persona administra varias razones sociales, cambiarla
        // aquí la deja al día en todas.
        $credencial = ArlCredencial::registrar(
            (int) $contrato->aliado_id,
            $nit,
            $datos['tipo_documento'],
            trim($datos['usuario']),
            $datos['contrasena'],
        );

        // El llavero también queda al día: quien lo consulte a mano ve la misma
        // clave que usa la afiliación automática.
        // Alcanza también a la EPS: en Sura el mismo usuario entra a ARL y a
        // EPS, y el NIT lo pregunta después.
        ClavePortalSincronizador::propagarSura(
            trim($datos['usuario']),
            $datos['contrasena'],
            $datos['tipo_documento'],
        );

        $r = ArlSuraSesionService::descubrirPoliza($credencial, $nit);

        if (! $r['ok']) {
            return response()->json(['ok' => false, 'mensaje' => $r['error']], 422);
        }

        // Con la póliza ya conocida se traen sus centros de trabajo: sin ellos
        // la afiliación fallaría igual, por no saber a qué centro va el
        // trabajador según su nivel de riesgo.
        $centros = ['centros' => 0, 'razones' => 0];

        try {
            $centros = ArlCentrosService::sincronizarPorPoliza($r['poliza'], (int) $contrato->aliado_id);
        } catch (Throwable $e) {
            Log::warning('ARL Sura: póliza guardada pero sin centros', [
                'poliza' => $r['poliza'], 'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'ok'      => true,
            'mensaje' => 'Credenciales guardadas. Póliza '.$r['poliza'].
                ' registrada para '.($r['empresa'] ?: $contrato->razonSocial->razon_social).'.'.
                ($centros['centros']
                    ? ' Se sincronizaron '.$centros['centros'].' centro(s) de trabajo.'
                    : ' Ojo: el portal no devolvió centros de trabajo para esta póliza.'),
            'poliza'  => $r['poliza'],
            'centros' => $centros['centros'],
        ]);
    }

    /**
     * Anula la afiliación. Solo dentro de los 30 días desde el inicio de la
     * cobertura: después la salida es el retiro.
     */
    public function anular(Request $request, int $contratoId)
    {
        // La anulación no tiene API: la hace un navegador sobre tres pantallas
        // del Struts, y eso pasa de largo el límite por defecto de PHP.
        $this->sinLimiteDeTiempo();

        $contrato = $this->contrato($request, $contratoId);

        try {
            $anulacion = ArlAfiliacionService::paraContrato($contrato)->anular($contrato, Auth::id());
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'mensaje' => $e->getMessage()], 422);
        }

        Bitacora::registrar(
            'deleted',
            'Contrato',
            $contrato->id,
            'Afiliación ARL Sura anulada (transacción '.$anulacion->codigo_transaccion.')',
            ['arl_afiliacion_id' => $anulacion->id],
            (int) $contrato->aliado_id
        );

        return response()->json([
            'ok'      => true,
            'mensaje' => 'Afiliación anulada en ARL Sura. La cobertura desapareció y el radicado volvió a pendiente.',
        ]);
    }

    /**
     * Renueva la cobertura del ciclo mensual: anula la vigente y crea una
     * nueva desde la fecha pedida.
     *
     * Sura no deja mover la fecha de una cobertura ya creada, así que el
     * trámite son dos pasos contra el portal. Ambos quedan en el historial.
     */
    public function renovar(Request $request, int $contratoId)
    {
        // Son dos trámites seguidos contra el portal, cada uno con su navegador.
        $this->sinLimiteDeTiempo();

        $contrato = $this->contrato($request, $contratoId);

        $datos = $request->validate([
            'fecha_inicio_cobertura' => 'required|date',
        ]);

        try {
            $ciclo = ArlAfiliacionService::paraContrato($contrato)->renovar(
                $contrato,
                Carbon::parse($datos['fecha_inicio_cobertura']),
                Auth::id()
            );
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'mensaje' => $e->getMessage()], 422);
        }

        // La cobertura del portal ya arrancaba ese día: no se tocó nada. No es
        // un error, así que en vez de un fallo se responde qué fecha tiene el
        // portal, para que la pantalla ofrezca registrarla en BryNex y se acabe
        // el desfase que motivó el intento.
        if ($ciclo['sin_cambio']) {
            return response()->json([
                'ok'            => false,
                'sin_cambio'    => true,
                'fecha_arl'     => $ciclo['sin_cambio']->format('Y-m-d'),
                'fecha_display' => $ciclo['sin_cambio']->format('d/m/Y'),
                'mensaje'       => 'En el portal la cobertura ya arranca el '
                    .$ciclo['sin_cambio']->format('d/m/Y').', así que no hay nada que mover. '
                    .'Lo que falta es registrar esa fecha en BryNex.',
            ]);
        }

        // O se movió la cobertura que ya existía, o no había ninguna y se afilió.
        $movimiento = $ciclo['modificacion'] ?: $ciclo['afiliacion'];
        $desde      = $movimiento->fecha_inicio_cobertura->format('d/m/Y');
        $movida     = (bool) $ciclo['modificacion'];

        Bitacora::registrar(
            'updated',
            'Contrato',
            $contrato->id,
            $movida
                ? "Renovación ARL Sura: cobertura movida a {$desde}"
                : "Renovación ARL Sura: nueva cobertura desde {$desde} (transacción {$movimiento->codigo_transaccion})",
            [
                'arl_modificacion_id' => $ciclo['modificacion']?->id,
                'arl_afiliacion_id'   => $ciclo['afiliacion']?->id,
            ],
            (int) $contrato->aliado_id
        );

        return response()->json([
            'ok'      => true,
            'mensaje' => $movida
                ? 'Cobertura movida en ARL Sura, con el certificado nuevo descargado.'
                : 'No había cobertura activa, así que se creó la afiliación en ARL Sura.',
            'movida'             => $movida,
            'codigo_transaccion' => $movimiento->codigo_transaccion,
            'fecha_arl'          => $movimiento->fecha_inicio_cobertura->format('Y-m-d'),
            'fecha_display'      => $desde,
            'aviso'              => $movimiento->mensaje_error,
        ]);
    }

    /**
     * Entrega el certificado ARL: el archivado hoy, o uno nuevo del portal.
     *
     * Existe aparte de la renovación porque el certificado cambia solo con el
     * tiempo: el que se descarga el mismo día que se mueve la cobertura sale
     * como "POR INICIAR", y al llegar la fecha pasa a estar activo. Este botón
     * permite volver por el bueno sin repetir ningún trámite.
     *
     * Y como ese cambio ocurre a medianoche, dentro del mismo día el archivado
     * y el del portal son el mismo papel: se reutiliza en vez de volver a
     * bajarlo. El carné va en el mismo viaje, así que también queda al día.
     */
    public function certificado(Request $request, int $contratoId)
    {
        // Baja dos PDF del portal, y si la sesión caducó hay que reabrirla.
        $this->sinLimiteDeTiempo();

        $contrato = $this->contrato($request, $contratoId);

        // El que se archivó hoy sirve tal cual: el certificado solo cambia al
        // llegar la fecha de la cobertura —de "POR INICIAR" a activo— y eso
        // pasa a medianoche. Renovar ya lo baja y lo archiva, así que sin esto
        // el botón repetía un viaje al portal de unos 20 segundos justo cuando
        // más se pide, que es al terminar la renovación.
        $dehoy = DocumentoCliente::where('aliado_id', $contrato->aliado_id)
            ->where('cc_cliente', $contrato->cedula)
            ->where('tipo_documento', ArlAfiliacionService::DOC_SOPORTE)
            ->whereDate('created_at', now()->toDateString())
            ->latest('id')
            ->first();

        if ($dehoy && Storage::disk('local')->exists($dehoy->ruta)) {
            return Storage::disk('local')->download($dehoy->ruta, $dehoy->nombre_archivo);
        }

        if (! $contrato->razonSocial?->arl_poliza) {
            // No es un error del certificado: falta entrar al portal una vez.
            // La pantalla lo usa para pedir la clave en lugar de dar un aviso
            // seco que deja al usuario sin saber qué hacer.
            return response()->json([
                'ok'                  => false,
                'mensaje'             => 'Esta empresa todavía no tiene póliza ARL. Carga la clave del portal y se descubre sola.',
                'requiere_credencial' => true,
            ], 422);
        }

        try {
            $servicio  = ArlAfiliacionService::paraContrato($contrato);
            $cobertura = $servicio->coberturaEnSura($contrato);

            if (! $cobertura) {
                return response()->json([
                    'ok'      => false,
                    'mensaje' => 'Este trabajador no tiene ninguna cobertura activa en el portal de Sura, '
                                .'así que no hay certificado que descargar.',
                ], 422);
            }

            // El tipo lo dice el portal, no la modalidad del contrato: en
            // Gestión ARL la modalidad figura como independiente y los
            // trabajadores están afiliados como dependientes. Pedir el
            // certificado con el tipo equivocado devuelve «No se ha encontrado
            // información», que no ayuda a nadie a entender qué pasó.
            $tipoAfiliado = $cobertura['tipoAfiliado']
                ?: (new ArlSuraPayloadBuilder(new ArlSuraApiService(
                    (int) $contrato->aliado_id,
                    (string) $contrato->razonSocial->arl_poliza
                )))->tipoAfiliado($contrato);

            $docs = $servicio->archivarDocumentos($contrato, $tipoAfiliado, Auth::id());
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'mensaje' => $e->getMessage()], 422);
        }

        $soporte = $docs[ArlAfiliacionService::DOC_SOPORTE] ?? null;

        if (! $soporte) {
            return response()->json(['ok' => false, 'mensaje' => 'El portal no devolvió el certificado.'], 422);
        }

        Bitacora::registrar(
            'created',
            'Contrato',
            $contrato->id,
            'Certificado ARL Sura descargado del portal',
            ['documento_id' => $soporte->id],
            (int) $contrato->aliado_id
        );

        // Va al disco `local`: el certificado lleva cédula, cargo y salario.
        return Storage::disk('local')->download($soporte->ruta, $soporte->nombre_archivo);
    }

    /**
     * Lo que el modal de retiro necesita saber antes de preguntar nada.
     *
     * El periodo del plano no se elige: el retiro tiene que caer exactamente en
     * el mes consecutivo al último cotizado, y `ContratoController::retirar()`
     * lo rechaza si no coincide. Se calcula con el mismo criterio para que el
     * usuario no tenga que adivinarlo.
     */
    public function datosRetiro(Request $request, int $contratoId)
    {
        $contrato = $this->contrato($request, $contratoId);

        $ultimoPlano = DB::table('planos')
            ->where('contrato_id', $contrato->id)
            ->where('num_dias', '>', 0)
            ->whereNull('deleted_at')
            ->orderByDesc('anio_plano')
            ->orderByDesc('mes_plano')
            ->first();

        if ($ultimoPlano) {
            $mes  = (int) $ultimoPlano->mes_plano + 1;
            $anio = (int) $ultimoPlano->anio_plano;

            if ($mes > 12) {
                $mes = 1;
                $anio++;
            }
        } elseif ($contrato->fecha_ingreso) {
            $mes  = (int) $contrato->fecha_ingreso->month;
            $anio = (int) $contrato->fecha_ingreso->year;
        } else {
            $mes  = (int) now()->month;
            $anio = (int) now()->year;
        }

        $meses = [1 => 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
            'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];

        return response()->json([
            'mes_plano'   => $mes,
            'anio_plano'  => $anio,
            'periodo'     => ($meses[$mes] ?? '').' '.$anio,
            'fecha_arl'   => $contrato->fecha_arl?->format('d/m/Y'),
            'motivos'     => DB::table('motivos_retiro')->where('activo', true)
                ->orderBy('nombre')->get(['id', 'nombre']),
        ]);
    }

    /**
     * Cierra la cobertura del trabajador en el portal, sin tocar el contrato.
     *
     * Primero intenta anular, que es lo que corresponde cuando la afiliación
     * fue un error: borra la cobertura como si nunca hubiera existido. Sura
     * solo lo permite dentro de los 30 días del inicio.
     *
     * Si ya pasó ese plazo NO retira por su cuenta: devuelve `puede_retirar` y
     * espera a que alguien lo decida. Retirar deja constancia de que la persona
     * estuvo afiliada, que no es lo mismo, y esa diferencia la elige el usuario.
     */
    public function anularSura(Request $request, int $contratoId)
    {
        // Son trámites contra el portal, con su navegador y su login.
        $this->sinLimiteDeTiempo();

        $contrato = $this->contrato($request, $contratoId);
        $rs       = $contrato->razonSocial;

        $credencial = ArlSuraSesionService::credencialPara(
            (int) $contrato->aliado_id,
            (string) ($rs?->arl_poliza ?? ''),
            $rs?->nit
        );

        if (! $credencial || ! $rs?->arl_poliza) {
            return response()->json([
                'ok'                  => false,
                'mensaje'             => 'Esta empresa todavía no tiene cargada la clave del portal de Sura.',
                'requiere_credencial' => true,
            ], 422);
        }

        $servicio  = ArlAfiliacionService::paraContrato($contrato);
        $cobertura = $servicio->coberturaEnSura($contrato);

        if (! $cobertura) {
            return response()->json([
                'ok'      => true,
                'accion'  => 'ninguna',
                'mensaje' => 'En Sura no hay cobertura activa para este trabajador, así que no había nada que anular.',
            ]);
        }

        // Segunda pasada: el usuario ya vio que no se podía anular y aceptó
        // retirar en su lugar.
        if ($request->boolean('retirar')) {
            try {
                $retiro = $servicio->retirar($contrato, now(), Auth::id());
            } catch (Throwable $e) {
                return response()->json(['ok' => false, 'mensaje' => $e->getMessage()], 422);
            }

            Bitacora::registrar(
                'updated',
                'Contrato',
                $contrato->id,
                'Retiro en ARL Sura (la cobertura ya no se podía anular)',
                ['arl_afiliacion_id' => $retiro->id],
                (int) $contrato->aliado_id
            );

            return response()->json([
                'ok'      => true,
                'accion'  => 'retiro',
                'mensaje' => 'Se retiró al trabajador en ARL Sura.',
            ]);
        }

        try {
            $anulacion = $servicio->anular($contrato, Auth::id());
        } catch (Throwable $e) {
            // El plazo vencido no es un error del sistema: es la otra rama del
            // trámite, y quien decide es el usuario.
            $fueraDePlazo = str_contains($e->getMessage(), 'los 30 días');

            return response()->json([
                'ok'            => false,
                'mensaje'       => $e->getMessage(),
                'puede_retirar' => $fueraDePlazo,
            ], 422);
        }

        Bitacora::registrar(
            'deleted',
            'Contrato',
            $contrato->id,
            'Afiliación ARL Sura anulada desde el retiro de Gestión ARL',
            ['arl_afiliacion_id' => $anulacion->id],
            (int) $contrato->aliado_id
        );

        return response()->json([
            'ok'      => true,
            'accion'  => 'anulacion',
            'mensaje' => 'Cobertura anulada en ARL Sura.',
        ]);
    }

    /**
     * Contrato del aliado activo —y, para un usuario de BryNex, de cualquier
     * aliado suyo, que es como ve las afiliaciones que gestiona (ver
     * {@see Contrato::paraTramite()}).
     */
    private function contrato(Request $request, int $id): Contrato
    {
        // OJO: `cliente` se carga aparte, NO con `with()`.
        //
        // La relación filtra por `$this->aliado_id`, que en un eager load todavía
        // no existe; el filtro se cae y trae el cliente de otro aliado. Y hay
        // cédulas repetidas en varios aliados con datos distintos: la misma
        // persona aparece cinco veces con dos fechas de nacimiento diferentes.
        // Con carga perezosa la relación sí filtra bien.
        // `cliente` se deja en carga perezosa a propósito: `with()` y `load()`
        // rompen el filtro por aliado y traen la ficha de otra empresa.
        return Contrato::paraTramite($id)
            ->loadMissing(['razonSocial', 'eps', 'pension', 'tipoModalidad', 'aliado']);
    }

    /**
     * Amplía el tiempo de ejecución para las operaciones que levantan un
     * navegador. Sin esto la petición muere con "Maximum execution time of 30
     * seconds exceeded" a mitad del trámite, cuando en el portal ya puede haber
     * quedado hecho.
     *
     * Ojo en producción: el servidor web tiene su propio timeout y también hay
     * que darle margen.
     */
    private function sinLimiteDeTiempo(): void
    {
        @set_time_limit(300);
        @ini_set('max_execution_time', '300');
    }

    private function etiquetaTipo(string $tipo): string
    {
        return match ($tipo) {
            'I'     => 'Independiente (I)',
            'E'     => 'Estudiante (E)',
            default => 'Dependiente (D)',
        };
    }
}
