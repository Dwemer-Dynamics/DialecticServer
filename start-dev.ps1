param(
    [int]$Port = 8085
)

$ErrorActionPreference = "Stop"

$serverRoot = Split-Path -Parent $PSScriptRoot
$router = Join-Path $PSScriptRoot "router.php"
$php = (Get-Command php -ErrorAction Stop).Source
$listen = "127.0.0.1:$Port"

Write-Host "Starting DialecticServer dev server at http://$listen/"
Write-Host "DialecticServer UI: http://$listen/DialecticServer/ui/core/config_hub.php"
Write-Host "DialecticServer API: http://$listen/DialecticServer/main.php"
Write-Host "Document root: $serverRoot"

& $php -S $listen -t $serverRoot $router
