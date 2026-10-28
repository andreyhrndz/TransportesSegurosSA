## Context

Los procedimientos existentes conceden/revocan un código individual con `USUARIOS_GESTIONAR_PERMISOS`. El nuevo contrato de cuenta separa consulta y gestión. La UI actual no debe mostrar `password_hash` ni convertir permisos en roles.

## Goals / Non-Goals

**Goals:** interfaz HTMX protegida, matriz individual comprensible y acciones confirmadas.

**Non-Goals:** roles, cambio de contraseña propia, recuperación por correo, edición de permisos por lote o acceso directo a tablas.

## Decisions

- El directorio usa `USUARIOS_VER`; acciones de cuenta usan `USUARIOS_GESTIONAR_CUENTAS`; concesión/revocación usa el permiso existente.
- PHP genera hash antes de llamar al SP y nunca vuelve a mostrar la contraseña temporal; el formulario obliga a confirmación local sin registrar el valor.
- La matriz muestra permisos activos disponibles, estado y fecha de asignación; cada mutación exige CSRF y confirmación.

## Risks / Trade-offs

- [Escalada accidental] → permisos y actor se verifican dos veces: guard PHP y SP.
- [Bloqueo de operadores] → mostrar el rechazo SQL de autoprotección sin permitir bypass.

## Migration Plan

Desplegar sólo tras contratos SQL, probar con cuentas de prueba autorizadas y revertir UI si falla; no hay migración de datos.
