/* =========================================================================
   Migración 12: Ubicaciones híbridas en solicitudes de transporte
   =========================================================================
   Alcance estrictamente aditivo, idempotente y compatible con Migraciones 01-11:
   1. dbo.ubicaciones: agrega es_reutilizable BIT NOT NULL DEFAULT (1) + índice
      no agrupado IX_ubicaciones_reutilizable_activo_nombre. Todos los registros
      preexistentes permanecen como ubicaciones frecuentes (es_reutilizable = 1).
   2. dbo.sp_listar_ubicaciones: excluye por defecto puntos puntuales
      (es_reutilizable = 0), manteniendo catálogo y comboboxes limpios.
   3. dbo.sp_listar_ubicaciones_parada: lista frecuentes activas + puntos
      puntuales activos vinculados a solicitudes abiertas (REGISTRADA /
      PENDIENTE_ASIGNACION) para planificar paradas en viajes.
   4. dbo.sp_crear_ubicacion / sp_actualizar_ubicacion / sp_desactivar_ubicacion:
      preservan integridad de es_reutilizable = 1 y evitan colisiones de nombre
      con puntos puntuales históricos.
   5. dbo.sp_obtener_solicitud y dbo.sp_listar_solicitudes: aditivos, resuelven
      siempre cualquier ubicación por ID sin filtrar por es_reutilizable.
   6. dbo.sp_crear_solicitud_con_ubicaciones: creación atómica (una sola
      transacción con XACT_ABORT ON) de puntos de mapa (puntuales o frecuentes
      con control estricto de UBICACIONES_GESTIONAR) + solicitud + TVP
      dbo.tipo_mercancias + validación geográfica Haversine (<= 25 m).
   ========================================================================= */

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

-- -------------------------------------------------------------------------
-- 1. COLUMNA ADITIVA E ÍNDICE EN dbo.ubicaciones
-- -------------------------------------------------------------------------
IF COL_LENGTH(N'dbo.ubicaciones', N'es_reutilizable') IS NULL
BEGIN
    ALTER TABLE dbo.ubicaciones
        ADD es_reutilizable BIT NOT NULL
            CONSTRAINT DF_ubicaciones_es_reutilizable DEFAULT (1);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.ubicaciones')
      AND name = N'IX_ubicaciones_reutilizable_activo_nombre'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_ubicaciones_reutilizable_activo_nombre
        ON dbo.ubicaciones (es_reutilizable, activo, nombre)
        INCLUDE (direccion, latitud, longitud);
END;
GO

-- -------------------------------------------------------------------------
-- 2. sp_listar_ubicaciones: SOLO REUTILIZABLES POR DEFECTO
-- -------------------------------------------------------------------------
CREATE OR ALTER PROCEDURE dbo.sp_listar_ubicaciones
    @buscar NVARCHAR(120) = NULL,
    @solo_activos BIT = 1,
    @pagina INT = 1,
    @por_pagina INT = 50,
    @total_registros INT OUTPUT,
    @incluir_puntuales BIT = 0
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
    DECLARE @buscar_esc NVARCHAR(250) = NULL;
    IF @buscar IS NOT NULL
    BEGIN
        SET @buscar_esc = REPLACE(REPLACE(REPLACE(REPLACE(@buscar, N'\', N'\\'), N'%', N'\%'), N'_', N'\_'), N'[', N'\[');
    END;

    SET @offset = CONVERT(BIGINT, @pagina - 1) * @por_pagina;

    SELECT @total_registros = COUNT(*)
    FROM dbo.ubicaciones
    WHERE (@incluir_puntuales = 1 OR es_reutilizable = 1)
      AND (@solo_activos IS NULL OR activo = @solo_activos)
      AND (
          @buscar_esc IS NULL
          OR nombre LIKE N'%' + @buscar_esc + N'%' ESCAPE N'\'
          OR direccion LIKE N'%' + @buscar_esc + N'%' ESCAPE N'\'
      );

    SELECT ubicacion_id, nombre, direccion, latitud, longitud, activo, es_reutilizable
    FROM dbo.ubicaciones
    WHERE (@incluir_puntuales = 1 OR es_reutilizable = 1)
      AND (@solo_activos IS NULL OR activo = @solo_activos)
      AND (
          @buscar_esc IS NULL
          OR nombre LIKE N'%' + @buscar_esc + N'%' ESCAPE N'\'
          OR direccion LIKE N'%' + @buscar_esc + N'%' ESCAPE N'\'
      )
    ORDER BY nombre, ubicacion_id
    OFFSET @offset ROWS FETCH NEXT @por_pagina ROWS ONLY;
END;
GO

-- -------------------------------------------------------------------------
-- 3. sp_listar_ubicaciones_parada: FRECUENTES + PUNTUALES DE SOLICITUDES ABIERTAS
-- -------------------------------------------------------------------------
CREATE OR ALTER PROCEDURE dbo.sp_listar_ubicaciones_parada
    @buscar NVARCHAR(120) = NULL,
    @pagina INT = 1,
    @por_pagina INT = 50,
    @total_registros INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    DECLARE @offset BIGINT;

    IF dbo.fn_tiene_permiso(@usuario_id, N'VIAJES_PARADAS') = 0
       AND dbo.fn_tiene_permiso(@usuario_id, N'UBICACIONES_VER') = 0
        THROW 50103, N'No tiene permiso para consultar ubicaciones de paradas.', 1;
    IF @pagina < 1 OR @por_pagina NOT BETWEEN 1 AND 100
        THROW 50102, N'Paginación inválida.', 1;

    SET @buscar = NULLIF(LTRIM(RTRIM(@buscar)), N'');
    DECLARE @buscar_esc NVARCHAR(250) = NULL;
    IF @buscar IS NOT NULL
    BEGIN
        SET @buscar_esc = REPLACE(REPLACE(REPLACE(REPLACE(@buscar, N'\', N'\\'), N'%', N'\%'), N'_', N'\_'), N'[', N'\[');
    END;

    SET @offset = CONVERT(BIGINT, @pagina - 1) * @por_pagina;

    ;WITH elegibles AS (
        SELECT
            u.ubicacion_id,
            u.nombre,
            u.direccion,
            u.latitud,
            u.longitud,
            u.activo,
            u.es_reutilizable,
            sol.codigo_solicitud AS solicitud_codigo
        FROM dbo.ubicaciones AS u
        OUTER APPLY (
            SELECT TOP (1) s.codigo_solicitud
            FROM dbo.solicitudes AS s
            WHERE s.estado IN ('REGISTRADA', 'PENDIENTE_ASIGNACION')
              AND (s.origen_id = u.ubicacion_id OR s.destino_id = u.ubicacion_id)
            ORDER BY s.solicitud_id DESC
        ) AS sol
        WHERE u.activo = 1
          AND (u.es_reutilizable = 1 OR sol.codigo_solicitud IS NOT NULL)
          AND (
              @buscar_esc IS NULL
              OR u.nombre LIKE N'%' + @buscar_esc + N'%' ESCAPE N'\'
              OR u.direccion LIKE N'%' + @buscar_esc + N'%' ESCAPE N'\'
              OR (sol.codigo_solicitud IS NOT NULL AND sol.codigo_solicitud LIKE N'%' + @buscar_esc + N'%' ESCAPE N'\')
          )
    )
    SELECT @total_registros = COUNT(*) FROM elegibles;

    ;WITH elegibles AS (
        SELECT
            u.ubicacion_id,
            u.nombre,
            u.direccion,
            u.latitud,
            u.longitud,
            u.activo,
            u.es_reutilizable,
            sol.codigo_solicitud AS solicitud_codigo
        FROM dbo.ubicaciones AS u
        OUTER APPLY (
            SELECT TOP (1) s.codigo_solicitud
            FROM dbo.solicitudes AS s
            WHERE s.estado IN ('REGISTRADA', 'PENDIENTE_ASIGNACION')
              AND (s.origen_id = u.ubicacion_id OR s.destino_id = u.ubicacion_id)
            ORDER BY s.solicitud_id DESC
        ) AS sol
        WHERE u.activo = 1
          AND (u.es_reutilizable = 1 OR sol.codigo_solicitud IS NOT NULL)
          AND (
              @buscar_esc IS NULL
              OR u.nombre LIKE N'%' + @buscar_esc + N'%' ESCAPE N'\'
              OR u.direccion LIKE N'%' + @buscar_esc + N'%' ESCAPE N'\'
              OR (sol.codigo_solicitud IS NOT NULL AND sol.codigo_solicitud LIKE N'%' + @buscar_esc + N'%' ESCAPE N'\')
          )
    )
    SELECT ubicacion_id, nombre, direccion, latitud, longitud, activo, es_reutilizable, solicitud_codigo
    FROM elegibles
    ORDER BY CASE WHEN es_reutilizable = 0 THEN 0 ELSE 1 END, nombre, ubicacion_id
    OFFSET @offset ROWS FETCH NEXT @por_pagina ROWS ONLY;
END;
GO

-- -------------------------------------------------------------------------
-- 4. CATÁLOGO DE UBICACIONES: RESPETAR es_reutilizable = 1
-- -------------------------------------------------------------------------
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
        IF EXISTS (
            SELECT 1
            FROM dbo.ubicaciones WITH (UPDLOCK, HOLDLOCK)
            WHERE nombre = @nombre AND es_reutilizable = 1
        )
            THROW 50504, 'Ya existe una ubicación registrada con ese nombre.', 1;

        INSERT INTO dbo.ubicaciones (nombre, direccion, latitud, longitud, activo, es_reutilizable)
        VALUES (@nombre, @direccion, @latitud, @longitud, 1, 1);

        SET @ubicacion_id = CONVERT(INT, SCOPE_IDENTITY());

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (
            @actor_id, N'ubicaciones', CONVERT(NVARCHAR(100), @ubicacion_id), 'INSERT',
            NULL, CONCAT(N'Nombre: ', @nombre, N'; Activo: 1; Reutilizable: 1')
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
        DECLARE @prev_nombre NVARCHAR(150), @activo BIT, @es_reutilizable BIT;
        SELECT @prev_nombre = nombre, @activo = activo, @es_reutilizable = es_reutilizable
        FROM dbo.ubicaciones WITH (UPDLOCK, HOLDLOCK)
        WHERE ubicacion_id = @ubicacion_id;

        IF @activo IS NULL OR @es_reutilizable = 0
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
            WHERE nombre = @nombre AND es_reutilizable = 1 AND ubicacion_id <> @ubicacion_id
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
        DECLARE @nombre NVARCHAR(150), @activo BIT, @es_reutilizable BIT;
        SELECT @nombre = nombre, @activo = activo, @es_reutilizable = es_reutilizable
        FROM dbo.ubicaciones WITH (UPDLOCK, HOLDLOCK)
        WHERE ubicacion_id = @ubicacion_id;

        IF @activo IS NULL OR @es_reutilizable = 0
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

-- -------------------------------------------------------------------------
-- 5. LECTURAS ADITIVAS DE SOLICITUDES (SIEMPRE RESUELVEN PUNTOS PUNTUALES)
-- -------------------------------------------------------------------------
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
        destino.direccion AS destino_direccion, destino.latitud AS destino_latitud, destino.longitud AS destino_longitud,
        origen.es_reutilizable AS origen_es_reutilizable, destino.es_reutilizable AS destino_es_reutilizable
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

-- -------------------------------------------------------------------------
-- 6. sp_crear_solicitud_con_ubicaciones (ATÓMICO, HÍBRIDO, ANTI-ESCALACIÓN)
-- -------------------------------------------------------------------------
CREATE OR ALTER PROCEDURE dbo.sp_crear_solicitud_con_ubicaciones
    @codigo NVARCHAR(30) = NULL,
    @cliente_id INT,
    @origen_modo VARCHAR(15),
    @origen_id INT = NULL,
    @origen_nombre NVARCHAR(150) = NULL,
    @origen_direccion NVARCHAR(250) = NULL,
    @origen_latitud DECIMAL(9,6) = NULL,
    @origen_longitud DECIMAL(9,6) = NULL,
    @origen_guardar_frecuente BIT = 0,
    @destino_modo VARCHAR(15),
    @destino_id INT = NULL,
    @destino_nombre NVARCHAR(150) = NULL,
    @destino_direccion NVARCHAR(250) = NULL,
    @destino_latitud DECIMAL(9,6) = NULL,
    @destino_longitud DECIMAL(9,6) = NULL,
    @destino_guardar_frecuente BIT = 0,
    @destinatario_nombre NVARCHAR(150),
    @destinatario_apellido1 NVARCHAR(100),
    @destinatario_apellido2 NVARCHAR(100),
    @destinatario_identificacion NVARCHAR(30),
    @destinatario_telefono NVARCHAR(30),
    @fecha_entrega_requerida DATETIME2,
    @mercancias dbo.tipo_mercancias READONLY,
    @solicitud_id INT OUTPUT,
    @origen_id_resuelto INT = NULL OUTPUT,
    @destino_id_resuelto INT = NULL OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @usuario INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    DECLARE @prioridad VARCHAR(10) = dbo.fn_calcular_prioridad(@fecha_entrega_requerida);
    DECLARE @orig_mode VARCHAR(15) = UPPER(LTRIM(RTRIM(ISNULL(@origen_modo, ''))));
    DECLARE @dest_mode VARCHAR(15) = UPPER(LTRIM(RTRIM(ISNULL(@destino_modo, ''))));

    IF dbo.fn_tiene_permiso(@usuario, N'SOLICITUDES_CREAR') = 0
        THROW 50203, 'No tiene permiso para crear solicitudes.', 1;

    IF @orig_mode NOT IN ('GUARDADA', 'MAPA') OR @dest_mode NOT IN ('GUARDADA', 'MAPA')
        THROW 50230, 'Modo de ubicación inválido.', 1;

    IF @fecha_entrega_requerida IS NULL OR @fecha_entrega_requerida <= SYSUTCDATETIME()
        THROW 50205, 'La entrega debe programarse a futuro.', 1;

    IF NOT EXISTS (SELECT 1 FROM @mercancias)
        THROW 50206, 'La solicitud debe tener mercancías.', 1;

    IF EXISTS (SELECT 1 FROM @mercancias WHERE peso_kg <= 0 OR volumen_m3 <= 0)
        THROW 50207, 'Peso y volumen deben ser positivos.', 1;

    IF EXISTS (
        SELECT 1
        FROM @mercancias AS m
        LEFT JOIN dbo.tipos_mercancia AS tm ON tm.tipo_mercancia_id = m.tipo_mercancia_id AND tm.activo = 1
        WHERE tm.tipo_mercancia_id IS NULL
    )
        THROW 50235, 'Algún tipo de mercancía indicado no existe o no está activo.', 1;

    IF NOT EXISTS (SELECT 1 FROM dbo.clientes WHERE cliente_id = @cliente_id AND activo = 1)
        THROW 50208, 'El cliente debe estar activo.', 1;

    SET @origen_nombre = NULLIF(LTRIM(RTRIM(@origen_nombre)), N'');
    SET @origen_direccion = NULLIF(LTRIM(RTRIM(@origen_direccion)), N'');
    SET @destino_nombre = NULLIF(LTRIM(RTRIM(@destino_nombre)), N'');
    SET @destino_direccion = NULLIF(LTRIM(RTRIM(@destino_direccion)), N'');
    SET @origen_guardar_frecuente = ISNULL(@origen_guardar_frecuente, 0);
    SET @destino_guardar_frecuente = ISNULL(@destino_guardar_frecuente, 0);

    DECLARE @orig_lat DECIMAL(9,6) = NULL, @orig_lon DECIMAL(9,6) = NULL;
    DECLARE @dest_lat DECIMAL(9,6) = NULL, @dest_lon DECIMAL(9,6) = NULL;
    DECLARE @orig_exists BIT = 0, @dest_exists BIT = 0;

    -- Validar extremo ORIGEN
    IF @orig_mode = 'GUARDADA'
    BEGIN
        IF @origen_id IS NULL OR @origen_id < 1
            THROW 50231, 'La ubicación de origen seleccionada no es válida.', 1;

        SELECT @orig_exists = 1, @orig_lat = latitud, @orig_lon = longitud
        FROM dbo.ubicaciones
        WHERE ubicacion_id = @origen_id AND activo = 1 AND es_reutilizable = 1;

        IF @orig_exists = 0
            THROW 50231, 'La ubicación de origen seleccionada no existe o no está activa.', 1;

        SET @origen_id_resuelto = @origen_id;
    END
    ELSE
    BEGIN
        IF @origen_direccion IS NULL
           OR @origen_latitud IS NULL OR @origen_longitud IS NULL
           OR @origen_latitud < -90 OR @origen_latitud > 90
           OR @origen_longitud < -180 OR @origen_longitud > 180
            THROW 50232, 'Indica una referencia descriptiva y un punto válido en el mapa para el origen.', 1;

        IF @origen_guardar_frecuente = 1
        BEGIN
            IF dbo.fn_tiene_permiso(@usuario, N'UBICACIONES_GESTIONAR') = 0
                THROW 50233, 'No tiene permiso para guardar ubicaciones en el catálogo frecuente.', 1;
            IF @origen_nombre IS NULL OR LEN(@origen_nombre) < 3
                THROW 50232, 'Indica un nombre de al menos 3 caracteres para guardar el origen como ubicación frecuente.', 1;
        END
        ELSE IF @origen_nombre IS NULL
        BEGIN
            SET @origen_nombre = LEFT(N'Punto de origen: ' + @origen_direccion, 150);
        END;

        SET @orig_lat = @origen_latitud;
        SET @orig_lon = @origen_longitud;
    END;

    -- Validar extremo DESTINO
    IF @dest_mode = 'GUARDADA'
    BEGIN
        IF @destino_id IS NULL OR @destino_id < 1
            THROW 50231, 'La ubicación de destino seleccionada no es válida.', 1;

        SELECT @dest_exists = 1, @dest_lat = latitud, @dest_lon = longitud
        FROM dbo.ubicaciones
        WHERE ubicacion_id = @destino_id AND activo = 1 AND es_reutilizable = 1;

        IF @dest_exists = 0
            THROW 50231, 'La ubicación de destino seleccionada no existe o no está activa.', 1;

        SET @destino_id_resuelto = @destino_id;
    END
    ELSE
    BEGIN
        IF @destino_direccion IS NULL
           OR @destino_latitud IS NULL OR @destino_longitud IS NULL
           OR @destino_latitud < -90 OR @destino_latitud > 90
           OR @destino_longitud < -180 OR @destino_longitud > 180
            THROW 50232, 'Indica una referencia descriptiva y un punto válido en el mapa para el destino.', 1;

        IF @destino_guardar_frecuente = 1
        BEGIN
            IF dbo.fn_tiene_permiso(@usuario, N'UBICACIONES_GESTIONAR') = 0
                THROW 50233, 'No tiene permiso para guardar ubicaciones en el catálogo frecuente.', 1;
            IF @destino_nombre IS NULL OR LEN(@destino_nombre) < 3
                THROW 50232, 'Indica un nombre de al menos 3 caracteres para guardar el destino como ubicación frecuente.', 1;
        END
        ELSE IF @destino_nombre IS NULL
        BEGIN
            SET @destino_nombre = LEFT(N'Punto de destino: ' + @destino_direccion, 150);
        END;

        SET @dest_lat = @destino_latitud;
        SET @dest_lon = @destino_longitud;
    END;

    -- Validación origen != destino por ID
    IF @orig_mode = 'GUARDADA' AND @dest_mode = 'GUARDADA' AND @origen_id = @destino_id
        THROW 50204, 'Origen y destino no pueden coincidir.', 1;

    -- Validación geográfica Haversine (tolerancia documentada: <= 25 metros)
    IF @orig_lat IS NOT NULL AND @orig_lon IS NOT NULL AND @dest_lat IS NOT NULL AND @dest_lon IS NOT NULL
    BEGIN
        DECLARE @dlat FLOAT = RADIANS(CONVERT(FLOAT, @dest_lat) - CONVERT(FLOAT, @orig_lat));
        DECLARE @dlon FLOAT = RADIANS(CONVERT(FLOAT, @dest_lon) - CONVERT(FLOAT, @orig_lon));
        DECLARE @a FLOAT = POWER(SIN(@dlat / 2.0), 2)
                         + COS(RADIANS(CONVERT(FLOAT, @orig_lat)))
                         * COS(RADIANS(CONVERT(FLOAT, @dest_lat)))
                         * POWER(SIN(@dlon / 2.0), 2);
        DECLARE @c FLOAT = 2.0 * ATN2(SQRT(@a), SQRT(CASE WHEN 1.0 - @a < 0.0 THEN 0.0 ELSE 1.0 - @a END));
        DECLARE @dist_m FLOAT = 6371000.0 * @c;

        IF @dist_m <= 25.0
            THROW 50234, 'El origen y el destino corresponden al mismo punto geográfico (tolerancia máxima: 25 metros).', 1;
    END;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION;

    BEGIN TRY
        IF @orig_mode = 'MAPA'
        BEGIN
            IF @origen_guardar_frecuente = 1
               AND EXISTS (
                   SELECT 1 FROM dbo.ubicaciones WITH (UPDLOCK, HOLDLOCK)
                   WHERE nombre = @origen_nombre AND es_reutilizable = 1
               )
                THROW 50504, 'Ya existe una ubicación frecuente registrada con el nombre indicado para el origen.', 1;

            INSERT INTO dbo.ubicaciones (nombre, direccion, latitud, longitud, activo, es_reutilizable)
            VALUES (@origen_nombre, @origen_direccion, @origen_latitud, @origen_longitud, 1, @origen_guardar_frecuente);

            SET @origen_id_resuelto = CONVERT(INT, SCOPE_IDENTITY());

            INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
            VALUES (
                @usuario, N'ubicaciones', CONVERT(NVARCHAR(100), @origen_id_resuelto), 'INSERT',
                NULL,
                CONCAT(
                    N'Nombre: ', @origen_nombre,
                    N'; Activo: 1; Reutilizable: ', CONVERT(NVARCHAR(1), @origen_guardar_frecuente),
                    CASE WHEN @origen_guardar_frecuente = 0 THEN N' (Solicitud)' ELSE N' (Catálogo)' END
                )
            );
        END;

        IF @dest_mode = 'MAPA'
        BEGIN
            IF @destino_guardar_frecuente = 1
               AND EXISTS (
                   SELECT 1 FROM dbo.ubicaciones WITH (UPDLOCK, HOLDLOCK)
                   WHERE nombre = @destino_nombre AND es_reutilizable = 1
               )
                THROW 50504, 'Ya existe una ubicación frecuente registrada con el nombre indicado para el destino.', 1;

            INSERT INTO dbo.ubicaciones (nombre, direccion, latitud, longitud, activo, es_reutilizable)
            VALUES (@destino_nombre, @destino_direccion, @destino_latitud, @destino_longitud, 1, @destino_guardar_frecuente);

            SET @destino_id_resuelto = CONVERT(INT, SCOPE_IDENTITY());

            INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
            VALUES (
                @usuario, N'ubicaciones', CONVERT(NVARCHAR(100), @destino_id_resuelto), 'INSERT',
                NULL,
                CONCAT(
                    N'Nombre: ', @destino_nombre,
                    N'; Activo: 1; Reutilizable: ', CONVERT(NVARCHAR(1), @destino_guardar_frecuente),
                    CASE WHEN @destino_guardar_frecuente = 0 THEN N' (Solicitud)' ELSE N' (Catálogo)' END
                )
            );
        END;

        IF @codigo IS NULL OR LTRIM(RTRIM(@codigo)) = N''
        BEGIN
            DECLARE @seq_sol INT = NEXT VALUE FOR dbo.seq_codigo_solicitud;
            SET @codigo = N'SOL-' + RIGHT(N'00000' + CAST(@seq_sol AS NVARCHAR(10)), 5);
        END;

        INSERT INTO dbo.solicitudes
        (
            codigo_solicitud, cliente_id, origen_id, destino_id,
            destinatario_nombre, destinatario_apellido1, destinatario_apellido2,
            destinatario_identificacion, destinatario_telefono,
            fecha_entrega_requerida, prioridad_calculada, prioridad_actual
        )
        VALUES
        (
            @codigo, @cliente_id, @origen_id_resuelto, @destino_id_resuelto,
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

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO
