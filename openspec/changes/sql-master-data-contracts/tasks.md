## 1. Contratos

- [x] 1.1 Confirmar en SQL Server índices, FKs, SP y permisos actuales de cada maestro.
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

## Evidencia y bloqueos

- En `TransportesSegurosSA_Test` se confirmaron los CHECK reales de coordenadas, capacidad,
  kilometraje, estados de vehículo/conductor y origen/destino de ruta. El inventario no contiene
  procedimientos de escritura para ubicaciones, catálogos, vehículos, conductores o rutas.
- Los únicos procedimientos nuevos relacionados son los listados de lectura del contrato
  `sql-read-access-contracts`; no sustituyen contratos de alta, edición ni desactivación.
- Permanecen bloqueadas la matriz de permisos individuales, los campos editables y las reglas
  de desactivación/referencia para flota y rutas. No se creó ni ejecutó DML maestro hasta que
  producto y DBA aprueben esas decisiones.
