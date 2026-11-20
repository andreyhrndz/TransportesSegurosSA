/* =========================================================================
   Rollback NO DESTRUCTIVO de Migración 14 (Adjuntos documentales privados)
   =========================================================================
   Política segura de reversión:
   - Revierte los procedimientos almacenados a su firma/comportamiento previo
     a Migración 14 y elimina sp_registrar_documento_archivo y sp_obtener_documento.
   - NO elimina filas ni columnas si existen registros con origen_archivo = 'INTERNA'
     para evitar pérdida irreversible de metadatos y evidencia documental.
   - Si NO existen filas con origen_archivo = 'INTERNA' (antes de que haya adjuntos
     subidos), el bloque condicional final remueve limpiamente índices, CHECKs y
     columnas aditivas dejando dbo.documentos idéntica a Migración 13.
   ========================================================================= */

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

DROP PROCEDURE IF EXISTS dbo.sp_obtener_documento;
GO
DROP PROCEDURE IF EXISTS dbo.sp_registrar_documento_archivo;
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

CREATE OR ALTER PROCEDURE dbo.sp_listar_documentos
    @solicitud_id INT,
    @pagina INT = 1,
    @por_pagina INT = 20,
    @total_registros INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    DECLARE @offset BIGINT;

    IF dbo.fn_tiene_permiso(@usuario_id, N'DOCUMENTOS_VER') = 0
        THROW 50103, N'No tiene permiso para consultar documentos.', 1;
    IF @solicitud_id IS NULL OR @solicitud_id < 1 OR @pagina < 1 OR @por_pagina NOT BETWEEN 1 AND 100
        THROW 50102, N'Parámetros inválidos.', 1;

    SET @offset = CONVERT(BIGINT, @pagina - 1) * @por_pagina;

    SELECT @total_registros = COUNT(*)
    FROM dbo.documentos
    WHERE solicitud_id = @solicitud_id;

    SELECT
        d.documento_id, d.solicitud_id, d.numero_referencia, d.fecha_documento,
        d.archivo_url, d.observaciones, d.fecha_registro,
        td.tipo_documento_id, td.nombre AS tipo_documento
    FROM dbo.documentos AS d
    JOIN dbo.tipos_documento AS td ON td.tipo_documento_id = d.tipo_documento_id
    WHERE d.solicitud_id = @solicitud_id
    ORDER BY d.fecha_registro DESC, d.documento_id DESC
    OFFSET @offset ROWS FETCH NEXT @por_pagina ROWS ONLY;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_listar_tipos_documento
    @solo_activos BIT = 1
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@usuario_id, N'CATALOGOS_VER') = 0
        THROW 50103, N'No tiene permiso para consultar catálogos.', 1;

    SELECT tipo_documento_id, nombre, descripcion, activo
    FROM dbo.tipos_documento
    WHERE @solo_activos IS NULL OR activo = @solo_activos
    ORDER BY nombre, tipo_documento_id;
END;
GO

-- Remoción condicional de columnas SOLO si no existen adjuntos internos (cero pérdida de datos)
IF COL_LENGTH(N'dbo.documentos', N'origen_archivo') IS NOT NULL
BEGIN
    DECLARE @internos INT = 0;
    EXEC sp_executesql N'SELECT @c = COUNT(*) FROM dbo.documentos WHERE origen_archivo = ''INTERNA'';', N'@c INT OUTPUT', @c = @internos OUTPUT;

    IF @internos = 0
    BEGIN
        IF EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.documentos') AND name = N'UQ_documentos_storage_key')
            DROP INDEX UQ_documentos_storage_key ON dbo.documentos;
        IF EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.documentos') AND name = N'IX_documentos_solicitud_fecha')
            DROP INDEX IX_documentos_solicitud_fecha ON dbo.documentos;
        IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE parent_object_id = OBJECT_ID(N'dbo.documentos') AND name = N'CK_documentos_soporte_coherente')
            ALTER TABLE dbo.documentos DROP CONSTRAINT CK_documentos_soporte_coherente;
        IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE parent_object_id = OBJECT_ID(N'dbo.documentos') AND name = N'CK_documentos_origen_archivo')
            ALTER TABLE dbo.documentos DROP CONSTRAINT CK_documentos_origen_archivo;
        IF EXISTS (SELECT 1 FROM sys.default_constraints WHERE parent_object_id = OBJECT_ID(N'dbo.documentos') AND name = N'DF_documentos_origen_archivo')
            ALTER TABLE dbo.documentos DROP CONSTRAINT DF_documentos_origen_archivo;

        ALTER TABLE dbo.documentos
            DROP COLUMN sha256_hex, tamano_bytes, mime_type, nombre_original, storage_key, origen_archivo;
        PRINT 'Rollback 14 completo: columnas aditivas removidas (0 adjuntos internos presentes).';
    END
    ELSE
    BEGIN
        PRINT 'Rollback 14 seguro (no destructivo): procedimientos revertidos; columnas preservadas porque existen adjuntos internos.';
    END;
END;
GO

-- Reversión de contratos y columnas condicionales en dbo.entregas
DROP PROCEDURE IF EXISTS dbo.sp_obtener_comprobante_entrega;
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

        UPDATE dbo.viaje_solicitudes SET estado = @estado WHERE viaje_solicitud_id = @viaje_solicitud_id;
        UPDATE dbo.solicitudes
        SET estado = CASE WHEN @estado = 'ENTREGADA' THEN 'ENTREGADA' ELSE 'ENTREGA_FALLIDA' END,
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

CREATE OR ALTER PROCEDURE dbo.sp_listar_entregas
    @estado VARCHAR(15) = NULL,
    @viaje_id INT = NULL,
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

    IF dbo.fn_tiene_permiso(@usuario_id, N'ENTREGAS_VER') = 0
        THROW 50103, N'No tiene permiso para consultar entregas.', 1;
    IF @pagina < 1 OR @por_pagina NOT BETWEEN 1 AND 100 OR (@desde IS NOT NULL AND @hasta IS NOT NULL AND @desde > @hasta)
        THROW 50102, N'Filtros o paginación inválidos.', 1;
    IF @estado IS NOT NULL AND @estado NOT IN ('ENTREGADA', 'FALLIDA')
        THROW 50102, N'Estado de entrega inválido.', 1;

    SET @offset = CONVERT(BIGINT, @pagina - 1) * @por_pagina;

    SELECT @total_registros = COUNT(*)
    FROM dbo.entregas AS e
    JOIN dbo.viaje_solicitudes AS vs ON vs.viaje_solicitud_id = e.viaje_solicitud_id
    WHERE (@estado IS NULL OR e.estado = @estado)
      AND (@viaje_id IS NULL OR vs.viaje_id = @viaje_id)
      AND (@desde IS NULL OR e.fecha_resultado >= @desde)
      AND (@hasta IS NULL OR e.fecha_resultado < DATEADD(DAY, 1, @hasta));

    SELECT
        e.entrega_id, e.estado, e.fecha_resultado, e.receptor_nombre,
        e.receptor_identificacion, e.receptor_telefono, e.fecha_recepcion,
        e.comprobante_url, e.motivo_fallo, e.observaciones,
        vs.viaje_solicitud_id, vs.viaje_id, s.solicitud_id, s.codigo_solicitud
    FROM dbo.entregas AS e
    JOIN dbo.viaje_solicitudes AS vs ON vs.viaje_solicitud_id = e.viaje_solicitud_id
    JOIN dbo.solicitudes AS s ON s.solicitud_id = vs.solicitud_id
    WHERE (@estado IS NULL OR e.estado = @estado)
      AND (@viaje_id IS NULL OR vs.viaje_id = @viaje_id)
      AND (@desde IS NULL OR e.fecha_resultado >= @desde)
      AND (@hasta IS NULL OR e.fecha_resultado < DATEADD(DAY, 1, @hasta))
    ORDER BY e.fecha_resultado DESC, e.entrega_id DESC
    OFFSET @offset ROWS FETCH NEXT @por_pagina ROWS ONLY;
END;
GO

IF COL_LENGTH(N'dbo.entregas', N'comprobante_origen') IS NOT NULL
BEGIN
    DECLARE @ent_internos INT = 0;
    EXEC sp_executesql N'SELECT @c = COUNT(*) FROM dbo.entregas WHERE comprobante_origen = ''INTERNA'';', N'@c INT OUTPUT', @c = @ent_internos OUTPUT;

    IF @ent_internos = 0
    BEGIN
        IF EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(N'dbo.entregas') AND name = N'UQ_entregas_comprobante_storage_key')
            DROP INDEX UQ_entregas_comprobante_storage_key ON dbo.entregas;
        IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE parent_object_id = OBJECT_ID(N'dbo.entregas') AND name = N'CK_entregas_comprobante_coherente')
            ALTER TABLE dbo.entregas DROP CONSTRAINT CK_entregas_comprobante_coherente;
        IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE parent_object_id = OBJECT_ID(N'dbo.entregas') AND name = N'CK_entregas_comprobante_origen')
            ALTER TABLE dbo.entregas DROP CONSTRAINT CK_entregas_comprobante_origen;

        ALTER TABLE dbo.entregas
            DROP COLUMN comprobante_sha256_hex, comprobante_tamano_bytes, comprobante_mime_type, comprobante_nombre_original, comprobante_storage_key, comprobante_origen;
        PRINT 'Rollback 14 entregas completo: columnas aditivas removidas (0 comprobantes internos presentes).';
    END
    ELSE
    BEGIN
        PRINT 'Rollback 14 entregas seguro (no destructivo): procedimientos revertidos; columnas preservadas porque existen comprobantes internos.';
    END;
END;
GO
