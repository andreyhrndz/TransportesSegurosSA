## 1. Contratos

- [x] 1.1 Confirmar en SQL Server índices, FKs, SP y permisos actuales de cada maestro.
- [x] 1.2 Acordar matriz de permisos individuales (`UBICACIONES_GESTIONAR`, `CATALOGOS_GESTIONAR`, `FLOTAS_GESTIONAR`, `RUTAS_GESTIONAR`) y campos editables/inmutables en `TransportesSegurosSA_Test`.
- [x] 1.3 Diseñar y desplegar en Test los 21 procedimientos parametrizados de alta, edición y desactivación (`sql/master-data/01_master_data_contracts.sql`), integrados con las listas activas de `sql-read-access-contracts`.

## 2. Salvaguardas

- [x] 2.1 Añadir validaciones de capacidad, kilometraje no regresivo, estados permitidos (prohibiendo `ASIGNADO` manual), vigencia de licencia, origen/destino distintos y referencias activas.
- [x] 2.2 Definir y verificar códigos `50501–50507`, incluyendo conflicto `50505` para ubicaciones, catálogos, vehículos, conductores y rutas comprometidos en operaciones activas.
- [x] 2.3 Revisar y aplicar permisos individuales mínimos para pruebas en `TransportesSegurosSA_Test` (`sql/test-environment/07_grant_master_data_permissions_test.sql` y su script de revocación).

## 3. Verificación

- [x] 3.1 Probar cada permiso, check, FK, unicidad, auditoría y bloqueo de desactivación con registros referenciados (`sql/master-data/03_contract_tests.sql` y `tests/master_data_integration_test.php`).
- [x] 3.2 Probar concurrencia real entre dos conexiones `PDO_SQLSRV` de flota disponible frente a `sp_crear_viaje` bajo `UPDLOCK, HOLDLOCK` (`tests/master_data_integration_test.php`).
- [x] 3.3 Validar estrictamente el change con `openspec validate` y conservar evidencia SQL sin alterar datos preexistentes ni tocar `TransportesSegurosSA`.

## Evidencia y alcance provisional

- Desplegado exclusivamente en `TransportesSegurosSA_Test`: 4 permisos individuales y 21 procedimientos almacenados con soporte de savepoints y trazabilidad real en `dbo.auditorias`.
- No existe reactivación automática de maestros desactivados en este contrato provisional (los registros con `activo = 0` se conservan intactos para integridad histórica y quedan excluidos de selectores operativos con `@solo_activos = 1`).
