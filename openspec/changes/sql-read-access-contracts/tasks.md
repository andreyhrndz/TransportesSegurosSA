## 1. Contrato y seguridad

- [ ] 1.1 Confirmar en la instancia los permisos, vistas y grants realmente desplegados.
- [ ] 1.2 Diseñar script idempotente de permisos `*_VER` y matrices de acceso sin roles.
- [ ] 1.3 Diseñar procedimientos/vistas de lista y detalle con columnas, filtros y paginación permitidos.

## 2. Validación SQL

- [ ] 2.1 Probar cada lectura con contexto autorizado, no autorizado y nulo.
- [ ] 2.2 Revisar planes e índices para filtros y paginación representativos.
- [ ] 2.3 Documentar grants de la cuenta de aplicación con privilegio mínimo.

## 3. Integración posterior

- [ ] 3.1 Actualizar controles PHP para requerir el permiso confirmado, sin fallback de escritura.
- [ ] 3.2 Ejecutar pruebas de regresión de dashboard y clientes.
- [ ] 3.3 Validar el change y registrar evidencia antes de archivarlo.
