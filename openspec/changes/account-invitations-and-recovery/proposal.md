# Propuesta: invitaciones de autoservicio por correo y recuperación de contraseñas

## Why

El modelo anterior de gestión de usuarios requería que el administrador creara las cuentas ingresando manualmente nombres, apellidos, nombre de usuario y una contraseña inicial conocida por el administrador, o generando contraseñas provisionales. Esta práctica vulnera principios fundamentales de seguridad y custodia de credenciales (OWASP), compromete el no repudio y genera sobrecarga administrativa. Además, no existía un mecanismo de autoservicio para que los usuarios legítimos restablecieran sus contraseñas en caso de olvido.

La **decisión definitiva de producto** es tajante: **el superadministrador SOLO introduce el correo electrónico para invitar a un nuevo usuario.** No solicita identificación, nombre, apellido, usuario ni contraseña. El usuario invitado completa sus propios datos mediante un enlace seguro de un solo uso y define su propia contraseña. Los permisos individuales se administran por separado con mínimo privilegio, sin roles y sin permisos administrativos por defecto.

## What Changes

- **Flujo de invitación administrativa:**
  - El modal de administración cambia de «Crear usuario» a «Invitar usuario».
  - El único campo del formulario es «Correo electrónico», con botones `Enviar invitación | Cancelar`.
  - Generación de token criptográfico aleatorio seguro (`random_bytes(32)`) del cual se almacena únicamente su hash SHA-256 en la base de datos `TransportesSegurosSA_Test` con vigencia de 24 horas.
  - Tabla independiente `dbo.invitaciones_usuario` con estados `PENDIENTE`, `COMPLETADA`, `EXPIRADA`, `CANCELADA`.
  - Vistas de administración de invitaciones pendientes con acciones de «Reenviar» y «Cancelar».
- **Flujo de onboarding del usuario invitado:**
  - El usuario abre el enlace `https://.../auth/onboarding.php?token=...`.
  - El correo aparece prefijado y no editable.
  - El usuario ingresa su tipo y número de identificación (primero, con opción de consulta oficial a Hacienda), nombres, apellidos, nombre de usuario y su propia contraseña (mínimo 12 caracteres, verificada con confirmación y almacenada con `password_hash`).
  - La cuenta se crea y activa de forma atómica en `dbo.usuarios` con cero permisos asignados.
- **Flujo de recuperación «¿Olvidaste tu contraseña?»:**
  - Enlace visible en la pantalla de login.
  - Formulario público con solo correo electrónico y respuesta genérica no enumerable.
  - Generación de token temporal (30 minutos) e invalidación de tokens previos.
  - Página de restablecimiento de contraseña que exige ingresar y confirmar nueva contraseña de al menos 12 caracteres.
- **Abstracción de correo transaccional (Brevo API):**
  - Interfaz `MailSender` con transporte real `BrevoApiSender` (vía API HTTPS `POST https://api.brevo.com/v3/smtp/email`) y transporte de pruebas `FakeMailCaptureSender` (por defecto en ambiente Test, capturando enlaces sin requerir servicios externos ni enviar correos reales).
  - Plantillas de correo profesional en español con identidad corporativa de Transportes Seguros S.A.

## Alcance, exclusiones y riesgos

- **Alcance:** Modificaciones en `TransportesSegurosSA_Test` para tabla de invitaciones y procedimientos almacenados; vistas y controladores de invitación, onboarding y recuperación; servicio de correo transaccional desacoplado; y suites de prueba automatizadas.
- **No-alcance:** No modifica las bases protegidas `TransportesSegurosSA` ni `TransportesSegurosSA_DW`; no envía correos reales en pruebas; no asigna permisos administrativos por defecto; no almacena contraseñas en texto claro ni tokens sin hashear; no altera el usuario `admin_proyecto` ni las cuentas existentes.
- **Riesgos y mitigación:**
  - *Consumo concurrente de tokens:* validación con transacción y bloqueo `UPDLOCK, HOLDLOCK` al canjear el token para evitar doble activación.
  - *Fuga de tokens:* almacenamiento exclusivo del hash SHA-256 en la base de datos, política `Referrer-Policy: no-referrer` en cabeceras HTTP y omisión de tokens en registros de auditoría y logs.
- **Estrategia de reversión:** Scripts SQL reversibles `02_rollback_account_invitations_and_recovery.sql` y commits modulares en Git.

## Capabilities

### New Capabilities
- `account-invitations-and-recovery`: Invitación por correo con autoservicio completo de onboarding, recuperación segura de contraseñas y servicio de correo transaccional compatible con Brevo API.

## Impact

Impacta `app/mail/`, `app/user_security.php`, `public/auth/`, `public/usuarios/`, base de datos de pruebas `TransportesSegurosSA_Test` y suites de prueba en `tests/`.
