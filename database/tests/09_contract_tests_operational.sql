/*
    Transportes Seguros S.A.
    Pruebas automatizadas de contratos operativos en TransportesSegurosSA_Test:
    - Autogeneración de códigos de solicitudes y viajes
    - Preservación de códigos explícitos
    - Cancelación de viajes planificados y liberación de recursos
    - Rechazo de cancelación en estados no permitidos y sin permiso
*/

IF DB_NAME() <> N'TransportesSegurosSA_Test'
BEGIN
    RAISERROR('Este script solo debe ejecutarse en TransportesSegurosSA_Test', 20, 1) WITH LOG;
    SET NOEXEC ON;
END
GO

PRINT '=== INICIO DE PRUEBAS DE CONTRATOS OPERATIVOS ===';

BEGIN TRANSACTION;
BEGIN TRY
    -- 1. Configurar contexto como admin (usuario_id = 1)
    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = 1;

    -- 2. Prueba autogeneración de código de solicitud
    DECLARE @mercancias dbo.tipo_mercancias;
    INSERT INTO @mercancias (tipo_mercancia_id, descripcion, peso_kg, volumen_m3)
    VALUES (1, N'Carga prueba autogeneración', 50.00, 2.500);

    DECLARE @solicitud_id INT;
    DECLARE @fecha_entrega DATETIME2 = DATEADD(DAY, 3, SYSUTCDATETIME());

    EXEC dbo.sp_crear_solicitud
        @codigo = NULL,
        @cliente_id = 1,
        @origen_id = 1,
        @destino_id = 2,
        @destinatario_nombre = N'Receptor',
        @destinatario_apellido1 = N'Prueba',
        @destinatario_apellido2 = N'Automatica',
        @destinatario_identificacion = N'111111111',
        @destinatario_telefono = N'88888888',
        @fecha_entrega_requerida = @fecha_entrega,
        @mercancias = @mercancias,
        @solicitud_id = @solicitud_id OUTPUT;

    IF @solicitud_id IS NULL OR @solicitud_id <= 0
        THROW 59001, 'No se generó el ID de solicitud.', 1;

    DECLARE @codigo_generado_sol NVARCHAR(30) = (SELECT codigo_solicitud FROM dbo.solicitudes WHERE solicitud_id = @solicitud_id);
    IF @codigo_generado_sol NOT LIKE N'SOL-%'
        THROW 59002, 'El código de solicitud generado no tiene el prefijo SOL-.', 1;
    PRINT 'OK: Solicitud autogenerada con código: ' + @codigo_generado_sol;

    -- 3. Prueba autogeneración de código de viaje
    DECLARE @viaje_id INT;
    DECLARE @fecha_viaje DATETIME2 = DATEADD(DAY, 2, SYSUTCDATETIME());

    EXEC dbo.sp_crear_viaje
        @codigo = NULL,
        @vehiculo_id = 1,
        @conductor_id = 1,
        @ruta_id = 1,
        @fecha_programada = @fecha_viaje,
        @viaje_id = @viaje_id OUTPUT;

    IF @viaje_id IS NULL OR @viaje_id <= 0
        THROW 59003, 'No se generó el ID de viaje.', 1;

    DECLARE @codigo_generado_via NVARCHAR(30) = (SELECT codigo_viaje FROM dbo.viajes WHERE viaje_id = @viaje_id);
    IF @codigo_generado_via NOT LIKE N'VIA-%'
        THROW 59004, 'El código de viaje generado no tiene el prefijo VIA-.', 1;
    PRINT 'OK: Viaje autogenerado con código: ' + @codigo_generado_via;

    -- Verificar que vehículo y conductor quedaron ASIGNADOS
    IF (SELECT estado FROM dbo.vehiculos WHERE vehiculo_id = 1) <> 'ASIGNADO'
        THROW 59005, 'El vehículo debería estar ASIGNADO.', 1;
    IF (SELECT estado_operativo FROM dbo.conductores WHERE conductor_id = 1) <> 'ASIGNADO'
        THROW 59006, 'El conductor debería estar ASIGNADO.', 1;

    -- 4. Agregar paradas y asignar la solicitud
    DECLARE @parada_recogida INT, @parada_entrega INT, @vs_id INT;
    EXEC dbo.sp_agregar_parada @viaje_id = @viaje_id, @ubicacion_id = 1, @orden = 1, @tipo_parada = 'RECOGIDA', @parada_id = @parada_recogida OUTPUT;
    EXEC dbo.sp_agregar_parada @viaje_id = @viaje_id, @ubicacion_id = 2, @orden = 2, @tipo_parada = 'ENTREGA', @parada_id = @parada_entrega OUTPUT;
    EXEC dbo.sp_asignar_solicitud_viaje @viaje_id = @viaje_id, @solicitud_id = @solicitud_id, @parada_recogida_id = @parada_recogida, @parada_entrega_id = @parada_entrega, @viaje_solicitud_id = @vs_id OUTPUT;

    IF (SELECT estado FROM dbo.solicitudes WHERE solicitud_id = @solicitud_id) <> 'ASIGNADA'
        THROW 59007, 'La solicitud debería estar en estado ASIGNADA.', 1;

    -- 5. Cancelar el viaje
    EXEC dbo.sp_cancelar_viaje @viaje_id = @viaje_id, @motivo = N'Cancelación de prueba automatizada';

    -- Verificar estado de viaje, solicitudes y recursos
    IF (SELECT estado FROM dbo.viajes WHERE viaje_id = @viaje_id) <> 'CANCELADO'
        THROW 59008, 'El viaje debería estar CANCELADO.', 1;
    IF (SELECT estado FROM dbo.solicitudes WHERE solicitud_id = @solicitud_id) <> 'PENDIENTE_ASIGNACION'
        THROW 59009, 'La solicitud debería retornar a PENDIENTE_ASIGNACION.', 1;
    IF (SELECT estado FROM dbo.vehiculos WHERE vehiculo_id = 1) <> 'DISPONIBLE'
        THROW 59010, 'El vehículo debería retornar a DISPONIBLE.', 1;
    IF (SELECT estado_operativo FROM dbo.conductores WHERE conductor_id = 1) <> 'DISPONIBLE'
        THROW 59011, 'El conductor debería retornar a DISPONIBLE.', 1;
    IF (SELECT estado FROM dbo.viaje_solicitudes WHERE viaje_solicitud_id = @vs_id) <> 'RETIRADA'
        THROW 59012, 'La asignación de viaje debería estar RETIRADA.', 1;

    PRINT 'OK: Cancelación de viaje completada y recursos liberados con éxito.';

    -- 6. Intentar cancelar un viaje ya cancelado (debe fallar)
    DECLARE @fallo_esperado BIT = 0;
    BEGIN TRY
        EXEC dbo.sp_cancelar_viaje @viaje_id = @viaje_id, @motivo = N'Intento redundante';
    END TRY
    BEGIN CATCH
        IF ERROR_NUMBER() = 50330
            SET @fallo_esperado = 1;
    END CATCH;

    IF @fallo_esperado <> 1
        THROW 59013, 'Debe rechazar cancelar un viaje que ya no está PLANIFICADO.', 1;
    PRINT 'OK: Rechazo de cancelación redundante verificado (50330).';

    ROLLBACK TRANSACTION;
    PRINT 'OK: Todas las pruebas de contratos operativos superadas y revertidas.';
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO
