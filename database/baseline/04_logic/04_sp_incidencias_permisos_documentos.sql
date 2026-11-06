USE TransportesSegurosSA;
GO

CREATE OR ALTER PROCEDURE dbo.sp_registrar_incidencia
    @solicitud_id INT,
    @tipo_incidencia_id INT,
    @descripcion NVARCHAR(500),
    @incidencia_id INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;


    DECLARE @usuario INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));

    IF dbo.fn_tiene_permiso(@usuario, N'INCIDENCIAS_REGISTRAR') = 0
        THROW 50401, 'Sin permiso para incidencias.', 1;

    IF NULLIF(LTRIM(RTRIM(@descripcion)), N'') IS NULL
        THROW 50402, 'La descripcion es obligatoria.', 1;

    INSERT INTO dbo.incidencias
    (
        solicitud_id, tipo_incidencia_id, responsable_usuario_id, descripcion
    )
    VALUES
    (
        @solicitud_id, @tipo_incidencia_id, @usuario, @descripcion
    );

    SET @incidencia_id = CONVERT(INT, SCOPE_IDENTITY());
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_resolver_incidencia
    @incidencia_id INT,
    @resolucion NVARCHAR(500)
AS
BEGIN
    SET NOCOUNT ON;


    DECLARE @usuario INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));

    IF dbo.fn_tiene_permiso(@usuario, N'INCIDENCIAS_RESOLVER') = 0
        THROW 50403, 'Sin permiso para resolver incidencias.', 1;

    IF NULLIF(LTRIM(RTRIM(@resolucion)), N'') IS NULL
        THROW 50404, 'Debe indicar la resolucion.', 1;

    UPDATE dbo.incidencias
    SET estado = 'RESUELTA',
        fecha_resolucion = SYSUTCDATETIME(),
        resolucion = @resolucion
    WHERE incidencia_id = @incidencia_id AND estado <> 'RESUELTA';

    IF @@ROWCOUNT <> 1
        THROW 50405, 'Incidencia inexistente o ya resuelta.', 1;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_registrar_documento
    @solicitud_id INT,
    @tipo_documento_id INT,
    @numero_referencia NVARCHAR(100),
    @archivo_url NVARCHAR(500),
    @documento_id INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;


    DECLARE @usuario INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));

    IF dbo.fn_tiene_permiso(@usuario, N'DOCUMENTOS_REGISTRAR') = 0
        THROW 50406, 'Sin permiso para registrar documentos.', 1;

    INSERT INTO dbo.documentos
    (
        solicitud_id, tipo_documento_id, numero_referencia,
        fecha_documento, archivo_url
    )
    VALUES
    (
        @solicitud_id, @tipo_documento_id, @numero_referencia,
        CONVERT(DATE, SYSUTCDATETIME()), @archivo_url
    );

    SET @documento_id = CONVERT(INT, SCOPE_IDENTITY());
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_conceder_permiso
    @usuario_destino INT,
    @codigo NVARCHAR(100)
AS
BEGIN
    SET NOCOUNT ON;


    DECLARE @actor INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    DECLARE @permiso INT;

    IF dbo.fn_tiene_permiso(@actor, N'USUARIOS_GESTIONAR_PERMISOS') = 0
        THROW 50407, 'Sin permiso para gestionar permisos.', 1;

    SELECT @permiso = permiso_id FROM dbo.permisos
    WHERE codigo = @codigo AND activo = 1;

    IF @permiso IS NULL
        THROW 50408, 'El codigo de permiso no existe.', 1;

    IF EXISTS
    (
        SELECT 1 FROM dbo.usuarios_permisos
        WHERE usuario_id = @usuario_destino AND permiso_id = @permiso
    )
        THROW 50409, 'El usuario ya tiene ese permiso.', 1;

    INSERT INTO dbo.usuarios_permisos (usuario_id, permiso_id)
    VALUES (@usuario_destino, @permiso);
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_revocar_permiso
    @usuario_destino INT,
    @codigo NVARCHAR(100)
AS
BEGIN
    SET NOCOUNT ON;


    DECLARE @actor INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));

    IF dbo.fn_tiene_permiso(@actor, N'USUARIOS_GESTIONAR_PERMISOS') = 0
        THROW 50410, 'Sin permiso para gestionar permisos.', 1;

    DELETE up
    FROM dbo.usuarios_permisos up
    JOIN dbo.permisos p ON p.permiso_id = up.permiso_id
    WHERE up.usuario_id = @usuario_destino
      AND p.codigo = @codigo;

    IF @@ROWCOUNT <> 1
        THROW 50411, 'Permiso no asignado.', 1;
END;
GO
