param([switch]$RunWhenLoggedOut)
$ErrorActionPreference = 'Stop'
$taskName = 'BW-Operations-Encrypted-Backups'
$projectDirectory = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$identity = [System.Security.Principal.WindowsIdentity]::GetCurrent()
$logonType = 'InteractiveToken'
if ($RunWhenLoggedOut) {
    $principal = New-Object System.Security.Principal.WindowsPrincipal($identity)
    if (-not $principal.IsInRole([System.Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'Logged-out scheduling requires an elevated PowerShell window. Run without the switch for user-level scheduling.'
    }
    $logonType = 'S4U'
}
$existingOutput = & schtasks.exe /Query /TN $taskName /XML 2>$null
$existing = $LASTEXITCODE -eq 0
if ($existing) {
    $old = [xml]($existingOutput -join "`n")
    if (-not $old.Task.Actions.Exec.InnerText.Contains($projectDirectory)) { throw 'An existing task belongs to another project. No changes were made.' }
}
$escape = { param($value) [System.Security.SecurityElement]::Escape($value) }
$powerShell = & $escape (Join-Path $env:SystemRoot 'System32/WindowsPowerShell/v1.0/powershell.exe')
$arguments = & $escape ('-NoProfile -NonInteractive -WindowStyle Hidden -File "' + (Join-Path $PSScriptRoot 'run-backup.ps1') + '"')
$workingDirectory = & $escape $projectDirectory
$sid = $identity.User.Value
$boundary = (Get-Date).Date.AddDays(1).AddHours(2).ToString('s')
$xml = @"
<?xml version="1.0" encoding="UTF-16"?>
<Task version="1.2" xmlns="http://schemas.microsoft.com/windows/2004/02/mit/task">
  <RegistrationInfo><Description>Encrypted local BW Operations backups daily at 02:00 Windows time. Requires MySQL. Catch up after missed starts and retry failures.</Description></RegistrationInfo>
  <Triggers><CalendarTrigger><StartBoundary>$boundary</StartBoundary><Enabled>true</Enabled><ScheduleByDay><DaysInterval>1</DaysInterval></ScheduleByDay></CalendarTrigger></Triggers>
  <Principals><Principal id="Author"><UserId>$sid</UserId><LogonType>$logonType</LogonType><RunLevel>LeastPrivilege</RunLevel></Principal></Principals>
  <Settings><MultipleInstancesPolicy>IgnoreNew</MultipleInstancesPolicy><DisallowStartIfOnBatteries>false</DisallowStartIfOnBatteries><StopIfGoingOnBatteries>false</StopIfGoingOnBatteries><StartWhenAvailable>true</StartWhenAvailable><RunOnlyIfNetworkAvailable>false</RunOnlyIfNetworkAvailable><AllowStartOnDemand>true</AllowStartOnDemand><Enabled>true</Enabled><Hidden>true</Hidden><ExecutionTimeLimit>PT2H</ExecutionTimeLimit><RestartOnFailure><Interval>PT15M</Interval><Count>3</Count></RestartOnFailure></Settings>
  <Actions Context="Author"><Exec><Command>$powerShell</Command><Arguments>$arguments</Arguments><WorkingDirectory>$workingDirectory</WorkingDirectory></Exec></Actions>
</Task>
"@
$temporary = Join-Path ([System.IO.Path]::GetTempPath()) ('bw-backup-task-' + [guid]::NewGuid().ToString('N') + '.xml')
try {
    [System.IO.File]::WriteAllText($temporary, $xml, [System.Text.Encoding]::Unicode)
    $taskArguments = @('/Create', '/TN', $taskName, '/XML', $temporary)
    if ($existing) { $taskArguments += '/F' }
    & schtasks.exe @taskArguments
    if ($LASTEXITCODE -ne 0) { throw 'Windows did not register the backup task.' }
} finally { if (Test-Path -LiteralPath $temporary) { Remove-Item -LiteralPath $temporary } }
Write-Output "Installed local encrypted backups daily at 02:00 Windows time, with catch-up and retries. Logon mode: $logonType."
