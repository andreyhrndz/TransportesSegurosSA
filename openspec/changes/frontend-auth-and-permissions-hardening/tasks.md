## 1. Sesión y acceso

- [ ] 1.1 Inventariar rutas, fragmentos HTMX y permisos realmente invocados.
- [ ] 1.2 Implementar guards reutilizables para sesión válida, permiso y formato de respuesta.
- [ ] 1.3 Revisar regeneración, expiración, cookie y destrucción de sesión sin registrar secretos.

## 2. Protección de solicitudes

- [ ] 2.1 Aplicar CSRF a toda acción POST y respuestas seguras a token inválido.
- [ ] 2.2 Conservar el límite de intentos de login y normalizar mensajes de error.
- [ ] 2.3 Documentar variables locales, TLS de producción y cuenta SQL de mínimo privilegio.

## 3. Verificación

- [ ] 3.1 Probar login válido, usuario inactivo, contraseña errónea, logout y sesión expirada.
- [ ] 3.2 Probar 403, CSRF inválido y comportamiento HTMX sin revelar detalles SQL.
- [ ] 3.3 Ejecutar smoke test de dashboard/clientes y validar el change estrictamente.
