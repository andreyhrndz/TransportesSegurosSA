## Purpose

Establecer cambios de clientes seguros y auditables sin borrar registros ni romper relaciones con solicitudes.

## ADDED Requirements

### Requirement: Edición autorizada de cliente
El procedimiento de edición SHALL verificar `CLIENTES_EDITAR`, conservar el subtipo existente y validar los campos definidos antes de actualizar.

#### Scenario: Intento de cambiar identificación
- **WHEN** una edición autorizada intenta cambiar una identificación
- **THEN** el contrato no acepta ese campo y no modifica la identificación existente.

### Requirement: Desactivación segura ante solicitudes no terminales
El procedimiento de desactivación SHALL verificar `CLIENTES_DESACTIVAR`, marcar únicamente `activo=0` sin borrar registros y rechazar la operación cuando el cliente tenga solicitudes en estado `REGISTRADA`, `PENDIENTE_ASIGNACION`, `ASIGNADA` o `EN_TRANSITO`. El despliegue permanece restringido a `TransportesSegurosSA_Test` hasta aprobación definitiva para la base original.

#### Scenario: Cliente con solicitud activa
- **WHEN** un actor con `CLIENTES_DESACTIVAR` solicita desactivar un cliente que posee solicitudes no terminales
- **THEN** el procedimiento rechaza la operación con el código `50220` sin alterar el cliente ni las solicitudes.

#### Scenario: Cliente con historial únicamente terminal
- **WHEN** un actor con `CLIENTES_DESACTIVAR` desactiva un cliente activo cuyas solicitudes están en estado terminal o inexistentes
- **THEN** el procedimiento actualiza `activo=0` y conserva intacto el historial referencial.

### Requirement: Campos editables definidos
Los procedimientos SHALL conservar identificación y subtipo; sólo actualizarán contacto/dirección común y los campos del subtipo definidos en el diseño, tras validar correo, longitudes y obligatoriedad.

#### Scenario: Cambio de subtipo implícito
- **WHEN** una edición intenta modificar un cliente físico como jurídico o alterar su identificación
- **THEN** el procedimiento rechaza la solicitud con `50218` sin modificar el cliente.

### Requirement: Integridad y trazabilidad
Los procedimientos SHALL ejecutarse transaccionalmente y SHALL registrar evidencia real en `dbo.auditorias` para cada edición o desactivación exitosa.

#### Scenario: Fallo de validación
- **WHEN** falla una validación durante una modificación
- **THEN** la transacción revierte y no queda un cliente parcialmente actualizado ni evento falso de auditoría.
