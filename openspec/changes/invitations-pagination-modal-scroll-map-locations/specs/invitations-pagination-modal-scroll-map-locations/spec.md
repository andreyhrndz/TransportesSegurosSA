# Especificación Delta: Invitaciones paginadas, corrección de correo, scroll modal global y ubicaciones en mapa

## ADDED Requirements

### Requirement: Búsqueda, filtro de estado efectivo y paginación real SQL en invitaciones
El módulo de invitaciones DEBE filtrar y paginar los registros directamente en SQL Server mediante `dbo.sp_listar_invitaciones_paginadas` antes de transferir resultados a PHP o al navegador, evaluando el estado efectivo en UTC (`PENDIENTE` con `fecha_expiracion <= SYSUTCDATETIME()` se trata como `EXPIRADA`).

#### Scenario: Filtrar y paginar más de 20 invitaciones en servidor
- **WHEN** existen al menos 25 invitaciones en `TransportesSegurosSA_Test` y un usuario con `USUARIOS_VER` filtra por fragmento de correo y estado efectivo
- **THEN** SQL Server devuelve como máximo 20 filas por página con `@total_registros` exacto y orden `fecha_creacion DESC, invitacion_id DESC`
- **AND** los controles Anterior/Siguiente y la recarga HTMX tras mutaciones preservan los filtros y ajustan la página si la última fila desaparece.

#### Scenario: Presentación visual YAGNI y acciones mínimas por estado
- **WHEN** se renderiza la tabla de invitaciones
- **THEN** cada fila muestra exactamente una insignia principal (`status_tag` del estado efectivo) y el estado de entrega como texto secundario compacto
- **AND** `PENDIENTE` vigente muestra solo **Reenviar** y **Revocar**, `EXPIRADA`/`REVOCADA`/`CANCELADA` muestran solo **Reenviar**, y `COMPLETADA` no muestra acciones
- **AND** no existe botón «Cancelar», «Eliminar», «Archivar» ni «Editar correo» separado.

### Requirement: Modal único de reenvío con confirmación o corrección atómica de correo
Todo botón «Reenviar» DEBE abrir un modal con el correo actual prellenado editable y confirmación explícita, permitiendo reenviar al mismo correo o corregir el correo destinatario en una transacción atómica segura.

#### Scenario: Corrección de correo destinatario invalidando enlace previo
- **WHEN** un usuario con `USUARIOS_GESTIONAR_CUENTAS` abre el modal «Reenviar», cambia el correo a una dirección válida sin cuenta activa ni invitación vigente y confirma
- **THEN** `dbo.sp_reenviar_invitacion_usuario` serializa la operación con bloqueo transaccional, invalida el token previo, conserva inmutable el registro histórico original con su correo anterior, crea una nueva invitación para el nuevo correo copiando solo permisos autorizados que el actor posee, registra auditoría `RESEND_CORRECT_EMAIL` y respeta límites de 60 s y 5 envíos/24 h sin permitir bypass
- **AND** el enlace anterior queda permanentemente rechazado en onboarding.

#### Scenario: Fallo del proveedor Brevo o error de validación
- **WHEN** el correo es inválido, pertenece a una cuenta activa, supera los límites o Brevo rechaza el envío
- **THEN** el modal permanece abierto conservando el valor ingresado, muestra el error o el estado `Fallido` con control seguro de reintento y no crea cuentas ni invitaciones duplicadas.

### Requirement: Bloqueo global de scroll de fondo en modales y posicionamiento inteligente de desplegables
Al abrir cualquier modal HTMX en cualquier módulo de la aplicación, el documento de fondo DEBE quedar inmovilizado sin desplazamiento accidental, manteniendo desplazamiento interno fluido en `.modal-card-body`, `.ts-dropdown-content`, Flatpickr y Leaflet.

#### Scenario: Desplazamiento de «Tipo de mercancía» en «Nueva solicitud» y modales de Flota
- **WHEN** el usuario abre «Nueva solicitud» o cualquier modal de «Flota y catálogos» con la página desplazada, despliega un combobox Tom Select (incluyendo uno próximo al borde inferior del viewport) y gira la rueda/trackpad sobre la lista o el modal
- **THEN** `window.scrollY` del fondo permanece inalterado (`delta === 0`)
- **AND** el desplegable calcula `max-height` disponible y realiza flip superior si no cabe debajo del control, permitiendo desplazar y seleccionar hasta la última opción
- **AND** al pulsar `Escape` por primera vez se cierra solo el desplegable y al segundo `Escape` (o botón cerrar/backdrop/éxito HTMX) se cierra el modal y se restaura exactamente el scroll y el foco.

### Requirement: Selección interactiva de ubicaciones en mapa Leaflet y enlaces externos a Google Maps
El formulario de ubicaciones (`entidad=ubicacion`) DEBE ofrecer un mapa interactivo basado en Leaflet (vendoreado localmente) y OpenStreetMap con alternativa manual sincronizada, y las vistas de ubicaciones, detalle de solicitud y paradas de viaje DEBEN mostrar enlaces «Abrir en Google Maps» únicamente cuando existan coordenadas válidas.

#### Scenario: Alta y edición de ubicación desde mapa con fallback manual
- **WHEN** un usuario con `UBICACIONES_GESTIONAR` crea o edita una ubicación
- **THEN** puede hacer clic o arrastrar el marcador para rellenar automáticamente `latitud` y `longitud` (6 decimales) sin inventar ni sobreescribir la dirección textual, o desplegar «Ingresar coordenadas manualmente» para editar los mismos campos sincronizados con el marcador
- **AND** si no selecciona punto, las coordenadas se guardan como `NULL` (nunca `0,0`)
- **AND** si fallan los tiles o el script del mapa, se muestra un aviso discreto y se abre automáticamente el panel manual sin bloquear el guardado.

#### Scenario: Enlaces «Abrir en Google Maps» seguros
- **WHEN** una ubicación en flota, origen/destino de solicitud o parada de viaje posee `latitud` y `longitud` válidas
- **THEN** se renderiza un enlace secundario con icono `map-pin` hacia `https://www.google.com/maps/search/?api=1&query=<lat>,<lon>` construido y validado en servidor, con `target="_blank" rel="noopener noreferrer"`
- **AND** cuando faltan coordenadas, no se genera enlace inventado y se muestra «Sin punto en el mapa».
