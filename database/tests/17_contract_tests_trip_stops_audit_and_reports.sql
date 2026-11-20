/* =========================================================================
   Suite de contratos T-SQL 17: Paradas editables, bitácora y reportes (Mig 13)
   =========================================================================
   Ejecutar EXCLUSIVAMENTE en TransportesSegurosSA_Test.
   ========================================================================= */

SET NOCOUNT ON;
SET XACT_ABORT OFF;

IF DB_NAME() <> N'TransportesSegurosSA_Test'
    THROW 50999, N'BLOQUEADO: Esta suite solo puede ejecutarse en TransportesSegurosSA_Test.', 1;

DECLARE @admin_id INT = (SELECT TOP (1) usuario_id FROM dbo.usuarios WHERE activo = 1 ORDER BY usuario_id);
DECLARE @cliente_id INT = (SELECT TOP (1) cliente_id FROM dbo.clientes WHERE activo = 1 ORDER BY cliente_id);
DECLARE @tipo_merc_id INT = (SELECT TOP (1) tipo_mercancia_id FROM dbo.tipos_mercancia WHERE activo = 1 ORDER BY tipo_mercancia_id);
DECLARE @tag NVARCHAR(25) = N'TSQL17_' + RIGHT(CONVERT(NVARCHAR(36), NEWID()), 8);
DECLARE @futuro DATETIME2 = DATEADD(DAY, 4, SYSUTCDATETIME());

DECLARE @u1 INT, @u2 INT, @u3 INT, @u4 INT, @ruta_id INT, @veh_id INT, @cond_id INT, @viaje_id INT;
DECLARE @sol1 INT, @sol2 INT;
DECLARE @p1 INT, @p2 INT, @p3 INT, @p4 INT;
DECLARE @inactivo_uid INT = NULL, @sin_perm_uid INT = NULL;
DECLARE @max_aud_id BIGINT = ISNULL((SELECT MAX(auditoria_id) FROM dbo.auditorias), 0);

BEGIN TRY
    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @admin_id;

    -- Sembrar 4 ubicaciones, ruta, vehículo, conductor, viaje PLANIFICADO y 4 paradas
    DECLARE @nu1 NVARCHAR(150) = @tag + N'_U1';
    DECLARE @nu2 NVARCHAR(150) = @tag + N'_U2';
    DECLARE @nu3 NVARCHAR(150) = @tag + N'_U3';
    DECLARE @nu4 NVARCHAR(150) = @tag + N'_U4';
    EXEC dbo.sp_crear_ubicacion @nombre=@nu1, @direccion=N'Dir 1', @latitud=9.910000, @longitud=-84.010000, @ubicacion_id=@u1 OUTPUT;
    EXEC dbo.sp_crear_ubicacion @nombre=@nu2, @direccion=N'Dir 2', @latitud=9.950000, @longitud=-84.050000, @ubicacion_id=@u2 OUTPUT;
    EXEC dbo.sp_crear_ubicacion @nombre=@nu3, @direccion=N'Dir 3', @latitud=9.990000, @longitud=-84.100000, @ubicacion_id=@u3 OUTPUT;
    EXEC dbo.sp_crear_ubicacion @nombre=@nu4, @direccion=N'Dir 4', @latitud=10.030000, @longitud=-84.150000, @ubicacion_id=@u4 OUTPUT;

    DECLARE @nruta NVARCHAR(150) = @tag + N'_RUTA';
    EXEC dbo.sp_crear_ruta @nombre=@nruta, @origen_id=@u1, @destino_id=@u4, @distancia_km=40, @duracion_estimada_min=60, @ruta_id=@ruta_id OUTPUT;

    DECLARE @placa NVARCHAR(20) = LEFT(REPLACE(@tag, N'_', N''), 14);
    EXEC dbo.sp_crear_vehiculo @placa=@placa, @marca=N'Isuzu', @modelo=N'NPR', @anio=2025, @capacidad_carga_kg=3000, @capacidad_volumen_m3=15, @kilometraje_actual=0, @estado='DISPONIBLE', @pma_kg=4000, @clase_legal='LIVIANO', @es_articulado=0, @estado_homologacion='VERIFICADO', @vehiculo_id=@veh_id OUTPUT;

    DECLARE @cid_doc NVARCHAR(30) = @tag + N'_C';
    DECLARE @lic_doc NVARCHAR(50) = @tag + N'_L';
    DECLARE @vence DATE = DATEADD(YEAR, 1, CONVERT(DATE, SYSUTCDATETIME()));
    EXEC dbo.sp_crear_conductor @identificacion=@cid_doc, @nombre=N'Luis', @primer_apellido=N'Arias', @segundo_apellido=NULL, @telefono=NULL, @correo=NULL, @numero_licencia=@lic_doc, @fecha_vencimiento_licencia=@vence, @estado_operativo='DISPONIBLE', @usuario_id=NULL, @codigo_licencia_inicial='B1', @conductor_id=@cond_id OUTPUT;

    DECLARE @cod_viaje NVARCHAR(30) = LEFT(@tag, 18) + N'_V1';
    EXEC dbo.sp_crear_viaje @codigo=@cod_viaje, @vehiculo_id=@veh_id, @conductor_id=@cond_id, @ruta_id=@ruta_id, @fecha_programada=@futuro, @viaje_id=@viaje_id OUTPUT;

    -- Agregar 4 paradas: #1 u1 (RECOGIDA), #2 u2 (MIXTA), #3 u4 (ENTREGA), #4 u3 (INTERMEDIA)
    EXEC dbo.sp_agregar_parada @viaje_id=@viaje_id, @ubicacion_id=@u1, @orden=1, @tipo_parada='RECOGIDA', @parada_id=@p1 OUTPUT;
    EXEC dbo.sp_agregar_parada @viaje_id=@viaje_id, @ubicacion_id=@u2, @orden=2, @tipo_parada='MIXTA', @parada_id=@p2 OUTPUT;
    EXEC dbo.sp_agregar_parada @viaje_id=@viaje_id, @ubicacion_id=@u4, @orden=3, @tipo_parada='ENTREGA', @parada_id=@p3 OUTPUT;
    EXEC dbo.sp_agregar_parada @viaje_id=@viaje_id, @ubicacion_id=@u3, @orden=4, @tipo_parada='INTERMEDIA', @parada_id=@p4 OUTPUT;

    -- Crear 2 solicitudes y asignarlas: Sol1 (p1->p3) y Sol2 (p1->p2)
    DECLARE @merc dbo.tipo_mercancias;
    INSERT INTO @merc (tipo_mercancia_id, descripcion, peso_kg, volumen_m3) VALUES (@tipo_merc_id, N'Carga 17', 100, 1.0);
    EXEC dbo.sp_crear_solicitud @codigo=NULL, @cliente_id=@cliente_id, @origen_id=@u1, @destino_id=@u4, @destinatario_nombre=N'A', @destinatario_apellido1=N'B', @destinatario_apellido2=N'C', @destinatario_identificacion=N'1', @destinatario_telefono=N'8', @fecha_entrega_requerida=@futuro, @mercancias=@merc, @solicitud_id=@sol1 OUTPUT;
    EXEC dbo.sp_crear_solicitud @codigo=NULL, @cliente_id=@cliente_id, @origen_id=@u1, @destino_id=@u2, @destinatario_nombre=N'D', @destinatario_apellido1=N'E', @destinatario_apellido2=N'F', @destinatario_identificacion=N'2', @destinatario_telefono=N'8', @fecha_entrega_requerida=@futuro, @mercancias=@merc, @solicitud_id=@sol2 OUTPUT;

    DECLARE @vs1 INT, @vs2 INT;
    EXEC dbo.sp_asignar_solicitud_viaje @viaje_id=@viaje_id, @solicitud_id=@sol1, @parada_recogida_id=@p1, @parada_entrega_id=@p3, @viaje_solicitud_id=@vs1 OUTPUT;
    EXEC dbo.sp_asignar_solicitud_viaje @viaje_id=@viaje_id, @solicitud_id=@sol2, @parada_recogida_id=@p1, @parada_entrega_id=@p2, @viaje_solicitud_id=@vs2 OUTPUT;

    -- 1. Reordenar 4 <-> 3 (p4 pasa a #3, p3 pasa a #4): sigue cumpliéndose p1(1) < p2(2) y p1(1) < p3(4)!
    DECLARE @ord_ok dbo.tipo_orden_paradas;
    INSERT INTO @ord_ok (parada_id, orden) VALUES (@p1, 1), (@p2, 2), (@p4, 3), (@p3, 4);
    EXEC dbo.sp_reordenar_paradas_viaje @viaje_id=@viaje_id, @paradas=@ord_ok;

    IF (SELECT orden FROM dbo.paradas_viaje WHERE parada_id = @p4) <> 3
       OR (SELECT orden FROM dbo.paradas_viaje WHERE parada_id = @p3) <> 4
        THROW 50985, N'El intercambio 4<->3 no persistió correctamente.', 1;

    IF NOT EXISTS (SELECT 1 FROM dbo.auditorias WHERE entidad = N'paradas_viaje' AND registro_id = CONVERT(NVARCHAR(100), @viaje_id) AND accion = 'REORDER')
        THROW 50986, N'El reordenamiento debe generar evento REORDER en dbo.auditorias.', 1;

    PRINT '1. [OK] Reordenamiento atómico 4<->3 con 2 solicitudes asignadas y auditoría REORDER.';

    -- 2. Reordenamiento inválido (entrega p2 antes que recogida p1) -> 50333 sin cambios parciales
    DECLARE @ord_bad dbo.tipo_orden_paradas;
    INSERT INTO @ord_bad (parada_id, orden) VALUES (@p2, 1), (@p1, 2), (@p4, 3), (@p3, 4);
    DECLARE @err INT = 0;
    BEGIN TRY
        EXEC dbo.sp_reordenar_paradas_viaje @viaje_id=@viaje_id, @paradas=@ord_bad;
    END TRY
    BEGIN CATCH SET @err = ERROR_NUMBER(); END CATCH;
    IF @err <> 50333 OR (SELECT orden FROM dbo.paradas_viaje WHERE parada_id = @p1) <> 1
        THROW 50987, N'Orden con entrega antes de recogida debe fallar con 50333 sin mutar filas.', 1;

    -- 3. Secuencia incompleta -> 50331; secuencia no consecutiva (1,2,3,5) -> 50332
    DECLARE @ord_inc dbo.tipo_orden_paradas;
    INSERT INTO @ord_inc (parada_id, orden) VALUES (@p1, 1), (@p2, 2), (@p4, 3);
    SET @err = 0;
    BEGIN TRY EXEC dbo.sp_reordenar_paradas_viaje @viaje_id=@viaje_id, @paradas=@ord_inc; END TRY
    BEGIN CATCH SET @err = ERROR_NUMBER(); END CATCH;
    IF @err <> 50331 THROW 50988, N'Paradas omitidas deben fallar con 50331.', 1;

    DECLARE @ord_gap dbo.tipo_orden_paradas;
    INSERT INTO @ord_gap (parada_id, orden) VALUES (@p1, 1), (@p2, 2), (@p4, 3), (@p3, 5);
    SET @err = 0;
    BEGIN TRY EXEC dbo.sp_reordenar_paradas_viaje @viaje_id=@viaje_id, @paradas=@ord_gap; END TRY
    BEGIN CATCH SET @err = ERROR_NUMBER(); END CATCH;
    IF @err <> 50332 THROW 50989, N'Secuencia no consecutiva debe fallar con 50332.', 1;

    PRINT '2. [OK] Rechazos 50333 (precedencia), 50331 (incompleta) y 50332 (no consecutiva).';

    -- 4. Editar parada NO vinculada (p4) OK; editar parada VINCULADA (p3) -> 50336
    EXEC dbo.sp_editar_parada_viaje @viaje_id=@viaje_id, @parada_id=@p4, @ubicacion_id=@u2, @tipo_parada='MIXTA';
    IF (SELECT ubicacion_id FROM dbo.paradas_viaje WHERE parada_id = @p4) <> @u2
       OR (SELECT tipo_parada FROM dbo.paradas_viaje WHERE parada_id = @p4) <> 'MIXTA'
        THROW 50990, N'Edición de parada no vinculada no persistió.', 1;

    SET @err = 0;
    BEGIN TRY
        EXEC dbo.sp_editar_parada_viaje @viaje_id=@viaje_id, @parada_id=@p3, @ubicacion_id=@u3, @tipo_parada='INTERMEDIA';
    END TRY
    BEGIN CATCH SET @err = ERROR_NUMBER(); END CATCH;
    IF @err <> 50336 THROW 50991, N'Editar parada vinculada debe rechazarse con 50336.', 1;

    -- 5. Iniciar viaje -> reordenar o editar debe rechazarse con 50307
    EXEC dbo.sp_iniciar_viaje @viaje_id=@viaje_id;
    SET @err = 0;
    BEGIN TRY EXEC dbo.sp_reordenar_paradas_viaje @viaje_id=@viaje_id, @paradas=@ord_ok; END TRY
    BEGIN CATCH SET @err = ERROR_NUMBER(); END CATCH;
    IF @err <> 50307 THROW 50992, N'Reordenar viaje EN_CURSO debe rechazarse con 50307.', 1;

    SET @err = 0;
    BEGIN TRY EXEC dbo.sp_editar_parada_viaje @viaje_id=@viaje_id, @parada_id=@p4, @ubicacion_id=@u3, @tipo_parada='INTERMEDIA'; END TRY
    BEGIN CATCH SET @err = ERROR_NUMBER(); END CATCH;
    IF @err <> 50307 THROW 50993, N'Editar parada de viaje EN_CURSO debe rechazarse con 50307.', 1;

    PRINT '3. [OK] Edición controlada de parada, protección referencial (50336) y bloqueo EN_CURSO (50307).';

    -- 6. Bitácora: filtrar por usuario (incluyendo usuario inactivo) y acción en SQL
    DECLARE @inact_name NVARCHAR(80) = N'inact_' + RIGHT(@tag, 8);
    DECLARE @inact_mail NVARCHAR(150) = @inact_name + N'@example.test';
    INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash, activo)
    VALUES (@inact_name, @inact_mail, N'$2y$10$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234', 0);
    SET @inactivo_uid = CONVERT(INT, SCOPE_IDENTITY());

    INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
    VALUES (@inactivo_uid, N'paradas_viaje', N'99999', 'REORDER', N'Prev', N'New');

    DECLARE @tot_aud INT = 0;
    DECLARE @aud_res TABLE (
        auditoria_id BIGINT, entidad NVARCHAR(100), registro_id NVARCHAR(100), accion VARCHAR(20),
        fecha_hora DATETIME2, usuario_id INT, usuario NVARCHAR(80), usuario_activo BIT,
        valores_anteriores NVARCHAR(MAX), valores_nuevos NVARCHAR(MAX)
    );
    INSERT INTO @aud_res
    EXEC dbo.sp_listar_auditorias @entidad=N'paradas_viaje', @registro_id=NULL, @desde=NULL, @hasta=NULL, @pagina=1, @por_pagina=20, @total_registros=@tot_aud OUTPUT, @usuario_buscar=@inact_name, @accion='REORDER';

    IF @tot_aud < 1 OR NOT EXISTS (SELECT 1 FROM @aud_res WHERE usuario = @inact_name AND usuario_activo = 0)
        THROW 50994, N'sp_listar_auditorias debe encontrar eventos de usuarios inactivos filtrando por nombre y acción.', 1;

    -- 7. Reportes agregados: verificar ejecución y control de permisos
    EXEC dbo.sp_reporte_solicitudes_resumen @buscar=NULL, @estado=NULL, @prioridad=NULL, @desde=NULL, @hasta=NULL;
    EXEC dbo.sp_reporte_entregas_resumen @estado=NULL, @desde=NULL, @hasta=NULL;

    -- Actor sin permisos recibe 50103 en auditoría y reportes y 50306 en paradas
    DECLARE @nop_name NVARCHAR(80) = N'nop17_' + RIGHT(@tag, 8);
    DECLARE @nop_mail NVARCHAR(150) = @nop_name + N'@example.test';
    INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash, activo)
    VALUES (@nop_name, @nop_mail, N'$2y$10$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234', 1);
    SET @sin_perm_uid = CONVERT(INT, SCOPE_IDENTITY());
    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @sin_perm_uid;

    SET @err = 0;
    BEGIN TRY EXEC dbo.sp_reordenar_paradas_viaje @viaje_id=@viaje_id, @paradas=@ord_ok; END TRY
    BEGIN CATCH SET @err = ERROR_NUMBER(); END CATCH;
    IF @err <> 50306 THROW 50995, N'Reordenar sin VIAJES_PARADAS debe lanzar 50306.', 1;

    SET @err = 0;
    BEGIN TRY EXEC dbo.sp_reporte_solicitudes_resumen; END TRY
    BEGIN CATCH SET @err = ERROR_NUMBER(); END CATCH;
    IF @err <> 50103 THROW 50996, N'Reporte solicitudes sin permisos debe lanzar 50103.', 1;

    PRINT '4. [OK] Bitácora extendida (inactivos + acción), reportes agregados y autorización estricta verificados.';

    -- Limpieza estricta en Test (borrar hijos/padres y luego auditorías generadas en la prueba)
    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @admin_id;
    DELETE FROM dbo.viaje_solicitudes WHERE viaje_id = @viaje_id;
    DELETE FROM dbo.paradas_viaje WHERE viaje_id = @viaje_id;
    DELETE FROM dbo.viajes WHERE viaje_id = @viaje_id;
    DELETE FROM dbo.historial_estados_solicitud WHERE solicitud_id IN (@sol1, @sol2);
    DELETE FROM dbo.mercancias WHERE solicitud_id IN (@sol1, @sol2);
    DELETE FROM dbo.solicitudes WHERE solicitud_id IN (@sol1, @sol2);
    DELETE FROM dbo.rutas WHERE ruta_id = @ruta_id;
    DELETE FROM dbo.vehiculos WHERE vehiculo_id = @veh_id;
    DELETE FROM dbo.conductores_licencias WHERE conductor_id = @cond_id;
    DELETE FROM dbo.conductores WHERE conductor_id = @cond_id;
    DELETE FROM dbo.ubicaciones WHERE ubicacion_id IN (@u1, @u2, @u3, @u4);
    DELETE FROM dbo.auditorias WHERE auditoria_id > @max_aud_id;
    DELETE FROM dbo.usuarios WHERE usuario_id IN (@inactivo_uid, @sin_perm_uid);

    PRINT '=== SUITE T-SQL 17 (PARADAS, BITÁCORA Y REPORTES): 100% PASS ===';
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    -- Limpieza defensiva
    DELETE FROM dbo.viaje_solicitudes WHERE viaje_id = @viaje_id;
    DELETE FROM dbo.paradas_viaje WHERE viaje_id = @viaje_id;
    DELETE FROM dbo.viajes WHERE viaje_id = @viaje_id;
    DELETE FROM dbo.historial_estados_solicitud WHERE solicitud_id IN (@sol1, @sol2);
    DELETE FROM dbo.mercancias WHERE solicitud_id IN (@sol1, @sol2);
    DELETE FROM dbo.solicitudes WHERE solicitud_id IN (@sol1, @sol2);
    DELETE FROM dbo.rutas WHERE ruta_id = @ruta_id;
    DELETE FROM dbo.vehiculos WHERE vehiculo_id = @veh_id;
    DELETE FROM dbo.conductores_licencias WHERE conductor_id = @cond_id;
    DELETE FROM dbo.conductores WHERE conductor_id = @cond_id;
    DELETE FROM dbo.ubicaciones WHERE ubicacion_id IN (@u1, @u2, @u3, @u4);
    DELETE FROM dbo.auditorias WHERE auditoria_id > @max_aud_id;
    DELETE FROM dbo.usuarios WHERE usuario_id IN (@inactivo_uid, @sin_perm_uid);
    THROW;
END CATCH;
GO
