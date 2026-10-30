## Purpose

Establecer cambios de clientes seguros y auditables sin borrar registros ni romper relaciones con solicitudes.

## ADDED Requirements

### Requirement: Edición autorizada de cliente
El procedimiento de edición SHALL verificar `CLIENTES_EDITAR`, conservar el subtipo existente y validar los campos definidos antes de actualizar.

#### Scenario: Intento de cambiar identificación
- **WHEN** una edición autorizada intenta cambiar una identificación
- **THEN** el contrato no acepta ese campo y no modifica la identificación existente.

### Requirement: Desactivación bloqueada hasta aprobar su regla
El change SHALL mantener la desactivación fuera del frontend y sin despliegue hasta que producto y DBA aprueben explícitamente la regla para solicitudes no terminales. La propuesta actual es verificar `CLIENTES_DESACTIVAR`, marcar sólo `activo=0` y rechazar solicitudes `REGISTRADA`, `PENDIENTE_ASIGNACION`, `ASIGNADA` o `EN_TRANSITO`; no es un contrato definitivo.

#### Scenario: Falta aprobación de negocio o DBA
- **WHEN** no existe aprobación documentada de la regla de solicitudes activas
- **THEN** no se ejecuta el script propuesto ni se muestra una acción de desactivación.

#### Scenario: Regla aprobada en un despliegue posterior
- **WHEN** producto y DBA aprueban la propuesta sin cambios y un cliente tiene solicitudes no terminales
- **THEN** el contrato aprobado rechaza la operación sin alterar las solicitudes.

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
