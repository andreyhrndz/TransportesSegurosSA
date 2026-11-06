## 1. Contratos y lectura

- [x] 1.1 Confirmar firma real del TVP/SP y contratos de lectura de solicitudes, clientes, ubicaciones y tipos.
- [x] 1.2 Crear guards para `SOLICITUDES_VER`, `SOLICITUDES_CREAR`, ajuste y cancelación.
- [x] 1.3 Implementar lista/detalle con filtros, paginación, estados y destinatario esperado.

## 2. Operaciones

- [x] 2.1 Implementar formulario de alta con filas de mercancía validadas en servidor.
- [x] 2.2 Construir y enviar `dbo.tipo_mercancias` a `sp_crear_solicitud` de forma parametrizada.
- [x] 2.3 Implementar ajuste justificado y cancelación confirmada sólo en estados permitidos.

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
- En `TransportesSegurosSA_Test` se confirmó la firma real de `sp_crear_solicitud` y del TVP,
  y se ejecutó `sql/test-environment/03_transport_request_contract_test.sql`: una identidad
  efímera de mínimo privilegio creó una solicitud con dos mercancías, ajustó su prioridad y la
  canceló mediante los procedimientos existentes. Todos los datos se revirtieron.
- Los contratos de lectura de solicitudes, clientes, ubicaciones y tipos están desplegados sólo
  en Test. `public/solicitudes.php`, sus fragmentos HTMX y el detalle consumen exclusivamente
  `sp_listar_solicitudes` y `sp_obtener_solicitud` con `SOLICITUDES_VER`. La prueba PHP/PDO y
  la prueba HTTP con sesión temporal confirmaron lista, detalle, filtros y paginación.
- Alta, ajuste y cancelación se habilitan únicamente con sus permisos individuales y conservan
  como fuente de verdad los estados y conflictos del procedimiento SQL.

## Evidencia de implementación local

- `public/solicitudes/` incorpora alta, prioridad y cancelación con guards individuales, CSRF,
  validación de servidor y refresco HTMX. El formulario exige los tres campos de nombre del
  destinatario porque `destinatario_apellido2` es `NOT NULL` en el esquema real de Test.
- `tests/operational_write_integration_test.php` ejecuta el TVP mediante PDO_SQLSRV, completa
  alta/prioridad/cancelación dentro de una transacción y revierte todos los datos en Test.
- Las pruebas exhaustivas de escenarios inválidos, concurrencia y revisión visual responsive
  permanecen pendientes; no se marcaron las tareas 3.x.
