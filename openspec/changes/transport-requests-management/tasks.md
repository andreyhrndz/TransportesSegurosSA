## 1. Contratos y lectura

- [ ] 1.1 Confirmar firma real del TVP/SP y contratos de lectura de solicitudes, clientes, ubicaciones y tipos.
- [ ] 1.2 Crear guards para `SOLICITUDES_VER`, `SOLICITUDES_CREAR`, ajuste y cancelación.
- [ ] 1.3 Implementar lista/detalle con filtros, paginación, estados y destinatario esperado.

## 2. Operaciones

- [ ] 2.1 Implementar formulario de alta con filas de mercancía validadas en servidor.
- [ ] 2.2 Construir y enviar `dbo.tipo_mercancias` a `sp_crear_solicitud` de forma parametrizada.
- [ ] 2.3 Implementar ajuste justificado y cancelación confirmada sólo en estados permitidos.

## 3. Verificación

- [ ] 3.1 Probar mercancía ausente/inválida, fecha pasada, origen igual y cliente inactivo.
- [ ] 3.2 Probar permisos, CSRF, estados concurrentes y escape de texto.
- [ ] 3.3 Probar desktop/tablet/móvil y validación estricta.
