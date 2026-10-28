## Context

`usuarios` tiene nombre y correo únicos, `password_hash`, `activo` y `ultimo_acceso`; `usuarios_permisos` tiene PK compuesta. Los SP existentes verifican `USUARIOS_GESTIONAR_PERMISOS` vía `fn_tiene_permiso`, pero no gestionan cuentas.

## Goals / Non-Goals

**Goals:** contratos de cuenta con permisos individuales, contraseñas hash recibidas desde PHP y protección de la cuenta operativa.

**Non-Goals:** roles, autenticación alternativa, exposición de hash, recuperación por correo o modificación de esquema.

## Decisions

- Agregar permisos `USUARIOS_VER` y `USUARIOS_GESTIONAR_CUENTAS`; conservar `USUARIOS_GESTIONAR_PERMISOS` para la matriz individual.
- Proponer: `sp_crear_usuario(@nombre_usuario nvarchar(80), @correo_acceso nvarchar(150), @password_hash nvarchar(255), @usuario_id int OUTPUT)`; `sp_actualizar_usuario(@usuario_id int,@nombre_usuario nvarchar(80),@correo_acceso nvarchar(150))`; `sp_cambiar_estado_usuario(@usuario_id int,@activo bit)`; `sp_restablecer_password(@usuario_id int,@password_hash nvarchar(255))`.
- Cada SP verifica el permiso propio, `SESSION_CONTEXT` válido, longitudes, unicidad y hash bcrypt/Argon2 compatible generado por PHP; nunca acepta contraseña en texto plano ni retorna hash.
- Se rechaza desactivar la cuenta propia y dejar sin cuentas activas con `USUARIOS_GESTIONAR_CUENTAS`; códigos propuestos 50412–50420, confirmados por DBA antes de despliegue.

## Risks / Trade-offs

- [Último administrador] → bloqueo transaccional y comprobación dentro del SP.
- [Hash malformado] → validación de formato/longitud y generación exclusiva en aplicación.

## Migration Plan

El script crea catálogo y SP idempotentes después de inspeccionar la instancia; prueba autorización, duplicados, autoprotección y rollback sin datos productivos.
