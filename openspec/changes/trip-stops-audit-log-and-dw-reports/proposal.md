# Propuesta: Itinerarios editables, bitácora independiente y reportes con Data Warehouse

## Por qué
Para cerrar funcionalmente el PR #4 y preparar la demostración con la base operacional original y el Data Warehouse:
1. Los viajes `PLANIFICADO` actualmente solo permiten agregar paradas sin posibilidad de corregir orden ni editar una parada equivocada.
2. El historial de actividad (`dbo.auditorias`) está mezclado como una pestaña dentro de `Reportes`, filtrando por códigos técnicos SQL y sin búsqueda por nombre de usuario ni redacción humana.
3. `Reportes` duplica los listados operativos sin indicadores por período, exportación CSV segura, vista imprimible/PDF ni conexión real al Data Warehouse (`TransportesSegurosSA_DW`).

## Qué cambia
1. **Itinerarios editables (Migración 13):**
   - Nuevo TVP `dbo.tipo_orden_paradas` y SPs transaccionales `dbo.sp_reordenar_paradas_viaje` y `dbo.sp_editar_parada_viaje` con permiso `VIAJES_PARADAS`, bloqueo de concurrencia, actualización de orden en dos fases respetando `CK_paradas_viaje_Orden` y `UQ_paradas_viaje_viaje_id_orden`, validación de precedencia recogida < entrega para todas las solicitudes asignadas y protección referencial de ubicación/tipo en paradas vinculadas.
   - Modales accesibles en `/viajes/detalle.php` para reordenar con botones Subir/Bajar y editar paradas no vinculadas.
2. **Bitácora independiente (`/auditoria.php` y `/auditoria/lista.php`):**
   - Entrada propia «Historial de actividad» en Administración del sidebar visible exclusivamente con `AUDITORIA_VER`; redirección segura desde `/reportes.php?seccion=auditoria`.
   - Extensión retrocompatible de `dbo.sp_listar_auditorias` con búsqueda por usuario (`LEFT JOIN` conservando usuarios inactivos/ausentes), módulo y acción en SQL antes de paginar, y capa de presentación en lenguaje humano con detalle técnico desplegable.
3. **Reportes reales + conexión read-only a `TransportesSegurosSA_DW`:**
   - Reestructuración de `/reportes.php` en 3 reportes con KPIs, Chart.js, paginación, Exportar CSV (UTF-8 Excel + protección CSV formula injection) e Imprimir/PDF (`window.print()`): Solicitudes por período, Entregas y resultados (tasa de éxito explícita) y Desempeño histórico conectado en solo lectura a `TransportesSegurosSA_DW` con fuente y última carga conocidas.
4. **Compatibilidad operacional y guía de despliegue (`docs/ops/deploy-migrations-pr4-exposicion.md`):**
   - Verificación preflight, respaldo FULL con `CHECKSUM` + `RESTORE VERIFYONLY`, ensayo en staging restaurado, compatibilidad hacia atrás con `main` y promoción controlada a `TransportesSegurosSA` original.

## Impacto
- **Base de datos:** Migración `13_trip_stops_audit_and_reports_contracts.sql` 100% aditiva y retrocompatible con `main`, con rollback no destructivo. `TransportesSegurosSA_DW` se consulta exclusivamente en modo solo lectura (`ApplicationIntent=ReadOnly`) sin modificar su esquema ni disparar el ETL desde la web.
- **Seguridad:** Separación estricta de `AUDITORIA_VER` vs `REPORTES_VER`, validación de permisos y CSRF en todos los endpoints nuevos, prohibición de selección de BD vía parámetros HTTP y protección anti-CSV-injection.
