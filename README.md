# Transportes Seguros S.A.

Plataforma integral de gestión logística, operativa y administrativa desarrollada con **PHP 8.4 (NTS x64)**, **Microsoft SQL Server 2025 (PDO_SQLSRV)**, **Bulma CSS** y **HTMX**.

---

## 1. Módulos y Capacidades Implementadas

La plataforma cubre el ciclo de vida completo de la operación de transporte de carga comercial bajo el marco legal de la **República de Costa Rica** y los estándares de seguridad de **OWASP ASVS Nivel 2**:

- **Autenticación y Control de Acceso Granular:**
  - Sesiones PHP blindadas (`SameSite=Lax`, `HttpOnly`, `__Host-` en HTTPS, regeneración contra fijación y expiración por inactividad).
  - Protección contra CSRF con tokens criptográficos por sesión en todos los formularios y solicitudes HTMX.
  - Modelo de autorización granular de **28 permisos individuales activos** (sin roles planos); auditoría transaccional en `dbo.auditorias`.
  - Sistema de invitaciones por correo transaccional vía **API de Brevo (Sendinblue)** con asignación previa de permisos por principio de mínimo privilegio y flujo de onboarding seguro.
  - Flujo seguro de recuperación de contraseñas mediante tokens efímeros con hash criptográfico SHA-256.

- **Directorio de Clientes e Integración Tributaria:**
  - Registro, edición y consulta de clientes físicos y corporativos (jurídicos).
  - Validación y autocompletado en tiempo real con el **Ministerio de Hacienda de Costa Rica** (API RUC de comprobantes electrónicos) mediante HTTPS seguro con timeout configurable.

- **Solicitudes de Transporte y Destinatarios:**
  - Alta y seguimiento de solicitudes de transporte con mercancías asociadas y validación de cubicaje y peso.
  - Selector interactivo de destinatario: entrega al mismo cliente o a un tercero debidamente autorizado (obligatorio para personas jurídicas).

- **Gestión de Flota Vehicular y Normativa de PMA:**
  - Administración de flota con registro de **PMA (Peso Máximo Autorizado en kg)** según ficha técnica oficial del MOPT, distinguiendo claramente el PMA de la **capacidad de carga útil**.
  - Clasificación legal de la unidad: `LIVIANO` (&le; 4.000 kg PMA), `MEDIANO_RIGIDO` (&le; 8.000 kg PMA), `PESADO_RIGIDO` (&gt; 8.000 kg PMA) y `PESADO_ARTICULADO`.
  - Registro de condición de remolque/articulado y estado de homologación vehicular (`VERIFICADO`, `PENDIENTE_VERIFICACION`, `NO_CONFORME`).

- **Conductores y Catálogo Oficial de Licencias de Conducir (Ley 9078 / 9460):**
  - Catálogo normativo oficial de **14 categorías** de licencias de Costa Rica (`A1`, `A2`, `A3`, `B1`, `B2`, `B3`, `B4`, `C1`, `C2`, `D1`, `D2`, `D3`, `E1`, `E2`).
  - Soporte de múltiples licencias por conductor en `dbo.conductores_licencias` con número de documento, fecha de vencimiento individual y estado de verificación oficial.

- **Planificación de Viajes y Validación Legal en Tiempo Real:**
  - Verificación autoritativa de compatibilidad de licencia, vigencia y PMA tanto en base de datos (`dbo.fn_conductor_habilitado_vehiculo`, con **Error 50320** en `sp_crear_viaje` y **Error 50321** en `sp_iniciar_viaje`) como en el navegador con diagnóstico visual dinámico.
  - Control de concurrencia pesimista con locks transaccionales (`UPDLOCK`, `HOLDLOCK`).
  - Asignación de paradas ordenadas y vinculación de solicitudes.

- **Entregas, Incidencias y Gestión Documental:**
  - Confirmación de entregas en destino o reporte de intentos fallidos con receptor efectivo.
  - Registro y resolución de incidencias operativas en ruta.
  - Carga y vinculación de respaldos documentales protegidos.

- **Reportes, Auditoría y Data Warehouse:**
  - Visualización del historial de auditoría de modificaciones a entidades críticas.
  - Esquema dimensional de analítica en `TransportesSegurosSA_DW` (6 tablas dimensionales y procedimientos ETL).

---

## 2. Requisitos del Sistema

- **Sistema Operativo:** Windows 10 / 11 / Server 2022+.
- **PHP:** Versión **8.4 x64 Non-Thread Safe (NTS)**.
  - Extensiones requeridas: `pdo`, `pdo_sqlsrv`, `sqlsrv`, `openssl`, `mbstring`, `curl`, `fileinfo`.
- **Base de Datos:** Microsoft SQL Server 2019 / 2022 / 2025 con autenticación Windows o SQL Server Authentication.
- **Controlador ODBC:** Microsoft ODBC Driver 17 u 18 for SQL Server.
- **Herramienta CLI:** `sqlcmd` (incluida en Microsoft Command Line Utilities for SQL Server).

---

## 3. Guía de Instalación y Arranque Rápido (Desde Cero)

El repositorio está diseñado para ser completamente **portable**: puede clonarse y ejecutarse desde cualquier unidad o carpeta de Windows (sin requerir rutas fijas).

### Paso 1: Clonar el Repositorio
```powershell
git clone https://github.com/andreyhrndz/TransportesSegurosSA.git
cd TransportesSegurosSA
```

### Paso 2: Preparar y Validar el Entorno de Desarrollo
Ejecute el asistente automatizado de configuración:
```powershell
.\tools\scripts\setup-dev.ps1
```
> [!NOTE]
> `setup-dev.ps1` detecta automáticamente su ejecutable de PHP 8.4 (en el PATH, en `tools/local/php84/php.exe` o ubicaciones estándar), valida extensiones, detecta los controladores ODBC y crea el archivo `.env` inicial con valores seguros de desarrollo si aún no existe. Si su PHP está en una ruta personalizada, pásela como parámetro:
> ```powershell
> .\tools\scripts\setup-dev.ps1 -PhpPath "D:\Herramientas\PHP\8.4\php.exe"
> ```

### Paso 3: Inicializar la Base de Datos de Pruebas (`TransportesSegurosSA_Test`)
Para aprovisionar la base de datos de pruebas local con las 28 tablas, procedimientos almacenados y contratos 01 a 09:
```powershell
.\tools\scripts\init-test-db.ps1
```
*(Opcional: use el parámetro `-IncludeSeed` si desea cargar datos de ejemplo sintéticos).*

### Paso 4: Verificar la Integridad del Entorno
Ejecute el diagnóstico exhaustivo de solo lectura:
```powershell
.\tools\scripts\verify_environment.ps1
```

### Paso 5: Iniciar el Servidor de Desarrollo
Levante el servidor integrado de PHP:
```powershell
.\tools\scripts\start-dev.ps1
```
El servidor quedará escuchando en `http://127.0.0.1:8001`.

Abra su navegador web y visite:
**[http://127.0.0.1:8001/auth/login.php](http://127.0.0.1:8001/auth/login.php)**

Para detener el servidor en cualquier momento, presione `Ctrl + C`.

---

## 4. Estructura Canónica del Proyecto

```
TransportesSegurosSA/
├── app/                                 # Lógica de negocio, consultas y contratos de seguridad
│   ├── bootstrap.php                    # Inicialización segura, encabezados y conexión PDO_SQLSRV
│   ├── auth.php                         # Control de sesiones, autenticación y CSRF
│   ├── master_data.php                  # Catálogos de flota, licencias, ubicaciones y rutas
│   └── ...
├── database/                            # Fuente canónica oficial de Base de Datos (SQL Server)
│   ├── baseline/                        # Esquema fundacional reproducible (24 tablas, DW, Jobs)
│   ├── migrations/                      # Migraciones incrementales aditivas (01 a 09) y rollbacks
│   ├── seed/                            # Datos sintéticos maestros y operativos de prueba
│   ├── tests/                           # 13 suites automatizadas de pruebas contractuales T-SQL
│   └── README.md                        # Documentación exhaustiva de arquitectura de datos
├── docs/                                # Documentación técnica, capturas Playwright y roadmaps
│   └── screenshots/                     # 48 capturas de pantallas y modales en 1366x768 y 1440x900
├── openspec/                            # Especificaciones del estándar OpenSpec (18 changes)
├── public/                              # Raíz del servidor web (document root)
│   ├── index.php                        # Redirección y panel de control
│   ├── auth/                            # Login, logout, recuperación y onboarding
│   ├── clientes/                        # Gestión de clientes físicos y jurídicos
│   ├── flota/                           # Gestión de flota (PMA, homologación) y conductores (licencias)
│   ├── solicitudes/                     # Registro y asignación de solicitudes
│   ├── viajes/                          # Planificación y control de viajes con diagnóstico Ley 9078
│   ├── entregas.php                     # Confirmación de entregas y terminales
│   └── assets/                          # Estilos Bulma, tema Dark Logistics y componentes JS
├── tests/                               # 24 suites de pruebas automatizadas PHP y suite Playwright
└── tools/                               # Configuración y utilidades de entorno de desarrollo
    ├── manifests/
    │   └── runtime_manifest.json        # Hashes SHA-256 de los binarios oficiales de PHP y drivers
    ├── scripts/
    │   ├── setup-dev.ps1                # Asistente de configuración inicial
    │   ├── start-dev.ps1                # Script de arranque del servidor local
    │   ├── init-test-db.ps1             # Inicializador de la base TransportesSegurosSA_Test
    │   ├── verify_environment.ps1       # Verificador de entorno en PowerShell
    │   └── verify_environment.php       # Diagnóstico interno de extensiones y contratos SQL
    ├── templates/
    │   └── php.ini.example              # Plantilla de configuración endurecida para PHP 8.4
    └── README.md                        # Guía del entorno de herramientas y drivers
```

---

## 5. Distinción Estricta de Bases de Datos y Entornos

| Base de Datos | Propósito | Reglas Operativas |
|---|---|---|
| **`TransportesSegurosSA_Test`** | **Desarrollo y Pruebas** | Entorno predeterminado en `.env`. Destino seguro para ejecutar suites de pruebas automatizadas, pruebas de integración y creación de registros efímeros. |
| **`TransportesSegurosSA`** | **Base Original de Producción** | Base de datos principal. Cuenta con las migraciones 01 a 09 aplicadas y verificadas. **PROHIBIDO ejecutar pruebas automatizadas con escrituras o modificaciones directas sobre esta base.** |
| **`TransportesSegurosSA_DW`** | **Data Warehouse** | Base dimensional para analítica e indicadores operacionales (ETL programado). |

---

## 6. Pruebas Automatizadas y Calidad

El proyecto cuenta con una cobertura integral de pruebas reproducibles:
- **24 Suites Automatizadas PHP:** Pruebas unitarias, funcionales, de integración HTTP y de seguridad ASVS (100% PASS bajo PHP 8.4).
- **13 Suites Contractuales T-SQL:** Ejecutadas sobre SQL Server validando reglas de negocio, rollback transaccional y catálogo de licencias.
- **Verificación Visual Playwright:** Inspección automatizada en resoluciones de laptop (1366×768 y 1440×900) con comprobación de contraste, selectores personalizados y modales accesibles.
- **OpenSpec:** 18 especificaciones activas validadas con `openspec validate --all` (18 passed, 0 failed).
