# ==============================================================================
# Transportes Seguros S.A. — Verificación de Entorno (PowerShell)
# ==============================================================================

[CmdletBinding()]
param (
    [string]$PhpPath = 'C:\AdmBD2026\Herramientas\PHP\8.4\php.exe',
    [string]$Server = 'localhost',
    [string]$Database = 'TransportesSegurosSA_Test'
)

Write-Host '====================================================================' -ForegroundColor Cyan
Write-Host 'Transportes Seguros S.A. — Verificación del Entorno de Herramientas' -ForegroundColor Cyan
Write-Host '====================================================================' -ForegroundColor Cyan

$hasErrors = $false

# 1. Comprobar ejecutable de PHP
if (-not (Test-Path $PhpPath)) {
    Write-Host "No se encontró el ejecutable en: $PhpPath" -ForegroundColor Red
    $hasErrors = $true
} else {
    $phpVer = & $PhpPath -r 'echo PHP_VERSION;'
    Write-Host "PHP Ejecutable: $PhpPath (Version $phpVer)" -ForegroundColor Green
    if ([System.Version]$phpVer -lt [System.Version]'8.4.0') {
        Write-Host '  ERROR: Se requiere PHP 8.4 o superior.' -ForegroundColor Red
        $hasErrors = $true
    } else {
        Write-Host '  OK: Version PHP 8.4 conforme.' -ForegroundColor Green
    }
}

# 2. Comprobar script PHP de diagnóstico
$diagScript = Join-Path $PSScriptRoot 'verify_environment.php'
if (Test-Path $diagScript) {
    Write-Host "`nEjecutando diagnóstico interno de extensiones y base de datos..." -ForegroundColor Yellow
    & $PhpPath $diagScript
    if ($LASTEXITCODE -ne 0) {
        $hasErrors = $true
    }
} else {
    Write-Host "No se encontró el script verify_environment.php en: $diagScript" -ForegroundColor Red
    $hasErrors = $true
}

# 3. Comprobar conectividad ODBC / SQL Server por sqlcmd
Write-Host "`n[SQL Server CLI]" -ForegroundColor Cyan
$sqlcmd = Get-Command sqlcmd -ErrorAction SilentlyContinue
if ($sqlcmd) {
    Write-Host "  sqlcmd disponible en: $($sqlcmd.Source)" -ForegroundColor Green
    $sqlVer = sqlcmd -S $Server -d $Database -E -h -1 -W -Q 'SET NOCOUNT ON; SELECT 1;' 2>&1
    if ($LASTEXITCODE -eq 0) {
        Write-Host "  OK: Conexión exitosa a $Database mediante Windows Auth." -ForegroundColor Green
    } else {
        Write-Host "  ERROR al conectar a SQL Server con sqlcmd: $sqlVer" -ForegroundColor Red
        $hasErrors = $true
    }
} else {
    Write-Host '  sqlcmd no está en el PATH del sistema.' -ForegroundColor Yellow
}

Write-Host "`n====================================================================" -ForegroundColor Cyan
if ($hasErrors) {
    Write-Host 'FALLO: Existen dependencias o configuraciones pendientes.' -ForegroundColor Red
    exit 1
} else {
    Write-Host 'ÉXITO: Todas las herramientas y dependencias están verificadas.' -ForegroundColor Green
    exit 0
}
