/* =========================================================================
   Rollback NO DESTRUCTIVO de Migración 13
   =========================================================================
   Revierte procedimientos agregados/ampliados a su estado de Migración 12.
   No elimina datos, auditorías ni columnas existentes.
   ========================================================================= */

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

DROP PROCEDURE IF EXISTS dbo.sp_reporte_entregas_resumen;
GO
DROP PROCEDURE IF EXISTS dbo.sp_reporte_solicitudes_resumen;
GO
DROP PROCEDURE IF EXISTS dbo.sp_editar_parada_viaje;
GO
DROP PROCEDURE IF EXISTS dbo.sp_reordenar_paradas_viaje;
GO
IF TYPE_ID(N'dbo.tipo_orden_paradas') IS NOT NULL
BEGIN
    DROP TYPE dbo.tipo_orden_paradas;
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

    SELECT
        v.viaje_id, v.codigo_viaje, v.estado, v.fecha_programada_salida, v.fecha_salida_real,
        v.fecha_llegada_real, v.observaciones, ve.vehiculo_id, ve.placa,
        c.conductor_id, CONCAT(c.nombre, N' ', c.primer_apellido) AS conductor_nombre,
        r.ruta_id, r.nombre AS ruta_nombre
    FROM dbo.viajes AS v
    JOIN dbo.vehiculos AS ve ON ve.vehiculo_id = v.vehiculo_id
    JOIN dbo.conductores AS c ON c.conductor_id = v.conductor_id
    JOIN dbo.rutas AS r ON r.ruta_id = v.ruta_id
    WHERE v.viaje_id = @viaje_id;

    IF @@ROWCOUNT = 0
        THROW 50101, N'Viaje no encontrado.', 1;

    SELECT
        p.parada_id, p.orden, p.tipo_parada, p.estado, p.llegada_programada, p.llegada_real,
        p.salida_real, p.observaciones, u.ubicacion_id, u.nombre AS ubicacion_nombre, u.direccion,
        u.latitud, u.longitud
    FROM dbo.paradas_viaje AS p
    JOIN dbo.ubicaciones AS u ON u.ubicacion_id = p.ubicacion_id
    WHERE p.viaje_id = @viaje_id
    ORDER BY p.orden;

    SELECT
        vs.viaje_solicitud_id, vs.solicitud_id, s.codigo_solicitud,
        s.estado AS estado_solicitud, vs.estado AS estado_asignacion,
        vs.parada_recogida_id, vs.parada_entrega_id
    FROM dbo.viaje_solicitudes AS vs
    JOIN dbo.solicitudes AS s ON s.solicitud_id = vs.solicitud_id
    WHERE vs.viaje_id = @viaje_id
    ORDER BY vs.viaje_solicitud_id;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_listar_auditorias
    @entidad NVARCHAR(100) = NULL,
    @registro_id NVARCHAR(100) = NULL,
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

    IF dbo.fn_tiene_permiso(@usuario_id, N'AUDITORIA_VER') = 0
        THROW 50103, N'No tiene permiso para consultar el historial de actividad.', 1;
    IF @pagina < 1 OR @por_pagina NOT BETWEEN 1 AND 100 OR (@desde IS NOT NULL AND @hasta IS NOT NULL AND @desde > @hasta)
        THROW 50102, N'Filtros o paginación inválidos.', 1;

    SET @entidad = NULLIF(LTRIM(RTRIM(@entidad)), N'');
    SET @registro_id = NULLIF(LTRIM(RTRIM(@registro_id)), N'');
    SET @offset = CONVERT(BIGINT, @pagina - 1) * @por_pagina;

    SELECT @total_registros = COUNT(*)
    FROM dbo.auditorias
    WHERE (@entidad IS NULL OR entidad = @entidad)
      AND (@registro_id IS NULL OR registro_id = @registro_id)
      AND (@desde IS NULL OR fecha_hora >= @desde)
      AND (@hasta IS NULL OR fecha_hora < DATEADD(DAY, 1, @hasta));

    SELECT a.auditoria_id, a.entidad, a.registro_id, a.accion, a.fecha_hora, a.usuario_id, u.nombre_usuario AS usuario
    FROM dbo.auditorias AS a
    LEFT JOIN dbo.usuarios AS u ON u.usuario_id = a.usuario_id
    WHERE (@entidad IS NULL OR a.entidad = @entidad)
      AND (@registro_id IS NULL OR a.registro_id = @registro_id)
      AND (@desde IS NULL OR a.fecha_hora >= @desde)
      AND (@hasta IS NULL OR a.fecha_hora < DATEADD(DAY, 1, @hasta))
    ORDER BY a.fecha_hora DESC, a.auditoria_id DESC
    OFFSET @offset ROWS FETCH NEXT @por_pagina ROWS ONLY;
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
        vs.viaje_id, v.codigo_viaje,
        s.origen_id, s.destino_id
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
