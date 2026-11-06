USE master;
GO

IF NOT EXISTS
(
    SELECT 1 FROM msdb.dbo.backupset
    WHERE database_name = N'TransportesSegurosSA'
      AND type = 'D'
      AND is_copy_only = 0
)
    THROW 50801, 'Antes debe crear un FULL normal.', 1;

DECLARE @archivo NVARCHAR(500);

SET @archivo = N'C:\SQL\Backups\TransportesSegurosSA_DIFF_'
             + CONVERT(CHAR(8), GETDATE(), 112)
             + N'_' + REPLACE(CONVERT(NVARCHAR(36), NEWID()), N'-', N'')
             + N'.bak';

BACKUP DATABASE TransportesSegurosSA
TO DISK = @archivo
WITH DIFFERENTIAL, INIT, COMPRESSION, CHECKSUM, STATS = 10;

RESTORE VERIFYONLY
FROM DISK = @archivo
WITH CHECKSUM;

SELECT 'DIFERENCIAL VERIFICADO' AS Resultado, @archivo AS Archivo;
GO
