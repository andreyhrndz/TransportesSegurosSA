# Tareas

## 1. Auditoría y baseline

- [x] 1.1 Inspeccionar frontend local real y documentar inventario de componentes/eventos HTMX y diferencias respecto a esta propuesta.
- [ ] 1.2 Registrar evidencia de comportamiento actual: login, panel, listado y alta de clientes.
- [x] 1.3 Revisar paleta elegida para cumplir contraste WCAG AA y dejar tokens definitivos en `docs/guia-visual.md`.

## 2. Sistema de diseño

- [x] 2.1 Refactorizar CSS a tokens semánticos, estilos mantenibles y variantes consistentes (sin introducir framework adicional).
- [x] 2.2 Crear reutilización de componentes PHP donde realmente exista duplicación: botón, campos, alertas, tablas, modal y KPI.
- [x] 2.3 Unificar estados de foco, deshabilitado, error, validación, éxito, vacío y carga.

## 3. Navegación y experiencia

- [x] 3.1 Estandarizar sidebar, header, textos visibles y navegación desktop/tablet/móvil.
- [x] 3.2 Reemplazar iconos Unicode improvisados por iconos SVG coherentes con etiquetas accesibles.
- [x] 3.3 Acortar textos de acciones manteniendo significado y accesibilidad.
- [x] 3.4 Ajustar presentación de KPI, tablas y fechas visibles sin cambiar consulta ni datos.

## 4. Integridad funcional

- [ ] 4.1 Confirmar que login/logout y protección de sesión funcionan sin regresiones.
- [ ] 4.2 Confirmar búsqueda, paginación, modales, HTMX, CSRF y registro de ambos tipos de clientes.
- [x] 4.3 Comprobar que no se exponen funcionalidades inexistentes ni mensajes académicos.

## 5. Calidad y cierre

- [ ] 5.1 Probar escritorio, tablet y móvil; registrar tamaños evaluados y defectos corregidos.
- [ ] 5.2 Verificar teclado/foco, contraste, mensajes y accesibilidad de controles.
- [x] 5.3 Ejecutar `openspec validate frontend-foundation-design-system --strict`, documentar pruebas/limitaciones y dejar el change listo para revisión (sin archivarlo).

## Evidencia y limitaciones

- Sintaxis PHP de todas las rutas de app/ y public/: correcta con PHP 8.4.
- openspec validate frontend-foundation-design-system --strict: correcto.
- Pruebas HTTP locales sin credenciales: el formulario de login conserva cookie/CSRF entre
  GET y POST y el favicon no modifica la sesión (`php tests/session_csrf_regression.php`).
  La sintaxis de JavaScript también fue validada con `node --check`.
- Corrección puntual del login: nombre de producto en panel petróleo con blanco; contraste calculado 9.42:1.
- El servidor local sí respondió para pruebas HTTP, pero el host de automatización de Chrome no
  estuvo disponible para capturas o inspección visual. Las pruebas de escritorio/tablet/móvil y
  los flujos autenticados que leen o escriben la base operativa siguen pendientes de un entorno
  de pruebas autorizado. Por ello 1.2, 4.1, 4.2, 5.1 y 5.2 siguen abiertas.
