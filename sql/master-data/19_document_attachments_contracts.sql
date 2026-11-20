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

-- -------------------------------------------------------------------------
-- 8. Columnas aditivas, restricciones e índice único en dbo.entregas
--    (cero UPDATE sobre filas históricas => cero disparos de tr_auditar_entregas)
-- -------------------------------------------------------------------------
IF COL_LENGTH(N'dbo.entregas', N'comprobante_origen') IS NULL
    ALTER TABLE dbo.entregas ADD comprobante_origen VARCHAR(10) NULL;
GO

IF COL_LENGTH(N'dbo.entregas', N'comprobante_storage_key') IS NULL
    ALTER TABLE dbo.entregas ADD comprobante_storage_key NVARCHAR(120) NULL;
GO

IF COL_LENGTH(N'dbo.entregas', N'comprobante_nombre_original') IS NULL
    ALTER TABLE dbo.entregas ADD comprobante_nombre_original NVARCHAR(255) NULL;
GO

IF COL_LENGTH(N'dbo.entregas', N'comprobante_mime_type') IS NULL
    ALTER TABLE dbo.entregas ADD comprobante_mime_type VARCHAR(100) NULL;
GO

IF COL_LENGTH(N'dbo.entregas', N'comprobante_tamano_bytes') IS NULL
    ALTER TABLE dbo.entregas ADD comprobante_tamano_bytes BIGINT NULL;
GO

IF COL_LENGTH(N'dbo.entregas', N'comprobante_sha256_hex') IS NULL
    ALTER TABLE dbo.entregas ADD comprobante_sha256_hex CHAR(64) NULL;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.check_constraints
    WHERE parent_object_id = OBJECT_ID(N'dbo.entregas')
      AND name = N'CK_entregas_comprobante_origen'
)
BEGIN
    ALTER TABLE dbo.entregas WITH CHECK
    ADD CONSTRAINT CK_entregas_comprobante_origen
        CHECK (comprobante_origen IS NULL OR comprobante_origen IN ('EXTERNA', 'INTERNA'));
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.check_constraints
    WHERE parent_object_id = OBJECT_ID(N'dbo.entregas')
      AND name = N'CK_entregas_comprobante_coherente'
)
BEGIN
    ALTER TABLE dbo.entregas WITH CHECK
    ADD CONSTRAINT CK_entregas_comprobante_coherente
        CHECK (
            (
                estado = 'FALLIDA'
                AND comprobante_origen IS NULL
                AND comprobante_storage_key IS NULL
                AND comprobante_nombre_original IS NULL
                AND comprobante_mime_type IS NULL
                AND comprobante_tamano_bytes IS NULL
                AND comprobante_sha256_hex IS NULL
            )
            OR (
                estado = 'ENTREGADA'
                AND ISNULL(comprobante_origen, 'EXTERNA') = 'EXTERNA'
                AND comprobante_storage_key IS NULL
                AND comprobante_nombre_original IS NULL
                AND comprobante_mime_type IS NULL
                AND comprobante_tamano_bytes IS NULL
                AND comprobante_sha256_hex IS NULL
            )
            OR (
                estado = 'ENTREGADA'
                AND comprobante_origen = 'INTERNA'
                AND comprobante_url IS NULL
                AND NULLIF(LTRIM(RTRIM(comprobante_storage_key)), N'') IS NOT NULL
                AND NULLIF(LTRIM(RTRIM(comprobante_nombre_original)), N'') IS NOT NULL
                AND comprobante_mime_type IN ('application/pdf', 'image/jpeg', 'image/png')
                AND comprobante_tamano_bytes > 0
                AND comprobante_tamano_bytes <= 10485760
                AND comprobante_sha256_hex IS NOT NULL
                AND LEN(comprobante_sha256_hex) = 64
                AND comprobante_sha256_hex NOT LIKE '%[^0-9a-fA-F]%'
            )
        );
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID(N'dbo.entregas')
      AND name = N'UQ_entregas_comprobante_storage_key'
)
BEGIN
    CREATE UNIQUE NONCLUSTERED INDEX UQ_entregas_comprobante_storage_key
        ON dbo.entregas (comprobante_storage_key)
        WHERE comprobante_storage_key IS NOT NULL;
END;
GO

-- -------------------------------------------------------------------------
-- 9. dbo.sp_registrar_entrega (RETROCOMPATIBLE + COMPROBANTE LOCAL SEGURO)
-- -------------------------------------------------------------------------
CREATE OR ALTER PROCEDURE dbo.sp_registrar_entrega
    @viaje_solicitud_id INT,
    @estado VARCHAR(15),
    @receptor_nombre NVARCHAR(150) = NULL,
    @receptor_identificacion NVARCHAR(30) = NULL,
    @receptor_telefono NVARCHAR(30) = NULL,
    @comprobante_url NVARCHAR(500) = NULL,
    @motivo_fallo NVARCHAR(500) = NULL,
    @comprobante_storage_key NVARCHAR(120) = NULL,
    @comprobante_nombre_original NVARCHAR(255) = NULL,
    @comprobante_mime_type VARCHAR(100) = NULL,
    @comprobante_tamano_bytes BIGINT = NULL,
    @comprobante_sha256_hex CHAR(64) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @usuario INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    DECLARE @solicitud INT;
    DECLARE @url_clean NVARCHAR(500) = NULLIF(LTRIM(RTRIM(@comprobante_url)), N'');
    DECLARE @key_clean NVARCHAR(120) = NULLIF(LTRIM(RTRIM(@comprobante_storage_key)), N'');
    DECLARE @name_clean NVARCHAR(255) = NULLIF(LTRIM(RTRIM(@comprobante_nombre_original)), N'');
    DECLARE @mime_clean VARCHAR(100) = LOWER(NULLIF(LTRIM(RTRIM(@comprobante_mime_type)), ''));
    DECLARE @sha_clean CHAR(64) = LOWER(NULLIF(LTRIM(RTRIM(@comprobante_sha256_hex)), ''));
    DECLARE @has_internal BIT = CASE
        WHEN @key_clean IS NOT NULL OR @name_clean IS NOT NULL OR @mime_clean IS NOT NULL
             OR @comprobante_tamano_bytes IS NOT NULL OR @sha_clean IS NOT NULL
        THEN 1 ELSE 0
    END;
    DECLARE @origen VARCHAR(10) = NULL;

    IF dbo.fn_tiene_permiso(@usuario, N'ENTREGAS_REGISTRAR') = 0
        THROW 50319, N'No tiene permiso para registrar entregas.', 1;

    IF @estado NOT IN ('ENTREGADA', 'FALLIDA')
        THROW 50320, N'Estado de entrega inválido.', 1;

    IF @estado = 'ENTREGADA'
    BEGIN
        IF NULLIF(LTRIM(RTRIM(@receptor_nombre)), N'') IS NULL
            THROW 50321, N'La entrega exige receptor y comprobante.', 1;

        -- Exclusión mutua estricta: archivo local O URL externa, nunca ambos ni ninguno
        IF (@url_clean IS NOT NULL AND @has_internal = 1)
           OR (@url_clean IS NULL AND @has_internal = 0)
            THROW 50321, N'La entrega exige exactamente un comprobante (archivo local o URL externa).', 1;

        IF @has_internal = 1
        BEGIN
            IF @key_clean IS NULL
               OR @key_clean LIKE N'%..%'
               OR @key_clean LIKE N'%\%'
               OR LEFT(@key_clean, 1) = N'/'
                THROW 50433, N'La clave de almacenamiento del comprobante no es válida.', 1;

            IF @name_clean IS NULL
                THROW 50433, N'El nombre del comprobante es obligatorio.', 1;

            IF @mime_clean NOT IN ('application/pdf', 'image/jpeg', 'image/png')
                THROW 50433, N'Solo se admiten comprobantes PDF, JPG o PNG.', 1;

            IF @comprobante_tamano_bytes IS NULL OR @comprobante_tamano_bytes <= 0 OR @comprobante_tamano_bytes > 10485760
                THROW 50433, N'El tamaño del comprobante debe ser mayor a 0 y no superar 10 MiB.', 1;

            IF @sha_clean IS NULL OR LEN(@sha_clean) <> 64 OR @sha_clean LIKE '%[^0-9a-f]%'
                THROW 50433, N'El hash SHA-256 del comprobante no es válido.', 1;

            SET @origen = 'INTERNA';
        END
        ELSE
        BEGIN
            SET @origen = 'EXTERNA';
        END;
    END
    ELSE
    BEGIN
        IF NULLIF(LTRIM(RTRIM(@motivo_fallo)), N'') IS NULL
            THROW 50322, N'Debe justificar la entrega fallida.', 1;

        IF @has_internal = 1 OR @url_clean IS NOT NULL
            THROW 50433, N'Una entrega fallida no admite comprobante adjunto ni URL.', 1;

        SET @origen = NULL;
    END;

    BEGIN TRY
        BEGIN TRANSACTION;

        SELECT @solicitud = vs.solicitud_id
        FROM dbo.viaje_solicitudes AS vs WITH (UPDLOCK, HOLDLOCK)
        JOIN dbo.viajes AS v ON v.viaje_id = vs.viaje_id
        WHERE vs.viaje_solicitud_id = @viaje_solicitud_id
          AND vs.estado = 'EN_TRANSITO'
          AND v.estado = 'EN_CURSO';

        IF @solicitud IS NULL
            THROW 50323, N'La solicitud no está en un viaje en curso.', 1;

        IF EXISTS (SELECT 1 FROM dbo.entregas WHERE viaje_solicitud_id = @viaje_solicitud_id)
            THROW 50324, N'La solicitud ya tiene una entrega registrada.', 1;

        IF @key_clean IS NOT NULL
           AND EXISTS (SELECT 1 FROM dbo.entregas WHERE comprobante_storage_key = @key_clean)
            THROW 50434, N'Ya existe un comprobante con la misma clave de almacenamiento.', 1;

        INSERT INTO dbo.entregas
        (
            viaje_solicitud_id,
            estado,
            receptor_nombre,
            receptor_identificacion,
            receptor_telefono,
            fecha_recepcion,
            comprobante_url,
            registrado_por_usuario_id,
            motivo_fallo,
            comprobante_origen,
            comprobante_storage_key,
            comprobante_nombre_original,
            comprobante_mime_type,
            comprobante_tamano_bytes,
            comprobante_sha256_hex
        )
        VALUES
        (
            @viaje_solicitud_id,
            @estado,
            CASE WHEN @estado = 'ENTREGADA' THEN LTRIM(RTRIM(@receptor_nombre)) ELSE NULL END,
            CASE WHEN @estado = 'ENTREGADA' THEN NULLIF(LTRIM(RTRIM(@receptor_identificacion)), N'') ELSE NULL END,
            CASE WHEN @estado = 'ENTREGADA' THEN NULLIF(LTRIM(RTRIM(@receptor_telefono)), N'') ELSE NULL END,
            CASE WHEN @estado = 'ENTREGADA' THEN SYSUTCDATETIME() ELSE NULL END,
            CASE WHEN @origen = 'EXTERNA' THEN @url_clean ELSE NULL END,
            @usuario,
            CASE WHEN @estado = 'FALLIDA' THEN LTRIM(RTRIM(@motivo_fallo)) ELSE NULL END,
            @origen,
            CASE WHEN @origen = 'INTERNA' THEN @key_clean ELSE NULL END,
            CASE WHEN @origen = 'INTERNA' THEN @name_clean ELSE NULL END,
            CASE WHEN @origen = 'INTERNA' THEN @mime_clean ELSE NULL END,
            CASE WHEN @origen = 'INTERNA' THEN @comprobante_tamano_bytes ELSE NULL END,
            CASE WHEN @origen = 'INTERNA' THEN @sha_clean ELSE NULL END
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

-- -------------------------------------------------------------------------
-- 10. dbo.sp_obtener_comprobante_entrega (DESCARGA AUTORIZADA CON ENTREGAS_VER)
-- -------------------------------------------------------------------------
CREATE OR ALTER PROCEDURE dbo.sp_obtener_comprobante_entrega
    @entrega_id INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));

    IF dbo.fn_tiene_permiso(@usuario_id, N'ENTREGAS_VER') = 0
        THROW 50103, N'No tiene permiso para consultar comprobantes de entrega.', 1;

    IF @entrega_id IS NULL OR @entrega_id < 1
        THROW 50102, N'Identificador de entrega inválido.', 1;

    SELECT
        e.entrega_id,
        e.viaje_solicitud_id,
        vs.viaje_id,
        s.solicitud_id,
        s.codigo_solicitud,
        e.estado,
        CASE WHEN e.estado = 'ENTREGADA' THEN ISNULL(e.comprobante_origen, 'EXTERNA') ELSE NULL END AS comprobante_origen,
        e.comprobante_url,
        e.comprobante_storage_key,
        e.comprobante_nombre_original,
        e.comprobante_mime_type,
        e.comprobante_tamano_bytes,
        e.comprobante_sha256_hex,
        e.fecha_resultado
    FROM dbo.entregas AS e
    JOIN dbo.viaje_solicitudes AS vs ON vs.viaje_solicitud_id = e.viaje_solicitud_id
    JOIN dbo.solicitudes AS s ON s.solicitud_id = vs.solicitud_id
    WHERE e.entrega_id = @entrega_id;

    IF @@ROWCOUNT = 0
        THROW 50101, N'Entrega no encontrada.', 1;
END;
GO

-- -------------------------------------------------------------------------
-- 11. dbo.sp_listar_entregas (EXTENDIDO ADITIVAMENTE AL FINAL DEL SELECT)
-- -------------------------------------------------------------------------
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
        e.entrega_id,
        e.estado,
        e.fecha_resultado,
        e.receptor_nombre,
        e.receptor_identificacion,
        e.receptor_telefono,
        e.fecha_recepcion,
        e.comprobante_url,
        e.motivo_fallo,
        e.observaciones,
        vs.viaje_solicitud_id,
        vs.viaje_id,
        s.solicitud_id,
        s.codigo_solicitud,
        CASE WHEN e.estado = 'ENTREGADA' THEN ISNULL(e.comprobante_origen, 'EXTERNA') ELSE NULL END AS comprobante_origen,
        e.comprobante_nombre_original,
        e.comprobante_mime_type,
        e.comprobante_tamano_bytes
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

-- -------------------------------------------------------------------------
-- 12. dbo.sp_obtener_destinatario_asignacion (PRECARGA DE RECEPTOR EN ENTREGA)
-- -------------------------------------------------------------------------
CREATE OR ALTER PROCEDURE dbo.sp_obtener_destinatario_asignacion
    @viaje_solicitud_id INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));

    IF dbo.fn_tiene_permiso(@usuario_id, N'ENTREGAS_REGISTRAR') = 0
        THROW 50319, N'No tiene permiso para registrar entregas.', 1;

    IF @viaje_solicitud_id IS NULL OR @viaje_solicitud_id < 1
        THROW 50102, N'Identificador de asignación inválido.', 1;

    SELECT
        vs.viaje_solicitud_id,
        vs.viaje_id,
        v.codigo_viaje,
        v.estado AS estado_viaje,
        vs.estado AS estado_asignacion,
        s.solicitud_id,
        s.codigo_solicitud,
        s.destinatario_nombre,
        s.destinatario_apellido1,
        s.destinatario_apellido2,
        LEFT(LTRIM(RTRIM(
            CONCAT(
                LTRIM(RTRIM(s.destinatario_nombre)),
                CASE WHEN NULLIF(LTRIM(RTRIM(s.destinatario_apellido1)), N'') IS NOT NULL THEN N' ' + LTRIM(RTRIM(s.destinatario_apellido1)) ELSE N'' END,
                CASE WHEN NULLIF(LTRIM(RTRIM(s.destinatario_apellido2)), N'') IS NOT NULL THEN N' ' + LTRIM(RTRIM(s.destinatario_apellido2)) ELSE N'' END
            )
        )), 150) AS destinatario_nombre_completo,
        s.destinatario_identificacion,
        s.destinatario_telefono
    FROM dbo.viaje_solicitudes AS vs
    JOIN dbo.viajes AS v ON v.viaje_id = vs.viaje_id
    JOIN dbo.solicitudes AS s ON s.solicitud_id = vs.solicitud_id
    WHERE vs.viaje_solicitud_id = @viaje_solicitud_id;

    IF @@ROWCOUNT = 0
        THROW 50101, N'Asignación de entrega no encontrada.', 1;
END;
GO
