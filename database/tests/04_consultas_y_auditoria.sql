USE TransportesSegurosSA;
GO


SELECT * FROM dbo.vw_solicitudes_activas;
SELECT * FROM dbo.vw_estado_flota;
SELECT * FROM dbo.vw_detalle_viajes;
SELECT * FROM dbo.vw_entregas;
SELECT * FROM dbo.vw_indicadores_operativos;

SELECT TOP (40)
    auditoria_id, usuario_id, entidad, registro_id,
    accion, fecha_hora, valores_anteriores, valores_nuevos
FROM dbo.auditorias
ORDER BY auditoria_id DESC;

SELECT
    s.codigo_solicitud,
    h.estado_anterior,
    h.estado_nuevo,
    h.fecha_cambio
FROM dbo.historial_estados_solicitud h
JOIN dbo.solicitudes s ON s.solicitud_id = h.solicitud_id
ORDER BY h.historial_estado_id;
GO
