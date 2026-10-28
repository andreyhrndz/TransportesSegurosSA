# Hoja de ruta de implementación

## Diagnóstico auditado

Implementado: login/logout, sesión/CSRF, permiso por código, dashboard de lectura y consulta/alta de clientes. El directorio usa temporalmente `CLIENTES_CREAR` porque no existe `CLIENTES_VER` en el catálogo de referencia. No hay frontend para maestros, solicitudes, viajes, entregas, incidencias, documentos, reportes ni auditoría. La comprobación local no alcanzó SQL Server: informó ODBC Driver 17 sin soporte de la configuración de cifrado. Los scripts son referencia, no prueba de despliegue.

## Orden definitivo

| Orden | Change | Dependencias | Riesgo/SQL | Cierre |
|---:|---|---|---|---|
| 01 | `frontend-foundation-design-system` | — | UI; preservar HTMX/CSRF | Tokens, componentes y smoke visual |
| 02 | `frontend-auth-and-permissions-hardening` | 01 | Sesión y respuestas HTMX | Login/logout/403/CSRF probados |
| 03 | `sql-read-access-contracts` | 02 | Permisos `*_VER`, listas/detalles | DBA prueba acceso/denegación |
| 04 | `sql-customer-lifecycle-contracts` | 03 | Faltan edición/desactivación | SP no destructivos probados |
| 05 | `customer-management-completion` | 01–04 | PII y subtipos | Directorio/ciclo completo |
| 06 | `sql-master-data-contracts` | 03 | Faltan SP/permisos maestros | Checks/FK/estados probados |
| 07 | `master-data-and-fleet-management` | 01–03, 06 | Disponibilidad de flota | Maestros reales, sin GPS |
| 08 | `transport-requests-management` | 01–03, 05, 07 | TVP y estados | Alta/mercancías/prioridad/cancelación |
| 09 | `trip-planning-and-assignments` | 01–03, 07–08 | Capacidad y exclusividad | Varios pedidos; ciclo válido |
| 10 | `delivery-incidents-and-documents` | 01–03, 08–09 | Entrega única y URL | Resultado/incidencias/documentos |
| 11 | `dashboards-reports-and-audit` | 03, 05, 07–10 | DW/ETL y PII | Fuentes/filtros/auditoría explícitos |
| 12 | `release-quality-and-accessibility` | 01–11 | TLS/ambiente y calidad | Evidencia, WCAG AA, gate de release |

## Contratos SQL

Documentados en scripts: altas de clientes; solicitud/TVP, prioridad y cancelación; viaje, paradas, asignación, inicio/finalización; entrega; incidencias; documentos; permisos, funciones y vistas. Pendientes: permisos de lectura, ciclo de vida de clientes y operaciones de maestros/flota. Ninguno se presume desplegado hasta revisión SQL aprobada.

## Aceptación transversal

- Validación OpenSpec estricta y tareas sin marcar hasta evidencia.
- PHP parametrizado, salida escapada, sesión/CSRF y autorización por permiso.
- SQL probado con acceso, denegación, validación y concurrencia relevante; sin DELETE no aprobado.
- UI en desktop/tablet/móvil, teclado, foco, contraste, estados y HTMX.
- Smoke de login, dashboard y clientes; registrar bloqueos de ambiente.
- PR humano revisado; nunca merge ni auto-merge de agentes.
