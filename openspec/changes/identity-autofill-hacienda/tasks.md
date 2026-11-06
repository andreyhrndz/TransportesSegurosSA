# Tareas de implementación — `identity-autofill-hacienda`

## 1. Fase Backend: Servicio de integración con Hacienda

- [x] 1.1 Implementar `app/hacienda_service.php` con cURL HTTPS, timeout de 5 segundos, validación de formato de cédula, caché efímera y manejo de respuestas 200, 404, 429 y 5xx.
- [x] 1.2 Crear el controlador `public/api/hacienda.php` con verificación de sesión/token y respuesta JSON estructurada.
- [x] 1.3 Crear suite de pruebas unitarias `tests/hacienda_service_test.php` con respuestas simuladas de Hacienda (éxito, no encontrado, timeout, 429 y sanitización).

## 2. Fase Frontend: Identificación primero y destinatario condicional

- [x] 2.1 Reorganizar los formularios de clientes (`public/clientes/form.php`, `public/clientes/editar_form.php`) para ubicar la identificación primero e integrar el botón «Consultar Hacienda».
- [x] 2.2 Reorganizar `public/solicitudes/form.php` para incorporar la pregunta «¿Quién recibirá la mercancía?», autocompletando datos para cliente físico y desplegando identificación primero con consulta Hacienda para terceros.
- [x] 2.3 Adaptar `public/solicitudes/guardar.php` para validar de forma robusta los datos de destinatario esperado según la modalidad elegida.
