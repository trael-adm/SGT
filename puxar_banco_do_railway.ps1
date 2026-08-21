<#
.SYNOPSIS
    Puxar Banco de Dados do Railway (Nuvem) para o Local (trael_db / trael_db_dev)
.DESCRIPTION
    Conecta ao serviço MySQL do Railway através das credenciais públicas/TCP Proxy,
    realiza o dump de todos os dados da nuvem e importa no banco MySQL local.
#>

[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$Host.UI.RawUI.WindowTitle = "Importador Railway -> Local (SGT)"

$mysqldump = 'C:\Users\06688286173\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysqldump.exe'
$mysql = 'C:\Users\06688286173\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysql.exe'
$configFile = "$PSScriptRoot\.env.railway"

Write-Host "=====================================================" -ForegroundColor Cyan
Write-Host "    PUXAR BANCO DE DADOS: RAILWAY (NUVEM) -> LOCAL   " -ForegroundColor Yellow
Write-Host "=====================================================" -ForegroundColor Cyan
Write-Host ""

$rHost = ""
$rPort = ""
$rUser = ""
$rPass = ""
$rDb   = ""

# Verificar se já existem credenciais salvas em .env.railway
if (Test-Path $configFile) {
    $lines = Get-Content $configFile
    foreach ($line in $lines) {
        if ($line -match '^\s*RAILWAY_HOST\s*=\s*(.+)$') { $rHost = $matches[1].Trim() }
        if ($line -match '^\s*RAILWAY_PORT\s*=\s*(.+)$') { $rPort = $matches[1].Trim() }
        if ($line -match '^\s*RAILWAY_USER\s*=\s*(.+)$') { $rUser = $matches[1].Trim() }
        if ($line -match '^\s*RAILWAY_PASSWORD\s*=\s*(.+)$') { $rPass = $matches[1].Trim() }
        if ($line -match '^\s*RAILWAY_DATABASE\s*=\s*(.+)$') { $rDb = $matches[1].Trim() }
    }
}

if (-not $rHost -or -not $rPort -or -not $rPass) {
    Write-Host "Para conectar ao Railway, precisamos dos dados de conexão externa (TCP Proxy):" -ForegroundColor Yellow
    Write-Host "(Encontrado no painel do Railway -> Serviço MySQL -> Connect -> Public Networking)" -ForegroundColor Gray
    Write-Host ""
    
    $urlInput = Read-Host "Cole a URL de Conexão do Railway (ou pressione Enter para digitar campo a campo)"
    
    if ($urlInput -match 'mysql://([^:]+):([^@]+)@([^:]+):(\d+)/(.+)') {
        $rUser = $matches[1]
        $rPass = $matches[2]
        $rHost = $matches[3]
        $rPort = $matches[4]
        $rDb   = $matches[5]
    } else {
        $rHost = Read-Host "Host Público (ex: roundhouse.proxy.rlwy.net)"
        $rPort = Read-Host "Porta Pública (ex: 12345)"
        $rUser = Read-Host "Usuário [Padrão: root]"
        if (-not $rUser) { $rUser = "root" }
        $rPass = Read-Host "Senha do MySQL no Railway"
        $rDb   = Read-Host "Nome do Banco [Padrão: railway]"
        if (-not $rDb) { $rDb = "railway" }
    }
    
    # Salvar para próximas execuções
    $saveConfig = @"
RAILWAY_HOST=$rHost
RAILWAY_PORT=$rPort
RAILWAY_USER=$rUser
RAILWAY_PASSWORD=$rPass
RAILWAY_DATABASE=$rDb
"@
    Set-Content -Path $configFile -Value $saveConfig -Force
    Write-Host "[OK] Credenciais salvas em .env.railway para as próximas sincronizações!" -ForegroundColor Green
    Write-Host ""
}

Write-Host "Conectando ao Railway:" -ForegroundColor Cyan
Write-Host "  Host: $rHost"
Write-Host "  Porta: $rPort"
Write-Host "  Usuário: $rUser"
Write-Host "  Banco: $rDb"
Write-Host ""

$dumpFile = "$PSScriptRoot\scratch\dump_railway_nuvem.sql"
if (-not (Test-Path "$PSScriptRoot\scratch")) {
    New-Item -ItemType Directory -Path "$PSScriptRoot\scratch" -Force | Out-Null
}

Write-Host "1. Baixando estrutura e dados do Railway..." -ForegroundColor Yellow
& $mysqldump -h $rHost -P $rPort -u $rUser "-p$rPass" --routines --triggers --quick --single-transaction $rDb --result-file=$dumpFile

if ($LASTEXITCODE -ne 0 -or -not (Test-Path $dumpFile) -or (Get-Item $dumpFile).Length -eq 0) {
    Write-Host "[ERRO] Falha ao conectar ou baixar dados do Railway. Verifique as credenciais e se o TCP Proxy/Public Networking está ativo no painel do Railway." -ForegroundColor Red
    Exit 1
}

Write-Host "[OK] Dados baixados com sucesso! Tamanho do dump: $([math]::Round((Get-Item $dumpFile).Length / 1MB, 2)) MB" -ForegroundColor Green
Write-Host ""

Write-Host "2. Em qual banco local deseja importar os dados do Railway?" -ForegroundColor Cyan
Write-Host "  1) Apenas no Oficial (trael_db)"
Write-Host "  2) Apenas no Desenvolvimento (trael_db_dev)"
Write-Host "  3) Em AMBOS (trael_db e trael_db_dev)"
$opcao = Read-Host "Escolha uma opção (1, 2 ou 3) [Padrão: 3]"

if (-not $opcao) { $opcao = "3" }

function Importar-BancoLocal($nomeDb) {
    Write-Host "Importando dados no banco local '$nomeDb'..." -ForegroundColor Yellow
    & $mysql -u root -e "DROP DATABASE IF EXISTS $nomeDb; CREATE DATABASE $nomeDb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    & $mysql -u root $nomeDb -e "source $dumpFile"
    Write-Host "[OK] Banco '$nomeDb' atualizado!" -ForegroundColor Green
}

if ($opcao -eq "1" -or $opcao -eq "3") {
    Importar-BancoLocal "trael_db"
}

if ($opcao -eq "2" -or $opcao -eq "3") {
    Importar-BancoLocal "trael_db_dev"
}

Write-Host ""
Write-Host "=====================================================" -ForegroundColor Cyan
Write-Host "   Sincronização concluída com sucesso!              " -ForegroundColor Green
Write-Host "=====================================================" -ForegroundColor Cyan
Write-Host ""
