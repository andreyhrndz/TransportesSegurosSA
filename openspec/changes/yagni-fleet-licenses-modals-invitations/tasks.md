# Tareas: Cierre YAGNI — Flota, licencias, selectores, modales e invitaciones

- [x] 1. Crear y aplicar en `TransportesSegurosSA_Test` (verificando `DB_NAME()`) la migración SQL incremental `10_yagni_fleet_licenses_and_invitations_contracts.sql`, su espejo en `sql/`, rollback y suite contractual T-SQL `14_contract_tests_yagni_fleet_and_invitations.sql`.
- [x] 2. Actualizar helpers de datos maestros, componentes y respuestas operativas (`app/master_data.php`, `app/components.php`, `app/operational_writes.php`, `app/layout.php`).
- [x] 3. Simplificar formularios, guardado y listado de conductores y vehículos (`public/flota.php`, `public/flota/form.php`, `public/flota/guardar.php`, `public/flota/lista.php`).
- [x] 4. Unificar cierre controlado de modales, validaciones inline de licencia/vencimiento, sincronización vehículo y manejo de errores/Escape en `public/assets/js/app.js`, `public/assets/css/estilos.css` y `public/clientes/guardar.php`.
- [x] 5. Implementar flujo de cierre/fallo Brevo/Fake en `public/usuarios/invitar.php` y revocación/descarte seguro con feedback sin destruir tabla en `public/usuarios.php`, `public/usuarios/invitaciones_lista.php`, `public/usuarios/revocar_invitacion.php` y `public/usuarios/reenviar_invitacion.php`.
- [x] 6. Actualizar pruebas existentes afectadas por la eliminación del default `B4` y crear suites nuevas (PHP funcionales/seguridad, T-SQL y Playwright real en 1366×768, 1440×900, 768×1024 y 375×667 con capturas sanitizadas antes/después).
- [x] 7. Crear el plan de QA integral ejecutable en `docs/qa/plan-qa-exposicion.md` y helper/fixtures sintéticos reproducibles en Test.
- [x] 8. Ejecutar todas las suites en `TransportesSegurosSA_Test`, validar OpenSpec (`openspec validate --all`), revisar secretos/`git diff --check`, confirmar integridad intacta de `TransportesSegurosSA` original y publicar PR hacia `development` sin hacer merge.
