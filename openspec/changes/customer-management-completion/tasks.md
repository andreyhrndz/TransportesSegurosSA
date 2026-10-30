## 1. Integración de contratos

- [x] 1.1 Confirmar despliegue y firmas de lectura, edición y desactivación (lectura desplegada; edición y desactivación ausentes).
- [ ] 1.2 Aplicar guards `CLIENTES_VER`, `CLIENTES_CREAR`, `CLIENTES_EDITAR` y `CLIENTES_DESACTIVAR` según capacidad.
- [ ] 1.3 Adaptar consultas y errores SQL a contratos parametrizados.

## 2. Interfaz

- [x] 2.1 Implementar directorio, filtros, paginación y estado vacío con componentes reutilizables.
- [x] 2.2 Implementar detalle y formularios por subtipo sin conversión de tipo.
- [ ] 2.3 Implementar confirmación y refresco HTMX para desactivación.

## 3. Verificación

- [ ] 3.1 Probar autorizaciones, CSRF, duplicados, conflictos y escape HTML.
- [ ] 3.2 Probar escritorio, tablet, móvil, teclado y lector de pantalla básico.
- [ ] 3.3 Ejecutar smoke de login/dashboard/clientes y validación estricta.

## Evidencia y límites

- El directorio y `public/clientes/detalle.php` consumen exclusivamente `sp_listar_clientes` y
  `sp_obtener_cliente` con `CLIENTES_VER`; las altas conservan los dos procedimientos existentes
  y siguen exigiendo `CLIENTES_CREAR`.
- No hay controles de edición ni desactivación visibles. Su contrato está preparado pero no fue
  aprobado ni desplegado, por lo que las tareas de guards y mutaciones quedan pendientes.
