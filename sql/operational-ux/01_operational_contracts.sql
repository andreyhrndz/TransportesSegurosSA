/*
    Transportes Seguros S.A.
    Contratos operativos: autogeneración de códigos de solicitud y viaje, y cancelación segura de viajes.
    Ambiente exclusivo: TransportesSegurosSA_Test
*/

IF DB_NAME() <> N'TransportesSegurosSA_Test'
BEGIN
    RAISERROR('Este script solo debe ejecutarse en TransportesSegurosSA_Test', 20, 1) WITH LOG;
    SET NOEXEC ON;
END
GO

-- 1. Secuencias para autogeneración de códigos
IF NOT EXISTS (SELECT 1 FROM sys.sequences WHERE name = 'seq_codigo_solicitud')
BEGIN
    CREATE SEQUENCE dbo.seq_codigo_solicitud AS INT START WITH 1 INCREMENT BY 1;
END
GO

IF NOT EXISTS (SELECT 1 FROM sys.sequences WHERE name = 'seq_codigo_viaje')
BEGIN
    CREATE SEQUENCE dbo.seq_codigo_viaje AS INT START WITH 1 INCREMENT BY 1;
END
GO

-- 2. Permiso para cancelar viajes
IF NOT EXISTS (SELECT 1 FROM dbo.permisos WHERE codigo = 'VIAJES_CANCELAR')
BEGIN
    INSERT INTO dbo.permisos (codigo, nombre, descripcion, activo)
    VALUES ('VIAJES_CANCELAR', 'Cancelar viajes', 'Cancelar viajes planificados antes de su inicio y liberar recursos asignados', 1);
END
GO

-- Asignar permiso a usuarios con facultades operativas o de administración
DECLARE @permiso_cancelar_id INT = (SELECT permiso_id FROM dbo.permisos WHERE codigo = 'VIAJES_CANCELAR');
INSERT INTO dbo.usuarios_permisos (usuario_id, permiso_id)
SELECT u.usuario_id, @permiso_cancelar_id
FROM dbo.usuarios u
WHERE NOT EXISTS (
    SELECT 1 FROM dbo.usuarios_permisos up
    WHERE up.usuario_id = u.usuario_id AND up.permiso_id = @permiso_cancelar_id
) AND (
    EXISTS (
        SELECT 1 FROM dbo.usuarios_permisos up2
        JOIN dbo.permisos p2 ON p2.permiso_id = up2.permiso_id
        WHERE up2.usuario_id = u.usuario_id AND p2.codigo IN ('VIAJES_CREAR', 'USUARIOS_GESTIONAR_PERMISOS')
    )
    OR u.nombre_usuario = 'admin_proyecto'
);
GO

-- 3. Procedimiento sp_crear_solicitud con autogeneración de código
CREATE OR ALTER PROCEDURE dbo.sp_crear_solicitud
    @codigo NVARCHAR(30) = NULL,
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

        -- Autogenerar código secuencial si no se suministra
        IF @codigo IS NULL OR LTRIM(RTRIM(@codigo)) = N''
        BEGIN
            DECLARE @seq_sol INT = NEXT VALUE FOR dbo.seq_codigo_solicitud;
            SET @codigo = N'SOL-' + RIGHT(N'00000' + CAST(@seq_sol AS NVARCHAR(10)), 5);
        END

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

-- 4. Procedimiento sp_crear_viaje con autogeneración de código
CREATE OR ALTER PROCEDURE dbo.sp_crear_viaje
    @codigo NVARCHAR(30) = NULL,
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

        -- Autogenerar código secuencial si no se suministra
        IF @codigo IS NULL OR LTRIM(RTRIM(@codigo)) = N''
        BEGIN
            DECLARE @seq_via INT = NEXT VALUE FOR dbo.seq_codigo_viaje;
            SET @codigo = N'VIA-' + RIGHT(N'00000' + CAST(@seq_via AS NVARCHAR(10)), 5);
        END

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

-- 5. Procedimiento sp_cancelar_viaje
CREATE OR ALTER PROCEDURE dbo.sp_cancelar_viaje
    @viaje_id INT,
    @motivo NVARCHAR(500) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @usuario INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@usuario, N'VIAJES_CANCELAR') = 0
        THROW 50328, 'No tiene permiso para cancelar viajes.', 1;

    DECLARE @estado VARCHAR(20);
    DECLARE @vehiculo_id INT;
    DECLARE @conductor_id INT;

    BEGIN TRY
        BEGIN TRANSACTION;

        SELECT @estado = estado, @vehiculo_id = vehiculo_id, @conductor_id = conductor_id
        FROM dbo.viajes WITH (UPDLOCK, HOLDLOCK)
        WHERE viaje_id = @viaje_id;

        IF @estado IS NULL
            THROW 50329, 'El viaje especificado no existe.', 1;

        IF @estado <> 'PLANIFICADO'
            THROW 50330, 'Solo se pueden cancelar viajes en estado PLANIFICADO.', 1;

        -- 1. Actualizar estado del viaje
        UPDATE dbo.viajes
        SET estado = 'CANCELADO',
            observaciones = CASE 
                WHEN @motivo IS NOT NULL AND LTRIM(RTRIM(@motivo)) <> N''
                THEN ISNULL(observaciones + N' | ', N'') + N'Cancelado: ' + LTRIM(RTRIM(@motivo))
                ELSE ISNULL(observaciones + N' | ', N'') + N'Viaje cancelado antes de iniciar.'
            END
        WHERE viaje_id = @viaje_id;

        -- 2. Devolver solicitudes asignadas a PENDIENTE_ASIGNACION
        UPDATE s
        SET s.estado = 'PENDIENTE_ASIGNACION',
            s.fecha_actualizacion = SYSUTCDATETIME()
        FROM dbo.solicitudes s
        JOIN dbo.viaje_solicitudes vs ON vs.solicitud_id = s.solicitud_id
        WHERE vs.viaje_id = @viaje_id;

        -- 3. Marcar las asignaciones de viaje como RETIRADA
        UPDATE dbo.viaje_solicitudes
        SET estado = 'RETIRADA'
        WHERE viaje_id = @viaje_id;

        -- 4. Omitir paradas pendientes
        UPDATE dbo.paradas_viaje
        SET estado = 'OMITIDA'
        WHERE viaje_id = @viaje_id AND estado = 'PENDIENTE';

        -- 5. Liberar vehículo y conductor
        UPDATE dbo.vehiculos
        SET estado = 'DISPONIBLE'
        WHERE vehiculo_id = @vehiculo_id;

        UPDATE dbo.conductores
        SET estado_operativo = 'DISPONIBLE'
        WHERE conductor_id = @conductor_id;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO
