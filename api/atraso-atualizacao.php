<?php
declare(strict_types=1);

/**
 * Quando a foto de atraso foi gravada pela última vez.
 *
 * Usada pelo contador "Próxima atualização" das telas de atraso (assets/js/atraso-atualizacao.js)
 * para saber se já há foto nova depois que o tempo zera.
 *
 * GET ?modulo=distribuicao|forca  ->  {"sucesso":true,"ultima":"2026-09-21 15:55:05"}
 */

require_once __DIR__ . '/../config/conexao.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../includes/boletim-atraso.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'erro' => 'Sessão expirada. Faça login novamente.']);
    exit;
}

// Só lê o banco: libera o lock da sessão para não travar outras requisições da mesma pessoa.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

if (($_GET['modulo'] ?? 'distribuicao') === 'forca') {
    require_once __DIR__ . '/../includes/boletim-atraso-forca.php';
    $atualizacao = boletimUltimaAtualizacaoAtrasoForca();
} else {
    $atualizacao = boletimUltimaAtualizacaoAtraso();
}

echo json_encode([
    'sucesso' => $atualizacao !== null,
    'ultima'  => $atualizacao['atualizado_em'] ?? null,
]);
