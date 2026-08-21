<#
.SYNOPSIS
    Puxar Banco de Dados do Railway (Nuvem) para o Local (trael_db / trael_db_dev)
.DESCRIPTION
    Suporta conexão direta e segura via Railway CLI (SSH Tunnel / Variáveis privadas)
    sem a necessidade de abrir portas públicas no Railway, ou via TCP Proxy.
#>

[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
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

$metodo = ""
$isLoggedIn = $false

# Verificar se Railway CLI está autenticado
if (Test-Path $railwayExe) {
    $whoami = & $railwayExe whoami 2>&1
    if ($LASTEXITCODE -eq 0 -and $whoami -notmatch 'Unauthorized') {
        $isLoggedIn = $true
        Write-Host "[Railway CLI Conectado] Logado como: $whoami" -ForegroundColor Green
    }
}

if (-not $isLoggedIn) {
    Write-Host "Como deseja autenticar/conectar ao Railway?" -ForegroundColor Cyan
    Write-Host "  1) Login no Railway CLI (Recomendado - Túnel SSH seguro sem conexão pública)" -ForegroundColor White
    Write-Host "  2) Informar credenciais públicas / TCP Proxy manualmente" -ForegroundColor White
    Write-Host ""
    $escolhaMetodo = Read-Host "Escolha uma opção (1 ou 2) [Padrão: 1]"
    if (-not $escolhaMetodo) { $escolhaMetodo = "1" }
    
    if ($escolhaMetodo -eq "1") {
        Write-Host ""
        Write-Host "Abrindo navegador para autenticação no Railway..." -ForegroundColor Yellow
        & $railwayExe login
        
        Write-Host ""
        Write-Host "Vinculando seu projeto Railway..." -ForegroundColor Yellow
        & $railwayExe link
        
        $isLoggedIn = $true
    }
}

# Realizar o dump via Railway CLI se autenticado
$dumpSucesso = $false

if ($isLoggedIn) {
    Write-Host ""
    Write-Host "1. Baixando banco de dados via Railway CLI (Túnel Privado)..." -ForegroundColor Yellow
    
    # Obter variáveis do serviço MySQL
    $varsJson = & $railwayExe variable list --json 2>&1 | Out-String
    
    # Se o projeto tiver serviços, vamos rodar o mysqldump via railway run
    & $railwayExe run -- "$mysqldump" -h `$MYSQLHOST -P `$MYSQLPORT -u `$MYSQLUSER "-p`$MYSQLPASSWORD" --routines --triggers --quick --single-transaction `$MYSQLDATABASE --result-file="$dumpFile"
    
    if ($LASTEXITCODE -eq 0 -and (Test-Path $dumpFile) -and (Get-Item $dumpFile).Length -gt 0) {
        $dumpSucesso = $true
    } else {
        Write-Host "Tentando via Túnel SSH Railway connect..." -ForegroundColor Yellow
        # Fallback para railway run com powershell
        $psCmd = "& '$mysqldump' -h `$env:MYSQLHOST -P `$env:MYSQLPORT -u `$env:MYSQLUSER `"-p`$env:MYSQLPASSWORD`" --routines --triggers --quick --single-transaction `$env:MYSQLDATABASE --result-file='$dumpFile'"
        & $railwayExe run powershell -Command $psCmd
        
        if ($LASTEXITCODE -eq 0 -and (Test-Path $dumpFile) -and (Get-Item $dumpFile).Length -gt 0) {
            $dumpSucesso = $true
        }
    }
}

# Se não foi pelo CLI ou falhou, fallback para credenciais diretas
if (-not $dumpSucesso) {
    Write-Host ""
    Write-Host "Conexão via TCP Proxy / Credenciais Diretas:" -ForegroundColor Yellow
    $configFile = "$PSScriptRoot\.env.railway"
    $rHost = ""; $rPort = ""; $rUser = ""; $rPass = ""; $rDb = ""

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
        $urlInput = Read-Host "Cole a URL de Conexão do Railway ou pressione Enter para digitar campos"
        if ($urlInput -match 'mysql://([^:]+):([^@]+)@([^:]+):(\d+)/(.+)') {
            $rUser = $matches[1]; $rPass = $matches[2]; $rHost = $matches[3]; $rPort = $matches[4]; $rDb = $matches[5]
        } else {
            $rHost = Read-Host "Host Público (ex: roundhouse.proxy.rlwy.net)"
            $rPort = Read-Host "Porta Pública (ex: 12345)"
            $rUser = Read-Host "Usuário [Padrão: root]"
            if (-not $rUser) { $rUser = "root" }
            $rPass = Read-Host "Senha do MySQL no Railway"
            $rDb   = Read-Host "Nome do Banco [Padrão: railway]"
            if (-not $rDb) { $rDb = "railway" }
        }
        
        $saveConfig = "RAILWAY_HOST=$rHost`nRAILWAY_PORT=$rPort`nRAILWAY_USER=$rUser`nRAILWAY_PASSWORD=$rPass`nRAILWAY_DATABASE=$rDb"
        Set-Content -Path $configFile -Value $saveConfig -Force
    }

    Write-Host "Baixando estrutura e dados do Railway via TCP Proxy..." -ForegroundColor Yellow
    & $mysqldump -h $rHost -P $rPort -u $rUser "-p$rPass" --routines --triggers --quick --single-transaction $rDb --result-file=$dumpFile
    if ($LASTEXITCODE -eq 0 -and (Test-Path $dumpFile) -and (Get-Item $dumpFile).Length -gt 0) {
        $dumpSucesso = $true
    }
}

if (-not $dumpSucesso) {
    Write-Host "[ERRO] Não foi possível baixar os dados do Railway. Verifique as permissões ou se o serviço MySQL está ativo no Railway." -ForegroundColor Red
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
    Write-Host "[OK] Banco local '$nomeDb' atualizado!" -ForegroundColor Green
}

if ($opcao -eq "1" -or $opcao -eq "3") {
    Importar-BancoLocal "trael_db"
}

if ($opcao -eq "2" -or $opcao -eq "3") {
    Importar-BancoLocal "trael_db_dev"
}

Write-Host ""
Write-Host "=====================================================" -ForegroundColor Cyan
Write-Host "   Sincronização com o Railway concluída!            " -ForegroundColor Green
Write-Host "=====================================================" -ForegroundColor Cyan
Write-Host ""
