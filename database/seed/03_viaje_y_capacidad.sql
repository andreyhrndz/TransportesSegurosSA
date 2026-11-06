USE TransportesSegurosSA;
GO


DECLARE @admin INT = (SELECT usuario_id FROM dbo.usuarios WHERE nombre_usuario = N'admin_proyecto');
EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @admin;

DECLARE @vehiculo INT = (SELECT vehiculo_id FROM dbo.vehiculos WHERE placa = N'DEMO-G6-001');
DECLARE @conductor INT = (SELECT conductor_id FROM dbo.conductores WHERE identificacion = N'DEMO-COND-001');
DECLARE @ruta INT = (SELECT ruta_id FROM dbo.rutas WHERE nombre = N'Nicoya - Liberia (ruta demo)');
DECLARE @nicoya INT = (SELECT ubicacion_id FROM dbo.ubicaciones WHERE nombre = N'Nicoya');
DECLARE @santa_cruz INT = (SELECT ubicacion_id FROM dbo.ubicaciones WHERE nombre = N'Santa Cruz');
DECLARE @liberia INT = (SELECT ubicacion_id FROM dbo.ubicaciones WHERE nombre = N'Liberia');

DECLARE @salida DATETIME2 = SYSUTCDATETIME();
DECLARE @viaje INT;
DECLARE @recogida INT;
DECLARE @entrega1 INT;
DECLARE @entrega2 INT;
DECLARE @asignacion INT;

-- Un solo viaje para varias solicitudes.
EXEC dbo.sp_crear_viaje
    @codigo = N'DEMO-VIAJE-001',
    @vehiculo_id = @vehiculo,
    @conductor_id = @conductor,
    @ruta_id = @ruta,
    @fecha_programada = @salida,
    @viaje_id = @viaje OUTPUT;

-- Orden obligatorio de recorrido.
EXEC dbo.sp_agregar_parada @viaje, @nicoya, 1, 'RECOGIDA', @recogida OUTPUT;
EXEC dbo.sp_agregar_parada @viaje, @santa_cruz, 2, 'ENTREGA', @entrega1 OUTPUT;
EXEC dbo.sp_agregar_parada @viaje, @liberia, 3, 'ENTREGA', @entrega2 OUTPUT;

DECLARE @solicitud1 INT = (SELECT solicitud_id FROM dbo.solicitudes WHERE codigo_solicitud = N'DEMO-SOL-001');
DECLARE @solicitud2 INT = (SELECT solicitud_id FROM dbo.solicitudes WHERE codigo_solicitud = N'DEMO-SOL-002');
DECLARE @solicitud3 INT = (SELECT solicitud_id FROM dbo.solicitudes WHERE codigo_solicitud = N'DEMO-SOL-003');

EXEC dbo.sp_asignar_solicitud_viaje
    @viaje, @solicitud1, @recogida, @entrega1, @asignacion OUTPUT;

EXEC dbo.sp_asignar_solicitud_viaje
    @viaje, @solicitud2, @recogida, @entrega2, @asignacion OUTPUT;

-- Prueba de ROLLBACK: 300 + 250 + 800 supera 1000 kg.
BEGIN TRY
    EXEC dbo.sp_asignar_solicitud_viaje
        @viaje, @solicitud3, @recogida, @entrega2, @asignacion OUTPUT;

    SELECT 'FAIL: Debio rechazar exceso de peso' AS Resultado;
END TRY
BEGIN CATCH
    SELECT
        CASE WHEN ERROR_NUMBER() = 50313 THEN 'PASS' ELSE 'FAIL' END AS Resultado,
        ERROR_NUMBER() AS CodigoError,
        ERROR_MESSAGE() AS Mensaje;
END CATCH;

-- El viaje conserva exactamente dos solicitudes.
SELECT
    v.codigo_viaje,
    COUNT(vs.viaje_solicitud_id) AS SolicitudesAsignadas,
    SUM(m.peso_kg) AS TotalPesoKG
FROM dbo.viajes v
JOIN dbo.viaje_solicitudes vs ON vs.viaje_id = v.viaje_id
JOIN dbo.mercancias m ON m.solicitud_id = vs.solicitud_id
WHERE v.viaje_id = @viaje
GROUP BY v.codigo_viaje;

IF (SELECT COUNT(*) FROM dbo.viaje_solicitudes WHERE viaje_id = @viaje) <> 2
    THROW 50510, 'Error: el rechazo dejo registros parciales.', 1;

EXEC sys.sp_set_session_context @key = N'usuario_id', @value = NULL;
GO
