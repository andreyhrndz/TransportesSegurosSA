## Context

La lista actual filtra seis columnas mediante SQL directo, pagina a diez registros y abre formularios HTMX. Las altas llaman a los dos SP existentes. No hay permiso `CLIENTES_VER`, `CLIENTES_EDITAR` ni `CLIENTES_DESACTIVAR` documentado en el catálogo disponible.

## Goals / Non-Goals

**Goals:** operaciones reales, feedback accesible y estados de carga/vacío/error.

**Non-Goals:** borrar, cambiar subtipo, editar solicitudes o inventar permisos.

## Decisions

- Consumir primero los contratos SQL de lectura y ciclo de vida, no interpolar filtros ni emular autorización.
- Un detalle mostrará campos comunes y específicos; formularios reutilizarán tokens/componentes del foundation change.
- Desactivar solicitará confirmación y refrescará únicamente el fragmento afectado; no habrá botón si falta permiso.

## Risks / Trade-offs

- [Cambio simultáneo] → bloqueo optimista o mensaje de conflicto según contrato SQL.
- [PII en tablas] → mostrar campos mínimos y escapar cada salida.

## Migration Plan

Desplegar después de contratos SQL; probar altas actuales y revertir páginas PHP si se detecta regresión, sin compensación de datos.
