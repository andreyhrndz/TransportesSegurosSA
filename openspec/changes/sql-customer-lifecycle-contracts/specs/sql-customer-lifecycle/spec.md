## Purpose

Establecer cambios de clientes seguros y auditables sin borrar registros ni romper relaciones con solicitudes.

## ADDED Requirements

### Requirement: Edición autorizada de cliente
El procedimiento de edición SHALL verificar `CLIENTES_EDITAR`, conservar el subtipo existente y validar los campos definidos antes de actualizar.

#### Scenario: Identificación duplicada
- **WHEN** una edición autorizada intenta usar una identificación ya existente
- **THEN** el procedimiento no modifica el cliente y devuelve un conflicto de negocio.

### Requirement: Desactivación no destructiva
El procedimiento de desactivación SHALL verificar `CLIENTES_DESACTIVAR` y SHALL marcar el cliente inactivo sin ejecutar DELETE.

#### Scenario: Cliente con solicitudes
- **WHEN** se solicita desactivar un cliente que tiene solicitudes relacionadas
- **THEN** el procedimiento aplica la regla de estado aprobada o rechaza la operación sin alterar las solicitudes.

### Requirement: Integridad y trazabilidad
Los procedimientos SHALL ejecutarse transaccionalmente y SHALL dejar evidencia de la operación cuando la auditoría aplicable esté habilitada.

#### Scenario: Fallo de validación
- **WHEN** falla una validación durante una modificación
- **THEN** la transacción revierte y no queda un cliente parcialmente actualizado.
