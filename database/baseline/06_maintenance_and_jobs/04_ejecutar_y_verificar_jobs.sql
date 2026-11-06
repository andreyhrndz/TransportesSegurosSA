USE msdb;
GO

-- Solo VERIFICA los trabajos.
-- La ejecucion manual se solicita con UNA llamada como esta:
-- EXEC dbo.sp_start_job @job_name = N'G6_ETL_TransportesSegurosSA';
-- Descomenta UNA llamada por vez y verifica el resultado antes de otra.
-- Para BACKUP: ejecutar primero FULL y despues DIFF.

SELECT
    j.name AS Trabajo,
    j.enabled AS Habilitado,
    s.name AS Horario,
    s.freq_type,
    s.freq_interval,
    s.active_start_time AS Hora
FROM dbo.sysjobs j
JOIN dbo.sysjobschedules js ON js.job_id = j.job_id
JOIN dbo.sysschedules s ON s.schedule_id = js.schedule_id
WHERE j.name LIKE N'G6_%TransportesSegurosSA'
ORDER BY j.name;

SELECT TOP (20)
    j.name AS Trabajo,
    h.step_id,
    CASE h.run_status WHEN 1 THEN 'EXITO' WHEN 0 THEN 'ERROR'
                      WHEN 2 THEN 'REINTENTO' ELSE 'OTRO' END AS Resultado,
    h.run_date,
    h.run_time,
    h.message
FROM dbo.sysjobhistory h
JOIN dbo.sysjobs j ON j.job_id = h.job_id
WHERE j.name LIKE N'G6_%TransportesSegurosSA'
ORDER BY h.instance_id DESC;
GO
