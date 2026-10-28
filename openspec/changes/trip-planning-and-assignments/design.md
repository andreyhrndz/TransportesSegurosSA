## Context

Los SP verifican vehículo/conductor disponible, licencia, ruta activa, viaje PLANIFICADO, paradas del mismo viaje en orden, una única asignación por solicitud y capacidad de peso/volumen. Iniciar requiere asignaciones; finalizar requiere todas ENTREGADA o FALLIDA.

## Goals / Non-Goals

**Goals:** reflejar la secuencia SQL y prevenir acciones no válidas desde UI.

**Non-Goals:** modificar SP, calcular rutas, cancelar viaje o presentar falsas disponibilidades.

## Decisions

- Crear un viaje antes de paradas y asignaciones; refrescar el detalle desde SQL después de cada acción.
- La capacidad visual es informativa; el SP sigue siendo la autoridad ante concurrencia.
- Agrupar solicitudes por viaje sin restringirlas por cliente; cada asignación conserva sus paradas propias.

## Risks / Trade-offs

- [Conflicto de disponibilidad] → manejar 50302–50314 como error de negocio y recargar opciones.
- [Orden de paradas] → impedir selección UI inválida, pero no duplicar la regla como sustituto SQL.

## Migration Plan

Liberar en secuencia creación/paradas/asignación/inicio/finalización; sin migraciones ni compensación automática.
