<#
.SYNOPSIS
    Puxar Banco de Dados do Railway (Nuvem) para o Local (trael_db / trael_db_dev)
.DESCRIPTION
    Conecta de forma 100% segura via Tunel SSH Privado do Railway CLI (sem portas publicas)
    e sincroniza a base de dados do Railway com os bancos MySQL locais.
#>

[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$OutputEncoding = [System.Text.Encoding]::UTF8
$Host.UI.RawUI.WindowTitle = "Importador Railway -> Local (SGT)"

$mysqldump = 'C:\Users\06688286173\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysqldump.exe'
$mysql = 'C:\Users\06688286173\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysql.exe'
$railwayExe = 'C:\Users\06688286173\AppData\Local\Programs\Railway\railway.exe'
$dumpFile = "$PSScriptRoot\scratch\dump_railway_nuvem.sql"

if (-not (Test-Path "$PSScriptRoot\scratch")) {
    New-Item -ItemType Directory -Path "$PSScriptRoot\scratch" -Force | Out-Null
}

Write-Host "=====================================================" -ForegroundColor Cyan
Write-Host "    PUXAR BANCO DE DADOS: RAILWAY (NUVEM) -> LOCAL   " -ForegroundColor Yellow
Write-Host "=====================================================" -ForegroundColor Cyan
Write-Host ""

if (-not (Test-Path $railwayExe)) {
    Write-Host "[ERRO] Railway CLI nao encontrado em $railwayExe" -ForegroundColor Red
    Exit 1
}

# 1. Obter variaveis do servico MySQL no Railway
Write-Host "1. Obtendo credenciais do servico MySQL no Railway..." -ForegroundColor Yellow
$varsJsonRaw = & $railwayExe variable list --json 2>&1 | Out-String
$vars = $null
try {
    $vars = $varsJsonRaw | ConvertFrom-Json
} catch {}

$dbPass = if ($vars -and $vars.MYSQLPASSWORD) { $vars.MYSQLPASSWORD } else { "AgZGhokRHhSkbesWwuGLRHmygmkXzJKJ" }
$dbName = if ($vars -and $vars.MYSQLDATABASE) { $vars.MYSQLDATABASE } else { "railway" }
$dbUser = if ($vars -and $vars.MYSQLUSER) { $vars.MYSQLUSER } else { "root" }
$localPort = 3307

Write-Host "[OK] Credenciais obtidas. Abrindo Tunel SSH Seguro..." -ForegroundColor Green

# 2. Abrir Tunel SSH em background
$tunnelJob = Start-Job -ScriptBlock {
    param($exe, $port)
    & $exe connect MySQL --ssh --tunnel-only -P $port
} -ArgumentList $railwayExe, $localPort

# Aguardar inicializacao do tunel
Start-Sleep -Seconds 3

Write-Host "2. Baixando estrutura e dados do Railway via Tunel SSH..." -ForegroundColor Yellow

& $mysqldump -h 127.0.0.1 -P $localPort -u $dbUser "-p$dbPass" --routines --triggers --quick --single-transaction $dbName --result-file="$dumpFile"

# Encerrar tunel
Stop-Job $tunnelJob -ErrorAction SilentlyContinue | Out-Null
Remove-Job $tunnelJob -Force -ErrorAction SilentlyContinue | Out-Null

if ($LASTEXITCODE -ne 0 -or -not (Test-Path $dumpFile) -or (Get-Item $dumpFile).Length -eq 0) {
    Write-Host "[ERRO] Falha ao baixar dados do Railway. Verifique sua conexao ou se o servico MySQL esta ativo." -ForegroundColor Red
    Exit 1
}

$dumpSizeMb = [math]::Round((Get-Item $dumpFile).Length / 1MB, 2)
Write-Host "[OK] Dados baixados com sucesso! Tamanho do dump: $dumpSizeMb MB" -ForegroundColor Green
Write-Host ""

# 3. Perguntar onde importar
Write-Host "3. Em qual banco local deseja importar os dados da nuvem?" -ForegroundColor Cyan
Write-Host "  1) Apenas no Oficial (trael_db)"
Write-Host "  2) Apenas no Desenvolvimento (trael_db_dev)"
Write-Host "  3) Em AMBOS (trael_db e trael_db_dev)"
$opcao = Read-Host "Escolha uma opcao (1, 2 ou 3) [Padrao: 3]"

if (-not $opcao) { $opcao = "3" }

function Importar-BancoLocal($nomeDb) {
    Write-Host "Importando dados no banco local '$nomeDb'..." -ForegroundColor Yellow
    & $mysql -u root -e "DROP DATABASE IF EXISTS $nomeDb; CREATE DATABASE $nomeDb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    & $mysql -u root $nomeDb -e "source $dumpFile"
    Write-Host "[OK] Banco local '$nomeDb' atualizado com sucesso!" -ForegroundColor Green
}

if ($opcao -eq "1" -or $opcao -eq "3") {
    Importar-BancoLocal "trael_db"
}

if ($opcao -eq "2" -or $opcao -eq "3") {
    Importar-BancoLocal "trael_db_dev"
}

Write-Host ""
Write-Host "=====================================================" -ForegroundColor Cyan
Write-Host "   Sincronizacao concluida com sucesso!              " -ForegroundColor Green
Write-Host "=====================================================" -ForegroundColor Cyan
Write-Host ""

# Mostrar comparativo de registros
$php = 'C:\Users\06688286173\laragon\bin\php\php-8.4.21-Win32-vs17-x64\php.exe'
if (Test-Path "$PSScriptRoot\scratch\compare_tables.php") {
    & $php "$PSScriptRoot\scratch\compare_tables.php"
}
