## Context

`entregas.viaje_solicitud_id` es único. `sp_registrar_entrega` sólo acepta EN_TRANSITO de viaje EN_CURSO; éxito exige receptor y comprobante URL, fallo exige motivo. La tabla exige receptor y fecha para éxito o motivo para fallo. Incidencias y documentos se relacionan con solicitud.

## Goals / Non-Goals

**Goals:** un único resultado final, trazabilidad de incidencias y referencias documentales.

**Non-Goals:** segundos intentos, subida de archivos, corrección de entrega, envío de notificaciones o cambio de esquema.

## Decisions

- El formulario cambia campos según resultado y valida en servidor; el SP decide la validez terminal.
- Receptor efectivo se captura sólo al éxito y se muestra junto al destinatario previsto sin sobrescribirlo.
- Documentos usan URL/referencia existentes; no se aceptan binarios hasta un contrato de almacenamiento aprobado.

## Risks / Trade-offs

- [URL no confiable] → validar formato, escapar salida y no descargar/ejecutar contenido desde servidor.
- [Entrega duplicada] → tratar 50324 como conflicto y recargar detalle.

## Migration Plan

Implementar primero consulta, luego entrega, incidencias y documentos; revertir UI sin modificar hechos de entrega.
