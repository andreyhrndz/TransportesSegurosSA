## Why

La plataforma tiene cuentas y permisos individuales, pero ninguna interfaz de administración. Sin un módulo explícito, tablas de seguridad quedarían sin cobertura o se administrarían de forma directa e insegura.

## What Changes

- Crear directorio de cuentas, detalle de permisos, alta, edición, activación/desactivación y restablecimiento mediante contratos SQL aprobados.
- Permitir conceder/revocar permisos individuales existentes; no introducir roles ni mostrar hashes.

## Capabilities

### New Capabilities
- `user-permissions-administration`: Administración segura de cuentas y permisos individuales.

### Modified Capabilities
- Ninguna.

## Impact

Depende de `sql-read-access-contracts`, `sql-user-account-contracts` y foundation/auth. No crea cuentas ni cambia permisos en esta planificación.
