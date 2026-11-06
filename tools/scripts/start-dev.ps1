<#
.SYNOPSIS
    Transportes Seguros S.A. — Inicio del servidor web local de desarrollo.
.DESCRIPTION
    Inicia el servidor web integrado de PHP apuntando al directorio publico de la aplicacion,
    con validacion previa de puerto, variables de entorno (.env) y base de datos configurada.
    Puede ejecutarse desde cualquier directorio.
.PARAMETER PhpPath
    Ruta explicita al ejecutable php.exe. Si se omite, se detecta automaticamente.
.PARAMETER Port
    Puerto TCP local donde escuchara el servidor (predeterminado: 8001).
.PARAMETER HostAddress
    Direccion IP o interfaz de red local (predeterminada: 127.0.0.1).
.PARAMETER Database
    Nombre explicito de la base de datos de desarrollo (predeterminada: TransportesSegurosSA_Test).
.EXAMPLE
    .\tools\scripts\start-dev.ps1
.EXAMPLE
    .\tools\scripts\start-dev.ps1 -Port 8002
#>

[CmdletBinding()]
param (
    [string]$PhpPath = '',
    [int]$Port = 8001,
    [string]$HostAddress = '127.0.0.1',
    [string]$Database = ''
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

function Test-PortAvailability {
    param (
        [string]$Ip,
        [int]$TargetPort
    )
    $listener = $null
    try {
        $ipObj = [System.Net.IPAddress]::Parse($Ip)
        $listener = New-Object System.Net.Sockets.TcpListener($ipObj, $TargetPort)
        $listener.Start()
        return $true
    } catch {
        return $false
    } finally {
        if ($listener) {
            $listener.Stop()
        }
    }
}

# 1. Resolver rutas canonicas
$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$publicDir = Join-Path $repoRoot 'public'

if (-not (Test-Path $publicDir)) {
    Write-Host "[ERROR] No se encontro el directorio publico en: $publicDir" -ForegroundColor Red
    exit 1
}

# 2. Localizar PHP
$resolvedPhp = Resolve-PhpExecutable -ExplicitPath $PhpPath -Root $repoRoot

if (-not $resolvedPhp) {
    Write-Host '[ERROR] No se encontro el ejecutable de PHP 8.4.' -ForegroundColor Red
    Write-Host 'Ejecute primero el asistente de preparacion:' -ForegroundColor Yellow
    Write-Host '  .\tools\scripts\setup-dev.ps1' -ForegroundColor Gray
    exit 1
}

# 3. Validar existencia y configuracion de .env
$envFile = Join-Path $repoRoot '.env'
if (-not (Test-Path $envFile)) {
    Write-Host '[ERROR] El archivo de configuracion .env no existe en la raiz del proyecto.' -ForegroundColor Red
    Write-Host 'Para crearlo con la configuracion predeterminada segura, ejecute:' -ForegroundColor Yellow
    Write-Host '  .\tools\scripts\setup-dev.ps1' -ForegroundColor Gray
    exit 1
}

$envMap = Get-EnvMap -Path $envFile
$targetDb = if ($Database) { $Database } elseif ($envMap.ContainsKey('TSSA_DB_DATABASE')) { $envMap['TSSA_DB_DATABASE'] } else { 'TransportesSegurosSA_Test' }
$appUrl = if ($envMap.ContainsKey('TSSA_APP_URL')) { $envMap['TSSA_APP_URL'] } else { "http://$HostAddress`:$Port" }

# Asegurar que no se ejecute contra bases no permitidas
if ($targetDb -notin @('TransportesSegurosSA_Test', 'TransportesSegurosSA')) {
    Write-Host "[ERROR] Base de datos no permitida: '$targetDb'." -ForegroundColor Red
    Write-Host 'Las bases validas son: TransportesSegurosSA_Test (recomendada) o TransportesSegurosSA.' -ForegroundColor Yellow
    exit 1
}

# Advertir si TSSA_APP_URL no coincide con el puerto local
$expectedUrl = "http://$HostAddress`:$Port"
if ($appUrl -ne $expectedUrl -and -not $appUrl.Contains(":$Port")) {
    Write-Host "[AVISO DE CONFIGURACION] TSSA_APP_URL en .env esta configurado como: $appUrl" -ForegroundColor Yellow
    Write-Host "pero el servidor local escuchara en: $expectedUrl" -ForegroundColor Yellow
    Write-Host 'Si los enlaces de activacion/recuperacion fallan, actualice TSSA_APP_URL en su .env.' -ForegroundColor Gray
}

# 4. Comprobar disponibilidad del puerto
if (-not (Test-PortAvailability -Ip $HostAddress -TargetPort $Port)) {
    Write-Host "[ERROR] El puerto TCP $Port en $HostAddress ya esta en uso por otro proceso." -ForegroundColor Red
    Write-Host 'Acciones posibles:' -ForegroundColor Yellow
    Write-Host "  1. Utilice un puerto diferente:  .\tools\scripts\start-dev.ps1 -Port $($Port + 1)" -ForegroundColor Gray
    Write-Host "  2. Identifique el proceso en uso con: Get-NetTCPConnection -LocalPort $Port" -ForegroundColor Gray
    exit 1
}

# 5. Configurar variables de entorno del proceso
$env:TSSA_DB_DATABASE = $targetDb

$listenTarget = "$HostAddress`:$Port"

Write-Host '====================================================================' -ForegroundColor Cyan
Write-Host ' Transportes Seguros S.A. — Servidor de Desarrollo Activo           ' -ForegroundColor Cyan
Write-Host '====================================================================' -ForegroundColor Cyan
Write-Host "  URL Local:           http://$listenTarget" -ForegroundColor Green
Write-Host "  Directorio Publico:  $publicDir" -ForegroundColor Gray
Write-Host "  Base de Datos:       $targetDb" -ForegroundColor Green
Write-Host "  PHP Runtime:         $resolvedPhp" -ForegroundColor Gray
Write-Host ''
Write-Host 'Presione [Ctrl + C] para detener el servidor de manera segura.' -ForegroundColor Yellow
Write-Host '====================================================================' -ForegroundColor Cyan
Write-Host ''

# 6. Iniciar servidor web de PHP
try {
    & $resolvedPhp -S $listenTarget -t $publicDir
} catch {
    Write-Host ''
    Write-Host '[INFO] Servidor web finalizado.' -ForegroundColor Gray
}
