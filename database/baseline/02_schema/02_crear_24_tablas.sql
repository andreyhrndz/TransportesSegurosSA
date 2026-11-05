-- ============================================================
-- 02 - CREAR LAS 24 TABLAS
-- Base de datos: TransportesSegurosSA
-- Ejecutar una sola vez en una base vacia.
-- ============================================================

USE TransportesSegurosSA;
GO

-- TABLA: clientes
CREATE TABLE dbo.clientes
(
    cliente_id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    identificacion NVARCHAR(30) NOT NULL UNIQUE,
    correo NVARCHAR(150) NOT NULL,
    telefono NVARCHAR(30) NULL,
    direccion NVARCHAR(250) NULL,
    activo BIT NOT NULL DEFAULT (1),
    fecha_registro DATETIME2 NOT NULL DEFAULT (SYSUTCDATETIME())
);
GO

-- TABLA: clientes_fisicos
CREATE TABLE dbo.clientes_fisicos
(
    cliente_id INT NOT NULL PRIMARY KEY,
    nombre NVARCHAR(100) NOT NULL,
    primer_apellido NVARCHAR(100) NOT NULL,
    segundo_apellido NVARCHAR(100) NULL
);
GO

-- TABLA: clientes_juridicos
CREATE TABLE dbo.clientes_juridicos
(
    cliente_id INT NOT NULL PRIMARY KEY,
    razon_social NVARCHAR(150) NOT NULL,
    nombre_comercial NVARCHAR(150) NULL,
    representante_legal NVARCHAR(150) NULL
);
GO

-- TABLA: ubicaciones
CREATE TABLE dbo.ubicaciones
(
    ubicacion_id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    nombre NVARCHAR(150) NOT NULL,
    direccion NVARCHAR(250) NOT NULL,
    latitud DECIMAL(9,6) NULL,
    longitud DECIMAL(9,6) NULL,
    activo BIT NOT NULL DEFAULT (1)
);
GO

-- TABLA: tipos_mercancia
CREATE TABLE dbo.tipos_mercancia
(
    tipo_mercancia_id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    nombre NVARCHAR(100) NOT NULL UNIQUE,
    descripcion NVARCHAR(250) NULL,
    activo BIT NOT NULL DEFAULT (1)
);
GO

-- TABLA: vehiculos
CREATE TABLE dbo.vehiculos
(
    vehiculo_id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    placa NVARCHAR(20) NOT NULL UNIQUE,
    marca NVARCHAR(80) NOT NULL,
    modelo NVARCHAR(80) NOT NULL,
    anio SMALLINT NOT NULL,
    capacidad_carga_kg DECIMAL(12,2) NOT NULL,
    capacidad_volumen_m3 DECIMAL(12,3) NULL,
    kilometraje_actual DECIMAL(12,1) NOT NULL DEFAULT (0),
    estado VARCHAR(20) NOT NULL DEFAULT ('DISPONIBLE'),
    activo BIT NOT NULL DEFAULT (1),
    fecha_registro DATETIME2 NOT NULL DEFAULT (SYSUTCDATETIME())
);
GO

-- TABLA: usuarios
CREATE TABLE dbo.usuarios
(
    usuario_id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    nombre_usuario NVARCHAR(80) NOT NULL UNIQUE,
    correo_acceso NVARCHAR(150) NOT NULL UNIQUE,
    password_hash NVARCHAR(255) NOT NULL,
    activo BIT NOT NULL DEFAULT (1),
    fecha_creacion DATETIME2 NOT NULL DEFAULT (SYSUTCDATETIME()),
    ultimo_acceso DATETIME2 NULL
);
GO

-- TABLA: permisos
CREATE TABLE dbo.permisos
(
    permiso_id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    codigo NVARCHAR(100) NOT NULL UNIQUE,
    nombre NVARCHAR(150) NOT NULL,
    descripcion NVARCHAR(250) NULL,
    activo BIT NOT NULL DEFAULT (1)
);
GO

-- TABLA: conductores
CREATE TABLE dbo.conductores
(
    conductor_id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    usuario_id INT NULL,
    identificacion NVARCHAR(30) NOT NULL UNIQUE,
    nombre NVARCHAR(100) NOT NULL,
    primer_apellido NVARCHAR(100) NOT NULL,
    segundo_apellido NVARCHAR(100) NULL,
    telefono NVARCHAR(30) NULL,
    correo NVARCHAR(150) NULL,
    numero_licencia NVARCHAR(50) NOT NULL UNIQUE,
    fecha_vencimiento_licencia DATE NOT NULL,
    estado_operativo VARCHAR(20) NOT NULL DEFAULT ('DISPONIBLE'),
    activo BIT NOT NULL DEFAULT (1),
    fecha_registro DATETIME2 NOT NULL DEFAULT (SYSUTCDATETIME())
);
GO

-- TABLA: rutas
CREATE TABLE dbo.rutas
(
    ruta_id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    nombre NVARCHAR(150) NOT NULL,
    origen_id INT NOT NULL,
    destino_id INT NOT NULL,
    distancia_km DECIMAL(10,2) NOT NULL,
    duracion_estimada_min INT NOT NULL,
    activo BIT NOT NULL DEFAULT (1)
);
GO

-- TABLA: tipos_incidencia
CREATE TABLE dbo.tipos_incidencia
(
    tipo_incidencia_id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    nombre NVARCHAR(100) NOT NULL UNIQUE,
    descripcion NVARCHAR(250) NULL,
    activo BIT NOT NULL DEFAULT (1)
);
GO

-- TABLA: tipos_documento
CREATE TABLE dbo.tipos_documento
(
    tipo_documento_id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    nombre NVARCHAR(100) NOT NULL UNIQUE,
    descripcion NVARCHAR(250) NULL,
    activo BIT NOT NULL DEFAULT (1)
);
GO

-- TABLA: solicitudes
CREATE TABLE dbo.solicitudes
(
    solicitud_id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    codigo_solicitud NVARCHAR(30) NOT NULL UNIQUE,
    cliente_id INT NOT NULL,
    origen_id INT NOT NULL,
    destino_id INT NOT NULL,
    destinatario_nombre NVARCHAR(150) NOT NULL,
    destinatario_apellido1 NVARCHAR(100) NOT NULL,
    destinatario_apellido2 NVARCHAR(100) NOT NULL,
    destinatario_identificacion NVARCHAR(30) NULL,
    destinatario_telefono NVARCHAR(30) NULL,
    instrucciones_entrega NVARCHAR(500) NULL,
    fecha_solicitud DATETIME2 NOT NULL DEFAULT (SYSUTCDATETIME()),
    fecha_entrega_requerida DATETIME2 NOT NULL,
    prioridad_calculada VARCHAR(10) NOT NULL,
    prioridad_actual VARCHAR(10) NOT NULL,
    justificacion_prioridad NVARCHAR(500) NULL,
    estado VARCHAR(30) NOT NULL DEFAULT ('REGISTRADA'),
    observaciones NVARCHAR(500) NULL,
    fecha_creacion DATETIME2 NOT NULL DEFAULT (SYSUTCDATETIME()),
    fecha_actualizacion DATETIME2 NOT NULL DEFAULT (SYSUTCDATETIME())
);
GO

-- TABLA: mercancias
CREATE TABLE dbo.mercancias
(
    mercancia_id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    solicitud_id INT NOT NULL,
    tipo_mercancia_id INT NOT NULL,
    descripcion NVARCHAR(250) NOT NULL,
    peso_kg DECIMAL(12,2) NOT NULL,
    volumen_m3 DECIMAL(12,3) NULL,
    observaciones NVARCHAR(500) NULL
);
GO

-- TABLA: usuarios_permisos
CREATE TABLE dbo.usuarios_permisos
(
    usuario_id INT NOT NULL,
    permiso_id INT NOT NULL,
    fecha_asignacion DATETIME2 NOT NULL DEFAULT (SYSUTCDATETIME()),
    CONSTRAINT PK_usuarios_permisos PRIMARY KEY (usuario_id, permiso_id)
);
GO

-- TABLA: viajes
CREATE TABLE dbo.viajes
(
    viaje_id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    codigo_viaje NVARCHAR(30) NOT NULL UNIQUE,
    vehiculo_id INT NOT NULL,
    conductor_id INT NOT NULL,
    ruta_id INT NOT NULL,
    fecha_programada_salida DATETIME2 NOT NULL,
    fecha_salida_real DATETIME2 NULL,
    fecha_llegada_real DATETIME2 NULL,
    estado VARCHAR(20) NOT NULL DEFAULT ('PLANIFICADO'),
    observaciones NVARCHAR(500) NULL,
    fecha_creacion DATETIME2 NOT NULL DEFAULT (SYSUTCDATETIME())
);
GO

-- TABLA: paradas_viaje
CREATE TABLE dbo.paradas_viaje
(
    parada_id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    viaje_id INT NOT NULL,
    ubicacion_id INT NOT NULL,
    orden INT NOT NULL,
    tipo_parada VARCHAR(20) NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT ('PENDIENTE'),
    llegada_programada DATETIME2 NULL,
    llegada_real DATETIME2 NULL,
    salida_real DATETIME2 NULL,
    observaciones NVARCHAR(500) NULL
);
GO

-- TABLA: viaje_solicitudes
CREATE TABLE dbo.viaje_solicitudes
(
    viaje_solicitud_id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    viaje_id INT NOT NULL,
    solicitud_id INT NOT NULL UNIQUE,
    parada_recogida_id INT NOT NULL,
    parada_entrega_id INT NOT NULL,
    fecha_asignacion DATETIME2 NOT NULL DEFAULT (SYSUTCDATETIME()),
    estado VARCHAR(20) NOT NULL DEFAULT ('PLANIFICADA'),
    observaciones NVARCHAR(500) NULL
);
GO

-- TABLA: entregas
CREATE TABLE dbo.entregas
(
    entrega_id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    viaje_solicitud_id INT NOT NULL UNIQUE,
    estado VARCHAR(15) NOT NULL,
    fecha_resultado DATETIME2 NOT NULL DEFAULT (SYSUTCDATETIME()),
    receptor_nombre NVARCHAR(150) NULL,
    receptor_identificacion NVARCHAR(30) NULL,
    receptor_telefono NVARCHAR(30) NULL,
    fecha_recepcion DATETIME2 NULL,
    comprobante_url NVARCHAR(500) NULL,
    registrado_por_usuario_id INT NOT NULL,
    motivo_fallo NVARCHAR(500) NULL,
    observaciones NVARCHAR(500) NULL
);
GO

-- TABLA: incidencias
CREATE TABLE dbo.incidencias
(
    incidencia_id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    solicitud_id INT NOT NULL,
    tipo_incidencia_id INT NOT NULL,
    responsable_usuario_id INT NOT NULL,
    descripcion NVARCHAR(500) NOT NULL,
    estado VARCHAR(20) NOT NULL DEFAULT ('ABIERTA'),
    fecha_reporte DATETIME2 NOT NULL DEFAULT (SYSUTCDATETIME()),
    fecha_resolucion DATETIME2 NULL,
    resolucion NVARCHAR(500) NULL
);
GO

-- TABLA: notificaciones
CREATE TABLE dbo.notificaciones
(
    notificacion_id BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    solicitud_id INT NOT NULL,
    tipo_evento VARCHAR(30) NOT NULL,
    correo_destinatario NVARCHAR(150) NOT NULL,
    asunto NVARCHAR(250) NOT NULL,
    fecha_intento DATETIME2 NOT NULL DEFAULT (SYSUTCDATETIME()),
    fecha_envio DATETIME2 NULL,
    estado_envio VARCHAR(20) NOT NULL DEFAULT ('PENDIENTE'),
    error_envio NVARCHAR(500) NULL
);
GO

-- TABLA: documentos
CREATE TABLE dbo.documentos
(
    documento_id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    solicitud_id INT NOT NULL,
    tipo_documento_id INT NOT NULL,
    numero_referencia NVARCHAR(100) NULL,
    fecha_documento DATE NULL,
    archivo_url NVARCHAR(500) NULL,
    observaciones NVARCHAR(500) NULL,
    fecha_registro DATETIME2 NOT NULL DEFAULT (SYSUTCDATETIME())
);
GO

-- TABLA: historial_estados_solicitud
CREATE TABLE dbo.historial_estados_solicitud
(
    historial_estado_id BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    solicitud_id INT NOT NULL,
    usuario_id INT NULL,
    estado_anterior VARCHAR(30) NULL,
    estado_nuevo VARCHAR(30) NOT NULL,
    fecha_cambio DATETIME2 NOT NULL DEFAULT (SYSUTCDATETIME()),
    observacion NVARCHAR(500) NULL
);
GO

-- TABLA: auditorias
CREATE TABLE dbo.auditorias
(
    auditoria_id BIGINT IDENTITY(1,1) NOT NULL PRIMARY KEY,
    usuario_id INT NULL,
    entidad NVARCHAR(100) NOT NULL,
    registro_id NVARCHAR(100) NOT NULL,
    accion VARCHAR(20) NOT NULL,
    fecha_hora DATETIME2 NOT NULL DEFAULT (SYSUTCDATETIME()),
    valores_anteriores NVARCHAR(MAX) NULL,
    valores_nuevos NVARCHAR(MAX) NULL
);
GO
