## Context

`sp_crear_solicitud` exige cliente activo, origen/destino distintos, entrega futura y al menos una mercancía con peso/volumen positivo; calcula prioridad. Ajuste/cancelación sólo admiten REGISTRADA o PENDIENTE_ASIGNACION y cancelar exige que no exista asignación.

## Goals / Non-Goals

**Goals:** formularios de varias mercancías, detalle legible y acciones condicionadas por contrato.

**Non-Goals:** reintento, reprogramación, edición de datos de solicitud, asignación a viaje o registro de receptor efectivo.

## Decisions

- El servidor valida índices de mercancías, longitudes y fechas, construye el TVP y mapea errores 50203–50215 sin mostrarlos literalmente.
- La prioridad calculada se presenta como resultado; un ajuste exige justificación y permiso explícito.
- El destinatario previsto permanece en `solicitudes`; receptor real no se captura aquí.

## Risks / Trade-offs

- [TVP PDO_SQLSRV] → probar binding y transacción contra la firma real antes de UI final.
- [Estados concurrentes] → refrescar detalle tras acción y tratar error SP como fuente de verdad.

## Migration Plan

Integrar primero alta y lista, después acciones de prioridad/cancelación; revertir endpoints sin modificar datos existentes.
