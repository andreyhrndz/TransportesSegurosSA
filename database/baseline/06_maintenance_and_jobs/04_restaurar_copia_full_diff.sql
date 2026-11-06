USE master;
GO

-- NO sobreescribe la base principal.
-- Requiere un backup FULL y al menos un DIFF compatible.
IF DB_ID(N'TransportesSegurosSA_PruebaRestore') IS NOT NULL
    THROW 50810, 'La base de restauracion ya existe. No se reemplaza.', 1;

DECLARE @full NVARCHAR(500);
DECLARE @diff NVARCHAR(500);
DECLARE @base_lsn NUMERIC(25,0);
DECLARE @inicio_diff DATETIME;

-- Buscar el ultimo diferencial del proyecto.
SELECT TOP (1)
    @diff = f.physical_device_name,
    @base_lsn = b.differential_base_lsn,
    @inicio_diff = b.backup_start_date
FROM msdb.dbo.backupset b
JOIN msdb.dbo.backupmediafamily f ON f.media_set_id = b.media_set_id
WHERE b.database_name = N'TransportesSegurosSA'
  AND b.type = 'I'
  AND f.physical_device_name LIKE N'C:\SQL\Backups\TransportesSegurosSA_%'
ORDER BY b.backup_set_id DESC;

IF @diff IS NULL
    THROW 50811, 'No existe backup diferencial.', 1;

-- Buscar el FULL que corresponda al diferencial.
SELECT TOP (1)
    @full = f.physical_device_name
FROM msdb.dbo.backupset b
JOIN msdb.dbo.backupmediafamily f ON f.media_set_id = b.media_set_id
WHERE b.database_name = N'TransportesSegurosSA'
  AND b.type = 'D'
  AND b.is_copy_only = 0
  AND b.checkpoint_lsn = @base_lsn
  AND b.backup_finish_date <= @inicio_diff
ORDER BY b.backup_set_id DESC;

IF @full IS NULL
    THROW 50812, 'No se encontro el FULL base de ese diferencial.', 1;

SELECT @full AS ArchivoFull, @diff AS ArchivoDiferencial;

-- Los nombres logicos son constantes porque los fijamos en CREATE DATABASE.
RESTORE DATABASE TransportesSegurosSA_PruebaRestore
FROM DISK = @full
WITH
    MOVE 'TransportesSegurosSA_Data'
        TO 'C:\SQL\SQLData\TransportesSegurosSA_PruebaRestore.mdf',
    MOVE 'TransportesSegurosSA_Log'
        TO 'C:\SQL\SQLLog\TransportesSegurosSA_PruebaRestore.ldf',
    NORECOVERY,
    CHECKSUM,
    STATS = 10;

RESTORE DATABASE TransportesSegurosSA_PruebaRestore
FROM DISK = @diff
WITH RECOVERY, CHECKSUM, STATS = 10;

SELECT name, state_desc
FROM sys.databases
WHERE name IN (N'TransportesSegurosSA', N'TransportesSegurosSA_PruebaRestore');

SELECT
    (SELECT COUNT(*) FROM TransportesSegurosSA.dbo.solicitudes) AS SolicitudesOriginal,
    (SELECT COUNT(*) FROM TransportesSegurosSA_PruebaRestore.dbo.solicitudes) AS SolicitudesRestauradas,
    (SELECT COUNT(*) FROM TransportesSegurosSA.dbo.entregas) AS EntregasOriginal,
    (SELECT COUNT(*) FROM TransportesSegurosSA_PruebaRestore.dbo.entregas) AS EntregasRestauradas;
GO
