USE TransportesSegurosSA;
GO


-- Una demostracion corta y segura de ROLLBACK.
DECLARE @antes INT = (SELECT COUNT(*) FROM dbo.ubicaciones);

BEGIN TRY
    BEGIN TRANSACTION;

    INSERT INTO dbo.ubicaciones (nombre, direccion)
    VALUES (N'DEMO-ROLLBACK', N'Una ubicacion temporal');

    -- Error deliberado: falta direccion NOT NULL.
    INSERT INTO dbo.ubicaciones (nombre, direccion)
    VALUES (N'DEMO-ERROR', NULL);

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0
        ROLLBACK TRANSACTION;

    SELECT ERROR_NUMBER() AS ErrorEsperado,
           ERROR_MESSAGE() AS Mensaje;
END CATCH;

DECLARE @despues INT = (SELECT COUNT(*) FROM dbo.ubicaciones);

SELECT
    @antes AS Antes,
    @despues AS Despues,
    CASE WHEN @antes = @despues THEN 'PASS' ELSE 'FAIL' END AS ValidacionRollback;

-- Un viaje con 2 solicitudes no debe tener entregas duplicadas.
SELECT
    s.codigo_solicitud,
    COUNT(e.entrega_id) AS RegistrosEntrega
FROM dbo.solicitudes s
JOIN dbo.viaje_solicitudes vs ON vs.solicitud_id = s.solicitud_id
LEFT JOIN dbo.entregas e ON e.viaje_solicitud_id = vs.viaje_solicitud_id
GROUP BY s.codigo_solicitud
ORDER BY s.codigo_solicitud;
GO
