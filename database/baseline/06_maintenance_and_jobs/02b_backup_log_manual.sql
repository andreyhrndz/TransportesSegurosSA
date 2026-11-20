USE master;
GO

-- Respaldo manual de Transaction Log (.trn) para RPO <= 15 min y truncado del .ldf.
-- Requiere que la base este en modelo FULL y cuente con al menos un backup FULL previo.

DECLARE @db SYSNAME = N'TransportesSegurosSA';

IF NOT EXISTS (SELECT 1 FROM sys.databases WHERE name = @db AND recovery_model_desc = N'FULL')
    THROW 50912, N'La base de datos debe estar en modelo de recuperacion FULL.', 1;

IF NOT EXISTS (SELECT 1 FROM msdb.dbo.backupset WHERE database_name = @db AND type = 'D' AND is_copy_only = 0)
    THROW 50911, N'Primero ejecute un backup FULL antes de respaldar el Transaction Log.', 1;

DECLARE @dir NVARCHAR(400) = CONVERT(NVARCHAR(400), SERVERPROPERTY('InstanceDefaultBackupPath'));
IF RIGHT(@dir, 1) NOT IN (N'\', N'/') SET @dir = @dir + N'\';

DECLARE @ruta NVARCHAR(500) = @dir
    + @db + N'_MANUAL_LOG_'
    + CONVERT(CHAR(8), GETDATE(), 112)
    + N'_' + REPLACE(CONVERT(CHAR(8), GETDATE(), 108), N':', N'')
    + N'.trn';

BACKUP LOG [TransportesSegurosSA]
TO DISK = @ruta
WITH INIT, COMPRESSION, CHECKSUM, STATS = 10;

RESTORE VERIFYONLY
FROM DISK = @ruta
WITH CHECKSUM;

SELECT @ruta AS RutaRespaldoLog;
GO
