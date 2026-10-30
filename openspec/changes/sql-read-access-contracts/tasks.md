## 1. Contrato y seguridad

- [ ] 1.1 Confirmar en la instancia los permisos, vistas y grants realmente desplegados.
- [x] 1.2 Diseñar script idempotente de permisos `*_VER` y matrices de acceso sin roles.
- [x] 1.3 Diseñar procedimientos/vistas de lista y detalle con columnas, filtros y paginación permitidos.

## 2. Validación SQL

- [ ] 2.1 Probar cada lectura con contexto autorizado, no autorizado y nulo.
- [ ] 2.2 Revisar planes e índices para filtros y paginación representativos.
- [x] 2.3 Documentar grants de la cuenta de aplicación con privilegio mínimo.

## 3. Integración posterior

- [ ] 3.1 Actualizar controles PHP para requerir el permiso confirmado, sin fallback de escritura.
- [ ] 3.2 Ejecutar pruebas de regresión de dashboard y clientes.
- [x] 3.3 Validar el change y registrar evidencia antes de archivarlo.

## Evidencia y limitaciones

- Se prepararon, sin ejecutar, `sql/read-access/01_permissions_and_core_contracts.sql`,
  `02_extended_read_contracts.sql`, `03_grants_template.sql` y `04_contract_tests.sql`.
  Cubren los 24 objetos de datos mediante listados o detalles compuestos; los resultados
  excluyen `password_hash`, no conceden `SELECT` amplio y usan `dbo.fn_tiene_permiso`
  con el `SESSION_CONTEXT` ya establecido por PHP.
- La plantilla de grants exige un principal de base de datos existente y se detiene con
  error si conserva el marcador. No presupone el principal de Windows integrado ni lo crea.
- Se contrastaron nombres de tablas, columnas, estados, índices, relaciones, TVP y SP de
  escritura contra `C:\\AdmBD2026\\BDTransportesSegurosSA`. El intento de inspección con
  `sqlcmd` local no es evidencia contra el proyecto: ese cliente usa ODBC 17 y falló su
  negociación TLS; no se usó para inferir que PDO_SQLSRV/ODBC 18 del proyecto falle.
- No se ejecutó ningún script ni prueba contra `TransportesSegurosSA`; faltan validación
  DBA, principal de aplicación, despliegue aprobado, pruebas de autorización y planes de
  ejecución en una base aislada. Por ello 1.1, 2.1, 2.2 y la integración PHP siguen abiertas.
