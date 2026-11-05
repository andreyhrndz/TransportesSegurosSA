-- ============================================================
-- 01 - CREAR LA BASE DE DATOS Y SUS ARCHIVOS
-- Base de datos: TransportesSegurosSA
-- Requiere C:\SQL\SQLData y C:\SQL\SQLLog creadas y con permisos.
-- ============================================================

USE master;
GO

-- El script NO borra bases existentes.
-- Los archivos se guardan en las carpetas indicadas.

IF DB_ID(N'TransportesSegurosSA') IS NOT NULL
BEGIN
    THROW 50001, 'TransportesSegurosSA ya existe. No se modifico.', 1;
END;

-- CREATE DATABASE se ejecuta dentro de su propio lote dinamico.
-- Asi evitamos que una segunda parte del archivo modifique otra base.
EXEC sys.sp_executesql N'
CREATE DATABASE TransportesSegurosSA
ON PRIMARY
(
    NAME = N''TransportesSegurosSA_Data'',
    FILENAME = N''C:\SQL\SQLData\TransportesSegurosSA.mdf'',
    SIZE = 32MB,
    FILEGROWTH = 16MB
)
LOG ON
(
    NAME = N''TransportesSegurosSA_Log'',
    FILENAME = N''C:\SQL\SQLLog\TransportesSegurosSA.ldf'',
    SIZE = 16MB,
    FILEGROWTH = 8MB
);
';

ALTER DATABASE TransportesSegurosSA
SET RECOVERY SIMPLE;

SELECT
    DB_NAME(database_id) AS BaseDatos,
    name AS NombreLogico,
    physical_name AS UbicacionArchivo
FROM sys.master_files
WHERE database_id = DB_ID(N'TransportesSegurosSA');
GO
