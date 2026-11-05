# Exposicion corta del Grupo 6

1. Mostrar `00_MODELO/Modelo_24_Tablas_Sin_GPS.dbml` y el diagrama. Se eliminaron GPS, mantenimiento preventivo, roles y encuestas.
2. Mostrar `clientes`, `solicitudes`, `mercancias` y destinatario con dos apellidos.
3. Mostrar `viajes`, `paradas_viaje` y `viaje_solicitudes`: un viaje, dos entregas distintas.
4. Mostrar `entregas`: una entrega fue exitosa con nombre del receptor, la otra fallida con motivo. Cada solicitud tiene como maximo una entrega.
5. Mostrar el rechazo de exceso de carga (en la prueba 05/03). Explicar transacciones y rollback.
6. Mostrar permisos y auditoria. Distinguir `usuarios` de `permisos` (no existen roles).
7. Mostrar `TransportesSegurosSA_DW`, dos ejecuciones sin duplicados.
8. Mostrar backups FULL y DIFF, `RESTORE VERIFYONLY` y base restaurada separada.
9. Mostrar SQL Server Agent y las tres tareas configuradas.
10. **Replicacion:** explicar que permanece pendiente de una segunda laptop; NO presentarla como validada.

Los archivos `comprobantes/*.pdf` son **rutas de demostracion, no comprobantes reales**. No se generan ni firman PDFs. El control de acceso mediante `SESSION_CONTEXT` es una simulacion academica; en produccion debe provenir de un backend que controle la identidad.
