## 1. Sesión y acceso

- [x] 1.1 Inventariar rutas, fragmentos HTMX y permisos realmente invocados.
- [x] 1.2 Implementar guards reutilizables para sesión válida, permiso y formato de respuesta.
- [x] 1.3 Revisar regeneración, expiración, cookie y destrucción de sesión sin registrar secretos.

## 2. Protección de solicitudes

- [x] 2.1 Aplicar CSRF a toda acción POST y respuestas seguras a token inválido.
- [x] 2.2 Conservar el límite de intentos de login y normalizar mensajes de error.
- [x] 2.3 Documentar variables locales, TLS de producción y cuenta SQL de mínimo privilegio.

## 3. Verificación

- [ ] 3.1 Probar login válido, usuario inactivo, contraseña errónea, logout y sesión expirada.
- [ ] 3.2 Probar 403, CSRF inválido y comportamiento HTMX sin revelar detalles SQL.
- [ ] 3.3 Ejecutar smoke test de dashboard/clientes y validar el change estrictamente.

## Evidencia y limitaciones

- Sintaxis PHP de todas las rutas de app/ y public/: correcta con PHP 8.4.
- Prueba de proceso aislado: generación/validación CSRF y límite de sesión vigente/expirada: correcta.
- Regresión HTTP automatizada: GET de login crea token y cookie, el POST con ambos pasa CSRF y los POST sin token, con token incorrecto o con cookie de otra sesión son rechazados. Ejecutar con PHP 8.4: `php tests/session_csrf_regression.php`.
- Regresión de recursos: favicon estático responde sin sesión; favicon ICO inexistente responde 404 plano sin cookie ni redirección; cargas paralelas de login/favicon conservan la sesión y el token original.
- openspec validate frontend-auth-and-permissions-hardening --strict: correcto.
- Sin una base de pruebas autorizada no se realizaron intentos de login, consultas de usuarios/permisos, altas de clientes ni pruebas de usuario inactivo. Por ello las tareas de verificación 3.1, 3.2 y 3.3 siguen abiertas.
