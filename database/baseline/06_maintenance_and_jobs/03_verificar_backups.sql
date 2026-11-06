USE msdb;
GO

SELECT TOP (20)
    b.backup_set_id,
    b.database_name,
    CASE b.type WHEN 'D' THEN 'FULL' WHEN 'I' THEN 'DIFF' END AS Tipo,
    b.backup_finish_date,
    b.checkpoint_lsn,
    b.differential_base_lsn,
    b.has_backup_checksums,
    f.physical_device_name AS Archivo
FROM dbo.backupset b
JOIN dbo.backupmediafamily f ON f.media_set_id = b.media_set_id
WHERE b.database_name = N'TransportesSegurosSA'
  AND b.type IN ('D','I')
ORDER BY b.backup_set_id DESC;
GO
