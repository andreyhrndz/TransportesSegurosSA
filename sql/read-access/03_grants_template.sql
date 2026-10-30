/*
  Plantilla de mínimo privilegio para un DBA.
  No ejecutar hasta sustituir el principal y revisar los objetos desplegados.
  Los procedimientos conservan la autorización funcional con SESSION_CONTEXT;
  este GRANT sólo permite a la identidad de aplicación invocarlos.
*/
USE TransportesSegurosSA_Test;
GO

IF DB_NAME() <> N'TransportesSegurosSA_Test'
    THROW 50922, 'Los grants de prueba solo pueden ejecutarse en TransportesSegurosSA_Test.', 1;
GO

DECLARE @principal SYSNAME = N'REPLACE_WITH_APPLICATION_DATABASE_PRINCIPAL';
IF @principal = N'REPLACE_WITH_APPLICATION_DATABASE_PRINCIPAL'
    THROW 50104, 'Defina el principal de base de datos antes de conceder EXECUTE.', 1;
IF DATABASE_PRINCIPAL_ID(@principal) IS NULL
    THROW 50104, 'El principal indicado no existe en esta base de datos.', 1;

DECLARE @sql NVARCHAR(MAX) = N'GRANT EXECUTE ON OBJECT::dbo.sp_listar_clientes TO ' + QUOTENAME(@principal)
    + N'; GRANT EXECUTE ON OBJECT::dbo.sp_obtener_cliente TO ' + QUOTENAME(@principal)
    + N'; GRANT EXECUTE ON OBJECT::dbo.sp_listar_ubicaciones TO ' + QUOTENAME(@principal)
    + N'; GRANT EXECUTE ON OBJECT::dbo.sp_listar_tipos_mercancia TO ' + QUOTENAME(@principal)
    + N'; GRANT EXECUTE ON OBJECT::dbo.sp_listar_solicitudes TO ' + QUOTENAME(@principal)
    + N'; GRANT EXECUTE ON OBJECT::dbo.sp_obtener_solicitud TO ' + QUOTENAME(@principal)
    + N'; GRANT EXECUTE ON OBJECT::dbo.sp_listar_notificaciones TO ' + QUOTENAME(@principal)
    + N'; GRANT EXECUTE ON OBJECT::dbo.sp_listar_usuarios TO ' + QUOTENAME(@principal)
    + N'; GRANT EXECUTE ON OBJECT::dbo.sp_obtener_usuario TO ' + QUOTENAME(@principal)
    + N'; GRANT EXECUTE ON OBJECT::dbo.sp_listar_permisos TO ' + QUOTENAME(@principal)
    + N'; GRANT EXECUTE ON OBJECT::dbo.sp_listar_vehiculos TO ' + QUOTENAME(@principal)
    + N'; GRANT EXECUTE ON OBJECT::dbo.sp_listar_conductores TO ' + QUOTENAME(@principal)
    + N'; GRANT EXECUTE ON OBJECT::dbo.sp_listar_rutas TO ' + QUOTENAME(@principal)
    + N'; GRANT EXECUTE ON OBJECT::dbo.sp_listar_tipos_incidencia TO ' + QUOTENAME(@principal)
    + N'; GRANT EXECUTE ON OBJECT::dbo.sp_listar_tipos_documento TO ' + QUOTENAME(@principal)
    + N'; GRANT EXECUTE ON OBJECT::dbo.sp_listar_viajes TO ' + QUOTENAME(@principal)
    + N'; GRANT EXECUTE ON OBJECT::dbo.sp_obtener_viaje TO ' + QUOTENAME(@principal)
    + N'; GRANT EXECUTE ON OBJECT::dbo.sp_listar_entregas TO ' + QUOTENAME(@principal)
    + N'; GRANT EXECUTE ON OBJECT::dbo.sp_listar_incidencias TO ' + QUOTENAME(@principal)
    + N'; GRANT EXECUTE ON OBJECT::dbo.sp_listar_documentos TO ' + QUOTENAME(@principal)
    + N'; GRANT EXECUTE ON OBJECT::dbo.sp_listar_auditorias TO ' + QUOTENAME(@principal) + N';';
EXEC sys.sp_executesql @sql;
GO
