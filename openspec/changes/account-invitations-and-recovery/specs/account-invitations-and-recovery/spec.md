# Delta: invitaciones de autoservicio por correo y recuperación de contraseñas

## Purpose

Definir los requisitos observables y verificables para el flujo de invitación de usuarios mediante correo electrónico exclusivo, alta autoservicio por token seguro, y restablecimiento de contraseñas olvidadas.

## ADDED Requirements

### Requirement: Alta administrativa mediante único campo de correo electrónico
La acción de alta de usuario por parte de la administración SHALL requerir únicamente la dirección de correo electrónico, sin solicitar nombres, usuario ni contraseñas.

#### Scenario: Envío de invitación por el administrador
- **GIVEN** un administrador con permiso `USUARIOS_GESTIONAR_CUENTAS`
- **WHEN** abre el modal «Invitar usuario»
- **THEN** el único campo visible es «Correo electrónico»
- **AND** los botones son `Enviar invitación` a la izquierda y `Cancelar` a la derecha
- **AND** al enviar un correo válido no registrado, se genera una invitación con estado `PENDIENTE` y un token de 24 horas de vigencia.

### Requirement: Onboarding autoservicio del usuario invitado
El usuario que recibe la invitación SHALL completar sus propios datos y contraseña mediante un enlace seguro de un solo uso.

#### Scenario: Completar alta desde el enlace de invitación
- **GIVEN** un enlace válido con token de invitación
- **WHEN** el usuario accede a la pantalla de onboarding
- **THEN** visualiza su correo en modo de solo lectura
- **AND** registra su identificación (primero, con opción de consultar Hacienda), nombre, apellidos, nombre de usuario y su propia contraseña (mínimo 12 caracteres)
- **AND** al guardar, su cuenta se activa atómicamente en `dbo.usuarios` con cero permisos asignados.

### Requirement: Recuperación de contraseña «¿Olvidaste tu contraseña?»
El sistema SHALL ofrecer un mecanismo seguro de restablecimiento de contraseña mediante correo electrónico sin revelar la existencia previa de la cuenta.

#### Scenario: Solicitud pública de restablecimiento
- **GIVEN** la pantalla de inicio de sesión
- **WHEN** el usuario hace clic en «¿Olvidaste tu contraseña?» e ingresa un correo
- **THEN** el sistema muestra una respuesta genérica neutral independiente de si el correo existe o no
- **AND** si la cuenta existe, se genera un token de un solo uso válido por 30 minutos enviado por correo.

#### Scenario: Cambio efectivo de contraseña mediante token
- **GIVEN** un token de recuperación válido y no expirado
- **WHEN** el usuario ingresa una nueva contraseña válida
- **THEN** la contraseña se actualiza en el sistema
- **AND** el token queda marcado como `USADO`, impidiendo su reutilización.

### Requirement: Desacoplamiento de transporte de correo transaccional
El sistema SHALL abstraer el envío de correos, soportando la API HTTPS de Brevo y un transporte de captura de pruebas sin dependencias externas.

#### Scenario: Captura de correo en entorno de pruebas
- **GIVEN** el ambiente de pruebas `TransportesSegurosSA_Test`
- **WHEN** se genera una invitación o solicitud de recuperación
- **THEN** el mensaje es interceptado por `FakeMailCaptureSender`
- **AND** el token generado está disponible de forma segura para las suites de prueba sin realizar conexiones de red externas ni exponer secretos.
