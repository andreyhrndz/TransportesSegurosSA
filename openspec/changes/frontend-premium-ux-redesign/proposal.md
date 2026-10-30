# Propuesta: rediseño integral UX/UI dark premium para Transportes Seguros S.A.

## Why

El frontend actual de Transportes Seguros S.A. cumple funcionalmente con los contratos operativos y de seguridad, pero su experiencia visual resulta rígida, genérica y desactualizada: utiliza controles nativos del navegador sin personalización, selectores simples que no escalan ante catálogos amplios, fechas mostradas en UTC con códigos técnicos en mayúsculas y guiones bajos (`PENDIENTE_ASIGNACION`, `ENTREGA_FALLIDA`), una barra lateral con poca separación y un encabezado superior subutilizado. Se requiere una transformación integral hacia una plataforma SaaS logística dark premium cohesiva, moderna, ergonómica y orientada a usuarios de Costa Rica.

## What Changes

- Definir e implementar una nueva identidad visual **Dark Logistics Premium** basada en tokens centralizados de color (`#0B111B` fondo principal, `#121C2B` sidebar, `#1A2636` superficies, `#648FFF` azul principal, `#36C5B2` turquesa), tipografía, espaciado, radios, sombras, bordes, estados interactivos, transiciones, tamaños y capas `z-index`.
- Rediseñar por completo el **Sidebar** lateral con identidad tipográfica limpia (sin cuadro de logo decorativo), iconografía unificada **Lucide**, espaciado generoso entre icono y etiqueta, indicador activo elegante, ocultamiento de enlaces sin permiso individual, ausencia de botón hamburguesa redundante en escritorio y colapso funcional con navegación por iconos y tooltips accesibles.
- Rediseñar por completo el **Topbar** superior con breadcrumb/identificación contextual de la sección actual y menú de cuenta profesional con avatar de iniciales, nombre legible, chevron interactivo, dropdown personalizado accesible y cierre de sesión protegido con CSRF.
- Incorporar recursos locales versionados bajo la política CSP vigente (`script-src 'self'`): iconografía **Lucide**, **Tom Select** para combobox buscables y selectores personalizados, **Flatpickr** (con localización en español, inicio en lunes y formato visible `dd/mm/aaaa`) para calendarios y **Chart.js** para visualizaciones ejecutivas basadas en datos reales.
- Estandarizar un **sistema transversal de componentes** reutilizables (inputs, buscadores con icono, textareas, selectores y combobox buscables, calendarios, checkboxes, radios, switches, botones, tablas, paginación, pestañas, badges semánticos amigables, modales, dropdowns, alertas, toasts, tooltips, confirmaciones, skeletons y estados vacíos).
- Implementar **combobox buscables con filtrado remoto paginado y autorizado** sobre los contratos SQL existentes para clientes, vehículos, conductores, rutas, ubicaciones, solicitudes y usuarios, integrados con el ciclo de vida de HTMX (`load`, `afterSwap`, `beforeCleanupElement`) y posicionados sin recorte dentro de modales.
- Adaptar **fechas, idioma, codificación UTF-8 y etiquetas a Costa Rica**: asegurar UTF-8 de extremo a extremo (`default_charset = UTF-8`, cabeceras `Content-Type: ...; charset=utf-8`, `PDO::SQLSRV_ATTR_ENCODING => PDO::SQLSRV_ENCODING_UTF8` y literales Unicode `N'...'` en contratos SQL), convertir marcas temporales UTC (`SYSUTCDATETIME()`) a la zona horaria `America/Costa_Rica` con formato legible (`dd/mm/aaaa, hh:mm a. m./p. m.`), preservar formatos técnicos requeridos por el backend y sustituir códigos técnicos con guiones bajos o referencias a procedimientos SQL por lenguaje natural en español («Flota y catálogos», «Consultar historial de actividad», «Ver historial de actividad»).
- Transformar el **Panel general (Dashboard)** y **Reportes** en un centro de control logístico con KPIs jerarquizados, accesos directos por permiso, distribución visual de solicitudes por estado y resultados de entregas/flota mediante **Chart.js** alimentado exclusivamente por contratos de lectura autorizados.
- Migrar visualmente todas las páginas principales, vistas de detalle, fragmentos HTMX y modales: Login, Dashboard, Clientes, Solicitudes, Viajes, Entregas, Incidencias y Documentos, Usuarios y Permisos, Flota y Catálogos, y Reportes e Historial de actividad.

## Alcance, exclusiones y riesgos

- **Alcance:** Rediseño visual completo, sistema de diseño por tokens, navegación lateral colapsable y topbar con menú de cuenta, biblioteca de componentes, combobox con búsqueda local/remota autorizada, calendarios Flatpickr en español, conversión horaria a `America/Costa_Rica`, integridad UTF-8 extremo a extremo, gráficos reales en dashboard/reportes y migración transversal de todas las vistas y fragmentos HTMX.
- **No-alcance (Exclusiones):** No modifica `TransportesSegurosSA` ni `TransportesSegurosSA_DW`, no altera firmas ni códigos internos de procedimientos almacenados o permisos SQL, SQL Server Agent, Jobs, backups ni replicación, no introduce frameworks SPA (React/Vue/Angular) y no inventa módulos ficticios (notificaciones interactivas, perfiles editables o configuraciones inexistentes).
- **Dependencias:** Contratos SQL de lectura y escritura vigentes, extensiones `PDO_SQLSRV`, `HTMX` local y activos locales versionados en `public/assets/vendor/`.
- **Riesgos y mitigación:**
  - *Fuga de memoria o duplicación de instancias JS tras swaps HTMX:* destruir instancias previas de Tom Select, Flatpickr y Chart.js en `htmx:beforeCleanupElement` / antes de re-inicializar.
  - *Recorte de dropdowns o calendarios en modales:* adjuntar contenedores flotantes al `body` o capa superior sincronizada con `z-index` mayor al modal.
  - *Regresión funcional en suites automatizadas:* conservar nombres de campos, códigos de estado enviados al servidor, tokens CSRF, cabeceras de seguridad y ejecutar las 14 suites existentes más la suite automatizada UTF-8 (`tests/utf8_encoding_and_language_test.php`) y verificación visual con Playwright.
- **Estrategia de reversión:** Todos los cambios se encapsulan en commits locales por fase sobre la rama de trabajo en `app/` y `public/`, permitiendo revertir por fase mediante `git revert` sin impacto alguno en la base de datos.

## Capabilities

### New Capabilities
- `premium-ux-system`: Identidad dark premium, navegación avanzada (sidebar colapsable y topbar contextual), sistema transversal de componentes, combobox buscables con búsqueda remota autorizada, localización temporal para Costa Rica (`America/Costa_Rica`) y visualizaciones ejecutivas con datos reales.

### Modified Capabilities
- `ui-foundations`: Evoluciona los tokens iniciales claros hacia el sistema de diseño Dark Logistics Premium y amplía el catálogo de componentes interactivos.

## Impact

Impacta `app/components.php`, `app/layout.php`, `app/operational_writes.php`, `public/assets/css/estilos.css`, `public/assets/js/app.js`, librerías locales en `public/assets/vendor/` y la totalidad de páginas y fragmentos en `public/`. Mantiene intacta la capa SQL Server y los controles de seguridad ASVS.
