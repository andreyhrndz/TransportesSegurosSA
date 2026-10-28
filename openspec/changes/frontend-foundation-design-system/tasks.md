# Tareas

## 1. Auditoría y baseline

- [ ] 1.1 Inspeccionar frontend local real y documentar inventario de componentes/eventos HTMX y diferencias respecto a esta propuesta.
- [ ] 1.2 Registrar evidencia de comportamiento actual: login, panel, listado y alta de clientes.
- [ ] 1.3 Revisar paleta elegida para cumplir contraste WCAG AA y dejar tokens definitivos en `docs/guia-visual.md`.

## 2. Sistema de diseño

- [ ] 2.1 Refactorizar CSS a tokens semánticos, estilos mantenibles y variantes consistentes (sin introducir framework adicional).
- [ ] 2.2 Crear reutilización de componentes PHP donde realmente exista duplicación: botón, campos, alertas, tablas, modal y KPI.
- [ ] 2.3 Unificar estados de foco, deshabilitado, error, validación, éxito, vacío y carga.

## 3. Navegación y experiencia

- [ ] 3.1 Estandarizar sidebar, header, textos visibles y navegación desktop/tablet/móvil.
- [ ] 3.2 Reemplazar iconos Unicode improvisados por iconos SVG coherentes con etiquetas accesibles.
- [ ] 3.3 Acortar textos de acciones manteniendo significado y accesibilidad.
- [ ] 3.4 Ajustar presentación de KPI, tablas y fechas visibles sin cambiar consulta ni datos.

## 4. Integridad funcional

- [ ] 4.1 Confirmar que login/logout y protección de sesión funcionan sin regresiones.
- [ ] 4.2 Confirmar búsqueda, paginación, modales, HTMX, CSRF y registro de ambos tipos de clientes.
- [ ] 4.3 Comprobar que no se exponen funcionalidades inexistentes ni mensajes académicos.

## 5. Calidad y cierre

- [ ] 5.1 Probar escritorio, tablet y móvil; registrar tamaños evaluados y defectos corregidos.
- [ ] 5.2 Verificar teclado/foco, contraste, mensajes y accesibilidad de controles.
- [ ] 5.3 Ejecutar `openspec validate frontend-foundation-design-system --strict`, documentar pruebas/limitaciones y dejar el change listo para revisión (sin archivarlo).
