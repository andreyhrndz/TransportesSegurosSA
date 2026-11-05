# Estructura Canónica de Base de Datos — Transportes Seguros S.A.

Este directorio contiene el ciclo de vida completo de la base de datos de **Transportes Seguros S.A.** en Microsoft SQL Server 2025, garantizando reproducibilidad técnica, trazabilidad de auditoría, compatibilidad estricta con la **Ley de Tránsito por Vías Públicas Terrestres y Seguridad Vial de Costa Rica (Ley 9078 y reformas)**, y separación rigurosa entre esquemas relacionales operacionales (OLTP) y analíticos (OLAP / Data Warehouse).

---

## 1. Organización del Directorio

```
database/
├── README.md                           # Documento maestro de arquitectura y despliegue
├── baseline/                           # Esquema base inicial (24 tablas de referencia, objetos y DW)
│   ├── 00_model/                       # Diagrama entidad-relación, DBML y especificación
│   ├── 01_setup/                       # Verificación previa del entorno y carpetas
│   ├── 02_schema/                      # Creación de base, 24 tablas, índices y FKs
│   ├── 03_security/                    # Catálogo de permisos inicial y administración
│   ├── 04_logic/                       # Tipos TVP, funciones, SPs base y triggers
│   ├── 05_data_warehouse/             # Base OLAP (TransportesSegurosSA_DW) y procedimiento ETL
│   ├── 06_maintenance_and_jobs/        # Scripts de copias de seguridad y agentes SQL
│   ├── 07_exposition/                  # Consultas ilustrativas y guiones
│   └── 08_replication_pending/         # Consultas de análisis para replicación transaccional
├── migrations/                         # Migraciones aditivas e incrementales del aplicativo
│   ├── 01_read_access_contracts.sql    # Contratos y procedimientos de lectura con RBAC
│   ├── 02_extended_read_contracts.sql  # Contratos extendidos de consulta y auditoría
│   ├── 02b_delivery_recipient_detail.sql# Detalle de destinatarios y recepciones
│   ├── 03_customer_lifecycle_contracts.sql # Alta, edición y estados de clientes físicos/jurídicos
│   ├── 04_master_data_contracts.sql    # Gestión de flota, ubicaciones y catálogos
│   ├── 05_operational_contracts.sql    # Creación de solicitudes TVP, viajes, paradas y entregas
│   ├── 06_user_accounts_contracts.sql  # Perfiles de usuario, roles y claves seguras
│   ├── 07_invitations_and_recovery.sql # Tokens de invitación de acceso y recuperación
│   ├── 08_invitation_permissions.sql   # Matriz de permisos individuales por invitación
│   ├── 09_driver_licenses_and_vehicle_pma_contracts.sql # Licencias Ley 9078, PMA y validación legal
│   └── rollbacks/                      # Scripts atómicos de reversión para cada migración
├── seed/                               # Datos semilla no sensibles para laboratorio y pruebas
│   ├── 01_datos_maestros.sql
│   ├── 02_clientes_y_solicitudes.sql
│   ├── 03_viaje_y_capacidad.sql
│   └── 04_entregas_y_finalizacion.sql
└── tests/                              # Suites automatizadas de verificación de contratos T-SQL
    ├── 01_prioridad_incidentes_documentos.sql
    ├── 02_permisos_y_errores.sql
    ├── 03_rollback_y_restricciones.sql
    ├── 04_consultas_y_auditoria.sql
    ├── 05_cancelar_solicitud.sql
    ├── 06_contract_tests_read_access.sql
    ├── 07_contract_tests_customer_lifecycle.sql
    ├── 08_contract_tests_master_data.sql
    ├── 09_contract_tests_operational.sql
    ├── 10_contract_tests_user_accounts.sql
    ├── 11_contract_tests_invitations_and_recovery.sql
    ├── 12_contract_tests_invitation_permissions.sql
    └── 13_contract_tests_driver_licenses_and_pma.sql
```

---

## 2. Orden de Despliegue para un Entorno Limpio

Para inicializar la base de datos desde cero en una instancia local o de pruebas (`TransportesSegurosSA_Test`):

1. **Creación del Esquema Base (`database/baseline/`):**
   ```powershell
   sqlcmd -S localhost -d master -E -i database/baseline/02_schema/01_crear_base.sql
   sqlcmd -S localhost -d TransportesSegurosSA -E -i database/baseline/02_schema/02_crear_24_tablas.sql
   sqlcmd -S localhost -d TransportesSegurosSA -E -i database/baseline/02_schema/03_crear_indices.sql
   sqlcmd -S localhost -d TransportesSegurosSA -E -i database/baseline/02_schema/04_crear_relaciones.sql
   sqlcmd -S localhost -d TransportesSegurosSA -E -i database/baseline/02_schema/05_restricciones.sql
   sqlcmd -S localhost -d TransportesSegurosSA -E -i database/baseline/03_security/01_catalogos.sql
   sqlcmd -S localhost -d TransportesSegurosSA -E -i database/baseline/04_logic/01_funciones_y_tipo.sql
   sqlcmd -S localhost -d TransportesSegurosSA -E -i database/baseline/04_logic/02_sp_clientes_solicitudes.sql
   sqlcmd -S localhost -d TransportesSegurosSA -E -i database/baseline/04_logic/03_sp_viajes_y_entregas.sql
   sqlcmd -S localhost -d TransportesSegurosSA -E -i database/baseline/04_logic/04_sp_incidencias_permisos_documentos.sql
   sqlcmd -S localhost -d TransportesSegurosSA -E -i database/baseline/04_logic/05_triggers_auditoria.sql
   sqlcmd -S localhost -d TransportesSegurosSA -E -i database/baseline/04_logic/06_vistas.sql
   ```

2. **Aplicación Secuencial de Migraciones (`database/migrations/`):**
   ```powershell
   sqlcmd -S localhost -d TransportesSegurosSA -E -i database/migrations/01_read_access_contracts.sql
   sqlcmd -S localhost -d TransportesSegurosSA -E -i database/migrations/02_extended_read_contracts.sql
   sqlcmd -S localhost -d TransportesSegurosSA -E -i database/migrations/02b_delivery_recipient_detail_contract.sql
   sqlcmd -S localhost -d TransportesSegurosSA -E -i database/migrations/03_customer_lifecycle_contracts.sql
   sqlcmd -S localhost -d TransportesSegurosSA -E -i database/migrations/04_master_data_contracts.sql
   sqlcmd -S localhost -d TransportesSegurosSA -E -i database/migrations/05_operational_contracts.sql
   sqlcmd -S localhost -d TransportesSegurosSA -E -i database/migrations/06_user_accounts_contracts.sql
   sqlcmd -S localhost -d TransportesSegurosSA -E -i database/migrations/07_invitations_and_recovery_contracts.sql
   sqlcmd -S localhost -d TransportesSegurosSA -E -i database/migrations/08_invitation_permissions_contracts.sql
   sqlcmd -S localhost -d TransportesSegurosSA -E -I -i database/migrations/09_driver_licenses_and_vehicle_pma_contracts.sql
   ```

3. **Carga de Datos Semilla (`database/seed/`):**
   ```powershell
   sqlcmd -S localhost -d TransportesSegurosSA -E -i database/seed/01_datos_maestros.sql
   sqlcmd -S localhost -d TransportesSegurosSA -E -i database/seed/02_clientes_y_solicitudes.sql
   ```

4. **Ejecución de Pruebas de Contrato (`database/tests/`):**
   ```powershell
   sqlcmd -S localhost -d TransportesSegurosSA -E -I -i database/tests/13_contract_tests_driver_licenses_and_pma.sql
   ```

---

## 3. Matriz Legal de Licencias y PMA (Costa Rica Ley 9078)

Conforme al artículo 86 de la Ley 9078 reformado por Ley 9460 y directrices técnicas del MOPT:

| Clase | Nombre / Tipo | Límite PMA / Criterio Legal | Articulado | Conducción Carga |
| :--- | :--- | :--- | :---: | :---: |
| **B-1** | Liviano | Hasta 4.000 kg PMA | No | Sí |
| **B-2** | Mediano | Hasta 8.000 kg PMA | No | Sí |
| **B-3** | Pesado Rígido | Sin límite de peso (PMA > 8.000 kg) | No | Sí |
| **B-4** | Pesado Articulado | Sin límite de peso (Cabezales y tráilers) | **Sí** | Sí |
| **E-1** | Universal 2+ ejes | Vehículos B y C-1 de 2 o más ejes | No | Sí |
| **E-2** | Universal completo | Vehículos B, C y D (habilita articulados) | **Sí** | Sí |
| **A-1 a A-3** | Motocicletas / Bicimotos | Por cilindrada (125cc, 500cc, sin límite) | No | No |
| **C-1 a C-2** | Transporte público | Taxis / Autobuses colectivos | No | No |
| **D-1 a D-3** | Equipo especial | Tractores agrícolas, oruga y maquinaria | No | No |

La función autoritativa `dbo.fn_conductor_habilitado_vehiculo(@conductor_id, @vehiculo_id, @fecha_salida)` comprueba en cada asignación que:
1. El vehículo tenga PMA registrado y estado de homologación distinto de `NO_CONFORME`.
2. El conductor posea en `dbo.conductores_licencias` al menos una licencia en estado `VERIFICADA`.
3. La fecha de vencimiento de la licencia cubra la fecha de salida programada del viaje.
4. Si el vehículo es articulado (`es_articulado = 1`), la licencia debe ser **B-4** o **E-2**.
5. Si el vehículo supera 8.000 kg de PMA, la licencia debe ser **B-3**, **B-4**, **E-1** o **E-2**.
6. Si el vehículo supera 4.000 kg de PMA, la licencia debe ser **B-2**, **B-3**, **B-4**, **E-1** o **E-2**.

Cualquier infracción es bloqueada transaccionalmente con error `50320` en `dbo.sp_crear_viaje` y `50321` en `dbo.sp_iniciar_viaje`.
