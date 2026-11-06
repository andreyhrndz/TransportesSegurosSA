USE TransportesSegurosSA;
GO


-- CAMBIA SOLO @hash por tu bcrypt generado con PHP.
DECLARE @hash NVARCHAR(255) = N'$2y$10$DtwuOr9H.Ao8UNiFf76wUeaSUT3jeFkZrQk4HtS8C6Vl.QLTzZVSi';

-- Verificar que se reemplazó el valor de ejemplo
IF @hash = N'PEGAR_HASH_BCRYPT_AQUI'
BEGIN
    THROW 50100, 'Primero genera y pega tu hash bcrypt.', 1;
END;

-- Verificar la longitud y el prefijo de bcrypt
IF LEN(@hash) <> 60
   OR LEFT(@hash, 4) NOT IN (N'$2y$', N'$2b$', N'$2a$')
BEGIN
    THROW 50101, 'El hash bcrypt no tiene el formato esperado.', 1;
END;

IF EXISTS (SELECT 1 FROM dbo.usuarios WHERE nombre_usuario = N'admin_proyecto')
BEGIN
    THROW 50102, 'El administrador ya existe.', 1;
END;

BEGIN TRANSACTION;

INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash)
VALUES (N'admin_proyecto', N'admin@proyecto.test', @hash);

DECLARE @usuario_id INT = CONVERT(INT, SCOPE_IDENTITY());

INSERT INTO dbo.usuarios_permisos (usuario_id, permiso_id)
SELECT @usuario_id, permiso_id
FROM dbo.permisos
WHERE activo = 1;

COMMIT TRANSACTION;

SELECT
    @usuario_id AS UsuarioCreado,
    (SELECT COUNT(*) FROM dbo.usuarios_permisos WHERE usuario_id = @usuario_id) AS PermisosAsignados;
GO
