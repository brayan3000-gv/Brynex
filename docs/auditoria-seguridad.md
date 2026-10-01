# Auditoría de seguridad — Brynex

**Fecha:** 31 de julio de 2026
**Alcance:** Laravel 10 / PHP 8.1, SQL Server, Blade + Alpine. 474 rutas, 45 controladores admin, 92 modelos.
**Contexto crítico:** el repositorio se auto-despliega a producción (brynex.co) y la base de datos local **es** la de producción. Todo lo versionado y accesible por web está publicado.
**Datos tratados:** cédulas, salarios, historias clínicas e incapacidades de afiliados colombianos → aplica **Ley 1581 de 2012** (datos sensibles de salud, art. 5–6).

**Estado:** correcciones aplicadas el 31/07/2026 en la rama `seguridad/correcciones`.

---

## Resumen

| Severidad | Total | Corregido | Pendiente |
|---|---|---|---|
| CRÍTICO | 6 | 6 | — |
| ALTO | 5 | 4 | 1 (A-2: estructural) |
| MEDIO | 6 | 5 | 1 (M-3: verificar `.env` del servidor) |
| BAJO | 3 | 2 | 1 (B-1: sin impacto) |

Los 6 críticos eran explotables sin autenticación o con una cuenta de aliado cualquiera. Todos están cerrados en código.

### Estado por hallazgo

| # | Hallazgo | Estado |
|---|---|---|
| C-1 | `public/tmp_fix_cedula.php` | ✅ eliminado |
| C-2 | `public/debug_saldos.php` | ✅ eliminado |
| C-3 | `public/fix_config_cache.php` | ✅ eliminado |
| C-1/C-3 bis | El mismo patrón **reapareció en cuentafacil.co** (`/var/www/cf`, mismo servidor): `diagnostico_storage.php` —que ejecutaba `unlink()`+`symlink()` sin autenticación—, `debug_schema.php` —que publicaba host y usuario del SQL Server— y `opcache_reset.php` | ✅ los tres eliminados (ago-2026) — ver [endurecimiento-servidor-2026-08.md](endurecimiento-servidor-2026-08.md) |
| C-4 | Documentos médicos públicos | ✅ disco privado + ruta autenticada — **falta correr la migración de archivos en el servidor** |
| C-5 | IDOR en Incapacidades | ✅ 12 accesos filtrados por aliado |
| C-5 bis | El mismo IDOR **reapareció en el formulario de EPS/pensión** (`FormularioEpsController`): ver el formulario con datos personales o sobrescribir la firma de un contrato de otro aliado cambiando el id | ✅ corregido (1-oct-2026) con `Contrato::paraTramite()` |
| C-6 | Login sin throttle / session fixation | ✅ `throttle:5,1` + `regenerate()` |
| A-1 | Rutas sin permisos | ✅ corregido (ago-2026) — catálogo de módulos + 96 puntos de control |
| A-2 | Sin aislamiento multi-tenant en modelos | ⬜ pendiente (estructural) |
| A-3 | Upload sin `mimes` | ✅ corregido |
| A-4 | Scripts de mantenimiento versionados | ✅ 25 eliminados |
| A-5 | Datos personales versionados | ✅ fuera de git (siguen en disco local) |
| M-1 | Token de WhatsApp en `.env.example` | ✅ vaciado — **falta rotarlo en Meta** |
| M-2 | `SESSION_SECURE_COOKIE` | ✅ documentado — **falta ponerlo en el `.env` del servidor** |
| M-3 | `APP_DEBUG=true` | ⬜ **verificar el `.env` del servidor** |
| M-4 | Mass assignment en `Empresa` | ✅ `$fillable` explícito |
| M-5 | XSS en flash de backups | ✅ escapado |
| M-6 | Portal público sin throttle | ✅ `throttle:10,1` — caducidad del token no aplicada (decisión de negocio) |
| B-1 | `whereRaw` con constantes | ⬜ no explotable, sin cambio |
| B-2 | `/logout` fuera de `auth` | ⬜ sin impacto real |
| B-3 | SVG en logo de aliado | ✅ eliminado de los mimes |

### Acciones pendientes fuera del código

1. **Ejecutar en el servidor:** `php artisan incapacidades:migrar-documentos --dry-run` y luego sin la bandera. Los documentos ya subidos siguen en el disco público del servidor; el código los sigue encontrando mientras tanto, pero siguen siendo accesibles por URL hasta que se muevan.
2. **Rotar** `WHATSAPP_WEBHOOK_VERIFY_TOKEN` en Meta.
3. **Revisar el `.env` del servidor:** `APP_DEBUG=false`, `APP_ENV=production`, `SESSION_SECURE_COOKIE=true`.
4. **Revocar** el GitHub PAT en texto plano de `.claude/settings.local.json`.

---

# CRÍTICOS

## C-1 — Script de migración de BD ejecutable desde internet

**Archivo:** [public/tmp_fix_cedula.php](public/tmp_fix_cedula.php)

```php
 * Acceder desde: https://brynex.co/tmp_fix_cedula.php?token=BryNex2026Fix
$token = $_GET['token'] ?? '';
if ($token !== 'BryNex2026Fix') { ... }
...
Schema::table('users', function (Blueprint $table) ... );
DB::table('migrations')->insert([...]);
```

**Impacto.** Cualquiera que conozca la URL modifica el esquema de la tabla `users` en producción y escribe en la tabla `migrations`. El token está escrito en un comentario del propio archivo, el archivo está **versionado en git**, y la URL completa aparece en texto plano. En caso de error el `catch` imprime `$e->getTraceAsString()` → rutas absolutas del servidor y estructura interna.

**Fix.** Borrar el archivo del repo y del servidor. Las migraciones se ejecutan por `php artisan migrate`, nunca por HTTP.

---

## C-2 — Datos financieros expuestos sin autenticación

**Archivo:** [public/debug_saldos.php](public/debug_saldos.php)

Sin ninguna verificación de identidad, imprime: saldos de `saldos_banco`, consignaciones y gastos por banco del aliado 2, totales por tipo y detalle de las cuentas 137, 141, 142, 145 y 199.

**Impacto.** `https://brynex.co/debug_saldos.php` devuelve la posición bancaria del aliado a cualquier visitante.

**Fix.** Borrar del repo y del servidor.

---

## C-3 — Limpieza de cachés de producción abierta a internet

**Archivo:** [public/fix_config_cache.php](public/fix_config_cache.php)

Ejecuta `config:clear`, `cache:clear`, `route:clear`, `view:clear` y `optimize:clear` sin autenticación. El propio archivo dice *"ELIMINAR ESTE ARCHIVO DESPUÉS DE USARLO"*.

**Impacto.** Denegación de servicio trivial: peticiones repetidas mantienen la app sin cachés compiladas y degradan el sitio. Además revela versiones y errores de Artisan.

**Fix.** Borrar del repo y del servidor.

---

## C-4 — Documentos médicos servidos públicamente, con la cédula en la ruta

**Archivos:** [IncapacidadController.php:946](app/Http/Controllers/Admin/IncapacidadController.php:946), [IncapacidadUploadController.php:107](app/Http/Controllers/IncapacidadUploadController.php:107)

```php
$ruta = $file->store("incapacidades/{$inc->aliado_id}/{$cedula}/{$id}", 'public');
```

El disco `public` se sirve por el symlink `public/storage` → los archivos quedan en
`https://brynex.co/storage/incapacidades/{aliado_id}/{cedula}/{incapacidad_id}/{archivo}`
**sin pasar por Laravel, sin sesión y sin ninguna comprobación.**

**Impacto.** Historias clínicas, epicrisis, exámenes y copias de cédula accesibles por URL directa. La ruta incluye la cédula, que es adivinable/enumerable: conocida la cédula de una persona, se puede iterar `aliado_id` e `incapacidad_id` (enteros pequeños) hasta dar con sus documentos. Son datos sensibles de salud bajo la Ley 1581; la exposición es notificable a la SIC.

**Fix.** Mover estos documentos al disco `local` (no servido) y entregarlos por una ruta autenticada que valide `aliado_id` y permiso, o por URL temporal firmada. Migrar los archivos ya almacenados y quitar la cédula de la ruta (usar el `incapacidad_id` o un UUID).

---

## C-5 — IDOR en todo el módulo de Incapacidades

**Archivo:** [IncapacidadController.php](app/Http/Controllers/Admin/IncapacidadController.php) — líneas 304, 436, 717, 794, 809, 880, 941, 982, 1117, 1221, 1239

```php
$inc = Incapacidad::findOrFail($id);   // sin filtro por aliado_id
```

Los módulos de Contratos, Facturación, Planos y Cobros **sí** filtran correctamente (`Contrato::where('aliado_id', $alidoId)->findOrFail($id)`). Incapacidades es la excepción: ninguno de sus 11 accesos por id restringe al aliado en sesión.

**Impacto.** Un usuario autenticado de cualquier aliado, cambiando el id en la URL, puede sobre incapacidades de **otros aliados**:
- ver el detalle completo, con cédula, diagnóstico y datos del cliente (línea 365);
- modificarlas (líneas 304, 436);
- registrar abonos y movimientos de dinero (línea 809);
- crear prórrogas (línea 880);
- y en `generarLink()` (línea 794) **obtener el token público de subida** de una incapacidad ajena, que es la llave del portal externo del punto C-6.

**Fix.** Aplicar en cada método el mismo patrón que ya usa ContratoController:
```php
$inc = Incapacidad::where('aliado_id', session('aliado_id_activo'))->findOrFail($id);
```

### C-5 bis — El mismo IDOR en el formulario de EPS y pensión (oct-2026)

**Archivo:** [FormularioEpsController.php](app/Http/Controllers/Admin/FormularioEpsController.php), rutas en [web.php:1125-1131](routes/web.php:1125).

Los cinco métodos recibían `Contrato $contrato` por *route model binding* implícito, que busca el registro solo por id y no mira el aliado. Un usuario autenticado de cualquier aliado, cambiando el id en `/admin/afiliaciones/{contrato}/formulario/...`, podía en un contrato de **otro aliado**:
- ver el formulario de afiliación de la EPS o del fondo de pensión, que lleva nombre, cédula, dirección, salario y beneficiarios (`formulario/eps`, `formulario/pension` y sus `/raw`);
- **sobrescribir la firma** del cliente (`POST formulario/eps/firma`). Esa firma se guarda por cédula en `storage/app/firmas/{cedula}.png` y se dibuja en los formularios que se mandan a las EPS por correo y por los portales.

**Fix** (commit `6c9dac59`, 1-oct-2026). Cada método recibe el id y resuelve el contrato con `Contrato::paraTramite($id)`: da 404 si el contrato no es del aliado activo, salvo para un usuario `es_brynex` que tenga permitido el aliado del contrato. Es la misma regla de los controladores que enlazan a estas rutas: los modales de S.O.S., Sanitas y correo a la EPS, y el detalle de afiliaciones. Por eso quien abre el modal también puede abrir el formulario. Las rutas además exigen id numérico (`whereNumber('contrato')`).

**Lección.** El *binding* implícito `Modelo $modelo` en un controlador del panel es un IDOR por defecto en este proyecto: no hay scope global por aliado (A-2). Para cualquier registro de un aliado hay que recibir el id y resolverlo con un método que filtre, como `Contrato::paraTramite()` o `Radicado::paraTramite()`.

---

## C-6 — Login sin límite de intentos y sin regenerar la sesión

**Archivo:** [Auth/LoginController.php:20-41](app/Http/Controllers/Auth/LoginController.php:20)

La ruta `POST /login` ([routes/web.php:39](routes/web.php:39)) no tiene `throttle`. `RateLimiter` solo está configurado para el grupo `api` ([RouteServiceProvider.php:27](app/Providers/RouteServiceProvider.php:27)), que en este proyecto tiene una sola ruta.

**Impacto.** Fuerza bruta sin restricción alguna. Y el identificador de acceso es la **cédula**, no un correo: en Colombia es un dato semi-público y de formato predecible, lo que reduce muchísimo el espacio de búsqueda. No hay bloqueo por intentos ni por IP.

Además, tras `Auth::login()` no se llama a `$request->session()->regenerate()` → **session fixation**: un atacante que consiga fijar un id de sesión en el navegador de la víctima conserva la sesión ya autenticada.

**Fix.**
```php
Route::post('/login', [LoginController::class, 'login'])
    ->middleware('throttle:5,1');
```
y añadir `$request->session()->regenerate();` inmediatamente después de `Auth::login(...)`.

---

# ALTOS

## A-1 — Ninguna ruta protegida por permisos (473 de 474)

**Archivo:** [routes/web.php](routes/web.php)

Spatie Permission está instalado y los alias `role`, `permission` y `role_or_permission` están registrados en [Kernel.php:70-72](app/Http/Kernel.php:70). Se usan en **una sola ruta**:

```php
Route::prefix('admin/traslados-rs')->middleware('role:superadmin|admin')  // línea 579
```

Todo lo demás cuelga de `Route::middleware('auth')` (línea 93): facturación, planos, cobros, informes, comisiones, préstamos, marketing, publicidad, configuración, usuarios.

Hay verificaciones dentro de ~20 controladores, pero es cobertura parcial y desigual: dependen de que cada método las repita.

**Impacto.** Cualquier usuario autenticado —un asesor, un auxiliar— puede invocar directamente por URL cualquier acción de cualquier módulo. La única barrera real es que el menú no muestre el enlace.

**Fix aplicado (agosto 2026).** Se montó un catálogo de módulos en vez de una lista suelta de permisos:

- Tabla `modulos` (41 módulos en 6 grupos) + columnas `modulo_id`, `etiqueta`, `accion`, `restringido` en `permissions`. 107 permisos con nombre `modulo.accion`, sembrados por `ModulosPermisosSeeder`.
- 96 puntos de control en `routes/web.php` con los middleware `permiso:` y `permiso.escritura:` (`VerificarPermiso` / `VerificarPermisoEscritura`): mensaje legible, registro en bitácora como `acceso_denegado`, y JSON si la petición es AJAX.
- `Gate::before` en `AuthServiceProvider`: superadmin recibe todo **menos** los permisos `restringido`, y los módulos `solo_brynex` exigen `es_brynex`.
- Permisos restringidos (no los hereda ningún rol, se otorgan usuario por usuario en `admin/usuarios/{id}/permisos`): contraseñas de claves de acceso, credenciales de operadores PILA, credenciales de Meta, tokens de redes sociales y backup de la BD.
- El sidebar pasó de `@role` a `@can`, y el rol duplicado `contador` se unificó en `contable`.

Queda fuera del alcance de este fix: el rol `asesor` sigue viendo todo el aliado en solo lectura porque no existe vínculo `users` ↔ `asesores`, y el rol `cliente` no tiene portal. Ver [[permisos-brynex]].

---

## A-2 — Sin aislamiento multi-tenant a nivel de modelo

**Archivo:** [app/Models/BaseModel.php](app/Models/BaseModel.php)

91 de 92 modelos extienden `BaseModel`, que **solo normaliza fechas de SQL Server**. No hay `BelongsToAliado`, ni global scope, ni trait que filtre por `aliado_id`.

**Impacto.** El aislamiento entre aliados depende de que cada una de las cientos de consultas recuerde escribir `->where('aliado_id', ...)`. C-5 es exactamente lo que pasa cuando alguien lo olvida. Es una fuga estructural, no un bug puntual: cada método nuevo es otra oportunidad de repetirla.

**Fix.** Trait `PerteneceAAliado` con un `addGlobalScope` que filtre por `session('aliado_id_activo')`, aplicado a los modelos con columna `aliado_id`, más un scope explícito `sinFiltroAliado()` para los casos legítimos de BryNex. Se puede introducir de forma incremental.

---

## A-3 — Subida de archivos sin restricción de tipo

**Archivo:** [IncapacidadController.php:937](app/Http/Controllers/Admin/IncapacidadController.php:937)

```php
'archivo' => 'required|file|max:15360',   // sin mimes:
```

El resto de uploads del proyecto sí valida (`mimes:pdf,jpg,jpeg,png,webp`). Este no, y guarda en el disco `public`.

**Impacto.** Se puede subir `.html`, `.svg` o `.php` a una ruta servida por el servidor web. HTML/SVG → XSS almacenado en el dominio de la aplicación, con acceso a las cookies de sesión. `.php` → ejecución remota de código si el servidor web procesa PHP bajo `/storage` (depende de la config de nginx; hay que verificarlo en el servidor).

**Fix.** Añadir `mimes:pdf,jpg,jpeg,png,webp` y mover al disco `local` (ver C-4).

---

## A-4 — 25 scripts de mantenimiento versionados en la raíz

**Archivos versionados:** `tmp_check_users.php`, `tmp_habilitar_usuario_7154104.php`, `tmp_crear_permiso_planos.php`, `tmp_fix_migrations.php`, `tmp_debug_c3.php`, `tmp_diag_tp.php`, `tmp_inspect.php`, `migrate_afiliacion.php`, `diagnostico_bancos.php`, `diag_empresa9.php`, `check_factura.php`, `inspect_legacy.php`, `compare-data.php`, `compare-years-brayan-gastos.php`, `run_script.php`, `print-sheet-names.php`, `test_fpdf.php` + duplicados ` 2`.

**Impacto.** Se despliegan a producción en cada sincronización. Fuera de `public/` no son invocables por HTTP hoy, pero conceden capacidades peligrosas (crear permisos, habilitar usuarios, tocar `migrations`) a cualquiera con acceso al servidor, y basta un cambio de docroot o una copia mal ubicada para que queden expuestos. `run_script.php` ejecuta código arbitrario pasado por parámetro.

**Fix.** Borrarlos. Lo que se necesite conservar, convertirlo en comando Artisan bajo `app/Console/Commands/`.

---

## A-5 — Datos personales reales versionados en git

**Archivos:** `cedulas_legacy.txt` (17 KB de cédulas reales), `IndividualesCertificado_86667957_20260706-033151-852.pdf`, `Brayan_Garcia_2026.xlsx`, `ELITES_CREACIONES_7_2026_P8 (1).xlsx`, `meta_templates.log` (84 KB), `output.txt` (incluye credenciales de conexión en el mensaje de error), y 6 PDFs de prueba.

**Impacto.** Quedan en el historial de git para siempre y se despliegan al servidor. Si el repositorio se vuelve público o se comparte con un colaborador, se filtra un padrón de cédulas.

**Fix.** Borrar del working tree, añadir a `.gitignore` (`*.xlsx`, `*.pdf` en raíz, `*.log`, `cedulas_legacy.txt`). Purgar el historial con `git filter-repo` solo si el repo va a compartirse; si es privado y de un solo usuario, basta con dejar de versionarlos.

---

# MEDIOS

## M-1 — Token de WhatsApp real en `.env.example`

[.env.example:69](.env.example:69) → `WHATSAPP_WEBHOOK_VERIFY_TOKEN=brynex_wh_secret_2026`. Un `.env.example` es plantilla pública; los valores deben ir vacíos. Rotar el token en Meta y dejar `WHATSAPP_WEBHOOK_VERIFY_TOKEN=`.

## M-2 — `SESSION_SECURE_COOKIE` sin definir

[config/session.php:171](config/session.php:171) usa `env('SESSION_SECURE_COOKIE')`, que no aparece en `.env` ni en `.env.example` → resuelve a `null` → la cookie de sesión viaja **sin flag `Secure`**. En HTTPS es interceptable en un downgrade a HTTP. Añadir `SESSION_SECURE_COOKIE=true` en producción y `SESSION_SAME_SITE=lax`.

## M-3 — `APP_DEBUG=true` y `APP_ENV=local` contra la BD de producción

[.env:2-4](.env:2). El entorno local apunta a la BD real: cualquier excepción muestra el stack trace de Ignition con credenciales de conexión y fragmentos de consulta. Verificar además el `.env` **del servidor** — si allí también está en `true`, sube a CRÍTICO.

## M-4 — Mass assignment abierto en `Empresa`

[app/Models/Empresa.php:10](app/Models/Empresa.php:10) → `protected $guarded = [];`. Cualquier `Empresa::create($request->all())` o `->update($request->all())` permite escribir cualquier columna, incluida `aliado_id` — es decir, mover una empresa a otro aliado desde el formulario. Declarar `$fillable` explícito.

## M-5 — XSS reflejado en mensajes flash sin escapar

[brynex/backups.blade.php:45](resources/views/brynex/backups.blade.php:45) → `{!! session('error') !!}`, alimentado en [BrynexBackupController.php:168](app/Http/Controllers/BrynexBackupController.php:168) con `$e->getMessage()` de SQL Server. El contenido del error no está bajo control de la app. Cambiar a `{{ }}`. Los otros 7 usos de `{!! !!}` revisados generan HTML fijo en el servidor y son seguros.

## M-6 — Portal público de incapacidades: token sin caducidad, verificación débil

[IncapacidadUploadController.php](app/Http/Controllers/IncapacidadUploadController.php). El `token_subida` no expira nunca y el segundo factor es la cédula del propio titular — un dato de bajo secreto. No hay `throttle` en la ruta ([web.php:44-45](routes/web.php:44)), así que la cédula se puede adivinar por fuerza bruta sobre un token conocido. Combinado con C-5 (`generarLink` sin filtro de aliado), un usuario de otro aliado obtiene el token y entra. Añadir caducidad al token, `throttle:10,1` a la ruta y limitar los intentos de verificación de cédula.

---

# BAJOS

- **B-1** — `whereRaw` con interpolación en [ComisionesController.php:584,600](app/Http/Controllers/Admin/ComisionesController.php:584). Las variables son constantes de clase (`self::CORTE_ANIO`), así que **no es inyectable**; queda como mala práctica que invita a copiarse mal. Pasar a bindings.
- **B-2** — `POST /logout` fuera del grupo `auth` ([web.php:40](routes/web.php:40)). Protegido por CSRF; sin impacto real.
- **B-3** — El logo de aliado acepta SVG ([ConfiguracionAliadoController.php:131](app/Http/Controllers/Admin/ConfiguracionAliadoController.php:131)) y se guarda en disco público. Un SVG con `<script>` es XSS si se abre directamente. Quitar `svg` de los mimes o sanitizarlo.

---

# Verificado y correcto

Para que no se re-audite:

- **Inyección SQL**: revisados los 114 usos de raw en `InformeController` y el resto del proyecto. Ninguno interpola entrada del usuario. `BrynexBackupController` construye el `BACKUP DATABASE` con el nombre de conexión y `env('BACKUP_PATH')`, y exige `es_brynex && superadmin`.
- **Path traversal en descarga de backups**: [BrynexBackupController.php:109-113](app/Http/Controllers/BrynexBackupController.php:109) aplica `basename()` y además rechaza `..`, `/` y `\`. Correcto.
- **Aislamiento por aliado** en Contratos, Facturación, Planos, Cobros y Comisiones: filtran por `aliado_id` de forma consistente.
- **Validación de uploads** en Finanzas, Gastos, Razón Social y el portal público: `mimes` + `max` correctos.
- **CSRF**: activo globalmente en el grupo `web`; sin excepciones en `$except`.
- **Hashing de contraseñas**: `password_verify` contra hash bcrypt, comparación en tiempo constante.
- **Mensaje de login genérico** ("Cédula o contraseña incorrectos") → sin enumeración de usuarios.
- **Rutas públicas** (`/aliado/{slug}`, cotizador, leads, métricas): con `throttle` y control de visibilidad en el controlador.

---

# Orden de ejecución sugerido

**Tanda 1 — inmediata, sin riesgo de romper nada** (C-1, C-2, C-3, A-4, A-5, M-1)
Borrar los 3 PHP de `public/`, los 25 scripts de la raíz y los archivos de datos; rotar el token de WhatsApp. Es borrado puro: nada del código de la app los referencia.

**Tanda 2 — parches acotados** (C-6, A-3, M-2, M-4, M-5, B-3)
Throttle + `session()->regenerate()` en login, `mimes` en el upload de incapacidades, `SESSION_SECURE_COOKIE`, `$fillable` en `Empresa`, escapar el flash de backups. Cambios de pocas líneas, cada uno en su commit.

**Tanda 3 — IDOR de incapacidades** (C-5)
11 consultas en un archivo, patrón idéntico al que ya usa ContratoController. Requiere probar el módulo completo después.

**Tanda 4 — documentos médicos fuera del disco público** (C-4, M-6)
Cambiar el disco, crear la ruta autenticada de descarga, migrar los archivos existentes y actualizar las vistas que enlazan `asset('storage/...')`. Es el cambio con más superficie.

**Tanda 5 — estructural** (A-1 ✅ hecho, A-2 pendiente)
Matriz de permisos por rol y trait de aislamiento por aliado. Proyecto aparte, módulo por módulo.

Las tandas 1 a 4 son las que cierran la exposición real. La 5 es la que evita que vuelva a abrirse.
