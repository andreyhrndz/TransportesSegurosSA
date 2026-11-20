# Propuesta: Cierre PR #4 — Invitaciones paginadas y corrección de correo, scroll modal global y ubicaciones con mapa Leaflet

## Por qué

Durante la revisión final de cierre del PR #4 se detectaron tres brechas operativas y de UX críticas para la demostración y QA:

1. **Invitaciones (`public/usuarios.php`, `public/usuarios/invitaciones_lista.php`, `public/usuarios/reenviar_invitacion.php`):**
   - El listado carga todas las invitaciones sin buscador, filtro por estado ni paginación SQL real (`fetchAll()` completo).
   - Muestra dos insignias apiladas (estado de invitación + estado de entrega) y el botón «Cancelar» sobre invitaciones expiradas (innecesario bajo YAGNI).
   - «Reenviar» no permite confirmar ni corregir el correo destinatario desde un modal, obligando a emitir una invitación desde cero.
2. **Scroll global en modales (`public/assets/js/app.js`, `public/assets/css/estilos.css`):**
   - Al abrir cualquier modal HTMX la página de fondo sigue desplazándose con la rueda/touchpad (`scrollY` salta +800 px tanto en «Nueva solicitud» → «Tipo de mercancía» como en los modales de «Flota y catálogos»).
   - Los desplegables Tom Select montados en `body` cerca del borde inferior (como «Tipo de mercancía») se recortan fuera del viewport (`bottom > innerHeight`) sin flip superior ni `max-height` dinámica, provocando que el scroll escape al documento de fondo.
3. **Ubicaciones con mapa interactivo y enlaces externos (`public/flota/form.php`, `public/flota/lista.php`, `public/solicitudes/detalle.php`, `public/viajes/detalle.php`):**
   - Registrar latitud y longitud manualmente en texto plano es propenso a error; además las vistas de ubicaciones, detalle de solicitud y paradas de viaje carecen de un enlace directo «Abrir en Google Maps» cuando existen coordenadas registradas.

## Qué cambia

1. **Migración SQL 11 (aditiva e idempotente, aplicada exclusivamente en `TransportesSegurosSA_Test`; pendiente en `TransportesSegurosSA` original):**
   - `dbo.sp_listar_invitaciones_paginadas`: filtrado por `@buscar` (parcial case-insensitive), `@estado` sobre estado efectivo UTC y paginación real `OFFSET/FETCH` (20 por página) con `@total_registros OUTPUT`, manteniendo `dbo.sp_listar_invitaciones` intacto para compatibilidad.
   - `dbo.sp_reenviar_invitacion_usuario`: parámetro opcional `@nuevo_correo NVARCHAR(150) = NULL` con serialización transaccional (`sp_getapplock`), verificación estricta de cuenta activa/invitación pendiente duplicada, preservación de rate limits (60 s y 5/24 h) sin bypass por cambio de correo, invalidación definitiva del token previo, conservación inmutable del registro histórico original, copia de permisos autorizados sin escalamiento y auditoría `RESEND_CORRECT_EMAIL`.
   - `dbo.sp_obtener_solicitud` y `dbo.sp_obtener_viaje`: exposición aditiva de coordenadas (`latitud`, `longitud` y direcciones) de las ubicaciones ya enlazadas, sin crear nuevas columnas ni tablas.
2. **UX y flujo de invitaciones:**
   - Barra de filtros en `#panel-invitaciones` idéntica en estructura y tokens a `#usuarios-filtros` (`.toolbar-panel`, `.filter-form`, `.input-with-icon`, `select[data-combobox]`, `.pagination-bar`).
   - Una sola insignia principal `status_tag($estadoEfectivo)`; estado de entrega como texto compacto; acciones mínimas por estado (`PENDIENTE` vigente: **Reenviar** y **Revocar**; `EXPIRADA`/`REVOCADA`/`CANCELADA`: **Reenviar** únicamente; `COMPLETADA`: sin acciones). Sin botón «Cancelar» ni botón separado «Editar».
   - Nuevo modal `public/usuarios/reenviar_form.php` abierto por todo botón «Reenviar» para confirmar o corregir el correo destinatario antes de enviar el nuevo enlace.
3. **Scroll modal global y posicionamiento inteligente de Tom Select:**
   - Bloqueo global del scroll del documento con compensación de scrollbar al abrir modales, restauración total al cerrar (incluyendo reemplazo modal-a-modal y 10+ ciclos) y contención `overscroll-behavior: contain` en `.modal-card-body`, `.ts-dropdown-content`, Flatpickr y Leaflet.
   - Clamping horizontal y vertical, `max-height` dinámica y flip superior automático en Tom Select (`dropdownParent: 'body'`) y captura controlada de eventos `wheel` dentro del desplegable.
4. **Mapa Leaflet vendoreado localmente + enlaces Google Maps:**
   - Leaflet 1.9.4 (BSD-2-Clause) vendoreado en `public/assets/vendor/leaflet/` con CSP estricta (`script-src 'self'`, `img-src` limitado únicamente a hosts oficiales de tiles de OpenStreetMap).
   - Selector interactivo en el modal de ubicación (click/drag, atribución OSM, reencuadre, fallback automático a entrada manual en caso de fallo/offline) con coordenadas manuales en `<details>` sincronizadas con el marcador y preservación de opcionalidad.
   - Botón/enlace «Abrir en Google Maps» (URL pública sin API key generada y validada en servidor) en ubicaciones, origen/destino de solicitud y paradas de viaje únicamente cuando existen coordenadas válidas.
5. **Pruebas, capturas sanitizadas y plan de QA:**
   - Suites T-SQL, PHP funcionales/seguridad/concurrencia y Playwright real en desktop (`1366×768`, `1440×900`, `1600×900`, `1920×1080`, `1280×720`), tablet (`768×1024`) y móvil (`375×667`).
   - Actualización de `docs/qa/plan-qa-exposicion.md` y documentación operativa sin marcar el QA manual como ejecutado.
