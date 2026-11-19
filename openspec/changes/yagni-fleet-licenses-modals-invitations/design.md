# Diseño técnico: Cierre YAGNI — Flota, licencias, selectores, modales e invitaciones

## 1. Contratos SQL (Migración 10 aditiva e idempotente)

Se crea `database/migrations/10_yagni_fleet_licenses_and_invitations_contracts.sql` (espejada en `sql/master-data/07_yagni_fleet_licenses_and_invitations_contracts.sql`) junto con su rollback (`database/migrations/rollbacks/rollback_10_yagni_fleet_licenses_and_invitations.sql`) y pruebas contractuales (`database/tests/14_contract_tests_yagni_fleet_and_invitations.sql`). Solo se aplica en `TransportesSegurosSA_Test`.

1. **`dbo.sp_crear_conductor`:**
   - Cambia el valor predeterminado `@codigo_licencia_inicial VARCHAR(10) = NULL` (elimina el default implícito `'B4'`).
   - Si se suministra `@codigo_licencia_inicial`, valida existencia activa en `dbo.cat_licencias`, registra la fila única en `dbo.conductores_licencias` con `estado_verificacion = 'VERIFICADA'`, `usuario_verificador_id = @actor_id`, `fecha_verificacion = SYSUTCDATETIME()` y observación de revisión interna del documento físico, y audita el evento.
   - Si se omite (`NULL`), no inserta filas en `dbo.conductores_licencias`.
2. **`dbo.sp_actualizar_licencia_principal_conductor`:**
   - Nuevo procedimiento atómico y savepoint-safe (`FLOTAS_GESTIONAR`, `UPDLOCK, HOLDLOCK`).
   - Bloquea modificaciones si el conductor está `ASIGNADO` o participa en viajes `PLANIFICADO`/`EN_CURSO` (`50505`).
   - Valida existencia (`50503`), estado activo (`50506`), unicidad de `numero_licencia` (`50504`), categoría activa en `dbo.cat_licencias` y fecha vigente (`50502`).
   - Captura el resumen completo de categorías previas en `dbo.conductores_licencias` para `dbo.auditorias` (`accion = 'ACTUALIZAR_PRINCIPAL'`), elimina cualquier categoría distinta a la elegida para impedir autorizaciones por categorías ocultas, hace upsert de la categoría elegida con revisión interna del actor autenticado y sincroniza `dbo.conductores.numero_licencia` y `dbo.conductores.fecha_vencimiento_licencia`.
3. **`dbo.fn_conductor_habilitado_vehiculo`:**
   - Exige `cat.activo = 1` en el `JOIN` con `dbo.cat_licencias`.
   - Añade diagnóstico explícito cuando el conductor posee categorías no válidas para carga (clases A/C/D) en unidades `<= 4.000 kg`, evitando mensajes de diagnóstico falsamente positivos.
4. **`dbo.sp_crear_vehiculo` y `dbo.sp_actualizar_vehiculo`:**
   - En `sp_crear_vehiculo`: default seguro `@kilometraje_actual = 0`, `@estado_homologacion = 'PENDIENTE_VERIFICACION'`; valida `capacidad_carga_kg <= pma_kg` y coherencia entre `pma_kg`, `clase_legal` y `es_articulado`.
   - En `sp_actualizar_vehiculo`: `@kilometraje_actual DECIMAL(12,1) = NULL` y `@estado_homologacion VARCHAR(25) = NULL`; cuando llegan `NULL` se preservan atómicamente los valores previos (`kilometraje_actual` nunca se reinicia a 0 y `NO_CONFORME` nunca se promociona silenciosamente). Valida coherencia PMA/carga útil/clase/articulado y mantiene validación de kilometraje no regresivo cuando se envía explícitamente.
5. **`dbo.sp_revocar_invitacion_usuario` y `dbo.sp_reenviar_invitacion_usuario`:**
   - En `sp_revocar_invitacion_usuario`: distingue invitación `PENDIENTE` vigente (`fecha_expiracion > SYSUTCDATETIME()` → `REVOCADA`, auditoría `REVOKE`) de invitación `EXPIRADA` o vencida (`fecha_expiracion <= SYSUTCDATETIME()` → `CANCELADA`, auditoría `CANCEL_EXPIRED`), preservando el registro histórico y rechazando `COMPLETADA` (`50441`) y terminales (`50442`).
   - En `sp_reenviar_invitacion_usuario`: marca la invitación previa como `REVOCADA` si aún estaba vigente o `CANCELADA` si ya estaba vencida/expirada, creando nueva invitación y copiando `dbo.invitaciones_permisos`.

## 2. Backend PHP y vistas (`app/`, `public/`)

- **`app/master_data.php`:** helpers `master_license_short_label()` (etiquetas concisas por categoría sin párrafos del MOPT), `master_validate_vehicle_coherence()` (reglas de carga útil ≤ PMA, articulado y rangos de clase) y `master_driver_license_summary()` (resolución segura de licencia única vs múltiples heredadas vs pendientes sin autopromoción).
- **`app/components.php`:** humanización de `LIVIANO`, `MEDIANO_RIGIDO`, `PESADO_RIGIDO`, `PESADO_ARTICULADO` y `CANCELADA`, además de formateo `es-CR` de medidas (`format_cr_measure()`) que elimina salidas como `.0` o decimales `.00` innecesarios.
- **`app/operational_writes.php` y `app/layout.php`:**
  - `operation_modal_success()` admite `closeModal: true` explícito en `tssaNotice`.
  - `operation_success()` registra un aviso flash de un solo uso en `$_SESSION['tssa_flash_notice']` para mostrar confirmación breve tras `HX-Redirect` sin duplicar toasts.
- **`public/flota/form.php`, `public/flota/guardar.php`, `public/flota/lista.php`, `public/flota.php`:**
  - Conductores: combobox Tom Select único (`#cond-tipo-licencia`, `name="codigo_licencia"`), fecha única (`#cond-vencimiento`), estado operativo con Tom Select (`#cond-estado`), errores inline `role="alert"`, aviso discreto para múltiples categorías heredadas, persistencia transaccional única en `guardar.php` sin default `B4` ni `estado_verificacion` libre desde POST.
  - Vehículos: una única nota de ayuda oscura (`.pma-help-callout`), selectores Tom Select para `#veh-clase` y `#veh-estado`, retiro de odómetro y selector ordinario de homologación, aviso operativo + casilla administrativa explícita únicamente cuando la unidad existente está en `NO_CONFORME`, tabla con columna `Capacidad de carga` (`Útil: … kg · PMA: … kg`).
- **`public/usuarios/invitar.php`, `public/usuarios/invitaciones_lista.php`, `public/usuarios/revocar_invitacion.php`, `public/usuarios/reenviar_invitacion.php`, `public/usuarios.php`:**
  - `invitar.php`: cierre automático con `tssaNotice` en Brevo 2xx y modo Fake; en fallo real de Brevo mantiene el modal abierto, deshabilita el alta duplicada y ofrece botón explícito de reenvío (`hx-post="/usuarios/reenviar_invitacion.php"`).
  - `invitaciones_lista.php`: muestra estado efectivo (comprobando fecha real de caducidad), fecha de vencimiento real también para expiradas, acción `Revocar` para pendientes vigentes y `Descartar` para expiradas, sin acción de revocación en completadas.
  - `revocar_invitacion.php` y `reenviar_invitacion.php`: mapeo seguro de errores SQL sin fugas de excepciones, `HX-Retarget: #invitaciones-feedback` ante errores para no reemplazar la tabla, y toast `tssaNotice` con `closeModal: false` en éxito de acciones en lista.

## 3. Frontend JS y CSS (`public/assets/js/app.js`, `public/assets/css/estilos.css`)

- **Ciclo de modales centralizado:**
  - `tssaNotice` solo cierra modal cuando `event.detail?.error !== true && event.detail?.closeModal !== false`.
  - `htmx:beforeSwap` admite respuestas de error 4xx y 5xx (`400, 403, 404, 405, 409, 422, 429, 500, 502, 503`), manteniendo el modal abierto, enfocando el mensaje de error y liberando el botón de envío.
  - Protección contra doble envío con estado de carga y bloqueo de cierre accidental mientras hay petición en curso.
  - `Escape` cierra primero dropdowns Tom Select o calendarios Flatpickr abiertos antes de cerrar el modal.
  - Unificación del evento `clienteCreado` mediante `operation_modal_success` evitando listeners duplicados.
- **Interacción de licencia y vehículos:**
  - Cambio real en `#cond-tipo-licencia` (ratón, teclado o API `tomselect.setValue`) limpia inmediatamente `#cond-vencimiento` (Flatpickr + valor base) y muestra aviso de nueva fecha requerida; re-seleccionar la misma opción no borra la fecha.
  - Validación inline antes de envío para categoría y fecha de vencimiento (sin cerrar modal ni borrar campos).
  - Sincronización asistida entre `#veh-articulado`, `#veh-clase` y `#veh-pma` en el modal de vehículo.
  - Soporte en `actualizarDiagnosticoHabilitacionViaje()` para metadatos de Tom Select remotos y validación contra fecha programada.
- **Estilos dark coherentes:**
  - `.pma-help-callout` estilizado con tokens oscuros desaturados (sin cajas celestes estridentes de Bulma `is-light`), `.table code` con `white-space: nowrap`, y ajustes responsive para modales y Tom Select en 1366×768, 1440×900, 768×1024 y 375×667.
