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

    -- ---------------------------------------------------------------------
    -- 6. Contratos de comprobante en dbo.entregas (INTERNA, EXTERNA, FALLIDA)
    -- ---------------------------------------------------------------------
    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @admin_id;

    DECLARE @ent_list TABLE (
        entrega_id INT, estado VARCHAR(15), fecha_resultado DATETIME2, receptor_nombre NVARCHAR(150),
        receptor_identificacion NVARCHAR(30), receptor_telefono NVARCHAR(30), fecha_recepcion DATETIME2,
        comprobante_url NVARCHAR(500), motivo_fallo NVARCHAR(500), observaciones NVARCHAR(500),
        viaje_solicitud_id INT, viaje_id INT, solicitud_id INT, codigo_solicitud NVARCHAR(50),
        comprobante_origen VARCHAR(10), comprobante_nombre_original NVARCHAR(255),
        comprobante_mime_type VARCHAR(100), comprobante_tamano_bytes BIGINT
    );
    DECLARE @tot_ent INT = 0;
    INSERT INTO @ent_list EXEC dbo.sp_listar_entregas @estado=NULL, @viaje_id=NULL, @desde=NULL, @hasta=NULL, @pagina=1, @por_pagina=50, @total_registros=@tot_ent OUTPUT;
    IF EXISTS (SELECT 1 FROM dbo.entregas WHERE entrega_id = 1 AND estado = 'ENTREGADA')
       AND NOT EXISTS (SELECT 1 FROM @ent_list WHERE entrega_id = 1 AND comprobante_origen = 'EXTERNA')
        THROW 50993, N'Entrega histórica #1 debe proyectarse como comprobante_origen=EXTERNA.', 1;

    -- Crear fixture efímero totalmente aislado (vehículo + conductor + 3 solicitudes + 1 viaje EN_CURSO + 2 paradas + 3 viaje_solicitudes EN_TRANSITO)
    DECLARE @cli_id INT = (SELECT TOP (1) cliente_id FROM dbo.clientes ORDER BY cliente_id);
    DECLARE @ori_id INT = (SELECT TOP (1) ubicacion_id FROM dbo.ubicaciones ORDER BY ubicacion_id);
    DECLARE @dst_id INT = (SELECT TOP (1) ubicacion_id FROM dbo.ubicaciones WHERE ubicacion_id <> @ori_id ORDER BY ubicacion_id);
    DECLARE @rut_id INT = (SELECT TOP (1) ruta_id FROM dbo.rutas ORDER BY ruta_id);

    DECLARE @veh_id INT = NULL, @con_id INT = NULL;
    DECLARE @sol_e1 INT = NULL, @sol_e2 INT = NULL, @sol_e3 INT = NULL;
    DECLARE @viaje_e INT = NULL, @par1 INT = NULL, @par2 INT = NULL;
    DECLARE @vs1 INT = NULL, @vs2 INT = NULL, @vs3 INT = NULL;

    INSERT INTO dbo.vehiculos (placa, marca, modelo, anio, capacidad_carga_kg, kilometraje_actual, estado, activo, pma_kg, clase_legal, es_articulado, estado_homologacion)
    VALUES (N'T18' + RIGHT(@tag, 6), N'Test', N'Prueba', 2024, 3000, 100, 'ASIGNADO', 1, 3500, 'LIVIANO', 0, 'VERIFICADO');
    SET @veh_id = CONVERT(INT, SCOPE_IDENTITY());

    INSERT INTO dbo.conductores (identificacion, nombre, primer_apellido, numero_licencia, fecha_vencimiento_licencia, estado_operativo, activo)
    VALUES (N'T18-' + @tag, N'Conductor', N'Prueba', N'LIC-' + @tag, DATEADD(YEAR, 2, CONVERT(DATE, SYSUTCDATETIME())), 'ASIGNADO', 1);
    SET @con_id = CONVERT(INT, SCOPE_IDENTITY());

    INSERT INTO dbo.solicitudes (codigo_solicitud, cliente_id, origen_id, destino_id, destinatario_nombre, destinatario_apellido1, destinatario_apellido2, fecha_solicitud, fecha_entrega_requerida, prioridad_calculada, prioridad_actual, estado)
    VALUES (N'T18-S1-' + @tag, @cli_id, @ori_id, @dst_id, N'Prueba', N'Entrega', N'Interna', SYSUTCDATETIME(), DATEADD(DAY, 1, SYSUTCDATETIME()), 'NORMAL', 'NORMAL', 'EN_TRANSITO');
    SET @sol_e1 = CONVERT(INT, SCOPE_IDENTITY());

    INSERT INTO dbo.solicitudes (codigo_solicitud, cliente_id, origen_id, destino_id, destinatario_nombre, destinatario_apellido1, destinatario_apellido2, fecha_solicitud, fecha_entrega_requerida, prioridad_calculada, prioridad_actual, estado)
    VALUES (N'T18-S2-' + @tag, @cli_id, @ori_id, @dst_id, N'Prueba', N'Entrega', N'Externa', SYSUTCDATETIME(), DATEADD(DAY, 1, SYSUTCDATETIME()), 'NORMAL', 'NORMAL', 'EN_TRANSITO');
    SET @sol_e2 = CONVERT(INT, SCOPE_IDENTITY());

    INSERT INTO dbo.solicitudes (codigo_solicitud, cliente_id, origen_id, destino_id, destinatario_nombre, destinatario_apellido1, destinatario_apellido2, fecha_solicitud, fecha_entrega_requerida, prioridad_calculada, prioridad_actual, estado)
    VALUES (N'T18-S3-' + @tag, @cli_id, @ori_id, @dst_id, N'Prueba', N'Entrega', N'Fallida', SYSUTCDATETIME(), DATEADD(DAY, 1, SYSUTCDATETIME()), 'NORMAL', 'NORMAL', 'EN_TRANSITO');
    SET @sol_e3 = CONVERT(INT, SCOPE_IDENTITY());

    INSERT INTO dbo.viajes (codigo_viaje, vehiculo_id, conductor_id, ruta_id, fecha_programada_salida, fecha_salida_real, estado)
    VALUES (N'T18-V-' + @tag, @veh_id, @con_id, @rut_id, SYSUTCDATETIME(), SYSUTCDATETIME(), 'EN_CURSO');
    SET @viaje_e = CONVERT(INT, SCOPE_IDENTITY());

    INSERT INTO dbo.paradas_viaje (viaje_id, ubicacion_id, orden, tipo_parada, estado)
    VALUES (@viaje_e, @ori_id, 1, 'RECOGIDA', 'COMPLETADA');
    SET @par1 = CONVERT(INT, SCOPE_IDENTITY());

    INSERT INTO dbo.paradas_viaje (viaje_id, ubicacion_id, orden, tipo_parada, estado)
    VALUES (@viaje_e, @dst_id, 2, 'ENTREGA', 'PENDIENTE');
    SET @par2 = CONVERT(INT, SCOPE_IDENTITY());

    INSERT INTO dbo.viaje_solicitudes (viaje_id, solicitud_id, parada_recogida_id, parada_entrega_id, estado)
    VALUES (@viaje_e, @sol_e1, @par1, @par2, 'EN_TRANSITO');
    SET @vs1 = CONVERT(INT, SCOPE_IDENTITY());

    INSERT INTO dbo.viaje_solicitudes (viaje_id, solicitud_id, parada_recogida_id, parada_entrega_id, estado)
    VALUES (@viaje_e, @sol_e2, @par1, @par2, 'EN_TRANSITO');
    SET @vs2 = CONVERT(INT, SCOPE_IDENTITY());

    INSERT INTO dbo.viaje_solicitudes (viaje_id, solicitud_id, parada_recogida_id, parada_entrega_id, estado)
    VALUES (@viaje_e, @sol_e3, @par1, @par2, 'EN_TRANSITO');
    SET @vs3 = CONVERT(INT, SCOPE_IDENTITY());

    -- Rechazo 50321: ambos (archivo + URL)
    DECLARE @ekey1 NVARCHAR(120) = N'2026/11/' + LOWER(CONVERT(VARCHAR(64), HASHBYTES('SHA2_256', N'ent_' + @tag), 2)) + N'.pdf';
    DECLARE @esha1 CHAR(64) = LOWER(CONVERT(VARCHAR(64), HASHBYTES('SHA2_256', N'ent_payload_' + @tag), 2));
    SET @err = 0;
    BEGIN TRY
        EXEC dbo.sp_registrar_entrega @viaje_solicitud_id=@vs1, @estado='ENTREGADA', @receptor_nombre=N'Ana Prueba',
            @comprobante_url=N'https://example.test/ambos.pdf', @comprobante_storage_key=@ekey1,
            @comprobante_nombre_original=N'comp.pdf', @comprobante_mime_type='application/pdf',
            @comprobante_tamano_bytes=512, @comprobante_sha256_hex=@esha1;
    END TRY BEGIN CATCH SET @err = ERROR_NUMBER(); END CATCH;
    IF @err <> 50321 THROW 50994, N'Enviar comprobante archivo + URL simultáneamente debe lanzar 50321.', 1;

    -- Rechazo 50433: MIME prohibido
    SET @err = 0;
    BEGIN TRY
        EXEC dbo.sp_registrar_entrega @viaje_solicitud_id=@vs1, @estado='ENTREGADA', @receptor_nombre=N'Ana Prueba',
            @comprobante_storage_key=N'2026/11/bad.svg', @comprobante_nombre_original=N'bad.svg',
            @comprobante_mime_type='image/svg+xml', @comprobante_tamano_bytes=120, @comprobante_sha256_hex=@esha1;
    END TRY BEGIN CATCH SET @err = ERROR_NUMBER(); END CATCH;
    IF @err <> 50433 THROW 50995, N'Comprobante entrega SVG debe lanzar 50433.', 1;

    -- Alta ENTREGADA con comprobante interno
    EXEC dbo.sp_registrar_entrega @viaje_solicitud_id=@vs1, @estado='ENTREGADA', @receptor_nombre=N'Ana Prueba',
        @receptor_identificacion=N'101110111', @receptor_telefono=N'88887777',
        @comprobante_storage_key=@ekey1, @comprobante_nombre_original=N'comprobante_t18.pdf',
        @comprobante_mime_type='application/pdf', @comprobante_tamano_bytes=2048, @comprobante_sha256_hex=@esha1;

    DECLARE @ent_int_id INT = (SELECT entrega_id FROM dbo.entregas WHERE viaje_solicitud_id = @vs1);
    IF @ent_int_id IS NULL
        THROW 50996, N'No se insertó la entrega con comprobante interno.', 1;

    -- Rechazo 50434: storage_key duplicado en otro viaje_solicitud
    SET @err = 0;
    BEGIN TRY
        EXEC dbo.sp_registrar_entrega @viaje_solicitud_id=@vs2, @estado='ENTREGADA', @receptor_nombre=N'Luis Prueba',
            @comprobante_storage_key=@ekey1, @comprobante_nombre_original=N'dup.pdf',
            @comprobante_mime_type='application/pdf', @comprobante_tamano_bytes=512, @comprobante_sha256_hex=@esha1;
    END TRY BEGIN CATCH SET @err = ERROR_NUMBER(); END CATCH;
    IF @err <> 50434 THROW 50997, N'comprobante_storage_key duplicado debe lanzar 50434.', 1;

    -- Alta ENTREGADA retrocompatible con URL externa (firma legacy de 7 params)
    EXEC dbo.sp_registrar_entrega @viaje_solicitud_id=@vs2, @estado='ENTREGADA', @receptor_nombre=N'Luis Prueba',
        @receptor_identificacion=NULL, @receptor_telefono=NULL,
        @comprobante_url=N'https://example.test/comprobante-t18.pdf', @motivo_fallo=NULL;

    -- Alta FALLIDA sin comprobante (rechaza si intenta adjuntar)
    SET @err = 0;
    BEGIN TRY
        EXEC dbo.sp_registrar_entrega @viaje_solicitud_id=@vs3, @estado='FALLIDA', @motivo_fallo=N'Cerrado',
            @comprobante_storage_key=N'2026/11/fail.pdf', @comprobante_nombre_original=N'fail.pdf',
            @comprobante_mime_type='application/pdf', @comprobante_tamano_bytes=100, @comprobante_sha256_hex=@esha1;
    END TRY BEGIN CATCH SET @err = ERROR_NUMBER(); END CATCH;
    IF @err <> 50433 THROW 50998, N'Entrega FALLIDA con archivo adjunto debe lanzar 50433.', 1;

    EXEC dbo.sp_registrar_entrega @viaje_solicitud_id=@vs3, @estado='FALLIDA', @motivo_fallo=N'Cliente ausente prueba';

    -- Verificar sp_obtener_destinatario_asignacion autorizado y RBAC denegado (50319)
    DECLARE @chk_dest TABLE (
        viaje_solicitud_id INT, viaje_id INT, codigo_viaje NVARCHAR(50), estado_viaje VARCHAR(30),
        estado_asignacion VARCHAR(30), solicitud_id INT, codigo_solicitud NVARCHAR(50),
        destinatario_nombre NVARCHAR(150), destinatario_apellido1 NVARCHAR(100), destinatario_apellido2 NVARCHAR(100),
        destinatario_nombre_completo NVARCHAR(150), destinatario_identificacion NVARCHAR(30), destinatario_telefono NVARCHAR(30)
    );
    INSERT INTO @chk_dest EXEC dbo.sp_obtener_destinatario_asignacion @viaje_solicitud_id = @vs1;
    IF NOT EXISTS (SELECT 1 FROM @chk_dest WHERE destinatario_nombre_completo = N'Prueba Entrega Interna')
        THROW 50999, N'sp_obtener_destinatario_asignacion no devolvió el nombre completo esperado.', 1;

    -- Verificar sp_obtener_comprobante_entrega autorizado y RBAC denegado (50103)
    DECLARE @chk_e TABLE (
        entrega_id INT, viaje_solicitud_id INT, viaje_id INT, solicitud_id INT, codigo_solicitud NVARCHAR(50),
        estado VARCHAR(15), comprobante_origen VARCHAR(10), comprobante_url NVARCHAR(500),
        comprobante_storage_key NVARCHAR(120), comprobante_nombre_original NVARCHAR(255),
        comprobante_mime_type VARCHAR(100), comprobante_tamano_bytes BIGINT,
        comprobante_sha256_hex CHAR(64), fecha_resultado DATETIME2
    );
    INSERT INTO @chk_e EXEC dbo.sp_obtener_comprobante_entrega @entrega_id = @ent_int_id;
    IF NOT EXISTS (SELECT 1 FROM @chk_e WHERE comprobante_origen = 'INTERNA' AND comprobante_storage_key = @ekey1 AND comprobante_sha256_hex = @esha1)
        THROW 50999, N'sp_obtener_comprobante_entrega no devolvió los metadatos esperados.', 1;

    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @noperm_uid;
    SET @err = 0;
    BEGIN TRY EXEC dbo.sp_obtener_destinatario_asignacion @viaje_solicitud_id = @vs1; END TRY
    BEGIN CATCH SET @err = ERROR_NUMBER(); END CATCH;
    IF @err <> 50319 THROW 50999, N'Sin ENTREGAS_REGISTRAR sp_obtener_destinatario_asignacion debe lanzar 50319.', 1;

    SET @err = 0;
    BEGIN TRY EXEC dbo.sp_obtener_comprobante_entrega @entrega_id = @ent_int_id; END TRY
    BEGIN CATCH SET @err = ERROR_NUMBER(); END CATCH;
    IF @err <> 50103 THROW 50999, N'Sin ENTREGAS_VER sp_obtener_comprobante_entrega debe lanzar 50103.', 1;

    PRINT '4. [OK] Comprobantes en dbo.entregas (INTERNA, EXTERNA, FALLIDA, RBAC 50103 y rechazos 50321/50433/50434) verificados.';

    -- Limpieza estricta
    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @admin_id;
    DELETE FROM dbo.entregas WHERE viaje_solicitud_id IN (@vs1, @vs2, @vs3);
    DELETE FROM dbo.viaje_solicitudes WHERE viaje_solicitud_id IN (@vs1, @vs2, @vs3);
    DELETE FROM dbo.paradas_viaje WHERE parada_id IN (@par1, @par2);
    DELETE FROM dbo.viajes WHERE viaje_id = @viaje_e;
    DELETE FROM dbo.historial_estados_solicitud WHERE solicitud_id IN (@sol_e1, @sol_e2, @sol_e3);
    DELETE FROM dbo.solicitudes WHERE solicitud_id IN (@sol_e1, @sol_e2, @sol_e3);
    DELETE FROM dbo.vehiculos WHERE vehiculo_id = @veh_id;
    DELETE FROM dbo.conductores WHERE conductor_id = @con_id;
    DELETE FROM dbo.documentos WHERE documento_id IN (@doc_int, @doc_ext);
    DELETE FROM dbo.auditorias WHERE auditoria_id > @max_aud_id;
    DELETE FROM dbo.usuarios WHERE usuario_id = @noperm_uid;

    PRINT '=== SUITE T-SQL 18 (DOCUMENTOS + COMPROBANTES ENTREGA MIG 14): 100% PASS ===';
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    DELETE FROM dbo.entregas WHERE viaje_solicitud_id IN (SELECT viaje_solicitud_id FROM dbo.viaje_solicitudes WHERE solicitud_id IN (SELECT solicitud_id FROM dbo.solicitudes WHERE codigo_solicitud LIKE N'T18-S%-' + @tag));
    DELETE FROM dbo.viaje_solicitudes WHERE solicitud_id IN (SELECT solicitud_id FROM dbo.solicitudes WHERE codigo_solicitud LIKE N'T18-S%-' + @tag);
    DELETE FROM dbo.paradas_viaje WHERE viaje_id IN (SELECT viaje_id FROM dbo.viajes WHERE codigo_viaje = N'T18-V-' + @tag);
    DELETE FROM dbo.viajes WHERE codigo_viaje = N'T18-V-' + @tag;
    DELETE FROM dbo.historial_estados_solicitud WHERE solicitud_id IN (SELECT solicitud_id FROM dbo.solicitudes WHERE codigo_solicitud LIKE N'T18-S%-' + @tag);
    DELETE FROM dbo.solicitudes WHERE codigo_solicitud LIKE N'T18-S%-' + @tag;
    DELETE FROM dbo.vehiculos WHERE placa = N'T18' + RIGHT(@tag, 6);
    DELETE FROM dbo.conductores WHERE identificacion = N'T18-' + @tag;
    DELETE FROM dbo.documentos WHERE documento_id IN (@doc_int, @doc_ext);
    DELETE FROM dbo.auditorias WHERE auditoria_id > @max_aud_id;
    DELETE FROM dbo.usuarios WHERE usuario_id = @noperm_uid;
    THROW;
END CATCH;
GO
