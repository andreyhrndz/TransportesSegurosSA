<#
.SYNOPSIS
    Transportes Seguros S.A. — Configuracion y preparacion del entorno de desarrollo local.
.DESCRIPTION
    Script idempotente y portable para verificar dependencias de PHP 8.4, extensiones de SQL Server,
    controladores ODBC, configuracion de variables de entorno (.env) y conectividad de desarrollo.
    Puede ejecutarse desde cualquier directorio.
.PARAMETER PhpPath
    Ruta explicita al ejecutable php.exe. Si se omite, se detecta automaticamente en tools/local,
    en el PATH del sistema o en ubicaciones estandar de desarrollo.
.PARAMETER SkipDbCheck
    Si se especifica, omite la prueba de conectividad inmediata con SQL Server.
.EXAMPLE
    .\tools\scripts\setup-dev.ps1
.EXAMPLE
    .\tools\scripts\setup-dev.ps1 -PhpPath "C:\Herramientas\PHP\8.4\php.exe"
#>

[CmdletBinding()]
param (
    [string]$PhpPath = '',
    [switch]$SkipDbCheck
)

$ErrorActionPreference = 'Stop'

function Resolve-PhpExecutable {
    param (
        [string]$ExplicitPath,
        [string]$Root
    )

    if ($ExplicitPath -and (Test-Path $ExplicitPath)) {
        return (Resolve-Path $ExplicitPath).Path
    }

    $candidates = @(
        (Join-Path $Root 'tools\local\php84\php.exe'),
        (Join-Path $Root 'tools\local\php\php.exe'),
        'C:\AdmBD2026\Herramientas\PHP\8.4\php.exe',
        'C:\tools\php84\php.exe',
        'C:\php84\php.exe',
        'C:\php\php.exe'
    )

    foreach ($cand in $candidates) {
        if (Test-Path $cand) {
            return (Resolve-Path $cand).Path
        }
    }

    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($cmd) {
        return $cmd.Source
    }

    return $null
}

function Get-EnvMap {
    param ([string]$Path)
    $map = @{}
    if (Test-Path $Path) {
        $lines = Get-Content $Path
        foreach ($line in $lines) {
            $trimmed = $line.Trim()
            if ($trimmed -and -not $trimmed.StartsWith('#') -and ($trimmed -match '^([^=]+)=(.*)$')) {
                $k = $matches[1].Trim()
                $v = $matches[2].Trim()
                $map[$k] = $v
            }
        }
    }
    return $map
}

# 1. Resolver raiz del repositorio Git de manera portable
$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
Write-Host '====================================================================' -ForegroundColor Cyan
Write-Host ' Transportes Seguros S.A. — Asistente de Preparacion (setup-dev)    ' -ForegroundColor Cyan
Write-Host '====================================================================' -ForegroundColor Cyan
Write-Host "Raiz del proyecto: $repoRoot" -ForegroundColor Gray

$hasErrors = $false
$hasWarnings = $false

# 2. Localizar ejecutable de PHP 8.4
$resolvedPhp = Resolve-PhpExecutable -ExplicitPath $PhpPath -Root $repoRoot

if (-not $resolvedPhp) {
    Write-Host ''
    Write-Host '[ERROR CRITICO] No se encontro el ejecutable de PHP.' -ForegroundColor Red
    Write-Host 'Instrucciones de instalacion oficial:' -ForegroundColor Yellow
    Write-Host '  1. Descargue PHP 8.4 x64 Non-Thread Safe (NTS) desde: https://windows.php.net/download/' -ForegroundColor Gray
    Write-Host "  2. Extraiga el contenido en una carpeta local (ejemplo: 'tools\local\php84\' o 'C:\tools\php84\')." -ForegroundColor Gray
    Write-Host '  3. Ejecute nuevamente setup-dev.ps1 indicando -PhpPath o ubicandolo en tools\local\php84\.' -ForegroundColor Gray
    exit 1
}

Write-Host ''
Write-Host '[1/5] Verificando Runtime PHP...' -ForegroundColor Yellow
Write-Host "  PHP detectado: $resolvedPhp" -ForegroundColor Green

# Validar version y arquitectura
$phpInfoRaw = & $resolvedPhp -r "echo PHP_VERSION . '|' . (PHP_INT_SIZE === 8 ? 'x64' : 'x86') . '|' . (PHP_ZTS ? 'TS' : 'NTS');" 2>$null
$phpParts = $phpInfoRaw -split '\|'
$phpVersion = $phpParts[0]
$phpArch = $phpParts[1]
$phpThread = $phpParts[2]

Write-Host "  Version: $phpVersion ($phpArch, $phpThread)" -ForegroundColor Green

if ([System.Version]$phpVersion -lt [System.Version]'8.4.0') {
    Write-Host "  [ERROR] Se requiere PHP 8.4.0 o superior (version detectada: $phpVersion)." -ForegroundColor Red
    Write-Host '          Actualice el runtime a PHP 8.4 NTS x64.' -ForegroundColor Yellow
    $hasErrors = $true
}

# php.ini cargado
$loadedIni = & $resolvedPhp -r "echo php_ini_loaded_file() ?: 'NINGUNO';" 2>$null
Write-Host "  Archivo php.ini cargado: $loadedIni" -ForegroundColor Gray
if ($loadedIni -eq 'NINGUNO') {
    Write-Host '  [ADVERTENCIA] PHP esta ejecutandose sin php.ini. Las extensiones podrian no cargarse.' -ForegroundColor Yellow
    $hasWarnings = $true
}

# 3. Validar extensiones obligatorias de PHP
Write-Host ''
Write-Host '[2/5] Verificando Extensiones Obligatorias de PHP...' -ForegroundColor Yellow
$requiredExtensions = @(
    @{ Name = 'pdo'; Desc = 'Capa de abstraccion PDO' },
    @{ Name = 'pdo_sqlsrv'; Desc = 'Driver PDO Microsoft SQL Server' },
    @{ Name = 'sqlsrv'; Desc = 'Driver nativo Microsoft SQL Server' },
    @{ Name = 'openssl'; Desc = 'Criptografia y TLS' },
    @{ Name = 'mbstring'; Desc = 'Codificacion UTF-8 multibyte' },
    @{ Name = 'curl'; Desc = 'Cliente HTTP para integraciones (Hacienda/Brevo)' },
    @{ Name = 'fileinfo'; Desc = 'Deteccion MIME segura de archivos' }
)

$loadedExts = (& $resolvedPhp -r "echo implode(',', get_loaded_extensions());") -split ','

foreach ($ext in $requiredExtensions) {
    if ($loadedExts -contains $ext.Name) {
        Write-Host "  [OK] $($ext.Name) - $($ext.Desc)" -ForegroundColor Green
    } else {
        Write-Host "  [FALTA] $($ext.Name) - $($ext.Desc)" -ForegroundColor Red
        $hasErrors = $true
        if ($ext.Name -match 'sqlsrv') {
            Write-Host '    Descargue los Microsoft Drivers for PHP for SQL Server (version 5.12+ compatible con PHP 8.4):' -ForegroundColor Gray
            Write-Host '    https://learn.microsoft.com/sql/connect/php/download-drivers-php-sql-server' -ForegroundColor Gray
            Write-Host "    Agregue 'extension=$($ext.Name)' a su php.ini." -ForegroundColor Gray
        } else {
            Write-Host "    Habilite 'extension=$($ext.Name)' en su php.ini ($loadedIni)." -ForegroundColor Gray
        }
    }
}

# 4. Validar controladores ODBC y cliente SQL Server
Write-Host ''
Write-Host '[3/5] Verificando Controladores ODBC de SQL Server...' -ForegroundColor Yellow
$odbcDrivers = @()
$regPaths = @(
    'HKLM:\SOFTWARE\ODBC\ODBCINST.INI\ODBC Drivers',
    'HKCU:\SOFTWARE\ODBC\ODBCINST.INI\ODBC Drivers'
)
foreach ($rp in $regPaths) {
    if (Test-Path $rp) {
        $props = Get-ItemProperty -Path $rp -ErrorAction SilentlyContinue
        if ($props) {
            $props.PSObject.Properties | Where-Object { $_.Name -like '*SQL Server*' } | ForEach-Object {
                $odbcDrivers += $_.Name
            }
        }
    }
}
$odbcDrivers = $odbcDrivers | Select-Object -Unique

if ($odbcDrivers.Count -gt 0) {
    Write-Host '  Controladores ODBC detectados:' -ForegroundColor Green
    foreach ($d in $odbcDrivers) {
        Write-Host "    [OK] $d" -ForegroundColor Green
    }
} else {
    Write-Host '  [ADVERTENCIA] No se detecto ODBC Driver for SQL Server en el registro estandar.' -ForegroundColor Yellow
    Write-Host '                Si la conexion falla, instale Microsoft ODBC Driver 18 for SQL Server:' -ForegroundColor Gray
    Write-Host '                https://learn.microsoft.com/sql/connect/odbc/download-odbc-driver-for-sql-server' -ForegroundColor Gray
    $hasWarnings = $true
}

$sqlcmdCmd = Get-Command sqlcmd -ErrorAction SilentlyContinue
if ($sqlcmdCmd) {
    Write-Host "  [OK] Herramienta CLI sqlcmd disponible: $($sqlcmdCmd.Source)" -ForegroundColor Green
} else {
    Write-Host '  - sqlcmd no encontrado en PATH (opcional para el servidor web, necesario para init-test-db).' -ForegroundColor Gray
}

# 5. Gestionar archivo de configuracion de entorno (.env)
Write-Host ''
Write-Host '[4/5] Verificando Configuracion de Entorno (.env)...' -ForegroundColor Yellow
$envFile = Join-Path $repoRoot '.env'
$envExample = Join-Path $repoRoot '.env.example'

if (-not (Test-Path $envFile)) {
    if (Test-Path $envExample) {
        Write-Host '  El archivo .env no existe. Creando a partir de .env.example...' -ForegroundColor Yellow
        Copy-Item -Path $envExample -Destination $envFile
        Write-Host '  [OK] Archivo .env creado con valores predeterminados seguros para desarrollo.' -ForegroundColor Green
        Write-Host '       (Base predeterminada: TransportesSegurosSA_Test | Windows Auth)' -ForegroundColor Gray
    } else {
        Write-Host "  [ERROR] No se encontro el archivo de plantilla .env.example en $envExample." -ForegroundColor Red
        $hasErrors = $true
    }
} else {
    Write-Host '  [OK] Archivo .env preexistente detectado. Conservado intacto (no se sobrescribio).' -ForegroundColor Green
}

$envMap = Get-EnvMap -Path $envFile
$configuredDb = if ($envMap.ContainsKey('TSSA_DB_DATABASE')) { $envMap['TSSA_DB_DATABASE'] } else { 'TransportesSegurosSA_Test' }
$configuredServer = if ($envMap.ContainsKey('TSSA_DB_SERVER')) { $envMap['TSSA_DB_SERVER'] } else { 'localhost' }
$configuredUrl = if ($envMap.ContainsKey('TSSA_APP_URL')) { $envMap['TSSA_APP_URL'] } else { 'http://127.0.0.1:8001' }

Write-Host "  Base de datos configurada: $configuredDb" -ForegroundColor Gray
Write-Host "  Servidor de base de datos: $configuredServer" -ForegroundColor Gray
Write-Host "  URL local configurada:     $configuredUrl" -ForegroundColor Gray

# 6. Prueba de conectividad con SQL Server
Write-Host ''
Write-Host '[5/5] Comprobando Conectividad con Base de Datos...' -ForegroundColor Yellow
if ($SkipDbCheck) {
    Write-Host '  - Prueba de conectividad omitida por parametro -SkipDbCheck.' -ForegroundColor Gray
} else {
    $verifyScript = Join-Path $PSScriptRoot 'verify_environment.php'
    if (Test-Path $verifyScript) {
        $diagProcess = Start-Process -FilePath $resolvedPhp -ArgumentList "`"$verifyScript`"" -WorkingDirectory $repoRoot -NoNewWindow -PassThru -Wait
        if ($diagProcess.ExitCode -ne 0) {
            Write-Host '  [ADVERTENCIA] La verificacion de base de datos reporto observaciones o fallas.' -ForegroundColor Yellow
            Write-Host '                Si aun no ha inicializado la base de pruebas, ejecute:' -ForegroundColor Gray
            Write-Host '                .\tools\scripts\init-test-db.ps1' -ForegroundColor Yellow
            $hasWarnings = $true
        } else {
            Write-Host '  [OK] Conexion y contratos de base de datos verificados con exito.' -ForegroundColor Green
        }
    }
}

# Resumen final y siguientes pasos
Write-Host '====================================================================' -ForegroundColor Cyan
if ($hasErrors) {
    Write-Host 'RESULTADO: Se encontraron dependencias pendientes que deben corregirse.' -ForegroundColor Red
    Write-Host 'Por favor atienda los errores senalados arriba antes de continuar.' -ForegroundColor Yellow
    exit 1
} elseif ($hasWarnings) {
    Write-Host 'RESULTADO: Entorno base preparado CON OBSERVACIONES.' -ForegroundColor Yellow
    Write-Host 'Siguientes pasos recomendados:' -ForegroundColor Cyan
    Write-Host '  1. Inicializar la base de pruebas (si aun no existe):' -ForegroundColor White
    Write-Host '     .\tools\scripts\init-test-db.ps1' -ForegroundColor Gray
    Write-Host '  2. Iniciar el servidor local de desarrollo:' -ForegroundColor White
    Write-Host '     .\tools\scripts\start-dev.ps1' -ForegroundColor Gray
    exit 0
} else {
    Write-Host 'RESULTADO: ENTORNO COMPLETAMENTE CONFIGURADO Y CONFORME.' -ForegroundColor Green
    Write-Host 'Comandos para iniciar el trabajo:' -ForegroundColor Cyan
    Write-Host '  Iniciar servidor de desarrollo:         .\tools\scripts\start-dev.ps1' -ForegroundColor White
    Write-Host '  Verificar entorno en cualquier momento: .\tools\scripts\verify_environment.ps1' -ForegroundColor White
    exit 0
}
