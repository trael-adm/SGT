<#
.SYNOPSIS
    Sincronizador Oficial VSAT (SQL Server) -> SGT (Setor de Papel)
.DESCRIPTION
    Conecta ao banco VsatTrael, extrai as programacoes de producao do PCP para:
    - Empresa: 01 (TRAEL Matriz)
    - Unidade Fabril: 01 (Distribuicao)
    - Tipo Construtivo: 16 (A Oleo) - Transformadores TPD (Distribuicao)
    - Periodo: Julho a Novembro de 2026
    - Status da Engenharia (dbo.ControleProjetoPCP)
    E busca o BOM REAL de isolamento (papel) de cada projeto, direto de
    dbo.ControleProjetoPCP -> dbo.ListaProcessos -> dbo.ListaMateriaisdaEtapaProdutiva
    -> dbo.Materiais (nao usa mais catalogo fixo de dimensoes - investigacao de
    2026-08-27 mostrou que o catalogo antigo tinha as mesmas dimensoes fixas
    para todo TPD, sem relacao com o desenho de engenharia real de cada projeto).
    Cada TPD tem 5 sub-projetos independentes em ControleProjetoPCP, um por
    bloco (AT/BT/PA/NUCLEO/MFL), cada um com sua propria revisao. A revisao
    valida escolhida por bloco e: statusProjeto = 'PRD' (liberado) quando
    existir; senao a maior NroRevisao ainda ativa (PierSitReg = 'ATV').
    A dimensao (espessura/largura/comprimento) vem embutida como texto livre
    em Materiais.ds_Prod (ex.: "REFORCO PAPEL ENTRE CAMADAS AT DIAMANTADO
    0,25 x 425 x 565 x 815") e e interpretada por ConvertFrom-DescricaoMaterial.
#>

[CmdletBinding()]
param (
    [string]$DataInicio = "2026-07-01",
    [string]$DataFim = "2026-11-30"
)

[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
Write-Host "=====================================================" -ForegroundColor Cyan
Write-Host "   SINCRONIZADOR VSAT -> SGT (SETOR DE PAPEL)        " -ForegroundColor Yellow
Write-Host "   Filtros: Empresa 01 | Unidade 01 (Dist.) | A Oleo " -ForegroundColor Yellow
Write-Host "=====================================================" -ForegroundColor Cyan

$connString = "Server=vsat.trael.local;Database=VsatTrael;User Id=bi_consulta;Password=YtowDn2zp5CvuhNO1vtM;TrustServerCertificate=True;Connection Timeout=10;"
$conn = New-Object System.Data.SqlClient.SqlConnection($connString)

# Interpreta a descricao livre de Materiais.ds_Prod e extrai nome/material/dimensoes.
# Formatos reais observados (VSAT, 2026-08-27):
#   "REFORCO PAPEL ENTRE CAMADAS AT DIAMANTADO 0,25 x 425 x 565 x 815"  (2 comprimentos - peca com dobra)
#   "1/2 CANAL (TALISCAS) 6x PRESSPHAN 3,00 x 425 x 15"                 ("6x" = contagem, ja refletida em Quantidade)
#   "ARRUELA ESPACADORA CABO AT PRESSPHAN 1,00 x Ø27"                   (diametro, sem largura/comprimento separados)
#   "CABECEIRA 8x PRESSPHAN 1,00 x 6 x 18 x C 395 - 455"                (comprimento em faixa - usa o maior valor)
function ConvertFrom-DescricaoMaterial {
    param([string]$DsProd)

    $marcadoresMaterial = @(
        @{ Padrao = 'PRESSPHAN'; Nome = 'PRESSPHAN' },
        @{ Padrao = 'DIAMANTADO'; Nome = 'DIAMANT' },
        @{ Padrao = 'DIAMANT'; Nome = 'DIAMANT' },
        @{ Padrao = 'KRAFT'; Nome = 'KRAFT' },
        @{ Padrao = 'PRESS'; Nome = 'PRESSPHAN' },
        # Tolerancia a erros de digitacao reais do VSAT (PRESPHAN, PRESPHANN,
        # PREPHANN, RESSPHAN...) - "PHAN" e um marcador raro o suficiente pra
        # nao dar falso positivo, e so entra em jogo quando a grafia correta
        # (PRESSPHAN/PRESS acima) nao for encontrada primeiro.
        @{ Padrao = 'PHAN'; Nome = 'PRESSPHAN' }
    )

    $posMaterial = -1
    $marcador = $null
    foreach ($m in $marcadoresMaterial) {
        $idx = $DsProd.IndexOf($m.Padrao, [System.StringComparison]::OrdinalIgnoreCase)
        if ($idx -ge 0 -and ($posMaterial -eq -1 -or $idx -lt $posMaterial)) {
            $posMaterial = $idx
            $marcador = $m
        }
    }

    if ($marcador) {
        $nomePeca = $DsProd.Substring(0, $posMaterial).Trim(' ', '-')
        $restante = $DsProd.Substring($posMaterial + $marcador.Padrao.Length).Trim()
    } else {
        # Descricao sem nenhum marcador de material reconhecido (falha real de
        # cadastro no VSAT, nao e so erro de digitacao). Ainda assim tenta achar
        # onde comecam as dimensoes (primeiro numero decimal "0,25"/"1,00"...) -
        # o material fica nulo aqui e e resolvido depois via Get-MaterialViaCadastroPeca
        # (busca o material bruto no cadastro da propria peca, cd_Referencia).
        if ($DsProd -notmatch '\d+[\.,]\d+') { return $null }
        $posMaterial = $DsProd.IndexOf($matches[0])
        $nomePeca = $DsProd.Substring(0, $posMaterial).Trim(' ', '-')
        $restante = $DsProd.Substring($posMaterial).Trim()
    }

    $nomePeca = ($nomePeca -replace '\s+\d+x\s*$', '').Trim()
    if ($nomePeca -eq '') { return $null }

    $partes = $restante -split '\s*[xX]\s*' | Where-Object { $_.Trim() -ne '' }

    # Formatos de token observados no VSAT, do mais especifico ao mais generico:
    #   "<num> C<num2> - <num3>" / "<num> C <num2>-<num3>" -> 2 numeros (largura + maior comprimento da faixa)
    #   "<num> C<num2>"                                    -> 2 numeros (largura + comprimento)
    #   "C<num2> - <num3>" / "C <num2>-<num3>"              -> 1 numero (maior comprimento da faixa)
    #   qualquer prefixo nao-numerico + numero (ex.: "Ø27") -> 1 numero
    $numeros = New-Object System.Collections.Generic.List[double]
    foreach ($parte in $partes) {
        $p = $parte.Trim()
        if ($p -match '^(\d+(?:[\.,]\d+)?)\s+C\s*(\d+(?:[\.,]\d+)?)\s*-\s*(\d+(?:[\.,]\d+)?)$') {
            [void]$numeros.Add([double]($matches[1] -replace ',', '.'))
            $a = [double]($matches[2] -replace ',', '.')
            $b = [double]($matches[3] -replace ',', '.')
            [void]$numeros.Add([Math]::Max($a, $b))
        } elseif ($p -match '^(\d+(?:[\.,]\d+)?)\s+C\s*(\d+(?:[\.,]\d+)?)$') {
            [void]$numeros.Add([double]($matches[1] -replace ',', '.'))
            [void]$numeros.Add([double]($matches[2] -replace ',', '.'))
        } elseif ($p -match '^C\s*(\d+(?:[\.,]\d+)?)\s*-\s*(\d+(?:[\.,]\d+)?)$') {
            $a = [double]($matches[1] -replace ',', '.')
            $b = [double]($matches[2] -replace ',', '.')
            [void]$numeros.Add([Math]::Max($a, $b))
        } elseif ($p -match '^\D*(\d+(?:[\.,]\d+)?)$') {
            [void]$numeros.Add([double]($matches[1] -replace ',', '.'))
        }
    }
    if ($numeros.Count -lt 2) { return $null }

    $esp = $numeros[0]
    $larg = $numeros[1]
    $comp = if ($numeros.Count -ge 3) { ($numeros[2..($numeros.Count - 1)] | Measure-Object -Maximum).Maximum } else { $larg }

    return [PSCustomObject]@{
        Nome          = $nomePeca
        Material      = if ($marcador) { $marcador.Nome } else { $null }
        Esp           = $esp
        Larg          = $larg
        Comp          = $comp
        DimensaoTexto = $restante
    }
}

# Fallback usado quando a peca (Materiais.ds_Prod) nao tem material reconhecivel
# no proprio texto - descoberta em 2026-08-27 (tela 92514/Multi-Projetos do
# Areco): cada peca cortada (cd_Referencia) tem seu PROPRIO cadastro de
# ControleProjetoPCP/ListaProcessos/ListaMateriaisdaEtapaProdutiva, apontando
# para a materia-prima bruta real (ex.: cd_Referencia 390788 "PAPEL VIGA EM
# 'L' AT PRESSPHAN 1,00 x 115 x 580" -> seu proprio processo consome o produto
# 186 "PAPEL PRESSPHAN 1,00mm"). So usa o MATERIAL dessa consulta - a dimensao
# de corte continua vindo do texto da propria peca (a materia-prima bruta so
# tem espessura de bobina, nao largura/comprimento cortados).
function Get-MaterialViaCadastroPeca {
    param([System.Data.SqlClient.SqlConnection]$Conn, [string]$CdReferencia, [array]$Marcadores)

    $cmdFallback = $Conn.CreateCommand()
    $cmdFallback.CommandTimeout = 15
    $cmdFallback.CommandText = @"
SELECT TOP 1 M2.ds_Prod
FROM dbo.Materiais M1
JOIN dbo.ControleProjetoPCP PCP2 WITH (NOLOCK) ON PCP2.id_Produto = M1.id_Produto AND PCP2.PierSitReg = 'ATV'
JOIN dbo.ListaProcessos LP2 WITH (NOLOCK) ON LP2.id_CtrlProjPCP = PCP2.id_CtrlProjPCP
JOIN dbo.ListaMateriaisdaEtapaProdutiva LM2 WITH (NOLOCK) ON LM2.id_lstProcessos = LP2.id_lstProcessos
JOIN dbo.Materiais M2 WITH (NOLOCK) ON M2.id_Produto = LM2.id_MatPrima
WHERE M1.cd_Referencia = '$CdReferencia'
ORDER BY PCP2.statusProjeto, PCP2.NroRevisao DESC
"@
    $readerFb = $cmdFallback.ExecuteReader()
    $dsProdBruto = $null
    if ($readerFb.Read()) { $dsProdBruto = [string]$readerFb["ds_Prod"] }
    $readerFb.Close()
    if (-not $dsProdBruto) { return $null }

    foreach ($m in $Marcadores) {
        if ($dsProdBruto.IndexOf($m.Padrao, [System.StringComparison]::OrdinalIgnoreCase) -ge 0) { return $m.Nome }
    }
    return $null
}

# Classifica o bloco (AT/BT/PA/NUCLEO/MFL) a partir do nome do sub-projeto em
# ControleProjetoPCP. Existem 2 convencoes de nome no VSAT (2026-08-27):
#   - Projetos com desenho CMI ja lancado: "CMI-xxx-MI-yyyy <BLOCO> - TPD-num"
#   - Projetos mais antigos/sem desenho CMI ainda: "PARTE ATIVA - TPD-num",
#     "NUCLEO - TPD-num", "MONTAGEM FINAL - MFL-num"
# "BOBINA DE AT"/"BOBINA DE BT" (mesma convencao antiga) sao propositalmente
# ignorados aqui - confirmado que sao referencias de montagem (fio esmaltado +
# link pro proprio projeto CMI/parte ativa), nao BOM de papel isolante; incluir
# geraria ruido (fio de cobre/aluminio entrando como "peca de papel").
# Tambem ignora ARMADURA/FUNDO/TAMPA/TANQUE/o registro do transformador
# completo - sao sub-conjuntos mecanicos, sem relacao com corte de papel.
function Get-BlocoDoProjeto {
    param([string]$DsCtrlProjPCP)

    if ($DsCtrlProjPCP -match '\s(AT|BT|PA|MFL|\S*CLEO)\s*-\s*TPD-\d') {
        $b = $matches[1]
        if ($b -match 'CLEO$') { return 'NUCLEO' }
        return $b
    }
    if ($DsCtrlProjPCP -match '^PARTE ATIVA\s*-') { return 'PA' }
    if ($DsCtrlProjPCP -match '^\S*CLEO\s*-') { return 'NUCLEO' }
    if ($DsCtrlProjPCP -match '^MONTAGEM FINAL\s*-') { return 'MFL' }
    return $null
}

# Sugestao de maquina/largura de bobina por material+espessura+nome. O BOM real
# do VSAT nao tem rota de maquina nem largura de bobina cadastradas (isso e
# estoque/roteiro de producao, nao o desenho de engenharia) - e uma aproximacao,
# nao um dado oficial. Fica sujeito a ajuste manual pelo operador na Mesa de Corte.
function Get-SugestaoMaquina {
    param([string]$Material, [double]$Esp, [string]$Nome)

    if ($Material -eq 'KRAFT' -or $Material -eq 'DIAMANT') { return 17001 } # Slitter de Bobina
    if ($Nome -match 'CANUDO|COLARINHO') { return 17003 } # Dobradeira de Papel
    if ($Esp -ge 3.0) { return 17012 } # Guilhotina Eletrica
    return 17002 # Guilhotina de Pedal
}

try {
    Write-Host "1. Conectando ao banco VsatTrael..." -ForegroundColor Yellow
    $conn.Open()
    Write-Host "[OK] Conectado ao VSAT com sucesso!" -ForegroundColor Green

    Write-Host "2. Extraindo programacoes do PCP (Empresa 01 - Distribuicao a Oleo)..." -ForegroundColor Yellow

    $cmd = $conn.CreateCommand()
    $cmd.CommandTimeout = 60
    $cmd.CommandText = @"
SELECT
    M.cd_Referencia AS ProjetoPrincipal,
    M.ds_Prod AS DescricaoTrafo,
    DATEPART(month, PP.DataEntraProducao) AS Mes,
    ISNULL(DATEPART(week, PP.DataEntraProducao), 31) AS Semana,
    CONVERT(VARCHAR(10), PP.DataEntraProducao, 120) AS DataPcp,
    COUNT(*) AS TrafosQtd,
    MIN(PP.id_of) AS OfMae,
    ISNULL(PCP.statusProjeto, 'PRD') AS StatusProjetoEng,
    ISNULL(PCP.NroRevisao, 0) AS NroRevisao
FROM dbo.ProgramacaoProducao PP WITH (NOLOCK)
INNER JOIN dbo.Materiais M WITH (NOLOCK) ON (M.id_Produto = PP.id_Produto)
OUTER APPLY (
    -- Quando ha mais de uma revisao ativa do produto (ex.: uma 'REV' em
    -- andamento e uma 'PRD' ja liberada), prioriza sempre a liberada (PRD),
    -- senao a de revisao mais alta ainda ativa (mesma regra usada no BOM)
    SELECT TOP 1 p2.statusProjeto, p2.NroRevisao
    FROM dbo.ControleProjetoPCP p2 WITH (NOLOCK)
    WHERE p2.id_Produto = M.id_Produto AND p2.PierSitReg = 'ATV'
    ORDER BY CASE p2.statusProjeto WHEN 'PRD' THEN 0 WHEN 'REV' THEN 1 ELSE 2 END, p2.NroRevisao DESC
) PCP
WHERE PP.PierSitReg = 'ATV'
  AND M.cd_Referencia LIKE 'TPD-%'
  AND PP.id_Empresa = 1
  AND PP.DataEntraProducao BETWEEN '$DataInicio' AND '$DataFim'
GROUP BY
    M.cd_Referencia,
    M.ds_Prod,
    DATEPART(month, PP.DataEntraProducao),
    DATEPART(week, PP.DataEntraProducao),
    CONVERT(VARCHAR(10), PP.DataEntraProducao, 120),
    PCP.statusProjeto,
    PCP.NroRevisao
ORDER BY DataPcp ASC, TrafosQtd DESC, M.cd_Referencia ASC
"@

    $adapter = New-Object System.Data.SqlClient.SqlDataAdapter($cmd)
    $dataset = New-Object System.Data.DataSet
    $adapter.Fill($dataset) | Out-Null
    $progsTable = $dataset.Tables[0]

    Write-Host "[OK] $($progsTable.Rows.Count) lotes de Transformadores TPD (Distribuicao a Oleo) recuperados do VSAT!" -ForegroundColor Green

    $mats = @("PRESSPHAN", "DIAMANT", "KRAFT")
    $matIdxPorNome = @{ "PRESSPHAN" = 0; "DIAMANT" = 1; "KRAFT" = 2 }

    # Categorias = blocos reais de ControleProjetoPCP (AT/BT/PA/NUCLEO/MFL).
    # Os nomes contem "AT"/"BT" de proposito, para que classificarCmiOrdem()
    # em pages/papel/ordem-corte.php (que agrupa pelo texto de peca+categoria)
    # continue funcionando sem precisar reescrever aquela pagina.
    $blocoParaCategoria = @{
        "AT"     = @{ Idx = 0; Nome = "ENROLAMENTO AT" }
        "BT"     = @{ Idx = 1; Nome = "ENROLAMENTO BT" }
        "PA"     = @{ Idx = 2; Nome = "MONTAGEM PARTE ATIVA" }
        "NUCLEO" = @{ Idx = 3; Nome = "ISOLACAO NUCLEO" }
        "MFL"    = @{ Idx = 4; Nome = "MONTAGEM FINAL" }
    }
    $cats = @("ENROLAMENTO AT", "ENROLAMENTO BT", "MONTAGEM PARTE ATIVA", "ISOLACAO NUCLEO", "MONTAGEM FINAL")

    $maquinasLista = @(
        @{ id = 17001; codigo = "17001"; nome = "Sliter de Bobina 17001"; tipo = "Slitter"; icone = "" },
        @{ id = 17002; codigo = "17002"; nome = "Guilhotina de Pedal 17002"; tipo = "Guilhotina Manual"; icone = "" },
        @{ id = 17012; codigo = "17012"; nome = "Guilhotina Elétrica 17012"; tipo = "Guilhotina Pesada"; icone = "" },
        @{ id = 17003; codigo = "17003"; nome = "Dobradeira de Papel 17003"; tipo = "Dobradeira"; icone = "" }
    )

    # Dicionario global de pecas: cada cd_Referencia (SKU real do VSAT, ja
    # unico por nome+material+dimensao) vira um indice; o nome exibido e o
    # nome da peca (sem a dimensao, que fica em esp/larg/comp por linha).
    $pecasMap = [System.Collections.Generic.Dictionary[string, int]]::new()
    $pecasLista = [System.Collections.Generic.List[string]]::new()

    $tpdsMap = [System.Collections.Generic.Dictionary[string, int]]::new()
    $tpdsLista = [System.Collections.Generic.List[string]]::new()
    $tpdTrafos = [System.Collections.Generic.List[int]]::new()
    $tpdOfs = [System.Collections.Generic.List[int]]::new()
    $tpdStatusCorte = [System.Collections.Generic.List[int]]::new()
    $tpdStatusEng = [System.Collections.Generic.List[string]]::new()
    $rows = [System.Collections.Generic.List[object]]::new()
    $projetosSemBom = New-Object System.Collections.Generic.List[string]
    $itensNaoInterpretados = 0

    Write-Host "3. Buscando BOM real de isolamento por projeto (ListaMateriaisdaEtapaProdutiva)..." -ForegroundColor Yellow

    $tpdIdxCounter = 0

    foreach ($row in $progsTable.Rows) {
        $tpdName = [string]$row["ProjetoPrincipal"]
        $semana = [int]$row["Semana"]
        $dataPcp = [string]$row["DataPcp"]
        $trafosQtd = [int]$row["TrafosQtd"]

        if (-not $tpdsMap.ContainsKey($tpdName)) {
            $currentIdx = $tpdIdxCounter
            $tpdsMap.Add($tpdName, $currentIdx)
            $tpdsLista.Add($tpdName)
            $tpdIdxCounter++

            $tpdTrafos.Add($trafosQtd)

            $stEngRaw = [string]$row["StatusProjetoEng"]
            $statusEng = "LIBERADO"
            if ($stEngRaw -eq "SUS") { $statusEng = "BLOQUEADO" }
            elseif ($stEngRaw -eq "ELA" -or $stEngRaw -eq "DES") { $statusEng = "AGUARDANDO_ENGENHARIA" }
            $tpdStatusEng.Add($statusEng)

            $stCorte = if ($semana -lt 31) { 2 } elseif ($semana -eq 31) { 1 } else { 0 }
            $tpdStatusCorte.Add($stCorte)

            $ofMaeBase = if ($row["OfMae"] -ne [DBNull]::Value) { [int]$row["OfMae"] } else { 2087000 + ($currentIdx * 100) }

            # Numero puro do TPD (so digitos) para montar o LIKE com seguranca
            $tpdNum = $tpdName -replace '[^0-9]', ''
            $itensReais = New-Object System.Collections.Generic.List[object]

            if ($tpdNum -ne '') {
                $cmdMat = $conn.CreateCommand()
                $cmdMat.CommandTimeout = 30
                $cmdMat.CommandText = @"
SELECT
    PCP.id_CtrlProjPCP, PCP.ds_CtrlProjPCP, PCP.NroRevisao, PCP.statusProjeto,
    LM.Ordenacao, LM.Quantidade, M.cd_Referencia, M.ds_Prod
FROM dbo.ControleProjetoPCP PCP WITH (NOLOCK)
JOIN dbo.ListaProcessos LP WITH (NOLOCK) ON LP.id_CtrlProjPCP = PCP.id_CtrlProjPCP
JOIN dbo.ListaMateriaisdaEtapaProdutiva LM WITH (NOLOCK) ON LM.id_lstProcessos = LP.id_lstProcessos
JOIN dbo.Materiais M WITH (NOLOCK) ON M.id_Produto = LM.id_MatPrima
WHERE PCP.PierSitReg = 'ATV'
  AND (PCP.ds_CtrlProjPCP LIKE '%TPD-$tpdNum' OR PCP.ds_CtrlProjPCP LIKE '%TPD-$tpdNum Rev.%'
       OR PCP.ds_CtrlProjPCP LIKE '%-$tpdNum' OR PCP.ds_CtrlProjPCP LIKE '%-$tpdNum Rev.%')
ORDER BY PCP.id_CtrlProjPCP, LM.Ordenacao
"@
                $readerMat = $cmdMat.ExecuteReader()
                $tabelaMat = New-Object System.Data.DataTable
                $tabelaMat.Load($readerMat)

                # Passo 1: por bloco (AT/BT/PA/NUCLEO/MFL), acha a revisao
                # vencedora (PRD > maior NroRevisao ainda ativa)
                # Quando o desenho CMI ja foi lancado para um bloco, a versao
                # com nome legado ("NUCLEO - TPD-x", "BOBINA DE AT - TPD-x"...)
                # vira so um container de referencias de montagem (aco silicio,
                # fio esmaltado, ate auto-referencia ao proprio bloco) - nao e
                # mais um BOM de papel. Confirmado em 2026-08-27 comparando
                # 'NUCLEO - TPD-390829' (legado, virou lixo de montagem) com
                # 'CMI-281-MI-1544 NUCLEO - TPD-390829' (papel real). Por isso
                # CMI sempre ganha do legado quando os dois existem pro mesmo
                # bloco, independente de status/revisao.
                $porBloco = @{}
                foreach ($linha in $tabelaMat.Rows) {
                    $dsCtrl = [string]$linha["ds_CtrlProjPCP"]
                    $bloco = Get-BlocoDoProjeto -DsCtrlProjPCP $dsCtrl
                    if (-not $bloco) { continue }
                    $idProj = [int]$linha["id_CtrlProjPCP"]
                    $status = [string]$linha["statusProjeto"]
                    $rev = [int]$linha["NroRevisao"]
                    $ehCmi = $dsCtrl -match '^CMI-'
                    $prioridade = switch ($status) { "PRD" { 0 }; "REV" { 1 }; default { 2 } }

                    $atual = $porBloco[$bloco]
                    $melhorQueAtual = (-not $atual) `
                        -or ($ehCmi -and -not $atual.EhCmi) `
                        -or ($ehCmi -eq $atual.EhCmi -and $prioridade -lt $atual.Prioridade) `
                        -or ($ehCmi -eq $atual.EhCmi -and $prioridade -eq $atual.Prioridade -and $rev -gt $atual.Rev)
                    if ($melhorQueAtual) {
                        $porBloco[$bloco] = @{ IdProj = $idProj; Prioridade = $prioridade; Rev = $rev; EhCmi = $ehCmi }
                    }
                }

                # Passo 1.5: multiplicador de bobinas AT por transformador (arvore real
                # do cadastro, confirmada em 2026-08-27: MONTAGEM FINAL -> 1x TANQUE +
                # 1x PARTE ATIVA -> Nx AT (N=3 no trifasico, N=1 no monofasico) -> cada
                # AT chama 1x BT, com os materiais da BT ja cadastrados divididos por N).
                # A quantidade real fica na linha de referencia "BOBINA DE AT" dentro do
                # BOM da propria PARTE ATIVA (Materiais.ds_Prod comeca com esse texto,
                # LM.Quantidade = N) - nao um valor fixo, varia por tipo de transformador.
                # Sem isso, AT e BT saiam subestimados em N vezes (bug real encontrado
                # comparando TPD-420289 no VSAT: PA chama "AT-420289 x3", mas o script
                # so aplicava trafosQtd, nunca esse N).
                $multiplicadorBobinasAt = 1.0
                foreach ($linhaRef in $tabelaMat.Rows) {
                    $dsProdRef = [string]$linhaRef["ds_Prod"]
                    if ($dsProdRef -match '^\s*BOBINA DE AT\b') {
                        $qtdRef = [double]$linhaRef["Quantidade"]
                        if ($qtdRef -gt 0) { $multiplicadorBobinasAt = $qtdRef }
                        break
                    }
                }

                # Passo 2: coleta so os materiais da revisao vencedora de cada bloco
                foreach ($linha in $tabelaMat.Rows) {
                    $dsCtrl = [string]$linha["ds_CtrlProjPCP"]
                    $bloco = Get-BlocoDoProjeto -DsCtrlProjPCP $dsCtrl
                    if (-not $bloco) { continue }
                    $idProj = [int]$linha["id_CtrlProjPCP"]
                    if ($porBloco[$bloco].IdProj -ne $idProj) { continue }

                    $dsProd = [string]$linha["ds_Prod"]
                    $cdReferenciaLinha = [string]$linha["cd_Referencia"]
                    $parsed = ConvertFrom-DescricaoMaterial -DsProd $dsProd
                    if (-not $parsed) {
                        Write-Host "  [AVISO] Nao interpretei a dimensao de '$dsProd' ($tpdName / $bloco)" -ForegroundColor DarkYellow
                        $itensNaoInterpretados++
                        continue
                    }

                    if (-not $parsed.Material) {
                        # Descricao da propria peca nao trouxe material - busca
                        # no cadastro proprio da peca (fallback validado em 2026-08-27)
                        $matFallback = Get-MaterialViaCadastroPeca -Conn $conn -CdReferencia $cdReferenciaLinha -Marcadores $marcadoresMaterial
                        if (-not $matFallback) {
                            Write-Host "  [AVISO] Sem material (nem no cadastro da peca) para '$dsProd' ($tpdName / $bloco)" -ForegroundColor DarkYellow
                            $itensNaoInterpretados++
                            continue
                        }
                        $parsed.Material = $matFallback
                    }

                    # AT e BT sao chamados N vezes por transformador (N = multiplicador
                    # de bobinas AT, ver Passo 1.5) - PA/NUCLEO/MFL nao tem esse fator
                    # (sao 1x por transformador na arvore do cadastro).
                    $multiplicadorItem = if ($bloco -eq 'AT' -or $bloco -eq 'BT') { $multiplicadorBobinasAt } else { 1.0 }

                    [void]$itensReais.Add([PSCustomObject]@{
                        Bloco        = $bloco
                        CdReferencia = [string]$linha["cd_Referencia"]
                        Nome         = $parsed.Nome
                        Material     = $parsed.Material
                        Esp          = $parsed.Esp
                        Larg         = $parsed.Larg
                        Comp         = $parsed.Comp
                        Quantidade   = [double]$linha["Quantidade"] * $multiplicadorItem
                    })
                }
            }

            if ($itensReais.Count -eq 0) {
                [void]$projetosSemBom.Add($tpdName)
            }
            $tpdOfs.Add($itensReais.Count)

            $p = 0
            foreach ($item in $itensReais) {
                if (-not $pecasMap.ContainsKey($item.CdReferencia)) {
                    $pecasMap.Add($item.CdReferencia, $pecasLista.Count)
                    [void]$pecasLista.Add($item.Nome)
                }
                $pecaIdx = $pecasMap[$item.CdReferencia]
                $matIdx = if ($matIdxPorNome.ContainsKey($item.Material)) { $matIdxPorNome[$item.Material] } else { 0 }
                $catInfo = $blocoParaCategoria[$item.Bloco]
                $catIdx = if ($catInfo) { $catInfo.Idx } else { 2 }

                $qtdTotalPecas = [int]($trafosQtd * $item.Quantidade)

                $dens = if ($item.Material -eq "PRESSPHAN") { 1.2 } elseif ($item.Material -eq "DIAMANT") { 0.95 } else { 0.85 }
                $volCm3 = ($item.Esp / 10.0) * ($item.Larg / 10.0) * ($item.Comp / 10.0)
                $massaUnit = ($volCm3 * $dens) / 1000.0
                $massaTotal = [Math]::Round($massaUnit * $qtdTotalPecas, 2)

                $ofFilhaNum = [string]($ofMaeBase + $p)
                $maquina = Get-SugestaoMaquina -Material $item.Material -Esp $item.Esp -Nome $item.Nome
                $largBobina = [Math]::Ceiling($item.Larg / 10.0) * 10 + 20 # aproximacao (sem dado oficial de bobina)

                # Schema do row (mesmo indice usado por ordem-corte.php,
                # minha-maquina.php e assets/js/papel/*.js):
                # 0: Semana, 1: DataPCP, 2: CatIdx, 3: PecaIdx, 4: MatIdx, 5: Esp, 6: Larg, 7: Comp,
                # 8: TpdIdx, 9: OF_Filha, 10: Qtd, 11: MassaTotal, 12: Trafos, 13: StCorte,
                # 14: LargBobina (aproximado), 15: OfsTotal, 16: StatusEngenharia, 17: MaquinaId (sugerido)
                [void]$rows.Add(@(
                    $semana,
                    $dataPcp,
                    $catIdx,
                    $pecaIdx,
                    $matIdx,
                    $item.Esp,
                    $item.Larg,
                    $item.Comp,
                    $currentIdx,
                    $ofFilhaNum,
                    $qtdTotalPecas,
                    $massaTotal,
                    $trafosQtd,
                    $stCorte,
                    $largBobina,
                    $itensReais.Count,
                    $statusEng,
                    $maquina
                ))
                $p++
            }
        }
    }

    if ($projetosSemBom.Count -gt 0) {
        Write-Host ""
        Write-Host "[AVISO] $($projetosSemBom.Count) projeto(s) sem BOM de isolamento cadastrado no VSAT (engenharia ainda nao lancou os desenhos CMI para esse TPD):" -ForegroundColor DarkYellow
        Write-Host "         $($projetosSemBom -join ', ')" -ForegroundColor DarkYellow
    }
    if ($itensNaoInterpretados -gt 0) {
        Write-Host "[AVISO] $itensNaoInterpretados linha(s) de material com descricao fora do padrao esperado (nao entraram no F-29 - ver avisos acima)." -ForegroundColor DarkYellow
    }

    $finalData = @{
        mats = $mats
        cats = $cats
        pecas = $pecasLista
        maquinas = $maquinasLista
        tpds = $tpdsLista
        tpd_trafos = $tpdTrafos
        tpd_ofs = $tpdOfs
        tpd_status = $tpdStatusCorte
        tpd_status_eng = $tpdStatusEng
        erp_metadata = @{
            caminho_relatorio = "/Tabelas Basicas Especialistas/Industria e Comercio de Transformadores/Relatorios/Programacao TRAEL - Semana Ano - DzOiD: 10101451 Revisao: 100 Consulta: 10101256"
            dzoid = "10101451"
            revisao = "100"
            consulta = "10101256"
            servidor = "vsat.trael.local (10.10.40.8)"
            banco = "VsatTrael"
            fonte_bom = "dbo.ControleProjetoPCP -> dbo.ListaProcessos -> dbo.ListaMateriaisdaEtapaProdutiva -> dbo.Materiais (BOM real por projeto/revisao, substituindo o catalogo fixo usado ate 2026-08-27)"
            empresa = "01 (TRAEL Matriz)"
            unidades_fabris = "01 - Distribuicao"
            status_of_filtro = "Aguardando Reserva de Mat. Prima, Cancelada, Mat. Prima Reservada, OF Encerrada"
            tipos_construtivos = "16 (A Oleo)"
            data_sincronizacao = (Get-Date).ToString("dd/MM/yyyy HH:mm:ss")
        }
        rows = $rows
    }

    $outJsonPath = "$PSScriptRoot\..\pages\papel\dados_pcp_completo.json"
    $jsonContent = $finalData | ConvertTo-Json -Depth 10 -Compress:$true
    [System.IO.File]::WriteAllText($outJsonPath, $jsonContent, [System.Text.UTF8Encoding]::new($false))

    Write-Host ""
    Write-Host "=====================================================" -ForegroundColor Cyan
    Write-Host "   SINCRONIZACAO CONCLUIDA COM SUCESSO!              " -ForegroundColor Green
    Write-Host "=====================================================" -ForegroundColor Cyan
    Write-Host "Arquivo atualizado: $outJsonPath"
    Write-Host "Total de Projetos TPD (Distribuicao a Oleo): $($tpdsLista.Count)"
    Write-Host "Total de Itens/OFs de Isolacao (BOM real): $($rows.Count)"
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
