# Propuesta: Cierre YAGNI — Flota, licencias sencillas, selectores visuales, modales coherentes e invitaciones revocables

## Problema

La gestión de flota e invitaciones presenta complejidad operativa innecesaria e inconsistencias de UX y contratos de datos:

1. **Conductores y licencias:** el formulario despliega una tabla de 14 categorías (`license-selector-table`) con columnas de verificación, textos normativos extensos y doble captura de vencimientos; además `public/flota/guardar.php` y `dbo.sp_crear_conductor` asignan `B4` por defecto ante ausencia de selección y admiten promoción silenciosa o categorías ocultas desincronizadas.
2. **Vehículos:** el formulario presenta dos recuadros azules redundantes sobre normativa, solicita odómetro/kilometraje y un selector manual de homologación en el flujo ordinario, y usa `<select>` nativos sin el patrón Tom Select del resto de la plataforma.
3. **Modales globales:** `app.js` cierra modales ante `tssaNotice` incluso cuando `error: true`, no intercambia respuestas HTTP 500/503 dentro de modales, cierra el modal entero al pulsar `Escape` aunque haya un desplegable abierto, y `public/usuarios/invitar.php` deja una pantalla intermedia con botón «Entendido» en lugar de cerrar automáticamente al confirmarse el despacho o aislar fallos reales de Brevo.
4. **Invitaciones:** las invitaciones en estado `EXPIRADA` no ofrecen cancelación/descarte claro desde la lista, los errores de revocación/reenvío reemplazan toda la tabla en `#invitaciones-resultado`, y `revocar_invitacion.php` expone excepciones crudas de PDO.

## Alcance

- Simplificar el formulario de conductores a un único campo de número de licencia, un único combobox Tom Select de tipo de licencia (sin preselección en alta y limpieza inmediata de vencimiento al cambiar de categoría) y un único selector de fecha de vencimiento costarricense.
- Eliminar cualquier asignación implícita de `B4` en PHP y en `dbo.sp_crear_conductor`, incorporar contrato atómico `dbo.sp_actualizar_licencia_principal_conductor` con auditoría completa y manejo explícito de conductores heredados con múltiples categorías, conservando la validación de compatibilidad por PMA/articulación en `dbo.fn_conductor_habilitado_vehiculo`, `sp_crear_viaje` y `sp_iniciar_viaje`.
- Simplificar el formulario y listado de vehículos conservando PMA, carga útil, volumen, clasificación legal, condición articulada y estado operativo; retirar odómetro y selector ordinario de homologación de la UI preservando el kilometraje y el bloqueo `NO_CONFORME` en BD, y estandarizar selectores con Tom Select.
- Centralizar el ciclo de vida de cierre de todos los modales únicamente tras éxito confirmado por servidor, prevenir doble envío, mostrar errores 4xx/5xx sin cerrar ni perder datos, y ajustar el flujo de invitaciones (cierre automático en Brevo 201 / modo Fake, preservación de advertencia y reenvío seguro sin duplicación cuando Brevo falla).
- Permitir revocar invitaciones vigentes (`PENDIENTE` → `REVOCADA`) y cancelar/descartar invitaciones expiradas (`EXPIRADA` → `CANCELADA`) con auditoría, sin permitir revocar completadas ni eliminar historial, manteniendo intacta la tabla ante errores HTMX.
- Preparar la migración SQL incremental `10_yagni_fleet_licenses_and_invitations_contracts.sql` (y su espejo en `sql/` más rollback y tests T-SQL) aplicada exclusivamente en `TransportesSegurosSA_Test`.
- Entregar el plan de QA integral ejecutable en `docs/qa/plan-qa-exposicion.md` y suites automatizadas PHP, T-SQL y Playwright (4 resoluciones).

## Fuera de alcance

- Rediseño general de la plataforma, módulos nuevos, métricas nuevas, Data Warehouse/ETL, respaldos/Jobs o CI/CD.
- Cambios en `TransportesSegurosSA` original o `TransportesSegurosSA_DW`, o alteración de `admin_proyecto`.
- Cambios de autenticación o integración Hacienda/Brevo fuera de los eventos HTMX y semántica de feedback de invitaciones.

## Dependencias y riesgos

- **Dependencia SQL:** requiere aplicar la migración incremental 10 en `TransportesSegurosSA_Test` (y dejarla documentada para despliegue posterior autorizado en la base original).
- **Riesgo de regresión en contratos de flota:** pruebas existentes que omitían categoría inicial o `pma_kg` deben actualizarse para declarar datos explícitos en lugar de depender de defaults implícitos.
- **Riesgo de datos heredados:** si un conductor posee varias categorías en `dbo.conductores_licencias`, no se elige arbitrariamente una; la UI advierte y exige selección explícita registrando el historial previo en `dbo.auditorias`.

## Estrategia de reversión

- Revertir el código PHP/JS/CSS mediante `git revert` sobre la rama de cambio.
- En base de datos de pruebas (o tras un futuro despliegue), ejecutar `database/migrations/rollbacks/rollback_10_yagni_fleet_licenses_and_invitations.sql`, que restaura las definiciones previas de los procedimientos sin eliminar columnas ni datos existentes.
