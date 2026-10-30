## 1. Consulta y acceso

- [x] 1.1 Confirmar contratos de lectura de entregas, solicitudes, incidencias, tipos y documentos.
- [ ] 1.2 Aplicar permisos `ENTREGAS_REGISTRAR`, `INCIDENCIAS_*` y `DOCUMENTOS_REGISTRAR` por acción.
- [ ] 1.3 Implementar detalle que separe destinatario esperado y receptor efectivo.
- [x] 1.4 Implementar historial de notificaciones de sólo lectura si el contrato `NOTIFICACIONES_VER` está desplegado.

## 2. Operaciones

- [ ] 2.1 Implementar registro de entrega en tránsito con campos condicionales de éxito/fallo.
- [ ] 2.2 Implementar alta y resolución de incidencias mediante los SP existentes.
- [ ] 2.3 Implementar registro de documento como metadato/referencia URL, sin subida de archivos.

## 3. Verificación

- [ ] 3.1 Probar éxito sin comprobante, fallo sin motivo, duplicado y viaje no válido.
- [ ] 3.2 Probar permisos, CSRF, URLs malformadas y salida escapada.
- [ ] 3.3 Probar que consultar notificaciones no envía, reintenta ni elimina mensajes.
- [ ] 3.4 Probar responsive, teclado y validación estricta.

## Evidencia y limitaciones

- `public/entregas.php` consulta entregas, incidencias, notificaciones y documentos mediante sus
  procedimientos `sp_listar_*` de Test, con permiso individual por sección. Los comprobantes y
  referencias sólo se enlazan cuando la URL tiene esquema permitido; no se descargan ni ejecutan.
- Notificaciones es exclusivamente historial y no invoca servicios de envío, reintento o borrado.
  Las acciones de entrega, incidencias y documentos no se habilitaron en esta tanda.
