## Context

`usuarios` tiene nombre y correo únicos, `password_hash`, `activo` y `ultimo_acceso`; `usuarios_permisos` tiene PK compuesta. Los SP existentes verifican `USUARIOS_GESTIONAR_PERMISOS` vía `fn_tiene_permiso`, pero no gestionan cuentas ni protegen auto-revocación o último gestor.

## Goals / Non-Goals

**Goals:** contratos de cuenta con permisos individuales, contraseñas hash bcrypt recibidas desde PHP, autoprotección de cuentas gestoras y despliegue aislado en `TransportesSegurosSA_Test`.

**Non-Goals:** roles, autenticación alternativa, exposición de hash, recuperación por correo, alteración de `TransportesSegurosSA` o modificación de esquema.

## Decisions

- Asegurar de forma idempotente `USUARIOS_VER` y `USUARIOS_GESTIONAR_CUENTAS`; conservar `USUARIOS_GESTIONAR_PERMISOS` y `sp_conceder_permiso` / `sp_revocar_permiso` para la matriz individual.
- Desplegar en `TransportesSegurosSA_Test`: `sp_crear_usuario(@nombre_usuario nvarchar(80), @correo_acceso nvarchar(150), @password_hash nvarchar(255), @usuario_id int OUTPUT)`; `sp_actualizar_usuario(@usuario_id int,@nombre_usuario nvarchar(80),@correo_acceso nvarchar(150))`; `sp_cambiar_estado_usuario(@usuario_id int,@activo bit)`; `sp_restablecer_password(@usuario_id int,@password_hash nvarchar(255))`; y `sp_revocar_permiso_seguro(@usuario_destino int,@codigo nvarchar(100))` que envuelve `sp_revocar_permiso` bajo transacción serializada.
- Cada SP verifica el permiso propio, `SESSION_CONTEXT` válido, longitudes, unicidad con `UPDLOCK, HOLDLOCK` y formato de hash bcrypt de 60 caracteres (`$2[abxy]$NN$...`) generado por PHP; nunca acepta contraseña en texto plano ni retorna o audita el hash.
- Códigos 50412–50420 en Test: 50412 sin permiso `USUARIOS_GESTIONAR_CUENTAS`, 50413 datos inválidos, 50414 hash inválido, 50415 usuario inexistente, 50416 usuario/correo duplicado, 50417 auto-desactivación, 50418 desactivación del último gestor activo (`USUARIOS_VER`, `USUARIOS_GESTIONAR_CUENTAS` o `USUARIOS_GESTIONAR_PERMISOS`), 50419 auto-revocación de permiso administrativo crítico, 50420 revocación del último gestor activo para el permiso crítico.
- Los procedimientos nuevos registran trazabilidad sin secretos en `dbo.auditorias`; `sp_conceder_permiso` original se mantiene sin cambios y por tanto no inserta filas en `dbo.auditorias`.

## Risks / Trade-offs

- [Último administrador] → bloqueo `UPDLOCK, HOLDLOCK` y comprobación transaccional tanto al desactivar como al revocar permisos críticos.
- [Hash malformado] → validación estricta de longitud 60 y prefijo bcrypt en SQL + generación exclusiva con `password_hash(..., PASSWORD_BCRYPT)` en PHP.

## Migration Plan

Aplicado exclusivamente en `TransportesSegurosSA_Test` mediante `sql/user-accounts/01_user_account_contracts.sql`. Reversión mediante `sql/user-accounts/02_rollback_user_account_contracts.sql` y `sql/test-environment/06_revoke_user_account_permissions_test.sql`.
