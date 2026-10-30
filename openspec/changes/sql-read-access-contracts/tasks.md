## 1. Contrato y seguridad

- [x] 1.1 Confirmar en la instancia los permisos, vistas y grants realmente desplegados.
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

- En `TransportesSegurosSA` se confirmaron 15 permisos existentes, cinco vistas de referencia
  y 232 grants de objeto. Sólo `REPORTES_VER` era un permiso de lectura; no había permisos
  `*_VER` por dominio. No se modificó esa base.
- En `TransportesSegurosSA_Test` se ejecutaron `sql/read-access/01_permissions_and_core_contracts.sql`
  y `02_extended_read_contracts.sql`: quedaron 15 permisos `*_VER` y 21 procedimientos
  de lista/detalle. `03_grants_template.sql` sigue sin ejecutar porque no existe un principal
  de aplicación aprobado; se detiene si conserva el marcador.
- `05_isolated_authorization_test.sql` pasó en Test con dos identidades transitorias dentro de
  una transacción revertida: la identidad con sólo `CLIENTES_VER` leyó el directorio, la que
  carecía del permiso recibió 50103 y la paginación inválida 50102.
- `04_contract_tests.sql` sigue pendiente de una cuenta de prueba real con todos los `*_VER`;
  no se usó una cuenta privilegiada para sustituir esa prueba. Los contratos cubren los 24
  objetos de datos mediante listados o detalles compuestos, excluyen `password_hash`, no
  conceden `SELECT` amplio y usan `dbo.fn_tiene_permiso` con `SESSION_CONTEXT` de PHP.
- Se contrastaron nombres de tablas, columnas, estados, índices, relaciones, TVP y SP de
  escritura contra `C:\\AdmBD2026\\BDTransportesSegurosSA` y la instancia restaurada.
- El fallo inicial de `sqlcmd` dentro del sandbox fue de identidad/SSPI del entorno Codex.
  Las consultas aisladas y PDO_SQLSRV con ODBC 18 funcionaron fuera de ese sandbox; no fue
  un fallo del proyecto.
- No se ejecutó ningún script ni prueba contra `TransportesSegurosSA`. Faltan el principal
  de aplicación, pruebas de todos los contratos y sus planes de ejecución, y la integración
  PHP que elimine el fallback temporal de lectura con `CLIENTES_CREAR`; por ello 2.1, 2.2 y
  3.1-3.2 siguen abiertas.
