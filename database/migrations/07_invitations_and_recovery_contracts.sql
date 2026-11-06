/*
    Transportes Seguros S.A.
    Contratos de invitaciones de autoservicio y recuperación de contraseñas.
    Ambiente exclusivo: TransportesSegurosSA_Test
*/

IF DB_NAME() NOT IN (N'TransportesSegurosSA', N'TransportesSegurosSA_Test', N'TransportesSegurosSA_Staging')
    THROW 50920, 'Este contrato solo puede ejecutarse en una base autorizada de TransportesSegurosSA.', 1;
GO

-- 1. Tabla de perfil de usuario (identificación personal/fiscal única)
IF OBJECT_ID('dbo.usuarios_perfil', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.usuarios_perfil (
        usuario_id INT NOT NULL PRIMARY KEY,
        tipo_identificacion VARCHAR(5) NOT NULL DEFAULT '01',
        identificacion NVARCHAR(30) NOT NULL UNIQUE,
        nombre NVARCHAR(100) NOT NULL,
        primer_apellido NVARCHAR(100) NOT NULL,
        segundo_apellido NVARCHAR(100) NULL,
        telefono NVARCHAR(30) NULL,
        fecha_creacion DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
        CONSTRAINT FK_usuarios_perfil_usuario FOREIGN KEY (usuario_id) REFERENCES dbo.usuarios(usuario_id)
    );

    -- Poblar perfiles iniciales para cuentas existentes
    INSERT INTO dbo.usuarios_perfil (usuario_id, tipo_identificacion, identificacion, nombre, primer_apellido, segundo_apellido)
    SELECT usuario_id, '01', CONCAT(N'ADM-', usuario_id), N'Administrador', N'Sistema', N'TSSA'
    FROM dbo.usuarios
    WHERE usuario_id NOT IN (SELECT usuario_id FROM dbo.usuarios_perfil);
END
GO

-- 2. Tabla de invitaciones de usuario
IF OBJECT_ID('dbo.invitaciones_usuario', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.invitaciones_usuario (
        invitacion_id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        correo_destino NVARCHAR(150) NOT NULL,
        token_hash VARCHAR(64) NOT NULL UNIQUE,
        estado VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',
        fecha_expiracion DATETIME2 NOT NULL,
        creado_por_usuario_id INT NOT NULL,
        fecha_creacion DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
        fecha_completada DATETIME2 NULL,
        completado_usuario_id INT NULL,
        ultimo_envio_en DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
        envios_contador INT NOT NULL DEFAULT 1,
        estado_envio VARCHAR(30) NOT NULL DEFAULT 'PENDIENTE',
        CONSTRAINT CK_invitaciones_estado CHECK (estado IN ('PENDIENTE', 'COMPLETADA', 'EXPIRADA', 'REVOCADA', 'CANCELADA')),
        CONSTRAINT FK_invitaciones_creador FOREIGN KEY (creado_por_usuario_id) REFERENCES dbo.usuarios(usuario_id),
        CONSTRAINT FK_invitaciones_usuario_creado FOREIGN KEY (completado_usuario_id) REFERENCES dbo.usuarios(usuario_id)
    );

    CREATE INDEX IX_invitaciones_correo ON dbo.invitaciones_usuario(correo_destino);
END
ELSE
BEGIN
    -- Asegurar columnas para ciclo de invitaciones extendido
    IF COL_LENGTH('dbo.invitaciones_usuario', 'ultimo_envio_en') IS NULL
    BEGIN
        ALTER TABLE dbo.invitaciones_usuario ADD ultimo_envio_en DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME();
    END;

    IF COL_LENGTH('dbo.invitaciones_usuario', 'envios_contador') IS NULL
    BEGIN
        ALTER TABLE dbo.invitaciones_usuario ADD envios_contador INT NOT NULL DEFAULT 1;
    END;

    IF COL_LENGTH('dbo.invitaciones_usuario', 'estado_envio') IS NULL
    BEGIN
        ALTER TABLE dbo.invitaciones_usuario ADD estado_envio VARCHAR(30) NOT NULL DEFAULT 'PENDIENTE';
    END;

    -- Actualizar restricción de estados para admitir REVOCADA
    IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE name = 'CK_invitaciones_estado')
    BEGIN
        ALTER TABLE dbo.invitaciones_usuario DROP CONSTRAINT CK_invitaciones_estado;
    END;

    ALTER TABLE dbo.invitaciones_usuario ADD CONSTRAINT CK_invitaciones_estado
        CHECK (estado IN ('PENDIENTE', 'COMPLETADA', 'EXPIRADA', 'REVOCADA', 'CANCELADA'));
END
GO

-- 3. Tabla de tokens de recuperación de contraseñas
IF OBJECT_ID('dbo.tokens_recuperacion', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.tokens_recuperacion (
        recuperacion_id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        usuario_id INT NOT NULL,
        token_hash VARCHAR(64) NOT NULL UNIQUE,
        estado VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',
        fecha_expiracion DATETIME2 NOT NULL,
        fecha_creacion DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
        fecha_usado DATETIME2 NULL,
        CONSTRAINT CK_tokens_recuperacion_estado CHECK (estado IN ('PENDIENTE', 'USADO', 'EXPIRADO', 'REVOCADO')),
        CONSTRAINT FK_tokens_recuperacion_usuario FOREIGN KEY (usuario_id) REFERENCES dbo.usuarios(usuario_id)
    );

    CREATE INDEX IX_tokens_recuperacion_usuario ON dbo.tokens_recuperacion(usuario_id);
END
GO

-- 4. SP para crear invitación de usuario (con rate limit y cooldown)
CREATE OR ALTER PROCEDURE dbo.sp_crear_invitacion_usuario
    @correo_destino NVARCHAR(150),
    @token_hash VARCHAR(64),
    @horas_vigencia INT = 24,
    @invitacion_id INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'USUARIOS_GESTIONAR_CUENTAS') = 0
        THROW 50412, 'No tiene permiso para gestionar cuentas de usuario.', 1;

    SET @correo_destino = LOWER(LTRIM(RTRIM(@correo_destino)));

    IF @correo_destino IS NULL
       OR @correo_destino NOT LIKE N'%_@_%._%'
       OR @correo_destino LIKE N'% %'
        THROW 50413, 'El correo electrónico proporcionado no tiene un formato válido.', 1;

    IF @token_hash IS NULL OR LEN(@token_hash) <> 64
        THROW 50435, 'El hash del token de invitación no es válido.', 1;

    BEGIN TRY
        BEGIN TRANSACTION;

        -- Verificar si ya existe una cuenta activa con ese correo
        IF EXISTS (
            SELECT 1 FROM dbo.usuarios WITH (UPDLOCK, HOLDLOCK)
            WHERE LOWER(correo_acceso) = @correo_destino AND activo = 1
        )
            THROW 50430, 'El correo ya pertenece a una cuenta activa.', 1;

        -- Rate limit: máximo 5 invitaciones para este correo en 24 horas
        DECLARE @envios_24h INT = 0;
        SELECT @envios_24h = COUNT(*)
        FROM dbo.invitaciones_usuario WITH (UPDLOCK, HOLDLOCK)
        WHERE LOWER(correo_destino) = @correo_destino
          AND fecha_creacion >= DATEADD(HOUR, -24, SYSUTCDATETIME());

        IF @envios_24h >= 5
            THROW 50439, 'Se ha alcanzado el límite de 5 invitaciones para este correo en un período de 24 horas.', 1;

        -- Verificar si existe una invitación vigente en estado PENDIENTE
        DECLARE @pendiente_id INT = NULL;
        DECLARE @ultimo_envio DATETIME2 = NULL;

        SELECT TOP 1
            @pendiente_id = invitacion_id,
            @ultimo_envio = ultimo_envio_en
        FROM dbo.invitaciones_usuario WITH (UPDLOCK, HOLDLOCK)
        WHERE LOWER(correo_destino) = @correo_destino
          AND estado = 'PENDIENTE'
          AND fecha_expiracion > SYSUTCDATETIME();

        IF @pendiente_id IS NOT NULL
        BEGIN
            -- Cooldown de 60 segundos entre reenvíos
            IF @ultimo_envio IS NOT NULL AND DATEDIFF(SECOND, @ultimo_envio, SYSUTCDATETIME()) < 60
                THROW 50440, 'Debes esperar al menos 60 segundos antes de reenviar una invitación a este correo.', 1;

            THROW 50431, 'Ya existe una invitación pendiente para este correo electrónico. Puedes reenviarla o revocarla.', 1;
        END;

        -- Insertar invitación
        INSERT INTO dbo.invitaciones_usuario
        (
            correo_destino, token_hash, estado, fecha_expiracion, creado_por_usuario_id,
            fecha_creacion, ultimo_envio_en, envios_contador, estado_envio
        )
        VALUES
        (
            @correo_destino, @token_hash, 'PENDIENTE',
            DATEADD(HOUR, ISNULL(@horas_vigencia, 24), SYSUTCDATETIME()),
            @actor_id,
            SYSUTCDATETIME(),
            SYSUTCDATETIME(),
            1,
            'PENDIENTE'
        );

        SET @invitacion_id = CONVERT(INT, SCOPE_IDENTITY());

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_nuevos)
        VALUES (@actor_id, N'invitaciones_usuario', CONVERT(NVARCHAR(100), @invitacion_id), 'INVITE', CONCAT(N'Invitación emitida a correo: ', @correo_destino));

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

-- 5. SP para validar token de invitación
CREATE OR ALTER PROCEDURE dbo.sp_validar_token_invitacion
    @token_hash VARCHAR(64)
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @invitacion_id INT;
    DECLARE @correo NVARCHAR(150);
    DECLARE @estado VARCHAR(20);
    DECLARE @expiracion DATETIME2;

    SELECT
        @invitacion_id = invitacion_id,
        @correo = correo_destino,
        @estado = estado,
        @expiracion = fecha_expiracion
    FROM dbo.invitaciones_usuario WITH (UPDLOCK, HOLDLOCK)
    WHERE token_hash = @token_hash;

    IF @invitacion_id IS NULL
    BEGIN
        SELECT 'invalido' AS resultado, NULL AS invitacion_id, NULL AS correo_destino;
        RETURN;
    END;

    IF @estado IN ('REVOCADA', 'CANCELADA')
    BEGIN
        SELECT 'revocada' AS resultado, @invitacion_id AS invitacion_id, @correo AS correo_destino;
        RETURN;
    END;

    IF @estado = 'COMPLETADA'
    BEGIN
        SELECT 'completada' AS resultado, @invitacion_id AS invitacion_id, @correo AS correo_destino;
        RETURN;
    END;

    IF @expiracion <= SYSUTCDATETIME()
    BEGIN
        IF @estado = 'PENDIENTE'
        BEGIN
            UPDATE dbo.invitaciones_usuario SET estado = 'EXPIRADA' WHERE invitacion_id = @invitacion_id;
        END;
        SELECT 'expirada' AS resultado, @invitacion_id AS invitacion_id, @correo AS correo_destino;
        RETURN;
    END;

    IF @estado = 'PENDIENTE'
    BEGIN
        SELECT 'valida' AS resultado, @invitacion_id AS invitacion_id, @correo AS correo_destino;
        RETURN;
    END;

    SELECT 'invalido' AS resultado, @invitacion_id AS invitacion_id, @correo AS correo_destino;
END;
GO

-- 6. SP para completar invitación y crear usuario con datos propios
CREATE OR ALTER PROCEDURE dbo.sp_completar_invitacion_usuario
    @token_hash VARCHAR(64),
    @identificacion NVARCHAR(30),
    @tipo_identificacion VARCHAR(5) = '01',
    @nombre NVARCHAR(100),
    @primer_apellido NVARCHAR(100),
    @segundo_apellido NVARCHAR(100) = NULL,
    @nombre_usuario NVARCHAR(80),
    @password_hash NVARCHAR(255),
    @telefono NVARCHAR(30) = NULL,
    @nuevo_usuario_id INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    SET @identificacion = NULLIF(LTRIM(RTRIM(@identificacion)), N'');
    SET @nombre = NULLIF(LTRIM(RTRIM(@nombre)), N'');
    SET @primer_apellido = NULLIF(LTRIM(RTRIM(@primer_apellido)), N'');
    SET @segundo_apellido = NULLIF(LTRIM(RTRIM(@segundo_apellido)), N'');
    SET @nombre_usuario = NULLIF(LTRIM(RTRIM(@nombre_usuario)), N'');
    SET @telefono = NULLIF(LTRIM(RTRIM(@telefono)), N'');

    IF @identificacion IS NULL
       OR @nombre IS NULL
       OR @primer_apellido IS NULL
       OR @nombre_usuario IS NULL
       OR LEN(@nombre_usuario) < 3
       OR @nombre_usuario LIKE N'% %'
        THROW 50413, 'Todos los datos requeridos deben ser completados correctamente.', 1;

    IF @password_hash IS NULL
       OR LEN(@password_hash) <> 60
       OR DATALENGTH(@password_hash) <> 120
       OR @password_hash NOT LIKE N'$2[abxy]$[0-9][0-9]$%'
        THROW 50414, 'El hash de contraseña proporcionado no es válido.', 1;

    BEGIN TRY
        BEGIN TRANSACTION;

        DECLARE @invitacion_id INT;
        DECLARE @correo NVARCHAR(150);
        DECLARE @estado VARCHAR(20);
        DECLARE @expiracion DATETIME2;

        SELECT
            @invitacion_id = invitacion_id,
            @correo = correo_destino,
            @estado = estado,
            @expiracion = fecha_expiracion
        FROM dbo.invitaciones_usuario WITH (UPDLOCK, HOLDLOCK)
        WHERE token_hash = @token_hash;

        IF @invitacion_id IS NULL OR @estado <> 'PENDIENTE' OR @expiracion <= SYSUTCDATETIME()
            THROW 50432, 'La invitación es inválida, ya fue utilizada, revocada o ha expirado.', 1;

        -- Verificar que el nombre de usuario o correo no existan ya
        IF EXISTS (
            SELECT 1 FROM dbo.usuarios WITH (UPDLOCK, HOLDLOCK)
            WHERE nombre_usuario = @nombre_usuario OR LOWER(correo_acceso) = LOWER(@correo)
        )
            THROW 50434, 'El nombre de usuario o correo ya está en uso.', 1;

        -- Verificar que la identificación no pertenezca a otro usuario
        IF EXISTS (
            SELECT 1 FROM dbo.usuarios_perfil WITH (UPDLOCK, HOLDLOCK)
            WHERE identificacion = @identificacion
        )
            THROW 50433, 'La identificación ya está registrada para otro usuario.', 1;

        -- Crear usuario en dbo.usuarios (activo, con contraseña propia, sin permisos por defecto)
        INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash, activo)
        VALUES (@nombre_usuario, @correo, @password_hash, 1);

        SET @nuevo_usuario_id = CONVERT(INT, SCOPE_IDENTITY());

        -- Insertar perfil
        INSERT INTO dbo.usuarios_perfil
        (
            usuario_id, tipo_identificacion, identificacion, nombre, primer_apellido, segundo_apellido, telefono
        )
        VALUES
        (
            @nuevo_usuario_id, ISNULL(@tipo_identificacion, '01'), @identificacion, @nombre, @primer_apellido, @segundo_apellido, @telefono
        );

        -- Marcar invitación completada
        UPDATE dbo.invitaciones_usuario
        SET estado = 'COMPLETADA',
            fecha_completada = SYSUTCDATETIME(),
            completado_usuario_id = @nuevo_usuario_id
        WHERE invitacion_id = @invitacion_id;

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_nuevos)
        VALUES (@nuevo_usuario_id, N'usuarios', CONVERT(NVARCHAR(100), @nuevo_usuario_id), 'ONBOARDING', CONCAT(N'Usuario activado por autoservicio: ', @nombre_usuario));

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

-- 7. SP para revocar invitación de usuario
CREATE OR ALTER PROCEDURE dbo.sp_revocar_invitacion_usuario
    @invitacion_id INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'USUARIOS_GESTIONAR_CUENTAS') = 0
        THROW 50412, 'No tiene permiso para gestionar cuentas de usuario.', 1;

    BEGIN TRY
        BEGIN TRANSACTION;

        DECLARE @correo NVARCHAR(150);
        DECLARE @estado VARCHAR(20);

        SELECT @correo = correo_destino, @estado = estado
        FROM dbo.invitaciones_usuario WITH (UPDLOCK, HOLDLOCK)
        WHERE invitacion_id = @invitacion_id;

        IF @correo IS NULL
            THROW 50436, 'La invitación no existe.', 1;

        IF @estado = 'COMPLETADA'
            THROW 50441, 'Una invitación completada no puede ser revocada.', 1;

        IF @estado IN ('REVOCADA', 'CANCELADA')
            THROW 50442, 'La invitación ya se encuentra revocada.', 1;

        UPDATE dbo.invitaciones_usuario
        SET estado = 'REVOCADA'
        WHERE invitacion_id = @invitacion_id;

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_nuevos)
        VALUES (@actor_id, N'invitaciones_usuario', CONVERT(NVARCHAR(100), @invitacion_id), 'REVOKE', CONCAT(N'Invitación revocada para: ', @correo));

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

-- Alias de compatibilidad para sp_cancelar_invitacion_usuario
CREATE OR ALTER PROCEDURE dbo.sp_cancelar_invitacion_usuario
    @invitacion_id INT
AS
BEGIN
    EXEC dbo.sp_revocar_invitacion_usuario @invitacion_id = @invitacion_id;
END;
GO

-- 8. SP para reenviar invitación de usuario
CREATE OR ALTER PROCEDURE dbo.sp_reenviar_invitacion_usuario
    @invitacion_id INT,
    @nuevo_token_hash VARCHAR(64),
    @horas_vigencia INT = 24,
    @nueva_invitacion_id INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'USUARIOS_GESTIONAR_CUENTAS') = 0
        THROW 50412, 'No tiene permiso para gestionar cuentas de usuario.', 1;

    IF @nuevo_token_hash IS NULL OR LEN(@nuevo_token_hash) <> 64
        THROW 50435, 'El hash del nuevo token de invitación no es válido.', 1;

    BEGIN TRY
        BEGIN TRANSACTION;

        DECLARE @correo NVARCHAR(150);
        DECLARE @estado VARCHAR(20);
        DECLARE @ultimo_envio DATETIME2;
        DECLARE @envios INT;

        SELECT
            @correo = correo_destino,
            @estado = estado,
            @ultimo_envio = ultimo_envio_en,
            @envios = envios_contador
        FROM dbo.invitaciones_usuario WITH (UPDLOCK, HOLDLOCK)
        WHERE invitacion_id = @invitacion_id;

        IF @correo IS NULL
            THROW 50437, 'La invitación original no fue encontrada.', 1;

        IF @estado = 'COMPLETADA'
            THROW 50441, 'Una invitación completada no puede ser reenviada.', 1;

        -- Verificar si ya existe cuenta activa
        IF EXISTS (
            SELECT 1 FROM dbo.usuarios WITH (UPDLOCK, HOLDLOCK)
            WHERE LOWER(correo_acceso) = LOWER(@correo) AND activo = 1
        )
            THROW 50430, 'El correo ya pertenece a una cuenta activa.', 1;

        -- Rate limit: máximo 5 invitaciones por correo en 24 horas
        DECLARE @envios_24h INT = 0;
        SELECT @envios_24h = COUNT(*)
        FROM dbo.invitaciones_usuario WITH (UPDLOCK, HOLDLOCK)
        WHERE LOWER(correo_destino) = LOWER(@correo)
          AND fecha_creacion >= DATEADD(HOUR, -24, SYSUTCDATETIME());

        IF @envios_24h >= 5
            THROW 50439, 'Se ha alcanzado el límite de 5 invitaciones para este correo en un período de 24 horas.', 1;

        -- Cooldown de 60 segundos entre reenvíos
        IF @ultimo_envio IS NOT NULL AND DATEDIFF(SECOND, @ultimo_envio, SYSUTCDATETIME()) < 60
            THROW 50440, 'Debes esperar al menos 60 segundos antes de reenviar una invitación a este correo.', 1;

        -- Revocar la invitación previa
        UPDATE dbo.invitaciones_usuario
        SET estado = 'REVOCADA'
        WHERE invitacion_id = @invitacion_id AND estado IN ('PENDIENTE', 'EXPIRADA');

        -- Crear la nueva invitación con nuevo token y nueva vigencia de 24h
        INSERT INTO dbo.invitaciones_usuario
        (
            correo_destino, token_hash, estado, fecha_expiracion, creado_por_usuario_id,
            fecha_creacion, ultimo_envio_en, envios_contador, estado_envio
        )
        VALUES
        (
            @correo, @nuevo_token_hash, 'PENDIENTE',
            DATEADD(HOUR, ISNULL(@horas_vigencia, 24), SYSUTCDATETIME()),
            @actor_id,
            SYSUTCDATETIME(),
            SYSUTCDATETIME(),
            ISNULL(@envios, 0) + 1,
            'PENDIENTE'
        );

        SET @nueva_invitacion_id = CONVERT(INT, SCOPE_IDENTITY());

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_nuevos)
        VALUES (@actor_id, N'invitaciones_usuario', CONVERT(NVARCHAR(100), @nueva_invitacion_id), 'RESEND', CONCAT(N'Invitación reenviada a correo: ', @correo));

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

-- 9. SP para actualizar el estado del envío de correo de una invitación
CREATE OR ALTER PROCEDURE dbo.sp_actualizar_estado_envio_invitacion
    @invitacion_id INT,
    @estado_envio VARCHAR(30)
AS
BEGIN
    SET NOCOUNT ON;

    UPDATE dbo.invitaciones_usuario
    SET estado_envio = @estado_envio,
        ultimo_envio_en = SYSUTCDATETIME()
    WHERE invitacion_id = @invitacion_id;
END;
GO

-- 10. SP para listar invitaciones de usuario
CREATE OR ALTER PROCEDURE dbo.sp_listar_invitaciones
    @estado VARCHAR(20) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'USUARIOS_VER') = 0
        THROW 50410, 'No tiene permiso para consultar usuarios.', 1;

    -- Actualizar automáticamente invitaciones vencidas
    UPDATE dbo.invitaciones_usuario
    SET estado = 'EXPIRADA'
    WHERE estado = 'PENDIENTE' AND fecha_expiracion <= SYSUTCDATETIME();

    SELECT
        i.invitacion_id,
        i.correo_destino,
        i.estado,
        i.fecha_expiracion,
        i.fecha_creacion,
        i.fecha_completada,
        i.ultimo_envio_en,
        i.envios_contador,
        i.estado_envio,
        u_crea.nombre_usuario AS creado_por_nombre,
        u_comp.nombre_usuario AS completado_por_nombre
    FROM dbo.invitaciones_usuario i
    JOIN dbo.usuarios u_crea ON u_crea.usuario_id = i.creado_por_usuario_id
    LEFT JOIN dbo.usuarios u_comp ON u_comp.usuario_id = i.completado_usuario_id
    WHERE (@estado IS NULL OR i.estado = @estado)
    ORDER BY i.fecha_creacion DESC;
END;
GO

-- 11. SP para solicitar recuperación de contraseña
CREATE OR ALTER PROCEDURE dbo.sp_solicitar_recuperacion_password
    @correo NVARCHAR(150),
    @token_hash VARCHAR(64),
    @minutos_vigencia INT = 120,
    @usuario_id INT OUTPUT,
    @nombre_usuario NVARCHAR(80) OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    SET @correo = LOWER(LTRIM(RTRIM(@correo)));
    SET @usuario_id = NULL;
    SET @nombre_usuario = NULL;

    SELECT
        @usuario_id = usuario_id,
        @nombre_usuario = nombre_usuario
    FROM dbo.usuarios WITH (UPDLOCK, HOLDLOCK)
    WHERE LOWER(correo_acceso) = @correo AND activo = 1;

    -- Si el usuario no existe o está inactivo, no hacemos nada (respuesta genérica no enumerable)
    IF @usuario_id IS NULL
        RETURN;

    BEGIN TRY
        BEGIN TRANSACTION;

        -- Revocar tokens previos pendientes
        UPDATE dbo.tokens_recuperacion
        SET estado = 'REVOCADO'
        WHERE usuario_id = @usuario_id AND estado = 'PENDIENTE';

        -- Insertar nuevo token
        INSERT INTO dbo.tokens_recuperacion
        (
            usuario_id, token_hash, estado, fecha_expiracion
        )
        VALUES
        (
            @usuario_id, @token_hash, 'PENDIENTE',
            DATEADD(MINUTE, ISNULL(@minutos_vigencia, 120), SYSUTCDATETIME())
        );

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

-- 12. SP para validar token de recuperación
CREATE OR ALTER PROCEDURE dbo.sp_validar_token_recuperacion
    @token_hash VARCHAR(64)
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @recuperacion_id INT;
    DECLARE @usuario_id INT;
    DECLARE @estado VARCHAR(20);
    DECLARE @expiracion DATETIME2;
    DECLARE @correo NVARCHAR(150);

    SELECT
        @recuperacion_id = r.recuperacion_id,
        @usuario_id = r.usuario_id,
        @estado = r.estado,
        @expiracion = r.fecha_expiracion,
        @correo = u.correo_acceso
    FROM dbo.tokens_recuperacion r WITH (UPDLOCK, HOLDLOCK)
    JOIN dbo.usuarios u ON u.usuario_id = r.usuario_id
    WHERE r.token_hash = @token_hash;

    IF @recuperacion_id IS NULL OR @estado <> 'PENDIENTE'
    BEGIN
        SELECT 'invalido' AS resultado, NULL AS usuario_id, NULL AS correo_acceso;
        RETURN;
    END;

    IF @expiracion <= SYSUTCDATETIME()
    BEGIN
        UPDATE dbo.tokens_recuperacion SET estado = 'EXPIRADO' WHERE recuperacion_id = @recuperacion_id;
        SELECT 'expirado' AS resultado, NULL AS usuario_id, NULL AS correo_acceso;
        RETURN;
    END;

    SELECT 'valido' AS resultado, @usuario_id AS usuario_id, @correo AS correo_acceso;
END;
GO

-- 13. SP para completar recuperación de contraseña
CREATE OR ALTER PROCEDURE dbo.sp_completar_recuperacion_password
    @token_hash VARCHAR(64),
    @password_hash NVARCHAR(255)
AS
BEGIN
    SET NOCOUNT ON;

    IF @password_hash IS NULL
       OR LEN(@password_hash) <> 60
       OR DATALENGTH(@password_hash) <> 120
       OR @password_hash NOT LIKE N'$2[abxy]$[0-9][0-9]$%'
        THROW 50414, 'El hash de contraseña proporcionado no es válido.', 1;

    BEGIN TRY
        BEGIN TRANSACTION;

        DECLARE @recuperacion_id INT;
        DECLARE @usuario_id INT;
        DECLARE @estado VARCHAR(20);
        DECLARE @expiracion DATETIME2;

        SELECT
            @recuperacion_id = recuperacion_id,
            @usuario_id = usuario_id,
            @estado = estado,
            @expiracion = fecha_expiracion
        FROM dbo.tokens_recuperacion WITH (UPDLOCK, HOLDLOCK)
        WHERE token_hash = @token_hash;

        IF @recuperacion_id IS NULL OR @estado <> 'PENDIENTE' OR @expiracion <= SYSUTCDATETIME()
            THROW 50438, 'El enlace de recuperación es inválido, ya fue utilizado o ha expirado.', 1;

        -- Actualizar contraseña del usuario
        UPDATE dbo.usuarios
        SET password_hash = @password_hash
        WHERE usuario_id = @usuario_id;

        -- Marcar token como USADO
        UPDATE dbo.tokens_recuperacion
        SET estado = 'USADO', fecha_usado = SYSUTCDATETIME()
        WHERE recuperacion_id = @recuperacion_id;

        -- Revocar cualquier otro token pendiente
        UPDATE dbo.tokens_recuperacion
        SET estado = 'REVOCADO'
        WHERE usuario_id = @usuario_id AND estado = 'PENDIENTE';

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_nuevos)
        VALUES (@usuario_id, N'usuarios', CONVERT(NVARCHAR(100), @usuario_id), 'PASSWORD_RESET', N'Contraseña restablecida por autoservicio con token verificado');

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO
