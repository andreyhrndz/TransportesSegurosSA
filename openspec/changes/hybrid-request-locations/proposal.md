# Propuesta: Ubicaciones híbridas en solicitudes de transporte

## Por qué
Actualmente `Nueva solicitud` (`public/solicitudes/form.php`) obliga a pre-registrar cualquier punto en `Flota y catálogos > Ubicaciones` antes de poder crear una orden de transporte. Esto introduce fricción operativa para entregas o recogidas puntuales y contamina el catálogo de ubicaciones frecuentes con destinos irrepetibles.

## Qué cambia
1. **Esquema aditivo y contratos SQL (Migración 12):**
   - Agrega `es_reutilizable BIT NOT NULL CONSTRAINT DF_ubicaciones_es_reutilizable DEFAULT (1)` e índice no agrupado `IX_ubicaciones_reutilizable_activo_nombre` a `dbo.ubicaciones`, preservando intactos todos los registros preexistentes y claves foráneas (`origen_id`, `destino_id`).
   - Actualiza `dbo.sp_listar_ubicaciones` para excluir puntos puntuales (`es_reutilizable = 0`) por defecto, manteniendo catálogo y combobox limpios.
   - Agrega `dbo.sp_listar_ubicaciones_parada` para que la planificación de paradas en viajes pueda incluir ubicaciones puntuales vinculadas a solicitudes abiertas (`REGISTRADA`, `PENDIENTE_ASIGNACION`) sin contaminar el catálogo global.
   - Agrega `dbo.sp_crear_solicitud_con_ubicaciones` (transacción atómica única con `XACT_ABORT ON`, TVP `dbo.tipo_mercancias`, validación de permisos `SOLICITUDES_CREAR` vs `UBICACIONES_GESTIONAR` sin escalación, y comparación geográfica Haversine con tolerancia de 25 metros).
   - Preserva `dbo.sp_crear_solicitud` sin ruptura y expone `origen_es_reutilizable`/`destino_es_reutilizable` en `dbo.sp_obtener_solicitud` y `origen_id`/`destino_id` en `dbo.sp_listar_solicitudes`.
2. **Selector híbrido en `Nueva solicitud`:**
   - Origen y destino independientes: cada campo conserva su combobox Tom Select de ubicaciones guardadas y agrega `Elegir en el mapa` reutilizando Leaflet 1.9.4 + OSM ya vendorizados.
   - Panel interno in-place dentro del mismo modal (sin cerrar ni perder cliente, fecha, destinatario ni mercancías), con referencia humana obligatoria, alias opcional, opción opt-in `Guardar en ubicaciones frecuentes` desactivada por defecto (controlada por `UBICACIONES_GESTIONAR`), fallback offline a combobox/coordenadas avanzadas y jerarquía de tecla `Escape`.
3. **Consistencia visual de ubicaciones y enlaces a Google Maps:**
   - Elimina columnas numéricas crudas de coordenadas de la tabla ordinaria de `Flota > Ubicaciones`, conservando `Abrir en Google Maps` y el texto consistente `Sin punto registrado` cuando no existen coordenadas.

## Impacto
- **Base de datos:** Migración `12_hybrid_request_locations_contracts.sql` aplicada exclusivamente en `TransportesSegurosSA_Test`. `TransportesSegurosSA` original y `TransportesSegurosSA_DW` no se tocan; despliegue ordenado `10 → 11 → 12` documentado para autorización manual del propietario.
- **Seguridad:** Cero escalación de privilegios (`SOLICITUDES_CREAR` solo crea puntos puntuales dentro de una solicitud válida; `guardar_frecuente=1` exige `UBICACIONES_GESTIONAR` en PHP y SQL), validación de coordenadas, CSRF, parametrización y rollback atómico sin registros huérfanos.
