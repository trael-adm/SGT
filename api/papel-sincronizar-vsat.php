<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

if (!hasAcessoPapel()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acesso restrito ao módulo Papel']);
    exit;
}

try {
    $scriptPs1 = realpath(__DIR__ . '/../scripts/sincronizar_vsat_pcp.ps1');
    if (!$scriptPs1 || !file_exists($scriptPs1)) {
        throw new RuntimeException("Script de sincronização não encontrado: scripts/sincronizar_vsat_pcp.ps1");
    }

    $cmd = "powershell -ExecutionPolicy Bypass -File " . escapeshellarg($scriptPs1);
    $output = [];
    $returnVar = 0;
    exec($cmd . ' 2>&1', $output, $returnVar);

    if ($returnVar !== 0) {
        $logStr = implode("\n", $output);
        $errMsg = "Não foi possível conectar ao servidor do ERP VSAT (vsat.trael.local / 10.10.40.8).";
        if (stripos($logStr, "rede") !== false || stripos($logStr, "network") !== false || stripos($logStr, "timeout") !== false || stripos($logStr, "ERRO CRITICO") !== false || stripos($logStr, "Name resolution") !== false) {
            $errMsg .= "\n\n⚠️ Motivo provável: Você está fora da rede interna da TRAEL ou a VPN está desconectada.";
        }
        echo json_encode([
            'success' => false,
            'error' => $errMsg,
            'log' => implode("\n", array_slice($output, -10))
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $jsonPath = __DIR__ . '/../pages/papel/dados_pcp_completo.json';
    if (!file_exists($jsonPath)) {
        throw new RuntimeException("Arquivo de dados não gerado após sincronização.");
    }

    $dados = json_decode(file_get_contents($jsonPath), true);
    $totalProjetos = count($dados['tpds'] ?? []);
    $totalItens = count($dados['rows'] ?? []);

    if ($totalProjetos === 0) {
        echo json_encode([
            'success' => false,
            'error' => "Nenhum projeto retornado na consulta ao VSAT para o período.",
            'log' => implode("\n", array_slice($output, -10))
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'success' => true,
        'total_projetos' => $totalProjetos,
        'total_itens' => $totalItens,
        'data_sincronizacao' => $dados['erp_metadata']['data_sincronizacao'] ?? date('d/m/Y H:i:s'),
        'log' => implode("\n", array_slice($output, -10))
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
