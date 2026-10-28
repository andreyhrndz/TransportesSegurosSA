## Purpose

Permitir planificar y ejecutar el ciclo de viaje existente para múltiples solicitudes, preservando capacidad, orden y exclusividad de asignación.

## ADDED Requirements

### Requirement: Viaje con recursos disponibles
El sistema SHALL crear un viaje sólo mediante el contrato SQL con vehículo, conductor habilitado, licencia vigente, ruta activa y fecha admisible.

#### Scenario: Conductor no disponible
- **WHEN** se intenta crear un viaje con conductor asignado o licencia vencida
- **THEN** no se crea el viaje y se comunica la indisponibilidad.

### Requirement: Asignación exclusiva y compatible
El sistema SHALL permitir varias solicitudes por viaje, incluso de clientes diferentes, pero SHALL impedir más de un viaje por solicitud y exigir paradas compatibles y ordenadas.

#### Scenario: Solicitud ya asignada
- **WHEN** se intenta asignar una solicitud que pertenece a otro viaje
- **THEN** el sistema rechaza la asignación y conserva ambos viajes.

### Requirement: Transiciones de viaje válidas
El sistema SHALL iniciar sólo viajes con asignaciones y SHALL finalizar sólo cuando todas sus asignaciones tengan resultado terminal.

#### Scenario: Finalización pendiente
- **WHEN** una persona intenta finalizar un viaje con una solicitud en tránsito
- **THEN** el viaje permanece en curso y se informa que faltan entregas.
