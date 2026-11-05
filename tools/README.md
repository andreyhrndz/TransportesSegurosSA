# Directorio de Herramientas y Entorno de Ejecución — Transportes Seguros S.A.

Este directorio documenta y estandariza los componentes de infraestructura, herramientas y librerías requeridas para ejecutar el sistema de **Transportes Seguros S.A.** en Microsoft Windows Server / Windows 11 con SQL Server 2025.

---

## 1. Política de Repositorio y Exclusión de Binarios

En cumplimiento de las buenas prácticas de ingeniería de software y control de versiones:
- **No se almacenan binarios pesados de terceros en el repositorio** (`.exe`, `.dll`, instaladores compilados o bibliotecas dinámicas).
- En su lugar, se provee el manifiesto formal [`tools/manifests/runtime_manifest.json`](manifests/runtime_manifest.json) con versiones exactas fijadas, URLs oficiales de descarga y sumas de comprobación criptográficas **SHA-256**.
- Se suministra la plantilla oficial de configuración [`tools/templates/php.ini.example`](templates/php.ini.example) lista para producción y desarrollo local.
- Se entregan scripts automatizados de diagnóstico en [`tools/scripts/`](scripts/) para validar la integridad del entorno antes del despliegue.

---

## 2. Requisitos de Infraestructura

1. **Sistema Operativo:** Windows 10/11 x64 o Windows Server 2022+ x64.
2. **Motor de Base de Datos:** Microsoft SQL Server 2025 (o 2022) con autenticación integrada o mixta.
3. **Runtime PHP:** PHP 8.4 x64 NTS (Non-Thread Safe) Visual C++ 2022.
4. **Controladores SQL Server:**
   - Microsoft ODBC Driver 17 o 18 for SQL Server.
   - Microsoft Drivers for PHP for SQL Server (versión compatible con PHP 8.4: `php_pdo_sqlsrv_84_nts_x64.dll` y `php_sqlsrv_84_nts_x64.dll`).

---

## 3. Instalación y Configuración Paso a Paso

### Paso 1: Instalación de PHP 8.4 NTS x64
1. Descargar el archivo ZIP oficial desde [windows.php.net](https://windows.php.net/downloads/releases/):
   - Versión: `php-8.4.27-nts-Win32-vs17-x64.zip`
   - Hash SHA-256 de `php.exe`: `69F59F1E561C24C5204800AD8ECC1585D79DFA03D07A644D66F4241C86B5991E`
2. Extraer en la ruta canónica local: `C:\AdmBD2026\Herramientas\PHP\8.4\`.

### Paso 2: Controladores Microsoft SQL Server
1. Descargar los controladores desde el repositorio oficial de Microsoft msphpsql:
   - [Microsoft msphpsql Releases](https://github.com/microsoft/msphpsql/releases)
2. Copiar los archivos a la carpeta `C:\AdmBD2026\Herramientas\PHP\8.4\ext\`:
   - `php_pdo_sqlsrv_84_nts_x64.dll` (SHA-256: `6BF961C7FB9A8DF261006CBB8C4CFE642433BA75AF5C79C8E31BA6C8197F448F`)
   - `php_sqlsrv_84_nts_x64.dll` (SHA-256: `B09762CA5EEAD8C77DD04C9CA3F91777C9FE543818FF17D14F209F10A6B025E7`)

### Paso 3: Configuración de `php.ini`
1. Copiar `tools/templates/php.ini.example` a `C:\AdmBD2026\Herramientas\PHP\8.4\php.ini`.
2. Verificar que las extensiones requeridas estén habilitadas:
   ```ini
   extension=curl
   extension=fileinfo
   extension=mbstring
   extension=openssl
   extension=php_sqlsrv_84_nts_x64.dll
   extension=php_pdo_sqlsrv_84_nts_x64.dll
   ```

### Paso 4: Verificación Automatizada del Entorno
Ejecutar el script de diagnóstico desde PowerShell:
```powershell
powershell -ExecutionPolicy Bypass -File tools/scripts/verify_environment.ps1
```
O directamente con PHP:
```powershell
& "C:\AdmBD2026\Herramientas\PHP\8.4\php.exe" tools/scripts/verify_environment.php
```

Ambos scripts validarán automáticamente:
- Versión de PHP $\ge$ 8.4.
- Carga de `pdo`, `pdo_sqlsrv`, `sqlsrv`, `openssl`, `mbstring`, `curl`, `fileinfo`.
- Conectividad activa y consulta a Microsoft SQL Server 2025.
