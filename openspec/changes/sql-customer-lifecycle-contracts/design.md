## Context

`clientes` tiene identificacion única, activo y relaciones a `solicitudes`; no hay borrado en cascada. Las altas existentes exigen `CLIENTES_CREAR` y usan el usuario de `SESSION_CONTEXT` validado por PHP.

## Goals / Non-Goals

**Goals:** edición transaccional y desactivación reversible por estado, con permisos separados.

**Non-Goals:** DELETE, cambio de tipo físico/jurídico, gestión de cuentas o modificación de solicitudes.

## Decisions

- Proponer `CLIENTES_EDITAR` y `CLIENTES_DESACTIVAR`, ambos verificados dentro del procedimiento.
- Firmas: `sp_actualizar_cliente_fisico(@cliente_id int,@correo nvarchar(150),@telefono nvarchar(30)=NULL,@direccion nvarchar(250)=NULL,@nombre nvarchar(100),@primer_apellido nvarchar(100),@segundo_apellido nvarchar(100)=NULL)`; `sp_actualizar_cliente_juridico(@cliente_id int,@correo nvarchar(150),@telefono nvarchar(30)=NULL,@direccion nvarchar(250)=NULL,@razon_social nvarchar(150),@nombre_comercial nvarchar(150)=NULL,@representante_legal nvarchar(150)=NULL)`; `sp_desactivar_cliente(@cliente_id int)`.
- La identificación y el subtipo son inmutables. Se validan correo, longitudes no vacías de campos obligatorios y unicidad que ya protege la tabla. Errores propuestos: 50216 permiso editar, 50217 permiso desactivar, 50218 subtipo/cliente inválido, 50219 datos inválidos, 50220 cliente con solicitud no terminal, 50221 cliente ya inactivo.
- La desactivación actualiza sólo `activo=0` y se rechaza si existe solicitud en `REGISTRADA`, `PENDIENTE_ASIGNACION`, `ASIGNADA` o `EN_TRANSITO`; solicitudes terminales permanecen como historial. Esta es una decisión funcional explícita del change, no una regla ya confirmada en la instancia.

## Risks / Trade-offs

- [Regla nueva frente a scripts de referencia] → requiere aprobación DBA/producto antes de desplegar; hasta entonces el frontend no ofrece desactivación.
- [Carrera de unicidad] → constraint existente y mapeo de 2601/2627.

## Migration Plan

Crear permisos y SP idempotentes en transacción tras aprobación DBA/producto; rollback elimina sólo objetos nuevos aprobados, nunca datos.
