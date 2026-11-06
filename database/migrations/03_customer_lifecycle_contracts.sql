/*
  Contratos provisionales para sql-customer-lifecycle-contracts en entorno aislado.
  Ejecutar exclusivamente en TransportesSegurosSA_Test. Prohibido desplegar en
  TransportesSegurosSA hasta revisión y aprobación definitiva de DBA/producto.
*/
-- Contexto establecido por conexion cliente

IF DB_NAME() NOT IN (N'TransportesSegurosSA', N'TransportesSegurosSA_Test', N'TransportesSegurosSA_Staging')
    THROW 50920, 'Este contrato solo puede ejecutarse en una base autorizada de TransportesSegurosSA.', 1;
GO

MERGE dbo.permisos AS target
USING (VALUES
    (N'CLIENTES_EDITAR', N'Editar clientes', N'Actualizar datos de contacto e información del cliente'),
    (N'CLIENTES_DESACTIVAR', N'Desactivar clientes', N'Desactivar clientes preservando su historial de solicitudes')
) AS source(codigo, nombre, descripcion)
ON target.codigo = source.codigo
WHEN MATCHED THEN UPDATE SET
    nombre = source.nombre,
    descripcion = source.descripcion,
    activo = 1
WHEN NOT MATCHED BY TARGET THEN
    INSERT (codigo, nombre, descripcion) VALUES (source.codigo, source.nombre, source.descripcion);
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

    -- ASVS 8.2.1, 8.3.1: verificación explícita de permiso individual en servidor SQL.
    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@usuario_id, N'CLIENTES_EDITAR') = 0
        THROW 50216, 'No tiene permiso para editar clientes.', 1;

    SET @correo = NULLIF(LTRIM(RTRIM(@correo)), N'');
    SET @telefono = NULLIF(LTRIM(RTRIM(@telefono)), N'');
    SET @direccion = NULLIF(LTRIM(RTRIM(@direccion)), N'');
    SET @nombre = NULLIF(LTRIM(RTRIM(@nombre)), N'');
    SET @primer_apellido = NULLIF(LTRIM(RTRIM(@primer_apellido)), N'');
    SET @segundo_apellido = NULLIF(LTRIM(RTRIM(@segundo_apellido)), N'');

    -- ASVS 2.2.1, 2.2.2: validación de obligatoriedad y formato básico de correo.
    IF @cliente_id IS NULL OR @cliente_id < 1
       OR @correo IS NULL
       OR @correo NOT LIKE N'%_@_%._%'
       OR @correo LIKE N'% %'
       OR @nombre IS NULL
       OR @primer_apellido IS NULL
        THROW 50219, 'Datos de cliente inválidos.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1
        BEGIN TRANSACTION;
    ELSE
        SAVE TRANSACTION sp_act_cli_fis;

    BEGIN TRY
        DECLARE @activo_actual BIT;

        SELECT @activo_actual = c.activo
        FROM dbo.clientes AS c WITH (UPDLOCK, HOLDLOCK)
        JOIN dbo.clientes_fisicos AS f ON f.cliente_id = c.cliente_id
        WHERE c.cliente_id = @cliente_id;

        IF @activo_actual IS NULL
            THROW 50218, 'Cliente inexistente o subtipo incompatible.', 1;

        UPDATE dbo.clientes
        SET correo = @correo,
            telefono = @telefono,
            direccion = @direccion
        WHERE cliente_id = @cliente_id;

        UPDATE dbo.clientes_fisicos
        SET nombre = @nombre,
            primer_apellido = @primer_apellido,
            segundo_apellido = @segundo_apellido
        WHERE cliente_id = @cliente_id;

        INSERT INTO dbo.auditorias
        (
            usuario_id, entidad, registro_id, accion,
            valores_anteriores, valores_nuevos
        )
        VALUES
        (
            @usuario_id,
            N'clientes',
            CONVERT(NVARCHAR(100), @cliente_id),
            'UPDATE',
            CONCAT(N'Subtipo: FISICA; Activo: ', CONVERT(NVARCHAR(1), @activo_actual)),
            CONCAT(N'Subtipo: FISICA; Activo: ', CONVERT(NVARCHAR(1), @activo_actual), N'; Datos actualizados')
        );

        IF @started_tran = 1
            COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1
            ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0
            ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1
            ROLLBACK TRANSACTION sp_act_cli_fis;
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

    -- ASVS 8.2.1, 8.3.1: verificación explícita de permiso individual en servidor SQL.
    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@usuario_id, N'CLIENTES_EDITAR') = 0
        THROW 50216, 'No tiene permiso para editar clientes.', 1;

    SET @correo = NULLIF(LTRIM(RTRIM(@correo)), N'');
    SET @telefono = NULLIF(LTRIM(RTRIM(@telefono)), N'');
    SET @direccion = NULLIF(LTRIM(RTRIM(@direccion)), N'');
    SET @razon_social = NULLIF(LTRIM(RTRIM(@razon_social)), N'');
    SET @nombre_comercial = NULLIF(LTRIM(RTRIM(@nombre_comercial)), N'');
    SET @representante_legal = NULLIF(LTRIM(RTRIM(@representante_legal)), N'');

    -- ASVS 2.2.1, 2.2.2: validación de obligatoriedad y formato básico de correo.
    IF @cliente_id IS NULL OR @cliente_id < 1
       OR @correo IS NULL
       OR @correo NOT LIKE N'%_@_%._%'
       OR @correo LIKE N'% %'
       OR @razon_social IS NULL
        THROW 50219, 'Datos de cliente inválidos.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1
        BEGIN TRANSACTION;
    ELSE
        SAVE TRANSACTION sp_act_cli_jur;

    BEGIN TRY
        DECLARE @activo_actual BIT;

        SELECT @activo_actual = c.activo
        FROM dbo.clientes AS c WITH (UPDLOCK, HOLDLOCK)
        JOIN dbo.clientes_juridicos AS j ON j.cliente_id = c.cliente_id
        WHERE c.cliente_id = @cliente_id;

        IF @activo_actual IS NULL
            THROW 50218, 'Cliente inexistente o subtipo incompatible.', 1;

        UPDATE dbo.clientes
        SET correo = @correo,
            telefono = @telefono,
            direccion = @direccion
        WHERE cliente_id = @cliente_id;

        UPDATE dbo.clientes_juridicos
        SET razon_social = @razon_social,
            nombre_comercial = @nombre_comercial,
            representante_legal = @representante_legal
        WHERE cliente_id = @cliente_id;

        INSERT INTO dbo.auditorias
        (
            usuario_id, entidad, registro_id, accion,
            valores_anteriores, valores_nuevos
        )
        VALUES
        (
            @usuario_id,
            N'clientes',
            CONVERT(NVARCHAR(100), @cliente_id),
            'UPDATE',
            CONCAT(N'Subtipo: JURIDICA; Activo: ', CONVERT(NVARCHAR(1), @activo_actual)),
            CONCAT(N'Subtipo: JURIDICA; Activo: ', CONVERT(NVARCHAR(1), @activo_actual), N'; Datos actualizados')
        );

        IF @started_tran = 1
            COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1
            ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0
            ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1
            ROLLBACK TRANSACTION sp_act_cli_jur;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_desactivar_cliente
    @cliente_id INT
AS
BEGIN
    SET NOCOUNT ON;

    -- ASVS 8.2.1, 8.3.1: verificación explícita de permiso individual para desactivación.
    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@usuario_id, N'CLIENTES_DESACTIVAR') = 0
        THROW 50217, 'No tiene permiso para desactivar clientes.', 1;
    IF @cliente_id IS NULL OR @cliente_id < 1
        THROW 50218, 'Cliente inexistente o subtipo incompatible.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1
        BEGIN TRANSACTION;
    ELSE
        SAVE TRANSACTION sp_des_cli;

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
          Regla provisional en Test: rechazar desactivación cuando existen solicitudes
          no terminales. Conserva solicitudes históricas e integridad referencial.
        */
        IF EXISTS (
            SELECT 1
            FROM dbo.solicitudes WITH (UPDLOCK, HOLDLOCK)
            WHERE cliente_id = @cliente_id
              AND estado IN ('REGISTRADA', 'PENDIENTE_ASIGNACION', 'ASIGNADA', 'EN_TRANSITO')
        )
            THROW 50220, 'El cliente tiene solicitudes operativas activas.', 1;

        UPDATE dbo.clientes
        SET activo = 0
        WHERE cliente_id = @cliente_id;

        INSERT INTO dbo.auditorias
        (
            usuario_id, entidad, registro_id, accion,
            valores_anteriores, valores_nuevos
        )
        VALUES
        (
            @usuario_id,
            N'clientes',
            CONVERT(NVARCHAR(100), @cliente_id),
            'UPDATE',
            N'Activo: 1',
            N'Activo: 0'
        );

        IF @started_tran = 1
            COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1
            ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0
            ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1
            ROLLBACK TRANSACTION sp_des_cli;
        THROW;
    END CATCH;
END;
GO
