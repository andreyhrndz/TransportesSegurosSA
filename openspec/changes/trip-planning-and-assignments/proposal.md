## Why

Viajes, paradas y asignaciones están soportados por procedimientos SQL, pero no tienen interfaz. El flujo debe planificar varios pedidos —incluso de clientes distintos— sin exceder capacidad ni permitir que una solicitud pertenezca a más de un viaje.

## What Changes

- Crear consulta, alta, paradas, asignación, inicio y finalización de viajes usando SP existentes.
- Mostrar capacidad acumulada, disponibilidad y estados como resultados de SQL Server.
- Presentar solicitudes elegibles y detalle del viaje sin crear cancelación o reprogramación inexistentes.

## Alcance, exclusiones y riesgos

Excluye GPS, optimización automática de rutas, reintentos, edición/cancelación de viaje y cambios SQL. Depende de maestros, solicitudes y lectura autorizada.

## Capabilities

### New Capabilities
- `trip-planning`: Planificación, paradas, asignación y ciclo existente de viajes.

### Modified Capabilities
- Ninguna.

## Impact

Usa `sp_crear_viaje`, `sp_agregar_parada`, `sp_asignar_solicitud_viaje`, `sp_iniciar_viaje`, `sp_finalizar_viaje`, y vistas existentes confirmadas.
