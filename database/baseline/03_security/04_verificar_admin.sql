USE TransportesSegurosSA;
GO


SELECT
    u.usuario_id,
    u.nombre_usuario,
    u.activo,
    COUNT(up.permiso_id) AS TotalPermisos,
    CASE
        WHEN LEFT(u.password_hash, 4) IN (N'$2a$', N'$2b$', N'$2y$')
        THEN 'PREFIJO BCRYPT'
        ELSE 'FORMATO NO RECONOCIDO'
    END AS FormatoHash
FROM dbo.usuarios u
LEFT JOIN dbo.usuarios_permisos up ON up.usuario_id = u.usuario_id
WHERE u.nombre_usuario = N'admin_proyecto'
GROUP BY u.usuario_id, u.nombre_usuario, u.activo, u.password_hash;
GO
