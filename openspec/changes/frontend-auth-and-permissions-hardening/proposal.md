## Why

El inicio de sesión actual valida bcrypt, regenera la sesión y protege formularios, pero mezcla comprobaciones de sesión y permisos en cada petición y expone respuestas HTML mínimas. Antes de ampliar módulos se requiere un contrato uniforme de autenticación, autorización y errores.

## What Changes

- Endurecer la sesión PHP, el cierre de sesión, la protección CSRF y el límite de intentos ya existente.
- Centralizar la decisión de acceso por permiso activo y las respuestas para páginas y fragmentos HTMX.
- Definir la configuración local segura y los requisitos pendientes para producción sin publicar la aplicación.

## Alcance, exclusiones y riesgos

Incluye login, logout, sesión, CSRF, errores y regresión de permisos. Excluye administrar roles, crear cuentas, modificar contraseñas o cambiar SQL Server. Riesgo: romper fragmentos HTMX; se mitiga con pruebas de 401/403, CSRF y regresión.

## Capabilities

### New Capabilities
- `auth-permissions-hardening`: Acceso autenticado y autorizado coherente para páginas y fragmentos.

### Modified Capabilities
- Ninguna.

## Impact

Afecta `app/bootstrap.php`, login/logout, layout y futuros controladores PHP; conserva PDO_SQLSRV, `dbo.fn_tiene_permiso` y el contexto SQL derivado de una sesión PHP válida.
