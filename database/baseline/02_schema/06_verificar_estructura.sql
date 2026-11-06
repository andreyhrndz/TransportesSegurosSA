-- ============================================================
-- VERIFICAR ESTRUCTURA
-- Base de datos: TransportesSegurosSA
-- 
-- ============================================================

USE TransportesSegurosSA;
GO

SELECT DB_NAME() AS BaseDatosActual;

SELECT COUNT(*) AS TotalTablas
FROM sys.tables WHERE is_ms_shipped = 0;

SELECT COUNT(*) AS TotalRelaciones
FROM sys.foreign_keys;

SELECT name AS Tabla
FROM sys.tables
ORDER BY name;

SELECT
    name AS Campo
FROM sys.columns
WHERE object_id = OBJECT_ID(N'dbo.solicitudes')
  AND name LIKE N'destinatario%';

IF OBJECT_ID(N'dbo.seguimientos_gps', N'U') IS NULL
    SELECT 'PASS - Sin seguimiento GPS' AS Verificacion;
ELSE
    THROW 50009, 'Se encontro una tabla GPS que no corresponde al modelo.', 1;

GO
