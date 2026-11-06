USE TransportesSegurosSA;
GO


DECLARE @admin INT = (SELECT usuario_id FROM dbo.usuarios WHERE nombre_usuario = N'admin_proyecto');
EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @admin;

DECLARE @viaje INT = (SELECT viaje_id FROM dbo.viajes WHERE codigo_viaje = N'DEMO-VIAJE-001');
DECLARE @asig1 INT;
DECLARE @asig2 INT;

SELECT @asig1 = vs.viaje_solicitud_id
FROM dbo.viaje_solicitudes vs
JOIN dbo.solicitudes s ON s.solicitud_id = vs.solicitud_id
WHERE s.codigo_solicitud = N'DEMO-SOL-001';

SELECT @asig2 = vs.viaje_solicitud_id
FROM dbo.viaje_solicitudes vs
JOIN dbo.solicitudes s ON s.solicitud_id = vs.solicitud_id
WHERE s.codigo_solicitud = N'DEMO-SOL-002';

EXEC dbo.sp_iniciar_viaje @viaje_id = @viaje;

-- Entrega exitosa. El enlace es FICTICIO: no se crea un PDF real.
EXEC dbo.sp_registrar_entrega
    @viaje_solicitud_id = @asig1,
    @estado = 'ENTREGADA',
    @receptor_nombre = N'Maria Lopez Vargas',
    @receptor_identificacion = N'DEMO-REC-001',
    @receptor_telefono = N'87000001',
    @comprobante_url = N'comprobantes/DEMO-SOL-001.pdf';

-- Entrega fallida. No se registra ningun segundo intento.
EXEC dbo.sp_registrar_entrega
    @viaje_solicitud_id = @asig2,
    @estado = 'FALLIDA',
    @motivo_fallo = N'Local de destino cerrado al momento de entrega.';

-- La base debe rechazar la segunda entrega de la misma solicitud.
BEGIN TRY
    EXEC dbo.sp_registrar_entrega
        @viaje_solicitud_id = @asig1,
        @estado = 'ENTREGADA',
        @receptor_nombre = N'Otra persona',
        @comprobante_url = N'comprobantes/duplicado.pdf';

    SELECT 'FAIL: Se acepto una segunda entrega' AS Resultado;
END TRY
BEGIN CATCH
    SELECT
        CASE WHEN ERROR_NUMBER() IN (50323, 50324, 2601, 2627)
             THEN 'PASS' ELSE 'FAIL' END AS Resultado,
        ERROR_NUMBER() AS CodigoError,
        ERROR_MESSAGE() AS Mensaje;
END CATCH;

EXEC dbo.sp_finalizar_viaje @viaje_id = @viaje;

SELECT * FROM dbo.vw_entregas ORDER BY codigo_solicitud;
SELECT * FROM dbo.vw_detalle_viajes WHERE codigo_viaje = N'DEMO-VIAJE-001';

SELECT placa, estado FROM dbo.vehiculos WHERE placa = N'DEMO-G6-001';
SELECT identificacion, estado_operativo FROM dbo.conductores WHERE identificacion = N'DEMO-COND-001';

EXEC sys.sp_set_session_context @key = N'usuario_id', @value = NULL;
GO
