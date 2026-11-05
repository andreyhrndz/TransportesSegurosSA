/*
    Transportes Seguros S.A.
    Rollback de contratos operativos.
    Ambiente exclusivo: TransportesSegurosSA_Test
*/

IF DB_NAME() <> N'TransportesSegurosSA_Test'
BEGIN
    RAISERROR('Este script solo debe ejecutarse en TransportesSegurosSA_Test', 20, 1) WITH LOG;
    SET NOEXEC ON;
END
GO

IF OBJECT_ID('dbo.sp_cancelar_viaje', 'P') IS NOT NULL
    DROP PROCEDURE dbo.sp_cancelar_viaje;
GO

DECLARE @permiso_cancelar_id INT = (SELECT permiso_id FROM dbo.permisos WHERE codigo = 'VIAJES_CANCELAR');
IF @permiso_cancelar_id IS NOT NULL
BEGIN
    DELETE FROM dbo.usuarios_permisos WHERE permiso_id = @permiso_cancelar_id;
    DELETE FROM dbo.permisos WHERE permiso_id = @permiso_cancelar_id;
END
GO

IF OBJECT_ID('dbo.seq_codigo_solicitud', 'SO') IS NOT NULL
    DROP SEQUENCE dbo.seq_codigo_solicitud;
GO

IF OBJECT_ID('dbo.seq_codigo_viaje', 'SO') IS NOT NULL
    DROP SEQUENCE dbo.seq_codigo_viaje;
GO
