USE master;
GO

-- Nombre de servidor, edicion y version.
SELECT
    @@SERVERNAME AS Servidor,
    SERVERPROPERTY('Edition') AS Edicion,
    SERVERPROPERTY('ProductVersion') AS VersionSQL;

-- Cuentas de los servicios.
SELECT
    servicename,
    service_account,
    status_desc
FROM sys.dm_server_services
WHERE servicename LIKE N'SQL Server%';

-- Comprobar que las carpetas existen.
SELECT 'DATOS' AS Carpeta, *
FROM sys.dm_os_file_exists(N'C:\SQL\SQLData\');

SELECT 'LOG' AS Carpeta, *
FROM sys.dm_os_file_exists(N'C:\SQL\SQLLog\');

SELECT 'BACKUPS' AS Carpeta, *
FROM sys.dm_os_file_exists(N'C:\SQL\Backups\');
GO
