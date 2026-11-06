# Diseño: mejoras operativas, ciclo de vida de viajes y consistencia UX

## 1. Arquitectura de presentación y modales

- **Estructura flexbox de modales:** En Bulma, `.modal-card` tiene `max-height: calc(100vh - 2.5rem)` y `display: flex`. Al contener un elemento `<form>` intermedio entre `.modal-card` y `.modal-card-body`, el formulario debe declararse con `display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; overflow: hidden;`. Con ello, `.modal-card-body` asume `flex: 1 1 auto; overflow-y: auto;` y `.modal-card-foot` permanece siempre visible y anclado al fondo con `flex-shrink: 0`.
- **Orden canónico de acciones:** Todo grupo de botones (`.buttons`) sitúa el botón de acción principal a la izquierda y el secundario («Cancelar») a la derecha, en concordancia estricta con el orden en el DOM y sin aplicar `flex-direction: row-reverse`.
- **Persistencia de sidebar:** En `app/layout.php`, un `<script>` síncrono previo al render del layout lee `localStorage.getItem('tssa_sidebar_collapsed') === '1'`. Si está activo, agrega la clase `preload-sidebar-collapsed` al elemento `<html>` y `is-sidebar-collapsed` a `#app-shell`. Durante el inicio de render, la clase `preload-no-transition` suprime transiciones CSS en `.sidebar` y `.app-shell`, removiéndose en el evento `DOMContentLoaded`.

## 2. Personalización de desplegables (Tom Select)

- **Filtros de estado en módulos:** Los selectores con ID o propósito de filtro en Solicitudes, Viajes, Entregas, Clientes, Usuarios, Flota y Reportes reciben atributos `data-combobox` y configuración adaptada.
- **Evitar colisión de flechas CSS:** Cuando Tom Select reemplaza un `<select>` contenido dentro de un contenedor `.select` de Bulma, la regla CSS `.select:has(.ts-wrapper)::after { display: none !important; }` oculta el chevron nativo de Bulma, dejando únicamente el indicador estilizado de Tom Select.
- **Desplegable abierto (`.ts-dropdown`):** Configurado con `dropdownParent: 'body'` para evitar recortes dentro de contenedores con `overflow: hidden`, respetando tokens `#1A2636`, bordes `#2B3B52`, sombreados elevados y soporte de teclado para navegación sin requerir escritura forzada.

## 3. Autogeneración atómica de códigos

- **Secuencias SQL:** En `TransportesSegurosSA_Test`, se crean secuencias independientes:
  ```sql
  CREATE SEQUENCE dbo.seq_codigo_solicitud AS INT START WITH 1 INCREMENT BY 1;
  CREATE SEQUENCE dbo.seq_codigo_viaje AS INT START WITH 1 INCREMENT BY 1;
  ```
  La secuencia se inicializa garantizando que el siguiente valor sea superior a cualquier sufijo numérico existente en los datos históricos.
- **Procedimientos actualizados:**
  - `sp_crear_solicitud`: si `@codigo` es `NULL` o cadena vacía, obtiene `NEXT VALUE FOR dbo.seq_codigo_solicitud` y formatea como `N'SOL-' + RIGHT(N'00000' + CAST(@seq AS NVARCHAR(10)), 5)`.
  - `sp_crear_viaje`: si `@codigo` es `NULL` o cadena vacía, obtiene `NEXT VALUE FOR dbo.seq_codigo_viaje` y formatea como `N'VIA-' + RIGHT(N'00000' + CAST(@seq AS NVARCHAR(10)), 5)`.
  - Si se proporciona un código explícito no vacío, se respeta para preservar compatibilidad con pruebas o cargas manuales.
- **Experiencia de usuario:** En los formularios modales de solicitud y viaje, el campo editable de código se sustituye por un campo bloqueado/informativo con texto «Código asignado al guardar» y descripción auxiliar.

## 4. Cancelación de viajes

- **Nuevo permiso:** Inserción en `dbo.permisos`:
  - `codigo`: `VIAJES_CANCELAR`
  - `nombre`: `Cancelar viajes`
  - `descripcion`: `Cancelar viajes planificados antes de su inicio y liberar recursos asociados`
- **Nuevo procedimiento `dbo.sp_cancelar_viaje`:**
  - Parámetros: `@viaje_id INT`, `@motivo NVARCHAR(500) = NULL`.
  - Valida permiso `VIAJES_CANCELAR` mediante `dbo.fn_tiene_permiso`.
  - Bloquea el viaje con `UPDLOCK, HOLDLOCK`. Verifica que el estado sea estrictamente `PLANIFICADO`. Si está en `EN_CURSO` o `FINALIZADO`, arroja error contractual 50330.
  - Actualiza `dbo.viajes` a estado `'CANCELADO'` y registra el motivo en `observaciones`.
  - Para todas las solicitudes vinculadas en `dbo.viaje_solicitudes`:
    - Actualiza `dbo.viaje_solicitudes.estado = 'RETIRADA'`.
    - Actualiza `dbo.solicitudes.estado = 'PENDIENTE_ASIGNACION'` y `fecha_actualizacion = SYSUTCDATETIME()`.
  - Marca paradas en estado `PENDIENTE` como `OMITIDA`.
  - Libera el vehículo (`estado = 'DISPONIBLE'`) y el conductor (`estado_operativo = 'DISPONIBLE'`).
  - Todo dentro de una transacción atómica protegida con `BEGIN TRY ... ROLLBACK`.
- **Interfaz de usuario:**
  - En la vista de detalle de viaje (`public/viajes/detalle.php`), si el viaje está en `PLANIFICADO` y el usuario tiene `VIAJES_CANCELAR`, se renderiza un botón «Cancelar viaje».
  - Al pulsarlo, se abre un modal de confirmación descriptivo con el motivo de cancelación opcional y botones `Confirmar cancelación | Volver`.
