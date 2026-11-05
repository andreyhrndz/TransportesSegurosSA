USE TransportesSegurosSA;
GO


-- CONSULTAS LISTAS PARA LA EXPOSICION

SELECT COUNT(*) AS TotalTablas FROM sys.tables WHERE is_ms_shipped = 0;
SELECT COUNT(*) AS TotalSP FROM sys.procedures WHERE is_ms_shipped = 0;

SELECT
    v.codigo_viaje, v.estado AS EstadoViaje, ve.placa,
    COUNT(vs.viaje_solicitud_id) AS SolicitudesEnViaje
FROM dbo.viajes v
JOIN dbo.vehiculos ve ON ve.vehiculo_id = v.vehiculo_id
LEFT JOIN dbo.viaje_solicitudes vs ON vs.viaje_id = v.viaje_id
GROUP BY v.codigo_viaje, v.estado, ve.placa;

SELECT * FROM dbo.vw_detalle_viajes;
SELECT * FROM dbo.vw_entregas;
SELECT * FROM dbo.vw_indicadores_operativos;
SELECT TOP (30) * FROM dbo.auditorias ORDER BY auditoria_id DESC;
GO
