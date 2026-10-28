## Purpose

Ofrecer información operativa y analítica confiable, autorizada y explícita sobre su fuente y cobertura de auditoría.

## ADDED Requirements

### Requirement: Indicadores con fuente identificable
El sistema SHALL mostrar indicadores operativos sólo desde contratos o vistas confirmados y SHALL comunicar un estado claro cuando no haya datos o falle la fuente.

#### Scenario: Sin solicitudes en el período
- **WHEN** un filtro autorizado no devuelve solicitudes
- **THEN** el panel muestra cero o estado vacío con el período aplicado, sin datos simulados.

### Requirement: Reportes filtrables autorizados
El sistema SHALL permitir filtros y paginación definidos para reportes sólo a quien posea el permiso correspondiente.

#### Scenario: Acceso denegado a reporte
- **WHEN** una persona sin `REPORTES_VER` solicita un reporte
- **THEN** el sistema no devuelve filas ni totales.

### Requirement: Auditoría y DW sin falsas garantías
El sistema SHALL exponer auditoría únicamente de entidades registradas y SHALL identificar datos del DW como analíticos con su disponibilidad conocida.

#### Scenario: Consulta DW sin carga
- **WHEN** no hay hechos analíticos para el filtro
- **THEN** la interfaz explica que no hay datos cargados, sin ejecutar ETL ni afirmar actualización en tiempo real.
