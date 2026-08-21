<#
.SYNOPSIS
    Script de Sincronização: SGT-dev -> SGT (Oficial / Railway)
.DESCRIPTION
    Transfere com segurança todas as alterações do ambiente de desenvolvimento (SGT-dev)
    para a pasta oficial (SGT), preservando o repositório Git (.git), uploads e arquivos .env locais.
    Opcionalmente também sincroniza a base de dados trael_db_dev -> trael_db.
#>

[CmdletBinding()]
param (
    [switch]$SyncDatabase = $false
)

[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$Host.UI.RawUI.WindowTitle = "Sincronizador SGT-dev -> SGT"

$src = "M:\APP\Sistema_SGT\www\SGT-dev"
$dst = "M:\APP\Sistema_SGT\www\SGT"

Write-Host "=====================================================" -ForegroundColor Cyan
Write-Host "   SINCRONIZAÇÃO SGT-dev  --->  SGT (OFICIAL)        " -ForegroundColor Yellow
Write-Host "=====================================================" -ForegroundColor Cyan
Write-Host "Origem : $src"
Write-Host "Destino: $dst"
Write-Host ""

if (-not (Test-Path $src)) {
    Write-Host "[ERRO] Diretório de origem $src não encontrado!" -ForegroundColor Red
    Exit 1
}

if (-not (Test-Path $dst)) {
    Write-Host "[ERRO] Diretório de destino $dst não encontrado!" -ForegroundColor Red
    Exit 1
}

Write-Host "1. Sincronizando arquivos de código, assets, telas e migrações..." -ForegroundColor Green

# Robocopy espelhando arquivos de código, preservando .git, .env, uploads e pastas temporárias
robocopy "$src" "$dst" /E /PURGE /XD .git .gemini .vscode scratch uploads /XF .env /R:2 /W:2 /NP /NDL

# Garantir existência de pastas essenciais no destino
if (-not (Test-Path "$dst\storage\cache")) {
    New-Item -ItemType Directory -Path "$dst\storage\cache" -Force | Out-Null
}
if (-not (Test-Path "$dst\uploads")) {
    New-Item -ItemType Directory -Path "$dst\uploads" -Force | Out-Null
}

# Sincronizar cache de índices se existir
if (Test-Path "$src\storage\cache\ns_of_indice.cache") {
    Copy-Item "$src\storage\cache\ns_of_indice.cache" "$dst\storage\cache\ns_of_indice.cache" -Force
}

# Sincronização opcional de banco de dados
$mysqldump = 'C:\Users\06688286173\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysqldump.exe'
$mysql = 'C:\Users\06688286173\laragon\bin\mysql\mysql-8.0.30-winx64\bin\mysql.exe'

if ($SyncDatabase -or (Test-Path $mysqldump)) {
    $perguntarDb = $true
    if ($SyncDatabase) {
        $resposta = "S"
    } else {
        Write-Host ""
        $resposta = Read-Host "Deseja também sincronizar o banco de dados trael_db_dev -> trael_db oficial? (S/N) [Padrão: N]"
    }

    if ($resposta -eq 'S' -or $resposta -eq 's' -or $resposta -eq 'sim') {
        Write-Host "Sincronizando banco de dados local (trael_db_dev -> trael_db)..." -ForegroundColor Yellow
        $tempDump = "$src\scratch\temp_db_sync.sql"
        if (-not (Test-Path "$src\scratch")) { New-Item -ItemType Directory -Path "$src\scratch" -Force | Out-Null }
        & $mysqldump -u root --routines --triggers trael_db_dev --result-file=$tempDump
        & $mysql -u root -e "DROP DATABASE IF EXISTS trael_db; CREATE DATABASE trael_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
        & $mysql -u root trael_db -e "source $tempDump"
        if (Test-Path $tempDump) { Remove-Item $tempDump -Force }
        Write-Host "[OK] Banco de dados trael_db atualizado com sucesso!" -ForegroundColor Green
    }
}

Write-Host ""
Write-Host "2. Verificando status do Git no repositório oficial (SGT)..." -ForegroundColor Green
Write-Host ""

git -C "$dst" status

Write-Host ""
Write-Host "=====================================================" -ForegroundColor Cyan
Write-Host "   Sincronização concluída com sucesso!              " -ForegroundColor Green
Write-Host "=====================================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Para enviar as alterações ao GitHub e acionar o Railway no SGT:" -ForegroundColor Yellow
Write-Host "  cd M:\APP\Sistema_SGT\www\SGT"
Write-Host "  git add ."
Write-Host "  git commit -m 'feat/refactor: sincroniza melhorias do SGT-dev'"
Write-Host "  git push origin main"
Write-Host ""
