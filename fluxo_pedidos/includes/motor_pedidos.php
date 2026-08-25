<?php
declare(strict_types=1);

/**
 * SGT — Motor de Pedidos & Classificação de Setores (2026)
 * Filtro estrito:
 * - Empresa: 1 (Trael Matriz)
 * - Registros Ativos: PierSitReg = 'ATV' (tabelas Pedidos e It_Pedido)
 * - Categorias de Grupo 40 a 44 (Transformadores Novos)
 * - Exclusão de ENT (Entregues) e CAN (Cancelados) com Saldo > 0
 */

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/followup_parser.php';

/**
 * Carrega e classifica todos os pedidos de 2026 em aberto nos 5 setores da esteira.
 */
function carregarEsteiraPedidos2026(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $pdo = getSqlServerDB();
    if (!$pdo) {
        return ['sucesso' => false, 'erro' => 'Não foi possível conectar ao SQL Server Trael.'];
    }

    // 1. Consulta base de Pedidos e Itens de 2026 em aberto na Empresa 1
    $sql = "
        SELECT 
            p.id_Ped,
            p.cdPedido,
            p.dt_Pedido,
            p.dt_LimiteEntrega,
            p.StatusPedido,
            p.PedidoCliente,
            p.vlPedido,
            p.Vlr_Liquido,
            p.id_Cliente,
            cli.Nome AS Cliente,
            cli.Apelido AS ClienteApelido,
            it.id_it_pedido,
            it.id_Produto,
            it.qtdItem,
            it.QtdSaldo,
            it.PrecoUnitario,
            m.cd_Referencia,
            m.ds_Prod,
            pot.PotenciaKVA,
            cl.ds_classeTensaoTrafo AS ClasseTensao,
            tp.ds_TpEnrolamentoNucleo AS TipoNucleo,
            esp.nrofasesTrafo AS Fases,
            norm.ds_normaTrafo AS DescrNormaTrafo,
            cg.cd_CatGrupo,
            cg.ds_CatGrupo
        FROM dbo.Pedidos p WITH(NOLOCK)
        JOIN dbo.Entidade cli WITH(NOLOCK) ON p.id_Cliente = cli.Id_Ent
        JOIN dbo.It_Pedido it WITH(NOLOCK) ON p.id_Ped = it.id_Ped
        JOIN dbo.Materiais m WITH(NOLOCK) ON it.id_Produto = m.id_Produto
        JOIN dbo.SubGrupoProduto sg WITH(NOLOCK) ON m.id_SubGrupoPrd = sg.id_SubGrupoPrd
        JOIN dbo.GrupoProduto gp WITH(NOLOCK) ON sg.id_grpProd = gp.id_grpProd
        JOIN dbo.CatGrupo cg WITH(NOLOCK) ON gp.id_catGrupo = cg.id_catGrupo
        LEFT JOIN dbo.EspecTrafo esp WITH(NOLOCK) ON m.id_Produto = esp.id_Produto
        LEFT JOIN dbo.Potencia pot WITH(NOLOCK) ON esp.id_potencia = pot.id_potencia
        LEFT JOIN dbo.ClasseTensaoTrafo cl WITH(NOLOCK) ON esp.id_classeTensaoTrafo = cl.id_classeTensaoTrafo
        LEFT JOIN dbo.TipoEnrolamentoNucleo tp WITH(NOLOCK) ON esp.id_TpEnrolamentoNucleo = tp.id_TpEnrolamentoNucleo
        LEFT JOIN dbo.NormaTrafo norm WITH(NOLOCK) ON esp.id_normaTrafo = norm.id_normaTrafo
        WHERE p.id_Empresa = 1
          AND p.PierSitReg = 'ATV'
          AND it.PierSitReg = 'ATV'
          AND p.dt_Pedido >= '2026-01-01'
          AND p.StatusPedido NOT IN ('CAN', 'ENT')
          AND it.QtdSaldo > 0
          AND cg.cd_CatGrupo BETWEEN 40 AND 44
        ORDER BY p.dt_Pedido ASC, p.cdPedido ASC
    ";

    try {
        $stmt = $pdo->query($sql);
        $linhas = $stmt->fetchAll();
    } catch (Throwable $e) {
        return ['sucesso' => false, 'erro' => 'Erro ao consultar pedidos: ' . $e->getMessage()];
    }

    // 2. Programação do PCP (quantidade programada com OFs via CtrlNumSerie nas categorias 40 a 44)
    $sqlProg = "
        SELECT 
            p.cdPedido,
            COUNT(DISTINCT cns.NumSerie) AS TotalNS_Gerados,
            SUM(prog.Quantidade) AS QtdProgramada,
            COUNT(DISTINCT prog.id_ProgProdPCP) AS TotalOFs
        FROM dbo.CtrlNumSerie cns WITH(NOLOCK)
        JOIN dbo.ProgramacaoProducao prog WITH(NOLOCK) ON cns.id_ProgProdPCP = prog.id_ProgProdPCP
        JOIN dbo.It_Pedido it WITH(NOLOCK) ON cns.id_it_pedido = it.id_it_pedido
        JOIN dbo.Pedidos p WITH(NOLOCK) ON it.id_Ped = p.id_Ped
        JOIN dbo.Materiais m WITH(NOLOCK) ON it.id_Produto = m.id_Produto
        JOIN dbo.SubGrupoProduto sg WITH(NOLOCK) ON m.id_SubGrupoPrd = sg.id_SubGrupoPrd
        JOIN dbo.GrupoProduto gp WITH(NOLOCK) ON sg.id_grpProd = gp.id_grpProd
        JOIN dbo.CatGrupo cg WITH(NOLOCK) ON gp.id_catGrupo = cg.id_catGrupo
        WHERE p.id_Empresa = 1
          AND p.PierSitReg = 'ATV'
          AND it.PierSitReg = 'ATV'
          AND p.dt_Pedido >= '2026-01-01'
          AND p.StatusPedido NOT IN ('CAN', 'ENT')
          AND prog.PierSitReg = 'ATV'
          AND cg.cd_CatGrupo BETWEEN 40 AND 44
        GROUP BY p.cdPedido
    ";
    $programadosMap = [];
    try {
        $stmtProg = $pdo->query($sqlProg);
        foreach ($stmtProg->fetchAll() as $pr) {
            $programadosMap[(int)$pr['cdPedido']] = [
                'qtd_programada' => (float)$pr['QtdProgramada'],
                'total_ns'       => (int)$pr['TotalNS_Gerados'],
                'total_ofs'      => (int)$pr['TotalOFs'],
            ];
        }
    } catch (Throwable $e) {}

    // 3. Pré-programação do PCP
    $sqlPre = "SELECT DISTINCT cdPedido FROM dw.vw_pre_programacao_novo WITH(NOLOCK) WHERE ano >= 2026";
    $preProgMap = [];
    try {
        $stmtPre = $pdo->query($sqlPre);
        foreach ($stmtPre->fetchAll() as $pp) {
            $preProgMap[(int)$pp['cdPedido']] = true;
        }
    } catch (Throwable $e) {}

    // 4. Saldo em Almoxarifados 10 e 11
    $sqlAlmox = "
        SELECT s.id_Produto, SUM(s.QtdSaldoFisico) AS SaldoEstoque
        FROM dbo.SaldoporAlmox s WITH(NOLOCK)
        JOIN dbo.Materiais m WITH(NOLOCK) ON s.id_Produto = m.id_Produto
        JOIN dbo.SubGrupoProduto sg WITH(NOLOCK) ON m.id_SubGrupoPrd = sg.id_SubGrupoPrd
        JOIN dbo.GrupoProduto gp WITH(NOLOCK) ON sg.id_grpProd = gp.id_grpProd
        JOIN dbo.CatGrupo cg WITH(NOLOCK) ON gp.id_catGrupo = cg.id_catGrupo
        WHERE s.id_AlmoxEmpresa IN (10, 11) 
          AND s.QtdSaldoFisico > 0
          AND cg.cd_CatGrupo BETWEEN 40 AND 44
        GROUP BY s.id_Produto
    ";
    $estoqueAlmoxMap = [];
    try {
        $stmtAlmox = $pdo->query($sqlAlmox);
        foreach ($stmtAlmox->fetchAll() as $al) {
            $estoqueAlmoxMap[(int)$al['id_Produto']] = (float)$al['SaldoEstoque'];
        }
    } catch (Throwable $e) {}

    // 5. Agrupamento estruturado por Pedido
    $pedidos = [];
    $hoje = strtotime('today');

    foreach ($linhas as $r) {
        $cdPed = (int)$r['cdPedido'];

        if (!isset($pedidos[$cdPed])) {
            $dtPedTs = !empty($r['dt_Pedido']) ? strtotime($r['dt_Pedido']) : null;
            $dtLimTs = !empty($r['dt_LimiteEntrega']) ? strtotime($r['dt_LimiteEntrega']) : null;

            $diasParaEntrega = null;
            $statusPrazo = 'no_prazo';

            if ($dtLimTs) {
                $diasParaEntrega = (int) round(($dtLimTs - $hoje) / 86400);
                if ($diasParaEntrega < 0) {
                    $statusPrazo = 'atrasado';
                } elseif ($diasParaEntrega <= 7) {
                    $statusPrazo = 'alerta';
                } else {
                    $statusPrazo = 'no_prazo';
                }
            }

            $pedidos[$cdPed] = [
                'cdPedido'           => $cdPed,
                'pedido_cliente'     => trim((string)$r['PedidoCliente']),
                'dt_pedido'          => $r['dt_Pedido'] ? date('d/m/Y', $dtPedTs) : '—',
                'dt_pedido_raw'      => $r['dt_Pedido'],
                'dt_entrega'         => $r['dt_LimiteEntrega'] ? date('d/m/Y', $dtLimTs) : '—',
                'dt_entrega_raw'     => $r['dt_LimiteEntrega'],
                'dias_para_entrega'  => $diasParaEntrega,
                'status_prazo'       => $statusPrazo,
                'status_pedido_erp'  => trim((string)$r['StatusPedido']),
                'cliente'            => trim((string)$r['Cliente']),
                'cliente_apelido'    => trim((string)($r['ClienteApelido'] ?: $r['Cliente'])),
                'valor_total'        => (float)($r['Vlr_Liquido'] ?: $r['vlPedido']),
                'qtd_total_itens'    => 0.0,
                'qtd_saldo_itens'    => 0.0,
                'kva_total'          => 0.0,
                'produtos'           => [],
                'setor_atual'        => 'COMERCIAL',
                'setor_motivo'       => '',
                'ultimo_followup'    => null,
                'total_followups'    => 0,
                'programado_pcp_pct' => 0.0,
                'qtd_programada_pcp' => 0.0,
                'saldo_almox_total'  => 0.0,
            ];
        }

        $qtdItem = (float)$r['qtdItem'];
        $qtdSaldo = (float)$r['QtdSaldo'];
        $potKva = (float)($r['PotenciaKVA'] ?? 0);
        $idProd = (int)$r['id_Produto'];
        $saldoAlmox = $estoqueAlmoxMap[$idProd] ?? 0.0;

        $pedidos[$cdPed]['qtd_total_itens'] += $qtdItem;
        $pedidos[$cdPed]['qtd_saldo_itens'] += $qtdSaldo;
        $pedidos[$cdPed]['kva_total'] += ($potKva * $qtdSaldo);
        $pedidos[$cdPed]['saldo_almox_total'] += $saldoAlmox;

        $pedidos[$cdPed]['produtos'][] = [
            'id_produto'     => $idProd,
            'cd_referencia'  => trim((string)$r['cd_Referencia']),
            'ds_prod'        => trim((string)$r['ds_Prod']),
            'cat_grupo'      => (int)$r['cd_CatGrupo'],
            'qtd'            => $qtdItem,
            'saldo'          => $qtdSaldo,
            'potencia_kva'   => $potKva,
            'classe_tensao'  => trim((string)$r['ClasseTensao']),
            'tipo_nucleo'    => trim((string)$r['TipoNucleo']),
            'fases'          => trim((string)$r['Fases']),
            'norma'          => trim((string)($r['DescrNormaTrafo'] ?? '')),
            'preco_unitario' => (float)$r['PrecoUnitario'],
            'saldo_almox'    => $saldoAlmox,
        ];
    }

    // 6. Classificação Estrita dos 5 Setores
    $setoresContagem = [
        'COMERCIAL'  => ['pedidos' => 0, 'itens' => 0, 'kva' => 0.0, 'valor' => 0.0, 'atrasados' => 0, 'alertas' => 0],
        'ENGENHARIA' => ['pedidos' => 0, 'itens' => 0, 'kva' => 0.0, 'valor' => 0.0, 'atrasados' => 0, 'alertas' => 0],
        'PCP'        => ['pedidos' => 0, 'itens' => 0, 'kva' => 0.0, 'valor' => 0.0, 'atrasados' => 0, 'alertas' => 0],
        'PRODUCAO'   => ['pedidos' => 0, 'itens' => 0, 'kva' => 0.0, 'valor' => 0.0, 'atrasados' => 0, 'alertas' => 0],
        'LOGISTICA'  => ['pedidos' => 0, 'itens' => 0, 'kva' => 0.0, 'valor' => 0.0, 'atrasados' => 0, 'alertas' => 0],
    ];

    foreach ($pedidos as $cdPed => &$p) {
        $followups = obterFollowupsDoPedido($cdPed);
        $p['total_followups'] = count($followups);
        $ultimoFu = !empty($followups) ? end($followups) : null;
        $p['ultimo_followup'] = $ultimoFu;

        $qtdSaldoTotal = $p['qtd_saldo_itens'];
        $progInfo = $programadosMap[$cdPed] ?? ['qtd_programada' => 0.0, 'total_ns' => 0, 'total_ofs' => 0];
        $qtdProgramada = $progInfo['qtd_programada'];
        $p['qtd_programada_pcp'] = $qtdProgramada;

        $pctProg = ($qtdSaldoTotal > 0) ? min(100.0, round(($qtdProgramada / $qtdSaldoTotal) * 100, 1)) : 0.0;
        $p['programado_pcp_pct'] = $pctProg;

        $estaNoAlmoxPronto = ($p['saldo_almox_total'] >= $qtdSaldoTotal && $qtdSaldoTotal > 0);
        $tipoCod = $ultimoFu ? $ultimoFu['tipo_cod'] : null;

        // Regra 5: LOGÍSTICA (Peças nos almoxarifados 10 e 11)
        if ($estaNoAlmoxPronto || $p['status_pedido_erp'] === 'ENP') {
            $setor = 'LOGISTICA';
            $motivo = 'Transformadores nos Almoxarifados 10 e 11 (Prontos p/ Expedição)';
        }
        // Regra 4: PRODUÇÃO (100% Programado no PCP com OFs ativas)
        elseif ($pctProg >= 99.9 && $qtdSaldoTotal > 0) {
            $setor = 'PRODUCAO';
            $motivo = "100% Programado no PCP ($qtdProgramada/$qtdSaldoTotal transformadores com OFs)";
        }
        // Regra 3: PCP (Follow-Up Tipo 4 ou 5 OU Pré-Programação OU Programação Parcial)
        elseif ($tipoCod === 4 || $tipoCod === 5 || isset($preProgMap[$cdPed]) || $pctProg > 0) {
            $setor = 'PCP';
            if ($pctProg > 0) $motivo = "Programação Parcial no PCP ($pctProg% concluído)";
            elseif ($tipoCod === 4) $motivo = 'Tipo 4: Enviado para Programação';
            elseif ($tipoCod === 5) $motivo = 'Tipo 5: Pedido Recebido PCP';
            else $motivo = 'Em Pré-Programação do PCP';
        }
        // Regra 2: ENGENHARIA (Estritamente com Follow-Up Tipo 1, 3, 6 ou 7)
        elseif ($tipoCod === 1 || $tipoCod === 3 || $tipoCod === 6 || $tipoCod === 7) {
            $setor = 'ENGENHARIA';
            if ($tipoCod === 1) $motivo = 'Tipo 1: Validação de Projeto';
            elseif ($tipoCod === 3) $motivo = 'Tipo 3: Alteração de Projeto';
            elseif ($tipoCod === 6) $motivo = 'Tipo 6: Aprovação de Projeto';
            else $motivo = 'Tipo 7: Projeto Aprovado';
        }
        // Regra 1: COMERCIAL (Sem Follow-Up ou Tipo 2)
        else {
            $setor = 'COMERCIAL';
            $motivo = ($tipoCod === 2) ? 'Tipo 2: Projeto Validado (Retornou ao Comercial)' : 'Sem Follow-Up (Em tratativas comerciais)';
        }

        $p['setor_atual'] = $setor;
        $p['setor_motivo'] = $motivo;

        // Somatórios do Setor
        $setoresContagem[$setor]['pedidos']++;
        $setoresContagem[$setor]['itens']   += $p['qtd_saldo_itens'];
        $setoresContagem[$setor]['kva']     += $p['kva_total'];
        $setoresContagem[$setor]['valor']   += $p['valor_total'];

        if ($p['status_prazo'] === 'atrasado') {
            $setoresContagem[$setor]['atrasados']++;
        } elseif ($p['status_prazo'] === 'alerta') {
            $setoresContagem[$setor]['alertas']++;
        }
    }
    unset($p);

    $totaisMacro = [
        'total_pedidos'   => count($pedidos),
        'total_itens'     => array_sum(array_column($pedidos, 'qtd_saldo_itens')),
        'total_kva'       => array_sum(array_column($pedidos, 'kva_total')),
        'total_valor'     => array_sum(array_column($pedidos, 'valor_total')),
        'total_atrasados' => count(array_filter($pedidos, fn($x) => $x['status_prazo'] === 'atrasado')),
        'total_alertas'   => count(array_filter($pedidos, fn($x) => $x['status_prazo'] === 'alerta')),
    ];

    return $cache = [
        'sucesso'        => true,
        'totais_macro'   => $totaisMacro,
        'resumo_setores' => $setoresContagem,
        'pedidos'        => array_values($pedidos),
    ];
}

/**
 * Retorna a visão filtrada especificamente para o Setor solicitado.
 */
function obterDadosSetorEspecifico(string $setor): array
{
    $esteira = carregarEsteiraPedidos2026();
    if (!$esteira['sucesso']) {
        return $esteira;
    }

    $setorUpper = strtoupper(trim($setor));
    $todosPedidos = $esteira['pedidos'];

    $pedidosFiltrados = array_values(array_filter($todosPedidos, fn($p) => $p['setor_atual'] === $setorUpper));

    return [
        'sucesso' => true,
        'setor'   => $setorUpper,
        'resumo'  => $esteira['resumo_setores'][$setorUpper] ?? [],
        'pedidos' => $pedidosFiltrados,
    ];
}
