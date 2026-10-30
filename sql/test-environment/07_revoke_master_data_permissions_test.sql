/*
  Revoca permisos individuales de gestión de datos maestros y flota
  del usuario administrativo de prueba en TransportesSegurosSA_Test.
*/
USE TransportesSegurosSA_Test;
GO

IF DB_NAME() <> N'TransportesSegurosSA_Test'
    THROW 50964, 'Este script solo puede ejecutarse en TransportesSegurosSA_Test.', 1;
GO

DELETE up
FROM dbo.usuarios_permisos AS up
JOIN dbo.usuarios AS u ON u.usuario_id = up.usuario_id
JOIN dbo.permisos AS p ON p.permiso_id = up.permiso_id
WHERE u.nombre_usuario = N'admin_proyecto'
  AND p.codigo IN (
      N'UBICACIONES_GESTIONAR',
      N'CATALOGOS_GESTIONAR',
      N'FLOTAS_GESTIONAR',
      N'RUTAS_GESTIONAR'
  );
GO
