/* =========================================================================
   Transportes Seguros S.A. — SQL Server 2025
   Rollback seguro de la Migración 11
   Restaura la versión de Migración 10 de sp_reenviar_invitacion_usuario,
   sp_obtener_solicitud y sp_obtener_viaje, y elimina sp_listar_invitaciones_paginadas.
   No elimina datos ni tablas.
   ========================================================================= */

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET NOCOUNT ON;
GO

IF DB_NAME() NOT IN (N'TransportesSegurosSA', N'TransportesSegurosSA_Test', N'TransportesSegurosSA_Staging')
    THROW 50920, 'Este rollback solo puede ejecutarse en una base autorizada de TransportesSegurosSA.', 1;
GO

DROP PROCEDURE IF EXISTS dbo.sp_listar_invitaciones_paginadas;
GO

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

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_reenv_inv_save;

    BEGIN TRY
        DECLARE @correo NVARCHAR(150);
        DECLARE @estado VARCHAR(20);
        DECLARE @expiracion DATETIME2;
        DECLARE @ultimo_envio DATETIME2;
        DECLARE @envios INT;

        SELECT
            @correo = correo_destino,
            @estado = estado,
            @expiracion = fecha_expiracion,
            @ultimo_envio = ultimo_envio_en,
            @envios = envios_contador
        FROM dbo.invitaciones_usuario WITH (UPDLOCK, HOLDLOCK)
        WHERE invitacion_id = @invitacion_id;

        IF @correo IS NULL
            THROW 50437, 'La invitación original no fue encontrada.', 1;

        IF @estado = 'COMPLETADA'
            THROW 50441, 'Una invitación completada no puede ser reenviada.', 1;

        IF EXISTS (
            SELECT 1 FROM dbo.usuarios WITH (UPDLOCK, HOLDLOCK)
            WHERE LOWER(correo_acceso) = LOWER(@correo) AND activo = 1
        )
            THROW 50430, 'El correo ya pertenece a una cuenta activa.', 1;

        DECLARE @envios_24h INT = 0;
        SELECT @envios_24h = COUNT(*)
        FROM dbo.invitaciones_usuario WITH (UPDLOCK, HOLDLOCK)
        WHERE LOWER(correo_destino) = LOWER(@correo)
          AND fecha_creacion >= DATEADD(HOUR, -24, SYSUTCDATETIME());

        IF @envios_24h >= 5
            THROW 50439, 'Se ha alcanzado el límite de 5 invitaciones para este correo en un período de 24 horas.', 1;

        IF @ultimo_envio IS NOT NULL AND DATEDIFF(SECOND, @ultimo_envio, SYSUTCDATETIME()) < 60
            THROW 50440, 'Debes esperar al menos 60 segundos antes de reenviar una invitación a este correo.', 1;

        IF @estado = 'PENDIENTE' AND @expiracion > SYSUTCDATETIME()
        BEGIN
            UPDATE dbo.invitaciones_usuario SET estado = 'REVOCADA' WHERE invitacion_id = @invitacion_id;
        END
        ELSE IF @estado IN ('PENDIENTE', 'EXPIRADA')
        BEGIN
            UPDATE dbo.invitaciones_usuario SET estado = 'CANCELADA' WHERE invitacion_id = @invitacion_id;
        END;

        INSERT INTO dbo.invitaciones_usuario
        (
            correo_destino, token_hash, estado, fecha_expiracion, creado_por_usuario_id,
            fecha_creacion, ultimo_envio_en, envios_contador, estado_envio
        )
        VALUES
        (
            @correo, @nuevo_token_hash, 'PENDIENTE',
            DATEADD(HOUR, ISNULL(@horas_vigencia, 24), SYSUTCDATETIME()),
            @actor_id, SYSUTCDATETIME(), SYSUTCDATETIME(), ISNULL(@envios, 0) + 1, 'PENDIENTE'
        );

        SET @nueva_invitacion_id = CONVERT(INT, SCOPE_IDENTITY());

        INSERT INTO dbo.invitaciones_permisos (invitacion_id, permiso_id, creado_en)
        SELECT @nueva_invitacion_id, ip.permiso_id, SYSUTCDATETIME()
        FROM dbo.invitaciones_permisos ip
        JOIN dbo.permisos p ON p.permiso_id = ip.permiso_id
        WHERE ip.invitacion_id = @invitacion_id AND p.activo = 1;

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (
            @actor_id, N'invitaciones_usuario', CONVERT(NVARCHAR(100), @nueva_invitacion_id), 'RESEND',
            CONCAT(N'Invitación previa ID ', @invitacion_id, N' (', @estado, N')'),
            CONCAT(N'Invitación reenviada a correo: ', @correo)
        );

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND @@TRANCOUNT > 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION sp_reenv_inv_save;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_obtener_solicitud
    @solicitud_id INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@usuario_id, N'SOLICITUDES_VER') = 0
        THROW 50103, N'No tiene permiso para consultar solicitudes.', 1;
    IF @solicitud_id IS NULL OR @solicitud_id < 1
        THROW 50102, N'Identificador de solicitud inválido.', 1;

    SELECT
        s.solicitud_id, s.codigo_solicitud, s.estado, s.prioridad_calculada, s.prioridad_actual,
        s.justificacion_prioridad, s.fecha_solicitud, s.fecha_entrega_requerida,
        s.destinatario_nombre, s.destinatario_apellido1, s.destinatario_apellido2,
        s.destinatario_identificacion, s.destinatario_telefono, s.instrucciones_entrega, s.observaciones,
        s.cliente_id, s.origen_id, origen.nombre AS origen_nombre, s.destino_id, destino.nombre AS destino_nombre,
        vs.viaje_solicitud_id, vs.viaje_id, v.codigo_viaje, vs.estado AS estado_asignacion
    FROM dbo.solicitudes AS s
    JOIN dbo.ubicaciones AS origen ON origen.ubicacion_id = s.origen_id
    JOIN dbo.ubicaciones AS destino ON destino.ubicacion_id = s.destino_id
    LEFT JOIN dbo.viaje_solicitudes AS vs ON vs.solicitud_id = s.solicitud_id
    LEFT JOIN dbo.viajes AS v ON v.viaje_id = vs.viaje_id
    WHERE s.solicitud_id = @solicitud_id;

    IF @@ROWCOUNT = 0 THROW 50101, N'Solicitud no encontrada.', 1;

    SELECT m.mercancia_id, m.tipo_mercancia_id, tm.nombre AS tipo_mercancia, m.descripcion, m.peso_kg, m.volumen_m3, m.observaciones
    FROM dbo.mercancias AS m
    JOIN dbo.tipos_mercancia AS tm ON tm.tipo_mercancia_id = m.tipo_mercancia_id
    WHERE m.solicitud_id = @solicitud_id ORDER BY m.mercancia_id;

    SELECT h.historial_estado_id, h.estado_anterior, h.estado_nuevo, h.fecha_cambio, h.observacion, h.usuario_id, u.nombre_usuario
    FROM dbo.historial_estados_solicitud AS h
    LEFT JOIN dbo.usuarios AS u ON u.usuario_id = h.usuario_id
    WHERE h.solicitud_id = @solicitud_id ORDER BY h.fecha_cambio, h.historial_estado_id;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_obtener_viaje
    @viaje_id INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@usuario_id, N'VIAJES_VER') = 0
        THROW 50103, N'No tiene permiso para consultar viajes.', 1;
    IF @viaje_id IS NULL OR @viaje_id < 1
        THROW 50102, N'Identificador de viaje inválido.', 1;

    SELECT v.viaje_id, v.codigo_viaje, v.estado, v.fecha_programada_salida, v.fecha_salida_real, v.fecha_llegada_real, v.observaciones,
           ve.vehiculo_id, ve.placa, c.conductor_id, CONCAT(c.nombre, N' ', c.primer_apellido) AS conductor_nombre, r.ruta_id, r.nombre AS ruta_nombre
    FROM dbo.viajes AS v
    JOIN dbo.vehiculos AS ve ON ve.vehiculo_id = v.vehiculo_id
    JOIN dbo.conductores AS c ON c.conductor_id = v.conductor_id
    JOIN dbo.rutas AS r ON r.ruta_id = v.ruta_id
    WHERE v.viaje_id = @viaje_id;

    IF @@ROWCOUNT = 0 THROW 50101, N'Viaje no encontrado.', 1;

    SELECT p.parada_id, p.orden, p.tipo_parada, p.estado, p.llegada_programada, p.llegada_real, p.salida_real, p.observaciones,
           u.ubicacion_id, u.nombre AS ubicacion_nombre, u.direccion
    FROM dbo.paradas_viaje AS p
    JOIN dbo.ubicaciones AS u ON u.ubicacion_id = p.ubicacion_id
    WHERE p.viaje_id = @viaje_id ORDER BY p.orden;

    SELECT vs.viaje_solicitud_id, vs.solicitud_id, s.codigo_solicitud, s.estado AS estado_solicitud, vs.estado AS estado_asignacion,
           vs.parada_recogida_id, vs.parada_entrega_id
    FROM dbo.viaje_solicitudes AS vs
    JOIN dbo.solicitudes AS s ON s.solicitud_id = vs.solicitud_id
    WHERE vs.viaje_id = @viaje_id ORDER BY vs.viaje_solicitud_id;
END;
GO
