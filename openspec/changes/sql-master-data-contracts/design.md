## Context

El esquema tiene checks de coordenadas, capacidad, kilometraje, estados de vehículo/conductor y origen/destino de ruta; `sp_crear_viaje` consume vehículos, conductores y rutas activos/disponibles. No hay SP maestros disponibles.

## Goals / Non-Goals

**Goals:** contratos de escritura autorizada, listas activas y desactivación no destructiva.

**Non-Goals:** GPS, historial de mantenimiento, asignación de usuarios a conductores fuera de los campos existentes o DELETE.

## Decisions

- Definir permisos individuales por capacidad, no un rol ni acceso genérico a tablas.
- Usar procedimientos con validación de checks y FK, y rechazar cambios incompatibles con viaje activo.
- Desactivar en lugar de borrar; los catálogos referenciados conservarán histórico.

## Risks / Trade-offs

- [Flota asignada] → verificar viajes PLANIFICADO/EN_CURSO bajo bloqueo apropiado.
- [Ubicación/ruta usada] → prohibir cambios que invaliden referencias o definir campos inmutables.

## Migration Plan

Inventariar objetos reales, revisar con DBA, desplegar SP/permisos aprobados y probar rollback en base no productiva.
