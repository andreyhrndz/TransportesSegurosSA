/*
  Reversión segura de sql-master-data-contracts en TransportesSegurosSA_Test.
  Elimina únicamente los 21 procedimientos nuevos; no altera tablas, registros ni lecturas.
*/
USE TransportesSegurosSA_Test;
GO

IF DB_NAME() <> N'TransportesSegurosSA_Test'
    THROW 50961, 'Este rollback solo puede ejecutarse en TransportesSegurosSA_Test.', 1;
GO

DROP PROCEDURE IF EXISTS dbo.sp_desactivar_ruta;
DROP PROCEDURE IF EXISTS dbo.sp_actualizar_ruta;
DROP PROCEDURE IF EXISTS dbo.sp_crear_ruta;

DROP PROCEDURE IF EXISTS dbo.sp_desactivar_conductor;
DROP PROCEDURE IF EXISTS dbo.sp_actualizar_conductor;
DROP PROCEDURE IF EXISTS dbo.sp_crear_conductor;

DROP PROCEDURE IF EXISTS dbo.sp_desactivar_vehiculo;
DROP PROCEDURE IF EXISTS dbo.sp_actualizar_vehiculo;
DROP PROCEDURE IF EXISTS dbo.sp_crear_vehiculo;

DROP PROCEDURE IF EXISTS dbo.sp_desactivar_tipo_documento;
DROP PROCEDURE IF EXISTS dbo.sp_actualizar_tipo_documento;
DROP PROCEDURE IF EXISTS dbo.sp_crear_tipo_documento;

DROP PROCEDURE IF EXISTS dbo.sp_desactivar_tipo_incidencia;
DROP PROCEDURE IF EXISTS dbo.sp_actualizar_tipo_incidencia;
DROP PROCEDURE IF EXISTS dbo.sp_crear_tipo_incidencia;

DROP PROCEDURE IF EXISTS dbo.sp_desactivar_tipo_mercancia;
DROP PROCEDURE IF EXISTS dbo.sp_actualizar_tipo_mercancia;
DROP PROCEDURE IF EXISTS dbo.sp_crear_tipo_mercancia;

DROP PROCEDURE IF EXISTS dbo.sp_desactivar_ubicacion;
DROP PROCEDURE IF EXISTS dbo.sp_actualizar_ubicacion;
DROP PROCEDURE IF EXISTS dbo.sp_crear_ubicacion;
GO
