# Puts a "هب الريح ERP" icon on the desktop (starts the system and opens it). Run once:
#   powershell -ExecutionPolicy Bypass -File launcher\Create-Shortcut.ps1
$root = Split-Path -Parent $PSScriptRoot
$ws = New-Object -ComObject WScript.Shell
$lnk = $ws.CreateShortcut((Join-Path ([Environment]::GetFolderPath('Desktop')) 'Hub Alreeh ERP.lnk'))
$lnk.TargetPath = Join-Path $root 'launcher\Start-ERP.bat'
$lnk.WorkingDirectory = $root
$lnk.IconLocation = Join-Path $root 'public\app\icons\erp.ico'
$lnk.WindowStyle = 7
$lnk.Save()
Write-Host 'Desktop shortcut created.'
