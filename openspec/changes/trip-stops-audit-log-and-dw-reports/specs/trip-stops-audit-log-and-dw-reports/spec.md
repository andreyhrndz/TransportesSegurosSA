# Especificación delta: Itinerarios editables, bitácora independiente y reportes con DW

## ADDED Requirements

### Requirement: Reordenamiento atómico y edición segura de paradas en viajes planificados
En `/viajes/detalle.php`, cuando el viaje esté en estado `PLANIFICADO` y el usuario posea `VIAJES_PARADAS`, el sistema SHALL permitir reordenar todas las paradas del viaje (secuencia consecutiva `1..N`) mediante botones Subir/Bajar y editar ubicación/tipo únicamente en paradas no vinculadas a ninguna asignación en `dbo.viaje_solicitudes`.

#### Scenario: Reordenamiento válido respetando recogida antes que entrega
- **WHEN** un usuario con `VIAJES_PARADAS` intercambia paradas en un viaje `PLANIFICADO` preservando `orden(recogida) < orden(entrega)` para cada solicitud asignada
- **THEN** `dbo.sp_reordenar_paradas_viaje` actualiza todos los órdenes de `1..N` atómicamente en dos fases sin colisión en `UQ_paradas_viaje_viaje_id_orden`, conserva intactos los `parada_id` y asociaciones, y registra la auditoría `REORDER`

#### Scenario: Rechazo de orden inválido o viaje ya iniciado
- **WHEN** el orden propuesto ubica una entrega antes o en la misma posición que su recogida, incluye paradas ajenas/incompletas, o el viaje ya no está `PLANIFICADO`
- **THEN** SQL Server rechaza la operación (`50333`, `50331`, `50332` o `50307`) con rollback completo, y la UI mantiene el modal abierto con mensaje claro

#### Scenario: Protección de ubicación y tipo en paradas vinculadas
- **WHEN** una parada está referenciada por `parada_recogida_id` o `parada_entrega_id` en `dbo.viaje_solicitudes`
- **THEN** la interfaz explica la restricción y `dbo.sp_editar_parada_viaje` rechaza cualquier intento de modificar ubicación o tipo con código `50336`

### Requirement: Bitácora independiente en sidebar con filtros SQL reales y redacción humana
El sistema SHALL ofrecer `/auditoria.php` y `/auditoria/lista.php` como módulo independiente «Historial de actividad» en Administración del sidebar, visible exclusivamente con `AUDITORIA_VER`.

#### Scenario: Acceso separado y redirección de URL histórica
- **WHEN** un usuario con `AUDITORIA_VER` abre `/auditoria.php` o `/reportes.php?seccion=auditoria`
- **THEN** accede a la bitácora independiente con filtros SQL por usuario (coincidencia parcial incluyendo inactivos), módulo humanizado, tipo de acción y rango de fechas con paginación de 20 registros
- **AND WHEN** un usuario sin `AUDITORIA_VER` intenta acceder a `/auditoria.php`, `/auditoria/lista.php` o `/reportes.php?seccion=auditoria`
- **THEN** el servidor responde HTTP 403 sin filtrar registros ni totales

#### Scenario: Eventos legibles sin inventar hechos y detalle técnico desplegable
- **WHEN** se renderiza una fila de `dbo.auditorias`
- **THEN** se presenta una acción humana fiel a la entidad/acción registrada (fallback «Acción registrada» si es desconocida), sin exponer contraseñas ni tokens y conservando IDs originales en un detalle técnico desplegable para soporte

### Requirement: Reportes analíticos con exportación CSV segura, impresión y Data Warehouse real
`/reportes.php` SHALL exigir `REPORTES_VER` (sin incluir la pestaña de auditoría) y presentar tres reportes con indicadores, gráficos Chart.js, filtros automáticos sin botón redundante «Aplicar» (conservando «Limpiar»), exportación CSV protegida contra formula injection y vista print-friendly: **Solicitudes**, **Entregas operativas** (ambos identificados como base operacional en tiempo real, con tasa de éxito explícita) y **Análisis histórico (DW)** conectado en solo lectura a `TransportesSegurosSA_DW`.

#### Scenario: Filtros automáticos en Reportes e Historial de actividad
- **WHEN** un usuario modifica la búsqueda (debounce ~350 ms), un combobox Tom Select o una fecha Flatpickr en `/reportes.php` o `/auditoria.php`
- **THEN** la tabla, los KPIs y los enlaces de exportación/impresión se actualizan automáticamente sin botón «Aplicar»/«Filtrar», reiniciando paginación a página 1 y evitando respuestas asíncronas obsoletas; el botón «Limpiar» restablece todos los filtros conservando la pestaña activa

#### Scenario: Selector inteligente de paradas compatibles al asignar solicitud a un viaje
- **WHEN** un usuario con `VIAJES_ASIGNAR` abre el modal «Asignar solicitud» en `/viajes/detalle.php`
- **THEN** los selectores de parada inician bloqueados hasta elegir una solicitud; al elegirla, «Parada de recogida» muestra únicamente paradas `RECOGIDA`/`MIXTA` con `ubicacion_id = origen_id`, «Parada de entrega» muestra únicamente paradas `ENTREGA`/`MIXTA` con `ubicacion_id = destino_id` (nunca por proximidad ni incluyendo `INTERMEDIA`), respetando `orden(recogida) < orden(entrega)` y paradas distintas, preseleccionando si existe una única pareja válida o bloqueando el envío con aviso concreto si falta compatibilidad, mientras `dbo.sp_asignar_solicitud_viaje` mantiene el rechazo autoritativo en servidor (`50312`)

#### Scenario: Consulta real al Data Warehouse en solo lectura sin ejecutar ETL
- **WHEN** un usuario autorizado consulta el reporte **Análisis histórico (DW)**
- **THEN** el servidor consulta `TransportesSegurosSA_DW` verificando `DB_NAME() = 'TransportesSegurosSA_DW'`, muestra la fuente explícita de solo lectura consolidada por ETL, la última carga conocida (`MAX(fecha_carga_utc)`), advierte que puede no coincidir con cambios operativos recientes y presenta los agregados de `dbo.fact_entregas` sin ejecutar `dbo.sp_cargar_datawarehouse`
- **AND WHEN** el DW no está disponible o no tiene hechos cargados
- **THEN** el reporte degrada elegantemente con mensaje claro sin afectar los demás reportes

#### Scenario: Exportación CSV segura e impresión/PDF limpia
- **WHEN** un usuario autorizado exporta a CSV o abre la vista de impresión de un reporte filtrado
- **THEN** el CSV incluye BOM UTF-8, escapa cualquier celda iniciada en `=`, `+`, `-`, `@`, `\t` o `\r`, respeta el límite seguro de servidor y la vista imprimible omite sidebar, botones y modales mostrando título, período, fuente, fecha y todas las filas filtradas
