USE TransportesSegurosSA_DW;
GO

-- ETL inicial simple: agrega nuevas entregas, sin duplicar.
-- Las entregas y sus resultados se consideran historicos e inmutables.
CREATE OR ALTER PROCEDURE dbo.sp_cargar_datawarehouse
AS
BEGIN
    SET NOCOUNT ON;

    BEGIN TRY
        BEGIN TRANSACTION;

        -- 1. Fechas de las entregas.
        INSERT INTO dbo.dim_fecha (fecha_key, fecha, anio, mes, dia)
        SELECT DISTINCT
            YEAR(e.fecha_resultado) * 10000 +
            MONTH(e.fecha_resultado) * 100 + DAY(e.fecha_resultado),
            CONVERT(DATE, e.fecha_resultado),
            YEAR(e.fecha_resultado),
            MONTH(e.fecha_resultado),
            DAY(e.fecha_resultado)
        FROM TransportesSegurosSA.dbo.entregas e
        WHERE NOT EXISTS
        (
            SELECT 1 FROM dbo.dim_fecha f
            WHERE f.fecha = CONVERT(DATE, e.fecha_resultado)
        );

        -- 2. Dimension cliente.
        INSERT INTO dbo.dim_cliente (cliente_id_origen, identificacion)
        SELECT c.cliente_id, c.identificacion
        FROM TransportesSegurosSA.dbo.clientes c
        WHERE NOT EXISTS
        (
            SELECT 1 FROM dbo.dim_cliente d
            WHERE d.cliente_id_origen = c.cliente_id
        );

        -- 3. Dimension vehiculo.
        INSERT INTO dbo.dim_vehiculo (vehiculo_id_origen, placa)
        SELECT v.vehiculo_id, v.placa
        FROM TransportesSegurosSA.dbo.vehiculos v
        WHERE NOT EXISTS
        (
            SELECT 1 FROM dbo.dim_vehiculo d
            WHERE d.vehiculo_id_origen = v.vehiculo_id
        );

        -- 4. Dimension conductor.
        INSERT INTO dbo.dim_conductor (conductor_id_origen, nombre_completo)
        SELECT
            c.conductor_id,
            CONCAT(c.nombre, N' ', c.primer_apellido, N' ', ISNULL(c.segundo_apellido, N''))
        FROM TransportesSegurosSA.dbo.conductores c
        WHERE NOT EXISTS
        (
            SELECT 1 FROM dbo.dim_conductor d
            WHERE d.conductor_id_origen = c.conductor_id
        );

        -- 5. Dimension ruta.
        INSERT INTO dbo.dim_ruta (ruta_id_origen, nombre)
        SELECT r.ruta_id, r.nombre
        FROM TransportesSegurosSA.dbo.rutas r
        WHERE NOT EXISTS
        (
            SELECT 1 FROM dbo.dim_ruta d
            WHERE d.ruta_id_origen = r.ruta_id
        );

        -- 6. Una fila de hecho por entrega (exitosa O fallida).
        INSERT INTO dbo.fact_entregas
        (
            entrega_id_origen, fecha_key, cliente_key,
            vehiculo_key, conductor_key, ruta_key,
            viaje_id_origen, solicitud_id_origen,
            resultado, peso_kg, volumen_m3, incidencias, entrega_puntual
        )
        SELECT
            e.entrega_id,
            YEAR(e.fecha_resultado) * 10000 +
            MONTH(e.fecha_resultado) * 100 + DAY(e.fecha_resultado),
            dc.cliente_key,
            dv.vehiculo_key,
            dcon.conductor_key,
            dr.ruta_key,
            v.viaje_id,
            s.solicitud_id,
            e.estado,
            (SELECT SUM(m.peso_kg)
             FROM TransportesSegurosSA.dbo.mercancias m
             WHERE m.solicitud_id = s.solicitud_id),
            (SELECT SUM(m.volumen_m3)
             FROM TransportesSegurosSA.dbo.mercancias m
             WHERE m.solicitud_id = s.solicitud_id),
            (SELECT COUNT(*)
             FROM TransportesSegurosSA.dbo.incidencias i
             WHERE i.solicitud_id = s.solicitud_id),
            CASE WHEN e.estado = 'ENTREGADA'
                       AND e.fecha_recepcion <= s.fecha_entrega_requerida
                 THEN 1 ELSE 0 END
        FROM TransportesSegurosSA.dbo.entregas e
        JOIN TransportesSegurosSA.dbo.viaje_solicitudes vs
            ON vs.viaje_solicitud_id = e.viaje_solicitud_id
        JOIN TransportesSegurosSA.dbo.solicitudes s
            ON s.solicitud_id = vs.solicitud_id
        JOIN TransportesSegurosSA.dbo.viajes v ON v.viaje_id = vs.viaje_id
        JOIN dbo.dim_cliente dc ON dc.cliente_id_origen = s.cliente_id
        JOIN dbo.dim_vehiculo dv ON dv.vehiculo_id_origen = v.vehiculo_id
        JOIN dbo.dim_conductor dcon ON dcon.conductor_id_origen = v.conductor_id
        JOIN dbo.dim_ruta dr ON dr.ruta_id_origen = v.ruta_id
        WHERE NOT EXISTS
        (
            SELECT 1 FROM dbo.fact_entregas f
            WHERE f.entrega_id_origen = e.entrega_id
        );

        COMMIT TRANSACTION;

        SELECT COUNT(*) AS TotalHechos
        FROM dbo.fact_entregas;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER VIEW dbo.vw_resumen_entregas
AS
SELECT
    resultado,
    COUNT(*) AS cantidad,
    SUM(peso_kg) AS peso_total_kg,
    SUM(incidencias) AS incidencias
FROM dbo.fact_entregas
GROUP BY resultado;
GO
