/*
  Contratos SQL provisionales para sql-user-account-contracts.
  Ejecutar exclusivamente en TransportesSegurosSA_Test.
  No introduce roles, no expone password_hash y conserva sp_conceder_permiso / sp_revocar_permiso.
*/
-- Contexto establecido por conexion cliente

IF DB_NAME() NOT IN (N'TransportesSegurosSA', N'TransportesSegurosSA_Test', N'TransportesSegurosSA_Staging')
    THROW 50920, 'Este contrato solo puede ejecutarse en una base autorizada de TransportesSegurosSA.', 1;
GO

MERGE dbo.permisos AS target
USING (VALUES
    (N'USUARIOS_VER', N'Consultar usuarios', N'Consultar cuentas de usuario y sus permisos asignados'),
    (N'USUARIOS_GESTIONAR_CUENTAS', N'Gestionar cuentas de usuario', N'Crear, editar, activar o desactivar cuentas y restablecer contraseñas')
) AS source(codigo, nombre, descripcion)
ON target.codigo = source.codigo
WHEN MATCHED THEN UPDATE SET
    nombre = source.nombre,
    descripcion = source.descripcion,
    activo = 1
WHEN NOT MATCHED BY TARGET THEN
    INSERT (codigo, nombre, descripcion) VALUES (source.codigo, source.nombre, source.descripcion);
GO

CREATE OR ALTER PROCEDURE dbo.sp_crear_usuario
    @nombre_usuario NVARCHAR(80),
    @correo_acceso NVARCHAR(150),
    @password_hash NVARCHAR(255),
    @usuario_id INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    -- ASVS 8.2.1, 8.3.1: exigir permiso individual USUARIOS_GESTIONAR_CUENTAS.
    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'USUARIOS_GESTIONAR_CUENTAS') = 0
        THROW 50412, 'No tiene permiso para gestionar cuentas de usuario.', 1;

    SET @nombre_usuario = NULLIF(LTRIM(RTRIM(@nombre_usuario)), N'');
    SET @correo_acceso = NULLIF(LTRIM(RTRIM(@correo_acceso)), N'');

    -- ASVS 2.2.1: validar estructura de usuario y correo.
    IF @nombre_usuario IS NULL
       OR LEN(@nombre_usuario) < 3
       OR @nombre_usuario LIKE N'% %'
       OR @correo_acceso IS NULL
       OR @correo_acceso NOT LIKE N'%_@_%._%'
       OR @correo_acceso LIKE N'% %'
        THROW 50413, 'Datos de cuenta de usuario inválidos.', 1;

    -- ASVS 6.2.8, 11.4: aceptar únicamente un hash bcrypt de 60 caracteres generado por PHP.
    IF @password_hash IS NULL
       OR LEN(@password_hash) <> 60
       OR DATALENGTH(@password_hash) <> 120
       OR @password_hash NOT LIKE N'$2[abxy]$[0-9][0-9]$%'
        THROW 50414, 'El hash de contraseña proporcionado no es válido.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1
        BEGIN TRANSACTION;
    ELSE
        SAVE TRANSACTION sp_crear_usr;

    BEGIN TRY
        IF EXISTS (
            SELECT 1
            FROM dbo.usuarios WITH (UPDLOCK, HOLDLOCK)
            WHERE nombre_usuario = @nombre_usuario
               OR correo_acceso = @correo_acceso
        )
            THROW 50416, 'El nombre de usuario o el correo de acceso ya está registrado.', 1;

        INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash, activo)
        VALUES (@nombre_usuario, @correo_acceso, @password_hash, 1);

        SET @usuario_id = CONVERT(INT, SCOPE_IDENTITY());

        INSERT INTO dbo.auditorias
        (
            usuario_id, entidad, registro_id, accion,
            valores_anteriores, valores_nuevos
        )
        VALUES
        (
            @actor_id,
            N'usuarios',
            CONVERT(NVARCHAR(100), @usuario_id),
            'INSERT',
            NULL,
            CONCAT(N'Usuario: ', @nombre_usuario, N'; Activo: 1')
        );

        IF @started_tran = 1
            COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1
            ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0
            ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1
            ROLLBACK TRANSACTION sp_crear_usr;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_actualizar_usuario
    @usuario_id INT,
    @nombre_usuario NVARCHAR(80),
    @correo_acceso NVARCHAR(150)
AS
BEGIN
    SET NOCOUNT ON;

    -- ASVS 8.2.1, 8.3.1: exigir permiso individual USUARIOS_GESTIONAR_CUENTAS.
    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'USUARIOS_GESTIONAR_CUENTAS') = 0
        THROW 50412, 'No tiene permiso para gestionar cuentas de usuario.', 1;

    IF @usuario_id IS NULL OR @usuario_id < 1
        THROW 50415, 'Usuario no encontrado.', 1;

    SET @nombre_usuario = NULLIF(LTRIM(RTRIM(@nombre_usuario)), N'');
    SET @correo_acceso = NULLIF(LTRIM(RTRIM(@correo_acceso)), N'');

    IF @nombre_usuario IS NULL
       OR LEN(@nombre_usuario) < 3
       OR @nombre_usuario LIKE N'% %'
       OR @correo_acceso IS NULL
       OR @correo_acceso NOT LIKE N'%_@_%._%'
       OR @correo_acceso LIKE N'% %'
        THROW 50413, 'Datos de cuenta de usuario inválidos.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1
        BEGIN TRANSACTION;
    ELSE
        SAVE TRANSACTION sp_act_usr;

    BEGIN TRY
        DECLARE @nombre_anterior NVARCHAR(80);
        SELECT @nombre_anterior = nombre_usuario
        FROM dbo.usuarios WITH (UPDLOCK, HOLDLOCK)
        WHERE usuario_id = @usuario_id;

        IF @nombre_anterior IS NULL
            THROW 50415, 'Usuario no encontrado.', 1;

        IF EXISTS (
            SELECT 1
            FROM dbo.usuarios WITH (UPDLOCK, HOLDLOCK)
            WHERE usuario_id <> @usuario_id
              AND (nombre_usuario = @nombre_usuario OR correo_acceso = @correo_acceso)
        )
            THROW 50416, 'El nombre de usuario o el correo de acceso ya está registrado.', 1;

        UPDATE dbo.usuarios
        SET nombre_usuario = @nombre_usuario,
            correo_acceso = @correo_acceso
        WHERE usuario_id = @usuario_id;

        INSERT INTO dbo.auditorias
        (
            usuario_id, entidad, registro_id, accion,
            valores_anteriores, valores_nuevos
        )
        VALUES
        (
            @actor_id,
            N'usuarios',
            CONVERT(NVARCHAR(100), @usuario_id),
            'UPDATE',
            CONCAT(N'Usuario: ', @nombre_anterior),
            CONCAT(N'Usuario: ', @nombre_usuario)
        );

        IF @started_tran = 1
            COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1
            ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0
            ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1
            ROLLBACK TRANSACTION sp_act_usr;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_cambiar_estado_usuario
    @usuario_id INT,
    @activo BIT
AS
BEGIN
    SET NOCOUNT ON;

    -- ASVS 8.2.1, 8.3.1: exigir permiso individual USUARIOS_GESTIONAR_CUENTAS.
    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'USUARIOS_GESTIONAR_CUENTAS') = 0
        THROW 50412, 'No tiene permiso para gestionar cuentas de usuario.', 1;

    IF @usuario_id IS NULL OR @usuario_id < 1
        THROW 50415, 'Usuario no encontrado.', 1;
    IF @activo IS NULL
        THROW 50413, 'Debe indicar un estado válido.', 1;

    -- Impedir auto-desactivación
    IF @activo = 0 AND @usuario_id = @actor_id
        THROW 50417, 'No puede desactivar su propia cuenta activa.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1
        BEGIN TRANSACTION;
    ELSE
        SAVE TRANSACTION sp_est_usr;

    BEGIN TRY
        DECLARE @activo_actual BIT;
        SELECT @activo_actual = activo
        FROM dbo.usuarios WITH (UPDLOCK, HOLDLOCK)
        WHERE usuario_id = @usuario_id;

        IF @activo_actual IS NULL
            THROW 50415, 'Usuario no encontrado.', 1;

        IF @activo = 0 AND @activo_actual = 1
        BEGIN
            -- Proteger al último gestor activo para permisos críticos de administración
            IF EXISTS (
                SELECT 1
                FROM dbo.usuarios_permisos AS up WITH (UPDLOCK, HOLDLOCK)
                JOIN dbo.permisos AS p ON p.permiso_id = up.permiso_id
                WHERE up.usuario_id = @usuario_id
                  AND p.activo = 1
                  AND p.codigo IN (N'USUARIOS_GESTIONAR_CUENTAS', N'USUARIOS_GESTIONAR_PERMISOS', N'USUARIOS_VER')
                  AND NOT EXISTS (
                      SELECT 1
                      FROM dbo.usuarios_permisos AS up2 WITH (UPDLOCK, HOLDLOCK)
                      JOIN dbo.usuarios AS u2 WITH (UPDLOCK, HOLDLOCK) ON u2.usuario_id = up2.usuario_id
                      WHERE up2.permiso_id = p.permiso_id
                        AND u2.usuario_id <> @usuario_id
                        AND u2.activo = 1
                  )
            )
                THROW 50418, 'No se puede desactivar la última cuenta activa con permisos administrativos críticos.', 1;
        END

        UPDATE dbo.usuarios
        SET activo = @activo
        WHERE usuario_id = @usuario_id;

        INSERT INTO dbo.auditorias
        (
            usuario_id, entidad, registro_id, accion,
            valores_anteriores, valores_nuevos
        )
        VALUES
        (
            @actor_id,
            N'usuarios',
            CONVERT(NVARCHAR(100), @usuario_id),
            'UPDATE',
            CONCAT(N'Activo: ', CONVERT(NVARCHAR(1), @activo_actual)),
            CONCAT(N'Activo: ', CONVERT(NVARCHAR(1), @activo))
        );

        IF @started_tran = 1
            COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1
            ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0
            ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1
            ROLLBACK TRANSACTION sp_est_usr;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_restablecer_password
    @usuario_id INT,
    @password_hash NVARCHAR(255)
AS
BEGIN
    SET NOCOUNT ON;

    -- ASVS 6.4.6, 8.2.1: flujo administrativo autorizado recibiendo únicamente hash bcrypt.
    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'USUARIOS_GESTIONAR_CUENTAS') = 0
        THROW 50412, 'No tiene permiso para gestionar cuentas de usuario.', 1;

    IF @usuario_id IS NULL OR @usuario_id < 1
        THROW 50415, 'Usuario no encontrado.', 1;

    IF @password_hash IS NULL
       OR LEN(@password_hash) <> 60
       OR DATALENGTH(@password_hash) <> 120
       OR @password_hash NOT LIKE N'$2[abxy]$[0-9][0-9]$%'
        THROW 50414, 'El hash de contraseña proporcionado no es válido.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1
        BEGIN TRANSACTION;
    ELSE
        SAVE TRANSACTION sp_rst_pwd;

    BEGIN TRY
        IF NOT EXISTS (
            SELECT 1
            FROM dbo.usuarios WITH (UPDLOCK, HOLDLOCK)
            WHERE usuario_id = @usuario_id
        )
            THROW 50415, 'Usuario no encontrado.', 1;

        UPDATE dbo.usuarios
        SET password_hash = @password_hash
        WHERE usuario_id = @usuario_id;

        -- Trazabilidad sin registrar jamás el hash ni secretos.
        INSERT INTO dbo.auditorias
        (
            usuario_id, entidad, registro_id, accion,
            valores_anteriores, valores_nuevos
        )
        VALUES
        (
            @actor_id,
            N'usuarios',
            CONVERT(NVARCHAR(100), @usuario_id),
            'UPDATE',
            NULL,
            N'Credencial restablecida'
        );

        IF @started_tran = 1
            COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1
            ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0
            ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1
            ROLLBACK TRANSACTION sp_rst_pwd;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_conceder_permiso_seguro
    @usuario_destino INT,
    @codigo NVARCHAR(100)
AS
BEGIN
    SET NOCOUNT ON;

    -- ASVS 8.2.1, 8.3.1: exige USUARIOS_GESTIONAR_PERMISOS y registra auditoría real de la asignación.
    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'USUARIOS_GESTIONAR_PERMISOS') = 0
        THROW 50407, 'Sin permiso para gestionar permisos.', 1;

    IF @usuario_destino IS NULL OR @usuario_destino < 1
        THROW 50415, 'Usuario no encontrado.', 1;

    SET @codigo = NULLIF(LTRIM(RTRIM(@codigo)), N'');
    IF @codigo IS NULL
        THROW 50408, 'El codigo de permiso no existe.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1
        BEGIN TRANSACTION;
    ELSE
        SAVE TRANSACTION sp_cnc_seg;

    BEGIN TRY
        IF NOT EXISTS (
            SELECT 1
            FROM dbo.usuarios WITH (UPDLOCK, HOLDLOCK)
            WHERE usuario_id = @usuario_destino
        )
            THROW 50415, 'Usuario no encontrado.', 1;

        -- Delegar la inserción en sp_conceder_permiso (valida existencia de permiso y no duplicidad)
        EXEC dbo.sp_conceder_permiso
            @usuario_destino = @usuario_destino,
            @codigo = @codigo;

        INSERT INTO dbo.auditorias
        (
            usuario_id, entidad, registro_id, accion,
            valores_anteriores, valores_nuevos
        )
        VALUES
        (
            @actor_id,
            N'usuarios_permisos',
            CONCAT(CONVERT(NVARCHAR(30), @usuario_destino), N':', @codigo),
            'INSERT',
            NULL,
            @codigo
        );

        IF @started_tran = 1
            COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1
            ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0
            ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1
            ROLLBACK TRANSACTION sp_cnc_seg;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_revocar_permiso_seguro
    @usuario_destino INT,
    @codigo NVARCHAR(100)
AS
BEGIN
    SET NOCOUNT ON;

    -- ASVS 8.2.1, 8.3.1: protege autoprotección y último gestor antes de delegar en sp_revocar_permiso.
    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'USUARIOS_GESTIONAR_PERMISOS') = 0
        THROW 50410, 'Sin permiso para gestionar permisos.', 1;

    IF @usuario_destino IS NULL OR @usuario_destino < 1
        THROW 50415, 'Usuario no encontrado.', 1;

    SET @codigo = NULLIF(LTRIM(RTRIM(@codigo)), N'');
    IF @codigo IS NULL
        THROW 50408, 'El codigo de permiso no existe.', 1;

    IF @usuario_destino = @actor_id
       AND @codigo IN (N'USUARIOS_VER', N'USUARIOS_GESTIONAR_PERMISOS', N'USUARIOS_GESTIONAR_CUENTAS')
        THROW 50419, 'No puede revocar sus propios permisos administrativos críticos.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1
        BEGIN TRANSACTION;
    ELSE
        SAVE TRANSACTION sp_rev_seg;

    BEGIN TRY
        DECLARE @destino_activo BIT;
        SELECT @destino_activo = activo
        FROM dbo.usuarios WITH (UPDLOCK, HOLDLOCK)
        WHERE usuario_id = @usuario_destino;

        IF @destino_activo IS NULL
            THROW 50415, 'Usuario no encontrado.', 1;

        IF @destino_activo = 1
           AND @codigo IN (N'USUARIOS_VER', N'USUARIOS_GESTIONAR_PERMISOS', N'USUARIOS_GESTIONAR_CUENTAS')
        BEGIN
            IF NOT EXISTS (
                SELECT 1
                FROM dbo.usuarios_permisos AS up WITH (UPDLOCK, HOLDLOCK)
                JOIN dbo.permisos AS p ON p.permiso_id = up.permiso_id
                JOIN dbo.usuarios AS u WITH (UPDLOCK, HOLDLOCK) ON u.usuario_id = up.usuario_id
                WHERE p.codigo = @codigo
                  AND p.activo = 1
                  AND u.activo = 1
                  AND u.usuario_id <> @usuario_destino
            )
                THROW 50420, 'No se puede revocar el último acceso administrativo activo para este permiso.', 1;
        END

        -- Delegar la baja efectiva en el procedimiento preexistente
        EXEC dbo.sp_revocar_permiso
            @usuario_destino = @usuario_destino,
            @codigo = @codigo;

        INSERT INTO dbo.auditorias
        (
            usuario_id, entidad, registro_id, accion,
            valores_anteriores, valores_nuevos
        )
        VALUES
        (
            @actor_id,
            N'usuarios_permisos',
            CONCAT(CONVERT(NVARCHAR(30), @usuario_destino), N':', @codigo),
            'DELETE',
            @codigo,
            NULL
        );

        IF @started_tran = 1
            COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1
            ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0
            ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1
            ROLLBACK TRANSACTION sp_rev_seg;
        THROW;
    END CATCH;
END;
GO
