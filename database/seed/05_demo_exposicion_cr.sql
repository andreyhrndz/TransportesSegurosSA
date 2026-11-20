-- ============================================================================
-- Reset limpio + Semilla Nacional de Mensajería y Encomiendas (Costa Rica)
-- Estilo Correos de Costa Rica / Paquetería Nacional — Transportes Seguros S.A.
--
-- SEGURIDAD:
-- - Destino EXCLUSIVO: TransportesSegurosSA_Test. PROHIBIDO en TransportesSegurosSA.
-- - Preserva usuarios (admin_proyecto, qa_operador_demo, qa_lectura_demo),
--   permisos, catálogos base y categorías de licencia Ley 9078.
-- ============================================================================

SET NOCOUNT ON;
SET XACT_ABORT ON;

IF DB_NAME() <> N'TransportesSegurosSA_Test'
    THROW 50999, N'PROHIBIDO: Este script solo puede ejecutarse en TransportesSegurosSA_Test.', 1;

DECLARE @admin INT = (SELECT TOP (1) usuario_id FROM dbo.usuarios WHERE activo = 1 ORDER BY usuario_id ASC);
EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @admin;

-- ============================================================================
-- 1. LIMPIEZA TOTAL DE DATOS OPERATIVOS Y MAESTROS ANTERIORES (solo Test)
-- ============================================================================
DELETE FROM dbo.notificaciones;
DELETE FROM dbo.documentos;
DELETE FROM dbo.incidencias;
DELETE FROM dbo.entregas;
DELETE FROM dbo.viaje_solicitudes;
DELETE FROM dbo.paradas_viaje;
DELETE FROM dbo.viajes;
DELETE FROM dbo.mercancias;
DELETE FROM dbo.historial_estados_solicitud;
DELETE FROM dbo.solicitudes;
DELETE FROM dbo.rutas;
DELETE FROM dbo.ubicaciones;
DELETE FROM dbo.conductores_licencias;
DELETE FROM dbo.conductores;
DELETE FROM dbo.vehiculos;
DELETE FROM dbo.clientes_fisicos;
DELETE FROM dbo.clientes_juridicos;
DELETE FROM dbo.clientes;

-- Reiniciar identidades para numeración limpia
DBCC CHECKIDENT ('dbo.notificaciones', RESEED, 0) WITH NO_INFOMSGS;
DBCC CHECKIDENT ('dbo.documentos', RESEED, 0) WITH NO_INFOMSGS;
DBCC CHECKIDENT ('dbo.incidencias', RESEED, 0) WITH NO_INFOMSGS;
DBCC CHECKIDENT ('dbo.entregas', RESEED, 0) WITH NO_INFOMSGS;
DBCC CHECKIDENT ('dbo.viaje_solicitudes', RESEED, 0) WITH NO_INFOMSGS;
DBCC CHECKIDENT ('dbo.paradas_viaje', RESEED, 0) WITH NO_INFOMSGS;
DBCC CHECKIDENT ('dbo.viajes', RESEED, 0) WITH NO_INFOMSGS;
DBCC CHECKIDENT ('dbo.mercancias', RESEED, 0) WITH NO_INFOMSGS;
DBCC CHECKIDENT ('dbo.historial_estados_solicitud', RESEED, 0) WITH NO_INFOMSGS;
DBCC CHECKIDENT ('dbo.solicitudes', RESEED, 0) WITH NO_INFOMSGS;
DBCC CHECKIDENT ('dbo.rutas', RESEED, 0) WITH NO_INFOMSGS;
DBCC CHECKIDENT ('dbo.ubicaciones', RESEED, 0) WITH NO_INFOMSGS;
DBCC CHECKIDENT ('dbo.conductores', RESEED, 0) WITH NO_INFOMSGS;
DBCC CHECKIDENT ('dbo.vehiculos', RESEED, 0) WITH NO_INFOMSGS;
DBCC CHECKIDENT ('dbo.clientes', RESEED, 0) WITH NO_INFOMSGS;

-- Enriquecer catálogo de tipos de mercancía para paquetería / correos
IF NOT EXISTS (SELECT 1 FROM dbo.tipos_mercancia WHERE nombre = N'Paquetería y encomiendas')
    INSERT INTO dbo.tipos_mercancia (nombre, descripcion, activo)
    VALUES (N'Paquetería y encomiendas', N'Cajas, bolsas de mensajería y pedidos de comercio electrónico.', 1);

IF NOT EXISTS (SELECT 1 FROM dbo.tipos_mercancia WHERE nombre = N'Documentos y valija')
    INSERT INTO dbo.tipos_mercancia (nombre, descripcion, activo)
    VALUES (N'Documentos y valija', N'Correspondencia certificada, contratos y valija institucional.', 1);

IF NOT EXISTS (SELECT 1 FROM dbo.tipos_mercancia WHERE nombre = N'Insumos médicos y farmacia')
    INSERT INTO dbo.tipos_mercancia (nombre, descripcion, activo)
    VALUES (N'Insumos médicos y farmacia', N'Medicamentos empacados y equipo clínico no refrigerado.', 1);

DECLARE @tmPaq INT = (SELECT TOP 1 tipo_mercancia_id FROM dbo.tipos_mercancia WHERE nombre = N'Paquetería y encomiendas');
DECLARE @tmDoc INT = (SELECT TOP 1 tipo_mercancia_id FROM dbo.tipos_mercancia WHERE nombre = N'Documentos y valija');
DECLARE @tmMed INT = (SELECT TOP 1 tipo_mercancia_id FROM dbo.tipos_mercancia WHERE nombre = N'Insumos médicos y farmacia');
DECLARE @tmElec INT = (SELECT TOP 1 tipo_mercancia_id FROM dbo.tipos_mercancia WHERE nombre LIKE N'Electr%');
DECLARE @tmAlim INT = (SELECT TOP 1 tipo_mercancia_id FROM dbo.tipos_mercancia WHERE nombre LIKE N'Alim%');

-- ============================================================================
-- 2. CENTROS DE DISTRIBUCIÓN Y SUCURSALES EN COSTA RICA (10 sedes con GPS real)
-- ============================================================================
INSERT INTO dbo.ubicaciones (nombre, direccion, latitud, longitud, activo, es_reutilizable) VALUES
(N'Centro de Clasificación Zapote (HUB GAM)', N'Zapote, 200 m este de la rotonda de las Garantías Sociales, San José', 9.921840, -84.055420, 1, 1),
(N'Sucursal San José Centro', N'Calle 2, Avenidas 1 y 3, frente al Correo Central, San José', 9.935560, -84.079380, 1, 1),
(N'Centro Logístico Alajuela (El Coyol)', N'Zona Franca El Coyol, 300 m oeste de la radial, Alajuela', 9.997850, -84.262340, 1, 1),
(N'Sucursal Heredia Centro', N'100 m norte del Parque Central Nicolás Ulloa, Heredia', 9.998520, -84.116530, 1, 1),
(N'Sucursal Cartago Centro', N'Costado sur de las Ruinas de Santiago Apóstol, Cartago', 9.864410, -83.919440, 1, 1),
(N'Sucursal Escazú - Santa Ana', N'Multiplaza Escazú, 150 m oeste sobre ruta 27, San José', 9.934720, -84.179540, 1, 1),
(N'Sucursal Regional Liberia', N'200 m sur del parque Mario Cañas Ruiz, Liberia, Guanacaste', 10.634590, -85.440670, 1, 1),
(N'Sucursal Regional Puntarenas', N'Avenida Central, 100 m este de la Catedral, Puntarenas', 9.976250, -84.838360, 1, 1),
(N'Sucursal Regional Limón', N'Avenida 2, frente al Parque Vargas, Limón Centro', 9.990740, -83.035960, 1, 1),
(N'Sucursal Regional Pérez Zeledón', N'San Isidro de El General, 150 m este del parque central, San José', 9.373810, -83.702960, 1, 1);

DECLARE @uZapote INT = (SELECT ubicacion_id FROM dbo.ubicaciones WHERE nombre = N'Centro de Clasificación Zapote (HUB GAM)');
DECLARE @uSJ INT = (SELECT ubicacion_id FROM dbo.ubicaciones WHERE nombre = N'Sucursal San José Centro');
DECLARE @uCoyol INT = (SELECT ubicacion_id FROM dbo.ubicaciones WHERE nombre = N'Centro Logístico Alajuela (El Coyol)');
DECLARE @uHeredia INT = (SELECT ubicacion_id FROM dbo.ubicaciones WHERE nombre = N'Sucursal Heredia Centro');
DECLARE @uCartago INT = (SELECT ubicacion_id FROM dbo.ubicaciones WHERE nombre = N'Sucursal Cartago Centro');
DECLARE @uEscazu INT = (SELECT ubicacion_id FROM dbo.ubicaciones WHERE nombre = N'Sucursal Escazú - Santa Ana');
DECLARE @uLiberia INT = (SELECT ubicacion_id FROM dbo.ubicaciones WHERE nombre = N'Sucursal Regional Liberia');
DECLARE @uPuntarenas INT = (SELECT ubicacion_id FROM dbo.ubicaciones WHERE nombre = N'Sucursal Regional Puntarenas');
DECLARE @uLimon INT = (SELECT ubicacion_id FROM dbo.ubicaciones WHERE nombre = N'Sucursal Regional Limón');
DECLARE @uPZ INT = (SELECT ubicacion_id FROM dbo.ubicaciones WHERE nombre = N'Sucursal Regional Pérez Zeledón');

-- ============================================================================
-- 3. RUTAS Y CORREDORES NACIONALES (7 corredores reales)
-- ============================================================================
INSERT INTO dbo.rutas (nombre, origen_id, destino_id, distancia_km, duracion_estimada_min, activo) VALUES
(N'Distribución GAM Este (Zapote → Cartago)', @uZapote, @uCartago, 22.5, 45, 1),
(N'Distribución GAM Oeste (Zapote → Escazú / Coyol)', @uZapote, @uCoyol, 26.0, 50, 1),
(N'Distribución GAM Norte (Zapote → Heredia)', @uZapote, @uHeredia, 14.5, 35, 1),
(N'Troncal Chorotega (Alajuela → Liberia)', @uCoyol, @uLiberia, 196.0, 225, 1),
(N'Troncal Pacífico Central (Alajuela → Puntarenas)', @uCoyol, @uPuntarenas, 84.0, 95, 1),
(N'Troncal Caribe Ruta 32 (Zapote → Limón)', @uZapote, @uLimon, 158.0, 190, 1),
(N'Troncal Zona Sur (Cartago → Pérez Zeledón)', @uCartago, @uPZ, 114.0, 150, 1);

DECLARE @rGamEste INT = (SELECT ruta_id FROM dbo.rutas WHERE nombre = N'Distribución GAM Este (Zapote → Cartago)');
DECLARE @rGamOeste INT = (SELECT ruta_id FROM dbo.rutas WHERE nombre = N'Distribución GAM Oeste (Zapote → Escazú / Coyol)');
DECLARE @rGamNorte INT = (SELECT ruta_id FROM dbo.rutas WHERE nombre = N'Distribución GAM Norte (Zapote → Heredia)');
DECLARE @rCaribe INT = (SELECT ruta_id FROM dbo.rutas WHERE nombre = N'Troncal Caribe Ruta 32 (Zapote → Limón)');

-- ============================================================================
-- 4. CLIENTES CORPORATIVOS Y PERSONALES DE COSTA RICA (8 clientes)
-- ============================================================================
DECLARE @cId INT;

INSERT INTO dbo.clientes (identificacion, correo, telefono, direccion, activo) VALUES (N'3-101-214509', N'despachos@farmaciaslabomba.cr.test', N'2256-4800', N'San José Centro, Avenida Central', 1);
SET @cId = SCOPE_IDENTITY();
INSERT INTO dbo.clientes_juridicos (cliente_id, razon_social, nombre_comercial, representante_legal) VALUES (@cId, N'Farmacias La Bomba de Costa Rica S.A.', N'Farmacias La Bomba', N'Dra. Patricia Calderón Monge');
DECLARE @cFarmacia INT = @cId;

INSERT INTO dbo.clientes (identificacion, correo, telefono, direccion, activo) VALUES (N'3-101-482910', N'envios@libreriauniversitaria.cr.test', N'2224-7120', N'San Pedro de Montes de Oca, San José', 1);
SET @cId = SCOPE_IDENTITY();
INSERT INTO dbo.clientes_juridicos (cliente_id, razon_social, nombre_comercial, representante_legal) VALUES (@cId, N'Librería Universitaria del Valle S.A.', N'Librería del Valle', N'Lic. Rodrigo Alvarado Campos');
DECLARE @cLibreria INT = @cId;

INSERT INTO dbo.clientes (identificacion, correo, telefono, direccion, activo) VALUES (N'3-102-653821', N'logistica@ticotech.cr.test', N'2289-3310', N'Escazú, Centro Comercial Avenida Escazú', 1);
SET @cId = SCOPE_IDENTITY();
INSERT INTO dbo.clientes_juridicos (cliente_id, razon_social, nombre_comercial, representante_legal) VALUES (@cId, N'Tienda Electrónica TicoTech Ltda.', N'TicoTech Store', N'Ing. Alejandro Quirós Umaña');
DECLARE @cTicoTech INT = @cId;

INSERT INTO dbo.clientes (identificacion, correo, telefono, direccion, activo) VALUES (N'3-101-739204', N'pedidos@cafetarrazu.cr.test', N'2546-1900', N'San Marcos de Tarrazú, San José', 1);
SET @cId = SCOPE_IDENTITY();
INSERT INTO dbo.clientes_juridicos (cliente_id, razon_social, nombre_comercial, representante_legal) VALUES (@cId, N'Cooperativa Café de Altura Tarrazú S.A.', N'Café Tarrazú', N'Ing. Mauricio Fallas Porras');
DECLARE @cCafe INT = @cId;

INSERT INTO dbo.clientes (identificacion, correo, telefono, direccion, activo) VALUES (N'3-101-812045', N'distribucion@insumosclinicos.cr.test', N'2433-9200', N'Zona Franca El Coyol, Alajuela', 1);
SET @cId = SCOPE_IDENTITY();
INSERT INTO dbo.clientes_juridicos (cliente_id, razon_social, nombre_comercial, representante_legal) VALUES (@cId, N'Insumos Clínicos del Pacífico S.A.', N'Insumos Clínicos CR', N'Dra. Valeria Méndez Herrera');
DECLARE @cClinicos INT = @cId;

INSERT INTO dbo.clientes (identificacion, correo, telefono, direccion, activo) VALUES (N'1-1482-0619', N'maria.madrigal@example.test', N'8814-2903', N'Curridabat, Granadilla Norte, San José', 1);
SET @cId = SCOPE_IDENTITY();
INSERT INTO dbo.clientes_fisicos (cliente_id, nombre, primer_apellido, segundo_apellido) VALUES (@cId, N'María Fernanda', N'Madrigal', N'Soto');
DECLARE @cMaria INT = @cId;

INSERT INTO dbo.clientes (identificacion, correo, telefono, direccion, activo) VALUES (N'2-0719-0348', N'jose.villalobos@example.test', N'8720-5511', N'Alajuela Centro, Barrio El Carmen', 1);
SET @cId = SCOPE_IDENTITY();
INSERT INTO dbo.clientes_fisicos (cliente_id, nombre, primer_apellido, segundo_apellido) VALUES (@cId, N'José Pablo', N'Villalobos', N'Quesada');
DECLARE @cJose INT = @cId;

INSERT INTO dbo.clientes (identificacion, correo, telefono, direccion, activo) VALUES (N'3-0451-0892', N'andrea.chacon@example.test', N'8643-7780', N'Cartago, Tejar de El Guarco', 1);
SET @cId = SCOPE_IDENTITY();
INSERT INTO dbo.clientes_fisicos (cliente_id, nombre, primer_apellido, segundo_apellido) VALUES (@cId, N'Andrea Carolina', N'Chacón', N'Brenes');
DECLARE @cAndrea INT = @cId;

-- ============================================================================
-- 5. FLOTA VEHICULAR NACIONAL (6 unidades: B1, B2, B3 y B4 Articulado)
-- ============================================================================
INSERT INTO dbo.vehiculos (placa, marca, modelo, anio, capacidad_carga_kg, capacidad_volumen_m3, kilometraje_actual, estado, activo, pma_kg, clase_legal, es_articulado, estado_homologacion) VALUES
(N'CL-10428', N'Toyota', N'Hiace Panel', 2024, 1150, 7.5, 31200, 'DISPONIBLE', 1, 3200, 'LIVIANO', 0, 'VERIFICADO'),
(N'CL-20815', N'Hyundai', N'H100 Reparto', 2023, 1400, 9.0, 48900, 'DISPONIBLE', 1, 3500, 'LIVIANO', 0, 'VERIFICADO'),
(N'CL-45210', N'Isuzu', N'NPR Furgón', 2024, 4200, 22.0, 29450, 'DISPONIBLE', 1, 7500, 'MEDIANO_RIGIDO', 0, 'VERIFICADO'),
(N'CL-58930', N'Hino', N'300 Furgón', 2025, 4500, 24.0, 14800, 'DISPONIBLE', 1, 7800, 'MEDIANO_RIGIDO', 0, 'VERIFICADO'),
(N'CL-79104', N'Hino', N'500 Caja Seca', 2024, 9500, 42.0, 62100, 'DISPONIBLE', 1, 15000, 'PESADO_RIGIDO', 0, 'VERIFICADO'),
(N'C-164820', N'Freightliner', N'M2 Articulado', 2025, 21000, 75.0, 22400, 'DISPONIBLE', 1, 32000, 'PESADO_ARTICULADO', 1, 'VERIFICADO');

DECLARE @vHiace INT = (SELECT vehiculo_id FROM dbo.vehiculos WHERE placa = N'CL-10428');
DECLARE @vH100 INT = (SELECT vehiculo_id FROM dbo.vehiculos WHERE placa = N'CL-20815');
DECLARE @vIsuzu INT = (SELECT vehiculo_id FROM dbo.vehiculos WHERE placa = N'CL-45210');
DECLARE @vHino300 INT = (SELECT vehiculo_id FROM dbo.vehiculos WHERE placa = N'CL-58930');
DECLARE @vHino500 INT = (SELECT vehiculo_id FROM dbo.vehiculos WHERE placa = N'CL-79104');
DECLARE @vTrailer INT = (SELECT vehiculo_id FROM dbo.vehiculos WHERE placa = N'C-164820');

-- ============================================================================
-- 6. CONDUCTORES DE REPARTO Y TRONCALES (6 choferes, Ley 9078)
-- ============================================================================
DECLARE @hoyCR DATE = CAST(DATEADD(HOUR, -6, SYSUTCDATETIME()) AS DATE);
DECLARE @kId INT;

INSERT INTO dbo.conductores (identificacion, nombre, primer_apellido, segundo_apellido, telefono, correo, numero_licencia, fecha_vencimiento_licencia, estado_operativo, activo)
VALUES (N'1-1240-0512', N'Carlos Alberto', N'Mora', N'Vega', N'8811-2345', N'carlos.mora@tssa.cr.test', N'CR-B1-1240', DATEADD(YEAR, 2, @hoyCR), 'DISPONIBLE', 1);
SET @kId = SCOPE_IDENTITY();
INSERT INTO dbo.conductores_licencias (conductor_id, codigo_licencia, numero_licencia, fecha_vencimiento, estado_verificacion, fecha_verificacion, usuario_verificador_id)
VALUES (@kId, N'B1', N'CR-B1-1240', DATEADD(YEAR, 2, @hoyCR), 'VERIFICADA', SYSUTCDATETIME(), @admin);
DECLARE @kB1 INT = @kId;

INSERT INTO dbo.conductores (identificacion, nombre, primer_apellido, segundo_apellido, telefono, correo, numero_licencia, fecha_vencimiento_licencia, estado_operativo, activo)
VALUES (N'1-1188-0432', N'Andrés Felipe', N'Rojas', N'Alfaro', N'8841-2209', N'andres.rojas@tssa.cr.test', N'CR-B2-1188', DATEADD(YEAR, 2, @hoyCR), 'DISPONIBLE', 1);
SET @kId = SCOPE_IDENTITY();
INSERT INTO dbo.conductores_licencias (conductor_id, codigo_licencia, numero_licencia, fecha_vencimiento, estado_verificacion, fecha_verificacion, usuario_verificador_id)
VALUES (@kId, N'B2', N'CR-B2-1188', DATEADD(YEAR, 2, @hoyCR), 'VERIFICADA', SYSUTCDATETIME(), @admin);
DECLARE @kB2a INT = @kId;

INSERT INTO dbo.conductores (identificacion, nombre, primer_apellido, segundo_apellido, telefono, correo, numero_licencia, fecha_vencimiento_licencia, estado_operativo, activo)
VALUES (N'4-0215-0833', N'Luis Fernando', N'Solano', N'Arias', N'8790-4112', N'luis.solano@tssa.cr.test', N'CR-B2-0215', DATEADD(YEAR, 2, @hoyCR), 'DISPONIBLE', 1);
SET @kId = SCOPE_IDENTITY();
INSERT INTO dbo.conductores_licencias (conductor_id, codigo_licencia, numero_licencia, fecha_vencimiento, estado_verificacion, fecha_verificacion, usuario_verificador_id)
VALUES (@kId, N'B2', N'CR-B2-0215', DATEADD(YEAR, 2, @hoyCR), 'VERIFICADA', SYSUTCDATETIME(), @admin);
DECLARE @kB2b INT = @kId;

-- Esteban Monge: licencia B3 próxima a vencer en 14 días (activa alertas de renovación)
INSERT INTO dbo.conductores (identificacion, nombre, primer_apellido, segundo_apellido, telefono, correo, numero_licencia, fecha_vencimiento_licencia, estado_operativo, activo)
VALUES (N'2-0654-0911', N'Esteban Mauricio', N'Monge', N'Navarro', N'8709-5514', N'esteban.monge@tssa.cr.test', N'CR-B3-0654', DATEADD(DAY, 14, @hoyCR), 'DISPONIBLE', 1);
SET @kId = SCOPE_IDENTITY();
INSERT INTO dbo.conductores_licencias (conductor_id, codigo_licencia, numero_licencia, fecha_vencimiento, estado_verificacion, fecha_verificacion, usuario_verificador_id)
VALUES (@kId, N'B3', N'CR-B3-0654', DATEADD(DAY, 14, @hoyCR), 'VERIFICADA', SYSUTCDATETIME(), @admin);
DECLARE @kB3 INT = @kId;

INSERT INTO dbo.conductores (identificacion, nombre, primer_apellido, segundo_apellido, telefono, correo, numero_licencia, fecha_vencimiento_licencia, estado_operativo, activo)
VALUES (N'3-0392-0774', N'Randall Gerardo', N'Quesada', N'Mora', N'8912-6630', N'randall.quesada@tssa.cr.test', N'CR-B4-0392', DATEADD(YEAR, 2, @hoyCR), 'DISPONIBLE', 1);
SET @kId = SCOPE_IDENTITY();
INSERT INTO dbo.conductores_licencias (conductor_id, codigo_licencia, numero_licencia, fecha_vencimiento, estado_verificacion, fecha_verificacion, usuario_verificador_id)
VALUES (@kId, N'B4', N'CR-B4-0392', DATEADD(YEAR, 2, @hoyCR), 'VERIFICADA', SYSUTCDATETIME(), @admin);
DECLARE @kB4 INT = @kId;

INSERT INTO dbo.conductores (identificacion, nombre, primer_apellido, segundo_apellido, telefono, correo, numero_licencia, fecha_vencimiento_licencia, estado_operativo, activo)
VALUES (N'1-1504-0289', N'Pamela Lucía', N'Chacón', N'Umaña', N'8655-1840', N'pamela.chacon@tssa.cr.test', N'CR-E2-1504', DATEADD(YEAR, 3, @hoyCR), 'DISPONIBLE', 1);
SET @kId = SCOPE_IDENTITY();
INSERT INTO dbo.conductores_licencias (conductor_id, codigo_licencia, numero_licencia, fecha_vencimiento, estado_verificacion, fecha_verificacion, usuario_verificador_id)
VALUES (@kId, N'E2', N'CR-E2-1504', DATEADD(YEAR, 3, @hoyCR), 'VERIFICADA', SYSUTCDATETIME(), @admin);
DECLARE @kE2 INT = @kId;

-- ============================================================================
-- 7. OPERACIÓN REALISTA DE PAQUETERÍA Y ENCOMIENDAS (10 envíos + 4 viajes)
-- ============================================================================
DECLARE @s1 INT, @s2 INT, @s3 INT, @s4 INT, @s5 INT, @s6 INT, @s7 INT, @s8 INT, @s9 INT, @s10 INT;
DECLARE @v1 INT, @v2 INT, @v3 INT, @v4 INT;
DECLARE @vs INT, @cr INT, @docId INT;

-- [VIA-00001 FINALIZADO] — Distribución GAM Norte con 2 entregas exitosas
INSERT INTO dbo.solicitudes (codigo_solicitud, cliente_id, origen_id, destino_id, destinatario_nombre, destinatario_apellido1, destinatario_apellido2, destinatario_identificacion, destinatario_telefono, instrucciones_entrega, fecha_solicitud, fecha_entrega_requerida, prioridad_calculada, prioridad_actual, estado)
VALUES (N'SOL-00001', @cLibreria, @uZapote, @uHeredia, N'Daniela', N'Arias', N'Solís', N'4-0231-0412', N'8832-1190', N'Entregar en recepción de sucursal Heredia (valija de textos universitarios).', DATEADD(DAY, -2, SYSUTCDATETIME()), DATEADD(DAY, -1, SYSUTCDATETIME()), 'NORMAL', 'NORMAL', 'REGISTRADA');
SET @s1 = SCOPE_IDENTITY();
INSERT INTO dbo.mercancias (solicitud_id, tipo_mercancia_id, descripcion, peso_kg, volumen_m3) VALUES (@s1, @tmPaq, N'12 cajas de libros académicos empacados', 145.00, 0.900);

INSERT INTO dbo.solicitudes (codigo_solicitud, cliente_id, origen_id, destino_id, destinatario_nombre, destinatario_apellido1, destinatario_apellido2, destinatario_identificacion, destinatario_telefono, instrucciones_entrega, fecha_solicitud, fecha_entrega_requerida, prioridad_calculada, prioridad_actual, estado)
VALUES (N'SOL-00002', @cFarmacia, @uSJ, @uHeredia, N'Dr. Ricardo', N'Vargas', N'Méndez', N'4-0198-0765', N'8874-0921', N'Entregar en regencia farmacéutica, exigir firma y sello.', DATEADD(DAY, -2, SYSUTCDATETIME()), DATEADD(DAY, -1, SYSUTCDATETIME()), 'ALTA', 'ALTA', 'REGISTRADA');
SET @s2 = SCOPE_IDENTITY();
INSERT INTO dbo.mercancias (solicitud_id, tipo_mercancia_id, descripcion, peso_kg, volumen_m3) VALUES (@s2, @tmMed, N'Lote de suministros de farmacia sellados', 95.00, 0.550);

EXEC dbo.sp_crear_viaje @codigo = N'VIA-00001', @vehiculo_id = @vHiace, @conductor_id = @kB1, @ruta_id = @rGamNorte, @fecha_programada = '2026-11-21 13:00:00', @viaje_id = @v1 OUTPUT;
EXEC dbo.sp_asignar_solicitud_con_paradas @viaje_id = @v1, @solicitud_id = @s1, @auto_crear_paradas = 1, @viaje_solicitud_id = @vs OUTPUT, @paradas_creadas = @cr OUTPUT;
DECLARE @vs1 INT = @vs;
EXEC dbo.sp_asignar_solicitud_con_paradas @viaje_id = @v1, @solicitud_id = @s2, @auto_crear_paradas = 1, @viaje_solicitud_id = @vs OUTPUT, @paradas_creadas = @cr OUTPUT;
DECLARE @vs2 INT = @vs;
EXEC dbo.sp_iniciar_viaje @viaje_id = @v1;
EXEC dbo.sp_registrar_entrega @viaje_solicitud_id = @vs1, @estado = 'ENTREGADA', @receptor_nombre = N'Daniela Arias Solís', @receptor_identificacion = N'4-0231-0412', @receptor_telefono = N'8832-1190', @comprobante_url = N'https://comprobantes.tssa.cr.test/envios/SOL-00001.pdf', @documento_id = @docId OUTPUT, @permitir_sin_comprobante = 1;
EXEC dbo.sp_registrar_entrega @viaje_solicitud_id = @vs2, @estado = 'ENTREGADA', @receptor_nombre = N'Dr. Ricardo Vargas Méndez', @receptor_identificacion = N'4-0198-0765', @receptor_telefono = N'8874-0921', @comprobante_url = N'https://comprobantes.tssa.cr.test/envios/SOL-00002.pdf', @documento_id = @docId OUTPUT, @permitir_sin_comprobante = 1;
EXEC dbo.sp_finalizar_viaje @viaje_id = @v1;

-- [VIA-00002 FINALIZADO] — Reparto Urbano GAM Oeste con 1 ENTREGA_FALLIDA (para probar "Reprogramar en nueva solicitud")
INSERT INTO dbo.solicitudes (codigo_solicitud, cliente_id, origen_id, destino_id, destinatario_nombre, destinatario_apellido1, destinatario_apellido2, destinatario_identificacion, destinatario_telefono, instrucciones_entrega, fecha_solicitud, fecha_entrega_requerida, prioridad_calculada, prioridad_actual, estado)
VALUES (N'SOL-00003', @cTicoTech, @uEscazu, @uCoyol, N'José Pablo', N'Villalobos', N'Quesada', N'2-0719-0348', N'8720-5511', N'Entregar paquete de monitor 27" en caseta de vigilancia.', DATEADD(DAY, -2, SYSUTCDATETIME()), DATEADD(DAY, -1, SYSUTCDATETIME()), 'NORMAL', 'NORMAL', 'REGISTRADA');
SET @s3 = SCOPE_IDENTITY();
INSERT INTO dbo.mercancias (solicitud_id, tipo_mercancia_id, descripcion, peso_kg, volumen_m3) VALUES (@s3, @tmElec, N'Paquete e-commerce: monitor LED 27" y accesorios', 18.50, 0.140);

EXEC dbo.sp_crear_viaje @codigo = N'VIA-00002', @vehiculo_id = @vH100, @conductor_id = @kB2b, @ruta_id = @rGamOeste, @fecha_programada = '2026-11-21 15:00:00', @viaje_id = @v2 OUTPUT;
EXEC dbo.sp_asignar_solicitud_con_paradas @viaje_id = @v2, @solicitud_id = @s3, @auto_crear_paradas = 1, @viaje_solicitud_id = @vs OUTPUT, @paradas_creadas = @cr OUTPUT;
EXEC dbo.sp_iniciar_viaje @viaje_id = @v2;
EXEC dbo.sp_registrar_entrega @viaje_solicitud_id = @vs, @estado = 'FALLIDA', @motivo_fallo = N'Destinatario ausente en el domicilio tras dos intentos de llamada; paquete retornado a HUB Zapote.', @documento_id = @docId OUTPUT, @permitir_sin_comprobante = 1;
EXEC dbo.sp_finalizar_viaje @viaje_id = @v2;

-- [VIA-00003 EN_CURSO] — Troncal Caribe Ruta 32 con 2 encomiendas EN_TRANSITO + 1 incidencia ABIERTA
INSERT INTO dbo.solicitudes (codigo_solicitud, cliente_id, origen_id, destino_id, destinatario_nombre, destinatario_apellido1, destinatario_apellido2, destinatario_identificacion, destinatario_telefono, instrucciones_entrega, fecha_solicitud, fecha_entrega_requerida, prioridad_calculada, prioridad_actual, estado)
VALUES (N'SOL-00004', @cFarmacia, @uZapote, @uLimon, N'Kenneth', N'Campbell', N'Brown', N'7-0211-0843', N'8851-3902', N'Valija prioritaria para sucursal Limón Centro, entregar en ventanilla de encomiendas.', DATEADD(HOUR, -6, SYSUTCDATETIME()), DATEADD(HOUR, 5, SYSUTCDATETIME()), 'URGENTE', 'URGENTE', 'REGISTRADA');
SET @s4 = SCOPE_IDENTITY();
INSERT INTO dbo.mercancias (solicitud_id, tipo_mercancia_id, descripcion, peso_kg, volumen_m3) VALUES
(@s4, @tmMed, N'Cajas selladas de medicamentos para sucursal Limón', 260.00, 1.400),
(@s4, @tmDoc, N'Valija certificada de documentación clínica', 12.00, 0.080);

INSERT INTO dbo.solicitudes (codigo_solicitud, cliente_id, origen_id, destino_id, destinatario_nombre, destinatario_apellido1, destinatario_apellido2, destinatario_identificacion, destinatario_telefono, instrucciones_entrega, fecha_solicitud, fecha_entrega_requerida, prioridad_calculada, prioridad_actual, estado)
VALUES (N'SOL-00005', @cCafe, @uZapote, @uCartago, N'Andrea Carolina', N'Chacón', N'Brenes', N'3-0451-0892', N'8643-7780', N'Entregar en sucursal Cartago Centro para retiro de cliente.', DATEADD(HOUR, -5, SYSUTCDATETIME()), DATEADD(HOUR, 4, SYSUTCDATETIME()), 'ALTA', 'ALTA', 'REGISTRADA');
SET @s5 = SCOPE_IDENTITY();
INSERT INTO dbo.mercancias (solicitud_id, tipo_mercancia_id, descripcion, peso_kg, volumen_m3) VALUES (@s5, @tmAlim, N'25 cajas de café molido exportación (paquetería)', 310.00, 1.850);

EXEC dbo.sp_crear_viaje @codigo = N'VIA-00003', @vehiculo_id = @vHino500, @conductor_id = @kB4, @ruta_id = @rCaribe, @fecha_programada = '2026-11-21 11:00:00', @viaje_id = @v3 OUTPUT;
EXEC dbo.sp_asignar_solicitud_con_paradas @viaje_id = @v3, @solicitud_id = @s4, @auto_crear_paradas = 1, @viaje_solicitud_id = @vs OUTPUT, @paradas_creadas = @cr OUTPUT;
EXEC dbo.sp_asignar_solicitud_con_paradas @viaje_id = @v3, @solicitud_id = @s5, @auto_crear_paradas = 1, @viaje_solicitud_id = @vs OUTPUT, @paradas_creadas = @cr OUTPUT;
EXEC dbo.sp_iniciar_viaje @viaje_id = @v3;

INSERT INTO dbo.incidencias (solicitud_id, tipo_incidencia_id, responsable_usuario_id, descripcion, estado)
VALUES (@s4, 1, @admin, N'Paso regulado en sector del túnel Zurquí (Ruta 32) por neblina y mantenimiento vial; atraso estimado 30 min.', 'ABIERTA');

-- [VIA-00004 PLANIFICADO] — Distribución GAM Este con 4 paradas desordenadas (ideal para "Ruta inteligente" + Hoja de Ruta + Google Maps)
INSERT INTO dbo.solicitudes (codigo_solicitud, cliente_id, origen_id, destino_id, destinatario_nombre, destinatario_apellido1, destinatario_apellido2, destinatario_identificacion, destinatario_telefono, instrucciones_entrega, fecha_solicitud, fecha_entrega_requerida, prioridad_calculada, prioridad_actual, estado)
VALUES (N'SOL-00006', @cMaria, @uZapote, @uCartago, N'María Fernanda', N'Madrigal', N'Soto', N'1-1482-0619', N'8814-2903', N'Paquete frágil, avisar al llegar a sucursal Cartago.', DATEADD(HOUR, -2, SYSUTCDATETIME()), DATEADD(DAY, 1, SYSUTCDATETIME()), 'ALTA', 'ALTA', 'REGISTRADA');
SET @s6 = SCOPE_IDENTITY();
INSERT INTO dbo.mercancias (solicitud_id, tipo_mercancia_id, descripcion, peso_kg, volumen_m3) VALUES (@s6, @tmPaq, N'Encomienda comercial de cerámica artesanal (frágil)', 85.00, 0.650);

INSERT INTO dbo.solicitudes (codigo_solicitud, cliente_id, origen_id, destino_id, destinatario_nombre, destinatario_apellido1, destinatario_apellido2, destinatario_identificacion, destinatario_telefono, instrucciones_entrega, fecha_solicitud, fecha_entrega_requerida, prioridad_calculada, prioridad_actual, estado)
VALUES (N'SOL-00007', @cTicoTech, @uHeredia, @uEscazu, N'Diego', N'Ureña', N'Cordero', N'1-1375-0920', N'8892-6401', N'Entregar en recepción corporativa de Avenida Escazú.', DATEADD(HOUR, -1, SYSUTCDATETIME()), DATEADD(DAY, 1, SYSUTCDATETIME()), 'NORMAL', 'NORMAL', 'REGISTRADA');
SET @s7 = SCOPE_IDENTITY();
INSERT INTO dbo.mercancias (solicitud_id, tipo_mercancia_id, descripcion, peso_kg, volumen_m3) VALUES (@s7, @tmElec, N'8 cajas de equipos de red y accesorios de oficina', 120.00, 0.800);

EXEC dbo.sp_crear_viaje @codigo = N'VIA-00004', @vehiculo_id = @vIsuzu, @conductor_id = @kB2a, @ruta_id = @rGamEste, @fecha_programada = '2026-11-22 14:00:00', @viaje_id = @v4 OUTPUT;
DECLARE @p1 INT, @p2 INT, @p3 INT, @p4 INT;
EXEC dbo.sp_agregar_parada @viaje_id = @v4, @ubicacion_id = @uZapote, @orden = 1, @tipo_parada = 'RECOGIDA', @parada_id = @p1 OUTPUT;
EXEC dbo.sp_agregar_parada @viaje_id = @v4, @ubicacion_id = @uCartago, @orden = 2, @tipo_parada = 'ENTREGA', @parada_id = @p2 OUTPUT;
EXEC dbo.sp_agregar_parada @viaje_id = @v4, @ubicacion_id = @uHeredia, @orden = 3, @tipo_parada = 'RECOGIDA', @parada_id = @p3 OUTPUT;
EXEC dbo.sp_agregar_parada @viaje_id = @v4, @ubicacion_id = @uEscazu, @orden = 4, @tipo_parada = 'ENTREGA', @parada_id = @p4 OUTPUT;
EXEC dbo.sp_asignar_solicitud_viaje @viaje_id = @v4, @solicitud_id = @s6, @parada_recogida_id = @p1, @parada_entrega_id = @p2, @viaje_solicitud_id = @vs OUTPUT;
EXEC dbo.sp_asignar_solicitud_viaje @viaje_id = @v4, @solicitud_id = @s7, @parada_recogida_id = @p3, @parada_entrega_id = @p4, @viaje_solicitud_id = @vs OUTPUT;

-- [3 ENCOMIENDAS SIN ASIGNAR] — Listas para demostrar Despacho Inteligente
-- SOL-00008: Zapote -> Cartago (URGENTE + ATRASADA) => calza ÓPTIMO en VIA-00004!
INSERT INTO dbo.solicitudes (codigo_solicitud, cliente_id, origen_id, destino_id, destinatario_nombre, destinatario_apellido1, destinatario_apellido2, destinatario_identificacion, destinatario_telefono, instrucciones_entrega, fecha_solicitud, fecha_entrega_requerida, prioridad_calculada, prioridad_actual, estado)
VALUES (N'SOL-00008', @cFarmacia, @uZapote, @uCartago, N'Dra. Sofía', N'Jiménez', N'Campos', N'3-0477-0192', N'8810-4432', N'Pedido urgente para sucursal Cartago; prioridad máxima de ventanilla.', DATEADD(DAY, -1, SYSUTCDATETIME()), DATEADD(HOUR, -2, SYSUTCDATETIME()), 'URGENTE', 'URGENTE', 'REGISTRADA');
SET @s8 = SCOPE_IDENTITY();
INSERT INTO dbo.mercancias (solicitud_id, tipo_mercancia_id, descripcion, peso_kg, volumen_m3) VALUES
(@s8, @tmMed, N'10 cajas de medicamentos de despacho urgente', 165.00, 1.100),
(@s8, @tmDoc, N'Sobres de facturación y recetas controladas', 5.00, 0.040);

-- SOL-00009: Alajuela (El Coyol) -> Liberia, Guanacaste (ALTA, PENDIENTE_ASIGNACION) => VIA-00004 marca DESVÍO ALTO y sugiere crear viaje Troncal Chorotega!
INSERT INTO dbo.solicitudes (codigo_solicitud, cliente_id, origen_id, destino_id, destinatario_nombre, destinatario_apellido1, destinatario_apellido2, destinatario_identificacion, destinatario_telefono, instrucciones_entrega, fecha_solicitud, fecha_entrega_requerida, prioridad_calculada, prioridad_actual, estado)
VALUES (N'SOL-00009', @cClinicos, @uCoyol, @uLiberia, N'Dr. Marvin', N'Gutiérrez', N'Paniagua', N'5-0319-0641', N'8731-9044', N'Entregar en sucursal Regional Liberia para distribución hospitalaria de Guanacaste.', DATEADD(HOUR, -4, SYSUTCDATETIME()), DATEADD(DAY, 1, SYSUTCDATETIME()), 'ALTA', 'ALTA', 'PENDIENTE_ASIGNACION');
SET @s9 = SCOPE_IDENTITY();
INSERT INTO dbo.mercancias (solicitud_id, tipo_mercancia_id, descripcion, peso_kg, volumen_m3) VALUES (@s9, @tmMed, N'Pallet de insumos clínicos estériles para Liberia', 950.00, 4.800);

-- SOL-00010: Zapote -> Puntarenas (NORMAL, REGISTRADA) => encomiendas e-commerce para Pacífico Central
INSERT INTO dbo.solicitudes (codigo_solicitud, cliente_id, origen_id, destino_id, destinatario_nombre, destinatario_apellido1, destinatario_apellido2, destinatario_identificacion, destinatario_telefono, instrucciones_entrega, fecha_solicitud, fecha_entrega_requerida, prioridad_calculada, prioridad_actual, estado)
VALUES (N'SOL-00010', @cJose, @uZapote, @uPuntarenas, N'Gabriela', N'Morales', N'Picado', N'6-0388-0215', N'8615-2890', N'Retiro en sucursal Puntarenas Centro con cédula.', DATEADD(HOUR, -1, SYSUTCDATETIME()), DATEADD(DAY, 2, SYSUTCDATETIME()), 'NORMAL', 'NORMAL', 'REGISTRADA');
SET @s10 = SCOPE_IDENTITY();
INSERT INTO dbo.mercancias (solicitud_id, tipo_mercancia_id, descripcion, peso_kg, volumen_m3) VALUES (@s10, @tmPaq, N'Saco consolidado de 18 paquetes e-commerce para Puntarenas', 240.00, 1.600);

-- Actualizar secuencias para que los próximos registros continúen en SOL-00011 y VIA-00005
ALTER SEQUENCE dbo.seq_codigo_solicitud RESTART WITH 11;
ALTER SEQUENCE dbo.seq_codigo_viaje RESTART WITH 5;

PRINT N'OK: Base TransportesSegurosSA_Test reiniciada con datos realistas de mensajería y encomiendas en Costa Rica.';
