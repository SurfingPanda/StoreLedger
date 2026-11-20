$ErrorActionPreference = 'Stop'
$projectDirectory = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$phpExecutable = (Get-Command php -ErrorAction Stop).Source
Push-Location -LiteralPath $projectDirectory
try {
    & $phpExecutable artisan backup:run --scheduled
    $backupExitCode = $LASTEXITCODE
} finally { Pop-Location }
exit $backupExitCode
