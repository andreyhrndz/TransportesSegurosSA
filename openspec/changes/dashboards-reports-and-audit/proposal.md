## Why

El dashboard actual usa cuatro consultas directas y una lista reciente; SQL Server ya provee vistas operativas, auditorías y un DW con ETL, pero no existe experiencia de reportes autorizada ni contrato confirmado de lectura.

## What Changes

- Reemplazar indicadores ad hoc por consultas autorizadas y filtros operativos reales.
- Añadir reportes de solicitudes, flota, viajes, entregas e historial de auditoría según permisos.
- Presentar el DW como fuente analítica separada, con fecha de carga/limitaciones visibles y sin ejecutar ETL desde web.

## Alcance, exclusiones y riesgos

Excluye alterar el DW, Jobs, ETL, backups, replicación y envío/reintento de notificaciones. Riesgo: datos de DW atrasados o PII en auditoría; se mitiga con fuente/fecha visible y columnas mínimas.

## Capabilities

### New Capabilities
- `dashboards-reports-audit`: Indicadores, reportes autorizados y consulta de auditoría.

### Modified Capabilities
- Ninguna.

## Impact

Depende de `sql-read-access-contracts`, módulos operativos y permisos `REPORTES_VER`/`AUDITORIA_VER` aprobados; reutiliza vistas existentes cuando estén confirmadas.
