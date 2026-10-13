# Formato: notificación del trabajador para el subtipo de cotizante 4

Para el trabajador que cumple los requisitos mínimos de pensión de vejez y
decide ejercer el derecho del **inciso 2 del artículo 17 de la Ley 100 de 1993**
de dejar de cotizar a pensión.

> **Antes de usarlo:** este borrador se armó a partir de un análisis jurídico
> publicado (Asuntos Legales, ago-2026) y de la jurisprudencia citada, **no del
> articulado de la resolución**. Que lo revise un abogado laboral antes de
> ponerlo a circular con clientes. Ver `modalidad-e1-salud-sin-pension.md`.

## Dónde se radica y cuánto demora

**No se radica ante ninguna entidad.** No va a la UGPP, ni al operador de
información, ni al Ministerio. El trabajador se lo entrega **al empleador**, y
el empleador lo conserva como soporte de por qué dejó de cotizar pensión. No
hay término de respuesta porque no hay trámite externo: el empleador marca el
subtipo 4 en la siguiente planilla.

Lo que sí tiene tiempos es el camino alterno, por si el operador rechaza:

- El operador valida contra el *"Reporte de Información de Personas que están en
  Trámite de Solicitud de Pensión"*, que el Ministerio publica en el FTP seguro
  de cada operador.
- Ese reporte lo alimentan las administradoras de pensiones, que envían a la
  plataforma **PISIS del SISPRO** a más tardar **el día 24 de cada mes**.
- Es decir: si la persona entra al reporte, entra en el ciclo mensual
  siguiente. Pero entra porque **radicó solicitud de pensión** ante su AFP — y
  la Resolución 1740 de 2019 dice expresamente que eso **no se le puede exigir**.

Ese círculo es el que el Consejo de Estado rompió al anular la validación. Si el
operador rechaza con `eo.val.2.655`, el escalamiento va con ese argumento. No
hay un término conocido de respuesta del operador: tocaría medirlo.

---

## Texto del formato

```
[Ciudad], [fecha]

Señores
[RAZÓN SOCIAL DEL EMPLEADOR]
NIT [NIT]

Asunto: Notificación de cumplimiento de requisitos para pensión de vejez y
        solicitud de cese de cotizaciones al Sistema General de Pensiones

Yo, [NOMBRE COMPLETO], identificado(a) con [TIPO DOC] No. [NÚMERO], en mi
calidad de trabajador(a) vinculado(a) a [RAZÓN SOCIAL] desde el [FECHA DE
INGRESO], de manera respetuosa manifiesto:

PRIMERO. Que a la fecha reúno los requisitos mínimos para el reconocimiento de
la pensión de vejez establecidos en la normatividad vigente, así:

   • Edad:     [EDAD] años (fecha de nacimiento [FECHA])
   • Semanas:  [NÚMERO] semanas cotizadas al Sistema General de Pensiones

SEGUNDO. Que en ejercicio del derecho previsto en el inciso 2 del artículo 17
de la Ley 100 de 1993, modificado por el artículo 4 de la Ley 797 de 2003,
manifiesto mi decisión de NO continuar cotizando al Sistema General de
Pensiones a partir del período [MES/AÑO].

TERCERO. Que conforme lo señaló el Consejo de Estado, Sección Segunda,
Subsección A, en sentencia del 17 de marzo de 2021 (radicado
11001-03-25-000-2019-00538-00), la obligación de cotizar al Sistema General de
Pensiones cesa cuando el afiliado reúne los requisitos mínimos para acceder a
la pensión de vejez, sin que sea necesario el reconocimiento previo de la
prestación.

CUARTO. Que de acuerdo con la Resolución 1740 de 2019 del Ministerio de Salud y
Protección Social, no se me exige iniciar el trámite administrativo de
reconocimiento de la pensión para ejercer este derecho.

QUINTO. Que adjunto los documentos que acreditan el cumplimiento de los
requisitos señalados en el numeral primero:

   • [Historia laboral / reporte de semanas cotizadas, expedido por [AFP]]
   • [Documento de identidad]
   • [Otros]

SEXTO. Que declaro bajo la gravedad del juramento que la información y los
documentos aportados son veraces, y que asumo las consecuencias legales que se
deriven de esta decisión, manteniendo indemne a [RAZÓN SOCIAL] frente a
cualquier reclamación relacionada con el cese de las cotizaciones a pensión
aquí solicitado.

En consecuencia, solicito que a partir del período [MES/AÑO] se me reporte en
la Planilla Integrada de Liquidación de Aportes con el subtipo de cotizante
04 — "Cotizante con requisitos cumplidos para pensión", y se cesen los aportes
al Sistema General de Pensiones, manteniendo los aportes a los demás
subsistemas a los que haya lugar.

Atentamente,


_______________________________
[NOMBRE COMPLETO]
[TIPO DOC] No. [NÚMERO]
Correo: [CORREO]      Teléfono: [TELÉFONO]
```

---

## Qué hace el aportante después

1. Archiva la notificación y sus soportes en la hoja de vida del trabajador.
2. Marca **subtipo 4** en la planilla del período indicado y cesa el aporte a
   pensión. En BryNex eso sale solo: el calculador emite subtipo 3 o 4 cuando el
   contrato va sin fondo de pensión.
3. Si el operador rechaza con `eo.val.2.655`, escala citando la nulidad parcial
   del numeral 8 de la Resolución 3559 de 2018 (Consejo de Estado, Sección
   Segunda, Subsección A, 17-mar-2021).

---

## Si el operador rechaza: cómo se escala

**Antes de nada:** tener el rechazo por escrito y con el código. El
`eo.val.2.655` se reproduce por API y por el portal, y está documentado sobre
una persona real que figura con subtipo 4 en una planilla **pagada** del
21-ago-2026 por ese mismo operador. Esa contradicción —aceptó y cobró lo que
hoy rechaza— es la evidencia más fuerte disponible.

**1. El operador — PQR formal.** La Superfinanciera obliga a los operadores de
información a llevar registro de las PQR y a conservarlo mientras la queja esté
viva, así que hay que exigir que quede **radicada como PQR**, no como consulta.
Pedir justificación escrita del rechazo y la norma vigente que lo sustenta,
frente a la nulidad parcial del numeral 8 de la Resolución 3559 de 2018.

**2. Superintendencia Financiera.** Desde el 10-jul-2012 (Decreto 19 de 2012)
ejerce inspección y vigilancia sobre la actividad de los operadores de
información de la PILA. Es el siguiente escalón si el operador no resuelve.

**3. Ministerio de Salud y Protección Social.** Es quien autoriza a los
operadores y quien expide las resoluciones de PILA y sus anexos técnicos. Si lo
que se discute es que el operador aplica una validación anulada o superada por
la Resolución 1740 de 2019, es el lugar donde se aclara la norma. (La
Superintendencia Nacional de Salud **no** tiene ese rol aquí.)

Antecedente útil: el Ministerio ya sostuvo que las dificultades de los
operadores "no pueden ser excusa para la negación de servicios de salud, ni
pueden generar intereses de mora a los aportantes".

> Los pasos 2 y 3 son inferencia de quién vigila a quién, no un procedimiento
> publicado. Confirmar los canales oficiales antes de radicar, y que un abogado
> maneje la escalera: el argumento de fondo es una nulidad de acto
> administrativo.

---

## Borrador de PQR al operador

> **No radicar sin un caso concreto.** Una PQR que solo cita normas se responde
> con "la validación aplica". La que pesa es la que nombra un trabajador que
> cumple requisitos, notificó por escrito, fue reportado con subtipo 4 y fue
> rechazado. Y antes de radicar, revisar que la ficha del aportante esté
> correcta: el operador va a mirar eso primero.

```
Señores
[OPERADOR DE INFORMACIÓN]

Asunto: Solicitud de revisión del rechazo eo.val.2.655 — subtipo de cotizante 04
        Aportante [RAZÓN SOCIAL], NIT [NIT]
        Radicación como PQR

En nuestra condición de aportante registrado ante ustedes, solicitamos revisión
del rechazo que su plataforma aplica al subtipo de cotizante 04, en los
siguientes términos:

HECHOS

1. El trabajador [NOMBRE], identificado con [DOC], acredita [SEMANAS] semanas
   cotizadas y [EDAD] años de edad, cumpliendo los requisitos mínimos para el
   reconocimiento de la pensión de vejez.

2. El [FECHA] nos notificó por escrito su decisión de no continuar cotizando al
   Sistema General de Pensiones, en ejercicio del derecho previsto en el inciso
   2 del artículo 17 de la Ley 100 de 1993, aportando los soportes
   correspondientes. Copia de la notificación se anexa.

3. Al reportarlo con subtipo de cotizante 04 en la planilla del período
   [PERÍODO], su plataforma devolvió:

      eo.val.2.655 — "El cotizante no puede hacer uso del subtipo de cotizante 4.
      Puede dirigirse a los canales de atención dispuestos por la UGPP..."

FUNDAMENTOS

4. La Resolución 1740 de 2019 del Ministerio de Salud y Protección Social
   establece que no se exige al cotizante que cumple los requisitos iniciar el
   trámite administrativo de reconocimiento de la pensión.

5. El Consejo de Estado, Sección Segunda, Subsección A, en sentencia del 17 de
   marzo de 2021, declaró la nulidad parcial del numeral 8 de la Resolución
   3559 de 2018, incluyendo el aparte que obligaba al operador de información a
   validar al cotizante contra un reporte externo, por imponer un requisito que
   el artículo 17 de la Ley 100 de 1993 no establece.

6. En la misma providencia se sostuvo que la obligación de cotizar al Sistema
   General de Pensiones cesa cuando el afiliado reúne los requisitos mínimos,
   sin que sea necesario el reconocimiento previo de la prestación.

SOLICITUDES

PRIMERA.  Que se registre esta comunicación como PQR y se nos informe su número
          de radicado.

SEGUNDA.  Que se nos indique por escrito la norma vigente que sustenta la
          validación que origina el rechazo eo.val.2.655, precisando su
          compatibilidad con la nulidad parcial señalada en el numeral 5.

TERCERA.  Que se permita el reporte del trabajador referido con subtipo de
          cotizante 04 para el período [PERÍODO] y los siguientes, conforme a
          la notificación que se anexa.

CUARTA.   Que se nos informe el procedimiento que su plataforma dispone para
          que un cotizante reporte esta novedad, conforme a la Resolución 1740
          de 2019.

Anexos: notificación del trabajador y soportes; captura del rechazo.

Atentamente,
[REPRESENTANTE LEGAL / APODERADO]
```

Si la respuesta no resuelve, los escalones siguientes son la Superintendencia
Financiera y el Ministerio de Salud — ver la sección anterior.
