## Context

El frontend autentica contra `dbo.usuarios`, usa `password_verify`, CSRF de 32 bytes y `SESSION_CONTEXT('usuario_id')` después de validar la sesión. `require_permission` devuelve texto plano para 403 y HTMX redirige login; no existe una política de respuestas común.

## Goals / Non-Goals

**Goals:** conservar los contratos actuales, separar autenticación de autorización y definir respuestas seguras para navegación normal y HTMX.

**Non-Goals:** roles, recuperación de contraseña, SSO, cuentas nuevas o cambios de tablas/procedimientos.

## Decisions

- La sesión PHP validada será la única fuente de identidad; el contexto SQL se establecerá por conexión y nunca autenticará por sí solo.
- Un guard reutilizable elegirá redirección, fragmento o 403 según el tipo de petición; evita que cada controlador replique lógica.
- Los mensajes al usuario serán genéricos; los detalles se registrarán sin credenciales ni hashes.
- La configuración TLS integrada actual queda documentada como local; producción requerirá certificado validado y cuenta de mínimo privilegio fuera del repositorio.

## Risks / Trade-offs

- [Cambio de respuesta HTMX] → pruebas de login, expiración, 403 y CSRF con swaps.
- [Sesión fijada] → regenerar ID al autenticar y borrar datos/cookie al salir.

## Migration Plan

Aplicar guards antes de migrar páginas existentes, ejecutar smoke tests y revertir los archivos PHP del change si falla acceso. No hay migración SQL.
