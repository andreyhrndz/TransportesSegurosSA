USE TransportesSegurosSA;
GO


-- Catalogos minimos para las pruebas del proyecto.
-- Ejecutar una sola vez sobre la base vacia.

INSERT INTO dbo.tipos_mercancia (nombre, descripcion)
VALUES
(N'Alimentos', N'Productos alimenticios'),
(N'Electronica', N'Equipos electronicos'),
(N'Muebles', N'Mobiliario'),
(N'Otros', N'Mercancia general');

INSERT INTO dbo.tipos_incidencia (nombre, descripcion)
VALUES
(N'Retraso', N'Demora en el transporte'),
(N'Mercancia', N'Dano o faltante'),
(N'Mecanica', N'Problema del vehiculo'),
(N'Otra', N'Incidencia general');

INSERT INTO dbo.tipos_documento (nombre, descripcion)
VALUES
(N'Guia de transporte', N'Documento de transporte'),
(N'Factura', N'Comprobante comercial'),
(N'Comprobante de entrega', N'Recepcion de mercancia');

INSERT INTO dbo.permisos (codigo, nombre)
VALUES
(N'CLIENTES_CREAR', N'Registrar clientes'),
(N'SOLICITUDES_CREAR', N'Crear solicitudes'),
(N'SOLICITUDES_AJUSTAR_PRIORIDAD', N'Ajustar prioridad'),
(N'SOLICITUDES_CANCELAR', N'Cancelar solicitudes'),
(N'VIAJES_CREAR', N'Crear viajes'),
(N'VIAJES_PARADAS', N'Crear paradas de viaje'),
(N'VIAJES_ASIGNAR', N'Asignar solicitudes a viajes'),
(N'VIAJES_INICIAR', N'Iniciar viajes'),
(N'VIAJES_FINALIZAR', N'Finalizar viajes'),
(N'ENTREGAS_REGISTRAR', N'Registrar entregas'),
(N'INCIDENCIAS_REGISTRAR', N'Registrar incidencias'),
(N'INCIDENCIAS_RESOLVER', N'Resolver incidencias'),
(N'DOCUMENTOS_REGISTRAR', N'Registrar documentos'),
(N'USUARIOS_GESTIONAR_PERMISOS', N'Conceder y revocar permisos'),
(N'REPORTES_VER', N'Consultar reportes');

SELECT 'Catalogos creados' AS Resultado;
GO
