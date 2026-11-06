/*
    Transportes Seguros S.A.
    Pruebas automatizadas T-SQL de contratos de permisos en invitaciones.
    Ambiente exclusivo: TransportesSegurosSA_Test
*/

IF DB_NAME() <> N'TransportesSegurosSA_Test'
BEGIN
    RAISERROR('Este script solo debe ejecutarse en TransportesSegurosSA_Test', 20, 1) WITH LOG;
    SET NOEXEC ON;
END
GO

SET NOCOUNT ON;
BEGIN TRANSACTION;

BEGIN TRY
    -- 1. Contexto con usuario admin (usuario_id = 1, superadmin con todos los permisos)
    EXEC sys.sp_set_session_context @key=N'usuario_id', @value=1;

    DECLARE @token1 VARCHAR(64) = '1111111111111111111111111111111111111111111111111111111111111111';
    DECLARE @inv_id INT;

    EXEC dbo.sp_crear_invitacion_usuario
        @correo_destino = N'test.permisos.sql@example.test',
        @token_hash = @token1,
        @horas_vigencia = 24,
        @invitacion_id = @inv_id OUTPUT;

    IF @inv_id IS NULL OR @inv_id < 1
        THROW 50901, 'No se pudo crear invitación de prueba.', 1;

    -- 2. Asignar 2 permisos específicos: CLIENTES_VER y SOLICITUDES_VER
    EXEC dbo.sp_asignar_permiso_invitacion @invitacion_id = @inv_id, @codigo = N'CLIENTES_VER';
    EXEC dbo.sp_asignar_permiso_invitacion @invitacion_id = @inv_id, @codigo = N'SOLICITUDES_VER';

    -- Verificar que la tabla invitaciones_permisos tiene exactamente 2 registros
    DECLARE @count_perms INT;
    SELECT @count_perms = COUNT(*) FROM dbo.invitaciones_permisos WHERE invitacion_id = @inv_id;
    IF @count_perms <> 2
        THROW 50902, 'La cantidad de permisos asignados a la invitación no coincide.', 1;

    -- 3. Asignación duplicada es idempotente
    EXEC dbo.sp_asignar_permiso_invitacion @invitacion_id = @inv_id, @codigo = N'CLIENTES_VER';
    SELECT @count_perms = COUNT(*) FROM dbo.invitaciones_permisos WHERE invitacion_id = @inv_id;
    IF @count_perms <> 2
        THROW 50903, 'La asignación de permisos debe ser idempotente.', 1;

    -- 4. Completar invitación y verificar que el nuevo usuario recibe exactamente esos 2 permisos
    DECLARE @nuevo_uid INT;
    EXEC dbo.sp_completar_invitacion_usuario
        @token_hash = @token1,
        @identificacion = N'TEST-SQL-PERM-01',
        @tipo_identificacion = '01',
        @nombre = N'Usuario',
        @primer_apellido = N'Permisos',
        @segundo_apellido = N'Test',
        @nombre_usuario = N'usr.perm.test01',
        @password_hash = N'$2y$12$e8w8G189eX2c1kS9yv7fQe6iUv8p0q7c4d5e6f7g8h9i0j1k2l3m4',
        @telefono = NULL,
        @nuevo_usuario_id = @nuevo_uid OUTPUT;

    IF @nuevo_uid IS NULL OR @nuevo_uid < 1
        THROW 50904, 'No se generó el nuevo usuario tras completar invitación.', 1;

    -- Contar permisos efectivos del nuevo usuario
    DECLARE @user_perms_count INT;
    SELECT @user_perms_count = COUNT(*) FROM dbo.usuarios_permisos WHERE usuario_id = @nuevo_uid;
    IF @user_perms_count <> 2
        THROW 50905, 'El usuario creado debe tener exactamente los 2 permisos autorizados.', 1;

    -- Verificar que fn_tiene_permiso responde 1 para los 2 y 0 para otros
    IF dbo.fn_tiene_permiso(@nuevo_uid, N'CLIENTES_VER') <> 1
        THROW 50906, 'El usuario debe tener CLIENTES_VER.', 1;
    IF dbo.fn_tiene_permiso(@nuevo_uid, N'SOLICITUDES_VER') <> 1
        THROW 50907, 'El usuario debe tener SOLICITUDES_VER.', 1;
    IF dbo.fn_tiene_permiso(@nuevo_uid, N'USUARIOS_VER') <> 0
        THROW 50908, 'El usuario NO debe tener permisos no concedidos.', 1;

    -- 5. Prueba de reenvío: nueva invitación debe heredar los permisos de la original
    DECLARE @token2 VARCHAR(64) = '2222222222222222222222222222222222222222222222222222222222222222';
    DECLARE @inv_id2 INT;
    EXEC dbo.sp_crear_invitacion_usuario
        @correo_destino = N'test.resend.sql@example.test',
        @token_hash = @token2,
        @horas_vigencia = 24,
        @invitacion_id = @inv_id2 OUTPUT;

    EXEC dbo.sp_asignar_permiso_invitacion @invitacion_id = @inv_id2, @codigo = N'FLOTAS_VER';

    -- Simular transcurso de cooldown
    UPDATE dbo.invitaciones_usuario SET ultimo_envio_en = DATEADD(SECOND, -65, SYSUTCDATETIME()) WHERE invitacion_id = @inv_id2;

    DECLARE @token3 VARCHAR(64) = '3333333333333333333333333333333333333333333333333333333333333333';
    DECLARE @inv_id3 INT;
    EXEC dbo.sp_reenviar_invitacion_usuario
        @invitacion_id = @inv_id2,
        @nuevo_token_hash = @token3,
        @horas_vigencia = 24,
        @nueva_invitacion_id = @inv_id3 OUTPUT;

    SELECT @count_perms = COUNT(*) FROM dbo.invitaciones_permisos WHERE invitacion_id = @inv_id3;
    IF @count_perms <> 1
        THROW 50909, 'La invitación reenviada debe conservar los permisos planificados.', 1;

    -- 6. Prevenir elevación de privilegios: un usuario sin USUARIOS_GESTIONAR_PERMISOS no puede asignar permisos
    EXEC sys.sp_set_session_context @key=N'usuario_id', @value=@nuevo_uid;
    DECLARE @failed BIT = 0;
    BEGIN TRY
        EXEC dbo.sp_asignar_permiso_invitacion @invitacion_id = @inv_id3, @codigo = N'CLIENTES_CREAR';
    END TRY
    BEGIN CATCH
        SET @failed = 1;
    END CATCH;
    IF @failed <> 1
        THROW 50910, 'Usuario sin USUARIOS_GESTIONAR_PERMISOS no debe poder asignar permisos a invitaciones.', 1;

    ROLLBACK TRANSACTION;
    PRINT 'OK: Todos los contratos SQL de permisos en invitaciones probados exitosamente.';
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO
