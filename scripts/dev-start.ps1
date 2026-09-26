$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$devDirectory = Join-Path $projectRoot '.dev'
$databaseDirectory = Join-Path $devDirectory 'mariadb-data'
$databasePidFile = Join-Path $devDirectory 'mariadb.pid'
$phpPidFile = Join-Path $devDirectory 'php.pid'
$databaseExecutable = 'C:\Program Files\MariaDB 13.0\bin\mariadbd.exe'

function Test-ManagedProcess([string] $pidFile) {
    if (-not (Test-Path -LiteralPath $pidFile)) {
        return $false
    }

    $processId = [int] (Get-Content -Raw -LiteralPath $pidFile)
    return $null -ne (Get-Process -Id $processId -ErrorAction SilentlyContinue)
}

New-Item -ItemType Directory -Path $devDirectory -Force | Out-Null

if (-not (Test-ManagedProcess $databasePidFile)) {
    if (-not (Test-Path -LiteralPath (Join-Path $databaseDirectory 'my.ini'))) {
        throw 'MariaDB 数据目录尚未初始化。'
    }

    $databaseProcess = Start-Process -FilePath $databaseExecutable `
        -ArgumentList @('--defaults-file=.dev/mariadb-data/my.ini', '--bind-address=127.0.0.1') `
        -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru
    $databaseProcess.Id | Set-Content -LiteralPath $databasePidFile -NoNewline
}

if (-not (Test-ManagedProcess $phpPidFile)) {
    $env:Path = [Environment]::GetEnvironmentVariable('Path', 'Machine') + ';' + [Environment]::GetEnvironmentVariable('Path', 'User')
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    if ($null -eq $phpCommand) {
        $phpExecutable = Get-ChildItem "$env:LOCALAPPDATA\Microsoft\WinGet\Packages\PHP.PHP.8.4_*\php.exe" -ErrorAction SilentlyContinue | Select-Object -First 1 -ExpandProperty FullName
    } else {
        $phpExecutable = $phpCommand.Source
    }
    if ([string]::IsNullOrWhiteSpace($phpExecutable)) {
        throw '找不到 PHP 8.4 可执行文件。'
    }
    $phpProcess = Start-Process -FilePath $phpExecutable `
        -ArgumentList @('-S', '0.0.0.0:8088', '-t', '.') `
        -WorkingDirectory $projectRoot -WindowStyle Hidden -PassThru
    $phpProcess.Id | Set-Content -LiteralPath $phpPidFile -NoNewline
}

$lanAddress = (Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
    Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' -and $_.IPAddress -notlike '172.1*' } |
    Select-Object -First 1 -ExpandProperty IPAddress)

Write-Host 'Notion Drive 开发环境已启动：' -ForegroundColor Green
Write-Host '  本机：http://127.0.0.1:8088/'
if ($lanAddress) {
    Write-Host "  局域网（手机同一 WiFi 可访问）：http://${lanAddress}:8088/"
}
Write-Host '  凭据：.dev/credentials.txt'
