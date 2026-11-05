/*
  Prueba de permisos de minimo privilegio para la copia aislada.
  Crea dos identidades efimeras dentro de una transaccion y siempre revierte
  los datos. No usa un administrador como sustituto de la autorizacion.
*/
USE TransportesSegurosSA_Test;
GO

IF DB_NAME() <> N'TransportesSegurosSA_Test'
    THROW 50924, 'La prueba solo puede ejecutarse en TransportesSegurosSA_Test.', 1;
GO

SET XACT_ABORT ON;
GO

BEGIN TRANSACTION;
BEGIN TRY
    DECLARE @authorized_user_id INT;
    DECLARE @denied_user_id INT;
    DECLARE @clientes_ver_id INT = (
        SELECT permiso_id FROM dbo.permisos WHERE codigo = N'CLIENTES_VER'
    );
    DECLARE @total INT;

    IF @clientes_ver_id IS NULL
        THROW 50925, 'CLIENTES_VER no esta desplegado en la base de pruebas.', 1;

    /* password_hash no se utiliza: estas identidades no pueden iniciar sesion. */
    INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash, activo)
    VALUES (N'__sql_read_auth_test', N'__sql_read_auth_test@example.test', N'not-usable-for-login', 1);
    SET @authorized_user_id = CONVERT(INT, SCOPE_IDENTITY());

    INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash, activo)
    VALUES (N'__sql_read_denied_test', N'__sql_read_denied_test@example.test', N'not-usable-for-login', 1);
    SET @denied_user_id = CONVERT(INT, SCOPE_IDENTITY());

    INSERT INTO dbo.usuarios_permisos (usuario_id, permiso_id)
    VALUES (@authorized_user_id, @clientes_ver_id);

    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @authorized_user_id;
    EXEC dbo.sp_listar_clientes @pagina = 1, @por_pagina = 20, @total_registros = @total OUTPUT;

    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @denied_user_id;
    BEGIN TRY
        EXEC dbo.sp_listar_clientes @pagina = 1, @por_pagina = 20, @total_registros = @total OUTPUT;
        THROW 50926, 'La identidad sin permiso no fue denegada.', 1;
    END TRY
    BEGIN CATCH
        IF ERROR_NUMBER() <> 50103 THROW;
    END CATCH;

    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @authorized_user_id;
    BEGIN TRY
        EXEC dbo.sp_listar_clientes @pagina = 0, @por_pagina = 20, @total_registros = @total OUTPUT;
        THROW 50927, 'La paginacion invalida no fue denegada.', 1;
    END TRY
    BEGIN CATCH
        IF ERROR_NUMBER() <> 50102 THROW;
    END CATCH;

    ROLLBACK TRANSACTION;
    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = NULL;
    PRINT N'PASS: autorizacion y validacion verificadas con identidades efimeras.';
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = NULL;
    THROW;
END CATCH;
GO
