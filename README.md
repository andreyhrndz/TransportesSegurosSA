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

- **Gestión de Flota Vehicular, Ubicaciones en Mapa y Normativa de PMA:**
  - Administración de flota con registro de **PMA (Peso Máximo Autorizado en kg)** y capacidad de carga útil, clasificación legal (`LIVIANO`, `MEDIANO_RIGIDO`, `PESADO_RIGIDO`, `PESADO_ARTICULADO`), preservación interna de odómetro y control administrativo de unidades `NO_CONFORME`.
  - **Ubicaciones con mapa interactivo (Leaflet 1.9.4 vendoreado localmente + OpenStreetMap):** selección de punto por clic o arrastre con llenado automático de latitud/longitud (6 decimales) manteniendo independiente la dirección textual, panel de **Opciones avanzadas** para entrada manual sincronizada y **fallback automático** si no hay conexión a los tiles (`tile.openstreetmap.org`). Los registros con punto exponen enlaces públicos seguros **«Abrir en Google Maps»** (sin API key ni GPS en tiempo real) en Ubicaciones, Solicitudes y Paradas de viaje.

- **Conductores y Licencias (Ley 9078 / 9460 — flujo YAGNI):**
  - Catálogo normativo oficial de **14 categorías** de licencias (`A1`–`E2`) con selector único en UI, fecha de vencimiento única (sin `B4` por defecto) y limpieza inmediata del vencimiento al cambiar de categoría.
  - Sincronización atómica en SQL Server (`dbo.sp_actualizar_licencia_principal_conductor`) que impide habilitaciones ocultas heredadas conservando historial en `dbo.auditorias`.

- **Planificación de Viajes y Validación Legal en Tiempo Real:**
  - Verificación autoritativa de compatibilidad de licencia, vigencia y PMA tanto en base de datos (`dbo.fn_conductor_habilitado_vehiculo`, con **Error 50320** en `sp_crear_viaje` y **Error 50321** en `sp_iniciar_viaje`) como en el navegador con diagnóstico visual dinámico.
  - Control de concurrencia pesimista con locks transaccionales (`UPDLOCK`, `HOLDLOCK`).
  - Asignación de paradas ordenadas y vinculación de solicitudes.

- **Entregas, Incidencias y Gestión Documental:**
  - Confirmación de entregas en destino o reporte de intentos fallidos con receptor efectivo.
  - Registro y resolución de incidencias operativas en ruta.
  - Carga y vinculación de respaldos documentales protegidos.

- **Reportes, Bitácora Independiente y Data Warehouse:**
  - Módulo independiente **Historial de actividad** (`/auditoria.php`) bajo Administración (visible solo con `AUDITORIA_VER`), con filtros SQL por usuario (preservando inactivos y sistema), módulo, acción y rango de fechas, descripción en lenguaje humano y detalle técnico desplegable con redacción de secretos.
  - Módulo **Reportes** (`/reportes.php`) con tres vistas analíticas (**Solicitudes por período**, **Entregas y resultados** y **Desempeño histórico (DW)** conectado en modo solo lectura a `TransportesSegurosSA_DW`), gráficos Chart.js, exportación **CSV** segura (UTF-8 BOM + protección contra inyección de fórmulas) y vista limpia **Imprimir / PDF**.

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
Para aprovisionar la base de datos de pruebas local con las tablas, procedimientos almacenados y contratos `01` a `13`:
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
| **`TransportesSegurosSA`** | **Base Original de Exposición** | Base de datos principal. Compatible con las migraciones `01` a `13` tras respaldo verificado y ensayo en staging (ver `docs/ops/deploy-migrations-pr4-exposicion.md`). **PROHIBIDO ejecutar pruebas automatizadas con escrituras o modificaciones directas sobre esta base.** |
| **`TransportesSegurosSA_DW`** | **Data Warehouse** | Base dimensional en estrella (6 tablas + ETL) consultada en modo solo lectura (`ApplicationIntent=ReadOnly`) desde el reporte histórico. |

---

## 6. Pruebas Automatizadas y Calidad

El proyecto cuenta con una cobertura integral de pruebas reproducibles ejecutables **exclusivamente sobre `TransportesSegurosSA_Test`**:
- **Suites Automatizadas PHP (26 suites `tests/*_test.php`) + JS:** Pruebas unitarias, funcionales, de concurrencia HTTP, ubicaciones híbridas y seguridad ASVS bajo PHP 8.4.
- **Suites Contractuales T-SQL (Migraciones 01 a 12):** Pruebas sobre SQL Server validando reglas de negocio, rollback transaccional, licencias/PMA, paginación SQL real, corrección atómica de correo en invitaciones y ubicaciones híbridas en solicitudes (`es_reutilizable` + validación Haversine 25 m).
- **Verificación E2E y Visual Playwright (Chromium real, 3 suites):** Cobertura de flujos YAGNI, scroll modal global, mapa Leaflet compartido (catálogo + Nueva solicitud híbrida) + fallback offline + enlaces Google Maps, e inspección visual en resoluciones desktop (`1366×768`, `1440×900`, `1600×900`, `1920×1080`, `1280×720`), tablet (`768×1024`) y móvil (`375×667`).
- **OpenSpec:** 21 especificaciones activas validadas con `openspec validate --all`.
- **Plan de QA manual para exposición:** ver `docs/qa/plan-qa-exposicion.md` y el helper sintético `tests/qa_seed_test_fixtures.php`.
