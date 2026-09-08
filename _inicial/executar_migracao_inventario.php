<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/conexao.php';

try {
    $pdo = getDB();
    $sql = file_get_contents(__DIR__ . '/migrar-papel-inventario.sql');
    $pdo->exec($sql);
    echo "[OK] Tabelas do Inventário de Papel criadas/atualizadas com sucesso no MySQL!\n";
} catch (Throwable $e) {
    echo "[ERRO] " . $e->getMessage() . "\n";
    exit(1);
}
