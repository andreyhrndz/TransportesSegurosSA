USE TransportesSegurosSA;
GO


DECLARE @admin INT = (SELECT usuario_id FROM dbo.usuarios WHERE nombre_usuario = N'admin_proyecto');

-- Usuario de laboratorio DESHABILITADO que no puede iniciar sesion.
INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash, activo)
VALUES (N'usuario_inactivo_prueba', N'inactivo@ejemplo.test', N'NO_ES_BCRYPT_NI_SE_PERMITE_LOGIN', 0);

DECLARE @usuario_prueba INT = CONVERT(INT, SCOPE_IDENTITY());

-- El administrador concede y revoca un permiso.
EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @admin;

EXEC dbo.sp_conceder_permiso
    @usuario_destino = @usuario_prueba,
    @codigo = N'CLIENTES_CREAR';

SELECT COUNT(*) AS PermisosConcedidos
FROM dbo.usuarios_permisos WHERE usuario_id = @usuario_prueba;

EXEC dbo.sp_revocar_permiso
    @usuario_destino = @usuario_prueba,
    @codigo = N'CLIENTES_CREAR';

SELECT COUNT(*) AS PermisosRestantes
FROM dbo.usuarios_permisos WHERE usuario_id = @usuario_prueba;

-- Usuario inexistente debe ser rechazado por el SP.
EXEC sys.sp_set_session_context @key = N'usuario_id', @value = 999999;

DECLARE @cliente_nuevo INT;

BEGIN TRY
    EXEC dbo.sp_crear_cliente_fisico
        @identificacion = N'DEMO-SIN-PERMISO',
        @nombre = N'No',
        @primer_apellido = N'Autorizado',
        @segundo_apellido = N'Demo',
        @correo = N'sinpermiso@ejemplo.test',
        @telefono = NULL,
        @cliente_id = @cliente_nuevo OUTPUT;

    SELECT 'FAIL: Acceso sin permiso' AS Resultado;
END TRY
BEGIN CATCH
    SELECT CASE WHEN ERROR_NUMBER() = 50201 THEN 'PASS' ELSE 'FAIL' END AS Resultado,
           ERROR_NUMBER() AS CodigoError,
           ERROR_MESSAGE() AS Mensaje;
END CATCH;

EXEC sys.sp_set_session_context @key = N'usuario_id', @value = NULL;
GO
