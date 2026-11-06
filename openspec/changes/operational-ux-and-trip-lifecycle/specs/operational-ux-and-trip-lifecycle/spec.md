# Delta: mejoras operativas, ciclo de vida de viajes y consistencia UX

## Purpose

Definir los requisitos verificables y escenarios de prueba para la visualización de modales con footer fijo, generación automática de códigos de solicitud y viaje, personalización integral de desplegables con Tom Select, cancelación segura de viajes y persistencia del sidebar sin parpadeos.

## ADDED Requirements

### Requirement: Layout y footer permanente en modales con scroll interno
Los modales de la aplicación SHALL mantener el encabezado y el pie de acciones (`.modal-card-foot`) siempre visibles dentro del viewport en cualquier resolución (`>= 1366x768`), delegando el desplazamiento al cuerpo del formulario (`.modal-card-body`).

#### Scenario: Visualización de modal de nueva solicitud con múltiples mercancías
- **GIVEN** una persona autenticada con permiso `SOLICITUDES_CREAR` en pantalla de 1366×768
- **WHEN** abre el modal de «Nueva solicitud» y agrega múltiples líneas de mercancía
- **THEN** el botón «Crear solicitud» y el botón «Cancelar» permanecen visibles y accesibles en la parte inferior de la ventana
- **AND** el botón «Crear solicitud» se ubica a la izquierda del botón «Cancelar».

### Requirement: Autogeneración atómica de códigos de solicitud y viaje
El sistema SHALL permitir crear solicitudes y viajes sin que el usuario digite manualmente un código, asignando consecutivamente códigos prefijados (`SOL-#####`, `VIA-#####`) garantizados contra concurrencia.

#### Scenario: Creación de solicitud con código automático
- **GIVEN** el formulario de creación de solicitud
- **WHEN** el usuario visualiza el campo de código
- **THEN** observa un indicador informativo «Código asignado al guardar»
- **AND** al registrar con éxito la solicitud en el backend, se genera un código único con prefijo `SOL-` sin colisión concurrente.

#### Scenario: Creación de viaje con código automático
- **GIVEN** el formulario de creación de viaje
- **WHEN** el usuario registra un nuevo viaje sin especificar código manual
- **THEN** el procedimiento asigna un código consecutivo con prefijo `VIA-` y almacena el registro en estado `PLANIFICADO`.

### Requirement: Desplegables personalizados con Dark Logistics Premium
Todos los filtros de selección en las vistas principales SHALL presentar apariencia coherente con el tema oscuro tanto cerrados como con la lista desplegada, sin chevrones duplicados y con soporte de teclado.

#### Scenario: Interacción con filtro de estado en Solicitudes
- **GIVEN** la pantalla de listado de Solicitudes
- **WHEN** el operador despliega el selector de estado
- **THEN** las opciones se renderizan con fondo oscuro, borde visible y resaltado al pasar el ratón o navegar con flechas
- **AND** la selección de una opción dispara la actualización HTMX de la tabla sin recargar la página.

### Requirement: Cancelación atómica y segura de viajes planificados
El sistema SHALL permitir la cancelación de viajes únicamente cuando se encuentren en estado `PLANIFICADO`, revirtiendo asignaciones y liberando recursos.

#### Scenario: Cancelación exitosa de un viaje planificado
- **GIVEN** un viaje en estado `PLANIFICADO` con vehículo y conductor asignados y una solicitud vinculada
- **WHEN** un operador con permiso `VIAJES_CANCELAR` ejecuta la cancelación del viaje
- **THEN** el estado del viaje pasa a `CANCELADO`
- **AND** el vehículo y el conductor retornan a estado `DISPONIBLE`
- **AND** la solicitud asociada retorna a estado `PENDIENTE_ASIGNACION`.

#### Scenario: Rechazo de cancelación de viaje en curso o finalizado
- **GIVEN** un viaje en estado `EN_CURSO` o `FINALIZADO`
- **WHEN** se intenta ejecutar `sp_cancelar_viaje`
- **THEN** la base de datos arroja error 50330 y no modifica el estado ni los recursos.

### Requirement: Persistencia inmediata de sidebar colapsado
El estado de la barra lateral SHALL conservarse de forma inmediata entre navegaciones sin transiciones o expansiones accidentales.

#### Scenario: Navegación repetida con sidebar contraído
- **GIVEN** un usuario en resolución de escritorio que contrae la barra lateral
- **WHEN** navega consecutivamente por 5 secciones del sistema o recarga la página
- **THEN** la barra lateral permanece contraída desde el primer cuadro de renderizado sin animación de apertura.
