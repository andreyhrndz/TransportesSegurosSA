## Why

Las solicitudes y mercancías tienen modelo, permisos y procedimientos reales, pero ninguna interfaz. Se necesita un flujo que use el TVP existente, permita prioridad/cancelación dentro de sus estados y distinga destinatario esperado de receptor efectivo.

## What Changes

- Crear consulta, detalle y alta de solicitud con una o más mercancías mediante `dbo.tipo_mercancias` y `sp_crear_solicitud`.
- Permitir ajuste de prioridad y cancelación sólo mediante sus SP y permisos existentes.
- Mostrar estados reales y preparación para asignación, sin alterar la entrega.

## Alcance, exclusiones y riesgos

Excluye reintentos/reprogramación, edición general de solicitud, entrega y SQL nuevo salvo que se confirme necesario. Depende de acceso de lectura, clientes/maestros disponibles y foundation/auth.

## Capabilities

### New Capabilities
- `transport-requests`: Gestión de solicitudes, destinatario esperado y mercancías.

### Modified Capabilities
- Ninguna.

## Impact

Consumirá `sp_crear_solicitud`, `sp_ajustar_prioridad`, `sp_cancelar_solicitud`, `vw_solicitudes_activas` y permisos SQL existentes confirmados en instancia.
