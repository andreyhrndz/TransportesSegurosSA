## Purpose

Administrar solicitudes de transporte con mercancías y reglas existentes sin confundir al destinatario esperado con el receptor real.

## ADDED Requirements

### Requirement: Registro de solicitud con mercancías
El sistema SHALL permitir crear una solicitud sólo con cliente activo, origen/destino distintos, fecha futura y al menos una mercancía válida.

#### Scenario: Mercancía ausente
- **WHEN** una persona autorizada intenta guardar una solicitud sin mercancías
- **THEN** el sistema no crea la solicitud y comunica el requisito.

### Requirement: Prioridad y cancelación condicionadas
El sistema SHALL permitir ajustar prioridad con justificación y cancelar sólo mediante los permisos, estados y procedimientos SQL existentes.

#### Scenario: Cancelación asignada
- **WHEN** se intenta cancelar una solicitud ya asignada a un viaje
- **THEN** la operación se rechaza y el estado de la solicitud no cambia.

### Requirement: Destinatario esperado separado
El sistema SHALL mostrar los datos del destinatario esperado de la solicitud y SHALL reservar los datos de receptor efectivo para la entrega final.

#### Scenario: Detalle antes de entrega
- **WHEN** se consulta una solicitud sin entrega registrada
- **THEN** se muestran sus instrucciones y destinatario esperado sin inventar un receptor real.
