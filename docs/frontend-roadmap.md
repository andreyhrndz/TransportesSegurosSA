# Hoja de ruta de implementación

## Diagnóstico y evidencia

El frontend implementa login/logout, sesión/CSRF, permisos por código, dashboard y consulta/alta de clientes. Todo lo demás es planificación. La conexión PHP 8.4/PDO_SQLSRV con ODBC Driver 18 fue verificada en el equipo principal y confirmó 24 tablas. El fallo aislado de Codex con ODBC 17 y cifrado es sólo una diferencia de ambiente; no constituye un defecto del proyecto ni una excusa para degradar TLS.

## Cobertura de las 24 tablas

| Tabla | Interfaz o exclusión planificada | Change responsable |
|---|---|---|
| `clientes`, `clientes_fisicos`, `clientes_juridicos` | Directorio, detalle, alta, edición y desactivación no destructiva | `customer-management-completion` + contrato SQL |
| `ubicaciones` | Lista, alta, edición, desactivación y selectores activos | `master-data-and-fleet-management` + contrato SQL |
| `tipos_mercancia`, `tipos_incidencia`, `tipos_documento` | Catálogos y selectores activos; sin borrado | `master-data-and-fleet-management` + contrato SQL |
| `vehiculos`, `conductores`, `rutas` | Gestión autorizada, estado/disponibilidad y rutas; sin GPS ni mantenimiento | `master-data-and-fleet-management` + contrato SQL |
| `solicitudes`, `mercancias` | Lista/detalle, alta con TVP, prioridad y cancelación existente | `transport-requests-management` |
| `viajes`, `paradas_viaje`, `viaje_solicitudes` | Crear, paradas, asignar, iniciar/finalizar; sin cancelación/reprogramación inventada | `trip-planning-and-assignments` |
| `entregas` | Consulta y único resultado exitoso/fallido | `delivery-incidents-and-documents` |
| `incidencias`, `documentos` | Registrar/resolver incidencias; registrar metadato/URL de documento | `delivery-incidents-and-documents` |
| `notificaciones` | Sólo historial de intentos/estado con permiso; se excluyen enviar, reintentar y borrar porque no hay SP/servicio | `sql-read-access-contracts` + entrega/reportes |
| `usuarios`, `usuarios_permisos`, `permisos` | Cuentas, estado, restablecimiento y permisos individuales; sin roles ni hashes | `user-accounts-and-permissions-administration` + contrato SQL |
| `historial_estados_solicitud`, `auditorias` | Consulta filtrable y paginada, limitada a cobertura real de triggers | `dashboards-reports-and-audit` + lectura |

## Orden definitivo

| Orden | Change | Dependencias y condición de cierre |
|---:|---|---|
| 01 | `frontend-foundation-design-system` | Tokens, componentes y smoke visual sin romper HTMX/CSRF. |
| 02 | `frontend-auth-and-permissions-hardening` | Sesión, autorización y respuestas HTMX con login/logout/403/CSRF probados. |
| 03 | `sql-read-access-contracts` | Permisos `*_VER`, firmas/paginación y pruebas autorizado/denegado por DBA. |
| 04 | `sql-user-account-contracts` | Contratos de cuenta y autocustodia aprobados/probados; no roles. |
| 05 | `user-accounts-and-permissions-administration` | UI de cuentas y permisos individuales sobre 03–04. |
| 06 | `sql-customer-lifecycle-contracts` | Edición/desactivación definida, aprobada por DBA/producto y probada. |
| 07 | `customer-management-completion` | Directorio/ciclo de cliente sobre 01–03,06. |
| 08 | `sql-master-data-contracts` | SP/permisos maestros, FKs/checks/disponibilidad probados. |
| 09 | `master-data-and-fleet-management` | Maestros/flota reales sobre 01–03,08; sin GPS/mantenimiento. |
| 10 | `transport-requests-management` | TVP y estados sobre 01–03,07,09. |
| 11 | `trip-planning-and-assignments` | Capacidad, paradas y exclusividad sobre 01–03,09–10. |
| 12 | `delivery-incidents-and-documents` | Entrega única e historial de notificación sobre 01–03,10–11. |
| 13 | `dashboards-reports-and-audit` | Fuentes, DW y auditoría real sobre 03,05,07,09–12. |
| 14 | `release-quality-and-accessibility` | Evidencia en equipo principal con ODBC 18, WCAG AA y gate humano. |

## Contratos y pruebas transversales

Los cambios SQL detallan firmas, entradas, salidas, permisos, códigos de error, filtros y límites de página. Se deben contrastar con metadatos de la instancia antes de despliegue; los códigos propuestos no se consideran reservados hasta esa revisión.

Todo change exige validación OpenSpec estricta, PHP parametrizado/escapado, CSRF, permiso individual, pruebas de acceso/denegación y UI en escritorio, tablet, móvil y teclado. El change no se considera terminado sin evidencia y revisión humana del PR; nunca habrá merge ni auto-merge de agentes.
