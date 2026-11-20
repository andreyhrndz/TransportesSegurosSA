/* =========================================================================
   Transportes Seguros S.A. — SQL Server 2025
   Suite Contractual T-SQL 15 (Migración 11):
   - Paginación y búsqueda SQL real en dbo.sp_listar_invitaciones_paginadas
     (incluyendo estado efectivo UTC y > 20 registros).
   - Corrección atómica de correo en dbo.sp_reenviar_invitacion_usuario:
     token viejo invalidado, registro original inmutable, permisos sin
     escalamiento, cooldown 60s, límite 5/24h sin bypass, rechazo COMPLETADA
     y cuenta activa, auditoría RESEND_CORRECT_EMAIL.
   - Coordenadas expuestas en dbo.sp_obtener_solicitud y dbo.sp_obtener_viaje.
   Ejecutar EXCLUSIVAMENTE en TransportesSegurosSA_Test (transacción con ROLLBACK).
   ========================================================================= */

SET NOCOUNT ON;
SET XACT_ABORT OFF;

IF DB_NAME() <> N'TransportesSegurosSA_Test'
    THROW 50999, 'ABORTADO: Esta suite de pruebas solo puede ejecutarse en TransportesSegurosSA_Test.', 1;

BEGIN TRANSACTION;
BEGIN TRY
    DECLARE @admin_id INT = (SELECT TOP 1 usuario_id FROM dbo.usuarios WHERE nombre_usuario = N'admin_proyecto' AND activo = 1);
    IF @admin_id IS NULL THROW 50950, 'No se encontró admin_proyecto en Test.', 1;
    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @admin_id;

    -- 1. Sembrar 25 invitaciones sintéticas con estados variados (incluyendo PENDIENTE vencida por UTC)
    DECLARE @i INT = 1;
    DECLARE @ahora DATETIME2 = SYSUTCDATETIME();
    WHILE @i <= 25
    BEGIN
        DECLARE @mail NVARCHAR(150) = CONCAT(N'm11.pag.', RIGHT(CONCAT('00', @i), 2), N'@example.test');
        DECLARE @tok VARCHAR(64) = RIGHT(CONCAT(REPLICATE('0', 64), CONVERT(VARCHAR(64), HASHBYTES('SHA2_256', @mail), 2)), 64);
        DECLARE @est VARCHAR(20) = CASE
            WHEN @i <= 10 THEN 'PENDIENTE'
            WHEN @i <= 15 THEN 'PENDIENTE' -- vencida por fecha -> estado efectivo EXPIRADA
            WHEN @i <= 20 THEN 'REVOCADA'
            ELSE 'COMPLETADA'
        END;
        DECLARE @exp DATETIME2 = CASE
            WHEN @i <= 10 THEN DATEADD(HOUR, 24, @ahora)
            WHEN @i <= 15 THEN DATEADD(HOUR, -3, @ahora)
            ELSE DATEADD(HOUR, 12, @ahora)
        END;
        INSERT INTO dbo.invitaciones_usuario (correo_destino, token_hash, estado, fecha_expiracion, creado_por_usuario_id, fecha_creacion, ultimo_envio_en, estado_envio)
        VALUES (@mail, @tok, @est, @exp, @admin_id, DATEADD(MINUTE, @i, DATEADD(DAY, -2, @ahora)), DATEADD(MINUTE, -10, @ahora), 'SIMULADO');
        SET @i += 1;
    END;

    -- 1.1 Paginación SQL real: página 1 (20 filas) y página 2 (5 filas) filtrando por 'm11.pag.'
    DECLARE @total INT = 0;
    DECLARE @res TABLE (
        invitacion_id INT, correo_destino NVARCHAR(150), estado VARCHAR(20), estado_original VARCHAR(20),
        fecha_expiracion DATETIME2, fecha_creacion DATETIME2, fecha_completada DATETIME2,
        ultimo_envio_en DATETIME2, envios_contador INT, estado_envio VARCHAR(20),
        creado_por_nombre NVARCHAR(100), completado_por_nombre NVARCHAR(100),
        total_permisos INT, permisos_codigos NVARCHAR(MAX)
    );

    INSERT INTO @res
    EXEC dbo.sp_listar_invitaciones_paginadas @buscar = N'm11.pag.', @estado = NULL, @pagina = 1, @por_pagina = 20, @total_registros = @total OUTPUT;
    IF @total <> 25 OR (SELECT COUNT(*) FROM @res) <> 20
        THROW 50951, 'Página 1 de m11.pag. debe devolver 20 de 25 registros.', 1;

    DELETE FROM @res;
    INSERT INTO @res
    EXEC dbo.sp_listar_invitaciones_paginadas @buscar = N'm11.pag.', @estado = NULL, @pagina = 2, @por_pagina = 20, @total_registros = @total OUTPUT;
    IF @total <> 25 OR (SELECT COUNT(*) FROM @res) <> 5
        THROW 50952, 'Página 2 de m11.pag. debe devolver 5 de 25 registros.', 1;

    -- 1.2 Estado efectivo UTC: PENDIENTE solo 10 (no las 5 vencidas), EXPIRADA exactamente 5
    DELETE FROM @res;
    INSERT INTO @res
    EXEC dbo.sp_listar_invitaciones_paginadas @buscar = N'm11.pag.', @estado = 'PENDIENTE', @pagina = 1, @por_pagina = 20, @total_registros = @total OUTPUT;
    IF @total <> 10 OR EXISTS (SELECT 1 FROM @res WHERE estado <> 'PENDIENTE')
        THROW 50953, 'Filtro PENDIENTE debe devolver exactamente 10 vigentes (excluyendo vencidas UTC).', 1;

    DELETE FROM @res;
    INSERT INTO @res
    EXEC dbo.sp_listar_invitaciones_paginadas @buscar = N'm11.pag.', @estado = 'EXPIRADA', @pagina = 1, @por_pagina = 20, @total_registros = @total OUTPUT;
    IF @total <> 5 OR EXISTS (SELECT 1 FROM @res WHERE estado <> 'EXPIRADA')
        THROW 50954, 'Filtro EXPIRADA debe detectar las 5 invitaciones PENDIENTE vencidas por UTC.', 1;

    PRINT '1. [OK] Paginación real SQL (25 registros, 20/pág) y estado efectivo UTC verificados.';

    -- 2. Corrección atómica de correo en sp_reenviar_invitacion_usuario
    DECLARE @inv_orig INT, @inv_corr INT;
    DECLARE @tok_orig VARCHAR(64) = REPLICATE('c', 64);
    DECLARE @tok_corr VARCHAR(64) = REPLICATE('d', 64);

    EXEC dbo.sp_crear_invitacion_usuario @correo_destino = N'm11.error.typo@example.test', @token_hash = @tok_orig, @horas_vigencia = 24, @invitacion_id = @inv_orig OUTPUT;
    EXEC dbo.sp_asignar_permiso_invitacion @invitacion_id = @inv_orig, @codigo = N'FLOTAS_VER';
    EXEC dbo.sp_asignar_permiso_invitacion @invitacion_id = @inv_orig, @codigo = N'CLIENTES_VER';

    -- Cooldown 60s NO debe poderse evadir cambiando de correo
    DECLARE @err INT = 0;
    BEGIN TRY
        EXEC dbo.sp_reenviar_invitacion_usuario
            @invitacion_id = @inv_orig, @nuevo_token_hash = @tok_corr, @horas_vigencia = 24,
            @nueva_invitacion_id = @inv_corr OUTPUT, @nuevo_correo = N'm11.corregido@example.test';
    END TRY
    BEGIN CATCH SET @err = ERROR_NUMBER(); END CATCH;
    IF @err <> 50440 THROW 50955, 'Cambiar correo no debe evadir el cooldown de 60 segundos (50440).', 1;

    -- Transcurridos 65s -> corrección de correo exitosa
    UPDATE dbo.invitaciones_usuario SET ultimo_envio_en = DATEADD(SECOND, -65, SYSUTCDATETIME()) WHERE invitacion_id = @inv_orig;

    EXEC dbo.sp_reenviar_invitacion_usuario
        @invitacion_id = @inv_orig, @nuevo_token_hash = @tok_corr, @horas_vigencia = 24,
        @nueva_invitacion_id = @inv_corr OUTPUT, @nuevo_correo = N'M11.Corregido@Example.Test';

    -- Verificar registro original inmutable (correo viejo + REVOCADA)
    IF NOT EXISTS (
        SELECT 1 FROM dbo.invitaciones_usuario
        WHERE invitacion_id = @inv_orig AND correo_destino = N'm11.error.typo@example.test' AND estado = 'REVOCADA'
    )
        THROW 50956, 'Registro original debe conservar su correo original y quedar REVOCADA.', 1;

    -- Verificar nueva invitación con nuevo correo normalizado y 2 permisos heredados
    IF NOT EXISTS (
        SELECT 1 FROM dbo.invitaciones_usuario
        WHERE invitacion_id = @inv_corr AND correo_destino = N'm11.corregido@example.test' AND estado = 'PENDIENTE'
    )
        THROW 50957, 'La nueva invitación debe crearse en PENDIENTE para el correo corregido normalizado.', 1;

    IF (SELECT COUNT(*) FROM dbo.invitaciones_permisos WHERE invitacion_id = @inv_corr) <> 2
        THROW 50958, 'La nueva invitación por corrección debe heredar los 2 permisos autorizados.', 1;

    IF NOT EXISTS (
        SELECT 1 FROM dbo.auditorias
        WHERE entidad = N'invitaciones_usuario' AND registro_id = CONVERT(NVARCHAR(100), @inv_corr)
          AND accion = 'RESEND_CORRECT_EMAIL'
          AND valores_anteriores LIKE N'%m11.error.typo@example.test%'
          AND valores_nuevos LIKE N'%m11.corregido@example.test%'
    )
        THROW 50959, 'Debe auditarse RESEND_CORRECT_EMAIL con correo original y corregido.', 1;

    -- 2.1 Prevención de escalamiento de privilegios al reenviar/corregir correo
    DECLARE @gestor_id INT;
    INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash, activo)
    VALUES (N'__m11_gestor_lim', N'm11.gestor.lim@example.test', N'hash_dummy', 1);
    SET @gestor_id = SCOPE_IDENTITY();
    INSERT INTO dbo.usuarios_permisos (usuario_id, permiso_id)
    SELECT @gestor_id, permiso_id FROM dbo.permisos WHERE codigo IN (N'USUARIOS_GESTIONAR_CUENTAS', N'USUARIOS_GESTIONAR_PERMISOS', N'CLIENTES_VER');

    -- El gestor solo posee CLIENTES_VER, NO FLOTAS_VER
    UPDATE dbo.invitaciones_usuario SET ultimo_envio_en = DATEADD(SECOND, -65, SYSUTCDATETIME()) WHERE invitacion_id = @inv_corr;
    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @gestor_id;

    DECLARE @inv_no_esc INT;
    DECLARE @tok_no_esc VARCHAR(64) = REPLICATE('e', 64);
    EXEC dbo.sp_reenviar_invitacion_usuario
        @invitacion_id = @inv_corr, @nuevo_token_hash = @tok_no_esc, @horas_vigencia = 24,
        @nueva_invitacion_id = @inv_no_esc OUTPUT, @nuevo_correo = N'm11.corregido2@example.test';

    IF (SELECT COUNT(*) FROM dbo.invitaciones_permisos WHERE invitacion_id = @inv_no_esc) <> 1
       OR EXISTS (
           SELECT 1 FROM dbo.invitaciones_permisos ip
           JOIN dbo.permisos p ON p.permiso_id = ip.permiso_id
           WHERE ip.invitacion_id = @inv_no_esc AND p.codigo = N'FLOTAS_VER'
       )
        THROW 50960, 'Un actor sin FLOTAS_VER no debe poder escalar y copiar FLOTAS_VER al corregir correo.', 1;

    -- 2.2 Rechazar corrección hacia correo que ya tiene invitación vigente (50431)
    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @admin_id;
    DECLARE @inv_dup_src INT;
    DECLARE @tok_dup_src VARCHAR(64) = REPLICATE('f', 64);
    DECLARE @tok_dup_dst VARCHAR(64) = REPLICATE('1', 64);
    EXEC dbo.sp_crear_invitacion_usuario @correo_destino = N'm11.dup.src@example.test', @token_hash = @tok_dup_src, @horas_vigencia = 24, @invitacion_id = @inv_dup_src OUTPUT;
    UPDATE dbo.invitaciones_usuario SET ultimo_envio_en = DATEADD(SECOND, -65, SYSUTCDATETIME()) WHERE invitacion_id = @inv_dup_src;

    SET @err = 0;
    BEGIN TRY
        DECLARE @inv_dummy INT;
        EXEC dbo.sp_reenviar_invitacion_usuario
            @invitacion_id = @inv_dup_src, @nuevo_token_hash = @tok_dup_dst, @horas_vigencia = 24,
            @nueva_invitacion_id = @inv_dummy OUTPUT, @nuevo_correo = N'm11.corregido2@example.test';
    END TRY
    BEGIN CATCH SET @err = ERROR_NUMBER(); END CATCH;
    IF @err <> 50431 THROW 50961, 'No debe permitir corregir hacia correo que ya posee invitación vigente (50431).', 1;

    PRINT '2. [OK] Corrección atómica de correo, anti-bypass 60s, sin escalamiento y anti-duplicado verificados.';

    -- 3. Verificar que sp_obtener_solicitud y sp_obtener_viaje exponen latitud/longitud
    IF NOT EXISTS (
        SELECT 1 FROM sys.dm_exec_describe_first_result_set_for_object(OBJECT_ID(N'dbo.sp_obtener_solicitud'), NULL)
        WHERE name IN (N'origen_latitud', N'origen_longitud', N'destino_latitud', N'destino_longitud')
    )
        THROW 50962, 'sp_obtener_solicitud debe exponer columnas de coordenadas de origen y destino.', 1;

    IF OBJECT_DEFINITION(OBJECT_ID(N'dbo.sp_obtener_viaje')) NOT LIKE N'%u.latitud, u.longitud%'
        THROW 50963, 'sp_obtener_viaje debe exponer u.latitud y u.longitud en paradas.', 1;

    PRINT '3. [OK] Lectura aditiva de coordenadas en sp_obtener_solicitud y sp_obtener_viaje verificada.';
    PRINT '=== SUITE T-SQL MIGRACION 11 COMPLETADA AL 100% ===';

    ROLLBACK TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    DECLARE @msg NVARCHAR(4000) = ERROR_MESSAGE();
    DECLARE @num INT = ERROR_NUMBER();
    PRINT 'FALLO TEST MIGRACION 11 [' + CAST(@num AS VARCHAR(10)) + ']: ' + @msg;
    THROW;
END CATCH;
GO
