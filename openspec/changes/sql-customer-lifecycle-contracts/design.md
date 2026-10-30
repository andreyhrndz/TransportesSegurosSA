## Context

`clientes` tiene identificacion única, activo y relaciones a `solicitudes`; no hay borrado en cascada. Las altas existentes exigen `CLIENTES_CREAR` y usan el usuario de `SESSION_CONTEXT` validado por PHP.

## Goals / Non-Goals

**Goals:** edición transaccional, desactivación reversible por estado y trazabilidad real en `dbo.auditorias`, con permisos separados y despliegue aislado en `TransportesSegurosSA_Test`.

**Non-Goals:** DELETE, cambio de tipo físico/jurídico, gestión de cuentas, modificación de solicitudes o alteración de `TransportesSegurosSA`.

## Decisions

- Registrar `CLIENTES_EDITAR` y `CLIENTES_DESACTIVAR` de forma idempotente en `dbo.permisos`, ambos verificados dentro del procedimiento mediante `dbo.fn_tiene_permiso`.
- Firmas: `sp_actualizar_cliente_fisico(@cliente_id int,@correo nvarchar(150),@telefono nvarchar(30)=NULL,@direccion nvarchar(250)=NULL,@nombre nvarchar(100),@primer_apellido nvarchar(100),@segundo_apellido nvarchar(100)=NULL)`; `sp_actualizar_cliente_juridico(@cliente_id int,@correo nvarchar(150),@telefono nvarchar(30)=NULL,@direccion nvarchar(250)=NULL,@razon_social nvarchar(150),@nombre_comercial nvarchar(150)=NULL,@representante_legal nvarchar(150)=NULL)`; `sp_desactivar_cliente(@cliente_id int)`.
- La identificación y el subtipo son inmutables. Se validan correo, obligatoriedad de campos requeridos y bloqueo transaccional (`UPDLOCK, HOLDLOCK`) compatible con savepoints. Errores: 50216 permiso editar, 50217 permiso desactivar, 50218 subtipo/cliente inválido, 50219 datos inválidos, 50220 cliente con solicitud no terminal, 50221 cliente ya inactivo.
- En `TransportesSegurosSA_Test` la desactivación marca sólo `activo=0` y rechaza solicitudes en `REGISTRADA`, `PENDIENTE_ASIGNACION`, `ASIGNADA` o `EN_TRANSITO`; las terminales permanecen como historial intacto. La regla continúa siendo provisional hasta revisión final antes de desplegar en `TransportesSegurosSA`.
- Como la base original no incluye trigger sobre `clientes`, cada procedimiento registra explícitamente el evento `UPDATE` en `dbo.auditorias` dentro de la misma transacción.

## Risks / Trade-offs

- [Regla provisional en Test] → desplegada exclusivamente en `TransportesSegurosSA_Test` con guarda `DB_NAME()`; requiere aprobación formal antes de pasar a `TransportesSegurosSA`.
- [Trazabilidad] → cubierta por inserción transaccional en `dbo.auditorias` para las mutaciones de estos tres procedimientos; las altas históricas originales no tienen trigger.
- [Carrera de concurrencia] → `UPDLOCK, HOLDLOCK` sobre `clientes` y `solicitudes`.

## Migration Plan

Aplicar permisos y SP idempotentes sólo en `TransportesSegurosSA_Test`; `sql/customer-lifecycle/02_rollback_customer_lifecycle_contracts.sql` elimina únicamente los procedimientos nuevos sin borrar clientes, solicitudes ni historial, y `sql/test-environment/05_revoke_customer_lifecycle_permissions_test.sql` revierte las asignaciones de prueba.
