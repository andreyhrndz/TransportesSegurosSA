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

## Evidencia y limitaciones

- Se verificó contra los scripts fuente que `dbo.tipo_mercancias` tiene, en este orden,
  `tipo_mercancia_id`, `descripcion`, `peso_kg DECIMAL(12,2)` y
  `volumen_m3 DECIMAL(12,3) NULL`. `app/transport_request_tvp.php` construye esa
  estructura para PDO_SQLSRV y `tests/transport_request_tvp_test.php` rechaza
  mercancías vacías, tipos inválidos, texto vacío, peso no positivo y escalas inválidas,
  sin conexión ni escrituras SQL.
- La integración de la llamada a `sp_crear_solicitud` y la UI siguen bloqueadas:
  los contratos de lectura para clientes, ubicaciones, tipos y solicitudes aún no se han
  desplegado ni validado por el DBA. No se muestra un módulo ni botones de solicitudes
  hasta que esos contratos estén aprobados y probados en una base aislada.
