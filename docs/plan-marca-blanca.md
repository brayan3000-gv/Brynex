# Plan: revender BryNex (referido, distribuidor y marca blanca)

Estado (23-nov-2026): **el distribuidor tiene valores decididos** y un contrato
borrador; el referido y la marca blanca siguen como propuesta. Nada escrito en
código. Todos los valores van **antes de IVA**.

## Por qué

Un asesor quiere ofrecer BryNex a otras empresas y poner sus propios precios.
La pregunta era: ¿se le deja subir el precio, se le prohíbe o se saca otra marca?

Respuesta corta: **no sacar otra marca para un asesor que empieza.** Se arma una
escalera de tres niveles. Sube de nivel a medida que demuestra que vende:

1. **Referido**: trae el contacto y gana un porcentaje.
2. **Distribuidor BryNex**: vende con la marca BryNex, compra a $600 por
   usuario y le cobra al aliado hasta el doble. Él factura al aliado.
3. **Marca blanca**: vende con **su** marca y precio libre, paga un mínimo
   mensual y una instalación.

La regla de fondo en los tres: **nadie vende por debajo de la lista de BryNex**,
porque eso le quitaría ventas al canal directo. Subir el precio sí se permite:
el revendedor está agregando su servicio.

## Lo que ya existe (para no inventar otra tarifa)

Por afiliado al mes, de `config/alianzas.php`:

| Alianza | Valor | Incluye |
|---|---|---|
| Esencial | $800 | La plataforma; el aliado usa sus razones sociales. |
| Específica | $5.500 | Plataforma, automatización y razones sociales de Brygar. |
| Integral | $15.000 | Toda la operación gestionada por Brygar. |

- **Mínimo de plataforma: $180.000 al mes por aliado.**
- **Montaje**: $400.000 sin permanencia, o sin montaje con 6 meses de permanencia.
- **Complemento de afiliación**, una vez por contrato nuevo: $3.000 si lo hace el
  equipo del aliado y $6.000 si lo hace Brygar.

Los revendedores ganan **solo sobre la parte de plataforma** ($800 por afiliado).
En la Específica y la Integral, lo demás es operación de Brygar y no se reparte.

## 1. Referido

Para quien solo presenta al aliado: no lo atiende y no pone precio.

- **Precio**: lo pone BryNex (la lista de siempre).
- **Gana**: **20 % de la plataforma** que el aliado pague, durante **12 meses**
  desde que el aliado empieza.
- Solo sobre lo que el aliado **ya pagó** (si está en mora, ese mes no cuenta).
- Si el aliado está en la Específica o la Integral, el 20 % se calcula sobre $800
  por afiliado, no sobre el valor completo.
- **No da soporte** ni ve datos del aliado.

| Aliado | Paga a BryNex al mes | Referido al mes | Referido en 12 meses |
|---|---|---|---|
| 100 afiliados (Esencial, queda en el mínimo) | $180.000 | $36.000 | $432.000 |
| 300 afiliados (Esencial) | $240.000 | $48.000 | $576.000 |
| 300 afiliados (Específica, $1.650.000) | $1.650.000 | $48.000 (sobre $240.000) | $576.000 |

**Configuración: no se le cobra nada.** El montaje del aliado sigue igual
(lo cobra BryNex) y el referido no gana sobre él.

## 2. Distribuidor BryNex

Para quien vende, capacita y atiende las dudas de uso. Vende **con la marca
BryNex**. Valores decididos por el dueño el 23-nov-2026. El contrato borrador
está en Claude Docs: https://claude.ai/artifact/41wtVUfE6LGs5TvkwvVNUr

| Concepto | Paga el distribuidor a BryNex | Cobra el distribuidor al aliado |
|---|---|---|
| Por usuario al mes | $600 | De $800 a $1.200 (el doble) |
| Mínimo mensual por aliado | $150.000 | De $180.000 a $200.000 |
| Instalación por aliado, una vez | $200.000 | Hasta $400.000 |

- **Usuario** = afiliado activo del aliado en el mes, según `ConteoMensualAliado`.
- Cada mes, por aliado, BryNex cobra el mayor de dos valores: usuarios × $600, o
  $150.000.
- **Arranque** (decidido 23-nov-2026): el primer mes de cada aliado va **sin
  mínimo**, solo los usuarios reales por $600. Desde el segundo mes aplica el
  mínimo de $150.000.
- Se descartó la escalera de $600 / $550 / $500 que estaba propuesta: el precio
  es **$600 parejo**.
- **Piso** (decidido 23-nov-2026): no puede cobrar menos del precio público,
  $800 por usuario y $180.000 de mínimo. Queda entre $800 y $1.200 por usuario, y
  su mínimo entre $180.000 y $200.000.
- **Consumos aparte**, en la misma cuenta mensual: las plantillas de WhatsApp
  ($120 cada una, por la cuenta compartida) y los módulos adicionales
  (automatización, afiliaciones gestionadas, razones sociales de BryNex).

### Quién factura: el distribuidor

**El distribuidor le factura al aliado y BryNex le factura al distribuidor.**
Antes estaba al revés; se cambió por dos razones:

- Si BryNex facturara los $1.200, todo sería ingreso de BryNex. En Régimen
  Simple el impuesto es sobre el ingreso bruto, así que se pagaría impuesto
  también sobre los $600 del distribuidor. Se podría evitar con un mandato
  (ingreso para terceros), pero hay que armarlo con el contador.
- El aliado vería en la factura de BryNex más que el precio público, o vería el
  costo del distribuidor.

El riesgo de que el distribuidor cobre y no pague se cubre así:

- Un **depósito** de un mes de lo que debe por todos sus aliados (mínimo
  $150.000).
- Pago dentro de los 10 días siguientes a la cuenta del mes.
- Si a los 15 días no ha pagado, BryNex puede cobrar del depósito, suspenderlo,
  o **cobrarles directo a sus aliados**, que pasan a ser de BryNex.

### Si el aliado ve que BryNex vale $800

Va a pasar, porque el precio es público. **No se esconde: se explica.**

- El distribuidor presenta **dos conceptos** en su propuesta y en su factura:
  la plataforma BryNex a $800 y su servicio (capacitación, acompañamiento,
  soporte) por el resto. Con $1.200 serían $800 + $400.
- No puede decir que BryNex cuesta más de $800, ni presentarse como dueño de
  BryNex.
- El tope del doble ($1.200) evita que un precio abusivo queme la marca.
- **Si el aliado quiere irse directo con BryNex, se le deja**: plataforma a
  precio público, sin el servicio. El distribuidor pasa a ser **referido** de
  ese aliado (20 % de la plataforma hasta cumplir 12 meses desde la
  instalación).

### Otras reglas del contrato

- Los aliados que inscriba son suyos mientras el contrato esté vigente: BryNex
  no se los ofrece directo.
- No ve los datos de los afiliados. Cada aliado acepta con BryNex los términos
  y el tratamiento de datos.
- Actúa en nombre y por cuenta propia: **no es agente comercial** (evita la
  cesantía comercial del art. 1324 del Código de Comercio). Esto debe revisarlo
  un abogado.
- Si el contrato termina por aviso de cualquiera de las partes, gana el 20 % de
  la plataforma de sus aliados por 6 meses, como el Plan Asesor. Si termina por
  incumplimiento suyo, no gana nada.
- Un año renovable; 30 días de aviso para terminar. Si a los 3 meses no tiene
  ningún aliado activo, BryNex puede terminarlo.

### Usuarios, franquicias y operación propia

- **Usuario** = afiliado activo del mes como lo cuenta `ConteoMensualAliado`:
  contratos facturados del mes más los que ingresan, menos los traslados de la
  misma cédula. Dos contratos simultáneos cuentan dos; el que entra y sale en el
  mes cuenta. Las cuentas de acceso son ilimitadas y no son usuarios.
- **Franquicias de un aliado del distribuidor**: sus usuarios suman al aliado a
  $600, el mínimo de $150.000 va una vez por toda la red, y cada franquicia paga
  su instalación de $200.000 (BryNex le crea a mano la sucursal y el usuario de
  Enlace). Lo que el aliado les cobre a sus franquicias es libre. Si una se
  independiza, queda inscrita a nombre del mismo distribuidor.
- **El distribuidor nunca es una «principal»** (`aliado_padre_id`): la principal
  entra a los datos de sus franquicias. En el código va en un campo aparte.
- **Operación propia** (decidido 23-nov-2026): los afiliados del propio
  distribuidor, de sus franquicias, de empresas suyas, de sus socios o de su
  familia **no son aliados inscritos** y se cobran a su tarifa de aliado, no a
  $600. **Quien ya es aliado de BryNex no se puede inscribir a nombre de un
  distribuidor.** Ejemplo: un aliado con 1.000 usuarios que paga $800
  ($800.000) y se vuelve distribuidor sigue pagando $800.000 por los suyos; solo
  los aliados nuevos que traiga van a $600. Si se le bajara todo a $600, BryNex
  perdería $200.000 al mes ($2,4 millones al año) sin que entre un aliado nuevo.

### Meta para seguir como distribuidor

- A los 3 meses, al menos 1 aliado activo. Al año, 3 aliados activos o 500
  usuarios entre todos.
- Si no la cumple **pasa a referido**: sus aliados siguen con BryNex al precio
  público y él gana el 20 % hasta cumplir 12 meses de cada instalación.
- Un prospecto registrado dura 90 días; si no hay instalación, queda libre. Entre
  dos distribuidores gana el que lo registró primero.

### Lo que también quedó en el contrato

- **Suspensión**: el distribuidor puede pedir que se suspenda a un aliado que no
  le paga; se avisa con 5 días hábiles y se bloquean solo los ingresos nuevos.
  **Ninguna suspensión bloquea retiros, pago de planillas ni descargar la
  información**: de eso depende la seguridad social de las personas.
- **Responsabilidad**: BryNex no responde por lo que digitan los aliados (mora de
  planillas, UGPP, rechazos). Por una falla de la plataforma, la corrige y
  responde como máximo por lo pagado por ese aliado en los 3 meses anteriores.
- **Si cambia el precio público**, el piso cambia con él; el techo sigue siendo
  el doble del costo del distribuidor.
- **Capacitación**: [10] horas sin costo antes del primer aliado.
- **Anexo 1** (decidido 23-nov-2026): lo que paga el distribuidor por módulo.
  Lo vende sin bajar del precio público ni pasar del doble de lo que paga.

  | Módulo | Precio público | Paga el distribuidor |
  |---|---|---|
  | Plantilla de WhatsApp | $120 | $120 (es costo de Meta) |
  | Afiliación hecha por el equipo del aliado | $3.000 | $3.000 |
  | Afiliación hecha por Brygar | $6.000 | $6.000 |
  | Alianza Específica | $5.500 por afiliado | $5.500 |
  | Alianza Integral | $15.000 por afiliado | $15.000 |
  | Módulo de franquicias | sin cargo mensual; $400.000 de instalación | sin cargo mensual; $200.000 de instalación |
  | Asistente de IA | costo del proveedor + 50 % | costo del proveedor + 30 % |

  Por ahora las afiliaciones y las alianzas Específica e Integral van **al
  precio público, sin descuento**: el distribuidor gana solo lo que cobre por
  encima, hasta el doble. La IA queda a costo + 50 % de público y + 30 % para él.
  La afiliación de Brygar vale siempre $6.000: el 23-nov-2026 se quitó el precio
  por volumen ($5.000 desde el contrato 301) también del precio público.
- **Soporte deficiente** (propuesto 23-nov-2026): el distribuidor responde en 1
  día hábil, o el mismo día si hay una planilla o una afiliación con fecha en
  riesgo. Si el aliado llama a BryNex, **BryNex lo atiende** (no se le deja sin
  servicio), registra el caso y le avisa al distribuidor. Las dudas de uso que
  debía resolver él: las 2 primeras de cada aliado en el mes van gratis, desde
  la tercera se le cobran $30.000 por caso. Con 3 quejas de un aliado en un mes
  (o 5 en 3 meses) se le avisa por escrito y tiene 15 días para corregir; si se
  repite, BryNex atiende a ese aliado directo al precio público y el
  distribuidor pasa a referido con él. Si le pasa con 2 o más aliados en un año
  es incumplimiento y se puede terminar el contrato.

### Ejemplos

| Caso | Aliado paga | BryNex | Distribuidor |
|---|---|---|---|
| Aliado de 300 a $1.200 | $360.000 | $180.000 | $180.000 |
| Aliado de 300 a $1.000 | $300.000 | $180.000 | $120.000 |
| Aliado de 300 a lista ($800) | $240.000 | $180.000 | $60.000 |
| Aliado de 100, cobra el mínimo de $200.000 | $200.000 | $150.000 (mínimo) | $50.000 |
| Instalación, cobrada a $400.000 | $400.000 | $200.000 | $200.000 |

### Lo que le cuesta a BryNex cada usuario

El servidor de netcup cuesta unos €24 al mes (~$110.000), sin importar cuántos
aliados haya. Repartido entre los afiliados de todos los aliados sale a pocos
pesos por usuario. Los $600 alcanzan siempre que lo variable se cobre aparte
(WhatsApp, módulos). El costo que sí crece con la red es SQL Server: la versión
Express tiene un techo de 10 GB por base de datos, y pasarse obliga a pagar una
licencia o cambiar de motor.

## 3. Marca blanca

Para un revendedor con volumen que quiere vender con **su** nombre. El aliado no
ve BryNex en ningún lado.

- **Instalación de la marca: $2.000.000**, una sola vez. Incluye logo, colores,
  la dirección de la app (por ejemplo app.sumarca.com) con su certificado de
  seguridad, la pantalla de entrada, recibos y PDF, el asistente de IA con su
  nombre y la extensión de Chrome funcionando en su dominio.
- **Mínimo mensual del revendedor: $600.000**, sin importar cuántos afiliados
  tenga.
- **Costo por afiliado** (escalera por toda su red, aplicada a todos):

  | Afiliados en su red | Costo por afiliado |
  |---|---|
  | Hasta 2.000 | $450 |
  | 2.001 a 5.000 | $400 |
  | Más de 5.000 | $350 |

- **Precio libre**: ya no compite con BryNex porque el aliado no sabe que existe.
- **Él factura y cobra a sus aliados**, y le paga a BryNex por mes vencido.
- **Depósito de garantía**: un mes del mínimo ($600.000). Si no paga en 15 días,
  se suspende la cuenta del revendedor.
- **Soporte**: él atiende todo lo de uso. BryNex atiende solo las fallas técnicas
  (portales, planos, planillas) y **solo con él**, nunca con sus aliados.
- **Si cierra o se va**, sus aliados pueden seguir con BryNex directo. Va en el
  contrato, junto con el contrato de tratamiento de datos (Ley 1581): BryNex
  sigue siendo quien trata los datos.

| Revendedor | Paga a BryNex al mes | Si cobra $1.000 por afiliado, gana |
|---|---|---|
| 800 afiliados (800 × $450 = $360.000) | $600.000 (mínimo) | $200.000 |
| 2.000 afiliados en 8 aliados | $900.000 | $1.100.000 |
| 5.000 afiliados (5.000 × $400) | $2.000.000 | $3.000.000 |

**Complemento de afiliación** (propuesta): le cuesta $2.400 / $4.800 por contrato
(20 % menos que la lista) y lo vende al precio que quiera.

**Configuración:**

| Concepto | Valor |
|---|---|
| Instalación de la marca (una vez) | $2.000.000 |
| Cada aliado nuevo (parte técnica: aliado, sucursal ante el operador, usuario de Enlace) | $150.000 |
| Cambio de marca después (nuevo logo, nuevo dominio) | $300.000 por cambio |
| Depósito de garantía (se devuelve al terminar) | Un mes del mínimo |

El montaje y la capacitación de sus aliados los cobra él, al precio que quiera.

## Comparación para BryNex

| | Directo | Distribuidor | Marca blanca |
|---|---|---|---|
| BryNex recibe por afiliado | $800 | $600 | $350 – $450 |
| Mínimo | $180.000 por aliado | $150.000 por aliado | $600.000 por revendedor |
| Quién vende | BryNex | Él | Él |
| Soporte de uso | BryNex | Él | Él |
| Quién cobra al aliado | BryNex | Él | Él |
| Configuración | Montaje $400.000 | $200.000 a BryNex por aliado; él cobra hasta $400.000 | $2.000.000 + $150.000 por aliado |
| Marca que ve el aliado | BryNex | BryNex | La del revendedor |

**Cuándo le conviene pasar de distribuidor a marca blanca**: desde unos 1.400
afiliados el mínimo de $600.000 ya no pesa. Con 2.000 afiliados pagaría $900.000
en vez de $1.200.000 como distribuidor, y los $2.000.000 de instalación los
recupera en unos 7 meses. A cambio él asume toda la atención y la cobranza.
Es la escalera esperada; BryNex gana menos por afiliado, pero no carga ni el
soporte ni el riesgo de cobro.

## Trabajo en el código

### WhatsApp

**Sigue en la cuenta compartida (Avapp)**, también en la marca blanca. Así no
hay trámite con Meta. Lo que hay que revisar:

- Que las plantillas compartidas que les llegan a los clientes no digan
  «BryNex». Por ejemplo, la alerta `notificar_brynex` dice «🔔 *Brynex*», pero
  es interna, no va a clientes.
- El nombre que ve el cliente en WhatsApp es el de la cuenta compartida, no el
  del revendedor. Se acepta así.
- Los mensajes de contactos que no se pueden identificar siguen cayendo a la
  bandeja de BryNex (`WhatsappBandejaCompartida`), como hoy.

### Por nivel

| Nivel | Qué hay que hacer | Días |
|---|---|---|
| **Referido** | En el aliado: quién lo refirió y desde cuándo. Un informe del 20 % de la plataforma cobrada en sus 12 meses. | ½ – 1 |
| **Distribuidor** | El aliado guarda su distribuidor. BryNex le saca **una cuenta de cobro al distribuidor** con el detalle por aliado ($600 por usuario, mínimo $150.000, instalaciones y consumos aparte). A los aliados de un distribuidor **se les oculta** la pantalla de lo que cobra BryNex (`informes/brynex-cobros`), porque mostraría los $600. | 1 – 2 |
| **Pantalla del distribuidor** | Sin datos de afiliados: sus aliados, los usuarios de cada uno en el mes, instalaciones y su cuenta de cobro. La necesita para facturarle a sus aliados. | 1 – 2 |
| **Asistente de WhatsApp** | Si escribe un prospecto registrado por un distribuidor, no le ofrece el precio directo. | ½ |
| **Marca blanca** | Los bloques de abajo. | 5 – 8 |

**Mientras no exista ese código**, a cada aliado de un distribuidor se le
cargan tramos **propios** de administración, porque la tarifa general es la del
precio público ($800 con mínimo $180.000, tramo 22 desde el 1-nov-2026):

- el mes de su Instalación: $600 por usuario **sin mínimo** (arranque), con
  `vigente_hasta` el último día de ese mes;
- desde el mes siguiente: $600 por usuario con **mínimo $150.000**, sin
  `vigente_hasta`.

La cuenta de cobro de esos aliados se le pasa al distribuidor a mano. Los
aliados que ya están en BryNex siguen con su tarifa propia (ver la cláusula
sexta del contrato).

### Bloques de la marca blanca

| Bloque | Hoy | Qué hacer | Días |
|---|---|---|---|
| Ficha de la marca | No existe. | Guardar por revendedor el nombre, logo, colores y dominio de la app. Una sola función decide qué marca mostrar: por el dominio antes de entrar y por el aliado después. | 1 – 2 |
| Entrada y dominio | `auth/login.blade.php` dice BryNex fijo (título, logo, «© BryNex», invitación a la demo). El dominio propio de hoy (`aliados.dominio_propio`, al inicio de `routes/web.php`) solo sirve la página pública. | La dirección de la app por revendedor con su marca en la entrada, y el certificado en netcup. | 1 – 2 |
| Textos en pantallas | 64 vistas dicen «BryNex», unas 25 las ve el aliado (título de la pestaña en `layouts/app.blade.php`, «Guardar en BryNex», «Pagos por BryNex», «Contacta a BryNex»…). | Cambiarlas por la función de la marca. Las del panel de BryNex se quedan. | 1 – 2 |
| Recibos y PDF | Ya usan el logo y el nombre del aliado; solo si no tiene, ponen los de BryNex. | Que el respaldo sea la marca del revendedor, y cambiar «Honorarios gestión BryNex» en `_recibo_cuerpo.blade.php`. | ½ |
| Asistente de IA | Se presenta como «asistente virtual de BryNex» (`AsistenteIaService`) y a los prospectos les ofrece los planes de BryNex. | Que use la marca del revendedor y no venda los planes de BryNex en sus cuentas. | ½ – 1 |
| Extensión de Chrome | Se llama «BryNex Portales» y solo funciona en brynex.co. | Nombre neutro, y que funcione en los dominios de los revendedores sin sacar una versión por cada uno. | ½ – 1 |
| WhatsApp | Cuenta compartida (Avapp). | Solo la revisión de plantillas de arriba. | ½ |
| Cobro al revendedor | Ya existe el cobro de la principal a sus franquicias (`cobrador_aliado_id`, `pdf/red_cobro.blade.php`; ver `docs/plan-franquicias.md`). | Reusarlo: una cuenta de cobro al revendedor con el detalle por aliado, la escalera y el mínimo. **Sin** que el revendedor entre a los aliados, a diferencia de la principal en las franquicias. | ½ – 1 |

Los correos no son trabajo: la app no envía ninguno. La mayoría de los enlaces se
arman con el dominio por el que entró la persona; los pocos fijos a `brynex.co`
son de la demo, la página de alianzas y el panel de BryNex.

**Por cada revendedor nuevo, después del primero**: unas horas (cargar la marca,
apuntar el dominio y poner el certificado).

## Pendiente de decidir

- [ ] Los valores del referido y de la marca blanca (el distribuidor ya está decidido).
- [ ] Con el contador: si BryNex factura como Régimen Simple y las retenciones al distribuidor.
- [ ] Con un abogado: el contrato del distribuidor, en especial que no sea agencia comercial.
- [ ] Si más adelante se da descuento al distribuidor en afiliaciones y alianzas (hoy van al precio público).
- [ ] El valor de la hora de capacitación adicional.
- [ ] Confirmar el soporte deficiente: $30.000 por caso desde el tercero y los umbrales de quejas.
- [ ] ¿El referido gana 12 meses o mientras el aliado siga activo?
- [ ] ¿Cuántos afiliados se le piden al distribuidor para pasar a marca blanca,
      o basta con que pague el mínimo?
- [ ] ¿Los aliados actuales (Fecop, Luis López, Integra, Julieta) pueden volverse
      distribuidores de BryNex?
- [ ] El contrato: tratamiento de datos, qué pasa con los aliados si el
      revendedor se va, y que no puede vender por debajo de la lista.
