/*
  Pruebas de solo lectura para una base AISLADA tras desplegar 01 y 02.
  No crear usuarios ni conceder permisos aquí. El DBA debe sustituir el valor
  de @usuario_autorizado por una cuenta de prueba ya preparada con los *_VER.
  No ejecutar en la base operativa.
*/
USE TransportesSegurosSA_Test;
GO

IF DB_NAME() <> N'TransportesSegurosSA_Test'
    THROW 50923, 'Las pruebas solo pueden ejecutarse en TransportesSegurosSA_Test.', 1;
GO

SET NOCOUNT ON;
GO

/* Un contexto ausente siempre debe ser denegado antes de devolver filas. */
EXEC sys.sp_set_session_context @key = N'usuario_id', @value = NULL;
BEGIN TRY
    DECLARE @total_denegado INT;
    EXEC dbo.sp_listar_clientes @pagina = 1, @por_pagina = 20, @total_registros = @total_denegado OUTPUT;
    THROW 50105, 'Fallo: la lectura sin contexto no fue denegada.', 1;
END TRY
BEGIN CATCH
    IF ERROR_NUMBER() <> 50103 THROW;
END CATCH;
GO

DECLARE @usuario_autorizado INT = 0; /* Sustituir por un usuario activo con todos los *_VER. */
IF @usuario_autorizado < 1
    THROW 50104, 'Defina @usuario_autorizado con una cuenta aislada autorizada.', 1;

EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @usuario_autorizado;
DECLARE @total INT;

/* Paginación y filtros válidos: no deben escribir ni exponer password_hash. */
EXEC dbo.sp_listar_clientes @buscar = NULL, @solo_activos = NULL, @pagina = 1, @por_pagina = 20, @total_registros = @total OUTPUT;
EXEC dbo.sp_listar_ubicaciones @solo_activos = 1, @pagina = 1, @por_pagina = 50, @total_registros = @total OUTPUT;
EXEC dbo.sp_listar_tipos_mercancia @solo_activos = 1;
EXEC dbo.sp_listar_solicitudes @pagina = 1, @por_pagina = 20, @total_registros = @total OUTPUT;
EXEC dbo.sp_listar_notificaciones @pagina = 1, @por_pagina = 20, @total_registros = @total OUTPUT;
EXEC dbo.sp_listar_usuarios @pagina = 1, @por_pagina = 20, @total_registros = @total OUTPUT;
EXEC dbo.sp_listar_permisos @solo_activos = NULL;
EXEC dbo.sp_listar_vehiculos @pagina = 1, @por_pagina = 20, @total_registros = @total OUTPUT;
EXEC dbo.sp_listar_conductores @pagina = 1, @por_pagina = 20, @total_registros = @total OUTPUT;
EXEC dbo.sp_listar_rutas @pagina = 1, @por_pagina = 20, @total_registros = @total OUTPUT;
EXEC dbo.sp_listar_tipos_incidencia @solo_activos = 1;
EXEC dbo.sp_listar_tipos_documento @solo_activos = 1;
EXEC dbo.sp_listar_viajes @pagina = 1, @por_pagina = 20, @total_registros = @total OUTPUT;
EXEC dbo.sp_listar_entregas @pagina = 1, @por_pagina = 20, @total_registros = @total OUTPUT;
EXEC dbo.sp_listar_incidencias @pagina = 1, @por_pagina = 20, @total_registros = @total OUTPUT;
EXEC dbo.sp_listar_auditorias @pagina = 1, @por_pagina = 20, @total_registros = @total OUTPUT;

/* Límite de paginación: el contrato debe rechazarlo antes de consultar. */
BEGIN TRY
    EXEC dbo.sp_listar_clientes @pagina = 0, @por_pagina = 20, @total_registros = @total OUTPUT;
    THROW 50105, 'Fallo: la paginación inválida no fue denegada.', 1;
END TRY
BEGIN CATCH
    IF ERROR_NUMBER() <> 50102 THROW;
END CATCH;

EXEC sys.sp_set_session_context @key = N'usuario_id', @value = NULL;
PRINT N'PASS: contratos de lectura verificados en contexto aislado.';
GO
