# Diseño técnico: rediseño integral UX/UI (`frontend-premium-ux-redesign`)

## Context

Transportes Seguros S.A. opera sobre PHP 8.4, Microsoft SQL Server 2025 (`PDO_SQLSRV`), Bulma y HTMX, con una política `Content-Security-Policy` estricta que restringe `script-src 'self'`. Todas las marcas temporales de base de datos se generan mediante `SYSUTCDATETIME()` en UTC. Existen 14 suites automatizadas que validan seguridad ASVS, sesiones, CSRF, permisos individuales, contratos SQL y flujos HTTP/E2E.

## Goals / Non-Goals

- **Goals:**
  - Implementar un sistema de diseño **Dark Logistics Premium** centralizado en `public/assets/css/estilos.css` y `app/components.php` que reemplace la apariencia genérica de Bulma.
  - Rediseñar `app/layout.php` con un sidebar colapsable dotado de iconografía Lucide y tooltips accesibles, y un topbar con breadcrumb contextual y menú desplegable de cuenta con avatar de iniciales.
  - Integrar versiones locales fijas de **Tom Select**, **Flatpickr** (con localización `es`) y **Chart.js** en `public/assets/vendor/`, garantizando cero dependencias de scripts externos y cumplimiento estricto de `script-src 'self'`.
  - Habilitar búsqueda remota paginada y autorizada en combobox reutilizando los controladores de lectura existentes (`?formato=json`) y los procedimientos almacenados vigentes.
  - Convertir marcas temporales UTC de SQL Server a `America/Costa_Rica` para visualización humana en formato costarricense (`dd/mm/aaaa, hh:mm a. m./p. m.`) y estandarizar etiquetas amigables sin códigos internos.
  - Modernizar todas las páginas, vistas de detalle, fragmentos HTMX y modales conservando intactos los contratos de formulario y las 14 suites de prueba.

- **Non-Goals:**
  - No modificar esquemas, tablas ni procedimientos almacenados en `TransportesSegurosSA` ni `TransportesSegurosSA_Test`.
  - No introducir frameworks SPA ni compiladores obligatorios en tiempo de ejecución.
  - No agregar elementos decorativos sin respaldo funcional (campanas de notificación, perfiles editables o gráficas inventadas).

## Decisions

1. **Sistema de Tokens y Arquitectura CSS (`public/assets/css/estilos.css`):**
   - Se definen variables CSS en `:root` organizadas en: paleta base (`--color-canvas: #0B111B`, `--color-sidebar: #121C2B`, `--color-surface: #1A2636`, `--color-surface-elevated: #213044`, `--color-primary: #648FFF`, `--color-accent: #36C5B2`), semántica (`success`, `warning`, `danger`, `info`), escala tipográfica, espaciados, radios, bordes translúcidos, sombras de profundidad, transiciones y escala `z-index`.
   - Se sobrescriben las reglas de Bulma para tarjetas, tablas, formularios, botones, pestañas y modales, además de temas oscuros específicos para `.ts-wrapper` / `.ts-dropdown` (Tom Select) y `.flatpickr-calendar` (Flatpickr).
   - Se desactiva la inyección de estilos inline de HTMX mediante `<meta name="htmx-config" content='{"includeIndicatorStyles":false}'>` y se declaran las clases `.htmx-indicator` y `.htmx-request` en el archivo CSS principal para evitar avisos de CSP en consola.

2. **Iconografía Lucide y Componentes PHP (`app/components.php`):**
   - `svg_icon(string $name, string $label = '', string $class = 'icon')` se amplía con el repertorio oficial de trazos vectoriales **Lucide** (`24x24`, `stroke-width="1.85"`, `stroke-linecap="round"`, `stroke-linejoin="round"`).
   - Se agregan funciones de presentación reutilizables:
     - `humanize_status(mixed $value): string` y `humanize_label(mixed $value): string` para traducir códigos (`PENDIENTE_ASIGNACION` → `Pendiente de asignación`, `ENTREGA_FALLIDA` → `Entrega fallida`, `EN_TRANSITO` → `En tránsito`, etc.).
     - `user_initials(string $username): string` para generar las iniciales del avatar en el topbar.
     - `format_utc_datetime(mixed $value): string` que interpreta marcas temporales UTC de SQL Server y las convierte a `America/Costa_Rica` con formato `d/m/Y, g:i a. m./p. m.`.
     - `format_cr_date(mixed $value): string` para fechas de calendario (`YYYY-MM-DD` → `dd/mm/aaaa`).

3. **Combobox Buscables e Integración Remota (`public/assets/js/app.js` + `lista.php`):**
   - Los selectores con atributo `data-combobox` se inicializan con **Tom Select**.
   - Cuando el selector define `data-remote-url` (clientes, ubicaciones, vehículos, conductores, rutas, solicitudes, usuarios), conserva las opciones iniciales renderizadas en servidor (garantizando apertura inmediata y compatibilidad con pruebas HTTP) y ejecuta consultas remotas con debounce hacia el controlador autorizado correspondiente con `formato=json&buscar=...&pagina=1`.
   - Para evitar que los menús desplegables se recorten dentro de `.modal-card` o contenedores con `overflow`, Tom Select utiliza `dropdownParent: 'body'` y sincroniza su posición y ancho con el control de origen.
   - Antes de cada swap o limpieza de DOM (`htmx:beforeCleanupElement` y `cerrarModal`), se invoca `.destroy()` sobre las instancias activas de Tom Select, Flatpickr y Chart.js.

4. **Calendarios Flatpickr y Semántica Temporal (`public/assets/js/app.js` + `app/operational_writes.php`):**
   - Los campos `type="date"` y `[data-datepicker]` se inicializan con **Flatpickr** en español (`firstDayOfWeek: 1`), `altInput: true`, `altFormat: 'd/m/Y'` (visible para el usuario) y `dateFormat: 'Y-m-d'` (valor técnico enviado al backend), incorporando barra inferior con acciones «Hoy» y «Limpiar».
   - Los campos `type="datetime-local"` y `[data-datetimepicker]` se inicializan con Flatpickr con selección de hora (`altFormat: 'd/m/Y, h:i K'`, `dateFormat: 'Y-m-d\TH:i'`).
   - En filtros de rango (`desde` / `hasta`), el cambio en `desde` restringe `minDate` de `hasta` y viceversa.

5. **Dashboard y Reportes con Chart.js (`public/index.php`, `public/reportes.php`):**
   - El servidor consulta conteos reales por estado usando exclusivamente los procedimientos almacenados autorizados (`dbo.sp_listar_solicitudes`, `dbo.sp_listar_viajes`, `dbo.sp_listar_entregas`, `dbo.sp_listar_vehiculos`, `dbo.sp_listar_incidencias`) y serializa los datos en atributos `data-chart-*` sobre elementos `<canvas>`.
   - `public/assets/js/app.js` instancia gráficos **Chart.js** con paleta coherente (`#648FFF`, `#36C5B2`, `#F59E0B`, `#F87171`, `#A78BFA`, `#38BDF8`), leyenda legible y soporte de `prefers-reduced-motion`.

## Risks / Trade-offs

- **[Política CSP estricta]** → Alojar `tom-select`, `flatpickr` y `chart.js` localmente en `public/assets/vendor/` y evitar scripts inline.
- **[Compatibilidad con las 14 suites de prueba]** → Mantener intactos los `name` de todos los inputs, los `value` de `<option>`, los textos de confirmación de escritura y las rutas HTTP; verificar cada fase ejecutando la suite completa contra `TransportesSegurosSA_Test`.

## Migration Plan

Ejecución estructurada en 5 fases con commits locales atómicos:
- **Fase A:** Tokens CSS, tipografía, iconografía Lucide, sidebar colapsable, topbar con menú de cuenta y layout compartido + Login.
- **Fase B:** Activos locales versionados (Tom Select, Flatpickr, Chart.js), biblioteca de componentes, calendarios en español, combobox con búsqueda remota autorizada, modales y ciclo de vida HTMX.
- **Fase C:** Rediseño de Panel general (Dashboard) y Reportes con gráficos Chart.js respaldados por datos reales.
- **Fase D:** Migración visual completa de Clientes, Solicitudes, Viajes, Entregas/Incidencias/Documentos, Usuarios/Permisos y Flota/Maestros.
- **Fase E:** Verificación visual en laptop (`1366x768` y `1440x900`) con Playwright, validación de consola sin errores JS, regresión de las 14 suites automatizadas y validación estricta de OpenSpec.
