## 1. Base de módulos

- [x] 1.1 Confirmar contratos SQL y permisos de lectura/escritura por entidad.
- [x] 1.2 Crear navegación que muestre sólo módulos autorizados e implementados.
- [x] 1.3 Crear listas, filtros y estados vacío/error/carga reutilizables.

## 2. Gestión por dominio

- [x] 2.1 Implementar ubicaciones y catálogos activos.
- [x] 2.2 Implementar vehículos y conductores con estado/disponibilidad de sólo lectura donde aplique.
- [x] 2.3 Implementar rutas con ubicaciones, distancia y duración validadas.

## 3. Verificación

- [ ] 3.1 Probar permisos, CSRF, validaciones y conflictos de dependencias.
- [ ] 3.2 Probar selectores de solicitudes/viajes con sólo registros activos.
- [ ] 3.3 Probar responsive, teclado y validación estricta.

## Evidencia y limitaciones

- `public/flota.php` y su fragmento HTMX consumen los listados autorizados de vehículos,
  conductores, rutas, ubicaciones y los tres catálogos en Test. La navegación sólo aparece con
  `FLOTAS_VER`, `RUTAS_VER`, `UBICACIONES_VER` o `CATALOGOS_VER`.
- No se habilitaron alta, edición ni desactivación: `sql-master-data-contracts` continúa pendiente
  de decisiones de negocio y contratos de escritura aprobados.
