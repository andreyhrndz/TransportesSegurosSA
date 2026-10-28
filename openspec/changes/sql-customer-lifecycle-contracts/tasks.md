## 1. Descubrimiento y contrato

- [ ] 1.1 Consultar metadatos de la instancia para confirmar SP, permisos, triggers y dependencias de clientes.
- [ ] 1.2 Acordar campos editables y regla de solicitudes activas con responsables de negocio/DBA.
- [ ] 1.3 Especificar `CLIENTES_EDITAR`, `CLIENTES_DESACTIVAR` y códigos de error de negocio.

## 2. Implementación SQL posterior

- [ ] 2.1 Crear procedimientos parametrizados que verifiquen permiso y subtipo dentro de una transacción.
- [ ] 2.2 Crear desactivación sin DELETE y sin alterar solicitudes.
- [ ] 2.3 Conceder únicamente los permisos confirmados a usuarios de prueba autorizados.

## 3. Verificación

- [ ] 3.1 Probar edición válida, duplicado, permiso ausente y cliente inexistente.
- [ ] 3.2 Probar desactivación con y sin solicitudes según la regla aprobada.
- [ ] 3.3 Validar el change y documentar el script ejecutado y rollback.
