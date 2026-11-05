/*
  Pruebas de contrato SQL para sql-master-data-contracts en TransportesSegurosSA_Test.
  Ejecuta toda la batería dentro de una transacción con ROLLBACK final,
  sin modificar ningún dato preexistente.
*/
USE TransportesSegurosSA_Test;
GO

IF DB_NAME() <> N'TransportesSegurosSA_Test'
    THROW 50965, 'Esta prueba solo puede ejecutarse en TransportesSegurosSA_Test.', 1;
GO

BEGIN TRANSACTION;

BEGIN TRY
    DECLARE @admin_id INT = (SELECT usuario_id FROM dbo.usuarios WHERE nombre_usuario = N'admin_proyecto');
    DECLARE @sin_permiso_id INT;

    INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash, activo)
    VALUES (N'__test_md_noperm', N'__test_md_noperm@example.test', N'$2y$10$12345678901234567890123456789012345678901234567890123', 1);
    SET @sin_permiso_id = CONVERT(INT, SCOPE_IDENTITY());

    -- 1. Sin permiso -> 50501
    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @sin_permiso_id;
    DECLARE @blocked BIT = 0, @dummy_id INT;
    BEGIN TRY
        EXEC dbo.sp_crear_ubicacion @nombre = N'__UBI_NOPERM', @direccion = N'Dir', @ubicacion_id = @dummy_id OUTPUT;
    END TRY
    BEGIN CATCH
        IF ERROR_NUMBER() = 50501 SET @blocked = 1;
    END CATCH;
    IF @blocked = 0 THROW 50966, 'sp_crear_ubicacion debio rechazar usuario sin UBICACIONES_GESTIONAR.', 1;

    -- Activar contexto autorizado
    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @admin_id;

    -- 2. Ubicaciones: alta, validación de coordenadas, edición
    DECLARE @ubi_a INT, @ubi_b INT, @err INT = 0;
    EXEC dbo.sp_crear_ubicacion
        @nombre = N'__TEST_Bodega Norte',
        @direccion = N'Alajuela, Coyol 200m norte',
        @latitud = 10.016250,
        @longitud = -84.211630,
        @ubicacion_id = @ubi_a OUTPUT;

    EXEC dbo.sp_crear_ubicacion
        @nombre = N'__TEST_Terminal Sur',
        @direccion = N'San José, Paso Ancho',
        @latitud = NULL,
        @longitud = NULL,
        @ubicacion_id = @ubi_b OUTPUT;

    SET @err = 0;
    BEGIN TRY
        EXEC dbo.sp_crear_ubicacion @nombre = N'__TEST_CoordIncompleta', @direccion = N'Dir', @latitud = 9.9, @longitud = NULL, @ubicacion_id = @dummy_id OUTPUT;
    END TRY
    BEGIN CATCH
        SET @err = ERROR_NUMBER();
    END CATCH;
    IF @err <> 50502 THROW 50967, 'Debio rechazar coordenada incompleta con 50502.', 1;

    EXEC dbo.sp_actualizar_ubicacion
        @ubicacion_id = @ubi_b,
        @nombre = N'__TEST_Terminal Sur Editada',
        @direccion = N'San José, Hatillo',
        @latitud = 9.915000,
        @longitud = -84.095000;

    -- 3. Catálogos: alta, edición y desactivación (con bloqueo de mercancía en uso)
    DECLARE @tm_id INT, @ti_id INT, @td_id INT;
    EXEC dbo.sp_crear_tipo_mercancia @nombre = N'__TEST_Refrigerada', @descripcion = N'Cadena de frío', @tipo_mercancia_id = @tm_id OUTPUT;
    EXEC dbo.sp_actualizar_tipo_mercancia @tipo_mercancia_id = @tm_id, @nombre = N'__TEST_Refrigerada Plus', @descripcion = N'0 a 4 C';

    EXEC dbo.sp_crear_tipo_incidencia @nombre = N'__TEST_Retraso Aduanal', @descripcion = N'Inspección', @tipo_incidencia_id = @ti_id OUTPUT;
    EXEC dbo.sp_actualizar_tipo_incidencia @tipo_incidencia_id = @ti_id, @nombre = N'__TEST_Retraso Fiscal', @descripcion = N'Aduana';
    EXEC dbo.sp_desactivar_tipo_incidencia @tipo_incidencia_id = @ti_id;

    EXEC dbo.sp_crear_tipo_documento @nombre = N'__TEST_Manifiesto Carga', @descripcion = N'Respaldo', @tipo_documento_id = @td_id OUTPUT;
    EXEC dbo.sp_actualizar_tipo_documento @tipo_documento_id = @td_id, @nombre = N'__TEST_Manifiesto Oficial', @descripcion = N'Firmado';
    EXEC dbo.sp_desactivar_tipo_documento @tipo_documento_id = @td_id;

    -- 4. Vehículo, Conductor y Ruta + bloqueo por solicitud y viaje activo
    DECLARE @veh_id INT, @cond_id INT, @ruta_id INT, @viaje_id INT;

    -- Rechazar estado inicial ASIGNADO manual
    SET @err = 0;
    BEGIN TRY
        EXEC dbo.sp_crear_vehiculo
            @placa = N'__TST999', @marca = N'Volvo', @modelo = N'FH', @anio = 2024,
            @capacidad_carga_kg = 15000, @capacidad_volumen_m3 = 60, @kilometraje_actual = 100,
            @estado = 'ASIGNADO', @vehiculo_id = @dummy_id OUTPUT;
    END TRY
    BEGIN CATCH
        SET @err = ERROR_NUMBER();
    END CATCH;
    IF @err <> 50502 THROW 50968, 'Debio rechazar creacion manual de vehiculo ASIGNADO con 50502.', 1;

    EXEC dbo.sp_crear_vehiculo
        @placa = N'__TST101', @marca = N'Isuzu', @modelo = N'NPR', @anio = 2025,
        @capacidad_carga_kg = 5000.00, @capacidad_volumen_m3 = 28.500, @kilometraje_actual = 1200.0,
        @estado = 'DISPONIBLE', @vehiculo_id = @veh_id OUTPUT;

    -- Rechazar licencia vencida
    SET @err = 0;
    BEGIN TRY
        EXEC dbo.sp_crear_conductor
            @identificacion = N'__COND_VENC', @nombre = N'Luis', @primer_apellido = N'Mora',
            @numero_licencia = N'__LIC_VENC', @fecha_vencimiento_licencia = '2020-01-01',
            @conductor_id = @dummy_id OUTPUT;
    END TRY
    BEGIN CATCH
        SET @err = ERROR_NUMBER();
    END CATCH;
    IF @err <> 50502 THROW 50969, 'Debio rechazar conductor con licencia vencida con 50502.', 1;

    DECLARE @lic_futura DATE = DATEADD(YEAR, 2, CONVERT(DATE, SYSUTCDATETIME()));
    EXEC dbo.sp_crear_conductor
        @identificacion = N'__COND_101', @nombre = N'Carlos', @primer_apellido = N'Vargas',
        @segundo_apellido = N'Solís', @telefono = N'8888-7777', @correo = N'carlos.test@example.test',
        @numero_licencia = N'__LIC_101', @fecha_vencimiento_licencia = @lic_futura,
        @estado_operativo = 'DISPONIBLE', @usuario_id = NULL, @conductor_id = @cond_id OUTPUT;

    -- Rechazar ruta con mismo origen y destino
    SET @err = 0;
    BEGIN TRY
        EXEC dbo.sp_crear_ruta
            @nombre = N'__Ruta Invalida', @origen_id = @ubi_a, @destino_id = @ubi_a,
            @distancia_km = 10, @duracion_estimada_min = 20, @ruta_id = @dummy_id OUTPUT;
    END TRY
    BEGIN CATCH
        SET @err = ERROR_NUMBER();
    END CATCH;
    IF @err <> 50502 THROW 50970, 'Debio rechazar ruta con origen=destino con 50502.', 1;

    EXEC dbo.sp_crear_ruta
        @nombre = N'__TEST_Ruta Coyol - Hatillo', @origen_id = @ubi_a, @destino_id = @ubi_b,
        @distancia_km = 24.50, @duracion_estimada_min = 45, @ruta_id = @ruta_id OUTPUT;

    -- Bloquear desactivación de ubicación usada por ruta activa -> 50505
    SET @err = 0;
    BEGIN TRY
        EXEC dbo.sp_desactivar_ubicacion @ubicacion_id = @ubi_a;
    END TRY
    BEGIN CATCH
        SET @err = ERROR_NUMBER();
    END CATCH;
    IF @err <> 50505 THROW 50971, 'Debio rechazar desactivar ubicacion con ruta activa (50505).', 1;

    -- Crear cliente y solicitud efímera usando las nuevas ubicaciones y el nuevo tipo de mercancía
    DECLARE @cli_id INT, @sol_id INT, @p1_id INT, @p2_id INT, @vs_id INT;
    DECLARE @merc dbo.tipo_mercancias;
    EXEC dbo.sp_crear_cliente_fisico
        @identificacion = N'__CLI_MD_101',
        @nombre = N'Elena', @primer_apellido = N'Rojas', @segundo_apellido = N'Mora',
        @correo = N'climd@example.test', @telefono = N'8888-0000',
        @cliente_id = @cli_id OUTPUT;

    INSERT INTO @merc (tipo_mercancia_id, descripcion, peso_kg, volumen_m3)
    VALUES (@tm_id, N'Lote refrigerado prueba', 120.00, 2.500);

    DECLARE @entrega_req DATETIME2 = DATEADD(DAY, 2, SYSUTCDATETIME());
    EXEC dbo.sp_crear_solicitud
        @codigo = N'__SOL_MD_101', @cliente_id = @cli_id,
        @origen_id = @ubi_a, @destino_id = @ubi_b,
        @destinatario_nombre = N'Pedro', @destinatario_apellido1 = N'Gómez', @destinatario_apellido2 = N'Castro',
        @destinatario_identificacion = NULL, @destinatario_telefono = NULL,
        @fecha_entrega_requerida = @entrega_req, @mercancias = @merc,
        @solicitud_id = @sol_id OUTPUT;

    -- Bloquear desactivación de tipo_mercancia mientras solicitud está activa -> 50505
    SET @err = 0;
    BEGIN TRY EXEC dbo.sp_desactivar_tipo_mercancia @tipo_mercancia_id = @tm_id; END TRY
    BEGIN CATCH SET @err = ERROR_NUMBER(); END CATCH;
    IF @err <> 50505 THROW 50977, 'Debio rechazar desactivar tipo_mercancia usado en solicitud activa (50505).', 1;

    -- Crear viaje planificado usando los tres recursos nuevos, asignar solicitud y probar bloqueos
    DECLARE @salida DATETIME2 = DATEADD(DAY, 1, SYSUTCDATETIME());
    EXEC dbo.sp_crear_viaje
        @codigo = N'__VJ_MD_101', @vehiculo_id = @veh_id, @conductor_id = @cond_id,
        @ruta_id = @ruta_id, @fecha_programada = @salida, @viaje_id = @viaje_id OUTPUT;

    EXEC dbo.sp_agregar_parada @viaje_id = @viaje_id, @ubicacion_id = @ubi_a, @orden = 1, @tipo_parada = 'RECOGIDA', @parada_id = @p1_id OUTPUT;
    EXEC dbo.sp_agregar_parada @viaje_id = @viaje_id, @ubicacion_id = @ubi_b, @orden = 2, @tipo_parada = 'ENTREGA', @parada_id = @p2_id OUTPUT;
    EXEC dbo.sp_asignar_solicitud_viaje @viaje_id = @viaje_id, @solicitud_id = @sol_id, @parada_recogida_id = @p1_id, @parada_entrega_id = @p2_id, @viaje_solicitud_id = @vs_id OUTPUT;

    -- Intentar desactivar vehículo, conductor y ruta con viaje PLANIFICADO -> todos 50505
    SET @err = 0;
    BEGIN TRY EXEC dbo.sp_desactivar_vehiculo @vehiculo_id = @veh_id; END TRY
    BEGIN CATCH SET @err = ERROR_NUMBER(); END CATCH;
    IF @err <> 50505 THROW 50972, 'Debio rechazar desactivar vehiculo asignado a viaje (50505).', 1;

    SET @err = 0;
    BEGIN TRY EXEC dbo.sp_desactivar_conductor @conductor_id = @cond_id; END TRY
    BEGIN CATCH SET @err = ERROR_NUMBER(); END CATCH;
    IF @err <> 50505 THROW 50973, 'Debio rechazar desactivar conductor asignado a viaje (50505).', 1;

    SET @err = 0;
    BEGIN TRY EXEC dbo.sp_desactivar_ruta @ruta_id = @ruta_id; END TRY
    BEGIN CATCH SET @err = ERROR_NUMBER(); END CATCH;
    IF @err <> 50505 THROW 50974, 'Debio rechazar desactivar ruta con viaje activo (50505).', 1;

    -- Iniciar viaje, registrar entrega y finalizar viaje para liberar vehículo, conductor y solicitud
    EXEC dbo.sp_iniciar_viaje @viaje_id = @viaje_id;
    EXEC dbo.sp_registrar_entrega
        @viaje_solicitud_id = @vs_id, @estado = 'ENTREGADA',
        @receptor_nombre = N'Pedro Gómez', @comprobante_url = N'https://example.test/pod/101';
    EXEC dbo.sp_finalizar_viaje @viaje_id = @viaje_id;

    -- Ahora sí permite editar/desactivar vehículo, conductor, tipo_mercancia, ruta y ubicaciones
    EXEC dbo.sp_desactivar_tipo_mercancia @tipo_mercancia_id = @tm_id;

    EXEC dbo.sp_actualizar_vehiculo
        @vehiculo_id = @veh_id, @marca = N'Isuzu', @modelo = N'NPR HD', @anio = 2025,
        @capacidad_carga_kg = 5500.00, @capacidad_volumen_m3 = 30.000, @kilometraje_actual = 1250.0,
        @estado = 'MANTENIMIENTO';
    EXEC dbo.sp_desactivar_vehiculo @vehiculo_id = @veh_id;

    EXEC dbo.sp_actualizar_conductor
        @conductor_id = @cond_id, @nombre = N'Carlos Alberto', @primer_apellido = N'Vargas',
        @segundo_apellido = N'Solís', @telefono = N'8888-9999', @correo = N'carlos.test@example.test',
        @numero_licencia = N'__LIC_101', @fecha_vencimiento_licencia = @lic_futura,
        @estado_operativo = 'DISPONIBLE', @usuario_id = NULL;
    EXEC dbo.sp_desactivar_conductor @conductor_id = @cond_id;

    -- Ruta con viaje histórico no permite cambiar origen/destino -> 50505, pero sí permite desactivar
    SET @err = 0;
    BEGIN TRY
        EXEC dbo.sp_actualizar_ruta
            @ruta_id = @ruta_id, @nombre = N'__TEST_Ruta Cambiada',
            @origen_id = @ubi_b, @destino_id = @ubi_a,
            @distancia_km = 25.00, @duracion_estimada_min = 50;
    END TRY
    BEGIN CATCH SET @err = ERROR_NUMBER(); END CATCH;
    IF @err <> 50505 THROW 50975, 'Debio proteger origen/destino de ruta con viaje historico (50505).', 1;

    EXEC dbo.sp_desactivar_ruta @ruta_id = @ruta_id;
    EXEC dbo.sp_desactivar_ubicacion @ubicacion_id = @ubi_a;
    EXEC dbo.sp_desactivar_ubicacion @ubicacion_id = @ubi_b;

    -- Verificar que se registraron filas reales en dbo.auditorias
    IF (SELECT COUNT(*) FROM dbo.auditorias WHERE entidad IN (N'ubicaciones', N'tipos_mercancia', N'vehiculos', N'conductores', N'rutas') AND usuario_id = @admin_id) < 10
        THROW 50976, 'Faltan registros esperados en dbo.auditorias.', 1;

    ROLLBACK TRANSACTION;
    SELECT N'OK: pruebas SQL de sql-master-data-contracts superadas con ROLLBACK limpio.' AS resultado;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO
