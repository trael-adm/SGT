<?php
declare(strict_types=1);

/**
 * Endpoint de Sincronização Segura — Local (Fábrica) -> Nuvem (Railway)
 * Recebe o payload consolidado de produção (Kardex, Metas e Atraso) e atualiza o Railway.
 */

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/boletim-planilha.php';

header('Content-Type: application/json; charset=utf-8');

// Token de segurança compartilhado entre o servidor local e o Railway
$tokenEsperado = getenv('SYNC_TOKEN') ?: 'trael_sgt_sync_token_2026';
$tokenRecebido = $_SERVER['HTTP_X_SYNC_TOKEN'] ?? $_POST['sync_token'] ?? $_GET['sync_token'] ?? '';

if (!hash_equals($tokenEsperado, (string)$tokenRecebido)) {
    http_response_code(403);
    echo json_encode(['sucesso' => false, 'erro' => 'Token de sincronização inválido ou ausente.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'erro' => 'Método HTTP inválido. Utilize POST.']);
    exit;
}

try {
    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true);

    if (!is_array($payload)) {
        // Fallback para form-data tradicional
        $payload = $_POST;
        if (isset($payload['dados']) && is_string($payload['dados'])) {
            $payload['dados'] = json_decode($payload['dados'], true);
        }
        if (isset($payload['metas']) && is_string($payload['metas'])) {
            $payload['metas'] = json_decode($payload['metas'], true);
        }
    }

    $mes = trim((string)($payload['mes'] ?? date('Y-m')));
    if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
        $mes = date('Y-m');
    }

    $dados = $payload['dados'] ?? null;
    $metas = $payload['metas'] ?? null;
    $snapshotCsv = $payload['snapshot_csv'] ?? null;
    $snapshotData = $payload['snapshot_data'] ?? null;

    $atualizacoes = [];

    // 1. Atualiza cache do Kardex (Distribuição e Média Força)
    if (is_array($dados) && (!empty($dados['porDia']) || !empty($dados['nucleoPorDia']))) {
        if (!is_dir(BOLETIM_KARDEX_CACHE_DIR)) {
            @mkdir(BOLETIM_KARDEX_CACHE_DIR, 0775, true);
        }

        $cacheJson = BOLETIM_KARDEX_CACHE_DIR . '/kardex_mes_' . $mes . '.json';
        $cacheBin  = BOLETIM_KARDEX_CACHE_DIR . '/kardex_mes_' . $mes . '.cache';

        $cacheEnvelope = [
            'timestamp' => time(),
            'sincronizado_em' => date('Y-m-d H:i:s'),
            'fonte' => 'Sincronizador Local Trael (vsat.trael.local)',
            'dados' => $dados,
        ];

        file_put_contents($cacheJson, json_encode($cacheEnvelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        file_put_contents($cacheBin, serialize($cacheEnvelope));
        $atualizacoes[] = "Cache de produção ($mes) atualizado com " . count($dados['porDia'] ?? []) . " dias";
    }

    // 2. Atualiza metas no banco de dados MySQL do Railway (apenas se explicitamente enviadas)
    if (is_array($metas) && !empty($metas)) {
        $pdo = getDB();
        $stmtMeta = $pdo->prepare("
            INSERT INTO boletim_config_metas (
                `month`, meta_tpd_distribuicao, meta_enrolado, meta_convencional, 
                meta_jctrif, meta_tpm, meta_tpd_forca, meta_tps, dias_uteis, dias_customizados
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                meta_tpd_distribuicao = VALUES(meta_tpd_distribuicao),
                meta_enrolado         = VALUES(meta_enrolado),
                meta_convencional     = VALUES(meta_convencional),
                meta_jctrif           = VALUES(meta_jctrif),
                meta_tpm              = VALUES(meta_tpm),
                meta_tpd_forca        = VALUES(meta_tpd_forca),
                meta_tps              = VALUES(meta_tps),
                dias_uteis            = VALUES(dias_uteis),
                dias_customizados     = VALUES(dias_customizados)
        ");

        $stmtMeta->execute([
            $mes,
            (int)($metas['meta_tpd_distribuicao'] ?? 0),
            (int)($metas['meta_enrolado'] ?? 0),
            (int)($metas['meta_convencional'] ?? 0),
            (int)($metas['meta_jctrif'] ?? 0),
            (int)($metas['meta_tpm'] ?? 0),
            (int)($metas['meta_tpd_forca'] ?? 0),
            (int)($metas['meta_tps'] ?? 0),
            (int)($metas['dias_uteis'] ?? 21),
            isset($metas['dias_customizados']) ? (is_string($metas['dias_customizados']) ? $metas['dias_customizados'] : json_encode($metas['dias_customizados'])) : null
        ]);
        $atualizacoes[] = "Metas do mês ($mes) gravadas no banco";
    }

    // 3. Atualiza snapshot do Atraso de Distribuição
    if (!empty($snapshotCsv) && is_string($snapshotCsv)) {
        $snapDir = __DIR__ . '/../storage/snapshots';
        if (!is_dir($snapDir)) {
            @mkdir($snapDir, 0775, true);
        }

        $snapDate = $snapshotData ?: date('Y-m-d');
        $snapFile = $snapDir . '/snapshot_' . $snapDate . '.csv';
        file_put_contents($snapFile, $snapshotCsv);
        $atualizacoes[] = "Snapshot de atraso ($snapDate) gravado";
    }

    // 4. Atualiza cache do Fluxo de Pedidos
    $fluxoPedidos = $payload['fluxo_pedidos'] ?? null;
    if (is_array($fluxoPedidos) && !empty($fluxoPedidos['pedidos'])) {
        $cacheDir = __DIR__ . '/../storage/cache';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
        file_put_contents($cacheDir . '/fluxo_pedidos.json', json_encode($fluxoPedidos, JSON_UNESCAPED_UNICODE));
        $atualizacoes[] = "Fluxo de Pedidos (" . count($fluxoPedidos['pedidos']) . " pedidos na esteira) atualizado";
    }

    // 5. Atualiza cache da Planilha de Produção do Fluxo
    $fluxoPlanilha = $payload['fluxo_planilha'] ?? null;
    if (is_array($fluxoPlanilha) && !empty($fluxoPlanilha['itens'])) {
        $cacheDir = __DIR__ . '/../storage/cache';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
        file_put_contents($cacheDir . '/fluxo_planilha.json', json_encode($fluxoPlanilha, JSON_UNESCAPED_UNICODE));
        $atualizacoes[] = "Planilha de Produção do Fluxo (" . count($fluxoPlanilha['itens']) . " ordens) atualizada";
    }

    // 6. Atualiza cache de Acompanhamento (Pintura x Montagem)
    $acompanhamento = $payload['acompanhamento'] ?? null;
    if (is_array($acompanhamento) && !empty($acompanhamento['itens'])) {
        $cacheDir = __DIR__ . '/../storage/cache';
        if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
        file_put_contents($cacheDir . '/acompanhamento.json', json_encode($acompanhamento, JSON_UNESCAPED_UNICODE));
        $atualizacoes[] = "Acompanhamento de Produção (" . count($acompanhamento['itens']) . " trafos) atualizado";
    }

    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Sincronização concluída com sucesso!',
        'mes' => $mes,
        'timestamp' => date('Y-m-d H:i:s'),
        'atualizacoes' => $atualizacoes
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'erro' => 'Erro interno ao processar sincronização: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
