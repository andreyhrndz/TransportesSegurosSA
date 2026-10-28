## Why

El frontend sólo lista y registra clientes usando `CLIENTES_CREAR`; no permite detalle, edición ni desactivación y usa consultas directas. Completarlo depende de los contratos SQL de lectura y ciclo de vida.

## What Changes

- Añadir directorio paginado, detalle, alta existente, edición y desactivación según contratos confirmados.
- Diferenciar cliente físico y jurídico sin permitir cambiar el subtipo.
- Usar acciones HTMX con CSRF, permisos individuales, validación y mensajes seguros.

## Alcance, exclusiones y riesgos

Excluye DELETE, conversión de subtipo y modificaciones de solicitudes. Riesgo: contratos SQL aún no desplegados; el módulo no se implementará hasta validar `sql-read-access-contracts` y `sql-customer-lifecycle-contracts`.

## Capabilities

### New Capabilities
- `customer-management`: Directorio y ciclo de vida no destructivo de clientes.

### Modified Capabilities
- Ninguna.

## Impact

Afectará páginas PHP de clientes, componentes visuales y contratos SQL aprobados; preserva altas `sp_crear_cliente_*` existentes.
