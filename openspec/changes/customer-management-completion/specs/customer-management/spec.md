## Purpose

Permitir administrar clientes físicos y jurídicos con operaciones existentes o aprobadas, sin borrado y con autorización granular.

## ADDED Requirements

### Requirement: Directorio autorizado de clientes
El sistema SHALL permitir a quien tenga `CLIENTES_VER` buscar, paginar y abrir el detalle de clientes sin conceder permiso de escritura.

#### Scenario: Directorio vacío
- **WHEN** un filtro autorizado no encuentra clientes
- **THEN** el sistema muestra un estado vacío y no una tabla o acción ficticia.

### Requirement: Alta y edición por subtipo
El sistema SHALL registrar mediante los procedimientos existentes y SHALL editar sólo mediante el contrato aprobado conservando cliente físico o jurídico.

#### Scenario: Edición de persona física
- **WHEN** una persona con `CLIENTES_EDITAR` guarda datos válidos de un cliente físico
- **THEN** el detalle refleja los campos actualizados y mantiene su subtipo.

### Requirement: Desactivación segura
El sistema SHALL mostrar desactivación sólo con `CLIENTES_DESACTIVAR`, solicitar confirmación y comunicar el rechazo de reglas SQL sin ocultar datos.

#### Scenario: Desactivación rechazada
- **WHEN** el contrato SQL rechaza desactivar por una relación operativa
- **THEN** el cliente permanece activo y la interfaz explica que la operación no se realizó.
