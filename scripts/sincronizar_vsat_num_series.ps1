<#
.SYNOPSIS
    Sincronizador VSAT (SQL Server) -> SGT (Paint Check / vsat_num_series_previo)
.DESCRIPTION
    Conecta ao banco VsatTrael e extrai a view dw.vw_relacao_num_series_previo
    (mesma fonte do relatorio "Relacao de Numeros de Series Previos Internos e
    do Cliente" do VSAT), filtrando por uma janela de DataEntraProducao.

    Substitui a dependencia de NS.OF.xlsx (planilha exportada via Power Query,
    confirmada desatualizada para alguns clientes — o campo NumSerieCliente
    ("Patrimonio") vinha vazio nela para Copel/Energisa em casos reais, mas
    existe e bate certo direto na view VSAT) como fonte primaria do Paint Check.

    Grava um .json intermediario (mesmo padrao ja usado por
    sincronizar_vsat_pcp.ps1) — quem persiste no MySQL e o script PHP
    api/vsat-num-series-sincronizar.php, que dispara este .ps1 e depois le o
    .json e faz o UPSERT na tabela vsat_num_series_previo.
#>

[CmdletBinding()]
param (
    # Janela relativa a hoje (nao hardcoded) — cobre retrabalhos de unidades
    # produzidas ha algum tempo e produção programada para o futuro próximo.
    [string]$DataInicio = (Get-Date).AddDays(-365).ToString("yyyy-MM-dd"),
    [string]$DataFim    = (Get-Date).AddDays(90).ToString("yyyy-MM-dd")
)

[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
Write-Host "=====================================================" -ForegroundColor Cyan
Write-Host "   SINCRONIZADOR VSAT -> SGT (PAINT CHECK / NUM SERIES)" -ForegroundColor Yellow
Write-Host "   Janela: $DataInicio a $DataFim                     " -ForegroundColor Yellow
Write-Host "=====================================================" -ForegroundColor Cyan

$connString = "Server=vsat.trael.local;Database=VsatTrael;User Id=bi_consulta;Password=YtowDn2zp5CvuhNO1vtM;TrustServerCertificate=True;Connection Timeout=10;"
$conn = New-Object System.Data.SqlClient.SqlConnection($connString)

try {
    Write-Host "1. Conectando ao banco VsatTrael..." -ForegroundColor Yellow
    $conn.Open()
    Write-Host "[OK] Conectado ao VSAT com sucesso!" -ForegroundColor Green

    Write-Host "2. Consultando dw.vw_relacao_num_series_previo..." -ForegroundColor Yellow

    $cmd = $conn.CreateCommand()
    $cmd.CommandTimeout = 120
    $cmd.CommandText = @"
SELECT
    NumSerie,
    NumSerieCliente,
    CAST(Observacao AS NVARCHAR(1000)) AS Observacao,
    cd_Referencia,
    ds_Prod,
    cdPedido,
    PedidoCliente,
    NomeCli,
    cd_of,
    CONVERT(VARCHAR(10), DataEntraProducao, 120) AS DataEntraProducao
FROM dw.vw_relacao_num_series_previo
WHERE cdEmpr = 1
  AND NumSerie IS NOT NULL
  AND NumSerie <> ''
  AND (DataEntraProducao IS NULL OR DataEntraProducao BETWEEN '$DataInicio' AND '$DataFim')
"@

    $adapter = New-Object System.Data.SqlClient.SqlDataAdapter($cmd)
    $dataset = New-Object System.Data.DataSet
    $adapter.Fill($dataset) | Out-Null
    $tabela = $dataset.Tables[0]

    Write-Host "[OK] $($tabela.Rows.Count) numeros de serie recuperados do VSAT!" -ForegroundColor Green

    $linhas = New-Object System.Collections.Generic.List[object]
    foreach ($row in $tabela.Rows) {
        $getStr = { param($col) if ($row[$col] -eq [DBNull]::Value) { $null } else { [string]$row[$col] } }

        [void]$linhas.Add([PSCustomObject]@{
            num_serie          = & $getStr "NumSerie"
            num_serie_cliente  = & $getStr "NumSerieCliente"
            observacao         = & $getStr "Observacao"
            cd_referencia      = & $getStr "cd_Referencia"
            descricao          = & $getStr "ds_Prod"
            cd_pedido          = & $getStr "cdPedido"
            pedido_cliente     = & $getStr "PedidoCliente"
            cliente            = & $getStr "NomeCli"
            cd_of              = & $getStr "cd_of"
            data_pcp           = & $getStr "DataEntraProducao"
        })
    }

    $finalData = @{
        erp_metadata = @{
            view             = "dw.vw_relacao_num_series_previo"
            servidor         = "vsat.trael.local (10.10.40.8)"
            banco            = "VsatTrael"
            janela_inicio    = $DataInicio
            janela_fim       = $DataFim
            data_sincronizacao = (Get-Date).ToString("dd/MM/yyyy HH:mm:ss")
        }
        linhas = $linhas
    }

    $outJsonPath = "$PSScriptRoot\..\storage\cache\vsat_num_series_previo.json"
    $outDir = Split-Path $outJsonPath -Parent
    if (-not (Test-Path $outDir)) { New-Item -ItemType Directory -Path $outDir -Force | Out-Null }

    $jsonContent = $finalData | ConvertTo-Json -Depth 6 -Compress:$true
    [System.IO.File]::WriteAllText($outJsonPath, $jsonContent, [System.Text.UTF8Encoding]::new($false))

    Write-Host ""
    Write-Host "=====================================================" -ForegroundColor Cyan
    Write-Host "   SINCRONIZACAO CONCLUIDA COM SUCESSO!              " -ForegroundColor Green
    Write-Host "=====================================================" -ForegroundColor Cyan
    Write-Host "Arquivo atualizado: $outJsonPath"
    Write-Host "Total de numeros de serie: $($linhas.Count)"
    Write-Host ""
}
catch {
    [Console]::Error.WriteLine("[ERRO CRITICO NA SINCRONIZACAO] $($_.Exception.Message)")
    Write-Host "[ERRO CRITICO NA SINCRONIZACAO] $($_.Exception.Message)" -ForegroundColor Red
    exit 1
}
finally {
    if ($conn.State -eq 'Open') { $conn.Close() }
}
