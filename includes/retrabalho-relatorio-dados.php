<?php
declare(strict_types=1);

/**
 * Calcula todos os dados (KPIs, análises e séries de gráficos) do Relatório
 * Operacional & Financeiro de Retrabalho a partir dos filtros informados.
 *
 * Extraído de pages/retrabalho/relatorio.php para ser reutilizado tanto pelo
 * carregamento inicial da página (render server-side) quanto pelo endpoint
 * de atualização via AJAX (api/retrabalho-relatorio-atualizar.php), garantindo
 * que os dois caminhos calculem exatamente os mesmos valores.
 *
 * @param PDO   $pdo            Conexão MySQL (getDB()).
 * @param array $get            Array de filtros no mesmo formato de $_GET.
 * @param bool  $podeVerValores Se o usuário pode ver valores financeiros (R$).
 * @return array Todas as variáveis calculadas, prontas para extract().
 */
function calcularRelatorioRetrabalho(PDO $pdo, array $get, bool $podeVerValores): array
{
    // ─── Parâmetros Globais de Custos ────────────────────────────────────────────
    $configRows = [];
    try {
        $configRows = $pdo->query("SELECT chave, valor FROM retrabalho_configuracoes")->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (\Throwable $e) {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS retrabalho_configuracoes (
                    chave VARCHAR(50) NOT NULL PRIMARY KEY,
                    valor VARCHAR(255) NOT NULL,
                    descricao VARCHAR(255) NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $configRows = $pdo->query("SELECT chave, valor FROM retrabalho_configuracoes")->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (\Throwable $e2) {}
    }
    $custoHoraHomem   = (float) ($configRows['custo_hora_homem'] ?? 45.00);
    $horasTrabalhoDia = (float) ($configRows['horas_trabalho_dia'] ?? 8.80);
    if ($horasTrabalhoDia <= 0) $horasTrabalhoDia = 8.80;

    // Catálogo de Materiais com Custos (para valorar peças usadas na triagem)
    $materiaisCatalogo = [];
    $catalogoLookup = [];
    $catalogoDescLookup = [];

    try {
        $materiaisCatalogo = $pdo->query("
            SELECT id, descricao, unidade, custo_unitario
            FROM retrabalho_materiais_catalogo
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        try {
            $pdo->exec("ALTER TABLE retrabalho_materiais_catalogo ADD COLUMN custo_unitario DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER unidade");
            $materiaisCatalogo = $pdo->query("
                SELECT id, descricao, unidade, custo_unitario
                FROM retrabalho_materiais_catalogo
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e2) {
            try {
                $rowsSemCusto = $pdo->query("SELECT id, descricao, unidade FROM retrabalho_materiais_catalogo")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rowsSemCusto as $rsc) {
                    $rsc['custo_unitario'] = 0.00;
                    $materiaisCatalogo[] = $rsc;
                }
            } catch (\Throwable $e3) {
                $materiaisCatalogo = [];
            }
        }
    }
    foreach ($materiaisCatalogo as $mc) {
        $id = (int) $mc['id'];
        $descNorm = mb_strtoupper(trim((string) $mc['descricao']));
        $itemInfo = [
            'id'             => $id,
            'descricao'      => (string) $mc['descricao'],
            'unidade'        => (string) $mc['unidade'],
            'custo_unitario' => (float) $mc['custo_unitario']
        ];
        $catalogoLookup[$id] = $itemInfo;
        $catalogoDescLookup[$descNorm] = $itemInfo;
    }

    // ─── Tratamento de Datas e Filtros ────────────────────────────────────────────
    $tz = new DateTimeZone('America/Cuiaba');
    $agora = new DateTime('now', $tz);

    $dataHojeIso = $agora->format('Y-m-d');
    $primeiroDiaMes = $agora->format('Y-m-01');

    $fDataDe   = trim((string) ($get['data_de'] ?? $primeiroDiaMes));
    $fDataAte  = trim((string) ($get['data_ate'] ?? $dataHojeIso));
    $fBusca    = trim((string) ($get['busca'] ?? ''));
    $fEstacao  = trim((string) ($get['estacao'] ?? ''));
    $fStatus   = trim((string) ($get['status'] ?? 'todos')); // todos | finalizado | em_andamento
    $fSetor    = trim((string) ($get['setor'] ?? ''));
    $fReprova  = (int) ($get['id_reprova'] ?? 0);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDataDe))  $fDataDe = $primeiroDiaMes;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDataAte)) $fDataAte = $dataHojeIso;

    // ─── Setores Disponíveis (para a coluna "setores_destino" livre-texto das telas de execução) ──
    $setoresDisponiveis = [
        'bobinagem_at'    => 'Bobinagem AT',
        'bobinagem_bt'    => 'Bobinagem BT',
        'montagem_nucleo' => 'Montagem de Núcleo',
        'solda'           => 'Solda / Caldeiraria',
        'radiador'        => 'Radiadores',
        'pintura'         => 'Pintura',
        'montagem_final'  => 'Montagem Final',
        'laboratorio'     => 'Laboratório'
    ];

    // ─── Setores Causadores (catálogo de reprovas — sempre preenchido, usado no filtro e no gráfico) ──
    $setoresCausadoresDisponiveis = [
        'CALDEIRARIA'    => 'Caldeiraria',
        'ELÉTRICO'       => 'Elétrico',
        'LINHA'          => 'Linha',
        'PINTURA'        => 'Pintura',
        'ENGENHARIA'     => 'Engenharia',
        'REVITALIZAÇÃO'  => 'Revitalização',
        'S/ Setor Causador' => 'Sem Setor Causador'
    ];

    // ─── Query Base de Retrabalhos ───────────────────────────────────────────────
    $where = [
        'r.deleted_at IS NULL',
        'r.id_reprova IS NOT NULL'
    ];
    $params = [];

    // Filtro por Data de Referência (data_reprova -> data_inicio -> created_at)
    $where[] = 'DATE(COALESCE(r.data_reprova, r.data_inicio, r.created_at)) >= :data_de';
    $params['data_de'] = $fDataDe;

    $where[] = 'DATE(COALESCE(r.data_reprova, r.data_inicio, r.created_at)) <= :data_ate';
    $params['data_ate'] = $fDataAte;

    if ($fEstacao !== '' && in_array($fEstacao, ['LAB', 'IQF', 'GER'], true)) {
        $where[] = 'r.estacao = :estacao';
        $params['estacao'] = $fEstacao;
    }

    if ($fReprova > 0) {
        $where[] = 'r.id_reprova = :id_reprova';
        $params['id_reprova'] = $fReprova;
    }

    if ($fStatus === 'finalizado') {
        $where[] = "r.status IN ('finalizado', 'aprovado')";
    } elseif ($fStatus === 'em_andamento') {
        $where[] = "r.status NOT IN ('finalizado', 'aprovado')";
    }

    if ($fSetor !== '' && isset($setoresCausadoresDisponiveis[$fSetor])) {
        $where[] = 'rep.setor_causador = :setor_causador';
        $params['setor_causador'] = $fSetor;
    }

    if ($fBusca !== '') {
        $where[] = '(r.ns_transformador LIKE :busca1 OR p.codigo LIKE :busca2 OR ped.numero LIKE :busca3 OR rep.descricao LIKE :busca4)';
        $buscaLike = '%' . $fBusca . '%';
        $params['busca1'] = $buscaLike;
        $params['busca2'] = $buscaLike;
        $params['busca3'] = $buscaLike;
        $params['busca4'] = $buscaLike;
    }

    $whereSql = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT
            r.id,
            r.id_lote,
            r.ns_transformador,
            r.id_projeto,
            r.id_reprova,
            r.estacao,
            r.prioridade,
            r.status,
            r.data_reprova,
            r.data_chegada,
            r.data_inicio,
            r.data_finalizacao,
            r.concluido_em,
            r.created_at,
            r.updated_at,
            r.setores_destino,
            r.causa_reprova,
            r.causa_raiz,
            r.correcao,
            p.codigo AS projeto_codigo,
            p.descricao AS projeto_descricao,
            ped.numero AS pedido_numero,
            rep.codigo AS reprova_codigo,
            rep.familia AS reprova_familia,
            rep.descricao AS reprova_descricao,
            rep.local AS reprova_local,
            rep.setor_causador AS reprova_setor_causador,
            rep.tempo_padrao_minutos AS reprova_tempo_minutos,
            u.nome AS responsavel_nome
        FROM retrabalhos r
        LEFT JOIN reprovas rep ON rep.id = r.id_reprova
        LEFT JOIN projetos p ON p.id = r.id_projeto
        LEFT JOIN pedidos ped ON ped.id = p.id_pedido
        LEFT JOIN usuarios u ON u.id = r.id_responsavel
        WHERE {$whereSql}
        ORDER BY r.id DESC
    ");
    $stmt->execute($params);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ─── Coletar Materiais Utilizados nos Lotes / Retrabalhos ───────────────────
    $lotesIds = [];
    $retrabalhoIds = [];
    foreach ($registros as $reg) {
        if (!empty($reg['id_lote'])) {
            $lotesIds[(int) $reg['id_lote']] = true;
        }
        $retrabalhoIds[(int) $reg['id']] = true;
    }

    $materiaisUsadosPorLote = [];
    $todosMateriaisConsumidos = [];

    if (!empty($lotesIds) || !empty($retrabalhoIds)) {
        $idsParaBusca = !empty($lotesIds) ? array_keys($lotesIds) : array_keys($retrabalhoIds);
        $inPlaceholders = implode(',', array_fill(0, count($idsParaBusca), '?'));

        $stmtMat = $pdo->prepare("
            SELECT id, id_lote, codigo, descricao, quantidade, preco_medio, unidade, created_at
            FROM retrabalho_material_uso
            WHERE id_lote IN ({$inPlaceholders})
        ");
        $stmtMat->execute($idsParaBusca);
        $materiaisRows = $stmtMat->fetchAll(PDO::FETCH_ASSOC);

        foreach ($materiaisRows as $mr) {
            $loteId = (int) $mr['id_lote'];
            $qtd    = (float) $mr['quantidade'];
            if ($qtd <= 0) continue;

            $desc   = trim((string) $mr['descricao']);
            $un     = !empty($mr['unidade']) ? trim((string) $mr['unidade']) : 'UND';
            $descNorm = mb_strtoupper($desc);

            $custoUnit = !empty($mr['preco_medio']) ? (float) $mr['preco_medio'] : 0.0;
            $idMat = null;

            if (isset($catalogoDescLookup[$descNorm])) {
                $idMat = $catalogoDescLookup[$descNorm]['id'];
                if ($custoUnit <= 0) {
                    $custoUnit = $catalogoDescLookup[$descNorm]['custo_unitario'];
                }
                if (empty($mr['unidade'])) {
                    $un = $catalogoDescLookup[$descNorm]['unidade'];
                }
            }

            $custoTotalMat = $qtd * $custoUnit;

            $itemMat = [
                'id_material'    => $idMat,
                'descricao'      => $desc,
                'unidade'        => $un,
                'quantidade'     => $qtd,
                'custo_unitario' => $custoUnit,
                'custo_total'    => $custoTotalMat
            ];

            $materiaisUsadosPorLote[$loteId][] = $itemMat;

            $chaveConsol = ($idMat ? 'CAT_' . $idMat : 'OUT_' . $descNorm) . '_' . $un;
            if (!isset($todosMateriaisConsumidos[$chaveConsol])) {
                $todosMateriaisConsumidos[$chaveConsol] = [
                    'descricao'      => $desc,
                    'unidade'        => $un,
                    'quantidade'     => 0.0,
                    'custo_unitario' => $custoUnit,
                    'custo_total'    => $custoTotalMat,
                    'ocorrencias'    => 0
                ];
            }
            $todosMateriaisConsumidos[$chaveConsol]['quantidade']  += $qtd;
            $todosMateriaisConsumidos[$chaveConsol]['custo_total'] += $custoTotalMat;
            $todosMateriaisConsumidos[$chaveConsol]['ocorrencias']++;
        }
    }

    // ─── Processamento Analítico com Horas pelo Tipo de Reprova ─────────────────
    $totalCasosRetrabalho = count($registros);
    $totalHorasRetrabalho = 0.0;
    $totalCustoMaoObra    = 0.0;
    $totalCustoPecas      = 0.0;
    $totalCustoGeral      = 0.0;
    $casosFinalizadosCont = 0;

    $analiseReprovas = [];
    $analiseSetores  = [];
    $registrosProcessados = [];

    foreach ($registros as $r) {
        // 1. Contabilização de Horas com base no Tempo Padrão da Reprova (Minutos)
        $minutosPadrao = !empty($r['reprova_tempo_minutos']) ? (int) $r['reprova_tempo_minutos'] : 60;
        if ($minutosPadrao <= 0) $minutosPadrao = 60;

        $horasTrabalhadas = round($minutosPadrao / 60, 2);

        $isFinalizado = in_array($r['status'], ['finalizado', 'aprovado'], true);
        if ($isFinalizado) {
            $casosFinalizadosCont++;
        }

        // 2. Custo de Mão de Obra
        $custoMO = round($horasTrabalhadas * $custoHoraHomem, 2);

        // 3. Custo de Peças
        $loteId = !empty($r['id_lote']) ? (int) $r['id_lote'] : (int) $r['id'];
        $pecasDoLote = $materiaisUsadosPorLote[$loteId] ?? [];
        $custoPecasItem = 0.0;
        $qtdPecasItem = 0.0;

        foreach ($pecasDoLote as $pItem) {
            $custoPecasItem += (float) $pItem['custo_total'];
            $qtdPecasItem   += (float) $pItem['quantidade'];
        }

        $custoTotalItem = round($custoMO + $custoPecasItem, 2);

        $totalHorasRetrabalho += $horasTrabalhadas;
        $totalCustoMaoObra    += $custoMO;
        $totalCustoPecas      += $custoPecasItem;
        $totalCustoGeral      += $custoTotalItem;

        // 4. Agrupamento por Tipo de Reprova
        $codReprova  = $r['reprova_codigo'] ?: 'N/D';
        $descReprova = $r['reprova_descricao'] ?: ($r['causa_reprova'] ?: 'Não Especificada');
        $famReprova  = $r['reprova_familia'] ?: 'GERAL';

        if (!isset($analiseReprovas[$codReprova])) {
            $analiseReprovas[$codReprova] = [
                'codigo'        => $codReprova,
                'familia'       => $famReprova,
                'descricao'     => $descReprova,
                'tempo_minutos' => $minutosPadrao,
                'ocorrencias'   => 0,
                'horas'         => 0.0,
                'custo_mo'      => 0.0,
                'custo_pecas'   => 0.0,
                'custo_total'   => 0.0
            ];
        }
        $analiseReprovas[$codReprova]['ocorrencias']++;
        $analiseReprovas[$codReprova]['horas']       += $horasTrabalhadas;
        $analiseReprovas[$codReprova]['custo_mo']    += $custoMO;
        $analiseReprovas[$codReprova]['custo_pecas'] += $custoPecasItem;
        $analiseReprovas[$codReprova]['custo_total'] += $custoTotalItem;

        // 5. Agrupamento por Setor Causador (catálogo de reprovas — sempre preenchido)
        $setorCausador = trim((string) ($r['reprova_setor_causador'] ?? '')) ?: 'S/ Setor Causador';
        $nomeSetorCausador = $setoresCausadoresDisponiveis[$setorCausador] ?? $setorCausador;

        if (!isset($analiseSetores[$setorCausador])) {
            $analiseSetores[$setorCausador] = [
                'slug'        => $setorCausador,
                'nome'        => $nomeSetorCausador,
                'ocorrencias' => 0,
                'horas'       => 0.0,
                'custo_total' => 0.0
            ];
        }
        $analiseSetores[$setorCausador]['ocorrencias']++;
        $analiseSetores[$setorCausador]['horas']       += $horasTrabalhadas;
        $analiseSetores[$setorCausador]['custo_total'] += $custoTotalItem;

        $r['minutos_padrao']    = $minutosPadrao;
        $r['horas_trabalhadas'] = $horasTrabalhadas;
        $r['custo_mo']          = $custoMO;
        $r['custo_pecas']       = $custoPecasItem;
        $r['custo_total']       = $custoTotalItem;
        $r['qtd_pecas']         = $qtdPecasItem;
        $r['pecas_detalhes']    = $pecasDoLote;

        $registrosProcessados[] = $r;
    }

    // Médias Gerais
    $mediaHorasPorCaso = $totalCasosRetrabalho > 0 ? round($totalHorasRetrabalho / $totalCasosRetrabalho, 2) : 0.0;
    $custoMedioPorCaso = $totalCasosRetrabalho > 0 ? round($totalCustoGeral / $totalCasosRetrabalho, 2) : 0.0;

    // Ordenar Reprovas pelo Maior Custo Total
    uasort($analiseReprovas, fn($a, $b) => $b['custo_total'] <=> $a['custo_total']);

    // Ordenar Setores pelo Maior Custo
    uasort($analiseSetores, fn($a, $b) => $b['custo_total'] <=> $a['custo_total']);

    // Ordenar Materiais pelo Maior Consumo/Custo
    uasort($todosMateriaisConsumidos, fn($a, $b) => $b['custo_total'] <=> $a['custo_total']);

    // ─── Dados para Gráficos Chart.js ────────────────────────────────────────────
    // Gráfico 1: Por Tipo de Reprova (Top 8)
    $chartReprovaLabels = [];
    $chartReprovaCustos = [];
    $chartReprovaHoras  = [];
    $chartReprovaQtd    = [];

    $limiteReprovas = 8;
    $contR = 0;
    $outrosCusto = 0.0;
    $outrosHoras = 0.0;
    $outrosQtd   = 0;

    foreach ($analiseReprovas as $ar) {
        if ($contR < $limiteReprovas) {
            $chartReprovaLabels[] = $ar['codigo'] . ' - ' . mb_substr($ar['descricao'], 0, 24);
            $chartReprovaCustos[] = round($ar['custo_total'], 2);
            $chartReprovaHoras[]  = round($ar['horas'], 2);
            $chartReprovaQtd[]    = (int) $ar['ocorrencias'];
            $contR++;
        } else {
            $outrosCusto += $ar['custo_total'];
            $outrosHoras += $ar['horas'];
            $outrosQtd   += $ar['ocorrencias'];
        }
    }
    if ($outrosQtd > 0) {
        $chartReprovaLabels[] = 'Outras Reprovas';
        $chartReprovaCustos[] = round($outrosCusto, 2);
        $chartReprovaHoras[]  = round($outrosHoras, 2);
        $chartReprovaQtd[]    = $outrosQtd;
    }

    // Percentual acumulado (curva de Pareto) sobre a série exibida (custo ou horas)
    $chartReprovaSerie = $podeVerValores ? $chartReprovaCustos : $chartReprovaHoras;
    $chartReprovaTotalSerie = array_sum($chartReprovaSerie);
    $chartReprovaAcumulado = [];
    $acumuladoParcial = 0.0;
    foreach ($chartReprovaSerie as $valor) {
        $acumuladoParcial += $valor;
        $chartReprovaAcumulado[] = $chartReprovaTotalSerie > 0
            ? round(($acumuladoParcial / $chartReprovaTotalSerie) * 100, 1)
            : 0.0;
    }

    // Gráfico 2: Setores Causadores com Paleta de Cores Harmônica e Exclusiva
    $mapaCoresSetores = [
        'LINHA'              => '#133a27', // Verde Floresta Trael (principal)
        'CALDEIRARIA'        => '#7c3aed', // Roxo Moderno
        'ELÉTRICO'           => '#0284c7', // Azul Elétrico
        'PINTURA'            => '#e8a020', // Gold Trael
        'ENGENHARIA'         => '#ea580c', // Laranja
        'REVITALIZAÇÃO'      => '#0d9488', // Teal
        'S/ Setor Causador'  => '#94a3b8'  // Slate / Cinza Neutro
    ];

    $chartSetorLabels = [];
    $chartSetorCustos = [];
    $chartSetorHoras  = [];
    $chartSetorColors = [];

    foreach ($analiseSetores as $as) {
        $chartSetorLabels[] = $as['nome'];
        $chartSetorCustos[] = round($as['custo_total'], 2);
        $chartSetorHoras[]  = round($as['horas'], 2);
        $chartSetorColors[] = $mapaCoresSetores[$as['slug']] ?? '#64748b';
    }

    return get_defined_vars();
}
