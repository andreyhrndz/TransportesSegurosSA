# Diseño: invitaciones de autoservicio por correo y recuperación de contraseñas

## 1. Esquema de datos y procedimientos en `TransportesSegurosSA_Test`

- **Tabla `dbo.invitaciones_usuario`:**
  ```sql
  CREATE TABLE dbo.invitaciones_usuario (
      invitacion_id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
      email NVARCHAR(255) NOT NULL,
      token_hash VARCHAR(64) NOT NULL UNIQUE,
      estado VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE', -- PENDIENTE, COMPLETADA, EXPIRADA, CANCELADA
      fecha_expiracion DATETIME2 NOT NULL,
      creado_por_usuario_id INT NOT NULL,
      fecha_creacion DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
      fecha_completada DATETIME2 NULL,
      CONSTRAINT CK_invitaciones_estado CHECK (estado IN ('PENDIENTE', 'COMPLETADA', 'EXPIRADA', 'CANCELADA')),
      CONSTRAINT FK_invitaciones_usuario FOREIGN KEY (creado_por_usuario_id) REFERENCES dbo.usuarios(usuario_id)
  );
  CREATE INDEX IX_invitaciones_email ON dbo.invitaciones_usuario(email);
  ```

- **Tabla `dbo.tokens_recuperacion`:**
  ```sql
  CREATE TABLE dbo.tokens_recuperacion (
      recuperacion_id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
      usuario_id INT NOT NULL,
      token_hash VARCHAR(64) NOT NULL UNIQUE,
      estado VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE', -- PENDIENTE, USADO, EXPIRADO, REVOCADO
      fecha_expiracion DATETIME2 NOT NULL,
      fecha_creacion DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
      fecha_usado DATETIME2 NULL,
      CONSTRAINT CK_tokens_recuperacion_estado CHECK (estado IN ('PENDIENTE', 'USADO', 'EXPIRADO', 'REVOCADO')),
      CONSTRAINT FK_tokens_recuperacion_usuario FOREIGN KEY (usuario_id) REFERENCES dbo.usuarios(usuario_id)
  );
  ```

- **Procedimientos almacenados en Test:**
  - `dbo.sp_crear_invitacion_usuario`: inserta la invitación si el correo no pertenece ya a un usuario activo o invitación pendiente vigente.
  - `dbo.sp_validar_token_invitacion`: consulta la validez del token por su hash SHA-256; si expiró, actualiza el estado a `EXPIRADA`.
  - `dbo.sp_completar_invitacion_usuario`: verifica el token con `UPDLOCK, HOLDLOCK`, crea el usuario en `dbo.usuarios` con `estado = 'ACTIVO'` e inserta la identificación asociada, marcando la invitación como `COMPLETADA` en una sola transacción atómica.
  - `dbo.sp_cancelar_invitacion_usuario`: marca la invitación como `CANCELADA`.
  - `dbo.sp_reenviar_invitacion_usuario`: invalida la invitación anterior y genera un nuevo registro.
  - `dbo.sp_listar_invitaciones`: lista las invitaciones pendientes y su historial para la administración.
  - `dbo.sp_solicitar_recuperacion_password`: busca el usuario por email e inserta el token hash si existe y está activo.
  - `dbo.sp_validar_token_recuperacion`: verifica la validez del token de reseteo.
  - `dbo.sp_completar_recuperacion_password`: actualiza la contraseña del usuario en `dbo.usuarios` y marca el token como `USADO`.

## 2. Servicio de correo transaccional (`app/mail/`)

- **Contrato `MailSender`:**
  - Método `send(string $to, string $toName, string $subject, string $htmlContent): bool`.
- **Implementaciones:**
  - `BrevoApiSender`: consume la API oficial HTTPS de Brevo (`POST https://api.brevo.com/v3/smtp/email`) con `api-key`, remitente verificado y cabeceras adecuadas.
  - `FakeMailCaptureSender`: utilizado en ambiente Test o cuando falta la clave de Brevo. Captura los correos enviados en un almacenamiento en memoria o archivo temporal protegido, permitiendo a las pruebas unitarias e integración extraer los tokens sin realizar peticiones de red reales.
- **Factoría `mail_sender()`:** devuelve automáticamente `FakeMailCaptureSender` si la base es `TransportesSegurosSA_Test` o no existe `BREVO_API_KEY`.

## 3. Seguridad de contraseñas y tokens

- Generación con `random_bytes(32)` (256 bits de entropía).
- Almacenamiento exclusivo del hash `hash('sha256', $token)`.
- Cabecera HTTP `Referrer-Policy: no-referrer` en todas las pantallas con tokens.
- Política de contraseñas compatible con el estándar del proyecto: mínimo 12 caracteres, soporte de caracteres extendidos, hashing seguro con `password_hash(..., PASSWORD_BCRYPT)`.
