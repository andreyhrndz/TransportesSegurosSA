## Context

Los SP verifican vehículo/conductor disponible, licencia, ruta activa, viaje PLANIFICADO, paradas del mismo viaje en orden, una única asignación por solicitud y capacidad de peso/volumen. Iniciar requiere asignaciones; finalizar requiere todas ENTREGADA o FALLIDA.

## Goals / Non-Goals

**Goals:** reflejar la secuencia SQL y prevenir acciones no válidas desde UI.

**Non-Goals:** modificar SP, calcular rutas, cancelar viaje o presentar falsas disponibilidades.

## Decisions

- Crear un viaje antes de paradas y asignaciones; refrescar el detalle desde SQL después de cada acción.
- La capacidad visual es informativa; el SP sigue siendo la autoridad ante concurrencia.
- Agrupar solicitudes por viaje sin restringirlas por cliente; cada asignación conserva sus paradas propias.

## Contratos existentes a integrar

`sp_crear_viaje(@codigo,@vehiculo_id,@conductor_id,@ruta_id,@fecha_programada,@viaje_id OUTPUT)`, `sp_agregar_parada(@viaje_id,@ubicacion_id,@orden,@tipo_parada,@parada_id OUTPUT)`, `sp_asignar_solicitud_viaje(@viaje_id,@solicitud_id,@parada_recogida_id,@parada_entrega_id,@viaje_solicitud_id OUTPUT)`, `sp_iniciar_viaje(@viaje_id)` y `sp_finalizar_viaje(@viaje_id)` son las únicas escrituras previstas. Exigen los permisos `VIAJES_CREAR`, `VIAJES_PARADAS`, `VIAJES_ASIGNAR`, `VIAJES_INICIAR` y `VIAJES_FINALIZAR`; los errores existentes 50301–50327 son la fuente de conflicto. Listas/detalles reciben filtros y paginación sólo por el contrato de lectura.

## Risks / Trade-offs

- [Conflicto de disponibilidad] → manejar 50302–50314 como error de negocio y recargar opciones.
- [Orden de paradas] → impedir selección UI inválida, pero no duplicar la regla como sustituto SQL.

## Migration Plan

Liberar en secuencia creación/paradas/asignación/inicio/finalización; sin migraciones ni compensación automática.
