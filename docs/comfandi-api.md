# Comfandi por API (en vez del robot headless)

Reconocimiento con sesión real de **Lala Confecciones** (NIT 902103184, usuario
Comfandi `9021031841`), 8-nov-2026. El portal `sakaar` es solo una capa sobre una
API REST en `ria.sucursalcomfandi.com`. Meta: reemplazar
`scripts/comfandi-subsidios.mjs` + `ComfandiSubsidiosHeadless` y cubrir todo el
flujo de caja Comfandi por API.

## Alcance pedido (lo que debe hacer la integración)

1. **Afiliar** al cotizante y sus beneficiarios (escritura).
2. **Sacar certificados** con beneficiarios (documento).
3. **Leer beneficiarios** para llenar la tabla de BryNex.
4. **Guardar en BryNex los documentos** de beneficiarios.
5. **Estado de subsidios por mes**: bloqueado / pagado / cobrado.

## Autenticación (Keycloak)

- Realm `https://iam.comfandi.com.co/auth/realms/empresas`, cliente
  `afiliaciones-empresas` (público, PKCE). **Direct grant CERRADO**
  (`grant_type=password` → `unauthorized_client`). Soporta `refresh_token` y
  scope `offline_access`.
- Token endpoint: `.../protocol/openid-connect/token`.
- **El portal OBLIGA TOTP** (required-action `CONFIGURE_TOTP`). TOTP estándar
  (HMAC-SHA1, 6 díg., 30 s, clave = bytes crudos del `totpSecret`; el QR es
  `base32` de esos bytes). Configurado y verificado para Lala.
  Ver [[comfandi-api-y-totp-obligatorio]].
- **Estrategia:** login auth-code + PKCE una vez (clave + TOTP del secreto)
  pidiendo `scope=openid offline_access` → guardar el **offline refresh_token** →
  cada corrida `grant_type=refresh_token` → access_token (JWT Bearer), sin 2FA.

## Endpoints (host `https://ria.sucursalcomfandi.com`) — PROBADOS

Headers: `Authorization: Bearer <jwt>` **+ `x-company-id: <GUID>`**.
Sin token → 401 AUTH_FAILED. Con token sin x-company-id → 403 "No tiene permisos".

1. **Contexto de empresa** → de aquí sale `x-company-id`:
   `GET /company-access/me/company-context`
   (Lala: GUID `25243509-2caf-4d28-86d7-0430f77a03fe`, NO es el NIT).

2. **Trabajador / listado**:
   `GET /sap?affilationDate=AAAAMMDD&affiliateTypeDoc=CO1N&affiliateNit=<NIT+DV>&page=1&maxLength=N&documentType=CO1C&documentNumber=<ced>`
   → 200 `{ TotalRegistros, Titular, Log, Pagina, TotalPaginas }`.
   Sin `documentNumber` + `maxLength` grande = listado completo.
   Tipos doc: CC=CO1C, CE=CO1E, TI=CO1T, PT/PPT/PE=CO1Y; empresa=CO1N.

3. **Subsidio monetario (estado por mes)**:
   `GET /monetary-subsidy/getMovements?movementType=GENERAL&fechaInicio=AAAA-MM-DD&fechaFin=AAAA-MM-DD&offset=0&limit=10&employeeIdType=CC&employeeIdNumber=<ced>`
   Acepta rango libre (probado con 1 año). Devuelve movimientos:
   - **Bloqueo (B)**: valor negativo, `Periodo de bloqueo`, `Motivo` (p. ej.
     "Bloqueo en Espera de Aportes"), fecha.
   - **Pago/Disponible (M)**: `Periodo desembolsado`, fecha de pago, beneficiario,
     valor.
   - **Retiro/cobrado (R)**: "Retiro ... Efecty", periodo, fecha, representante,
     beneficiario, valor.

## Endpoints por capturar (sesión limpia; la UI por coordenadas es frágil)

- **Beneficiarios** (leer): pantalla "Gestión de beneficiarios"
  (`/sakaar/workers/pacs-managment`); muestra nombre, tipo/nº doc, relación,
  fecha de afiliación. Capturar su GET.
- **Certificado trabajador y beneficiarios** (PDF): botón en el menú Gestionar.
- **Afiliar** (POST): flujo "Afiliar trabajador" (NO enviar en pruebas).
- Catálogos del formulario (tipos contrato/salario/horas/nivel) ya mapeados en
  `ComfandiCajaService::PREDETERMINADOS`.

## ⚠️ Akamai: la API NO se puede llamar desde el servidor (8-nov-2026)

Probado desde netcup:
- `GET ria.sucursalcomfandi.com/sap` → **403 "Access Denied" de Akamai**
  (página "Reference #…", IP de borde Akamai). Un `curl`/Guzzle plano queda
  bloqueado, tenga o no token. Es lo mismo que rechaza al Chrome sin ventana;
  Akamai valida el **navegador**, no la IP → un proxy tampoco lo arregla
  (ver [[produccion-mod-php-permisos]] y la nota de proxy en
  `ComfandiSubsidiosHeadless`).
- `iam.comfandi.com.co` (Keycloak) → 200, sí alcanzable.
- **Proxy Colombia tampoco pasa:** a través de `PROXY_COLOMBIA` (IP CO real) la
  API sigue dando 403 Access Denied. Confirma que el bloqueo es por *fingerprint
  de navegador*, no por IP/país. Impersonar un navegador para engañar a Akamai
  queda descartado (es burlar la detección de bots).

**Login a 60 días (no toca Akamai, resuelve el 2FA):** tras verificar el TOTP,
Comfandi ofrece "No volver a preguntar en 60 días" ligado a ese navegador+cookies.
Si el Chrome de netcup usa un **perfil persistente** (user-data-dir fijo), la
cookie de confianza sobrevive y el TOTP solo hace falta una vez cada 60 días (o
si se borra el perfil). El navegador sigue siendo necesario por Akamai.

**Conclusión:** no se puede botar el navegador por completo. La API sí sirve,
pero **las llamadas hay que hacerlas DESDE el navegador real** (xvfb Chrome, que
pasa Akamai). O sea: el robot se queda para (a) pasar Akamai y (b) el login con
TOTP, pero **deja de raspar el DOM y usa `fetch()` a la API JSON** adentro de la
página. Eso es mucho más robusto y destraba las 5 capacidades (afiliar por POST,
subsidios, beneficiarios, certificados) con datos estructurados.

## Un solo usuario gestor para todas las empresas (decisión 9-nov-2026)

En "Administración de usuarios" de cada empresa se agrega un **usuario gestor**
común (Brayan García, CC `1143944458`, `brayan3000@gmail.com`). Si ese gestor
está en TODAS las razones sociales:

- **Un solo login** (CC del gestor + su clave) y **un solo secreto TOTP** para
  todo — no uno por NIT.
- `GET /company-access/me/company-context` lista **todas** sus empresas; por
  operación se escoge el `x-company-id` de la que toca.
- **Un solo perfil persistente** de Chrome (confianza 60 días) para el gestor.

Esto reemplaza el esquema de "credencial por empresa" de `clave_accesos`: la
credencial del gestor + su secreto TOTP se guardan **una vez** (config o una fila
dedicada), y el mapa NIT → `x-company-id` sale de company-context.

## Plan de implementación (navegador + fetch a la API)

1. `scripts/comfandi-subsidios.mjs` (o uno nuevo): login con **TOTP** (generado
   del secreto; se acabó el "Omitir"), seleccionar empresa, y luego
   `page.evaluate(fetch(...))` a los endpoints en vez de leer el DOM.
   - Headers: Bearer (del token en memoria del portal) + `x-company-id` (de
     `/company-access/me/company-context`).
2. Endpoints: `/sap` (trabajador/listado), `/monetary-subsidy/getMovements`
   (subsidios), beneficiarios, certificado, afiliar (por capturar).
3. `ComfandiApi` (PHP) orquesta: lanza el navegador headless una vez por
   corrida, pasa clave + secreto TOTP por stdin, recibe el JSON ya parseado.
4. Guardar el **secreto TOTP** por empresa (migración en `clave_accesos` o tabla
   aparte, cifrado). El offline token pierde sentido si igual hay que abrir el
   navegador para Akamai: basta el login con TOTP por corrida.
5. Guardar beneficiarios y sus documentos en BryNex.

## Resultados de la corrida de descubrimiento (9-nov-2026)

Probado end-to-end desde netcup con el gestor Brayan (CC 1143944458):

- **Login del gestor es por CC, no NIT.** El robot forzaba NIT (login de
  empresa); se saltó esa selección para CC (el default del combo). `tipoLogin`
  en el `.mjs`.
- **Las empresas del gestor + sus GUID vienen en el TOKEN** (claim `companies`:
  `{id: <GUID=x-company-id>, identification: "<NIT>-<DV>", name, roles, is_affiliated}`).
  `/company-access/me/company-context` da 403 para el gestor: NO se usa.
- **`affiliateNit` va CON dígito de verificación** (9021031841, no 902103184);
  sale de `identification` del token.
- **`/sap` devuelve trabajador + BENEFICIARIOS** en `Titular[].PACS[]`
  (documento, parentesco, nombres, fechas). Cubre leer beneficiarios.
- **Subsidios `/monetary-subsidy/getMovements` — RESUELTO.** GET con
  `movementType=GENERAL` (trae pagos+retiros+bloqueos), rango de fechas,
  `employeeIdType=CC&employeeIdNumber`, headers **Accept + Authorization +
  x-company-id**. La respuesta 200 es `{ data: { total, movements:[...] }, ... }`
  (los movimientos están en **`data.movements`**, no en `data`). Cada movimiento:
  `{ movementType: BLOQUEO|PAGO|RETIRO, movementTitle, amount, date(YYYYMMDD),
  period(YYMM), blockReason, company, beneficiaries:[{name, amount, pacDocument,
  pacDocType}] }`.
  Un fetch directo del robot da 400 fuera de la sesión del portal; la vía que
  SÍ funciona: abrir la pantalla de Subsidio monetario (dispara getMovements →
  se captura con `page.on('request')` el token Bearer y el x-company-id DE ESA
  llamada), y **re-ejecutar getMovements con ese token** para el rango completo
  (el datepicker deja de ser crítico). Implementado en `subsidios` modo api.
- **Ojo (ya corregido):** con perfil persistente la sesión puede seguir viva y
  el login no aplica → hay que detectar que ya está dentro y saltarlo. Y cuidado
  con el shadowing de `hasta` (el bucle de login tenía su propio `const hasta`).
- El perfil persistente de Chrome quedó con la confianza de 60 días en netcup
  (storage/app/comfandi-perfiles/1143944458), así que ya no pide 2FA por corrida.
- OJO: la clave del gestor guardada en el módulo tenía un carácter mal (no-ASCII)
  y el login fallaba; la correcta es ASCII. Hay que dejarla bien en el módulo.

## Pendiente

- **Subsidios:** capturar de la UI la forma exacta del `getMovements`.
- **Afiliar / certificados / documentos** (POST y descargas) por capturar.
- **Wiring PHP:** `ComfandiApi` debe leer la clave+TOTP del gestor del módulo
  (por usuario 1143944458) y resolver x-company-id del token; añadir campo TOTP
  al módulo de claves (migración).
- Agregar al gestor Brayan en TODAS las razones sociales de Comfandi (hoy solo
  está en Lala).
