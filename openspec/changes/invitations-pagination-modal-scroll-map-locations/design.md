# Diseño Técnico: Cierre PR #4 — Invitaciones, Scroll Global y Mapa Leaflet

## 1. Contratos SQL (Migración 11 — aditiva, idempotente, solo Test)
- **Archivo:** `database/migrations/11_invitations_search_correct_email_contracts.sql` (espejo `sql/master-data/10_invitations_search_correct_email_contracts.sql`) + rollback en `database/migrations/rollbacks/rollback_11_invitations_search_correct_email.sql`.
- **`dbo.sp_listar_invitaciones_paginadas`:**
  - Parámetros: `@buscar NVARCHAR(120) = NULL`, `@estado VARCHAR(20) = NULL`, `@pagina INT = 1`, `@por_pagina INT = 20`, `@total_registros INT OUTPUT`.
  - Requiere `USUARIOS_VER` (`50410`). Usa estado efectivo UTC (`CASE WHEN estado = 'PENDIENTE' AND fecha_expiracion <= SYSUTCDATETIME() THEN 'EXPIRADA' ELSE estado END`) para filtrar sin requerir escrituras implícitas, escape seguro para `LIKE`, `COUNT(*)` para `@total_registros` y `ORDER BY fecha_creacion DESC, invitacion_id DESC OFFSET @offset ROWS FETCH NEXT @por_pagina ROWS ONLY`.
  - `dbo.sp_listar_invitaciones` se conserva intacto para consumidores heredados.
- **`dbo.sp_reenviar_invitacion_usuario`:**
  - Extiende firma de forma retrocompatible con `@nuevo_correo NVARCHAR(150) = NULL`.
  - Usa `sp_getapplock` exclusivo por transacción sobre la invitación origen y sobre el correo objetivo (y origen) para eliminar condiciones de carrera entre dos reenvíos concurrentes.
  - Valida formato (`50413`), existencia (`50437`), no completada (`50441`), no cuenta activa (`50430`), ausencia de invitación vigente duplicada en nuevo correo (`50431`), cooldown 60 s (`50440`) y límite 5 envíos/24 h (`50439`) tanto sobre correo origen como destino (sin bypass por cambio de correo).
  - Deja intacto `correo_destino` del registro original; si estaba vigente `PENDIENTE` la marca `REVOCADA` (si estaba `PENDIENTE` ya vencida la normaliza a `EXPIRADA`).
  - Copia únicamente permisos activos de `dbo.invitaciones_permisos` que el actor realmente posee (si tiene `USUARIOS_GESTIONAR_PERMISOS` y `dbo.fn_tiene_permiso(@actor_id, p.codigo) = 1`; nunca escala privilegios).
  - Audita `'RESEND'` o `'RESEND_CORRECT_EMAIL'`.
- **`dbo.sp_obtener_solicitud` y `dbo.sp_obtener_viaje`:**
  - Añaden al final de sus `SELECT` existentes `origen_direccion`, `origen_latitud`, `origen_longitud`, `destino_direccion`, `destino_latitud`, `destino_longitud` (solicitud) y `u.latitud`, `u.longitud` (paradas de viaje).

## 2. Frontend de Invitaciones
- `public/usuarios.php`: reemplaza el párrafo plano en `#panel-invitaciones` por `.toolbar-panel` con `<form class="filter-form" id="invitaciones-filtros">` (input búsqueda con icono `search` + select `#invitaciones-estado` con `data-combobox`) + botón `Invitar usuario`.
- `public/usuarios/invitaciones_lista.php`: llama a `dbo.sp_listar_invitaciones_paginadas` (20/página), muestra 1 sola insignia principal `status_tag($estadoEfectivo)`, texto compacto de entrega + envíos, permisos planificados, vigencia y botones mínimos (`PENDIENTE` vigente → `Reenviar` + `Revocar`; `EXPIRADA`/`REVOCADA`/`CANCELADA` → `Reenviar`; `COMPLETADA` → `—`), además de `.pagination-bar` con preservación de filtros y página actual (`#invitaciones-pagina-actual`).
- `public/usuarios/reenviar_form.php`: nuevo modal HTMX ligero (`modal_header` / `modal_footer`) que recibe `id`, valida permiso `USUARIOS_GESTIONAR_CUENTAS` y carga el correo actual editable con aviso de invalidación del enlace previo.
- `public/usuarios/reenviar_invitacion.php`: soporta `nuevo_correo` opcional (retrocompatible con POST directos), ejecuta `sp_reenviar_invitacion_usuario`, despacha correo fuera de la transacción SQL, maneja fallo Brevo dejando el modal abierto con reintento seguro sin duplicar y en éxito cierra modal con `operation_modal_success` refrescando la lista con filtros/página actuales.

## 3. Scroll Modal Global y Tom Select
- `public/assets/css/estilos.css` + `public/assets/js/app.js`:
  - Lock global mediante clase `html.is-modal-open` + `body.is-modal-open` con `overflow: hidden`, compensación `--modal-scrollbar-comp` en `body` para evitar layout shift, `overscroll-behavior: contain` en `.modal`, `.modal-card-body`, `.ts-dropdown-content`, `.flatpickr-calendar` y `.location-map-canvas`.
  - Contador/estado único idempotente `lockPageScroll()` / `unlockPageScroll()` que guarda y preserva `window.scrollY` sin resetearlo ni acumularse en 10+ ciclos o reemplazo modal-a-modal.
  - En `initComboboxes`: `syncDropdownPosition` calcula espacio disponible arriba/abajo del control, aplica flip superior automático cuando el espacio inferior es insuficiente, establece `maxHeight` dinámica en `.ts-dropdown-content` dentro de los márgenes del viewport y evita que la rueda del ratón/trackpad o touch en el menú escape hacia la página de fondo.

## 4. Mapa Leaflet vendoreado y enlaces Google Maps
- **Vendor local:** `public/assets/vendor/leaflet/` (Leaflet 1.9.4, licencia BSD-2-Clause, JS + CSS + ícono SVG/local sin depender de CDN).
- **CSP en `app/bootstrap.php`:** mantiene `script-src 'self'`, `style-src 'self' https://cdn.jsdelivr.net`, y amplía `img-src` estrictamente a `'self' data: https://tile.openstreetmap.org https://a.tile.openstreetmap.org https://b.tile.openstreetmap.org https://c.tile.openstreetmap.org`.
- **Helper servidor en `app/components.php`:** `google_maps_url(mixed $lat, mixed $lon): ?string` y `google_maps_link(mixed $lat, mixed $lon, string $label = 'Abrir en Google Maps', bool $compact = false): string` con validación numérica estricta (`-90..90`, `-180..180`), `number_format(..., 6, '.', '')` y `rel="noopener noreferrer" target="_blank"`.
- **UI mapa en `public/flota/form.php` + `public/assets/js/app.js`:** inicializa Leaflet tras montar el modal (`initLocationMap`), ejecuta `invalidateSize()`, soporta click/drag/reencuadre/limpiar punto, sincroniza inputs `latitud`/`longitud` dentro de `<details id="ubi-coords-avanzadas">`, detecta fallo de carga/tiles abriendo automáticamente el panel manual y destruye la instancia al cerrar el modal.
