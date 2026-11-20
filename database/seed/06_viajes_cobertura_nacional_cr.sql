-- ============================================================================
-- Cobertura Nacional de Viajes PLANIFICADO (Costa Rica completo)
-- SOLO TransportesSegurosSA_Test — Idempotente.
--
-- Abre 5 viajes troncales PLANIFICADO cubriendo las 5 grandes regiones del país
-- (GAM, Chorotega/Guanacaste, Pacífico Central, Caribe Ruta 32 y Zona Sur)
-- y garantiza 2 unidades + 2 choferes de reserva DISPONIBLE (B1 y B2) para
-- demostraciones de creación de viaje y bloqueo legal Ley 9078 (Error 50320).
-- ============================================================================

SET NOCOUNT ON;
SET XACT_ABORT ON;

IF DB_NAME() <> N'TransportesSegurosSA_Test'
    THROW 50999, N'PROHIBIDO: Este script solo puede ejecutarse en TransportesSegurosSA_Test.', 1;

DECLARE @admin INT = (SELECT TOP (1) usuario_id FROM dbo.usuarios WHERE activo = 1 ORDER BY usuario_id ASC);
EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @admin;

DECLARE @hoyCR DATE = CAST(DATEADD(HOUR, -6, SYSUTCDATETIME()) AS DATE);
DECLARE @salida DATETIME2 = DATEADD(HOUR, 18, SYSUTCDATETIME());

-- 1. Asegurar unidad y conductor de reserva B2 (para tener siempre B1 + B2 libres para pruebas)
IF NOT EXISTS (SELECT 1 FROM dbo.vehiculos WHERE placa = N'CL-63410')
    INSERT INTO dbo.vehiculos (placa, marca, modelo, anio, capacidad_carga_kg, capacidad_volumen_m3, kilometraje_actual, estado, activo, pma_kg, clase_legal, es_articulado, estado_homologacion)
    VALUES (N'CL-63410', N'Mitsubishi', N'Fuso Canter', 2024, 3800, 20.0, 18500, 'DISPONIBLE', 1, 7500, 'MEDIANO_RIGIDO', 0, 'VERIFICADO');

IF NOT EXISTS (SELECT 1 FROM dbo.conductores WHERE identificacion = N'1-1610-0824')
BEGIN
    INSERT INTO dbo.conductores (identificacion, nombre, primer_apellido, segundo_apellido, telefono, correo, numero_licencia, fecha_vencimiento_licencia, estado_operativo, activo)
    VALUES (N'1-1610-0824', N'Diego', N'Vega', N'Araya', N'8877-3041', N'diego.vega@tssa.cr.test', N'CR-B2-1610', DATEADD(YEAR, 2, @hoyCR), 'DISPONIBLE', 1);
    DECLARE @kRes INT = SCOPE_IDENTITY();
    INSERT INTO dbo.conductores_licencias (conductor_id, codigo_licencia, numero_licencia, fecha_vencimiento, estado_verificacion, fecha_verificacion, usuario_verificador_id)
    VALUES (@kRes, N'B2', N'CR-B2-1610', DATEADD(YEAR, 2, @hoyCR), 'VERIFICADA', SYSUTCDATETIME(), @admin);
END

-- 2. Abrir cada uno de los 5 viajes regionales solo si no existe ya un viaje PLANIFICADO en esa ruta
DECLARE @rGam INT = (SELECT TOP 1 ruta_id FROM dbo.rutas WHERE nombre LIKE N'Distribución GAM Este%' AND activo = 1);
DECLARE @rCho INT = (SELECT TOP 1 ruta_id FROM dbo.rutas WHERE nombre LIKE N'Troncal Chorotega%' AND activo = 1);
DECLARE @rPac INT = (SELECT TOP 1 ruta_id FROM dbo.rutas WHERE nombre LIKE N'Troncal Pacífico Central%' AND activo = 1);
DECLARE @rCar INT = (SELECT TOP 1 ruta_id FROM dbo.rutas WHERE nombre LIKE N'Troncal Caribe%' AND activo = 1);
DECLARE @rSur INT = (SELECT TOP 1 ruta_id FROM dbo.rutas WHERE nombre LIKE N'Troncal Zona Sur%' AND activo = 1);

DECLARE @vIsuzu INT = (SELECT TOP 1 vehiculo_id FROM dbo.vehiculos WHERE placa = N'CL-45210' AND estado = 'DISPONIBLE');
DECLARE @vHino300 INT = (SELECT TOP 1 vehiculo_id FROM dbo.vehiculos WHERE placa = N'CL-58930' AND estado = 'DISPONIBLE');
DECLARE @vH100 INT = (SELECT TOP 1 vehiculo_id FROM dbo.vehiculos WHERE placa = N'CL-20815' AND estado = 'DISPONIBLE');
DECLARE @vHino500 INT = (SELECT TOP 1 vehiculo_id FROM dbo.vehiculos WHERE placa = N'CL-79104' AND estado = 'DISPONIBLE');
DECLARE @vTrailer INT = (SELECT TOP 1 vehiculo_id FROM dbo.vehiculos WHERE placa = N'C-164820' AND estado = 'DISPONIBLE');

DECLARE @kRojas INT = (SELECT TOP 1 conductor_id FROM dbo.conductores WHERE identificacion = N'1-1188-0432' AND estado_operativo = 'DISPONIBLE');
DECLARE @kSolano INT = (SELECT TOP 1 conductor_id FROM dbo.conductores WHERE identificacion = N'4-0215-0833' AND estado_operativo = 'DISPONIBLE');
DECLARE @kChacon INT = (SELECT TOP 1 conductor_id FROM dbo.conductores WHERE identificacion = N'1-1504-0289' AND estado_operativo = 'DISPONIBLE');
DECLARE @kMonge INT = (SELECT TOP 1 conductor_id FROM dbo.conductores WHERE identificacion = N'2-0654-0911' AND estado_operativo = 'DISPONIBLE');
DECLARE @kQuesada INT = (SELECT TOP 1 conductor_id FROM dbo.conductores WHERE identificacion = N'3-0392-0774' AND estado_operativo = 'DISPONIBLE');

DECLARE @vid INT;

IF @rGam IS NOT NULL AND @vIsuzu IS NOT NULL AND @kRojas IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM dbo.viajes WHERE ruta_id = @rGam AND estado = 'PLANIFICADO')
    EXEC dbo.sp_crear_viaje @codigo = NULL, @vehiculo_id = @vIsuzu, @conductor_id = @kRojas, @ruta_id = @rGam, @fecha_programada = @salida, @viaje_id = @vid OUTPUT;

IF @rCho IS NOT NULL AND @vHino300 IS NOT NULL AND @kSolano IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM dbo.viajes WHERE ruta_id = @rCho AND estado = 'PLANIFICADO')
    EXEC dbo.sp_crear_viaje @codigo = NULL, @vehiculo_id = @vHino300, @conductor_id = @kSolano, @ruta_id = @rCho, @fecha_programada = @salida, @viaje_id = @vid OUTPUT;

IF @rPac IS NOT NULL AND @vH100 IS NOT NULL AND @kChacon IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM dbo.viajes WHERE ruta_id = @rPac AND estado = 'PLANIFICADO')
    EXEC dbo.sp_crear_viaje @codigo = NULL, @vehiculo_id = @vH100, @conductor_id = @kChacon, @ruta_id = @rPac, @fecha_programada = @salida, @viaje_id = @vid OUTPUT;

IF @rCar IS NOT NULL AND @vHino500 IS NOT NULL AND @kMonge IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM dbo.viajes WHERE ruta_id = @rCar AND estado = 'PLANIFICADO')
    EXEC dbo.sp_crear_viaje @codigo = NULL, @vehiculo_id = @vHino500, @conductor_id = @kMonge, @ruta_id = @rCar, @fecha_programada = @salida, @viaje_id = @vid OUTPUT;

IF @rSur IS NOT NULL AND @vTrailer IS NOT NULL AND @kQuesada IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM dbo.viajes WHERE ruta_id = @rSur AND estado = 'PLANIFICADO')
    EXEC dbo.sp_crear_viaje @codigo = NULL, @vehiculo_id = @vTrailer, @conductor_id = @kQuesada, @ruta_id = @rSur, @fecha_programada = @salida, @viaje_id = @vid OUTPUT;

PRINT N'OK: Cobertura nacional de 5 viajes PLANIFICADO verificada en TransportesSegurosSA_Test.';
GO
