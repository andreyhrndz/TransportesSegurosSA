/*
  Reversión de contratos de cuentas de usuario en TransportesSegurosSA_Test.
  Elimina únicamente los procedimientos nuevos; no altera sp_conceder_permiso,
  sp_revocar_permiso, cuentas ni asignaciones existentes.
*/
USE TransportesSegurosSA_Test;
GO

IF DB_NAME() <> N'TransportesSegurosSA_Test'
    THROW 50951, 'Este rollback solo puede ejecutarse en TransportesSegurosSA_Test.', 1;
GO

DROP PROCEDURE IF EXISTS dbo.sp_revocar_permiso_seguro;
DROP PROCEDURE IF EXISTS dbo.sp_conceder_permiso_seguro;
DROP PROCEDURE IF EXISTS dbo.sp_restablecer_password;
DROP PROCEDURE IF EXISTS dbo.sp_cambiar_estado_usuario;
DROP PROCEDURE IF EXISTS dbo.sp_actualizar_usuario;
DROP PROCEDURE IF EXISTS dbo.sp_crear_usuario;
GO
