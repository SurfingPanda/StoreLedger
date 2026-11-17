$ErrorActionPreference = 'Stop'
$taskName = 'BW-StoreLedger-Automation'
$projectDirectory = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$phpExecutable = (Get-Command php -ErrorAction Stop).Source
$existing = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
if ($existing) {
    if ($existing.Actions.WorkingDirectory -notcontains $projectDirectory) {
        throw 'A task with this name belongs to another directory. No changes were made.'
    }
    Write-Output 'StoreLedger automation is already installed. Inspect it in Windows Task Scheduler.'
    return
}
$action = New-ScheduledTaskAction -Execute $phpExecutable -Argument 'artisan schedule:run' -WorkingDirectory $projectDirectory
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1)
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 1)
$principal = New-ScheduledTaskPrincipal -UserId ([System.Security.Principal.WindowsIdentity]::GetCurrent().Name) -LogonType Interactive -RunLevel Limited
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Description 'Runs StoreLedger scheduled email delivery, recurring billing generation, and daily Accounting reminders. Requires MySQL, network access, and this Windows user to be logged in.' | Out-Null
Write-Output 'Installed StoreLedger automation every minute. Keep MySQL running and this Windows user logged in.'
