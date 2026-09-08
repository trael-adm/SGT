<?php
declare(strict_types=1);

/**
 * Módulo Fluxo de Pedidos — portado de SGT-dev/fluxo_pedidos (25/08/2026).
 * Mapa de 5 setores (Comercial→Engenharia→PCP→Produção→Logística) + planilha
 * de produção do chão de fábrica com 10 células (CH BT AT CNC SOL MN PIN ME MF LAB).
 *
 * Usa a conexão SQL Server já existente do PCP (getSqlServerDB() em
 * config/conexao.php) — não a conexão própria/com senha hardcoded que o
 * módulo original tinha em includes/conexao.php.
 *
 * Fonte de dados separada do Kardex (includes/boletim-planilha.php): consulta
 * pedidos/programação/almoxarifado direto nas tabelas ERP (dbo.Pedidos etc.),
 * não as views dw.vw_kardex_lotes/dw.vw_ficha_espc_trafo dos dashboards de
 * produção diária.
 */

// Ano-base da esteira: só pedidos a partir de 1º de janeiro deste ano entram
// nas consultas (regra herdada do módulo original, lá hardcoded '2026-01-01'
// em 3 queries — aqui numa constante só, pra não virar bomba-relógio quieta
// quando 2027 chegar).
const FLUXO_ANO_BASE = 2026;

// ─── Sanitização UTF-8 (não existia ainda no PCP) ───────────────────────────
function sanitizarUtf8Recursivo(mixed $dado): mixed
{
    if (is_string($dado)) {
        if (!mb_check_encoding($dado, 'UTF-8')) {
            $convertido = @mb_convert_encoding($dado, 'UTF-8', 'Windows-1252');
            return is_string($convertido) ? $convertido : @mb_convert_encoding($dado, 'UTF-8', 'ISO-8859-1');
        }
        return $dado;
    }
    if (is_array($dado)) {
        $saida = [];
        foreach ($dado as $k => $v) {
            $chaveSanitizada = is_string($k) ? sanitizarUtf8Recursivo($k) : $k;
            $saida[$chaveSanitizada] = sanitizarUtf8Recursivo($v);
        }
        return $saida;
    }
    return $dado;
}

// ─── Follow-ups / SAC (planilhas/Dados.csv) ─────────────────────────────────
// Feed manual exportado do VSAT — separado do Kardex, precisa continuar
// sendo atualizado manualmente (mesma rotina de antes, agora dentro do PCP).
function carregarFollowupsCSVFluxo(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $caminhos = [
        __DIR__ . '/../planilhas/Dados.csv',
        __DIR__ . '/../planilhas/dados.csv',
    ];

    $arquivo = null;
    foreach ($caminhos as $c) {
        if (file_exists($c)) {
            $arquivo = $c;
            break;
        }
    }

    if (!$arquivo) {
        return $cache = [];
    }

    $fh = fopen($arquivo, 'r');
    if (!$fh) {
        return $cache = [];
    }

    // Pular cabeçalho
    fgetcsv($fh, null, ';', '"', '\\');

    $pedidos = [];

    while (($row = fgetcsv($fh, null, ';', '"', '\\')) !== false) {
        if (count($row) < 10) continue;

        $tabela = trim((string) ($row[18] ?? ''));
        $codChave = trim((string) ($row[19] ?? ''));

        if ($tabela !== 'Pedidos' || !is_numeric($codChave)) {
            continue;
        }

        $pedido = (int) $codChave;
        $tipoCod = (int) ($row[4] ?? 0);
        $tipoDesc = trim((string) ($row[5] ?? ''));
        $situacao = trim((string) ($row[1] ?? ''));
        $dataStr = trim((string) ($row[3] ?? ''));
        $de = trim((string) ($row[7] ?? ''));
        $para = trim((string) ($row[16] ?? ''));
        $obs = trim((string) ($row[0] ?? ''));
        $solucao = trim((string) ($row[2] ?? ''));

        // Converter data pt-BR (DD/MM/YYYY HH:MM:SS) para timestamp
        $timestamp = 0;
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})\s+(\d{2}):(\d{2}):(\d{2})$/', $dataStr, $m)) {
            $timestamp = strtotime("{$m[3]}-{$m[2]}-{$m[1]} {$m[4]}:{$m[5]}:{$m[6]}");
        }

        $pedidos[$pedido][] = [
            'tipo_cod'    => $tipoCod,
            'tipo_desc'   => $tipoDesc,
            'situacao'    => $situacao,
            'data_str'    => $dataStr,
            'timestamp'   => $timestamp,
            'de'          => $de,
            'para'        => $para,
            'observacoes' => $obs,
            'solucao'     => $solucao,
        ];
    }
    fclose($fh);

    // Ordenar eventos de cada pedido cronologicamente
    foreach ($pedidos as $p => &$evts) {
        usort($evts, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
    }
    unset($evts);

    return $cache = $pedidos;
}

function obterFollowupsDoPedidoFluxo(int $cdPedido): array
{
    $todos = carregarFollowupsCSVFluxo();
    return $todos[$cdPedido] ?? [];
}

// ─── Motor 1: Esteira de Pedidos & Classificação de Setores ────────────────
// Filtro estrito: Empresa 1, registros ativos (PierSitReg='ATV'), categorias
// de grupo 40-44 (Transformadores Novos), exclui ENT/CAN.
function carregarEsteiraPedidos(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $cacheFile = __DIR__ . '/../storage/cache/fluxo_pedidos.json';

    $pdo = getSqlServerDB();
    if (!$pdo) {
        if (is_file($cacheFile)) {
            $raw = @file_get_contents($cacheFile);
            if ($raw !== false) {
                $dados = @json_decode($raw, true);
                if (is_array($dados) && isset($dados['sucesso']) && $dados['sucesso'] === true) {
                    return $cache = $dados;
                }
            }
        }
        return ['sucesso' => false, 'erro' => 'Não foi possível conectar ao SQL Server Trael.'];
    }

    $anoBase = FLUXO_ANO_BASE . '-01-01';

    // 1. Consulta base de Pedidos e Itens em aberto na Empresa 1
    $sql = "
        SELECT
            p.id_Ped, p.cdPedido, p.dt_Pedido, p.dt_LimiteEntrega, p.StatusPedido,
            p.PedidoCliente, p.vlPedido, p.Vlr_Liquido, p.id_Cliente,
            cli.Nome AS Cliente, cli.Apelido AS ClienteApelido,
            it.id_it_pedido, it.id_Produto, it.qtdItem, it.QtdSaldo, it.PrecoUnitario,
            m.cd_Referencia, m.ds_Prod, pot.PotenciaKVA,
            cl.ds_classeTensaoTrafo AS ClasseTensao, tp.ds_TpEnrolamentoNucleo AS TipoNucleo,
            esp.nrofasesTrafo AS Fases, norm.ds_normaTrafo AS DescrNormaTrafo,
            cg.cd_CatGrupo, cg.ds_CatGrupo
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
          AND p.dt_Pedido >= '$anoBase'
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

    // 2. Programação do PCP (quantidade programada com OFs ativas)
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
          AND p.dt_Pedido >= '$anoBase'
          AND p.StatusPedido NOT IN ('CAN', 'ENT')
          AND prog.PierSitReg = 'ATV'
          AND cg.cd_CatGrupo BETWEEN 40 AND 44
        GROUP BY p.cdPedido
    ";
    $programadosMap = [];
    try {
        $stmtProg = $pdo->query($sqlProg);
        foreach ($stmtProg->fetchAll() as $pr) {
            $programadosMap[(int) $pr['cdPedido']] = [
                'qtd_programada' => (float) $pr['QtdProgramada'],
                'total_ns'       => (int) $pr['TotalNS_Gerados'],
                'total_ofs'      => (int) $pr['TotalOFs'],
            ];
        }
    } catch (Throwable $e) {
        // silencioso — mesmo comportamento do módulo original: sem programação
        // ainda não é erro fatal, o pedido só fica com pctProg=0.
    }

    // 3. Pré-programação do PCP
    $sqlPre = "SELECT DISTINCT cdPedido FROM dw.vw_pre_programacao_novo WITH(NOLOCK) WHERE ano >= " . FLUXO_ANO_BASE;
    $preProgMap = [];
    try {
        $stmtPre = $pdo->query($sqlPre);
        foreach ($stmtPre->fetchAll() as $pp) {
            $preProgMap[(int) $pp['cdPedido']] = true;
        }
    } catch (Throwable $e) {
    }

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
            $estoqueAlmoxMap[(int) $al['id_Produto']] = (float) $al['SaldoEstoque'];
        }
    } catch (Throwable $e) {
    }

    // 5. Agrupamento estruturado por Pedido
    $pedidos = [];
    $hoje = strtotime('today');

    foreach ($linhas as $r) {
        $cdPed = (int) $r['cdPedido'];

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
                'pedido_cliente'     => trim((string) $r['PedidoCliente']),
                'dt_pedido'          => $r['dt_Pedido'] ? date('d/m/Y', $dtPedTs) : '—',
                'dt_pedido_raw'      => $r['dt_Pedido'],
                'dt_entrega'         => $r['dt_LimiteEntrega'] ? date('d/m/Y', $dtLimTs) : '—',
                'dt_entrega_raw'     => $r['dt_LimiteEntrega'],
                'dias_para_entrega'  => $diasParaEntrega,
                'status_prazo'       => $statusPrazo,
                'status_pedido_erp'  => trim((string) $r['StatusPedido']),
                'cliente'            => trim((string) $r['Cliente']),
                'cliente_apelido'    => trim((string) ($r['ClienteApelido'] ?: $r['Cliente'])),
                'valor_total'        => (float) ($r['Vlr_Liquido'] ?: $r['vlPedido']),
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

        $qtdItem = (float) $r['qtdItem'];
        $qtdSaldo = (float) $r['QtdSaldo'];
        $potKva = (float) ($r['PotenciaKVA'] ?? 0);
        $idProd = (int) $r['id_Produto'];
        $saldoAlmox = $estoqueAlmoxMap[$idProd] ?? 0.0;

        $pedidos[$cdPed]['qtd_total_itens'] += $qtdItem;
        $pedidos[$cdPed]['qtd_saldo_itens'] += $qtdSaldo;
        $pedidos[$cdPed]['kva_total'] += ($potKva * $qtdSaldo);
        $pedidos[$cdPed]['saldo_almox_total'] += $saldoAlmox;

        $pedidos[$cdPed]['produtos'][] = [
            'id_produto'     => $idProd,
            'cd_referencia'  => trim((string) $r['cd_Referencia']),
            'ds_prod'        => trim((string) $r['ds_Prod']),
            'cat_grupo'      => (int) $r['cd_CatGrupo'],
            'qtd'            => $qtdItem,
            'saldo'          => $qtdSaldo,
            'potencia_kva'   => $potKva,
            'classe_tensao'  => trim((string) $r['ClasseTensao']),
            'tipo_nucleo'    => trim((string) $r['TipoNucleo']),
            'fases'          => trim((string) $r['Fases']),
            'norma'          => trim((string) ($r['DescrNormaTrafo'] ?? '')),
            'preco_unitario' => (float) $r['PrecoUnitario'],
            'saldo_almox'    => $saldoAlmox,
        ];
    }

    // 6. Classificação estrita dos 5 setores (cascata — primeira regra que
    // casar vence, ordem importa).
    $setoresContagem = [
        'COMERCIAL'  => ['pedidos' => 0, 'itens' => 0, 'kva' => 0.0, 'valor' => 0.0, 'atrasados' => 0, 'alertas' => 0],
        'ENGENHARIA' => ['pedidos' => 0, 'itens' => 0, 'kva' => 0.0, 'valor' => 0.0, 'atrasados' => 0, 'alertas' => 0],
        'PCP'        => ['pedidos' => 0, 'itens' => 0, 'kva' => 0.0, 'valor' => 0.0, 'atrasados' => 0, 'alertas' => 0],
        'PRODUCAO'   => ['pedidos' => 0, 'itens' => 0, 'kva' => 0.0, 'valor' => 0.0, 'atrasados' => 0, 'alertas' => 0],
        'LOGISTICA'  => ['pedidos' => 0, 'itens' => 0, 'kva' => 0.0, 'valor' => 0.0, 'atrasados' => 0, 'alertas' => 0],
    ];

    foreach ($pedidos as $cdPed => &$p) {
        $followups = obterFollowupsDoPedidoFluxo($cdPed);
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

        // Regra 5: LOGÍSTICA (peças nos almoxarifados 10 e 11)
        if ($estaNoAlmoxPronto || $p['status_pedido_erp'] === 'ENP') {
            $setor = 'LOGISTICA';
            $motivo = 'Transformadores nos Almoxarifados 10 e 11 (Prontos p/ Expedição)';
        }
        // Regra 4: PRODUÇÃO (100% programado no PCP com OFs ativas)
        elseif ($pctProg >= 99.9 && $qtdSaldoTotal > 0) {
            $setor = 'PRODUCAO';
            $motivo = "100% Programado no PCP ($qtdProgramada/$qtdSaldoTotal transformadores com OFs)";
        }
        // Regra 3: PCP (Follow-Up tipo 4 ou 5, ou pré-programação, ou parcial)
        elseif ($tipoCod === 4 || $tipoCod === 5 || isset($preProgMap[$cdPed]) || $pctProg > 0) {
            $setor = 'PCP';
            if ($pctProg > 0) $motivo = "Programação Parcial no PCP ($pctProg% concluído)";
            elseif ($tipoCod === 4) $motivo = 'Tipo 4: Enviado para Programação';
            elseif ($tipoCod === 5) $motivo = 'Tipo 5: Pedido Recebido PCP';
            else $motivo = 'Em Pré-Programação do PCP';
        }
        // Regra 2: ENGENHARIA (estritamente Follow-Up tipo 1, 3, 6 ou 7)
        elseif ($tipoCod === 1 || $tipoCod === 3 || $tipoCod === 6 || $tipoCod === 7) {
            $setor = 'ENGENHARIA';
            if ($tipoCod === 1) $motivo = 'Tipo 1: Validação de Projeto';
            elseif ($tipoCod === 3) $motivo = 'Tipo 3: Alteração de Projeto';
            elseif ($tipoCod === 6) $motivo = 'Tipo 6: Aprovação de Projeto';
            else $motivo = 'Tipo 7: Projeto Aprovado';
        }
        // Regra 1: COMERCIAL (sem follow-up ou tipo 2)
        else {
            $setor = 'COMERCIAL';
            $motivo = ($tipoCod === 2) ? 'Tipo 2: Projeto Validado (Retornou ao Comercial)' : 'Sem Follow-Up (Em tratativas comerciais)';
        }

        $p['setor_atual'] = $setor;
        $p['setor_motivo'] = $motivo;

        $setoresContagem[$setor]['pedidos']++;
        $setoresContagem[$setor]['itens'] += $p['qtd_saldo_itens'];
        $setoresContagem[$setor]['kva'] += $p['kva_total'];
        $setoresContagem[$setor]['valor'] += $p['valor_total'];

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

function obterDadosSetorEspecifico(string $setor): array
{
    $esteira = carregarEsteiraPedidos();
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

// ─── Motor 2: Planilha de Produção (10 células do chão de fábrica) ─────────
function avaliarStatusCelulaFluxo(array $subNos, string $tipo, bool $isMaeEnc): string
{
    if ($isMaeEnc) return 'OK';
    // Nenhum sub-componente encontrado no join de 5 níveis: célula desconhecida,
    // não "pendente" (evita que o item caia sempre na primeira célula checada, ex. BT).
    if (empty($subNos)) return 'DESCONHECIDO';

    foreach ($subNos as $n) {
        $ref = strtoupper(trim((string) ($n['cd_Referencia'] ?? '')));
        $st = strtoupper(trim((string) ($n['StatusOF'] ?? '')));
        $qtdProd = (float) ($n['QtdProduzida'] ?? 0);
        $qtdTot = (float) ($n['Quantidade'] ?? 0);

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
                if (str_starts_with($ref, 'ME-') || str_starts_with($ref, 'ME_') || $ref === 'ME'
                    || str_starts_with($ref, 'PA-') || str_starts_with($ref, 'PA_') || $ref === 'PA') $match = true;
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

function carregarPlanilhaProducaoFluxo(
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
    $cacheFile = __DIR__ . '/../storage/cache/fluxo_planilha.json';
    $pdo = getSqlServerDB();
    if (!$pdo) {
        if (is_file($cacheFile)) {
            $raw = @file_get_contents($cacheFile);
            if ($raw !== false) {
                $dados = @json_decode($raw, true);
                if (is_array($dados) && isset($dados['sucesso']) && $dados['sucesso'] === true) {
                    $itens = $dados['itens'] ?? [];

                    // Filtrar em memória se estiver servindo do cache
                    if ($filtroEmpresa !== null && $filtroEmpresa !== '') {
                        $itens = array_values(array_filter($itens, function ($it) use ($filtroEmpresa) {
                            return (string) ($it['empresa'] ?? '1') === (string) $filtroEmpresa;
                        }));
                    }
                    if ($statusFila === 'em_aberto') {
                        $itens = array_values(array_filter($itens, function ($it) {
                            return empty($it['is_concluido']);
                        }));
                    } elseif ($statusFila === 'concluidos') {
                        $itens = array_values(array_filter($itens, function ($it) {
                            return !empty($it['is_concluido']);
                        }));
                    }
                    if (!empty($dtInicio)) {
                        $itens = array_values(array_filter($itens, function ($it) use ($dtInicio) {
                            return !empty($it['data_raw']) && substr($it['data_raw'], 0, 10) >= $dtInicio;
                        }));
                    }
                    if (!empty($dtFim)) {
                        $itens = array_values(array_filter($itens, function ($it) use ($dtFim) {
                            return !empty($it['data_raw']) && substr($it['data_raw'], 0, 10) <= $dtFim;
                        }));
                    }
                    if (!empty($mes) && $mes > 0) {
                        $itens = array_values(array_filter($itens, function ($it) use ($mes) {
                            if (empty($it['data_raw'])) return false;
                            return (int) date('m', strtotime($it['data_raw'])) === $mes;
                        }));
                    }
                    if (!empty($ano) && $ano > 0) {
                        $itens = array_values(array_filter($itens, function ($it) use ($ano) {
                            if (empty($it['data_raw'])) return false;
                            return (int) date('Y', strtotime($it['data_raw'])) === $ano;
                        }));
                    }
                    if (!empty($semana) && $semana > 0) {
                        $itens = array_values(array_filter($itens, function ($it) use ($semana) {
                            return (int) ($it['sem'] ?? 0) === $semana;
                        }));
                    }
                    if (!empty($filtroPedido)) {
                        $itens = array_values(array_filter($itens, function ($it) use ($filtroPedido) {
                            return stripos((string) ($it['pedido'] ?? ''), $filtroPedido) !== false;
                        }));
                    }
                    if (!empty($filtroProjeto)) {
                        $itens = array_values(array_filter($itens, function ($it) use ($filtroProjeto) {
                            return stripos((string) ($it['projeto'] ?? ''), $filtroProjeto) !== false || stripos((string) ($it['desc_projeto'] ?? ''), $filtroProjeto) !== false;
                        }));
                    }
                    if (!empty($filtroNS)) {
                        $itens = array_values(array_filter($itens, function ($it) use ($filtroNS) {
                            return stripos((string) ($it['nr_serie'] ?? ''), $filtroNS) !== false;
                        }));
                    }
                    if ($limite !== null && $limite > 0) {
                        $itens = array_slice($itens, 0, $limite);
                    }

                    $totalConcluidos = count(array_filter($itens, fn($x) => !empty($x['is_concluido'])));
                    $totalPendentes = count($itens) - $totalConcluidos;

                    return [
                        'sucesso'          => true,
                        'total'            => count($itens),
                        'total_concluidos' => $totalConcluidos,
                        'total_pendentes'  => $totalPendentes,
                        'itens'            => $itens,
                    ];
                }
            }
        }
        return ['sucesso' => false, 'erro' => 'Não foi possível conectar ao SQL Server Trael.'];
    }

    $anoBase = FLUXO_ANO_BASE . '-01-01';

    $where = [
        "cns.NumSerie > 0",
        "p.PierSitReg = 'ATV'",
        "it.PierSitReg = 'ATV'",
        "p.dt_Pedido >= '$anoBase'",
        "p.StatusPedido NOT IN ('CAN', 'ENT')",
        "it.QtdSaldo > 0",
        "cg.cd_CatGrupo BETWEEN 40 AND 44",
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
            ISNULL(seq_info.SeqPlano, 0) AS SeqPlano,
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
        $stmt->execute($params);
        $registros = $stmt->fetchAll();
    } catch (Throwable $e) {
        return ['sucesso' => false, 'erro' => 'Erro ao consultar planilha: ' . $e->getMessage()];
    }

    if (empty($registros)) {
        return ['sucesso' => true, 'total' => 0, 'itens' => []];
    }

    // Árvore de sub-OFs (componentes) via cadeia de 5 níveis de RlcProgramacao —
    // não é uma CTE recursiva de verdade, é um limite fixo de 5 saltos. Bug
    // histórico (pedido 69549 / NS 850297-850300, ver PROJETO-PCP.md) já foi
    // corrigido alargando de "só OF-mãe" pra essa cadeia — não simplificar de
    // volta pra um único join.
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
    } catch (Throwable $e) {
    }

    $trafosFinais = [];
    $totalConcluidos = 0;
    $totalPendentes = 0;

    foreach ($registros as $r) {
        $ns = (int) $r['NumSerie'];
        $statusMae = strtoupper(trim((string) ($r['StatusOF'] ?? '')));
        $isMaeEnc = ($statusMae === 'ENC');
        $subNos = $componentesPorProgId[$r['id_ProgProdPCP']] ?? [];

        $dtProg = !empty($r['DataHoraProducaoAux']) ? date('d/m/Y', strtotime($r['DataHoraProducaoAux'])) : '—';
        $classe = '15';
        if (preg_match('/(\d+[\.,]?\d*)/', (string) $r['ClasseTensao'], $mCl)) {
            $classe = (string) round((float) str_replace(',', '.', $mCl[1]));
        }

        $tapsRaw = strtoupper(trim((string) ($r['TipoNucleo'] ?? '')));
        $fasesRaw = strtoupper(trim((string) ($r['Fases'] ?? '')));
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
            'CH'  => avaliarStatusCelulaFluxo($subNos, 'CH', $isMaeEnc),
            'BT'  => avaliarStatusCelulaFluxo($subNos, 'BT', $isMaeEnc),
            'AT'  => avaliarStatusCelulaFluxo($subNos, 'AT', $isMaeEnc),
            'CNC' => avaliarStatusCelulaFluxo($subNos, 'CNC', $isMaeEnc),
            'SOL' => avaliarStatusCelulaFluxo($subNos, 'SOL', $isMaeEnc),
            'MN'  => avaliarStatusCelulaFluxo($subNos, 'MN', $isMaeEnc),
            'PIN' => avaliarStatusCelulaFluxo($subNos, 'PIN', $isMaeEnc),
            'ME'  => $isMaeEnc ? 'OK' : avaliarStatusCelulaFluxo($subNos, 'ME', $isMaeEnc),
            'MF'  => avaliarStatusCelulaFluxo($subNos, 'MF', $isMaeEnc),
            'LAB' => $isMaeEnc ? 'OK' : avaliarStatusCelulaFluxo($subNos, 'LAB', $isMaeEnc),
        ];

        $pendentesCount = 0;
        foreach ($setores as $st) {
            if ($st !== 'OK') $pendentesCount++;
        }

        $isConcluido = ($pendentesCount === 0);
        if ($isConcluido) $totalConcluidos++;
        else $totalPendentes++;

        $seq = (int) ($r['SeqPlano'] ?? 0);

        $trafosFinais[] = [
            'pedido'       => trim((string) $r['Pedido']),
            'empresa'      => trim((string) ($r['EmpDestino'] ?? '1')) ?: '1',
            'data'         => $dtProg,
            'data_raw'     => $r['DataHoraProducaoAux'],
            'seq'          => $seq,
            'seq_plano'    => $seq,
            'projeto'      => trim((string) $r['Projeto']),
            'desc_projeto' => trim((string) $r['DescricaoProjeto']),
            'cliente'      => trim((string) ($r['ClienteApelido'] ?: $r['Cliente'])),
            'qtde'         => 1,
            'pot'          => (float) ($r['PotenciaKVA'] ?? 0),
            'class'        => $classe,
            'taps'         => $taps,
            'tipo_constr'  => trim((string) ($r['DsTipoConstrTrafo'] ?? '')) ?: '—',
            'sem'          => (int) ($r['SEM'] ?? 0),
            'nr_serie'     => $ns,
            'of_mae'       => (int) ($r['OF_Mae'] ?? 0),
            'mult_bobinas' => $multBobinas,
            'cat_grupo'    => (int) ($r['cd_CatGrupo'] ?? 0),
            'setores'      => $setores,
            'is_concluido' => $isConcluido,
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
