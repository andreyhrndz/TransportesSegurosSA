## 1. Contratos SQL

- [x] 1.1 Confirmar catálogo, objetos y política de hash bcrypt desplegados en `TransportesSegurosSA_Test`.
- [x] 1.2 Diseñar e instalar permisos `USUARIOS_VER` y `USUARIOS_GESTIONAR_CUENTAS` idempotentes en `TransportesSegurosSA_Test`.
- [x] 1.3 Implementar las cuatro firmas de cuenta y `sp_revocar_permiso_seguro` con códigos 50412–50420 documentados.

## 2. Seguridad

- [x] 2.1 Verificar actor, unicidad, formato bcrypt de 60 caracteres y exclusión total de hash en resultados y auditoría.
- [x] 2.2 Proteger auto-desactivación, auto-revocación crítica y última cuenta administradora activa bajo transacción con `UPDLOCK, HOLDLOCK`.
- [x] 2.3 Probar concesión/revocación individual delegando en `sp_conceder_permiso` y `sp_revocar_permiso` existentes.

## 3. Verificación

- [x] 3.1 Probar autorizado, denegado, duplicado, hash inválido y usuario inexistente.
- [x] 3.2 Probar cuenta propia y última cuenta administradora en desactivación y revocación.
- [x] 3.3 Validar el change y registrar evidencia SQL.

## Evidencia de ejecución en Test

- Instalado exclusivamente en `TransportesSegurosSA_Test`: `sql/user-accounts/01_user_account_contracts.sql` y `sql/test-environment/06_grant_user_account_permissions_test.sql`. `TransportesSegurosSA` no fue modificada.
- Reversión preparada en `sql/user-accounts/02_rollback_user_account_contracts.sql` y `sql/test-environment/06_revoke_user_account_permissions_test.sql`.
- Pruebas ejecutadas y revertidas con `ROLLBACK`: `sql/user-accounts/03_contract_tests.sql` y `tests/user_accounts_integration_test.php` (compatibilidad `password_hash`/`password_verify`, exclusión de `password_hash`, errores 50410 y 50412–50420, y trazabilidad sin secretos en `dbo.auditorias` para los SP nuevos; `sp_conceder_permiso` original se mantiene intacto sin auditoría propia).
