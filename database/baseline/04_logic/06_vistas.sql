USE TransportesSegurosSA;
GO


CREATE OR ALTER VIEW dbo.vw_solicitudes_activas
AS
SELECT
    s.solicitud_id,
    s.codigo_solicitud,
    s.estado,
    s.prioridad_actual,
    s.fecha_entrega_requerida,
    s.destinatario_nombre,
    s.destinatario_apellido1,
    s.destinatario_apellido2,
    vs.viaje_id,
    v.codigo_viaje
FROM dbo.solicitudes s
LEFT JOIN dbo.viaje_solicitudes vs ON vs.solicitud_id = s.solicitud_id
LEFT JOIN dbo.viajes v ON v.viaje_id = vs.viaje_id
WHERE s.estado NOT IN ('ENTREGADA', 'ENTREGA_FALLIDA', 'CANCELADA');
GO

CREATE OR ALTER VIEW dbo.vw_estado_flota
AS
SELECT
    ve.vehiculo_id,
    ve.placa,
    ve.estado,
    ve.activo,
    ve.capacidad_carga_kg,
    ve.capacidad_volumen_m3,
    v.viaje_id AS viaje_activo
FROM dbo.vehiculos ve
LEFT JOIN dbo.viajes v
    ON v.vehiculo_id = ve.vehiculo_id
   AND v.estado IN ('PLANIFICADO', 'EN_CURSO');
GO

CREATE OR ALTER VIEW dbo.vw_detalle_viajes
AS
SELECT
    v.viaje_id,
    v.codigo_viaje,
    v.estado,
    ve.placa,
    co.nombre AS conductor_nombre,
    s.solicitud_id,
    s.codigo_solicitud,
    s.destinatario_nombre,
    s.estado AS estado_solicitud,
    e.estado AS resultado_entrega,
    e.receptor_nombre,
    e.fecha_recepcion
FROM dbo.viajes v
JOIN dbo.vehiculos ve ON ve.vehiculo_id = v.vehiculo_id
JOIN dbo.conductores co ON co.conductor_id = v.conductor_id
LEFT JOIN dbo.viaje_solicitudes vs ON vs.viaje_id = v.viaje_id
LEFT JOIN dbo.solicitudes s ON s.solicitud_id = vs.solicitud_id
LEFT JOIN dbo.entregas e ON e.viaje_solicitud_id = vs.viaje_solicitud_id;
GO

CREATE OR ALTER VIEW dbo.vw_entregas
AS
SELECT
    e.entrega_id,
    v.codigo_viaje,
    s.codigo_solicitud,
    e.estado,
    e.receptor_nombre,
    e.fecha_recepcion,
    e.comprobante_url,
    e.motivo_fallo
FROM dbo.entregas e
JOIN dbo.viaje_solicitudes vs ON vs.viaje_solicitud_id = e.viaje_solicitud_id
JOIN dbo.viajes v ON v.viaje_id = vs.viaje_id
JOIN dbo.solicitudes s ON s.solicitud_id = vs.solicitud_id;
GO

CREATE OR ALTER VIEW dbo.vw_indicadores_operativos
AS
SELECT
    CONVERT(DATE, s.fecha_solicitud) AS fecha_solicitud,
    COUNT(*) AS total_solicitudes,
    SUM(CASE WHEN s.estado = 'ENTREGADA' THEN 1 ELSE 0 END) AS entregadas,
    SUM(CASE WHEN s.estado = 'ENTREGA_FALLIDA' THEN 1 ELSE 0 END) AS fallidas,
    SUM(CASE WHEN s.estado = 'CANCELADA' THEN 1 ELSE 0 END) AS canceladas
FROM dbo.solicitudes s
GROUP BY CONVERT(DATE, s.fecha_solicitud);
GO
