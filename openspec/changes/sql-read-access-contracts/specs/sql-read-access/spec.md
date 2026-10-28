## Purpose

Define lecturas operativas con autorización individual y datos mínimos para que el frontend no dependa de permisos de escritura.

## ADDED Requirements

### Requirement: Permiso de lectura individual
El contrato SQL SHALL requerir un permiso activo de lectura específico para cada dominio y SHALL rechazar un contexto de usuario nulo o sin autorización.

#### Scenario: Consulta denegada
- **WHEN** un usuario sin `SOLICITUDES_VER` solicita una lista de solicitudes
- **THEN** el contrato rechaza la consulta y no devuelve registros.

### Requirement: Listas seguras y paginadas
Las listas SHALL aceptar sólo filtros, orden y tamaño de página definidos por el contrato y SHALL devolver un total consistente para la paginación.

#### Scenario: Búsqueda paginada
- **WHEN** una persona autorizada filtra clientes y solicita una página válida
- **THEN** recibe sólo las columnas de directorio autorizadas y el total del filtro aplicado.

### Requirement: Minimización de datos
Los contratos SHALL excluir hashes, secretos y columnas no necesarias para la pantalla solicitante.

#### Scenario: Consulta de usuario
- **WHEN** una pantalla autorizada consulta datos de usuario relacionados
- **THEN** el resultado no contiene `password_hash` ni credenciales.
