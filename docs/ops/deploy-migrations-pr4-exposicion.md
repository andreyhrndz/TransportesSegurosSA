# Despliegue operacional y preparación de bases SQL — Cierre PR #4 (Exposición)

**Fecha de ejecución:** 2026-11-19 (America/Costa_Rica)  
**Instancia SQL Server:** `localhost` (`hp` — Microsoft SQL Server 2025 v17.0.1155.3, Autenticación Integrada de Windows)  
**Rama:** `fix/yagni-flota-licencias-modales-invitaciones` (PR #4 hacia `development`)

---

## 1. Respaldos previos verificados (`WITH CHECKSUM` + `RESTORE VERIFYONLY`)

Los respaldos se generaron **antes** de tocar la base operacional original, en el directorio local estándar de respaldos de SQL Server (**fuera de Git; nunca versionar archivos `.bak`**):

| Base de datos | Ruta local del respaldo | Tipo | Verificación `RESTORE VERIFYONLY WITH CHECKSUM` |
|---|---|---|---|
| `TransportesSegurosSA` | `C:\SQL\Backups\TransportesSegurosSA_PRE_MIG10_13_20261119_225547.bak` | FULL (`CHECKSUM`, 1.314 páginas) | `The backup set on file 1 is valid.` |
| `TransportesSegurosSA_DW` | `C:\SQL\Backups\TransportesSegurosSA_DW_PRE_MIG10_13_20261119_225547.bak` | FULL (`CHECKSUM`, 722 páginas) | `The backup set on file 1 is valid.` |

---

## 2. Ensayo previo en copia aislada (`TransportesSegurosSA_Staging`) y retrocompatibilidad con `main`

1. Se verificó por catálogo (`sys.parameters` + definición SQL) que las migraciones **`10`**, **`11`**, **`12`** y **`13`** son estrictamente **aditivas, idempotentes y retrocompatibles con `origin/main`**:
   - Cero parámetros eliminados ni cambiados de tipo en procedimientos existentes.
   - Todos los parámetros nuevos en SPs previos (`@nuevo_correo`, `@incluir_puntuales`, `@usuario_buscar`, `@accion`, `@prioridad`) son opcionales con valor por defecto (`NULL` / `0`).
   - Las columnas nuevas (`dbo.ubicaciones.es_reutilizable BIT NOT NULL DEFAULT (1)`, y columnas aditivas al final de `sp_obtener_viaje`, `sp_obtener_solicitud`, `sp_listar_solicitudes`, `sp_listar_auditorias`) preservan el comportamiento previo de `main`.
2. Se restauró `TransportesSegurosSA_PRE_MIG10_13_20261119_225547.bak` en una base temporal **`TransportesSegurosSA_Staging`** con `WITH MOVE` hacia archivos físicos aislados (`C:\SQL\SQLData\TransportesSegurosSA_Staging.mdf` / `.ldf`).
3. Se aplicaron `10 → 11 → 12 → 13` secuencialmente sobre `TransportesSegurosSA_Staging` sin errores, se reconciliaron todos los conteos, se probaron llamadas de solo lectura con firmas legacy (`main`) y firmas nuevas (PR #4), y al concluir se eliminó la base temporal `TransportesSegurosSA_Staging`.

---

## 3. Promoción controlada a `TransportesSegurosSA` (Original) y reconciliación pre/postflight

Scripts aplicados en orden estricto con `sqlcmd -S localhost -E -d TransportesSegurosSA -b -I -f 65001`:

1. `database/migrations/10_yagni_fleet_licenses_and_invitations_contracts.sql`
2. `database/migrations/11_invitations_search_correct_email_contracts.sql`
3. `database/migrations/12_hybrid_request_locations_contracts.sql`
4. `database/migrations/13_trip_stops_audit_and_reports_contracts.sql`

### Reconciliación de integridad de datos (Original)

| Indicador / tabla | Preflight (Mig 09) | Postflight (Mig 13) | Estado |
|---|---:|---:|---|
| `dbo.usuarios` | 2 | 2 | Preservado intacto |
| Permisos activos de `admin_proyecto` | 36 | 36 | Preservado intacto |
| `dbo.clientes` | 2 | 2 | Preservado intacto |
| `dbo.ubicaciones` (total / `es_reutilizable = 1`) | 4 / N/A | 4 / 4 | 100% preservadas como frecuentes |
| `dbo.vehiculos` | 2 | 2 | Preservado intacto |
| `dbo.conductores` | 1 | 1 | Preservado intacto |
| `dbo.solicitudes` | 3 | 3 | Preservado intacto |
| `dbo.viajes` | 1 | 1 | Preservado intacto |
| `dbo.entregas` | 2 | 2 | Preservado intacto |
| `dbo.auditorias` | 34 | 34 | Preservado intacto (0 escrituras de prueba) |
| `dbo.invitaciones_usuario` | 2 | 2 | Preservado intacto |

### Objetos contractuales verificados en `TransportesSegurosSA`

- Migración 10: `dbo.fn_conductor_habilitado_vehiculo`, `dbo.sp_actualizar_licencia_principal_conductor`, `dbo.sp_crear_conductor`, `dbo.sp_crear_vehiculo`, `dbo.sp_actualizar_vehiculo`, `dbo.sp_revocar_invitacion_usuario` → **OK**.
- Migración 11: `dbo.sp_listar_invitaciones_paginadas`, `dbo.sp_reenviar_invitacion_usuario`, `dbo.sp_obtener_solicitud`, `dbo.sp_obtener_viaje` → **OK**.
- Migración 12: `dbo.ubicaciones.es_reutilizable`, `IX_ubicaciones_reutilizable_activo_nombre`, `dbo.sp_listar_ubicaciones`, `dbo.sp_listar_ubicaciones_parada`, `dbo.sp_crear_solicitud_con_ubicaciones` → **OK**.
- Migración 13: TVP `dbo.tipo_orden_paradas`, `IX_auditorias_fecha_entidad_accion`, `dbo.sp_reordenar_paradas_viaje`, `dbo.sp_editar_parada_viaje`, `dbo.sp_obtener_viaje`, `dbo.sp_listar_auditorias`, `dbo.sp_listar_solicitudes`, `dbo.sp_reporte_solicitudes_resumen`, `dbo.sp_reporte_entregas_resumen` → **OK**.

### Verificación PHP 8.4 + HTTP GET (estrictamente de solo lectura)

Se levantó un proceso efímero aislado apuntando a `TSSA_DB_DATABASE=TransportesSegurosSA` (sin tocar `.env` ni el servidor del propietario en `127.0.0.1:8001`) y se verificó HTTP **200** en las 17 rutas críticas (`/`, `/solicitudes.php`, `/viajes/detalle.php?id=1`, `/flota.php`, `/usuarios.php`, `/auditoria.php`, `/reportes.php` —incluyendo `historico_dw` conectado a `TransportesSegurosSA_DW`— y exportaciones CSV/Imprimir), confirmando **0 escrituras** antes y después.

---

## 4. Estado del Data Warehouse (`TransportesSegurosSA_DW`) y SQL Server Agent

| Verificación | Resultado observado |
|---|---|
| Base de datos (`DB_NAME()`) | `TransportesSegurosSA_DW` (`ONLINE`, modelo `FULL`) |
| Tablas en esquema estrella (`dbo`) | **6 tablas** (`dim_fecha`, `dim_cliente`, `dim_vehiculo`, `dim_conductor`, `dim_ruta`, `fact_entregas`) |
| Contratos ETL / Vista | `dbo.sp_cargar_datawarehouse = OK`, `dbo.vw_resumen_entregas = OK` |
| Hechos históricos cargados | **`fact_entregas = 2`** (coincide con las 2 entregas reales de `TransportesSegurosSA`) |
| Última carga conocida (`MAX(fecha_carga_utc)`) | `2026-10-28 04:53:20.5254153` (`27/10/2026, 10:53 p. m.` hora Costa Rica) |
| Lectura desde la app web | Conexión dedicada `db_dw()` con `ApplicationIntent=ReadOnly`; jamás ejecuta ETL al abrir `/reportes.php`. |
| **SQL Server Agent** | Servicio **detenido** (`Stopped`, arranque `Manual`). Los 3 Jobs (`G6_FULL_TransportesSegurosSA`, `G6_DIFF_TransportesSegurosSA`, `G6_ETL_TransportesSegurosSA`) existen y tienen `enabled = 1`, pero **no se ejecutan automáticamente** mientras el servicio permanezca detenido. |

> [!NOTE]
> Si durante la exposición se registran nuevas entregas en `TransportesSegurosSA` y se desea reflejarlas en el reporte 3 (**Desempeño histórico (DW)**), el propietario puede ejecutar manualmente (cuando lo considere oportuno) `EXEC TransportesSegurosSA_DW.dbo.sp_cargar_datawarehouse;` o iniciar el servicio de SQL Server Agent. La aplicación web nunca dispara el ETL de forma implícita.

---

## 5. Alternar entre `TransportesSegurosSA_Test` (QA) y `TransportesSegurosSA` (Exposición)

El archivo `.env` local permanece configurado en `TSSA_DB_DATABASE=TransportesSegurosSA_Test` para mantener seguras las sesiones de QA y desarrollo.

- **Arranque sobre `TransportesSegurosSA_Test` (QA / pruebas con escrituras):**
  ```powershell
  $env:TSSA_DB_DATABASE = 'TransportesSegurosSA_Test'
  .\tools\scripts\start-dev.ps1
  ```
- **Arranque sobre `TransportesSegurosSA` (exposición con datos reales):**
  ```powershell
  $env:TSSA_DB_DATABASE = 'TransportesSegurosSA'
  .\tools\scripts\start-dev.ps1
  ```
- El reporte 3 (**Desempeño histórico (DW)**) lee siempre en modo solo lectura desde `TransportesSegurosSA_DW` mediante `db_dw()`, con etiqueta explícita de fuente y última carga conocida.

---

## 6. Plan de rollback seguro y no destructivo (orden inverso `13 → 12 → 11 → 10`)

En caso de requerir reversión de contratos sin eliminar datos ni columnas:

1. `sqlcmd -S localhost -E -d TransportesSegurosSA -b -I -f 65001 -i database\migrations\rollbacks\rollback_13_trip_stops_audit_and_reports.sql`
2. `sqlcmd -S localhost -E -d TransportesSegurosSA -b -I -f 65001 -i database\migrations\rollbacks\rollback_12_hybrid_request_locations.sql`
3. `sqlcmd -S localhost -E -d TransportesSegurosSA -b -I -f 65001 -i database\migrations\rollbacks\rollback_11_invitations_search_correct_email.sql`
4. `sqlcmd -S localhost -E -d TransportesSegurosSA -b -I -f 65001 -i database\migrations\rollbacks\rollback_10_yagni_fleet_licenses_and_invitations.sql`

Y ante una contingencia grave, restaurar el respaldo FULL verificado `C:\SQL\Backups\TransportesSegurosSA_PRE_MIG10_13_20261119_225547.bak`.
