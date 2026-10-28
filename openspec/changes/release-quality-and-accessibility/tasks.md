## 1. Estrategia

- [ ] 1.1 Inventariar flujos, permisos, estados y contratos SQL por change.
- [ ] 1.2 Definir matriz de pruebas PHP, SQL de sólo lectura/escritura controlada y navegador.
- [ ] 1.3 Ejecutar y registrar el smoke SQL en el equipo principal con PDO_SQLSRV y ODBC Driver 18, sin degradar TLS para adaptar Codex.

## 2. Accesibilidad y calidad

- [ ] 2.1 Revisar contraste AA, foco, teclado, roles, mensajes, errores y movimiento reducido.
- [ ] 2.2 Ejecutar smoke de login, dashboard, clientes, HTMX, CSRF y controles de permiso.
- [ ] 2.3 Ejecutar pruebas de los módulos nuevos en desktop, tablet y móvil.

## 3. Release

- [ ] 3.1 Documentar configuración de desarrollo, producción y secretos fuera del repositorio.
- [ ] 3.2 Preparar checklist de rollback, evidencia y criterios de aprobación humana.
- [ ] 3.3 Validar el change estrictamente sin publicar ni hacer merge.
