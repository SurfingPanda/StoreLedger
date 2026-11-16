$ErrorActionPreference = 'Stop'
$taskName = 'BW-UTAK-Renewal-Reminders'
$projectDirectory = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$phpExecutable = (Get-Command php -ErrorAction Stop).Source
$existing = Get-ScheduledTask -ErrorAction Stop | Where-Object TaskName -EQ $taskName
if ($existing) {
    Write-Output 'The renewal reminder task already exists. Inspect it in Task Scheduler before changing it.'
    return
}
$action = New-ScheduledTaskAction -Execute $phpExecutable -Argument 'artisan pos:send-renewal-reminders' -WorkingDirectory $projectDirectory
$trigger = New-ScheduledTaskTrigger -Daily -At '09:00'
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -ExecutionTimeLimit (New-TimeSpan -Hours 1)
$principal = New-ScheduledTaskPrincipal -UserId ([System.Security.Principal.WindowsIdentity]::GetCurrent().Name) -LogonType Interactive -RunLevel Limited
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings -Principal $principal -Description 'Email Accounting for pending UTAK renewals within 15 days. Requires configured SMTP, MySQL, and a logged-in Windows user.' | Out-Null
Write-Output 'Installed daily 09:00 renewal reminders (local Windows time; requires this user to be logged in).'
