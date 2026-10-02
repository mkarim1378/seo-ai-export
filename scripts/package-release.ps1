# Builds deployable zip for WordPress host upload and prepares release metadata.
# Usage: pwsh -File scripts/package-release.ps1

$ErrorActionPreference = 'Stop'
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
    'writers'
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

if (Test-Path $zipPath) { Remove-Item -Force $zipPath }
Compress-Archive -Path $stageDir -DestinationPath $zipPath -Force

Write-Output "ZIP=$zipPath"
Write-Output "TAG=$tag"
Write-Output "VERSION=$version"
Write-Output "SHA=$sha"
