## Purpose

Definir administración de cuentas y permisos individuales sin roles ni exposición de credenciales.

## ADDED Requirements

### Requirement: Contratos de cuenta con privilegio individual
Los procedimientos SHALL requerir `USUARIOS_GESTIONAR_CUENTAS`, validar entradas y devolver `usuario_id` sólo en el alta exitosa.

#### Scenario: Alta duplicada
- **WHEN** se crea una cuenta con nombre o correo existente
- **THEN** el procedimiento no inserta datos y devuelve el conflicto documentado.

### Requirement: Contraseñas no expuestas
El contrato SHALL aceptar sólo un hash generado por PHP, SHALL prohibir contraseña en texto plano y SHALL excluir `password_hash` de toda lectura.

#### Scenario: Lectura de cuenta
- **WHEN** una persona con `USUARIOS_VER` consulta una cuenta
- **THEN** recibe identidad, estado y permisos autorizados sin hash.

### Requirement: Estado seguro y permisos individuales
El contrato SHALL impedir que un actor desactive su propia cuenta o la última cuenta activa gestora y SHALL usar exclusivamente asignaciones de `usuarios_permisos`.

#### Scenario: Última cuenta gestora
- **WHEN** se intenta desactivar la última cuenta activa con `USUARIOS_GESTIONAR_CUENTAS`
- **THEN** el procedimiento rechaza la operación sin modificar estado ni permisos.
