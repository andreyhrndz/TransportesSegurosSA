<#
.SYNOPSIS
    Transportes Seguros S.A. — Verificacion del Entorno de Herramientas y Base de Datos.
.DESCRIPTION
    Script portable para diagnosticar la configuracion de PHP 8.4, extensiones de SQL Server,
    controladores ODBC y contratos de base de datos sin requerir rutas fijas ni dependencias del propietario.
    Puede ejecutarse desde cualquier directorio.
.PARAMETER PhpPath
    Ruta explicita al ejecutable php.exe. Si se omite, se detecta automaticamente.
.PARAMETER Server
    Servidor o instancia de SQL Server a verificar (predeterminado: desde .env o localhost).
.PARAMETER Database
    Base de datos a verificar (predeterminada: desde .env o TransportesSegurosSA_Test).
.EXAMPLE
    .\tools\scripts\verify_environment.ps1
.EXAMPLE
    .\tools\scripts\verify_environment.ps1 -PhpPath "C:\tools\php84\php.exe"
#>

[CmdletBinding()]
param (
    [string]$PhpPath = '',
    [string]$Server = '',
    [string]$Database = ''
)

$ErrorActionPreference = 'Stop'

# 1. Resolver raiz del repositorio Git
$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path

Write-Host '====================================================================' -ForegroundColor Cyan
Write-Host ' Transportes Seguros S.A. — Verificacion del Entorno (PowerShell)   ' -ForegroundColor Cyan
Write-Host '====================================================================' -ForegroundColor Cyan
Write-Host "Raiz del proyecto: $repoRoot" -ForegroundColor Gray

$hasErrors = $false

# 2. Localizar ejecutable de PHP 8.4
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

$resolvedPhp = Resolve-PhpExecutable -ExplicitPath $PhpPath -Root $repoRoot

if (-not $resolvedPhp) {
    Write-Host ''
    Write-Host '[ERROR] No se encontro el ejecutable de PHP 8.4.' -ForegroundColor Red
    Write-Host 'Ejecute el asistente de configuracion inicial:' -ForegroundColor Yellow
    Write-Host '  .\tools\scripts\setup-dev.ps1' -ForegroundColor Gray
    exit 1
}

$phpVer = & $resolvedPhp -r "echo PHP_VERSION;" 2>$null
Write-Host ''
Write-Host '[PHP Runtime]' -ForegroundColor Cyan
Write-Host "  Ejecutable: $resolvedPhp" -ForegroundColor Green
Write-Host "  Version:    $phpVer" -ForegroundColor Green

if ([System.Version]$phpVer -lt [System.Version]'8.4.0') {
    Write-Host '  [ERROR] Se requiere PHP 8.4 o superior.' -ForegroundColor Red
    $hasErrors = $true
}

# 3. Ejecutar diagnostico exhaustivo PHP / PDO_SQLSRV
$diagScript = Join-Path $PSScriptRoot 'verify_environment.php'
if (Test-Path $diagScript) {
    Write-Host ''
    Write-Host 'Ejecutando diagnostico interno de extensiones y contratos SQL...' -ForegroundColor Yellow
    $diagProcess = Start-Process -FilePath $resolvedPhp -ArgumentList "`"$diagScript`"" -WorkingDirectory $repoRoot -NoNewWindow -PassThru -Wait
    if ($diagProcess.ExitCode -ne 0) {
        $hasErrors = $true
    }
} else {
    Write-Host "[ERROR] No se encontro el script verify_environment.php en: $diagScript" -ForegroundColor Red
    $hasErrors = $true
}

# 4. Comprobacion opcional de conectividad SQL Server por sqlcmd
Write-Host ''
Write-Host '[SQL Server CLI - sqlcmd]' -ForegroundColor Cyan
$sqlcmdCmd = Get-Command sqlcmd -ErrorAction SilentlyContinue

if ($sqlcmdCmd) {
    Write-Host "  sqlcmd disponible en: $($sqlcmdCmd.Source)" -ForegroundColor Green

    # Obtener configuracion efectiva si no se pasaron parametros
    $envFile = Join-Path $repoRoot '.env'
    $targetDb = $Database
    $targetServer = $Server

    if (Test-Path $envFile) {
        $lines = Get-Content $envFile
        foreach ($line in $lines) {
            $trimmed = $line.Trim()
            if ($trimmed -and -not $trimmed.StartsWith('#') -and ($trimmed -match '^([^=]+)=(.*)$')) {
                $k = $matches[1].Trim()
                $v = $matches[2].Trim()
                if (-not $targetDb -and $k -eq 'TSSA_DB_DATABASE') { $targetDb = $v }
                if (-not $targetServer -and $k -eq 'TSSA_DB_SERVER') { $targetServer = $v }
            }
        }
    }
    if (-not $targetDb) { $targetDb = 'TransportesSegurosSA_Test' }
    if (-not $targetServer) { $targetServer = 'localhost' }

    $sqlCheck = sqlcmd -S $targetServer -d $targetDb -E -I -h -1 -W -Q 'SET NOCOUNT ON; SELECT DB_NAME();' 2>&1
    if ($LASTEXITCODE -eq 0 -and ($sqlCheck.Trim() -eq $targetDb)) {
        Write-Host "  [OK] Conexion CLI exitosa a '$targetDb' en '$targetServer' mediante Windows Auth." -ForegroundColor Green
    } else {
        Write-Host "  [ADVERTENCIA] Fallo consulta CLI sqlcmd contra '$targetDb': $sqlCheck" -ForegroundColor Yellow
    }
} else {
    Write-Host '  sqlcmd no esta en el PATH del sistema (opcional para el runtime de PHP).' -ForegroundColor Gray
}

Write-Host ''
Write-Host '====================================================================' -ForegroundColor Cyan
if ($hasErrors) {
    Write-Host 'RESULTADO: Se encontraron problemas en el entorno de desarrollo.' -ForegroundColor Red
    exit 1
} else {
    Write-Host 'RESULTADO: ENTORNO VERIFICADO Y CONFORME.' -ForegroundColor Green
    exit 0
}
