USE TransportesSegurosSA;
GO


-- IMPORTANTE: toda la prueba se hace en una sola ventana de SSMS.
-- Asignamos el contexto del administrador solamente para el laboratorio.

DECLARE @admin INT =
(
    SELECT usuario_id FROM dbo.usuarios WHERE nombre_usuario = N'admin_proyecto'
);

IF @admin IS NULL
    THROW 50501, 'Ejecutar primero la creacion del administrador.', 1;

EXEC sys.sp_set_session_context @key = N'usuario_id', @value = @admin;

DECLARE @cliente_fisico INT;
DECLARE @cliente_juridico INT;
DECLARE @solicitud INT;
DECLARE @origen INT = (SELECT ubicacion_id FROM dbo.ubicaciones WHERE nombre = N'Nicoya');
DECLARE @santa_cruz INT = (SELECT ubicacion_id FROM dbo.ubicaciones WHERE nombre = N'Santa Cruz');
DECLARE @liberia INT = (SELECT ubicacion_id FROM dbo.ubicaciones WHERE nombre = N'Liberia');
DECLARE @tipo INT = (SELECT tipo_mercancia_id FROM dbo.tipos_mercancia WHERE nombre = N'Alimentos');
DECLARE @fecha2 DATETIME2 = DATEADD(DAY, 2, SYSUTCDATETIME());
DECLARE @fecha3 DATETIME2 = DATEADD(DAY, 3, SYSUTCDATETIME());
DECLARE @fecha4 DATETIME2 = DATEADD(DAY, 4, SYSUTCDATETIME());

-- 1. Cliente fisico.
EXEC dbo.sp_crear_cliente_fisico
    @identificacion = N'DEMO-FIS-001',
    @nombre = N'Carlos',
    @primer_apellido = N'Rodriguez',
    @segundo_apellido = N'Mora',
    @correo = N'carlos@ejemplo.test',
    @telefono = N'88881111',
    @cliente_id = @cliente_fisico OUTPUT;

-- 2. Cliente juridico.
EXEC dbo.sp_crear_cliente_juridico
    @identificacion = N'DEMO-JUR-001',
    @razon_social = N'Distribuidora Guanacaste S.A.',
    @nombre_comercial = N'Distribuidora Demo',
    @representante_legal = N'Ana Vargas',
    @correo = N'empresa@ejemplo.test',
    @cliente_id = @cliente_juridico OUTPUT;

-- 3. Primera solicitud, se entregara correctamente.
DECLARE @mercancias dbo.tipo_mercancias;

INSERT INTO @mercancias
(tipo_mercancia_id, descripcion, peso_kg, volumen_m3)
VALUES (@tipo, N'Cajas de alimentos A', 300.00, 2.000);

EXEC dbo.sp_crear_solicitud
    @codigo = N'DEMO-SOL-001',
    @cliente_id = @cliente_fisico,
    @origen_id = @origen,
    @destino_id = @santa_cruz,
    @destinatario_nombre = N'Maria',
    @destinatario_apellido1 = N'Lopez',
    @destinatario_apellido2 = N'Vargas',
    @destinatario_identificacion = N'DEMO-REC-001',
    @destinatario_telefono = N'87000001',
    @fecha_entrega_requerida = @fecha2,
    @mercancias = @mercancias,
    @solicitud_id = @solicitud OUTPUT;

-- 4. Segunda solicitud, se registrara como fallida.
DELETE FROM @mercancias;

INSERT INTO @mercancias
(tipo_mercancia_id, descripcion, peso_kg, volumen_m3)
VALUES (@tipo, N'Cajas de alimentos B', 250.00, 1.500);

EXEC dbo.sp_crear_solicitud
    @codigo = N'DEMO-SOL-002',
    @cliente_id = @cliente_juridico,
    @origen_id = @origen,
    @destino_id = @liberia,
    @destinatario_nombre = N'Jose',
    @destinatario_apellido1 = N'Perez',
    @destinatario_apellido2 = N'Lopez',
    @destinatario_identificacion = N'DEMO-REC-002',
    @destinatario_telefono = N'87000002',
    @fecha_entrega_requerida = @fecha3,
    @mercancias = @mercancias,
    @solicitud_id = @solicitud OUTPUT;

-- 5. Tercera solicitud, demasiado pesada para el mismo viaje.
DELETE FROM @mercancias;

INSERT INTO @mercancias
(tipo_mercancia_id, descripcion, peso_kg, volumen_m3)
VALUES (@tipo, N'Carga pesada de prueba', 800.00, 5.000);

EXEC dbo.sp_crear_solicitud
    @codigo = N'DEMO-SOL-003',
    @cliente_id = @cliente_fisico,
    @origen_id = @origen,
    @destino_id = @liberia,
    @destinatario_nombre = N'Laura',
    @destinatario_apellido1 = N'Mendez',
    @destinatario_apellido2 = N'Solis',
    @destinatario_identificacion = N'DEMO-REC-003',
    @destinatario_telefono = N'87000003',
    @fecha_entrega_requerida = @fecha4,
    @mercancias = @mercancias,
    @solicitud_id = @solicitud OUTPUT;

SELECT s.codigo_solicitud, s.estado, s.destinatario_nombre, m.peso_kg
FROM dbo.solicitudes s
JOIN dbo.mercancias m ON m.solicitud_id = s.solicitud_id
WHERE s.codigo_solicitud LIKE N'DEMO-SOL-%'
ORDER BY s.codigo_solicitud;

EXEC sys.sp_set_session_context @key = N'usuario_id', @value = NULL;
GO
