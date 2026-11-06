## 1. Descubrimiento y contrato

- [x] 1.1 Consultar metadatos de la instancia para confirmar SP, permisos, triggers y dependencias de clientes.
- [x] 1.2 Acordar campos editables y regla provisional de solicitudes activas para despliegue aislado en `TransportesSegurosSA_Test`.
- [x] 1.3 Especificar `CLIENTES_EDITAR`, `CLIENTES_DESACTIVAR` y códigos de error de negocio 50216–50221.

## 2. Implementación SQL posterior

- [x] 2.1 Crear procedimientos parametrizados que verifiquen permiso y subtipo dentro de una transacción.
- [x] 2.2 Crear desactivación sin DELETE y sin alterar solicitudes, con trazabilidad real en `dbo.auditorias`.
- [x] 2.3 Conceder únicamente los permisos confirmados a usuarios de prueba autorizados en `TransportesSegurosSA_Test`.

## 3. Verificación

- [x] 3.1 Probar edición válida, subtipo incompatible, datos inválidos, permiso ausente y cliente inexistente.
- [x] 3.2 Probar desactivación con y sin solicitudes activas según la regla provisional en `TransportesSegurosSA_Test`.
- [x] 3.3 Validar el change y documentar el script ejecutado y rollback.

## Evidencia de ejecución en Test

- Desplegado exclusivamente en `TransportesSegurosSA_Test`: `sql/customer-lifecycle/01_customer_lifecycle_contracts.sql` y `sql/test-environment/05_grant_customer_lifecycle_permissions_test.sql`. `TransportesSegurosSA` permanece intacta.
- Reversión disponible en `sql/customer-lifecycle/02_rollback_customer_lifecycle_contracts.sql` y `sql/test-environment/05_revoke_customer_lifecycle_permissions_test.sql`.
- Pruebas ejecutadas y revertidas con `ROLLBACK`: `sql/customer-lifecycle/03_contract_tests.sql` y `tests/customer_lifecycle_integration_test.php` (edición física/jurídica, inmutabilidad de identificación/subtipo, errores 50216–50221 y registro real en `dbo.auditorias`).
