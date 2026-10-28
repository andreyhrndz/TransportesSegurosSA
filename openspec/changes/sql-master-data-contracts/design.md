## Context

El esquema tiene checks de coordenadas, capacidad, kilometraje, estados de vehículo/conductor y origen/destino de ruta; `sp_crear_viaje` consume vehículos, conductores y rutas activos/disponibles. No hay SP maestros disponibles.

## Goals / Non-Goals

**Goals:** contratos de escritura autorizada, listas activas y desactivación no destructiva.

**Non-Goals:** GPS, historial de mantenimiento, asignación de usuarios a conductores fuera de los campos existentes o DELETE.

## Decisions

- Definir permisos individuales por capacidad, no un rol ni acceso genérico a tablas.
- Usar procedimientos con validación de checks y FK, y rechazar cambios incompatibles con viaje activo.
- Desactivar en lugar de borrar; los catálogos referenciados conservarán histórico.

## Firmas propuestas y respuestas

Todos los procedimientos verifican el permiso individual en `SESSION_CONTEXT`, devuelven el identificador en alta por `OUTPUT` y usan 50501–50530 como rango propuesto de errores de permiso, validación, duplicado, dependencia y estado; el DBA debe confirmar que el rango no colisiona antes de implementarlo.

| Entidad | Altas/edición/desactivación propuestas | Entradas principales |
|---|---|---|
| Ubicación | `sp_crear_ubicacion`, `sp_actualizar_ubicacion`, `sp_desactivar_ubicacion` | nombre(150), dirección(250), latitud decimal(9,6) opcional, longitud decimal(9,6) opcional; edición agrega `@ubicacion_id` |
| Tipo de mercancía/incidencia/documento | `sp_crear_tipo_mercancia`, `sp_actualizar_tipo_mercancia`, `sp_desactivar_tipo_mercancia` y familias equivalentes `tipo_incidencia`/`tipo_documento` | nombre(100), descripción(250) opcional; edición/desactivación recibe ID |
| Vehículo | `sp_crear_vehiculo`, `sp_actualizar_vehiculo`, `sp_desactivar_vehiculo` | placa(20), marca/modelo(80), año smallint, capacidad kg/m³, kilometraje; edición sólo permite campos no derivados por contrato |
| Conductor | `sp_crear_conductor`, `sp_actualizar_conductor`, `sp_desactivar_conductor` | usuario_id opcional, identificación(30), nombres, contacto, licencia(50), vencimiento; estado operativo no se altera libremente si está asignado |
| Ruta | `sp_crear_ruta`, `sp_actualizar_ruta`, `sp_desactivar_ruta` | nombre(150), origen_id, destino_id, distancia decimal(10,2), duración int |

Las altas devuelven `@*_id OUTPUT`; edición/desactivación devuelven éxito por `@@ROWCOUNT=1` o error documentado. Todas las entradas se validan contra los CHECK/FK existentes, no aceptan paginación y no exponen consultas de lectura: esas pertenecen a `sql-read-access-contracts`.

## Risks / Trade-offs

- [Flota asignada] → verificar viajes PLANIFICADO/EN_CURSO bajo bloqueo apropiado.
- [Ubicación/ruta usada] → prohibir cambios que invaliden referencias o definir campos inmutables.

## Migration Plan

Inventariar objetos reales, revisar con DBA, desplegar SP/permisos aprobados y probar rollback en base no productiva.
