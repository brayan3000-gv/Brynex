---
name: planos-brynex
description: >
  Generación de archivos planos y Excel para operadores de planilla de seguridad social.
  Actívate cuando el usuario mencione: plano, PILA, archivo plano, TXT planilla,
  NI (Novedad Individual), Aportes en Línea, Asopagos, MiPlanilla, SOI,
  PlanoPilaTxtService, ExcelPlanoNIService, ExcelAportesEnLineaService,
  ExcelAsopagosService, ExcelMiPlanillaService, PlanoPagoController, descarga de planilla.
---

# Skill: Planos y Exportaciones de Planilla Brynex

## Servicios de Exportación

| Servicio | Archivo | Formato | Operador |
|---|---|---|---|
| `PlanoPilaTxtService` | `.txt` | PILA fijo | Todos los operadores |
| `ExcelPlanoNIService` | `.xlsx` | Excel | NI (Novedad Individual) |
| `ExcelAportesEnLineaService` | `.xlsx` | Excel | Aportes en Línea |
| `ExcelAsopagosService` | `.xlsx` | Excel | Asopagos |
| `ExcelMiPlanillaService` | `.xlsx` | Excel | Mi Planilla |
| `PilaCotizanteCalculator` | — | Servicio | Cálculo de aportes |

## Modelo Plano

```php
// Tabla: planos
// Columnas principales:
// - aliado_id, contrato_id, factura_id
// - mes, anio
// - tipo_reg (tipo de registro PILA: 1=normal, 2=novedad, etc.)
// - tipo_p (subtipo específico del operador)
// - numero_planilla, numero_factura
// - nombre_eps, nombre_pension, nombre_arl, nombre_caja
// - datos del cotizante: cedula, salario, ibc, días, etc.
// - deleted_at (soft delete)
// - id_legacy (FK a Brygar_BD para registros migrados)
```

## Formato PILA (TXT)

El archivo plano PILA tiene registros de longitud fija separados por `|`:
```
TipoReg|TipoDoc|NumDoc|PrimerApellido|SegundoApellido|PrimerNombre|...
```

Reglas:
- Campos numéricos: rellenar con ceros a la izquierda
- Campos alfanuméricos: rellenar con espacios a la derecha
- Decimales: sin punto decimal (ej: `150000` = `$150.000`)
- Secuencia: registro de control → registros de cotizantes → registro de cierre

### Sucursal del aportante (registro tipo 1, campos 12-13)
La sucursal es **del aliado**, no de la empresa: una razón social prestada tiene
una sucursal por aliado ante el operador (01 Brygar, 02 Fecop, 03 Luis López,
04 Integra Duitama). Se configura en la ficha del aliado
(`aliados.codigo_sucursal` / `nombre_sucursal`) y `SucursalOperador::aplicar($rs)`
la pone en la razón social antes de generar el TXT y los Excel (NI, Aportes en
Línea 1 y 2). Si el aliado no tiene, se usa la de la razón social. La razón
social de independientes nunca lleva sucursal.

## Campos Calculados por `PilaCotizanteCalculator`

```php
// Aportes calculados sobre el IBC:
$aporteEPS    = $ibc * 0.1250;  // 12.5% (empleador + trabajador)
$aportePension = $ibc * 0.1600; // 16% (empleador + trabajador)  
$aporteARL    = $ibc * ($tarifaArl / 100);
$aporteCCF    = $ibc * 0.04;    // 4%

// Para tiempo parcial: IBC proporcional a días trabajados
// min(ibc, smmlv * factor_dias)
```

## Vistas del Módulo

```
resources/views/admin/planos/
├── index.blade.php         ← Lista de planos generados
├── show.blade.php          ← Detalle de un plano
└── partials/               ← Acciones de descarga
```

## Botones de Descarga (Nomenclatura Actual)

| Botón en UI | Acción |
|---|---|
| "Descargar Txt para todos los operadores" | `PlanoPilaTxtService` → `.txt` |
| "Descargar Excel NI" | `ExcelPlanoNIService` → `.xlsx` |
| "Descargar Excel Aportes en Línea" | `ExcelAportesEnLineaService` → `.xlsx` |
| "Descargar Excel Asopagos" | `ExcelAsopagosService` → `.xlsx` |

## Generación de Plano (Flujo)

1. Se genera a partir de las **facturas pagadas** del mes
2. `PlanoPagoController` orquesta la generación
3. Cada factura → un registro de cotizante en el plano
4. El plano se guarda en `storage/app/planos/`
5. Se registra en la tabla `planos` con `factura_id`

## Notas Importantes

- `tipo_p` en SQL Server es `smallint` (ampliar a `int` si se necesitan más de 32,767 valores)
- Los nombres de entidades (`nombre_eps`, etc.) se almacenan desnormalizados en la tabla `planos` para preservar el valor histórico aunque cambie la entidad
- `id_legacy` para trazabilidad con Brygar_BD
