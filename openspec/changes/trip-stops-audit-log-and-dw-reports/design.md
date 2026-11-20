# Diseño técnico: Itinerarios editables, bitácora independiente y reportes con DW

## 1. Contratos SQL (Migración 13)
- **TVP `dbo.tipo_orden_paradas`:** `(parada_id INT NOT NULL PRIMARY KEY, orden INT NOT NULL)`. Evita SQL dinámico e impide IDs duplicados a nivel de tipo.
- **Reordenamiento en dos fases seguro para `CK_paradas_viaje_Orden ([orden] > 0)` y `UQ_paradas_viaje_viaje_id_orden`:**
  1. Fase 1: `UPDATE dbo.paradas_viaje SET orden = orden + 100000 WHERE viaje_id = @viaje_id`.
  2. Fase 2: `UPDATE` al nuevo orden `1..N` desde `@paradas`.
  Todo bajo `SET XACT_ABORT ON` + `UPDLOCK, HOLDLOCK` en `dbo.viajes` y `dbo.paradas_viaje`.
- **Retrocompatibilidad con `main`:** `sp_obtener_viaje` agrega columnas al final del resultset 2 (`tiene_solicitudes`, `es_reutilizable`); `sp_listar_auditorias` agrega parámetros opcionales al final (`@usuario_buscar = NULL`, `@accion = NULL`) y columnas aditivas al final (`usuario_activo`, `valores_anteriores`, `valores_nuevos`). Ninguna firma previa se rompe.
- **Reportes operativos agregados:** `dbo.sp_reporte_solicitudes_resumen` y `dbo.sp_reporte_entregas_resumen` centralizan KPIs y distribuciones en SQL para garantizar consistencia 1:1 entre KPIs, gráficos y tabla.

## 2. Conexión read-only al Data Warehouse
- `db_dw()` en `app/bootstrap.php` construye DSN fijo hacia `Database=TransportesSegurosSA_DW;ApplicationIntent=ReadOnly` (nunca modificable por parámetros de petición).
- `app/reports_service.php` valida `DB_NAME() === 'TransportesSegurosSA_DW'` y ejecuta únicamente consultas `SELECT` parametrizadas sobre `dbo.fact_entregas` y sus 5 dimensiones (`dim_fecha`, `dim_ruta`, `dim_vehiculo`, `dim_conductor`, `dim_cliente`).

## 3. Presentación humana de auditoría y exportación segura
- `app/audit_presenter.php` traduce pares `(entidad, accion)` a lenguaje natural veraz y redacta cualquier valor con patrón de credencial/token antes de enviarlo al detalle desplegable.
- `public/reportes/exportar.php` centraliza `formato=csv` (UTF-8 BOM + neutralización anti-CSV-injection + tope de 2 000 filas en servidor) y `formato=imprimir` (hoja limpia print-friendly + `window.print()`).
