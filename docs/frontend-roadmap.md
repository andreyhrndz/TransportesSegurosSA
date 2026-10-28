# Hoja de ruta del frontend — Transportes Seguros S.A.

Esta hoja de ruta es **planificación**, no una declaración de funcionalidades completadas. Antes de crear specs de cada módulo, revisar SQL Server, los procedimientos disponibles y el código actual. Cada fase se implementa mediante un OpenSpec change pequeño, con cierre comprobado.

## Base existente a preservar

- Login y logout PHP con hash bcrypt, sesión y CSRF (revisar implementación real).
- Dashboard con información consultada desde SQL Server.
- Listado y registro de clientes físicos/jurídicos con HTMX.
- SQL Server 2025 con base operativa y DW; scripts históricos separados.

## Cambios propuestos, orden de ejecución

| Orden | ID recomendado | Propósito y salida verificable |
|---|---|---|
| 01 | `frontend-foundation-design-system` | Tokens visuales sobrios, componentes UI, navegación responsive, textos comerciales, estados accesibles; sin cambios en BD. |
| 02 | `frontend-auth-and-permissions-hardening` | Auditar cuentas, permisos, sesión, CSRF, controles de acceso, tratamiento de errores y seguridad del conector SQL. |
| 03 | `customer-management-completion` | Ver, registrar, editar y desactivar clientes según reglas y permisos SQL confirmados; no borrar datos relacionados. |
| 04 | `master-data-and-fleet-management` | Ubicaciones, vehículos, conductores, catálogos; listas, formularios, reglas y estados autorizados. |
| 05 | `transport-requests-management` | Solicitudes, mercancías, destinos, estados, prioridad y cancelaciones; experiencia de formularios y errores de negocio. |
| 06 | `trip-planning-and-assignments` | Rutas, viajes, paradas, agrupación de solicitudes y capacidad; respetar las FK y condiciones de un solo viaje por solicitud. |
| 07 | `delivery-incidents-and-documents` | Registrar entrega exitosa o fallida, receptor, evidencia, incidencias y documentos según restricciones SQL. |
| 08 | `dashboards-reports-and-audit` | KPIs, consultas, filtros, DW, visualización de auditorías y notificaciones pertinentes. |
| 09 | `release-quality-and-accessibility` | Pruebas end-to-end, accesibilidad, rendimiento, documentación de operación y despliegue seguro. |

## Reglas de implementación

- Un change activo por área de trabajo hasta tener un flujo confiable; cada cambio se prueba y archiva.
- Nunca declarar que un CRUD existe cuando solo hay un listado.
- Si no existe SP o permiso para cierta acción, abrir change de contrato SQL independiente y obtener autorización antes de modificar la BD.
- Probar al menos escritorio, tablet y móvil; navegación con teclado y formularios con errores.
- La replicación no se presume terminada; documentarla por separado.
- Mantener la UI sin referencias académicas y sin nombres técnicos de tablas que no ayuden al usuario.

## Evidencia esperada por cambio

- `openspec validate <change> --strict` sin errores estructurales.
- Checklist completado solo con validación real.
- Capturas o resultados automatizados de interfaz, sin credenciales ni datos sensibles.
- Comportamientos existentes (login, panel y clientes) sin regresiones.
