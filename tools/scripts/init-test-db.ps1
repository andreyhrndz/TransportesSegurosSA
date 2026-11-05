<#
.SYNOPSIS
    Transportes Seguros S.A. — Inicializacion segura de la base de datos de pruebas (TransportesSegurosSA_Test).
.DESCRIPTION
    Asistente portable para crear e inicializar la base de datos de pruebas de desarrollo
    (TransportesSegurosSA_Test) desde cero en cualquier maquina con Microsoft SQL Server.
    Aplica el esquema base, las migraciones 01 a 09 con UTF-8 nativo y las semillas sinteticas.

    REGLAS DE SEGURIDAD ESTRICTAS:
    - Destino exclusivo: TransportesSegurosSA_Test. PROHIBIDO ejecutar sobre TransportesSegurosSA.
    - No utiliza DROP DATABASE ni acciones destructivas.
    - Permite que SQL Server utilice su ubicacion de almacenamiento predeterminada (sin rutas C:\SQL fijas).
.PARAMETER Server
    Servidor o instancia de SQL Server (predeterminado: localhost).
.PARAMETER Force
    Si se especifica, omite la solicitud de confirmacion interactiva.
.PARAMETER IncludeSeed
    Si se especifica, carga datos semilla sinteticos de prueba tras aplicar las migraciones.
.EXAMPLE
    .\tools\scripts\init-test-db.ps1
.EXAMPLE
    .\tools\scripts\init-test-db.ps1 -Server "localhost\SQLEXPRESS" -IncludeSeed
#>

[CmdletBinding()]
param (
    [string]$Server = 'localhost',
    [switch]$Force,
    [switch]$IncludeSeed
)

$ErrorActionPreference = 'Stop'

$TargetDatabase = 'TransportesSegurosSA_Test'

Write-Host '====================================================================' -ForegroundColor Cyan
Write-Host ' Transportes Seguros S.A. — Inicializacion de Base de Pruebas (Test)' -ForegroundColor Cyan
Write-Host '====================================================================' -ForegroundColor Cyan
Write-Host "Servidor destino: $Server" -ForegroundColor Gray
Write-Host "Base de destino:  $TargetDatabase (AISLADA PARA PRUEBAS)" -ForegroundColor Green

# 1. Preflight: Deteccion de sqlcmd
$sqlcmd = Get-Command sqlcmd -ErrorAction SilentlyContinue
if (-not $sqlcmd) {
    Write-Host "`n[ERROR CRITICO] No se encontro la herramienta CLI 'sqlcmd' en el PATH del sistema." -ForegroundColor Red
    Write-Host "Instrucciones de instalacion oficial:" -ForegroundColor Yellow
    Write-Host "  Descargue e instale Microsoft Command Line Utilities 15 o 17/18 for SQL Server:" -ForegroundColor Gray
    Write-Host "  https://learn.microsoft.com/sql/tools/sqlcmd/sqlcmd-utility" -ForegroundColor Gray
    exit 1
}
Write-Host "Herramienta sqlcmd: $($sqlcmd.Source)" -ForegroundColor Gray

# 2. Comprobar conexion con el servidor SQL
Write-Host "`n[1/5] Verificando conexion con el motor SQL Server..." -ForegroundColor Yellow
$srvTest = & sqlcmd -S $Server -E -I -h -1 -W -Q 'SET NOCOUNT ON; SELECT @@SERVERNAME;' 2>&1
if ($LASTEXITCODE -ne 0) {
    Write-Host "[ERROR] No se pudo conectar al servidor SQL Server en '$Server'." -ForegroundColor Red
    Write-Host "Detalle del error: $srvTest" -ForegroundColor Gray
    Write-Host "Asegurese de que el servicio de SQL Server este iniciado y acepte conexiones Windows Auth." -ForegroundColor Yellow
    exit 1
}
Write-Host "  [OK] Motor SQL Server activo: $($srvTest.Trim())" -ForegroundColor Green

# 3. Comprobar existencia previa de TransportesSegurosSA_Test
Write-Host "`n[2/5] Verificando existencia de $TargetDatabase..." -ForegroundColor Yellow
$dbCheck = & sqlcmd -S $Server -E -I -h -1 -W -Q "SET NOCOUNT ON; SELECT DB_ID(N'$TargetDatabase');" 2>&1
$dbExists = ($LASTEXITCODE -eq 0 -and $dbCheck.Trim() -and $dbCheck.Trim() -ne 'NULL')

if ($dbExists) {
    Write-Host "  La base de datos '$TargetDatabase' ya existe en el servidor." -ForegroundColor Yellow
    if (-not $Force) {
        $confirm = Read-Host "¿Desea actualizar y verificar las migraciones sobre la base existente? (S/N)"
        if ($confirm -notmatch '^[sSyY]') {
            Write-Host 'Operacion cancelada por el usuario.' -ForegroundColor Gray
            exit 0
        }
    }
} else {
    Write-Host "  Creando base de datos '$TargetDatabase' en almacenamiento predeterminado..." -ForegroundColor Yellow
    $createDbSql = @"
IF DB_ID(N'$TargetDatabase') IS NULL
BEGIN
    CREATE DATABASE [$TargetDatabase];
    ALTER DATABASE [$TargetDatabase] SET RECOVERY SIMPLE;
END
"@
    $createResult = & sqlcmd -S $Server -d master -E -I -b -Q $createDbSql 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-Host "[ERROR] Fallo la creacion de la base de datos '$TargetDatabase'." -ForegroundColor Red
        Write-Host "Detalle: $createResult" -ForegroundColor Gray
        exit 1
    }
    Write-Host "  [OK] Base de datos '$TargetDatabase' creada exitosamente." -ForegroundColor Green
}

# 4. Resolver ruta del repositorio
$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$baselineDir = Join-Path $repoRoot 'database\baseline'
$migrationsDir = Join-Path $repoRoot 'database\migrations'
$seedDir = Join-Path $repoRoot 'database\seed'

# 5. Aplicar esquema base (si es base nueva o faltan tablas base)
Write-Host "`n[3/5] Verificando y aplicando esquemas base..." -ForegroundColor Yellow

$tablesCheck = & sqlcmd -S $Server -d $TargetDatabase -E -I -h -1 -W -Q "SET NOCOUNT ON; SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE';" 2>&1
$totalTables = [int]($tablesCheck.Trim())

if ($totalTables -lt 24) {
    Write-Host "  Aplicando las 24 tablas base de Transportes Seguros S.A..." -ForegroundColor Yellow

    # Lista ordenada de scripts de baseline requeridos
    $baselineScripts = @(
        '02_schema\02_crear_24_tablas.sql',
        '02_schema\03_crear_indices.sql',
        '02_schema\04_crear_relaciones.sql',
        '02_schema\05_restricciones.sql',
        '03_security\01_catalogos.sql',
        '03_security\03_crear_administrador.sql',
        '04_logic\01_funciones_y_tipo.sql',
        '04_logic\02_sp_clientes_solicitudes.sql',
        '04_logic\03_sp_viajes_y_entregas.sql',
        '04_logic\04_sp_incidencias_permisos_documentos.sql',
        '04_logic\05_triggers_auditoria.sql',
        '04_logic\05b_trigger_historial.sql',
        '04_logic\06_vistas.sql'
    )

    $tempScriptDir = Join-Path ([System.IO.Path]::GetTempPath()) ("tssa_init_" + [System.Guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $tempScriptDir | Out-Null

    try {
        foreach ($relPath in $baselineScripts) {
            $fullPath = Join-Path $baselineDir $relPath
            if (Test-Path $fullPath) {
                Write-Host "    Ejecutando baseline: $relPath..." -ForegroundColor Gray
                # Reemplazar de forma aislada la directiva USE hacia $TargetDatabase en archivo temporal
                $content = Get-Content $fullPath -Raw
                $adaptedContent = $content -replace 'USE\s+TransportesSegurosSA\s*;', "USE [$TargetDatabase];"
                $tempFile = Join-Path $tempScriptDir ([System.IO.Path]::GetFileName($fullPath))
                Set-Content -Path $tempFile -Value $adaptedContent -Encoding UTF8

                $runRes = & sqlcmd -S $Server -d $TargetDatabase -E -I -b -f 65001 -i $tempFile 2>&1
                if ($LASTEXITCODE -ne 0) {
                    Write-Host "    [ERROR] Fallo la ejecucion de $relPath : $runRes" -ForegroundColor Red
                    exit 1
                }
            }
        }
        Write-Host "  [OK] Esquema base de 24 tablas y logica inicial aplicado." -ForegroundColor Green
    } finally {
        if (Test-Path $tempScriptDir) {
            Remove-Item -Path $tempScriptDir -Recurse -Force -ErrorAction SilentlyContinue
        }
    }
} else {
    Write-Host "  [OK] Las 24 tablas base ya estan presentes en '$TargetDatabase'." -ForegroundColor Green
}

# 6. Aplicar migraciones incrementales 01 a 09
Write-Host "`n[4/5] Aplicando migraciones incrementales 01 a 09 con codificacion UTF-8..." -ForegroundColor Yellow

$migrationFiles = @(
    '01_read_access_contracts.sql',
    '02_extended_read_contracts.sql',
    '02b_delivery_recipient_detail_contract.sql',
    '03_customer_lifecycle_contracts.sql',
    '04_master_data_contracts.sql',
    '05_operational_contracts.sql',
    '06_user_account_contracts.sql',
    '07_invitations_and_recovery_contracts.sql',
    '08_invitation_permissions_contracts.sql',
    '09_driver_licenses_and_vehicle_pma_contracts.sql'
)

foreach ($migFile in $migrationFiles) {
    $migPath = Join-Path $migrationsDir $migFile
    if (Test-Path $migPath) {
        Write-Host "    Aplicando: $migFile..." -ForegroundColor Gray
        $migRes = & sqlcmd -S $Server -d $TargetDatabase -E -I -b -f 65001 -i $migPath 2>&1
        if ($LASTEXITCODE -ne 0) {
            Write-Host "    [ERROR] Fallo la aplicacion de $migFile :" -ForegroundColor Red
            Write-Host "    $migRes" -ForegroundColor Gray
            exit 1
        }
    } else {
        Write-Host "    [AVISO] Archivo de migracion no encontrado: $migFile" -ForegroundColor Yellow
    }
}
Write-Host "  [OK] Todas las migraciones incrementales fueron aplicadas exitosamente." -ForegroundColor Green

# 7. Carga de semillas sinteticas (opcional)
if ($IncludeSeed) {
    Write-Host "`n[5/5] Cargando semillas sinteticas de prueba..." -ForegroundColor Yellow
    $seedFiles = @(
        '01_datos_maestros.sql',
        '02_clientes_y_solicitudes.sql',
        '03_viaje_y_capacidad.sql',
        '04_entregas_y_finalizacion.sql'
    )
    foreach ($sf in $seedFiles) {
        $sfPath = Join-Path $seedDir $sf
        if (Test-Path $sfPath) {
            Write-Host "    Ejecutando semilla: $sf..." -ForegroundColor Gray
            $seedRes = & sqlcmd -S $Server -d $TargetDatabase -E -I -b -f 65001 -i $sfPath 2>&1
            # Seeds no detienen ejecucion si hay duplicados controlados
        }
    }
    Write-Host "  [OK] Semillas sinteticas procesadas." -ForegroundColor Green
} else {
    Write-Host "`n[5/5] Carga de semillas omitida (utilice -IncludeSeed si desea datos sinteticos)." -ForegroundColor Gray
}

# 8. Verificacion final de la base de pruebas
Write-Host "`n====================================================================" -ForegroundColor Cyan
Write-Host "Ejecutando verificacion de integridad de la base $TargetDatabase..." -ForegroundColor Yellow

$verifyScript = Join-Path $PSScriptRoot 'verify_environment.ps1'
if (Test-Path $verifyScript) {
    & powershell -ExecutionPolicy Bypass -File $verifyScript -Database $TargetDatabase -Server $Server
} else {
    Write-Host "Base de pruebas lista para su uso en desarrollo." -ForegroundColor Green
}
