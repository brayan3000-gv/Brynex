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
