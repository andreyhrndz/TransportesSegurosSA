USE msdb;
GO

-- TRANSACTION LOG cada 15 minutos (RPO <= 15 minutos).
-- Requiere base en modelo de recuperacion FULL, un backup FULL previo y SQL Server Agent activo.

IF EXISTS (SELECT 1 FROM dbo.sysjobs WHERE name = N'G6_LOG_15MIN_TransportesSegurosSA')
    THROW 50901, 'Este Job ya existe. No se duplica.', 1;

IF EXISTS (SELECT 1 FROM dbo.sysschedules WHERE name = N'G6_LOG_Cada_15_Minutos')
    THROW 50902, 'Este horario ya existe.', 1;

DECLARE @job UNIQUEIDENTIFIER;
DECLARE @horario INT;
DECLARE @fecha_inicio INT = CONVERT(INT, CONVERT(CHAR(8), GETDATE(), 112));

DECLARE @comando NVARCHAR(MAX) = N'IF NOT EXISTS
(
    SELECT 1 FROM sys.databases
    WHERE name = N''TransportesSegurosSA''
      AND recovery_model_desc = N''FULL''
)
    THROW 50912, ''La base debe estar en modelo de recuperacion FULL para respaldar el Transaction Log.'', 1;

IF NOT EXISTS
(
    SELECT 1 FROM msdb.dbo.backupset
    WHERE database_name = N''TransportesSegurosSA''
      AND type = ''D''
      AND is_copy_only = 0
)
    THROW 50911, ''Primero es necesario un backup FULL base.'', 1;

DECLARE @dir NVARCHAR(400) = CONVERT(NVARCHAR(400), SERVERPROPERTY(''InstanceDefaultBackupPath''));
IF RIGHT(@dir, 1) NOT IN (N''\'', N''/'') SET @dir = @dir + N''\'';

DECLARE @ruta NVARCHAR(500) = @dir
    + N''TransportesSegurosSA_AUTO_LOG_''
    + CONVERT(CHAR(8), GETDATE(), 112)
    + N''_'' + REPLACE(CONVERT(CHAR(8), GETDATE(), 108), N'':'', N'''')
    + N''_'' + LEFT(REPLACE(CONVERT(NVARCHAR(36), NEWID()), N''-'', N''''), 8)
    + N''.trn'';

BACKUP LOG TransportesSegurosSA
TO DISK = @ruta
WITH INIT, COMPRESSION, CHECKSUM, STATS = 10;

RESTORE VERIFYONLY
FROM DISK = @ruta
WITH CHECKSUM;';

EXEC dbo.sp_add_job
    @job_name = N'G6_LOG_15MIN_TransportesSegurosSA',
    @enabled = 1,
    @description = N'Transaction Log cada 15 minutos (.trn) para garantizar RPO <= 15 minutos y truncar el archivo .ldf.',
    @job_id = @job OUTPUT;

EXEC dbo.sp_add_jobstep
    @job_id = @job,
    @step_name = N'Ejecutar respaldo de Transaction Log (15 min)',
    @subsystem = N'TSQL',
    @database_name = N'master',
    @command = @comando,
    @on_success_action = 1,
    @on_fail_action = 2,
    @retry_attempts = 1,
    @retry_interval = 2;

EXEC dbo.sp_add_schedule
    @schedule_name = N'G6_LOG_Cada_15_Minutos',
    @enabled = 1,
    @freq_type = 4,              -- Diario
    @freq_interval = 1,          -- Todos los dias
    @freq_subday_type = 4,       -- Minutos
    @freq_subday_interval = 15,  -- Cada 15 minutos
    @active_start_date = @fecha_inicio,
    @active_start_time = 0,
    @active_end_time = 235959,
    @schedule_id = @horario OUTPUT;

EXEC dbo.sp_attach_schedule
    @job_id = @job,
    @schedule_id = @horario;

EXEC dbo.sp_add_jobserver
    @job_id = @job;

SELECT name, enabled FROM dbo.sysjobs
WHERE name = N'G6_LOG_15MIN_TransportesSegurosSA';
GO
