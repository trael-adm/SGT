<?php
declare(strict_types=1);

/**
 * Motor da Planilha de Produção do Chão de Fábrica (Padrão Excel)
 * Filtro estrito:
 * - Empresa: 1 (Trael Matriz)
 * - Registros Ativos: PierSitReg = 'ATV'
 * - Categorias de Grupo 40 a 44 (Transformadores Novos)
 * Rateio correto de componentes por sufixo de projeto, lote e NS crescente.
 */

require_once __DIR__ . '/conexao.php';

function avaliarStatusCelulaReal(array $subNos, string $tipo, bool $isMaeEnc): string {
    if ($isMaeEnc) return 'OK';
    if (empty($subNos)) return 'PEND';

    foreach ($subNos as $n) {
        $ref = strtoupper(trim((string)($n['cd_Referencia'] ?? '')));
        $st = strtoupper(trim((string)($n['StatusOF'] ?? '')));
        $qtdProd = (float)($n['QtdProduzida'] ?? 0);
        $qtdTot = (float)($n['Quantidade'] ?? 0);

        $match = false;
        switch ($tipo) {
            case 'CH':
                if (str_starts_with($ref, 'MDA') || str_starts_with($ref, 'MFU')) $match = true;
                break;
            case 'BT':
                if (str_starts_with($ref, 'BT-') || str_starts_with($ref, 'BT_') || $ref === 'BT') $match = true;
                break;
            case 'AT':
                if (str_starts_with($ref, 'AT-') || str_starts_with($ref, 'AT_') || $ref === 'AT') $match = true;
                break;
            case 'CNC':
                if (str_starts_with($ref, 'CNC')) $match = true;
                break;
            case 'SOL':
                if (str_starts_with($ref, 'MTP')) $match = true;
                break;
            case 'MN':
                if (str_starts_with($ref, 'MN-') || str_starts_with($ref, 'MNC')) $match = true;
                break;
            case 'PIN':
                if (str_starts_with($ref, 'MTQ')) $match = true;
                break;
            case 'ME':
                if (str_starts_with($ref, 'ME-') || str_starts_with($ref, 'ME_') || $ref === 'ME' || str_starts_with($ref, 'PA-') || str_starts_with($ref, 'PA_') || $ref === 'PA') $match = true;
                break;
            case 'MF':
                if (str_starts_with($ref, 'MFL')) $match = true;
                break;
            case 'LAB':
                if (str_starts_with($ref, 'LAB')) $match = true;
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

function carregarPlanilhaProducao(
    ?int $limite = null,
    ?string $dtInicio = null,
    ?string $dtFim = null,
    ?int $semana = null,
    ?int $mes = null,
    ?int $ano = null,
    ?string $filtroPedido = null,
    ?string $filtroProjeto = null,
    ?string $filtroNS = null,
    ?string $statusFila = 'em_aberto', // 'todos', 'em_aberto', 'concluidos'
    ?string $filtroEmpresa = null // '1', '4' ou null (ambas)
): array {
    $pdo = getSqlServerDB();
    if (!$pdo) {
        return ['sucesso' => false, 'erro' => 'Não foi possível conectar ao SQL Server Trael.'];
    }

    $where = [
        "cns.NumSerie > 0", 
        "p.PierSitReg = 'ATV'",
        "it.PierSitReg = 'ATV'",
        "p.dt_Pedido >= '2026-01-01'", 
        "p.StatusPedido NOT IN ('CAN', 'ENT')",
        "it.QtdSaldo > 0",
        "cg.cd_CatGrupo BETWEEN 40 AND 44"
    ];
    $params = [];

    if ($filtroEmpresa === '1') {
        $where[] = "COALESCE(emp_prog.cdEnt, emp_ped.cdEnt, '1') = '1'";
    } elseif ($filtroEmpresa === '4') {
        $where[] = "COALESCE(emp_prog.cdEnt, emp_ped.cdEnt, '1') = '4'";
    }

    if ($statusFila === 'em_aberto') {
        $where[] = "(ofp.StatusOF NOT IN ('ENC') OR ofp.StatusOF IS NULL)";
    } elseif ($statusFila === 'concluidos') {
        $where[] = "ofp.StatusOF = 'ENC'";
    }

    if (!empty($dtInicio)) {
        $where[] = "prog.DataHoraProducaoAux >= ?";
        $params[] = $dtInicio . ' 00:00:00';
    }
    if (!empty($dtFim)) {
        $where[] = "prog.DataHoraProducaoAux <= ?";
        $params[] = $dtFim . ' 23:59:59';
    }
    if (!empty($semana) && $semana > 0) {
        $where[] = "DATEPART(WEEK, prog.DataHoraProducaoAux) = ?";
        $params[] = $semana;
    }
    if (!empty($mes) && $mes > 0) {
        $where[] = "DATEPART(MONTH, prog.DataHoraProducaoAux) = ?";
        $params[] = $mes;
    }
    if (!empty($ano) && $ano > 0) {
        $where[] = "DATEPART(YEAR, prog.DataHoraProducaoAux) = ?";
        $params[] = $ano;
    }

    if (!empty($filtroPedido)) {
        $where[] = "p.cdPedido LIKE ?";
        $params[] = "%$filtroPedido%";
    }
    if (!empty($filtroProjeto)) {
        $where[] = "(m.cd_Referencia LIKE ? OR m.ds_Prod LIKE ?)";
        $params[] = "%$filtroProjeto%";
        $params[] = "%$filtroProjeto%";
    }
    if (!empty($filtroNS)) {
        $where[] = "cns.NumSerie LIKE ?";
        $params[] = "%$filtroNS%";
    }

    $whereStr = implode(' AND ', $where);
    $topClause = ($limite !== null && $limite > 0) ? "TOP $limite" : "";

    $sql = "
        SELECT $topClause
            cns.NumSerie,
            cns.id_ProgProdPCP,
            COALESCE(emp_prog.cdEnt, emp_ped.cdEnt, '1') AS EmpDestino,
            p.cdPedido AS Pedido,
            cli.Nome AS Cliente,
            cli.Apelido AS ClienteApelido,
            m.cd_Referencia AS Projeto,
            m.ds_Prod AS DescricaoProjeto,
            pot.PotenciaKVA,
            cl.ds_classeTensaoTrafo AS ClasseTensao,
            tp.ds_TpEnrolamentoNucleo AS TipoNucleo,
            esp.nrofasesTrafo AS Fases,
            norm.ds_normaTrafo AS DescrNormaTrafo,
            tc.ds_tpConstrTrafo AS DsTipoConstrTrafo,
            prog.DataHoraProducaoAux,
            DATEPART(WEEK, prog.DataHoraProducaoAux) AS SEM,
            ofp.id_of,
            ofp.cd_of AS OF_Mae,
            ofp.dt_OF AS DataOF_Mae,
            ofp.StatusOF,
            ofp.Quantidade AS Qtd_OF_Mae,
            ofp.QtdProduzida AS QtdProd_OF_Mae,
            cg.cd_CatGrupo,
            cg.ds_CatGrupo
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
        LEFT JOIN dbo.EspecTrafo esp WITH(NOLOCK) ON m.id_Produto = esp.id_Produto
        LEFT JOIN dbo.Potencia pot WITH(NOLOCK) ON esp.id_potencia = pot.id_potencia
        LEFT JOIN dbo.ClasseTensaoTrafo cl WITH(NOLOCK) ON esp.id_classeTensaoTrafo = cl.id_classeTensaoTrafo
        LEFT JOIN dbo.TipoEnrolamentoNucleo tp WITH(NOLOCK) ON esp.id_TpEnrolamentoNucleo = tp.id_TpEnrolamentoNucleo
        LEFT JOIN dbo.NormaTrafo norm WITH(NOLOCK) ON esp.id_normaTrafo = norm.id_normaTrafo
        LEFT JOIN dbo.TipoConstrutivoTrafo tc WITH(NOLOCK) ON esp.id_tpConstrTrafo = tc.id_tpConstrTrafo
        WHERE $whereStr
        ORDER BY p.dt_Pedido ASC, p.cdPedido ASC, cns.NumSerie ASC
    ";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $registros = $stmt->fetchAll();
    } catch (Throwable $e) {
        return ['sucesso' => false, 'erro' => 'Erro ao consultar planilha: ' . $e->getMessage()];
    }

    if (empty($registros)) {
        return ['sucesso' => true, 'total' => 0, 'itens' => []];
    }

    // 1. Buscar todas as sub-OFs da árvore de componentes diretamente via SQL Server
    $componentesPorProgId = [];
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
        LEFT JOIN dbo.EspecTrafo esp WITH(NOLOCK) ON m.id_Produto = esp.id_Produto
        LEFT JOIN dbo.Potencia pot WITH(NOLOCK) ON esp.id_potencia = pot.id_potencia
        LEFT JOIN dbo.ClasseTensaoTrafo cl WITH(NOLOCK) ON esp.id_classeTensaoTrafo = cl.id_classeTensaoTrafo
        LEFT JOIN dbo.TipoEnrolamentoNucleo tp WITH(NOLOCK) ON esp.id_TpEnrolamentoNucleo = tp.id_TpEnrolamentoNucleo
        LEFT JOIN dbo.NormaTrafo norm WITH(NOLOCK) ON esp.id_normaTrafo = norm.id_normaTrafo
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
            m_sub.cd_Referencia LIKE 'MDA%' OR
            m_sub.cd_Referencia LIKE 'MFU%' OR
            m_sub.cd_Referencia LIKE 'BT%' OR
            m_sub.cd_Referencia LIKE 'AT%' OR
            m_sub.cd_Referencia LIKE 'CNC%' OR
            m_sub.cd_Referencia LIKE 'MTP%' OR
            m_sub.cd_Referencia LIKE 'MN%' OR
            m_sub.cd_Referencia LIKE 'MNC%' OR
            m_sub.cd_Referencia LIKE 'MTQ%' OR
            m_sub.cd_Referencia LIKE 'PA%' OR
            m_sub.cd_Referencia LIKE 'ME%' OR
            m_sub.cd_Referencia LIKE 'MFL%' OR
            m_sub.cd_Referencia LIKE 'LAB%'
          )
    ";
    try {
        $stmtSub = $pdo->prepare($sqlSub);
        $stmtSub->execute($params);
        while ($row = $stmtSub->fetch()) {
            $componentesPorProgId[$row['id_Raiz']][] = $row;
        }
    } catch (Throwable $e) {}

    $trafosFinais = [];
    $totalConcluidos = 0;
    $totalPendentes = 0;

    foreach ($registros as $r) {
        $ns = (int)$r['NumSerie'];
        $statusMae = strtoupper(trim((string)($r['StatusOF'] ?? '')));
        $isMaeEnc = ($statusMae === 'ENC');
        $subNos = $componentesPorProgId[$r['id_ProgProdPCP']] ?? [];

        $dtProg = !empty($r['DataHoraProducaoAux']) ? date('d/m/Y', strtotime($r['DataHoraProducaoAux'])) : '—';
        $classe = '15';
        if (preg_match('/(\d+[\.,]?\d*)/', (string)$r['ClasseTensao'], $mCl)) {
            $classe = (string) round((float)str_replace(',', '.', $mCl[1]));
        }

        $tapsRaw = strtoupper(trim((string)($r['TipoNucleo'] ?? '')));
        $fasesRaw = strtoupper(trim((string)($r['Fases'] ?? '')));
        $taps = 'ENR';
        $multBobinas = 2;
        if (strpos($tapsRaw, 'EMP') !== false) {
            $taps = 'EMP';
            $multBobinas = 3;
        } elseif (strpos($tapsRaw, 'JC') !== false) {
            if ($fasesRaw === 'TRI' || strpos($fasesRaw, '3F') !== false) {
                $taps = 'JC-TRIF';
                $multBobinas = 3;
            } else {
                $taps = 'JC';
                $multBobinas = 2;
            }
        }

        $setores = [
            'CH'    => avaliarStatusCelulaReal($subNos, 'CH', $isMaeEnc),
            'BT'    => avaliarStatusCelulaReal($subNos, 'BT', $isMaeEnc),
            'AT'    => avaliarStatusCelulaReal($subNos, 'AT', $isMaeEnc),
            'CNC'   => avaliarStatusCelulaReal($subNos, 'CNC', $isMaeEnc),
            'SOL'   => avaliarStatusCelulaReal($subNos, 'SOL', $isMaeEnc),
            'MN'    => avaliarStatusCelulaReal($subNos, 'MN', $isMaeEnc),
            'PIN'   => avaliarStatusCelulaReal($subNos, 'PIN', $isMaeEnc),
            'ME'    => $isMaeEnc ? 'OK' : avaliarStatusCelulaReal($subNos, 'ME', $isMaeEnc),
            'MF'    => avaliarStatusCelulaReal($subNos, 'MF', $isMaeEnc),
            'LAB'   => $isMaeEnc ? 'OK' : avaliarStatusCelulaReal($subNos, 'LAB', $isMaeEnc),
        ];

        $pendentesCount = 0;
        foreach ($setores as $st) {
            if ($st !== 'OK') $pendentesCount++;
        }

        $isConcluido = ($pendentesCount === 0);
        if ($isConcluido) $totalConcluidos++;
        else $totalPendentes++;

        $trafosFinais[] = [
            'pedido'        => trim((string)$r['Pedido']),
            'empresa'       => trim((string)($r['EmpDestino'] ?? '1')) ?: '1',
            'data'          => $dtProg,
            'data_raw'      => $r['DataHoraProducaoAux'],
            'projeto'       => trim((string)$r['Projeto']),
            'desc_projeto'  => trim((string)$r['DescricaoProjeto']),
            'cliente'       => trim((string)($r['ClienteApelido'] ?: $r['Cliente'])),
            'qtde'          => 1,
            'pot'           => (float)($r['PotenciaKVA'] ?? 0),
            'class'         => $classe,
            'taps'          => $taps,
            'tipo_constr'   => trim((string)($r['DsTipoConstrTrafo'] ?? '')) ?: '—',
            'sem'           => (int)($r['SEM'] ?? 0),
            'nr_serie'      => $ns,
            'of_mae'        => (int)($r['OF_Mae'] ?? 0),
            'mult_bobinas'  => $multBobinas,
            'cat_grupo'     => (int)($r['cd_CatGrupo'] ?? 0),
            'setores'       => $setores,
            'is_concluido'  => $isConcluido,
        ];
    }

    return [
        'sucesso'          => true,
        'total'            => count($trafosFinais),
        'total_concluidos' => $totalConcluidos,
        'total_pendentes'  => $totalPendentes,
        'itens'            => $trafosFinais,
    ];
}
