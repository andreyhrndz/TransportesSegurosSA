## Why

La base sólo ofrece `sp_crear_cliente_fisico` y `sp_crear_cliente_juridico`; no existe procedimiento ni permiso para editar o desactivar. El módulo de clientes no puede prometer esas acciones hasta acordar contratos que preserven solicitudes relacionadas.

## What Changes

- Especificar permisos y procedimientos para actualizar datos permitidos y desactivar clientes.
- Definir validaciones de tipo físico/jurídico, unicidad y prohibición de DELETE físico.
- Definir respuestas de conflicto y pruebas con solicitudes existentes.

## Alcance, exclusiones y riesgos

No se implementa SQL ni UI; no se elimina ningún cliente ni se reprograman solicitudes. Riesgo: desactivar cliente con operaciones activas; se mitiga con regla explícita y transacción.

## Capabilities

### New Capabilities
- `sql-customer-lifecycle`: Contratos de edición y desactivación segura de clientes.

### Modified Capabilities
- Ninguna.

## Impact

Desbloquea `customer-management-completion` y requiere revisión DBA de procedimientos, permisos y restricciones reales.
