USE TransportesSegurosSA;
GO


DECLARE @admin INT = (SELECT usuario_id FROM dbo.usuarios WHERE nombre_usuario = N'admin_proyecto');
EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @admin;

DECLARE @sol3 INT = (SELECT solicitud_id FROM dbo.solicitudes WHERE codigo_solicitud = N'DEMO-SOL-003');
DECLARE @sol1 INT = (SELECT solicitud_id FROM dbo.solicitudes WHERE codigo_solicitud = N'DEMO-SOL-001');
DECLARE @tipo INT = (SELECT tipo_incidencia_id FROM dbo.tipos_incidencia WHERE nombre = N'Retraso');
DECLARE @tipo_doc INT = (SELECT tipo_documento_id FROM dbo.tipos_documento WHERE nombre = N'Guia de transporte');
DECLARE @incidencia INT;
DECLARE @documento INT;

EXEC dbo.sp_ajustar_prioridad
    @solicitud_id = @sol3,
    @prioridad = 'URGENTE',
    @justificacion = N'Prioridad academica de prueba';

EXEC dbo.sp_registrar_incidencia
    @solicitud_id = @sol1,
    @tipo_incidencia_id = @tipo,
    @descripcion = N'Retraso de demostracion',
    @incidencia_id = @incidencia OUTPUT;

EXEC dbo.sp_resolver_incidencia
    @incidencia_id = @incidencia,
    @resolucion = N'Incidencia resuelta en prueba';

EXEC dbo.sp_registrar_documento
    @solicitud_id = @sol1,
    @tipo_documento_id = @tipo_doc,
    @numero_referencia = N'GUIA-DEMO-001',
    @archivo_url = N'archivos/guia-demo.pdf',
    @documento_id = @documento OUTPUT;

SELECT codigo_solicitud, prioridad_actual, justificacion_prioridad
FROM dbo.solicitudes WHERE solicitud_id = @sol3;

SELECT * FROM dbo.incidencias WHERE incidencia_id = @incidencia;
SELECT * FROM dbo.documentos WHERE documento_id = @documento;

EXEC sys.sp_set_session_context @key = N'usuario_id', @value = NULL;
GO
