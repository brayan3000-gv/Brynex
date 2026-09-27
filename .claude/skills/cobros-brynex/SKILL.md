---
name: cobros-brynex
description: >
  Módulo de cobros, cuadre diario, consignaciones, anticipos y gestión financiera en Brynex.
  Actívate cuando el usuario mencione: cobro, cobros, cuadre, cuadre diario, consignación,
  anticipo, abono, saldo banco, caja menor, mora, CobrosController, CuadreDiarioController,
  AnticipoController, BitacoraCobro, gestión de cartera, pagos pendientes.
---

# Skill: Cobros y Gestión Financiera Brynex

## Módulos Financieros

```
Controllers:
├── CobrosController.php         ← Gestión principal de cobros (~101KB)
├── CuadreDiarioController.php   ← Cierre de caja (~38KB)
├── AnticipoController.php       ← Anticipos a facturas (~27KB)
├── GastoAdminController.php     ← Registro de gastos

Models:
├── Consignacion.php    ← Comprobantes bancarios
├── Anticipo.php        ← Anticipos (con soft delete)
├── Abono.php           ← Pagos parciales sobre facturas
├── Cuadre.php          ← Cierre de caja diario
├── CajaMenor.php       ← Caja menor
├── SaldoBanco.php      ← Registro de saldos bancarios
├── Gasto.php           ← Gastos administrativos
├── BancoCuenta.php     ← Cuentas bancarias del aliado
└── BitacoraCobro.php   ← Auditoría de acciones de cobro
```

## Anticipos

```php
// Tabla: anticipos
// - aliado_id, contrato_id (o cliente_id)
// - valor_total, saldo_disponible
// - fecha_pago
// - dist_encargado, dist_asesor  ← Distribución del anticipo
// - deleted_at (soft delete desde 2026-06-18)

// Se aplica automáticamente al generar facturas:
// factura.anticipo_aplicado = min(anticipo.saldo, factura.valor_total)
```

## Consignaciones

```php
// Tabla: consignaciones
// - aliado_id
// - banco_cuenta_id   ← Cuenta bancaria destino
// - valor
// - fecha_consignacion
// - imagen             ← Comprobante adjunto
// - tipo: 'ingreso', 'anticipo', 'gasto'
// - cuadre_id          ← FK al cuadre donde se registró
// - gasto_id           ← FK al gasto asociado (si aplica)
```

## Cuadre Diario

El cuadre agrupa:
1. **Consignaciones del día** (ingresos por cobros)
2. **Gastos del día**
3. **Saldo inicial del banco**
4. **Cálculo del saldo final**

```php
// Flujo:
// 1. CuadreDiarioController::crear() → nuevo Cuadre para la fecha
// 2. Asociar consignaciones y gastos al cuadre
// 3. Calcular saldo: saldo_inicial + ingresos - gastos
// 4. Registrar SaldoBanco resultante
```

## Cobro en visita (celular) — `/visita`

Pantalla móvil para cobrar puesto por puesto en una empresa visitada
(`VisitaCobroController`, `VisitaCobroService`, `ReciboVisitaService`). No
tiene cálculo ni caja propios:

- **Lo que debe** sale de `FacturacionController::mesPagado` (período, saldo a
  favor, mora) + `CobroContratoService::calcular`, más los anticipos del
  contrato. El período es el mismo que propone el modal al abrirse.
- **Si alcanza, factura**: llama a `FacturacionController::facturar` con el
  mismo cuerpo que arma `modal_facturar_v2.js` (tipo planilla, anticipos
  previos, `aplicar_saldo`). La transferencia entra completa (no se parte: la
  conciliación la casa por valor); el efectivo pone lo que falta y el
  **sobrante queda como anticipo**. Si el backend responde
  `PagoIncompletoException` por unos pesos, se reintenta una vez con el
  efectivo sobrante.
- **Si no alcanza** (o es mes de afiliación, contrato que inicia después, hueco
  de meses), todo queda como anticipo, igual que `AnticipoController::store`.
- La **mora no se puede quitar** desde la calle.
- **Sin caja aparte**: todo cae en la caja del día del usuario. Los registros
  de la pantalla llevan `observacion` "Cobro en visita · …".
- **Recibo**: PDF tipo tirilla (`pdf/recibo_visita`) enviado con la plantilla
  Meta `recibo_de_pago` (header DOCUMENT). La crea/consulta
  `php artisan whatsapp:plantilla-recibo [--estado]` en la WABA de BryNex y en
  las de aliados con número propio.

## BitacoraCobro

Registra cada acción de cobro:
- `tipo`: `llamada`, `whatsapp`, `email`, `visita`, `acuerdo_pago`
- `resultado`: `exitoso`, `sin_respuesta`, `promesa_pago`, `enviado_whatsapp`
- `factura_id`: factura gestionada
- `razon_social_id`: empresa del cliente
- `observacion`: notas del ejecutivo

## Mora

```php
// MoraClienteService calcula la mora según:
// - config_aliado.mora_porcentaje (% mensual)
// - config_brynex.mora_dias_gracia (días sin cobrar mora)
// - días de atraso desde la fecha de vencimiento

// La mora se recalcula al abrir una factura vencida
```

## Rutas Principales de Cobros

```
GET  /admin/cobros                          → lista de facturas pendientes
GET  /admin/cobros/empresa/{empresa}        → facturas por empresa
POST /admin/cobros/{factura}/registrar-pago → registrar consignación/pago
POST /admin/cobros/{factura}/bitacora       → agregar gestión de cobro
POST /admin/cobros/whatsapp/enviar          → enviar mensaje de cobro
```

## Vistas del Módulo

```
resources/views/admin/cobros/
├── index.blade.php        ← Cartera con filtros
├── empresa.blade.php      ← Facturas de una empresa
└── partials/              ← Modales de pago y gestión

resources/views/admin/cuadre-diario/
├── index.blade.php        ← Histórico de cuadres
└── show.blade.php         ← Detalle del cuadre

resources/views/admin/anticipos/
├── index.blade.php
└── show.blade.php
```
