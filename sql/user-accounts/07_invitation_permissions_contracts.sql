/*
    Transportes Seguros S.A.
    Contratos SQL para permisos preseleccionados en invitaciones de usuario.
    Ambiente exclusivo: TransportesSegurosSA_Test
*/

IF DB_NAME() <> N'TransportesSegurosSA_Test'
BEGIN
    RAISERROR('Este script solo debe ejecutarse en TransportesSegurosSA_Test', 20, 1) WITH LOG;
    SET NOEXEC ON;
END
GO

-- 1. Tabla de permisos asociados a invitaciones de usuario
IF OBJECT_ID('dbo.invitaciones_permisos', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.invitaciones_permisos (
        invitacion_id INT NOT NULL,
        permiso_id INT NOT NULL,
        creado_en DATETIME2 NOT NULL DEFAULT SYSUTCDATETIME(),
        CONSTRAINT PK_invitaciones_permisos PRIMARY KEY (invitacion_id, permiso_id),
        CONSTRAINT FK_invitaciones_permisos_invitacion FOREIGN KEY (invitacion_id) REFERENCES dbo.invitaciones_usuario(invitacion_id) ON DELETE CASCADE,
        CONSTRAINT FK_invitaciones_permisos_permiso FOREIGN KEY (permiso_id) REFERENCES dbo.permisos(permiso_id)
    );

    CREATE INDEX IX_invitaciones_permisos_invitacion ON dbo.invitaciones_permisos(invitacion_id);
END
GO

-- 2. Procedimiento para asignar un permiso a una invitación pendiente
CREATE OR ALTER PROCEDURE dbo.sp_asignar_permiso_invitacion
    @invitacion_id INT,
    @codigo NVARCHAR(100)
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'USUARIOS_GESTIONAR_PERMISOS') = 0
        THROW 50407, 'Sin permiso para gestionar permisos.', 1;

    IF @invitacion_id IS NULL OR @invitacion_id < 1
        THROW 50436, 'La invitación no existe.', 1;

    SET @codigo = NULLIF(LTRIM(RTRIM(@codigo)), N'');
    IF @codigo IS NULL
        THROW 50408, 'El código de permiso no es válido.', 1;

    -- Validar que el actor posea el permiso que intenta conceder (prevención de elevación de privilegios)
    IF dbo.fn_tiene_permiso(@actor_id, @codigo) = 0
        THROW 50445, 'No puedes otorgar un permiso que tu propia cuenta no posee.', 1;

    DECLARE @permiso_id INT;
    SELECT @permiso_id = permiso_id
    FROM dbo.permisos
    WHERE codigo = @codigo AND activo = 1;

    IF @permiso_id IS NULL
        THROW 50408, 'El código de permiso no existe o está inactivo.', 1;

    -- Validar que la invitación exista y esté PENDIENTE
    IF NOT EXISTS (
        SELECT 1 FROM dbo.invitaciones_usuario WITH (UPDLOCK, HOLDLOCK)
        WHERE invitacion_id = @invitacion_id AND estado = 'PENDIENTE' AND fecha_expiracion > SYSUTCDATETIME()
    )
        THROW 50432, 'La invitación no existe, ya fue completada, revocada o ha expirado.', 1;

    -- Inserción idempotente
    IF NOT EXISTS (
        SELECT 1 FROM dbo.invitaciones_permisos
        WHERE invitacion_id = @invitacion_id AND permiso_id = @permiso_id
    )
    BEGIN
        INSERT INTO dbo.invitaciones_permisos (invitacion_id, permiso_id, creado_en)
        VALUES (@invitacion_id, @permiso_id, SYSUTCDATETIME());

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_nuevos)
        VALUES (@actor_id, N'invitaciones_permisos', CONCAT(CONVERT(NVARCHAR(30), @invitacion_id), N':', @codigo), 'INSERT', @codigo);
    END;
END;
GO

-- 3. Actualizar dbo.sp_completar_invitacion_usuario para aplicar los permisos autorizados
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

        -- Crear usuario en dbo.usuarios (activo, con contraseña propia)
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

        -- Aplicar permisos preseleccionados en la invitación (si existen y siguen activos en el catálogo)
        INSERT INTO dbo.usuarios_permisos (usuario_id, permiso_id, fecha_asignacion)
        SELECT @nuevo_usuario_id, ip.permiso_id, SYSUTCDATETIME()
        FROM dbo.invitaciones_permisos ip
        JOIN dbo.permisos p ON p.permiso_id = ip.permiso_id
        WHERE ip.invitacion_id = @invitacion_id
          AND p.activo = 1;

        -- Registrar auditoría por cada permiso concedido automáticamente al usuario
        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_nuevos)
        SELECT @nuevo_usuario_id, N'usuarios_permisos', CONCAT(CONVERT(NVARCHAR(30), @nuevo_usuario_id), N':', p.codigo), 'INSERT', p.codigo
        FROM dbo.invitaciones_permisos ip
        JOIN dbo.permisos p ON p.permiso_id = ip.permiso_id
        WHERE ip.invitacion_id = @invitacion_id
          AND p.activo = 1;

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

-- 4. Actualizar dbo.sp_reenviar_invitacion_usuario para copiar permisos planificados
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

        -- Copiar los permisos planificados de la invitación previa a la nueva
        INSERT INTO dbo.invitaciones_permisos (invitacion_id, permiso_id, creado_en)
        SELECT @nueva_invitacion_id, ip.permiso_id, SYSUTCDATETIME()
        FROM dbo.invitaciones_permisos ip
        JOIN dbo.permisos p ON p.permiso_id = ip.permiso_id
        WHERE ip.invitacion_id = @invitacion_id
          AND p.activo = 1;

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

-- 5. Actualizar dbo.sp_listar_invitaciones para incluir información de permisos asignados
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
        u_comp.nombre_usuario AS completado_por_nombre,
        ISNULL(perm_agg.total_permisos, 0) AS total_permisos,
        perm_agg.permisos_codigos
    FROM dbo.invitaciones_usuario i
    JOIN dbo.usuarios u_crea ON u_crea.usuario_id = i.creado_por_usuario_id
    LEFT JOIN dbo.usuarios u_comp ON u_comp.usuario_id = i.completado_usuario_id
    OUTER APPLY (
        SELECT
            COUNT(*) AS total_permisos,
            STRING_AGG(p.codigo, ', ') WITHIN GROUP (ORDER BY p.codigo) AS permisos_codigos
        FROM dbo.invitaciones_permisos ip
        JOIN dbo.permisos p ON p.permiso_id = ip.permiso_id
        WHERE ip.invitacion_id = i.invitacion_id
    ) perm_agg
    WHERE (@estado IS NULL OR i.estado = @estado)
    ORDER BY i.fecha_creacion DESC;
END;
GO
