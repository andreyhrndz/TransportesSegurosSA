## Why

No existe interfaz para ubicaciones, vehículos, conductores, rutas ni catálogos, aunque el modelo los requiere para solicitudes y viajes. Debe construirse sobre contratos SQL aprobados, sin introducir módulos ajenos.

## What Changes

- Crear módulos de consulta y administración autorizada para maestros y flota.
- Mostrar disponibilidad y estados reales, con selectores activos reutilizables.
- Usar desactivación confirmada y mensajes de dependencia, sin DELETE.

## Alcance, exclusiones y riesgos

Excluye GPS, mantenimiento independiente, gestión de usuarios/roles y escritura SQL directa. Depende de `sql-read-access-contracts` y `sql-master-data-contracts`.

## Capabilities

### New Capabilities
- `master-data-fleet-management`: Gestión operativa de maestros y flota.

### Modified Capabilities
- Ninguna.

## Impact

Afectará nuevos controladores y fragmentos PHP/HTMX, navegación real y componentes foundation; no altera el esquema.
