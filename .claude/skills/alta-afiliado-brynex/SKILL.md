---
name: alta-afiliado-brynex
description: >
  Alta completa de un afiliado desde el chat: ficha del cliente, contrato y
  afiliación a la ARL, a partir de la foto de la cédula y datos sueltos.
  Actívate cuando el usuario mande una cédula (foto o número) con datos de
  afiliación y pida: ingresar, crear la ficha, crear el contrato, afiliar,
  "ingresar en el aliado X", "en la empresa Y", gestión ARL, riesgo N, cargo,
  "inicio mañana", o "afílialo en ARL Sura/Colmena".
---

# Skill: Alta de afiliado (ficha + contrato + ARL) — Brynex

Flujo de punta a punta para cuando el usuario manda una cédula y unos datos
sueltos ("ingresar la ficha y contrato en el aliado Brygar, empresa Brayan
Garcia, Gestión ARL riesgo 3, mantenimiento de computadores, inicio mañana").

Complementa a [[contratos-brynex]] (reglas del contrato), [[afiliaciones-brynex]]
(radicados y vigencia ARL) y [[permisos-brynex]] (aliado activo). La memoria
`flujo-crear-cliente-contrato-por-chat` tiene el origen de varias reglas.

## Regla de oro: proponer todo y confirmar una sola vez

El alta tiene que quedar bien a la primera (un contrato con la razón social o
el riesgo equivocado se paga en planillas mal liquidadas y coberturas que no
sirven), pero sin interrogar al usuario dato por dato. Por eso **nada se
pregunta suelto: se propone**.

1. Con lo que mandó el usuario, la cédula y lo que hay en la BD, **llenar todos
   los datos**: los que dijo, tal cual; los que no dijo, con la **sugerencia**
   que sale de los pasos 1-5 (fecha de ingreso = mañana, razón social más usada
   para esa modalidad en el aliado, riesgo según la clase oficial del cargo,
   tarifa del tarifario, plan de la modalidad…).
2. Mostrar **una ficha de confirmación** en una tabla, marcando en cada fila de
   dónde salió el valor: *lo dijiste*, *cédula*, *RUAF*, *sugerido: porqué*
   ("la más usada: 45 Gestión ARL vigentes", "Decreto 768: CIIU 9511 es clase
   2", "tarifa de Brygar riesgo 2"). Si lo que dijo el usuario choca con la
   verificación (p. ej. dijo riesgo 3 y la clase oficial es 2), decirlo en esa
   fila y sugerir el valor verificado.
3. Terminar con una sola pregunta: **"¿Lo creo así, o qué cambio?"**. El
   usuario confirma o corrige solo lo que quiera.
4. **No escribir nada** (ficha, contrato, catálogo ni afiliación a la ARL) hasta
   que confirme. Con la confirmación se hace todo de corrido: ficha, contrato y
   afiliación.

Qué no se sugiere sino que se resuelve solo: EPS y AFP (RUAF), datos de la
cédula (lectura de la foto), salario mínimo, encargado (user 2), municipio Cali
si la cédula es de Cali y no dicen otro. Igual aparecen en la ficha de
confirmación para que se vean.

**La única excepción es el aliado**, porque todo lo demás (razón social,
tarifa, empresa) depende de él. Si no viene en el mensaje: si la cédula existe
en un solo aliado (sin contar el 1, que es de pruebas), sugerirlo en la ficha
como los demás; si existe en varios o en ninguno, preguntar el aliado **primero**,
en una pregunta corta, y después armar la ficha. Nunca asumir el aliado 1 ni el
último usado.

Si falta un dato que **no tiene sugerencia razonable** y la entidad lo exige
(celular, dirección para EPS o caja), aparece en la ficha como *falta* y se pide
ahí mismo, en la misma pregunta de confirmación.

### Datos que lleva la ficha de confirmación

| Pieza | Dato | De dónde sale |
|---|---|---|
| Todo | aliado | el usuario; si no, ver la excepción de arriba |
| Ficha | tipo y número de documento, nombres, apellidos, género, fecha de nacimiento | foto de la cédula |
| Ficha | fecha y lugar de expedición, RH | reverso de la cédula |
| Ficha | celular | el mensaje; si no viene, *falta* |
| Ficha | EPS, AFP | RUAF (paso 2) |
| Ficha | empresa cliente (`cod_empresa`) | el usuario; si no, sugerir la que ya tenga la ficha o ninguna |
| Ficha | dirección, correo | el mensaje; ver la nota de abajo |
| Contrato | razón social | el usuario; si no, sugerir la más usada (paso 3) |
| Contrato | modalidad y plan | el usuario ("Gestión ARL" = modalidad 15, plan 2) |
| Contrato | nivel de riesgo y cargo | el usuario + verificación (paso 4); si no dijo el riesgo, sugerir la clase oficial |
| Contrato | fecha de ingreso | el usuario ("mañana" = hoy + 1); si no, sugerir mañana |
| Contrato | administración y afiliación | el usuario; si no, sugerir según el paso 5 |
| ARL | fecha de inicio de cobertura | la de ingreso (Sura cubre desde mañana como pronto) |

Dirección y correo: la afiliación a **ARL Sura** cae a la dirección y el correo
de la razón social si la ficha no los tiene, así que no bloquean (en la ficha:
*se usa la de la razón social*). **EPS y caja sí los exigen**: si el plan
incluye EPS o caja, la dirección va como *falta*.

## Paso 1 — Buscar la cédula en el aliado correcto

La misma cédula existe en varios aliados (ver memoria
`relaciones-por-cedula-sin-aliado`). Siempre filtrar por `aliado_id`:

```php
DB::table('clientes')->where('aliado_id', $aliado)->where('cedula', $ced)->first();
DB::table('contratos')->where('aliado_id', $aliado)->where('cedula', $ced)->get();
```

- Si la ficha existe, se **actualiza** (celular, dirección, EPS/AFP, ocupación).
- **Contrato: siempre uno nuevo.** El viejo solo se mira para saber si quedó
  retirado. Si hay un contrato **vigente** con la misma razón social, avisarlo
  en la ficha de confirmación: probablemente es un duplicado y no se crea
  otro sin que el usuario lo confirme.
- Aliados: 1 BryNex (solo pruebas), 2 Brygar, 4 GiMave, 6 Luis Lopez, 9 Fecop…
  confirmar con `DB::table('aliados')->pluck('nombre','id')`.
- Si el usuario no dijo el aliado, buscar en qué aliados existe la cédula
  (`clientes` y `contratos` sin filtrar por aliado) y aplicar la excepción de
  la regla de oro: uno solo → sugerirlo; varios o ninguno → preguntar el
  aliado primero.

## Paso 2 — RUAF para EPS y AFP reales

```php
app(\App\Services\RegistroOficialService::class)->consultar($aliado, $ced, 'CC');
// → eps_id, eps_nombre, pension_id, pension_nombre, estado, regimen
```

El tipo de documento es parte de la llave (memoria
`ruaf-consulta-por-tipo-documento`). Si el RUAF no lo encuentra o la EPS que
dice el usuario es otra, mostrar las dos en la ficha y sugerir la del RUAF.

## Paso 3 — Empresa cliente ≠ razón social

"En la empresa Brayan Garcia" casi siempre es la **empresa cliente**
(`empresas`, va en `clientes.cod_empresa`), no la razón social del contrato.

```php
DB::table('empresas')->where('aliado_id', $aliado)->where('empresa', 'like', "%{$x}%")->get();
DB::table('razones_sociales')->where('aliado_id', $aliado)->where('razon_social', 'like', "%{$x}%")->get();
```

Si el nombre aparece en las dos, o en ninguna, señalarlo en la ficha de
confirmación. Para sugerir la razón social, contar las vigentes de esa
modalidad en el aliado:

```php
DB::table('contratos')->where('aliado_id', $aliado)->where('tipo_modalidad_id', $mod)
  ->where('estado', 'vigente')->selectRaw('razon_social_id, count(*) n')
  ->groupBy('razon_social_id')->orderByDesc('n')->get();
```

## Paso 4 — Cargo y nivel de riesgo: verificarlo, no copiarlo

El nivel que dice el usuario se **contrasta** antes de crear el contrato. Un
riesgo subvaluado deja al trabajador mal cubierto; uno sobrevaluado cobra de
más cada mes.

1. **Catálogo del aliado**: `razon_social_cargos` (`aliado_id`, `cargo`,
   `nivel_riesgo`, `codigo_ocupacion`). Si el cargo existe ahí, ese nivel ya
   fue revisado una vez.
2. **Actividad económica (CIIU)**: el API de Enlace/Simple la encuentra por
   nombre o código:
   ```php
   $cred = \App\Models\OperadorCredencial::paraOperador($aliado, 6, $rsId)->first(); // 6 = Simple
   $api  = new \App\Services\SuaporteApiService(['operador'=>'SIMPLE', 'usuario'=>$cred->usuario,
            'contrasena'=>$cred->contrasena, 'clave_secreta'=>$cred->clave_secreta]);
   $api->autenticar();
   // GET {apiUrl}/gestion/economicactivities/find?name=COMPUTADORES
   // → [{id:263, codigo:"9511", descripcion:"Mantenimiento y reparación de computadores…"}]
   ```
   Da el CIIU, **no** la clase de riesgo.
3. **Clase de riesgo según la ARL**: la clasificación oficial es la del
   Decreto 768 de 2022 (código de actividad = clase + CIIU + consecutivo, p. ej.
   `3951101`). En Sura la ve el portal en el buscador de actividades
   económicas del ingreso de independientes (columnas Código, Nombre, Clase
   riesgo, Tasa). Su API (`POST /sel-services/consultas/independientes/actividadEconomica`,
   cuerpo `{cdActividad, dsActividad, numClase}`) responde **500** desde el
   servidor con cualquier combinación probada (14-oct-2026), y el portal pide
   iniciar sesión, que Claude no puede hacer.
   **Fuente que sí funciona:** la tabla completa del decreto, publicada en texto en
   `https://normativa.colpensiones.gov.co/colpensiones/compilacion/docs/decreto_0768_2022.htm`
   (unos 400 mil caracteres: leerla con WebFetch en tramos con `offset` 0,
   100000, 200000 y 300000, buscando el CIIU). Va ordenada por clase de
   riesgo. Ejemplo: **9511-01 "Mantenimiento y reparación de computadores y de
   equipo periférico" es clase 2**; solo aparece ahí. El PDF de Función Pública
   falla por certificado.
   `actividades_economicas` de BryNex solo tiene 23 filas con
   `nivel_arl_sugerido`: sirve de pista, no de fuente.
4. **Decidir** (en la ficha de confirmación, no en una pregunta aparte):
   - Si el nivel del usuario coincide con el catálogo o con la clase oficial,
     va como *lo dijiste ✓ verificado*.
   - Si **no coincide**, sugerir la clase oficial y explicar el choque en la
     fila: "dijiste riesgo 3; el Decreto 768 pone CIIU 9511 en clase 2 →
     sugiero 2". Riesgo mayor solo si las tareas reales lo justifican
     (alturas, electricidad).
   - Si **no se pudo verificar**, decirlo en la fila. No crear con un nivel
     dudoso sin que el usuario lo haya visto.
5. Si el cargo no estaba en el catálogo, agregarlo para el aliado con el nivel
   ya confirmado y su código **CIUO** (p. ej. 7422 reparadores de equipos de
   informática y comunicaciones). El CIUO también lo pide la caja Comfandi.
   ```php
   DB::table('razon_social_cargos')->insert(['aliado_id'=>$aliado, 'razon_social_id'=>null,
     'cargo'=>'MANTENIMIENTO DE COMPUTADORES', 'nivel_riesgo'=>3, 'por_defecto'=>0, 'activo'=>1,
     'codigo_ocupacion'=>'7422', 'created_at'=>now(), 'updated_at'=>now()]);
   ```

En Sura el cargo viaja como **texto libre**: lo que define la tasa es el
**centro de trabajo**. En las razones sociales de Brygar los centros están
armados por riesgo (`000RIESGO1`, `0000000013 — RIESGO 3`…), así que
`contratos.n_arl` elige el centro (`ArlCentroTrabajo::paraRiesgo`).

## Paso 5 — Tarifas: tarifario contra lo que se viene cobrando

```php
DB::table('afiliacion_arl_modalidad')->where('aliado_id', $aliado)
  ->where('tipo_modalidad_id', $mod)->where('plan_id', $plan)->where('nivel_arl', $nivel)->first();
// costo_afiliacion, administracion (null = la del contrato), retiro
```

Comparar con los últimos contratos iguales del aliado (misma modalidad, plan y
nivel). Sugerir el tarifario, y si la práctica reciente no coincide (p. ej.
tarifa $58.800 y contratos recientes en $52.000 y $40.000), mostrarlo en la
fila para que el usuario elija. En Brygar, Gestión ARL se ha venido cobrando
por debajo del tarifario: Jhon Fred (riesgo 2) quedó en $50.000.
Gestión ARL en Brygar: administración $46.000.

## Paso 6 — Crear ficha y contrato

Escribir un script PHP en el scratchpad, copiarlo al servidor y correrlo como
`www-data` **dentro de una transacción**. Las escrituras van en el servidor, no
por el túnel (memoria `tunel-transaccion-huerfana-bloquea`):

```bash
scp -q $SCRATCH/alta.php netcup:/tmp/alta.php
ssh netcup 'cd /var/www/brynex && sudo -u www-data php artisan tinker --execute="require \"/tmp/alta.php\";" </dev/null; rm -f /tmp/alta.php'
```

`</dev/null` es obligatorio: sin él, tinker se queda esperando en el prompt
interactivo.

Trampas al crear:

- **`clientes.id` no es IDENTITY.** Hay que darlo a mano, y el modelo en
  memoria queda con `id = 0` después del `create`. Guardar el id en una
  variable antes:
  ```php
  $cliId = (int) (DB::table('clientes')->max('id') ?? 0) + 1;
  Cliente::create(['id' => $cliId, 'aliado_id' => $aliado, 'cod_empresa' => $empresaId, ...]);
  ```
  Lo mismo con `empresas` si hay que crear una (memoria
  `flujo-crear-cliente-contrato-por-chat`).
- **El contrato se crea como `ContratoController::store`**, para que nazcan
  sus radicados pendientes según el plan:
  ```php
  $c = Contrato::create([... 'estado'=>'vigente', 'fecha_created'=>now(), 'encargado_id'=>2,
        'motivo_afiliacion_id'=>1, 'salario'=>$smmlv, 'ibc'=>$smmlv, ...]);
  $c->load('plan');
  $c->crearRadicadosPendientes();
  ```
  Salario mínimo: `\App\Models\ConfiguracionBrynex::salarioMinimo()`.
- Gestión ARL (modalidad 15): plan 2, `eps_id` y `pension_id` en null en el
  contrato (van en la ficha), `caja_id` null, ARL Sura = `arl_id` 3,
  Colmena = 2.
- Al terminar, releer el contrato y sus radicados y mostrarlos.

## Paso 7 — Afiliar a la ARL

### ARL Sura

Se hace por los **endpoints de BryNex con la sesión del usuario** en Chrome
(Claude in Chrome en `https://brynex.co`): exigen `Auth::id()` y el permiso
`automatizar-arl`, y reutilizan la credencial y la póliza por NIT.

```js
// 1. Precheck: datos faltantes, credencial, póliza, centro y cobertura viva
await fetch('/admin/gestion-arl/{contrato}/precheck', {headers:{Accept:'application/json'}})
// → ok, problemas[], requiere_credencial, resumen{poliza, centro, tasa, cargo}, cobertura_actual, ya_afiliado

// 2. Afiliar (fecha = la de ingreso; Sura cubre desde mañana como pronto)
const token = document.querySelector('meta[name="csrf-token"]').content;
await fetch('/admin/gestion-arl/{contrato}/afiliar', {method:'POST',
  headers:{'Content-Type':'application/json', Accept:'application/json', 'X-CSRF-TOKEN':token},
  body: JSON.stringify({fecha_inicio_cobertura:'AAAA-MM-DD'})})
// → {ok, codigo_transaccion, fecha_arl}
```

- Si el precheck trae `problemas` o `requiere_credencial`, **no afiliar**:
  decirle al usuario qué falta.
- Si trae `cobertura_actual` o `ya_afiliado`, parar: ya tiene cobertura en Sura
  y crear otra la duplica.
- **`500 en /direccion/estandarizar`**: es intermitente del lado de Sura.
  Reintentar **una** vez sin cambiar nada (14-oct-2026 pasó al segundo
  intento). Si vuelve a fallar, revisar la dirección (de la ficha o de la razón
  social) y decirlo.
- Al terminar, verificar en BD: el radicado `arl` en `ok` con el número de
  transacción y `ruta_pdf` del soporte, y `contratos.fecha_arl` igual a la
  cobertura.

### ARL Colmena

Tiene su propio servicio (`app/Services/ArlColmena/ColmenaAfiliacionService.php`,
memoria `colmena-portal-arl`). Cuidar el ritmo: muchas afiliaciones de una
misma empresa en un mes la marcan como agrupadora (memoria
`colmena-agrupadora-ritmo`).

## Cierre: qué reportar

Una respuesta corta con:

- Ficha (id, nombre, documento, EPS/AFP del RUAF, empresa cliente).
- Contrato (id, modalidad, razón social, ARL y riesgo, cargo, inicio, salario,
  administración, afiliación).
- Afiliación (transacción, póliza, centro y tasa, desde cuándo cubre) y el
  estado del radicado.
- Lo que quedó sin verificar (p. ej. el nivel de riesgo) y lo que falta en la
  ficha (dirección, correo).
