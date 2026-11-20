/* =========================================================================
   Rollback NO DESTRUCTIVO de Migración 12: Ubicaciones híbridas en solicitudes
   =========================================================================
   Revierte únicamente los procedimientos almacenados a su versión previa
   (Migración 11). NO elimina la columna dbo.ubicaciones.es_reutilizable ni
   borra ubicaciones o solicitudes registradas, preservando integridad histórica.
   ========================================================================= */

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

DROP PROCEDURE IF EXISTS dbo.sp_crear_solicitud_con_ubicaciones;
GO

DROP PROCEDURE IF EXISTS dbo.sp_listar_ubicaciones_parada;
GO

CREATE OR ALTER PROCEDURE dbo.sp_listar_ubicaciones
    @buscar NVARCHAR(120) = NULL,
    @solo_activos BIT = 1,
    @pagina INT = 1,
    @por_pagina INT = 50,
    @total_registros INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    DECLARE @offset BIGINT;

    IF dbo.fn_tiene_permiso(@usuario_id, N'UBICACIONES_VER') = 0
        THROW 50103, N'No tiene permiso para consultar ubicaciones.', 1;
    IF @pagina < 1 OR @por_pagina NOT BETWEEN 1 AND 100
        THROW 50102, N'Paginación inválida.', 1;

    SET @buscar = NULLIF(LTRIM(RTRIM(@buscar)), N'');
    SET @offset = CONVERT(BIGINT, @pagina - 1) * @por_pagina;

    SELECT @total_registros = COUNT(*)
    FROM dbo.ubicaciones
    WHERE (@solo_activos IS NULL OR activo = @solo_activos)
      AND (@buscar IS NULL OR nombre LIKE N'%' + @buscar + N'%' OR direccion LIKE N'%' + @buscar + N'%');

    SELECT ubicacion_id, nombre, direccion, latitud, longitud, activo
    FROM dbo.ubicaciones
    WHERE (@solo_activos IS NULL OR activo = @solo_activos)
      AND (@buscar IS NULL OR nombre LIKE N'%' + @buscar + N'%' OR direccion LIKE N'%' + @buscar + N'%')
    ORDER BY nombre, ubicacion_id
    OFFSET @offset ROWS FETCH NEXT @por_pagina ROWS ONLY;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_crear_ubicacion
    @nombre NVARCHAR(150),
    @direccion NVARCHAR(250),
    @latitud DECIMAL(9,6) = NULL,
    @longitud DECIMAL(9,6) = NULL,
    @ubicacion_id INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'UBICACIONES_GESTIONAR') = 0
        THROW 50501, 'No tiene permiso para gestionar ubicaciones.', 1;

    SET @nombre = NULLIF(LTRIM(RTRIM(@nombre)), N'');
    SET @direccion = NULLIF(LTRIM(RTRIM(@direccion)), N'');

    IF @nombre IS NULL
       OR @direccion IS NULL
       OR (@latitud IS NULL AND @longitud IS NOT NULL)
       OR (@latitud IS NOT NULL AND @longitud IS NULL)
       OR (@latitud IS NOT NULL AND (@latitud < -90 OR @latitud > 90))
       OR (@longitud IS NOT NULL AND (@longitud < -180 OR @longitud > 180))
        THROW 50502, 'Datos o coordenadas de ubicación inválidos.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_crear_ubi;

    BEGIN TRY
        IF EXISTS (SELECT 1 FROM dbo.ubicaciones WITH (UPDLOCK, HOLDLOCK) WHERE nombre = @nombre)
            THROW 50504, 'Ya existe una ubicación registrada con ese nombre.', 1;

        INSERT INTO dbo.ubicaciones (nombre, direccion, latitud, longitud, activo)
        VALUES (@nombre, @direccion, @latitud, @longitud, 1);

        SET @ubicacion_id = CONVERT(INT, SCOPE_IDENTITY());

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (
            @actor_id, N'ubicaciones', CONVERT(NVARCHAR(100), @ubicacion_id), 'INSERT',
            NULL, CONCAT(N'Nombre: ', @nombre, N'; Activo: 1')
        );

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION sp_crear_ubi;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_actualizar_ubicacion
    @ubicacion_id INT,
    @nombre NVARCHAR(150),
    @direccion NVARCHAR(250),
    @latitud DECIMAL(9,6) = NULL,
    @longitud DECIMAL(9,6) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'UBICACIONES_GESTIONAR') = 0
        THROW 50501, 'No tiene permiso para gestionar ubicaciones.', 1;

    SET @nombre = NULLIF(LTRIM(RTRIM(@nombre)), N'');
    SET @direccion = NULLIF(LTRIM(RTRIM(@direccion)), N'');

    IF @ubicacion_id IS NULL OR @ubicacion_id < 1
       OR @nombre IS NULL
       OR @direccion IS NULL
       OR (@latitud IS NULL AND @longitud IS NOT NULL)
       OR (@latitud IS NOT NULL AND @longitud IS NULL)
       OR (@latitud IS NOT NULL AND (@latitud < -90 OR @latitud > 90))
       OR (@longitud IS NOT NULL AND (@longitud < -180 OR @longitud > 180))
        THROW 50502, 'Datos o coordenadas de ubicación inválidos.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_act_ubi;

    BEGIN TRY
        DECLARE @prev_nombre NVARCHAR(150), @activo BIT;
        SELECT @prev_nombre = nombre, @activo = activo
        FROM dbo.ubicaciones WITH (UPDLOCK, HOLDLOCK)
        WHERE ubicacion_id = @ubicacion_id;

        IF @activo IS NULL
            THROW 50503, 'Ubicación no encontrada.', 1;
        IF @activo = 0
            THROW 50506, 'No se puede editar una ubicación inactiva.', 1;

        IF EXISTS (
            SELECT 1
            FROM dbo.paradas_viaje AS p
            JOIN dbo.viajes AS v ON v.viaje_id = p.viaje_id
            WHERE p.ubicacion_id = @ubicacion_id AND v.estado = 'EN_CURSO'
        )
            THROW 50505, 'No se puede modificar una ubicación con viajes actualmente en curso.', 1;

        IF EXISTS (
            SELECT 1 FROM dbo.ubicaciones WITH (UPDLOCK, HOLDLOCK)
            WHERE nombre = @nombre AND ubicacion_id <> @ubicacion_id
        )
            THROW 50504, 'Ya existe otra ubicación con ese nombre.', 1;

        UPDATE dbo.ubicaciones
        SET nombre = @nombre,
            direccion = @direccion,
            latitud = @latitud,
            longitud = @longitud
        WHERE ubicacion_id = @ubicacion_id;

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (
            @actor_id, N'ubicaciones', CONVERT(NVARCHAR(100), @ubicacion_id), 'UPDATE',
            CONCAT(N'Nombre: ', @prev_nombre),
            CONCAT(N'Nombre: ', @nombre)
        );

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION sp_act_ubi;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_desactivar_ubicacion
    @ubicacion_id INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'UBICACIONES_GESTIONAR') = 0
        THROW 50501, 'No tiene permiso para gestionar ubicaciones.', 1;

    IF @ubicacion_id IS NULL OR @ubicacion_id < 1
        THROW 50502, 'Identificador de ubicación inválido.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_des_ubi;

    BEGIN TRY
        DECLARE @nombre NVARCHAR(150), @activo BIT;
        SELECT @nombre = nombre, @activo = activo
        FROM dbo.ubicaciones WITH (UPDLOCK, HOLDLOCK)
        WHERE ubicacion_id = @ubicacion_id;

        IF @activo IS NULL
            THROW 50503, 'Ubicación no encontrada.', 1;
        IF @activo = 0
            THROW 50506, 'La ubicación ya se encuentra inactiva.', 1;

        IF EXISTS (
            SELECT 1 FROM dbo.rutas
            WHERE (origen_id = @ubicacion_id OR destino_id = @ubicacion_id) AND activo = 1
        )
        OR EXISTS (
            SELECT 1 FROM dbo.solicitudes
            WHERE (origen_id = @ubicacion_id OR destino_id = @ubicacion_id)
              AND estado NOT IN ('ENTREGADA', 'ENTREGA_FALLIDA', 'CANCELADA')
        )
        OR EXISTS (
            SELECT 1
            FROM dbo.paradas_viaje AS p
            JOIN dbo.viajes AS v ON v.viaje_id = p.viaje_id
            WHERE p.ubicacion_id = @ubicacion_id AND v.estado IN ('PLANIFICADO', 'EN_CURSO')
        )
            THROW 50505, 'No se puede desactivar la ubicación porque participa en rutas activas, solicitudes abiertas o viajes activos.', 1;

        UPDATE dbo.ubicaciones
        SET activo = 0
        WHERE ubicacion_id = @ubicacion_id;

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (
            @actor_id, N'ubicaciones', CONVERT(NVARCHAR(100), @ubicacion_id), 'DEACTIVATE',
            CONCAT(N'Nombre: ', @nombre, N'; Activo: 1'),
            N'Activo: 0'
        );

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION sp_des_ubi;
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
        vs.viaje_solicitud_id, vs.viaje_id, v.codigo_viaje, vs.estado AS estado_asignacion,
        origen.direccion AS origen_direccion, origen.latitud AS origen_latitud, origen.longitud AS origen_longitud,
        destino.direccion AS destino_direccion, destino.latitud AS destino_latitud, destino.longitud AS destino_longitud
    FROM dbo.solicitudes AS s
    JOIN dbo.ubicaciones AS origen ON origen.ubicacion_id = s.origen_id
    JOIN dbo.ubicaciones AS destino ON destino.ubicacion_id = s.destino_id
    LEFT JOIN dbo.viaje_solicitudes AS vs ON vs.solicitud_id = s.solicitud_id
    LEFT JOIN dbo.viajes AS v ON v.viaje_id = vs.viaje_id
    WHERE s.solicitud_id = @solicitud_id;

    IF @@ROWCOUNT = 0
        THROW 50101, N'Solicitud no encontrada.', 1;

    SELECT m.mercancia_id, m.tipo_mercancia_id, tm.nombre AS tipo_mercancia, m.descripcion, m.peso_kg, m.volumen_m3, m.observaciones
    FROM dbo.mercancias AS m
    JOIN dbo.tipos_mercancia AS tm ON tm.tipo_mercancia_id = m.tipo_mercancia_id
    WHERE m.solicitud_id = @solicitud_id
    ORDER BY m.mercancia_id;

    SELECT h.historial_estado_id, h.estado_anterior, h.estado_nuevo, h.fecha_cambio, h.observacion,
           h.usuario_id, u.nombre_usuario
    FROM dbo.historial_estados_solicitud AS h
    LEFT JOIN dbo.usuarios AS u ON u.usuario_id = h.usuario_id
    WHERE h.solicitud_id = @solicitud_id
    ORDER BY h.fecha_cambio, h.historial_estado_id;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_listar_solicitudes
    @buscar NVARCHAR(120) = NULL,
    @estado VARCHAR(30) = NULL,
    @cliente_id INT = NULL,
    @desde DATETIME2 = NULL,
    @hasta DATETIME2 = NULL,
    @pagina INT = 1,
    @por_pagina INT = 20,
    @total_registros INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    DECLARE @offset BIGINT;

    IF dbo.fn_tiene_permiso(@usuario_id, N'SOLICITUDES_VER') = 0
        THROW 50103, N'No tiene permiso para consultar solicitudes.', 1;
    IF @pagina < 1 OR @por_pagina NOT BETWEEN 1 AND 100 OR (@desde IS NOT NULL AND @hasta IS NOT NULL AND @desde > @hasta)
        THROW 50102, N'Filtros o paginación inválidos.', 1;
    IF @estado IS NOT NULL AND @estado NOT IN ('REGISTRADA', 'PENDIENTE_ASIGNACION', 'ASIGNADA', 'EN_TRANSITO', 'ENTREGADA', 'ENTREGA_FALLIDA', 'CANCELADA')
        THROW 50102, N'Estado de solicitud inválido.', 1;

    SET @buscar = NULLIF(LTRIM(RTRIM(@buscar)), N'');
    SET @offset = CONVERT(BIGINT, @pagina - 1) * @por_pagina;

    SELECT @total_registros = COUNT(*)
    FROM dbo.solicitudes AS s
    JOIN dbo.clientes AS c ON c.cliente_id = s.cliente_id
    WHERE (@buscar IS NULL OR s.codigo_solicitud LIKE N'%' + @buscar + N'%' OR c.identificacion LIKE N'%' + @buscar + N'%'
           OR s.destinatario_nombre LIKE N'%' + @buscar + N'%' OR s.destinatario_apellido1 LIKE N'%' + @buscar + N'%')
      AND (@estado IS NULL OR s.estado = @estado)
      AND (@cliente_id IS NULL OR s.cliente_id = @cliente_id)
      AND (@desde IS NULL OR s.fecha_solicitud >= @desde)
      AND (@hasta IS NULL OR s.fecha_solicitud < DATEADD(DAY, 1, @hasta));

    SELECT
        s.solicitud_id, s.codigo_solicitud, s.estado, s.prioridad_calculada, s.prioridad_actual,
        s.fecha_solicitud, s.fecha_entrega_requerida, s.cliente_id, c.identificacion AS cliente_identificacion,
        CASE WHEN f.cliente_id IS NOT NULL THEN CONCAT(f.nombre, N' ', f.primer_apellido)
             ELSE COALESCE(j.nombre_comercial, j.razon_social) END AS cliente_nombre,
        s.destinatario_nombre, s.destinatario_apellido1, s.destinatario_apellido2,
        origen.nombre AS origen_nombre, destino.nombre AS destino_nombre,
        vs.viaje_id, v.codigo_viaje
    FROM dbo.solicitudes AS s
    JOIN dbo.clientes AS c ON c.cliente_id = s.cliente_id
    LEFT JOIN dbo.clientes_fisicos AS f ON f.cliente_id = c.cliente_id
    LEFT JOIN dbo.clientes_juridicos AS j ON j.cliente_id = c.cliente_id
    JOIN dbo.ubicaciones AS origen ON origen.ubicacion_id = s.origen_id
    JOIN dbo.ubicaciones AS destino ON destino.ubicacion_id = s.destino_id
    LEFT JOIN dbo.viaje_solicitudes AS vs ON vs.solicitud_id = s.solicitud_id
    LEFT JOIN dbo.viajes AS v ON v.viaje_id = vs.viaje_id
    WHERE (@buscar IS NULL OR s.codigo_solicitud LIKE N'%' + @buscar + N'%' OR c.identificacion LIKE N'%' + @buscar + N'%'
           OR s.destinatario_nombre LIKE N'%' + @buscar + N'%' OR s.destinatario_apellido1 LIKE N'%' + @buscar + N'%')
      AND (@estado IS NULL OR s.estado = @estado)
      AND (@cliente_id IS NULL OR s.cliente_id = @cliente_id)
      AND (@desde IS NULL OR s.fecha_solicitud >= @desde)
      AND (@hasta IS NULL OR s.fecha_solicitud < DATEADD(DAY, 1, @hasta))
    ORDER BY s.fecha_solicitud DESC, s.solicitud_id DESC
    OFFSET @offset ROWS FETCH NEXT @por_pagina ROWS ONLY;
END;
GO
