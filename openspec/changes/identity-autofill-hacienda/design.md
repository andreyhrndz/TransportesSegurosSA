# Diseño: consulta a Hacienda y destinatario condicional en solicitudes

## 1. Servicio PHP de consulta a Hacienda (`app/hacienda_service.php`)

- **Contrato de consulta:**
  - Función `consultar_hacienda_identificacion(string $cedula): array`
  - Normaliza la cédula eliminando guiones y espacios.
  - Valida longitud y formato numérico (9 para físicas, 10 para jurídicas, 11-12 para DIMEX, 10 para NITE). Si el formato es inválido, retorna estado `'invalido'` sin realizar llamada de red.
  - Consulta `https://api.hacienda.go.cr/fe/ae?identificacion={cedula}` mediante cURL HTTPS.
  - Parámetros de conexión: `CURLOPT_TIMEOUT => 5`, `CURLOPT_SSL_VERIFYPEER => true`, `CURLOPT_RETURNTRANSFER => true`.
  - Tratamiento de respuestas HTTP:
    - `200`: decodifica JSON y extrae `nombre` y `tipoIdentificacion`. Retorna `['status' => 'encontrado', 'nombre' => $nombre, 'tipo' => $tipo]`.
    - `404`: retorna `['status' => 'no_encontrado', 'mensaje' => 'No se encontró la identificación en el registro de Hacienda.']`.
    - `429`: retorna `['status' => 'limite_excedido', 'mensaje' => 'Servicio temporalmente saturado; ingrese los datos manualmente.']`.
    - Otros / Error de red / Timeout: retorna `['status' => 'no_disponible', 'mensaje' => 'No fue posible conectar con Hacienda; continúe manualmente.']`.
  - Caché efímera local para evitar llamadas duplicadas en la misma sesión o peticiones sucesivas.

## 2. Endpoint seguro para la interfaz (`public/api/hacienda.php`)

- Exclusivo para peticiones autenticadas o con token de invitación válido.
- Responde JSON con cabecera `Content-Type: application/json; charset=utf-8`.
- Verifica CSRF en peticiones internas.

## 3. Destinatario condicional en nueva solicitud

- Al seleccionar un cliente en `public/solicitudes/form.php`:
  - Se consulta si el cliente es físico o jurídico (a través de los atributos o endpoint de detalle del cliente).
  - Selector por radio/botones interactivos:
    - Para cliente físico: opción predeterminada «El mismo cliente (recepción propia)» y opción «Otra persona autorizada».
    - Para cliente jurídico: la opción predeterminada exige persona de contacto («Persona física autorizada»).
  - Si se elige recepción propia del cliente físico:
    - Los campos de destinatario esperado se rellenan automáticamente con los nombres, apellidos, cédula y teléfono del cliente y se muestran en modo informativo/deshabilitado.
  - Si se elige otra persona o cliente jurídico:
    - Se muestran los campos con la identificación en primer lugar, acompañada del botón «Consultar Hacienda», permitiendo completar nombres y apellidos rápidamente.
- La validación del backend en `public/solicitudes/guardar.php` garantiza que todos los campos requeridos por el procedimiento almacenado (`destinatario_nombre`, `destinatario_apellido1`, `destinatario_apellido2`, etc.) viajen con valores válidos y no nulos.
