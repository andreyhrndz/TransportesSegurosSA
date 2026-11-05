USE TransportesSegurosSA;
GO

-- La tercera solicitud no entro al viaje por exceso de peso.
-- Por eso puede cancelarse sin afectar el viaje ya terminado.

DECLARE @admin INT =
(
    SELECT usuario_id
    FROM dbo.usuarios
    WHERE nombre_usuario = N'admin_proyecto'
);

EXEC sys.sp_set_session_context
    @key = N'usuario_id',
    @value = @admin;

DECLARE @solicitud INT =
(
    SELECT solicitud_id
    FROM dbo.solicitudes
    WHERE codigo_solicitud = N'DEMO-SOL-003'
);

EXEC dbo.sp_cancelar_solicitud
    @solicitud_id = @solicitud,
    @motivo = N'Prueba academica de cancelacion';

SELECT
    codigo_solicitud,
    estado,
    observaciones
FROM dbo.solicitudes
WHERE solicitud_id = @solicitud;

EXEC sys.sp_set_session_context
    @key = N'usuario_id',
    @value = NULL;
GO
