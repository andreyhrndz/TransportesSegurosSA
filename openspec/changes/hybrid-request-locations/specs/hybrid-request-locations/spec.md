# Especificación delta: Ubicaciones híbridas en solicitudes

## ADDED Requirements

### Requirement: Selección híbrida independiente de origen y destino en Nueva solicitud
El formulario `public/solicitudes/form.php` y el endpoint `public/solicitudes/guardar.php` SHALL permitir seleccionar origen y destino de forma independiente mediante ubicación frecuente guardada (combobox Tom Select con búsqueda remota) o mediante punto marcado directamente en el mapa Leaflet/OpenStreetMap compartido dentro del mismo modal.

#### Scenario: Combinación híbrida punto puntual en mapa y ubicación guardada
- **WHEN** un operador con permiso `SOLICITUDES_CREAR` selecciona un punto de origen en el mapa con referencia humana obligatoria (sin marcar `Guardar en ubicaciones frecuentes`) y selecciona una ubicación frecuente guardada como destino
- **THEN** el sistema registra atómicamente el punto puntual con `es_reutilizable = 0` y la solicitud vinculada mediante `origen_id` y `destino_id`, sin mostrar el punto puntual en `Flota > Ubicaciones` ni en el combobox de ubicaciones guardadas

#### Scenario: Opción opt-in de guardado en ubicaciones frecuentes con control de permisos
- **WHEN** un usuario con `SOLICITUDES_CREAR` y `UBICACIONES_GESTIONAR` marca `Guardar en ubicaciones frecuentes` e ingresa un nombre/alias válido para un punto marcado en el mapa
- **THEN** el punto se registra con `es_reutilizable = 1` y queda disponible en catálogo y comboboxes de ubicaciones frecuentes
- **AND WHEN** un usuario sin `UBICACIONES_GESTIONAR` intenta enviar `origen_guardar_frecuente=1` o `destino_guardar_frecuente=1`
- **THEN** el servidor rechaza la operación con HTTP 403 y código SQL 50233 sin crear ni la ubicación ni la solicitud

#### Scenario: Conservación del estado del modal y alternancia limpia de modos
- **WHEN** el usuario completa cliente, fecha, destinatario y mercancías, abre el panel del mapa para origen o destino, confirma un punto y luego alterna a ubicación guardada o cancela con `Escape`
- **THEN** el modal principal permanece abierto sin perder los demás campos ni el scroll bloqueado del fondo, y el POST nunca mezcla ambos modos activos para el mismo extremo

#### Scenario: Validación geográfica de origen y destino distintos y rollback atómico
- **WHEN** origen y destino coinciden por identificador o su distancia geográfica Haversine es menor o igual a 25 metros, o falla una validación de mercancías/cliente/BD
- **THEN** el procedimiento `dbo.sp_crear_solicitud_con_ubicaciones` rechaza la operación (50204 o 50234) y revierte completamente la transacción sin dejar registros huérfanos en `dbo.ubicaciones`

#### Scenario: Planificación de paradas en viajes para puntos puntuales y enlaces Google Maps
- **WHEN** una solicitud con punto puntual (`es_reutilizable = 0`) está abierta (`REGISTRADA` o `PENDIENTE_ASIGNACION`)
- **THEN** `dbo.sp_listar_ubicaciones_parada` permite agregar esa ubicación como parada en un viaje planificado, y el detalle de solicitud y de viaje muestran nombre, referencia y enlace `Abrir en Google Maps` (o `Sin punto registrado` cuando faltan coordenadas)

#### Scenario: Fallo de carga de tiles del mapa sin bloqueo operativo
- **WHEN** los tiles de OpenStreetMap o Leaflet no pueden cargarse en el modal de `Nueva solicitud`
- **THEN** la interfaz muestra un aviso claro, ofrece volver al combobox de ubicaciones guardadas y despliega la entrada manual avanzada de coordenadas sin bloquear la creación de solicitudes tradicionales

#### Scenario: Tabla ordinaria de ubicaciones sin columna numérica de coordenadas
- **WHEN** un operador consulta `Flota y catálogos > Ubicaciones`
- **THEN** la tabla muestra nombre, dirección, enlace `Abrir en Google Maps` (o `Sin punto registrado`) y estado, sin exponer columnas numéricas crudas de latitud/longitud en el listado general
