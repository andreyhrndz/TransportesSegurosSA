## 1. Integración de contratos

- [x] 1.1 Confirmar despliegue y firmas de lectura, edición y desactivación en `TransportesSegurosSA_Test`.
- [x] 1.2 Aplicar guards `CLIENTES_VER`, `CLIENTES_CREAR`, `CLIENTES_EDITAR` y `CLIENTES_DESACTIVAR` según capacidad.
- [x] 1.3 Adaptar consultas y errores SQL a contratos parametrizados (sin `SELECT` directo en altas ni mutaciones).

## 2. Interfaz

- [x] 2.1 Implementar directorio, filtros, paginación y estado vacío con componentes reutilizables.
- [x] 2.2 Implementar detalle y formularios por subtipo sin conversión de tipo ni edición de identificación.
- [x] 2.3 Implementar confirmación y refresco HTMX para edición y desactivación.

## 3. Verificación

- [x] 3.1 Probar autorizaciones, CSRF, duplicados, conflictos, subtipo incompatible y escape HTML.
- [ ] 3.2 Probar escritorio, tablet, móvil, teclado y lector de pantalla básico (pendiente para el change final `release-quality-and-accessibility`).
- [x] 3.3 Ejecutar smoke de login/dashboard/clientes y validación estricta.

## Evidencia y límites

- `public/clientes.php`, `public/clientes/lista.php`, `public/clientes/detalle.php`, `public/clientes/guardar.php`, `public/clientes/editar_form.php`, `public/clientes/actualizar.php`, `public/clientes/desactivar_form.php` y `public/clientes/desactivar.php` consumen exclusivamente procedimientos almacenados autorizados con CSRF y escape HTML.
- Verificado en `TransportesSegurosSA_Test` mediante `tests/customer_http_functional_test.php`, `tests/session_csrf_regression.php` y `tests/operational_routes_regression.php`.
- La verificación exhaustiva de responsive móvil/tablet y lector de pantalla se reserva para `release-quality-and-accessibility`.
