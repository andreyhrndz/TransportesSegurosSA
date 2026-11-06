# Delta: sistema de experiencia de usuario dark premium (`premium-ux-system`)

## Purpose

Establecer los requisitos observables de identidad visual dark premium, navegación ejecutiva, componentes estandarizados, combobox buscables con integración HTMX, localización costarricense de fechas/etiquetas y visualizaciones con datos reales en Transportes Seguros S.A.

## ADDED Requirements

### Requirement: Identidad visual Dark Logistics Premium y tokens centralizados
El frontend SHALL aplicar una identidad visual oscura de alto contraste basada en tokens CSS centralizados (`#0B111B` fondo principal, `#121C2B` sidebar, `#1A2636` superficies, `#648FFF` azul primario y `#36C5B2` turquesa) que cubran colores, tipografía, espaciados, radios, sombras, bordes, estados interactivos, transiciones, tamaños de control y capas `z-index`.

#### Scenario: Consistencia visual en páginas y modales
- **GIVEN** una persona autenticada que navega en laptop (`1366x768` o `1440x900`) entre el panel general, listados operativos, vistas de detalle y formularios modales
- **WHEN** se renderiza cualquier vista principal o fragmento HTMX
- **THEN** todas las superficies, tarjetas, tablas y controles aplican los tokens del tema oscuro logístico con contraste legible WCAG AA
- **AND** ningún control muestra la apariencia nativa sin personalizar del navegador.

### Requirement: Sidebar rediseñado con iconografía Lucide y colapso funcional
La navegación lateral SHALL presentar la identidad tipográfica «Transportes Seguros» sin recuadros decorativos de marca, iconos SVG de la familia Lucide con trazo y alineación uniformes, separación clara entre icono y etiqueta, indicador activo destacado, filtrado estricto por permisos individuales y capacidad real de contraerse en escritorio manteniendo tooltips accesibles.

#### Scenario: Navegación lateral en escritorio y modo contraído
- **GIVEN** una sesión autenticada en resolución de escritorio (`> 960px`)
- **WHEN** la persona visualiza la barra lateral y activa el control «Contraer barra lateral»
- **THEN** el botón hamburguesa móvil permanece oculto en el topbar
- **AND** la barra lateral alterna hacia un riel compacto de iconos con tooltips accesibles al pasar el cursor o enfocar con teclado, recordando la preferencia del usuario.

#### Scenario: Ocultamiento por permisos individuales
- **GIVEN** un usuario autenticado que carece del permiso de lectura de un módulo (por ejemplo, `USUARIOS_VER`)
- **WHEN** se construye el menú lateral
- **THEN** el enlace correspondiente no se renderiza en ninguna sección de la navegación.

### Requirement: Topbar contextual y menú de cuenta con iniciales
El encabezado superior SHALL mostrar el contexto o breadcrumb de la sección activa e integrar un menú desplegable de cuenta con avatar de iniciales generadas del usuario, nombre legible, icono chevron y acción de cierre de sesión protegida con CSRF, sin incluir enlaces ficticios a módulos inexistentes.

#### Scenario: Apertura del menú de cuenta y cierre de sesión
- **GIVEN** una persona autenticada en cualquier pantalla del sistema
- **WHEN** hace clic o presiona Enter sobre el botón de cuenta en el topbar
- **THEN** se despliega un menú personalizado con la identidad activa y la opción «Cerrar sesión»
- **AND** el menú se cierra al presionar `Escape` o hacer clic fuera de su contenedor.

### Requirement: Combobox buscables con soporte remoto paginado e integración HTMX
Los selectores de catálogos amplios o críticos (clientes, vehículos, conductores, rutas, ubicaciones de origen/destino, solicitudes asociables y usuarios) SHALL operar como combobox buscables que permitan desplazamiento inicial, filtrado por escritura, navegación por teclado (`Enter`, `Escape`, flechas), limpieza opcional, preservación del valor enviado al backend y consulta remota paginada sobre los contratos autorizados sin ser recortados por modales.

#### Scenario: Búsqueda y selección de cliente o recurso en modal HTMX
- **GIVEN** el modal de creación de solicitud o viaje cargado dinámicamente por HTMX
- **WHEN** la persona abre el combobox de cliente o ubicación, escribe un término de búsqueda y selecciona una opción con `Enter` o clic
- **THEN** el desplegable se posiciona por encima del modal sin recortarse, filtra resultados autorizados y asigna el identificador técnico requerido por el formulario
- **AND** al cerrar y reabrir el modal múltiples veces no se duplican instancias ni se producen errores en la consola JavaScript.

### Requirement: Fechas en zona horaria de Costa Rica, codificación UTF-8 extremo a extremo y lenguaje natural
Toda la interfaz SHALL operar en UTF-8 de extremo a extremo (SQL Server `PDO_SQLSRV`, PHP, HTML, fragmentos HTMX y JSON) sin pérdida de caracteres ni doble codificación, presentar textos, permisos y estados en español comprensible sin guiones bajos ni referencias internas a procedimientos SQL o `UTC`, convertir las marcas temporales almacenadas en UTC (`SYSUTCDATETIME()`) a la zona horaria `America/Costa_Rica` con formato legible (`dd/mm/aaaa, hh:mm a. m./p. m.`) y utilizar calendarios Flatpickr en español con formato visible `dd/mm/aaaa` e inicio de semana en lunes.

#### Scenario: Visualización de marcas temporales, permisos en español con tildes y estados operativos
- **GIVEN** registros de solicitudes, viajes, entregas, usuarios, permisos o historial de actividad cuyas fechas provienen de SQL Server en UTC, cuyos textos contienen caracteres en español (`á é í ó ú Á É Í Ó Ú ñ Ñ ü ¿ ¡`) y cuyos estados son códigos como `PENDIENTE_ASIGNACION` o `ENTREGA_FALLIDA`
- **WHEN** se presentan en páginas completas, fragmentos HTMX o respuestas JSON
- **THEN** las fechas se muestran convertidas a `America/Costa_Rica` (por ejemplo, `27/10/2026, 10:36 p. m.`) sin el sufijo `UTC`
- **AND** las tildes, eñes y signos de apertura se muestran íntegros sin secuencias corruptas (`Ã`, `Â`, `â`, ``), usando denominaciones claras como «Flota y catálogos», «Consultar historial de actividad: Ver historial de actividad», «Pendiente de asignación» y «Entrega fallida».

#### Scenario: Selección de fechas en filtros y formularios
- **GIVEN** un filtro de rango de fechas o un campo de fecha requerida/programada
- **WHEN** la persona abre el calendario Flatpickr y elige una fecha
- **THEN** el calendario se presenta en español con lunes como primer día y muestra al usuario el formato `dd/mm/aaaa`
- **AND** el formulario conserva y envía al servidor el valor en el formato técnico ISO esperado por el backend.

### Requirement: Dashboard logístico moderno con gráficos respaldados por datos reales
El Panel general SHALL funcionar como un centro de control logístico con tarjetas KPI jerarquizadas, indicadores operativos, accesos directos según permisos y gráficos sobrios en Chart.js alimentados exclusivamente por conteos reales de los contratos SQL de lectura autorizados.

#### Scenario: Renderizado del panel general con datos reales
- **GIVEN** una persona con permiso `REPORTES_VER` y permisos de lectura operativa
- **WHEN** accede al Panel general (`/`)
- **THEN** visualiza indicadores KPI con iconografía Lucide, gráficos de distribución de solicitudes por estado y estado de entregas/flota construidos con datos reales de los procedimientos almacenados
- **AND** no se muestran porcentajes, tendencias ni comparativas ficticias.
