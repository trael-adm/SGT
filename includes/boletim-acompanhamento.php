<?php
declare(strict_types=1);

/**
 * Módulo Acompanhamento: Tanque (Pintura) × Parte Ativa (Montagem Elétrica) → Montagem Final
 * Regras e rastreabilidade validadas no chão de fábrica (Empresa 1).
 */

require_once __DIR__ . '/../config/conexao.php';

function avaliarStatusCelulaAcompanhamento(array $subNos, string $tipo, bool $isMaeEnc): string
{
    if ($isMaeEnc) {
        return 'OK';
    }
    if (empty($subNos)) {
        return 'PEND';
    }

    foreach ($subNos as $n) {
        $ref     = strtoupper(trim((string) ($n['cd_Referencia'] ?? '')));
        $st      = strtoupper(trim((string) ($n['StatusOF'] ?? '')));
        $qtdProd = (float) ($n['QtdProduzida'] ?? 0);
        $qtdTot  = (float) ($n['Quantidade'] ?? 0);

        $match = false;
        switch ($tipo) {
            case 'PIN': // Pintura / Tanque
                if (str_starts_with($ref, 'MTQ')) {
                    $match = true;
                }
                break;
            case 'ME': // Montagem Elétrica / Parte Ativa
                if (str_starts_with($ref, 'ME-') || str_starts_with($ref, 'ME_') || $ref === 'ME'
                    || str_starts_with($ref, 'PA-') || str_starts_with($ref, 'PA_') || $ref === 'PA') {
                    $match = true;
                }
                break;
            case 'MF': // Montagem Final
                if (str_starts_with($ref, 'MFL')) {
                    $match = true;
                }
                break;
        }

        if ($match) {
            if ($st === 'ENC' || ($qtdTot > 0 && $qtdProd >= $qtdTot)) {
                return 'OK';
            }
        }
    }
    return 'PEND';
}

/**
 * Regras de classificação do Acompanhamento:
 * - Montagem Final OK + Pintura OK + Montagem Elétrica OK → null (ciclo completo encerrado)
 * - Montagem Final OK + (Pintura ou Montagem Elétrica != OK) → "VERIFICAR APONTAMENTO"
 * - Montagem Final != OK + Pintura OK + Montagem Elétrica OK → "DESCER PARA MONTAGEM FINAL"
 * - Montagem Final != OK + Montagem Elétrica OK + Pintura != OK → "PINTAR TANQUE"
 * - Montagem Final != OK + Pintura OK + Montagem Elétrica != OK → "GUARDAR NA ESTUFA"
 * - Nenhuma das duas pronta ainda → null (não entra na fila)
 */
function classificarAcompanhamento(string $pintura, string $montagemEletrica, string $montagemFinal): ?string
{
    if ($montagemFinal === 'OK') {
        if ($pintura === 'OK' && $montagemEletrica === 'OK') {
            return null;
        }
        return 'VERIFICAR APONTAMENTO';
    }

    if ($pintura === 'OK' && $montagemEletrica === 'OK') {
        return 'DESCER PARA MONTAGEM FINAL';
    }
    if ($montagemEletrica === 'OK') {
        return 'PINTAR TANQUE';
    }
    if ($pintura === 'OK') {
        return 'GUARDAR NA ESTUFA';
    }

    return null;
}

function carregarAcompanhamentoProducao(): array
{
    $pdo = getSqlServerDB();
    if (!$pdo) {
        return ['sucesso' => false, 'erro' => 'Não foi possível conectar ao SQL Server Trael.', 'itens' => []];
    }

    $where = [
        "cns.NumSerie > 0",
        "p.PierSitReg = 'ATV'",
        "it.PierSitReg = 'ATV'",
        "p.dt_Pedido >= '2026-01-01'",
        "p.StatusPedido NOT IN ('CAN', 'ENT')",
        "it.QtdSaldo > 0",
        "cg.cd_CatGrupo BETWEEN 40 AND 44",
        "COALESCE(emp_prog.cdEnt, emp_ped.cdEnt, '1') = '1'",
    ];
    $whereStr = implode(' AND ', $where);

    // Consulta sem produto cartesiano, capturando SeqPlano via OUTER APPLY TOP 1
    $sql = "
        SELECT
            cns.NumSerie,
            cns.id_ProgProdPCP,
            p.cdPedido AS Pedido,
            cli.Nome AS Cliente,
            cli.Apelido AS ClienteApelido,
            m.cd_Referencia AS Projeto,
            m.ds_Prod AS DescricaoProjeto,
            prog.DataHoraProducaoAux,
            ISNULL(seq_info.SeqPlano, 0) AS SeqPlano,
            ofp.StatusOF
        FROM dbo.CtrlNumSerie cns WITH(NOLOCK)
        JOIN dbo.ProgramacaoProducao prog WITH(NOLOCK) ON cns.id_ProgProdPCP = prog.id_ProgProdPCP
        JOIN dbo.It_Pedido it WITH(NOLOCK) ON cns.id_it_pedido = it.id_it_pedido
        JOIN dbo.Pedidos p WITH(NOLOCK) ON it.id_Ped = p.id_Ped
        JOIN dbo.Entidade cli WITH(NOLOCK) ON p.id_Cliente = cli.Id_Ent
        LEFT JOIN dbo.Entidade emp_ped WITH(NOLOCK) ON p.id_Empresa = emp_ped.Id_Ent
        LEFT JOIN dbo.Entidade emp_prog WITH(NOLOCK) ON prog.id_Empresa = emp_prog.Id_Ent
        JOIN dbo.Materiais m WITH(NOLOCK) ON cns.id_Produto = m.id_Produto
        JOIN dbo.SubGrupoProduto sg WITH(NOLOCK) ON m.id_SubGrupoPrd = sg.id_SubGrupoPrd
        JOIN dbo.GrupoProduto gp WITH(NOLOCK) ON sg.id_grpProd = gp.id_grpProd
        JOIN dbo.CatGrupo cg WITH(NOLOCK) ON gp.id_catGrupo = cg.id_catGrupo
        LEFT JOIN dbo.OrdemFabricacao ofp WITH(NOLOCK) ON cns.id_of = ofp.id_of
        OUTER APPLY (
            SELECT TOP 1 cip.SeqPlano
            FROM dbo.CtrlItemPedidoPCP cip WITH(NOLOCK)
            WHERE cip.id_it_pedido = it.id_it_pedido
              AND cip.PierSitReg = 'ATV'
            ORDER BY cip.IDCtrlItPedidoPCP DESC
        ) AS seq_info
        WHERE $whereStr
        ORDER BY prog.DataHoraProducaoAux ASC, ISNULL(seq_info.SeqPlano, 0) ASC, p.cdPedido ASC, cns.NumSerie ASC
    ";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $registros = $stmt->fetchAll();
    } catch (Throwable $e) {
        return ['sucesso' => false, 'erro' => 'Erro ao consultar produção: ' . $e->getMessage(), 'itens' => []];
    }

    if (empty($registros)) {
        return ['sucesso' => true, 'itens' => []];
    }

    // Sub-OFs da árvore de componentes
    $sqlSub = "
        SELECT DISTINCT
            cns.id_ProgProdPCP AS id_Raiz,
            m_sub.cd_Referencia,
            ofp_sub.StatusOF,
            ofp_sub.Quantidade,
            ofp_sub.QtdProduzida
        FROM dbo.CtrlNumSerie cns WITH(NOLOCK)
        JOIN dbo.ProgramacaoProducao prog WITH(NOLOCK) ON cns.id_ProgProdPCP = prog.id_ProgProdPCP
        JOIN dbo.It_Pedido it WITH(NOLOCK) ON cns.id_it_pedido = it.id_it_pedido
        JOIN dbo.Pedidos p WITH(NOLOCK) ON it.id_Ped = p.id_Ped
        JOIN dbo.Entidade cli WITH(NOLOCK) ON p.id_Cliente = cli.Id_Ent
        LEFT JOIN dbo.Entidade emp_ped WITH(NOLOCK) ON p.id_Empresa = emp_ped.Id_Ent
        LEFT JOIN dbo.Entidade emp_prog WITH(NOLOCK) ON prog.id_Empresa = emp_prog.Id_Ent
        JOIN dbo.Materiais m WITH(NOLOCK) ON cns.id_Produto = m.id_Produto
        JOIN dbo.SubGrupoProduto sg WITH(NOLOCK) ON m.id_SubGrupoPrd = sg.id_SubGrupoPrd
        JOIN dbo.GrupoProduto gp WITH(NOLOCK) ON sg.id_grpProd = gp.id_grpProd
        JOIN dbo.CatGrupo cg WITH(NOLOCK) ON gp.id_catGrupo = cg.id_catGrupo
        LEFT JOIN dbo.OrdemFabricacao ofp WITH(NOLOCK) ON cns.id_of = ofp.id_of
        JOIN dbo.RlcProgramacao r1 WITH(NOLOCK) ON cns.id_ProgProdPCP = r1.id_ProgProdPCP AND r1.PierSitReg = 'ATV'
        LEFT JOIN dbo.RlcProgramacao r2 WITH(NOLOCK) ON r1.IDProgProdPCPAnt = r2.id_ProgProdPCP AND r2.PierSitReg = 'ATV'
        LEFT JOIN dbo.RlcProgramacao r3 WITH(NOLOCK) ON r2.IDProgProdPCPAnt = r3.id_ProgProdPCP AND r3.PierSitReg = 'ATV'
        LEFT JOIN dbo.RlcProgramacao r4 WITH(NOLOCK) ON r3.IDProgProdPCPAnt = r4.id_ProgProdPCP AND r4.PierSitReg = 'ATV'
        LEFT JOIN dbo.RlcProgramacao r5 WITH(NOLOCK) ON r4.IDProgProdPCPAnt = r5.id_ProgProdPCP AND r5.PierSitReg = 'ATV'
        CROSS APPLY (
            SELECT r1.IDProgProdPCPAnt AS id_Filho
            UNION SELECT r2.IDProgProdPCPAnt WHERE r2.IDProgProdPCPAnt IS NOT NULL
            UNION SELECT r3.IDProgProdPCPAnt WHERE r3.IDProgProdPCPAnt IS NOT NULL
            UNION SELECT r4.IDProgProdPCPAnt WHERE r4.IDProgProdPCPAnt IS NOT NULL
            UNION SELECT r5.IDProgProdPCPAnt WHERE r5.IDProgProdPCPAnt IS NOT NULL
        ) AS sub
        JOIN dbo.ProgramacaoProducao pp WITH(NOLOCK) ON sub.id_Filho = pp.id_ProgProdPCP
        JOIN dbo.Materiais m_sub WITH(NOLOCK) ON pp.id_Produto = m_sub.id_Produto
        LEFT JOIN dbo.OrdemFabricacao ofp_sub WITH(NOLOCK) ON pp.id_of = ofp_sub.id_of
        WHERE $whereStr
          AND (
            m_sub.cd_Referencia LIKE 'MTQ%' OR
            m_sub.cd_Referencia LIKE 'ME%' OR
            m_sub.cd_Referencia LIKE 'PA%' OR
            m_sub.cd_Referencia LIKE 'MFL%'
          )
    ";

    $componentesPorProgId = [];
    try {
        $stmtSub = $pdo->prepare($sqlSub);
        $stmtSub->execute();
        while ($row = $stmtSub->fetch()) {
            $componentesPorProgId[$row['id_Raiz']][] = $row;
        }
    } catch (Throwable $e) {
        // Fallback gracioso
    }

    $itens = [];
    foreach ($registros as $r) {
        $statusMae = strtoupper(trim((string) ($r['StatusOF'] ?? '')));
        $isMaeEnc  = ($statusMae === 'ENC');
        $subNos    = $componentesPorProgId[$r['id_ProgProdPCP']] ?? [];

        $pintura          = avaliarStatusCelulaAcompanhamento($subNos, 'PIN', $isMaeEnc);
        $montagemEletrica = avaliarStatusCelulaAcompanhamento($subNos, 'ME', $isMaeEnc);
        $montagemFinal    = avaliarStatusCelulaAcompanhamento($subNos, 'MF', $isMaeEnc);

        $acao = classificarAcompanhamento($pintura, $montagemEletrica, $montagemFinal);
        if ($acao === null) {
            continue;
        }

        $dtTimestamp = !empty($r['DataHoraProducaoAux']) ? strtotime((string) $r['DataHoraProducaoAux']) : 0;
        $dtFormatada = $dtTimestamp > 0 ? date('d/m/Y', $dtTimestamp) : '—';
        $seq = (int) ($r['SeqPlano'] ?? 0);

        $itens[] = [
            'pedido'             => trim((string) $r['Pedido']),
            'data'               => $dtFormatada,
            'data_raw'           => $dtTimestamp,
            'seq'                => $seq > 0 ? (string) $seq : '—',
            'seq_num'            => $seq,
            'projeto'            => trim((string) $r['Projeto']),
            'desc_projeto'       => trim((string) $r['DescricaoProjeto']),
            'cliente'            => trim((string) ($r['ClienteApelido'] ?: $r['Cliente'])),
            'nr_serie'           => (int) $r['NumSerie'],
            'pintura'            => $pintura,
            'montagem_eletrica'  => $montagemEletrica,
            'montagem_final'     => $montagemFinal,
            'acao'               => $acao,
        ];
    }

    return ['sucesso' => true, 'itens' => $itens];
}