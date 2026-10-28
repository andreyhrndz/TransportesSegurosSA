# Delta: fundamentos visuales del frontend

## ADDED Requirements

### Requirement: Identidad visual coherente y legible

El frontend SHALL utilizar tokens visuales compartidos, con colores suaves en superficies, contraste suficiente en texto y acciones y estilos uniformes entre módulos.

#### Scenario: Interfaz en escritorio
- GIVEN una persona autenticada en el panel
- WHEN visita el dashboard o el listado de clientes
- THEN encuentra la misma jerarquía tipográfica, esquema de color, estados y espaciados del sistema
- AND puede leer títulos, datos y controles sin depender exclusivamente del color.

### Requirement: Controles y acciones reutilizables

El frontend SHALL ofrecer patrones coherentes de botones, campos, tablas, paginación, modales y notificaciones, con etiquetas breves y comprensibles.

#### Scenario: Guardar formulario
- GIVEN un formulario válido de registro de cliente
- WHEN la persona confirma con el botón «Guardar»
- THEN la interfaz presenta estado de proceso y resultado
- AND conserva los mecanismos de validación y protección CSRF.

#### Scenario: Error al guardar
- GIVEN un formulario con datos inválidos o rechazados por una regla de negocio
- WHEN se intenta guardar
- THEN el sistema muestra un mensaje legible y cercano al problema
- AND no muestra información técnica sensible.

### Requirement: Navegación responsive operable

El frontend SHALL permitir navegación visible o descubrible en escritorio, tablet y móvil sin recortar acciones importantes.

#### Scenario: Pantalla de móvil
- GIVEN una pantalla móvil de ancho reducido
- WHEN la persona accede a un módulo
- THEN puede navegar y cerrar sesión mediante controles accesibles
- AND las acciones principales no requieren desplazamiento horizontal de la página.

### Requirement: Iconografía y accesibilidad consistente

La interfaz SHALL usar una familia coherente de iconos y navegación por teclado, con etiquetas accesibles y foco visible.

#### Scenario: Acciones con iconos
- GIVEN una acción representada mediante icono
- WHEN una persona usa lector de pantalla o teclado
- THEN la acción tiene un nombre accesible, recibe foco y comunica su propósito.

### Requirement: Información y estados honestos

La interfaz SHALL mostrar solamente acciones implementadas, evitar mensajes académicos y presentar fechas operativas legibles para la persona usuaria.

#### Scenario: Módulo no disponible
- GIVEN un módulo que todavía no está implementado
- WHEN la persona navega por el sistema
- THEN la interfaz no ofrece una acción que simule una funcionalidad inexistente.

#### Scenario: Fecha de actividad
- GIVEN un registro que contiene una marca temporal de SQL Server
- WHEN se visualiza en un listado o tarjeta
- THEN la fecha se presenta en un formato humano consistente y con zona horaria aclarada cuando sea relevante.

### Requirement: Preservar funcionalidades existentes

La estandarización visual SHALL mantener disponibles el login, cierre de sesión, dashboard y operaciones actuales de clientes sin alterar las reglas SQL.

#### Scenario: Operación después del rediseño
- GIVEN una cuenta habilitada con permiso para registrar clientes
- WHEN inicia sesión y registra un cliente físico o jurídico
- THEN la operación mantiene sus controles de autorización, CSRF y validación
- AND el resultado aparece en el listado según las capacidades actuales.
