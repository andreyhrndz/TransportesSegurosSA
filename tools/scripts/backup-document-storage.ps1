<#
.SYNOPSIS
    Respaldo, verificación SHA-256 y restauración portable del almacén privado de documentos.

.DESCRIPTION
    Herramienta manual y segura para respaldar los archivos binarios (PDF, JPG, PNG)
    guardados fuera de public/ por Transportes Seguros S.A., generar su manifiesto
    SHA-256 y verificar o restaurar copias.

    IMPORTANTE:
    - Los respaldos FULL/DIFF de SQL Server NO incluyen estos archivos en disco.
    - Este script NO programa tareas en Windows, NO ejecuta Jobs de SQL Server Agent,
      NO modifica bases de datos y NO sube archivos a la nube.
    - Admite -DryRun y -WhatIf para simular operaciones sin escribir en disco.

.EXAMPLE
    .\tools\scripts\backup-document-storage.ps1 -DestinationRoot "C:\SQL\Backups\Documentos" -DryRun

.EXAMPLE
    .\tools\scripts\backup-document-storage.ps1 -DestinationRoot "C:\SQL\Backups\Documentos"

.EXAMPLE
    .\tools\scripts\backup-document-storage.ps1 -VerifyOnly -BackupDir "C:\SQL\Backups\Documentos\documents_20261120_110000"

.EXAMPLE
    .\tools\scripts\backup-document-storage.ps1 -Restore -BackupDir "C:\SQL\Backups\Documentos\documents_20261120_110000" -RestoreTargetDir "C:\Temp\restore_check"
#>
[CmdletBinding(SupportsShouldProcess = $true)]
param(
    [string]$SourceDir = '',
    [string]$DestinationRoot = '',
    [string]$BackupDir = '',
    [string]$RestoreTargetDir = '',
    [switch]$VerifyOnly,
    [switch]$Restore,
    [switch]$DryRun,
    [switch]$Force
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Resolve-RepoRoot {
    return (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
}

function Get-ConfiguredStorageDir {
    param([string]$RepoRoot, [string]$OverrideDir)

    if (-not [string]::IsNullOrWhiteSpace($OverrideDir)) {
        return $OverrideDir.Trim()
    }
    if (-not [string]::IsNullOrWhiteSpace($env:TSSA_DOCUMENT_STORAGE_DIR)) {
        return $env:TSSA_DOCUMENT_STORAGE_DIR.Trim()
    }

    $envFile = Join-Path $RepoRoot '.env'
    if (Test-Path -LiteralPath $envFile) {
        foreach ($rawLine in Get-Content -LiteralPath $envFile -Encoding UTF8) {
            $line = $rawLine.Trim()
            if ($line -eq '' -or $line.StartsWith('#')) { continue }
            if ($line -match '^TSSA_DOCUMENT_STORAGE_DIR\s*=\s*(.*)$') {
                $val = $Matches[1].Trim().Trim('"').Trim("'")
                if (-not [string]::IsNullOrWhiteSpace($val)) {
                    return $val
                }
            }
        }
    }

    return (Join-Path $RepoRoot 'storage\private\documents')
}

function Assert-OutsidePublic {
    param([string]$PathToCheck, [string]$RepoRoot)
    $publicDir = [System.IO.Path]::GetFullPath((Join-Path $RepoRoot 'public')).TrimEnd('\', '/') + '\'
    $candidate = [System.IO.Path]::GetFullPath($PathToCheck).TrimEnd('\', '/') + '\'
    if ($candidate.StartsWith($publicDir, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Ruta rechazada por seguridad: no se permite usar una carpeta dentro de public/ ($PathToCheck)."
    }
}

function Get-DocumentFiles {
    param([string]$RootDir)
    if (-not (Test-Path -LiteralPath $RootDir)) {
        return @()
    }
    $resolvedRoot = (Resolve-Path -LiteralPath $RootDir).Path.TrimEnd('\', '/')
    $items = Get-ChildItem -LiteralPath $resolvedRoot -Recurse -File -Force |
        Where-Object {
            $_.Name -ne '.gitkeep' -and
            $_.Name -ne 'manifest.sha256.json' -and
            $_.Name -ne 'manifest.sha256.txt' -and
            -not $_.Attributes.HasFlag([System.IO.FileAttributes]::ReparsePoint)
        } |
        Sort-Object FullName

    $result = @()
    foreach ($file in $items) {
        $rel = $file.FullName.Substring($resolvedRoot.Length).TrimStart('\', '/').Replace('\', '/')
        $result += [PSCustomObject]@{
            FullName     = $file.FullName
            RelativePath = $rel
            Bytes        = [int64]$file.Length
        }
    }
    return $result
}

function Test-ManifestIntegrity {
    param(
        [string]$ManifestPath,
        [string]$TargetDataDir
    )
    if (-not (Test-Path -LiteralPath $ManifestPath)) {
        throw "No se encontró el manifiesto SHA-256 en: $ManifestPath"
    }
    if (-not (Test-Path -LiteralPath $TargetDataDir)) {
        throw "No se encontró el directorio objetivo a verificar: $TargetDataDir"
    }

    $manifest = Get-Content -LiteralPath $ManifestPath -Raw -Encoding UTF8 | ConvertFrom-Json
    $entries = @($manifest.files)
    $ok = 0
    $errors = New-Object System.Collections.Generic.List[string]

    foreach ($entry in $entries) {
        $rel = [string]$entry.relative_path
        if ($rel -match '(^|/)\.\.(/|$)' -or $rel -match '^[A-Za-z]:' -or $rel.StartsWith('/')) {
            $errors.Add("Ruta insegura en manifiesto: $rel")
            continue
        }
        $localPath = Join-Path $TargetDataDir ($rel.Replace('/', '\'))
        if (-not (Test-Path -LiteralPath $localPath -PathType Leaf)) {
            $errors.Add("Falta archivo: $rel")
            continue
        }
        $info = Get-Item -LiteralPath $localPath
        if ($info.Length -ne [int64]$entry.bytes) {
            $errors.Add("Tamaño distinto en ${rel}: esperado=$($entry.bytes), actual=$($info.Length)")
            continue
        }
        $actualHash = (Get-FileHash -LiteralPath $localPath -Algorithm SHA256).Hash.ToLowerInvariant()
        if ($actualHash -ne ([string]$entry.sha256).ToLowerInvariant()) {
            $errors.Add("SHA-256 no coincide en ${rel}")
            continue
        }
        $ok++
    }

    $expectedMap = @{}
    foreach ($entry in $entries) { $expectedMap[[string]$entry.relative_path] = $true }
    $actualFiles = @(Get-DocumentFiles -RootDir $TargetDataDir)
    foreach ($af in $actualFiles) {
        if (-not $expectedMap.ContainsKey($af.RelativePath)) {
            $errors.Add("Archivo adicional no registrado en manifiesto: $($af.RelativePath)")
        }
    }

    if ($errors.Count -gt 0) {
        Write-Error ("Verificación SHA-256 FALLIDA ($ok/$($entries.Count) válidos, $($errors.Count) errores):`n - " + ($errors -join "`n - "))
        exit 2
    }

    Write-Output "[OK] Verificación SHA-256 exitosa: $ok/$($entries.Count) archivo(s) íntegros en '$TargetDataDir'."
    exit 0
}

$repoRoot = Resolve-RepoRoot

# Modo 1: Solo verificación de respaldo o destino restaurado
if ($VerifyOnly) {
    if ([string]::IsNullOrWhiteSpace($BackupDir)) {
        throw "Con -VerifyOnly debe indicar -BackupDir."
    }
    $manifestPath = Join-Path $BackupDir 'manifest.sha256.json'
    $targetDir = if (-not [string]::IsNullOrWhiteSpace($RestoreTargetDir)) { $RestoreTargetDir } else { Join-Path $BackupDir 'files' }
    Test-ManifestIntegrity -ManifestPath $manifestPath -TargetDataDir $targetDir
}

# Modo 2: Restauración controlada + verificación SHA-256 automática
if ($Restore) {
    if ([string]::IsNullOrWhiteSpace($BackupDir) -or [string]::IsNullOrWhiteSpace($RestoreTargetDir)) {
        throw "Con -Restore debe indicar -BackupDir y -RestoreTargetDir."
    }
    Assert-OutsidePublic -PathToCheck $RestoreTargetDir -RepoRoot $repoRoot

    $manifestPath = Join-Path $BackupDir 'manifest.sha256.json'
    $backupFilesDir = Join-Path $BackupDir 'files'
    if (-not (Test-Path -LiteralPath $manifestPath)) {
        throw "No existe manifest.sha256.json en '$BackupDir'."
    }
    if (-not (Test-Path -LiteralPath $backupFilesDir)) {
        throw "No existe la subcarpeta 'files' en '$BackupDir'."
    }

    $manifest = Get-Content -LiteralPath $manifestPath -Raw -Encoding UTF8 | ConvertFrom-Json
    $entries = @($manifest.files)

    $existing = @(Get-DocumentFiles -RootDir $RestoreTargetDir)
    if ($existing.Count -gt 0 -and -not $Force) {
        throw "El destino '$RestoreTargetDir' ya contiene $($existing.Count) archivo(s). Use -Force solo si desea sobrescribir."
    }

    if ($DryRun -or -not $PSCmdlet.ShouldProcess($RestoreTargetDir, "Restaurar $($entries.Count) archivo(s) desde '$BackupDir'")) {
        Write-Output "[DRY-RUN] Se restaurarían $($entries.Count) archivo(s) desde '$backupFilesDir' hacia '$RestoreTargetDir' y se validaría SHA-256."
        exit 0
    }

    New-Item -ItemType Directory -Path $RestoreTargetDir -Force | Out-Null
    foreach ($entry in $entries) {
        $rel = [string]$entry.relative_path
        if ($rel -match '(^|/)\.\.(/|$)' -or $rel -match '^[A-Za-z]:' -or $rel.StartsWith('/')) {
            throw "Ruta insegura detectada en manifiesto: $rel"
        }
        $src = Join-Path $backupFilesDir ($rel.Replace('/', '\'))
        $dst = Join-Path $RestoreTargetDir ($rel.Replace('/', '\'))
        $dstParent = Split-Path -Path $dst -Parent
        if (-not (Test-Path -LiteralPath $dstParent)) {
            New-Item -ItemType Directory -Path $dstParent -Force | Out-Null
        }
        Copy-Item -LiteralPath $src -Destination $dst -Force
    }

    Test-ManifestIntegrity -ManifestPath $manifestPath -TargetDataDir $RestoreTargetDir
}

# Modo 3 (predeterminado): Respaldo + manifiesto SHA-256
$resolvedSource = Get-ConfiguredStorageDir -RepoRoot $repoRoot -OverrideDir $SourceDir
Assert-OutsidePublic -PathToCheck $resolvedSource -RepoRoot $repoRoot

if ([string]::IsNullOrWhiteSpace($DestinationRoot)) {
    throw "Debe especificar -DestinationRoot fuera del repositorio (ej.: 'C:\SQL\Backups\Documentos')."
}
Assert-OutsidePublic -PathToCheck $DestinationRoot -RepoRoot $repoRoot

if (-not (Test-Path -LiteralPath $resolvedSource)) {
    throw "No existe el directorio origen del almacén de documentos: $resolvedSource"
}

$files = @(Get-DocumentFiles -RootDir $resolvedSource)
$totalBytes = ($files | Measure-Object -Property Bytes -Sum).Sum
if ($null -eq $totalBytes) { $totalBytes = 0 }

$stamp = (Get-Date).ToUniversalTime().ToString('yyyyMMdd_HHmmss')
$targetBackupDir = Join-Path $DestinationRoot ("documents_" + $stamp)
$targetFilesDir = Join-Path $targetBackupDir 'files'

if ($DryRun -or -not $PSCmdlet.ShouldProcess($targetBackupDir, "Respaldar $($files.Count) archivo(s) ($totalBytes bytes) con manifiesto SHA-256")) {
    Write-Output "[DRY-RUN] Origen : $resolvedSource"
    Write-Output "[DRY-RUN] Destino: $targetBackupDir"
    Write-Output "[DRY-RUN] Archivos a copiar: $($files.Count) ($totalBytes bytes)."
    exit 0
}

New-Item -ItemType Directory -Path $targetFilesDir -Force | Out-Null

$manifestFiles = New-Object System.Collections.Generic.List[object]
$shaLines = New-Object System.Collections.Generic.List[string]

foreach ($f in $files) {
    $dst = Join-Path $targetFilesDir ($f.RelativePath.Replace('/', '\'))
    $dstParent = Split-Path -Path $dst -Parent
    if (-not (Test-Path -LiteralPath $dstParent)) {
        New-Item -ItemType Directory -Path $dstParent -Force | Out-Null
    }
    Copy-Item -LiteralPath $f.FullName -Destination $dst -Force
    $hash = (Get-FileHash -LiteralPath $dst -Algorithm SHA256).Hash.ToLowerInvariant()
    $manifestFiles.Add([ordered]@{
        relative_path = $f.RelativePath
        bytes         = $f.Bytes
        sha256        = $hash
    })
    $shaLines.Add("$hash  files/$($f.RelativePath)")
}

$manifestObj = [ordered]@{
    schema_version = 1
    generated_utc  = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
    source_dir     = (Resolve-Path -LiteralPath $resolvedSource).Path
    file_count     = $files.Count
    total_bytes    = [int64]$totalBytes
    files          = $manifestFiles
}

$manifestJsonPath = Join-Path $targetBackupDir 'manifest.sha256.json'
$manifestTxtPath = Join-Path $targetBackupDir 'manifest.sha256.txt'
($manifestObj | ConvertTo-Json -Depth 5) | Set-Content -LiteralPath $manifestJsonPath -Encoding UTF8
$shaLines | Set-Content -LiteralPath $manifestTxtPath -Encoding UTF8

Write-Output "[OK] Respaldo creado en '$targetBackupDir' ($($files.Count) archivo(s), $totalBytes bytes)."
Test-ManifestIntegrity -ManifestPath $manifestJsonPath -TargetDataDir $targetFilesDir
