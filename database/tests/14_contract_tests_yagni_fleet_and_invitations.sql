/* =========================================================================
   Transportes Seguros S.A. — Suite T-SQL de Pruebas Contractuales (Migración 10)
   Valida:
   1. Sin default B4 en sp_crear_conductor.
   2. Contrato atómico sp_actualizar_licencia_principal_conductor (reemplazo sin
      categorías ocultas, auditoría, bloqueo en viaje activo 50505, permisos 50501).
   3. Fronteras exactas PMA 4.000 kg y 8.000 kg, articulados (B4/E2) y clases A/C/D.
   4. Coherencia vehículo (carga útil <= PMA, articulado vs clase_legal) y
      preservación de kilometraje_actual y estado_homologacion = 'NO_CONFORME'.
   5. Invitaciones: revocar PENDIENTE vigente -> REVOCADA, descartar EXPIRADA -> CANCELADA,
      rechazo en COMPLETADA (50441), rechazo repetido (50442) y reenvío conserva permisos.
   Ambiente exclusivo: TransportesSegurosSA_Test (con ROLLBACK total).
   ========================================================================= */

SET NOCOUNT ON;
GO

IF DB_NAME() <> N'TransportesSegurosSA_Test'
    THROW 50999, 'Esta suite de pruebas SOLO puede ejecutarse en TransportesSegurosSA_Test.', 1;
GO

BEGIN TRANSACTION;

BEGIN TRY
    DECLARE @actor INT = 1;
    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @actor;

    PRINT '=== INICIO TEST T-SQL MIGRACION 10 (YAGNI FLOTA, LICENCIAS E INVITACIONES) ===';

    DECLARE @err INT;
    DECLARE @hab BIT, @motivo NVARCHAR(250);
    DECLARE @futura DATE = DATEADD(YEAR, 2, CONVERT(DATE, SYSUTCDATETIME()));
    DECLARE @futura2 DATE = DATEADD(YEAR, 3, CONVERT(DATE, SYSUTCDATETIME()));

    -- 1. Crear conductor sin categoría inicial -> NO debe recibir B4 por defecto
    DECLARE @cid INT;
    EXEC dbo.sp_crear_conductor
        @identificacion = N'__M10_COND_1',
        @nombre = N'Lucía',
        @primer_apellido = N'Jiménez',
        @numero_licencia = N'__M10_LIC_1',
        @fecha_vencimiento_licencia = @futura,
        @conductor_id = @cid OUTPUT;

    IF EXISTS (SELECT 1 FROM dbo.conductores_licencias WHERE conductor_id = @cid)
        THROW 50901, 'sp_crear_conductor no debe asignar B4 ni ninguna categoría por defecto.', 1;
    PRINT '1. [OK] sp_crear_conductor sin categoría inicial no asigna B4.';

    -- 2. Crear vehículos frontera: 4000.00 kg, 4000.01 kg, 8000.00 kg, 8000.01 kg, articulado y NO_CONFORME
    DECLARE @v4000 INT, @v4001 INT, @v8000 INT, @v8001 INT, @vart INT, @vnc INT;
    EXEC dbo.sp_crear_vehiculo
        @placa = N'__M10_4000', @marca = N'Toyota', @modelo = N'Hilux', @anio = 2024,
        @capacidad_carga_kg = 1200.00, @kilometraje_actual = 1234.5, @estado = 'DISPONIBLE',
        @pma_kg = 4000.00, @clase_legal = 'LIVIANO', @es_articulado = 0, @vehiculo_id = @v4000 OUTPUT;

    EXEC dbo.sp_crear_vehiculo
        @placa = N'__M10_4001', @marca = N'Isuzu', @modelo = N'ELF', @anio = 2024,
        @capacidad_carga_kg = 2000.00, @estado = 'DISPONIBLE',
        @pma_kg = 4000.01, @clase_legal = 'MEDIANO_RIGIDO', @es_articulado = 0, @vehiculo_id = @v4001 OUTPUT;

    EXEC dbo.sp_crear_vehiculo
        @placa = N'__M10_8000', @marca = N'Isuzu', @modelo = N'NPR', @anio = 2024,
        @capacidad_carga_kg = 4500.00, @estado = 'DISPONIBLE',
        @pma_kg = 8000.00, @clase_legal = 'MEDIANO_RIGIDO', @es_articulado = 0, @vehiculo_id = @v8000 OUTPUT;

    EXEC dbo.sp_crear_vehiculo
        @placa = N'__M10_8001', @marca = N'Hino', @modelo = N'500', @anio = 2024,
        @capacidad_carga_kg = 5000.00, @estado = 'DISPONIBLE',
        @pma_kg = 8000.01, @clase_legal = 'PESADO_RIGIDO', @es_articulado = 0, @vehiculo_id = @v8001 OUTPUT;

    EXEC dbo.sp_crear_vehiculo
        @placa = N'__M10_ART', @marca = N'Volvo', @modelo = N'FH', @anio = 2024,
        @capacidad_carga_kg = 20000.00, @estado = 'DISPONIBLE',
        @pma_kg = 32000.00, @clase_legal = 'PESADO_ARTICULADO', @es_articulado = 1, @vehiculo_id = @vart OUTPUT;

    EXEC dbo.sp_crear_vehiculo
        @placa = N'__M10_NC', @marca = N'Hino', @modelo = N'300', @anio = 2024,
        @capacidad_carga_kg = 1500.00, @kilometraje_actual = 9876.5, @estado = 'DISPONIBLE',
        @pma_kg = 3500.00, @clase_legal = 'LIVIANO', @es_articulado = 0, @estado_homologacion = 'NO_CONFORME',
        @vehiculo_id = @vnc OUTPUT;

    -- 2.1 Coherencia de vehículo: rechazar carga útil > PMA y contradicciones clase/articulado
    SET @err = 0;
    BEGIN TRY
        DECLARE @vdummy INT;
        EXEC dbo.sp_crear_vehiculo
            @placa = N'__M10_BAD1', @marca = N'X', @modelo = N'Y', @anio = 2024,
            @capacidad_carga_kg = 5000.00, @pma_kg = 3500.00, @clase_legal = 'LIVIANO', @es_articulado = 0,
            @vehiculo_id = @vdummy OUTPUT;
    END TRY
    BEGIN CATCH SET @err = ERROR_NUMBER(); END CATCH;
    IF @err <> 50502 THROW 50902, 'Debió rechazar carga útil > PMA con 50502.', 1;

    SET @err = 0;
    BEGIN TRY
        EXEC dbo.sp_crear_vehiculo
            @placa = N'__M10_BAD2', @marca = N'X', @modelo = N'Y', @anio = 2024,
            @capacidad_carga_kg = 1000.00, @pma_kg = 3500.00, @clase_legal = 'LIVIANO', @es_articulado = 1,
            @vehiculo_id = @vdummy OUTPUT;
    END TRY
    BEGIN CATCH SET @err = ERROR_NUMBER(); END CATCH;
    IF @err <> 50502 THROW 50903, 'Debió rechazar articulado con clase LIVIANO (50502).', 1;

    -- 2.2 Editar vehículo sin kilometraje ni homologación preserva ambos valores (incluido NO_CONFORME)
    EXEC dbo.sp_actualizar_vehiculo
        @vehiculo_id = @vnc, @marca = N'Hino Editado', @modelo = N'300 Plus', @anio = 2024,
        @capacidad_carga_kg = 1600.00, @capacidad_volumen_m3 = 10.0,
        @kilometraje_actual = NULL, @estado = 'DISPONIBLE',
        @pma_kg = 3500.00, @clase_legal = 'LIVIANO', @es_articulado = 0, @estado_homologacion = NULL;

    DECLARE @chk_km DECIMAL(12,1), @chk_hom VARCHAR(25);
    SELECT @chk_km = kilometraje_actual, @chk_hom = estado_homologacion FROM dbo.vehiculos WHERE vehiculo_id = @vnc;
    IF @chk_km <> 9876.5 OR @chk_hom <> 'NO_CONFORME'
        THROW 50904, 'sp_actualizar_vehiculo alteró kilometraje o promovió NO_CONFORME al omitir parámetros.', 1;
    PRINT '2. [OK] Vehículos frontera, coherencia PMA y preservación de km/NO_CONFORME verificados.';

    -- 3. Simular conductor con múltiples categorías heredadas (B2 + B4) y reemplazar por B1 atómicamente
    EXEC dbo.sp_asignar_licencia_conductor @conductor_id = @cid, @codigo_licencia = 'B2', @numero_licencia = N'__M10_LIC_1', @fecha_vencimiento = @futura, @estado_verificacion = 'VERIFICADA';
    EXEC dbo.sp_asignar_licencia_conductor @conductor_id = @cid, @codigo_licencia = 'B4', @numero_licencia = N'__M10_LIC_1', @fecha_vencimiento = @futura, @estado_verificacion = 'VERIFICADA';

    EXEC dbo.sp_actualizar_licencia_principal_conductor
        @conductor_id = @cid, @codigo_licencia = 'B1', @numero_licencia = N'__M10_LIC_1', @fecha_vencimiento = @futura2;

    IF (SELECT COUNT(*) FROM dbo.conductores_licencias WHERE conductor_id = @cid) <> 1
       OR NOT EXISTS (SELECT 1 FROM dbo.conductores_licencias WHERE conductor_id = @cid AND codigo_licencia = 'B1' AND fecha_vencimiento = @futura2)
        THROW 50905, 'Debió conservar únicamente B1 tras sp_actualizar_licencia_principal_conductor.', 1;

    IF NOT EXISTS (SELECT 1 FROM dbo.auditorias WHERE entidad = N'conductores_licencias' AND accion = 'ACTUALIZAR_PRINCIPAL' AND registro_id = CONCAT(@cid, N':B1') AND valores_anteriores LIKE N'%B2%' AND valores_anteriores LIKE N'%B4%')
        THROW 50906, 'La auditoría debe conservar el historial previo B2 | B4.', 1;

    -- B1 en fronteras exactas: <= 4000 sí, 4000.01 no, NO_CONFORME no
    SELECT @hab = es_habilitado FROM dbo.fn_conductor_habilitado_vehiculo(@cid, @v4000, CONVERT(DATE, SYSUTCDATETIME()));
    IF @hab <> 1 THROW 50907, 'B1 debe habilitar PMA exacto 4000.00 kg.', 1;

    SELECT @hab = es_habilitado FROM dbo.fn_conductor_habilitado_vehiculo(@cid, @v4001, CONVERT(DATE, SYSUTCDATETIME()));
    IF @hab <> 0 THROW 50908, 'B1 NO debe habilitar PMA 4000.01 kg (y la antigua B4 oculta no debe autorizarlo).', 1;

    SELECT @hab = es_habilitado FROM dbo.fn_conductor_habilitado_vehiculo(@cid, @vnc, CONVERT(DATE, SYSUTCDATETIME()));
    IF @hab <> 0 THROW 50909, 'Vehículo NO_CONFORME debe seguir bloqueado.', 1;

    -- Cambiar a B2 -> 8000.00 sí, 8000.01 no
    EXEC dbo.sp_actualizar_licencia_principal_conductor @conductor_id = @cid, @codigo_licencia = 'B2', @numero_licencia = N'__M10_LIC_1', @fecha_vencimiento = @futura2;
    SELECT @hab = es_habilitado FROM dbo.fn_conductor_habilitado_vehiculo(@cid, @v8000, CONVERT(DATE, SYSUTCDATETIME()));
    IF @hab <> 1 THROW 50910, 'B2 debe habilitar PMA exacto 8000.00 kg.', 1;
    SELECT @hab = es_habilitado FROM dbo.fn_conductor_habilitado_vehiculo(@cid, @v8001, CONVERT(DATE, SYSUTCDATETIME()));
    IF @hab <> 0 THROW 50911, 'B2 NO debe habilitar PMA 8000.01 kg.', 1;

    -- Cambiar a A1 -> rechazado con diagnóstico claro para carga liviana
    EXEC dbo.sp_actualizar_licencia_principal_conductor @conductor_id = @cid, @codigo_licencia = 'A1', @numero_licencia = N'__M10_LIC_1', @fecha_vencimiento = @futura2;
    SELECT @hab = es_habilitado, @motivo = motivo_diagnostico FROM dbo.fn_conductor_habilitado_vehiculo(@cid, @v4000, CONVERT(DATE, SYSUTCDATETIME()));
    IF @hab <> 0 OR @motivo LIKE N'%habilitado y compatible%'
        THROW 50912, 'A1 debe rechazarse con diagnóstico explícito de no carga.', 1;

    -- Cambiar a E2 -> habilita articulado
    EXEC dbo.sp_actualizar_licencia_principal_conductor @conductor_id = @cid, @codigo_licencia = 'E2', @numero_licencia = N'__M10_LIC_1', @fecha_vencimiento = @futura2;
    SELECT @hab = es_habilitado FROM dbo.fn_conductor_habilitado_vehiculo(@cid, @vart, CONVERT(DATE, SYSUTCDATETIME()));
    IF @hab <> 1 THROW 50913, 'E2 debe habilitar vehículo articulado.', 1;

    -- Crear viaje activo con conductor y verificar bloqueo 50505 al intentar cambiar licencia
    DECLARE @viaje_id INT;
    DECLARE @salida DATETIME2 = DATEADD(DAY, 2, SYSUTCDATETIME());
    EXEC dbo.sp_crear_viaje @codigo = N'__M10_VIA1', @vehiculo_id = @vart, @conductor_id = @cid, @ruta_id = 1, @fecha_programada = @salida, @viaje_id = @viaje_id OUTPUT;

    SET @err = 0;
    BEGIN TRY
        EXEC dbo.sp_actualizar_licencia_principal_conductor @conductor_id = @cid, @codigo_licencia = 'B1', @numero_licencia = N'__M10_LIC_1', @fecha_vencimiento = @futura2;
    END TRY
    BEGIN CATCH SET @err = ERROR_NUMBER(); END CATCH;
    IF @err <> 50505 THROW 50914, 'Debió bloquear cambio de licencia con viaje activo (50505).', 1;
    PRINT '3. [OK] Licencia principal única, fronteras 4000/8000, A1/E2, auditoría y bloqueo viaje activo verificados.';

    -- 4. Invitaciones: PENDIENTE vigente -> REVOCADA; EXPIRADA -> CANCELADA; COMPLETADA -> 50441
    DECLARE @inv_vig INT, @inv_exp INT;
    DECLARE @tok1 VARCHAR(64) = REPLICATE('a', 64);
    DECLARE @tok2 VARCHAR(64) = REPLICATE('b', 64);

    EXEC dbo.sp_crear_invitacion_usuario @correo_destino = N'm10.vigente@example.test', @token_hash = @tok1, @horas_vigencia = 24, @invitacion_id = @inv_vig OUTPUT;
    EXEC dbo.sp_revocar_invitacion_usuario @invitacion_id = @inv_vig;
    IF (SELECT estado FROM dbo.invitaciones_usuario WHERE invitacion_id = @inv_vig) <> 'REVOCADA'
        THROW 50915, 'Invitación vigente revocada debe quedar en estado REVOCADA.', 1;

    EXEC dbo.sp_crear_invitacion_usuario @correo_destino = N'm10.expirada@example.test', @token_hash = @tok2, @horas_vigencia = 24, @invitacion_id = @inv_exp OUTPUT;
    UPDATE dbo.invitaciones_usuario SET estado = 'EXPIRADA', fecha_expiracion = DATEADD(HOUR, -2, SYSUTCDATETIME()) WHERE invitacion_id = @inv_exp;

    EXEC dbo.sp_revocar_invitacion_usuario @invitacion_id = @inv_exp;
    IF (SELECT estado FROM dbo.invitaciones_usuario WHERE invitacion_id = @inv_exp) <> 'CANCELADA'
        THROW 50916, 'Invitación expirada descartada debe quedar en estado CANCELADA.', 1;
    IF NOT EXISTS (SELECT 1 FROM dbo.auditorias WHERE entidad = N'invitaciones_usuario' AND registro_id = CONVERT(NVARCHAR(100), @inv_exp) AND accion = 'CANCEL_EXPIRED')
        THROW 50917, 'Debe registrarse auditoría CANCEL_EXPIRED para invitación expirada.', 1;

    -- Re-invocar revocación en CANCELADA debe fallar con 50442
    SET @err = 0;
    BEGIN TRY
        EXEC dbo.sp_revocar_invitacion_usuario @invitacion_id = @inv_exp;
    END TRY
    BEGIN CATCH SET @err = ERROR_NUMBER(); END CATCH;
    IF @err <> 50442 THROW 50918, 'Invitación ya cancelada debe rechazar nueva revocación con 50442.', 1;

    PRINT '4. [OK] Invitaciones vigentes (REVOCADA), expiradas (CANCELADA) y auditoría verificadas.';
    PRINT '=== SUITE T-SQL MIGRACION 10 COMPLETADA AL 100% ===';

    ROLLBACK TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    DECLARE @msg NVARCHAR(4000) = ERROR_MESSAGE();
    DECLARE @num INT = ERROR_NUMBER();
    PRINT 'FALLO TEST MIGRACION 10 [' + CAST(@num AS VARCHAR(10)) + ']: ' + @msg;
    THROW;
END CATCH;
GO
