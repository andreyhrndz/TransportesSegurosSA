## 1. Base de módulos

- [x] 1.1 Confirmar contratos SQL y permisos de lectura/escritura por entidad (`FLOTAS_VER/GESTIONAR`, `RUTAS_VER/GESTIONAR`, `UBICACIONES_VER/GESTIONAR`, `CATALOGOS_VER/GESTIONAR`) en `TransportesSegurosSA_Test`.
- [x] 1.2 Crear navegación que muestre sólo módulos y secciones autorizados e implementados (aterrizando automáticamente en la primera sección autorizada).
- [x] 1.3 Crear listas, filtros (`buscar`, `estado` operativo y condición `activo`) y estados vacío/error/carga reutilizables.

## 2. Gestión por dominio

- [x] 2.1 Implementar consulta, alta, edición y desactivación confirmada de ubicaciones y catálogos (`tipo_mercancia`, `tipo_incidencia`, `tipo_documento`).
- [x] 2.2 Implementar consulta, alta, edición y desactivación confirmada de vehículos y conductores distinguiendo estado operativo y condición `activo`, con `placa`/`identificacion` inmutables y estado `ASIGNADO` de sólo lectura.
- [x] 2.3 Implementar consulta, alta, edición y desactivación confirmada de rutas con ubicaciones activas, distancia y duración validadas.

## 3. Verificación

- [x] 3.1 Probar permisos granulares, CSRF, validaciones, duplicados y conflictos de dependencias con viajes y rutas activas (`tests/master_data_http_functional_test.php`).
- [x] 3.2 Probar por HTTP que los selectores de solicitudes, viajes, incidencias y documentos incluyen sólo registros activos y recursos disponibles (`tests/master_data_http_functional_test.php`).
- [ ] 3.3 Probar responsive integral en tablet/móvil y teclado final (pendiente para `release-quality-and-accessibility`; validación estricta OpenSpec y vistas de escritorio completadas).

## Evidencia y limitaciones

- `app/master_data.php`, `public/flota.php`, `public/flota/lista.php`, `public/flota/form.php`, `public/flota/guardar.php`, `public/flota/desactivar_form.php` y `public/flota/desactivar.php` consumen exclusivamente los procedimientos de `sql-read-access-contracts` y `sql-master-data-contracts` sin SQL directo a tablas ni `DELETE`.
- Límite funcional documentado: los contratos de maestros en Test implementan desactivación lógica (`activo = 0`) sin reactivación automática para preservar la trazabilidad histórica; los registros desactivados permanecen consultables con el filtro `Condición de registro: Inactivos/Todos` y quedan excluidos de los selectores operativos.
