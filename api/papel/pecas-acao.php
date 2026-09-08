<?php
declare(strict_types=1);

/**
 * Endpoints da API para Módulo Papel — Gestão de Peças
 * SGT (Sistema de Gestão Trael)
 */

require_once __DIR__ . '/../../config/conexao.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireLogin();
requireAcessoPapel();

header('Content-Type: application/json; charset=utf-8');

$pdo = getDB();
$acao = trim((string)($_REQUEST['acao'] ?? ''));

try {
    switch ($acao) {
        case 'listar':
            $bloco = trim((string)($_GET['bloco'] ?? ''));
            $busca = trim((string)($_GET['busca'] ?? ''));
            
            $where = ['deleted_at IS NULL'];
            $params = [];
            
            if ($bloco !== '') {
                $where[] = 'bloco = ?';
                $params[] = $bloco;
            }
            if ($busca !== '') {
                $where[] = '(nome_engenharia LIKE ? OR codigo_vsat LIKE ? OR categoria LIKE ?)';
                $term = '%' . $busca . '%';
                $params = array_merge($params, [$term, $term, $term]);
            }
            
            $sql = 'SELECT * FROM papel_pecas WHERE ' . implode(' AND ', $where) . ' ORDER BY nome_engenharia ASC';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $pecas = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            echo json_encode(['sucesso' => true, 'dados' => $pecas]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['sucesso' => false, 'mensagem' => 'Ação inválida ou não informada.']);
            break;
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Erro interno: ' . $e->getMessage()]);
}
