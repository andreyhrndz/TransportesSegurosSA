/*
  Contratos SQL provisionales para sql-master-data-contracts.
  Ejecutar exclusivamente en TransportesSegurosSA_Test.
  Cubre ubicaciones, catálogos (mercancía, incidencia, documento), vehículos, conductores y rutas.
  Sin roles, sin DELETE, con bloqueo transaccional compatible con sp_crear_viaje y auditoría real.

  Rango de errores (50501-50507):
    50501: Permiso individual insuficiente.
    50502: Datos de entrada o estado solicitado inválidos.
    50503: Registro maestro solicitado no existe.
    50504: Registro duplicado (nombre, placa, identificación, licencia, usuario o ruta).
    50505: Conflicto por operación activa o referencia comprometida.
    50506: El registro ya se encuentra inactivo (activo = 0).
    50507: Referencia relacionada inexistente o inactiva (origen, destino o usuario).
*/
USE TransportesSegurosSA_Test;
GO

IF DB_NAME() <> N'TransportesSegurosSA_Test'
    THROW 50960, 'Este contrato solo puede ejecutarse en TransportesSegurosSA_Test.', 1;
GO

MERGE dbo.permisos AS target
USING (VALUES
    (N'UBICACIONES_GESTIONAR', N'Gestionar ubicaciones', N'Crear, actualizar y desactivar ubicaciones logísticas'),
    (N'CATALOGOS_GESTIONAR', N'Gestionar catálogos', N'Crear, actualizar y desactivar tipos de mercancía, incidencia y documento'),
    (N'FLOTAS_GESTIONAR', N'Gestionar flota', N'Crear, actualizar y desactivar vehículos y conductores'),
    (N'RUTAS_GESTIONAR', N'Gestionar rutas', N'Crear, actualizar y desactivar rutas logísticas')
) AS source(codigo, nombre, descripcion)
ON target.codigo = source.codigo
WHEN MATCHED THEN UPDATE SET
    nombre = source.nombre,
    descripcion = source.descripcion,
    activo = 1
WHEN NOT MATCHED BY TARGET THEN
    INSERT (codigo, nombre, descripcion) VALUES (source.codigo, source.nombre, source.descripcion);
GO

/* =========================================================================
   1. UBICACIONES (UBICACIONES_GESTIONAR)
   ========================================================================= */

CREATE OR ALTER PROCEDURE dbo.sp_crear_ubicacion
    @nombre NVARCHAR(150),
    @direccion NVARCHAR(250),
    @latitud DECIMAL(9,6) = NULL,
    @longitud DECIMAL(9,6) = NULL,
    @ubicacion_id INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'UBICACIONES_GESTIONAR') = 0
        THROW 50501, 'No tiene permiso para gestionar ubicaciones.', 1;

    SET @nombre = NULLIF(LTRIM(RTRIM(@nombre)), N'');
    SET @direccion = NULLIF(LTRIM(RTRIM(@direccion)), N'');

    IF @nombre IS NULL
       OR @direccion IS NULL
       OR (@latitud IS NULL AND @longitud IS NOT NULL)
       OR (@latitud IS NOT NULL AND @longitud IS NULL)
       OR (@latitud IS NOT NULL AND (@latitud < -90 OR @latitud > 90))
       OR (@longitud IS NOT NULL AND (@longitud < -180 OR @longitud > 180))
        THROW 50502, 'Datos o coordenadas de ubicación inválidos.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_crear_ubi;

    BEGIN TRY
        IF EXISTS (SELECT 1 FROM dbo.ubicaciones WITH (UPDLOCK, HOLDLOCK) WHERE nombre = @nombre)
            THROW 50504, 'Ya existe una ubicación registrada con ese nombre.', 1;

        INSERT INTO dbo.ubicaciones (nombre, direccion, latitud, longitud, activo)
        VALUES (@nombre, @direccion, @latitud, @longitud, 1);

        SET @ubicacion_id = CONVERT(INT, SCOPE_IDENTITY());

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (
            @actor_id, N'ubicaciones', CONVERT(NVARCHAR(100), @ubicacion_id), 'INSERT',
            NULL, CONCAT(N'Nombre: ', @nombre, N'; Activo: 1')
        );

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION sp_crear_ubi;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_actualizar_ubicacion
    @ubicacion_id INT,
    @nombre NVARCHAR(150),
    @direccion NVARCHAR(250),
    @latitud DECIMAL(9,6) = NULL,
    @longitud DECIMAL(9,6) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'UBICACIONES_GESTIONAR') = 0
        THROW 50501, 'No tiene permiso para gestionar ubicaciones.', 1;

    SET @nombre = NULLIF(LTRIM(RTRIM(@nombre)), N'');
    SET @direccion = NULLIF(LTRIM(RTRIM(@direccion)), N'');

    IF @ubicacion_id IS NULL OR @ubicacion_id < 1
       OR @nombre IS NULL
       OR @direccion IS NULL
       OR (@latitud IS NULL AND @longitud IS NOT NULL)
       OR (@latitud IS NOT NULL AND @longitud IS NULL)
       OR (@latitud IS NOT NULL AND (@latitud < -90 OR @latitud > 90))
       OR (@longitud IS NOT NULL AND (@longitud < -180 OR @longitud > 180))
        THROW 50502, 'Datos o coordenadas de ubicación inválidos.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_act_ubi;

    BEGIN TRY
        DECLARE @prev_nombre NVARCHAR(150), @activo BIT;
        SELECT @prev_nombre = nombre, @activo = activo
        FROM dbo.ubicaciones WITH (UPDLOCK, HOLDLOCK)
        WHERE ubicacion_id = @ubicacion_id;

        IF @activo IS NULL
            THROW 50503, 'Ubicación no encontrada.', 1;
        IF @activo = 0
            THROW 50506, 'No se puede editar una ubicación inactiva.', 1;

        IF EXISTS (
            SELECT 1
            FROM dbo.paradas_viaje AS p
            JOIN dbo.viajes AS v ON v.viaje_id = p.viaje_id
            WHERE p.ubicacion_id = @ubicacion_id AND v.estado = 'EN_CURSO'
        )
            THROW 50505, 'No se puede modificar una ubicación con viajes actualmente en curso.', 1;

        IF EXISTS (
            SELECT 1 FROM dbo.ubicaciones WITH (UPDLOCK, HOLDLOCK)
            WHERE nombre = @nombre AND ubicacion_id <> @ubicacion_id
        )
            THROW 50504, 'Ya existe otra ubicación con ese nombre.', 1;

        UPDATE dbo.ubicaciones
        SET nombre = @nombre,
            direccion = @direccion,
            latitud = @latitud,
            longitud = @longitud
        WHERE ubicacion_id = @ubicacion_id;

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (
            @actor_id, N'ubicaciones', CONVERT(NVARCHAR(100), @ubicacion_id), 'UPDATE',
            CONCAT(N'Nombre: ', @prev_nombre),
            CONCAT(N'Nombre: ', @nombre)
        );

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION sp_act_ubi;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_desactivar_ubicacion
    @ubicacion_id INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'UBICACIONES_GESTIONAR') = 0
        THROW 50501, 'No tiene permiso para gestionar ubicaciones.', 1;

    IF @ubicacion_id IS NULL OR @ubicacion_id < 1
        THROW 50502, 'Identificador de ubicación inválido.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_des_ubi;

    BEGIN TRY
        DECLARE @nombre NVARCHAR(150), @activo BIT;
        SELECT @nombre = nombre, @activo = activo
        FROM dbo.ubicaciones WITH (UPDLOCK, HOLDLOCK)
        WHERE ubicacion_id = @ubicacion_id;

        IF @activo IS NULL
            THROW 50503, 'Ubicación no encontrada.', 1;
        IF @activo = 0
            THROW 50506, 'La ubicación ya se encuentra inactiva.', 1;

        IF EXISTS (
            SELECT 1 FROM dbo.rutas
            WHERE activo = 1 AND (origen_id = @ubicacion_id OR destino_id = @ubicacion_id)
        )
        OR EXISTS (
            SELECT 1 FROM dbo.solicitudes
            WHERE (origen_id = @ubicacion_id OR destino_id = @ubicacion_id)
              AND estado NOT IN ('ENTREGADA', 'ENTREGA_FALLIDA', 'CANCELADA')
        )
        OR EXISTS (
            SELECT 1
            FROM dbo.paradas_viaje AS p
            JOIN dbo.viajes AS v ON v.viaje_id = p.viaje_id
            WHERE p.ubicacion_id = @ubicacion_id AND v.estado IN ('PLANIFICADO', 'EN_CURSO')
        )
            THROW 50505, 'No se puede desactivar la ubicación porque participa en rutas activas, solicitudes abiertas o viajes activos.', 1;

        UPDATE dbo.ubicaciones
        SET activo = 0
        WHERE ubicacion_id = @ubicacion_id;

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (
            @actor_id, N'ubicaciones', CONVERT(NVARCHAR(100), @ubicacion_id), 'DEACTIVATE',
            CONCAT(N'Nombre: ', @nombre, N'; Activo: 1'),
            N'Activo: 0'
        );

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION sp_des_ubi;
        THROW;
    END CATCH;
END;
GO

/* =========================================================================
   2. CATÁLOGOS OPERATIVOS (CATALOGOS_GESTIONAR)
   ========================================================================= */

CREATE OR ALTER PROCEDURE dbo.sp_crear_tipo_mercancia
    @nombre NVARCHAR(100),
    @descripcion NVARCHAR(250) = NULL,
    @tipo_mercancia_id INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'CATALOGOS_GESTIONAR') = 0
        THROW 50501, 'No tiene permiso para gestionar catálogos.', 1;

    SET @nombre = NULLIF(LTRIM(RTRIM(@nombre)), N'');
    SET @descripcion = NULLIF(LTRIM(RTRIM(@descripcion)), N'');

    IF @nombre IS NULL
        THROW 50502, 'El nombre del tipo de mercancía es obligatorio.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_crear_tm;

    BEGIN TRY
        IF EXISTS (SELECT 1 FROM dbo.tipos_mercancia WITH (UPDLOCK, HOLDLOCK) WHERE nombre = @nombre)
            THROW 50504, 'Ya existe un tipo de mercancía con ese nombre.', 1;

        INSERT INTO dbo.tipos_mercancia (nombre, descripcion, activo)
        VALUES (@nombre, @descripcion, 1);

        SET @tipo_mercancia_id = CONVERT(INT, SCOPE_IDENTITY());

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (@actor_id, N'tipos_mercancia', CONVERT(NVARCHAR(100), @tipo_mercancia_id), 'INSERT', NULL, CONCAT(N'Nombre: ', @nombre, N'; Activo: 1'));

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION sp_crear_tm;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_actualizar_tipo_mercancia
    @tipo_mercancia_id INT,
    @nombre NVARCHAR(100),
    @descripcion NVARCHAR(250) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'CATALOGOS_GESTIONAR') = 0
        THROW 50501, 'No tiene permiso para gestionar catálogos.', 1;

    SET @nombre = NULLIF(LTRIM(RTRIM(@nombre)), N'');
    SET @descripcion = NULLIF(LTRIM(RTRIM(@descripcion)), N'');

    IF @tipo_mercancia_id IS NULL OR @tipo_mercancia_id < 1 OR @nombre IS NULL
        THROW 50502, 'Datos del tipo de mercancía inválidos.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_act_tm;

    BEGIN TRY
        DECLARE @prev_nombre NVARCHAR(100), @activo BIT;
        SELECT @prev_nombre = nombre, @activo = activo
        FROM dbo.tipos_mercancia WITH (UPDLOCK, HOLDLOCK)
        WHERE tipo_mercancia_id = @tipo_mercancia_id;

        IF @activo IS NULL THROW 50503, 'Tipo de mercancía no encontrado.', 1;
        IF @activo = 0 THROW 50506, 'No se puede editar un tipo de mercancía inactivo.', 1;

        IF EXISTS (SELECT 1 FROM dbo.tipos_mercancia WITH (UPDLOCK, HOLDLOCK) WHERE nombre = @nombre AND tipo_mercancia_id <> @tipo_mercancia_id)
            THROW 50504, 'Ya existe otro tipo de mercancía con ese nombre.', 1;

        UPDATE dbo.tipos_mercancia
        SET nombre = @nombre, descripcion = @descripcion
        WHERE tipo_mercancia_id = @tipo_mercancia_id;

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (@actor_id, N'tipos_mercancia', CONVERT(NVARCHAR(100), @tipo_mercancia_id), 'UPDATE', CONCAT(N'Nombre: ', @prev_nombre), CONCAT(N'Nombre: ', @nombre));

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION sp_act_tm;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_desactivar_tipo_mercancia
    @tipo_mercancia_id INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'CATALOGOS_GESTIONAR') = 0
        THROW 50501, 'No tiene permiso para gestionar catálogos.', 1;

    IF @tipo_mercancia_id IS NULL OR @tipo_mercancia_id < 1
        THROW 50502, 'Identificador de tipo de mercancía inválido.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_des_tm;

    BEGIN TRY
        DECLARE @nombre NVARCHAR(100), @activo BIT;
        SELECT @nombre = nombre, @activo = activo
        FROM dbo.tipos_mercancia WITH (UPDLOCK, HOLDLOCK)
        WHERE tipo_mercancia_id = @tipo_mercancia_id;

        IF @activo IS NULL THROW 50503, 'Tipo de mercancía no encontrado.', 1;
        IF @activo = 0 THROW 50506, 'El tipo de mercancía ya se encuentra inactivo.', 1;

        IF EXISTS (
            SELECT 1
            FROM dbo.mercancias AS m
            JOIN dbo.solicitudes AS s ON s.solicitud_id = m.solicitud_id
            WHERE m.tipo_mercancia_id = @tipo_mercancia_id
              AND s.estado NOT IN ('ENTREGADA', 'ENTREGA_FALLIDA', 'CANCELADA')
        )
            THROW 50505, 'No se puede desactivar el tipo de mercancía porque se utiliza en solicitudes operativas activas.', 1;

        UPDATE dbo.tipos_mercancia SET activo = 0 WHERE tipo_mercancia_id = @tipo_mercancia_id;

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (@actor_id, N'tipos_mercancia', CONVERT(NVARCHAR(100), @tipo_mercancia_id), 'DEACTIVATE', CONCAT(N'Nombre: ', @nombre, N'; Activo: 1'), N'Activo: 0');

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION sp_des_tm;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_crear_tipo_incidencia
    @nombre NVARCHAR(100),
    @descripcion NVARCHAR(250) = NULL,
    @tipo_incidencia_id INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'CATALOGOS_GESTIONAR') = 0
        THROW 50501, 'No tiene permiso para gestionar catálogos.', 1;

    SET @nombre = NULLIF(LTRIM(RTRIM(@nombre)), N'');
    SET @descripcion = NULLIF(LTRIM(RTRIM(@descripcion)), N'');

    IF @nombre IS NULL
        THROW 50502, 'El nombre del tipo de incidencia es obligatorio.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_crear_ti;

    BEGIN TRY
        IF EXISTS (SELECT 1 FROM dbo.tipos_incidencia WITH (UPDLOCK, HOLDLOCK) WHERE nombre = @nombre)
            THROW 50504, 'Ya existe un tipo de incidencia con ese nombre.', 1;

        INSERT INTO dbo.tipos_incidencia (nombre, descripcion, activo)
        VALUES (@nombre, @descripcion, 1);

        SET @tipo_incidencia_id = CONVERT(INT, SCOPE_IDENTITY());

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (@actor_id, N'tipos_incidencia', CONVERT(NVARCHAR(100), @tipo_incidencia_id), 'INSERT', NULL, CONCAT(N'Nombre: ', @nombre, N'; Activo: 1'));

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION sp_crear_ti;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_actualizar_tipo_incidencia
    @tipo_incidencia_id INT,
    @nombre NVARCHAR(100),
    @descripcion NVARCHAR(250) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'CATALOGOS_GESTIONAR') = 0
        THROW 50501, 'No tiene permiso para gestionar catálogos.', 1;

    SET @nombre = NULLIF(LTRIM(RTRIM(@nombre)), N'');
    SET @descripcion = NULLIF(LTRIM(RTRIM(@descripcion)), N'');

    IF @tipo_incidencia_id IS NULL OR @tipo_incidencia_id < 1 OR @nombre IS NULL
        THROW 50502, 'Datos del tipo de incidencia inválidos.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_act_ti;

    BEGIN TRY
        DECLARE @prev_nombre NVARCHAR(100), @activo BIT;
        SELECT @prev_nombre = nombre, @activo = activo
        FROM dbo.tipos_incidencia WITH (UPDLOCK, HOLDLOCK)
        WHERE tipo_incidencia_id = @tipo_incidencia_id;

        IF @activo IS NULL THROW 50503, 'Tipo de incidencia no encontrado.', 1;
        IF @activo = 0 THROW 50506, 'No se puede editar un tipo de incidencia inactivo.', 1;

        IF EXISTS (SELECT 1 FROM dbo.tipos_incidencia WITH (UPDLOCK, HOLDLOCK) WHERE nombre = @nombre AND tipo_incidencia_id <> @tipo_incidencia_id)
            THROW 50504, 'Ya existe otro tipo de incidencia con ese nombre.', 1;

        UPDATE dbo.tipos_incidencia
        SET nombre = @nombre, descripcion = @descripcion
        WHERE tipo_incidencia_id = @tipo_incidencia_id;

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (@actor_id, N'tipos_incidencia', CONVERT(NVARCHAR(100), @tipo_incidencia_id), 'UPDATE', CONCAT(N'Nombre: ', @prev_nombre), CONCAT(N'Nombre: ', @nombre));

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION sp_act_ti;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_desactivar_tipo_incidencia
    @tipo_incidencia_id INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'CATALOGOS_GESTIONAR') = 0
        THROW 50501, 'No tiene permiso para gestionar catálogos.', 1;

    IF @tipo_incidencia_id IS NULL OR @tipo_incidencia_id < 1
        THROW 50502, 'Identificador de tipo de incidencia inválido.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_des_ti;

    BEGIN TRY
        DECLARE @nombre NVARCHAR(100), @activo BIT;
        SELECT @nombre = nombre, @activo = activo
        FROM dbo.tipos_incidencia WITH (UPDLOCK, HOLDLOCK)
        WHERE tipo_incidencia_id = @tipo_incidencia_id;

        IF @activo IS NULL THROW 50503, 'Tipo de incidencia no encontrado.', 1;
        IF @activo = 0 THROW 50506, 'El tipo de incidencia ya se encuentra inactivo.', 1;

        IF EXISTS (
            SELECT 1 FROM dbo.incidencias
            WHERE tipo_incidencia_id = @tipo_incidencia_id
              AND estado IN ('ABIERTA', 'EN_ATENCION')
        )
            THROW 50505, 'No se puede desactivar el tipo de incidencia porque existen incidencias abiertas o en atención.', 1;

        UPDATE dbo.tipos_incidencia SET activo = 0 WHERE tipo_incidencia_id = @tipo_incidencia_id;

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (@actor_id, N'tipos_incidencia', CONVERT(NVARCHAR(100), @tipo_incidencia_id), 'DEACTIVATE', CONCAT(N'Nombre: ', @nombre, N'; Activo: 1'), N'Activo: 0');

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION sp_des_ti;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_crear_tipo_documento
    @nombre NVARCHAR(100),
    @descripcion NVARCHAR(250) = NULL,
    @tipo_documento_id INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'CATALOGOS_GESTIONAR') = 0
        THROW 50501, 'No tiene permiso para gestionar catálogos.', 1;

    SET @nombre = NULLIF(LTRIM(RTRIM(@nombre)), N'');
    SET @descripcion = NULLIF(LTRIM(RTRIM(@descripcion)), N'');

    IF @nombre IS NULL
        THROW 50502, 'El nombre del tipo de documento es obligatorio.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_crear_td;

    BEGIN TRY
        IF EXISTS (SELECT 1 FROM dbo.tipos_documento WITH (UPDLOCK, HOLDLOCK) WHERE nombre = @nombre)
            THROW 50504, 'Ya existe un tipo de documento con ese nombre.', 1;

        INSERT INTO dbo.tipos_documento (nombre, descripcion, activo)
        VALUES (@nombre, @descripcion, 1);

        SET @tipo_documento_id = CONVERT(INT, SCOPE_IDENTITY());

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (@actor_id, N'tipos_documento', CONVERT(NVARCHAR(100), @tipo_documento_id), 'INSERT', NULL, CONCAT(N'Nombre: ', @nombre, N'; Activo: 1'));

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION sp_crear_td;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_actualizar_tipo_documento
    @tipo_documento_id INT,
    @nombre NVARCHAR(100),
    @descripcion NVARCHAR(250) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'CATALOGOS_GESTIONAR') = 0
        THROW 50501, 'No tiene permiso para gestionar catálogos.', 1;

    SET @nombre = NULLIF(LTRIM(RTRIM(@nombre)), N'');
    SET @descripcion = NULLIF(LTRIM(RTRIM(@descripcion)), N'');

    IF @tipo_documento_id IS NULL OR @tipo_documento_id < 1 OR @nombre IS NULL
        THROW 50502, 'Datos del tipo de documento inválidos.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_act_td;

    BEGIN TRY
        DECLARE @prev_nombre NVARCHAR(100), @activo BIT;
        SELECT @prev_nombre = nombre, @activo = activo
        FROM dbo.tipos_documento WITH (UPDLOCK, HOLDLOCK)
        WHERE tipo_documento_id = @tipo_documento_id;

        IF @activo IS NULL THROW 50503, 'Tipo de documento no encontrado.', 1;
        IF @activo = 0 THROW 50506, 'No se puede editar un tipo de documento inactivo.', 1;

        IF EXISTS (SELECT 1 FROM dbo.tipos_documento WITH (UPDLOCK, HOLDLOCK) WHERE nombre = @nombre AND tipo_documento_id <> @tipo_documento_id)
            THROW 50504, 'Ya existe otro tipo de documento con ese nombre.', 1;

        UPDATE dbo.tipos_documento
        SET nombre = @nombre, descripcion = @descripcion
        WHERE tipo_documento_id = @tipo_documento_id;

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (@actor_id, N'tipos_documento', CONVERT(NVARCHAR(100), @tipo_documento_id), 'UPDATE', CONCAT(N'Nombre: ', @prev_nombre), CONCAT(N'Nombre: ', @nombre));

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION sp_act_td;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_desactivar_tipo_documento
    @tipo_documento_id INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'CATALOGOS_GESTIONAR') = 0
        THROW 50501, 'No tiene permiso para gestionar catálogos.', 1;

    IF @tipo_documento_id IS NULL OR @tipo_documento_id < 1
        THROW 50502, 'Identificador de tipo de documento inválido.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_des_td;

    BEGIN TRY
        DECLARE @nombre NVARCHAR(100), @activo BIT;
        SELECT @nombre = nombre, @activo = activo
        FROM dbo.tipos_documento WITH (UPDLOCK, HOLDLOCK)
        WHERE tipo_documento_id = @tipo_documento_id;

        IF @activo IS NULL THROW 50503, 'Tipo de documento no encontrado.', 1;
        IF @activo = 0 THROW 50506, 'El tipo de documento ya se encuentra inactivo.', 1;

        IF EXISTS (
            SELECT 1
            FROM dbo.documentos AS d
            JOIN dbo.solicitudes AS s ON s.solicitud_id = d.solicitud_id
            WHERE d.tipo_documento_id = @tipo_documento_id
              AND s.estado NOT IN ('ENTREGADA', 'ENTREGA_FALLIDA', 'CANCELADA')
        )
            THROW 50505, 'No se puede desactivar el tipo de documento porque está asociado a solicitudes operativas activas.', 1;

        UPDATE dbo.tipos_documento SET activo = 0 WHERE tipo_documento_id = @tipo_documento_id;

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (@actor_id, N'tipos_documento', CONVERT(NVARCHAR(100), @tipo_documento_id), 'DEACTIVATE', CONCAT(N'Nombre: ', @nombre, N'; Activo: 1'), N'Activo: 0');

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION sp_des_td;
        THROW;
    END CATCH;
END;
GO

/* =========================================================================
   3. VEHÍCULOS (FLOTAS_GESTIONAR)
   ========================================================================= */

CREATE OR ALTER PROCEDURE dbo.sp_crear_vehiculo
    @placa NVARCHAR(20),
    @marca NVARCHAR(80),
    @modelo NVARCHAR(80),
    @anio SMALLINT,
    @capacidad_carga_kg DECIMAL(12,2),
    @capacidad_volumen_m3 DECIMAL(12,3) = NULL,
    @kilometraje_actual DECIMAL(12,1) = 0,
    @estado VARCHAR(20) = 'DISPONIBLE',
    @vehiculo_id INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'FLOTAS_GESTIONAR') = 0
        THROW 50501, 'No tiene permiso para gestionar la flota.', 1;

    SET @placa = UPPER(NULLIF(LTRIM(RTRIM(@placa)), N''));
    SET @marca = NULLIF(LTRIM(RTRIM(@marca)), N'');
    SET @modelo = NULLIF(LTRIM(RTRIM(@modelo)), N'');
    SET @estado = UPPER(ISNULL(NULLIF(LTRIM(RTRIM(@estado)), ''), 'DISPONIBLE'));

    IF @placa IS NULL
       OR @marca IS NULL
       OR @modelo IS NULL
       OR @anio IS NULL OR @anio < 1980 OR @anio > (YEAR(SYSUTCDATETIME()) + 1)
       OR @capacidad_carga_kg IS NULL OR @capacidad_carga_kg <= 0
       OR (@capacidad_volumen_m3 IS NOT NULL AND @capacidad_volumen_m3 <= 0)
       OR @kilometraje_actual IS NULL OR @kilometraje_actual < 0
       OR @estado NOT IN ('DISPONIBLE', 'MANTENIMIENTO', 'FUERA_SERVICIO')
        THROW 50502, 'Datos del vehículo o estado operativo inicial inválidos.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_crear_veh;

    BEGIN TRY
        IF EXISTS (SELECT 1 FROM dbo.vehiculos WITH (UPDLOCK, HOLDLOCK) WHERE placa = @placa)
            THROW 50504, 'Ya existe un vehículo registrado con esa placa.', 1;

        INSERT INTO dbo.vehiculos (
            placa, marca, modelo, anio, capacidad_carga_kg,
            capacidad_volumen_m3, kilometraje_actual, estado, activo
        )
        VALUES (
            @placa, @marca, @modelo, @anio, @capacidad_carga_kg,
            @capacidad_volumen_m3, @kilometraje_actual, @estado, 1
        );

        SET @vehiculo_id = CONVERT(INT, SCOPE_IDENTITY());

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (
            @actor_id, N'vehiculos', CONVERT(NVARCHAR(100), @vehiculo_id), 'INSERT',
            NULL, CONCAT(N'Placa: ', @placa, N'; Estado: ', @estado, N'; Activo: 1')
        );

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION sp_crear_veh;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_actualizar_vehiculo
    @vehiculo_id INT,
    @marca NVARCHAR(80),
    @modelo NVARCHAR(80),
    @anio SMALLINT,
    @capacidad_carga_kg DECIMAL(12,2),
    @capacidad_volumen_m3 DECIMAL(12,3) = NULL,
    @kilometraje_actual DECIMAL(12,1),
    @estado VARCHAR(20)
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'FLOTAS_GESTIONAR') = 0
        THROW 50501, 'No tiene permiso para gestionar la flota.', 1;

    SET @marca = NULLIF(LTRIM(RTRIM(@marca)), N'');
    SET @modelo = NULLIF(LTRIM(RTRIM(@modelo)), N'');
    SET @estado = UPPER(NULLIF(LTRIM(RTRIM(@estado)), ''));

    IF @vehiculo_id IS NULL OR @vehiculo_id < 1
       OR @marca IS NULL
       OR @modelo IS NULL
       OR @anio IS NULL OR @anio < 1980 OR @anio > (YEAR(SYSUTCDATETIME()) + 1)
       OR @capacidad_carga_kg IS NULL OR @capacidad_carga_kg <= 0
       OR (@capacidad_volumen_m3 IS NOT NULL AND @capacidad_volumen_m3 <= 0)
       OR @kilometraje_actual IS NULL OR @kilometraje_actual < 0
       OR @estado IS NULL OR @estado NOT IN ('DISPONIBLE', 'MANTENIMIENTO', 'FUERA_SERVICIO')
        THROW 50502, 'Datos del vehículo o estado operativo inválidos.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_act_veh;

    BEGIN TRY
        DECLARE @placa NVARCHAR(20), @prev_estado VARCHAR(20), @prev_km DECIMAL(12,1), @activo BIT;
        SELECT @placa = placa,
               @prev_estado = estado,
               @prev_km = kilometraje_actual,
               @activo = activo
        FROM dbo.vehiculos WITH (UPDLOCK, HOLDLOCK)
        WHERE vehiculo_id = @vehiculo_id;

        IF @activo IS NULL
            THROW 50503, 'Vehículo no encontrado.', 1;
        IF @activo = 0
            THROW 50506, 'No se puede editar un vehículo inactivo.', 1;

        IF @prev_estado = 'ASIGNADO'
           OR EXISTS (
               SELECT 1 FROM dbo.viajes
               WHERE vehiculo_id = @vehiculo_id AND estado IN ('PLANIFICADO', 'EN_CURSO')
           )
            THROW 50505, 'No se puede modificar el vehículo mientras está asignado a un viaje planificado o en curso.', 1;

        IF @kilometraje_actual < @prev_km
            THROW 50502, 'El kilometraje actual no puede ser menor al registrado previamente.', 1;

        UPDATE dbo.vehiculos
        SET marca = @marca,
            modelo = @modelo,
            anio = @anio,
            capacidad_carga_kg = @capacidad_carga_kg,
            capacidad_volumen_m3 = @capacidad_volumen_m3,
            kilometraje_actual = @kilometraje_actual,
            estado = @estado
        WHERE vehiculo_id = @vehiculo_id;

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (
            @actor_id, N'vehiculos', CONVERT(NVARCHAR(100), @vehiculo_id), 'UPDATE',
            CONCAT(N'Placa: ', @placa, N'; Estado: ', @prev_estado, N'; Km: ', @prev_km),
            CONCAT(N'Placa: ', @placa, N'; Estado: ', @estado, N'; Km: ', @kilometraje_actual)
        );

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION sp_act_veh;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_desactivar_vehiculo
    @vehiculo_id INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'FLOTAS_GESTIONAR') = 0
        THROW 50501, 'No tiene permiso para gestionar la flota.', 1;

    IF @vehiculo_id IS NULL OR @vehiculo_id < 1
        THROW 50502, 'Identificador de vehículo inválido.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_des_veh;

    BEGIN TRY
        DECLARE @placa NVARCHAR(20), @prev_estado VARCHAR(20), @activo BIT;
        SELECT @placa = placa, @prev_estado = estado, @activo = activo
        FROM dbo.vehiculos WITH (UPDLOCK, HOLDLOCK)
        WHERE vehiculo_id = @vehiculo_id;

        IF @activo IS NULL
            THROW 50503, 'Vehículo no encontrado.', 1;
        IF @activo = 0
            THROW 50506, 'El vehículo ya se encuentra inactivo.', 1;

        IF @prev_estado = 'ASIGNADO'
           OR EXISTS (
               SELECT 1 FROM dbo.viajes
               WHERE vehiculo_id = @vehiculo_id AND estado IN ('PLANIFICADO', 'EN_CURSO')
           )
            THROW 50505, 'No se puede desactivar un vehículo asignado a un viaje planificado o en curso.', 1;

        UPDATE dbo.vehiculos
        SET activo = 0,
            estado = 'FUERA_SERVICIO'
        WHERE vehiculo_id = @vehiculo_id;

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (
            @actor_id, N'vehiculos', CONVERT(NVARCHAR(100), @vehiculo_id), 'DEACTIVATE',
            CONCAT(N'Placa: ', @placa, N'; Estado: ', @prev_estado, N'; Activo: 1'),
            N'Estado: FUERA_SERVICIO; Activo: 0'
        );

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION sp_des_veh;
        THROW;
    END CATCH;
END;
GO

/* =========================================================================
   4. CONDUCTORES (FLOTAS_GESTIONAR)
   ========================================================================= */

CREATE OR ALTER PROCEDURE dbo.sp_crear_conductor
    @identificacion NVARCHAR(30),
    @nombre NVARCHAR(100),
    @primer_apellido NVARCHAR(100),
    @segundo_apellido NVARCHAR(100) = NULL,
    @telefono NVARCHAR(30) = NULL,
    @correo NVARCHAR(150) = NULL,
    @numero_licencia NVARCHAR(50),
    @fecha_vencimiento_licencia DATE,
    @estado_operativo VARCHAR(20) = 'DISPONIBLE',
    @usuario_id INT = NULL,
    @conductor_id INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'FLOTAS_GESTIONAR') = 0
        THROW 50501, 'No tiene permiso para gestionar la flota.', 1;

    SET @identificacion = NULLIF(LTRIM(RTRIM(@identificacion)), N'');
    SET @nombre = NULLIF(LTRIM(RTRIM(@nombre)), N'');
    SET @primer_apellido = NULLIF(LTRIM(RTRIM(@primer_apellido)), N'');
    SET @segundo_apellido = NULLIF(LTRIM(RTRIM(@segundo_apellido)), N'');
    SET @telefono = NULLIF(LTRIM(RTRIM(@telefono)), N'');
    SET @correo = NULLIF(LTRIM(RTRIM(@correo)), N'');
    SET @numero_licencia = UPPER(NULLIF(LTRIM(RTRIM(@numero_licencia)), N''));
    SET @estado_operativo = UPPER(ISNULL(NULLIF(LTRIM(RTRIM(@estado_operativo)), ''), 'DISPONIBLE'));

    IF @identificacion IS NULL
       OR @nombre IS NULL
       OR @primer_apellido IS NULL
       OR @numero_licencia IS NULL
       OR @fecha_vencimiento_licencia IS NULL
       OR @fecha_vencimiento_licencia < CONVERT(DATE, SYSUTCDATETIME())
       OR (@correo IS NOT NULL AND (@correo NOT LIKE N'%_@_%._%' OR @correo LIKE N'% %'))
       OR @estado_operativo NOT IN ('DISPONIBLE', 'INACTIVO')
        THROW 50502, 'Datos del conductor, licencia o estado operativo inválidos.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_crear_cond;

    BEGIN TRY
        IF @usuario_id IS NOT NULL
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM dbo.usuarios WHERE usuario_id = @usuario_id AND activo = 1)
                THROW 50507, 'El usuario asociado no existe o está inactivo.', 1;
        END;

        IF EXISTS (
            SELECT 1 FROM dbo.conductores WITH (UPDLOCK, HOLDLOCK)
            WHERE identificacion = @identificacion
               OR numero_licencia = @numero_licencia
               OR (@usuario_id IS NOT NULL AND usuario_id = @usuario_id)
        )
            THROW 50504, 'Ya existe un conductor con esa identificación, número de licencia o cuenta de usuario.', 1;

        INSERT INTO dbo.conductores (
            usuario_id, identificacion, nombre, primer_apellido, segundo_apellido,
            telefono, correo, numero_licencia, fecha_vencimiento_licencia, estado_operativo, activo
        )
        VALUES (
            @usuario_id, @identificacion, @nombre, @primer_apellido, @segundo_apellido,
            @telefono, @correo, @numero_licencia, @fecha_vencimiento_licencia, @estado_operativo, 1
        );

        SET @conductor_id = CONVERT(INT, SCOPE_IDENTITY());

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (
            @actor_id, N'conductores', CONVERT(NVARCHAR(100), @conductor_id), 'INSERT',
            NULL, CONCAT(N'Licencia: ', @numero_licencia, N'; Estado: ', @estado_operativo, N'; Activo: 1')
        );

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION sp_crear_cond;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_actualizar_conductor
    @conductor_id INT,
    @nombre NVARCHAR(100),
    @primer_apellido NVARCHAR(100),
    @segundo_apellido NVARCHAR(100) = NULL,
    @telefono NVARCHAR(30) = NULL,
    @correo NVARCHAR(150) = NULL,
    @numero_licencia NVARCHAR(50),
    @fecha_vencimiento_licencia DATE,
    @estado_operativo VARCHAR(20),
    @usuario_id INT = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'FLOTAS_GESTIONAR') = 0
        THROW 50501, 'No tiene permiso para gestionar la flota.', 1;

    SET @nombre = NULLIF(LTRIM(RTRIM(@nombre)), N'');
    SET @primer_apellido = NULLIF(LTRIM(RTRIM(@primer_apellido)), N'');
    SET @segundo_apellido = NULLIF(LTRIM(RTRIM(@segundo_apellido)), N'');
    SET @telefono = NULLIF(LTRIM(RTRIM(@telefono)), N'');
    SET @correo = NULLIF(LTRIM(RTRIM(@correo)), N'');
    SET @numero_licencia = UPPER(NULLIF(LTRIM(RTRIM(@numero_licencia)), N''));
    SET @estado_operativo = UPPER(NULLIF(LTRIM(RTRIM(@estado_operativo)), ''));

    IF @conductor_id IS NULL OR @conductor_id < 1
       OR @nombre IS NULL
       OR @primer_apellido IS NULL
       OR @numero_licencia IS NULL
       OR @fecha_vencimiento_licencia IS NULL
       OR @fecha_vencimiento_licencia < CONVERT(DATE, SYSUTCDATETIME())
       OR (@correo IS NOT NULL AND (@correo NOT LIKE N'%_@_%._%' OR @correo LIKE N'% %'))
       OR @estado_operativo IS NULL OR @estado_operativo NOT IN ('DISPONIBLE', 'INACTIVO')
        THROW 50502, 'Datos del conductor, vigencia de licencia o estado operativo inválidos.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_act_cond;

    BEGIN TRY
        DECLARE @prev_licencia NVARCHAR(50), @prev_estado VARCHAR(20), @activo BIT;
        SELECT @prev_licencia = numero_licencia,
               @prev_estado = estado_operativo,
               @activo = activo
        FROM dbo.conductores WITH (UPDLOCK, HOLDLOCK)
        WHERE conductor_id = @conductor_id;

        IF @activo IS NULL
            THROW 50503, 'Conductor no encontrado.', 1;
        IF @activo = 0
            THROW 50506, 'No se puede editar un conductor inactivo.', 1;

        IF @prev_estado = 'ASIGNADO'
           OR EXISTS (
               SELECT 1 FROM dbo.viajes
               WHERE conductor_id = @conductor_id AND estado IN ('PLANIFICADO', 'EN_CURSO')
           )
            THROW 50505, 'No se puede modificar el conductor mientras está asignado a un viaje planificado o en curso.', 1;

        IF @usuario_id IS NOT NULL
        BEGIN
            IF NOT EXISTS (SELECT 1 FROM dbo.usuarios WHERE usuario_id = @usuario_id AND activo = 1)
                THROW 50507, 'El usuario asociado no existe o está inactivo.', 1;
        END;

        IF EXISTS (
            SELECT 1 FROM dbo.conductores WITH (UPDLOCK, HOLDLOCK)
            WHERE conductor_id <> @conductor_id
              AND (numero_licencia = @numero_licencia OR (@usuario_id IS NOT NULL AND usuario_id = @usuario_id))
        )
            THROW 50504, 'Otro conductor ya utiliza ese número de licencia o cuenta de usuario.', 1;

        UPDATE dbo.conductores
        SET usuario_id = @usuario_id,
            nombre = @nombre,
            primer_apellido = @primer_apellido,
            segundo_apellido = @segundo_apellido,
            telefono = @telefono,
            correo = @correo,
            numero_licencia = @numero_licencia,
            fecha_vencimiento_licencia = @fecha_vencimiento_licencia,
            estado_operativo = @estado_operativo
        WHERE conductor_id = @conductor_id;

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (
            @actor_id, N'conductores', CONVERT(NVARCHAR(100), @conductor_id), 'UPDATE',
            CONCAT(N'Licencia: ', @prev_licencia, N'; Estado: ', @prev_estado),
            CONCAT(N'Licencia: ', @numero_licencia, N'; Estado: ', @estado_operativo)
        );

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION sp_act_cond;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_desactivar_conductor
    @conductor_id INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'FLOTAS_GESTIONAR') = 0
        THROW 50501, 'No tiene permiso para gestionar la flota.', 1;

    IF @conductor_id IS NULL OR @conductor_id < 1
        THROW 50502, 'Identificador de conductor inválido.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_des_cond;

    BEGIN TRY
        DECLARE @licencia NVARCHAR(50), @prev_estado VARCHAR(20), @activo BIT;
        SELECT @licencia = numero_licencia, @prev_estado = estado_operativo, @activo = activo
        FROM dbo.conductores WITH (UPDLOCK, HOLDLOCK)
        WHERE conductor_id = @conductor_id;

        IF @activo IS NULL
            THROW 50503, 'Conductor no encontrado.', 1;
        IF @activo = 0
            THROW 50506, 'El conductor ya se encuentra inactivo.', 1;

        IF @prev_estado = 'ASIGNADO'
           OR EXISTS (
               SELECT 1 FROM dbo.viajes
               WHERE conductor_id = @conductor_id AND estado IN ('PLANIFICADO', 'EN_CURSO')
           )
            THROW 50505, 'No se puede desactivar un conductor asignado a un viaje planificado o en curso.', 1;

        UPDATE dbo.conductores
        SET activo = 0,
            estado_operativo = 'INACTIVO'
        WHERE conductor_id = @conductor_id;

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (
            @actor_id, N'conductores', CONVERT(NVARCHAR(100), @conductor_id), 'DEACTIVATE',
            CONCAT(N'Licencia: ', @licencia, N'; Estado: ', @prev_estado, N'; Activo: 1'),
            N'Estado: INACTIVO; Activo: 0'
        );

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION sp_des_cond;
        THROW;
    END CATCH;
END;
GO

/* =========================================================================
   5. RUTAS (RUTAS_GESTIONAR)
   ========================================================================= */

CREATE OR ALTER PROCEDURE dbo.sp_crear_ruta
    @nombre NVARCHAR(150),
    @origen_id INT,
    @destino_id INT,
    @distancia_km DECIMAL(10,2),
    @duracion_estimada_min INT,
    @ruta_id INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'RUTAS_GESTIONAR') = 0
        THROW 50501, 'No tiene permiso para gestionar rutas.', 1;

    SET @nombre = NULLIF(LTRIM(RTRIM(@nombre)), N'');

    IF @nombre IS NULL
       OR @origen_id IS NULL OR @origen_id < 1
       OR @destino_id IS NULL OR @destino_id < 1
       OR @origen_id = @destino_id
       OR @distancia_km IS NULL OR @distancia_km <= 0
       OR @duracion_estimada_min IS NULL OR @duracion_estimada_min <= 0
        THROW 50502, 'Datos de la ruta inválidos (origen y destino deben ser distintos y las magnitudes positivas).', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_crear_ruta;

    BEGIN TRY
        IF NOT EXISTS (SELECT 1 FROM dbo.ubicaciones WHERE ubicacion_id = @origen_id AND activo = 1)
           OR NOT EXISTS (SELECT 1 FROM dbo.ubicaciones WHERE ubicacion_id = @destino_id AND activo = 1)
            THROW 50507, 'Las ubicaciones de origen y destino deben existir y estar activas.', 1;

        IF EXISTS (
            SELECT 1 FROM dbo.rutas WITH (UPDLOCK, HOLDLOCK)
            WHERE origen_id = @origen_id AND destino_id = @destino_id AND nombre = @nombre
        )
            THROW 50504, 'Ya existe una ruta con ese nombre entre el mismo origen y destino.', 1;

        INSERT INTO dbo.rutas (nombre, origen_id, destino_id, distancia_km, duracion_estimada_min, activo)
        VALUES (@nombre, @origen_id, @destino_id, @distancia_km, @duracion_estimada_min, 1);

        SET @ruta_id = CONVERT(INT, SCOPE_IDENTITY());

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (
            @actor_id, N'rutas', CONVERT(NVARCHAR(100), @ruta_id), 'INSERT',
            NULL, CONCAT(N'Nombre: ', @nombre, N'; Origen: ', @origen_id, N'; Destino: ', @destino_id, N'; Activo: 1')
        );

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION sp_crear_ruta;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_actualizar_ruta
    @ruta_id INT,
    @nombre NVARCHAR(150),
    @origen_id INT,
    @destino_id INT,
    @distancia_km DECIMAL(10,2),
    @duracion_estimada_min INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'RUTAS_GESTIONAR') = 0
        THROW 50501, 'No tiene permiso para gestionar rutas.', 1;

    SET @nombre = NULLIF(LTRIM(RTRIM(@nombre)), N'');

    IF @ruta_id IS NULL OR @ruta_id < 1
       OR @nombre IS NULL
       OR @origen_id IS NULL OR @origen_id < 1
       OR @destino_id IS NULL OR @destino_id < 1
       OR @origen_id = @destino_id
       OR @distancia_km IS NULL OR @distancia_km <= 0
       OR @duracion_estimada_min IS NULL OR @duracion_estimada_min <= 0
        THROW 50502, 'Datos de la ruta inválidos (origen y destino deben ser distintos y las magnitudes positivas).', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_act_ruta;

    BEGIN TRY
        DECLARE @prev_nombre NVARCHAR(150), @prev_origen INT, @prev_destino INT, @activo BIT;
        SELECT @prev_nombre = nombre,
               @prev_origen = origen_id,
               @prev_destino = destino_id,
               @activo = activo
        FROM dbo.rutas WITH (UPDLOCK, HOLDLOCK)
        WHERE ruta_id = @ruta_id;

        IF @activo IS NULL
            THROW 50503, 'Ruta no encontrada.', 1;
        IF @activo = 0
            THROW 50506, 'No se puede editar una ruta inactiva.', 1;

        IF EXISTS (
            SELECT 1 FROM dbo.viajes
            WHERE ruta_id = @ruta_id AND estado IN ('PLANIFICADO', 'EN_CURSO')
        )
            THROW 50505, 'No se puede modificar una ruta asociada a un viaje planificado o en curso.', 1;

        IF (@prev_origen <> @origen_id OR @prev_destino <> @destino_id)
           AND EXISTS (SELECT 1 FROM dbo.viajes WHERE ruta_id = @ruta_id)
            THROW 50505, 'No se puede cambiar el origen o destino de una ruta con historial de viajes registrados.', 1;

        IF NOT EXISTS (SELECT 1 FROM dbo.ubicaciones WHERE ubicacion_id = @origen_id AND activo = 1)
           OR NOT EXISTS (SELECT 1 FROM dbo.ubicaciones WHERE ubicacion_id = @destino_id AND activo = 1)
            THROW 50507, 'Las ubicaciones de origen y destino deben existir y estar activas.', 1;

        IF EXISTS (
            SELECT 1 FROM dbo.rutas WITH (UPDLOCK, HOLDLOCK)
            WHERE ruta_id <> @ruta_id AND origen_id = @origen_id AND destino_id = @destino_id AND nombre = @nombre
        )
            THROW 50504, 'Ya existe otra ruta con ese nombre entre el mismo origen y destino.', 1;

        UPDATE dbo.rutas
        SET nombre = @nombre,
            origen_id = @origen_id,
            destino_id = @destino_id,
            distancia_km = @distancia_km,
            duracion_estimada_min = @duracion_estimada_min
        WHERE ruta_id = @ruta_id;

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (
            @actor_id, N'rutas', CONVERT(NVARCHAR(100), @ruta_id), 'UPDATE',
            CONCAT(N'Nombre: ', @prev_nombre, N'; Origen: ', @prev_origen, N'; Destino: ', @prev_destino),
            CONCAT(N'Nombre: ', @nombre, N'; Origen: ', @origen_id, N'; Destino: ', @destino_id)
        );

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION sp_act_ruta;
        THROW;
    END CATCH;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_desactivar_ruta
    @ruta_id INT
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@actor_id, N'RUTAS_GESTIONAR') = 0
        THROW 50501, 'No tiene permiso para gestionar rutas.', 1;

    IF @ruta_id IS NULL OR @ruta_id < 1
        THROW 50502, 'Identificador de ruta inválido.', 1;

    DECLARE @started_tran BIT = CASE WHEN @@TRANCOUNT = 0 THEN 1 ELSE 0 END;
    IF @started_tran = 1 BEGIN TRANSACTION; ELSE SAVE TRANSACTION sp_des_ruta;

    BEGIN TRY
        DECLARE @nombre NVARCHAR(150), @activo BIT;
        SELECT @nombre = nombre, @activo = activo
        FROM dbo.rutas WITH (UPDLOCK, HOLDLOCK)
        WHERE ruta_id = @ruta_id;

        IF @activo IS NULL
            THROW 50503, 'Ruta no encontrada.', 1;
        IF @activo = 0
            THROW 50506, 'La ruta ya se encuentra inactiva.', 1;

        IF EXISTS (
            SELECT 1 FROM dbo.viajes
            WHERE ruta_id = @ruta_id AND estado IN ('PLANIFICADO', 'EN_CURSO')
        )
            THROW 50505, 'No se puede desactivar una ruta asociada a un viaje planificado o en curso.', 1;

        UPDATE dbo.rutas
        SET activo = 0
        WHERE ruta_id = @ruta_id;

        INSERT INTO dbo.auditorias (usuario_id, entidad, registro_id, accion, valores_anteriores, valores_nuevos)
        VALUES (
            @actor_id, N'rutas', CONVERT(NVARCHAR(100), @ruta_id), 'DEACTIVATE',
            CONCAT(N'Nombre: ', @nombre, N'; Activo: 1'),
            N'Activo: 0'
        );

        IF @started_tran = 1 COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF XACT_STATE() = -1 AND @started_tran = 1 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 1 AND @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        ELSE IF @started_tran = 0 AND XACT_STATE() = 1 ROLLBACK TRANSACTION sp_des_ruta;
        THROW;
    END CATCH;
END;
GO
