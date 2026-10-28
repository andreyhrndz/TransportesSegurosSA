## 1. Contratos

- [ ] 1.1 Confirmar en SQL Server índices, FKs, SP y permisos actuales de cada maestro.
- [ ] 1.2 Acordar matriz de permisos y campos editables para ubicaciones, flota, rutas y catálogos.
- [ ] 1.3 Diseñar procedimientos parametrizados de alta, edición, desactivación y listas activas.

## 2. Salvaguardas

- [ ] 2.1 Añadir validaciones de capacidad, estados, licencia, origen/destino y referencias activas.
- [ ] 2.2 Definir códigos de conflicto para vehículo/conductor/ruta asociados a viaje.
- [ ] 2.3 Revisar grants mínimos de la cuenta de aplicación.

## 3. Verificación

- [ ] 3.1 Probar cada permiso, check, FK y desactivación con registros referenciados.
- [ ] 3.2 Probar concurrencia de flota disponible frente a creación de viaje.
- [ ] 3.3 Validar el change y conservar evidencia SQL sin datos sensibles.
