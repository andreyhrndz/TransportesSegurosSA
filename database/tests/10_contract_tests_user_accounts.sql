/*
  Pruebas contractuales autónomas y reversibles de sql-user-account-contracts.
  Ejecutar exclusivamente en TransportesSegurosSA_Test.
*/
USE TransportesSegurosSA_Test;
GO

IF DB_NAME() <> N'TransportesSegurosSA_Test'
    THROW 50952, 'Las pruebas solo pueden ejecutarse en TransportesSegurosSA_Test.', 1;
GO

BEGIN TRANSACTION;
BEGIN TRY
    DECLARE @gestor_a INT;
    DECLARE @gestor_b INT;
    DECLARE @operador_sin_permiso INT;
    DECLARE @nuevo_usuario_id INT;
    DECLARE @hash_valido NVARCHAR(255) = N'$2y$12$012345678901234567890u1234567890123456789012345678901';
    DECLARE @hash_nuevo NVARCHAR(255)  = N'$2y$12$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234';

    -- Aislar el conteo de gestores desactivando temporalmente gestores existentes dentro de la transacción
    UPDATE dbo.usuarios SET activo = 0 WHERE activo = 1;

    INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash, activo)
    VALUES (N'__usr_gestor_a', N'__usr_gestor_a@example.test', @hash_valido, 1);
    SET @gestor_a = CONVERT(INT, SCOPE_IDENTITY());

    INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash, activo)
    VALUES (N'__usr_gestor_b', N'__usr_gestor_b@example.test', @hash_valido, 1);
    SET @gestor_b = CONVERT(INT, SCOPE_IDENTITY());

    INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash, activo)
    VALUES (N'__usr_sin_perm', N'__usr_sin_perm@example.test', @hash_valido, 1);
    SET @operador_sin_permiso = CONVERT(INT, SCOPE_IDENTITY());

    -- Gestor A tiene USUARIOS_VER, USUARIOS_GESTIONAR_CUENTAS y USUARIOS_GESTIONAR_PERMISOS
    INSERT INTO dbo.usuarios_permisos (usuario_id, permiso_id)
    SELECT @gestor_a, permiso_id
    FROM dbo.permisos
    WHERE codigo IN (N'USUARIOS_VER', N'USUARIOS_GESTIONAR_CUENTAS', N'USUARIOS_GESTIONAR_PERMISOS');

    -- Gestor B tiene inicialmente solo USUARIOS_GESTIONAR_CUENTAS y USUARIOS_VER (por tanto A es el ÚNICO con USUARIOS_GESTIONAR_PERMISOS)
    INSERT INTO dbo.usuarios_permisos (usuario_id, permiso_id)
    SELECT @gestor_b, permiso_id
    FROM dbo.permisos
    WHERE codigo IN (N'USUARIOS_VER', N'USUARIOS_GESTIONAR_CUENTAS');

    -- 1. Actor sin permiso -> 50412
    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @operador_sin_permiso;
    BEGIN TRY
        EXEC dbo.sp_crear_usuario
            @nombre_usuario = N'__usr_denegado',
            @correo_acceso = N'__usr_denegado@example.test',
            @password_hash = @hash_valido,
            @usuario_id = @nuevo_usuario_id OUTPUT;
        THROW 50953, 'Se esperaba error 50412 por permiso insuficiente.', 1;
    END TRY
    BEGIN CATCH
        IF ERROR_NUMBER() <> 50412 THROW;
    END CATCH;

    -- 2. Alta válida con Gestor A
    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @gestor_a;
    EXEC dbo.sp_crear_usuario
        @nombre_usuario = N'__usr_creado',
        @correo_acceso = N'__usr_creado@example.test',
        @password_hash = @hash_valido,
        @usuario_id = @nuevo_usuario_id OUTPUT;

    IF @nuevo_usuario_id IS NULL OR @nuevo_usuario_id < 1
        THROW 50954, 'sp_crear_usuario no devolvió usuario_id.', 1;

    -- 3. Duplicado de nombre o correo -> 50416
    BEGIN TRY
        DECLARE @dup_id INT;
        EXEC dbo.sp_crear_usuario
            @nombre_usuario = N'__usr_creado',
            @correo_acceso = N'otro.correo@example.test',
            @password_hash = @hash_valido,
            @usuario_id = @dup_id OUTPUT;
        THROW 50953, 'Se esperaba error 50416 por usuario duplicado.', 1;
    END TRY
    BEGIN CATCH
        IF ERROR_NUMBER() <> 50416 THROW;
    END CATCH;

    -- 4. Hash en texto plano o inválido -> 50414
    BEGIN TRY
        EXEC dbo.sp_restablecer_password
            @usuario_id = @nuevo_usuario_id,
            @password_hash = N'TextoPlanoInseguro123!';
        THROW 50953, 'Se esperaba error 50414 por hash no bcrypt.', 1;
    END TRY
    BEGIN CATCH
        IF ERROR_NUMBER() <> 50414 THROW;
    END CATCH;

    -- 5. Edición y restablecimiento válidos + usuario inexistente (50415)
    EXEC dbo.sp_actualizar_usuario
        @usuario_id = @nuevo_usuario_id,
        @nombre_usuario = N'__usr_editado',
        @correo_acceso = N'__usr_editado@example.test';

    EXEC dbo.sp_restablecer_password
        @usuario_id = @nuevo_usuario_id,
        @password_hash = @hash_nuevo;

    IF EXISTS (
        SELECT 1
        FROM dbo.auditorias
        WHERE entidad = N'usuarios'
          AND (valores_anteriores LIKE N'%$2y$%' OR valores_nuevos LIKE N'%$2y$%')
    )
        THROW 50955, 'CRITICO: la auditoría nunca debe almacenar hashes bcrypt.', 1;

    BEGIN TRY
        EXEC dbo.sp_actualizar_usuario
            @usuario_id = 99999999,
            @nombre_usuario = N'__no_existe',
            @correo_acceso = N'noexiste@example.test';
        THROW 50953, 'Se esperaba error 50415 por usuario inexistente.', 1;
    END TRY
    BEGIN CATCH
        IF ERROR_NUMBER() <> 50415 THROW;
    END CATCH;

    -- 6. Auto-desactivación prohibida -> 50417
    BEGIN TRY
        EXEC dbo.sp_cambiar_estado_usuario @usuario_id = @gestor_a, @activo = 0;
        THROW 50953, 'Se esperaba error 50417 por auto-desactivación.', 1;
    END TRY
    BEGIN CATCH
        IF ERROR_NUMBER() <> 50417 THROW;
    END CATCH;

    -- 7. Protección del último gestor activo -> 50418
    -- Gestor B intenta desactivar a Gestor A, pero Gestor A es el único activo con USUARIOS_GESTIONAR_PERMISOS
    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @gestor_b;
    BEGIN TRY
        EXEC dbo.sp_cambiar_estado_usuario @usuario_id = @gestor_a, @activo = 0;
        THROW 50953, 'Se esperaba error 50418 al intentar desactivar al último gestor de permisos.', 1;
    END TRY
    BEGIN CATCH
        IF ERROR_NUMBER() <> 50418 THROW;
    END CATCH;

    -- 8. Concesión y revocación segura mediante SP existentes + reglas 50419 y 50420
    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @gestor_a;

    -- Auto-revocación crítica -> 50419
    BEGIN TRY
        EXEC dbo.sp_revocar_permiso_seguro
            @usuario_destino = @gestor_a,
            @codigo = N'USUARIOS_GESTIONAR_PERMISOS';
        THROW 50953, 'Se esperaba error 50419 por auto-revocación administrativa.', 1;
    END TRY
    BEGIN CATCH
        IF ERROR_NUMBER() <> 50419 THROW;
    END CATCH;

    -- Conceder permiso operativo a nuevo usuario y revocarlo
    EXEC dbo.sp_conceder_permiso @usuario_destino = @nuevo_usuario_id, @codigo = N'CLIENTES_VER';
    EXEC dbo.sp_revocar_permiso_seguro @usuario_destino = @nuevo_usuario_id, @codigo = N'CLIENTES_VER';

    -- Revocar USUARIOS_GESTIONAR_CUENTAS a Gestor B (válido porque Gestor A sigue activo con ese permiso)
    EXEC dbo.sp_revocar_permiso_seguro @usuario_destino = @gestor_b, @codigo = N'USUARIOS_GESTIONAR_CUENTAS';

    -- Conceder USUARIOS_GESTIONAR_PERMISOS a Gestor B y probar 50420 cuando B intenta revocar el último USUARIOS_GESTIONAR_CUENTAS de A
    EXEC dbo.sp_conceder_permiso @usuario_destino = @gestor_b, @codigo = N'USUARIOS_GESTIONAR_PERMISOS';
    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @gestor_b;
    BEGIN TRY
        EXEC dbo.sp_revocar_permiso_seguro
            @usuario_destino = @gestor_a,
            @codigo = N'USUARIOS_GESTIONAR_CUENTAS';
        THROW 50953, 'Se esperaba error 50420 al revocar el último gestor de cuentas activo.', 1;
    END TRY
    BEGIN CATCH
        IF ERROR_NUMBER() <> 50420 THROW;
    END CATCH;

    -- Desactivar y reactivar cuenta ordinaria
    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @gestor_a;
    EXEC dbo.sp_cambiar_estado_usuario @usuario_id = @nuevo_usuario_id, @activo = 0;
    EXEC dbo.sp_cambiar_estado_usuario @usuario_id = @nuevo_usuario_id, @activo = 1;

    ROLLBACK TRANSACTION;
    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = NULL;
    SELECT N'PASS: sql-user-account-contracts verificado y revertido en TransportesSegurosSA_Test.' AS resultado;
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = NULL;
    THROW;
END CATCH;
GO
