/*
  Contratos de lectura para los dominios restantes.
  Complementa 01_permissions_and_core_contracts.sql y solo se ejecuta en Test.
*/
-- Contexto establecido por conexion cliente

IF DB_NAME() NOT IN (N'TransportesSegurosSA', N'TransportesSegurosSA_Test', N'TransportesSegurosSA_Staging')
    THROW 50920, 'Este contrato solo puede ejecutarse en una base autorizada de TransportesSegurosSA.', 1;
GO

MERGE dbo.permisos AS target
USING (VALUES
    (N'FLOTAS_VER', N'Consultar flota', N'Consultar vehículos y conductores'),
    (N'RUTAS_VER', N'Consultar rutas', N'Consultar rutas y ubicaciones relacionadas'),
    (N'VIAJES_VER', N'Consultar viajes', N'Consultar viajes, paradas y asignaciones'),
    (N'ENTREGAS_VER', N'Consultar entregas', N'Consultar entregas y receptor efectivo'),
    (N'INCIDENCIAS_VER', N'Consultar incidencias', N'Consultar incidencias registradas'),
    (N'DOCUMENTOS_VER', N'Consultar documentos', N'Consultar documentos asociados a solicitudes'),
    (N'AUDITORIA_VER', N'Consultar historial de actividad', N'Ver historial de actividad')
) AS source(codigo, nombre, descripcion)
ON target.codigo = source.codigo
WHEN MATCHED THEN UPDATE SET nombre = source.nombre, descripcion = source.descripcion
WHEN NOT MATCHED BY TARGET THEN INSERT (codigo, nombre, descripcion) VALUES (source.codigo, source.nombre, source.descripcion);
GO

CREATE OR ALTER PROCEDURE dbo.sp_listar_vehiculos
    @estado VARCHAR(20) = NULL, @solo_activos BIT = NULL, @buscar NVARCHAR(80) = NULL,
    @pagina INT = 1, @por_pagina INT = 20, @total_registros INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id')), @offset BIGINT;
    IF dbo.fn_tiene_permiso(@usuario_id, N'FLOTAS_VER') = 0 THROW 50103, N'No tiene permiso para consultar flota.', 1;
    IF @pagina < 1 OR @por_pagina NOT BETWEEN 1 AND 100 THROW 50102, N'Paginación inválida.', 1;
    IF @estado IS NOT NULL AND @estado NOT IN ('DISPONIBLE','ASIGNADO','MANTENIMIENTO','FUERA_SERVICIO') THROW 50102, N'Estado de vehículo inválido.', 1;
    SET @buscar = NULLIF(LTRIM(RTRIM(@buscar)), N''); SET @offset = CONVERT(BIGINT, @pagina - 1) * @por_pagina;
    SELECT @total_registros = COUNT(*) FROM dbo.vehiculos WHERE (@estado IS NULL OR estado=@estado) AND (@solo_activos IS NULL OR activo=@solo_activos) AND (@buscar IS NULL OR placa LIKE N'%'+@buscar+N'%' OR marca LIKE N'%'+@buscar+N'%' OR modelo LIKE N'%'+@buscar+N'%');
    SELECT vehiculo_id, placa, marca, modelo, anio, capacidad_carga_kg, capacidad_volumen_m3, kilometraje_actual, estado, activo
    FROM dbo.vehiculos WHERE (@estado IS NULL OR estado=@estado) AND (@solo_activos IS NULL OR activo=@solo_activos) AND (@buscar IS NULL OR placa LIKE N'%'+@buscar+N'%' OR marca LIKE N'%'+@buscar+N'%' OR modelo LIKE N'%'+@buscar+N'%')
    ORDER BY placa, vehiculo_id OFFSET @offset ROWS FETCH NEXT @por_pagina ROWS ONLY;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_listar_conductores
    @estado VARCHAR(20) = NULL, @solo_activos BIT = NULL, @buscar NVARCHAR(120) = NULL,
    @pagina INT = 1, @por_pagina INT = 20, @total_registros INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id')), @offset BIGINT;
    IF dbo.fn_tiene_permiso(@usuario_id, N'FLOTAS_VER') = 0 THROW 50103, N'No tiene permiso para consultar flota.', 1;
    IF @pagina < 1 OR @por_pagina NOT BETWEEN 1 AND 100 THROW 50102, N'Paginación inválida.', 1;
    IF @estado IS NOT NULL AND @estado NOT IN ('DISPONIBLE','ASIGNADO','INACTIVO') THROW 50102, N'Estado de conductor inválido.', 1;
    SET @buscar = NULLIF(LTRIM(RTRIM(@buscar)), N''); SET @offset = CONVERT(BIGINT, @pagina - 1) * @por_pagina;
    SELECT @total_registros = COUNT(*) FROM dbo.conductores WHERE (@estado IS NULL OR estado_operativo=@estado) AND (@solo_activos IS NULL OR activo=@solo_activos) AND (@buscar IS NULL OR identificacion LIKE N'%'+@buscar+N'%' OR nombre LIKE N'%'+@buscar+N'%' OR primer_apellido LIKE N'%'+@buscar+N'%' OR numero_licencia LIKE N'%'+@buscar+N'%');
    SELECT conductor_id, usuario_id, identificacion, nombre, primer_apellido, segundo_apellido, telefono, correo, numero_licencia, fecha_vencimiento_licencia, estado_operativo, activo
    FROM dbo.conductores WHERE (@estado IS NULL OR estado_operativo=@estado) AND (@solo_activos IS NULL OR activo=@solo_activos) AND (@buscar IS NULL OR identificacion LIKE N'%'+@buscar+N'%' OR nombre LIKE N'%'+@buscar+N'%' OR primer_apellido LIKE N'%'+@buscar+N'%' OR numero_licencia LIKE N'%'+@buscar+N'%')
    ORDER BY nombre, primer_apellido, conductor_id OFFSET @offset ROWS FETCH NEXT @por_pagina ROWS ONLY;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_listar_rutas
    @origen_id INT = NULL, @destino_id INT = NULL, @solo_activas BIT = NULL, @buscar NVARCHAR(120) = NULL,
    @pagina INT = 1, @por_pagina INT = 20, @total_registros INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON;
    DECLARE @usuario_id INT = TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id')), @offset BIGINT;
    IF dbo.fn_tiene_permiso(@usuario_id, N'RUTAS_VER') = 0 THROW 50103, N'No tiene permiso para consultar rutas.', 1;
    IF @pagina < 1 OR @por_pagina NOT BETWEEN 1 AND 100 OR (@origen_id IS NOT NULL AND @origen_id=@destino_id) THROW 50102, N'Filtros o paginación inválidos.', 1;
    SET @buscar = NULLIF(LTRIM(RTRIM(@buscar)), N''); SET @offset = CONVERT(BIGINT, @pagina - 1) * @por_pagina;
    SELECT @total_registros = COUNT(*) FROM dbo.rutas AS r JOIN dbo.ubicaciones AS o ON o.ubicacion_id=r.origen_id JOIN dbo.ubicaciones AS d ON d.ubicacion_id=r.destino_id WHERE (@origen_id IS NULL OR r.origen_id=@origen_id) AND (@destino_id IS NULL OR r.destino_id=@destino_id) AND (@solo_activas IS NULL OR r.activo=@solo_activas) AND (@buscar IS NULL OR r.nombre LIKE N'%'+@buscar+N'%' OR o.nombre LIKE N'%'+@buscar+N'%' OR d.nombre LIKE N'%'+@buscar+N'%');
    SELECT r.ruta_id, r.nombre, r.origen_id, o.nombre AS origen_nombre, r.destino_id, d.nombre AS destino_nombre, r.distancia_km, r.duracion_estimada_min, r.activo
    FROM dbo.rutas AS r JOIN dbo.ubicaciones AS o ON o.ubicacion_id=r.origen_id JOIN dbo.ubicaciones AS d ON d.ubicacion_id=r.destino_id
    WHERE (@origen_id IS NULL OR r.origen_id=@origen_id) AND (@destino_id IS NULL OR r.destino_id=@destino_id) AND (@solo_activas IS NULL OR r.activo=@solo_activas) AND (@buscar IS NULL OR r.nombre LIKE N'%'+@buscar+N'%' OR o.nombre LIKE N'%'+@buscar+N'%' OR d.nombre LIKE N'%'+@buscar+N'%')
    ORDER BY r.nombre, r.ruta_id OFFSET @offset ROWS FETCH NEXT @por_pagina ROWS ONLY;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_listar_tipos_incidencia @solo_activos BIT = 1
AS
BEGIN
    SET NOCOUNT ON; DECLARE @usuario_id INT=TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@usuario_id,N'CATALOGOS_VER')=0 THROW 50103,N'No tiene permiso para consultar catálogos.',1;
    SELECT tipo_incidencia_id,nombre,descripcion,activo FROM dbo.tipos_incidencia WHERE @solo_activos IS NULL OR activo=@solo_activos ORDER BY nombre,tipo_incidencia_id;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_listar_tipos_documento @solo_activos BIT = 1
AS
BEGIN
    SET NOCOUNT ON; DECLARE @usuario_id INT=TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@usuario_id,N'CATALOGOS_VER')=0 THROW 50103,N'No tiene permiso para consultar catálogos.',1;
    SELECT tipo_documento_id,nombre,descripcion,activo FROM dbo.tipos_documento WHERE @solo_activos IS NULL OR activo=@solo_activos ORDER BY nombre,tipo_documento_id;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_listar_viajes
    @estado VARCHAR(20) = NULL, @desde DATETIME2 = NULL, @hasta DATETIME2 = NULL,
    @pagina INT = 1, @por_pagina INT = 20, @total_registros INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON; DECLARE @usuario_id INT=TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id')), @offset BIGINT;
    IF dbo.fn_tiene_permiso(@usuario_id,N'VIAJES_VER')=0 THROW 50103,N'No tiene permiso para consultar viajes.',1;
    IF @pagina<1 OR @por_pagina NOT BETWEEN 1 AND 100 OR (@desde IS NOT NULL AND @hasta IS NOT NULL AND @desde>@hasta) THROW 50102,N'Filtros o paginación inválidos.',1;
    IF @estado IS NOT NULL AND @estado NOT IN ('PLANIFICADO','EN_CURSO','FINALIZADO','CANCELADO') THROW 50102,N'Estado de viaje inválido.',1;
    SET @offset=CONVERT(BIGINT,@pagina-1)*@por_pagina;
    SELECT @total_registros=COUNT(*) FROM dbo.viajes WHERE (@estado IS NULL OR estado=@estado) AND (@desde IS NULL OR fecha_programada_salida>=@desde) AND (@hasta IS NULL OR fecha_programada_salida<DATEADD(DAY,1,@hasta));
    SELECT v.viaje_id,v.codigo_viaje,v.estado,v.fecha_programada_salida,v.fecha_salida_real,v.fecha_llegada_real,ve.vehiculo_id,ve.placa,c.conductor_id,CONCAT(c.nombre,N' ',c.primer_apellido) AS conductor_nombre,r.ruta_id,r.nombre AS ruta_nombre
    FROM dbo.viajes AS v JOIN dbo.vehiculos AS ve ON ve.vehiculo_id=v.vehiculo_id JOIN dbo.conductores AS c ON c.conductor_id=v.conductor_id JOIN dbo.rutas AS r ON r.ruta_id=v.ruta_id
    WHERE (@estado IS NULL OR v.estado=@estado) AND (@desde IS NULL OR v.fecha_programada_salida>=@desde) AND (@hasta IS NULL OR v.fecha_programada_salida<DATEADD(DAY,1,@hasta))
    ORDER BY v.fecha_programada_salida DESC,v.viaje_id DESC OFFSET @offset ROWS FETCH NEXT @por_pagina ROWS ONLY;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_obtener_viaje @viaje_id INT
AS
BEGIN
    SET NOCOUNT ON; DECLARE @usuario_id INT=TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id'));
    IF dbo.fn_tiene_permiso(@usuario_id,N'VIAJES_VER')=0 THROW 50103,N'No tiene permiso para consultar viajes.',1;
    IF @viaje_id IS NULL OR @viaje_id<1 THROW 50102,N'Identificador de viaje inválido.',1;
    SELECT v.viaje_id,v.codigo_viaje,v.estado,v.fecha_programada_salida,v.fecha_salida_real,v.fecha_llegada_real,v.observaciones,ve.vehiculo_id,ve.placa,c.conductor_id,CONCAT(c.nombre,N' ',c.primer_apellido) AS conductor_nombre,r.ruta_id,r.nombre AS ruta_nombre
    FROM dbo.viajes AS v JOIN dbo.vehiculos AS ve ON ve.vehiculo_id=v.vehiculo_id JOIN dbo.conductores AS c ON c.conductor_id=v.conductor_id JOIN dbo.rutas AS r ON r.ruta_id=v.ruta_id WHERE v.viaje_id=@viaje_id;
    IF @@ROWCOUNT=0 THROW 50101,N'Viaje no encontrado.',1;
    SELECT p.parada_id,p.orden,p.tipo_parada,p.estado,p.llegada_programada,p.llegada_real,p.salida_real,p.observaciones,u.ubicacion_id,u.nombre AS ubicacion_nombre,u.direccion FROM dbo.paradas_viaje AS p JOIN dbo.ubicaciones AS u ON u.ubicacion_id=p.ubicacion_id WHERE p.viaje_id=@viaje_id ORDER BY p.orden;
    SELECT vs.viaje_solicitud_id,vs.solicitud_id,s.codigo_solicitud,s.estado AS estado_solicitud,vs.estado AS estado_asignacion,vs.parada_recogida_id,vs.parada_entrega_id FROM dbo.viaje_solicitudes AS vs JOIN dbo.solicitudes AS s ON s.solicitud_id=vs.solicitud_id WHERE vs.viaje_id=@viaje_id ORDER BY vs.viaje_solicitud_id;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_listar_entregas
    @estado VARCHAR(15)=NULL,@viaje_id INT=NULL,@desde DATETIME2=NULL,@hasta DATETIME2=NULL,@pagina INT=1,@por_pagina INT=20,@total_registros INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON; DECLARE @usuario_id INT=TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id')), @offset BIGINT;
    IF dbo.fn_tiene_permiso(@usuario_id,N'ENTREGAS_VER')=0 THROW 50103,N'No tiene permiso para consultar entregas.',1;
    IF @pagina<1 OR @por_pagina NOT BETWEEN 1 AND 100 OR (@desde IS NOT NULL AND @hasta IS NOT NULL AND @desde>@hasta) THROW 50102,N'Filtros o paginación inválidos.',1;
    IF @estado IS NOT NULL AND @estado NOT IN ('ENTREGADA','FALLIDA') THROW 50102,N'Estado de entrega inválido.',1;
    SET @offset=CONVERT(BIGINT,@pagina-1)*@por_pagina;
    SELECT @total_registros=COUNT(*) FROM dbo.entregas AS e JOIN dbo.viaje_solicitudes AS vs ON vs.viaje_solicitud_id=e.viaje_solicitud_id WHERE (@estado IS NULL OR e.estado=@estado) AND (@viaje_id IS NULL OR vs.viaje_id=@viaje_id) AND (@desde IS NULL OR e.fecha_resultado>=@desde) AND (@hasta IS NULL OR e.fecha_resultado<DATEADD(DAY,1,@hasta));
    SELECT e.entrega_id,e.estado,e.fecha_resultado,e.receptor_nombre,e.receptor_identificacion,e.receptor_telefono,e.fecha_recepcion,e.comprobante_url,e.motivo_fallo,e.observaciones,vs.viaje_solicitud_id,vs.viaje_id,s.solicitud_id,s.codigo_solicitud
    FROM dbo.entregas AS e JOIN dbo.viaje_solicitudes AS vs ON vs.viaje_solicitud_id=e.viaje_solicitud_id JOIN dbo.solicitudes AS s ON s.solicitud_id=vs.solicitud_id
    WHERE (@estado IS NULL OR e.estado=@estado) AND (@viaje_id IS NULL OR vs.viaje_id=@viaje_id) AND (@desde IS NULL OR e.fecha_resultado>=@desde) AND (@hasta IS NULL OR e.fecha_resultado<DATEADD(DAY,1,@hasta))
    ORDER BY e.fecha_resultado DESC,e.entrega_id DESC OFFSET @offset ROWS FETCH NEXT @por_pagina ROWS ONLY;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_listar_incidencias
    @solicitud_id INT=NULL,@estado VARCHAR(20)=NULL,@pagina INT=1,@por_pagina INT=20,@total_registros INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON; DECLARE @usuario_id INT=TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id')), @offset BIGINT;
    IF dbo.fn_tiene_permiso(@usuario_id,N'INCIDENCIAS_VER')=0 THROW 50103,N'No tiene permiso para consultar incidencias.',1;
    IF @pagina<1 OR @por_pagina NOT BETWEEN 1 AND 100 THROW 50102,N'Paginación inválida.',1;
    IF @estado IS NOT NULL AND @estado NOT IN ('ABIERTA','EN_ATENCION','RESUELTA') THROW 50102,N'Estado de incidencia inválido.',1;
    SET @offset=CONVERT(BIGINT,@pagina-1)*@por_pagina;
    SELECT @total_registros=COUNT(*) FROM dbo.incidencias WHERE (@solicitud_id IS NULL OR solicitud_id=@solicitud_id) AND (@estado IS NULL OR estado=@estado);
    SELECT i.incidencia_id,i.solicitud_id,i.descripcion,i.estado,i.fecha_reporte,i.fecha_resolucion,i.resolucion,ti.tipo_incidencia_id,ti.nombre AS tipo_incidencia,u.usuario_id,u.nombre_usuario AS responsable
    FROM dbo.incidencias AS i JOIN dbo.tipos_incidencia AS ti ON ti.tipo_incidencia_id=i.tipo_incidencia_id JOIN dbo.usuarios AS u ON u.usuario_id=i.responsable_usuario_id
    WHERE (@solicitud_id IS NULL OR i.solicitud_id=@solicitud_id) AND (@estado IS NULL OR i.estado=@estado)
    ORDER BY i.fecha_reporte DESC,i.incidencia_id DESC OFFSET @offset ROWS FETCH NEXT @por_pagina ROWS ONLY;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_listar_documentos @solicitud_id INT,@pagina INT=1,@por_pagina INT=20,@total_registros INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON; DECLARE @usuario_id INT=TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id')), @offset BIGINT;
    IF dbo.fn_tiene_permiso(@usuario_id,N'DOCUMENTOS_VER')=0 THROW 50103,N'No tiene permiso para consultar documentos.',1;
    IF @solicitud_id IS NULL OR @solicitud_id<1 OR @pagina<1 OR @por_pagina NOT BETWEEN 1 AND 100 THROW 50102,N'Parámetros inválidos.',1;
    SET @offset=CONVERT(BIGINT,@pagina-1)*@por_pagina;
    SELECT @total_registros=COUNT(*) FROM dbo.documentos WHERE solicitud_id=@solicitud_id;
    SELECT d.documento_id,d.solicitud_id,d.numero_referencia,d.fecha_documento,d.archivo_url,d.observaciones,d.fecha_registro,td.tipo_documento_id,td.nombre AS tipo_documento
    FROM dbo.documentos AS d JOIN dbo.tipos_documento AS td ON td.tipo_documento_id=d.tipo_documento_id WHERE d.solicitud_id=@solicitud_id
    ORDER BY d.fecha_registro DESC,d.documento_id DESC OFFSET @offset ROWS FETCH NEXT @por_pagina ROWS ONLY;
END;
GO

CREATE OR ALTER PROCEDURE dbo.sp_listar_auditorias
    @entidad NVARCHAR(100)=NULL,@registro_id NVARCHAR(100)=NULL,@desde DATETIME2=NULL,@hasta DATETIME2=NULL,@pagina INT=1,@por_pagina INT=20,@total_registros INT OUTPUT
AS
BEGIN
    SET NOCOUNT ON; DECLARE @usuario_id INT=TRY_CONVERT(INT, SESSION_CONTEXT(N'usuario_id')), @offset BIGINT;
    IF dbo.fn_tiene_permiso(@usuario_id,N'AUDITORIA_VER')=0 THROW 50103,N'No tiene permiso para consultar el historial de actividad.',1;
    IF @pagina<1 OR @por_pagina NOT BETWEEN 1 AND 100 OR (@desde IS NOT NULL AND @hasta IS NOT NULL AND @desde>@hasta) THROW 50102,N'Filtros o paginación inválidos.',1;
    SET @entidad=NULLIF(LTRIM(RTRIM(@entidad)),N''); SET @registro_id=NULLIF(LTRIM(RTRIM(@registro_id)),N''); SET @offset=CONVERT(BIGINT,@pagina-1)*@por_pagina;
    SELECT @total_registros=COUNT(*) FROM dbo.auditorias WHERE (@entidad IS NULL OR entidad=@entidad) AND (@registro_id IS NULL OR registro_id=@registro_id) AND (@desde IS NULL OR fecha_hora>=@desde) AND (@hasta IS NULL OR fecha_hora<DATEADD(DAY,1,@hasta));
    SELECT a.auditoria_id,a.entidad,a.registro_id,a.accion,a.fecha_hora,a.usuario_id,u.nombre_usuario AS usuario
    FROM dbo.auditorias AS a LEFT JOIN dbo.usuarios AS u ON u.usuario_id=a.usuario_id
    WHERE (@entidad IS NULL OR a.entidad=@entidad) AND (@registro_id IS NULL OR a.registro_id=@registro_id) AND (@desde IS NULL OR a.fecha_hora>=@desde) AND (@hasta IS NULL OR a.fecha_hora<DATEADD(DAY,1,@hasta))
    ORDER BY a.fecha_hora DESC,a.auditoria_id DESC OFFSET @offset ROWS FETCH NEXT @por_pagina ROWS ONLY;
END;
GO
