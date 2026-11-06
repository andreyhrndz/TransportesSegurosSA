/*
  Concede USUARIOS_GESTIONAR_CUENTAS al usuario administrativo de prueba
  exclusivamente en TransportesSegurosSA_Test.
*/
USE TransportesSegurosSA_Test;
GO

IF DB_NAME() <> N'TransportesSegurosSA_Test'
    THROW 50956, 'Este script solo puede ejecutarse en TransportesSegurosSA_Test.', 1;
GO

DECLARE @usuario_id INT = (
    SELECT usuario_id
    FROM dbo.usuarios
    WHERE nombre_usuario = N'admin_proyecto' AND activo = 1
);

IF @usuario_id IS NULL
    THROW 50957, 'No se encontro el usuario de prueba activo esperado.', 1;

INSERT INTO dbo.usuarios_permisos (usuario_id, permiso_id)
SELECT @usuario_id, p.permiso_id
FROM dbo.permisos AS p
WHERE p.codigo IN (N'USUARIOS_VER', N'USUARIOS_GESTIONAR_CUENTAS', N'USUARIOS_GESTIONAR_PERMISOS')
  AND p.activo = 1
  AND NOT EXISTS (
      SELECT 1
      FROM dbo.usuarios_permisos AS up
      WHERE up.usuario_id = @usuario_id AND up.permiso_id = p.permiso_id
  );
GO
