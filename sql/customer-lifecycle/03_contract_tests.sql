/*
  Pruebas reversibles de sql-customer-lifecycle-contracts.
  Ejecutar solo DESPUES de aprobacion de producto/DBA, despliegue en la base
  aislada y seleccion de usuarios reales con los permisos individuales pedidos.
  Todos los cambios de datos se revierten con ROLLBACK.
*/
USE TransportesSegurosSA_Test;
GO

IF DB_NAME() <> N'TransportesSegurosSA_Test'
    THROW 50912, 'Las pruebas solo pueden ejecutarse en TransportesSegurosSA_Test.', 1;
GO

DECLARE @authorized_user_id INT = NULL;   -- Reemplazar por usuario con CLIENTES_EDITAR.
DECLARE @unauthorized_user_id INT = NULL; -- Reemplazar por usuario sin CLIENTES_EDITAR.
DECLARE @physical_client_id INT = NULL;   -- Reemplazar por cliente fisico activo de prueba.
DECLARE @juridical_client_id INT = NULL;  -- Reemplazar por cliente juridico activo de prueba.

IF @authorized_user_id IS NULL OR @unauthorized_user_id IS NULL
   OR @physical_client_id IS NULL OR @juridical_client_id IS NULL
    THROW 50913, 'Defina usuarios y clientes de prueba verificados antes de ejecutar.', 1;

IF dbo.fn_tiene_permiso(@authorized_user_id, N'CLIENTES_EDITAR') <> 1
    THROW 50914, 'El usuario autorizado no tiene CLIENTES_EDITAR.', 1;
IF dbo.fn_tiene_permiso(@unauthorized_user_id, N'CLIENTES_EDITAR') <> 0
    THROW 50915, 'El usuario no autorizado conserva CLIENTES_EDITAR.', 1;

BEGIN TRANSACTION;
BEGIN TRY
    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @authorized_user_id;
    EXEC dbo.sp_actualizar_cliente_fisico
        @cliente_id = @physical_client_id,
        @correo = N'prueba.ciclo@example.test',
        @telefono = NULL,
        @direccion = NULL,
        @nombre = N'Prueba',
        @primer_apellido = N'Contrato',
        @segundo_apellido = NULL;

    BEGIN TRY
        EXEC dbo.sp_actualizar_cliente_fisico
            @cliente_id = @physical_client_id,
            @correo = N'correo-invalido',
            @telefono = NULL,
            @direccion = NULL,
            @nombre = N'Prueba',
            @primer_apellido = N'Contrato',
            @segundo_apellido = NULL;
        THROW 50916, 'Se esperaba el error de validacion 50219.', 1;
    END TRY
    BEGIN CATCH
        IF ERROR_NUMBER() <> 50219 THROW;
    END CATCH;

    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @unauthorized_user_id;
    BEGIN TRY
        EXEC dbo.sp_actualizar_cliente_juridico
            @cliente_id = @juridical_client_id,
            @correo = N'prueba.juridica@example.test',
            @telefono = NULL,
            @direccion = NULL,
            @razon_social = N'Prueba juridica',
            @nombre_comercial = NULL,
            @representante_legal = NULL;
        THROW 50917, 'Se esperaba el error de permiso 50216.', 1;
    END TRY
    BEGIN CATCH
        IF ERROR_NUMBER() <> 50216 THROW;
    END CATCH;

    ROLLBACK TRANSACTION;
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO
