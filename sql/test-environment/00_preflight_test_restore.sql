/*
  Inspección de solo lectura antes de restaurar TransportesSegurosSA_Test.
  Ejecutar en master. No crea, sobrescribe ni modifica bases.
*/
USE master;
GO

IF DB_ID(N'TransportesSegurosSA') IS NULL
    THROW 50901, 'No existe la base fuente TransportesSegurosSA.', 1;

SELECT name, state_desc, recovery_model_desc
FROM sys.databases
WHERE name IN (N'TransportesSegurosSA', N'TransportesSegurosSA_Test');

SELECT name AS logical_name, physical_name, type_desc
FROM sys.master_files
WHERE database_id = DB_ID(N'TransportesSegurosSA')
ORDER BY file_id;

SELECT TOP (10)
    b.backup_set_id, b.type, b.backup_start_date, b.backup_finish_date,
    b.is_copy_only, b.has_backup_checksums, b.checkpoint_lsn,
    f.physical_device_name
FROM msdb.dbo.backupset AS b
JOIN msdb.dbo.backupmediafamily AS f ON f.media_set_id = b.media_set_id
WHERE b.database_name = N'TransportesSegurosSA'
ORDER BY b.backup_set_id DESC;

IF DB_ID(N'TransportesSegurosSA_Test') IS NOT NULL
    THROW 50902, 'TransportesSegurosSA_Test ya existe; no se permite sobrescribirla.', 1;
GO
