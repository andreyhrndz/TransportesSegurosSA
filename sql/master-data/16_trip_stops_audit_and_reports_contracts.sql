/* =========================================================================
   Migración 13: Itinerarios editables, bitácora independiente y reportes
   =========================================================================
   Estrictamente aditiva, idempotente y 100% retrocompatible con main y 01-12:
   1. TVP dbo.tipo_orden_paradas: (parada_id INT PRIMARY KEY, orden INT).
   2. dbo.sp_obtener_viaje: agrega columnas aditivas tiene_solicitudes y
      es_reutilizable al final del resultset 2 (paradas del itinerario).
   3. dbo.sp_reordenar_paradas_viaje: reordenamiento atómico 1..N en dos fases
      (+100000 -> 1..N) respetando CK_paradas_viaje_Orden y UQ_paradas_viaje,
      validando recogida < entrega en dbo.viaje_solicitudes y auditando REORDER.
   4. dbo.sp_editar_parada_viaje: edición segura de ubicación y tipo solo en
      viajes PLANIFICADO y paradas NO vinculadas a solicitudes, con auditoría.
   5. dbo.sp_listar_auditorias: agrega parámetros opcionales @usuario_buscar y
      @accion y columnas aditivas usuario_activo, valores_anteriores y
      valores_nuevos; preserva usuarios inactivos/ausentes vía LEFT JOIN.
   6. Índice no agrupado IX_auditorias_fecha_entidad_accion en dbo.auditorias.
   7. dbo.sp_listar_solicitudes: agrega parámetro opcional @prioridad = NULL.
   8. dbo.sp_reporte_solicitudes_resumen y dbo.sp_reporte_entregas_resumen:
      agregaciones consistentes en SQL para KPIs y gráficos de reportes.
   ========================================================================= */

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

-- -------------------------------------------------------------------------
-- 1. TVP FUERTE PARA REORDENAMIENTO DE PARADAS (IMPIDE DUPLICADOS)
-- -------------------------------------------------------------------------
IF TYPE_ID(N'dbo.tipo_orden_paradas') IS NULL
BEGIN
    CREATE TYPE dbo.tipo_orden_paradas AS TABLE
    (
        parada_id INT NOT NULL PRIMARY KEY,
        orden INT NOT NULL
    );
END;
GO

-- -------------------------------------------------------------------------
-- 2. ÍNDICE DE SOPORTE PARA BITÁCORA CRONOLÓGICA Y FILTROS
-- -------------------------------------------------------------------------
IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.auditorias')
      AND name = N'IX_auditorias_fecha_entidad_accion'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_auditorias_fecha_entidad_accion
        ON dbo.auditorias (fecha_hora DESC, auditoria_id DESC)
        INCLUDE (usuario_id, entidad, accion, registro_id);
END;
GO

-- -------------------------------------------------------------------------
-- 3. sp_obtener_viaje: COLUMNAS ADITIVAS EN RESULTSET DE PARADAS
-- -------------------------------------------------------------------------
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
        u.latitud, u.longitud,
        CASE
            WHEN EXISTS (
                SELECT 1
                FROM dbo.viaje_solicitudes AS vs
                WHERE vs.viaje_id = p.viaje_id
                  AND (vs.parada_recogida_id = p.parada_id OR vs.parada_entrega_id = p.parada_id)
            ) THEN CONVERT(BIT, 1)
            ELSE CONVERT(BIT, 0)
        END AS tiene_solicitudes,
        u.es_reutilizable
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

-- -------------------------------------------------------------------------
-- 4. sp_reordenar_paradas_viaje (ATÓMICO, 2 FASES, RESPETA SOLICITUDES)
-- -------------------------------------------------------------------------
CREATE OR ALTER PROCEDURE dbo.sp_reordenar_paradas_viaje
    @viaje_id INT,
    @paradas dbo.tipo_orden_paradas READONLY
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'VIAJES_PARADAS') = 0
        THROW 50306, 'No tiene permiso para gestionar paradas del viaje.', 1;

    IF @viaje_id IS NULL OR @viaje_id < 1
        THROW 50307, 'El viaje especificado no es válido.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION;

    BEGIN TRY
        DECLARE @estado_viaje VARCHAR(20);
        DECLARE @codigo_viaje NVARCHAR(30);

        SELECT @estado_viaje = estado, @codigo_viaje = codigo_viaje
        FROM dbo.viajes WITH (UPDLOCK, HOLDLOCK)
        WHERE viaje_id = @viaje_id;

        IF @estado_viaje IS NULL OR @estado_viaje <> 'PLANIFICADO'
            THROW 50307, 'Solo se pueden reordenar paradas de viajes en estado PLANIFICADO.', 1;

        DECLARE @total_existentes INT = 0;
        DECLARE @total_recibidas INT = 0;
        DECLARE @coincidencias INT = 0;

        SELECT @total_existentes = COUNT(*)
        FROM dbo.paradas_viaje WITH (UPDLOCK, HOLDLOCK)
        WHERE viaje_id = @viaje_id;

        SELECT @total_recibidas = COUNT(*) FROM @paradas;

        IF @total_existentes < 2
            THROW 50331, 'El viaje requiere al menos dos paradas para reordenar el itinerario.', 1;

        IF @total_recibidas <> @total_existentes
            THROW 50331, 'Debe enviar exactamente todas las paradas del viaje sin omisiones ni duplicados.', 1;

        SELECT @coincidencias = COUNT(*)
        FROM @paradas AS np
        JOIN dbo.paradas_viaje AS p ON p.parada_id = np.parada_id AND p.viaje_id = @viaje_id;

        IF @coincidencias <> @total_existentes
            THROW 50331, 'Alguna parada indicada no pertenece a este viaje.', 1;

        DECLARE @min_ord INT, @max_ord INT, @dist_ord INT;
        SELECT @min_ord = MIN(orden), @max_ord = MAX(orden), @dist_ord = COUNT(DISTINCT orden)
        FROM @paradas;

        IF @min_ord <> 1 OR @max_ord <> @total_existentes OR @dist_ord <> @total_existentes
            THROW 50332, 'La secuencia de órdenes debe ser consecutiva del 1 al total de paradas.', 1;

        -- Verificar precedencia recogida < entrega para TODAS las solicitudes asignadas al viaje
        IF EXISTS (
            SELECT 1
            FROM dbo.viaje_solicitudes AS vs WITH (UPDLOCK, HOLDLOCK)
            JOIN @paradas AS p_rec ON p_rec.parada_id = vs.parada_recogida_id
            JOIN @paradas AS p_ent ON p_ent.parada_id = vs.parada_entrega_id
            WHERE vs.viaje_id = @viaje_id
              AND p_rec.orden >= p_ent.orden
        )
            THROW 50333, 'El orden propuesto ubica una entrega antes o en la misma posición que su recogida.', 1;

        DECLARE @resumen_anterior NVARCHAR(MAX);
        DECLARE @resumen_nuevo NVARCHAR(MAX);

        SELECT @resumen_anterior = STRING_AGG(
            CONCAT(N'#', p.orden, N' ', u.nombre, N' (', p.tipo_parada, N')'),
            N' -> '
        ) WITHIN GROUP (ORDER BY p.orden)
        FROM dbo.paradas_viaje AS p
        JOIN dbo.ubicaciones AS u ON u.ubicacion_id = p.ubicacion_id
        WHERE p.viaje_id = @viaje_id;

        SELECT @resumen_nuevo = STRING_AGG(
            CONCAT(N'#', np.orden, N' ', u.nombre, N' (', p.tipo_parada, N')'),
            N' -> '
        ) WITHIN GROUP (ORDER BY np.orden)
        FROM @paradas AS np
        JOIN dbo.paradas_viaje AS p ON p.parada_id = np.parada_id AND p.viaje_id = @viaje_id
        JOIN dbo.ubicaciones AS u ON u.ubicacion_id = p.ubicacion_id;

        -- Actualización segura en 2 fases: respeta CK_paradas_viaje_Orden (> 0) y UQ_paradas_viaje_viaje_id_orden
        UPDATE dbo.paradas_viaje
        SET orden = orden + 100000
        WHERE viaje_id = @viaje_id;

        UPDATE p
        SET p.orden = np.orden
        FROM dbo.paradas_viaje AS p
        JOIN @paradas AS np ON np.parada_id = p.parada_id
        WHERE p.viaje_id = @viaje_id;

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (
            @actor_id,
            N'paradas_viaje',
            CONVERT(NVARCHAR(100), @viaje_id),
            'REORDER',
            CONCAT(N'Viaje ', @codigo_viaje, N': ', LEFT(ISNULL(@resumen_anterior, N''), 900)),
            CONCAT(N'Viaje ', @codigo_viaje, N': ', LEFT(ISNULL(@resumen_nuevo, N''), 900))
        );

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

-- -------------------------------------------------------------------------
-- 5. sp_editar_parada_viaje (SOLO PLANIFICADO Y NO VINCULADA A SOLICITUDES)
-- -------------------------------------------------------------------------
CREATE OR ALTER PROCEDURE dbo.sp_editar_parada_viaje
    @viaje_id INT,
    @parada_id INT,
    @ubicacion_id INT,
    @tipo_parada VARCHAR(20)
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'VIAJES_PARADAS') = 0
        THROW 50306, 'No tiene permiso para editar paradas del viaje.', 1;

    SET @tipo_parada = UPPER(LTRIM(RTRIM(ISNULL(@tipo_parada, ''))));
    IF @tipo_parada NOT IN ('RECOGIDA', 'ENTREGA', 'MIXTA', 'INTERMEDIA')
        THROW 50334, 'El tipo de parada especificado no es válido.', 1;

    IF @viaje_id IS NULL OR @viaje_id < 1 OR @parada_id IS NULL OR @parada_id < 1 OR @ubicacion_id IS NULL OR @ubicacion_id < 1
        THROW 50335, 'Los identificadores de viaje, parada y ubicación son obligatorios.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION;

    BEGIN TRY
        DECLARE @estado_viaje VARCHAR(20), @codigo_viaje NVARCHAR(30);
        SELECT @estado_viaje = estado, @codigo_viaje = codigo_viaje
        FROM dbo.viajes WITH (UPDLOCK, HOLDLOCK)
        WHERE viaje_id = @viaje_id;

        IF @estado_viaje IS NULL OR @estado_viaje <> 'PLANIFICADO'
            THROW 50307, 'Solo se pueden editar paradas de viajes en estado PLANIFICADO.', 1;

        DECLARE @prev_ubi_id INT, @prev_tipo VARCHAR(20), @prev_orden INT;
        SELECT @prev_ubi_id = ubicacion_id, @prev_tipo = tipo_parada, @prev_orden = orden
        FROM dbo.paradas_viaje WITH (UPDLOCK, HOLDLOCK)
        WHERE parada_id = @parada_id AND viaje_id = @viaje_id;

        IF @prev_ubi_id IS NULL
            THROW 50335, 'La parada indicada no existe en este viaje.', 1;

        IF EXISTS (
            SELECT 1
            FROM dbo.viaje_solicitudes WITH (UPDLOCK, HOLDLOCK)
            WHERE viaje_id = @viaje_id
              AND (parada_recogida_id = @parada_id OR parada_entrega_id = @parada_id)
        )
            THROW 50336, 'No se puede editar la ubicación ni el tipo de una parada vinculada a una solicitud asignada.', 1;

        DECLARE @new_ubi_nombre NVARCHAR(150) = NULL, @new_ubi_activo BIT = NULL, @new_ubi_reut BIT = NULL;
        SELECT @new_ubi_nombre = nombre, @new_ubi_activo = activo, @new_ubi_reut = es_reutilizable
        FROM dbo.ubicaciones
        WHERE ubicacion_id = @ubicacion_id;

        IF @new_ubi_activo IS NULL OR @new_ubi_activo = 0
            THROW 50337, 'La ubicación seleccionada no existe o no está activa.', 1;

        IF @new_ubi_reut = 0 AND @ubicacion_id <> @prev_ubi_id
           AND NOT EXISTS (
               SELECT 1
               FROM dbo.solicitudes
               WHERE estado IN ('REGISTRADA', 'PENDIENTE_ASIGNACION')
                 AND (origen_id = @ubicacion_id OR destino_id = @ubicacion_id)
           )
            THROW 50337, 'La ubicación puntual indicada no pertenece a una solicitud abierta elegible.', 1;

        DECLARE @prev_ubi_nombre NVARCHAR(150);
        SELECT @prev_ubi_nombre = nombre FROM dbo.ubicaciones WHERE ubicacion_id = @prev_ubi_id;

        UPDATE dbo.paradas_viaje
        SET ubicacion_id = @ubicacion_id,
            tipo_parada = @tipo_parada
        WHERE parada_id = @parada_id AND viaje_id = @viaje_id;

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (
            @actor_id,
            N'paradas_viaje',
            CONVERT(NVARCHAR(100), @parada_id),
            'UPDATE',
            CONCAT(N'Viaje ', @codigo_viaje, N' parada #', @prev_orden, N': ', ISNULL(@prev_ubi_nombre, N''), N' (', @prev_tipo, N')'),
            CONCAT(N'Viaje ', @codigo_viaje, N' parada #', @prev_orden, N': ', ISNULL(@new_ubi_nombre, N''), N' (', @tipo_parada, N')')
        );

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

-- -------------------------------------------------------------------------
-- 6. sp_listar_auditorias: RETROCOMPATIBLE + USUARIO, ACCIÓN Y DETALLE
-- -------------------------------------------------------------------------
CREATE OR ALTER PROCEDURE dbo.sp_listar_auditorias
    @entidad NVARCHAR(100) = NULL,
    @registro_id NVARCHAR(100) = NULL,
    @desde DATETIME2 = NULL,
    @hasta DATETIME2 = NULL,
    @pagina INT = 1,
    @por_pagina INT = 20,
    @total_registros INT OUTPUT,
    @usuario_buscar NVARCHAR(100) = NULL,
    @accion VARCHAR(20) = NULL
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
    SET @accion = NULLIF(UPPER(LTRIM(RTRIM(@accion))), '');
    SET @usuario_buscar = NULLIF(LTRIM(RTRIM(@usuario_buscar)), N'');

    DECLARE @usuario_esc NVARCHAR(220) = NULL;
    IF @usuario_buscar IS NOT NULL
    BEGIN
        SET @usuario_esc = REPLACE(REPLACE(REPLACE(REPLACE(@usuario_buscar, N'\', N'\\'), N'%', N'\%'), N'_', N'\_'), N'[', N'\[');
    END;

    SET @offset = CONVERT(BIGINT, @pagina - 1) * @por_pagina;

    SELECT @total_registros = COUNT(*)
    FROM dbo.auditorias AS a
    LEFT JOIN dbo.usuarios AS u ON u.usuario_id = a.usuario_id
    WHERE (@entidad IS NULL OR a.entidad = @entidad)
      AND (@registro_id IS NULL OR a.registro_id = @registro_id)
      AND (@accion IS NULL OR UPPER(a.accion) = @accion)
      AND (@desde IS NULL OR a.fecha_hora >= @desde)
      AND (@hasta IS NULL OR a.fecha_hora < DATEADD(DAY, 1, @hasta))
      AND (
          @usuario_esc IS NULL
          OR u.nombre_usuario LIKE N'%' + @usuario_esc + N'%' ESCAPE N'\'
          OR (a.usuario_id IS NULL AND N'sistema' LIKE N'%' + @usuario_esc + N'%' ESCAPE N'\')
      );

    SELECT
        a.auditoria_id,
        a.entidad,
        a.registro_id,
        a.accion,
        a.fecha_hora,
        a.usuario_id,
        u.nombre_usuario AS usuario,
        u.activo AS usuario_activo,
        a.valores_anteriores,
        a.valores_nuevos
    FROM dbo.auditorias AS a
    LEFT JOIN dbo.usuarios AS u ON u.usuario_id = a.usuario_id
    WHERE (@entidad IS NULL OR a.entidad = @entidad)
      AND (@registro_id IS NULL OR a.registro_id = @registro_id)
      AND (@accion IS NULL OR UPPER(a.accion) = @accion)
      AND (@desde IS NULL OR a.fecha_hora >= @desde)
      AND (@hasta IS NULL OR a.fecha_hora < DATEADD(DAY, 1, @hasta))
      AND (
          @usuario_esc IS NULL
          OR u.nombre_usuario LIKE N'%' + @usuario_esc + N'%' ESCAPE N'\'
          OR (a.usuario_id IS NULL AND N'sistema' LIKE N'%' + @usuario_esc + N'%' ESCAPE N'\')
      )
    ORDER BY a.fecha_hora DESC, a.auditoria_id DESC
    OFFSET @offset ROWS FETCH NEXT @por_pagina ROWS ONLY;
END;
GO

-- -------------------------------------------------------------------------
-- 7. sp_listar_solicitudes: PARÁMETRO OPCIONAL @prioridad (RETROCOMPATIBLE)
-- -------------------------------------------------------------------------
CREATE OR ALTER PROCEDURE dbo.sp_listar_solicitudes
    @buscar NVARCHAR(120) = NULL,
    @estado VARCHAR(30) = NULL,
    @cliente_id INT = NULL,
    @desde DATETIME2 = NULL,
    @hasta DATETIME2 = NULL,
    @pagina INT = 1,
    @por_pagina INT = 20,
    @total_registros INT OUTPUT,
    @prioridad VARCHAR(10) = NULL
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
    SET @prioridad = NULLIF(UPPER(LTRIM(RTRIM(@prioridad))), '');
    IF @prioridad IS NOT NULL AND @prioridad NOT IN ('NORMAL', 'ALTA', 'URGENTE')
        THROW 50102, N'Prioridad de solicitud inválida.', 1;

    SET @buscar = NULLIF(LTRIM(RTRIM(@buscar)), N'');
    SET @offset = CONVERT(BIGINT, @pagina - 1) * @por_pagina;

    SELECT @total_registros = COUNT(*)
    FROM dbo.solicitudes AS s
    JOIN dbo.clientes AS c ON c.cliente_id = s.cliente_id
    WHERE (@buscar IS NULL OR s.codigo_solicitud LIKE N'%' + @buscar + N'%' OR c.identificacion LIKE N'%' + @buscar + N'%'
           OR s.destinatario_nombre LIKE N'%' + @buscar + N'%' OR s.destinatario_apellido1 LIKE N'%' + @buscar + N'%')
      AND (@estado IS NULL OR s.estado = @estado)
      AND (@prioridad IS NULL OR s.prioridad_actual = @prioridad)
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
      AND (@prioridad IS NULL OR s.prioridad_actual = @prioridad)
      AND (@cliente_id IS NULL OR s.cliente_id = @cliente_id)
      AND (@desde IS NULL OR s.fecha_solicitud >= @desde)
      AND (@hasta IS NULL OR s.fecha_solicitud < DATEADD(DAY, 1, @hasta))
    ORDER BY s.fecha_solicitud DESC, s.solicitud_id DESC
    OFFSET @offset ROWS FETCH NEXT @por_pagina ROWS ONLY;
END;
GO

-- -------------------------------------------------------------------------
-- 8. sp_reporte_solicitudes_resumen (KPIs + DESGLOSE ESTADO Y PRIORIDAD)
-- -------------------------------------------------------------------------
CREATE OR ALTER PROCEDURE dbo.sp_reporte_solicitudes_resumen
    @buscar NVARCHAR(120) = NULL,
    @estado VARCHAR(30) = NULL,
    @prioridad VARCHAR(10) = NULL,
    @desde DATETIME2 = NULL,
    @hasta DATETIME2 = NULL
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));

    IF dbo.fn_tiene_permiso(@usuario_id, N'REPORTES_VER') = 0
       OR dbo.fn_tiene_permiso(@usuario_id, N'SOLICITUDES_VER') = 0
        THROW 50103, N'No tiene permiso para consultar el reporte de solicitudes.', 1;

    IF @desde IS NOT NULL AND @hasta IS NOT NULL AND @desde > @hasta
        THROW 50102, N'Rango de fechas inválido.', 1;

    SET @buscar = NULLIF(LTRIM(RTRIM(@buscar)), N'');
    SET @estado = NULLIF(UPPER(LTRIM(RTRIM(@estado))), '');
    SET @prioridad = NULLIF(UPPER(LTRIM(RTRIM(@prioridad))), '');

    IF @estado IS NOT NULL AND @estado NOT IN ('REGISTRADA', 'PENDIENTE_ASIGNACION', 'ASIGNADA', 'EN_TRANSITO', 'ENTREGADA', 'ENTREGA_FALLIDA', 'CANCELADA')
        THROW 50102, N'Estado de solicitud inválido.', 1;
    IF @prioridad IS NOT NULL AND @prioridad NOT IN ('NORMAL', 'ALTA', 'URGENTE')
        THROW 50102, N'Prioridad de solicitud inválida.', 1;

    -- Resultset 1: KPIs globales del período/filtros
    SELECT
        COUNT(*) AS total_solicitudes,
        SUM(CASE WHEN s.estado IN ('REGISTRADA', 'PENDIENTE_ASIGNACION') THEN 1 ELSE 0 END) AS pendientes_asignacion,
        SUM(CASE WHEN s.estado IN ('ASIGNADA', 'EN_TRANSITO') THEN 1 ELSE 0 END) AS en_operacion,
        SUM(CASE WHEN s.estado = 'ENTREGADA' THEN 1 ELSE 0 END) AS entregadas,
        SUM(CASE WHEN s.estado = 'ENTREGA_FALLIDA' THEN 1 ELSE 0 END) AS fallidas,
        SUM(CASE WHEN s.estado = 'CANCELADA' THEN 1 ELSE 0 END) AS canceladas,
        ISNULL(SUM(ISNULL(pm.peso_total_kg, 0)), 0) AS peso_total_kg
    FROM dbo.solicitudes AS s
    JOIN dbo.clientes AS c ON c.cliente_id = s.cliente_id
    OUTER APPLY (
        SELECT SUM(m.peso_kg) AS peso_total_kg
        FROM dbo.mercancias AS m
        WHERE m.solicitud_id = s.solicitud_id
    ) AS pm
    WHERE (@buscar IS NULL OR s.codigo_solicitud LIKE N'%' + @buscar + N'%' OR c.identificacion LIKE N'%' + @buscar + N'%'
           OR s.destinatario_nombre LIKE N'%' + @buscar + N'%' OR s.destinatario_apellido1 LIKE N'%' + @buscar + N'%')
      AND (@estado IS NULL OR s.estado = @estado)
      AND (@prioridad IS NULL OR s.prioridad_actual = @prioridad)
      AND (@desde IS NULL OR s.fecha_solicitud >= @desde)
      AND (@hasta IS NULL OR s.fecha_solicitud < DATEADD(DAY, 1, @hasta));

    -- Resultset 2: Distribución por estado
    SELECT s.estado, COUNT(*) AS cantidad
    FROM dbo.solicitudes AS s
    JOIN dbo.clientes AS c ON c.cliente_id = s.cliente_id
    WHERE (@buscar IS NULL OR s.codigo_solicitud LIKE N'%' + @buscar + N'%' OR c.identificacion LIKE N'%' + @buscar + N'%'
           OR s.destinatario_nombre LIKE N'%' + @buscar + N'%' OR s.destinatario_apellido1 LIKE N'%' + @buscar + N'%')
      AND (@estado IS NULL OR s.estado = @estado)
      AND (@prioridad IS NULL OR s.prioridad_actual = @prioridad)
      AND (@desde IS NULL OR s.fecha_solicitud >= @desde)
      AND (@hasta IS NULL OR s.fecha_solicitud < DATEADD(DAY, 1, @hasta))
    GROUP BY s.estado
    ORDER BY COUNT(*) DESC, s.estado;

    -- Resultset 3: Distribución por prioridad actual
    SELECT s.prioridad_actual AS prioridad, COUNT(*) AS cantidad
    FROM dbo.solicitudes AS s
    JOIN dbo.clientes AS c ON c.cliente_id = s.cliente_id
    WHERE (@buscar IS NULL OR s.codigo_solicitud LIKE N'%' + @buscar + N'%' OR c.identificacion LIKE N'%' + @buscar + N'%'
           OR s.destinatario_nombre LIKE N'%' + @buscar + N'%' OR s.destinatario_apellido1 LIKE N'%' + @buscar + N'%')
      AND (@estado IS NULL OR s.estado = @estado)
      AND (@prioridad IS NULL OR s.prioridad_actual = @prioridad)
      AND (@desde IS NULL OR s.fecha_solicitud >= @desde)
      AND (@hasta IS NULL OR s.fecha_solicitud < DATEADD(DAY, 1, @hasta))
    GROUP BY s.prioridad_actual
    ORDER BY COUNT(*) DESC, s.prioridad_actual;
END;
GO

-- -------------------------------------------------------------------------
-- 9. sp_reporte_entregas_resumen (TASA DE ÉXITO CON DENOMINADOR EXPLÍCITO)
-- -------------------------------------------------------------------------
CREATE OR ALTER PROCEDURE dbo.sp_reporte_entregas_resumen
    @estado VARCHAR(15) = NULL,
    @desde DATETIME2 = NULL,
    @hasta DATETIME2 = NULL
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));

    IF dbo.fn_tiene_permiso(@usuario_id, N'REPORTES_VER') = 0
       OR dbo.fn_tiene_permiso(@usuario_id, N'ENTREGAS_VER') = 0
        THROW 50103, N'No tiene permiso para consultar el reporte de entregas.', 1;

    IF @desde IS NOT NULL AND @hasta IS NOT NULL AND @desde > @hasta
        THROW 50102, N'Rango de fechas inválido.', 1;

    SET @estado = NULLIF(UPPER(LTRIM(RTRIM(@estado))), '');
    IF @estado IS NOT NULL AND @estado NOT IN ('ENTREGADA', 'FALLIDA')
        THROW 50102, N'Estado de entrega inválido.', 1;

    -- Resultset 1: KPIs (denominador explícito: entregadas + fallidas; sin pendientes)
    SELECT
        COUNT(*) AS total_resultados,
        SUM(CASE WHEN e.estado = 'ENTREGADA' THEN 1 ELSE 0 END) AS entregadas,
        SUM(CASE WHEN e.estado = 'FALLIDA' THEN 1 ELSE 0 END) AS fallidas,
        CASE
            WHEN COUNT(*) = 0 THEN NULL
            ELSE CONVERT(DECIMAL(5,1), (100.0 * SUM(CASE WHEN e.estado = 'ENTREGADA' THEN 1 ELSE 0 END)) / COUNT(*))
        END AS tasa_exito_pct,
        SUM(CASE WHEN e.estado = 'ENTREGADA' AND e.fecha_recepcion IS NOT NULL AND e.fecha_recepcion <= s.fecha_entrega_requerida THEN 1 ELSE 0 END) AS entregadas_a_tiempo
    FROM dbo.entregas AS e
    JOIN dbo.viaje_solicitudes AS vs ON vs.viaje_solicitud_id = e.viaje_solicitud_id
    JOIN dbo.solicitudes AS s ON s.solicitud_id = vs.solicitud_id
    WHERE (@estado IS NULL OR e.estado = @estado)
      AND (@desde IS NULL OR e.fecha_resultado >= @desde)
      AND (@hasta IS NULL OR e.fecha_resultado < DATEADD(DAY, 1, @hasta));

    -- Resultset 2: Serie diaria por fecha_resultado
    SELECT
        CONVERT(DATE, e.fecha_resultado) AS fecha_dia,
        SUM(CASE WHEN e.estado = 'ENTREGADA' THEN 1 ELSE 0 END) AS entregadas,
        SUM(CASE WHEN e.estado = 'FALLIDA' THEN 1 ELSE 0 END) AS fallidas
    FROM dbo.entregas AS e
    WHERE (@estado IS NULL OR e.estado = @estado)
      AND (@desde IS NULL OR e.fecha_resultado >= @desde)
      AND (@hasta IS NULL OR e.fecha_resultado < DATEADD(DAY, 1, @hasta))
    GROUP BY CONVERT(DATE, e.fecha_resultado)
    ORDER BY fecha_dia ASC;
END;
GO
