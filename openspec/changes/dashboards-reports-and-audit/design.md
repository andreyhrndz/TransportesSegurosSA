## Context

Existen vistas operativas y `vw_indicadores_operativos`; el DW almacena hechos de entrega e incluye `sp_cargar_datawarehouse`, cuya ejecución debe quedar en Job/operación SQL. Triggers actuales escriben auditoría de solicitudes, viajes, asignaciones y entregas, no de todas las entidades.

## Goals / Non-Goals

**Goals:** separar panel operativo de reportes analíticos, revelar fuente y permitir filtros/paginación autorizados.

**Non-Goals:** modificar ETL, prometer auditoría universal, ejecutar procedimientos DW desde PHP o afirmar replicación.

## Decisions

- Dashboard consume contratos de lectura/vistas operativas; reportes DW son sólo lectura y mostrarán fecha de carga disponible.
- Auditoría se describe por entidades realmente auditadas y filtra/pagina por contrato, sin exponer valores sensibles.
- `REPORTES_VER` no sustituirá una capacidad operativa; auditoría requerirá permiso específico si el contrato lo crea.

## Risks / Trade-offs

- [Desfase DW] → mostrar la fuente y no mezclarla silenciosamente con datos en vivo.
- [Costo de consultas] → rangos de fecha obligatorios/limitados e índices revisados por DBA.

## Migration Plan

Introducir endpoints de sólo lectura y comparar resultados con vistas/consultas SQL; rollback restaura dashboard actual sin tocar DW.
