# Tareas de implementación — `account-invitations-and-recovery`

## 1. Fase Backend y SQL: Esquema de invitaciones y recuperación

- [x] 1.1 Crear scripts SQL en `sql/user-accounts/` para crear tablas `dbo.invitaciones_usuario` y `dbo.tokens_recuperacion`, y procedimientos de invitación y recuperación exclusivamente en `TransportesSegurosSA_Test`.
- [x] 1.2 Implementar la abstracción de correo en `app/mail/`: interfaz `MailSender`, `BrevoApiSender`, `FakeMailCaptureSender` y plantillas de correo en español.
- [x] 1.3 Implementar lógica de negocio en `app/user_security.php` para generación de tokens seguros, validación de hash, consumo atómico e invalidación de sesiones.

## 2. Fase Frontend: Vistas de administración, onboarding y recuperación

- [x] 2.1 Reemplazar el modal de «Crear usuario» en `public/usuarios/form.php` por el modal «Invitar usuario» con solo el campo «Correo electrónico» y botones `Enviar invitación | Cancelar`.
- [x] 2.2 Agregar la vista y gestión de invitaciones pendientes en `public/usuarios.php` y `public/usuarios/invitaciones.php`.
- [x] 2.3 Implementar la página pública de onboarding `public/auth/onboarding.php` con correo prefijado, identificación primero + consulta Hacienda, datos personales y creación de contraseña propia.
- [x] 2.4 Implementar el flujo «¿Olvidaste tu contraseña?» en `public/auth/login.php`, `public/auth/recuperar.php` y `public/auth/reset_password.php`.

## 3. Pruebas y verificación

- [x] 3.1 Crear y ejecutar suites de pruebas automatizadas PHP (`tests/account_invitations_test.php` y `tests/password_recovery_test.php`).
- [x] 3.2 Verificar con Playwright el flujo completo de invitación administrativa, recepción en FakeMailCapture, onboarding autoservicio e inicio de sesión.
