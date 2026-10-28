## Why

Las tablas de ubicaciones, vehículos, conductores, rutas y catálogos existen, pero los scripts no incluyen permisos ni procedimientos de creación, edición o desactivación. El frontend no puede gestionar datos maestros con SQL directo.

## What Changes

- Definir permisos y procedimientos transaccionales para ubicaciones, vehículos, conductores, rutas y tipos de mercancía/incidencia/documento.
- Proteger estados y referencias: vehículo/conductor asignados, rutas activas y catálogos usados.
- Definir listas de selección activas para solicitudes, viajes e incidencias.

## Alcance, exclusiones y riesgos

Excluye GPS, mantenimiento como módulo, DELETE y cambios a viajes ya ejecutados. Riesgo: reglas de desactivación no escritas; se resolverán antes del script con pruebas de FK.

## Capabilities

### New Capabilities
- `sql-master-data`: Contratos autorizados para datos maestros y flota.

### Modified Capabilities
- Ninguna.

## Impact

Requiere script SQL separado, permisos individuales y pruebas DBA antes de `master-data-and-fleet-management`.
