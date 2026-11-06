## Why

Las tablas `usuarios`, `permisos` y `usuarios_permisos` existen, pero sólo se disponen los procedimientos de conceder/revocar permiso. No hay contrato seguro para administrar cuentas; el frontend no debe escribir tablas de seguridad directamente ni crear roles.

## What Changes

- Definir permisos, procedimientos, firmas y errores para crear, editar, activar/desactivar y restablecer cuentas.
- Conservar `sp_conceder_permiso` y `sp_revocar_permiso` como único mecanismo de asignación individual.
- Prohibir acceso a hashes en lecturas y operaciones de roles.

## Capabilities

### New Capabilities
- `sql-user-accounts`: Contratos SQL autorizados para cuentas y permisos individuales.

### Modified Capabilities
- Ninguna.

## Impact

Requiere revisión DBA y antecede a la interfaz de cuentas. No modifica datos, tablas, hashes ni procedimientos durante este change; reversión elimina sólo objetos nuevos aprobados.
