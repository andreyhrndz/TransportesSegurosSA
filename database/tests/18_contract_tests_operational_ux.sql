-- ============================================================================
-- Pruebas contractuales T-SQL — Migración 14 (solo TransportesSegurosSA_Test)
-- Valida: sp_asignar_solicitud_con_paradas, sp_obtener_contexto_entrega,
-- sp_listar_entregas_pendientes, sp_registrar_entrega (comprobante opcional y
-- archivo sincronizado con dbo.documentos), sp_obtener_documento_archivo.
-- ============================================================================

SET NOCOUNT ON;
SET XACT_ABORT ON;

IF DB_NAME() <> N'TransportesSegurosSA_Test'
    THROW 50999, N'Esta prueba solo puede ejecutarse en TransportesSegurosSA_Test.', 1;

BEGIN TRANSACTION;

DECLARE @admin INT = (SELECT TOP (1) usuario_id FROM dbo.usuarios WHERE activo = 1 ORDER BY usuario_id ASC);
EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @admin;

-- 1. Tomar recursos activos de prueba (creados en Test o QA seed)
DECLARE @u1 INT, @u2 INT, @ruta INT, @cli INT, @veh INT, @cond INT, @sol INT, @viaje INT;

SELECT TOP (1) @ruta = ruta_id, @u1 = origen_id, @u2 = destino_id
FROM dbo.rutas WHERE activo = 1 ORDER BY ruta_id ASC;

SELECT TOP (1) @cli = cliente_id FROM dbo.clientes WHERE activo = 1 ORDER BY cliente_id ASC;

SELECT TOP (1) @veh = v.vehiculo_id, @cond = c.conductor_id
FROM dbo.vehiculos v
CROSS JOIN dbo.conductores c
CROSS APPLY dbo.fn_conductor_habilitado_vehiculo(c.conductor_id, v.vehiculo_id, '2027-06-01 08:00:00') h
WHERE v.activo = 1 AND v.estado = 'DISPONIBLE'
  AND c.activo = 1 AND c.estado_operativo = 'DISPONIBLE'
  AND h.es_habilitado = 1
ORDER BY v.vehiculo_id ASC, c.conductor_id ASC;

IF @ruta IS NULL OR @cli IS NULL OR @veh IS NULL OR @cond IS NULL
    THROW 50990, N'Faltan datos base disponibles en TransportesSegurosSA_Test para ejecutar la prueba.', 1;

INSERT INTO dbo.solicitudes
(
    codigo_solicitud, cliente_id, origen_id, destino_id,
    destinatario_nombre, destinatario_apellido1, destinatario_apellido2, destinatario_identificacion, destinatario_telefono,
    fecha_entrega_requerida, prioridad_calculada, prioridad_actual, estado
)
VALUES
(
    N'SOL-TM14-01', @cli, @u1, @u2,
    N'Ana', N'Perez', N'Gomez', N'112233445', N'87776666',
    DATEADD(DAY, 2, SYSUTCDATETIME()), 'NORMAL', 'NORMAL', 'REGISTRADA'
);
SET @sol = SCOPE_IDENTITY();

DECLARE @tipoMerc INT = (SELECT TOP (1) tipo_mercancia_id FROM dbo.tipos_mercancia WHERE activo = 1 ORDER BY tipo_mercancia_id ASC);
INSERT INTO dbo.mercancias (solicitud_id, tipo_mercancia_id, descripcion, peso_kg)
VALUES (@sol, @tipoMerc, N'Cajas prueba M14', 150);

-- 2. Crear viaje con 0 paradas y probar sp_asignar_solicitud_con_paradas (auto-crea 2 paradas)
EXEC dbo.sp_crear_viaje
    @vehiculo_id = @veh,
    @conductor_id = @cond,
    @ruta_id = @ruta,
    @fecha_programada = '2027-06-01 08:00:00',
    @viaje_id = @viaje OUTPUT;

DECLARE @vs_id INT, @creadas INT;
EXEC dbo.sp_asignar_solicitud_con_paradas
    @viaje_id = @viaje,
    @solicitud_id = @sol,
    @parada_recogida_id = NULL,
    @parada_entrega_id = NULL,
    @auto_crear_paradas = 1,
    @viaje_solicitud_id = @vs_id OUTPUT,
    @paradas_creadas = @creadas OUTPUT;

IF @vs_id IS NULL OR @creadas <> 2
    THROW 50991, N'FAIL: sp_asignar_solicitud_con_paradas no creó las 2 paradas esperadas.', 1;

-- 2b. Probar sp_obtener_capacidad_viaje, sp_obtener_solicitud_360, sp_obtener_alertas_panel
EXEC dbo.sp_obtener_capacidad_viaje @viaje_id = @viaje;
EXEC dbo.sp_obtener_solicitud_360 @solicitud_id = @sol;
EXEC dbo.sp_obtener_alertas_panel;

-- 2c. Probar desasignar y eliminar parada no vinculada, luego reasignar automáticamente
EXEC dbo.sp_desasignar_solicitud_viaje @viaje_id = @viaje, @solicitud_id = @sol;
DECLARE @paradaBorrar INT = (SELECT TOP (1) parada_id FROM dbo.paradas_viaje WHERE viaje_id = @viaje ORDER BY orden DESC);
EXEC dbo.sp_eliminar_parada_viaje @viaje_id = @viaje, @parada_id = @paradaBorrar;

DECLARE @creadas2 INT = 0;
EXEC dbo.sp_asignar_solicitud_con_paradas
    @viaje_id = @viaje,
    @solicitud_id = @sol,
    @parada_recogida_id = NULL,
    @parada_entrega_id = NULL,
    @auto_crear_paradas = 1,
    @viaje_solicitud_id = @vs_id OUTPUT,
    @paradas_creadas = @creadas2 OUTPUT;

IF @creadas2 <> 1
    THROW 50996, N'FAIL: sp_asignar_solicitud_con_paradas debió reutilizar 1 parada y crear solo 1.', 1;

-- 3. Iniciar viaje y validar sp_obtener_contexto_entrega y sp_listar_entregas_pendientes
EXEC dbo.sp_iniciar_viaje @viaje_id = @viaje;

EXEC dbo.sp_obtener_contexto_entrega @viaje_solicitud_id = @vs_id;

DECLARE @totalPend INT = 0;
EXEC dbo.sp_listar_entregas_pendientes @buscar = N'SOL-TM14-01', @pagina = 1, @por_pagina = 10, @total_registros = @totalPend OUTPUT;
IF @totalPend <> 1
    THROW 50992, N'FAIL: sp_listar_entregas_pendientes no encontró la asignación EN_TRANSITO.', 1;

-- 4. Registrar entrega con archivo local (comprobante_url NULL) -> debe crear documento y enlazar /entregas/archivo.php?id=...
DECLARE @doc_id INT = NULL;
EXEC dbo.sp_registrar_entrega
    @viaje_solicitud_id = @vs_id,
    @estado = 'ENTREGADA',
    @receptor_nombre = N'Ana Perez',
    @receptor_identificacion = N'112233445',
    @receptor_telefono = N'87776666',
    @comprobante_url = NULL,
    @motivo_fallo = NULL,
    @nombre_archivo_original = N'firma_cliente.pdf',
    @mime_type = N'application/pdf',
    @tamano_bytes = 2048,
    @ruta_interna = N'0123456789abcdef0123456789abcdef.pdf',
    @documento_id = @doc_id OUTPUT;

IF @doc_id IS NULL
    THROW 50993, N'FAIL: sp_registrar_entrega no creó el documento vinculado.', 1;

IF NOT EXISTS (
    SELECT 1 FROM dbo.entregas
    WHERE viaje_solicitud_id = @vs_id
      AND comprobante_url = CONCAT(N'/entregas/archivo.php?id=', @doc_id)
)
    THROW 50994, N'FAIL: dbo.entregas.comprobante_url no fue actualizado con la ruta protegida.', 1;

IF NOT EXISTS (
    SELECT 1 FROM dbo.documentos
    WHERE documento_id = @doc_id
      AND ruta_interna = N'0123456789abcdef0123456789abcdef.pdf'
      AND archivo_url = CONCAT(N'/entregas/archivo.php?id=', @doc_id)
)
    THROW 50995, N'FAIL: dbo.documentos no guardó los metadatos del archivo.', 1;

EXEC dbo.sp_obtener_documento_archivo @documento_id = @doc_id;

ROLLBACK TRANSACTION;
PRINT N'OK: Todas las pruebas contractuales de Migración 14 pasaron exitosamente.';
