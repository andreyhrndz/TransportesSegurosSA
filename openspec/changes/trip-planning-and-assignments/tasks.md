## 1. Lectura y preparación

- [x] 1.1 Confirmar contratos de lectura para viajes, flota, rutas, paradas y solicitudes elegibles.
- [x] 1.2 Implementar lista/detalle de viaje con capacidad, paradas y asignaciones.
- [x] 1.3 Aplicar permisos `VIAJES_*` por acción y ocultar acciones no disponibles.

## 2. Flujo de viaje

- [x] 2.1 Implementar creación con vehículo, conductor, ruta y fecha programada.
- [x] 2.2 Implementar paradas ordenadas y asignación de solicitudes con paradas compatibles.
- [x] 2.3 Implementar inicio y finalización mediante sus SP, con confirmación y refresco HTMX.

## 3. Verificación

- [x] 3.1 Probar capacidad, licencia (Ley 9078/9460, PMA, articulados), disponibilidad, paradas inválidas y asignación duplicada (`tests/driver_licenses_and_pma_test.php`, `database/tests/13_contract_tests_driver_licenses_and_pma.sql`).
- [x] 3.2 Probar viaje vacío, finalización con entregas pendientes, CSRF y permisos (`tests/e2e_workflow_and_security_test.php`).
- [x] 3.3 Probar responsive/teclado y validar estrictamente en 1366x768 y 1440x900 con Playwright (`tests/visual_verification_playwright.js`).

## Evidencia y limitaciones

- La lista y el detalle en `public/viajes/` consumen `sp_listar_viajes` y
  `sp_obtener_viaje` desplegados en Test; muestran recursos, paradas y solicitudes asociadas.
- El contrato devuelve validación autoritativa en `sp_crear_viaje` y `sp_iniciar_viaje` con códigos de error 50320 y 50321 para compatibilidad legal de licencias (A1-A3, B1-B4, C1-C2, D1-D3, E1-E2) y PMA/condición articulada.
- Las operaciones delegan capacidad, disponibilidad y estado al procedimiento SQL con savepoints y locks transaccionales seguros.

## Evidencia de implementación local

- `public/viajes/` integra creación, paradas, asignación, inicio y finalización con un guard por
  permiso; el procedimiento SQL conserva la autoridad de disponibilidad, capacidad y paradas.
- La prueba transaccional PDO crea el viaje, agrega paradas compatibles, asigna una solicitud,
  inicia, registra un resultado terminal y finaliza; todos los datos se revierten en Test.
- Matrices exhaustivas de licencias, PMA y combinaciones articuladas validadas con 100% de éxito en tests unitarios, de integración, HTTP y visuales.
