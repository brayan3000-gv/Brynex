---
name: razones-sociales-brynex
description: >
  Módulo de BryNex para administrar razones sociales: obligaciones tributarias ante la DIAN,
  claves de portales, afiliados y movimientos de dinero consolidados por NIT.
  Actívate cuando el usuario mencione: razón social de BryNex, ficha maestra, régimen simple,
  RST, régimen ordinario, DIAN, exógena, retención en la fuente, IVA bimestral, anticipo
  bimestral, formulario 2593, formulario 260, ICA, matrícula mercantil, firma electrónica,
  checklist tributario, calendario tributario, vencimientos, contador, BrynexRazonSocial,
  BrynexObligacion, BrynexRazonSocialService, brynex_razones.
---

# Skill: Razones Sociales de BryNex

Módulo del hub de BryNex (creado ago-2026) para que el contable de la casa
controle las obligaciones tributarias de las razones sociales por las que se
afilia gente. **No es el CRUD de razones sociales del aliado** — ese sigue
siendo `Admin\RazonSocialController` y no se toca.

## La idea que hay que entender antes de tocar nada

**Una razón social real es un NIT, no una fila de `razones_sociales`.**

Esa tabla está por aliado: el mismo NIT existe como fila distinta en cada
aliado que usa esa empresa. Medido en ago-2026: 249 filas, 8 aliados, **45 NIT
repetidos**. ELITES CREACIONES (901904750) tiene 4 filas y **421 afiliados
vigentes** repartidos entre Grupo Fecop (214), BRYGAR (143), Luis Lopez (60) y
BryNex (4).

Ante la DIAN eso es **una** empresa con 421 personas y **una** declaración. Por
eso el módulo gira alrededor de `brynex_razones_sociales` (la ficha, una por
NIT) y no de `razones_sociales`.

```
brynex_razones_sociales (1 por NIT, sin aliado_id)
  └── brynex_razon_social_vinculos → razones_sociales.id de CADA aliado
        ├── contratos          → afiliados vigentes
        ├── banco_cuentas      → consignaciones y gastos
        └── razon_social_credenciales (por ficha_id) → claves compartidas
```

## Por qué este módulo NO filtra por aliado

Todo lo demás en BryNex filtra por `session('aliado_id_activo')`. Aquí **no**,
a propósito: la pregunta que responde ("¿cuánta gente hay afiliada a esta
empresa?") no tiene respuesta dentro de un solo aliado.

El aislamiento lo da el permiso, no el filtro. `brynex_razones.*` está marcado
`solo_brynex`, así que el `Gate::before` de `AuthServiceProvider` le cierra la
puerta a cualquiera que no tenga `es_brynex`. Ver [[permisos-brynex]].

**Tampoco lleva `aliado_id` en las tablas nuevas**, rompiendo la regla de
[[laravel-migracion]]. Es deliberado: la ficha es de BryNex, no de un aliado,
igual que `brynex_modulos`.

## Los cuatro permisos y por qué están partidos así

```
brynex_razones.ver           entrar y consultar
brynex_razones.gestionar     seguir, chulear, subir soportes, calendario
brynex_razones.claves        revelar claves de DIAN y cámara
brynex_razones.claves_banco  revelar claves de BANCO  ← restringido
```

`claves_banco` es el primer caso del escenario que anticipaba el comentario de
`claves_acceso` en `ModulosPermisosSeeder`: **por esas cuentas pasa plata de
los afiliados, no de la empresa**. Al ser `restringido`, no lo hereda ningún
rol — ni el superadmin. Se otorga usuario por usuario.

Las rutas viven en `routes/web.php` bajo `brynex/razones-sociales` pero **en su
propio grupo, fuera del grupo del hub**. El grupo del hub exige
`brynex_hub.ver`, y el contable no tiene por qué entrar a backups, cobros a
aliados ni entrenamiento de la IA para llegar a su módulo.

El menú del sidebar de `layouts/app.blade.php` tiene el módulo **dos veces**:
dentro del panel BryNex (que exige `superadmin`) y como ítem suelto con
`@unless` para quien no es superadmin. Sin eso, el contable no vería nada.

## El checklist: cómo se generan las obligaciones

`BrynexRazonSocialService::generarObligaciones()` cruza tres cosas de la ficha
—régimen, si es responsable de IVA y con qué periodicidad— contra
`brynex_obligaciones_catalogo`, y genera un renglón por período desde el año de
`fecha_constitucion` hasta hoy.

Reglas que ya están implementadas y conviene no romper:

- **Nunca toca un renglón existente.** Solo crea lo que falta. Si el contador
  marcó algo como pagado y después cambia el régimen, ese renglón se queda:
  borrarlo sería perder el rastro de un pago real.
- **No genera períodos anteriores a la constitución.** Una empresa constituida
  en septiembre no debe el anticipo del primer bimestre de ese año.
- **`fecha_vencimiento` puede ser null.** Son los años sin calendario cargado.
  Esos renglones existen para poder subir el soporte y ponerse al día, pero
  quedan en semáforo `gris`: fuera de alertas y del tablero. **Inventar un
  vencimiento es peor que no tenerlo.**

**Cuándo un renglón está cumplido (verde):** `pagada`, `no_aplica`, o
**`presentada` con `valor_pagado = 0`** (`BrynexObligacion::presentadaEnCero()`,
`cerrada()`): una declaración en cero no tiene nada que pagar. `valor_pagado`
vacío significa «aún no se sabe», no cero, y la presentada vencida sigue
amarilla. El 23-nov-2026 se puso 0 a los 140 2593/260 en cero (leídos del PDF:
casilla 56, total ICA e IVA en 0; en el 260, ningún «a pagar»/sanción distinto
de 0). Quedan 21 presentadas sin valor: rentas 110 e IVA 300 de AVAPP,
CONSTRUTECH, LOGIC y LOGISTICS, y Brygar 2025-B5 (tuvo $115.000).

**Cámara de comercio: se sube una sola vez, en el checklist.** Al subir el
certificado al renglón `camara_renovacion`, `BrynexObligacionController::
certificadoDeCamara()` lo lee con `LectorDocumentoRazonSocialService`; si es un
certificado de este NIT con «Último año renovado» ≥ el año del renglón, lo da
por hecho con la fecha de renovación, y si es el más reciente de la ficha lo
copia como «Cámara de Comercio» a los documentos de cada razón social ligada
(`brynex_razon_social_vinculos`, todos los aliados), reemplazando la anterior.
Un recibo de pago de la cámara no trae «Último año renovado» y no se copia.
Años anteriores con certificado «último año renovado 2026»: el año de
constitución va `no_aplica` y los siguientes `pagada` con la fecha de la última
renovación (la cámara no deja renovar sin los años anteriores).

`fecha_constitucion` es obligatoria al poner una razón social en seguimiento
porque está **vacía en 203 de las 249 filas** de `razones_sociales`: no se
puede heredar.

## El calendario es parametría a mano — se recarga cada enero

`brynex_calendario_vencimientos` se sembró con `BrynexObligacionesSeeder` desde
el PDF oficial `dian.gov.co/Calendarios/Calendario_Tributario_2026.pdf`
(271 fechas). **No se deduce de nada.**

Cada enero hay que agregarle el año nuevo al seeder y volverlo a correr. Si no,
las obligaciones del año entrante nacen sin fecha y dejan de avisar, sin error
visible. Ver [[calendario-dian-recarga-anual]].

Detalles del formato de la DIAN que importan al transcribir:

- Vence por el **último dígito del NIT sin el dígito de verificación**, en dos
  filas de cinco: `1 2 3 4 5` y `6 7 8 9 0`.
- El **RST anual agrupa de a dos dígitos**: `1-2, 3-4, 5-6, 7-8, 9-0`.
- El último período de casi todo vence en **enero del año siguiente**.
- La renta y el RST anual se guardan con el **año gravable**, no el de
  vencimiento: `renta_juridica` año 2025 vence en mayo de 2026.

**Dos obligaciones nunca van a estar en ese PDF** y se cargan desde
`/brynex/razones-sociales/calendario`:

- **Exógena**: la fija una resolución aparte cada año.
- **ICA**: lo fija cada municipio. Por eso hay dos entradas de catálogo
  (`ica_bimestral` e `ica_anual`) y `aplicaA()` las cruza contra
  `periodicidad_ica` de la ficha, no contra el régimen.

Al guardar una fecha, `propagarACheckLists()` la baja a los renglones ya
generados que sigan **abiertos**. Los pagados conservan la fecha con la que se
trabajó.

## De dónde sale el dinero

```
entradas → consignaciones.banco_cuenta_id
salidas  → gastos.banco_origen_id
```

Las cuentas de una razón social se resuelven por dos vías en `cuentasDe()`:
`banco_cuentas.razon_social_id` (corregible a mano) **o** el NIT normalizado.

`banco_cuentas.nit` es texto libre y viene tanto `'901175588'` como
`'901175588-8'`, así que se compara solo la parte numérica antes del guion, y
solo si tiene 9 o 10 dígitos — los Nequi y Daviplata traen un celular ahí y
cruzarían falso. Las 79 cuentas se cargan de una sola vez y se filtran en PHP:
armar esa normalización en SQL Server sale más caro.

**`banco_cuentas.razon_social_id` está en NULL en las 79 filas y no lo lee
nadie más** (ni siquiera está en el `$fillable` de `BancoCuenta`). Se dejó así a
propósito: el cruce por NIT es determinista y no se desactualiza. La columna
queda para enganchar a mano la cuenta cuyo NIT no sirve.

⚠️ **Lo que este total NO es**: la mayor parte de lo que entra a estas cuentas
es plata de los afiliados para pagar su seguridad social, **no ingreso de la
empresa**. Sirve para conciliar contra el extracto, no como base gravable. La
pantalla lo dice en un aviso; no lo quites.

## Claves: una por NIT, compartida

Las claves viven en `razon_social_credenciales` pero la llave de lectura es
**`ficha_id`**, no `razon_social_id`. Ese es el mecanismo de "lo que modifique
uno le actualiza al otro": la clave de la DIAN de ELITES es una sola aunque la
usen Grupo Fecop y BRYGAR.

`aliado_id` se quedó como dato de auditoría (qué aliado registró la clave). No
se hizo nullable porque tiene dos índices encima y en SQL Server eso obliga a
tumbarlos y recrearlos sobre producción.

Otras dos reglas ya implementadas:

- La contraseña tiene cast `encrypted` y `$hidden`. **Revelarla siempre pasa
  por el endpoint que deja rastro en `Bitacora`** con acción `clave_revelada`.
- Dejar la contraseña vacía en el formulario significa "no la cambies", no
  "bórrala": si no, editar el link de acceso borraría la clave.

## Trampas de sqlsrv que ya costaron un bug aquí

1. **`pluck(DB::raw('COUNT(*)'), 'x')` no funciona.** sqlsrv devuelve la
   columna llamada literalmente `COUNT(*)` y pluck no la encuentra
   (`Undefined property: stdClass::$COUNT(*)`). Usar
   `->get(['x', DB::raw('COUNT(*) as total')])->pluck('total', 'x')`.

2. **Los scopes de `BrynexObligacion` califican las columnas con la tabla.** El
   tablero y el comando de alertas los usan sobre un join con
   `brynex_razones_sociales`, que también tiene `estado`: sin calificar, SQL
   Server responde `Ambiguous column name 'estado'`. No quitar el
   `$this->getTable()`.

3. **`users.cedula` es `nvarchar`.** Buscar con un entero hace que SQL Server
   convierta todas las cédulas a int y revienta con
   `The conversion of the nvarchar value '11306667121' overflowed an int
   column`. Pasar la cédula como string. Emparenta con
   [[sqlsrv-enteros-como-string]].

4. **Un campo `nullable` que no viene en la petición tampoco viene en el array
   de `validate()`.** Usar `?? null`, no acceso directo.

## Rendimiento

Cada consulta al SQL Server cuesta ~250 ms (ver [[sqlsrv-latencia-red-domina]]),
así que aquí lo caro es **el número de consultas**, no los índices. La ficha
hace 19, de las cuales 4 son del layout que paga toda la app.

Tres cosas están escritas específicamente para eso; no las "simplifiques":

- `razonSocialIds()` memoriza en `$idsCache` — la ficha lo pide dos veces.
- `sincronizarVinculos()` corre en **cada** apertura de la ficha y solo escribe
  lo que cambió; el caso normal es que no haya cambiado nada.
- El listado trae los ~200 NIT agrupados en una consulta y **pagina en PHP**.

## Alertas

`brynex:alertar-vencimientos [--dias=7] [--seco]`, programado de lunes a
viernes 7:30 AM Colombia en `Console\Kernel`. Sale por
`AlertaOperativaService::enviarA()` con la plantilla `notificar_brynex` de
Brygar (ver [[whatsapp-brynex]] y [[plantilla-notificar-brynex-boton]]).

Manda **un mensaje por contador**, no uno por obligación: con 20 razones
sociales serían 60 mensajes en un día de vencimientos y dejarían de leerse. Las
razones sociales sin contador asignado caen al número de guardia, para que no
pase que a nadie le avisaron.

## Archivos

```
app/Http/Controllers/BrynexRazonSocialController.php   listado, ficha, claves
app/Http/Controllers/BrynexObligacionController.php    checklist, soportes, calendario
app/Services/BrynexRazonSocialService.php              consolidación por NIT
app/Console/Commands/BrynexAlertarVencimientos.php
app/Models/BrynexRazonSocial.php · BrynexObligacion.php · BrynexObligacionCatalogo.php
         · BrynexCalendarioVencimiento.php · BrynexObligacionDocumento.php
         · BrynexRazonSocialVinculo.php
database/seeders/BrynexObligacionesSeeder.php          catálogo + calendario 2026
resources/views/brynex/razones_sociales/               index · show · tablero · calendario
```

Los soportes van al disco **`local`** (`storage/app/brynex/razones-sociales/{nit}/{año}`),
nunca a `public`: una declaración de renta trae NIT, ingresos y patrimonio.
Ver C-4 en `docs/auditoria-seguridad.md`.
