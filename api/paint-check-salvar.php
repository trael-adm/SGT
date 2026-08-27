<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/session.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

if (!podeEditar('pin.pai') && !isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Apenas consulta: sem permissão para salvar inspeções']);
    exit;
}

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload inválido']);
    exit;
}

$idUsuario = (int)(currentUser()['id'] ?? 0);
if ($idUsuario <= 0) {
    http_response_code(401);
    echo json_encode(['error' => 'Sessão de usuário inválida']);
    exit;
}

$total = (int)($data['total_transformadores'] ?? 0);
$validos = (int)($data['validos'] ?? 0);
$invalidos = (int)($data['invalidos'] ?? 0);
$resultadosJson = json_encode($data['resultados'] ?? []);

$pdo = getDB();

try {
    $stmt = $pdo->prepare("INSERT INTO sgt_paintcheck_historico (id_usuario, data_hora, total_transformadores, validos, invalidos, resultados_json) VALUES (?, NOW(), ?, ?, ?, ?)");
    $stmt->execute([$idUsuario, $total, $validos, $invalidos, $resultadosJson]);
    
    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno ao salvar histórico.']);
}
