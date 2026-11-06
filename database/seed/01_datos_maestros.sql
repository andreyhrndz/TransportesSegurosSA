USE TransportesSegurosSA;
GO


-- Datos de DEMOSTRACION. No son clientes reales.
-- Ejecutar sobre una base nueva con los catalogos y SP creados.

INSERT INTO dbo.ubicaciones (nombre, direccion, latitud, longitud)
VALUES
(N'Nicoya', N'Centro de Nicoya', 10.1480, -85.4540),
(N'Santa Cruz', N'Centro de Santa Cruz', 10.2600, -85.5850),
(N'Liberia', N'Centro de Liberia', 10.6346, -85.4407),
(N'Filadelfia', N'Centro de Filadelfia', 10.4480, -85.5520);

INSERT INTO dbo.rutas
(
    nombre, origen_id, destino_id, distancia_km, duracion_estimada_min
)
SELECT
    N'Nicoya - Liberia (ruta demo)',
    (SELECT ubicacion_id FROM dbo.ubicaciones WHERE nombre = N'Nicoya'),
    (SELECT ubicacion_id FROM dbo.ubicaciones WHERE nombre = N'Liberia'),
    78.00,
    95;

INSERT INTO dbo.vehiculos
(
    placa, marca, modelo, anio, capacidad_carga_kg,
    capacidad_volumen_m3
)
VALUES
(N'DEMO-G6-001', N'Hino', N'Camion principal', 2021, 1000.00, 8.000),
(N'DEMO-G6-002', N'Isuzu', N'Camion pequeno', 2022, 200.00, 2.000);

INSERT INTO dbo.conductores
(
    identificacion, nombre, primer_apellido, segundo_apellido,
    numero_licencia, fecha_vencimiento_licencia
)
VALUES
(
    N'DEMO-COND-001', N'Pedro', N'Mora', N'Lopez',
    N'LIC-DEMO-001', DATEADD(YEAR, 2, CONVERT(DATE, SYSUTCDATETIME()))
);

SELECT * FROM dbo.vehiculos;
SELECT * FROM dbo.conductores;
SELECT * FROM dbo.ubicaciones;
GO
