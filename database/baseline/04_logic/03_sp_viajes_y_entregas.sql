USE TransportesSegurosSA;
GO

CREATE OR ALTER PROCEDURE dbo.sp_crear_viaje
    @codigo NVARCHAR(30),
    @vehiculo_id INT,
    @conductor_id INT,
    @ruta_id INT,
    @fecha_programada DATETIME2,
    @viaje_id INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;


    DECLARE @usuario INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));

    IF dbo.fn_tiene_permiso(@usuario, N'VIAJES_CREAR') = 0
        THROW 50301, 'No tiene permiso para crear viajes.', 1;

    BEGIN TRY
        BEGIN TRANSACTION;

        IF NOT EXISTS
        (
            SELECT 1 FROM dbo.vehiculos WITH (UPDLOCK, HOLDLOCK)
            WHERE vehiculo_id = @vehiculo_id AND activo = 1
              AND estado = 'DISPONIBLE'
        )
            THROW 50302, 'Vehiculo no disponible.', 1;

        IF NOT EXISTS
        (
            SELECT 1 FROM dbo.conductores WITH (UPDLOCK, HOLDLOCK)
            WHERE conductor_id = @conductor_id AND activo = 1
              AND estado_operativo = 'DISPONIBLE'
              AND fecha_vencimiento_licencia >= CONVERT(DATE, @fecha_programada)
        )
            THROW 50303, 'Conductor no disponible o licencia vencida.', 1;

        IF NOT EXISTS
        (
            SELECT 1 FROM dbo.rutas
            WHERE ruta_id = @ruta_id AND activo = 1
        )
            THROW 50304, 'La ruta no esta activa.', 1;

        IF @fecha_programada < DATEADD(HOUR, -1, SYSUTCDATETIME())
            THROW 50305, 'Fecha programada demasiado antigua.', 1;

        INSERT INTO dbo.viajes
        (
            codigo_viaje, vehiculo_id, conductor_id, ruta_id,
            fecha_programada_salida
        )
        VALUES
        (
            @codigo, @vehiculo_id, @conductor_id, @ruta_id,
            @fecha_programada
        );

        SET @viaje_id = CONVERT(INT, SCOPE_IDENTITY());

        UPDATE dbo.vehiculos SET estado = 'ASIGNADO'
        WHERE vehiculo_id = @vehiculo_id;

        UPDATE dbo.conductores SET estado_operativo = 'ASIGNADO'
        WHERE conductor_id = @conductor_id;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_agregar_parada
    @viaje_id INT,
    @ubicacion_id INT,
    @orden INT,
    @tipo_parada VARCHAR(20),
    @parada_id INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;


    DECLARE @usuario INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));

    IF dbo.fn_tiene_permiso(@usuario, N'VIAJES_PARADAS') = 0
        THROW 50306, 'No tiene permiso para registrar paradas.', 1;

    IF NOT EXISTS
    (
        SELECT 1 FROM dbo.viajes
        WHERE viaje_id = @viaje_id AND estado = 'PLANIFICADO'
    )
        THROW 50307, 'El viaje no esta en planificacion.', 1;

    INSERT INTO dbo.paradas_viaje
    (
        viaje_id, ubicacion_id, orden, tipo_parada
    )
    VALUES
    (
        @viaje_id, @ubicacion_id, @orden, @tipo_parada
    );

    SET @parada_id = CONVERT(INT, SCOPE_IDENTITY());
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_asignar_solicitud_viaje
    @viaje_id INT,
    @solicitud_id INT,
    @parada_recogida_id INT,
    @parada_entrega_id INT,
    @viaje_solicitud_id INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;


    DECLARE @usuario INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    DECLARE @vehiculo INT;
    DECLARE @peso DECIMAL(18,2);
    DECLARE @volumen DECIMAL(18,3);
    DECLARE @max_peso DECIMAL(18,2);
    DECLARE @max_volumen DECIMAL(18,3);

    IF dbo.fn_tiene_permiso(@usuario, N'VIAJES_ASIGNAR') = 0
        THROW 50308, 'No tiene permiso para asignar.', 1;

    BEGIN TRY
        BEGIN TRANSACTION;

        SELECT @vehiculo = vehiculo_id
        FROM dbo.viajes WITH (UPDLOCK, HOLDLOCK)
        WHERE viaje_id = @viaje_id AND estado = 'PLANIFICADO';

        IF @vehiculo IS NULL
            THROW 50309, 'El viaje no existe o ya inicio.', 1;

        IF NOT EXISTS
        (
            SELECT 1 FROM dbo.solicitudes
            WHERE solicitud_id = @solicitud_id
              AND estado IN ('REGISTRADA', 'PENDIENTE_ASIGNACION')
        )
            THROW 50310, 'Solicitud no disponible para asignacion.', 1;

        IF EXISTS
        (
            SELECT 1 FROM dbo.viaje_solicitudes
            WHERE solicitud_id = @solicitud_id
        )
            THROW 50311, 'Solicitud ya asignada a otro viaje.', 1;

        -- Verifica que las paradas pertenezcan al viaje,
        -- que coincidan con origen/destino y sigan orden correcto.
        IF NOT EXISTS
        (
            SELECT 1
            FROM dbo.solicitudes s
            JOIN dbo.paradas_viaje p1
              ON p1.parada_id = @parada_recogida_id
             AND p1.viaje_id = @viaje_id
             AND p1.ubicacion_id = s.origen_id
            JOIN dbo.paradas_viaje p2
              ON p2.parada_id = @parada_entrega_id
             AND p2.viaje_id = @viaje_id
             AND p2.ubicacion_id = s.destino_id
            WHERE s.solicitud_id = @solicitud_id
              AND p1.orden < p2.orden
              AND p1.tipo_parada IN ('RECOGIDA','MIXTA')
              AND p2.tipo_parada IN ('ENTREGA','MIXTA')
        )
            THROW 50312, 'Las paradas no corresponden a la solicitud.', 1;

        -- Peso TOTAL del viaje + solicitud nueva.
        SELECT @peso = ISNULL(SUM(m.peso_kg), 0),
               @volumen = ISNULL(SUM(ISNULL(m.volumen_m3, 0)), 0)
        FROM dbo.viaje_solicitudes vs
        JOIN dbo.mercancias m ON m.solicitud_id = vs.solicitud_id
        WHERE vs.viaje_id = @viaje_id;

        SELECT @peso = @peso + ISNULL(SUM(peso_kg), 0),
               @volumen = @volumen + ISNULL(SUM(ISNULL(volumen_m3, 0)), 0)
        FROM dbo.mercancias
        WHERE solicitud_id = @solicitud_id;

        SELECT @max_peso = capacidad_carga_kg,
               @max_volumen = capacidad_volumen_m3
        FROM dbo.vehiculos WHERE vehiculo_id = @vehiculo;

        IF @peso > @max_peso
            THROW 50313, 'Capacidad de peso insuficiente.', 1;

        IF @max_volumen IS NOT NULL AND @volumen > @max_volumen
            THROW 50314, 'Capacidad de volumen insuficiente.', 1;

        IF NOT EXISTS (SELECT 1 FROM dbo.mercancias WHERE solicitud_id = @solicitud_id)
            THROW 50315, 'Solicitud sin mercancias.', 1;

        INSERT INTO dbo.viaje_solicitudes
        (
            viaje_id, solicitud_id, parada_recogida_id, parada_entrega_id
        )
        VALUES
        (
            @viaje_id, @solicitud_id, @parada_recogida_id, @parada_entrega_id
        );

        SET @viaje_solicitud_id = CONVERT(INT, SCOPE_IDENTITY());

        UPDATE dbo.solicitudes
        SET estado = 'ASIGNADA', fecha_actualizacion = SYSUTCDATETIME()
        WHERE solicitud_id = @solicitud_id;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_iniciar_viaje
    @viaje_id INT
AS
BEGIN
    SET NOCOUNT ON;


    DECLARE @usuario INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));

    IF dbo.fn_tiene_permiso(@usuario, N'VIAJES_INICIAR') = 0
        THROW 50316, 'No tiene permiso para iniciar viajes.', 1;

    BEGIN TRY
        BEGIN TRANSACTION;

        IF NOT EXISTS
        (
            SELECT 1 FROM dbo.viajes WITH (UPDLOCK, HOLDLOCK)
            WHERE viaje_id = @viaje_id AND estado = 'PLANIFICADO'
        )
            THROW 50317, 'El viaje no esta planificado.', 1;

        IF NOT EXISTS
        (
            SELECT 1 FROM dbo.viaje_solicitudes WHERE viaje_id = @viaje_id
        )
            THROW 50318, 'No se puede iniciar un viaje vacio.', 1;

        UPDATE dbo.viajes
        SET estado = 'EN_CURSO', fecha_salida_real = SYSUTCDATETIME()
        WHERE viaje_id = @viaje_id;

        -- Al salir se considera realizada la recogida.
        UPDATE dbo.paradas_viaje
        SET estado = 'COMPLETADA'
        WHERE viaje_id = @viaje_id
          AND tipo_parada = 'RECOGIDA';

        UPDATE dbo.viaje_solicitudes
        SET estado = 'EN_TRANSITO'
        WHERE viaje_id = @viaje_id;

        UPDATE s
        SET s.estado = 'EN_TRANSITO', s.fecha_actualizacion = SYSUTCDATETIME()
        FROM dbo.solicitudes s
        JOIN dbo.viaje_solicitudes vs ON vs.solicitud_id = s.solicitud_id
        WHERE vs.viaje_id = @viaje_id;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_registrar_entrega
    @viaje_solicitud_id INT,
    @estado VARCHAR(15),
    @receptor_nombre NVARCHAR(150) = NULL,
    @receptor_identificacion NVARCHAR(30) = NULL,
    @receptor_telefono NVARCHAR(30) = NULL,
    @comprobante_url NVARCHAR(500) = NULL,
    @motivo_fallo NVARCHAR(500) = NULL
AS
BEGIN
    SET NOCOUNT ON;


    DECLARE @usuario INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    DECLARE @solicitud INT;

    IF dbo.fn_tiene_permiso(@usuario, N'ENTREGAS_REGISTRAR') = 0
        THROW 50319, 'No tiene permiso para registrar entregas.', 1;

    IF @estado NOT IN ('ENTREGADA','FALLIDA')
        THROW 50320, 'Estado de entrega invalido.', 1;

    IF @estado = 'ENTREGADA' AND
       (NULLIF(LTRIM(RTRIM(@receptor_nombre)), N'') IS NULL OR
        NULLIF(LTRIM(RTRIM(@comprobante_url)), N'') IS NULL)
        THROW 50321, 'La entrega exige receptor y comprobante.', 1;

    IF @estado = 'FALLIDA' AND NULLIF(LTRIM(RTRIM(@motivo_fallo)), N'') IS NULL
        THROW 50322, 'Debe justificar la entrega fallida.', 1;

    BEGIN TRY
        BEGIN TRANSACTION;

        SELECT @solicitud = vs.solicitud_id
        FROM dbo.viaje_solicitudes vs WITH (UPDLOCK, HOLDLOCK)
        JOIN dbo.viajes v ON v.viaje_id = vs.viaje_id
        WHERE vs.viaje_solicitud_id = @viaje_solicitud_id
          AND vs.estado = 'EN_TRANSITO'
          AND v.estado = 'EN_CURSO';

        IF @solicitud IS NULL
            THROW 50323, 'La solicitud no esta en un viaje en curso.', 1;

        IF EXISTS (SELECT 1 FROM dbo.entregas WHERE viaje_solicitud_id = @viaje_solicitud_id)
            THROW 50324, 'La solicitud ya tiene una entrega registrada.', 1;

        INSERT INTO dbo.entregas
        (
            viaje_solicitud_id, estado, receptor_nombre,
            receptor_identificacion, receptor_telefono, fecha_recepcion,
            comprobante_url, registrado_por_usuario_id, motivo_fallo
        )
        VALUES
        (
            @viaje_solicitud_id, @estado, @receptor_nombre,
            @receptor_identificacion, @receptor_telefono,
            CASE WHEN @estado = 'ENTREGADA' THEN SYSUTCDATETIME() ELSE NULL END,
            @comprobante_url, @usuario, @motivo_fallo
        );

        UPDATE dbo.viaje_solicitudes
        SET estado = @estado
        WHERE viaje_solicitud_id = @viaje_solicitud_id;

        UPDATE dbo.solicitudes
        SET estado = CASE WHEN @estado = 'ENTREGADA'
                          THEN 'ENTREGADA' ELSE 'ENTREGA_FALLIDA' END,
            fecha_actualizacion = SYSUTCDATETIME()
        WHERE solicitud_id = @solicitud;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_finalizar_viaje
    @viaje_id INT
AS
BEGIN
    SET NOCOUNT ON;


    DECLARE @usuario INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    DECLARE @vehiculo INT;
    DECLARE @conductor INT;

    IF dbo.fn_tiene_permiso(@usuario, N'VIAJES_FINALIZAR') = 0
        THROW 50325, 'No tiene permiso para finalizar viajes.', 1;

    BEGIN TRY
        BEGIN TRANSACTION;

        SELECT @vehiculo = vehiculo_id, @conductor = conductor_id
        FROM dbo.viajes WITH (UPDLOCK, HOLDLOCK)
        WHERE viaje_id = @viaje_id AND estado = 'EN_CURSO';

        IF @vehiculo IS NULL
            THROW 50326, 'El viaje no esta en curso.', 1;

        IF EXISTS
        (
            SELECT 1 FROM dbo.viaje_solicitudes
            WHERE viaje_id = @viaje_id
              AND estado NOT IN ('ENTREGADA', 'FALLIDA')
        )
            THROW 50327, 'Aun hay entregas pendientes.', 1;

        -- El viaje finaliza con sus paradas completadas.
        -- Las horas particulares de cada parada solo se guardan si se registraron.
        UPDATE dbo.paradas_viaje
        SET estado = 'COMPLETADA'
        WHERE viaje_id = @viaje_id
          AND estado = 'PENDIENTE';

        UPDATE dbo.viajes
        SET estado = 'FINALIZADO', fecha_llegada_real = SYSUTCDATETIME()
        WHERE viaje_id = @viaje_id;

        UPDATE dbo.vehiculos SET estado = 'DISPONIBLE'
        WHERE vehiculo_id = @vehiculo;

        UPDATE dbo.conductores SET estado_operativo = 'DISPONIBLE'
        WHERE conductor_id = @conductor;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO
