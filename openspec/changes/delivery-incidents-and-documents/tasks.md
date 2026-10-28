## 1. Consulta y acceso

- [ ] 1.1 Confirmar contratos de lectura de entregas, solicitudes, incidencias, tipos y documentos.
- [ ] 1.2 Aplicar permisos `ENTREGAS_REGISTRAR`, `INCIDENCIAS_*` y `DOCUMENTOS_REGISTRAR` por acción.
- [ ] 1.3 Implementar detalle que separe destinatario esperado y receptor efectivo.

## 2. Operaciones

- [ ] 2.1 Implementar registro de entrega en tránsito con campos condicionales de éxito/fallo.
- [ ] 2.2 Implementar alta y resolución de incidencias mediante los SP existentes.
- [ ] 2.3 Implementar registro de documento como metadato/referencia URL, sin subida de archivos.

## 3. Verificación

- [ ] 3.1 Probar éxito sin comprobante, fallo sin motivo, duplicado y viaje no válido.
- [ ] 3.2 Probar permisos, CSRF, URLs malformadas y salida escapada.
- [ ] 3.3 Probar responsive, teclado y validación estricta.
