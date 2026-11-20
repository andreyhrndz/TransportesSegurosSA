# Plan de QA Integral para Exposición — Transportes Seguros S.A.

## 1. Separación de alcances de calidad

| Tipo de QA | Propósito | Ambiente permitido | Estado actual |
|---|---|---|---|
| **QA automatizado del PR #4 (YAGNI + Invitaciones + Scroll + Mapa)** | Verificar contratos SQL (Migraciones 10 y 11), paginación SQL real, corrección de correo y concurrencia, licencias únicas, preservación de odómetro/homologación, bloqueo global de scroll modal, mapa Leaflet + fallback offline + enlaces Google Maps y matriz visual en 7 resoluciones. | Exclusivamente `TransportesSegurosSA_Test` (servidor aislado puerto `8002`, `require_test_database()`). | Ejecutado en esta entrega (ver evidencia del PR #4). |
| **QA manual integral de exposición** | Revisión guiada paso a paso de todos los módulos antes de promover a `main` o presentar la demostración final. | `TransportesSegurosSA_Test` para toda prueba con escrituras; `TransportesSegurosSA` y `TransportesSegurosSA_DW` **únicamente en solo lectura** para verificación de estado. | **PENDIENTE** (no marcar `PASS` hasta ejecución manual real por el propietario). |

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
| QA-AUTH-05 | Búsqueda, filtro efectivo, paginación y acciones por estado | 1. En `Invitaciones`, probar buscador parcial por correo, filtro `Pendientes`/`Expiradas`/`Revocadas`/`Completadas` y paginación `20/pág`. 2. Verificar que cada fila tiene **1 sola insignia** (estado de invitación) y estado de entrega en texto compacto. 3. Confirmar `PENDIENTE` → `Reenviar` + `Revocar`; `EXPIRADA`/`REVOCADA` → solo `Reenviar` (sin `Cancelar` ni `Editar`); `COMPLETADA` → sin acciones. | Filtrado y paginación ejecutados en SQL Server; sin corte horizontal en 1366×768/1440×900/1600×900/1920×1080; `Revocar` sobre vigente invalida el enlace y conserva filtros/página. | `qa.pendiente@example.test`, `qa.expirada@example.test` | Alta | Captura tabla + paginación | PENDIENTE |
| QA-AUTH-05b | Modal Reenviar: confirmar o corregir correo destinatario | 1. Pulsar `Reenviar` sobre una invitación `PENDIENTE` o `EXPIRADA`. 2. Verificar correo prellenado editable. 3. Probar correo inválido (422, modal abierto) y luego corregir a `qa.corregido@example.test`. 4. Intentar abrir el enlace antiguo en `/auth/onboarding.php?token=...`. | Modal cierra solo tras 200 con toast; registro histórico original conserva correo antiguo (`REVOCADA`); enlace antiguo queda inválido en onboarding; nueva invitación hereda solo permisos autorizados; auditoría `RESEND_CORRECT_EMAIL` y cooldown 60s/5 en 24h activos. | Invitación vigente + `qa.corregido@example.test` | Alta | Captura modal + onboarding rechazado | PENDIENTE |
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
| QA-SOL-04 | Solicitud híbrida: origen puntual en mapa + destino guardado | 1. En `Nueva solicitud`, llenar cliente, fecha y mercancía. 2. Pulsar `Elegir en el mapa` en Origen, marcar un punto, escribir referencia humana (sin marcar `Guardar en ubicaciones frecuentes`) y confirmar `Usar este punto como origen`. 3. Elegir destino guardado en el combobox y guardar. | El modal no se cierra ni pierde cliente/mercancías; la solicitud se crea con `es_reutilizable = 0` para origen; el punto puntual NO aparece en `Flota > Ubicaciones` ni en combobox, pero SÍ aparece en `/solicitudes/detalle.php` con enlace `Abrir en Google Maps`. | `QA-FIS-001` + punto en mapa | Alta | Captura resumen + detalle + Flota | PENDIENTE |
| QA-SOL-05 | Solicitud híbrida: ambos puntos en mapa (uno frecuente opt-in y uno puntual) | 1. En `Nueva solicitud` (con usuario con `UBICACIONES_GESTIONAR`), marcar origen en mapa con `Guardar en ubicaciones frecuentes` activado y alias válido. 2. Marcar destino en mapa sin activar frecuente. 3. Probar `Cambiar`, `Quitar` y `Escape` (cierra solo panel mapa). 4. Guardar. | El punto marcado frecuente aparece luego en `Flota > Ubicaciones` (sin columna numérica de coordenadas); el puntual permanece fuera del catálogo; alternar modos no mezcla `*_id` con `*_modo=mapa`. | Usuario con `UBICACIONES_GESTIONAR` | Alta | Captura mapa + Flota | PENDIENTE |
| QA-SOL-06 | Seguridad anti-escalación y validación geográfica (<= 25 m) | 1. Con usuario con `SOLICITUDES_CREAR` sin `UBICACIONES_GESTIONAR`, abrir `Nueva solicitud` → `Elegir en el mapa`: comprobar casilla frecuente deshabilitada y poder crear punto puntual. 2. Intentar marcar origen y destino a < 25 m (o dos ubicaciones guardadas con las mismas coordenadas). | Sin `UBICACIONES_GESTIONAR` puede crear punto puntual pero no frecuente (POST falsificado retorna 403 / SQL 50233); origen=destino geográfico retorna 422 / SQL 50234 con 0 ubicaciones huérfanas. | Operador sin `UBICACIONES_GESTIONAR` | Alta | Captura + DevTools Network | PENDIENTE |
| QA-SOL-07 | Fallback offline en mapa de Nueva solicitud | 1. En DevTools bloquear `tile.openstreetmap.org`. 2. Abrir `Nueva solicitud` → `Elegir en el mapa`. 3. Verificar aviso, despliegue automático de coordenadas avanzadas y botón `Usar ubicación guardada`. | El fallo de tiles no bloquea la creación de solicitudes tradicionales; permite volver al combobox con 1 clic o ingresar coordenadas manuales válidas. | DevTools Network block | Media | Captura fallback | PENDIENTE |
| QA-SOL-08 | Planificación de viaje con punto puntual de solicitud | 1. Crear solicitud con punto puntual en mapa (QA-SOL-04). 2. En `Viajes` abrir viaje `PLANIFICADO` → `Agregar parada`. 3. Verificar que el punto puntual aparece etiquetado con el código de solicitud, agregar paradas RECOGIDA y ENTREGA, asignar la solicitud y abrir `/viajes/detalle.php`. | El punto puntual es seleccionable en paradas mientras la solicitud está abierta (sin contaminar `Flota > Ubicaciones`); `/viajes/detalle.php` muestra `Abrir en Google Maps` exacto en las paradas. | Solicitud de QA-SOL-04 + viaje planificado | Alta | Captura paradas + Google Maps | PENDIENTE |

### Módulo 4: Flota, licencias, PMA, rutas y ubicaciones

| ID | Escenario | Pasos | Resultado esperado | Datos de prueba | Prioridad | Evidencia | Estado |
|---|---|---|---|---|---|---|---|
| QA-FLT-01 | Alta y edición de vehículo simplificado | 1. Abrir `Flota` → `Vehículos` → `Nuevo vehículo`. 2. Verificar ausencia de odómetro y homologación, ayuda única PMA y selectores Tom Select. 3. Probar carga útil > PMA (422). 4. Corregir y guardar. | Error 422 mantiene modal abierto; éxito cierra modal y muestra `Útil: … kg · PMA: … kg` sin columna de odómetro ni etiqueta `Homol.`. | Placa `QA-NEW-01`, útil `3000`, PMA `7500` | Alta | Captura modal + tabla | PENDIENTE |
| QA-FLT-02 | Preservación de odómetro y bloqueo `NO_CONFORME` | 1. Editar `QA-BLK-NC` sin marcar levantamiento de bloqueo. 2. Comprobar en BD Test que conserva `kilometraje_actual = 12345.5` y `estado_homologacion = 'NO_CONFORME'`. | Muestra aviso claro de unidad bloqueada; edición ordinaria no resetea kilometraje ni levanta bloqueo en silencio. | `QA-BLK-NC` | Alta | Captura aviso + SELECT BD | PENDIENTE |
| QA-FLT-03 | Alta de conductor con licencia única y limpieza de vencimiento | 1. Abrir `Conductores` → `Nuevo conductor`. 2. Confirmar combobox vacío (sin B4) y fecha vacía. 3. Elegir `B2`, ingresar fecha futura y guardar. 4. Reabrir edición, cambiar `B2` → `B1`. | Al cambiar `B2` → `B1`, la fecha se limpia de inmediato y bloquea guardado con error inline hasta ingresar nueva fecha; re-seleccionar misma categoría conserva la fecha. | Conductor efímero `QA-COND-NEW` | Alta | Captura antes/después cambio | PENDIENTE |
| QA-FLT-04 | Resolución de conductor con categorías heredadas | 1. Abrir edición de `QA-COND-MULTI` (tiene `B1` y `B4`). 2. Verificar advertencia discreta sin preselección. 3. Elegir `B1` + fecha futura y guardar. | Solo `B1` queda activa en BD; historial `B1 | B4` queda en `dbo.auditorias`; ya no puede operar camión `QA-B2-7500` ni `QA-B4-ART`. | `QA-COND-MULTI` | Alta | Captura aviso + verificación viaje | PENDIENTE |
| QA-FLT-05 | Ubicaciones con mapa Leaflet, opciones avanzadas y fallback | 1. Abrir `Flota` → `Ubicaciones` → `Nueva ubicación`. 2. Escribir nombre y dirección, hacer clic en el mapa y arrastrar marcador. 3. Abrir «Opciones avanzadas» y editar lat/lon verificando sincronización del marcador. 4. Guardar y reabrir `Editar` comprobando marcador cargado. 5. Crear otra ubicación sin punto (guardar solo dirección). 6. Simular modo sin conexión/bloqueo de `tile.openstreetmap.org`. | Clic/arrastre llena lat/lon a 6 decimales sin inventar dirección; edición recupera marcador; sin punto persiste `NULL` (jamás `0,0`); sin tiles abre automáticamente las coordenadas manuales con aviso y permite guardar; atribución OpenStreetMap visible. | `QA Bodega Mapa`, `QA Bodega Sin Punto` | Alta | Captura mapa + fallback + SELECT BD | PENDIENTE |
| QA-FLT-06 | Enlaces «Abrir en Google Maps» (Flota, Solicitud y Viaje) | 1. Verificar botón `Abrir en Google Maps` en listado de ubicaciones (confirmando ausencia de columna numérica de coordenadas), origen/destino de `/solicitudes/detalle.php` y paradas de `/viajes/detalle.php`. 2. Probar una ubicación con punto y otra sin punto. | Con coordenadas abre `https://www.google.com/maps/search/?api=1&query=<lat>,<lon>` en nueva pestaña (`rel="noopener noreferrer"`); sin coordenadas muestra «Sin punto registrado» sin inventar enlace. | Ubicaciones de QA-FLT-05 en solicitud y viaje | Alta | Captura enlaces + URL | PENDIENTE |
| QA-FLT-07 | Rutas y catálogos protegidos | 1. Crear ruta entre dos ubicaciones distintas y elemento de catálogo. 2. Intentar desactivar ubicación usada en ruta activa. | Altas cierran modal tras éxito; desactivación de recurso en uso responde 409 con modal abierto y mensaje claro. | Datos sintéticos `QA-UBI-*` | Media | Captura | PENDIENTE |

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

### Módulo 7: Bitácora independiente, Reportes, Data Warehouse/ETL, respaldos y Jobs

> [!IMPORTANT]
> Los casos sobre `TransportesSegurosSA`, `TransportesSegurosSA_DW`, backups y SQL Server Agent Jobs son **estrictamente de verificación en solo lectura**. No ejecutar `RESTORE`, `DELETE`, ni disparar jobs automáticamente sobre la base original durante el QA.

| ID | Escenario | Pasos | Resultado esperado | Datos de prueba | Prioridad | Evidencia | Estado |
|---|---|---|---|---|---|---|---|
| QA-VIA-03 | Reordenamiento seguro de paradas (`PLANIFICADO`) | 1. En `/viajes/detalle.php?id=...` de un viaje `PLANIFICADO` con ≥ 2 paradas y solicitudes asignadas, abrir `Reordenar paradas`. 2. Intercambiar dos paradas manteniendo recogida < entrega y guardar. 3. Intentar ubicar una entrega antes que su recogida y guardar. | El orden válido persiste en SQL (`1..N`) manteniendo los `parada_id` y registra evento `REORDER` en bitácora; el orden inválido se rechaza (`409`) dentro del modal sin mutar filas. | Viaje `PLANIFICADO` con 4 paradas y 2 solicitudes | Alta | Captura modal + tabla | PENDIENTE |
| QA-VIA-04 | Edición controlada de parada no vinculada vs vinculada | 1. En viaje `PLANIFICADO`, verificar que paradas vinculadas a solicitudes muestran `Vinculada a solicitud` (sin botón `Editar`). 2. Editar ubicación/tipo de una parada NO vinculada. 3. Iniciar viaje y comprobar que desaparecen `Reordenar paradas` y `Editar`. | Solo se edita una parada no vinculada en estado `PLANIFICADO`; una vez `EN_CURSO`, los controles desaparecen y las mutaciones se bloquean (`409`). Nota visible aclara que el estado de paradas se actualiza automáticamente al iniciar/finalizar (sin GPS en tiempo real). | Viaje `PLANIFICADO` → `EN_CURSO` | Alta | Captura detalle + modal | PENDIENTE |
| QA-VIA-05 | Selector inteligente de paradas en «Asignar solicitud» | 1. En viaje `PLANIFICADO`, abrir `Asignar solicitud` y confirmar que recogida/entrega inician bloqueados con «Selecciona primero una solicitud». 2. Elegir solicitud con pareja única válida y confirmar preselección y exclusión de paradas `INTERMEDIA` o distinto `ubicacion_id`. 3. Elegir solicitud con orden invertido o sin parada compatible. | Filtra reactivamente recogida (`RECOGIDA`/`MIXTA` + `origen_id`) y entrega (`ENTREGA`/`MIXTA` + `destino_id`) respetando `orden_recogida < orden_entrega`; ante incompatibilidad muestra aviso concreto y deshabilita `Asignar`. | Viaje `PLANIFICADO` con paradas variadas | Alta | Captura modal | PENDIENTE |
| QA-AUD-01 | Bitácora independiente en sidebar y permisos | 1. Verificar entrada independiente **Historial de actividad** (`/auditoria.php`) bajo `Administración` visible solo con `AUDITORIA_VER`. 2. Confirmar que **Reportes** ya no incluye pestaña de auditoría y que `/reportes.php?seccion=auditoria` redirige a `/auditoria.php` con permiso (o `403` sin él). | Separación limpia de módulos y control de acceso estricto en UI y backend. | Cuentas con/sin `AUDITORIA_VER` y `REPORTES_VER` | Alta | Captura sidebar + HTTP | PENDIENTE |
| QA-AUD-02 | Filtros automáticos SQL y lenguaje humano en bitácora | 1. En `/auditoria.php`, confirmar ausencia de botón `Filtrar` y presencia de `Limpiar`. 2. Escribir usuario (incluyendo inactivo o `sistema`) y cambiar módulo, acción y fechas observando actualización automática sin duplicados y reinicio a página 1. 3. Expandir `Ver soporte` y pulsar `Limpiar`. | Filtros se aplican automáticamente vía HTMX; eventos en lenguaje humano sin exponer secretos; `Limpiar` restablece todos los filtros. | Eventos reales en `dbo.auditorias` | Alta | Captura tabla + soporte | PENDIENTE |
| QA-REP-01 | Reportes operacionales (`Solicitudes` y `Entregas operativas`) | 1. Abrir `/reportes.php`, confirmar ausencia de botón `Aplicar`, presencia de `Limpiar` y pestañas **Solicitudes**, **Entregas operativas** y **Análisis histórico (DW)**. 2. Verificar filtros automáticos y etiqueta `Base operacional (datos en tiempo real)` en los dos primeros reportes; probar rango vacío y rango invertido (`desde > hasta`). | KPIs coinciden con SQL; tasa de éxito muestra `Entregadas ÷ (Entregadas + Fallidas)`; rango vacío muestra `Sin datos`; rango invertido responde `422` utilizable. | Datos en Test / Original (solo lectura) | Alta | Captura pestañas 1 y 2 | PENDIENTE |
| QA-REP-02 | Reporte 3: **Análisis histórico (DW)** (`TransportesSegurosSA_DW`) | 1. Abrir **Análisis histórico (DW)** en `/reportes.php?seccion=historico_dw`. 2. Filtrar automáticamente por resultado y ruta. 3. Confirmar etiqueta `Data Warehouse de solo lectura (última carga conocida: …)` con aclaración ETL. | Consulta real en modo solo lectura contra `TransportesSegurosSA_DW` (`fact_entregas` + 5 dimensiones) sin disparar ETL ni escribir en el DW. | `TransportesSegurosSA_DW` (solo lectura) | Alta | Captura pestaña 3 | PENDIENTE |
| QA-REP-03 | Exportación CSV segura y vista Imprimir / PDF | 1. En los 3 reportes, descargar **Exportar CSV** y abrir en Excel/editor UTF-8 verificando tildes y encabezados. 2. Abrir **Imprimir / PDF** y comprobar que oculta sidebar/botones y muestra título, fuente, período, KPIs y tabla. | CSV incluye BOM UTF-8 y neutraliza fórmulas (`= + - @`); vista imprimible lista para guardar PDF limpio sin sidebar. | Los 3 reportes | Alta | Archivo CSV + captura print | PENDIENTE |
| QA-BKP-01 | Verificación de respaldos FULL/DIFF y Jobs (solo lectura) | 1. Consultar `msdb.dbo.backupset` y `msdb.dbo.sysjobs` / `sysjobhistory` para revisar existencia y última ejecución de jobs ETL y Backup FULL/DIFF. | Se confirma configuración y bitácora (recordar que SQL Server Agent está detenido en laboratorio, por lo que los Jobs no corren automáticamente). | `msdb` (solo lectura) | Media | Salida `sqlcmd` | PENDIENTE |

### Módulo 8: Navegación, responsive, accesibilidad básica y errores operativos

| ID | Escenario | Pasos | Resultado esperado | Datos de prueba | Prioridad | Evidencia | Estado |
|---|---|---|---|---|---|---|---|
| QA-UX-01 | Responsive desktop-first + tablet/móvil | 1. Inspeccionar Invitaciones, Vehículos, Conductores, Ubicaciones (mapa), Solicitudes y Viajes en `1366×768`, `1440×900`, `1600×900`, `1920×1080`, `1280×720`, `768×1024` y `375×667`. | Cero scroll horizontal indebido en desktop; acciones visibles sin recortes; dropdowns Tom Select, calendarios Flatpickr y mapa Leaflet dentro del viewport. | DevTools / Chromium | Alta | Capturas 7 viewports | PENDIENTE |
| QA-UX-02 | Scroll modal global (Solicitudes y Flota/catálogos) | 1. Desplazar la página de fondo en `Solicitudes`, abrir `Nueva solicitud`, desplegar `Tipo de mercancía` y girar la rueda 5 veces. 2. Repetir en las 5 subpestañas de `Flota y catálogos`, `Clientes`, `Usuarios`, `Viajes` y `Entregas`. 3. Ejecutar 10 ciclos abrir/cerrar (X, Cancelar, backdrop, doble Escape). | El fondo permanece inmóvil (`window.scrollY` sin cambio) mientras la lista de opciones / cuerpo del modal / mapa sí se desplazan; flip superior en bordes inferiores; al cerrar restaura exactamente posición y foco. | Solicitudes + Flota 5 subpestañas | Alta | Registro `scrollY` + captura | PENDIENTE |
| QA-UX-03 | Teclado, foco, doble clic y errores operativos | 1. Navegar modal con `Tab` y probar doble `Escape` (primero cierra dropdown/calendario, segundo cierra modal). 2. Simular fallo de red/500 y doble clic rápido en `Guardar`. | Ante error el modal permanece abierto, con foco en el mensaje y botón re-habilitado; doble clic envía una sola petición. | DevTools Network throttling | Alta | Registro Network | PENDIENTE |

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
| **4:00 – 5:30** | **4. Solicitud híbrida con mapa + viaje + Google Maps** | En `Nueva solicitud`, elegir cliente, seleccionar un origen guardado y marcar un **destino puntual directo en el mapa** (sin marcar «Guardar en ubicaciones frecuentes») con referencia humana; guardar, abrir detalle, planificar parada en `Viajes` y pulsar `Abrir en Google Maps`. Comprobar en `Flota > Ubicaciones` que el catálogo frecuente no se contaminó. | Flujo autónomo sin salir del modal, cero contaminación del catálogo y navegación real al punto exacto. |
| **5:30 – 6:30** | **5. Paradas editables + Invitaciones paginadas** | En un viaje `PLANIFICADO`, abrir `Reordenar paradas` (subir/bajar manteniendo recogida < entrega) y editar una parada no vinculada; luego en `Usuarios y permisos` → `Invitaciones`, mostrar búsqueda/filtro/paginación SQL y `Reenviar` con corrección de correo. | Control transaccional en itinerarios (sin fingir GPS) y gestión segura de invitaciones sin duplicados. |
| **6:30 – 7:45** | **6. Bitácora independiente, Reportes + DW y Exportación** | Abrir **Historial de actividad** (`/auditoria.php`) desde Administración mostrando el reordenamiento recién registrado en lenguaje humano + `<details>` de soporte; cerrar en **Reportes** recorriendo Solicitudes, Entregas (tasa de éxito explícita) y **Desempeño histórico (DW)** rotulado con última carga de `TransportesSegurosSA_DW`, más **Exportar CSV** e **Imprimir / PDF**. | Trazabilidad humana independiente, analítica real conectada al Data Warehouse en modo solo lectura y salidas listas para gerencia. |

---

## 5. Estado de despliegue de bases SQL para la exposición (`TransportesSegurosSA`, `Test` y `DW`)

> [!NOTE]
> Las migraciones **`10`**, **`11`**, **`12`** y **`13`** fueron **validadas en `TransportesSegurosSA_Test`**, respaldadas con `CHECKSUM` + `RESTORE VERIFYONLY`, ensayadas en una restauración temporal aislada (`TransportesSegurosSA_Staging`) y **promovidas de forma controlada a `TransportesSegurosSA` (Original)** preservando el 100% de sus datos, usuarios, permisos y auditorías. Detalle completo en [`docs/ops/deploy-migrations-pr4-exposicion.md`](../ops/deploy-migrations-pr4-exposicion.md).

### Limitaciones conocidas documentadas para la exposición
1. **Semántica de estado de paradas:** al ejecutar `sp_iniciar_viaje` las paradas `RECOGIDA` pasan automáticamente a `COMPLETADA`, y al ejecutar `sp_finalizar_viaje` las paradas `PENDIENTE` restantes pasan a `COMPLETADA`. No representa seguimiento GPS en tiempo real; la UI lo aclara explícitamente en el detalle de viaje.
2. **SQL Server Agent detenido en laboratorio:** los 3 Jobs (`G6_FULL_TransportesSegurosSA`, `G6_DIFF_TransportesSegurosSA`, `G6_ETL_TransportesSegurosSA`) existen habilitados pero no se disparan automáticamente mientras SQL Server Agent esté en estado `Stopped`. El reporte 3 lee en modo solo lectura la última carga conocida del DW sin invocar ETL desde PHP.

### Plan de rollback seguro y NO destructivo (orden inverso `13 → 12 → 11 → 10`)
- `database/migrations/rollbacks/rollback_13_trip_stops_audit_and_reports.sql`
- `database/migrations/rollbacks/rollback_12_hybrid_request_locations.sql`
- `database/migrations/rollbacks/rollback_11_invitations_search_correct_email.sql`
- `database/migrations/rollbacks/rollback_10_yagni_fleet_licenses_and_invitations.sql`
