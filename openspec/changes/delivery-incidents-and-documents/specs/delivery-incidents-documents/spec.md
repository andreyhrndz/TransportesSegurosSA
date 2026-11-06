## Purpose

Registrar resultados terminales de entrega e información asociada sin crear reintentos ni confundir personas o evidencias.

## ADDED Requirements

### Requirement: Entrega final única
El sistema SHALL registrar una sola entrega para una solicitud asignada en tránsito y SHALL aceptar únicamente ENTREGADA o FALLIDA.

#### Scenario: Segundo resultado
- **WHEN** se intenta registrar una segunda entrega para la misma asignación
- **THEN** el sistema rechaza la acción y conserva el primer resultado.

### Requirement: Datos coherentes con el resultado
El sistema SHALL exigir receptor efectivo y comprobante para ENTREGADA, y motivo para FALLIDA, sin mezclar los conjuntos de datos.

#### Scenario: Entrega fallida
- **WHEN** una persona registra FALLIDA con un motivo válido
- **THEN** la solicitud pasa a ENTREGA_FALLIDA y no se solicita receptor efectivo.

### Requirement: Incidencias y documentos vinculados
El sistema SHALL registrar incidencias y referencias documentales contra la solicitud con permiso correspondiente y SHALL permitir resolver sólo incidencias abiertas o en atención.

#### Scenario: Resolver dos veces
- **WHEN** se intenta resolver una incidencia ya resuelta
- **THEN** el sistema no altera su resolución y comunica el conflicto.
