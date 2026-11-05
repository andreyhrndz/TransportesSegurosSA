-- ============================================================
-- 03 - CREAR RELACIONES ENTRE TABLAS
-- Base de datos: TransportesSegurosSA
-- Claves foraneas sin borrado automatico.
-- ============================================================

USE TransportesSegurosSA;
GO

-- clientes_fisicos -> clientes
ALTER TABLE dbo.clientes_fisicos
ADD CONSTRAINT FK_clientes_fisicos_clientes_01
FOREIGN KEY (cliente_id)
REFERENCES dbo.clientes (cliente_id);
GO

-- clientes_juridicos -> clientes
ALTER TABLE dbo.clientes_juridicos
ADD CONSTRAINT FK_clientes_juridicos_clientes_02
FOREIGN KEY (cliente_id)
REFERENCES dbo.clientes (cliente_id);
GO

-- solicitudes -> clientes
ALTER TABLE dbo.solicitudes
ADD CONSTRAINT FK_solicitudes_clientes_03
FOREIGN KEY (cliente_id)
REFERENCES dbo.clientes (cliente_id);
GO

-- solicitudes -> ubicaciones
ALTER TABLE dbo.solicitudes
ADD CONSTRAINT FK_solicitudes_ubicaciones_04
FOREIGN KEY (origen_id)
REFERENCES dbo.ubicaciones (ubicacion_id);
GO

-- solicitudes -> ubicaciones
ALTER TABLE dbo.solicitudes
ADD CONSTRAINT FK_solicitudes_ubicaciones_05
FOREIGN KEY (destino_id)
REFERENCES dbo.ubicaciones (ubicacion_id);
GO

-- mercancias -> solicitudes
ALTER TABLE dbo.mercancias
ADD CONSTRAINT FK_mercancias_solicitudes_06
FOREIGN KEY (solicitud_id)
REFERENCES dbo.solicitudes (solicitud_id);
GO

-- mercancias -> tipos_mercancia
ALTER TABLE dbo.mercancias
ADD CONSTRAINT FK_mercancias_tipos_mercancia_07
FOREIGN KEY (tipo_mercancia_id)
REFERENCES dbo.tipos_mercancia (tipo_mercancia_id);
GO

-- usuarios_permisos -> usuarios
ALTER TABLE dbo.usuarios_permisos
ADD CONSTRAINT FK_usuarios_permisos_usuarios_08
FOREIGN KEY (usuario_id)
REFERENCES dbo.usuarios (usuario_id);
GO

-- usuarios_permisos -> permisos
ALTER TABLE dbo.usuarios_permisos
ADD CONSTRAINT FK_usuarios_permisos_permisos_09
FOREIGN KEY (permiso_id)
REFERENCES dbo.permisos (permiso_id);
GO

-- conductores -> usuarios
ALTER TABLE dbo.conductores
ADD CONSTRAINT FK_conductores_usuarios_10
FOREIGN KEY (usuario_id)
REFERENCES dbo.usuarios (usuario_id);
GO

-- rutas -> ubicaciones
ALTER TABLE dbo.rutas
ADD CONSTRAINT FK_rutas_ubicaciones_11
FOREIGN KEY (origen_id)
REFERENCES dbo.ubicaciones (ubicacion_id);
GO

-- rutas -> ubicaciones
ALTER TABLE dbo.rutas
ADD CONSTRAINT FK_rutas_ubicaciones_12
FOREIGN KEY (destino_id)
REFERENCES dbo.ubicaciones (ubicacion_id);
GO

-- viajes -> vehiculos
ALTER TABLE dbo.viajes
ADD CONSTRAINT FK_viajes_vehiculos_13
FOREIGN KEY (vehiculo_id)
REFERENCES dbo.vehiculos (vehiculo_id);
GO

-- viajes -> conductores
ALTER TABLE dbo.viajes
ADD CONSTRAINT FK_viajes_conductores_14
FOREIGN KEY (conductor_id)
REFERENCES dbo.conductores (conductor_id);
GO

-- viajes -> rutas
ALTER TABLE dbo.viajes
ADD CONSTRAINT FK_viajes_rutas_15
FOREIGN KEY (ruta_id)
REFERENCES dbo.rutas (ruta_id);
GO

-- paradas_viaje -> viajes
ALTER TABLE dbo.paradas_viaje
ADD CONSTRAINT FK_paradas_viaje_viajes_16
FOREIGN KEY (viaje_id)
REFERENCES dbo.viajes (viaje_id);
GO

-- paradas_viaje -> ubicaciones
ALTER TABLE dbo.paradas_viaje
ADD CONSTRAINT FK_paradas_viaje_ubicaciones_17
FOREIGN KEY (ubicacion_id)
REFERENCES dbo.ubicaciones (ubicacion_id);
GO

-- viaje_solicitudes -> viajes
ALTER TABLE dbo.viaje_solicitudes
ADD CONSTRAINT FK_viaje_solicitudes_viajes_18
FOREIGN KEY (viaje_id)
REFERENCES dbo.viajes (viaje_id);
GO

-- viaje_solicitudes -> solicitudes
ALTER TABLE dbo.viaje_solicitudes
ADD CONSTRAINT FK_viaje_solicitudes_solicitudes_19
FOREIGN KEY (solicitud_id)
REFERENCES dbo.solicitudes (solicitud_id);
GO

-- viaje_solicitudes -> paradas_viaje
ALTER TABLE dbo.viaje_solicitudes
ADD CONSTRAINT FK_viaje_solicitudes_paradas_viaje_20
FOREIGN KEY (viaje_id, parada_recogida_id)
REFERENCES dbo.paradas_viaje (viaje_id, parada_id);
GO

-- viaje_solicitudes -> paradas_viaje
ALTER TABLE dbo.viaje_solicitudes
ADD CONSTRAINT FK_viaje_solicitudes_paradas_viaje_21
FOREIGN KEY (viaje_id, parada_entrega_id)
REFERENCES dbo.paradas_viaje (viaje_id, parada_id);
GO

-- entregas -> viaje_solicitudes
ALTER TABLE dbo.entregas
ADD CONSTRAINT FK_entregas_viaje_solicitudes_22
FOREIGN KEY (viaje_solicitud_id)
REFERENCES dbo.viaje_solicitudes (viaje_solicitud_id);
GO

-- entregas -> usuarios
ALTER TABLE dbo.entregas
ADD CONSTRAINT FK_entregas_usuarios_23
FOREIGN KEY (registrado_por_usuario_id)
REFERENCES dbo.usuarios (usuario_id);
GO

-- incidencias -> solicitudes
ALTER TABLE dbo.incidencias
ADD CONSTRAINT FK_incidencias_solicitudes_24
FOREIGN KEY (solicitud_id)
REFERENCES dbo.solicitudes (solicitud_id);
GO

-- incidencias -> tipos_incidencia
ALTER TABLE dbo.incidencias
ADD CONSTRAINT FK_incidencias_tipos_incidencia_25
FOREIGN KEY (tipo_incidencia_id)
REFERENCES dbo.tipos_incidencia (tipo_incidencia_id);
GO

-- incidencias -> usuarios
ALTER TABLE dbo.incidencias
ADD CONSTRAINT FK_incidencias_usuarios_26
FOREIGN KEY (responsable_usuario_id)
REFERENCES dbo.usuarios (usuario_id);
GO

-- notificaciones -> solicitudes
ALTER TABLE dbo.notificaciones
ADD CONSTRAINT FK_notificaciones_solicitudes_27
FOREIGN KEY (solicitud_id)
REFERENCES dbo.solicitudes (solicitud_id);
GO

-- documentos -> solicitudes
ALTER TABLE dbo.documentos
ADD CONSTRAINT FK_documentos_solicitudes_28
FOREIGN KEY (solicitud_id)
REFERENCES dbo.solicitudes (solicitud_id);
GO

-- documentos -> tipos_documento
ALTER TABLE dbo.documentos
ADD CONSTRAINT FK_documentos_tipos_documento_29
FOREIGN KEY (tipo_documento_id)
REFERENCES dbo.tipos_documento (tipo_documento_id);
GO

-- historial_estados_solicitud -> solicitudes
ALTER TABLE dbo.historial_estados_solicitud
ADD CONSTRAINT FK_historial_estados_solicitud_solicitudes_30
FOREIGN KEY (solicitud_id)
REFERENCES dbo.solicitudes (solicitud_id);
GO

-- historial_estados_solicitud -> usuarios
ALTER TABLE dbo.historial_estados_solicitud
ADD CONSTRAINT FK_historial_estados_solicitud_usuarios_31
FOREIGN KEY (usuario_id)
REFERENCES dbo.usuarios (usuario_id);
GO

-- auditorias -> usuarios
ALTER TABLE dbo.auditorias
ADD CONSTRAINT FK_auditorias_usuarios_32
FOREIGN KEY (usuario_id)
REFERENCES dbo.usuarios (usuario_id);
GO
