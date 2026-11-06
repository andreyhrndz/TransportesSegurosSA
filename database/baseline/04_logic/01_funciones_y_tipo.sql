USE TransportesSegurosSA;
GO


-- Las solicitudes pueden tener varias mercancias.
-- Un parametro tipo tabla permite registrar todas en una transaccion.

CREATE TYPE dbo.tipo_mercancias AS TABLE
(
    tipo_mercancia_id INT NOT NULL,
    descripcion NVARCHAR(250) NOT NULL,
    peso_kg DECIMAL(12,2) NOT NULL,
    volumen_m3 DECIMAL(12,3) NULL
);
GO

CREATE OR ALTER FUNCTION dbo.fn_tiene_permiso
(
    @usuario_id INT,
    @codigo NVARCHAR(100)
)
RETURNS BIT
AS
BEGIN
    DECLARE @tiene BIT = 0;

    IF EXISTS
    (
        SELECT 1
        FROM dbo.usuarios u
        JOIN dbo.usuarios_permisos up ON up.usuario_id = u.usuario_id
        JOIN dbo.permisos p ON p.permiso_id = up.permiso_id
        WHERE u.usuario_id = @usuario_id
          AND u.activo = 1
          AND p.codigo = @codigo
          AND p.activo = 1
    )
        SET @tiene = 1;

    RETURN @tiene;
END;
GO

CREATE OR ALTER FUNCTION dbo.fn_calcular_prioridad
(
    @fecha_entrega DATETIME2
)
RETURNS VARCHAR(10)
AS
BEGIN
    DECLARE @horas INT = DATEDIFF(HOUR, SYSUTCDATETIME(), @fecha_entrega);

    IF @horas <= 24 RETURN 'URGENTE';
    IF @horas <= 72 RETURN 'ALTA';

    RETURN 'NORMAL';
END;
GO
