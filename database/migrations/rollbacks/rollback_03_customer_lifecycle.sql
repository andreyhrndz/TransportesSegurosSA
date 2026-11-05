/*
  Reversión de contratos de ciclo de vida.
  No borra clientes, solicitudes, permisos ni asignaciones históricas.
  Revisar dependencias antes de ejecutar, sólo en TransportesSegurosSA_Test.
*/
USE TransportesSegurosSA_Test;
GO

IF DB_NAME() <> N'TransportesSegurosSA_Test'
    THROW 50911, 'Este rollback solo puede ejecutarse en TransportesSegurosSA_Test.', 1;
GO

DROP PROCEDURE IF EXISTS dbo.sp_actualizar_cliente_fisico;
DROP PROCEDURE IF EXISTS dbo.sp_actualizar_cliente_juridico;
DROP PROCEDURE IF EXISTS dbo.sp_desactivar_cliente;
GO

-- Los permisos requieren una revisión manual; este rollback no revoca permisos existentes.
