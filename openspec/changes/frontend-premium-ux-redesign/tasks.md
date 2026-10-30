# Tareas de implementación — `frontend-premium-ux-redesign`

## 1. Fase A: Identidad visual, tokens, iconografía, sidebar, topbar y layout

- [ ] 1.1 Definir el sistema centralizado de design tokens Dark Logistics Premium (`#0B111B`, `#121C2B`, `#1A2636`, `#648FFF`, `#36C5B2`, tipografía, espaciados, radios, sombras, bordes, estados, transiciones y `z-index`) en `public/assets/css/estilos.css`.
- [ ] 1.2 Actualizar `app/components.php` con el conjunto completo de iconos SVG Lucide, formateadores de fecha para `America/Costa_Rica` (`format_utc_datetime`, `format_cr_date`), generador de iniciales y traductores de etiquetas amigables (`humanize_status`, `humanize_label`).
- [ ] 1.3 Rediseñar `app/layout.php` con sidebar tipográfico sin cuadro decorativo, navegación por permisos individuales, colapso funcional con tooltips accesibles en escritorio, y topbar con breadcrumb contextual y menú desplegable de cuenta con iniciales y cierre de sesión CSRF.
- [ ] 1.4 Rediseñar la pantalla de acceso `public/auth/login.php` con la nueva identidad visual dark premium y controles de alto contraste.

## 2. Fase B: Biblioteca de componentes, calendarios Flatpickr y combobox Tom Select

- [ ] 2.1 Incorporar recursos locales versionados de Tom Select, Flatpickr (con localización `es`) y Chart.js en `public/assets/vendor/` respetando la política CSP (`script-src 'self'`).
- [ ] 2.2 Implementar en `public/assets/js/app.js` la inicialización y destrucción segura (`htmx:afterSwap`, `htmx:beforeCleanupElement`, modales y filas dinámicas) de combobox buscables Tom Select (`dropdownParent: 'body'`), calendarios Flatpickr (`dd/mm/aaaa`, inicio lunes, botones Hoy/Limpiar) y menú de cuenta.
- [ ] 2.3 Habilitar respuestas JSON paginadas y autorizadas (`?formato=json`) sobre los controladores de lectura existentes (`clientes/lista.php`, `flota/lista.php`, `solicitudes/lista.php`, `usuarios/lista.php`) para alimentar la búsqueda remota de combobox sin inventar endpoints SQL ni omitir permisos.

## 3. Fase C: Dashboard moderno y módulo de Reportes con datos reales

- [ ] 3.1 Rediseñar `public/index.php` como centro de control logístico con tarjetas KPI jerarquizadas, iconos Lucide, accesos rápidos según permisos, gráficos Chart.js con datos reales de contratos autorizados y tabla mejorada de solicitudes recientes.
- [ ] 3.2 Rediseñar `public/reportes.php` y `public/reportes/lista.php` con pestañas modernas, filtros con combobox/calendarios Flatpickr, resumen visual basado en datos reales, etiquetas amigables y fechas en zona horaria de Costa Rica.

## 4. Fase D: Migración visual transversal de módulos operativos y administrativos

- [ ] 4.1 Migrar `public/clientes.php` y `public/clientes/*` (listado, detalle, creación, edición y desactivación) al nuevo sistema de componentes dark premium.
- [ ] 4.2 Migrar `public/solicitudes.php` y `public/solicitudes/*` (listado, detalle, formulario con combobox buscables de cliente/origen/destino/mercancías, Flatpickr y acciones).
- [ ] 4.3 Migrar `public/viajes.php` y `public/viajes/*` (listado, detalle, formulario con combobox buscables de vehículo/conductor/ruta y modales de paradas/asignación de solicitudes).
- [ ] 4.4 Migrar `public/entregas.php` y `public/entregas/*` (entregas, incidencias, notificaciones, documentos y formularios modales con combobox buscables de solicitudes y catálogos).
- [ ] 4.5 Migrar `public/usuarios.php` y `public/usuarios/*` (directorio, ficha de usuario y permisos individuales, creación/edición, contraseña, estado y concesión/revocación).
- [ ] 4.6 Migrar `public/flota.php` y `public/flota/*` (vehículos, conductores, rutas, ubicaciones y catálogos con combobox buscables de ubicaciones y usuarios).

## 5. Fase E: Revisión visual en laptop, integración HTMX, accesibilidad y regresiones

- [ ] 5.1 Ejecutar las 14 suites automatizadas existentes contra `TransportesSegurosSA_Test` y verificar cero regresiones funcionales o de seguridad.
- [ ] 5.2 Ejecutar comprobación visual y funcional en navegador real con Playwright en resoluciones de laptop (`1366x768` y `1440x900`), validando sidebar colapsable, topbar, combobox en modales, calendarios Flatpickr, gráficos del dashboard, swaps HTMX y consola sin errores JavaScript, guardando capturas de evidencia.
- [ ] 5.3 Ejecutar `openspec validate frontend-premium-ux-redesign --strict` y actualizar este archivo de tareas con la evidencia obtenida.
