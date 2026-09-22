<?php
declare(strict_types=1);

/**
 * Módulo de Gestão e Métricas de Atraso - Fábrica de Média Força (Trael).
 *
 * Responsável por:
 * 1. Extração e congelamento diário de snapshots (fotos do PCP Média Força - EmpDestino = 4).
 * 2. Cálculo dos indicadores de atraso (dias e peças) por linha (TPD, TPM, TPS/Seco).
 * 3. Série temporal para o gráfico de evolução histórica da média de dias.
 * 4. Agregação mensal e semanal com suporte a drilldown interativo.
 * 5. Cruzamento com esteira de produção e números de série para rastreabilidade.
 */

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/boletim-planilha.php';
require_once __DIR__ . '/boletim-fluxo-pedidos.php';
require_once __DIR__ . '/boletim-atraso.php';
require_once __DIR__ . '/helpers.php';

/**
 * Garante que a tabela de registros de atraso de Média Força exista no MySQL local.
 */
function boletimGarantirTabelasAtrasoForca(PDO $pdo): void
{
    boletimGarantirTabelasAtraso($pdo); // garante também `atraso_historico_diario`, compartilhada com Distribuição

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS atraso_forca_registros (
            id INT AUTO_INCREMENT PRIMARY KEY,
            data_extracao DATE NOT NULL,
            data_programada DATE NOT NULL,
            cd_referencia VARCHAR(50) NOT NULL,
            ds_produto VARCHAR(255) NULL,
            qtd_item INT NOT NULL DEFAULT 1,
            quantidade INT NOT NULL DEFAULT 1,
            qtd_produzida INT NOT NULL DEFAULT 0,
            qtd_a_produzir INT NOT NULL DEFAULT 1,
            cliente_nome VARCHAR(255) NULL,
            cliente_apelido VARCHAR(255) NULL,
            cd_pedido VARCHAR(50) NULL,
            dt_pedido DATE NULL,
            dt_limite_entrega DATE NULL,
            potencia_kva DECIMAL(10,2) NULL DEFAULT 0.00,
            fases INT NULL DEFAULT 3,
            classe_tensao VARCHAR(50) NULL,
            tipo_nucleo VARCHAR(50) NULL,
            tipo_construtivo VARCHAR(100) NULL,
            linha VARCHAR(50) NOT NULL DEFAULT 'TPD',
            seq_plano INT NULL,
            uf VARCHAR(10) NULL,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_extracao_forca (data_extracao),
            INDEX idx_prog_forca (data_programada),
            INDEX idx_linha_forca (linha),
            INDEX idx_pedido_forca (cd_pedido)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
}

/**
 * Extrai snapshot diário de atraso de Média Força do SQL Server e grava no MySQL.
 * Sem $forcarSobrescrita, a foto de hoje já existente fica congelada; com ela
 * (scripts/atualizar_atraso.php, a cada 15 min) a foto de hoje é substituída no lugar e
 * os dias anteriores nunca são tocados.
 */
function boletimExtrairSnapshotAtrasoForca(?string $dataExtracao = null, bool $forcarSobrescrita = false): array
{
    $pdoLocal = getDB();
    if (!$pdoLocal) {
        return ['sucesso' => false, 'erro' => 'Não foi possível conectar ao banco de dados MySQL local.'];
    }

    boletimGarantirTabelasAtrasoForca($pdoLocal);

    $inicioSync = microtime(true);
    $dataHoje = $dataExtracao ?: date('Y-m-d');

    if (!$forcarSobrescrita) {
        $stmtExiste = $pdoLocal->prepare("SELECT COUNT(*) FROM atraso_forca_registros WHERE data_extracao = ?");
        $stmtExiste->execute([$dataHoje]);
        $qtdExistente = (int) $stmtExiste->fetchColumn();
        if ($qtdExistente > 0) {
            return [
                'sucesso' => true,
                'total_importado' => $qtdExistente,
                'data_extracao' => $dataHoje,
                'congelado' => true,
                'mensagem' => "Foto de $dataHoje já está congelada no banco ($qtdExistente ordens de Média Força)."
            ];
        }
    }

    $pdoSrv = getSqlServerDB();
    if (!$pdoSrv) {
        return ['sucesso' => false, 'erro' => 'Não foi possível conectar ao SQL Server local (vsat.trael.local).'];
    }

    $sql = "
    SELECT CAST(Sum(PP.Quantidade) AS INT) AS Quantidade
    , PP.DATAHORAPRODUCAOAUX AS DataHoraProducaoAux
    , M.cd_Referencia AS cd_Referencia
    , M.ds_Prod AS ds_Prod
    , CAST(IPE.qtdItem AS INT) AS qtdItem
    , IPE.dt_LimiteEntrega AS dt_LimiteEntrega
    , Cli.cdEnt AS cdEnt
    , Cli.Nome AS Nome
    , Cli.Apelido AS Apelido
    , Ped.dt_Pedido AS dt_Pedido
    , Ped.cdPedido AS cdPedido
    , PT.ds_potencia AS ds_potencia
    , PT.PotenciaKVA AS PotenciaKVA
    , ET.nrofasesTrafo AS nrofasesTrafo
    , CTT.ds_classeTensaoTrafo AS ds_classeTensaoTrafo
    , PCP.dt_criacao AS dt_criacao
    , PCP.NroRevisao AS NroRevisao
    , PCP.dt_Revisao AS dt_Revisao
    , TP.ds_TensaoTrafo AS ds_TensaoTrafo
    , TS.ds_TensaoTrafo AS ds_TensaoTrafoSec
    , TT.ds_TapsTrafo AS ds_TapsTrafo
    , UF.cd_SglEstado AS cd_SglEstado
    , TEN.ds_TpEnrolamentoNucleo AS ds_TpEnrolamentoNucleo
    , TCT.Ds_tpConstrTrafo AS Ds_tpConstrTrafo
    , TEN.cd_TpEnrolamentoNucleo AS cd_TpEnrolamentoNucleo
    , TensoesTrafoDespacho.ds_TensaoTrafo AS ds_TensaoTrafoDesp
    , CAST(Sum(ORDF.QtdProduzida) AS INT) AS QtdProduzida
    , EmpDestino.cdEnt AS cdEntEmpDesti
    , CtrlItemPedidoPCP.SeqPlano AS SeqPlano
    , CAST(Sum(PP.Quantidade) - Sum(ORDF.QtdProduzida) AS INT) AS QtdAproduzir
     FROM ProgramacaoProducao AS PP WITH(NOLOCK) 
    INNER JOIN Materiais AS M WITH(NOLOCK) ON (M.id_Produto = PP.id_Produto)
    INNER JOIN SubGrupoProduto AS SG WITH(NOLOCK) ON (SG.id_SubGrupoPrd = M.id_SubGrupoPrd)
    INNER JOIN GrupoProduto AS G WITH(NOLOCK) ON (G.id_grpProd = SG.id_grpProd)
    INNER JOIN CatGrupo AS CG WITH(NOLOCK) ON (CG.id_catGrupo = G.id_catGrupo)
    LEFT JOIN RlcProgramacaoItPedido AS RPP WITH(NOLOCK) ON (RPP.id_ProgProdPCP = PP.id_ProgProdPCP AND RPP.PierSitReg = 'ATV')
    LEFT JOIN It_Pedido AS IPE WITH(NOLOCK) ON (IPE.id_it_pedido = RPP.id_it_pedido AND IPE.PierSitReg = 'ATV')
    LEFT JOIN Pedidos AS Ped WITH(NOLOCK) ON (Ped.id_Ped = IPE.id_Ped)
    LEFT JOIN Entidade AS Cli WITH(NOLOCK) ON (Cli.Id_Ent = Ped.id_Cliente)
    LEFT JOIN EspecTrafo AS ET WITH(NOLOCK) ON (ET.id_Produto = M.id_Produto)
    LEFT JOIN Potencia AS PT WITH(NOLOCK) ON (PT.id_potencia = ET.id_potencia)
    LEFT JOIN ClasseTensaoTrafo AS CTT WITH(NOLOCK) ON (CTT.id_classeTensaoTrafo = ET.id_classeTensaoTrafo)
    LEFT JOIN TensoesTrafo AS TP WITH(NOLOCK) ON (TP.id_TensaoTrafo = ET.id_TensaoTrafo)
    LEFT JOIN TensoesTrafo AS TS WITH(NOLOCK) ON (TS.id_TensaoTrafo = ET.id_tensaoTrafoSec)
    LEFT JOIN TapsTrafo AS TT WITH(NOLOCK) ON (TT.id_TapsTrafo = ET.id_TapsTrafo)
    INNER JOIN ControleProjetoPCP AS PCP WITH(NOLOCK) ON (PCP.id_Produto = M.id_Produto AND PCP.PierSitReg = 'ATV')
    LEFT JOIN Enderecos_entid AS EE WITH(NOLOCK) ON (EE.Id_End = Ped.IDEndEntrega)
    LEFT JOIN Unid_Federacao AS UF WITH(NOLOCK) ON (UF.id_SglEstado = EE.id_SglEstado)
    LEFT JOIN TipoEnrolamentoNucleo AS TEN WITH(NOLOCK) ON (TEN.id_TpEnrolamentoNucleo = ET.id_TpEnrolamentoNucleo)
    LEFT JOIN TipoConstrutivoTrafo AS TCT WITH(NOLOCK) ON (TCT.id_tpConstrTrafo = ET.id_tpConstrTrafo)
    LEFT JOIN OrdemFabricacao AS ORDF WITH(NOLOCK) ON (ORDF.id_of = PP.id_of AND ORDF.PierSitReg = 'ATV')
    LEFT JOIN TensoesTrafo AS TensoesTrafoDespacho WITH(NOLOCK) ON (TensoesTrafoDespacho.id_TensaoTrafo = ET.id_tensaoDespacho)
    LEFT JOIN Entidade AS EmpDestino WITH(NOLOCK) ON (EmpDestino.Id_Ent = PP.id_Empresa AND EmpDestino.PierSitReg = 'ATV')
    LEFT JOIN RlcCtrlItemPedidoPCPProgProd AS RlcCtrlItemPedidoPCPProgProd WITH(NOLOCK) ON (RlcCtrlItemPedidoPCPProgProd.id_ProgProdPCP = PP.id_ProgProdPCP AND RlcCtrlItemPedidoPCPProgProd.PierSitReg = 'ATV')
    LEFT JOIN CtrlItemPedidoPCP AS CtrlItemPedidoPCP WITH(NOLOCK) ON (CtrlItemPedidoPCP.IDCtrlItPedidoPCP = RlcCtrlItemPedidoPCPProgProd.IDCtrlItPedidoPCP AND CtrlItemPedidoPCP.PierSitReg = 'ATV')
    WHERE (((PP.id_of > 0)) OR (PP.id_of = 0))
     AND (PP.PierSitReg = 'ATV')
     AND (CG.cd_CatGrupo BETWEEN 40 AND 45)
     AND (PP.DataHoraProducaoAux BETWEEN DATEADD(month, -12, ?) AND DATEADD(day, -1, ?))
     AND (PCP.statusProjeto = 'PRD' OR (PCP.statusProjeto = 'DES' AND NOT EXISTS (SELECT 1 FROM ControleProjetoPCP AS PCP2 WHERE PCP2.id_Produto = M.id_Produto AND PCP2.PierSitReg = 'ATV' AND PCP2.statusProjeto = 'PRD')))
     AND EmpDestino.cdEnt = '4'
     AND ORDF.StatusOF IN ('AGU', 'RES')
    GROUP BY (PP.DATAHORAPRODUCAOAUX)
    , M.cd_Referencia, M.ds_Prod, IPE.qtdItem, IPE.dt_LimiteEntrega, Cli.cdEnt, Cli.Nome, Cli.Apelido, Ped.dt_Pedido, Ped.cdPedido, PT.ds_potencia, PT.PotenciaKVA, ET.nrofasesTrafo, CTT.ds_classeTensaoTrafo, PCP.dt_criacao, PCP.NroRevisao, PCP.dt_Revisao, TP.ds_TensaoTrafo, TS.ds_TensaoTrafo, TT.ds_TapsTrafo, UF.cd_SglEstado, PP.DataHoraProducaoAux, TEN.ds_TpEnrolamentoNucleo, TCT.Ds_tpConstrTrafo, TEN.cd_TpEnrolamentoNucleo, TensoesTrafoDespacho.ds_TensaoTrafo, EmpDestino.cdEnt, CtrlItemPedidoPCP.SeqPlano
    HAVING (CAST(Sum(PP.Quantidade) - Sum(ORDF.QtdProduzida) AS INT) > 0)
    ORDER BY PP.DataHoraProducaoAux
    ";

    try {
        $stmt = $pdoSrv->prepare($sql);
        $stmt->execute([$dataHoje, $dataHoje]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Atualização automática: nunca troca uma foto boa do dia por uma extração vazia.
        if ($forcarSobrescrita && empty($rows)) {
            $stmtAtual = $pdoLocal->prepare("SELECT COUNT(*) FROM atraso_forca_registros WHERE data_extracao = ?");
            $stmtAtual->execute([$dataHoje]);
            $qtdAtual = (int) $stmtAtual->fetchColumn();
            if ($qtdAtual > 0) {
                return [
                    'sucesso'    => false,
                    'preservado' => true,
                    'erro'       => "Extração veio vazia; foto de $dataHoje ($qtdAtual ordens de Média Força) mantida.",
                ];
            }
        }

        // DELETE + INSERT na mesma transação: a tela nunca vê a foto do dia vazia no meio da troca.
        $pdoLocal->beginTransaction();
        $pdoLocal->prepare("DELETE FROM atraso_forca_registros WHERE data_extracao = ?")->execute([$dataHoje]);

        $stmtIns = $pdoLocal->prepare("
            INSERT INTO atraso_forca_registros (
                data_extracao, data_programada, cd_referencia, ds_produto, qtd_item,
                quantidade, qtd_produzida, qtd_a_produzir, cliente_nome, cliente_apelido,
                cd_pedido, dt_pedido, dt_limite_entrega, potencia_kva, fases,
                classe_tensao, tipo_nucleo, tipo_construtivo, linha, seq_plano, uf
            ) VALUES (
                :data_extracao, :data_programada, :cd_referencia, :ds_produto, :qtd_item,
                :quantidade, :qtd_produzida, :qtd_a_produzir, :cliente_nome, :cliente_apelido,
                :cd_pedido, :dt_pedido, :dt_limite_entrega, :potencia_kva, :fases,
                :classe_tensao, :tipo_nucleo, :tipo_construtivo, :linha, :seq_plano, :uf
            )
        ");

        $limpar = function (?string $str): string {
            if ($str === null || $str === '') return '';
            if (!mb_check_encoding($str, 'UTF-8')) {
                $str = mb_convert_encoding($str, 'UTF-8', 'ISO-8859-1, WINDOWS-1252, CP1252');
            }
            return trim($str);
        };

        $totalInserido = 0;
        foreach ($rows as $r) {
            $dtProg = substr((string)$r['DataHoraProducaoAux'], 0, 10);
            $ref = $limpar((string)$r['cd_Referencia']);
            $kva = (float)($r['PotenciaKVA'] ?? 0.0);
            $tipoConst = $limpar((string)($r['Ds_tpConstrTrafo'] ?? ''));
            $linhaForca = boletimClassificarLinhaForca($ref, $kva, $tipoConst);

            $stmtIns->execute([
                'data_extracao'     => $dataHoje,
                'data_programada'   => $dtProg,
                'cd_referencia'     => $ref,
                'ds_produto'        => $limpar((string)($r['ds_Prod'] ?? '')),
                'qtd_item'          => (int)($r['qtdItem'] ?? 1),
                'quantidade'        => (int)($r['Quantidade'] ?? 1),
                'qtd_produzida'     => (int)($r['QtdProduzida'] ?? 0),
                'qtd_a_produzir'    => (int)($r['QtdAproduzir'] ?? 1),
                'cliente_nome'      => $limpar((string)($r['Nome'] ?? '')),
                'cliente_apelido'   => $limpar((string)($r['Apelido'] ?? '')),
                'cd_pedido'         => $limpar((string)($r['cdPedido'] ?? '')),
                'dt_pedido'         => !empty($r['dt_Pedido']) ? substr((string)$r['dt_Pedido'], 0, 10) : null,
                'dt_limite_entrega' => !empty($r['dt_LimiteEntrega']) ? substr((string)$r['dt_LimiteEntrega'], 0, 10) : null,
                'potencia_kva'      => $kva,
                'fases'             => (int)($r['nrofasesTrafo'] ?? 3),
                'classe_tensao'     => $limpar((string)($r['ds_classeTensaoTrafo'] ?? '')),
                'tipo_nucleo'       => $limpar((string)($r['ds_TpEnrolamentoNucleo'] ?? '')),
                'tipo_construtivo'  => $tipoConst,
                'linha'             => $linhaForca,
                'seq_plano'         => !empty($r['SeqPlano']) ? (int)$r['SeqPlano'] : null,
                'uf'                => $limpar((string)($r['cd_SglEstado'] ?? '')),
            ]);
            $totalInserido++;
        }
        $pdoLocal->commit();

        return [
            'sucesso' => true,
            'total_importado' => $totalInserido,
            'data_extracao' => $dataHoje,
            'congelado' => true,
            'duracao_s' => round(microtime(true) - $inicioSync, 1),
            'mensagem' => "Snapshot de Média Força de $dataHoje importado com sucesso ($totalInserido ordens)."
        ];
    } catch (Exception $e) {
        if ($pdoLocal->inTransaction()) {
            $pdoLocal->rollBack();
        }
        return ['sucesso' => false, 'erro' => 'Erro ao extrair do SQL Server: ' . $e->getMessage()];
    }
}

/**
 * Quando a foto de atraso de Média Força foi gravada pela última vez (criado_em das linhas da
 * extração). Base do "Atualizado em HH:MM" da tela e do alerta de dados desatualizados.
 *
 * @return array{data_extracao:string, atualizado_em:string, idade_s:int, ultima:bool}|null
 */
function boletimUltimaAtualizacaoAtrasoForca(?string $dataExtracao = null): ?array
{
    try {
        $pdo = getDB();
        $maxData = (string) $pdo->query("SELECT MAX(data_extracao) FROM atraso_forca_registros")->fetchColumn();
        if ($dataExtracao === null || $dataExtracao === '') {
            $dataExtracao = $maxData;
        }
        if ($dataExtracao === '') {
            return null;
        }
        $stmt = $pdo->prepare("SELECT MAX(criado_em) FROM atraso_forca_registros WHERE data_extracao = ?");
        $stmt->execute([$dataExtracao]);
        $quando = (string) $stmt->fetchColumn();
        if ($quando === '') {
            return null;
        }
        return [
            'data_extracao' => $dataExtracao,
            'atualizado_em' => $quando,
            'idade_s'       => max(0, time() - (int) strtotime($quando)),
            'ultima'        => $dataExtracao === $maxData,
        ];
    } catch (\Throwable $e) {
        return null;
    }
}

/**
 * Calcula todas as métricas analíticas e executivas do Painel de Atraso de Média Força.
 */
function boletimCalcularMetricasAtrasoForca(string $dataCorte = '', ?string $dataExtracao = null, array $mesesFiltro = []): array
{
    $pdo = getDB();
    if (!$pdo) {
        return ['sucesso' => false, 'erro' => 'Sem conexão com banco de dados MySQL local.'];
    }

    boletimGarantirTabelasAtrasoForca($pdo);

    // 1. Determina a data de extração mais recente disponível ou solicitada
    $datasDisponiveis = $pdo->query("
        SELECT DISTINCT data_extracao 
        FROM atraso_forca_registros 
        ORDER BY data_extracao DESC
    ")->fetchAll(PDO::FETCH_COLUMN);

    if (empty($datasDisponiveis)) {
        $resSnap = boletimExtrairSnapshotAtrasoForca();
        if ($resSnap['sucesso']) {
            $datasDisponiveis = [$resSnap['data_extracao']];
        }
    }

    if (empty($dataExtracao) || !in_array($dataExtracao, $datasDisponiveis, true)) {
        $dataExtracao = !empty($datasDisponiveis) ? $datasDisponiveis[0] : date('Y-m-d');
    }

    if (empty($dataCorte)) {
        $dataCorte = $dataExtracao;
    }

    $mesRef = substr($dataCorte, 0, 7); // '2026-09'

    // 2. Dias úteis decorridos no mês até D-1
    $anoCorte = (int) substr($dataCorte, 0, 4);
    $mesCorteNum = (int) substr($dataCorte, 5, 2);
    $diaCorteNum = (int) substr($dataCorte, 8, 2);

    $diasUteisTrabalhados = [];
    for ($dia = 1; $dia < $diaCorteNum; $dia++) {
        $dataYmd = sprintf('%04d-%02d-%02d', $anoCorte, $mesCorteNum, $dia);
        $diaSemana = (int) date('N', strtotime($dataYmd));
        if ($diaSemana <= 5) {
            $diasUteisTrabalhados[] = $dataYmd;
        }
    }
    $totalDiasUteisDMenos1 = count($diasUteisTrabalhados);

    // 3. Produção acumulada no mês corrente de Média Força (Almox 403 / EmpDestino 4)
    $dadosMesRef = boletimObterDadosMes($mesRef);
    $forcaPorDia = $dadosMesRef['forcaPorDia'] ?? [];

    $prodAcumulada = [
        'TPD' => 0,
        'TPM' => 0,
        'TPS' => 0,
    ];
    foreach ($diasUteisTrabalhados as $diaYmd) {
        $p = $forcaPorDia[$diaYmd] ?? [];
        $prodAcumulada['TPD'] += (int) ($p['TPD'] ?? 0);
        $prodAcumulada['TPM'] += (int) ($p['TPM'] ?? 0);
        $prodAcumulada['TPS'] += (int) ($p['TPS'] ?? 0);
    }

    // 4. Médias diárias acumuladas (peças/dia)
    $mediaDiaria = [
        'TPD' => ($totalDiasUteisDMenos1 > 0) ? ($prodAcumulada['TPD'] / $totalDiasUteisDMenos1) : 0.0,
        'TPM' => ($totalDiasUteisDMenos1 > 0) ? ($prodAcumulada['TPM'] / $totalDiasUteisDMenos1) : 0.0,
        'TPS' => ($totalDiasUteisDMenos1 > 0) ? ($prodAcumulada['TPS'] / $totalDiasUteisDMenos1) : 0.0,
    ];

    // Baseline de capacidade consolidada do mês anterior
    $mesAnterior = date('Y-m', strtotime($mesRef . '-01 -1 month'));
    $forcaAnt = boletimObterDadosMes($mesAnterior)['forcaPorDia'] ?? [];
    $mediaDiariaRef = ['TPD' => 0.0, 'TPM' => 0.0, 'TPS' => 0.0];
    if (!empty($forcaAnt)) {
        $diasAnt = count($forcaAnt);
        $pTPDAnt = 0; $pTPMAnt = 0; $pTPSAnt = 0;
        foreach ($forcaAnt as $p) {
            $pTPDAnt += (int) ($p['TPD'] ?? 0);
            $pTPMAnt += (int) ($p['TPM'] ?? 0);
            $pTPSAnt += (int) ($p['TPS'] ?? 0);
        }
        if ($diasAnt > 0) {
            $mediaDiariaRef['TPD'] = $pTPDAnt / $diasAnt;
            $mediaDiariaRef['TPM'] = $pTPMAnt / $diasAnt;
            $mediaDiariaRef['TPS'] = $pTPSAnt / $diasAnt;
        }
    }

    foreach (['TPD', 'TPM', 'TPS'] as $lKey) {
        if ($totalDiasUteisDMenos1 <= 3 || $mediaDiaria[$lKey] <= 0) {
            if ($mediaDiariaRef[$lKey] > 0) {
                $mediaDiaria[$lKey] = $mediaDiariaRef[$lKey];
            }
        }
    }

    // 5. Consulta itens do banco de dados MySQL para Média Força
    $pdo = getDB();
    $stmtItens = $pdo->prepare("
        SELECT 
            id, data_extracao, data_programada, cd_referencia, ds_produto, qtd_item,
            quantidade, qtd_produzida, qtd_a_produzir, cliente_nome, cliente_apelido,
            cd_pedido, dt_pedido, dt_limite_entrega, potencia_kva, fases,
            classe_tensao, tipo_nucleo, tipo_construtivo, linha, seq_plano, uf
        FROM atraso_forca_registros
        WHERE data_extracao = :data_extracao
        ORDER BY data_programada ASC, id ASC
    ");
    $stmtItens->execute(['data_extracao' => $dataExtracao]);
    $todosItens = $stmtItens->fetchAll(PDO::FETCH_ASSOC);

    // Carrega esteira e cache de NS
    $planilhaFluxo = carregarPlanilhaProducaoFluxo();
    $fluxoPorPedProj = [];
    $fluxoPorPed = [];
    $fluxoPorProj = [];
    if (!empty($planilhaFluxo['itens'])) {
        foreach ($planilhaFluxo['itens'] as $fl) {
            $pedFl = trim((string)($fl['pedido'] ?? ''));
            $projFl = trim((string)($fl['projeto'] ?? ''));
            if ($pedFl && $projFl) {
                $fluxoPorPedProj["{$pedFl}_{$projFl}"][] = $fl;
            }
            if ($pedFl) {
                $fluxoPorPed[$pedFl][] = $fl;
            }
            if ($projFl) {
                $fluxoPorProj[$projFl][] = $fl;
            }
        }
    }

    $suplementarNS = [];
    $suplemFile = __DIR__ . '/../storage/cache/atraso_ns_suplementar.json';
    if (is_file($suplemFile)) {
        $rawSuplem = @file_get_contents($suplemFile);
        if ($rawSuplem !== false) {
            $suplementarNS = @json_decode($rawSuplem, true) ?: [];
        }
    }

    $itensAtrasados = [];
    $pecasPorLinha = [
        'TPD' => 0.0,
        'TPM' => 0.0,
        'TPS' => 0.0,
    ];
    $pecasPorMes = [];
    $semanasPorMes = [];
    $mesesDisponiveis = [];

    $mesesNomes = [
        1 => 'JANEIRO', 2 => 'FEVEREIRO', 3 => 'MARÇO', 4 => 'ABRIL',
        5 => 'MAIO', 6 => 'JUNHO', 7 => 'JULHO', 8 => 'AGOSTO',
        9 => 'SETEMBRO', 10 => 'OUTUBRO', 11 => 'NOVEMBRO', 12 => 'DEZEMBRO'
    ];

    foreach ($todosItens as $r) {
        $dtProg = (string) $r['data_programada'];
        $mKey = substr($dtProg, 0, 7);
        $mesNum = (int) substr($dtProg, 5, 2);
        $anoDois = substr($dtProg, 2, 2);
        $mRotulo = ($mesesNomes[$mesNum] ?? 'MÊS') . '/' . $anoDois;

        if (!isset($mesesDisponiveis[$mKey])) {
            $mesesDisponiveis[$mKey] = $mRotulo;
        }

        if ($dtProg > $dataCorte) {
            continue;
        }

        if (!empty($mesesFiltro) && !in_array($mKey, $mesesFiltro, true)) {
            continue;
        }

        $qtd = (int) $r['quantidade'];
        $linha = $r['linha'];
        if (!isset($pecasPorLinha[$linha])) {
            $linha = 'TPD';
        }

        $pecasPorLinha[$linha] += $qtd;

        if (!isset($pecasPorMes[$mKey])) {
            $pecasPorMes[$mKey] = [
                'chave'  => $mKey,
                'rotulo' => $mRotulo,
                'qtd'    => 0.0,
            ];
        }
        $pecasPorMes[$mKey]['qtd'] += $qtd;

        // Distribuição Semanal
        $tsProg = strtotime($dtProg);
        $numSemana = (int) date('W', $tsProg);
        $anoSemana = (int) date('o', $tsProg);

        $dto = new DateTime();
        $dto->setISODate($anoSemana, $numSemana);
        $dtIniSem = $dto->format('d/m');
        $dto->modify('+6 days');
        $dtFimSem = $dto->format('d/m');
        $rotuloSem = "Sem {$numSemana} ({$dtIniSem} a {$dtFimSem})";

        $r['semana_ano'] = $numSemana;
        $r['semana_rotulo'] = $rotuloSem;
        $r['mes_chave'] = $mKey;

        if (!isset($semanasPorMes[$mKey][$numSemana])) {
            $semanasPorMes[$mKey][$numSemana] = [
                'mes_chave'     => $mKey,
                'mes_rotulo'    => $mRotulo,
                'semana'        => $numSemana,
                'rotulo'        => $rotuloSem,
                'qtd'           => 0,
                'tpd'           => 0,
                'tpm'           => 0,
                'tps'           => 0,
            ];
        }
        $semanasPorMes[$mKey][$numSemana]['qtd'] += $qtd;
        if ($linha === 'TPD') $semanasPorMes[$mKey][$numSemana]['tpd'] += $qtd;
        elseif ($linha === 'TPM') $semanasPorMes[$mKey][$numSemana]['tpm'] += $qtd;
        elseif ($linha === 'TPS') $semanasPorMes[$mKey][$numSemana]['tps'] += $qtd;

        $diasAtrasoOrdem = (int) max(0, (strtotime($dataCorte) - strtotime($dtProg)) / 86400);
        $r['dias_atraso_individual'] = $diasAtrasoOrdem;
        $r['mes_ano_rotulo'] = $mRotulo;

        // Cruzamento com Fluxo de Pedidos
        $ped = trim((string)($r['cd_pedido'] ?? ''));
        $proj = trim((string)($r['cd_referencia'] ?? ''));
        $kPedProj = "{$ped}_{$proj}";
        
        $matchedFluxo = $fluxoPorPedProj[$kPedProj] ?? ($fluxoPorPed[$ped] ?? ($fluxoPorProj[$proj] ?? []));

        $nsList = [];
        $setoresConsolidado = [
            'CH' => 'PEND', 'BT' => 'PEND', 'AT' => 'PEND', 'CNC' => 'PEND',
            'SOL' => 'PEND', 'MN' => 'PEND', 'PIN' => 'PEND', 'ME' => 'PEND',
            'MF' => 'PEND', 'LAB' => 'PEND'
        ];
        $fluxoDetalhes = [];

        if (!empty($matchedFluxo)) {
            foreach ($matchedFluxo as $mf) {
                if (!empty($mf['nr_serie'])) {
                    $nsList[] = (string)$mf['nr_serie'];
                }
                $fluxoDetalhes[] = [
                    'nr_serie'     => $mf['nr_serie'] ?? '—',
                    'of_mae'       => $mf['of_mae'] ?? '—',
                    'seq'          => $mf['seq'] ?? '—',
                    'tipo_constr'  => $mf['tipo_constr'] ?? '—',
                    'setores'      => $mf['setores'] ?? [],
                    'is_concluido' => !empty($mf['is_concluido']),
                ];
            }
            if (!empty($matchedFluxo[0]['setores'])) {
                $setoresConsolidado = $matchedFluxo[0]['setores'];
            }
        }

        if (empty($nsList)) {
            $suplSeries = $suplementarNS[$kPedProj] ?? ($suplementarNS[$proj] ?? []);
            if (!empty($suplSeries)) {
                foreach ($suplSeries as $nsNum) {
                    $nsList[] = (string)$nsNum;
                    $fluxoDetalhes[] = [
                        'nr_serie'     => (string)$nsNum,
                        'of_mae'       => '—',
                        'seq'          => '—',
                        'tipo_constr'  => '—',
                        'setores'      => [
                            'CH' => 'OK', 'BT' => 'OK', 'AT' => 'OK', 'CNC' => 'OK',
                            'SOL' => 'OK', 'MN' => 'OK', 'PIN' => 'OK', 'ME' => 'OK',
                            'MF' => 'OK', 'LAB' => 'PEND'
                        ],
                        'is_concluido' => false,
                    ];
                }
                $setoresConsolidado = [
                    'CH' => 'OK', 'BT' => 'OK', 'AT' => 'OK', 'CNC' => 'OK',
                    'SOL' => 'OK', 'MN' => 'OK', 'PIN' => 'OK', 'ME' => 'OK',
                    'MF' => 'OK', 'LAB' => 'PEND'
                ];
            }
        }

        $r['numeros_serie'] = array_values(array_unique($nsList));
        if (count($r['numeros_serie']) > 1) {
            $r['nr_serie_formatado'] = min($r['numeros_serie']) . ' – ' . max($r['numeros_serie']);
        } elseif (count($r['numeros_serie']) === 1) {
            $r['nr_serie_formatado'] = $r['numeros_serie'][0];
        } else {
            $r['nr_serie_formatado'] = '—';
        }

        $r['setores'] = $setoresConsolidado;
        $r['fluxo_detalhes'] = $fluxoDetalhes;

        $itensAtrasados[] = $r;
    }

    krsort($pecasPorMes);
    ksort($mesesDisponiveis);

    // 6. Cálculo dos Indicadores de Atraso em Dias
    $atrasoDias = [
        'TPD' => ($mediaDiaria['TPD'] > 0) ? ($pecasPorLinha['TPD'] / $mediaDiaria['TPD']) : 0.0,
        'TPM' => ($mediaDiaria['TPM'] > 0) ? ($pecasPorLinha['TPM'] / $mediaDiaria['TPM']) : 0.0,
        'TPS' => ($mediaDiaria['TPS'] > 0) ? ($pecasPorLinha['TPS'] / $mediaDiaria['TPS']) : 0.0,
    ];

    $mediaGeralDias = ($atrasoDias['TPD'] + $atrasoDias['TPM'] + $atrasoDias['TPS']) / 3.0;
    $totalPecasAtrasadas = $pecasPorLinha['TPD'] + $pecasPorLinha['TPM'] + $pecasPorLinha['TPS'];

    // 7. Série Temporal para o Gráfico "Média de dias em atraso" (Curva Diária)
    // Média literal: para cada um dos últimos 15 dias úteis, quantos dias as
    // peças ainda em aberto (na foto de hoje) já estavam atrasadas NAQUELE dia
    // — média ponderada por quantidade, dentro de cada linha (TPD/TPM/TPS).
    // Não depende de produção nem de snapshots históricos salvos.
    $diasJanela = [];
    $dataCursor = $dataCorte;
    while (count($diasJanela) < 15) {
        $n = (int) date('N', strtotime($dataCursor));
        if ($n <= 5) {
            $diasJanela[] = $dataCursor;
        }
        $dataCursor = date('Y-m-d', strtotime($dataCursor . ' -1 day'));
    }
    $diasJanela = array_reverse($diasJanela);

    // Snapshots reais já salvos para os dias desta janela (gravados no fim
    // desta função em cada sincronização) — têm prioridade sobre a
    // reconstrução por estimativa, pois refletem a foto de peças em aberto
    // do próprio dia, não a de hoje.
    $historicoRealPorDia = [];
    try {
        $stmtHistReal = $pdo->prepare("
            SELECT data_referencia, media_geral, dias_mono AS dias_tpd, dias_conv AS dias_tpm, dias_jc AS dias_tps, pecas_total
            FROM `atraso_historico_diario`
            WHERE modulo = 'media_forca' AND data_referencia IN (" . implode(',', array_fill(0, count($diasJanela), '?')) . ")
        ");
        $stmtHistReal->execute($diasJanela);
        while ($r = $stmtHistReal->fetch(PDO::FETCH_ASSOC)) {
            $historicoRealPorDia[$r['data_referencia']] = $r;
        }
    } catch (\Throwable $e) {}

    $pecasPorDataProg = [];
    foreach ($todosItens as $r) {
        $dt = (string) $r['data_programada'];
        $l = $r['linha'];
        if (!isset($pecasPorDataProg[$dt])) {
            $pecasPorDataProg[$dt] = ['TPD' => 0, 'TPM' => 0, 'TPS' => 0];
        }
        $pecasPorDataProg[$dt][$l] = ($pecasPorDataProg[$dt][$l] ?? 0) + (int) $r['quantidade'];
    }

    $serieEvolucao = [];
    foreach ($diasJanela as $diaSim) {
        if ($diaSim === $dataCorte) {
            $serieEvolucao[] = [
                'data'         => $diaSim,
                'label'        => date('d/m/Y', strtotime($diaSim)),
                'media_geral'  => round($mediaGeralDias, 2),
                'dias_tpd'     => round($atrasoDias['TPD'], 2),
                'dias_tpm'     => round($atrasoDias['TPM'], 2),
                'dias_tps'     => round($atrasoDias['TPS'], 2),
                'pecas_total'  => (int) round($totalPecasAtrasadas),
            ];
            continue;
        }

        if (isset($historicoRealPorDia[$diaSim])) {
            $hr = $historicoRealPorDia[$diaSim];
            $serieEvolucao[] = [
                'data'         => $diaSim,
                'label'        => date('d/m/Y', strtotime($diaSim)),
                'media_geral'  => (float) $hr['media_geral'],
                'dias_tpd'     => (float) $hr['dias_tpd'],
                'dias_tpm'     => (float) $hr['dias_tpm'],
                'dias_tps'     => (float) $hr['dias_tps'],
                'pecas_total'  => (int) $hr['pecas_total'],
            ];
            continue;
        }

        $somaDiasTpd = 0.0; $qtdTpd = 0;
        $somaDiasTpm = 0.0; $qtdTpm = 0;
        $somaDiasTps = 0.0; $qtdTps = 0;

        foreach ($pecasPorDataProg as $dProg => $linhasQtd) {
            if ($dProg > $diaSim) continue;
            $diasAtrasoNaData = (int) round((strtotime($diaSim) - strtotime($dProg)) / 86400);

            if (!empty($linhasQtd['TPD'])) {
                $somaDiasTpd += $diasAtrasoNaData * $linhasQtd['TPD'];
                $qtdTpd += $linhasQtd['TPD'];
            }
            if (!empty($linhasQtd['TPM'])) {
                $somaDiasTpm += $diasAtrasoNaData * $linhasQtd['TPM'];
                $qtdTpm += $linhasQtd['TPM'];
            }
            if (!empty($linhasQtd['TPS'])) {
                $somaDiasTps += $diasAtrasoNaData * $linhasQtd['TPS'];
                $qtdTps += $linhasQtd['TPS'];
            }
        }

        $diasTpdSim = ($qtdTpd > 0) ? ($somaDiasTpd / $qtdTpd) : 0.0;
        $diasTpmSim = ($qtdTpm > 0) ? ($somaDiasTpm / $qtdTpm) : 0.0;
        $diasTpsSim = ($qtdTps > 0) ? ($somaDiasTps / $qtdTps) : 0.0;
        $mediaGeralSim = ($diasTpdSim + $diasTpmSim + $diasTpsSim) / 3.0;

        $serieEvolucao[] = [
            'data'         => $diaSim,
            'label'        => date('d/m/Y', strtotime($diaSim)),
            'media_geral'  => round($mediaGeralSim, 1),
            'dias_tpd'     => round($diasTpdSim, 2),
            'dias_tpm'     => round($diasTpmSim, 2),
            'dias_tps'     => round($diasTpsSim, 2),
            'pecas_total'  => (int) ($qtdTpd + $qtdTpm + $qtdTps),
        ];
    }

    // Grava o snapshot real de hoje no histórico persistente (idempotente —
    // cada sincronização sobrescreve o valor do próprio dia, então o dia só
    // "congela" de fato quando o calendário vira). Só grava a data de hoje:
    // dias passados não são recalculados aqui.
    if ($dataCorte === date('Y-m-d')) {
        $ultimoPonto = end($serieEvolucao);
        if ($ultimoPonto && $ultimoPonto['data'] === $dataCorte) {
            try {
                $stmtUpHist = $pdo->prepare("
                    INSERT INTO `atraso_historico_diario` (
                        modulo, data_referencia, media_geral, dias_mono, dias_conv, dias_jc, pecas_total
                    ) VALUES (
                        'media_forca', :dt, :media, :tpd, :tpm, :tps, :pecas
                    ) ON DUPLICATE KEY UPDATE
                        media_geral = VALUES(media_geral),
                        dias_mono   = VALUES(dias_mono),
                        dias_conv   = VALUES(dias_conv),
                        dias_jc     = VALUES(dias_jc),
                        pecas_total = VALUES(pecas_total)
                ");
                $stmtUpHist->execute([
                    ':dt'    => $dataCorte,
                    ':media' => $ultimoPonto['media_geral'],
                    ':tpd'   => $ultimoPonto['dias_tpd'],
                    ':tpm'   => $ultimoPonto['dias_tpm'],
                    ':tps'   => $ultimoPonto['dias_tps'],
                    ':pecas' => $ultimoPonto['pecas_total'],
                ]);
            } catch (\Throwable $e) {}
        }
    }

    $semanasPorMesFormatado = [];
    foreach ($semanasPorMes as $mK => $sems) {
        ksort($sems);
        $semanasPorMesFormatado[$mK] = array_values($sems);
    }

    return boletimUtf8Safe([
        'sucesso'               => true,
        'data_corte'            => $dataCorte,
        'data_corte_formatada'  => boletimFormatarDataPorExtenso($dataCorte),
        'data_extracao'         => $dataExtracao,
        'mes_referencia'        => $mesRef,
        'datas_disponiveis'     => $datasDisponiveis,
        'meses_disponiveis'     => $mesesDisponiveis,
        'meses_filtro_ativos'   => $mesesFiltro,
        'dias_trabalhados_d1'   => $totalDiasUteisDMenos1,
        'prod_acumulada'        => $prodAcumulada,
        'media_diaria'          => [
            'TPD' => round($mediaDiaria['TPD'], 2),
            'TPM' => round($mediaDiaria['TPM'], 2),
            'TPS' => round($mediaDiaria['TPS'], 2),
        ],
        'pecas_atraso'          => [
            'TPD'   => (int) round($pecasPorLinha['TPD']),
            'TPM'   => (int) round($pecasPorLinha['TPM']),
            'TPS'   => (int) round($pecasPorLinha['TPS']),
            'TOTAL' => (int) round($totalPecasAtrasadas),
        ],
        'atraso_dias'           => [
            'TPD'         => round($atrasoDias['TPD'], 2),
            'TPM'         => round($atrasoDias['TPM'], 2),
            'TPS'         => round($atrasoDias['TPS'], 2),
            'MEDIA_GERAL' => round($mediaGeralDias, 2),
        ],
        'pecas_por_mes'         => array_values($pecasPorMes),
        'semanas_por_mes'       => $semanasPorMesFormatado,
        'serie_evolucao'        => $serieEvolucao,
        'total_ordens'          => count($itensAtrasados),
        'itens_detalhados'      => $itensAtrasados,
    ]);
}
