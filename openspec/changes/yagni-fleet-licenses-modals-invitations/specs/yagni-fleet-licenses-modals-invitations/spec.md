# Delta: cierre YAGNI de flota, licencias, selectores, modales e invitaciones

## Purpose

Establecer los contratos observables de interfaz, negocio, persistencia y seguridad para el registro simplificado de conductores y vehículos, la consistencia visual de selectores Tom Select, el cierre controlado de modales ante éxito confirmado y la gestión revocable/cancelable de invitaciones.

## ADDED Requirements

### Requirement: Licencia única visible con limpieza de vencimiento en conductores
El formulario de conductor SHALL presentar únicamente un campo de número de licencia, un único combobox Tom Select de tipo de licencia basado en las categorías activas de `dbo.cat_licencias` y un único selector de fecha de vencimiento, sin tabla multicategoría ni columna de verificación externa.

#### Scenario: Alta de conductor sin categoría por defecto
- **GIVEN** un operador con permiso `FLOTAS_GESTIONAR`
- **WHEN** abre el modal «Nuevo conductor»
- **THEN** el combobox de tipo de licencia inicia vacío sin preseleccionar `B4` ni otra categoría
- **AND** la fecha de vencimiento inicia vacía
- **AND** intentar guardar sin categoría o sin fecha vigente bloquea el guardado con error cercano al campo y mantiene el modal abierto sin borrar los datos ingresados.

#### Scenario: Cambio de categoría limpia vencimiento anterior
- **GIVEN** un conductor abierto en edición o creación con categoría `B2` y fecha de vencimiento ingresada
- **WHEN** el usuario cambia el tipo de licencia a `B1` mediante ratón, teclado o API del componente
- **THEN** el campo de fecha de vencimiento se limpia de inmediato
- **AND** no se permite guardar hasta ingresar una fecha de vencimiento vigente
- **AND** volver a seleccionar la misma categoría sin cambio real conserva la fecha ingresada.

#### Scenario: Resolución explícita de conductor con múltiples categorías heredadas
- **GIVEN** un conductor que posee más de una fila en `dbo.conductores_licencias`
- **WHEN** un operador autorizado abre el modal de edición
- **THEN** se muestra una advertencia discreta indicando que posee varias categorías heredadas y solicitando elegir la categoría principal
- **AND** ninguna categoría se selecciona arbitrariamente ni se promueven filas `PENDIENTE` o `RECHAZADA` silenciosamente
- **AND** al guardar una categoría explícita, el contrato atómico sincroniza `dbo.conductores` y `dbo.conductores_licencias`, registra el historial previo en `dbo.auditorias` y garantiza que ninguna categoría antigua oculta habilite viajes incompatibles.

#### Scenario: Rechazo de modificación de licencia en conductor con viaje activo
- **GIVEN** un conductor en estado `ASIGNADO` o vinculado a un viaje `PLANIFICADO` o `EN_CURSO`
- **WHEN** se intenta abrir edición o ejecutar `dbo.sp_actualizar_licencia_principal_conductor`
- **THEN** el sistema rechaza la modificación con error de conflicto (`50505` / HTTP 409) preservando la habilitación vigente del viaje activo.

### Requirement: Vehículos simplificados con PMA coherente y preservación de datos internos
El formulario y listado de vehículos SHALL concentrarse en los datos operativos esenciales (incluido PMA, carga útil, clasificación legal y condición articulada), retirando odómetro y selector ordinario de homologación de la UI sin destruir ni promover silenciosamente los valores almacenados en base de datos.

#### Scenario: Alta y edición de vehículo sin odómetro ni selector de homologación
- **GIVEN** un operador con permiso `FLOTAS_GESTIONAR`
- **WHEN** abre el formulario de vehículo nuevo o existente
- **THEN** visualiza un único texto breve de ayuda que distingue carga útil y PMA
- **AND** los selectores de clasificación legal y estado operativo utilizan el componente personalizado Tom Select coherente con la plataforma
- **AND** no se muestra el campo de kilometraje/odómetro ni el selector ordinario de homologación
- **AND** al editar un vehículo existente sin enviar kilometraje, se conserva intacto el kilometraje previo en base de datos.

#### Scenario: Preservación estricta de vehículos bloqueados como NO_CONFORME
- **GIVEN** un vehículo registrado con `estado_homologacion = 'NO_CONFORME'`
- **WHEN** un operador edita atributos ordinarios del vehículo (marca, modelo, capacidad, PMA o estado operativo)
- **THEN** el modal muestra un aviso operativo claro del bloqueo
- **AND** guardar cambios ordinarios conserva `estado_homologacion = 'NO_CONFORME'` sin promoverlo silenciosamente
- **AND** `dbo.fn_conductor_habilitado_vehiculo` continúa impidiendo asignar el vehículo a viajes salvo levantamiento administrativo explícito auditado.

#### Scenario: Validación de coherencia entre carga útil, PMA, clase legal y condición articulada
- **GIVEN** un formulario de alta o edición de vehículo
- **WHEN** se envía carga útil mayor que PMA, o un vehículo marcado como articulado con clase distinta a `PESADO_ARTICULADO`, o una clase rígida contradictoria con el rango de PMA
- **THEN** el servidor rechaza el guardado con HTTP 422 y mensaje claro manteniendo el modal abierto y los datos intactos.

### Requirement: Cierre global de modales únicamente tras éxito confirmado
Todos los modales del sistema SHALL permanecer abiertos durante el envío y ante cualquier error HTTP o semántico, cerrándose únicamente cuando el servidor confirme el éxito de la operación.

#### Scenario: Error de validación, permisos o servidor en cualquier modal
- **GIVEN** cualquier modal con formulario HTMX abierto
- **WHEN** el envío responde 403, 404, 409, 422, 500, 503 o fallo de red
- **THEN** el modal permanece abierto, los valores ingresados se conservan, el botón de envío vuelve a habilitarse y el mensaje de error recibe foco visible
- **AND** pulsar `Escape` con un desplegable Tom Select o calendario Flatpickr abierto cierra primero el control flotante sin cerrar el modal.

#### Scenario: Cierre automático en invitaciones exitosas y resguardo ante fallo de Brevo
- **GIVEN** el modal «Invitar usuario»
- **WHEN** Brevo acepta el envío (HTTP 2xx) o el transporte opera en modo `fake`
- **THEN** el modal se cierra automáticamente tras confirmarse la respuesta, muestra un toast preciso (`Invitación enviada` aclarando aceptación por proveedor, o `Invitación registrada en modo prueba` en modo `fake`) y refresca los listados
- **AND** cuando la invitación se guarda pero el despacho de Brevo falla (`FALLIDO`), el modal permanece abierto con advertencia clara, deshabilita el reenvío ciego del alta y ofrece acción explícita de reenvío sobre el registro creado.

### Requirement: Invitaciones revocables y cancelables sin pérdida de historial
El listado de invitaciones SHALL reflejar el estado efectivo y fecha real de expiración, permitiendo revocar invitaciones vigentes y cancelar/descartar invitaciones expiradas con auditoría, protegiendo las completadas y conservando el historial.

#### Scenario: Revocación de invitación vigente y cancelación de invitación expirada
- **GIVEN** un administrador con `USUARIOS_GESTIONAR_CUENTAS` en la pestaña «Invitaciones»
- **WHEN** revoca una invitación `PENDIENTE` vigente
- **THEN** pasa a estado `REVOCADA`, su token queda inválido de inmediato y la tabla se actualiza vía HTMX con toast de confirmación
- **AND** cuando descarta/cancela una invitación `EXPIRADA` no completada, pasa a estado `CANCELADA` con auditoría específica sin borrar el registro
- **AND** una invitación `COMPLETADA` nunca ofrece revocación ni puede revocarse por POST directo (HTTP 409)
- **AND** cualquier error (CSRF, permisos, cooldown o conflicto) muestra alerta sin destruir la tabla de invitaciones.
