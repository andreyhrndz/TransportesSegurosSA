/*
  Integracion reversible del TVP y procedimientos existentes de solicitudes.
  Crea datos y una identidad de minimo privilegio SOLO dentro de una transaccion
  de TransportesSegurosSA_Test, y siempre revierte todos los cambios.
*/
USE TransportesSegurosSA_Test;
GO

IF DB_NAME() <> N'TransportesSegurosSA_Test'
    THROW 50930, 'Esta prueba solo puede ejecutarse en TransportesSegurosSA_Test.', 1;
GO

SET XACT_ABORT ON;
GO

BEGIN TRANSACTION;
BEGIN TRY
    DECLARE @usuario_id INT;
    DECLARE @cliente_id INT;
    DECLARE @origen_id INT;
    DECLARE @destino_id INT;
    DECLARE @tipo_mercancia_id INT;
    DECLARE @solicitud_id INT;
    DECLARE @fecha_entrega_requerida DATETIME2 = DATEADD(DAY, 1, SYSUTCDATETIME());
    DECLARE @mercancias dbo.tipo_mercancias;

    INSERT INTO dbo.usuarios (nombre_usuario, correo_acceso, password_hash, activo)
    VALUES (N'__transport_request_test', N'__transport_request_test@example.test', N'not-usable-for-login', 1);
    SET @usuario_id = CONVERT(INT, SCOPE_IDENTITY());

    INSERT INTO dbo.usuarios_permisos (usuario_id, permiso_id)
    SELECT @usuario_id, permiso_id
    FROM dbo.permisos
    WHERE codigo IN (N'SOLICITUDES_CREAR', N'SOLICITUDES_AJUSTAR_PRIORIDAD', N'SOLICITUDES_CANCELAR');

    IF @@ROWCOUNT <> 3
        THROW 50931, 'Faltan permisos operativos de solicitudes en la copia de prueba.', 1;

    INSERT INTO dbo.clientes (identificacion, correo, activo)
    VALUES (N'__TR_TEST_CLIENT', N'cliente.solicitud@example.test', 1);
    SET @cliente_id = CONVERT(INT, SCOPE_IDENTITY());
    INSERT INTO dbo.clientes_fisicos (cliente_id, nombre, primer_apellido)
    VALUES (@cliente_id, N'Cliente', N'Prueba');

    INSERT INTO dbo.ubicaciones (nombre, direccion, activo)
    VALUES (N'__TR_TEST_ORIGEN', N'Direccion de prueba origen', 1);
    SET @origen_id = CONVERT(INT, SCOPE_IDENTITY());
    INSERT INTO dbo.ubicaciones (nombre, direccion, activo)
    VALUES (N'__TR_TEST_DESTINO', N'Direccion de prueba destino', 1);
    SET @destino_id = CONVERT(INT, SCOPE_IDENTITY());

    INSERT INTO dbo.tipos_mercancia (nombre, descripcion, activo)
    VALUES (N'__TR_TEST_MERCANCIA', N'Tipo efimero para contrato', 1);
    SET @tipo_mercancia_id = CONVERT(INT, SCOPE_IDENTITY());

    INSERT INTO @mercancias (tipo_mercancia_id, descripcion, peso_kg, volumen_m3)
    VALUES
        (@tipo_mercancia_id, N'Caja de prueba A', CAST(10.50 AS DECIMAL(12,2)), CAST(1.250 AS DECIMAL(12,3))),
        (@tipo_mercancia_id, N'Caja de prueba B', CAST(5.00 AS DECIMAL(12,2)), CAST(0.500 AS DECIMAL(12,3)));

    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @usuario_id;
    EXEC dbo.sp_crear_solicitud
        @codigo = N'__TR_TEST_001',
        @cliente_id = @cliente_id,
        @origen_id = @origen_id,
        @destino_id = @destino_id,
        @destinatario_nombre = N'Destinatario',
        @destinatario_apellido1 = N'De',
        @destinatario_apellido2 = N'Prueba',
        @destinatario_identificacion = NULL,
        @destinatario_telefono = NULL,
        @fecha_entrega_requerida = @fecha_entrega_requerida,
        @mercancias = @mercancias,
        @solicitud_id = @solicitud_id OUTPUT;

    IF @solicitud_id IS NULL
        THROW 50932, 'sp_crear_solicitud no devolvio solicitud_id.', 1;
    IF (SELECT COUNT(*) FROM dbo.mercancias WHERE solicitud_id = @solicitud_id) <> 2
        THROW 50933, 'El TVP no creo las dos mercancias esperadas.', 1;

    EXEC dbo.sp_ajustar_prioridad
        @solicitud_id = @solicitud_id,
        @prioridad = 'ALTA',
        @justificacion = N'Prueba contractual reversible.';
    IF NOT EXISTS (SELECT 1 FROM dbo.solicitudes WHERE solicitud_id = @solicitud_id AND prioridad_actual = 'ALTA')
        THROW 50934, 'No se aplico el ajuste de prioridad.', 1;

    EXEC dbo.sp_cancelar_solicitud @solicitud_id = @solicitud_id, @motivo = N'Prueba contractual reversible.';
    IF NOT EXISTS (SELECT 1 FROM dbo.solicitudes WHERE solicitud_id = @solicitud_id AND estado = 'CANCELADA')
        THROW 50935, 'No se aplico la cancelacion permitida.', 1;

    ROLLBACK TRANSACTION;
    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = NULL;
    PRINT N'PASS: solicitud, TVP, prioridad y cancelacion verificados y revertidos.';
END TRY
BEGIN CATCH
    IF XACT_STATE() <> 0 ROLLBACK TRANSACTION;
    EXEC sys.sp_set_session_context @key = N'usuario_id', @value = NULL;
    THROW;
END CATCH;
GO
