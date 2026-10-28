## Purpose

Definir operaciones de datos maestros y flota que respeten los estados, relaciones y restricciones existentes de SQL Server.

## ADDED Requirements

### Requirement: Escritura autorizada de maestros
Cada procedimiento de maestro SHALL verificar un permiso activo específico antes de crear, editar o desactivar registros.

#### Scenario: Alta sin permiso
- **WHEN** un contexto sin el permiso de vehículo intenta registrar una placa
- **THEN** el procedimiento rechaza la operación sin crear el vehículo.

### Requirement: Integridad de flota y rutas
Los procedimientos SHALL validar capacidad, estados, vigencia de licencia y origen/destino distintos antes de persistir cambios.

#### Scenario: Ruta inválida
- **WHEN** se registra una ruta con el mismo origen y destino
- **THEN** el procedimiento rechaza la operación sin depender sólo de la interfaz.

### Requirement: Desactivación compatible con referencias
La desactivación SHALL ser no destructiva y SHALL rechazar o proteger cambios incompatibles con referencias operativas existentes.

#### Scenario: Vehículo asignado
- **WHEN** se intenta desactivar un vehículo de un viaje planificado o en curso
- **THEN** el procedimiento devuelve un conflicto y conserva la asignación.
