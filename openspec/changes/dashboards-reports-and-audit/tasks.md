## 1. Contratos y fuentes

- [x] 1.1 Confirmar vistas, columnas, permisos y estado real del DW/ETL en SQL Server.
- [x] 1.2 Definir consultas paginadas y filtros permitidos para panel, reportes y auditoría.
- [x] 1.3 Definir qué entidades cuentan con auditoría real y columnas que se pueden mostrar.

## 2. Interfaz

- [x] 2.1 Implementar KPIs operativos y estados vacío/error/carga sin consultas ad hoc inseguras.
- [x] 2.2 Implementar reportes filtrables de solicitudes, flota, viajes y entregas.
- [ ] 2.3 Implementar auditoría autorizada y vista analítica con fuente/fecha visibles.

## 3. Verificación

- [ ] 3.1 Comparar muestras contra SQL y comprobar filtros, límites y paginación.
- [ ] 3.2 Probar permisos, PII, errores de fuente y ausencia de datos DW.
- [ ] 3.3 Probar responsive/accesibilidad y validación estricta.

## Evidencia y límites

- Test confirmó `vw_indicadores_operativos` (fecha, total, entregadas, fallidas y canceladas),
  `sp_listar_auditorias` y los triggers de solicitudes, viajes, asignaciones y entregas. La UI
  usa los contratos `sp_listar_clientes`, `sp_listar_solicitudes`, `sp_listar_vehiculos`,
  `sp_listar_viajes`, `sp_listar_entregas` y `sp_listar_auditorias`; no ejecuta consultas SQL ad hoc.
- `public/reportes.php` filtra y pagina solicitudes, flota, viajes, entregas y auditoría. Auditoría
  muestra únicamente entidad, registro, acción, usuario y fecha, y exige `AUDITORIA_VER`; las
  secciones operativas exigen además `REPORTES_VER` y el permiso del contrato fuente.
- El DW está en línea y contiene dimensiones y `fact_entregas`, pero su único proceso detectado
  es `sp_cargar_datawarehouse`. No se ejecutó ni se presenta información del DW como actual;
  la vista analítica queda pendiente de confirmar fecha de carga, permisos y contrato de lectura.
