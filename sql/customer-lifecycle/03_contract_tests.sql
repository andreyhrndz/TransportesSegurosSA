/*
  Pruebas contractuales autónomas y reversibles de sql-customer-lifecycle-contracts.
  Ejecutar exclusivamente en TransportesSegurosSA_Test.
  Crea actores y clientes efímeros dentro de una transacción y revierte todo al finalizar.
*/
USE TransportesSegurosSA_Test;
GO

IF DB_NAME() <> N'TransportesSegurosSA_Test'
    THROW 50912, 'Las pruebas solo pueden ejecutarse en TransportesSegurosSA_Test.', 1;
GO

BEGIN TRANSACTION;
BEGIN TRY
    DECLARE @auth_user_id INT;
    DECLARE @unauth_user_id INT;
    DECLARE @physical_client_id INT;
    DECLARE @juridical_client_id INT;
    DECLARE @origen_id INT;
    DECLARE @destino_id INT;
    DECLARE @solicitud_activa_id INT;
    DECLARE @solicitud_terminal_id INT;
    DECLARE @auditorias_antes INT;
    DECLARE @auditorias_despues INT;

    INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash, activo)
    VALUES (N'__cust_life_auth', N'__cust_life_auth@example.test', N'$2y$12$012345678901234567890u1234567890123456789012345678901', 1);
    SET @auth_user_id = CONVERT(INT, SCOPE_IDENTITY());

    INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash, activo)
    VALUES (N'__cust_life_unauth', N'__cust_life_unauth@example.test', N'$2y$12$012345678901234567890u1234567890123456789012345678901', 1);
    SET @unauth_user_id = CONVERT(INT, SCOPE_IDENTITY());

    INSERT INTO dbo.usuarios_permisos (usuario_id, permiso_id)
    SELECT @auth_user_id, permiso_id
    FROM dbo.permisos
    WHERE codigo IN (N'CLIENTES_EDITAR', N'CLIENTES_DESACTIVAR');

    IF @@ROWCOUNT <> 2
        THROW 50913, 'Faltan permisos CLIENTES_EDITAR o CLIENTES_DESACTIVAR en Test.', 1;

    INSERT INTO dbo.clientes (identificacion, correo, telefono, direccion, activo)
    VALUES (N'__CL_FIS_001', N'fisico.inicial@example.test', N'8888-0001', N'Dirección inicial', 1);
    SET @physical_client_id = CONVERT(INT, SCOPE_IDENTITY());
    INSERT INTO dbo.clientes_fisicos (cliente_id, nombre, primer_apellido, segundo_apellido)
    VALUES (@physical_client_id, N'Ana', N'Mora', N'Solis');

    INSERT INTO dbo.clientes (identificacion, correo, telefono, direccion, activo)
    VALUES (N'__CL_JUR_001', N'juridico.inicial@example.test', N'2222-0001', N'Zona Industrial', 1);
    SET @juridical_client_id = CONVERT(INT, SCOPE_IDENTITY());
    INSERT INTO dbo.clientes_juridicos (cliente_id, razon_social, nombre_comercial, representante_legal)
    VALUES (@juridical_client_id, N'Comercial Prueba S.A.', N'Comercial Prueba', N'Carlos Vega');

    SELECT @auditorias_antes = COUNT(*)
    FROM dbo.auditorias
    WHERE entidad = N'clientes'
      AND registro_id IN (CONVERT(NVARCHAR(100), @physical_client_id), CONVERT(NVARCHAR(100), @juridical_client_id));

    -- 1. Edición válida de cliente físico y jurídico con actor autorizado
    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @auth_user_id;

    EXEC dbo.sp_actualizar_cliente_fisico
        @cliente_id = @physical_client_id,
        @correo = N'fisico.editado@example.test',
        @telefono = N'8888-9999',
        @direccion = N'San José, Sabana Norte',
        @nombre = N'Ana María',
        @primer_apellido = N'Mora',
        @segundo_apellido = N'Solís';

    IF NOT EXISTS (
        SELECT 1
        FROM dbo.clientes AS c
        JOIN dbo.clientes_fisicos AS f ON f.cliente_id = c.cliente_id
        WHERE c.cliente_id = @physical_client_id
          AND c.identificacion = N'__CL_FIS_001'
          AND c.correo = N'fisico.editado@example.test'
          AND c.direccion = N'San José, Sabana Norte'
          AND f.nombre = N'Ana María'
    )
        THROW 50914, 'La edición de cliente físico no persistió los cambios esperados.', 1;

    EXEC dbo.sp_actualizar_cliente_juridico
        @cliente_id = @juridical_client_id,
        @correo = N'juridico.editado@example.test',
        @telefono = N'2222-9999',
        @direccion = N'Heredia, Parque Empresarial',
        @razon_social = N'Comercial Prueba Actualizada S.A.',
        @nombre_comercial = N'Prueba Express',
        @representante_legal = N'Laura Méndez';

    IF NOT EXISTS (
        SELECT 1
        FROM dbo.clientes AS c
        JOIN dbo.clientes_juridicos AS j ON j.cliente_id = c.cliente_id
        WHERE c.cliente_id = @juridical_client_id
          AND c.identificacion = N'__CL_JUR_001'
          AND c.correo = N'juridico.editado@example.test'
          AND j.razon_social = N'Comercial Prueba Actualizada S.A.'
    )
        THROW 50915, 'La edición de cliente jurídico no persistió los cambios esperados.', 1;

    -- 2. Validación de correo inválido -> 50219
    BEGIN TRY
        EXEC dbo.sp_actualizar_cliente_fisico
            @cliente_id = @physical_client_id,
            @correo = N'correo-invalido',
            @telefono = NULL,
            @direccion = NULL,
            @nombre = N'Ana',
            @primer_apellido = N'Mora',
            @segundo_apellido = NULL;
        THROW 50916, 'Se esperaba error 50219 por correo inválido.', 1;
    END TRY
    BEGIN CATCH
        IF ERROR_NUMBER() <> 50219 THROW;
    END CATCH;

    -- 3. Subtipo cruzado e inexistente -> 50218
    BEGIN TRY
        EXEC dbo.sp_actualizar_cliente_juridico
            @cliente_id = @physical_client_id,
            @correo = N'cruzado@example.test',
            @telefono = NULL,
            @direccion = NULL,
            @razon_social = N'Intento Cruzado S.A.',
            @nombre_comercial = NULL,
            @representante_legal = NULL;
        THROW 50916, 'Se esperaba error 50218 por subtipo incompatible.', 1;
    END TRY
    BEGIN CATCH
        IF ERROR_NUMBER() <> 50218 THROW;
    END CATCH;

    BEGIN TRY
        EXEC dbo.sp_actualizar_cliente_fisico
            @cliente_id = 99999999,
            @correo = N'noexiste@example.test',
            @telefono = NULL,
            @direccion = NULL,
            @nombre = N'Nadie',
            @primer_apellido = N'Ninguno',
            @segundo_apellido = NULL;
        THROW 50916, 'Se esperaba error 50218 por cliente inexistente.', 1;
    END TRY
    BEGIN CATCH
        IF ERROR_NUMBER() <> 50218 THROW;
    END CATCH;

    -- 4. Bloqueo de desactivación con solicitud no terminal -> 50220
    INSERT INTO dbo.ubicaciones (nombre, direccion, activo) VALUES (N'__CL_ORIG', N'Dir 1', 1);
    SET @origen_id = CONVERT(INT, SCOPE_IDENTITY());
    INSERT INTO dbo.ubicaciones (nombre, direccion, activo) VALUES (N'__CL_DEST', N'Dir 2', 1);
    SET @destino_id = CONVERT(INT, SCOPE_IDENTITY());

    INSERT INTO dbo.solicitudes
    (
        codigo_solicitud, cliente_id, origen_id, destino_id,
        destinatario_nombre, destinatario_apellido1, destinatario_apellido2,
        fecha_entrega_requerida, prioridad_calculada, prioridad_actual, estado
    )
    VALUES
    (
        N'__SOL_CL_ACTIVA', @physical_client_id, @origen_id, @destino_id,
        N'Dest', N'Uno', N'Dos', DATEADD(DAY, 2, SYSUTCDATETIME()),
        'NORMAL', 'NORMAL', 'REGISTRADA'
    );
    SET @solicitud_activa_id = CONVERT(INT, SCOPE_IDENTITY());

    BEGIN TRY
        EXEC dbo.sp_desactivar_cliente @cliente_id = @physical_client_id;
        THROW 50916, 'Se esperaba error 50220 por solicitud activa.', 1;
    END TRY
    BEGIN CATCH
        IF ERROR_NUMBER() <> 50220 THROW;
    END CATCH;

    -- 5. Desactivación permitida cuando las solicitudes son terminales (CANCELADA)
    UPDATE dbo.solicitudes SET estado = 'CANCELADA' WHERE solicitud_id = @solicitud_activa_id;
    SET @solicitud_terminal_id = @solicitud_activa_id;

    EXEC dbo.sp_desactivar_cliente @cliente_id = @physical_client_id;

    IF NOT EXISTS (SELECT 1 FROM dbo.clientes WHERE cliente_id = @physical_client_id AND activo = 0)
        THROW 50917, 'El cliente físico debía quedar inactivo.', 1;
    IF NOT EXISTS (SELECT 1 FROM dbo.solicitudes WHERE solicitud_id = @solicitud_terminal_id AND estado = 'CANCELADA')
        THROW 50917, 'La solicitud histórica no debía eliminarse ni alterarse.', 1;

    -- 6. Re-desactivación de cliente ya inactivo -> 50221
    BEGIN TRY
        EXEC dbo.sp_desactivar_cliente @cliente_id = @physical_client_id;
        THROW 50916, 'Se esperaba error 50221 por cliente ya inactivo.', 1;
    END TRY
    BEGIN CATCH
        IF ERROR_NUMBER() <> 50221 THROW;
    END CATCH;

    -- 7. Verificar trazabilidad real en dbo.auditorias (3 eventos: 2 ediciones + 1 desactivación)
    SELECT @auditorias_despues = COUNT(*)
    FROM dbo.auditorias
    WHERE entidad = N'clientes'
      AND usuario_id = @auth_user_id
      AND registro_id IN (CONVERT(NVARCHAR(100), @physical_client_id), CONVERT(NVARCHAR(100), @juridical_client_id));

    IF (@auditorias_despues - @auditorias_antes) <> 3
        THROW 50918, 'No se registraron los 3 eventos de auditoría esperados para clientes.', 1;

    -- 8. Permiso insuficiente -> 50216 y 50217
    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @unauth_user_id;
    BEGIN TRY
        EXEC dbo.sp_actualizar_cliente_juridico
            @cliente_id = @juridical_client_id,
            @correo = N'sinpermiso@example.test',
            @telefono = NULL,
            @direccion = NULL,
            @razon_social = N'No Autorizado S.A.',
            @nombre_comercial = NULL,
            @representante_legal = NULL;
        THROW 50916, 'Se esperaba error 50216 por falta de permiso editar.', 1;
    END TRY
    BEGIN CATCH
        IF ERROR_NUMBER() <> 50216 THROW;
    END CATCH;

    BEGIN TRY
        EXEC dbo.sp_desactivar_cliente @cliente_id = @juridical_client_id;
        THROW 50916, 'Se esperaba error 50217 por falta de permiso desactivar.', 1;
    END TRY
    BEGIN CATCH
        IF ERROR_NUMBER() <> 50217 THROW;
    END CATCH;

    ROLLBACK TRANSACTION;
    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = NULL;
    SELECT N'PASS: sql-customer-lifecycle-contracts verificado y revertido en TransportesSegurosSA_Test.' AS resultado;
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = NULL;
    THROW;
END CATCH;
GO
