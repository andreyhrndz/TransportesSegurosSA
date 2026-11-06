-- ============================================================
-- 02B - INDICES Y REGLAS UNICAS
-- Base de datos: TransportesSegurosSA
-- Ejecutar ANTES del archivo 04_crear_relaciones.sql.
-- ============================================================

USE TransportesSegurosSA;
GO

CREATE UNIQUE INDEX UQ_rutas_origen_id_destino_id_nombre
ON dbo.rutas (origen_id, destino_id, nombre);
GO

CREATE UNIQUE INDEX UQ_paradas_viaje_viaje_id_orden
ON dbo.paradas_viaje (viaje_id, orden);
GO

CREATE UNIQUE INDEX UQ_paradas_viaje_viaje_id_parada_id
ON dbo.paradas_viaje (viaje_id, parada_id);
GO

-- Un usuario puede estar asociado con un solo conductor.
CREATE UNIQUE INDEX UQ_conductores_usuario
ON dbo.conductores (usuario_id)
WHERE usuario_id IS NOT NULL;
GO

-- Impedir que un vehiculo realice dos viajes activos.
CREATE UNIQUE INDEX UQ_viajes_vehiculo_activo
ON dbo.viajes (vehiculo_id)
WHERE estado IN ('PLANIFICADO', 'EN_CURSO');
GO

-- Impedir que un conductor realice dos viajes activos.
CREATE UNIQUE INDEX UQ_viajes_conductor_activo
ON dbo.viajes (conductor_id)
WHERE estado IN ('PLANIFICADO', 'EN_CURSO');
GO
