# Directorio de Herramientas y Entorno de Ejecución — Transportes Seguros S.A.

Este directorio estandariza los componentes de infraestructura, herramientas y utilidades requeridas para ejecutar el sistema de **Transportes Seguros S.A.** en cualquier máquina con Windows y Microsoft SQL Server.

---

## 1. Política de Repositorio y Exclusión de Binarios

En estricto cumplimiento de buenas prácticas de ingeniería de software:
- **No se almacenan binarios de terceros en el repositorio Git** (`.exe`, `.dll`, instaladores compilados o bibliotecas dinámicas).
- En su lugar, se provee el manifiesto formal [`tools/manifests/runtime_manifest.json`](manifests/runtime_manifest.json) con versiones exactas, enlaces oficiales de descarga y sumas criptográficas **SHA-256**.
- Se suministra la plantilla oficial de configuración [`tools/templates/php.ini.example`](templates/php.ini.example) lista para desarrollo y producción.
- Se entregan scripts automatizados en [`tools/scripts/`](scripts/) para configurar, inicializar, verificar y levantar el entorno local desde cualquier directorio.

### Carpeta Opcional de Instalación Local (`tools/local/`)
Si prefiere mantener un entorno de PHP autocontenido dentro de la copia del repositorio sin instalarlo en el sistema global, puede extraer PHP en `tools/local/php84/`:
- Esta ruta está **completamente excluida en `.gitignore`** (`/tools/local/`).
- Los scripts `setup-dev.ps1`, `start-dev.ps1` y `verify_environment.ps1` detectan automáticamente `tools\local\php84\php.exe` de forma preferente.

---

## 2. Requisitos de Infraestructura

1. **Sistema Operativo:** Windows 10/11 x64 o Windows Server 2022+ x64.
2. **Motor de Base de Datos:** Microsoft SQL Server 2019, 2022 o 2025 (Standard, Developer o Express).
3. **Runtime PHP:** PHP 8.4 x64 Non-Thread Safe (NTS) Visual C++ 2022.
4. **Controladores SQL Server:**
   - Microsoft ODBC Driver 17 u 18 for SQL Server.
   - Microsoft Drivers for PHP for SQL Server (versión 5.12+ compatible con PHP 8.4: `php_pdo_sqlsrv_84_nts_x64.dll` y `php_sqlsrv_84_nts_x64.dll`).
5. **Herramienta CLI (Opcional para web, requerida para inicializar base):** `sqlcmd`.

---

## 3. Scripts Automatizados de Ciclo de Vida (`tools/scripts/`)

| Script | Propósito | Ejemplo de Uso |
|---|---|---|
| **`setup-dev.ps1`** | **Preparación del Entorno:** Detecta PHP, valida extensiones, detecta ODBC y crea `.env` inicial si no existe. | `.\tools\scripts\setup-dev.ps1` |
| **`init-test-db.ps1`** | **Inicialización de Base de Pruebas:** Crea la base `TransportesSegurosSA_Test` en el almacenamiento por defecto de SQL Server, aplica las 24 tablas base y las 10 migraciones con UTF-8. | `.\tools\scripts\init-test-db.ps1` |
| **`verify_environment.ps1`** | **Diagnóstico Integral:** Comprueba el runtime PHP, las 7 extensiones obligatorias, conectividad SQL Server y existencia de contratos. | `.\tools\scripts\verify_environment.ps1` |
| **`start-dev.ps1`** | **Servidor Web Local:** Inicia el servidor PHP apuntando a `public/` en `http://127.0.0.1:8001` con validación previa de puerto y variables. | `.\tools\scripts\start-dev.ps1` |

---

## 4. Detección Portable del Ejecutable de PHP

Los scripts resuelven la ubicación de PHP en el siguiente orden de precedencia:
1. Parámetro explícito `-PhpPath "ruta\hacia\php.exe"`.
2. Carpeta local ignorada por Git: `<raíz>\tools\local\php84\php.exe` o `<raíz>\tools\local\php\php.exe`.
3. Ubicaciones estándar de Windows (`C:\AdmBD2026\Herramientas\PHP\8.4\php.exe`, `C:\tools\php84\php.exe`, `C:\php84\php.exe`).
4. Comando `php` disponible en el `PATH` del sistema.

---

## 5. Manifiesto de Integridad Criptográfica (`tools/manifests/runtime_manifest.json`)

Para verificar que sus binarios locales coinciden exactamente con la versión certificada del proyecto:
- **`php.exe` (PHP 8.4.27 NTS x64):**
  `69F59F1E561C24C5204800AD8ECC1585D79DFA03D07A644D66F4241C86B5991E`
- **`php_pdo_sqlsrv_84_nts_x64.dll`:**
  `6BF961C7FB9A8DF261006CBB8C4CFE642433BA75AF5C79C8E31BA6C8197F448F`
- **`php_sqlsrv_84_nts_x64.dll`:**
  `B09762CA5EEAD8C77DD04C9CA3F91777C9FE543818FF17D14F209F10A6B025E7`
