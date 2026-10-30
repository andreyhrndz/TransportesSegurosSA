## 1. Descubrimiento y contrato

- [x] 1.1 Consultar metadatos de la instancia para confirmar SP, permisos, triggers y dependencias de clientes.
- [ ] 1.2 Acordar campos editables y regla de solicitudes activas con responsables de negocio/DBA.
- [x] 1.3 Especificar `CLIENTES_EDITAR`, `CLIENTES_DESACTIVAR` y códigos de error de negocio propuestos, pendientes de aprobación.

## 2. Implementación SQL posterior

- [ ] 2.1 Crear procedimientos parametrizados que verifiquen permiso y subtipo dentro de una transacción.
- [ ] 2.2 Crear desactivación sin DELETE y sin alterar solicitudes.
- [ ] 2.3 Conceder únicamente los permisos confirmados a usuarios de prueba autorizados.

## 3. Verificación

- [ ] 3.1 Probar edición válida, duplicado, permiso ausente y cliente inexistente.
- [ ] 3.2 Probar desactivación con y sin solicitudes según la regla aprobada.
- [ ] 3.3 Validar el change y documentar el script ejecutado y rollback.

## Evidencia y bloqueo de aprobación

- Test confirma 24 tablas, FK de `solicitudes` a `clientes`, subtipos con FK a `clientes`, y
  únicamente `sp_crear_cliente_fisico`/`sp_crear_cliente_juridico` como escritura desplegada.
  `sp_actualizar_cliente_*` y `sp_desactivar_cliente` no existen en Test.
- `sql/customer-lifecycle/01_customer_lifecycle_contracts.sql` conserva firmas alineadas con
  las longitudes reales y errores 50216–50221, pero no se ejecutó. No existe trigger de auditoría
  de clientes en el inventario actual; el contrato no debe prometer auditoría hasta aprobarla.
- La regla de bloquear desactivación con solicitudes `REGISTRADA`, `PENDIENTE_ASIGNACION`,
  `ASIGNADA` o `EN_TRANSITO` sigue siendo una propuesta que requiere aprobación explícita de
  producto y DBA. Por ello las tareas 1.2 y 2.x–3.x permanecen abiertas.
