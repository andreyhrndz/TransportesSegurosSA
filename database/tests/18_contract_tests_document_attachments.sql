/* =========================================================================
   Suite de contratos T-SQL 18: Adjuntos documentales privados (Migración 14)
   =========================================================================
   Ejecutar EXCLUSIVAMENTE en TransportesSegurosSA_Test.
   ========================================================================= */

SET NOCOUNT ON;
SET XACT_ABORT OFF;

IF DB_NAME() <> N'TransportesSegurosSA_Test'
    THROW 50999, N'BLOQUEADO: Esta suite solo puede ejecutarse en TransportesSegurosSA_Test.', 1;

DECLARE @admin_id INT = (SELECT TOP (1) usuario_id FROM dbo.usuarios WHERE activo = 1 ORDER BY usuario_id);
DECLARE @solicitud_id INT = (SELECT TOP (1) solicitud_id FROM dbo.solicitudes ORDER BY solicitud_id);
DECLARE @tipo_doc_id INT = (SELECT TOP (1) tipo_documento_id FROM dbo.tipos_documento WHERE activo = 1 ORDER BY tipo_documento_id);
DECLARE @max_aud_id BIGINT = ISNULL((SELECT MAX(auditoria_id) FROM dbo.auditorias), 0);
DECLARE @tag NVARCHAR(20) = RIGHT(REPLACE(CONVERT(NVARCHAR(36), NEWID()), N'-', N''), 10);

DECLARE @doc_int INT = NULL, @doc_ext INT = NULL, @noperm_uid INT = NULL;
DECLARE @key1 NVARCHAR(120) = N'2026/11/' + LOWER(REPLACE(CONVERT(NVARCHAR(36), NEWID()), N'-', N'') + REPLACE(CONVERT(NVARCHAR(36), NEWID()), N'-', N'')) + N'.pdf';
DECLARE @sha1 CHAR(64) = REPLICATE('a', 64);

BEGIN TRY
    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @admin_id;

    -- 1. Preservar filas previas de URL como EXTERNA sin pérdida
    IF EXISTS (SELECT 1 FROM dbo.documentos WHERE documento_id = 1 AND origen_archivo <> 'EXTERNA')
        THROW 50980, N'Los documentos previos deben conservar origen_archivo = EXTERNA.', 1;
    PRINT '1. [OK] Documento histórico #1 preservado como EXTERNA.';

    -- 2. Registrar adjunto interno válido vía sp_registrar_documento_archivo
    DECLARE @ref_int NVARCHAR(100) = N'DOCINT_' + @tag;
    EXEC dbo.sp_registrar_documento_archivo
        @solicitud_id = @solicitud_id,
        @tipo_documento_id = @tipo_doc_id,
        @numero_referencia = @ref_int,
        @storage_key = @key1,
        @nombre_original = N'factura_prueba.pdf',
        @mime_type = 'application/pdf',
        @tamano_bytes = 2048,
        @sha256_hex = @sha1,
        @documento_id = @doc_int OUTPUT;

    IF @doc_int IS NULL OR @doc_int < 1
        THROW 50981, N'sp_registrar_documento_archivo no devolvió documento_id.', 1;

    -- Verificar sp_obtener_documento y sp_listar_documentos
    DECLARE @det TABLE (
        documento_id INT, solicitud_id INT, tipo_documento_id INT, tipo_documento NVARCHAR(100),
        numero_referencia NVARCHAR(100), fecha_documento DATE, archivo_url NVARCHAR(500),
        origen_archivo VARCHAR(10), storage_key NVARCHAR(120), nombre_original NVARCHAR(255),
        mime_type VARCHAR(100), tamano_bytes BIGINT, sha256_hex CHAR(64), fecha_registro DATETIME2
    );
    INSERT INTO @det EXEC dbo.sp_obtener_documento @documento_id = @doc_int;
    IF NOT EXISTS (SELECT 1 FROM @det WHERE origen_archivo = 'INTERNA' AND storage_key = @key1 AND sha256_hex = @sha1 AND archivo_url IS NULL)
        THROW 50982, N'sp_obtener_documento no retornó metadatos esperados.', 1;

    IF NOT EXISTS (SELECT 1 FROM dbo.auditorias WHERE auditoria_id > @max_aud_id AND entidad = N'documentos' AND registro_id = CONVERT(NVARCHAR(100), @doc_int) AND accion = 'INSERT')
        THROW 50983, N'sp_registrar_documento_archivo debe auditar INSERT en dbo.auditorias.', 1;

    -- 3. Registrar URL externa retrocompatible vía sp_registrar_documento
    DECLARE @ref_ext NVARCHAR(100) = N'DOCEXT_' + @tag;
    EXEC dbo.sp_registrar_documento
        @solicitud_id = @solicitud_id,
        @tipo_documento_id = @tipo_doc_id,
        @numero_referencia = @ref_ext,
        @archivo_url = N'https://example.test/guia-18.pdf',
        @documento_id = @doc_ext OUTPUT;

    IF (SELECT origen_archivo FROM dbo.documentos WHERE documento_id = @doc_ext) <> 'EXTERNA'
        THROW 50984, N'sp_registrar_documento debe persistir origen_archivo = EXTERNA.', 1;

    DECLARE @tot INT = 0;
    DECLARE @list TABLE (
        documento_id INT, solicitud_id INT, numero_referencia NVARCHAR(100), fecha_documento DATE,
        archivo_url NVARCHAR(500), observaciones NVARCHAR(500), fecha_registro DATETIME2,
        tipo_documento_id INT, tipo_documento NVARCHAR(100),
        origen_archivo VARCHAR(10), nombre_original NVARCHAR(255), mime_type VARCHAR(100), tamano_bytes BIGINT
    );
    INSERT INTO @list EXEC dbo.sp_listar_documentos @solicitud_id = @solicitud_id, @pagina = 1, @por_pagina = 50, @total_registros = @tot OUTPUT;
    IF NOT EXISTS (SELECT 1 FROM @list WHERE documento_id = @doc_int AND origen_archivo = 'INTERNA')
       OR NOT EXISTS (SELECT 1 FROM @list WHERE documento_id = @doc_ext AND origen_archivo = 'EXTERNA')
        THROW 50985, N'sp_listar_documentos debe devolver tanto adjuntos INTERNA como enlaces EXTERNA.', 1;

    PRINT '2. [OK] Alta interna, alta externa retrocompatible, lectura, listado y auditoría verificados.';

    -- 4. Negativas: MIME inválido, tamaño >10 MiB, traversal en storage_key, duplicado storage_key, inexistente
    DECLARE @err INT = 0, @tmp INT = NULL;
    BEGIN TRY
        EXEC dbo.sp_registrar_documento_archivo @solicitud_id=@solicitud_id, @tipo_documento_id=@tipo_doc_id, @numero_referencia=N'BAD1', @storage_key=N'2026/11/bad.svg', @nombre_original=N'a.svg', @mime_type='image/svg+xml', @tamano_bytes=100, @sha256_hex=@sha1, @documento_id=@tmp OUTPUT;
    END TRY BEGIN CATCH SET @err = ERROR_NUMBER(); END CATCH;
    IF @err <> 50433 THROW 50986, N'MIME image/svg+xml debe rechazarse con 50433.', 1;

    SET @err = 0;
    BEGIN TRY
        EXEC dbo.sp_registrar_documento_archivo @solicitud_id=@solicitud_id, @tipo_documento_id=@tipo_doc_id, @numero_referencia=N'BAD2', @storage_key=N'2026/11/big.pdf', @nombre_original=N'big.pdf', @mime_type='application/pdf', @tamano_bytes=10485761, @sha256_hex=@sha1, @documento_id=@tmp OUTPUT;
    END TRY BEGIN CATCH SET @err = ERROR_NUMBER(); END CATCH;
    IF @err <> 50433 THROW 50987, N'Tamaño >10 MiB debe rechazarse con 50433.', 1;

    SET @err = 0;
    BEGIN TRY
        EXEC dbo.sp_registrar_documento_archivo @solicitud_id=@solicitud_id, @tipo_documento_id=@tipo_doc_id, @numero_referencia=N'BAD3', @storage_key=N'../evil.pdf', @nombre_original=N'evil.pdf', @mime_type='application/pdf', @tamano_bytes=100, @sha256_hex=@sha1, @documento_id=@tmp OUTPUT;
    END TRY BEGIN CATCH SET @err = ERROR_NUMBER(); END CATCH;
    IF @err <> 50433 THROW 50988, N'Path traversal en storage_key debe rechazarse con 50433.', 1;

    SET @err = 0;
    BEGIN TRY
        EXEC dbo.sp_registrar_documento_archivo @solicitud_id=@solicitud_id, @tipo_documento_id=@tipo_doc_id, @numero_referencia=N'BAD4', @storage_key=@key1, @nombre_original=N'dup.pdf', @mime_type='application/pdf', @tamano_bytes=100, @sha256_hex=@sha1, @documento_id=@tmp OUTPUT;
    END TRY BEGIN CATCH SET @err = ERROR_NUMBER(); END CATCH;
    IF @err <> 50434 THROW 50989, N'storage_key duplicado debe rechazarse con 50434.', 1;

    SET @err = 0;
    BEGIN TRY EXEC dbo.sp_obtener_documento @documento_id = 99999999; END TRY
    BEGIN CATCH SET @err = ERROR_NUMBER(); END CATCH;
    IF @err <> 50101 THROW 50990, N'Documento inexistente debe lanzar 50101.', 1;

    -- 5. Control de permisos en SQL (usuario sin DOCUMENTOS_REGISTRAR ni DOCUMENTOS_VER)
    DECLARE @nop_name NVARCHAR(80) = N'nop18_' + @tag;
    DECLARE @nop_mail NVARCHAR(150) = @nop_name + N'@example.test';
    INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash, activo)
    VALUES (@nop_name, @nop_mail, N'$2y$10$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234', 1);
    SET @noperm_uid = CONVERT(INT, SCOPE_IDENTITY());
    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @noperm_uid;

    SET @err = 0;
    BEGIN TRY
        EXEC dbo.sp_registrar_documento_archivo @solicitud_id=@solicitud_id, @tipo_documento_id=@tipo_doc_id, @numero_referencia=N'NOP', @storage_key=N'2026/11/nop.pdf', @nombre_original=N'nop.pdf', @mime_type='application/pdf', @tamano_bytes=100, @sha256_hex=@sha1, @documento_id=@tmp OUTPUT;
    END TRY BEGIN CATCH SET @err = ERROR_NUMBER(); END CATCH;
    IF @err <> 50406 THROW 50991, N'Sin DOCUMENTOS_REGISTRAR debe lanzar 50406.', 1;

    SET @err = 0;
    BEGIN TRY EXEC dbo.sp_obtener_documento @documento_id = @doc_int; END TRY
    BEGIN CATCH SET @err = ERROR_NUMBER(); END CATCH;
    IF @err <> 50103 THROW 50992, N'Sin DOCUMENTOS_VER debe lanzar 50103.', 1;

    PRINT '3. [OK] Rechazos de validación (50433/50434/50101) y RBAC SQL (50406/50103) verificados.';

    -- Limpieza estricta
    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @admin_id;
    DELETE FROM dbo.documentos WHERE documento_id IN (@doc_int, @doc_ext);
    DELETE FROM dbo.auditorias WHERE auditoria_id > @max_aud_id;
    DELETE FROM dbo.usuarios WHERE usuario_id = @noperm_uid;

    PRINT '=== SUITE T-SQL 18 (ADJUNTOS DOCUMENTALES MIG 14): 100% PASS ===';
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    DELETE FROM dbo.documentos WHERE documento_id IN (@doc_int, @doc_ext);
    DELETE FROM dbo.auditorias WHERE auditoria_id > @max_aud_id;
    DELETE FROM dbo.usuarios WHERE usuario_id = @noperm_uid;
    THROW;
END CATCH;
GO
