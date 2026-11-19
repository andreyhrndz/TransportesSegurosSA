# Plan de QA Integral para Exposición — Transportes Seguros S.A.

## 1. Separación de alcances de calidad

| Tipo de QA | Propósito | Ambiente permitido | Estado actual |
|---|---|---|---|
| **QA automatizado de la corrección YAGNI** | Verificar contratos SQL (Migración 10), lógica HTTP/seguridad PHP, limpieza de licencias, preservación de odómetro/homologación, cierre controlado de modales, revocación/descarte de invitaciones y renderizado Chromium en 4 resoluciones. | Exclusivamente `TransportesSegurosSA_Test` (servidor aislado puerto `8002`, `require_test_database()`). | Ejecutado en esta entrega (ver evidencia del PR). |
| **QA manual integral de exposición** | Revisión guiada paso a paso de todos los módulos antes de fusionar PRs o presentar la demostración final. | `TransportesSegurosSA_Test` para toda prueba con escrituras; `TransportesSegurosSA` y `TransportesSegurosSA_DW` **únicamente en solo lectura** para verificación de estado. | **PENDIENTE** (no marcar `PASS` hasta ejecución manual real). |

> [!CAUTION]
> **Regla absoluta de protección de datos:** Nunca ejecutes pruebas con escrituras, semillas, restauraciones ni jobs destructivos sobre `TransportesSegurosSA` (original) ni `TransportesSegurosSA_DW`. Antes de iniciar el servidor local para QA manual, verifica que `TSSA_DB_DATABASE=TransportesSegurosSA_Test` y confirma `SELECT DB_NAME() = 'TransportesSegurosSA_Test'`.

---

## 2. Preparación de entorno y fixtures sintéticos reproducibles (solo Test)

### 2.1 Arranque aislado en puerto independiente (sin editar `.env`)

```powershell
# 1. Crear fixtures sintéticos y cuenta de prueba QA en TransportesSegurosSA_Test
$env:TSSA_DB_DATABASE = 'TransportesSegurosSA_Test'
& "C:\AdmBD2026\Herramientas\PHP\8.4\php.exe" tests\qa_seed_test_fixtures.php seed

# 2. Iniciar servidor web aislado en puerto 8002 apuntando a Test (Modo correo Fake por defecto)
$env:TSSA_DB_DATABASE = 'TransportesSegurosSA_Test'
$env:TSSA_MAIL_TRANSPORT = 'fake'
$env:TSSA_APP_URL = 'http://127.0.0.1:8002'
& "C:\AdmBD2026\Herramientas\PHP\8.4\php.exe" -S 127.0.0.1:8002 -t public
```

Para limpiar los fixtures sintéticos al finalizar la sesión de QA (sin alterar `admin_proyecto` ni datos preexistentes):

```powershell
$env:TSSA_DB_DATABASE = 'TransportesSegurosSA_Test'
& "C:\AdmBD2026\Herramientas\PHP\8.4\php.exe" tests\qa_seed_test_fixtures.php cleanup
```

### 2.2 Catálogo de fixtures generados por `qa_seed_test_fixtures.php`

- **Cuenta QA operativa/administrativa:** `qa_operador_demo` (credencial temporal generada al ejecutar `seed`, nunca persistida en Git; posee los 28 permisos activos para no tocar `admin_proyecto`).
- **Cuenta QA de solo lectura:** `qa_lectura_demo` (solo permisos `*_VER` para comprobar denegaciones `403` y ocultamiento de botones de escritura).
- **Clientes sintéticos:** `QA-FIS-001` (persona física) y `QA-JUR-001` (persona jurídica).
- **Vehículos sintéticos:**
  - `QA-B1-4000` (Liviano, carga útil 1.200 kg, PMA 4.000 kg, rígido).
  - `QA-B2-7500` (Mediano rígido, carga útil 4.000 kg, PMA 7.500 kg).
  - `QA-B4-ART` (Pesado articulado, carga útil 20.000 kg, PMA 32.000 kg, articulado).
  - `QA-BLK-NC` (Liviano, PMA 3.500 kg, `kilometraje_actual = 12345.5`, `estado_homologacion = 'NO_CONFORME'`).
- **Conductores sintéticos:**
  - `QA-COND-B1` (Categoría única `B1`, vigente).
  - `QA-COND-B2` (Categoría única `B2`, vigente).
  - `QA-COND-B4` (Categoría única `B4`, vigente).
  - `QA-COND-MULTI` (Herencia simulada `B1 + B4` para probar resolución explícita).
- **Invitaciones sintéticas:** una invitación `PENDIENTE` vigente (`qa.pendiente@example.test`) y una invitación `EXPIRADA` (`qa.expirada@example.test`).

---

## 3. Checklist ejecutable de QA manual por módulo

> Convención de estado: `PENDIENTE` | `PASS` | `FAIL` | `BLOQUEADO`. **Todos los casos inician en `PENDIENTE`.**

### Módulo 1: Inicio/cierre de sesión, recuperación, invitaciones y permisos

| ID | Escenario | Pasos | Resultado esperado | Datos de prueba | Prioridad | Evidencia | Estado |
|---|---|---|---|---|---|---|---|
| QA-AUTH-01 | Login válido y cierre de sesión | 1. Abrir `/auth/login.php`. 2. Ingresar `qa_operador_demo` y su clave temporal. 3. Entrar al panel y ejecutar «Cerrar sesión». | Redirige al panel general; al cerrar sesión invalida cookie y bloquea acceso posterior a `/dashboard.php`. | `qa_operador_demo` | Alta | Captura + red 302 | PENDIENTE |
| QA-AUTH-02 | Recuperación de contraseña neutral | 1. En login abrir «¿Olvidaste tu contraseña?». 2. Probar con correo existente y con correo inexistente. | Ambos responden con el mismo mensaje genérico sin enumeración; en modo Fake se genera token de 30 min para cuenta activa. | `qa_operador_demo@example.test` y `noexiste@example.test` | Alta | Captura | PENDIENTE |
| QA-AUTH-03 | Invitación modo prueba y cierre automático | 1. Ir a `Usuarios y permisos` → `Invitaciones` → `Invitar usuario`. 2. Seleccionar 2 permisos y enviar correo sintético. | El modal se cierra automáticamente tras HTTP 200, muestra toast `Invitación registrada en modo prueba` y refresca la tabla. | `qa.nueva@example.test` + `CLIENTES_VER`, `FLOTAS_VER` | Alta | Captura toast + fila | PENDIENTE |
| QA-AUTH-04 | Invitación Brevo 201 vs fallo controlado | 1. Probar en ambiente con Brevo configurado (o mock local): envío aceptado y envío fallido. | HTTP 201 cierra modal con toast `Invitación enviada (aceptada por el proveedor)`; fallo real deja modal abierto con estado `Fallido` y botón `Reenviar invitación` sin duplicar alta. | Correo de prueba controlado | Alta | Captura + estado BD | PENDIENTE |
| QA-AUTH-05 | Revocar vigente y cancelar expirada | 1. En `Invitaciones`, pulsar `Revocar` sobre `qa.pendiente@example.test`. 2. Pulsar `Cancelar` sobre `qa.expirada@example.test`. | La vigente pasa a `Revocada` (token inválido); la expirada pasa a `Cancelada` conservando el registro histórico; la tabla se actualiza sin recargar toda la app. | `qa.pendiente@example.test`, `qa.expirada@example.test` | Alta | Captura tabla + auditoría | PENDIENTE |
| QA-AUTH-06 | Restricción de permisos granulares | 1. Iniciar sesión con `qa_lectura_demo`. 2. Navegar a Clientes, Flota y Usuarios. 3. Intentar POST directo sin permiso. | No se muestran botones de creación/edición; cualquier POST sin permiso responde HTTP 403. | `qa_lectura_demo` | Alta | Captura UI + 403 | PENDIENTE |

### Módulo 2: Clientes físicos/jurídicos y consulta Hacienda

| ID | Escenario | Pasos | Resultado esperado | Datos de prueba | Prioridad | Evidencia | Estado |
|---|---|---|---|---|---|---|---|
| QA-CLI-01 | Alta cliente físico con Hacienda | 1. Abrir `Clientes` → `Nuevo cliente`. 2. Ingresar cédula física válida y pulsar `Consultar Hacienda`. 3. Verificar desglose `Nombre(s)`, `Primer apellido`, `Segundo apellido` y guardar. | Autocompleta campos de persona física; al guardar cierra modal solo tras 200, muestra toast y actualiza tabla. | Cédula CR válida o sintética manual | Alta | Captura modal + tabla | PENDIENTE |
| QA-CLI-02 | Alta cliente jurídico (empresa) | 1. Abrir `Nueva empresa`. 2. Ingresar cédula jurídica y consultar Hacienda (o completar manual). 3. Guardar. | Conserva razón social intacta sin separar apellidos; cierra modal tras 200 y refresca listado. | `3-101-000001` / `QA-JUR-001` | Alta | Captura | PENDIENTE |
| QA-CLI-03 | Edición, error 422 y desactivación | 1. Abrir detalle de cliente → `Editar` → ingresar correo inválido → `Guardar`. 2. Corregir correo → `Guardar`. 3. Desactivar cliente sin solicitudes activas. | Error 422 mantiene modal abierto con datos intactos; éxito cierra modal y refresca detalle; desactivación cambia estado a `Inactivo`. | Cliente efímero creado en QA-CLI-01 | Media | Captura | PENDIENTE |

### Módulo 3: Solicitudes, destinatario mismo/tercero, mercancías, prioridades y cancelación

| ID | Escenario | Pasos | Resultado esperado | Datos de prueba | Prioridad | Evidencia | Estado |
|---|---|---|---|---|---|---|---|
| QA-SOL-01 | Solicitud cliente físico (mismo cliente vs tercero) | 1. Abrir `Solicitudes` → `Nueva solicitud`. 2. Elegir `QA-FIS-001`. 3. Alternar radios `El mismo cliente` y `Otra persona autorizada`. 4. Agregar 2 mercancías y guardar. | Radios interactivos; en `mismo cliente` oculta campos de tercero; en `tercero` exige `Nombre(s)` y `Primer apellido`; crea solicitud y redirige a detalle con toast. | `QA-FIS-001`, origen/destino distintos | Alta | Captura detalle | PENDIENTE |
| QA-SOL-02 | Solicitud cliente jurídico (tercero obligatorio) | 1. Elegir `QA-JUR-001`. 2. Verificar que fuerza `Otra persona autorizada`. 3. Completar receptor físico y guardar. | Opción `El mismo cliente` queda oculta/inhabilitada; requiere persona física receptora y guarda correctamente. | `QA-JUR-001` | Alta | Captura | PENDIENTE |
| QA-SOL-03 | Ajuste de prioridad y cancelación | 1. En detalle de solicitud `REGISTRADA`, abrir `Ajustar prioridad` → `URGENTE` con justificación. 2. En otra solicitud libre, abrir `Cancelar` con motivo. | Ambos modales permanecen abiertos ante error y actualizan estado + historial tras éxito con toast. | Solicitudes creadas en QA-SOL-01/02 | Media | Captura historial | PENDIENTE |

### Módulo 4: Flota, licencias, PMA, rutas y ubicaciones

| ID | Escenario | Pasos | Resultado esperado | Datos de prueba | Prioridad | Evidencia | Estado |
|---|---|---|---|---|---|---|---|
| QA-FLT-01 | Alta y edición de vehículo simplificado | 1. Abrir `Flota` → `Vehículos` → `Nuevo vehículo`. 2. Verificar ausencia de odómetro y homologación, ayuda única PMA y selectores Tom Select. 3. Probar carga útil > PMA (422). 4. Corregir y guardar. | Error 422 mantiene modal abierto; éxito cierra modal y muestra `Útil: … kg · PMA: … kg` sin columna de odómetro ni etiqueta `Homol.`. | Placa `QA-NEW-01`, útil `3000`, PMA `7500` | Alta | Captura modal + tabla | PENDIENTE |
| QA-FLT-02 | Preservación de odómetro y bloqueo `NO_CONFORME` | 1. Editar `QA-BLK-NC` sin marcar levantamiento de bloqueo. 2. Comprobar en BD Test que conserva `kilometraje_actual = 12345.5` y `estado_homologacion = 'NO_CONFORME'`. | Muestra aviso claro de unidad bloqueada; edición ordinaria no resetea kilometraje ni levanta bloqueo en silencio. | `QA-BLK-NC` | Alta | Captura aviso + SELECT BD | PENDIENTE |
| QA-FLT-03 | Alta de conductor con licencia única y limpieza de vencimiento | 1. Abrir `Conductores` → `Nuevo conductor`. 2. Confirmar combobox vacío (sin B4) y fecha vacía. 3. Elegir `B2`, ingresar fecha futura y guardar. 4. Reabrir edición, cambiar `B2` → `B1`. | Al cambiar `B2` → `B1`, la fecha se limpia de inmediato y bloquea guardado con error inline hasta ingresar nueva fecha; re-seleccionar misma categoría conserva la fecha. | Conductor efímero `QA-COND-NEW` | Alta | Captura antes/después cambio | PENDIENTE |
| QA-FLT-04 | Resolución de conductor con categorías heredadas | 1. Abrir edición de `QA-COND-MULTI` (tiene `B1` y `B4`). 2. Verificar advertencia discreta sin preselección. 3. Elegir `B1` + fecha futura y guardar. | Solo `B1` queda activa en BD; historial `B1 | B4` queda en `dbo.auditorias`; ya no puede operar camión `QA-B2-7500` ni `QA-B4-ART`. | `QA-COND-MULTI` | Alta | Captura aviso + verificación viaje | PENDIENTE |
| QA-FLT-05 | Ubicaciones, rutas y catálogos | 1. Crear ubicación, ruta entre dos ubicaciones distintas y elemento de catálogo. 2. Intentar desactivar ubicación usada en ruta activa. | Altas cierran modal tras éxito; desactivación de recurso en uso responde 409 con modal abierto y mensaje claro. | Datos sintéticos `QA-UBI-*` | Media | Captura | PENDIENTE |

### Módulo 5: Viajes, compatibilidad conductor/vehículo, paradas, inicio/finalización/cancelación

| ID | Escenario | Pasos | Resultado esperado | Datos de prueba | Prioridad | Evidencia | Estado |
|---|---|---|---|---|---|---|---|
| QA-VJE-01 | Bloqueo por incompatibilidad B1 → camión 7.500 kg | 1. Abrir `Viajes` → `Nuevo viaje`. 2. Elegir `QA-B2-7500` y `QA-COND-B1`. 3. Observar diagnóstico y pulsar `Crear viaje`. | Diagnóstico rojo inmediato; servidor rechaza con HTTP 422 / Error 50320 y mantiene modal abierto. | `QA-B2-7500` + `QA-COND-B1` | Alta | Captura diagnóstico + error | PENDIENTE |
| QA-VJE-02 | Habilitación válida B2 → 7.500 kg y B4 → articulado | 1. Seleccionar `QA-B2-7500` + `QA-COND-B2` (o `QA-B4-ART` + `QA-COND-B4`) + ruta activa + fecha futura. 2. Crear viaje. | Diagnóstico verde compatible; crea viaje `PLANIFICADO`, pasa unidad y conductor a `ASIGNADO` y redirige a detalle con toast. | `QA-B2-7500` + `QA-COND-B2` | Alta | Captura detalle | PENDIENTE |
| QA-VJE-03 | Ciclo completo: paradas, asignación, inicio, entrega y cierre | 1. Agregar parada RECOGIDA (origen) y ENTREGA (destino). 2. Asignar solicitud compatible. 3. `Iniciar` viaje. 4. Registrar entrega. 5. `Finalizar` viaje. | Cada transición valida precondiciones; al finalizar libera vehículo y conductor a `DISPONIBLE`. | Viaje de QA-VJE-02 + solicitud de QA-SOL-01 | Alta | Captura estados | PENDIENTE |
| QA-VJE-04 | Cancelación de viaje planificado | 1. Crear segundo viaje `PLANIFICADO` y asignar solicitud. 2. Pulsar `Cancelar viaje` con motivo. | Viaje pasa a `CANCELADO`, solicitud vuelve a `PENDIENTE_ASIGNACION`, vehículo y conductor vuelven a `DISPONIBLE`. | Recursos disponibles en Test | Media | Captura | PENDIENTE |

### Módulo 6: Entregas, incidencias y documentos

| ID | Escenario | Pasos | Resultado esperado | Datos de prueba | Prioridad | Evidencia | Estado |
|---|---|---|---|---|---|---|---|
| QA-ENT-01 | Registro de entrega exitosa y fallida | 1. Desde viaje `EN_CURSO`, abrir `Registrar entrega`. 2. Verificar alternancia de campos entre `Entregada` y `Fallida`. 3. Guardar resultado. | Exige receptor + URL comprobante en `Entregada`, o motivo en `Fallida`; actualiza listado en `/entregas.php`. | Asignación `EN_TRANSITO` | Alta | Captura | PENDIENTE |
| QA-ENT-02 | Registro y resolución de incidencia | 1. En `Entregas` → `Incidencias`, registrar nueva incidencia para una solicitud. 2. Abrir `Resolver` e ingresar resolución. | Incidencia cambia a `RESUELTA` y aparece actualizada en listado. | Solicitud activa | Media | Captura | PENDIENTE |
| QA-ENT-03 | Vinculación de documento por URL segura | 1. En `Entregas` → `Documentos`, probar URL no HTTP(S) (422). 2. Ingresar URL HTTPS válida y guardar. | Rechaza URLs inseguras o con credenciales; guarda referencia válida y la lista bajo la solicitud. | `https://example.test/guia-qa.pdf` | Media | Captura | PENDIENTE |

### Módulo 7: Reportes, auditoría, Data Warehouse/ETL, respaldos y Jobs (SOLO LECTURA en original)

> [!IMPORTANT]
> Los casos sobre `TransportesSegurosSA`, `TransportesSegurosSA_DW`, backups y SQL Server Agent Jobs son **estrictamente de verificación en solo lectura**. No ejecutar `RESTORE`, `DELETE`, ni disparar jobs automáticamente sobre la base original durante el QA.

| ID | Escenario | Pasos | Resultado esperado | Datos de prueba | Prioridad | Evidencia | Estado |
|---|---|---|---|---|---|---|---|
| QA-REP-01 | Filtros y pestañas de Reportes y Auditoría | 1. Abrir `/reportes.php` y recorrer Solicitudes, Flota, Viajes, Entregas y Auditoría. 2. Filtrar por rango de fechas y entidad (`conductores_licencias`, `invitaciones_usuario`). | Tablas filtran vía HTMX sin errores; auditoría muestra eventos de licencias e invitaciones con fecha `America/Costa_Rica`. | Datos de pruebas previas en Test | Media | Captura | PENDIENTE |
| QA-DW-01 | Estado de Data Warehouse y ETL (solo lectura) | 1. Ejecutar consulta `SELECT` de conteo en tablas dimensionales y hechos de `TransportesSegurosSA_DW`. 2. Verificar existencia de `sp_cargar_dw` sin modificar datos. | Las 6 dimensiones y hechos existen y responden a consultas de solo lectura sin errores. | `TransportesSegurosSA_DW` (solo lectura) | Media | Salida `sqlcmd` | PENDIENTE |
| QA-BKP-01 | Verificación de respaldos FULL/DIFF y Jobs (solo lectura) | 1. Consultar `msdb.dbo.backupset` y `msdb.dbo.sysjobs` / `sysjobhistory` para revisar existencia y última ejecución de jobs ETL y Backup FULL/DIFF. | Se confirma configuración y bitácora sin disparar respaldos ni restauraciones automáticas sobre la base original. | `msdb` (solo lectura) | Media | Salida `sqlcmd` | PENDIENTE |

### Módulo 8: Navegación, responsive, accesibilidad básica y errores operativos

| ID | Escenario | Pasos | Resultado esperado | Datos de prueba | Prioridad | Evidencia | Estado |
|---|---|---|---|---|---|---|---|
| QA-UX-01 | Responsive en 4 resoluciones | 1. Inspeccionar Vehículos, Conductores, Viajes e Invitaciones en `1366×768`, `1440×900`, `768×1024` y `375×667`. | Sin scroll horizontal indebido (`scrollWidth <= innerWidth`), sin dropdowns Tom Select ni calendarios Flatpickr cortados fuera del viewport. | DevTools / Chromium | Alta | Capturas 4 viewports | PENDIENTE |
| QA-UX-02 | Teclado, foco y `Escape` en modales | 1. Navegar modal de conductor solo con `Tab`, abrir combobox, elegir con flechas/Enter, pulsar `Escape` con dropdown abierto y luego `Escape` con dropdown cerrado. | Foco visible en todo control; primer `Escape` cierra solo el desplegable; segundo `Escape` cierra el modal. | Modal conductor / vehículo | Alta | Registro manual | PENDIENTE |
| QA-UX-03 | Manejo de errores operativos y doble clic | 1. Simular pérdida temporal de red o respuesta 500 en un modal. 2. Probar doble clic rápido en `Guardar`. | Ante error el modal permanece abierto, con foco en el mensaje y botón re-habilitado; doble clic envía una sola petición. | DevTools Network throttling | Alta | Registro Network | PENDIENTE |

---

## 4. Guion de demostración universitaria (5–8 minutos)

> **Prerrequisitos ya preparados antes de empezar la demo (ejecutar `qa_seed_test_fixtures.php seed` o tener datos equivalentes cargados):**
> - Vehículos listos: `QA-B1-4000` (4.000 kg), `QA-B2-7500` (7.500 kg) y `QA-B4-ART` (articulado).
> - Al menos una ruta activa y un cliente físico + un cliente jurídico.
> - Una invitación `EXPIRADA` y una `PENDIENTE` ya visibles en la pestaña Invitaciones (si se demuestra descarte de expirada sin esperar 24h).
> - Para demostrar correo real Brevo en vivo (opcional): verificar `.env` con remitente autorizado antes de iniciar; si no hay red estable, demostrar transparentemente en modo prueba (`fake`).

| Minuto | Bloque | Qué mostrar en pantalla | Mensaje clave |
|---|---|---|---|
| **0:00 – 1:00** | **1. Seguridad y acceso** | Iniciar sesión con cuenta autorizada, mostrar navegación filtrada por permisos granulares y panel general. | Control de acceso individual por permiso, sesiones endurecidas y CSRF en toda mutación. |
| **1:00 – 2:30** | **2. Flota y licencias YAGNI** | Abrir `Nuevo vehículo` (mostrar ayuda única Carga útil vs PMA, selectores Tom Select, sin odómetro/homologación). Abrir `Nuevo conductor`: elegir `B2` + fecha, guardar; reabrir edición, cambiar `B2` → `B1` mostrando limpieza inmediata de fecha y error inline hasta ingresar nueva vigencia. | Formulario simple para el operador; cero asignaciones `B4` implícitas ni tablas de 14 licencias. |
| **2:30 – 4:00** | **3. Compatibilidad legal en Viajes** | En `Viajes` → `Nuevo viaje`: seleccionar camión `7.500 kg` + conductor `B1` → diagnóstico rojo y bloqueo transaccional (`50320`, modal sigue abierto). Cambiar a conductor `B2` → diagnóstico verde y creación exitosa del viaje. | La integridad vive en SQL Server (`fn_conductor_habilitado_vehiculo` + `UPDLOCK, HOLDLOCK`) y se anticipa en UI. |
| **4:00 – 5:30** | **4. Clientes, Solicitudes y Entregas** | Mostrar consulta Hacienda en cliente (o solicitud con destinatario mismo cliente vs tercero obligatorio en empresa) y trazabilidad de viaje/entrega. | Integración real y reglas operativas coherentes sin sobrecargar la interfaz. |
| **5:30 – 6:45** | **5. Invitaciones revocables** | Ir a `Usuarios y permisos` → `Invitaciones`: emitir nueva invitación (cierre automático + toast + actualización HTMX), revocar una pendiente y cancelar una expirada conservando historial. | Ciclo completo auditable sin borrar registros históricos ni dejar enlaces activos huérfanos. |
| **6:45 – 7:30** | **6. Auditoría y cierre** | Abrir `Reportes` → `Auditoría` mostrando los registros recién generados (cambio de licencia principal, revocación/cancelación) y confirmar estado de DW/respaldos. | Trazabilidad integral de extremo a extremo. |
