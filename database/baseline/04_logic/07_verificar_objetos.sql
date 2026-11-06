USE TransportesSegurosSA;
GO


SELECT COUNT(*) AS TotalProcedimientos FROM sys.procedures
WHERE is_ms_shipped = 0;

SELECT COUNT(*) AS TotalTriggers FROM sys.triggers
WHERE parent_class = 1 AND is_ms_shipped = 0;

SELECT COUNT(*) AS TotalVistas FROM sys.views
WHERE is_ms_shipped = 0;

SELECT name AS Procedimiento FROM sys.procedures
WHERE is_ms_shipped = 0 ORDER BY name;
GO
