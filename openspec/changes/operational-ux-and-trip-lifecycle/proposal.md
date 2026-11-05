# Propuesta: mejoras operativas, ciclo de vida de viajes y consistencia UX

## Why

Durante la operación continua y revisiones de usabilidad en resoluciones de trabajo estándar (laptop 1366×768 y 1440×900) se detectaron fricciones críticas que impactan la fluidez y la seguridad operativa:
1. El modal de «Nueva solicitud» oculta el pie de acciones (botones «Crear solicitud» y «Cancelar») cuando se agregan líneas de mercancía o en pantallas con altura reducida, impidiendo guardar la orden sin trucos de zoom.
2. Los códigos de solicitud y viaje exigen ingreso manual por parte del operador, lo cual es propenso a errores tipográficos, colisiones y sobrecarga cognitiva; deben ser autogenerados de manera atómica y secuencial en la base de datos.
3. Los filtros de estado en Solicitudes, Viajes, Entregas, Clientes, Usuarios, Flota y Reportes continúan mostrando selectores nativos del navegador con menús blancos del sistema operativo al abrirse, rompiendo la experiencia Dark Logistics Premium.
4. No existe capacidad operativa ni procedimiento para cancelar viajes planificados antes de su inicio, dejando vehículos y conductores bloqueados indefinidamente en estado `ASIGNADO` y solicitudes atrapadas sin poder reasignarse.
5. Al colapsar la barra lateral y navegar entre distintas secciones del sistema, la barra lateral se expande y contrae bruscamente (flicker FOUC) en cada carga de página.

## What Changes

- **Corrección de layout modal:** ajustar el contenedor `.modal-card` y `<form>` en CSS para que el formulario actúe como flexbox vertical con `min-height: 0`, permitiendo scroll interno en `.modal-card-body` y manteniendo el pie `.modal-card-foot` permanentemente visible con el botón principal «Crear solicitud» a la izquierda del botón secundario «Cancelar».
- **Autogeneración atómica de códigos:** crear secuencias dedicadas (`dbo.seq_codigo_solicitud` y `dbo.seq_codigo_viaje`) en `TransportesSegurosSA_Test` y actualizar `sp_crear_solicitud` y `sp_crear_viaje` para generar automáticamente códigos secuenciales formateados (`SOL-00001`, `VIA-00001`) si el parámetro recibido es nulo o vacío, mostrando en la interfaz un indicador informativo «Código asignado al guardar» en lugar de un campo de texto obligatorio.
- **Desplegables personalizados Dark Logistics:** aplicar Tom Select a todos los filtros de estado cerrados y abiertos en los siete módulos principales, asegurando tema oscuro cohesivo, sin duplicación de chevrones, con selección fluida mediante clic o teclado y compatibilidad total con eventos HTMX.
- **Contrato y UX de cancelación de viajes:** incorporar el permiso `VIAJES_CANCELAR`, crear el procedimiento almacenado `dbo.sp_cancelar_viaje` para viajes en estado `PLANIFICADO` (liberando vehículo y conductor a `DISPONIBLE`, devolviendo solicitudes a `PENDIENTE_ASIGNACION` y paradas a `OMITIDA`) e integrar botón y modal de confirmación en el detalle de viaje.
- **Persistencia inmediata de barra lateral colapsada:** ejecutar un script bloqueante en la cabecera HTML que lea `localStorage` y aplique inmediatamente las clases de colapso y neutralice transiciones CSS iniciales, eliminando el parpadeo de apertura/cierre al navegar.

## Alcance, exclusiones y riesgos

- **Alcance:** Corrección de estilos de modales y sidebar, personalización de filtros con Tom Select, secuencias y generación de códigos en SQL Test, procedimiento y permiso de cancelación de viajes en SQL Test, y mejoras de usabilidad en asignación de solicitudes.
- **No-alcance:** No modifica las bases protegidas `TransportesSegurosSA` ni `TransportesSegurosSA_DW`; no permite cancelar viajes en curso o finalizados; no renombra ni renumera códigos históricos; no altera firmas contractuales públicas de forma incompatible.
- **Riesgos y mitigación:**
  - *Concurrencia en códigos secuenciales:* uso de `SEQUENCE` nativo de SQL Server con incremento atómico para evitar colisiones y bloqueos de tabla.
  - *Bloqueo de recursos en cancelación:* comprobación transaccional con `UPDLOCK, HOLDLOCK` y reversión de estados asociados en una sola unidad de trabajo atómica.
  - *Doble flecha en selectores:* reglas CSS específicas para ocultar el pseudoelemento de Bulma cuando Tom Select está activo.
- **Estrategia de reversión:** Scripts SQL reversibles `02_rollback_operational_ux_and_trip_lifecycle.sql` en `sql/` y commits locales atómicos en Git.

## Capabilities

### New Capabilities
- `operational-ux-and-trip-lifecycle`: Autogeneración de códigos de transporte, cancelación segura de viajes planificados, selectores de filtro dark premium y navegación sin parpadeos.

## Impact

Impacta `public/assets/css/estilos.css`, `public/assets/js/app.js`, `app/layout.php`, `public/solicitudes/form.php`, `public/solicitudes/guardar.php`, `public/viajes/form.php`, `public/viajes/guardar.php`, `public/viajes/detalle.php`, vistas de filtro en `public/*.php`, y procedimientos en `TransportesSegurosSA_Test`.
