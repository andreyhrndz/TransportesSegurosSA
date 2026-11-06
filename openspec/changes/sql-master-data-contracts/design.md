## Context

El esquema tiene checks de coordenadas, capacidad, kilometraje, estados de vehículo/conductor y origen/destino de ruta; `sp_crear_viaje` consume vehículos, conductores y rutas activos/disponibles. Este contrato provee los 21 procedimientos almacenados de escritura autorizada desplegados en `TransportesSegurosSA_Test`.

## Goals / Non-Goals

**Goals:** contratos de escritura autorizada con permisos individuales, serialización segura frente a asignación de viajes (`UPDLOCK, HOLDLOCK`), trazabilidad real en `dbo.auditorias` y desactivación no destructiva.

**Non-Goals:** GPS, historial de mantenimiento, asignación de usuarios a conductores fuera de los campos existentes o `DELETE`.

## Decisions

- Permisos individuales por capacidad (sin roles):
  - `UBICACIONES_GESTIONAR`: ubicaciones logísticas.
  - `CATALOGOS_GESTIONAR`: tipos de mercancía, incidencia y documento.
  - `FLOTAS_GESTIONAR`: vehículos y conductores.
  - `RUTAS_GESTIONAR`: rutas logísticas.
- Rango de códigos de error verificado sin colisiones (`50501–50507`):
  - `50501`: permiso individual insuficiente.
  - `50502`: datos inválidos (campos vacíos, coordenadas incompletas o fuera de rango, capacidad `<= 0`, kilometraje regresivo, licencia vencida, origen = destino, intento de asignar `ASIGNADO` manualmente).
  - `50503`: registro maestro no encontrado.
  - `50504`: valor único duplicado.
  - `50505`: conflicto por operación activa o referencia comprometida (viajes `PLANIFICADO`/`EN_CURSO`, solicitudes no terminales, rutas activas, incidencias abiertas o cambio de extremos en ruta con historial de viajes).
  - `50506`: registro ya inactivo (`activo = 0`).
  - `50507`: referencia relacionada (`origen_id`, `destino_id`, `usuario_id`) inexistente o inactiva.
- Desactivar en lugar de borrar; los vehículos pasan a `activo = 0, estado = 'FUERA_SERVICIO'` y los conductores pasan a `activo = 0, estado_operativo = 'INACTIVO'`.

## Firmas implementadas en Test

| Entidad | Procedimientos (`dbo.*`) | Campos inmutables tras alta |
|---|---|---|
| Ubicación | `sp_crear_ubicacion`, `sp_actualizar_ubicacion`, `sp_desactivar_ubicacion` | `ubicacion_id` |
| Tipo de mercancía | `sp_crear_tipo_mercancia`, `sp_actualizar_tipo_mercancia`, `sp_desactivar_tipo_mercancia` | `tipo_mercancia_id` |
| Tipo de incidencia | `sp_crear_tipo_incidencia`, `sp_actualizar_tipo_incidencia`, `sp_desactivar_tipo_incidencia` | `tipo_incidencia_id` |
| Tipo de documento | `sp_crear_tipo_documento`, `sp_actualizar_tipo_documento`, `sp_desactivar_tipo_documento` | `tipo_documento_id` |
| Vehículo | `sp_crear_vehiculo`, `sp_actualizar_vehiculo`, `sp_desactivar_vehiculo` | `vehiculo_id`, `placa` |
| Conductor | `sp_crear_conductor`, `sp_actualizar_conductor`, `sp_desactivar_conductor` | `conductor_id`, `identificacion` |
| Ruta | `sp_crear_ruta`, `sp_actualizar_ruta`, `sp_desactivar_ruta` | `ruta_id` (`origen_id`/`destino_id` inmutables si existen viajes registrados) |

Las listas de selección activas reutilizan los procedimientos de `sql-read-access-contracts` con `@solo_activos = 1` / `@solo_activas = 1`.

## Risks / Trade-offs

- [Flota asignada] → bloqueo `UPDLOCK, HOLDLOCK` simétrico con `sp_crear_viaje`.
- [Ubicación/ruta usada] → bloqueo `50505` ante rutas activas, solicitudes abiertas o viajes.

## Migration Plan

Desplegado y verificado en `TransportesSegurosSA_Test` (`sql/master-data/01_master_data_contracts.sql`, rollback en `02_rollback_master_data_contracts.sql`); provisional hasta aprobación final antes de tocar `TransportesSegurosSA`.
