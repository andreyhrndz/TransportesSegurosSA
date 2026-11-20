/* =========================================================================
   Migración 14: Adjuntos documentales privados (PDF/JPG/PNG) y compatibilidad URL
   =========================================================================
   Estrictamente aditiva, idempotente y retrocompatible con Migraciones 01-13:
   1. dbo.documentos: agrega origen_archivo ('EXTERNA'|'INTERNA', default 'EXTERNA'),
      storage_key, nombre_original, mime_type, tamano_bytes y sha256_hex.
      Preserva intactos todos los documentos previos basados en archivo_url.
   2. Restricciones CK_documentos_origen_archivo y CK_documentos_soporte_coherente,
      índice único filtrado UQ_documentos_storage_key e índice IX_documentos_solicitud_fecha.
   3. dbo.sp_registrar_documento: retrocompatible para URLs externas con auditoría.
   4. dbo.sp_registrar_documento_archivo: contrato seguro para adjuntos privados.
   5. dbo.sp_obtener_documento: lectura autorizada (DOCUMENTOS_VER) para descarga.
   6. dbo.sp_listar_documentos: agrega columnas aditivas de metadatos al final.
   ========================================================================= */

SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO

-- -------------------------------------------------------------------------
-- 1. COLUMNAS ADITIVAS EN dbo.documentos (PRESERVA FILAS HISTÓRICAS)
-- -------------------------------------------------------------------------
IF COL_LENGTH(N'dbo.documentos', N'origen_archivo') IS NULL
BEGIN
    ALTER TABLE dbo.documentos
        ADD origen_archivo VARCHAR(10) NOT NULL
            CONSTRAINT DF_documentos_origen_archivo DEFAULT ('EXTERNA');
END;
GO

IF COL_LENGTH(N'dbo.documentos', N'storage_key') IS NULL
BEGIN
    ALTER TABLE dbo.documentos ADD storage_key NVARCHAR(120) NULL;
END;
GO

IF COL_LENGTH(N'dbo.documentos', N'nombre_original') IS NULL
BEGIN
    ALTER TABLE dbo.documentos ADD nombre_original NVARCHAR(255) NULL;
END;
GO

IF COL_LENGTH(N'dbo.documentos', N'mime_type') IS NULL
BEGIN
    ALTER TABLE dbo.documentos ADD mime_type VARCHAR(100) NULL;
END;
GO

IF COL_LENGTH(N'dbo.documentos', N'tamano_bytes') IS NULL
BEGIN
    ALTER TABLE dbo.documentos ADD tamano_bytes BIGINT NULL;
END;
GO

IF COL_LENGTH(N'dbo.documentos', N'sha256_hex') IS NULL
BEGIN
    ALTER TABLE dbo.documentos ADD sha256_hex CHAR(64) NULL;
END;
GO

-- -------------------------------------------------------------------------
-- 2. RESTRICCIONES DE COHERENCIA E ÍNDICES (IDEMPOTENTES)
-- -------------------------------------------------------------------------
IF NOT EXISTS (
    SELECT 1 FROM sys.check_constraints
    WHERE parent_object_id = OBJECT_ID(N'dbo.documentos')
      AND name = N'CK_documentos_origen_archivo'
)
BEGIN
    ALTER TABLE dbo.documentos WITH CHECK
        ADD CONSTRAINT CK_documentos_origen_archivo
        CHECK (origen_archivo IN ('EXTERNA', 'INTERNA'));
END;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.check_constraints
    WHERE parent_object_id = OBJECT_ID(N'dbo.documentos')
      AND name = N'CK_documentos_soporte_coherente'
)
BEGIN
    ALTER TABLE dbo.documentos WITH CHECK
        ADD CONSTRAINT CK_documentos_soporte_coherente
        CHECK (
            (
                origen_archivo = 'EXTERNA'
                AND storage_key IS NULL
                AND nombre_original IS NULL
                AND mime_type IS NULL
                AND tamano_bytes IS NULL
                AND sha256_hex IS NULL
            )
            OR
            (
                origen_archivo = 'INTERNA'
                AND archivo_url IS NULL
                AND NULLIF(LTRIM(RTRIM(storage_key)), N'') IS NOT NULL
                AND NULLIF(LTRIM(RTRIM(nombre_original)), N'') IS NOT NULL
                AND mime_type IN ('application/pdf', 'image/jpeg', 'image/png')
                AND tamano_bytes BETWEEN 1 AND 10485760
                AND LEN(sha256_hex) = 64
            )
        );
END;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.documentos')
      AND name = N'UQ_documentos_storage_key'
)
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UQ_documentos_storage_key
        ON dbo.documentos (storage_key)
        WHERE storage_key IS NOT NULL;
END;
GO

IF NOT EXISTS (
    SELECT 1 FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.documentos')
      AND name = N'IX_documentos_solicitud_fecha'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_documentos_solicitud_fecha
        ON dbo.documentos (solicitud_id, fecha_registro DESC, documento_id DESC);
END;
GO

-- -------------------------------------------------------------------------
-- 3. dbo.sp_registrar_documento (RETROCOMPATIBLE PARA URLs EXTERNAS)
-- -------------------------------------------------------------------------
CREATE OR ALTER PROCEDURE dbo.sp_registrar_documento
    @solicitud_id INT,
    @tipo_documento_id INT,
    @numero_referencia NVARCHAR(100),
    @archivo_url NVARCHAR(500),
    @documento_id INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @usuario INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@usuario, N'DOCUMENTOS_REGISTRAR') = 0
        THROW 50406, 'Sin permiso para registrar documentos.', 1;

    SET @numero_referencia = NULLIF(LTRIM(RTRIM(@numero_referencia)), N'');
    SET @archivo_url = NULLIF(LTRIM(RTRIM(@archivo_url)), N'');

    IF @solicitud_id IS NULL OR NOT EXISTS (SELECT 1 FROM dbo.solicitudes WHERE solicitud_id = @solicitud_id)
        THROW 50431, 'La solicitud indicada no existe.', 1;

    IF @tipo_documento_id IS NULL OR NOT EXISTS (SELECT 1 FROM dbo.tipos_documento WHERE tipo_documento_id = @tipo_documento_id AND activo = 1)
        THROW 50432, 'El tipo de documento indicado no existe o no está activo.', 1;

    IF @numero_referencia IS NULL OR @archivo_url IS NULL
        THROW 50433, 'El número de referencia y la URL del documento son obligatorios.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION;

    BEGIN TRY
        INSERT INTO dbo.documentos
        (
            solicitud_id, tipo_documento_id, numero_referencia,
            fecha_documento, archivo_url, origen_archivo
        )
        VALUES
        (
            @solicitud_id, @tipo_documento_id, @numero_referencia,
            CONVERT(DATE, SYSUTCDATETIME()), @archivo_url, 'EXTERNA'
        );

        SET @documento_id = CONVERT(INT, SCOPE_IDENTITY());

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (
            @usuario,
            N'documentos',
            CONVERT(NVARCHAR(100), @documento_id),
            'INSERT',
            NULL,
            CONCAT(N'Solicitud #', @solicitud_id, N' · Enlace externo · Ref: ', LEFT(@numero_referencia, 60))
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
-- 4. dbo.sp_registrar_documento_archivo (ADJUNTOS PRIVADOS PDF/JPG/PNG)
-- -------------------------------------------------------------------------
CREATE OR ALTER PROCEDURE dbo.sp_registrar_documento_archivo
    @solicitud_id INT,
    @tipo_documento_id INT,
    @numero_referencia NVARCHAR(100),
    @storage_key NVARCHAR(120),
    @nombre_original NVARCHAR(255),
    @mime_type VARCHAR(100),
    @tamano_bytes BIGINT,
    @sha256_hex CHAR(64),
    @documento_id INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @usuario INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@usuario, N'DOCUMENTOS_REGISTRAR') = 0
        THROW 50406, 'Sin permiso para registrar documentos.', 1;

    SET @numero_referencia = NULLIF(LTRIM(RTRIM(@numero_referencia)), N'');
    SET @storage_key = NULLIF(LTRIM(RTRIM(@storage_key)), N'');
    SET @nombre_original = NULLIF(LTRIM(RTRIM(@nombre_original)), N'');
    SET @mime_type = LOWER(NULLIF(LTRIM(RTRIM(@mime_type)), ''));
    SET @sha256_hex = LOWER(NULLIF(LTRIM(RTRIM(@sha256_hex)), ''));

    IF @solicitud_id IS NULL OR NOT EXISTS (SELECT 1 FROM dbo.solicitudes WHERE solicitud_id = @solicitud_id)
        THROW 50431, 'La solicitud indicada no existe.', 1;

    IF @tipo_documento_id IS NULL OR NOT EXISTS (SELECT 1 FROM dbo.tipos_documento WHERE tipo_documento_id = @tipo_documento_id AND activo = 1)
        THROW 50432, 'El tipo de documento indicado no existe o no está activo.', 1;

    IF @numero_referencia IS NULL
        THROW 50433, 'El número de referencia del documento es obligatorio.', 1;

    IF @storage_key IS NULL
       OR @storage_key LIKE N'%..%'
       OR @storage_key LIKE N'%\%'
       OR LEFT(@storage_key, 1) = N'/'
       OR @nombre_original IS NULL
       OR @mime_type NOT IN ('application/pdf', 'image/jpeg', 'image/png')
       OR @tamano_bytes IS NULL
       OR @tamano_bytes < 1
       OR @tamano_bytes > 10485760
       OR @sha256_hex IS NULL
       OR LEN(@sha256_hex) <> 64
       OR @sha256_hex LIKE '%[^0-9a-f]%'
        THROW 50433, 'Los metadatos del archivo adjunto no cumplen los requisitos de seguridad.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION;

    BEGIN TRY
        IF EXISTS (SELECT 1 FROM dbo.documentos WITH (UPDLOCK, HOLDLOCK) WHERE storage_key = @storage_key)
            THROW 50434, 'Ya existe un archivo registrado con esa clave de almacenamiento.', 1;

        INSERT INTO dbo.documentos
        (
            solicitud_id, tipo_documento_id, numero_referencia,
            fecha_documento, archivo_url, origen_archivo,
            storage_key, nombre_original, mime_type, tamano_bytes, sha256_hex
        )
        VALUES
        (
            @solicitud_id, @tipo_documento_id, @numero_referencia,
            CONVERT(DATE, SYSUTCDATETIME()), NULL, 'INTERNA',
            @storage_key, @nombre_original, @mime_type, @tamano_bytes, @sha256_hex
        );

        SET @documento_id = CONVERT(INT, SCOPE_IDENTITY());

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (
            @usuario,
            N'documentos',
            CONVERT(NVARCHAR(100), @documento_id),
            'INSERT',
            NULL,
            CONCAT(N'Solicitud #', @solicitud_id, N' · Adjunto interno (', @mime_type, N', ', @tamano_bytes, N' bytes) · Ref: ', LEFT(@numero_referencia, 60))
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
-- 5. dbo.sp_obtener_documento (AUTORIZACIÓN DOCUMENTOS_VER PARA DESCARGA)
-- -------------------------------------------------------------------------
CREATE OR ALTER PROCEDURE dbo.sp_obtener_documento
    @documento_id INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@usuario_id, N'DOCUMENTOS_VER') = 0
        THROW 50103, N'No tiene permiso para consultar documentos.', 1;

    IF @documento_id IS NULL OR @documento_id < 1
        THROW 50102, N'Identificador de documento inválido.', 1;

    SELECT
        d.documento_id,
        d.solicitud_id,
        d.tipo_documento_id,
        td.nombre AS tipo_documento,
        d.numero_referencia,
        d.fecha_documento,
        d.archivo_url,
        d.origen_archivo,
        d.storage_key,
        d.nombre_original,
        d.mime_type,
        d.tamano_bytes,
        d.sha256_hex,
        d.fecha_registro
    FROM dbo.documentos AS d
    JOIN dbo.tipos_documento AS td ON td.tipo_documento_id = d.tipo_documento_id
    WHERE d.documento_id = @documento_id;

    IF @@ROWCOUNT = 0
        THROW 50101, N'Documento no encontrado.', 1;
END;
GO

-- -------------------------------------------------------------------------
-- 6. dbo.sp_listar_documentos (EXTENDIDO ADITIVAMENTE AL FINAL DEL SELECT)
-- -------------------------------------------------------------------------
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
        d.documento_id,
        d.solicitud_id,
        d.numero_referencia,
        d.fecha_documento,
        d.archivo_url,
        d.observaciones,
        d.fecha_registro,
        td.tipo_documento_id,
        td.nombre AS tipo_documento,
        d.origen_archivo,
        d.nombre_original,
        d.mime_type,
        d.tamano_bytes
    FROM dbo.documentos AS d
    JOIN dbo.tipos_documento AS td ON td.tipo_documento_id = d.tipo_documento_id
    WHERE d.solicitud_id = @solicitud_id
    ORDER BY d.fecha_registro DESC, d.documento_id DESC
    OFFSET @offset ROWS FETCH NEXT @por_pagina ROWS ONLY;
END;
GO

-- -------------------------------------------------------------------------
-- 7. dbo.sp_listar_tipos_documento (HABILITA MÍNIMO PRIVILEGIO DOCUMENTOS_REGISTRAR)
-- -------------------------------------------------------------------------
CREATE OR ALTER PROCEDURE dbo.sp_listar_tipos_documento
    @solo_activos BIT = 1
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@usuario_id, N'CATALOGOS_VER') = 0
       AND dbo.fn_tiene_permiso(@usuario_id, N'DOCUMENTOS_REGISTRAR') = 0
        THROW 50103, N'No tiene permiso para consultar tipos de documento.', 1;

    SELECT tipo_documento_id, nombre, descripcion, activo
    FROM dbo.tipos_documento
    WHERE @solo_activos IS NULL OR activo = @solo_activos
    ORDER BY nombre, tipo_documento_id;
END;
GO
