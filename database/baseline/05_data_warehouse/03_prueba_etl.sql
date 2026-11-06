USE TransportesSegurosSA_DW;
GO

-- Primera carga.
EXEC dbo.sp_cargar_datawarehouse;

DECLARE @antes INT = (SELECT COUNT(*) FROM dbo.fact_entregas);

-- Segunda carga sin duplicar.
EXEC dbo.sp_cargar_datawarehouse;

DECLARE @despues INT = (SELECT COUNT(*) FROM dbo.fact_entregas);

SELECT
    @antes AS PrimeraCarga,
    @despues AS SegundaCarga,
    CASE WHEN @antes = @despues THEN 'PASS' ELSE 'FAIL' END AS SinDuplicados;

SELECT * FROM dbo.fact_entregas;
SELECT * FROM dbo.vw_resumen_entregas;
GO
