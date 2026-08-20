<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'erro' => 'Não autenticado']);
    exit;
}

try {
    $pdo = getDB();

    // ─── Setores Mapeados no Diagrama ───────────────────────────────────────────
    $SETORES_CONFIG = [
        'LAB' => [
            'codigo' => 'LAB',
            'nome' => 'Laboratório de Ensaios',
            'categoria' => 'teste',
            'cor' => '#3b82f6', // Azul
            'cor_accent' => '#60a5fa',
            'slugs' => ['laboratorio', 'lab', 'LAB', 'ensaio']
        ],
        'MF' => [
            'codigo' => 'MF',
            'nome' => 'Montagem Final',
            'categoria' => 'montagem',
            'cor' => '#8b5cf6', // Roxo
            'cor_accent' => '#a78bfa',
            'slugs' => ['montagem_final', 'mf', 'MF', 'inspecao_final', 'iqf', 'IQF']
        ],
        'RET' => [
            'codigo' => 'RET',
            'nome' => 'Setor de Retrabalho',
            'categoria' => 'retrabalho',
            'cor' => '#d97706', // Âmbar / Laranja
            'cor_accent' => '#f59e0b',
            'slugs' => ['retrabalho', 'ret', 'RET', 'triagem', 'desmontagem']
        ],
        'ME' => [
            'codigo' => 'ME',
            'nome' => 'Montagem Elétrica',
            'categoria' => 'montagem',
            'cor' => '#06b6d4', // Ciano
            'cor_accent' => '#22d3ee',
            'slugs' => ['montagem_eletrica', 'me', 'ME', 'mel', 'MEL', 'montagem_nucleo', 'montagem']
        ],
        'BOB' => [
            'codigo' => 'BOB',
            'nome' => 'Bobinagem (AT / BT)',
            'categoria' => 'producao',
            'cor' => '#10b981', // Verde
            'cor_accent' => '#34d399',
            'slugs' => ['bobinagem_at', 'bobinagem_bt', 'bobinagem', 'bob', 'BOB']
        ],
        'PINT' => [
            'codigo' => 'PINT',
            'nome' => 'Pintura e Tratamento',
            'categoria' => 'acabamento',
            'cor' => '#f59e0b', // Âmbar
            'cor_accent' => '#fbbf24',
            'slugs' => ['pintura', 'pint', 'PINT']
        ],
        'CALD' => [
            'codigo' => 'CALD',
            'nome' => 'Caldeiraria e Solda',
            'categoria' => 'producao',
            'cor' => '#ef4444', // Vermelho
            'cor_accent' => '#f87171',
            'slugs' => ['solda', 'radiador', 'caldeiraria', 'corte', 'usinagem', 'cald', 'CALD']
        ],
        'PCP' => [
            'codigo' => 'PCP',
            'nome' => 'Planejamento e Controle (PCP)',
            'categoria' => 'apoio',
            'cor' => '#64748b', // Slate
            'cor_accent' => '#94a3b8',
            'slugs' => ['pcp', 'PCP', 'planejamento']
        ],
        'ENG' => [
            'codigo' => 'ENG',
            'nome' => 'Engenharia / Análise Técnica',
            'categoria' => 'apoio',
            'cor' => '#ec4899', // Rosa
            'cor_accent' => '#f472b6',
            'slugs' => ['engenharia', 'eng', 'ENG', 'analise_tecnica']
        ],
        'ALMX' => [
            'codigo' => 'ALMX',
            'nome' => 'Almoxarifado / Peças',
            'categoria' => 'apoio',
            'cor' => '#eab308', // Amarelo escuro
            'cor_accent' => '#fde047',
            'slugs' => ['almoxarifado', 'almox', 'ALMX', 'materiais']
        ],
    ];

    // Helper: mapeia um slug salvo no banco para o código do setor do mapa
    $mapSlugToSetor = function (string $slug) use ($SETORES_CONFIG): ?string {
        $slug = trim($slug);
        if ($slug === '') return null;
        foreach ($SETORES_CONFIG as $codigo => $info) {
            if (strcasecmp($slug, $codigo) === 0) {
                return $codigo;
            }
            foreach ($info['slugs'] as $s) {
                if (strcasecmp($slug, $s) === 0) {
                    return $codigo;
                }
            }
        }
        // Heurística de matching por substring
        if (stripos($slug, 'iqf') !== false || stripos($slug, 'mf') !== false || stripos($slug, 'final') !== false) return 'MF';
        if (stripos($slug, 'ret') !== false || stripos($slug, 'triag') !== false) return 'RET';
        if (stripos($slug, 'bob') !== false) return 'BOB';
        if (stripos($slug, 'sold') !== false || stripos($slug, 'rad') !== false || stripos($slug, 'cald') !== false) return 'CALD';
        if (stripos($slug, 'pint') !== false) return 'PINT';
        if (stripos($slug, 'lab') !== false || stripos($slug, 'ensaio') !== false) return 'LAB';
        if (stripos($slug, 'elet') !== false || stripos($slug, 'me') !== false || stripos($slug, 'nuc') !== false || stripos($slug, 'mont') !== false) return 'ME';
        if (stripos($slug, 'eng') !== false) return 'ENG';
        if (stripos($slug, 'pcp') !== false) return 'PCP';
        if (stripos($slug, 'almox') !== false) return 'ALMX';
        return null;
    };

    // ─── 1. Buscar Retrabalhos Ativos ──────────────────────────────────────────
    // Considera registros com status diferente de 'finalizado' OU com retorno aguardando
    $sqlRet = "
        SELECT 
            r.id,
            r.id_lote,
            r.ns_transformador,
            r.id_projeto,
            r.id_reprova,
            r.estacao,
            r.data_reprova,
            r.data_chegada,
            r.data_inicio,
            r.data_finalizacao,
            r.status,
            r.prioridade AS retrabalho_prioridade,
            r.observacoes,
            r.causa_raiz,
            r.causa_reprova,
            r.setores_destino,
            r.created_at,
            pr.codigo AS projeto_codigo,
            pr.descricao AS projeto_descricao,
            pr.prioridade AS projeto_prioridade,
            ped.id AS id_pedido,
            ped.numero AS pedido_numero,
            ped.prioridade AS pedido_prioridade,
            rep.codigo AS reprova_codigo,
            rep.familia AS reprova_familia,
            rep.descricao AS reprova_descricao,
            rep.local AS reprova_local,
            u.nome AS responsavel_nome
        FROM retrabalhos r
        INNER JOIN projetos pr ON pr.id = r.id_projeto
        INNER JOIN pedidos ped ON ped.id = pr.id_pedido
        LEFT JOIN reprovas rep ON rep.id = r.id_reprova
        LEFT JOIN usuarios u ON u.id = r.id_responsavel
        WHERE r.deleted_at IS NULL
          AND r.id_reprova IS NOT NULL
          AND (
              r.status IN ('agu_chegada', 'agu_abertura', 'agu_causa_raiz')
              OR EXISTS (
                  SELECT 1 FROM producao_etapas pe
                  WHERE pe.ns_transformador = r.ns_transformador
                    AND pe.id_projeto = r.id_projeto
                    AND pe.status = 'aguardando_retorno'
                    AND pe.deleted_at IS NULL
              )
          )
        ORDER BY ped.prioridade ASC, pr.prioridade ASC, r.created_at DESC
    ";
    $stmtRet = $pdo->query($sqlRet);
    $linhasRet = $stmtRet->fetchAll(PDO::FETCH_ASSOC);

    // ─── 2. Buscar Peças Ativas na Produção / Laboratório / IQF (Lista e Retornos) ────────────────────
    $sqlEtapas = "
        SELECT 
            pe.id,
            pe.ns_transformador,
            pe.id_projeto,
            pe.estacao,
            pe.status,
            pe.data_inicio,
            pe.created_at,
            pe.id_responsavel,
            u.nome AS responsavel_nome,
            pr.codigo AS projeto_codigo,
            pr.descricao AS projeto_descricao,
            pr.prioridade AS projeto_prioridade,
            ped.id AS id_pedido,
            ped.numero AS pedido_numero,
            ped.prioridade AS pedido_prioridade
        FROM producao_etapas pe
        INNER JOIN projetos pr ON pr.id = pe.id_projeto
        INNER JOIN pedidos ped ON ped.id = pr.id_pedido
        LEFT JOIN usuarios u   ON u.id   = pe.id_responsavel
        WHERE pe.deleted_at IS NULL
          AND pe.status IN ('em_andamento', 'aguardando_retorno')
        ORDER BY pe.data_inicio DESC
    ";
    $stmtEtapas = $pdo->query($sqlEtapas);
    $linhasEtapas = $stmtEtapas->fetchAll(PDO::FETCH_ASSOC);

    // Indexar etapas por NS para rápida localização de estação ativa/retorno
    $etapasPorNs = [];
    foreach ($linhasEtapas as $et) {
        $ns = trim((string)$et['ns_transformador']);
        if ($ns !== '') {
            $etapasPorNs[$ns] = $et;
        }
    }

    // ─── 3. Consolidar Transformadores Únicos por NS / Lote ────────────────────
    $transformadores = [];

    foreach ($linhasRet as $row) {
        $ns = trim((string)$row['ns_transformador']);
        if ($ns === '') continue;

        if (!isset($transformadores[$ns])) {
            $destinosSlugs = array_filter(array_map('trim', explode(',', (string)($row['setores_destino'] ?? ''))));
            
            // Determina o setor único onde a peça se encontra:
            // 1ª Prioridade: Se possui etapa ativa/retorno na estação de produção (LAB / MF)
            if (isset($etapasPorNs[$ns])) {
                $et = $etapasPorNs[$ns];
                $setor = $mapSlugToSetor($et['estacao']) ?: 'MF';
            }
            // 2ª Prioridade: Se está aguardando chegada no retrabalho, ainda se encontra na estação de origem
            elseif ($row['status'] === 'agu_chegada') {
                $localRep = strtoupper(trim((string)($row['estacao'] ?: $row['reprova_local'] ?: '')));
                $setor = ($localRep === 'IQF') ? 'MF' : 'LAB';
            }
            // 3ª Prioridade: Se já concluiu triagem e possui destino definido nos próximos setores
            elseif (!empty($destinosSlugs)) {
                $primeiroDestino = null;
                foreach ($destinosSlugs as $ds) {
                    $mapped = $mapSlugToSetor($ds);
                    if ($mapped) {
                        $primeiroDestino = $mapped;
                        break;
                    }
                }
                $setor = $primeiroDestino ?: 'RET';
            }
            // 4ª Prioridade: Se não tem destino explícito mas tem reprova de pintura
            elseif (in_array(strtoupper(trim((string)($row['reprova_familia'] ?? ''))), ['PINTURA', 'SERIGRAFIA', 'CAMADA'], true)) {
                $setor = 'PINT';
            }
            // 5ª Prioridade: No Setor de Retrabalho (RET)
            else {
                $setor = 'RET';
            }

            $isEtapaRetorno = (isset($etapasPorNs[$ns]) && $etapasPorNs[$ns]['status'] === 'aguardando_retorno');
            $isLabRetorno = ($isEtapaRetorno && $setor === 'LAB');
            $retornoLabel = $isEtapaRetorno ? ($isLabRetorno ? 'RETORNO AO LAB' : 'RETORNO À MF') : '';

            // Resolução de Prioridade com Herança SGT (NS > Projeto > Pedido)
            $ns_prio  = trim((string)($row['retrabalho_prioridade'] ?? ''));
            $pr_prio  = trim((string)($row['projeto_prioridade'] ?? ''));
            $ped_prio = trim((string)($row['pedido_prioridade'] ?? ''));

            $eff_prio = 'neutro';
            if ($ns_prio !== '' && $ns_prio !== 'neutro') {
                $eff_prio = $ns_prio;
            } elseif ($pr_prio !== '' && $pr_prio !== 'neutro') {
                $eff_prio = $pr_prio;
            } elseif ($ped_prio !== '' && $ped_prio !== 'neutro') {
                $eff_prio = $ped_prio;
            }
            $isUrgente = in_array(strtolower($eff_prio), ['emergente', 'urgente', 'importante'], true);

            $transformadores[$ns] = [
                'ns' => $ns,
                'id_principal' => (int)$row['id'],
                'id_lote' => $row['id_lote'] ? (int)$row['id_lote'] : (int)$row['id'],
                'id_projeto' => (int)$row['id_projeto'],
                'projeto_codigo' => $row['projeto_codigo'],
                'projeto_descricao' => $row['projeto_descricao'] ?? '',
                'id_pedido' => (int)$row['id_pedido'],
                'pedido_numero' => $row['pedido_numero'],
                'prioridade' => $eff_prio,
                'prioridade_ns' => $ns_prio,
                'prioridade_projeto' => $pr_prio,
                'prioridade_pedido' => $ped_prio,
                'is_urgente' => $isUrgente,
                'status' => $row['status'],
                'data_reprova' => $row['data_reprova'],
                'data_chegada' => $row['data_chegada'],
                'data_inicio' => $row['data_inicio'],
                'observacoes' => $row['observacoes'] ?? '',
                'causa_reprova' => $row['causa_reprova'] ?? '',
                'causa_raiz' => $row['causa_raiz'] ?? '',
                'responsavel' => (isset($etapasPorNs[$ns]) && !empty($etapasPorNs[$ns]['responsavel_nome'])) ? $etapasPorNs[$ns]['responsavel_nome'] : ($row['responsavel_nome'] ?? ''),
                'setor' => $setor,
                'setores' => [$setor],
                'setores_slugs' => $destinosSlugs,
                'reprovas' => [],
                'em_retorno' => $isEtapaRetorno,
                'em_retorno_lab' => $isLabRetorno,
                'retorno_setor' => $isEtapaRetorno ? $setor : null,
                'retorno_label' => $retornoLabel,
                'dias_aberto' => (int)floor((time() - strtotime($row['data_reprova'] ?: $row['created_at'])) / 86400),
            ];
        }

        if ($row['id_reprova']) {
            $transformadores[$ns]['reprovas'][] = [
                'id' => (int)$row['id_reprova'],
                'codigo' => $row['reprova_codigo'] ?? '',
                'familia' => $row['reprova_familia'] ?? '',
                'descricao' => $row['reprova_descricao'] ?? '',
                'local' => $row['estacao'] ?: ($row['reprova_local'] ?? ''),
            ];
        }
    }

    // ─── 4. Agrupar Transformadores por Setor do Mapa ──────────────────────────
    $setoresData = [];
    foreach ($SETORES_CONFIG as $codigo => $conf) {
        $setoresData[$codigo] = array_merge($conf, [
            'total_pecas' => 0,
            'pecas_urgentes' => 0,
            'pecas_retorno' => 0,
            'transformadores' => [],
            'reprovas_frequentes' => [],
        ]);
    }

    $totalGeralPecas = count($transformadores);
    $totalUrgentes = 0;
    $totalAguardandoChegada = 0;
    $totalRetorno = 0;

    foreach ($transformadores as $ns => $t) {
        $isUrgente = !empty($t['is_urgente']) || in_array(strtolower((string)($t['prioridade'] ?? '')), ['emergente', 'urgente', 'importante'], true);
        if ($isUrgente) $totalUrgentes++;
        if ($t['status'] === 'agu_chegada' || $t['status'] === 'agu_abertura') $totalAguardandoChegada++;
        if (!empty($t['em_retorno'])) $totalRetorno++;

        foreach ($t['setores'] as $setorCod) {
            if (isset($setoresData[$setorCod])) {
                $setoresData[$setorCod]['total_pecas']++;
                if ($isUrgente) {
                    $setoresData[$setorCod]['pecas_urgentes']++;
                }
                if (!empty($t['em_retorno'])) {
                    $setoresData[$setorCod]['pecas_retorno']++;
                }
                $setoresData[$setorCod]['transformadores'][] = $t;
            }
        }
    }

    // Calcula reprovas mais frequentes por setor
    foreach ($setoresData as $codigo => &$s) {
        $contagemRep = [];
        foreach ($s['transformadores'] as $t) {
            foreach ($t['reprovas'] as $rep) {
                $key = $rep['codigo'] ?: $rep['familia'];
                if ($key) {
                    $contagemRep[$key] = ($contagemRep[$key] ?? 0) + 1;
                }
            }
        }
        arsort($contagemRep);
        $s['reprovas_frequentes'] = array_slice($contagemRep, 0, 3, true);
    }
    unset($s);

    // Resumo global
    $resumo = [
        'total_pecas' => $totalGeralPecas,
        'total_urgentes' => $totalUrgentes,
        'aguardando_chegada' => $totalAguardandoChegada,
        'em_retorno_lab' => $totalRetorno,
        'aguardando_retorno' => $totalRetorno,
        'atualizado_em' => date('H:i:s'),
    ];

    echo json_encode([
        'sucesso' => true,
        'resumo' => $resumo,
        'setores' => $setoresData,
        'transformadores' => array_values($transformadores),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'erro' => 'Erro ao carregar dados do mapa de retrabalho.',
    ]);
}
