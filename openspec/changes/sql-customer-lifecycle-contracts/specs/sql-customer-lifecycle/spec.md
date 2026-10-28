## Purpose

Establecer cambios de clientes seguros y auditables sin borrar registros ni romper relaciones con solicitudes.

## ADDED Requirements

### Requirement: Edición autorizada de cliente
El procedimiento de edición SHALL verificar `CLIENTES_EDITAR`, conservar el subtipo existente y validar los campos definidos antes de actualizar.

#### Scenario: Identificación duplicada
- **WHEN** una edición autorizada intenta usar una identificación ya existente
- **THEN** el procedimiento no modifica el cliente y devuelve un conflicto de negocio.

### Requirement: Desactivación no destructiva
El procedimiento de desactivación SHALL verificar `CLIENTES_DESACTIVAR`, marcar el cliente inactivo sin DELETE y rechazarlo cuando tenga una solicitud `REGISTRADA`, `PENDIENTE_ASIGNACION`, `ASIGNADA` o `EN_TRANSITO`.

#### Scenario: Cliente con solicitudes
- **WHEN** se solicita desactivar un cliente que tiene solicitudes relacionadas
- **THEN** el procedimiento rechaza la operación sin alterar las solicitudes.

### Requirement: Campos editables definidos
Los procedimientos SHALL conservar identificación y subtipo; sólo actualizarán contacto/dirección común y los campos del subtipo definidos en el diseño, tras validar correo, longitudes y obligatoriedad.

#### Scenario: Cambio de subtipo implícito
- **WHEN** una edición intenta modificar un cliente físico como jurídico o alterar su identificación
- **THEN** el procedimiento rechaza la solicitud sin modificar el cliente.

### Requirement: Integridad y trazabilidad
Los procedimientos SHALL ejecutarse transaccionalmente y SHALL dejar evidencia de la operación cuando la auditoría aplicable esté habilitada.

#### Scenario: Fallo de validación
- **WHEN** falla una validación durante una modificación
- **THEN** la transacción revierte y no queda un cliente parcialmente actualizado.
