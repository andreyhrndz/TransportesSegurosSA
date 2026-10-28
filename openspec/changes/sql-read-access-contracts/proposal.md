## Why

El catálogo actual sólo define permisos de acción y `REPORTES_VER`; el frontend usa temporalmente `CLIENTES_CREAR` para consultar. Para completar módulos sin conceder escritura a quien sólo consulta se necesitan contratos SQL explícitos de lectura.

## What Changes

- Especificar permisos individuales de consulta por dominio y su verificación mediante `dbo.fn_tiene_permiso`.
- Especificar procedimientos o vistas de lectura con columnas mínimas, filtros parametrizados y paginación.
- Definir migración, pruebas de denegación y compatibilidad temporal para clientes actuales.

## Alcance, exclusiones y riesgos

No modifica datos, tablas ni frontend en este change; tampoco introduce roles. Riesgo: permisos insuficientes o exposición de PII; se mitiga con listas de columnas, pruebas con cuenta sin permiso y revisión DBA.

## Capabilities

### New Capabilities
- `sql-read-access`: Contratos SQL de lectura autorizada para los módulos operativos.

### Modified Capabilities
- Ninguna.

## Impact

Requerirá script SQL aprobado por separado y desbloqueará clientes, maestros, solicitudes, viajes, entregas, reportes y auditoría.
