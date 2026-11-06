## 1. Integración

- [x] 1.1 Confirmar contratos de lectura/cuenta y permisos desplegados en `TransportesSegurosSA_Test`.
- [x] 1.2 Crear guards por capacidad (`USUARIOS_VER`, `USUARIOS_GESTIONAR_CUENTAS`, `USUARIOS_GESTIONAR_PERMISOS`) y endpoints HTMX con CSRF.
- [x] 1.3 Implementar directorio, detalle recargable por HTMX y matriz individual sin hashes.

## 2. Operaciones

- [x] 2.1 Implementar alta, edición, cambio de estado y restablecimiento de contraseña con bcrypt mediante `sp_crear_usuario`, `sp_actualizar_usuario`, `sp_cambiar_estado_usuario` y `sp_restablecer_password`.
- [x] 2.2 Implementar concesión individual mediante `sp_conceder_permiso` existente y revocación protegida mediante `sp_revocar_permiso_seguro` (que delega en `sp_revocar_permiso`).
- [x] 2.3 Implementar modales de confirmación, mensajes comprensibles de conflicto y autoprotección en PHP y SQL.

## 3. Verificación

- [x] 3.1 Probar login real con usuario creado y restablecido, permisos, CSRF, duplicados, cuenta propia y última cuenta gestora (`tests/user_accounts_http_functional_test.php`).
- [x] 3.2 Comprobar automáticamente que ningún hash bcrypt ni contraseña llega a HTML, logs del servidor o `dbo.auditorias`.
- [ ] 3.3 Probar accesibilidad/responsive integral en tablet/móvil (pendiente para `release-quality-and-accessibility`; validación estricta OpenSpec y vistas de escritorio completadas).

## Evidencia y límites

- `public/usuarios.php`, `public/usuarios/lista.php` y `public/usuarios/detalle.php` consultan mediante `sp_listar_usuarios`, `sp_obtener_usuario` y `sp_listar_permisos` bajo `USUARIOS_VER`; ningún procedimiento ni vista expone `password_hash`.
- Las mutaciones de cuenta (`form.php`, `guardar.php`, `estado_form.php`, `estado.php`, `password_form.php`, `password.php`) exigen `USUARIOS_GESTIONAR_CUENTAS` y generan hashes bcrypt con `password_hash(..., PASSWORD_BCRYPT)` compatibles con `password_verify()` en `/auth/login.php`.
- Las mutaciones de permisos (`permiso_form.php`, `permiso.php`) exigen `USUARIOS_GESTIONAR_PERMISOS`, bloquean auto-revocaciones críticas y protegen el último titular activo.
- Límite de auditoría documentado: `sp_crear_usuario`, `sp_actualizar_usuario`, `sp_cambiar_estado_usuario`, `sp_restablecer_password` y `sp_revocar_permiso_seguro` registran metadatos sin secretos en `dbo.auditorias`; `sp_conceder_permiso` se conserva sin alteraciones respecto al contrato original y no inserta fila en `dbo.auditorias`.
