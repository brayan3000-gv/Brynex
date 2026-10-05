<?php

use App\Http\Controllers\AlidoSelectorController;
use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

// ─── Dominio propio del aliado (Fase 6) — ej. brygar.com sirviendo directamente su página ──
// DEBE ir antes que cualquier ruta "/" sin restricción de dominio (como el login de abajo):
// Laravel resuelve rutas en orden de registro, y una ruta "/" sin Route::domain() responde
// en CUALQUIER host — si este bloque quedara después, el login siempre le ganaría en brygar.com.
// Se registra dinámicamente por cada aliado con `dominio_propio` configurado. Solo hace falta
// mapear la RAÍZ ("/"): las rutas /aliado/{slug}/cotizar, /lead y /metrica no tienen
// Route::domain(), así que Laravel ya las resuelve sobre el host que llegó en la petición —
// no hace falta duplicarlas por dominio. Requiere que el DNS del dominio apunte a este
// servidor — ver docs/plan-pagina-publica-aliado.md, Fase 6, para los pasos exactos.
foreach (\App\Models\Aliado::whereNotNull('dominio_propio')->where('activo', true)->get(['slug', 'dominio_propio']) as $aliadoConDominio) {
    $dominioBase = strtolower(preg_replace('/^www\./', '', $aliadoConDominio->dominio_propio));

    foreach ([$dominioBase, 'www.'.$dominioBase] as $dominio) {
        Route::domain($dominio)
            ->get('/', [\App\Http\Controllers\Publico\PaginaAliadoController::class, 'show'])
            ->defaults('slug', $aliadoConDominio->slug)
            ->name("dominio.{$dominio}.show");

        Route::domain($dominio)
            ->get('/politica-privacidad', [\App\Http\Controllers\Publico\PaginaAliadoController::class, 'politicaPrivacidad'])
            ->defaults('slug', $aliadoConDominio->slug);
        Route::domain($dominio)
            ->get('/terminos-servicio', [\App\Http\Controllers\Publico\PaginaAliadoController::class, 'terminosServicio'])
            ->defaults('slug', $aliadoConDominio->slug);
        Route::domain($dominio)
            ->get('/eliminacion-datos', [\App\Http\Controllers\Publico\PaginaAliadoController::class, 'eliminacionDatos'])
            ->defaults('slug', $aliadoConDominio->slug);
    }
}

// ─── Rutas públicas ────────────────────────────────────────────────────────
Route::get('/', [LoginController::class, 'showLogin'])->name('login');
Route::get('/login', [LoginController::class, 'showLogin']);
// Throttle obligatorio: el identificador de acceso es la cédula, un dato
// semi-público y de formato predecible. Sin límite, la fuerza bruta es trivial.
Route::post('/login', [LoginController::class, 'login'])->name('login.submit')
    ->middleware('throttle:5,1');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

// ─── Portal de empresas (brynex.co/portal) ─────────────────────────────────
// Guard aparte (`empresa`): quien entra aquí nunca está autenticado en `web`,
// así que ninguna ruta de /admin lo deja pasar. Solo lectura, salvo su clave.
// auth.session: si el equipo le restablece la clave, las sesiones abiertas con
// la anterior se cierran en la siguiente petición.
Route::prefix('portal')->name('portal.')->middleware(['auth:empresa', 'auth.session', 'portal.empresa'])->group(function () {
    $pe = \App\Http\Controllers\Portal\PortalEmpresaController::class;
    Route::get('/', [$pe, 'inicio'])->name('inicio');
    Route::get('/planilla/{plano}', [$pe, 'planilla'])->whereNumber('plano')->name('planilla')
        ->middleware('throttle:30,1');
    Route::get('/facturas', [$pe, 'facturas'])->name('facturas');
    Route::get('/incapacidades', [$pe, 'incapacidades'])->name('incapacidades');
    Route::get('/retirados', [$pe, 'retirados'])->name('retirados');
    Route::get('/clave', [$pe, 'clave'])->name('clave');
    Route::post('/clave', [$pe, 'guardarClave'])->name('clave.guardar')->middleware('throttle:10,1');
    Route::post('/salir', [$pe, 'salir'])->name('salir');

    // Trámites: lo que la empresa pide. Nada se ejecuta solo, abre una tarea.
    $pt = \App\Http\Controllers\Portal\PortalTramitesController::class;
    Route::get('/tramites', [$pt, 'index'])->name('tramites');
    Route::get('/tramites/nuevo', [$pt, 'nuevo'])->name('tramites.nuevo');
    Route::post('/tramites/{tipo}', [$pt, 'guardar'])->whereIn('tipo', ['ingreso', 'retiro', 'incapacidad', 'otra'])
        ->name('tramites.guardar')->middleware('throttle:20,1');
    Route::get('/tramites/{solicitud}/archivo/{i}', [$pt, 'archivo'])->whereNumber(['solicitud', 'i'])
        ->name('tramites.archivo');
});

// ─── Ruta pública: subida de documentos por token (cliente) ───────────────
// No requiere auth — solo verificación de cédula dentro del controller.
// Throttle: la verificación es por cédula (dato de bajo secreto), así que sin
// límite se puede adivinar por fuerza bruta sobre un token conocido.
Route::get('/incapacidades/subir/{token}', [\App\Http\Controllers\IncapacidadUploadController::class, 'show'])->name('incapacidades.subir');
Route::post('/incapacidades/subir/{token}', [\App\Http\Controllers\IncapacidadUploadController::class, 'upload'])->name('incapacidades.subir.post')
    ->middleware('throttle:10,1');

// ─── Página web pública de aliado (brynex.co/aliado/{slug}) ────────────────
// No requiere auth — visibilidad controlada por aliados.activo + pagina_aliado_config.activo
// dentro del controller (404 si el aliado no existe o la página no está activa).
Route::get('/aliado/{slug}', [\App\Http\Controllers\Publico\PaginaAliadoController::class, 'show'])->name('publico.aliado');

// Vista previa firmada: permite ver la página aunque esté inactiva (usada desde el CMS admin).
Route::get('/aliado/{slug}/preview', [\App\Http\Controllers\Publico\PaginaAliadoController::class, 'preview'])
    ->name('publico.aliado.preview')
    ->middleware('signed');

// Páginas legales (requeridas por Meta for Developers para publicar la app de Facebook/Instagram).
Route::get('/aliado/{slug}/politica-privacidad', [\App\Http\Controllers\Publico\PaginaAliadoController::class, 'politicaPrivacidad'])->name('publico.aliado.privacidad');
Route::get('/aliado/{slug}/terminos-servicio', [\App\Http\Controllers\Publico\PaginaAliadoController::class, 'terminosServicio'])->name('publico.aliado.terminos');
Route::get('/aliado/{slug}/eliminacion-datos', [\App\Http\Controllers\Publico\PaginaAliadoController::class, 'eliminacionDatos'])->name('publico.aliado.eliminacion_datos');

// Cotizador "Arma tu plan" y captura de leads — throttle por IP, sin autenticación.
Route::post('/aliado/{slug}/cotizar', [\App\Http\Controllers\Publico\PaginaAliadoController::class, 'cotizar'])
    ->name('publico.aliado.cotizar')
    ->middleware('throttle:30,1');
Route::post('/aliado/{slug}/lead', [\App\Http\Controllers\Publico\PaginaAliadoController::class, 'lead'])
    ->name('publico.aliado.lead')
    ->middleware('throttle:10,1');

// Beacon de analítica propia (clic en WhatsApp) — throttle generoso, es solo un contador.
Route::post('/aliado/{slug}/metrica', [\App\Http\Controllers\Publico\PaginaAliadoController::class, 'registrarMetrica'])
    ->name('publico.aliado.metrica')
    ->middleware('throttle:60,1');

Route::get('/sitemap.xml', [\App\Http\Controllers\Publico\PaginaAliadoController::class, 'sitemap'])->name('publico.sitemap');

// Planes de alianza de Brygar con cotizador (brynex.co/aliados). Los precios son los del
// volante «Propuesta de Alianza Estratégica»; si cambian allá, cambian en la vista.
Route::get('/aliados', function () {
    return view('publico.aliados-planes', [
        'urlWhatsappBase' => 'https://wa.me/573001563615',
        'urlWhatsapp' => 'https://wa.me/573001563615?text='.rawurlencode('Hola, quiero saber más de las alianzas de Brygar.'),
    ]);
})->name('publico.aliados.planes');

// Presentación pública del servicio de afiliaciones para aliados (brynex.co/aliados/afiliaciones).
// La vende Brygar: el WhatsApp es su línea comercial (la del volante de alianzas);
// las imágenes salen de scripts/imagenes-aliados.sh.
Route::get('/aliados/afiliaciones', function () {
    $texto = rawurlencode('Hola, quiero saber más de las afiliaciones automáticas para aliados.');

    return view('publico.aliados-afiliaciones', [
        'urlWhatsapp' => "https://wa.me/573001563615?text={$texto}",
    ]);
})->name('publico.aliados.afiliaciones');

// Link corto que redirige al wa.me rastreado. YA NO se usa al publicar (ver
// PublicacionPublisher::linkWhatsappRastreado: se volvió al wa.me directo porque Meta exime
// del castigo de alcance a los enlaces hacia sus propias tecnologías, y un dominio propio no).
// La ruta se conserva porque los comentarios ya publicados en Facebook/Instagram apuntan
// aquí: borrarla los dejaría en 404.
Route::get('/wa/{publicacion}', [\App\Http\Controllers\Publico\WhatsappRedirectController::class, 'redirigir'])->name('publico.wa');

// ─── Webhook público WhatsApp (Meta Cloud API) ─────────────────────────────
// No requiere auth — Meta llama directamente. Seguridad via HMAC en el controller.
Route::get('/whatsapp/webhook', [\App\Http\Controllers\WhatsappWebhookController::class, 'verify'])->name('whatsapp.webhook.verify');
Route::post('/whatsapp/webhook', [\App\Http\Controllers\WhatsappWebhookController::class, 'receive'])->name('whatsapp.webhook.receive');

// ─── GARVIS: fotos que Brayan le manda por WhatsApp ─────────────────────────
// Sin auth: las baja el workflow de GARVIS en GitHub. Las protege el enlace firmado,
// que vence en pocas horas (ver GarvisFotoJob).
Route::get('/garvis/foto/{archivo}', \App\Http\Controllers\GarvisFotoController::class)
    ->middleware('signed:relative')
    ->name('garvis.foto');

// ─── CSRF token fresco (puede llamarse sin auth, pero solo desde session activa) ──
// El JS lo usa para renovar el token antes de peticiones PATCH/POST críticas.
Route::get('/csrf-token', function () {
    return response()->json([
        'token' => csrf_token(),
    ]);
})->name('csrf.token')->middleware('web');

// ─── Rutas protegidas ──────────────────────────────────────────────────────
Route::middleware('auth')->group(function () {

    // Aviso de tratamiento de datos (Ley 1581). ExigirAvisoTratamiento manda
    // aquí a quien no lo haya aceptado, y deja pasar estas dos rutas para no
    // dejarlo dando vueltas en un bucle de redirecciones.
    Route::get('/tratamiento-datos', [\App\Http\Controllers\AvisoTratamientoController::class, 'mostrar'])
        ->name('tratamiento.mostrar');
    Route::post('/tratamiento-datos', [\App\Http\Controllers\AvisoTratamientoController::class, 'aceptar'])
        ->name('tratamiento.aceptar');

    // Selector de aliado (solo usuarios BryNex)
    Route::get('/seleccionar-aliado', [AlidoSelectorController::class, 'index'])->name('aliado.selector');
    Route::post('/seleccionar-aliado', [AlidoSelectorController::class, 'seleccionar'])->name('aliado.seleccionar');
    Route::post('/cambiar-aliado', [AlidoSelectorController::class, 'cambiar'])->name('aliado.cambiar');

    // Dashboard principal
    Route::get('/dashboard', function () {
        return view('dashboard');
    })->name('dashboard');

    // ─── Asistente Virtual IA ───────────────────────────────────────
    Route::prefix('asistente-ia')->name('asistente_ia.')->middleware('permiso:asistente_ia.usar')->group(function () {
        $ia = \App\Http\Controllers\AsistenteIaController::class;
        Route::get('/activo', [$ia, 'activo'])->name('activo');
        Route::post('/chat', [$ia, 'chat'])->name('chat');
    });

    // ─── Panel Administración ──────────────────────────────────────
    Route::prefix('admin')->name('admin.')->group(function () {

        // Aliados (solo BryNex — el módulo `aliados` es solo_brynex)
        Route::middleware('permiso:aliados.ver')->group(function () {
            Route::get('aliados', [\App\Http\Controllers\Admin\AlidoController::class, 'index'])->name('aliados.index');
        });
        Route::middleware('permiso:aliados.gestionar')->group(function () {
            Route::resource('aliados', \App\Http\Controllers\Admin\AlidoController::class)
                ->except(['show', 'index']);
            Route::patch('aliados/{id}/restore', [\App\Http\Controllers\Admin\AlidoController::class, 'restore'])
                ->name('aliados.restore');
        });

        // Usuarios — ver: admin | crear/editar y permisos: solo superadmin
        Route::middleware('permiso:usuarios.ver')->group(function () {
            Route::get('usuarios', [\App\Http\Controllers\Admin\UsuarioController::class, 'index'])->name('usuarios.index');
        });
        Route::middleware('permiso:usuarios.gestionar')->group(function () {
            Route::resource('usuarios', \App\Http\Controllers\Admin\UsuarioController::class)
                ->except(['show', 'index']);
            Route::patch('usuarios/{id}/restore', [\App\Http\Controllers\Admin\UsuarioController::class, 'restore'])
                ->name('usuarios.restore');
        });
        // Permisos por usuario (pantalla de módulos) — solo superadmin
        Route::middleware('permiso:usuarios.permisos')->group(function () {
            Route::get('usuarios/{usuario}/permisos', [\App\Http\Controllers\Admin\UsuarioPermisoController::class, 'edit'])->name('usuarios.permisos.edit');
            Route::post('usuarios/{usuario}/permisos', [\App\Http\Controllers\Admin\UsuarioPermisoController::class, 'update'])->name('usuarios.permisos.update');
        });

        // Niveles de asesor (plantillas de comisión) — viven en el hub de Configuración.
        // Van fuera de 'asesores/...' para no chocar con Route::resource('asesores').
        // La autorización fina (ver vs gestionar) la aplica el propio controlador.
        Route::prefix('configuracion/niveles-asesor')->name('configuracion.niveles.')->group(function () {
            $nc = \App\Http\Controllers\Admin\AsesorNivelController::class;
            Route::get('/', [$nc, 'index'])->name('index');
            Route::post('/', [$nc, 'store'])->name('store');
            Route::put('{id}', [$nc, 'update'])->whereNumber('id')->name('update');
            Route::delete('{id}', [$nc, 'destroy'])->whereNumber('id')->name('destroy');
            Route::post('{id}/duplicar', [$nc, 'duplicar'])->whereNumber('id')->name('duplicar');
            Route::get('{id}/matriz', [$nc, 'matriz'])->whereNumber('id')->name('matriz');
            Route::post('{id}/matriz', [$nc, 'guardarMatriz'])->whereNumber('id')->name('matriz.guardar');
        });

        // Asesores — ver: admin/contable/usuario | gestionar y comisiones: admin
        Route::middleware('permiso:asesores.ver')->group(function () {
            Route::get('asesores/reporte-mensual', [\App\Http\Controllers\Admin\AsesorController::class, 'reporteMensual'])
                ->name('asesores.reporte_mensual');
            Route::get('asesores/como-van', [\App\Http\Controllers\Admin\AsesorController::class, 'comoVan'])
                ->name('asesores.como_van');
            Route::get('asesores', [\App\Http\Controllers\Admin\AsesorController::class, 'index'])->name('asesores.index');
            // Antes de asesores/{asesor} no hace falta cuidarse: show tiene whereNumber.
            Route::get('asesores/{asesor}/tarifas', [\App\Http\Controllers\Admin\AsesorController::class, 'tarifas'])
                ->whereNumber('asesor')->name('asesores.tarifas');
            Route::get('asesores/{asesor}', [\App\Http\Controllers\Admin\AsesorController::class, 'show'])
                ->whereNumber('asesor')
                ->name('asesores.show');
        });
        Route::middleware('permiso:asesores.gestionar')->group(function () {
            Route::resource('asesores', \App\Http\Controllers\Admin\AsesorController::class)
                ->parameters(['asesores' => 'asesor'])
                ->except(['index', 'show']);
            Route::patch('asesores/{id}/restore', [\App\Http\Controllers\Admin\AsesorController::class, 'restore'])
                ->name('asesores.restore');
            // Tarifario del asesor: aplicar un nivel, editar su matriz propia e imprimir.
            Route::post('asesores/{asesor}/nivel', [\App\Http\Controllers\Admin\AsesorController::class, 'aplicarNivel'])
                ->whereNumber('asesor')->name('asesores.nivel.aplicar');
            Route::post('asesores/{asesor}/tarifas', [\App\Http\Controllers\Admin\AsesorController::class, 'guardarTarifas'])
                ->whereNumber('asesor')->name('asesores.tarifas.guardar');
            Route::get('asesores/{asesor}/tarifario-pdf', [\App\Http\Controllers\Admin\AsesorController::class, 'tarifarioPdf'])
                ->whereNumber('asesor')->name('asesores.tarifario_pdf');
            Route::post('asesores/{asesor}/comisiones', [\App\Http\Controllers\Admin\AsesorController::class, 'registrarComision'])
                ->name('asesores.comisiones.store');
            Route::patch('comisiones/{comision}/pagar', [\App\Http\Controllers\Admin\AsesorController::class, 'marcarPagada'])
                ->name('asesores.comisiones.pagar');
        });

        // Cotizaciones y Prospectos
        Route::middleware('permiso:cotizaciones.gestionar')->group(function () {
            Route::post('cotizaciones/{id}/gestion', [\App\Http\Controllers\Admin\CotizacionController::class, 'registrarGestion'])->name('cotizaciones.gestion');
            Route::post('cotizaciones/{id}/cotizar', [\App\Http\Controllers\Admin\CotizacionController::class, 'cotizar'])->name('cotizaciones.cotizar');
            Route::post('cotizaciones/{id}/convertir', [\App\Http\Controllers\Admin\CotizacionController::class, 'convertirACliente'])->name('cotizaciones.convertir');
            Route::post('cotizaciones/{id}/whatsapp', [\App\Http\Controllers\Admin\CotizacionController::class, 'enviarWhatsapp'])->name('cotizaciones.whatsapp');
            Route::post('cotizaciones/{id}/estado', [\App\Http\Controllers\Admin\CotizacionController::class, 'cambiarEstado'])->name('cotizaciones.estado');
        });
        Route::middleware(['permiso:cotizaciones.ver', 'permiso.escritura:cotizaciones.gestionar'])->group(function () {
            Route::get('cotizaciones/{id}/pdf', [\App\Http\Controllers\Admin\CotizacionController::class, 'descargarPdf'])->name('cotizaciones.pdf');
            Route::resource('cotizaciones', \App\Http\Controllers\Admin\CotizacionController::class);
        });

        // Clientes
        Route::middleware('permiso:clientes.ver')->group(function () {
            Route::get('clientes/buscar-cedula', [\App\Http\Controllers\Admin\ClienteController::class, 'buscarPorCedula'])
                ->name('clientes.buscar_cedula');
            // Ficha por cédula (la usa el modal reutilizable de cliente)
            Route::get('clientes/ficha/{cedula}', [\App\Http\Controllers\Admin\ClienteController::class, 'fichaPorCedula'])
                ->name('clientes.ficha_cedula');
            Route::get('clientes', [\App\Http\Controllers\Admin\ClienteController::class, 'index'])->name('clientes.index');
        });
        Route::middleware('permiso:clientes.crear')->group(function () {
            Route::get('clientes/create', [\App\Http\Controllers\Admin\ClienteController::class, 'create'])->name('clientes.create');
            Route::post('clientes', [\App\Http\Controllers\Admin\ClienteController::class, 'store'])->name('clientes.store');
        });
        Route::middleware('permiso:clientes.editar')->group(function () {
            Route::get('clientes/{cliente}/edit', [\App\Http\Controllers\Admin\ClienteController::class, 'edit'])->name('clientes.edit');
            Route::put('clientes/{cliente}', [\App\Http\Controllers\Admin\ClienteController::class, 'update'])->name('clientes.update');
            Route::patch('clientes/{cliente}', [\App\Http\Controllers\Admin\ClienteController::class, 'update']);
            Route::get('clientes/{cliente}/cedula/impacto', [\App\Http\Controllers\Admin\ClienteController::class, 'impactoCedula'])->name('clientes.cedula.impacto');
            Route::post('clientes/{cliente}/cedula/corregir', [\App\Http\Controllers\Admin\ClienteController::class, 'corregirCedula'])->name('clientes.cedula.corregir');
        });

        // Beneficiarios
        Route::get('clientes/{cedula}/beneficiarios', [\App\Http\Controllers\Admin\BeneficiarioController::class, 'index'])->name('clientes.beneficiarios.index')->middleware('permiso:beneficiarios.ver');
        Route::post('clientes/{cedula}/beneficiarios', [\App\Http\Controllers\Admin\BeneficiarioController::class, 'store'])->name('clientes.beneficiarios.store')->middleware('permiso:beneficiarios.gestionar');
        Route::put('beneficiarios/{id}', [\App\Http\Controllers\Admin\BeneficiarioController::class, 'update'])->name('beneficiarios.update')->middleware('permiso:beneficiarios.gestionar');
        Route::delete('beneficiarios/{id}', [\App\Http\Controllers\Admin\BeneficiarioController::class, 'destroy'])->name('beneficiarios.destroy')->middleware('permiso:beneficiarios.eliminar');

        // Documentos del cliente
        Route::get('clientes/{cedula}/documentos', [\App\Http\Controllers\Admin\DocumentoClienteController::class, 'index'])->name('clientes.documentos.index')->middleware('permiso:documentos.ver');
        Route::post('clientes/{cedula}/documentos', [\App\Http\Controllers\Admin\DocumentoClienteController::class, 'store'])->name('clientes.documentos.store')->middleware('permiso:documentos.subir');
        Route::get('documentos/{id}/descargar', [\App\Http\Controllers\Admin\DocumentoClienteController::class, 'download'])->name('documentos.download')->middleware('permiso:documentos.descargar');
        Route::delete('documentos/{id}', [\App\Http\Controllers\Admin\DocumentoClienteController::class, 'destroy'])->name('documentos.destroy')->middleware('permiso:documentos.eliminar');

        // Claves de acceso (cliente y razón social)
        // OJO: `claves_acceso.ver` muestra el listado; la CONTRASEÑA en claro
        // la tapa el permiso restringido `claves_acceso.ver_contrasena`, que se
        // otorga usuario por usuario (ver ClaveAccesoController).
        $cac = \App\Http\Controllers\Admin\ClaveAccesoController::class;
        Route::middleware('permiso:claves_acceso.ver')->group(function () use ($cac) {
            Route::get('clave-accesos/global', [$cac, 'vistaGlobal'])->name('clave_accesos.global');
            Route::get('clave-accesos', [$cac, 'index'])->name('clave_accesos.index');
            Route::get('clave-accesos/razon-social/{id}', [$cac, 'indexRazonSocial'])->name('clave_accesos.razon_social');
            Route::get('clave-accesos/empresa/{id}', [$cac, 'indexEmpresa'])->name('clave_accesos.empresa');
            // Quién cambió una clave y qué había antes: importa desde que la
            // misma clave la pueden tocar varios aliados de la misma empresa.
            Route::get('clave-accesos/{id}/historial', [$cac, 'historial'])->name('clave_accesos.historial');
            // Portales de entidades de una razón social (pestaña y panel 🔑 de Afiliaciones)
            Route::get('clave-accesos/portales/{id}', [\App\Http\Controllers\Admin\PortalEntidadController::class, 'index'])->whereNumber('id')->name('clave_accesos.portales');
        });
        Route::middleware('permiso:claves_acceso.gestionar')->group(function () use ($cac) {
            Route::post('clave-accesos', [$cac, 'store'])->name('clave_accesos.store');
            Route::put('clave-accesos/{id}', [$cac, 'update'])->name('clave_accesos.update');
            Route::post('clave-accesos/portales/{id}', [\App\Http\Controllers\Admin\PortalEntidadController::class, 'store'])->whereNumber('id')->name('clave_accesos.portales.store');
            Route::post('clave-accesos/portales/{id}/otra', [\App\Http\Controllers\Admin\PortalEntidadController::class, 'otra'])->whereNumber('id')->name('clave_accesos.portales.otra');
            Route::post('clave-accesos/portales/{id}/asignar', [\App\Http\Controllers\Admin\PortalEntidadController::class, 'asignar'])->whereNumber('id')->name('clave_accesos.portales.asignar');
        });
        Route::delete('clave-accesos/{id}', [$cac, 'destroy'])->name('clave_accesos.destroy')->middleware('permiso:claves_acceso.eliminar');

        // Bitácora (solo superadmin)
        Route::get('bitacora', [\App\Http\Controllers\Admin\BitacoraController::class, 'index'])->name('bitacora.index')->middleware('permiso:bitacora.ver');

        // Contratos
        Route::middleware('permiso:contratos.ver')->group(function () {
            Route::get('contratos', [\App\Http\Controllers\Admin\ContratoController::class, 'index'])->name('contratos.index');
            // APIs reactivas del cotizador (solo calculan, no escriben)
            Route::get('contratos/api/calcular-retiro/{contrato}', [\App\Http\Controllers\Admin\ContratoController::class, 'apiCalcularRetiro'])->name('contratos.calcular_retiro');
            Route::post('contratos/api/cotizar', [\App\Http\Controllers\Admin\ContratoController::class, 'cotizar'])->name('contratos.cotizar');
            Route::get('contratos/api/tarifas', [\App\Http\Controllers\Admin\ContratoController::class, 'tarifasPorPlan'])->name('contratos.tarifas');
        });
        Route::middleware('permiso:contratos.crear')->group(function () {
            Route::get('contratos/create', [\App\Http\Controllers\Admin\ContratoController::class, 'create'])->name('contratos.create');
            Route::post('contratos', [\App\Http\Controllers\Admin\ContratoController::class, 'store'])->name('contratos.store');
            Route::post('contratos/{contrato}/duplicar-ir', [\App\Http\Controllers\Admin\ContratoController::class, 'duplicarIngresoRetiro'])->name('contratos.duplicar-ir');
        });
        Route::middleware('permiso:contratos.editar')->group(function () {
            // El bloqueo fino "no editar un contrato YA RADICADO" (permiso
            // contratos.editar_radicado, solo superadmin) va dentro del
            // controlador: depende del estado del registro, no de la URL.
            Route::get('contratos/{contrato}/edit', [\App\Http\Controllers\Admin\ContratoController::class, 'edit'])->name('contratos.edit');
            Route::put('contratos/{contrato}', [\App\Http\Controllers\Admin\ContratoController::class, 'update'])->name('contratos.update');
            Route::patch('contratos/{contrato}', [\App\Http\Controllers\Admin\ContratoController::class, 'update']);
            Route::patch('contratos/api/radicado/{id}', [\App\Http\Controllers\Admin\ContratoController::class, 'actualizarRadicado'])->name('contratos.radicado.update');
        });
        Route::patch('contratos/{contrato}/retirar', [\App\Http\Controllers\Admin\ContratoController::class, 'retirar'])->name('contratos.retirar')->middleware('permiso:contratos.retirar');

        // Configuración del aliado (tarifas, admon, ARL)
        // ── Configuración del aliado ──────────────────────────────────────
        // ver: admin (solo lectura) · editar: solo superadmin
        // El hub se abre con cualquiera de los permisos que viven en él (Gate
        // `entrar-configuracion`) y solo pinta las tarjetas que el usuario puede abrir.
        Route::get('configuracion', [\App\Http\Controllers\Admin\ConfiguracionAliadoController::class, 'hub'])
            ->name('configuracion.hub')->middleware('permiso:entrar-configuracion');
        Route::middleware('permiso:configuracion.ver')->group(function () {
            Route::get('configuracion/parametros', [\App\Http\Controllers\Admin\ConfiguracionAliadoController::class, 'index'])->name('configuracion.index');
        });
        Route::post('configuracion/parametros', [\App\Http\Controllers\Admin\ConfiguracionAliadoController::class, 'store'])->name('configuracion.store')->middleware('permiso:configuracion.editar');

        // Sugerir y aplicar precios de afiliación de los planes sin AFP. Solo superadmin:
        // reescribe la lista de precios completa del aliado.
        Route::get('configuracion/precios-sugeridos', [\App\Http\Controllers\Admin\ConfiguracionAliadoController::class, 'preciosSugeridos'])
            ->name('configuracion.precios_sugeridos')->middleware('role:superadmin');
        Route::post('configuracion/precios-sugeridos', [\App\Http\Controllers\Admin\ConfiguracionAliadoController::class, 'aplicarPreciosSugeridos'])
            ->name('configuracion.precios_sugeridos.aplicar')->middleware('role:superadmin');

        // Recalcular los retiros con el salario mínimo del año. Solo sube los que quedaron cortos.
        Route::get('configuracion/retiros-sugeridos', [\App\Http\Controllers\Admin\ConfiguracionAliadoController::class, 'retirosSugeridos'])
            ->name('configuracion.retiros_sugeridos')->middleware('role:superadmin');
        Route::post('configuracion/retiros-sugeridos', [\App\Http\Controllers\Admin\ConfiguracionAliadoController::class, 'aplicarRetirosSugeridos'])
            ->name('configuracion.retiros_sugeridos.aplicar')->middleware('role:superadmin');

        // ── Cuentas bancarias ─────────────────────────────────────────────
        // El rol `usuario` puede VER las cuentas y CREAR una de incapacidad
        // (el controlador fuerza facturacion_incapacidad=1 si solo tiene ese
        // permiso). Editar e inactivar siguen siendo de admin.
        Route::middleware('permiso:cuentas_bancarias.ver')->group(function () {
            Route::get('configuracion/cuentas', [\App\Http\Controllers\Admin\ConfiguracionAliadoController::class, 'cuentas'])->name('configuracion.cuentas');
            Route::get('configuracion/cuentas/{id}/estado-registros', [\App\Http\Controllers\Admin\ConfiguracionAliadoController::class, 'estadoCuentaContratos'])->name('configuracion.cuentas.estado_registros');
        });
        Route::post('configuracion/cuentas', [\App\Http\Controllers\Admin\ConfiguracionAliadoController::class, 'storeCuenta'])->name('configuracion.cuentas.store')
            ->middleware('permiso:cuentas_bancarias.crear_incapacidad|cuentas_bancarias.gestionar');
        Route::middleware('permiso:cuentas_bancarias.gestionar')->group(function () {
            Route::patch('configuracion/cuentas/{id}', [\App\Http\Controllers\Admin\ConfiguracionAliadoController::class, 'updateCuenta'])->name('configuracion.cuentas.update');
            Route::delete('configuracion/cuentas/{id}', [\App\Http\Controllers\Admin\ConfiguracionAliadoController::class, 'destroyCuenta'])->name('configuracion.cuentas.destroy');
            Route::patch('configuracion/cuentas/{id}/inactivar', [\App\Http\Controllers\Admin\ConfiguracionAliadoController::class, 'inactivarCuenta'])->name('configuracion.cuentas.inactivar');
        });

        // Catálogo de seguros del aliado (plan exequial, mascotas, vida…)
        $cs = \App\Http\Controllers\Admin\ConfiguracionAliadoController::class;
        Route::get('configuracion/seguros', [$cs, 'seguros'])->name('configuracion.seguros')
            ->middleware('permiso:configuracion.ver');
        Route::middleware('permiso:configuracion.editar')->group(function () use ($cs) {
            Route::post('configuracion/seguros', [$cs, 'storeSeguro'])->name('configuracion.seguros.store');
            Route::patch('configuracion/seguros/{id}', [$cs, 'updateSeguro'])->name('configuracion.seguros.update');
            Route::delete('configuracion/seguros/{id}', [$cs, 'destroySeguro'])->name('configuracion.seguros.destroy');
        });

        // Configuración de modalidades → planes (solo superadmin)
        $mc = \App\Http\Controllers\Admin\ModalidadConfigController::class;
        Route::get('configuracion/modalidades', [$mc, 'index'])->name('configuracion.modalidades')->middleware('permiso:configuracion.ver');
        Route::middleware('permiso:configuracion.editar')->group(function () use ($mc) {
            Route::post('configuracion/modalidades', [$mc, 'guardar'])->name('configuracion.modalidades.guardar');
            Route::patch('configuracion/modalidades/{id}/toggle', [$mc, 'toggleActivo'])->name('configuracion.modalidades.toggle');
        });

        // Configuración de operadores de planilla SS por aliado
        $opc = \App\Http\Controllers\Admin\OperadorPlanillaConfigController::class;
        Route::get('configuracion/operadores-planilla', [$opc, 'index'])->name('configuracion.operadores.index')->middleware('permiso:operadores_planilla.ver');
        Route::middleware('permiso:operadores_planilla.configurar')->group(function () use ($opc) {
            Route::patch('configuracion/operadores-planilla/{id}/toggle', [$opc, 'toggle'])->name('configuracion.operadores.toggle');
            Route::post('configuracion/operadores-planilla/orden', [$opc, 'guardarOrden'])->name('configuracion.operadores.orden');
        });

        // Credenciales de las APIs de operadores — permiso RESTRINGIDO:
        // con esto se liquida y se paga plata, no lo hereda ningún rol.
        $opcr = \App\Http\Controllers\Admin\OperadorCredencialController::class;
        Route::middleware('permiso:operadores_planilla.credenciales')->group(function () use ($opcr) {
            Route::post('configuracion/operadores-planilla/{operador}/credenciales', [$opcr, 'storeAliado'])->name('configuracion.operadores.credenciales.store');
            Route::post('configuracion/operadores-planilla/{operador}/credenciales/probar', [$opcr, 'probarAliado'])->name('configuracion.operadores.credenciales.probar');
            Route::delete('configuracion/operadores-planilla/{operador}/credenciales', [$opcr, 'destroyAliado'])->name('configuracion.operadores.credenciales.destroy');
        });

        // Catálogo de cargos por razón social: alimenta el selector del contrato,
        // y de ahí sale el nivel de riesgo con el que se elige el centro de ARL.
        $rsc = \App\Http\Controllers\Admin\RazonSocialCargoController::class;
        Route::get('razones-sociales/{razonSocial}/cargos', [$rsc, 'index'])->name('razones.cargos.index');
        Route::post('razones-sociales/{razonSocial}/cargos', [$rsc, 'store'])->name('razones.cargos.store');

        // Credenciales del portal de ARL Sura — mismo permiso restringido que las
        // de operadores: con esto se afilia gente a una ARL, no lo hereda un rol.
        $arlc = \App\Http\Controllers\Admin\ArlSuraCredencialController::class;
        Route::middleware('permiso:operadores_planilla.credenciales')->group(function () use ($arlc) {
            Route::get('configuracion/arl-sura', [$arlc, 'index'])->name('configuracion.arl-sura.index');
            Route::post('configuracion/arl-sura', [$arlc, 'store'])->name('configuracion.arl-sura.store');
            Route::post('configuracion/arl-sura/probar', [$arlc, 'probar'])->name('configuracion.arl-sura.probar');
            Route::delete('configuracion/arl-sura', [$arlc, 'destroy'])->name('configuracion.arl-sura.destroy');
        });

        // CRUD de Razones Sociales (empresas de afiliación) por aliado
        // ver: admin/contable/usuario · gestionar: admin · eliminar: superadmin
        $rsc = \App\Http\Controllers\Admin\RazonSocialController::class;
        Route::middleware('permiso:razones_sociales.ver')->group(function () use ($rsc) {
            Route::get('configuracion/razones-sociales', [$rsc, 'index'])->name('configuracion.razones.index');
            Route::get('configuracion/razones-sociales/{id}/estado-contratos', [$rsc, 'estadoContratos'])->name('configuracion.razones.estado_contratos');
        });
        Route::middleware('permiso:razones_sociales.gestionar')->group(function () use ($rsc) {
            Route::get('configuracion/razones-sociales/crear', [$rsc, 'create'])->name('configuracion.razones.create');
            Route::post('configuracion/razones-sociales', [$rsc, 'store'])->name('configuracion.razones.store');
            Route::get('configuracion/razones-sociales/{id}/editar', [$rsc, 'edit'])->name('configuracion.razones.edit');
            Route::put('configuracion/razones-sociales/{id}', [$rsc, 'update'])->name('configuracion.razones.update');
            Route::patch('configuracion/razones-sociales/{id}/estado', [$rsc, 'toggleEstado'])->name('configuracion.razones.estado');
            Route::patch('configuracion/razones-sociales/{id}/inactivar', [$rsc, 'inactivar'])->name('configuracion.razones.inactivar');
            Route::post('configuracion/razones-sociales/{id}/sello', [$rsc, 'subirSello'])->name('configuracion.razones.sello');
            Route::post('configuracion/razones-sociales/{id}/cajas', [$rsc, 'agregarCaja'])->whereNumber('id')->name('configuracion.razones.cajas.agregar');
            // Prestar la razón social a otro aliado (solo superadmin BryNex, ver RazonSocialCompartida)
            Route::get('configuracion/razones-sociales/{id}/aliados', [$rsc, 'aliadosCompartida'])->whereNumber('id')->name('configuracion.razones.aliados');
            Route::post('configuracion/razones-sociales/{id}/habilitar', [$rsc, 'habilitarEnAliados'])->whereNumber('id')->name('configuracion.razones.habilitar');
        });
        Route::delete('configuracion/razones-sociales/{id}', [$rsc, 'destroy'])->name('configuracion.razones.destroy')->middleware('permiso:razones_sociales.eliminar');

        // Documentos de Razones Sociales
        $rsdc = \App\Http\Controllers\Admin\RazonSocialDocumentoController::class;
        // Credenciales de las APIs de operadores, por razón social
        $rsoc = \App\Http\Controllers\Admin\OperadorCredencialController::class;
        Route::middleware('permiso:operadores_planilla.credenciales')->group(function () use ($rsoc) {
            Route::post('configuracion/razones-sociales/{id}/credenciales', [$rsoc, 'store'])->name('configuracion.razones.credenciales.store');
            Route::post('configuracion/razones-sociales/{id}/credenciales/{operador}/probar', [$rsoc, 'probar'])->name('configuracion.razones.credenciales.probar');
            Route::delete('configuracion/razones-sociales/{id}/credenciales/{operador}', [$rsoc, 'destroy'])->name('configuracion.razones.credenciales.destroy');
        });

        // Claves de portales críticos por razón social: DIAN, bancos, cámara.
        // Aparte de `clave_accesos` (EPS/ARL/caja), que las ve todo el rol
        // `usuario`: estas solo el superadmin del aliado y a quien se le
        // otorgue `credenciales_rs.*` a mano.
        $rscc = \App\Http\Controllers\Admin\RazonSocialCredencialController::class;
        Route::middleware('permiso:credenciales_rs.ver')->group(function () use ($rscc) {
            Route::get('configuracion/razones-sociales/{id}/claves', [$rscc, 'index'])->name('configuracion.razones.claves.index');
            Route::get('configuracion/razones-sociales/{id}/claves/{cred}/revelar', [$rscc, 'revelar'])->name('configuracion.razones.claves.revelar');
        });
        Route::middleware('permiso:credenciales_rs.gestionar')->group(function () use ($rscc) {
            Route::post('configuracion/razones-sociales/{id}/claves', [$rscc, 'store'])->name('configuracion.razones.claves.store');
            Route::put('configuracion/razones-sociales/{id}/claves/{cred}', [$rscc, 'update'])->name('configuracion.razones.claves.update');
            Route::delete('configuracion/razones-sociales/{id}/claves/{cred}', [$rscc, 'destroy'])->name('configuracion.razones.claves.destroy');
        });

        Route::get('configuracion/razones-sociales/documentos/{id}/descargar', [$rsdc, 'download'])->name('configuracion.razones.documentos.download')->middleware('permiso:razones_sociales.ver');
        // Permiso propio, aparte de `gestionar`: subir la cámara de comercio o
        // el RUT no tiene por qué dar también editar los datos de la empresa.
        Route::middleware('permiso:razones_sociales.documentos')->group(function () use ($rsdc) {
            Route::get('configuracion/razones-sociales/{id}/documentos', [$rsdc, 'index'])->whereNumber('id')->name('configuracion.razones.documentos.index');
            Route::post('configuracion/razones-sociales/{id}/documentos', [$rsdc, 'store'])->name('configuracion.razones.documentos.store');
            Route::delete('configuracion/razones-sociales/documentos/{id}', [$rsdc, 'destroy'])->name('configuracion.razones.documentos.destroy');
        });

        // Formularios EPS — mapeo visual de coordenadas
        // Mapeo de los PDF: el permiso es de BryNex, no de configuración del
        // aliado. `configuracion.editar` cubre además parámetros y modalidades,
        // que sí son solo de superadmin — no sirve para esto.
        $ef = \App\Http\Controllers\Admin\EpsFormularioController::class;
        Route::middleware('permiso:formularios_pdf.editar')->group(function () use ($ef) {
            Route::get('configuracion/eps/{eps}/formulario', [$ef, 'editor'])->name('configuracion.eps.formulario');
            Route::get('configuracion/eps/{eps}/formulario/pdf', [$ef, 'verPdf'])->name('configuracion.eps.formulario.vpdf');
            Route::post('configuracion/eps/{eps}/formulario', [$ef, 'guardar'])->name('configuracion.eps.formulario.guardar');
            Route::post('configuracion/eps/{eps}/formulario/pdf', [$ef, 'subirPdf'])->name('configuracion.eps.formulario.pdf');
        });

        // Formularios de fondos de pensión (COLPENSIONES y demás AFP) —
        // mismo editor visual, otra entidad dueña del PDF.
        $pf = \App\Http\Controllers\Admin\PensionFormularioController::class;
        Route::middleware('permiso:formularios_pdf.editar')->group(function () use ($pf) {
            Route::get('configuracion/pensiones/{pension}/formulario', [$pf, 'editor'])->name('configuracion.pensiones.formulario');
            Route::get('configuracion/pensiones/{pension}/formulario/pdf', [$pf, 'verPdf'])->name('configuracion.pensiones.formulario.vpdf');
            Route::post('configuracion/pensiones/{pension}/formulario', [$pf, 'guardar'])->name('configuracion.pensiones.formulario.guardar');
            Route::post('configuracion/pensiones/{pension}/formulario/pdf', [$pf, 'subirPdf'])->name('configuracion.pensiones.formulario.pdf');
        });

        // Planillas de Pago SS — mapeo visual de coordenadas
        $opf = \App\Http\Controllers\Admin\OperadorPlanillaFormularioController::class;
        Route::middleware('permiso:formularios_pdf.editar')->group(function () use ($opf) {
            Route::get('configuracion/operadores/{operador}/formulario', [$opf, 'editor'])->name('configuracion.operadores.formulario');
            Route::get('configuracion/operadores/{operador}/formulario/pdf', [$opf, 'verPdf'])->name('configuracion.operadores.formulario.vpdf');
            Route::post('configuracion/operadores/{operador}/formulario', [$opf, 'guardar'])->name('configuracion.operadores.formulario.guardar');
            Route::post('configuracion/operadores/{operador}/formulario/pdf', [$opf, 'subirPdf'])->name('configuracion.operadores.formulario.pdf');
            Route::get('configuracion/operadores/datos-ejemplo', [$opf, 'obtenerDatosEjemplo'])->name('configuracion.operadores.ejemplo');
        });

        // API utilitaria: ciudades por departamento (para selects dinámicos)
        Route::get('api/departamentos/{id}/ciudades', function ($id) {
            return \App\Models\Ciudad::where('departamento_id', $id)
                ->orderBy('nombre')
                ->get(['id', 'nombre']);
        })->name('api.ciudades');

        // ─── Facturación ──────────────────────────────────────────────
        Route::prefix('facturacion')->name('facturacion.')->middleware(['permiso:facturacion.ver', 'permiso.escritura:facturacion.generar'])->group(function () {
            $fc = \App\Http\Controllers\Admin\FacturacionController::class;
            Route::get('/', [$fc, 'index'])->name('index');
            Route::get('empresa/crear', [$fc, 'createEmpresa'])->name('empresa.create')->middleware('permiso:facturacion.editar');
            Route::post('empresa', [$fc, 'storeEmpresa'])->name('empresa.store')->middleware('permiso:facturacion.editar');
            Route::get('empresa/{id}', [$fc, 'empresa'])->name('empresa');
            Route::get('empresa/{id}/exportar', [$fc, 'exportarEmpresaExcel'])->name('empresa.exportar')->middleware('permiso:facturacion.exportar');
            Route::get('empresa/{id}/historial', [$fc, 'historialEmpresa'])->name('empresa.historial');
            Route::get('empresa/{id}/retirados', [$fc, 'retiradosEmpresa'])->name('empresa.retirados');
            Route::get('empresa/{id}/editar', [$fc, 'editEmpresa'])->name('empresa.edit')->middleware('permiso:facturacion.editar');
            Route::put('empresa/{id}/editar', [$fc, 'updateEmpresa'])->name('empresa.update')->middleware('permiso:facturacion.editar');
            // Acceso de la empresa a su portal (brynex.co/portal).
            Route::middleware('permiso:facturacion.portal_empresas')->group(function () {
                $ea = \App\Http\Controllers\Admin\EmpresaAccesoController::class;
                Route::post('empresa/{id}/portal', [$ea, 'store'])->whereNumber('id')->name('empresa.portal.store');
                Route::post('empresa/{id}/portal/restablecer', [$ea, 'restablecer'])->whereNumber('id')->name('empresa.portal.restablecer');
                Route::patch('empresa/{id}/portal', [$ea, 'update'])->whereNumber('id')->name('empresa.portal.update');
            });
            Route::post('facturar', [$fc, 'facturar'])->name('facturar')->middleware('permiso:facturacion.generar');
            Route::post('abonar/{id}', [$fc, 'abonar'])->name('abonar')->middleware('permiso:facturacion.generar');
            Route::get('recibo/{id}', [$fc, 'recibo'])->name('recibo');
            Route::get('recibo-abono/{id}', [$fc, 'reciboAbono'])->name('recibo-abono');
            Route::get('api/saldo/{cedula}', [$fc, 'saldoCliente'])->name('api.saldo');
            Route::get('api/mes-pagado/{contratoId}', [$fc, 'mesPagado'])->name('api.mes_pagado');
            Route::post('correccion/{contratoId}', [\App\Http\Controllers\Admin\CorreccionPlanillaController::class, 'store'])->whereNumber('contratoId')->name('correccion.store')->middleware('permiso:facturacion.generar');
            Route::get('api/plano/{razon_social_id}', [$fc, 'planoActual'])->name('api.plano');
            Route::get('api/saldos-contratos', [$fc, 'saldosContratos'])->name('api.saldos_contratos');
            Route::post('api/verificar-periodo', [$fc, 'verificarPeriodoLote'])->name('api.verificar_periodo');
            Route::get('api/cotizacion-contrato/{id}', [$fc, 'cotizacionContrato'])->name('api.cotizacion_contrato');
            Route::delete('{id}/anular', [$fc, 'anular'])->name('anular')->middleware('permiso:facturacion.anular');
            Route::get('historial/{cedula}', [$fc, 'historial'])->name('historial');
            Route::get('anuladas', [$fc, 'anuladas'])->name('anuladas');
            Route::post('{id}/restaurar', [$fc, 'restaurar'])->name('restaurar')->middleware('permiso:facturacion.anular');
            // Imágenes de consignaciones
            Route::post('consignacion/{id}/imagen', [$fc, 'subirImagenConsignacion'])->name('consignacion.imagen.subir');
            Route::get('consignacion/{id}/imagen', [$fc, 'verImagenConsignacion'])->name('consignacion.imagen.ver');
            // Otro ingreso (trámites: traslado EPS, inclusión beneficiarios, etc.)
            Route::post('otro-ingreso', [$fc, 'facturarOtroIngreso'])->name('otro_ingreso.store')->middleware('permiso:facturacion.generar');
            Route::match(['get', 'post'], 'cuenta-cobro', [$fc, 'cuentaCobroPreview'])->name('cuenta_cobro.preview');
            Route::post('contrato/{contrato}/retiro-pendiente', [$fc, 'guardarRetiroPendiente'])->name('contrato.retiro_pendiente');
            // ── Carga masiva de cédulas con NP provisional ───────────────────────────
            Route::post('empresa/{id}/verificar-cedulas', [$fc, 'verificarCedulas'])->name('empresa.verificar_cedulas');
            Route::post('empresa/{id}/asignar-np', [$fc, 'asignarNpProvisional'])->name('empresa.asignar_np');

            // ── Cobros adicionales por empresa (parafiscales, pendientes, etc.) ──
            Route::middleware('permiso:facturacion.cobros_adicionales')->group(function () use ($fc) {
                Route::get('empresa/{empresaId}/cobros-adicionales', [$fc, 'cobrosAdicionalesIndex'])->name('cobros_adicionales.index');
                Route::post('empresa/{empresaId}/cobros-adicionales', [$fc, 'cobrosAdicionalesStore'])->name('cobros_adicionales.store');
                Route::delete('cobros-adicionales/{cobroId}', [$fc, 'cobrosAdicionalesDestroy'])->name('cobros_adicionales.destroy');
            });

            // ── Facturación Electrónica (Dataico) — solo admin + superadmin ──
            $fe = \App\Http\Controllers\Admin\FacturacionElectronicaController::class;
            Route::get('electronica', [$fe, 'index'])->name('electronica.index')->middleware('permiso:facturacion_electronica.ver');
            Route::get('electronica/exportar', [$fe, 'exportar'])->name('electronica.exportar')->middleware('permiso:facturacion_electronica.ver');
            Route::patch('electronica/marcar', [$fe, 'marcar'])->name('electronica.marcar')->middleware('permiso:facturacion_electronica.emitir');

            // ── Dataico por API — reemplaza el Excel manual de arriba ──
            $dc = \App\Http\Controllers\Admin\DataicoController::class;
            Route::get('dataico', [$dc, 'index'])->name('dataico.index')->middleware('permiso:facturacion_electronica.ver');
            Route::get('dataico/simular', [$dc, 'simular'])->name('dataico.simular')->middleware('permiso:facturacion_electronica.ver');
            Route::post('dataico/reintentar', [$dc, 'reintentar'])->name('dataico.reintentar')->middleware('permiso:facturacion_electronica.emitir');
            Route::post('dataico/omitir', [$dc, 'omitir'])->name('dataico.omitir')->middleware('permiso:facturacion_electronica.emitir');
            Route::post('dataico/configuracion', [$dc, 'guardarConfiguracion'])->name('dataico.configuracion')->middleware('permiso:facturacion_electronica.configurar');
        });

        // ── Planos (Pago Planillas SS) ────────────────────────────────────
        Route::prefix('planos')->name('planos.')->middleware(['permiso:planos.ver', 'permiso.escritura:planos.generar'])->group(function () {
            $pp = \App\Http\Controllers\Admin\PlanoPagoController::class;
            Route::get('/', [$pp, 'index'])->name('index');
            Route::get('/descargar', [$pp, 'descargar'])->name('descargar');
            Route::get('/descargar-asopagos', [$pp, 'descargarAsopagos'])->name('descargar_asopagos');
            Route::get('/descargar-miplanilla', [$pp, 'descargarMiPlanilla'])->name('descargar_miplanilla');
            Route::get('/descargar-aportes-en-linea', [$pp, 'descargarAportesEnLinea'])->name('descargar_aportes_en_linea');
            Route::get('/descargar-aportes-en-linea-2', [$pp, 'descargarAportesEnLinea2'])->name('descargar_aportes_en_linea_2');
            Route::get('/certificado-pdf', [$pp, 'descargarCertificadoPdf'])->name('certificado_pdf');
            Route::get('/certificado-pdf/progreso', [$pp, 'progresoCertificadoPdf'])->name('certificado_pdf.progreso');
            Route::post('/miplanilla-clave', [$pp, 'guardarClaveMiPlanilla'])->name('miplanilla_clave');
            Route::patch('/n-plano', [$pp, 'actualizarNPlano'])->name('n_plano.update');
            Route::patch('/operador-cliente', [$pp, 'asignarOperadorCliente'])->name('operador_cliente.asignar');
            Route::patch('/mover-masivo', [$pp, 'moverPlanoMasivo'])->name('mover_masivo');
            Route::patch('/{id}/mover', [$pp, 'moverPlano'])->name('mover');
            Route::post('/confirmar-pago', [$pp, 'confirmarPago'])->name('confirmar_pago');
            // La correccion se filtra dentro del controlador: admin (gastos.gestionar)
            // o el usuario que confirmo ese pago.
            Route::post('/corregir-pago', [$pp, 'corregirPago'])->name('corregir_pago');
            Route::get('/api/razon/{id}', [$pp, 'apiRazonSocial'])->name('api.razon');
            Route::get('/api/resumen', [$pp, 'apiResumenPlanos'])->name('api.resumen');

            // Liquidación directa contra la API del operador (Enlace Operativo)
            $pa = \App\Http\Controllers\Admin\PlanillaApiController::class;
            Route::get('/api-operador/estado', [$pa, 'estado'])->name('api_operador.estado');
            Route::post('/api-operador/liquidar', [$pa, 'liquidar'])->name('api_operador.liquidar');
            Route::post('/api-operador/liquidar-independiente', [$pa, 'liquidarIndependiente'])->name('api_operador.liquidar_independiente');
            Route::post('/api-operador/autocorregir', [$pa, 'autocorregir'])->name('api_operador.autocorregir');
            Route::get('/api-operador/{codigoPlanilla}/inconsistencias', [$pa, 'inconsistencias'])
                ->whereNumber('codigoPlanilla')->name('api_operador.inconsistencias');

            // Envío de planillas por WhatsApp
            Route::get('/envio-planillas', [$pp, 'enviosPlanillaIndex'])->name('envio_planillas');
            Route::get('/envio-planillas/api', [$pp, 'enviosPlanillaApi'])->name('envio_planillas.api');
            Route::post('/envio-planillas/enviar', [$pp, 'enviosPlanillaEnviar'])->name('envio_planillas.enviar');
            Route::post('/envio-planillas/{detalleId}/reenviar', [$pp, 'enviosPlanillaReenviar'])->name('envio_planillas.reenviar');
            Route::get('/envio-planillas/historial', [$pp, 'enviosPlanillaHistorial'])->name('envio_planillas.historial');
            Route::get('/envio-planillas/{loteId}/detalle', [$pp, 'enviosPlanillaLoteDetalle'])->name('envio_planillas.lote_detalle');
            Route::post('/envio-planillas/crear-plantilla', [$pp, 'enviosPlanillaCrearPlantilla'])->name('envio_planillas.crear_plantilla');
        });

        // ── Cobros ───────────────────────────────────────────────────────
        Route::prefix('cobros')->name('cobros.')->middleware(['permiso:cobros.ver', 'permiso.escritura:cobros.registrar'])->group(function () {
            $cb = \App\Http\Controllers\Admin\CobrosController::class;
            // Individuales
            Route::get('/', [$cb, 'index'])->name('index');
            Route::get('/exportar', [$cb, 'exportar'])->name('exportar');
            Route::get('/whatsapp/previsualizar', [$cb, 'vistaPrevisualizarWhatsApp'])->name('whatsapp.previsualizar');
            Route::post('/whatsapp/prueba', [$cb, 'enviarPruebaWhatsApp'])->name('whatsapp.prueba');
            Route::post('/whatsapp/enviar-filtro', [$cb, 'enviarFiltroWhatsApp'])->name('whatsapp.enviar_filtro');
            Route::get('/whatsapp/historial', [$cb, 'historialEnviosWhatsApp'])->name('whatsapp.historial');
            Route::get('/whatsapp/{loteId}/reporte', [$cb, 'reporteLoteWhatsApp'])->name('whatsapp.reporte');
            Route::post('/whatsapp/{loteId}/reintentar', [$cb, 'reintentarLoteWhatsApp'])->name('whatsapp.reintentar');
            Route::post('/{contratoId}/llamada', [$cb, 'registrarLlamada'])->name('llamada.store');
            Route::get('/{contratoId}/llamadas', [$cb, 'historialLlamadas'])->name('llamadas');
            // Empresas
            Route::get('/empresas', [$cb, 'empresas'])->name('empresas');
            Route::get('/empresas/whatsapp/previsualizar', [$cb, 'previsualizarWhatsAppEmpresas'])->name('empresas.whatsapp.previsualizar');
            Route::post('/empresas/whatsapp/enviar', [$cb, 'enviarWhatsAppEmpresas'])->name('empresas.whatsapp.enviar');
            Route::post('/empresa/{id}/llamada', [$cb, 'registrarLlamadaEmpresa'])->name('empresa.llamada.store');
            Route::get('/empresa/{id}/llamadas', [$cb, 'historialEmpresa'])->name('empresa.llamadas');
            Route::patch('/empresa/{id}/encargado', [$cb, 'asignarEncargado'])->name('empresa.encargado');
        });

        // ── Informes (admin + superadmin; financiero también para contador) ──
        Route::prefix('informes')->name('informes.')->middleware(['permiso:informes.ver', 'permiso.escritura:cobros.registrar'])->group(function () {
            $ic = \App\Http\Controllers\Admin\InformeController::class;
            Route::get('/', [$ic, 'hub'])->name('hub');
            Route::get('/consolidado-mensual', [$ic, 'consolidadoMensual'])->name('consolidado_mensual');
            Route::get('/consolidado-mensual/detalle', [$ic, 'consolidadoMensualDetalle'])->name('consolidado_mensual_detalle');
            Route::get('/consolidado-mensual/whatsapp-detalle', [$ic, 'consolidadoMensualWhatsapp'])->name('consolidado_mensual_whatsapp');
            Route::get('/brynex-cobros', [$ic, 'brynexCobros'])->name('brynex_cobros');
            Route::get('/brynex-cobros/{cobro}/pdf', [$ic, 'brynexCobroPdf'])->name('brynex_cobros.pdf');
            Route::get('/listados-mes', [$ic, 'listadosMes'])->name('listados_mes');
            Route::get('/clientes-activos', [$ic, 'clientesActivos'])->name('clientes_activos');
            Route::get('/por-razon-social', [$ic, 'porRazonSocial'])->name('por_razon_social');
            Route::get('/afiliaciones-retiros', [$ic, 'afiliacionesRetiros'])->name('afiliaciones_retiros');
            Route::get('/empresas-clientes', [$ic, 'empresasClientes'])->name('empresas_clientes');
            Route::get('/por-entidades', [$ic, 'porEntidades'])->name('por_entidades');
            Route::get('/retirados-mes', [$ic, 'retiradosMes'])->name('retirados_mes');
            Route::get('/validacion-cierre', [$ic, 'validacionCierre'])->name('validacion_cierre')
                ->middleware('permiso:brynex_cierre.ver');
            Route::get('/validacion-cierre/sin-planilla', [$ic, 'validacionCierreSinPlanilla'])
                ->name('validacion_cierre.sin_planilla')->middleware('permiso:brynex_cierre.ver');
            Route::get('/validacion-cierre/ficha', [$ic, 'validacionCierreFicha'])
                ->name('validacion_cierre.ficha')->middleware('permiso:brynex_cierre.ver');
            Route::get('/cierre-operacion', [$ic, 'cierreOperacion'])->name('cierre_operacion');
            Route::get('/incapacidades', [$ic, 'resumenIncapacidades'])->name('incapacidades');
            Route::get('/tareas', [$ic, 'resumenTareas'])->name('tareas');
            Route::get('/conciliacion-bancos', [$ic, 'conciliacionBancos'])->name('conciliacion_bancos');

            // El KPI de préstamos del mes también lo consume la pantalla de
            // Cobros, así que cuelga de `prestamos.ver` y no del financiero:
            // si no, a un `usuario` en Cobros le respondería 403.
            Route::get('/financiero/prestamos-mes', [$ic, 'prestamesMes'])
                ->name('financiero.prestamos_mes')->middleware('permiso:prestamos.ver');

            // ── Estado financiero: superadmin y contable ───────────────────
            // La plata del aliado va aparte del resto de informes. Escribir
            // (corregir una consignación, subir su soporte) pide otro permiso
            // que ningún rol trae: contable mira, no toca.
            Route::middleware('permiso:informes.financiero')->group(function () use ($ic) {
                Route::get('/financiero', [$ic, 'estadoFinanciero'])->name('financiero');
                Route::get('/financiero/bancos', [$ic, 'financieroBancos'])->name('financiero.bancos');
                Route::get('/financiero/efectivo', [$ic, 'financieroEfectivo'])->name('financiero.efectivo');
                Route::get('/financiero/auditar-planilla', [$ic, 'auditarPlanilla'])->name('financiero.auditar_planilla');
                Route::get('/financiero/ss-planillas', [$ic, 'ssPlanillas'])->name('financiero.ss_planillas');
                Route::get('/financiero/conciliacion-ss', [$ic, 'conciliacionSS'])->name('financiero.conciliacion_ss');
                Route::get('/financiero/detalle-dia', [$ic, 'detalleDia'])->name('financiero.detalle_dia');
                Route::get('/financiero/desglose-dia', [$ic, 'desgloseDia'])->name('financiero.desglose_dia');
                Route::get('/financiero/gastos-detalle', [$ic, 'gastosDetalle'])->name('financiero.gastos_detalle');
            });
            Route::middleware('permiso:informes.financiero_editar')->group(function () use ($ic) {
                Route::patch('/financiero/consignacion/{id}', [$ic, 'editarConsignacion'])->name('financiero.consignacion.editar');
                Route::post('/financiero/consignacion/{id}/imagen', [$ic, 'subirImagenConsignacionFinanciero'])->name('financiero.consignacion.imagen');
            });

            // ── Extracto del banco: la bandeja de conciliación ─────────────
            // Va con los mismos permisos que el resto de la plata del aliado:
            // el contable mira las diferencias, pero confirmar un pago o
            // deshacer un cruce es escritura financiera.
            $eb = \App\Http\Controllers\Admin\ExtractoBancoController::class;
            Route::middleware('permiso:informes.financiero')->group(function () use ($eb) {
                Route::get('/extracto-banco', [$eb, 'index'])->name('extracto_banco');
                Route::get('/extracto-banco/consignaciones', [$eb, 'buscarConsignaciones'])
                    ->name('extracto_banco.consignaciones');
            });
            Route::middleware('permiso:informes.financiero_editar')->group(function () use ($eb) {
                Route::post('/extracto-banco/cargar', [$eb, 'cargarExtracto'])->name('extracto_banco.cargar');
                Route::post('/extracto-banco/sincronizar', [$eb, 'sincronizar'])->name('extracto_banco.sincronizar');
                Route::post('/extracto-banco/conciliar', [$eb, 'conciliar'])->name('extracto_banco.conciliar');
                Route::post('/extracto-banco/movimiento/{id}/vincular', [$eb, 'vincular'])->name('extracto_banco.vincular');
                Route::post('/extracto-banco/movimiento/{id}/ignorar', [$eb, 'ignorar'])->name('extracto_banco.ignorar');
                Route::post('/extracto-banco/movimiento/{id}/personal', [$eb, 'marcarPersonal'])->name('extracto_banco.personal');
                Route::post('/extracto-banco/movimiento/{id}/registrar-gasto', [$eb, 'registrarGasto'])->name('extracto_banco.registrar_gasto');
                Route::post('/extracto-banco/movimiento/{id}/registrar-entrada', [$eb, 'registrarEntrada'])->name('extracto_banco.registrar_entrada');
                Route::post('/extracto-banco/vinculo/{id}/deshacer', [$eb, 'desvincular'])->name('extracto_banco.desvincular');
                Route::post('/extracto-banco/consignacion/{id}/no-aparece', [$eb, 'noAparece'])->name('extracto_banco.no_aparece');
            });
            Route::get('/auditoria-facturas', [$ic, 'auditoriaFacturas'])->name('auditoria_facturas');

            // ── Gestión de gastos ──────────────────────────────────────────
            $ga = \App\Http\Controllers\Admin\GastoAdminController::class;
            Route::get('/gastos', [$ga, 'index'])->name('gastos.index')->middleware('permiso:gastos.ver');
            Route::middleware('permiso:gastos.gestionar')->group(function () use ($ga) {
                Route::get('/gastos/{id}/impacto-planilla', [$ga, 'impactoPlanilla'])->name('gastos.impacto_planilla');
                Route::post('/gastos', [$ga, 'store'])->name('gastos.store');
                Route::put('/gastos/{id}', [$ga, 'update'])->name('gastos.update');
                Route::delete('/gastos/{id}', [$ga, 'destroy'])->name('gastos.destroy');
                Route::post('/gastos/{id}/imagen', [$ga, 'imagen'])->name('gastos.imagen');
            });

            // ── Comisiones Asesores ──────────────────────────────────────
            $cc = \App\Http\Controllers\Admin\ComisionesController::class;
            Route::middleware('permiso:comisiones.ver')->group(function () use ($cc) {
                Route::get('/comisiones', [$cc, 'index'])->name('comisiones.index');
                Route::get('/comisiones/afiliaciones', [$cc, 'afiliaciones'])->name('comisiones.afiliaciones');
            });
            Route::middleware('permiso:comisiones.gestionar')->group(function () use ($cc) {
                Route::post('/comisiones/afiliaciones/{id}', [$cc, 'distribuir'])->name('comisiones.distribuir');
                Route::post('/comisiones/asesores/{asesor}/pagar', [$cc, 'pagar'])->name('comisiones.pagar');
            });
        });

        // ── Anticipos (Pagos sin Factura) ────────────────────────────────
        Route::prefix('anticipos')->name('anticipos.')->middleware(['permiso:anticipos.ver', 'permiso.escritura:anticipos.gestionar'])->group(function () {
            $ac = \App\Http\Controllers\Admin\AnticipoController::class;
            Route::post('/', [$ac, 'store'])->name('store');
            Route::post('/distribuir', [$ac, 'storeDistribuido'])->name('distribuir');
            Route::get('/informe', [$ac, 'informe'])->name('informe');
            Route::get('/saldos', [$ac, 'saldosFavor'])->name('saldos');
            Route::post('/saldos/ajustar', [$ac, 'ajustarSaldos'])->name('saldos.ajustar');
            Route::delete('/saldos/ajuste/{id}', [$ac, 'deshacerAjuste'])->name('saldos.deshacer');
            Route::get('/{id}/recibo', [$ac, 'reciboAnticipo'])->name('recibo');
            Route::post('/{id}/anular', [$ac, 'anular'])->name('anular');
            Route::post('/{id}/devolver', [$ac, 'devolver'])->name('devolver');
            Route::delete('/{id}', [$ac, 'destroy'])->name('destroy');
            // APIs para modal facturar
            Route::get('/api/contrato/{id}', [$ac, 'porContrato'])->name('api.contrato');
            Route::get('/api/empresa/{id}', [$ac, 'porEmpresa'])->name('api.empresa');
            Route::get('/api/contratos-empresa/{id}', [$ac, 'contratosEmpresa'])->name('api.contratos_empresa');
            Route::get('/api/cliente/{cedula}', [$ac, 'porCliente'])->name('api.cliente');
        });

        // ── Préstamos / Cartera ──────────────────────────────────────────
        Route::prefix('prestamos')->name('prestamos.')->middleware(['permiso:prestamos.ver', 'permiso.escritura:prestamos.gestionar'])->group(function () {
            $pc = \App\Http\Controllers\Admin\PrestamosController::class;
            Route::get('/', [$pc, 'index'])->name('index');
            Route::get('/api/pendientes', [$pc, 'apiPendientes'])->name('api.pendientes');
            Route::get('/abono/{id}/soporte', [$pc, 'descargarSoporteAbono'])->name('abono.soporte');
            Route::post('/abono/{id}/soporte', [$pc, 'adjuntarSoporteAbono'])->name('abono.soporte.adjuntar');
            Route::get('/{id}', [$pc, 'show'])->name('show');
            Route::post('/{id}/abonar', [$pc, 'abonar'])->name('abonar');
            Route::post('/{id}/condonar', [$pc, 'condonar'])->name('condonar');
            Route::post('/{id}/gestion', [$pc, 'registrarGestion'])->name('gestion.store');
            Route::get('/{id}/gestiones', [$pc, 'historialGestiones'])->name('gestiones');
        });

    });

    // ── BryNex Global (solo usuarios es_brynex) ──────────────────────────
    Route::prefix('brynex')->name('brynex.')->middleware('permiso:brynex_hub.ver')->group(function () {
        $bx = \App\Http\Controllers\BrynexController::class;
        Route::get('/', [$bx, 'hub'])->name('hub');
        Route::get('/accesos', [$bx, 'accesos'])->name('accesos');
        Route::post('/accesos', [$bx, 'toggleAcceso'])->name('accesos.toggle');

        // Conciliación de afiliados contra ARL Sura. Vive en BryNex y no en los
        // informes del aliado porque cruza una póliza contra los contratos de
        // todos los aliados que comparten ese NIT.
        // Cada empresa se consulta aparte: abrir las once de golpe serían once
        // sesiones en el portal y varios minutos de espera.
        $acc = \App\Http\Controllers\Admin\ArlConciliacionController::class;
        Route::middleware('permiso:brynex_cierre.ver')->group(function () use ($acc) {
            Route::get('/conciliacion-arl', [$acc, 'index'])->name('conciliacion_arl');
            Route::get('/conciliacion-arl/{nit}', [$acc, 'conciliar'])->name('conciliacion_arl.empresa');
            Route::get('/conciliacion-arl/{nit}/riesgos', [$acc, 'riesgos'])->name('conciliacion_arl.riesgos');
            Route::get('/conciliacion-arl/{nit}/eps-sura', [$acc, 'epsSura'])->name('conciliacion_arl.eps_sura');
        });

        // Parámetros globales del sistema (salario mínimo, % de SS, tarifas ARL).
        // Estaban en la Configuración de cada aliado aunque no son del aliado.
        Route::get('/parametros', [$bx, 'parametros'])->name('parametros');
        Route::post('/parametros', [$bx, 'guardarParametros'])->name('parametros.guardar');

        // Configuración del Asistente Virtual IA
        $iac = \App\Http\Controllers\IaConfigController::class;
        Route::get('/ia', [$iac, 'index'])->name('ia.index');
        Route::post('/ia/global', [$iac, 'guardarGlobal'])->name('ia.global');
        Route::post('/ia/{alidoId}', [$iac, 'guardarAliado'])->name('ia.aliado');

        // Simulador de conversación (probar la IA en vivo y anotar correcciones)
        Route::prefix('ia/simulador')->name('ia.simulador.')->middleware('permiso:brynex_ia.ver')->group(function () {
            $ims = \App\Http\Controllers\IaSimuladorController::class;
            Route::get('/', [$ims, 'index'])->name('index');
            Route::get('/{alidoId}/historial', [$ims, 'historial'])->name('historial');
            Route::post('/mensaje', [$ims, 'mensaje'])->name('mensaje');
            Route::post('/nota', [$ims, 'guardarNota'])->name('nota');
            Route::post('/nota/{id}/resolver', [$ims, 'resolverNota'])->name('nota.resolver');
            Route::post('/reiniciar', [$ims, 'reiniciar'])->name('reiniciar');
        });

        // Entrenamiento / conocimiento del Asistente IA
        Route::prefix('ia/conocimiento')->name('ia.conocimiento.')->middleware('permiso:brynex_ia.entrenar')->group(function () {
            $ick = \App\Http\Controllers\IaConocimientoController::class;
            Route::get('/', [$ick, 'index'])->name('index');
            Route::post('/guardar', [$ick, 'guardar'])->name('guardar');
            Route::post('/{id}/aprobar', [$ick, 'aprobar'])->name('aprobar');
            Route::post('/{id}/rechazar', [$ick, 'rechazar'])->name('rechazar');
            Route::post('/{id}/eliminar', [$ick, 'eliminar'])->name('eliminar');
            Route::post('/preguntas/{id}/responder', [$ick, 'responderPregunta'])->name('preguntas.responder');
            Route::post('/preguntas/{id}/descartar', [$ick, 'descartarPregunta'])->name('preguntas.descartar');
        });

        // Copias de Seguridad (Backups)
        $bbc = \App\Http\Controllers\BrynexBackupController::class;
        Route::middleware('permiso:brynex_backup.ejecutar')->group(function () use ($bbc) {
            Route::get('/backups', [$bbc, 'backups'])->name('backups');
            Route::get('/backups/descargar', [$bbc, 'descargarBackup'])->name('backups.descargar');
            Route::post('/backups/crear', [$bbc, 'crearBackupManual'])->name('backups.crear');
        });

        // Entrega de datos a un aliado que se va. El acceso lo cierra
        // `exportacion.access` (lista blanca en config/exportacion) + un código
        // por WhatsApp que exige el controlador.
        $bex = \App\Http\Controllers\BrynexExportController::class;
        Route::prefix('exportaciones')->name('exportaciones.')->group(function () use ($bex) {
            Route::get('/', [$bex, 'index'])->name('index');
            Route::post('/solicitar', [$bex, 'solicitar'])->name('solicitar');
            Route::post('/confirmar', [$bex, 'confirmar'])->name('confirmar');
            Route::post('/cancelar', [$bex, 'cancelar'])->name('cancelar');
            Route::get('/{id}/descargar', [$bex, 'descargar'])->name('descargar');
            Route::get('/{id}/password', [$bex, 'password'])->name('password');
            Route::delete('/{id}', [$bex, 'eliminar'])->name('eliminar');
        });

        // ── Módulo Consumo & Cobros ───────────────────────────────────────────
        $cx = \App\Http\Controllers\BrynexConsumoController::class;
        Route::middleware('permiso:brynex_cobros.ver')->group(function () use ($cx) {
            Route::get('consumo', [$cx, 'index'])->name('consumo.index');
            Route::get('consumo/contabilidad', [$cx, 'contabilidad'])->name('consumo.contabilidad');
            Route::get('consumo/{aliado}/{mes}/{anio}', [$cx, 'show'])->name('consumo.show');
            Route::get('consumo/{aliado}/modulos', [$cx, 'modulosAliado'])->name('consumo.modulos');
            Route::get('consumo/cobros/{cobro}/pdf', [$cx, 'descargarPdf'])->name('consumo.pdf');
        });
        Route::middleware('permiso:brynex_cobros.gestionar')->group(function () use ($cx) {
            Route::post('consumo/{aliado}/{mes}/{anio}/cerrar', [$cx, 'cerrar'])->name('consumo.cerrar');
            Route::put('consumo/{aliado}/modulos', [$cx, 'actualizarModulos'])->name('consumo.modulos.update');
            Route::post('consumo/cobros/{cobro}/pago', [$cx, 'registrarPago'])->name('consumo.pago');
        });
    });

    // ── Razones sociales de BryNex ────────────────────────────────────────
    // Va en su propio grupo y NO dentro del de arriba a propósito: ese exige
    // `brynex_hub.ver`, y el contable de la casa no tiene por qué entrar al hub
    // entero (backups, cobros a aliados, entrenamiento de la IA) solo para
    // llegar a su módulo. Aquí la puerta la abre `brynex_razones.ver`, que
    // igual es `solo_brynex` y exige es_brynex vía el Gate::before.
    //
    // Cruza aliados a propósito: una razón social la usan varios y las
    // obligaciones ante la DIAN son una sola.
    Route::prefix('brynex/razones-sociales')->name('brynex.razones.')->group(function () {
        $brs = \App\Http\Controllers\BrynexRazonSocialController::class;
        $bob = \App\Http\Controllers\BrynexObligacionController::class;

        Route::middleware('permiso:brynex_razones.ver')->group(function () use ($brs, $bob) {
            Route::get('/', [$brs, 'index'])->name('index');
            Route::get('/tablero', [$brs, 'tablero'])->name('tablero');
            Route::get('/afiliados-excel', [$brs, 'afiliadosExcel'])->name('afiliados_excel');
            Route::get('/calendario', [$bob, 'calendario'])->name('calendario');
            Route::get('/{id}', [$brs, 'show'])->whereNumber('id')->name('show');
            Route::get('/documentos/{id}/descargar', [$bob, 'descargarDocumento'])->name('documentos.descargar');
        });

        Route::middleware('permiso:brynex_razones.gestionar')->group(function () use ($brs, $bob) {
            Route::post('/leer-documento', [$brs, 'leerDocumento'])->name('leer_documento');
            Route::post('/seguir', [$brs, 'seguir'])->name('seguir');
            Route::put('/{id}', [$brs, 'update'])->whereNumber('id')->name('update');
            Route::post('/{id}/dejar-de-seguir', [$brs, 'dejarDeSeguir'])->name('dejar');
            Route::post('/{id}/regenerar', [$bob, 'regenerar'])->name('regenerar');

            Route::put('/obligaciones/{id}', [$bob, 'actualizar'])->name('obligaciones.update');
            Route::post('/obligaciones/{id}/documento', [$bob, 'subirDocumento'])->name('obligaciones.documento');
            Route::delete('/documentos/{id}', [$bob, 'eliminarDocumento'])->name('documentos.destroy');
            Route::post('/calendario', [$bob, 'guardarCalendario'])->name('calendario.guardar');
        });

        // Las claves van por su propio permiso: el contador necesita las de la
        // DIAN y la cámara, pero no las del banco (ver `claves_banco`, que es
        // restringido y no lo hereda ni el superadmin).
        Route::middleware('permiso:brynex_razones.claves')->group(function () use ($brs) {
            Route::post('/{id}/claves', [$brs, 'guardarClave'])->whereNumber('id')->name('claves.guardar');
            Route::get('/claves/{id}/revelar', [$brs, 'revelarClave'])->name('claves.revelar');
            Route::delete('/claves/{id}', [$brs, 'eliminarClave'])->name('claves.destroy');
        });
    });

    // Consulta DIAN por documento. Va fuera del hub por la misma razón que
    // razones sociales: se usa para llenar la ficha de un cliente y quien la
    // necesita no tiene por qué entrar al hub entero. Cruza aliados a
    // propósito — la consulta sale de la cuenta de Dataico de la casa.
    Route::prefix('brynex/consulta-dian')->name('brynex.dian.')->group(function () {
        $bdc = \App\Http\Controllers\BrynexDianController::class;

        Route::middleware('permiso:brynex_dian.ver')->group(function () use ($bdc) {
            Route::get('/', [$bdc, 'index'])->name('index');
            Route::post('/consultar', [$bdc, 'consultar'])->name('consultar');
        });

        // La contraseña del portal es una credencial: permiso aparte y
        // restringido, como el resto de las credenciales del sistema.
        Route::post('/credenciales', [$bdc, 'guardarCredenciales'])
            ->middleware('permiso:brynex_dian.configurar')->name('credenciales');
    });

    // ── Cobro en visita (celular) ────────────────────────────────────
    // Cobrar puesto por puesto: factura si alcanza, anticipo si no. Todo cae
    // en la caja del día de quien cobra. Ver VisitaCobroService.
    Route::prefix('visita')->name('admin.visita.')->middleware(['permiso:facturacion.generar', 'permiso.escritura:facturacion.generar'])->group(function () {
        $vc = \App\Http\Controllers\Admin\VisitaCobroController::class;
        Route::get('/', [$vc, 'index'])->name('index');
        Route::get('/buscar', [$vc, 'buscar'])->name('buscar');
        Route::get('/cliente/{cedula}', [$vc, 'cliente'])->name('cliente');
        Route::get('/hoy', [$vc, 'hoy'])->name('hoy');
        Route::get('/recibo', [$vc, 'recibo'])->name('recibo');
        Route::post('/cobrar', [$vc, 'cobrar'])->name('cobrar');
        Route::post('/recibo/enviar', [$vc, 'enviarRecibo'])->name('recibo.enviar');
    });

    // ── Cuadre Diario ────────────────────────────────────────────────

    Route::prefix('cuadre-diario')->name('admin.cuadre-diario.')->middleware(['permiso:cuadre_diario.ver', 'permiso.escritura:cuadre_diario.gestionar'])->group(function () {
        $cd = \App\Http\Controllers\Admin\CuadreDiarioController::class;
        Route::get('/', [$cd, 'index'])->name('index');
        Route::get('/consolidado', [$cd, 'consolidado'])->name('consolidado');
        Route::get('/facturas-dia', [$cd, 'facturasDia'])->name('facturas-dia');
        Route::get('/facturas-dia/exportar', [$cd, 'exportarFacturasDia'])->name('facturas-dia.exportar');
        Route::post('/gasto', [$cd, 'registrarGasto'])->name('gasto.store');
        Route::delete('/gasto/{gastoId}', [$cd, 'eliminarGasto'])->name('gasto.destroy');
        Route::post('/gasto/{gastoId}/imagen', [$cd, 'subirImagenGasto'])->name('gasto.imagen');
        Route::post('/consignacion/{csId}/imagen', [$cd, 'subirImagenConsignacion'])->name('consignacion.imagen');
        Route::post('/consignacion/{csId}/confirmar', [$cd, 'confirmarConsignacion'])->name('consignacion.confirmar');
        Route::patch('/consignacion/{csId}/confirmar/reversar', [$cd, 'reversarConsignacion'])->name('consignacion.reversar');
        Route::post('/consignacion/{csId}/no-aparece', [$cd, 'noApareceConsignacion'])->name('consignacion.no-aparece');
        Route::delete('/consignacion/{csId}/anular-prestamo', [$cd, 'anularConsignacionPrestamo'])->name('consignacion.anular-prestamo');
        Route::post('/cerrar-dia', [$cd, 'cerrarDia'])->name('cerrar-dia');
        Route::delete('/cerrar-dia/{cuadreId}', [$cd, 'reabrirDia'])->name('reabrir-dia');
        Route::get('/{id}', [$cd, 'ver'])->name('ver');
    });

    // ── Caja Menor ───────────────────────────────────────────────────
    Route::prefix('caja-menor')->name('admin.caja-menor.')->middleware(['permiso:caja_menor.ver', 'permiso.escritura:caja_menor.gestionar'])->group(function () {
        $cm = \App\Http\Controllers\Admin\CajaMenorController::class;
        Route::get('/', [$cm, 'index'])->name('index');
        Route::post('/', [$cm, 'store'])->name('store');
    });

    // ── Gestión ARL (tipo_modalidad_id = 15)
    Route::prefix('admin/gestion-arl')->name('admin.gestion-arl.')->middleware(['permiso:gestion_arl.ver', 'permiso.escritura:gestion_arl.gestionar'])->group(function () {
        $ga = \App\Http\Controllers\Admin\GestionArlController::class;
        Route::get('/', [$ga, 'index'])->name('index');
        Route::patch('/{id}/renovar', [$ga, 'renovar'])->name('renovar');

        // Afiliación directa contra el API de ARL Sura. El precheck no toca el
        // portal: dice qué le falta al contrato antes de intentar nada.
        // Todo lo que opera el portal exige ARL por API (BryNex o aliado con el
        // módulo `arl_api` o la automatización completa).
        $aa = \App\Http\Controllers\Admin\ArlAfiliacionController::class;
        Route::get('/{id}/datos-retiro', [$aa, 'datosRetiro'])->name('datos-retiro');
        Route::middleware('can:automatizar-arl')->group(function () use ($aa) {
            Route::get('/{id}/precheck', [$aa, 'precheck'])->name('precheck');
            Route::post('/{id}/afiliar', [$aa, 'afiliar'])->name('afiliar');
            // Anular NO tiene API en Sura: lo hace un navegador sobre el Struts.
            Route::post('/{id}/anular', [$aa, 'anular'])->name('anular');
            // El ciclo mensual: anula la cobertura vigente y crea la del mes nuevo.
            // Sura no deja mover la fecha de una cobertura ya creada.
            Route::post('/{id}/renovar-sura', [$aa, 'renovar'])->name('renovar-sura');
            // El certificado cambia de estado al llegar la fecha de cobertura, así
            // que se puede volver por el bueno sin repetir ningún trámite.
            Route::get('/{id}/certificado', [$aa, 'certificado'])->name('certificado');
            // Cierra la cobertura en el portal para el retiro del módulo. Solo toca
            // Sura: el contrato lo marca después el retiro de siempre.
            Route::post('/{id}/anular-sura', [$aa, 'anularSura'])->name('anular-sura');
            // Credencial del portal de esa empresa; al guardarla se descubre su póliza.
            Route::post('/{id}/credencial', [$aa, 'credencial'])->name('credencial');
        });
    });

    // -- Afiliaciones
    Route::prefix('admin/afiliaciones')->name('admin.afiliaciones.')->middleware(['permiso:afiliaciones.ver', 'permiso.escritura:afiliaciones.gestionar'])->group(function () {
        $ac = \App\Http\Controllers\Admin\AfiliacionController::class;
        $fc = \App\Http\Controllers\Admin\FormularioEpsController::class;
        Route::get('/', [$ac, 'index'])->name('index');
        Route::get('/exportar', [$ac, 'exportar'])->name('exportar');
        // ARL Colmena por su API, desde el radicado de ARL. Va con ARL Sura en
        // `automatizar-arl`, no con los portales de EPS. Anular solo vale hasta
        // un día después del inicio de la vigencia; luego es retiro.
        Route::middleware('can:automatizar-arl')->group(function () {
            $colm = \App\Http\Controllers\Admin\ArlColmenaController::class;
            Route::get('/colmena/progreso/{id}', [$colm, 'progreso'])->name('colmena.progreso');
            Route::get('/{contrato}/colmena/precheck', [$colm, 'precheck'])->name('colmena.precheck');
            Route::post('/{contrato}/colmena/afiliar', [$colm, 'afiliar'])->name('colmena.afiliar');
            Route::post('/{contrato}/colmena/anular', [$colm, 'anular'])->name('colmena.anular');
            Route::post('/{contrato}/colmena/retirar', [$colm, 'retirar'])->name('colmena.retirar');
        });
        // Automatización de portales, Conciliar EPS y Buzón: BryNex o aliado autorizado.
        Route::middleware('can:automatizar-portales')->group(function () {
            // La extensión BryNex Portales, para instalarla en Chrome. Se arma
            // desde el código desplegado, así que nunca queda una versión vieja.
            $extc = \App\Http\Controllers\Admin\ExtensionPortalesController::class;
            Route::get('/extension-portales', [$extc, 'descargar'])->name('extension-portales');
            Route::get('/extension-portales/version', [$extc, 'version'])->name('extension-portales.version');
            // Conciliación de radicados de EPS SURA contra el portal (proceso en segundo plano).
            $esc = \App\Http\Controllers\Admin\EpsSuraConciliacionController::class;
            Route::post('/conciliar-eps-sura', [$esc, 'iniciar'])->name('conciliar-eps-sura');
            Route::get('/conciliar-eps-sura/estado', [$esc, 'estado'])->name('conciliar-eps-sura.estado');
            // Conciliación de Sanitas: el Estado de Afiliación lo trae la extensión BryNex Portales.
            $sanc = \App\Http\Controllers\Admin\SanitasController::class;
            Route::post('/sanitas/conciliar', [$sanc, 'conciliar'])->name('sanitas.conciliar');
            Route::get('/sanitas/conciliar/estado', [$sanc, 'estado'])->name('sanitas.conciliar.estado');
            // Novedad de cambio de empleador por el formulario web de Sanitas (lo llena la extensión).
            $sann = \App\Http\Controllers\Admin\SanitasNovedadController::class;
            Route::get('/{contrato}/sanitas/precheck', [$sann, 'precheck'])->name('sanitas.precheck');
            Route::get('/{contrato}/sanitas/formulario', [$sann, 'formulario'])->name('sanitas.formulario');
            Route::post('/{contrato}/sanitas/aplicar', [$sann, 'aplicar'])->name('sanitas.aplicar');
            // Bandeja del agente del buzón de afiliaciones.
            $buz = \App\Http\Controllers\Admin\BuzonAfiliacionesController::class;
            Route::get('/buzon', [$buz, 'index'])->name('buzon');
            Route::post('/buzon/recibido/{id}', [$buz, 'marcar'])->name('buzon.marcar');
            Route::post('/buzon/enviado/{id}/cerrar', [$buz, 'cerrarEnviado'])->name('buzon.cerrar-enviado');
            Route::get('/buzon/recibido/{id}/adjunto/{indice}', [$buz, 'adjunto'])->name('buzon.adjunto');
            // Reingreso en el portal de Nueva EPS desde el radicado de EPS.
            $nec = \App\Http\Controllers\Admin\NuevaEpsController::class;
            Route::get('/{contrato}/nueva-eps/precheck', [$nec, 'precheck'])->name('nueva-eps.precheck');
            Route::post('/{contrato}/nueva-eps/consultar', [$nec, 'consultar'])->name('nueva-eps.consultar');
            Route::post('/{contrato}/nueva-eps/registrar', [$nec, 'registrar'])->name('nueva-eps.registrar');
            // Reingreso en el portal de EPS SURA desde el radicado de EPS.
            $esr = \App\Http\Controllers\Admin\EpsSuraReingresoController::class;
            Route::get('/{contrato}/eps-sura/precheck', [$esr, 'precheck'])->name('eps-sura.precheck');
            Route::post('/{contrato}/eps-sura/explorar', [$esr, 'explorar'])->name('eps-sura.explorar');
            Route::post('/{contrato}/eps-sura/consultar', [$esr, 'consultar'])->name('eps-sura.consultar');
            Route::post('/{contrato}/eps-sura/registrar', [$esr, 'registrar'])->name('eps-sura.registrar');
            // Lo que la extensión trae del portal cuando el trámite lo hace
            // ella, en el navegador de la persona, en vez del servidor.
            Route::post('/{contrato}/eps-sura/aplicar', [$esr, 'aplicar'])->name('eps-sura.aplicar');
            Route::get('/{contrato}/eps-sura/credencial', [$esr, 'credencial'])->name('eps-sura.credencial');
            Route::get('/{contrato}/eps-sura/progreso', [$esr, 'progreso'])->name('eps-sura.progreso');
            // Novedad de inicio laboral en Salud Total desde el radicado de EPS.
            $stc = \App\Http\Controllers\Admin\SaludTotalController::class;
            Route::get('/{contrato}/salud-total/precheck', [$stc, 'precheck'])->name('salud-total.precheck');
            Route::get('/{contrato}/salud-total/progreso', [$stc, 'progreso'])->name('salud-total.progreso');
            Route::post('/{contrato}/salud-total/consultar', [$stc, 'consultar'])->name('salud-total.consultar');
            Route::post('/{contrato}/salud-total/registrar', [$stc, 'registrar'])->name('salud-total.registrar');
            // Novedad de inicio laboral en S.O.S.: el portal lo opera la extensión BryNex Portales.
            $sosc = \App\Http\Controllers\Admin\SosController::class;
            Route::get('/{contrato}/sos/precheck', [$sosc, 'precheck'])->name('sos.precheck');
            Route::get('/{contrato}/sos/lado-b', [$sosc, 'ladoB'])->name('sos.lado-b');
            Route::post('/{contrato}/sos/credencial', [$sosc, 'credencial'])->name('sos.credencial');
            Route::post('/{contrato}/sos/aplicar', [$sosc, 'aplicar'])->name('sos.aplicar');
            // Conciliación de radicados de S.O.S.: la extensión pide a quién consultar y
            // devuelve lo que leyó del portal en la sesión que abrió la persona.
            Route::get('/sos/conciliar/pendientes', [$sosc, 'porConsultar'])->name('sos.conciliar.pendientes');
            Route::post('/sos/conciliar', [$sosc, 'conciliar'])->name('sos.conciliar');
            Route::get('/sos/conciliar/estado', [$sosc, 'estadoConciliacion'])->name('sos.conciliar.estado');
            // Plan B: afiliación por correo al asesor de S.O.S. (portal rechaza o independientes).
            Route::get('/{contrato}/sos/correo', [$sosc, 'correoPreparar'])->name('sos.correo');
            Route::get('/{contrato}/sos/correo/formulario', [$sosc, 'correoFormulario'])->name('sos.correo.formulario');
            Route::post('/{contrato}/sos/correo/documento', [$sosc, 'correoDocumento'])->name('sos.correo.documento')->middleware('permiso:documentos.subir');
            Route::post('/{contrato}/sos/correo/enviar', [$sosc, 'correoEnviar'])->name('sos.correo.enviar');
            // EPS sin portal de empleador (Comfenalco Valle): afiliación por correo al asesor.
            // Caja Comfenalco Valle: afiliación por su Sucursal Virtual con la extensión.
            $ccc = \App\Http\Controllers\Admin\ComfenalcoCajaController::class;
            Route::get('/{contrato}/caja-comfenalco/precheck', [$ccc, 'precheck'])->name('caja-comfenalco.precheck');
            Route::post('/{contrato}/caja-comfenalco/credencial', [$ccc, 'credencial'])->name('caja-comfenalco.credencial');
            Route::post('/{contrato}/caja-comfenalco/aplicar', [$ccc, 'aplicar'])->name('caja-comfenalco.aplicar');
            Route::post('/{contrato}/caja-comfenalco/documentos', [$ccc, 'documentos'])->name('caja-comfenalco.documentos');
            Route::get('/{contrato}/caja-comfenalco/firma', [$ccc, 'firma'])->name('caja-comfenalco.firma');
            Route::post('/{contrato}/caja-comfenalco/pdf-radicado', [$ccc, 'pdfRadicado'])->name('caja-comfenalco.pdf-radicado');
            Route::post('/{contrato}/caja-comfenalco/declaracion', [$ccc, 'firmarDeclaracion'])->name('caja-comfenalco.declaracion');
            Route::post('/caja-comfenalco/conciliar', [$ccc, 'conciliar'])->name('caja-comfenalco.conciliar');
            Route::get('/caja-comfenalco/conciliar/estado', [$ccc, 'estado'])->name('caja-comfenalco.conciliar.estado');
            Route::post('/caja-comfenalco/beneficiarios', [$ccc, 'beneficiarios'])->name('caja-comfenalco.beneficiarios');
            // Caja Comfandi: afiliación por su Sucursal Virtual Empresas con la extensión.
            $cfd = \App\Http\Controllers\Admin\ComfandiCajaController::class;
            Route::get('/{contrato}/caja-comfandi/precheck', [$cfd, 'precheck'])->name('caja-comfandi.precheck');
            Route::post('/{contrato}/caja-comfandi/credencial', [$cfd, 'credencial'])->name('caja-comfandi.credencial');
            Route::post('/{contrato}/caja-comfandi/aplicar', [$cfd, 'aplicar'])->name('caja-comfandi.aplicar');
            Route::post('/caja-comfandi/conciliar', [$cfd, 'conciliar'])->name('caja-comfandi.conciliar');
            Route::get('/caja-comfandi/conciliar/estado', [$cfd, 'estado'])->name('caja-comfandi.conciliar.estado');
            // Revisión de subsidios bloqueados: la extensión pide a quién
            // consultar y devuelve lo que lee del portal, que se vuelve tareas.
            Route::get('/caja-comfandi/subsidios/candidatos', [$cfd, 'subsidiosCandidatos'])->name('caja-comfandi.subsidios.candidatos');
            Route::post('/caja-comfandi/subsidios', [$cfd, 'subsidios'])->name('caja-comfandi.subsidios');
            // Portal Boxalud (Emssanar): afiliación con la extensión BryNex Portales.
            $bxc = \App\Http\Controllers\Admin\BoxaludController::class;
            Route::get('/{contrato}/boxalud/{eps}/precheck', [$bxc, 'precheck'])->name('boxalud.precheck')->where('eps', '[a-z_]+');
            Route::post('/{contrato}/boxalud/{eps}/credencial', [$bxc, 'credencial'])->name('boxalud.credencial')->where('eps', '[a-z_]+');
            Route::get('/{contrato}/boxalud/{eps}/archivo/{tipo}', [$bxc, 'archivo'])->name('boxalud.archivo')->where(['eps' => '[a-z_]+', 'tipo' => 'formulario|encuesta']);
            Route::post('/{contrato}/boxalud/{eps}/aplicar', [$bxc, 'aplicar'])->name('boxalud.aplicar')->where('eps', '[a-z_]+');
            $cae = \App\Http\Controllers\Admin\CorreoAsesorEpsController::class;
            Route::get('/{contrato}/correo-eps/{entidad}', [$cae, 'preparar'])->name('correo-eps.preparar')->where('entidad', '[a-z_]+');
            Route::post('/{contrato}/correo-eps/{entidad}/documento', [$cae, 'documento'])->name('correo-eps.documento')->where('entidad', '[a-z_]+')->middleware('permiso:documentos.subir');
            Route::post('/{contrato}/correo-eps/{entidad}/enviar', [$cae, 'enviar'])->name('correo-eps.enviar')->where('entidad', '[a-z_]+');
        });
        Route::get('/{contrato}/historial', [$ac, 'historial'])->name('historial');
        Route::get('/{contrato}/formulario/eps', [$fc, 'vista'])->name('formulario.eps')->whereNumber('contrato');
        Route::get('/{contrato}/formulario/eps/raw', [$fc, 'generar'])->name('formulario.eps.raw')->whereNumber('contrato');
        Route::post('/{contrato}/formulario/eps/firma', [$fc, 'guardarFirma'])->name('formulario.eps.firma')->whereNumber('contrato');
        // Formulario del fondo de pensión (COLPENSIONES y demás). La firma es la
        // misma del cliente, por eso no se duplica la ruta.
        Route::get('/{contrato}/formulario/pension', [$fc, 'vistaPension'])->name('formulario.pension')->whereNumber('contrato');
        Route::get('/{contrato}/formulario/pension/raw', [$fc, 'generarPension'])->name('formulario.pension.raw')->whereNumber('contrato');
    });

    // ── Tareas ───────────────────────────────────────────────────────────────
    Route::prefix('admin/tareas')->name('admin.tareas.')->middleware(['permiso:tareas.ver', 'permiso.escritura:tareas.gestionar'])->group(function () {
        $tc = \App\Http\Controllers\Admin\TareaController::class;
        Route::get('/', [$tc, 'index'])->name('index');
        Route::post('/', [$tc, 'store'])->name('store');
        Route::get('/reporte', [$tc, 'reporte'])->name('reporte');
        Route::put('/{id}', [$tc, 'update'])->name('update');
        Route::delete('/{id}', [$tc, 'destroy'])->name('destroy');
        Route::get('/{id}/show', [$tc, 'show'])->name('show');
        Route::post('/{id}/gestion', [$tc, 'gestion'])->name('gestion');
        Route::patch('/{id}/trasladar', [$tc, 'trasladar'])->name('trasladar');
        Route::patch('/{id}/cerrar', [$tc, 'cerrar'])->name('cerrar');
        Route::post('/{id}/documento', [$tc, 'subirDocumento'])->name('documento.store');
        Route::get('/documento/{docId}', [$tc, 'descargarDocumento'])->name('documento.download');
        Route::get('/api/clientes', [$tc, 'buscarCliente'])->name('api.clientes');
        Route::get('/api/contratos', [$tc, 'contratosPorCedula'])->name('api.contratos');
    });

    // ── Solicitudes del portal de empresas (se trabajan desde la tarea) ─────
    Route::prefix('admin/portal-solicitudes')->name('admin.portal_solicitudes.')->middleware(['permiso:tareas.ver', 'permiso.escritura:tareas.gestionar'])->group(function () {
        $es = \App\Http\Controllers\Admin\EmpresaSolicitudController::class;
        Route::get('/nuevas', [$es, 'nuevas'])->name('nuevas');
        Route::get('/{id}/archivo/{i}', [$es, 'archivo'])->whereNumber(['id', 'i'])->name('archivo');
        Route::get('/{id}/ruaf', [$es, 'ruaf'])->whereNumber('id')->name('ruaf');
        Route::post('/{id}/retiro', [$es, 'retiro'])->whereNumber('id')->name('retiro')
            ->middleware('permiso:facturacion.generar');
        Route::post('/{id}/incapacidad', [$es, 'incapacidad'])->whereNumber('id')->name('incapacidad')
            ->middleware('permiso:incapacidades.gestionar');
        Route::post('/{id}/rechazar', [$es, 'rechazar'])->whereNumber('id')->name('rechazar');
    });

    // ── Traslado Masivo de Razón Social ─────────────────────────────────────
    Route::prefix('admin/traslados-rs')->name('admin.traslados.')->middleware('permiso:traslados_rs.ejecutar')->group(function () {
        $trs = \App\Http\Controllers\Admin\TrasladoRazonSocialController::class;
        Route::get('/', [$trs, 'index'])->name('index');
        Route::post('/validar', [$trs, 'validar'])->name('validar');
        Route::post('/ejecutar', [$trs, 'ejecutar'])->name('ejecutar');
        Route::post('/retiro-opcion-a/previsualizar', [$trs, 'previsualizarOpcionA'])->name('retiro_a.previsualizar');
        Route::post('/retiro-opcion-a', [$trs, 'retirarOpcionA'])->name('retiro_a');
        Route::post('/retiro-opcion-b', [$trs, 'retirarOpcionB'])->name('retiro_b');
        Route::get('/descargar-plano', [$trs, 'descargarPlano'])->name('descargar_plano');
        Route::get('/descargar-excel', [$trs, 'descargarExcel'])->name('descargar_excel');
        Route::get('/api/n-planos/{id}', [$trs, 'apiNPlanosRs'])->name('api.n_planos');
    });

    // ── Incapacidades ────────────────────────────────────────────────────────
    Route::prefix('admin/incapacidades')->name('admin.incapacidades.')->middleware(['permiso:incapacidades.ver', 'permiso.escritura:incapacidades.gestionar'])->group(function () {
        $ic = \App\Http\Controllers\Admin\IncapacidadController::class;
        Route::get('/', [$ic, 'index'])->name('index');
        // Lo mismo que muestra la tabla, con los mismos filtros, en dos hojas:
        // originales y prórrogas.
        Route::get('/excel', [$ic, 'exportarExcel'])->name('excel');
        Route::post('/', [$ic, 'store'])->name('store');
        Route::put('/{id}', [$ic, 'update'])->name('update');
        Route::delete('/{id}', [$ic, 'destroy'])->name('destroy');
        Route::get('/{id}/show', [$ic, 'show'])->name('show');
        Route::post('/{id}/gestion', [$ic, 'storeGestion'])->name('gestion.store');
        Route::post('/{id}/documento', [$ic, 'storeDocumento'])->name('documento.store');
        Route::get('/documento/{docId}', [$ic, 'descargarDocumento'])->name('documento.download');
        Route::get('/documento/{docId}/ver', [$ic, 'verDocumento'])->name('documento.ver');
        Route::get('/{id}/documentos-familia', [$ic, 'documentosFamilia'])->name('documentos.familia');
        Route::post('/{id}/pago', [$ic, 'registrarPago'])->name('pago.store');
        Route::get('/api/calcular/{id}', [$ic, 'calcularValor'])->name('api.calcular');
        Route::get('/api/clientes', [$ic, 'apiClientes'])->name('api.clientes');
        Route::get('/api/contratos', [$ic, 'apiContratos'])->name('api.contratos');
        // Nuevas rutas
        Route::post('/{id}/link', [$ic, 'generarLink'])->name('link.generar');
        Route::post('/{id}/abono', [$ic, 'storeAbono'])->name('abono.store');
        Route::post('/{id}/prorroga', [$ic, 'storeProrroga'])->name('prorroga.store');
        // Vecinas por fechas (aviso al crear y modal de unir) + unir dos que
        // quedaron separadas.
        Route::get('/api/vecinas', [$ic, 'vecinas'])->name('api.vecinas');
        Route::post('/{id}/unir-prorroga', [$ic, 'unirProrroga'])->name('unir.prorroga');
        // Sacar una prórroga de su familia cuando quedaron encadenadas dos que
        // no son continuas. `unir-prorroga` ahora también MUEVE de familia.
        Route::post('/{id}/desligar-prorroga', [$ic, 'desligarProrroga'])->name('desligar.prorroga');
        // Cierre administrativo de una incapacidad que nunca entró a trámite
        // (el cliente no envió papeles, se creó por error...). Sin permiso
        // aparte: es reversible y queda en el timeline y en bitácora.
        Route::post('/{id}/anular', [$ic, 'anular'])->name('anular');
        Route::post('/{id}/reabrir', [$ic, 'reabrir'])->name('reabrir');
        Route::get('/{id}/cuentas-rs', [$ic, 'cuentasRazonSocial'])->name('cuentas.rs');
        // Deshacer el último cambio de estado y los movimientos de plata que
        // generó. Permiso aparte: ningún rol lo trae de fábrica, lo hereda el
        // superadmin por el Gate::before y se otorga a un admin desde
        // admin/usuarios/{id}/permisos.
        Route::get('/{id}/reversion/preview', [$ic, 'previewReversion'])
            ->name('reversion.preview')->middleware('permiso:incapacidades.revertir_estado');
        Route::post('/{id}/reversion', [$ic, 'revertirGestion'])
            ->name('reversion.store')->middleware('permiso:incapacidades.revertir_estado');
    });

    // -- Radicados
    Route::prefix('admin/radicados')->name('admin.radicados.')->middleware(['permiso:radicados.ver', 'permiso.escritura:radicados.gestionar'])->group(function () {
        $rc = \App\Http\Controllers\Admin\RadicadoController::class;
        Route::post('crear', [$rc, 'crearPendiente'])->name('crear');
        Route::patch('{id}', [$rc, 'update'])->name('update');
        Route::post('{id}/pdf', [$rc, 'subirPdf'])->name('pdf');
        Route::get('{id}/pdf/descargar', [$rc, 'descargarPdf'])->name('pdf.download');
        Route::patch('{id}/enviado', [$rc, 'marcarEnviado'])->name('enviado');
        Route::get('{id}/bitacora', [$rc, 'bitacora'])->name('bitacora');
        Route::get('{id}/documentos', [$rc, 'documentosCotizante'])->name('documentos');
    });

    // ─── Módulo WhatsApp ───────────────────────────────────────────────────────
    Route::prefix('admin/whatsapp')->name('admin.whatsapp.')->group(function () {
        $chat = \App\Http\Controllers\Admin\WhatsappChatController::class;
        $plantilla = \App\Http\Controllers\Admin\WhatsappPlantillaController::class;
        $masivo = \App\Http\Controllers\Admin\WhatsappMasivoController::class;
        $config = \App\Http\Controllers\Admin\WhatsappConfigController::class;

        // ── Chat: ver el inbox ────────────────────────────────────────────────
        // El id va numérico obligado: el controlador lo recibe como `int` y un
        // `/chat/null` —que el chat se pone solo en la barra de direcciones si
        // el JS pierde la conversación— reventaba con un 500 al recargar.
        Route::middleware('permiso:whatsapp.ver')->group(function () use ($chat) {
            Route::get('chat', [$chat, 'index'])->name('chat.index');
            Route::get('chat/{id}', [$chat, 'show'])->name('chat.show')->whereNumber('id');
            Route::get('chat/{id}/api-mensajes', [$chat, 'apiMensajes'])->name('chat.api_mensajes')->whereNumber('id');
            Route::get('chat/{id}/api-sidebar', [$chat, 'apiConversacionSidebar'])->name('chat.api_sidebar')->whereNumber('id');
            Route::get('chat/media/{mensajeId}', [$chat, 'descargarMedia'])->name('chat.media')->whereNumber('mensajeId');
            Route::get('api/no-leidos', [$chat, 'apiNoLeidos'])->name('api.no_leidos');
            Route::patch('chat/{id}/leer', [$chat, 'marcarLeido'])->name('chat.leer')->whereNumber('id');
        });
        // ── Chat: responder y operar la conversación ──────────────────────────
        Route::middleware('permiso:whatsapp.responder')->group(function () use ($chat) {
            Route::post('chat/{id}/mensaje', [$chat, 'enviarMensaje'])->name('chat.mensaje')->whereNumber('id');
            Route::patch('chat/{id}/toggle-bot', [$chat, 'toggleBot'])->name('chat.toggle_bot')->whereNumber('id');
            Route::patch('chat/{id}/cerrar', [$chat, 'cerrar'])->name('chat.cerrar')->whereNumber('id');
            Route::patch('chat/{id}/no-contactar', [$chat, 'noContactar'])->name('chat.no_contactar')->whereNumber('id');
            Route::post('chat/{id}/atendida', [$chat, 'marcarAtendida'])->name('chat.atendida')->whereNumber('id');
            Route::post('chat/{id}/reabrir', [$chat, 'reabrir'])->name('chat.reabrir')->whereNumber('id');
            Route::post('chat/{id}/acuse', [$chat, 'enviarAcuse'])->name('chat.acuse')->whereNumber('id');
        });
        Route::patch('chat/{id}/asignar', [$chat, 'asignar'])->name('chat.asignar')->middleware('permiso:whatsapp.asignar')->whereNumber('id');
        // Manual: devuelve al inbox general las asignadas con el cliente esperando hace horas.
        Route::post('chat/liberar-sin-atender', [$chat, 'liberarSinAtender'])->name('chat.liberar_sin_atender')->middleware('permiso:whatsapp.asignar');
        // Bandeja del número compartido: BryNex pasa la conversación al aliado dueño del contacto.
        Route::patch('chat/{id}/mover-aliado', [$chat, 'moverAliado'])->name('chat.mover_aliado')->middleware('permiso:whatsapp.asignar')->whereNumber('id');

        // ── Plantillas (admin del aliado) ─────────────────────────────────────
        Route::get('api/plantillas-aprobadas', [$plantilla, 'apiListarAprobadas'])->name('api.plantillas')->middleware('permiso:whatsapp.ver');
        Route::middleware('permiso:whatsapp.plantillas')->group(function () use ($plantilla) {
            Route::get('plantillas', [$plantilla, 'index'])->name('plantillas.index');
            Route::get('plantillas/crear', [$plantilla, 'create'])->name('plantillas.create');
            Route::post('plantillas', [$plantilla, 'store'])->name('plantillas.store');
            Route::get('plantillas/importar', [$plantilla, 'vistaImportar'])->name('plantillas.importar');
            Route::post('plantillas/importar', [$plantilla, 'procesarImportar'])->name('plantillas.importar.store');
            Route::get('plantillas/{id}/editar', [$plantilla, 'edit'])->name('plantillas.edit');
            Route::put('plantillas/{id}', [$plantilla, 'update'])->name('plantillas.update');
            Route::delete('plantillas/{id}', [$plantilla, 'destroy'])->name('plantillas.destroy');
            Route::post('plantillas/sincronizar', [$plantilla, 'sincronizar'])->name('plantillas.sincronizar');
        });

        // ── Envíos masivos (admin del aliado) ─────────────────────────────────
        Route::middleware('permiso:whatsapp.masivo')->group(function () use ($masivo) {
            Route::post('masivo/individual', [$masivo, 'lanzarIndividual'])->name('masivo.individual');
            Route::post('masivo/empresa', [$masivo, 'lanzarEmpresa'])->name('masivo.empresa');
            Route::get('masivo/historial', [$masivo, 'historial'])->name('masivo.historial');
            Route::get('masivo/{id}', [$masivo, 'detalle'])->name('masivo.detalle');
        });

        // ── Configuración: permiso RESTRINGIDO (credenciales de Meta) ─────────
        Route::middleware('permiso:whatsapp.configurar')->group(function () use ($config) {
            Route::get('configuracion', [$config, 'index'])->name('config.index');
            Route::get('configuracion/global', [$config, 'editGlobal'])->name('config.global');
            Route::post('configuracion/global', [$config, 'updateGlobal'])->name('config.global.update');
            Route::get('configuracion/{id}/editar', [$config, 'edit'])->name('config.edit');
            Route::put('configuracion/{id}', [$config, 'update'])->name('config.update');
            Route::get('configuracion/{id}/switch-and-go', [$config, 'switchAndGo'])->name('config.switch_and_go');
            Route::post('configuracion/{id}/copiar-plantilla', [$config, 'copiarPlantillaGlobal'])->name('config.copiar_plantilla');
            Route::post('configuracion/{id}/sincronizar-meta', [$config, 'sincronizarPlantillasMeta'])->name('config.sincronizar_meta');
            Route::post('configuracion/verificar', [$config, 'verificarWebhook'])->name('config.verificar');
        });
    });

    // ─── Módulo Marketing (envío masivo de campañas publicitarias) ─────────────
    Route::prefix('admin/marketing')->name('admin.marketing.')->middleware(['permiso:marketing.ver', 'permiso.escritura:marketing.gestionar'])->group(function () {
        $listas = \App\Http\Controllers\Admin\MarketingListaController::class;
        $campanas = \App\Http\Controllers\Admin\MarketingCampanaController::class;

        // ── Hub (punto de entrada desde la tarjeta del dashboard) ──────────────
        Route::get('/', [\App\Http\Controllers\Admin\MarketingHubController::class, 'index'])->name('index');

        // ── Listas de contactos ────────────────────────────────────────────────
        Route::get('listas', [$listas, 'index'])->name('listas.index');
        Route::get('listas/crear', [$listas, 'create'])->name('listas.create');
        Route::post('listas', [$listas, 'store'])->name('listas.store');
        Route::get('listas/{id}', [$listas, 'show'])->name('listas.show');
        Route::delete('listas/{id}', [$listas, 'destroy'])->name('listas.destroy');

        // ── Reactivación de retirados (solo informe; el envío va por comando) ──
        Route::get('reactivacion', [\App\Http\Controllers\Admin\MarketingReactivacionController::class, 'index'])->name('reactivacion');
        Route::post('reactivacion/enviar', [\App\Http\Controllers\Admin\MarketingReactivacionController::class, 'enviar'])->name('reactivacion.enviar');

        // ── Campañas y lanzamiento de tandas ───────────────────────────────────
        Route::get('campanas', [$campanas, 'index'])->name('campanas.index');
        Route::get('campanas/crear', [$campanas, 'create'])->name('campanas.create');
        Route::post('campanas', [$campanas, 'store'])->name('campanas.store');
        Route::get('campanas/{id}', [$campanas, 'show'])->name('campanas.show');
        Route::patch('campanas/{id}', [$campanas, 'update'])->name('campanas.update');
        Route::get('campanas/{id}/previsualizar', [$campanas, 'previsualizar'])->name('campanas.previsualizar');
        Route::post('campanas/{id}/lanzar-tanda', [$campanas, 'lanzarTanda'])->name('campanas.lanzar_tanda');
    });

    // ─── CMS ligero de la página web pública del aliado ────────────────────────
    Route::prefix('admin/pagina')->name('admin.pagina.')->middleware(['permiso:pagina_web.ver', 'permiso.escritura:pagina_web.editar'])->group(function () {
        $pag = \App\Http\Controllers\Admin\PaginaAliadoAdminController::class;

        Route::get('/', [$pag, 'edit'])->name('index');
        Route::post('/', [$pag, 'update'])->name('update');

        Route::get('faqs', [$pag, 'faqs'])->name('faqs.index');
        Route::post('faqs', [$pag, 'faqStore'])->name('faqs.store');
        Route::put('faqs/{id}', [$pag, 'faqUpdate'])->name('faqs.update');
        Route::delete('faqs/{id}', [$pag, 'faqDestroy'])->name('faqs.destroy');

        Route::get('leads', [$pag, 'leads'])->name('leads.index');
        Route::patch('leads/{id}', [$pag, 'leadUpdateEstado'])->name('leads.update');
    });

    // ─── Credenciales de redes sociales por aliado (Facebook, Instagram, ...) ──
    Route::prefix('admin/redes-sociales')->name('admin.redes-sociales.')->middleware(['permiso:redes_sociales.ver', 'permiso.escritura:redes_sociales.configurar'])->group(function () {
        $rs = \App\Http\Controllers\Admin\RedesSocialesController::class;

        Route::get('/', [$rs, 'edit'])->name('index');
        Route::post('{red}', [$rs, 'update'])->name('update');
        Route::post('{red}/probar', [$rs, 'probarConexion'])->name('probar');
    });

    // ─── Generador de publicidad (plantillas/canvas + IA, aprobación, publicación) ─────
    Route::prefix('admin/publicidad')->name('admin.publicidad.')->middleware(['permiso:publicidad.ver', 'permiso.escritura:publicidad.gestionar'])->group(function () {
        $pub = \App\Http\Controllers\Admin\PublicidadController::class;

        Route::get('/', [$pub, 'index'])->name('index');
        Route::get('crear', [$pub, 'create'])->name('create');
        Route::post('/', [$pub, 'store'])->name('store');

        // Piloto automático (antes de {id} para que no lo capture la ruta genérica)
        Route::get('autopilot', [$pub, 'autopilot'])->name('autopilot');
        Route::post('autopilot', [$pub, 'autopilotUpdate'])->name('autopilot.update');
        Route::post('autopilot/generar-ahora', [$pub, 'autopilotGenerarAhora'])->name('autopilot.generar_ahora');
        Route::post('autopilot/flyer-ahora', [$pub, 'flyerGenerarAhora'])->name('autopilot.flyer_ahora');

        // Pauta pagada — config antes de {id} por el mismo motivo
        Route::get('pauta/config', [$pub, 'pautaConfig'])->name('pauta.config');
        Route::post('pauta/config', [$pub, 'pautaConfigUpdate'])->name('pauta.config.update');

        Route::get('{id}', [$pub, 'show'])->whereNumber('id')->name('show');
        Route::delete('{id}', [$pub, 'destroy'])->whereNumber('id')->name('destroy');
        Route::post('{id}/aprobar', [$pub, 'aprobar'])->whereNumber('id')->name('aprobar');
        Route::post('{id}/rechazar', [$pub, 'rechazar'])->whereNumber('id')->name('rechazar');
        Route::post('{id}/reintentar/{red}', [$pub, 'reintentar'])->whereNumber('id')->name('reintentar');
        Route::post('{id}/pauta/crear', [$pub, 'pautaCrear'])->whereNumber('id')->name('pauta.crear');
        Route::post('{id}/pauta/activar', [$pub, 'pautaActivar'])->whereNumber('id')->name('pauta.activar');
        Route::post('{id}/pauta/pausar', [$pub, 'pautaPausar'])->whereNumber('id')->name('pauta.pausar');

        Route::post('generar-copia', [$pub, 'generarCopia'])->name('generar_copia');
        Route::post('generar-imagen', [$pub, 'generarImagen'])->name('generar_imagen');
        Route::post('subir-canvas', [$pub, 'subirCanvas'])->name('subir_canvas');

        // Video IA (Veo + overlay) — antes de {id} por el mismo motivo que autopilot/pauta
        Route::post('generar-video', [$pub, 'generarVideo'])->name('generar_video');
        Route::get('video/{id}/estado', [$pub, 'estadoVideo'])->whereNumber('id')->name('video.estado');
    });

    // ============================================
    // MÓDULO FINANZAS PERSONALES (BD separada)
    // ============================================
    Route::prefix('finanzas')->name('finanzas.')->middleware(['finanzas.access'])->group(function () {
        $ns = \App\Http\Controllers\Finanzas\FinanzasDashboardController::class;
        $ent = \App\Http\Controllers\Finanzas\EntradaController::class;
        $gas = \App\Http\Controllers\Finanzas\GastoController::class;
        $pre = \App\Http\Controllers\Finanzas\PrestamoController::class;
        $cc = \App\Http\Controllers\Finanzas\CuentaCorrienteController::class;
        $expd = \App\Http\Controllers\Finanzas\PrestamoExpedienteController::class;
        $inv = \App\Http\Controllers\Finanzas\InversionController::class;
        $pat = \App\Http\Controllers\Finanzas\PatrimonioController::class;
        $pro = \App\Http\Controllers\Finanzas\ProyectoController::class;
        $cta = \App\Http\Controllers\Finanzas\CuentaController::class;

        // Dashboard
        Route::get('/', [$ns, 'index'])->name('dashboard');

        // Dashboard API — endpoints AJAX para carga progresiva
        Route::prefix('api')->name('api.')->group(function () use ($ns) {
            Route::get('/resumen', [$ns, 'apiResumen'])->name('resumen');
            Route::get('/evolucion', [$ns, 'apiEvolucion'])->name('evolucion');
            Route::get('/consolidado', [$ns, 'apiConsolidado'])->name('consolidado');
            Route::get('/cuentas', [$ns, 'apiCuentas'])->name('cuentas');
            Route::get('/alertas', [$ns, 'apiAlertas'])->name('alertas');
            Route::get('/intereses-detalle', [$ns, 'apiInteresesDetalle'])->name('intereses-detalle');
        });

        // Cuentas / Bolsillos
        Route::get('/cuentas', [$cta, 'index'])->name('cuentas.index');
        Route::post('/cuentas', [$cta, 'store'])->name('cuentas.store');
        Route::put('/cuentas/{cuenta}', [$cta, 'update'])->name('cuentas.update');
        Route::delete('/cuentas/{cuenta}', [$cta, 'destroy'])->name('cuentas.destroy');
        Route::post('/cuentas/transferir', [$cta, 'transferir'])->name('cuentas.transferir');
        Route::delete('/cuentas/transferencia/{id}', [$cta, 'eliminarTransferencia'])->name('cuentas.transferencia.destroy');

        // Entradas / Fuentes
        Route::get('/entradas', [$ent, 'index'])->name('entradas.index');
        Route::post('/entradas', [$ent, 'store'])->name('entradas.store');
        Route::get('/entradas/detalle-esporadico', [$ent, 'getDetalleEsporadico'])->name('entradas.detalle-esporadico');
        Route::put('/entradas/esporadico/{id}', [$ent, 'updateEsporadico'])->name('entradas.update-esporadico');
        Route::delete('/entradas/esporadico/{id}', [$ent, 'destroyEsporadico'])->name('entradas.destroy-esporadico');
        Route::get('/fuentes', [$ent, 'fuentesIndex'])->name('fuentes.index');
        Route::post('/fuentes', [$ent, 'fuenteStore'])->name('fuentes.store');
        Route::put('/fuentes/{fuente}', [$ent, 'fuenteUpdate'])->name('fuentes.update');
        Route::delete('/fuentes/{fuente}', [$ent, 'fuenteDestroy'])->name('fuentes.destroy');

        // App Líderes / Otras App
        Route::get('/app-lideres', [\App\Http\Controllers\Finanzas\AppLiderController::class, 'index'])->name('app-lideres.index');
        Route::post('/app-lideres/aliados', [\App\Http\Controllers\Finanzas\AppLiderController::class, 'storeAliado'])->name('app-lideres.store-aliado');
        Route::post('/app-lideres/update', [\App\Http\Controllers\Finanzas\AppLiderController::class, 'updateAliado'])->name('app-lideres.update-aliado');
        Route::post('/app-lideres/save-cell', [\App\Http\Controllers\Finanzas\AppLiderController::class, 'saveCell'])->name('app-lideres.save-cell');
        Route::post('/app-lideres/recibos', [\App\Http\Controllers\Finanzas\AppLiderController::class, 'registrarRecibo'])->name('app-lideres.registrar-recibo');
        Route::delete('/app-lideres/recibos/{id}', [\App\Http\Controllers\Finanzas\AppLiderController::class, 'deleteRecibo'])->name('app-lideres.delete-recibo');
        Route::get('/app-lideres/recibos/{id}/soporte', [\App\Http\Controllers\Finanzas\AppLiderController::class, 'descargarSoporte'])->name('app-lideres.descargar-soporte');

        // Brynex Aliados
        Route::get('/brynex-aliados', [\App\Http\Controllers\Finanzas\BrynexAliadoController::class, 'index'])->name('brynex-aliados.index');
        Route::post('/brynex-aliados/aliados', [\App\Http\Controllers\Finanzas\BrynexAliadoController::class, 'storeAliado'])->name('brynex-aliados.store-aliado');
        Route::post('/brynex-aliados/update', [\App\Http\Controllers\Finanzas\BrynexAliadoController::class, 'updateAliado'])->name('brynex-aliados.update-aliado');
        Route::post('/brynex-aliados/save-cell', [\App\Http\Controllers\Finanzas\BrynexAliadoController::class, 'saveCell'])->name('brynex-aliados.save-cell');
        Route::post('/brynex-aliados/recibos', [\App\Http\Controllers\Finanzas\BrynexAliadoController::class, 'registrarRecibo'])->name('brynex-aliados.registrar-recibo');
        Route::delete('/brynex-aliados/recibos/{id}', [\App\Http\Controllers\Finanzas\BrynexAliadoController::class, 'deleteRecibo'])->name('brynex-aliados.delete-recibo');
        Route::get('/brynex-aliados/recibos/{recibo}/soporte', [\App\Http\Controllers\Finanzas\BrynexAliadoController::class, 'descargarSoporte'])->name('brynex-aliados.descargar-soporte');

        // Gastos / Categorías
        Route::get('/gastos', [$gas, 'index'])->name('gastos.index');
        Route::post('/gastos', [$gas, 'store'])->name('gastos.store');
        Route::put('/gastos/{gasto}', [$gas, 'update'])->name('gastos.update');
        Route::delete('/gastos/{gasto}', [$gas, 'destroy'])->name('gastos.destroy');
        Route::get('/gastos/informe', [$gas, 'informe'])->name('gastos.informe');
        Route::get('/gastos/{gasto}/soporte', [$gas, 'descargarSoporte'])->name('gastos.descargar-soporte');
        Route::get('/categorias', [$gas, 'categoriasIndex'])->name('categorias.index');
        Route::post('/categorias', [$gas, 'categoriaStore'])->name('categorias.store');
        Route::put('/categorias/{cat}', [$gas, 'categoriaUpdate'])->name('categorias.update');
        Route::delete('/categorias/{cat}', [$gas, 'categoriaDestroy'])->name('categorias.destroy');

        // Préstamos
        Route::get('/prestamos', [$pre, 'index'])->name('prestamos.index');
        Route::get('/prestamos/crear', [$pre, 'create'])->name('prestamos.create');
        Route::post('/prestamos', [$pre, 'store'])->name('prestamos.store');
        Route::get('/prestamos/{prestamo}', [$pre, 'show'])->name('prestamos.show');
        Route::get('/prestamos/{prestamo}/edit', [$pre, 'edit'])->name('prestamos.edit');
        Route::put('/prestamos/{prestamo}', [$pre, 'update'])->name('prestamos.update');
        Route::post('/prestamos/{prestamo}/pago', [$pre, 'registrarPago'])->name('prestamos.pago');
        Route::post('/prestamos/{prestamo}/anexar', [$pre, 'anexarValor'])->name('prestamos.anexar');
        Route::post('/prestamos/{prestamo}/whatsapp', [$pre, 'enviarWhatsapp'])->name('prestamos.whatsapp');
        Route::post('/prestamos/{prestamo}/toggle-alertas', [$pre, 'toggleAlertas'])->name('prestamos.toggle-alertas');
        Route::post('/prestamos/{prestamo}/castigar', [$pre, 'castigar'])->name('prestamos.castigar');
        Route::post('/prestamos/{prestamo}/reactivar', [$pre, 'reactivar'])->name('prestamos.reactivar');
        // Cuenta corriente de servicios: clientes recurrentes con varios trabajos.
        // La ruta vieja se conserva como redirección para no romper enlaces guardados.
        Route::get('/cuenta-corriente', [$cc, 'index'])->name('cuenta-corriente.index');
        Route::post('/cuenta-corriente/clientes', [$cc, 'storeCliente'])->name('cuenta-corriente.clientes.store');
        Route::get('/cuenta-corriente/{cliente}', [$cc, 'show'])->whereNumber('cliente')->name('cuenta-corriente.show');
        Route::put('/cuenta-corriente/{cliente}', [$cc, 'updateCliente'])->whereNumber('cliente')->name('cuenta-corriente.clientes.update');
        Route::delete('/cuenta-corriente/{cliente}', [$cc, 'destroyCliente'])->whereNumber('cliente')->name('cuenta-corriente.clientes.destroy');
        Route::post('/cuenta-corriente/{cliente}/trabajos', [$cc, 'storeTrabajo'])->whereNumber('cliente')->name('cuenta-corriente.trabajos.store');
        Route::post('/cuenta-corriente/{cliente}/abono', [$cc, 'abonoGeneral'])->whereNumber('cliente')->name('cuenta-corriente.abono');
        Route::post('/cuenta-corriente/{cliente}/liquidar', [$cc, 'liquidarCliente'])->whereNumber('cliente')->name('cuenta-corriente.liquidar');
        Route::post('/cuenta-corriente/{cliente}/whatsapp', [$cc, 'whatsapp'])->whereNumber('cliente')->name('cuenta-corriente.whatsapp');
        Route::put('/cuenta-corriente-trabajo/{trabajo}', [$cc, 'updateTrabajo'])->name('cuenta-corriente.trabajos.update');
        Route::delete('/cuenta-corriente-trabajo/{trabajo}', [$cc, 'destroyTrabajo'])->name('cuenta-corriente.trabajos.destroy');
        Route::post('/cuenta-corriente-trabajo/{trabajo}/pago', [$cc, 'pagarTrabajo'])->name('cuenta-corriente.trabajos.pago');
        Route::get('/prestamos-cuenta-corriente', fn () => redirect()->route('finanzas.cuenta-corriente.index'))->name('prestamos.cuenta-corriente');
        Route::post('/prestamos-movimiento/{movimiento}', [$pre, 'updateMovimiento'])->name('prestamos.movimiento.update');
        Route::delete('/prestamos-movimiento/{movimiento}', [$pre, 'destroyMovimiento'])->name('prestamos.movimiento.destroy');
        Route::post('/prestamos-pago/{movimiento}', [$pre, 'updatePago'])->name('prestamos.pago.update');
        Route::delete('/prestamos-pago/{movimiento}', [$pre, 'destroyPago'])->name('prestamos.pago.destroy');
        Route::get('/prestamos/{prestamo}/soporte', [$pre, 'descargarSoporte'])->name('prestamos.descargar-soporte');
        Route::get('/prestamos-movimiento/{movimiento}/soporte', [$pre, 'descargarSoporteMovimiento'])->name('prestamos.movimiento.descargar-soporte');

        // Préstamos formales: expediente legal, documentos para firmar y
        // desembolso. Mientras no se desembolsa no hay préstamo ni egreso.
        Route::get('/expedientes', [$expd, 'index'])->name('expedientes.index');
        Route::post('/expedientes', [$expd, 'store'])->name('expedientes.store');
        Route::get('/expedientes/{expediente}', [$expd, 'show'])->whereNumber('expediente')->name('expedientes.show');
        Route::get('/expedientes/{expediente}/editar', [$expd, 'edit'])->whereNumber('expediente')->name('expedientes.edit');
        Route::put('/expedientes/{expediente}', [$expd, 'update'])->whereNumber('expediente')->name('expedientes.update');
        Route::get('/expedientes/{expediente}/documentos/{formato?}', [$expd, 'descargarDocumentos'])->whereNumber('expediente')->whereIn('formato', ['pdf', 'word'])->name('expedientes.documentos');
        Route::get('/expedientes/{expediente}/firmados', [$expd, 'descargarFirmados'])->whereNumber('expediente')->name('expedientes.firmados');
        Route::post('/expedientes/{expediente}/firma', [$expd, 'registrarFirma'])->whereNumber('expediente')->name('expedientes.firma');
        Route::post('/expedientes/{expediente}/desembolsar', [$expd, 'desembolsar'])->whereNumber('expediente')->name('expedientes.desembolsar');
        Route::post('/expedientes/{expediente}/anular', [$expd, 'anular'])->whereNumber('expediente')->name('expedientes.anular');
        Route::post('/prestamistas/{prestamista?}', [$expd, 'guardarPrestamista'])->whereNumber('prestamista')->name('prestamistas.guardar');

        // Inversiones
        Route::get('/inversiones', [$inv, 'index'])->name('inversiones.index');
        Route::post('/inversiones', [$inv, 'store'])->name('inversiones.store');
        Route::put('/inversiones/{inv}', [$inv, 'update'])->name('inversiones.update');
        Route::delete('/inversiones/{inv}', [$inv, 'destroy'])->name('inversiones.destroy');
        Route::get('/inversiones/precio-usdt', [$inv, 'precioUsdt'])->name('inversiones.precio-usdt');
        Route::post('/inversiones/{inv}/movimientos', [$inv, 'storeMovimiento'])->name('inversiones.movimientos.store');

        // Patrimonio
        Route::get('/patrimonio', [$pat, 'index'])->name('patrimonio.index');
        Route::post('/patrimonio', [$pat, 'store'])->name('patrimonio.store');
        Route::get('/patrimonio/{pat}', [$pat, 'show'])->name('patrimonio.show');
        Route::put('/patrimonio/{pat}', [$pat, 'update'])->name('patrimonio.update');
        Route::post('/patrimonio/{pat}/gasto', [$pat, 'agregarGasto'])->name('patrimonio.gasto');

        // Proyectos
        Route::get('/proyectos', [$pro, 'index'])->name('proyectos.index');
        Route::post('/proyectos', [$pro, 'store'])->name('proyectos.store');
        Route::get('/proyectos/{proyecto}', [$pro, 'show'])->name('proyectos.show');
        Route::put('/proyectos/{proyecto}', [$pro, 'update'])->name('proyectos.update');
        Route::post('/proyectos/{proyecto}/movimiento', [$pro, 'agregarMovimiento'])->name('proyectos.movimiento');
        Route::delete('/proyectos-movimiento/{movimiento}', [$pro, 'eliminarMovimiento'])->name('proyectos.movimiento.destroy');
        Route::put('/proyectos-movimiento/{movimiento}', [$pro, 'actualizarMovimiento'])->name('proyectos.movimiento.update');
        Route::get('/proyectos-movimiento/{movimiento}/soporte', [$pro, 'descargarSoporteMovimiento'])->name('proyectos.movimiento.descargar-soporte');
    });
});
