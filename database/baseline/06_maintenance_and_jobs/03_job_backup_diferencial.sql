USE msdb;
GO

-- DIFERENCIAL lunes a sabado a las 01:00.
-- Requiere SQL Server Agent encendido y privilegios para crear Jobs.

IF EXISTS (SELECT 1 FROM dbo.sysjobs WHERE name = N'G6_DIFF_TransportesSegurosSA')
    THROW 50901, 'Este Job ya existe. No se duplica.', 1;

IF EXISTS (SELECT 1 FROM dbo.sysschedules WHERE name = N'G6_DIFF_LunesSabado_0100')
    THROW 50902, 'Este horario ya existe.', 1;

DECLARE @job UNIQUEIDENTIFIER;
DECLARE @horario INT;
DECLARE @fecha_inicio INT = CONVERT(INT, CONVERT(CHAR(8), GETDATE(), 112));

DECLARE @comando NVARCHAR(MAX) = N'IF NOT EXISTS
(
SELECT 1 FROM msdb.dbo.backupset
WHERE database_name = N''TransportesSegurosSA''
  AND type = ''D''
  AND is_copy_only = 0
)
THROW 50911, ''Primero es necesario un FULL.'', 1;

DECLARE @ruta NVARCHAR(500);

SET @ruta = N''C:\SQL\Backups\TransportesSegurosSA_AUTO_DIFF_''
        + CONVERT(CHAR(8), GETDATE(), 112)
        + N''_'' + REPLACE(CONVERT(NVARCHAR(36), NEWID()), N''-'', N'''')
        + N''.bak'';

BACKUP DATABASE TransportesSegurosSA
TO DISK = @ruta
WITH DIFFERENTIAL, INIT, COMPRESSION, CHECKSUM, STATS = 10;

RESTORE VERIFYONLY
FROM DISK = @ruta
WITH CHECKSUM;';

EXEC dbo.sp_add_job
    @job_name = N'G6_DIFF_TransportesSegurosSA',
    @enabled = 1,
    @description = N'DIFERENCIAL lunes a sabado a las 01:00.',
    @job_id = @job OUTPUT;

EXEC dbo.sp_add_jobstep
    @job_id = @job,
    @step_name = N'Ejecutar proceso',
    @subsystem = N'TSQL',
    @database_name = N'master',
    @command = @comando,
    @on_success_action = 1,
    @on_fail_action = 2,
    @retry_attempts = 1,
    @retry_interval = 5;

EXEC dbo.sp_add_schedule
    @schedule_name = N'G6_DIFF_LunesSabado_0100',
    @enabled = 1,
    @freq_type = 8,
    @freq_interval = 126,
    @freq_recurrence_factor = 1,
    @active_start_date = @fecha_inicio,
    @active_start_time = 10000,
    @schedule_id = @horario OUTPUT;

EXEC dbo.sp_attach_schedule
    @job_id = @job,
    @schedule_id = @horario;

EXEC dbo.sp_add_jobserver
    @job_id = @job;

SELECT name, enabled FROM dbo.sysjobs
WHERE name = N'G6_DIFF_TransportesSegurosSA';
GO
