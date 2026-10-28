## Purpose

Administrar cuentas y permisos individuales de forma segura, sin introducir una capa de roles ni revelar secretos.

## ADDED Requirements

### Requirement: Directorio de cuentas autorizado
El sistema SHALL permitir consultar cuentas y permisos sólo con `USUARIOS_VER` y SHALL excluir hash y contraseña de la interfaz.

#### Scenario: Cuenta consultada
- **WHEN** una persona autorizada abre una cuenta
- **THEN** ve identidad, estado y permisos individuales sin secretos.

### Requirement: Mutaciones protegidas
El sistema SHALL usar exclusivamente contratos SQL aprobados, CSRF y permisos específicos para crear, editar, cambiar estado, restablecer y asignar/revocar permisos.

#### Scenario: Actor sin permiso
- **WHEN** una persona sin permiso de gestión intenta conceder un código
- **THEN** la asignación no cambia y recibe acceso denegado.

### Requirement: Sin roles y sin bypass de seguridad
El sistema SHALL representar asignaciones individuales y SHALL comunicar el rechazo de desactivar cuenta propia o última cuenta gestora sin ofrecer un bypass.

#### Scenario: Desactivación protegida
- **WHEN** el SP rechaza una desactivación protegida
- **THEN** la cuenta conserva su estado y la interfaz explica que la acción no se realizó.
