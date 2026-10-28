## Why

Existen procedimientos para entrega, incidencias, resolución y documentos, sin interfaz. El módulo debe respetar una sola entrega final por solicitud, sus dos resultados terminales y los contratos actuales basados en URL de comprobante/documento.

## What Changes

- Crear vistas de entregas en tránsito/finalizadas y registro de entrega exitosa o fallida.
- Crear registro/resolución de incidencias y registro de documento de referencia.
- Distinguir destinatario esperado, receptor efectivo y motivo de fallo; consultar notificaciones sólo como historial, sin enviarlas ni reintentarlas.

## Alcance, exclusiones y riesgos

Excluye reintentos, reprogramación, carga binaria/almacenamiento de archivos, envío/reintento de correo y cambios SQL. Riesgo: `archivo_url` no implica un repositorio seguro; se aceptará sólo referencia URL según el contrato existente.

## Capabilities

### New Capabilities
- `delivery-incidents-documents`: Resultado final de entrega, incidencias y documentos de solicitud.

### Modified Capabilities
- Ninguna.

## Impact

Usa `sp_registrar_entrega`, `sp_registrar_incidencia`, `sp_resolver_incidencia`, `sp_registrar_documento`, vistas y permisos existentes, más contratos de lectura.
