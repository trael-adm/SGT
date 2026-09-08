<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/session.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acesso restrito ao Administrador']);
    exit;
}

try {
    $scriptPs1 = realpath(__DIR__ . '/../scripts/sincronizar_vsat_num_series.ps1');
    if (!$scriptPs1 || !file_exists($scriptPs1)) {
        throw new RuntimeException('Script de sincronização não encontrado: scripts/sincronizar_vsat_num_series.ps1');
    }

    $cmd = 'powershell -ExecutionPolicy Bypass -File ' . escapeshellarg($scriptPs1);
    $output = [];
    $returnVar = 0;
    exec($cmd . ' 2>&1', $output, $returnVar);

    if ($returnVar !== 0) {
        $logStr = implode("\n", $output);
        $errMsg = 'Não foi possível conectar ao servidor do ERP VSAT (vsat.trael.local / 10.10.40.8).';
        if (stripos($logStr, 'rede') !== false || stripos($logStr, 'network') !== false || stripos($logStr, 'timeout') !== false || stripos($logStr, 'ERRO CRITICO') !== false || stripos($logStr, 'Name resolution') !== false) {
            $errMsg .= "\n\n⚠️ Motivo provável: Você está fora da rede interna da TRAEL ou a VPN está desconectada.";
        }
        echo json_encode([
            'success' => false,
            'error' => $errMsg,
            'log' => implode("\n", array_slice($output, -10)),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $jsonPath = __DIR__ . '/../storage/cache/vsat_num_series_previo.json';
    if (!file_exists($jsonPath)) {
        throw new RuntimeException('Arquivo de dados não gerado após sincronização.');
    }

    $dados = json_decode(file_get_contents($jsonPath), true);
    $linhas = $dados['linhas'] ?? [];

    if (!is_array($linhas) || count($linhas) === 0) {
        echo json_encode([
            'success' => false,
            'error' => 'Nenhum número de série retornado na consulta ao VSAT para o período.',
            'log' => implode("\n", array_slice($output, -10)),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Normaliza para lista indexada — json_decode(assoc) devolve objeto único
    // como array associativo em vez de lista quando só há 1 linha no PowerShell.
    if (isset($linhas['num_serie'])) {
        $linhas = [$linhas];
    }

    $pdo = getDB();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('
        INSERT INTO vsat_num_series_previo
            (num_serie, num_serie_cliente, observacao, cd_referencia, descricao, cd_pedido, pedido_cliente, cliente, cd_of, data_pcp)
        VALUES
            (:num_serie, :num_serie_cliente, :observacao, :cd_referencia, :descricao, :cd_pedido, :pedido_cliente, :cliente, :cd_of, :data_pcp)
        ON DUPLICATE KEY UPDATE
            num_serie_cliente = VALUES(num_serie_cliente),
            observacao        = VALUES(observacao),
            cd_referencia     = VALUES(cd_referencia),
            descricao         = VALUES(descricao),
            cd_pedido         = VALUES(cd_pedido),
            pedido_cliente    = VALUES(pedido_cliente),
            cliente           = VALUES(cliente),
            cd_of             = VALUES(cd_of),
            data_pcp          = VALUES(data_pcp)
    ');

    $gravados = 0;
    foreach ($linhas as $linha) {
        $numSerie = trim((string) ($linha['num_serie'] ?? ''));
        if ($numSerie === '') {
            continue;
        }

        $stmt->execute([
            'num_serie'         => $numSerie,
            'num_serie_cliente' => $linha['num_serie_cliente'] ?? null,
            'observacao'        => $linha['observacao'] ?? null,
            'cd_referencia'     => $linha['cd_referencia'] ?? null,
            'descricao'         => $linha['descricao'] ?? null,
            'cd_pedido'         => $linha['cd_pedido'] ?? null,
            'pedido_cliente'    => $linha['pedido_cliente'] ?? null,
            'cliente'           => $linha['cliente'] ?? null,
            'cd_of'             => $linha['cd_of'] ?? null,
            'data_pcp'          => $linha['data_pcp'] ?? null,
        ]);
        $gravados++;
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'total_recebidos' => count($linhas),
        'total_gravados' => $gravados,
        'data_sincronizacao' => $dados['erp_metadata']['data_sincronizacao'] ?? date('d/m/Y H:i:s'),
        'log' => implode("\n", array_slice($output, -10)),
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
