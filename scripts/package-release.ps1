# Builds deployable zip for WordPress host upload and prepares release metadata.
# Usage: powershell -NoProfile -ExecutionPolicy Bypass -File scripts/package-release.ps1
#
# IMPORTANT: Do not use Compress-Archive — it stores Windows backslashes in entry
# names, so Linux/cPanel unzip creates flat files like "ai-exporter\builders\x.php".
# This script writes ZIP entries with forward-slash paths (ZIP spec / Unix unzip).

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
Set-Location $root

$configText = Get-Content -Raw (Join-Path $root 'config.php')
if ($configText -notmatch "'version'\s*=>\s*'([^']+)'") {
    throw 'Could not read version from config.php'
}
$version = $Matches[1]
$sha = (git rev-parse --short HEAD).Trim()
$tag = "v$version-$sha"
$zipName = 'ai-exporter.zip'
$distDir = Join-Path $root 'dist'
$stageDir = Join-Path $distDir 'ai-exporter'
$zipPath = Join-Path $distDir $zipName

if (Test-Path $distDir) {
    Remove-Item -Recurse -Force $distDir
}
New-Item -ItemType Directory -Path $stageDir | Out-Null

$include = @(
    'index.php',
    'config.php',
    'helpers.php',
    'README.md',
    'prompt-fa.md',
    'prompt-en.md',
    'assets',
    'builders',
    'exporters',
    'helpers',
    'repositories',
    'views',
    'writers',
    'input'
)

foreach ($item in $include) {
    $src = Join-Path $root $item
    if (-not (Test-Path $src)) { continue }
    $dest = Join-Path $stageDir $item
    if (Test-Path $src -PathType Container) {
        Copy-Item -Recurse -Force $src $dest
    } else {
        Copy-Item -Force $src $dest
    }
}

function Add-ZipTree {
    param(
        [Parameter(Mandatory = $true)][string]$SourceDir,
        [Parameter(Mandatory = $true)][string]$ZipPath,
        [Parameter(Mandatory = $true)][string]$RootEntryName
    )

    if (Test-Path $ZipPath) { Remove-Item -Force $ZipPath }

    $zip = [System.IO.Compression.ZipFile]::Open(
        $ZipPath,
        [System.IO.Compression.ZipArchiveMode]::Create
    )
    try {
        $sourceFull = (Resolve-Path $SourceDir).Path.TrimEnd('\', '/')
        $files = Get-ChildItem -Path $sourceFull -Recurse -File
        foreach ($file in $files) {
            $relative = $file.FullName.Substring($sourceFull.Length).TrimStart('\', '/')
            $entryName = ($RootEntryName + '/' + ($relative -replace '\\', '/')) -replace '/+', '/'
            [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $zip,
                $file.FullName,
                $entryName,
                [System.IO.Compression.CompressionLevel]::Optimal
            )
        }
    }
    finally {
        $zip.Dispose()
    }
}

Add-ZipTree -SourceDir $stageDir -ZipPath $zipPath -RootEntryName 'ai-exporter'

# Sanity check: entry names must use / not \
$check = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
try {
    $bad = @($check.Entries | Where-Object { $_.FullName -match '\\' })
    $sample = @($check.Entries | Select-Object -First 5 -ExpandProperty FullName)
    if ($bad.Count -gt 0) {
        throw "Zip still contains backslash entry names (e.g. $($bad[0].FullName))"
    }
    if ($sample.Count -eq 0) {
        throw 'Zip is empty'
    }
}
finally {
    $check.Dispose()
}

Write-Output "ZIP=$zipPath"
Write-Output "TAG=$tag"
Write-Output "VERSION=$version"
Write-Output "SHA=$sha"
Write-Output "SAMPLE_ENTRIES=$($sample -join ' | ')"
