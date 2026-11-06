/* Reversión de USUARIOS_GESTIONAR_CUENTAS en TransportesSegurosSA_Test. */
USE TransportesSegurosSA_Test;
GO

IF DB_NAME() <> N'TransportesSegurosSA_Test'
    THROW 50958, 'Este script solo puede ejecutarse en TransportesSegurosSA_Test.', 1;
GO

DELETE up
FROM dbo.usuarios_permisos AS up
JOIN dbo.usuarios AS u ON u.usuario_id = up.usuario_id
JOIN dbo.permisos AS p ON p.permiso_id = up.permiso_id
WHERE u.nombre_usuario = N'admin_proyecto'
  AND p.codigo = N'USUARIOS_GESTIONAR_CUENTAS';
GO
