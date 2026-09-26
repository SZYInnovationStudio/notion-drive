$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent $PSScriptRoot
$devDirectory = Join-Path $projectRoot '.dev'

foreach ($name in @('php', 'mariadb')) {
    $pidFile = Join-Path $devDirectory "$name.pid"
    if (-not (Test-Path -LiteralPath $pidFile)) {
        continue
    }

    $processId = [int] (Get-Content -Raw -LiteralPath $pidFile)
    $process = Get-Process -Id $processId -ErrorAction SilentlyContinue
    if ($null -ne $process) {
        Stop-Process -Id $processId
    }
    Remove-Item -LiteralPath $pidFile -Force
}

Write-Host 'Notion Drive 开发环境已停止。' -ForegroundColor Yellow
