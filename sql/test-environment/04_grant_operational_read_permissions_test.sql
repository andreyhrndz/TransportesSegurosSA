/*
  Permisos mínimos para probar módulos de consulta en la copia aislada.
  No crea usuarios, no modifica contraseñas y no debe ejecutarse fuera de Test.
*/
USE TransportesSegurosSA_Test;
GO

IF DB_NAME() <> N'TransportesSegurosSA_Test'
    THROW 50940, 'Este script solo puede ejecutarse en TransportesSegurosSA_Test.', 1;
GO

DECLARE @usuario_id INT = (
    SELECT usuario_id
    FROM dbo.usuarios
    WHERE nombre_usuario = N'admin_proyecto' AND activo = 1
);

IF @usuario_id IS NULL
    THROW 50941, 'No se encontro el usuario de prueba activo esperado.', 1;

INSERT INTO dbo.usuarios_permisos (usuario_id, permiso_id)
SELECT @usuario_id, p.permiso_id
FROM dbo.permisos AS p
WHERE p.codigo IN (
    N'CLIENTES_VER', N'SOLICITUDES_VER', N'VIAJES_VER', N'ENTREGAS_VER', N'INCIDENCIAS_VER',
    N'DOCUMENTOS_VER', N'NOTIFICACIONES_VER', N'AUDITORIA_VER', N'FLOTAS_VER', N'RUTAS_VER',
    N'UBICACIONES_VER', N'CATALOGOS_VER', N'USUARIOS_VER'
)
AND NOT EXISTS (
    SELECT 1 FROM dbo.usuarios_permisos AS up
    WHERE up.usuario_id = @usuario_id AND up.permiso_id = p.permiso_id
);
GO
