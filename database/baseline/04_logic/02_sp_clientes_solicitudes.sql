USE TransportesSegurosSA;
GO

CREATE OR ALTER PROCEDURE dbo.sp_crear_cliente_fisico
    @identificacion NVARCHAR(30),
    @nombre NVARCHAR(100),
    @primer_apellido NVARCHAR(100),
    @segundo_apellido NVARCHAR(100),
    @correo NVARCHAR(150),
    @telefono NVARCHAR(30),
    @cliente_id INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;


    DECLARE @usuario INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));

    IF dbo.fn_tiene_permiso(@usuario, N'CLIENTES_CREAR') = 0
        THROW 50201, 'No tiene permiso para crear clientes.', 1;

    BEGIN TRY
        BEGIN TRANSACTION;

        INSERT INTO dbo.clientes (identificacion, correo, telefono)
        VALUES (@identificacion, @correo, @telefono);

        SET @cliente_id = CONVERT(INT, SCOPE_IDENTITY());

        INSERT INTO dbo.clientes_fisicos
        (
            cliente_id, nombre, primer_apellido, segundo_apellido
        )
        VALUES
        (
            @cliente_id, @nombre, @primer_apellido, @segundo_apellido
        );

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_crear_cliente_juridico
    @identificacion NVARCHAR(30),
    @razon_social NVARCHAR(150),
    @nombre_comercial NVARCHAR(150),
    @representante_legal NVARCHAR(150),
    @correo NVARCHAR(150),
    @cliente_id INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;


    DECLARE @usuario INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));

    IF dbo.fn_tiene_permiso(@usuario, N'CLIENTES_CREAR') = 0
        THROW 50202, 'No tiene permiso para crear clientes.', 1;

    BEGIN TRY
        BEGIN TRANSACTION;

        INSERT INTO dbo.clientes (identificacion, correo)
        VALUES (@identificacion, @correo);

        SET @cliente_id = CONVERT(INT, SCOPE_IDENTITY());

        INSERT INTO dbo.clientes_juridicos
        (
            cliente_id, razon_social, nombre_comercial, representante_legal
        )
        VALUES
        (
            @cliente_id, @razon_social, @nombre_comercial, @representante_legal
        );

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_crear_solicitud
    @codigo NVARCHAR(30),
    @cliente_id INT,
    @origen_id INT,
    @destino_id INT,
    @destinatario_nombre NVARCHAR(150),
    @destinatario_apellido1 NVARCHAR(100),
    @destinatario_apellido2 NVARCHAR(100),
    @destinatario_identificacion NVARCHAR(30),
    @destinatario_telefono NVARCHAR(30),
    @fecha_entrega_requerida DATETIME2,
    @mercancias dbo.tipo_mercancias READONLY,
    @solicitud_id INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;


    DECLARE @usuario INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    DECLARE @prioridad VARCHAR(10) = dbo.fn_calcular_prioridad(@fecha_entrega_requerida);

    IF dbo.fn_tiene_permiso(@usuario, N'SOLICITUDES_CREAR') = 0
        THROW 50203, 'No tiene permiso para crear solicitudes.', 1;

    IF @origen_id = @destino_id
        THROW 50204, 'Origen y destino no pueden coincidir.', 1;

    IF @fecha_entrega_requerida <= SYSUTCDATETIME()
        THROW 50205, 'La entrega debe programarse a futuro.', 1;

    IF NOT EXISTS (SELECT 1 FROM @mercancias)
        THROW 50206, 'La solicitud debe tener mercancias.', 1;

    IF EXISTS (SELECT 1 FROM @mercancias WHERE peso_kg <= 0 OR volumen_m3 <= 0)
        THROW 50207, 'Peso y volumen deben ser positivos.', 1;

    IF NOT EXISTS (SELECT 1 FROM dbo.clientes WHERE cliente_id = @cliente_id AND activo = 1)
        THROW 50208, 'El cliente debe estar activo.', 1;

    BEGIN TRY
        BEGIN TRANSACTION;

        INSERT INTO dbo.solicitudes
        (
            codigo_solicitud, cliente_id, origen_id, destino_id,
            destinatario_nombre, destinatario_apellido1, destinatario_apellido2,
            destinatario_identificacion, destinatario_telefono,
            fecha_entrega_requerida, prioridad_calculada, prioridad_actual
        )
        VALUES
        (
            @codigo, @cliente_id, @origen_id, @destino_id,
            @destinatario_nombre, @destinatario_apellido1, @destinatario_apellido2,
            @destinatario_identificacion, @destinatario_telefono,
            @fecha_entrega_requerida, @prioridad, @prioridad
        );

        SET @solicitud_id = CONVERT(INT, SCOPE_IDENTITY());

        INSERT INTO dbo.mercancias
        (
            solicitud_id, tipo_mercancia_id, descripcion, peso_kg, volumen_m3
        )
        SELECT
            @solicitud_id, tipo_mercancia_id, descripcion, peso_kg, volumen_m3
        FROM @mercancias;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_ajustar_prioridad
    @solicitud_id INT,
    @prioridad VARCHAR(10),
    @justificacion NVARCHAR(500)
AS
BEGIN
    SET NOCOUNT ON;


    DECLARE @usuario INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));

    IF dbo.fn_tiene_permiso(@usuario, N'SOLICITUDES_AJUSTAR_PRIORIDAD') = 0
        THROW 50209, 'Sin permiso para ajustar prioridad.', 1;

    IF @prioridad NOT IN ('NORMAL', 'ALTA', 'URGENTE')
        THROW 50210, 'Prioridad invalida.', 1;

    IF NULLIF(LTRIM(RTRIM(@justificacion)), N'') IS NULL
        THROW 50211, 'Debe justificar el cambio.', 1;

    UPDATE dbo.solicitudes
    SET prioridad_actual = @prioridad,
        justificacion_prioridad = @justificacion,
        fecha_actualizacion = SYSUTCDATETIME()
    WHERE solicitud_id = @solicitud_id
      AND estado IN ('REGISTRADA', 'PENDIENTE_ASIGNACION');

    IF @@ROWCOUNT <> 1
        THROW 50212, 'La solicitud no existe o no admite cambios.', 1;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_cancelar_solicitud
    @solicitud_id INT,
    @motivo NVARCHAR(500)
AS
BEGIN
    SET NOCOUNT ON;


    DECLARE @usuario INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));

    IF dbo.fn_tiene_permiso(@usuario, N'SOLICITUDES_CANCELAR') = 0
        THROW 50213, 'No tiene permiso para cancelar.', 1;

    IF NULLIF(LTRIM(RTRIM(@motivo)), N'') IS NULL
        THROW 50214, 'Debe indicar un motivo.', 1;

    UPDATE dbo.solicitudes
    SET estado = 'CANCELADA',
        observaciones = @motivo,
        fecha_actualizacion = SYSUTCDATETIME()
    WHERE solicitud_id = @solicitud_id
      AND estado IN ('REGISTRADA', 'PENDIENTE_ASIGNACION')
      AND NOT EXISTS
      (
          SELECT 1 FROM dbo.viaje_solicitudes
          WHERE solicitud_id = @solicitud_id
      );

    IF @@ROWCOUNT <> 1
        THROW 50215, 'La solicitud ya fue asignada o no puede cancelarse.', 1;
END;
GO
