## Context

`sp_crear_solicitud` exige cliente activo, origen/destino distintos, entrega futura y al menos una mercancía con peso/volumen positivo; calcula prioridad. Ajuste/cancelación sólo admiten REGISTRADA o PENDIENTE_ASIGNACION y cancelar exige que no exista asignación.

## Goals / Non-Goals

**Goals:** formularios de varias mercancías, detalle legible y acciones condicionadas por contrato.

**Non-Goals:** reintento, reprogramación, edición de datos de solicitud, asignación a viaje o registro de receptor efectivo.

## Decisions

- El servidor valida índices de mercancías, longitudes y fechas, construye el TVP y mapea errores 50203–50215 sin mostrarlos literalmente.
- La prioridad calculada se presenta como resultado; un ajuste exige justificación y permiso explícito.
- El destinatario previsto permanece en `solicitudes`; receptor real no se captura aquí.

## Contratos existentes a integrar

`sp_crear_solicitud` recibe código, cliente, origen, destino, nombres/identificación/teléfono del destinatario, fecha requerida, `dbo.tipo_mercancias READONLY` y devuelve `@solicitud_id OUTPUT`; exige `SOLICITUDES_CREAR`. `sp_ajustar_prioridad(@solicitud_id,@prioridad,@justificacion)` exige `SOLICITUDES_AJUSTAR_PRIORIDAD`; `sp_cancelar_solicitud(@solicitud_id,@motivo)` exige `SOLICITUDES_CANCELAR`. Los errores 50203–50215, ya presentes en scripts de referencia, se traducen a mensajes seguros sin depender de texto literal. Filtros/listas y paginación dependen de `sql-read-access-contracts`.

## Risks / Trade-offs

- [TVP PDO_SQLSRV] → probar binding y transacción contra la firma real antes de UI final.
- [Estados concurrentes] → refrescar detalle tras acción y tratar error SP como fuente de verdad.

## Migration Plan

Integrar primero alta y lista, después acciones de prioridad/cancelación; revertir endpoints sin modificar datos existentes.
