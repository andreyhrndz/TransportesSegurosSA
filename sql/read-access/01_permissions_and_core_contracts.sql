/*
  Contratos SQL propuestos para sql-read-access-contracts.
  No ejecutar en TransportesSegurosSA sin revisión y despliegue DBA aprobados.
  Requiere dbo.fn_tiene_permiso y SESSION_CONTEXT(N'usuario_id') existentes.
*/
USE TransportesSegurosSA;
GO

SET XACT_ABORT ON;
GO

/* Permisos individuales de lectura. No introduce roles ni concede SELECT directo. */
MERGE dbo.permisos AS target
USING (VALUES
    (N'CLIENTES_VER', N'Consultar clientes', N'Consultar directorio y detalle de clientes'),
    (N'UBICACIONES_VER', N'Consultar ubicaciones', N'Consultar ubicaciones activas y su detalle'),
    (N'CATALOGOS_VER', N'Consultar catálogos', N'Consultar tipos de mercancía, incidencia y documento'),
    (N'SOLICITUDES_VER', N'Consultar solicitudes', N'Consultar solicitudes y mercancías asociadas'),
    (N'NOTIFICACIONES_VER', N'Consultar notificaciones', N'Consultar historial de envío de notificaciones'),
    (N'USUARIOS_VER', N'Consultar usuarios', N'Consultar cuentas y permisos individuales sin hashes')
) AS source(codigo, nombre, descripcion)
ON target.codigo = source.codigo
WHEN MATCHED THEN UPDATE SET
    nombre = source.nombre,
    descripcion = source.descripcion
WHEN NOT MATCHED BY TARGET THEN
    INSERT (codigo, nombre, descripcion) VALUES (source.codigo, source.nombre, source.descripcion);
GO

CREATE OR ALTER PROCEDURE dbo.sp_listar_clientes
    @buscar NVARCHAR(120) = NULL,
    @solo_activos BIT = NULL,
    @pagina INT = 1,
    @por_pagina INT = 20,
    @total_registros INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    DECLARE @offset BIGINT;

    IF dbo.fn_tiene_permiso(@usuario_id, N'CLIENTES_VER') = 0
        THROW 50103, 'No tiene permiso para consultar clientes.', 1;
    IF @pagina < 1 OR @por_pagina NOT BETWEEN 1 AND 100
        THROW 50102, 'Paginación inválida.', 1;

    SET @buscar = NULLIF(LTRIM(RTRIM(@buscar)), N'');
    SET @offset = CONVERT(BIGINT, @pagina - 1) * @por_pagina;

    SELECT @total_registros = COUNT(*)
    FROM dbo.clientes AS c
    LEFT JOIN dbo.clientes_fisicos AS f ON f.cliente_id = c.cliente_id
    LEFT JOIN dbo.clientes_juridicos AS j ON j.cliente_id = c.cliente_id
    WHERE (@solo_activos IS NULL OR c.activo = @solo_activos)
      AND (
          @buscar IS NULL OR c.identificacion LIKE N'%' + @buscar + N'%'
          OR c.correo LIKE N'%' + @buscar + N'%'
          OR f.nombre LIKE N'%' + @buscar + N'%'
          OR f.primer_apellido LIKE N'%' + @buscar + N'%'
          OR j.razon_social LIKE N'%' + @buscar + N'%'
          OR j.nombre_comercial LIKE N'%' + @buscar + N'%'
      );

    SELECT
        c.cliente_id,
        c.identificacion,
        CASE WHEN f.cliente_id IS NOT NULL THEN N'FISICA' ELSE N'JURIDICA' END AS tipo_cliente,
        CASE WHEN f.cliente_id IS NOT NULL
            THEN CONCAT(f.nombre, N' ', f.primer_apellido, N' ', COALESCE(f.segundo_apellido, N''))
            ELSE COALESCE(j.nombre_comercial, j.razon_social) END AS nombre,
        c.correo,
        c.telefono,
        c.activo
    FROM dbo.clientes AS c
    LEFT JOIN dbo.clientes_fisicos AS f ON f.cliente_id = c.cliente_id
    LEFT JOIN dbo.clientes_juridicos AS j ON j.cliente_id = c.cliente_id
    WHERE (@solo_activos IS NULL OR c.activo = @solo_activos)
      AND (
          @buscar IS NULL OR c.identificacion LIKE N'%' + @buscar + N'%'
          OR c.correo LIKE N'%' + @buscar + N'%'
          OR f.nombre LIKE N'%' + @buscar + N'%'
          OR f.primer_apellido LIKE N'%' + @buscar + N'%'
          OR j.razon_social LIKE N'%' + @buscar + N'%'
          OR j.nombre_comercial LIKE N'%' + @buscar + N'%'
      )
    ORDER BY c.cliente_id DESC
    OFFSET @offset ROWS FETCH NEXT @por_pagina ROWS ONLY;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_obtener_cliente
    @cliente_id INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));

    IF dbo.fn_tiene_permiso(@usuario_id, N'CLIENTES_VER') = 0
        THROW 50103, 'No tiene permiso para consultar clientes.', 1;
    IF @cliente_id IS NULL OR @cliente_id < 1
        THROW 50102, 'Identificador de cliente inválido.', 1;

    SELECT
        c.cliente_id, c.identificacion, c.correo, c.telefono, c.direccion, c.activo, c.fecha_registro,
        CASE WHEN f.cliente_id IS NOT NULL THEN N'FISICA' ELSE N'JURIDICA' END AS tipo_cliente,
        f.nombre, f.primer_apellido, f.segundo_apellido,
        j.razon_social, j.nombre_comercial, j.representante_legal
    FROM dbo.clientes AS c
    LEFT JOIN dbo.clientes_fisicos AS f ON f.cliente_id = c.cliente_id
    LEFT JOIN dbo.clientes_juridicos AS j ON j.cliente_id = c.cliente_id
    WHERE c.cliente_id = @cliente_id;

    IF @@ROWCOUNT = 0
        THROW 50101, 'Cliente no encontrado.', 1;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_listar_ubicaciones
    @buscar NVARCHAR(120) = NULL,
    @solo_activos BIT = 1,
    @pagina INT = 1,
    @por_pagina INT = 50,
    @total_registros INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    DECLARE @offset BIGINT;

    IF dbo.fn_tiene_permiso(@usuario_id, N'UBICACIONES_VER') = 0
        THROW 50103, 'No tiene permiso para consultar ubicaciones.', 1;
    IF @pagina < 1 OR @por_pagina NOT BETWEEN 1 AND 100
        THROW 50102, 'Paginación inválida.', 1;

    SET @buscar = NULLIF(LTRIM(RTRIM(@buscar)), N'');
    SET @offset = CONVERT(BIGINT, @pagina - 1) * @por_pagina;

    SELECT @total_registros = COUNT(*)
    FROM dbo.ubicaciones
    WHERE (@solo_activos IS NULL OR activo = @solo_activos)
      AND (@buscar IS NULL OR nombre LIKE N'%' + @buscar + N'%' OR direccion LIKE N'%' + @buscar + N'%');

    SELECT ubicacion_id, nombre, direccion, latitud, longitud, activo
    FROM dbo.ubicaciones
    WHERE (@solo_activos IS NULL OR activo = @solo_activos)
      AND (@buscar IS NULL OR nombre LIKE N'%' + @buscar + N'%' OR direccion LIKE N'%' + @buscar + N'%')
    ORDER BY nombre, ubicacion_id
    OFFSET @offset ROWS FETCH NEXT @por_pagina ROWS ONLY;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_listar_tipos_mercancia
    @solo_activos BIT = 1
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));

    IF dbo.fn_tiene_permiso(@usuario_id, N'CATALOGOS_VER') = 0
        THROW 50103, 'No tiene permiso para consultar catálogos.', 1;

    SELECT tipo_mercancia_id, nombre, descripcion, activo
    FROM dbo.tipos_mercancia
    WHERE @solo_activos IS NULL OR activo = @solo_activos
    ORDER BY nombre, tipo_mercancia_id;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_listar_solicitudes
    @buscar NVARCHAR(120) = NULL,
    @estado VARCHAR(30) = NULL,
    @cliente_id INT = NULL,
    @desde DATETIME2 = NULL,
    @hasta DATETIME2 = NULL,
    @pagina INT = 1,
    @por_pagina INT = 20,
    @total_registros INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    DECLARE @offset BIGINT;

    IF dbo.fn_tiene_permiso(@usuario_id, N'SOLICITUDES_VER') = 0
        THROW 50103, 'No tiene permiso para consultar solicitudes.', 1;
    IF @pagina < 1 OR @por_pagina NOT BETWEEN 1 AND 100 OR (@desde IS NOT NULL AND @hasta IS NOT NULL AND @desde > @hasta)
        THROW 50102, 'Filtros o paginación inválidos.', 1;
    IF @estado IS NOT NULL AND @estado NOT IN ('REGISTRADA', 'PENDIENTE_ASIGNACION', 'ASIGNADA', 'EN_TRANSITO', 'ENTREGADA', 'ENTREGA_FALLIDA', 'CANCELADA')
        THROW 50102, 'Estado de solicitud inválido.', 1;

    SET @buscar = NULLIF(LTRIM(RTRIM(@buscar)), N'');
    SET @offset = CONVERT(BIGINT, @pagina - 1) * @por_pagina;

    SELECT @total_registros = COUNT(*)
    FROM dbo.solicitudes AS s
    JOIN dbo.clientes AS c ON c.cliente_id = s.cliente_id
    WHERE (@buscar IS NULL OR s.codigo_solicitud LIKE N'%' + @buscar + N'%' OR c.identificacion LIKE N'%' + @buscar + N'%'
           OR s.destinatario_nombre LIKE N'%' + @buscar + N'%' OR s.destinatario_apellido1 LIKE N'%' + @buscar + N'%')
      AND (@estado IS NULL OR s.estado = @estado)
      AND (@cliente_id IS NULL OR s.cliente_id = @cliente_id)
      AND (@desde IS NULL OR s.fecha_solicitud >= @desde)
      AND (@hasta IS NULL OR s.fecha_solicitud < DATEADD(DAY, 1, @hasta));

    SELECT
        s.solicitud_id, s.codigo_solicitud, s.estado, s.prioridad_calculada, s.prioridad_actual,
        s.fecha_solicitud, s.fecha_entrega_requerida, s.cliente_id, c.identificacion AS cliente_identificacion,
        CASE WHEN f.cliente_id IS NOT NULL THEN CONCAT(f.nombre, N' ', f.primer_apellido)
             ELSE COALESCE(j.nombre_comercial, j.razon_social) END AS cliente_nombre,
        s.destinatario_nombre, s.destinatario_apellido1, s.destinatario_apellido2,
        origen.nombre AS origen_nombre, destino.nombre AS destino_nombre,
        vs.viaje_id, v.codigo_viaje
    FROM dbo.solicitudes AS s
    JOIN dbo.clientes AS c ON c.cliente_id = s.cliente_id
    LEFT JOIN dbo.clientes_fisicos AS f ON f.cliente_id = c.cliente_id
    LEFT JOIN dbo.clientes_juridicos AS j ON j.cliente_id = c.cliente_id
    JOIN dbo.ubicaciones AS origen ON origen.ubicacion_id = s.origen_id
    JOIN dbo.ubicaciones AS destino ON destino.ubicacion_id = s.destino_id
    LEFT JOIN dbo.viaje_solicitudes AS vs ON vs.solicitud_id = s.solicitud_id
    LEFT JOIN dbo.viajes AS v ON v.viaje_id = vs.viaje_id
    WHERE (@buscar IS NULL OR s.codigo_solicitud LIKE N'%' + @buscar + N'%' OR c.identificacion LIKE N'%' + @buscar + N'%'
           OR s.destinatario_nombre LIKE N'%' + @buscar + N'%' OR s.destinatario_apellido1 LIKE N'%' + @buscar + N'%')
      AND (@estado IS NULL OR s.estado = @estado)
      AND (@cliente_id IS NULL OR s.cliente_id = @cliente_id)
      AND (@desde IS NULL OR s.fecha_solicitud >= @desde)
      AND (@hasta IS NULL OR s.fecha_solicitud < DATEADD(DAY, 1, @hasta))
    ORDER BY s.fecha_solicitud DESC, s.solicitud_id DESC
    OFFSET @offset ROWS FETCH NEXT @por_pagina ROWS ONLY;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_obtener_solicitud
    @solicitud_id INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));

    IF dbo.fn_tiene_permiso(@usuario_id, N'SOLICITUDES_VER') = 0
        THROW 50103, 'No tiene permiso para consultar solicitudes.', 1;
    IF @solicitud_id IS NULL OR @solicitud_id < 1
        THROW 50102, 'Identificador de solicitud inválido.', 1;

    SELECT
        s.solicitud_id, s.codigo_solicitud, s.estado, s.prioridad_calculada, s.prioridad_actual,
        s.justificacion_prioridad, s.fecha_solicitud, s.fecha_entrega_requerida,
        s.destinatario_nombre, s.destinatario_apellido1, s.destinatario_apellido2,
        s.destinatario_identificacion, s.destinatario_telefono, s.instrucciones_entrega, s.observaciones,
        s.cliente_id, s.origen_id, origen.nombre AS origen_nombre, s.destino_id, destino.nombre AS destino_nombre,
        vs.viaje_solicitud_id, vs.viaje_id, v.codigo_viaje, vs.estado AS estado_asignacion
    FROM dbo.solicitudes AS s
    JOIN dbo.ubicaciones AS origen ON origen.ubicacion_id = s.origen_id
    JOIN dbo.ubicaciones AS destino ON destino.ubicacion_id = s.destino_id
    LEFT JOIN dbo.viaje_solicitudes AS vs ON vs.solicitud_id = s.solicitud_id
    LEFT JOIN dbo.viajes AS v ON v.viaje_id = vs.viaje_id
    WHERE s.solicitud_id = @solicitud_id;

    IF @@ROWCOUNT = 0
        THROW 50101, 'Solicitud no encontrada.', 1;

    SELECT m.mercancia_id, m.tipo_mercancia_id, tm.nombre AS tipo_mercancia, m.descripcion, m.peso_kg, m.volumen_m3, m.observaciones
    FROM dbo.mercancias AS m
    JOIN dbo.tipos_mercancia AS tm ON tm.tipo_mercancia_id = m.tipo_mercancia_id
    WHERE m.solicitud_id = @solicitud_id
    ORDER BY m.mercancia_id;

    SELECT h.historial_estado_id, h.estado_anterior, h.estado_nuevo, h.fecha_cambio, h.observacion,
           h.usuario_id, u.nombre_usuario
    FROM dbo.historial_estados_solicitud AS h
    LEFT JOIN dbo.usuarios AS u ON u.usuario_id = h.usuario_id
    WHERE h.solicitud_id = @solicitud_id
    ORDER BY h.fecha_cambio, h.historial_estado_id;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_listar_notificaciones
    @solicitud_id INT = NULL,
    @estado_envio VARCHAR(20) = NULL,
    @desde DATETIME2 = NULL,
    @hasta DATETIME2 = NULL,
    @pagina INT = 1,
    @por_pagina INT = 20,
    @total_registros INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    DECLARE @offset BIGINT;

    IF dbo.fn_tiene_permiso(@usuario_id, N'NOTIFICACIONES_VER') = 0
        THROW 50103, 'No tiene permiso para consultar notificaciones.', 1;
    IF @pagina < 1 OR @por_pagina NOT BETWEEN 1 AND 100 OR (@desde IS NOT NULL AND @hasta IS NOT NULL AND @desde > @hasta)
        THROW 50102, 'Filtros o paginación inválidos.', 1;
    IF @estado_envio IS NOT NULL AND @estado_envio NOT IN ('PENDIENTE', 'ENVIADA', 'ERROR')
        THROW 50102, 'Estado de envío inválido.', 1;

    SET @offset = CONVERT(BIGINT, @pagina - 1) * @por_pagina;
    SELECT @total_registros = COUNT(*)
    FROM dbo.notificaciones
    WHERE (@solicitud_id IS NULL OR solicitud_id = @solicitud_id)
      AND (@estado_envio IS NULL OR estado_envio = @estado_envio)
      AND (@desde IS NULL OR fecha_intento >= @desde)
      AND (@hasta IS NULL OR fecha_intento < DATEADD(DAY, 1, @hasta));

    SELECT notificacion_id, solicitud_id, tipo_evento, correo_destinatario, asunto, fecha_intento, fecha_envio, estado_envio
    FROM dbo.notificaciones
    WHERE (@solicitud_id IS NULL OR solicitud_id = @solicitud_id)
      AND (@estado_envio IS NULL OR estado_envio = @estado_envio)
      AND (@desde IS NULL OR fecha_intento >= @desde)
      AND (@hasta IS NULL OR fecha_intento < DATEADD(DAY, 1, @hasta))
    ORDER BY fecha_intento DESC, notificacion_id DESC
    OFFSET @offset ROWS FETCH NEXT @por_pagina ROWS ONLY;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_listar_usuarios
    @buscar NVARCHAR(120) = NULL,
    @activo BIT = NULL,
    @pagina INT = 1,
    @por_pagina INT = 20,
    @total_registros INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    DECLARE @offset BIGINT;

    IF dbo.fn_tiene_permiso(@usuario_id, N'USUARIOS_VER') = 0
        THROW 50103, 'No tiene permiso para consultar usuarios.', 1;
    IF @pagina < 1 OR @por_pagina NOT BETWEEN 1 AND 100
        THROW 50102, 'Paginación inválida.', 1;

    SET @buscar = NULLIF(LTRIM(RTRIM(@buscar)), N'');
    SET @offset = CONVERT(BIGINT, @pagina - 1) * @por_pagina;
    SELECT @total_registros = COUNT(*)
    FROM dbo.usuarios
    WHERE (@activo IS NULL OR activo = @activo)
      AND (@buscar IS NULL OR nombre_usuario LIKE N'%' + @buscar + N'%' OR correo_acceso LIKE N'%' + @buscar + N'%');

    SELECT usuario_id, nombre_usuario, correo_acceso, activo, fecha_creacion, ultimo_acceso
    FROM dbo.usuarios
    WHERE (@activo IS NULL OR activo = @activo)
      AND (@buscar IS NULL OR nombre_usuario LIKE N'%' + @buscar + N'%' OR correo_acceso LIKE N'%' + @buscar + N'%')
    ORDER BY nombre_usuario, usuario_id
    OFFSET @offset ROWS FETCH NEXT @por_pagina ROWS ONLY;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_obtener_usuario
    @usuario_id INT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @actor_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));

    IF dbo.fn_tiene_permiso(@actor_id, N'USUARIOS_VER') = 0
        THROW 50103, 'No tiene permiso para consultar usuarios.', 1;
    IF @usuario_id IS NULL OR @usuario_id < 1
        THROW 50102, 'Identificador de usuario inválido.', 1;

    SELECT usuario_id, nombre_usuario, correo_acceso, activo, fecha_creacion, ultimo_acceso
    FROM dbo.usuarios
    WHERE usuario_id = @usuario_id;

    IF @@ROWCOUNT = 0
        THROW 50101, 'Usuario no encontrado.', 1;

    SELECT p.permiso_id, p.codigo, p.nombre, p.descripcion, p.activo, up.fecha_asignacion
    FROM dbo.usuarios_permisos AS up
    JOIN dbo.permisos AS p ON p.permiso_id = up.permiso_id
    WHERE up.usuario_id = @usuario_id
    ORDER BY p.codigo;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_listar_permisos
    @solo_activos BIT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));

    IF dbo.fn_tiene_permiso(@usuario_id, N'USUARIOS_VER') = 0
        THROW 50103, 'No tiene permiso para consultar permisos.', 1;

    SELECT permiso_id, codigo, nombre, descripcion, activo
    FROM dbo.permisos
    WHERE @solo_activos IS NULL OR activo = @solo_activos
    ORDER BY codigo;
END;
GO
