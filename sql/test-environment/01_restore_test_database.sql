/*
  RESTORE controlado de la copia aislada.
  Precondiciones: ejecutar y revisar 00_preflight_test_restore.sql, identificar
  un FULL valido y confirmar que TransportesSegurosSA_Test no existe.
  Nunca usar WITH REPLACE ni cambiar el nombre de base fuente.

  Este archivo no es ejecutable hasta reemplazar todos los marcadores. Primero
  inspecciona el respaldo y las rutas destino; no modifica la base operativa.
*/
USE master;
GO

DECLARE @backup NVARCHAR(4000) = N'REPLACE_WITH_VERIFIED_FULL_BACKUP_PATH';
DECLARE @target_data NVARCHAR(4000) = N'REPLACE_WITH_TEST_DATA_FILE_PATH';
DECLARE @target_log NVARCHAR(4000) = N'REPLACE_WITH_TEST_LOG_FILE_PATH';
DECLARE @source_data SYSNAME = N'REPLACE_WITH_SOURCE_DATA_LOGICAL_NAME';
DECLARE @source_log SYSNAME = N'REPLACE_WITH_SOURCE_LOG_LOGICAL_NAME';

IF @backup LIKE N'REPLACE_WITH_%'
   OR @target_data LIKE N'REPLACE_WITH_%'
   OR @target_log LIKE N'REPLACE_WITH_%'
   OR @source_data LIKE N'REPLACE_WITH_%'
   OR @source_log LIKE N'REPLACE_WITH_%'
    THROW 50903, 'Complete los marcadores con valores verificados antes de restaurar.', 1;

IF DB_ID(N'TransportesSegurosSA_Test') IS NOT NULL
    THROW 50902, 'TransportesSegurosSA_Test ya existe; no se permite sobrescribirla.', 1;
IF DB_ID(N'TransportesSegurosSA') IS NULL
    THROW 50901, 'No existe la base fuente TransportesSegurosSA.', 1;

/* Confirme tipo FULL, integridad y nombres logicos contra el respaldo elegido. */
RESTORE HEADERONLY FROM DISK = @backup;
RESTORE VERIFYONLY FROM DISK = @backup WITH CHECKSUM;
RESTORE FILELISTONLY FROM DISK = @backup;

DECLARE @data_file_exists INT = (
    SELECT file_exists FROM sys.dm_os_file_exists(@target_data)
);
DECLARE @data_parent_exists INT = (
    SELECT parent_directory_exists FROM sys.dm_os_file_exists(@target_data)
);
DECLARE @log_file_exists INT = (
    SELECT file_exists FROM sys.dm_os_file_exists(@target_log)
);
DECLARE @log_parent_exists INT = (
    SELECT parent_directory_exists FROM sys.dm_os_file_exists(@target_log)
);

IF @data_file_exists = 1 OR @log_file_exists = 1
    THROW 50905, 'Ya existe un archivo fisico destino; seleccione nombres nuevos.', 1;
IF @data_parent_exists <> 1 OR @log_parent_exists <> 1
    THROW 50906, 'No existe una carpeta destino verificada para MDF o LDF.', 1;

/* RESTORE requiere literales en MOVE; se cita cada valor verificado de forma segura. */
DECLARE @restore_sql NVARCHAR(MAX) =
    N'RESTORE DATABASE [TransportesSegurosSA_Test] FROM DISK = N''' + REPLACE(@backup, N'''', N'''''') + N''' WITH ' +
    N'MOVE N''' + REPLACE(@source_data, N'''', N'''''') + N''' TO N''' + REPLACE(@target_data, N'''', N'''''') + N''', ' +
    N'MOVE N''' + REPLACE(@source_log, N'''', N'''''') + N''' TO N''' + REPLACE(@target_log, N'''', N'''''') + N''', ' +
    N'CHECKSUM, RECOVERY, STATS = 10;';

EXEC sys.sp_executesql @restore_sql;
GO
