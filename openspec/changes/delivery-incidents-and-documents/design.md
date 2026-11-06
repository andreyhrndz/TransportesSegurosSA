## Context

`entregas.viaje_solicitud_id` es único. `sp_registrar_entrega` sólo acepta EN_TRANSITO de viaje EN_CURSO; éxito exige receptor y comprobante URL, fallo exige motivo. La tabla exige receptor y fecha para éxito o motivo para fallo. Incidencias y documentos se relacionan con solicitud.

## Goals / Non-Goals

**Goals:** un único resultado final, trazabilidad de incidencias y referencias documentales.

**Non-Goals:** segundos intentos, subida de archivos, corrección de entrega, envío de notificaciones o cambio de esquema.

## Decisions

- El formulario cambia campos según resultado y valida en servidor; el SP decide la validez terminal.
- Receptor efectivo se captura sólo al éxito y se muestra junto al destinatario previsto sin sobrescribirlo.
- Documentos usan URL/referencia existentes; no se aceptan binarios hasta un contrato de almacenamiento aprobado.
- `notificaciones` se presenta, si `NOTIFICACIONES_VER` está aprobado, como lista de intentos/estado; no existe SP ni servicio para crear, enviar o reintentar y esas acciones quedan excluidas.

## Contratos existentes a integrar

`sp_registrar_entrega(@viaje_solicitud_id,@estado,@receptor_nombre=NULL,@receptor_identificacion=NULL,@receptor_telefono=NULL,@comprobante_url=NULL,@motivo_fallo=NULL)` exige `ENTREGAS_REGISTRAR` y puede devolver 50319–50324. `sp_registrar_incidencia(@solicitud_id,@tipo_incidencia_id,@descripcion,@incidencia_id OUTPUT)`, `sp_resolver_incidencia(@incidencia_id,@resolucion)` y `sp_registrar_documento(@solicitud_id,@tipo_documento_id,@numero_referencia,@archivo_url,@documento_id OUTPUT)` usan los permisos/códigos 50401–50406. Los listados, filtros de fecha/estado y paginación se obtienen del contrato de lectura; no se invoca SQL para enviar notificaciones.

## Risks / Trade-offs

- [URL no confiable] → validar formato, escapar salida y no descargar/ejecutar contenido desde servidor.
- [Entrega duplicada] → tratar 50324 como conflicto y recargar detalle.

## Migration Plan

Implementar primero consulta, luego entrega, incidencias y documentos; revertir UI sin modificar hechos de entrega.
