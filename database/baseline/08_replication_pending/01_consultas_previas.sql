USE master;
GO

SELECT
    @@SERVERNAME AS Servidor,
    SERVERPROPERTY('Edition') AS Edicion,
    SERVERPROPERTY('ProductVersion') AS Version;

SELECT name, state_desc FROM sys.databases
WHERE name = N'TransportesSegurosSA';

SELECT servicename, status_desc FROM sys.dm_server_services
WHERE servicename LIKE N'SQL Server Agent%';
GO
