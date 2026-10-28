## Purpose

Establecer condiciones verificables para liberar la aplicación sin confundir validación estructural, pruebas funcionales y requisitos de producción.

## ADDED Requirements

### Requirement: Evidencia de pruebas por cambio
Cada change SHALL registrar pruebas ejecutadas, ambiente, resultados y limitaciones antes de considerarse terminado.

#### Scenario: Base no disponible
- **WHEN** un smoke SQL no puede ejecutarse por configuración del ambiente
- **THEN** el change registra el bloqueo y no declara el flujo funcional como aprobado.

### Requirement: Interfaz accesible y responsive
La interfaz SHALL mantener contraste WCAG AA, foco visible, navegación por teclado, mensajes accesibles y operación a 320 px, tablet y escritorio.

#### Scenario: Error de formulario
- **WHEN** un campo falla validación
- **THEN** el error identifica el problema, se asocia al control y es perceptible sin depender sólo del color.

### Requirement: Promoción segura
Un release SHALL requerir TLS verificable, secretos fuera del repositorio, cuenta SQL de mínimo privilegio, rollback documentado y revisión humana de PR.

#### Scenario: Gate incompleto
- **WHEN** falta una evidencia crítica o aprobación requerida
- **THEN** el cambio no se promueve ni se fusiona automáticamente.
