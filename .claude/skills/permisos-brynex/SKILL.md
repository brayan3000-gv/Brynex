---
name: permisos-brynex
description: >
  Modelo de roles, permisos y multi-tenancy por aliado en Brynex. Actívate
  cuando el usuario mencione: rol, permiso, Spatie, superadmin, es_brynex,
  aliado activo, cambiar de aliado, quién puede ver esto, autorización,
  middleware de auth, SetAlidoContext, aliado_user.
---

# Skill: Roles, Permisos y Multi-tenancy — Brynex

## Catálogo de módulos: el permiso es `modulo.accion`

Desde agosto-2026 el control de acceso NO es por rol en la ruta, es por
permiso de módulo. La tabla `modulos` (modelo `App\Models\Modulo`) es el
catálogo: 41 módulos en 6 grupos, cada uno agrupando los permisos de Spatie
que empiezan por su `codigo` (`facturacion.ver`, `facturacion.anular`, …).
107 permisos en total, sembrados por `ModulosPermisosSeeder`.

**No confundir `modulos` con `brynex_modulos`.** La primera decide quién ve
qué; la segunda es la tabla de FACTURACIÓN de Brynex al aliado (cuánto le
cobro a GiMave por WhatsApp). Se cruzan por `modulos.modulo_brynex_codigo`.

### Los 6 roles

```
superadmin → todo, MENOS los permisos restringidos (vía Gate::before)
admin      → operación y financiero completos, pero NO configuración del
             aliado, NO crear usuarios, NO auditoría/traslados, y NO tocar
             los datos de fondo de un contrato ya radicado
contable   → solo lectura + exportar de lo financiero (+ emitir factura
             electrónica). Reemplaza a `contador`, que fue eliminado.
usuario    → trabajador del aliado: día a día completo (clientes, contratos,
             facturar, cobrar, incapacidades, tareas, whatsapp, planos,
             marketing, publicidad). Sin informes ni comisiones.
asesor     → HOY solo lectura. Pendiente: no existe vínculo users↔asesores,
             así que no se puede filtrar "solo sus clientes".
cliente    → sin permisos y sin uso. Las EMPRESAS cliente entran por otro lado:
             el portal de empresas (ver sección abajo), que no usa roles.
```

### `asignable`: qué se ve en la pantalla de permisos

De los 107 permisos, solo **36** se pintan en `admin/usuarios/{id}/permisos`.
La regla la aplica el seeder sola:

```php
$asignable = $restringido || ! in_array('U', $roles);
```

**Si el rol `usuario` ya lo trae, no se muestra.** El sistema solo otorga
permisos, nunca los revoca, así que esa casilla estaría siempre marcada y en
gris: no hay nada que decidir. Los ~70 permisos del día a día (ver clientes,
afiliar, radicar, cobrar, incapacidades, cotizar, claves de acceso) quedan
fuera del formulario pero siguen existiendo y siendo exigidos por el
middleware igual que antes.

Lo que sí se pinta: lo que solo tiene admin (anular facturas, planos SS,
gestionar razones sociales, reasignar tareas), lo que no trae nadie
(`contratos.editar_radicado`, `usuarios.gestionar`) y los restringidos.

El grupo `brynex` tampoco se pinta: esos permisos son de la empresa dueña de
la plataforma y se reparten con `permisos:aplicar-inicial`.

**Si agregas un permiso y quieres que salga en el formulario, no se lo des al
rol `usuario`.** Esa es toda la palanca.

### La columna "quién lo tiene ya"

A la derecha de cada permiso, la pantalla lista los usuarios activos del mismo
aliado que ya lo tienen (gris = por el rol, ★ morado = otorgado a mano, azul =
el usuario que se está editando). Sin eso, habilitar algo es a ciegas.

Lo calcula `UsuarioPermisoController::quienTiene()` **por conjuntos, no con
`can()` por casilla**: replica a mano las dos reglas del `Gate::before`. Dos
cosas a respetar si tocas esto:

1. **Si cambias el `Gate::before`, cambia también `quienTiene()`** o la
   columna mentirá. Hay un contraste fácil: recorrer el mapa y compararlo
   contra `$u->can($permiso)` — debe dar cero diferencias.
2. **Lo caro son los viajes a la BD, no el CPU.** El servidor SQL es remoto y
   cada consulta cuesta ~235 ms; la primera versión hacía 14 y tardaba 3,4 s.
   Por eso el equipo se carga una sola vez con `roles.permissions` y de ahí
   sale todo, incluidos los datos del usuario editado.

### Permisos restringidos: la pieza clave

Un permiso marcado `restringido` **no lo hereda ningún rol, ni superadmin**.
Solo se otorga usuario por usuario en `admin/usuarios/{id}/permisos`
(`UsuarioPermisoController`). Es el escalón de arriba de `asignable`: no
basta con que ningún rol lo traiga, es que **ni el superadmin lo hereda**.
Se reserva para credenciales. Hoy son 4:

```
whatsapp.configurar                operadores_planilla.credenciales
redes_sociales.configurar          brynex_backup.ejecutar
```

Ojo: `claves_acceso.*` (EPS, ARL, cajas, operadores por cliente) **no** es
restringido — el trabajador las necesita para afiliar, así que las trae el rol
`usuario` completas, contraseña incluida. Si algún día hay claves de BANCOS,
ese sí sería un módulo aparte y restringido.

### Cómo proteger una ruta nueva

```php
// Entrada al módulo
->middleware('permiso:facturacion.ver')

// Toda escritura del grupo, sin repetir línea por línea
->middleware(['permiso:tareas.ver', 'permiso.escritura:tareas.gestionar'])

// Acción puntual más restrictiva (se acumula con la del grupo)
->middleware('permiso:facturacion.anular')
```

`permiso` es `VerificarPermiso`: da un 403 con mensaje legible ("No tienes
permiso para «Anular facturas (Facturación)»…"), lo registra en bitácora
como `acceso_denegado`, y responde JSON si la petición es AJAX.
`permiso.escritura` es el mismo pero solo actúa en POST/PUT/PATCH/DELETE.

**No uses `role:` ni `hasRole()` en código nuevo.** El único uso legítimo que
queda de `hasRole('superadmin')` es junto a `es_brynex` para lo de BryNex.

### En las vistas

`@can('modulo.ver')` / `@canany([...])`. El sidebar de `layouts/app.blade.php`
ya está migrado entero; no quedan `@role` ahí.

### Centro de Configuración: se entra con cualquiera de sus permisos

`admin/configuracion` (el hub) no pide `configuracion.ver`, pide el Gate
`entrar-configuracion` (`AuthServiceProvider::PERMISOS_CONFIGURACION`): basta con
uno de los permisos que se otorgan a mano y que viven allá (cuentas bancarias,
razones sociales, usuarios, facturación electrónica…). El hub solo pinta las
tarjetas cuyo permiso de ruta tiene el usuario. **Si agregas una tarjeta con un
permiso asignable nuevo, súmalo a esa lista**, o quien lo reciba suelto no tendrá
por dónde llegar. No meter ahí permisos que ya trae el rol `usuario`: abriría
Configuración a todos los trabajadores.

`ConfiguracionAliadoController` ya no tiene `role:superadmin|admin` en el
constructor (sep-2026): cada ruta trae su permiso. Si ves otro controlador con
un `role:` en el constructor, sospecha lo mismo: un permiso otorgado a mano no
sirve de nada si el rol lo bloquea antes.

### Reglas que no caben en una ruta

Tres casos dependen del registro, no de la URL, y viven en el controlador:

- `contratos.editar_radicado` — si el contrato tiene radicado en trámite u OK,
  salario, IBC, entidades, fechas, plan y razón social quedan congelados para
  quien no tenga el permiso (`ContratoController::update`). Afecta ~25% de los
  contratos.
- `cuentas_bancarias.crear_incapacidad` — el rol `usuario` puede crear cuentas,
  pero se le fuerza `incapacidad=1`, `cobro=0`, `facturacion=0`
  (`ConfiguracionAliadoController::storeCuenta`).
- `claves_acceso.ver_contrasena` — el controlador reemplaza la contraseña por
  `'__oculta__'` antes de mandarla a la vista o al JSON. Ojo: taparla solo en
  el Blade no sirve, los endpoints la devolvían en claro.

### Puesta en marcha

`php artisan permisos:aplicar-inicial` (en seco) y `--ejecutar` para aplicar:
asigna rol `usuario` a los activos sin rol y entrega los restringidos al dueño.

## Portal de empresas: guard aparte, no un rol

Una empresa cliente entra a `brynex.co/portal` con su NIT por el MISMO login
(`LoginController` prueba primero `users.cedula` y, si no, `empresa_accesos`).
Se autentica en el guard **`empresa`** (modelo `EmpresaAcceso`, tabla
`empresa_accesos`), nunca en `web`:

- Ninguna ruta de `/admin` la deja pasar: todas usan `auth` = guard `web`.
- `auth:empresa` corre ANTES que los middleware del grupo `web` (prioridad de
  Authenticate) y cambia el guard por defecto. Por eso `SetAlidoContext` y
  `ExigirAvisoTratamiento` usan `Auth::guard('web')` explícito. **Un middleware
  nuevo del grupo `web` que lea el usuario debe hacer lo mismo**, o tratará a la
  empresa como si fuera del equipo.
- No tiene aliado en sesión: el portal filtra con `aliado_id`/`empresa_id` del
  acceso y las cédulas de `clientes.cod_empresa` (`EmpresaAcceso::cedulas()`).
- Crear, restablecer clave o desactivar: tarjeta en «Editar empresa», permiso
  `facturacion.portal_empresas` (sin rol; el superadmin lo tiene por Gate::before).
- Los valores salen de `EmpresaPeriodoService`, el mismo que pinta
  `admin/facturacion/empresa/{id}`: si cambia un cálculo, cambia en los dos.
- **Trámites** (`/portal/tramites`): la empresa pide ingresos, retiros,
  incapacidades u otras cosas. Nada se ejecuta solo: cada envío crea una
  `EmpresaSolicitud` y una tarea (`tareas.empresa_id`, tipos `portal_*`) para el
  encargado de la empresa. El equipo la resuelve desde el detalle de la tarea
  (`EmpresaSolicitudController`); el ingreso se aprueba al crear el contrato con
  `?solicitud=`. La empresa solo ve las gestiones con `visible_empresa = 1` y
  no ve la descripción de las tareas internas. Los adjuntos van al disco `local`.

## `es_brynex`: no es un rol, es un flag de identidad

```php
// users.es_brynex (boolean)
```

Distingue **quién es empleado de BryNex (la empresa dueña de la plataforma)**
de quién es empleado de un aliado (cliente de la plataforma). No sustituye a
los roles de Spatie — un usuario puede ser `es_brynex=true` Y tener rol
`asesor`. Se usa para dos cosas:

1. **Multi-aliado**: solo un usuario `es_brynex` puede tener acceso a más de
   un aliado (tabla pivote `aliado_user`, con `rol` y `activo` propios del
   pivote — distinto de los roles de Spatie).
2. Checks de "solo BryNex" repartidos en controladores, ej.
   `BrynexBackupController`: `$user->es_brynex && $user->hasRole('superadmin')`.

## Aliado activo en sesión — cómo se resuelve en cada request

`SetAlidoContext` (middleware del grupo `web`, ver `app/Http/Kernel.php`) corre
en cada petición autenticada:

```php
// 1. Si es_brynex=true y viene ?aliado=ID en la URL → cambia el aliado activo
//    (solo si User::puedeAccederAliado($id) lo permite)
// 2. Si no hay aliado en sesión → usa el aliado_id propio del usuario
// 3. Comparte 'alidoActivo' con TODAS las vistas
```

`User::puedeAccederAliado(int $alidoId)`:
- El aliado propio (`aliado_id`) siempre es accesible.
- `es_brynex` + `superadmin` → cualquier aliado activo.
- `es_brynex` sin superadmin → solo los aliados asignados en `aliado_user`
  con `pivot.activo = true`.
- Usuario normal (no `es_brynex`) → nunca puede cambiar de aliado.

**Todo query multi-tenant debe filtrar por `session('aliado_id_activo')`**,
no por `Auth::user()->aliado_id` directamente — un usuario BryNex puede estar
operando sobre un aliado distinto al suyo. Ver `IncapacidadController` (tras
la corrección de C-5) como referencia del patrón correcto.

## No existe un scope global de aislamiento

91 de 92 modelos extienden `BaseModel`, que solo normaliza fechas — no hay
`addGlobalScope` que filtre por aliado automáticamente. Cada query nueva debe
recordar el `->where('aliado_id', session('aliado_id_activo'))` a mano. Ver
`docs/auditoria-seguridad.md`, hallazgo A-2, para el plan de introducir un
trait que lo automatice.
