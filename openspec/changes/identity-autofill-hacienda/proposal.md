# Propuesta: consulta a Hacienda y destinatario condicional en solicitudes

## Why

El registro manual de clientes, terceros y nuevos usuarios en Costa Rica suele provocar inconsistencias en los nombres fiscales y retrasos por digitación repetitiva de datos ya existentes en registros oficiales o en el directorio interno de clientes:
1. En el alta y edición de clientes (físicos y empresas) y en el registro de usuarios, los formularios solicitan primero nombres y apellidos antes de la identificación, sin aprovechar la consulta de actividades económicas del Ministerio de Hacienda de Costa Rica (`api.hacienda.go.cr`).
2. En el modal de «Nueva solicitud», el operador está obligado a redactar de nuevo los datos del destinatario, aun cuando el cliente físico que contrata el servicio es quien recibirá personalmente la mercancía.
3. Para clientes jurídicos (empresas), no se contempla una distinción clara para solicitar obligatoriamente una persona física autorizada para la recepción, ni se facilita la consulta rápida ante receptores terceros.

## What Changes

- **Integración con API de Hacienda desde servidor PHP:** implementar un servicio en backend (`app/hacienda_service.php`) que consulte `https://api.hacienda.go.cr/fe/ae?identificacion=` vía cURL HTTPS con timeout controlado (5s), validación previa de formato (física 9 dígitos, jurídica 10 dígitos, DIMEX 11-12, NITE 10), protección contra SSRF y límites de frecuencia mediante una pequeña caché en memoria o temporal.
- **Identificación primero en formularios:** reorganizar los formularios de clientes, personas autorizadas y registro de usuarios para solicitar el tipo y número de identificación como primer campo, habilitando el botón «Consultar Hacienda» para autocompletar el nombre o razón social oficial sin inventar datos no retornados (teléfonos o correos).
- **Destinatario condicional en nueva solicitud:** en el modal de solicitud, al seleccionar el cliente, consultar de forma interactiva «¿Quién recibirá la mercancía?» con opciones «El mismo cliente» y «Otra persona autorizada». Para clientes físicos receptores, reutilizar automáticamente sus datos existentes; para empresas o terceros autorizados, desplegar campos de identificación primero con opción de consulta Hacienda, preservando la diferencia entre destinatario previsto y receptor final de la entrega.

## Alcance, exclusiones y riesgos

- **Alcance:** Servicio PHP de consulta a Hacienda, endpoint protegido para AJAX/HTMX, reordenamiento de campos de identificación en clientes y solicitudes, flujo interactivo de destinatario condicional en solicitudes, y pruebas unitarias con mocks.
- **No-alcance:** No utiliza credenciales ni certificados criptográficos de llave tributaria (el endpoint es público); no invoca la API externa real en suites automatizadas (utiliza respuestas simuladas); no asume que Hacienda verifica identidad biométrica; no altera el contrato de recepción real de entregas (`entregas.receptor_*`).
- **Riesgos y mitigación:**
  - *Indisponibilidad o rate limit de Hacienda:* si la API retorna 429, 5xx o timeout, la interfaz muestra un aviso discreto y permite la digitación manual sin bloquear el registro.
  - *Nombres no estructurados:* cuando Hacienda retorna una sola cadena («APELLIDO1 APELLIDO2 NOMBRE»), el servicio sugiere una distribución prudente pero permite siempre la corrección manual en campos editables.
- **Estrategia de reversión:** Cambios contenidos en archivos PHP de backend y vistas modales, sin cambios estructurales destructivos en SQL.

## Capabilities

### New Capabilities
- `identity-autofill-hacienda`: Consulta y autocompletado oficial mediante API de Hacienda de Costa Rica y flujo de destinatario esperado condicional en órdenes logísticas.

## Impact

Impacta `app/hacienda_service.php`, `public/api/hacienda.php`, `public/clientes/form.php`, `public/clientes/editar_form.php`, `public/solicitudes/form.php`, `public/solicitudes/guardar.php` y pruebas en `tests/`.
