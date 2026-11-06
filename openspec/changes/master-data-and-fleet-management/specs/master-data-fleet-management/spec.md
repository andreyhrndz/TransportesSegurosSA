## Purpose

Ofrecer administración segura de los datos maestros que alimentan solicitudes y viajes, respetando los contratos y estados existentes.

## ADDED Requirements

### Requirement: Módulos visibles sólo cuando son reales y autorizados
El sistema SHALL mostrar una sección maestra sólo si está implementada y la persona posee el permiso de lectura correspondiente.

#### Scenario: Sin permiso de flota
- **WHEN** una persona autenticada no posee el permiso de lectura de flota
- **THEN** no puede navegar ni consultar vehículos o conductores.

### Requirement: Gestión mediante contratos SQL
El sistema SHALL crear, editar y desactivar maestros exclusivamente mediante los contratos aprobados y SHALL mostrar sus conflictos de negocio.

#### Scenario: Desactivar ruta usada
- **WHEN** el contrato rechaza desactivar una ruta con referencia operativa
- **THEN** la interfaz conserva el registro y explica que no se realizó el cambio.

### Requirement: Selectores operativos válidos
El sistema SHALL usar sólo ubicaciones, catálogos y recursos activos disponibles en los formularios que los requieren.

#### Scenario: Crear viaje
- **WHEN** una persona abre el formulario de viaje autorizado
- **THEN** el selector excluye vehículos o conductores no disponibles según el contrato de lectura.
