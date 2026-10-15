# Brynex

Plataforma de gestión de seguridad social para aliados (afiliación, contratos,
facturación, cobros, incapacidades) + un módulo de finanzas personales del
dueño, todo bajo Laravel 10.

## ⚠️ Reglas críticas — leer antes de tocar nada

1. **La base de datos local ES la de producción.** No hay entorno de staging.
   `DB_CONNECTION=sqlsrv` en `.env` apunta al SQL Server de **netcup**
   (`159.195.233.132`, migrado el 17-ago-2026) → BD `BryNex`.
   Ese 1433 **solo escucha en loopback**: desde el Mac se llega abriendo el
   túnel `ssh -fN netcup-db`, y por eso el `.env` local dice
   `DB_HOST=127.0.0.1`. Sin el túnel arriba, nada conecta.
   Nunca ejecutar `migrate:fresh`, `migrate:reset`, `db:wipe`, `DROP TABLE` ni
   `TRUNCATE` sin confirmación explícita del usuario.

2. **Los tests corren contra la BD real.** `phpunit.xml` tiene el override a
   SQLite **comentado**:
   ```xml
   <!-- <env name="DB_CONNECTION" value="sqlite"/> -->
   <!-- <env name="DB_DATABASE" value=":memory:"/> -->
   ```
   Sin ese override, `php artisan test` usa la conexión real. **No correr la
   suite de tests sin antes descomentar esas dos líneas** (o confirmar con el
   usuario que se quiere correr contra producción, lo cual normalmente no se
   quiere).

3. **Mergear a `main` despliega a brynex.co.** Cada push a `main` dispara el
   flujo *Desplegar* de GitHub Actions, que lleva el código a netcup
   (`/var/www/brynex`) — ver la sección *Despliegue*. Las migraciones que solo
   agregan corren solas; si alguna borra datos, el despliegue se detiene sin
   tocar nada y avisa por WhatsApp. Por eso nunca poner un script ejecutable
   dentro de `public/` "solo para probar algo"
   — ver `docs/auditoria-seguridad.md`, hallazgos C-1/C-2/C-3: así se filtraron
   tres scripts que quedaron accesibles por URL. `storage/app/public` NO se
   sincroniza a producción; solo el código y la BD.

4. **Migraciones incrementales, nunca recrear tablas.** Varias tablas son
   legacy con `id` sin `IDENTITY` (ver `Empresa`) — revisar la migración de
   creación antes de asumir el patrón estándar de Laravel.

5. **Multi-tenant sin scope automático.** 91 de 92 modelos extienden
   `BaseModel`, que solo normaliza fechas — no hay `addGlobalScope` por
   `aliado_id`. Toda query debe filtrar explícitamente por
   `session('aliado_id_activo')`. Ver [[permisos-brynex]] antes de escribir
   un controlador nuevo que acceda a un registro por id.

## Stack

| Capa | Tecnología |
|---|---|
| Backend | Laravel 10 (PHP 8.1+) |
| BD principal | SQL Server (`sqlsrv`) — BD `BryNex` |
| BD legacy | SQL Server (`Brygar_BD`) — solo lectura, migración |
| BD finanzas | SQL Server, conexión `finanzas` separada — BD `BryNex_Finanzas` |
| Frontend | Blade + Alpine.js + Vite |
| PDF | DomPDF + FPDF/FPDI |
| Excel | PhpSpreadsheet |
| Permisos | Spatie Laravel Permission v6 (roles definidos, poco aplicados — ver [[permisos-brynex]]) |
| WebSockets | Laravel Reverb |
| WhatsApp | HTTP API propia via Guzzle (Meta Cloud API) |
| IA | Proveedor configurable (Claude/OpenAI/Gemini) — ver [[ia-brynex]] |

## Comandos

```bash
php artisan serve                 # servidor local
npm run dev                       # Vite (assets)
php artisan migrate               # SOLO migraciones incrementales
php artisan pint                  # formateo PHP (Laravel Pint)
php -l ruta/al/archivo.php        # chequeo de sintaxis rápido, sin bootear Laravel
```

### Despliegue

```bash
./scripts/desplegar.sh --dry-run   # qué se desplegaría, sin tocar nada
./scripts/desplegar.sh             # despliega origin/main a netcup
./scripts/desplegar.sh --migrate   # además corre las migraciones que borran datos
```

**Al mergear a `main` se despliega solo**, por el flujo *Desplegar* de GitHub
Actions. Las migraciones que solo agregan (tablas, campos, índices) se aplican
solas. Si alguna **borra datos** —quita una tabla o un campo, vacía o borra
filas, cambia el tipo de un campo— el despliegue se detiene antes del pull, sin
tocar nada, y avisa por WhatsApp; se revisa y se lanza a mano con *migrar*. Una
migración que solo agranda un campo lo declara con `// no-borra-datos: <por
qué>` (ver [[laravel-migracion]]).

**También se lanza a mano**, sin la Mac: GitHub → *Actions* → *Desplegar* →
*Run workflow* (sirve desde la app de GitHub en el iPhone). Tiene tres
opciones: `modo` (`dry-run` por defecto, o `desplegar`), `migrar` (apagado por
defecto; prendido aplica también las que borran datos) y `reverb`. Solo corre desde
`main` y de a uno a la vez (`.github/workflows/desplegar.yml`). Entra con la
llave del secret `NETCUP_SSH_KEY`, que en el servidor no da shell: tiene un
`command=` forzado (`/usr/local/sbin/brynex-deploy-gh`) que solo acepta
`--dry-run`, `--migrate` y `--reverb`, y corre el `scripts/deploy.sh` de
`origin/main`. `desplegar.sh` desde la Mac sigue funcionando igual.

El push a GitHub lo hace el usuario; el script solo lleva al servidor lo que ya
esté en `origin/main`. Hace el `git pull`, devuelve los archivos a `www-data`
(git corre como root y si no, Apache pierde la escritura), reinstala
dependencias si cambió `composer.lock`, limpia y recompila las vistas y
reinicia los workers. Si el despliegue trae migraciones que borran datos **se
detiene antes del pull** salvo que se pase `--migrate`: la base de datos es la
de producción.

El trabajo real está en `scripts/deploy.sh`, que se envía por stdin al servidor
en vez de guardarse allá, para que nunca corra una versión desactualizada de sí
mismo ni se reescriba a la mitad de un `git pull`.

Para entrar a tinker en producción, siempre como `www-data` (nunca como root,
que deja archivos sin escritura para Apache):

```bash
ssh netcup 'cd /var/www/brynex && sudo -u www-data php artisan tinker'
```

Eso funciona porque `/var/www/.config` es de `www-data` — sin ese directorio,
psysh muere con *"Writing to directory /var/www/.config/psysh is not allowed"*.
El script de despliegue lo rehace en cada corrida, así que no hay que acordarse.

Tests: ver advertencia #2 arriba antes de correr `php artisan test`.

## Estructura

```
app/
├── Http/Controllers/
│   ├── Admin/          ← paneles del aliado (45 controladores)
│   ├── Finanzas/        ← finanzas personales del dueño (ver [[finanzas-brynex]])
│   └── Publico/         ← páginas públicas de aliado, sin auth
├── Models/               ← 92 modelos Eloquent, casi todos extendiendo BaseModel
├── Services/             ← lógica pesada (Excel, PDF, planos, WhatsApp, IA)
├── Console/Commands/     ← scripts de mantenimiento como comandos Artisan
                            (NUNCA como script suelto en la raíz o en public/)
└── helpers.php

resources/views/admin/    ← vistas Blade por módulo (ver [[blade-alpine-brynex]])
routes/web.php            ← 474 rutas, casi todo bajo Route::middleware('auth')
docs/auditoria-seguridad.md ← auditoría de seguridad y su estado de corrección
```

## Skills del proyecto

Documentación de dominio en `.claude/skills/` — consultar el que aplique
antes de tocar un módulo de negocio:

- [[contratos-brynex]] — contratos, planes, modalidades, ARL, PILA
- [[afiliaciones-brynex]] — cotizador público, gestión de vigencia ARL, radicados
- [[alta-afiliado-brynex]] — alta desde el chat: ficha + contrato + afiliación ARL (cédula y datos sueltos)
- [[facturacion-brynex]] — facturación, mora, anticipos, distribución de cobros
- [[cobros-brynex]] — cuadre diario, consignaciones, caja menor
- [[incapacidades-brynex]] — incapacidades médicas, prórrogas, abonos
- [[planos-brynex]] — archivos planos PILA para operadores de planilla
- [[whatsapp-brynex]] — WhatsApp Business API, plantillas, webhooks
- [[ia-brynex]] — asistente de IA, proveedores, tools, conocimiento
- [[razones-sociales-brynex]] — obligaciones DIAN, claves y consolidado por NIT (módulo del contable de BryNex)
- [[finanzas-brynex]] — finanzas personales del dueño (módulo aparte, acceso exclusivo)
- [[permisos-brynex]] — roles Spatie, `es_brynex`, aliado activo en sesión
- [[blade-alpine-brynex]] — patrones de UI (Blade + Alpine.js)
- [[laravel-migracion]] — cómo escribir migraciones incrementales para SQL Server

## Convenciones

- `snake_case` en columnas y tablas de BD.
- Español en nombres de dominio (`Contrato`, `Cliente`, `Aliado`, `Radicado`),
  inglés en lo genérico de framework.
- Un controlador nuevo que acceda a un registro por `id` debe filtrar por
  `aliado_id` desde el primer query, no agregarlo después — ver el patrón en
  `ContratoController` o `IncapacidadController` (tras la corrección de IDOR
  documentada en `docs/auditoria-seguridad.md`, C-5).
- Documentos con datos personales o sensibles van al disco `local`
  (`storage/app`), nunca a `public` — ver C-4 en la auditoría.
