-- ============================================================================
-- Migración 14: Mejoras de flujo operativo (paradas automáticas, entregas rápidas
-- con comprobante seguro sincronizado a documentos y entregas pendientes).
-- 100% aditiva, idempotente y retrocompatible con migraciones 01..13.
-- ============================================================================

SET NOCOUNT ON;
SET XACT_ABORT ON;
GO

-- 1. Columnas opcionales en dbo.documentos para archivos locales seguros (fuera de public/)
IF COL_LENGTH(N'dbo.documentos', N'nombre_archivo_original') IS NULL
    ALTER TABLE dbo.documentos ADD nombre_archivo_original NVARCHAR(255) NULL;
GO

IF COL_LENGTH(N'dbo.documentos', N'mime_type') IS NULL
    ALTER TABLE dbo.documentos ADD mime_type NVARCHAR(100) NULL;
GO

IF COL_LENGTH(N'dbo.documentos', N'tamano_bytes') IS NULL
    ALTER TABLE dbo.documentos ADD tamano_bytes INT NULL;
GO

IF COL_LENGTH(N'dbo.documentos', N'ruta_interna') IS NULL
    ALTER TABLE dbo.documentos ADD ruta_interna NVARCHAR(260) NULL;
GO

-- 2. Asignación atómica de solicitud a viaje con reutilización/creación automática de paradas
CREATE OR ALTER PROCEDURE dbo.sp_asignar_solicitud_con_paradas
    @viaje_id INT,
    @solicitud_id INT,
    @parada_recogida_id INT = NULL,
    @parada_entrega_id INT = NULL,
    @auto_crear_paradas BIT = 1,
    @viaje_solicitud_id INT OUTPUT,
    @paradas_creadas INT = NULL OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @usuario INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    DECLARE @vehiculo INT;
    DECLARE @origen_id INT;
    DECLARE @destino_id INT;
    DECLARE @peso DECIMAL(18,2);
    DECLARE @volumen DECIMAL(18,3);
    DECLARE @max_peso DECIMAL(18,2);
    DECLARE @max_volumen DECIMAL(18,3);
    DECLARE @recogida_id INT = @parada_recogida_id;
    DECLARE @entrega_id INT = @parada_entrega_id;
    DECLARE @recogida_orden INT = NULL;
    DECLARE @next_orden INT = 1;
    DECLARE @creadas INT = 0;

    IF dbo.fn_tiene_permiso(@usuario, N'VIAJES_ASIGNAR') = 0
        THROW 50308, N'No tiene permiso para asignar solicitudes.', 1;

    BEGIN TRY
        BEGIN TRANSACTION;

        SELECT @vehiculo = vehiculo_id
        FROM dbo.viajes WITH (UPDLOCK, HOLDLOCK)
        WHERE viaje_id = @viaje_id AND estado = 'PLANIFICADO';

        IF @vehiculo IS NULL
            THROW 50309, N'El viaje no existe o ya inició.', 1;

        SELECT @origen_id = origen_id,
               @destino_id = destino_id
        FROM dbo.solicitudes WITH (UPDLOCK, HOLDLOCK)
        WHERE solicitud_id = @solicitud_id
          AND estado IN ('REGISTRADA', 'PENDIENTE_ASIGNACION');

        IF @origen_id IS NULL
            THROW 50310, N'La solicitud no está disponible para asignación.', 1;

        IF EXISTS (SELECT 1 FROM dbo.viaje_solicitudes WHERE solicitud_id = @solicitud_id)
            THROW 50311, N'La solicitud ya está asignada a otro viaje.', 1;

        IF NOT EXISTS (SELECT 1 FROM dbo.mercancias WHERE solicitud_id = @solicitud_id)
            THROW 50315, N'La solicitud no tiene mercancías registradas.', 1;

        -- Resolver paradas si no se pasaron explícitamente y está habilitada la auto-creación
        IF (@recogida_id IS NULL OR @entrega_id IS NULL) AND ISNULL(@auto_crear_paradas, 0) = 1
        BEGIN
            -- Intentar encontrar un par compatible ya existente (recogida < entrega)
            IF @recogida_id IS NULL AND @entrega_id IS NULL
            BEGIN
                SELECT TOP (1)
                    @recogida_id = p1.parada_id,
                    @recogida_orden = p1.orden,
                    @entrega_id = p2.parada_id
                FROM dbo.paradas_viaje p1 WITH (UPDLOCK, HOLDLOCK)
                JOIN dbo.paradas_viaje p2 WITH (UPDLOCK, HOLDLOCK)
                  ON p2.viaje_id = @viaje_id
                 AND p2.ubicacion_id = @destino_id
                 AND p2.tipo_parada IN ('ENTREGA', 'MIXTA')
                 AND p1.orden < p2.orden
                WHERE p1.viaje_id = @viaje_id
                  AND p1.ubicacion_id = @origen_id
                  AND p1.tipo_parada IN ('RECOGIDA', 'MIXTA')
                ORDER BY p1.orden ASC, p2.orden ASC;
            END

            -- Si aún falta recogida, buscar parada compatible existente o crearla al final
            IF @recogida_id IS NULL
            BEGIN
                SELECT TOP (1)
                    @recogida_id = p1.parada_id,
                    @recogida_orden = p1.orden
                FROM dbo.paradas_viaje p1 WITH (UPDLOCK, HOLDLOCK)
                WHERE p1.viaje_id = @viaje_id
                  AND p1.ubicacion_id = @origen_id
                  AND p1.tipo_parada IN ('RECOGIDA', 'MIXTA')
                ORDER BY p1.orden ASC;

                IF @recogida_id IS NULL
                BEGIN
                    IF dbo.fn_tiene_permiso(@usuario, N'VIAJES_PARADAS') = 0
                        THROW 50306, N'No tiene permiso para crear paradas automáticamente en el viaje.', 1;

                    SELECT @next_orden = ISNULL(MAX(orden), 0) + 1
                    FROM dbo.paradas_viaje WITH (UPDLOCK, HOLDLOCK)
                    WHERE viaje_id = @viaje_id;

                    INSERT INTO dbo.paradas_viaje (viaje_id, ubicacion_id, orden, tipo_parada)
                    VALUES (@viaje_id, @origen_id, @next_orden, 'RECOGIDA');

                    SET @recogida_id = CONVERT(INT, SCOPE_IDENTITY());
                    SET @recogida_orden = @next_orden;
                    SET @creadas = @creadas + 1;
                END
            END
            ELSE IF @recogida_orden IS NULL
            BEGIN
                SELECT @recogida_orden = orden
                FROM dbo.paradas_viaje
                WHERE parada_id = @recogida_id AND viaje_id = @viaje_id;
            END

            -- Si aún falta entrega (posterior a recogida), buscar existente o crearla al final
            IF @entrega_id IS NULL
            BEGIN
                SELECT TOP (1)
                    @entrega_id = p2.parada_id
                FROM dbo.paradas_viaje p2 WITH (UPDLOCK, HOLDLOCK)
                WHERE p2.viaje_id = @viaje_id
                  AND p2.ubicacion_id = @destino_id
                  AND p2.tipo_parada IN ('ENTREGA', 'MIXTA')
                  AND p2.orden > @recogida_orden
                ORDER BY p2.orden ASC;

                IF @entrega_id IS NULL
                BEGIN
                    IF dbo.fn_tiene_permiso(@usuario, N'VIAJES_PARADAS') = 0
                        THROW 50306, N'No tiene permiso para crear paradas automáticamente en el viaje.', 1;

                    SELECT @next_orden = ISNULL(MAX(orden), 0) + 1
                    FROM dbo.paradas_viaje WITH (UPDLOCK, HOLDLOCK)
                    WHERE viaje_id = @viaje_id;

                    INSERT INTO dbo.paradas_viaje (viaje_id, ubicacion_id, orden, tipo_parada)
                    VALUES (@viaje_id, @destino_id, @next_orden, 'ENTREGA');

                    SET @entrega_id = CONVERT(INT, SCOPE_IDENTITY());
                    SET @creadas = @creadas + 1;
                END
            END
        END

        -- Verificación autoritativa final de correspondencia y orden de paradas
        IF NOT EXISTS
        (
            SELECT 1
            FROM dbo.solicitudes s
            JOIN dbo.paradas_viaje p1
              ON p1.parada_id = @recogida_id
             AND p1.viaje_id = @viaje_id
             AND p1.ubicacion_id = s.origen_id
            JOIN dbo.paradas_viaje p2
              ON p2.parada_id = @entrega_id
             AND p2.viaje_id = @viaje_id
             AND p2.ubicacion_id = s.destino_id
            WHERE s.solicitud_id = @solicitud_id
              AND p1.orden < p2.orden
              AND p1.tipo_parada IN ('RECOGIDA', 'MIXTA')
              AND p2.tipo_parada IN ('ENTREGA', 'MIXTA')
        )
            THROW 50312, N'Las paradas no corresponden a la solicitud.', 1;

        -- Validación de capacidad de peso y volumen del vehículo
        SELECT @peso = ISNULL(SUM(m.peso_kg), 0),
               @volumen = ISNULL(SUM(ISNULL(m.volumen_m3, 0)), 0)
        FROM dbo.viaje_solicitudes vs
        JOIN dbo.mercancias m ON m.solicitud_id = vs.solicitud_id
        WHERE vs.viaje_id = @viaje_id;

        SELECT @peso = @peso + ISNULL(SUM(peso_kg), 0),
               @volumen = @volumen + ISNULL(SUM(ISNULL(volumen_m3, 0)), 0)
        FROM dbo.mercancias
        WHERE solicitud_id = @solicitud_id;

        SELECT @max_peso = capacidad_carga_kg,
               @max_volumen = capacidad_volumen_m3
        FROM dbo.vehiculos
        WHERE vehiculo_id = @vehiculo;

        IF @peso > @max_peso
            THROW 50313, N'Capacidad de peso insuficiente.', 1;

        IF @max_volumen IS NOT NULL AND @volumen > @max_volumen
            THROW 50314, N'Capacidad de volumen insuficiente.', 1;

        INSERT INTO dbo.viaje_solicitudes (viaje_id, solicitud_id, parada_recogida_id, parada_entrega_id)
        VALUES (@viaje_id, @solicitud_id, @recogida_id, @entrega_id);

        SET @viaje_solicitud_id = CONVERT(INT, SCOPE_IDENTITY());
        SET @paradas_creadas = @creadas;

        UPDATE dbo.solicitudes
        SET estado = 'ASIGNADA',
            fecha_actualizacion = SYSUTCDATETIME()
        WHERE solicitud_id = @solicitud_id;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

-- 3. Contexto para prellenar el modal de Registrar entrega con el destinatario autorizado
CREATE OR ALTER PROCEDURE dbo.sp_obtener_contexto_entrega
    @viaje_solicitud_id INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@usuario_id, N'ENTREGAS_REGISTRAR') = 0
       AND dbo.fn_tiene_permiso(@usuario_id, N'ENTREGAS_VER') = 0
        THROW 50103, N'No tiene permiso para consultar el contexto de entrega.', 1;

    IF @viaje_solicitud_id IS NULL OR @viaje_solicitud_id < 1
        THROW 50102, N'Parámetros inválidos.', 1;

    SELECT
        vs.viaje_solicitud_id,
        vs.viaje_id,
        v.codigo_viaje,
        v.estado AS estado_viaje,
        vs.solicitud_id,
        s.codigo_solicitud,
        vs.estado AS estado_asignacion,
        LTRIM(RTRIM(CONCAT(
            ISNULL(s.destinatario_nombre, N''),
            CASE WHEN NULLIF(LTRIM(RTRIM(s.destinatario_apellido1)), N'') IS NOT NULL THEN N' ' + LTRIM(RTRIM(s.destinatario_apellido1)) ELSE N'' END,
            CASE WHEN NULLIF(LTRIM(RTRIM(s.destinatario_apellido2)), N'') IS NOT NULL THEN N' ' + LTRIM(RTRIM(s.destinatario_apellido2)) ELSE N'' END
        ))) AS destinatario_nombre,
        s.destinatario_identificacion,
        s.destinatario_telefono,
        uo.nombre AS origen_nombre,
        ud.nombre AS destino_nombre
    FROM dbo.viaje_solicitudes AS vs
    JOIN dbo.viajes AS v ON v.viaje_id = vs.viaje_id
    JOIN dbo.solicitudes AS s ON s.solicitud_id = vs.solicitud_id
    JOIN dbo.ubicaciones AS uo ON uo.ubicacion_id = s.origen_id
    JOIN dbo.ubicaciones AS ud ON ud.ubicacion_id = s.destino_id
    WHERE vs.viaje_solicitud_id = @viaje_solicitud_id;

    IF @@ROWCOUNT = 0
        THROW 50101, N'Asignación no encontrada.', 1;
END;
GO

-- 4. Listado paginado de entregas pendientes (EN_TRANSITO en viajes EN_CURSO) para acceso directo en /entregas.php
CREATE OR ALTER PROCEDURE dbo.sp_listar_entregas_pendientes
    @buscar NVARCHAR(100) = NULL,
    @pagina INT = 1,
    @por_pagina INT = 20,
    @total_registros INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    DECLARE @offset BIGINT;
    DECLARE @patron NVARCHAR(102) = NULL;

    IF dbo.fn_tiene_permiso(@usuario_id, N'ENTREGAS_VER') = 0
        THROW 50103, N'No tiene permiso para consultar entregas pendientes.', 1;

    IF @pagina < 1 OR @por_pagina NOT BETWEEN 1 AND 100
        THROW 50102, N'Parámetros de paginación inválidos.', 1;

    SET @buscar = NULLIF(LTRIM(RTRIM(@buscar)), N'');
    IF @buscar IS NOT NULL
        SET @patron = N'%' + @buscar + N'%';

    SET @offset = CONVERT(BIGINT, @pagina - 1) * @por_pagina;

    SELECT @total_registros = COUNT(*)
    FROM dbo.viaje_solicitudes AS vs
    JOIN dbo.viajes AS v ON v.viaje_id = vs.viaje_id
    JOIN dbo.solicitudes AS s ON s.solicitud_id = vs.solicitud_id
    JOIN dbo.vehiculos AS veh ON veh.vehiculo_id = v.vehiculo_id
    JOIN dbo.ubicaciones AS ud ON ud.ubicacion_id = s.destino_id
    WHERE vs.estado = 'EN_TRANSITO'
      AND v.estado = 'EN_CURSO'
      AND NOT EXISTS (SELECT 1 FROM dbo.entregas AS e WHERE e.viaje_solicitud_id = vs.viaje_solicitud_id)
      AND (
          @patron IS NULL
          OR s.codigo_solicitud LIKE @patron
          OR v.codigo_viaje LIKE @patron
          OR veh.placa LIKE @patron
          OR ud.nombre LIKE @patron
      );

    SELECT
        vs.viaje_solicitud_id,
        vs.viaje_id,
        v.codigo_viaje,
        vs.solicitud_id,
        s.codigo_solicitud,
        veh.placa,
        LTRIM(RTRIM(CONCAT(c.nombre, N' ', c.primer_apellido, CASE WHEN NULLIF(LTRIM(RTRIM(c.segundo_apellido)), N'') IS NOT NULL THEN N' ' + LTRIM(RTRIM(c.segundo_apellido)) ELSE N'' END))) AS conductor_nombre,
        ud.nombre AS destino_nombre,
        LTRIM(RTRIM(CONCAT(
            ISNULL(s.destinatario_nombre, N''),
            CASE WHEN NULLIF(LTRIM(RTRIM(s.destinatario_apellido1)), N'') IS NOT NULL THEN N' ' + LTRIM(RTRIM(s.destinatario_apellido1)) ELSE N'' END
        ))) AS destinatario_nombre,
        v.fecha_salida_real
    FROM dbo.viaje_solicitudes AS vs
    JOIN dbo.viajes AS v ON v.viaje_id = vs.viaje_id
    JOIN dbo.solicitudes AS s ON s.solicitud_id = vs.solicitud_id
    JOIN dbo.vehiculos AS veh ON veh.vehiculo_id = v.vehiculo_id
    JOIN dbo.conductores AS c ON c.conductor_id = v.conductor_id
    JOIN dbo.ubicaciones AS ud ON ud.ubicacion_id = s.destino_id
    WHERE vs.estado = 'EN_TRANSITO'
      AND v.estado = 'EN_CURSO'
      AND NOT EXISTS (SELECT 1 FROM dbo.entregas AS e WHERE e.viaje_solicitud_id = vs.viaje_solicitud_id)
      AND (
          @patron IS NULL
          OR s.codigo_solicitud LIKE @patron
          OR v.codigo_viaje LIKE @patron
          OR veh.placa LIKE @patron
          OR ud.nombre LIKE @patron
      )
    ORDER BY v.fecha_salida_real ASC, vs.viaje_solicitud_id ASC
    OFFSET @offset ROWS FETCH NEXT @por_pagina ROWS ONLY;
END;
GO

-- 5. Actualizar dbo.sp_registrar_entrega: comprobante opcional + sincronización automática con dbo.documentos
CREATE OR ALTER PROCEDURE dbo.sp_registrar_entrega
    @viaje_solicitud_id INT,
    @estado VARCHAR(15),
    @receptor_nombre NVARCHAR(150) = NULL,
    @receptor_identificacion NVARCHAR(30) = NULL,
    @receptor_telefono NVARCHAR(30) = NULL,
    @comprobante_url NVARCHAR(500) = NULL,
    @motivo_fallo NVARCHAR(500) = NULL,
    @nombre_archivo_original NVARCHAR(255) = NULL,
    @mime_type NVARCHAR(100) = NULL,
    @tamano_bytes INT = NULL,
    @ruta_interna NVARCHAR(260) = NULL,
    @documento_id INT = NULL OUTPUT,
    @permitir_sin_comprobante BIT = 0
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @usuario INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    DECLARE @solicitud INT;
    DECLARE @codigo_solicitud NVARCHAR(30);
    DECLARE @entrega_id INT;
    DECLARE @tipo_doc_comprobante INT;
    DECLARE @url_limpia NVARCHAR(500) = NULLIF(LTRIM(RTRIM(@comprobante_url)), N'');
    DECLARE @ruta_limpia NVARCHAR(260) = NULLIF(LTRIM(RTRIM(@ruta_interna)), N'');
    DECLARE @doc_creado INT = NULL;

    IF dbo.fn_tiene_permiso(@usuario, N'ENTREGAS_REGISTRAR') = 0
        THROW 50319, N'No tiene permiso para registrar entregas.', 1;

    IF @estado NOT IN ('ENTREGADA', 'FALLIDA')
        THROW 50320, N'Estado de entrega inválido.', 1;

    IF @estado = 'ENTREGADA' AND (
        NULLIF(LTRIM(RTRIM(@receptor_nombre)), N'') IS NULL
        OR (ISNULL(@permitir_sin_comprobante, 0) = 0 AND @url_limpia IS NULL AND @ruta_limpia IS NULL)
    )
        THROW 50321, N'La entrega exitosa exige indicar receptor efectivo y comprobante.', 1;

    IF @estado = 'FALLIDA' AND NULLIF(LTRIM(RTRIM(@motivo_fallo)), N'') IS NULL
        THROW 50322, N'Debe justificar la entrega fallida.', 1;

    BEGIN TRY
        BEGIN TRANSACTION;

        SELECT @solicitud = vs.solicitud_id,
               @codigo_solicitud = s.codigo_solicitud
        FROM dbo.viaje_solicitudes vs WITH (UPDLOCK, HOLDLOCK)
        JOIN dbo.viajes v ON v.viaje_id = vs.viaje_id
        JOIN dbo.solicitudes s ON s.solicitud_id = vs.solicitud_id
        WHERE vs.viaje_solicitud_id = @viaje_solicitud_id
          AND vs.estado = 'EN_TRANSITO'
          AND v.estado = 'EN_CURSO';

        IF @solicitud IS NULL
            THROW 50323, N'La solicitud no está en un viaje en curso.', 1;

        IF EXISTS (SELECT 1 FROM dbo.entregas WHERE viaje_solicitud_id = @viaje_solicitud_id)
            THROW 50324, N'La solicitud ya tiene una entrega registrada.', 1;

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
            @url_limpia, @usuario, @motivo_fallo
        );

        SET @entrega_id = CONVERT(INT, SCOPE_IDENTITY());

        -- Si hay archivo subido o URL de comprobante en entrega exitosa, registrarlo también en dbo.documentos
        IF @estado = 'ENTREGADA' AND (@ruta_limpia IS NOT NULL OR @url_limpia IS NOT NULL)
        BEGIN
            SELECT TOP (1) @tipo_doc_comprobante = tipo_documento_id
            FROM dbo.tipos_documento
            WHERE nombre LIKE N'%Comprobante%'
            ORDER BY tipo_documento_id ASC;

            IF @tipo_doc_comprobante IS NULL
                SET @tipo_doc_comprobante = 3;

            INSERT INTO dbo.documentos
            (
                solicitud_id, tipo_documento_id, numero_referencia,
                fecha_documento, archivo_url, observaciones,
                nombre_archivo_original, mime_type, tamano_bytes, ruta_interna
            )
            VALUES
            (
                @solicitud,
                @tipo_doc_comprobante,
                LEFT(CONCAT(N'ENT-', @codigo_solicitud), 100),
                CONVERT(DATE, SYSUTCDATETIME()),
                @url_limpia,
                N'Comprobante vinculado automáticamente al registrar entrega.',
                NULLIF(LTRIM(RTRIM(@nombre_archivo_original)), N''),
                NULLIF(LTRIM(RTRIM(@mime_type)), N''),
                @tamano_bytes,
                @ruta_limpia
            );

            SET @doc_creado = CONVERT(INT, SCOPE_IDENTITY());

            IF @ruta_limpia IS NOT NULL AND @url_limpia IS NULL
            BEGIN
                DECLARE @ruta_descarga NVARCHAR(500) = CONCAT(N'/entregas/archivo.php?id=', @doc_creado);

                UPDATE dbo.documentos
                SET archivo_url = @ruta_descarga
                WHERE documento_id = @doc_creado;

                UPDATE dbo.entregas
                SET comprobante_url = @ruta_descarga
                WHERE entrega_id = @entrega_id;
            END
        END

        SET @documento_id = @doc_creado;

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

-- 6. Actualizar dbo.sp_registrar_documento para admitir archivo local seguro o URL
CREATE OR ALTER PROCEDURE dbo.sp_registrar_documento
    @solicitud_id INT,
    @tipo_documento_id INT,
    @numero_referencia NVARCHAR(100),
    @archivo_url NVARCHAR(500) = NULL,
    @documento_id INT OUTPUT,
    @nombre_archivo_original NVARCHAR(255) = NULL,
    @mime_type NVARCHAR(100) = NULL,
    @tamano_bytes INT = NULL,
    @ruta_interna NVARCHAR(260) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @usuario INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    DECLARE @url_limpia NVARCHAR(500) = NULLIF(LTRIM(RTRIM(@archivo_url)), N'');
    DECLARE @ruta_limpia NVARCHAR(260) = NULLIF(LTRIM(RTRIM(@ruta_interna)), N'');

    IF dbo.fn_tiene_permiso(@usuario, N'DOCUMENTOS_REGISTRAR') = 0
        THROW 50406, N'Sin permiso para registrar documentos.', 1;

    IF @url_limpia IS NULL AND @ruta_limpia IS NULL
        THROW 50102, N'Debe proporcionar un archivo o una URL de referencia.', 1;

    INSERT INTO dbo.documentos
    (
        solicitud_id, tipo_documento_id, numero_referencia,
        fecha_documento, archivo_url,
        nombre_archivo_original, mime_type, tamano_bytes, ruta_interna
    )
    VALUES
    (
        @solicitud_id, @tipo_documento_id, @numero_referencia,
        CONVERT(DATE, SYSUTCDATETIME()), @url_limpia,
        NULLIF(LTRIM(RTRIM(@nombre_archivo_original)), N''),
        NULLIF(LTRIM(RTRIM(@mime_type)), N''),
        @tamano_bytes,
        @ruta_limpia
    );

    SET @documento_id = CONVERT(INT, SCOPE_IDENTITY());

    IF @ruta_limpia IS NOT NULL AND @url_limpia IS NULL
    BEGIN
        UPDATE dbo.documentos
        SET archivo_url = CONCAT(N'/entregas/archivo.php?id=', @documento_id)
        WHERE documento_id = @documento_id;
    END
END;
GO

-- 7. Lectura autorizada de metadatos de archivo local para descarga protegida
CREATE OR ALTER PROCEDURE dbo.sp_obtener_documento_archivo
    @documento_id INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@usuario_id, N'DOCUMENTOS_VER') = 0
       AND dbo.fn_tiene_permiso(@usuario_id, N'ENTREGAS_VER') = 0
        THROW 50103, N'No tiene permiso para descargar comprobantes o documentos.', 1;

    IF @documento_id IS NULL OR @documento_id < 1
        THROW 50102, N'Identificador de documento inválido.', 1;

    SELECT
        documento_id,
        solicitud_id,
        numero_referencia,
        archivo_url,
        nombre_archivo_original,
        mime_type,
        tamano_bytes,
        ruta_interna
    FROM dbo.documentos
    WHERE documento_id = @documento_id;

    IF @@ROWCOUNT = 0
        THROW 50101, N'Documento no encontrado.', 1;
END;
GO

-- 8. Actualizar dbo.sp_listar_documentos para exponer metadatos de archivo adjunto
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
        d.nombre_archivo_original,
        d.mime_type,
        d.tamano_bytes
    FROM dbo.documentos AS d
    JOIN dbo.tipos_documento AS td ON td.tipo_documento_id = d.tipo_documento_id
    WHERE d.solicitud_id = @solicitud_id
    ORDER BY d.fecha_registro DESC, d.documento_id DESC
    OFFSET @offset ROWS FETCH NEXT @por_pagina ROWS ONLY;
END;
GO

-- 9. Desasignar solicitud de un viaje en estado PLANIFICADO
CREATE OR ALTER PROCEDURE dbo.sp_desasignar_solicitud_viaje
    @viaje_id INT,
    @solicitud_id INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @usuario INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));

    IF dbo.fn_tiene_permiso(@usuario, N'VIAJES_ASIGNAR') = 0
        THROW 50308, N'No tiene permiso para desasignar solicitudes.', 1;

    BEGIN TRY
        BEGIN TRANSACTION;

        IF NOT EXISTS (
            SELECT 1 FROM dbo.viajes WITH (UPDLOCK, HOLDLOCK)
            WHERE viaje_id = @viaje_id AND estado = 'PLANIFICADO'
        )
            THROW 50309, N'El viaje no existe o ya no está en planificación.', 1;

        IF NOT EXISTS (
            SELECT 1 FROM dbo.viaje_solicitudes WITH (UPDLOCK, HOLDLOCK)
            WHERE viaje_id = @viaje_id AND solicitud_id = @solicitud_id
        )
            THROW 50101, N'La solicitud no está asignada a este viaje.', 1;

        DELETE FROM dbo.viaje_solicitudes
        WHERE viaje_id = @viaje_id AND solicitud_id = @solicitud_id;

        UPDATE dbo.solicitudes
        SET estado = 'PENDIENTE_ASIGNACION',
            fecha_actualizacion = SYSUTCDATETIME()
        WHERE solicitud_id = @solicitud_id;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

-- 10. Eliminar parada no vinculada de un viaje en estado PLANIFICADO y resecuenciar 1..N
CREATE OR ALTER PROCEDURE dbo.sp_eliminar_parada_viaje
    @viaje_id INT,
    @parada_id INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @usuario INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));

    IF dbo.fn_tiene_permiso(@usuario, N'VIAJES_PARADAS') = 0
        THROW 50306, N'No tiene permiso para eliminar paradas del viaje.', 1;

    BEGIN TRY
        BEGIN TRANSACTION;

        IF NOT EXISTS (
            SELECT 1 FROM dbo.viajes WITH (UPDLOCK, HOLDLOCK)
            WHERE viaje_id = @viaje_id AND estado = 'PLANIFICADO'
        )
            THROW 50307, N'El viaje ya no está en planificación.', 1;

        IF NOT EXISTS (
            SELECT 1 FROM dbo.paradas_viaje WITH (UPDLOCK, HOLDLOCK)
            WHERE viaje_id = @viaje_id AND parada_id = @parada_id
        )
            THROW 50101, N'La parada no pertenece a este viaje.', 1;

        IF EXISTS (
            SELECT 1 FROM dbo.viaje_solicitudes WITH (UPDLOCK, HOLDLOCK)
            WHERE viaje_id = @viaje_id
              AND (parada_recogida_id = @parada_id OR parada_entrega_id = @parada_id)
        )
            THROW 50338, N'No se puede eliminar una parada vinculada a una solicitud asignada.', 1;

        DELETE FROM dbo.paradas_viaje
        WHERE viaje_id = @viaje_id AND parada_id = @parada_id;

        -- Resecuenciar órdenes sin colisión de índice único (desplazar +10000 y compactar a 1..N)
        UPDATE dbo.paradas_viaje
        SET orden = orden + 10000
        WHERE viaje_id = @viaje_id;

        ;WITH cte AS (
            SELECT parada_id, ROW_NUMBER() OVER (ORDER BY orden ASC, parada_id ASC) AS nuevo_orden
            FROM dbo.paradas_viaje
            WHERE viaje_id = @viaje_id
        )
        UPDATE p
        SET p.orden = cte.nuevo_orden
        FROM dbo.paradas_viaje p
        JOIN cte ON cte.parada_id = p.parada_id;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

-- 11. Resumen de ocupación de capacidad (peso y volumen) de un viaje
CREATE OR ALTER PROCEDURE dbo.sp_obtener_capacidad_viaje
    @viaje_id INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @usuario INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@usuario, N'VIAJES_VER') = 0
        THROW 50103, N'No tiene permiso para consultar viajes.', 1;

    SELECT
        v.viaje_id,
        veh.capacidad_carga_kg,
        veh.capacidad_volumen_m3,
        ISNULL((
            SELECT SUM(m.peso_kg)
            FROM dbo.viaje_solicitudes vs
            JOIN dbo.mercancias m ON m.solicitud_id = vs.solicitud_id
            WHERE vs.viaje_id = v.viaje_id
        ), 0) AS peso_usado_kg,
        ISNULL((
            SELECT SUM(ISNULL(m.volumen_m3, 0))
            FROM dbo.viaje_solicitudes vs
            JOIN dbo.mercancias m ON m.solicitud_id = vs.solicitud_id
            WHERE vs.viaje_id = v.viaje_id
        ), 0) AS volumen_usado_m3
    FROM dbo.viajes v
    JOIN dbo.vehiculos veh ON veh.vehiculo_id = v.vehiculo_id
    WHERE v.viaje_id = @viaje_id;
END;
GO

-- 12. Vista 360° de una solicitud: viaje enlazable, entrega, documentos e incidencias
CREATE OR ALTER PROCEDURE dbo.sp_obtener_solicitud_360
    @solicitud_id INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @usuario INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@usuario, N'SOLICITUDES_VER') = 0
        THROW 50103, N'No tiene permiso para consultar solicitudes.', 1;

    -- Set 0: Viaje asignado (con viaje_id para enlace directo)
    SELECT TOP (1)
        vs.viaje_solicitud_id,
        vs.viaje_id,
        v.codigo_viaje,
        v.estado AS estado_viaje,
        vs.estado AS estado_asignacion,
        veh.placa
    FROM dbo.viaje_solicitudes vs
    JOIN dbo.viajes v ON v.viaje_id = vs.viaje_id
    JOIN dbo.vehiculos veh ON veh.vehiculo_id = v.vehiculo_id
    WHERE vs.solicitud_id = @solicitud_id
    ORDER BY vs.viaje_solicitud_id DESC;

    -- Set 1: Entrega registrada
    SELECT TOP (1)
        e.entrega_id,
        e.estado,
        e.receptor_nombre,
        e.receptor_identificacion,
        e.receptor_telefono,
        e.fecha_recepcion,
        e.fecha_resultado,
        e.comprobante_url,
        e.motivo_fallo
    FROM dbo.viaje_solicitudes vs
    JOIN dbo.entregas e ON e.viaje_solicitud_id = vs.viaje_solicitud_id
    WHERE vs.solicitud_id = @solicitud_id
    ORDER BY e.entrega_id DESC;

    -- Set 2: Documentos asociados
    SELECT
        d.documento_id,
        td.nombre AS tipo_documento,
        d.numero_referencia,
        d.fecha_documento,
        d.archivo_url,
        d.nombre_archivo_original
    FROM dbo.documentos d
    JOIN dbo.tipos_documento td ON td.tipo_documento_id = d.tipo_documento_id
    WHERE d.solicitud_id = @solicitud_id
    ORDER BY d.fecha_registro DESC, d.documento_id DESC;

    -- Set 3: Incidencias asociadas
    SELECT
        i.incidencia_id,
        ti.nombre AS tipo_incidencia,
        i.estado,
        i.descripcion,
        i.fecha_reporte
    FROM dbo.incidencias i
    JOIN dbo.tipos_incidencia ti ON ti.tipo_incidencia_id = i.tipo_incidencia_id
    WHERE i.solicitud_id = @solicitud_id
    ORDER BY i.fecha_reporte DESC, i.incidencia_id DESC;
END;
GO

-- 13. Torre de control operativa para Panel General (una sola llamada SQL, respeta permisos en PHP)
CREATE OR ALTER PROCEDURE dbo.sp_obtener_alertas_panel
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @usuario INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF @usuario IS NULL OR @usuario < 1
        THROW 50103, N'Sesión de usuario inválida.', 1;

    DECLARE @ahora DATETIME2 = SYSUTCDATETIME();
    DECLARE @hoy DATE = CAST(DATEADD(HOUR, -6, @ahora) AS DATE);

    -- Set 0: Contadores de control operativo
    SELECT
        (SELECT COUNT(*) FROM dbo.solicitudes WHERE estado IN ('REGISTRADA', 'PENDIENTE_ASIGNACION')) AS solicitudes_sin_asignar,
        (SELECT COUNT(*) FROM dbo.solicitudes WHERE estado IN ('REGISTRADA', 'PENDIENTE_ASIGNACION', 'ASIGNADA', 'EN_TRANSITO') AND fecha_entrega_requerida < @ahora) AS solicitudes_atrasadas,
        (SELECT COUNT(*) FROM dbo.viaje_solicitudes vs JOIN dbo.viajes v ON v.viaje_id = vs.viaje_id WHERE vs.estado = 'EN_TRANSITO' AND v.estado = 'EN_CURSO' AND NOT EXISTS (SELECT 1 FROM dbo.entregas e WHERE e.viaje_solicitud_id = vs.viaje_solicitud_id)) AS entregas_pendientes,
        (SELECT COUNT(*) FROM dbo.incidencias WHERE estado IN ('ABIERTA', 'EN_ATENCION')) AS incidencias_abiertas,
        (SELECT COUNT(DISTINCT cl.conductor_id) FROM dbo.conductores_licencias cl JOIN dbo.conductores c ON c.conductor_id = cl.conductor_id WHERE c.activo = 1 AND cl.estado_verificacion = 'VERIFICADA' AND cl.fecha_vencimiento <= DATEADD(DAY, 30, @hoy)) AS licencias_alerta;

    -- Set 1: Próximas solicitudes sin asignar ordenadas por fecha compromiso (urgentes primero)
    SELECT TOP (6)
        s.solicitud_id,
        s.codigo_solicitud,
        COALESCE(
            NULLIF(LTRIM(RTRIM(CONCAT(cf.nombre, N' ', cf.primer_apellido))), N''),
            cj.nombre_comercial,
            cj.razon_social,
            c.identificacion
        ) AS cliente_nombre,
        uo.nombre AS origen_nombre,
        ud.nombre AS destino_nombre,
        s.prioridad_actual,
        s.estado,
        s.fecha_entrega_requerida,
        CASE WHEN s.fecha_entrega_requerida < @ahora THEN 1 ELSE 0 END AS esta_atrasada
    FROM dbo.solicitudes s
    JOIN dbo.clientes c ON c.cliente_id = s.cliente_id
    LEFT JOIN dbo.clientes_fisicos cf ON cf.cliente_id = c.cliente_id
    LEFT JOIN dbo.clientes_juridicos cj ON cj.cliente_id = c.cliente_id
    JOIN dbo.ubicaciones uo ON uo.ubicacion_id = s.origen_id
    JOIN dbo.ubicaciones ud ON ud.ubicacion_id = s.destino_id
    WHERE s.estado IN ('REGISTRADA', 'PENDIENTE_ASIGNACION')
    ORDER BY
        CASE WHEN s.fecha_entrega_requerida < @ahora THEN 0 ELSE 1 END ASC,
        CASE s.prioridad_actual WHEN 'URGENTE' THEN 0 WHEN 'ALTA' THEN 1 WHEN 'NORMAL' THEN 2 ELSE 3 END ASC,
        s.fecha_entrega_requerida ASC;

    -- Set 2: Conductores activos con licencia vencida o próxima a vencer (<= 30 días)
    SELECT TOP (6)
        c.conductor_id,
        LTRIM(RTRIM(CONCAT(c.nombre, N' ', c.primer_apellido, CASE WHEN NULLIF(LTRIM(RTRIM(c.segundo_apellido)), N'') IS NOT NULL THEN N' ' + LTRIM(RTRIM(c.segundo_apellido)) ELSE N'' END))) AS conductor_nombre,
        cl.codigo_licencia AS categoria_codigo,
        cl.fecha_vencimiento,
        DATEDIFF(DAY, @hoy, cl.fecha_vencimiento) AS dias_restantes
    FROM dbo.conductores_licencias cl
    JOIN dbo.conductores c ON c.conductor_id = cl.conductor_id
    WHERE c.activo = 1
      AND cl.estado_verificacion = 'VERIFICADA'
      AND cl.fecha_vencimiento <= DATEADD(DAY, 30, @hoy)
    ORDER BY cl.fecha_vencimiento ASC;
END;
GO

-- 14. Extender dbo.sp_listar_viajes con @buscar opcional y resumen de paquetes/entregas (retrocompatible)
CREATE OR ALTER PROCEDURE dbo.sp_listar_viajes
    @estado VARCHAR(20) = NULL,
    @desde DATETIME2 = NULL,
    @hasta DATETIME2 = NULL,
    @pagina INT = 1,
    @por_pagina INT = 20,
    @total_registros INT OUTPUT,
    @buscar NVARCHAR(100) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    DECLARE @offset BIGINT;
    DECLARE @patron NVARCHAR(102) = NULL;

    IF dbo.fn_tiene_permiso(@usuario_id, N'VIAJES_VER') = 0
        THROW 50103, N'No tiene permiso para consultar viajes.', 1;

    IF @pagina < 1 OR @por_pagina NOT BETWEEN 1 AND 100 OR (@desde IS NOT NULL AND @hasta IS NOT NULL AND @desde > @hasta)
        THROW 50102, N'Filtros o paginación inválidos.', 1;

    IF @estado IS NOT NULL AND @estado NOT IN ('PLANIFICADO', 'EN_CURSO', 'FINALIZADO', 'CANCELADO')
        THROW 50102, N'Estado de viaje inválido.', 1;

    SET @buscar = NULLIF(LTRIM(RTRIM(@buscar)), N'');
    IF @buscar IS NOT NULL
        SET @patron = N'%' + @buscar + N'%';

    SET @offset = CONVERT(BIGINT, @pagina - 1) * @por_pagina;

    SELECT @total_registros = COUNT(*)
    FROM dbo.viajes AS v
    JOIN dbo.vehiculos AS ve ON ve.vehiculo_id = v.vehiculo_id
    JOIN dbo.conductores AS c ON c.conductor_id = v.conductor_id
    JOIN dbo.rutas AS r ON r.ruta_id = v.ruta_id
    WHERE (@estado IS NULL OR v.estado = @estado)
      AND (@desde IS NULL OR v.fecha_programada_salida >= @desde)
      AND (@hasta IS NULL OR v.fecha_programada_salida < DATEADD(DAY, 1, @hasta))
      AND (
          @patron IS NULL
          OR v.codigo_viaje LIKE @patron
          OR ve.placa LIKE @patron
          OR CONCAT(c.nombre, N' ', c.primer_apellido) LIKE @patron
          OR r.nombre LIKE @patron
      );

    SELECT
        v.viaje_id,
        v.codigo_viaje,
        v.estado,
        v.fecha_programada_salida,
        v.fecha_salida_real,
        v.fecha_llegada_real,
        ve.vehiculo_id,
        ve.placa,
        c.conductor_id,
        CONCAT(c.nombre, N' ', c.primer_apellido) AS conductor_nombre,
        r.ruta_id,
        r.nombre AS ruta_nombre,
        (SELECT COUNT(*) FROM dbo.viaje_solicitudes vs WHERE vs.viaje_id = v.viaje_id) AS total_solicitudes,
        (SELECT COUNT(*) FROM dbo.viaje_solicitudes vs WHERE vs.viaje_id = v.viaje_id AND vs.estado IN ('ENTREGADA', 'FALLIDA')) AS entregas_registradas
    FROM dbo.viajes AS v
    JOIN dbo.vehiculos AS ve ON ve.vehiculo_id = v.vehiculo_id
    JOIN dbo.conductores AS c ON c.conductor_id = v.conductor_id
    JOIN dbo.rutas AS r ON r.ruta_id = v.ruta_id
    WHERE (@estado IS NULL OR v.estado = @estado)
      AND (@desde IS NULL OR v.fecha_programada_salida >= @desde)
      AND (@hasta IS NULL OR v.fecha_programada_salida < DATEADD(DAY, 1, @hasta))
      AND (
          @patron IS NULL
          OR v.codigo_viaje LIKE @patron
          OR ve.placa LIKE @patron
          OR CONCAT(c.nombre, N' ', c.primer_apellido) LIKE @patron
          OR r.nombre LIKE @patron
      )
    ORDER BY v.fecha_programada_salida DESC, v.viaje_id DESC
    OFFSET @offset ROWS FETCH NEXT @por_pagina ROWS ONLY;
END;
GO

-- 15. Peso y volumen total de solicitudes disponibles para asignación a viajes
CREATE OR ALTER PROCEDURE dbo.sp_listar_pesos_solicitudes_disponibles
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@usuario_id, N'VIAJES_ASIGNAR') = 0
       AND dbo.fn_tiene_permiso(@usuario_id, N'SOLICITUDES_VER') = 0
        THROW 50103, N'No tiene permiso para consultar pesos de solicitudes.', 1;

    SELECT
        s.solicitud_id,
        ISNULL(SUM(m.peso_kg), 0) AS peso_total_kg,
        ISNULL(SUM(ISNULL(m.volumen_m3, 0)), 0) AS volumen_total_m3
    FROM dbo.solicitudes s
    LEFT JOIN dbo.mercancias m ON m.solicitud_id = s.solicitud_id
    WHERE s.estado IN ('REGISTRADA', 'PENDIENTE_ASIGNACION')
    GROUP BY s.solicitud_id;
END;
GO

-- 16. Despacho inteligente: datos autoritativos de viajes PLANIFICADO para sugerir asignación óptima
CREATE OR ALTER PROCEDURE dbo.sp_sugerir_viajes_para_solicitud
    @solicitud_id INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@usuario_id, N'SOLICITUDES_VER') = 0
       OR dbo.fn_tiene_permiso(@usuario_id, N'VIAJES_VER') = 0
        THROW 50103, N'No tiene permiso para consultar sugerencias de despacho.', 1;

    -- Set 0: Resumen geográfico y de carga de la solicitud
    SELECT
        s.solicitud_id,
        s.codigo_solicitud,
        s.estado,
        s.origen_id,
        uo.nombre AS origen_nombre,
        uo.latitud AS origen_lat,
        uo.longitud AS origen_lon,
        s.destino_id,
        ud.nombre AS destino_nombre,
        ud.latitud AS destino_lat,
        ud.longitud AS destino_lon,
        s.fecha_entrega_requerida,
        ISNULL((SELECT SUM(m.peso_kg) FROM dbo.mercancias m WHERE m.solicitud_id = s.solicitud_id), 0) AS peso_solicitud_kg,
        ISNULL((SELECT SUM(ISNULL(m.volumen_m3, 0)) FROM dbo.mercancias m WHERE m.solicitud_id = s.solicitud_id), 0) AS volumen_solicitud_m3
    FROM dbo.solicitudes s
    JOIN dbo.ubicaciones uo ON uo.ubicacion_id = s.origen_id
    JOIN dbo.ubicaciones ud ON ud.ubicacion_id = s.destino_id
    WHERE s.solicitud_id = @solicitud_id;

    -- Set 1: Todos los viajes PLANIFICADO con capacidad restante y coordenadas de ruta
    SELECT
        v.viaje_id,
        v.codigo_viaje,
        v.fecha_programada_salida,
        veh.placa,
        veh.capacidad_carga_kg,
        veh.capacidad_volumen_m3,
        CONCAT(c.nombre, N' ', c.primer_apellido) AS conductor_nombre,
        r.ruta_id,
        r.nombre AS ruta_nombre,
        r.origen_id AS ruta_origen_id,
        ro.latitud AS ruta_origen_lat,
        ro.longitud AS ruta_origen_lon,
        r.destino_id AS ruta_destino_id,
        rd.latitud AS ruta_destino_lat,
        rd.longitud AS ruta_destino_lon,
        ISNULL((
            SELECT SUM(m.peso_kg)
            FROM dbo.viaje_solicitudes vs
            JOIN dbo.mercancias m ON m.solicitud_id = vs.solicitud_id
            WHERE vs.viaje_id = v.viaje_id
        ), 0) AS peso_usado_kg,
        ISNULL((
            SELECT SUM(ISNULL(m.volumen_m3, 0))
            FROM dbo.viaje_solicitudes vs
            JOIN dbo.mercancias m ON m.solicitud_id = vs.solicitud_id
            WHERE vs.viaje_id = v.viaje_id
        ), 0) AS volumen_usado_m3,
        (SELECT COUNT(*) FROM dbo.viaje_solicitudes vs WHERE vs.viaje_id = v.viaje_id) AS total_solicitudes
    FROM dbo.viajes v
    JOIN dbo.vehiculos veh ON veh.vehiculo_id = v.vehiculo_id
    JOIN dbo.conductores c ON c.conductor_id = v.conductor_id
    JOIN dbo.rutas r ON r.ruta_id = v.ruta_id
    JOIN dbo.ubicaciones ro ON ro.ubicacion_id = r.origen_id
    JOIN dbo.ubicaciones rd ON rd.ubicacion_id = r.destino_id
    WHERE v.estado = 'PLANIFICADO'
    ORDER BY v.fecha_programada_salida ASC, v.viaje_id ASC;

    -- Set 2: Paradas actuales de los viajes PLANIFICADO
    SELECT
        p.viaje_id,
        p.parada_id,
        p.ubicacion_id,
        p.orden,
        p.tipo_parada,
        u.latitud,
        u.longitud
    FROM dbo.paradas_viaje p
    JOIN dbo.viajes v ON v.viaje_id = p.viaje_id
    JOIN dbo.ubicaciones u ON u.ubicacion_id = p.ubicacion_id
    WHERE v.estado = 'PLANIFICADO'
    ORDER BY p.viaje_id ASC, p.orden ASC;
END;
GO

-- Enriquecer sp_listar_pesos_solicitudes_disponibles con coordenadas de origen/destino para ranking en Asignar solicitud
CREATE OR ALTER PROCEDURE dbo.sp_listar_pesos_solicitudes_disponibles
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@usuario_id, N'VIAJES_ASIGNAR') = 0
       AND dbo.fn_tiene_permiso(@usuario_id, N'SOLICITUDES_VER') = 0
        THROW 50103, N'No tiene permiso para consultar pesos de solicitudes.', 1;

    SELECT
        s.solicitud_id,
        ISNULL(SUM(m.peso_kg), 0) AS peso_total_kg,
        ISNULL(SUM(ISNULL(m.volumen_m3, 0)), 0) AS volumen_total_m3,
        uo.latitud AS origen_lat,
        uo.longitud AS origen_lon,
        ud.latitud AS destino_lat,
        ud.longitud AS destino_lon,
        s.fecha_entrega_requerida
    FROM dbo.solicitudes s
    JOIN dbo.ubicaciones uo ON uo.ubicacion_id = s.origen_id
    JOIN dbo.ubicaciones ud ON ud.ubicacion_id = s.destino_id
    LEFT JOIN dbo.mercancias m ON m.solicitud_id = s.solicitud_id
    WHERE s.estado IN ('REGISTRADA', 'PENDIENTE_ASIGNACION')
    GROUP BY s.solicitud_id, uo.latitud, uo.longitud, ud.latitud, ud.longitud, s.fecha_entrega_requerida;
END;
GO

-- Enriquecer sp_obtener_capacidad_viaje con coordenadas de ruta del viaje
CREATE OR ALTER PROCEDURE dbo.sp_obtener_capacidad_viaje
    @viaje_id INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @usuario INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@usuario, N'VIAJES_VER') = 0
        THROW 50103, N'No tiene permiso para consultar viajes.', 1;

    SELECT
        v.viaje_id,
        veh.capacidad_carga_kg,
        veh.capacidad_volumen_m3,
        ISNULL((
            SELECT SUM(m.peso_kg)
            FROM dbo.viaje_solicitudes vs
            JOIN dbo.mercancias m ON m.solicitud_id = vs.solicitud_id
            WHERE vs.viaje_id = v.viaje_id
        ), 0) AS peso_usado_kg,
        ISNULL((
            SELECT SUM(ISNULL(m.volumen_m3, 0))
            FROM dbo.viaje_solicitudes vs
            JOIN dbo.mercancias m ON m.solicitud_id = vs.solicitud_id
            WHERE vs.viaje_id = v.viaje_id
        ), 0) AS volumen_usado_m3,
        r.origen_id AS ruta_origen_id,
        ro.latitud AS ruta_origen_lat,
        ro.longitud AS ruta_origen_lon,
        r.destino_id AS ruta_destino_id,
        rd.latitud AS ruta_destino_lat,
        rd.longitud AS ruta_destino_lon
    FROM dbo.viajes v
    JOIN dbo.vehiculos veh ON veh.vehiculo_id = v.vehiculo_id
    JOIN dbo.rutas r ON r.ruta_id = v.ruta_id
    JOIN dbo.ubicaciones ro ON ro.ubicacion_id = r.origen_id
    JOIN dbo.ubicaciones rd ON rd.ubicacion_id = r.destino_id
    WHERE v.viaje_id = @viaje_id;
END;
GO

-- 17. Consola administrativa de Respaldos y Recuperación (FULL, DIFF, LOG .trn con RPO 15 min)
CREATE OR ALTER PROCEDURE dbo.sp_listar_respaldos_y_jobs
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@usuario_id, N'AUDITORIA_VER') = 0
       OR dbo.fn_tiene_permiso(@usuario_id, N'USUARIOS_GESTIONAR_CUENTAS') = 0
        THROW 50103, N'No tiene permiso para administrar respaldos.', 1;

    DECLARE @db SYSNAME = DB_NAME();
    DECLARE @dir NVARCHAR(400) = CONVERT(NVARCHAR(400), SERVERPROPERTY('InstanceDefaultBackupPath'));
    DECLARE @recovery NVARCHAR(60) = (SELECT recovery_model_desc FROM sys.databases WHERE name = @db);
    DECLARE @agentActivo BIT = CASE WHEN EXISTS (SELECT 1 FROM sys.dm_server_services WHERE servicename LIKE N'SQL Server Agent%' AND status_desc = N'Running') THEN 1 ELSE 0 END;

    -- Set 0: Estado actual de la base y política de respaldos
    SELECT
        @db AS base_datos,
        @recovery AS recovery_model_desc,
        @dir AS ruta_predeterminada_backups,
        CASE WHEN EXISTS (SELECT 1 FROM msdb.dbo.backupset WHERE database_name = @db AND type = 'D' AND is_copy_only = 0) THEN 1 ELSE 0 END AS tiene_full_base,
        (SELECT MAX(backup_finish_date) FROM msdb.dbo.backupset WHERE database_name = @db AND type = 'D' AND is_copy_only = 0) AS ultimo_full,
        (SELECT MAX(backup_finish_date) FROM msdb.dbo.backupset WHERE database_name = @db AND type = 'I') AS ultimo_diff,
        (SELECT MAX(backup_finish_date) FROM msdb.dbo.backupset WHERE database_name = @db AND type = 'L') AS ultimo_log,
        @agentActivo AS agent_activo;

    -- Set 1: Últimos 25 respaldos reales registrados en msdb.dbo.backupset
    SELECT TOP (25)
        b.backup_set_id,
        b.type AS tipo_codigo,
        CASE b.type
            WHEN 'D' THEN N'FULL (.bak)'
            WHEN 'I' THEN N'DIFF (.bak)'
            WHEN 'L' THEN N'LOG (.trn)'
            ELSE b.type
        END AS tipo_etiqueta,
        b.backup_start_date AS fecha_inicio,
        b.backup_finish_date AS fecha_fin,
        CAST(b.backup_size / 1048576.0 AS DECIMAL(12,2)) AS tamano_mb,
        CAST(b.compressed_backup_size / 1048576.0 AS DECIMAL(12,2)) AS comprimido_mb,
        f.physical_device_name AS ruta_fisica,
        b.has_backup_checksums AS tiene_checksum,
        b.user_name AS usuario_motor,
        CONVERT(NVARCHAR(40), b.checkpoint_lsn) AS checkpoint_lsn
    FROM msdb.dbo.backupset AS b
    JOIN msdb.dbo.backupmediafamily AS f ON f.media_set_id = b.media_set_id
    WHERE b.database_name = @db
    ORDER BY b.backup_set_id DESC;

    -- Set 2: Jobs de SQL Server Agent relacionados con el proyecto
    SELECT
        j.name AS job_name,
        j.enabled,
        j.description,
        s.name AS schedule_name,
        s.freq_type,
        s.freq_subday_type,
        s.freq_subday_interval
    FROM msdb.dbo.sysjobs AS j
    LEFT JOIN msdb.dbo.sysjobschedules AS js ON js.job_id = j.job_id
    LEFT JOIN msdb.dbo.sysschedules AS s ON s.schedule_id = js.schedule_id
    WHERE j.name LIKE N'G6_%' OR j.name LIKE N'%TransportesSegurosSA%'
    ORDER BY j.name ASC;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_ejecutar_respaldo_operativo
    @tipo VARCHAR(10),
    @ruta_generada NVARCHAR(500) OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@usuario_id, N'AUDITORIA_VER') = 0
       OR dbo.fn_tiene_permiso(@usuario_id, N'USUARIOS_GESTIONAR_CUENTAS') = 0
        THROW 50103, N'No tiene permiso para generar respaldos.', 1;

    SET @tipo = UPPER(LTRIM(RTRIM(ISNULL(@tipo, ''))));
    IF @tipo NOT IN ('FULL', 'DIFF', 'LOG')
        THROW 50102, N'Tipo de respaldo inválido. Use FULL, DIFF o LOG.', 1;

    DECLARE @db SYSNAME = DB_NAME();
    DECLARE @recovery NVARCHAR(60) = (SELECT recovery_model_desc FROM sys.databases WHERE name = @db);

    IF @tipo IN ('DIFF', 'LOG') AND NOT EXISTS (
        SELECT 1 FROM msdb.dbo.backupset
        WHERE database_name = @db AND type = 'D' AND is_copy_only = 0
    )
        THROW 50911, N'Primero debe existir un respaldo FULL base antes de ejecutar un diferencial o log.', 1;

    IF @tipo = 'LOG' AND @recovery <> N'FULL'
        THROW 50912, N'La base de datos debe estar en modelo de recuperación FULL para respaldar el Transaction Log (.trn).', 1;

    DECLARE @dir NVARCHAR(400) = CONVERT(NVARCHAR(400), SERVERPROPERTY('InstanceDefaultBackupPath'));
    IF @dir IS NULL OR LTRIM(RTRIM(@dir)) = N''
        THROW 50913, N'No se pudo resolver la ruta predeterminada de respaldos del motor SQL Server.', 1;

    IF RIGHT(@dir, 1) NOT IN (N'\', N'/')
        SET @dir = @dir + N'\';

    DECLARE @ext NVARCHAR(4) = CASE WHEN @tipo = 'LOG' THEN N'.trn' ELSE N'.bak' END;
    DECLARE @stamp NVARCHAR(20) = CONVERT(CHAR(8), GETDATE(), 112) + N'_' + REPLACE(CONVERT(CHAR(8), GETDATE(), 108), N':', N'');
    DECLARE @ruta NVARCHAR(500) = @dir + @db + N'_WEB_' + @tipo + N'_' + @stamp + @ext;

    DECLARE @sqlBackup NVARCHAR(MAX);
    IF @tipo = 'FULL'
        SET @sqlBackup = N'BACKUP DATABASE ' + QUOTENAME(@db) + N' TO DISK = @p_ruta WITH INIT, COMPRESSION, CHECKSUM;';
    ELSE IF @tipo = 'DIFF'
        SET @sqlBackup = N'BACKUP DATABASE ' + QUOTENAME(@db) + N' TO DISK = @p_ruta WITH DIFFERENTIAL, INIT, COMPRESSION, CHECKSUM;';
    ELSE
        SET @sqlBackup = N'BACKUP LOG ' + QUOTENAME(@db) + N' TO DISK = @p_ruta WITH INIT, COMPRESSION, CHECKSUM;';

    EXEC sys.sp_executesql @sqlBackup, N'@p_ruta NVARCHAR(500)', @p_ruta = @ruta;

    -- Verificación inmediata de integridad del archivo generado
    DECLARE @sqlVerify NVARCHAR(MAX) = N'RESTORE VERIFYONLY FROM DISK = @p_ruta WITH CHECKSUM;';
    EXEC sys.sp_executesql @sqlVerify, N'@p_ruta NVARCHAR(500)', @p_ruta = @ruta;

    SET @ruta_generada = @ruta;

    -- Registrar evento en bitácora de auditoría
    INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
    VALUES (
        @usuario_id,
        N'respaldos',
        @db,
        N'BACKUP_' + @tipo,
        NULL,
        LEFT(CONCAT(N'Tipo=', @tipo, N' | Verificado=CHECKSUM+VERIFYONLY | Ruta=', @ruta), 500)
    );
END;
GO

-- 18. Resolver o crear automáticamente la ruta exacta (Origen -> Destino) desde una solicitud
CREATE OR ALTER PROCEDURE dbo.sp_resolver_ruta_desde_solicitud
    @solicitud_id INT,
    @ruta_id INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@usuario_id, N'VIAJES_CREAR') = 0
       OR dbo.fn_tiene_permiso(@usuario_id, N'RUTAS_GESTIONAR') = 0
        THROW 50103, N'No tiene permiso para generar rutas automáticamente desde solicitudes.', 1;

    DECLARE @ori_id INT, @des_id INT;
    DECLARE @ori_nom NVARCHAR(150), @des_nom NVARCHAR(150);
    DECLARE @lat1 FLOAT, @lon1 FLOAT, @lat2 FLOAT, @lon2 FLOAT;

    SELECT
        @ori_id = s.origen_id,
        @des_id = s.destino_id,
        @ori_nom = uo.nombre,
        @des_nom = ud.nombre,
        @lat1 = TRY_CONVERT(FLOAT, uo.latitud),
        @lon1 = TRY_CONVERT(FLOAT, uo.longitud),
        @lat2 = TRY_CONVERT(FLOAT, ud.latitud),
        @lon2 = TRY_CONVERT(FLOAT, ud.longitud)
    FROM dbo.solicitudes s
    JOIN dbo.ubicaciones uo ON uo.ubicacion_id = s.origen_id
    JOIN dbo.ubicaciones ud ON ud.ubicacion_id = s.destino_id
    WHERE s.solicitud_id = @solicitud_id;

    IF @ori_id IS NULL OR @des_id IS NULL OR @ori_id = @des_id
        THROW 50101, N'No se encontraron ubicaciones válidas de origen y destino para la solicitud.', 1;

    -- 1. Reutilizar si ya existe una ruta activa exacta (origen -> destino)
    SELECT TOP (1) @ruta_id = ruta_id
    FROM dbo.rutas WITH (UPDLOCK, HOLDLOCK)
    WHERE origen_id = @ori_id
      AND destino_id = @des_id
      AND activo = 1
    ORDER BY ruta_id ASC;

    IF @ruta_id IS NOT NULL
        RETURN;

    -- 2. Calcular distancia Haversine * 1.25 (factor vial estimado) y duración (~55 km/h)
    DECLARE @dist_km DECIMAL(10,2) = 25.00;
    IF @lat1 IS NOT NULL AND @lon1 IS NOT NULL AND @lat2 IS NOT NULL AND @lon2 IS NOT NULL
    BEGIN
        DECLARE @dLat FLOAT = RADIANS(@lat2 - @lat1);
        DECLARE @dLon FLOAT = RADIANS(@lon2 - @lon1);
        DECLARE @a FLOAT = POWER(SIN(@dLat / 2.0), 2) + COS(RADIANS(@lat1)) * COS(RADIANS(@lat2)) * POWER(SIN(@dLon / 2.0), 2);
        IF @a > 1.0 SET @a = 1.0;
        DECLARE @haversine FLOAT = 2.0 * 6371.0 * ASIN(SQRT(@a));
        SET @dist_km = CAST(ROUND(CASE WHEN @haversine * 1.25 < 5.0 THEN 5.0 ELSE @haversine * 1.25 END, 1) AS DECIMAL(10,2));
    END

    DECLARE @dur_min INT = CONVERT(INT, ROUND((CONVERT(FLOAT, @dist_km) / 55.0) * 60.0, 0));
    IF @dur_min < 15 SET @dur_min = 15;

    DECLARE @nombre_ruta NVARCHAR(150) = LEFT(CONCAT(LTRIM(RTRIM(@ori_nom)), N' → ', LTRIM(RTRIM(@des_nom))), 150);

    -- Si existía una ruta inactiva con el mismo nombre exacto, reactivarla en lugar de duplicar
    SELECT TOP (1) @ruta_id = ruta_id
    FROM dbo.rutas WITH (UPDLOCK, HOLDLOCK)
    WHERE origen_id = @ori_id AND destino_id = @des_id AND nombre = @nombre_ruta;

    IF @ruta_id IS NOT NULL
    BEGIN
        UPDATE dbo.rutas
        SET activo = 1, distancia_km = @dist_km, duracion_estimada_min = @dur_min
        WHERE ruta_id = @ruta_id;
        RETURN;
    END

    INSERT INTO dbo.rutas (nombre, origen_id, destino_id, distancia_km, duracion_estimada_min, activo)
    VALUES (@nombre_ruta, @ori_id, @des_id, @dist_km, @dur_min, 1);

    SET @ruta_id = CONVERT(INT, SCOPE_IDENTITY());
END;
GO
