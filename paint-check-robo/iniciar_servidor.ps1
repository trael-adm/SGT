# Mantém a janela aberta e captura possíveis erros
try {
    Set-Location -LiteralPath $PSScriptRoot
    Write-Host "Diretorio atual: $PWD" -ForegroundColor Cyan
    Write-Host "Iniciando server.py no Python 3.11..." -ForegroundColor Yellow
    
    py -3.11 server.py
}
catch {
    Write-Host "Ocorreu um erro ao executar o script:" -ForegroundColor Red
    Write-Host $_.Exception.Message -ForegroundColor Red
}
finally {
    Write-Host ""
    Read-Host "Pressione ENTER para fechar..."
}