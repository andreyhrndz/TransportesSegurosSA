## Context

El modelo define 13 entidades de soporte entre ubicaciones, flota, rutas y catálogos. `vw_estado_flota` ya aporta estado de vehículo/viaje, mientras los contratos de escritura están pendientes.

## Goals / Non-Goals

**Goals:** pantallas agrupadas por dominio, filtros y formularios accesibles basados en contratos SQL.

**Non-Goals:** navegación ficticia, mantenimiento, mapas/GPS, editar cuentas o saltar reglas de disponibilidad.

## Decisions

- Ubicaciones, catálogos, flota y rutas serán secciones reales que sólo aparecen con permiso de lectura.
- Las acciones de escritura llaman a SP aprobados y refrescan fragments HTMX; los selectores consumen sólo activos.
- El estado de vehículo/conductor se presenta como resultado operativo, no como campo editable libre si el contrato no lo permite.

## Risks / Trade-offs

- [Módulo amplio] → tareas separadas por dominio y smoke tests incrementales.
- [Listas grandes] → filtros y paginación desde contratos de lectura.

## Migration Plan

Liberar por dominio después de sus contratos; ocultar secciones no listas y revertir sólo controladores/UI si hay regresión.
