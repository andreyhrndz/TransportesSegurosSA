/*
  Propuesta para sql-customer-lifecycle-contracts.
  Ejecutar exclusivamente en TransportesSegurosSA_Test después de aprobación
  de la regla de solicitudes activas por producto/DBA.
*/
USE TransportesSegurosSA_Test;
GO

IF DB_NAME() <> N'TransportesSegurosSA_Test'
    THROW 50910, 'Este contrato solo puede ejecutarse en TransportesSegurosSA_Test.', 1;
GO

MERGE dbo.permisos AS target
USING (VALUES
    (N'CLIENTES_EDITAR', N'Editar clientes', N'Actualizar contacto y campos del subtipo sin cambiar identificación ni subtipo'),
    (N'CLIENTES_DESACTIVAR', N'Desactivar clientes', N'Desactivar clientes sin borrarlos')
) AS source(codigo, nombre, descripcion)
ON target.codigo = source.codigo
WHEN MATCHED THEN UPDATE SET nombre = source.nombre, descripcion = source.descripcion
WHEN NOT MATCHED BY TARGET THEN INSERT (codigo, nombre, descripcion) VALUES (source.codigo, source.nombre, source.descripcion);
GO

CREATE OR ALTER PROCEDURE dbo.sp_actualizar_cliente_fisico
    @cliente_id INT,
    @correo NVARCHAR(150),
    @telefono NVARCHAR(30) = NULL,
    @direccion NVARCHAR(250) = NULL,
    @nombre NVARCHAR(100),
    @primer_apellido NVARCHAR(100),
    @segundo_apellido NVARCHAR(100) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@usuario_id, N'CLIENTES_EDITAR') = 0
        THROW 50216, 'No tiene permiso para editar clientes.', 1;
    IF @cliente_id IS NULL OR @cliente_id < 1
       OR NULLIF(LTRIM(RTRIM(@correo)), N'') IS NULL
       OR @correo NOT LIKE N'%_@_%._%'
       OR NULLIF(LTRIM(RTRIM(@nombre)), N'') IS NULL
       OR NULLIF(LTRIM(RTRIM(@primer_apellido)), N'') IS NULL
        THROW 50219, 'Datos de cliente inválidos.', 1;

    BEGIN TRANSACTION;
    BEGIN TRY
        IF NOT EXISTS (
            SELECT 1
            FROM dbo.clientes AS c WITH (UPDLOCK, HOLDLOCK)
            JOIN dbo.clientes_fisicos AS f ON f.cliente_id = c.cliente_id
            WHERE c.cliente_id = @cliente_id
        )
            THROW 50218, 'Cliente inexistente o subtipo incompatible.', 1;

        UPDATE dbo.clientes
        SET correo = LTRIM(RTRIM(@correo)),
            telefono = NULLIF(LTRIM(RTRIM(@telefono)), N''),
            direccion = NULLIF(LTRIM(RTRIM(@direccion)), N'')
        WHERE cliente_id = @cliente_id;

        UPDATE dbo.clientes_fisicos
        SET nombre = LTRIM(RTRIM(@nombre)),
            primer_apellido = LTRIM(RTRIM(@primer_apellido)),
            segundo_apellido = NULLIF(LTRIM(RTRIM(@segundo_apellido)), N'')
        WHERE cliente_id = @cliente_id;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_actualizar_cliente_juridico
    @cliente_id INT,
    @correo NVARCHAR(150),
    @telefono NVARCHAR(30) = NULL,
    @direccion NVARCHAR(250) = NULL,
    @razon_social NVARCHAR(150),
    @nombre_comercial NVARCHAR(150) = NULL,
    @representante_legal NVARCHAR(150) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@usuario_id, N'CLIENTES_EDITAR') = 0
        THROW 50216, 'No tiene permiso para editar clientes.', 1;
    IF @cliente_id IS NULL OR @cliente_id < 1
       OR NULLIF(LTRIM(RTRIM(@correo)), N'') IS NULL
       OR @correo NOT LIKE N'%_@_%._%'
       OR NULLIF(LTRIM(RTRIM(@razon_social)), N'') IS NULL
        THROW 50219, 'Datos de cliente inválidos.', 1;

    BEGIN TRANSACTION;
    BEGIN TRY
        IF NOT EXISTS (
            SELECT 1
            FROM dbo.clientes AS c WITH (UPDLOCK, HOLDLOCK)
            JOIN dbo.clientes_juridicos AS j ON j.cliente_id = c.cliente_id
            WHERE c.cliente_id = @cliente_id
        )
            THROW 50218, 'Cliente inexistente o subtipo incompatible.', 1;

        UPDATE dbo.clientes
        SET correo = LTRIM(RTRIM(@correo)),
            telefono = NULLIF(LTRIM(RTRIM(@telefono)), N''),
            direccion = NULLIF(LTRIM(RTRIM(@direccion)), N'')
        WHERE cliente_id = @cliente_id;

        UPDATE dbo.clientes_juridicos
        SET razon_social = LTRIM(RTRIM(@razon_social)),
            nombre_comercial = NULLIF(LTRIM(RTRIM(@nombre_comercial)), N''),
            representante_legal = NULLIF(LTRIM(RTRIM(@representante_legal)), N'')
        WHERE cliente_id = @cliente_id;

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_desactivar_cliente
    @cliente_id INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@usuario_id, N'CLIENTES_DESACTIVAR') = 0
        THROW 50217, 'No tiene permiso para desactivar clientes.', 1;
    IF @cliente_id IS NULL OR @cliente_id < 1
        THROW 50218, 'Cliente inexistente o subtipo incompatible.', 1;

    BEGIN TRANSACTION;
    BEGIN TRY
        DECLARE @activo BIT;
        SELECT @activo = activo
        FROM dbo.clientes WITH (UPDLOCK, HOLDLOCK)
        WHERE cliente_id = @cliente_id;

        IF @activo IS NULL
            THROW 50218, 'Cliente inexistente o subtipo incompatible.', 1;
        IF @activo = 0
            THROW 50221, 'El cliente ya está inactivo.', 1;

        /*
          Regla propuesta, pendiente de aprobación: no desactivar si hay solicitud
          no terminal. No altera ni borra solicitudes existentes.
        */
        IF EXISTS (
            SELECT 1
            FROM dbo.solicitudes WITH (UPDLOCK, HOLDLOCK)
            WHERE cliente_id = @cliente_id
              AND estado IN ('REGISTRADA', 'PENDIENTE_ASIGNACION', 'ASIGNADA', 'EN_TRANSITO')
        )
            THROW 50220, 'El cliente tiene solicitudes operativas activas.', 1;

        UPDATE dbo.clientes SET activo = 0 WHERE cliente_id = @cliente_id;
        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO
