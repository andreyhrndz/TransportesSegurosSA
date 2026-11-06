USE msdb;
GO

-- ETL diario a las 02:00.
-- Requiere SQL Server Agent encendido y privilegios para crear Jobs.

IF EXISTS (SELECT 1 FROM dbo.sysjobs WHERE name = N'G6_ETL_TransportesSegurosSA')
THROW 50901, 'Este Job ya existe. No se duplica.', 1;

IF EXISTS (SELECT 1 FROM dbo.sysschedules WHERE name = N'G6_ETL_Diario_0200')
THROW 50902, 'Este horario ya existe.', 1;

DECLARE @job UNIQUEIDENTIFIER;
DECLARE @horario INT;
DECLARE @fecha_inicio INT = CONVERT(INT, CONVERT(CHAR(8), GETDATE(), 112));

DECLARE @comando NVARCHAR(MAX) = N'EXEC dbo.sp_cargar_datawarehouse;';

EXEC dbo.sp_add_job
@job_name = N'G6_ETL_TransportesSegurosSA',
@enabled = 1,
@description = N'ETL diario a las 02:00.',
@job_id = @job OUTPUT;

EXEC dbo.sp_add_jobstep
@job_id = @job,
@step_name = N'Ejecutar proceso',
@subsystem = N'TSQL',
@database_name = N'TransportesSegurosSA_DW',
@command = @comando,
@on_success_action = 1,
@on_fail_action = 2,
@retry_attempts = 1,
@retry_interval = 5;

EXEC dbo.sp_add_schedule
@schedule_name = N'G6_ETL_Diario_0200',
@enabled = 1,
@freq_type = 4,
@freq_interval = 1,
@freq_recurrence_factor = 1,
@active_start_date = @fecha_inicio,
@active_start_time = 20000,
@schedule_id = @horario OUTPUT;

EXEC dbo.sp_attach_schedule
@job_id = @job,
@schedule_id = @horario;

EXEC dbo.sp_add_jobserver
@job_id = @job;

SELECT name, enabled FROM dbo.sysjobs
WHERE name = N'G6_ETL_TransportesSegurosSA';
GO
