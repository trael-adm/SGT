<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/session.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'erro' => 'Sessão expirada. Faça login novamente.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$celula = trim((string) ($_GET['celula'] ?? ''));
if ($celula === '') {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'erro' => 'Célula não especificada.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$tipo = strtolower(trim((string) ($_GET['tipo'] ?? 'atraso')));
$linha = trim((string) ($_GET['linha'] ?? 'TODOS'));
$dataCorteParam = trim((string) ($_GET['data_corte'] ?? ''));
$dataExtracaoParam = trim((string) ($_GET['data_extracao'] ?? ''));
$empresaParam = (int) ($_GET['empresa'] ?? 1);
if (!in_array($empresaParam, [1, 4, 0], true)) {
    $empresaParam = 1;
}

$pdo = getDB();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Erro de conexão com o banco de dados.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Fábrica 2 (Média Força): sem decomposição de sub-OFs nesta base, então não há célula real —
// as barras do gráfico entram pelo único progresso real disponível: se a OF já tem alguma peça
// produzida (Semi-Acabada) ou nem começou (Não Iniciada). Rotuladas "Média Força — Semi-Acabada"
// quando exibidas junto da Distribuição (empresa=0), ou só "Semi-Acabada"/"Não Iniciada" quando
// a tela está filtrada só nela (empresa=4).
$prefixoForca = 'Média Força — ';
$statusForcaCelula = '';
if (str_starts_with($celula, $prefixoForca)) {
    $statusForcaCelula = mb_substr($celula, mb_strlen($prefixoForca));
} elseif ($empresaParam === 4 && in_array($celula, ['Semi-Acabada', 'Não Iniciada'], true)) {
    $statusForcaCelula = $celula;
}

if (in_array($statusForcaCelula, ['Semi-Acabada', 'Não Iniciada'], true)) {
    try {
        $maxDataForca = (string) ($pdo->query("SELECT MAX(data_extracao) FROM atraso_forca_registros")->fetchColumn() ?: date('Y-m-d'));
        $dataCorteForca = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataCorteParam)) ? $dataCorteParam : $maxDataForca;

        $where = ["data_extracao = :data_extracao"];
        $where[] = ($statusForcaCelula === 'Semi-Acabada') ? "qtd_produzida > 0" : "qtd_produzida = 0";
        $params = ['data_extracao' => $maxDataForca, 'data_corte_st' => $dataCorteForca];

        // Mesmo vocabulário de linha (TPD/TPM/TPS) do filtro "Linha" da tela — independente do
        // agrupamento por progresso (Semi-Acabada/Não Iniciada) das barras.
        if (in_array($linha, ['TPD', 'TPM', 'TPS'], true)) {
            $where[] = "linha = :linha_celula";
            $params['linha_celula'] = $linha;
        }

        if ($tipo === 'atraso') {
            $where[] = "data_programada < :data_corte_filter AND qtd_a_produzir > 0";
            $params['data_corte_filter'] = $dataCorteForca;
        } elseif ($tipo === 'adiantamento' || $tipo === 'prazo') {
            $where[] = "data_programada >= :data_corte_filter AND qtd_a_produzir > 0";
            $params['data_corte_filter'] = $dataCorteForca;
        } elseif ($tipo === 'aberto') {
            $where[] = "qtd_a_produzir > 0";
        } elseif ($tipo === 'apontada' || $tipo === 'concluida') {
            $where[] = "qtd_produzida > 0";
        }

        $whereSql = implode(' AND ', $where);

        $sql = "
            SELECT
                id, cd_referencia as op, seq_plano, cd_pedido as pedido, potencia_kva as potencia,
                fases, data_programada as data_mf, cliente_nome as cliente, quantidade as qtd_total,
                qtd_produzida as qtd_apontada, qtd_a_produzir as qtd_aberto, linha,
                CASE
                    WHEN qtd_a_produzir > 0 AND data_programada < :data_corte_st THEN 'Atraso'
                    WHEN qtd_a_produzir > 0 THEN 'Em Aberto (No Prazo)'
                    ELSE 'Apontada / Concluída'
                END as status_of
            FROM atraso_forca_registros
            WHERE {$whereSql}
            ORDER BY data_programada ASC, seq_plano ASC, id ASC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $ofs = [];
        $totalAberto = 0;
        foreach ($rows as $it) {
            $diasAtraso = 0;
            if ($it['status_of'] === 'Atraso' && !empty($it['data_mf'])) {
                $diasAtraso = max(1, (int) floor((strtotime($dataCorteForca) - strtotime((string) $it['data_mf'])) / 86400));
            }
            $qtdAb = (int) ($it['qtd_aberto'] ?? 0);
            $totalAberto += $qtdAb;
            $fasesNum = (int) ($it['fases'] ?? 0);
            $faseLabel = $fasesNum === 1 ? 'MON' : ($fasesNum === 3 ? 'TRI' : ($fasesNum > 0 ? $fasesNum . 'F' : '—'));

            $ofs[] = [
                'seq'               => $it['seq_plano'] ?: '—',
                'op'                => (string) ($it['op'] ?? '—'),
                'ns'                => '—',
                'celula'            => (string) ($it['linha'] ?: 'Não classificado'),
                'pedido'            => $it['pedido'] ?: '—',
                'potencia'          => number_format((float) ($it['potencia'] ?? 0), 1, ',', '.') . ' kVA',
                'fase'              => $faseLabel,
                'data_mf'           => !empty($it['data_mf']) ? date('d/m/Y', strtotime((string) $it['data_mf'])) : '—',
                'cliente'           => (string) ($it['cliente'] ?? '—'),
                'qtd_total'         => (int) ($it['qtd_total'] ?? 1),
                'qtd_aberto'        => $qtdAb,
                'status_of'         => (string) $it['status_of'],
                'setores_pendentes' => '',
                'dias_atraso'       => $diasAtraso,
            ];
        }

        echo json_encode([
            'sucesso'      => true,
            'celula'       => $celula,
            'total_ofs'    => count($ofs),
            'total_aberto' => $totalAberto,
            'ofs'          => $ofs,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['sucesso' => false, 'erro' => 'Erro ao consultar OFs da célula (Média Força): ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// 1. Data de extração (snapshot)
$dataExtracao = $dataExtracaoParam;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataExtracao)) {
    $dataBanco = $pdo->query("SELECT MAX(data_extracao) FROM atraso_distribuicao_registros")->fetchColumn();
    $dataExtracao = $dataBanco ? (string) $dataBanco : date('Y-m-d');
}

// 2. Data de corte
$dataCorte = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dataCorteParam)) ? $dataCorteParam : $dataExtracao;

// Verifica se a tabela atraso_distribuicao_celulas possui dados para esta data
$temDadosCelulas = 0;
try {
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM atraso_distribuicao_celulas WHERE data_extracao = ?");
    $stmtCheck->execute([$dataExtracao]);
    $temDadosCelulas = (int) $stmtCheck->fetchColumn();
} catch (\Throwable $e) {
    $temDadosCelulas = 0;
}

$prefix = ($temDadosCelulas > 0) ? 'r.' : '';

// 3. Montagem dos filtros WHERE
$where = ["{$prefix}data_extracao = :data_extracao"];
$params = [
    'data_extracao' => $dataExtracao,
    'data_corte_st' => $dataCorte,
];

// Filtro de Célula / Setor Real
if ($temDadosCelulas > 0) {
    if ($celula === 'Averiguar') {
        $where[] = "(c.celula_nome = 'Averiguar' OR r.setor_real = 'Averiguar' OR r.tipo_bloqueio = 'MATERIAL')";
    } elseif ($celula === 'Não classificado') {
        $where[] = "(c.celula_nome IS NULL OR r.setor_real IS NULL OR r.setor_real = '')";
    } else {
        $where[] = "c.celula_nome = :celula";
        $params['celula'] = $celula;
    }
} else {
    if ($celula === 'Averiguar') {
        $where[] = "(setor_real = 'Averiguar' OR tipo_bloqueio = 'MATERIAL' OR setor_real = 'Aguardando Material/Compra')";
    } elseif ($celula === 'Não classificado') {
        $where[] = "(setor_real IS NULL OR setor_real = '' OR setor_real = 'Não classificado')";
    } else {
        $where[] = "setor_real = :celula";
        $params['celula'] = $celula;
    }
}

// Filtro de Status
if ($tipo === 'atraso') {
    $where[] = "{$prefix}data_programada < :data_corte_filter AND {$prefix}qtd_a_produzir > 0";
    $params['data_corte_filter'] = $dataCorte;
} elseif ($tipo === 'adiantamento' || $tipo === 'prazo') {
    $where[] = "{$prefix}data_programada >= :data_corte_filter AND {$prefix}qtd_a_produzir > 0";
    $params['data_corte_filter'] = $dataCorte;
} elseif ($tipo === 'aberto') {
    $where[] = "{$prefix}qtd_a_produzir > 0";
} elseif ($tipo === 'apontada' || $tipo === 'concluida') {
    $where[] = "{$prefix}qtd_produzida > 0";
}

// Filtro de Linha
if ($linha !== 'TODOS' && !empty($linha)) {
    if ($linha === 'MON') {
        $where[] = "({$prefix}fases = 'MON' OR {$prefix}linha LIKE '%Mono%')";
    } elseif ($linha === 'TRI') {
        $where[] = "({$prefix}fases = 'TRI' OR {$prefix}linha LIKE '%Convencional%' OR {$prefix}linha LIKE '%JC%')";
    } elseif ($linha === 'EPO') {
        $where[] = "({$prefix}tipo_construtivo LIKE '%Seco%' OR {$prefix}linha LIKE '%EPO%')";
    } elseif ($linha === 'POT') {
        $where[] = "({$prefix}potencia_kva >= 150 OR {$prefix}linha LIKE '%POT%')";
    } else {
        $where[] = "({$prefix}linha LIKE :linha_param OR {$prefix}fases LIKE :linha_param_fases)";
        $params['linha_param'] = '%' . $linha . '%';
        $params['linha_param_fases'] = '%' . $linha . '%';
    }
}

$whereSql = implode(' AND ', $where);

// 4. Carrega números de série suplementares (cache JSON se disponível)
$suplementarNS = [];
$suplemFile = __DIR__ . '/../storage/cache/atraso_ns_suplementar.json';
if (is_file($suplemFile)) {
    $suplementarNS = @json_decode((string) file_get_contents($suplemFile), true) ?: [];
}

try {
    if ($temDadosCelulas > 0) {
        $sql = "
            SELECT 
                r.id,
                r.cd_referencia as op,
                r.num_serie,
                r.seq_plano,
                r.cd_pedido as pedido,
                r.potencia_kva as potencia,
                r.fases as fase,
                r.data_programada as data_mf,
                r.cliente_nome as cliente,
                r.ds_produto as descricao,
                r.quantidade as qtd_total,
                r.qtd_produzida as qtd_apontada,
                r.qtd_a_produzir as qtd_aberto,
                r.tipo_nucleo,
                r.setor_real,
                r.tipo_bloqueio,
                r.setores_pendentes,
                r.linha,
                CASE 
                    WHEN r.qtd_a_produzir > 0 AND r.data_programada < :data_corte_st THEN 'Atraso'
                    WHEN r.qtd_a_produzir > 0 THEN 'Em Aberto (No Prazo)'
                    ELSE 'Apontada / Concluída'
                END as status_of
            FROM atraso_distribuicao_registros r
            JOIN atraso_distribuicao_celulas c ON c.registro_id = r.id
            WHERE {$whereSql}
            GROUP BY r.id
            ORDER BY r.data_programada ASC, r.seq_plano ASC, r.num_serie ASC, r.id ASC
        ";
    } else {
        $sql = "
            SELECT 
                id,
                cd_referencia as op,
                num_serie,
                seq_plano,
                cd_pedido as pedido,
                potencia_kva as potencia,
                fases as fase,
                data_programada as data_mf,
                cliente_nome as cliente,
                ds_produto as descricao,
                quantidade as qtd_total,
                qtd_produzida as qtd_apontada,
                qtd_a_produzir as qtd_aberto,
                tipo_nucleo,
                setor_real,
                tipo_bloqueio,
                linha,
                CASE 
                    WHEN qtd_a_produzir > 0 AND data_programada < :data_corte_st THEN 'Atraso'
                    WHEN qtd_a_produzir > 0 THEN 'Em Aberto (No Prazo)'
                    ELSE 'Apontada / Concluída'
                END as status_of
            FROM atraso_distribuicao_registros
            WHERE {$whereSql}
            ORDER BY data_programada ASC, seq_plano ASC, num_serie ASC, id ASC
        ";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $ofs = [];
    $totalAberto = 0;

    foreach ($rows as $it) {
        $ped = trim((string) ($it['pedido'] ?? ''));
        $op  = trim((string) ($it['op'] ?? ''));
        $kPedProj = "{$ped}_{$op}";

        $nsFmt = '—';
        if (!empty($it['num_serie'])) {
            $nsFmt = (string) $it['num_serie'];
        } else {
            $seriesEncontradas = $suplementarNS[$kPedProj] ?? ($suplementarNS[$op] ?? []);
            if (!empty($seriesEncontradas)) {
                $uSeries = array_values(array_unique(array_map('strval', $seriesEncontradas)));
                $nsFmt = count($uSeries) > 1 ? (min($uSeries) . ' – ' . max($uSeries)) : ($uSeries[0] ?? '—');
            }
        }

        $diasAtraso = 0;
        if ($it['status_of'] === 'Atraso' && !empty($it['data_mf'])) {
            $diasAtraso = max(1, (int) floor((strtotime($dataCorte) - strtotime((string) $it['data_mf'])) / 86400));
        }

        $qtdAb = (int) ($it['qtd_aberto'] ?? 0);
        $totalAberto += $qtdAb;

        $ofs[] = [
            'seq'         => $it['seq_plano'] ?: '—',
            'op'          => (string) ($it['op'] ?? '—'),
            'ns'          => $nsFmt,
            'celula'      => (string) ($it['setor_real'] ?: 'Não classificado'),
            'pedido'      => $it['pedido'] ?: '—',
            'potencia'    => number_format((float) ($it['potencia'] ?? 0), 1, ',', '.') . ' kVA',
            'fase'        => (string) ($it['fase'] ?? '—'),
            'data_mf'     => !empty($it['data_mf']) ? date('d/m/Y', strtotime((string) $it['data_mf'])) : '—',
            'cliente'     => (string) ($it['cliente'] ?? '—'),
            'qtd_total'   => (int) ($it['qtd_total'] ?? 1),
            'qtd_aberto'  => $qtdAb,
            'status_of'   => (string) $it['status_of'],
            'setores_pendentes' => (string) ($it['setores_pendentes'] ?? ''),
            'dias_atraso' => $diasAtraso,
        ];
    }

    echo json_encode([
        'sucesso'      => true,
        'celula'       => $celula,
        'total_ofs'    => count($ofs),
        'total_aberto' => $totalAberto,
        'ofs'          => $ofs,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Erro ao consultar OFs da célula: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
