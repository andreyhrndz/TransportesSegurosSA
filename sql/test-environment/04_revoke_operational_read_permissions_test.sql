/* Reversión del acceso de lectura de módulos para la copia aislada. */
USE TransportesSegurosSA_Test;
GO

IF DB_NAME() <> N'TransportesSegurosSA_Test'
    THROW 50942, 'Este script solo puede ejecutarse en TransportesSegurosSA_Test.', 1;
GO

DELETE up
FROM dbo.usuarios_permisos AS up
JOIN dbo.usuarios AS u ON u.usuario_id = up.usuario_id
JOIN dbo.permisos AS p ON p.permiso_id = up.permiso_id
WHERE u.nombre_usuario = N'admin_proyecto'
  AND p.codigo IN (
      N'SOLICITUDES_VER', N'VIAJES_VER', N'ENTREGAS_VER', N'INCIDENCIAS_VER',
      N'DOCUMENTOS_VER', N'NOTIFICACIONES_VER', N'FLOTAS_VER', N'RUTAS_VER',
      N'UBICACIONES_VER', N'CATALOGOS_VER'
  );
GO
