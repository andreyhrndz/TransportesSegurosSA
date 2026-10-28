## Context

`clientes` tiene identificacion única, activo y relaciones a `solicitudes`; no hay borrado en cascada. Las altas existentes exigen `CLIENTES_CREAR` y usan el usuario de `SESSION_CONTEXT` validado por PHP.

## Goals / Non-Goals

**Goals:** edición transaccional y desactivación reversible por estado, con permisos separados.

**Non-Goals:** DELETE, cambio de tipo físico/jurídico, gestión de cuentas o modificación de solicitudes.

## Decisions

- Proponer `CLIENTES_EDITAR` y `CLIENTES_DESACTIVAR`, ambos verificados dentro del procedimiento.
- La edición conservará el subtipo existente y validará longitudes, correo y unicidad; el contrato declarará campos editables.
- La desactivación actualizará sólo `activo=0`; el DBA decidirá si se rechaza cuando existen solicitudes no terminales, con prueba explícita.

## Risks / Trade-offs

- [Regla de operaciones activas no confirmada] → resolverla antes de crear el script y no codificarla en PHP.
- [Carrera de unicidad] → constraint existente y mapeo de 2601/2627.

## Migration Plan

Crear permisos y SP idempotentes en transacción tras respaldo aprobado; rollback elimina sólo objetos nuevos aprobados, nunca datos.
