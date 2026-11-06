/* =========================================================================
   Transportes Seguros S.A. — Suite T-SQL de Pruebas Automatizadas
   Contratos de Licencias de Conducir y PMA de Vehículos
   Ambiente exclusivo: TransportesSegurosSA_Test
   ========================================================================= */

SET NOCOUNT ON;
BEGIN TRANSACTION;

DECLARE @test_actor INT = 1;
EXEC sys.sp_set_session_context @key=N'usuario_id', @value=@test_actor;

PRINT '=== INICIO TEST T-SQL: LICENCIAS DE CONDUCIR Y PMA DE VEHICULOS ===';

-- 1. Verificar catálogo cat_licencias
DECLARE @total_cat INT;
SELECT @total_cat = COUNT(*) FROM dbo.cat_licencias WHERE activo = 1;
IF @total_cat < 14
BEGIN
    PRINT 'FALLO: El catálogo cat_licencias debe tener al menos 14 categorías.';
    ROLLBACK TRANSACTION;
    RETURN;
END;
PRINT '1. [OK] Catálogo cat_licencias verificado (14 categorías activas).';

-- 2. Crear datos de prueba aislados efímeros
DECLARE @suffix NVARCHAR(10) = SUBSTRING(CONVERT(NVARCHAR(36), NEWID()), 1, 6);
DECLARE @cond_id INT, @veh_b1 INT, @veh_b2 INT, @veh_b3 INT, @veh_b4 INT;

-- 2.1 Conductor efímero
EXEC dbo.sp_crear_conductor
    @identificacion = N'TEST-COND-LIC',
    @nombre = N'Conductor',
    @primer_apellido = N'PruebaLicencias',
    @segundo_apellido = NULL,
    @telefono = N'88889999',
    @correo = N'cond.lic@test.cr',
    @numero_licencia = N'LIC-TEST-001',
    @fecha_vencimiento_licencia = '2029-12-31',
    @estado_operativo = 'DISPONIBLE',
    @usuario_id = NULL,
    @conductor_id = @cond_id OUTPUT;

-- 2.2 Vehículos efímeros: B1 (3.500 kg), B2 (6.000 kg), B3 (12.000 kg), B4 (20.000 kg articulado)
EXEC dbo.sp_crear_vehiculo
    @placa = N'TS-B1-01', @marca = N'Toyota', @modelo = N'Hilux', @anio = 2022,
    @capacidad_carga_kg = 1000.00, @capacidad_volumen_m3 = 4.0, @kilometraje_actual = 10000,
    @estado = 'DISPONIBLE', @pma_kg = 3500.00, @clase_legal = 'LIVIANO', @es_articulado = 0,
    @estado_homologacion = 'VERIFICADO', @vehiculo_id = @veh_b1 OUTPUT;

EXEC dbo.sp_crear_vehiculo
    @placa = N'TS-B2-01', @marca = N'Isuzu', @modelo = N'NPR', @anio = 2021,
    @capacidad_carga_kg = 3500.00, @capacidad_volumen_m3 = 12.0, @kilometraje_actual = 20000,
    @estado = 'DISPONIBLE', @pma_kg = 6500.00, @clase_legal = 'MEDIANO_RIGIDO', @es_articulado = 0,
    @estado_homologacion = 'VERIFICADO', @vehiculo_id = @veh_b2 OUTPUT;

EXEC dbo.sp_crear_vehiculo
    @placa = N'TS-B3-01', @marca = N'Hino', @modelo = N'500', @anio = 2020,
    @capacidad_carga_kg = 8000.00, @capacidad_volumen_m3 = 25.0, @kilometraje_actual = 50000,
    @estado = 'DISPONIBLE', @pma_kg = 14000.00, @clase_legal = 'PESADO_RIGIDO', @es_articulado = 0,
    @estado_homologacion = 'VERIFICADO', @vehiculo_id = @veh_b3 OUTPUT;

EXEC dbo.sp_crear_vehiculo
    @placa = N'TS-B4-01', @marca = N'Freightliner', @modelo = N'Cascadia', @anio = 2023,
    @capacidad_carga_kg = 24000.00, @capacidad_volumen_m3 = 60.0, @kilometraje_actual = 80000,
    @estado = 'DISPONIBLE', @pma_kg = 38000.00, @clase_legal = 'PESADO_ARTICULADO', @es_articulado = 1,
    @estado_homologacion = 'VERIFICADO', @vehiculo_id = @veh_b4 OUTPUT;

PRINT '2. [OK] Conductor y 4 unidades vehiculares (B1, B2, B3, B4) creados para pruebas.';

-- 3. Probar sin licencias verificadas registradas
DECLARE @hab BIT, @motivo NVARCHAR(250);
SELECT @hab = es_habilitado, @motivo = motivo_diagnostico
FROM dbo.fn_conductor_habilitado_vehiculo(@cond_id, @veh_b1, '2026-12-01');

IF @hab <> 0
BEGIN
    PRINT 'FALLO: El conductor sin licencias verificadas no debe estar habilitado.';
    ROLLBACK TRANSACTION;
    RETURN;
END;
PRINT '3. [OK] Conductor sin licencias verificadas es rechazado correctamente.';

-- 4. Asignar licencia B1 verificada
EXEC dbo.sp_asignar_licencia_conductor
    @conductor_id = @cond_id,
    @codigo_licencia = 'B1',
    @numero_licencia = N'LIC-B1-VALIDA',
    @fecha_vencimiento = '2028-05-20',
    @estado_verificacion = 'VERIFICADA';

-- 4.1 Probar con Vehículo B1 (PMA 3.500 kg) -> Habilitado
SELECT @hab = es_habilitado FROM dbo.fn_conductor_habilitado_vehiculo(@cond_id, @veh_b1, '2026-12-01');
IF @hab <> 1
BEGIN
    PRINT 'FALLO: Conductor con B1 debe estar habilitado para vehículo B1 (PMA 3.500 kg).';
    ROLLBACK TRANSACTION;
    RETURN;
END;
PRINT '4.1 [OK] Vehículo B1 + Licencia B1 vigente: HABILITADO.';

-- 4.2 Probar con Vehículo B2 (PMA 6.500 kg) -> Rechazado por B1 insuficiente
SELECT @hab = es_habilitado, @motivo = motivo_diagnostico FROM dbo.fn_conductor_habilitado_vehiculo(@cond_id, @veh_b2, '2026-12-01');
IF @hab <> 0 OR @motivo NOT LIKE '%B-2%'
BEGIN
    PRINT 'FALLO: Conductor con B1 debe ser rechazado para vehículo B2 (PMA 6.500 kg).';
    ROLLBACK TRANSACTION;
    RETURN;
END;
PRINT '4.2 [OK] Vehículo B2 (6.500 kg PMA) + Solo B1: RECHAZADO (requiere B-2).';

-- 5. Agregar licencia B2 verificada (multilicencia)
EXEC dbo.sp_asignar_licencia_conductor
    @conductor_id = @cond_id,
    @codigo_licencia = 'B2',
    @numero_licencia = N'LIC-B2-VALIDA',
    @fecha_vencimiento = '2028-05-20',
    @estado_verificacion = 'VERIFICADA';

SELECT @hab = es_habilitado FROM dbo.fn_conductor_habilitado_vehiculo(@cond_id, @veh_b2, '2026-12-01');
IF @hab <> 1
BEGIN
    PRINT 'FALLO: Conductor con B2 debe estar habilitado para vehículo B2.';
    ROLLBACK TRANSACTION;
    RETURN;
END;
PRINT '5.1 [OK] Vehículo B2 + Licencia B2 vigente: HABILITADO.';

-- 5.2 Probar con Vehículo B3 (PMA 14.000 kg) -> Rechazado por B2 insuficiente
SELECT @hab = es_habilitado, @motivo = motivo_diagnostico FROM dbo.fn_conductor_habilitado_vehiculo(@cond_id, @veh_b3, '2026-12-01');
IF @hab <> 0 OR @motivo NOT LIKE '%B-3%'
BEGIN
    PRINT 'FALLO: Conductor con B2 debe ser rechazado para vehículo B3 (14.000 kg PMA).';
    ROLLBACK TRANSACTION;
    RETURN;
END;
PRINT '5.2 [OK] Camión pesado B3 (14.000 kg PMA) + B2: RECHAZADO (requiere B-3).';

-- 6. Agregar licencia B3 verificada
EXEC dbo.sp_asignar_licencia_conductor
    @conductor_id = @cond_id,
    @codigo_licencia = 'B3',
    @numero_licencia = N'LIC-B3-VALIDA',
    @fecha_vencimiento = '2028-05-20',
    @estado_verificacion = 'VERIFICADA';

SELECT @hab = es_habilitado FROM dbo.fn_conductor_habilitado_vehiculo(@cond_id, @veh_b3, '2026-12-01');
IF @hab <> 1
BEGIN
    PRINT 'FALLO: Conductor con B3 debe estar habilitado para camión rígido B3.';
    ROLLBACK TRANSACTION;
    RETURN;
END;
PRINT '6.1 [OK] Camión pesado rígido B3 + Licencia B3: HABILITADO.';

-- 6.2 Probar con Unidad Articulada B4 -> Rechazado con B3 porque no permite articulados
SELECT @hab = es_habilitado, @motivo = motivo_diagnostico FROM dbo.fn_conductor_habilitado_vehiculo(@cond_id, @veh_b4, '2026-12-01');
IF @hab <> 0 OR @motivo NOT LIKE '%articulada%'
BEGIN
    PRINT 'FALLO: Unidad pesada articulada debe rechazar conductor con solo B3.';
    ROLLBACK TRANSACTION;
    RETURN;
END;
PRINT '6.2 [OK] Unidad pesada articulada + Licencia B3: RECHAZADO (no autoriza articulados).';

-- 7. Agregar licencia B4 verificada
EXEC dbo.sp_asignar_licencia_conductor
    @conductor_id = @cond_id,
    @codigo_licencia = 'B4',
    @numero_licencia = N'LIC-B4-VALIDA',
    @fecha_vencimiento = '2028-05-20',
    @estado_verificacion = 'VERIFICADA';

SELECT @hab = es_habilitado FROM dbo.fn_conductor_habilitado_vehiculo(@cond_id, @veh_b4, '2026-12-01');
IF @hab <> 1
BEGIN
    PRINT 'FALLO: Conductor con B4 debe estar habilitado para unidad pesada articulada.';
    ROLLBACK TRANSACTION;
    RETURN;
END;
PRINT '7.1 [OK] Unidad pesada articulada + Licencia B4: HABILITADO.';

-- 8. Probar vencimiento de licencia ante fecha de salida
SELECT @hab = es_habilitado, @motivo = motivo_diagnostico FROM dbo.fn_conductor_habilitado_vehiculo(@cond_id, @veh_b4, '2029-01-01');
IF @hab <> 0 OR @motivo NOT LIKE '%vence antes%'
BEGIN
    PRINT 'FALLO: La fecha de salida posterior al vencimiento debe ser rechazada.';
    ROLLBACK TRANSACTION;
    RETURN;
END;
PRINT '8. [OK] Licencia que vence antes de la fecha programada de salida: RECHAZADA.';

-- 9. Probar sp_crear_viaje transaccional con regla de licencias
DECLARE @viaje_id_test INT;
DECLARE @fecha_viaje DATETIME2 = DATEADD(DAY, 2, SYSUTCDATETIME());

-- Intento 9.1: Asignar conductor con licencia vencida (simulada) -> Debe fallar con 50320
BEGIN TRY
    -- Vencer temporalmente la licencia
    UPDATE dbo.conductores_licencias SET fecha_vencimiento = '2020-01-01' WHERE conductor_id = @cond_id;
    EXEC dbo.sp_crear_viaje
        @codigo = NULL, @vehiculo_id = @veh_b1, @conductor_id = @cond_id,
        @ruta_id = 1, @fecha_programada = @fecha_viaje, @viaje_id = @viaje_id_test OUTPUT;

    PRINT 'FALLO: sp_crear_viaje debió rechazar licencia vencida.';
    ROLLBACK TRANSACTION;
    RETURN;
END TRY
BEGIN CATCH
    IF ERROR_NUMBER() = 50320
        PRINT '9.1 [OK] sp_crear_viaje rechaza conductor con licencia vencida (Error 50320).';
    ELSE
    BEGIN
        PRINT 'FALLO: Error inesperado en sp_crear_viaje: ' + CAST(ERROR_NUMBER() AS VARCHAR(10)) + ' ' + ERROR_MESSAGE();
        ROLLBACK TRANSACTION;
        RETURN;
    END;
END CATCH;

-- Intento 9.2: Restaurar vigencia y crear viaje exitosamente
UPDATE dbo.conductores_licencias SET fecha_vencimiento = '2028-12-31' WHERE conductor_id = @cond_id;
EXEC dbo.sp_crear_viaje
    @codigo = NULL, @vehiculo_id = @veh_b4, @conductor_id = @cond_id,
    @ruta_id = 1, @fecha_programada = @fecha_viaje, @viaje_id = @viaje_id_test OUTPUT;

IF @viaje_id_test IS NULL OR @viaje_id_test < 1
BEGIN
    PRINT 'FALLO: sp_crear_viaje debió crear el viaje con conductor calificado.';
    ROLLBACK TRANSACTION;
    RETURN;
END;
PRINT '9.2 [OK] sp_crear_viaje completó exitosamente asignando articulado con conductor B4.';

PRINT '============================================================';
PRINT 'RESULTADO: Todos los contratos de licencias y PMA pasaron 100%.';
PRINT '============================================================';

ROLLBACK TRANSACTION;
GO
