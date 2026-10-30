-- ====================================================================
-- RESTABLECIMIENTO SEGURO DE CREDENCIALES: admin_proyecto (TEST)
-- ====================================================================
-- Este script copia el hash original e intacto desde la base protegida
-- TransportesSegurosSA hacia TransportesSegurosSA_Test.
--
-- No expone ni modifica contraseñas en texto plano.
-- Requiere ejecución exclusiva en el contexto de TransportesSegurosSA_Test.
-- ====================================================================

USE TransportesSegurosSA_Test;
GO

-- 1. Validar que estamos en la base de pruebas autorizada
IF DB_NAME() <> 'TransportesSegurosSA_Test'
BEGIN
    RAISERROR(N'SEGURIDAD: Este script sólo puede ejecutarse en TransportesSegurosSA_Test.', 16, 1);
    RETURN;
END;
GO

-- 2. Declarar variable y leer el hash original intacto
DECLARE @HashOriginal VARCHAR(255);
DECLARE @ActivoOriginal BIT;

SELECT TOP (1) 
    @HashOriginal = password_hash,
    @ActivoOriginal = activo
FROM TransportesSegurosSA.dbo.usuarios
WHERE usuario_id = 1 AND nombre_usuario = 'admin_proyecto';

IF @HashOriginal IS NULL
BEGIN
    RAISERROR(N'ERROR: No se pudo localizar la cuenta original en TransportesSegurosSA.', 16, 1);
    RETURN;
END;

-- 3. Actualizar la cuenta admin_proyecto en la base de pruebas
UPDATE dbo.usuarios
SET password_hash = @HashOriginal,
    activo = 1
WHERE usuario_id = 1 AND nombre_usuario = 'admin_proyecto';

PRINT N'ÉXITO: Hash de admin_proyecto restablecido en TransportesSegurosSA_Test.';
PRINT N'La base original TransportesSegurosSA no fue modificada.';
GO
