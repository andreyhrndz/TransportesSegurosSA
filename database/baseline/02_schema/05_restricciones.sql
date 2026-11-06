-- ============================================================
-- 05 - VALIDACIONES BASICAS CON CHECK
-- Base de datos: TransportesSegurosSA
-- 
-- ============================================================

USE TransportesSegurosSA;
GO

ALTER TABLE dbo.ubicaciones
ADD CONSTRAINT CK_ubicaciones_Latitud
CHECK (latitud IS NULL OR latitud BETWEEN -90 AND 90);
GO

ALTER TABLE dbo.ubicaciones
ADD CONSTRAINT CK_ubicaciones_Longitud
CHECK (longitud IS NULL OR longitud BETWEEN -180 AND 180);
GO

ALTER TABLE dbo.solicitudes
ADD CONSTRAINT CK_solicitudes_DistintoOrigenDestino
CHECK (origen_id <> destino_id);
GO

ALTER TABLE dbo.solicitudes
ADD CONSTRAINT CK_solicitudes_PrioridadCalculada
CHECK (prioridad_calculada IN ('NORMAL','ALTA','URGENTE'));
GO

ALTER TABLE dbo.solicitudes
ADD CONSTRAINT CK_solicitudes_PrioridadActual
CHECK (prioridad_actual IN ('NORMAL','ALTA','URGENTE'));
GO

ALTER TABLE dbo.solicitudes
ADD CONSTRAINT CK_solicitudes_Estado
CHECK (estado IN ('REGISTRADA','PENDIENTE_ASIGNACION','ASIGNADA','EN_TRANSITO','ENTREGADA','ENTREGA_FALLIDA','CANCELADA'));
GO

ALTER TABLE dbo.solicitudes
ADD CONSTRAINT CK_solicitudes_Fechas
CHECK (fecha_entrega_requerida >= fecha_solicitud);
GO

ALTER TABLE dbo.solicitudes
ADD CONSTRAINT CK_solicitudes_PrioridadJustificada
CHECK (prioridad_actual = prioridad_calculada OR NULLIF(LTRIM(RTRIM(justificacion_prioridad)),N'') IS NOT NULL);
GO

ALTER TABLE dbo.mercancias
ADD CONSTRAINT CK_mercancias_Peso
CHECK (peso_kg > 0);
GO

ALTER TABLE dbo.mercancias
ADD CONSTRAINT CK_mercancias_Volumen
CHECK (volumen_m3 IS NULL OR volumen_m3 > 0);
GO

ALTER TABLE dbo.vehiculos
ADD CONSTRAINT CK_vehiculos_Carga
CHECK (capacidad_carga_kg > 0);
GO

ALTER TABLE dbo.vehiculos
ADD CONSTRAINT CK_vehiculos_Volumen
CHECK (capacidad_volumen_m3 IS NULL OR capacidad_volumen_m3 > 0);
GO

ALTER TABLE dbo.vehiculos
ADD CONSTRAINT CK_vehiculos_Kilometraje
CHECK (kilometraje_actual >= 0);
GO

ALTER TABLE dbo.vehiculos
ADD CONSTRAINT CK_vehiculos_Estado
CHECK (estado IN ('DISPONIBLE','ASIGNADO','MANTENIMIENTO','FUERA_SERVICIO'));
GO

ALTER TABLE dbo.conductores
ADD CONSTRAINT CK_conductores_Estado
CHECK (estado_operativo IN ('DISPONIBLE','ASIGNADO','INACTIVO'));
GO

ALTER TABLE dbo.rutas
ADD CONSTRAINT CK_rutas_Distancia
CHECK (distancia_km > 0);
GO

ALTER TABLE dbo.rutas
ADD CONSTRAINT CK_rutas_Duracion
CHECK (duracion_estimada_min > 0);
GO

ALTER TABLE dbo.rutas
ADD CONSTRAINT CK_rutas_OrigenDestino
CHECK (origen_id <> destino_id);
GO

ALTER TABLE dbo.viajes
ADD CONSTRAINT CK_viajes_Estado
CHECK (estado IN ('PLANIFICADO','EN_CURSO','FINALIZADO','CANCELADO'));
GO

ALTER TABLE dbo.viajes
ADD CONSTRAINT CK_viajes_FechaLlegada
CHECK (fecha_llegada_real IS NULL OR fecha_salida_real IS NOT NULL);
GO

ALTER TABLE dbo.paradas_viaje
ADD CONSTRAINT CK_paradas_viaje_Orden
CHECK (orden > 0);
GO

ALTER TABLE dbo.paradas_viaje
ADD CONSTRAINT CK_paradas_viaje_Tipo
CHECK (tipo_parada IN ('RECOGIDA','ENTREGA','MIXTA','INTERMEDIA'));
GO

ALTER TABLE dbo.paradas_viaje
ADD CONSTRAINT CK_paradas_viaje_Estado
CHECK (estado IN ('PENDIENTE','EN_CURSO','COMPLETADA','OMITIDA'));
GO

ALTER TABLE dbo.viaje_solicitudes
ADD CONSTRAINT CK_viaje_solicitudes_Estado
CHECK (estado IN ('PLANIFICADA','CARGADA','EN_TRANSITO','ENTREGADA','FALLIDA','RETIRADA'));
GO

ALTER TABLE dbo.viaje_solicitudes
ADD CONSTRAINT CK_viaje_solicitudes_ParadasDistintas
CHECK (parada_recogida_id <> parada_entrega_id);
GO

ALTER TABLE dbo.entregas
ADD CONSTRAINT CK_entregas_Estado
CHECK (estado IN ('ENTREGADA','FALLIDA'));
GO

ALTER TABLE dbo.entregas
ADD CONSTRAINT CK_entregas_DatosResultado
CHECK ((estado = 'ENTREGADA' AND NULLIF(LTRIM(RTRIM(receptor_nombre)),N'') IS NOT NULL AND fecha_recepcion IS NOT NULL AND motivo_fallo IS NULL) OR (estado = 'FALLIDA' AND NULLIF(LTRIM(RTRIM(motivo_fallo)),N'') IS NOT NULL AND fecha_recepcion IS NULL));
GO

ALTER TABLE dbo.incidencias
ADD CONSTRAINT CK_incidencias_Estado
CHECK (estado IN ('ABIERTA','EN_ATENCION','RESUELTA'));
GO

ALTER TABLE dbo.notificaciones
ADD CONSTRAINT CK_notificaciones_Estado
CHECK (estado_envio IN ('PENDIENTE','ENVIADA','ERROR'));
GO
