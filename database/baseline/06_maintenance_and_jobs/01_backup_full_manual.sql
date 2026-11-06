USE master;
GO

DECLARE @archivo NVARCHAR(500);

SET @archivo = N'C:\SQL\Backups\TransportesSegurosSA_FULL_'
             + CONVERT(CHAR(8), GETDATE(), 112)
             + N'_' + REPLACE(CONVERT(NVARCHAR(36), NEWID()), N'-', N'')
             + N'.bak';

BACKUP DATABASE TransportesSegurosSA
TO DISK = @archivo
WITH INIT, COMPRESSION, CHECKSUM, STATS = 10;

RESTORE VERIFYONLY
FROM DISK = @archivo
WITH CHECKSUM;

SELECT 'FULL VERIFICADO' AS Resultado, @archivo AS Archivo;
GO
