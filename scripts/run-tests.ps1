# Roda a suíte PHPUnit do SGT (tests/) contra o banco de dev local.
# Uso: powershell -File scripts/run-tests.ps1  (ou simplesmente ./scripts/run-tests.ps1)
#
# O PHP portátil não fica no PATH deste ambiente — mesmo protocolo de
# gemini.md: procura em ~/Downloads/php-*-Win32-*/php.exe.

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot

$phpCandidates = Get-ChildItem -Path "$env:USERPROFILE\Downloads" -Filter "php-*-Win32-*" -Directory -ErrorAction SilentlyContinue |
    ForEach-Object { Join-Path $_.FullName "php.exe" } |
    Where-Object { Test-Path $_ }

if (-not $phpCandidates) {
    Write-Error "Nenhum PHP portátil encontrado em $env:USERPROFILE\Downloads\php-*-Win32-*\php.exe. Baixe o build portátil do PHP 8.4 (ver gemini.md) antes de rodar os testes."
    exit 1
}

$php = $phpCandidates | Select-Object -First 1
$extDir = Join-Path (Split-Path $php -Parent) "ext"

& $php -d extension_dir="$extDir" -d extension=pdo_mysql -d extension=mbstring `
    "$root\vendor\phpunit\phpunit\phpunit" --configuration "$root\phpunit.xml" @args

exit $LASTEXITCODE
