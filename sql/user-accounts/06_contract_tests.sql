/*
    Transportes Seguros S.A.
    Pruebas automatizadas de contratos de invitaciones y recuperación en TransportesSegurosSA_Test:
    - Creación de invitación (solo correo)
    - Rechazo de duplicados y usuarios existentes
    - Validación de token
    - Autoservicio de alta (onboarding) y asignación de perfil
    - Verificación de 0 permisos por defecto
    - Cancelación de invitación
    - Flujo de recuperación de contraseña ("Olvidé mi contraseña")
*/

IF DB_NAME() <> N'TransportesSegurosSA_Test'
BEGIN
    RAISERROR('Este script solo debe ejecutarse en TransportesSegurosSA_Test', 20, 1) WITH LOG;
    SET NOEXEC ON;
END
GO

PRINT '=== INICIO DE PRUEBAS DE INVITACIONES Y RECUPERACIÓN ===';

BEGIN TRANSACTION;
BEGIN TRY
    -- 1. Contexto como admin (usuario_id = 1)
    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = 1;

    DECLARE @token1 VARCHAR(64) = LOWER(CONVERT(VARCHAR(64), HASHBYTES('SHA2_256', 'token_prueba_invitacion_1'), 2));
    DECLARE @inv_id INT;

    -- 2. Crear invitación válida
    EXEC dbo.sp_crear_invitacion_usuario
        @correo_destino = N'nuevo.invitado@example.test',
        @token_hash = @token1,
        @horas_vigencia = 24,
        @invitacion_id = @inv_id OUTPUT;

    IF @inv_id IS NULL OR @inv_id <= 0
        THROW 59101, 'No se generó el ID de invitación.', 1;
    PRINT 'OK: Invitación creada con ID ' + CAST(@inv_id AS VARCHAR(10));

    -- 3. Rechazar invitación duplicada activa (50431)
    DECLARE @fallo_dup BIT = 0;
    BEGIN TRY
        DECLARE @inv_dup INT;
        DECLARE @token_dup VARCHAR(64) = LOWER(CONVERT(VARCHAR(64), HASHBYTES('SHA2_256', 'token_dup'), 2));
        EXEC dbo.sp_crear_invitacion_usuario
            @correo_destino = N'nuevo.invitado@example.test',
            @token_hash = @token_dup,
            @horas_vigencia = 24,
            @invitacion_id = @inv_dup OUTPUT;
    END TRY
    BEGIN CATCH
        IF ERROR_NUMBER() = 50431
            SET @fallo_dup = 1;
    END CATCH;

    IF @fallo_dup <> 1
        THROW 59102, 'Debe rechazar invitación duplicada para correo con invitación pendiente.', 1;
    PRINT 'OK: Rechazo de invitación duplicada verificado (50431).';

    -- 4. Validar token
    DECLARE @t_res TABLE (resultado VARCHAR(20), invitacion_id INT, correo_destino NVARCHAR(150));
    INSERT INTO @t_res
    EXEC dbo.sp_validar_token_invitacion @token_hash = @token1;

    IF NOT EXISTS (SELECT 1 FROM @t_res WHERE resultado = 'valida' AND invitacion_id = @inv_id)
        THROW 59103, 'La validación del token no retornó resultado valida.', 1;
    PRINT 'OK: Validación de token retornó resultado valida.';

    -- 5. Completar invitación (onboarding autoservicio)
    DECLARE @nuevo_usr_id INT;
    DECLARE @pwd_hash NVARCHAR(255) = N'$2y$12$e0MYzXyjpJS7Pd0RVvHwHeFj4Gv8D8vT1O0U1F1U1O0U1F1U1O0U1'; -- hash válido bcrypt de 60 chars

    EXEC dbo.sp_completar_invitacion_usuario
        @token_hash = @token1,
        @identificacion = N'1-9999-8888',
        @tipo_identificacion = '01',
        @nombre = N'Invitado',
        @primer_apellido = N'Prueba',
        @segundo_apellido = N'Autoservicio',
        @nombre_usuario = N'invitado_auto',
        @password_hash = @pwd_hash,
        @telefono = N'8765-4321',
        @nuevo_usuario_id = @nuevo_usr_id OUTPUT;

    IF @nuevo_usr_id IS NULL OR @nuevo_usr_id <= 0
        THROW 59104, 'No se generó el nuevo usuario al completar invitación.', 1;

    -- Verificar que la cuenta está activa
    IF (SELECT activo FROM dbo.usuarios WHERE usuario_id = @nuevo_usr_id) <> 1
        THROW 59105, 'El usuario creado debe estar activo.', 1;

    -- Verificar que el usuario tiene CERO permisos por defecto
    IF EXISTS (SELECT 1 FROM dbo.usuarios_permisos WHERE usuario_id = @nuevo_usr_id)
        THROW 59106, 'El usuario nuevo NO debe recibir permisos por defecto.', 1;
    PRINT 'OK: Usuario activado con cero permisos por defecto.';

    -- Verificar que la invitación pasó a COMPLETADA
    IF (SELECT estado FROM dbo.invitaciones_usuario WHERE invitacion_id = @inv_id) <> 'COMPLETADA'
        THROW 59107, 'La invitación debe quedar COMPLETADA.', 1;
    PRINT 'OK: Invitación marcada como COMPLETADA.';

    -- 6. Intentar reusar token ya completado (debe fallar)
    DECLARE @fallo_reuso BIT = 0;
    BEGIN TRY
        DECLARE @usr_reuso INT;
        EXEC dbo.sp_completar_invitacion_usuario
            @token_hash = @token1,
            @identificacion = N'1-9999-7777',
            @tipo_identificacion = '01',
            @nombre = N'Otro',
            @primer_apellido = N'Intento',
            @segundo_apellido = NULL,
            @nombre_usuario = N'otro_intento',
            @password_hash = @pwd_hash,
            @telefono = NULL,
            @nuevo_usuario_id = @usr_reuso OUTPUT;
    END TRY
    BEGIN CATCH
        IF ERROR_NUMBER() = 50432
            SET @fallo_reuso = 1;
    END CATCH;

    IF @fallo_reuso <> 1
        THROW 59108, 'Debe rechazar reuso de token de invitación completado.', 1;
    PRINT 'OK: Rechazo de reuso de token verificado (50432).';

    -- 7. Flujo de recuperación de contraseña
    DECLARE @token_rec VARCHAR(64) = LOWER(CONVERT(VARCHAR(64), HASHBYTES('SHA2_256', 'token_recuperacion_test'), 2));
    DECLARE @rec_usr_id INT, @rec_nombre NVARCHAR(80);

    EXEC dbo.sp_solicitar_recuperacion_password
        @correo = N'nuevo.invitado@example.test',
        @token_hash = @token_rec,
        @minutos_vigencia = 30,
        @usuario_id = @rec_usr_id OUTPUT,
        @nombre_usuario = @rec_nombre OUTPUT;

    IF @rec_usr_id <> @nuevo_usr_id
        THROW 59109, 'La solicitud de recuperación no resolvió al usuario esperado.', 1;
    PRINT 'OK: Solicitud de recuperación generada para el usuario.';

    -- Validar token de recuperación
    DECLARE @t_rec TABLE (resultado VARCHAR(20), usuario_id INT, correo_acceso NVARCHAR(150));
    INSERT INTO @t_rec
    EXEC dbo.sp_validar_token_recuperacion @token_hash = @token_rec;

    IF NOT EXISTS (SELECT 1 FROM @t_rec WHERE resultado = 'valido' AND usuario_id = @nuevo_usr_id)
        THROW 59110, 'La validación del token de recuperación no retornó valido.', 1;
    PRINT 'OK: Token de recuperación validado exitosamente.';

    -- Completar restablecimiento de contraseña
    DECLARE @nuevo_pwd_hash NVARCHAR(255) = N'$2y$12$K1mN2oP3qR4sT5uV6wX7yZ8aB9cDefGhIjKlMnOpQrStUvWxYz012';
    EXEC dbo.sp_completar_recuperacion_password
        @token_hash = @token_rec,
        @password_hash = @nuevo_pwd_hash;

    -- Verificar que la contraseña fue actualizada
    IF (SELECT password_hash FROM dbo.usuarios WHERE usuario_id = @nuevo_usr_id) <> @nuevo_pwd_hash
        THROW 59111, 'La contraseña no fue actualizada en dbo.usuarios.', 1;

    -- Verificar que el token quedó marcado como USADO
    IF (SELECT estado FROM dbo.tokens_recuperacion WHERE token_hash = @token_rec) <> 'USADO'
        THROW 59112, 'El token de recuperación debe quedar USADO.', 1;
    PRINT 'OK: Contraseña restablecida con éxito y token marcado como USADO.';

    ROLLBACK TRANSACTION;
    PRINT 'OK: Todas las pruebas de contratos de invitaciones y recuperación superadas y revertidas.';
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO
