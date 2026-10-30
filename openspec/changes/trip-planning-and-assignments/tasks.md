## 1. Lectura y preparación

- [x] 1.1 Confirmar contratos de lectura para viajes, flota, rutas, paradas y solicitudes elegibles.
- [x] 1.2 Implementar lista/detalle de viaje con capacidad, paradas y asignaciones.
- [x] 1.3 Aplicar permisos `VIAJES_*` por acción y ocultar acciones no disponibles.

## 2. Flujo de viaje

- [x] 2.1 Implementar creación con vehículo, conductor, ruta y fecha programada.
- [x] 2.2 Implementar paradas ordenadas y asignación de solicitudes con paradas compatibles.
- [x] 2.3 Implementar inicio y finalización mediante sus SP, con confirmación y refresco HTMX.

## 3. Verificación

- [ ] 3.1 Probar capacidad, licencia, disponibilidad, paradas inválidas y asignación duplicada.
- [ ] 3.2 Probar viaje vacío, finalización con entregas pendientes, CSRF y permisos.
- [ ] 3.3 Probar responsive/teclado y validar estrictamente.

## Evidencia y limitaciones

- La lista y el detalle en `public/viajes/` consumen `sp_listar_viajes` y
  `sp_obtener_viaje` desplegados en Test; muestran recursos, paradas y solicitudes asociadas.
- El contrato no devuelve un agregado de capacidad confiable por viaje, por lo que no se muestra
  un indicador estimado. Las operaciones habilitadas delegan capacidad, disponibilidad y estado
  al procedimiento SQL.

## Evidencia de implementación local

- `public/viajes/` integra creación, paradas, asignación, inicio y finalización con un guard por
  permiso; el procedimiento SQL conserva la autoridad de disponibilidad, capacidad y paradas.
- La prueba transaccional PDO crea el viaje, agrega paradas compatibles, asigna una solicitud,
  inicia, registra un resultado terminal y finaliza; todos los datos se revierten en Test.
- Las matrices exhaustivas de capacidad/licencia/concurrencia y revisión responsive siguen pendientes.
