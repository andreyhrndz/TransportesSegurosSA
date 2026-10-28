## Purpose

Define acceso seguro y predecible para la aplicación PHP sin convertir el contexto SQL en un mecanismo de autenticación.

## ADDED Requirements

### Requirement: Identidad de sesión validada
El sistema SHALL permitir acceso sólo con una sesión PHP que corresponda a un usuario activo y SHALL regenerar el identificador de sesión al autenticar.

#### Scenario: Sesión inexistente
- **WHEN** una persona solicita una página protegida sin sesión válida
- **THEN** el sistema redirige al inicio de sesión o entrega la respuesta HTMX equivalente sin exponer datos.

### Requirement: Autorización por permiso activo
El sistema SHALL comprobar el permiso individual activo antes de cada capacidad protegida y SHALL derivar `SESSION_CONTEXT('usuario_id')` únicamente de esa sesión validada.

#### Scenario: Permiso ausente
- **WHEN** una persona autenticada intenta una acción sin el permiso requerido
- **THEN** recibe una respuesta 403 comprensible y la acción no llega al contrato de escritura SQL.

### Requirement: Protección de acciones y errores
El sistema SHALL exigir CSRF en toda acción que cambie estado, invalidar la sesión al cerrar y comunicar fallos sin datos técnicos sensibles.

#### Scenario: Token inválido en HTMX
- **WHEN** un fragmento HTMX envía un token CSRF inválido
- **THEN** el sistema rechaza la acción, conserva la integridad de los datos y muestra un mensaje accionable.
