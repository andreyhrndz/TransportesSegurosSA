# Tareas de implementación — `operational-ux-and-trip-lifecycle`

## 1. Fase UX: Modales, filtros y sidebar

- [x] 1.1 Corregir reglas CSS de `.modal-card` y `<form>` en `public/assets/css/estilos.css` para scroll interno y footer fijo visible en 1366×768.
- [x] 1.2 Actualizar el botón de acción principal a «Crear solicitud» a la izquierda de «Cancelar» en `public/solicitudes/form.php` y revisar consistencia transversal en modales.
- [x] 1.3 Personalizar filtros desplegables con Tom Select en `solicitudes.php`, `viajes.php`, `entregas.php`, `clientes.php`, `usuarios.php`, `flota.php` y `reportes.php`, asegurando estilos oscuros y sin doble flecha.
- [x] 1.4 Eliminar el parpadeo de apertura del sidebar mediante script de inicialización inmediata en `app/layout.php` y supresión temporal de transiciones.

## 2. Fase Logística y SQL: Códigos autogenerados y cancelación de viajes

- [x] 2.1 Crear secuencias y lógica de autogeneración de códigos en `TransportesSegurosSA_Test` para `sp_crear_solicitud` (`SOL-#####`) y `sp_crear_viaje` (`VIA-#####`).
- [x] 2.2 Actualizar los formularios modales de solicitud y viaje para mostrar «Código asignado al guardar» como indicativo de solo lectura.
- [x] 2.3 Crear permiso `VIAJES_CANCELAR` y procedimiento `sp_cancelar_viaje` en `TransportesSegurosSA_Test`, liberando recursos de viajes `PLANIFICADO`.
- [x] 2.4 Integrar botón y modal de cancelación de viaje en `public/viajes/detalle.php` y controlador `public/viajes/cancelar.php`.
- [x] 2.5 Mejorar la interfaz de asignación de paradas/solicitudes con validación anticipada y filtros compatibles de origen/destino.

## 3. Pruebas y verificación

- [x] 3.1 Crear y ejecutar suite de integración PHP para generación automática de códigos y cancelación de viajes con rollback.
- [x] 3.2 Verificar visualmente con Playwright modales con scroll, opciones abiertas de selectores y persistencia de sidebar en 1366×768 y 1440×900.
